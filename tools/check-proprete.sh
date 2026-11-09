#!/usr/bin/env bash
# Propreté du dépôt, contrôles git grep, git diff et git log :
#   1. aucun tiret cadratin (U+2014) dans packages/ et tools/ (deux-points, virgule ou parenthèses),
#      ni dans les lignes de docs/ ajoutées depuis la base ;
#   2. aucun nom de client hors docs/ : la liste ne vit pas dans le dépôt (produit générique), elle vient
#      de FLUFFLESS_NOMS_CLIENTS (noms séparés par des virgules ; secret du même nom dans la CI GitHub)
#      ou du fichier non suivi .git/info/noms-clients (un nom par ligne). Sans liste, ce contrôle est
#      annoncé comme sauté ;
#   3. aucune mention de l'outil de rédaction (mots listés dans $outil) dans les fichiers suivis, sauf
#      les fichiers de $exempts ;
#   4. dans les commits postérieurs à la base : ni auteur ni courriel de commit de l'outil, ni trailer
#      de cosignature (Co-authored-by), ni ligne « generated with ».
# La base est le dernier commit de main publié avant ce contrôle : l'historique déjà publié ne se
# réécrit pas (procédure dans le coffre Rome, groupe Fluffless, proprete-depot-2026-11), il est donc exempté en bloc. Sans la base (clone
# superficiel), les contrôles d'historique sont annoncés comme sautés.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

failed=0
skipped=()
dash="$(printf '\342\200\224')"
# Mots coupés pour que ce script ne se signale pas lui-même.
outil='clau''de|anthro''pic'
# Base : main au 7 nov. 2026 (b714c53). Elle contient les 23 commits d'auteur outil et 1c73196.
base='b714c53a5f5a1800e3cc6382c208ef79fca6f0f8'
# Fichiers exemptés du contrôle des mots (vide depuis la sortie des docs internes, 7 nov. 2026).
exempts=()

if matches="$(git grep -nIF -e "$dash" -- packages tools)"; then
	echo "Tirets cadratins trouvés (remplacer par deux-points, virgule ou parenthèses) :" >&2
	echo "$matches" >&2
	failed=1
fi

names=()
if [ -n "${FLUFFLESS_NOMS_CLIENTS:-}" ]; then
	IFS=',' read -r -a names <<< "$FLUFFLESS_NOMS_CLIENTS"
else
	list="$(git rev-parse --git-path info/noms-clients)"
	if [ -f "$list" ]; then
		while IFS= read -r name; do
			[ -n "$name" ] && names+=("$name")
		done < "$list"
	fi
fi
if [ "${#names[@]}" -eq 0 ]; then
	skipped+=("noms de clients (ni FLUFFLESS_NOMS_CLIENTS ni .git/info/noms-clients)")
else
	patterns=()
	for name in "${names[@]}"; do
		patterns+=(-e "$name")
	done
	if matches="$(git grep -nIiF "${patterns[@]}" -- . ':!docs')"; then
		echo "Noms de client trouvés hors docs/ (produit générique) :" >&2
		echo "$matches" >&2
		failed=1
	fi
fi

if matches="$(git grep -nIiwE -e "$outil" -- . ${exempts[@]+"${exempts[@]}"})"; then
	echo "Mention de l'outil de rédaction dans des fichiers suivis :" >&2
	echo "$matches" >&2
	failed=1
fi

if git cat-file -e "${base}^{commit}" 2>/dev/null; then
	if matches="$(git diff -U0 "$base" -- docs | grep -E '^\+[^+]' | grep -F "$dash")"; then
		echo "Tirets cadratins ajoutés dans docs/ depuis la base :" >&2
		echo "$matches" >&2
		failed=1
	fi
	if matches="$(git log --format='%h %an <%ae> / %cn <%ce>' "${base}..HEAD" | grep -iE "$outil")"; then
		echo "Auteur ou courriel de commit de l'outil :" >&2
		echo "$matches" >&2
		failed=1
	fi
	if matches="$(git log --format='%h %s%n%b' "${base}..HEAD" | grep -iE '^[[:space:]]*co-authored-by:|generated with')"; then
		echo "Trailer de cosignature ou « generated with » dans des messages de commit :" >&2
		echo "$matches" >&2
		failed=1
	fi
else
	skipped+=("historique et cadratins de docs/ (base ${base:0:7} absente : clone superficiel)")
fi

if [ "$failed" -ne 0 ]; then
	exit 1
fi
for note in ${skipped[@]+"${skipped[@]}"}; do
	echo "SAUTÉ : $note."
done
echo "OK : propreté des fichiers et des commits postérieurs à ${base:0:7}."
