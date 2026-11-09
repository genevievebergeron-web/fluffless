// Monte une base Postgres jetable et le service sur un port libre.
// Sans Postgres local, les tests de contrat échouent au lieu de se déclarer verts;
// API_TEST_SKIP_DB=1 les saute explicitement.
import { createServer } from 'node:http';
import { generateKeyPairSync, randomBytes } from 'node:crypto';
import postgres from 'postgres';
import { createApp } from '../src/app.mjs';
import { loadConfig } from '../src/config.mjs';
import { migrate } from '../src/migrate.mjs';

const ADMIN_URL = process.env.TEST_DATABASE_URL ?? 'postgres://localhost:5432/postgres';

const TEST_DB = /^fluffless_api_test_(\d+)_[0-9a-f]{6}$/;

function alive(pid) {
	try {
		process.kill(pid, 0);
		return true;
	} catch (error) {
		return error.code === 'EPERM';
	}
}

// Une exécution tuée (délai dépassé, Ctrl-C) ne peut pas nettoyer sa base : la suivante retire
// celles dont le processus n'existe plus, sans toucher à celles d'un test encore en cours.
async function dropStaleDatabases(admin) {
	const rows = await admin`select datname from pg_database where datname like 'fluffless\_api\_test\_%'`;
	for (const { datname } of rows) {
		const match = TEST_DB.exec(datname);
		if (match && !alive(Number(match[1]))) {
			// Deux démarrages simultanés peuvent viser la même base périmée : le second échec est sans conséquence.
			await admin.unsafe(`drop database if exists ${datname} with (force)`).catch(() => {});
		}
	}
}

export async function startTestServer(options = {}) {
	const dbName = `fluffless_api_test_${process.pid}_${randomBytes(3).toString('hex')}`;
	const admin = postgres(ADMIN_URL, { max: 1, onnotice: () => {} });
	await dropStaleDatabases(admin);
	await admin.unsafe(`create database ${dbName}`);
	try {
		return await startOn(dbName, admin, options);
	} catch (error) {
		// L'installation a échoué après la création de la base : on ne la laisse pas derrière.
		await admin.unsafe(`drop database if exists ${dbName} with (force)`).catch(() => {});
		await admin.end({ timeout: 2 }).catch(() => {});
		throw error;
	}
}

async function startOn(dbName, admin, { now, env: extraEnv = {}, stripe = null, app = {} }) {
	const dbUrl = new URL(ADMIN_URL);
	dbUrl.pathname = `/${dbName}`;

	const { privateKey, publicKey } = generateKeyPairSync('ed25519');
	const env = {
		DATABASE_URL: dbUrl.toString(),
		PUBLIC_URL: 'http://127.0.0.1',
		SIGNING_PRIVATE_KEY: privateKey.export({ format: 'der', type: 'pkcs8' }).toString('base64'),
		ADMIN_TOKEN: randomBytes(32).toString('hex'),
		URL_SECRET: randomBytes(32).toString('hex'),
		HASH_PEPPER: randomBytes(32).toString('hex'),
		TRUST_PROXY: '1',
		...extraEnv,
	};
	const config = loadConfig(env);
	const sql = postgres(config.databaseUrl, { max: 3, onnotice: () => {} });
	await migrate(sql);

	const mails = [];
	const logs = [];
	// Livraison des liens de packs : remplaçable par test (échec SMTP, envoi absent).
	const deliveries = [];
	let deliverImpl = async (mail) => { deliveries.push(mail); return true; };
	// Livraison des clés de licence achetées, remplaçable de la même façon.
	const licenceDeliveries = [];
	let deliverLicenceImpl = async (mail) => { licenceDeliveries.push(mail); return true; };
	// Rappels d'expiration : remplaçable par test (échec SMTP).
	const reminders = [];
	let remindImpl = async (mail) => { reminders.push(mail); return true; };
	// Courriel du nouveau lien après renewal-refund : remplaçable par test (échec SMTP).
	const refundNotices = [];
	let refundNoticeImpl = async (mail) => { refundNotices.push(mail); return true; };
	const alerts = [];
	const handler = createApp({
		sql,
		config,
		notify: async (report) => { mails.push(report); return true; },
		deliver: (mail) => deliverImpl(mail),
		deliverLicence: (mail) => deliverLicenceImpl(mail),
		alert: async (message) => { alerts.push(message); return true; },
		remind: (mail) => remindImpl(mail),
		notifyRenewalRefund: (mail) => refundNoticeImpl(mail),
		stripe,
		log: (level, event, fields) => logs.push({ level, event, ...fields }),
		...(now ? { now } : {}),
		...app,
	});
	const server = createServer(handler);
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
	const base = `http://127.0.0.1:${server.address().port}`;
	config.publicUrl = base;

	// La base se retire quoi qu'il arrive aux étapes d'avant (connexion tenue ouverte, sql.end en échec).
	async function stop() {
		try {
			server.closeAllConnections();
			await new Promise((resolve) => server.close(resolve));
			await sql.end({ timeout: 2 });
		} finally {
			try {
				await admin.unsafe(`drop database if exists ${dbName} with (force)`);
			} finally {
				await admin.end({ timeout: 2 });
			}
		}
	}

	const request = (path, { method = 'GET', body, token, headers = {}, redirect = 'follow' } = {}) => fetch(base + path, {
		method,
		redirect,
		headers: {
			...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
			...(token ? { Authorization: `Bearer ${token}` } : {}),
			...headers,
		},
		body: body === undefined ? undefined : typeof body === 'string' ? body : JSON.stringify(body),
	});

	const setDeliver = (fn) => { deliverImpl = fn ?? (async (mail) => { deliveries.push(mail); return true; }); };

	const setDeliverLicence = (fn) => { deliverLicenceImpl = fn ?? (async (mail) => { licenceDeliveries.push(mail); return true; }); };

	const setRemind = (fn) => { remindImpl = fn ?? (async (mail) => { reminders.push(mail); return true; }); };

	const setRefundNotice = (fn) => { refundNoticeImpl = fn ?? (async (mail) => { refundNotices.push(mail); return true; }); };

	return {
		base, env, config, sql, publicKey, mails, logs, deliveries, licenceDeliveries, reminders, refundNotices, alerts, setDeliver, setDeliverLicence, setRemind, setRefundNotice, request, stop,
		reconcile: handler.reconcile, redeliverLicences: handler.redeliverLicences, sendRenewalReminders: handler.sendRenewalReminders,
	};
}

export const fakeZip = (label = 'x') => Buffer.concat([Buffer.from([0x50, 0x4b, 0x03, 0x04]), Buffer.from(label.repeat(64))]);
export const fakePng = () => Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), randomBytes(32)]);
export const siteId = (n = 1) => String(n).padStart(64, 'a').slice(0, 64).replace(/[^0-9a-f]/g, 'b');
