// Validation aux frontières. Chaque refus nomme le champ, la contrainte et ce qui a été reçu
// (le type ou la longueur, jamais le contenu, qui peut être personnel).
import { HttpError } from './http.mjs';

const describe = (value) => (typeof value === 'string' ? `chaîne de ${value.length} caractères` : typeof value);

export function rejectUnknown(body, allowed) {
	const unknown = Object.keys(body).filter((key) => !allowed.includes(key));
	if (unknown.length > 0) {
		throw new HttpError(400, 'unknown_field', `Champs inconnus : ${unknown.join(', ')}`);
	}
}

export function text(body, field, { min = 0, max, pattern, optional = false } = {}) {
	const value = body[field];
	if (value === undefined || value === null) {
		if (optional) {
			return null;
		}
		throw new HttpError(400, 'invalid_field', `${field} est requis`);
	}
	if (typeof value !== 'string' || value.length < min || value.length > max || (pattern && !pattern.test(value))) {
		throw new HttpError(400, 'invalid_field', `${field} invalide (${min} à ${max} caractères attendus, reçu : ${describe(value)})`);
	}
	if (CONTROL.test(value)) {
		throw new HttpError(400, 'invalid_field', `${field} contient un caractère de contrôle`);
	}
	return value;
}

// Caractères de contrôle, sauf tabulation et sauts de ligne, et forçages de sens d'écriture
// (texte qui s'affiche autrement qu'il ne se lit). Postgres refuse aussi U+0000 : sans ce filtre,
// il donnerait une erreur 500. Plages en points de code : la source reste en ASCII visible.
const CONTROL_RANGES = [[0x00, 0x08], [0x0b, 0x0c], [0x0e, 0x1f], [0x7f, 0x9f], [0x202a, 0x202e], [0x2066, 0x2069]];
export const CONTROL = new RegExp(`[${CONTROL_RANGES.map(([from, to]) => `${String.fromCharCode(from)}-${String.fromCharCode(to)}`).join('')}]`);

export function integer(body, field, { min, max }) {
	const value = body[field];
	if (!Number.isInteger(value) || value < min || value > max) {
		throw new HttpError(400, 'invalid_field', `${field} doit être un entier entre ${min} et ${max} (reçu : ${describe(value)})`);
	}
	return value;
}

export const SLUG = /^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/;
export const SITE = /^[0-9a-f]{64}$/;
export const EMAIL = /^[^\s@<>"]{1,64}@[^\s@<>"]{1,189}\.[A-Za-z]{2,}$/;
export const LOCALE = /^[a-z]{2}_[A-Z]{2}$/;
