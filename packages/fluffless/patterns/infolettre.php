<?php
/**
 * Title: Newsletter
 * Slug: fluffless/infolettre
 * Categories: fluffless-agir
 * Keywords: newsletter, subscription, email, sign-up
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: An accent-colour band with an honest frequency promise in very large type, plus a raw-bordered box listing what subscribers get, with the sign-up button.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"}}},"backgroundColor":"accent","textColor":"on-accent","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-on-accent-color has-accent-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|60","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"58%","style":{"spacing":{"blockGap":"var:preset|spacing|50"}}} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:58%">
			<!-- wp:heading {"style":{"typography":{"fontWeight":"900"}},"fontSize":"xx-large"} -->
			<h2 class="wp-block-heading has-xx-large-font-size" style="font-weight:900"><?php esc_html_e( 'One letter a month. That’s it.', 'fluffless' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"medium"} -->
			<p class="has-medium-font-size"><?php esc_html_e( 'Promise a frequency you’ll actually keep. One letter a month beats one a week for three weeks.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"42%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:42%">
			<!-- wp:group {"className":"is-style-bordure-brute","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"textColor":"contrast","layout":{"type":"default"}} -->
			<div class="wp-block-group is-style-bordure-brute has-contrast-color has-text-color">
				<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"fontSize":"large"} -->
				<p class="has-large-font-size" style="font-weight:800"><?php esc_html_e( 'In every issue', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:list {"className":"is-style-coches"} -->
				<ul class="wp-block-list is-style-coches"><!-- wp:list-item -->
<li><?php esc_html_e( 'Something readers will learn', 'fluffless' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e( 'News from your field', 'fluffless' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e( 'Just one call to action', 'fluffless' ); ?></li>
<!-- /wp:list-item --></ul>
				<!-- /wp:list -->

				<!-- wp:buttons -->
				<div class="wp-block-buttons">
					<!-- wp:button {"className":"is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"} -->
					<div class="wp-block-button is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( fluffless_page_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Sign me up', 'fluffless' ); ?></a></div>
					<!-- /wp:button -->
				</div>
				<!-- /wp:buttons -->

				<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
				<p class="has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'Unsubscribe in one click. Your address is used for this and nothing else.', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
