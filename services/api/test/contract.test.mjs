// Contrat partagé avec le client WordPress : chaque route, un chemin heureux et ses refus.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash, verify } from 'node:crypto';
import { fakePng, fakeZip, siteId, startTestServer } from './helpers.mjs';
import { hmacHex } from '../src/crypto.mjs';

const skip = process.env.API_TEST_SKIP_DB === '1' ? 'API_TEST_SKIP_DB=1' : false;
const sha256Hex = (value) => createHash('sha256').update(value).digest('hex');

// Transcription de Api::site_identity() (packages/fluffless-suite/src/Core/Api.php) : le serveur
// ne recalcule jamais l'empreinte, ce test fixe seulement la règle que le client doit tenir.
function siteIdentity(url) {
	const parsed = new URL(url);
	const port = parsed.port ? `:${parsed.port}` : ''; // URL retire déjà le port par défaut du schéma.
	return `${parsed.hostname.toLowerCase()}${port}${parsed.pathname.replace(/\/+$/, '')}`;
}

describe('contrat API', { skip }, () => {
	let t;
	let token;
	let licence;
	const zip = fakeZip('stable');

	const publish = (fields) => t.request('/v1/admin/releases', {
		method: 'POST',
		token,
		body: {
			slug: 'fluffless-suite',
			channel: 'stable',
			name: 'Fluffless Suite',
			homepage: 'https://fluffless.dev',
			requires: '6.6',
			requires_php: '8.2',
			tested: '6.8',
			description: { fr: 'Outils', en: 'Tools' },
			changelog: { fr: 'Nouveau', en: 'New' },
			zip_base64: zip.toString('base64'),
			...fields,
		},
	});
	const createLicence = async (body) => (await t.request('/v1/admin/licences', { method: 'POST', token, body })).json();
	const updateUrl = (params) => `/v1/update?${new URLSearchParams({ slug: 'fluffless-suite', channel: 'stable', site: siteId(1), ...params })}`;
	const getUpdate = (params, key = licence) => t.request(updateUrl(params), { headers: { 'X-Fluffless-Licence': key } });
	const verifyLicence = (site, key = licence) => t.request('/v1/licences/verify', { method: 'POST', body: { site }, headers: { 'X-Fluffless-Licence': key } });

	before(async () => {
		t = await startTestServer({ app: { downloadIdleMs: 1_500 } });
		token = t.env.ADMIN_TOKEN;
	});
	after(async () => t?.stop());

	test('GET /health répond ok avec les en-têtes de sécurité et sans CORS', async () => {
		const res = await t.request('/health');
		assert.equal(res.status, 200);
		assert.deepEqual(await res.json(), { status: 'ok' });
		assert.equal(res.headers.get('x-content-type-options'), 'nosniff');
		assert.equal(res.headers.get('access-control-allow-origin'), null);
	});

	test('GET / répond comme /health (sonde de canner)', async () => {
		const res = await t.request('/');
		assert.equal(res.status, 200);
		assert.deepEqual(await res.json(), { status: 'ok' });
	});

	test('routes inconnues et mauvaise méthode', async () => {
		assert.equal((await t.request('/nope')).status, 404);
		assert.equal((await t.request('/health', { method: 'POST' })).status, 405);
	});

	test('admin : jeton absent ou faux refusé', async () => {
		assert.equal((await t.request('/v1/admin/reports')).status, 401);
		assert.equal((await t.request('/v1/admin/reports', { token: 'x'.repeat(64) })).status, 401);
	});

	test('admin : publication signée, doublon refusé, zip invalide refusé', async () => {
		const res = await publish({ version: '0.0.1-test' });
		assert.equal(res.status, 201);
		const body = await res.json();
		assert.equal(body.sha256, createHash('sha256').update(zip).digest('hex'));
		assert.equal(verify(null, zip, t.publicKey, Buffer.from(body.signature, 'base64')), true);
		// Manifeste signé : JSON compact {slug, version, sha256}, signature ed25519 de la chaîne exacte.
		assert.equal(body.manifest, `{"slug":"fluffless-suite","version":"0.0.1-test","sha256":"${body.sha256}"}`);
		assert.equal(verify(null, Buffer.from(body.manifest, 'utf8'), t.publicKey, Buffer.from(body.manifest_signature, 'base64')), true);
		assert.equal((await publish({ version: '0.0.1-test' })).status, 409);
		assert.equal((await publish({ version: '0.0.2', zip_base64: Buffer.from('pas un zip').toString('base64') })).status, 400);
		assert.equal((await publish({ version: 'n/a' })).status, 400);
		assert.equal((await publish({ version: '0.0.3', bonus: 1 })).status, 400);
		assert.equal((await publish({ version: '0.1.0-beta.1', channel: 'beta', zip_base64: fakeZip('beta').toString('base64') })).status, 201);
	});

	test('admin : création de licence, validation des champs', async () => {
		const created = await createLicence({ plan: 'solo', sites_max: 1, expires: '2099-01-01', note: 'test' });
		assert.match(created.licence, /^FL-[0-9A-F]{8}(-[0-9A-F]{8}){3}$/);
		licence = created.licence;
		const bad = await t.request('/v1/admin/licences', { method: 'POST', token, body: { plan: 'solo', sites_max: 0 } });
		assert.equal(bad.status, 400);
		const [stored] = await t.sql`select key_hash from licences where id = ${created.id}`;
		assert.notEqual(stored.key_hash, licence, 'la clé ne doit pas être stockée en clair');
	});

	test('POST /v1/licences/verify : inscrit le site, puis refuse au-delà de sites_max', async () => {
		const first = await (await verifyLicence(siteId(1))).json();
		assert.deepEqual({ ...first, expires: typeof first.expires }, { valid: true, plan: 'solo', expires: 'string', sites_used: 1, sites_max: 1 });
		const again = await (await verifyLicence(siteId(1))).json();
		assert.equal(again.sites_used, 1);
		const other = await (await verifyLicence(siteId(2))).json();
		assert.equal(other.valid, false);
		assert.equal(other.code, 'site_limit');
		const unknown = await (await verifyLicence(siteId(1), 'FL-NOPE')).json();
		assert.equal(unknown.valid, false);
		assert.equal(unknown.code, 'licence_invalid');
		const missing = await verifyLicence(siteId(1), '');
		assert.equal(missing.status, 200);
		assert.equal((await missing.json()).code, 'licence_invalid');
		assert.equal((await verifyLicence('court')).status, 400);
		const [row] = await t.sql`select site_hash from licence_sites limit 1`;
		assert.notEqual(row.site_hash, siteId(1), 'le site est poivré avant stockage');
	});

	test('empreinte de site : vecteurs de docs/PUBLICATION.md, une adresse normalisée occupe une seule place', async () => {
		const vectors = [
			['https://WWW.Exemple.ca:443/Blogue/', 'www.exemple.ca/Blogue'],
			['http://localhost:8080', 'localhost:8080'],
		];
		for (const [url, identity] of vectors) {
			assert.equal(siteIdentity(url), identity, url);
		}
		const hash = createHash('sha256').update('www.exemple.ca/Blogue').digest('hex');
		assert.equal(sha256Hex(siteIdentity('https://www.exemple.ca/Blogue')), hash, 'même empreinte sans port par défaut');
		const { licence: key } = await createLicence({ plan: 'solo', sites_max: 1 });
		const first = await (await verifyLicence(hash, key)).json();
		const again = await (await verifyLicence(sha256Hex(siteIdentity('https://WWW.Exemple.ca:443/Blogue/')), key)).json();
		assert.deepEqual([first.valid, first.sites_used, again.valid, again.sites_used], [true, 1, true, 1]);
	});

	test('GET /v1/manifest : manifeste signé de la version stable, sans licence', async () => {
		const res = await t.request('/v1/manifest?slug=fluffless-suite');
		assert.equal(res.status, 200);
		const body = await res.json();
		assert.deepEqual(Object.keys(body).sort(), ['manifest', 'manifest_signature', 'slug', 'version']);
		assert.equal(body.version, '0.0.1-test');
		assert.equal(verify(null, Buffer.from(body.manifest, 'utf8'), t.publicKey, Buffer.from(body.manifest_signature, 'base64')), true);
		assert.equal((await t.request('/v1/manifest?slug=inconnu')).status, 404);
		assert.equal((await t.request('/v1/manifest')).status, 400);
	});

	test('GET /v1/update : 200 avec paquet signé, 204 à jour, canal beta', async () => {
		const res = await getUpdate({ version: '0.0.0' });
		assert.equal(res.status, 200);
		const body = await res.json();
		assert.deepEqual(Object.keys(body).sort(), ['changelog', 'manifest', 'manifest_signature', 'package', 'requires', 'requires_php', 'sha256', 'slug', 'tested', 'version']);
		assert.equal(body.version, '0.0.1-test');
		// Le manifeste servi est celui signé à la publication, et il décrit bien cette fiche.
		assert.deepEqual(JSON.parse(body.manifest), { slug: 'fluffless-suite', version: '0.0.1-test', sha256: body.sha256 });
		assert.equal(verify(null, Buffer.from(body.manifest, 'utf8'), t.publicKey, Buffer.from(body.manifest_signature, 'base64')), true);
		assert.equal(verify(null, Buffer.from(body.manifest.replace('0.0.1-test', '0.0.9'), 'utf8'), t.publicKey, Buffer.from(body.manifest_signature, 'base64')), false);
		assert.deepEqual(body.changelog, { fr: 'Nouveau', en: 'New' });

		const download = await fetch(body.package);
		assert.equal(download.status, 200);
		assert.equal(download.headers.get('content-type'), 'application/zip');
		const bytes = Buffer.from(await download.arrayBuffer());
		assert.deepEqual(bytes, zip);
		const signature = download.headers.get('x-content-signature');
		assert.equal(verify(null, bytes, t.publicKey, Buffer.from(signature, 'base64')), true);
		const sig = await fetch(`${body.package}.sig`);
		assert.equal(await sig.text(), signature);

		assert.equal((await getUpdate({ version: '0.0.1-test' })).status, 204);
		assert.equal((await getUpdate({ version: '0.0.1' })).status, 204);
		const beta = await (await getUpdate({ version: '0.0.1', channel: 'beta' })).json();
		assert.equal(beta.version, '0.1.0-beta.1');
	});

	test('GET /v1/update : 403 licence_invalid, licence_expired, site_limit', async () => {
		const code = async (params, key) => {
			const res = await getUpdate({ version: '0.0.0', ...params }, key);
			assert.equal(res.status, 403);
			return (await res.json()).code;
		};
		assert.equal(await code({}, 'FL-FAUX'), 'licence_invalid');
		assert.equal(await code({}, ''), 'licence_invalid');
		const expired = await createLicence({ plan: 'solo', sites_max: 1, expires: '2020-01-01' });
		assert.equal(await code({}, expired.licence), 'licence_expired');
		assert.equal(await code({ site: siteId(3) }), 'site_limit');
		assert.equal((await getUpdate({ version: '0.0.0', slug: 'inconnu' })).status, 404);
		assert.equal((await getUpdate({ version: 'x' })).status, 400);
	});

	test('licence : en-tête seulement, jamais dans la requête ni au journal', async () => {
		const inQuery = await t.request(updateUrl({ version: '0.0.0', licence }));
		assert.equal(inQuery.status, 400);
		assert.equal((await inQuery.json()).code, 'licence_in_query');
		assert.equal((await t.request(`/v1/info?slug=fluffless-suite&licence=${licence}`)).status, 400);
		const inBody = await t.request('/v1/licences/verify', { method: 'POST', body: { licence, site: siteId(1) }, headers: { 'X-Fluffless-Licence': licence } });
		assert.equal(inBody.status, 400);
		const info = await t.request('/v1/info?slug=fluffless-suite', { headers: { 'X-Fluffless-Licence': licence } });
		assert.equal(info.status, 200);
		assert.equal((await getUpdate({ version: '0.0.0' }, 'x'.repeat(129))).status, 400);
		assert.equal(JSON.stringify(t.logs).includes(licence), false, 'la clé de licence ne doit jamais être journalisée');
	});

	test('téléchargement : lien falsifié ou expiré refusé', async () => {
		const body = await (await getUpdate({ version: '0.0.0' })).json();
		const tampered = body.package.replace(/\/([0-9a-f]{64})\.zip$/, `/${'0'.repeat(64)}.zip`);
		assert.equal((await fetch(tampered)).status, 403);
		const past = Math.floor(Date.now() / 1000) - 1;
		const tokenPast = hmacHex(t.env.URL_SECRET, `download\nfluffless-suite\n0.0.1-test\n${past}`);
		assert.equal((await fetch(`${t.base}/v1/download/fluffless-suite/0.0.1-test/${past}/${tokenPast}.zip`)).status, 403);
	});

	test('GET /v1/info : fiche en français et en anglais, 404 si inconnu', async () => {
		const fr = await (await t.request('/v1/info?slug=fluffless-suite&locale=fr_CA')).json();
		assert.deepEqual(Object.keys(fr).sort(), ['homepage', 'last_updated', 'name', 'requires', 'requires_php', 'sections', 'slug', 'tested', 'version']);
		assert.equal(fr.version, '0.0.1-test', 'la fiche publique ignore le canal beta');
		assert.deepEqual(fr.sections, { description: 'Outils', changelog: 'Nouveau' });
		const en = await (await t.request('/v1/info?slug=fluffless-suite&locale=en_CA')).json();
		assert.equal(en.sections.description, 'Tools');
		assert.equal((await t.request('/v1/info?slug=inconnu')).status, 404);
	});

	describe('signalements', () => {
		let reportId;
		const report = (fields = {}, headers = { 'X-Forwarded-For': '203.0.113.1' }) => t.request('/v1/reports', {
			method: 'POST',
			headers,
			body: { type: 'bug', what: 'Le bouton ne répond pas', expected: 'Qu\'il réponde', locale: 'fr_CA', ...fields },
		});

		test('201 avec capture, notification déclenchée, IP jamais stockée', async () => {
			const res = await report({ reply_email: 'a@exemple.ca', diagnostics: { wp: '6.8' }, site: siteId(1), screenshot: fakePng().toString('base64') });
			assert.equal(res.status, 201);
			reportId = (await res.json()).id;
			assert.match(reportId, /^[0-9a-f-]{36}$/);
			await new Promise((resolve) => setImmediate(resolve));
			assert.equal(t.mails.at(-1).id, reportId);
			const [row] = await t.sql`select screenshot_type, row_to_json(reports)::text as dump from reports where id = ${reportId}`;
			assert.equal(row.screenshot_type, 'image/png');
			assert.equal(row.dump.includes('203.0.113.1'), false);
			assert.equal(JSON.stringify(t.logs).includes('203.0.113.1'), false);
			assert.equal(JSON.stringify(t.logs).includes('a@exemple.ca'), false);
		});

		test('validation stricte : type, tailles, capture, champs inconnus, JSON', async () => {
			const status = async (fields) => (await report(fields, { 'X-Forwarded-For': '198.51.100.9' })).status;
			assert.equal(await status({ type: 'autre' }), 400);
			assert.equal(await status({ what: '' }), 400);
			assert.equal(await status({ what: 'x'.repeat(5001) }), 400);
			assert.equal(await status({ reply_email: 'pas-un-courriel' }), 400);
			assert.equal(await status({ screenshot: Buffer.from('GIF89a').toString('base64') }), 400);
			assert.equal(await status({ screenshot: Buffer.alloc(1024 * 1024 + 1, 1).toString('base64') }), 400);
			assert.equal(await status({ diagnostics: { gros: 'x'.repeat(21000) } }), 400);
			assert.equal(await status({ ip: '1.2.3.4' }), 400);
			const raw = await t.request('/v1/reports', { method: 'POST', body: '{nope', headers: { 'X-Forwarded-For': '198.51.100.9' } });
			assert.equal(raw.status, 400);
			const tooBig = await t.request('/v1/reports', { method: 'POST', body: JSON.stringify({ what: 'x'.repeat(1700 * 1024) }), headers: { 'X-Forwarded-For': '198.51.100.8' } });
			assert.equal(tooBig.status, 413);
		});

		test('caractères de contrôle refusés dans le texte libre, sauts de ligne gardés', async () => {
			const status = async (fields) => (await report(fields, { 'X-Forwarded-For': '198.51.100.20' })).status;
			assert.equal(await status({ what: 'nul \u0000' }), 400);
			assert.equal(await status({ expected: 'cloche \u0007' }), 400);
			assert.equal(await status({ what: 'inversé \u202Etxt.exe' }), 400);
			assert.equal(await status({ diagnostics: { note: 'nul \u0000' } }), 400);
			assert.equal(await status({ what: 'Ligne 1\nLigne 2\r\n\tfin' }), 201);
		});

		test('429 au onzième envoi dans l\'heure pour une même IP', async () => {
			const headers = { 'X-Forwarded-For': '192.0.2.50' };
			for (let i = 0; i < 10; i++) {
				assert.equal((await report({}, headers)).status, 201);
			}
			const blocked = await report({}, headers);
			assert.equal(blocked.status, 429);
			assert.ok(Number(blocked.headers.get('retry-after')) > 0);
			assert.equal((await report({}, { 'X-Forwarded-For': '192.0.2.51' })).status, 201);
		});

		test('admin : liste filtrée, capture, statut réglé puis suppression', async () => {
			const list = await (await t.request('/v1/admin/reports?status=nouveau', { token })).json();
			const mine = list.reports.find((r) => r.id === reportId);
			assert.equal(mine.has_screenshot, true);
			assert.equal('screenshot' in mine, false);
			const shot = await t.request(`/v1/admin/reports/${reportId}/screenshot`, { token });
			assert.equal(shot.headers.get('content-type'), 'image/png');
			assert.equal((await t.request('/v1/admin/reports?status=faux', { token })).status, 400);

			const patched = await (await t.request(`/v1/admin/reports/${reportId}`, { method: 'PATCH', token, body: { status: 'réglé' } })).json();
			assert.equal(patched.status, 'réglé');
			assert.ok(patched.resolved_at);
			assert.equal((await t.request(`/v1/admin/reports/${reportId}`, { method: 'PATCH', token, body: { status: 'fini' } })).status, 400);
			const solved = await (await t.request('/v1/admin/reports?status=réglé', { token })).json();
			assert.equal(solved.reports.length, 1);

			assert.equal((await t.request(`/v1/admin/reports/${reportId}`, { method: 'DELETE', token })).status, 204);
			assert.equal((await t.request(`/v1/admin/reports/${reportId}`, { method: 'DELETE', token })).status, 404);
		});
	});

	test('purge Loi 25 : un signalement réglé depuis plus de 12 mois disparaît, les autres restent', async () => {
		const { purgeResolvedReports } = await import('../src/migrate.mjs');
		await t.sql`insert into reports (type, what, locale, status, resolved_at) values
			('bug', 'vieux', 'fr_CA', 'réglé', now() - interval '13 months'),
			('bug', 'récent', 'fr_CA', 'réglé', now() - interval '11 months')`;
		assert.equal(await purgeResolvedReports(t.sql), 1);
		const rows = await t.sql`select what from reports where what in ('vieux', 'récent')`;
		assert.deepEqual(rows.map((r) => r.what), ['récent']);
	});

	test('purge des places : sites de développement après 90 jours, libérations après 30 jours, signalements après 12 mois', async () => {
		const { purgeLicencePlaces } = await import('../src/migrate.mjs');
		const { id } = await createLicence({ plan: 'solo', sites_max: 1 });
		const { id: kept } = await createLicence({ plan: 'solo', sites_max: 1 });
		await t.sql`insert into licence_dev_sites (licence_id, site_hash, last_seen_at) values
			(${id}, 'dev-vieux', now() - interval '91 days'), (${id}, 'dev-recent', now() - interval '89 days')`;
		await t.sql`insert into licence_site_releases (licence_id, released_at) values
			(${id}, now() - interval '31 days'), (${id}, now() - interval '29 days')`;
		await t.sql`update licences set notice_at = now() - interval '14 months', notice_until = now() - interval '13 months',
			notice_reason = 'usage' where id = ${kept}`;
		await t.sql`insert into licence_flags (licence_id, rule, level, created_at, outcome) values
			(${id}, 'dev', 1, now() - interval '13 months', null),
			(${id}, 'dev', 2, now() - interval '11 months', null),
			(${kept}, 'dev', 3, now() - interval '13 months', 'notice')`;
		assert.deepEqual(await purgeLicencePlaces(t.sql), { dev_sites: 1, releases: 1, flags: 1 });
		assert.deepEqual((await t.sql`select site_hash from licence_dev_sites where licence_id = ${id}`).map((r) => r.site_hash), ['dev-recent']);
		assert.equal((await t.sql`select count(*)::int as n from licence_site_releases where licence_id = ${id}`)[0].n, 1);
		assert.deepEqual((await t.sql`select level from licence_flags where licence_id in (${id}, ${kept}) order by level`).map((r) => r.level), [2, 3]);
		// Avis levé depuis plus de 12 mois : le signalement qui l'a motivé part aussi.
		await t.sql`update licences set notice_at = null, notice_until = null, notice_reason = null, restored_at = now() - interval '13 months' where id = ${kept}`;
		assert.equal((await purgeLicencePlaces(t.sql)).flags, 1);
	});

	test('purge Loi 25 : une licence échue depuis plus de 12 mois disparaît avec ses sites et ses packs', async () => {
		const { purgeExpiredLicences } = await import('../src/migrate.mjs');
		const old = await createLicence({ plan: 'solo', sites_max: 1, email: 'vieille@exemple.ca' });
		const recent = await createLicence({ plan: 'solo', sites_max: 1 });
		const restored = await createLicence({ plan: 'solo', sites_max: 1 });
		const lifetime = await createLicence({ plan: 'solo', sites_max: 1 });
		await t.sql`update licences set expires_at = now() - interval '13 months' where id in (${old.id}, ${restored.id})`;
		await t.sql`update licences set expires_at = now() - interval '11 months' where id = ${recent.id}`;
		await t.sql`update licences set restored_at = now() - interval '11 months' where id = ${restored.id}`;
		await t.sql`insert into licence_sites (licence_id, site_hash) values (${old.id}, 'site-vieux')`;
		const [pack] = await t.sql`
			insert into packs (slug, name_fr, name_en, zip, zip_size, sha256, signature)
			values ('purge-test', 'P', 'P', ${Buffer.from('x')}, 1, 'x', 'x') returning id`;
		await t.sql`insert into pack_claims (licence_id, pack_id, source) values (${old.id}, ${pack.id}, 'free_claim')`;
		await t.sql`insert into licence_purchases (checkout_session_id, licence_id, amount_total, currency, outcome) values
			('cs_purge', ${old.id}, 4900, 'cad', 'granted')`;
		// D'autres licences échues de ce fichier (2020) partent aussi : on vérifie les nôtres.
		assert.ok((await purgeExpiredLicences(t.sql)).licences >= 1);
		const left = (await t.sql`select id from licences where id in (${old.id}, ${recent.id}, ${restored.id}, ${lifetime.id}) order by id`).map((r) => Number(r.id));
		assert.deepEqual(left, [recent.id, restored.id, lifetime.id]);
		assert.equal((await t.sql`select count(*)::int as n from licence_sites where licence_id = ${old.id}`)[0].n, 0);
		assert.equal((await t.sql`select count(*)::int as n from pack_claims where licence_id = ${old.id}`)[0].n, 0);
		// La pièce d'achat reste (7 ans), sans lien vers la licence.
		assert.equal((await t.sql`select licence_id from licence_purchases where checkout_session_id = 'cs_purge'`)[0].licence_id, null);
	});

	test('Stripe non configuré : webhook et paiement répondent 503', async () => {
		const res = await t.request('/v1/webhooks/stripe', { method: 'POST', body: {} });
		assert.equal(res.status, 503);
		assert.equal((await res.json()).code, 'payments_unavailable');
	});

	test('admin : suppression de release et de licence', async () => {
		assert.equal((await t.request('/v1/admin/releases/fluffless-suite/0.1.0-beta.1', { method: 'DELETE', token })).status, 204);
		const created = await createLicence({ plan: 'agence', sites_max: 5 });
		assert.equal(created.expires, null);
		assert.equal((await t.request(`/v1/admin/licences/${created.id}`, { method: 'DELETE', token })).status, 204);
	});

	test('purge Loi 25 : événements Stripe après 90 jours, achats réglés après 7 ans', async () => {
		const { purgeStripeRecords } = await import('../src/migrate.mjs');
		await t.sql`insert into stripe_events (id, type, received_at, processed_at) values
			('evt_vieux', 'x', now() - interval '91 days', now() - interval '91 days'),
			('evt_recent', 'x', now() - interval '89 days', now() - interval '89 days')`;
		await t.sql`insert into pack_purchases (checkout_session_id, amount_total, currency, outcome, created_at) values
			('cs_vieux', 500, 'cad', 'granted', now() - interval '8 years'),
			('cs_attente', 500, 'cad', 'refund_pending', now() - interval '8 years'),
			('cs_recent', 500, 'cad', 'refunded', now() - interval '6 years')`;
		await t.sql`insert into licence_purchases (checkout_session_id, amount_total, currency, outcome, created_at) values
			('cs_lic_vieux', 4900, 'cad', 'granted', now() - interval '8 years'),
			('cs_lic_recent', 4900, 'cad', 'granted', now() - interval '6 years')`;
		assert.deepEqual(await purgeStripeRecords(t.sql), { events: 1, purchases: 1, licence_purchases: 1 });
		assert.deepEqual((await t.sql`select id from stripe_events order by id`).map((r) => r.id), ['evt_recent']);
		assert.deepEqual((await t.sql`select checkout_session_id from pack_purchases order by 1`).map((r) => r.checkout_session_id), ['cs_attente', 'cs_recent']);
	});

	test('routes publiques : plafond par IP, /48 IPv6 et IPv4 mappée comptées à part', async () => {
		const info = (ip) => t.request('/v1/info?slug=fluffless-suite', { headers: { 'X-Forwarded-For': ip } });
		const statuses = async (ip, n) => Promise.all(Array.from({ length: n }, async () => (await info(ip)).status));
		const first = await statuses('203.0.113.77', 121);
		assert.equal(first.filter((s) => s === 200).length, 120);
		const refused = await info('203.0.113.77');
		assert.equal(refused.status, 429);
		assert.ok(Number(refused.headers.get('retry-after')) > 0);
		assert.equal((await info('203.0.113.78')).status, 200, 'une autre IP garde son quota');

		// ::ffff:a.b.c.d compte pour l'IPv4 elle-même, pas pour un compteur partagé.
		await statuses('::ffff:198.51.100.40', 120);
		assert.equal((await info('::ffff:198.51.100.40')).status, 429);
		assert.equal((await info('::ffff:198.51.100.41')).status, 200);

		// Neuf /64 d'un même /48 : 960 appels passent (8 × 120), le suivant est refusé même d'un /64 neuf.
		for (let round = 0; round < 8; round++) {
			const batch = await Promise.all(Array.from({ length: 120 }, async (_, i) => (await info(`2001:db8:5:${(round * 120 + i) % 9}::1`)).status));
			assert.ok(batch.every((s) => s === 200), `tour ${round}`);
		}
		assert.equal((await info('2001:db8:5:ff::1')).status, 429);
		assert.equal((await info('2001:db8:6::1')).status, 200);

		// Un /64 qui insiste au-delà de sa limite n'entame pas le quota de ses voisins du /48.
		await statuses('2001:db8:7:1::1', 1000);
		assert.equal((await info('2001:db8:7:2::1')).status, 200);
	});

	test('téléchargements simultanés bornés à 8, clientes muettes coupées', async () => {
		const { request: httpRequest } = await import('node:http');
		const big = Buffer.concat([Buffer.from([0x50, 0x4b, 0x03, 0x04]), Buffer.alloc(20 * 1024 * 1024, 7)]);
		assert.equal((await publish({ slug: 'fluffless-gros', version: '1.0.0', zip_base64: big.toString('base64') })).status, 201);
		const expires = Math.floor(Date.now() / 1000) + 600;
		const url = `${t.base}/v1/download/fluffless-gros/1.0.0/${expires}/${hmacHex(t.env.URL_SECRET, `download\nfluffless-gros\n1.0.0\n${expires}`)}.zip`;
		// Huit clientes muettes : la réponse arrive, le corps n'est jamais lu.
		const slow = await Promise.all(Array.from({ length: 8 }, (_, i) => new Promise((resolve, reject) => {
			const req = httpRequest(url, { headers: { 'X-Forwarded-For': `192.0.2.${100 + i}` } }, (res) => {
				res.pause();
				resolve({ req, status: res.statusCode });
			});
			req.on('error', () => {});
			req.setTimeout(10_000, () => reject(new Error('délai')));
			req.end();
		})));
		assert.deepEqual(slow.map((c) => c.status), Array(8).fill(200));
		const busy = await fetch(url, { headers: { 'X-Forwarded-For': '192.0.2.110' } });
		assert.equal(busy.status, 503);
		assert.equal(busy.headers.get('retry-after'), '30');
		// Sans rien couper côté cliente : le délai d'inactivité du serveur rend les places.
		let freed = 0;
		// Node réarme le délai tant que l'écriture progresse : compter jusqu'à 2 délais et demi.
		for (let i = 0; i < 80 && freed !== 200; i++) {
			await new Promise((r) => setTimeout(r, 100));
			const res = await fetch(url, { headers: { 'X-Forwarded-For': '192.0.2.111' } });
			freed = res.status;
			await res.arrayBuffer();
		}
		assert.equal(freed, 200, 'les places se libèrent quand le serveur coupe les muettes');
		for (const c of slow) {
			c.req.destroy();
		}
	});

	test('signalements : budget quotidien de captures, puis plafond global', async () => {
		const png = Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), Buffer.alloc(1024 * 1024 - 8, 1)]);
		const post = (n, screenshot) => t.request('/v1/reports', {
			method: 'POST',
			body: { type: 'bug', what: `budget ${n}`, expected: 'rien', locale: 'fr_CA', ...(screenshot ? { screenshot: png.toString('base64') } : {}) },
			headers: { 'X-Forwarded-For': `100.64.${Math.floor(n / 250)}.${n % 250}` },
		});
		let n = 0;
		let status = 201;
		let dropped = false;
		while (!dropped && n < 60) {
			const res = await post(n++, true);
			status = res.status;
			dropped = Boolean((await res.json()).screenshot_dropped);
		}
		assert.ok(dropped && n >= 50 && n <= 51, `capture laissée tomber après ${n} envois`);
		assert.equal(status, 201);
		const over = await post(n++, true);
		assert.equal(over.status, 201, 'au-delà du budget, le texte passe quand même');
		const { id, screenshot_dropped: overDropped } = await over.json();
		assert.equal(overDropped, true);
		assert.equal((await t.sql`select screenshot from reports where id = ${id}`)[0].screenshot, null);
		const before = (await t.sql`select count(*)::int as c from reports`)[0].c;
		status = 201;
		while (status === 201 && n < 200) {
			status = (await post(n++, false)).status;
		}
		assert.equal(status, 429, 'plafond global atteint');
		const [{ c }] = await t.sql`select count(*)::int as c from reports`;
		assert.ok(c - before < 100, 'rien d\'écrit au-delà du plafond');
		assert.equal((await post(n++, false)).status, 429);
		assert.equal((await t.sql`select count(*)::int as c from reports`)[0].c, c);
	});

	test('migrations idempotentes', async () => {
		const { migrate } = await import('../src/migrate.mjs');
		assert.deepEqual(await migrate(t.sql), []);
	});
});
