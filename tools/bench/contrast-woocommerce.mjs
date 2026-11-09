/**
 * Contraste réel des pages WooCommerce, mesuré dans le navigateur : chaque texte visible de la
 * boutique, d'une fiche produit en promotion, du panier, de la caisse (erreurs de champ
 * déclenchées par un envoi vide), de la confirmation d'une commande d'essai et de la connexion
 * au compte, plus le texte saisi et la bordure de chaque champ (3:1). Couleur du texte composée
 * avec l'opacité de ses ancêtres ; fond lu sous le texte, si bien qu'une étiquette flottante
 * prend celui de son champ. Seuils WCAG 2.2 AA : 4,5:1, ou 3:1 pour le gros texte (24 px, ou
 * 18,66 px en gras). Les textes posés sur une image ou recouverts ne sont pas jugés ; le script
 * en donne le compte (--details les nomme). Un composant désactivé est exempté, comme dans
 * WCAG. La caisse est mesurée note de commande et coupon ouverts, texte indicatif compris ; à
 * la caisse et à la connexion, un tour au clavier juge le focus de chaque champ (3:1).
 * Une palette par passe : appliquer la variation de style voulue avant chaque passe.
 *
 * Usage : node tools/bench/contrast-woocommerce.mjs --base http://127.0.0.1:8896 --add 12 [--product tasse-gres] [--width 1280] [--label nom] [--details]
 * Prérequis : WooCommerce actif, tools/bench/seed-woocommerce.php, un produit en promotion,
 * paiement à la livraison (cod) actif.
 * Instance isolée seulement : le script remplit un panier et passe une commande d'essai.
 */
import { parseArgs } from 'node:util';
import { chromium } from 'playwright-core';

const { values: opts } = parseArgs( {
	options: {
		base: { type: 'string' },
		product: { type: 'string', default: 'tasse-gres' },
		add: { type: 'string', default: '' },
		label: { type: 'string', default: '' },
		width: { type: 'string', default: '1280' },
		details: { type: 'boolean', default: false },
	},
} );
if ( ! opts.base || ! opts.add ) {
	console.error( 'Usage : node tools/bench/contrast-woocommerce.mjs --base URL --add id [--product slug] [--width px] [--label nom]' );
	process.exit( 2 );
}
const base = opts.base.replace( /\/$/, '' );

