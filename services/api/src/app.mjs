import { createHash, randomBytes } from 'node:crypto';
import { HttpError, readJson, readRaw, send } from './http.mjs';
import { hmacHex, newLicenceKey, normaliseLicenceKey, releaseManifest, safeEqual, sha256Hex, signEd25519 } from './crypto.mjs';
import { compareVersions, parseVersion } from './versions.mjs';
import { createLockout, createRateLimiter } from './ratelimit.mjs';
import { verifyStripeSignature } from './stripe.mjs';
import { longDay } from './mail.mjs';
import { EMAIL, integer, LOCALE, rejectUnknown, SITE, SLUG, text } from './validate.mjs';

const KB = 1024;
const LIMITS = {
	defaultBody: 16 * KB,
	reportBody: 1600 * KB, // capture de 1 Mo encodée en base64 (+33 %) et texte.
	releaseBody: 40 * 1024 * KB, // zip de 25 Mo au plus, encodé en base64.
	zipBytes: 25 * 1024 * KB,
	screenshotBytes: 1024 * KB,
	longText: 5000,
	diagnosticsJson: 20 * KB,
	webhookBody: 256 * KB,
};
const DOWNLOAD_TTL_SECONDS = 15 * 60;
const REPORTS_PER_HOUR = 10;
const LICENCE_MAX_CHARS = 128;
const REPORT_STATUSES = ['nouveau', 'vu', 'réglé'];
// Packs curés (docs/PLAN.md) : lien de 72 h à usage unique, 10 appels par IP et
// 5 réclamations par licence par quart d'heure, verrou progressif plafonné à 24 h.
const PACK_LINK_TTL_MS = 72 * 3_600_000;
const PACK_WINDOW_MS = 15 * 60_000;
const PACK_CALLS_PER_IP = 10;
const PACK_CLAIMS_PER_LICENCE = 5;
const PACK_LOCK_MAX_MS = 24 * 3_600_000;
// Sessions Checkout créées par licence par quart d'heure (retours de la page d'annulation).
const CHECKOUTS_PER_LICENCE = 10;
// Au-delà, un remboursement en attente déclenche une alerte à chaque réconciliation.
const REFUND_STUCK_MS = 48 * 3_600_000;
// Bail d'un événement Stripe en cours : au-delà des délais cumulés de Stripe et du SMTP.
const EVENT_LEASE_MS = 5 * 60_000;
// Durée de vie d'une session Checkout (Stripe accepte de 30 min à 24 h).
const CHECKOUT_TTL_SECONDS = 3_600;
// Lien de paiement signé par l'administration : 24 h au plus, borne haute de Stripe.
const LINK_HOURS_MAX = 24;
// Prix gelé (décision 32) : le prix de l'achat vaut jusqu'à 30 jours après la fin de la licence,
// ou après son dernier rétablissement si celui-ci est plus tard; borne incluse.
const GRACE_MS = 30 * 86_400_000;
const graceUntil = (row) => Math.max(row.expires_at.getTime(), row.restored_at?.getTime() ?? 0) + GRACE_MS;
const PRICE_ID = /^price_[A-Za-z0-9]{3,250}$/;
const PRODUCT_ID = /^prod_[A-Za-z0-9]{3,250}$/;
// Prix porté par les métadonnées d'une session (posées par l'API elle-même) : identifiant,
// montant unitaire avant taxes et rabais, produit. null si absent ou mal formé.
function pricedFrom(metadata) {
	const amount = /^\d{2,9}$/.test(metadata.unit_amount ?? '') ? Number(metadata.unit_amount) : null;
	if (!PRICE_ID.test(metadata.price_id ?? '') || amount === null || amount < 50) {
		return null;
	}
	return { price_id: metadata.price_id, unit_amount: amount, product_id: PRODUCT_ID.test(metadata.product_id ?? '') ? metadata.product_id : null };
}
// Métadonnées de prix d'une session, en chaînes comme Stripe les garde.
const priceMetadata = (priced) => ({
	price_id: priced.price_id,
	unit_amount: String(priced.unit_amount),
	...(priced.product_id ? { product_id: priced.product_id } : {}),
});
// Pays de facturation acceptés pour la vente de packs (voir migrations/005_vente_canada.sql).
const SALE_COUNTRIES = new Set(['CA']);
// Rappels d'expiration traités par passage quotidien.
const REMINDER_BATCH = 500;
// Une prise de rappel sans envoi confirmé (plantage) se reprend après ce délai.
const REMINDER_CLAIM_MS = 15 * 60_000;
// Avec Managed Payments (STRIPE_MANAGED_PAYMENTS=1), pays où Stripe prend en charge les taxes
// sur les ventes numériques d'un vendeur canadien, relevés le 29 sept. 2026 sur
// docs.stripe.com/payments/managed-payments/tax-compliance. Ailleurs, les taxes resteraient à
// la charge de la vendeuse : le paiement est remboursé (migrations/006_managed_payments.sql).
export const MANAGED_COUNTRIES = new Set([
	// Amérique du Nord, Amérique latine et Caraïbes
	'CA', 'US', 'BB', 'BM', 'KY', 'MX', 'VG',
	// Union européenne
	'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT',
	'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
	// Reste de l'Europe (la Serbie seulement pour un vendeur non inscrit à la TVA serbe)
	'AL', 'BY', 'CH', 'GB', 'GI', 'IS', 'LI', 'MD', 'NO', 'RS', 'UA',
	// Asie-Pacifique
	'AM', 'AU', 'AZ', 'BN', 'GE', 'HK', 'ID', 'IL', 'IN', 'JP', 'KG', 'KR', 'KW', 'KZ', 'LA', 'MO',
	'MY', 'NP', 'NZ', 'PH', 'QA', 'SA', 'SG', 'TH', 'TJ', 'TR', 'TW', 'VN',
	// Afrique
	'CM', 'EG', 'GH', 'KE', 'NG', 'UG', 'ZA', 'ZM', 'ZW',
]);
const CHECKOUT_MIN_TTL_SECONDS = 1_800;
// Les sessions ouvertes gardées en base disparaissent après l'expiration maximale de Stripe.
const CHECKOUT_ROW_MAX_MS = 25 * 3_600_000;
const PACKS_PER_CLAIM = 20;
// Plafond global des signalements anonymes : au-delà, 429 sans rien écrire.
const REPORTS_GLOBAL_PER_HOUR = 100;
const SCREENSHOT_BYTES_PER_DAY = 50 * 1024 * 1024;
// Routes publiques sans limiteur propre : un plafond par IP protège le pool Postgres.
const PUBLIC_CALLS_PER_MINUTE = 120;
const PUBLIC_ROUTES = new Set(['update', 'manifest', 'download', 'info', 'verifyLicence', 'releaseSite', 'packLinkPage', 'packDownload',
	'licenceDownloadPage', 'licenceDownload']);
// Un zip de 25 Mo tient en mémoire le temps de l'envoi : on borne les envois simultanés, et
// une cliente qui ne lit plus (ou trop lentement) est coupée pour rendre sa place.
const MAX_CONCURRENT_DOWNLOADS = 8;
const DOWNLOAD_IDLE_MS = 30_000;
const DOWNLOAD_MAX_MS = 5 * 60_000;
const DOWNLOAD_ROUTES = new Set(['download', 'packDownload']);
// Un /48 IPv6 regroupe 65 536 /64 : il reçoit ce multiple de la limite d'un /64. Le /64 est
// compté d'abord : un seul /64 abusif ne vide donc pas le quota de ses voisins.
const IPV6_48_FACTOR = 8;
const IP_PATTERN = /^[0-9A-Fa-f:.]{2,45}$/;
// Réconciliation : sessions Checkout des trois derniers jours, 20 pages de 100 au plus.
const RECONCILE_WINDOW_SECONDS = 3 * 86_400;
const RECONCILE_MAX_PAGES = 20;
const STRIPE_ID = /^[A-Za-z0-9_]{3,255}$/;
const DB_ID = /^\d{1,18}$/;
// Vente des licences (migrations/008_vente_licences.sql). Plafond global de sessions Checkout
// par heure, toutes IP confondues : au-delà, 429 et une seule alerte par heure.
const LICENCE_CHECKOUTS_PER_HOUR = 200;
// Bail d'une livraison de clé : au-delà des délais cumulés du SMTP (40 s au pire).
const LICENCE_DELIVERY_LEASE_MS = 3 * 60_000;
// Alerte au premier échec de livraison, puis toutes les 36 tentatives (6 h à 10 min d'écart).
const LICENCE_DELIVERY_ALERT_EVERY = 36;
const LICENCE_REDELIVER_BATCH = 50;
const LICENCE_FORM_BYTES = 1 * KB;
// La page de succès lit l'état d'un achat pendant 24 h, pas plus (Loi 25 : rien d'utile après).
const LICENCE_STATUS_TTL_MS = 24 * 3_600_000;
const CHECKOUT_SESSION = /^cs_(test|live)_[A-Za-z0-9_]{1,200}$/;
const DEFAULT_RELEASE_SLUG = 'fluffless-suite';
// Thème et Consent gratuits : la Suite les met à jour pour toute licence valide, quelle que soit la liste.
const FREE_RELEASE_SLUGS = ['fluffless', 'fluffless-consent'];
// Code de rabais : majuscules, chiffres et tirets; la saisie est mise en majuscules avant l'essai.
const COUPON_CODE = /^[A-Z0-9-]{3,40}$/;
const MAX_RELEASES_PER_OFFER = 5;
// Une licence de 10 000 sites est « illimitée » : le plafond reste interne, jamais affiché.
const UNLIMITED_SITES = 10_000;
// Sites de développement couverts par une licence de 1 ou 5 sites. Interne (décision 34) :
// jamais renvoyé par l'API ni écrit sur une page.
const DEV_SITES_MAX = 25;
// Le plafond de l'illimitée ne sort jamais de l'API : la réponse dit « illimitée », sans nombre.
const seatsMax = (sitesMax) => (sitesMax >= UNLIMITED_SITES ? { sites_max: null, unlimited: true } : { sites_max: sitesMax });
const devSitesMax = (licence) => (licence.sites_max >= UNLIMITED_SITES ? UNLIMITED_SITES : DEV_SITES_MAX);
// Le dernier contact d'un site s'écrit au plus une fois par jour.
const SEEN_EVERY_MS = 86_400_000;
const FLAG_LABELS = {
	ceiling: 'plafond interne de l\'illimitée atteint',
	dev: 'plafond des sites de développement atteint',
	releases: 'plafond de libérations atteint',
};
const FLAG_OUTCOME = /^(?:ok|notice|revoked)$/;
// Avis avant révocation : 30 jours par défaut (conditions), jamais moins, au plus 60.
const NOTICE_DAYS_DEFAULT = 30;
const NOTICE_DAYS_MIN = 30;
const NOTICE_DAYS_MAX = 60;
const NOTICE_REASON = /^(?:usage|resale|sharing|other)$/;
// Libérations d'une place par la cliente : max(3, sites_max) sur 30 jours glissants, bornées à 50.
const RELEASE_WINDOW_MS = 30 * 86_400_000;
const releasesMax = (licence) => Math.min(50, Math.max(3, licence.sites_max));

