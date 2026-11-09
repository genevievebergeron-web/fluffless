# Fluffless Suite

Extension payante de la suite Fluffless WP : modules de sobriété numérique, blocs `fluffless/*`, styles de blocs et 78 patterns.

Patterns et styles de blocs s'enregistrent seulement sous le thème Fluffless ; un pattern dont l'en-tête `Fluffless Modules` nomme un module inactif (ex. `expression` pour Vague, Récit défilant et Pop-up) n'est pas proposé, pour ne jamais insérer un bloc non enregistré ; les pieds de page enrichis de Suite remplacent ceux du thème sous les mêmes slugs. Sans Suite, le thème garde 20 patterns en blocs natifs.

La bannière de consentement est une extension gratuite à part, **Fluffless Consent** (`packages/fluffless-consent`). Le bloc Barre légale affiche son lien « Gérer mes témoins » si elle est active (`fluffless_consent_link()`), et l'écran de réglages recommande de l'installer quand un service non essentiel est déclaré sans bannière.

## Bloc Icône (`fluffless/icone`)

Icône Lucide rendue au serveur en SVG en ligne : aucun JavaScript ni aucune requête côté visiteur.

```html
<!-- wp:fluffless/icone {"icon":"leaf","size":"large","textColor":"accent"} /-->
```

| Attribut | Valeurs | Défaut |
|---|---|---|
| `icon` | nom Lucide en kebab-case, parmi la liste curée | `sparkles` |
| `size` | `small`, `medium`, `large`, `xl` | `medium` |
| `label` | texte alternatif ; vide = icône décorative (`aria-hidden`) | `""` |

Couleurs : palette seulement (`textColor`, `backgroundColor`). Alignement gauche, centre, droite. Marges.

Un nom inconnu ou invalide ne rend rien (un commentaire HTML seulement si `WP_DEBUG`).

### Liste d'icônes

163 icônes en 14 catégories, générées dans `blocks/icone/icons.php` depuis le paquet npm `lucide-static` (version épinglée dans le `package.json` racine). Pour ajouter une icône : l'inscrire dans `CATEGORIES` de `tools/build-icons.mjs`, puis depuis la racine du monorepo :

```sh
npm install
npm run icons:build
```

Le script échoue si un nom n'existe pas dans la version installée ou si un nœud SVG sort de la liste blanche (balises de forme, attributs géométriques). La liste n'est envoyée qu'à l'éditeur (script en ligne sur `enqueue_block_editor_assets`).

Licence des icônes : ISC (Lucide) et MIT (Feather), voir `blocks/icone/LICENSE`.

### Test

```sh
docker compose run --rm -T cli wp plugin activate fluffless-suite
npm run test:icone
```

Le test (`tests/icone-render-test.php`) sort avec un code non nul au premier contrôle raté.

## Noyau et modules (phase 2)

```
src/Core/    Module (interface), Options, Sanitize, Loader, Http, Lifecycle
src/Modules/ Cleanup, Images, Visibility
src/Admin/   SettingsPage (Fluffless → Modules, Settings API native)
assets/      visibility-editor.js (éditeur seulement, sans build)
uninstall.php
```

**Une seule option**, `fluffless_settings` (autoload), versionnée (`version`) :

```php
array(
	'version' => 1,
	'modules' => array( 'cleanup' => true, 'images' => true, 'visibility' => false, 'http' => false ),
	'cleanup' => array( 'emoji' => true, ... ),
	'images'  => array( 'output_format' => 'webp', 'remove_sizes' => array( ... ), 'warn_kb' => 400, 'max_kb' => 2048 ),
	'http'    => array( 'allowed_hosts' => array( 'wordpress.org', 'fluffless.dev' ) ),
)
```

Lecture typée : `Options::get_bool( 'cleanup', 'emoji' )`, `get_int`, `get_string`, `get_list`, `is_enabled( 'images' )`. Toute valeur absente ou hors liste reprend son défaut ; les clés inconnues sont écartées. L'option est déclarée à la Settings API (`admin_init`) et à l'API REST (`rest_api_init`, schéma dans `/wp/v2/settings`), jamais sur les pages vues par le visiteur. La section `consent` appartient à Fluffless Consent : Suite la garde telle quelle à l'enregistrement et l'admet dans son schéma REST (`Options::FOREIGN`), sans l'assainir.

