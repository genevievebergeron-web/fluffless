-- Concurrence des paiements de packs (docs/PLAN.md, « Packs curés », point 2).

-- Bail de traitement : deux livraisons simultanées du même événement Stripe ne se traitent pas
-- en double. La seconde reçoit 409 et Stripe la réessaie plus tard. Un échec libère le bail.
alter table stripe_events add column processing_until timestamptz;

-- Sessions Checkout ouvertes par licence et par pack. Une nouvelle session expire les
-- précédentes (deux onglets ne paient pas deux fois), une réclamation gratuite les expire
-- toutes. Identifiants seulement; lignes effacées au paiement ou après 25 h.
create table pack_checkouts (
	checkout_session_id text primary key,
	licence_id bigint not null references licences(id) on delete cascade,
	pack_id bigint not null references packs(id) on delete cascade,
	created_at timestamptz not null default now()
);
create index pack_checkouts_owner on pack_checkouts (licence_id, pack_id);
