// Tests du script d'éditeur du module Canevas, sans navigateur : un faux objet wp enregistre
// les filtres, puis on vérifie ce qu'ils font aux blocs pour une graphiste et en mode Simple.
// Lancer : node packages/fluffless-suite/tests/canvas-editor-test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync( new URL( '../assets/canvas-editor.js', import.meta.url ), 'utf8' );
const BLOCKS = [ 'core/columns', 'core/column', 'core/details', 'core/media-text' ];

/** Charge le script avec les données de la personne et renvoie ce qu'il a enregistré. */
function load( canDesign ) {
	const filters = {};
	const plugins = [];
	const supports = { 'core/group': { background: { backgroundImage: true } } };
	const noop = () => null;
	const wp = {
		element: { createElement: noop, Fragment: 'Fragment', createPortal: noop, useEffect: noop, useState: () => [ null, noop ] },
		i18n: { __: ( s ) => s, sprintf: ( s ) => s },
		hooks: { addFilter: ( hook, ns, cb ) => ( filters[ hook ] = [ ...( filters[ hook ] || [] ), cb ] ) },
		compose: { createHigherOrderComponent: ( fn ) => fn },
		blocks: {
			getBlockSupport: ( name, path ) => path.reduce( ( o, k ) => ( o ? o[ k ] : undefined ), supports[ name ] ),
			hasBlockSupport: () => true,
		},
		blockEditor: {},
		components: {},
		data: { useSelect: noop, useDispatch: () => ( {} ) },
		plugins: { registerPlugin: ( name ) => plugins.push( name ) },
	};
	const window = { wp, flufflessCanvas: { canDesign, blocks: BLOCKS, warnKb: 400, breakpoint: 782 } };
	vm.runInNewContext( source, { window, document: {} } );
	const register = ( settings, name ) => filters[ 'blocks.registerBlockType' ].reduce( ( s, cb ) => cb( s, name ), settings );
	return { filters, plugins, register, config: window.flufflessCanvas };
}

// Graphiste : support natif ajouté, supports existants gardés, attribut mobile, bascule enregistrée.
{
	const { filters, plugins, register, config } = load( true );
	const columns = register( { attributes: {}, supports: { background: { gradient: true } } }, 'core/columns' );
	assert.equal( columns.supports.background.backgroundImage, true, 'Colonnes : image de fond attendue' );
	assert.equal( columns.supports.background.gradient, true, 'Colonnes : dégradé existant écrasé' );
	assert.ok( columns.attributes.flufflessMobileBg, 'Colonnes : attribut de fond mobile attendu' );
	const group = register( { attributes: {}, supports: { background: { backgroundImage: true } } }, 'core/group' );
	assert.ok( group.attributes.flufflessMobileBg, 'Groupe : attribut de fond mobile attendu' );
	const paragraph = register( { attributes: {}, supports: {} }, 'core/paragraph' );
	assert.equal( paragraph.attributes.flufflessMobileBg, undefined, 'Paragraphe : aucun attribut attendu' );
	assert.equal( paragraph.supports.background, undefined, 'Paragraphe : aucun support attendu' );
	const calque = register( { attributes: {}, supports: {} }, 'fluffless/calque' );
	assert.notEqual( calque.supports.inserter, false, 'graphiste : le calque doit rester dans l\'insertion' );
	assert.deepEqual( plugins, [ 'fluffless-canvas' ], 'graphiste : bascule Visuel / HTML attendue' );
	assert.equal( filters[ 'editor.BlockEdit' ].length, 2, 'panneau de fond mobile ou bouton HTML des sections verrouillées non enregistré' );
	assert.equal( typeof config.SizeWarning, 'function', 'alerte de poids non exposée au bloc calque' );
}

// Mode Simple : pas de nouveau contrôle, mais l'attribut reste déclaré pour ne rien perdre.
{
	const { plugins, register } = load( false );
	const columns = register( { attributes: {}, supports: {} }, 'core/columns' );
	assert.equal( columns.supports.background, undefined, 'mode Simple : image de fond ajoutée à tort' );
	assert.ok( columns.attributes.flufflessMobileBg, 'mode Simple : attribut perdu, un réglage de graphiste disparaîtrait' );
	const calque = register( { attributes: {}, supports: {} }, 'fluffless/calque' );
	assert.equal( calque.supports.inserter, false, 'mode Simple : le calque ne doit pas être insérable' );
	assert.deepEqual( plugins, [], 'mode Simple : aucune bascule ajoutée' );
}

// Couche du calque : mêmes classes et variables que Canvas::render_calque(), pour que l'aperçu suive le site.
{
	const { config } = load( true );
	const full = config.calqueLayer( {
		x: 10, y: 90, width: 40, rotate: -15, opacity: 35, blend: 'multiply', layer: 'front', hideMobile: true,
		pin: 'top-right', clip: 'clip', clipMargin: 8, minWidth: '12rem', maxWidth: '10vw', xMobile: 20, widthMobile: 60,
	} );
	assert.equal( full.className, 'fluffless-calque-layer is-front is-pin-top-right is-clip is-hide-mobile', 'classes de la couche différentes du rendu serveur' );
	assert.deepEqual( JSON.parse( JSON.stringify( full.style ) ), {
		'--fluffless-x': '10%', '--fluffless-y': '90%', '--fluffless-w': '40', '--fluffless-r': '-15deg', '--fluffless-o': '0.35',
		'--fluffless-blend': 'multiply', '--fluffless-min': '12rem', '--fluffless-clip': '8px', '--fluffless-xm': '20%', '--fluffless-wm': '60',
	}, 'variables de la couche différentes du rendu serveur (10vw doit être ignoré)' );
	const empty = config.calqueLayer( { pin: 'middle', clip: 'hidden', minWidth: 'calc(1px)' } );
	assert.equal( empty.className, 'fluffless-calque-layer is-back is-pin-center is-clip', 'défauts de la couche inattendus' );
	assert.deepEqual( JSON.parse( JSON.stringify( empty.style ) ), { '--fluffless-x': '50%', '--fluffless-y': '50%', '--fluffless-w': '30', '--fluffless-r': '0deg', '--fluffless-o': '1' }, 'variables par défaut inattendues' );
}

console.log( 'OK : canvas-editor, 20 contrôles.' );
