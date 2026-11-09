// Pages de l'acheteuse, courriels et limiteur par IP : constats mineurs du bloc F27 de l'audit final.
// Aucun besoin de base : les pages et les courriels se rendent à partir de fonctions pures.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createIpLimiter, licenceDownloadHtml, packLinkHtml, renewHtml } from '../src/app.mjs';
import {
	createReminderMailer, formatLicenceEmail, formatPackEmail, formatReminderEmail, formatRenewalRefundEmail, formatReportEmail,
} from '../src/mail.mjs';

const html = ([, body]) => body.toString();
const EXPIRED = { date: { fr: '1 octobre 2026', en: 'October 1, 2026' }, renew: 'https://api.exemple/v1/licences/renew?t=abc', grace: { fr: '31 octobre 2026', en: 'October 31, 2026' } };
const LINKS = [
	{ url: 'https://api.exemple/v1/download/fluffless-suite/1.0.0/1/a.zip', name: 'Fluffless Suite', version: '1.0.0' },
	{ url: 'https://api.exemple/v1/download/fluffless-seo/1.0.0/1/b.zip', name: 'Fluffless SEO', version: '1.0.0' },
];
const DOWNLOAD_PAGES = {
	form: licenceDownloadHtml(200, 'form'),
	invalid: licenceDownloadHtml(403, 'invalid'),
	partial: licenceDownloadHtml(200, 'partial', LINKS.slice(0, 1)),
	unavailable: licenceDownloadHtml(503, 'unavailable'),
	expired: licenceDownloadHtml(403, 'expired', [], EXPIRED),
	expiredSansLien: licenceDownloadHtml(403, 'expired', [], { ...EXPIRED, renew: null, grace: null }),
	links: licenceDownloadHtml(200, 'links', LINKS),
};
const RENEW_PAGES = {
	renewInvalid: renewHtml(410, 'renewInvalid'),
	renewUnavailable: renewHtml(503, 'renewUnavailable'),
	renewLimited: renewHtml(429, 'renewLimited'),
};

test('C4-1-03 : un seul role=alert par état, qui porte le français et l\'anglais', () => {
	for (const [state, page] of Object.entries({ ...DOWNLOAD_PAGES, ...RENEW_PAGES })) {
		const body = html(page);
		const alerts = body.match(/role="alert"/g) ?? [];
		const expected = ['form', 'links'].includes(state) ? 0 : 1;
		assert.equal(alerts.length, expected, `${state} : ${alerts.length} role=alert`);
		if (expected) {
			const zone = /<div role="alert">([\s\S]*?)<\/div>/.exec(body);
			assert.ok(zone, `${state} : conteneur role=alert`);
			assert.match(zone[1], /<p>/, `${state} : le français dans l'alerte`);
			assert.match(zone[1], /<p lang="en">/, `${state} : l'anglais dans l'alerte`);
		}
	}
});

test('C4-1-04 : la partie anglaise des libellés et boutons bilingues porte lang="en"', () => {
	for (const page of [...Object.values(DOWNLOAD_PAGES), [200, Buffer.from(packLinkHtml(true))]]) {
		const body = html(page);
		assert.doesNotMatch(body, /\/ (Licence key|Download)</, 'anglais sans lang');
		assert.match(body, /Télécharger \/ <span lang="en">Download<\/span><\/button>/);
	}
	assert.match(html(DOWNLOAD_PAGES.form), /<label for="key">Clé de licence \/ <span lang="en">Licence key<\/span><\/label>/);
});

test('C4-1-05 : la page de liens nomme Fluffless Suite en premier, comme le courriel', () => {
	const body = html(DOWNLOAD_PAGES.links);
	assert.doesNotMatch(body, /la première en premier|the first one first/);
	assert.match(body, /<p>Installe Fluffless Suite en premier[\u00a0 ]: les autres extensions en ont besoin\./);
	assert.match(body, /<p lang="en">Install Fluffless Suite first: the other plugins need it\./);
	assert.match(formatLicenceEmail({ locale: 'fr_CA', key: 'FL-1', url: 'https://x', several: true }).text, /Installe Fluffless Suite en premier[\u00a0 ]: les autres extensions en ont besoin\./);
});

