#!/usr/bin/env bash
# Mesure rapide d'une page : poids HTML, ressources liées et domaines appelés.
# Usage : tools/measure.sh [url]   (défaut : http://127.0.0.1:8882/)
# Deux colonnes : « transféré » (octets sur le réseau, gzip) et « décodé » (octets une fois décompressés).
# Les budgets de tools/budgets.json (Lighthouse) comptent le transféré des ressources liées ; le CSS et le
# JavaScript en ligne sont dans le HTML et se lisent à part.
# Mesure indicative sans navigateur ; Lighthouse CI (npm run lhci) fait foi.
set -euo pipefail

url="${1:-http://127.0.0.1:8882/}"
origin="$(printf '%s' "$url" | sed -E 's#^(https?://[^/]+).*#\1#')"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

curl -fsSL --compressed "$url" -o "$tmp"
# Octets sur le réseau : on demande du gzip sans le décoder, donc size_download est la taille transférée.
wire() { curl -fsSL -H 'Accept-Encoding: gzip' -o /dev/null -w '%{size_download}' "$1" || echo 0; }
# Octets décodés.
plain() { curl -fsSL --compressed "$1" | wc -c | tr -d ' ' || echo 0; }
# Octets du contenu des balises <style> ou <script> sans src, dans le HTML déjà téléchargé.
inline_bytes() {
	perl -Mbytes -0777 -ne 'while (/<'"$1"'(?![^>]*\bsrc=)[^>]*>(.*?)<\/'"$1"'>/gs) { $n += length($1) } print $n + 0' "$tmp"
}
if grep -q 'fluffless-maintenance' "$tmp"; then
	echo "Page de maintenance mesurée (mode Bientôt actif) : éteindre le module Maintenance ou mesurer connecté." >&2
	exit 1
fi
html_bytes="$(wc -c < "$tmp" | tr -d ' ')"
html_wire="$(wire "$url")"
inline_css="$(inline_bytes style)"
inline_js="$(inline_bytes script)"

# Ressources référencées par src= ou href= de feuilles de style.
resources="$(grep -oE "(src|href)=[\"'][^\"']+\.(css|js|woff2?|png|jpe?g|webp|avif|svg)(\?[^\"']*)?[\"']" "$tmp" \
	| sed -E "s/^(src|href)=[\"']//; s/[\"']$//" | sort -u || true)"

total="$html_bytes"
total_wire="$html_wire"
count=1
printf '%10s %10s  %s\n' 'transféré' 'décodé' 'ressource'
printf '%10s %10s  %s\n' "$html_wire" "$html_bytes" "$url (HTML)"
third_party=0
while IFS= read -r res; do
	[ -z "$res" ] && continue
	case "$res" in
		http*) full="$res" ;;
		//*) full="https:$res" ;;
		/*) full="$origin$res" ;;
		*) full="$origin/$res" ;;
	esac
	case "$full" in
		"$origin"*) ;;
		*) third_party=$((third_party + 1)); echo "Tiers : $full" ;;
	esac
	size="$(plain "$full")"
	size_wire="$(wire "$full")"
	total=$((total + size))
	total_wire=$((total_wire + size_wire))
	count=$((count + 1))
	printf '%10s %10s  %s\n' "$size_wire" "$size" "$full"
done <<< "$resources"

printf '\nHTML : %s o transférés, %s o décodés\n' "$html_wire" "$html_bytes"
printf 'Dans le HTML : %s o de CSS en ligne, %s o de JavaScript en ligne (décodés)\n' "$inline_css" "$inline_js"
printf 'Total transféré : %s o (%s ko), décodé : %s o (%s ko), %s requêtes, %s tierces\n' \
	"$total_wire" "$((total_wire / 1024))" "$total" "$((total / 1024))" "$count" "$third_party"
