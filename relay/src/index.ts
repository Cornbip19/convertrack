/**
 * Convertrack Search Console relay.
 *
 * A stateless broker that lets a WordPress site connect Google Search Console
 * with one "Sign in with Google" click, without the site owner creating a
 * Google Cloud project. The relay owns the Google OAuth client (and so the
 * client secret and the registered redirect URI); the site keeps everything
 * else: its own anti-CSRF state, its PKCE verifier and the tokens Google
 * issues. Nothing is stored here.
 *
 *   POST /v1/sites      register a site (proves control via a challenge the site serves)
 *   GET  /v1/authorize  interstitial naming the site; POST continues to Google
 *   GET  /v1/callback   Google's redirect; bounces `code` + the site's state to the site
 *   POST /v1/token      adds the client secret and forwards to Google's token endpoint
 *   GET  /v1/health
 */

import { RELAY_CALLBACK_PATH, RELAY_VERSION } from './constants';
import { STATE_TTL_SECONDS, deriveSiteId, deriveSiteSecret, signState, timingSafeEqual, verifyState } from './crypto';
import { GOOGLE_TOKEN_ENDPOINT, ALLOWED_SCOPES, googleAuthorizeUrl, parseScopes } from './google';
import { errorPage, interstitialPage } from './html';
import { withinLimits } from './ratelimit';
import { canonicalizeSiteUrl, challengeUrlFor, siteAdminUrl, siteCallbackUrl, type CanonicalSite } from './site';
import type { Env } from './types';

const SITE_ID_RE = /^[a-f0-9]{32}$/;
const SITE_STATE_RE = /^[A-Za-z0-9._~-]{16,256}$/;
const CODE_CHALLENGE_RE = /^[A-Za-z0-9_-]{43}$/;
const CODE_VERIFIER_RE = /^[A-Za-z0-9._~-]{43,128}$/;
const CHALLENGE_RE = /^[A-Za-z0-9_-]{32,128}$/;
const OPAQUE_TOKEN_RE = /^[\x21-\x7e]{1,2048}$/;
const OAUTH_ERROR_RE = /^[a-z_]{1,64}$/;

const MAX_BODY_BYTES = 16 * 1024;
const CHALLENGE_TIMEOUT_MS = 10_000;
const GOOGLE_TIMEOUT_MS = 20_000;

const HTML_HEADERS: Record<string, string> = {
	'Content-Type': 'text/html; charset=utf-8',
	'Cache-Control': 'no-store',
	'Referrer-Policy': 'no-referrer',
	'X-Content-Type-Options': 'nosniff',
	'X-Frame-Options': 'DENY',
	'Content-Security-Policy': "default-src 'none'; style-src 'unsafe-inline'; form-action 'self' https://accounts.google.com; frame-ancestors 'none'; base-uri 'none'",
};

const JSON_HEADERS: Record<string, string> = {
	'Content-Type': 'application/json; charset=utf-8',
	'Cache-Control': 'no-store',
	'X-Content-Type-Options': 'nosniff',
};

/** Errors the relay itself raises. All carry the `relay_` prefix so the plugin can tell them from Google's. */
type RelayErrorCode =
	| 'relay_invalid_request'
	| 'relay_not_configured'
	| 'relay_rate_limited'
	| 'relay_site_unreachable'
	| 'relay_challenge_mismatch'
	| 'relay_site_not_registered'
	| 'relay_invalid_site_credentials'
	| 'relay_invalid_state'
	| 'relay_scope_not_allowed'
	| 'relay_upstream_unavailable'
	| 'relay_upstream_invalid'
	| 'relay_not_found'
	| 'relay_method_not_allowed';

function json(status: number, body: unknown, extra: Record<string, string> = {}): Response {
	return new Response(JSON.stringify(body), { status, headers: { ...JSON_HEADERS, ...extra } });
}

function relayError(status: number, code: RelayErrorCode, description: string): Response {
	log('error', { status, code });
	return json(status, { error: code, error_description: description }, { 'X-Convertrack-Relay': 'error' });
}

function html(status: number, markup: string): Response {
	return new Response(markup, { status, headers: HTML_HEADERS });
}

function htmlError(status: number, code: RelayErrorCode, title: string, message: string): Response {
	log('error', { status, code });
	return html(status, errorPage(title, message, code));
}

/** Structured, minimal logging: never a URL, query string, token or body. */
function log(kind: string, fields: Record<string, string | number | boolean>): void {
	console.log(JSON.stringify({ relay: kind, ...fields }));
}

