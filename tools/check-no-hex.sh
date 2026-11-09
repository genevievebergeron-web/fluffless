#!/usr/bin/env bash
# Échoue si un pattern ou un gabarit du thème, ou un pattern de Fluffless Suite, contient une couleur hexadécimale en dur.
# Toutes les couleurs doivent passer par les jetons de theme.json.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
targets=(
	"$root/packages/fluffless/patterns"
	"$root/packages/fluffless/templates"
	"$root/packages/fluffless/parts"
	"$root/packages/fluffless-suite/patterns"
)

if matches="$(grep -RInE '#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?([0-9a-fA-F]{2})?\b' "${targets[@]}" 2>/dev/null)"; then
	echo "Couleurs hexadécimales en dur trouvées (utiliser les jetons) :" >&2
	echo "$matches" >&2
	exit 1
fi

echo "OK : aucune couleur hexadécimale en dur dans les patterns, gabarits et parts."
