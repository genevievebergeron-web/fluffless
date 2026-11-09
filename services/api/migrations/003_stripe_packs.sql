-- Achat de packs par Stripe Checkout (docs/PLAN.md, « Packs curés », point 2).

-- Prix Stripe du pack; null = pas en vente. Montant et devise recopiés du prix à
-- l'enregistrement, pour l'affichage du catalogue sans appel à Stripe.
alter table packs add column stripe_price_id text;
alter table packs add column price_amount integer;
alter table packs add column price_currency text;
alter table packs add constraint packs_price_complete
	check ((stripe_price_id is null) = (price_amount is null) and (price_amount is null) = (price_currency is null));

-- Un paiement confirmé par session Checkout. La ligne survit à la licence (pièce
-- comptable) : licence_id passe à null si la licence est effacée.
create table pack_purchases (
	checkout_session_id text primary key,
	payment_intent text unique,
	licence_id bigint references licences(id) on delete set null,
	pack_id bigint references packs(id) on delete restrict,
	amount_total integer not null,
	currency text not null,
	-- refund_pending : remboursement dû mais pas encore confirmé par Stripe (réessayé par la
	-- réconciliation quotidienne).
	outcome text not null check (outcome in ('granted', 'refund_pending', 'refunded')),
	refund_reason text check (refund_reason in ('already_owned', 'licence_missing', 'pack_missing')),
	refund_id text,
	created_at timestamptz not null default now(),
	updated_at timestamptz not null default now()
);
create index pack_purchases_pending on pack_purchases (outcome) where outcome = 'refund_pending';

-- Loi 25 : l'événement complet porte le nom, l'adresse et le courriel de la cliente. On ne
-- garde que l'identifiant de l'objet.
alter table stripe_events alter column payload drop not null;
