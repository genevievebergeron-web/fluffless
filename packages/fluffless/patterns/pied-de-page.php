<?php
/**
 * Title: Manifesto footer
 * Slug: fluffless/pied-de-page
 * Categories: fluffless-structure
 * Keywords: footer, signature, links, manifesto, dark
 * Viewport Width: 1400
 * Block Types: core/template-part/footer
 * Description: A signature sentence in very large type and the site name in poster format, then contact, navigation and the legal line.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

// Lien affiché seulement quand une page de politique de confidentialité est publiée.
$fluffless_privacy_url  = get_privacy_policy_url();
$fluffless_privacy_link = '' === $fluffless_privacy_url ? '' : sprintf( ' · <a href="%s">%s</a>', esc_url( $fluffless_privacy_url ), esc_html__( 'Privacy policy', 'fluffless' ) );
?>
<!-- wp:group {"align":"full","className":"is-style-inverse","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|70"}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull is-style-inverse has-base-color has-contrast-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|60"}},"layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"fontSize":"xx-large","className":"fluffless-example"} -->
		<p class="has-xx-large-font-size fluffless-example" style="font-weight:800"><?php esc_html_e( 'Write the sentence you want people to remember as they leave your site.', 'fluffless' ); ?></p>
		<!-- /wp:paragraph -->

		<!-- wp:site-title {"level":0,"style":{"typography":{"fontWeight":"800"}},"fontSize":"x-large"} /-->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|70"}}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"top"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"vertical"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}},"fontSize":"large","className":"fluffless-example"} -->
			<p class="has-large-font-size fluffless-example" style="font-weight:600"><a href="mailto:bonjour@exemple.ca"><?php esc_html_e( 'Your email address', 'fluffless' ); ?></a></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}},"fontSize":"large","className":"fluffless-example"} -->
			<p class="has-large-font-size fluffless-example" style="font-weight:600"><a href="tel:+15555550123"><?php esc_html_e( 'Your phone number', 'fluffless' ); ?></a></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"fontSize":"medium","className":"fluffless-example"} -->
			<p class="has-medium-font-size fluffless-example"><?php esc_html_e( 'Monday to Friday, 9 a.m. to 5 p.m.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<?php
		echo fluffless_navigation_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- balise de bloc dont les attributs sont encodés en JSON.
			array(
				'ariaLabel'   => __( 'Footer menu', 'fluffless' ),
				'overlayMenu' => 'never',
				'style'       => array( 'spacing' => array( 'blockGap' => 'var:preset|spacing|20' ) ),
				'fontSize'    => 'medium',
				'layout'      => array(
					'type'        => 'flex',
					'orientation' => 'vertical',
				),
			)
		);
		?>
	</div>
	<!-- /wp:group -->

	<!-- wp:paragraph {"align":"wide","fontSize":"small"} -->
	<p class="alignwide has-small-font-size">© <?php echo esc_html( gmdate( 'Y' ) . ' ' . get_bloginfo( 'name' ) ); ?><?php echo wp_kses_post( $fluffless_privacy_link ); ?></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
