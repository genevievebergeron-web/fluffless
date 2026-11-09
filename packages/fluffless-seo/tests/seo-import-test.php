<?php
/**
 * Tests de l'importation depuis Yoast SEO, SEOPress et Rank Math : simulation sans écriture,
 * données d'origine gardées, champs déjà remplis jamais remplacés, variables converties.
 *
 * Lancer : npm run test:tools -- seo-import
 * Les contenus créés ici sont supprimés à la fin.
 *
 * @package Fluffless_Seo
 */

use Fluffless\Seo\Importer;
use Fluffless\Seo\Meta;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

// Extension optionnelle : inactive sur le banc, le test est sauté et le dit, sans échouer.
if ( ! class_exists( Importer::class ) ) {
	echo esc_html( "SAUTÉ : Fluffless SEO inactive (wp plugin activate fluffless-seo pour lancer ces contrôles).\n" );
	return;
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_posts = array();
$fluffless_new   = static function ( array $meta ) use ( &$fluffless_posts ): int {
	$id = (int) wp_insert_post(
		array(
			'post_title'  => 'Import SEO ' . wp_generate_password( 6, false ),
			'post_status' => 'draft',
			'post_type'   => 'post',
		)
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	$fluffless_posts[] = $id;
	return $id;
};

try {
	// Variables : %x% (Rank Math) et alias convertis, inconnues gardées.
	$fluffless_assert( '%%title%% %%sep%% %%sitename%%' === Importer::convert_variables( '%title% %sep% %sitename%' ), 'Variables Rank Math : ' . Importer::convert_variables( '%title% %sep% %sitename%' ) );
	$fluffless_assert( '%%title%% | %%sitename%% %%page%%' === Importer::convert_variables( '%%post_title%% | %%sitetitle%% %%pagenumber%%' ), 'Alias : ' . Importer::convert_variables( '%%post_title%% | %%sitetitle%% %%pagenumber%%' ) );
	$fluffless_assert( '100% bio' === Importer::convert_variables( '100% bio' ), 'Un pourcentage isolé ne doit pas devenir une variable.' );

	$fluffless_yoast = $fluffless_new(
		array(
			'_yoast_wpseo_title'               => '%%title%% %%sep%% %%sitename%%',
			'_yoast_wpseo_metadesc'            => 'Description venue de Yoast.',
			'_yoast_wpseo_focuskw'             => 'jardin',
			'_yoast_wpseo_meta-robots-noindex' => '1',
			'_yoast_wpseo_meta-robots-adv'     => 'noarchive,nosnippet',
			'_yoast_wpseo_opengraph-image-id'  => '0',
		)
	);
	$fluffless_mine  = $fluffless_new(
		array(
			'_yoast_wpseo_title' => 'Titre Yoast',
			Meta::TITLE          => 'Le nôtre',
		)
	);
	$fluffless_rank  = $fluffless_new(
		array(
			'rank_math_title'         => '%title% %sep% %sitename%',
			'rank_math_robots'        => array( 'index', 'nofollow' ),
			'rank_math_focus_keyword' => 'vélo, ville',
		)
	);

	// Simulation : comptes rendus, rien d'écrit.
	$fluffless_dry = Importer::run( 'yoast' );
	$fluffless_assert( $fluffless_dry['posts'] >= 2 && $fluffless_dry['written'] >= 4 && $fluffless_dry['kept'] >= 1, 'Simulation Yoast : ' . wp_json_encode( $fluffless_dry ) );
	$fluffless_assert( '' === Meta::text( $fluffless_yoast, Meta::TITLE ), 'La simulation a écrit un titre.' );

	// Importation réelle.
	Importer::run( 'yoast', true );
	$fluffless_assert( '%%title%% %%sep%% %%sitename%%' === Meta::text( $fluffless_yoast, Meta::TITLE ), 'Titre Yoast non importé : ' . Meta::text( $fluffless_yoast, Meta::TITLE ) );
	$fluffless_assert( 'Description venue de Yoast.' === Meta::text( $fluffless_yoast, Meta::DESCRIPTION ), 'Description Yoast non importée.' );
	$fluffless_assert( 'jardin' === Meta::text( $fluffless_yoast, Meta::KEYWORD ), 'Mot-clé Yoast non importé.' );
	$fluffless_robots = Meta::robots( $fluffless_yoast );
	sort( $fluffless_robots );
	$fluffless_assert( array( 'noarchive', 'noindex', 'nosnippet' ) === $fluffless_robots, 'Robots Yoast : ' . implode( ',', $fluffless_robots ) );
	$fluffless_assert( ! metadata_exists( 'post', $fluffless_yoast, Meta::OG_IMAGE ), 'Une image d\'identifiant 0 ne doit pas être importée.' );
	$fluffless_assert( 'Description venue de Yoast.' === get_post_meta( $fluffless_yoast, '_yoast_wpseo_metadesc', true ), 'Les données d\'origine doivent rester.' );
	$fluffless_assert( 'Le nôtre' === Meta::text( $fluffless_mine, Meta::TITLE ), 'Un champ déjà rempli a été remplacé.' );

	// Relancer ne réécrit rien : tout est déjà rempli.
	$fluffless_second = Importer::run( 'yoast', true );
	$fluffless_assert( 0 === $fluffless_second['written'], 'Deuxième passage : aucune écriture attendue, reçu ' . wp_json_encode( $fluffless_second ) );

	// Rank Math : variables à un seul %, robots en tableau, premier mot-clé seulement.
	Importer::run( 'rankmath', true );
	$fluffless_assert( '%%title%% %%sep%% %%sitename%%' === Meta::text( $fluffless_rank, Meta::TITLE ), 'Titre Rank Math : ' . Meta::text( $fluffless_rank, Meta::TITLE ) );
	$fluffless_assert( array( 'nofollow' ) === Meta::robots( $fluffless_rank ), 'Robots Rank Math : ' . implode( ',', Meta::robots( $fluffless_rank ) ) );
	$fluffless_assert( 'vélo' === Meta::text( $fluffless_rank, Meta::KEYWORD ), 'Mot-clé Rank Math : ' . Meta::text( $fluffless_rank, Meta::KEYWORD ) );

	// Source inconnue : erreur qui nomme la valeur reçue.
	try {
		Importer::run( 'inconnue' );
		$fluffless_assert( false, 'Source inconnue acceptée.' );
	} catch ( InvalidArgumentException $error ) {
		$fluffless_assert( str_contains( $error->getMessage(), 'inconnue' ), 'Le message doit nommer la source : ' . $error->getMessage() );
	}
} finally {
	foreach ( $fluffless_posts as $fluffless_id ) {
		wp_delete_post( $fluffless_id, true );
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles (importation) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}

echo esc_html( 'OK : ' . $fluffless_checks . " contrôles réussis (importation : simulation, Yoast, Rank Math, champs gardés, variables).\n" );
