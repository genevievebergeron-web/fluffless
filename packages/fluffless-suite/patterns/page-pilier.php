<?php
/**
 * Title: Pillar content page
 * Slug: fluffless/page-pilier
 * Categories: fluffless-pages
 * Keywords: pillar page, guide, seo, table of contents, long read, key takeaways, faq, full page, style-editorial
 * Viewport Width: 1400
 * Block Types: core/post-content
 * Post Types: page
 * Description: A long-form guide in magazine style: a standfirst, a table of contents that jumps to each section, five sections to fill in (define, explain, examples, mistakes, tools), key takeaways, a FAQ, related posts and a quiet next step.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|70"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:separator {"backgroundColor":"contrast","className":"is-style-wide"} -->
		<hr class="wp-block-separator has-text-color has-contrast-color has-alpha-channel-opacity has-contrast-background-color has-background is-style-wide"/>
		<!-- /wp:separator -->

		<!-- wp:columns {"style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|70"}}}} -->
		<div class="wp-block-columns">
			<!-- wp:column {"width":"22%","style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
			<div class="wp-block-column" style="flex-basis:22%">
				<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em"}},"textColor":"accent","fontSize":"small"} -->
				<p class="has-accent-color has-text-color has-small-font-size" style="letter-spacing:0.12em;text-transform:uppercase"><?php esc_html_e( 'Section name', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:paragraph {"textColor":"muted","fontSize":"small","fontFamily":"serif"} -->
				<p class="has-muted-color has-text-color has-serif-font-family has-small-font-size"><em><?php esc_html_e( 'Date, or issue number', 'fluffless-suite' ); ?></em></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column {"width":"78%","style":{"spacing":{"blockGap":"var:preset|spacing|50"}}} -->
			<div class="wp-block-column" style="flex-basis:78%">
				<!-- wp:heading {"level":1,"style":{"typography":{"lineHeight":"1.02","fontWeight":"400"}},"fontSize":"xx-large","fontFamily":"didone"} -->
				<h1 class="wp-block-heading has-didone-font-family has-xx-large-font-size" style="font-weight:400;line-height:1.02"><?php esc_html_e( 'A headline that makes a claim, not a label', 'fluffless-suite' ); ?></h1>
				<!-- /wp:heading -->

				<!-- wp:paragraph {"style":{"typography":{"lineHeight":"1.4"}},"fontSize":"x-large","fontFamily":"serif"} -->
				<p class="has-serif-font-family has-x-large-font-size" style="line-height:1.4"><em><?php esc_html_e( 'The standfirst: two sentences that tell readers what they will understand by the end, and why it matters to them now.', 'fluffless-suite' ); ?></em></p>
				<!-- /wp:paragraph -->

				<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
				<p class="has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'By first and last name · 6 min read', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:column -->
		</div>
		<!-- /wp:columns -->
	</div>
	<!-- /wp:group -->

	<!-- wp:image {"align":"wide","aspectRatio":"21/9","scale":"cover","sizeSlug":"full"} -->
	<figure class="wp-block-image alignwide size-full"><img alt="" style="aspect-ratio:21/9;object-fit:cover"/><figcaption class="wp-element-caption"><?php esc_html_e( 'A caption that adds something the photo cannot say. Credit the photographer.', 'fluffless-suite' ); ?></figcaption></figure>
	<!-- /wp:image -->
</div>
<!-- /wp:group -->

<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|70"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"30%","style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column" style="flex-basis:30%">
			<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em"}},"textColor":"accent","fontSize":"small"} -->
			<p class="has-accent-color has-text-color has-small-font-size" style="letter-spacing:0.12em;text-transform:uppercase"><?php esc_html_e( 'In this guide', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:list {"ordered":true,"fontFamily":"serif"} -->
			<ol class="wp-block-list has-serif-font-family"><!-- wp:list-item -->
<li><a href="#definition"><?php esc_html_e( 'What it is', 'fluffless-suite' ); ?></a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><a href="#how-it-works"><?php esc_html_e( 'How it works', 'fluffless-suite' ); ?></a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><a href="#examples"><?php esc_html_e( 'Examples', 'fluffless-suite' ); ?></a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><a href="#common-mistakes"><?php esc_html_e( 'Common mistakes', 'fluffless-suite' ); ?></a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><a href="#tools"><?php esc_html_e( 'Tools and resources', 'fluffless-suite' ); ?></a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><a href="#key-takeaways"><?php esc_html_e( 'Key takeaways', 'fluffless-suite' ); ?></a></li>
<!-- /wp:list-item --></ol>
			<!-- /wp:list -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'If you rename a section, update its link here. Each heading’s anchor is under Advanced, in the block settings.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"70%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:70%">
			<!-- wp:paragraph {"style":{"typography":{"lineHeight":"1.5"}},"fontSize":"large","fontFamily":"serif"} -->
			<p class="has-serif-font-family has-large-font-size" style="line-height:1.5"><?php esc_html_e( 'Answer the main question in the first two sentences, the way you would say it to a client across the table. Search engines and AI answers often quote this very paragraph.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"fontFamily":"serif"} -->
			<p class="has-serif-font-family"><?php esc_html_e( 'Then say who this guide is for and what they’ll be able to do once they’ve read it. Use the words your readers type into a search bar, not your industry jargon.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:separator {"align":"wide","backgroundColor":"contrast","className":"is-style-wide"} -->
	<hr class="wp-block-separator alignwide has-text-color has-contrast-color has-alpha-channel-opacity has-contrast-background-color has-background is-style-wide"/>
	<!-- /wp:separator -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|30","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"22%"} -->
		<div class="wp-block-column" style="flex-basis:22%">
			<!-- wp:paragraph {"textColor":"muted","fontSize":"x-large","fontFamily":"didone"} -->
			<p class="has-muted-color has-text-color has-didone-font-family has-x-large-font-size"><?php esc_html_e( '01', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"78%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:78%">
			<!-- wp:heading {"style":{"typography":{"fontWeight":"400"}},"fontSize":"xx-large","fontFamily":"didone"} -->
			<h2 class="wp-block-heading has-didone-font-family has-xx-large-font-size" id="definition" style="font-weight:400"><?php esc_html_e( 'What it is, in plain words', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontFamily":"serif"} -->
			<p class="has-serif-font-family"><?php esc_html_e( 'Define the term in one sentence a newcomer can repeat. Then add what it is not, because that’s where most confusion comes from.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|30","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"22%"} -->
		<div class="wp-block-column" style="flex-basis:22%">
			<!-- wp:paragraph {"textColor":"muted","fontSize":"x-large","fontFamily":"didone"} -->
			<p class="has-muted-color has-text-color has-didone-font-family has-x-large-font-size"><?php esc_html_e( '02', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"78%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:78%">
			<!-- wp:heading {"style":{"typography":{"fontWeight":"400"}},"fontSize":"xx-large","fontFamily":"didone"} -->
			<h2 class="wp-block-heading has-didone-font-family has-xx-large-font-size" id="how-it-works" style="font-weight:400"><?php esc_html_e( 'How it works', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontFamily":"serif"} -->
			<p class="has-serif-font-family"><?php esc_html_e( 'Explain the mechanism step by step, in the order it happens. One idea per paragraph; if you need a subheading, use a level 3 heading.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|30","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"22%"} -->
		<div class="wp-block-column" style="flex-basis:22%">
			<!-- wp:paragraph {"textColor":"muted","fontSize":"x-large","fontFamily":"didone"} -->
			<p class="has-muted-color has-text-color has-didone-font-family has-x-large-font-size"><?php esc_html_e( '03', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"78%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:78%">
			<!-- wp:heading {"style":{"typography":{"fontWeight":"400"}},"fontSize":"xx-large","fontFamily":"didone"} -->
			<h2 class="wp-block-heading has-didone-font-family has-xx-large-font-size" id="examples" style="font-weight:400"><?php esc_html_e( 'Real examples', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontFamily":"serif"} -->
			<p class="has-serif-font-family"><?php esc_html_e( 'Two or three cases you know first-hand, with names or numbers when you have permission. A real example teaches more than three paragraphs of theory.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|30","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"22%"} -->
		<div class="wp-block-column" style="flex-basis:22%">
			<!-- wp:paragraph {"textColor":"muted","fontSize":"x-large","fontFamily":"didone"} -->
			<p class="has-muted-color has-text-color has-didone-font-family has-x-large-font-size"><?php esc_html_e( '04', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"78%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:78%">
			<!-- wp:heading {"style":{"typography":{"fontWeight":"400"}},"fontSize":"xx-large","fontFamily":"didone"} -->
			<h2 class="wp-block-heading has-didone-font-family has-xx-large-font-size" id="common-mistakes" style="font-weight:400"><?php esc_html_e( 'Common mistakes', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:list {"fontFamily":"serif"} -->
			<ul class="wp-block-list has-serif-font-family"><!-- wp:list-item -->
<li><?php esc_html_e( 'The mistake, then how to avoid it, in one line each', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e( 'The one you made yourself, if you’re willing to say so', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e( 'The costly one that people only notice too late', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item --></ul>
			<!-- /wp:list -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|30","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"22%"} -->
		<div class="wp-block-column" style="flex-basis:22%">
			<!-- wp:paragraph {"textColor":"muted","fontSize":"x-large","fontFamily":"didone"} -->
			<p class="has-muted-color has-text-color has-didone-font-family has-x-large-font-size"><?php esc_html_e( '05', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"78%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:78%">
			<!-- wp:heading {"style":{"typography":{"fontWeight":"400"}},"fontSize":"xx-large","fontFamily":"didone"} -->
			<h2 class="wp-block-heading has-didone-font-family has-xx-large-font-size" id="tools" style="font-weight:400"><?php esc_html_e( 'Tools and resources', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontFamily":"serif"} -->
			<p class="has-serif-font-family"><?php esc_html_e( 'Recommend only what you have actually used, with its price and who it suits. Say it if a link earns you a commission.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|60"},"blockGap":"var:preset|spacing|40"}},"backgroundColor":"surface","layout":{"type":"constrained","contentSize":"48rem","justifyContent":"left"}} -->
	<div class="wp-block-group alignwide has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--60)">
		<!-- wp:heading {"style":{"typography":{"fontWeight":"400"}},"fontSize":"x-large","fontFamily":"didone"} -->
		<h2 class="wp-block-heading has-didone-font-family has-x-large-font-size" id="key-takeaways" style="font-weight:400"><?php esc_html_e( 'Key takeaways', 'fluffless-suite' ); ?></h2>
		<!-- /wp:heading -->

		<!-- wp:list {"className":"is-style-coches","fontFamily":"serif"} -->
		<ul class="wp-block-list is-style-coches has-serif-font-family"><!-- wp:list-item -->
<li><?php esc_html_e( 'Three to five sentences a reader could remember a week later', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e( 'Each one stands on its own, without the rest of the page', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e( 'The last one says what to do first, tomorrow morning', 'fluffless-suite' ); ?></li>
<!-- /wp:list-item --></ul>
		<!-- /wp:list -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"36%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:36%">
			<!-- wp:fluffless/icone {"icon":"circle-question-mark","size":"xl","textColor":"accent"} /-->

			<!-- wp:heading {"fontSize":"xx-large"} -->
			<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'The questions we hear every week', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'Copy the real questions from your inbox, in your clients’ own words. If an answer needs three paragraphs, it deserves its own page.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"64%","style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column" style="flex-basis:64%">
			<!-- wp:details {"className":"is-style-bordure-brute"} -->
			<details class="wp-block-details is-style-bordure-brute"><summary><?php esc_html_e( 'How much does it cost?', 'fluffless-suite' ); ?></summary>
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Give a starting price or a range. People leave when they have to ask for it.', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->
			</details>
			<!-- /wp:details -->

			<!-- wp:details {"className":"is-style-bordure-brute"} -->
			<details class="wp-block-details is-style-bordure-brute"><summary><?php esc_html_e( 'How long does it take?', 'fluffless-suite' ); ?></summary>
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'A realistic timeline, from the first call to the finished work. Mention what can slow things down.', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->
			</details>
			<!-- /wp:details -->

			<!-- wp:details {"className":"is-style-bordure-brute"} -->
			<details class="wp-block-details is-style-bordure-brute"><summary><?php esc_html_e( 'Who is it not for?', 'fluffless-suite' ); ?></summary>
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Saying no clearly saves everyone time, and it makes the right people trust you more.', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->
			</details>
			<!-- /wp:details -->

			<!-- wp:details {"className":"is-style-bordure-brute"} -->
			<details class="wp-block-details is-style-bordure-brute"><summary><?php esc_html_e( 'What happens after I get in touch?', 'fluffless-suite' ); ?></summary>
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Walk through the next three steps. Nobody likes wondering whether their message got lost.', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->
			</details>
			<!-- /wp:details -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:separator {"align":"wide","backgroundColor":"contrast","className":"is-style-wide"} -->
	<hr class="wp-block-separator alignwide has-text-color has-contrast-color has-alpha-channel-opacity has-contrast-background-color has-background is-style-wide"/>
	<!-- /wp:separator -->

	<!-- wp:heading {"align":"wide","style":{"typography":{"fontWeight":"400"}},"fontSize":"xx-large","fontFamily":"didone"} -->
	<h2 class="wp-block-heading alignwide has-didone-font-family has-xx-large-font-size" style="font-weight:400"><?php esc_html_e( 'Keep reading', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:query {"queryId":31,"query":{"perPage":3,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false},"align":"wide"} -->
	<div class="wp-block-query alignwide">
		<!-- wp:post-template {"style":{"spacing":{"blockGap":"var:preset|spacing|60"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"16rem"}} -->
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group">
				<!-- wp:post-terms {"term":"category","textColor":"accent","fontSize":"small"} /-->
				<!-- wp:post-title {"level":3,"isLink":true,"fontSize":"large","fontFamily":"serif"} /-->
				<!-- wp:post-excerpt {"textColor":"muted","excerptLength":24} /-->
			</div>
			<!-- /wp:group -->
		<!-- /wp:post-template -->

		<!-- wp:query-no-results -->
			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Your related posts will show up here. Pick the category that matches this guide in the query settings.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		<!-- /wp:query-no-results -->
	</div>
	<!-- /wp:query -->

	<!-- wp:paragraph {"align":"wide","fontSize":"large","fontFamily":"serif"} -->
	<p class="alignwide has-serif-font-family has-large-font-size"><em><?php esc_html_e( 'Want help putting this into practice?', 'fluffless-suite' ); ?></em> <a href="<?php echo esc_url( function_exists( 'fluffless_page_url' ) ? fluffless_page_url( '/contact/' ) : home_url( '/' ) ); ?>"><?php esc_html_e( 'Tell us where you’re at.', 'fluffless-suite' ); ?></a></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
