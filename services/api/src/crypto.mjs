import {
	createHash,
	createHmac,
	createPrivateKey,
	createPublicKey,
	randomBytes,
	sign,
	timingSafeEqual,
} from 'node:crypto';

export const sha256Hex = (data) => createHash('sha256').update(data).digest('hex');

export const hmacHex = (secret, message) => createHmac('sha256', secret).update(message).digest('hex');

// Hacher les deux côtés ramène les entrées à la même longueur : timingSafeEqual
// exige des tampons égaux, et comparer les longueurs d'abord trahirait la taille du secret.
export function safeEqual(a, b) {
	const digestA = createHash('sha256').update(String(a)).digest();
	const digestB = createHash('sha256').update(String(b)).digest();
	return timingSafeEqual(digestA, digestB);
}

// La clé privée voyage en PKCS8 DER base64 : une seule ligne, sans les sauts de ligne du PEM
// que certains champs de variables d'environnement avalent.
export function loadSigningKey(base64Der) {
	try {
		const key = createPrivateKey({ key: Buffer.from(base64Der, 'base64'), format: 'der', type: 'pkcs8' });
		if (key.asymmetricKeyType !== 'ed25519') {
			throw new Error(`type ${key.asymmetricKeyType}`);
		}
		return key;
	} catch (error) {
		throw new Error(`SIGNING_PRIVATE_KEY illisible (attendu : ed25519 PKCS8 DER en base64) : ${error.message}`);
	}
}

// Manifeste d'une version : JSON compact, clés dans cet ordre, sans espace. Le site ne le
// recompose jamais : il vérifie la signature sur la chaîne reçue telle quelle, puis la décode.
export const releaseManifest = ({ slug, version, sha256 }) => JSON.stringify({ slug, version, sha256 });

// Signature détachée de 64 octets, compatible sodium_crypto_sign_verify_detached() côté PHP.
export const signEd25519 = (privateKey, data) => sign(null, data, privateKey).toString('base64');

// Clé publique brute de 32 octets (les 32 derniers octets du SPKI), le format que lit sodium.
export function publicKeyRawBase64(privateKey) {
	const spki = createPublicKey(privateKey).export({ format: 'der', type: 'spki' });
	return spki.subarray(-32).toString('base64');
}

export const newLicenceKey = () => {
	const hex = randomBytes(16).toString('hex').toUpperCase();
	return `FL-${hex.match(/.{8}/g).join('-')}`;
};

export const normaliseLicenceKey = (key) => String(key).trim().toUpperCase();
