<?php
/**
 * Title: Post list row
 * Slug: fluffless/fiche-liste
 * Categories: fluffless-structure
 * Keywords: loop, list, query, post, excerpt
 * Viewport Width: 500
 * Block Types: core/post-template
 * Description: One post as a list row: title, date and a short excerpt under a thin rule. No image to load.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"className":"fluffless-fiche fluffless-fiche-liste","style":{"spacing":{"blockGap":"var:preset|spacing|20","padding":{"bottom":"var:preset|spacing|40"}},"border":{"bottom":{"color":"var:preset|color|divider","width":"1px"}}},"layout":{"type":"default"}} -->
<div class="wp-block-group fluffless-fiche fluffless-fiche-liste" style="border-bottom-color:var(--wp--preset--color--divider);border-bottom-width:1px;padding-bottom:var(--wp--preset--spacing--40)">
	<!-- wp:post-title {"level":2,"isLink":true,"fontSize":"large"} /-->

	<!-- wp:post-date {"textColor":"muted","fontSize":"small"} /-->

	<!-- wp:post-excerpt {"excerptLength":30} /-->
</div>
<!-- /wp:group -->
