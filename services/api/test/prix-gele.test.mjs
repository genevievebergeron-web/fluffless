// Prix gelé (F-prix-gele, décision 32) : le prix de l'achat, avant taxes, suit la licence au
// renouvellement jusqu'à 30 jours après la fin (ou après le dernier rétablissement); au-delà,
// le prix du jour, qui devient le nouveau prix gelé. Stripe simulé, aucune requête vers l'extérieur.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac, randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fakeZip, startTestServer } from './helpers.mjs';

const skip = process.env.API_TEST_SKIP_DB === '1' ? 'API_TEST_SKIP_DB=1' : false;
const DAY = 86_400_000;
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
	const price = (id, amount) => ({ id, active: true, type: 'one_time', unit_amount: amount, currency: 'cad', tax_behavior: 'exclusive', product: 'prod_suite' });
	const fake = {
		sessions: [],
		prices: { price_p69: price('price_p69', 6900), price_p79: price('price_p79', 7900) },
		async createCheckoutSession(params) {
			fake.sessions.push(params);
			return { id: `cs_test_pg_${fake.sessions.length}`, url: `https://checkout.stripe.com/c/pay/cs_test_pg_${fake.sessions.length}` };
		},
		async retrievePrice(id) {
			if (!fake.prices[id]) {
				throw Object.assign(new Error('absent'), { status: 404, code: 'resource_missing' });
			}
			return fake.prices[id];
		},
		async expireCheckoutSession(id) {
			return { id, status: 'expired' };
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

describe('prix gelé au renouvellement', { skip }, () => {
	let t;
	let stripe;
	let offerId;
	let counter = 0;
	let ip = 0;
	const admin = (path, method, body) => t.request(path, { method, token: t.env.ADMIN_TOKEN, body });
	const setPrice = async (priceId) => assert.equal((await admin('/v1/admin/licence-offers/solo/price', 'PUT', { price_id: priceId })).status, 200);
	const webhook = (object) => {
		const payload = JSON.stringify({ id: `evt_pg_${++counter}`, type: 'checkout.session.completed', data: { object } });
		return t.request('/v1/webhooks/stripe', { method: 'POST', body: payload, headers: { 'Stripe-Signature': sign(payload) } });
	};
	const renewToken = (id, until) => createHmac('sha256', t.env.URL_SECRET).update(`renew\n${id}\n${until}`).digest('hex');
	const untilOf = async (id) => Math.floor((await t.sql`select expires_at from licences where id = ${id}`)[0].expires_at.getTime() / 1000);
	const renew = async (id) => {
		const until = await untilOf(id);
		return t.request(`/v1/licences/renew?licence=${id}&until=${until}&token=${renewToken(id, until)}`, { headers: { 'X-Forwarded-For': `10.7.0.${++ip}` }, redirect: 'manual' });
	};
	const lastSession = () => stripe.sessions.at(-1);
	const setExpiry = (id, days) => t.sql`update licences set expires_at = ${new Date(Date.now() + days * DAY)} where id = ${id}`;
	// Achat complet : session Checkout par la route publique, puis règlement avec ses métadonnées.
	const sell = async (extra = {}) => {
		const res = await t.request('/v1/licences/checkout', { method: 'POST', body: { offer: 'solo', locale: 'fr_CA' }, headers: { 'X-Forwarded-For': `10.8.0.${++ip}` } });
		assert.equal(res.status, 201);
		const params = lastSession();
		const n = ++counter;
		const session = {
			id: `cs_test_pg_buy_${n}`, object: 'checkout.session', payment_status: 'paid', payment_intent: `pi_pg_${n}`,
			amount_total: 7934, amount_subtotal: 6900, currency: 'cad', metadata: params.metadata,
			customer_details: { email: `pg${n}@exemple.ca`, address: { country: 'CA' } },
			...extra,
		};
		assert.equal((await webhook(session)).status, 200);
		const [row] = await t.sql`select licence_id from licence_purchases where checkout_session_id = ${session.id}`;
		const key = t.licenceDeliveries.filter((m) => m.to === session.customer_details.email).at(-1)?.key;
		return { id: Number(row.licence_id), session, key };
	};
	const payRenewal = async (id, fields = {}) => {
		const params = lastSession();
		assert.equal(params.metadata.kind, 'licence_renewal');
		assert.equal(params.metadata.licence_id, String(id));
		const n = ++counter;
		const session = {
			id: `cs_test_pg_renew_${n}`, object: 'checkout.session', payment_status: 'paid', payment_intent: `pi_pg_r${n}`,
			amount_total: 7934, amount_subtotal: params.metadata.unit_amount ? Number(params.metadata.unit_amount) : 6900, currency: 'cad',
			metadata: params.metadata, customer_details: { email: 'renouv@exemple.ca', address: { country: 'CA' } },
			...fields,
		};
		assert.equal((await webhook(session)).status, 200);
		return session;
	};
	const frozen = async (id) => (await t.sql`
		select renewal_price_id, renewal_product_id, renewal_unit_amount, renewal_currency from licences where id = ${id}`)[0];

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
		offerId = String((await t.sql`select id from licence_offers where slug = 'solo'`)[0].id);
	});
	after(async () => t?.stop());

	describe('achat et lien de renouvellement', () => {
		before(async () => setPrice('price_p69'));

		test('8. achat : la session porte le prix, la licence le garde; un rejeu ne change rien', async () => {
			const { id, session } = await sell();
			assert.equal(session.metadata.price_id, 'price_p69');
			assert.equal(session.metadata.unit_amount, '6900');
			assert.equal(session.metadata.product_id, 'prod_suite');
			assert.deepEqual({ ...await frozen(id) }, { renewal_price_id: 'price_p69', renewal_product_id: 'prod_suite', renewal_unit_amount: 6900, renewal_currency: 'cad' });
			await setPrice('price_p79');
			assert.equal((await webhook(session)).status, 200);
			assert.equal((await frozen(id)).renewal_price_id, 'price_p69');
			await setPrice('price_p69');
		});

		test('1. prix de l\'offre changé : le renouvellement reprend le prix de l\'achat', async () => {
			const { id } = await sell();
			await setPrice('price_p79');
			try {
				assert.equal((await renew(id)).status, 303);
				const params = lastSession();
				assert.equal(params.line_items[0].price, 'price_p69');
				assert.equal(params.metadata.price_basis, 'gele');
				assert.equal(params.metadata.unit_amount, '6900');
			} finally {
				await setPrice('price_p69');
			}
		});

		test('2. offre retirée de la vente pendant la licence : lien au prix gelé quand même', async () => {
			const { id } = await sell();
			assert.equal((await admin('/v1/admin/licence-offers/solo/price', 'PUT', { price_id: null })).status, 200);
			try {
				assert.equal((await renew(id)).status, 303);
				assert.equal(lastSession().line_items[0].price, 'price_p69');
			} finally {
				await setPrice('price_p69');
			}
		});

		test('3. rappel à 30 jours, offre retirée : le courriel porte le lien', async () => {
			const { id, session } = await sell();
			await setExpiry(id, 20);
			assert.equal((await admin('/v1/admin/licence-offers/solo/price', 'PUT', { price_id: null })).status, 200);
			try {
				await t.sendRenewalReminders();
				const mail = t.reminders.filter((m) => m.to === session.customer_details.email).at(-1);
				assert.ok(mail, 'rappel envoyé');
				assert.match(mail.renewUrl ?? '', /\/v1\/licences\/renew\?licence=/);
			} finally {
				await setPrice('price_p69');
			}
		});

		test('4. page d\'expiration dans la grâce, offre retirée : lien et date limite', async () => {
			const { id, key } = await sell();
			await setExpiry(id, -3);
			assert.equal((await admin('/v1/admin/licence-offers/solo/price', 'PUT', { price_id: null })).status, 200);
			try {
				const res = await t.request('/v1/licences/download', { method: 'POST', body: `key=${key}`, headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Forwarded-For': `10.9.0.${++ip}` } });
				assert.equal(res.status, 403);
				const html = await res.text();
				assert.match(html, /licences\/renew\?licence=/);
				assert.match(html, /Renouvelle d'ici le \d{1,2}(er)? \p{L}+ \d{4} pour garder ton prix/u);
				assert.match(html, /Renew by \p{L}+ \d{1,2}, \d{4} to keep your price/u);
			} finally {
				await setPrice('price_p69');
			}
		});

		test('5. fin dépassée de 31 jours : prix du jour, base « jour », avec le prix complet en métadonnées', async () => {
			const { id } = await sell();
			await setExpiry(id, -31);
			await setPrice('price_p79');
			try {
				assert.equal((await renew(id)).status, 303);
				const params = lastSession();
				assert.equal(params.line_items[0].price, 'price_p79');
				assert.equal(params.metadata.price_basis, 'jour');
				assert.equal(params.metadata.price_id, 'price_p79');
				assert.equal(params.metadata.unit_amount, '7900');
				assert.equal(params.metadata.product_id, 'prod_suite');
			} finally {
				await setPrice('price_p69');
			}
		});

		test('fin à 30 jours pile : borne incluse, prix gelé', async () => {
			const { id } = await sell();
			await setExpiry(id, -30 + 1 / 1440);
			await setPrice('price_p79');
			try {
				assert.equal((await renew(id)).status, 303);
				assert.equal(lastSession().line_items[0].price, 'price_p69');
			} finally {
				await setPrice('price_p69');
			}
		});

		test('6. fin dépassée de 31 jours, offre retirée : page lisible bilingue, aucune session', async () => {
			const { id } = await sell();
			await setExpiry(id, -31);
			assert.equal((await admin('/v1/admin/licence-offers/solo/price', 'PUT', { price_id: null })).status, 200);
			try {
				const before = stripe.sessions.length;
				const res = await renew(id);
				assert.equal(res.status, 404);
				assert.match(res.headers.get('content-type'), /text\/html/);
				assert.equal(stripe.sessions.length, before);
			} finally {
				await setPrice('price_p69');
			}
		});

		test('7. prix gelé archivé chez Stripe : repli price_data au même montant, taxes en sus, alerte', async () => {
			const { id } = await sell();
			const alerts = t.alerts.length;
			stripe.prices.price_p69.active = false;
			try {
				assert.equal((await renew(id)).status, 303);
				const item = lastSession().line_items[0];
				assert.equal(item.price, undefined);
				assert.deepEqual(item.price_data, { currency: 'cad', product: 'prod_suite', unit_amount: 6900, tax_behavior: 'exclusive' });
				assert.ok(t.alerts.slice(alerts).some((a) => a.subject.startsWith('Prix gelé archivé chez Stripe')));
			} finally {
				stripe.prices.price_p69.active = true;
			}
		});

		test('9. règlement : à la base « jour » le prix payé devient le prix gelé; à la base « gele », rien ne change', async () => {
			const gele = await sell();
			await setExpiry(gele.id, 10);
			await setPrice('price_p79');
			try {
				assert.equal((await renew(gele.id)).status, 303);
				const paid = await payRenewal(gele.id);
				assert.equal((await frozen(gele.id)).renewal_price_id, 'price_p69');
				const [row] = await t.sql`select outcome, unit_amount, price_basis from licence_renewals where checkout_session_id = ${paid.id}`;
				assert.deepEqual({ ...row }, { outcome: 'granted', unit_amount: 6900, price_basis: 'gele' });
				const jour = await sell();
				await setExpiry(jour.id, -40);
				assert.equal((await renew(jour.id)).status, 303);
				const paidJour = await payRenewal(jour.id);
				assert.deepEqual({ ...await frozen(jour.id) }, { renewal_price_id: 'price_p79', renewal_product_id: 'prod_suite', renewal_unit_amount: 7900, renewal_currency: 'cad' });
				const [rowJour] = await t.sql`select unit_amount, price_basis from licence_renewals where checkout_session_id = ${paidJour.id}`;
				assert.deepEqual({ ...rowJour }, { unit_amount: 7900, price_basis: 'jour' });
			} finally {
				await setPrice('price_p69');
			}
		});

		test('10. sous-total différent du prix attendu : alerte, licence prolongée quand même', async () => {
			const { id } = await sell();
			await setExpiry(id, 10);
			const before = await untilOf(id);
			assert.equal((await renew(id)).status, 303);
			const alerts = t.alerts.length;
			await payRenewal(id, { amount_subtotal: 5000 });
			assert.ok(await untilOf(id) > before);
			assert.ok(t.alerts.slice(alerts).some((a) => a.subject.startsWith('Écart de prix')));
			const buyAlerts = t.alerts.length;
			await sell({ amount_subtotal: 100 });
			assert.ok(t.alerts.slice(buyAlerts).some((a) => a.subject.startsWith('Écart de prix')));
		});

		test('13 et 14. révoquée puis rétablie : la grâce part du rétablissement; sans rétablissement, prix du jour', async () => {
			const restored = await sell();
			await setExpiry(restored.id, -45);
			await t.sql`update licences set restored_at = ${new Date(Date.now() - 5 * DAY)} where id = ${restored.id}`;
			const plain = await sell();
			await setExpiry(plain.id, -45);
			await setPrice('price_p79');
			try {
				assert.equal((await renew(restored.id)).status, 303);
				assert.equal(lastSession().line_items[0].price, 'price_p69');
				assert.equal(lastSession().metadata.price_basis, 'gele');
				assert.equal((await renew(plain.id)).status, 303);
				assert.equal(lastSession().line_items[0].price, 'price_p79');
				assert.equal(lastSession().metadata.price_basis, 'jour');
			} finally {
				await setPrice('price_p69');
			}
		});

		test('15. licence échue depuis 10 jours : licence_expired porte grace_until et renew_url; à 31 jours, ni l\'un ni l\'autre', async () => {
			const { id, key } = await sell();
			await setExpiry(id, -10);
			const verify = () => t.request('/v1/licences/verify', { method: 'POST', body: { site: 'b'.repeat(64) }, headers: { 'X-Fluffless-Licence': key } });
			const body = await (await verify()).json();
			assert.equal(body.code, 'licence_expired');
			const [{ expires_at: end }] = await t.sql`select expires_at from licences where id = ${id}`;
			assert.equal(body.grace_until, new Date(end.getTime() + 30 * DAY).toISOString());
			assert.match(body.renew_url, new RegExp(`/v1/licences/renew\\?licence=${id}&until=\\d+&token=[0-9a-f]{64}$`));
			await setExpiry(id, -31);
			const late = await (await verify()).json();
			assert.equal(late.code, 'licence_expired');
			assert.equal('grace_until' in late, false);
			assert.equal('renew_url' in late, false);
		});

		test('15b. /v1/update pendant la grâce : le 403 licence_expired porte grace_until et renew_url; à 31 jours, le code seul', async () => {
			const { id, key } = await sell();
			await setExpiry(id, -10);
			const update = async () => {
				const res = await t.request(`/v1/update?${new URLSearchParams({ slug: 'fluffless-suite', version: '0.0.1', site: 'c'.repeat(64) })}`, {
					headers: { 'X-Fluffless-Licence': key },
				});
				assert.equal(res.status, 403);
				return res.json();
			};
			const body = await update();
			assert.equal(body.code, 'licence_expired');
			const [{ expires_at: end }] = await t.sql`select expires_at from licences where id = ${id}`;
			assert.equal(body.expires, end.toISOString());
			assert.equal(body.grace_until, new Date(end.getTime() + 30 * DAY).toISOString());
			assert.match(body.renew_url, new RegExp(`/v1/licences/renew\\?licence=${id}&until=\\d+&token=[0-9a-f]{64}$`));
			await setExpiry(id, -31);
			assert.deepEqual(await update(), { code: 'licence_expired' });
		});
	});

	describe('remboursement d\'un renouvellement (renewal-refund)', () => {
		before(async () => setPrice('price_p69'));
		const refund = (sessionId) => admin(`/v1/admin/licence-renewals/${sessionId}/refund`, 'POST', {});

		test('11. la fin revient à la période d\'avant, la ligne passe à refunded, un second appel ne fait rien', async () => {
			const { id } = await sell();
			await setExpiry(id, 10);
			const periodFrom = await untilOf(id);
			assert.equal((await renew(id)).status, 303);
			const paid = await payRenewal(id);
			assert.ok(await untilOf(id) > periodFrom);
			const res = await refund(paid.id);
			assert.equal(res.status, 200);
			const body = await res.json();
			assert.equal(await untilOf(id), periodFrom);
			assert.equal(body.expires_at, new Date(periodFrom * 1000).toISOString());
			// 18. Un nouveau lien pour la fin rétablie : l'ancien est mort avec l'ancienne fin.
			assert.match(body.renew_url, new RegExp(`/v1/licences/renew\\?licence=${id}&until=${periodFrom}&token=[0-9a-f]{64}$`));
			// 18 (suite). Le nouveau lien part aussi par courriel à l'adresse de la licence, avec la date limite du prix.
			assert.equal(body.mail_sent, true);
			const [{ email }] = await t.sql`select email from licences where id = ${id}`;
			const notice = t.refundNotices.filter((m) => m.to === email).at(-1);
			assert.ok(notice, 'courriel du nouveau lien');
			assert.equal(notice.renewUrl, body.renew_url);
			assert.equal(notice.locale, 'fr_CA');
			assert.equal(notice.expires.toISOString(), body.expires_at);
			assert.equal(notice.graceUntil.getTime(), periodFrom * 1000 + 30 * DAY);
			assert.equal((await t.sql`select outcome from licence_renewals where checkout_session_id = ${paid.id}`)[0].outcome, 'refunded');
			assert.equal((await frozen(id)).renewal_price_id, 'price_p69', 'le prix gelé ne change pas');
			const again = await refund(paid.id);
			assert.equal(again.status, 409);
			assert.equal((await again.json()).code, 'not_granted');
			assert.equal(await untilOf(id), periodFrom);
		});

		test('17. seulement le dernier renouvellement : un plus ancien répond 409 not_last_renewal', async () => {
			const { id } = await sell();
			await setExpiry(id, 10);
			assert.equal((await renew(id)).status, 303);
			const first = await payRenewal(id);
			assert.equal((await renew(id)).status, 303);
			const second = await payRenewal(id);
			const end = await untilOf(id);
			const res = await refund(first.id);
			assert.equal(res.status, 409);
			assert.equal((await res.json()).code, 'not_last_renewal');
			assert.equal(await untilOf(id), end);
			assert.equal((await refund(second.id)).status, 200);
			assert.equal((await refund('cs_test_inconnu')).status, 404);
			assert.equal((await t.request(`/v1/admin/licence-renewals/${second.id}/refund`, { method: 'POST', body: {} })).status, 401);
		});

		test('18. courriel en échec : le remboursement tient, la réponse dit mail_sent false', async () => {
			const { id } = await sell();
			await setExpiry(id, 10);
			const periodFrom = await untilOf(id);
			assert.equal((await renew(id)).status, 303);
			const paid = await payRenewal(id);
			t.setRefundNotice(async () => { throw Object.assign(new Error('boom'), { responseCode: 421 }); });
			try {
				const res = await refund(paid.id);
				assert.equal(res.status, 200);
				const body = await res.json();
				assert.equal(body.mail_sent, false);
				assert.ok(body.renew_url);
				assert.equal(await untilOf(id), periodFrom);
				assert.ok(t.logs.some((l) => l.event === 'renouvellement_defait_courriel_echec' && l.error === 421));
			} finally {
				t.setRefundNotice();
			}
		});

		test('fin d\'avant déjà passée : la licence prend fin maintenant', async () => {
			const { id } = await sell();
			await setExpiry(id, -5);
			assert.equal((await renew(id)).status, 303);
			const paid = await payRenewal(id);
			assert.equal((await refund(paid.id)).status, 200);
			const end = (await untilOf(id)) * 1000;
			assert.ok(Math.abs(end - Date.now()) < 60_000, String(end - Date.now()));
		});
	});

	test('12. migration 013 : remplit les licences vendues, laisse les manuelles vides, rejouée ne change rien', async () => {
		await setPrice('price_p69');
		const { id } = await sell();
		const manual = await (await admin('/v1/admin/licences', 'POST', { plan: 'solo', sites_max: 1, expires: '2030-01-01T00:00:00Z' })).json();
		await t.sql`update licences set renewal_price_id = null, renewal_product_id = null, renewal_unit_amount = null, renewal_currency = null where id = ${id}`;
		const source = readFileSync(new URL('../migrations/013_prix_gele.sql', import.meta.url), 'utf8');
		const fill = source.slice(source.indexOf('-- remplissage'));
		await t.sql.unsafe(fill);
		assert.deepEqual({ ...await frozen(id) }, { renewal_price_id: 'price_p69', renewal_product_id: 'prod_suite', renewal_unit_amount: 6900, renewal_currency: 'cad' });
		assert.equal((await frozen(manual.id)).renewal_price_id, null);
		await t.sql`update licences set renewal_unit_amount = 6800 where id = ${id}`;
		await t.sql.unsafe(fill);
		assert.equal((await frozen(id)).renewal_unit_amount, 6800, 'colonnes déjà remplies : intouchées');
	});

	test('licence-renewal-refund (CLI) : sans identifiant de session, l\'aide et le code 1', async () => {
		const { spawnSync } = await import('node:child_process');
		const run = spawnSync(process.execPath, [new URL('../bin/admin.mjs', import.meta.url).pathname, 'renewal-refund'], { env: { PATH: process.env.PATH }, encoding: 'utf8' });
		assert.equal(run.status, 1);
		assert.match(run.stderr, /renewal-refund <checkout_session_id>/);
	});
	void offerId;
});
