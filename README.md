# Fluffless WP

Suite WordPress légère et expressive : le thème **Fluffless** (l'apparence) et l'extension **Fluffless Suite** (le comportement). *Bare but mighty. Minimaliste, mais puissant.*

Le plan complet reste dans le dépôt de travail privé.

Sites bilingues : Polylang est la solution recommandée.

## Principes

- Lourd dans l'éditeur, plume sur le front : le visiteur ne reçoit que le HTML et le CSS de ce qui est affiché.
- Natif avant maison : WordPress et le CSS moderne d'abord, du code seulement pour l'écart.
- Contenu en blocs standards, lisible même si la suite est désactivée.

## Démarrer

Prérequis : Docker et Node 20 ou plus. PHP n'a pas besoin d'être installé sur la machine.

```bash
cp .env.example .env         # puis changer DB_PASSWORD
npm run wp:up                # WordPress sur http://127.0.0.1:8882
npm run wp:cli -- theme activate fluffless
npm run php:install          # PHPCS et PHPStan dans Docker
```

## Vérifications

| Commande | Rôle |
|---|---|
| `npm run ci` | Tous les contrôles ci-dessous, arrêt au premier échec |
| `npm run check:version` | Même version partout (source : `package.json`) ; `npm run version:set 0.2.0` la change |
| `npm run lint:php` | Normes WordPress (PHPCS + WPCS) |
| `npm run analyse` | Analyse statique (PHPStan niveau 8) |
| `npm run check:hex` | Aucune couleur en dur dans les patterns |
| `npm run check:contrast` | Contraste WCAG des palettes et des combinaisons des patterns |
| `npm run test:tools` | Tous les fichiers `tests/*-test.php` et `*-test.mjs` des paquets |
| `npm run test:blocks` | Aucun bloc invalide dans les patterns |
| `npm run check:budget` | Mesure rapide du poids d'une page (hors `ci`) |
| `npm run lhci` | Lighthouse CI avec les budgets de sobriété (hors `ci`) |

## Publier

`npm run release` construit `dist/fluffless-<version>.zip`, `dist/fluffless-suite-<version>.zip`, leurs sommes SHA-256 et `dist/update.json` ; `npm run release:verify` les installe sur le banc et vérifie l'activation.

## Budgets de sobriété

Accueil ≤ 300 ko, 1 ko ou moins de JS, réservé à l'accessibilité, CSS ≤ 40 ko, polices ≤ 100 ko, ≤ 20 requêtes, aucune requête tierce (les statistiques, si activées, sont la seule exception prévue au plan), DOM ≤ 800 nœuds. Voir [tools/budgets.json](tools/budgets.json).

**Unité des budgets.** Tous les ko ci-dessus sont des ko **transférés** (octets sur le réseau, compressés), tels que Lighthouse les compte pour les ressources liées. Le CSS et le JavaScript en ligne font partie du document HTML : ils comptent dans le total de 300 ko, pas dans le budget « CSS ≤ 40 ko », qui ne vise que les feuilles de style liées. Le CSS en ligne se lit à part, en octets décodés, dans `npm run check:budget`.

## Mesures

Une seule table de poids fait foi. Elle a été relevée le 12 octobre 2026 sur l'accueil du banc de développement, thème, Fluffless Suite et Fluffless Consent actifs. Elle n'a pas été rejouée depuis : à refaire sur le banc (`npm run check:budget`) avant de la citer dans une page publiée.

| Mesure | Valeur | Budget |
|---|---|---|
| Poids transféré (compressé) | 20,1 ko | ≤ 300 ko |
| Requêtes (tierces) | 4 (0) | ≤ 20 (0) |
| JavaScript transféré, total | 2,4 ko | ≤ 1 ko |
| dont thème (réservé à l'accessibilité) | 0,4 ko | |
| dont Fluffless Consent | 2,0 ko | |

Le total de JavaScript dépasse le budget de 1 ko tant que Consent est actif : le thème seul (0,4 ko) le respecte, Consent ajoute ses 2,0 ko. Consent est facultatif; sans lui, ou avant qu'il soit activé, le JavaScript du thème reste sous le budget.

`tools/measure.sh` (`npm run check:budget`) affiche deux colonnes par ressource, **transféré** (gzip) et **décodé**, puis le CSS et le JavaScript en ligne du HTML (décodés).

Cette mesure n'est pas celle de la page de test qui compare Fluffless à Ollie et à Elementor.
