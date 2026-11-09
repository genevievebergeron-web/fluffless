#!/usr/bin/env node
/**
 * Génère la page de référence du banc d'essai à partir d'une seule source de texte :
 * - page.html, header.html, footer.html : balisage de blocs natifs (Fluffless, Ollie) ;
 * - elementor.json : structure _elementor_data (Elementor) ;
 * - content.json : textes partagés (titre du site, menus) lus par seed.php.
 * Sortie : tools/reports/bench-build/ (non versionné).
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const OUT = join( dirname( fileURLToPath( import.meta.url ) ), '..', 'reports', 'bench-build' );

// Même couleur dans les trois variantes pour la bande d'appel à l'action.
const CTA_BACKGROUND = '#1f2a37';
const CTA_TEXT = '#ffffff';
const FIGURE_FONT_SIZE_REM = 3;
const CTA_PADDING_REM = 4;

const CONTENT = {
	siteTitle: 'Northwind Studio',
	nav: [
		[ 'Services', '/#services' ],
		[ 'About', '/#about' ],
		[ 'Contact', '/#contact' ],
	],
	hero: {
		title: 'Websites that load fast and last',
		text: 'We design and build lightweight websites for small organisations. Clear content, accessible pages, and nothing your visitors do not need.',
		button: [ 'Book a call', '/#contact' ],
	},
	cards: {
		anchor: 'services',
		title: 'What we do',
		items: [
			{ title: 'Strategy', text: 'We start with your goals and your visitors, then decide which pages the site really needs.', button: [ 'Learn more', '/#services' ] },
			{ title: 'Design', text: 'A clear visual system, readable type and strong contrast, tested on small screens first.', button: [ 'Learn more', '/#services' ] },
			{ title: 'Development', text: 'Hand-written code on WordPress, measured before launch and easy for your team to edit.', button: [ 'Learn more', '/#services' ] },
		],
	},
	figures: {
		anchor: 'about',
		title: 'In numbers',
		items: [
			[ '120', 'websites delivered' ],
			[ '15', 'years of practice' ],
			[ '3', 'people on the team' ],
		],
	},
	cta: {
		anchor: 'contact',
		title: 'Ready to start?',
		text: 'Tell us about your project. We answer every message within two business days.',
		button: [ 'Get in touch', 'mailto:hello@example.com' ],
	},
	footer: {
		text: '© 2026 Northwind Studio. Montreal, Quebec.',
		links: [
			[ 'Privacy', '/#privacy' ],
			[ 'Accessibility', '/#accessibility' ],
			[ 'Contact', '/#contact' ],
		],
	},
};

const esc = ( s ) => s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );

/* ---------- Blocs natifs ---------- */

const block = ( name, attrs, inner ) => {
	const json = attrs && Object.keys( attrs ).length ? ` ${ JSON.stringify( attrs ) }` : '';
	return `<!-- wp:${ name }${ json } -->\n${ inner }\n<!-- /wp:${ name } -->`;
};
const bHeading = ( level, text ) =>
	block( 'heading', level === 2 ? null : { level }, `<h${ level } class="wp-block-heading">${ esc( text ) }</h${ level }>` );
const bParagraph = ( text ) => block( 'paragraph', null, `<p>${ esc( text ) }</p>` );
const bFigure = ( text ) =>
	block(
		'paragraph',
		{ style: { typography: { fontSize: `${ FIGURE_FONT_SIZE_REM }rem` } } },
		`<p style="font-size:${ FIGURE_FONT_SIZE_REM }rem">${ esc( text ) }</p>`
	);
const bButtons = ( [ label, href ] ) =>
	block(
		'buttons',
		null,
		`<div class="wp-block-buttons">${ block(
			'button',
			null,
			`<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="${ esc( href ) }">${ esc( label ) }</a></div>`
		) }</div>`
	);
const bSection = ( anchor, inner, extra = {} ) => {
	const attrs = { tagName: 'section', anchor, align: 'full', ...extra.attrs, layout: { type: 'constrained' } };
	const cls = [ 'wp-block-group', 'alignfull', ...( extra.classes ?? [] ) ].join( ' ' );
	const style = extra.style ? ` style="${ extra.style }"` : '';
	return block( 'group', attrs, `<section id="${ anchor }" class="${ cls }"${ style }>${ inner.join( '\n' ) }</section>` );
};
const bColumns = ( columns ) =>
	block(
		'columns',
		null,
		`<div class="wp-block-columns">${ columns
			.map( ( inner ) => block( 'column', null, `<div class="wp-block-column">${ inner.join( '\n' ) }</div>` ) )
			.join( '\n' ) }</div>`
	);

const { hero, cards, figures, cta, footer } = CONTENT;

