<?php
/**
 * Tests du module Liens de campagne (UTM).
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/liens-campagne-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Utm;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Utm::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;

$fluffless_assert = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

/*
 * Contrat : dans la liste, éteint d'office, sans réglage, option effacée à la désinstallation.
 */
$fluffless_assert( isset( Loader::modules()[ Utm::id() ] ), 'module absent du Loader' );
$fluffless_assert( ! Utm::enabled_by_default(), 'module allumé par défaut' );
$fluffless_assert( array() === Utm::fields() && array() === Utm::sanitize( array( 'x' => 1 ) ), 'réglage inattendu' );

/*
 * Normalisation : minuscules, espaces et soulignés en traits d'union, rien aux extrémités.
 */
foreach (
	array(
		'Infolettre'          => 'infolettre',
		'  Soldes  Été 2026 ' => 'soldes-été-2026',
		'Mon_Courriel'        => 'mon-courriel',
		'a -- b'              => 'a-b',
		"<b>gras</b>\tonglet" => 'gras-onglet',
		''                    => '',
	) as $fluffless_in => $fluffless_out
) {
	$fluffless_assert( Utm::normalize( (string) $fluffless_in ) === $fluffless_out, 'normalisation de « ' . $fluffless_in . ' » : ' . Utm::normalize( (string) $fluffless_in ) );
}
$fluffless_assert( Utm::MAX_LENGTH === mb_strlen( Utm::normalize( str_repeat( 'é', 300 ) ) ), 'longueur maximale' );

/*
 * Construction : ordre fixe, encodage, paramètres vides omis, anciens UTM remplacés, ancre gardée.
 */
$fluffless_params = array(
	'utm_source'   => 'Infolettre',
	'utm_medium'   => 'Courriel',
	'utm_campaign' => 'Soldes Été',
	'utm_term'     => '',
	'utm_content'  => 'Bouton & lien',
);
$fluffless_assert( 'https://exemple.test/page/?utm_source=infolettre&utm_medium=courriel&utm_campaign=soldes-%C3%A9t%C3%A9&utm_content=bouton-%26-lien' === Utm::build( 'https://exemple.test/page/', $fluffless_params ), 'adresse construite : ' . Utm::build( 'https://exemple.test/page/', $fluffless_params ) );
$fluffless_assert( 'https://exemple.test/?p=4&utm_source=infolettre#prix' === Utm::build( 'https://exemple.test/?p=4&utm_source=vieux&utm_medium=x#prix', array( 'utm_source' => 'infolettre' ) ), 'requête existante et ancre : ' . Utm::build( 'https://exemple.test/?p=4&utm_source=vieux&utm_medium=x#prix', array( 'utm_source' => 'infolettre' ) ) );
$fluffless_assert( '' === Utm::build( 'javascript:alert(1)', $fluffless_params ), 'adresse non http acceptée' );
$fluffless_assert( '' === Utm::build( 'ftp://exemple.test/', $fluffless_params ), 'adresse ftp acceptée' );

/*
 * Préréglages : nom et source exigés, même nom remplacé, plafond, retrait, option effacée vide.
 */
