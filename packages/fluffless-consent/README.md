# Fluffless Consent

Extension gratuite (GPL-2.0-or-later) : une bannière de consentement légère, affichée seulement quand un service non essentiel est déclaré. Rien des services déclarés ni des contenus intégrés de la liste ne se charge avant un oui, et refuser est aussi simple qu'accepter. Elle fonctionne avec n'importe quel thème ; le thème Fluffless et Fluffless Suite placent son lien « Gérer mes témoins » dans le pied de page.

Réglages : page de l'extension sous **Réglages** (`options-general.php?page=fluffless-consent`).

## Ce qu'elle fait

- Aucun service déclaré : aucun octet ajouté, aucune bannière.
- Au moins un service déclaré : le HTML de la bannière, quelques lignes de CSS en ligne (jetons de couleur du thème, couleurs système en repli) et `assets/consent/consent.js` en `defer`, 2 ko compressés au plus (plafond vérifié par les tests).
- Choix par catégorie (mesure d'audience, publicité, contenu intégré), aucune case précochée, « Refuser tout » au même niveau que « Accepter tout ».
- Choix gardé dans le navigateur (stockage local, aucun témoin posé par le site), de 1 à 13 mois (6 par défaut). Une nouvelle liste de services ou un nouveau profil redemande le choix.
- Profils juridiques Québec (Loi 25, par défaut), reste du Canada (LPRPDE) et Union européenne (RGPD) : texte de la bannière et section proposée dans l'outil de politique de confidentialité de WordPress, avec la consigne d'EFVP pour le Québec.
- Petit écran : au plus 40 % de la hauteur, texte resserré, boutons collés au bas du panneau, cibles de 44 px, focus visible. Aucune animation.

## Ce qu'elle ne prouve pas et ne couvre pas

- Aucun journal de preuve : le choix reste dans le navigateur de la personne et rien n'est consigné sur ton serveur. Si ta loi t'oblige à démontrer un consentement (le RGPD, article 7, par exemple), l'extension ne fournit pas cette trace : prévois un autre moyen de la garder.
- Pas de Google Consent Mode v2 : les signaux `ad_storage`, `analytics_storage`, `ad_user_data` et `ad_personalization` ne sont pas envoyés. Google Analytics 4 se charge seulement après un oui et n'envoie rien avant ; la modélisation des visites refusées n'est pas disponible.
- Un Google Analytics ou une autre balise installés ailleurs que par l'extension (thème, autre extension, code collé) échappent à son contrôle.
- Les textes des profils (Québec, reste du Canada, Union européenne) servent de point de départ et ne remplacent pas un avis juridique.

## Contenus intégrés (iframe, embed)

Le serveur remplace toujours les iframes et embeds de services connus (YouTube, Vimeo, cartes OpenStreetMap et Google, réseaux sociaux, etc.) par un espace réservé avec un lien direct vers le contenu. Il ne lit jamais de témoin : le HTML est identique pour tout le monde, donc compatible avec les caches de page. Après un oui à la catégorie « media », `consent.js` recrée l'iframe. Pour les services de la liste, il n'y a ni requête tierce ni témoin tiers avant ce oui.

- Le service implicite « contenu intégré » (catégorie media) fait apparaître la bannière dès qu'un contenu publié ou rendu en porte un.
- Hôtes inconnus : non touchés, donc chargés sans consentement (un formulaire Google ou un Typeform collé dans une page, par exemple). Seuls les services de la liste sont couverts. Le filtre `fluffless_consent_embed_hosts` en ajoute (`hote` ou `hote/chemin`).
- Une iframe avec `srcdoc` n'est pas touchée (contenu en ligne, sans adresse tierce).
- Une iframe collée dans du HTML libre n'est attrapée que pour les hôtes connus.
- Un embed ajouté après le chargement de la page (AJAX, fenêtre modale) n'est pas vu par le serveur : il reste tel quel, sans consentement. Le filtre ne traite que le HTML rendu par WordPress.
- Limites nommées (K1e) : les scripts émis hors du contenu (`wp_head`, `wp_footer` d'une autre extension, `wp_print_inline_script_tag`, thème de blocs) ne sont pas touchés ; un fragment Meta Pixel (chargeur `fbevents.js` écrit en ligne, `<noscript><img src="https://www.facebook.com/tr…">`) et les `<img>`, `<audio>` ou `srcset` d'hôtes de la liste ne sont pas neutralisés ; `FB.init` et les chargeurs écrits en ligne ne sont pas réactivés par un script suivant l'autre ; la description courte WooCommerce et les descriptions de termes en contexte classique ne passent pas par le filtre.
- Rendu par `admin-ajax.php` ou par l'API REST : le filtre se branche sur l'action `wp` (page vue par un visiteur), qui ne tourne pas dans ces requêtes. Un contenu intégré rendu par ces voies est chargé sans consentement.
- Les hôtes encodés en pourcentage (`www.you%74ube.com`) et en pleine chasse sont lus comme le navigateur (conversion UTS46 avec l'extension intl; sans elle, tout hôte non ASCII est retiré). `https:www.youtube.com/…` (sans barres) est lu comme `https://www.youtube.com/…`. Les contenus écrits par des rôles qui ont `unfiltered_html` contournent ces formes : le risque dépend de l'absence de tels rôles non fiables.
- Une vidéo de couverture (`core/cover`) d'un hôte de la liste est remplacée par un lien « Ouvrir sur … » ; le texte du bloc reste.
- Le contenu intégré posé dans une page ou un modèle (hors d'un article publié) n'est pas vu à l'enregistrement : la première visite qui en rend un pose l'option `fluffless_consent_embeds` (écriture pendant une requête anonyme) et affiche la bannière en pied de page ; les visites suivantes la placent en tête. L'option est retirée à la désinstallation.
- Sans JavaScript, le lien direct de l'espace réservé reste lisible.

## Déclarer un service

Google Analytics : l'identifiant GA4 dans les réglages. Tout autre service, depuis un thème ou une extension :

```php
add_filter( 'fluffless_consent_services', fn( $services ) => $services + array(
	'youtube' => array(
		'category' => 'media', // analytics, marketing ou media
		'label'    => 'YouTube',
		'location' => 'United States',
		'src'      => 'https://…', // facultatif : script chargé après un oui (https seulement)
	),
) );
```

Un script inerte `<script type="text/plain" data-fluffless-consent="media">` est aussi activé après un oui, et l'événement DOM `fluffless:consent` annonce les catégories acceptées. Au plus 20 services ; une entrée invalide est écartée.

## Déclarer un réglage gardé dans le navigateur

Un réglage d'affichage que la personne qui visite choisit elle-même, gardé dans son navigateur (le choix du mode sombre de Fluffless Suite, par exemple), se déclare à part : il figure dans la section proposée de la politique de confidentialité, sans faire apparaître la bannière. Ce filtre est réservé à ces réglages, écrits seulement après un choix de la personne : jamais un identifiant, une mesure ou du suivi, qui restent des services à déclarer avec `fluffless_consent_services`. Vérifier que l'exemption s'applique selon le profil juridique du site.

```php
add_filter( 'fluffless_consent_storage', fn( $items ) => $items + array(
	'mon-theme-taille-texte' => array( 'label' => 'Taille du texte' ), // clé du stockage local
) );
```

Clé de 1 à 64 caractères (lettres, chiffres, `_ . : -`, pas seulement des chiffres), libellé obligatoire, au plus 20 entrées.

## Lien « Gérer mes témoins »

Code court `[fluffless_consent_link]` ou fonction `fluffless_consent_link()` ; les deux ne rendent rien sans service déclaré. Si la page n'en rend aucun, le lien est ajouté au bas de la page, pour que le retrait du consentement reste possible.

## Réglages partagés

Option `fluffless_settings`, section `consent` (`profile`, `ga_id`, `retention_months`) : la même que l'ancien module Consentement de Fluffless Suite, donc passer de l'un à l'autre garde les réglages. L'écran de l'extension n'écrit que cette section, et Fluffless Suite la conserve sans y toucher.

## Requêtes sortantes

Aucune côté serveur. Côté visiteur, seulement les services acceptés.

## Désinstallation

Supprimer l'extension retire la section `consent` ; l'option entière disparaît seulement si rien d'autre n'y reste. La désactivation seule ne supprime rien.

Pour tout garder, ajouter `define( 'FLUFFLESS_KEEP_DATA', true );` à `wp-config.php` avant la suppression, ou cocher « Garder mes données » dans le module Formulaire de Fluffless Suite : l'extension lit les deux, comme Fluffless Suite. Une constante à `false` ne garde rien.

Supprimer Fluffless Suite ne touche pas à la section `consent` : la Suite efface ses propres réglages et laisse dans `fluffless_settings` les sections qu'elle ne gère pas (`Options::FOREIGN`). Si elle garde ses données (`FLUFFLESS_KEEP_DATA` ou « Garder mes données »), l'option reste telle quelle.

## Tests

```sh
npm run test:tools
```

`tests/consent-test.php` (WP-CLI) : services déclarés et écartés, aucune ressource tierce avant un oui, bannière et lien, profils, réglages et section partagée (45 contrôles). `tests/consent-test.mjs` (Node) : première visite, refus, réouverture, acceptation, retrait, version, Échap, défilement (18 contrôles).
