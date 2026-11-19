/**
 * Site URL handling.
 *
 * The relay only ever sends a browser to one place on a site: the plugin's
 * fixed OAuth callback. Every site URL is reduced to a canonical form
 * (scheme + lower-case host + optional install path, no trailing slash) and
 * the callback is rebuilt from that, never taken from a request.
 */

export const SITE_CALLBACK_PATH = '/wp-admin/admin-post.php';
export const SITE_CALLBACK_ACTION = 'convertrack_gsc_oauth_callback';
export const SITE_CHALLENGE_PATH = '/wp-json/convertrack/v1/gsc/relay-challenge';

export interface CanonicalSite {
	/** Canonical URL: `https://example.com` or `https://example.com/blog`. */
	url: string;
	/** Lower-case host, including a non-default port. */
	host: string;
	/** `scheme://host[:port]`. */
	origin: string;
	/** Install path without trailing slash; empty for a root install. */
	path: string;
}

export type SiteResult = { ok: true; site: CanonicalSite } | { ok: false; reason: string };

const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]', '::1']);
const PRIVATE_SUFFIXES = ['.localhost', '.local', '.internal', '.test', '.example', '.invalid', '.home', '.lan', '.intranet', '.corp'];

export function isLocalHost(host: string): boolean {
	return LOCAL_HOSTS.has(host) || host.endsWith('.localhost');
}

function isIpLiteral(host: string): boolean {
	return /^\d{1,3}(\.\d{1,3}){3}$/.test(host) || host.startsWith('[');
}

/**
 * Hosts the relay refuses to contact or redirect to in production: loopback,
 * private-use names and single-label names. Local development opts in with
 * ALLOW_INSECURE_SITES.
 */
export function isPublicHost(host: string): boolean {
	const bare = host.replace(/:\d+$/, '');
	if (isLocalHost(bare) || isIpLiteral(bare)) {
		return false;
	}
	if (!bare.includes('.')) {
		return false;
	}
	for (const suffix of PRIVATE_SUFFIXES) {
		if (bare.endsWith(suffix)) {
			return false;
		}
	}
	return true;
}

export function canonicalizeSiteUrl(input: unknown, allowInsecure: boolean): SiteResult {
	if (typeof input !== 'string' || input.length === 0 || input.length > 2000) {
		return { ok: false, reason: 'site_url must be a string of at most 2000 characters' };
	}
	let url: URL;
	try {
		url = new URL(input);
	} catch {
		return { ok: false, reason: 'site_url is not an absolute URL' };
	}
	if (url.username || url.password) {
		return { ok: false, reason: 'site_url must not contain credentials' };
	}
	if (url.search || url.hash) {
		return { ok: false, reason: 'site_url must not contain a query string or fragment' };
	}
	const host = url.host.toLowerCase();
	const bareHost = url.hostname.toLowerCase();
	if (url.protocol === 'http:') {
		if (!allowInsecure && !isLocalHost(bareHost)) {
			return { ok: false, reason: 'site_url must use https (http is only allowed for localhost)' };
		}
	} else if (url.protocol !== 'https:') {
		return { ok: false, reason: 'site_url must use https' };
	}
	// localhost is exempt from the public-host rule (it is the one host a
	// browser can only ever reach on the operator's own machine).
	if (!allowInsecure && !isLocalHost(bareHost) && !isPublicHost(host)) {
		return { ok: false, reason: 'site_url must point at a public host' };
	}

	let path = url.pathname.replace(/\/+$/, '');
	if (path !== '' && !/^(\/[A-Za-z0-9._~!$&'()*+,;=:@%-]+)+$/.test(path)) {
		return { ok: false, reason: 'site_url path contains unsupported characters' };
	}
	if (/(^|\/)\.\.?(\/|$)/.test(path)) {
		return { ok: false, reason: 'site_url path must not contain dot segments' };
	}
	if (/\/wp-admin(\/|$)|\/wp-json(\/|$)|admin-post\.php/.test(path)) {
		return { ok: false, reason: 'site_url must be the site root, not an admin or REST URL' };
	}

	const origin = url.protocol + '//' + host;
	return { ok: true, site: { url: origin + path, host, origin, path } };
}

/** The one URL on a site the relay will ever redirect a browser to. */
export function siteCallbackUrl(site: CanonicalSite): string {
	return site.url + SITE_CALLBACK_PATH + '?action=' + SITE_CALLBACK_ACTION;
}

/** The plugin's admin screen, for the interstitial's Cancel link. */
export function siteAdminUrl(site: CanonicalSite): string {
	return site.url + '/wp-admin/admin.php?page=convertrack-gsc';
}

/**
 * Resolve where to fetch the registration challenge from. The plugin may pass
 * its own REST URL (plain permalinks use `?rest_route=`), but it has to live on
 * the registered site's origin and under its install path, or it is ignored.
 */
export function challengeUrlFor(site: CanonicalSite, provided: unknown): string {
	const fallback = site.url + SITE_CHALLENGE_PATH;
	if (typeof provided !== 'string' || provided.length === 0 || provided.length > 2000) {
		return fallback;
	}
	let url: URL;
	try {
		url = new URL(provided);
	} catch {
		return fallback;
	}
	if (url.username || url.password || url.hash) {
		return fallback;
	}
	if (url.protocol + '//' + url.host.toLowerCase() !== site.origin) {
		return fallback;
	}
	const path = url.pathname;
	if (site.path !== '' && path !== site.path && !path.startsWith(site.path + '/')) {
		return fallback;
	}
	if (/(^|\/)\.\.?(\/|$)/.test(path)) {
		return fallback;
	}
	url.hostname = url.hostname.toLowerCase();
	return url.toString();
}
