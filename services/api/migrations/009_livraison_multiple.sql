-- Une offre livre une ou plusieurs extensions (Suite + SEO : deux paquets). La licence garde la
-- liste reçue à l'achat : changer l'offre plus tard ne retire rien à qui a déjà payé.
-- licences.release_slugs nul : licence créée à la main avant cette migration ou sans liste;
-- elle reçoit les mises à jour de toute extension et la page de téléchargement lui donne la Suite.
-- La contrainte relit la liste jointe par des virgules, puis la redécoupe : un nom qui contient
-- une virgule, ou un élément nul, donne un compte différent et la ligne est refusée.

alter table licence_offers add column release_slugs text[];
update licence_offers set release_slugs = array[release_slug];
alter table licence_offers
	alter column release_slugs set not null,
	alter column release_slugs set default array['fluffless-suite'],
	add constraint licence_offers_release_slugs_check check (
		cardinality(release_slugs) between 1 and 5
		and array_to_string(release_slugs, ',') ~ '^[a-z0-9-]{1,64}(,[a-z0-9-]{1,64}){0,4}$'
		and cardinality(string_to_array(array_to_string(release_slugs, ','), ',')) = cardinality(release_slugs)
	);

alter table licences add column release_slugs text[] check (
	release_slugs is null or (
		cardinality(release_slugs) between 1 and 5
		and array_to_string(release_slugs, ',') ~ '^[a-z0-9-]{1,64}(,[a-z0-9-]{1,64}){0,4}$'
		and cardinality(string_to_array(array_to_string(release_slugs, ','), ',')) = cardinality(release_slugs)
	)
);
update licences l set release_slugs = array[o.release_slug]
from licence_purchases p join licence_offers o on o.id = p.offer_id
where p.licence_id = l.id;

alter table licence_offers drop column release_slug;
