<?php
/**
 * Tests du mode clair ou sombre selon l'appareil (Presentation\Scheme, Blocks\ModeCouleur) :
 * jumelle des 7 palettes du thème dans les deux sens, contrastes (4,5:1 texte, 3:1 bordure des
 * champs), couleur de marque gardée, contraste élevé gardé, même entrée même sortie, palette
 * incomplète refusée, réglage enregistré puis retiré, feuille light-dark() sous @supports,
 * bloc, script d'en-tête et insertion après le menu seulement quand c'est actif, survie au
 * module Minify.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/scheme-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * L'option de la palette est remise à son état initial à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Blocks\ModeCouleur;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Minify;
use Fluffless\Suite\Modules\Palette;
use Fluffless\Suite\Presentation\Scheme;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Scheme::class ) || ! class_exists( ModeCouleur::class ) || ! isset( Loader::modules()['palette'] ) ) {
	throw new RuntimeException( 'Mode clair ou sombre absent : classe Presentation\Scheme, Blocks\ModeCouleur ou module Palette manquant.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// Mélange RVB comme color-mix(in srgb) : $share de $a sur $b.
$fluffless_mix = static function ( string $a, string $b, float $share ): string {
	$x = array_map( 'hexdec', str_split( substr( $a, 1 ), 2 ) );
	$y = array_map( 'hexdec', str_split( substr( $b, 1 ), 2 ) );
	return vsprintf( '#%02x%02x%02x', array_map( static fn( int $i ): int => (int) round( $x[ $i ] * $share + $y[ $i ] * ( 1 - $share ) ), array( 0, 1, 2 ) ) );
};

$fluffless_backup_option = get_option( Palette::OPTION, null );
$fluffless_backup_logo   = array( get_option( 'site_logo', null ), get_theme_mod( 'custom_logo', null ) );
$fluffless_no_border     = array(
	'border' => '',
	'large'  => false,
);
$fluffless_framed_button = array(
	'border' => 'contrast',
	'large'  => false,
);

// Les 7 palettes du thème : theme.json et ses variations.
$fluffless_theme_dir = get_template_directory();
$fluffless_sets      = array( 'theme.json' => $fluffless_theme_dir . '/theme.json' );
foreach ( (array) glob( $fluffless_theme_dir . '/styles/*.json' ) as $fluffless_file ) {
	$fluffless_sets[ basename( (string) $fluffless_file ) ] = (string) $fluffless_file;
}
$fluffless_palettes = array();
foreach ( $fluffless_sets as $fluffless_name => $fluffless_file ) {
	$fluffless_json = json_decode( (string) file_get_contents( $fluffless_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du thème.
	$fluffless_p    = array();
	foreach ( (array) ( $fluffless_json['settings']['color']['palette'] ?? array() ) as $fluffless_color ) {
		$fluffless_p[ $fluffless_color['slug'] ] = strtolower( $fluffless_color['color'] );
	}
	if ( array() !== $fluffless_p ) {
		$fluffless_palettes[ $fluffless_name ] = $fluffless_p;
	}
}

try {
	delete_option( Palette::OPTION );
	Scheme::reset();

	// 1. Les 7 palettes : une jumelle de sens inverse, toutes les paires au seuil.
	$fluffless_assert( 7 === count( $fluffless_palettes ), 'les 7 palettes du thème sont lues (' . count( $fluffless_palettes ) . ')' );
	$fluffless_directions = array();
	foreach ( $fluffless_palettes as $fluffless_name => $fluffless_p ) {
		$fluffless_result = Scheme::check( $fluffless_p, $fluffless_no_border );
		$fluffless_twin   = $fluffless_result['twin'];
		$fluffless_assert( null !== $fluffless_twin && array() === $fluffless_result['failures'], "{$fluffless_name} : jumelle sans paire en échec (" . implode( ', ', $fluffless_result['failures'] ) . ')' );
		if ( null === $fluffless_twin ) {
			continue;
		}
		$fluffless_directions[ Scheme::is_dark( $fluffless_p ) ? 'vers-clair' : 'vers-sombre' ] = true;
		$fluffless_assert( Scheme::is_dark( $fluffless_p ) !== Scheme::is_dark( $fluffless_twin ), "{$fluffless_name} : la jumelle est de sens inverse" );
		$fluffless_assert( array_keys( $fluffless_twin ) === array_keys( $fluffless_p ), "{$fluffless_name} : la jumelle a les mêmes rôles, dans le même ordre" );
		$fluffless_assert( array() === Palette::failures( $fluffless_twin, $fluffless_no_border ), "{$fluffless_name} : Palette::failures() vide sur la jumelle" );
		// Boutons à contour de texte (Brut, Contraste élevé), en petit texte : le cas le plus strict des variations.
		$fluffless_framed = Scheme::check( $fluffless_p, $fluffless_framed_button );
		$fluffless_assert( array() === $fluffless_framed['failures'], "{$fluffless_name} : jumelle sans échec avec bouton à contour (" . implode( ', ', $fluffless_framed['failures'] ) . ')' );
		$fluffless_assert( Palette::ratio( $fluffless_twin['contrast'], $fluffless_twin['base'] ) >= 4.5 && Palette::ratio( $fluffless_twin['contrast'], $fluffless_twin['surface'] ) >= 4.5, "{$fluffless_name} : texte à 4,5:1 sur le fond et la surface" );
		$fluffless_assert( Palette::ratio( $fluffless_twin['divider'], $fluffless_twin['base'] ) >= 3.0 && Palette::ratio( $fluffless_twin['divider'], $fluffless_twin['surface'] ) >= 3.0, "{$fluffless_name} : bordure des champs à 3:1 sur le fond et la surface" );
		// Surligneur du survol (LinkHover::MARKER, 40 % d'accent) sous le texte hérité.
		foreach ( array( 'base', 'surface' ) as $fluffless_ground ) {
			$fluffless_marker = $fluffless_mix( $fluffless_twin['accent'], $fluffless_twin[ $fluffless_ground ], 0.4 );
			$fluffless_assert( Palette::ratio( $fluffless_twin['contrast'], $fluffless_marker ) >= 4.5, "{$fluffless_name} : texte à 4,5:1 sur le surligneur, fond {$fluffless_ground}" );
		}
		foreach ( $fluffless_twin as $fluffless_role => $fluffless_color ) {
			$fluffless_assert( 1 === preg_match( '/^#[0-9a-f]{6}$/', $fluffless_color ), "{$fluffless_name} : {$fluffless_role} en #rrggbb minuscules" );
		}
		// Même entrée, même sortie, mémoire vidée ou non.
		Scheme::reset();
		$fluffless_assert( Scheme::twin( $fluffless_p, $fluffless_no_border ) === $fluffless_twin, "{$fluffless_name} : même entrée, même sortie" );
	}
	$fluffless_assert( isset( $fluffless_directions['vers-clair'], $fluffless_directions['vers-sombre'] ), 'les deux sens sont couverts par les palettes du thème' );

	// 2. Contraste élevé gardé au-dessus de 15:1.
	$fluffless_high = $fluffless_palettes['contraste-eleve.json'] ?? array();
	$fluffless_twin = array() === $fluffless_high ? null : Scheme::twin( $fluffless_high, $fluffless_no_border );
	$fluffless_assert( null !== $fluffless_twin && Palette::ratio( $fluffless_twin['contrast'], $fluffless_twin['base'] ) >= Scheme::HIGH_CONTRAST, 'contraste élevé : la jumelle garde au moins 15:1' );

	// 3. Couleur de marque qui passe déjà sur les fonds de la jumelle : gardée telle quelle.
	$fluffless_brand            = $fluffless_palettes['clair.json'] ?? array();
	$fluffless_brand['divider'] = '#808080';
	$fluffless_twin             = Scheme::twin( $fluffless_brand, $fluffless_no_border );
	$fluffless_assert( null !== $fluffless_twin && '#808080' === $fluffless_twin['divider'], 'bordure #808080 lisible des deux côtés : gardée' );

	// 4. Palette incomplète ou mal écrite : aucune jumelle.
	$fluffless_partial = $fluffless_palettes['theme.json'];
	unset( $fluffless_partial['accent'] );
	$fluffless_assert( array( 'incomplete' ) === Scheme::check( $fluffless_partial, $fluffless_no_border )['failures'] && null === Scheme::twin( $fluffless_partial, $fluffless_no_border ), 'rôle manquant : aucune jumelle' );
	$fluffless_bad         = $fluffless_palettes['theme.json'];
	$fluffless_bad['base'] = 'red';
	$fluffless_assert( null === Scheme::twin( $fluffless_bad, $fluffless_no_border ), 'couleur hors #rrggbb : aucune jumelle' );

	// 5. Feuille : light-dark() par rôle, sous @supports, choix du visiteur sur la racine.
	$fluffless_dark = $fluffless_palettes['theme.json'];
	$fluffless_twin = (array) Scheme::twin( $fluffless_dark, $fluffless_no_border );
	$fluffless_css  = Scheme::css( $fluffless_dark, $fluffless_twin );
	$fluffless_assert( str_starts_with( $fluffless_css, '@supports (color:light-dark(#000,#fff)){:root:root{color-scheme:light dark;' ), 'feuille sous @supports, color-scheme light dark' );
	$fluffless_assert( str_contains( $fluffless_css, '--wp--preset--color--base:light-dark(' . $fluffless_twin['base'] . ',' . $fluffless_dark['base'] . ')' ), 'palette sombre : sa couleur reste du côté sombre de light-dark()' );
	$fluffless_assert( str_contains( $fluffless_css, ':root[data-fluffless-scheme=light]{color-scheme:light}' ) && str_contains( $fluffless_css, ':root[data-fluffless-scheme=dark]{color-scheme:dark}' ), 'choix du visiteur posé sur la racine' );
	$fluffless_assert( str_contains( $fluffless_css, '.wp-block-fluffless-mode-couleur{display:none' ) && str_contains( $fluffless_css, ':root[data-fluffless-toggle] .wp-block-fluffless-mode-couleur{display:flex}' ), 'bouton caché tant que le script ne l\'a pas permis' );
	$fluffless_light = $fluffless_palettes['clair.json'];
	$fluffless_ltwin = (array) Scheme::twin( $fluffless_light, $fluffless_no_border );
	$fluffless_assert( str_contains( Scheme::css( $fluffless_light, $fluffless_ltwin ), '--wp--preset--color--base:light-dark(' . $fluffless_light['base'] . ',' . $fluffless_ltwin['base'] . ')' ), 'palette claire : sa couleur reste du côté clair de light-dark()' );
	$fluffless_same            = $fluffless_twin;
	$fluffless_same['divider'] = $fluffless_dark['divider'];
	$fluffless_assert( str_contains( Scheme::css( $fluffless_dark, $fluffless_same ), '--wp--preset--color--divider:' . $fluffless_dark['divider'] . ';' ), 'même couleur des deux côtés : valeur simple, sans light-dark()' );
	$fluffless_assert( strlen( $fluffless_css ) < 1800, 'feuille sous 1,8 ko (' . strlen( $fluffless_css ) . ' octets)' );

	// 6. Minify garde @supports et light-dark().
	$fluffless_min = (string) Minify::css( $fluffless_css );
	$fluffless_assert( str_contains( $fluffless_min, '@supports' ) && str_contains( $fluffless_min, 'light-dark(' ) && str_contains( $fluffless_min, 'color-scheme:light dark' ), 'Minify::css garde @supports, light-dark() et color-scheme' );
	$fluffless_html = (string) Minify::html( "<!doctype html><html><head><style>\n" . $fluffless_css . "\n</style></head><body><p>x</p></body></html>" );
	$fluffless_assert( str_contains( $fluffless_html, '@supports' ) && str_contains( $fluffless_html, 'light-dark(' ), 'Minify::html garde @supports et light-dark()' );

	// 7. Réglage : décoché par défaut, enregistré, puis retiré sans laisser d'option vide.
	$fluffless_assert( ! Scheme::enabled() && ! Scheme::active(), 'éteint par défaut' );
	Scheme::save( true );
	$fluffless_assert( Scheme::enabled() && true === ( get_option( Palette::OPTION )[ Scheme::KEY ] ?? null ), 'case cochée enregistrée' );
	$fluffless_assert( Scheme::active() && null !== Scheme::current_twin(), 'actif avec la palette du thème' );
	Scheme::save( false );
	$fluffless_assert( ! Scheme::enabled() && false === get_option( Palette::OPTION, false ), 'case décochée : option retirée' );
	update_option( Palette::OPTION, array( 'link_hover' => 'souligne' ), false );
	Scheme::save( true );
	Scheme::save( false );
	$fluffless_assert( array( 'link_hover' => 'souligne' ) === get_option( Palette::OPTION ), 'case décochée : les autres réglages restent' );
	delete_option( Palette::OPTION );
	update_option( Palette::OPTION, array( Scheme::KEY => 'oui' ), false );
	$fluffless_assert( ! Scheme::enabled(), 'valeur autre que true : éteint' );
	delete_option( Palette::OPTION );

	// 8. Éteint : rien sur le site.
	$fluffless_header       = new WP_Block_Template();
	$fluffless_header->type = 'wp_template_part';
	$fluffless_header->area = 'header';
	$fluffless_assert( '' === ModeCouleur::render(), 'éteint : le bloc ne rend rien' );
	ob_start();
	ModeCouleur::print_script();
	$fluffless_assert( '' === ob_get_clean(), 'éteint : aucun script d\'en-tête' );
	$fluffless_assert( array() === ModeCouleur::hook( array(), 'after', ModeCouleur::ANCHOR, $fluffless_header ), 'éteint : rien d\'accroché après le menu' );
	$fluffless_assert( array() === ModeCouleur::storage( array() ), 'éteint : aucune clé déclarée à fluffless-consent' );

	// 9. Actif : bouton, script et insertion.
	Scheme::save( true );
	$fluffless_button = ModeCouleur::render();
	$fluffless_assert( str_contains( $fluffless_button, '<button type="button" class="fl-scheme-toggle" aria-pressed="false">' ), 'actif : bouton à bascule, aria-pressed' );
	$fluffless_assert( str_contains( $fluffless_button, 'aria-hidden="true"' ) && 1 === preg_match( '#<span>[^<]+</span></button>#', $fluffless_button ), 'actif : icône cachée, nom en texte' );
	ob_start();
	ModeCouleur::print_script();
	$fluffless_script = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_script, 'id="fluffless-scheme-js"' ) && str_contains( $fluffless_script, 'data-no-optimize="1"' ) && str_contains( $fluffless_script, 'data-no-defer="1"' ) && str_contains( $fluffless_script, 'data-cfasync="false"' ), 'actif : script en ligne protégé des extensions de cache' );
	$fluffless_assert( ! str_contains( $fluffless_script, 'src=' ) && ! str_contains( $fluffless_script, '/*' ) && str_contains( $fluffless_script, 'fluffless-scheme' ), 'actif : script sans requête ni commentaire' );
	$fluffless_assert( strlen( ModeCouleur::script() ) < 1500, 'script sous 1,5 ko (' . strlen( ModeCouleur::script() ) . ' octets)' );
	$fluffless_assert( str_contains( ModeCouleur::script(), "'" . ModeCouleur::KEY . "'" ), 'actif : la clé déclarée est celle du script' );
	$fluffless_storage = ModeCouleur::storage( array( 'autre' => array( 'label' => 'Autre' ) ) );
	$fluffless_assert( array( 'autre', ModeCouleur::KEY ) === array_keys( $fluffless_storage ) && '' !== $fluffless_storage[ ModeCouleur::KEY ]['label'], 'actif : clé déclarée à fluffless-consent, entrées existantes gardées' );
	$fluffless_assert( false !== has_filter( 'fluffless_consent_storage', array( ModeCouleur::class, 'storage' ) ), 'actif : filtre de déclaration branché' );

	$fluffless_assert( array( ModeCouleur::NAME ) === ModeCouleur::hook( array(), 'after', ModeCouleur::ANCHOR, $fluffless_header ), 'actif : accroché après le menu d\'une partie d\'en-tête' );
	$fluffless_assert( array( ModeCouleur::NAME ) === ModeCouleur::hook( array(), 'after', ModeCouleur::ANCHOR, array( 'slug' => 'fluffless/menu' ) ), 'actif : accroché dans le menu partagé du thème' );
	$fluffless_footer       = new WP_Block_Template();
	$fluffless_footer->type = 'wp_template_part';
	$fluffless_footer->area = 'footer';
	$fluffless_assert( array() === ModeCouleur::hook( array(), 'after', ModeCouleur::ANCHOR, $fluffless_footer ), 'pied de page : rien d\'accroché' );
	$fluffless_assert( array() === ModeCouleur::hook( array(), 'before', ModeCouleur::ANCHOR, $fluffless_header ), 'autre position : rien d\'accroché' );
	$fluffless_assert( array() === ModeCouleur::hook( array(), 'after', 'core/group', $fluffless_header ), 'autre ancre : rien d\'accroché' );
	$fluffless_assert( array() === ModeCouleur::hook( array(), 'after', ModeCouleur::ANCHOR, new WP_Post( (object) array( 'post_type' => 'post' ) ) ), 'publication : rien d\'accroché' );
	$fluffless_parsed = array( 'blockName' => ModeCouleur::NAME );
	$fluffless_assert( null === ModeCouleur::hooked( $fluffless_parsed, ModeCouleur::NAME, 'after', array( 'attrs' => array( 'layout' => array( 'orientation' => 'vertical' ) ) ) ), 'menu vertical : bouton écarté' );
	$fluffless_assert( ModeCouleur::hooked( $fluffless_parsed, ModeCouleur::NAME, 'after', array( 'attrs' => array() ) ) === $fluffless_parsed, 'menu horizontal : bouton gardé' );

	// 10. Le motif du menu réel, lu comme WordPress le sert (Block Hooks appliqués par le
	// registre) : un seul bouton, après le menu horizontal ; aucun quand c'est éteint.
	$fluffless_registry = WP_Block_Patterns_Registry::get_instance();
	$fluffless_count    = static function () use ( $fluffless_registry ): int {
		$pattern = $fluffless_registry->get_registered( 'fluffless/menu' );
		return is_array( $pattern ) ? substr_count( (string) $pattern['content'], 'wp:' . ModeCouleur::NAME ) : -1;
	};
	$fluffless_assert( 1 === $fluffless_count(), 'motif du menu : un seul bouton inséré (' . $fluffless_count() . ')' );
	Scheme::save( false );
	$fluffless_assert( 0 === $fluffless_count(), 'motif du menu, éteint : aucun bouton (' . $fluffless_count() . ')' );

	// 11. Bloc enregistré, sans attribut, une seule fois par page.
	$fluffless_type = WP_Block_Type_Registry::get_instance()->get_registered( ModeCouleur::NAME );
	$fluffless_assert( $fluffless_type instanceof WP_Block_Type && array() === array_diff( array_keys( (array) $fluffless_type->attributes ), array( 'lock', 'metadata', 'className', 'style' ) ) && false === ( $fluffless_type->supports['multiple'] ?? true ), 'bloc enregistré, sans attribut propre, un seul par page' );

	// 12. Aperçu du logo sur le fond de la palette, puis de la jumelle ; rien sans logo.
	$fluffless_light = $fluffless_palettes['theme.json'];
	$fluffless_ltwin = (array) Scheme::twin( $fluffless_light, $fluffless_no_border );
	delete_option( 'site_logo' );
	remove_theme_mod( 'custom_logo' );
	$fluffless_assert( '' === Palette::scheme_logo( $fluffless_light, $fluffless_ltwin ), 'sans logo : aucun aperçu' );
	$fluffless_upload = wp_upload_bits( 'fl-scheme-logo-test.png', null, (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- image PNG de 1 px du test.
	$fluffless_assert( false === $fluffless_upload['error'], 'logo de test téléversé' );
	$fluffless_logo_id = (int) wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'fl-scheme-logo-test',
			'post_status'    => 'inherit',
		),
		$fluffless_upload['file']
	);
	wp_update_attachment_metadata(
		$fluffless_logo_id,
		array(
			'width'  => 1,
			'height' => 1,
			'file'   => _wp_relative_upload_path( $fluffless_upload['file'] ),
		)
	);
	set_theme_mod( 'custom_logo', $fluffless_logo_id );
	$fluffless_preview = Palette::scheme_logo( $fluffless_light, $fluffless_ltwin );
	$fluffless_assert( 2 === substr_count( $fluffless_preview, '<img' ) && str_contains( $fluffless_preview, 'alt=""' ), 'avec logo : deux images décoratives' );
	$fluffless_assert( (int) strpos( $fluffless_preview, 'background:' . $fluffless_light['base'] ) > 0 && strpos( $fluffless_preview, 'background:' . $fluffless_light['base'] ) < (int) strpos( $fluffless_preview, 'background:' . $fluffless_ltwin['base'] ), 'avec logo : fond de la palette, puis fond de la jumelle' );
	$fluffless_assert( str_contains( $fluffless_preview, '<figcaption' ), 'avec logo : légende' );
	$fluffless_assert( '' === Palette::scheme_logo( array( 'base' => 'red' ), $fluffless_ltwin ), 'fond hors #rrggbb : aucun aperçu' );
} finally {
	if ( isset( $fluffless_logo_id ) && $fluffless_logo_id > 0 ) {
		wp_delete_attachment( $fluffless_logo_id, true );
	}
	if ( null === $fluffless_backup_logo[0] ) {
		delete_option( 'site_logo' );
	} else {
		update_option( 'site_logo', $fluffless_backup_logo[0] );
	}
	if ( null === $fluffless_backup_logo[1] ) {
		remove_theme_mod( 'custom_logo' );
	} else {
		set_theme_mod( 'custom_logo', $fluffless_backup_logo[1] );
	}
	if ( null === $fluffless_backup_option ) {
		delete_option( Palette::OPTION );
	} else {
		update_option( Palette::OPTION, $fluffless_backup_option, false );
	}
	Scheme::reset();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Mode clair ou sombre : {$fluffless_checks} vérifications passées." );
