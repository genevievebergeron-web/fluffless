<?php
/**
 * Title: Newsletter sign-up
 * Slug: fluffless/formulaire-infolettre
 * Categories: fluffless-agir
 * Keywords: newsletter, subscribe, sign up, email, mailing list, form
 * Viewport Width: 1000
 * Post Types: page
 * Fluffless Modules: form
 * Description: First name, email and consent, nothing else. The site receives the sign-up by email and, with the Newsletter module on, the newsletter tool receives it too. The consent is never pre-checked.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

// serialize_block_attributes() : un nom de site avec guillemets ou barre oblique ne casse pas le bloc.
$fluffless_site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
$fluffless_consent = serialize_block_attributes(
	array(
		'type'     => 'consent',
		'label'    => '' === $fluffless_site
			? __( 'I agree to receive the newsletter by email.', 'fluffless-suite' )
			/* translators: %s: site name */
			: sprintf( __( 'I agree to receive the %s newsletter by email.', 'fluffless-suite' ), $fluffless_site ),
		'name'     => 'consentement',
		'required' => true,
	)
);
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained","contentSize":"720px"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Get the newsletter', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'One email a month at most. Unsubscribe with one click.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:fluffless/formulaire {"confirm":false,"submitLabel":"<?php echo esc_attr__( 'Subscribe', 'fluffless-suite' ); ?>","success":"<?php echo esc_attr__( 'Thank you. If you receive a confirmation email, click its link to finish signing up.', 'fluffless-suite' ); ?>","newsletter":true,"newsletterConsent":"consentement"} -->
		<!-- wp:fluffless/champ {"type":"text","label":"<?php echo esc_attr__( 'First name', 'fluffless-suite' ); ?>","name":"prenom","autocomplete":"given-name"} /-->

		<!-- wp:fluffless/champ {"type":"email","label":"<?php echo esc_attr__( 'Email', 'fluffless-suite' ); ?>","name":"courriel","required":true,"autocomplete":"email"} /-->

		<!-- wp:fluffless/champ <?php echo $fluffless_consent; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON de bloc, échappé par serialize_block_attributes(). ?> /-->
	<!-- /wp:fluffless/formulaire -->
</div>
<!-- /wp:group -->
