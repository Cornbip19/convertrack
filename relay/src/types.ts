/**
 * Bindings and variables the relay Worker receives.
 *
 * Secrets are set with `wrangler secret put <NAME>` in production and via
 * `.dev.vars` for `wrangler dev`. Nothing here is ever written anywhere: the
 * Worker holds no storage at all.
 */
export interface RateLimiterBinding {
	limit(options: { key: string }): Promise<{ success: boolean }>;
}

export interface Env {
	/** OAuth client ID of the relay operator's Google Cloud project. */
	GOOGLE_CLIENT_ID?: string;
	/** Matching client secret. Never leaves this Worker. */
	GOOGLE_CLIENT_SECRET?: string;
	/** Key for every HMAC: site secrets and the signed OAuth state. */
	RELAY_HMAC_KEY?: string;
	/** Public origin of this relay; empty means "derive from the request". */
	RELAY_PUBLIC_URL?: string;
	/** "true" admits http:// and non-public hosts (local development only). */
	ALLOW_INSECURE_SITES?: string;
	/** Name shown on the consent interstitial. */
	RELAY_OPERATOR_NAME?: string;
	/** Cloudflare rate-limiting binding; optional so tests and old runtimes still work. */
	RATE_LIMITER?: RateLimiterBinding;
}

/** The relay-signed OAuth `state` carried through Google and back. */
export interface StatePayload {
	/** Payload version. */
	v: 1;
	/** Registered site id (hex). */
	sid: string;
	/** Canonical site URL the code is bounced back to. */
	site: string;
	/** The site's own anti-CSRF state, returned to it untouched. */
	st: string;
	/** Unix seconds after which the state is dead. */
	exp: number;
}
