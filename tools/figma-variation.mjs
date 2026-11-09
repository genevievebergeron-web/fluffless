#!/usr/bin/env node
// Chemin inverse de figma-tokens.mjs : des variables exportées de Figma deviennent une variation
// de style WordPress (palette et formes). Accepte l'export natif de Figma (DTCG, couleur en objet),
// le format de figma-tokens.mjs (DTCG, couleur en #rrggbb) et Tokens Studio (value/type sans $).
// Les variables reconnues : couleur/<rôle> (button-bg facultatif) et
// rayon/<none|soft|round|large|small|cut>, quel que soit le préfixe de collection.
// Une palette qui échoue au contraste AA est refusée.
// Usage : node tools/figma-variation.mjs --titre "Variante" [--sortie styles/variante.json] export.json [autre.json…]

import { readFileSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const theme = JSON.parse( readFileSync( join( dirname( fileURLToPath( import.meta.url ) ), '..', 'packages', 'fluffless', 'theme.json' ), 'utf8' ) );
const REM = 16;
export const ROLES = theme.settings.color.palette.map( ( c ) => c.slug );
const NAMES = Object.fromEntries( theme.settings.color.palette.map( ( c ) => [ c.slug, c.name ] ) );
const RADII = [ 'none', 'soft', 'round', 'large', 'small', 'cut' ];
// Rôle facultatif (Palette::OPTIONAL_ROLES) : fond des boutons, vérifié seulement s'il est fourni.
const OPTIONAL = { 'button-bg': 'Button background' };

// Mêmes paires que tools/check-contrast.mjs : texte à 4,5:1, composants à 3:1.
const PAIRS = [
	[ 'contrast', 'base' ], [ 'contrast', 'surface' ], [ 'muted', 'base' ], [ 'muted', 'surface' ],
	[ 'accent', 'base' ], [ 'accent', 'surface' ], [ 'on-accent', 'accent' ], [ 'accent-2', 'base' ],
	[ 'accent-2', 'surface' ], [ 'base', 'accent-2' ], [ 'alert', 'base' ], [ 'base', 'contrast' ],
];
const UI = [ [ 'contrast', 'base' ], [ 'contrast', 'surface' ], [ 'on-accent', 'accent' ] ];
const OPTIONAL_PAIRS = [ [ 'on-accent', 'button-bg' ] ];
const OPTIONAL_UI = [ [ 'button-bg', 'base' ], [ 'button-bg', 'surface' ] ];

/** Aplatit un fichier de jetons : { 'Couleurs/couleur/accent': valeur brute }. */
export function flatten( tree, prefix = '' ) {
	const out = {};
	for ( const [ key, node ] of Object.entries( tree ?? {} ) ) {
		if ( key.startsWith( '$' ) || null === node || 'object' !== typeof node ) {
			continue;
		}
		const path = prefix + key;
		if ( '$value' in node || ( 'value' in node && 'type' in node ) ) {
			out[ path ] = node.$value ?? node.value;
		} else {
			Object.assign( out, flatten( node, path + '/' ) );
		}
	}
	return out;
}

/** Couleur opaque en #rrggbb, ou null : chaîne hexadécimale, ou objet DTCG (hex ou components 0-1). */
export function toHex( value ) {
	if ( 'string' === typeof value ) {
		const m = /^#([0-9a-f]{6})(ff)?$/i.exec( value.trim() );
		return m ? '#' + m[ 1 ].toLowerCase() : null;
	}
	if ( value && 'object' === typeof value ) {
		if ( undefined !== value.alpha && 1 !== value.alpha ) {
			return null;
		}
		if ( 'string' === typeof value.hex ) {
			return toHex( value.hex );
		}
		if ( Array.isArray( value.components ) && 3 === value.components.length && ( ! value.colorSpace || 'srgb' === value.colorSpace ) ) {
			return '#' + value.components.map( ( c ) => Math.round( Math.min( 1, Math.max( 0, c ) ) * 255 ).toString( 16 ).padStart( 2, '0' ) ).join( '' );
		}
	}
	return null;
}

/** Nombre de pixels : 12, « 12px », { value: 12, unit: 'px' }. */
function toPx( value ) {
	if ( 'number' === typeof value ) {
		return value;
	}
	if ( value && 'object' === typeof value && 'number' === typeof value.value && ( ! value.unit || 'px' === value.unit ) ) {
		return value.value;
	}
	const m = /^(-?[\d.]+)(px)?$/.exec( String( value ).trim() );
	return m ? parseFloat( m[ 1 ] ) : null;
}

/** Pixels vers la notation du thème : 0, 999px pour la pilule, sinon en rem. */
function toCss( n ) {
	if ( 0 === n ) {
		return '0';
	}
	if ( n >= 999 ) {
		return '999px';
	}
	return `${ Math.round( ( n / REM ) * 1000 ) / 1000 }rem`;
}

function luminance( hex ) {
	const [ r, g, b ] = [ 1, 3, 5 ].map( ( i ) => {
		const c = parseInt( hex.slice( i, i + 2 ), 16 ) / 255;
		return c <= 0.03928 ? c / 12.92 : ( ( c + 0.055 ) / 1.055 ) ** 2.4;
	} );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function ratio( a, b ) {
	const [ hi, lo ] = [ luminance( a ), luminance( b ) ].sort( ( x, y ) => y - x );
	return ( hi + 0.05 ) / ( lo + 0.05 );
}

/**
 * Jetons aplatis vers une variation. Retourne { variation, errors, notes }.
 *
 * @param {Record<string, unknown>} tokens
 * @param {string} title
 * @param {{ description?: string }} options Description affichée sous le titre dans l'éditeur.
 */
export function build( tokens, title, options = {} ) {
	const colors = {};
	const radii = {};
	const errors = [];
	const notes = [];
	for ( const [ path, value ] of Object.entries( tokens ) ) {
		const parts = path.split( '/' );
		const [ group, name ] = parts.slice( -2 ).map( ( s ) => s.toLowerCase() );
		if ( 'couleur' === group || 'color' === group ) {
			const role = 'border' === name ? 'divider' : name;
			if ( ! ROLES.includes( role ) && ! ( role in OPTIONAL ) ) {
				notes.push( `ignoré : ${ path } (rôle inconnu)` );
				continue;
			}
			const hex = toHex( value );
			if ( null === hex ) {
				errors.push( `${ path } : couleur illisible ou transparente` );
			} else {
				colors[ role ] = hex;
			}
		} else if ( 'rayon' === group || 'radius' === group ) {
			const n = toPx( value );
			if ( ! RADII.includes( name ) || null === n || n < 0 ) {
				notes.push( `ignoré : ${ path }` );
			} else {
				radii[ name ] = n;
			}
		} else {
			notes.push( `ignoré : ${ path }` );
		}
	}
	if ( 0 === Object.keys( colors ).length && 0 === Object.keys( radii ).length ) {
		errors.push( 'aucune variable couleur/<rôle> ni rayon/<nom> trouvée' );
	}

	// Contraste sur la palette complète : les rôles absents gardent la couleur du thème.
	const base = Object.fromEntries( theme.settings.color.palette.map( ( c ) => [ c.slug, c.color.toLowerCase() ] ) );
	const full = { ...base, ...colors };
	for ( const role of ROLES.filter( ( r ) => ! ( r in colors ) && Object.keys( colors ).length ) ) {
		notes.push( `${ role } absent : couleur du thème gardée (${ base[ role ] })` );
	}
	const optional = 'button-bg' in colors ? [ [ OPTIONAL_PAIRS, 4.5 ], [ OPTIONAL_UI, 3 ] ] : [];
	for ( const [ list, min ] of [ [ PAIRS, 4.5 ], [ UI, 3 ], ...optional ] ) {
		for ( const [ fg, bg ] of list ) {
			const r = ratio( full[ fg ], full[ bg ] );
			if ( r < min ) {
				errors.push( `${ fg } sur ${ bg } : ${ r.toFixed( 2 ) }:1, sous ${ min }:1` );
			}
		}
	}

	const settings = {};
	if ( Object.keys( colors ).length ) {
		settings.color = {};
		// Duotones du dépôt : contraste et accent posés sur la base, ombres d'abord.
		if ( [ 'contrast', 'accent', 'base' ].every( ( r ) => r in colors ) ) {
			settings.color.duotone = [
				{ slug: 'contraste', name: 'Contrast on base', colors: [ colors.contrast, colors.base ] },
				{ slug: 'accent', name: 'Accent on base', colors: [ colors.accent, colors.base ] },
			];
		}
		settings.color.palette = [ ...ROLES, ...Object.keys( OPTIONAL ) ].filter( ( r ) => r in colors ).map( ( slug ) => ( { slug, name: NAMES[ slug ] ?? OPTIONAL[ slug ], color: colors[ slug ] } ) );
	}
	const { cut, ...rest } = radii;
	if ( Object.keys( rest ).length || undefined !== cut ) {
		settings.custom = {};
		if ( Object.keys( rest ).length ) {
			settings.custom.radius = { ...theme.settings.custom.radius, ...Object.fromEntries( Object.entries( rest ).map( ( [ k, v ] ) => [ k, toCss( v ) ] ) ) };
		}
		if ( undefined !== cut ) {
			settings.custom.cut = toCss( cut );
		}
	}
	const description = 'string' === typeof options.description && '' !== options.description ? { description: options.description } : {};
	return { variation: { $schema: 'https://schemas.wp.org/wp/7.1/theme.json', version: 3, title, ...description, settings }, errors, notes };
}

/** Même mise en forme que les variations du dépôt : une entrée de palette ou de duotone par ligne. */
export function format( variation ) {
	return JSON.stringify( variation, null, '\t' )
		.replace( /\{\n\t+"slug": ("[^"]+"),\n\t+"name": ("[^"]+"),\n\t+"color": ("[^"]+")\n\t+\}/g, '{ "slug": $1, "name": $2, "color": $3 }' )
		.replace( /\{\n\t+"slug": ("[^"]+"),\n\t+"name": ("[^"]+"),\n\t+"colors": \[\n\t+("[^"]+"),\n\t+("[^"]+")\n\t+\]\n\t+\}/g, '{ "slug": $1, "name": $2, "colors": [ $3, $4 ] }' ) + '\n';
}

function main( argv ) {
	const files = [];
	let title = '';
	let description = '';
	let out = '';
	for ( let i = 0; i < argv.length; i++ ) {
		if ( '--titre' === argv[ i ] ) {
			title = argv[ ++i ] ?? '';
		} else if ( '--description' === argv[ i ] ) {
			description = argv[ ++i ] ?? '';
		} else if ( '--sortie' === argv[ i ] ) {
			out = argv[ ++i ] ?? '';
		} else {
			files.push( argv[ i ] );
		}
	}
	if ( ! title || ! files.length ) {
		console.error( 'Usage : node tools/figma-variation.mjs --titre "Nom" [--description "Une phrase"] [--sortie variation.json] export.json [autre.json…]' );
		return 2;
	}
	const tokens = {};
	for ( const f of files ) {
		Object.assign( tokens, flatten( JSON.parse( readFileSync( f, 'utf8' ) ) ) );
	}
	const { variation, errors, notes } = build( tokens, title, { description } );
	notes.forEach( ( n ) => console.error( `  ${ n }` ) );
	if ( errors.length ) {
		console.error( `REFUS : ${ errors.length } problème(s) :` );
		errors.forEach( ( e ) => console.error( `- ${ e }` ) );
		return 1;
	}
	if ( out ) {
		writeFileSync( out, format( variation ) );
		console.error( `OK : variation « ${ title } » écrite dans ${ out }.` );
	} else {
		process.stdout.write( format( variation ) );
	}
	return 0;
}

if ( process.argv[ 1 ] === fileURLToPath( import.meta.url ) ) {
	process.exitCode = main( process.argv.slice( 2 ) );
}
