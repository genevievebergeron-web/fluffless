<?php
/**
 * Title: Upcoming events
 * Slug: fluffless/evenements-a-venir
 * Categories: fluffless-presenter
 * Keywords: events, calendar, upcoming, agenda, workshop, conference
 * Viewport Width: 1200
 * Fluffless Modules: events
 * Description: The next three events, soonest first, with date and place, and a link to all events. Past events never show.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_archive = (string) get_post_type_archive_link( 'fluffless_event' );
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Upcoming events', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:query {"queryId":8,"query":{"perPage":3,"pages":1,"offset":0,"postType":"fluffless_event","order":"asc","orderBy":"date","inherit":false},"align":"wide"} -->
	<div class="wp-block-query alignwide">
		<!-- wp:post-template {"style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"16rem"}} -->
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"fluffless/evenement","args":{"key":"when"}}}},"textColor":"accent","style":{"typography":{"fontWeight":"700"}}} -->
				<p class="has-accent-color has-text-color" style="font-weight:700"></p>
				<!-- /wp:paragraph -->

				<!-- wp:post-title {"level":3,"isLink":true,"fontSize":"large"} /-->

				<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"fluffless/evenement","args":{"key":"place"}}}},"textColor":"muted"} -->
				<p class="has-muted-color has-text-color"></p>
				<!-- /wp:paragraph -->

				<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"fluffless/evenement","args":{"key":"status"}}}},"style":{"typography":{"fontWeight":"700"}}} -->
				<p style="font-weight:700"></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		<!-- /wp:post-template -->

		<!-- wp:query-no-results -->
			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'No upcoming events for now.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		<!-- /wp:query-no-results -->
	</div>
	<!-- /wp:query -->

	<?php if ( '' !== $fluffless_archive ) : ?>
	<!-- wp:buttons -->
	<div class="wp-block-buttons">
		<!-- wp:button {"className":"is-style-outline"} -->
		<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $fluffless_archive ); ?>"><?php esc_html_e( 'All events', 'fluffless-suite' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
	<?php endif; ?>
</div>
<!-- /wp:group -->
