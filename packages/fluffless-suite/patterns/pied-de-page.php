<?php
/**
 * Title: Manifesto footer
 * Slug: fluffless/pied-de-page
 * Categories: fluffless-structure
 * Keywords: footer, signature, links, manifesto, dark
 * Viewport Width: 1400
 * Block Types: core/template-part/footer
 * Fluffless Modules: footer
 * Description: A signature sentence in very large type and the site name in poster format, then contact and navigation, trust signals and the legal bar. Each zone reflows when elements are removed.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","className":"is-style-inverse","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|70"}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull is-style-inverse has-base-color has-contrast-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","className":"fluffless-zone-signature is-style-forme-accent fl-accent-shape-disc fl-accent-place-right-edge fl-accent-alpha-10","style":{"spacing":{"blockGap":"var:preset|spacing|60"}},"layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide fluffless-zone-signature is-style-forme-accent fl-accent-shape-disc fl-accent-place-right-edge fl-accent-alpha-10">
		<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"fontSize":"xx-large","className":"fluffless-example"} -->
		<p class="has-xx-large-font-size fluffless-example" style="font-weight:800"><?php esc_html_e( 'Write the sentence you want people to remember as they leave your site.', 'fluffless-suite' ); ?></p>
		<!-- /wp:paragraph -->

		<!-- wp:site-title {"level":0,"className":"is-style-geant"} /-->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"align":"wide","className":"fluffless-zone-navigation-contact","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|70"}}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
	<div class="wp-block-group alignwide fluffless-zone-navigation-contact">
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"vertical"}} -->
		<div class="wp-block-group">
			<!-- wp:fluffless/icone {"icon":"mail","size":"large","textColor":"base"} /-->

			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700"}},"fontSize":"x-large","className":"fluffless-example"} -->
			<p class="has-x-large-font-size fluffless-example" style="font-weight:700"><a href="mailto:bonjour@exemple.ca"><?php esc_html_e( 'Your email address', 'fluffless-suite' ); ?></a></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"className":"fluffless-example"} -->
			<p class="fluffless-example"><a href="tel:+15555550123"><?php esc_html_e( 'Your phone number', 'fluffless-suite' ); ?></a></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"fontSize":"small","className":"fluffless-example"} -->
			<p class="has-small-font-size fluffless-example"><?php esc_html_e( 'Monday to Friday, 9 a.m. to 5 p.m.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<?php
		echo fluffless_suite_navigation_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- balise de bloc dont les attributs sont encodés en JSON.
			array(
				'ariaLabel'   => __( 'Footer menu', 'fluffless-suite' ),
				'overlayMenu' => 'never',
				'layout'      => array(
					'type'        => 'flex',
					'orientation' => 'vertical',
				),
			)
		);
		?>
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"align":"wide","className":"fluffless-zone-confiance","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|30","left":"var:preset|spacing|60"}}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
	<div class="wp-block-group alignwide fluffless-zone-confiance">
		<!-- wp:fluffless/empreinte {"fontSize":"small"} /-->

		<!-- wp:fluffless/badge-hebergement {"fontSize":"small"} /-->

		<!-- wp:fluffless/certifications /-->

		<!-- wp:fluffless/reseaux /-->

		<!-- wp:fluffless/lien-infolettre {"fontSize":"small"} /-->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"align":"wide","className":"fluffless-zone-legale","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|20","left":"var:preset|spacing|50"}}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group alignwide fluffless-zone-legale">
		<!-- wp:fluffless/legal {"fontSize":"small"} /-->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