export function createApp({ sql, config, notify, deliver = async () => false, deliverLicence = async () => false, remind = async () => false, notifyRenewalRefund = async () => false, alert = async () => false, stripe = null, log, now = () => Date.now(), downloadIdleMs = DOWNLOAD_IDLE_MS, downloadMaxMs = DOWNLOAD_MAX_MS }) {
	const reportLimiter = createIpLimiter({ limit: REPORTS_PER_HOUR, windowMs: 3_600_000, now });
	const reportGlobal = createRateLimiter({ limit: REPORTS_GLOBAL_PER_HOUR, windowMs: 3_600_000, now });
	const screenshotBudget = { bytes: 0, resetAt: 0 };
	const packIpLimiter = createIpLimiter({ limit: PACK_CALLS_PER_IP, windowMs: PACK_WINDOW_MS, now });
	const publicLimiter = createIpLimiter({ limit: PUBLIC_CALLS_PER_MINUTE, windowMs: 60_000, now });
	let downloadsInFlight = 0;
	const packClaimLockout = createLockout({ limit: PACK_CLAIMS_PER_LICENCE, windowMs: PACK_WINDOW_MS, maxLockMs: PACK_LOCK_MAX_MS, now });
	const checkoutLockout = createLockout({ limit: CHECKOUTS_PER_LICENCE, windowMs: PACK_WINDOW_MS, maxLockMs: PACK_LOCK_MAX_MS, now });
	const packLinkHash = (token) => hmacHex(config.urlSecret, `pack-link\n${token}`);
	const keyHash = (key) => hmacHex(config.hashPepper, `licence:${normaliseLicenceKey(key)}`);
	const siteHash = (site) => hmacHex(config.hashPepper, `site:${site}`);
	// Le jeton lie le lien de renouvellement à la fin de période annoncée : une fois la licence
	// prolongée, l'ancien lien (et ses doublons) ne vaut plus rien.
	const renewToken = (licenceId, until) => hmacHex(config.urlSecret, `renew\n${licenceId}\n${until}`);
	const renewUrl = (licenceId, until) => `${config.publicUrl}/v1/licences/renew?licence=${licenceId}&until=${until}&token=${renewToken(licenceId, until)}`;
	const downloadToken = (slug, version, expires) => hmacHex(config.urlSecret, `download\n${slug}\n${version}\n${expires}`);
	// Clé d'une licence achetée, dérivée de la session et de la génération : un nouvel essai de
	// livraison renvoie la même clé, sans jamais la garder en clair. Un renvoi demandé par
	// l'administration incrémente la génération, donc change la clé.
	const purchaseKey = (sessionId, generation) => {
		const hex = hmacHex(config.urlSecret, `licence-key\n${sessionId}\n${generation}`).slice(0, 32).toUpperCase();
		return `FL-${hex.match(/.{8}/g).join('-')}`;
	};
	const licenceCheckoutGlobal = createRateLimiter({ limit: LICENCE_CHECKOUTS_PER_HOUR, windowMs: 3_600_000, now });
	let licenceCheckoutAlertAt = 0;
	// Les liens de rappel, ouverts d'avance par certains filtres de courriel, ne doivent pas
	// affamer la vente de nouvelles licences : plafond à part.
	const licenceRenewGlobal = createRateLimiter({ limit: LICENCE_CHECKOUTS_PER_HOUR, windowMs: 3_600_000, now });

	function clientIp(req) {
		const forwarded = req.headers['x-forwarded-for'];
		if (config.trustProxy && typeof forwarded === 'string' && forwarded.length > 0) {
			// Dernière entrée : celle qu'ajoute le mandataire de confiance, la seule non falsifiable.
			return forwarded.split(',').at(-1).trim();
		}
		return req.socket.remoteAddress ?? 'inconnue';
	}

	function requireAdmin(req) {
		const header = req.headers.authorization ?? '';
		const token = header.startsWith('Bearer ') ? header.slice(7) : '';
		if (!token || !safeEqual(token, config.adminToken)) {
			throw new HttpError(401, 'unauthorized', 'Jeton administrateur requis');
		}
	}

	// La licence voyage seulement dans l'en-tête X-Fluffless-Licence : une chaîne de requête
	// finit dans les journaux des mandataires. Un reliquat dans la requête est refusé
	// plutôt qu'ignoré, pour qu'un client mal branché s'en aperçoive.
	function licenceFrom(req, query) {
		if (query.licence !== undefined) {
			throw new HttpError(400, 'licence_in_query', 'La licence passe par l\'en-tête X-Fluffless-Licence, jamais par la requête');
		}
		const value = req.headers['x-fluffless-licence'];
		if (typeof value !== 'string' || value.trim() === '') {
			return null;
		}
		if (value.length > LICENCE_MAX_CHARS) {
			throw new HttpError(400, 'invalid_field', `X-Fluffless-Licence limitée à ${LICENCE_MAX_CHARS} caractères (reçu : ${value.length})`);
		}
		return value;
	}

	// Signalement à examiner (licence_flags) : un par règle et par palier, que l'index unique
	// garantit. Rien d'automatique ne suit. L'alerte ne porte que l'id de licence et des comptes,
	// jamais une clé, une empreinte, un courriel ni un domaine.
	async function flagLicence(tx, licenceId, rule, level, observed) {
		const [flag] = await tx`
			insert into licence_flags (licence_id, rule, level, observed, created_at)
			values (${licenceId}, ${rule}, ${level}, ${sql.json(observed)}, ${new Date(now())})
			on conflict (licence_id, rule, level) do nothing returning id`;
		return flag ? { licence: Number(licenceId), rule, observed } : null;
	}

	async function alertFlag(flag) {
		if (flag) {
			await sendAlert('Licence à examiner', [
				`Licence ${flag.licence}`,
				`Règle : ${FLAG_LABELS[flag.rule]}`,
				...Object.entries(flag.observed).map(([key, value]) => `${key} : ${value}`),
			]);
		}
	}

	// Vérifie la licence et inscrit le site s'il reste une place. Le verrou de ligne
	// empêche deux sites d'obtenir en même temps la dernière place. Un site de développement
	// (drapeau envoyé par la Suite, qui seule voit l'adresse) ne prend aucune place.
	async function checkLicence(licence, site, { dev = false } = {}) {
		let flagged = null;
		const state = await sql.begin(async (tx) => {
			const [row] = await tx`
				select id, plan, sites_max, expires_at, revoked_at, restored_at, renewal_price_id, release_slugs, notice_until from licences
				where key_hash = ${keyHash(licence)} for update`;
			// Une clé révoquée le dit : la personne qui a la clé juste ne cherche pas une faute de frappe.
			// Une clé inconnue garde licence_invalid, sans rien révéler.
			if (!row || row.revoked_at) {
				return { valid: false, code: row ? 'licence_revoked' : 'licence_invalid', plan: null, expires: null, sites_used: 0, sites_max: 0 };
			}
			const [{ used }] = await tx`select count(*)::int as used from licence_sites where licence_id = ${row.id}`;
			const state = {
				valid: true,
				releases: row.release_slugs,
				plan: row.plan,
				expires: row.expires_at ? row.expires_at.toISOString() : null,
				sites_used: used,
				...seatsMax(row.sites_max),
				// Avis écrit en cours : la Suite le montre aussi, au cas où le courriel se perd.
				...(row.notice_until ? { notice_until: row.notice_until.toISOString() } : {}),
			};
			if (row.expires_at && row.expires_at.getTime() <= now()) {
				// Pendant la grâce (prix gelé), la Suite peut montrer la date limite et le lien : la clé
				// prouve déjà le droit d'ouvrir ce paiement.
				const grace = row.renewal_price_id && stripe && now() <= graceUntil(row);
				return {
					...state, valid: false, code: 'licence_expired',
					...(grace ? {
						grace_until: new Date(graceUntil(row)).toISOString(),
						renew_url: renewUrl(row.id, Math.floor(row.expires_at.getTime() / 1000)),
					} : {}),
				};
			}
			const hashed = siteHash(site);
			const at = new Date(now());
			const stale = new Date(now() - SEEN_EVERY_MS);
			if (dev) {
				const [knownDev] = await tx`select 1 from licence_dev_sites where licence_id = ${row.id} and site_hash = ${hashed}`;
				if (knownDev) {
					await tx`
						update licence_dev_sites set last_seen_at = ${at}
						where licence_id = ${row.id} and site_hash = ${hashed} and last_seen_at < ${stale}`;
				} else {
					const [{ count }] = await tx`select count(*)::int as count from licence_dev_sites where licence_id = ${row.id}`;
					if (count >= devSitesMax(row)) {
						// R6 : la règle des sites de développement vient du client; son plafond se surveille.
						flagged = await flagLicence(tx, row.id, 'dev', devSitesMax(row), { dev_sites: count });
						return { ...state, valid: false, code: 'dev_limit', dev: true };
					}
					await tx`insert into licence_dev_sites (licence_id, site_hash, last_seen_at) values (${row.id}, ${hashed}, ${at})`;
				}
				// Site local inscrit avant que la Suite ne le signale : sa place revient.
				const freed = await tx`delete from licence_sites where licence_id = ${row.id} and site_hash = ${hashed} returning 1`;
				return { ...state, sites_used: used - freed.length, dev: true };
			}
			const [known] = await tx`select last_seen_at from licence_sites where licence_id = ${row.id} and site_hash = ${hashed}`;
			if (known) {
				if (known.last_seen_at < stale) {
					await tx`update licence_sites set last_seen_at = ${at} where licence_id = ${row.id} and site_hash = ${hashed}`;
				}
			} else {
				if (used >= row.sites_max) {
					if (row.sites_max < UNLIMITED_SITES) {
						return { ...state, valid: false, code: 'site_limit' };
					}
					// Plafond interne de l'illimitée (R4) : rien à acheter, une vérification à faire.
					// Les sites inscrits restent couverts; seul le suivant attend.
					flagged = await flagLicence(tx, row.id, 'ceiling', row.sites_max, { sites: used });
					return { ...state, valid: false, code: 'licence_review' };
				}
				await tx`insert into licence_sites (licence_id, site_hash, last_seen_at) values (${row.id}, ${hashed}, ${at})`;
				state.sites_used = used + 1;
			}
			return state;
		});
		await alertFlag(flagged);
		return state;
	}

	// La page catalogue de fluffless.dev appelle depuis son serveur : sans relais de l'IP de
	// la cliente, toutes les demandes partageraient le compteur du site. Le relais n'est cru
	// qu'avec PACKS_PROXY_TOKEN; un jeton faux est refusé plutôt qu'ignoré.
	function packClientIp(req) {
		const proof = req.headers['x-fluffless-proxy'];
		if (proof === undefined) {
			return clientIp(req);
		}
		if (!config.packsProxyToken || typeof proof !== 'string' || !safeEqual(proof, config.packsProxyToken)) {
			throw new HttpError(401, 'proxy_invalid', 'Jeton de mandataire X-Fluffless-Proxy invalide');
		}
		const ip = req.headers['x-fluffless-client-ip'];
		if (typeof ip !== 'string' || !IP_PATTERN.test(ip)) {
			throw new HttpError(400, 'invalid_field', 'X-Fluffless-Client-IP doit porter l\'adresse IP de la cliente');
		}
		return ip;
	}

	function packRateLimited(req) {
		const limit = packIpLimiter.hit(packClientIp(req));
		return limit.allowed ? null : [429, { code: 'rate_limited' }, { 'Retry-After': String(limit.retryAfterSeconds) }];
	}

	async function packLicence(licence) {
		if (!licence) {
			throw new HttpError(403, 'licence_invalid', 'Licence requise dans X-Fluffless-Licence');
		}
		const [row] = await sql`select id, email, expires_at, revoked_at from licences where key_hash = ${keyHash(licence)}`;
		if (!row || row.revoked_at) {
			throw new HttpError(403, 'licence_invalid', 'Licence inconnue ou révoquée');
		}
		return { ...row, active: !row.expires_at || row.expires_at.getTime() > now() };
	}

	const windowOpen = (pack, at) => Boolean(pack.claim_opens_at && pack.claim_opens_at <= at && pack.claim_closes_at > at);

	// Nouveau lien de 72 h pour une réclamation; les liens non utilisés d'avant sont annulés.
	async function issuePackLink(tx, claimId, at) {
		await tx`delete from pack_links where claim_id = ${claimId} and used_at is null`;
		const token = randomBytes(32).toString('base64url');
		await tx`
			insert into pack_links (token_hash, claim_id, expires_at, created_at)
			values (${packLinkHash(token)}, ${claimId}, ${new Date(at.getTime() + PACK_LINK_TTL_MS)}, ${at})`;
		return `${config.publicUrl}/v1/packs/download/${token}`;
	}

	// Enregistre un paiement de pack confirmé. Idempotent par session Checkout : un rejeu du
	// webhook, ou la réconciliation qui repasse derrière, retrouve la ligne sans rien refaire.
	// Premier arrivé, premier servi : si la licence possède déjà le pack (réclamation gratuite
	// ou achat antérieur), le paiement est remboursé.
	async function settlePackPurchase(session) {
		const metadata = session.metadata ?? {};
		const paymentIntent = typeof session.payment_intent === 'string' ? session.payment_intent : session.payment_intent?.id ?? null;
		const at = new Date(now());
		const settled = await sql.begin(async (tx) => {
			const [licence] = DB_ID.test(metadata.licence_id ?? '')
				? await tx`select id, email from licences where id = ${metadata.licence_id} for update`
				: [];
			const [pack] = DB_ID.test(metadata.pack_id ?? '')
				? await tx`select id, slug, name_fr, name_en from packs where id = ${metadata.pack_id}`
				: [];
			// L'insertion sert de verrou : une livraison concurrente du même événement attend ici,
			// puis retrouve la ligne.
			const [inserted] = await tx`
				insert into pack_purchases (checkout_session_id, payment_intent, licence_id, pack_id, amount_total, currency,
					outcome, created_at, updated_at)
				values (${session.id}, ${paymentIntent}, ${licence?.id ?? null}, ${pack?.id ?? null},
					${Number(session.amount_total) || 0}, ${String(session.currency ?? '')}, 'refund_pending', ${at}, ${at})
				on conflict (checkout_session_id) do nothing
				returning checkout_session_id`;
			await tx`delete from pack_checkouts where checkout_session_id = ${session.id}`;
			if (!inserted) {
				const [existing] = await tx`select outcome, refund_reason from pack_purchases where checkout_session_id = ${session.id}`;
				return { fresh: false, outcome: existing.outcome, reason: existing.refund_reason };
			}
			// Seul le pays de l'adresse de facturation est lu, rien n'est gardé. La règle suit le mode
			// de la session (posé dans ses métadonnées), pas l'interrupteur actuel : une session
			// ouverte avant un changement de mode se règle selon ce qu'elle a affiché.
			const country = session.customer_details?.address?.country;
			const managed = metadata.sale === 'managed';
			const countryReason = managed
				? (MANAGED_COUNTRIES.has(country) ? null : 'country_not_covered')
				: (SALE_COUNTRIES.has(country) ? null : 'outside_canada');
			let reason = !licence ? 'licence_missing' : !pack ? 'pack_missing' : countryReason;
			let link = null;
			if (!reason) {
				const [claim] = await tx`
					insert into pack_claims (licence_id, pack_id, source) values (${licence.id}, ${pack.id}, 'purchase')
					on conflict (licence_id, pack_id) do nothing returning id`;
				if (claim) {
					link = { name: pack[`name_${(metadata.locale ?? '').startsWith('en') ? 'en' : 'fr'}`], url: await issuePackLink(tx, claim.id, at) };
				} else {
					reason = 'already_owned';
				}
			}
			await tx`
				update pack_purchases set outcome = ${reason ? 'refund_pending' : 'granted'}, refund_reason = ${reason}
				where checkout_session_id = ${session.id}`;
			return { fresh: true, outcome: reason ? 'refund_pending' : 'granted', reason, licence, pack, link };
		});
		log('info', 'achat_pack', { outcome: settled.outcome, reason: settled.reason, fresh: settled.fresh });
		if (settled.outcome === 'refund_pending') {
			await refundPurchase(session.id, paymentIntent, settled.reason);
		} else if (settled.fresh && settled.link && settled.licence.email) {
			const expires = new Date(at.getTime() + PACK_LINK_TTL_MS);
			let delivered = false;
			try {
				delivered = await deliver({ to: settled.licence.email, locale: metadata.locale ?? 'fr_CA', links: [settled.link], expires, purchase: true });
			} catch (error) {
				log('warn', 'livraison_echec', { error: error.responseCode ?? error.code ?? 'inconnue' });
			}
			// Le pack est acquis et la cliente peut redemander son lien au catalogue, mais elle
			// vient de payer : on prévient pour pouvoir lui écrire.
			if (!delivered) {
				await sendAlert('Achat payé, courriel de livraison non parti', [
					`Licence : ${settled.licence.id}`,
					`Pack : ${settled.pack.slug}`,
					`Session Checkout : ${session.id}`,
				]);
			}
		}
		if (settled.outcome !== 'refund_pending') {
			await alertRefundBeforePurchase(settled, session, paymentIntent, settled.licence?.id);
		}
		return settled;
	}

	// La clé d'idempotence tient à la session : un nouvel essai ne rembourse jamais deux fois.
	// table : pack_purchases ou licence_purchases, selon ce que la session vendait.
	async function refundPurchase(sessionId, paymentIntent, reason, table = 'pack_purchases') {
		if (!paymentIntent) {
			throw new Error(`Session ${sessionId} sans payment_intent : remboursement impossible`);
		}
		let refund;
		try {
			refund = await stripe.createRefund({
				payment_intent: paymentIntent,
				...(reason === 'already_owned' ? { reason: 'duplicate' } : {}),
				metadata: { checkout_session_id: sessionId, refund_reason: reason ?? '' },
			}, `refund-${sessionId}`);
		} catch (error) {
			// Remboursé à la main dans le tableau de bord, ou réponse perdue après l'expiration de
			// la clé d'idempotence : on reprend le remboursement existant.
			if (error.code !== 'charge_already_refunded') {
				throw error;
			}
			const existing = await stripe.listRefunds({ payment_intent: paymentIntent, limit: 1 });
			refund = existing.data?.[0];
			if (!refund?.id) {
				throw error;
			}
		}
		const [row] = await sql`
			update ${sql(table)} set outcome = 'refunded', refund_id = ${refund.id}, updated_at = ${new Date(now())}
			where checkout_session_id = ${sessionId} and outcome = 'refund_pending'
			returning licence_id, amount_total, currency`;
		log('info', 'remboursement_auto', { reason });
		if (row) {
			await sendAlert({ licence_purchases: 'Remboursement automatique d\'une licence', licence_renewals: 'Remboursement automatique d\'un renouvellement' }[table] ?? 'Remboursement automatique d\'un pack', [
				`Raison : ${reason}`,
				`Licence : ${row.licence_id ?? 'aucune'}`,
				`Montant : ${formatAmount(row.amount_total, row.currency)}`,
				`Session Checkout : ${sessionId}`,
				`Remboursement Stripe : ${refund.id}`,
			]);
		}
	}

	// Retire les sessions ouvertes de la licence pour ce pack, sauf keepId. À appeler sous le
	// verrou de la ligne de licence : la dernière transaction validée garde la seule session.
	const takeOpenCheckouts = (tx, licenceId, packId, keepId = '') => tx`
		delete from pack_checkouts
		where licence_id = ${licenceId} and pack_id = ${packId} and checkout_session_id <> ${keepId}
		returning checkout_session_id`;

	// Expire chez Stripe, hors transaction. Au mieux : une session déjà payée ou expirée refuse
	// l'expiration, et le webhook règle le reste (remboursement si la course est perdue).
	async function expireCheckouts(rows) {
		if (!stripe) {
			return;
		}
		for (const row of rows) {
			try {
				await stripe.expireCheckoutSession(row.checkout_session_id);
			} catch (error) {
				log('info', 'expiration_session', { error: error.code ?? 'inconnue' });
			}
		}
	}

	// Une alerte qui lève une erreur laisse le même témoin que celle que le courriel ne livre pas
	// (voir createAlertMailer) : le journal, au niveau error.
	async function sendAlert(subject, lines) {
		try {
			await alert({ subject, lines });
		} catch (error) {
			log('error', 'alerte_non_livree', { subject, lines, cause: `erreur ${error.responseCode ?? error.code ?? 'inconnue'}` });
		}
	}

	// Remboursement ou litige vu par Stripe : alerte seulement, jamais de retrait d'accès
	// automatique (docs/PLAN.md, point 2). La colonne source dit si la ligne était payée.
	async function alertPayment(type, object) {
		const paymentIntent = typeof object.payment_intent === 'string' ? object.payment_intent : null;
		const [purchase] = paymentIntent ? await sql`
			select p.licence_id, p.outcome, p.refund_reason, pk.slug, c.source
			from pack_purchases p
			left join packs pk on pk.id = p.pack_id
			left join pack_claims c on c.licence_id = p.licence_id and c.pack_id = p.pack_id
			where p.payment_intent = ${paymentIntent}` : [];
		const [licencePurchase] = paymentIntent && !purchase ? await sql`
			select p.licence_id, p.outcome, p.refund_reason, o.slug, l.revoked_at
			from licence_purchases p
			left join licence_offers o on o.id = p.offer_id
			left join licences l on l.id = p.licence_id
			where p.payment_intent = ${paymentIntent}` : [];
		const [renewal] = paymentIntent && !purchase && !licencePurchase ? await sql`
			select licence_id, outcome, refund_reason from licence_renewals where payment_intent = ${paymentIntent}` : [];
		const dispute = type === 'charge.dispute.created';
		// Remboursement automatique : l'alerte est déjà partie avec le motif.
		if (!dispute && (purchase ?? licencePurchase ?? renewal)?.outcome === 'refunded') {
			return;
		}
		const known = purchase
			? `Pack : ${purchase.slug ?? 'supprimé'}, licence ${purchase.licence_id ?? 'effacée'}, issue ${purchase.outcome}${purchase.refund_reason ? ` (${purchase.refund_reason})` : ''}, accès actuel : ${purchase.source ?? 'aucun'}`
			: licencePurchase
				? `Licence achetée : offre ${licencePurchase.slug ?? 'supprimée'}, licence ${licencePurchase.licence_id ?? 'effacée'}, issue ${licencePurchase.outcome}${licencePurchase.refund_reason ? ` (${licencePurchase.refund_reason})` : ''}, ${licencePurchase.revoked_at ? 'révoquée' : 'toujours active'}`
				: renewal
					? `Renouvellement de la licence ${renewal.licence_id ?? 'effacée'}, issue ${renewal.outcome}${renewal.refund_reason ? ` (${renewal.refund_reason})` : ''}`
					: 'Aucun achat de pack ni de licence connu pour ce paiement.';
		await sendAlert(dispute ? 'Litige Stripe ouvert' : 'Remboursement Stripe', [
			`Événement : ${type}`,
			`Objet : ${object.id ?? 'inconnu'}`,
			`Montant : ${formatAmount(dispute ? object.amount : object.amount_refunded, object.currency)}`,
			...(dispute ? [`Motif du litige : ${object.reason ?? 'non précisé'}`] : []),
			known,
			'Aucun accès n\'a été retiré automatiquement.',
		]);
	}

	// Échec de paiement : toujours une trace au journal. Seule une vente différée perdue pour de
	// bon (async_payment_failed) alerte; un refus de carte se rejoue au paiement, et une alerte par
	// refus noierait les vraies. Jamais d'adresse courriel : la session suffit pour retrouver.
	async function reportFailedPayment(type, object) {
		const deferred = type === 'checkout.session.async_payment_failed';
		const short = (value) => (typeof value === 'string' ? value.slice(0, 60) : null);
		if (!deferred) {
			const failure = object.last_payment_error ?? {};
			log('warn', 'paiement_echoue', { type, payment_intent: short(object.id), code: short(failure.code), decline_code: short(failure.decline_code) });
			return;
		}
		const kind = short(object.metadata?.kind);
		log('error', 'paiement_echoue', { type, session: short(object.id), kind });
		if (kind === 'pack' || kind === 'licence' || kind === 'licence_renewal') {
			await sendAlert('Paiement échoué', [
				`Événement : ${type}`,
				`Session Checkout : ${object.id ?? 'inconnue'}`,
				`Vente : ${kind}`,
				`Montant : ${formatAmount(object.amount_total, object.currency)}`,
				'Aucune licence ni aucun accès n\'a été accordé.',
			]);
		}
	}

	// Un remboursement arrivé avant l'événement d'achat n'a rien trouvé à relier (alerte « aucun achat
	// connu »). Quand l'achat se règle ensuite, la licence naît sans lien avec ce remboursement : une
	// alerte les rapproche. Le webhook garde l'identifiant du paiement de chaque remboursement reçu.
	async function alertRefundBeforePurchase(settled, session, paymentIntent, licenceId) {
		if (!settled.fresh || settled.outcome !== 'granted' || !paymentIntent) {
			return;
		}
		try {
			const [early] = await sql`
				select 1 from stripe_events where type = 'charge.refunded' and payload->>'payment_intent' = ${paymentIntent} limit 1`;
			if (early) {
				await sendAlert('Achat réglé après son remboursement', [
					`Licence : ${licenceId ?? 'aucune'}`,
					`Session Checkout : ${session.id}`,
					'Un remboursement Stripe est arrivé avant cet achat : la licence a été accordée.',
					'Aucun accès n\'a été retiré automatiquement.',
				]);
			}
		} catch (error) {
			// Une alerte de rapprochement ne doit jamais empêcher la livraison ni faire rejouer l'événement.
			log('error', 'alerte_remboursement_echec', { error: error.code ?? 'inconnue' });
		}
	}

	async function handleStripeEvent(event) {
		const object = event.data.object;
		switch (event.type) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				if (object.payment_status !== 'paid') {
					return;
				}
				if (object.metadata?.kind === 'pack') {
					await settlePackPurchase(object);
				} else if (object.metadata?.kind === 'licence') {
					await settleLicencePurchase(object);
				} else if (object.metadata?.kind === 'licence_renewal') {
					await settleLicenceRenewal(object);
				}
				return;
			case 'checkout.session.async_payment_failed':
			case 'payment_intent.payment_failed':
				await reportFailedPayment(event.type, object);
				return;
			case 'charge.refunded':
			case 'charge.dispute.created':
				await alertPayment(event.type, object);
				return;
			default:
				return;
		}
	}

	// Rattrape un webhook qui n'arriverait jamais, et réessaie les remboursements en attente.
	async function reconcile() {
		if (!stripe) {
			return { checked: 0, settled: 0, refunds_retried: 0 };
		}
		const since = Math.floor(now() / 1000) - RECONCILE_WINDOW_SECONDS;
		let checked = 0;
		let settled = 0;
		let startingAfter;
		for (let page = 0; page < RECONCILE_MAX_PAGES; page++) {
			const list = await stripe.listCheckoutSessions({
				created: { gte: since },
				status: 'complete',
				limit: 100,
				...(startingAfter ? { starting_after: startingAfter } : {}),
			});
			for (const session of list.data ?? []) {
				checked++;
				const kind = session.metadata?.kind;
				if ((kind !== 'pack' && kind !== 'licence' && kind !== 'licence_renewal') || session.payment_status !== 'paid') {
					continue;
				}
				const table = { pack: 'pack_purchases', licence: 'licence_purchases', licence_renewal: 'licence_renewals' }[kind];
				const [known] = await sql`select 1 from ${sql(table)} where checkout_session_id = ${session.id}`;
				if (known) {
					continue;
				}
				try {
					// Un webhook qui passe au même moment rend fresh: false : pas un rattrapage.
					const result = await { pack: settlePackPurchase, licence: settleLicencePurchase, licence_renewal: settleLicenceRenewal }[kind](session);
					if (result.fresh) {
						settled++;
					}
				} catch (error) {
					log('error', 'rattrapage_echec', { error: error.code ?? error.name });
				}
			}
			if (!list.has_more || !list.data?.length) {
				break;
			}
			startingAfter = list.data.at(-1).id;
		}
		await sql`delete from pack_checkouts where created_at < ${new Date(now() - CHECKOUT_ROW_MAX_MS)}`;
		const pending = await sql`
			select 'pack_purchases' as source, checkout_session_id, payment_intent, refund_reason, created_at
			from pack_purchases where outcome = 'refund_pending'
			union all
			select 'licence_purchases', checkout_session_id, payment_intent, refund_reason, created_at
			from licence_purchases where outcome = 'refund_pending'
			union all
			select 'licence_renewals', checkout_session_id, payment_intent, refund_reason, created_at
			from licence_renewals where outcome = 'refund_pending'`;
		let retried = 0;
		const stuck = [];
		for (const row of pending) {
			try {
				await refundPurchase(row.checkout_session_id, row.payment_intent, row.refund_reason, row.source);
				retried++;
			} catch (error) {
				log('error', 'remboursement_echec', { error: error.code ?? error.name });
				if (now() - row.created_at.getTime() > REFUND_STUCK_MS) {
					stuck.push(`Session ${row.checkout_session_id} (${row.refund_reason}) : ${error.code ?? 'erreur'}`);
				}
			}
		}
		if (stuck.length > 0) {
			await sendAlert('Remboursements bloqués depuis plus de 48 h', [
				...stuck,
				'Rembourser à la main dans le tableau de bord Stripe : la réconciliation suivante le verra.',
			]);
		}
		if (settled > 0) {
			await sendAlert('Réconciliation Stripe : achats rattrapés', [
				`${settled} paiement(s) confirmé(s) par Stripe n'étaient pas en base : le webhook ne les a pas livrés.`,
				'Vérifier l\'état du point de terminaison dans le tableau de bord Stripe.',
			]);
		}
		log('info', 'reconciliation', { checked, settled, refunds_retried: retried });
		return { checked, settled, refunds_retried: retried, refunds_pending: pending.length - retried };
	}

	// Enregistre un paiement de licence confirmé et crée la licence. Idempotent par session
	// Checkout, comme settlePackPurchase. Le courriel part hors transaction : un échec laisse
	// l'achat non livré, et redeliverLicences réessaie.
	// Contrôle du prix : le sous-total de Checkout (avant taxes et rabais) doit valoir le prix
	// attendu. Un écart n'arrête rien (la cliente a payé ce que Checkout lui a montré) : alerte seulement.
	async function alertPriceGap(session, expected, licenceId) {
		const subtotal = session.amount_subtotal;
		if (expected === null || expected === undefined || !Number.isInteger(subtotal) || subtotal === expected) {
			return;
		}
		log('warn', 'ecart_prix', { expected, subtotal });
		await sendAlert('Écart de prix entre la session Checkout et le prix attendu', [
			`Licence : ${licenceId ?? 'aucune'}`,
			`Prix attendu : ${expected} cents; sous-total payé : ${subtotal} cents (avant taxes et rabais).`,
			'La licence est servie quand même. Vérifier le prix de l\'offre et le prix gelé de la licence.',
		]);
	}

	// Note de la licence achetée : l'offre, et pour un lien admin la raison du rabais (organisme,
	// NEQ) posée par l'administration à la création du lien.
	function licenceNote(slug, metadata) {
		const note = metadata.link === 'admin' && typeof metadata.note === 'string'
			? metadata.note.replace(/[\p{Cc}\p{Cf}]/gu, ' ').trim().slice(0, 120)
			: '';
		return note ? `Achat ${slug} · ${note}` : `Achat ${slug}`;
	}

	async function settleLicencePurchase(session) {
		const metadata = session.metadata ?? {};
		const paymentIntent = typeof session.payment_intent === 'string' ? session.payment_intent : session.payment_intent?.id ?? null;
		const locale = LOCALE.test(metadata.locale ?? '') ? metadata.locale : 'fr_CA';
		const at = new Date(now());
		const settled = await sql.begin(async (tx) => {
			const [offer] = DB_ID.test(metadata.offer_id ?? '')
				? await tx`
					select id, slug, plan, sites_max, duration_days, release_slugs, stripe_price_id, price_amount, price_currency, stripe_product_id
					from licence_offers where id = ${metadata.offer_id}`
				: [];
			// Le code vient des métadonnées posées à la création de la session; il ne compte que s'il
			// existe encore dans la table.
			const [coupon] = typeof metadata.coupon === 'string' && COUPON_CODE.test(metadata.coupon)
				? await tx`select code from licence_coupons where code = ${metadata.coupon}`
				: [];
			const discount = coupon ? Math.max(0, Math.trunc(Number(session.total_details?.amount_discount) || 0)) : 0;
			const [inserted] = await tx`
				insert into licence_purchases (checkout_session_id, payment_intent, offer_id, amount_total, currency, locale,
					coupon_code, discount_amount, outcome, created_at, updated_at)
				values (${session.id}, ${paymentIntent}, ${offer?.id ?? null}, ${Number(session.amount_total) || 0},
					${String(session.currency ?? '')}, ${locale}, ${coupon?.code ?? null}, ${discount}, 'refund_pending', ${at}, ${at})
				on conflict (checkout_session_id) do nothing
				returning checkout_session_id`;
			if (!inserted) {
				const [existing] = await tx`select outcome, refund_reason from licence_purchases where checkout_session_id = ${session.id}`;
				return { fresh: false, outcome: existing.outcome, reason: existing.refund_reason };
			}
			const country = session.customer_details?.address?.country;
			const managed = metadata.sale === 'managed';
			const countryReason = managed
				? (MANAGED_COUNTRIES.has(country) ? null : 'country_not_covered')
				: (SALE_COUNTRIES.has(country) ? null : 'outside_canada');
			const reason = !offer ? 'offer_missing' : countryReason;
			if (reason) {
				await tx`update licence_purchases set refund_reason = ${reason} where checkout_session_id = ${session.id}`;
				return { fresh: true, outcome: 'refund_pending', reason };
			}
			// L'adresse saisie chez Stripe devient celle de la licence. Sans adresse lisible, la
			// licence naît quand même : l'alerte de livraison invite à la renvoyer avec --email.
			const rawEmail = session.customer_details?.email;
			const email = typeof rawEmail === 'string' && rawEmail.length <= 254 && EMAIL.test(rawEmail) ? rawEmail : null;
			const expires = offer.duration_days ? new Date(at.getTime() + offer.duration_days * 86_400_000) : null;
			// Prix gelé : celui de la session; à défaut (session créée avant le prix gelé), celui de l'offre.
			const priced = pricedFrom(metadata) ?? (offer.stripe_price_id && offer.price_currency === 'cad' && offer.price_amount >= 50
				? { price_id: offer.stripe_price_id, unit_amount: offer.price_amount, product_id: offer.stripe_product_id }
				: null);
			const [licence] = await tx`
				insert into licences (key_hash, plan, sites_max, expires_at, note, email, release_slugs,
					renewal_price_id, renewal_product_id, renewal_unit_amount, renewal_currency)
				values (${keyHash(purchaseKey(session.id, 0))}, ${offer.plan}, ${offer.sites_max}, ${expires},
					${licenceNote(offer.slug, metadata)}, ${email}, ${offer.release_slugs},
					${priced?.price_id ?? null}, ${priced?.product_id ?? null}, ${priced?.unit_amount ?? null}, ${priced ? 'cad' : null})
				returning id`;
			await tx`
				update licence_purchases set outcome = 'granted', licence_id = ${licence.id}, updated_at = ${at}
				where checkout_session_id = ${session.id}`;
			return { fresh: true, outcome: 'granted', reason: null, licenceId: licence.id, expected: priced?.unit_amount ?? null };
		});
		log('info', 'achat_licence', { outcome: settled.outcome, reason: settled.reason, fresh: settled.fresh });
		if (settled.fresh) {
			await alertPriceGap(session, settled.expected, settled.licenceId);
		}
		if (settled.outcome === 'refund_pending') {
			await refundPurchase(session.id, paymentIntent, settled.reason, 'licence_purchases');
		} else if (settled.fresh) {
			await deliverLicencePurchase(session.id);
			await alertRefundBeforePurchase(settled, session, paymentIntent, settled.licenceId);
		}
		return settled;
	}

	// Le message a peut-être été remis malgré l'erreur : tout délai dépassé, ou une coupure en pleine
	// transmission du message (DATA). Le délai de socket de nodemailer porte toujours command « CONN », même
	// après DATA : on ne peut donc pas lire l'étape dans l'erreur, et tout délai reste ambigu. Tout le reste
	// est un échec prouvé : refus SMTP (4xx, 5xx), connexion jamais établie, identifiants ou adresse refusés,
	// ou une erreur de notre côté. Une clé redonnée par la relivraison coûte moins cher qu'une clé morte.
	const DURING_MESSAGE = /^(DATA|<message>|MESSAGE|\.)$/i;
	const GOT_LOST = new Set(['ESOCKET', 'ECONNECTION', 'ECONNRESET', 'EPROTOCOL']);
	function maybeDelivered(error) {
		if (Number(error?.responseCode) >= 400) {
			return false;
		}
		if (error?.code === 'ETIMEDOUT') {
			return true;
		}
		return GOT_LOST.has(error?.code) && DURING_MESSAGE.test(String(error?.command ?? ''));
	}

	// Envoie la clé sous un bail : deux essais simultanés (webhook et relivraison) ne partent
	// jamais tous les deux. La clé de la génération courante remplace le hachage de la licence
	// avant l'envoi, pour qu'elle ouvre la licence dès que le courriel arrive. Si l'envoi échoue sans
	// doute possible (tout sauf maybeDelivered), le hachage précédent est remis : la clé déjà livrée
	// continue de fonctionner et celle qui n'est jamais partie n'ouvre rien. Si l'issue est ambiguë
	// (maybeDelivered), la nouvelle clé reste en place : le courriel a pu partir, et la relivraison
	// renvoie la même clé (elle est dérivée de la session, pas tirée au hasard), au lieu de laisser
	// une clé reçue morte. Si l'envoi réussit, la nouvelle clé reste en place même quand l'écriture
	// de « livré » échoue ensuite : la relivraison renvoie alors la même clé.
	async function deliverLicencePurchase(sessionId) {
		const at = new Date(now());
		const lease = new Date(at.getTime() + LICENCE_DELIVERY_LEASE_MS);
		const [row] = await sql`
			update licence_purchases p
			set delivering_until = ${lease}, delivery_attempts = p.delivery_attempts + 1, updated_at = ${at}
			from licences l
			where p.checkout_session_id = ${sessionId} and p.outcome = 'granted' and p.delivered_at is null
				and l.id = p.licence_id and l.revoked_at is null
				and (p.delivering_until is null or p.delivering_until <= ${at})
			returning p.licence_id, p.key_generation, p.delivery_attempts, p.locale, l.email, l.release_slugs, l.expires_at`;
		if (!row) {
			return 'skipped';
		}
		const key = purchaseKey(sessionId, row.key_generation);
		const newHash = keyHash(key);
		const [{ key_hash: previousHash }] = await sql`select key_hash from licences where id = ${row.licence_id}`;
		await sql`update licences set key_hash = ${newHash} where id = ${row.licence_id}`;
		let delivered = false;
		let maybeSent = false;
		let failure = row.email ? 'smtp' : 'email_missing';
		if (row.email) {
			try {
				delivered = await deliverLicence({
					to: row.email, locale: row.locale, key, url: `${config.publicUrl}/v1/licences/download`,
					several: (row.release_slugs?.length ?? 1) > 1, expires: row.expires_at, reminders: config.remindersEnabled,
				});
				failure = delivered ? null : 'smtp_absent';
			} catch (error) {
				// Le message SMTP peut citer l'adresse : seul le code part au journal.
				log('warn', 'livraison_licence_echec', { error: error.responseCode ?? error.code ?? 'inconnue' });
				failure = `smtp ${error.responseCode ?? error.code ?? 'inconnue'}`;
				maybeSent = maybeDelivered(error);
			}
		}
		if (!delivered && !maybeSent && previousHash !== newHash) {
			await sql`update licences set key_hash = ${previousHash} where id = ${row.licence_id} and key_hash = ${newHash}`;
		}
		await sql`
			update licence_purchases
			set delivering_until = null, delivered_at = ${delivered ? new Date(now()) : null}, updated_at = ${new Date(now())}
			where checkout_session_id = ${sessionId} and delivering_until = ${lease}`;
		if (delivered) {
			return 'delivered';
		}
		if (row.delivery_attempts === 1 || row.delivery_attempts % LICENCE_DELIVERY_ALERT_EVERY === 0) {
			await sendAlert('Licence payée, courriel de la clé non parti', [
				`Licence : ${row.licence_id}`,
				`Session Checkout : ${sessionId}`,
				`Tentatives : ${row.delivery_attempts}`,
				`Cause : ${failure}`,
				'La relivraison réessaie toutes les 10 minutes. Adresse à corriger : node bin/admin.mjs licence-resend <id> --email <adresse>.',
			]);
		}
		return 'failed';
	}

	// Appelée toutes les 10 minutes par server.mjs : les clés payées dont le courriel n'est pas parti.
	async function redeliverLicences() {
		const rows = await sql`
			select p.checkout_session_id from licence_purchases p
			join licences l on l.id = p.licence_id and l.revoked_at is null
			where p.outcome = 'granted' and p.delivered_at is null
				and l.email is not null
				and (p.delivering_until is null or p.delivering_until <= ${new Date(now())})
			order by p.delivery_attempts, p.created_at
			limit ${LICENCE_REDELIVER_BATCH}`;
		const counts = { delivered: 0, failed: 0, skipped: 0 };
		for (const row of rows) {
			try {
				counts[await deliverLicencePurchase(row.checkout_session_id)]++;
			} catch (error) {
				counts.failed++;
				log('error', 'relivraison_echec', { error: error.code ?? error.name });
			}
		}
		if (rows.length > 0) {
			log('info', 'relivraison_licences', counts);
		}
		return counts;
	}

	// Enregistre un paiement de renouvellement : la clé ne change pas, la fin recule d'un an (à
	// partir d'aujourd'hui si la licence est déjà expirée). Idempotent par session Checkout. La
	// ligne de licence est verrouillée : deux paiements pour la même période se suivent, le
	// second voit la nouvelle fin et se fait rembourser.
	async function settleLicenceRenewal(session) {
		const metadata = session.metadata ?? {};
		const paymentIntent = typeof session.payment_intent === 'string' ? session.payment_intent : session.payment_intent?.id ?? null;
		const locale = LOCALE.test(metadata.locale ?? '') ? metadata.locale : 'fr_CA';
		const licenceId = DB_ID.test(metadata.licence_id ?? '') ? metadata.licence_id : null;
		const until = /^\d{1,11}$/.test(metadata.until ?? '') ? Number(metadata.until) : null;
		const priced = pricedFrom(metadata);
		const basis = metadata.price_basis === 'gele' || metadata.price_basis === 'jour' ? metadata.price_basis : null;
		const at = new Date(now());
		const settled = await sql.begin(async (tx) => {
			const [offer] = DB_ID.test(metadata.offer_id ?? '')
				? await tx`select id, duration_days from licence_offers where id = ${metadata.offer_id}`
				: [];
			const [inserted] = await tx`
				insert into licence_renewals (checkout_session_id, payment_intent, licence_id, offer_id, period_from, amount_total,
					currency, locale, outcome, unit_amount, price_basis, created_at, updated_at)
				values (${session.id}, ${paymentIntent}, null, ${offer?.id ?? null}, ${until === null ? null : new Date(until * 1000)},
					${Number(session.amount_total) || 0}, ${String(session.currency ?? '')}, ${locale}, 'refund_pending',
					${priced?.unit_amount ?? null}, ${basis}, ${at}, ${at})
				on conflict (checkout_session_id) do nothing
				returning checkout_session_id`;
			if (!inserted) {
				const [existing] = await tx`select outcome, refund_reason from licence_renewals where checkout_session_id = ${session.id}`;
				return { fresh: false, outcome: existing.outcome, reason: existing.refund_reason };
			}
			const refuse = async (reason) => {
				await tx`update licence_renewals set refund_reason = ${reason} where checkout_session_id = ${session.id}`;
				return { fresh: true, outcome: 'refund_pending', reason };
			};
			const [licence] = licenceId
				? await tx`select id, expires_at, revoked_at from licences where id = ${licenceId} for update`
				: [];
			if (!licence || licence.revoked_at || !offer?.duration_days || until === null) {
				return refuse(!offer?.duration_days ? 'offer_missing' : 'licence_missing');
			}
			const country = session.customer_details?.address?.country;
			const countryOk = metadata.sale === 'managed' ? MANAGED_COUNTRIES.has(country) : SALE_COUNTRIES.has(country);
			if (!countryOk) {
				return refuse(metadata.sale === 'managed' ? 'country_not_covered' : 'outside_canada');
			}
			if (!licence.expires_at || Math.floor(licence.expires_at.getTime() / 1000) !== until) {
				return refuse('already_renewed');
			}
			const from = Math.max(licence.expires_at.getTime(), at.getTime());
			const expires = new Date(from + offer.duration_days * 86_400_000);
			await tx`update licences set expires_at = ${expires} where id = ${licence.id}`;
			// Renouvellement au prix du jour (après la grâce) : ce prix devient le nouveau prix gelé.
			if (basis === 'jour' && priced) {
				await tx`
					update licences set renewal_price_id = ${priced.price_id}, renewal_product_id = ${priced.product_id},
						renewal_unit_amount = ${priced.unit_amount}, renewal_currency = 'cad'
					where id = ${licence.id}`;
			}
			await tx`
				update licence_renewals set outcome = 'granted', licence_id = ${licence.id}, updated_at = ${at}
				where checkout_session_id = ${session.id}`;
			return { fresh: true, outcome: 'granted', reason: null, licenceId: licence.id };
		});
		log('info', 'renouvellement_licence', { outcome: settled.outcome, reason: settled.reason, fresh: settled.fresh });
		if (settled.fresh && settled.outcome === 'granted') {
			await alertPriceGap(session, priced?.unit_amount ?? null, settled.licenceId);
		}
		if (settled.outcome === 'refund_pending') {
			await refundPurchase(session.id, paymentIntent, settled.reason, 'licence_renewals');
		} else {
			await alertRefundBeforePurchase(settled, session, paymentIntent, settled.licenceId);
		}
		return settled;
	}

	// Rappels d'expiration à 30 et 7 jours. Chaque rappel se prend dans licence_reminders avant
	// l'envoi (un seul passage l'emporte) et se retire si le courriel échoue : le passage suivant
	// réessaie. Une licence sans courriel valide est ignorée sans erreur.
	async function sendRenewalReminders() {
		const from = new Date(now());
		const rows = await sql`
			select l.id, l.email, l.expires_at, p.locale,
				((l.renewal_price_id is not null or o.stripe_price_id is not null) and o.duration_days is not null) as renewable
			from licences l
			left join licence_purchases p on p.licence_id = l.id
			left join licence_offers o on o.id = p.offer_id
			where l.revoked_at is null and l.email is not null
				and l.expires_at > ${from} and l.expires_at <= ${new Date(from.getTime() + 30 * 86_400_000)}
				and not exists (
					select 1 from licence_reminders r
					where r.licence_id = l.id and r.period_end = l.expires_at and r.sent_at is not null
						and r.days_before = case when l.expires_at <= ${new Date(from.getTime() + 7 * 86_400_000)} then 7 else 30 end)
			order by l.expires_at
			limit ${REMINDER_BATCH}`;
		const counts = { sent: 0, failed: 0, skipped: 0 };
		for (const row of rows) {
			if (typeof row.email !== 'string' || row.email.length > 254 || !EMAIL.test(row.email)) {
				counts.skipped++;
				continue;
			}
			const threshold = row.expires_at.getTime() - from.getTime() <= 7 * 86_400_000 ? 7 : 30;
			// Jours réellement restants, pour le texte : le seuil ne sert qu'à ne pas envoyer deux fois.
			const daysLeft = Math.max(1, Math.ceil((row.expires_at.getTime() - from.getTime()) / 86_400_000));
			const [claim] = await sql`
				insert into licence_reminders (licence_id, period_end, days_before, claimed_at)
				values (${row.id}, ${row.expires_at}, ${threshold}, ${from})
				on conflict (licence_id, period_end, days_before) do update set claimed_at = excluded.claimed_at
				where licence_reminders.sent_at is null and licence_reminders.claimed_at <= ${new Date(from.getTime() - REMINDER_CLAIM_MS)}
				returning licence_id`;
			if (!claim) {
				counts.skipped++;
				continue;
			}
			let sent = false;
			try {
				sent = await remind({
					to: row.email, locale: row.locale ?? 'fr_CA', daysLeft, expires: row.expires_at,
					renewUrl: row.renewable ? renewUrl(row.id, Math.floor(row.expires_at.getTime() / 1000)) : null,
				});
			} catch (error) {
				// Le message SMTP peut citer l'adresse : seul le code part au journal.
				log('warn', 'rappel_echec', { error: error.responseCode ?? error.code ?? 'inconnue' });
			}
			try {
				if (sent) {
					counts.sent++;
					await sql`update licence_reminders set sent_at = ${new Date(now())} where licence_id = ${row.id} and period_end = ${row.expires_at} and days_before = ${threshold}`;
				} else {
					counts.failed++;
					await sql`delete from licence_reminders where licence_id = ${row.id} and period_end = ${row.expires_at} and days_before = ${threshold}`;
				}
			} catch (error) {
				// La prise se reprend après REMINDER_CLAIM_MS : le lot continue.
				log('error', 'rappel_ecriture_echec', { error: error.code ?? error.name });
			}
		}
		if (rows.length > 0) {
			log('info', 'rappels_licences', counts);
		}
		return counts;
	}

	// Offre en vente : prix posé et une version stable publiée pour chaque extension couverte.
	// Sans SMTP, ni la clé ni l'alerte ne partiraient : la cliente paierait pour rien.
	async function saleableOffer(slug) {
		if (!stripe || !config.smtp.user || !config.smtp.token) {
			throw new HttpError(503, 'payments_unavailable', 'La vente des licences n\'est pas encore ouverte');
		}
		const [offer] = await sql`
			select id, slug, stripe_price_id, price_amount, stripe_product_id, release_slugs from licence_offers where slug = ${slug}`;
		if (!offer?.stripe_price_id) {
			throw new HttpError(404, 'not_for_sale', `L'offre ${slug} n'est pas en vente`);
		}
		// Une extension sans version stable : la cliente paierait pour un lien qui n'existe pas.
		for (const release of offer.release_slugs) {
			if (!await latestRelease(release, ['stable'], ['version'])) {
				log('warn', 'offre_sans_version', { offer: offer.slug, release });
				throw new HttpError(404, 'not_for_sale', `L'offre ${slug} n'est pas en vente`);
			}
		}
		return offer;
	}

	// Paramètres de session Checkout d'une licence, communs à la route publique et au lien admin :
	// taxes, adresse, conditions et avis de vente ne peuvent pas diverger entre les deux.
	function licenceSessionParams({ offer, locale, coupon = null, email = null, note = null, expiresAt }) {
		const managed = config.stripe.managedPayments;
		const metadata = {
			kind: 'licence', offer_id: String(offer.id), offer_slug: offer.slug, locale,
			// Le prix lu à la création de la session devient le prix gelé de la licence : l'offre peut
			// changer de prix pendant que la session est ouverte.
			...priceMetadata({ price_id: offer.stripe_price_id, unit_amount: offer.price_amount, product_id: offer.stripe_product_id }),
			...(coupon ? { coupon: coupon.code } : {}),
			...(note ? { link: 'admin', note } : {}),
			...(managed ? { sale: 'managed' } : {}),
		};
		const en = locale.startsWith('en');
		// La page de succès sait ainsi qu'elle annonce une clé par courriel, pas un pack.
		const success = new URL((en && config.stripe.successUrlEn) || config.stripe.successUrl);
		success.search = success.search ? `${success.search}&kind=licence` : '?kind=licence';
		return {
			mode: 'payment',
			line_items: [{ price: offer.stripe_price_id, quantity: 1 }],
			...(coupon ? { discounts: [{ coupon: coupon.stripe_coupon_id }] } : {}),
			...(email ? { customer_email: email } : {}),
			metadata,
			payment_intent_data: { metadata },
			...(managed ? { managed_payments: { enabled: true } } : { automatic_tax: { enabled: true } }),
			billing_address_collection: 'required',
			// Exige l'URL des conditions de vente dans les réglages publics du compte Stripe.
			consent_collection: { terms_of_service: 'required' },
			custom_text: { submit: { message: saleNotice(managed, en) } },
			locale: en ? 'en' : 'fr-CA',
			expires_at: expiresAt ?? Math.floor(now() / 1000) + CHECKOUT_TTL_SECONDS,
			success_url: success.href,
			cancel_url: (en && config.stripe.cancelUrlEn) || config.stripe.cancelUrl,
		};
	}

	// Crée la session sous le plafond horaire global, qui compte aussi les liens admin. Rend
	// { limited } (réponse 429 toute prête) quand le plafond est atteint, sinon { session }.
	async function createLicenceSession(options) {
		const global = licenceCheckoutGlobal.hit('*');
		if (!global.allowed) {
			if (now() - licenceCheckoutAlertAt >= 3_600_000) {
				licenceCheckoutAlertAt = now();
				await sendAlert('Vente des licences : plafond horaire de sessions atteint', [
					`${LICENCE_CHECKOUTS_PER_HOUR} sessions Checkout de licence créées dans l'heure : les suivantes reçoivent 429.`,
					'Un pic réel de ventes, ou un robot qui contourne la limite par IP.',
				]);
			}
			return { limited: [429, { code: 'rate_limited' }, { 'Retry-After': String(global.retryAfterSeconds) }] };
		}
		try {
			return { session: await stripe.createCheckoutSession(licenceSessionParams(options)) };
		} catch (error) {
			log('error', 'checkout_echec', { error: error.code ?? error.name });
			// Stripe refuse (400) une séance qui porte un coupon : son plafond est atteint ou le coupon
			// est périmé chez lui. Même refus que pour tout code inutilisable, pas une panne.
			if (options.coupon && error.status === 400) {
				throw new HttpError(422, 'coupon_invalid', 'Ce code de rabais n\'est pas valide');
			}
			throw new HttpError(502, 'payment_provider_error', 'Stripe ne répond pas : réessaie dans un moment');
		}
	}

	// Coupon utilisable maintenant : actif, pas expiré, pas épuisé. Un code inconnu, éteint, expiré ou
	// épuisé donne le même refus : la réponse ne dit pas lequel des cas, pour qu'on ne puisse pas
	// sonder les codes. Le plafond se vérifie ici et chez Stripe (max_redemptions), qui tranche si
	// deux séances se croisent.
	async function usableCoupon(raw) {
		const code = String(raw ?? '').trim().toUpperCase();
		if (!COUPON_CODE.test(code)) {
			return null;
		}
		const [coupon] = await sql`
			select c.code, c.percent_off, c.stripe_coupon_id
			from licence_coupons c
			where c.code = ${code} and c.active and (c.expires_at is null or c.expires_at > ${new Date(now())})
				and (c.max_redemptions is null
					or (select count(*) from licence_purchases p where p.coupon_code = c.code and p.outcome = 'granted') < c.max_redemptions)`;
		return coupon ?? null;
	}

	// Lit un prix Stripe et vérifie qu'il convient à la vente : actif, paiement unique, en CAD,
	// 0,50 $ au moins, taxes en sus, et code fiscal si Managed Payments est allumé.
	async function readSalePrice(priceId) {
		if (!stripe) {
			throw new HttpError(503, 'payments_unavailable', 'Paiements Stripe non configurés');
		}
		let price;
		try {
			// Le produit n'est lu qu'avec Managed Payments : la clé restreinte du mode Canada
			// n'a pas besoin du droit Products.
			price = await stripe.retrievePrice(priceId, config.stripe.managedPayments ? { expand: ['product'] } : undefined);
		} catch (error) {
			throw new HttpError(error.status === 404 ? 400 : 502, 'price_invalid', `Prix ${priceId} illisible chez Stripe (${error.code ?? 'erreur'})`);
		}
		if (!price.active || price.type !== 'one_time' || !Number.isInteger(price.unit_amount)) {
			throw new HttpError(400, 'price_invalid', 'Le prix doit être actif, à paiement unique et à montant fixe');
		}
		// Le site affiche un prix avant taxes, en dollars canadiens; 0,50 $ est le minimum de
		// Stripe, et un prix nul ne déclencherait jamais la livraison.
		if (price.currency !== 'cad' || price.unit_amount < 50 || price.tax_behavior !== 'exclusive') {
			throw new HttpError(400, 'price_invalid', 'Le prix doit être en CAD, d\'au moins 0,50 $, avec taxes en sus (tax_behavior exclusive)');
		}
		// Managed Payments refuse un produit sans code fiscal admissible. Stripe seul sait
		// lesquels le sont : on vérifie qu'il y en a un, l'admissibilité se voit au tableau de bord.
		const taxCode = typeof price.product === 'object' ? price.product?.tax_code : undefined;
		if (config.stripe.managedPayments && !(typeof taxCode === 'string' ? taxCode : taxCode?.id)) {
			throw new HttpError(400, 'price_invalid', 'Managed Payments : le produit du prix doit avoir un code fiscal admissible (« Eligible for Managed Payments »)');
		}
		const product = typeof price.product === 'string' ? price.product : price.product?.id;
		return { amount: price.unit_amount, currency: price.currency, product: PRODUCT_ID.test(product ?? '') ? product : null };
	}

	// Texte affiché sous le bouton de paiement : la règle de pays suit le mode de vente.
	function saleNotice(managed, en) {
		return managed
			? (en
				? 'Not sold in every country: a payment with a billing address in a country we do not serve is refunded automatically.'
				: 'Pas en vente dans tous les pays : un paiement avec une adresse de facturation dans un pays non desservi est remboursé automatiquement.')
			: (en
				? 'Sold in Canada only: a payment with a billing address outside Canada is refunded automatically.'
				: 'Vente au Canada seulement : un paiement avec une adresse de facturation hors du Canada est remboursé automatiquement.');
	}

	async function latestRelease(slug, channels, columns) {
		const rows = await sql`
			select ${sql(columns)} from releases
			where slug = ${slug} and channel in ${sql(channels)}`;
		return rows.reduce((best, row) => (!best || compareVersions(row.version, best.version) > 0 ? row : best), null);
	}

	const handlers = {
		async health() {
			await sql`select 1`;
			return [200, { status: 'ok' }];
		},

		// Manifeste signé de la dernière version stable, sans licence ni paquet : de quoi sonder en lecture
		// seule que la clé de signature du serveur correspond à celle des Suites installées.
		async manifest(req, url) {
			const slug = text(Object.fromEntries(url.searchParams), 'slug', { max: 64, pattern: SLUG });
			const release = await latestRelease(slug, ['stable'], ['slug', 'version', 'manifest', 'manifest_signature']);
			if (!release || !release.manifest) {
				throw new HttpError(404, 'unknown_slug', `Aucune version publiée pour ${slug}`);
			}
			return [200, { slug: release.slug, version: release.version, manifest: release.manifest, manifest_signature: release.manifest_signature }];
		},

		async update(req, url) {
			const q = Object.fromEntries(url.searchParams);
			const slug = text(q, 'slug', { max: 64, pattern: SLUG });
			const version = text(q, 'version', { max: 64 });
			if (!parseVersion(version)) {
				throw new HttpError(400, 'invalid_field', 'version invalide');
			}
			const channel = q.channel ?? 'stable';
			if (!['stable', 'beta'].includes(channel)) {
				throw new HttpError(400, 'invalid_field', 'channel doit valoir stable ou beta');
			}
			const site = text(q, 'site', { min: 64, max: 64, pattern: SITE });
			if (q.dev !== undefined && q.dev !== '1') {
				throw new HttpError(400, 'invalid_field', 'dev doit valoir 1 ou être absent');
			}
			const licence = licenceFrom(req, q);
			if (!licence) {
				return [403, { code: 'licence_invalid' }];
			}
			const release = await latestRelease(slug, channel === 'beta' ? ['stable', 'beta'] : ['stable'], [
				'slug', 'version', 'sha256', 'requires', 'requires_php', 'tested', 'changelog_fr', 'changelog_en',
				'manifest', 'manifest_signature',
			]);
			if (!release) {
				throw new HttpError(404, 'unknown_slug', `Aucune version publiée pour ${slug}`);
			}
			const state = await checkLicence(licence, site, { dev: q.dev === '1' });
			if (!state.valid) {
				// Pendant la grâce, la Suite montre la date limite et le lien sans attendre la vérification de la clé.
				const grace = state.grace_until ? { expires: state.expires, grace_until: state.grace_until, renew_url: state.renew_url } : {};
				return [403, { code: state.code, ...grace }];
			}
			// Extension non comprise dans la licence (SEO sur une licence Suite seule). Un 404 et non
			// un 403 : la Suite installée lirait un 403 comme une clé invalide et suspendrait tout.
			if (state.releases && !FREE_RELEASE_SLUGS.includes(release.slug) && !state.releases.includes(release.slug)) {
				return [404, { code: 'not_included' }];
			}
			// Un 204 n'a pas de corps : l'avis en cours passe aussi par un en-tête.
			const notice = state.notice_until ? { 'X-Fluffless-Notice-Until': state.notice_until } : {};
			if (compareVersions(release.version, version) <= 0) {
				return [204, null, notice];
			}
			const expires = Math.floor(now() / 1000) + DOWNLOAD_TTL_SECONDS;
			const token = downloadToken(release.slug, release.version, expires);
			return [200, {
				slug: release.slug,
				version: release.version,
				package: `${config.publicUrl}/v1/download/${release.slug}/${release.version}/${expires}/${token}.zip`,
				sha256: release.sha256,
				manifest: release.manifest,
				manifest_signature: release.manifest_signature,
				requires: release.requires,
				requires_php: release.requires_php,
				tested: release.tested,
				changelog: { fr: release.changelog_fr, en: release.changelog_en },
				...(state.notice_until ? { notice_until: state.notice_until } : {}),
			}, notice];
		},

		async download(req, url, params) {
			const [slug, version, expires, token, isSig] = params;
			const valid = safeEqual(token, downloadToken(slug, version, expires)) && Number(expires) * 1000 >= now();
			if (!valid) {
				// Le zip s'ouvre depuis la page de la clé : une personne, pas la Suite, lit ce refus.
				if (!isSig) {
					return linkExpiredHtml(`${config.publicUrl}/v1/licences/download`);
				}
				throw new HttpError(403, 'link_expired', 'Lien de téléchargement invalide ou expiré');
			}
			const columns = isSig ? sql`signature` : sql`signature, zip`;
			const [row] = await sql`select ${columns} from releases where slug = ${slug} and version = ${version}`;
			if (!row) {
				throw new HttpError(404, 'not_found', 'Version introuvable');
			}
			const body = isSig ? Buffer.from(row.signature) : row.zip;
			return [200, body, {
				'Content-Type': isSig ? 'text/plain; charset=utf-8' : 'application/zip',
				'Content-Disposition': `attachment; filename="${slug}-${version}.zip${isSig ? '.sig' : ''}"`,
				'X-Content-Signature': row.signature,
			}];
		},

		async info(req, url) {
			const q = Object.fromEntries(url.searchParams);
			const slug = text(q, 'slug', { max: 64, pattern: SLUG });
			licenceFrom(req, q); // Fiche publique : l'en-tête est accepté sans être exigé.
			const lang = (q.locale ?? 'fr_CA').startsWith('en') ? 'en' : 'fr';
			const release = await latestRelease(slug, ['stable'], [
				'name', 'slug', 'version', 'requires', 'tested', 'requires_php', 'homepage', 'created_at',
				`description_${lang}`, `changelog_${lang}`,
			]);
			if (!release) {
				throw new HttpError(404, 'unknown_slug', `Aucune version publiée pour ${slug}`);
			}
			return [200, {
				name: release.name,
				slug: release.slug,
				version: release.version,
				sections: { description: release[`description_${lang}`], changelog: release[`changelog_${lang}`] },
				requires: release.requires,
				tested: release.tested,
				requires_php: release.requires_php,
				last_updated: release.created_at.toISOString(),
				homepage: release.homepage,
			}];
		},

		async verifyLicence(req) {
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['site', 'dev']);
			const site = text(body, 'site', { min: 64, max: 64, pattern: SITE });
			if (body.dev !== undefined && typeof body.dev !== 'boolean') {
				throw new HttpError(400, 'invalid_field', `dev doit être un booléen (reçu : ${typeof body.dev})`);
			}
			const licence = licenceFrom(req, {});
			if (!licence) {
				return [200, { valid: false, plan: null, expires: null, sites_used: 0, sites_max: 0, code: 'licence_invalid' }];
			}
			const { code, releases, ...state } = await checkLicence(licence, site, { dev: body.dev === true });
			return [200, code ? { ...state, code } : state];
		},

		// La cliente rend la place d'un site (déménagement, site fermé). Une licence échue est
		// acceptée : elle doit pouvoir faire le ménage avant de renouveler. Une libération sans
		// effet ne compte pas; le verrou de ligne tient le plafond sous des appels simultanés.
		async releaseSite(req, url) {
			const body = await readJson(req, LIMITS.defaultBody);
			const licence = licenceFrom(req, Object.fromEntries(url.searchParams));
			rejectUnknown(body, ['site']);
			const site = text(body, 'site', { min: 64, max: 64, pattern: SITE });
			if (!licence) {
				throw new HttpError(403, 'licence_invalid', 'Licence requise dans X-Fluffless-Licence');
			}
			let flagged = null;
			const result = await sql.begin(async (tx) => {
				const [row] = await tx`select id, sites_max, revoked_at from licences where key_hash = ${keyHash(licence)} for update`;
				if (!row || row.revoked_at) {
					throw new HttpError(403, 'licence_invalid', 'Licence inconnue ou révoquée');
				}
				const hashed = siteHash(site);
				const at = new Date(now());
				const [seat] = await tx`select 1 from licence_sites where licence_id = ${row.id} and site_hash = ${hashed}`;
				let released = false;
				if (seat) {
					const recent = await tx`
						select released_at from licence_site_releases
						where licence_id = ${row.id} and released_at > ${new Date(now() - RELEASE_WINDOW_MS)}
						order by released_at`;
					if (recent.length >= releasesMax(row)) {
						const retryAt = new Date(recent[recent.length - releasesMax(row)].released_at.getTime() + RELEASE_WINDOW_MS);
						const seconds = Math.max(1, Math.ceil((retryAt.getTime() - now()) / 1000));
						// R5 : sur une illimitée seulement, une fois par fenêtre de 30 jours.
						if (row.sites_max >= UNLIMITED_SITES) {
							flagged = await flagLicence(tx, row.id, 'releases', Math.floor(now() / RELEASE_WINDOW_MS), { releases: recent.length });
						}
						return [429, { code: 'release_limit', retry_at: retryAt.toISOString() }, { 'Retry-After': String(seconds) }];
					}
					await tx`delete from licence_sites where licence_id = ${row.id} and site_hash = ${hashed}`;
					await tx`insert into licence_site_releases (licence_id, released_at) values (${row.id}, ${at})`;
					released = true;
				}
				const dev = await tx`delete from licence_dev_sites where licence_id = ${row.id} and site_hash = ${hashed} returning 1`;
				const [{ used }] = await tx`select count(*)::int as used from licence_sites where licence_id = ${row.id}`;
				return [200, { released: released || dev.length > 0, sites_used: used, ...seatsMax(row.sites_max) }];
			});
			await alertFlag(flagged);
			return result;
		},

		async createReport(req) {
			const limit = reportLimiter.hit(clientIp(req));
			if (!limit.allowed) {
				return [429, { code: 'rate_limited' }, { 'Retry-After': String(limit.retryAfterSeconds) }];
			}
			const body = await readJson(req, LIMITS.reportBody);
			rejectUnknown(body, ['type', 'what', 'expected', 'locale', 'reply_email', 'diagnostics', 'site', 'screenshot']);
			const type = text(body, 'type', { max: 10, pattern: /^(bug|idea|question)$/ });
			const what = text(body, 'what', { min: 1, max: LIMITS.longText });
			const expected = text(body, 'expected', { max: LIMITS.longText });
			const locale = text(body, 'locale', { max: 5, pattern: LOCALE });
			const replyEmail = text(body, 'reply_email', { max: 254, pattern: EMAIL, optional: true });
			const site = text(body, 'site', { min: 64, max: 64, pattern: SITE, optional: true });
			const diagnostics = parseDiagnostics(body.diagnostics);
			let screenshot = parseScreenshot(body.screenshot);
			const global = reportGlobal.hit('*');
			if (!global.allowed) {
				return [429, { code: 'rate_limited' }, { 'Retry-After': String(global.retryAfterSeconds) }];
			}
			if (screenshot) {
				if (now() >= screenshotBudget.resetAt) {
					screenshotBudget.bytes = 0;
					screenshotBudget.resetAt = now() + 86_400_000;
				}
				if (screenshotBudget.bytes + screenshot.bytes.length > SCREENSHOT_BYTES_PER_DAY) {
					// Budget du jour épuisé : le texte passe, la capture tombe.
					screenshot = null;
				} else {
					screenshotBudget.bytes += screenshot.bytes.length;
				}
			}
			const dropped = Boolean(body.screenshot) && !screenshot;
			const [row] = await sql`
				insert into reports (type, what, expected, locale, reply_email, diagnostics, site_hash, screenshot, screenshot_type)
				values (${type}, ${what}, ${expected}, ${locale}, ${replyEmail}, ${diagnostics === null ? null : sql.json(diagnostics)},
					${site ? siteHash(site) : null}, ${screenshot?.bytes ?? null}, ${screenshot?.type ?? null})
				returning id`;
			notify({ id: row.id, type, what, expected, locale, reply_email: replyEmail, diagnostics, has_screenshot: Boolean(screenshot) })
				.catch((error) => log('warn', 'notification_echec', { report_id: row.id, error: error.code ?? error.name }));
			return [201, dropped ? { id: row.id, screenshot_dropped: true } : { id: row.id }];
		},

		// Stripe réessaie pendant trois jours tout ce qui ne reçoit pas un 2xx : une erreur de
		// traitement laisse l'événement non traité (processed_at nul) pour le prochain essai.
		async stripeWebhook(req) {
			if (!stripe) {
				return [503, { code: 'payments_unavailable', message: 'Paiements Stripe non configurés' }];
			}
			const raw = await readRaw(req, LIMITS.webhookBody);
			if (!verifyStripeSignature(raw, req.headers['stripe-signature'], config.stripe.webhookSecret, Math.floor(now() / 1000))) {
				throw new HttpError(400, 'signature_invalid', 'Signature Stripe-Signature invalide ou trop ancienne');
			}
			let event;
			try {
				event = JSON.parse(raw.toString('utf8'));
			} catch {
				throw new HttpError(400, 'invalid_json', 'Corps JSON illisible');
			}
			const object = event?.data?.object;
			if (typeof event?.id !== 'string' || !STRIPE_ID.test(event.id) || typeof event.type !== 'string'
				|| object === null || typeof object !== 'object') {
				throw new HttpError(400, 'invalid_event', 'Événement Stripe incomplet');
			}
			// Bail : une seule livraison traite l'événement à la fois. Une livraison concurrente
			// attend la validation de l'insertion, trouve le bail pris et reçoit 409.
			const at = new Date(now());
			const lease = new Date(at.getTime() + EVENT_LEASE_MS);
			const [leased] = await sql`
				insert into stripe_events (id, type, payload, processing_until)
				values (${event.id}, ${event.type.slice(0, 100)}, ${sql.json({ object: typeof object.id === 'string' ? object.id : null, ...(event.type === 'charge.refunded' && typeof object.payment_intent === 'string' ? { payment_intent: object.payment_intent } : {}) })},
					${lease})
				on conflict (id) do update set processing_until = excluded.processing_until
					where stripe_events.processed_at is null
						and (stripe_events.processing_until is null or stripe_events.processing_until <= ${at})
				returning id`;
			if (!leased) {
				const [existing] = await sql`select processed_at from stripe_events where id = ${event.id}`;
				if (existing?.processed_at) {
					return [200, { received: true, duplicate: true }];
				}
				return [409, { code: 'event_in_progress', message: 'Événement en cours de traitement' }];
			}
			try {
				await handleStripeEvent(event);
			} catch (error) {
				// Libère le bail pour que le nouvel essai de Stripe reprenne tout de suite, sauf si
				// une autre livraison l'a repris entre-temps (bail échu) : ce n'est plus le nôtre.
				await sql`update stripe_events set processing_until = null where id = ${event.id} and processing_until = ${lease}`.catch(() => {});
				throw error;
			}
			await sql`update stripe_events set processed_at = ${new Date(now())}, processing_until = null where id = ${event.id}`;
			return [200, { received: true }];
		},

		// Session Checkout pour un pack. Le courriel et les métadonnées viennent de la licence,
		// jamais du formulaire : le webhook sait à qui rattacher le paiement.
		async packCheckout(req) {
			const limited = packRateLimited(req);
			if (limited) {
				return limited;
			}
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['pack', 'locale']);
			const slug = text(body, 'pack', { max: 64, pattern: SLUG });
			const locale = text(body, 'locale', { max: 5, pattern: LOCALE });
			const owner = await packLicence(licenceFrom(req, {}));
			if (!stripe) {
				throw new HttpError(503, 'payments_unavailable', 'La vente de packs n\'est pas encore ouverte');
			}
			if (!owner.active) {
				throw new HttpError(403, 'licence_expired', 'Licence expirée : renouvelle-la avant d\'acheter un pack');
			}
			if (!owner.email) {
				throw new HttpError(409, 'email_missing', 'Aucune adresse courriel sur cette licence : écris à genevieve@fluffless.dev');
			}
			const [pack] = await sql`
				select p.id, p.slug, p.stripe_price_id, p.claim_opens_at, p.claim_closes_at, c.id as claim_id
				from packs p left join pack_claims c on c.pack_id = p.id and c.licence_id = ${owner.id}
				where p.slug = ${slug}`;
			if (!pack?.stripe_price_id) {
				throw new HttpError(404, 'not_for_sale', `Le pack ${slug} n'est pas en vente`);
			}
			if (pack.claim_id) {
				throw new HttpError(409, 'already_owned', 'Tu as déjà ce pack : redemande son lien sur la page du catalogue');
			}
			// La licence active le réclame gratuitement pendant la fenêtre : jamais le vendre.
			if (windowOpen(pack, new Date(now()))) {
				throw new HttpError(409, 'claim_open', 'Ce pack est gratuit pour toi en ce moment : réclame-le sur la page du catalogue');
			}
			// Une fenêtre gratuite qui s'ouvre bientôt borne la session : elle ne se paie jamais
			// pendant la fenêtre. Stripe exige 30 minutes au moins; plus proche, on refuse.
			let expiresAt = Math.floor(now() / 1000) + CHECKOUT_TTL_SECONDS;
			if (pack.claim_opens_at && pack.claim_opens_at.getTime() > now()) {
				const opens = Math.floor(pack.claim_opens_at.getTime() / 1000);
				if (opens < Math.floor(now() / 1000) + CHECKOUT_MIN_TTL_SECONDS) {
					throw new HttpError(409, 'claim_open', 'Ce pack devient gratuit pour toi dans moins de 30 minutes : réclame-le à ce moment sur la page du catalogue');
				}
				expiresAt = Math.min(expiresAt, opens);
			}
			const lock = checkoutLockout.hit(String(owner.id));
			if (!lock.allowed) {
				return [429, { code: 'rate_limited' }, { 'Retry-After': String(lock.retryAfterSeconds) }];
			}
			const managed = config.stripe.managedPayments;
			const metadata = { kind: 'pack', licence_id: String(owner.id), pack_id: String(pack.id), pack_slug: pack.slug, locale, ...(managed ? { sale: 'managed' } : {}) };
			const en = locale.startsWith('en');
			let session;
			try {
				session = await stripe.createCheckoutSession({
					mode: 'payment',
					line_items: [{ price: pack.stripe_price_id, quantity: 1 }],
					client_reference_id: String(owner.id),
					metadata,
					payment_intent_data: { metadata },
					// Managed Payments : Stripe (Link, LLC) vend et gère les taxes, automatic_tax est
					// interdit. Sinon, Stripe Tax perçoit la TPS, la TVQ ou la TVH selon l'adresse.
					// Dans les deux cas, l'adresse de facturation décide du pays.
					...(managed ? { managed_payments: { enabled: true } } : { automatic_tax: { enabled: true } }),
					billing_address_collection: 'required',
					custom_text: { submit: { message: saleNotice(managed, en) } },
					locale: en ? 'en' : 'fr-CA',
					expires_at: expiresAt,
					success_url: (en && config.stripe.successUrlEn) || config.stripe.successUrl,
					cancel_url: (en && config.stripe.cancelUrlEn) || config.stripe.cancelUrl,
				});
			} catch (error) {
				log('error', 'checkout_echec', { error: error.code ?? error.name });
				throw new HttpError(502, 'payment_provider_error', 'Stripe ne répond pas : réessaie dans un moment');
			}
			// Deux onglets : la session enregistrée en dernier reste, les autres expirent. Sous le
			// verrou, on revérifie : une réclamation gratuite pendant l'appel à Stripe n'a pas pu
			// voir cette session, elle expire donc ici avec les autres.
			let blocked = null;
			let stale;
			try {
				stale = await sql.begin(async (tx) => {
					await tx`select id from licences where id = ${owner.id} for update`;
					const [current] = await tx`
						select p.claim_opens_at, p.claim_closes_at, c.id as claim_id
						from packs p left join pack_claims c on c.pack_id = p.id and c.licence_id = ${owner.id}
						where p.id = ${pack.id}
						for share of p`;
					// Fenêtre posée pendant l'appel à Stripe : ouverte, ou qui ouvrira avant la fin de la session.
					const opensBeforeExpiry = current?.claim_opens_at && current.claim_opens_at.getTime() > now()
						&& current.claim_opens_at.getTime() < expiresAt * 1000;
					if (!current || current.claim_id || windowOpen(current, new Date(now())) || opensBeforeExpiry) {
						blocked = !current || current.claim_id ? 'already_owned' : 'claim_open';
						return [...await takeOpenCheckouts(tx, owner.id, pack.id), { checkout_session_id: session.id }];
					}
					await tx`
						insert into pack_checkouts (checkout_session_id, licence_id, pack_id, created_at)
						values (${session.id}, ${owner.id}, ${pack.id}, ${new Date(now())})`;
					return takeOpenCheckouts(tx, owner.id, pack.id, session.id);
				});
			} catch (error) {
				// La session existe chez Stripe sans être suivie ici : on la ferme avant de répondre.
				await expireCheckouts([{ checkout_session_id: session.id }]);
				throw error;
			}
			await expireCheckouts(stale);
			if (blocked) {
				throw new HttpError(409, blocked, blocked === 'already_owned'
					? 'Tu as déjà ce pack : redemande son lien sur la page du catalogue'
					: 'Ce pack est gratuit pour toi en ce moment : réclame-le sur la page du catalogue');
			}
			log('info', 'checkout_cree', { pack: pack.slug });
			return [201, { url: session.url }];
		},

		// Associe un prix Stripe au pack (ou le retire de la vente avec null). Le prix est lu
		// chez Stripe : un identifiant erroné ou un abonnement est refusé ici, pas au paiement.
		async adminSetPackPrice(req, url, [slug]) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['price_id']);
			if (!('price_id' in body)) {
				throw new HttpError(400, 'invalid_field', 'price_id requis (identifiant price_… ou null)');
			}
			const priceId = text(body, 'price_id', { max: 255, pattern: /^price_[A-Za-z0-9]{3,250}$/, optional: true });
			const { amount, currency } = priceId ? await readSalePrice(priceId) : { amount: null, currency: null };
			const [row] = await sql`
				update packs set stripe_price_id = ${priceId}, price_amount = ${amount}, price_currency = ${currency}
				where slug = ${slug} returning slug, stripe_price_id, price_amount, price_currency`;
			return row ? [200, row] : [404, { code: 'not_found' }];
		},

		// Session Checkout pour une licence, sans compte : la cliente saisit son courriel chez
		// Stripe, et la licence naît au règlement du paiement (settleLicencePurchase). Aucun code
		// de rabais ici : un rabais passe seulement par un lien signé par l'administration
		// (adminLicenceLink, décision 4).
		async licenceCheckout(req) {
			const limited = packRateLimited(req);
			if (limited) {
				return limited;
			}
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['offer', 'locale', 'website']);
			const slug = text(body, 'offer', { max: 64, pattern: SLUG });
			const locale = text(body, 'locale', { max: 5, pattern: LOCALE });
			const honeypot = text(body, 'website', { max: 200, optional: true });
			// Pot de miel du formulaire de fluffless.dev : un robot qui le remplit croit avoir réussi.
			if (honeypot) {
				log('info', 'pot_de_miel', {});
				return [201, { url: config.stripe.cancelUrl || config.publicUrl }];
			}
			const offer = await saleableOffer(slug);
			const { limited: busy, session } = await createLicenceSession({ offer, locale });
			if (busy) {
				return busy;
			}
			log('info', 'checkout_licence_cree', { offer: offer.slug });
			return [201, { url: session.url }];
		},

		// Lien de paiement à tarif réduit, signé à la main par l'administration après vérification
		// de l'organisme sans but lucratif : coupon obligatoire, note sur l'organisme gardée dans la licence,
		// session valable jusqu'à 24 h pour tenir dans un échange par courriel.
		async adminLicenceLink(req) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['offer', 'locale', 'coupon', 'hours', 'email', 'note']);
			const slug = text(body, 'offer', { max: 64, pattern: SLUG });
			const locale = text(body, 'locale', { max: 5, pattern: LOCALE });
			const couponInput = text(body, 'coupon', { max: 64 });
			const hours = body.hours === undefined ? LINK_HOURS_MAX : integer(body, 'hours', { min: 1, max: LINK_HOURS_MAX });
			const email = text(body, 'email', { max: 254, pattern: EMAIL, optional: true });
			const note = text(body, 'note', { min: 3, max: 120 });
			const offer = await saleableOffer(slug);
			const coupon = await usableCoupon(couponInput);
			if (!coupon) {
				throw new HttpError(422, 'coupon_invalid', 'Ce code de rabais n\'est pas valide');
			}
			const expiresAt = Math.floor(now() / 1000) + hours * 3600;
			const { limited, session } = await createLicenceSession({ offer, locale, coupon, email, note, expiresAt });
			if (limited) {
				return limited;
			}
			// Ni courriel, ni note, ni adresse de la session (elle vaut paiement) dans le journal.
			log('info', 'lien_licence_admin', { offer: offer.slug, coupon: coupon.code, hours });
			return [201, {
				url: session.url,
				expires_at: new Date(expiresAt * 1000).toISOString(),
				offer: offer.slug,
				coupon: coupon.code,
				percent_off: coupon.percent_off,
			}];
		},

		// Page où la cliente colle sa clé pour télécharger l'extension. La clé voyage dans le
		// corps du POST, jamais dans l'adresse : elle ne finit pas dans les journaux. Une clé
		// valide renvoie (303) vers le lien signé de 15 minutes de /v1/update : seul ce lien prend
		// une place de téléchargement, jamais un corps de formulaire encore en lecture.
		// État d'un achat de licence pour la page de succès de fluffless.dev, avec l'adresse
		// d'envoi masquée : une faute de frappe saisie chez Stripe se voit tout de suite. Une
		// session inconnue, pas encore réglée ou de plus de 24 h répond pareil (unknown).
		async licencePurchase(req, url) {
			const limited = packRateLimited(req);
			if (limited) {
				return limited;
			}
			const q = Object.fromEntries(url.searchParams);
			rejectUnknown(q, ['session']);
			const session = text(q, 'session', { max: 210, pattern: CHECKOUT_SESSION });
			const noStore = { 'Cache-Control': 'no-store' };
			const [row] = await sql`
				select p.outcome, p.refund_reason, p.delivered_at, l.email
				from licence_purchases p
				left join licences l on l.id = p.licence_id
				where p.checkout_session_id = ${session} and p.created_at > ${new Date(now() - LICENCE_STATUS_TTL_MS)}`;
			if (!row) {
				return [200, { status: 'unknown' }, noStore];
			}
			if (row.outcome !== 'granted') {
				return [200, { status: 'refunded', reason: row.refund_reason }, noStore];
			}
			return [200, {
				status: row.delivered_at ? 'sent' : 'sending',
				email: row.email ? maskEmail(row.email) : null,
			}, noStore];
		},

		// Lien du courriel de rappel et de la page d'expiration : ouvre un paiement de
		// renouvellement pour cette licence, sans qu'elle ait à ressaisir sa clé. Le jeton lie le
		// lien à la fin de période annoncée.
		async licenceRenew(req, url) {
			try {
				const result = await handlers.renewCheckout_(req, url);
				if (result[0] === 429) {
					return renewHtml(429, 'renewLimited', { 'Retry-After': result[2]['Retry-After'] });
				}
				return result;
			} catch (error) {
				// Lien cliqué depuis un courriel : une page lisible, pas du JSON.
				if (!(error instanceof HttpError)) {
					throw error;
				}
				return renewHtml(error.status, error.status === 403 || error.status === 400 ? 'renewInvalid' : 'renewUnavailable');
			}
		},

		async renewCheckout_(req, url) {
			const limited = packRateLimited(req);
			if (limited) {
				return limited;
			}
			const q = Object.fromEntries(url.searchParams);
			rejectUnknown(q, ['licence', 'until', 'token']);
			const licenceId = text(q, 'licence', { max: 18, pattern: DB_ID });
			const until = text(q, 'until', { max: 11, pattern: /^\d{1,11}$/ });
			const token = text(q, 'token', { max: 64, pattern: /^[0-9a-f]{64}$/ });
			if (!safeEqual(token, renewToken(licenceId, until))) {
				throw new HttpError(403, 'link_invalid', 'Lien de renouvellement invalide');
			}
			if (!stripe) {
				throw new HttpError(503, 'payments_unavailable', 'Le renouvellement n\'est pas encore ouvert');
			}
			const [row] = await sql`
				select l.expires_at, l.revoked_at, l.restored_at, l.renewal_price_id, l.renewal_product_id, l.renewal_unit_amount,
					o.id as offer_id, o.slug as offer_slug, o.stripe_price_id, o.price_amount, o.stripe_product_id, o.duration_days,
					p.locale, c.stripe_coupon_id
				from licences l
				join licence_purchases p on p.licence_id = l.id
				join licence_offers o on o.id = p.offer_id
				left join licence_coupons c on c.code = p.coupon_code
				where l.id = ${licenceId}`;
			// Une licence prolongée entre-temps n'a plus la fin annoncée : le lien ne sert qu'une fois.
			if (!row || row.revoked_at || !row.expires_at || String(Math.floor(row.expires_at.getTime() / 1000)) !== until) {
				throw new HttpError(403, 'link_invalid', 'Lien de renouvellement invalide ou déjà utilisé');
			}
			// Décision 32 : jusqu'à 30 jours après la fin (ou le dernier rétablissement), le prix de
			// l'achat, même si l'offre a changé de prix ou n'est plus en vente; ensuite le prix du jour.
			const frozen = row.renewal_price_id && now() <= graceUntil(row);
			if (!row.duration_days || (!frozen && !row.stripe_price_id)) {
				throw new HttpError(404, 'not_for_sale', 'Cette licence ne se renouvelle pas en ligne');
			}
			const global = licenceRenewGlobal.hit('*');
			if (!global.allowed) {
				return [429, { code: 'rate_limited' }, { 'Retry-After': String(global.retryAfterSeconds) }];
			}
			const priced = frozen
				? { price_id: row.renewal_price_id, unit_amount: row.renewal_unit_amount, product_id: row.renewal_product_id }
				: { price_id: row.stripe_price_id, unit_amount: row.price_amount, product_id: row.stripe_product_id };
			let lineItem = { price: priced.price_id, quantity: 1 };
			if (frozen) {
				let price = null;
				try {
					price = await stripe.retrievePrice(priced.price_id);
				} catch (error) {
					if (error.status !== 404) {
						log('error', 'checkout_echec', { error: error.code ?? error.name });
						throw new HttpError(502, 'payment_provider_error', 'Stripe ne répond pas : réessaie dans un moment');
					}
				}
				// Prix archivé, effacé ou changé chez Stripe : même montant, taxes en sus, sur le même produit.
				if (!price?.active || price.unit_amount !== priced.unit_amount || price.currency !== 'cad') {
					lineItem = {
						price_data: {
							currency: 'cad',
							...(priced.product_id ? { product: priced.product_id } : { product_data: { name: `Licence Fluffless (${row.offer_slug})` } }),
							unit_amount: priced.unit_amount,
							tax_behavior: 'exclusive',
						},
						quantity: 1,
					};
					log('warn', 'prix_gele_repli', {});
					await sendAlert('Prix gelé archivé chez Stripe, repli appliqué', [
						`Licence : ${licenceId}`,
						`Le prix ${priced.price_id} n'est plus actif ou a changé chez Stripe : la session porte le même montant (${priced.unit_amount} cents, taxes en sus) en price_data.`,
						...(priced.product_id ? [] : ['Aucun produit Stripe connu : repasser offer-price sur l\'offre pour garder le code fiscal du produit.']),
					]);
				}
			}
			const managed = config.stripe.managedPayments;
			const locale = LOCALE.test(row.locale ?? '') ? row.locale : 'fr_CA';
			const metadata = {
				kind: 'licence_renewal', licence_id: licenceId, offer_id: String(row.offer_id), until, locale,
				price_basis: frozen ? 'gele' : 'jour', ...priceMetadata(priced),
				...(managed ? { sale: 'managed' } : {}),
			};
			const en = locale.startsWith('en');
			const success = new URL((en && config.stripe.successUrlEn) || config.stripe.successUrl);
			success.search = success.search ? `${success.search}&kind=renewal` : '?kind=renewal';
			// Droit acquis : le coupon de l'achat suit à chaque renouvellement, même éteint, expiré ou
			// épuisé depuis (le rabais d'un organisme reconnu vaut à chaque renouvellement). Seul Stripe peut
			// encore le refuser, d'où un coupon créé sans plafond ni date limite pour cet usage.
			const create = (discount) => stripe.createCheckoutSession({
				mode: 'payment',
				line_items: [lineItem],
				...(discount ? { discounts: [{ coupon: discount }] } : {}),
				metadata,
				payment_intent_data: { metadata },
				...(managed ? { managed_payments: { enabled: true } } : { automatic_tax: { enabled: true } }),
				billing_address_collection: 'required',
				consent_collection: { terms_of_service: 'required' },
				custom_text: { submit: { message: saleNotice(managed, en) } },
				locale: en ? 'en' : 'fr-CA',
				expires_at: Math.floor(now() / 1000) + CHECKOUT_TTL_SECONDS,
				success_url: success.href,
				cancel_url: (en && config.stripe.cancelUrlEn) || config.stripe.cancelUrl,
			});
			let session;
			try {
				try {
					session = await create(row.stripe_coupon_id);
				} catch (error) {
					// Stripe a refusé le coupon (échu ou épuisé chez lui) : mieux vaut un paiement au plein prix,
					// affiché tel quel dans Checkout, qu'un renouvellement impossible. L'alerte dit de corriger le coupon.
					if (!row.stripe_coupon_id || error.status !== 400) {
						throw error;
					}
					log('warn', 'renouvellement_coupon_refuse', { error: error.code ?? error.name });
					await sendAlert('Renouvellement : coupon refusé par Stripe, session au plein prix', [
						`Licence : ${licenceId}`,
						'Le coupon de l\'achat est échu ou épuisé chez Stripe. Pour un rabais qui doit durer, créer le code sans --max ni --until.',
					]);
					session = await create(null);
				}
			} catch (error) {
				log('error', 'checkout_echec', { error: error.code ?? error.name });
				throw new HttpError(502, 'payment_provider_error', 'Stripe ne répond pas : réessaie dans un moment');
			}
			log('info', 'checkout_renouvellement_cree', {});
			return [303, Buffer.alloc(0), { Location: session.url, 'Cache-Control': 'no-store' }];
		},

		async licenceDownloadPage() {
			return licenceDownloadHtml(200, 'form');
		},

		async licenceDownload(req) {
			const raw = await readRaw(req, LICENCE_FORM_BYTES, 'application/x-www-form-urlencoded');
			const key = new URLSearchParams(raw.toString('utf8')).get('key') ?? '';
			if (key.trim() === '' || key.length > LICENCE_MAX_CHARS) {
				return licenceDownloadHtml(400, 'invalid');
			}
			const [licence] = await sql`
				select id, expires_at, restored_at, renewal_price_id, release_slugs from licences
				where key_hash = ${keyHash(key)} and revoked_at is null`;
			if (!licence) {
				return licenceDownloadHtml(403, 'invalid');
			}
			if (licence.expires_at && licence.expires_at.getTime() <= now()) {
				// Pendant la grâce, le prix gelé suffit, même si l'offre n'est plus en vente.
				const grace = licence.renewal_price_id && now() <= graceUntil(licence) ? new Date(graceUntil(licence)) : null;
				const [renewable] = await sql`
					select 1 from licence_purchases p join licence_offers o on o.id = p.offer_id
					where p.licence_id = ${licence.id} and o.duration_days is not null
						and (${grace !== null} or o.stripe_price_id is not null)`;
				const until = Math.floor(licence.expires_at.getTime() / 1000);
				const renew = renewable && stripe ? renewUrl(licence.id, until) : null;
				return licenceDownloadHtml(403, 'expired', [], {
					date: { fr: longDay(licence.expires_at, 'fr_CA'), en: longDay(licence.expires_at, 'en_CA') },
					renew,
					grace: renew && grace ? { fr: longDay(grace, 'fr_CA'), en: longDay(grace, 'en_CA') } : null,
				});
			}
			// La Suite d'abord : les autres extensions s'installent par-dessus.
			const slugs = [...(licence.release_slugs ?? [DEFAULT_RELEASE_SLUG])]
				.sort((a, b) => (b === DEFAULT_RELEASE_SLUG) - (a === DEFAULT_RELEASE_SLUG));
			const expires = Math.floor(now() / 1000) + DOWNLOAD_TTL_SECONDS;
			const links = [];
			for (const slug of slugs) {
				const latest = await latestRelease(slug, ['stable'], ['name', 'version']);
				if (latest) {
					const token = downloadToken(slug, latest.version, expires);
					links.push({
						name: latest.name || slug,
						version: latest.version,
						url: `${config.publicUrl}/v1/download/${slug}/${latest.version}/${expires}/${token}.zip`,
					});
				}
			}
			if (links.length === 0) {
				return licenceDownloadHtml(503, 'unavailable');
			}
			// Une seule extension : renvoi direct au zip, comme avant. Plusieurs : une page de liens.
			if (slugs.length === 1) {
				return [303, Buffer.alloc(0), { Location: links[0].url, 'Cache-Control': 'no-store' }];
			}
			return licenceDownloadHtml(200, links.length < slugs.length ? 'partial' : 'links', links);
		},

		// Crée l'offre, ou change son plan, ses sites, sa durée ou l'extension livrée. Le prix
		// se pose à part (adminSetOfferPrice) : une offre sans prix n'est pas en vente.
		async adminUpsertLicenceOffer(req) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['slug', 'plan', 'sites_max', 'duration_days', 'release_slugs']);
			const slug = text(body, 'slug', { max: 64, pattern: SLUG });
			const plan = text(body, 'plan', { min: 1, max: 40, pattern: /^[a-z0-9_-]+$/ });
			const sitesMax = integer(body, 'sites_max', { min: 1, max: 10_000 });
			const durationDays = body.duration_days === null || body.duration_days === undefined
				? null
				: integer(body, 'duration_days', { min: 1, max: 3650 });
			// Sans liste : la Suite pour une nouvelle offre, et la liste en place pour une offre
			// modifiée. Changer les sites d'une offre Suite + SEO ne lui retire pas la SEO.
			const releaseSlugs = releaseList(body, 'release_slugs') ?? null;
			const [row] = await sql`
				insert into licence_offers (slug, plan, sites_max, duration_days, release_slugs)
				values (${slug}, ${plan}, ${sitesMax}, ${durationDays}, ${releaseSlugs ?? [DEFAULT_RELEASE_SLUG]})
				on conflict (slug) do update set plan = excluded.plan, sites_max = excluded.sites_max,
					duration_days = excluded.duration_days,
					release_slugs = coalesce(${releaseSlugs}::text[], licence_offers.release_slugs)
				returning slug, plan, sites_max, duration_days, release_slugs, stripe_price_id, price_amount, price_currency`;
			return [200, row];
		},

		async adminListLicenceOffers(req) {
			requireAdmin(req);
			const rows = await sql`
				select o.slug, o.plan, o.sites_max, o.duration_days, o.release_slugs, o.stripe_price_id, o.price_amount,
					o.price_currency, count(p.checkout_session_id) filter (where p.outcome = 'granted')::int as sold
				from licence_offers o left join licence_purchases p on p.offer_id = o.id
				group by o.id order by o.slug`;
			return [200, { offers: rows }];
		},

		// Crée un coupon : le taux se pose ici, jamais dans le code. Le coupon Stripe naît d'abord
		// (clé d'idempotence tirée du code : un nouvel essai ne le duplique pas), la ligne ensuite.
		async adminCreateLicenceCoupon(req) {
			requireAdmin(req);
			if (!stripe) {
				throw new HttpError(503, 'payments_unavailable', 'Paiements Stripe non configurés');
			}
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['code', 'percent_off', 'label', 'max_redemptions', 'expires_at']);
			const code = text({ code: String(body.code ?? '').trim().toUpperCase() }, 'code', { min: 3, max: 40, pattern: COUPON_CODE });
			const percentOff = integer(body, 'percent_off', { min: 1, max: 99 });
			const label = text(body, 'label', { max: 80, optional: true }) ?? '';
			const maxRedemptions = body.max_redemptions === undefined || body.max_redemptions === null
				? null
				: integer(body, 'max_redemptions', { min: 1, max: 100_000 });
			let expiresAt = null;
			if (body.expires_at !== undefined && body.expires_at !== null) {
				expiresAt = new Date(text(body, 'expires_at', { max: 40 }));
				if (Number.isNaN(expiresAt.getTime()) || expiresAt.getTime() <= now()) {
					throw new HttpError(400, 'invalid_field', 'expires_at doit être une date future (ISO 8601)');
				}
			}
			const [taken] = await sql`select 1 from licence_coupons where code = ${code}`;
			if (taken) {
				throw new HttpError(409, 'coupon_exists', `Le coupon ${code} existe déjà`);
			}
			let created;
			try {
				created = await stripe.createCoupon({
					percent_off: percentOff,
					duration: 'once',
					name: label || code,
					...(maxRedemptions ? { max_redemptions: maxRedemptions } : {}),
					...(expiresAt ? { redeem_by: Math.floor(expiresAt.getTime() / 1000) } : {}),
					metadata: { code },
				}, `coupon-${code}-${percentOff}-${maxRedemptions ?? 0}-${expiresAt ? expiresAt.getTime() : 0}`);
			} catch (error) {
				log('error', 'coupon_echec', { error: error.code ?? error.name });
				throw new HttpError(502, 'payment_provider_error', 'Stripe ne répond pas : réessaie dans un moment');
			}
			const [row] = await sql`
				insert into licence_coupons (code, label, percent_off, stripe_coupon_id, max_redemptions, expires_at)
				values (${code}, ${label}, ${percentOff}, ${created.id}, ${maxRedemptions}, ${expiresAt})
				returning code, label, percent_off, max_redemptions, expires_at, active`;
			return [201, row];
		},

		async adminListLicenceCoupons(req) {
			requireAdmin(req);
			const rows = await sql`
				select c.code, c.label, c.percent_off, c.max_redemptions, c.expires_at, c.active,
					count(p.checkout_session_id)::int as redeemed, coalesce(sum(p.discount_amount), 0)::int as discount_total
				from licence_coupons c left join licence_purchases p on p.coupon_code = c.code
				group by c.code order by c.created_at, c.code`;
			return [200, { coupons: rows }];
		},

		// Éteint un coupon : les achats déjà faits le gardent, aucun nouveau ne l'applique.
		async adminDisableLicenceCoupon(req, url, [code]) {
			requireAdmin(req);
			const [row] = await sql`update licence_coupons set active = false where code = ${code} returning code, active`;
			return row ? [200, row] : [404, { code: 'not_found' }];
		},

		async adminSetOfferPrice(req, url, [slug]) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['price_id']);
			if (!('price_id' in body)) {
				throw new HttpError(400, 'invalid_field', 'price_id requis (identifiant price_… ou null)');
			}
			const priceId = text(body, 'price_id', { max: 255, pattern: /^price_[A-Za-z0-9]{3,250}$/, optional: true });
			const { amount, currency, product } = priceId ? await readSalePrice(priceId) : { amount: null, currency: null, product: null };
			// Les licences déjà vendues gardent leur prix gelé : seul le prix des nouvelles ventes change.
			const [row] = await sql`
				update licence_offers set stripe_price_id = ${priceId}, price_amount = ${amount}, price_currency = ${currency},
					stripe_product_id = ${product}
				where slug = ${slug} returning slug, stripe_price_id, price_amount, price_currency`;
			return row ? [200, row] : [404, { code: 'not_found' }];
		},

		// Après un remboursement de renouvellement fait à la main dans Stripe : la fin revient à celle
		// d'avant le paiement (ou à maintenant si elle est passée), le prix gelé ne change pas et la grâce
		// repart de la fin rétablie. Seul le dernier renouvellement se défait : défaire un plus ancien
		// effacerait des périodes payées ensuite. Rend le lien de la fin rétablie (l'ancien est mort).
		async adminRefundRenewal(req, url, [sessionId]) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, []);
			const result = await sql.begin(async (tx) => {
				const [renewal] = await tx`
					select licence_id, period_from, outcome from licence_renewals where checkout_session_id = ${sessionId} for update`;
				if (!renewal) {
					return [404, { code: 'not_found' }];
				}
				if (renewal.outcome !== 'granted' || !renewal.licence_id || !renewal.period_from) {
					return [409, { code: 'not_granted' }];
				}
				const [licence] = await tx`
					select l.email, l.restored_at, p.locale from licences l
					left join licence_purchases p on p.licence_id = l.id
					where l.id = ${renewal.licence_id} for update of l`;
				const [later] = await tx`
					select 1 from licence_renewals
					where licence_id = ${renewal.licence_id} and outcome = 'granted' and period_from > ${renewal.period_from}`;
				if (later) {
					return [409, { code: 'not_last_renewal' }];
				}
				const expires = new Date(Math.max(renewal.period_from.getTime(), now()));
				await tx`update licences set expires_at = ${expires} where id = ${renewal.licence_id}`;
				await tx`
					update licence_renewals set outcome = 'refunded', refund_reason = 'manual', updated_at = ${new Date(now())}
					where checkout_session_id = ${sessionId}`;
				return [200, {
					licence_id: Number(renewal.licence_id),
					expires_at: expires.toISOString(),
					renew_url: renewUrl(renewal.licence_id, Math.floor(expires.getTime() / 1000)),
				}, { email: licence.email, locale: licence.locale ?? 'fr_CA', grace: graceUntil({ expires_at: expires, restored_at: licence.restored_at }) }];
			});
			if (result[0] !== 200) {
				return result;
			}
			log('info', 'renouvellement_defait', {});
			// Le nouveau lien part aussi par courriel. Un échec n'annule rien : la réponse donne le lien,
			// et mail_sent dit s'il faut l'envoyer à la main.
			const [status, answer, { email, locale, grace }] = result;
			let sent = false;
			if (typeof email === 'string' && email.length <= 254 && EMAIL.test(email)) {
				try {
					sent = await notifyRenewalRefund({ to: email, locale, expires: new Date(answer.expires_at), graceUntil: new Date(grace), renewUrl: answer.renew_url });
				} catch (error) {
					// Le message SMTP peut citer l'adresse : seul le code part au journal.
					log('warn', 'renouvellement_defait_courriel_echec', { error: error.responseCode ?? error.code ?? 'inconnue' });
				}
			}
			return [status, { ...answer, mail_sent: sent }];
		},

		// Renvoie la clé d'une licence achetée, au besoin à une adresse corrigée. La génération
		// augmente : la clé envoyée est nouvelle, l'ancienne cesse de fonctionner une fois le
		// courriel parti, et pas avant. Un renvoi ne rétablit donc jamais une clé qui a fuité.
		// Licence créée à la main : rotate.
		async adminResendLicence(req, url, [id]) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['email']);
			const email = text(body, 'email', { max: 254, pattern: EMAIL, optional: true });
			const purchase = await sql.begin(async (tx) => {
				const [licence] = await tx`select id, email, revoked_at from licences where id = ${id} for update`;
				if (!licence) {
					return null;
				}
				const [row] = await tx`
					select checkout_session_id, delivering_until from licence_purchases
					where licence_id = ${id} and outcome = 'granted' for update`;
				if (!row) {
					throw new HttpError(409, 'not_purchased', 'Licence sans achat Stripe : rotate donne une nouvelle clé');
				}
				if (licence.revoked_at) {
					throw new HttpError(409, 'licence_revoked', 'Licence révoquée : rien à renvoyer');
				}
				if (row.delivering_until && row.delivering_until.getTime() > now()) {
					throw new HttpError(409, 'delivery_in_progress', 'Un envoi de cette clé est en cours : réessaie dans quelques minutes');
				}
				if (!email && !licence.email) {
					throw new HttpError(409, 'email_missing', 'Aucune adresse sur cette licence : précise email');
				}
				if (email) {
					await tx`update licences set email = ${email} where id = ${id}`;
				}
				await tx`
					update licence_purchases
					set key_generation = key_generation + 1, delivered_at = null, delivery_attempts = 0, updated_at = ${new Date(now())}
					where checkout_session_id = ${row.checkout_session_id}`;
				return row;
			});
			if (!purchase) {
				return [404, { code: 'not_found' }];
			}
			const result = await deliverLicencePurchase(purchase.checkout_session_id);
			if (result === 'skipped') {
				// La relivraison a pris le bail entre-temps : l'envoi de la nouvelle clé est en cours.
				return [202, { id: Number(id), delivered: false, code: 'delivery_in_progress' }];
			}
			if (result !== 'delivered') {
				return [503, { code: 'delivery_unavailable', message: 'Le courriel n\'est pas parti : la clé actuelle reste valable et la nouvelle clé part à la relivraison, toutes les 10 minutes' }];
			}
			return [200, { id: Number(id), delivered: true }];
		},

		async adminReconcile(req) {
			requireAdmin(req);
			if (!stripe) {
				throw new HttpError(503, 'payments_unavailable', 'Paiements Stripe non configurés');
			}
			return [200, await reconcile()];
		},

		async adminCreateRelease(req) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.releaseBody);
			rejectUnknown(body, ['slug', 'version', 'channel', 'name', 'homepage', 'requires', 'requires_php', 'tested', 'description', 'changelog', 'zip_base64']);
			const slug = text(body, 'slug', { max: 64, pattern: SLUG });
			const version = text(body, 'version', { max: 64 });
			if (!parseVersion(version)) {
				throw new HttpError(400, 'invalid_field', 'version invalide');
			}
			const channel = text(body, 'channel', { max: 6, pattern: /^(stable|beta)$/ });
			const name = text(body, 'name', { min: 1, max: 200 });
			const optional = (field) => text(body, field, { max: 500, optional: true }) ?? '';
			const description = localised(body, 'description');
			const changelog = localised(body, 'changelog');
			const zip = parseZip(body);
			const sha256 = sha256Hex(zip);
			const signature = signEd25519(config.signingKey, zip);
			// Manifeste signé : lie slug, version et somme sous la même clé que le zip. Le site vérifie
			// la signature sur la chaîne exacte reçue, puis compare chaque champ à ce qu'il a téléchargé.
			const manifest = releaseManifest({ slug, version, sha256 });
			const manifestSignature = signEd25519(config.signingKey, Buffer.from(manifest, 'utf8'));
			const [row] = await sql`
				insert into releases (slug, version, channel, name, homepage, requires, requires_php, tested,
					description_fr, description_en, changelog_fr, changelog_en, zip, zip_size, sha256, signature,
					manifest, manifest_signature)
				values (${slug}, ${version}, ${channel}, ${name}, ${optional('homepage')}, ${optional('requires')},
					${optional('requires_php')}, ${optional('tested')}, ${description.fr}, ${description.en},
					${changelog.fr}, ${changelog.en}, ${zip}, ${zip.length}, ${sha256}, ${signature},
					${manifest}, ${manifestSignature})
				on conflict (slug, version) do nothing
				returning id`;
			if (!row) {
				throw new HttpError(409, 'release_exists', `${slug} ${version} existe déjà; publie un nouveau numéro de version`);
			}
			log('info', 'release_publiee', { slug, version, channel, bytes: zip.length });
			return [201, { slug, version, channel, sha256, signature, manifest, manifest_signature: manifestSignature, size: zip.length }];
		},

		async adminDeleteRelease(req, url, [slug, version]) {
			requireAdmin(req);
			const rows = await sql`delete from releases where slug = ${slug} and version = ${version} returning id`;
			return rows.length ? [204, null] : [404, { code: 'not_found' }];
		},

		async adminCreateLicence(req) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['plan', 'sites_max', 'expires', 'note', 'email', 'release_slugs']);
			const plan = text(body, 'plan', { min: 1, max: 40, pattern: /^[a-z0-9_-]+$/ });
			const sitesMax = integer(body, 'sites_max', { min: 1, max: 10_000 });
			const expires = optionalDate(body, 'expires');
			const note = text(body, 'note', { max: 200, optional: true }) ?? '';
			const email = text(body, 'email', { max: 254, pattern: EMAIL, optional: true });
			// Sans liste : la Suite seule, comme une offre. null, demandé exprès : toutes les
			// extensions, présentes et futures (mises à jour), et la Suite sur la page de téléchargement.
			const listed = releaseList(body, 'release_slugs', { all: true });
			const releaseSlugs = listed === undefined ? [DEFAULT_RELEASE_SLUG] : listed;
			const licence = newLicenceKey();
			const [row] = await sql`
				insert into licences (key_hash, plan, sites_max, expires_at, note, email, release_slugs)
				values (${keyHash(licence)}, ${plan}, ${sitesMax}, ${expires}, ${note}, ${email}, ${releaseSlugs}) returning id`;
			// La clé n'est rendue qu'ici : la base n'en garde que le HMAC.
			return [201, {
				id: Number(row.id), licence, plan, sites_max: sitesMax, expires: expires?.toISOString() ?? null, release_slugs: releaseSlugs,
			}];
		},

		// Seule adresse où partent les liens de packs : la corriger ici, jamais par le formulaire.
		async adminUpdateLicence(req, url, [id]) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['email', 'release_slugs']);
			if (!('email' in body) && !('release_slugs' in body)) {
				throw new HttpError(400, 'invalid_field', 'email (adresse ou null) ou release_slugs (liste ou null) requis');
			}
			const fields = {};
			if ('email' in body) {
				fields.email = text(body, 'email', { max: 254, pattern: EMAIL, optional: true });
			}
			// Passage de la Suite seule à Suite + SEO, ou correction : la liste remplace l'ancienne.
			if ('release_slugs' in body) {
				fields.release_slugs = releaseList(body, 'release_slugs', { all: true });
			}
			const [row] = await sql`
				update licences set ${sql(fields, ...Object.keys(fields))} where id = ${id}
				returning id, email, release_slugs`;
			return row
				? [200, { id: Number(row.id), email: row.email, release_slugs: row.release_slugs }]
				: [404, { code: 'not_found' }];
		},

		// Une licence qui a obtenu des packs ne se supprime qu'avec with_packs=1 (demande
		// d'effacement, Loi 25). Pour une clé qui a fuité : rotate, qui garde tout.
		async adminDeleteLicence(req, url, [id]) {
			requireAdmin(req);
			const withPacks = url.searchParams.get('with_packs') === '1';
			const rows = await sql.begin(async (tx) => {
				const [claimed] = await tx`select 1 from pack_claims where licence_id = ${id} limit 1`;
				if (claimed && !withPacks) {
					throw new HttpError(409, 'licence_has_packs', 'Cette licence a obtenu des packs : rotate pour changer la clé, ou with_packs=1 pour tout effacer');
				}
				await tx`delete from pack_claims where licence_id = ${id}`;
				return tx`delete from licences where id = ${id} returning id`;
			});
			return rows.length ? [204, null] : [404, { code: 'not_found' }];
		},

		// Sites d'une licence pour le soutien : l'empreinte n'apparaît que tronquée à 12 caractères,
		// assez pour désigner un site à libérer, pas pour le recouper ailleurs.
		async adminLicenceSites(req, url, [id]) {
			requireAdmin(req);
			const [licence] = await sql`select sites_max from licences where id = ${id}`;
			if (!licence) {
				return [404, { code: 'not_found' }];
			}
			const rows = await sql`
				select left(site_hash, 12) as hash, false as dev, registered_at, last_seen_at from licence_sites where licence_id = ${id}
				union all
				select left(site_hash, 12), true, null, last_seen_at from licence_dev_sites where licence_id = ${id}
				order by dev, registered_at, last_seen_at`;
			return [200, {
				sites_max: licence.sites_max,
				sites: rows.map((row) => ({
					hash: row.hash,
					dev: row.dev,
					registered_at: row.registered_at?.toISOString() ?? null,
					last_seen_at: row.last_seen_at.toISOString(),
				})),
			}];
		},

		// Libération par le soutien : hors du plafond de la cliente, qui ne compte que ses propres appels.
		async adminReleaseSites(req, url, [id, hash]) {
			requireAdmin(req);
			return sql.begin(async (tx) => {
				const [licence] = await tx`select id from licences where id = ${id} for update`;
				if (!licence) {
					return [404, { code: 'not_found' }];
				}
				const prefix = hash ? `${hash}%` : '%';
				const seats = await tx`delete from licence_sites where licence_id = ${id} and site_hash like ${prefix} returning 1`;
				const devs = await tx`delete from licence_dev_sites where licence_id = ${id} and site_hash like ${prefix} returning 1`;
				const removed = seats.length + devs.length;
				return hash && removed === 0 ? [404, { code: 'not_found' }] : [200, { removed }];
			});
		},

		async adminListLicenceFlags(req, url) {
			requireAdmin(req);
			const open = url.searchParams.get('open') === '1';
			const rows = await sql`
				select id, licence_id, rule, level, observed, created_at, reviewed_at, outcome from licence_flags
				${open ? sql`where reviewed_at is null` : sql``}
				order by created_at desc, id desc limit 500`;
			return [200, {
				flags: rows.map((row) => ({
					id: Number(row.id),
					licence_id: Number(row.licence_id),
					rule: row.rule,
					observed: row.observed,
					created_at: row.created_at.toISOString(),
					reviewed_at: row.reviewed_at?.toISOString() ?? null,
					outcome: row.outcome,
				})),
			}];
		},

		async adminReviewLicenceFlag(req, url, [id]) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['outcome']);
			const outcome = text(body, 'outcome', { max: 10, pattern: FLAG_OUTCOME });
			const [row] = await sql`
				update licence_flags set outcome = ${outcome}, reviewed_at = ${new Date(now())}
				where id = ${id} returning id, outcome, reviewed_at`;
			return row
				? [200, { id: Number(row.id), outcome: row.outcome, reviewed_at: row.reviewed_at.toISOString() }]
				: [404, { code: 'not_found' }];
		},

		// Avis écrit avant une révocation (conditions : « sans correction dans les 30 jours »). La
		// licence reste valide pendant l'avis. Le courriel à la cliente attend ses gabarits : l'avis
		// s'écrit à la main d'ici là, la Suite le montre déjà par notice_until.
		async adminLicenceNotice(req, url, [id]) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['reason', 'days']);
			const reason = text(body, 'reason', { max: 20, pattern: NOTICE_REASON });
			const days = body.days === undefined ? NOTICE_DAYS_DEFAULT : integer(body, 'days', { min: NOTICE_DAYS_MIN, max: NOTICE_DAYS_MAX });
			const at = new Date(now());
			const until = new Date(at.getTime() + days * 86_400_000);
			const [row] = await sql`
				update licences set notice_at = ${at}, notice_until = ${until}, notice_reason = ${reason}
				where id = ${id} returning id`;
			if (!row) {
				return [404, { code: 'not_found' }];
			}
			log('info', 'licence_avis', { licence: Number(row.id), reason, days });
			return [200, { id: Number(row.id), notice_at: at.toISOString(), notice_until: until.toISOString(), notice_reason: reason }];
		},

		async adminLicenceNoticeOff(req, url, [id]) {
			requireAdmin(req);
			const [row] = await sql`
				update licences set notice_at = null, notice_until = null, notice_reason = null
				where id = ${id} returning id`;
			if (!row) {
				return [404, { code: 'not_found' }];
			}
			log('info', 'licence_avis_leve', { licence: Number(row.id) });
			return [200, { id: Number(row.id), notice_until: null }];
		},

		// Révocation seulement après un avis arrivé à terme. L'avis reste en base pour l'historique.
		async adminRevokeLicence(req, url, [id]) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['reason', 'refund']);
			const reason = text(body, 'reason', { min: 3, max: 200 });
			if (body.refund !== undefined && typeof body.refund !== 'boolean') {
				throw new HttpError(400, 'invalid_field', `refund doit être un booléen (reçu : ${typeof body.refund})`);
			}
			// Après un remboursement, la clé se désactive (page de remboursement) : la cliente a
			// demandé son argent, ce n'est pas une sanction, donc aucun avis. La raison le garde.
			const refund = body.refund === true;
			return sql.begin(async (tx) => {
				const [licence] = await tx`select id, notice_until, revoked_at from licences where id = ${id} for update`;
				if (!licence) {
					return [404, { code: 'not_found' }];
				}
				if (licence.revoked_at) {
					return [409, { code: 'already_revoked' }];
				}
				if (!refund && !licence.notice_until) {
					return [409, { code: 'notice_required' }];
				}
				if (!refund && licence.notice_until.getTime() > now()) {
					return [409, { code: 'notice_running', notice_until: licence.notice_until.toISOString() }];
				}
				const at = new Date(now());
				await tx`update licences set revoked_at = ${at}, revoke_reason = ${refund ? `refund: ${reason}` : reason} where id = ${id}`;
				log('info', 'licence_revoquee', { licence: Number(licence.id) });
				return [200, { id: Number(licence.id), revoked_at: at.toISOString() }];
			});
		},

		// Rétablissement : les places n'ont pas bougé; restored_at sert au délai de grâce du renouvellement.
		async adminRestoreLicence(req, url, [id]) {
			requireAdmin(req);
			const at = new Date(now());
			const [row] = await sql`
				update licences set revoked_at = null, revoke_reason = null, notice_at = null, notice_until = null,
					notice_reason = null, restored_at = ${at}
				where id = ${id} and revoked_at is not null returning id`;
			if (!row) {
				const [exists] = await sql`select 1 from licences where id = ${id}`;
				return exists ? [409, { code: 'not_revoked' }] : [404, { code: 'not_found' }];
			}
			log('info', 'licence_retablie', { licence: Number(row.id) });
			return [200, { id: Number(row.id), restored_at: at.toISOString() }];
		},

		// Refusée tant que la clé d'un achat n'est pas livrée : la livraison écraserait la clé
		// tournée (licence-resend sert à ce cas).
		async adminRotateLicence(req, url, [id]) {
			requireAdmin(req);
			const licence = newLicenceKey();
			const [row] = await sql`
				update licences set key_hash = ${keyHash(licence)}
				where id = ${id} and not exists (
					select 1 from licence_purchases p
					where p.licence_id = licences.id and p.outcome = 'granted' and p.delivered_at is null)
				returning id`;
			if (row) {
				return [200, { id: Number(row.id), licence }];
			}
			const [exists] = await sql`select 1 from licences where id = ${id}`;
			if (exists) {
				throw new HttpError(409, 'delivery_pending', 'Clé achetée pas encore livrée : licence-resend envoie une nouvelle clé');
			}
			return [404, { code: 'not_found' }];
		},

		async listPacks(req, url) {
			const q = Object.fromEntries(url.searchParams);
			const licence = licenceFrom(req, q);
			const limited = packRateLimited(req);
			if (limited) {
				return limited;
			}
			const lang = (q.locale ?? 'fr_CA').startsWith('en') ? 'en' : 'fr';
			const owner = await packLicence(licence);
			const at = new Date(now());
			const rows = await sql`
				select p.slug, p.name_fr, p.name_en, p.description_fr, p.description_en,
					p.claim_opens_at, p.claim_closes_at, p.stripe_price_id, p.price_amount, p.price_currency,
					c.id is not null as owned
				from packs p
				left join pack_claims c on c.pack_id = p.id and c.licence_id = ${owner.id}
				where c.id is not null
					or (${owner.active} and p.claim_opens_at <= ${at} and p.claim_closes_at > ${at})
					or (${owner.active && Boolean(stripe)} and p.stripe_price_id is not null)
				order by p.slug`;
			return [200, {
				packs: rows.map((row) => ({
					slug: row.slug,
					name: row[`name_${lang}`],
					description: row[`description_${lang}`],
					owned: row.owned,
					claimable: !row.owned && owner.active && windowOpen(row, at),
					claim_closes: windowOpen(row, at) ? row.claim_closes_at.toISOString() : null,
					// Prix avant taxes, en cents; Stripe Tax ajoute les taxes au paiement.
					buyable: !row.owned && owner.active && Boolean(stripe) && row.stripe_price_id !== null && !windowOpen(row, at),
					price: row.stripe_price_id ? { amount: row.price_amount, currency: row.price_currency } : null,
				})),
			}];
		},

		// Réclame les packs demandés et envoie un lien par pack à l'adresse de la licence.
		// Un pack déjà obtenu (gratuit ou acheté) reçoit un nouveau lien, l'ancien est annulé.
		async claimPacks(req) {
			const limited = packRateLimited(req);
			if (limited) {
				return limited;
			}
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['packs', 'locale', 'website']);
			const slugs = packSlugs(body.packs);
			const locale = text(body, 'locale', { max: 5, pattern: LOCALE });
			// Pot de miel : un robot qui remplit le champ caché reçoit une réponse d'apparence normale.
			if (text(body, 'website', { max: 500, optional: true })) {
				log('info', 'pot_de_miel', { route: 'claimPacks' });
				return [202, { sent: slugs, refused: [] }];
			}
			const owner = await packLicence(licenceFrom(req, {}));
			// Compté après la vérification : des clés bidon ne remplissent pas la table.
			const lock = packClaimLockout.hit(String(owner.id));
			if (!lock.allowed) {
				return [429, { code: 'rate_limited' }, { 'Retry-After': String(lock.retryAfterSeconds) }];
			}
			if (!owner.email) {
				throw new HttpError(409, 'email_missing', 'Aucune adresse courriel sur cette licence : écris à genevieve@fluffless.dev');
			}
			const lang = locale.startsWith('en') ? 'en' : 'fr';
			const at = new Date(now());
			const expires = new Date(at.getTime() + PACK_LINK_TTL_MS);
			const sent = [];
			const refused = [];
			const links = [];
			const stale = [];
			await sql.begin(async (tx) => {
				// Une réclamation à la fois par licence : les liens annulés le restent.
				await tx`select id from licences where id = ${owner.id} for update`;
				const packs = await tx`select id, slug, name_fr, name_en, claim_opens_at, claim_closes_at from packs where slug in ${tx(slugs)}`;
				const bySlug = new Map(packs.map((pack) => [pack.slug, pack]));
				for (const slug of slugs) {
					const pack = bySlug.get(slug);
					if (!pack) {
						refused.push({ slug, code: 'unknown_pack' });
						continue;
					}
					let [claimed] = await tx`select id from pack_claims where licence_id = ${owner.id} and pack_id = ${pack.id}`;
					if (!claimed) {
						if (!owner.active || !windowOpen(pack, at)) {
							refused.push({ slug, code: owner.active ? 'claim_closed' : 'licence_expired' });
							continue;
						}
						// Premier arrivé, premier servi : si un achat a pris la ligne entre-temps,
						// la licence possède quand même le pack et reçoit son lien.
						[claimed] = await tx`
							insert into pack_claims (licence_id, pack_id, source) values (${owner.id}, ${pack.id}, 'free_claim')
							on conflict (licence_id, pack_id) do nothing returning id`;
						if (claimed) {
							// Une session d'achat ouverte avant la fenêtre ne doit plus aboutir.
							stale.push(...await takeOpenCheckouts(tx, owner.id, pack.id));
						}
						claimed ??= (await tx`select id from pack_claims where licence_id = ${owner.id} and pack_id = ${pack.id}`)[0];
					}
					links.push({ name: pack[`name_${lang}`], url: await issuePackLink(tx, claimed.id, at) });
					sent.push(slug);
				}
			});
			await expireCheckouts(stale);
			if (links.length === 0) {
				return [202, { sent, refused }];
			}
			let delivered = false;
			try {
				delivered = await deliver({ to: owner.email, locale, links, expires });
			} catch (error) {
				// Le message SMTP peut citer l'adresse : seul le code part au journal.
				log('warn', 'livraison_echec', { error: error.responseCode ?? error.code ?? 'inconnue' });
			}
			if (!delivered) {
				return [503, { code: 'delivery_unavailable', message: 'Réclamation enregistrée, mais le courriel n\'a pas pu partir : redemande le lien plus tard' }];
			}
			return [202, { sent, refused }];
		},

		// La page ne consomme pas le lien : un robot de messagerie qui suit l'URL du courriel
		// ne brûle pas l'usage unique. Seul le POST du bouton livre le zip.
		async packLinkPage(req, url, [token]) {
			const [link] = await sql`
				select 1 from pack_links l
				join pack_claims c on c.id = l.claim_id
				join licences li on li.id = c.licence_id
				where l.token_hash = ${packLinkHash(token)} and l.used_at is null
					and l.expires_at > ${new Date(now())} and li.revoked_at is null`;
			return [link ? 200 : 410, Buffer.from(packLinkHtml(Boolean(link))), {
				'Content-Type': 'text/html; charset=utf-8',
				'Content-Security-Policy': "default-src 'none'; form-action 'self'; frame-ancestors 'none'",
			}];
		},

		async packDownload(req, url, [token]) {
			const at = new Date(now());
			const [link] = await sql`
				update pack_links l set used_at = ${at}
				from pack_claims c, licences li
				where l.token_hash = ${packLinkHash(token)} and l.used_at is null and l.expires_at > ${at}
					and c.id = l.claim_id and li.id = c.licence_id and li.revoked_at is null
				returning c.pack_id`;
			if (!link) {
				throw new HttpError(410, 'link_invalid', 'Lien invalide, expiré ou déjà utilisé : redemande-le sur la page du catalogue');
			}
			const [pack] = await sql`select slug, zip, signature from packs where id = ${link.pack_id}`;
			return [200, pack.zip, {
				'Content-Type': 'application/zip',
				'Content-Disposition': `attachment; filename="${pack.slug}.zip"`,
				'X-Content-Signature': pack.signature,
			}];
		},

		async adminCreatePack(req) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.releaseBody);
			rejectUnknown(body, ['slug', 'name', 'description', 'claim_opens', 'claim_closes', 'zip_base64']);
			const slug = text(body, 'slug', { max: 64, pattern: SLUG });
			const name = localised(body, 'name', { min: 1, max: 200 });
			const description = localised(body, 'description');
			const window = claimWindow(body);
			const zip = parseZip(body);
			const sha256 = sha256Hex(zip);
			const signature = signEd25519(config.signingKey, zip);
			const [row] = await sql`
				insert into packs (slug, name_fr, name_en, description_fr, description_en, claim_opens_at, claim_closes_at,
					zip, zip_size, sha256, signature)
				values (${slug}, ${name.fr}, ${name.en}, ${description.fr}, ${description.en}, ${window.opens}, ${window.closes},
					${zip}, ${zip.length}, ${sha256}, ${signature})
				on conflict (slug) do nothing
				returning id`;
			if (!row) {
				throw new HttpError(409, 'pack_exists', `Le pack ${slug} existe déjà`);
			}
			log('info', 'pack_publie', { slug, bytes: zip.length });
			return [201, { slug, sha256, signature, size: zip.length }];
		},

		async adminUpdatePack(req, url, [slug]) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['claim_opens', 'claim_closes']);
			const window = claimWindow(body);
			const [row] = await sql`
				update packs set claim_opens_at = ${window.opens}, claim_closes_at = ${window.closes}
				where slug = ${slug} returning id, slug, claim_opens_at, claim_closes_at`;
			if (!row) {
				return [404, { code: 'not_found' }];
			}
			// Une session ouverte avant la fenêtre ne doit pas se payer pendant. Seule une fenêtre qui
			// ouvre avant qu'une session puisse expirer d'elle-même justifie de les fermer.
			if (window.opens && window.opens.getTime() < now() + CHECKOUT_TTL_SECONDS * 1000 && window.closes.getTime() > now()) {
				await expireCheckouts(await sql`delete from pack_checkouts where pack_id = ${row.id} returning checkout_session_id`);
			}
			const { id, ...shown } = row;
			return [200, shown];
		},

		// Corrige le contenu d'un pack déjà obtenu (ou le re-signe après rotation de clé).
		async adminReplacePackZip(req, url, [slug]) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.releaseBody);
			rejectUnknown(body, ['zip_base64']);
			const zip = parseZip(body);
			const sha256 = sha256Hex(zip);
			const signature = signEd25519(config.signingKey, zip);
			const [row] = await sql`
				update packs set zip = ${zip}, zip_size = ${zip.length}, sha256 = ${sha256}, signature = ${signature}
				where slug = ${slug} returning slug`;
			if (!row) {
				return [404, { code: 'not_found' }];
			}
			log('info', 'pack_remplace', { slug, bytes: zip.length });
			return [200, { slug, sha256, signature, size: zip.length }];
		},

		async adminDeletePack(req, url, [slug]) {
			requireAdmin(req);
			try {
				const rows = await sql`delete from packs where slug = ${slug} returning id`;
				return rows.length ? [204, null] : [404, { code: 'not_found' }];
			} catch (error) {
				// 23001 : on delete restrict; 23503 : même refus sous forme de clé étrangère.
				if (error.code === '23001' || error.code === '23503') {
					throw new HttpError(409, 'pack_claimed', `Le pack ${slug} a déjà été obtenu : ferme sa fenêtre plutôt que de le supprimer`);
				}
				throw error;
			}
		},

		async adminListReports(req, url) {
			requireAdmin(req);
			const status = url.searchParams.get('status');
			if (status !== null && !REPORT_STATUSES.includes(status)) {
				throw new HttpError(400, 'invalid_field', `status doit valoir ${REPORT_STATUSES.join(', ')}`);
			}
			const limit = Math.min(Math.max(Number(url.searchParams.get('limit')) || 100, 1), 500);
			const rows = await sql`
				select id, type, what, expected, locale, reply_email, diagnostics, status,
					screenshot is not null as has_screenshot, created_at, resolved_at
				from reports ${status ? sql`where status = ${status}` : sql``}
				order by created_at desc limit ${limit}`;
			return [200, { reports: rows }];
		},

		async adminReportScreenshot(req, url, [id]) {
			requireAdmin(req);
			const [row] = await sql`select screenshot, screenshot_type from reports where id = ${id}`;
			if (!row?.screenshot) {
				throw new HttpError(404, 'not_found', 'Aucune capture pour ce signalement');
			}
			return [200, row.screenshot, { 'Content-Type': row.screenshot_type }];
		},

		async adminUpdateReport(req, url, [id]) {
			requireAdmin(req);
			const body = await readJson(req, LIMITS.defaultBody);
			rejectUnknown(body, ['status']);
			const status = text(body, 'status', { max: 10 });
			if (!REPORT_STATUSES.includes(status)) {
				throw new HttpError(400, 'invalid_field', `status doit valoir ${REPORT_STATUSES.join(', ')}`);
			}
			const [row] = await sql`
				update reports set status = ${status}, updated_at = now(),
					resolved_at = ${status === 'réglé' ? sql`now()` : null}
				where id = ${id} returning id, status, resolved_at`;
			return row ? [200, row] : [404, { code: 'not_found' }];
		},

		async adminDeleteReport(req, url, [id]) {
			requireAdmin(req);
			const rows = await sql`delete from reports where id = ${id} returning id`;
			return rows.length ? [204, null] : [404, { code: 'not_found' }];
		},
	};

	const UUID = '([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})';
	const VERSION_SEGMENT = '([0-9A-Za-z.-]{1,64})';
	const routes = [
		// La sonde de canner interroge « / » : elle reçoit la même réponse que /health.
		['GET', /^\/(?:health)?$/, 'health'],
		['GET', /^\/v1\/update$/, 'update'],
		['GET', /^\/v1\/manifest$/, 'manifest'],
		['GET', new RegExp(`^/v1/download/([a-z0-9-]{1,64})/${VERSION_SEGMENT}/(\\d{1,12})/([0-9a-f]{64})\\.zip(\\.sig)?$`), 'download'],
		['GET', /^\/v1\/info$/, 'info'],
		['POST', /^\/v1\/licences\/verify$/, 'verifyLicence'],
		['POST', /^\/v1\/licences\/release$/, 'releaseSite'],
		['POST', /^\/v1\/reports$/, 'createReport'],
		['POST', /^\/v1\/webhooks\/stripe$/, 'stripeWebhook'],
		['POST', /^\/v1\/admin\/releases$/, 'adminCreateRelease'],
		['DELETE', new RegExp(`^/v1/admin/releases/([a-z0-9-]{1,64})/${VERSION_SEGMENT}$`), 'adminDeleteRelease'],
		['POST', /^\/v1\/admin\/licences$/, 'adminCreateLicence'],
		['PATCH', /^\/v1\/admin\/licences\/(\d{1,18})$/, 'adminUpdateLicence'],
		['DELETE', /^\/v1\/admin\/licences\/(\d{1,18})$/, 'adminDeleteLicence'],
		['GET', /^\/v1\/admin\/licences\/(\d{1,18})\/sites$/, 'adminLicenceSites'],
		['DELETE', /^\/v1\/admin\/licences\/(\d{1,18})\/sites(?:\/([0-9a-f]{12}))?$/, 'adminReleaseSites'],
		['POST', /^\/v1\/admin\/licences\/(\d{1,18})\/rotate$/, 'adminRotateLicence'],
		['POST', /^\/v1\/admin\/licences\/(\d{1,18})\/notice$/, 'adminLicenceNotice'],
		['DELETE', /^\/v1\/admin\/licences\/(\d{1,18})\/notice$/, 'adminLicenceNoticeOff'],
		['POST', /^\/v1\/admin\/licences\/(\d{1,18})\/revoke$/, 'adminRevokeLicence'],
		['POST', /^\/v1\/admin\/licences\/(\d{1,18})\/restore$/, 'adminRestoreLicence'],
		['GET', /^\/v1\/admin\/licence-flags$/, 'adminListLicenceFlags'],
		['PATCH', /^\/v1\/admin\/licence-flags\/(\d{1,18})$/, 'adminReviewLicenceFlag'],
		['GET', /^\/v1\/packs$/, 'listPacks'],
		['POST', /^\/v1\/packs\/claim$/, 'claimPacks'],
		['POST', /^\/v1\/packs\/checkout$/, 'packCheckout'],
		['GET', /^\/v1\/packs\/download\/([A-Za-z0-9_-]{43})$/, 'packLinkPage'],
		['POST', /^\/v1\/packs\/download\/([A-Za-z0-9_-]{43})$/, 'packDownload'],
		['POST', /^\/v1\/admin\/packs$/, 'adminCreatePack'],
		['PATCH', /^\/v1\/admin\/packs\/([a-z0-9-]{1,64})$/, 'adminUpdatePack'],
		['DELETE', /^\/v1\/admin\/packs\/([a-z0-9-]{1,64})$/, 'adminDeletePack'],
		['PUT', /^\/v1\/admin\/packs\/([a-z0-9-]{1,64})\/zip$/, 'adminReplacePackZip'],
		['PUT', /^\/v1\/admin\/packs\/([a-z0-9-]{1,64})\/price$/, 'adminSetPackPrice'],
		['POST', /^\/v1\/licences\/checkout$/, 'licenceCheckout'],
		['GET', /^\/v1\/licences\/purchase$/, 'licencePurchase'],
		['GET', /^\/v1\/licences\/renew$/, 'licenceRenew'],
		['GET', /^\/v1\/licences\/download$/, 'licenceDownloadPage'],
		['POST', /^\/v1\/licences\/download$/, 'licenceDownload'],
		['GET', /^\/v1\/admin\/licence-offers$/, 'adminListLicenceOffers'],
		['POST', /^\/v1\/admin\/licence-offers$/, 'adminUpsertLicenceOffer'],
		['PUT', /^\/v1\/admin\/licence-offers\/([a-z0-9-]{1,64})\/price$/, 'adminSetOfferPrice'],
		['GET', /^\/v1\/admin\/licence-coupons$/, 'adminListLicenceCoupons'],
		['POST', /^\/v1\/admin\/licence-coupons$/, 'adminCreateLicenceCoupon'],
		['POST', /^\/v1\/admin\/licence-links$/, 'adminLicenceLink'],
		['POST', /^\/v1\/admin\/licence-renewals\/(cs_[A-Za-z0-9_]{1,200})\/refund$/, 'adminRefundRenewal'],
		['DELETE', /^\/v1\/admin\/licence-coupons\/([A-Z0-9-]{3,40})$/, 'adminDisableLicenceCoupon'],
		['POST', /^\/v1\/admin\/licences\/(\d{1,18})\/resend$/, 'adminResendLicence'],
		['POST', /^\/v1\/admin\/stripe\/reconcile$/, 'adminReconcile'],
		['GET', /^\/v1\/admin\/reports$/, 'adminListReports'],
		['GET', new RegExp(`^/v1/admin/reports/${UUID}/screenshot$`), 'adminReportScreenshot'],
		['PATCH', new RegExp(`^/v1/admin/reports/${UUID}$`), 'adminUpdateReport'],
		['DELETE', new RegExp(`^/v1/admin/reports/${UUID}$`), 'adminDeleteReport'],
	];

	async function handle(req, res) {
		const started = now();
		let route = 'not_found';
		let status = 500;
		try {
			const url = new URL(req.url ?? '/', 'http://localhost');
			let pathMatched = false;
			for (const [method, pattern, name] of routes) {
				const match = pattern.exec(url.pathname);
				if (!match) {
					continue;
				}
				pathMatched = true;
				if (method !== req.method) {
					continue;
				}
				route = name;
				if (PUBLIC_ROUTES.has(name)) {
					const limit = publicLimiter.hit(clientIp(req));
					if (!limit.allowed) {
						status = 429;
						send(res, 429, { code: 'rate_limited' }, { 'Retry-After': String(limit.retryAfterSeconds) });
						return;
					}
				}
				if (DOWNLOAD_ROUTES.has(name)) {
					// Avant le handler : un lien de pack à usage unique ne se brûle pas sur un refus.
					if (downloadsInFlight >= MAX_CONCURRENT_DOWNLOADS) {
						status = 503;
						send(res, 503, { code: 'busy' }, { 'Retry-After': '30' });
						return;
					}
					downloadsInFlight++;
					let released = false;
					const deadline = setTimeout(() => res.destroy(), downloadMaxMs);
					deadline.unref();
					res.once('close', () => {
						clearTimeout(deadline);
						if (!released) {
							released = true;
							downloadsInFlight--;
						}
					});
				}
				const [code, body, headers] = await handlers[name](req, url, match.slice(1));
				if (DOWNLOAD_ROUTES.has(name)) {
					// Armé après la lecture en base, pas avant : une attente de connexion au pool ne
					// doit pas couper la cliente sans réponse. Le plafond total, lui, court déjà.
					res.setTimeout(downloadIdleMs, () => res.destroy());
				}
				status = code;
				send(res, code, body, headers);
				return;
			}
			status = pathMatched ? 405 : 404;
			send(res, status, { code: pathMatched ? 'method_not_allowed' : 'not_found' });
		} catch (error) {
			if (error instanceof HttpError) {
				status = error.status;
				send(res, status, { code: error.code, message: error.message });
			} else {
				status = 500;
				// Le nom de l'erreur seulement : un message peut recopier une valeur reçue.
				log('error', 'erreur_interne', { route, error: error.code ?? error.name });
				if (!res.headersSent) {
					send(res, 500, { code: 'internal_error' });
				} else {
					res.destroy();
				}
			}
		} finally {
			log('info', 'requete', { method: req.method, route, status, ms: now() - started });
		}
	}
	// Appelées par server.mjs : chaque jour, et toutes les 10 minutes.
	handle.reconcile = reconcile;
	handle.redeliverLicences = redeliverLicences;
	handle.sendRenewalReminders = sendRenewalReminders;
	return handle;
}