test('C4-1-12 : cibles d\'au moins 24 px et champ de clé assez large, style autorisé par son empreinte', () => {
	for (const [state, page] of Object.entries(DOWNLOAD_PAGES)) {
		const body = html(page);
		const csp = page[2]['Content-Security-Policy'];
		const style = /<style>([^<]*)<\/style>/.exec(body);
		assert.ok(style, `${state} : feuille de style en ligne`);
		const hash = createHash('sha256').update(style[1]).digest('base64');
		assert.ok(csp.includes(`style-src 'sha256-${hash}'`), `${state} : CSP ${csp}`);
		assert.match(csp, /default-src 'none'/);
		assert.match(style[1], /li a\{[^}]*display:inline-block[^}]*min-block-size:44px/);
		assert.match(style[1], /button\{[^}]*min-block-size:44px/);
		assert.match(body, /<input id="key"[^>]* size="40"/);
	}
});

test('C4-1-07 : le rappel d\'expiration répond à l\'adresse d\'aide, comme les autres courriels', async () => {
	const sent = [];
	const mailer = createReminderMailer({ user: 'bonjour@exemple.ca', token: 'jeton' }, () => {}, () => ({ sendMail: async (mail) => sent.push(mail) }));
	await mailer({ to: 'c@d.ca', locale: 'fr_CA', daysLeft: 7, expires: new Date(), renewUrl: null });
	assert.match(formatReminderEmail({ locale: 'fr_CA', daysLeft: 7, expires: new Date(), renewUrl: null }).text, /Réponds à ce courriel/);
	assert.equal(sent[0].replyTo, 'genevieve@fluffless.dev');
});

