<?php
/**
 * Title: Values, adjustable
 * Slug: fluffless/section-valeurs
 * Categories: fluffless-presenter
 * Keywords: values, principles, mission, commitments, icons, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading and four values side by side, each with an icon, a name and one line. Switches in the sidebar set three to six values, remove the icons, or show them as a list.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_values = array(
	array( 'heart', __( 'Care', 'fluffless-suite' ), __( 'Give a concrete example of what is done instead of an adjective.', 'fluffless-suite' ) ),
	array( 'eye', __( 'Plain talk', 'fluffless-suite' ), __( 'One line on what this value changes for the people served.', 'fluffless-suite' ) ),
	array( 'handshake', __( 'Rigour', 'fluffless-suite' ), __( 'Keep each value to one or two words so the section reads at a glance.', 'fluffless-suite' ) ),
	array( 'leaf', __( 'Openness', 'fluffless-suite' ), __( 'Three values are often enough: the sidebar removes this one.', 'fluffless-suite' ) ),
);
?>
<!-- wp:fluffless/valeurs {"align":"wide"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'What guides the work', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:group {"layout":{"type":"grid","columnCount":4,"minimumColumnWidth":"12rem"}} -->
<div class="wp-block-group">
<?php foreach ( $fluffless_values as $fluffless_value ) : ?>
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:fluffless/icone {"icon":"<?php echo esc_attr( $fluffless_value[0] ); ?>","textColor":"accent"} /-->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php echo esc_html( $fluffless_value[1] ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo esc_html( $fluffless_value[2] ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<?php endforeach; ?>
</div>
<!-- /wp:group -->
<!-- /wp:fluffless/valeurs -->