function isConfigured(env: Env): env is Env & { GOOGLE_CLIENT_ID: string; GOOGLE_CLIENT_SECRET: string; RELAY_HMAC_KEY: string } {
	return Boolean(env.GOOGLE_CLIENT_ID && env.GOOGLE_CLIENT_SECRET && env.RELAY_HMAC_KEY && env.RELAY_HMAC_KEY.length >= 16);
}

function allowInsecure(env: Env): boolean {
	return (env.ALLOW_INSECURE_SITES ?? '').toLowerCase() === 'true';
}

function publicOrigin(env: Env, request: Request): string {
	const configured = (env.RELAY_PUBLIC_URL ?? '').trim();
	if (configured !== '') {
		return configured.replace(/\/+$/, '');
	}
	return new URL(request.url).origin;
}

function relayCallback(env: Env, request: Request): string {
	return publicOrigin(env, request) + RELAY_CALLBACK_PATH;
}

function clientIp(request: Request): string {
	return request.headers.get('cf-connecting-ip') ?? 'unknown';
}

function nowSeconds(): number {
	return Math.floor(Date.now() / 1000);
}

async function readBody(request: Request): Promise<string | null> {
	const declared = Number(request.headers.get('content-length') ?? '0');
	if (Number.isFinite(declared) && declared > MAX_BODY_BYTES) {
		return null;
	}
	const text = await request.text();
	return text.length > MAX_BODY_BYTES ? null : text;
}

/** Accepts JSON or form bodies and returns a flat string map. */
async function readParams(request: Request): Promise<Record<string, string> | null> {
	const text = await readBody(request);
	if (text === null) {
		return null;
	}
	const type = (request.headers.get('content-type') ?? '').toLowerCase();
	const out: Record<string, string> = {};
	if (type.includes('application/json')) {
		let parsed: unknown;
		try {
			parsed = JSON.parse(text);
		} catch {
			return null;
		}
		if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
			return null;
		}
		for (const [key, value] of Object.entries(parsed as Record<string, unknown>)) {
			if (typeof value === 'string') {
				out[key] = value;
			}
		}
		return out;
	}
	const params = new URLSearchParams(text);
	for (const [key, value] of params) {
		out[key] = value;
	}
	return out;
}

/* ---------------------------------------------------------------- health */

function handleHealth(env: Env): Response {
	return json(200, { ok: true, service: 'convertrack-gsc-relay', version: RELAY_VERSION, configured: isConfigured(env) });
}

/* ------------------------------------------------------------- register */

async function fetchChallenge(url: string): Promise<{ ok: true; challenge: string } | { ok: false; reason: string }> {
	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), CHALLENGE_TIMEOUT_MS);
	try {
		const response = await fetch(url, {
			method: 'GET',
			redirect: 'manual',
			signal: controller.signal,
			headers: {
				Accept: 'application/json',
				'User-Agent': 'Convertrack-Relay/' + RELAY_VERSION + ' (+https://github.com/Cornbip19/convertrack)',
			},
		});
		if (response.status !== 200) {
			return { ok: false, reason: 'the site answered HTTP ' + response.status };
		}
		const text = await response.text();
		if (text.length > 4096) {
			return { ok: false, reason: 'the site answered with an unexpectedly large body' };
		}
		let parsed: unknown;
		try {
			parsed = JSON.parse(text);
		} catch {
			return { ok: false, reason: 'the site did not answer with JSON' };
		}
		const challenge = parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>).challenge : undefined;
		if (typeof challenge !== 'string' || !CHALLENGE_RE.test(challenge)) {
			return { ok: false, reason: 'the site did not publish a registration challenge' };
		}
		return { ok: true, challenge };
	} catch (error) {
		return { ok: false, reason: controller.signal.aborted ? 'the site did not answer within 10 seconds' : 'the site could not be reached' };
	} finally {
		clearTimeout(timer);
	}
}

