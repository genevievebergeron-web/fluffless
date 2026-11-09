// Courriels par la soumission SMTP de Proton Mail : notification des signalements et
// livraison des liens de packs et des clés de licence achetées. Sans PROTON_SMTP_USER ou
// PROTON_SMTP_TOKEN, rien ne part et l'absence est journalisée. Pour un signalement, l'envoi
// est un confort (la réponse reste 201); pour un pack, l'appelant répond 503 et la cliente
// redemande le lien plus tard; pour une licence, la relivraison réessaie toutes les 10 minutes.
import nodemailer from 'nodemailer';

const TYPE_LABELS = { bug: 'Bogue', idea: 'Idée', question: 'Question' };
const DIAGNOSTICS_MAX_CHARS = 4000;

export function formatReportEmail(report) {
	const diagnostics = report.diagnostics ? JSON.stringify(report.diagnostics, null, 2).slice(0, DIAGNOSTICS_MAX_CHARS) : 'aucun';
	return {
		subject: `[Fluffless] ${TYPE_LABELS[report.type]} ${report.id.slice(0, 8)}`,
		text: [
			`Type : ${TYPE_LABELS[report.type]}`,
			`Langue : ${report.locale}`,
			`Réponse souhaitée à : ${report.reply_email ?? 'personne (anonyme)'}`,
			`Capture d'écran : ${report.has_screenshot ? 'oui (voir bin/admin.mjs)' : 'non'}`,
			'',
			'Ce qui se passe :',
			report.what,
			'',
			'Ce qui était attendu :',
			report.expected || '(non précisé)',
			'',
			'Diagnostic :',
			diagnostics,
			'',
			`Identifiant : ${report.id}`,
		].join('\n'),
	};
}

export function createMailer({ user, token, to }, log, transportFactory = nodemailer.createTransport) {
	if (!user || !token) {
		return async (report) => {
			log('info', 'notification_absente', { report_id: report.id, reason: 'PROTON_SMTP_USER ou PROTON_SMTP_TOKEN absente' });
			return false;
		};
	}
	const transport = protonTransport(user, token, transportFactory);
	return async (report) => {
		const { subject, text } = formatReportEmail(report);
		await transport.sendMail({
			from: `Fluffless <${user}>`,
			to,
			replyTo: report.reply_email ?? undefined,
			subject,
			text,
		});
		log('info', 'notification_envoyee', { report_id: report.id });
		return true;
	};
}

function protonTransport(user, token, transportFactory) {
	return transportFactory({
		host: 'smtp.protonmail.ch',
		port: 587,
		secure: false,
		requireTLS: true,
		auth: { user, pass: token },
		connectionTimeout: 10_000,
		greetingTimeout: 10_000,
		socketTimeout: 20_000,
	});
}

// Textes français : U+00A0 avant « : », U+202F avant « ; ? ! », rien dans l'anglais
// (test/pages-courriels.test.mjs, C4-1-09).
const PACK_TEXTS = {
	fr: {
		subject: (count) => (count > 1 ? 'Tes packs Fluffless' : 'Ton pack Fluffless'),
		intro: 'Voici le lien de téléchargement demandé avec ta licence Fluffless :',
		introPurchase: 'Merci pour ton achat ! Voici le lien de téléchargement de ton pack :',
		rules: (date) => [
			`Chaque lien fonctionne une seule fois, jusqu'au ${date} (heure de Montréal).`,
			'Si le téléchargement échoue ou si le lien expire, redemande-le sur la page du catalogue avec ta clé de licence.',
			'Tu n\'as rien demandé ? Ignore ce courriel : personne ne peut changer l\'adresse où partent ces liens.',
		],
	},
	en: {
		subject: (count) => (count > 1 ? 'Your Fluffless packs' : 'Your Fluffless pack'),
		intro: 'Here is the download link requested with your Fluffless licence:',
		introPurchase: 'Thank you for your purchase! Here is the download link for your pack:',
		rules: (date) => [
			`Each link works only once, until ${date} (Montreal time).`,
			'If the download fails or the link expires, request it again from the catalogue page with your licence key.',
			'Didn\'t ask for this? Ignore this email: nobody can change the address these links go to.',
		],
	},
};

export function formatPackEmail({ locale, links, expires, purchase = false }) {
	const t = PACK_TEXTS[locale.startsWith('en') ? 'en' : 'fr'];
	const date = longDayTime(expires, locale);
	return {
		subject: t.subject(links.length),
		text: [
			purchase ? t.introPurchase : t.intro,
			'',
			...links.flatMap(({ name, url }) => [name, url, '']),
			...t.rules(date),
			'',
			'Fluffless',
		].join('\n'),
	};
}

