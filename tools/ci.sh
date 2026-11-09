#!/usr/bin/env bash
# Intégration continue locale : enchaîne les contrôles et s'arrête au premier échec.
# Usage : npm run ci
# Prérequis : npm install, npm run php:install, npm run wp:up (extension et thème actifs).
# Module Événements allumé (events-test.php l'exige) :
#   docker compose run --rm -T cli wp option patch update fluffless_settings modules events true --format=json
# Autre instance : COMPOSE_FILE, COMPOSE_PROJECT_NAME et FLUFFLESS_HOME_URL (accueil servi, défaut
# http://127.0.0.1:8882/) la visent pour toutes les étapes, l'instance de dev n'est alors pas touchée.
# test:api demande un Postgres : TEST_DATABASE_URL (ex. postgres://localhost:5432/postgres) ; sans lui
# l'étape est annoncée comme sautée (jamais déclarée verte), et le CI GitHub fournit un Postgres.
# test:uninstall efface options, tables et comptes : il ne tourne que si COMPOSE_PROJECT_NAME contient
# « jetable » (le CI GitHub en monte une), sinon l'étape est annoncée comme sautée, jamais déclarée verte.
# test:giant-size demande Chromium (playwright-core, ou CHROMIUM_PATH) et mesure l'accueil servi.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

# Ordre : du plus rapide au plus lent ; les contrôles sans Docker d'abord.
steps=(
	check:version
	check:sh
	check:hex
	check:abspath
	check:proprete
	check:theme-free
	check:contrast
	check:typo-fr
	lint:php
	analyse
	test:tools
	# Le service de licences : Postgres jetable, aucun WordPress. Sauté (annoncé) sans TEST_DATABASE_URL.
	test:api
	# Fluffless SEO activée le temps de ses tests (sinon ils se sautent), puis remise à l'état de départ.
	test:seo
	# Thème gratuit seul : Fluffless Suite désactivée le temps du test, puis réactivée.
	test:theme-only
	# Budgets de tools/budgets.json sur l'accueil servi : bloquants.
	check:budget-urls
	# Taille « Géante » dans Chromium, sur l'accueil servi.
	test:giant-size
	test:icone
	test:blocks
	test:font-bank
	test:figma-variation
	# Après tous les tests WordPress : efface options, tables et comptes (instance jetable seulement).
	test:uninstall
	# En dernier : échoue tant que la traduction fr_CA n'est pas terminée, sans masquer les autres étapes.
	check:i18n
)

if ! docker compose ps --status running --services 2>/dev/null | grep -qx wordpress; then
	echo "WordPress de dev arrêté : lancer npm run wp:up avant npm run ci (tests WP-CLI)." >&2
	exit 1
fi

home_url="${FLUFFLESS_HOME_URL:-http://127.0.0.1:8882/}"
export FLUFFLESS_HOME_URL="$home_url"

started="$(date +%s)"
passed=0
for step in "${steps[@]}"; do
	printf '\n==> %s\n' "$step"
	if [ "$step" = "test:api" ] && [ -z "${TEST_DATABASE_URL:-}" ]; then
		printf 'SAUTÉ : test:api (TEST_DATABASE_URL absent, aucun Postgres visé).\n'
		continue
	fi
	if [ "$step" = "test:uninstall" ] && [[ "${COMPOSE_PROJECT_NAME:-}" != *jetable* ]]; then
		printf 'SAUTÉ : test:uninstall (COMPOSE_PROJECT_NAME sans « jetable » : instance de dev protégée).\n'
		continue
	fi
	args=()
	[ "$step" = "check:budget-urls" ] && args=(-- "$home_url")
	if ! BASE="$home_url" npm run --silent "$step" ${args[@]+"${args[@]}"}; then
		printf '\nÉCHEC : %s (les étapes suivantes n'"'"'ont pas été lancées).\n' "$step" >&2
		exit 1
	fi
	passed=$((passed + 1))
done
printf '\n==> constantes FLUFFLESS_* documentées (tools/check-constants-doc.sh)\n'
tools/check-constants-doc.sh || { printf '\nÉCHEC : constantes non documentées.\n' >&2; exit 1; }
printf '\n==> parité fr_CA (tools/check-i18n-parity.py)\n'
python3 tools/check-i18n-parity.py || { printf '\nÉCHEC : parité fr_CA.\n' >&2; exit 1; }
printf '\nOK : %s étapes passées sur %s en %s s.\n' "$passed" "${#steps[@]}" "$(($(date +%s) - started))"
