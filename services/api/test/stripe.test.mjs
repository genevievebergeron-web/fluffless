// Achat de packs par Stripe : session Checkout, webhook signé et idempotent, remboursement
// automatique quand la course est perdue, alertes et réconciliation. Stripe est simulé.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { fakeZip, startTestServer } from './helpers.mjs';

const skip = process.env.API_TEST_SKIP_DB === '1' ? 'API_TEST_SKIP_DB=1' : false;
const DAY = 86_400_000;
const iso = (offsetMs) => new Date(Date.now() + offsetMs).toISOString();
const WEBHOOK_SECRET = 'whsec_testtesttesttesttest';
const STRIPE_ENV = {
	STRIPE_SECRET_KEY: 'sk_test_abcdefghijklmnop',
	STRIPE_WEBHOOK_SECRET: WEBHOOK_SECRET,
	STRIPE_SUCCESS_URL: 'https://fluffless.dev/packs/merci',
	STRIPE_CANCEL_URL: 'https://fluffless.dev/packs',
};

function createFakeStripe() {
	const fake = {
		sessions: [],
		expired: [],
		refunds: [],
		listed: [],
		prices: {
			price_ok123: { id: 'price_ok123', active: true, type: 'one_time', unit_amount: 1900, currency: 'cad', tax_behavior: 'exclusive' },
			price_abo123: { id: 'price_abo123', active: true, type: 'recurring', unit_amount: 900, currency: 'cad', tax_behavior: 'exclusive' },
			price_ttc123: { id: 'price_ttc123', active: true, type: 'one_time', unit_amount: 1900, currency: 'cad', tax_behavior: 'inclusive' },
			price_usd123: { id: 'price_usd123', active: true, type: 'one_time', unit_amount: 1900, currency: 'usd', tax_behavior: 'exclusive' },
			price_bas123: { id: 'price_bas123', active: true, type: 'one_time', unit_amount: 25, currency: 'cad', tax_behavior: 'exclusive' },
			price_mp123: { id: 'price_mp123', active: true, type: 'one_time', unit_amount: 1900, currency: 'cad', tax_behavior: 'exclusive', product: { id: 'prod_1', tax_code: 'txcd_10202000' } },
		},
		// false, true (panne) ou 'already' (déjà remboursé à la main dans le tableau de bord).
		failRefund: false,
		manualRefunds: {},
		async createCheckoutSession(params) {
			// Numéro pris avant l'attente : des appels parallèles reçoivent chacun le leur, comme chez Stripe.
			const n = fake.sessions.push(params);
			await fake.onCreate?.();
			return { id: `cs_test_${n}`, url: `https://checkout.stripe.com/c/pay/cs_test_${n}` };
		},
		async expireCheckoutSession(id) {
			fake.expired.push(id);
			return { id, status: 'expired' };
		},
		async retrievePrice(id, params) {
			fake.priceParams = params;
			if (!fake.prices[id]) {
				throw Object.assign(new Error('absent'), { status: 404, code: 'resource_missing' });
			}
			return fake.prices[id];
		},
		async createRefund(params, idempotencyKey) {
			await fake.onRefund?.();
			if (fake.failRefund === 'already') {
				fake.manualRefunds[params.payment_intent] = { id: `re_manuel_${params.payment_intent}` };
				throw Object.assign(new Error('Stripe a répondu 400'), { status: 400, code: 'charge_already_refunded' });
			}
			if (fake.failRefund) {
				throw Object.assign(new Error('Stripe a répondu 500'), { status: 500, code: 'api_error' });
			}
			const existing = fake.refunds.find((refund) => refund.idempotencyKey === idempotencyKey);
			if (existing) {
				return existing;
			}
			const refund = { id: `re_${fake.refunds.length + 1}`, params, idempotencyKey };
			fake.refunds.push(refund);
			return refund;
		},
		async listRefunds(params) {
			const refund = fake.manualRefunds[params.payment_intent];
			return { data: refund ? [refund] : [] };
		},
		async listCheckoutSessions(params) {
			const start = params.starting_after ? fake.listed.findIndex((s) => s.id === params.starting_after) + 1 : 0;
			const data = fake.listed.slice(start, start + 2);
			return { data, has_more: start + 2 < fake.listed.length };
		},
	};
	return fake;
}

const sign = (payload, secret = WEBHOOK_SECRET, timestamp = Math.floor(Date.now() / 1000)) =>
	`t=${timestamp},v1=${createHmac('sha256', secret).update(`${timestamp}.${payload}`).digest('hex')}`;

