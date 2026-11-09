// Packs curés : réclamation gratuite par licence, livraison par courriel, lien à usage unique.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { verify } from 'node:crypto';
import { fakeZip, startTestServer } from './helpers.mjs';

const skip = process.env.API_TEST_SKIP_DB === '1' ? 'API_TEST_SKIP_DB=1' : false;
const DAY = 86_400_000;
const iso = (offsetMs) => new Date(Date.now() + offsetMs).toISOString();
const PROXY_TOKEN = 'p'.repeat(40);

describe('packs curés', { skip }, () => {
	let t;
	let token;
	let ipCounter = 0;
	// Chaque test prend sa propre adresse : les limites par IP ne débordent pas d'un test à l'autre.
	const freshIp = () => `203.0.113.${++ipCounter}`;

	const admin = (path, method, body) => t.request(path, { method, token, body });
	const createPack = (fields) => admin('/v1/admin/packs', 'POST', {
		slug: 'printemps',
		name: { fr: 'Pack Printemps', en: 'Spring pack' },
		description: { fr: 'Gabarits', en: 'Templates' },
		claim_opens: iso(-DAY),
		claim_closes: iso(DAY),
		zip_base64: fakeZip('pack').toString('base64'),
		...fields,
	});
	const createLicence = async (fields = {}) => (await admin('/v1/admin/licences', 'POST', { plan: 'fondateur', sites_max: 1, email: 'cliente@exemple.ca', ...fields })).json();
	const list = (key, ip = freshIp(), locale = 'fr_CA') => t.request(`/v1/packs?locale=${locale}`, {
		headers: { 'X-Fluffless-Licence': key, 'X-Forwarded-For': ip },
	});
	const claim = (key, body, ip = freshIp(), headers = {}) => t.request('/v1/packs/claim', {
		method: 'POST',
		body: { locale: 'fr_CA', ...body },
		headers: { 'X-Fluffless-Licence': key, 'X-Forwarded-For': ip, ...headers },
	});
	const linkToken = (delivery, index = 0) => delivery.links[index].url.split('/').at(-1);
	const download = (tok, method = 'POST') => t.request(`/v1/packs/download/${tok}`, { method });

	before(async () => {
		t = await startTestServer({ env: { PACKS_PROXY_TOKEN: PROXY_TOKEN } });
		token = t.env.ADMIN_TOKEN;
		assert.equal((await createPack({})).status, 201);
		assert.equal((await createPack({ slug: 'hiver', name: { fr: 'Pack Hiver', en: 'Winter pack' }, claim_opens: iso(-3 * DAY), claim_closes: iso(-DAY) })).status, 201);
		assert.equal((await createPack({ slug: 'payant', name: { fr: 'Pack payant', en: 'Paid pack' }, claim_opens: null, claim_closes: null })).status, 201);
		assert.equal((await createPack({ slug: 'automne', name: { fr: 'Pack Automne', en: 'Fall pack' } })).status, 201);
	});
	after(async () => t?.stop());

	test('admin : pack signé, doublon et fenêtres incohérentes refusés, jeton exigé', async () => {
		const res = await createPack({ slug: 'ete' });
		assert.equal(res.status, 201);
		const body = await res.json();
		assert.equal(verify(null, fakeZip('pack'), t.publicKey, Buffer.from(body.signature, 'base64')), true);
		assert.equal((await createPack({ slug: 'ete' })).status, 409);
		assert.equal((await createPack({ slug: 'x1', claim_closes: null })).status, 400, 'ouverture sans fermeture');
		assert.equal((await createPack({ slug: 'x2', claim_opens: iso(DAY), claim_closes: iso(-DAY) })).status, 400, 'fermeture avant ouverture');
		assert.equal((await createPack({ slug: 'x3', zip_base64: Buffer.from('pas un zip').toString('base64') })).status, 400);
		assert.equal((await createPack({ slug: 'x4', bonus: 1 })).status, 400);
		assert.equal((await t.request('/v1/admin/packs', { method: 'POST', body: {} })).status, 401);
		const patched = await admin('/v1/admin/packs/ete', 'PATCH', { claim_opens: null, claim_closes: null });
		assert.equal(patched.status, 200);
		assert.equal((await admin('/v1/admin/packs/ete', 'DELETE')).status, 204);
		assert.equal((await admin('/v1/admin/packs/ete', 'DELETE')).status, 404);
	});

	test('admin : courriel de licence validé, modifiable, jamais journalisé', async () => {
		assert.equal((await admin('/v1/admin/licences', 'POST', { plan: 'solo', sites_max: 1, email: 'pas-un-courriel' })).status, 400);
		const { id } = await createLicence({ email: 'avant@exemple.ca' });
		const res = await admin(`/v1/admin/licences/${id}`, 'PATCH', { email: 'apres@exemple.ca' });
		assert.equal(res.status, 200);
		const [row] = await t.sql`select email from licences where id = ${id}`;
		assert.equal(row.email, 'apres@exemple.ca');
		assert.equal((await admin('/v1/admin/licences/999999', 'PATCH', { email: 'a@b.ca' })).status, 404);
		assert.equal(JSON.stringify(t.logs).includes('exemple.ca'), false);
	});

	test('GET /v1/packs : packs réclamables seulement, licence exigée par en-tête', async () => {
		const { licence } = await createLicence();
		const res = await list(licence);
		assert.equal(res.status, 200);
		const { packs } = await res.json();
		assert.deepEqual(packs.map((p) => p.slug).sort(), ['automne', 'printemps']);
		const printemps = packs.find((p) => p.slug === 'printemps');
		assert.deepEqual(Object.keys(printemps).sort(), ['buyable', 'claim_closes', 'claimable', 'description', 'name', 'owned', 'price', 'slug']);
		assert.deepEqual([printemps.name, printemps.claimable, printemps.owned], ['Pack Printemps', true, false]);
		const en = await (await list(licence, freshIp(), 'en_CA')).json();
		assert.equal(en.packs.find((p) => p.slug === 'printemps').name, 'Spring pack');
		assert.equal((await list('FL-FAUX')).status, 403);
		assert.equal((await t.request('/v1/packs', { headers: { 'X-Forwarded-For': freshIp() } })).status, 403);
		assert.equal((await t.request(`/v1/packs?licence=${licence}`, { headers: { 'X-Forwarded-For': freshIp() } })).status, 400);
	});

	test('réclamation : ligne free_claim, lien envoyé à l\'adresse de la licence seulement', async () => {
		const { id, licence } = await createLicence({ email: 'proprio@exemple.ca' });
		const before = t.deliveries.length;
		const withEmail = await claim(licence, { packs: ['printemps'], email: 'pirate@exemple.ca' });
		assert.equal(withEmail.status, 400, 'aucun champ courriel accepté');
		const res = await claim(licence, { packs: ['printemps', 'hiver', 'payant', 'inconnu'] });
		assert.equal(res.status, 202);
		assert.deepEqual(await res.json(), {
			sent: ['printemps'],
			refused: [{ slug: 'hiver', code: 'claim_closed' }, { slug: 'payant', code: 'claim_closed' }, { slug: 'inconnu', code: 'unknown_pack' }],
		});
		assert.equal(t.deliveries.length, before + 1);
		const delivery = t.deliveries.at(-1);
		assert.equal(delivery.to, 'proprio@exemple.ca');
		assert.equal(delivery.links.length, 1);
		assert.match(delivery.links[0].url, /\/v1\/packs\/download\/[A-Za-z0-9_-]{43}$/);
		const rows = await t.sql`select source from pack_claims where licence_id = ${id}`;
		assert.deepEqual(rows.map((r) => r.source), ['free_claim']);
		const owned = await (await list(licence)).json();
		assert.equal(owned.packs.find((p) => p.slug === 'printemps').owned, true);
	});

	test('lien : la page GET ne consomme rien, le POST livre le zip signé une seule fois', async () => {
		const { licence } = await createLicence();
		await claim(licence, { packs: ['printemps'] });
		const tok = linkToken(t.deliveries.at(-1));
		for (let i = 0; i < 2; i++) {
			const page = await download(tok, 'GET');
			assert.equal(page.status, 200, 'un robot qui suit le lien ne le brûle pas');
			assert.match(page.headers.get('content-type'), /text\/html/);
			assert.match(await page.text(), /<form method="post"/);
		}
		const res = await download(tok);
		assert.equal(res.status, 200);
		assert.equal(res.headers.get('content-type'), 'application/zip');
		const bytes = Buffer.from(await res.arrayBuffer());
		assert.deepEqual(bytes, fakeZip('pack'));
		assert.equal(verify(null, bytes, t.publicKey, Buffer.from(res.headers.get('x-content-signature'), 'base64')), true);
		assert.equal((await download(tok)).status, 410, 'usage unique');
		assert.equal((await download(tok, 'GET')).status, 410);
		assert.equal((await download('A'.repeat(43))).status, 410);
		assert.equal((await download('trop-court')).status, 404);
	});

	test('lien : expiré après 72 h', async () => {
		const { licence } = await createLicence();
		await claim(licence, { packs: ['printemps'] });
		const tok = linkToken(t.deliveries.at(-1));
		const [link] = await t.sql`select token_hash, expires_at, created_at from pack_links order by created_at desc limit 1`;
		assert.equal(link.expires_at - link.created_at, 72 * 3_600_000);
		await t.sql`update pack_links set expires_at = now() - interval '1 second' where token_hash = ${link.token_hash}`;
		assert.equal((await download(tok)).status, 410);
	});

	test('régénération : un pack déjà obtenu reçoit un nouveau lien, l\'ancien est annulé', async () => {
		const { id, licence } = await createLicence();
		await claim(licence, { packs: ['printemps'] });
		const first = linkToken(t.deliveries.at(-1));
		const again = await claim(licence, { packs: ['printemps'] });
		assert.equal(again.status, 202);
		const second = linkToken(t.deliveries.at(-1));
		assert.notEqual(first, second);
		assert.equal((await download(first)).status, 410);
		assert.equal((await download(second)).status, 200);
		const [{ count }] = await t.sql`select count(*)::int as count from pack_claims where licence_id = ${id}`;
		assert.equal(count, 1);
	});

	test('concurrence : la première écriture gagne, un achat déjà présent reste un achat', async () => {
		const { id, licence } = await createLicence();
		const [pack] = await t.sql`select id from packs where slug = 'automne'`;
		await t.sql`insert into pack_claims (licence_id, pack_id, source) values (${id}, ${pack.id}, 'purchase')`;
		const res = await claim(licence, { packs: ['automne'] });
		assert.equal(res.status, 202);
		assert.deepEqual((await res.json()).sent, ['automne'], 'la licence possède le pack : elle reçoit son lien');
		const rows = await t.sql`select source from pack_claims where licence_id = ${id}`;
		assert.deepEqual(rows.map((r) => r.source), ['purchase']);

		const other = await createLicence();
		const results = await Promise.all([1, 2, 3].map(() => claim(other.licence, { packs: ['printemps'] })));
		assert.deepEqual(results.map((r) => r.status), [202, 202, 202]);
		const [{ count }] = await t.sql`select count(*)::int as count from pack_claims where licence_id = ${other.id}`;
		assert.equal(count, 1);
		const [{ live }] = await t.sql`
			select count(*)::int as live from pack_links l join pack_claims c on c.id = l.claim_id
			where c.licence_id = ${other.id} and l.used_at is null`;
		assert.equal(live, 1, 'les réclamations simultanées s\'enchaînent : un seul lien reste valide');
	});

	test('licence expirée : garde ses packs, ne réclame plus; révoquée ou sans courriel : refus', async () => {
		const { id, licence } = await createLicence();
		await claim(licence, { packs: ['printemps'] });
		await t.sql`update licences set expires_at = now() - interval '1 day' where id = ${id}`;
		const listed = await (await list(licence)).json();
		assert.deepEqual(listed.packs.map((p) => [p.slug, p.owned, p.claimable]), [['printemps', true, false]]);
		const res = await claim(licence, { packs: ['printemps', 'automne'] });
		assert.deepEqual(await res.json(), { sent: ['printemps'], refused: [{ slug: 'automne', code: 'licence_expired' }] });

		await t.sql`update licences set revoked_at = now() where id = ${id}`;
		assert.equal((await claim(licence, { packs: ['printemps'] })).status, 403);
		assert.equal((await list(licence)).status, 403);

		const bare = await createLicence({ email: undefined });
		const before = t.deliveries.length;
		const noEmail = await claim(bare.licence, { packs: ['printemps'] });
		assert.equal(noEmail.status, 409);
		const noEmailBody = await noEmail.json();
		assert.equal(noEmailBody.code, 'email_missing');
		assert.match(noEmailBody.message, /genevieve@fluffless\.dev/, 'X5-31 : adresse d\'aide décidée (D45)');
		const [{ count }] = await t.sql`select count(*)::int as count from pack_claims where licence_id = ${bare.id}`;
		assert.equal(count, 0, 'rien n\'est consommé si le lien ne peut pas partir');
		assert.equal(t.deliveries.length, before);
	});

	test('envoi impossible : 503, la réclamation reste et une nouvelle demande relivre', async () => {
		const { id, licence } = await createLicence();
		t.setDeliver(async () => false);
		const res = await claim(licence, { packs: ['printemps'] });
		assert.equal(res.status, 503);
		assert.equal((await res.json()).code, 'delivery_unavailable');
		t.setDeliver(async () => { throw new Error('535 authentication failed'); });
		assert.equal((await claim(licence, { packs: ['printemps'] })).status, 503);
		t.setDeliver(null);
		const [{ count }] = await t.sql`select count(*)::int as count from pack_claims where licence_id = ${id}`;
		assert.equal(count, 1);
		assert.equal((await claim(licence, { packs: ['printemps'] })).status, 202);
	});

	test('pot de miel : réponse d\'apparence normale, rien d\'écrit ni d\'envoyé', async () => {
		const { id, licence } = await createLicence();
		const before = t.deliveries.length;
		const res = await claim(licence, { packs: ['printemps'], website: 'http://spam.example' });
		assert.equal(res.status, 202);
		assert.deepEqual(await res.json(), { sent: ['printemps'], refused: [] });
		assert.equal(t.deliveries.length, before);
		const [{ count }] = await t.sql`select count(*)::int as count from pack_claims where licence_id = ${id}`;
		assert.equal(count, 0);
	});

	test('limites : 5 réclamations par licence puis verrou, 10 appels par IP', async () => {
		const { licence } = await createLicence();
		for (let i = 0; i < 5; i++) {
			assert.equal((await claim(licence, { packs: ['printemps'] })).status, 202);
		}
		const locked = await claim(licence, { packs: ['printemps'] });
		assert.equal(locked.status, 429);
		assert.equal(locked.headers.get('retry-after'), String(15 * 60));

		const ip = freshIp();
		const other = await createLicence();
		for (let i = 0; i < 10; i++) {
			assert.equal((await list(other.licence, ip)).status, 200);
		}
		assert.equal((await list(other.licence, ip)).status, 429);
		assert.equal((await claim(other.licence, { packs: ['printemps'] }, ip)).status, 429, 'liste et réclamation partagent le compteur');
	});

	test('mandataire du site : IP de la cliente prise seulement avec le bon jeton', async () => {
		const { licence } = await createLicence();
		const viaProxy = (clientIp, proxyToken = PROXY_TOKEN) => t.request('/v1/packs', {
			headers: { 'X-Fluffless-Licence': licence, 'X-Forwarded-For': '198.51.100.1', 'X-Fluffless-Proxy': proxyToken, 'X-Fluffless-Client-IP': clientIp },
		});
		for (let i = 0; i < 15; i++) {
			assert.equal((await viaProxy(`192.0.2.${i}`)).status, 200, 'chaque cliente a son propre compteur derrière le site');
		}
		assert.equal((await viaProxy('192.0.2.200', 'x'.repeat(40))).status, 401);
		assert.equal((await viaProxy('')).status, 400);
		// Sans jeton de mandataire, X-Fluffless-Client-IP est ignoré : le compteur reste celui de l'appelant.
		const direct = (clientIp) => t.request('/v1/packs', {
			headers: { 'X-Fluffless-Licence': licence, 'X-Forwarded-For': '198.51.100.2', 'X-Fluffless-Client-IP': clientIp },
		});
		for (let i = 0; i < 10; i++) {
			assert.equal((await direct(`192.0.2.${100 + i}`)).status, 200);
		}
		assert.equal((await direct('192.0.2.150')).status, 429);
		// IPv6 : un /64 partage un seul compteur.
		for (let i = 0; i < 10; i++) {
			assert.equal((await viaProxy(`2001:db8:1:2::${i + 1}`)).status, 200);
		}
		assert.equal((await viaProxy('2001:db8:1:2:ffff::1')).status, 429);
	});

	test('journal : ni clé, ni courriel, ni jeton de lien', async () => {
		const { licence } = await createLicence({ email: 'secrete@exemple.ca' });
		await claim(licence, { packs: ['printemps'] });
		const tok = linkToken(t.deliveries.at(-1));
		await download(tok);
		const logs = JSON.stringify(t.logs);
		assert.equal(logs.includes(licence), false);
		assert.equal(logs.includes('secrete@exemple.ca'), false);
		assert.equal(logs.includes(tok), false);
	});

	test('admin : un pack déjà obtenu ne se supprime pas, mais son zip se remplace et se re-signe', async () => {
		assert.equal((await admin('/v1/admin/packs/printemps', 'DELETE')).status, 409);
		const fixed = fakeZip('corrige');
		const res = await admin('/v1/admin/packs/printemps/zip', 'PUT', { zip_base64: fixed.toString('base64') });
		assert.equal(res.status, 200);
		const { licence } = await createLicence();
		await claim(licence, { packs: ['printemps'] });
		const dl = await download(linkToken(t.deliveries.at(-1)));
		const bytes = Buffer.from(await dl.arrayBuffer());
		assert.deepEqual(bytes, fixed);
		assert.equal(verify(null, bytes, t.publicKey, Buffer.from(dl.headers.get('x-content-signature'), 'base64')), true);
		assert.equal((await admin('/v1/admin/packs/inconnu/zip', 'PUT', { zip_base64: fixed.toString('base64') })).status, 404);
	});

	test('admin : rotation de clé garde les packs; suppression d\'une licence avec packs explicite', async () => {
		const { id, licence } = await createLicence();
		await claim(licence, { packs: ['printemps'] });
		const rotated = await admin(`/v1/admin/licences/${id}/rotate`, 'POST');
		assert.equal(rotated.status, 200);
		const { licence: fresh } = await rotated.json();
		assert.notEqual(fresh, licence);
		assert.equal((await list(licence)).status, 403, 'l\'ancienne clé ne vaut plus rien');
		const owned = await (await list(fresh)).json();
		assert.equal(owned.packs.find((p) => p.slug === 'printemps').owned, true);
		assert.equal((await admin(`/v1/admin/licences/${id}`, 'DELETE')).status, 409);
		assert.equal((await admin(`/v1/admin/licences/${id}?with_packs=1`, 'DELETE')).status, 204);
		const [{ count }] = await t.sql`select count(*)::int as count from pack_claims where licence_id = ${id}`;
		assert.equal(count, 0);
		assert.equal((await admin('/v1/admin/licences/999999/rotate', 'POST')).status, 404);
	});
});
