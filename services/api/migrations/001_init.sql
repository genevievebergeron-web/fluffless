-- Schéma initial du serveur Fluffless. Aucune adresse IP n'est stockée (Loi 25) :
-- la limite de débit vit en mémoire. Les sites et les clés de licence sont gardés
-- sous forme de HMAC (poivre HASH_PEPPER), jamais en clair.

create table releases (
	id bigserial primary key,
	slug text not null check (slug ~ '^[a-z0-9-]{1,64}$'),
	version text not null check (length(version) between 1 and 64),
	channel text not null check (channel in ('stable', 'beta')),
	name text not null,
	homepage text not null default '',
	requires text not null default '',
	requires_php text not null default '',
	tested text not null default '',
	description_fr text not null default '',
	description_en text not null default '',
	changelog_fr text not null default '',
	changelog_en text not null default '',
	zip bytea not null,
	zip_size integer not null,
	sha256 text not null,
	signature text not null,
	created_at timestamptz not null default now(),
	unique (slug, version)
);

create table licences (
	id bigserial primary key,
	key_hash text not null unique,
	plan text not null,
	sites_max integer not null check (sites_max between 1 and 10000),
	expires_at timestamptz,
	note text not null default '',
	created_at timestamptz not null default now(),
	revoked_at timestamptz
);

create table licence_sites (
	licence_id bigint not null references licences (id) on delete cascade,
	site_hash text not null,
	registered_at timestamptz not null default now(),
	primary key (licence_id, site_hash)
);

create table reports (
	id uuid primary key default gen_random_uuid(),
	type text not null check (type in ('bug', 'idea', 'question')),
	what text not null,
	expected text not null default '',
	locale text not null,
	reply_email text,
	diagnostics jsonb,
	site_hash text,
	screenshot bytea,
	screenshot_type text check (screenshot_type in ('image/png', 'image/webp')),
	status text not null default 'nouveau' check (status in ('nouveau', 'vu', 'réglé')),
	created_at timestamptz not null default now(),
	updated_at timestamptz not null default now(),
	resolved_at timestamptz
);
create index reports_status_created on reports (status, created_at desc);

-- Emplacement réservé aux webhooks Stripe (non branché, voir README).
create table stripe_events (
	id text primary key,
	type text not null,
	payload jsonb not null,
	received_at timestamptz not null default now(),
	processed_at timestamptz
);
