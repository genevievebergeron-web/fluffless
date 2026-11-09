<?php
/**
 * Title: Editorial numbered list
 * Slug: fluffless/editorial-sommaire
 * Categories: fluffless-presenter
 * Keywords: services, numbered list, table of contents, steps, serif, magazine, editorial, style-editorial
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: A heading in the margin and a numbered list set like a table of contents: large numbers, fine rules, one line per service. A calm alternative to a grid of cards.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"38%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:38%">
			<!-- wp:heading {"style":{"typography":{"fontWeight":"400"}},"fontSize":"xx-large","fontFamily":"serif"} -->
			<h2 class="wp-block-heading has-serif-font-family has-xx-large-font-size" style="font-weight:400"><?php esc_html_e( 'What we offer, in order', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'Number your services from the one people ask for most to the one they discover last. Each line says what they get.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"62%"} -->
		<div class="wp-block-column" style="flex-basis:62%">
			<!-- wp:list {"ordered":true,"className":"is-style-numeros","fontFamily":"serif"} -->
			<ol class="wp-block-list is-style-numeros has-serif-font-family"><!-- wp:list-item -->
<li><strong><?php esc_html_e( 'Your main service.', 'fluffless-suite' ); ?></strong> <?php esc_html_e( 'One sentence on the result, not the method.', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong><?php esc_html_e( 'A second service.', 'fluffless-suite' ); ?></strong> <?php esc_html_e( 'Who it suits, and how long it takes.', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong><?php esc_html_e( 'A third service.', 'fluffless-suite' ); ?></strong> <?php esc_html_e( 'The question it answers for your clients.', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong><?php esc_html_e( 'The follow-up.', 'fluffless-suite' ); ?></strong> <?php esc_html_e( 'What happens once the work is delivered.', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item --></ol>
			<!-- /wp:list -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
