// Coupons de licence (rabais OBNL et autres) : créés par l'administration avec un taux posé à la
// création (aucune valeur par défaut dans le code), appliqués à la session Checkout par code,
// comptés à l'achat. Stripe et SMTP simulés, aucune requête vers l'extérieur.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac, randomBytes } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fakeZip, startTestServer } from './helpers.mjs';

const skip = process.env.API_TEST_SKIP_DB === '1' ? 'API_TEST_SKIP_DB=1' : false;
const WEBHOOK_SECRET = `whsec_${randomBytes(18).toString('hex')}`;
const STRIPE_ENV = {
	STRIPE_SECRET_KEY: `sk_test_${randomBytes(12).toString('hex')}`,
	STRIPE_WEBHOOK_SECRET: WEBHOOK_SECRET,
	STRIPE_SUCCESS_URL: 'https://fluffless.dev/merci?session={CHECKOUT_SESSION_ID}',
	STRIPE_CANCEL_URL: 'https://fluffless.dev/acheter',
	PROTON_SMTP_USER: 'vente@exemple.ca',
	PROTON_SMTP_TOKEN: 'faux-jeton-smtp',
};

function createFakeStripe() {
	const fake = {
		sessions: [],
		coupons: [],
		failCoupon: false,
		refuseDiscount: false,
		failSession: false,
		prices: { price_lic123: { id: 'price_lic123', active: true, type: 'one_time', unit_amount: 6900, currency: 'cad', tax_behavior: 'exclusive' } },
		async createCheckoutSession(params) {
			if (fake.refuseDiscount && params.discounts) {
				throw Object.assign(new Error('Stripe a répondu 400'), { code: 'coupon_expired', status: 400 });
			}
			if (fake.failSession) {
				throw Object.assign(new Error('Stripe a répondu 500'), { code: 'stripe_500', status: 500 });
			}
			fake.sessions.push(params);
			return { id: `cs_test_cp_${fake.sessions.length}`, url: `https://checkout.stripe.com/c/pay/cs_test_cp_${fake.sessions.length}` };
		},
		async createCoupon(params, idempotencyKey) {
			if (fake.failCoupon) {
				throw Object.assign(new Error('Stripe a répondu 500'), { code: 'stripe_500', status: 500 });
			}
			const coupon = { id: `cpn_${fake.coupons.length + 1}`, params, idempotencyKey };
			fake.coupons.push(coupon);
			return coupon;
		},
		async expireCheckoutSession(id) {
			return { id, status: 'expired' };
		},
		async retrievePrice(id) {
			return fake.prices[id];
		},
		async createRefund() {
			return { id: 're_1' };
		},
		async listRefunds() {
			return { data: [] };
		},
		async listCheckoutSessions() {
			return { data: [], has_more: false };
		},
	};
	return fake;
}

const sign = (payload, timestamp = Math.floor(Date.now() / 1000)) =>
	`t=${timestamp},v1=${createHmac('sha256', WEBHOOK_SECRET).update(`${timestamp}.${payload}`).digest('hex')}`;

