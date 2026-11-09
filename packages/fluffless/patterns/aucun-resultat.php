<?php
/**
 * Title: No search results message
 * Slug: fluffless/aucun-resultat
 * Inserter: no
 * Description: Shown by the search template when nothing matches.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:paragraph -->
<p><?php esc_html_e( 'No results. Try other words.', 'fluffless' ); ?></p>
<!-- /wp:paragraph -->
