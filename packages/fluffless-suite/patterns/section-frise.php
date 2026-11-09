<?php
/**
 * Title: Timeline, adjustable
 * Slug: fluffless/section-frise
 * Categories: fluffless-raconter
 * Keywords: timeline, steps, process, history, milestones, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading and three dated steps on a vertical line. Switches in the sidebar set two to eight steps, lay them side by side on wide screens, or number them.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_steps = array(
	array( __( '2019', 'fluffless-suite' ), __( 'The first project', 'fluffless-suite' ), __( 'One or two sentences: what changed at this step, and for whom.', 'fluffless-suite' ) ),
	array( __( '2022', 'fluffless-suite' ), __( 'A team forms', 'fluffless-suite' ), __( 'A date can be a year, a season or a label like “Today”.', 'fluffless-suite' ) ),
	array( __( '2026', 'fluffless-suite' ), __( 'Where we are now', 'fluffless-suite' ), __( 'End on the present or the next step: a timeline points forward.', 'fluffless-suite' ) ),
);
?>
<!-- wp:fluffless/frise -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'How we got here', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:group {"className":"fluffless-frise__list","layout":{"type":"default"}} -->
<div class="wp-block-group fluffless-frise__list">
<?php foreach ( $fluffless_steps as $fluffless_step ) : ?>
<!-- wp:fluffless/jalon -->
<!-- wp:paragraph {"className":"fluffless-frise__date","textColor":"muted","fontSize":"small"} -->
<p class="fluffless-frise__date has-muted-color has-text-color has-small-font-size"><?php echo esc_html( $fluffless_step[0] ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php echo esc_html( $fluffless_step[1] ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo esc_html( $fluffless_step[2] ); ?></p>
<!-- /wp:paragraph -->
<!-- /wp:fluffless/jalon -->
<?php endforeach; ?>
</div>
<!-- /wp:group -->
<!-- /wp:fluffless/frise -->
