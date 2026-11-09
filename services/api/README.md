# Serveur Fluffless (`services/api`)

Serveur des mises à jour signées, des licences et des signalements « Report a fluff »
de la suite Fluffless. Hébergé chez canner.ca (projet `fluffless-api`), données au Canada.

- Node 20 ou plus, `node:http`, deux dépendances épinglées : `postgres` 3.4.9 (client SQL)
  et `nodemailer` 10.0.9 (soumission SMTP Proton, aucune dépendance transitive).
- Postgres fourni par canner (`DATABASE_URL` injectée). Migrations SQL dans `migrations/`,
  appliquées au démarrage, une fois chacune, sous verrou consultatif.
- Zip des versions et captures d'écran stockés en `bytea` : une seule chose à sauvegarder, la base.

## Routes

| Méthode et chemin | Rôle |
| --- | --- |
| `GET /health` | État du service et de la base |
| `GET /v1/update?slug=&version=&channel=&site=&dev=` | 200 fiche de mise à jour avec `manifest` et `manifest_signature` (ed25519, signés à la publication), 204 à jour, 403 `{code}` (plus `expires`, `grace_until` et `renew_url` pendant la grâce d'une licence échue), 404 `not_included` (extension hors licence) |
| `GET /v1/download/…/<jeton>.zip` et `.zip.sig` | Lien signé HMAC valable 15 minutes, en-tête `X-Content-Signature` |
| `GET /v1/licences/renew?licence=&until=&token=` | Lien des rappels et de la page d'expiration : 303 vers un paiement de renouvellement de la même licence (403 si le jeton est faux ou la fin déjà changée) |
| `GET /v1/info?slug=&locale=` | Fiche publique (canal stable seulement) |
| `POST /v1/licences/verify` | `{site, dev?}` : inscrit le site, refuse au-delà de `sites_max`; un site de développement ne prend aucune place |
| `POST /v1/licences/release` | `{site}` : rend la place de ce site; idempotente; licence échue acceptée; plafond de libérations sur 30 jours (429 `release_limit`) |
| `POST /v1/reports` | Signalement, 10 par heure par IP, capture PNG ou WebP de 1 Mo au plus |
| `GET /v1/packs?locale=` | Packs obtenus ou réclamables par la licence (voir « Packs curés ») |
| `POST /v1/packs/claim` | `{packs, locale, website}` : réclame et envoie un lien par pack à l'adresse de la licence |
| `GET` et `POST /v1/packs/download/<jeton>` | Page de confirmation, puis zip signé; usage unique, 72 heures |
| `POST /v1/packs/checkout` | `{pack, locale}` : session Stripe Checkout pour acheter un pack, rend `{url}` |
| `POST /v1/licences/checkout` | `{offer, locale, website}` : session Stripe Checkout pour acheter une licence au plein prix, sans compte, rend `{url}` (voir « Vente des licences »); un champ `coupon` reçoit 400 `unknown_field` |
| `GET /v1/licences/purchase?session=cs_…` | État d'un achat de licence pour la page de succès : `unknown`, `sending`, `sent` (avec l'adresse masquée) ou `refunded`; 24 h seulement |
| `GET` et `POST /v1/licences/download` | Page où la cliente colle sa clé, puis renvoi (303) vers le lien signé de la dernière version stable |
| `POST /v1/webhooks/stripe` | Événements Stripe signés (voir « Stripe ») ; 503 si Stripe n'est pas configuré |
| `/v1/admin/*` | Jeton `ADMIN_TOKEN` en `Authorization: Bearer` |

Précisions sur le contrat partagé avec le client WordPress :

- La licence voyage dans l'en-tête `X-Fluffless-Licence` sur `/v1/update`, `/v1/info` (facultative)
  et `/v1/licences/verify`. Un paramètre `licence` dans la chaîne de requête donne 400
  `licence_in_query`, et un champ `licence` dans le corps de `/verify` donne 400 `unknown_field` :
  une URL finit dans les journaux des mandataires, un en-tête non.
- `site` : SHA-256 hexadécimal (minuscules)
  de l'adresse `home_url()` normalisée ainsi, dans l'ordre : schéma retiré (`https://`, `http://`) ;
  hôte en minuscules ; port retiré s'il vaut celui du schéma (443 pour https, 80 pour http), gardé
  sinon sous la forme `:8080` ; chemin gardé tel quel (casse comprise), sans barre finale ; `www.`
  gardé. Pas de requête ni de fragment. Exemples : `https://WWW.Exemple.ca:443/Blogue/` donne
  `www.exemple.ca/Blogue` ; `http://localhost:8080` donne `localhost:8080`. Référence :
  `Api::site_identity()`. Le serveur ne recalcule rien à partir d'une URL : il ne voit que
  l'empreinte, et deux clients qui normalisent différemment occupent deux places. Les deux
  vecteurs sont vérifiés par `npm test`.

- `/v1/licences/verify` répond toujours 200 ; un refus donne `valid: false` et un champ `code`
  (`licence_invalid`, `licence_revoked`, `licence_expired`, `site_limit`, `dev_limit`,
  `licence_review`) en plus des champs du contrat. Une clé révoquée rend `licence_revoked` sur
  `/verify` et `/v1/update`; une clé inconnue garde `licence_invalid`. Une Suite qui ne connaît pas
  `licence_revoked` retombe sur son message de clé invalide.
- Licence illimitée (`sites_max` à `UNLIMITED_SITES` dans `app.mjs`) : les réponses portent
  `sites_max: null` et `unlimited: true`, jamais le plafond interne. À ce plafond, un nouveau site
  reçoit `licence_review` (et non `site_limit` : il n'y a rien à acheter), les sites inscrits restent
  couverts, et une alerte « Licence à examiner » part une seule fois (journal `licence_flags`,
  règle `ceiling`). L'alerte ne porte que l'id de licence et des comptes.
- Site de développement : la Suite, seule à voir l'adresse, envoie `"dev": true` à `/verify` et
  `dev=1` à `/v1/update` quand l'hôte est local (`localhost`, `.localhost`, `.local`, `.test`) ou
  que WordPress se déclare en préproduction. Le site ne prend alors aucune place : il s'inscrit à
  part (`licence_dev_sites`), et une place prise par ce même site avant la règle se libère. La
  réponse ajoute `dev: true`. Un plafond interne borne ces sites (`DEV_SITES_MAX` dans `app.mjs`,
  jamais renvoyé ni affiché); au-delà, `dev_limit`. Toute autre valeur de `dev` donne 400. Le
  drapeau vient du client : c'est une règle d'usage, pas un contrôle. Le dernier contact de chaque
  site (`last_seen_at`) s'écrit au plus une fois par jour. Ordre de déploiement : l'API avant la
  Suite qui envoie `dev`, puisque l'ancienne `/verify` refuse tout champ inconnu.
- `/v1/licences/release` (licence en en-tête, corps `{site}`) rend la place de ce site, ou retire
  son inscription de développement, et répond `{released, sites_used, sites_max}`. Un site sans
  place répond `released: false` et ne compte pas. Une licence échue est acceptée (ménage avant
  le renouvellement); une clé absente, inconnue ou révoquée donne 403 `licence_invalid`. Plafond :
  `max(3, sites_max)` places rendues sur 30 jours glissants, borné à 50; au-delà, 429
  `release_limit` avec `Retry-After` et `retry_at`. Le verrou de ligne de la licence tient le
  plafond sous des appels simultanés.
- `/v1/update` vérifie la licence avant de comparer les versions, et inscrit le site au passage
  comme le fait `/verify`. Une licence absente donne 403 `licence_invalid` ; un slug sans version publiée, 404.
  Une licence qui porte une liste d'extensions (`release_slugs`, posée à l'achat ou à la création)
  ne reçoit que celles-là : pour une autre, 404 `not_included`, et non 403, que la Suite installée
  lirait comme une clé invalide. Ce 404 ne s'affiche nulle part : une cliente Suite seule qui
  installe la SEO la voit rester à sa version, sans avis (à savoir pour le support). Sans liste
  (`--release all` à la création, ou licence créée à la main avant la migration 009), toutes.