async function handleRegister(request: Request, env: Env): Promise<Response> {
	if (!isConfigured(env)) {
		return relayError(503, 'relay_not_configured', 'The relay has no Google client or signing key configured.');
	}
	const ip = clientIp(request);
	if (!(await withinLimits(env, ['sites:ip:' + ip]))) {
		return relayError(429, 'relay_rate_limited', 'Too many registration attempts from this address. Try again in a minute.');
	}
	const params = await readParams(request);
	if (!params) {
		return relayError(400, 'relay_invalid_request', 'Send a JSON object with site_url and challenge.');
	}
	const siteResult = canonicalizeSiteUrl(params.site_url, allowInsecure(env));
	if (!siteResult.ok) {
		return relayError(400, 'relay_invalid_request', siteResult.reason);
	}
	const site = siteResult.site;
	const challenge = params.challenge ?? '';
	if (!CHALLENGE_RE.test(challenge)) {
		return relayError(400, 'relay_invalid_request', 'challenge must be 32-128 URL-safe characters.');
	}
	if (!(await withinLimits(env, ['sites:host:' + site.host]))) {
		return relayError(429, 'relay_rate_limited', 'Too many registration attempts for this site. Try again in a minute.');
	}

	const challengeUrl = challengeUrlFor(site, params.challenge_url);
	const fetched = await fetchChallenge(challengeUrl);
	if (!fetched.ok) {
		return relayError(502, 'relay_site_unreachable', 'Could not confirm the site: ' + fetched.reason + '. The site must be reachable from the internet and the plugin active.');
	}
	if (!(await timingSafeEqual(fetched.challenge, challenge))) {
		return relayError(403, 'relay_challenge_mismatch', 'The site published a different challenge. Start the connection again from the plugin.');
	}

	const siteId = await deriveSiteId(env.RELAY_HMAC_KEY, site.url);
	const siteSecret = await deriveSiteSecret(env.RELAY_HMAC_KEY, siteId);
	log('register', { status: 200, host: site.host });
	return json(200, {
		site_id: siteId,
		site_secret: siteSecret,
		site_url: site.url,
		relay_url: publicOrigin(env, request),
		relay_callback: relayCallback(env, request),
	});
}

/* ------------------------------------------------------------ authorize */

interface AuthorizeRequest {
	site: CanonicalSite;
	siteId: string;
	state: string;
	codeChallenge: string;
	scopes: string[];
}

async function validateAuthorize(params: Record<string, string>, env: Env & { RELAY_HMAC_KEY: string }): Promise<AuthorizeRequest | Response> {
	const siteResult = canonicalizeSiteUrl(params.site_url, allowInsecure(env));
	if (!siteResult.ok) {
		return htmlError(400, 'relay_invalid_request', 'This link is not valid', 'The site address in this link is not acceptable: ' + siteResult.reason + '.');
	}
	const siteId = params.site_id ?? '';
	if (!SITE_ID_RE.test(siteId)) {
		return htmlError(400, 'relay_invalid_request', 'This link is not valid', 'The site identifier is missing or malformed.');
	}
	const state = params.state ?? '';
	if (!SITE_STATE_RE.test(state)) {
		return htmlError(400, 'relay_invalid_request', 'This link is not valid', 'The security token from the site is missing or malformed.');
	}
	const codeChallenge = params.code_challenge ?? '';
	if (!CODE_CHALLENGE_RE.test(codeChallenge)) {
		return htmlError(400, 'relay_invalid_request', 'This link is not valid', 'The PKCE challenge from the site is missing or malformed.');
	}
	const scopes = parseScopes(params.scopes ?? null);
	if (!scopes) {
		return htmlError(400, 'relay_scope_not_allowed', 'This link is not valid', 'The site asked for a Google permission this relay does not broker.');
	}
	const expectedId = await deriveSiteId(env.RELAY_HMAC_KEY, siteResult.site.url);
	if (!(await timingSafeEqual(siteId, expectedId))) {
		return htmlError(403, 'relay_site_not_registered', 'Site not registered', 'This site is not registered with this relay, or its registration is out of date.');
	}
	return { site: siteResult.site, siteId, state, codeChallenge, scopes };
}

async function handleAuthorizeGet(request: Request, env: Env): Promise<Response> {
	if (!isConfigured(env)) {
		return htmlError(503, 'relay_not_configured', 'Relay not configured', 'This relay has no Google client configured yet.');
	}
	const url = new URL(request.url);
	const params: Record<string, string> = {};
	for (const [key, value] of url.searchParams) {
		params[key] = value;
	}
	const validated = await validateAuthorize(params, env);
	if (validated instanceof Response) {
		return validated;
	}
	return html(
		200,
		interstitialPage({
			siteUrl: validated.site.url,
			adminUrl: siteAdminUrl(validated.site),
			operator: env.RELAY_OPERATOR_NAME || 'The relay',
			fields: {
				site_id: validated.siteId,
				site_url: validated.site.url,
				state: validated.state,
				code_challenge: validated.codeChallenge,
				scopes: validated.scopes.join(' '),
			},
			scopeSummary: validated.scopes.map((scope) => ALLOWED_SCOPES[scope] ?? scope),
		}),
	);
}

