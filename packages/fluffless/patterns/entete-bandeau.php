<?php
/**
 * Title: Header with banner
 * Slug: fluffless/entete-bandeau
 * Categories: fluffless-structure
 * Keywords: header, navigation, banner, announcement, promotion
 * Viewport Width: 1400
 * Block Types: core/template-part/header
 * Description: A thin announcement bar above the default header: a promotion, an event, a new service. One paragraph, easy to change or remove.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","className":"fluffless-entete-bandeau__barre","style":{"spacing":{"padding":{"top":"var:preset|spacing|20","right":"var:preset|spacing|50","bottom":"var:preset|spacing|20","left":"var:preset|spacing|50"}}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull fluffless-entete-bandeau__barre has-base-color has-contrast-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:paragraph {"align":"center","fontSize":"small"} -->
	<p class="has-text-align-center has-small-font-size"><?php esc_html_e( 'Announce something here: a promotion, an event, a new service.', 'fluffless' ); ?> <a class="<?php echo esc_attr( fluffless_page_class( '/contact/' ) ); ?>" href="<?php echo esc_url( fluffless_page_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Learn more', 'fluffless' ); ?></a></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"fluffless-entete fluffless-entete-bandeau","style":{"spacing":{"padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|50","bottom":"var:preset|spacing|40","left":"var:preset|spacing|50"}}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull fluffless-entete fluffless-entete-bandeau has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group">
			<!-- wp:site-logo /-->

			<!-- wp:site-title {"level":0,"style":{"typography":{"fontWeight":"900","letterSpacing":"-0.02em"}},"fontSize":"large"} /-->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"right"}} -->
		<div class="wp-block-group">
			<!-- wp:pattern {"slug":"fluffless/menu"} /-->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"} -->
				<div class="wp-block-button is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( fluffless_page_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Contact us', 'fluffless' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
