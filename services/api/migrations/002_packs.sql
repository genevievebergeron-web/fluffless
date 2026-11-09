-- Packs curés : réclamation gratuite par licence (docs/PLAN.md, « Packs curés »).
-- Le lien de réclamation se fait par licences.id, jamais par la clé : une clé régénérée ne
-- casse rien. L'achat Stripe (source = 'purchase') n'est pas encore branché.

-- Seule adresse à laquelle un lien de pack peut partir : jamais un champ du formulaire.
alter table licences add column email text;

create table packs (
	id bigserial primary key,
	slug text not null unique,
	name_fr text not null,
	name_en text not null,
	description_fr text not null default '',
	description_en text not null default '',
	-- Fenêtre de réclamation gratuite; null des deux côtés = pas de réclamation gratuite.
	claim_opens_at timestamptz,
	claim_closes_at timestamptz,
	zip bytea not null,
	zip_size integer not null,
	sha256 text not null,
	signature text not null,
	created_at timestamptz not null default now(),
	check ((claim_opens_at is null) = (claim_closes_at is null)),
	check (claim_closes_at is null or claim_closes_at > claim_opens_at)
);

create table pack_claims (
	id bigserial primary key,
	-- restrict : supprimer une licence ne doit pas effacer des packs obtenus en silence
	-- (rotation de clé : POST /v1/admin/licences/:id/rotate).
	licence_id bigint not null references licences(id) on delete restrict,
	pack_id bigint not null references packs(id) on delete restrict,
	source text not null check (source in ('purchase', 'free_claim')),
	created_at timestamptz not null default now(),
	-- Premier arrivé, premier servi : la première écriture qui aboutit garde la ligne.
	unique (licence_id, pack_id)
);

-- Lien de téléchargement envoyé par courriel : 72 h, usage unique. Seul le HMAC du jeton
-- est gardé, comme pour les clés de licence.
create table pack_links (
	token_hash text primary key,
	claim_id bigint not null references pack_claims(id) on delete cascade,
	expires_at timestamptz not null,
	used_at timestamptz,
	created_at timestamptz not null default now()
);
create index pack_links_claim on pack_links (claim_id);