- `package` porte le jeton dans le chemin (`…/<expiration>/<hmac>.zip`), pour que `<package>.sig`
  soit une URL valide par simple concaténation.
- Signature : ed25519 détachée de 64 octets en base64, sur les octets exacts du zip.
  Côté PHP : `sodium_crypto_sign_verify_detached( base64_decode( $sig ), $zip, base64_decode( CLE_PUBLIQUE ) )`.
- Ajouts hors contrat, réservés à l'administration : `DELETE /v1/admin/releases/:slug/:version`,
  `DELETE /v1/admin/licences/:id` (409 `licence_has_packs` si la licence a des packs, sauf avec
  `?with_packs=1`), `POST /v1/admin/licences/:id/rotate` (nouvelle clé, mêmes packs et sites),
  `PATCH /v1/admin/licences/:id` (`{email}`), `POST /v1/admin/licences/:id/resend` (`{email?}`,
  licence achetée seulement : nouvelle clé envoyée par courriel), `GET` et `POST /v1/admin/licence-offers`,
  `PUT /v1/admin/licence-offers/:slug/price` (`{price_id}` ou `null`), `DELETE /v1/admin/reports/:id`, `GET /v1/admin/reports/:id/screenshot`.
- Statuts de signalement : `nouveau`, `vu`, `réglé` (avec accent ; le CLI accepte `regle`).

## Packs curés

Deux branches codées : la réclamation
gratuite et l'achat par Stripe (voir « Stripe » plus bas). `pack_claims.source` vaut `purchase`
ou `free_claim`, contrainte `UNIQUE (licence_id, pack_id)`.

Contrat pour la page catalogue de fluffless.dev, qui appelle depuis son serveur :

- La clé passe par `X-Fluffless-Licence`, comme ailleurs. La réclamation se rattache à
  `licences.id`, jamais à la clé : une clé régénérée (`rotate-licence <id>`) garde ses packs.
  Supprimer une licence qui a des packs est refusé tant qu'on ne le demande pas explicitement
  (`delete-licence <id> --with-packs`).
- Aucun champ courriel n'est accepté : le lien part **toujours** à `licences.email`. Sans adresse
  sur la licence, 409 `email_missing` et rien n'est consommé. L'adresse se corrige par
  `node bin/admin.mjs licence-email <id> <courriel>`. Les extensions d'une licence se changent
  par `licence-releases <id> <fluffless-suite,fluffless-seo|all>` (passage à Suite + SEO sur la
  même clé, ou correction).
- `GET /v1/packs` rend `{packs: [{slug, name, description, owned, claimable, claim_closes, buyable, price}]}` :
  les packs déjà obtenus, plus ceux dont la fenêtre est ouverte ou qui sont en vente si la
  licence est active. `price` vaut `{amount, currency}` (cents, avant taxes) ou `null`.
- `POST /v1/packs/checkout` `{pack, locale}` répond 201 `{url}` : le site redirige la cliente
  vers Stripe. Refus : 404 `not_for_sale`, 409 `already_owned`, `claim_open` (fenêtre gratuite
  ouverte pour cette licence : le pack ne se vend jamais pendant qu'elle peut le réclamer) ou
  `email_missing`, 403 `licence_expired`, 503 `payments_unavailable`, 502 `payment_provider_error`.
  Limite par IP comme la réclamation, et 10 sessions créées par licence par quart d'heure (les
  refus ne comptent pas).
  Licence absente, inconnue ou révoquée : 403 `licence_invalid`.
- `POST /v1/packs/claim` répond 202 `{sent, refused: [{slug, code}]}` (codes `unknown_pack`,
  `claim_closed`, `licence_expired`). Un pack déjà obtenu, gratuit ou acheté, reçoit un nouveau
  lien et l'ancien est annulé : c'est la régénération. Une licence expirée garde ses packs mais
  n'en réclame plus. Courriel impossible (SMTP absent ou en échec) : 503 `delivery_unavailable`,
  la réclamation reste, une nouvelle demande relivre.
- Concurrence : premier arrivé, premier servi. Si un achat a déjà pris la ligne, la réclamation
  envoie quand même le lien (la licence possède le pack). L'inverse, un achat qui arrive après
  une réclamation gratuite, est remboursé automatiquement (voir « Stripe »). Une réclamation
  gratuite expire aussi chez Stripe les sessions d'achat encore ouvertes pour ce pack.
- `website` est un pot de miel : rempli, la réponse a l'air normale et rien n'est fait.
- Limites : 10 appels par IP par quart d'heure sur la liste et la réclamation ensemble (une
  adresse IPv6 compte pour son /64 entier, et son /48 plafonne à 8 fois la limite) ; 5 réclamations par licence existante par quart d'heure, puis verrou de 15 min qui double à chaque
  récidive (plafond 24 h). 429 avec `Retry-After`. Compteurs en mémoire, comme les autres.
- IP de la cliente : le serveur du site envoie `X-Fluffless-Proxy: <PACKS_PROXY_TOKEN>` et
  `X-Fluffless-Client-IP: <adresse>`. Un jeton faux donne 401 `proxy_invalid`. Sans ces
  en-têtes, la limite par IP vise le site lui-même et bloque vite tout le monde.
- Lien du courriel : `GET` affiche une page avec un bouton et ne consomme rien, pour qu'un
  robot de messagerie qui suit l'URL ne brûle pas l'usage unique ; `POST` consomme le lien et
  livre le zip avec `X-Content-Signature` (ed25519, même clé que les versions). Lien utilisé,
  expiré, inconnu ou licence révoquée : 410. Un téléchargement interrompu se redemande.
