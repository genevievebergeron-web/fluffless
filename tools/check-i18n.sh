#!/usr/bin/env bash
# Échoue si une traduction fr_CA contient des chaînes non traduites (msgstr vide) ou floues (fuzzy).
# Usage : npm run check:i18n
# Prérequis : gettext sur la machine hôte (msgfmt, msgattrib ; brew install gettext).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Instance de dev par défaut ; COMPOSE_FILE en vise une autre (voir tools/run-tests.sh).
compose=(docker compose)
if [ -z "${COMPOSE_FILE:-}" ]; then
	compose+=(-f "$root/compose.yaml")
fi
files=(
	"$root/packages/fluffless/languages/fr_CA.po"
	"$root/packages/fluffless-suite/languages/fluffless-suite-fr_CA.po"
	"$root/packages/fluffless-seo/languages/fluffless-seo-fr_CA.po"
	"$root/packages/fluffless-consent/languages/fluffless-consent-fr_CA.po"
)
# Exemples montrés par fichier et par type de problème : assez pour se repérer, pas un fichier entier.
max_examples=10

for tool in msgfmt msgattrib; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "$tool introuvable : installer gettext (brew install gettext)." >&2
		exit 2
	fi
done

# Messages de gettext en anglais, quelle que soit la langue du terminal : le décompte en dépend.
export LC_ALL=C

failures=0
for po in "${files[@]}"; do
	rel="${po#"$root"/}"
	if [ ! -f "$po" ]; then
		echo "$rel : fichier absent." >&2
		failures=$((failures + 1))
		continue
	fi
	if ! stats="$(msgfmt --statistics -o /dev/null "$po" 2>&1)"; then
		echo "$rel : msgfmt refuse le fichier :" >&2
		echo "$stats" >&2
		failures=$((failures + 1))
		continue
	fi
	fuzzy="$(grep -oE '[0-9]+ fuzzy' <<<"$stats" | grep -oE '[0-9]+' || echo 0)"
	untranslated="$(grep -oE '[0-9]+ untranslated' <<<"$stats" | grep -oE '[0-9]+' || echo 0)"
	if [ "$fuzzy" -eq 0 ] && [ "$untranslated" -eq 0 ]; then
		echo "OK : $rel ($stats)"
		continue
	fi
	failures=$((failures + 1))
	echo "$rel : $untranslated non traduite(s), $fuzzy floue(s)." >&2
	for kind in untranslated only-fuzzy; do
		examples="$(msgattrib "--$kind" --no-obsolete --no-wrap "$po" | grep -E '^msgid "[^"]' | head -n "$max_examples" || true)"
		if [ -n "$examples" ]; then
			echo "  $kind (${max_examples} premières au plus) :" >&2
			sed 's/^/    /' <<<"$examples" >&2
		fi
	done
done

# Chaînes présentes dans le code mais absentes du .po : on régénère le .pot à part, sans toucher à languages/.
# Désactivable (CHECK_I18N_POT=0) sur une machine sans Docker.
if [ "${CHECK_I18N_POT:-1}" = "1" ]; then
	tmp="$(mktemp -d)"
	trap 'rm -rf "$tmp"' EXIT
	for pair in "fluffless:themes/fluffless:fr_CA.po" "fluffless-suite:plugins/fluffless-suite:fluffless-suite-fr_CA.po" "fluffless-seo:plugins/fluffless-seo:fluffless-seo-fr_CA.po" "fluffless-consent:plugins/fluffless-consent:fluffless-consent-fr_CA.po"; do
		IFS=: read -r slug path po_name <<<"$pair"
		dir="${path#*/}"
		"${compose[@]}" run --rm -T cli sh -c \
			"wp i18n make-pot wp-content/$path /tmp/check.pot --slug=$slug --domain=$slug --exclude=vendor,node_modules,languages,tests --skip-audit >/dev/null && cat /tmp/check.pot" \
			>"$tmp/$slug.pot" 2>/dev/null || true
		if ! grep -q '^msgid' "$tmp/$slug.pot"; then
			echo "$slug : impossible de régénérer le .pot (Docker lancé ?)." >&2
			failures=$((failures + 1))
			continue
		fi
		po="$root/packages/$dir/languages/$po_name"
		if ! missing="$(msgcmp --use-untranslated "$po" "$tmp/$slug.pot" 2>&1)"; then
			count="$(grep -c 'this message is used but not defined' <<<"$missing" || true)"
			echo "$slug : $count chaîne(s) du code absente(s) du .po (lancer npm run i18n:pot && npm run i18n:update, puis traduire)." >&2
			failures=$((failures + 1))
		else
			echo "OK : $slug, toutes les chaînes du code sont dans le .po."
		fi
	done
fi

if [ "$failures" -gt 0 ]; then
	echo "ÉCHEC : $failures problème(s) de traduction." >&2
	exit 1
fi
echo "OK : traductions fr_CA complètes, aucune chaîne floue."
