// Places de licence : sites de développement hors compte, libération d'une place, outils
// d'administration (liste, libération, avis, révocation, rétablissement) et purge.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { fakeZip, startTestServer } from './helpers.mjs';

const skip = process.env.API_TEST_SKIP_DB === '1' ? 'API_TEST_SKIP_DB=1' : false;
const DAY = 86_400_000;
const site = (n) => n.toString(16).padStart(64, '0');

describe('places de licence', { skip }, () => {
	let t;
	let token;
	let clock = Date.now();
	const admin = (path, method = 'GET', body) => t.request(path, { method, token, body });
	const createLicence = async (fields = {}) => (await admin('/v1/admin/licences', 'POST', { plan: 'solo', sites_max: 1, ...fields })).json();
	const verify = async (key, body) => (await t.request('/v1/licences/verify', { method: 'POST', body, headers: { 'X-Fluffless-Licence': key } })).json();
	const update = (key, params) => t.request(`/v1/update?${new URLSearchParams({ slug: 'fluffless-suite', version: '0.0.1', ...params })}`, {
		headers: { 'X-Fluffless-Licence': key },
	});
	const seats = async (id) => (await t.sql`select count(*)::int as n from licence_sites where licence_id = ${id}`)[0].n;

	before(async () => {
		t = await startTestServer({ now: () => clock });
		token = t.env.ADMIN_TOKEN;
		const published = await admin('/v1/admin/releases', 'POST', {
			slug: 'fluffless-suite',
			version: '1.0.0',
			channel: 'stable',
			name: 'Fluffless Suite',
			description: { fr: 'Outils', en: 'Tools' },
			changelog: { fr: 'Première', en: 'First' },
			zip_base64: fakeZip('suite').toString('base64'),
		});
		assert.equal(published.status, 201);
	});
	after(async () => t?.stop());

	describe('sites de développement', () => {
		test('verify dev:true : valide sans prendre de place, la réponse le dit', async () => {
			const { id, licence } = await createLicence();
			const state = await verify(licence, { site: site(1), dev: true });
			assert.equal(state.valid, true);
			assert.equal(state.dev, true);
			assert.equal(state.sites_used, 0);
			assert.equal(await seats(id), 0);
		});

		test('verify dev:true sur une licence pleine : toujours valide', async () => {
			const { licence } = await createLicence();
			assert.equal((await verify(licence, { site: site(10) })).valid, true);
			assert.equal((await verify(licence, { site: site(11) })).code, 'site_limit');
			const dev = await verify(licence, { site: site(11), dev: true });
			assert.equal(dev.valid, true);
			assert.equal(dev.sites_used, 1);
		});

		test('verify dev:true : un site local inscrit avant le correctif rend sa place', async () => {
			const { id, licence } = await createLicence();
			assert.equal((await verify(licence, { site: site(20) })).sites_used, 1);
			const dev = await verify(licence, { site: site(20), dev: true });
			assert.equal(dev.valid, true);
			assert.equal(dev.sites_used, 0);
			assert.equal(await seats(id), 0);
			assert.equal((await verify(licence, { site: site(21) })).valid, true);
		});

		test('verify dev:true : le 26e site de développement est refusé, sans chiffre dans la réponse', async () => {
			const { licence } = await createLicence({ sites_max: 5 });
			for (let n = 0; n < 25; n++) {
				assert.equal((await verify(licence, { site: site(100 + n), dev: true })).valid, true, `site ${n + 1}`);
			}
			const refused = await verify(licence, { site: site(200), dev: true });
			assert.equal(refused.valid, false);
			assert.equal(refused.code, 'dev_limit');
			assert.doesNotMatch(JSON.stringify(refused), /25/);
			// Un site déjà connu reste couvert.
			assert.equal((await verify(licence, { site: site(100), dev: true })).valid, true);
		});

		test('verify dev:true : licence échue ou révoquée refusée comme avant', async () => {
			const expired = await createLicence({ expires: '2020-01-01' });
			assert.equal((await verify(expired.licence, { site: site(30), dev: true })).code, 'licence_expired');
			const revoked = await createLicence();
			await t.sql`update licences set revoked_at = now() where id = ${revoked.id}`;
			assert.equal((await verify(revoked.licence, { site: site(31), dev: true })).valid, false);
			assert.equal((await t.sql`select count(*)::int as n from licence_dev_sites where licence_id in (${expired.id}, ${revoked.id})`)[0].n, 0);
		});

		test('dev mal formé : 400 sur verify et sur update', async () => {
			const { licence } = await createLicence();
			const res = await t.request('/v1/licences/verify', { method: 'POST', body: { site: site(40), dev: 'oui' }, headers: { 'X-Fluffless-Licence': licence } });
			assert.equal(res.status, 400);
			assert.equal((await res.json()).code, 'invalid_field');
			assert.equal((await update(licence, { site: site(40), dev: '2' })).status, 400);
		});

		test('update dev=1 : signe le lien sans inscrire le site', async () => {
			const { id, licence } = await createLicence();
			const res = await update(licence, { site: site(50), dev: '1' });
			assert.equal(res.status, 200);
			assert.match((await res.json()).package, /\/v1\/download\//);
			assert.equal(await seats(id), 0);
			assert.equal((await t.sql`select count(*)::int as n from licence_dev_sites where licence_id = ${id}`)[0].n, 1);
		});

		test('last_seen_at : une seule écriture par 24 h', async () => {
			const { id, licence } = await createLicence();
			await verify(licence, { site: site(60) });
			const seen = async () => (await t.sql`select last_seen_at from licence_sites where licence_id = ${id}`)[0].last_seen_at.getTime();
			const first = await seen();
			clock += 3_600_000;
			await verify(licence, { site: site(60) });
			assert.equal(await seen(), first);
			clock += DAY;
			await verify(licence, { site: site(60) });
			assert.ok(await seen() > first);
		});
	});

	describe('libération d\'une place', () => {
		const release = (key, body, headers = {}) => t.request('/v1/licences/release', {
			method: 'POST', body, headers: { ...(key ? { 'X-Fluffless-Licence': key } : {}), ...headers },
		});

		test('release : rend la place, puis un autre site entre', async () => {
			const { id, licence } = await createLicence();
			assert.equal((await verify(licence, { site: site(300) })).valid, true);
			assert.equal((await verify(licence, { site: site(301) })).code, 'site_limit');
			const res = await release(licence, { site: site(300) });
			assert.equal(res.status, 200);
			assert.deepEqual(await res.json(), { released: true, sites_used: 0, sites_max: 1 });
			assert.equal((await verify(licence, { site: site(301) })).valid, true);
			assert.equal(await seats(id), 1);
		});

		test('release : idempotente, et une libération sans effet ne compte pas', async () => {
			const { id, licence } = await createLicence();
			await verify(licence, { site: site(310) });
			assert.equal((await (await release(licence, { site: site(310) })).json()).released, true);
			assert.equal((await (await release(licence, { site: site(310) })).json()).released, false);
			assert.equal((await t.sql`select count(*)::int as n from licence_site_releases where licence_id = ${id}`)[0].n, 1);
		});

		test('release : libère aussi un site de développement', async () => {
			const { id, licence } = await createLicence();
			await verify(licence, { site: site(315), dev: true });
			assert.equal((await (await release(licence, { site: site(315) })).json()).released, true);
			assert.equal((await t.sql`select count(*)::int as n from licence_dev_sites where licence_id = ${id}`)[0].n, 0);
		});

		test('release : licence échue acceptée, inconnue ou révoquée refusée, clé en requête refusée', async () => {
			const expired = await createLicence({ expires: '2020-01-01' });
			assert.equal((await release(expired.licence, { site: site(320) })).status, 200);
			const unknown = await release('FL-FAUX', { site: site(320) });
			assert.equal(unknown.status, 403);
			assert.equal((await unknown.json()).code, 'licence_invalid');
			assert.equal((await release(null, { site: site(320) })).status, 403);
			const revoked = await createLicence();
			await t.sql`update licences set revoked_at = now() where id = ${revoked.id}`;
			assert.equal((await release(revoked.licence, { site: site(320) })).status, 403);
			const inQuery = await t.request(`/v1/licences/release?licence=${expired.licence}`, { method: 'POST', body: { site: site(320) } });
			assert.equal(inQuery.status, 400);
			assert.equal((await inQuery.json()).code, 'licence_in_query');
			assert.equal((await release(expired.licence, { site: 'x' })).status, 400);
			assert.equal((await release(expired.licence, { site: site(320), bonus: 1 })).status, 400);
		});

		test('release : 4e libération en 30 jours sur une licence d\'un site, 429 avec Retry-After, puis de nouveau permise', async () => {
			const { licence } = await createLicence();
			for (let n = 0; n < 3; n++) {
				await verify(licence, { site: site(330 + n) });
				assert.equal((await (await release(licence, { site: site(330 + n) })).json()).released, true);
			}
			await verify(licence, { site: site(340) });
			const refused = await release(licence, { site: site(340) });
			assert.equal(refused.status, 429);
			const body = await refused.json();
			assert.equal(body.code, 'release_limit');
			assert.ok(Number(refused.headers.get('retry-after')) > 0);
			assert.match(body.retry_at, /^\d{4}-\d{2}-\d{2}T/);
			clock += 31 * DAY;
			assert.equal((await (await release(licence, { site: site(340) })).json()).released, true);
		});

		test('release : deux libérations simultanées ne dépassent pas le plafond', async () => {
			const { id, licence } = await createLicence({ sites_max: 5 });
			for (let n = 0; n < 5; n++) {
				await verify(licence, { site: site(350 + n) });
			}
			await t.sql`insert into licence_site_releases (licence_id, released_at) values (${id}, ${new Date(clock)}), (${id}, ${new Date(clock)}), (${id}, ${new Date(clock)}), (${id}, ${new Date(clock)})`;
			const results = await Promise.all([release(licence, { site: site(350) }), release(licence, { site: site(351) })]);
			assert.deepEqual(results.map((res) => res.status).sort(), [200, 429]);
			assert.equal((await t.sql`select count(*)::int as n from licence_site_releases where licence_id = ${id}`)[0].n, 5);
		});
	});

	describe('places vues par l\'administration', () => {
		test('admin : liste les sites d\'une licence, empreinte tronquée à 12 caractères', async () => {
			const { id, licence } = await createLicence({ sites_max: 3 });
			await verify(licence, { site: site(400) });
			await verify(licence, { site: site(401), dev: true });
			const res = await admin(`/v1/admin/licences/${id}/sites`);
			assert.equal(res.status, 200);
			const body = await res.json();
			assert.equal(body.sites_max, 3);
			const [seat] = await t.sql`select site_hash from licence_sites where licence_id = ${id}`;
			const [dev] = await t.sql`select site_hash from licence_dev_sites where licence_id = ${id}`;
			assert.deepEqual(body.sites.map((row) => [row.hash, row.dev]), [[seat.site_hash.slice(0, 12), false], [dev.site_hash.slice(0, 12), true]]);
			assert.doesNotMatch(JSON.stringify(body), new RegExp(seat.site_hash));
			assert.match(body.sites[0].registered_at, /^\d{4}-/);
			assert.match(body.sites[0].last_seen_at, /^\d{4}-/);
			assert.doesNotMatch(JSON.stringify(body), new RegExp(site(400)));
			assert.equal((await admin('/v1/admin/licences/999999/sites')).status, 404);
			assert.equal((await t.request(`/v1/admin/licences/${id}/sites`)).status, 401);
		});

		test('admin : libère un site par son empreinte tronquée, sans entamer le plafond de la cliente', async () => {
			const { id, licence } = await createLicence();
			await verify(licence, { site: site(410) });
			const short = (await t.sql`select site_hash from licence_sites where licence_id = ${id}`)[0].site_hash.slice(0, 12);
			const res = await admin(`/v1/admin/licences/${id}/sites/${short}`, 'DELETE');
			assert.equal(res.status, 200);
			assert.deepEqual(await res.json(), { removed: 1 });
			assert.equal(await seats(id), 0);
			assert.equal((await t.sql`select count(*)::int as n from licence_site_releases where licence_id = ${id}`)[0].n, 0);
			assert.equal((await admin(`/v1/admin/licences/${id}/sites/${short}`, 'DELETE')).status, 404);
			assert.equal((await admin(`/v1/admin/licences/${id}/sites/abc`, 'DELETE')).status, 404);
		});

		test('admin : libère tous les sites d\'une licence', async () => {
			const { id, licence } = await createLicence({ sites_max: 3 });
			await verify(licence, { site: site(420) });
			await verify(licence, { site: site(421) });
			await verify(licence, { site: site(422), dev: true });
			const res = await admin(`/v1/admin/licences/${id}/sites`, 'DELETE');
			assert.deepEqual(await res.json(), { removed: 3 });
			assert.equal(await seats(id), 0);
			assert.equal((await admin('/v1/admin/licences/999999/sites', 'DELETE')).status, 404);
		});
	});

	describe('licence illimitée', () => {
		test('verify sur illimitée : sites_max null, unlimited true, aucun 10 000 dans la réponse', async () => {
			const { licence } = await createLicence({ plan: 'agence', sites_max: 10_000 });
			const state = await verify(licence, { site: site(500) });
			assert.equal(state.valid, true);
			assert.equal(state.sites_max, null);
			assert.equal(state.unlimited, true);
			assert.doesNotMatch(JSON.stringify(state), /10000|10 000/);
			const freed = await t.request('/v1/licences/release', { method: 'POST', body: { site: site(500) }, headers: { 'X-Fluffless-Licence': licence } });
			assert.deepEqual(await freed.json(), { released: true, sites_used: 0, sites_max: null, unlimited: true });
		});

		test('verify sur 5 sites : sites_max 5, sans unlimited', async () => {
			const { licence } = await createLicence({ sites_max: 5 });
			const state = await verify(licence, { site: site(510) });
			assert.equal(state.sites_max, 5);
			assert.equal('unlimited' in state, false);
		});

		test('illimitée au 10 000e site : le suivant rend licence_review, jamais site_limit, et une seule alerte', async () => {
			const { id, licence } = await createLicence({ plan: 'agence', sites_max: 10_000 });
			await t.sql`
				insert into licence_sites (licence_id, site_hash)
				select ${id}, 'rempli-' || n from generate_series(1, 10000) as n`;
			const before = t.alerts.length;
			const refused = await verify(licence, { site: site(520) });
			assert.equal(refused.valid, false);
			assert.equal(refused.code, 'licence_review');
			assert.equal(refused.sites_max, null);
			// Seul le compte réel des sites porte ce nombre ici, jamais un maximum.
			const { sites_used: used, ...rest } = refused;
			assert.equal(used, 10_000);
			assert.doesNotMatch(JSON.stringify(rest), /10000|10 000/);
			assert.equal((await verify(licence, { site: site(521) })).code, 'licence_review');
			const res = await update(licence, { site: site(522) });
			assert.equal(res.status, 403);
			assert.equal((await res.json()).code, 'licence_review');
			const sent = t.alerts.slice(before);
			assert.equal(sent.length, 1);
			assert.equal(sent[0].subject, 'Licence à examiner');
			assert.doesNotMatch(JSON.stringify(sent[0]), /[0-9a-f]{12,}|FL-|@/);
			const flags = await t.sql`select rule, level, observed from licence_flags where licence_id = ${id}`;
			assert.deepEqual(flags.map((row) => [row.rule, row.level]), [['ceiling', 10_000]]);
		});
	});

	describe('licence révoquée', () => {
		test('révoquée : licence_revoked sur verify et update; une clé inconnue garde licence_invalid', async () => {
			const { id, licence } = await createLicence();
			await verify(licence, { site: site(600) });
			await t.sql`update licences set revoked_at = now() where id = ${id}`;
			const state = await verify(licence, { site: site(600) });
			assert.equal(state.valid, false);
			assert.equal(state.code, 'licence_revoked');
			const res = await update(licence, { site: site(600) });
			assert.equal(res.status, 403);
			assert.equal((await res.json()).code, 'licence_revoked');
			assert.equal((await verify('FL-FAUX', { site: site(600) })).code, 'licence_invalid');
			assert.equal(await seats(id), 1);
		});
	});

	describe('avis, révocation, rétablissement', () => {
		test('notice : days de 30 à 60, 30 par défaut, raison connue, aucun courriel envoyé', async () => {
			const { id } = await createLicence({ email: 'cliente@exemple.ca' });
			const outgoing = () => t.mails.length + t.deliveries.length + t.licenceDeliveries.length + t.reminders.length;
			const mails = outgoing();
			assert.equal((await admin(`/v1/admin/licences/${id}/notice`, 'POST', { reason: 'usage', days: 29 })).status, 400);
			assert.equal((await admin(`/v1/admin/licences/${id}/notice`, 'POST', { reason: 'usage', days: 61 })).status, 400);
			assert.equal((await admin(`/v1/admin/licences/${id}/notice`, 'POST', { reason: 'autre' })).status, 400);
			assert.equal((await admin(`/v1/admin/licences/${id}/notice`, 'POST', { reason: 'usage', bonus: 1 })).status, 400);
			const res = await admin(`/v1/admin/licences/${id}/notice`, 'POST', { reason: 'usage' });
			assert.equal(res.status, 200);
			const body = await res.json();
			assert.equal(body.notice_reason, 'usage');
			assert.equal(new Date(body.notice_until).getTime() - new Date(body.notice_at).getTime(), 30 * DAY);
			const sixty = await (await admin(`/v1/admin/licences/${id}/notice`, 'POST', { reason: 'resale', days: 60 })).json();
			assert.equal(new Date(sixty.notice_until).getTime() - new Date(sixty.notice_at).getTime(), 60 * DAY);
			assert.equal(outgoing(), mails);
			assert.equal((await admin('/v1/admin/licences/999999/notice', 'POST', { reason: 'usage' })).status, 404);
			assert.equal((await t.request(`/v1/admin/licences/${id}/notice`, { method: 'POST', body: { reason: 'usage' } })).status, 401);
		});

		test('pendant l\'avis : verify valide avec notice_until, update aussi (corps et en-tête)', async () => {
			const { id, licence } = await createLicence();
			const { notice_until: until } = await (await admin(`/v1/admin/licences/${id}/notice`, 'POST', { reason: 'sharing' })).json();
			const state = await verify(licence, { site: site(700) });
			assert.equal(state.valid, true);
			assert.equal(state.notice_until, until);
			const fresh = await update(licence, { site: site(700) });
			assert.equal(fresh.status, 200);
			assert.equal((await fresh.json()).notice_until, until);
			assert.equal(fresh.headers.get('x-fluffless-notice-until'), until);
			const current = await update(licence, { site: site(700), version: '1.0.0' });
			assert.equal(current.status, 204);
			assert.equal(current.headers.get('x-fluffless-notice-until'), until);
			const off = await admin(`/v1/admin/licences/${id}/notice`, 'DELETE');
			assert.equal(off.status, 200);
			assert.equal((await off.json()).notice_until, null);
			assert.equal('notice_until' in await verify(licence, { site: site(700) }), false);
			assert.equal((await update(licence, { site: site(700) })).headers.get('x-fluffless-notice-until'), null);
		});

		test('revoke : 409 sans avis ou avant la fin de l\'avis, 200 après; restore rouvre tout', async () => {
			const { id, licence } = await createLicence({ expires: new Date(clock + 365 * DAY).toISOString().slice(0, 10) });
			await verify(licence, { site: site(710) });
			const early = await admin(`/v1/admin/licences/${id}/revoke`, 'POST', { reason: 'Revente constatée' });
			assert.equal(early.status, 409);
			assert.equal((await early.json()).code, 'notice_required');
			await admin(`/v1/admin/licences/${id}/notice`, 'POST', { reason: 'resale' });
			const running = await admin(`/v1/admin/licences/${id}/revoke`, 'POST', { reason: 'Revente constatée' });
			assert.equal(running.status, 409);
			assert.equal((await running.json()).code, 'notice_running');
			assert.equal((await admin(`/v1/admin/licences/${id}/revoke`, 'POST', {})).status, 400);
			clock += 31 * DAY;
			const revoked = await admin(`/v1/admin/licences/${id}/revoke`, 'POST', { reason: 'Revente constatée' });
			assert.equal(revoked.status, 200);
			assert.match((await revoked.json()).revoked_at, /^\d{4}-/);
			assert.equal((await verify(licence, { site: site(710) })).code, 'licence_revoked');
			const [kept] = await t.sql`select notice_reason, revoke_reason from licences where id = ${id}`;
			assert.deepEqual({ ...kept }, { notice_reason: 'resale', revoke_reason: 'Revente constatée' });

			const restored = await admin(`/v1/admin/licences/${id}/restore`, 'POST');
			assert.equal(restored.status, 200);
			const state = await verify(licence, { site: site(710) });
			assert.equal(state.valid, true);
			assert.equal(state.sites_used, 1);
			const [row] = await t.sql`select revoked_at, revoke_reason, notice_at, notice_until, notice_reason, restored_at from licences where id = ${id}`;
			assert.equal(row.revoked_at, null);
			assert.equal(row.revoke_reason, null);
			assert.equal(row.notice_at, null);
			assert.equal(row.notice_until, null);
			assert.equal(row.notice_reason, null);
			assert.equal(row.restored_at.getTime(), clock);
			assert.equal((await admin(`/v1/admin/licences/${id}/restore`, 'POST')).status, 409);
			assert.equal((await admin('/v1/admin/licences/999999/restore', 'POST')).status, 404);
		});

		test('revoke après un remboursement : sans avis, la raison le dit', async () => {
			const { id, licence } = await createLicence();
			assert.equal((await admin(`/v1/admin/licences/${id}/revoke`, 'POST', { reason: 'x', refund: 'oui' })).status, 400);
			const res = await admin(`/v1/admin/licences/${id}/revoke`, 'POST', { reason: 'Remboursée le 26 octobre', refund: true });
			assert.equal(res.status, 200);
			assert.equal((await verify(licence, { site: site(720) })).code, 'licence_revoked');
			const [row] = await t.sql`select revoke_reason, notice_at from licences where id = ${id}`;
			assert.equal(row.revoke_reason, 'refund: Remboursée le 26 octobre');
			assert.equal(row.notice_at, null);
		});
	});

	describe('signalements', () => {
		const flags = async (id) => (await t.sql`select rule, level, observed from licence_flags where licence_id = ${id} order by id`)
			.map((row) => [row.rule, row.observed]);
		const release = (key, n) => t.request('/v1/licences/release', { method: 'POST', body: { site: site(n) }, headers: { 'X-Fluffless-Licence': key } });

		test('R6 : plafond des sites de développement atteint, une seule alerte, aucune empreinte', async () => {
			const { id, licence } = await createLicence();
			for (let n = 0; n < 25; n++) {
				await verify(licence, { site: site(800 + n), dev: true });
			}
			const before = t.alerts.length;
			assert.equal((await verify(licence, { site: site(900), dev: true })).code, 'dev_limit');
			assert.equal((await verify(licence, { site: site(901), dev: true })).code, 'dev_limit');
			const sent = t.alerts.slice(before);
			assert.equal(sent.length, 1);
			assert.equal(sent[0].subject, 'Licence à examiner');
			assert.doesNotMatch(JSON.stringify(sent[0]), /[0-9a-f]{12,}|FL-|@/);
			assert.deepEqual(await flags(id), [['dev', { dev_sites: 25 }]]);
		});

		test('R5 : plafond de libérations atteint sur une illimitée, une alerte par fenêtre de 30 jours', async () => {
			const { id, licence } = await createLicence({ plan: 'agence', sites_max: 10_000 });
			await t.sql`insert into licence_site_releases (licence_id, released_at) select ${id}, ${new Date(clock)} from generate_series(1, 50)`;
			await verify(licence, { site: site(950) });
			const before = t.alerts.length;
			assert.equal((await release(licence, 950)).status, 429);
			assert.equal((await release(licence, 950)).status, 429);
			assert.equal(t.alerts.length - before, 1);
			assert.deepEqual(await flags(id), [['releases', { releases: 50 }]]);
		});

		test('R5 : une licence de 1 site au plafond de libérations ne signale rien', async () => {
			const { id, licence } = await createLicence();
			await t.sql`insert into licence_site_releases (licence_id, released_at) select ${id}, ${new Date(clock)} from generate_series(1, 3)`;
			await verify(licence, { site: site(960) });
			assert.equal((await release(licence, 960)).status, 429);
			assert.deepEqual(await flags(id), []);
		});

		test('admin : liste des signalements ouverts, puis issue posée', async () => {
			const { id, licence } = await createLicence();
			for (let n = 0; n < 26; n++) {
				await verify(licence, { site: site(1000 + n), dev: true });
			}
			const open = await (await admin('/v1/admin/licence-flags?open=1')).json();
			const mine = open.flags.find((flag) => flag.licence_id === Number(id));
			assert.equal(mine.rule, 'dev');
			assert.deepEqual(mine.observed, { dev_sites: 25 });
			assert.equal(mine.reviewed_at, null);
			assert.equal((await admin(`/v1/admin/licence-flags/${mine.id}`, 'PATCH', { outcome: 'banni' })).status, 400);
			const done = await admin(`/v1/admin/licence-flags/${mine.id}`, 'PATCH', { outcome: 'ok' });
			assert.equal(done.status, 200);
			assert.equal((await done.json()).outcome, 'ok');
			const after = await (await admin('/v1/admin/licence-flags?open=1')).json();
			assert.equal(after.flags.some((flag) => flag.id === mine.id), false);
			const all = await (await admin('/v1/admin/licence-flags')).json();
			assert.equal(all.flags.some((flag) => flag.id === mine.id), true);
			assert.equal((await admin('/v1/admin/licence-flags/999999', 'PATCH', { outcome: 'ok' })).status, 404);
		});
	});
});
