#!/usr/bin/env node
// Test hors réseau de tools/figma-variation.mjs : aller-retour avec les jetons générés par
// figma-tokens.mjs, formats d'export de Figma et de Tokens Studio, refus au contraste.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { build, flatten, format, toHex } from './figma-variation.mjs';

const root = join( import.meta.dirname, '..' );
const read = ( p ) => JSON.parse( readFileSync( join( root, p ), 'utf8' ) );
const tokens = ( ...files ) => Object.assign( {}, ...files.map( ( f ) => flatten( read( `design/figma/${ f }.tokens.json` ) ) ) );

// Aller-retour : jetons de la variation Clair vers les réglages identiques à styles/clair.json.
const clair = build( tokens( 'Couleurs.Clair' ), 'Light', { description: read( 'packages/fluffless/styles/clair.json' ).description } );
assert.deepEqual( clair.errors, [] );
// Les styles (titres, boutons) sont écrits à la main : seuls les réglages (palette) viennent de Figma.
const clairFile = read( 'packages/fluffless/styles/clair.json' );
assert.deepEqual( { ...clair.variation, styles: clairFile.styles }, clairFile );

// Formes : Doux redonne ses rayons et son coin coupé.
const doux = build( tokens( 'Couleurs.Doux', 'Forme.Doux' ), 'Soft' );
assert.deepEqual( doux.errors, [] );
assert.deepEqual( doux.variation.settings.custom, read( 'packages/fluffless/styles/doux.json' ).settings.custom );

// Couleurs : chaîne, objet hex, composantes sRGB ; transparence refusée.
assert.equal( toHex( '#AABBCC' ), '#aabbcc' );
assert.equal( toHex( '#aabbccff' ), '#aabbcc' );
assert.equal( toHex( { colorSpace: 'srgb', components: [ 1, 0.5, 0 ], alpha: 1, hex: '#FF8000' } ), '#ff8000' );
assert.equal( toHex( { colorSpace: 'srgb', components: [ 1, 0.5, 0 ], alpha: 1 } ), '#ff8000' );
assert.equal( toHex( { components: [ 0, 0, 0 ], alpha: 0.5 } ), null );
assert.equal( toHex( '#abc' ), null );

// Export natif de Figma et Tokens Studio ; l'ancien nom « border » devient « divider ».
const native = flatten( { couleur: { border: { $type: 'color', $value: { colorSpace: 'srgb', components: [ 0.8, 0.8, 0.8 ], alpha: 1 } } } } );
assert.equal( build( native, 'T' ).variation.settings.color.palette[ 0 ].slug, 'divider' );
const studio = flatten( { Marque: { rayon: { soft: { value: '8px', type: 'borderRadius' } } } } );
assert.equal( build( studio, 'T' ).variation.settings.custom.radius.soft, '0.5rem' );

// Contraste : un accent illisible sur la base fait refuser la variation.
const bad = build( { ...tokens( 'Couleurs.Clair' ), 'Couleurs/couleur/accent': '#f5f5f5' }, 'T' );
assert.ok( bad.errors.some( ( e ) => e.startsWith( 'accent sur base' ) ) );

// Rôle facultatif button-bg et rayons large/small ; button-bg trop pâle sur la base : refus.
const extra = build( { ...tokens( 'Couleurs.Clair' ), 'x/couleur/button-bg': '#1d4ed8', 'x/rayon/large': 24, 'x/rayon/small': 4 }, 'T' );
assert.deepEqual( extra.errors, [] );
assert.deepEqual( extra.variation.settings.color.palette.at( -1 ), { slug: 'button-bg', name: 'Button background', color: '#1d4ed8' } );
assert.equal( extra.variation.settings.custom.radius.large, '1.5rem' );
assert.equal( extra.variation.settings.custom.radius.small, '0.25rem' );
assert.ok( build( { ...tokens( 'Couleurs.Clair' ), 'x/couleur/button-bg': '#fafafa' }, 'T' ).errors.some( ( e ) => e.startsWith( 'button-bg sur base' ) ) );

// Rien de reconnu : refus.
assert.ok( build( { 'x/y/z': 1 }, 'T' ).errors.length );

console.log( 'figma-variation : OK' );
