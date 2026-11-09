// Téléchargement des zip et pages de retour vues par l'acheteuse (bloc F4 de l'audit final).
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { fakeZip, startTestServer } from './helpers.mjs';
import { hmacHex } from '../src/crypto.mjs';

const skip = process.env.API_TEST_SKIP_DB === '1' ? 'API_TEST_SKIP_DB=1' : false;
const IDLE_MS = 300;

describe('téléchargement et pages de retour', { skip }, () => {
	let t;
	let ipCounter = 0;
	const freshIp = () => `203.0.113.${++ipCounter}`;
	const zipUrl = (slug, version, expires) => `/v1/download/${slug}/${version}/${expires}/${hmacHex(t.env.URL_SECRET, `download\n${slug}\n${version}\n${expires}`)}.zip`;

	before(async () => {
		t = await startTestServer({ app: { downloadIdleMs: IDLE_MS } });
		const res = await t.request('/v1/admin/releases', {
			method: 'POST',
			token: t.env.ADMIN_TOKEN,
			body: {
				slug: 'fluffless-suite', version: '1.0.0', channel: 'stable', name: 'Fluffless Suite',
				homepage: 'https://fluffless.dev', requires: '6.6', requires_php: '8.2', tested: '6.8',
				description: { fr: 'Outils', en: 'Tools' }, changelog: { fr: 'Nouveau', en: 'New' },
				zip_base64: fakeZip('f4').toString('base64'),
			},
		});
		assert.equal(res.status, 201);
	});
	after(async () => t?.stop());

	// P6b-01 : le pool de la base (3 connexions) est occupé plus longtemps que le délai
	// d'inactivité. La cliente doit quand même recevoir son zip, pas une connexion coupée.
	test('P6b-01 : une base lente ne coupe pas le téléchargement avant la réponse', async () => {
		const busy = Array.from({ length: 3 }, () => t.sql`select pg_sleep(${(IDLE_MS * 4) / 1000})`.execute());
		await new Promise((r) => setTimeout(r, 50));
		const expires = Math.floor(Date.now() / 1000) + 600;
		const res = await t.request(zipUrl('fluffless-suite', '1.0.0', expires), { headers: { 'X-Forwarded-For': freshIp() } });
		assert.equal(res.status, 200);
		assert.equal(res.headers.get('content-type'), 'application/zip');
		assert.deepEqual(Buffer.from(await res.arrayBuffer()), fakeZip('f4'));
		await Promise.all(busy);
	});

	// C4-1-02 : la page de téléchargement annonce des liens de 15 minutes ; passé ce délai, la
	// visiteuse doit tomber sur une page lisible qui la ramène à la page de la clé.
	test('C4-1-02 : zip expiré ou falsifié, page HTML bilingue avec le chemin de retour', async () => {
		const past = Math.floor(Date.now() / 1000) - 1;
		const forged = zipUrl('fluffless-suite', '1.0.0', past + 600).replace(/[0-9a-f]{64}\.zip$/, `${'0'.repeat(64)}.zip`);
		for (const path of [zipUrl('fluffless-suite', '1.0.0', past), forged]) {
			const res = await t.request(path, { headers: { 'X-Forwarded-For': freshIp() } });
			assert.equal(res.status, 403);
			assert.match(res.headers.get('content-type'), /^text\/html/);
			assert.equal(res.headers.get('cache-control'), 'no-store');
			const html = await res.text();
			assert.match(html, /<h1>[^<]*expiré/);
			assert.match(html, /lang="en"[^>]*>[^<]*expired/);
			assert.ok(html.includes(`href="${t.config.publicUrl}/v1/licences/download"`), html);
		}
		// La signature reste en JSON : elle est lue par la Suite, pas par une personne.
		const sig = await t.request(`${zipUrl('fluffless-suite', '1.0.0', past)}.sig`, { headers: { 'X-Forwarded-For': freshIp() } });
		assert.equal(sig.status, 403);
		assert.equal((await sig.json()).code, 'link_expired');
	});

	// C4-1-06 : le lien de renouvellement vient d'un courriel. Son échec a sa propre page, sans
	// le formulaire de clé de la page de téléchargement, et le 429 se lit aussi en HTML.
	test('C4-1-06 : renouvellement refusé, h1 propre sans formulaire, 429 en HTML', async () => {
		const ip = freshIp();
		const renew = () => t.request(`/v1/licences/renew?licence=1&until=1&token=${'0'.repeat(64)}`, { headers: { 'X-Forwarded-For': ip } });
		const res = await renew();
		assert.equal(res.status, 403);
		const html = await res.text();
		assert.match(html, /<h1>[^<]*[Rr]enouvel/);
		assert.doesNotMatch(html, /Télécharger la Suite/);
		assert.doesNotMatch(html, /<form|name="key"/);
		assert.match(html, /no longer works/);
		let limited = res;
		for (let i = 0; i < 20 && limited.status !== 429; i++) {
			limited = await renew();
		}
		assert.equal(limited.status, 429);
		assert.match(limited.headers.get('content-type'), /^text\/html/);
		assert.match(limited.headers.get('retry-after'), /^\d+$/);
		const page = await limited.text();
		assert.match(page, /<h1>[^<]*[Rr]enouvel/);
		assert.doesNotMatch(page, /<form/);
		assert.match(page, /lang="en"/);
	});
});
