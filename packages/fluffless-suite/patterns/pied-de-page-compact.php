<?php
/**
 * Title: Compact footer
 * Slug: fluffless/pied-de-page-compact
 * Categories: fluffless-structure
 * Keywords: footer, compact, band, minimal, links
 * Viewport Width: 1400
 * Block Types: core/template-part/footer
 * Fluffless Modules: footer
 * Description: A single band on a tinted background: site name, menu and contact, trust signals and legal links flow on one line and wrap as needed.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"}}},"backgroundColor":"surface","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-surface-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|60"}}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"className":"fluffless-zone-signature","layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group fluffless-zone-signature">
			<!-- wp:site-title {"level":0,"style":{"typography":{"fontWeight":"800"}},"fontSize":"medium"} /-->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"fluffless-zone-navigation-contact","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|20","left":"var:preset|spacing|50"}}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group fluffless-zone-navigation-contact">
			<?php
			echo fluffless_suite_navigation_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- balise de bloc dont les attributs sont encodés en JSON.
				array(
					'ariaLabel'   => __( 'Footer menu', 'fluffless-suite' ),
					'overlayMenu' => 'never',
					'fontSize'    => 'small',
					'layout'      => array(
						'type'     => 'flex',
						'flexWrap' => 'wrap',
					),
				)
			);
			?>

			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700"}},"fontSize":"small","className":"fluffless-example"} -->
			<p class="has-small-font-size fluffless-example" style="font-weight:700"><a href="mailto:bonjour@exemple.ca"><?php esc_html_e( 'Your email address', 'fluffless-suite' ); ?></a></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"fontSize":"small","className":"fluffless-example"} -->
			<p class="has-small-font-size fluffless-example"><a href="tel:+15555550123"><?php esc_html_e( 'Your phone number', 'fluffless-suite' ); ?></a></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"small","className":"fluffless-example"} -->
			<p class="has-muted-color has-text-color has-small-font-size fluffless-example"><?php esc_html_e( 'Monday to Friday, 9 a.m. to 5 p.m.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"fluffless-zone-confiance","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|20","left":"var:preset|spacing|50"}}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group fluffless-zone-confiance">
			<!-- wp:fluffless/empreinte {"fontSize":"small"} /-->

			<!-- wp:fluffless/badge-hebergement {"fontSize":"small"} /-->

			<!-- wp:fluffless/certifications /-->

			<!-- wp:fluffless/reseaux /-->

			<!-- wp:fluffless/lien-infolettre {"fontSize":"small"} /-->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"fluffless-zone-legale","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|20","left":"var:preset|spacing|40"}}},"textColor":"muted","layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group fluffless-zone-legale has-muted-color has-text-color">
			<!-- wp:fluffless/legal {"fontSize":"small"} /-->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