- Administration : `pack`, `pack-window`, `pack-price <slug> <price_…|->` (lu chez Stripe :
  prix actif, à paiement unique, en CAD, d'au moins 0,50 $, taxes en sus; `-` retire de la
  vente), `pack-update <slug> <zip>` (remplace et re-signe le
  zip : les liens suivants livrent la version corrigée), `delete-pack` (refusé, 409, si le pack
  a déjà été obtenu : fermer sa fenêtre à la place).

Décision provisoire, à confirmer par Geneviève : les packs sont signés avec la même clé ed25519
que les versions de l'extension. Avantage : la Suite vérifie déjà cette clé. Risque : une fuite
compromet les deux canaux à la fois, et une rotation (voir plus bas) oblige à re-signer les packs
aussi (`pack-update` pour chacun).

Écarts assumés par rapport au design :

- Le jeton de lien est un aléa de 256 bits dont la base garde le HMAC, pas un jeton signé :
  l'usage unique exige une ligne en base de toute façon, et la révocation suit la licence.
- Pas de limite par valeur de clé sur `GET /v1/packs` : une clé inconnue ne coûte qu'une lecture
  d'index, et la limite par IP s'applique.
- Les verrous de réclamation et d'achat suivent la licence, pas l'IP : une personne qui détient
  la clé (agence, pigiste) peut bloquer la titulaire jusqu'à 24 h en insistant. Risque accepté :
  la clé est déjà un secret partagé, et la révoquer (`rotate`) règle le problème.

Avant d'ouvrir une première fenêtre :

- Remplir `licences.email` pour les licences Founders déjà émises (`licence-email`), sinon
  leurs réclamations répondent 409 `email_missing`.
- Vérifier si les journaux d'accès de canner enregistrent le chemin `/v1/packs/download/<jeton>` :
  si oui, un jeton non utilisé y reste lisible 72 heures.
- Tester le lien avec une messagerie d'entreprise : certains filtres suivent les liens, et un
  filtre qui soumettrait aussi le formulaire consommerait l'usage unique (la relivraison le règle).

Reste ouvert : le format d'un pack et son installation dans la Suite (le serveur livre un zip
signé sans rien supposer de son contenu), et le raccord éventuel avec le canal de mise à jour
signé (point 4 du design, toujours un prérequis explicite).

## Développement et tests

```sh
cd services/api && npm ci
npm test                       # ou, depuis la racine : npm run test:api
```

Les tests de contrat créent une base jetable sur le Postgres local (`TEST_DATABASE_URL`,
défaut `postgres://localhost:5432/postgres`) et la suppriment à la fin. Sans Postgres, ils
échouent ; `API_TEST_SKIP_DB=1` les saute en le disant.

