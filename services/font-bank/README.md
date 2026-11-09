# Banque de polices Fluffless

Site statique servi à `https://fluffless-polices.canner.app` (projet canner `fluffless-polices`).
Le module Polices de Fluffless Suite y copie une seule fois le WOFF2 dont un site a besoin.

La banque ne contient que des polices sous SIL Open Font License 1.1. Les polices Fontshare
(ITF Free Font License) n'y entrent pas : leur licence interdit de les héberger sur un serveur
public tiers. Le module les télécharge directement depuis `cdn.fontshare.com`, et Fontshare reste
le distributeur.

- `public/collection.json`, `public/index.html`, `public/LICENCES/` : produits par
  `node tools/font-bank.mjs build <archive Proton>/Fluffless/polices --out services/font-bank/public`.
  Le champ facultatif `copyright` de `font.json` est repris dans `collection.json`.
- `build.mjs` : au déploiement, récupère chaque fichier à son adresse d'origine (`ORIGINS`) ou,
  pour une famille sans hôte public, le lit dans `local/<slug>.woff2`. Le build s'arrête si
  l'empreinte SHA-256 diffère de celle de l'archive maîtresse.
- `local/` : WOFF2 convertis depuis l'archive (polices Velvetyne, Clara Sambot, etc.).
  Les polices à nom réservé (Reserved Font Name) y sont converties au complet, sans sous-ensemble,
  puisqu'une version modifiée ne peut pas garder le nom réservé.
- `server.mjs` : sert `public/` seulement (HTML, JSON, texte, WOFF2), sans dépendance.

## Ajouter une police

1. La déposer dans l'archive Proton (voir son README), puis relancer la commande `build` ci-dessus.
2. Ajouter son adresse d'origine dans `ORIGINS` de `build.mjs`, ou copier le WOFF2 dans `local/`.
3. Déployer les fichiers de ce dossier (sans `public/fonts/`) avec `canner_save_app`
   (les WOFF2 de `local/` en `encoding: "base64"`). Un envoi trop lourd peut se faire par étapes :
   une famille absente de `local/` est reprise de la banque en ligne, sous le même contrôle
   d'empreinte, pourvu que le déploiement précédent la serve déjà.