// Devises sans décimales et à trois décimales selon Stripe (docs.stripe.com/currencies) :
// Adaptive Pricing, toujours actif avec Managed Payments, peut encaisser en yens ou en dinars.
const ZERO_DECIMAL = new Set(['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF']);
const THREE_DECIMAL = new Set(['BHD', 'JOD', 'KWD', 'OMR', 'TND']);

export function formatAmount(minor, currency) {
	const code = String(currency ?? '').toUpperCase();
	const decimals = ZERO_DECIMAL.has(code) ? 0 : THREE_DECIMAL.has(code) ? 3 : 2;
	return `${(Number(minor) / 10 ** decimals).toFixed(decimals)} ${code}`;
}

function parseZip(body) {
	const zip = Buffer.from(text(body, 'zip_base64', { min: 4, max: LIMITS.releaseBody, pattern: /^[A-Za-z0-9+/]+={0,2}$/ }), 'base64');
	if (zip.length > LIMITS.zipBytes || zip.readUInt32LE(0) !== 0x04034b50) {
		throw new HttpError(400, 'invalid_zip', `zip_base64 doit contenir une archive zip de ${LIMITS.zipBytes} octets au plus`);
	}
	return zip;
}

function localised(body, field, { min = 0, max = 100_000 } = {}) {
	const value = body[field] ?? {};
	if (typeof value !== 'object' || Array.isArray(value) || value === null) {
		throw new HttpError(400, 'invalid_field', `${field} doit être un objet {fr, en}`);
	}
	rejectUnknown(value, ['fr', 'en']);
	const read = (lang) => (min > 0 ? text(value, lang, { min, max }) : text(value, lang, { max, optional: true }) ?? '');
	return { fr: read('fr'), en: read('en') };
}

