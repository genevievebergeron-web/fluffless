#!/usr/bin/env node
// Petit client en ligne de commande pour les routes /v1/admin.
//
//   FLUFFLESS_API_URL=https://api.fluffless.dev
//   FLUFFLESS_ADMIN_TOKEN_FILE=~/…/secrets/ADMIN_TOKEN   (ou FLUFFLESS_ADMIN_TOKEN=…)
//
//   node bin/admin.mjs release <archive.zip> <meta.json>
//   node bin/admin.mjs delete-release <slug> <version>
//   node bin/admin.mjs licence --plan solo --sites 1 [--expires 2027-09-12] [--note "…"] [--email a@b.ca] [--release fluffless-suite,fluffless-seo|all]
//   node bin/admin.mjs licence-email <id> <courriel|->
//   node bin/admin.mjs licence-releases <id> <fluffless-suite,fluffless-seo|all>
//   node bin/admin.mjs rotate-licence <id>
//   node bin/admin.mjs licence-sites <id>   (empreintes tronquées, dev, inscription, dernier contact)
//   node bin/admin.mjs licence-release <id> <empreinte de 12 caractères|all>
//   node bin/admin.mjs licence-notice <id> --reason usage|resale|sharing|other [--days 30..60]
//   node bin/admin.mjs licence-notice-off <id>
//   node bin/admin.mjs licence-revoke <id> --reason "…" [--refund]   (après un avis arrivé à terme, ou un remboursement)
//   node bin/admin.mjs licence-restore <id>
//   node bin/admin.mjs licence-flags [--all]   (signalements à examiner; --all : aussi ceux déjà vus)
//   node bin/admin.mjs licence-flag <flag-id> <ok|notice|revoked>
//   node bin/admin.mjs licence-resend <id> [--email a@b.ca]   (licence achetée : nouvelle clé par courriel)
//   node bin/admin.mjs renewal-refund <checkout_session_id>
//       (après un remboursement fait dans Stripe : défait le dernier renouvellement, rend le nouveau lien)
//   node bin/admin.mjs offers
//   node bin/admin.mjs offer <slug> --plan solo --sites 1 [--days 365] [--release fluffless-suite,fluffless-seo]
//   node bin/admin.mjs offer-price <slug> <price_…|->
//   node bin/admin.mjs coupons
//   node bin/admin.mjs coupon <CODE> --percent 1..99 [--label "…"] [--max 50] [--until 2027-12-31T23:59:59Z]
//   node bin/admin.mjs coupon-off <CODE>
//   node bin/admin.mjs licence-link <offre> --coupon <CODE> --note "Organisme, NEQ …" [--hours 1..24] [--email a@b.ca] [--locale fr_CA|en_CA]
//       (lien de paiement à tarif réduit, valable 24 h par défaut; l'adresse ne s'imprime qu'ici)
//   node bin/admin.mjs delete-licence <id> [--with-packs]
//   node bin/admin.mjs pack <archive.zip> <pack.json>
//   node bin/admin.mjs pack-window <slug> <ouverture|-> <fermeture|->
//   node bin/admin.mjs pack-update <slug> <archive.zip>
//   node bin/admin.mjs pack-price <slug> <price_…|->
//   node bin/admin.mjs reconcile
//   node bin/admin.mjs delete-pack <slug>
//   node bin/admin.mjs reports [--status nouveau|vu|réglé] [--limit 100]
//   node bin/admin.mjs report-status <id> <nouveau|vu|regle>
//   node bin/admin.mjs screenshot <id> <sortie.png>
//   node bin/admin.mjs delete-report <id>
//
// meta.json : {"slug","version","channel","name","homepage","requires","requires_php","tested",
//              "description":{"fr","en"},"changelog":{"fr","en"}}
// pack.json : {"slug","name":{"fr","en"},"description":{"fr","en"},"claim_opens","claim_closes"}
//             (dates ISO 8601, ou null pour les deux : pas de réclamation gratuite)
import { readFileSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { parseArgs } from 'node:util';

// L'aide est l'en-tête de commentaires ci-dessus, lu jusqu'à la première ligne de code.
const header = readFileSync(new URL(import.meta.url), 'utf8').split('\n').slice(1);
const USAGE = header.slice(0, header.findIndex((l) => !l.startsWith('//'))).map((l) => l.replace(/^\/\/ ?/, '')).join('\n');

function fail(message) {
	process.stderr.write(`${message}\n`);
	process.exit(1);
}

function adminToken() {
	if (process.env.FLUFFLESS_ADMIN_TOKEN) {
		return process.env.FLUFFLESS_ADMIN_TOKEN;
	}
	const file = process.env.FLUFFLESS_ADMIN_TOKEN_FILE;
	if (!file) {
		fail('Définis FLUFFLESS_ADMIN_TOKEN ou FLUFFLESS_ADMIN_TOKEN_FILE.');
	}
	return readFileSync(file.replace(/^~/, homedir()), 'utf8').trim();
}

async function call(method, path, body) {
	const base = process.env.FLUFFLESS_API_URL;
	if (!base) {
		fail('Définis FLUFFLESS_API_URL (ex. https://api.fluffless.dev).');
	}
	const res = await fetch(new URL(path, base), {
		method,
		headers: { Authorization: `Bearer ${adminToken()}`, ...(body ? { 'Content-Type': 'application/json' } : {}) },
		body: body ? JSON.stringify(body) : undefined,
	});
	if (!res.ok) {
		fail(`${method} ${path} : HTTP ${res.status} ${await res.text()}`);
	}
	return res;
}

const printJson = async (res) => process.stdout.write(res.status === 204 ? 'OK\n' : `${JSON.stringify(await res.json(), null, 2)}\n`);

const { values, positionals } = parseArgs({
	allowPositionals: true,
	options: {
		plan: { type: 'string' },
		sites: { type: 'string' },
		expires: { type: 'string' },
		note: { type: 'string' },
		email: { type: 'string' },
		'with-packs': { type: 'boolean' },
		status: { type: 'string' },
		limit: { type: 'string' },
		days: { type: 'string' },
		release: { type: 'string' },
		percent: { type: 'string' },
		label: { type: 'string' },
		max: { type: 'string' },
		until: { type: 'string' },
		reason: { type: 'string' },
		refund: { type: 'boolean' },
		all: { type: 'boolean' },
		coupon: { type: 'string' },
		hours: { type: 'string' },
		locale: { type: 'string' },
	},
});
const [command, ...args] = positionals;
const need = (count) => args.length >= count || fail(USAGE);
// « all » : toutes les extensions (null côté API), à n'utiliser que de propos délibéré.
const releaseSlugs = (value) => (value === 'all' ? null : value.split(','));

switch (command) {
	case 'release': {
		need(2);
		const meta = JSON.parse(readFileSync(args[1], 'utf8'));
		await printJson(await call('POST', '/v1/admin/releases', { ...meta, zip_base64: readFileSync(args[0]).toString('base64') }));
		break;
	}
	case 'delete-release':
		need(2);
		await printJson(await call('DELETE', `/v1/admin/releases/${encodeURIComponent(args[0])}/${encodeURIComponent(args[1])}`));
		break;
	case 'licence':
		if (!values.plan || !values.sites) {
			fail(USAGE);
		}
		await printJson(await call('POST', '/v1/admin/licences', {
			plan: values.plan,
			sites_max: Number(values.sites),
			expires: values.expires ?? null,
			...(values.note ? { note: values.note } : {}),
			...(values.email ? { email: values.email } : {}),
			...(values.release ? { release_slugs: releaseSlugs(values.release) } : {}),
		}));
		break;
	case 'licence-releases':
		need(2);
		await printJson(await call('PATCH', `/v1/admin/licences/${encodeURIComponent(args[0])}`, { release_slugs: releaseSlugs(args[1]) }));
		break;
	case 'licence-sites':
		need(1);
		await printJson(await call('GET', `/v1/admin/licences/${encodeURIComponent(args[0])}/sites`));
		break;
	case 'licence-release':
		need(2);
		await printJson(await call('DELETE', `/v1/admin/licences/${encodeURIComponent(args[0])}/sites${args[1] === 'all' ? '' : `/${encodeURIComponent(args[1])}`}`));
		break;
	case 'licence-notice':
		need(1);
		if (!values.reason) {
			fail(USAGE);
		}
		await printJson(await call('POST', `/v1/admin/licences/${encodeURIComponent(args[0])}/notice`, {
			reason: values.reason,
			...(values.days ? { days: Number(values.days) } : {}),
		}));
		break;
	case 'licence-notice-off':
		need(1);
		await printJson(await call('DELETE', `/v1/admin/licences/${encodeURIComponent(args[0])}/notice`));
		break;
	case 'licence-revoke':
		need(1);
		if (!values.reason) {
			fail(USAGE);
		}
		await printJson(await call('POST', `/v1/admin/licences/${encodeURIComponent(args[0])}/revoke`, {
			reason: values.reason,
			...(values.refund ? { refund: true } : {}),
		}));
		break;
	case 'licence-restore':
		need(1);
		await printJson(await call('POST', `/v1/admin/licences/${encodeURIComponent(args[0])}/restore`));
		break;
	case 'licence-flags':
		await printJson(await call('GET', `/v1/admin/licence-flags${values.all ? '' : '?open=1'}`));
		break;
	case 'licence-flag':
		need(2);
		await printJson(await call('PATCH', `/v1/admin/licence-flags/${encodeURIComponent(args[0])}`, { outcome: args[1] }));
		break;
	case 'licence-email':
		need(2);
		await printJson(await call('PATCH', `/v1/admin/licences/${encodeURIComponent(args[0])}`, { email: args[1] === '-' ? null : args[1] }));
		break;
	case 'pack': {
		need(2);
		const meta = JSON.parse(readFileSync(args[1], 'utf8'));
		await printJson(await call('POST', '/v1/admin/packs', { ...meta, zip_base64: readFileSync(args[0]).toString('base64') }));
		break;
	}
	case 'pack-window':
		need(3);
		await printJson(await call('PATCH', `/v1/admin/packs/${encodeURIComponent(args[0])}`, {
			claim_opens: args[1] === '-' ? null : args[1],
			claim_closes: args[2] === '-' ? null : args[2],
		}));
		break;
	case 'pack-update':
		need(2);
		await printJson(await call('PUT', `/v1/admin/packs/${encodeURIComponent(args[0])}/zip`, { zip_base64: readFileSync(args[1]).toString('base64') }));
		break;
	case 'pack-price':
		need(2);
		await printJson(await call('PUT', `/v1/admin/packs/${encodeURIComponent(args[0])}/price`, { price_id: args[1] === '-' ? null : args[1] }));
		break;
	case 'licence-resend':
		need(1);
		await printJson(await call('POST', `/v1/admin/licences/${encodeURIComponent(args[0])}/resend`, values.email ? { email: values.email } : {}));
		break;
	case 'renewal-refund':
		need(1);
		await printJson(await call('POST', `/v1/admin/licence-renewals/${encodeURIComponent(args[0])}/refund`, {}));
		break;
	case 'offers':
		await printJson(await call('GET', '/v1/admin/licence-offers'));
		break;
	case 'offer':
		need(1);
		if (!values.plan || !values.sites) {
			fail(USAGE);
		}
		await printJson(await call('POST', '/v1/admin/licence-offers', {
			slug: args[0],
			plan: values.plan,
			sites_max: Number(values.sites),
			duration_days: values.days ? Number(values.days) : null,
			...(values.release ? { release_slugs: values.release.split(',') } : {}),
		}));
		break;
	case 'offer-price':
		need(2);
		await printJson(await call('PUT', `/v1/admin/licence-offers/${encodeURIComponent(args[0])}/price`, { price_id: args[1] === '-' ? null : args[1] }));
		break;
	case 'coupons':
		await printJson(await call('GET', '/v1/admin/licence-coupons'));
		break;
	case 'coupon':
		need(1);
		if (!values.percent) {
			fail(USAGE);
		}
		await printJson(await call('POST', '/v1/admin/licence-coupons', {
			code: args[0],
			percent_off: Number(values.percent),
			...(values.label ? { label: values.label } : {}),
			...(values.max ? { max_redemptions: Number(values.max) } : {}),
			...(values.until ? { expires_at: values.until } : {}),
		}));
		break;
	case 'coupon-off':
		need(1);
		await printJson(await call('DELETE', `/v1/admin/licence-coupons/${encodeURIComponent(args[0].toUpperCase())}`));
		break;
	case 'licence-link': {
		if (args.length < 1 || !values.coupon || !values.note) {
			fail(USAGE);
		}
		const res = await call('POST', '/v1/admin/licence-links', {
			offer: args[0],
			locale: values.locale ?? 'fr_CA',
			coupon: values.coupon,
			note: values.note,
			...(values.hours ? { hours: Number(values.hours) } : {}),
			...(values.email ? { email: values.email } : {}),
		});
		const link = await res.json();
		const until = new Date(link.expires_at).toLocaleString('fr-CA', { timeZone: 'America/Montreal', dateStyle: 'long', timeStyle: 'short' });
		// Ni fichier ni journal : l'adresse vaut paiement jusqu'à son expiration.
		process.stdout.write(`${link.url}\nOffre ${link.offer}, coupon ${link.coupon} (${link.percent_off} %), valable jusqu'au ${until} (heure de Montréal)\n`);
		break;
	}
	case 'reconcile':
		await printJson(await call('POST', '/v1/admin/stripe/reconcile'));
		break;
	case 'delete-pack':
		need(1);
		await printJson(await call('DELETE', `/v1/admin/packs/${encodeURIComponent(args[0])}`));
		break;
	case 'rotate-licence':
		need(1);
		await printJson(await call('POST', `/v1/admin/licences/${encodeURIComponent(args[0])}/rotate`));
		break;
	case 'delete-licence':
		need(1);
		await printJson(await call('DELETE', `/v1/admin/licences/${encodeURIComponent(args[0])}${values['with-packs'] ? '?with_packs=1' : ''}`));
		break;
	case 'reports': {
		const query = new URLSearchParams();
		if (values.status) query.set('status', values.status === 'regle' ? 'réglé' : values.status);
		if (values.limit) query.set('limit', values.limit);
		await printJson(await call('GET', `/v1/admin/reports?${query}`));
		break;
	}
	case 'report-status':
		need(2);
		await printJson(await call('PATCH', `/v1/admin/reports/${encodeURIComponent(args[0])}`, { status: args[1] === 'regle' ? 'réglé' : args[1] }));
		break;
	case 'screenshot': {
		need(2);
		const res = await call('GET', `/v1/admin/reports/${encodeURIComponent(args[0])}/screenshot`);
		writeFileSync(args[1], Buffer.from(await res.arrayBuffer()));
		process.stdout.write(`Capture écrite dans ${args[1]}\n`);
		break;
	}
	case 'delete-report':
		need(1);
		await printJson(await call('DELETE', `/v1/admin/reports/${encodeURIComponent(args[0])}`));
		break;
	default:
		fail(USAGE);
}
