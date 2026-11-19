import type { Env } from './types';

/**
 * Best-effort in-memory limiter used when the Cloudflare rate-limiting
 * binding is not available (tests, older runtimes). It is per isolate, so it
 * is a backstop, not a guarantee; production relies on the binding.
 */
const MEMORY_LIMIT = 20;
const MEMORY_PERIOD_MS = 60_000;
const memoryBuckets = new Map<string, { count: number; resetAt: number }>();

export function resetMemoryLimiter(): void {
	memoryBuckets.clear();
}

function memoryAllow(key: string, now: number): boolean {
	const bucket = memoryBuckets.get(key);
	if (!bucket || bucket.resetAt <= now) {
		memoryBuckets.set(key, { count: 1, resetAt: now + MEMORY_PERIOD_MS });
		return true;
	}
	bucket.count += 1;
	return bucket.count <= MEMORY_LIMIT;
}

/** True when every key is still within its allowance. */
export async function withinLimits(env: Env, keys: string[], now: number = Date.now()): Promise<boolean> {
	for (const key of keys) {
		if (env.RATE_LIMITER && typeof env.RATE_LIMITER.limit === 'function') {
			const { success } = await env.RATE_LIMITER.limit({ key });
			if (!success) {
				return false;
			}
		} else if (!memoryAllow(key, now)) {
			return false;
		}
	}
	return true;
}
