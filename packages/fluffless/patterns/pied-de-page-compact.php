<?php
/**
 * Title: Compact footer
 * Slug: fluffless/pied-de-page-compact
 * Categories: fluffless-structure
 * Keywords: footer, compact, links, contact
 * Viewport Width: 1400
 * Block Types: core/template-part/footer
 * Description: A single band on a tinted background: site name, menu and contact on one line that wraps as needed, then the legal line.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

// Lien affiché seulement quand une page de politique de confidentialité est publiée.
$fluffless_privacy_url  = get_privacy_policy_url();
$fluffless_privacy_link = '' === $fluffless_privacy_url ? '' : sprintf( ' · <a href="%s">%s</a>', esc_url( $fluffless_privacy_url ), esc_html__( 'Privacy policy', 'fluffless' ) );
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|40"}},"backgroundColor":"surface","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-surface-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|60"}}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:site-title {"level":0,"style":{"typography":{"fontWeight":"800"}},"fontSize":"medium"} /-->

		<?php
		echo fluffless_navigation_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- balise de bloc dont les attributs sont encodés en JSON.
			array(
				'ariaLabel'   => __( 'Footer menu', 'fluffless' ),
				'overlayMenu' => 'never',
				'fontSize'    => 'small',
			)
		);
		?>

		<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700"}},"fontSize":"small","className":"fluffless-example"} -->
		<p class="has-small-font-size fluffless-example" style="font-weight:700"><a href="mailto:bonjour@exemple.ca"><?php esc_html_e( 'Your email address', 'fluffless' ); ?></a></p>
		<!-- /wp:paragraph -->

		<!-- wp:paragraph {"fontSize":"small","className":"fluffless-example"} -->
		<p class="has-small-font-size fluffless-example"><a href="tel:+15555550123"><?php esc_html_e( 'Your phone number', 'fluffless' ); ?></a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<!-- wp:paragraph {"align":"wide","textColor":"muted","fontSize":"small"} -->
	<p class="alignwide has-muted-color has-text-color has-small-font-size">© <?php echo esc_html( gmdate( 'Y' ) . ' ' . get_bloginfo( 'name' ) ); ?><?php echo wp_kses_post( $fluffless_privacy_link ); ?></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