const pageBlocks = [
	bSection( 'top', [ bHeading( 1, hero.title ), bParagraph( hero.text ), bButtons( hero.button ) ] ),
	bSection( cards.anchor, [
		bHeading( 2, cards.title ),
		bColumns( cards.items.map( ( c ) => [ bHeading( 3, c.title ), bParagraph( c.text ), bButtons( c.button ) ] ) ),
	] ),
	bSection( figures.anchor, [
		bHeading( 2, figures.title ),
		bColumns( figures.items.map( ( [ n, caption ] ) => [ bFigure( n ), bParagraph( caption ) ] ) ),
	] ),
	bSection( cta.anchor, [ bHeading( 2, cta.title ), bParagraph( cta.text ), bButtons( cta.button ) ], {
		attrs: {
			style: {
				color: { background: CTA_BACKGROUND, text: CTA_TEXT },
				spacing: { padding: { top: `${ CTA_PADDING_REM }rem`, bottom: `${ CTA_PADDING_REM }rem` } },
			},
		},
		classes: [ 'has-text-color', 'has-background' ],
		style: `color:${ CTA_TEXT };background-color:${ CTA_BACKGROUND };padding-top:${ CTA_PADDING_REM }rem;padding-bottom:${ CTA_PADDING_REM }rem`,
	} ),
].join( '\n\n' );

// __NAV_REF__ est remplacé par l'identifiant du menu wp_navigation dans seed.php.
const headerBlocks = block(
	'group',
	{ align: 'full', layout: { type: 'flex', flexWrap: 'wrap', justifyContent: 'space-between' } },
	`<div class="wp-block-group alignfull"><!-- wp:site-title {"level":0} /-->\n<!-- wp:navigation {"ref":__NAV_REF__,"overlayMenu":"never"} /--></div>`
);

const footerLinks = footer.links.map( ( [ l, h ] ) => `<a href="${ esc( h ) }">${ esc( l ) }</a>` ).join( ' · ' );
const footerBlocks = block(
	'group',
	{ align: 'full', layout: { type: 'constrained' } },
	`<div class="wp-block-group alignfull">${ bParagraph( footer.text ) }\n${ block( 'paragraph', null, `<p>${ footerLinks }</p>` ) }</div>`
);

/* ---------- Elementor ---------- */

let lastId = 0;
const eId = () => ( ++lastId ).toString( 16 ).padStart( 7, '0' );
const eWidget = ( widgetType, settings ) => ( { id: eId(), elType: 'widget', widgetType, settings, elements: [], isInner: false } );
const eContainer = ( settings, elements, isInner = false ) => ( { id: eId(), elType: 'container', settings, elements, isInner } );
const eHeading = ( tag, title, extra = {} ) => eWidget( 'heading', { title, header_size: tag, ...extra } );
const eText = ( text, extra = {} ) => eWidget( 'text-editor', { editor: `<p>${ esc( text ) }</p>`, ...extra } );
const eButton = ( [ text, url ] ) => eWidget( 'button', { text, link: { url, is_external: '', nofollow: '' } } );
const column = { flex_direction: 'column' };
const row = { flex_direction: 'row' };

const elementorPage = [
	eContainer( { ...column, _element_id: 'top' }, [ eHeading( 'h1', hero.title ), eText( hero.text ), eButton( hero.button ) ] ),
	eContainer( { ...column, _element_id: cards.anchor }, [
		eHeading( 'h2', cards.title ),
		eContainer(
			row,
			cards.items.map( ( c ) => eContainer( column, [ eHeading( 'h3', c.title ), eText( c.text ), eButton( c.button ) ], true ) ),
			true
		),
	] ),
	eContainer( { ...column, _element_id: figures.anchor }, [
		eHeading( 'h2', figures.title ),
		eContainer(
			row,
			figures.items.map( ( [ n, caption ] ) =>
				eContainer(
					column,
					[
						eHeading( 'p', n, {
							typography_typography: 'custom',
							typography_font_size: { unit: 'rem', size: FIGURE_FONT_SIZE_REM, sizes: [] },
						} ),
						eText( caption ),
					],
					true
				)
			),
			true
		),
	] ),
	eContainer(
		{
			...column,
			_element_id: cta.anchor,
			// « boxed » (défaut) : fond sur toute la largeur, contenu contraint, comme le groupe natif.
			background_background: 'classic',
			background_color: CTA_BACKGROUND,
			padding: { unit: 'rem', top: String( CTA_PADDING_REM ), right: '0', bottom: String( CTA_PADDING_REM ), left: '0', isLinked: false },
		},
		[
			eHeading( 'h2', cta.title, { title_color: CTA_TEXT } ),
			eText( cta.text, { text_color: CTA_TEXT } ),
			eButton( cta.button ),
		]
	),
];

mkdirSync( OUT, { recursive: true } );
const files = {
	'page.html': pageBlocks,
	'header.html': headerBlocks,
	'footer.html': footerBlocks,
	'elementor.json': JSON.stringify( elementorPage ),
	'content.json': JSON.stringify( { siteTitle: CONTENT.siteTitle, nav: CONTENT.nav, footerLinks: footer.links, footerText: footer.text } ),
};
for ( const [ name, data ] of Object.entries( files ) ) {
	writeFileSync( join( OUT, name ), `${ data }\n` );
}
console.log( `Contenu de référence généré dans ${ OUT } (${ Object.keys( files ).length } fichiers).` );
