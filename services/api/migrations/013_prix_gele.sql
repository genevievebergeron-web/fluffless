-- Prix gelé : le prix de l'achat, avant taxes et avant rabais, suit la licence. Le lien de
-- renouvellement le reprend jusqu'à 30 jours après la fin (ou après le dernier rétablissement);
-- au-delà, le prix du jour s'applique et devient le nouveau prix gelé.

-- Produit Stripe du prix de l'offre, lu quand le prix se pose : sert au repli price_data si le
-- prix gelé est archivé chez Stripe.
alter table licence_offers add column stripe_product_id text;

alter table licences
	add column renewal_price_id text,
	add column renewal_product_id text,
	add column renewal_unit_amount integer check (renewal_unit_amount >= 50),
	add column renewal_currency text check (renewal_currency = 'cad'),
	add check ((renewal_price_id is null) = (renewal_unit_amount is null)
		and (renewal_unit_amount is null) = (renewal_currency is null));

-- Prix unitaire payé et base du renouvellement (gelé ou du jour).
alter table licence_renewals
	add column unit_amount integer,
	add column price_basis text check (price_basis in ('gele', 'jour'));

-- remplissage
-- Licences déjà vendues : le prix actuel de leur offre d'origine, faute de mieux. Seules les
-- colonnes encore vides sont touchées : rejouer ce bloc ne change rien. Une licence créée à la
-- main n'a pas d'achat et garde des colonnes vides.
update licences l
set renewal_price_id = o.stripe_price_id, renewal_product_id = o.stripe_product_id,
	renewal_unit_amount = o.price_amount, renewal_currency = o.price_currency
from licence_purchases p
join licence_offers o on o.id = p.offer_id
where p.licence_id = l.id and p.outcome = 'granted' and l.renewal_price_id is null
	and o.stripe_price_id is not null and o.price_currency = 'cad' and o.price_amount >= 50;
