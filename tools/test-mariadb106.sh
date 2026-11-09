#!/usr/bin/env bash
# Tous les tests sur MariaDB 10.6, la version des projets PlanetHoster World.
# Monte une instance jetable (projet compose fluffless-wp-db106, port 8886), installe
# WordPress comme l'instance de dev (mêmes langues, thème et extensions actifs), lance
# tools/run-tests.sh et vérifie l'accueil, puis retire l'instance et ses volumes (trap).
# L'instance de dev (fluffless-wp, port 8882) n'est jamais touchée.
# Usage : npm run test:mariadb106 [-- filtre]
# Prérequis : .env avec DB_PASSWORD.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export COMPOSE_FILE="$root/compose.yaml:$root/compose.mariadb106.yaml"
export COMPOSE_PROJECT_NAME=fluffless-wp-db106
url="http://127.0.0.1:8886"

teardown() {
	docker compose down -v --remove-orphans >/dev/null 2>&1 || echo "Attention : retirer l'instance avec « docker compose -p $COMPOSE_PROJECT_NAME down -v »." >&2
}
trap teardown EXIT

wp() {
	docker compose run --rm -T cli wp "$@"
}

docker compose up -d --wait db wordpress >/dev/null

# Le conteneur wordpress copie le cœur dans son volume au premier démarrage.
tries=0
until wp core version >/dev/null 2>&1; do
	tries=$((tries + 1))
	if [ "$tries" -ge 30 ]; then
		echo "WordPress n'est pas prêt après 60 s." >&2
		exit 1
	fi
	sleep 2
done

wp core install --url="$url" --title="Fluffless" --admin_user=admin \
	--admin_email=admin@example.invalid --skip-email >/dev/null

# Version lue par $wpdb (mysqli, comme WordPress) : le client mysql de l'image WP-CLI
# exige TLS, que MariaDB 10.6 n'offre pas par défaut.
version="$(wp eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );')"
case "$version" in
	10.6.*) ;;
	*)
		echo "MariaDB 10.6 attendue, reçu : $version" >&2
		exit 1
		;;
esac
# Langues de l'instance de dev (fr_CA et en_CA, cœur et extensions), copiées de son volume
# sans téléchargement : les tests basculent entre les deux.
dev_volume=fluffless-wp_wp
if ! docker volume inspect "$dev_volume" >/dev/null 2>&1; then
	echo "Volume $dev_volume absent : lancer d'abord npm run wp:up." >&2
	exit 1
fi
docker compose run --rm -T -v "$dev_volume:/dev-wp:ro" cli \
	sh -c 'mkdir -p wp-content/languages && cp -R /dev-wp/wp-content/languages/. wp-content/languages/' >/dev/null
wp option update WPLANG fr_CA >/dev/null
wp rewrite structure '/%postname%/' >/dev/null
wp theme activate fluffless >/dev/null
wp plugin activate fluffless-suite fluffless-consent >/dev/null

"$root/tools/run-tests.sh" "${1:-}"

status="$(curl -s -o /dev/null -w '%{http_code}' "$url/")"
if [ "$status" != 200 ]; then
	echo "Accueil : HTTP $status ($url/)." >&2
	exit 1
fi
echo "OK : MariaDB $version, tests passés, accueil HTTP 200."
