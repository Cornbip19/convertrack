import { SELF, createExecutionContext, env, waitOnExecutionContext } from 'cloudflare:test';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import worker from '../src/index';
import { RELAY_CALLBACK_PATH } from '../src/constants';
import { STATE_TTL_SECONDS, deriveSiteId, deriveSiteSecret, signState, verifyState } from '../src/crypto';
import { resetMemoryLimiter } from '../src/ratelimit';
import { canonicalizeSiteUrl, challengeUrlFor, isPublicHost } from '../src/site';
import { parseScopes } from '../src/google';

const RELAY = 'https://relay.test';
const RELAY_CALLBACK = RELAY + RELAY_CALLBACK_PATH;
const SITE = 'https://example.com';
const SITE_CALLBACK = SITE + '/wp-admin/admin-post.php?action=convertrack_gsc_oauth_callback';
const CHALLENGE_PATH = '/wp-json/convertrack/v1/gsc/relay-challenge';
const CHALLENGE = 'Q'.repeat(48);
const SITE_STATE = 'siteStateSiteStateSiteState01234';
const CODE_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
const CODE_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
const KEY = env.RELAY_HMAC_KEY as string;

function now(): number {
	return Math.floor(Date.now() / 1000);
}

async function post(path: string, body: Record<string, string> | string, headers: Record<string, string> = {}): Promise<Response> {
	const isJson = typeof body !== 'string';
	return SELF.fetch(RELAY + path, {
		method: 'POST',
		redirect: 'manual',
		headers: { 'content-type': isJson ? 'application/json' : 'application/x-www-form-urlencoded', ...headers },
		body: isJson ? JSON.stringify(body) : body,
	});
}

type Expectation = { method: string; origin: string; path: string | ((path: string) => boolean); respond: (body: string) => Response | Promise<Response> };
const expectations: Expectation[] = [];

/**
 * The Worker under test runs in this isolate, so stubbing the global fetch
 * intercepts its outbound calls (to the site's challenge route and to
 * Google). Every expectation must be consumed, and anything unexpected fails.
 */
function stubFetch(): void {
	vi.stubGlobal('fetch', async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
		const request = new Request(input, init);
		const url = new URL(request.url);
		const index = expectations.findIndex(
			(e) => e.method === request.method && e.origin === url.origin && (typeof e.path === 'string' ? e.path === url.pathname + url.search : e.path(url.pathname + url.search)),
		);
		if (index === -1) {
			throw new Error('Unexpected outbound request: ' + request.method + ' ' + url.origin + url.pathname);
		}
		const [expectation] = expectations.splice(index, 1);
		return expectation!.respond(await request.text());
	});
}

function expectChallenge(origin = SITE, path: string | ((p: string) => boolean) = CHALLENGE_PATH, status = 200, body: unknown = { challenge: CHALLENGE }): void {
	expectations.push({
		method: 'GET',
		origin,
		path,
		respond: () => new Response(typeof body === 'string' ? body : JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } }),
	});
}

async function register(siteUrl = SITE, ip = '203.0.113.10', extra: Record<string, string> = {}): Promise<Response> {
	return post('/v1/sites', { site_url: siteUrl, challenge: CHALLENGE, ...extra }, { 'cf-connecting-ip': ip });
}

async function credentials(siteUrl = SITE): Promise<{ site_id: string; site_secret: string }> {
	const site_id = await deriveSiteId(KEY, siteUrl);
	return { site_id, site_secret: await deriveSiteSecret(KEY, site_id) };
}

beforeEach(() => {
	resetMemoryLimiter();
	expectations.length = 0;
	stubFetch();
});

afterEach(() => {
	vi.unstubAllGlobals();
	expect(expectations, 'every expected outbound request was made').toHaveLength(0);
});

