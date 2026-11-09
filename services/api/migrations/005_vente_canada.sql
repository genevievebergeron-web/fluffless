-- Vente des packs au Canada seulement (TPS, TVQ et TVH perçues par Stripe Tax; pas de TVA
-- étrangère à percevoir). Checkout ne sait pas restreindre le pays de facturation : un paiement
-- venu d'ailleurs est remboursé au règlement, sans accès.
alter table pack_purchases drop constraint pack_purchases_refund_reason_check;
alter table pack_purchases add constraint pack_purchases_refund_reason_check
	check (refund_reason in ('already_owned', 'licence_missing', 'pack_missing', 'outside_canada'));
