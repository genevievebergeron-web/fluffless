<?php
/**
 * Tests du module Palette : rôles, contraste, écriture dans les styles globaux, retour au thème.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/palette-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * Les styles globaux et l'option du module sont remis à leur état initial à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\GlobalStyles;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Palette;

defined( 'ABSPATH' ) || exit;

// Les contrôles lisent les textes en en_US : indépendants de la langue du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Palette::class ) || ! isset( Loader::modules()['palette'] ) ) {
	throw new RuntimeException( 'Module Palette absent : activer l\'extension et l\'inscrire dans Loader.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// Sans utilisatrice, wp_insert_post() ignore le terme wp_theme : WordPress recréerait les styles à chaque lecture.
$fluffless_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );

$fluffless_styles         = GlobalStyles::user();
$fluffless_backup_content = null === $fluffless_styles ? null : (string) get_post_field( 'post_content', $fluffless_styles['id'], 'raw' );
$fluffless_backup_option  = get_option( Palette::OPTION, null );

// Palette du thème : lisible, sert de base aux cas.
$fluffless_good = array(
	'base'      => '#12110f',
	'surface'   => '#1f1d1a',
	'contrast'  => '#f1ebdf',
	'muted'     => '#b3aa9a',
	'divider'   => '#3b3730',
	'accent'    => '#f4b400',
	'on-accent' => '#12110f',
	'accent-2'  => '#6fd3c1',
	'alert'     => '#ff8577',
);

$fluffless_effective = static function (): array {
	$out = array();
	foreach ( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) as $fluffless_color ) {
		$out[ $fluffless_color['slug'] ] = $fluffless_color['color'];
	}
	return $out;
};

try {
	$fluffless_assert( null !== $fluffless_styles, 'styles globaux indisponibles' );
	delete_option( Palette::OPTION );

	// 1. Les rôles sont ceux de theme.json, dans le même ordre.
	$fluffless_theme = WP_Theme_JSON_Resolver::get_theme_data()->get_settings()['color']['palette']['theme'] ?? array();
	$fluffless_assert( array_column( $fluffless_theme, 'slug' ) === Palette::ROLES, 'Palette::ROLES diffère des rôles de theme.json' );

	// 2. Assainissement : 9 couleurs #rrggbb, rien d'autre.
	$fluffless_upper = array_merge( $fluffless_good, array( 'accent' => '#F4B400' ) );
	$fluffless_clean = Palette::sanitize_palette( array_merge( $fluffless_upper, array( 'intrus' => '#000000' ) ) );
	$fluffless_assert( $fluffless_good === $fluffless_clean, 'assainissement : minuscules, ordre des rôles et clés inconnues retirées' );
	$fluffless_missing = $fluffless_good;
	unset( $fluffless_missing['alert'] );
	$fluffless_assert( null === Palette::sanitize_palette( $fluffless_missing ), 'rôle manquant accepté' );
	$fluffless_assert( null === Palette::sanitize_palette( array_merge( $fluffless_good, array( 'accent' => 'red' ) ) ), 'nom de couleur accepté' );
	$fluffless_assert( null === Palette::sanitize_palette( array_merge( $fluffless_good, array( 'accent' => '#fff' ) ) ), 'forme courte #fff acceptée' );
	$fluffless_assert( null === Palette::sanitize_palette( array_merge( $fluffless_good, array( 'accent' => '#f4b400;x' ) ) ), 'valeur avec CSS en trop acceptée' );

	// 3. Contraste : mêmes paires que tools/check-contrast.mjs.
	$fluffless_assert( array() === Palette::failures( $fluffless_good ), 'palette du thème refusée : ' . implode( ', ', Palette::failures( $fluffless_good ) ) );
	$fluffless_dim = array_merge( $fluffless_good, array( 'accent' => '#3b3730' ) );
	$fluffless_assert( in_array( 'accent/base', Palette::failures( $fluffless_dim ), true ), 'accent illisible sur base non signalé' );

	// 4. Écriture : la palette du site change, les autres clés restent, les noms du thème aussi.
	$fluffless_seed                                 = GlobalStyles::user()['data'] ?? array();
	$fluffless_seed['settings']['custom']['marker'] = 'garde-moi';
	GlobalStyles::save( (int) ( $fluffless_styles['id'] ?? 0 ), $fluffless_seed );

	$fluffless_new = array_merge( $fluffless_good, array( 'accent' => '#e0a800' ) );
	$fluffless_assert( Palette::apply( $fluffless_new ), 'apply() a échoué sur une palette lisible' );
	$fluffless_now = $fluffless_effective();
	$fluffless_assert( '#e0a800' === ( $fluffless_now['accent'] ?? '' ), 'la palette du site n\'a pas changé' );
	$fluffless_data = GlobalStyles::user()['data'] ?? array();
	$fluffless_assert( 'garde-moi' === GlobalStyles::get( $fluffless_data, 'settings.custom.marker' ), 'une clé étrangère a été perdue' );
	$fluffless_saved = (array) GlobalStyles::get( $fluffless_data, 'settings.color.palette.theme' );
	$fluffless_assert( array_column( $fluffless_theme, 'name' ) === array_column( $fluffless_saved, 'name' ), 'les noms des rôles du thème doivent être gardés' );
	$fluffless_assert( Palette::is_custom(), 'is_custom() faux après apply()' );
	$fluffless_assert( Palette::current() === $fluffless_new, 'current() ne rend pas la palette en place' );

	// 5. Palette illisible : refusée, site inchangé.
	$fluffless_assert( ! Palette::apply( $fluffless_dim ), 'palette illisible acceptée' );
	$fluffless_assert( '#e0a800' === ( $fluffless_effective()['accent'] ?? '' ), 'une palette refusée a quand même changé le site' );

	// 6. Retour au thème : la clé écrite disparaît, la valeur d'origine revient.
	$fluffless_assert( Palette::reset(), 'reset() a échoué' );
	$fluffless_data = GlobalStyles::user()['data'] ?? array();
	$fluffless_assert( null === GlobalStyles::get( $fluffless_data, 'settings.color.palette' ), 'la palette écrite est restée après reset()' );
	$fluffless_assert( '#f4b400' === ( $fluffless_effective()['accent'] ?? '' ), 'la palette du thème n\'est pas revenue' );
	$fluffless_assert( 'garde-moi' === GlobalStyles::get( $fluffless_data, 'settings.custom.marker' ), 'reset() a touché une clé étrangère' );
	$fluffless_assert( ! Palette::is_custom(), 'is_custom() vrai après reset()' );

	// 7. Indice énergétique : entier de 0 à 100, meilleur pour une palette sombre.
	$fluffless_light = array_merge(
		$fluffless_good,
		array(
			'base'    => '#f4f1ea',
			'surface' => '#e8e2d4',
		)
	);
	$fluffless_score = Palette::score( $fluffless_good );
	$fluffless_assert( $fluffless_score >= 0 && $fluffless_score <= 100, 'indice hors bornes' );
	$fluffless_assert( $fluffless_score > Palette::score( $fluffless_light ), 'une palette sombre doit obtenir un meilleur indice' );

	// 8. Fiche de marque : CMJN approché, variables CSS, variation theme.json.
	$fluffless_k = array(
		'c' => 0,
		'm' => 0,
		'y' => 0,
		'k' => 100,
	);
	$fluffless_assert( Palette::cmyk( '#000000' ) === $fluffless_k, 'CMJN du noir' );
	$fluffless_assert( 0 === array_sum( Palette::cmyk( '#ffffff' ) ), 'CMJN du blanc' );
	$fluffless_amber = array(
		'c' => 0,
		'm' => 26,
		'y' => 100,
		'k' => 4,
	);
	$fluffless_assert( Palette::cmyk( '#f4b400' ) === $fluffless_amber, 'CMJN de l’accent : ' . wp_json_encode( Palette::cmyk( '#f4b400' ) ) );
	$fluffless_assert( str_contains( Palette::css_vars( $fluffless_good ), '--wp--preset--color--accent: #f4b400;' ), 'variables CSS des préréglages' );
	$fluffless_variation = json_decode( Palette::variation_json( $fluffless_good ), true );
	$fluffless_assert( 3 === ( $fluffless_variation['version'] ?? null ) && array_column( $fluffless_variation['settings']['color']['palette'] ?? array(), 'color', 'slug' ) === $fluffless_good, 'variation theme.json de la palette' );
	wp_set_current_user( 1 );
	ob_start();
	Palette::render();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_twin = (string) strstr( (string) strstr( $fluffless_html, 'class="fl-palette-twin"' ), '</ul>', true );
	$fluffless_assert( 9 === substr_count( $fluffless_twin, 'class="fl-swatch"' ), 'version claire ou sombre affichée avec les 9 couleurs' );
	$fluffless_assert( str_contains( $fluffless_html, 'fl-brand-sheet' ) && 9 === substr_count( $fluffless_html, 'class="fl-swatch"' ) - substr_count( $fluffless_twin, 'class="fl-swatch"' ), 'fiche de marque affichée avec les 9 couleurs' );

	// 9. Palettes enregistrées : nom requis, lisibles seulement, survivent à reset(), plafond.
	$fluffless_assert( null === Palette::keep( '  ', $fluffless_good ), 'palette sans nom enregistrée' );
	$fluffless_assert( null === Palette::keep( 'Terne', $fluffless_dim ), 'palette illisible enregistrée' );
	$fluffless_slug = Palette::keep( 'Ma marque', $fluffless_new );
	$fluffless_assert( 'ma-marque' === $fluffless_slug, 'identifiant de palette enregistrée : ' . (string) $fluffless_slug );
	$fluffless_assert( ( Palette::saved()['ma-marque']['palette'] ?? null ) === $fluffless_new, 'palette enregistrée relue' );
	Palette::keep( 'Ma marque', $fluffless_good );
	$fluffless_assert( 1 === count( Palette::saved() ) && Palette::saved()['ma-marque']['palette'] === $fluffless_good, 'le même nom doit remplacer la palette' );
	$fluffless_assert( Palette::apply( $fluffless_new ) && Palette::reset() && isset( Palette::saved()['ma-marque'] ), 'reset() a effacé les palettes enregistrées' );
	for ( $fluffless_i = 2; $fluffless_i <= 12; $fluffless_i++ ) {
		Palette::keep( 'Essai ' . $fluffless_i, $fluffless_good );
	}
	$fluffless_assert( 12 === count( Palette::saved() ) && null === Palette::keep( 'De trop', $fluffless_good ), 'plafond de 12 palettes' );
	$fluffless_assert( Palette::forget( 'ma-marque' ), 'suppression d’une palette enregistrée' );
	$fluffless_assert( ! array_key_exists( 'ma-marque', Palette::saved() ), 'palette supprimée encore listée' );
	$fluffless_assert( false === Palette::forget( 'inconnue' ), 'suppression d’une palette inconnue acceptée' );
	ob_start();
	Palette::render();
	$fluffless_assert( 11 === substr_count( (string) ob_get_clean(), 'name="fl_forget"' ), 'liste des palettes enregistrées affichée' );

	// 10. Panneau de l'éditeur de site : données complètes, script chargé dans l'éditeur de site seulement.
	$fluffless_panel = Palette::editor_data();
	$fluffless_assert( array_keys( $fluffless_panel['roles'] ) === array_merge( Palette::ROLES, Palette::OPTIONAL_ROLES, array( 'button-border' ) ) && Palette::button() === $fluffless_panel['button'] && array_keys( $fluffless_panel['palette'] ) === Palette::ROLES && array_keys( $fluffless_panel['names'] ) === array_merge( Palette::ROLES, Palette::OPTIONAL_ROLES ), 'rôles du panneau (les facultatifs et la bordure de bouton ont un libellé, pas de couleur)' );
	$fluffless_assert( array( 'mood', 'strict', 'harmony' ) === array_keys( $fluffless_panel['options'] ), 'réglages du générateur dans le panneau' );
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	set_current_screen( 'dashboard' );
	Palette::editor_assets();
	$fluffless_assert( ! wp_script_is( 'fluffless-palette-editor', 'enqueued' ), 'panneau chargé hors de l’éditeur de site' );
	set_current_screen( 'site-editor' );
	Palette::editor_assets();
	$fluffless_assert( wp_script_is( 'fluffless-palette-editor', 'enqueued' ), 'panneau absent de l’éditeur de site' );

	// 11. Palette posée depuis l'éditeur de site (sans copie d'origine) : reconnue, puis retirée par reset().
	$fluffless_raw = GlobalStyles::user();
	GlobalStyles::save(
		(int) ( $fluffless_raw['id'] ?? 0 ),
		GlobalStyles::put(
			$fluffless_raw['data'] ?? array(),
			'settings.color.palette.theme',
			array(
				array(
					'slug'  => 'accent',
					'name'  => 'Accent',
					'color' => '#e0a800',
				),
			)
		)
	);
	$fluffless_assert( Palette::is_custom(), 'palette de l’éditeur de site non reconnue' );
	$fluffless_assert( Palette::reset() && ! Palette::is_custom(), 'reset() n’a pas retiré la palette de l’éditeur de site' );

	// 12. Codes Pantone fournis par la marque : liés au HEX, jamais calculés.
	$fluffless_assert( 'PANTONE 186 C' === Palette::sanitize_pantone( "  PANTONE \t 186 C " ), 'code Pantone : espaces normalisées' );
	$fluffless_assert( '' === Palette::sanitize_pantone( '186 C;color:red' ) && '' === Palette::sanitize_pantone( array() ) && '' === Palette::sanitize_pantone( str_repeat( 'A', 41 ) ), 'code Pantone douteux accepté' );
	$fluffless_assert( Palette::apply( $fluffless_good ), 'apply() avant les codes Pantone' );
	Palette::note_pantone(
		$fluffless_good,
		array(
			'base'      => 'Black 6 C',
			'accent'    => 'PANTONE 1235 C',
			'on-accent' => '',
		)
	);
	$fluffless_codes = array(
		'#12110f' => 'Black 6 C',
		'#f4b400' => 'PANTONE 1235 C',
	);
	$fluffless_assert( Palette::pantone() === $fluffless_codes, 'codes Pantone par HEX (un rôle vide qui partage la couleur ne l’efface pas) : ' . wp_json_encode( Palette::pantone() ) );
	$fluffless_assert( str_contains( Palette::css_vars( $fluffless_good ), '--wp--preset--color--accent: #f4b400; /* PANTONE 1235 C */' ), 'code Pantone dans les variables CSS' );
	ob_start();
	Palette::render();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_html, '<td>PANTONE 1235 C</td>' ) && 9 === substr_count( $fluffless_html, 'name="pantone[' ) && str_contains( $fluffless_html, 'name="pantone[accent]" value="PANTONE 1235 C"' ), 'codes Pantone dans le formulaire et la fiche' );
	$fluffless_assert( Palette::reset() && Palette::pantone() === $fluffless_codes, 'reset() a effacé les codes Pantone' );
	Palette::note_pantone( $fluffless_good, array( 'base' => 'Black 6 C' ) );
	$fluffless_assert( array( '#12110f' => 'Black 6 C' ) === Palette::pantone(), 'champ vidé : code Pantone retiré' );
	Palette::note_pantone( $fluffless_good, array() );
	$fluffless_stored = get_option( Palette::OPTION );
	$fluffless_assert( ! is_array( $fluffless_stored ) || ! array_key_exists( 'pantone', $fluffless_stored ), 'clé pantone vide laissée dans l’option' );
	ob_start();
	Palette::render();
	$fluffless_assert( ! str_contains( (string) ob_get_clean(), 'Pantone (brand guide)' ), 'colonne Pantone affichée sans code' );

	// 13. Rôle facultatif button-bg : absent, rien ne change; présent, ses paires sont vérifiées.
	$fluffless_assert( ! array_key_exists( 'button-bg', (array) Palette::sanitize_palette( $fluffless_good ) ), 'button-bg ajouté sans avoir été fourni' );
	$fluffless_button = array_merge( $fluffless_good, array( 'button-bg' => '#6FD3C1' ) );
	$fluffless_assert( '#6fd3c1' === ( Palette::sanitize_palette( $fluffless_button )['button-bg'] ?? '' ), 'button-bg : valeur non gardée en minuscules' );
	$fluffless_assert( Palette::sanitize_palette( array_merge( $fluffless_good, array( 'button-bg' => '' ) ) ) === $fluffless_good, 'button-bg vide : doit être ignoré' );
	$fluffless_assert( null === Palette::sanitize_palette( array_merge( $fluffless_good, array( 'button-bg' => 'teal' ) ) ), 'button-bg : nom de couleur accepté' );
	$fluffless_assert( array() === Palette::failures( $fluffless_button ), 'button-bg lisible refusé : ' . implode( ', ', Palette::failures( $fluffless_button ) ) );
	$fluffless_dark_button = Palette::failures( array_merge( $fluffless_good, array( 'button-bg' => '#2a2824' ) ) );
	$fluffless_assert( in_array( 'button-bg/base', $fluffless_dark_button, true ) && in_array( 'on-accent/button-bg', $fluffless_dark_button, true ), 'bouton sombre non signalé : ' . implode( ', ', $fluffless_dark_button ) );
	// #66625c : 3,12:1 sur base (passe à 3:1), 2,78:1 sur surface, 3,12:1 pour le texte on-accent.
	$fluffless_grey_button = Palette::failures( array_merge( $fluffless_good, array( 'button-bg' => '#66625c' ) ) );
	$fluffless_assert( array( 'on-accent/button-bg', 'button-bg/surface' ) === $fluffless_grey_button, 'seuils du bouton (4,5:1 texte, 3:1 contour) : ' . implode( ', ', $fluffless_grey_button ) );
	// Bordure déclarée : elle délimite le bouton à la place du fond ; texte large : 3:1 sous le texte.
	$fluffless_grey = array_merge( $fluffless_good, array( 'button-bg' => '#66625c' ) );
	$fluffless_join = static fn( array $pairs ): string => implode( ', ', $pairs );
	$fluffless_assert(
		array( 'on-accent/button-bg' ) === Palette::failures(
			$fluffless_grey,
			array(
				'border' => 'contrast',
				'large'  => false,
			)
		),
		'bordure contrast : le fond du bouton ne doit plus être jugé à 3:1 : ' . $fluffless_join(
			Palette::failures(
				$fluffless_grey,
				array(
					'border' => 'contrast',
					'large'  => false,
				)
			)
		)
	);
	$fluffless_assert(
		array( 'button-bg/surface' ) === Palette::failures(
			$fluffless_grey,
			array(
				'border' => '',
				'large'  => true,
			)
		),
		'texte large : 3,12:1 sous le texte doit passer : ' . $fluffless_join(
			Palette::failures(
				$fluffless_grey,
				array(
					'border' => '',
					'large'  => true,
				)
			)
		)
	);
	$fluffless_assert(
		array() === Palette::failures(
			$fluffless_grey,
			array(
				'border' => '#f1ebdf',
				'large'  => true,
			)
		),
		'bordure en hexadécimal et texte large refusés'
	);
	$fluffless_assert(
		array( 'button-border/base', 'button-border/surface' ) === Palette::failures(
			$fluffless_grey,
			array(
				'border' => 'divider',
				'large'  => true,
			)
		),
		'bordure trop pâle non signalée : ' . $fluffless_join(
			Palette::failures(
				$fluffless_grey,
				array(
					'border' => 'divider',
					'large'  => true,
				)
			)
		)
	);
	$fluffless_assert(
		in_array(
			'on-accent/accent',
			Palette::failures(
				array_merge( $fluffless_grey, array( 'accent' => '#66625c' ) ),
				array(
					'border' => '',
					'large'  => true,
				)
			),
			true
		),
		'le texte large du bouton ne doit pas abaisser on-accent/accent'
	);

	// Style des boutons lu dans theme.json : celui de Fluffless n'a ni bordure visible ni texte large.
	$fluffless_assert(
		array(
			'border' => '',
			'large'  => false,
		) === Palette::button(),
		'style des boutons du thème mal lu : ' . wp_json_encode( Palette::button() )
	);
	$fluffless_button_style = array();
	$fluffless_theme_filter = static function ( $data ) use ( &$fluffless_button_style ) {
		return $data->update_with(
			array(
				'version' => 3,
				'styles'  => array( 'elements' => array( 'button' => $fluffless_button_style ) ),
			)
		);
	};
	add_filter( 'wp_theme_json_data_theme', $fluffless_theme_filter );
	$fluffless_read = static function ( array $style ) use ( &$fluffless_button_style ): array {
		$fluffless_button_style = $style;
		wp_clean_theme_json_cache();
		return Palette::button();
	};
	try {
		$fluffless_assert(
			array(
				'border' => 'contrast',
				'large'  => true,
			) === $fluffless_read(
				array(
					'border'     => array(
						'color' => 'var:preset|color|contrast',
						'width' => '2px',
						'style' => 'solid',
					),
					'typography' => array(
						'fontSize'   => 'var:preset|font-size|medium',
						'fontWeight' => '700',
					),
				)
			),
			'bordure et texte large (medium en gras, 19,2 px au minimum) non lus'
		);
		$fluffless_assert(
			array(
				'border' => '',
				'large'  => false,
			) === $fluffless_read(
				array(
					'border'     => array(
						'color' => 'var(--wp--preset--color--contrast)',
						'width' => '0',
					),
					'typography' => array(
						'fontSize'   => 'var:preset|font-size|medium',
						'fontWeight' => '400',
					),
				)
			),
			'bordure nulle ou medium sans gras comptés'
		);
		$fluffless_assert(
			array(
				'border' => '#1f4e3d',
				'large'  => true,
			) === $fluffless_read(
				array(
					'border'     => array(
						'color' => '#1F4E3D',
						'width' => '0.125rem',
					),
					'typography' => array( 'fontSize' => 'clamp(1.5rem, 1rem + 2vw, 2rem)' ),
				)
			),
			'bordure en hexadécimal ou clamp() à 24 px non lus'
		);
		$fluffless_assert(
			array(
				'border' => '',
				'large'  => false,
			) === $fluffless_read(
				array(
					'border'     => array(
						'color' => 'var:preset|color|marque-3',
						'width' => '2px',
					),
					'typography' => array(
						'fontSize'   => 'var:preset|font-size|base',
						'fontWeight' => 'bold',
					),
				)
			),
			'couleur hors rôles ou base en gras (16 px) acceptées'
		);

		// Les messages de l'écran nomment la paire en cause.
		$fluffless_read(
			array(
				'border' => array(
					'color' => 'var:preset|color|divider',
					'width' => '2px',
				),
			)
		);
		$_GET['fl_palette'] = 'contrast';
		$_GET['fl_pairs']   = 'button-border/base';
		ob_start();
		Palette::render();
		$fluffless_assert( str_contains( (string) ob_get_clean(), 'Button border on Background' ), 'refus sans nommer la bordure de bouton' );
	} finally {
		unset( $_GET['fl_palette'], $_GET['fl_pairs'] );
		remove_filter( 'wp_theme_json_data_theme', $fluffless_theme_filter );
		wp_clean_theme_json_cache();
	}

	$fluffless_assert( Palette::apply( $fluffless_button ), 'apply() avec button-bg a échoué' );
	$fluffless_assert( '#6fd3c1' === ( $fluffless_effective()['button-bg'] ?? '' ) && Palette::current() === Palette::sanitize_palette( $fluffless_button ), 'button-bg non écrit dans la palette du site' );
	$fluffless_assert( Palette::apply( $fluffless_good ) && ! array_key_exists( 'button-bg', $fluffless_effective() ) && Palette::current() === $fluffless_good, 'palette sans button-bg : le rôle doit disparaître' );

	// 14. Couleurs hors rôles : gardées à l'application, hors contraste; border n'est pas recopié.
	$fluffless_extra_seed = GlobalStyles::user()['data'] ?? array();
	$fluffless_extra_list = array();
	foreach ( $fluffless_good as $fluffless_slug => $fluffless_hex ) {
		$fluffless_extra_list[] = array(
			'slug'  => $fluffless_slug,
			'name'  => $fluffless_slug,
			'color' => $fluffless_hex,
		);
	}
	$fluffless_extra_list[] = array(
		'slug'  => 'marque-3',
		'name'  => 'Marque 3',
		'color' => '#123456',
	);
	$fluffless_extra_list[] = array(
		'slug'  => 'border',
		'name'  => 'Ancien filet',
		'color' => '#3b3730',
	);
	$fluffless_extra_list[] = array(
		'slug'  => 'Mauvais slug',
		'name'  => 'x',
		'color' => '#000000',
	);
	GlobalStyles::save( (int) ( $fluffless_styles['id'] ?? 0 ), GlobalStyles::put( $fluffless_extra_seed, 'settings.color.palette.theme', $fluffless_extra_list ) );
	$fluffless_extra_want = array(
		array(
			'slug'  => 'marque-3',
			'name'  => 'Marque 3',
			'color' => '#123456',
		),
	);
	$fluffless_assert( Palette::extras() === $fluffless_extra_want, 'extras() : ' . wp_json_encode( Palette::extras() ) );
	$fluffless_assert( Palette::editor_data()['extras'] === $fluffless_extra_want, 'couleurs hors rôles absentes du panneau' );
	$fluffless_assert( array() === Palette::failures( array_merge( $fluffless_good, array( 'marque-3' => '#123456' ) ) ) && ! array_key_exists( 'marque-3', (array) Palette::sanitize_palette( array_merge( $fluffless_good, array( 'marque-3' => '#123456' ) ) ) ), 'une couleur hors rôles est entrée dans les rôles ou le contraste' );
	$fluffless_assert( Palette::apply( $fluffless_new ), 'apply() avec une couleur hors rôles a échoué' );
	$fluffless_extra_saved = array_column( (array) GlobalStyles::get( GlobalStyles::user()['data'] ?? array(), 'settings.color.palette.theme' ), 'color', 'slug' );
	$fluffless_assert( '#123456' === ( $fluffless_extra_saved['marque-3'] ?? '' ) && '#e0a800' === ( $fluffless_extra_saved['accent'] ?? '' ), 'couleur hors rôles perdue à l\'application : ' . wp_json_encode( $fluffless_extra_saved ) );
	$fluffless_assert( ! isset( $fluffless_extra_saved['border'] ) && ! isset( $fluffless_extra_saved['Mauvais slug'] ) && 10 === count( $fluffless_extra_saved ), 'border ou slug invalide recopié : ' . wp_json_encode( array_keys( $fluffless_extra_saved ) ) );
	$fluffless_assert( str_contains( Palette::variation_json( Palette::current() ), '"slug": "marque-3"' ), 'couleur hors rôles absente de la variation exportée' );

	// 12. Fonds en alternance : éteints par défaut, Base puis Surface ; aucune feuille tant que c'est éteint.
	$fluffless_alt = Palette::alternation();
	$fluffless_assert( false === $fluffless_alt['on'] && 'base' === $fluffless_alt['first'] && 'surface' === $fluffless_alt['second'], 'alternance : défaut inattendu : ' . wp_json_encode( $fluffless_alt ) );
	$fluffless_assert( '' === Palette::alternation_rules(), 'alternance éteinte : des règles CSS sont émises' );
	$fluffless_group = '<!-- wp:group --><div class="wp-block-group"><p>a</p></div><!-- /wp:group -->';
	$fluffless_html  = apply_filters( 'the_content', $fluffless_group . $fluffless_group ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$fluffless_assert( ! str_contains( $fluffless_html, 'is-fl-alt-' ), 'alternance éteinte : classe posée quand même' );
	Palette::save_alternation(
		array(
			'on'     => '1',
			'first'  => 'accent',
			'second' => 'accent',
		)
	);
	$fluffless_alt = Palette::alternation();
	$fluffless_assert( true === $fluffless_alt['on'] && 'base' === $fluffless_alt['first'] && 'surface' === $fluffless_alt['second'], 'alternance : deux rôles identiques non ramenés à Base et Surface : ' . wp_json_encode( $fluffless_alt ) );
	Palette::save_alternation(
		array(
			'on'     => '1',
			'first'  => 'pas-un-role',
			'second' => 'accent',
		)
	);
	$fluffless_alt = Palette::alternation();
	$fluffless_assert( 'base' === $fluffless_alt['first'] && 'accent' === $fluffless_alt['second'], 'alternance : rôle inconnu accepté : ' . wp_json_encode( $fluffless_alt ) );
	$fluffless_css = Palette::alternation_rules();
	$fluffless_assert( str_contains( $fluffless_css, '.is-fl-alt-1{background:var(--wp--preset--color--base);color:var(--wp--preset--color--contrast);--fl-alt-fond:var(--wp--preset--color--base)}' ) && str_contains( $fluffless_css, '.is-fl-alt-2{background:var(--wp--preset--color--accent);color:var(--wp--preset--color--on-accent);--fl-alt-fond:var(--wp--preset--color--accent)}' ), 'alternance : fonds ou texte lisible absents : ' . $fluffless_css );
	$fluffless_assert( str_contains( $fluffless_css, '.is-fl-alt-2 .wp-block-button:not(.is-style-outline)>.wp-element-button{background:var(--wp--preset--color--on-accent);color:var(--wp--preset--color--accent)}' ) && ! str_contains( $fluffless_css, '.is-fl-alt-1 .wp-block-button' ), 'alternance : bouton inversé manquant sur accent ou posé sur base' );
	$fluffless_assert( str_contains( $fluffless_css, 'body .fluffless-tableau.is-stripes-striped tbody tr:nth-child(odd){background:var(--wp--preset--color--accent)' ) && str_contains( $fluffless_css, 'tr:nth-child(even){background:var(--wp--preset--color--base)' ), 'alternance : zébrures du Tableau sans la paire : ' . $fluffless_css );
	$fluffless_assert( str_contains( $fluffless_css, '.fluffless-section:is(.is-fl-alt-1,.is-fl-alt-2){padding-inline:' ), 'alternance : rembourrage latéral des sections absent' );
	$fluffless_assert( strlen( $fluffless_css ) < 1024, 'alternance : feuille au-delà de 1 ko : ' . strlen( $fluffless_css ) );

	// Sur le contenu : les groupes sans fond alternent, ceux à couleur, dégradé ou style inversé gardent le leur et ne comptent pas ; un bloc imbriqué ne compte pas.
	$fluffless_html = apply_filters(
		'the_content', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$fluffless_group
		. '<!-- wp:group {"backgroundColor":"surface"} --><div class="wp-block-group has-surface-background-color has-background"><p>b</p></div><!-- /wp:group -->'
		. '<!-- wp:group {"className":"is-style-inverse"} --><div class="wp-block-group is-style-inverse"><p>c</p></div><!-- /wp:group -->'
		. '<!-- wp:group {"gradient":"maille"} --><div class="wp-block-group has-maille-gradient-background has-background"><p>d</p></div><!-- /wp:group -->'
		. '<!-- wp:group {"style":{"color":{"background":"#123456"}}} --><div class="wp-block-group has-background" style="background-color:#123456"><p>e</p></div><!-- /wp:group -->'
		. '<!-- wp:group {"className":"mine"} --><div class="wp-block-group mine">' . $fluffless_group . '</div><!-- /wp:group -->'
		. $fluffless_group
	);
	$fluffless_assert( 3 === substr_count( $fluffless_html, 'is-fl-alt-' ) && 2 === substr_count( $fluffless_html, 'is-fl-alt-1' ) && 1 === substr_count( $fluffless_html, 'is-fl-alt-2' ), 'alternance : comptage inattendu : ' . $fluffless_html );
	$fluffless_assert( 1 === preg_match( '/class="wp-block-group[^"]* is-fl-alt-1"><p>a<\/p>/', $fluffless_html ) && 1 === preg_match( '/class="wp-block-group mine[^"]* is-fl-alt-2"/', $fluffless_html ), 'alternance : classe absente du premier groupe ou du groupe nommé : ' . $fluffless_html );
	$fluffless_assert( 4 === preg_match_all( '/<p>[bcde]<\/p>/', $fluffless_html ) && 0 === preg_match( '/class="[^"]*is-fl-alt-\d[^"]*"[^>]*><p>[bcde]</', $fluffless_html ), 'alternance : un groupe à fond propre a été marqué : ' . $fluffless_html );
	$fluffless_assert( 0 === preg_match( '/is-fl-alt-\d"><p>a<\/p><\/div><\/div>/', $fluffless_html ), 'alternance : un groupe imbriqué a été marqué' );
	$fluffless_html = apply_filters( 'the_content', $fluffless_group ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$fluffless_assert( str_contains( $fluffless_html, 'is-fl-alt-1' ) && ! str_contains( $fluffless_html, 'is-fl-alt-2' ), 'alternance : compteur non remis à zéro entre deux publications' );
	// Hors de the_content, aucun marquage ; un bloc de la Suite à fond propre n'est pas marqué, un bloc sans fond l'est, un bloc imbriqué non plus.
	$fluffless_block = array(
		'blockName' => 'fluffless/liste',
		'attrs'     => array( 'background' => 'surface' ),
	);
	$fluffless_assert( Palette::alternate_block( $fluffless_block, $fluffless_block, null ) === $fluffless_block, 'alternance : marquage hors de the_content' );
	Palette::start_alternation( '' );
	$fluffless_assert( Palette::alternate_block( $fluffless_block, $fluffless_block, null ) === $fluffless_block, 'alternance : section à fond surface marquée' );
	$fluffless_block['attrs'] = array( 'className' => 'mine' );
	$fluffless_parent         = new WP_Block(
		array(
			'blockName'    => 'core/group',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
	$fluffless_assert( Palette::alternate_block( $fluffless_block, $fluffless_block, $fluffless_parent ) === $fluffless_block, 'alternance : bloc imbriqué marqué' );
	$fluffless_marked = Palette::alternate_block( $fluffless_block, $fluffless_block, null );
	$fluffless_assert( 1 === ( $fluffless_marked['attrs']['flAlternate'] ?? 0 ) && 'mine' === ( $fluffless_marked['attrs']['className'] ?? '' ), 'alternance : section de la Suite sans fond non marquée : ' . wp_json_encode( $fluffless_marked ) );
	$fluffless_marked = Palette::alternate_block( $fluffless_block, $fluffless_block, null );
	$fluffless_assert( 2 === ( $fluffless_marked['attrs']['flAlternate'] ?? 0 ), 'alternance : deuxième section non marquée 2' );
	$fluffless_assert( '<div class="wp-block-fluffless-liste is-fl-alt-2"><p>x</p></div>' === Palette::mark_alternation( '<div class="wp-block-fluffless-liste"><p>x</p></div>', $fluffless_marked ), 'alternance : classe absente du rendu marqué' );
	$fluffless_assert( '<div class="a"><p>x</p></div>' === Palette::mark_alternation( '<div class="a"><p>x</p></div>', $fluffless_block ), 'alternance : classe posée sur un bloc non marqué' );
	Palette::stop_alternation( '' );
	Palette::save_alternation( array( 'on' => '0' ) );
	$fluffless_assert( ! array_key_exists( 'alternate', (array) get_option( Palette::OPTION, array() ) ), 'alternance : réglage par défaut gardé dans l\'option' );
	$fluffless_assert( '' === Palette::alternation_rules(), 'alternance : règles émises après extinction' );

	// 13. Effet de survol des liens : éteint par défaut (rien n'est émis), valeur inconnue refusée, feuille sous 600 o, clé retirée de l'option au retour au défaut.
	$fluffless_assert( '' === Palette::link_hover() && '' === Palette::link_hover_rules(), 'survol des liens : défaut inattendu ou règles émises sans effet choisi' );
	wp_dequeue_style( Palette::LINK_HOVER_HANDLE );
	Palette::link_hover_css();
	$fluffless_assert( ! wp_style_is( Palette::LINK_HOVER_HANDLE, 'enqueued' ), 'survol des liens : feuille chargée sans effet choisi' );
	Palette::save_link_hover( 'clignote"><script>' );
	$fluffless_assert( '' === Palette::link_hover() && ! array_key_exists( 'link_hover', (array) get_option( Palette::OPTION, array() ) ), 'survol des liens : valeur inconnue acceptée' );
	foreach ( \Fluffless\Suite\Presentation\LinkHover::EFFECTS as $fluffless_effect ) {
		Palette::save_link_hover( $fluffless_effect );
		$fluffless_css = Palette::link_hover_rules();
		$fluffless_assert( Palette::link_hover() === $fluffless_effect && '' !== $fluffless_css && strlen( $fluffless_css ) < 600, "survol des liens {$fluffless_effect} : non enregistré ou feuille au-delà de 600 o : " . strlen( $fluffless_css ) );
		$fluffless_assert( str_contains( $fluffless_css, ':is(.wp-block-post-content,.wp-block-navigation) a:not(.wp-element-button,:has(img),[class*=-survol-] *)' ), "survol des liens {$fluffless_effect} : portée (contenu et navigation, ni bouton, ni lien d'image, ni bloc qui a son propre style de survol) absente : " . $fluffless_css );
		$fluffless_assert( \Fluffless\Suite\Presentation\LinkHover::rules( $fluffless_effect, \Fluffless\Suite\Presentation\LinkHover::SITE_SCOPE ) === $fluffless_css, "survol des liens {$fluffless_effect} : le réglage ne passe pas par la source unique" );
	}
	update_option( Palette::OPTION, array_merge( (array) get_option( Palette::OPTION, array() ), array( 'link_hover' => 'pas-un-effet' ) ) );
	$fluffless_assert( '' === Palette::link_hover() && '' === Palette::link_hover_rules(), 'survol des liens : valeur inconnue lue dans l\'option' );
	Palette::save_link_hover( 'etire' );
	wp_dequeue_style( Palette::LINK_HOVER_HANDLE );
	Palette::link_hover_css();
	$fluffless_assert( wp_style_is( Palette::LINK_HOVER_HANDLE, 'enqueued' ) && implode( '', (array) wp_styles()->get_data( Palette::LINK_HOVER_HANDLE, 'after' ) ) === Palette::link_hover_rules(), 'survol des liens : feuille en ligne absente avec un effet choisi' );
	wp_dequeue_style( Palette::LINK_HOVER_HANDLE );
	$fluffless_assert( false !== has_action( 'wp_enqueue_scripts', array( Palette::class, 'link_hover_css' ) ), 'survol des liens : feuille non branchée sur wp_enqueue_scripts' );
	Palette::save_link_hover( '' );
	$fluffless_assert( ! array_key_exists( 'link_hover', (array) get_option( Palette::OPTION, array() ) ), 'survol des liens : réglage par défaut gardé dans l\'option' );
} finally {
	// L'option d'abord : sinon Palette::restore_colors() remettrait la palette du test dans les styles.
	if ( null === $fluffless_backup_option ) {
		delete_option( Palette::OPTION );
	} else {
		update_option( Palette::OPTION, $fluffless_backup_option, false );
	}
	if ( null !== $fluffless_backup_content ) {
		wp_update_post(
			wp_slash(
				array(
					'ID'           => (int) ( $fluffless_styles['id'] ?? 0 ),
					'post_content' => $fluffless_backup_content,
				)
			)
		);
		WP_Theme_JSON_Resolver::clean_cached_data();
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Palette : {$fluffless_checks} vérifications passées." );