function optionalDate(body, field) {
	const raw = text(body, field, { max: 30, optional: true });
	if (raw === null) {
		return null;
	}
	const date = new Date(raw);
	if (Number.isNaN(date.getTime())) {
		throw new HttpError(400, 'invalid_field', `${field} doit être une date ISO 8601 ou null`);
	}
	return date;
}

// Fenêtre de réclamation gratuite : les deux bornes, ou aucune (pack payant seulement).
function claimWindow(body) {
	const opens = optionalDate(body, 'claim_opens');
	const closes = optionalDate(body, 'claim_closes');
	if ((opens === null) !== (closes === null)) {
		throw new HttpError(400, 'invalid_field', 'claim_opens et claim_closes vont ensemble : les deux dates, ou null pour les deux');
	}
	if (opens && closes <= opens) {
		throw new HttpError(400, 'invalid_field', 'claim_closes doit suivre claim_opens');
	}
	return { opens, closes };
}

// Une adresse IPv6 compte pour son /64 (ou son /48 avec groups = 3) : un bloc attribué à une
// seule personne en fournit des milliards, qui rempliraient la table du limiteur. Une IPv4
// écrite en IPv6 (::ffff:a.b.c.d) redevient elle-même, sinon toutes partageraient un compteur.
export function ipBucket(ip, groups = 4) {
	const mapped = /^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i.exec(ip);
	if (mapped) {
		return mapped[1];
	}
	if (!ip.includes(':')) {
		return ip;
	}
	const [head, tail = ''] = ip.toLowerCase().split('::');
	const left = head ? head.split(':') : [];
	const right = tail ? tail.split(':') : [];
	const parts = ip.includes('::') ? [...left, ...Array(Math.max(0, 8 - left.length - right.length)).fill('0'), ...right] : left;
	return `${parts.slice(0, groups).map((g) => g.replace(/^0+(?=.)/, '')).join(':')}::/${groups * 16}`;
}

