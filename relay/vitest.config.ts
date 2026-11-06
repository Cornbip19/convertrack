import { defineConfig } from 'vitest/config';
import { cloudflareTest } from '@cloudflare/vitest-plugin';

export default defineConfig({
	plugins: [
		cloudflareTest({
			wrangler: { configPath: './wrangler.toml' },
			miniflare: {
				// Test-only secrets. Real values live in `.dev.vars` / `wrangler secret`.
				bindings: {
					GOOGLE_CLIENT_ID: 'test-client-id.apps.googleusercontent.com',
					GOOGLE_CLIENT_SECRET: 'test-client-secret',
					RELAY_HMAC_KEY: 'test-hmac-key-0123456789abcdef0123456789abcdef',
					RELAY_PUBLIC_URL: 'https://relay.test',
					ALLOW_INSECURE_SITES: 'false',
				},
			},
		}),
	],
	test: {
		include: ['test/**/*.test.ts'],
	},
});
