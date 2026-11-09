-- Relation après l'achat : rappels d'expiration et renouvellement qui garde la clé.
--
-- licence_reminders : un rappel par licence, par fin de période et par seuil (30 ou 7 jours).
-- La ligne se prend (claimed_at) avant l'envoi, se retire si l'envoi échoue et reçoit sent_at une
-- fois parti : deux passages simultanés n'envoient jamais deux fois, une prise restée sans envoi
-- (plantage) se reprend après 15 minutes, et un renouvellement (nouvelle fin) rouvre les rappels
-- de l'an suivant.
create table licence_reminders (
	licence_id bigint not null references licences (id) on delete cascade,
	period_end timestamptz not null,
	days_before integer not null check (days_before in (7, 30)),
	claimed_at timestamptz not null default now(),
	sent_at timestamptz,
	primary key (licence_id, period_end, days_before)
);

-- licence_renewals : un paiement de renouvellement par session Checkout. licence_purchases ne
-- convient pas (licence_id y est unique : une licence naît d'un seul achat). period_from est la
-- fin de période que le lien de renouvellement annonçait : deux paiements pour la même période
-- ne prolongent qu'une fois, le second est remboursé.
create table licence_renewals (
	checkout_session_id text primary key,
	payment_intent text,
	licence_id bigint references licences (id) on delete set null,
	offer_id bigint references licence_offers (id) on delete set null,
	period_from timestamptz,
	amount_total bigint not null default 0,
	currency text not null default '',
	locale text not null default 'fr_CA',
	outcome text not null check (outcome in ('granted', 'refund_pending', 'refunded')),
	refund_reason text,
	refund_id text,
	created_at timestamptz not null default now(),
	updated_at timestamptz not null default now()
);
create unique index licence_renewals_period_once on licence_renewals (licence_id, period_from) where outcome = 'granted';
create index licence_renewals_pending on licence_renewals (created_at) where outcome = 'refund_pending';
