<?php
/**
 * Title: Countdown, adjustable
 * Slug: fluffless/section-compte-a-rebours
 * Categories: fluffless-agir
 * Keywords: countdown, date, event, launch, deadline, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading, one line and the number of days left before a date, computed by the server on every visit. Set the date in the sidebar; the line shown once it has passed is yours too.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_block = array(
	'align' => 'wide',
	'date'  => wp_date( 'Y-m-d\TH:i', time() + 30 * DAY_IN_SECONDS ),
);
?>
<!-- wp:fluffless/compte-a-rebours <?php echo wp_json_encode( $fluffless_block ); ?> -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Doors open soon', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Say what happens on that date and what to do before: register, order, show up.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->
<!-- /wp:fluffless/compte-a-rebours -->
