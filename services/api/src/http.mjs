export class HttpError extends Error {
	constructor(status, code, message = code) {
		super(message);
		this.status = status;
		this.code = code;
	}
}

// Appels de serveur à serveur seulement : aucun en-tête CORS n'est émis, donc un
// navigateur tiers ne peut pas lire les réponses.
export const SECURITY_HEADERS = {
	'Cache-Control': 'no-store',
	'Content-Security-Policy': "default-src 'none'; frame-ancestors 'none'",
	'Cross-Origin-Resource-Policy': 'same-origin',
	'Referrer-Policy': 'no-referrer',
	'Strict-Transport-Security': 'max-age=31536000',
	'X-Content-Type-Options': 'nosniff',
	'X-Frame-Options': 'DENY',
};

export function send(res, status, body, headers = {}) {
	if (status === 204) {
		res.writeHead(204, { ...SECURITY_HEADERS, ...headers });
		res.end();
		return;
	}
	const payload = Buffer.isBuffer(body) ? body : Buffer.from(JSON.stringify(body));
	res.writeHead(status, {
		...SECURITY_HEADERS,
		'Content-Type': 'application/json; charset=utf-8',
		'Content-Length': payload.length,
		...headers,
	});
	res.end(payload);
}

export async function readJson(req, limitBytes) {
	let data;
	try {
		data = JSON.parse((await readRaw(req, limitBytes)).toString('utf8'));
	} catch (error) {
		if (error instanceof HttpError) {
			throw error;
		}
		throw new HttpError(400, 'invalid_json', 'Corps JSON illisible');
	}
	if (data === null || typeof data !== 'object' || Array.isArray(data)) {
		throw new HttpError(400, 'invalid_json', 'Objet JSON attendu');
	}
	return data;
}

// Corps brut, octets exacts : la signature d'un webhook Stripe porte sur eux, pas sur le JSON relu.
export async function readRaw(req, limitBytes, expectedType = 'application/json') {
	const type = req.headers['content-type'] ?? '';
	if (!type.startsWith(expectedType)) {
		throw new HttpError(415, 'unsupported_media_type', `Content-Type attendu : ${expectedType}`);
	}
	const declared = Number(req.headers['content-length'] ?? 0);
	if (declared > limitBytes) {
		throw new HttpError(413, 'payload_too_large', `Corps limité à ${limitBytes} octets`);
	}
	const chunks = [];
	let total = 0;
	for await (const chunk of req) {
		total += chunk.length;
		if (total > limitBytes) {
			throw new HttpError(413, 'payload_too_large', `Corps limité à ${limitBytes} octets`);
		}
		chunks.push(chunk);
	}
	return Buffer.concat(chunks);
}