// Mesure dans la page : liste des textes sous le seuil, plus le compte des textes jugés.
function sweep() {
	// rgb(), rgba() et color(srgb r g b / a), que rend color-mix().
	const parse = ( c ) => {
		const m = c.match( /rgba?\(([^)]+)\)/ ) ?? c.match( /color\(srgb\s+([^)]+)\)/ );
		if ( ! m ) {
			return null;
		}
		const p = m[ 1 ].split( /[\s,/]+/ ).filter( Boolean ).map( Number );
		const scale = c.startsWith( 'color(' ) ? 255 : 1;
		return [ p[ 0 ] * scale, p[ 1 ] * scale, p[ 2 ] * scale, p.length > 3 ? p[ 3 ] : 1 ];
	};
	const lum = ( [ r, g, b ] ) => {
		const f = ( v ) => {
			v /= 255;
			return v <= 0.03928 ? v / 12.92 : ( ( v + 0.055 ) / 1.055 ) ** 2.4;
		};
		return 0.2126 * f( r ) + 0.7152 * f( g ) + 0.0722 * f( b );
	};
	const over = ( top, under ) => top.slice( 0, 3 ).map( ( v, i ) => v * top[ 3 ] + under[ i ] * ( 1 - top[ 3 ] ) );
	// Fond réellement peint sous le centre du texte (pile de elementsFromPoint, pas les ancêtres) :
	// une étiquette flottante posée sur son champ prend le fond du champ.
	// Première ligne de la boîte : le centre d'un texte sur plusieurs lignes tombe parfois entre deux.
	const background = ( el ) => {
		const box = el.getClientRects()[ 0 ] ?? el.getBoundingClientRect();
		const stack = document.elementsFromPoint( box.left + box.width / 2, box.top + box.height / 2 );
		if ( ! stack.includes( el ) ) {
			return null;
		}
		const layers = [];
		for ( const n of stack.slice( stack.indexOf( el ) ) ) {
			const s = getComputedStyle( n );
			if ( [ 'IMG', 'PICTURE', 'VIDEO', 'CANVAS', 'svg' ].includes( n.tagName ) || ( s.backgroundImage !== 'none' && ! s.backgroundImage.startsWith( 'linear-gradient' ) ) ) {
				return null;
			}
			const c = parse( s.backgroundColor );
			if ( c && c[ 3 ] > 0 ) {
				layers.push( c );
				if ( c[ 3 ] >= 1 ) {
					break;
				}
			}
		}
		let bg = [ 255, 255, 255 ];
		for ( const layer of layers.reverse() ) {
			bg = over( layer, bg );
		}
		return bg;
	};
	const opacity = ( el ) => {
		let o = 1;
		for ( let n = el; n; n = n.parentElement ) {
			o *= Number( getComputedStyle( n ).opacity );
		}
		return o;
	};
	const fails = [];
	const unjudged = [];
	let judged = 0;
	const seen = new Set();
	const walker = document.createTreeWalker( document.body, NodeFilter.SHOW_TEXT );
	for ( let t = walker.nextNode(); t; t = walker.nextNode() ) {
		const el = t.parentElement;
		if ( ! el || ! t.textContent.trim() || seen.has( el ) ) {
			continue;
		}
		seen.add( el );
		const s = getComputedStyle( el );
		const box = el.getBoundingClientRect();
		if ( s.visibility !== 'visible' || box.width < 1 || box.height < 1 || el.closest( '[hidden], .screen-reader-text, #wpadminbar' ) ) {
			continue;
		}
		if ( s.clip === 'rect(0px, 0px, 0px, 0px)' || s.clipPath === 'inset(50%)' ) {
			continue;
		}
		// Composant inactif : exempté par WCAG 1.4.3.
		if ( el.closest( ':disabled, [aria-disabled="true"]' ) ) {
			continue;
		}
		el.scrollIntoView( { block: 'center' } );
		const bg = background( el );
		const fg = parse( s.color );
		if ( ! bg || ! fg ) {
			unjudged.push( `${ el.tagName.toLowerCase() } « ${ t.textContent.trim().slice( 0, 40 ) } »` );
			continue;
		}
		const text = over( [ fg[ 0 ], fg[ 1 ], fg[ 2 ], fg[ 3 ] * opacity( el ) ], bg );
		const [ a, b ] = [ lum( text ), lum( bg ) ];
		const r = ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 );
		const size = parseFloat( s.fontSize );
		const large = size >= 24 || ( size >= 18.66 && Number( s.fontWeight ) >= 700 );
		const min = large ? 3 : 4.5;
		judged++;
		if ( r < min ) {
			const name = el.tagName.toLowerCase() + ( el.className && typeof el.className === 'string' ? '.' + el.className.trim().split( /\s+/ ).slice( 0, 2 ).join( '.' ) : '' );
			fails.push( `${ r.toFixed( 2 ) } < ${ min } ${ name } « ${ t.textContent.trim().slice( 0, 40 ) } »` );
		}
	}
	// Champs : texte saisi à 4,5:1 et bordure à 3:1 contre le fond (WCAG 1.4.11).
	for ( const el of document.querySelectorAll( 'input:not([type="hidden"], [type="checkbox"], [type="radio"], [type="submit"]), select, textarea' ) ) {
		const s = getComputedStyle( el );
		const box = el.getBoundingClientRect();
		if ( s.visibility !== 'visible' || box.width < 1 || box.height < 1 || el.closest( '#wpadminbar' ) ) {
			continue;
		}
		el.scrollIntoView( { block: 'center' } );
		const bg = background( el );
		const fg = parse( s.color );
		const name = el.tagName.toLowerCase() + ( el.id ? '#' + el.id : '' );
		if ( ! bg || ! fg ) {
			unjudged.push( name );
			continue;
		}
		const pairs = [ [ over( [ fg[ 0 ], fg[ 1 ], fg[ 2 ], fg[ 3 ] * opacity( el ) ], bg ), 4.5, 'texte saisi' ] ];
		const hint = parse( getComputedStyle( el, '::placeholder' ).color );
		if ( el.placeholder && ! el.value && hint ) {
			const alpha = hint[ 3 ] * Number( getComputedStyle( el, '::placeholder' ).opacity ) * opacity( el );
			pairs.push( [ over( [ hint[ 0 ], hint[ 1 ], hint[ 2 ], alpha ], bg ), 4.5, 'texte indicatif' ] );
		}
		const border = parse( s.borderTopColor );
		if ( border && parseFloat( s.borderTopWidth ) > 0 ) {
			pairs.push( [ over( border, bg ), 3, 'bordure' ] );
		}
		for ( const [ c, min, what ] of pairs ) {
			const [ a, b ] = [ lum( c ), lum( bg ) ];
			const r = ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 );
			judged++;
			if ( r < min ) {
				fails.push( `${ r.toFixed( 2 ) } < ${ min } ${ name } (${ what })` );
			}
		}
	}
	return { judged, unjudged, fails };
}