| Module | Par défaut | Ce qu'il fait |
|---|---|---|
| Nettoyage (`cleanup`) | actif | Six interrupteurs : émojis (scripts, styles, TinyMCE, DNS prefetch s.w.org, flux, courriels), découverte oEmbed et wp-embed, liens RSD/wlwmanifest/shortlink/generator, fils RSS des commentaires, heartbeat hors éditeurs (`post.php`, `post-new.php`, `site-editor.php`), XML-RPC. Les styles globaux ne sont jamais touchés. |
| Images (`images`) | actif | JPEG et PNG redimensionnés en WebP (AVIF si l'éditeur d'image du serveur l'écrit, sinon WebP). Tailles du cœur non générées : `medium_large`, `1536x1536`, `2048x2048` par défaut ; celles du thème ou d'une extension sont toujours gardées. Avertissement au-delà de 400 ko, refus au-delà de 2 048 ko (réglables). |
| Visibilité (`visibility`) | inactif | Choix « Afficher sur » dans chaque bloc. Rendu serveur : classe `fluffless-hide-mobile` (Ordinateur seulement) ou `fluffless-hide-desktop` (Mobile seulement), et 2 règles CSS en ligne ajoutées seulement si un bloc de la page s'en sert. Point de rupture unique : 782 px (celui où WordPress empile ses colonnes). |
| Canevas (`canvas`, requiert `shape`) | inactif | Pour les graphistes (mode Studio, ou `edit_theme_options` sans le module Forme) : image de fond native (position, taille, répétition, fixe) ajoutée à Colonnes, Colonne, Détails et Média et texte ; fond propre au petit écran (autre image ou aucune) sous 782 px ; bloc `fluffless/calque` (image de la médiathèque ancrée par son centre ou un coin, position en %, largeur en % de la section (cqi) bornée en px ou rem, position et largeur propres sous 782 px, rotation, opacité, fusion, derrière ou devant, masquable sur mobile, découpe aux bords de la section avec marge en hauteur ou débordement vertical, jamais latéral), rendu serveur dans une couche absolue qui ne prend aucune place en flux, flex ou grille et ne découpe jamais la section elle-même, aucun JS visiteur ; mesures : `node tools/reports/audit/ui/measure-canvas.mjs <id>` ; bouton Visuel / HTML dans l'en-tête de l'éditeur et « Edit as HTML » dans la barre d'outils des sections contentOnly. CSS en ligne seulement si la page s'en sert (1 622 o calques, 581 o gzip ; 144 o fond mobile). Inactif par défaut : il change le rendu public. |
| Expressivité (`expression`) | inactif | Bloc `fluffless/vague` : forme entre deux sections (vague, courbe douce, pics, pente), en haut ou en bas, miroir horizontal, hauteur de 16 à 240 px, couleur limitée à la palette du thème. SVG en ligne décoratif (`aria-hidden`), rendu serveur, aucun JS visiteur ; CSS en ligne sous 1 ko seulement si la page s'en sert. Bloc `fluffless/recit` (Récit défilant) : scène collante (premier groupe, à gauche ou à droite dès 782 px) et étapes (enfants du second groupe) qui entrent au défilement, montée et fondu, fondu seul ou aucun effet ; `animation-timeline: view()` sous `@supports`, ailleurs repli `IntersectionObserver` en ligne (moins de 1,2 ko) chargé seulement pour un récit animé ; tout ce qui masque vit sous `prefers-reduced-motion: no-preference`, donc rien n'est caché sans JS ni en mouvement réduit ; éditeur sans animation. Bloc `fluffless/popup`, 0 JS, ouverture au clic seulement (aucune option d'ouverture automatique) : fenêtre (`<button popovertarget>` et panneau `popover`, fermée par Échap, clic à l'extérieur ou bouton de fermeture ; sans prise en charge de `popover`, le contenu reste affiché dans la page) ou bandeau d'annonce (`<dialog open>` refermé par `<form method="dialog">`) ; libellés en texte seul, 80 caractères au plus ; ids uniques par rendu. Inactif par défaut : il change le rendu public. Bloc `fluffless/flottant` (Élément flottant, `Blocks\Flottant`) : un seul par page, fixé dans un coin (calque `position: fixed` sans capture des clics, zones sûres `env(safe-area-inset-*)`, z-index 10 sous le panneau de menu, l'entête collant et le bandeau de Consent), trois modes (bouton en pilule avec icône de la liste curée et barre pleine largeur sous 600 px, sceau dont le texte tourne par `Ring::svg`, pastille d'état qui pulse), couleur de la palette et texte apparié à 4,5:1 par `Palette::ratio`, adresses relatives, http, https, mailto et tel seulement, masqué à l'impression, immobile en mouvement réduit, cible de 44 px ; la feuille en ligne ne contient que la part du mode rendu (1 033 à 1 533 o). Bloc `fluffless/bandeau` (Mots-clés qui défilent, `Blocks\Bandeau`) : 2 à 12 mots de 60 caractères au plus dans une vraie liste `ul` doublée d'une copie `aria-hidden`, translation CSS de 0 à -50 % posée à l'écran sous `no-preference` seulement, durée proportionnelle au texte (10 à 180 s), pause au survol, au focus et par un bouton Pause sans script (case à cocher et `label`, cible de 44 px), liste repliée en mouvement réduit et à l'impression, liste seule dans les extraits et les flux, trois allures (bande accent avec texte apparié à 4,5:1, filets, lettres en contour), couleur de la palette ; feuille de base et feuille par allure, 1 157 à 1 328 o. Bloc `fluffless/graphique` (Graphique, `Blocks\Graphique`) : secteurs, anneau ou barres pour 2 à 8 rangées (étiquette de 40 caractères au plus, valeur positive ou nulle), dessin unique `aria-hidden` en `conic-gradient` calculé en PHP (parts entières par plus grands restes, total de 100, anneau par `mask`), barres en pourcentage du maximum par une variable CSS numérique, couleurs en suite fixe de la palette puis teintes `color-mix`, légende en vrai tableau visible (pastille, étiquette, valeur, part) avec nombres par `number_format_i18n`, rendu vide si le total est nul ou s'il reste moins de deux rangées valides, dessin masqué en couleurs forcées, `print-color-adjust: exact` à l'impression, tableau seul dans les extraits et les flux ; feuille de base et feuille par type, 648 à 837 o. |
| Requêtes sortantes (`http`) | inactif | Liste blanche sur `pre_http_request`. Sous-domaines inclus ; le site lui-même est toujours permis. Les 20 derniers domaines refusés sont gardés une semaine et affichés dans les réglages. |

**Crochets**

| Crochet | Type | Usage |
|---|---|---|
| `fluffless_modules` | filtre | `array<id, class-string<Module>>` : ajouter ou retirer un module. La clé doit égaler `id()`. |
| `fluffless_loaded` | action | Reçoit la liste des modules actifs, une fois enregistrés. |
| `fluffless_visibility_breakpoint` | filtre | Point de rupture de la Visibilité, en px (320 à 3000). |

Ajouter un module : une classe qui implémente `Fluffless\Suite\Core\Module` (métadonnées statiques, `register()` qui pose seulement des crochets), puis une ligne dans le filtre. Les champs déclarés dans `fields()` (`bool`, `int`, `choice`, `multi`, `hosts`) donnent à la fois l'assainissement, le schéma REST et l'écran de réglages.

**Cycle de vie** : l'activation écrit les défauts si l'option n'existe pas ; la désactivation retire les transitoires (`fluffless_http_refusals`, `fluffless_upload_notices`) ; la désinstallation retire l'option et les transitoires, sur chaque site d'un réseau.

**Limites connues**
- L'avertissement d'image lourde s'affiche au prochain écran d'administration classique, pas dans l'éditeur de blocs (le refus, lui, est immédiat).
- La désinstallation efface les réglages de la Suite et garde dans l'option la section `consent` de Fluffless Consent (`Options::FOREIGN`).
- XML-RPC coupé : les méthodes d'introspection `system.*`, ajoutées par la bibliothèque IXR elle-même, répondent encore.
- Les réglages écrits par `update_option()` hors de l'administration et de l'API REST ne passent pas par l'assainissement ; la lecture (`Options::all()`) filtre quand même les types.

### Constantes de `wp-config.php`

Toutes sont facultatives et se placent avant la ligne « That's all, stop editing! ». Une constante l'emporte sur le réglage affiché à l'écran; les secrets ne se lisent que dans une constante ou l'environnement, jamais dans la base de données.

| Constante | Rôle |
|---|---|
| `FLUFFLESS_LICENCE_KEY` | Clé de licence (mises à jour et soutien). Gagne sur la clé saisie dans Fluffless > Modules. |
| `FLUFFLESS_SMTP_PASSWORD` | Mot de passe du module Envoi de courriel; la variable d'environnement du même nom fonctionne aussi. |
| `FLUFFLESS_STATUS_KEY` | Clé de la route d'état en lecture seule (16 caractères au moins), envoyée dans l'en-tête `X-Fluffless-Key`; variable d'environnement acceptée. |
| `FLUFFLESS_STATS_API_KEY` | Clé d'API de ton instance de statistiques, pour le rapport carbone; variable d'environnement acceptée. |
| `FLUFFLESS_PEXELS_KEY` | Clé d'API Pexels gratuite, pour le module Photos Pexels. Constante seulement. |
| `FLUFFLESS_FONT_BANK` | Adresse HTTPS de la banque de polices du module Polices libres; chaîne vide : chaque fichier vient de son hôte d'origine. |
| `FLUFFLESS_KEEP_DATA` | `true` : toutes les données sont gardées à la suppression de l'extension, et Fluffless Consent garde aussi sa section. `false` ou absente : rien n'est gardé par la constante. |
| `FLUFFLESS_API_URL` | Adresse de l'API Fluffless, pour un serveur d'essai ou de préproduction. |
| `FLUFFLESS_SIGNING_PUBLIC_KEY` | Remplace la clé publique qui vérifie la signature des mises à jour. Sans clé valide, aucune mise à jour n'est proposée. |
| `FLUFFLESS_ALLOW_UNSIGNED` | `true` : accepte des paquets non signés. Pris en compte seulement quand le type d'environnement est `local` ou `development`; ailleurs, ignoré avec un avis dans l'administration. |

Fluffless SEO ajoute `FLUFFLESS_SEO_KEEP_DATA`, lue de la même façon : la valeur compte (voir son README).

### Tests du noyau

```sh
npm run test:tools
```

`tests/tools-test.php` : assainissement, Loader (module désactivé = ni instance ni crochet), Nettoyage (`wp_head` sans émojis ni liens hérités), Images (fichier JPEG réellement réécrit en WebP, tailles, seuils), Visibilité (classes, CSS chargé seulement si utilisé), liste blanche HTTP, rendu de l'écran de réglages (nonce, capacité), schéma REST, activation et désinstallation. L'option est sauvegardée puis restaurée.
