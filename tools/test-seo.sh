#!/usr/bin/env bash
# Tests de Fluffless SEO avec l'extension active : sur le banc elle est inactive par défaut,
# et ses tests PHP se sautent. On l'active le temps des tests, puis on remet l'état de départ.
# Usage : npm run test:seo
# Prérequis : npm run wp:up.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Instance de dev par défaut ; COMPOSE_FILE en vise une autre (voir tools/run-tests.sh).
compose=(docker compose)
if [ -z "${COMPOSE_FILE:-}" ]; then
	compose+=(-f "$root/compose.yaml")
fi
wp() {
	"${compose[@]}" run --rm -T cli wp "$@" 2>/dev/null
}

was_active=0
if wp plugin is-active fluffless-seo; then
	was_active=1
fi

restore() {
	if [ "$was_active" -eq 0 ]; then
		wp plugin deactivate fluffless-seo >/dev/null || echo "Attention : fluffless-seo est restée active." >&2
	fi
}
trap restore EXIT

if [ "$was_active" -eq 0 ]; then
	wp plugin activate fluffless-seo >/dev/null
fi

# Charger le site ne doit demander aucune traduction de fluffless-seo avant init (WordPress 6.7 refuse
# alors le domaine de texte). La sonde s'installe avant WordPress et crie si l'avis part.
probe=wp-content/plugins/fluffless-seo/tests/early-textdomain-probe.php
if "${compose[@]}" run --rm -T cli wp --require="$probe" eval 'exit( 0 );' 2>/dev/null | grep -q 'FLUFFLESS_SEO_TEXTDOMAIN_TOO_EARLY'; then
	echo "ÉCHEC : une traduction de fluffless-seo est demandée avant init." >&2
	exit 1
fi
"$root/tools/run-tests.sh" seo
