import { existsSync } from 'node:fs';
import { createServer } from 'node:http';
import postgres from 'postgres';
import { createApp } from './app.mjs';
import { loadConfig, startupWarnings } from './config.mjs';
import { createLogger } from './log.mjs';
import { createAlertChannel, createAlertMailer, createLicenceMailer, createMailer, createPackMailer, createReminderMailer, createRenewalRefundMailer } from './mail.mjs';
import { migrate, purgeExpiredLicences, purgeLicencePlaces, purgeResolvedReports, purgeStripeRecords } from './migrate.mjs';
import { createStripeClient } from './stripe.mjs';

const DAY_MS = 86_400_000;
const LICENCE_REDELIVER_MS = 10 * 60_000;
const log = createLogger();

async function main() {
	const config = loadConfig();
	const sql = postgres(config.databaseUrl, { max: 5, idle_timeout: 30, connect_timeout: 10, onnotice: () => {} });

	const applied = await migrate(sql);
	log('info', 'migrations', { applied });

	const purge = () => Promise.all([
		purgeResolvedReports(sql).then((count) => log('info', 'purge_signalements', { count })),
		purgeStripeRecords(sql).then((counts) => log('info', 'purge_stripe', counts)),
		purgeLicencePlaces(sql).then((counts) => log('info', 'purge_places', counts)),
		purgeExpiredLicences(sql).then((counts) => log('info', 'purge_licences', counts)),
	]).catch((error) => log('error', 'purge_echec', { error: error.code ?? error.name }));
	await purge();
	setInterval(purge, DAY_MS).unref();

	const stripe = config.stripe.secretKey ? createStripeClient({ secretKey: config.stripe.secretKey, apiVersion: config.stripe.apiVersion }) : null;
	const handler = createApp({
		sql,
		config,
		notify: createMailer(config.smtp, log),
		deliver: createPackMailer(config.smtp, log),
		deliverLicence: createLicenceMailer(config.smtp, log),
		alert: createAlertChannel({ webhookUrl: config.alertWebhookUrl, mailer: createAlertMailer(config.smtp, log), log }),
		remind: createReminderMailer(config.smtp, log),
		notifyRenewalRefund: createRenewalRefundMailer(config.smtp, log),
		stripe,
		log,
	});
	if (stripe) {
		// Rattrapage quotidien des achats dont le webhook ne serait jamais arrivé.
		const reconcile = () => handler.reconcile()
			.catch((error) => log('error', 'reconciliation_echec', { error: error.code ?? error.name }));
		setTimeout(reconcile, 60_000).unref();
		setInterval(reconcile, DAY_MS).unref();
		// Clés de licence payées dont le courriel n'est pas parti (SMTP en panne, adresse refusée).
		const redeliver = () => handler.redeliverLicences()
			.catch((error) => log('error', 'relivraison_echec', { error: error.code ?? error.name }));
		setInterval(redeliver, LICENCE_REDELIVER_MS).unref();
	}
	// Rappels d'expiration (30 et 7 jours) : écrits, éteints tant que REMINDERS_ENABLED n'est pas à 1.
	if (config.remindersEnabled) {
		const remind = () => handler.sendRenewalReminders()
			.catch((error) => log('error', 'rappels_echec', { error: error.code ?? error.name }));
		setTimeout(remind, 120_000).unref();
		setInterval(remind, DAY_MS).unref();
	}
	const server = createServer({ requestTimeout: 60_000, headersTimeout: 15_000 }, handler);
	server.listen(config.port, () => {
		log('info', 'demarrage', { port: config.port, smtp: Boolean(config.smtp.user && config.smtp.token), stripe: Boolean(stripe), rappels: config.remindersEnabled });
		for (const warning of startupWarnings(config)) {
			log('warn', warning, {});
		}
	});

	const stop = (signal) => {
		log('info', 'arret', { signal });
		server.close(() => sql.end({ timeout: 5 }).finally(() => process.exit(0)));
		setTimeout(() => process.exit(1), 10_000).unref();
	};
	process.on('SIGTERM', stop);
	process.on('SIGINT', stop);
}

// Canner écrit les variables du projet dans .env.production au lieu de l'environnement
// du processus. Une variable déjà présente dans l'environnement garde la priorité.
function loadEnvFile(path = '.env.production') {
	if (existsSync(path)) {
		process.loadEnvFile(path);
	}
}

// Un démarrage raté laisse un serveur minimal qui répond 503 avec la cause : les noms des
// variables manquantes ou le code d'erreur, jamais de valeur. Sans lui, un plantage au
// démarrage ne se diagnostique qu'en ouvrant les journaux de l'hébergeur.
function serveStartupFailure(error) {
	const body = JSON.stringify({ status: 'startup_failed', missing: error.missing ?? [], reason: error.code ?? 'voir le journal' });
	createServer((req, res) => {
		res.writeHead(503, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
		res.end(body);
	}).listen(Number(process.env.PORT ?? 3000));
}

loadEnvFile();
main().catch((error) => {
	log('error', 'demarrage_echec', { error: error.message });
	serveStartupFailure(error);
});
