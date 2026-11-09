#!/usr/bin/env node
// Jetons Figma (format DTCG) tirés de theme.json et des variations de couleur.
// Un fichier par collection et par mode, dans design/figma/ : Figma les importe comme variables
// (Variables > Importer), Tokens Studio aussi. À relancer après tout changement de theme.json.
// Usage : node tools/figma-tokens.mjs

import { readFileSync, writeFileSync, mkdirSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const THEME = 'packages/fluffless';
const OUT = 'design/figma';
const REM = 16;

const theme = JSON.parse( readFileSync( join( THEME, 'theme.json' ), 'utf8' ) );

// Noms de mode en français (les titres de variation sont en anglais, pour la traduction de WP).
const MODES = {
	'': 'Défaut',
	brut: 'Brut',
	chaleureux: 'Chaleureux',
	clair: 'Clair',
	'contraste-eleve': 'Contraste élevé',
	doux: 'Doux',
	editorial: 'Éditorial',
};

// Polices système du thème : leur plus proche équivalent disponible dans Figma (Google Fonts).
const FIGMA_FONTS = {
	sans: 'Inter',
	humanist: 'Source Sans 3',
	industrial: 'Barlow Semi Condensed',
	rounded: 'Nunito',
	serif: 'Source Serif 4',
	didone: 'Bodoni Moda',
	slab: 'Roboto Slab',
	mono: 'Source Code Pro',
};

/** « 1.25rem », « 12px », « 0 » en pixels ; null si la valeur dépend de la fenêtre (vw). */
function px( value ) {
	const m = /^(-?[\d.]+)(rem|px|em)?$/.exec( String( value ).trim() );
	if ( ! m ) {
		return null;
	}
	const n = parseFloat( m[ 1 ] );
	return Math.round( ( 'rem' === m[ 2 ] || 'em' === m[ 2 ] ? n * REM : n ) * 100 ) / 100;
}

/** Bornes d'un clamp(min, idéal, max) : [min, max] en pixels. */
function clampBounds( value ) {
	const m = /^clamp\(\s*([^,]+),[^,]+,\s*([^)]+)\)$/.exec( value );
	return m ? [ px( m[ 1 ] ), px( m[ 2 ] ) ] : [ px( value ), px( value ) ];
}

const color = ( hex ) => ( { $type: 'color', $value: hex } );
const dim = ( n, description ) => ( { $type: 'number', $value: n, ...( description ? { $description: description } : {} ) } );

function palette( json ) {
	const out = {};
	for ( const c of json.settings?.color?.palette ?? [] ) {
		out[ c.slug ] = color( c.color.toLowerCase() );
	}
	return out;
}

function radius( json ) {
	const r = { ...theme.settings.custom.radius, ...( json.settings?.custom?.radius ?? {} ) };
	const cut = json.settings?.custom?.cut ?? theme.settings.custom.cut;
	const out = {};
	for ( const [ k, v ] of Object.entries( r ) ) {
		out[ k ] = dim( px( v ) );
	}
	out.cut = dim( px( cut ), 'Coin coupé (style Coupé)' );
	return out;
}

const files = {};
const add = ( collection, mode, tokens ) => {
	files[ `${ collection }.${ mode }.tokens.json` ] = tokens;
};

// Couleurs et forme : un mode par variation.
const variations = { '': theme };
for ( const f of readdirSync( join( THEME, 'styles' ) ).filter( ( f ) => f.endsWith( '.json' ) ) ) {
	variations[ f.replace( /\.json$/, '' ) ] = JSON.parse( readFileSync( join( THEME, 'styles', f ), 'utf8' ) );
}
for ( const [ slug, json ] of Object.entries( variations ) ) {
	const mode = MODES[ slug ] ?? slug;
	add( 'Couleurs', mode, { couleur: { ...palette( theme ), ...palette( json ) } } );
	add( 'Forme', mode, { rayon: radius( json ) } );
}

// Tailles et espacements fluides : mode Mobile (minimum) et Bureau (maximum).
for ( const [ i, mode ] of [ 'Mobile', 'Bureau' ].entries() ) {
	const taille = {};
	for ( const s of theme.settings.typography.fontSizes ) {
		taille[ s.slug ] = dim( px( s.fluid ? ( i ? s.fluid.max : s.fluid.min ) : s.size ), s.name );
	}
	const espace = {};
	for ( const s of theme.settings.spacing.spacingSizes ) {
		espace[ s.slug ] = dim( clampBounds( s.size )[ i ], s.name );
	}
	add( 'Mesures', mode, {
		taille,
		espace,
		mise: { contenu: dim( px( theme.settings.layout.contentSize ) ), large: dim( px( theme.settings.layout.wideSize ) ) },
	} );
}

// Polices : équivalent Figma, la pile CSS du thème en description.
const police = {};
for ( const f of theme.settings.typography.fontFamilies ) {
	police[ f.slug ] = { $type: 'string', $value: FIGMA_FONTS[ f.slug ] ?? 'Inter', $description: f.fontFamily };
}
const interligne = {};
for ( const [ k, v ] of Object.entries( theme.settings.custom[ 'line-height' ] ) ) {
	interligne[ k ] = dim( Math.round( parseFloat( v ) * 100 ), 'Pourcentage de la taille' );
}
add( 'Typographie', 'Défaut', { police, interligne } );

mkdirSync( OUT, { recursive: true } );
for ( const [ name, tokens ] of Object.entries( files ) ) {
	writeFileSync( join( OUT, name ), JSON.stringify( tokens, null, '\t' ) + '\n' );
}
console.log( `OK : ${ Object.keys( files ).length } fichiers de jetons dans ${ OUT }/.` );
