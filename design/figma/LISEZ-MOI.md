# Jetons Figma

Fichiers générés par `npm run figma:tokens` à partir de `packages/fluffless/theme.json` et des
variations de `styles/`. Ne pas les modifier à la main : relancer le script après tout changement
du thème.

Format DTCG, un fichier par collection et par mode :

- `Couleurs.<variation>` : les 9 rôles (base, surface, contrast, muted, divider, accent,
  on-accent, accent-2, alert) ;
- `Forme.<variation>` : rayons none, soft, round et coin coupé ;
- `Mesures.Mobile` et `Mesures.Bureau` : tailles de texte et espacements aux bornes de leur
  `clamp()`, largeurs de contenu ;
- `Typographie.Défaut` : polices (équivalent Figma, la pile CSS du thème en description) et
  interlignes en pourcentage.

Import dans Figma : Variables, puis Importer. Chaque fichier d'une même collection devient un
mode. Le plan Starter de Figma n'accepte qu'un mode par collection ; les variations passent alors
par une collection de référence (voir le fichier Figma « Fluffless · Système de design »).

Les polices du thème sont des piles système, sans fichier à charger. Figma n'a pas ces polices :
le fichier utilise l'équivalent Google Fonts le plus proche (Inter pour sans, Source Serif 4 pour
serif, etc.). Le rendu réel dépend de l'appareil.

## De Figma vers WordPress

Le chemin inverse produit une variation de style à partir des variables d'un fichier Figma
(exportées en JSON depuis Figma ou Tokens Studio) :

    npm run figma:variation -- --titre "Nom" --description "Une phrase sous le titre" --sortie packages/fluffless/styles/nom.json export.json

Le script lit les variables `couleur/<rôle>` (dont `button-bg`, facultatif) et
`rayon/<none|soft|round|large|small|cut>`, quel que soit le
nom de la collection. Les rôles absents gardent la couleur du thème. Il refuse la variation si
une paire texte et fond passe sous 4,5:1 (3:1 pour les composants), comme la vérification de
contraste du thème. Les polices ne sont pas lues : elles passent par la banque de polices.
