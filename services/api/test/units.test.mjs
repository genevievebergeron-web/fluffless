import { createServer } from 'node:http';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { generateKeyPairSync, verify } from 'node:crypto';
import { compareVersions, parseVersion } from '../src/versions.mjs';
import { createLockout, createRateLimiter } from '../src/ratelimit.mjs';
import { loadSigningKey, publicKeyRawBase64, safeEqual, signEd25519 } from '../src/crypto.mjs';
import { loadConfig, startupWarnings } from '../src/config.mjs';
import { createAlertMailer, createLicenceMailer, createMailer, createPackMailer, createReminderMailer, createRenewalRefundMailer, formatLicenceEmail, formatPackEmail, formatReminderEmail, formatRenewalRefundEmail, formatReportEmail } from '../src/mail.mjs';
import { ipBucket, maskEmail } from '../src/app.mjs';

test('versions : ordre semver, préversions et versions à deux chiffres', () => {
	assert.equal(compareVersions('0.0.1-test', '0.0.1'), -1);
	assert.equal(compareVersions('1.2', '1.2.0'), 0);
	assert.equal(compareVersions('1.10.0', '1.9.9'), 1);
	assert.equal(compareVersions('1.0.0-beta.2', '1.0.0-beta.10'), -1);
	assert.equal(compareVersions('1.0.0-alpha', '1.0.0-alpha.1'), -1);
	assert.equal(parseVersion('1.0.0; drop table'), null);
	assert.throws(() => compareVersions('abc', '1.0.0'), /Version invalide : abc/);
});

test('limiteur : 10 passages puis refus, remise à zéro après la fenêtre', () => {
	let clock = 0;
	const limiter = createRateLimiter({ limit: 10, windowMs: 1000, now: () => clock });
	for (let i = 0; i < 10; i++) {
		assert.equal(limiter.hit('a').allowed, true);
	}
	assert.equal(limiter.hit('a').allowed, false);
	assert.equal(limiter.hit('b').allowed, true);
	clock = 1000;
	assert.equal(limiter.hit('a').allowed, true);
});

test('limiteur : la table reste bornée et refuse quand elle est pleine', () => {
	const limiter = createRateLimiter({ limit: 1, windowMs: 1000, maxKeys: 2, now: () => 0 });
	limiter.hit('a');
	limiter.hit('b');
	assert.equal(limiter.hit('c').allowed, false);
	assert.equal(limiter.size, 2);
});

test('signature ed25519 : vérifiable avec la clé publique brute', () => {
	const { privateKey } = generateKeyPairSync('ed25519');
	const key = loadSigningKey(privateKey.export({ format: 'der', type: 'pkcs8' }).toString('base64'));
	const data = Buffer.from('zip');
	const signature = Buffer.from(signEd25519(key, data), 'base64');
	assert.equal(signature.length, 64);
	const raw = Buffer.from(publicKeyRawBase64(key), 'base64');
	assert.equal(raw.length, 32);
	const spki = Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), raw]);
	assert.equal(verify(null, data, { key: spki, format: 'der', type: 'spki' }, signature), true);
});

test('clé de signature : une clé RSA ou illisible est refusée avec le format attendu', () => {
	const { privateKey } = generateKeyPairSync('rsa', { modulusLength: 1024 });
	assert.throws(() => loadSigningKey(privateKey.export({ format: 'der', type: 'pkcs8' }).toString('base64')), /ed25519 PKCS8 DER/);
	assert.throws(() => loadSigningKey('pas-une-cle'), /SIGNING_PRIVATE_KEY illisible/);
});

test('safeEqual : égalité exacte seulement', () => {
	assert.equal(safeEqual('abc', 'abc'), true);
	assert.equal(safeEqual('abc', 'abcd'), false);
});

test('config : nomme les variables manquantes et refuse un secret court', () => {
	assert.throws(() => loadConfig({}), /DATABASE_URL, PUBLIC_URL, SIGNING_PRIVATE_KEY, ADMIN_TOKEN, URL_SECRET, HASH_PEPPER/);
	const { privateKey } = generateKeyPairSync('ed25519');
	const env = {
		DATABASE_URL: 'postgres://x/y',
		PUBLIC_URL: 'https://api.exemple.ca/',
		SIGNING_PRIVATE_KEY: privateKey.export({ format: 'der', type: 'pkcs8' }).toString('base64'),
		ADMIN_TOKEN: 'court',
		URL_SECRET: 'x'.repeat(32),
		HASH_PEPPER: 'x'.repeat(32),
	};
	assert.throws(() => loadConfig(env), /ADMIN_TOKEN fait 5 caractères/);
	const config = loadConfig({ ...env, ADMIN_TOKEN: 'y'.repeat(32) });
	assert.equal(config.publicUrl, 'https://api.exemple.ca');
	assert.equal(config.smtp.to, 'bonjour@genevievebergeron.ca');
});

