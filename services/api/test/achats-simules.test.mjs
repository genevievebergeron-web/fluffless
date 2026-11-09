// Événements d'achat simulés de bout en bout : webhooks Stripe signés localement, Stripe et
// SMTP simulés, aucune requête vers l'extérieur. Quatre cas : paiement réussi, paiement échoué
// (ou en attente), remboursement, événement en double.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac, randomBytes } from 'node:crypto';
import { fakeZip, startTestServer } from './helpers.mjs';

const skip = process.env.API_TEST_SKIP_DB === '1' ? 'API_TEST_SKIP_DB=1' : false;
// Secret de test généré à chaque exécution : rien d'écrit dans le dépôt.
const WEBHOOK_SECRET = `whsec_${randomBytes(18).toString('hex')}`;
const STRIPE_ENV = {
	STRIPE_SECRET_KEY: `sk_test_${randomBytes(12).toString('hex')}`,
	STRIPE_WEBHOOK_SECRET: WEBHOOK_SECRET,
	STRIPE_SUCCESS_URL: 'https://fluffless.dev/merci?session={CHECKOUT_SESSION_ID}',
	STRIPE_CANCEL_URL: 'https://fluffless.dev/acheter',
	PROTON_SMTP_USER: 'vente@exemple.ca',
	PROTON_SMTP_TOKEN: 'faux-jeton-smtp',
};
const KEY_FORMAT = /^FL-[0-9A-F]{8}-[0-9A-F]{8}-[0-9A-F]{8}-[0-9A-F]{8}$/;

function createFakeStripe() {
	const fake = {
		refunds: [],
		calls: 0,
		prices: { price_lic123: { id: 'price_lic123', active: true, type: 'one_time', unit_amount: 6900, currency: 'cad', tax_behavior: 'exclusive' } },
		async createCheckoutSession() {
			fake.calls++;
			return { id: 'cs_test_x', url: 'https://checkout.stripe.com/c/pay/cs_test_x' };
		},
		async expireCheckoutSession(id) {
			return { id, status: 'expired' };
		},
		async retrievePrice(id) {
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
			return { data: [], has_more: false };
		},
	};
	return fake;
}

const sign = (payload, timestamp = Math.floor(Date.now() / 1000)) =>
	`t=${timestamp},v1=${createHmac('sha256', WEBHOOK_SECRET).update(`${timestamp}.${payload}`).digest('hex')}`;