// Deux niveaux en IPv6 : le /64 à la limite, le /48 à IPV6_48_FACTOR fois la limite. Un /64
// neuf passe d'abord par son /48, avant d'avoir sa clé : un tunnel qui change de /64 à chaque
// requête crée au plus IPV6_48_FACTOR fois la limite de clés, et ne remplit plus la table.
export function createIpLimiter({ limit, windowMs, maxKeys, now }) {
	const narrow = createRateLimiter({ limit, windowMs, maxKeys, now });
	const wide = createRateLimiter({ limit: limit * IPV6_48_FACTOR, windowMs, maxKeys, now });
	return {
		hit(ip) {
			const bucket = ipBucket(ip);
			if (!bucket.endsWith('::/64')) {
				return narrow.hit(bucket);
			}
			if (!narrow.active(bucket)) {
				const outer = wide.hit(ipBucket(ip, 3));
				return outer.allowed ? narrow.hit(bucket) : outer;
			}
			// /64 déjà compté : lui d'abord, ses requêtes refusées n'entament pas le quota des voisins.
			const inner = narrow.hit(bucket);
			return inner.allowed ? wide.hit(ipBucket(ip, 3)) : inner;
		},
	};
}

function packSlugs(value) {
	if (!Array.isArray(value) || value.length === 0 || value.length > PACKS_PER_CLAIM) {
		throw new HttpError(400, 'invalid_field', `packs doit être une liste de 1 à ${PACKS_PER_CLAIM} identifiants`);
	}
	for (const slug of value) {
		if (typeof slug !== 'string' || slug.length > 64 || !SLUG.test(slug)) {
			throw new HttpError(400, 'invalid_field', 'packs : identifiant invalide');
		}
	}
	return [...new Set(value)];
}