describe('routing and health', () => {
	it('reports health without leaking configuration values', async () => {
		const response = await SELF.fetch(RELAY + '/v1/health');
		expect(response.status).toBe(200);
		const body = await response.json<Record<string, unknown>>();
		expect(body).toMatchObject({ ok: true, configured: true });
		expect(JSON.stringify(body)).not.toContain('test-client-secret');
	});

	it('answers 404 and 405 with relay_ error codes', async () => {
		const missing = await SELF.fetch(RELAY + '/v1/nope');
		expect(missing.status).toBe(404);
		expect((await missing.json<{ error: string }>()).error).toBe('relay_not_found');

		const wrongMethod = await SELF.fetch(RELAY + '/v1/token');
		expect(wrongMethod.status).toBe(405);
		expect(wrongMethod.headers.get('allow')).toBe('POST');
	});

	it('refuses everything with 503 relay_not_configured when secrets are missing', async () => {
		const ctx = createExecutionContext();
		const response = await worker.fetch(
			new Request(RELAY + '/v1/token', { method: 'POST', headers: { 'content-type': 'application/json' }, body: '{}' }),
			{ ...env, GOOGLE_CLIENT_SECRET: '' },
		);
		await waitOnExecutionContext(ctx);
		expect(response.status).toBe(503);
		expect((await response.json<{ error: string }>()).error).toBe('relay_not_configured');
	});
});

describe('site URL handling', () => {
	it('canonicalises scheme, host case, trailing slashes and install paths', () => {
		const root = canonicalizeSiteUrl('https://Example.COM/', false);
		expect(root).toEqual({ ok: true, site: { url: 'https://example.com', host: 'example.com', origin: 'https://example.com', path: '' } });
		const sub = canonicalizeSiteUrl('https://example.com/blog///', false);
		expect(sub.ok && sub.site.url).toBe('https://example.com/blog');
		expect(sub.ok && sub.site.path).toBe('/blog');
	});

	it('rejects http (except localhost), credentials, queries, admin paths and private hosts', () => {
		expect(canonicalizeSiteUrl('http://example.com', false).ok).toBe(false);
		expect(canonicalizeSiteUrl('http://localhost:8000', false).ok).toBe(true);
		expect(canonicalizeSiteUrl('http://site.localhost', false).ok).toBe(true);
		expect(canonicalizeSiteUrl('https://user:pw@example.com', false).ok).toBe(false);
		expect(canonicalizeSiteUrl('https://example.com/?x=1', false).ok).toBe(false);
		expect(canonicalizeSiteUrl('https://example.com/wp-admin', false).ok).toBe(false);
		// The URL parser resolves dot segments before the relay sees the path.
		const dots = canonicalizeSiteUrl('https://example.com/a/../b', false);
		expect(dots.ok && dots.site.url).toBe('https://example.com/b');
		expect(canonicalizeSiteUrl('https://intranet', false).ok).toBe(false);
		expect(canonicalizeSiteUrl('https://10.0.0.1', false).ok).toBe(false);
		expect(canonicalizeSiteUrl('https://dev.local', false).ok).toBe(false);
		expect(canonicalizeSiteUrl('http://dev.local', true).ok).toBe(true);
		expect(isPublicHost('example.com:8443')).toBe(true);
	});

	it('only honours a challenge URL on the registered origin and install path', () => {
		const site = canonicalizeSiteUrl('https://example.com/blog', false);
		if (!site.ok) throw new Error('unexpected');
		expect(challengeUrlFor(site.site, undefined)).toBe('https://example.com/blog' + CHALLENGE_PATH);
		expect(challengeUrlFor(site.site, 'https://example.com/blog/?rest_route=/convertrack/v1/gsc/relay-challenge')).toBe(
			'https://example.com/blog/?rest_route=/convertrack/v1/gsc/relay-challenge',
		);
		expect(challengeUrlFor(site.site, 'https://evil.example/wp-json/x')).toBe('https://example.com/blog' + CHALLENGE_PATH);
		expect(challengeUrlFor(site.site, 'https://example.com/other/wp-json/x')).toBe('https://example.com/blog' + CHALLENGE_PATH);
		expect(challengeUrlFor(site.site, 'http://example.com/blog/wp-json/x')).toBe('https://example.com/blog' + CHALLENGE_PATH);
	});

	it('parses scopes against the allow-list', () => {
		expect(parseScopes(null)).toEqual(['https://www.googleapis.com/auth/webmasters', 'openid', 'email']);
		expect(parseScopes('openid https://www.googleapis.com/auth/indexing openid')).toEqual(['openid', 'https://www.googleapis.com/auth/indexing']);
		expect(parseScopes('https://www.googleapis.com/auth/drive')).toBeNull();
	});
});