async function handleAuthorizePost(request: Request, env: Env): Promise<Response> {
	if (!isConfigured(env)) {
		return htmlError(503, 'relay_not_configured', 'Relay not configured', 'This relay has no Google client configured yet.');
	}
	const params = await readParams(request);
	if (!params) {
		return htmlError(400, 'relay_invalid_request', 'This request is not valid', 'The form could not be read.');
	}
	const validated = await validateAuthorize(params, env);
	if (validated instanceof Response) {
		return validated;
	}
	const state = await signState(env.RELAY_HMAC_KEY, {
		v: 1,
		sid: validated.siteId,
		site: validated.site.url,
		st: validated.state,
		exp: nowSeconds() + STATE_TTL_SECONDS,
	});
	const location = googleAuthorizeUrl({
		clientId: env.GOOGLE_CLIENT_ID,
		redirectUri: relayCallback(env, request),
		scopes: validated.scopes,
		state,
		codeChallenge: validated.codeChallenge,
	});
	log('authorize', { status: 302, host: validated.site.host });
	return new Response(null, {
		status: 302,
		headers: { Location: location, 'Cache-Control': 'no-store', 'Referrer-Policy': 'no-referrer' },
	});
}

/* ------------------------------------------------------------- callback */

async function handleCallback(request: Request, env: Env): Promise<Response> {
	if (!isConfigured(env)) {
		return htmlError(503, 'relay_not_configured', 'Relay not configured', 'This relay has no Google client configured yet.');
	}
	const url = new URL(request.url);
	const payload = await verifyState(env.RELAY_HMAC_KEY, url.searchParams.get('state') ?? '', nowSeconds());
	if (!payload) {
		return htmlError(400, 'relay_invalid_state', 'This sign-in link has expired', 'The sign-in took too long or the link was altered. Nothing was connected.');
	}
	// The destination is rebuilt from the signed site URL and re-validated, so
	// a browser is only ever sent to the plugin's fixed callback on an https
	// (or localhost) host. Anything else is an error page, never a redirect.
	const siteResult = canonicalizeSiteUrl(payload.site, allowInsecure(env));
	if (!siteResult.ok || !SITE_STATE_RE.test(payload.st)) {
		return htmlError(400, 'relay_invalid_state', 'This sign-in link is not valid', 'The site this connection belongs to is not acceptable. Nothing was connected.');
	}
	const expectedId = await deriveSiteId(env.RELAY_HMAC_KEY, siteResult.site.url);
	if (!(await timingSafeEqual(payload.sid, expectedId))) {
		return htmlError(403, 'relay_site_not_registered', 'Site not registered', 'This site is no longer registered with this relay. Nothing was connected.');
	}
	const target = new URL(siteCallbackUrl(siteResult.site));
	target.searchParams.set('state', payload.st);

	const googleError = url.searchParams.get('error');
	if (googleError !== null) {
		target.searchParams.set('error', OAUTH_ERROR_RE.test(googleError) ? googleError : 'oauth_error');
	} else {
		const code = url.searchParams.get('code') ?? '';
		if (!OPAQUE_TOKEN_RE.test(code)) {
			return htmlError(400, 'relay_invalid_request', 'Google did not return a code', 'Google sent the browser back without an authorization code. Nothing was connected.');
		}
		target.searchParams.set('code', code);
	}
	log('callback', { status: 302, host: siteResult.site.host, error: googleError !== null });
	return new Response(null, {
		status: 302,
		headers: { Location: target.toString(), 'Cache-Control': 'no-store', 'Referrer-Policy': 'no-referrer' },
	});
}

/* ---------------------------------------------------------------- token */