describe('achat de packs par Stripe', { skip }, () => {
	let t;
	let token;
	let stripe;
	let ipCounter = 0;
	let eventCounter = 0;
	const freshIp = () => `198.51.100.${++ipCounter}`;
	const admin = (path, method, body) => t.request(path, { method, token, body });
	const createLicence = async (fields = {}) => (await admin('/v1/admin/licences', 'POST', { plan: 'solo', sites_max: 1, email: 'acheteuse@exemple.ca', ...fields })).json();
	const packId = async (slug) => String((await t.sql`select id from packs where slug = ${slug}`)[0].id);
	const checkout = (key, body = {}) => t.request('/v1/packs/checkout', {
		method: 'POST',
		body: { pack: 'vente', locale: 'fr_CA', ...body },
		headers: { 'X-Fluffless-Licence': key, 'X-Forwarded-For': freshIp() },
	});
	const webhook = (event, { signature, raw } = {}) => {
		const payload = raw ?? JSON.stringify(event);
		return t.request('/v1/webhooks/stripe', {
			method: 'POST',
			body: payload,
			headers: { 'Stripe-Signature': signature ?? sign(payload) },
		});
	};
	const paidSession = async (licenceId, slug, fields = {}) => {
		const n = ++eventCounter;
		return {
			id: `cs_test_paid_${n}`,
			object: 'checkout.session',
			payment_status: 'paid',
			payment_intent: `pi_${n}`,
			amount_total: 2185,
			currency: 'cad',
			metadata: { kind: 'pack', licence_id: String(licenceId), pack_id: await packId(slug), pack_slug: slug, locale: 'fr_CA' },
			customer_details: { address: { country: 'CA' } },
			...fields,
		};
	};
	const event = (type, object) => ({ id: `evt_${++eventCounter}`, type, data: { object } });

	before(async () => {
		stripe = createFakeStripe();
		t = await startTestServer({ env: STRIPE_ENV, stripe });
		token = t.env.ADMIN_TOKEN;
		for (const [slug, window] of [['vente', null], ['gratuit', [iso(-DAY), iso(DAY)]]]) {
			const res = await admin('/v1/admin/packs', 'POST', {
				slug,
				name: { fr: `Pack ${slug}`, en: `${slug} pack` },
				claim_opens: window?.[0] ?? null,
				claim_closes: window?.[1] ?? null,
				zip_base64: fakeZip(slug).toString('base64'),
			});
			assert.equal(res.status, 201);
		}
	});
	after(async () => t?.stop());

	test('admin : prix lu chez Stripe, abonnement et prix inconnu refusés, retrait de la vente', async () => {
		const res = await admin('/v1/admin/packs/vente/price', 'PUT', { price_id: 'price_ok123' });
		assert.equal(res.status, 200);
		assert.deepEqual(await res.json(), { slug: 'vente', stripe_price_id: 'price_ok123', price_amount: 1900, price_currency: 'cad' });
		assert.equal((await admin('/v1/admin/packs/gratuit/price', 'PUT', { price_id: 'price_abo123' })).status, 400);
		assert.equal((await admin('/v1/admin/packs/gratuit/price', 'PUT', { price_id: 'price_absent1' })).status, 400);
		for (const price of ['price_ttc123', 'price_usd123', 'price_bas123']) {
			assert.equal((await admin('/v1/admin/packs/gratuit/price', 'PUT', { price_id: price })).status, 400, `${price} refusé (taxes incluses, devise, minimum)`);
		}
		assert.equal((await admin('/v1/admin/packs/gratuit/price', 'PUT', { price_id: 'pas-un-prix' })).status, 400);
		assert.equal((await admin('/v1/admin/packs/inconnu/price', 'PUT', { price_id: null })).status, 404);
		assert.equal((await t.request('/v1/admin/packs/vente/price', { method: 'PUT', body: { price_id: null } })).status, 401);
		// Le pack gratuit se vend aussi, pour les tests de course.
		assert.equal((await admin('/v1/admin/packs/gratuit/price', 'PUT', { price_id: 'price_ok123' })).status, 200);
	});

	test('GET /v1/packs : pack en vente listé avec son prix hors taxes', async () => {
		const { licence } = await createLicence();
		const res = await t.request('/v1/packs', { headers: { 'X-Fluffless-Licence': licence, 'X-Forwarded-For': freshIp() } });
		const packs = (await res.json()).packs;
		const vente = packs.find((pack) => pack.slug === 'vente');
		assert.deepEqual({ buyable: vente.buyable, claimable: vente.claimable, price: vente.price }, { buyable: true, claimable: false, price: { amount: 1900, currency: 'cad' } });
		const gratuit = packs.find((pack) => pack.slug === 'gratuit');
		assert.deepEqual({ buyable: gratuit.buyable, claimable: gratuit.claimable }, { buyable: false, claimable: true }, 'jamais vendu pendant la fenêtre gratuite');
	});

	test('checkout : session sans courriel, métadonnées de la licence, Stripe Tax, adresse exigée', async () => {
		const { id, licence } = await createLicence();
		const res = await checkout(licence, { locale: 'en_CA' });
		assert.equal(res.status, 201);
		assert.match((await res.json()).url, /^https:\/\/checkout\.stripe\.com\//);
		const params = stripe.sessions.at(-1);
		assert.equal(params.customer_email, undefined, 'le courriel de la licence ne part pas chez Stripe');
		assert.deepEqual(params.metadata, { kind: 'pack', licence_id: String(id), pack_id: await packId('vente'), pack_slug: 'vente', locale: 'en_CA' });
		assert.deepEqual(params.payment_intent_data.metadata, params.metadata);
		assert.deepEqual(params.automatic_tax, { enabled: true });
		assert.equal(params.billing_address_collection, 'required');
		assert.match(params.custom_text.submit.message, /Canada/, 'la vente au Canada seulement est dite avant le paiement');
		assert.equal(params.locale, 'en');
		assert.deepEqual(params.line_items, [{ price: 'price_ok123', quantity: 1 }]);
		assert.equal(params.success_url, STRIPE_ENV.STRIPE_SUCCESS_URL);
	});

	test('checkout : refus clairs (déjà obtenu, pas en vente, expirée, sans courriel, champ inconnu)', async () => {
		const owner = await createLicence();
		await t.sql`insert into pack_claims (licence_id, pack_id, source) values (${owner.id}, ${await packId('vente')}, 'free_claim')`;
		const codeOf = async (res) => [res.status, (await res.json()).code];
		assert.deepEqual(await codeOf(await checkout(owner.licence)), [409, 'already_owned']);
		assert.deepEqual(await codeOf(await checkout(owner.licence, { pack: 'gratuit' })), [409, 'claim_open']);
		await admin('/v1/admin/packs', 'POST', { slug: 'prive', name: { fr: 'Privé', en: 'Private' }, zip_base64: fakeZip('p').toString('base64') });
		assert.deepEqual(await codeOf(await checkout(owner.licence, { pack: 'prive' })), [404, 'not_for_sale']);
		const expired = await createLicence({ expires: iso(-DAY) });
		assert.deepEqual(await codeOf(await checkout(expired.licence)), [403, 'licence_expired']);
		const mute = await createLicence({ email: null });
		const muteBody = await (await checkout(mute.licence)).json();
		assert.equal(muteBody.code, 'email_missing');
		assert.match(muteBody.message, /genevieve@fluffless\.dev/, 'X5-31 : adresse d\'aide décidée (D45)');
		assert.equal((await checkout(owner.licence, { email: 'autre@exemple.ca' })).status, 400, 'aucun courriel accepté du formulaire');
		assert.equal((await checkout('FAUSSE-CLE')).status, 403);
	});

	test('checkout : seules les sessions créées comptent pour le verrou, 10 par quart d\'heure', async () => {
		const owner = await createLicence();
		await t.sql`insert into pack_claims (licence_id, pack_id, source) values (${owner.id}, ${await packId('vente')}, 'free_claim')`;
		for (let i = 0; i < 12; i++) {
			assert.equal((await checkout(owner.licence)).status, 409, 'un refus ne consomme rien');
		}
		const buyer = await createLicence();
		for (let i = 0; i < 10; i++) {
			assert.equal((await checkout(buyer.licence)).status, 201);
		}
		const res = await checkout(buyer.licence);
		assert.equal(res.status, 429);
		assert.ok(Number(res.headers.get('retry-after')) > 0);
	});

	test('webhook : signature fausse, périmée ou absente refusée, rien d\'enregistré', async () => {
		const evt = event('checkout.session.completed', { id: 'cs_x' });
		const payload = JSON.stringify(evt);
		assert.equal((await webhook(evt, { signature: sign(payload, 'whsec_autresecretautre') })).status, 400);
		assert.equal((await webhook(evt, { signature: sign(payload, WEBHOOK_SECRET, Math.floor(Date.now() / 1000) - 600) })).status, 400);
		assert.equal((await webhook(evt, { signature: 't=1' })).status, 400);
		assert.equal((await webhook(evt, { raw: payload.replace('cs_x', 'cs_y'), signature: sign(payload) })).status, 400, 'corps modifié après signature');
		const [{ count }] = await t.sql`select count(*)::int as count from stripe_events where id = ${evt.id}`;
		assert.equal(count, 0);
	});

	test('webhook : paiement confirmé donne le pack et un lien de remerciement; un rejeu ne refait rien', async () => {
		const { id } = await createLicence({ email: 'merci@exemple.ca' });
		const session = await paidSession(id, 'vente');
		const evt = event('checkout.session.completed', session);
		const before = t.deliveries.length;
		const res = await webhook(evt);
		assert.equal(res.status, 200);
		const [claim] = await t.sql`select source from pack_claims where licence_id = ${id}`;
		assert.equal(claim.source, 'purchase');
		const [purchase] = await t.sql`select outcome, payment_intent, amount_total from pack_purchases where checkout_session_id = ${session.id}`;
		assert.deepEqual({ ...purchase }, { outcome: 'granted', payment_intent: session.payment_intent, amount_total: 2185 });
		assert.equal(t.deliveries.length, before + 1);
		const mail = t.deliveries.at(-1);
		assert.equal(mail.to, 'merci@exemple.ca');
		assert.equal(mail.purchase, true);
		const tok = mail.links[0].url.split('/').at(-1);
		assert.equal((await t.request(`/v1/packs/download/${tok}`, { method: 'POST' })).status, 200);

		const replay = await webhook(evt);
		assert.deepEqual(await replay.json(), { received: true, duplicate: true });
		// Même session dans un autre événement (async_payment_succeeded) : aucune seconde livraison.
		assert.equal((await webhook(event('checkout.session.async_payment_succeeded', session))).status, 200);
		assert.equal(t.deliveries.length, before + 1);
		assert.equal(stripe.refunds.length, 0);
		const [stored] = await t.sql`select payload from stripe_events where id = ${evt.id}`;
		assert.deepEqual(stored.payload, { object: session.id }, 'Loi 25 : rien de la cliente en base');
		assert.equal(JSON.stringify(t.logs).includes('exemple.ca'), false);
	});

	test('livraison ratée (SMTP absent ou en erreur) : pack acquis, alerte pour écrire à la cliente', async () => {
		for (const failing of [async () => false, async () => { throw Object.assign(new Error('smtp'), { code: 'ECONNREFUSED' }); }]) {
			const { id } = await createLicence();
			const session = await paidSession(id, 'vente');
			const before = t.alerts.length;
			t.setDeliver(failing);
			try {
				assert.equal((await webhook(event('checkout.session.completed', session))).status, 200);
			} finally {
				t.setDeliver();
			}
			const [purchase] = await t.sql`select outcome from pack_purchases where checkout_session_id = ${session.id}`;
			assert.equal(purchase.outcome, 'granted');
			assert.equal(t.alerts.length, before + 1);
			assert.equal(t.alerts.at(-1).subject, 'Achat payé, courriel de livraison non parti');
			assert.match(t.alerts.at(-1).lines.join('\n'), new RegExp(`Licence : ${id}\\b`));
		}
	});

	test('webhook : paiement en attente ou autre produit ignoré sans erreur', async () => {
		const { id } = await createLicence();
		const pending = await paidSession(id, 'vente', { payment_status: 'unpaid' });
		assert.equal((await webhook(event('checkout.session.completed', pending))).status, 200);
		const other = await paidSession(id, 'vente', { metadata: { kind: 'autre' } });
		assert.equal((await webhook(event('checkout.session.completed', other))).status, 200);
		assert.equal((await webhook(event('customer.created', { id: 'cus_1' }))).status, 200);
		const [{ count }] = await t.sql`select count(*)::int as count from pack_purchases where licence_id = ${id}`;
		assert.equal(count, 0);
	});

	test('course perdue : pack déjà réclamé gratuitement, paiement remboursé une seule fois et alerte', async () => {
		const { id } = await createLicence();
		await t.sql`insert into pack_claims (licence_id, pack_id, source) values (${id}, ${await packId('gratuit')}, 'free_claim')`;
		const session = await paidSession(id, 'gratuit');
		const alertsBefore = t.alerts.length;
		assert.equal((await webhook(event('checkout.session.completed', session))).status, 200);
		const refund = stripe.refunds.at(-1);
		assert.equal(refund.params.payment_intent, session.payment_intent);
		assert.equal(refund.params.reason, 'duplicate');
		assert.equal(refund.idempotencyKey, `refund-${session.id}`);
		const [purchase] = await t.sql`select outcome, refund_reason, refund_id from pack_purchases where checkout_session_id = ${session.id}`;
		assert.deepEqual({ ...purchase }, { outcome: 'refunded', refund_reason: 'already_owned', refund_id: refund.id });
		const [claim] = await t.sql`select source from pack_claims where licence_id = ${id}`;
		assert.equal(claim.source, 'free_claim', 'la réclamation gratuite garde la ligne');
		assert.equal(t.alerts.length, alertsBefore + 1);
		assert.match(t.alerts.at(-1).lines.join('\n'), /already_owned/);
		const refunds = stripe.refunds.length;
		assert.equal((await webhook(event('checkout.session.completed', session))).status, 200);
		assert.equal(stripe.refunds.length, refunds);
		// Le charge.refunded de notre propre remboursement ne double pas l'alerte.
		assert.equal((await webhook(event('charge.refunded', { id: 'ch_auto', payment_intent: session.payment_intent, amount_refunded: 2185, currency: 'cad' }))).status, 200);
		assert.equal(t.alerts.length, alertsBefore + 1);
	});

	test('déjà remboursé à la main chez Stripe : la ligne reprend ce remboursement', async () => {
		const { id } = await createLicence();
		await t.sql`insert into pack_claims (licence_id, pack_id, source) values (${id}, ${await packId('vente')}, 'free_claim')`;
		const session = await paidSession(id, 'vente');
		stripe.failRefund = 'already';
		try {
			assert.equal((await webhook(event('checkout.session.completed', session))).status, 200);
		} finally {
			stripe.failRefund = false;
		}
		const [purchase] = await t.sql`select outcome, refund_id from pack_purchases where checkout_session_id = ${session.id}`;
		assert.deepEqual({ ...purchase }, { outcome: 'refunded', refund_id: `re_manuel_${session.payment_intent}` });
	});

	test('remboursement en échec : 500 pour que Stripe réessaie, puis la réconciliation le termine', async () => {
		const { id } = await createLicence();
		await t.sql`insert into pack_claims (licence_id, pack_id, source) values (${id}, ${await packId('vente')}, 'free_claim')`;
		const session = await paidSession(id, 'vente');
		const evt = event('checkout.session.completed', session);
		stripe.failRefund = true;
		assert.equal((await webhook(evt)).status, 500);
		const [pending] = await t.sql`select outcome from pack_purchases where checkout_session_id = ${session.id}`;
		assert.equal(pending.outcome, 'refund_pending');
		const [stored] = await t.sql`select processed_at from stripe_events where id = ${evt.id}`;
		assert.equal(stored.processed_at, null);
		// Encore en panne à la réconciliation : pas d'alerte avant 48 h, une alerte après.
		const alertsBefore = t.alerts.length;
		assert.equal((await t.reconcile()).refunds_pending, 1);
		assert.equal(t.alerts.length, alertsBefore);
		await t.sql`update pack_purchases set created_at = now() - interval '3 days' where checkout_session_id = ${session.id}`;
		await t.reconcile();
		assert.equal(t.alerts.at(-1).subject, 'Remboursements bloqués depuis plus de 48 h');
		assert.match(t.alerts.at(-1).lines.join('\n'), new RegExp(session.id));
		stripe.failRefund = false;
		const result = await t.reconcile();
		assert.equal(result.refunds_retried >= 1, true);
		const [done] = await t.sql`select outcome from pack_purchases where checkout_session_id = ${session.id}`;
		assert.equal(done.outcome, 'refunded');
		// Le nouvel essai de Stripe trouve la ligne réglée et ne rembourse pas deux fois.
		const refunds = stripe.refunds.length;
		assert.equal((await webhook(evt)).status, 200);
		assert.equal(stripe.refunds.length, refunds);
	});

	test('licence effacée avant le webhook : remboursement, raison licence_missing', async () => {
		const session = await paidSession('999999', 'vente');
		assert.equal((await webhook(event('checkout.session.completed', session))).status, 200);
		const [purchase] = await t.sql`select outcome, refund_reason, licence_id from pack_purchases where checkout_session_id = ${session.id}`;
		assert.deepEqual({ ...purchase }, { outcome: 'refunded', refund_reason: 'licence_missing', licence_id: null });
	});

	test('adresse de facturation hors Canada, ou absente : remboursement sans accès, raison outside_canada', async () => {
		for (const details of [{ address: { country: 'FR' } }, null]) {
			const { id } = await createLicence();
			const session = await paidSession(id, 'vente', { customer_details: details });
			const deliveries = t.deliveries.length;
			assert.equal((await webhook(event('checkout.session.completed', session))).status, 200);
			const [purchase] = await t.sql`select outcome, refund_reason from pack_purchases where checkout_session_id = ${session.id}`;
			assert.deepEqual({ ...purchase }, { outcome: 'refunded', refund_reason: 'outside_canada' });
			assert.equal((await t.sql`select 1 from pack_claims where licence_id = ${id}`).length, 0);
			assert.equal(t.deliveries.length, deliveries, 'aucun lien envoyé');
			assert.equal(stripe.refunds.at(-1).params.metadata.refund_reason, 'outside_canada');
		}
		// Même règle quand le paiement est rattrapé par la réconciliation plutôt que par le webhook.
		const { id } = await createLicence();
		const missed = await paidSession(id, 'vente', { customer_details: { address: { country: 'US' } } });
		stripe.listed = [missed];
		assert.equal((await t.reconcile()).settled, 1);
		const [purchase] = await t.sql`select outcome, refund_reason from pack_purchases where checkout_session_id = ${missed.id}`;
		assert.deepEqual({ ...purchase }, { outcome: 'refunded', refund_reason: 'outside_canada' });
		assert.equal((await t.sql`select 1 from pack_claims where licence_id = ${id}`).length, 0);
	});

	// L'interrupteur est lu à chaque session : on le bascule sur la config du serveur de test.
	const withManaged = async (fn) => {
		t.config.stripe.managedPayments = true;
		try {
			await fn();
		} finally {
			t.config.stripe.managedPayments = false;
		}
	};

	test('Managed Payments : managed_payments au lieu de Stripe Tax, mode noté dans les métadonnées', async () => {
		await withManaged(async () => {
			const { id, licence } = await createLicence();
			assert.equal((await checkout(licence)).status, 201);
			const params = stripe.sessions.at(-1);
			assert.deepEqual(params.managed_payments, { enabled: true });
			assert.equal(params.automatic_tax, undefined, 'automatic_tax est interdit avec Managed Payments');
			assert.equal(params.billing_address_collection, 'required');
			assert.equal(params.metadata.sale, 'managed');
			assert.equal(params.metadata.licence_id, String(id));
			assert.deepEqual(params.payment_intent_data.metadata, params.metadata);
			assert.doesNotMatch(params.custom_text.submit.message, /Canada/);
			assert.match(params.custom_text.submit.message, /remboursé automatiquement/);
		});
		// Interrupteur coupé : retour à Stripe Tax, sans mode dans les métadonnées.
		const { licence } = await createLicence();
		assert.equal((await checkout(licence)).status, 201);
		const params = stripe.sessions.at(-1);
		assert.equal(params.managed_payments, undefined);
		assert.deepEqual(params.automatic_tax, { enabled: true });
		assert.equal(params.metadata.sale, undefined);
	});

	test('Managed Payments : pays couverts servis, autres remboursés (country_not_covered), règle tirée de la session', async () => {
		const managedSession = async (licenceId, country) => {
			const session = await paidSession(licenceId, 'vente', { customer_details: country ? { address: { country } } : null });
			session.metadata = { ...session.metadata, sale: 'managed' };
			return session;
		};
		const outcomeOf = async (session) => {
			const [purchase] = await t.sql`select outcome, refund_reason from pack_purchases where checkout_session_id = ${session.id}`;
			return { ...purchase };
		};
		// L'interrupteur reste coupé : une session ouverte en mode Managed Payments se règle selon son mode.
		for (const country of ['US', 'FR', 'CA', 'JP']) {
			const { id } = await createLicence();
			const session = await managedSession(id, country);
			assert.equal((await webhook(event('checkout.session.completed', session))).status, 200);
			assert.deepEqual(await outcomeOf(session), { outcome: 'granted', refund_reason: null }, country);
			assert.equal((await t.sql`select 1 from pack_claims where licence_id = ${id}`).length, 1);
		}
		for (const country of ['BR', 'CL', null]) {
			const { id } = await createLicence();
			const session = await managedSession(id, country);
			assert.equal((await webhook(event('checkout.session.completed', session))).status, 200);
			assert.deepEqual(await outcomeOf(session), { outcome: 'refunded', refund_reason: 'country_not_covered' }, String(country));
			assert.equal((await t.sql`select 1 from pack_claims where licence_id = ${id}`).length, 0);
			assert.equal(stripe.refunds.at(-1).params.metadata.refund_reason, 'country_not_covered');
		}
		// Même règle par la réconciliation.
		const { id } = await createLicence();
		const missed = await managedSession(id, 'CO');
		stripe.listed = [missed];
		assert.equal((await t.reconcile()).settled, 1);
		assert.deepEqual(await outcomeOf(missed), { outcome: 'refunded', refund_reason: 'country_not_covered' });
		// Et l'inverse : interrupteur allumé, une session ouverte en mode Canada garde sa règle.
		await withManaged(async () => {
			const other = await createLicence();
			const session = await paidSession(other.id, 'vente', { customer_details: { address: { country: 'US' } } });
			assert.equal((await webhook(event('checkout.session.completed', session))).status, 200);
			assert.deepEqual(await outcomeOf(session), { outcome: 'refunded', refund_reason: 'outside_canada' });
		});
	});

	test('Managed Payments : le prix doit avoir un produit avec code fiscal', async () => {
		await withManaged(async () => {
			const res = await admin('/v1/admin/packs/gratuit/price', 'PUT', { price_id: 'price_ok123' });
			assert.equal(res.status, 400);
			assert.match((await res.json()).message, /code fiscal/);
			assert.equal((await admin('/v1/admin/packs/gratuit/price', 'PUT', { price_id: 'price_mp123' })).status, 200);
			assert.deepEqual(stripe.priceParams, { expand: ['product'] });
		});
		// Hors Managed Payments, le code fiscal n'est pas exigé (Stripe Tax prend celui par défaut).
		assert.equal((await admin('/v1/admin/packs/gratuit/price', 'PUT', { price_id: 'price_ok123' })).status, 200);
		assert.equal(stripe.priceParams, undefined, 'produit non lu hors Managed Payments (clé sans droit Products)');
	});

	test('remboursement et litige vus par Stripe : alerte avec la source, accès gardé', async () => {
		const { id } = await createLicence();
		const session = await paidSession(id, 'vente');
		await webhook(event('checkout.session.completed', session));
		const before = t.alerts.length;
		assert.equal((await webhook(event('charge.refunded', { id: 'ch_1', payment_intent: session.payment_intent, amount_refunded: 2185, currency: 'cad' }))).status, 200);
		assert.equal((await webhook(event('charge.dispute.created', { id: 'dp_1', payment_intent: session.payment_intent, amount: 2185, currency: 'cad', reason: 'fraudulent' }))).status, 200);
		assert.equal(t.alerts.length, before + 2);
		const [refunded, dispute] = t.alerts.slice(-2).map((alert) => alert.lines.join('\n'));
		assert.match(refunded, /Pack : vente, licence \d+, issue granted, accès actuel : purchase/);
		assert.match(refunded, /21\.85 CAD/);
		assert.match(dispute, /fraudulent/);
		assert.equal(t.alerts.slice(-2).some((alert) => alert.lines.join('').includes('@')), false, 'aucun courriel dans les alertes');
		const [claim] = await t.sql`select source from pack_claims where licence_id = ${id}`;
		assert.equal(claim.source, 'purchase');
		assert.equal((await webhook(event('charge.refunded', { id: 'ch_2', payment_intent: 'pi_inconnu', amount_refunded: 100, currency: 'cad' }))).status, 200);
		assert.match(t.alerts.at(-1).lines.join('\n'), /Aucun achat de pack ni de licence connu/);
	});

	test('réconciliation : paiement sans webhook rattrapé et signalé, pages suivies, déjà connus ignorés', async () => {
		const { id } = await createLicence({ email: 'rattrapage@exemple.ca' });
		const missed = await paidSession(id, 'vente');
		const known = await paidSession(id, 'vente');
		await t.sql`insert into pack_purchases (checkout_session_id, amount_total, currency, outcome) values (${known.id}, 1, 'cad', 'granted')`;
		// Licence effacée et paiement sans payment_intent : le remboursement échoue, la suite passe.
		const broken = await paidSession('999998', 'vente', { payment_intent: null });
		stripe.listed = [
			broken,
			{ id: 'cs_autre', metadata: {}, payment_status: 'paid' },
			known,
			{ ...(await paidSession(id, 'vente')), payment_status: 'unpaid' },
			missed,
		];
		const before = t.alerts.length;
		const result = await t.reconcile();
		assert.equal(result.checked, 5);
		assert.equal(result.settled, 1);
		const [purchase] = await t.sql`select outcome from pack_purchases where checkout_session_id = ${missed.id}`;
		assert.equal(purchase.outcome, 'granted');
		assert.equal(t.deliveries.at(-1).to, 'rattrapage@exemple.ca');
		assert.equal(t.alerts.length, before + 1);
		assert.equal((await t.reconcile()).settled, 0);
		const res = await admin('/v1/admin/stripe/reconcile', 'POST');
		assert.equal(res.status, 200);
	});

	// Concurrence : requêtes lancées en parallèle contre le vrai Postgres (pool de 3 connexions).
	const claimFree = (key) => t.request('/v1/packs/claim', {
		method: 'POST',
		body: { packs: ['gratuit'], locale: 'fr_CA' },
		headers: { 'X-Fluffless-Licence': key, 'X-Forwarded-For': freshIp() },
	});
	const refundsFor = (paymentIntent) => stripe.refunds.filter((refund) => refund.params.payment_intent === paymentIntent).length;

	test('concurrence : deux onglets, une seule session reste ouverte', async () => {
		const { id, licence } = await createLicence();
		const first = await checkout(licence);
		const second = await checkout(licence);
		assert.deepEqual([first.status, second.status], [201, 201]);
		const [older, newer] = stripe.sessions.slice(-2).map((_, index) => `cs_test_${stripe.sessions.length - 1 + index}`);
		assert.deepEqual(stripe.expired.slice(-1), [older]);
		assert.equal(stripe.expired.includes(newer), false);
		assert.equal(stripe.sessions.at(-1).expires_at - Math.floor(Date.now() / 1000) <= 3_600, true, 'session d\'une heure au plus');

		const expiredBefore = stripe.expired.length;
		const parallel = await Promise.all(Array.from({ length: 4 }, () => checkout(licence)));
		assert.deepEqual(parallel.map((res) => res.status), [201, 201, 201, 201]);
		const open = await t.sql`select checkout_session_id from pack_checkouts where licence_id = ${id}`;
		assert.equal(open.length, 1, 'une seule session survit');
		assert.equal(stripe.expired.length - expiredBefore, 4, 'la précédente et trois des quatre nouvelles expirent');
		const returned = await Promise.all(parallel.map(async (res) => (await res.json()).url.split('/').at(-1)));
		assert.equal(returned.includes(open[0].checkout_session_id), true);
		assert.equal(stripe.expired.includes(open[0].checkout_session_id), false);
	});

	test('concurrence : réclamation gratuite, la session d\'achat ouverte avant la fenêtre expire', async () => {
		const { id, licence } = await createLicence();
		await t.sql`insert into pack_checkouts (checkout_session_id, licence_id, pack_id) values ('cs_test_avant', ${id}, ${await packId('gratuit')})`;
		assert.equal((await claimFree(licence)).status, 202);
		assert.equal(stripe.expired.at(-1), 'cs_test_avant');
		const [{ count }] = await t.sql`select count(*)::int as count from pack_checkouts where licence_id = ${id}`;
		assert.equal(count, 0);
	});

	test('concurrence : le même événement livré 6 fois en parallèle se traite une fois', async () => {
		const { id } = await createLicence();
		const evt = event('checkout.session.completed', await paidSession(id, 'vente'));
		const deliveriesBefore = t.deliveries.length;
		const responses = await Promise.all(Array.from({ length: 6 }, () => webhook(evt)));
		const bodies = await Promise.all(responses.map((res) => res.json()));
		const processed = responses.filter((res, i) => res.status === 200 && !bodies[i].duplicate).length;
		assert.equal(processed, 1, `réponses : ${responses.map((res) => res.status).join(', ')}`);
		assert.equal(responses.every((res) => res.status === 200 || res.status === 409), true);
		assert.equal(t.deliveries.length, deliveriesBefore + 1);
		// Après le traitement, un rejeu est un doublon simple.
		assert.deepEqual(await (await webhook(evt)).json(), { received: true, duplicate: true });
	});

	test('bail : événement en cours ailleurs, 409; bail périmé (processus tombé), repris', async () => {
		const { id } = await createLicence();
		const evt = event('checkout.session.completed', await paidSession(id, 'vente'));
		await t.sql`insert into stripe_events (id, type, processing_until) values (${evt.id}, ${evt.type}, now() + interval '4 minutes')`;
		const busy = await webhook(evt);
		assert.equal(busy.status, 409);
		assert.equal((await busy.json()).code, 'event_in_progress');
		await t.sql`update stripe_events set processing_until = now() - interval '1 second' where id = ${evt.id}`;
		assert.deepEqual(await (await webhook(evt)).json(), { received: true });
		const [row] = await t.sql`select processed_at, processing_until from stripe_events where id = ${evt.id}`;
		assert.ok(row.processed_at);
		assert.equal(row.processing_until, null);
	});

	test('concurrence : la même session dans 6 événements différents en parallèle, une livraison', async () => {
		const { id } = await createLicence();
		const session = await paidSession(id, 'vente');
		const deliveriesBefore = t.deliveries.length;
		const responses = await Promise.all(Array.from({ length: 6 }, (_, i) =>
			webhook(event(i % 2 ? 'checkout.session.async_payment_succeeded' : 'checkout.session.completed', session))));
		assert.deepEqual(responses.map((res) => res.status), [200, 200, 200, 200, 200, 200]);
		assert.equal(t.deliveries.length, deliveriesBefore + 1);
		assert.equal(refundsFor(session.payment_intent), 0);
		const [{ count }] = await t.sql`select count(*)::int as count from pack_claims where licence_id = ${id}`;
		assert.equal(count, 1);
	});

	test('concurrence : deux paiements du même pack en parallèle, un accordé, un remboursé une fois', async () => {
		const { id } = await createLicence();
		const [a, b] = [await paidSession(id, 'vente'), await paidSession(id, 'vente')];
		const alertsBefore = t.alerts.length;
		const responses = await Promise.all([webhook(event('checkout.session.completed', a)), webhook(event('checkout.session.completed', b))]);
		assert.deepEqual(responses.map((res) => res.status), [200, 200]);
		const outcomes = (await t.sql`select outcome from pack_purchases where checkout_session_id in ${t.sql([a.id, b.id])}`).map((row) => row.outcome).sort();
		assert.deepEqual(outcomes, ['granted', 'refunded']);
		assert.equal(refundsFor(a.payment_intent) + refundsFor(b.payment_intent), 1);
		assert.equal(t.alerts.length, alertsBefore + 1);
	});

	test('concurrence : réclamation gratuite contre paiement, invariants tenus dans les deux ordres', async (tc) => {
		const orders = { free_claim: 0, purchase: 0 };
		for (let round = 0; round < 10; round++) {
			const { id, licence } = await createLicence();
			const session = await paidSession(id, 'gratuit');
			const evt = event('checkout.session.completed', session);
			const [claimRes, hookRes] = round % 2
				? await Promise.all([claimFree(licence), webhook(evt)])
				: (await Promise.all([webhook(evt), claimFree(licence)])).reverse();
			assert.equal(claimRes.status, 202);
			assert.deepEqual((await claimRes.json()).sent, ['gratuit'], 'la licence reçoit toujours son lien');
			assert.equal(hookRes.status, 200);
			const claims = await t.sql`select source from pack_claims where licence_id = ${id}`;
			assert.equal(claims.length, 1);
			const [purchase] = await t.sql`select outcome from pack_purchases where checkout_session_id = ${session.id}`;
			const source = claims[0].source;
			orders[source]++;
			assert.equal(purchase.outcome, source === 'purchase' ? 'granted' : 'refunded');
			assert.equal(refundsFor(session.payment_intent), source === 'purchase' ? 0 : 1);
		}
		tc.diagnostic(`gagnants : gratuit ${orders.free_claim}, achat ${orders.purchase}`);
	});

	test('concurrence : réconciliation et webhook en même temps, une livraison, alerte seulement si rattrapé', async () => {
		const { id } = await createLicence();
		const session = await paidSession(id, 'vente');
		stripe.listed = [session];
		const deliveriesBefore = t.deliveries.length;
		const alertsBefore = t.alerts.length;
		const [hookRes, result] = await Promise.all([webhook(event('checkout.session.completed', session)), t.reconcile()]);
		assert.equal(hookRes.status, 200);
		assert.equal(t.deliveries.length, deliveriesBefore + 1);
		assert.equal(t.alerts.length - alertsBefore, result.settled > 0 ? 1 : 0);
		stripe.listed = [];
	});

	test('concurrence : remboursement vu deux fois en parallèle, une seule alerte', async () => {
		const { id } = await createLicence();
		const session = await paidSession(id, 'vente');
		await webhook(event('checkout.session.completed', session));
		const evt = event('charge.refunded', { id: 'ch_par', payment_intent: session.payment_intent, amount_refunded: 2185, currency: 'cad' });
		const alertsBefore = t.alerts.length;
		await Promise.all([webhook(evt), webhook(evt), webhook(evt)]);
		assert.equal(t.alerts.length, alertsBefore + 1);
	});

	test('concurrence : réclamation ou fenêtre survenue pendant l\'appel à Stripe, la session expire, 409', async () => {
		const { id, licence } = await createLicence();
		const vente = await packId('vente');
		stripe.onCreate = () => t.sql`insert into pack_claims (licence_id, pack_id, source) values (${id}, ${vente}, 'free_claim')`;
		const owned = await checkout(licence);
		stripe.onCreate = null;
		assert.equal(owned.status, 409);
		assert.equal((await owned.json()).code, 'already_owned');
		assert.equal(stripe.expired.at(-1), `cs_test_${stripe.sessions.length}`, 'la session créée est fermée');
		assert.equal((await t.sql`select 1 from pack_checkouts where licence_id = ${id}`).length, 0);

		const other = await createLicence();
		stripe.onCreate = () => t.sql`update packs set claim_opens_at = now() - interval '1 minute', claim_closes_at = now() + interval '1 day' where id = ${vente}`;
		const opened = await checkout(other.licence);
		stripe.onCreate = null;
		await t.sql`update packs set claim_opens_at = null, claim_closes_at = null where id = ${vente}`;
		assert.equal(opened.status, 409);
		assert.equal((await opened.json()).code, 'claim_open');
		assert.equal(stripe.expired.at(-1), `cs_test_${stripe.sessions.length}`);

		// Fenêtre posée pendant l'appel, qui ouvrira avant la fin de la session d'une heure.
		const third = await createLicence();
		stripe.onCreate = () => t.sql`update packs set claim_opens_at = now() + interval '40 minutes', claim_closes_at = now() + interval '1 day' where id = ${vente}`;
		const later = await checkout(third.licence);
		stripe.onCreate = null;
		await t.sql`update packs set claim_opens_at = null, claim_closes_at = null where id = ${vente}`;
		assert.equal(later.status, 409);
		assert.equal((await later.json()).code, 'claim_open');
		assert.equal(stripe.expired.at(-1), `cs_test_${stripe.sessions.length}`);
		assert.equal((await t.sql`select 1 from pack_checkouts where licence_id = ${third.id}`).length, 0);
	});

	test('fenêtre gratuite à venir : session bornée à son ouverture, refus à moins de 30 min, sessions fermées à la pose', async () => {
		const { id, licence } = await createLicence();
		const setWindow = (opens, closes) => admin('/v1/admin/packs/vente', 'PATCH', { claim_opens: opens, claim_closes: closes });
		assert.equal((await checkout(licence)).status, 201);
		const openSession = `cs_test_${stripe.sessions.length}`;
		// Une fenêtre lointaine laisse la session expirer d'elle-même.
		const expiredBefore = stripe.expired.length;
		assert.equal((await setWindow(iso(14 * DAY), iso(15 * DAY))).status, 200);
		assert.equal(stripe.expired.length, expiredBefore);
		assert.equal((await t.sql`select 1 from pack_checkouts where licence_id = ${id}`).length, 1);
		const opens = new Date(Date.now() + 45 * 60_000);
		const res = await setWindow(opens.toISOString(), iso(DAY));
		assert.equal(res.status, 200);
		assert.deepEqual(Object.keys(await res.json()).sort(), ['claim_closes_at', 'claim_opens_at', 'slug']);
		assert.equal(stripe.expired.at(-1), openSession, 'la session ouverte avant la pose de la fenêtre est fermée');
		assert.equal((await t.sql`select 1 from pack_checkouts where licence_id = ${id}`).length, 0);

		assert.equal((await checkout(licence)).status, 201);
		assert.equal(stripe.sessions.at(-1).expires_at, Math.floor(opens.getTime() / 1000), 'la session expire à l\'ouverture');
		await setWindow(iso(10 * 60_000), iso(DAY));
		const soon = await checkout(licence);
		assert.equal(soon.status, 409);
		assert.equal((await soon.json()).code, 'claim_open');
		await setWindow(null, null);
	});

	test('bail : un échec ne libère pas un bail repris entre-temps par une autre livraison', async () => {
		const { id } = await createLicence();
		await t.sql`insert into pack_claims (licence_id, pack_id, source) values (${id}, ${await packId('vente')}, 'free_claim')`;
		const evt = event('checkout.session.completed', await paidSession(id, 'vente'));
		stripe.failRefund = true;
		stripe.onRefund = () => t.sql`update stripe_events set processing_until = now() + interval '10 minutes' where id = ${evt.id}`;
		assert.equal((await webhook(evt)).status, 500);
		stripe.failRefund = false;
		stripe.onRefund = null;
		const [row] = await t.sql`select processing_until from stripe_events where id = ${evt.id}`;
		assert.notEqual(row.processing_until, null, 'le bail de l\'autre livraison reste');
		assert.equal((await webhook(evt)).status, 409);
	});

	test('concurrence : lien de téléchargement ouvert 8 fois en parallèle, un seul zip', async () => {
		const { id } = await createLicence();
		await webhook(event('checkout.session.completed', await paidSession(id, 'vente')));
		const tok = t.deliveries.at(-1).links[0].url.split('/').at(-1);
		const responses = await Promise.all(Array.from({ length: 8 }, () => t.request(`/v1/packs/download/${tok}`, { method: 'POST' })));
		const statuses = responses.map((res) => res.status);
		// Au-delà de 4 envois simultanés, 503 : le lien n'est pas consommé, le 200 reste unique.
		assert.equal(statuses.filter((s) => s === 200).length, 1, statuses.join());
		assert.ok(statuses.every((s) => [200, 410, 503].includes(s)), statuses.join());
		assert.equal((await t.request(`/v1/packs/download/${tok}`, { method: 'POST' })).status, 410);
	});
});
