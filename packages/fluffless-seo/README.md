# Fluffless SEO

Extension optionnelle de Fluffless Suite : référencement complet, écrit côté serveur, pour n'importe quel thème. Aucun script pour les visiteurs.

Réglages : **Réglages > Fluffless SEO**. Quand elle est active, le module SEO léger de Fluffless Suite se met en retrait (constante `FLUFFLESS_SEO_VERSION`).

## Ce qu'elle fait

- Panneau « SEO » de l'éditeur : titre et description avec aperçu et largeur estimée en pixels, adresse canonique, directives robots, partage (Open Graph, X), type de données structurées, analyse locale du mot-clé.
- Gabarits de titre et de description par type de contenu, taxonomie, archive, recherche et 404, avec variables (`%%title%%`, `%%sitename%%`, `%%sep%%`, `%%excerpt%%`, `%%term%%`...).
- Graphe JSON-LD : WebSite, Organization, LocalBusiness ou Person, WebPage, Article, BreadcrumbList, FAQPage (blocs Détails dont le résumé finit par « ? »), Service, Event, Product, SoftwareApplication (catégorie, système, prix, 0 compris), Person. Le filtre `fluffless_seo_graph` reçoit le graphe et l'identifiant du contenu ; ce qui n'est pas un nœud est retiré.
- Bloc serveur `fluffless-seo/fil-ariane`.
- Plan de site natif ajusté (exclusions, lastmod, noindex retiré). Les adresses du plan répondent 200 et non 404 (`pre_handle_404`, WordPress 7.1).
- Redirections 301, 302, 307, 410, avec regex ; journal des adresses introuvables (chemin, compteur, dernière visite ; ni IP ni agent utilisateur), purgé après 90 jours et plafonné à 5000 adresses (les moins vues cèdent leur place, filtre `fluffless_seo_404_max_rows`) ; importation CSV.
- robots.txt et llms.txt virtuels, balises de vérification, IndexNow (éteint par défaut).
- hreflang et og:locale avec Polylang, avec x-default vers la langue choisie dans les réglages (écrit seulement si la page existe dans cette langue).
- Importation depuis Yoast SEO, SEOPress et Rank Math (simulation d'abord, données d'origine gardées).

## Requête sortante

IndexNow, s'il est allumé, envoie une requête `POST` à `https://api.indexnow.org/indexnow` à chaque publication d'un contenu public, sans attendre la réponse. Aucune autre requête sortante. Pas d'envoi sur un site privé ni en environnement `local` ou `development`.

## Ligne de commande

```
wp fluffless-seo redirect list|add|delete|import
wp fluffless-seo 404 list|purge [--all]
wp fluffless-seo import <yoast|seopress|rankmath> [--apply]
```

## Désinstallation

WordPress ne propose pas d'étape de confirmation à la suppression d'une extension. **Supprimer Fluffless SEO efface** son option `fluffless_seo_settings`, ses deux tables (redirections et journal) et ses champs de contenu `_fluffless_seo_*`. La description `_fluffless_description`, partagée avec Fluffless Suite, est gardée.

Pour tout garder, cocher « Garder mes données à la suppression de l'extension » dans Réglages > Fluffless SEO (groupe Crawlers), ou ajouter avant la suppression dans `wp-config.php` :

```php
define( 'FLUFFLESS_SEO_KEEP_DATA', true );
```

La désactivation seule ne supprime rien.

En multisite, la suppression nettoie chaque site un par un (au plus 10 000). Un site qui a coché « Garder mes données » garde les siennes.

## Limites connues

- Dépend de Fluffless Suite (`Requires Plugins: fluffless-suite`). La Suite n'est pas sur WordPress.org : les deux extensions se distribuent ensemble depuis fluffless.dev. Une soumission de SEO à WordPress.org demanderait d'abord de la rendre autonome (audit L4).
- Pas d'images dans le plan de site : le rendu natif de WordPress n'accepte que `loc`, `lastmod`, `changefreq` et `priority`.
- Pas de réglage SEO par terme de taxonomie : les gabarits s'appliquent.
- Pas d'édition en masse.
- Les champs du panneau passent par l'API REST : un type de contenu personnalisé doit déclarer le support `custom-fields`.
