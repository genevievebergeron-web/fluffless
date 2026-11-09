// Vente des licences par Stripe : offres, session Checkout sans compte, règlement idempotent,
// livraison de la clé par courriel, relivraison, renvoi par l'administration et page de
// téléchargement où la cliente colle sa clé. Stripe est simulé.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { fakeZip, startTestServer } from './helpers.mjs';

const skip = process.env.API_TEST_SKIP_DB === '1' ? 'API_TEST_SKIP_DB=1' : false;
const DAY = 86_400_000;
const WEBHOOK_SECRET = 'whsec_testtesttesttesttest';
const STRIPE_ENV = {
	STRIPE_SECRET_KEY: 'sk_test_abcdefghijklmnop',
	STRIPE_WEBHOOK_SECRET: WEBHOOK_SECRET,
	STRIPE_SUCCESS_URL: 'https://fluffless.dev/merci?session={CHECKOUT_SESSION_ID}',
	STRIPE_CANCEL_URL: 'https://fluffless.dev/acheter',
	// Faux : la livraison est simulée, mais la vente exige un SMTP configuré.
	PROTON_SMTP_USER: 'vente@exemple.ca',
	PROTON_SMTP_TOKEN: 'faux-jeton-smtp',
};
const KEY_FORMAT = /^FL-[0-9A-F]{8}-[0-9A-F]{8}-[0-9A-F]{8}-[0-9A-F]{8}$/;
const SITE = 'a'.repeat(64);

function createFakeStripe() {
	const fake = {
		sessions: [],
		refunds: [],
		listed: [],
		prices: {
			price_lic123: { id: 'price_lic123', active: true, type: 'one_time', unit_amount: 4900, currency: 'cad', tax_behavior: 'exclusive' },
			price_abo123: { id: 'price_abo123', active: true, type: 'recurring', unit_amount: 900, currency: 'cad', tax_behavior: 'exclusive' },
		},
		async createCheckoutSession(params) {
			fake.sessions.push(params);
			return { id: `cs_test_${fake.sessions.length}`, url: `https://checkout.stripe.com/c/pay/cs_test_${fake.sessions.length}` };
		},
		async expireCheckoutSession(id) {
			return { id, status: 'expired' };
		},
		async retrievePrice(id) {
			if (!fake.prices[id]) {
				throw Object.assign(new Error('absent'), { status: 404, code: 'resource_missing' });
			}
			return fake.prices[id];
		},
		async createRefund(params, idempotencyKey) {
			const existing = fake.refunds.find((refund) => refund.idempotencyKey === idempotencyKey);
			if (existing) {
				return existing;
			}
			const refund = { id: `re_${fake.refunds.length + 1}`, params, idempotencyKey };
			fake.refunds.push(refund);
			return refund;
		},
		async listRefunds() {
			return { data: [] };
		},
		async listCheckoutSessions() {
			return { data: fake.listed, has_more: false };
		},
	};
	return fake;
}

const sign = (payload, timestamp = Math.floor(Date.now() / 1000)) =>
	`t=${timestamp},v1=${createHmac('sha256', WEBHOOK_SECRET).update(`${timestamp}.${payload}`).digest('hex')}`;

