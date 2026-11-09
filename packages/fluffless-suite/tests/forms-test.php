<?php
/**
 * Tests du module Formulaires sobres.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/forms-test.php
 * Les vérifications qui touchent une extension ne tournent que si elle est active.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Audit;
use Fluffless\Suite\Modules\Forms;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Forms::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};
$fluffless_all      = array_keys( Forms::plugins() );

try {
	// Contrat du module.
	$fluffless_assert( 'forms' === Forms::id(), 'id' );
	$fluffless_assert( Loader::is_active( 'forms' ), 'module actif par défaut' );
	$fluffless_assert( array_keys( Forms::fields() ) === array_keys( Forms::defaults() ), 'un champ par réglage' );
	$fluffless_assert( false === Forms::sanitize( array( 'assets' => '0' ) )['assets'], 'bool assaini' );
	$fluffless_assert( true === Forms::sanitize( array() )['assets'], 'clé absente au défaut' );
	$fluffless_assert( 5 === count( $fluffless_all ), 'cinq extensions reconnues' );
	foreach ( Forms::plugins() as $fluffless_key => $fluffless_plugin ) {
		$fluffless_assert( '' !== $fluffless_plugin['label'] && array() !== $fluffless_plugin['shortcodes'] && array() !== $fluffless_plugin['blocks'], "table : {$fluffless_key} incomplète" );
	}

	// Détection dans le contenu, extension par extension, sans dépendre de ce qui est installé.
	$fluffless_content = array(
		'cf7'        => array( '<!-- wp:shortcode -->[contact-form-7 id="3295" title="Contact"]<!-- /wp:shortcode -->', '<!-- wp:contact-form-7/contact-form-selector {"id":3295} /-->' ),
		'wpforms'    => array( '[wpforms id="3298"]', '<!-- wp:wpforms/form-selector {"formId":"3298"} /-->' ),
		'fluent'     => array( '[fluentform id="1"]', '<!-- wp:fluentfom/guten-block {"formId":"1"} /-->' ),
		'formidable' => array( '[formidable id=1]', '<!-- wp:formidable/simple-form {"formId":"1"} /-->' ),
		'gravity'    => array( '[gravityform id="1" title="false"]', '<!-- wp:gravityforms/form {"formId":"1"} /-->' ),
	);
	foreach ( $fluffless_content as $fluffless_key => $fluffless_samples ) {
		foreach ( $fluffless_samples as $fluffless_sample ) {
			$fluffless_assert( Forms::has_form_content( $fluffless_sample, array( $fluffless_key ) ), "contenu : {$fluffless_key} non détecté dans {$fluffless_sample}" );
			$fluffless_others = array_values( array_diff( $fluffless_all, array( $fluffless_key ) ) );
			$fluffless_assert( ! Forms::has_form_content( $fluffless_sample, $fluffless_others ), "contenu : {$fluffless_sample} attribué à une autre extension" );
		}
	}
	$fluffless_assert( ! Forms::has_form_content( '<!-- wp:paragraph --><p>[gallery ids="1"] et [contact] sans formulaire</p><!-- /wp:paragraph -->', $fluffless_all ), 'contenu : faux positif sur un code court voisin' );
	$fluffless_assert( ! Forms::has_form_content( '<!-- wp:fluffless/formulaire --><!-- wp:fluffless/champ {"type":"email"} /--><!-- /wp:fluffless/formulaire -->', $fluffless_all ), 'contenu : le bloc Formulaire de la suite n\'est pas une extension tierce' );
	$fluffless_assert( ! Forms::has_form_content( '[wpforms id="1"]', array() ), 'contenu : rien sans extension active' );
	$fluffless_assert( Forms::is_form_shortcode( 'contact-form', array( 'cf7' ) ) && ! Forms::is_form_shortcode( 'contact', array( 'cf7' ) ), 'code court : nom exact' );
	$fluffless_assert( Forms::is_form_block( 'formidable/calculator', array( 'formidable' ) ) && ! Forms::is_form_block( 'core/paragraph', $fluffless_all ), 'bloc : préfixe de l\'extension' );

	// Formulaire noté au rendu d'un bloc ou d'un code court.
	Forms::reset();
	Forms::note_block( '', array( 'blockName' => 'core/paragraph' ) );
	$fluffless_block = static fn(): bool => false;
	add_filter( 'fluffless_forms_page', $fluffless_block );
	$fluffless_assert( ! Forms::is_form_page(), 'filtre empêche la page de formulaire' );
	remove_filter( 'fluffless_forms_page', $fluffless_block );
	$fluffless_force = static fn(): bool => true;
	add_filter( 'fluffless_forms_page', $fluffless_force );
	$fluffless_assert( Forms::is_form_page( false ), 'filtre force la page de formulaire, valeur tierce ignorée' );
	remove_filter( 'fluffless_forms_page', $fluffless_force );

	// Captcha : lecture du HTML, pure.
	$fluffless_assert( array() === Forms::captcha_hosts_in_html( '<html><body><form></form></body></html>' ), 'captcha : rien dans un HTML sans captcha' );
	// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	$fluffless_html = '<script src="https://www.google.com/recaptcha/api.js?render=abc"></script><script src="https://www.gstatic.com/recaptcha/releases/x/recaptcha__fr.js"></script><script src="https://js.hcaptcha.com/1/api.js"></script>';
	// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	$fluffless_assert( array( 'reCAPTCHA', 'hCaptcha' ) === Forms::captcha_hosts_in_html( $fluffless_html ), 'captcha : services lus dans le HTML, sans doublon : ' . wp_json_encode( Forms::captcha_hosts_in_html( $fluffless_html ) ) );
	$fluffless_assert( array( 'Turnstile' ) === Forms::captcha_hosts_in_html( '<iframe src="https://challenges.cloudflare.com/turnstile/v0/x"></iframe>' ), 'captcha : Turnstile reconnu' );
	$fluffless_assert( 'bad' === Audit::check_captcha( $fluffless_html )['status'], 'bilan : captcha chargé sur l\'accueil signalé' );
	$fluffless_assert( str_contains( Audit::check_captcha( $fluffless_html )['fix'], 'ALTCHA' ), 'bilan : ALTCHA proposé' );

	// Captcha : réglages lus seulement dans les extensions actives ; un réglage orphelin ne compte pas.
	$fluffless_saved_gf = get_option( 'rg_gforms_captcha_public_key', null );
	update_option( 'rg_gforms_captcha_public_key', 'clef-publique' );
	$fluffless_assert( 'reCAPTCHA' === Forms::captcha_service( 'gravity' ), 'captcha : clé Gravity lue' );
	$fluffless_assert( in_array( 'gravity', Forms::active_plugins(), true ) || ! isset( Forms::captcha_services()['Gravity Forms'] ), 'captcha : extension inactive ignorée' );
	if ( null === $fluffless_saved_gf ) {
		delete_option( 'rg_gforms_captcha_public_key' );
	} else {
		update_option( 'rg_gforms_captcha_public_key', $fluffless_saved_gf );
	}
	$fluffless_assert( '' === Forms::captcha_service( 'gravity' ), 'captcha : sans clé, rien' );
	$fluffless_saved_wpf = get_option( 'wpforms_settings', null );
	update_option(
		'wpforms_settings',
		array(
			'captcha-provider'   => 'turnstile',
			'turnstile-site-key' => 'x',
		)
	);
	$fluffless_assert( 'Turnstile' === Forms::captcha_service( 'wpforms' ), 'captcha : fournisseur WPForms lu' );
	update_option(
		'wpforms_settings',
		array(
			'captcha-provider'   => 'hcaptcha',
			'turnstile-site-key' => 'x',
		)
	);
	$fluffless_assert( '' === Forms::captcha_service( 'wpforms' ), 'captcha : fournisseur WPForms sans clé' );
	if ( null === $fluffless_saved_wpf ) {
		delete_option( 'wpforms_settings' );
	} else {
		update_option( 'wpforms_settings', $fluffless_saved_wpf );
	}
	$fluffless_assert( '' === Forms::captcha_service( 'inconnue' ), 'captcha : extension inconnue' );

	// Retrait des feuilles et scripts : un faux identifiant enregistré dans le dossier de chaque extension active.
	$fluffless_active = Forms::active_plugins();
	if ( array() === $fluffless_active ) {
		$fluffless_assert( '' !== Forms::status(), 'avertissement sans extension' );
		$fluffless_assert( array() === Forms::plugin_urls(), 'aucune adresse sans extension' );
	} else {
		$fluffless_assert( '' === Forms::status(), 'aucun avertissement avec une extension' );
		Forms::reset();
		$fluffless_block = static fn(): bool => false;
		add_filter( 'fluffless_forms_page', $fluffless_block );
		foreach ( $fluffless_active as $fluffless_key ) {
			$fluffless_base = Forms::plugin_url( $fluffless_key );
			$fluffless_assert( str_starts_with( $fluffless_base, content_url( '/plugins/' ) ) && str_ends_with( $fluffless_base, '/' ), "adresse de {$fluffless_key} : {$fluffless_base}" );
			wp_register_style( 'fluffless-test-' . $fluffless_key, $fluffless_base . 'assets/x.css', array(), '1' );
			wp_enqueue_style( 'fluffless-test-' . $fluffless_key );
			wp_register_script( 'fluffless-test-' . $fluffless_key, $fluffless_base . 'assets/x.js', array(), '1', true );
			wp_enqueue_script( 'fluffless-test-' . $fluffless_key );
		}
		foreach ( Forms::extra_handles() as $fluffless_handle ) {
			wp_register_style( $fluffless_handle, admin_url( 'admin-ajax.php?action=frmpro_css' ), array(), '1' );
			wp_enqueue_style( $fluffless_handle );
		}
		wp_register_style( 'fluffless-test-theme', get_template_directory_uri() . '/assets/css/forms.css', array(), '1' );
		wp_enqueue_style( 'fluffless-test-theme' );
		Forms::dequeue_off_forms();
		foreach ( $fluffless_active as $fluffless_key ) {
			$fluffless_assert( ! wp_style_is( 'fluffless-test-' . $fluffless_key ) && ! wp_script_is( 'fluffless-test-' . $fluffless_key ), "feuille et script de {$fluffless_key} retirés hors formulaire" );
		}
		foreach ( Forms::extra_handles() as $fluffless_handle ) {
			$fluffless_assert( ! wp_style_is( $fluffless_handle ), "identifiant {$fluffless_handle} retiré par son nom" );
		}
		$fluffless_assert( wp_style_is( 'fluffless-test-theme' ), 'feuille du thème gardée' );
		remove_filter( 'fluffless_forms_page', $fluffless_block );
		wp_dequeue_style( 'fluffless-test-theme' );

		// Sur une page de formulaire, rien ne bouge.
		Forms::note_shortcode( '', Forms::plugins()[ $fluffless_active[0] ]['shortcodes'][0] );
		$fluffless_assert( Forms::is_form_page(), 'code court rendu : page de formulaire' );
		wp_enqueue_style( 'fluffless-test-' . $fluffless_active[0] );
		Forms::dequeue_off_forms();
		$fluffless_assert( wp_style_is( 'fluffless-test-' . $fluffless_active[0] ), 'feuille gardée sur la page de formulaire' );
		wp_dequeue_style( 'fluffless-test-' . $fluffless_active[0] );
		Forms::reset();
		if ( in_array( 'cf7', $fluffless_active, true ) ) {
			$fluffless_assert( false === apply_filters( 'wpcf7_load_css', true ) && false === apply_filters( 'wpcf7_load_js', true ), 'Contact Form 7 : feuille et scripts refusés hors formulaire' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}
	}
} finally {
	Forms::reset();
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		echo 'ÉCHEC : ', esc_html( $fluffless_failure ), "\n";
	}
	exit( 1 );
}
echo 'OK : Formulaires sobres, ', esc_html( (string) $fluffless_checks ), " vérifications réussies.\n";
