<?php
/**
 * Title: Services, adjustable
 * Slug: fluffless/section-services
 * Categories: fluffless-presenter
 * Keywords: services, offer, cards, grid, icons, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading and three services as cards, each with an icon, a name and one line. Switches in the sidebar set two to six services, two to four columns, remove the icons, add a button to each service, or show them as a list.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_services = array(
	array( 'target', __( 'Your featured service', 'fluffless-suite' ), __( 'What people get, in one or two sentences. Name the result, not the method.', 'fluffless-suite' ) ),
	array( 'pencil', __( 'A second service', 'fluffless-suite' ), __( 'Say who it is for and what changes once it is done.', 'fluffless-suite' ) ),
	array( 'graduation-cap', __( 'A third service', 'fluffless-suite' ), __( 'Three services are often enough: the sidebar adds or removes one.', 'fluffless-suite' ) ),
);
?>
<!-- wp:fluffless/services {"align":"wide"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Three concrete ways we can help', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:group {"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"14rem"}} -->
<div class="wp-block-group">
<?php foreach ( $fluffless_services as $fluffless_service ) : ?>
<!-- wp:group {"className":"fluffless-services__item"} -->
<div class="wp-block-group fluffless-services__item"><!-- wp:fluffless/icone {"icon":"<?php echo esc_attr( $fluffless_service[0] ); ?>","textColor":"accent"} /-->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php echo esc_html( $fluffless_service[1] ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo esc_html( $fluffless_service[2] ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<?php endforeach; ?>
</div>
<!-- /wp:group -->
<!-- /wp:fluffless/services -->
