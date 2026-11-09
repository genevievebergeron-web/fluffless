<?php
/**
 * Tests du bloc Vague (module Expressivité) : contrat du module, attributs bornés, couleur
 * limitée à la palette du thème, rendu SVG décoratif, poids, CSS chargé seulement si utilisé.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/vague-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * L'enregistrement du bloc est remis à son état initial à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Expression;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Expression::class ) || ! isset( Loader::modules()['expression'] ) ) {
	throw new RuntimeException( 'Module Expressivité absent : l\'inscrire dans Loader.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_registry   = WP_Block_Type_Registry::get_instance();
$fluffless_registered = $fluffless_registry->is_registered( Expression::VAGUE );

try {
	// 1. Contrat du module.
	$fluffless_assert( false === Expression::enabled_by_default(), 'Expressivité doit être inactif par défaut (il change le site public)' );
	$fluffless_assert( array() === Loader::requires( 'expression' ), 'Expressivité ne requiert aucun autre module' );

	// 2. Attributs : défauts, bornes, listes fermées.
	$fluffless_a = Expression::vague_attributes( array() );
	$fluffless_assert( 'wave' === $fluffless_a['shape'], 'forme par défaut : wave' );
	$fluffless_assert( 'bottom' === $fluffless_a['position'], 'position par défaut : bottom' );
	$fluffless_assert( 64 === $fluffless_a['height'], 'hauteur par défaut : 64' );
	$fluffless_assert( false === $fluffless_a['flip'], 'pas de miroir par défaut' );
	$fluffless_assert( 'surface' === $fluffless_a['colour'], 'couleur par défaut : surface' );

	$fluffless_a = Expression::vague_attributes(
		array(
			'shape'    => 'inconnue',
			'position' => 'milieu',
			'height'   => 9999,
			'flip'     => 'oui',
			'colour'   => 'red;background:url(x)',
		)
	);
	$fluffless_assert( 'wave' === $fluffless_a['shape'], 'forme hors liste : retour au défaut' );
	$fluffless_assert( 'bottom' === $fluffless_a['position'], 'position hors liste : retour au défaut' );
	$fluffless_assert( Expression::VAGUE_HEIGHT[1] === $fluffless_a['height'], 'hauteur au-delà du maximum : bornée' );
	$fluffless_assert( false === $fluffless_a['flip'], 'miroir : seul true l\'active' );
	$fluffless_assert( 'surface' === $fluffless_a['colour'], 'couleur hors palette : retour au défaut (aucune injection CSS)' );
	$fluffless_low = Expression::vague_attributes( array( 'height' => -5 ) )['height'];
	$fluffless_assert( Expression::VAGUE_HEIGHT[0] === $fluffless_low, 'hauteur sous le minimum : bornée' );
	$fluffless_assert( 'accent' === Expression::vague_attributes( array( 'colour' => 'accent' ) )['colour'], 'couleur de la palette acceptée' );
	foreach ( array_keys( Expression::VAGUE_SHAPES ) as $fluffless_shape ) {
		$fluffless_kept = Expression::vague_attributes( array( 'shape' => $fluffless_shape ) )['shape'];
		$fluffless_assert( $fluffless_kept === $fluffless_shape, "forme {$fluffless_shape} refusée" );
	}

	// 3. Rendu : SVG décoratif, étiré, couleur de la palette par variable, jamais de valeur brute.
	$fluffless_html = Expression::render_vague(
		array(
			'shape'    => 'peaks',
			'position' => 'top',
			'height'   => 80,
			'flip'     => true,
			'colour'   => 'accent',
		)
	);
	$fluffless_assert( str_contains( $fluffless_html, '<svg' ), 'rendu sans SVG' );
	$fluffless_assert( str_contains( $fluffless_html, 'aria-hidden="true"' ), 'la vague doit être cachée aux lecteurs d\'écran' );
	$fluffless_assert( str_contains( $fluffless_html, 'focusable="false"' ), 'SVG focalisable' );
	$fluffless_assert( str_contains( $fluffless_html, 'preserveAspectRatio="none"' ), 'la vague doit s\'étirer sur toute la largeur' );
	$fluffless_assert( str_contains( $fluffless_html, 'fill="currentColor"' ), 'le tracé doit suivre la couleur du conteneur' );
	$fluffless_assert( str_contains( $fluffless_html, 'var(--wp--preset--color--accent)' ), 'couleur de la palette absente' );
	$fluffless_assert( str_contains( $fluffless_html, '--fluffless-vague-h:80px' ), 'hauteur absente' );
	$fluffless_assert( str_contains( $fluffless_html, 'is-top' ) && str_contains( $fluffless_html, 'is-flip' ), 'classes de position ou de miroir absentes' );
	$fluffless_assert( str_contains( $fluffless_html, 'is-shape-peaks' ), 'classe de forme absente' );
	$fluffless_assert( strlen( $fluffless_html ) < 1024, 'rendu d\'une vague au-delà de 1 ko (' . strlen( $fluffless_html ) . ' o)' );

	$fluffless_html = Expression::render_vague( array( 'colour' => 'red;background:url(x)' ) );
	$fluffless_assert( ! str_contains( $fluffless_html, 'url(' ) && ! str_contains( $fluffless_html, 'red' ), 'valeur de couleur brute recopiée dans le rendu' );
	$fluffless_assert( '' !== Expression::render_vague( 'x' ), 'attributs non tableau : rendu par défaut attendu' );

	// 3 bis. Séparateurs en ligne : acceptés, sans tracé étiré, motif centré seulement quand prévu.
	foreach ( Expression::VAGUE_LINES as $fluffless_shape ) {
		$fluffless_assert( Expression::vague_attributes( array( 'shape' => $fluffless_shape ) )['shape'] === $fluffless_shape, "forme {$fluffless_shape} refusée" );
		$fluffless_html = Expression::render_vague(
			array(
				'shape'  => $fluffless_shape,
				'colour' => 'divider',
				'height' => 32,
			)
		);
		$fluffless_assert( str_contains( $fluffless_html, 'is-line' ) && str_contains( $fluffless_html, 'is-shape-' . $fluffless_shape ), "{$fluffless_shape} : classes absentes" );
		$fluffless_assert( str_contains( $fluffless_html, 'aria-hidden="true"' ), "{$fluffless_shape} : doit rester décoratif" );
		$fluffless_assert( ! str_contains( $fluffless_html, 'preserveAspectRatio' ), "{$fluffless_shape} : le motif ne doit pas s'étirer" );
		$fluffless_assert( isset( Expression::VAGUE_MARKS[ $fluffless_shape ] ) === str_contains( $fluffless_html, '<svg' ), "{$fluffless_shape} : motif centré inattendu ou absent" );
		$fluffless_assert( str_contains( $fluffless_html, '--fluffless-vague-h:32px' ) && str_contains( $fluffless_html, '--divider)' ), "{$fluffless_shape} : hauteur ou couleur absente" );
	}
	$fluffless_assert( ! str_contains( Expression::render_vague( array( 'shape' => 'wave' ) ), 'is-line' ), 'une vague ne doit pas porter is-line' );

	// 3 ter. Formes d'accent : motif centré seul (is-mark), transparence en style seulement sous 100.
	foreach ( Expression::VAGUE_ACCENTS as $fluffless_shape ) {
		$fluffless_html = Expression::render_vague(
			array(
				'shape'   => $fluffless_shape,
				'colour'  => 'accent',
				'opacity' => 25,
			)
		);
		$fluffless_assert( str_contains( $fluffless_html, 'is-mark' ) && ! str_contains( $fluffless_html, 'is-line' ) && str_contains( $fluffless_html, '<svg' ) && str_contains( $fluffless_html, '--accent)' ), "{$fluffless_shape} : forme d'accent mal classée ou sans motif" );
		$fluffless_assert( str_contains( $fluffless_html, ';opacity:0.25' ), "{$fluffless_shape} : transparence absente" );
	}
	$fluffless_assert(
		! str_contains(
			Expression::render_vague(
				array(
					'shape'   => 'disc',
					'opacity' => 100,
				)
			),
			'opacity:'
		) && ! str_contains(
			Expression::render_vague(
				array(
					'shape'   => 'disc',
					'opacity' => 33,
				)
			),
			'opacity:'
		),
		'opacité 100 ou hors liste : aucun style'
	);
	$fluffless_assert(
		str_contains(
			Expression::render_vague(
				array(
					'shape'   => 'wave',
					'opacity' => '10',
				)
			),
			';opacity:0.1'
		),
		'opacité 10 en chaîne acceptée sur une vague'
	);
	foreach ( Expression::VAGUE_PRESETS as $fluffless_preset ) {
		$fluffless_assert( Expression::vague_attributes( array( 'height' => $fluffless_preset ) )['height'] === $fluffless_preset, "préréglage {$fluffless_preset} hors bornes" );
	}
	$fluffless_enum = wp_json_file_decode( dirname( __DIR__ ) . '/blocks/vague/block.json', array( 'associative' => true ) )['attributes']['shape']['enum'] ?? array();
	$fluffless_assert( array_merge( array_keys( Expression::VAGUE_SHAPES ), Expression::VAGUE_LINES, Expression::VAGUE_ACCENTS ) === $fluffless_enum, 'block.json et Expression ne listent pas les mêmes formes' );

	// 4. CSS : chargé seulement quand une vague est rendue, sous 1 ko.
	$fluffless_assert( strlen( Expression::vague_css() ) < 1024, 'CSS de la vague au-delà de 1 ko' );
	wp_dequeue_style( Expression::STYLE_VAGUE );
	wp_deregister_style( Expression::STYLE_VAGUE );
	$fluffless_assert( ! wp_style_is( Expression::STYLE_VAGUE, 'enqueued' ), 'CSS chargé avant tout rendu' );
	Expression::render_vague( array() );
	$fluffless_assert( wp_style_is( Expression::STYLE_VAGUE, 'enqueued' ), 'CSS non chargé après un rendu' );

	// 5. Bloc réel : enregistré depuis block.json, rendu par do_blocks().
	if ( ! $fluffless_registered ) {
		Expression::register_block();
	}
	$fluffless_assert( $fluffless_registry->is_registered( Expression::VAGUE ), 'bloc fluffless/vague non enregistré' );
	$fluffless_html = do_blocks( '<!-- wp:fluffless/vague {"shape":"tilt","colour":"contrast"} /-->' );
	$fluffless_assert( str_contains( $fluffless_html, 'is-shape-tilt' ) && str_contains( $fluffless_html, '--contrast)' ), 'do_blocks ne passe pas par le rendu du module' );
} finally {
	if ( ! $fluffless_registered && $fluffless_registry->is_registered( Expression::VAGUE ) ) {
		unregister_block_type( Expression::VAGUE );
	}
}

// Les deux : deux tracés, le premier retourné par la classe is-both.
$fluffless_both = Expression::render_vague( array( 'position' => 'both' ) );
$fluffless_assert( 2 === substr_count( $fluffless_both, '<svg' ) && str_contains( $fluffless_both, 'is-both' ), 'position both : deux tracés attendus' );

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Vague : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Vague : {$fluffless_checks} vérifications passées." );