describe('POST /v1/sites', () => {
	it('registers a site after fetching its challenge and derives stable credentials', async () => {
		expectChallenge();
		const response = await register();
		expect(response.status).toBe(200);
		const body = await response.json<Record<string, string>>();
		expect(body.site_id).toMatch(/^[a-f0-9]{32}$/);
		expect(body.site_id).toBe(await deriveSiteId(KEY, SITE));
		expect(body.site_secret).toBe(await deriveSiteSecret(KEY, body.site_id ?? ''));
		expect(body.site_url).toBe(SITE);
		expect(body.relay_callback).toBe(RELAY_CALLBACK);

		// Same site, different spelling: same credentials, so re-registration is idempotent.
		expectChallenge();
		const again = await (await register('https://EXAMPLE.com/', '203.0.113.11')).json<Record<string, string>>();
		expect(again.site_id).toBe(body.site_id);
		expect(again.site_secret).toBe(body.site_secret);
	});

	it('uses the plugin-supplied challenge URL when it lives on the same origin', async () => {
		expectChallenge(SITE, (p) => p.startsWith('/?rest_route=/convertrack/v1/gsc/relay-challenge'));
		const response = await register(SITE, '203.0.113.12', { challenge_url: SITE + '/?rest_route=/convertrack/v1/gsc/relay-challenge' });
		expect(response.status).toBe(200);
	});

	it('ignores a challenge URL on another origin', async () => {
		expectChallenge();
		const response = await register(SITE, '203.0.113.13', { challenge_url: 'https://attacker.example/wp-json/convertrack/v1/gsc/relay-challenge' });
		expect(response.status).toBe(200);
	});

	it('refuses when the site publishes a different challenge', async () => {
		expectChallenge(SITE, CHALLENGE_PATH, 200, { challenge: 'R'.repeat(48) });
		const response = await register(SITE, '203.0.113.14');
		expect(response.status).toBe(403);
		const body = await response.json<Record<string, string>>();
		expect(body.error).toBe('relay_challenge_mismatch');
		expect(body.site_secret).toBeUndefined();
	});

	it('refuses an http site without contacting it', async () => {
		const response = await register('http://example.com', '203.0.113.15');
		expect(response.status).toBe(400);
		expect((await response.json<{ error: string }>()).error).toBe('relay_invalid_request');
	});

	it('reports an unreachable or broken site as relay_site_unreachable', async () => {
		expectChallenge(SITE, CHALLENGE_PATH, 500, 'nope');
		const response = await register(SITE, '203.0.113.16');
		expect(response.status).toBe(502);
		expect((await response.json<{ error: string }>()).error).toBe('relay_site_unreachable');
	});

	it('rate-limits registration attempts per client address', async () => {
		let last = 0;
		for (let i = 0; i < 21; i++) {
			last = (await post('/v1/sites', '', { 'cf-connecting-ip': '198.51.100.77' })).status;
		}
		expect(last).toBe(429);
	});
});