describe('coupons de licence', { skip }, () => {
	let t;
	let stripe;
	let offerId;
	let counter = 0;
	const admin = (path, method, body, token = t.env.ADMIN_TOKEN) => t.request(path, { method, token, body });
	const createCoupon = (fields = {}) => admin('/v1/admin/licence-coupons', 'POST', { code: 'OBNL', percent_off: 20, label: 'OBNL', ...fields });
	const checkout = (fields = {}) => t.request('/v1/licences/checkout', { method: 'POST', body: { offer: 'solo', locale: 'fr_CA', ...fields } });
	// Lien de paiement signé à la main par l'administration (F-obnl-lien) : seul chemin du rabais.
	const NOTE = 'Organisme test, NEQ 1234567890';
	const link = (fields = {}, token = t.env.ADMIN_TOKEN) => admin('/v1/admin/licence-links', 'POST', { offer: 'solo', locale: 'fr_CA', coupon: 'OBNL2026', note: NOTE, ...fields }, token);
	const lastSession = () => stripe.sessions[stripe.sessions.length - 1];
	const webhook = (object) => {
		const payload = JSON.stringify({ id: `evt_cp_${++counter}`, type: 'checkout.session.completed', data: { object } });
		return t.request('/v1/webhooks/stripe', { method: 'POST', body: payload, headers: { 'Stripe-Signature': sign(payload) } });
	};

	before(async () => {
		stripe = createFakeStripe();
		t = await startTestServer({ env: STRIPE_ENV, stripe });
		const release = await admin('/v1/admin/releases', 'POST', {
			slug: 'fluffless-suite', version: '1.0.0', channel: 'stable', name: 'Fluffless Suite',
			description: { fr: 'Outils', en: 'Tools' }, changelog: { fr: 'Première', en: 'First' },
			zip_base64: fakeZip('suite').toString('base64'),
		});
		assert.equal(release.status, 201);
		assert.equal((await admin('/v1/admin/licence-offers', 'POST', { slug: 'solo', plan: 'solo', sites_max: 1, duration_days: 365 })).status, 200);
		assert.equal((await admin('/v1/admin/licence-offers/solo/price', 'PUT', { price_id: 'price_lic123' })).status, 200);
		offerId = String((await t.sql`select id from licence_offers where slug = 'solo'`)[0].id);
	});
	after(async () => t?.stop());

	test('aucun coupon n\'existe tant que l\'administration n\'en pose pas, et le code ne porte aucun taux', async () => {
		assert.equal((await t.sql`select count(*)::int as n from licence_coupons`)[0].n, 0);
		const source = ['app.mjs', 'config.mjs'].map((file) => readFileSync(new URL(`../src/${file}`, import.meta.url), 'utf8')).join('\n');
		assert.doesNotMatch(source, /percent_off\s*[:=]\s*\d/);
		assert.doesNotMatch(source, /NONPROFIT|OBNL/i);
	});

	test('création : réservée à l\'administration, taux obligatoire entre 1 et 99, champs inconnus refusés', async () => {
		assert.equal((await admin('/v1/admin/licence-coupons', 'POST', { code: 'XXX1', percent_off: 20 }, 'jeton-invalide')).status, 401);
		const noToken = await t.request('/v1/admin/licence-coupons', { method: 'POST', body: { code: 'SANSJETON', percent_off: 20 } });
		assert.equal(noToken.status, 401);
		for (const bad of [undefined, 0, 100, 150, -5, 12.5, '20']) {
			const res = await admin('/v1/admin/licence-coupons', 'POST', { code: 'MAUVAIS', ...(bad === undefined ? {} : { percent_off: bad }) });
			assert.equal(res.status, 400, `percent_off ${String(bad)}`);
		}
		for (const code of ['ab', 'a b', 'ÉTÉ', 'X'.repeat(41)]) {
			assert.equal((await admin('/v1/admin/licence-coupons', 'POST', { code, percent_off: 20 })).status, 400, code);
		}
		assert.equal((await admin('/v1/admin/licence-coupons', 'POST', { code: 'INCONNU', percent_off: 20, amount_off: 500 })).status, 400);
		assert.equal((await t.sql`select count(*)::int as n from licence_coupons where code = 'MAUVAIS'`)[0].n, 0);
	});

	test('création : le taux posé part chez Stripe tel quel, une seule fois par utilisation', async () => {
		const res = await createCoupon({ code: 'obnl2026', percent_off: 20, max_redemptions: 50, expires_at: '2099-01-01T00:00:00Z' });
		assert.equal(res.status, 201);
		const body = await res.json();
		assert.equal(body.code, 'OBNL2026');
		assert.equal(body.percent_off, 20);
		assert.equal(body.active, true);
		const sent = stripe.coupons.at(-1);
		assert.equal(sent.params.percent_off, 20);
		assert.equal(sent.params.duration, 'once');
		assert.equal(sent.params.max_redemptions, 50);
		assert.equal(sent.params.redeem_by, Math.floor(Date.parse('2099-01-01T00:00:00Z') / 1000));
		assert.ok(sent.idempotencyKey);
		const [row] = await t.sql`select code, percent_off, stripe_coupon_id from licence_coupons where code = 'OBNL2026'`;
		assert.equal(row.percent_off, 20);
		assert.equal(row.stripe_coupon_id, sent.id);
	});

	test('création : code déjà pris (409) et Stripe en panne (502) ne laissent aucune ligne', async () => {
		assert.equal((await createCoupon({ code: 'OBNL2026', percent_off: 30 })).status, 409);
		assert.equal((await t.sql`select percent_off from licence_coupons where code = 'OBNL2026'`)[0].percent_off, 20);
		stripe.failCoupon = true;
		assert.equal((await createCoupon({ code: 'PANNE', percent_off: 10 })).status, 502);
		stripe.failCoupon = false;
		assert.equal((await t.sql`select count(*)::int as n from licence_coupons where code = 'PANNE'`)[0].n, 0);
	});

	test('achat sans coupon : session inchangée, aucun rabais', async () => {
		const res = await checkout();
		assert.equal(res.status, 201);
		assert.equal('discounts' in lastSession(), false);
		assert.equal('coupon' in lastSession().metadata, false);
	});

	test('route publique : un corps avec coupon répond 400 unknown_field, aucune session (F-obnl-lien-01)', async () => {
		const before = stripe.sessions.length;
		const res = await checkout({ coupon: 'OBNL2026' });
		assert.equal(res.status, 400);
		assert.equal((await res.json()).code, 'unknown_field');
		assert.equal(stripe.sessions.length, before);
	});

	test('lien admin : sans jeton ou jeton faux, 401 et aucune session', async () => {
		const before = stripe.sessions.length;
		assert.equal((await link({}, 'jeton-invalide')).status, 401);
		const noToken = await t.request('/v1/admin/licence-links', { method: 'POST', body: { offer: 'solo', locale: 'fr_CA', coupon: 'OBNL2026', note: NOTE } });
		assert.equal(noToken.status, 401);
		assert.equal(stripe.sessions.length, before);
	});

	test('lien admin : offre inconnue, sans prix ou sans version stable, 404 not_for_sale', async () => {
		assert.equal((await admin('/v1/admin/licence-offers', 'POST', { slug: 'sansprix', plan: 'solo', sites_max: 1, duration_days: 365 })).status, 200);
		assert.equal((await admin('/v1/admin/licence-offers', 'POST', { slug: 'sansversion', plan: 'solo', sites_max: 1, duration_days: 365, release_slugs: ['fluffless-seo'] })).status, 200);
		assert.equal((await admin('/v1/admin/licence-offers/sansversion/price', 'PUT', { price_id: 'price_lic123' })).status, 200);
		const before = stripe.sessions.length;
		for (const offer of ['inconnue', 'sansprix', 'sansversion']) {
			const res = await link({ offer });
			assert.equal(res.status, 404, offer);
			assert.equal((await res.json()).code, 'not_for_sale', offer);
		}
		assert.equal(stripe.sessions.length, before);
	});

	test('lien admin : session avec discounts du coupon, metadata kind=licence, link=admin, note, et url rendue', async () => {
		const res = await link({ coupon: 'obnl2026' });
		assert.equal(res.status, 201);
		const body = await res.json();
		const session = lastSession();
		assert.equal(body.url, `https://checkout.stripe.com/c/pay/cs_test_cp_${stripe.sessions.length}`);
		assert.equal(body.offer, 'solo');
		assert.equal(body.coupon, 'OBNL2026');
		assert.equal(body.percent_off, 20);
		assert.deepEqual(session.discounts, [{ coupon: stripe.coupons[0].id }]);
		assert.equal(session.metadata.kind, 'licence');
		assert.equal(session.metadata.link, 'admin');
		assert.equal(session.metadata.note, NOTE);
		assert.equal(session.metadata.coupon, 'OBNL2026');
		assert.equal(session.payment_intent_data.metadata.coupon, 'OBNL2026');
		assert.equal('allow_promotion_codes' in session, false);
		// Mêmes garanties que la route publique : taxes, adresse, conditions, avis de vente.
		assert.deepEqual(session.automatic_tax, { enabled: true });
		assert.equal(session.billing_address_collection, 'required');
		assert.deepEqual(session.consent_collection, { terms_of_service: 'required' });
		assert.ok(session.custom_text?.submit?.message);
		assert.match(session.success_url, /kind=licence/);
		assert.equal(session.locale, 'fr-CA');
	});

	test('lien admin : expire par défaut 24 h après, hours=1 donne 1 h, hours=0, 25, 1.5 ou "abc" refusés en 400', async () => {
		const start = Math.floor(Date.now() / 1000);
		const res = await link();
		assert.equal(res.status, 201);
		const body = await res.json();
		const expires = lastSession().expires_at;
		assert.ok(expires >= start + 24 * 3600 && expires <= Math.floor(Date.now() / 1000) + 24 * 3600, String(expires));
		assert.equal(Date.parse(body.expires_at), expires * 1000);
		assert.equal((await link({ hours: 1 })).status, 201);
		assert.ok(lastSession().expires_at <= Math.floor(Date.now() / 1000) + 3600);
		const before = stripe.sessions.length;
		for (const hours of [0, 25, 1.5, 'abc', -1]) {
			assert.equal((await link({ hours })).status, 400, String(hours));
		}
		assert.equal(stripe.sessions.length, before);
	});

	test('lien admin : email posé en customer_email; email mal formé refusé en 400', async () => {
		assert.equal((await link({ email: 'organisme@exemple.ca' })).status, 201);
		assert.equal(lastSession().customer_email, 'organisme@exemple.ca');
		assert.equal('email' in lastSession().metadata, false);
		assert.equal((await link()).status, 201);
		assert.equal('customer_email' in lastSession(), false);
		assert.equal((await link({ email: 'pas-un-courriel' })).status, 400);
	});

	test('lien admin : note absente, trop courte ou de plus de 120 caractères, 400', async () => {
		const before = stripe.sessions.length;
		assert.equal((await admin('/v1/admin/licence-links', 'POST', { offer: 'solo', locale: 'fr_CA', coupon: 'OBNL2026' })).status, 400);
		for (const note of ['', 'ab', 'x'.repeat(121)]) {
			assert.equal((await link({ note })).status, 400, `note de ${note.length}`);
		}
		assert.equal((await link({ note: 'x'.repeat(120) })).status, 201);
		assert.equal(stripe.sessions.length, before + 1);
	});

	test('lien admin : champ inconnu refusé (rejectUnknown)', async () => {
		const res = await link({ amount_off: 500 });
		assert.equal(res.status, 400);
		assert.equal((await res.json()).code, 'unknown_field');
	});

	test('lien admin : Stripe refuse le coupon (400), 422 coupon_invalid; Stripe en panne, 502', async () => {
		stripe.refuseDiscount = true;
		const res = await link();
		stripe.refuseDiscount = false;
		assert.equal(res.status, 422);
		assert.equal((await res.json()).code, 'coupon_invalid');
		stripe.failSession = true;
		const down = await link();
		stripe.failSession = false;
		assert.equal(down.status, 502);
	});

	test('lien admin : coupon absent 400; inconnu, éteint, expiré ou épuisé, même 422 coupon_invalid', async () => {
		assert.equal((await admin('/v1/admin/licence-links', 'POST', { offer: 'solo', locale: 'fr_CA', note: NOTE })).status, 400);
		await createCoupon({ code: 'ETEINT', percent_off: 10 });
		await createCoupon({ code: 'PERIME', percent_off: 10, expires_at: '2099-01-01T00:00:00Z' });
		await createCoupon({ code: 'UNSEUL', percent_off: 10, max_redemptions: 1 });
		assert.equal((await admin('/v1/admin/licence-coupons/ETEINT', 'DELETE')).status, 200);
		await t.sql`update licence_coupons set expires_at = now() - interval '1 day' where code = 'PERIME'`;
		const paid = {
			id: 'cs_test_cp_pay', object: 'checkout.session', payment_status: 'paid', payment_intent: 'pi_cp_pay', amount_total: 5520, currency: 'cad',
			metadata: { kind: 'licence', offer_id: offerId, offer_slug: 'solo', locale: 'fr_CA', coupon: 'UNSEUL' },
			total_details: { amount_discount: 690 },
			customer_details: { email: 'obnl@exemple.ca', address: { country: 'CA' } },
		};
		assert.equal((await webhook(paid)).status, 200);
		const before = stripe.sessions.length;
		const bodies = [];
		for (const coupon of ['INEXISTANT', 'ETEINT', 'PERIME', 'UNSEUL']) {
			const res = await link({ coupon });
			assert.equal(res.status, 422, coupon);
			bodies.push(await res.json());
		}
		assert.equal(new Set(bodies.map((body) => JSON.stringify(body))).size, 1);
		assert.equal(bodies[0].code, 'coupon_invalid');
		assert.equal(stripe.sessions.length, before);
	});

	test('achat réglé avec coupon : le code et le rabais sont gardés, le compte monte, un rejeu ne compte pas deux fois', async () => {
		const [row] = await t.sql`select coupon_code, discount_amount, amount_total, outcome from licence_purchases where checkout_session_id = 'cs_test_cp_pay'`;
		assert.equal(row.outcome, 'granted');
		assert.equal(row.coupon_code, 'UNSEUL');
		assert.equal(row.discount_amount, 690);
		assert.equal(row.amount_total, 5520);
		await webhook({ id: 'cs_test_cp_pay', object: 'checkout.session', payment_status: 'paid', payment_intent: 'pi_cp_pay', amount_total: 5520, currency: 'cad', metadata: { kind: 'licence', offer_id: offerId, offer_slug: 'solo', locale: 'fr_CA', coupon: 'UNSEUL' }, customer_details: { email: 'obnl@exemple.ca', address: { country: 'CA' } } });
		const list = await (await admin('/v1/admin/licence-coupons', 'GET')).json();
		const unseul = list.coupons.find((coupon) => coupon.code === 'UNSEUL');
		assert.equal(unseul.redeemed, 1);
		assert.equal(unseul.percent_off, 10);
	});

	test('achat sans rabais réel (métadonnée absente ou code inconnu) : aucun code gardé', async () => {
		const plain = {
			id: 'cs_test_cp_plain', object: 'checkout.session', payment_status: 'paid', payment_intent: 'pi_cp_plain', amount_total: 7934, currency: 'cad',
			metadata: { kind: 'licence', offer_id: offerId, offer_slug: 'solo', locale: 'fr_CA' },
			customer_details: { email: 'plein@exemple.ca', address: { country: 'CA' } },
		};
		assert.equal((await webhook(plain)).status, 200);
		const forged = { ...plain, id: 'cs_test_cp_forged', payment_intent: 'pi_cp_forged', customer_details: { email: 'faux@exemple.ca', address: { country: 'CA' } }, metadata: { ...plain.metadata, coupon: 'N\'IMPORTE QUOI' } };
		assert.equal((await webhook(forged)).status, 200);
		const rows = await t.sql`select checkout_session_id, coupon_code, discount_amount from licence_purchases where checkout_session_id in ('cs_test_cp_plain', 'cs_test_cp_forged') order by 1`;
		assert.deepEqual(rows.map((row) => [row.coupon_code, row.discount_amount]), [[null, 0], [null, 0]]);
	});

	test('plafond : un achat remboursé ne compte pas, le coupon redevient utilisable', async () => {
		await createCoupon({ code: 'PLAFOND', percent_off: 10, max_redemptions: 1 });
		const abroad = {
			id: 'cs_test_cp_abroad', object: 'checkout.session', payment_status: 'paid', payment_intent: 'pi_cp_abroad', amount_total: 6210, currency: 'cad',
			metadata: { kind: 'licence', offer_id: offerId, offer_slug: 'solo', locale: 'fr_CA', coupon: 'PLAFOND' },
			total_details: { amount_discount: 690 },
			customer_details: { email: 'ailleurs@exemple.ca', address: { country: 'FR' } },
		};
		assert.equal((await webhook(abroad)).status, 200);
		const [row] = await t.sql`select coupon_code, outcome from licence_purchases where checkout_session_id = 'cs_test_cp_abroad'`;
		assert.equal(row.coupon_code, 'PLAFOND');
		assert.notEqual(row.outcome, 'granted');
		assert.equal((await link({ coupon: 'PLAFOND' })).status, 201, 'le rachat remboursé libère la place');
		const granted = { ...abroad, id: 'cs_test_cp_inca', payment_intent: 'pi_cp_inca', customer_details: { email: 'ici@exemple.ca', address: { country: 'CA' } } };
		assert.equal((await webhook(granted)).status, 200);
		assert.equal((await link({ coupon: 'PLAFOND' })).status, 422, 'un achat réglé occupe la place');
	});

	test('règlement d\'un lien admin : licence granted, coupon_code et discount_amount gardés, note « Achat <slug> · <note> » (F-obnl-lien-03)', async () => {
		assert.equal((await link()).status, 201);
		const metadata = lastSession().metadata;
		const paid = {
			id: 'cs_test_cp_lien', object: 'checkout.session', payment_status: 'paid', payment_intent: 'pi_cp_lien', amount_total: 5520, currency: 'cad',
			metadata, total_details: { amount_discount: 1380 },
			customer_details: { email: 'lien@exemple.ca', address: { country: 'CA' } },
		};
		assert.equal((await webhook(paid)).status, 200);
		const [row] = await t.sql`
			select p.outcome, p.coupon_code, p.discount_amount, l.note
			from licence_purchases p join licences l on l.id = p.licence_id where p.checkout_session_id = 'cs_test_cp_lien'`;
		assert.equal(row.outcome, 'granted');
		assert.equal(row.coupon_code, 'OBNL2026');
		assert.equal(row.discount_amount, 1380);
		assert.equal(row.note, `Achat solo · ${NOTE}`);
	});

	test('règlement d\'un lien admin hors Canada : remboursement automatique, aucune licence (comportement inchangé)', async () => {
		assert.equal((await link()).status, 201);
		const paid = {
			id: 'cs_test_cp_lien_fr', object: 'checkout.session', payment_status: 'paid', payment_intent: 'pi_cp_lien_fr', amount_total: 5520, currency: 'cad',
			metadata: lastSession().metadata, total_details: { amount_discount: 1380 },
			customer_details: { email: 'lienfr@exemple.ca', address: { country: 'FR' } },
		};
		assert.equal((await webhook(paid)).status, 200);
		const [row] = await t.sql`select outcome, licence_id from licence_purchases where checkout_session_id = 'cs_test_cp_lien_fr'`;
		assert.notEqual(row.outcome, 'granted');
		assert.equal(row.licence_id, null);
	});

	test('licence-link (CLI) : sans --coupon ou --note, l\'aide s\'affiche et le code de sortie vaut 1', () => {
		const cli = new URL('../bin/admin.mjs', import.meta.url).pathname;
		const env = { PATH: process.env.PATH };
		for (const argv of [['licence-link', 'solo', '--note', NOTE], ['licence-link', 'solo', '--coupon', 'OBNL20'], ['licence-link', '--coupon', 'OBNL20', '--note', NOTE]]) {
			const run = spawnSync(process.execPath, [cli, ...argv], { env, encoding: 'utf8' });
			assert.equal(run.status, 1, argv.join(' '));
			assert.match(run.stderr, /licence-link <offre> --coupon/);
		}
	});

	// Le rabais OBNL vaut à chaque renouvellement tant que l'organisme est reconnu : le lien de
	// renouvellement (et donc les rappels, qui mènent au même lien) reporte le coupon de l'achat.
	describe('renouvellement', () => {
		const renewToken = (id, until) => createHmac('sha256', t.env.URL_SECRET).update(`renew\n${id}\n${until}`).digest('hex');
		const ips = new Set();
		const freshIp = () => {
			let ip;
			do { ip = `10.9.${Math.floor(Math.random() * 250)}.${Math.floor(Math.random() * 250)}`; } while (ips.has(ip));
			ips.add(ip);
			return ip;
		};
		const sellWith = async (id, email, metadata = {}) => {
			const session = {
				id, object: 'checkout.session', payment_status: 'paid', payment_intent: `pi_${id}`, amount_total: 5520, currency: 'cad',
				metadata: { kind: 'licence', offer_id: offerId, offer_slug: 'solo', locale: 'fr_CA', ...metadata },
				customer_details: { email, address: { country: 'CA' } },
			};
			assert.equal((await webhook(session)).status, 200);
			const [row] = await t.sql`select p.licence_id, p.coupon_code, l.expires_at from licence_purchases p join licences l on l.id = p.licence_id where p.checkout_session_id = ${id}`;
			return { licenceId: Number(row.licence_id), until: Math.floor(row.expires_at.getTime() / 1000), couponCode: row.coupon_code };
		};
		const renew = async ({ licenceId, until }) => {
			const res = await t.request(`/v1/licences/renew?licence=${licenceId}&until=${until}&token=${renewToken(licenceId, until)}`, { headers: { 'X-Forwarded-For': freshIp() }, redirect: 'manual' });
			assert.equal(res.status, 303);
			return lastSession();
		};

		test('licence achetée avec un coupon : la session de renouvellement le reporte', async () => {
			await createCoupon({ code: 'RENOUV', percent_off: 20 });
			// Achat fait par un lien admin : mêmes métadonnées que la session signée à la main.
			assert.equal((await link({ coupon: 'RENOUV' })).status, 201);
			const { kind, offer_id: offerIdMeta, ...fromLink } = lastSession().metadata;
			assert.equal(kind, 'licence');
			assert.equal(offerIdMeta, offerId);
			assert.equal(fromLink.link, 'admin');
			const sold = await sellWith('cs_test_cp_renew1', 'renouv1@exemple.ca', fromLink);
			assert.equal(sold.couponCode, 'RENOUV');
			const [{ stripe_coupon_id: stripeCoupon }] = await t.sql`select stripe_coupon_id from licence_coupons where code = 'RENOUV'`;
			const session = await renew(sold);
			assert.equal(session.metadata.kind, 'licence_renewal');
			assert.deepEqual(session.discounts, [{ coupon: stripeCoupon }]);
		});

		test('coupon éteint, expiré ou épuisé depuis l\'achat : droit acquis, le rabais suit quand même', async () => {
			const sold = await sellWith('cs_test_cp_renew2', 'renouv2@exemple.ca', { coupon: 'RENOUV' });
			const [{ stripe_coupon_id: stripeCoupon }] = await t.sql`select stripe_coupon_id from licence_coupons where code = 'RENOUV'`;
			assert.equal((await admin('/v1/admin/licence-coupons/RENOUV', 'DELETE')).status, 200);
			await t.sql`update licence_coupons set expires_at = now() - interval '1 day', max_redemptions = 1 where code = 'RENOUV'`;
			const session = await renew(sold);
			assert.deepEqual(session.discounts, [{ coupon: stripeCoupon }]);
		});

		test('Stripe refuse le coupon au renouvellement : session au plein prix plutôt qu\'un paiement impossible, avec alerte', async () => {
			const sold = await sellWith('cs_test_cp_renew4', 'renouv4@exemple.ca', { coupon: 'RENOUV' });
			const alertsBefore = t.alerts.length;
			stripe.refuseDiscount = true;
			try {
				const session = await renew(sold);
				assert.equal('discounts' in session, false);
				assert.equal(session.metadata.kind, 'licence_renewal');
			} finally {
				stripe.refuseDiscount = false;
			}
			assert.ok(t.alerts.slice(alertsBefore).some((a) => a.subject.startsWith('Renouvellement : coupon refusé par Stripe')));
		});

		test('licence achetée sans coupon : aucun rabais au renouvellement', async () => {
			const sold = await sellWith('cs_test_cp_renew3', 'renouv3@exemple.ca');
			assert.equal(sold.couponCode, null);
			assert.equal('discounts' in await renew(sold), false);
		});
	});
});
