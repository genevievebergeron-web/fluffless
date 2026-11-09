#!/usr/bin/env bash
# Installe les paquets de dist/ sur l'instance de banc (port 8883) en deux temps, puis arrête le banc
# (docker compose down, volumes gardés) :
#   1. chemin gratuit : le thème et Fluffless Consent seuls, sans Suite ni SEO (ce que reçoit qui
#      télécharge sur fluffless.dev/telecharger/) ;
#   2. chemin complet : Suite et SEO ajoutées par-dessus.
# Chaque fois : activation, versions et accueil HTTP 200 sans erreur PHP.
# Usage : npm run release (d'abord), puis npm run release:verify
# N'agit que sur le projet compose nommé ci-dessous (fluffless-bench par défaut), jamais sur l'instance de dev.
# Autre banc jetable : RELEASE_VERIFY_PROJECT, RELEASE_VERIFY_COMPOSE (fichier) et RELEASE_VERIFY_URL.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
project="${RELEASE_VERIFY_PROJECT:-fluffless-bench}"
compose=(docker compose -p "$project" -f "${RELEASE_VERIFY_COMPOSE:-$root/tools/bench/compose.yaml}")
url="${RELEASE_VERIFY_URL:-http://127.0.0.1:8883}"
version="$(node -p "require('$root/package.json').version")"
theme_zip="$root/dist/fluffless-$version.zip"
consent_zip="$root/dist/fluffless-consent-$version.zip"
plugin_zip="$root/dist/fluffless-suite-$version.zip"
seo_zip="$root/dist/fluffless-seo-$version.zip"

for zip in "$theme_zip" "$consent_zip" "$plugin_zip" "$seo_zip"; do
	if [ ! -f "$zip" ]; then
		echo "Paquet absent : ${zip#"$root"/}. Lancer npm run release d'abord." >&2
		exit 1
	fi
done

wp() { "${compose[@]}" run --rm -T cli wp "$@"; }
trap '"${compose[@]}" down >/dev/null 2>&1 && echo "Banc arrêté (docker compose down)."' EXIT

"${compose[@]}" up -d --wait db wordpress >/dev/null
for _ in $(seq 1 30); do
	curl -fsS -o /dev/null "$url/wp-login.php" 2>/dev/null && break
	sleep 2
done
if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$url" --title="Banc" --admin_user=bench \
		--admin_password="$(openssl rand -hex 16)" --admin_email=bench@example.org --skip-email >/dev/null
fi

fail() {
	echo "ÉCHEC : $1" >&2
	exit 1
}

home="$(mktemp)"
trap 'rm -f "$home"; "${compose[@]}" down >/dev/null 2>&1 && echo "Banc arrêté (docker compose down)."' EXIT

# Accueil en HTTP 200, sans erreur PHP rendue.
check_home() {
	local status
	status="$(curl -sS -o "$home" -w '%{http_code}' "$url/")"
	[ "$status" = 200 ] || fail "$1 : accueil en HTTP $status."
	if grep -qiE 'fatal error|critical error|erreur critique' "$home"; then
		fail "$1 : erreur PHP dans l'accueil."
	fi
}

# Les paquets passent par le volume partagé /var/www/html, lu par le conteneur WP-CLI.
install_zip() {
	local kind="$1" zip="$2"
	"${compose[@]}" cp "$zip" "wordpress:/var/www/html/$(basename "$zip")"
	wp "$kind" install "/var/www/html/$(basename "$zip")" --force --activate
	"${compose[@]}" exec -T wordpress rm -f "/var/www/html/$(basename "$zip")"
}

# 1. Chemin gratuit : un banc déjà passé par le chemin complet perd d'abord Suite et SEO (fichiers seulement,
# sans désinstallation : leurs réglages restent pour le chemin complet).
for slug in fluffless-seo fluffless-suite; do
	if wp plugin is-installed "$slug"; then
		wp plugin deactivate "$slug" --quiet || true
		wp plugin delete "$slug" --quiet
	fi
done
install_zip theme "$theme_zip"
install_zip plugin "$consent_zip"

[ "$(wp theme list --status=active --field=name)" = fluffless ] || fail "gratuit : thème Fluffless inactif."
[ "$(wp theme get fluffless --field=version)" = "$version" ] || fail "gratuit : version du thème différente de $version."
wp plugin is-active fluffless-consent || fail "gratuit : Fluffless Consent inactive après installation."
[ "$(wp plugin get fluffless-consent --field=version)" = "$version" ] || fail "gratuit : version de Fluffless Consent différente de $version."
! wp plugin is-installed fluffless-suite || fail "gratuit : Fluffless Suite encore présente."
! wp plugin is-installed fluffless-seo || fail "gratuit : Fluffless SEO encore présente."
# Un chargement complet sans la Suite n'écrit rien d'autre que le témoin : aucun avertissement PHP
# ni avis de dépendance manquante.
# Les lignes d'avancement de docker compose (« Container … Created ») sont retirées.
loaded="$("${compose[@]}" run --rm -T cli wp eval 'echo "fluffless-ok";' 2>&1 | grep -vE '^ *(Container|Network|Volume) ' || true)"
[ "$loaded" = fluffless-ok ] || fail "gratuit : sortie inattendue au chargement sans la Suite : $loaded"
check_home gratuit
echo "OK (gratuit) : Fluffless $version et Fluffless Consent $version installés depuis les ZIP, seuls, et actifs ; accueil HTTP 200 ($(wc -c < "$home" | tr -d ' ') o)."

# 2. Chemin complet. Fluffless SEO après Suite : elle la déclare dans Requires Plugins.
install_zip plugin "$plugin_zip"
install_zip plugin "$seo_zip"

wp plugin is-active fluffless-suite || fail "extension inactive après installation."
wp plugin is-active fluffless-seo || fail "Fluffless SEO inactive après installation."
[ "$(wp plugin get fluffless-seo --field=version)" = "$version" ] || fail "version de Fluffless SEO différente de $version."
[ "$(wp theme list --status=active --field=name)" = fluffless ] || fail "thème Fluffless inactif."
[ "$(wp plugin get fluffless-suite --field=version)" = "$version" ] || fail "version de l'extension différente de $version."
[ "$(wp theme get fluffless --field=version)" = "$version" ] || fail "version du thème différente de $version."
wp plugin is-active fluffless-consent || fail "Fluffless Consent inactive à côté de la Suite."
modules="$(wp eval 'echo implode( ",", \Fluffless\Suite\Core\Loader::active() );')"
[ -n "$modules" ] || fail "aucun module actif après activation."

check_home complet
echo "OK (complet) : Fluffless $version, Fluffless Suite $version, Fluffless SEO et Fluffless Consent installés depuis les ZIP et actifs ; modules actifs : $modules ; accueil HTTP 200 ($(wc -c < "$home" | tr -d ' ') o)."