async function handleToken(request: Request, env: Env): Promise<Response> {
	if (!isConfigured(env)) {
		return relayError(503, 'relay_not_configured', 'The relay has no Google client or signing key configured.');
	}
	const params = await readParams(request);
	if (!params) {
		return relayError(400, 'relay_invalid_request', 'Send a form or JSON body.');
	}
	const siteId = params.site_id ?? '';
	const siteSecret = params.site_secret ?? '';
	if (!SITE_ID_RE.test(siteId) || siteSecret === '') {
		return relayError(401, 'relay_invalid_site_credentials', 'site_id and site_secret are required.');
	}
	const expectedSecret = await deriveSiteSecret(env.RELAY_HMAC_KEY, siteId);
	if (!(await timingSafeEqual(siteSecret, expectedSecret))) {
		return relayError(401, 'relay_invalid_site_credentials', 'The site credentials were not accepted. Register the site again.');
	}

	const grantType = params.grant_type ?? '';
	const body = new URLSearchParams();
	if (grantType === 'authorization_code') {
		const code = params.code ?? '';
		const verifier = params.code_verifier ?? '';
		const redirectUri = params.redirect_uri ?? '';
		if (!OPAQUE_TOKEN_RE.test(code)) {
			return relayError(400, 'relay_invalid_request', 'code is required.');
		}
		if (!CODE_VERIFIER_RE.test(verifier)) {
			return relayError(400, 'relay_invalid_request', 'code_verifier is required (43-128 characters).');
		}
		if (redirectUri !== relayCallback(env, request)) {
			return relayError(400, 'relay_invalid_request', 'redirect_uri must be this relay\'s callback: ' + relayCallback(env, request));
		}
		body.set('grant_type', 'authorization_code');
		body.set('code', code);
		body.set('code_verifier', verifier);
		body.set('redirect_uri', redirectUri);
	} else if (grantType === 'refresh_token') {
		const refreshToken = params.refresh_token ?? '';
		if (!OPAQUE_TOKEN_RE.test(refreshToken)) {
			return relayError(400, 'relay_invalid_request', 'refresh_token is required.');
		}
		body.set('grant_type', 'refresh_token');
		body.set('refresh_token', refreshToken);
	} else {
		return relayError(400, 'relay_invalid_request', 'grant_type must be authorization_code or refresh_token.');
	}
	body.set('client_id', env.GOOGLE_CLIENT_ID);
	body.set('client_secret', env.GOOGLE_CLIENT_SECRET);

	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), GOOGLE_TIMEOUT_MS);
	let upstream: Response;
	try {
		upstream = await fetch(GOOGLE_TOKEN_ENDPOINT, {
			method: 'POST',
			signal: controller.signal,
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
			body: body.toString(),
		});
	} catch {
		return relayError(502, 'relay_upstream_unavailable', 'Google\'s token endpoint could not be reached. Try again shortly.');
	} finally {
		clearTimeout(timer);
	}

	// Google's answer goes back verbatim (status and JSON) so the plugin sees a
	// real invalid_grant only when Google said so. A non-JSON answer is a relay
	// error, not a Google verdict.
	const text = await upstream.text();
	let parsed: unknown;
	try {
		parsed = JSON.parse(text);
	} catch {
		return relayError(502, 'relay_upstream_invalid', 'Google\'s token endpoint answered with something other than JSON (HTTP ' + upstream.status + ').');
	}
	if (!parsed || typeof parsed !== 'object') {
		return relayError(502, 'relay_upstream_invalid', 'Google\'s token endpoint answered with unexpected JSON.');
	}
	log('token', { status: upstream.status, grant: grantType });
	return json(upstream.status, parsed, { 'X-Convertrack-Relay': 'passthrough' });
}

/* --------------------------------------------------------------- router */

export default {
	async fetch(request: Request, env: Env): Promise<Response> {
		const url = new URL(request.url);
		const path = url.pathname.replace(/\/+$/, '') || '/';
		const method = request.method.toUpperCase();

		switch (path) {
			case '/v1/health':
				return method === 'GET' || method === 'HEAD' ? handleHealth(env) : methodNotAllowed('GET');
			case '/v1/sites':
				return method === 'POST' ? handleRegister(request, env) : methodNotAllowed('POST');
			case '/v1/authorize':
				if (method === 'GET') {
					return handleAuthorizeGet(request, env);
				}
				if (method === 'POST') {
					return handleAuthorizePost(request, env);
				}
				return methodNotAllowed('GET, POST');
			case RELAY_CALLBACK_PATH:
				return method === 'GET' ? handleCallback(request, env) : methodNotAllowed('GET');
			case '/v1/token':
				return method === 'POST' ? handleToken(request, env) : methodNotAllowed('POST');
			case '/':
				return json(200, { service: 'convertrack-gsc-relay', version: RELAY_VERSION, docs: 'https://github.com/Cornbip19/convertrack/tree/main/relay' });
			default:
				return relayError(404, 'relay_not_found', 'No such endpoint.');
		}
	},
} satisfies ExportedHandler<Env>;

function methodNotAllowed(allow: string): Response {
	const response = relayError(405, 'relay_method_not_allowed', 'Method not allowed.');
	response.headers.set('Allow', allow);
	return response;
}
