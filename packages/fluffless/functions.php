<?php
/**
 * Fluffless : présentation seulement. Toute logique vit dans Fluffless Suite.
 *
 * @package Fluffless
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Traductions livrées avec le thème. WordPress ne cherche d'office que dans
 * wp-content/languages/themes : sans cet appel, languages/ est ignoré (vérifié sur WP 7.1).
 */
function fluffless_load_textdomain(): void {
	load_theme_textdomain( 'fluffless', get_template_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'fluffless_load_textdomain' );

/**
 * Charge style.css (quelques règles hors theme.json).
 */
function fluffless_enqueue_styles(): void {
	// Version = date de modification du fichier : chaque changement de style.css est servi tout de suite.
	$mtime   = filemtime( get_stylesheet_directory() . '/style.css' );
	$version = false !== $mtime ? (string) $mtime : (string) wp_get_theme( 'fluffless' )->get( 'Version' );
	wp_enqueue_style( 'fluffless', get_stylesheet_uri(), array(), $version );
	fluffless_flush_block_sheets();
}
add_action( 'wp_enqueue_scripts', 'fluffless_enqueue_styles' );

/**
 * Le rôle « border » s'appelle « divider » : WordPress donne la classe has-border-color à tout
 * bloc qui a une bordure colorée, et la couleur de ce rôle recouvrait alors son texte.
 * Une palette enregistrée sous l'ancien nom (écran Palette, éditeur de site, variation choisie)
 * est lue sous le nouveau ; la base n'est pas réécrite. Le relais CSS est dans style.css.
 *
 * @param WP_Theme_JSON_Data $theme_json Styles globaux de l'utilisateur.
 */
function fluffless_rename_border_role( WP_Theme_JSON_Data $theme_json ): WP_Theme_JSON_Data {
	$data    = $theme_json->get_data();
	$palette = $data['settings']['color']['palette']['theme'] ?? null;
	if ( ! is_array( $palette ) ) {
		return $theme_json;
	}
	$slugs = array_column( $palette, 'slug' );
	if ( ! in_array( 'border', $slugs, true ) || in_array( 'divider', $slugs, true ) ) {
		return $theme_json;
	}
	foreach ( $palette as $i => $color ) {
		if ( is_array( $color ) && 'border' === ( $color['slug'] ?? null ) ) {
			$palette[ $i ]['slug'] = 'divider';
		}
	}
	$data['settings']['color']['palette']['theme'] = $palette;
	return $theme_json->update_with( $data );
}
add_filter( 'wp_theme_json_data_user', 'fluffless_rename_border_role' );

/**
 * Accord avec Sensei LMS, seulement quand l'extension est active et sur ses pages (cours,
 * leçon, questionnaire, liste de cours, bloc Sensei) : ses couleurs fixes supposent un fond
 * blanc (voir assets/css/sensei.css). Filtre fluffless_sensei_styles pour étendre.
 */
function fluffless_enqueue_sensei_styles(): void {
	if ( ! class_exists( 'Sensei_Main' ) ) {
		return;
	}
	$course = function_exists( 'is_sensei' ) && is_sensei();
	if ( ! apply_filters( 'fluffless_sensei_styles', $course ) ) {
		return;
	}
	$file    = get_template_directory() . '/assets/css/sensei.css';
	$mtime   = filemtime( $file );
	$version = false !== $mtime ? (string) $mtime : (string) wp_get_theme( 'fluffless' )->get( 'Version' );
	wp_enqueue_style( 'fluffless-sensei', get_template_directory_uri() . '/assets/css/sensei.css', array(), $version );
}
add_action( 'wp_enqueue_scripts', 'fluffless_enqueue_sensei_styles', 20 );

/**
 * Extensions de formulaires établies reconnues par le thème : classe ou constante qui dit
 * que l'extension est active, codes courts et préfixes de blocs qui posent un formulaire.
 *
 * @return array<string, array{active: string, shortcodes: list<string>, blocks: list<string>}>
 */
function fluffless_form_plugins(): array {
	return array(
		'cf7'        => array(
			'active'     => 'WPCF7_PLUGIN',
			'shortcodes' => array( 'contact-form-7', 'contact-form' ),
			'blocks'     => array( 'contact-form-7/' ),
		),
		'wpforms'    => array(
			'active'     => 'WPFORMS_PLUGIN_URL',
			'shortcodes' => array( 'wpforms' ),
			'blocks'     => array( 'wpforms/' ),
		),
		'fluent'     => array(
			'active'     => 'FLUENTFORM_DIR_PATH',
			'shortcodes' => array( 'fluentform' ),
			// « fluentfom/ » sans r : nom réel du bloc de Fluent Forms (fluentfom/guten-block).
			'blocks'     => array( 'fluentfom/', 'fluentform/' ),
		),
		'formidable' => array(
			'active'     => 'FrmAppHelper',
			'shortcodes' => array( 'formidable', 'display-frm-data', 'frm_gated_content' ),
			'blocks'     => array( 'formidable/' ),
		),
		'gravity'    => array(
			'active'     => 'GF_PLUGIN_DIR_PATH',
			'shortcodes' => array( 'gravityform', 'gravityforms' ),
			'blocks'     => array( 'gravityforms/' ),
		),
	);
}

/**
 * Extensions de formulaires établies actives (clés de fluffless_form_plugins()).
 *
 * @return list<string>
 */
function fluffless_active_form_plugins(): array {
	$active = array();
	foreach ( fluffless_form_plugins() as $key => $plugin ) {
		if ( defined( $plugin['active'] ) || class_exists( $plugin['active'] ) ) {
			$active[] = $key;
		}
	}
	return $active;
}

/**
 * Le contenu porte un formulaire d'une extension établie (code court ou bloc).
 *
 * @param string                  $content Contenu brut d'une publication.
 * @param array<int, string>|null $plugins Clés à considérer ; toutes les extensions actives par défaut.
 */
function fluffless_content_has_form( string $content, ?array $plugins = null ): bool {
	if ( '' === $content ) {
		return false;
	}
	$table = fluffless_form_plugins();
	foreach ( $plugins ?? fluffless_active_form_plugins() as $key ) {
		foreach ( $table[ $key ]['blocks'] ?? array() as $prefix ) {
			if ( str_contains( $content, '<!-- wp:' . $prefix ) ) {
				return true;
			}
		}
		foreach ( $table[ $key ]['shortcodes'] ?? array() as $tag ) {
			if ( preg_match( '/\[' . preg_quote( $tag, '/' ) . '(?:[\s\]])/', $content ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Accord avec les extensions de formulaires établies (Contact Form 7, WPForms, Fluent Forms,
 * Formidable, Gravity Forms) : leurs champs, libellés, erreurs et boutons prennent les
 * couleurs, la police, les formes et le focus du thème (voir assets/css/forms.css). Chargé
 * seulement quand une de ces extensions est active et que la publication affichée porte
 * un de ses formulaires. Filtre fluffless_forms_styles pour étendre (formulaire dans un
 * gabarit, une fenêtre ou un pied de page).
 */
function fluffless_enqueue_forms_styles(): void {
	if ( array() === fluffless_active_form_plugins() ) {
		return;
	}
	$post = is_singular() ? get_post() : null;
	$form = $post instanceof WP_Post && fluffless_content_has_form( (string) $post->post_content );
	/**
	 * Force ou empêche la feuille d'accord des formulaires sur la page en cours.
	 *
	 * @param bool $form Vrai quand la publication affichée porte un formulaire connu.
	 */
	if ( ! apply_filters( 'fluffless_forms_styles', $form ) ) {
		return;
	}
	$file    = get_template_directory() . '/assets/css/forms.css';
	$mtime   = filemtime( $file );
	$version = false !== $mtime ? (string) $mtime : (string) wp_get_theme( 'fluffless' )->get( 'Version' );
	wp_enqueue_style( 'fluffless-forms', get_template_directory_uri() . '/assets/css/forms.css', array(), $version );
}
add_action( 'wp_enqueue_scripts', 'fluffless_enqueue_forms_styles', 20 );

/**
 * Accord avec WooCommerce, seulement sur ses pages : les champs de la caisse, le rouge
 * d'erreur et la pastille de promotion reprennent la palette (voir assets/css/woocommerce.css). En ligne dans
 * la feuille du thème, pour ne pas ajouter de requête.
 */
function fluffless_inline_woocommerce_styles(): void {
	$shop = ( function_exists( 'is_woocommerce' ) && is_woocommerce() )
		|| ( function_exists( 'is_cart' ) && is_cart() )
		|| ( function_exists( 'is_checkout' ) && is_checkout() )
		|| ( function_exists( 'is_account_page' ) && is_account_page() );
	/**
	 * Permet d'injecter les styles WooCommerce sur d'autres pages (grille de produits sur l'accueil).
	 *
	 * @param bool $shop Vrai sur les pages de boutique de WooCommerce.
	 */
	if ( ! apply_filters( 'fluffless_woocommerce_styles', $shop ) ) {
		return;
	}
	$css = file_get_contents( get_template_directory() . '/assets/css/woocommerce.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( is_string( $css ) && '' !== $css ) {
		wp_add_inline_style( 'fluffless', $css );
	}
}
add_action( 'wp_enqueue_scripts', 'fluffless_inline_woocommerce_styles', 20 );

/**
 * Les mêmes règles dans l'éditeur que sur le site : style.css porte les styles de blocs que
 * theme.json ne sait pas exprimer (flèche, coches, cadre décalé...). Sans cet appel,
 * l'éditeur ne les montre pas et n'est plus fidèle au rendu public.
 */
function fluffless_editor_styles(): void {
	$sheets = array( 'style.css' );
	foreach ( array_keys( fluffless_block_sheets() ) as $sheet ) {
		$sheets[] = "assets/css/blocks/{$sheet}.css";
	}
	add_editor_style( $sheets );
}
add_action( 'after_setup_theme', 'fluffless_editor_styles' );

/**
 * Feuilles de assets/css/blocks/ et classe qui les appelle. Une page sans cette classe ne
 * télécharge pas leurs règles ; l'éditeur les charge toutes (fluffless_editor_styles).
 *
 * @return array<string, string> Nom de feuille => classe CSS cherchée dans le rendu des blocs.
 */
function fluffless_block_sheets(): array {
	return array(
		'accordeon'     => 'wp-block-details',
		'bordure-brute' => 'is-style-bordure-brute',
		'cadre-decale'  => 'is-style-cadre-decale',
		'cadre-image'   => 'fluffless-cadre-image',
		'carte'         => 'is-style-carte',
		'coches'        => 'is-style-coches',
		'contour-pixel' => 'is-style-contour-pixel',
		'fleche'        => 'is-style-fleche',
		'fleche-pixel'  => 'is-style-fleche-pixel',
		'geant'         => 'is-style-geant',
		'grille'        => 'is-layout-grid',
		'monochrome'    => 'is-style-monochrome',
		'nb-survol'     => 'is-style-nb-survol',
		'prix'          => 'is-style-bordure-brute',
		'surligne'      => 'is-style-surligne',
		'taille-geante' => 'has-giant-font-size',
		'zigzag'        => 'fluffless-zigzag-inverse',
	);
}

/**
 * Repère, dans chaque bloc rendu, les classes qui ont une feuille dans assets/css/blocks/.
 * Une seule fois par feuille et par page.
 *
 * @param string $content Rendu du bloc.
 */
function fluffless_inline_block_sheets( string $content ): string {
	static $done = array();
	if ( '' === $content || is_admin() || is_feed() || wp_is_serving_rest_request() ) {
		return $content;
	}
	foreach ( fluffless_block_sheets() as $sheet => $class ) {
		if ( isset( $done[ $sheet ] ) || ! preg_match( '/[\s"]' . preg_quote( $class, '/' ) . '[\s"]/', $content ) ) {
			continue;
		}
		$done[ $sheet ] = true;
		if ( wp_style_is( 'fluffless', 'done' ) ) {
			// Bloc rendu après l'en-tête (pied de page, widget) : la feuille vient avec lui.
			$content = '<style id="fluffless-' . $sheet . '-inline-css">' . fluffless_block_sheet_css( $sheet ) . '</style>' . $content;
			continue;
		}
		fluffless_flush_block_sheets( $sheet );
	}
	return $content;
}
add_filter( 'render_block', 'fluffless_inline_block_sheets', 20 );

/**
 * Met en ligne, après style.css, les feuilles repérées. Un thème de blocs rend son gabarit
 * avant wp_head, donc avant que style.css soit enfilé : les feuilles attendent alors
 * fluffless_enqueue_styles(), puis arrivent dans l'en-tête, sans requête de plus.
 *
 * @param string $sheet Feuille à ajouter ; vide pour vider l'attente.
 */
function fluffless_flush_block_sheets( string $sheet = '' ): void {
	static $pending = array();
	if ( '' !== $sheet ) {
		$pending[] = $sheet;
	}
	if ( ! wp_style_is( 'fluffless', 'registered' ) ) {
		return;
	}
	// Ordre fixe, celui de fluffless_block_sheets(), quel que soit l'ordre des blocs dans la page.
	foreach ( array_keys( fluffless_block_sheets() ) as $name ) {
		if ( in_array( $name, $pending, true ) && '' !== fluffless_block_sheet_css( $name ) ) {
			wp_add_inline_style( 'fluffless', fluffless_block_sheet_css( $name ) );
		}
	}
	$pending = array();
}

/**
 * Contenu d'une feuille de assets/css/blocks/ ; vide si elle est illisible.
 *
 * @param string $sheet Nom de la feuille, sans .css.
 */
function fluffless_block_sheet_css( string $sheet ): string {
	$css = file_get_contents( get_template_directory() . "/assets/css/blocks/{$sheet}.css" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	return is_string( $css ) ? $css : '';
}

/**
 * Styles de blocs faits de CSS seulement. Leurs règles sont dans assets/css/blocks/.
 * Un fichier styles/blocks/*.json exigerait des styles non vides (WP 6.8.3 et plus)
 * et WordPress les recopierait dans la page à chaque bloc qui les utilise.
 */
function fluffless_register_block_styles(): void {
	$styles = array(
		'core/button'  => array(
			'fleche'        => _x( 'Arrow', 'Style variation name', 'fluffless' ),
			'contour-pixel' => _x( 'Pixel outline', 'Style variation name', 'fluffless' ),
			'fleche-pixel'  => _x( 'Pixel arrow', 'Style variation name', 'fluffless' ),
		),
		'core/heading' => array( 'surligne' => _x( 'Highlighted', 'Style variation name', 'fluffless' ) ),
		'core/image'   => array(
			'cadre-decale' => _x( 'Offset frame', 'Style variation name', 'fluffless' ),
			'nb-survol'    => _x( 'Greyscale on hover', 'Style variation name', 'fluffless' ),
			'monochrome'   => _x( 'Monochrome', 'Style variation name', 'fluffless' ),
		),
		'core/list'    => array( 'coches' => _x( 'Checkmarks', 'Style variation name', 'fluffless' ) ),
	);

	foreach ( $styles as $block => $variations ) {
		foreach ( $variations as $name => $label ) {
			register_block_style(
				$block,
				array(
					'name'  => $name,
					'label' => $label,
				)
			);
		}
	}
}
add_action( 'init', 'fluffless_register_block_styles' );

/**
 * Catégories de patterns, enregistrées avant le chargement des patterns.
 */
function fluffless_register_pattern_categories(): void {
	$categories = array(
		'fluffless-structure'  => __( 'Structure', 'fluffless' ),
		'fluffless-ouvertures' => __( 'Openers', 'fluffless' ),
		'fluffless-presenter'  => __( 'Showcase', 'fluffless' ),
		'fluffless-prouver'    => __( 'Proof', 'fluffless' ),
		'fluffless-raconter'   => __( 'Storytelling', 'fluffless' ),
		'fluffless-agir'       => __( 'Action', 'fluffless' ),
		'fluffless-pages'      => __( 'Full pages', 'fluffless' ),
	);

	foreach ( $categories as $slug => $label ) {
		register_block_pattern_category( $slug, array( 'label' => $label ) );
	}
}
add_action( 'init', 'fluffless_register_pattern_categories', 9 );

/**
 * Titre et description du gabarit « Archive en grille » dans l'éditeur de site et dans les
 * listes de gabarits (Fluffless Suite, Conditions d'affichage).
 *
 * @param array<string, array{title: string, description: string}> $types Types de gabarits connus.
 * @return array<string, array{title: string, description: string}>
 */
function fluffless_template_types( array $types ): array {
	$types['archive-grille'] = array(
		'title'       => _x( 'Archive in a grid', 'Template name', 'fluffless' ),
		'description' => __( 'The posts of an archive as cards in a three-column grid: image, category, title and date.', 'fluffless' ),
	);
	return $types;
}
add_filter( 'default_template_types', 'fluffless_template_types' );

/**
 * Classes à poser sur le corps du canevas de l'éditeur, déclarées par un thème enfant :
 * add_theme_support( 'fluffless-editor-canvas-classes', array( 'ma-classe' ) ).
 * WordPress ne copie dans l'iframe de l'éditeur que admin-color-*, post-type-* et
 * wp-embed-responsive ; une feuille préfixée par une classe du corps y resterait sans effet.
 * La même liste vaut pour tous les types de contenu (post-type-* reste là pour distinguer)
 * et pour les aperçus iframés (compositions, styles globaux).
 *
 * @return list<string> Classes nettoyées, sans doublon, 20 au plus ; vide sans support.
 */
function fluffless_editor_canvas_classes(): array {
	$support = get_theme_support( 'fluffless-editor-canvas-classes' );
	$list    = is_array( $support ) && is_array( $support[0] ?? null ) ? $support[0] : array();
	$classes = array();
	foreach ( $list as $class ) {
		$clean = is_string( $class ) ? sanitize_html_class( $class ) : '';
		if ( '' !== $clean && ! in_array( $clean, $classes, true ) ) {
			$classes[] = $clean;
		}
	}
	return array_slice( $classes, 0, 20 );
}

/**
 * Charge dans l'éditeur (document d'administration et iframe du canevas, parce que
 * enqueue_block_assets sert les deux) le script qui pose ces classes. Rien sans support,
 * rien sur le site public. Non couvert : l'éditeur sans iframe, où le canevas est
 * .editor-styles-wrapper dans le document principal.
 */
function fluffless_enqueue_editor_canvas_classes(): void {
	if ( ! is_admin() ) {
		return;
	}
	$classes = fluffless_editor_canvas_classes();
	if ( array() === $classes ) {
		return;
	}
	$file    = get_template_directory() . '/assets/js/editor-canvas-classes.js';
	$mtime   = filemtime( $file );
	$version = false !== $mtime ? (string) $mtime : (string) wp_get_theme( 'fluffless' )->get( 'Version' );
	wp_enqueue_script( 'fluffless-editor-canvas-classes', get_template_directory_uri() . '/assets/js/editor-canvas-classes.js', array(), $version, true );
	wp_add_inline_script( 'fluffless-editor-canvas-classes', 'window.flufflessEditorCanvasClasses = ' . wp_json_encode( $classes ) . ';', 'before' );
}
add_action( 'enqueue_block_assets', 'fluffless_enqueue_editor_canvas_classes' );

/**
 * Identifiants des ancêtres de l'objet interrogé, mémoïsés par requête : pages parentes d'une
 * page (ou de tout contenu hiérarchique), termes parents d'un terme sur son archive.
 *
 * @return array{post: list<int>, term: list<int>} Identifiants ; listes vides hors de ces cas.
 */
function fluffless_queried_ancestors(): array {
	static $memo = array();
	$key         = get_queried_object_id() . ':' . ( is_singular() ? 'post' : ( is_tax() || is_category() || is_tag() ? 'term' : '' ) );
	if ( isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}
	$object = get_queried_object();
	$found  = array(
		'post' => array(),
		'term' => array(),
	);
	if ( $object instanceof WP_Post && is_singular() ) {
		$found['post'] = array_map( 'intval', get_post_ancestors( $object ) );
	} elseif ( $object instanceof WP_Term && ( is_tax() || is_category() || is_tag() ) ) {
		$found['term'] = array_values( array_map( 'intval', get_ancestors( $object->term_id, $object->taxonomy, 'taxonomy' ) ) );
	}
	$memo[ $key ] = $found;
	return $found;
}

/**
 * Classe current-menu-ancestor (et current-page-ancestor pour un contenu) sur l'élément de
 * menu d'un lien ou d'un sous-menu quand la page affichée descend de sa cible sans figurer
 * dans le menu. Le cœur ne marque que la page courante (current-menu-item) et, dans un
 * sous-menu, ses parents ; une page enfant absente du menu ne mettait rien en évidence.
 * Un sous-menu dont un lien intérieur est ancêtre hérite aussi de la classe, comme le cœur
 * le fait pour current-menu-item. Jamais d'aria-current : il reste à la page courante.
 *
 * @param mixed $html  Rendu du lien ou du sous-menu.
 * @param mixed $block Bloc analysé.
 */
function fluffless_navigation_ancestor_classes( $html, $block ): string {
	$html = (string) $html;
	if ( '' === $html || ! is_array( $block ) || is_admin() ) {
		return $html;
	}
	$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
	$kind     = $attrs['kind'] ?? 'post-type';
	$id       = (int) ( $attrs['id'] ?? 0 );
	$classes  = array();
	$ancestry = fluffless_queried_ancestors();
	if ( $id > 0 && 'post-type' === $kind && in_array( $id, $ancestry['post'], true ) ) {
		$classes = array( 'current-menu-ancestor', 'current-page-ancestor' );
	} elseif ( $id > 0 && 'taxonomy' === $kind && in_array( $id, $ancestry['term'], true ) ) {
		$classes = array( 'current-menu-ancestor' );
	} elseif ( 'core/navigation-submenu' === ( $block['blockName'] ?? '' ) ) {
		$inner = new WP_HTML_Tag_Processor( $html );
		$inner->next_tag( array( 'tag_name' => 'LI' ) );
		if ( $inner->next_tag( array( 'class_name' => 'current-menu-ancestor' ) ) ) {
			$classes = array( 'current-menu-ancestor' );
		}
	}
	if ( array() === $classes ) {
		return $html;
	}
	$tags = new WP_HTML_Tag_Processor( $html );
	if ( ! $tags->next_tag( array( 'tag_name' => 'LI' ) ) || $tags->has_class( 'current-menu-item' ) || $tags->has_class( 'current-menu-ancestor' ) ) {
		return $html;
	}
	foreach ( $classes as $class ) {
		$tags->add_class( $class );
	}
	return $tags->get_updated_html();
}
add_filter( 'render_block_core/navigation-link', 'fluffless_navigation_ancestor_classes', 10, 2 );
add_filter( 'render_block_core/navigation-submenu', 'fluffless_navigation_ancestor_classes', 10, 2 );

/**
 * Retire du premier <nav> un aria-label fait d'un nombre seul : quand plusieurs blocs
 * Navigation n'ont pas de nom, le cœur nomme le deuxième « 2 », le troisième « 3 ». Rien
 * n'est inventé à la place ; donner un nom (ariaLabel) à chaque bloc Navigation reste la règle.
 *
 * @param mixed $html  Rendu du bloc Navigation.
 * @param mixed $block Bloc analysé (ignoré).
 */
function fluffless_navigation_strip_numeric_label( $html, $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature du filtre.
	$html = (string) $html;
	$tags = new WP_HTML_Tag_Processor( $html );
	if ( ! $tags->next_tag( array( 'tag_name' => 'NAV' ) ) ) {
		return $html;
	}
	$label = $tags->get_attribute( 'aria-label' );
	if ( is_string( $label ) && 1 === preg_match( '/^\s*\d+\s*$/', $label ) ) {
		$tags->remove_attribute( 'aria-label' );
		return $tags->get_updated_html();
	}
	return $html;
}
add_filter( 'render_block_core/navigation', 'fluffless_navigation_strip_numeric_label', 10, 2 );

/**
 * Enfile le script qui ferme à la touche Échap un <details data-fluffless-esc> ouvert et rend le
 * focus à son summary. Appelé par le menu mobile du thème, par l'action fluffless_nav_escape
 * (Suite, thème enfant) ; idempotent, site public seulement.
 */
function fluffless_nav_escape_script(): void {
	if ( is_admin() ) {
		return;
	}
	$file    = get_template_directory() . '/assets/js/nav-escape.js';
	$mtime   = filemtime( $file );
	$version = false !== $mtime ? (string) $mtime : (string) wp_get_theme( 'fluffless' )->get( 'Version' );
	wp_enqueue_script(
		'fluffless-nav-escape',
		get_template_directory_uri() . '/assets/js/nav-escape.js',
		array(),
		$version,
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'fluffless_nav_escape', 'fluffless_nav_escape_script' );

/**
 * Déclare à Fluffless Suite (tableau de bord) le seul script que le thème peut servir aux visiteurs.
 *
 * @param mixed $scripts Déclarations déjà posées par les autres composants.
 * @return array<string, list<string>>
 */
function fluffless_declare_visitor_scripts( $scripts ): array {
	$scripts          = is_array( $scripts ) ? $scripts : array();
	$scripts['theme'] = array( get_template_directory() . '/assets/js/nav-escape.js' );
	return $scripts;
}
add_filter( 'fluffless_visitor_scripts', 'fluffless_declare_visitor_scripts' );

/**
 * Le <details> du menu mobile (classe fluffless-nav-mobile) demande la fermeture à Échap :
 * attribut data-fluffless-esc posé au rendu, pour ne pas l'enregistrer dans le bloc, et script
 * enfilé. Les autres <details> (FAQ) gardent le comportement natif.
 *
 * @param mixed $html  Rendu du bloc Détails.
 * @param mixed $block Bloc analysé (ignoré).
 */
function fluffless_nav_mobile_escape( $html, $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature du filtre.
	$html = (string) $html;
	$tags = new WP_HTML_Tag_Processor( $html );
	if ( ! $tags->next_tag( array( 'tag_name' => 'DETAILS' ) ) || ! $tags->has_class( 'fluffless-nav-mobile' ) ) {
		return $html;
	}
	$tags->set_attribute( 'data-fluffless-esc', true );
	do_action( 'fluffless_nav_escape' );
	return $tags->get_updated_html();
}
add_filter( 'render_block_core/details', 'fluffless_nav_mobile_escape', 10, 2 );

/**
 * Racines du thème enfant puis du parent : adresse sans schéma ni barre finale => dossier réel
 * avec barre finale. Sert à reconnaître une image servie depuis le thème.
 *
 * @return array<string, string>
 */
function fluffless_theme_roots(): array {
	static $roots = null;
	if ( is_array( $roots ) ) {
		return $roots;
	}
	$roots = array();
	foreach ( array( array( get_stylesheet_directory_uri(), get_stylesheet_directory() ), array( get_template_directory_uri(), get_template_directory() ) ) as $pair ) {
		$real = realpath( $pair[1] );
		$uri  = untrailingslashit( (string) preg_replace( '#^https?:#i', '', $pair[0] ) );
		if ( false === $real || '' === $uri || isset( $roots[ $uri ] ) ) {
			continue;
		}
		$roots[ $uri ] = trailingslashit( $real );
	}
	return $roots;
}

/**
 * Largeur et hauteur d'un fichier SVG : la viewBox arrondie en entiers, sinon ses attributs
 * width et height en nombres (px admis). Lu sur 4 Ko au plus. Null sans l'un ni l'autre.
 *
 * @param string $file Chemin réel du fichier.
 * @return array{0: int, 1: int}|null
 */
function fluffless_svg_size( string $file ): ?array {
	$head = file_get_contents( $file, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier du thème, lecture locale.
	if ( ! is_string( $head ) ) {
		return null;
	}
	$tags = new WP_HTML_Tag_Processor( $head );
	if ( ! $tags->next_tag( array( 'tag_name' => 'SVG' ) ) ) {
		return null;
	}
	$view_box = $tags->get_attribute( 'viewBox' );
	if ( is_string( $view_box ) ) {
		$parts = preg_split( '/[\s,]+/', trim( $view_box ) );
		if ( is_array( $parts ) && 4 === count( $parts ) && is_numeric( $parts[2] ) && is_numeric( $parts[3] ) ) {
			$width  = (int) round( (float) $parts[2] );
			$height = (int) round( (float) $parts[3] );
			if ( $width > 0 && $height > 0 ) {
				return array( $width, $height );
			}
		}
	}
	$width  = $tags->get_attribute( 'width' );
	$height = $tags->get_attribute( 'height' );
	$number = static function ( $value ): int {
		return is_string( $value ) && 1 === preg_match( '/^\s*(\d+(?:\.\d+)?)(?:px)?\s*$/', $value, $m ) ? (int) round( (float) $m[1] ) : 0;
	};
	$width  = $number( $width );
	$height = $number( $height );
	return $width > 0 && $height > 0 ? array( $width, $height ) : null;
}

/**
 * Dimensions d'une image du thème désignée par son adresse ; null si l'adresse n'est pas dans
 * le thème, si le chemin remonte, si l'extension n'est pas une image ou si le fichier manque.
 * Mémoïsé par requête.
 *
 * @param string $src Adresse de l'image telle qu'écrite dans le bloc.
 * @return array{0: int, 1: int}|null
 */
function fluffless_theme_image_size( string $src ): ?array {
	static $memo = array();
	if ( array_key_exists( $src, $memo ) ) {
		return $memo[ $src ];
	}
	$memo[ $src ] = null;
	$path         = (string) preg_replace( '#^https?:#i', '', trim( $src ) );
	$path         = rawurldecode( (string) preg_replace( '/[?#].*$/s', '', $path ) );
	foreach ( fluffless_theme_roots() as $uri => $dir ) {
		if ( ! str_starts_with( $path, $uri . '/' ) ) {
			continue;
		}
		$relative = substr( $path, strlen( $uri ) + 1 );
		if ( '' === $relative || str_contains( $relative, '..' ) || str_contains( $relative, "\0" ) || 1 !== preg_match( '/\.(webp|png|jpe?g|gif|avif|svg)$/i', $relative ) ) {
			return null;
		}
		$file = realpath( $dir . $relative );
		if ( false === $file || ! str_starts_with( $file, $dir ) || ! is_file( $file ) ) {
			return null;
		}
		if ( 1 === preg_match( '/\.svg$/i', $file ) ) {
			$size = fluffless_svg_size( $file );
		} else {
			$info = wp_getimagesize( $file );
			$size = is_array( $info ) && (int) $info[0] > 0 && (int) $info[1] > 0 ? array( (int) $info[0], (int) $info[1] ) : null;
		}
		$memo[ $src ] = $size;
		return $size;
	}
	return null;
}

/**
 * Blocs Image et Média et texte : une image servie depuis le dossier du thème (pas une pièce
 * jointe, donc ignorée du cœur) reçoit width et height lus dans le fichier. Le cœur pose
 * ensuite decoding, loading et fetchpriority, qu'il n'ajoute qu'aux images dimensionnées.
 * Jamais sur une image déjà dimensionnée, téléversée ou extérieure.
 *
 * @param mixed $html  Rendu du bloc.
 * @param mixed $block Bloc analysé.
 */
function fluffless_image_dimensions_from_theme( $html, $block ): string {
	$html = (string) $html;
	if ( '' === $html || ! is_array( $block ) ) {
		return $html;
	}
	$tags    = new WP_HTML_Tag_Processor( $html );
	$changed = false;
	while ( $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		if ( null !== $tags->get_attribute( 'width' ) || null !== $tags->get_attribute( 'height' ) ) {
			continue;
		}
		$src = $tags->get_attribute( 'src' );
		if ( ! is_string( $src ) ) {
			continue;
		}
		$size = fluffless_theme_image_size( $src );
		if ( null === $size ) {
			continue;
		}
		$tags->set_attribute( 'width', (string) $size[0] );
		$tags->set_attribute( 'height', (string) $size[1] );
		$changed = true;
	}
	return $changed ? $tags->get_updated_html() : $html;
}
add_filter( 'render_block_core/image', 'fluffless_image_dimensions_from_theme', 10, 2 );
add_filter( 'render_block_core/media-text', 'fluffless_image_dimensions_from_theme', 10, 2 );

/**
 * Identifiant stable pour la légende d'un tableau : condensé du bloc, suffixe -2, -3 pour les
 * répétitions dans la même requête.
 *
 * @param string $seed Contenu enregistré du bloc.
 */
function fluffless_table_caption_id( string $seed ): string {
	static $seen   = array();
	$base          = 'fluffless-table-' . substr( md5( $seed ), 0, 8 );
	$seen[ $base ] = ( $seen[ $base ] ?? 0 ) + 1;
	return 1 === $seen[ $base ] ? $base : $base . '-' . $seen[ $base ];
}

/**
 * Bloc Tableau : la figure, que le cœur fait défiler de côté (overflow-x: auto), devient un
 * repère atteignable au clavier et nommé : tabindex="0", role="region", aria-labelledby vers
 * la légende (qui reçoit un id stable au besoin), sinon aria-label « Table », ou le nom rendu
 * par le filtre fluffless_table_region_label( string $label, array $block ). Un attribut déjà
 * présent est gardé. Sans script, l'arrêt de tabulation existe même quand rien ne déborde ;
 * un enfant peut retirer le filtre.
 *
 * @param mixed $html  Rendu du bloc.
 * @param mixed $block Bloc analysé.
 */
function fluffless_table_scroll_region( $html, $block ): string {
	$html = (string) $html;
	if ( '' === $html || ! is_array( $block ) ) {
		return $html;
	}
	$caption_id = null;
	$tags       = new WP_HTML_Tag_Processor( $html );
	if ( $tags->next_tag( array( 'tag_name' => 'FIGCAPTION' ) ) ) {
		$caption_id = $tags->get_attribute( 'id' );
		if ( ! is_string( $caption_id ) || '' === $caption_id ) {
			$inner      = $block['innerHTML'] ?? '';
			$caption_id = fluffless_table_caption_id( is_string( $inner ) && '' !== $inner ? $inner : $html );
			$tags->set_attribute( 'id', $caption_id );
			$html = $tags->get_updated_html();
		}
	}
	$tags = new WP_HTML_Tag_Processor( $html );
	if ( ! $tags->next_tag( array( 'tag_name' => 'FIGURE' ) ) ) {
		return $html;
	}
	if ( null === $tags->get_attribute( 'tabindex' ) ) {
		$tags->set_attribute( 'tabindex', '0' );
	}
	if ( null === $tags->get_attribute( 'role' ) ) {
		$tags->set_attribute( 'role', 'region' );
	}
	if ( null === $tags->get_attribute( 'aria-label' ) && null === $tags->get_attribute( 'aria-labelledby' ) ) {
		if ( null !== $caption_id ) {
			$tags->set_attribute( 'aria-labelledby', $caption_id );
		} else {
			// Deux repères du même nom se confondent (landmark-unique) : le premier tableau sans légende
			// garde « Table », les suivants de la même page sont numérotés.
			static $untitled = 0;
			++$untitled;
			$label = 1 === $untitled
				? __( 'Table', 'fluffless' )
				/* translators: %d: position of the caption-less table on the page, from 2. */
				: sprintf( __( 'Table %d', 'fluffless' ), $untitled );
			/**
			 * Nom du repère d'un tableau sans légende.
			 *
			 * @param string               $label Nom par défaut, « Table » traduit, numéroté à partir du deuxième tableau.
			 * @param array<string, mixed> $block Bloc analysé.
			 */
			$label = apply_filters( 'fluffless_table_region_label', $label, $block );
			if ( is_string( $label ) && '' !== trim( $label ) ) { // @phpstan-ignore function.alreadyNarrowedType (un filtre tiers peut rendre autre chose qu'une chaîne)
				$tags->set_attribute( 'aria-label', $label );
			}
		}
	}
	return $tags->get_updated_html();
}
add_filter( 'render_block_core/table', 'fluffless_table_scroll_region', 10, 2 );

/**
 * Page publiée à un chemin donné, ou null. WordPress garde le résultat de `get_page_by_path` en
 * cache d'exécution par chemin (une requête SQL au plus) et l'invalide à chaque changement de contenu.
 *
 * @param string $path Chemin de la page, p. ex. « /contact/ ».
 */
function fluffless_published_page( string $path ): ?WP_Post {
	$page = get_page_by_path( trim( $path, '/' ) );
	return ( $page instanceof WP_Post && 'publish' === $page->post_status ) ? $page : null;
}

/**
 * Adresse d'une page du site par son chemin, ou « # » tant que la page n'existe pas.
 *
 * Les motifs pointent vers Services, À propos ou Contact : sur un site neuf ces pages n'existent
 * pas encore, et un lien codé en dur mènerait à une erreur 404 publique. « # » reste sur la page.
 * Le lien inséré porte aussi la classe de `fluffless_page_class()` : au rendu public, il est relié à
 * la page dès qu'elle est publiée, et masqué tant qu'elle ne l'est pas.
 *
 * @param string $path Chemin de la page, p. ex. « /contact/ ». « / » donne l'accueil.
 */
function fluffless_page_url( string $path ): string {
	if ( '/' === $path ) {
		return home_url( '/' );
	}
	$page = fluffless_published_page( $path );
	return null === $page ? '#' : (string) get_permalink( $page );
}

/**
 * Classe repère d'un lien vers une page du site (survit à l'éditeur, contrairement à un attribut data-).
 *
 * @param string $path Chemin de la page, p. ex. « /contact/ ».
 */
function fluffless_page_class( string $path ): string {
	return 'fluffless-page-' . implode( '--', array_map( 'sanitize_title', explode( '/', trim( $path, '/' ) ) ) );
}

/**
 * Chemin de page lu dans une liste de classes (inverse de `fluffless_page_class()`), ou null.
 *
 * @param string $classes Valeur d'un attribut class.
 */
function fluffless_marked_path( string $classes ): ?string {
	if ( 1 !== preg_match( '/(?:^|\s)fluffless-page-([a-z0-9_-]+)(?:\s|$)/i', $classes, $found ) ) {
		return null;
	}
	return '/' . str_replace( '--', '/', $found[1] ) . '/';
}

/**
 * Adresse à donner à un lien marqué : l'adresse de la page cible si elle est publiée, null pour
 * masquer le lien (page absente ou non publiée), false pour le laisser tel quel.
 *
 * Seules les valeurs d'origine sont reliées : « # », une adresse d'exemple, ou l'adresse de la page
 * cible reconnue par son chemin (comparé sans schéma, sans préfixe de langue ni de sous-dossier) ou
 * par `?page_id=` quand la page n'existe plus. Une adresse saisie ensuite par la cliente reste intacte.
 *
 * @param string $href Valeur de l'attribut href.
 * @param string $path Chemin de la page cible.
 * @return string|false|null
 */
function fluffless_resolve_page_href( string $href, string $path ) {
	$page  = fluffless_published_page( $path );
	$url   = null === $page ? null : (string) get_permalink( $page );
	$href  = trim( $href );
	$parts = wp_parse_url( $href );
	if ( '' === $href || '#' === $href ) {
		return $url;
	}
	if ( ! is_array( $parts ) ) {
		return false;
	}
	$host = strtolower( (string) ( $parts['host'] ?? '' ) );
	if ( '' !== $host ) {
		if ( 'exemple.ca' === $host || str_ends_with( $host, '.exemple.ca' ) ) {
			return $url;
		}
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( preg_replace( '/^www\./', '', $host ) !== preg_replace( '/^www\./', '', $home ) ) {
			return false;
		}
	}
	$query = array();
	parse_str( (string) ( $parts['query'] ?? '' ), $query );
	$page_id = isset( $query['page_id'] ) && is_scalar( $query['page_id'] ) ? (int) $query['page_id'] : 0;
	if ( $page_id > 0 ) {
		if ( null !== $page && $page_id === $page->ID ) {
			$extra = array_diff_key( $query, array( 'page_id' => true ) );
			return isset( $parts['fragment'] ) || array() !== $extra ? false : $url; // Ancre ou paramètres saisis : l'adresse de la cliente reste telle quelle.
		}
		$other = get_post( $page_id );
		return ( $other instanceof WP_Post && 'publish' === $other->post_status ) ? false : $url;
	}
	if ( isset( $parts['fragment'] ) || ( isset( $parts['query'] ) && '' !== $parts['query'] ) ) {
		return false; // Ancre ou paramètres saisis : l'adresse de la cliente reste telle quelle.
	}
	$route = trim( rawurldecode( (string) ( $parts['path'] ?? '' ) ), '/' );
	$want  = trim( $path, '/' );
	$base  = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	if ( '' !== $base && str_starts_with( $route . '/', $base . '/' ) ) {
		$route = trim( substr( $route, strlen( $base ) ), '/' ); // Installation en sous-dossier : le dossier du site n'est pas un préfixe de langue.
	}
	if ( '' !== $route && 1 === preg_match( '#^(?:[a-z]{2,3}(?:[_-][a-z]{2})?/)?' . preg_quote( $want, '#' ) . '$#i', $route ) ) {
		return $url; // Chemin exact, avec au plus un préfixe de langue (fr, en-ca).
	}
	return false;
}

/**
 * Relie au rendu un bouton ou une entrée de menu marqués par `fluffless_page_class()`.
 *
 * @param string               $content Rendu du bloc.
 * @param array<string, mixed> $block   Bloc analysé.
 */
function fluffless_render_page_link( string $content, array $block ): string {
	$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
	if ( ! empty( $attrs['id'] ) ) {
		return $content; // Entrée liée à une page par son identifiant : le cœur masque déjà une page non publiée.
	}
	$path = fluffless_marked_path( is_string( $attrs['className'] ?? null ) ? $attrs['className'] : '' );
	if ( null === $path ) {
		return $content;
	}
	$tags = new WP_HTML_Tag_Processor( $content );
	if ( ! $tags->next_tag( array( 'tag_name' => 'A' ) ) ) {
		return $content;
	}
	$href = $tags->get_attribute( 'href' );
	if ( ! is_string( $href ) ) {
		return $content;
	}
	$target = fluffless_resolve_page_href( $href, $path );
	if ( false === $target ) {
		return $content;
	}
	if ( null === $target ) {
		return '';
	}
	$tags->set_attribute( 'href', $target );
	return $tags->get_updated_html();
}
add_filter( 'render_block_core/button', 'fluffless_render_page_link', 10, 2 );
add_filter( 'render_block_core/navigation-link', 'fluffless_render_page_link', 10, 2 );

/**
 * Retire un conteneur de boutons dont tous les boutons ont été masqués.
 *
 * @param string               $content Rendu du bloc.
 * @param array<string, mixed> $block   Bloc analysé.
 */
function fluffless_drop_empty_buttons( string $content, array $block ): string {
	if ( empty( $block['innerBlocks'] ) || 1 === preg_match( '/class="[^"]*\bwp-block-button\b[ "]/', $content ) ) {
		return $content;
	}
	return '';
}
add_filter( 'render_block_core/buttons', 'fluffless_drop_empty_buttons', 10, 2 );

/**
 * Relie au rendu les liens en ligne (dans un paragraphe) marqués par `fluffless_page_class()`; un
 * lien à masquer redevient du texte simple.
 *
 * @param string $content Rendu du bloc.
 */
function fluffless_render_inline_page_links( string $content ): string {
	if ( false === strpos( $content, 'fluffless-page-' ) ) {
		return $content;
	}
	return (string) preg_replace_callback(
		'#<a\b[^>]*>.*?</a>#is',
		static function ( array $found ): string {
			$tags = new WP_HTML_Tag_Processor( $found[0] );
			if ( ! $tags->next_tag( array( 'tag_name' => 'A' ) ) ) {
				return $found[0];
			}
			$class = $tags->get_attribute( 'class' );
			$href  = $tags->get_attribute( 'href' );
			$path  = is_string( $class ) ? fluffless_marked_path( $class ) : null;
			if ( null === $path || ! is_string( $href ) ) {
				return $found[0];
			}
			$target = fluffless_resolve_page_href( $href, $path );
			if ( false === $target ) {
				return $found[0];
			}
			if ( null === $target ) {
				return (string) preg_replace( '#^<a\b[^>]*>|</a>$#i', '', $found[0] );
			}
			$tags->set_attribute( 'href', $target );
			return $tags->get_updated_html();
		},
		$content
	);
}
add_filter( 'render_block_core/paragraph', 'fluffless_render_inline_page_links' );

/**
 * Avertit les caches de page complets qu'une page cible vient d'être publiée : les liens qui la
 * visent étaient masqués jusque-là. Les extensions de cache peuvent purger sur cette action.
 *
 * @param string  $new_status Nouveau statut.
 * @param string  $old_status Ancien statut.
 * @param WP_Post $post       Contenu modifié.
 */
function fluffless_announce_published_page( string $new_status, string $old_status, WP_Post $post ): void {
	if ( 'page' === $post->post_type && 'publish' === $new_status && 'publish' !== $old_status ) {
		/**
		 * Une page vient d'être publiée (elle peut être la cible de liens masqués jusque-là).
		 *
		 * @param WP_Post $post La page publiée.
		 */
		do_action( 'fluffless_page_target_published', $post );
	}
}
add_action( 'transition_post_status', 'fluffless_announce_published_page', 10, 3 );

/**
 * Chemins des pages que les motifs du thème visent.
 *
 * @return list<string>
 */
function fluffless_known_page_paths(): array {
	$paths = array( '/services/', '/' . _x( 'about', 'page address', 'fluffless' ) . '/', '/contact/', '/portfolio/' );
	/**
	 * Chemins de pages visés par des liens marqués (pour les signaler dans l'éditeur).
	 *
	 * @param list<string> $paths Chemins.
	 */
	$paths = apply_filters( 'fluffless_known_page_paths', $paths );
	return array_values( array_filter( (array) $paths, 'is_string' ) );
}

/**
 * Dans l'éditeur, signale un lien dont la page n'est pas encore publiée : il restera masqué sur le
 * site. Feuille de style seulement (rien pour le visiteur), le texte vient de PHP.
 */
function fluffless_editor_missing_page_notice(): void {
	if ( ! is_admin() ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen instanceof WP_Screen && ! $screen->is_block_editor() ) {
		return; // Pas d'avis (ni de requêtes) hors des écrans d'édition.
	}
	$css = '';
	foreach ( fluffless_known_page_paths() as $path ) {
		if ( null !== fluffless_published_page( $path ) ) {
			continue;
		}
		/* translators: %s: address of the page that does not exist yet, e.g. contact. */
		$text = sprintf( __( 'This link stays hidden on the site until the page “%s” is published.', 'fluffless' ), trim( $path, '/' ) );
		$sel  = '.' . fluffless_page_class( $path );
		$css .= $sel . '{outline:2px dashed currentColor;outline-offset:3px}' . $sel . '::after{content:"' . addcslashes( wp_strip_all_tags( $text ), "\"\\\n" ) . '";display:block;margin-top:4px;font-size:12px;font-weight:400;line-height:1.3;text-transform:none;letter-spacing:0;white-space:normal}';
	}
	if ( '' !== $css ) {
		wp_register_style( 'fluffless-page-links-editor', false, array(), wp_get_theme()->get( 'Version' ) );
		wp_enqueue_style( 'fluffless-page-links-editor' );
		wp_add_inline_style( 'fluffless-page-links-editor', $css );
	}
}
add_action( 'enqueue_block_assets', 'fluffless_editor_missing_page_notice' );

/**
 * Liens de navigation (balises de bloc) vers les pages du site. Une entrée dont la page n'est pas
 * encore publiée reste dans le menu enregistré, marquée par `fluffless_page_class()` : elle est
 * masquée en public tant que la page n'existe pas et apparaît dès sa publication, sans réédition.
 * L'accueil reste toujours.
 *
 * @param list<array{0: string, 1: string}> $items Paires libellé et chemin.
 */
function fluffless_nav_links( array $items ): string {
	$out = '';
	foreach ( $items as $item ) {
		list( $label, $path ) = $item;
		$front_id             = (int) get_option( 'page_on_front' );
		$page                 = '/' === $path ? ( $front_id > 0 ? get_post( $front_id ) : null ) : fluffless_published_page( $path );
		$attrs                = array(
			'label'          => $label,
			'url'            => home_url( $path ),
			'kind'           => 'custom',
			'isTopLevelLink' => true,
		);
		if ( $page instanceof WP_Post && 'page' === $page->post_type && 'publish' === $page->post_status ) {
			$attrs['url']  = (string) get_permalink( $page );
			$attrs['kind'] = 'post-type';
			$attrs['type'] = 'page';
			$attrs['id']   = $page->ID;
		} elseif ( '/' !== $path ) {
			$attrs['className'] = fluffless_page_class( $path );
		}
		$out .= '<!-- wp:navigation-link ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . " /-->\n";
	}
	return $out;
}

/**
 * Entrées par défaut du menu principal : l'accueil et les trois pages que les motifs visent.
 *
 * @return list<array{0: string, 1: string}> Paires libellé et chemin.
 */
function fluffless_default_menu_links(): array {
	return array(
		array( __( 'Home', 'fluffless' ), '/' ),
		array( __( 'Services', 'fluffless' ), '/services/' ),
		array( __( 'About', 'fluffless' ), '/' . _x( 'about', 'page address', 'fluffless' ) . '/' ),
		array( __( 'Contact', 'fluffless' ), '/contact/' ),
	);
}

/**
 * Liens de navigation des pieds de page : une seule liste pour les pieds du thème et ceux de la
 * Suite, avec les adresses de pages de `fluffless_known_page_paths()`. Sert aussi de repli quand
 * le menu partagé ne peut pas être créé.
 */
function fluffless_footer_nav(): string {
	return fluffless_nav_links( fluffless_default_menu_links() );
}

/** Option qui garde l'identifiant du menu partagé (entité wp_navigation). */
const FLUFFLESS_NAVIGATION_OPTION = 'fluffless_navigation';

/**
 * Identifiant du menu partagé par l'en-tête (ordinateur et téléphone) et les pieds de page : une
 * seule entité wp_navigation, créée avec les entrées par défaut au premier besoin (jamais à
 * l'activation du thème), puis gardée dans une option. Modifier ce menu une fois le change partout.
 * Renvoie 0 si l'entité ne peut pas être créée (les motifs gardent alors leurs liens fixes).
 */
function fluffless_navigation_id(): int {
	$stored = get_option( FLUFFLESS_NAVIGATION_OPTION );
	$id     = is_numeric( $stored ) ? (int) $stored : 0;
	if ( $id > 0 && 'wp_navigation' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
		return $id;
	}
	// Libellés dans la langue du site, pas dans celle de la personne qui active le thème.
	$switched = switch_to_locale( get_locale() );
	$new      = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => __( 'Main menu', 'fluffless' ),
				'post_content' => fluffless_nav_links( fluffless_default_menu_links() ),
			)
		),
		true
	);
	if ( $switched ) {
		restore_previous_locale();
	}
	if ( is_wp_error( $new ) ) {
		return 0;
	}
	if ( false !== $stored ) {
		update_option( FLUFFLESS_NAVIGATION_OPTION, (int) $new, false );
		return (int) $new;
	}
	// Deux requêtes simultanées : la première option enregistrée l'emporte, l'autre entité est retirée.
	if ( ! add_option( FLUFFLESS_NAVIGATION_OPTION, (int) $new, '', false ) ) {
		wp_delete_post( (int) $new, true );
		$winner = get_option( FLUFFLESS_NAVIGATION_OPTION );
		return is_numeric( $winner ) ? (int) $winner : 0;
	}
	return (int) $new;
}

/**
 * Chemin visé par une entrée de menu : page liée par identifiant, lien marqué par
 * `fluffless_page_class()`, sinon chemin de l'adresse sous l'accueil du site. Null s'il n'y en a pas.
 *
 * @param array<string, mixed> $attrs Attributs d'un bloc navigation-link.
 */
function fluffless_navigation_link_path( array $attrs ): ?string {
	$id = (int) ( $attrs['id'] ?? 0 );
	if ( $id > 0 && 'page' === get_post_type( $id ) ) {
		return (int) get_option( 'page_on_front' ) === $id ? '/' : '/' . get_page_uri( $id ) . '/';
	}
	$marked = fluffless_marked_path( is_string( $attrs['className'] ?? null ) ? $attrs['className'] : '' );
	if ( null !== $marked ) {
		return $marked;
	}
	$url  = is_string( $attrs['url'] ?? null ) ? (string) wp_parse_url( $attrs['url'], PHP_URL_PATH ) : '';
	$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	if ( '' === $url || ! str_starts_with( trailingslashit( $url ), $base ) ) {
		return null;
	}
	$rest = trim( substr( trailingslashit( $url ), strlen( $base ) ), '/' );
	return '' === $rest ? '/' : '/' . $rest . '/';
}

/**
 * Libellés par défaut du menu, par chemin, dans la langue courante et dans celle du site (le menu
 * partagé est créé dans la langue du site).
 *
 * @return array<string, list<string>>
 */
function fluffless_default_menu_labels(): array {
	$labels = array();
	foreach ( fluffless_default_menu_links() as $link ) {
		$labels[ $link[1] ][] = $link[0];
	}
	$switched = switch_to_locale( get_locale() );
	foreach ( fluffless_default_menu_links() as $link ) {
		$labels[ $link[1] ][] = $link[0];
	}
	if ( $switched ) {
		restore_previous_locale();
	}
	return $labels;
}

/**
 * Ajoute au menu partagé les pages données, sous leur titre, avant l'entrée Contact (sinon à la
 * fin). Une page déjà au menu (même chemin) garde son entrée, renommée au titre de la page si
 * l'entrée porte encore son libellé par défaut (C1-9-05 : « Contact » mène à « Heures et
 * adresse ») ; un libellé choisi par la cliente reste. Une page en brouillon reste masquée jusqu'à
 * sa publication (voir `fluffless_nav_links()`). Sert à l'import d'une trousse.
 *
 * @param array<mixed> $pages Pages (WP_Post).
 * @return int Nombre d'entrées ajoutées ou renommées.
 */
function fluffless_navigation_add_pages( array $pages ): int {
	$id = fluffless_navigation_id();
	if ( 0 === $id ) {
		return 0;
	}
	$blocks   = parse_blocks( (string) get_post_field( 'post_content', $id ) );
	$paths    = array();
	$contact  = null;
	$defaults = fluffless_default_menu_labels();
	$renamed  = 0;
	foreach ( $blocks as $index => $block ) {
		if ( 'core/navigation-link' !== $block['blockName'] ) {
			continue;
		}
		$path = fluffless_navigation_link_path( $block['attrs'] );
		if ( null !== $path ) {
			$paths[ $path ] = (int) $index;
		}
		if ( '/contact/' === $path && null === $contact ) {
			$contact = (int) $index;
		}
	}
	$new = array();
	foreach ( $pages as $page ) {
		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
			continue;
		}
		$path = '/' . get_page_uri( $page ) . '/';
		if ( isset( $paths[ $path ] ) ) {
			$at    = $paths[ $path ];
			$label = $at >= 0 ? (string) ( $blocks[ $at ]['attrs']['label'] ?? '' ) : '';
			if ( $at >= 0 && '' !== $page->post_title && $label !== $page->post_title && in_array( $label, $defaults[ $path ] ?? array(), true ) ) {
				$blocks[ $at ] = array_merge( $blocks[ $at ], array( 'attrs' => array_merge( $blocks[ $at ]['attrs'], array( 'label' => $page->post_title ) ) ) );
				++$renamed;
			}
			continue;
		}
		$paths[ $path ] = -1;
		$new            = array_merge( $new, parse_blocks( fluffless_nav_links( array( array( $page->post_title, $path ) ) ) ) );
	}
	$added = count( array_filter( $new, static fn( array $block ): bool => 'core/navigation-link' === $block['blockName'] ) );
	if ( 0 === $added + $renamed ) {
		return 0;
	}
	array_splice( $blocks, null === $contact ? count( $blocks ) : $contact, 0, $new );
	// wp_update_post() déséchappe ses données : sans wp_slash, les \u0026 et \u0022 de serialize_blocks() perdent leur barre oblique.
	$saved = wp_update_post(
		wp_slash(
			array(
				'ID'           => $id,
				'post_content' => serialize_blocks( $blocks ),
			)
		),
		true
	);
	return is_wp_error( $saved ) ? 0 : $added + $renamed;
}

/**
 * Bloc Navigation qui référence le menu partagé, avec ses attributs de présentation ; repli sur
 * les liens fixes si le menu partagé n'existe pas.
 *
 * @param array<string, mixed> $attrs Attributs du bloc (libellé, mise en page, taille).
 */
function fluffless_navigation_block( array $attrs ): string {
	$id = fluffless_navigation_id();
	if ( $id > 0 ) {
		return '<!-- wp:navigation ' . wp_json_encode( array( 'ref' => $id ) + $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' /-->';
	}
	return '<!-- wp:navigation ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . " -->\n" . fluffless_footer_nav() . '<!-- /wp:navigation -->';
}

/**
 * Texte d'un fragment HTML ramené à une forme comparable : balises retirées, entités décodées,
 * espaces insécables remplacées, espaces compressées. Sans cela `&#039;`, `&nbsp;` ou l'espace
 * insécable fine du français avant les deux-points feraient croire à un texte modifié.
 *
 * @param string $html Fragment HTML.
 */
function fluffless_comparable_text( string $html ): string {
	$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = str_replace( array( "\u{00A0}", "\u{202F}" ), ' ', $text );
	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Textes d'exemple des motifs (phrase de signature, courriel, téléphone, heures), dans leur forme
 * d'origine et dans la langue du site. Un bloc marqué `fluffless-example` qui porte encore l'un
 * d'eux ne se publie pas.
 *
 * @return list<string>
 */
function fluffless_example_texts(): array {
	$texts = array(
		'Write the sentence you want people to remember as they leave your site.',
		'Your email address',
		'Your phone number',
		'Monday to Friday, 9 a.m. to 5 p.m.',
		__( 'Write the sentence you want people to remember as they leave your site.', 'fluffless' ),
		__( 'Your email address', 'fluffless' ),
		__( 'Your phone number', 'fluffless' ),
		__( 'Monday to Friday, 9 a.m. to 5 p.m.', 'fluffless' ),
	);
	/**
	 * Textes d'exemple reconnus (p. ex. ceux d'un motif ajouté par une extension).
	 *
	 * @param list<string> $texts Textes.
	 */
	$texts = apply_filters( 'fluffless_example_texts', $texts );
	return array_values( array_filter( (array) $texts, 'is_string' ) );
}

/**
 * Au rendu public, un bloc d'exemple marqué `fluffless-example` disparaît tant que son texte est
 * celui d'origine. Le motif reste complet dans le gabarit enregistré : l'éditeur du site le voit et
 * le modifie, et la sérialisation de l'éditeur ne fige rien. Si le texte a changé mais que le lien
 * garde l'adresse d'exemple (courriel `exemple.ca`, faux numéro), le texte se publie sans le lien.
 *
 * @param string               $content Rendu du bloc.
 * @param array<string, mixed> $block   Bloc analysé.
 */
function fluffless_hide_example_content( string $content, array $block ): string {
	$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
	$class = is_string( $attrs['className'] ?? null ) ? $attrs['className'] : '';
	if ( 1 !== preg_match( '/(?:^|\s)fluffless-example(?:\s|$)/', $class ) ) {
		return $content;
	}
	$text = fluffless_comparable_text( $content );
	if ( '' === $text || in_array( $text, array_map( 'fluffless_comparable_text', fluffless_example_texts() ), true ) ) {
		return '';
	}
	return (string) preg_replace_callback(
		'#<a\b[^>]*>(.*?)</a>#is',
		static function ( array $found ): string {
			$tags = new WP_HTML_Tag_Processor( $found[0] );
			$href = $tags->next_tag( array( 'tag_name' => 'A' ) ) ? $tags->get_attribute( 'href' ) : null;
			$fake = is_string( $href ) && 1 === preg_match( '#^(?:mailto:[^@]*@(?:[a-z0-9-]+\.)*exemple\.ca|tel:\+?1?5555550123)#i', trim( $href ) );
			return $fake ? $found[1] : $found[0];
		},
		$content
	);
}
add_filter( 'render_block_core/paragraph', 'fluffless_hide_example_content', 10, 2 );
add_filter( 'render_block_core/button', 'fluffless_hide_example_content', 10, 2 );

/**
 * Un seul H1 par page : le titre de page s'efface quand le contenu a déjà le sien.
 *
 * Les motifs de page ouvrent sur un grand titre de niveau 1, et le gabarit de page en ajoute un
 * second avec le titre de la page. Le titre du gabarit reste quand le contenu n'a pas de H1.
 *
 * @param string               $block_content Rendu du bloc titre de l'article.
 * @param array<string, mixed> $block Bloc analysé.
 * @param WP_Block             $instance      Instance du bloc, avec son contexte.
 */
function fluffless_single_h1( string $block_content, array $block, $instance = null ): string {
	if ( ! str_contains( $block_content, '<h1' ) ) {
		return $block_content;
	}
	$post_id = $instance instanceof WP_Block && isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : (int) get_the_ID();
	$post    = $post_id > 0 ? get_post( $post_id ) : null;
	// Page protégée par mot de passe : le contenu (et son H1) n'est pas rendu, le titre de la page est le seul H1.
	if ( $post instanceof WP_Post && ! post_password_required( $post ) && fluffless_post_has_h1( $post ) ) {
		return '';
	}
	return $block_content;
}

/**
 * Le contenu d'un article porte-t-il son propre H1 ? Lu une fois par requête et par article (le bloc titre
 * peut s'afficher plusieurs fois, en boucle), et sans analyser les blocs quand rien dans le texte ne peut
 * en porter un : ni titre, ni motif synchronisé, ni HTML libre.
 *
 * @param WP_Post $post Article.
 */
function fluffless_post_has_h1( WP_Post $post ): bool {
	static $known = array();

	$key = $post->ID . '|' . $post->post_modified_gmt;

	if ( ! isset( $known[ $key ] ) ) {
		$content       = $post->post_content;
		$known[ $key ] = ( str_contains( $content, 'wp:heading' ) || str_contains( $content, 'wp:block' ) || str_contains( $content, 'wp:html' ) || stripos( $content, '<h1' ) !== false )
			&& fluffless_blocks_have_h1( parse_blocks( $content ) );
	}
	return $known[ $key ];
}

/**
 * Ces blocs portent-ils un titre de niveau 1 ? On lit les blocs, pas le texte brut : un commentaire
 * HTML ou un extrait de code ne compte pas, un motif synchronisé compte.
 *
 * @param array<int|string, array<string, mixed>> $blocks  Blocs analysés.
 * @param array<int, int>                         $visited Motifs synchronisés déjà lus (évite les boucles).
 */
function fluffless_blocks_have_h1( array $blocks, array $visited = array() ): bool {
	foreach ( $blocks as $block ) {
		$name  = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$html  = (string) preg_replace( '/<!--.*?-->/s', '', (string) ( $block['innerHTML'] ?? '' ) );
		if ( 'core/heading' === $name && 1 === (int) ( $attrs['level'] ?? 2 ) ) {
			return true;
		}
		if ( in_array( $name, array( 'core/html', '' ), true ) && 1 === preg_match( '/<h1[\s>]/i', $html ) ) {
			return true;
		}
		if ( 'core/block' === $name ) {
			$ref    = (int) ( $attrs['ref'] ?? 0 );
			$synced = $ref > 0 && ! in_array( $ref, $visited, true ) ? get_post( $ref ) : null;
			if ( $synced instanceof WP_Post && 'wp_block' === $synced->post_type && 'publish' === $synced->post_status
				&& fluffless_blocks_have_h1( parse_blocks( $synced->post_content ), array_merge( $visited, array( $ref ) ) ) ) {
				return true;
			}
		}
		if ( ! empty( $block['innerBlocks'] ) && fluffless_blocks_have_h1( $block['innerBlocks'], $visited ) ) {
			return true;
		}
	}
	return false;
}
add_filter( 'render_block_core/post-title', 'fluffless_single_h1', 10, 3 );