describe('GET/POST /v1/authorize', () => {
	async function authorizeParams(siteUrl = SITE): Promise<URLSearchParams> {
		const { site_id } = await credentials(siteUrl);
		return new URLSearchParams({ site_id, site_url: siteUrl, state: SITE_STATE, code_challenge: CODE_CHALLENGE, scopes: 'https://www.googleapis.com/auth/webmasters openid email' });
	}

	it('shows an interstitial naming the site with a Continue form', async () => {
		const response = await SELF.fetch(RELAY + '/v1/authorize?' + (await authorizeParams()).toString());
		expect(response.status).toBe(200);
		expect(response.headers.get('content-type')).toContain('text/html');
		expect(response.headers.get('content-security-policy')).toContain("default-src 'none'");
		const page = await response.text();
		expect(page).toContain('Connect Search Console to <span class="site">https://example.com</span>?');
		expect(page).toContain('<form method="post" action="/v1/authorize"');
		expect(page).toContain(`name="code_challenge" value="${CODE_CHALLENGE}"`);
		expect(page).toContain(`name="state" value="${SITE_STATE}"`);
		expect(page).toContain('Continue to Google');
		expect(page).toContain('href="https://example.com/wp-admin/admin.php?page=convertrack-gsc"');
		expect(page).not.toContain('<script');
	});

	it('refuses an unregistered site id, a bad scope and a malformed challenge without a form', async () => {
		const params = await authorizeParams();
		params.set('site_id', 'd'.repeat(32));
		const unregistered = await SELF.fetch(RELAY + '/v1/authorize?' + params.toString());
		expect(unregistered.status).toBe(403);
		expect(await unregistered.text()).not.toContain('<form');

		const scoped = await authorizeParams();
		scoped.set('scopes', 'https://www.googleapis.com/auth/drive');
		expect((await SELF.fetch(RELAY + '/v1/authorize?' + scoped.toString())).status).toBe(400);

		const noChallenge = await authorizeParams();
		noChallenge.delete('code_challenge');
		expect((await SELF.fetch(RELAY + '/v1/authorize?' + noChallenge.toString())).status).toBe(400);
	});

	it('continues to Google with the relay client, the relay callback, the site PKCE challenge and a signed state', async () => {
		const response = await post('/v1/authorize', (await authorizeParams()).toString());
		expect(response.status).toBe(302);
		const location = new URL(response.headers.get('location') ?? '');
		expect(location.origin + location.pathname).toBe('https://accounts.google.com/o/oauth2/v2/auth');
		expect(location.searchParams.get('client_id')).toBe(env.GOOGLE_CLIENT_ID);
		expect(location.searchParams.get('redirect_uri')).toBe(RELAY_CALLBACK);
		expect(location.searchParams.get('response_type')).toBe('code');
		expect(location.searchParams.get('access_type')).toBe('offline');
		expect(location.searchParams.get('prompt')).toBe('consent');
		expect(location.searchParams.get('code_challenge')).toBe(CODE_CHALLENGE);
		expect(location.searchParams.get('code_challenge_method')).toBe('S256');
		expect(location.searchParams.get('scope')).toBe('https://www.googleapis.com/auth/webmasters openid email');
		expect(location.toString()).not.toContain('test-client-secret');

		const payload = await verifyState(KEY, location.searchParams.get('state') ?? '', now());
		expect(payload).toMatchObject({ v: 1, site: SITE, st: SITE_STATE, sid: (await credentials()).site_id });
		expect(payload && payload.exp).toBeGreaterThan(now() + STATE_TTL_SECONDS - 30);
	});

	it('will not continue for a site that is not registered', async () => {
		const params = await authorizeParams();
		params.set('site_url', 'https://other.example.com');
		const response = await post('/v1/authorize', params.toString());
		expect(response.status).toBe(403);
		expect(response.headers.get('location')).toBeNull();
	});
});