test('COH6-12 : Stripe branché et rappels éteints, le démarrage le signale', () => {
	assert.deepEqual(startupWarnings({ stripe: { secretKey: 'sk_test_x' }, remindersEnabled: false }), ['rappels_eteints']);
	assert.deepEqual(startupWarnings({ stripe: { secretKey: 'sk_test_x' }, remindersEnabled: true }), []);
	assert.deepEqual(startupWarnings({ stripe: { secretKey: '' }, remindersEnabled: false }), [], 'vente fermée : rien à rappeler');
});

test('courriel : sans jeton Proton, rien ne part et l\'absence est journalisée', async () => {
	const logs = [];
	const notify = createMailer({ user: '', token: '', to: 'x@y.ca' }, (level, event) => logs.push(event), () => {
		throw new Error('le transport ne doit pas être créé');
	});
	assert.equal(await notify({ id: 'abc' }), false);
	assert.deepEqual(logs, ['notification_absente']);
});

test('courriel : avec jeton, soumission Proton 587 STARTTLS et réponse à l\'auteur', async () => {
	let options;
	const sent = [];
	const notify = createMailer({ user: 'fluffless@exemple.ca', token: 'jeton', to: 'bonjour@genevievebergeron.ca' }, () => {}, (opts) => {
		options = opts;
		return { sendMail: async (mail) => sent.push(mail) };
	});
	const report = { id: '12345678-aaaa', type: 'bug', what: 'Le bloc casse', expected: '', locale: 'fr_CA', reply_email: 'a@b.ca', diagnostics: null, has_screenshot: false };
	assert.equal(await notify(report), true);
	assert.equal(options.host, 'smtp.protonmail.ch');
	assert.equal(options.port, 587);
	assert.equal(options.requireTLS, true);
	assert.equal(sent[0].replyTo, 'a@b.ca');
	assert.match(sent[0].subject, /\[Fluffless\] Bogue 12345678/);
	assert.match(formatReportEmail(report).text, /Le bloc casse/);
});

test('courriel : une erreur SMTP remonte avec son message', async () => {
	const notify = createMailer({ user: 'u@x.ca', token: 't', to: 'x@y.ca' }, () => {}, () => ({
		sendMail: async () => { throw new Error('535 authentication failed'); },
	}));
	await assert.rejects(notify({ id: 'x', type: 'idea', what: 'a', expected: '', locale: 'en_CA' }), /535/);
});

