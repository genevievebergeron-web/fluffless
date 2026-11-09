<?php
/**
 * Title: Pricing page
 * Slug: fluffless/page-tarifs
 * Categories: fluffless-pages
 * Keywords: pricing, prices, plans, rates, fees, packages, full page
 * Viewport Width: 1400
 * Block Types: core/post-content
 * Post Types: page
 * Description: A full Pricing page: what people get for their money, how your prices work, three plans side by side, what every plan includes, the questions people ask about money, then one clear next step.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_plans = array(
	array(
		'name'     => __( 'Starter', 'fluffless-suite' ),
		'price'    => __( '$400', 'fluffless-suite' ),
		'period'   => __( 'per month', 'fluffless-suite' ),
		'for'      => __( 'For one person getting started.', 'fluffless-suite' ),
		'lines'    => array( __( 'The essentials that solve the main problem', 'fluffless-suite' ), __( 'What is left out, said plainly', 'fluffless-suite' ) ),
		'button'   => __( 'Choose Starter', 'fluffless-suite' ),
		'featured' => false,
	),
	array(
		'name'     => __( 'Standard', 'fluffless-suite' ),
		'price'    => __( '$750', 'fluffless-suite' ),
		'period'   => __( 'per month', 'fluffless-suite' ),
		'for'      => __( 'For most clients: say who, in a few words.', 'fluffless-suite' ),
		'lines'    => array( __( 'Everything in Starter', 'fluffless-suite' ), __( 'What this plan adds, one line per thing that matters', 'fluffless-suite' ), __( 'Support included, and for how long', 'fluffless-suite' ) ),
		'button'   => __( 'Choose Standard', 'fluffless-suite' ),
		'featured' => true,
	),
	array(
		'name'     => __( 'Complete', 'fluffless-suite' ),
		'price'    => __( '$1,200', 'fluffless-suite' ),
		'period'   => __( 'per month', 'fluffless-suite' ),
		'for'      => __( 'For larger needs. Two plans are plenty: delete this card if you only need two.', 'fluffless-suite' ),
		'lines'    => array( __( 'Everything in Standard', 'fluffless-suite' ), __( 'What only this plan adds', 'fluffless-suite' ) ),
		'button'   => __( 'Choose Complete', 'fluffless-suite' ),
		'featured' => false,
	),
);

$fluffless_questions = array(
	array( __( 'Are taxes included?', 'fluffless-suite' ), __( 'Say it once, clearly, and use the same wording on every plan.', 'fluffless-suite' ) ),
	array( __( 'Can I change plans along the way?', 'fluffless-suite' ), __( 'Explain how to move up or down, and from when the new price applies.', 'fluffless-suite' ) ),
	array( __( 'How do I pay?', 'fluffless-suite' ), __( 'Card, transfer or cheque, upfront or in instalments: list what you accept.', 'fluffless-suite' ) ),
);
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:paragraph {"align":"wide","textColor":"accent","fontSize":"small"} -->
	<p class="alignwide has-accent-color has-text-color has-small-font-size"><?php esc_html_e( 'Pricing', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"metadata":{"name":"fl:accroche"},"level":1,"align":"wide","className":"is-style-geant"} -->
	<h1 class="wp-block-heading alignwide is-style-geant"><?php esc_html_e( 'Say what people get for their money, in one sentence.', 'fluffless-suite' ); ?></h1>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"metadata":{"name":"fl:principe"},"align":"wide","textColor":"muted","fontSize":"medium"} -->
	<p class="alignwide has-muted-color has-text-color has-medium-font-size"><?php esc_html_e( 'Explain how your prices work: per project, per month or per hour, taxes extra or included, with or without a contract.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"align":"wide","fontSize":"xx-large"} -->
	<h2 class="wp-block-heading alignwide has-xx-large-font-size"><?php esc_html_e( 'Choose your plan', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"16rem"}} -->
	<div class="wp-block-group alignwide">
		<?php
		foreach ( $fluffless_plans as $fluffless_plan ) :
			$fluffless_colors = $fluffless_plan['featured'] ? ',"backgroundColor":"accent","textColor":"on-accent"' : ',"backgroundColor":"surface"';
			$fluffless_class  = $fluffless_plan['featured'] ? 'has-on-accent-color has-accent-background-color has-text-color has-background' : 'has-surface-background-color has-background';
			?>
		<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|50","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|40"}}<?php echo $fluffless_colors; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constante du gabarit. ?>,"layout":{"type":"default"}} -->
		<div class="wp-block-group <?php echo esc_attr( $fluffless_class ); ?>" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--50)">
			<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
			<h3 class="wp-block-heading has-x-large-font-size"><?php echo esc_html( $fluffless_plan['name'] ); ?></h3>
			<!-- /wp:heading -->

			<?php if ( $fluffless_plan['featured'] ) : ?>
			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><?php esc_html_e( 'Recommended', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<?php endif; ?>
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"900","lineHeight":"1"}},"fontSize":"xx-large"} -->
			<p class="has-xx-large-font-size" style="font-weight:900;line-height:1"><?php echo esc_html( $fluffless_plan['price'] ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><?php echo esc_html( $fluffless_plan['period'] ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph -->
			<p><?php echo esc_html( $fluffless_plan['for'] ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:list {"className":"is-style-coches"} -->
			<ul class="wp-block-list is-style-coches">
				<?php foreach ( $fluffless_plan['lines'] as $fluffless_line ) : ?>
				<!-- wp:list-item -->
				<li><?php echo esc_html( $fluffless_line ); ?></li>
				<!-- /wp:list-item -->
				<?php endforeach; ?>
			</ul>
			<!-- /wp:list -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<?php if ( $fluffless_plan['featured'] ) : ?>
				<!-- wp:button {"backgroundColor":"base","textColor":"contrast"} -->
				<div class="wp-block-button"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button" href="#"><?php echo esc_html( $fluffless_plan['button'] ); ?></a></div>
				<!-- /wp:button -->
				<?php else : ?>
				<!-- wp:button {"className":"is-style-outline"} -->
				<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#"><?php echo esc_html( $fluffless_plan['button'] ); ?></a></div>
				<!-- /wp:button -->
				<?php endif; ?>
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
		<?php endforeach; ?>
	</div>
	<!-- /wp:group -->

	<!-- wp:paragraph {"align":"wide","textColor":"muted","fontSize":"small"} -->
	<p class="alignwide has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'Show the real price. No struck-through “regular price” unless that price was actually charged recently.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:quote {"align":"wide"} -->
	<blockquote class="wp-block-quote alignwide">
		<!-- wp:paragraph -->
		<p><?php esc_html_e( 'Paste a real sentence from a person you helped or a client, word for word and with their consent.', 'fluffless-suite' ); ?></p>
		<!-- /wp:paragraph -->
	</blockquote>
	<!-- /wp:quote -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"}}},"backgroundColor":"surface","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-surface-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|60","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column">
			<!-- wp:heading {"fontSize":"x-large"} -->
			<h2 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'In every plan', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"metadata":{"name":"fl:inclus"}} -->
			<p><?php esc_html_e( 'List what every client gets, whatever the plan. It saves repeating it on each card.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column">
			<!-- wp:heading {"fontSize":"x-large"} -->
			<h2 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'If it is not working out', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"metadata":{"name":"fl:engagement"}} -->
			<p><?php esc_html_e( 'Say how to stop, with how much notice, and what people keep. A clear way out makes it easier to say yes.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|40"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Questions about prices', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<?php foreach ( $fluffless_questions as $fluffless_question ) : ?>
	<!-- wp:details {"className":"is-style-bordure-brute"} -->
	<details class="wp-block-details is-style-bordure-brute"><summary><?php echo esc_html( $fluffless_question[0] ); ?></summary>
		<!-- wp:paragraph -->
		<p><?php echo esc_html( $fluffless_question[1] ); ?></p>
		<!-- /wp:paragraph -->
	</details>
	<!-- /wp:details -->

	<?php endforeach; ?>
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|40"}},"backgroundColor":"accent","textColor":"on-accent","layout":{"type":"constrained","contentSize":"40rem"}} -->
<div class="wp-block-group alignfull has-on-accent-color has-accent-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"metadata":{"name":"fl:appel"},"textAlign":"center","fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-text-align-center has-xx-large-font-size"><?php esc_html_e( 'Not sure which plan fits? Say what the next step is.', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button {"backgroundColor":"base","textColor":"contrast"} -->
		<div class="wp-block-button"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button" href="#"><?php esc_html_e( 'Book a free call', 'fluffless-suite' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