describe('GET /v1/callback', () => {
	async function signedState(overrides: Partial<{ site: string; sid: string; st: string; exp: number }> = {}): Promise<string> {
		const site = overrides.site ?? SITE;
		return signState(KEY, {
			v: 1,
			sid: overrides.sid ?? (await deriveSiteId(KEY, site)),
			site,
			st: overrides.st ?? SITE_STATE,
			exp: overrides.exp ?? now() + STATE_TTL_SECONDS,
		});
	}

	it('bounces the code and the site state to the fixed plugin callback', async () => {
		const state = await signedState();
		const response = await SELF.fetch(RELAY + RELAY_CALLBACK_PATH + '?' + new URLSearchParams({ state, code: '4/0AX4XfWgoogleCode_-' }).toString(), { redirect: 'manual' });
		expect(response.status).toBe(302);
		expect(response.headers.get('cache-control')).toBe('no-store');
		const location = new URL(response.headers.get('location') ?? '');
		expect(location.origin + location.pathname).toBe('https://example.com/wp-admin/admin-post.php');
		expect(location.searchParams.get('action')).toBe('convertrack_gsc_oauth_callback');
		expect(location.searchParams.get('code')).toBe('4/0AX4XfWgoogleCode_-');
		expect(location.searchParams.get('state')).toBe(SITE_STATE);
		expect(location.toString().startsWith(SITE_CALLBACK + '&')).toBe(true);
	});

	it('bounces a Google error with the site state and never a code', async () => {
		const state = await signedState();
		const response = await SELF.fetch(RELAY + RELAY_CALLBACK_PATH + '?' + new URLSearchParams({ state, error: 'access_denied' }).toString(), { redirect: 'manual' });
		expect(response.status).toBe(302);
		const location = new URL(response.headers.get('location') ?? '');
		expect(location.searchParams.get('error')).toBe('access_denied');
		expect(location.searchParams.get('state')).toBe(SITE_STATE);
		expect(location.searchParams.has('code')).toBe(false);

		const weird = await SELF.fetch(RELAY + RELAY_CALLBACK_PATH + '?' + new URLSearchParams({ state, error: '<script>' }).toString(), { redirect: 'manual' });
		expect(new URL(weird.headers.get('location') ?? '').searchParams.get('error')).toBe('oauth_error');
	});

	it('shows an error page for an expired, tampered or missing state', async () => {
		const expired = await signedState({ exp: now() - 1 });
		const response = await SELF.fetch(RELAY + RELAY_CALLBACK_PATH + '?' + new URLSearchParams({ state: expired, code: 'abc' }).toString());
		expect(response.status).toBe(400);
		expect(response.headers.get('location')).toBeNull();
		expect(await response.text()).toContain('expired');

		const valid = await signedState();
		const tampered = valid.slice(0, -2) + (valid.endsWith('AA') ? 'BB' : 'AA');
		expect((await SELF.fetch(RELAY + RELAY_CALLBACK_PATH + '?' + new URLSearchParams({ state: tampered, code: 'abc' }).toString())).status).toBe(400);
		expect((await SELF.fetch(RELAY + RELAY_CALLBACK_PATH + '?code=abc')).status).toBe(400);
	});

	it('never redirects to an http site, an unregistered site or a missing code', async () => {
		const insecure = await signedState({ site: 'http://example.com' });
		const insecureResponse = await SELF.fetch(RELAY + RELAY_CALLBACK_PATH + '?' + new URLSearchParams({ state: insecure, code: 'abc' }).toString());
		expect(insecureResponse.status).toBe(400);
		expect(insecureResponse.headers.get('location')).toBeNull();

		const unregistered = await signedState({ sid: 'e'.repeat(32) });
		const unregisteredResponse = await SELF.fetch(RELAY + RELAY_CALLBACK_PATH + '?' + new URLSearchParams({ state: unregistered, code: 'abc' }).toString());
		expect(unregisteredResponse.status).toBe(403);
		expect(unregisteredResponse.headers.get('location')).toBeNull();

		const noCode = await SELF.fetch(RELAY + RELAY_CALLBACK_PATH + '?' + new URLSearchParams({ state: await signedState() }).toString());
		expect(noCode.status).toBe(400);
		expect(noCode.headers.get('location')).toBeNull();
	});
});

