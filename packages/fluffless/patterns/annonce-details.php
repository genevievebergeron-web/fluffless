<?php
/**
 * Title: Announcement banner
 * Slug: fluffless/annonce-details
 * Categories: fluffless-structure
 * Keywords: announcement, banner, notice, news, closable
 * Viewport Width: 1400
 * Post Types: page, wp_template, wp_template_part
 * Block Types: core/template-part/header
 * Description: An inverted full-width band with one line of news and a link. Built on the native Details block: it opens by default and folds to its first line with a click or the keyboard, with no script.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","className":"is-style-inverse","style":{"spacing":{"padding":{"top":"var:preset|spacing|20","right":"var:preset|spacing|50","bottom":"var:preset|spacing|20","left":"var:preset|spacing|50"}}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull is-style-inverse has-base-color has-contrast-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:details {"showContent":true,"align":"wide","style":{"typography":{"fontWeight":"700"}},"fontSize":"small"} -->
	<details class="wp-block-details alignwide has-small-font-size" style="font-weight:700" open><summary><?php esc_html_e( 'One line of news worth the top of every page', 'fluffless' ); ?></summary>
		<!-- wp:paragraph {"style":{"typography":{"fontWeight":"400"}}} -->
		<p style="font-weight:400"><?php esc_html_e( 'One sentence with the date or the detail that matters, then the link.', 'fluffless' ); ?> <a class="<?php echo esc_attr( fluffless_page_class( '/contact/' ) ); ?>" href="<?php echo esc_url( fluffless_page_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Read more', 'fluffless' ); ?></a></p>
		<!-- /wp:paragraph -->
	</details>
	<!-- /wp:details -->
</div>
<!-- /wp:group -->
