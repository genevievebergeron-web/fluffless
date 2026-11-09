#!/usr/bin/env bash
# Prépare puis mesure chaque variante, l'une après l'autre, sur l'instance de banc d'essai.
# Usage : npm run bench [-- fluffless-gratuit fluffless ollie elementor]
# Options de mesure transmises par variables : BENCH_RUNS (défaut 3), BENCH_LIGHTHOUSE=0 pour l'omettre.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
compose=(docker compose -f "$here/compose.yaml")

if [ "$#" -gt 0 ]; then
	variants=("$@")
else
	variants=(fluffless-gratuit fluffless ollie elementor)
fi

for variant in "${variants[@]}"; do
	"$here/setup.sh" "$variant"
	versions="$("${compose[@]}" run --rm -T cli wp eval-file /bench/versions.php)"
	node "$here/measure.mjs" --label "$variant" --versions "$versions"
done