export function packLinkHtml(valid) {
	const body = valid
		? `<h1>Télécharger ton pack Fluffless</h1>
<p lang="en">Download your Fluffless pack</p>
<form method="post"><button type="submit">Télécharger / <span lang="en">Download</span></button></form>
<p>Ce lien fonctionne une seule fois.</p>
<p lang="en">This link works only once.</p>`
		: `<h1>Lien expiré ou déjà utilisé</h1>
<p>Redemande-le sur la page du catalogue avec ta clé de licence.</p>
<p lang="en">This link has expired or was already used. Request a new one from the catalogue page with your licence key.</p>`;
	return `<!doctype html>
<html lang="fr-CA">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Fluffless</title>
${body}
</html>
`;
}

const LICENCE_PAGE_TEXTS = {
	invalid: `<div role="alert">
<p>Clé inconnue ou révoquée. Vérifie-la dans le courriel reçu après ton achat.</p>
<p lang="en">Unknown or revoked key. Check it in the email you received after your purchase.</p>
</div>`,
	partial: `<div role="alert">
<p>Une des extensions de ta licence n'a pas encore de version publiée. Télécharge les autres maintenant et reviens plus tard, ou écris à genevieve@fluffless.dev.</p>
<p lang="en">One of the plugins in your licence has no published version yet. Download the others now and come back later, or write to genevieve@fluffless.dev.</p>
</div>`,
	renewInvalid: `<div role="alert">
<p>Ce lien de renouvellement ne fonctionne plus. Si tu as déjà renouvelé, il n'y a rien à faire. Sinon, écris à genevieve@fluffless.dev.</p>
<p lang="en">This renewal link no longer works. If you already renewed, there is nothing to do. Otherwise, write to genevieve@fluffless.dev.</p>
</div>`,
	renewUnavailable: `<div role="alert">
<p>Le renouvellement n'est pas disponible pour le moment. Réessaie dans un moment ou écris à genevieve@fluffless.dev.</p>
<p lang="en">Renewal is not available right now. Try again in a moment or write to genevieve@fluffless.dev.</p>
</div>`,
	renewLimited: `<div role="alert">
<p>Trop d'essais en peu de temps. Réessaie dans quelques minutes.</p>
<p lang="en">Too many attempts in a short time. Try again in a few minutes.</p>
</div>`,
	unavailable: `<div role="alert">
<p>Aucune version n'est disponible pour le moment. Réessaie plus tard ou écris à genevieve@fluffless.dev.</p>
<p lang="en">No version is available right now. Try again later or write to genevieve@fluffless.dev.</p>
</div>`,
};

