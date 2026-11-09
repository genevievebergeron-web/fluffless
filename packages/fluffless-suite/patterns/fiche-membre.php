<?php
/**
 * Title: Loop card: team member
 * Slug: fluffless/fiche-membre
 * Categories: fluffless-structure
 * Keywords: loop, card, team, member, staff, people
 * Viewport Width: 500
 * Block Types: core/post-template
 * Description: One team member as a card: photo, name and role read from the Role custom field. Pick it for the Team member type in Loop cards and every team loop follows.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"className":"fluffless-fiche fluffless-fiche-membre","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
<div class="wp-block-group fluffless-fiche fluffless-fiche-membre">
	<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"1","className":"is-style-nb-survol"} /-->

	<!-- wp:post-title {"level":3,"isLink":true,"fontSize":"large"} /-->

	<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"fluffless/champ","args":{"key":"poste"}}}},"textColor":"muted","fontSize":"small"} -->
	<p class="has-muted-color has-text-color has-small-font-size"></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
