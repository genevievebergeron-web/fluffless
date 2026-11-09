-- Places de licence : une place se libère, un site de développement n'en prend aucune, et
-- l'administration peut donner un avis, révoquer puis rétablir une licence.

-- Dernier contact de chaque site, écrit au plus une fois par 24 h : sert à compter les sites
-- actifs, jamais à retirer une place.
alter table licence_sites add column last_seen_at timestamptz not null default now();

-- Sites de développement (adresse locale ou préproduction déclarée dans WordPress) : couverts
-- sans prendre de place, sous un plafond interne. Purgés 90 jours après le dernier contact.
create table licence_dev_sites (
	licence_id bigint not null references licences (id) on delete cascade,
	site_hash text not null,
	last_seen_at timestamptz not null default now(),
	primary key (licence_id, site_hash)
);

-- Libérations faites par la cliente, pour le plafond sur 30 jours glissants. Purgées après 30 jours.
create table licence_site_releases (
	id bigserial primary key,
	licence_id bigint not null references licences (id) on delete cascade,
	released_at timestamptz not null default now()
);
create index licence_site_releases_licence on licence_site_releases (licence_id, released_at);

-- Avis écrit avant une révocation, raison de la révocation, date du dernier rétablissement
-- (le délai de grâce du renouvellement en tient compte).
alter table licences
	add column notice_at timestamptz,
	add column notice_until timestamptz,
	add column notice_reason text check (notice_reason in ('usage', 'resale', 'sharing', 'other')),
	add column revoke_reason text,
	add column restored_at timestamptz,
	add check ((notice_at is null) = (notice_until is null) and (notice_at is null) = (notice_reason is null));

-- Signalements d'usage à examiner : des comptes seulement, jamais une empreinte ni un domaine.
-- Un même palier d'une même règle ne se signale qu'une fois.
create table licence_flags (
	id bigserial primary key,
	licence_id bigint not null references licences (id) on delete cascade,
	rule text not null check (rule in ('rhythm', 'volume', 'spread', 'ceiling', 'releases', 'dev')),
	level integer not null default 0,
	observed jsonb not null default '{}',
	created_at timestamptz not null default now(),
	reviewed_at timestamptz,
	outcome text check (outcome in ('ok', 'notice', 'revoked'))
);
create unique index licence_flags_level on licence_flags (licence_id, rule, level);
