<?php
/**
 * Tests du module Préréglages de style : validation par listes fermées, injections refusées,
 * contraste de 4,5:1, texte jumelé au fond, nom unique et préfixé, plafond, style_data au format
 * theme.json, enregistrement natif, CSS écrit seulement sur les pages qui s'en servent.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/styles-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * L'option et les styles enregistrés sont remis à leur état initial à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Blocks\Flottant;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Palette;
use Fluffless\Suite\Modules\Styles;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Styles::class ) || ! isset( Loader::modules()['styles'] ) ) {
	throw new RuntimeException( 'Module Préréglages de style absent.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_before = get_option( Styles::OPTION, null );
$fluffless_hex    = Flottant::palette_hex();
$fluffless_ch     = Styles::choices();
$fluffless_base   = array(
	'label'  => 'Carte test',
	'blocks' => array( 'core/group' ),
	'radius' => '8px',
);

try {
	delete_option( Styles::OPTION );

	// Listes fermées tirées du thème.
	$fluffless_assert( array() !== $fluffless_ch['background'] && array() !== $fluffless_ch['padding'] && array() !== $fluffless_ch['font_size'], 'choix : palette, espacements et tailles lus dans les styles du thème' );
	foreach ( array( 'background', 'text', 'padding', 'border_color', 'shadow', 'font_size' ) as $fluffless_key ) {
		foreach ( array_keys( $fluffless_ch[ $fluffless_key ] ) as $fluffless_slug ) {
			$fluffless_assert( 1 === preg_match( '/^[a-z0-9-]{1,40}$/', (string) $fluffless_slug ), "choix $fluffless_key : slug sûr ($fluffless_slug)" );
		}
	}
	$fluffless_assert( array_map( 'strval', array_keys( $fluffless_ch['radius'] ) ) === Styles::RADII && array_keys( $fluffless_ch['border_width'] ) === Styles::WIDTHS, 'choix : arrondis et épaisseurs alignés sur les constantes' );
	$fluffless_assert( array_keys( Styles::block_labels() ) === Styles::BLOCKS, 'blocs : un libellé par bloc offert' );

	// Validation.
	$fluffless_ok = Styles::clean( $fluffless_base );
	$fluffless_assert( is_array( $fluffless_ok ) && 'fl-preset-carte-test' === $fluffless_ok['name'] && 'Carte test' === $fluffless_ok['label'], 'propre : nom préfixé tiré du libellé' );
	$fluffless_assert( 'no-name' === Styles::clean( array( 'label' => '  ' ) + $fluffless_base ), 'nom vide refusé' );
	$fluffless_assert( 'no-name' === Styles::clean( array( 'label' => '<b></b>' ) + $fluffless_base ), 'nom fait de balises seulement refusé' );
	$fluffless_long = Styles::clean( array( 'label' => str_repeat( 'é', 90 ) ) + $fluffless_base );
	$fluffless_assert( is_array( $fluffless_long ) && Styles::LABEL_MAX === mb_strlen( $fluffless_long['label'] ) && strlen( $fluffless_long['name'] ) <= strlen( Styles::PREFIX ) + 30, 'nom long tronqué, slug borné' );
	$fluffless_x = Styles::clean( array( 'label' => 'A <script>alert(1)</script>' ) + $fluffless_base );
	$fluffless_assert( is_array( $fluffless_x ) && ! str_contains( $fluffless_x['label'], '<' ), 'balises retirées du libellé' );
	$fluffless_assert( 'no-block' === Styles::clean( array( 'blocks' => array() ) + $fluffless_base ), 'aucun bloc refusé' );
	$fluffless_assert( 'no-block' === Styles::clean( array( 'blocks' => array( 'core/html', 'evil/block' ) ) + $fluffless_base ), 'blocs hors liste refusés' );
	$fluffless_mixed = Styles::clean( array( 'blocks' => array( 'core/html', 'core/button' ) ) + $fluffless_base );
	$fluffless_assert( is_array( $fluffless_mixed ) && array( 'core/button' ) === $fluffless_mixed['blocks'], 'blocs hors liste écartés, les autres gardés' );
	$fluffless_assert( 'no-block' === Styles::clean( array( 'blocks' => 'core/group' ) + $fluffless_base ), 'blocs non tableau refusés' );
	foreach ( array(
		'radius'       => '8px;}body{display:none',
		'border_width' => '10px',
		'padding'      => 'calc(100vw)',
		'background'   => '#ff0000',
		'shadow'       => 'url(x)',
		'font_size'    => '1px',
	) as $fluffless_key => $fluffless_bad ) {
		$fluffless_assert( 'invalid' === Styles::clean( array( $fluffless_key => $fluffless_bad ) + $fluffless_base ), "valeur hors liste refusée : $fluffless_key" );
	}
	$fluffless_assert( 'empty' === Styles::clean( array( 'radius' => array( '8px' ) ) + $fluffless_base ), 'valeur non chaîne ignorée' );
	$fluffless_assert( 'empty' === Styles::clean( array( 'radius' => '' ) + $fluffless_base ), 'aucun réglage refusé' );
	$fluffless_assert(
		'empty' === Styles::clean(
			array(
				'radius'       => '',
				'border_color' => (string) array_key_first( $fluffless_ch['border_color'] ),
			) + $fluffless_base
		),
		'couleur de bordure seule ne compte pas comme réglage'
	);
	$fluffless_bw = Styles::clean( array( 'border_color' => (string) array_key_first( $fluffless_ch['border_color'] ) ) + $fluffless_base );
	$fluffless_assert( is_array( $fluffless_bw ) && '1px' === $fluffless_bw['border_width'], 'couleur de bordure : épaisseur fine posée d\'office' );
	$fluffless_assert( is_array( Styles::clean( array( 'radius' => '0' ) + $fluffless_base ) ), 'arrondi 0 accepté' );

	// Contraste.
	$fluffless_bad_pair = null;
	$fluffless_good     = null;
	foreach ( $fluffless_hex as $fluffless_bg => $fluffless_bgh ) {
		foreach ( $fluffless_hex as $fluffless_tx => $fluffless_txh ) {
			$fluffless_r = Palette::ratio( $fluffless_bgh, $fluffless_txh );
			if ( null === $fluffless_bad_pair && $fluffless_r < Styles::MIN_RATIO && isset( $fluffless_ch['background'][ $fluffless_bg ] ) ) {
				$fluffless_bad_pair = array( $fluffless_bg, $fluffless_tx );
			}
			if ( null === $fluffless_good && $fluffless_r >= Styles::MIN_RATIO && isset( $fluffless_ch['background'][ $fluffless_bg ] ) ) {
				$fluffless_good = array( $fluffless_bg, $fluffless_tx );
			}
		}
	}
	if ( null !== $fluffless_bad_pair ) {
		$fluffless_assert(
			'contrast' === Styles::clean(
				array(
					'background' => $fluffless_bad_pair[0],
					'text'       => $fluffless_bad_pair[1],
				) + $fluffless_base
			),
			'contraste sous 4,5:1 refusé'
		);
	}
	if ( null !== $fluffless_good ) {
		$fluffless_assert(
			is_array(
				Styles::clean(
					array(
						'background' => $fluffless_good[0],
						'text'       => $fluffless_good[1],
					) + $fluffless_base
				)
			),
			'contraste suffisant accepté'
		);
	}
	foreach ( array_keys( $fluffless_ch['background'] ) as $fluffless_bg ) {
		$fluffless_auto = Styles::clean( array( 'background' => (string) $fluffless_bg ) + $fluffless_base );
		if ( is_array( $fluffless_auto ) ) {
			$fluffless_assert( Flottant::text_slug( (string) $fluffless_bg ) === $fluffless_auto['text'], "fond seul : texte jumelé par Flottant::text_slug ($fluffless_bg)" );
		}
	}

	// Stockage.
	$fluffless_assert( 'added' === Styles::add( $fluffless_base ), 'ajout : accepté' );
	$fluffless_assert( 'taken' === Styles::add( $fluffless_base ), 'ajout : nom en double refusé' );
	$fluffless_assert( 'taken' === Styles::add( array( 'label' => 'CARTE   test' ) + $fluffless_base ), 'ajout : nom en double refusé malgré casse et espaces' );
	$fluffless_assert( 1 === count( Styles::all() ), 'ajout : un seul préréglage gardé' );
	for ( $fluffless_i = 2; $fluffless_i <= Styles::MAX; $fluffless_i++ ) {
		Styles::add( array( 'label' => 'Carte ' . $fluffless_i ) + $fluffless_base );
	}
	$fluffless_assert( Styles::MAX === count( Styles::all() ), 'plafond : ' . Styles::MAX . ' préréglages' );
	$fluffless_assert( 'full' === Styles::add( array( 'label' => 'De trop' ) + $fluffless_base ), 'plafond : le suivant est refusé' );
	$fluffless_assert( false === Styles::remove( 'fl-preset-inexistant' ), 'retrait : nom inconnu' );
	$fluffless_assert( true === Styles::remove( 'fl-preset-carte-2' ) && Styles::MAX - 1 === count( Styles::all() ), 'retrait : un de moins' );
	$fluffless_raw   = get_option( Styles::OPTION );
	$fluffless_raw[] = array(
		'name'   => 'fl-preset-pirate',
		'label'  => 'Pirate',
		'blocks' => array( 'core/group' ),
		'radius' => '1px;}*{color:red',
	);
	$fluffless_raw[] = 'pas un tableau';
	update_option( Styles::OPTION, $fluffless_raw, false );
	$fluffless_names = array_column( Styles::all(), 'name' );
	$fluffless_assert( ! in_array( 'fl-preset-pirate', $fluffless_names, true ) && Styles::MAX - 1 === count( $fluffless_names ), 'lecture : préréglage altéré dans la base écarté' );
	foreach ( array_column( Styles::all(), 'name' ) as $fluffless_name ) {
		Styles::remove( (string) $fluffless_name );
	}
	$fluffless_assert( false === get_option( Styles::OPTION ), 'retrait du dernier : option supprimée' );
	$fluffless_assert( 'no-block' === Styles::add( array( 'blocks' => array() ) + $fluffless_base ) && false === get_option( Styles::OPTION ), 'ajout refusé : rien d\'écrit' );

	// style_data.
	$fluffless_full = Styles::clean(
		array(
			'background'   => null !== $fluffless_good ? $fluffless_good[0] : '',
			'text'         => null !== $fluffless_good ? $fluffless_good[1] : '',
			'padding'      => (string) array_key_first( $fluffless_ch['padding'] ),
			'radius'       => '16px',
			'border_width' => '2px',
			'border_color' => (string) array_key_first( $fluffless_ch['border_color'] ),
			'shadow'       => (string) ( array_key_first( $fluffless_ch['shadow'] ) ?? '' ),
			'font_size'    => (string) array_key_first( $fluffless_ch['font_size'] ),
		) + $fluffless_base
	);
	$fluffless_assert( is_array( $fluffless_full ), 'préréglage complet accepté' );
	if ( is_array( $fluffless_full ) ) {
		$fluffless_sd = Styles::style_data( $fluffless_full );
		$fluffless_p  = 'var:preset|spacing|' . $fluffless_full['padding'];
		$fluffless_assert(
			array(
				'top'    => $fluffless_p,
				'right'  => $fluffless_p,
				'bottom' => $fluffless_p,
				'left'   => $fluffless_p,
			) === $fluffless_sd['spacing']['padding'],
			'style_data : espacement sur les quatre côtés'
		);
		$fluffless_assert( '16px' === $fluffless_sd['border']['radius'] && '2px' === $fluffless_sd['border']['width'] && 'solid' === $fluffless_sd['border']['style'] && 'var:preset|color|' . $fluffless_full['border_color'] === $fluffless_sd['border']['color'], 'style_data : bordure et arrondi' );
		$fluffless_assert( 'var:preset|font-size|' . $fluffless_full['font_size'] === $fluffless_sd['typography']['fontSize'], 'style_data : taille par préréglage' );
		if ( '' !== $fluffless_full['shadow'] ) {
			$fluffless_assert( 'var:preset|shadow|' . $fluffless_full['shadow'] === $fluffless_sd['shadow'], 'style_data : ombre par préréglage' );
		}
		if ( '' !== $fluffless_full['background'] ) {
			$fluffless_assert( 'var:preset|color|' . $fluffless_full['background'] === $fluffless_sd['color']['background'] && 'var:preset|color|' . $fluffless_full['text'] === $fluffless_sd['color']['text'], 'style_data : couleurs par préréglage' );
		}
		$fluffless_values = array();
		array_walk_recursive(
			$fluffless_sd,
			static function ( $value ) use ( &$fluffless_values ): void {
				$fluffless_values[] = (string) $value;
			}
		);
		$fluffless_assert( array() === preg_grep( '/[;{}<>()\\\\]/', $fluffless_values ), 'style_data : aucune valeur qui sortirait d\'une déclaration CSS' );
	}
	/**
	 * Préréglage minimal : bordure seule, sans coins.
	 *
	 * @var array<string, mixed> $fluffless_min_preset
	 */
	$fluffless_min_preset = (array) Styles::clean(
		array(
			'border_width' => '3px',
			'radius'       => '',
		) + $fluffless_base
	);
	$fluffless_min        = Styles::style_data( $fluffless_min_preset );
	$fluffless_assert(
		array(
			'border' => array(
				'width' => '3px',
				'style' => 'solid',
				'color' => 'currentColor',
			),
		) === $fluffless_min,
		'style_data : bordure seule en couleur courante, rien d\'autre'
	);

	// Enregistrement et CSS.
	Styles::add(
		array(
			'label'  => 'Carte rendu',
			'blocks' => array( 'core/group', 'core/button' ),
			'radius' => '24px',
		)
	);
	Styles::register_styles();
	$fluffless_reg = WP_Block_Styles_Registry::get_instance();
	$fluffless_assert( $fluffless_reg->is_registered( 'core/group', 'fl-preset-carte-rendu' ) && $fluffless_reg->is_registered( 'core/button', 'fl-preset-carte-rendu' ) && ! $fluffless_reg->is_registered( 'core/image', 'fl-preset-carte-rendu' ), 'enregistrement : sur les blocs choisis seulement' );
	$fluffless_style = $fluffless_reg->get_registered( 'core/group', 'fl-preset-carte-rendu' );
	$fluffless_assert( 'Carte rendu' === ( $fluffless_style['label'] ?? '' ) && array( 'border' => array( 'radius' => '24px' ) ) === ( $fluffless_style['style_data'] ?? null ), 'enregistrement : libellé et style_data' );
	$fluffless_out = do_blocks( '<!-- wp:group {"className":"is-style-fl-preset-carte-rendu"} --><div class="wp-block-group is-style-fl-preset-carte-rendu"><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
	$fluffless_assert( str_contains( $fluffless_out, 'is-style-fl-preset-carte-rendu' ), 'rendu : la classe du style est posée' );
	$fluffless_page = wp_get_global_stylesheet();
	$fluffless_assert( ! str_contains( $fluffless_page, 'fl-preset-carte-rendu' ), 'CSS : rien dans la feuille globale commune à toutes les pages' );
} finally {
	foreach ( array( 'core/group', 'core/button' ) as $fluffless_block ) {
		if ( WP_Block_Styles_Registry::get_instance()->is_registered( $fluffless_block, 'fl-preset-carte-rendu' ) ) {
			unregister_block_style( $fluffless_block, 'fl-preset-carte-rendu' );
		}
	}
	if ( null === $fluffless_before ) {
		delete_option( Styles::OPTION );
	} else {
		update_option( Styles::OPTION, $fluffless_before, false );
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Préréglages de style : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Préréglages de style : {$fluffless_checks} vérifications passées." );
