<?php
/**
 * Tests du module Canevas : supports de fond, rendu réel du moteur de styles, fond mobile,
 * calque (bornes, accessibilité, CSS chargé seulement si utilisé), classe du conteneur, droits.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/canvas-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * L'image, la personne de test et les enregistrements de blocs sont remis à leur état initial à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Canvas;
use Fluffless\Suite\Modules\Shape;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Canvas::class ) || ! isset( Loader::modules()['canvas'] ) ) {
	throw new RuntimeException( 'Module Canevas absent : l\'inscrire dans Loader.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_registry   = WP_Block_Type_Registry::get_instance();
$fluffless_image      = 0;
$fluffless_user       = 0;
$fluffless_reregister = false;
$fluffless_had_filter = false !== has_filter( 'register_block_type_args', array( Canvas::class, 'block_args' ) );

try {
	// 1. Contrat du module.
	$fluffless_assert( false === Canvas::enabled_by_default(), 'Canevas doit être inactif par défaut (il change le site public)' );
	$fluffless_assert( array( 'shape' ) === Loader::requires( 'canvas' ), 'Canevas doit déclarer sa dépendance au module Forme' );

	// 2. Supports : ajoutés aux blocs visés, gardés ailleurs, attribut de fond mobile sur tout bloc à image de fond.
	$fluffless_args = Canvas::block_args( array( 'supports' => array( 'background' => array( 'gradient' => true ) ) ), 'core/columns' );
	$fluffless_assert( true === ( $fluffless_args['supports']['background']['backgroundImage'] ?? null ), 'Colonnes sans image de fond' );
	$fluffless_assert( true === ( $fluffless_args['supports']['background']['gradient'] ?? null ), 'un support déjà déclaré a été écrasé' );
	$fluffless_assert( isset( $fluffless_args['attributes'][ Canvas::MOBILE_ATTR ] ), 'Colonnes sans attribut de fond mobile' );
	$fluffless_args = Canvas::block_args( array( 'supports' => array( 'background' => array( 'backgroundImage' => false ) ) ), 'core/column' );
	$fluffless_assert( false === $fluffless_args['supports']['background']['backgroundImage'], 'un refus explicite de block.json doit gagner' );
	$fluffless_args = Canvas::block_args( array( 'supports' => array( 'background' => array( 'backgroundImage' => true ) ) ), 'core/group' );
	$fluffless_assert( isset( $fluffless_args['attributes'][ Canvas::MOBILE_ATTR ] ), 'Groupe (fond natif) sans attribut de fond mobile' );
	$fluffless_args = Canvas::block_args( array( 'supports' => array() ), 'core/paragraph' );
	$fluffless_assert( ! isset( $fluffless_args['supports']['background'] ) && ! isset( $fluffless_args['attributes'] ), 'Paragraphe modifié à tort' );
	$fluffless_assert( 'x' === Canvas::block_args( 'x', 'core/columns' ), 'arguments non tableau modifiés' );

	// 3. Rendu réel : Colonne réenregistrée depuis son block.json, avec puis sans le filtre.
	$fluffless_markup = '<!-- wp:column {"style":{"background":{"backgroundImage":{"url":"https://example.test/fond.webp","id":0,"source":"file"},"backgroundSize":"contain","backgroundRepeat":"no-repeat","backgroundAttachment":"fixed","backgroundPosition":"20% 30%"}}} --><div class="wp-block-column"></div><!-- /wp:column -->';
	// Le témoin part de la Colonne native, même quand le module est actif sur le site.
	$fluffless_reregister = true;
	remove_filter( 'register_block_type_args', array( Canvas::class, 'block_args' ), 10 );
	$fluffless_registry->unregister( 'core/column' );
	register_block_type_from_metadata( ABSPATH . 'wp-includes/blocks/column' );
	$fluffless_before = do_blocks( $fluffless_markup );

	$fluffless_registry->unregister( 'core/column' );
	add_filter( 'register_block_type_args', array( Canvas::class, 'block_args' ), 10, 2 );
	register_block_type_from_metadata( ABSPATH . 'wp-includes/blocks/column' );
	$fluffless_after = do_blocks( $fluffless_markup );
	$fluffless_assert( ! str_contains( $fluffless_before, 'background-image' ), 'témoin : Colonne native ne devrait pas rendre de fond' );
	foreach ( array( 'background-image:url(', 'fond.webp', 'background-size:contain', 'background-repeat:no-repeat', 'background-attachment:fixed', 'background-position:20% 30%' ) as $fluffless_needle ) {
		$fluffless_assert( str_contains( $fluffless_after, $fluffless_needle ), "moteur de styles : « {$fluffless_needle} » absent du rendu de Colonne" );
	}

	// 4. Image de test dans la médiathèque.
	$fluffless_upload = wp_upload_dir();
	$fluffless_file   = trailingslashit( $fluffless_upload['path'] ) . 'fluffless-canvas-test.png';
	$fluffless_gd     = imagecreatetruecolor( 64, 32 );
	if ( false === $fluffless_gd || ! imagepng( $fluffless_gd, $fluffless_file ) ) {
		throw new RuntimeException( "Image de test impossible à écrire : {$fluffless_file}" );
	}
	$fluffless_image = (int) wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'fluffless-canvas-test',
			'post_status'    => 'inherit',
		),
		$fluffless_file
	);
	wp_update_attachment_metadata( $fluffless_image, wp_generate_attachment_metadata( $fluffless_image, $fluffless_file ) );
	$fluffless_assert( wp_attachment_is_image( $fluffless_image ), 'image de test non reconnue' );

	// 5. Calque : bornes et listes.
	$fluffless_clean = Canvas::calque_attributes(
		array(
			'x'         => 999,
			'y'         => -999,
			'width'     => 'abc',
			'opacity'   => 150,
			'blend'     => 'url(evil)',
			'layer'     => 'side',
			'className' => 'ok "><script>',
		)
	);
	$fluffless_assert( 150 === $fluffless_clean['x'] && -50 === $fluffless_clean['y'], 'X/Y non bornés' );
	$fluffless_assert( 30 === $fluffless_clean['width'], 'largeur invalide : défaut attendu' );
	$fluffless_assert( 100 === $fluffless_clean['opacity'], 'opacité non bornée' );
	$fluffless_assert( 'normal' === $fluffless_clean['blend'], 'mode de fusion hors liste accepté' );
	$fluffless_assert( false === $fluffless_clean['front'], 'couche inconnue : derrière attendu' );
	$fluffless_assert( ! str_contains( $fluffless_clean['className'], '<' ) && ! str_contains( $fluffless_clean['className'], '"' ), 'classe non assainie' );
	$fluffless_assert( 'center' === $fluffless_clean['pin'] && 'clip' === $fluffless_clean['clip'], 'ancrage et découpe : défauts attendus (centre, rester dans la section)' );
	$fluffless_assert( null === $fluffless_clean['xMobile'] && null === $fluffless_clean['widthMobile'], 'réglage mobile absent : null attendu (reprend le bureau)' );
	$fluffless_clean = Canvas::calque_attributes(
		array(
			'pin'         => 'top-right',
			'clip'        => 'overflow',
			'clipMargin'  => 999,
			'minWidth'    => '12rem',
			'maxWidth'    => '10vw',
			'xMobile'     => 400,
			'widthMobile' => '60',
		)
	);
	$fluffless_assert( 'top-right' === $fluffless_clean['pin'] && 'overflow' === $fluffless_clean['clip'], 'ancrage ou découpe valides refusés' );
	$fluffless_assert( 64 === $fluffless_clean['clipMargin'], 'marge de découpe non bornée' );
	$fluffless_assert( '12rem' === $fluffless_clean['minWidth'] && '' === $fluffless_clean['maxWidth'], 'bornes de taille : rem accepté, vw refusé' );
	$fluffless_assert( 150 === $fluffless_clean['xMobile'] && 60 === $fluffless_clean['widthMobile'], 'réglages mobiles non bornés' );
	$fluffless_clean = Canvas::calque_attributes(
		array(
			'pin'      => 'middle',
			'clip'     => 'hidden',
			'minWidth' => 'calc(1px)',
			'maxWidth' => '99999px',
		)
	);
	$fluffless_assert( 'center' === $fluffless_clean['pin'] && 'clip' === $fluffless_clean['clip'], 'ancrage ou découpe hors liste acceptés' );
	$fluffless_assert( '' === $fluffless_clean['minWidth'] && '' === $fluffless_clean['maxWidth'], 'borne de taille hors format ou démesurée acceptée' );

	// 6. Calque : rendu, accessibilité, CSS en ligne seulement si utilisé.
	$fluffless_assert( ! wp_style_is( 'fluffless-calque', 'enqueued' ), 'CSS du calque chargé sans calque' );
	$fluffless_assert( '' === Canvas::render_calque( array( 'id' => 0 ) ), 'calque sans image : rendu attendu vide' );
	$fluffless_assert( '' === Canvas::render_calque( array( 'id' => 999999 ) ), 'calque vers une image inexistante : rendu attendu vide' );
	$fluffless_assert( ! wp_style_is( 'fluffless-calque', 'enqueued' ), 'CSS du calque chargé pour un calque vide' );
	$fluffless_html  = Canvas::render_calque(
		array(
			'id'          => $fluffless_image,
			'x'           => 10,
			'y'           => 90,
			'width'       => 40,
			'rotate'      => -15,
			'opacity'     => 35,
			'blend'       => 'multiply',
			'layer'       => 'front',
			'hideMobile'  => true,
			'pin'         => 'top-right',
			'clipMargin'  => 8,
			'minWidth'    => '12rem',
			'xMobile'     => 20,
			'widthMobile' => 60,
		)
	);
	$fluffless_layer = new WP_HTML_Tag_Processor( $fluffless_html );
	$fluffless_assert( $fluffless_layer->next_tag() && 'SPAN' === $fluffless_layer->get_tag(), 'calque : couche dédiée (span) attendue autour de l\'image' );
	foreach ( array( 'fluffless-calque-layer', 'is-front', 'is-hide-mobile', 'is-pin-top-right', 'is-clip' ) as $fluffless_needle ) {
		$fluffless_assert( true === $fluffless_layer->has_class( $fluffless_needle ), "classe « {$fluffless_needle} » absente de la couche" );
	}
	$fluffless_style = (string) $fluffless_layer->get_attribute( 'style' );
	foreach ( array( '--fluffless-x:10%', '--fluffless-y:90%', '--fluffless-w:40;', '--fluffless-r:-15deg', '--fluffless-o:0.35', '--fluffless-blend:multiply', '--fluffless-min:12rem', '--fluffless-clip:8px', '--fluffless-xm:20%', '--fluffless-wm:60' ) as $fluffless_needle ) {
		$fluffless_assert( str_contains( $fluffless_style . ';', $fluffless_needle ), "variable « {$fluffless_needle} » absente de la couche" );
	}
	$fluffless_assert( ! str_contains( $fluffless_style, '--fluffless-ym' ) && ! str_contains( $fluffless_style, '--fluffless-max' ), 'réglage non fourni écrit quand même' );
	$fluffless_tags = new WP_HTML_Tag_Processor( $fluffless_html );
	$fluffless_assert( $fluffless_tags->next_tag( array( 'tag_name' => 'img' ) ), 'calque sans balise img' );
	$fluffless_assert( '' === $fluffless_tags->get_attribute( 'alt' ), 'calque décoratif : alt vide attendu' );
	$fluffless_assert( 'true' === $fluffless_tags->get_attribute( 'aria-hidden' ), 'calque décoratif : aria-hidden attendu' );
	$fluffless_assert( 'lazy' === $fluffless_tags->get_attribute( 'loading' ), 'calque : chargement différé attendu' );
	$fluffless_assert( true === $fluffless_tags->has_class( 'fluffless-calque' ), 'classe fluffless-calque absente de l\'image' );
	// Le cœur préfixe « auto, » aux images différées : seule la fin nous appartient.
	$fluffless_assert( str_ends_with( (string) $fluffless_tags->get_attribute( 'sizes' ), '(max-width: 781.98px) 60vw, 40vw' ), 'sizes : largeur mobile distincte attendue' );
	$fluffless_assert( wp_style_is( 'fluffless-calque', 'enqueued' ), 'CSS du calque absent alors qu\'un calque est rendu' );

	$fluffless_tags = new WP_HTML_Tag_Processor(
		Canvas::render_calque(
			array(
				'id'         => $fluffless_image,
				'decorative' => false,
				'alt'        => 'Carte du Québec',
			)
		)
	);
	$fluffless_tags->next_tag( array( 'tag_name' => 'img' ) );
	$fluffless_assert( 'Carte du Québec' === $fluffless_tags->get_attribute( 'alt' ), 'calque porteur de sens : alt perdu' );
	$fluffless_assert( null === $fluffless_tags->get_attribute( 'aria-hidden' ), 'calque porteur de sens : ne doit pas être masqué' );
	$fluffless_assert( ! str_contains( $fluffless_tags->get_updated_html(), 'blend' ) && ! str_contains( $fluffless_tags->get_updated_html(), '--fluffless-xm' ), 'fusion normale ou mobile absent : variable inutile écrite' );

	$fluffless_css = Canvas::calque_css();
	foreach ( array( 'container-type:inline-size', 'clip-path:inset(calc(-1*var(--fluffless-clip', 'overflow-x:clip', '1cqi', 'clamp(', 'max-width:781.98px', 'margin-block-start:0' ) as $fluffless_needle ) {
		$fluffless_assert( str_contains( $fluffless_css, $fluffless_needle ), "CSS du calque sans « {$fluffless_needle} »" );
	}
	$fluffless_assert( 1 !== preg_match( '/has-calque[^{]*\{[^}]*overflow/', $fluffless_css ), 'la section ne doit pas découper (contours de focus, menus des enfants)' );

	// 7. Classe du conteneur : seulement si un enfant direct est un calque.
	$fluffless_with    = parse_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:fluffless/calque {"id":' . $fluffless_image . '} /--></div><!-- /wp:group -->' )[0];
	$fluffless_without = parse_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Texte</p><!-- /wp:paragraph --></div><!-- /wp:group -->' )[0];
	$fluffless_assert( str_contains( Canvas::render_block( '<div class="wp-block-group"></div>', $fluffless_with ), Canvas::PARENT_CLASS ), 'conteneur d\'un calque sans classe' );
	$fluffless_assert( '<div class="wp-block-group"><p>Texte</p></div>' === Canvas::render_block( '<div class="wp-block-group"><p>Texte</p></div>', $fluffless_without ), 'bloc sans calque modifié' );

	// 8. Fond mobile : image de la médiathèque seulement, ou aucune.
	$fluffless_assert( ! wp_style_is( 'fluffless-bg-mobile', 'enqueued' ), 'CSS du fond mobile chargé sans usage' );
	$fluffless_assert( null === Canvas::mobile_value( array( 'url' => 'https://evil.test/x.png");}body{display:none' ) ), 'une URL sans identifiant ne doit jamais passer' );
	$fluffless_assert( null === Canvas::mobile_value( array( 'id' => 999999 ) ), 'identifiant inexistant accepté' );
	$fluffless_assert( 'none' === Canvas::mobile_value( array( 'hide' => true ) ), 'masquage du fond mobile ignoré' );
	$fluffless_mobile = Canvas::render_block(
		'<div class="wp-block-group" style="padding:1rem"></div>',
		array(
			'blockName' => 'core/group',
			'attrs'     => array( Canvas::MOBILE_ATTR => array( 'id' => $fluffless_image ) ),
		)
	);
	$fluffless_assert( str_contains( $fluffless_mobile, 'padding:1rem;--fluffless-bg-m:url(' ), 'variable de fond mobile mal ajoutée au style existant' );
	$fluffless_assert( str_contains( $fluffless_mobile, Canvas::MOBILE_CLASS ), 'classe de fond mobile absente' );
	$fluffless_assert( wp_style_is( 'fluffless-bg-mobile', 'enqueued' ), 'CSS du fond mobile absent alors qu\'il sert' );

	// 9. Droits : mode Simple sans contrôles, mode Studio avec.
	$fluffless_user = wp_insert_user(
		array(
			'user_login' => 'fluffless-canvas-test',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => 'author',
		)
	);
	if ( is_wp_error( $fluffless_user ) ) {
		throw new RuntimeException( 'Personne de test : ' . $fluffless_user->get_error_message() );
	}
	wp_set_current_user( $fluffless_user );
	if ( Loader::is_active( 'shape' ) ) {
		$fluffless_assert( false === Canvas::can_design(), 'mode Simple : les contrôles de graphiste doivent rester cachés' );
		$fluffless_assert( false === Canvas::editor_data()['canDesign'], 'mode Simple : canDesign doit valoir false dans l\'éditeur' );
		update_user_meta( $fluffless_user, Shape::STUDIO_META, '1' );
		$fluffless_assert( true === Canvas::can_design(), 'mode Studio : contrôles de graphiste attendus' );
	} else {
		$fluffless_assert( false === Canvas::can_design(), 'sans module Forme : réservé à edit_theme_options' );
	}
	$fluffless_assert( Canvas::BLOCKS === Canvas::editor_data()['blocks'], 'liste des blocs non transmise à l\'éditeur' );
} finally {
	if ( $fluffless_reregister ) {
		if ( ! $fluffless_had_filter ) {
			remove_filter( 'register_block_type_args', array( Canvas::class, 'block_args' ), 10 );
		}
		if ( $fluffless_registry->is_registered( 'core/column' ) ) {
			$fluffless_registry->unregister( 'core/column' );
		}
		register_block_type_from_metadata( ABSPATH . 'wp-includes/blocks/column' );
	}
	if ( $fluffless_image > 0 ) {
		wp_delete_attachment( $fluffless_image, true );
	}
	if ( is_int( $fluffless_user ) && $fluffless_user > 0 ) {
		wp_delete_user( $fluffless_user );
	}
	wp_set_current_user( 0 );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Canevas : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Canevas, {$fluffless_checks} contrôles passés.\n" );
