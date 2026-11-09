<?php
/**
 * Title: Call to action banner, adjustable
 * Slug: fluffless/section-appel-action
 * Categories: fluffless-agir
 * Keywords: call to action, banner, button, contact, booking, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A full-width banner with one invitation and two buttons. Switches in the sidebar set one or two buttons, a plain, accent or image background, centred or left, thin or generous.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/appel-action {"align":"full","className":"is-style-forme-accent fl-accent-shape-half-moon fl-accent-place-bottom-left fl-accent-size-large"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Invite the reader to take one clear step', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Say what happens after the click: how long it takes, whether it is free, and that there is no obligation.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Name the action with a verb', 'fluffless-suite' ); ?></a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Offer a lighter option', 'fluffless-suite' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- /wp:fluffless/appel-action -->
