<?php
/**
 * Title: Tabs, adjustable
 * Slug: fluffless/section-onglets
 * Categories: fluffless-presenter
 * Keywords: tabs, accordion, panels, toggle, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading and three panels shown one at a time: tabs on wide screens, an accordion on phones. Switches in the sidebar set two to six tabs or keep the accordion everywhere.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_tabs = array(
	array( __( 'For individuals', 'fluffless-suite' ), __( 'Say in a few lines what this audience gets. One idea per tab: people read the one that concerns them.', 'fluffless-suite' ) ),
	array( __( 'For organizations', 'fluffless-suite' ), __( 'Same structure, another audience. Keep the tab names short: they sit side by side.', 'fluffless-suite' ) ),
	array( __( 'For partners', 'fluffless-suite' ), __( 'A tab can hold anything: a list, an image, a button. The core Details block does the work, without any script.', 'fluffless-suite' ) ),
);
?>
<!-- wp:fluffless/onglets -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'One place, three angles', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:group {"className":"fluffless-onglets__list","layout":{"type":"default"}} -->
<div class="wp-block-group fluffless-onglets__list">
<?php foreach ( $fluffless_tabs as $fluffless_i => $fluffless_tab ) : ?>
<!-- wp:details <?php echo 0 === $fluffless_i ? '{"showContent":true}' : '{}'; ?> -->
<details class="wp-block-details"<?php echo 0 === $fluffless_i ? ' open' : ''; ?>><summary><?php echo esc_html( $fluffless_tab[0] ); ?></summary><!-- wp:paragraph -->
<p><?php echo esc_html( $fluffless_tab[1] ); ?></p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->
<?php endforeach; ?>
</div>
<!-- /wp:group -->
<!-- /wp:fluffless/onglets -->