describe('événements d\'achat simulés', { skip }, () => {
	let t;
	let stripe;
	let offerId;
	let counter = 0;
	const webhook = (event) => {
		const payload = JSON.stringify(event);
		return t.request('/v1/webhooks/stripe', { method: 'POST', body: payload, headers: { 'Stripe-Signature': sign(payload) } });
	};
	const session = (fields = {}) => {
		const n = ++counter;
		return {
			id: `cs_test_sim_${n}`,
			object: 'checkout.session',
			payment_status: 'paid',
			payment_intent: `pi_sim_${n}`,
			amount_total: 7934,
			currency: 'cad',
			metadata: { kind: 'licence', offer_id: offerId, offer_slug: 'solo', locale: 'fr_CA' },
			customer_details: { email: `cliente${n}@exemple.ca`, address: { country: 'CA' } },
			...fields,
		};
	};
	const event = (object, type = 'checkout.session.completed', id = `evt_sim_${++counter}`) => ({ id, type, data: { object } });
	const purchase = async (sessionId) => (await t.sql`select * from licence_purchases where checkout_session_id = ${sessionId}`)[0];
	const licencesFor = async (email) => (await t.sql`select count(*)::int as n from licences where email = ${email}`)[0].n;
	const mailsTo = (email) => t.licenceDeliveries.filter((mail) => mail.to === email);
	const processed = async (id) => (await t.sql`select processed_at from stripe_events where id = ${id}`)[0];

	before(async () => {
		stripe = createFakeStripe();
		t = await startTestServer({ env: STRIPE_ENV, stripe });
		const admin = (path, method, body) => t.request(path, { method, token: t.env.ADMIN_TOKEN, body });
		const release = await admin('/v1/admin/releases', 'POST', {
			slug: 'fluffless-suite', version: '1.0.0', channel: 'stable', name: 'Fluffless Suite',
			description: { fr: 'Outils', en: 'Tools' }, changelog: { fr: 'Première', en: 'First' },
			zip_base64: fakeZip('suite').toString('base64'),
		});
		assert.equal(release.status, 201);
		const created = await admin('/v1/admin/licence-offers', 'POST', { slug: 'solo', plan: 'solo', sites_max: 1, duration_days: 365 });
		assert.equal(created.status, 200);
		offerId = String((await t.sql`select id from licence_offers where slug = 'solo'`)[0].id);
	});
	after(async () => t?.stop());

	test('paiement réussi : une licence, une clé par courriel, achat livré, événement réglé', async () => {
		const refundsBefore = stripe.refunds.length;
		const paid = session();
		const evt = event(paid);
		const res = await webhook(evt);
		assert.equal(res.status, 200);
		assert.deepEqual(await res.json(), { received: true });
		const row = await purchase(paid.id);
		assert.equal(row.outcome, 'granted');
		assert.ok(row.delivered_at);
		assert.equal(row.amount_total, 7934);
		assert.equal(row.currency, 'cad');
		const [mail] = mailsTo(paid.customer_details.email);
		assert.match(mail.key, KEY_FORMAT);
		assert.ok((await processed(evt.id)).processed_at);
		assert.equal(await licencesFor(paid.customer_details.email), 1);
		assert.equal(stripe.refunds.length, refundsBefore);
	});

	test('paiement échoué : événements ignorés sans effet (200, aucune licence, courriel ni remboursement)', async () => {
		const refundsBefore = stripe.refunds.length;
		const failedSession = session({ payment_status: 'unpaid' });
		const email = failedSession.customer_details.email;
		const failures = [
			event(failedSession, 'checkout.session.async_payment_failed'),
			event({
				id: failedSession.payment_intent,
				object: 'payment_intent',
				status: 'requires_payment_method',
				amount: 7934,
				currency: 'cad',
				last_payment_error: { code: 'card_declined', decline_code: 'insufficient_funds' },
			}, 'payment_intent.payment_failed'),
			event({ ...failedSession, status: 'expired' }, 'checkout.session.expired'),
		];
		for (const failure of failures) {
			const res = await webhook(failure);
			assert.equal(res.status, 200, failure.type);
			assert.ok((await processed(failure.id)).processed_at, failure.type);
		}
		assert.equal(await purchase(failedSession.id), undefined);
		assert.equal(await licencesFor(email), 0);
		assert.deepEqual(mailsTo(email), []);
		assert.equal(stripe.refunds.length, refundsBefore);
	});

	test('paiement différé : en attente rien, puis confirmé une licence, un échec tardif ne la retire pas', async () => {
		const delayed = session({ payment_status: 'unpaid' });
		const email = delayed.customer_details.email;
		assert.equal((await webhook(event(delayed))).status, 200);
		assert.equal(await purchase(delayed.id), undefined);
		assert.equal(await licencesFor(email), 0);
		const confirmed = { ...delayed, payment_status: 'paid' };
		assert.equal((await webhook(event(confirmed, 'checkout.session.async_payment_succeeded'))).status, 200);
		assert.equal((await purchase(delayed.id)).outcome, 'granted');
		assert.equal(mailsTo(email).length, 1);
		// Un événement d'échec arrivé en retard est ignoré : la licence reste, aucun second courriel.
		assert.equal((await webhook(event({ ...delayed, payment_status: 'unpaid' }, 'checkout.session.async_payment_failed'))).status, 200);
		assert.equal((await purchase(delayed.id)).outcome, 'granted');
		assert.equal(await licencesFor(email), 1);
		assert.equal(mailsTo(email).length, 1);
	});

	test('remboursement total : alerte qui nomme la licence, accès gardé, rien à rembourser de plus', async () => {
		const refundsBefore = stripe.refunds.length;
		const paid = session();
		await webhook(event(paid));
		const row = await purchase(paid.id);
		const before = t.alerts.length;
		const refunded = event({ id: `ch_sim_${++counter}`, payment_intent: paid.payment_intent, amount_refunded: 7934, currency: 'cad' }, 'charge.refunded');
		assert.equal((await webhook(refunded)).status, 200);
		const alert = t.alerts.slice(before).find((a) => a.subject === 'Remboursement Stripe');
		assert.ok(alert);
		assert.ok(alert.lines.some((line) => line.startsWith(`Licence achetée : offre solo, licence ${row.licence_id}`)));
		assert.ok(alert.lines.some((line) => line.includes('toujours active')));
		assert.ok(alert.lines.includes('Aucun accès n\'a été retiré automatiquement.'));
		assert.equal((await t.sql`select revoked_at from licences where id = ${row.licence_id}`)[0].revoked_at, null);
		assert.equal(stripe.refunds.length, refundsBefore);
	});

	test('remboursement partiel et remboursement arrivé avant l\'achat : une alerte chacun, jamais de plantage', async () => {
		const paid = session();
		await webhook(event(paid));
		const before = t.alerts.length;
		const partial = event({ id: `ch_sim_${++counter}`, payment_intent: paid.payment_intent, amount_refunded: 1500, currency: 'cad' }, 'charge.refunded');
		assert.equal((await webhook(partial)).status, 200);
		const early = event({ id: `ch_sim_${++counter}`, payment_intent: 'pi_sim_inconnu', amount_refunded: 7934, currency: 'cad' }, 'charge.refunded');
		assert.equal((await webhook(early)).status, 200);
		const alerts = t.alerts.slice(before).filter((a) => a.subject === 'Remboursement Stripe');
		assert.equal(alerts.length, 2);
		assert.ok(alerts[0].lines.includes('Montant : 15.00 CAD'));
		assert.ok(alerts[1].lines.includes('Aucun achat de pack ni de licence connu pour ce paiement.'));
	});

	test('événement en double : même identifiant rejoué, un seul achat, un seul courriel', async () => {
		const paid = session();
		const email = paid.customer_details.email;
		const evt = event(paid);
		const first = await webhook(evt);
		assert.deepEqual(await first.json(), { received: true });
		const second = await webhook(evt);
		assert.equal(second.status, 200);
		assert.deepEqual(await second.json(), { received: true, duplicate: true });
		assert.equal(await licencesFor(email), 1);
		assert.equal(mailsTo(email).length, 1);
		assert.equal((await t.sql`select count(*)::int as n from licence_purchases where checkout_session_id = ${paid.id}`)[0].n, 1);
	});

	test('même paiement sous un autre identifiant d\'événement : toujours une seule licence', async () => {
		const refundsBefore = stripe.refunds.length;
		const paid = session();
		const email = paid.customer_details.email;
		assert.equal((await webhook(event(paid))).status, 200);
		assert.equal((await webhook(event(paid, 'checkout.session.async_payment_succeeded'))).status, 200);
		assert.equal((await webhook(event(paid))).status, 200);
		assert.equal(await licencesFor(email), 1);
		assert.equal(mailsTo(email).length, 1);
		assert.equal(stripe.refunds.length, refundsBefore);
	});

	// Paiement échoué : toujours une trace au journal; une alerte seulement quand une vente
	// différée est définitivement perdue (un refus de carte, lui, se rejoue tout seul au paiement).
	test('paiement différé échoué : trace au journal (niveau error) et alerte, sans courriel de cliente', async () => {
		const failed = session({ payment_status: 'unpaid' });
		const logsBefore = t.logs.length;
		const alertsBefore = t.alerts.length;
		assert.equal((await webhook(event(failed, 'checkout.session.async_payment_failed'))).status, 200);
		const trace = t.logs.slice(logsBefore).find((entry) => entry.event === 'paiement_echoue');
		assert.ok(trace);
		assert.equal(trace.level, 'error');
		assert.equal(trace.session, failed.id);
		assert.equal(trace.kind, 'licence');
		const alerts = t.alerts.slice(alertsBefore);
		assert.equal(alerts.length, 1);
		assert.equal(alerts[0].subject, 'Paiement échoué');
		assert.ok(alerts[0].lines.includes(`Session Checkout : ${failed.id}`));
		assert.ok(alerts[0].lines.includes('Montant : 79.34 CAD'));
		const everything = JSON.stringify([trace, alerts]);
		assert.ok(!everything.includes(failed.customer_details.email));
		assert.equal(await purchase(failed.id), undefined);
	});

	test('refus de carte (payment_intent.payment_failed) : trace au journal avec le code, aucune alerte', async () => {
		const failedSession = session({ payment_status: 'unpaid' });
		const logsBefore = t.logs.length;
		const alertsBefore = t.alerts.length;
		const res = await webhook(event({ id: failedSession.payment_intent, object: 'payment_intent', amount: 7934, currency: 'cad', last_payment_error: { code: 'card_declined', decline_code: 'insufficient_funds' } }, 'payment_intent.payment_failed'));
		assert.equal(res.status, 200);
		const trace = t.logs.slice(logsBefore).find((entry) => entry.event === 'paiement_echoue');
		assert.ok(trace);
		assert.equal(trace.level, 'warn');
		assert.equal(trace.payment_intent, failedSession.payment_intent);
		assert.equal(trace.code, 'card_declined');
		assert.equal(trace.decline_code, 'insufficient_funds');
		assert.equal(t.alerts.length, alertsBefore);
	});

	test('paiement différé échoué d\'une autre vente du compte Stripe : trace seulement, aucune alerte', async () => {
		const other = session({ payment_status: 'unpaid', metadata: { kind: 'autre_produit' } });
		const logsBefore = t.logs.length;
		const alertsBefore = t.alerts.length;
		assert.equal((await webhook(event(other, 'checkout.session.async_payment_failed'))).status, 200);
		assert.ok(t.logs.slice(logsBefore).some((entry) => entry.event === 'paiement_echoue'));
		assert.equal(t.alerts.length, alertsBefore);
	});

	test('événement d\'échec rejoué : une seule alerte', async () => {
		const failed = session({ payment_status: 'unpaid' });
		const evt = event(failed, 'checkout.session.async_payment_failed');
		const alertsBefore = t.alerts.length;
		await webhook(evt);
		await webhook(evt);
		assert.equal(t.alerts.length - alertsBefore, 1);
	});

	test('remboursement reçu avant l\'événement d\'achat : l\'alerte nomme la licence créée ensuite', async () => {
		const paid = session();
		await webhook(event({ id: `ch_sim_${++counter}`, payment_intent: paid.payment_intent, amount_refunded: 7934, currency: 'cad' }, 'charge.refunded'));
		const alertsBefore = t.alerts.length;
		await webhook(event(paid));
		const row = await purchase(paid.id);
		const late = t.alerts.slice(alertsBefore).filter((a) => a.subject === 'Achat réglé après son remboursement');
		assert.equal(late.length, 1);
		assert.ok(late[0].lines.includes(`Licence : ${row.licence_id}`));
		assert.ok(late[0].lines.includes(`Session Checkout : ${paid.id}`));
	});

	test('achat normal suivi d\'un remboursement : pas d\'alerte « réglé après son remboursement »', async () => {
		const paid = session();
		const alertsBefore = t.alerts.length;
		await webhook(event(paid));
		await webhook(event({ id: `ch_sim_${++counter}`, payment_intent: paid.payment_intent, amount_refunded: 7934, currency: 'cad' }, 'charge.refunded'));
		assert.equal(t.alerts.slice(alertsBefore).filter((a) => a.subject === 'Achat réglé après son remboursement').length, 0);
	});

	test('signature fausse ou secret d\'un autre environnement : refusé, rien d\'enregistré', async () => {
		const paid = session();
		const evt = event(paid);
		const payload = JSON.stringify(evt);
		const stamp = Math.floor(Date.now() / 1000);
		const forged = `t=${stamp},v1=${createHmac('sha256', 'whsec_autre').update(`${stamp}.${payload}`).digest('hex')}`;
		const res = await t.request('/v1/webhooks/stripe', { method: 'POST', body: payload, headers: { 'Stripe-Signature': forged } });
		assert.ok(res.status >= 400 && res.status < 500);
		assert.equal(await purchase(paid.id), undefined);
		assert.equal((await t.sql`select count(*)::int as n from stripe_events where id = ${evt.id}`)[0].n, 0);
	});
});
