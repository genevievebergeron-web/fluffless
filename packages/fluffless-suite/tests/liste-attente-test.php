<?php
/**
 * Test de la liste d'attente de fluffless.dev : elle appartient au site, pas à la Suite (P9a-01).
 * Aucun patron livré aux clientes ne porte le titre, l'adresse ou le formulaire de cette liste.
 *
 * Lancer : npm run test:tools liste-attente
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;

$fluffless_assert = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_dir = dirname( __DIR__ ) . '/patterns';
$fluffless_assert( ! file_exists( $fluffless_dir . '/liste-attente.php' ), 'patterns/liste-attente.php retiré de la Suite' );

$fluffless_glob  = glob( $fluffless_dir . '/*.php' );
$fluffless_files = false === $fluffless_glob ? array() : $fluffless_glob;
$fluffless_assert( count( $fluffless_files ) > 0, 'des patrons de la Suite sont lus' );

$fluffless_motifs = array(
	'Slug: fluffless/liste-attente' => 'slug fluffless/liste-attente',
	'"formId":"listeattente"'       => 'formulaire listeattente',
	'fluffless.dev'                 => 'adresse fluffless.dev',
	'Follow Fluffless until launch' => 'titre de la liste d\'attente du lancement',
);
foreach ( $fluffless_files as $fluffless_file ) {
	$fluffless_src = (string) file_get_contents( $fluffless_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local de l'extension.
	foreach ( $fluffless_motifs as $fluffless_motif => $fluffless_nom ) {
		$fluffless_assert( ! str_contains( $fluffless_src, $fluffless_motif ), basename( $fluffless_file ) . ' sans ' . $fluffless_nom );
	}
}

if ( $fluffless_failures ) {
	throw new RuntimeException( esc_html( 'liste-attente : ' . implode( ' | ', $fluffless_failures ) ) );
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo 'liste-attente-test : ' . $fluffless_checks . " vérifications OK\n";
