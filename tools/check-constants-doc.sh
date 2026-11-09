#!/bin/bash
# Signale toute constante FLUFFLESS_* lue par le code (hors tests et marqueurs internes) qui ne figure
# dans aucun README ni readme.txt. Sortie vide et code 0 : tout est documenté.
set -u
cd "$(dirname "$0")/.." || exit 2
# Marqueur interne de tools/export-patterns et nom d'option du thème : pas des constantes de configuration.
INTERNES='FLUFFLESS_JSON|FLUFFLESS_NAVIGATION_OPTION'
code="$(grep -rhoE 'FLUFFLESS_[A-Z0-9_]+' packages services/api/src services/api/bin tools/veille.mjs \
	--include='*.php' --include='*.mjs' --include='*.js' \
	--exclude-dir=tests --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=languages 2>/dev/null \
	| sort -u | grep -vxE "$INTERNES")"
manque=0
for c in $code; do
	if ! grep -qw -- "$c" README.md packages/*/README.md packages/*/readme.txt services/api/README.md 2>/dev/null; then
		echo "Non documentée : $c"
		manque=1
	fi
done
exit "$manque"