// Indicateur de focus du champ actif (WCAG 2.4.7 et 1.4.11) : contour, sinon ombre, à 3:1
// contre le premier fond opaque sous le champ. Rend null si l'élément actif n'est pas un champ.
function focusRing() {
	const el = document.activeElement;
	if ( ! el || ! el.matches( 'input:not([type="hidden"]), select, textarea' ) ) {
		return null;
	}
	const rgb = ( c ) => {
		const m = c.match( /rgba?\(([^)]+)\)/ ) ?? c.match( /color\(srgb\s+([^)]+)\)/ );
		const p = m?.[ 1 ].split( /[\s,/]+/ ).filter( Boolean ).map( Number );
		const scale = m && c.includes( 'color(srgb' ) ? 255 : 1;
		return p ? [ p[ 0 ] * scale, p[ 1 ] * scale, p[ 2 ] * scale, p.length > 3 ? p[ 3 ] : 1 ] : null;
	};
	const lum = ( c ) => c.slice( 0, 3 ).map( ( v ) => {
		v /= 255;
		return v <= 0.03928 ? v / 12.92 : ( ( v + 0.055 ) / 1.055 ) ** 2.4;
	} ).reduce( ( sum, v, i ) => sum + v * [ 0.2126, 0.7152, 0.0722 ][ i ], 0 );
	const s = getComputedStyle( el );
	let ring = null;
	if ( s.outlineStyle !== 'none' && parseFloat( s.outlineWidth ) >= 1 ) {
		ring = rgb( s.outlineColor );
	} else if ( s.boxShadow !== 'none' ) {
		ring = rgb( s.boxShadow );
	}
	let bg = [ 255, 255, 255 ];
	for ( let n = el.parentElement; n; n = n.parentElement ) {
		const c = rgb( getComputedStyle( n ).backgroundColor );
		if ( c && c[ 3 ] >= 1 ) {
			bg = c;
			break;
		}
	}
	const name = el.tagName.toLowerCase() + ( el.id ? '#' + el.id : '' );
	if ( ! ring ) {
		return { name, ratio: 0 };
	}
	ring = ring.slice( 0, 3 ).map( ( v, i ) => v * ring[ 3 ] + bg[ i ] * ( 1 - ring[ 3 ] ) );
	const [ a, b ] = [ lum( ring ), lum( bg ) ];
	return { name, ratio: ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 ) };
}

const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: Number( opts.width ), height: 900 } } );
let failures = 0;
const report = async ( name, extra = '' ) => {
	const { judged, unjudged, fails } = await page.evaluate( sweep );
	failures += fails.length;
	console.log( `${ fails.length ? 'NON' : 'ok ' } ${ opts.label } ${ name } : ${ judged } texte(s) jugé(s), ${ unjudged.length } sur image ou recouvert(s)${ extra }` );
	fails.forEach( ( f ) => console.log( `    ${ f }` ) );
	if ( opts.details ) {
		unjudged.forEach( ( u ) => console.log( `    non jugé : ${ u }` ) );
	}
};
// Parcourt le formulaire au clavier depuis son premier champ et juge le focus de chaque
// champ atteint ; rend leur nombre.
const focusTour = async ( name, start, stops ) => {
	const bad = [];
	const seen = new Set();
	await page.keyboard.press( 'Tab' ); // Mode clavier, pour que :focus-visible s'applique.
	await page.locator( start ).first().focus();
	for ( let i = 0; i <= stops; i++ ) {
		if ( i ) {
			await page.keyboard.press( 'Tab' );
		}
		const ring = await page.evaluate( focusRing );
		if ( ring && ! seen.has( ring.name ) ) {
			seen.add( ring.name );
			if ( ring.ratio < 3 ) {
				bad.push( `${ ring.ratio.toFixed( 2 ) } < 3 ${ ring.name } (focus)` );
			}
		}
	}
	failures += bad.length;
	console.log( `${ bad.length ? 'NON' : 'ok ' } ${ opts.label } ${ name } : focus de ${ seen.size } champ(s) jugé(s) au clavier` );
	bad.forEach( ( f ) => console.log( `    ${ f }` ) );
	return seen.size;
};
// Le panier et la caisse se dessinent en React après le chargement : on attend leur contenu.
const visit = async ( path, ready = '' ) => {
	const res = await page.goto( base + path, { waitUntil: 'networkidle' } );
	if ( ! res || res.status() >= 400 ) {
		throw new Error( `${ path } : statut ${ res ? res.status() : 'aucun' }` );
	}
	if ( ready ) {
		await page.locator( ready ).first().waitFor( { timeout: 15000 } );
	}
};

