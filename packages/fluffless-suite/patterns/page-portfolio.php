<?php
/**
 * Title: Portfolio page
 * Slug: fluffless/page-portfolio
 * Categories: fluffless-pages
 * Keywords: portfolio, projects, work, gallery, query, filter, full page, style-brut
 * Viewport Width: 1400
 * Block Types: core/post-content
 * Post Types: page
 * Description: A full Portfolio page: a headline, simple filter links by project type (no JavaScript) and a grid of every post in the “portfolio” category, with image, type and title.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_portfolio    = get_category_by_slug( 'portfolio' );
$fluffless_portfolio_id = $fluffless_portfolio instanceof WP_Term ? (int) $fluffless_portfolio->term_id : 0;
// Le type d'un projet est son étiquette (consignes et cartes) : le filtre liste les étiquettes des projets.
$fluffless_projects = $fluffless_portfolio_id ? get_posts(
	array(
		'category'    => $fluffless_portfolio_id,
		'fields'      => 'ids',
		'numberposts' => 100,
	)
) : array();
$fluffless_types    = $fluffless_projects ? get_terms(
	array(
		'taxonomy'   => 'post_tag',
		'object_ids' => $fluffless_projects,
	)
) : array();
$fluffless_types    = is_array( $fluffless_types ) ? $fluffless_types : array();
// Sans catégorie « portfolio », le terme 0 donne une requête vide : le message d'état vide paraît au lieu du blogue entier.
$fluffless_tax_query = sprintf( ',"taxQuery":{"category":[%d]}', $fluffless_portfolio_id );
// Appel final vers Contact, comme page-services.php du thème.
$fluffless_contact_url   = function_exists( 'fluffless_page_url' ) ? fluffless_page_url( '/contact/' ) : '#';
$fluffless_contact_class = function_exists( 'fluffless_page_class' ) ? ' ' . fluffless_page_class( '/contact/' ) : '';
?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"66%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:66%">
			<!-- wp:heading {"level":1,"className":"is-style-geant"} -->
			<h1 class="wp-block-heading is-style-geant"><?php esc_html_e( 'Our work', 'fluffless-suite' ); ?></h1>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"34%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:34%">
			<!-- wp:paragraph {"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'Each project is a post in the “portfolio” category. Its featured image shows up here, and its tag names the type of project.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

<?php if ( $fluffless_types ) : ?>

	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"wrap"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:paragraph {"fontSize":"small"} -->
		<p class="has-small-font-size"><strong><?php esc_html_e( 'Filter:', 'fluffless-suite' ); ?></strong></p>
		<!-- /wp:paragraph -->

		<!-- wp:paragraph {"fontSize":"small"} -->
		<p class="has-small-font-size"><a href="<?php echo esc_url( get_category_link( $fluffless_portfolio_id ) ); ?>"><?php esc_html_e( 'All projects', 'fluffless-suite' ); ?></a></p>
		<!-- /wp:paragraph -->
	<?php foreach ( $fluffless_types as $fluffless_type ) : ?>

		<!-- wp:paragraph {"fontSize":"small"} -->
		<p class="has-small-font-size"><a href="<?php echo esc_url( add_query_arg( 'tag', $fluffless_type->slug, get_category_link( $fluffless_portfolio_id ) ) ); ?>"><?php echo esc_html( $fluffless_type->name ); ?></a></p>
		<!-- /wp:paragraph -->
	<?php endforeach; ?>
	</div>
	<!-- /wp:group -->
<?php endif; ?>
</div>
<!-- /wp:group -->

<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:query {"queryId":21,"query":{"perPage":12,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false<?php echo $fluffless_tax_query; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer-only JSON fragment. ?>},"align":"wide"} -->
	<div class="wp-block-query alignwide">
		<!-- wp:post-template {"style":{"spacing":{"blockGap":"var:preset|spacing|60"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"16rem"}} -->
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group">
				<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"4/5","className":"is-style-nb-survol"} /-->
				<!-- wp:post-terms {"term":"post_tag","textColor":"accent","fontSize":"small"} /-->
				<!-- wp:post-title {"level":2,"isLink":true,"fontSize":"large"} /-->
			</div>
			<!-- /wp:group -->
		<!-- /wp:post-template -->

		<!-- wp:query-no-results -->
			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'No projects yet. Create a “portfolio” category, add one post per project with a featured image and a tag for its type, and it will show up here.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		<!-- /wp:query-no-results -->

		<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"left"}} -->
			<!-- wp:query-pagination-previous /-->
			<!-- wp:query-pagination-numbers /-->
			<!-- wp:query-pagination-next /-->
		<!-- /wp:query-pagination -->
	</div>
	<!-- /wp:query -->
</div>
<!-- /wp:group -->

<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"}}},"backgroundColor":"accent","textColor":"on-accent","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-on-accent-color has-accent-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"68%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:68%">
			<!-- wp:heading {"className":"is-style-geant"} -->
			<h2 class="wp-block-heading is-style-geant"><?php esc_html_e( 'One sentence that makes people want to act today', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"32%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:32%">
			<!-- wp:fluffless/icone {"icon":"arrow-down-right","size":"xl","textColor":"on-accent"} /-->

			<!-- wp:paragraph {"fontSize":"medium"} -->
			<p class="has-medium-font-size"><?php esc_html_e( 'Say what happens after the click: a 20-minute call, a short form, a two-step donation.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"backgroundColor":"base","textColor":"contrast","className":"is-style-fleche<?php echo esc_attr( $fluffless_contact_class ); ?>"} -->
				<div class="wp-block-button is-style-fleche<?php echo esc_attr( $fluffless_contact_class ); ?>"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button" href="<?php echo esc_url( $fluffless_contact_url ); ?>"><?php esc_html_e( 'Your next step', 'fluffless-suite' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