test('S2G4-v6-01 : le courriel de licence reprend la formule de la page Merci sur les mises à jour', () => {
	const fr = formatLicenceEmail({ locale: 'fr_CA', key: 'k', url: 'https://x.ca' }).text;
	const en = formatLicenceEmail({ locale: 'en_CA', key: 'k', url: 'https://x.ca' }).text;
	assert.match(fr, /les mises à jour suivantes s'affichent dans WordPress, comme pour toute extension\./);
	assert.match(en, /later updates show up in WordPress like any other plugin's\./);
	assert.doesNotMatch(fr, /toutes seules/);
	assert.doesNotMatch(en, /on their own/);
});

test('verrou progressif : 5 essais, puis verrou de 15 min qui double à chaque récidive', () => {
	let clock = 0;
	const min = 60_000;
	const lock = createLockout({ limit: 5, windowMs: 15 * min, maxLockMs: 60 * min, now: () => clock });
	for (let i = 0; i < 5; i++) {
		assert.equal(lock.hit('k').allowed, true);
	}
	assert.deepEqual(lock.hit('k'), { allowed: false, retryAfterSeconds: 15 * 60 });
	assert.equal(lock.hit('autre').allowed, true, 'une autre valeur garde son propre compteur');
	clock = 15 * min - 1;
	assert.equal(lock.hit('k').allowed, false, 'encore verrouillé');
	clock = 15 * min;
	for (let i = 0; i < 5; i++) {
		assert.equal(lock.hit('k').allowed, true);
	}
	assert.deepEqual(lock.hit('k'), { allowed: false, retryAfterSeconds: 30 * 60 }, 'deuxième dépassement : 30 min');
	clock += 30 * min;
	for (let i = 0; i < 6; i++) {
		lock.hit('k');
	}
	clock += 1;
	for (let i = 0; i < 6; i++) {
		lock.hit('k');
	}
	assert.equal(lock.hit('k').retryAfterSeconds <= 60 * 60, true, 'plafonné à maxLockMs');
	clock += 3 * 60 * min;
	for (let i = 0; i < 5; i++) {
		assert.equal(lock.hit('k').allowed, true);
	}
	assert.equal(lock.hit('k').retryAfterSeconds, 15 * 60, 'les récidives s\'oublient après maxLockMs sans écart');
});

test('verrou progressif : la table reste bornée', () => {
	const lock = createLockout({ limit: 1, windowMs: 1000, maxLockMs: 1000, maxKeys: 2, now: () => 0 });
	lock.hit('a');
	lock.hit('b');
	assert.equal(lock.hit('c').allowed, false);
	assert.equal(lock.size, 2);
});

test('courriel de pack : tutoiement en français, anglais, liens et échéance, sans tiret cadratin', () => {
	const links = [{ name: 'Pack Printemps', url: 'https://api.exemple/v1/packs/download/abc' }];
	const fr = formatPackEmail({ locale: 'fr_CA', links, expires: new Date('2026-10-02T12:00:00Z') });
	assert.match(fr.subject, /pack/i);
	assert.match(fr.text, /Pack Printemps/);
	assert.match(fr.text, /https:\/\/api\.exemple\/v1\/packs\/download\/abc/);
	assert.match(fr.text, /une seule fois/);
	assert.match(fr.text, /jusqu.au 2 octobre 2026 à 8\u00a0h\u00a000 \(heure de Montréal\)/);
	assert.doesNotMatch(fr.text + fr.subject, /\u2014/);
	const en = formatPackEmail({ locale: 'en_CA', links, expires: new Date('2026-10-02T12:00:00Z') });
	assert.match(en.text, /only once/);
	assert.doesNotMatch(en.text + en.subject, /\u2014/);
});

test('courriel de licence : clé, page de téléchargement, installation, sans tiret cadratin', () => {
	const key = 'FL-0123ABCD-0123ABCD-0123ABCD-0123ABCD';
	const url = 'https://api.exemple/v1/licences/download';
	const fr = formatLicenceEmail({ locale: 'fr_CA', key, url });
	assert.equal(fr.subject, 'Ta licence Fluffless');
	assert.match(fr.text, new RegExp(key));
	assert.match(fr.text, /colle ta clé sur cette page/);
	assert.ok(fr.text.includes(url));
	assert.match(fr.text, /Téléverser/);
	assert.doesNotMatch(fr.text + fr.subject, /\u2014/);
	const en = formatLicenceEmail({ locale: 'en_CA', key, url });
	assert.equal(en.subject, 'Your Fluffless licence');
	assert.match(en.text, /paste your key on this page/);
	assert.doesNotMatch(en.text + en.subject, /\u2014/);
	assert.doesNotMatch(fr.text, /en premier/);
	// Plusieurs extensions : un lien pour chacune, la Suite en premier.
	const several = formatLicenceEmail({ locale: 'fr_CA', key, url, several: true });
	assert.match(several.text, /un lien pour chacune/);
	assert.match(several.text, /Installe Fluffless Suite en premier/);
	assert.doesNotMatch(several.text, /\u2014/);
	const severalEn = formatLicenceEmail({ locale: 'en_CA', key, url, several: true });
	assert.match(severalEn.text, /a link for each one/);
	assert.match(severalEn.text, /Install Fluffless Suite first/);
});

test('courriel de licence : absent sans jeton Proton, envoyé sinon', async () => {
	const logs = [];
	const absent = createLicenceMailer({ user: '', token: '' }, (level, event) => logs.push(event), () => {
		throw new Error('le transport ne doit pas être créé');
	});
	assert.equal(await absent({ to: 'c@d.ca', locale: 'fr_CA', key: 'FL-X', url: 'https://x' }), false);
	assert.deepEqual(logs, ['livraison_absente']);
	const sent = [];
	const mailer = createLicenceMailer({ user: 'bonjour@exemple.ca', token: 'jeton' }, () => {}, () => ({ sendMail: async (mail) => sent.push(mail) }));
	assert.equal(await mailer({ to: 'c@d.ca', locale: 'en_CA', key: 'FL-X', url: 'https://x' }), true);
	assert.equal(sent[0].to, 'c@d.ca');
	assert.equal(sent[0].subject, 'Your Fluffless licence');
});

test('courriel de pack : envoyé à l\'adresse de la licence, absent sans jeton Proton', async () => {
	const logs = [];
	const absent = createPackMailer({ user: '', token: '' }, (level, event) => logs.push(event), () => {
		throw new Error('le transport ne doit pas être créé');
	});
	assert.equal(await absent({ to: 'c@d.ca', locale: 'fr_CA', links: [], expires: new Date() }), false);
	assert.deepEqual(logs, ['livraison_absente']);
	const sent = [];
	const deliver = createPackMailer({ user: 'fluffless@exemple.ca', token: 't' }, () => {}, () => ({ sendMail: async (mail) => sent.push(mail) }));
	assert.equal(await deliver({ to: 'c@d.ca', locale: 'en_CA', links: [{ name: 'P', url: 'https://x' }], expires: new Date() }), true);
	assert.equal(sent[0].to, 'c@d.ca');
	assert.equal(sent[0].from, 'Fluffless <fluffless@exemple.ca>');
});

test('compteur IP : IPv4 telle quelle, IPv6 regroupée par /64', () => {
	assert.equal(ipBucket('203.0.113.7'), '203.0.113.7');
	assert.equal(ipBucket('2001:db8:1:2::1'), '2001:db8:1:2::/64');
	assert.equal(ipBucket('2001:0DB8:0001:0002:ffff:0:0:1'), '2001:db8:1:2::/64');
	assert.equal(ipBucket('::1'), '0:0:0:0::/64');
	assert.equal(ipBucket('2001:db8::'), '2001:db8:0:0::/64');
	assert.equal(ipBucket('::ffff:203.0.113.7'), '203.0.113.7', 'IPv4 mappée : chacune son compteur');
	assert.equal(ipBucket('::FFFF:198.51.100.1'), '198.51.100.1');
	assert.equal(ipBucket('2001:db8:1:2::1', 3), '2001:db8:1::/48');
});

test('signature Stripe : HMAC du horodatage et du corps brut, 5 minutes de tolérance', async () => {
	const { createHmac } = await import('node:crypto');
	const { verifyStripeSignature } = await import('../src/stripe.mjs');
	const secret = 'whsec_unitunitunitunit';
	const body = Buffer.from('{"id":"evt_1"}');
	const at = 1_800_000_000;
	const mac = (ts, s = secret, b = body) => createHmac('sha256', s).update(`${ts}.`).update(b).digest('hex');
	assert.equal(verifyStripeSignature(body, `t=${at},v1=${mac(at)}`, secret, at + 10), true);
	assert.equal(verifyStripeSignature(body, `t=${at},v1=${'0'.repeat(64)},v1=${mac(at)}`, secret, at), true, 'plusieurs v1 pendant une rotation');
	assert.equal(verifyStripeSignature(body, `t=${at},v1=${mac(at)}`, secret, at + 301), false);
	assert.equal(verifyStripeSignature(body, `t=${at},v1=${mac(at, 'whsec_autreautreautre')}`, secret, at), false);
	assert.equal(verifyStripeSignature(Buffer.from('{"id":"evt_2"}'), `t=${at},v1=${mac(at)}`, secret, at), false);
	assert.equal(verifyStripeSignature(body, `t=${at},v0=${mac(at)}`, secret, at), false, 'v0 (test) ignoré');
	assert.equal(verifyStripeSignature(body, undefined, secret, at), false);
	assert.equal(verifyStripeSignature(body, `t=${at},v1=${mac(at)}`, '', at), false);
});

test('encodage de formulaire Stripe : objets et listes imbriqués, valeurs nulles omises', async () => {
	const { formEncode } = await import('../src/stripe.mjs');
	assert.equal(
		formEncode({ mode: 'payment', line_items: [{ price: 'price_1', quantity: 1 }], metadata: { a: 'é&b' }, skip: null, created: { gte: 5 } }),
		'mode=payment&line_items%5B0%5D%5Bprice%5D=price_1&line_items%5B0%5D%5Bquantity%5D=1&metadata%5Ba%5D=%C3%A9%26b&created%5Bgte%5D=5',
	);
});

test('client Stripe : jeton Bearer, version épinglée, clé d\'idempotence, erreur réduite à son code', async () => {
	const { createStripeClient } = await import('../src/stripe.mjs');
	const calls = [];
	const client = createStripeClient({
		secretKey: 'sk_test_x',
		apiVersion: '2025-09-30.clover',
		fetchImpl: async (url, init) => {
			calls.push({ url, init });
			return url.includes('/refunds')
				? new Response(JSON.stringify({ error: { code: 'charge_already_refunded', message: 'a@b.ca' } }), { status: 400 })
				: new Response(JSON.stringify({ id: 'price_1' }), { status: 200 });
		},
	});
	assert.deepEqual(await client.retrievePrice('price_1', { expand: ['product'] }), { id: 'price_1' });
	assert.match(calls[0].url, /\/v1\/prices\/price_1\?expand%5B0%5D=product$/);
	assert.equal(calls[0].init.headers.Authorization, 'Bearer sk_test_x');
	await assert.rejects(client.createRefund({ payment_intent: 'pi_1' }, 'refund-cs_1'), (error) => error.code === 'charge_already_refunded' && !error.message.includes('@'));
	assert.equal(calls[1].init.headers['Idempotency-Key'], 'refund-cs_1');
	assert.equal(calls[1].init.body, 'payment_intent=pi_1');
	assert.equal(calls[0].init.headers['Stripe-Version'], '2025-09-30.clover');
	await assert.rejects(client.listRefunds({ payment_intent: 'pi_1', limit: 1 }));
	assert.match(calls[2].url, /\/v1\/refunds\?payment_intent=pi_1&limit=1$/);
	assert.equal(calls[2].init.method, 'GET');
});

test('montants des alertes : décimales selon la devise', async () => {
	const { formatAmount } = await import('../src/app.mjs');
	assert.equal(formatAmount(2185, 'cad'), '21.85 CAD');
	assert.equal(formatAmount(2800, 'jpy'), '2800 JPY');
	assert.equal(formatAmount(12345, 'kwd'), '12.345 KWD');
});

test('config Stripe : tout ou rien, formats vérifiés', async () => {
	const { loadConfig } = await import('../src/config.mjs');
	const { generateKeyPairSync } = await import('node:crypto');
	const base = {
		DATABASE_URL: 'postgres://x', PUBLIC_URL: 'https://api.exemple.ca',
		SIGNING_PRIVATE_KEY: generateKeyPairSync('ed25519').privateKey.export({ format: 'der', type: 'pkcs8' }).toString('base64'),
		ADMIN_TOKEN: 'a'.repeat(32), URL_SECRET: 'b'.repeat(32), HASH_PEPPER: 'c'.repeat(32),
	};
	const full = { STRIPE_SECRET_KEY: 'sk_test_abcdefghijk', STRIPE_WEBHOOK_SECRET: 'whsec_abcdefghijk', STRIPE_SUCCESS_URL: 'https://f.dev/merci', STRIPE_CANCEL_URL: 'https://f.dev/packs' };
	assert.equal(loadConfig(base).stripe.secretKey, '');
	assert.equal(loadConfig({ ...base, ...full }).stripe.webhookSecret, 'whsec_abcdefghijk');
	assert.throws(() => loadConfig({ ...base, STRIPE_SECRET_KEY: full.STRIPE_SECRET_KEY }), /STRIPE_WEBHOOK_SECRET/);
	assert.throws(() => loadConfig({ ...base, ...full, STRIPE_SECRET_KEY: 'pk_test_abcdefghijk' }), /sk_/);
	assert.throws(() => loadConfig({ ...base, ...full, STRIPE_CANCEL_URL: 'pas une url' }), /STRIPE_CANCEL_URL/);
	// Pages anglaises : facultatives, validées si présentes.
	assert.equal(loadConfig({ ...base, ...full }).stripe.successUrlEn, '');
	assert.equal(loadConfig({ ...base, ...full, STRIPE_SUCCESS_URL_EN: 'https://f.dev/en/thank-you/' }).stripe.successUrlEn, 'https://f.dev/en/thank-you/');
	assert.throws(() => loadConfig({ ...base, ...full, STRIPE_CANCEL_URL_EN: 'pas une url' }), /STRIPE_CANCEL_URL_EN/);
	assert.equal(loadConfig({ ...base, ...full }).stripe.apiVersion, '');
	assert.equal(loadConfig({ ...base, ...full, STRIPE_API_VERSION: '2025-09-30.clover' }).stripe.apiVersion, '2025-09-30.clover');
	assert.throws(() => loadConfig({ ...base, ...full, STRIPE_API_VERSION: 'latest' }), /STRIPE_API_VERSION/);
	// Managed Payments : désactivé par défaut, 0 ou 1 seulement, version basil ou plus récente.
	assert.equal(loadConfig({ ...base, ...full }).stripe.managedPayments, false);
	assert.equal(loadConfig({ ...base, ...full, STRIPE_MANAGED_PAYMENTS: '0' }).stripe.managedPayments, false);
	assert.throws(() => loadConfig({ ...base, ...full, STRIPE_MANAGED_PAYMENTS: '1' }), /STRIPE_API_VERSION/, 'version épinglée exigée');
	assert.throws(() => loadConfig({ ...base, ...full, STRIPE_MANAGED_PAYMENTS: 'oui' }), /STRIPE_MANAGED_PAYMENTS/);
	assert.throws(() => loadConfig({ ...base, STRIPE_MANAGED_PAYMENTS: '1' }), /STRIPE_MANAGED_PAYMENTS/);
	assert.throws(() => loadConfig({ ...base, ...full, STRIPE_MANAGED_PAYMENTS: '1', STRIPE_API_VERSION: '2025-02-24.acacia' }), /2025-03-31/);
	assert.equal(loadConfig({ ...base, ...full, STRIPE_MANAGED_PAYMENTS: '1', STRIPE_API_VERSION: '2025-03-31.basil' }).stripe.managedPayments, true);
});

test('adresse masquée : première et dernière lettre, domaine entier, trois étoiles', () => {
	assert.equal(maskEmail('genevieve@gmial.com'), 'g***e@gmial.com');
	assert.equal(maskEmail('ab@exemple.ca'), 'a***@exemple.ca');
	assert.equal(maskEmail('a@exemple.ca'), 'a***@exemple.ca');
	assert.equal(maskEmail('"a@b"@exemple.ca'), '"***"@exemple.ca');
	// Un emoji en tête reste entier : JSON lisible côté site.
	assert.equal(maskEmail('😀abc@exemple.ca'), '😀***c@exemple.ca');
	assert.doesNotThrow(() => JSON.parse(JSON.stringify(maskEmail('😀😀@x.ca'))));
	assert.equal(maskEmail('😀😀@x.ca'), '😀***@x.ca');
});

test('alerte de paiement qui ne part pas : événement structuré au niveau error, avec de quoi retrouver l\'achat', async () => {
	const lines = ['Licence : 42', 'Session Checkout : cs_test_1'];
	const logs = [];
	const log = (level, event, fields) => logs.push({ level, event, ...fields });
	const absent = createAlertMailer({ user: '', token: '', to: 'a@b.ca' }, log, () => {
		throw new Error('le transport ne doit pas être créé');
	});
	assert.equal(await absent({ subject: 'Achat payé, courriel de livraison non parti', lines }), false);
	const down = createAlertMailer({ user: 'f@exemple.ca', token: 't', to: 'a@b.ca' }, log, () => ({
		sendMail: async () => {
			throw Object.assign(new Error('refus'), { responseCode: 421 });
		},
	}));
	assert.equal(await down({ subject: 'Licence payée, courriel de la clé non parti', lines }), false);
	assert.equal(logs.length, 2);
	for (const entry of logs) {
		assert.equal(entry.level, 'error');
		assert.equal(entry.event, 'alerte_non_livree');
		assert.deepEqual(entry.lines, lines);
		assert.ok(entry.subject && entry.cause);
	}
	assert.match(logs[0].cause, /PROTON_SMTP/);
	assert.match(logs[1].cause, /421/);
	// Une alerte livrée ne produit pas cet événement.
	const sent = [];
	const ok = createAlertMailer({ user: 'f@exemple.ca', token: 't', to: 'a@b.ca' }, log, () => ({ sendMail: async (mail) => sent.push(mail) }));
	assert.equal(await ok({ subject: 'S', lines }), true);
	assert.equal(logs.filter((l) => l.event === 'alerte_non_livree').length, 2);
});

test('config : ALERT_WEBHOOK_URL vide par défaut, https exigé (http seulement en boucle locale), jamais recopiée dans l\'erreur', () => {
	const { privateKey } = generateKeyPairSync('ed25519');
	const env = {
		DATABASE_URL: 'postgres://x/y',
		PUBLIC_URL: 'https://api.exemple.ca/',
		SIGNING_PRIVATE_KEY: privateKey.export({ format: 'der', type: 'pkcs8' }).toString('base64'),
		ADMIN_TOKEN: 'y'.repeat(32),
		URL_SECRET: 'x'.repeat(32),
		HASH_PEPPER: 'x'.repeat(32),
	};
	assert.equal(loadConfig(env).alertWebhookUrl, '');
	assert.equal(loadConfig({ ...env, ALERT_WEBHOOK_URL: 'https://hooks.exemple.ca/v1/abc' }).alertWebhookUrl, 'https://hooks.exemple.ca/v1/abc');
	assert.equal(loadConfig({ ...env, ALERT_WEBHOOK_URL: 'http://127.0.0.1:9999/essai' }).alertWebhookUrl, 'http://127.0.0.1:9999/essai');
	for (const bad of ['http://hooks.exemple.ca/jeton-secret-123', 'pas une url jeton-secret-123', 'ftp://hooks.exemple.ca/jeton-secret-123']) {
		assert.throws(() => loadConfig({ ...env, ALERT_WEBHOOK_URL: bad }), (error) => /ALERT_WEBHOOK_URL/.test(error.message) && !error.message.includes('jeton-secret-123'));
	}
});

test('canal d\'alerte : webhook d\'abord, courriel en secours, trace au journal si le webhook échoue', async () => {
	const { createAlertChannel } = await import('../src/mail.mjs');
	const logs = [];
	const log = (level, event, fields) => logs.push({ level, event, ...fields });
	const message = { subject: 'Paiement échoué', lines: ['Session Checkout : cs_test_1', 'Montant : 79.34 CAD'] };
	const mailed = [];
	const mailer = async (alert) => { mailed.push(alert); return true; };
	// Sans webhook : le courriel, comme avant.
	assert.equal(await createAlertChannel({ webhookUrl: '', mailer, log })(message), true);
	assert.equal(mailed.length, 1);
	// Webhook qui répond : un POST JSON, pas de courriel.
	const calls = [];
	const fetchOk = async (url, init) => { calls.push({ url, init }); return { ok: true, status: 200 }; };
	assert.equal(await createAlertChannel({ webhookUrl: 'https://hooks.exemple.ca/jeton', mailer, log, fetchImpl: fetchOk })(message), true);
	assert.equal(mailed.length, 1);
	assert.equal(calls.length, 1);
	assert.equal(calls[0].url, 'https://hooks.exemple.ca/jeton');
	assert.equal(calls[0].init.method, 'POST');
	assert.equal(calls[0].init.headers['Content-Type'], 'application/json');
	assert.equal(calls[0].init.redirect, 'error');
	assert.deepEqual(JSON.parse(calls[0].init.body), { subject: '[Fluffless] Paiement échoué', lines: message.lines, text: '[Fluffless] Paiement échoué\nSession Checkout : cs_test_1\nMontant : 79.34 CAD' });
	assert.deepEqual(logs.map((l) => [l.level, l.event]), [['info', 'alerte_webhook_envoyee']]);
	// Webhook en panne (500, puis réseau) : trace au journal sans l'adresse, puis courriel.
	logs.length = 0;
	const fetch500 = async () => ({ ok: false, status: 500 });
	assert.equal(await createAlertChannel({ webhookUrl: 'https://hooks.exemple.ca/jeton', mailer, log, fetchImpl: fetch500 })(message), true);
	const fetchDown = async () => { throw Object.assign(new Error('connexion refusée https://hooks.exemple.ca/jeton'), { code: 'ECONNREFUSED' }); };
	assert.equal(await createAlertChannel({ webhookUrl: 'https://hooks.exemple.ca/jeton', mailer, log, fetchImpl: fetchDown })(message), true);
	assert.equal(mailed.length, 3);
	assert.deepEqual(logs.map((l) => [l.level, l.event, l.cause]), [['error', 'alerte_webhook_echec', 'http 500'], ['error', 'alerte_webhook_echec', 'ECONNREFUSED']]);
	assert.ok(!JSON.stringify(logs).includes('jeton'));
});

test('canal d\'alerte : POST réel vers une boucle locale', async () => {
	const { createAlertChannel } = await import('../src/mail.mjs');
	const received = [];
	const server = createServer((req, res) => {
		let body = '';
		req.on('data', (chunk) => { body += chunk; });
		req.on('end', () => { received.push({ method: req.method, type: req.headers['content-type'], body }); res.end('ok'); });
	});
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
	try {
		const url = `http://127.0.0.1:${server.address().port}/alerte`;
		const channel = createAlertChannel({ webhookUrl: url, mailer: async () => { throw new Error('pas de courriel'); }, log: () => {} });
		assert.equal(await channel({ subject: 'Essai', lines: ['a', 'b'] }), true);
		assert.equal(received.length, 1);
		assert.equal(received[0].method, 'POST');
		assert.match(received[0].type, /application\/json/);
		assert.equal(JSON.parse(received[0].body).text, '[Fluffless] Essai\na\nb');
	} finally {
		await new Promise((resolve) => server.close(resolve));
	}
});

test('courriel de licence : première semaine utile, adresse d\'aide, date de fin, aucune adresse web inventée', () => {
	const key = 'FL-0123ABCD-0123ABCD-0123ABCD-0123ABCD';
	const url = 'https://api.exemple/v1/licences/download';
	const expires = new Date('2027-10-12T12:00:00Z');
	const fr = formatLicenceEmail({ locale: 'fr_CA', key, url, expires, reminders: true });
	assert.match(fr.text, /Ta première semaine/);
	assert.match(fr.text, /genevieve@fluffless\.dev/);
	assert.match(fr.text, /valable jusqu'au 12 octobre 2027/);
	assert.match(fr.text, /rappel/i);
	assert.deepEqual(fr.text.match(/https?:\/\/\S+/g), [url]);
	assert.doesNotMatch(fr.text, /bonjour@genevievebergeron/);
	const en = formatLicenceEmail({ locale: 'en_CA', key, url, expires });
	assert.match(en.text, /Your first week/);
	assert.match(en.text, /genevieve@fluffless\.dev/);
	assert.match(en.text, /valid until October 12, 2027/);
	assert.deepEqual(en.text.match(/https?:\/\/\S+/g), [url]);
	assert.doesNotMatch(formatLicenceEmail({ locale: 'fr_CA', key, url, expires }).text, /rappel/i, 'pas de promesse tant que les rappels sont éteints');
	// Une fin à 22 h à Montréal reste le même jour (UTC dirait le lendemain).
	assert.match(formatLicenceEmail({ locale: 'fr_CA', key, url, expires: new Date('2027-10-13T02:00:00Z') }).text, /12 octobre 2027/);
	assert.doesNotMatch(fr.text + en.text, /\u2014/);
	// Sans date de fin (licence sans durée) : aucune phrase de validité.
	assert.doesNotMatch(formatLicenceEmail({ locale: 'fr_CA', key, url }).text, /valable jusqu'au/);
});

test('rappel d\'expiration : jours restants, date de fin, lien qui garde la clé, sans tiret cadratin', () => {
	const expires = new Date('2027-10-12T12:00:00Z');
	const renewUrl = 'https://api.exemple/v1/licences/renew?licence=4&until=1&token=ab';
	const fr = formatReminderEmail({ locale: 'fr_CA', daysLeft: 30, expires, renewUrl });
	assert.match(fr.subject, /30 jours/);
	assert.match(fr.text, /12 octobre 2027/);
	assert.ok(fr.text.includes(renewUrl));
	assert.match(fr.text, /ta clé reste la même/);
	assert.match(fr.text, /Fluffless/);
	const frWeek = formatReminderEmail({ locale: 'fr_CA', daysLeft: 7, expires, renewUrl });
	assert.match(frWeek.subject, /7 jours/);
	assert.match(formatReminderEmail({ locale: 'fr_CA', daysLeft: 1, expires, renewUrl }).subject, /dans 1 jour$/);
	const en = formatReminderEmail({ locale: 'en_CA', daysLeft: 7, expires, renewUrl });
	assert.match(en.subject, /7 days/);
	assert.match(en.text, /your key stays the same/);
	// Sans lien (licence sans offre d'origine) : une adresse pour écrire, aucun lien inventé.
	const plain = formatReminderEmail({ locale: 'fr_CA', daysLeft: 7, expires, renewUrl: null });
	assert.match(plain.text, /genevieve@fluffless\.dev/);
	assert.doesNotMatch(plain.text, /https?:/);
	assert.doesNotMatch(fr.text + fr.subject + frWeek.text + en.text + plain.text, /\u2014/);
});

test('rappel d\'expiration : absent sans jeton Proton, envoyé sinon', async () => {
	const logs = [];
	const absent = createReminderMailer({ user: '', token: '' }, (level, event) => logs.push(event), () => {
		throw new Error('le transport ne doit pas être créé');
	});
	assert.equal(await absent({ to: 'c@d.ca', locale: 'fr_CA', daysLeft: 7, expires: new Date(), renewUrl: null }), false);
	assert.deepEqual(logs, ['rappel_absent']);
	const sent = [];
	const mailer = createReminderMailer({ user: 'bonjour@exemple.ca', token: 'jeton' }, () => {}, () => ({ sendMail: async (mail) => sent.push(mail) }));
	assert.equal(await mailer({ to: 'c@d.ca', locale: 'en_CA', daysLeft: 30, expires: new Date(), renewUrl: null }), true);
	assert.equal(sent[0].to, 'c@d.ca');
	assert.match(sent[0].subject, /30 days/);
});

test('renouvellement remboursé : nouvelle fin, nouveau lien, date limite du prix, sans tiret cadratin', () => {
	const expires = new Date('2027-10-12T12:00:00Z');
	const graceUntil = new Date('2027-11-11T12:00:00Z');
	const renewUrl = 'https://api.exemple/v1/licences/renew?licence=4&until=1&token=ab';
	const fr = formatRenewalRefundEmail({ locale: 'fr_CA', expires, graceUntil, renewUrl });
	assert.match(fr.subject, /rembours/);
	assert.match(fr.text, /12 octobre 2027/);
	assert.match(fr.text, /11 novembre 2027/);
	assert.ok(fr.text.includes(renewUrl));
	assert.match(fr.text, /ne fonctionne plus/);
	assert.match(fr.text, /ta clé reste la même/);
	const en = formatRenewalRefundEmail({ locale: 'en_CA', expires, graceUntil, renewUrl });
	assert.match(en.subject, /refund/i);
	assert.match(en.text, /October 12, 2027/);
	assert.match(en.text, /November 11, 2027/);
	assert.match(en.text, /no longer works/);
	assert.ok(en.text.includes(renewUrl));
	assert.doesNotMatch(fr.text + fr.subject + en.text + en.subject, /[\u2013\u2014]/);
});

test('renouvellement remboursé : absent sans jeton Proton, envoyé sinon', async () => {
	const logs = [];
	const absent = createRenewalRefundMailer({ user: '', token: '' }, (level, event) => logs.push(event), () => {
		throw new Error('le transport ne doit pas être créé');
	});
	const mail = { to: 'c@d.ca', locale: 'fr_CA', expires: new Date(), graceUntil: new Date(), renewUrl: 'https://x/renew' };
	assert.equal(await absent(mail), false);
	assert.deepEqual(logs, ['renouvellement_defait_courriel_absent']);
	const sent = [];
	const mailer = createRenewalRefundMailer({ user: 'bonjour@exemple.ca', token: 'jeton' }, () => {}, () => ({ sendMail: async (m) => sent.push(m) }));
	assert.equal(await mailer({ ...mail, locale: 'en_CA' }), true);
	assert.equal(sent[0].to, 'c@d.ca');
	assert.equal(sent[0].replyTo, 'genevieve@fluffless.dev');
	assert.ok(sent[0].text.includes('https://x/renew'));
});

test('config : REMINDERS_ENABLED est faux par défaut et n\'accepte que 0 ou 1', () => {
	const { privateKey } = generateKeyPairSync('ed25519');
	const env = {
		DATABASE_URL: 'postgres://x/y',
		PUBLIC_URL: 'https://api.exemple.ca/',
		SIGNING_PRIVATE_KEY: privateKey.export({ format: 'der', type: 'pkcs8' }).toString('base64'),
		ADMIN_TOKEN: 'y'.repeat(32),
		URL_SECRET: 'x'.repeat(32),
		HASH_PEPPER: 'x'.repeat(32),
	};
	assert.equal(loadConfig(env).remindersEnabled, false);
	assert.equal(loadConfig({ ...env, REMINDERS_ENABLED: '0' }).remindersEnabled, false);
	assert.equal(loadConfig({ ...env, REMINDERS_ENABLED: '1' }).remindersEnabled, true);
	assert.throws(() => loadConfig({ ...env, REMINDERS_ENABLED: 'oui' }), /REMINDERS_ENABLED vaut 0 ou 1/);
});
