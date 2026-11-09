<?php
/**
 * Tests du bloc Pop-up (module Expressivité) : ouverture au clic seulement (popover natif),
 * bandeau refermable (dialog + form method="dialog"), 0 JS, libellés assainis, ids uniques.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/popup-test.php
 * L'enregistrement des blocs est remis à son état initial à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Modules\Expression;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! defined( Expression::class . '::POPUP' ) ) {
	throw new RuntimeException( 'Pop-up absent du module Expressivité.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_registry = WP_Block_Type_Registry::get_instance();
$fluffless_before   = array();
foreach ( array( Expression::VAGUE, Expression::RECIT, Expression::POPUP ) as $fluffless_name ) {
	$fluffless_before[ $fluffless_name ] = $fluffless_registry->is_registered( $fluffless_name );
}

try {
	// 1. Attributs : mode fermé, libellés texte seul et bornés, défauts traduisibles.
	$fluffless_a = Expression::popup_attributes( array() );
	$fluffless_assert( 'popup' === $fluffless_a['mode'], 'mode par défaut : popup' );
	$fluffless_assert( '' !== $fluffless_a['label'] && '' !== $fluffless_a['closeLabel'], 'libellés par défaut vides' );
	$fluffless_a = Expression::popup_attributes(
		array(
			'mode'       => 'auto',
			'label'      => '<b onmouseover="x">Ouvrir</b>' . str_repeat( 'a', 200 ),
			'closeLabel' => '   ',
		)
	);
	$fluffless_assert( 'popup' === $fluffless_a['mode'], 'mode hors liste (dont ouverture automatique) : retour au défaut' );
	$fluffless_assert( ! str_contains( $fluffless_a['label'], '<' ) && ! str_contains( $fluffless_a['label'], 'onmouseover' ), 'balises gardées dans le libellé' );
	$fluffless_assert( mb_strlen( $fluffless_a['label'] ) <= Expression::POPUP_LABEL_MAX, 'libellé non borné' );
	$fluffless_assert( '' !== $fluffless_a['closeLabel'], 'libellé de fermeture vide : retour au défaut attendu' );

	// 2. Fenêtre au clic : bouton lié au panneau, fermeture native, aucun script.
	$fluffless_inner = '<p>Contenu de la fenêtre</p>';
	$fluffless_html  = Expression::render_popup( array( 'label' => 'Voir les tarifs' ), $fluffless_inner );
	$fluffless_assert( 1 === preg_match( '/popovertarget="([^"]+)"/', $fluffless_html, $fluffless_m ), 'bouton sans popovertarget' );
	$fluffless_id = $fluffless_m[1] ?? '';
	$fluffless_assert( str_contains( $fluffless_html, 'id="' . $fluffless_id . '" popover' ), 'panneau popover absent ou mal lié au bouton' );
	$fluffless_assert( str_contains( $fluffless_html, 'popovertargetaction="hide"' ), 'bouton de fermeture absent' );
	$fluffless_assert( str_contains( $fluffless_html, '<button type="button"' ), 'déclencheur hors d\'un vrai bouton' );
	$fluffless_assert( str_contains( $fluffless_html, $fluffless_inner ), 'contenu de la fenêtre modifié' );
	$fluffless_assert( str_contains( $fluffless_html, '>Voir les tarifs</button>' ), 'libellé du bouton absent' );
	$fluffless_assert( ! preg_match( '/<script|\son[a-z]+=|showPopover|showModal/i', $fluffless_html ), 'script ou ouverture programmée dans le rendu' );
	$fluffless_html2 = Expression::render_popup( array(), '' );
	$fluffless_assert( 1 === preg_match( '/popovertarget="([^"]+)"/', $fluffless_html2, $fluffless_m2 ) && $fluffless_m2[1] !== $fluffless_id, 'deux fenêtres partagent le même id' );

	// 3. Bandeau : dialog ouvert, refermable sans script.
	$fluffless_html = Expression::render_popup(
		array(
			'mode'  => 'banner',
			'label' => 'Annonce',
		),
		'<p>Fermé lundi</p>'
	);
	$fluffless_assert( str_contains( $fluffless_html, '<dialog open' ), 'bandeau hors d\'un dialog ouvert' );
	$fluffless_assert( str_contains( $fluffless_html, '<form method="dialog"' ), 'bandeau sans fermeture native' );
	$fluffless_assert( str_contains( $fluffless_html, 'aria-label="Annonce"' ), 'bandeau sans nom accessible' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'popover' ), 'le bandeau ne doit pas passer par popover' );
	$fluffless_assert( ! preg_match( '/<script|\son[a-z]+=/i', $fluffless_html ), 'script dans le bandeau' );
	$fluffless_assert( '' !== Expression::render_popup( 'x', null ), 'attributs non tableau : rendu par défaut attendu' );

	// 4. CSS : léger, et ne force jamais l'affichage d'un panneau ou d'un bandeau fermé.
	$fluffless_css = Expression::popup_css();
	$fluffless_assert( strlen( $fluffless_css ) < 1024, 'CSS du pop-up au-delà de 1 ko (' . strlen( $fluffless_css ) . ' o)' );
	$fluffless_assert( ! preg_match( '/__panel\{[^}]*display/', $fluffless_css ), 'display sur le panneau : il resterait visible fermé' );
	$fluffless_assert( ! preg_match( '/is-banner\{[^}]*display/', $fluffless_css ), 'display sur le bandeau hors [open] : il resterait visible fermé' );
	wp_dequeue_style( Expression::STYLE_POPUP );
	wp_deregister_style( Expression::STYLE_POPUP );
	Expression::render_popup( array(), '' );
	$fluffless_assert( wp_style_is( Expression::STYLE_POPUP, 'enqueued' ), 'CSS non chargé après un rendu' );

	// 5. Bloc réel.
	if ( ! $fluffless_before[ Expression::POPUP ] ) {
		Expression::register_block();
	}
	$fluffless_assert( $fluffless_registry->is_registered( Expression::POPUP ), 'bloc fluffless/popup non enregistré' );
	$fluffless_html = do_blocks( '<!-- wp:fluffless/popup {"mode":"banner","label":"Avis"} --><!-- wp:paragraph --><p>Avis important</p><!-- /wp:paragraph --><!-- /wp:fluffless/popup -->' );
	$fluffless_assert( str_contains( $fluffless_html, '<dialog open' ) && str_contains( $fluffless_html, 'Avis important</p>' ), 'do_blocks ne passe pas par le rendu du module' );
} finally {
	foreach ( $fluffless_before as $fluffless_name => $fluffless_was ) {
		if ( ! $fluffless_was && $fluffless_registry->is_registered( $fluffless_name ) ) {
			unregister_block_type( $fluffless_name );
		}
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Pop-up : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Pop-up : {$fluffless_checks} vérifications passées." );
