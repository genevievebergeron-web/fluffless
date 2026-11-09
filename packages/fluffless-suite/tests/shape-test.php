<?php
/**
 * Tests du module Forme et densité : styles globaux, densité, déverrouillage, mode Studio.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/shape-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * Les styles globaux, les options et l'utilisatrice de test sont remis à leur état initial à la fin.
 *
 * @package Fluffless_Suite
 */

// Le test appelle un filtre du cœur : ce nom ne peut pas porter le préfixe.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Shape;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Shape::class ) || ! isset( Loader::modules()['shape'] ) ) {
	throw new RuntimeException( 'Module Forme absent : activer l\'extension et l\'inscrire dans Loader.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_admin = (int) ( get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
)[0] ?? 0 );
wp_set_current_user( $fluffless_admin );

$fluffless_styles         = Shape::user_styles();
$fluffless_backup_content = null === $fluffless_styles ? null : (string) get_post_field( 'post_content', $fluffless_styles['id'], 'raw' );
$fluffless_backup_shape   = get_option( Shape::OPTION, null );
$fluffless_backup_option  = get_option( Options::NAME, null );
$fluffless_editor         = 0;

$fluffless_read = static function (): array {
	$styles = Shape::user_styles();
	return null === $styles ? array() : $styles['data'];
};

