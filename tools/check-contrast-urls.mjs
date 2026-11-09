#!/usr/bin/env node
// Vérifie le contraste WCAG 2.2 AA des paires texte/fond trouvées dans le HTML et les feuilles
// de style d'une liste d'URL rendues. Ne remplace pas check:contrast (qui connaît les jetons
// theme.json et la structure des blocs de ce dépôt) : c'est sa version pour un thème audité qui
// vit dans un autre dépôt (banc de rendu), où on ne peut viser qu'un chemin de thème en dur.
//
// Heuristique, pas une cascade CSS complète : associe les déclarations color/background-color
// trouvées dans une même règle CSS ou un même attribut style="" en ligne, résout les
// var(--jeton) déclarés dans la même feuille, et calcule le ratio WCAG de chaque paire ainsi
// formée. Ne résout ni l'héritage entre règles, ni la spécificité, ni les media queries : une
// paire non détectée n'est pas prouvée conforme. Un audit navigateur réel (Lighthouse, axe)
// fait foi.
//
// Usage : node tools/check-contrast-urls.mjs <url...>            (une ou plusieurs URL)
//         node tools/check-contrast-urls.mjs <fichier-liste.txt>  (une URL par ligne, # ignoré)
import { readFileSync } from 'node:fs';

const AA = 4.5;
const LARGE = 3;
const LARGE_PX = 18.66; // ~14pt gras ou 18pt, seuil WCAG du grand texte.

function luminance( hex ) {
	const clean = hex.replace( '#', '' );
	const full = clean.length === 3 ? [ ...clean ].map( ( c ) => c + c ).join( '' ) : clean;
	if ( ! /^[0-9a-f]{6}$/i.test( full ) ) {
		return null;
	}
	const [ r, g, b ] = [ 0, 2, 4 ].map( ( i ) => {
		const c = parseInt( full.slice( i, i + 2 ), 16 ) / 255;
		return c <= 0.04045 ? c / 12.92 : ( ( c + 0.055 ) / 1.055 ) ** 2.4;
	} );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio( a, b ) {
	const [ la, lb ] = [ luminance( a ), luminance( b ) ];
	if ( la === null || lb === null ) {
		return null;
	}
	const [ hi, lo ] = [ la, lb ].sort( ( x, y ) => y - x );
	return ( hi + 0.05 ) / ( lo + 0.05 );
}

// #rgb, #rrggbb ou rgb[a](r, g, b[, a]) -> #rrggbb ; toute autre valeur (mot-clé, transparent,
// gradient) est ignorée, faute de table de couleurs nommées.
function toHex( value ) {
	const v = value.trim().toLowerCase();
	if ( /^#([0-9a-f]{3}|[0-9a-f]{6})$/.test( v ) ) {
		return v;
	}
	const m = /^rgba?\(\s*([0-9]+)\s*,\s*([0-9]+)\s*,\s*([0-9]+)\s*(?:,\s*[0-9.]+\s*)?\)$/.exec( v );
	if ( m ) {
		const [ , r, g, b ] = m;
		return `#${ [ r, g, b ].map( ( n ) => Number( n ).toString( 16 ).padStart( 2, '0' ) ).join( '' ) }`;
	}
	return null;
}

function px( length ) {
	const m = /^\s*([0-9]*\.?[0-9]+)(px|rem|em)\b/i.exec( String( length ?? '' ) );
	return m ? parseFloat( m[ 1 ] ) * ( 'px' === m[ 2 ].toLowerCase() ? 1 : 16 ) : 0;
}

function customProps( css ) {
	const map = new Map();
	for ( const m of css.matchAll( /--([a-zA-Z0-9-]+)\s*:\s*([^;}]+)\s*[;}]/g ) ) {
		const hex = toHex( m[ 2 ] );
		if ( hex ) {
			map.set( m[ 1 ], hex );
		}
	}
	return map;
}

function resolveColor( raw, props ) {
	const v = raw.trim();
	const varMatch = /^var\(\s*--([a-zA-Z0-9-]+)\s*(?:,\s*(.+))?\)$/.exec( v );
	if ( varMatch ) {
		return props.get( varMatch[ 1 ] ) ?? ( varMatch[ 2 ] ? resolveColor( varMatch[ 2 ], props ) : null );
	}
	return toHex( v );
}

