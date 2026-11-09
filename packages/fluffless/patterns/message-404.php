<?php
/**
 * Title: 404 message
 * Slug: fluffless/message-404
 * Inserter: no
 * Description: Heading and explanation shown on the 404 template.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:heading {"level":1,"align":"wide","className":"is-style-geant"} -->
<h1 class="wp-block-heading alignwide is-style-geant">404</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"wide","fontSize":"medium"} -->
<p class="alignwide has-medium-font-size">
	<?php
	printf(
		wp_kses(
			/* translators: %s: URL of the home page. */
			__( 'This page does not exist or has moved. Try a search, or <a href="%s">go back to the home page</a>.', 'fluffless' ),
			array( 'a' => array( 'href' => array() ) )
		),
		esc_url( home_url( '/' ) )
	);
	?>
</p>
<!-- /wp:paragraph -->
