// Tests du générateur de palettes (assets/admin/palette-core.js), sans navigateur.
// Lancer : node packages/fluffless-suite/tests/palette-generator-test.mjs (ou tools/run-tests.sh palette)
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const core = require( '../assets/admin/palette-core.js' );

const failures = [];
let checks = 0;
const assert = ( ok, message ) => {
	checks++;
	if ( ! ok ) {
		failures.push( message );
	}
};

// Hasard reproductible (mulberry32) : un échec se rejoue avec la même graine.
const seeded = ( start ) => {
	let seed = start;
	return () => {
		seed = ( seed + 0x6d2b79f5 ) | 0;
		let t = Math.imul( seed ^ ( seed >>> 15 ), 1 | seed );
		t = ( t + Math.imul( t ^ ( t >>> 7 ), 61 | t ) ) ^ t;
		return ( ( t ^ ( t >>> 14 ) ) >>> 0 ) / 4294967296;
	};
};

const HEX = /^#[0-9a-f]{6}$/;
assert( core.ROLES.length === 9, '9 rôles attendus, comme theme.json' );

// Toutes les combinaisons d'entrées donnent une palette complète et lisible.
for ( const mood of Object.keys( core.MOODS ) ) {
	for ( const strict of Object.keys( core.STRICT ) ) {
		for ( const harmony of core.HARMONIES ) {
			for ( let seed = 1; seed <= 25; seed++ ) {
				const pal = core.generate( { mood, strict, harmony, rand: seeded( seed ) } );
				const id = `${ mood }/${ strict }/${ harmony }/graine ${ seed }`;
				assert( core.ROLES.every( ( r ) => HEX.test( pal[ r ] ?? '' ) ), `${ id } : rôle manquant ou couleur invalide ${ JSON.stringify( pal ) }` );
				const bad = core.check( pal );
				assert( bad.length === 0, `${ id } : contraste insuffisant (${ bad.join( ', ' ) })` );
				assert( ! Object.values( pal ).includes( '#ffffff' ), `${ id } : blanc pur` );
				const score = core.score( pal );
				assert( Number.isInteger( score ) && score >= 0 && score <= 100, `${ id } : indice hors bornes (${ score })` );
			}
		}
	}
}

// Cadenas : la couleur verrouillée est gardée telle quelle, le reste se construit autour.
const locked = core.generate( {
	mood: 'variee',
	strict: 'equilibre',
	harmony: 'libre',
	locked: { accent: '#f4b400', base: '#12110f' },
	rand: seeded( 7 ),
} );
assert( locked.accent === '#f4b400' && locked.base === '#12110f', 'couleurs verrouillées modifiées' );
assert( core.check( locked ).length === 0, `autour de couleurs lisibles, contraste insuffisant (${ core.check( locked ).join( ', ' ) })` );

// Couleur de marque illisible en texte : gardée, et signalée plutôt que corrigée en douce.
const brand = core.generate( {
	mood: 'variee',
	strict: 'max',
	harmony: 'libre',
	locked: { accent: '#1b365d' },
	rand: seeded( 3 ),
} );
assert( brand.accent === '#1b365d', 'couleur de marque modifiée' );
assert( core.check( brand ).some( ( p ) => p.startsWith( 'accent/' ) ), 'échec de contraste de la couleur de marque non signalé' );

// check() suit les paires de tools/check-contrast.mjs.
assert( core.check( { ...locked, muted: locked.base } ).includes( 'muted/base' ), 'paire muted/base non vérifiée' );
assert( core.check( { ...locked, 'on-accent': locked.accent } ).includes( 'on-accent/accent' ), 'paire on-accent/accent non vérifiée' );
// Rôle facultatif button-bg : absent, aucune paire; présent, texte à 4,5:1 et contour à 3:1.
assert( core.OPTIONAL.includes( 'button-bg' ) && ! core.ROLES.includes( 'button-bg' ), 'button-bg doit rester hors des rôles obligatoires' );
assert( ! core.check( locked ).some( ( p ) => p.includes( 'button-bg' ) ), 'paire button-bg vérifiée sans le rôle' );
const dark = { ...locked, base: '#12110f', surface: '#1f1d1a', 'on-accent': '#12110f' };
assert( core.check( { ...dark, 'button-bg': '#6fd3c1' } ).length === core.check( dark ).length, 'button-bg lisible refusé' );
const grey = core.check( { ...dark, 'button-bg': '#66625c' } ).filter( ( p ) => p.includes( 'button-bg' ) );
assert( grey.join() === 'on-accent/button-bg,button-bg/surface', `seuils du bouton (4,5:1 texte, 3:1 contour) : ${ grey.join( ', ' ) }` );
// Style des boutons (Palette::button()) : bordure déclarée à la place du fond, 3:1 sous le texte large.
const pale = { ...dark, contrast: '#f1ebdf', divider: '#3b3730', 'button-bg': '#66625c' };
const only = ( button ) => core.check( pale, button ).filter( ( p ) => p.includes( 'button' ) ).join();
assert( only( { border: 'contrast', large: false } ) === 'on-accent/button-bg', `bordure contrast : ${ only( { border: 'contrast', large: false } ) }` );
assert( only( { border: '', large: true } ) === 'button-bg/surface', `texte large : ${ only( { border: '', large: true } ) }` );
assert( only( { border: '#f1ebdf', large: true } ) === '', `bordure hexadécimale : ${ only( { border: '#f1ebdf', large: true } ) }` );
assert( only( { border: 'divider', large: true } ) === 'button-border/base,button-border/surface', `bordure pâle : ${ only( { border: 'divider', large: true } ) }` );

// Indice : une palette sombre coûte moins qu'une claire (modèle OLED).
const light = {
	base: '#f4f1ea',
	surface: '#e8e2d4',
	contrast: '#1c1b19',
	muted: '#57524a',
	divider: '#c9c1ae',
	accent: '#1f4e3d',
	'on-accent': '#f4f1ea',
	'accent-2': '#7b3f99',
	alert: '#9e2a1f',
};
assert( core.score( locked ) > core.score( light ), 'une palette sombre doit obtenir un meilleur indice qu’une claire' );

// Mélange (bordure).
assert( core.mix( '#000000', '#ffffff', 0.5 ) === '#808080', 'mélange 50 % noir et blanc' );

if ( failures.length ) {
	console.error( `${ failures.length } échec(s) sur ${ checks } vérifications :\n- ${ failures.slice( 0, 20 ).join( '\n- ' ) }` );
	process.exit( 1 );
}
console.log( `OK : générateur de palettes, ${ checks } vérifications.` );
