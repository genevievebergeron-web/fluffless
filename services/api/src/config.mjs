// Lecture et validation des variables d'environnement, une seule fois au démarrage.
// Une variable manquante arrête le service avec son nom : mieux vaut un
// déploiement qui échoue qu'un serveur qui signe avec une clé absente.
import { loadSigningKey } from './crypto.mjs';

const REQUIRED = ['DATABASE_URL', 'PUBLIC_URL', 'SIGNING_PRIVATE_KEY', 'ADMIN_TOKEN', 'URL_SECRET', 'HASH_PEPPER'];
const MIN_SECRET_LENGTH = 32;

export function loadConfig(env = process.env) {
	const missing = REQUIRED.filter((key) => !env[key]);
	if (missing.length > 0) {
		throw Object.assign(
			new Error(`Variables d'environnement manquantes : ${missing.join(', ')}. Modèle : services/api/.env.example.`),
			{ missing },
		);
	}
	for (const key of ['ADMIN_TOKEN', 'URL_SECRET', 'HASH_PEPPER']) {
		if (env[key].length < MIN_SECRET_LENGTH) {
			throw new Error(`${key} fait ${env[key].length} caractères; il en faut au moins ${MIN_SECRET_LENGTH}.`);
		}
	}
	const packsProxyToken = env.PACKS_PROXY_TOKEN ?? '';
	if (packsProxyToken && packsProxyToken.length < MIN_SECRET_LENGTH) {
		throw new Error(`PACKS_PROXY_TOKEN fait ${packsProxyToken.length} caractères; il en faut au moins ${MIN_SECRET_LENGTH}.`);
	}
	const stripe = loadStripe(env);
	// Rappels d'expiration des licences : écrits, mais éteints tant que la variable n'est pas à 1.
	const reminders = env.REMINDERS_ENABLED ?? '';
	if (!['', '0', '1'].includes(reminders)) {
		throw new Error(`REMINDERS_ENABLED vaut 0 ou 1 (reçu : ${reminders}).`);
	}
	// Webhook des alertes de paiement : vide par défaut (le courriel part seul). L'adresse porte
	// souvent un jeton : jamais recopiée dans une erreur ni au journal.
	const alertWebhookUrl = env.ALERT_WEBHOOK_URL ?? '';
	if (alertWebhookUrl) {
		let hook = null;
		try {
			hook = new URL(alertWebhookUrl);
		} catch {
			// message plus bas
		}
		const loopback = ['127.0.0.1', 'localhost', '[::1]'].includes(hook?.hostname);
		if (!hook || !(hook.protocol === 'https:' || (hook.protocol === 'http:' && loopback))) {
			throw new Error('ALERT_WEBHOOK_URL doit être une adresse https (http seulement vers 127.0.0.1, localhost ou [::1]).');
		}
	}
	let publicUrl;
	try {
		publicUrl = new URL(env.PUBLIC_URL).origin;
	} catch {
		throw new Error(`PUBLIC_URL n'est pas une URL valide (reçu : ${env.PUBLIC_URL}).`);
	}
	return {
		databaseUrl: env.DATABASE_URL,
		port: Number(env.PORT ?? 3000),
		publicUrl,
		signingKey: loadSigningKey(env.SIGNING_PRIVATE_KEY),
		adminToken: env.ADMIN_TOKEN,
		urlSecret: env.URL_SECRET,
		hashPepper: env.HASH_PEPPER,
		// Facultatif : jeton du serveur de fluffless.dev, seul autorisé à relayer l'IP d'une cliente.
		packsProxyToken,
		// Derrière le mandataire de canner, l'adresse du socket est celle du mandataire.
		trustProxy: env.TRUST_PROXY !== '0',
		// Tout ou rien : sans STRIPE_SECRET_KEY, la vente est fermée (503) et le webhook aussi.
		stripe,
		remindersEnabled: reminders === '1',
		alertWebhookUrl,
		smtp: {
			user: env.PROTON_SMTP_USER ?? '',
			token: env.PROTON_SMTP_TOKEN ?? '',
			to: env.NOTIFY_TO || 'bonjour@genevievebergeron.ca',
		},
	};
}

