<?php
/**
 * Exporte le contenu rendu (PHP exécuté) de chaque pattern fluffless/* dans une langue.
 *
 * Lu sur STDIN par `wp eval-file - <locale>` (voir tools/export-patterns.mjs) : le
 * conteneur ne monte pas tools/. Une langue par processus : le registre des patterns
 * met en cache le contenu au premier rendu, une bascule de langue ne le régénère pas.
 * Sortie : un objet JSON { slug: contenu } encadré par des marqueurs, pour que les avis
 * PHP éventuels écrits sur la sortie standard ne corrompent pas le JSON.
 *
 * @package Fluffless
 */

$fluffless_locale = $args[0] ?? '';
// switch_to_locale() renvoie false quand la langue demandée est déjà celle du site.
$fluffless_switched = determine_locale() === $fluffless_locale || switch_to_locale( $fluffless_locale );
if ( ! in_array( $fluffless_locale, get_available_languages(), true ) || ! $fluffless_switched ) {
	WP_CLI::error( sprintf( 'Langue « %s » non installée. Reçu en argument, attendu : %s.', $fluffless_locale, implode( ', ', get_available_languages() ) ) );
}

// Les blocs accrochés (Block Hooks) d'autres extensions, comme le mini-panier de WooCommerce dans
// l'en-tête, ne font pas partie du thème : le validateur ne connaît pas leurs blocs et les jugerait manquants.
add_filter( 'hooked_block_types', '__return_empty_array', PHP_INT_MAX );

$fluffless_export = array();
foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $fluffless_pattern ) {
	if ( str_starts_with( $fluffless_pattern['name'], 'fluffless/' ) ) {
		$fluffless_export[ $fluffless_pattern['name'] ] = $fluffless_pattern['content'];
	}
}

echo "\n<<<FLUFFLESS_JSON\n";
echo wp_json_encode( $fluffless_export, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
echo "\nFLUFFLESS_JSON>>>\n";