test('C4-1-11 : l\'échéance du lien de pack se lit en date et heure de Montréal, sans ISO ni UTC', () => {
	const links = [{ name: 'Pack Printemps', url: 'https://api.exemple/v1/packs/download/abc' }];
	// 2 h 30 UTC le 3 octobre : encore le 2 octobre, 22 h 30, à Montréal (heure avancée).
	const expires = new Date('2026-10-03T02:30:00Z');
	const fr = formatPackEmail({ locale: 'fr_CA', links, expires }).text;
	const en = formatPackEmail({ locale: 'en_CA', links, expires }).text;
	for (const text of [fr, en]) {
		assert.doesNotMatch(text, /2026-10-0\d|UTC/);
	}
	assert.match(fr, /jusqu'au 2 octobre 2026 à 22\u00a0h\u00a030 \(heure de Montréal\)\./);
	assert.match(en, /until October 2, 2026 at 22:30 \(Montreal time\)\./);
});

// Texte français seulement : les lignes et paragraphes anglais, les adresses web et les balises sont écartés.
function frenchOf(text) {
	return text.split('\n').filter((line) => !/^https?:\/\//.test(line)).join('\n');
}
function frenchOfHtml(body) {
	return body
		.replace(/<style>[^<]*<\/style>/g, '')
		.replace(/<(p|h1|label|button)[^>]*lang="en"[^>]*>[\s\S]*?<\/\1>/g, '')
		.replace(/<span lang="en">[^<]*<\/span>/g, '')
		.replace(/<[^>]+>/g, '');
}
function typoErrors(text) {
	const errors = [];
	text.split('\n').forEach((line) => {
		if (/ [:;?!]/.test(line)) {
			errors.push(`espace ordinaire : ${line}`);
		}
		if (/[^\s\u00a0\u202f][?!;]/.test(line)) {
			errors.push(`signe collé : ${line}`);
		}
		if (/[^\s\u00a0]:(?!\/\/)/.test(line) && !/\d:\d/.test(line)) {
			errors.push(`deux-points collé : ${line}`);
		}
		if (/\u00a0[;?!]/.test(line) || /\u202f:/.test(line)) {
			errors.push(`mauvaise espace : ${line}`);
		}
	});
	return errors;
}

test('C4-1-09 : textes français de l\'API avec U+00A0 avant « : » et U+202F avant « ; ? ! »', () => {
	const expires = new Date('2026-10-03T02:30:00Z');
	const links = [{ name: 'Pack Printemps', url: 'https://api.exemple/v1/packs/download/abc' }];
	const mails = [
		formatPackEmail({ locale: 'fr_CA', links, expires }),
		formatPackEmail({ locale: 'fr_CA', links, expires, purchase: true }),
		formatLicenceEmail({ locale: 'fr_CA', key: 'FL-1', url: 'https://x', several: true, expires, reminders: true }),
		formatLicenceEmail({ locale: 'fr_CA', key: 'FL-1', url: 'https://x' }),
		formatReminderEmail({ locale: 'fr_CA', daysLeft: 7, expires, renewUrl: 'https://x' }),
		formatReminderEmail({ locale: 'fr_CA', daysLeft: 1, expires, renewUrl: null }),
		formatRenewalRefundEmail({ locale: 'fr_CA', expires, graceUntil: expires, renewUrl: 'https://x' }),
		formatReportEmail({ id: '0123456789', type: 'bug', locale: 'fr_CA', reply_email: null, has_screenshot: false, what: 'x', expected: '', diagnostics: null }),
	];
	const errors = mails.flatMap(({ subject, text }) => typoErrors(frenchOf(`${subject}\n${text}`)));
	const pages = [...Object.values(DOWNLOAD_PAGES), ...Object.values(RENEW_PAGES), [200, Buffer.from(packLinkHtml(true))], [200, Buffer.from(packLinkHtml(false))]];
	errors.push(...pages.flatMap((page) => typoErrors(frenchOfHtml(html(page)))));
	assert.deepEqual(errors, []);
});

test('C4-1-09 : aucune espace insécable dans les textes anglais', () => {
	const expires = new Date('2026-10-03T02:30:00Z');
	const links = [{ name: 'Pack', url: 'https://x' }];
	const texts = [
		formatPackEmail({ locale: 'en_CA', links, expires }).text,
		formatLicenceEmail({ locale: 'en_CA', key: 'FL-1', url: 'https://x', several: true, expires, reminders: true }).text,
		formatReminderEmail({ locale: 'en_CA', daysLeft: 7, expires, renewUrl: 'https://x' }).text,
		formatRenewalRefundEmail({ locale: 'en_CA', expires, graceUntil: expires, renewUrl: 'https://x' }).text,
		...[...Object.values(DOWNLOAD_PAGES), ...Object.values(RENEW_PAGES)].flatMap((page) => html(page).match(/lang="en"[^>]*>[^<]*/g) ?? []),
	];
	for (const text of texts) {
		assert.doesNotMatch(text, /[\u00a0\u202f]/, text);
	}
});

test('P6c-01 : un /48 qui change de /64 à chaque requête ne remplit pas la table et ne bloque personne d\'autre', () => {
	const limiter = createIpLimiter({ limit: 2, windowMs: 60_000, maxKeys: 50, now: () => 0 });
	let refused = 0;
	for (let i = 0; i < 200; i++) {
		if (!limiter.hit(`2001:db8:0:${i.toString(16)}::1`).allowed) {
			refused++;
		}
	}
	assert.equal(refused, 200 - 2 * 8, 'le /48 garde son quota de 8 fois la limite');
	assert.equal(limiter.hit('198.51.100.77').allowed, true, 'une IPv4 tierce passe');
	assert.equal(limiter.hit('2001:db8:ffff:1::1').allowed, true, 'un autre /48 passe');
});

test('P6c-01 : un /64 abusif n\'entame pas le quota de ses voisins du même /48', () => {
	const limiter = createIpLimiter({ limit: 2, windowMs: 60_000, now: () => 0 });
	for (let i = 0; i < 100; i++) {
		limiter.hit('2001:db8:0:1::1');
	}
	for (let i = 2; i < 2 + 7; i++) {
		assert.equal(limiter.hit(`2001:db8:0:${i}::1`).allowed, true, `voisin ${i}`);
	}
});
