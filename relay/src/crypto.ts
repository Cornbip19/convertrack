import type { StatePayload } from './types';

const encoder = new TextEncoder();
const decoder = new TextDecoder();

/** How long a relay-signed state stays valid. */
export const STATE_TTL_SECONDS = 10 * 60;

export function base64url(bytes: ArrayBuffer | Uint8Array): string {
	const view = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
	let binary = '';
	for (const byte of view) {
		binary += String.fromCharCode(byte);
	}
	return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function base64urlDecode(text: string): Uint8Array | null {
	if (!/^[A-Za-z0-9_-]*$/.test(text)) {
		return null;
	}
	const padded = text.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (text.length % 4)) % 4);
	try {
		const binary = atob(padded);
		const out = new Uint8Array(binary.length);
		for (let i = 0; i < binary.length; i++) {
			out[i] = binary.charCodeAt(i);
		}
		return out;
	} catch {
		return null;
	}
}

export function hex(bytes: ArrayBuffer | Uint8Array): string {
	const view = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
	let out = '';
	for (const byte of view) {
		out += byte.toString(16).padStart(2, '0');
	}
	return out;
}

export async function hmacSha256(key: string, message: string): Promise<Uint8Array> {
	const cryptoKey = await crypto.subtle.importKey('raw', encoder.encode(key), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
	const signature = await crypto.subtle.sign('HMAC', cryptoKey, encoder.encode(message));
	return new Uint8Array(signature);
}

/**
 * Constant-time string comparison. Both inputs are hashed first so the
 * comparison never leaks length and the loop always runs over 32 bytes.
 */
export async function timingSafeEqual(a: string, b: string): Promise<boolean> {
	const [ha, hb] = await Promise.all([
		crypto.subtle.digest('SHA-256', encoder.encode(a)),
		crypto.subtle.digest('SHA-256', encoder.encode(b)),
	]);
	const va = new Uint8Array(ha);
	const vb = new Uint8Array(hb);
	let diff = 0;
	for (let i = 0; i < va.length; i++) {
		diff |= (va[i] ?? 0) ^ (vb[i] ?? 0);
	}
	return diff === 0 && a.length === b.length;
}

/**
 * A site's id is derived from its canonical URL, so the relay stores nothing
 * and a re-registration of the same site yields the same id. It carries no
 * secret and appears in browser URLs.
 */
export async function deriveSiteId(hmacKey: string, canonicalSiteUrl: string): Promise<string> {
	return hex(await hmacSha256(hmacKey, 'convertrack-site-id|' + canonicalSiteUrl)).slice(0, 32);
}

/** The site secret proves a `/v1/token` caller registered the site. */
export async function deriveSiteSecret(hmacKey: string, siteId: string): Promise<string> {
	return base64url(await hmacSha256(hmacKey, 'convertrack-site-secret|' + siteId));
}

export async function signState(hmacKey: string, payload: StatePayload): Promise<string> {
	const body = base64url(encoder.encode(JSON.stringify(payload)));
	const mac = base64url(await hmacSha256(hmacKey, 'convertrack-state|' + body));
	return body + '.' + mac;
}

/** Returns the payload when the signature is valid and the state has not expired. */
export async function verifyState(hmacKey: string, token: string, nowSeconds: number): Promise<StatePayload | null> {
	if (typeof token !== 'string' || token.length > 4096) {
		return null;
	}
	const dot = token.indexOf('.');
	if (dot <= 0 || dot === token.length - 1) {
		return null;
	}
	const body = token.slice(0, dot);
	const mac = token.slice(dot + 1);
	const expected = base64url(await hmacSha256(hmacKey, 'convertrack-state|' + body));
	if (!(await timingSafeEqual(mac, expected))) {
		return null;
	}
	const raw = base64urlDecode(body);
	if (!raw) {
		return null;
	}
	let payload: unknown;
	try {
		payload = JSON.parse(decoder.decode(raw));
	} catch {
		return null;
	}
	if (!isStatePayload(payload)) {
		return null;
	}
	if (payload.exp <= nowSeconds) {
		return null;
	}
	return payload;
}

function isStatePayload(value: unknown): value is StatePayload {
	if (!value || typeof value !== 'object') {
		return false;
	}
	const p = value as Record<string, unknown>;
	return (
		p.v === 1 &&
		typeof p.sid === 'string' &&
		typeof p.site === 'string' &&
		typeof p.st === 'string' &&
		typeof p.exp === 'number' &&
		Number.isFinite(p.exp)
	);
}
