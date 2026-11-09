<?php
/**
 * Title: Post card
 * Slug: fluffless/fiche-carte
 * Categories: fluffless-structure
 * Keywords: loop, card, query, post, grid
 * Viewport Width: 500
 * Block Types: core/post-template
 * Description: One post as a card: image, category, title and date. Insert it in a Query Loop; with Fluffless Suite, pick it once for a content type and every loop follows.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"className":"fluffless-fiche fluffless-fiche-carte is-style-bordure-brute","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
<div class="wp-block-group fluffless-fiche fluffless-fiche-carte is-style-bordure-brute">
	<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"3/2","className":"is-style-nb-survol"} /-->

	<!-- wp:post-terms {"term":"category","textColor":"accent","fontSize":"small"} /-->

	<!-- wp:post-title {"level":2,"isLink":true,"fontSize":"large"} /-->

	<!-- wp:post-date {"textColor":"muted","fontSize":"small"} /-->
</div>
<!-- /wp:group -->
