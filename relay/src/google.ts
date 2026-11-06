export const GOOGLE_AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
export const GOOGLE_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

/** Scopes a site may ask for, with the plain-language line shown on the interstitial. */
export const ALLOWED_SCOPES: Record<string, string> = {
	'https://www.googleapis.com/auth/webmasters': 'Read and manage your Search Console data (URL inspection, sitemaps, search analytics).',
	'https://www.googleapis.com/auth/webmasters.readonly': 'Read your Search Console data.',
	'https://www.googleapis.com/auth/indexing': 'Send URL update notifications through the Indexing API.',
	openid: 'See which Google account is connected.',
	email: 'See the email address of the connected account.',
	profile: 'See basic profile information.',
};

export const DEFAULT_SCOPES = ['https://www.googleapis.com/auth/webmasters', 'openid', 'email'];

/** Normalise a space-separated scope string; null when any scope is not allowed. */
export function parseScopes(raw: string | null): string[] | null {
	const requested = (raw ?? '').split(/[\s+]+/).filter((s) => s.length > 0);
	if (requested.length === 0) {
		return DEFAULT_SCOPES.slice();
	}
	const out: string[] = [];
	for (const scope of requested) {
		if (!Object.prototype.hasOwnProperty.call(ALLOWED_SCOPES, scope)) {
			return null;
		}
		if (!out.includes(scope)) {
			out.push(scope);
		}
	}
	return out;
}

export interface AuthorizeParams {
	clientId: string;
	redirectUri: string;
	scopes: string[];
	state: string;
	codeChallenge: string;
}

export function googleAuthorizeUrl(params: AuthorizeParams): string {
	const url = new URL(GOOGLE_AUTH_ENDPOINT);
	url.searchParams.set('client_id', params.clientId);
	url.searchParams.set('redirect_uri', params.redirectUri);
	url.searchParams.set('response_type', 'code');
	url.searchParams.set('scope', params.scopes.join(' '));
	url.searchParams.set('access_type', 'offline');
	url.searchParams.set('prompt', 'consent');
	url.searchParams.set('include_granted_scopes', 'true');
	url.searchParams.set('state', params.state);
	url.searchParams.set('code_challenge', params.codeChallenge);
	url.searchParams.set('code_challenge_method', 'S256');
	return url.toString();
}