// Page sans script ni style externe : le formulaire poste sur lui-même, d'où form-action 'self'.
// g***e@gmial.com : la première et la dernière lettre, et le domaine en entier, là où se
// cachent la plupart des fautes de frappe. Toujours trois étoiles : la longueur ne fuit pas.
export function maskEmail(email) {
	const at = email.lastIndexOf('@');
	// Par point de code : un emoji en tête ne doit pas être coupé en deux (JSON illisible côté site).
	const local = Array.from(email.slice(0, at));
	const masked = local.length <= 2 ? `${local[0]}***` : `${local[0]}***${local.at(-1)}`;
	return `${masked}@${email.slice(at + 1)}`;
}

// Liste d'extensions livrées : 1 à 5 identifiants distincts, ou null si le champ est absent.
// Absent : undefined. null : toutes les extensions, seulement là où { all: true } le permet.
function releaseList(body, field, { all = false } = {}) {
	const value = body[field];
	if (value === undefined || (value === null && all)) {
		return value;
	}
	const valid = Array.isArray(value) && value.length >= 1 && value.length <= MAX_RELEASES_PER_OFFER
		&& value.every((slug) => typeof slug === 'string' && slug.length <= 64 && SLUG.test(slug))
		&& new Set(value).size === value.length;
	if (!valid) {
		throw new HttpError(400, 'invalid_field', `${field} : liste de 1 à ${MAX_RELEASES_PER_OFFER} identifiants d'extension distincts`);
	}
	return value;
}

