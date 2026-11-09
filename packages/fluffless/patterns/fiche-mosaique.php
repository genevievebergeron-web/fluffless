<?php
/**
 * Title: Post mosaic tile
 * Slug: fluffless/fiche-mosaique
 * Categories: fluffless-structure
 * Keywords: loop, mosaic, tile, query, portfolio, gallery
 * Viewport Width: 500
 * Block Types: core/post-template
 * Description: One post as a square tile: image and title. Suits portfolios, products and galleries in a tight grid.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"className":"fluffless-fiche fluffless-fiche-mosaique","style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
<div class="wp-block-group fluffless-fiche fluffless-fiche-mosaique">
	<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"1","className":"is-style-nb-survol"} /-->

	<!-- wp:post-title {"level":2,"isLink":true,"fontSize":"base"} /-->
</div>
<!-- /wp:group -->
