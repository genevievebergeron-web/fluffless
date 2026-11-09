<?php
/**
 * Title: Editorial footer
 * Slug: fluffless/pied-de-page-editorial
 * Categories: fluffless-structure
 * Keywords: footer, editorial, serif, rules, magazine, style-editorial
 * Viewport Width: 1400
 * Block Types: core/template-part/footer
 * Description: A magazine colophon in serif type: fine rules separate the signature, the contact and menu, the trust signals and the legal notice.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","textColor":"contrast","fontFamily":"serif","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background has-serif-font-family" style="padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:separator {"align":"wide","backgroundColor":"contrast","className":"is-style-wide"} -->
	<hr class="wp-block-separator alignwide has-text-color has-contrast-color has-alpha-channel-opacity has-contrast-background-color has-background is-style-wide"/>
	<!-- /wp:separator -->

	<!-- wp:group {"align":"wide","className":"fluffless-zone-signature","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|30","left":"var:preset|spacing|70"}}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
	<div class="wp-block-group alignwide fluffless-zone-signature">
		<!-- wp:site-title {"level":0,"style":{"typography":{"fontWeight":"400"}},"fontSize":"xx-large"} /-->

		<!-- wp:paragraph {"style":{"typography":{"fontStyle":"italic","lineHeight":"1.3"}},"fontSize":"large","className":"fluffless-example"} -->
		<p class="has-large-font-size fluffless-example" style="font-style:italic;line-height:1.3"><?php esc_html_e( 'Write the sentence you want people to remember as they leave your site.', 'fluffless-suite' ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<!-- wp:separator {"align":"wide","backgroundColor":"divider","className":"is-style-wide"} -->
	<hr class="wp-block-separator alignwide has-text-color has-divider-color has-alpha-channel-opacity has-divider-background-color has-background is-style-wide"/>
	<!-- /wp:separator -->

	<!-- wp:group {"align":"wide","className":"fluffless-zone-navigation-contact","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|80"}}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"top"}} -->
	<div class="wp-block-group alignwide fluffless-zone-navigation-contact">
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"vertical"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em"}},"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size" style="letter-spacing:0.12em;text-transform:uppercase"><?php esc_html_e( 'Write to us', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"style":{"typography":{"fontStyle":"italic"}},"fontSize":"x-large","className":"fluffless-example"} -->
			<p class="has-x-large-font-size fluffless-example" style="font-style:italic"><a href="mailto:bonjour@exemple.ca"><?php esc_html_e( 'Your email address', 'fluffless-suite' ); ?></a></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"className":"fluffless-example"} -->
			<p class="fluffless-example"><a href="tel:+15555550123"><?php esc_html_e( 'Your phone number', 'fluffless-suite' ); ?></a></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"small","className":"fluffless-example"} -->
			<p class="has-muted-color has-text-color has-small-font-size fluffless-example"><?php esc_html_e( 'Monday to Friday, 9 a.m. to 5 p.m.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"vertical"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em"}},"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size" style="letter-spacing:0.12em;text-transform:uppercase"><?php esc_html_e( 'Sections', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

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
	</div>
	<!-- /wp:group -->

	<!-- wp:separator {"align":"wide","backgroundColor":"divider","className":"is-style-wide"} -->
	<hr class="wp-block-separator alignwide has-text-color has-divider-color has-alpha-channel-opacity has-divider-background-color has-background is-style-wide"/>
	<!-- /wp:separator -->

	<!-- wp:group {"align":"wide","className":"fluffless-zone-confiance","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|20","left":"var:preset|spacing|60"}}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
	<div class="wp-block-group alignwide fluffless-zone-confiance">
		<!-- wp:fluffless/empreinte {"fontSize":"small"} /-->

		<!-- wp:fluffless/badge-hebergement {"fontSize":"small"} /-->

		<!-- wp:fluffless/certifications /-->

		<!-- wp:fluffless/reseaux /-->

		<!-- wp:fluffless/lien-infolettre {"fontSize":"small"} /-->
	</div>
	<!-- /wp:group -->

	<!-- wp:separator {"align":"wide","backgroundColor":"divider","className":"is-style-wide"} -->
	<hr class="wp-block-separator alignwide has-text-color has-divider-color has-alpha-channel-opacity has-divider-background-color has-background is-style-wide"/>
	<!-- /wp:separator -->

	<!-- wp:group {"align":"wide","className":"fluffless-zone-legale","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|20","left":"var:preset|spacing|50"}}},"textColor":"muted","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group alignwide fluffless-zone-legale has-muted-color has-text-color">
		<!-- wp:fluffless/legal {"fontSize":"small"} /-->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