await visit( '/shop/', '.wc-block-components-product-price, .products .price' );
const struck = await page.locator( 'del' ).count();
await report( 'boutique', struck ? ', prix barré présent' : ', AUCUN prix barré' );
await visit( `/product/${ opts.product }/`, '.wc-block-components-product-price, .summary .price' );
const sale = await page.locator( '.wc-block-components-product-sale-badge, .onsale' ).count();
await report( 'fiche produit', sale ? ', pastille de promotion présente' : ', AUCUNE pastille de promotion' );
await visit( `/?add-to-cart=${ opts.add }` );
await visit( '/cart/', '.wc-block-cart-items__row .wc-block-components-quantity-selector__input' );
await report( 'panier' );
await visit( '/checkout/', '.wc-block-components-address-form input' );
// Champs repliés : note de commande et coupon, pour mesurer aussi leur zone de saisie.
await page.locator( '.wc-block-checkout__add-note input[type="checkbox"]' ).first().check();
await page.locator( '.wc-block-components-textarea' ).first().waitFor( { timeout: 10000 } );
await page.locator( '.wc-block-components-totals-coupon .wc-block-components-panel__button' ).first().click();
await page.locator( '.wc-block-components-totals-coupon__input input' ).first().waitFor( { timeout: 10000 } );
await report( 'caisse', ', note de commande et coupon ouverts' );
const focused = await focusTour( 'caisse', '#email', 40 );
await page.locator( '.wc-block-components-checkout-place-order-button' ).first().click();
await page.locator( '.wc-block-components-validation-error' ).first().waitFor( { timeout: 10000 } );
const errors = await page.locator( '.wc-block-components-validation-error' ).count();
await report( 'caisse envoyée vide', `, ${ errors } erreur(s) de champ affichée(s)` );

// Commande d'essai payée à la livraison (passerelle cod active), puis page de confirmation.
const fields = {
	'#email': 'essai@example.test',
	'#billing-first_name': 'Essai',
	'#billing-last_name': 'Contraste',
	'#billing-address_1': '1 rue Principale',
	'#billing-city': 'Montréal',
	'#billing-postcode': 'H2X 1Y4',
};
await page.locator( '#billing-country' ).selectOption( 'CA' );
await page.locator( '#billing-state' ).selectOption( 'QC' );
for ( const [ field, value ] of Object.entries( fields ) ) {
	await page.locator( field ).fill( value );
}
await page.locator( '#radio-control-wc-payment-method-options-cod' ).check();
await page.locator( '.wc-block-components-checkout-place-order-button' ).first().click();
await page.waitForURL( /order-received/, { timeout: 20000 } );
await page.waitForLoadState( 'networkidle' );
await report( 'confirmation de commande' );

// Compte, déconnecté : formulaire de connexion classique de WooCommerce.
await page.context().clearCookies();
await visit( '/my-account/', 'form.woocommerce-form-login input' );
await report( 'compte (connexion)' );
await focusTour( 'compte (connexion)', '#username', 6 );
await browser.close();

if ( ! struck || ! sale || ! errors || ! focused ) {
	console.error( 'Élément attendu absent : prix barré, produit en promotion, erreurs de caisse ou champ atteint au clavier.' );
	process.exit( 1 );
}
process.exit( failures ? 1 : 0 );