describe('POST /v1/token', () => {
	function expectGoogle(status: number, body: unknown, capture?: (body: string) => void): void {
		expectations.push({
			method: 'POST',
			origin: 'https://oauth2.googleapis.com',
			path: '/token',
			respond: (text) => {
				capture?.(text);
				return new Response(typeof body === 'string' ? body : JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
			},
		});
	}

	it('rejects bad site credentials with its own 401 and never calls Google', async () => {
		const { site_id } = await credentials();
		const response = await post('/v1/token', { site_id, site_secret: 'wrong', grant_type: 'refresh_token', refresh_token: 'r' });
		expect(response.status).toBe(401);
		expect(response.headers.get('x-convertrack-relay')).toBe('error');
		const body = await response.json<{ error: string }>();
		expect(body.error).toBe('relay_invalid_site_credentials');
		expect(body.error).not.toBe('invalid_grant');
	});

	it('exchanges a code with the relay client secret, the site verifier and the relay callback', async () => {
		let forwarded = '';
		const googleBody = { access_token: 'ya29.test', expires_in: 3599, refresh_token: '1//refresh', scope: 'https://www.googleapis.com/auth/webmasters openid email', token_type: 'Bearer', id_token: 'id.token' };
		expectGoogle(200, googleBody, (text) => (forwarded = text));

		const response = await post('/v1/token', {
			...(await credentials()),
			grant_type: 'authorization_code',
			code: '4/0AX4XfWgoogleCode',
			code_verifier: CODE_VERIFIER,
			redirect_uri: RELAY_CALLBACK,
			client_id: 'site-must-not-override',
		});
		expect(response.status).toBe(200);
		expect(response.headers.get('x-convertrack-relay')).toBe('passthrough');
		expect(await response.json()).toEqual(googleBody);

		const sent = new URLSearchParams(forwarded);
		expect(sent.get('grant_type')).toBe('authorization_code');
		expect(sent.get('code')).toBe('4/0AX4XfWgoogleCode');
		expect(sent.get('code_verifier')).toBe(CODE_VERIFIER);
		expect(sent.get('redirect_uri')).toBe(RELAY_CALLBACK);
		expect(sent.get('client_id')).toBe(env.GOOGLE_CLIENT_ID);
		expect(sent.get('client_secret')).toBe(env.GOOGLE_CLIENT_SECRET);
		expect(sent.has('site_secret')).toBe(false);
	});

	it('refuses an exchange without a verifier or with a foreign redirect_uri before Google is involved', async () => {
		const base = { ...(await credentials()), grant_type: 'authorization_code', code: 'abc' };
		const noVerifier = await post('/v1/token', { ...base, redirect_uri: RELAY_CALLBACK });
		expect(noVerifier.status).toBe(400);
		expect((await noVerifier.json<{ error: string }>()).error).toBe('relay_invalid_request');

		const wrongRedirect = await post('/v1/token', { ...base, code_verifier: CODE_VERIFIER, redirect_uri: SITE_CALLBACK });
		expect(wrongRedirect.status).toBe(400);
	});

	it('passes a Google invalid_grant through verbatim', async () => {
		expectGoogle(400, { error: 'invalid_grant', error_description: 'Token has been expired or revoked.' });
		const response = await post('/v1/token', { ...(await credentials()), grant_type: 'refresh_token', refresh_token: '1//dead' });
		expect(response.status).toBe(400);
		expect(response.headers.get('x-convertrack-relay')).toBe('passthrough');
		expect(await response.json()).toEqual({ error: 'invalid_grant', error_description: 'Token has been expired or revoked.' });
	});

	it('forwards a refresh grant as a form body', async () => {
		let forwarded = '';
		expectGoogle(200, { access_token: 'ya29.fresh', expires_in: 3599, token_type: 'Bearer' }, (text) => (forwarded = text));
		const response = await post('/v1/token', new URLSearchParams({ ...(await credentials()), grant_type: 'refresh_token', refresh_token: '1//refresh' }).toString());
		expect(response.status).toBe(200);
		const sent = new URLSearchParams(forwarded);
		expect(sent.get('grant_type')).toBe('refresh_token');
		expect(sent.get('refresh_token')).toBe('1//refresh');
		expect(sent.has('code')).toBe(false);
	});

	it('turns Google outages into 502 relay_ errors, never invalid_grant', async () => {
		expectations.push({ method: 'POST', origin: 'https://oauth2.googleapis.com', path: '/token', respond: () => {
				throw new Error('connection reset');
			},
		});
		const down = await post('/v1/token', { ...(await credentials()), grant_type: 'refresh_token', refresh_token: '1//refresh' });
		expect(down.status).toBe(502);
		expect((await down.json<{ error: string }>()).error).toBe('relay_upstream_unavailable');

		expectGoogle(502, '<html>Bad gateway</html>');
		const html = await post('/v1/token', { ...(await credentials()), grant_type: 'refresh_token', refresh_token: '1//refresh' });
		expect(html.status).toBe(502);
		expect((await html.json<{ error: string }>()).error).toBe('relay_upstream_invalid');
	});
});
