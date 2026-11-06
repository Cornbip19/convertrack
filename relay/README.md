# Convertrack Search Console relay

A stateless Cloudflare Worker that gives the Convertrack WordPress plugin a one-click **Sign in with Google** for Google Search Console, without each site owner creating a Google Cloud project, consent screen, OAuth client and redirect URI.

Google requires an OAuth web client to have a confidential client secret and pre-registered, exact HTTPS redirect URIs on a domain the app owner controls. A plugin distributed from a public repository cannot ship either. The relay owns them; everything else stays on the site. This is the model Site Kit and Rank Math use.

## What the relay does, and does not do

The relay only **brokers**:

| Step | Who does it |
| --- | --- |
| Generate the anti-CSRF `state` and the PKCE `code_verifier` / `code_challenge` | the site |
| Own the Google OAuth client (client ID, client secret, registered redirect URI) | the relay |
| Show the consent screen and issue the authorization code | Google |
| Bounce the code (and the site's own `state`) back to the site | the relay |
| Exchange the code, with the `code_verifier`, and refresh tokens | the site, through the relay, which adds the client secret and forwards to Google |
| Store the access and refresh tokens (encrypted) | the site |
| Call the Search Console API | the site, directly, with its own tokens |

The relay **stores nothing**: no database, no KV, no Durable Objects. Site credentials are derived with HMAC from a single secret key, the OAuth state is signed rather than stored, and tokens pass through without being logged. Search Console data never touches it.

```
   Site (WordPress)                 Relay (Worker)                     Google
   ────────────────                 ──────────────                     ──────
1. POST /v1/sites {site_url, challenge}  ──►
                                    GET site/wp-json/.../relay-challenge ──► (site)
                                    ◄── {challenge}
   ◄── {site_id, site_secret}
2. browser ──► GET /v1/authorize?site_id&site_url&state&code_challenge&scopes
                                    interstitial "Connect Search Console to https://example.com?"
   browser ──► POST /v1/authorize (Continue)
                                    302 ──────────────────────────────► accounts.google.com (relay client_id,
                                                                        redirect_uri = relay /v1/callback,
                                                                        site's code_challenge, signed state)
                                    ◄────────────────── 302 /v1/callback?code&state
   ◄── 302 site/wp-admin/admin-post.php?action=convertrack_gsc_oauth_callback&code&state=<site's state>
3. POST /v1/token {site_id, site_secret, grant_type, code, code_verifier, redirect_uri}
                                    + client_id + client_secret ──────► oauth2.googleapis.com/token
   ◄── Google's JSON and status, verbatim
```

## Endpoints

All responses carry `Cache-Control: no-store`. JSON errors raised by the relay itself look like `{"error":"relay_…","error_description":"…"}` with the header `X-Convertrack-Relay: error`; Google's token answers are returned verbatim with `X-Convertrack-Relay: passthrough`. The relay never emits `invalid_grant` itself, so the plugin clears a site's tokens only when Google says the grant is gone.

### `POST /v1/sites` — register a site

Body (JSON): `site_url` (the WordPress site URL, `https://` except localhost), `challenge` (32–128 URL-safe characters the plugin just minted), optional `challenge_url` (the plugin's REST URL for the challenge; honoured only on the same origin and install path).

The relay fetches `GET <site_url>/wp-json/convertrack/v1/gsc/relay-challenge` (or the supplied `challenge_url`) and requires it to answer `{"challenge":"<same value>"}`. That proves the caller controls the site. It then answers:

```json
{ "site_id": "32 hex chars", "site_secret": "…", "site_url": "https://example.com", "relay_url": "https://connect.example.com", "relay_callback": "https://connect.example.com/v1/callback" }
```

`site_id = HMAC(RELAY_HMAC_KEY, site_url)` and `site_secret = HMAC(RELAY_HMAC_KEY, site_id)`, so a repeated registration returns the same credentials and nothing is stored. Rate-limited per client IP and per site host (20 per minute each). Errors: `relay_invalid_request` (400), `relay_rate_limited` (429), `relay_site_unreachable` (502), `relay_challenge_mismatch` (403).

### `GET /v1/authorize` — consent interstitial

Query: `site_id`, `site_url`, `state` (the site's), `code_challenge` (the site's PKCE S256 challenge), `scopes` (space-separated; only `https://www.googleapis.com/auth/webmasters`, `…/webmasters.readonly`, `…/indexing`, `openid`, `email`, `profile` are brokered).

Shows "Connect Search Console to https://example.com?" with what the site will be allowed to do and a **Continue to Google** button. `site_id` must match the relay's derivation for `site_url`, otherwise an error page is shown and nothing continues.

### `POST /v1/authorize` — continue to Google

Same fields as a form body. Redirects (302) to `https://accounts.google.com/o/oauth2/v2/auth` with the relay's `client_id`, `redirect_uri` = the relay's `/v1/callback`, the **site's** `code_challenge` (`S256`), `access_type=offline`, `prompt=consent`, and a relay-signed `state` (HMAC over the site id, the canonical site URL, the site's own state and a ten-minute expiry).

### `GET /v1/callback` — Google's redirect

Verifies the signed state and its expiry, rebuilds the site's callback from the signed site URL, re-validates it (https, or localhost; the fixed path `/wp-admin/admin-post.php?action=convertrack_gsc_oauth_callback` on the registered site), then 302s the browser there with `code` and the site's original `state`. A Google error (`error=access_denied`) is bounced the same way. Anything invalid shows an error page and never redirects. The query string is never logged.

### `POST /v1/token` — token exchange and refresh

Body (form or JSON): `site_id`, `site_secret`, then either

- `grant_type=authorization_code`, `code`, `code_verifier` (43–128 characters), `redirect_uri` (must equal the relay's `/v1/callback`), or
- `grant_type=refresh_token`, `refresh_token`.

The relay appends `client_id` and `client_secret`, posts to `https://oauth2.googleapis.com/token`, and returns Google's JSON body and HTTP status verbatim. A missing verifier never reaches Google (400 `relay_invalid_request`); a wrong verifier is Google's call (see the PKCE test plan). Relay-side failures: `relay_invalid_site_credentials` (401; the plugin then re-registers once and retries), `relay_not_configured` (503), `relay_upstream_unavailable` / `relay_upstream_invalid` (502).

### `GET /v1/health`

`{"ok":true,"service":"convertrack-gsc-relay","version":"1.0.0","configured":true}`. `configured` is false until all three secrets are set.

## Owner's one-time setup checklist

Everything in this list can only be done by the relay operator (the plugin author), once.

1. **Domain.** Pick the relay's domain, e.g. `connect.convertrack.example`, on a zone in your Cloudflare account. Google's app verification also wants a **homepage** that describes the app and a **privacy policy** page on a domain you own (they can live on the main project site).
2. **Google Cloud project.** Create one and enable the **Google Search Console API**. Enable the **Web Search Indexing API** as well if sites should be able to use the plugin's Indexing API option through the relay.
3. **OAuth consent screen.** User type *External*. App name, support email, developer contact, the app's homepage, the privacy policy URL, and the relay domain under *Authorized domains*. Add the scopes `https://www.googleapis.com/auth/webmasters`, `openid`, `email` (and `https://www.googleapis.com/auth/indexing` if enabled). Then **publish** the app and **submit it for verification**: `webmasters` is a sensitive scope, so Google reviews the app (brand verification first; allow a few weeks). While the app is still in *Testing*, only listed test users can sign in and their refresh tokens expire after seven days.
4. **OAuth client.** Credentials → Create credentials → OAuth client ID → *Web application*. Authorized redirect URI: exactly `https://<relay-domain>/v1/callback`. No JavaScript origins are needed. Keep the client ID and secret for the next step.
5. **Deploy the Worker** (below), with the custom domain, and confirm `https://<relay-domain>/v1/health` reports `"configured": true`.
6. **Point the plugin at it.** Set `Relay::DEFAULT_URL` in `includes/gsc/class-gsc-relay.php` to `https://<relay-domain>` and release. Until that constant (or the `convertrack_gsc_relay_url` filter) names a real relay, the plugin shows Sign in with Google as unavailable and offers only the own-credentials path.

## Deploying (self-hosting)

Requirements: Node 20+, a Cloudflare account, the three values from the checklist.

```bash
cd relay
npm install
npx wrangler login
npx wrangler secret put GOOGLE_CLIENT_ID
npx wrangler secret put GOOGLE_CLIENT_SECRET
npx wrangler secret put RELAY_HMAC_KEY      # e.g. the output of: openssl rand -base64 48
npx wrangler deploy
```

Then add the custom domain: uncomment and edit the `routes` block in `wrangler.toml` (`{ pattern = "connect.example.com", custom_domain = true }`) and deploy again, or attach the domain in the Cloudflare dashboard under the Worker's *Settings → Domains & Routes*. The `workers.dev` URL also works, but the Google redirect URI must match whichever origin the relay is reached on, so pick one and keep it.

Variables in `wrangler.toml`:

| Name | Purpose |
| --- | --- |
| `RELAY_PUBLIC_URL` | Leave empty to derive the origin from each request. Set it only when a proxy rewrites the Host header. |
| `ALLOW_INSECURE_SITES` | `"true"` admits `http://` sites and non-public hosts. For `wrangler dev` only; keep `"false"` in production. |
| `RELAY_OPERATOR_NAME` | Name shown on the interstitial. |
| `[[ratelimits]] RATE_LIMITER` | Cloudflare's rate-limiting binding (20 requests per 60 s per key) for `POST /v1/sites`. Without it, an in-memory best-effort limiter is used. |

**Rotating `RELAY_HMAC_KEY`** invalidates every site's `site_secret` and every in-flight signed state. Sites recover on their own: the next token request gets `relay_invalid_site_credentials`, the plugin re-registers once and retries. A site that was registered by someone who no longer controls the domain keeps a valid secret until the key is rotated, because registration is deterministic; rotate the key if that ever matters.

**Logs** contain only the route, the HTTP status, the relay error code, the site host and the grant type: never a URL, a query string, a token, a code or a body.

## Local development

```bash
cd relay
npm install
cp .dev.vars.example .dev.vars    # fill in a Google client (or any values, to exercise everything but Google)
npm test                           # Vitest inside the Workers runtime
npm run typecheck
npm run dev                        # http://localhost:8787
```

`npm run dev` sets `ALLOW_INSECURE_SITES` from `.dev.vars`, so a local WordPress at e.g. `http://plugin-development.local` can register. Point the plugin at the dev relay from a must-use plugin or `functions.php`:

```php
add_filter( 'convertrack_gsc_relay_url', function () { return 'http://localhost:8787'; } );
```

The relay must be able to fetch the site's challenge route, and a real Google sign-in needs the dev relay's callback (`http://localhost:8787/v1/callback`) added to the Google client's redirect URIs (Google allows `http://localhost` for testing).

## Tests

`npm test` covers every endpoint without network: site URL canonicalisation and the public-host rules, registration (challenge fetch, mismatch, unreachable site, same-origin `challenge_url`, rate limiting), the interstitial and the Google redirect (relay client, relay callback, the site's PKCE challenge, signed state), the callback (bounce of `code` + site state, Google errors, expired/tampered state, refusal to redirect to http or unregistered sites), and the token endpoint (site credential check, verifier/redirect validation before Google is involved, verbatim passthrough of Google's `invalid_grant`, 502s for Google outages and non-JSON answers).

### Negative PKCE test plan (manual; needs a real Google client)

What it proves: a bounced code is useless without the verifier that only the site holds, so an attacker who sees the code in a browser history or a log cannot turn it into tokens, and the relay cannot either.

This cannot run in CI because it needs Google's consent screen. Run it once against `wrangler dev` with a real client in `.dev.vars` (and `http://localhost:8787/v1/callback` registered on that client) or against the deployed relay.

1. Pick a verifier and compute its challenge:
   ```bash
   VERIFIER=$(openssl rand -base64 48 | tr -d '=+/' | cut -c1-64)
   CHALLENGE=$(printf '%s' "$VERIFIER" | openssl dgst -sha256 -binary | openssl base64 -A | tr '+/' '-_' | tr -d '=')
   ```
2. Register a site you control (the plugin does this when you click **Sign in with Google**; or call `POST /v1/sites` by hand while the plugin's challenge route is live). Note the `site_id`.
3. Open `https://<relay>/v1/authorize?site_id=<site_id>&site_url=<site>&state=manualTestState0123456789&code_challenge=$CHALLENGE` in a browser, click **Continue to Google** and complete the consent. The relay bounces to the site's callback; the plugin rejects the state it did not mint (so it does **not** exchange the code) and the browser's address bar still shows `code=…`. Copy that code.
4. Exchange it with a **wrong** verifier, within a minute:
   ```bash
   curl -s -X POST https://<relay>/v1/token \
     -d site_id=<site_id> -d site_secret=<site_secret> \
     -d grant_type=authorization_code -d code='<code>' \
     -d code_verifier='wrongwrongwrongwrongwrongwrongwrongwrongwrong0' \
     -d redirect_uri=https://<relay>/v1/callback
   ```
   Expected: HTTP 400 from Google, passed through verbatim, with `"error": "invalid_grant"` and `X-Convertrack-Relay: passthrough`.
5. Exchange with a **missing** verifier: expected HTTP 400 `relay_invalid_request` from the relay (Google is never contacted).
6. Positive control: repeat step 3 for a fresh code (Google voids a code after a failed exchange) and exchange it with the correct `$VERIFIER`: expected HTTP 200 with `access_token` and `refresh_token`. Revoke that grant afterwards at <https://myaccount.google.com/permissions>.

## Manual end-to-end test plan (plugin + relay)

Preconditions: the relay deployed and `configured: true`; the plugin build points at it; a WordPress site reachable from the internet over https whose Google account owns a Search Console property.

### Fresh install

1. Convertrack → Search & SEO → Indexing shows one setup step, **Sign in with Google**, with the own-credentials path folded under *Advanced*.
2. Click **Sign in with Google**. The relay interstitial names the site; **Continue to Google**; choose the account; allow. You land back on the Indexing screen with "Google Search Console connected", the account email in the header, the matching property auto-selected and **monitoring on** (no "Connected, but monitoring is off" notice).
3. The activity log shows "Site registered with the sign-in service", "Google Search Console connected" and "Google Index Monitor turned on".
4. **Scan Sitemap**, then **Run Batch**: URLs are inspected and coverage figures appear.
5. Keyword Opportunities: run a sync; rows appear.
6. After 60+ minutes (the access token expires in one hour), run another batch or wait for the hourly cron: the log shows "Google access token refreshed." and inspections continue. Stop the relay briefly (or point the filter at a dead URL) and run a batch: the batch stops with "The sign-in service could not be reached…", the connection stays, and no reconnect notice appears. Restore the relay: the next batch works without reconnecting.

### Migration of an existing direct-mode site

1. On a site connected with its own Google Cloud credentials, update the plugin. The connection keeps working unchanged (mode reads as *direct*; the Advanced disclosure is open and shows the saved Client ID; the header says "with your own Google Cloud credentials").
2. Click **Switch to Sign in with Google**. The old grant is revoked, and you go straight to the relay interstitial and Google. After consenting you are connected again, through the relay.
3. Verify batch inspection (**Run Batch**), sitemap scan (**Scan Sitemap**), keyword sync, and a token refresh after 60+ minutes, as above.
4. Rollback: click **Disconnect**. Open *Advanced: use your own Google Cloud credentials*: the stored Client ID is still there. Click **Use my own Google Cloud credentials**, then **Connect Google Search Console**: the site is connected directly again and the same checks pass.

### Key rotation

Rotate `RELAY_HMAC_KEY` (`wrangler secret put`) on a relay with a connected site. The next refresh logs "The sign-in service rejected the site credentials; registering again." followed by "Site registered with the sign-in service" and "Google access token refreshed."; no reconnect is needed.

## Security notes

- The relay never sees a Google password, and holds a code only for the length of one redirect. Codes are bound to the site's PKCE verifier, which never leaves the site.
- The only place a browser is ever redirected to on a site is the plugin's fixed callback path on the registered, https (or localhost) host, rebuilt from the signed state: the relay is not an open redirector.
- Site registration proves control of the site through a challenge the site publishes for ten minutes on a public REST route that otherwise answers 404. Hosts that are loopback, private-use names, IP literals or single-label names are refused unless `ALLOW_INSECURE_SITES` is on.
- The interstitial names the site so that a link crafted by someone else cannot silently connect a victim's Google account to a stranger's site; the site's own `state` check rejects codes it did not ask for anyway.
- Response headers on HTML pages: `Content-Security-Policy: default-src 'none'; …; frame-ancestors 'none'`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`.