$fluffless_backup = get_option( Utm::OPTION, null );
$fluffless_admin  = 0;
try {
	delete_option( Utm::OPTION );
	$fluffless_assert( array() === Utm::presets(), 'préréglages : liste vide au départ' );
	$fluffless_assert( ! Utm::save_preset( array( 'name' => 'Sans source' ) ), 'préréglage sans source enregistré' );
	$fluffless_assert( ! Utm::save_preset( array( 'utm_source' => 'x' ) ), 'préréglage sans nom enregistré' );
	$fluffless_assert(
		Utm::save_preset(
			array(
				'name'       => 'Infolettre',
				'utm_source' => 'Infolettre',
				'utm_medium' => 'Courriel',
			)
		),
		'préréglage refusé'
	);
	$fluffless_assert(
		Utm::save_preset(
			array(
				'name'       => 'Infolettre',
				'utm_source' => 'infolettre',
				'utm_medium' => 'email',
			)
		),
		'préréglage remplacé refusé'
	);
	$fluffless_presets = Utm::presets();
	$fluffless_assert( 1 === count( $fluffless_presets ) && 'email' === $fluffless_presets[0]['utm_medium'], 'même nom : préréglage dédoublé ou pas remplacé' );
	$fluffless_assert( 'infolettre' === $fluffless_presets[0]['utm_source'], 'préréglage non normalisé' );
	for ( $fluffless_i = 0; $fluffless_i < Utm::MAX_PRESETS + 5; $fluffless_i++ ) {
		Utm::save_preset(
			array(
				'name'       => 'P' . $fluffless_i,
				'utm_source' => 's',
			)
		);
	}
	$fluffless_presets = Utm::presets();
	$fluffless_assert( Utm::MAX_PRESETS === count( $fluffless_presets ) && 'P' . ( Utm::MAX_PRESETS + 4 ) === $fluffless_presets[0]['name'], 'plafond des préréglages ou ordre (plus récent en tête)' );
	$fluffless_assert( ! array_key_exists( Utm::OPTION, wp_load_alloptions() ), 'option chargée à chaque page (autoload)' );
	update_option(
		Utm::OPTION,
		array(
			array(
				'name'       => 'Ok',
				'utm_source' => 's',
			),
			'texte',
			array( 'name' => '' ),
		)
	);
	$fluffless_assert( 1 === count( Utm::presets() ), 'préréglages invalides gardés à la lecture' );
	Utm::delete_preset( 'Ok' );
	$fluffless_assert( false === get_option( Utm::OPTION ), 'option gardée une fois la liste vide' );

	/*
	 * Écran : formulaire sans script requis, lien construit, bouton Copier, préréglage cliquable.
	 */
	$fluffless_admin = wp_insert_user(
		array(
			'user_login' => 'fl-utm-admin',
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'fl-utm-admin@example.test',
			'role'       => 'administrator',
		)
	);
	if ( ! is_int( $fluffless_admin ) ) {
		throw new RuntimeException( 'Administratrice de test non créée.' );
	}
	// Sous une administratrice : les lectures de styles globaux ne recréent pas de billet.
	wp_set_current_user( $fluffless_admin );
	Utm::save_preset(
		array(
			'name'       => 'Facebook',
			'utm_source' => 'facebook',
			'utm_medium' => 'social',
		)
	);
	$_GET = array(
		'page'         => Utm::PAGE,
		'url'          => 'https://exemple.test/offre/',
		'utm_source'   => 'Infolettre',
		'utm_medium'   => 'email',
		'utm_campaign' => 'Automne 2026',
	);
	ob_start();
	Utm::render();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_html, 'value="https://exemple.test/offre/?utm_source=infolettre&amp;utm_medium=email&amp;utm_campaign=automne-2026"' ), 'lien construit absent de l\'écran' );
	$fluffless_assert( str_contains( $fluffless_html, 'id="fl-utm-copy"' ) && str_contains( $fluffless_html, 'navigator.clipboard' ), 'bouton Copier absent' );
	$fluffless_assert( str_contains( $fluffless_html, 'method="get"' ) && str_contains( $fluffless_html, 'name="_wpnonce"' ), 'formulaire GET ou jeton absent' );
	$fluffless_assert( str_contains( $fluffless_html, '>Facebook</a>' ) && str_contains( $fluffless_html, 'utm_source=facebook' ), 'préréglage absent ou non cliquable' );
	foreach ( Utm::PARAMS as $fluffless_param ) {
		$fluffless_assert( str_contains( $fluffless_html, 'for="fl-' . $fluffless_param . '"' ), 'champ sans étiquette : ' . $fluffless_param );
	}
	$_GET = array( 'page' => Utm::PAGE );
	ob_start();
	Utm::render();
	$fluffless_assert( ! str_contains( (string) ob_get_clean(), 'fl-utm-result' ), 'lien affiché sans source' );
} finally {
	$_GET = array();
	if ( null === $fluffless_backup ) {
		delete_option( Utm::OPTION );
	} else {
		update_option( Utm::OPTION, $fluffless_backup, false );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_int( $fluffless_admin ) && $fluffless_admin > 0 ) {
		wp_delete_user( $fluffless_admin );
	}
	wp_set_current_user( 0 );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Liens de campagne : {$fluffless_checks} vérifications passées." );
