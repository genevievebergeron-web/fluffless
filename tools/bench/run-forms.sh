#!/usr/bin/env bash
# Mesure l'accueil (sans formulaire) et la page d'un formulaire avec chaque extension de formulaires activée seule,
# Fluffless + Suite, WooCommerce et Sensei coupés le temps de la mesure. Résultats dans tools/reports/bench-<date>.json,
# HTML des pages de formulaire dans $FORMS_HTML_DIR (défaut : tools/reports/forms-html, non versionné).
# Prérequis : pages form-fluffless, form-cf7, form-wpforms, form-fluent, form-formidable avec un formulaire chacune.
set -euo pipefail
cd ~/Projects/fluffless-wp
wp() { docker compose run --rm -T cli wp "$@" 2>/dev/null; }
base=http://127.0.0.1:8882
plugins=( contact-form-7 wpforms-lite fluentform formidable )
declare -A page=( [contact-form-7]=form-cf7 [wpforms-lite]=form-wpforms [fluentform]=form-fluent [formidable]=form-formidable )
out="${FORMS_HTML_DIR:-tools/reports/forms-html}"; mkdir -p "$out"
wp plugin deactivate woocommerce sensei-lms "${plugins[@]}" >/dev/null || true
wp cache flush >/dev/null
BENCH_LIGHTHOUSE=0 node tools/bench/measure.mjs --label "forms-aucune-accueil" --url "$base/" | tail -1
curl -s "$base/form-fluffless/" > "$out/fluffless.html"
BENCH_LIGHTHOUSE=0 node tools/bench/measure.mjs --label "forms-fluffless-page" --url "$base/form-fluffless/" | tail -1
for p in "${plugins[@]}"; do
	wp plugin activate "$p" >/dev/null; wp cache flush >/dev/null
	BENCH_LIGHTHOUSE=0 node tools/bench/measure.mjs --label "forms-$p-accueil" --url "$base/" | tail -1
	curl -s "$base/${page[$p]}/" > "$out/$p.html"
	BENCH_LIGHTHOUSE=0 node tools/bench/measure.mjs --label "forms-$p-page" --url "$base/${page[$p]}/" | tail -1
	wp plugin deactivate "$p" >/dev/null
done
wp plugin activate woocommerce sensei-lms >/dev/null; wp cache flush >/dev/null
echo FIN
