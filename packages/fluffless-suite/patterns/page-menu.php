<?php
/**
 * Title: Restaurant menu page
 * Slug: fluffless/page-menu
 * Categories: fluffless-pages
 * Keywords: menu, restaurant, dishes, food, prices, café, bistro, full page
 * Viewport Width: 1400
 * Block Types: core/post-content
 * Post Types: page
 * Description: A full Menu page for a place to eat: what the kitchen cooks, the dishes by course with a short description and a price, what to know about allergies and dietary needs, then the hours and how to book.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_courses = array(
	array(
		'name'   => __( 'To start', 'fluffless-suite' ),
		'dishes' => array(
			array( __( 'Name of a starter', 'fluffless-suite' ), __( 'What is in it, in plain words: the main ingredient, how it is cooked, where it comes from.', 'fluffless-suite' ), __( '$12', 'fluffless-suite' ) ),
			array( __( 'Soup of the day', 'fluffless-suite' ), __( 'Say how people find out today’s soup: on the board, from the staff.', 'fluffless-suite' ), __( '$9', 'fluffless-suite' ) ),
		),
	),
	array(
		'name'   => __( 'Mains', 'fluffless-suite' ),
		'dishes' => array(
			array( __( 'Name of a main dish', 'fluffless-suite' ), __( 'The ingredients people look for, and what comes with it.', 'fluffless-suite' ), __( '$26', 'fluffless-suite' ) ),
			array( __( 'Name of a vegetarian dish', 'fluffless-suite' ), __( 'Mark the vegetarian and vegan dishes the same way everywhere on the menu.', 'fluffless-suite' ), __( '$22', 'fluffless-suite' ) ),
			array( __( 'Name of a dish to share', 'fluffless-suite' ), __( 'Say for how many people, if it changes the price.', 'fluffless-suite' ), __( '$48', 'fluffless-suite' ) ),
		),
	),
	array(
		'name'   => __( 'Desserts', 'fluffless-suite' ),
		'dishes' => array(
			array( __( 'Name of a dessert', 'fluffless-suite' ), __( 'One sentence that makes people want it.', 'fluffless-suite' ), __( '$10', 'fluffless-suite' ) ),
			array( __( 'Name of a second dessert', 'fluffless-suite' ), __( 'Keep the descriptions short: one line, the words people look for.', 'fluffless-suite' ), __( '$9', 'fluffless-suite' ) ),
		),
	),
);

$fluffless_contact = function_exists( 'fluffless_page_url' ) ? fluffless_page_url( '/contact/' ) : home_url( '/' );
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:paragraph {"align":"wide","textColor":"accent","fontSize":"small"} -->
	<p class="alignwide has-accent-color has-text-color has-small-font-size"><?php esc_html_e( 'Menu', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"metadata":{"name":"fl:accroche"},"level":1,"align":"wide","className":"is-style-geant"} -->
	<h1 class="wp-block-heading alignwide is-style-geant"><?php esc_html_e( 'Say what the kitchen cooks, in one sentence.', 'fluffless-suite' ); ?></h1>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"metadata":{"name":"fl:principe"},"align":"wide","textColor":"muted","fontSize":"medium"} -->
	<p class="alignwide has-muted-color has-text-color has-medium-font-size"><?php esc_html_e( 'Say how often the menu changes (with the seasons, every week) and whether prices include taxes and service. Keep the menu as text on this page rather than a PDF: it reads on a phone and search engines find the dishes.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<?php foreach ( $fluffless_courses as $fluffless_course ) : ?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|50","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|40"}},"backgroundColor":"base","layout":{"type":"constrained","contentSize":"44rem"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-xx-large-font-size"><?php echo esc_html( $fluffless_course['name'] ); ?></h2>
	<!-- /wp:heading -->

	<?php foreach ( $fluffless_course['dishes'] as $fluffless_dish ) : ?>
	<!-- wp:group {"className":"fl-menu-dish","style":{"spacing":{"blockGap":"var:preset|spacing|20","padding":{"bottom":"var:preset|spacing|30"}},"border":{"bottom":{"color":"var:preset|color|divider","width":"1px"}}},"layout":{"type":"default"}} -->
	<div class="wp-block-group fl-menu-dish" style="border-bottom-color:var(--wp--preset--color--divider);border-bottom-width:1px;padding-bottom:var(--wp--preset--spacing--30)">
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"top"}} -->
		<div class="wp-block-group">
			<!-- wp:heading {"level":3,"fontSize":"large"} -->
			<h3 class="wp-block-heading has-large-font-size"><?php echo esc_html( $fluffless_dish[0] ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700"}},"fontSize":"large"} -->
			<p class="has-large-font-size" style="font-weight:700"><?php echo esc_html( $fluffless_dish[2] ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:paragraph {"textColor":"muted"} -->
		<p class="has-muted-color has-text-color"><?php echo esc_html( $fluffless_dish[1] ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<?php endforeach; ?>
</div>
<!-- /wp:group -->

<?php endforeach; ?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|50","bottom":"var:preset|spacing|70","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"}},"backgroundColor":"surface","layout":{"type":"constrained","contentSize":"44rem"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"x-large"} -->
	<h2 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Allergies and dietary needs', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'Say how people tell you about an allergy (when booking, to the staff) and what the kitchen can or cannot guarantee. List the allergens of each dish, or say that the staff has the list.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|40"}},"backgroundColor":"accent","textColor":"on-accent","layout":{"type":"constrained","contentSize":"40rem"}} -->
<div class="wp-block-group alignfull has-on-accent-color has-accent-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"metadata":{"name":"fl:appel"},"textAlign":"center","fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-text-align-center has-xx-large-font-size"><?php esc_html_e( 'Say how to get a table: booking, walk-in, or both.', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button {"backgroundColor":"base","textColor":"contrast"} -->
		<div class="wp-block-button"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button" href="<?php echo esc_url( $fluffless_contact ); ?>"><?php esc_html_e( 'Hours and address', 'fluffless-suite' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
