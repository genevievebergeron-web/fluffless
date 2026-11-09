<?php
/**
 * Tests du menu partagé (P4-01) : une seule entité wp_navigation, créée au besoin et référencée
 * par ref dans l'en-tête (ordinateur et téléphone) et dans les pieds de page ; pages ajoutées au
 * menu avec leur titre (C1-9-01, C1-9-05).
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/menu-entite-test.php
 * L'option et le contenu du menu sont restaurés à la fin ; les pages et menus créés sont supprimés.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

if ( 'fluffless' !== get_template() ) {
	throw new RuntimeException( 'Thème Fluffless inactif : wp theme activate fluffless.' );
}

switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// Références des blocs Navigation d'un contenu, à toute profondeur.
$fluffless_refs = static function ( string $content ): array {
	$refs = array();
	$walk = static function ( array $blocks ) use ( &$walk, &$refs ): void {
		foreach ( $blocks as $block ) {
			if ( 'core/navigation' === $block['blockName'] ) {
				$refs[] = array( (int) ( $block['attrs']['ref'] ?? 0 ), count( $block['innerBlocks'] ) );
			}
			$walk( $block['innerBlocks'] );
		}
	};
	$walk( parse_blocks( $content ) );
	return $refs;
};

$fluffless_option_before  = get_option( 'fluffless_navigation' );
$fluffless_content_before = null;
$fluffless_created        = array();

try {
	$fluffless_assert( function_exists( 'fluffless_navigation_id' ), 'fluffless_navigation_id() existe.' );
	if ( ! function_exists( 'fluffless_navigation_id' ) ) {
		throw new RuntimeException( 'fluffless_navigation_id() absente.' );
	}

	// 1. Une seule entité, publiée, créée au besoin et réutilisée.
	$fluffless_id             = fluffless_navigation_id();
	$fluffless_content_before = get_post_field( 'post_content', $fluffless_id );
	$fluffless_assert( $fluffless_id > 0 && 'wp_navigation' === get_post_type( $fluffless_id ) && 'publish' === get_post_status( $fluffless_id ), 'Entité wp_navigation publiée.' );
	$fluffless_assert( fluffless_navigation_id() === $fluffless_id && (int) get_option( 'fluffless_navigation' ) === $fluffless_id, 'Même entité au second appel, gardée dans l’option.' );
	$fluffless_links = array_values( array_filter( parse_blocks( (string) $fluffless_content_before ), static fn( array $b ): bool => 'core/navigation-link' === $b['blockName'] ) );
	$fluffless_assert( count( $fluffless_links ) >= 1, 'Le menu contient les liens par défaut.' );

	// 2. En-tête et pieds de page : la même entité, par ref, sans liens fixes.
	$fluffless_registry = WP_Block_Patterns_Registry::get_instance();
	$fluffless_places   = array(
		'fluffless/menu'                 => 2,
		'fluffless/pied-de-page'         => 1,
		'fluffless/pied-de-page-compact' => 1,
	);
	// Pieds de page de la Suite, quand elle est active.
	foreach ( array( 'fluffless/pied-de-page-colonnes', 'fluffless/pied-de-page-editorial' ) as $fluffless_slug ) {
		if ( $fluffless_registry->is_registered( $fluffless_slug ) ) {
			$fluffless_places[ $fluffless_slug ] = 1;
		}
	}
	foreach ( $fluffless_places as $fluffless_slug => $fluffless_count ) {
		$fluffless_pattern = $fluffless_registry->get_registered( $fluffless_slug );
		$fluffless_found   = is_array( $fluffless_pattern ) ? $fluffless_refs( (string) $fluffless_pattern['content'] ) : array();
		$fluffless_assert( array_fill( 0, $fluffless_count, array( $fluffless_id, 0 ) ) === $fluffless_found, $fluffless_slug . ' : ' . $fluffless_count . ' bloc(s) Navigation, ref vers l’entité, sans liens fixes.' );
	}

	// 3. Entité disparue (option vers un identifiant inconnu) : une nouvelle est créée, une seule.
	update_option( 'fluffless_navigation', PHP_INT_MAX, false );
	$fluffless_new = fluffless_navigation_id();
	$fluffless_assert( $fluffless_new > 0 && $fluffless_new !== $fluffless_id && fluffless_navigation_id() === $fluffless_new, 'Entité recréée quand la première a disparu, une seule fois.' );
	$fluffless_created[] = $fluffless_new;

	// 3b. Libellé par défaut avec & et guillemets : intact dans le menu créé.
	$fluffless_gettext = static fn( string $translation, string $text, string $domain ): string => 'fluffless' === $domain && 'Home' === $text ? 'Accueil & "maison"' : $translation;
	add_filter( 'gettext', $fluffless_gettext, 10, 3 );
	update_option( 'fluffless_navigation', PHP_INT_MAX, false );
	$fluffless_amp = fluffless_navigation_id();
	remove_filter( 'gettext', $fluffless_gettext, 10 );
	$fluffless_created[] = $fluffless_amp;
	$fluffless_first     = array_values( array_filter( parse_blocks( (string) get_post_field( 'post_content', $fluffless_amp ) ), static fn( array $b ): bool => 'core/navigation-link' === $b['blockName'] ) );
	$fluffless_assert( 'Accueil & "maison"' === (string) ( $fluffless_first[0]['attrs']['label'] ?? '' ), 'Menu créé : libellé par défaut avec & et guillemets intact.' );
	update_option( 'fluffless_navigation', $fluffless_id, false );

	// 4. Pages ajoutées au menu (C1-9-01) et libellé tiré du titre (C1-9-05).
	$fluffless_assert( function_exists( 'fluffless_navigation_add_pages' ), 'fluffless_navigation_add_pages() existe.' );
	if ( function_exists( 'fluffless_navigation_add_pages' ) ) {
		$fluffless_work      = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Réalisations de test',
				'post_name'   => 'fl-menu-test-realisations',
			)
		);
		$fluffless_created[] = (int) $fluffless_work;
		$fluffless_services  = get_page_by_path( 'services', OBJECT, 'page' );
		$fluffless_svc_made  = false;
		if ( ! $fluffless_services instanceof WP_Post ) {
			$fluffless_services  = get_post(
				wp_insert_post(
					array(
						'post_type'   => 'page',
						'post_status' => 'draft',
						'post_title'  => 'Commandes de test',
						'post_name'   => 'services',
					)
				)
			);
			$fluffless_svc_made  = true;
			$fluffless_created[] = $fluffless_services instanceof WP_Post ? $fluffless_services->ID : 0;
		}
		$fluffless_changed = fluffless_navigation_add_pages( array( get_post( (int) $fluffless_work ), $fluffless_services ) );
		$fluffless_blocks  = parse_blocks( (string) get_post_field( 'post_content', fluffless_navigation_id() ) );
		$fluffless_labels  = array();
		foreach ( $fluffless_blocks as $fluffless_block ) {
			if ( 'core/navigation-link' === $fluffless_block['blockName'] ) {
				$fluffless_labels[] = (string) ( $fluffless_block['attrs']['label'] ?? '' );
			}
		}
		$fluffless_assert( in_array( 'Réalisations de test', $fluffless_labels, true ), 'Page de la trousse ajoutée au menu, avec son titre (C1-9-01).' );
		$fluffless_contact = array_search( 'Contact', $fluffless_labels, true );
		$fluffless_work_at = array_search( 'Réalisations de test', $fluffless_labels, true );
		$fluffless_assert( false === $fluffless_contact || ( false !== $fluffless_work_at && $fluffless_work_at < $fluffless_contact ), 'Ajoutée avant Contact.' );
		if ( $fluffless_svc_made ) {
			$fluffless_assert( in_array( 'Commandes de test', $fluffless_labels, true ) && ! in_array( 'Services', $fluffless_labels, true ), 'Entrée par défaut renommée au titre de la page (C1-9-05).' );
		}
		$fluffless_assert( $fluffless_changed >= 1, 'Nombre d’entrées changées.' );
		$fluffless_assert( 0 === fluffless_navigation_add_pages( array( get_post( (int) $fluffless_work ) ) ), 'Page déjà au menu : rien d’ajouté.' );

		// 5. Esperluette et guillemets : l'ajout d'une page n'abîme ni son libellé ni les entrées de la cliente.
		$fluffless_nav    = fluffless_navigation_id();
		$fluffless_client = array(
			'label'          => 'Prix & forfaits',
			'url'            => 'https://exemple.ca/?a=1&b=2',
			'kind'           => 'custom',
			'isTopLevelLink' => true,
		);
		// Enregistrée comme le fait le cœur (données échappées par wp_slash).
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $fluffless_nav,
					'post_content' => (string) get_post_field( 'post_content', $fluffless_nav ) . '<!-- wp:navigation-link ' . serialize_block_attributes( $fluffless_client ) . ' /-->',
				)
			)
		);
		$fluffless_hours      = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Heures & "adresse"',
				'post_name'   => 'fl-menu-test-heures',
			)
		);
		$fluffless_created[]  = (int) $fluffless_hours;
		$fluffless_hours_post = get_post( (int) $fluffless_hours );
		fluffless_navigation_add_pages( array( $fluffless_hours_post ) );
		$fluffless_entries = array();
		foreach ( parse_blocks( (string) get_post_field( 'post_content', $fluffless_nav ) ) as $fluffless_block ) {
			if ( 'core/navigation-link' === $fluffless_block['blockName'] ) {
				$fluffless_entries[] = array( (string) ( $fluffless_block['attrs']['label'] ?? '' ), (string) ( $fluffless_block['attrs']['url'] ?? '' ) );
			}
		}
		$fluffless_titles = array_column( $fluffless_entries, 0 );
		$fluffless_assert( $fluffless_hours_post instanceof WP_Post && in_array( $fluffless_hours_post->post_title, $fluffless_titles, true ), 'Page au titre avec & et guillemets : libellé intact au menu.' );
		$fluffless_assert( in_array( array( 'Prix & forfaits', 'https://exemple.ca/?a=1&b=2' ), $fluffless_entries, true ), 'Entrée de la cliente intacte (libellé et adresse avec &) après l’ajout d’une page.' );
	}
} finally {
	foreach ( array_unique( array_filter( $fluffless_created ) ) as $fluffless_post_id ) {
		wp_delete_post( $fluffless_post_id, true );
	}
	if ( false === $fluffless_option_before ) {
		if ( isset( $fluffless_id ) && $fluffless_id > 0 ) {
			wp_delete_post( $fluffless_id, true );
		}
		delete_option( 'fluffless_navigation' );
	} else {
		update_option( 'fluffless_navigation', $fluffless_option_before, false );
		if ( is_string( $fluffless_content_before ) && isset( $fluffless_id ) && $fluffless_id > 0 ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $fluffless_id,
						'post_content' => $fluffless_content_before,
					)
				)
			);
		}
	}
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		WP_CLI::warning( $fluffless_failure );
	}
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . ' vérifications.' );
}
WP_CLI::success( 'Menu partagé : ' . $fluffless_checks . ' vérifications passées.' );
