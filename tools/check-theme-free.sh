#!/usr/bin/env bash
# Échoue si le thème gratuit dépend de Fluffless Suite : sans l'extension payante, un bloc
# fluffless/* serait orphelin (« contenu non pris en charge ») dans l'éditeur, et un appel
# <!-- wp:pattern --> vers une composition de Suite ne rendrait rien.
# Usage : npm run check:theme-free
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
targets=(
	"$root/packages/fluffless/patterns"
	"$root/packages/fluffless/parts"
	"$root/packages/fluffless/templates"
)

if matches="$(grep -RIn 'wp:fluffless/' "${targets[@]}" 2>/dev/null)"; then
	echo "Blocs de Fluffless Suite dans le thème gratuit (les déplacer dans packages/fluffless-suite/patterns) :" >&2
	echo "${matches//"$root"\//}" >&2
	exit 1
fi

# Chaque composition appelée par slug doit être déclarée par le thème lui-même.
theme_slugs="$(sed -n 's/^ \* Slug: *//p' "$root"/packages/fluffless/patterns/*.php | sort -u)"
foreign=""
while IFS= read -r line; do
	[ -n "$line" ] || continue
	slug="$(sed -E 's/.*"slug":"([^"]+)".*/\1/' <<< "$line")"
	if ! grep -qxF "$slug" <<< "$theme_slugs"; then
		foreign+="${line//"$root"\//}"$'\n'
	fi
done < <(grep -RInE '<!-- wp:pattern \{"slug":"[^"]+"' "${targets[@]}" 2>/dev/null || true)

if [ -n "$foreign" ]; then
	echo "Compositions appelées par le thème gratuit sans y être déclarées (copier leur contenu ou les déclarer dans packages/fluffless/patterns) :" >&2
	printf '%s' "$foreign" >&2
	exit 1
fi

echo "OK : aucun bloc fluffless/* ni appel de composition hors thème dans les patterns, parts et gabarits."