Banc WordPress dans un conteneur : l'API bâtit les liens de paquet (`package`, téléchargement
signé) à partir de `PUBLIC_URL`. Avec `PUBLIC_URL=http://127.0.0.1:3000`, WordPress reçoit des
liens qui visent son propre conteneur et la mise à jour échoue. Pour un banc, donner à
`PUBLIC_URL` une adresse que le conteneur WordPress joint, par exemple
`http://host.docker.internal:3000` (API lancée sur l'hôte) ou le nom du service API dans le même
réseau compose (`http://api:3000`), et la même adresse dans la constante `FLUFFLESS_API_URL` du wp-config.php du banc (0b-api-07).

## Déploiement sur canner

Le projet est déployé depuis les sources (`canner_save_app`, slug `fluffless-api`) :
canner exécute `npm ci`, `npm run build` (contrôle de syntaxe), puis `npm start`.
Au démarrage puis chaque jour, le service purge les données échues (voir « Loi 25 »).
Canner écrit les variables dans `.env.production` : le service le lit au démarrage (une variable
déjà présente dans l'environnement garde la priorité). Si le démarrage échoue, le service répond
503 sur toutes les routes avec les noms des variables manquantes, jamais leurs valeurs.

Variables d'env du projet canner (modèle : `.env.example`) :

| Variable | Contenu |
| --- | --- |
| `DATABASE_URL` | Fournie par canner |
| `PUBLIC_URL` | `https://api.fluffless.dev` |
| `SIGNING_PRIVATE_KEY` | Clé privée ed25519, PKCS8 DER en base64 |
| `ADMIN_TOKEN`, `URL_SECRET`, `HASH_PEPPER` | Secrets aléatoires de 64 caractères hexadécimaux |
| `PROTON_SMTP_USER`, `PROTON_SMTP_TOKEN` | Notification des signalements et liens de packs (optionnel ; sans eux, la réclamation répond 503) |
| `PACKS_PROXY_TOKEN` | Jeton partagé avec le serveur de fluffless.dev (64 caractères hexadécimaux) |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` | Clé restreinte `rk_…` et secret `whsec_…` du point de terminaison (optionnel, tout ou rien avec les deux suivantes) |
| `STRIPE_SUCCESS_URL`, `STRIPE_CANCEL_URL` | Pages de fluffless.dev après paiement et après abandon |
| `STRIPE_SUCCESS_URL_EN`, `STRIPE_CANCEL_URL_EN` | Facultatives : les mêmes pages en anglais, pour un paiement en anglais (sinon les pages françaises) |
| `STRIPE_API_VERSION` | Optionnelle : version d'API épinglée (ex. `2025-09-30.clover`), la même que celle du point de terminaison |
| `ALERT_WEBHOOK_URL` | Facultative, vide par défaut : adresse `https` (ou `http` vers 127.0.0.1, localhost, [::1]) qui reçoit chaque alerte de paiement en POST JSON `{subject, lines, text}`. Elle porte souvent un jeton : elle n'est jamais journalisée. Si le webhook ne répond pas, la trace `alerte_webhook_echec` va au journal (niveau error, sans l'adresse) et l'alerte part par courriel |
| `REMINDERS_ENABLED` | Facultative, `0` par défaut : à `1`, une tâche quotidienne envoie les rappels d'expiration des licences (30 et 7 jours avant la fin). Écrite, éteinte tant qu'on ne l'allume pas |
| `NOTIFY_TO` | Destinataire, défaut `bonjour@genevievebergeron.ca` |
| `TRUST_PROXY` | Défaut `1` : l'IP vient de la dernière entrée de `X-Forwarded-For` (mandataire de canner). Mettre `0` si le port du service est joignable sans passer par le mandataire, sinon un en-tête forgé contourne toutes les limites |

Les secrets vivent seulement dans canner et dans le coffre Proton Drive
`Fluffless/secrets/` (un fichier par secret, droits 600). Jamais dans git.

### Domaine `api.fluffless.dev`

Vérifié le 13 septembre 2026, certificat Let's Encrypt. Enregistrement `A` vers l'adresse que donne
`canner_list_domains`, dans la zone DNS de `fluffless.dev` chez PlanetHoster. `PUBLIC_URL` vaut
`https://api.fluffless.dev` : c'est elle qui forme les liens de téléchargement. Suite autorise d'office l'hôte de l'API dans ses requêtes sortantes (`Api::host()`).

## Créer le jeton SMTP Proton (notifications)

1. Proton Mail sur le web : Réglages, puis IMAP/SMTP, puis Jetons SMTP.
2. Générer un jeton pour une adresse d'un domaine personnalisé (ex. `fluffless@genevievebergeron.ca`) ;
   Proton n'en délivre pas pour les adresses `@proton.me`.
3. Copier le jeton, qui ne s'affiche qu'une fois, dans les variables canner :
   `PROTON_SMTP_USER` = l'adresse, `PROTON_SMTP_TOKEN` = le jeton (cocher « sensible »).
4. Le service envoie par `smtp.protonmail.ch:587` en STARTTLS. Sans ces variables, le
   signalement est stocké quand même et le journal indique `notification_absente`.

## Administration (CLI)

```sh
export FLUFFLESS_API_URL=https://api.fluffless.dev
export FLUFFLESS_ADMIN_TOKEN_FILE="$HOME/Library/CloudStorage/ProtonDrive-bonjour@genevievebergeron.ca-folder/Fluffless/secrets/ADMIN_TOKEN"
node bin/admin.mjs licence --plan solo --sites 1 --expires 2027-09-12
node bin/admin.mjs release dist/fluffless-suite-0.2.0.zip release.json
node bin/admin.mjs reports --status nouveau
node bin/admin.mjs report-status <id> regle
node bin/admin.mjs offer suite-solo --plan solo --sites 1 --days 365
node bin/admin.mjs offer suite-seo-solo --plan solo --sites 1 --days 365 --release fluffless-suite,fluffless-seo
node bin/admin.mjs offer-price suite-solo price_…
node bin/admin.mjs offers
node bin/admin.mjs licence-resend <id> --email nouvelle@exemple.ca
node bin/admin.mjs licence-sites <id>
node bin/admin.mjs licence-release <id> <empreinte|all>
```

`FLUFFLESS_ADMIN_TOKEN_FILE` pointe vers un fichier qui contient le jeton; à défaut, `FLUFFLESS_ADMIN_TOKEN` porte le jeton lui-même (à éviter dans l'historique d'un shell). `FLUFFLESS_API_URL` est l'adresse de l'API visée.

La clé de licence n'est affichée qu'à la création : la base n'en garde que le HMAC.

`licence-sites` (`GET /v1/admin/licences/:id/sites`) liste les sites d'une licence : empreinte
tronquée à 12 caractères, `dev`, date d'inscription et dernier contact. Le soutien libère une place
avec `licence-release <id> <empreinte>` (`DELETE …/sites/:empreinte`) ou toutes avec `all`
(`DELETE …/sites`); ces libérations ne comptent pas dans le plafond de la cliente.

Avis, révocation, rétablissement (aucune révocation sans avis écrit) :

- `licence-notice <id> --reason usage|resale|sharing|other [--days 30..60]`
  (`POST /v1/admin/licences/:id/notice`) : pose l'avis, 30 jours par défaut, ni moins de 30 ni
  plus de 60. La licence reste valide; `/verify` et `/v1/update` ajoutent `notice_until` (sur un
  204, par l'en-tête `X-Fluffless-Notice-Until`). Aucun courriel ne part encore : les gabarits
  attendent la relecture juridique, l'avis s'écrit donc à la main, en plus.
- `licence-notice-off <id>` (`DELETE …/notice`) : lève l'avis.
- `licence-revoke <id> --reason "…"` (`POST …/revoke`) : 409 `notice_required` sans avis,
  409 `notice_running` avant la date de l'avis. Garde l'avis en base pour l'historique.
  `--refund` (`refund: true`) : après un remboursement seulement, sans avis.
- `licence-restore <id>` (`POST …/restore`) : remet `revoked_at`, `revoke_reason` et l'avis à
  vide, pose `restored_at`; les places n'ont pas bougé. 409 `not_revoked` si rien n'est révoqué.

Signalements (`licence_flags`) : rien d'automatique ne suit, ils ne coupent ni ne ralentissent
rien et n'écrivent pas à la cliente. Chacun part une fois en alerte « Licence à examiner » vers
`ALERT_WEBHOOK_URL`, avec l'id de licence et des comptes seulement. Règles en place : plafond
interne de l'illimitée (`ceiling`), plafond des sites de développement (`dev`), plafond de
libérations sur une illimitée (`releases`, une fois par fenêtre de 30 jours). `licence-flags`
(`GET /v1/admin/licence-flags?open=1`; `--all` pour tout) les liste, `licence-flag <id> ok|notice|revoked`
(`PATCH /v1/admin/licence-flags/:id`) note l'issue.

## Rotation des clés

- `ADMIN_TOKEN` : générer (`openssl rand -hex 32`), remplacer dans canner et dans le coffre. Sans effet ailleurs.
- `URL_SECRET` : même geste. Seuls les liens de téléchargement de moins de 15 minutes deviennent invalides.
- `SIGNING_PRIVATE_KEY` : la clé publique est inscrite dans l'extension. Publier d'abord une version
  de l'extension qui accepte l'ancienne et la nouvelle clé publique, attendre son adoption, puis
  remplacer la clé privée et republier les zip (leur signature change), packs compris. En cas de fuite, faire
  la même chose sans attendre et retirer les versions signées par la clé compromise.
- `HASH_PEPPER` : ne pas le changer ; les licences et les sites inscrits ne seraient plus
  reconnus. Une rotation exige de réémettre toutes les licences.

## Sauvegardes

Toute la donnée est dans la base canner. Faire un `pg_dump` mensuel (et avant chaque migration
de schéma) depuis l'Atelier de données de canner, et le ranger chiffré dans Proton Drive
`Fluffless/sauvegardes/`. Garder trois vidages ; supprimer les plus anciens (ils contiennent des
signalements purgés depuis). Tester une restauration sur une base locale une fois par trimestre.

## Loi 25 : données personnelles

| Donnée | Où | Durée |
| --- | --- | --- |
| Adresse IP | Mémoire du processus (limite de débit) | 24 h au plus (verrous), perdue au redémarrage ; jamais en base ni au journal |
| Identifiant de site (sha256 de `home_url`) | Base, re-haché avec `HASH_PEPPER` | Durée de la licence ; supprimé avec elle |
| Clé de licence | Base, HMAC seulement | Durée de la licence |
| Site de développement (même empreinte, `licence_dev_sites`) | Base | Purge automatique 90 jours après le dernier contact; supprimé avec la licence |
| Libérations de place (`licence_site_releases`) : date seulement | Base | Purge automatique après 30 jours |
| Signalements d'usage (`licence_flags`) : règle et comptes seulement | Base | Purge automatique 12 mois après la création, l'examen ou le rétablissement (le plus tardif); gardé tant que l'avis ou la révocation qu'il a motivé tient |
| Signalement (texte, diagnostic, capture) | Base canner (Canada) | Purge automatique 12 mois après le statut `réglé` |
| Courriel de réponse (facultatif) | Base, avec le signalement | Idem ; aussi dans la boîte Proton de notification |
| Courriel de la licence (livraison des packs) | Base, sur la licence | Durée de la licence ; supprimé avec elle ; aussi dans les envoyés Proton |
| Réclamations et liens de packs | Base (`pack_claims`, HMAC des jetons dans `pack_links`) | Durée de la licence ; supprimés avec elle |
| Achats de licences (montant, identifiants Stripe, offre, langue, numéro de licence) | Base (`licence_purchases`) | Pièce comptable : gardée après la licence (`licence_id` mis à null), purgée après 7 ans ; un remboursement en attente reste |
| Achats de packs (montant, identifiants Stripe, numéro de licence) | Base (`pack_purchases`) | Pièce comptable : gardée après la licence (`licence_id` mis à null), purgée après 7 ans ; un remboursement en attente reste |
| Événements Stripe | Base (`stripe_events`) : identifiant, type et identifiant de l'objet seulement | Purge automatique après 90 jours (les identifiants `cs_`, `ch_`, `dp_` se rattachent à une personne chez Stripe) |
| Sessions d'achat ouvertes | Base (`pack_checkouts`) : identifiants de session, de licence et de pack | Effacées au paiement, à l'expiration de la session suivante, ou après 25 h |
| Courriel, nom, adresse de facturation, carte | Stripe (États-Unis) | Selon Stripe ; la cliente saisit son courriel sur la page Stripe, l'API ne le transmet pas. À l'achat d'une licence, seul le courriel est recopié sur la licence (livraison de la clé et des packs) ; le pays de facturation est lu, jamais gardé |

Communications hors Québec : Proton (Suisse) reçoit les courriels des licences (envoi des clés et des liens),
le texte des signalements et les alertes; Stripe (États-Unis) traite le paiement. Les deux vont
dans l'évaluation des facteurs relatifs à la vie privée et dans la politique de confidentialité.

Licence échue : purge automatique 12 mois après la fin (ou après le rétablissement, s'il est plus
tardif), avec son courriel, ses sites, ses réclamations et liens de packs, ses rappels et ses
signalements. Les pièces d'achat restent 7 ans, `licence_id` mis à null. Une licence sans fin ne
s'efface que sur demande (`delete-licence`).

Le journal ne contient ni URL complète (la chaîne de requête porte le site), ni en-tête de licence, ni IP,
ni courriel, ni texte de signalement, ni jeton de lien de pack. Une erreur n'y laisse que son code
ou le nom de sa classe, jamais son message (il peut recopier une valeur reçue). Seul un échec au
démarrage garde son message (configuration, migration, clé de signature) : il ne porte aucune
donnée reçue d'une cliente et sert au diagnostic. Les journaux
d'accès du mandataire de canner, eux, voient la chaîne de requête de `GET /v1/update` (empreinte
du site) : à vérifier chez canner. Les signalements `nouveau` ou `vu` ne sont pas purgés :
les traiter, ou les supprimer (`delete-report`) sur demande de la personne concernée. Une
demande d'effacement vise aussi Stripe : supprimer la cliente dans le tableau de bord Stripe.

Autres limites publiques, toutes en mémoire : 120 appels par minute par IP (même regroupement
IPv6) sur `update`, `download`, `info`, `licences/verify`, `licences/release` et le lien de pack; 8 téléchargements
de zip simultanés au plus (503 `busy`, `Retry-After: 30`, le lien de pack n'est pas consommé).
Un téléchargement est coupé après 30 s sans progression ou 5 min en tout, pour qu'une cliente
qui ne lit pas ne garde pas sa place. Signalements : 10 par heure par IP et 100 par heure en
tout; au-delà de 50 Mo de captures par jour, le texte est reçu sans la capture (réponse
`screenshot_dropped: true`). Le plafond global est un compromis : une rafale venue de
nombreuses IP bloque aussi les vrais signalements pendant une heure, mais la boîte courriel
et la base ne débordent pas.
Responsable : Geneviève Bergeron, bonjour@genevievebergeron.ca.

## Stripe

Branché pour l'achat de packs et la vente des licences (`src/stripe.mjs`, sans dépendance).
La vente des licences remplit `licences.email` avec l'adresse saisie chez Stripe, seule adresse
de livraison de la clé et des packs (voir « Vente des licences »).

Parcours d'un achat :

1. Le site appelle `POST /v1/packs/checkout`. L'API crée une session Checkout d'une heure, sans
   courriel prérempli (quiconque détient la clé le lirait sur la page Stripe), avec les métadonnées `{kind: pack, licence_id, pack_id, pack_slug, locale}` (aussi
   sur le PaymentIntent), Stripe Tax (`automatic_tax`) et l'adresse de facturation obligatoire.
2. Stripe envoie `checkout.session.completed` (ou `checkout.session.async_payment_succeeded`).
   Le webhook vérifie `Stripe-Signature` sur le corps brut (5 minutes de tolérance), garde
   l'identifiant de l'événement (un rejeu répond `duplicate`; une livraison simultanée du même
   événement répond 409 `event_in_progress` et Stripe la réessaie : un bail de 5 minutes, libéré
   en cas d'échec, empêche le double traitement), puis inscrit l'achat dans
   `pack_purchases`, une ligne par session : un second passage ne refait rien.
3. Premier arrivé, premier servi : si la licence possède déjà le pack, si la licence ou le
   pack a disparu, ou si l'adresse de facturation n'est pas au Canada (`outside_canada`, ou
   `country_not_covered` avec Managed Payments, voir plus bas), le
   paiement est remboursé (clé d'idempotence `refund-<session>`) et une
   alerte part à `NOTIFY_TO`. Sinon `pack_claims` reçoit `source = purchase` et un lien de 72 h
   part à `licences.email` avec un mot de remerciement. Si ce courriel ne part pas, une alerte
   le signale : la cliente a payé, il faut lui écrire.
4. Une erreur (Stripe injoignable pendant un remboursement) répond 500 : Stripe réessaie, et la
   ligne reste `refund_pending` jusqu'au succès. Un paiement déjà remboursé à la main est repris
   tel quel. Un remboursement encore bloqué après 48 h déclenche une alerte à chaque
   réconciliation.

Vente au Canada seulement : Stripe Tax perçoit la TPS, la TVQ ou la TVH, et aucune TVA
étrangère n'est à percevoir. Checkout ne sait pas restreindre le pays de facturation (seulement
celui de livraison) : la page de paiement l'annonce (`custom_text`), et le règlement rembourse
un paiement dont l'adresse de facturation est ailleurs ou absente, sans donner accès. Seul le
pays est lu; l'adresse n'est pas gardée ici. Le site doit aussi l'annoncer avant le bouton
d'achat.

Vente hors Canada par Managed Payments : **préparée, désactivée**. `STRIPE_MANAGED_PAYMENTS=1`
remplace Stripe Tax (`automatic_tax`, interdit dans ce mode) par `managed_payments[enabled]` :
Stripe, sous le nom « Sold through Link, LLC », devient la vendeuse officielle et perçoit,
déclare et verse les taxes dans la liste de pays `MANAGED_COUNTRIES` (`src/app.mjs`, relevée le
29 sept. 2026 sur la page Tax compliance de Stripe). Un paiement venu d'un autre pays est
remboursé sans accès (`country_not_covered`, migration 006) : les taxes de ces pays
resteraient à la charge de la vendeuse. Chaque session porte son mode dans ses métadonnées
(`sale: managed`) : une session ouverte avant une bascule se règle selon ce qu'elle a affiché.
Avant d'allumer l'interrupteur :

- Confirmation écrite de Stripe que Link, LLC achète et revend en son nom (et non comme
  mandataire), et avis de la comptabilité sur la détaxation des ventes à Link (TPS/TVQ).
- Accepter les conditions de Managed Payments et l'activer au tableau de bord.
- `STRIPE_API_VERSION` épinglée sur 2025-03-31.basil ou plus récente (le démarrage refuse une
  version absente ou plus ancienne).
- Donner le droit Products (lecture) à la clé restreinte : le produit est lu avec le prix.
- Un code fiscal « Eligible for Managed Payments » sur chaque produit, puis **refixer le prix de
  chaque pack en vente** avec `pack-price` : c'est là que l'absence de code fiscal est refusée
  (l'admissibilité, elle, se vérifie au tableau de bord). Un prix posé avant la bascule n'est
  pas revérifié, et Stripe refuserait la session.
- Adaptive Pricing est toujours actif : `pack_purchases.amount_total` et `currency` peuvent
  être dans la devise de la cliente.
- Refaire la liste `MANAGED_COUNTRIES` contre la page de Stripe, et changer la mention
  « Canada seulement » du site.

Pour revenir en arrière, remettre l'interrupteur à 0 : les sessions Managed Payments déjà
ouvertes restent payables jusqu'à une heure, et se règlent selon leur mode. Pour les couper
tout de suite, les expirer chez Stripe (celles de `pack_checkouts`).

Deux onglets : chaque nouvelle session est inscrite dans `pack_checkouts` sous le verrou de la
licence, et les autres sessions ouvertes de la licence pour ce pack sont expirées chez Stripe.
Une seule reste payable. Une session déjà payée refuse l'expiration : le webhook la règle, et
le remboursement couvre le doublon. Si une réclamation gratuite ou l'ouverture d'une fenêtre
survient pendant l'appel à Stripe, la session tout juste créée est expirée et la réponse est
409. Une session ne dure jamais au-delà de l'ouverture d'une fenêtre gratuite prévue pour le
pack; à moins de 30 min de cette ouverture, la vente est refusée (`claim_open`). Poser avec
`pack-window` une fenêtre qui ouvre dans moins d'une heure expire les sessions ouvertes du pack;
une fenêtre plus lointaine les laisse expirer d'elles-mêmes. La lecture du pack sous verrou
partagé empêche une session de passer entre la pose de la fenêtre et ce ménage.

Concurrence vérifiée par les tests sur Postgres réel : même événement livré 6 fois en parallèle,
même session dans 6 événements, deux sessions payées en parallèle, réclamation gratuite contre
webhook dans les deux ordres (10 tours), réconciliation pendant le webhook, `charge.refunded`
triple, lien de pack ouvert 8 fois en parallèle, réclamation ou fenêtre pendant la création de
session, bail repris par une autre livraison pendant un échec. Invariants : une seule réclamation, un seul
courriel, au plus un remboursement.

`checkout.session.async_payment_failed` (paiement différé définitivement échoué) laisse la trace
`paiement_echoue` au journal (niveau error) et envoie l'alerte « Paiement échoué » pour une vente de
pack, de licence ou de renouvellement; `payment_intent.payment_failed` (refus de carte, que la cliente
rejoue) laisse seulement la trace (niveau warn, avec le code Stripe), sans alerte. Aucun courriel de
cliente dans le journal ni dans l'alerte. Une alerte part au webhook `ALERT_WEBHOOK_URL` quand il
est configuré, sinon (ou s'il échoue) par courriel. Un remboursement reçu avant l'événement d'achat
déclenche, au règlement de l'achat, l'alerte « Achat réglé après son remboursement » avec la licence.

`charge.refunded` et `charge.dispute.created` envoient une alerte (pack, licence, source de
l'accès actuel) sans retirer l'accès; un remboursement automatique n'alerte qu'une fois.
Un paiement de licence nomme l'offre et la licence; un paiement inconnu de l'API alerte avec la
mention « Aucun achat de pack ni de licence connu ». Un achat avec coupon garde le code et le rabais obtenu (voir « Coupons de licence »). Chaque jour, et par `node bin/admin.mjs reconcile`, la
réconciliation relit les sessions payées des trois derniers jours, rattrape celles que le
webhook n'a pas livrées (alerte à l'appui) et réessaie les remboursements en attente.

Mise en place chez Stripe (mode test d'abord) :

1. Stripe Tax : inscrire les numéros de TPS et de TVQ dans les réglages fiscaux, sinon aucune
   taxe n'est perçue. Donner un code fiscal aux produits (produit numérique téléchargé).
2. Un produit et un prix unique (CAD) par pack, puis `node bin/admin.mjs pack-price <slug> <price_…>`.
3. Point de terminaison `https://api.fluffless.dev/v1/webhooks/stripe` abonné à
   `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `charge.refunded`,
   `charge.dispute.created`. Son secret `whsec_…` va dans `STRIPE_WEBHOOK_SECRET`.
4. Clé restreinte `rk_…` : Checkout Sessions (écriture, qui couvre l'expiration), Prices
   (lecture), Refunds (écriture). Products (lecture) en plus avec Managed Payments.
5. Les variables dans canner et dans le coffre Proton Drive, puis redémarrage. Épingler
   `STRIPE_API_VERSION` sur la version du compte au moment des tests.
6. La page `STRIPE_SUCCESS_URL` rappelle que le lien arrive par courriel et qu'il se redemande
   au catalogue.

### Vente des licences

Parcours sans compte client, Canada seulement :

1. Le site appelle `POST /v1/licences/checkout` `{offer, locale, website}` depuis son serveur
   (relais d'IP par `X-Fluffless-Proxy` et `X-Fluffless-Client-IP`, comme les packs). Limites :
   celle des packs par IP, et 200 sessions par heure en tout (au-delà, 429 et une alerte par
   heure). `website` est un pot de miel : rempli, la réponse a l'air normale et rien n'est créé.
   Sans `PROTON_SMTP_USER` et `PROTON_SMTP_TOKEN`, la vente répond 503 : ni la clé ni l'alerte
   ne partiraient.
   La session porte `{kind: licence, offer_id, offer_slug, locale}`, Stripe Tax, l'adresse de
   facturation obligatoire, l'acceptation des conditions de vente (`consent_collection`) et la
   mention « Canada seulement ». `STRIPE_SUCCESS_URL` reçoit en plus `kind=licence`, pour que
   la page de succès dise « ta clé arrive par courriel ».
2. Au paiement (webhook ou réconciliation, mêmes garanties que les packs : une ligne par session
   dans `licence_purchases`, bail par événement), la licence naît avec le plan, le nombre de
   sites, la durée et les extensions de l'offre (`release_slugs`, copiées : changer l'offre
   ensuite ne touche pas les licences vendues), et le courriel saisi chez Stripe. Hors Canada ou offre
   disparue : remboursement automatique (`refund-<session>`), aucune licence, alerte.
3. La clé part par courriel (Proton), avec l'adresse de la page de téléchargement et les étapes
   d'installation. Elle est dérivée de la session (HMAC avec `URL_SECRET`) : un nouvel essai
   envoie la même clé, la base n'en garde que le HMAC. Compromis assumé : la base et
   `URL_SECRET` ensemble redonnent les clés achetées, mais qui détient l'environnement détient
   aussi la clé de signature des mises à jour, bien pire. Un courriel qui ne part pas déclenche
   une alerte (au premier échec puis toutes les 36 tentatives) et la relivraison réessaie
   toutes les 10 minutes sous un bail de 3 minutes, les achats les moins essayés d'abord. Un
   achat sans courriel n'est pas relivré : l'alerte demande `licence-resend <id> --email`.
   Proton accepte l'envoi avant de connaître un rebond : une adresse mal saisie chez Stripe ne
   déclenche aucune alerte. D'où `GET /v1/licences/purchase?session=cs_…` : la page de succès
   (`STRIPE_SUCCESS_URL` et `STRIPE_SUCCESS_URL_EN` doivent garder `session={CHECKOUT_SESSION_ID}`) y lit l'état de l'achat
   et l'adresse d'envoi masquée (`g***e@gmial.com` : première et dernière lettre, domaine
   entier), pour que la cliente voie sa faute tout de suite. Une session inconnue, pas encore
   réglée ou vieille de plus de 24 h répond `unknown`; la limite par IP est celle des packs.
4. `GET /v1/licences/download` affiche un formulaire; la cliente y colle sa clé (`POST`, formulaire
   encodé) et repart (303) vers le lien signé de 15 minutes de la dernière version stable de
   l'extension de la licence, le même que donne `/v1/update`. Seul ce lien prend une place de
   téléchargement. Licence à plusieurs extensions (Suite + SEO) : une page de liens, la Suite en
   premier, et le courriel le dit. Licence sans liste : la Suite. Clé inconnue, révoquée ou
   expirée : 403. Sans aucune version publiée : 503; s'il en manque une seule, la page donne les
   autres et le signale. La clé voyage dans le corps, jamais dans l'URL.
   La page ne compte pas les sites : une clé partagée télécharge, mais n'active pas plus de
   `sites_max` sites.
5. Courriel perdu ou adresse erronée : `licence-resend <id> [--email …]` génère une nouvelle clé
   et l'envoie (202 si la relivraison l'envoie au même moment). L'ancienne clé cesse quand le
   courriel de la nouvelle est parti : si l'envoi échoue sans doute possible (refus SMTP 4xx ou 5xx,
   connexion jamais établie, adresse ou identifiants refusés, erreur interne; réponse 503), l'ancienne
   reste valable et la relivraison, toutes les 10 minutes, envoie la nouvelle. Si l'issue est
   ambiguë (tout délai dépassé, ou coupure en pleine transmission du message), le message a pu partir : la nouvelle clé s'ouvre tout de suite, l'ancienne
   cesse, et la relivraison renvoie la même nouvelle clé (elle se dérive de la session). Une licence
   créée à la main se renouvelle par `rotate`, refusé (409 `delivery_pending`) tant que la clé
   d'un achat n'est pas livrée.

La pièce d'achat est purgée après 7 ans, même si la licence (sans fin) vit encore : passé ce
délai, `licence-resend` répond 409 `not_purchased` et `rotate` prend le relais.

Un remboursement ou un litige sur une licence alerte sans retirer l'accès. Procédure de
remboursement d'une licence, dans l'ordre :

1. Rembourser dans le tableau de bord Stripe (ou laisser l'API le faire, cas ci-dessus).
2. Désactiver la clé, comme le promet la page de remboursement :
   `node bin/admin.mjs licence-revoke <id> --reason "Remboursée le …" --refund`. Aucun avis n'est
   demandé : la cliente a demandé son argent. La raison est gardée avec le préfixe `refund:`.

Un litige ouvert ne révoque rien. Pour un litige perdu ou une fraude, la règle n'est pas encore
fixée : d'ici là, avis puis révocation, comme pour tout autre cas.

Une licence à durée fixe ne se renouvelle pas toute seule. Deux rappels partent, à 30 et à 7 jours
de la fin, une seule fois chacun (table `licence_reminders`), si `REMINDERS_ENABLED=1`. Ils portent
la date de fin et un lien signé (`/v1/licences/renew`) qui ouvre un paiement pour la même licence :
la clé ne change pas, la fin recule d'un an (à partir d'aujourd'hui si la licence est déjà
expirée). Le paiement est réglé par `settleLicenceRenewal` (table `licence_renewals`); une période
payée deux fois, un paiement hors Canada ou une licence révoquée sont remboursés. Une licence
sans courriel valide est ignorée. Une prise de rappel restée sans envoi confirmé (plantage) se reprend après 15 minutes; un envoi accepté par le SMTP dont la confirmation se perd peut donc partir deux fois, compromis préféré à un rappel perdu. Le courriel de bienvenue ne promet les rappels que si `REMINDERS_ENABLED=1`. La page de téléchargement d'une licence expirée dit la date de
fin et offre le même lien; une licence créée à la main y trouve l'adresse pour écrire.

Prix gelé (migration 013) : le prix de l'achat, avant taxes et avant rabais, suit la licence
(`renewal_price_id`, `renewal_unit_amount`, `renewal_product_id`). Le lien de renouvellement le
reprend jusqu'à 30 jours après la fin, ou après le dernier rétablissement, même si l'offre a
changé de prix ou n'est plus en vente; au-delà, c'est le prix du jour de l'offre, qui devient le
nouveau prix gelé. Si le prix gelé est archivé ou changé chez Stripe, la session porte le même
montant en `price_data` sur le même produit et une alerte part. La base de chaque paiement est
gardée (`licence_renewals.price_basis` : `gele` ou `jour`); un montant payé qui ne correspond pas
au prix attendu déclenche l'alerte « Écart de prix ». Pendant ces 30 jours, `verify` d'une
licence expirée donne aussi `grace_until` et `renew_url`, et la page de téléchargement dit
jusqu'à quand le prix tient. Les licences vendues avant la migration reçoivent le prix actuel de
leur offre (bloc `-- remplissage`, sans effet si on le rejoue).

Remboursement d'un renouvellement fait à la main dans Stripe : `renewal-refund <cs_…>`
(`POST /v1/admin/licence-renewals/:cs/refund`). La fin revient à celle d'avant le paiement (ou à
maintenant si elle est passée), la ligne passe à `refunded` et la réponse donne le nouveau lien de
renouvellement, l'ancien ne valant plus. Ce lien part aussi par courriel (FR ou EN, selon l'achat)
à l'adresse de la licence, avec la date limite du prix gelé ; `mail_sent: false` dans la réponse
veut dire que l'envoi a échoué ou que le SMTP manque : envoie alors le lien à la main. Seul le dernier renouvellement se défait (409
`not_last_renewal`); la grâce repart de la fin rétablie.

#### Coupons de licence

Un coupon est un code (3 à 40 caractères : majuscules, chiffres, tirets) qui retire un pourcentage
du prix annuel de l'offre. Aucun taux n'est codé : il se pose à la création, par
`node bin/admin.mjs coupon <CODE> --percent <1 à 99> [--label "…"] [--max <n>] [--until <date ISO>]`
(`POST /v1/admin/licence-coupons`), qui crée aussi le coupon Stripe correspondant (`duration: once`,
`max_redemptions`, `redeem_by`). Un rabais de 100 % est refusé : une session à zéro dollar n'a pas
de paiement à régler. `coupons` liste les codes avec le nombre d'achats et le rabais total;
`coupon-off <CODE>` éteint un code sans toucher aux achats faits. Le coupon reste vivant chez Stripe : un lien déjà envoyé avec ce code reste payable au tarif réduit jusqu'à son expiration (24 h au plus). Le plafond de rachats compte aussi les achats remboursés par l'API (hors Canada), ce qui peut faire perdre une place à un organisme.

Aucun code ne se saisit sur la page de vente : la route publique refuse le champ `coupon`. Le
rabais passe seulement par un lien de paiement signé par l'administration, après vérification de
l'organisme :

```sh
node bin/admin.mjs licence-link <offre> --coupon OBNL20 --note "Organisme, NEQ 1234567890" [--hours 24] [--email a@b.ca] [--locale en_CA]
```

(`POST /v1/admin/licence-links`, corps `{offer, locale, coupon, note, hours?, email?}`.) La note
(3 à 120 caractères : organisme, NEQ ou numéro de bienfaisance) est obligatoire; la licence née du
paiement la garde (`Achat <offre> · <note>`). `hours` va de 1 à 24, 24 par défaut. `email` se pose
en `customer_email` de la session, pour que la clé parte à l'adresse vérifiée. La commande imprime
l'adresse de paiement et son heure d'expiration (heure de Montréal) dans le terminal seulement :
l'adresse vaut paiement, elle ne va ni dans un fichier ni dans les journaux (`lien_licence_admin`
ne garde que l'offre, le code et la durée). Les mêmes garanties que la route publique s'appliquent
(taxes, adresse de facturation, conditions, avis de vente, plafond horaire global des sessions).
Un lien expiré ne se paie plus chez Stripe : on en signe un autre. Deux liens pour le même
organisme donnent deux licences possibles, la décision reste manuelle.

Un code inconnu, éteint, expiré ou épuisé reçoit le même 422 `coupon_invalid` (le refus ne dit pas
lequel). Sinon la session porte `discounts`, le code, `link: admin` et la note dans ses
métadonnées; au règlement, `licence_purchases` garde `coupon_code` et `discount_amount`.
Le plafond de `--max` se vérifie ici et chez Stripe, qui tranche si deux séances se croisent.
Ici, seuls les achats réglés comptent (un achat remboursé libère sa place). Stripe, lui, ne décompte pas un remboursement : un code remis en jeu ici peut encore être refusé chez lui, et la cliente reçoit alors le même 422 `coupon_invalid`.
Le renouvellement reprend le coupon de l'achat, à chaque échéance, même si le code a été éteint,
est expiré ou épuisé depuis (droit acquis). Si Stripe le refuse quand même (échu ou épuisé chez lui),
la session est créée au plein prix, visible dans Checkout, et une alerte demande de corriger le coupon. Pour un rabais
qui doit durer (organisme reconnu), crée donc le code sans `--max` ni `--until`, une seule fois en
production : `node bin/admin.mjs coupon OBNL20 --percent 20 --label "OBNL 20 %"`. Le taux reste une
donnée, jamais une constante du code. Un organisme qui n'est plus reconnu garde le rabais au
renouvellement tant qu'on ne fait rien : ne pas renouveler à la main, ou révoquer après avis (voir
« Avis, révocation, rétablissement » plus haut).

Mise en place, en plus des étapes des packs :

1. Un produit « Fluffless Suite » et un prix unique en CAD par offre (taxes en sus), puis
   `offer <slug> --plan … --sites … [--days …] [--release fluffless-suite,fluffless-seo]` et
   `offer-price <slug> <price_…>`. Sans `--release`, une nouvelle offre livre la Suite seule et
   une offre modifiée garde ses extensions. Une offre dont une extension n'a pas de version
   stable publiée n'est pas en vente (404 `not_for_sale`, journal `offre_sans_version`). Une
   licence créée à la main (`licence`) reçoit la Suite seule, sauf `--release`.
2. Adresse des conditions de vente dans les réglages publics du compte Stripe : sans elle,
   Stripe refuse `consent_collection` et l'API répond 502.
3. Publier une version stable de la Suite (`release`) avant la première vente : sans elle, la
   page de téléchargement répond 503.
4. Sur fluffless.dev : le bouton d'achat (appel serveur à serveur), la mention « Vente au Canada
   seulement » près du bouton, et la page de succès quand `kind=licence`.
5. `REMINDERS_ENABLED=1` dans l'environnement du service, à poser **à l'ouverture des ventes**
   (il est à 0 par défaut). Avant l'expiration, le rappel par courriel est la seule porte de
   renouvellement : sans lui, la FAQ promet des rappels qui ne partent pas.

Non vérifié : que le reçu Stripe affiche les numéros de TPS et de TVQ (réglage du tableau de
bord ou `invoice_creation`), les limites d'envoi de Proton par jour, et `consent_collection`
avec Managed Payments.

Hors du code, à faire avant la production : l'évaluation des facteurs relatifs à la vie privée
pour Stripe (États-Unis) et Proton (Suisse), et la politique de confidentialité qui le dit.
