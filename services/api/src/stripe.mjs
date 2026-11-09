// Client Stripe minimal, sans dépendance : quelques appels de l'API et la vérification de
// signature des webhooks. Seuls les codes d'erreur Stripe remontent : leurs messages
// peuvent citer une adresse courriel, qui n'a rien à faire au journal.
import { createHmac } from 'node:crypto';
import { safeEqual } from './crypto.mjs';

const API = 'https://api.stripe.com/v1';
export const SIGNATURE_TOLERANCE_SECONDS = 300;

// En-tête Stripe-Signature : « t=<horodatage>,v1=<hmac>[,v1=…] ». Le HMAC-SHA256 porte sur
// « <horodatage>.<corps brut> ». Au-delà de 5 minutes d'écart, l'envoi est tenu pour un rejeu.
export function verifyStripeSignature(payload, header, secret, nowSeconds, tolerance = SIGNATURE_TOLERANCE_SECONDS) {
	if (typeof header !== 'string' || !secret) {
		return false;
	}
	let timestamp = null;
	const signatures = [];
	for (const part of header.split(',')) {
		const separator = part.indexOf('=');
		const key = part.slice(0, separator).trim();
		const value = part.slice(separator + 1).trim();
		if (key === 't' && /^\d{1,12}$/.test(value)) {
			timestamp = Number(value);
		} else if (key === 'v1' && /^[0-9a-f]{64}$/.test(value)) {
			signatures.push(value);
		}
	}
	if (timestamp === null || signatures.length === 0 || Math.abs(nowSeconds - timestamp) > tolerance) {
		return false;
	}
	const expected = createHmac('sha256', secret).update(`${timestamp}.`).update(payload).digest('hex');
	return signatures.some((signature) => safeEqual(signature, expected));
}

// Encodage application/x-www-form-urlencoded à la manière de Stripe : a[b][0][c]=valeur.
export function formEncode(params, prefix = '') {
	const pairs = [];
	for (const [key, value] of Object.entries(params ?? {})) {
		if (value === undefined || value === null) {
			continue;
		}
		const name = prefix ? `${prefix}[${key}]` : key;
		if (typeof value === 'object') {
			const nested = formEncode(Array.isArray(value) ? Object.fromEntries(value.entries()) : value, name);
			if (nested) {
				pairs.push(nested);
			}
		} else {
			pairs.push(`${encodeURIComponent(name)}=${encodeURIComponent(String(value))}`);
		}
	}
	return pairs.join('&');
}

// apiVersion épingle la forme des objets lus par l'API : sans elle, une mise à niveau faite dans le
// tableau de bord Stripe la changerait en silence.
export function createStripeClient({ secretKey, apiVersion = '', fetchImpl = fetch, timeoutMs = 15_000 }) {
	async function call(method, path, params, idempotencyKey) {
		const query = method === 'GET' && params ? `?${formEncode(params)}` : '';
		const response = await fetchImpl(`${API}${path}${query}`, {
			method,
			headers: {
				Authorization: `Bearer ${secretKey}`,
				...(apiVersion ? { 'Stripe-Version': apiVersion } : {}),
				...(method === 'GET' ? {} : { 'Content-Type': 'application/x-www-form-urlencoded' }),
				...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}),
			},
			body: method === 'GET' ? undefined : formEncode(params),
			signal: AbortSignal.timeout(timeoutMs),
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) {
			throw Object.assign(new Error(`Stripe a répondu ${response.status}`), {
				code: data.error?.code ?? data.error?.type ?? `stripe_${response.status}`,
				status: response.status,
			});
		}
		return data;
	}

	return {
		createCheckoutSession: (params) => call('POST', '/checkout/sessions', params),
		expireCheckoutSession: (id) => call('POST', `/checkout/sessions/${encodeURIComponent(id)}/expire`, {}),
		listCheckoutSessions: (params) => call('GET', '/checkout/sessions', params),
		retrievePrice: (id, params) => call('GET', `/prices/${encodeURIComponent(id)}`, params),
		createCoupon: (params, idempotencyKey) => call('POST', '/coupons', params, idempotencyKey),
		createRefund: (params, idempotencyKey) => call('POST', '/refunds', params, idempotencyKey),
		listRefunds: (params) => call('GET', '/refunds', params),
	};
}