const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);

const HELP_EMAIL = 'genevieve@fluffless.dev';

// Licence expirée : la date de fin, la même clé après renouvellement, et le lien quand la
// licence vient d'un achat en ligne; sinon une adresse pour écrire (licence créée à la main).
function expiredNotice({ date, renew, grace = null }) {
	const href = renew ? escapeHtml(renew).replace(/&#38;/g, '&amp;') : null;
	return `<div role="alert">
<p>Ta licence est expirée : elle a pris fin le ${date.fr}. Le site continue de fonctionner ; les mises à jour et le téléchargement reprennent dès le renouvellement, et ta clé reste la même.${grace ? ` Renouvelle d'ici le ${grace.fr} pour garder ton prix.` : ''}</p>
<p lang="en">Your licence has expired: it ended on ${date.en}. Your site keeps working; updates and downloads resume as soon as you renew, and your key stays the same.${grace ? ` Renew by ${grace.en} to keep your price.` : ''}</p>
</div>
${href ? `<p><a href="${href}">Renouveler ma licence</a></p>` : `<p>Pour la renouveler, écris à ${HELP_EMAIL}.</p>`}
${href ? `<p lang="en"><a href="${href}">Renew my licence</a></p>` : `<p lang="en">To renew it, write to ${HELP_EMAIL}.</p>`}`;
}

// Lien de zip périmé (15 minutes) ou falsifié : retour vers la page où l'on colle sa clé.
function linkExpiredHtml(downloadPage) {
	const href = escapeHtml(downloadPage);
	const html = `<!doctype html>
<html lang="fr-CA">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Fluffless</title>
<h1>Lien de téléchargement expiré</h1>
<p lang="en">Download link expired</p>
<p>Les liens de téléchargement valent 15 minutes. Colle ta clé de nouveau pour en obtenir d'autres.</p>
<p><a href="${href}">Retourner à la page de téléchargement</a></p>
<p lang="en">Download links last 15 minutes. Paste your key again to get new ones.</p>
<p lang="en"><a href="${href}">Back to the download page</a></p>
</html>
`;
	return [403, Buffer.from(html), {
		'Content-Type': 'text/html; charset=utf-8',
		'Content-Security-Policy': "default-src 'none'; frame-ancestors 'none'",
		'Cache-Control': 'no-store',
	}];
}

// Échec d'un lien de renouvellement : sa propre page, sans le formulaire de clé, qui ne
// renouvelle rien. Un 429 garde son Retry-After.
export function renewHtml(status, state, headers = {}) {
	const html = `<!doctype html>
<html lang="fr-CA">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Fluffless</title>
<h1>Renouveler ta licence Fluffless</h1>
<p lang="en">Renew your Fluffless licence</p>
${LICENCE_PAGE_TEXTS[state]}
</html>
`;
	return [status, Buffer.from(html), {
		'Content-Type': 'text/html; charset=utf-8',
		'Content-Security-Policy': "default-src 'none'; frame-ancestors 'none'",
		'Cache-Control': 'no-store',
		...headers,
	}];
}

// Cibles d'au moins 44 px (liens, champ et bouton) et champ de clé à sa largeur : la seule
// feuille de style de la page, autorisée par son empreinte (la CSP garde default-src 'none').
const DOWNLOAD_PAGE_STYLE = 'li a{display:inline-block;min-block-size:44px;line-height:44px}'
	+ 'input,button{min-block-size:44px;box-sizing:border-box;max-inline-size:100%}button{padding-inline:1em}';
const DOWNLOAD_PAGE_STYLE_HASH = createHash('sha256').update(DOWNLOAD_PAGE_STYLE).digest('base64');

export function licenceDownloadHtml(status, state, links = [], expired = null) {
	const list = links.length === 0 ? '' : `<ol>
${links.map((link) => `<li><a href="${escapeHtml(link.url)}">${escapeHtml(link.name)} ${escapeHtml(link.version)}</a></li>`).join('\n')}
</ol>
<p>Installe Fluffless Suite en premier : les autres extensions en ont besoin. Les liens valent 15 minutes ; pour en obtenir d'autres, colle ta clé de nouveau.</p>
<p lang="en">Install Fluffless Suite first: the other plugins need it. The links last 15 minutes; to get new ones, paste your key again.</p>
`;
	const html = `<!doctype html>
<html lang="fr-CA">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Fluffless</title>
<style>${DOWNLOAD_PAGE_STYLE}</style>
<h1>Télécharger la Suite Fluffless</h1>
<p lang="en">Download the Fluffless Suite</p>
${state === 'expired' ? expiredNotice(expired) : LICENCE_PAGE_TEXTS[state] ?? ''}
${list}<form method="post">
<label for="key">Clé de licence / <span lang="en">Licence key</span></label>
<input id="key" name="key" type="text" size="40" required maxlength="${LICENCE_MAX_CHARS}" autocomplete="off" spellcheck="false" placeholder="FL-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX">
<button type="submit">Télécharger / <span lang="en">Download</span></button>
</form>
<p>Ta clé est dans le courriel reçu après ton achat.</p>
<p lang="en">Your key is in the email you received after your purchase.</p>
</html>
`;
	return [status, Buffer.from(html), {
		'Content-Type': 'text/html; charset=utf-8',
		'Content-Security-Policy': `default-src 'none'; style-src 'sha256-${DOWNLOAD_PAGE_STYLE_HASH}'; form-action 'self'; frame-ancestors 'none'`,
		// La page peut porter des liens signés : aucun cache ne doit la garder.
		'Cache-Control': 'no-store',
	}];
}

function parseDiagnostics(value) {
	if (value === undefined || value === null) {
		return null;
	}
	if (typeof value !== 'object' || Array.isArray(value)) {
		throw new HttpError(400, 'invalid_field', 'diagnostics doit être un objet JSON');
	}
	const serialized = JSON.stringify(value);
	const size = Buffer.byteLength(serialized);
	if (size > LIMITS.diagnosticsJson) {
		throw new HttpError(400, 'invalid_field', `diagnostics limité à ${LIMITS.diagnosticsJson} octets (reçu : ${size})`);
	}
	// jsonb n'accepte pas U+0000.
	if (serialized.includes(`${'\\'}u0000`)) {
		throw new HttpError(400, 'invalid_field', 'diagnostics contient un caractère nul');
	}
	return value;
}

const PNG_MAGIC = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

function parseScreenshot(value) {
	if (value === undefined || value === null) {
		return null;
	}
	const maxBase64 = Math.ceil(LIMITS.screenshotBytes / 3) * 4;
	if (typeof value !== 'string') {
		throw new HttpError(400, 'invalid_field', 'screenshot doit être une chaîne base64');
	}
	const data = value.replace(/^data:image\/(png|webp);base64,/, '');
	if (data.length > maxBase64 || !/^[A-Za-z0-9+/]+={0,2}$/.test(data)) {
		throw new HttpError(400, 'invalid_field', `screenshot : base64 de ${LIMITS.screenshotBytes} octets au plus (reçu : ${data.length} caractères)`);
	}
	const bytes = Buffer.from(data, 'base64');
	if (bytes.length > LIMITS.screenshotBytes) {
		throw new HttpError(400, 'invalid_field', `screenshot dépasse ${LIMITS.screenshotBytes} octets`);
	}
	if (bytes.subarray(0, 8).equals(PNG_MAGIC)) {
		return { bytes, type: 'image/png' };
	}
	if (bytes.length >= 12 && bytes.toString('latin1', 0, 4) === 'RIFF' && bytes.toString('latin1', 8, 12) === 'WEBP') {
		return { bytes, type: 'image/webp' };
	}
	throw new HttpError(400, 'invalid_field', 'screenshot doit être une image PNG ou WebP');
}