// Livre les liens de packs à l'adresse enregistrée sur la licence, jamais à une autre.
export function createPackMailer({ user, token }, log, transportFactory = nodemailer.createTransport) {
	if (!user || !token) {
		return async () => {
			log('warn', 'livraison_absente', { reason: 'PROTON_SMTP_USER ou PROTON_SMTP_TOKEN absente' });
			return false;
		};
	}
	const transport = protonTransport(user, token, transportFactory);
	return async ({ to, locale, links, expires, purchase = false }) => {
		const { subject, text } = formatPackEmail({ locale, links, expires, purchase });
		await transport.sendMail({ from: `Fluffless <${user}>`, replyTo: HELP_ADDRESS, to, subject, text });
		log('info', 'livraison_envoyee', { packs: links.length });
		return true;
	};
}

// Les clientes qui répondent à un courriel de licence ou de rappel écrivent à cette adresse.
const HELP_ADDRESS = 'genevieve@fluffless.dev';

const LICENCE_TEXTS = {
	fr: {
		subject: 'Ta licence Fluffless',
		intro: 'Merci pour ton achat ! Voici ta clé de licence Fluffless :',
		download: 'Pour télécharger l\'extension, colle ta clé sur cette page :',
		downloadSeveral: 'Pour télécharger tes extensions, colle ta clé sur cette page : elle donne un lien pour chacune.',
		order: 'Installe Fluffless Suite en premier : les autres extensions en ont besoin.',
		steps: [
			'Pour l\'installer : dans WordPress, Extensions, Ajouter, Téléverser une extension, puis choisis le fichier zip.',
			'Colle ensuite ta clé dans Fluffless, Modules, carte des mises à jour : les mises à jour suivantes s\'affichent dans WordPress, comme pour toute extension.',
		],
		week: [
			'Ta première semaine :',
			'1. Jour 1 : installe et active la Suite, puis colle ta clé (les étapes ci-dessus).',
			'2. Jours 2 et 3 : ouvre Fluffless, Accueil, et suis les trois étapes de départ.',
			'3. D\'ici la fin de la semaine : publie ta première page.',
		],
		valid: (date) => `Ta licence est valable jusqu'au ${date}.`,
		reminders: 'Deux rappels t\'arrivent avant la fin (30 et 7 jours avant), avec un lien pour la renouveler en gardant la même clé.',
		rules: [
			'La page de téléchargement reste ouverte tant que ta licence est active.',
			'Garde ce courriel : c\'est le seul endroit où ta clé est écrite. Perdue, elle se remplace par une nouvelle.',
			'Une question ? Réponds à ce courriel ou écris à genevieve@fluffless.dev.',
		],
	},
	en: {
		subject: 'Your Fluffless licence',
		intro: 'Thank you for your purchase! Here is your Fluffless licence key:',
		download: 'To download the plugin, paste your key on this page:',
		downloadSeveral: 'To download your plugins, paste your key on this page: it gives a link for each one.',
		order: 'Install Fluffless Suite first: the other plugins need it.',
		steps: [
			'To install it: in WordPress, go to Plugins, Add New, Upload Plugin, then choose the zip file.',
			'Then paste your key in Fluffless, Modules, updates card: later updates show up in WordPress like any other plugin\'s.',
		],
		week: [
			'Your first week:',
			'1. Day 1: install and activate the Suite, then paste your key (the steps above).',
			'2. Days 2 and 3: open Fluffless, Home, and follow the three getting-started steps.',
			'3. By the end of the week: publish your first page.',
		],
		valid: (date) => `Your licence is valid until ${date}.`,
		reminders: 'Two reminders reach you before it ends (30 and 7 days before), with a link to renew it and keep the same key.',
		rules: [
			'The download page stays open as long as your licence is active.',
			'Keep this email: it is the only place your key is written down. If you lose it, we replace it with a new one.',
			'Questions? Reply to this email or write to genevieve@fluffless.dev.',
		],
	},
};

// Jour de fin lisible, à l'heure de Montréal : une fin à 21 h ne passe pas au lendemain.
export const longDay = (date, locale) => new Intl.DateTimeFormat(locale.startsWith('en') ? 'en-CA' : 'fr-CA', { dateStyle: 'long', timeZone: 'America/Toronto' }).format(date);

// Jour et heure (sur 24 h) à l'heure de Montréal, pour une échéance qui tombe dans la journée.
// L'heure se compose à la main : le gabarit de l'heure varie d'une version d'ICU à l'autre.
export function longDayTime(date, locale) {
	const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'America/Toronto' })
		.formatToParts(date).map(({ type, value }) => [type, value]));
	const hour = Number(parts.hour);
	return locale.startsWith('en')
		? `${longDay(date, locale)} at ${hour}:${parts.minute}`
		: `${longDay(date, locale)} à ${hour}\u00a0h\u00a0${parts.minute}`;
}

