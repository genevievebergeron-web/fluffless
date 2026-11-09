// Tests des variations du Groupe natif posées par assets/sections-editor.js (Section, En-tête de
// section), sans navigateur : un faux objet wp capte les enregistrements. Les tableaux venant du
// contexte vm ont un autre prototype : Array.from avant deepEqual.
// Lancer : node packages/fluffless-suite/tests/sections-variations-test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync( new URL( '../assets/sections-editor.js', import.meta.url ), 'utf8' );
const variations = [];
const noop = () => null;
const wp = {
	element: { createElement: noop, Fragment: 'Fragment' },
	i18n: { __: ( s ) => s },
	blocks: { createBlock: noop, registerBlockVariation: ( block, v ) => variations.push( { block, ...v } ) },
	data: { select: noop, dispatch: noop, useSelect: noop },
	blockEditor: { useBlockProps: noop, useInnerBlocksProps: noop, InspectorControls: 'Inspector', InnerBlocks: {} },
	components: {},
};
const window = { wp };
vm.runInNewContext( source, { window } );

// Les aides communes restent exposées aux blocs.
assert.equal( typeof window.flufflessSections.shell, 'function', 'aides communes perdues' );

// Deux variations du Groupe, chacune dans l'insertion, reconnue par sa classe, sans verrou de gabarit.
assert.deepEqual( variations.map( ( v ) => [ v.block, v.name ] ), [ [ 'core/group', 'fluffless-conteneur' ], [ 'core/group', 'fluffless-en-tete' ] ], 'variations attendues' );
for ( const v of variations ) {
	assert.ok( v.scope.includes( 'inserter' ) && v.scope.includes( 'transform' ), `${ v.name } : hors insertion ou transformation` );
	assert.equal( v.attributes.layout.type, 'constrained', `${ v.name } : largeur non contrainte` );
	assert.equal( v.attributes.templateLock, undefined, `${ v.name } : gabarit verrouillé` );
	assert.equal( typeof v.isActive, 'function', `${ v.name } : isActive doit être une fonction (jeton de classe)` );
	assert.equal( v.isActive.length, 2, `${ v.name } : isActive( attributs du bloc, attributs de la variation )` );
	assert.ok( v.isActive( { className: 'a ' + v.attributes.className + ' b' }, v.attributes ), `${ v.name } : classe entourée non reconnue` );
	assert.ok( v.isActive( { className: v.attributes.className }, v.attributes ), `${ v.name } : classe seule non reconnue` );
	assert.ok( ! v.isActive( { className: v.attributes.className + '-x' }, v.attributes ), `${ v.name } : préfixe pris pour la classe` );
	assert.ok( ! v.isActive( {}, v.attributes ), `${ v.name } : sans classe, active à tort` );
	// WordPress appelle isActive( attributs du bloc, attributs de la variation ), jamais la variation.
	assert.ok( ! v.isActive( { className: v.attributes.className }, { className: 'autre' } ), `${ v.name } : isActive ignore son second argument` );
	assert.ok( v.title && v.description, `${ v.name } : titre ou description manquants` );
}
const [ section, header ] = variations;
assert.equal( section.attributes.tagName, 'section', 'Section : balise section attendue' );
assert.equal( section.innerBlocks, undefined, 'Section : aucun bloc imposé' );
assert.equal( header.attributes.tagName, undefined, 'En-tête : reste un div' );
assert.deepEqual( Array.from( header.innerBlocks, ( b ) => b[ 0 ] ), [ 'core/paragraph', 'core/heading', 'core/paragraph' ], 'En-tête : sur-titre, titre, paragraphe' );
assert.equal( header.innerBlocks[ 1 ][ 1 ].level, 2, 'En-tête : titre de niveau 2' );

console.log( 'OK : variations du Groupe (sections-editor.js).' );
