#!/usr/bin/env bash
# Échoue si le HTML rendu d'une URL, ou une feuille de style qu'il charge depuis la même
# origine, contient une couleur hexadécimale en dur. Ne remplace pas check:hex (qui vérifie
# la source du thème dans ce dépôt) : vérifie le résultat servi, utile quand le thème audité
# vit dans un autre dépôt (banc de rendu) et qu'on ne peut pas viser un chemin de thème en dur.
# Usage : tools/check-hex-urls.sh <url...>             (une ou plusieurs URL en argument)
#         tools/check-hex-urls.sh <fichier-liste.txt>   (une URL par ligne, lignes vides et # ignorées)
set -euo pipefail

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
	echo "Aucune URL à vérifier." >&2
	exit 2
fi

hex_re='#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?([0-9a-fA-F]{2})?\b'
failures=0
for url in "${urls[@]}"; do
	html_tmp="$(mktemp)"
	if ! curl -fsSL --compressed "$url" -o "$html_tmp"; then
		echo "ÉCHEC $url : requête impossible" >&2
		failures=$((failures + 1))
		rm -f "$html_tmp"
		continue
	fi
	origin="$(printf '%s' "$url" | sed -E 's#^(https?://[^/]+).*#\1#')"
	found=0
	if matches="$(grep -nE "$hex_re" "$html_tmp" 2>/dev/null)"; then
		echo "NON $url (HTML) :"
		echo "$matches" | cut -c1-160
		found=1
	fi
	stylesheets="$(grep -oE "href=[\"'][^\"']+\.css(\?[^\"']*)?[\"']" "$html_tmp" \
		| sed -E "s/^href=[\"']//; s/[\"']\$//" | sort -u || true)"
	while IFS= read -r css; do
		[ -z "$css" ] && continue
		case "$css" in
			http*) full="$css" ;;
			//*) full="https:$css" ;;
			/*) full="$origin$css" ;;
			*) full="$origin/$css" ;;
		esac
		case "$full" in
			"$origin"*) ;;
			*) continue ;; # feuille tierce : hors périmètre du thème audité.
		esac
		css_tmp="$(mktemp)"
		if curl -fsSL --compressed "$full" -o "$css_tmp" 2>/dev/null; then
			if matches="$(grep -nE "$hex_re" "$css_tmp" 2>/dev/null)"; then
				echo "NON $full (feuille liée depuis $url) :"
				echo "$matches" | cut -c1-160
				found=1
			fi
		fi
		rm -f "$css_tmp"
	done <<< "$stylesheets"
	rm -f "$html_tmp"
	if [ "$found" -eq 1 ]; then
		failures=$((failures + 1))
	else
		echo "ok  $url"
	fi
done

if [ "$failures" -gt 0 ]; then
	echo "" >&2
	echo "$failures URL sur ${#urls[@]} avec au moins une couleur hexadécimale en dur." >&2
	exit 1
fi
echo ""
echo "OK : ${#urls[@]} URL sans couleur hexadécimale en dur (HTML et feuilles liées)."