// Avertissements du démarrage. Vente ouverte et rappels éteints : la FAQ et le courriel de
// bienvenue ne promettent alors aucun rappel, mais un oubli de REMINDERS_ENABLED=1 à l'ouverture
// des ventes laisserait les licences expirer sans prévenir (COH6-12).
export function startupWarnings(config) {
	return config.stripe?.secretKey && !config.remindersEnabled ? ['rappels_eteints'] : [];
}

const STRIPE_KEYS = ['STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_SUCCESS_URL', 'STRIPE_CANCEL_URL'];

function loadStripe(env) {
	const present = STRIPE_KEYS.filter((key) => env[key]);
	if (present.length === 0) {
		if (env.STRIPE_MANAGED_PAYMENTS === '1') {
			throw new Error('STRIPE_MANAGED_PAYMENTS=1 demande les variables Stripe.');
		}
		return { secretKey: '', webhookSecret: '', successUrl: '', cancelUrl: '', successUrlEn: '', cancelUrlEn: '', apiVersion: '', managedPayments: false };
	}
	const missing = STRIPE_KEYS.filter((key) => !env[key]);
	if (missing.length > 0) {
		throw Object.assign(new Error(`Stripe à moitié configuré, il manque : ${missing.join(', ')}.`), { missing });
	}
	if (!/^(sk|rk)_(test|live)_[A-Za-z0-9]{10,}$/.test(env.STRIPE_SECRET_KEY)) {
		throw new Error('STRIPE_SECRET_KEY doit être une clé secrète ou restreinte Stripe (sk_… ou rk_…).');
	}
	if (!/^whsec_[A-Za-z0-9]{10,}$/.test(env.STRIPE_WEBHOOK_SECRET)) {
		throw new Error('STRIPE_WEBHOOK_SECRET doit être le secret de signature du point de terminaison (whsec_…).');
	}
	// Optionnelle : la version du compte s'applique sinon.
	const apiVersion = env.STRIPE_API_VERSION ?? '';
	if (apiVersion && !/^\d{4}-\d{2}-\d{2}(\.[a-z]+)?$/.test(apiVersion)) {
		throw new Error(`STRIPE_API_VERSION doit ressembler à 2025-09-30.clover (reçu : ${apiVersion}).`);
	}
	// Interrupteur de Managed Payments (Stripe revendeur, vente hors Canada). Désactivé par défaut :
	// à ne mettre à 1 qu'après confirmation écrite du rôle de Link, LLC (README, Vente des packs).
	const managed = env.STRIPE_MANAGED_PAYMENTS ?? '';
	if (!['', '0', '1'].includes(managed)) {
		throw new Error(`STRIPE_MANAGED_PAYMENTS vaut 0 ou 1 (reçu : ${managed}).`);
	}
	// Managed Payments exige la version 2025-03-31.basil ou plus récente : épinglée, sinon celle du
	// compte s'appliquerait sans contrôle.
	if (managed === '1' && (!apiVersion || apiVersion.slice(0, 10) < '2025-03-31')) {
		throw new Error(`Managed Payments exige STRIPE_API_VERSION 2025-03-31.basil ou plus récente (reçu : ${apiVersion || 'vide'}).`);
	}
	const url = (key) => {
		try {
			return new URL(env[key]).toString();
		} catch {
			throw new Error(`${key} n'est pas une URL valide (reçu : ${env[key]}).`);
		}
	};
	return {
		secretKey: env.STRIPE_SECRET_KEY,
		webhookSecret: env.STRIPE_WEBHOOK_SECRET,
		successUrl: url('STRIPE_SUCCESS_URL'),
		cancelUrl: url('STRIPE_CANCEL_URL'),
		// Facultatives : pages anglaises pour un paiement en anglais; sinon les pages françaises.
		successUrlEn: env.STRIPE_SUCCESS_URL_EN ? url('STRIPE_SUCCESS_URL_EN') : '',
		cancelUrlEn: env.STRIPE_CANCEL_URL_EN ? url('STRIPE_CANCEL_URL_EN') : '',
		apiVersion,
		managedPayments: managed === '1',
	};
}
