#!/usr/bin/env bash
# Lance tous les tests des paquets, sans liste en dur : chaque fichier nommé *-test.php
# (WP-CLI, instance de dev) ou *-test.mjs (Node) dans packages/*/tests/ est pris.
# Usage : tools/run-tests.sh [filtre]   ex. tools/run-tests.sh icone-render
# Prérequis : npm run wp:up, extension et thème actifs. S'arrête au premier échec.
# Les tests PHP tournent toujours en en_US, quelle que soit la langue du site.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
filter="${1:-}"
case "$filter" in
	*[!a-z0-9-]*)
		echo "Filtre attendu en minuscules, chiffres et tirets (reçu : '${filter}')." >&2
		exit 2
		;;
esac

# Instance de dev par défaut ; COMPOSE_FILE en vise une autre (tools/test-mariadb106.sh).
compose=(docker compose)
if [ -z "${COMPOSE_FILE:-}" ]; then
	compose+=(-f "$root/compose.yaml")
fi

# PHP : un seul conteneur WP-CLI pour tous les fichiers (packages/ est monté dans wp-content/).
php_count="$("${compose[@]}" run --rm -T -e "FILTER=$filter" cli sh -c '
	set -e
	n=0
	# Les tests affirment les chaînes sources (anglais) : la langue du site ne doit pas les changer.
	force_en='"'"'WP_CLI::add_wp_hook( "locale", function () { return "en_US"; } );'"'"'
	for t in wp-content/plugins/fluffless*/tests/*${FILTER}*-test.php wp-content/themes/fluffless*/tests/*${FILTER}*-test.php; do
		[ -e "$t" ] || continue
		echo "--> $t" >&2
		wp --exec="$force_en" eval-file "$t" >&2
		n=$((n + 1))
	done
	echo "$n"
')"

node_count=0
for t in "$root"/packages/*/tests/*"${filter}"*-test.mjs; do
	[ -e "$t" ] || continue
	echo "--> ${t#"$root"/}"
	node "$t"
	node_count=$((node_count + 1))
done

if [ "$((php_count + node_count))" -eq 0 ]; then
	echo "Aucun fichier de test trouvé (filtre : '${filter}')." >&2
	exit 1
fi
echo "OK : fichiers de test passés, PHP ${php_count}, Node ${node_count}."
