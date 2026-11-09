<?php
/**
 * Title: Team, adjustable
 * Slug: fluffless/section-equipe
 * Categories: fluffless-presenter
 * Keywords: team, people, staff, portraits, about, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading, a short text and three people with a round photo, a name, a role and a short bio. Switches in the sidebar set 2, 3 or 4 columns, square photos, and add or remove the role, the bio and a LinkedIn link.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/equipe {"align":"wide"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'The people behind the work', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Why this team, for these clients? One or two sentences. Skip “passionate”: everyone says it.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:group {"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"12rem"}} -->
<div class="wp-block-group">
<?php for ( $fluffless_i = 0; $fluffless_i < 3; $fluffless_i++ ) : ?>
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:image {"sizeSlug":"medium","className":"fluffless-equipe__photo"} -->
<figure class="wp-block-image size-medium fluffless-equipe__photo"><img alt=""/></figure>
<!-- /wp:image -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php esc_html_e( 'First and last name', 'fluffless-suite' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"fluffless-equipe__role"} -->
<p class="fluffless-equipe__role"><?php esc_html_e( 'Role, in plain words', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"fluffless-equipe__bio"} -->
<p class="fluffless-equipe__bio"><?php esc_html_e( 'One thing this person does that nobody else on the team does.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<?php endfor; ?>
</div>
<!-- /wp:group -->
<!-- /wp:fluffless/equipe -->
