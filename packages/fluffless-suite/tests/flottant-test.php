<?php
/**
 * Tests du bloc Élément flottant (module Expressivité) : attributs bornés et listes fermées,
 * injections refusées (couleur, adresse, texte), rendu des trois modes, texte réel fourni une
 * seule fois, couleur de texte appariée à 4,5:1, un seul élément par page, poids, CSS chargé
 * seulement si utilisé.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/flottant-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * L'enregistrement du bloc est remis à son état initial à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Blocks\Flottant;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Expression;
use Fluffless\Suite\Modules\Palette;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Flottant::class ) || ! isset( Loader::modules()['expression'] ) ) {
	throw new RuntimeException( 'Bloc Élément flottant absent : classe Blocks\Flottant ou module Expressivité manquant.' );
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
$fluffless_registered = $fluffless_registry->is_registered( Flottant::NAME );

try {
	// 1. Attributs : défauts.
	$fluffless_a = Flottant::attributes( array() );
	$fluffless_assert( 'bouton' === $fluffless_a['mode'], 'mode par défaut : bouton' );
	$fluffless_assert( 'bottom-right' === $fluffless_a['corner'], 'coin par défaut : bottom-right' );
	$fluffless_assert( 'accent' === $fluffless_a['colour'], 'couleur par défaut : accent' );
	$fluffless_assert( '' === $fluffless_a['icon'], 'aucune icône par défaut' );
	$fluffless_assert( '' === $fluffless_a['url'], 'aucune adresse par défaut' );
	$fluffless_assert( false === $fluffless_a['newTab'] && false === $fluffless_a['mobileBar'], 'nouvel onglet et barre mobile éteints par défaut' );
	$fluffless_assert( '' !== $fluffless_a['text'], 'texte par défaut non vide' );

	// 2. Listes fermées et bornes.
	$fluffless_a = Flottant::attributes(
		array(
			'mode'      => 'explosion',
			'corner'    => 'centre',
			'colour'    => 'red;background:url(x)',
			'icon'      => '../../etc/passwd',
			'url'       => 'javascript:alert(1)',
			'text'      => str_repeat( 'a', 300 ),
			'newTab'    => 'oui',
			'mobileBar' => 1,
			'className' => 'ok "><script>',
		)
	);
	$fluffless_assert( 'bouton' === $fluffless_a['mode'], 'mode hors liste : retour au défaut' );
	$fluffless_assert( 'bottom-right' === $fluffless_a['corner'], 'coin hors liste : retour au défaut' );
	$fluffless_assert( 'accent' === $fluffless_a['colour'], 'couleur hors palette : retour à accent' );
	$fluffless_assert( '' === $fluffless_a['icon'], 'icône hors liste : aucune' );
	$fluffless_assert( '' === $fluffless_a['url'], 'adresse javascript: refusée' );
	$fluffless_assert( Flottant::MAX_TEXT === mb_strlen( $fluffless_a['text'] ), 'texte borné à MAX_TEXT' );
	$fluffless_assert( false === $fluffless_a['newTab'] && false === $fluffless_a['mobileBar'], 'booléens stricts seulement' );
	$fluffless_assert( ! str_contains( $fluffless_a['className'], '<' ) && ! str_contains( $fluffless_a['className'], '"' ), 'className assaini' );

	foreach ( Flottant::MODES as $fluffless_mode ) {
		$fluffless_assert( Flottant::attributes( array( 'mode' => $fluffless_mode ) )['mode'] === $fluffless_mode, "mode {$fluffless_mode} accepté" );
	}
	foreach ( Flottant::CORNERS as $fluffless_corner ) {
		$fluffless_assert( Flottant::attributes( array( 'corner' => $fluffless_corner ) )['corner'] === $fluffless_corner, "coin {$fluffless_corner} accepté" );
	}
	foreach ( array_keys( Flottant::ICONS ) as $fluffless_icon ) {
		$fluffless_assert( Flottant::attributes( array( 'icon' => $fluffless_icon ) )['icon'] === $fluffless_icon, "icône {$fluffless_icon} acceptée" );
		$fluffless_assert( '' !== \Fluffless\Suite\Blocks\Icone::svg( Flottant::ICONS[ $fluffless_icon ] ), "icône {$fluffless_icon} présente dans la liste du bloc Icône" );
	}
	foreach ( array( '/contact/', '#rendez-vous', 'https://example.org/a?b=1', 'mailto:a@example.org', 'tel:+15145550100' ) as $fluffless_url ) {
		$fluffless_assert( '' !== Flottant::attributes( array( 'url' => $fluffless_url ) )['url'], "adresse {$fluffless_url} acceptée" );
	}
	foreach ( array( 'javascript:alert(1)', 'data:text/html,<script>1</script>', 'vbscript:x' ) as $fluffless_url ) {
		$fluffless_assert( '' === Flottant::attributes( array( 'url' => $fluffless_url ) )['url'], "adresse {$fluffless_url} refusée" );
	}

	// 3. Couleur de texte : appariée à 4,5:1 sur chaque rôle de la palette active.
	$fluffless_hex = Flottant::palette_hex();
	$fluffless_assert( isset( $fluffless_hex['accent'] ), 'palette active lue (accent présent)' );
	foreach ( $fluffless_hex as $fluffless_slug => $fluffless_bg ) {
		$fluffless_fg = Flottant::text_slug( $fluffless_slug );
		$fluffless_assert( isset( $fluffless_hex[ $fluffless_fg ] ), "texte de {$fluffless_slug} : rôle de la palette" );
		$fluffless_best = 0.0;
		foreach ( $fluffless_hex as $fluffless_other ) {
			$fluffless_best = max( $fluffless_best, Palette::ratio( $fluffless_bg, $fluffless_other ) );
		}
		if ( isset( $fluffless_hex[ $fluffless_fg ] ) && $fluffless_best >= 4.5 ) {
			$fluffless_assert( Palette::ratio( $fluffless_bg, $fluffless_hex[ $fluffless_fg ] ) >= 4.5, "texte sur {$fluffless_slug} sous 4,5:1" );
		}
	}
	$fluffless_assert( 'contrast' === Flottant::text_slug( 'inconnu' ), 'fond inconnu : texte contrast' );

	// 4. Rendu du bouton.
	Flottant::reset();
	$fluffless_html = Flottant::render(
		array(
			'text'   => 'Écrire <b>ici</b> "maintenant"',
			'url'    => '/contact/',
			'icon'   => 'calendar',
			'newTab' => true,
			'corner' => 'top-left',
		)
	);
	$fluffless_assert( str_contains( $fluffless_html, 'fl-flottant--bouton' ) && str_contains( $fluffless_html, 'is-top-left' ), 'classes du mode et du coin' );
	$fluffless_assert( str_contains( $fluffless_html, 'href="/contact/"' ), 'lien relatif rendu' );
	$fluffless_assert( str_contains( $fluffless_html, 'target="_blank"' ) && str_contains( $fluffless_html, 'rel="noopener"' ), 'nouvel onglet : target et rel noopener' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<b>' ) && ! str_contains( $fluffless_html, '"maintenant"' ), 'texte échappé, balises retirées' );
	$fluffless_assert( 1 === substr_count( $fluffless_html, '<svg' ) && str_contains( $fluffless_html, 'aria-hidden="true"' ), 'icône décorative en ligne' );
	$fluffless_assert( str_contains( $fluffless_html, '--fl-f-bg:var(--wp--preset--color--accent)' ), 'fond en variable de palette' );
	$fluffless_assert( 1 === preg_match( '/--fl-f-fg:var\(--wp--preset--color--[a-z0-9-]+\)/', $fluffless_html ), 'texte en variable de palette' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'has-bar' ), 'pas de barre mobile sans l\'option' );

	Flottant::reset();
	$fluffless_assert( '' === Flottant::render( array( 'url' => '' ) ), 'bouton sans adresse : rien sur le site' );
	Flottant::reset();
	$fluffless_assert(
		str_contains(
			Flottant::render(
				array(
					'url'       => '/x',
					'mobileBar' => true,
				)
			),
			'has-bar'
		),
		'barre mobile demandée'
	);
	Flottant::reset();
	$fluffless_assert(
		! str_contains(
			Flottant::render(
				array(
					'mode'      => 'pastille',
					'mobileBar' => true,
				)
			),
			'has-bar'
		),
		'barre mobile réservée au mode bouton'
	);
	Flottant::reset();
	$fluffless_html = Flottant::render(
		array(
			'url'    => '/x',
			'colour' => 'red;} body{display:none',
		)
	);
	$fluffless_assert( ! str_contains( $fluffless_html, 'display:none' ) && ! str_contains( $fluffless_html, 'red' ), 'injection CSS par la couleur refusée' );

	// 5. Sceau : SVG décoratif, texte réel fourni une seule fois, lien facultatif.
	Flottant::reset();
	$fluffless_html = Flottant::render(
		array(
			'mode' => 'sceau',
			'text' => 'Atelier ouvert',
		)
	);
	$fluffless_assert( str_contains( $fluffless_html, 'class="fl-ring"' ) && str_contains( $fluffless_html, 'aria-hidden="true"' ), 'sceau : anneau décoratif' );
	$fluffless_assert( 1 === substr_count( $fluffless_html, 'fl-flottant__sr' ) && str_contains( $fluffless_html, '>Atelier ouvert</span>' ), 'sceau : texte réel une seule fois' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<a ' ), 'sceau sans adresse : aucun lien' );
	Flottant::reset();
	$fluffless_html = Flottant::render(
		array(
			'mode' => 'sceau',
			'url'  => '#contact',
		)
	);
	$fluffless_assert( str_contains( $fluffless_html, '<a ' ) && str_contains( $fluffless_html, 'href="#contact"' ), 'sceau avec adresse : lien' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'aria-label' ), 'sceau : pas de second nom accessible' );

	// 6. Pastille.
	Flottant::reset();
	$fluffless_html = Flottant::render(
		array(
			'mode' => 'pastille',
			'text' => 'Disponible en octobre',
		)
	);
	$fluffless_assert( str_contains( $fluffless_html, 'fl-flottant__dot' ) && str_contains( $fluffless_html, 'Disponible en octobre' ), 'pastille : point et texte' );
	$fluffless_assert( 1 === preg_match( '/fl-flottant__dot"[^>]*aria-hidden="true"/', $fluffless_html ), 'pastille : point décoratif' );

	// 7. Un seul élément flottant par page.
	Flottant::reset();
	$fluffless_first  = Flottant::render( array( 'url' => '/a' ) );
	$fluffless_second = Flottant::render( array( 'url' => '/b' ) );
	$fluffless_assert( '' !== $fluffless_first && '' === $fluffless_second, 'le second élément flottant de la page est ignoré' );
	Flottant::reset();
	Flottant::render( array( 'url' => '' ) );
	$fluffless_assert( '' !== Flottant::render( array( 'url' => '/c' ) ), 'un rendu vide ne compte pas comme l\'élément de la page' );

	// 8. CSS : poids, garde-fous.
	$fluffless_css = Flottant::css();
	foreach ( Flottant::MODES as $fluffless_mode ) {
		$fluffless_part = Flottant::css( $fluffless_mode, true );
		$fluffless_assert( strlen( $fluffless_part ) < 1536, "CSS du mode {$fluffless_mode} avec barre : " . strlen( $fluffless_part ) . ' o, budget 1536' );
		$fluffless_assert( strlen( $fluffless_part ) < strlen( $fluffless_css ), "CSS du mode {$fluffless_mode} : seulement sa part" );
		$fluffless_motion = strpos( $fluffless_part, '@media (prefers-reduced-motion:no-preference)' );
		$fluffless_still  = false === $fluffless_motion ? $fluffless_part : substr( $fluffless_part, 0, $fluffless_motion );
		$fluffless_assert( ! str_contains( $fluffless_still, 'animation' ), "mode {$fluffless_mode} : aucune animation hors de no-preference" );
		$fluffless_assert( ( 'bouton' === $fluffless_mode ) === ( false === $fluffless_motion ), "mode {$fluffless_mode} : seuls le sceau et la pastille bougent" );
	}
	$fluffless_assert( ! str_contains( Flottant::css( 'bouton', false ), 'has-bar' ), 'sans barre mobile : aucune règle de barre' );
	$fluffless_assert( str_contains( $fluffless_css, 'position:fixed' ), 'position fixed' );
	foreach ( array( 'top', 'right', 'bottom', 'left' ) as $fluffless_side ) {
		$fluffless_assert( str_contains( $fluffless_css, "env(safe-area-inset-{$fluffless_side}" ), "zone sûre {$fluffless_side}" );
	}
	$fluffless_assert( 1 === preg_match( '/z-index:(\d+)/', $fluffless_css, $fluffless_z ) && (int) $fluffless_z[1] < 20, 'z-index sous le panneau de menu (20) et le bandeau de consentement (100000)' );
	$fluffless_assert( str_contains( $fluffless_css, '@media print{.fl-flottant{display:none' ), 'masqué à l\'impression' );
	$fluffless_assert( str_contains( $fluffless_css, 'min-height:44px' ) && str_contains( $fluffless_css, 'min-width:44px' ), 'cible tactile de 44 px' );
	$fluffless_assert( str_contains( $fluffless_css, ':focus-visible' ), 'focus visible' );
	$fluffless_assert( str_contains( $fluffless_css, 'scroll-padding-bottom' ), 'scroll-padding-bottom avec la barre mobile' );
	$fluffless_assert( ! str_contains( $fluffless_css, 'url(' ) && ! str_contains( $fluffless_css, '@import' ) && 0 === preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_css ), 'aucun url(), @import ni couleur en dur' );
	$fluffless_assert( str_contains( Flottant::editor_css(), 'position:static' ), 'éditeur : rendu statique en place' );

	// 9. CSS chargé seulement si le bloc est rendu.
	wp_dequeue_style( Flottant::STYLE );
	wp_deregister_style( Flottant::STYLE );
	Flottant::reset();
	Flottant::render( array( 'url' => '' ) );
	$fluffless_assert( ! wp_style_is( Flottant::STYLE, 'enqueued' ), 'CSS chargé pour un rendu vide' );
	Flottant::render( array( 'url' => '/d' ) );
	$fluffless_assert( wp_style_is( Flottant::STYLE, 'enqueued' ), 'CSS non chargé après un rendu' );

	// 10. Bloc réel et block.json aligné sur les constantes.
	if ( ! $fluffless_registered ) {
		Expression::register_block();
	}
	$fluffless_assert( $fluffless_registry->is_registered( Flottant::NAME ), 'bloc fluffless/flottant non enregistré' );
	Flottant::reset();
	$fluffless_html = do_blocks( '<!-- wp:fluffless/flottant {"url":"/contact/","corner":"bottom-left"} /--><!-- wp:fluffless/flottant {"url":"/autre/"} /-->' );
	$fluffless_assert( str_contains( $fluffless_html, 'is-bottom-left' ) && ! str_contains( $fluffless_html, '/autre/' ), 'do_blocks : un seul élément rendu' );
	$fluffless_json = wp_json_file_decode( dirname( __DIR__ ) . '/blocks/flottant/block.json', array( 'associative' => true ) );
	$fluffless_assert( Flottant::MODES === ( $fluffless_json['attributes']['mode']['enum'] ?? null ), 'block.json : modes alignés' );
	$fluffless_assert( Flottant::CORNERS === ( $fluffless_json['attributes']['corner']['enum'] ?? null ), 'block.json : coins alignés' );
	$fluffless_assert( array_merge( array( '' ), array_keys( Flottant::ICONS ) ) === ( $fluffless_json['attributes']['icon']['enum'] ?? null ), 'block.json : icônes alignées' );
	$fluffless_assert( false === ( $fluffless_json['supports']['html'] ?? null ), 'block.json : html false' );
} finally {
	Flottant::reset();
	if ( ! $fluffless_registered && $fluffless_registry->is_registered( Flottant::NAME ) ) {
		unregister_block_type( Flottant::NAME );
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Élément flottant : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Élément flottant : {$fluffless_checks} vérifications passées." );
