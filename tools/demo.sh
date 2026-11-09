#!/usr/bin/env bash
# Monte les pages de démonstration du WordPress local (visite du produit).
# Usage : npm run demo
# Prérequis : npm run wp:up (le thème et Fluffless Suite actifs pour la page « Oui, tout »).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

if ! docker compose ps --status running --services 2>/dev/null | grep -qx wordpress; then
	echo "WordPress de dev arrêté : lancer npm run wp:up avant npm run demo." >&2
	exit 1
fi

docker compose run --rm -T cli wp eval-file - < tools/demo.php
echo "Visite : http://127.0.0.1:8882/ (jamais localhost : l'éditeur perdrait ses cookies)."
