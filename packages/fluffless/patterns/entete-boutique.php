<?php
/**
 * Title: Header, shop
 * Slug: fluffless/entete-boutique
 * Categories: fluffless-structure
 * Keywords: header, navigation, shop, cart, search
 * Viewport Width: 1400
 * Block Types: core/template-part/header
 * Description: Site name on the left; menu, a search field and a Cart link on the right. The Cart link goes to the WooCommerce cart when the shop is active, otherwise to /panier/.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<?php
$fluffless_cart_url = function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : home_url( '/panier/' );
?>
<!-- wp:group {"align":"full","className":"fluffless-entete fluffless-entete-boutique","style":{"spacing":{"padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|50","bottom":"var:preset|spacing|40","left":"var:preset|spacing|50"}}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull fluffless-entete fluffless-entete-boutique has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group">
			<!-- wp:site-logo /-->

			<!-- wp:site-title {"level":0,"style":{"typography":{"fontWeight":"900","letterSpacing":"-0.02em"}},"fontSize":"large"} /-->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"right"}} -->
		<div class="wp-block-group">
			<!-- wp:pattern {"slug":"fluffless/menu"} /-->

			<!-- wp:search {"label":"<?php echo esc_attr__( 'Search', 'fluffless' ); ?>","showLabel":false,"placeholder":"<?php echo esc_attr__( 'Search…', 'fluffless' ); ?>","width":260,"widthUnit":"px","buttonText":"<?php echo esc_attr__( 'Search', 'fluffless' ); ?>","buttonPosition":"button-inside","buttonUseIcon":true,"className":"fluffless-entete-boutique__recherche"} /-->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-outline"} -->
				<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $fluffless_cart_url ); ?>"><?php esc_html_e( 'Cart', 'fluffless' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
