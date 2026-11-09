-- Coupons de licence : un code que la cliente saisit, un rabais en pourcentage posé par
-- l'administration (aucun taux n'existe avant qu'elle en crée un), un coupon Stripe créé avec le
-- même taux. Les achats gardent le code et le rabais obtenu, pour les compter et les rapprocher.
create table licence_coupons (
	code text primary key check (code ~ '^[A-Z0-9-]{3,40}$'),
	label text not null default '',
	percent_off integer not null check (percent_off between 1 and 99),
	stripe_coupon_id text not null,
	max_redemptions integer check (max_redemptions between 1 and 100000),
	expires_at timestamptz,
	active boolean not null default true,
	created_at timestamptz not null default now()
);

alter table licence_purchases
	add column coupon_code text references licence_coupons (code) on delete set null,
	add column discount_amount integer not null default 0;
create index licence_purchases_coupon on licence_purchases (coupon_code) where coupon_code is not null;