export function formatLicenceEmail({ locale, key, url, several = false, expires = null, reminders = false }) {
	const t = LICENCE_TEXTS[locale.startsWith('en') ? 'en' : 'fr'];
	const steps = several ? [t.order, ...t.steps] : t.steps;
	return {
		subject: t.subject,
		text: [
			t.intro, '', key, '', several ? t.downloadSeveral : t.download, url, '', ...steps, '',
			...t.week, '', ...(expires ? [t.valid(longDay(expires, locale)), ...(reminders ? [t.reminders] : []), ''] : []), ...t.rules, '', 'Fluffless',
		].join('\n'),
	};
}

// Livre la clé d'une licence achetée à l'adresse saisie chez Stripe.
export function createLicenceMailer({ user, token }, log, transportFactory = nodemailer.createTransport) {
	if (!user || !token) {
		return async () => {
			log('warn', 'livraison_absente', { reason: 'PROTON_SMTP_USER ou PROTON_SMTP_TOKEN absente' });
			return false;
		};
	}
	const transport = protonTransport(user, token, transportFactory);
	return async ({ to, locale, key, url, several, expires, reminders }) => {
		const { subject, text } = formatLicenceEmail({ locale, key, url, several, expires, reminders });
		await transport.sendMail({ from: `Fluffless <${user}>`, replyTo: HELP_ADDRESS, to, subject, text });
		log('info', 'licence_livree', {});
		return true;
	};
}

const REMINDER_TEXTS = {
	fr: {
		subject: (days) => `Ta licence Fluffless se termine dans ${days} ${days === 1 ? 'jour' : 'jours'}`,
		intro: (days, date) => `Ta licence Fluffless se termine dans ${days} ${days === 1 ? 'jour' : 'jours'}, le ${date}.`,
		after: 'Après cette date, ton site continue de fonctionner. Les mises à jour et le téléchargement de la Suite sont mis en pause tant que la licence n\'est pas renouvelée.',
		renew: 'Pour la renouveler, ta clé reste la même :',
		noLink: 'Pour la renouveler en gardant ta clé, écris à genevieve@fluffless.dev.',
		help: 'Une question ? Réponds à ce courriel ou écris à genevieve@fluffless.dev.',
	},
	en: {
		subject: (days) => `Your Fluffless licence ends in ${days} ${days === 1 ? 'day' : 'days'}`,
		intro: (days, date) => `Your Fluffless licence ends in ${days} ${days === 1 ? 'day' : 'days'}, on ${date}.`,
		after: 'After that date your site keeps working. Updates and the Suite download are paused until the licence is renewed.',
		renew: 'To renew it, your key stays the same:',
		noLink: 'To renew it and keep your key, write to genevieve@fluffless.dev.',
		help: 'Questions? Reply to this email or write to genevieve@fluffless.dev.',
	},
};

export function formatReminderEmail({ locale, daysLeft, expires, renewUrl = null }) {
	const t = REMINDER_TEXTS[locale.startsWith('en') ? 'en' : 'fr'];
	return {
		subject: t.subject(daysLeft),
		text: [t.intro(daysLeft, longDay(expires, locale)), '', t.after, '', ...(renewUrl ? [t.renew, renewUrl] : [t.noLink]), '', t.help, '', 'Fluffless'].join('\n'),
	};
}

// Rappel d'expiration à l'adresse enregistrée sur la licence. Une absence de SMTP se journalise
// et ne compte pas comme envoi : le passage suivant réessaie.
export function createReminderMailer({ user, token }, log, transportFactory = nodemailer.createTransport) {
	if (!user || !token) {
		return async () => {
			log('warn', 'rappel_absent', { reason: 'PROTON_SMTP_USER ou PROTON_SMTP_TOKEN absente' });
			return false;
		};
	}
	const transport = protonTransport(user, token, transportFactory);
	return async ({ to, locale, daysLeft, expires, renewUrl }) => {
		const { subject, text } = formatReminderEmail({ locale, daysLeft, expires, renewUrl });
		await transport.sendMail({ from: `Fluffless <${user}>`, replyTo: HELP_ADDRESS, to, subject, text });
		log('info', 'rappel_envoye', { days_left: daysLeft });
		return true;
	};
}