try {
	$fluffless_assert( null !== $fluffless_styles, 'styles globaux indisponibles' );
	delete_option( Shape::OPTION );

	// 1. Valeurs bornées.
	$fluffless_assert( 32 === Shape::sanitize_state( array( 'radius' => 99 ) )['radius'], 'arrondi au-delà de 32 accepté' );
	$fluffless_assert( 0 === Shape::sanitize_state( array( 'radius' => -5 ) )['radius'], 'arrondi négatif accepté' );
	$fluffless_assert( 'custom' === Shape::sanitize_state( array( 'preset' => '<script>' ) )['preset'], 'préréglage inconnu accepté' );
	$fluffless_assert( 'normal' === Shape::sanitize_state( array( 'density' => 'huge' ) )['density'], 'densité inconnue acceptée' );
	$fluffless_assert(
		12 === Shape::sanitize_state(
			array(
				'preset' => 'rounded',
				'radius' => 30,
			)
		)['radius'],
		'un préréglage doit imposer son arrondi'
	);

	// 2. Écriture dans les styles globaux, clés étrangères préservées, valeur d'origine gardée.
	$fluffless_seed                                 = $fluffless_read();
	$fluffless_seed['settings']['custom']['cut']    = '2rem';
	$fluffless_seed['settings']['custom']['marker'] = 'garde-moi';
	wp_update_post(
		wp_slash(
			array(
				'ID'           => $fluffless_styles['id'] ?? 0,
				'post_content' => (string) wp_json_encode( $fluffless_seed ),
			)
		)
	);
	WP_Theme_JSON_Resolver::clean_cached_data();

	$fluffless_assert(
		Shape::apply(
			array(
				'preset'  => 'custom',
				'radius'  => 99,
				'density' => 'compact',
			)
		),
		'apply() a échoué'
	);
	$fluffless_data = $fluffless_read();
	$fluffless_assert( '32px' === Shape::get_path( $fluffless_data, 'settings.custom.radius.soft' ), 'arrondi non écrit ou non borné : ' . wp_json_encode( Shape::get_path( $fluffless_data, 'settings.custom.radius' ) ) );
	$fluffless_assert( '2rem' === Shape::get_path( $fluffless_data, 'settings.custom.cut' ), 'Sur mesure a écrasé le coin coupé d\'origine' );
	$fluffless_assert( 'garde-moi' === Shape::get_path( $fluffless_data, 'settings.custom.marker' ), 'clé étrangère perdue' );

	// 3. Densité : chaque cran du thème multiplié, même nombre de crans.
	$fluffless_sizes = Shape::get_path( $fluffless_data, 'settings.spacing.spacingSizes' );
	$fluffless_theme = WP_Theme_JSON_Resolver::get_theme_data( array(), array( 'with_supports' => false ) )->get_settings()['spacing']['spacingSizes']['theme'] ?? array();
	$fluffless_assert( is_array( $fluffless_sizes ) && count( $fluffless_sizes ) === count( $fluffless_theme ) && count( $fluffless_theme ) > 0, 'densité : nombre de crans différent du thème' );
	foreach ( is_array( $fluffless_sizes ) ? $fluffless_sizes : array() as $fluffless_size ) {
		$fluffless_assert( str_starts_with( $fluffless_size['size'], 'calc(clamp(' ) && str_ends_with( $fluffless_size['size'], ' * 0.8)' ), 'densité compacte mal écrite : ' . $fluffless_size['size'] );
	}
	$fluffless_css = wp_get_global_stylesheet( array( 'variables' ) );
	$fluffless_assert( str_contains( $fluffless_css, '--wp--custom--radius--soft: 32px' ), 'la feuille globale ne reprend pas l\'arrondi' );
	$fluffless_assert( 1 === preg_match( '/--wp--preset--spacing--50: calc\(clamp\([^;]+\) \* 0\.8\);(?![\s\S]*--wp--preset--spacing--50:)/', $fluffless_css ), 'la dernière déclaration de --wp--preset--spacing--50 n\'est pas la densité' );

	Shape::apply(
		array(
			'preset'  => 'custom',
			'radius'  => 10,
			'density' => 'airy',
		)
	);
	$fluffless_first = Shape::get_path( $fluffless_read(), 'settings.spacing.spacingSizes' )[0]['size'] ?? '';
	$fluffless_assert( str_ends_with( $fluffless_first, ' * 1.25)' ) && ! str_contains( $fluffless_first, '0.8' ), 'densité aérée cumulée à la compacte : ' . $fluffless_first );

	// 4. Préréglages Oblique et Pilule, puis retour à Normal.
	Shape::apply( array( 'preset' => 'oblique' ) );
	$fluffless_data = $fluffless_read();
	$fluffless_assert( '0px' === Shape::get_path( $fluffless_data, 'settings.custom.radius.soft' ) && '1.25rem' === Shape::get_path( $fluffless_data, 'settings.custom.cut' ) && '6vw' === Shape::get_path( $fluffless_data, 'settings.custom.slant' ), 'Oblique mal écrit' );
	$fluffless_assert( null === Shape::get_path( $fluffless_data, 'settings.spacing.spacingSizes' ), 'densité Normal : les crans du thème doivent revenir' );
	Shape::apply( array( 'preset' => 'pill' ) );
	$fluffless_data = $fluffless_read();
	$fluffless_assert( 'var:custom|radius|round' === Shape::get_path( $fluffless_data, 'styles.elements.button.border.radius' ), 'Pilule : boutons non arrondis' );
	$fluffless_assert( '2rem' === Shape::get_path( $fluffless_data, 'settings.custom.cut' ) && null === Shape::get_path( $fluffless_data, 'settings.custom.slant' ), 'Pilule : coin coupé ou bord oblique d\'origine non remis' );

	// 4 bis. Diagonale : deux jetons (grand et petit coin), boutons en diagonale; un autre préréglage les retire.
	Shape::apply( array( 'preset' => 'diagonal' ) );
	$fluffless_data = $fluffless_read();
	$fluffless_assert( '1.5rem' === Shape::get_path( $fluffless_data, 'settings.custom.radius.large' ) && '0.25rem' === Shape::get_path( $fluffless_data, 'settings.custom.radius.small' ) && '8px' === Shape::get_path( $fluffless_data, 'settings.custom.radius.soft' ), 'Diagonale : jetons mal écrits' );
	$fluffless_assert( Shape::DIAGONAL === Shape::get_path( $fluffless_data, 'styles.elements.button.border.radius' ), 'Diagonale : boutons non posés en diagonale' );
	$fluffless_assert( '2rem' === Shape::get_path( $fluffless_data, 'settings.custom.cut' ) && null === Shape::get_path( $fluffless_data, 'settings.custom.slant' ), 'Diagonale : coin coupé ou bord oblique touchés' );
	$fluffless_css = wp_get_global_stylesheet();
	$fluffless_assert( str_contains( $fluffless_css, '--wp--custom--radius--large: 1.5rem' ) && str_contains( $fluffless_css, 'border-top-left-radius: var(--wp--custom--radius--large)' ) && str_contains( $fluffless_css, 'border-top-right-radius: var(--wp--custom--radius--small)' ), 'Diagonale : la feuille globale ne reprend pas les coins' );
	$fluffless_block_styles = array_column( WP_Theme_JSON_Resolver::get_style_variations( 'block' ), 'slug' );
	$fluffless_assert( in_array( 'diagonale', $fluffless_block_styles, true ), 'style de bloc Diagonale absent : ' . implode( ', ', $fluffless_block_styles ) );
	Shape::apply( array( 'preset' => 'oblique' ) );
	$fluffless_data = $fluffless_read();
	$fluffless_assert( null === Shape::get_path( $fluffless_data, 'settings.custom.radius.large' ) && null === Shape::get_path( $fluffless_data, 'settings.custom.radius.small' ) && null === Shape::get_path( $fluffless_data, 'styles.elements.button.border.radius' ), 'Oblique après Diagonale : jetons ou boutons restés' );
	$fluffless_assert( '1.25rem' === Shape::get_path( $fluffless_data, 'settings.custom.cut' ) && '6vw' === Shape::get_path( $fluffless_data, 'settings.custom.slant' ), 'Oblique après Diagonale : mal écrit' );

	// 5. Revenir aux valeurs du thème : tout reprend sa valeur d'avant.
	$fluffless_assert( Shape::is_custom(), 'état personnalisé non mémorisé' );
	$fluffless_assert( Shape::reset(), 'reset() a échoué' );
	$fluffless_data = $fluffless_read();
	$fluffless_assert( null === Shape::get_path( $fluffless_data, 'settings.custom.radius.soft' ) && null === Shape::get_path( $fluffless_data, 'settings.custom.radius.large' ), 'reset : arrondi resté' );
	$fluffless_assert( '2rem' === Shape::get_path( $fluffless_data, 'settings.custom.cut' ), 'reset : coin coupé d\'origine perdu' );
	$fluffless_assert( null === Shape::get_path( $fluffless_data, 'styles.elements.button' ), 'reset : rayon des boutons resté' );
	$fluffless_assert( 'garde-moi' === Shape::get_path( $fluffless_data, 'settings.custom.marker' ), 'reset : clé étrangère perdue' );
	$fluffless_assert( ! Shape::is_custom() && false === get_option( Shape::OPTION ), 'reset : état non effacé' );

	// 6. Déverrouillage : fonction pure.
	$fluffless_locked = '<!-- wp:group {"templateLock":"contentOnly","layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:group {"templateLock":"contentOnly"} --><div class="wp-block-group"><!-- wp:paragraph --><p>Texte</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:group -->';
	$fluffless_open   = Shape::strip_content_lock( $fluffless_locked );
	$fluffless_assert( ! str_contains( $fluffless_open, 'contentOnly' ) && str_contains( $fluffless_open, '"layout":{"type":"constrained"}' ) && str_contains( $fluffless_open, '<p>Texte</p>' ), 'déverrouillage : ' . $fluffless_open );
	$fluffless_all = '<!-- wp:group {"templateLock":"all"} --><div class="wp-block-group"></div><!-- /wp:group -->';
	$fluffless_assert( Shape::strip_content_lock( $fluffless_all ) === $fluffless_all, 'déverrouillage : verrou « all » retiré à tort' );

	// 7. Déverrouillage par rôle, via la route REST des patterns.
	$fluffless_new = wp_insert_user(
		array(
			'user_login' => 'fluffless-shape-test-' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => 'editor',
		)
	);
	$fluffless_assert( is_int( $fluffless_new ), 'utilisatrice de test non créée' );
	$fluffless_editor       = is_int( $fluffless_new ) ? $fluffless_new : 0;
	$fluffless_set          = static function ( bool $on, array $roles ): void {
		$option          = get_option( Options::NAME, array() );
		$option          = is_array( $option ) ? $option : array();
		$option['shape'] = array(
			'unlock_sections' => $on,
			'unlock_roles'    => $roles,
		);
		update_option( Options::NAME, $option );
		Options::flush();
	};
	$fluffless_locked_count = static function (): int {
		$data  = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/block-patterns/patterns' ) )->get_data();
		$count = 0;
		foreach ( is_array( $data ) ? $data : array() as $pattern ) {
			$count += str_contains( (string) ( $pattern['content'] ?? '' ), '"templateLock":"contentOnly"' ) ? 1 : 0;
		}
		return $count;
	};

	$fluffless_set( false, array( 'editor' ) );
	wp_set_current_user( $fluffless_editor );
	$fluffless_before = $fluffless_locked_count();
	$fluffless_assert( $fluffless_before > 0, 'aucun pattern verrouillé servi : le test ne prouve rien' );
	$fluffless_assert( ! Shape::can_unlock(), 'réglage éteint : déverrouillé quand même' );

	$fluffless_set( true, array( 'editor' ) );
	$fluffless_assert( Shape::can_unlock() && 0 === $fluffless_locked_count(), 'éditrice choisie : patterns encore verrouillés' );
	wp_set_current_user( $fluffless_admin );
	$fluffless_assert( ! Shape::can_unlock() && $fluffless_locked_count() > 0, 'administratrice non choisie : patterns déverrouillés' );
	$fluffless_assert(
		array( 'editor' ) === Shape::sanitize(
			array(
				'unlock_sections' => '1',
				'unlock_roles'    => array( 'editor', 'pirate' ),
			)
		)['unlock_roles'],
		'rôle inconnu accepté'
	);

	// 8. Mode Studio par personne.
	$fluffless_base = array(
		'__experimentalFeatures' => array(
			'color'   => array( 'custom' => false ),
			'spacing' => array( 'units' => array( 'rem' ) ),
		),
	);
	$fluffless_ctx  = new WP_Block_Editor_Context();
	update_user_meta( $fluffless_editor, Shape::STUDIO_META, '1' );
	wp_set_current_user( $fluffless_editor );
	$fluffless_studio = apply_filters( 'block_editor_settings_all', $fluffless_base, $fluffless_ctx );
	$fluffless_assert( true === ( $fluffless_studio['__experimentalFeatures']['color']['custom'] ?? null ) && in_array( 'px', $fluffless_studio['__experimentalFeatures']['spacing']['units'] ?? array(), true ), 'Studio : couleurs ou unités libres absentes' );
	$fluffless_set( false, array() );
	$fluffless_assert( Shape::can_unlock(), 'Studio : sections encore verrouillées' );
	wp_set_current_user( $fluffless_admin );
	$fluffless_simple = apply_filters( 'block_editor_settings_all', $fluffless_base, $fluffless_ctx );
	$fluffless_assert( false === ( $fluffless_simple['__experimentalFeatures']['color']['custom'] ?? null ), 'Studio appliqué à une personne qui ne l\'a pas' );

	// 9. Case du profil : réservée à qui peut edit_theme_options.
	$_POST = array();
	Shape::save_studio_field( $fluffless_editor );
	$fluffless_assert( ! Shape::is_studio( $fluffless_editor ), 'profil : case décochée sans effet' );
	wp_set_current_user( $fluffless_editor );
	$_POST = array( 'fluffless_studio' => '1' );
	Shape::save_studio_field( $fluffless_editor );
	$fluffless_assert( ! Shape::is_studio( $fluffless_editor ), 'profil : une éditrice s\'est donné le mode Studio' );
	$_POST = array();

	// 10. Écran : groupé, interrupteurs honnêtes, carte Forme présente.
	wp_set_current_user( $fluffless_admin );
	ob_start();
	SettingsPage::render();
	$fluffless_page = (string) ob_get_clean();
	foreach ( array( 'id="fl-group-lighten"', 'id="fl-group-present"', 'role="switch"', 'name="fluffless_settings[modules][shape]"', 'name="fluffless_settings[shape][unlock_sections]"', 'class="fl-modules fl-hue-' ) as $fluffless_needle ) {
		$fluffless_assert( str_contains( $fluffless_page, $fluffless_needle ), 'réglages : « ' . $fluffless_needle . ' » absent' );
	}
	$fluffless_assert( 0 === preg_match( '/role="switch"(?![^>]*type="checkbox")[^>]*>/', str_replace( 'type="checkbox" role="switch"', '', $fluffless_page ) ), 'réglages : role="switch" posé sur autre chose qu\'une case à cocher' );
	ob_start();
	Shape::render();
	$fluffless_screen = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_screen, 'type="range"' ) && str_contains( $fluffless_screen, 'max="32"' ) && str_contains( $fluffless_screen, 'id="fl-preview"' ), 'écran Forme incomplet' );
} finally {
	wp_set_current_user( $fluffless_admin );
	if ( null !== $fluffless_styles && null !== $fluffless_backup_content ) {
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $fluffless_styles['id'],
					'post_content' => $fluffless_backup_content,
				)
			)
		);
		WP_Theme_JSON_Resolver::clean_cached_data();
	}
	null === $fluffless_backup_shape ? delete_option( Shape::OPTION ) : update_option( Shape::OPTION, $fluffless_backup_shape, false );
	null === $fluffless_backup_option ? delete_option( Options::NAME ) : update_option( Options::NAME, $fluffless_backup_option );
	if ( $fluffless_editor > 0 ) {
		wp_delete_user( $fluffless_editor );
	}
	$_POST = array();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles (forme et densité) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}

echo esc_html( 'OK : ' . $fluffless_checks . " contrôles réussis (forme et densité : styles globaux, densité, retour au thème, déverrouillage par rôle, mode Studio, écrans).\n" );
