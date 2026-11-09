#!/usr/bin/env bash
# Échoue si un fichier PHP des paquets peut s'exécuter en accès direct : chaque fichier suivi doit
# porter defined( 'ABSPATH' ) || exit; (ou la garde WP_UNINSTALL_PLUGIN pour uninstall.php).
# Hors contrôle : tests/ (lancés par WP-CLI), languages/ (catalogues générés), *.asset.php et
# icons.php (tableaux générés par les outils de construction, sans code exécuté).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

missing=()
while IFS= read -r file; do
	case "$file" in
		*/tests/* | */languages/* | *.asset.php | */icons.php) continue ;;
	esac
	if ! grep -qE "defined\( *'(ABSPATH|WP_UNINSTALL_PLUGIN)' *\)" "$file"; then
		missing+=("$file")
	fi
done < <(git ls-files -- 'packages/*.php')

if [ "${#missing[@]}" -gt 0 ]; then
	echo "Fichiers PHP sans garde d'accès direct (ajouter defined( 'ABSPATH' ) || exit;) :" >&2
	printf '  %s\n' "${missing[@]}" >&2
	exit 1
fi

echo "OK : chaque fichier PHP des paquets porte une garde d'accès direct."
