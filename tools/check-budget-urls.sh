#!/usr/bin/env bash
# Vérifie que chaque URL rendue respecte tous les budgets de tools/budgets.json (chemin « /* ») :
# poids par type (total, script, stylesheet, font, image, third-party, en ko de 1024 o transférés)
# et nombre de requêtes par type. Bloquant : sortie 1 au premier budget dépassé sur une URL.
# Contrepartie de check:hex/check:contrast contre une liste d'URL : utile quand le thème audité
# vit dans un autre dépôt (banc de rendu) et qu'on ne peut pas viser un chemin de thème en dur.
# Usage : tools/check-budget-urls.sh <url...>             (une ou plusieurs URL en argument)
#         tools/check-budget-urls.sh <fichier-liste.txt>   (une URL par ligne, lignes vides et # ignorées)
#         BUDGETS=chemin/budgets.json tools/check-budget-urls.sh <url>   (autre fichier de budgets)
# Mesure indicative sans navigateur (curl, pas de rendu réel) : seules les ressources nommées dans un
# attribut src= ou href= du HTML sont vues (pas les url() des feuilles ni ce que le JavaScript charge).
# npm run lhci fait foi pour le budget certifié. Reprend la logique de mesure de tools/measure.sh.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
budgets_file="${BUDGETS:-$root/tools/budgets.json}"

if [ "$#" -eq 0 ]; then
	echo "Usage : $0 <url...> | <fichier-liste.txt>" >&2
	exit 2
fi

urls=()
if [ "$#" -eq 1 ] && [ -f "$1" ]; then
	while IFS= read -r line; do
		line="${line%%#*}"
		line="$(printf '%s' "$line" | sed -E 's/^[[:space:]]+|[[:space:]]+$//g')"
		[ -n "$line" ] && urls+=("$line")
	done < "$1"
else
	urls=("$@")
fi

if [ "${#urls[@]}" -eq 0 ]; then
	echo "Aucune URL à mesurer." >&2
	exit 2
fi

# Une ligne par budget : « size|count type budget ». Un fichier illisible ou sans budget arrête tout.
budgets="$(node -e '
	const list = JSON.parse( require( "fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
	const entry = list.find( ( b ) => b.path === "/*" );
	if ( ! entry ) { console.error( "Aucun budget pour le chemin /*." ); process.exit( 1 ); }
	const out = [];
	for ( const b of entry.resourceSizes || [] ) out.push( `size ${ b.resourceType } ${ b.budget }` );
	for ( const b of entry.resourceCounts || [] ) out.push( `count ${ b.resourceType } ${ b.budget }` );
	if ( ! out.length ) { console.error( "Budgets vides." ); process.exit( 1 ); }
	console.log( out.join( "\n" ) );
' "$budgets_file")" || { echo "Budgets illisibles : $budgets_file" >&2; exit 2; }

# Type Lighthouse d'une ressource d'après son extension.
kind_of() {
	case "${1%%\?*}" in
		*.js) echo script ;;
		*.css) echo stylesheet ;;
		*.woff | *.woff2) echo font ;;
		*) echo image ;;
	esac
}

failures=0
for url in "${urls[@]}"; do
	tmp="$(mktemp)"
	if ! curl -fsSL --compressed "$url" -o "$tmp"; then
		echo "ÉCHEC $url : requête impossible" >&2
		failures=$((failures + 1))
		rm -f "$tmp"
		continue
	fi
	if grep -q 'fluffless-maintenance' "$tmp"; then
		echo "ÉCHEC $url : page de maintenance mesurée (mode Bientôt actif)" >&2
		failures=$((failures + 1))
		rm -f "$tmp"
		continue
	fi
	origin="$(printf '%s' "$url" | sed -E 's#^(https?://[^/]+).*#\1#')"
	resources="$(grep -oE "(src|href)=[\"'][^\"']+\.(css|js|woff2?|png|jpe?g|webp|avif|svg)(\?[^\"']*)?[\"']" "$tmp" \
		| sed -E "s/^(src|href)=[\"']//; s/[\"']\$//" | sort -u || true)"
	# Octets transférés (gzip demandé, non décodé) et nombre de requêtes, par type ; le HTML compte dans total.
	# Variables s_<type> et n_<type> (sans tableau associatif : le bash 3.2 du Mac doit suffire).
	html_wire="$(curl -fsSL -H 'Accept-Encoding: gzip' -o /dev/null -w '%{size_download}' "$url" || echo 0)"
	# shellcheck disable=SC2034 # lues par indirection (${!name}) dans la boucle des budgets.
	s_total="$html_wire" s_script=0 s_stylesheet=0 s_font=0 s_image=0 s_third_party=0
	# shellcheck disable=SC2034
	n_total=1 n_script=0 n_stylesheet=0 n_font=0 n_image=0 n_third_party=0
	while IFS= read -r res; do
		[ -z "$res" ] && continue
		case "$res" in
			http*) full="$res" ;;
			//*) full="https:$res" ;;
			/*) full="$origin$res" ;;
			*) full="$origin/$res" ;;
		esac
		bytes="$(curl -fsSL -H 'Accept-Encoding: gzip' -o /dev/null -w '%{size_download}' "$full" 2>/dev/null || echo 0)"
		kind="$(kind_of "$full")"
		kinds=(total "$kind")
		case "$full" in
			"$origin"/* | "$origin") ;;
			*) kinds+=(third_party) ;;
		esac
		for k in "${kinds[@]}"; do
			sv="s_$k"
			nv="n_$k"
			printf -v "$sv" '%s' "$((${!sv} + bytes))"
			printf -v "$nv" '%s' "$((${!nv} + 1))"
		done
	done <<< "$resources"
	rm -f "$tmp"

	over=0
	while read -r measure type budget; do
		var="${type//-/_}"
		if [ "$measure" = size ]; then
			name="s_$var"
			value="${!name:-0}"
			limit=$((budget * 1024))
			unit="o"
		else
			name="n_$var"
			value="${!name:-0}"
			limit="$budget"
			unit="requêtes"
		fi
		if [ "$value" -gt "$limit" ]; then
			printf 'NON %s : %s %s, %s %s > budget %s\n' "$url" "$measure" "$type" "$value" "$unit" "$limit"
			over=$((over + 1))
		fi
	done <<< "$budgets"
	if [ "$over" -gt 0 ]; then
		failures=$((failures + 1))
	else
		printf 'ok  %s : %s o en %s requêtes (script %s o, stylesheet %s o, font %s o, tiers %s)\n' \
			"$url" "$s_total" "$n_total" "$s_script" "$s_stylesheet" "$s_font" "$n_third_party"
	fi
done

if [ "$failures" -gt 0 ]; then
	echo "" >&2
	echo "$failures échec(s) sur ${#urls[@]} URL." >&2
	exit 1
fi
echo ""
echo "OK : ${#urls[@]} URL sous tous les budgets de $(basename "$budgets_file")."
