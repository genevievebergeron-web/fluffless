<?php
/**
 * Tests du territoire du thème (audit final L1, L2) : rien n'est créé à l'activation du thème
 * et la page de connexion n'est pas habillée par le thème (la Suite s'en charge).
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/territoire-test.php
 * Aucune écriture : les crochets sont inspectés, pas déclenchés.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

if ( 'fluffless' !== get_template() ) {
	throw new RuntimeException( 'Thème Fluffless inactif : wp theme activate fluffless.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// Rappels branchés sur un crochet dont le code vit dans le dossier du thème parent.
$fluffless_theme_callbacks = static function ( string $hook ): array {
	global $wp_filter;
	$found = array();
	$dir   = wp_normalize_path( get_template_directory() ) . '/';
	if ( ! isset( $wp_filter[ $hook ] ) ) {
		return $found;
	}
	foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'];
			try {
				if ( is_string( $function ) && function_exists( $function ) ) {
					$reflection = new ReflectionFunction( $function );
				} elseif ( $function instanceof Closure ) {
					$reflection = new ReflectionFunction( $function );
				} elseif ( is_array( $function ) && 2 === count( $function ) ) {
					$reflection = new ReflectionMethod( $function[0], (string) $function[1] );
				} else {
					continue;
				}
			} catch ( ReflectionException $e ) {
				continue;
			}
			$file = $reflection->getFileName();
			if ( is_string( $file ) && str_starts_with( wp_normalize_path( $file ), $dir ) ) {
				$found[] = $reflection->getName();
			}
		}
	}
	return $found;
};

// L1 : aucune entité créée à l'activation du thème ; le menu naît au premier besoin.
$fluffless_assert( array() === $fluffless_theme_callbacks( 'after_switch_theme' ), 'L1 : rien du thème sur after_switch_theme.' );
$fluffless_assert( ! function_exists( 'fluffless_navigation_on_switch' ), 'L1 : fluffless_navigation_on_switch retirée.' );
$fluffless_assert( function_exists( 'fluffless_navigation_id' ), 'L1 : le menu partagé se crée toujours au besoin.' );

// L2 : la page de connexion n'est pas du ressort du thème.
foreach ( array( 'login_enqueue_scripts', 'login_head', 'login_headerurl', 'login_headertext', 'login_footer', 'login_init' ) as $fluffless_hook ) {
	$fluffless_assert( array() === $fluffless_theme_callbacks( $fluffless_hook ), 'L2 : rien du thème sur ' . $fluffless_hook . '.' );
}
foreach ( array( 'fluffless_login_styles', 'fluffless_login_css', 'fluffless_login_variables', 'fluffless_login_logo_css', 'fluffless_login_header_url', 'fluffless_login_header_text' ) as $fluffless_function ) {
	$fluffless_assert( ! function_exists( $fluffless_function ), 'L2 : ' . $fluffless_function . ' retirée du thème.' );
}
$fluffless_assert( ! file_exists( get_template_directory() . '/assets/css/login.css' ), 'L2 : assets/css/login.css retiré du thème.' );

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		WP_CLI::warning( $fluffless_failure );
	}
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . ' vérifications.' );
}
WP_CLI::success( 'Territoire du thème : ' . $fluffless_checks . ' vérifications passées.' );
