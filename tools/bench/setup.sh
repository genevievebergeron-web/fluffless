#!/usr/bin/env bash
# Remet l'instance de banc d'essai à zéro (base vidée, extensions et thèmes des autres
# variantes retirés) puis installe une variante et sa page de référence.
# Usage : tools/bench/setup.sh <fluffless|fluffless-gratuit|ollie|elementor>
# N'agit que sur le projet compose fluffless-bench (port 8883), jamais sur l'instance de dev.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
compose=(docker compose -f "$here/compose.yaml")
url="http://127.0.0.1:8883"

wp() { "${compose[@]}" run --rm -T cli wp "$@"; }
in_cli() { "${compose[@]}" run --rm -T --entrypoint sh cli -c "$1"; }

variant="${1:-}"
case "$variant" in
	fluffless | fluffless-gratuit | ollie | elementor) ;;
	*)
		echo "Variante attendue : fluffless, fluffless-gratuit, ollie ou elementor (reçu : '${variant}')." >&2
		exit 2
		;;
esac

node "$here/build-content.mjs"
"${compose[@]}" up -d --wait db wordpress >/dev/null

# État propre : aucune trace d'une variante précédente (fichiers, CSS générés, base).
in_cli 'rm -rf wp-content/themes/fluffless wp-content/themes/ollie wp-content/themes/hello-elementor \
	wp-content/plugins/fluffless-suite wp-content/plugins/fluffless-consent wp-content/plugins/ollie-pro wp-content/plugins/elementor \
	wp-content/uploads/elementor'
wp db reset --yes
wp core install --url="$url" --title=Bench --admin_user=bench --admin_password=bench-local-only \
	--admin_email=bench@example.test --skip-email
wp rewrite structure '/%postname%/'

case "$variant" in
	fluffless | fluffless-gratuit)
		# Gratuit : thème et bannière de consentement seulement. Payant : + Fluffless Suite.
		in_cli 'cp -R /packages/fluffless wp-content/themes/ && if [ -d /packages/fluffless-consent ]; then cp -R /packages/fluffless-consent wp-content/plugins/; fi'
		wp theme activate fluffless
		if [ -d "$here/../../packages/fluffless-consent" ]; then
			wp plugin activate fluffless-consent
		fi
		if [ "$variant" = fluffless ]; then
			in_cli 'cp -R /packages/fluffless-suite wp-content/plugins/'
			wp plugin activate fluffless-suite
		fi
		;;
	ollie)
		wp theme install /references/ollie.1.6.0.zip --activate
		wp plugin install /references/ollie_pro.zip --activate
		;;
	elementor)
		# Versions gratuites publiées sur wordpress.org ; aucune licence Pro.
		wp theme install hello-elementor --activate
		wp plugin install elementor --activate
		;;
esac

wp eval-file /bench/seed.php "$variant"
echo "Variante ${variant} prête sur ${url}/"
