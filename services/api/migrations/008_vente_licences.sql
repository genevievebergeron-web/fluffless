-- Vente des licences par Stripe Checkout (README, « Vente des licences »). La cliente paie sans
-- compte : la licence naît au règlement du paiement, avec le courriel saisi chez Stripe, et la clé
-- part par courriel avec l'adresse de la page de téléchargement (la cliente y colle sa clé).

-- Offres en vente. Une offre sans prix Stripe n'est pas en vente. duration_days nul : licence sans
-- date de fin (renouveler = racheter). release_slug : l'extension livrée par la page de téléchargement.
create table licence_offers (
	id bigserial primary key,
	slug text not null unique check (slug ~ '^[a-z0-9-]{1,64}$'),
	plan text not null check (plan ~ '^[a-z0-9_-]{1,40}$'),
	sites_max integer not null check (sites_max between 1 and 10000),
	duration_days integer check (duration_days between 1 and 3650),
	release_slug text not null default 'fluffless-suite' check (release_slug ~ '^[a-z0-9-]{1,64}$'),
	stripe_price_id text,
	price_amount integer,
	price_currency text,
	created_at timestamptz not null default now(),
	check ((stripe_price_id is null) = (price_amount is null) and (price_amount is null) = (price_currency is null))
);

-- Un paiement confirmé par session Checkout, comme pack_purchases. La ligne survit à la licence
-- (pièce comptable) : licence_id passe à null si la licence est effacée.
create table licence_purchases (
	checkout_session_id text primary key,
	payment_intent text unique,
	offer_id bigint references licence_offers(id) on delete restrict,
	licence_id bigint unique references licences(id) on delete set null,
	amount_total integer not null,
	currency text not null,
	-- Langue du courriel de livraison, choisie au moment du paiement.
	locale text not null default 'fr_CA',
	outcome text not null check (outcome in ('granted', 'refund_pending', 'refunded')),
	refund_reason text check (refund_reason in ('offer_missing', 'outside_canada', 'country_not_covered')),
	refund_id text,
	-- Livraison de la clé : posée quand le courriel est parti. La clé est dérivée de la session et de
	-- key_generation (jamais gardée en clair) : un nouvel essai renvoie la même clé. Seul un renvoi
	-- demandé par l'administration incrémente la génération, donc change la clé.
	key_generation integer not null default 0,
	delivered_at timestamptz,
	delivering_until timestamptz,
	delivery_attempts integer not null default 0,
	created_at timestamptz not null default now(),
	updated_at timestamptz not null default now()
);
create index licence_purchases_pending on licence_purchases (outcome) where outcome = 'refund_pending';
create index licence_purchases_undelivered on licence_purchases (created_at) where outcome = 'granted' and delivered_at is null;
