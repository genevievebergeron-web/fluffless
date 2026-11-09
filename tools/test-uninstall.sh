#!/usr/bin/env bash
# Tests de désinstallation des trois paquets (Suite, SEO, Consent) : fichiers packages/*/tests/*-jetable.php.
# Ils effacent des options, des tables et des comptes : instance JETABLE seulement, jamais l'instance de dev.
# Usage : COMPOSE_FILE=compose.flfix-jetable.yaml COMPOSE_PROJECT_NAME=flfix-jetable npm run test:uninstall
# Prérequis : l'instance jetable est montée (docker compose up -d), WordPress installé, thème et trois
# extensions présents (SEO active : son test le demande). Un fichier à la fois, un processus par fichier
# (chaque test pose une constante, qui ne se retire pas). S'arrête au premier échec.
set -euo pipefail

if [ -z "${COMPOSE_FILE:-}" ] || [ -z "${COMPOSE_PROJECT_NAME:-}" ]; then
	echo "REFUS : COMPOSE_FILE et COMPOSE_PROJECT_NAME doivent viser une instance jetable (jamais l'instance de dev)." >&2
	exit 2
fi
case "$COMPOSE_PROJECT_NAME" in
	*jetable*) ;;
	*)
		echo "REFUS : le projet compose '$COMPOSE_PROJECT_NAME' ne contient pas « jetable »." >&2
		exit 2
		;;
esac

compose=(docker compose --profile cli)
wp() {
	"${compose[@]}" run --rm -T cli wp "$@"
}

# Le garde-fou des tests se lit dans la base de l'instance visée, qui est jetable par la vérification ci-dessus.
wp option update fluffless_test_jetable 1 >/dev/null
if ! wp plugin is-active fluffless-seo; then
	wp plugin activate fluffless-seo >/dev/null
fi

ran=0
tests="$("${compose[@]}" run --rm -T cli sh -c 'ls wp-content/plugins/fluffless*/tests/*-jetable.php')"
for t in $tests; do
	printf '\n--> %s\n' "$t"
	wp eval-file "$t"
	ran=$((ran + 1))
done
if [ "$ran" -eq 0 ]; then
	echo "Aucun test de désinstallation trouvé." >&2
	exit 1
fi
printf '\n%s fichier(s) de désinstallation passés.\n' "$ran"