const RENEWAL_REFUND_TEXTS = {
	fr: {
		subject: 'Ton renouvellement Fluffless est remboursé',
		intro: (date) => `Ton dernier renouvellement est remboursé. Ta licence Fluffless prend donc fin le ${date}.`,
		after: 'Après cette date, ton site continue de fonctionner. Les mises à jour et le téléchargement de la Suite sont mis en pause tant que la licence n\'est pas renouvelée.',
		renew: 'Le lien de renouvellement reçu avant ce remboursement ne fonctionne plus. Voici le nouveau, ta clé reste la même :',
		grace: (date) => `Renouvelle au plus tard le ${date} pour garder le prix de ton achat.`,
		help: 'Une question ? Réponds à ce courriel ou écris à genevieve@fluffless.dev.',
	},
	en: {
		subject: 'Your Fluffless renewal was refunded',
		intro: (date) => `Your last renewal was refunded, so your Fluffless licence now ends on ${date}.`,
		after: 'After that date your site keeps working. Updates and the Suite download are paused until the licence is renewed.',
		renew: 'The renewal link you got before this refund no longer works. Here is the new one, and your key stays the same:',
		grace: (date) => `Renew by ${date} to keep the price you paid.`,
		help: 'Questions? Reply to this email or write to genevieve@fluffless.dev.',
	},
};

export function formatRenewalRefundEmail({ locale, expires, graceUntil, renewUrl }) {
	const t = RENEWAL_REFUND_TEXTS[locale.startsWith('en') ? 'en' : 'fr'];
	return {
		subject: t.subject,
		text: [t.intro(longDay(expires, locale)), '', t.after, '', t.renew, renewUrl, '', t.grace(longDay(graceUntil, locale)), '', t.help, '', 'Fluffless'].join('\n'),
	};
}

// Après renewal-refund : le nouveau lien de renouvellement à l'adresse de la licence (l'ancien
// meurt avec l'ancienne fin, et les rappels de la fin rétablie peuvent déjà être partis).
export function createRenewalRefundMailer({ user, token }, log, transportFactory = nodemailer.createTransport) {
	if (!user || !token) {
		return async () => {
			log('warn', 'renouvellement_defait_courriel_absent', { reason: 'PROTON_SMTP_USER ou PROTON_SMTP_TOKEN absente' });
			return false;
		};
	}
	const transport = protonTransport(user, token, transportFactory);
	return async ({ to, locale, expires, graceUntil, renewUrl }) => {
		const { subject, text } = formatRenewalRefundEmail({ locale, expires, graceUntil, renewUrl });
		await transport.sendMail({ from: `Fluffless <${user}>`, replyTo: HELP_ADDRESS, to, subject, text });
		log('info', 'renouvellement_defait_courriel', {});
		return true;
	};
}

// Alertes de paiement à Geneviève (remboursement, litige, rattrapage). Jamais de courriel
// ni d'adresse de cliente dans le texte : le numéro de licence suffit pour retrouver.
export function createAlertMailer({ user, token, to }, log, transportFactory = nodemailer.createTransport) {
	// Une alerte qui ne part pas laisse un seul témoin : le journal, au niveau error, avec de quoi
	// retrouver l'achat (numéro de licence, session), sans adresse de cliente.
	const undelivered = (subject, lines, cause) => {
		log('error', 'alerte_non_livree', { subject, lines, cause });
		return false;
	};
	if (!user || !token) {
		return async ({ subject, lines }) => undelivered(subject, lines, 'PROTON_SMTP_USER ou PROTON_SMTP_TOKEN absente');
	}
	const transport = protonTransport(user, token, transportFactory);
	return async ({ subject, lines }) => {
		try {
			await transport.sendMail({ from: `Fluffless <${user}>`, to, subject: `[Fluffless] ${subject}`, text: lines.join('\n') });
		} catch (error) {
			return undelivered(subject, lines, `smtp ${error.responseCode ?? error.code ?? 'inconnue'}`);
		}
		log('info', 'alerte_envoyee', { subject });
		return true;
	};
}

// Canal des alertes de paiement : le webhook d'abord (ALERT_WEBHOOK_URL), le courriel en secours.
// Sans webhook, le courriel part seul, comme avant. Le corps tient en JSON simple (subject, lines,
// text) pour qu'un relais le transforme en message. L'adresse du webhook porte souvent un jeton :
// ni le journal ni les causes ne la recopient, seulement un code ou l'état HTTP.
export function createAlertChannel({ webhookUrl, mailer, log, fetchImpl = fetch, timeoutMs = 5000 }) {
	if (!webhookUrl) {
		return mailer;
	}
	return async ({ subject, lines }) => {
		const title = `[Fluffless] ${subject}`;
		try {
			const response = await fetchImpl(webhookUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ subject: title, lines, text: [title, ...lines].join('\n') }),
				redirect: 'error',
				signal: AbortSignal.timeout(timeoutMs),
			});
			if (!response.ok) {
				throw Object.assign(new Error('refus'), { code: `http ${response.status}` });
			}
		} catch (error) {
			log('error', 'alerte_webhook_echec', { subject, cause: error.code ?? error.name ?? 'inconnue' });
			return mailer({ subject, lines });
		}
		log('info', 'alerte_webhook_envoyee', { subject });
		return true;
	};
}