describe('vente des licences par Stripe', { skip }, () => {
	let t;
	let token;
	let stripe;
	let offerId;
	let ipCounter = 0;
	let counter = 0;
	const freshIp = () => `198.51.100.${++ipCounter}`;
	const admin = (path, method, body) => t.request(path, { method, token, body });
	const checkout = (body = {}) => t.request('/v1/licences/checkout', {
		method: 'POST',
		body: { offer: 'solo', locale: 'fr_CA', ...body },
		headers: { 'X-Forwarded-For': freshIp() },
	});
	const webhook = (event) => {
		const payload = JSON.stringify(event);
		return t.request('/v1/webhooks/stripe', { method: 'POST', body: payload, headers: { 'Stripe-Signature': sign(payload) } });
	};
	const paidSession = (fields = {}) => {
		const n = ++counter;
		return {
			id: `cs_test_lic_${n}`,
			object: 'checkout.session',
			payment_status: 'paid',
			payment_intent: `pi_lic_${n}`,
			amount_total: 5634,
			currency: 'cad',
			metadata: { kind: 'licence', offer_id: offerId, offer_slug: 'solo', locale: 'fr_CA' },
			customer_details: { email: `cliente${n}@exemple.ca`, address: { country: 'CA' } },
			...fields,
		};
	};
	const event = (object, type = 'checkout.session.completed') => ({ id: `evt_lic_${++counter}`, type, data: { object } });
	const purchase = async (sessionId) => (await t.sql`select * from licence_purchases where checkout_session_id = ${sessionId}`)[0];
	const verify = (key) => t.request('/v1/licences/verify', { method: 'POST', body: { site: SITE }, headers: { 'X-Fluffless-Licence': key } });
	const download = (body, type = 'application/x-www-form-urlencoded') => t.request('/v1/licences/download', {
		method: 'POST',
		body,
		headers: { 'Content-Type': type, 'X-Forwarded-For': freshIp() },
	});

	before(async () => {
		stripe = createFakeStripe();
		t = await startTestServer({ env: STRIPE_ENV, stripe });
		token = t.env.ADMIN_TOKEN;
		const release = await admin('/v1/admin/releases', 'POST', {
			slug: 'fluffless-suite',
			version: '1.0.0',
			channel: 'stable',
			name: 'Fluffless Suite',
			description: { fr: 'Outils', en: 'Tools' },
			changelog: { fr: 'Première', en: 'First' },
			zip_base64: fakeZip('suite').toString('base64'),
		});
		assert.equal(release.status, 201);
	});
	after(async () => t?.stop());

	test('offres : création, prix vérifié chez Stripe, liste', async () => {
		const created = await admin('/v1/admin/licence-offers', 'POST', { slug: 'solo', plan: 'solo', sites_max: 1, duration_days: 365 });
		assert.equal(created.status, 200);
		assert.deepEqual((await created.json()).release_slugs, ['fluffless-suite']);
		offerId = String((await t.sql`select id from licence_offers where slug = 'solo'`)[0].id);
		// Sans prix, l'offre n'est pas en vente.
		assert.equal((await checkout()).status, 404);
		assert.equal((await admin('/v1/admin/licence-offers/solo/price', 'PUT', { price_id: 'price_abo123' })).status, 400);
		assert.equal((await admin('/v1/admin/licence-offers/absente/price', 'PUT', { price_id: 'price_lic123' })).status, 404);
		const priced = await admin('/v1/admin/licence-offers/solo/price', 'PUT', { price_id: 'price_lic123' });
		assert.equal(priced.status, 200);
		assert.equal((await priced.json()).price_amount, 4900);
		// Mise à jour : le plan change, le prix reste.
		const updated = await (await admin('/v1/admin/licence-offers', 'POST', { slug: 'solo', plan: 'solo', sites_max: 2, duration_days: 365 })).json();
		assert.equal(updated.sites_max, 2);
		assert.equal(updated.stripe_price_id, 'price_lic123');
		await admin('/v1/admin/licence-offers', 'POST', { slug: 'solo', plan: 'solo', sites_max: 1, duration_days: 365 });
		const list = await (await admin('/v1/admin/licence-offers', 'GET')).json();
		assert.equal(list.offers.length, 1);
		assert.equal(list.offers[0].sold, 0);
		assert.equal((await t.request('/v1/admin/licence-offers')).status, 401);
	});

	test('checkout : session sans compte, Canada seulement, conditions de vente, pot de miel', async () => {
		const before = stripe.sessions.length;
		const res = await checkout({ locale: 'en_CA' });
		assert.equal(res.status, 201);
		assert.match((await res.json()).url, /^https:\/\/checkout\.stripe\.com\//);
		const params = stripe.sessions.at(-1);
		assert.deepEqual(params.metadata, { kind: 'licence', offer_id: offerId, offer_slug: 'solo', locale: 'en_CA', price_id: 'price_lic123', unit_amount: '4900' });
		assert.deepEqual(params.payment_intent_data.metadata, params.metadata);
		assert.deepEqual(params.line_items, [{ price: 'price_lic123', quantity: 1 }]);
		assert.deepEqual(params.automatic_tax, { enabled: true });
		assert.equal(params.billing_address_collection, 'required');
		assert.deepEqual(params.consent_collection, { terms_of_service: 'required' });
		assert.match(params.custom_text.submit.message, /Canada only/);
		assert.equal(params.locale, 'en');
		assert.equal(params.success_url, 'https://fluffless.dev/merci?session={CHECKOUT_SESSION_ID}&kind=licence');
		assert.equal(params.cancel_url, 'https://fluffless.dev/acheter');
		assert.equal(params.customer_email, undefined);
		// Pages anglaises facultatives : un paiement en anglais y revient, un paiement en français non.
		t.config.stripe.successUrlEn = 'https://fluffless.dev/en/thank-you/?session={CHECKOUT_SESSION_ID}';
		t.config.stripe.cancelUrlEn = 'https://fluffless.dev/en/buy/';
		await checkout({ locale: 'en_CA' });
		assert.equal(stripe.sessions.at(-1).success_url, 'https://fluffless.dev/en/thank-you/?session={CHECKOUT_SESSION_ID}&kind=licence');
		assert.equal(stripe.sessions.at(-1).cancel_url, 'https://fluffless.dev/en/buy/');
		await checkout({ locale: 'fr_CA' });
		assert.equal(stripe.sessions.at(-1).success_url, 'https://fluffless.dev/merci?session={CHECKOUT_SESSION_ID}&kind=licence');
		assert.equal(stripe.sessions.at(-1).cancel_url, 'https://fluffless.dev/acheter');
		t.config.stripe.successUrlEn = '';
		t.config.stripe.cancelUrlEn = '';
		const sessionsBefore = stripe.sessions.length;
		// Pot de miel : réponse d'apparence normale, aucune session.
		const bot = await checkout({ website: 'http://spam.example' });
		assert.equal(bot.status, 201);
		assert.equal(stripe.sessions.length, sessionsBefore);
		assert.equal(stripe.sessions.length, before + 3);
		assert.equal((await checkout({ autre: 1 })).status, 400);
		assert.equal((await checkout({ offer: 'inconnue' })).status, 404);
		// Sans SMTP, ni la clé ni l'alerte ne partiraient : vente fermée.
		t.config.smtp.token = '';
		assert.equal((await checkout()).status, 503);
		t.config.smtp.token = 'faux-jeton-smtp';
	});

	let soldKey;
	let soldLicenceId;
	test('paiement : licence créée, clé livrée par courriel, une seule fois malgré les rejeux', async () => {
		const session = paidSession();
		const statuses = await Promise.all(Array.from({ length: 6 }, () => webhook(event(session)).then((r) => r.status)));
		assert.ok(statuses.every((s) => s === 200 || s === 409), statuses.join(','));
		const row = await purchase(session.id);
		assert.equal(row.outcome, 'granted');
		assert.ok(row.delivered_at);
		const mails = t.licenceDeliveries.filter((m) => m.to === session.customer_details.email);
		assert.equal(mails.length, 1);
		const [mail] = mails;
		assert.match(mail.key, KEY_FORMAT);
		assert.equal(mail.url, `${t.config.publicUrl}/v1/licences/download`);
		assert.equal(mail.locale, 'fr_CA');
		const [licence] = await t.sql`select * from licences where id = ${row.licence_id}`;
		assert.equal(licence.plan, 'solo');
		assert.equal(licence.sites_max, 1);
		assert.equal(licence.email, session.customer_details.email);
		const days = (licence.expires_at.getTime() - Date.now()) / DAY;
		assert.ok(days > 364 && days <= 365, String(days));
		// La clé reçue ouvre la licence; la base n'en garde que le hachage.
		assert.equal((await (await verify(mail.key)).json()).valid, true);
		assert.notEqual(licence.key_hash, mail.key);
		soldKey = mail.key;
		soldLicenceId = Number(row.licence_id);
		assert.equal((await t.sql`select count(*)::int as n from licences where email = ${session.customer_details.email}`)[0].n, 1);
	});

	test('page de téléchargement : formulaire, zip signé avec la clé, refus sinon', async () => {
		const page = await t.request('/v1/licences/download');
		assert.equal(page.status, 200);
		assert.match(page.headers.get('content-type'), /text\/html/);
		assert.match(page.headers.get('content-security-policy'), /form-action 'self'/);
		const html = await page.text();
		assert.match(html, /<form method="post">/);
		assert.match(html, /name="key"/);
		const res = await download(`key=${encodeURIComponent(` ${soldKey.toLowerCase()} `)}`);
		assert.equal(res.status, 200);
		// Renvoi (303) vers le lien signé de 15 minutes, qui seul prend une place de téléchargement.
		assert.ok(res.redirected);
		assert.match(res.url, /\/v1\/download\/fluffless-suite\/1\.0\.0\/\d+\/[0-9a-f]+\.zip$/);
		assert.equal(res.headers.get('content-type'), 'application/zip');
		assert.match(res.headers.get('content-disposition'), /fluffless-suite-1\.0\.0\.zip/);
		assert.ok(res.headers.get('x-content-signature'));
		assert.deepEqual(Buffer.from(await res.arrayBuffer()), fakeZip('suite'));
		const wrong = await download('key=FL-00000000-00000000-00000000-00000000');
		assert.equal(wrong.status, 403);
		assert.match(await wrong.text(), /Clé inconnue/);
		assert.equal((await download('key=')).status, 400);
		assert.equal((await download(JSON.stringify({ key: soldKey }), 'application/json')).status, 415);
		assert.equal((await download(`key=${'x'.repeat(2000)}`)).status, 413);
	});

	test('hors du Canada ou offre inconnue : remboursé, aucune licence, alerte', async () => {
		const outside = paidSession({ customer_details: { email: 'loin@exemple.com', address: { country: 'FR' } } });
		assert.equal((await webhook(event(outside))).status, 200);
		const row = await purchase(outside.id);
		assert.equal(row.outcome, 'refunded');
		assert.equal(row.refund_reason, 'outside_canada');
		assert.equal(row.licence_id, null);
		assert.equal(stripe.refunds.at(-1).idempotencyKey, `refund-${outside.id}`);
		assert.ok(t.alerts.some((a) => a.subject === 'Remboursement automatique d\'une licence'));
		assert.equal((await t.sql`select count(*)::int as n from licences where email = 'loin@exemple.com'`)[0].n, 0);
		const orphan = paidSession({ metadata: { kind: 'licence', offer_id: '999999', locale: 'fr_CA' } });
		assert.equal((await webhook(event(orphan))).status, 200);
		assert.equal((await purchase(orphan.id)).refund_reason, 'offer_missing');
		// Paiement non abouti : rien.
		const unpaid = paidSession({ payment_status: 'unpaid' });
		assert.equal((await webhook(event(unpaid))).status, 200);
		assert.equal(await purchase(unpaid.id), undefined);
	});

	test('courriel en échec : alerte, puis la relivraison envoie la même clé', async () => {
		const attempted = [];
		t.setDeliverLicence(async (mail) => {
			attempted.push(mail.key);
			throw Object.assign(new Error('refus'), { responseCode: 421 });
		});
		const session = paidSession();
		const alertsBefore = t.alerts.length;
		assert.equal((await webhook(event(session))).status, 200);
		let row = await purchase(session.id);
		assert.equal(row.outcome, 'granted');
		assert.equal(row.delivered_at, null);
		assert.equal(row.delivering_until, null);
		assert.equal(row.delivery_attempts, 1);
		// Une rotation écraserait la clé à la livraison suivante : refusée tant que rien n'est livré.
		assert.equal((await admin(`/v1/admin/licences/${row.licence_id}/rotate`, 'POST')).status, 409);
		const alert = t.alerts.slice(alertsBefore).find((a) => a.subject === 'Licence payée, courriel de la clé non parti');
		assert.ok(alert);
		assert.ok(!alert.lines.join('\n').includes(session.customer_details.email), 'jamais d\'adresse dans une alerte');
		t.setDeliverLicence(null);
		const counts = await t.redeliverLicences();
		assert.equal(counts.delivered, 1);
		row = await purchase(session.id);
		assert.ok(row.delivered_at);
		const mail = t.licenceDeliveries.find((m) => m.to === session.customer_details.email);
		assert.equal(mail.key, attempted[0]);
		assert.equal((await (await verify(mail.key)).json()).valid, true);
		// Plus rien à relivrer.
		assert.deepEqual(await t.redeliverLicences(), { delivered: 0, failed: 0, skipped: 0 });
		// Livrée : la rotation redevient possible.
		assert.equal((await admin(`/v1/admin/licences/${row.licence_id}/rotate`, 'POST')).status, 200);
		assert.equal((await admin('/v1/admin/licences/999999/rotate', 'POST')).status, 404);
	});

	test('paiement sans courriel : alerte, et la relivraison ne s\'y use pas', async () => {
		const session = paidSession({ customer_details: { address: { country: 'CA' } } });
		const alertsBefore = t.alerts.length;
		assert.equal((await webhook(event(session))).status, 200);
		const row = await purchase(session.id);
		assert.equal(row.outcome, 'granted');
		assert.equal(row.delivered_at, null);
		const alert = t.alerts.slice(alertsBefore).find((a) => a.subject === 'Licence payée, courriel de la clé non parti');
		assert.ok(alert.lines.includes('Cause : email_missing'));
		assert.deepEqual(await t.redeliverLicences(), { delivered: 0, failed: 0, skipped: 0 });
		const res = await admin(`/v1/admin/licences/${row.licence_id}/resend`, 'POST', { email: 'trouvee@exemple.ca' });
		assert.equal(res.status, 200);
		assert.equal(t.licenceDeliveries.at(-1).to, 'trouvee@exemple.ca');
	});

	test('relivraison : un bail en cours n\'est pas doublé', async () => {
		let release;
		const gate = new Promise((resolve) => { release = resolve; });
		t.setDeliverLicence(async (mail) => {
			await gate;
			t.licenceDeliveries.push(mail);
			return true;
		});
		const session = paidSession();
		const pending = webhook(event(session));
		// Attend que le webhook ait pris le bail.
		for (let i = 0; i < 50 && !(await purchase(session.id))?.delivering_until; i++) {
			await new Promise((resolve) => setTimeout(resolve, 20));
		}
		assert.deepEqual(await t.redeliverLicences(), { delivered: 0, failed: 0, skipped: 0 });
		release();
		assert.equal((await pending).status, 200);
		t.setDeliverLicence(null);
		assert.equal(t.licenceDeliveries.filter((m) => m.to === session.customer_details.email).length, 1);
	});

	test('renvoi par l\'administration : nouvelle clé, l\'ancienne cesse, adresse corrigée', async () => {
		const res = await admin(`/v1/admin/licences/${soldLicenceId}/resend`, 'POST', { email: 'nouvelle@exemple.ca' });
		assert.equal(res.status, 200);
		const mail = t.licenceDeliveries.at(-1);
		assert.equal(mail.to, 'nouvelle@exemple.ca');
		assert.match(mail.key, KEY_FORMAT);
		assert.notEqual(mail.key, soldKey);
		assert.equal((await (await verify(soldKey)).json()).valid, false);
		assert.equal((await (await verify(mail.key)).json()).valid, true);
		assert.equal((await t.sql`select email from licences where id = ${soldLicenceId}`)[0].email, 'nouvelle@exemple.ca');
		// Licence créée à la main : rotate, pas resend.
		const manual = await (await admin('/v1/admin/licences', 'POST', { plan: 'solo', sites_max: 1 })).json();
		assert.equal((await admin(`/v1/admin/licences/${manual.id}/resend`, 'POST', {})).status, 409);
		assert.equal((await admin('/v1/admin/licences/999999/resend', 'POST', {})).status, 404);
		assert.equal((await admin(`/v1/admin/licences/${soldLicenceId}/resend`, 'POST', { email: 'pas-une-adresse' })).status, 400);
		// Une licence créée à la main télécharge aussi : elle n'a pas d'offre, la Suite par défaut.
		assert.equal((await download(`key=${manual.licence}`)).status, 200);
	});

	test('renvoi dont l\'envoi échoue : la clé déjà livrée reste valable, la suivante part à la relivraison', async () => {
		const session = paidSession();
		assert.equal((await webhook(event(session))).status, 200);
		const row = await purchase(session.id);
		const oldKey = t.licenceDeliveries.find((m) => m.to === session.customer_details.email).key;
		assert.equal((await (await verify(oldKey)).json()).valid, true);
		t.setDeliverLicence(async () => { throw Object.assign(new Error('refus'), { responseCode: 421 }); });
		try {
			const res = await admin(`/v1/admin/licences/${row.licence_id}/resend`, 'POST', {});
			assert.equal(res.status, 503);
			assert.equal((await (await verify(oldKey)).json()).valid, true, 'l\'ancienne clé doit rester valable tant que la nouvelle n\'est pas partie');
		} finally {
			t.setDeliverLicence(null);
		}
		// Le courriel part enfin : la nouvelle clé remplace l'ancienne, une seule fois.
		const before = t.licenceDeliveries.length;
		await t.redeliverLicences();
		const sent = t.licenceDeliveries.slice(before).filter((m) => m.to === session.customer_details.email);
		assert.equal(sent.length, 1);
		assert.notEqual(sent[0].key, oldKey);
		assert.equal((await (await verify(sent[0].key)).json()).valid, true);
		assert.equal((await (await verify(oldKey)).json()).valid, false);
	});

	test('renvoi dont l\'échec est sans doute possible (connexion refusée, erreur interne) : l\'ancienne clé reste valable', async () => {
		const session = paidSession();
		assert.equal((await webhook(event(session))).status, 200);
		const row = await purchase(session.id);
		const oldKey = t.licenceDeliveries.find((m) => m.to === session.customer_details.email).key;
		const failures = [
			Object.assign(new Error('refusée'), { code: 'ESOCKET', command: 'CONN' }),
			new TypeError('panne interne'),
		];
		try {
			for (const failure of failures) {
				t.setDeliverLicence(async () => { throw failure; });
				assert.equal((await admin(`/v1/admin/licences/${row.licence_id}/resend`, 'POST', {})).status, 503);
				assert.equal((await (await verify(oldKey)).json()).valid, true, String(failure.code ?? failure.name));
			}
		} finally {
			t.setDeliverLicence(null);
		}
	});

	test('renvoi dont l\'issue est ambiguë (délai dépassé) : la clé peut être partie, elle ouvre la licence et la relivraison renvoie la même', async () => {
		const session = paidSession();
		assert.equal((await webhook(event(session))).status, 200);
		const row = await purchase(session.id);
		const oldKey = t.licenceDeliveries.find((m) => m.to === session.customer_details.email).key;
		const attempted = [];
		t.setDeliverLicence(async (mail) => {
			attempted.push(mail.key);
			// Forme réelle de nodemailer : le délai de socket porte « CONN » même après DATA.
			throw Object.assign(new Error('Timeout'), { code: 'ETIMEDOUT', command: 'CONN' });
		});
		try {
			assert.equal((await admin(`/v1/admin/licences/${row.licence_id}/resend`, 'POST', {})).status, 503);
		} finally {
			t.setDeliverLicence(null);
		}
		assert.equal(attempted.length, 1);
		assert.equal((await (await verify(attempted[0])).json()).valid, true, 'une clé peut-être reçue ne doit pas être morte');
		assert.equal((await purchase(session.id)).delivered_at, null, 'rien n\'est marqué livré');
		const before = t.licenceDeliveries.length;
		await t.redeliverLicences();
		const sent = t.licenceDeliveries.slice(before).filter((m) => m.to === session.customer_details.email);
		assert.deepEqual(sent.map((m) => m.key), [attempted[0]], 'la relivraison renvoie la même clé');
		assert.notEqual(attempted[0], oldKey);
	});

	test('renvoi dont l\'envoi réussit mais dont l\'écriture échoue : la clé partie reste valable, jamais dédoublée', async () => {
		const session = paidSession();
		assert.equal((await webhook(event(session))).status, 200);
		const row = await purchase(session.id);
		const oldKey = t.licenceDeliveries.find((m) => m.to === session.customer_details.email).key;
		const sentKeys = [];
		// Le courriel part, puis toute écriture de « livré » échoue (panne de base simulée).
		t.setDeliverLicence(async (mail) => {
			sentKeys.push(mail.key);
			await t.sql.unsafe(`create or replace function public.fl_refuse_livre() returns trigger language plpgsql as $$ begin raise exception 'panne simulée'; end $$`);
			await t.sql.unsafe('create trigger fl_refuse_livre before update on licence_purchases for each row when (new.delivered_at is not null) execute function public.fl_refuse_livre()');
			return true;
		});
		try {
			const res = await admin(`/v1/admin/licences/${row.licence_id}/resend`, 'POST', {});
			assert.ok(res.status >= 500, String(res.status));
		} finally {
			await t.sql.unsafe('drop trigger if exists fl_refuse_livre on licence_purchases');
			await t.sql.unsafe('drop function if exists public.fl_refuse_livre()');
			t.setDeliverLicence(null);
		}
		assert.equal(sentKeys.length, 1);
		assert.notEqual(sentKeys[0], oldKey);
		assert.equal((await (await verify(sentKeys[0])).json()).valid, true, 'la clé qui est partie par courriel doit ouvrir la licence');
		// La relivraison renvoie la même clé (même génération), pas une troisième.
		const before = t.licenceDeliveries.length;
		await new Promise((resolve) => setTimeout(resolve, 5));
		await t.sql`update licence_purchases set delivering_until = null where checkout_session_id = ${session.id}`;
		await t.redeliverLicences();
		assert.ok((await purchase(session.id)).delivered_at);
		const resent = t.licenceDeliveries.slice(before).filter((m) => m.to === session.customer_details.email);
		assert.equal(resent.length, 1);
		assert.equal(resent[0].key, sentKeys[0]);
	});

	test('licence expirée ou révoquée : la page refuse le téléchargement', async () => {
		const expired = await (await admin('/v1/admin/licences', 'POST', { plan: 'solo', sites_max: 1, expires: '2020-01-01T00:00:00Z' })).json();
		const res = await download(`key=${expired.licence}`);
		assert.equal(res.status, 403);
		assert.match(await res.text(), /expirée/);
		const revoked = await (await admin('/v1/admin/licences', 'POST', { plan: 'solo', sites_max: 1 })).json();
		await t.sql`update licences set revoked_at = now() where id = ${revoked.id}`;
		assert.equal((await download(`key=${revoked.licence}`)).status, 403);
	});

	test('réconciliation : un paiement de licence sans webhook est rattrapé et livré', async () => {
		const session = paidSession();
		stripe.listed = [session, { ...paidSession(), metadata: { kind: 'autre' } }];
		const result = await t.reconcile();
		assert.equal(result.settled, 1);
		const row = await purchase(session.id);
		assert.equal(row.outcome, 'granted');
		assert.ok(row.delivered_at);
		assert.ok(t.alerts.some((a) => a.subject === 'Réconciliation Stripe : achats rattrapés'));
		// Un second passage ne refait rien.
		assert.equal((await t.reconcile()).settled, 0);
		stripe.listed = [];
	});

	test('remboursement ou litige d\'une licence : alerte qui nomme la licence, accès gardé', async () => {
		const [row] = await t.sql`select payment_intent, licence_id from licence_purchases where licence_id = ${soldLicenceId}`;
		const alertsBefore = t.alerts.length;
		const refunded = await webhook(event({ id: 'ch_lic_1', payment_intent: row.payment_intent, amount_refunded: 5634, currency: 'cad' }, 'charge.refunded'));
		assert.equal(refunded.status, 200);
		const alert = t.alerts.slice(alertsBefore).find((a) => a.subject === 'Remboursement Stripe');
		assert.ok(alert);
		assert.ok(alert.lines.some((line) => line.startsWith(`Licence achetée : offre solo, licence ${soldLicenceId}`)));
		assert.equal((await t.sql`select revoked_at from licences where id = ${soldLicenceId}`)[0].revoked_at, null);
	});

	test('page de succès : état de l\'achat et adresse masquée, 24 h seulement', async () => {
		const status = async (session) => {
			const res = await t.request(`/v1/licences/purchase?session=${session}`, { headers: { 'X-Forwarded-For': freshIp() } });
			assert.equal(res.headers.get('cache-control'), 'no-store');
			return res.json();
		};
		const sold = paidSession({ customer_details: { email: 'genevieve@gmial.com', address: { country: 'CA' } } });
		await webhook(event(sold));
		assert.deepEqual(await status(sold.id), { status: 'sent', email: 'g***e@gmial.com' });
		// Pas encore réglée (webhook en retard) ou inconnue : même réponse.
		assert.deepEqual(await status('cs_test_jamais_vue'), { status: 'unknown' });
		const outside = paidSession({ customer_details: { email: 'loin@exemple.com', address: { country: 'US' } } });
		await webhook(event(outside));
		assert.deepEqual(await status(outside.id), { status: 'refunded', reason: 'outside_canada' });
		const noEmail = paidSession({ customer_details: { address: { country: 'CA' } } });
		await webhook(event(noEmail));
		assert.deepEqual(await status(noEmail.id), { status: 'sending', email: null });
		// Après 24 h, plus rien.
		await t.sql`update licence_purchases set created_at = now() - interval '25 hours' where checkout_session_id = ${sold.id}`;
		assert.deepEqual(await status(sold.id), { status: 'unknown' });
		assert.equal((await t.request('/v1/licences/purchase?session=pi_123')).status, 400);
		assert.equal((await t.request('/v1/licences/purchase')).status, 400);
		assert.equal((await t.request(`/v1/licences/purchase?session=${sold.id}&email=x`)).status, 400);
	});

	test('ventes comptées dans la liste des offres', async () => {
		const list = await (await admin('/v1/admin/licence-offers', 'GET')).json();
		assert.ok(list.offers[0].sold >= 4);
	});
	test('offre Suite + SEO : deux extensions livrées, mises à jour limitées à la licence', async () => {
		const created = await admin('/v1/admin/licence-offers', 'POST', {
			slug: 'suite-seo-solo', plan: 'solo', sites_max: 1, duration_days: 365, release_slugs: ['fluffless-seo', 'fluffless-suite'],
		});
		assert.equal(created.status, 200);
		assert.deepEqual((await created.json()).release_slugs, ['fluffless-seo', 'fluffless-suite']);
		// Changer les sites sans redonner la liste ne retire pas la SEO.
		const resized = await (await admin('/v1/admin/licence-offers', 'POST', { slug: 'suite-seo-solo', plan: 'solo', sites_max: 2, duration_days: 365 })).json();
		assert.deepEqual(resized.release_slugs, ['fluffless-seo', 'fluffless-suite']);
		assert.equal(resized.sites_max, 2);
		assert.equal((await admin('/v1/admin/licence-offers', 'POST', { slug: 'suite-seo-solo', plan: 'solo', sites_max: 1, duration_days: 365, release_slugs: null })).status, 400);
		assert.equal((await admin('/v1/admin/licence-offers', 'POST', { slug: 'suite-seo-solo', plan: 'solo', sites_max: 1, duration_days: 365 })).status, 200);
		// Avec un prix mais sans version SEO publiée : pas en vente.
		assert.equal((await admin('/v1/admin/licence-offers/suite-seo-solo/price', 'PUT', { price_id: 'price_lic123' })).status, 200);
		const early = await checkout({ offer: 'suite-seo-solo' });
		assert.equal(early.status, 404);
		assert.equal((await early.json()).code, 'not_for_sale');
		const [offer] = await t.sql`select id from licence_offers where slug = 'suite-seo-solo'`;
		const session = paidSession({ metadata: { kind: 'licence', offer_id: String(offer.id), offer_slug: 'suite-seo-solo', locale: 'fr_CA' } });
		assert.equal((await webhook(event(session))).status, 200);
		const row = await purchase(session.id);
		assert.equal(row.outcome, 'granted');
		const [licence] = await t.sql`select release_slugs from licences where id = ${row.licence_id}`;
		assert.deepEqual(licence.release_slugs, ['fluffless-seo', 'fluffless-suite']);
		const mail = t.licenceDeliveries.find((m) => m.to === session.customer_details.email);
		assert.equal(mail.several, true);

		// SEO pas encore publiée : la Suite seule, avec un avis.
		const partial = await download(`key=${encodeURIComponent(mail.key)}`);
		assert.equal(partial.status, 200);
		assert.equal(partial.headers.get('cache-control'), 'no-store');
		const partialHtml = await partial.text();
		assert.match(partialHtml, /pas encore de version publiée/);
		assert.equal(partialHtml.match(/\/v1\/download\//g).length, 1);
		assert.match(partialHtml, /\/v1\/download\/fluffless-suite\/1\.0\.0\//);

		const seo = await admin('/v1/admin/releases', 'POST', {
			slug: 'fluffless-seo',
			version: '0.1.0',
			channel: 'stable',
			name: 'Fluffless SEO',
			description: { fr: 'Référencement', en: 'Search' },
			changelog: { fr: 'Première', en: 'First' },
			zip_base64: fakeZip('seo').toString('base64'),
		});
		assert.equal(seo.status, 201);
		assert.equal((await checkout({ offer: 'suite-seo-solo' })).status, 201);
		const page = await download(`key=${encodeURIComponent(mail.key)}`);
		assert.equal(page.status, 200);
		const html = await page.text();
		assert.doesNotMatch(html, /pas encore de version publiée/);
		// La Suite d'abord, même si l'offre la nomme en second.
		const links = [...html.matchAll(/href="([^"]+)"/g)].map((m) => m[1].replaceAll('&amp;', '&'));
		assert.equal(links.length, 2);
		assert.match(links[0], /\/v1\/download\/fluffless-suite\/1\.0\.0\/\d+\/[0-9a-f]+\.zip$/);
		assert.match(links[1], /\/v1\/download\/fluffless-seo\/0\.1\.0\/\d+\/[0-9a-f]+\.zip$/);
		const zip = await t.request(new URL(links[1]).pathname);
		assert.equal(zip.status, 200);
		assert.deepEqual(Buffer.from(await zip.arrayBuffer()), fakeZip('seo'));

		// Mises à jour : la SEO pour la licence Suite + SEO, un 404 (pas un 403) pour la Suite seule.
		const update = (slug, key) => t.request(`/v1/update?${new URLSearchParams({ slug, version: '0.0.1', channel: 'stable', site: SITE })}`, {
			headers: { 'X-Fluffless-Licence': key },
		});
		assert.equal((await update('fluffless-seo', mail.key)).status, 200);
		assert.equal((await update('fluffless-suite', mail.key)).status, 200);
		const soloOnly = paidSession();
		assert.equal((await webhook(event(soloOnly))).status, 200);
		const soloMail = t.licenceDeliveries.find((m) => m.to === soloOnly.customer_details.email);
		assert.equal(soloMail.several, false);
		const soloKey = soloMail.key;
		const refused = await update('fluffless-seo', soloKey);
		assert.equal(refused.status, 404);
		assert.deepEqual(await refused.json(), { code: 'not_included' });
		assert.equal((await update('fluffless-suite', soloKey)).status, 200);
		// Thème et Consent gratuits : compris dans toute licence, même limitée à la Suite.
		for (const slug of ['fluffless', 'fluffless-consent']) {
			const free = await admin('/v1/admin/releases', 'POST', {
				slug,
				version: '0.2.0',
				channel: 'stable',
				name: slug,
				description: { fr: 'Gratuit', en: 'Free' },
				changelog: { fr: 'Première', en: 'First' },
				zip_base64: fakeZip(slug).toString('base64'),
			});
			assert.equal(free.status, 201);
			assert.equal((await update(slug, soloKey)).status, 200);
		}
		// Passage à Suite + SEO sur la même clé.
		const soloId = (await purchase(soloOnly.id)).licence_id;
		const upgraded = await admin(`/v1/admin/licences/${soloId}`, 'PATCH', { release_slugs: ['fluffless-suite', 'fluffless-seo'] });
		assert.equal(upgraded.status, 200);
		assert.deepEqual((await upgraded.json()).release_slugs, ['fluffless-suite', 'fluffless-seo']);
		assert.equal((await update('fluffless-seo', soloKey)).status, 200);
		assert.equal((await admin(`/v1/admin/licences/${soloId}`, 'PATCH', { release_slugs: [] })).status, 400);
		assert.equal((await admin('/v1/admin/licences/999999', 'PATCH', { release_slugs: ['fluffless-suite'] })).status, 404);
		// La vérification ne dévoile pas la liste.
		const verified = await (await verify(mail.key)).json();
		assert.equal(verified.valid, true);
		assert.equal('releases' in verified, false);

		// Licence créée à la main : la Suite seule par défaut; toutes les extensions sur demande (null).
		const manual = await (await admin('/v1/admin/licences', 'POST', { plan: 'solo', sites_max: 1 })).json();
		assert.deepEqual(manual.release_slugs, ['fluffless-suite']);
		assert.equal((await update('fluffless-seo', manual.licence)).status, 404);
		const everything = await admin('/v1/admin/licences', 'POST', { plan: 'fondateur', sites_max: 1, release_slugs: null });
		assert.equal(everything.status, 201);
		const everythingLicence = await everything.json();
		assert.equal(everythingLicence.release_slugs, null);
		assert.equal((await update('fluffless-seo', everythingLicence.licence)).status, 200);
	});

	test('liste d\'extensions invalide : refusée', async () => {
		for (const release_slugs of [[], ['fluffless-suite', 'fluffless-suite'], ['a', 'b', 'c', 'd', 'e', 'f'], ['Pas Bon'], ['x'.repeat(65)], 'fluffless-suite', [1]]) {
			const offer = await admin('/v1/admin/licence-offers', 'POST', { slug: 'mauvaise', plan: 'solo', sites_max: 1, duration_days: 365, release_slugs });
			assert.equal(offer.status, 400, JSON.stringify(release_slugs));
			const licence = await admin('/v1/admin/licences', 'POST', { plan: 'solo', sites_max: 1, release_slugs });
			assert.equal(licence.status, 400, JSON.stringify(release_slugs));
		}
		assert.equal((await t.sql`select count(*)::int as n from licence_offers where slug = 'mauvaise'`)[0].n, 0);
	});

	// Relation après l'achat : rappels d'expiration (30 et 7 jours), lien de renouvellement qui
	// garde la clé, page d'expiration claire. Stripe et le SMTP sont simulés.
	describe('relation après l\'achat', () => {
		const renewToken = (id, until) => createHmac('sha256', t.env.URL_SECRET).update(`renew\n${id}\n${until}`).digest('hex');
		const sell = async (fields = {}) => {
			const session = paidSession(fields);
			assert.equal((await webhook(event(session))).status, 200);
			const row = await purchase(session.id);
			const mail = t.licenceDeliveries.filter((m) => m.to === session.customer_details.email).at(-1);
			return { session, id: Number(row.licence_id), key: mail.key, email: session.customer_details.email };
		};
		const setExpiry = (id, days) => t.sql`update licences set expires_at = ${new Date(Date.now() + days * DAY)} where id = ${id}`;
		const remindersFor = (email) => t.reminders.filter((m) => m.to === email);
		const renewalSession = (id, until, fields = {}) => paidSession({
			metadata: { kind: 'licence_renewal', licence_id: String(id), offer_id: offerId, until: String(until), locale: 'fr_CA' },
			...fields,
		});
		const untilOf = async (id) => Math.floor((await t.sql`select expires_at from licences where id = ${id}`)[0].expires_at.getTime() / 1000);

		test('rappel à 30 jours : un seul courriel, avec le lien de renouvellement et la date de fin', async () => {
			const { id, email } = await sell();
			await setExpiry(id, 20);
			const first = await t.sendRenewalReminders();
			assert.equal(first.sent >= 1, true);
			const mails = remindersFor(email);
			assert.equal(mails.length, 1);
			assert.equal(mails[0].daysLeft, 20, 'jours réellement restants');
			assert.equal(mails[0].locale, 'fr_CA');
			assert.match(mails[0].renewUrl, new RegExp(`^${t.config.publicUrl}/v1/licences/renew\\?licence=${id}&until=\\d+&token=[0-9a-f]{64}$`));
			assert.ok(mails[0].expires instanceof Date);
			await t.sendRenewalReminders();
			await Promise.all([t.sendRenewalReminders(), t.sendRenewalReminders()]);
			assert.equal(remindersFor(email).length, 1, 'idempotent, même en parallèle');
		});

		test('rappel à 7 jours : part une fois, après celui de 30 jours', async () => {
			const { id, email } = await sell();
			await setExpiry(id, 25);
			await t.sendRenewalReminders();
			await setExpiry(id, 5);
			await t.sendRenewalReminders();
			await t.sendRenewalReminders();
			assert.deepEqual(remindersFor(email).map((m) => m.daysLeft), [25, 5]);
		});

		test('rien à envoyer : trop loin, déjà expirée, révoquée ou sans courriel valide, et rien ne plante', async () => {
			const far = await sell();
			await setExpiry(far.id, 90);
			const gone = await sell();
			await setExpiry(gone.id, -2);
			const revoked = await sell();
			await setExpiry(revoked.id, 10);
			await t.sql`update licences set revoked_at = now() where id = ${revoked.id}`;
			const mute = await (await admin('/v1/admin/licences', 'POST', { plan: 'solo', sites_max: 1, expires: new Date(Date.now() + 10 * DAY).toISOString() })).json();
			const broken = await sell();
			await setExpiry(broken.id, 10);
			await t.sql`update licences set email = 'pas-un-courriel' where id = ${broken.id}`;
			const result = await t.sendRenewalReminders();
			for (const who of [far, gone, revoked]) {
				assert.equal(remindersFor(who.email).length, 0);
			}
			assert.equal(t.reminders.some((m) => m.to === 'pas-un-courriel'), false);
			assert.equal(typeof result.sent, 'number');
			assert.ok(mute.id);
		});

		test('échec du courriel : rien n\'est compté comme envoyé, le passage suivant réessaie', async () => {
			const { id, email } = await sell();
			await setExpiry(id, 6);
			t.setRemind(async () => { throw Object.assign(new Error('smtp'), { responseCode: 421 }); });
			const failed = await t.sendRenewalReminders();
			assert.equal(failed.failed >= 1, true);
			assert.equal(remindersFor(email).length, 0);
			t.setRemind();
			await t.sendRenewalReminders();
			assert.equal(remindersFor(email).length, 1);
			assert.equal(remindersFor(email)[0].daysLeft, 6);
		});

		test('lien de renouvellement : ouvre un paiement pour la même licence, refuse un jeton faux ou périmé', async () => {
			const { id } = await sell();
			const until = await untilOf(id);
			const url = (token, u = until) => `/v1/licences/renew?licence=${id}&until=${u}&token=${token}`;
			const get = (path) => t.request(path, { headers: { 'X-Forwarded-For': freshIp() }, redirect: 'manual' });
			const ok = await get(url(renewToken(id, until)));
			assert.equal(ok.status, 303);
			assert.match(ok.headers.get('location'), /^https:\/\/checkout\.stripe\.com\//);
			const params = stripe.sessions.at(-1);
			assert.deepEqual(params.metadata, {
				kind: 'licence_renewal', licence_id: String(id), offer_id: offerId, until: String(until), locale: 'fr_CA',
				price_basis: 'gele', price_id: 'price_lic123', unit_amount: '4900',
			});
			assert.equal(params.line_items[0].price, 'price_lic123');
			assert.equal(params.customer_email, undefined, 'le courriel de la licence ne part pas chez Stripe');
			const before = stripe.sessions.length;
			assert.equal((await get(url('0'.repeat(64)))).status, 403);
			assert.equal((await get(url(renewToken(id, until + 1), until + 1))).status, 403);
			assert.equal((await get(`/v1/licences/renew?licence=${id}`)).status, 400);
			assert.equal(stripe.sessions.length, before, 'un lien refusé ne crée aucun paiement');
		});

		test('paiement du renouvellement : la clé reste, la fin recule d\'un an, un rejeu ne double rien', async () => {
			const { id, key } = await sell();
			await setExpiry(id, 10);
			const until = await untilOf(id);
			const [{ key_hash: hashBefore }] = await t.sql`select key_hash from licences where id = ${id}`;
			const session = renewalSession(id, until);
			const statuses = await Promise.all(Array.from({ length: 4 }, () => webhook(event(session)).then((r) => r.status)));
			assert.ok(statuses.every((s) => s === 200 || s === 409), statuses.join(','));
			const [licence] = await t.sql`select key_hash, expires_at from licences where id = ${id}`;
			assert.equal(licence.key_hash, hashBefore, 'la clé ne change pas');
			assert.equal((await (await verify(key)).json()).valid, true);
			const added = (licence.expires_at.getTime() - until * 1000) / DAY;
			assert.ok(Math.abs(added - 365) < 0.01, String(added));
			assert.equal((await t.sql`select count(*)::int as n from licence_renewals where licence_id = ${id}`)[0].n, 1);
		});

		test('renouvellement d\'une licence déjà expirée : un an à partir d\'aujourd\'hui, la page redevient ouverte', async () => {
			const { id, key } = await sell();
			await setExpiry(id, -30);
			const until = await untilOf(id);
			assert.equal((await download(`key=${key}`)).status, 403);
			assert.equal((await webhook(event(renewalSession(id, until)))).status, 200);
			const [{ expires_at: expiresAt }] = await t.sql`select expires_at from licences where id = ${id}`;
			const days = (expiresAt.getTime() - Date.now()) / DAY;
			assert.ok(days > 364 && days <= 365, String(days));
			const again = await download(`key=${key}`);
			assert.ok(again.redirected, 'renvoi vers le lien signé');
			assert.equal(again.status, 200);
		});

		test('renouvellement en double, hors Canada ou licence révoquée : remboursé, fin inchangée', async () => {
			const { id } = await sell();
			await setExpiry(id, 10);
			const until = await untilOf(id);
			assert.equal((await webhook(event(renewalSession(id, until)))).status, 200);
			const afterFirst = await untilOf(id);
			const stale = renewalSession(id, until);
			assert.equal((await webhook(event(stale))).status, 200);
			assert.equal(await untilOf(id), afterFirst, 'même période payée deux fois : une seule prolongation');
			const [staleRow] = await t.sql`select outcome, refund_reason from licence_renewals where checkout_session_id = ${stale.id}`;
			assert.deepEqual({ ...staleRow }, { outcome: 'refunded', refund_reason: 'already_renewed' });
			const abroad = renewalSession(id, afterFirst, { customer_details: { email: 'x@exemple.ca', address: { country: 'FR' } } });
			assert.equal((await webhook(event(abroad))).status, 200);
			assert.equal((await t.sql`select refund_reason from licence_renewals where checkout_session_id = ${abroad.id}`)[0].refund_reason, 'outside_canada');
			const other = await sell();
			await t.sql`update licences set revoked_at = now() where id = ${other.id}`;
			const revoked = renewalSession(other.id, await untilOf(other.id));
			assert.equal((await webhook(event(revoked))).status, 200);
			assert.equal((await t.sql`select refund_reason from licence_renewals where checkout_session_id = ${revoked.id}`)[0].refund_reason, 'licence_missing');
			assert.equal(await untilOf(id), afterFirst);
		});

		test('page d\'une licence expirée : dit la date de fin, garde la clé et offre le lien de renouvellement', async () => {
			const { id, key } = await sell();
			await setExpiry(id, -3);
			const res = await download(`key=${key}`);
			assert.equal(res.status, 403);
			const html = await res.text();
			assert.match(html, /a pris fin le \d{1,2}(er)? \p{L}+ \d{4}/u);
			assert.match(html, /ta clé reste la même/);
			assert.match(html, new RegExp(`href="${t.config.publicUrl}/v1/licences/renew\\?licence=${id}&amp;until=\\d+&amp;token=[0-9a-f]{64}"`));
			assert.match(html, /it ended on \p{L}+ \d{1,2}, \d{4}/u);
			// Une licence créée à la main, sans offre d'origine : pas de lien inventé, une adresse pour écrire.
			const manual = await (await admin('/v1/admin/licences', 'POST', { plan: 'solo', sites_max: 1, expires: '2020-01-01T00:00:00Z' })).json();
			const plain = await (await download(`key=${manual.licence}`)).text();
			assert.doesNotMatch(plain, /licences\/renew/);
			assert.match(plain, /genevieve@fluffless\.dev/);
		});
		test('un rappel pris puis jamais envoyé (plantage) se reprend après 15 minutes, pas avant', async () => {
			const { id, email } = await sell();
			await setExpiry(id, 6);
			const [{ expires_at: end }] = await t.sql`select expires_at from licences where id = ${id}`;
			await t.sql`insert into licence_reminders (licence_id, period_end, days_before, claimed_at) values (${id}, ${end}, 7, now())`;
			await t.sendRenewalReminders();
			assert.equal(remindersFor(email).length, 0, 'prise récente : un autre passage envoie');
			await t.sql`update licence_reminders set claimed_at = now() - interval '20 minutes' where licence_id = ${id}`;
			await t.sendRenewalReminders();
			assert.equal(remindersFor(email).length, 1);
			await t.sendRenewalReminders();
			assert.equal(remindersFor(email).length, 1);
		});

		test('lien de renouvellement refusé : page lisible bilingue, pas du JSON', async () => {
			const { id } = await sell();
			const res = await t.request(`/v1/licences/renew?licence=${id}&until=1&token=${'0'.repeat(64)}`, { headers: { 'X-Forwarded-For': freshIp() } });
			assert.equal(res.status, 403);
			assert.match(res.headers.get('content-type'), /text\/html/);
			const html = await res.text();
			assert.match(html, /ne fonctionne plus/);
			assert.match(html, /no longer works/);
		});
	});
});
