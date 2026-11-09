#!/usr/bin/env node
// Vérifie le contraste WCAG 2.2 AA de chaque palette : palette de base (theme.json) et
// chaque variation de style (styles/*.json).
//  1. PAIRS : paires de rôles texte/fond qui doivent atteindre 4,5:1 partout.
//  2. UI : paires de composants (contour de focus, filets) qui doivent atteindre 3:1.
//     Le rôle facultatif button-bg (fond des boutons) ajoute ses paires seulement s'il est
//     déclaré : texte on-accent à 4,5:1 et bouton contre base et surface à 3:1. Comme
//     Palette::button() : une bordure de bouton visible est jugée à 3:1 à la place du fond,
//     et le texte du bouton descend à 3:1 s'il est large (14 pt en gras ou 18 pt).
//  3. FORBIDDEN : paires qu'aucune palette ne peut rendre lisibles (accent et accent-2 sont
//     lisibles sur base, donc jamais sur contrast). Elles ne doivent apparaître dans aucun pattern.
//  4. Analyse des patterns : chaque couleur de texte réellement posée dans un pattern est
//     vérifiée contre le fond de son bloc ou de l'ancêtre le plus proche (4,5:1, ou 3:1 pour
//     le gros texte, les titres et les icônes).
// Usage : node tools/check-contrast.mjs
import { readFileSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const theme = join( dirname( fileURLToPath( import.meta.url ) ), '..', 'packages', 'fluffless' );
const AA = 4.5;
const LARGE = 3;

// Paires texte/fond à valider : [texte, fond].
const PAIRS = [
	[ 'contrast', 'base' ],
	[ 'contrast', 'surface' ],
	[ 'muted', 'base' ],
	[ 'muted', 'surface' ],
	[ 'accent', 'base' ],
	[ 'accent', 'surface' ],
	[ 'on-accent', 'accent' ],
	[ 'accent-2', 'base' ],
	[ 'accent-2', 'surface' ],
	[ 'base', 'accent-2' ],
	[ 'alert', 'base' ],
	[ 'base', 'contrast' ],
];

// Composants et contour de focus (WCAG 1.4.11 et 2.4.13) : [trait, fond adjacent].
// Le focus est un double contour (trait contrast, liseré base) : il reste lisible sur
// accent, surface et base parce que ses deux couleurs contrastent entre elles.
const UI = [
	[ 'contrast', 'base' ],
	[ 'contrast', 'surface' ],
	[ 'on-accent', 'accent' ],
	[ 'divider', 'base' ],
	[ 'divider', 'surface' ],
];

// Rôle facultatif : sans lui, les boutons restent sur accent, déjà couvert plus haut.
const OPTIONAL = 'button-bg';
const OPTIONAL_PAIRS = [ [ 'on-accent', OPTIONAL ] ];
const OPTIONAL_UI = [
	[ OPTIONAL, 'base' ],
	[ OPTIONAL, 'surface' ],
];

// Bordure de bouton déclarée : elle délimite le bouton à la place du fond.
const BORDER_UI = [
	[ 'button-border', 'base' ],
	[ 'button-border', 'surface' ],
];

// Paires interdites en texte : [texte, fond]. Le ratio est affiché pour mémoire.
const FORBIDDEN = [
	[ 'accent-2', 'contrast' ],
	[ 'accent', 'contrast' ],
];

// Fond implicite des styles de blocs (styles/blocks/*.json) et des dégradés.
const STYLE_BG = {
	'is-style-inverse': 'contrast',
	'is-style-carte': 'surface',
	'is-style-diagonale': 'surface',
	'is-style-bordure-brute': 'base',
	'is-style-forme-douce': 'surface',
};
const GRADIENT_BG = { maille: 'surface', fondu: 'base' };
const LARGE_SIZES = new Set( [ 'large', 'x-large', 'xx-large', 'giant' ] );

function luminance( hex ) {
	const clean = hex.replace( '#', '' );
	if ( ! /^[0-9a-f]{6}$/i.test( clean ) ) {
		throw new Error( `Couleur invalide : ${ hex }` );
	}
	const [ r, g, b ] = [ 0, 2, 4 ].map( ( i ) => {
		const c = parseInt( clean.slice( i, i + 2 ), 16 ) / 255;
		return c <= 0.04045 ? c / 12.92 : ( ( c + 0.055 ) / 1.055 ) ** 2.4;
	} );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio( a, b ) {
	const [ hi, lo ] = [ luminance( a ), luminance( b ) ].sort( ( x, y ) => y - x );
	return ( hi + 0.05 ) / ( lo + 0.05 );
}

// Longueur en pixels (px, rem et em à 16 px ; premier terme d'un clamp() ou d'un min()) ; 0 si inconnue.
function px( length ) {
	const m = /^\s*(?:(?:clamp|min)\(\s*)?([0-9]*\.?[0-9]+)(px|rem|em)\b/i.exec( String( length ?? '' ) );
	return m ? parseFloat( m[ 1 ] ) * ( 'px' === m[ 2 ].toLowerCase() ? 1 : 16 ) : 0;
}

// Style des boutons (thème, puis variation) : { border: rôle, #rrggbb ou '', large }.
function buttonStyle( base, json, roles ) {
	const b = { ...base?.styles?.elements?.button, ...json?.styles?.elements?.button };
	const border = b.border ?? {};
	let color = '';
	const c = String( border.color ?? '' ).trim().toLowerCase();
	const role = /^(?:var:preset\|color\||var\(--wp--preset--color--)([a-z0-9-]+)/.exec( c );
	if ( px( border.width ) > 0 && ! [ 'none', 'hidden' ].includes( border.style ) ) {
		color = /^#[0-9a-f]{6}$/.test( c ) ? c : role && roles.includes( role[ 1 ] ) ? role[ 1 ] : '';
	}
	let size = String( b.typography?.fontSize ?? '' );
	const preset = /^(?:var:preset\|font-size\||var\(--wp--preset--font-size--)([a-z0-9-]+)/.exec( size );
	if ( preset ) {
		const sizes = json?.settings?.typography?.fontSizes ?? base?.settings?.typography?.fontSizes ?? [];
		const found = sizes.find( ( f ) => f.slug === preset[ 1 ] );
		size = found ? found.fluid?.min ?? found.size : '';
	}
	const weight = String( b.typography?.fontWeight ?? '' ).toLowerCase();
	const bold = [ 'bold', 'bolder' ].includes( weight ) || parseInt( weight, 10 ) >= 700;
	return { border: color, large: px( size ) >= 24 || ( bold && px( size ) >= 18.66 ) };
}

function palette( json, file ) {
	const list = json?.settings?.color?.palette;
	if ( ! Array.isArray( list ) ) {
		return null;
	}
	const map = Object.fromEntries( list.map( ( c ) => [ c.slug, c.color ] ) );
	for ( const [ text, bg ] of [ ...PAIRS, ...UI, ...FORBIDDEN ] ) {
		if ( ! map[ text ] || ! map[ bg ] ) {
			throw new Error( `${ file } : rôle manquant (${ text } ou ${ bg })` );
		}
	}
	return map;
}

// Fond propre d'un bloc : attribut backgroundColor, style de bloc ou dégradé.
function ownBackground( attrs ) {
	if ( attrs.backgroundColor ) {
		return attrs.backgroundColor;
	}
	for ( const cls of ( attrs.className ?? '' ).split( /\s+/ ) ) {
		if ( STYLE_BG[ cls ] ) {
			return STYLE_BG[ cls ];
		}
	}
	return GRADIENT_BG[ attrs.gradient ] ?? null;
}

// Parcourt les commentaires de blocs d'un pattern et relève chaque [texte, fond, seuil].
function usedPairs( source ) {
	const found = [];
	const stack = [ 'base' ];
	const re = /<!--\s+(\/)?wp:([a-z0-9/-]+)(\s+(\{.*?\}))?\s+(\/)?-->/g;
	for ( const [ , closing, name, , json, selfClosing ] of source.matchAll( re ) ) {
		if ( closing ) {
			stack.pop();
			continue;
		}
		let attrs = {};
		try {
			attrs = json ? JSON.parse( json ) : {};
		} catch {
			attrs = {}; // Attribut contenant du PHP : seul le fond hérité compte.
		}
		const bg = ownBackground( attrs ) ?? stack.at( -1 );
		if ( attrs.textColor ) {
			const large = name === 'core/heading' || name === 'heading' || name.endsWith( '/icone' ) || LARGE_SIZES.has( attrs.fontSize );
			found.push( [ attrs.textColor, bg, large ? LARGE : AA, name ] );
		}
		if ( ! selfClosing ) {
			stack.push( bg );
		}
	}
	return found;
}

const baseJson = JSON.parse( readFileSync( join( theme, 'theme.json' ), 'utf8' ) );
const sources = [ [ 'theme.json (Sobre)', join( theme, 'theme.json' ) ] ];
for ( const name of readdirSync( join( theme, 'styles' ) ) ) {
	if ( name.endsWith( '.json' ) ) {
		sources.push( [ `styles/${ name }`, join( theme, 'styles', name ) ] );
	}
}

// Patterns du thème et de Fluffless Suite : les deux se posent sur les mêmes palettes.
const patternDirs = [
	[ '', join( theme, 'patterns' ) ],
	[ 'fluffless-suite/', join( theme, '..', 'fluffless-suite', 'patterns' ) ],
];
const patterns = patternDirs.flatMap( ( [ prefix, dir ] ) =>
	readdirSync( dir )
		.filter( ( f ) => f.endsWith( '.php' ) )
		.map( ( f ) => [ prefix + f, usedPairs( readFileSync( join( dir, f ), 'utf8' ) ) ] )
);

// Feuille WooCommerce du thème : chaque couleur de palette qu'elle pose. Texte contre le fond
// déclaré dans la même règle, sinon contre base et surface ; trait (bordure, contour, ombre,
// icône) à 3:1 contre base et surface. La mesure dans le navigateur reste
// tools/bench/contrast-woocommerce.mjs ; ce contrôle attrape une palette qui casse une paire.
const WOO_CSS = join( theme, 'assets', 'css', 'woocommerce.css' );
const ROLE = /var\(--wp--preset--color--([a-z0-9-]+)\)/;

function wooPairs( css ) {
	const found = [];
	for ( const [ , selector, body ] of css.replace( /\/\*[\s\S]*?\*\//g, '' ).matchAll( /([^{}]+)\{([^{}]*)\}/g ) ) {
		const decls = Object.fromEntries( [ ...body.matchAll( /([a-z-]+)\s*:\s*([^;]+)/g ) ].map( ( m ) => [ m[ 1 ], m[ 2 ] ] ) );
		const where = selector.trim().split( /\s*,\s*/ )[ 0 ];
		const bg = ROLE.exec( decls[ 'background-color' ] ?? '' )?.[ 1 ];
		const text = ROLE.exec( decls.color ?? decls[ '-webkit-text-fill-color' ] ?? '' )?.[ 1 ];
		if ( text ) {
			( bg ? [ bg ] : [ 'base', 'surface' ] ).forEach( ( b ) => found.push( [ text, b, AA, where ] ) );
		}
		for ( const prop of [ 'border-color', 'border-left', 'outline-color', 'box-shadow', 'fill' ] ) {
			const stroke = ROLE.exec( decls[ prop ] ?? '' )?.[ 1 ];
			// Ombre intérieure : c'est un fond (remplissage automatique), pas un trait.
			if ( stroke && ! ( 'box-shadow' === prop && /\binset\b/.test( decls[ prop ] ) ) && stroke !== text ) {
				[ 'base', 'surface' ].forEach( ( b ) => found.push( [ stroke, b, LARGE, `${ where } (${ prop })` ] ) );
			}
		}
	}
	return found;
}

const woo = wooPairs( readFileSync( WOO_CSS, 'utf8' ) );
if ( ! woo.length ) {
	throw new Error( 'woocommerce.css : aucune couleur de palette relevée' );
}

let failures = 0;

// Règle indépendante des palettes : aucune paire interdite dans un pattern.
for ( const [ file, pairs ] of patterns ) {
	for ( const [ text, bg, , block ] of pairs ) {
		if ( FORBIDDEN.some( ( [ t, b ] ) => t === text && b === bg ) ) {
			failures++;
			console.error( `NON patterns/${ file } : ${ block } en ${ text } sur ${ bg } (paire interdite)` );
		}
	}
}

for ( const [ label, path ] of sources ) {
	const json = JSON.parse( readFileSync( path, 'utf8' ) );
	const map = palette( json, label );
	if ( ! map ) {
		continue;
	}
	const lines = [];
	const check = ( text, bg, min, kind ) => {
		const r = ratio( map[ text ], map[ bg ] );
		const ok = r >= min;
		if ( ! ok ) {
			failures++;
		}
		lines.push( `${ ok ? 'ok ' : 'NON' } ${ kind } ${ text }/${ bg } ${ r.toFixed( 2 ) } (min ${ min })` );
	};
	PAIRS.forEach( ( [ t, b ] ) => check( t, b, AA, 'texte' ) );
	UI.forEach( ( [ t, b ] ) => check( t, b, LARGE, 'UI   ' ) );
	const button = buttonStyle( baseJson, json, Object.keys( map ) );
	if ( button.border ) {
		map[ 'button-border' ] = button.border.startsWith( '#' ) ? button.border : map[ button.border ];
		BORDER_UI.forEach( ( [ t, b ] ) => check( t, b, LARGE, 'UI   ' ) );
	}
	if ( map[ OPTIONAL ] ) {
		OPTIONAL_PAIRS.forEach( ( [ t, b ] ) => check( t, b, button.large ? LARGE : AA, 'texte' ) );
		if ( ! button.border ) {
			OPTIONAL_UI.forEach( ( [ t, b ] ) => check( t, b, LARGE, 'UI   ' ) );
		}
	}
	FORBIDDEN.forEach( ( [ t, b ] ) => lines.push( `--  interdit ${ t }/${ b } ${ ratio( map[ t ], map[ b ] ).toFixed( 2 ) } (jamais en texte)` ) );

	// Paires réellement posées dans les patterns, dédoublonnées par seuil.
	const seen = new Map();
	for ( const [ file, pairs ] of patterns ) {
		for ( const [ text, bg, min ] of pairs ) {
			if ( ! map[ text ] || ! map[ bg ] ) {
				continue;
			}
			const key = `${ text }/${ bg }/${ min }`;
			if ( ! seen.has( key ) ) {
				seen.set( key, [ text, bg, min, file ] );
			}
		}
	}
	for ( const [ text, bg, min, file ] of seen.values() ) {
		const r = ratio( map[ text ], map[ bg ] );
		if ( r < min ) {
			failures++;
			lines.push( `NON usage ${ text }/${ bg } ${ r.toFixed( 2 ) } (min ${ min }, ex. ${ file })` );
		}
	}
	lines.push( `${ seen.size } combinaison(s) relevée(s) dans les patterns` );

	const wooSeen = new Set();
	for ( const [ text, bg, min, where ] of woo ) {
		const key = `${ text }/${ bg }/${ min }`;
		if ( wooSeen.has( key ) ) {
			continue;
		}
		wooSeen.add( key );
		if ( ! map[ text ] || ! map[ bg ] ) {
			failures++;
			lines.push( `NON boutique ${ text }/${ bg } : rôle absent de la palette (${ where })` );
			continue;
		}
		const r = ratio( map[ text ], map[ bg ] );
		if ( r < min ) {
			failures++;
			lines.push( `NON boutique ${ text }/${ bg } ${ r.toFixed( 2 ) } (min ${ min }, ${ where })` );
		}
	}
	lines.push( `${ wooSeen.size } combinaison(s) relevée(s) dans woocommerce.css` );
	console.log( `${ label }\n  ${ lines.join( '\n  ' ) }` );
}

// Écrans d'administration de la Suite : jetons --fl-* de admin.css, clair puis sombre.
// Texte à 4,5:1 ; focus et indicateur d'état « off » à 3:1 (WCAG 1.4.11).
const ADMIN_CSS = join( theme, '..', 'fluffless-suite', 'assets', 'admin', 'admin.css' );
const ADMIN_TEXT = [
	[ 'text', 'bg' ], [ 'text', 'surface' ], [ 'muted', 'bg' ], [ 'muted', 'surface' ],
	[ 'accent', 'bg' ], [ 'accent', 'surface' ], [ 'on', 'surface' ],
	[ 'on-strong', 'strong' ],
	[ 'good', 'good-bg' ], [ 'warn', 'warn-bg' ], [ 'bad', 'bad-bg' ], [ 'idle', 'idle-bg' ],
];
const ADMIN_UI = [ [ 'focus', 'bg' ], [ 'focus', 'surface' ], [ 'off', 'surface' ] ];

function adminTokens( block ) {
	return Object.fromEntries( [ ...block.matchAll( /--fl-([a-z-]+):\s*(#[0-9a-f]{6})\b/gi ) ].map( ( m ) => [ m[ 1 ], m[ 2 ] ] ) );
}

{
	const css = readFileSync( ADMIN_CSS, 'utf8' );
	const light = /\.fluffless-screen\s*\{([^}]*)\}/.exec( css );
	const dark = /prefers-color-scheme:\s*dark\)\s*\{\s*\.fluffless-screen\s*\{([^}]*)\}/.exec( css );
	if ( ! light || ! dark ) {
		throw new Error( 'admin.css : blocs de jetons clair et sombre introuvables' );
	}
	const lightTokens = adminTokens( light[ 1 ] );
	const modes = [ [ 'admin.css (clair)', lightTokens ], [ 'admin.css (sombre)', { ...lightTokens, ...adminTokens( dark[ 1 ] ) } ] ];
	for ( const [ label, map ] of modes ) {
		const lines = [];
		const run = ( pairs, min, kind ) => pairs.forEach( ( [ t, b ] ) => {
			if ( ! map[ t ] || ! map[ b ] ) {
				throw new Error( `${ label } : jeton manquant (${ t } ou ${ b })` );
			}
			const r = ratio( map[ t ], map[ b ] );
			if ( r < min ) {
				failures++;
			}
			lines.push( `${ r >= min ? 'ok ' : 'NON' } ${ kind } ${ t }/${ b } ${ r.toFixed( 2 ) } (min ${ min })` );
		} );
		run( ADMIN_TEXT, AA, 'texte' );
		run( ADMIN_UI, LARGE, 'UI   ' );
		console.log( `${ label }\n  ${ lines.join( '\n  ' ) }` );
	}

	// Tuiles d'icônes teintées par groupe (.fl-hue-*) : chaque teinte est déclarée une fois
	// en clair, puis une fois dans le bloc sombre qui suit. Icône à 3:1.
	const tiles = [ ...css.matchAll( /\.fl-hue-([a-z-]+)\s*\{\s*--fl-tile-bg:\s*(#[0-9a-f]{6});\s*--fl-tile-fg:\s*(#[0-9a-f]{6});/gi ) ];
	const lightTiles = tiles.filter( ( m, i ) => tiles.findIndex( ( n ) => n[ 1 ] === m[ 1 ] ) === i );
	const darkTiles = tiles.filter( ( m ) => ! lightTiles.includes( m ) );
	const darkStart = css.lastIndexOf( 'prefers-color-scheme: dark', darkTiles[ 0 ]?.index ?? 0 );
	if ( lightTiles.length < 5 || darkTiles.length !== lightTiles.length || darkStart < lightTiles.at( -1 ).index ) {
		throw new Error( `admin.css : tuiles .fl-hue-* introuvables ou mal rangées (${ lightTiles.length } claires, ${ darkTiles.length } sombres)` );
	}
	const lines = [];
	for ( const [ mode, list ] of [ [ 'clair', lightTiles ], [ 'sombre', darkTiles ] ] ) {
		for ( const [ , hue, bg, fg ] of list ) {
			const r = ratio( fg, bg );
			if ( r < LARGE ) {
				failures++;
			}
			lines.push( `${ r >= LARGE ? 'ok ' : 'NON' } tuile ${ hue } (${ mode }) ${ r.toFixed( 2 ) } (min ${ LARGE })` );
		}
	}

	// Règles qui posent elles-mêmes un fond et un texte en couleurs littérales (aperçu de forme).
	let literal = 0;
	for ( const [ , selector, body ] of css.replace( /\/\*[\s\S]*?\*\//g, '' ).matchAll( /([^{}]+)\{([^{}]*)\}/g ) ) {
		const bg = /(?:^|;)\s*background(?:-color)?\s*:\s*(#[0-9a-f]{6})\b/i.exec( body )?.[ 1 ];
		const fg = /(?:^|;)\s*color\s*:\s*(#[0-9a-f]{6})\b/i.exec( body )?.[ 1 ];
		if ( ! bg || ! fg ) {
			continue;
		}
		literal++;
		const r = ratio( fg, bg );
		if ( r < AA ) {
			failures++;
		}
		lines.push( `${ r >= AA ? 'ok ' : 'NON' } texte ${ selector.trim() } ${ r.toFixed( 2 ) } (min ${ AA })` );
	}
	if ( ! literal ) {
		throw new Error( 'admin.css : aucune règle à couleurs littérales relevée (aperçu de forme)' );
	}
	console.log( `admin.css (tuiles et aperçu)\n  ${ lines.join( '\n  ' ) }` );
}

// Guide « Bien démarrer » de la Suite (widget du tableau de bord, panneau de l'éditeur) :
// jetons --fl-g-* sur fond forêt. Texte à 4,5:1 ; icônes, bordure de survol et focus à 3:1.
const GUIDE_CSS = join( theme, '..', 'fluffless-suite', 'assets', 'guide.css' );
const GUIDE_TEXT = [
	[ 'text', 'bg' ], [ 'text', 'surface' ], [ 'muted', 'bg' ], [ 'muted', 'surface' ],
	[ 'green', 'bg' ], [ 'bg', 'green' ],
];
const GUIDE_UI = [ [ 'green', 'surface' ], [ 'pink', 'bg' ], [ 'pink', 'surface' ] ];

{
	const css = readFileSync( GUIDE_CSS, 'utf8' );
	const tokens = Object.fromEntries( [ ...css.matchAll( /--fl-g-([a-z-]+):\s*(#[0-9a-f]{6})\b/gi ) ].map( ( m ) => [ m[ 1 ], m[ 2 ] ] ) );
	const lines = [];
	const run = ( pairs, min, kind ) => pairs.forEach( ( [ t, b ] ) => {
		if ( ! tokens[ t ] || ! tokens[ b ] ) {
			throw new Error( `guide.css : jeton manquant (${ t } ou ${ b })` );
		}
		const r = ratio( tokens[ t ], tokens[ b ] );
		if ( r < min ) {
			failures++;
		}
		lines.push( `${ r >= min ? 'ok ' : 'NON' } ${ kind } ${ t }/${ b } ${ r.toFixed( 2 ) } (min ${ min })` );
	} );
	run( GUIDE_TEXT, AA, 'texte' );
	run( GUIDE_UI, LARGE, 'UI   ' );
	console.log( `guide.css\n  ${ lines.join( '\n  ' ) }` );
}

if ( failures ) {
	console.error( `\n${ failures } échec(s) de contraste.` );
	process.exit( 1 );
}
console.log( `\nOK : paires de texte à ${ AA }:1, composants à ${ LARGE }:1, aucune paire interdite dans les patterns, woocommerce.css, admin.css et guide.css inclus.` );
