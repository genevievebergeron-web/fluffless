<?php
/**
 * Title: About us, adjustable
 * Slug: fluffless/section-qui-nous-sommes
 * Categories: fluffless-raconter
 * Keywords: about, who we are, mission, story, figures, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading, the mission set apart and a short story beside a photo. Switches in the sidebar swap the photo for key figures and remove the mission.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/qui-nous-sommes {"align":"wide"} -->
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Why this organization exists, in one sentence', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"fluffless-qui-nous-sommes__mission"} -->
<p class="fluffless-qui-nous-sommes__mission"><?php esc_html_e( 'The mission in one sentence: who is served and what changes for them.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'The moment it started: a problem seen, people to help. A story beats a mission statement.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Then what the team still refuses to do, years later. That is what sets it apart.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img alt=""/></figure>
<!-- /wp:image -->
<!-- /wp:fluffless/qui-nous-sommes -->
