<?php
/**
 * Title: Heading, text and list, adjustable
 * Slug: fluffless/section-liste
 * Categories: fluffless-presenter
 * Keywords: list, checklist, bullets, benefits, steps, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading, a short text and a list. Switches in the sidebar choose bullets, check marks or numbers, one or two columns, and add a button.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/liste {"marker":"check"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Name the promise this list keeps', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'One sentence of context: who this list is for and when it helps.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><?php esc_html_e( 'One benefit per line, starting with a verb', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e( 'Keep each line under ten words', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e( 'Put the most convincing point first', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->
<!-- /wp:fluffless/liste -->
