-- Vente hors Canada par Managed Payments (Stripe revendeur, STRIPE_MANAGED_PAYMENTS=1). Stripe
-- gère les taxes dans une liste de pays (MANAGED_COUNTRIES, src/app.mjs) ; ailleurs, elles
-- resteraient à la charge de la vendeuse : le paiement est remboursé au règlement, sans accès.
alter table pack_purchases drop constraint pack_purchases_refund_reason_check;
alter table pack_purchases add constraint pack_purchases_refund_reason_check
	check (refund_reason in ('already_owned', 'licence_missing', 'pack_missing', 'outside_canada', 'country_not_covered'));
