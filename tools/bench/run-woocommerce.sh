#!/usr/bin/env bash
# Mesure la boutique WooCommerce sur l'instance de dev (8882) selon trois variantes : Twenty Twenty-Five,
# Fluffless seul, Fluffless + Suite. Prérequis : tools/bench/seed-woocommerce.php. Résultats dans tools/reports/bench-<date>.json.
set -euo pipefail
cd ~/Projects/fluffless-wp
wp() { docker compose run --rm -T cli wp "$@" 2>/dev/null; }
base=http://127.0.0.1:8882
pages=( "boutique|$base/shop/" "produit|$base/product/chandail-merinos/" "panier|$base/cart/?add-to-cart=2419" "caisse|$base/checkout/?add-to-cart=2419" )
mesure() { # $1 = variante
	for p in "${pages[@]}"; do
		BENCH_LIGHTHOUSE=0 node tools/bench/measure.mjs --label "woo-$1-${p%%|*}" --url "${p#*|}" | tail -1
	done
}
wp theme activate twentytwentyfive >/dev/null; wp plugin deactivate fluffless-suite >/dev/null; wp cache flush >/dev/null
mesure tt5
wp theme activate fluffless >/dev/null; wp cache flush >/dev/null
mesure fluffless
wp plugin activate fluffless-suite >/dev/null; wp cache flush >/dev/null
mesure suite
echo FIN
