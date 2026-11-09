<?php
/**
 * Tests de la navigation du thème : classes d'ancêtre sur les liens et sous-menus du bloc
 * Navigation, résidu aria-label numérique retiré, cible de 24 px pour l'icône de sous-menu,
 * script Échap chargé seulement quand un <details> l'a demandé.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/navigation-test.php
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( 'fluffless' !== get_template() ) {
	throw new RuntimeException( 'Thème Fluffless inactif : wp theme activate fluffless.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};
$fluffless_created  = array();
$fluffless_terms    = array();

// Lien de menu vers une page ou un terme, comme l'éditeur l'enregistre.
$fluffless_link = static function ( string $kind, string $type, int $id, string $label, string $url, string $inner = '' ): string {
	$attrs = wp_json_encode(
		array(
			'label' => $label,
			'type'  => $type,
			'id'    => $id,
			'url'   => $url,
			'kind'  => $kind,
		),
		JSON_UNESCAPED_SLASHES
	);
	if ( '' === $inner ) {
		return '<!-- wp:navigation-link ' . $attrs . ' /-->';
	}
	return '<!-- wp:navigation-submenu ' . $attrs . ' -->' . $inner . '<!-- /wp:navigation-submenu -->';
};
// Classes du <li> dont le lien porte le libellé donné ('' s'il manque).
$fluffless_item_class = static function ( string $html, string $label ): string {
	$found = preg_match( '/<li\b[^>]*\bclass="([^"]*)"[^>]*>\s*<a [^>]*>[^<]*<span class="wp-block-navigation-item__label">' . preg_quote( $label, '/' ) . '<\/span>/', $html, $m );
	return 1 === $found ? $m[1] : '';
};
$fluffless_nav        = static function ( string $inner, array $attrs = array() ): string {
	$json = array() === $attrs ? '' : ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );
	return do_blocks( '<!-- wp:navigation' . $json . ' -->' . $inner . '<!-- /wp:navigation -->' );
};

// Simule la page ou l'archive affichée (objet interrogé) ; remis à zéro à la fin.
$fluffless_visit = static function ( array $query ): void {
	query_posts( $query ); // phpcs:ignore WordPress.WP.DiscouragedFunctions.query_posts_query_posts -- l'objet interrogé global est ce que le cœur et le thème lisent.
};
$fluffless_page  = static function ( string $title, int $parent_id = 0 ) use ( &$fluffless_created ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_parent' => $parent_id,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( esc_html( $id->get_error_message() ) );
	}
	$fluffless_created[] = $id;
	return $id;
};

try {
	// Pages : grand-parent > parent > enfant ; une page sans lien.
	$fluffless_gp     = $fluffless_page( 'Nav grand-parent' );
	$fluffless_parent = $fluffless_page( 'Nav parent', $fluffless_gp );
	$fluffless_child  = $fluffless_page( 'Nav enfant', $fluffless_parent );
	$fluffless_other  = $fluffless_page( 'Nav autre' );
	$fluffless_menu   = $fluffless_link( 'post-type', 'page', $fluffless_gp, 'Grand-parent', (string) get_permalink( $fluffless_gp ) )
		. $fluffless_link( 'post-type', 'page', $fluffless_parent, 'Parent', (string) get_permalink( $fluffless_parent ) )
		. $fluffless_link( 'post-type', 'page', $fluffless_other, 'Autre', (string) get_permalink( $fluffless_other ) );

	// 1. Page enfant non listée : ses ancêtres listés reçoivent current-menu-ancestor (et
	// current-page-ancestor), pas aria-current ; la page sans lien de parenté n'a rien.
	$fluffless_visit( array( 'page_id' => $fluffless_child ) );
	$fluffless_html = $fluffless_nav( $fluffless_menu, array( 'ariaLabel' => 'Principal' ) );
	$fluffless_assert( str_contains( $fluffless_item_class( $fluffless_html, 'Parent' ), 'current-menu-ancestor' ), 'enfant : parent sans current-menu-ancestor' );
	$fluffless_assert( str_contains( $fluffless_item_class( $fluffless_html, 'Parent' ), 'current-page-ancestor' ), 'enfant : parent sans current-page-ancestor' );
	$fluffless_assert( str_contains( $fluffless_item_class( $fluffless_html, 'Grand-parent' ), 'current-menu-ancestor' ), 'enfant : grand-parent sans current-menu-ancestor' );
	$fluffless_assert( ! str_contains( $fluffless_item_class( $fluffless_html, 'Autre' ), 'ancestor' ), 'enfant : page étrangère marquée' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'current-menu-item' ) && ! str_contains( $fluffless_html, 'aria-current' ), 'enfant : page courante inventée' );

	// 2. Page listée : le cœur pose current-menu-item ; pas de current-menu-ancestor sur elle-même.
	$fluffless_visit( array( 'page_id' => $fluffless_parent ) );
	$fluffless_html = $fluffless_nav( $fluffless_menu, array( 'ariaLabel' => 'Principal' ) );
	$fluffless_assert( str_contains( $fluffless_item_class( $fluffless_html, 'Parent' ), 'current-menu-item' ), 'parent : current-menu-item du cœur perdu' );
	$fluffless_assert( ! str_contains( $fluffless_item_class( $fluffless_html, 'Parent' ), 'current-menu-ancestor' ), 'parent : ancêtre de lui-même' );
	$fluffless_assert( str_contains( $fluffless_item_class( $fluffless_html, 'Grand-parent' ), 'current-menu-ancestor' ), 'parent : grand-parent sans classe' );

	// 3. Page sans parent : aucune classe d'ancêtre nulle part.
	$fluffless_visit( array( 'page_id' => $fluffless_other ) );
	$fluffless_html = $fluffless_nav( $fluffless_menu, array( 'ariaLabel' => 'Principal' ) );
	$fluffless_assert( ! str_contains( $fluffless_html, 'ancestor' ), 'page sans parent : classe d’ancêtre' );

	// 4. Sous-menu : un lien enfant marqué ancêtre remonte la classe sur le <li> du sous-menu,
	// comme le cœur le fait pour current-menu-item.
	$fluffless_visit( array( 'page_id' => $fluffless_child ) );
	$fluffless_sub  = $fluffless_link( 'post-type', 'page', $fluffless_other, 'Autre', (string) get_permalink( $fluffless_other ), $fluffless_link( 'post-type', 'page', $fluffless_parent, 'Parent', (string) get_permalink( $fluffless_parent ) ) );
	$fluffless_html = $fluffless_nav( $fluffless_sub, array( 'ariaLabel' => 'Principal' ) );
	$fluffless_assert( str_contains( $fluffless_item_class( $fluffless_html, 'Autre' ), 'current-menu-ancestor' ), 'sous-menu : classe non remontée' );
	$fluffless_assert( str_contains( $fluffless_item_class( $fluffless_html, 'Parent' ), 'current-menu-ancestor' ), 'sous-menu : lien intérieur sans classe' );

	// 5. Taxonomie : archive d'une sous-catégorie, le lien de la catégorie mère est ancêtre.
	$fluffless_cat_parent = wp_insert_term( 'Nav cat mère', 'category' );
	if ( is_wp_error( $fluffless_cat_parent ) ) {
		throw new RuntimeException( 'Catégorie mère de test impossible à créer.' );
	}
	$fluffless_cat_parent_id = max( 0, (int) $fluffless_cat_parent['term_id'] );
	$fluffless_cat_child     = wp_insert_term( 'Nav cat fille', 'category', array( 'parent' => $fluffless_cat_parent_id ) );
	if ( is_wp_error( $fluffless_cat_child ) ) {
		$fluffless_terms = array( $fluffless_cat_parent_id );
		throw new RuntimeException( 'Catégorie fille de test impossible à créer.' );
	}
	$fluffless_cat_child_id = (int) $fluffless_cat_child['term_id'];
	$fluffless_terms        = array( $fluffless_cat_child_id, $fluffless_cat_parent_id );
	$fluffless_term_url     = get_term_link( $fluffless_cat_parent_id );
	$fluffless_visit( array( 'cat' => $fluffless_cat_child_id ) );
	$fluffless_assert( is_category( $fluffless_cat_child_id ), 'archive de catégorie non simulée' );
	$fluffless_html = $fluffless_nav( $fluffless_link( 'taxonomy', 'category', $fluffless_cat_parent_id, 'Mère', is_string( $fluffless_term_url ) ? $fluffless_term_url : '' ), array( 'ariaLabel' => 'Principal' ) );
	$fluffless_assert( str_contains( $fluffless_item_class( $fluffless_html, 'Mère' ), 'current-menu-ancestor' ), 'taxonomie : catégorie mère sans classe' );
	$fluffless_assert( ! str_contains( $fluffless_item_class( $fluffless_html, 'Mère' ), 'current-page-ancestor' ), 'taxonomie : current-page-ancestor sur un terme' );

	// 6. Résidu aria-label : deux blocs sans nom, le second devient " 2" chez le cœur ; retiré.
	// Un nom suivi d'un compteur est gardé.
	$fluffless_visit( array( 'page_id' => $fluffless_other ) );
	$fluffless_nav( $fluffless_menu );
	$fluffless_html = $fluffless_nav( $fluffless_menu );
	$fluffless_assert( ! str_contains( $fluffless_html, 'aria-label=" 2"' ) && ! preg_match( '/<nav[^>]*aria-label="\s*\d+"/', $fluffless_html ), 'résidu aria-label numérique gardé : ' . substr( $fluffless_html, 0, 200 ) );
	$fluffless_html = $fluffless_nav( $fluffless_menu, array( 'ariaLabel' => 'Pied' ) ) . $fluffless_nav( $fluffless_menu, array( 'ariaLabel' => 'Pied' ) );
	$fluffless_assert( str_contains( $fluffless_html, 'aria-label="Pied 2"' ), 'nom suivi d’un compteur retiré' );
	$fluffless_stripped = new WP_HTML_Tag_Processor( fluffless_navigation_strip_numeric_label( '<nav aria-label=" 2"><nav aria-label=" 3">', array() ) );
	$fluffless_stripped->next_tag( array( 'tag_name' => 'NAV' ) );
	$fluffless_first = $fluffless_stripped->get_attribute( 'aria-label' );
	$fluffless_stripped->next_tag( array( 'tag_name' => 'NAV' ) );
	$fluffless_assert( null === $fluffless_first && ' 3' === $fluffless_stripped->get_attribute( 'aria-label' ), 'seul le premier nav est touché' );
	$fluffless_assert( '<p>x</p>' === fluffless_navigation_strip_numeric_label( '<p>x</p>', array() ), 'rendu sans nav modifié' );

	// 7. Icône de sous-menu : le cœur rend toujours le bouton .wp-block-navigation__submenu-icon
	// (sinon la règle de 24 px ne sert à rien) ; style.css porte la règle.
	$fluffless_html = $fluffless_nav(
		$fluffless_sub,
		array(
			'ariaLabel'           => 'Principal',
			'showSubmenuIcon'     => true,
			'openSubmenusOnClick' => false,
		)
	);
	$fluffless_assert( 1 === preg_match( '/<(button|span)\b[^>]*\bclass="[^"]*\bwp-block-navigation__submenu-icon\b/', $fluffless_html ), 'icône de sous-menu du cœur absente ou renommée' );
	$fluffless_css  = (string) file_get_contents( get_template_directory() . '/style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_pos  = strpos( $fluffless_css, '.wp-block-navigation .wp-block-navigation__submenu-icon {' );
	$fluffless_rule = false === $fluffless_pos ? '' : substr( $fluffless_css, $fluffless_pos, (int) strpos( $fluffless_css, '}', $fluffless_pos ) - $fluffless_pos );
	$fluffless_assert( str_contains( $fluffless_rule, 'min-width: 24px' ) && str_contains( $fluffless_rule, 'min-height: 24px' ) && str_contains( $fluffless_rule, 'margin-left: 0' ), 'style.css : règle de 24 px pour l’icône de sous-menu' );
	$fluffless_assert( str_contains( $fluffless_css, '.wp-block-navigation .wp-block-navigation__submenu-icon svg {' ), 'style.css : taille du svg de l’icône' );
	$fluffless_assert( str_contains( $fluffless_css, '.current-menu-ancestor > .wp-block-navigation-item__content' ), 'style.css : règle pour l’ancêtre' );

	// 8. Échap : le <details> du menu mobile reçoit data-fluffless-esc et enfile le script ; un
	// <details> ordinaire ne fait rien. Le script est court et sans dépendance.
	wp_dequeue_script( 'fluffless-nav-escape' );
	$fluffless_html = do_blocks( '<!-- wp:details --><details class="wp-block-details"><summary>FAQ</summary><p>r</p></details><!-- /wp:details -->' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'data-fluffless-esc' ), 'details ordinaire : attribut posé' );
	$fluffless_assert( ! wp_script_is( 'fluffless-nav-escape', 'enqueued' ), 'details ordinaire : script enfilé' );
	$fluffless_html = do_blocks( '<!-- wp:details {"className":"fluffless-nav-mobile"} --><details class="wp-block-details fluffless-nav-mobile"><summary>Menu</summary><p>r</p></details><!-- /wp:details -->' );
	$fluffless_assert( 1 === preg_match( '/<details[^>]* data-fluffless-esc[\s>]/', $fluffless_html ), 'menu mobile : attribut data-fluffless-esc absent' );
	$fluffless_assert( wp_script_is( 'fluffless-nav-escape', 'enqueued' ), 'menu mobile : script non enfilé' );
	$fluffless_assert( 'defer' === wp_scripts()->get_data( 'fluffless-nav-escape', 'strategy' ), 'script Échap sans defer' );
	$fluffless_assert( array() === ( wp_scripts()->registered['fluffless-nav-escape']->deps ?? array( 'x' ) ), 'script Échap avec dépendance' );
	$fluffless_js = (string) file_get_contents( get_template_directory() . '/assets/js/nav-escape.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( strlen( $fluffless_js ) < 1500 && str_contains( $fluffless_js, 'defaultPrevented' ) && str_contains( $fluffless_js, 'data-fluffless-esc' ), 'script Échap : taille ou garde' );
	// L'action publique sert à la Suite (panneau de menu) ou à un enfant qui pose l'attribut lui-même.
	wp_dequeue_script( 'fluffless-nav-escape' );
	do_action( 'fluffless_nav_escape' );
	$fluffless_assert( wp_script_is( 'fluffless-nav-escape', 'enqueued' ), 'action fluffless_nav_escape sans effet' );
} finally {
	wp_reset_query(); // phpcs:ignore WordPress.WP.DiscouragedFunctions.wp_reset_query_wp_reset_query -- query_posts() dans le test.
	foreach ( $fluffless_created as $fluffless_id ) {
		wp_delete_post( $fluffless_id, true );
	}
	foreach ( $fluffless_terms as $fluffless_term_id ) {
		wp_delete_term( $fluffless_term_id, 'category' );
	}
}

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( 'navigation-test : ' . count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} :\n - " . implode( "\n - ", $fluffless_failures ) );
}
WP_CLI::success( "navigation-test : {$fluffless_checks} vérifications passées." );