// Relève les paires [texte, fond, tailleSeuil, contexte] déclarées ensemble dans un bloc de
// déclarations CSS (une règle { ... } ou un style="" en ligne).
function pairsInBlock( decls, props, context ) {
	const get = ( prop ) => {
		const m = new RegExp( `(?:^|;)\\s*${ prop }\\s*:\\s*([^;]+)` ).exec( decls );
		return m ? m[ 1 ].trim() : null;
	};
	const color = get( 'color' );
	const bg = get( 'background-color' ) ?? get( 'background' );
	if ( ! color || ! bg ) {
		return [];
	}
	const textHex = resolveColor( color, props );
	const bgHex = resolveColor( bg, props );
	if ( ! textHex || ! bgHex ) {
		return [];
	}
	const size = px( get( 'font-size' ) );
	const weight = ( get( 'font-weight' ) ?? '' ).toLowerCase();
	const bold = [ 'bold', 'bolder' ].includes( weight ) || parseInt( weight, 10 ) >= 700;
	const large = size >= 24 || ( bold && size >= LARGE_PX );
	return [ [ textHex, bgHex, large ? LARGE : AA, context ] ];
}

function pairsInCss( css, props, label ) {
	const found = [];
	for ( const m of css.matchAll( /([^{}]+)\{([^{}]*)\}/g ) ) {
		found.push( ...pairsInBlock( m[ 2 ], props, `${ label } ${ m[ 1 ].trim().slice( 0, 60 ) }` ) );
	}
	return found;
}

function pairsInlineStyles( html, props, label ) {
	const found = [];
	for ( const m of html.matchAll( /<[a-z][a-z0-9-]*\b[^>]*\sstyle=["']([^"']+)["'][^>]*>/gi ) ) {
		found.push( ...pairsInBlock( m[ 1 ], props, `${ label } (style en ligne)` ) );
	}
	return found;
}

async function fetchText( url ) {
	const res = await fetch( url );
	if ( ! res.ok ) {
		throw new Error( `${ url } a répondu ${ res.status }` );
	}
	return res.text();
}

function sameOrigin( href, origin ) {
	try {
		return new URL( href, origin ).origin === new URL( origin ).origin;
	} catch {
		return false;
	}
}

async function checkUrl( url ) {
	const html = await fetchText( url );
	const inlineCss = [ ...html.matchAll( /<style\b[^>]*>([\s\S]*?)<\/style>/gi ) ].map( ( m ) => m[ 1 ] ).join( '\n' );
	const hrefs = [ ...html.matchAll( /<link\b[^>]*\brel=["']stylesheet["'][^>]*\bhref=["']([^"']+)["'][^>]*>/gi ) ]
		.map( ( m ) => m[ 1 ] )
		.filter( ( href ) => sameOrigin( href, url ) );
	const linkedCss = [];
	for ( const href of hrefs ) {
		try {
			linkedCss.push( await fetchText( new URL( href, url ).href ) );
		} catch {
			// Feuille injoignable : ignorée, pas de paire à en tirer.
		}
	}
	const css = [ inlineCss, ...linkedCss ].join( '\n' );
	const props = customProps( css );
	const pairs = [ ...pairsInCss( css, props, url ), ...pairsInlineStyles( html, props, url ) ];

	const seen = new Map();
	for ( const [ text, bg, min, context ] of pairs ) {
		const key = `${ text }/${ bg }/${ min }`;
		if ( ! seen.has( key ) ) {
			seen.set( key, [ text, bg, min, context ] );
		}
	}
	const lines = [];
	let failures = 0;
	for ( const [ text, bg, min, context ] of seen.values() ) {
		const r = ratio( text, bg );
		if ( r === null ) {
			continue;
		}
		const ok = r >= min;
		if ( ! ok ) {
			failures++;
		}
		lines.push( `  ${ ok ? 'ok ' : 'NON' } ${ text }/${ bg } ${ r.toFixed( 2 ) } (min ${ min }), ${ context }` );
	}
	return { failures, lines, count: seen.size };
}

function readUrls( args ) {
	if ( args.length === 1 ) {
		try {
			const content = readFileSync( args[ 0 ], 'utf8' );
			return content
				.split( '\n' )
				.map( ( l ) => l.replace( /#.*$/, '' ).trim() )
				.filter( Boolean );
		} catch {
			return args;
		}
	}
	return args;
}

const args = process.argv.slice( 2 );
if ( args.length === 0 ) {
	console.error( 'Usage : node tools/check-contrast-urls.mjs <url...> | <fichier-liste.txt>' );
	process.exit( 2 );
}
const urls = readUrls( args );
if ( urls.length === 0 ) {
	console.error( 'Aucune URL à vérifier.' );
	process.exit( 2 );
}

let totalFailures = 0;
for ( const url of urls ) {
	try {
		const { failures, lines, count } = await checkUrl( url );
		totalFailures += failures;
		console.log( `${ url } (${ count } paire(s) relevée(s))` );
		if ( lines.length ) {
			console.log( lines.join( '\n' ) );
		}
	} catch ( error ) {
		totalFailures++;
		console.error( `ÉCHEC ${ url } : ${ error.message }` );
	}
}

if ( totalFailures ) {
	console.error( `\n${ totalFailures } échec(s) de contraste ou de requête.` );
	process.exit( 1 );
}
console.log( `\nOK : ${ urls.length } URL, aucune paire texte/fond détectée sous le seuil WCAG.` );
