#!/usr/bin/env bash
# Le thème gratuit seul, sans Fluffless Suite : ses patterns s'enregistrent, ses gabarits,
# parts et patterns n'utilisent que des blocs connus, et l'accueil se rend sans bloc orphelin.
# Suite est désactivée le temps du test, puis remise dans son état de départ (trap).
# Usage : npm run test:theme-only
# Prérequis : npm run wp:up, thème fluffless actif.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Instance de dev par défaut ; COMPOSE_FILE en vise une autre (voir tools/run-tests.sh).
compose=(docker compose)
if [ -z "${COMPOSE_FILE:-}" ]; then
	compose+=(-f "$root/compose.yaml")
fi
# Nombre de patterns livrés par le thème (décision A du partage gratuit et payant).
expected_patterns=36
home_url="${FLUFFLESS_HOME_URL:-http://127.0.0.1:8882/}"
wp() {
	"${compose[@]}" run --rm -T cli wp "$@" 2>/dev/null
}

was_active=0
if wp plugin is-active fluffless-suite; then
	was_active=1
fi

restore() {
	if [ "$was_active" -eq 1 ]; then
		wp plugin activate fluffless-suite >/dev/null || echo "Attention : fluffless-suite est restée désactivée." >&2
	fi
}
trap restore EXIT

if [ "$was_active" -eq 1 ]; then
	wp plugin deactivate fluffless-suite >/dev/null
fi

# Un seul passage WP-CLI : patterns du thème, puis tout nom de bloc non enregistré dans
# les gabarits, les parts et les patterns du thème (parse_blocks, récursif, borné).
report="$(wp eval '
	$theme  = wp_normalize_path( get_template_directory() );
	$count  = 0;
	$markup = array();
	foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p ) {
		if ( str_starts_with( wp_normalize_path( (string) ( $p["filePath"] ?? "" ) ), $theme . "/patterns/" ) ) {
			++$count;
			$markup[ "pattern " . $p["name"] ] = (string) ( $p["content"] ?? "" );
		}
	}
	foreach ( array( "wp_template", "wp_template_part" ) as $type ) {
		foreach ( get_block_templates( array( "theme" => get_stylesheet() ), $type ) as $t ) {
			$markup[ $type . " " . $t->slug ] = (string) $t->content;
		}
	}
	$registry = WP_Block_Type_Registry::get_instance();
	$unknown  = array();
	$walk     = static function ( array $blocks, string $where, int $depth ) use ( &$walk, &$unknown, $registry ): void {
		if ( $depth > 50 ) {
			$unknown[] = $where . " : imbrication de plus de 50 niveaux";
			return;
		}
		foreach ( $blocks as $b ) {
			$name = $b["blockName"] ?? null;
			if ( is_string( $name ) && ! $registry->is_registered( $name ) ) {
				$unknown[] = $where . " : " . $name;
			}
			$walk( $b["innerBlocks"] ?? array(), $where, $depth + 1 );
		}
	};
	foreach ( $markup as $where => $content ) {
		$walk( parse_blocks( $content ), $where, 0 );
	}
	echo wp_json_encode( array( "patterns" => $count, "unknown" => array_values( array_unique( $unknown ) ) ) );
')"

failures=0
patterns="$(node -e 'const r = JSON.parse(process.argv[1]); console.log(r.patterns)' "$report")"
unknown="$(node -e 'const r = JSON.parse(process.argv[1]); console.log(r.unknown.join("\n"))' "$report")"
if [ "$patterns" -ne "$expected_patterns" ]; then
	echo "Patterns du thème enregistrés : $patterns (attendu : $expected_patterns)." >&2
	failures=$((failures + 1))
fi
if [ -n "$unknown" ]; then
	echo "Blocs non enregistrés sans Suite :" >&2
	sed 's/^/  /' <<<"$unknown" >&2
	failures=$((failures + 1))
fi

# Accueil rendu : réponse 200, aucun commentaire de bloc laissé tel quel, aucun avis d'erreur PHP.
status="$(curl -s -o "${TMPDIR:-/tmp}/fluffless-theme-only.html" -w '%{http_code}' "$home_url")"
if [ "$status" != 200 ]; then
	echo "Accueil : HTTP $status ($home_url)." >&2
	failures=$((failures + 1))
elif grep -qE '<!-- /?wp:|Fatal error|Warning: ' "${TMPDIR:-/tmp}/fluffless-theme-only.html"; then
	echo "Accueil : bloc non rendu ou erreur PHP dans le HTML." >&2
	failures=$((failures + 1))
fi
rm -f "${TMPDIR:-/tmp}/fluffless-theme-only.html"

if [ "$failures" -gt 0 ]; then
	echo "ÉCHEC : thème seul, $failures problème(s)." >&2
	exit 1
fi
echo "OK : thème seul, $patterns patterns, aucun bloc orphelin dans les gabarits, parts et patterns, accueil HTTP 200."
