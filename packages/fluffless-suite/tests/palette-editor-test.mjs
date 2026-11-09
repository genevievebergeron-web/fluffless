// Panneau Palette de l'éditeur de site : wp simulé, un rendu, une couleur gardée, Générer puis Appliquer.
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const root = new URL( '../assets/', import.meta.url ).pathname;
const require = createRequire( import.meta.url );
const core = require( root + 'admin/palette-core.js' );

const state = [];
let slot = 0;
const edits = [];
const record = { settings: { color: { palette: { theme: [ { slug: 'accent', name: 'Accent', color: '#E0A800' } ] } }, custom: { keep: 1 } } };
const h = ( type, props, ...children ) => ( { type: type.displayName || type, props: props || {}, children: children.flat() } );
const comp = ( name ) => Object.assign( ( props ) => h( name, props ), { displayName: name } );
let plugin = null;
const wp = {
	element: { createElement: h, useState: ( init ) => { const i = slot++; if ( ! ( i in state ) ) state[ i ] = init; return [ state[ i ], ( v ) => { state[ i ] = v; } ]; } },
	data: {
		useSelect: ( fn ) => fn( () => ( { __experimentalGetCurrentGlobalStylesId: () => 7, getEditedEntityRecord: () => record } ) ),
		useDispatch: () => ( { editEntityRecord: ( ...a ) => edits.push( a ) } ),
	},
	components: Object.fromEntries( [ 'PanelBody', 'SelectControl', 'Button', 'CheckboxControl', 'ColorIndicator', 'Notice', 'ExternalLink' ].map( ( n ) => [ n, comp( n ) ] ) ),
	editor: { PluginSidebar: comp( 'PluginSidebar' ) },
	plugins: { registerPlugin: ( name, s ) => { plugin = s; } },
};
const roles = Object.fromEntries( core.ROLES.map( ( r ) => [ r, r.toUpperCase() ] ) );
const palette = { base: '#12110f', surface: '#1f1d1a', contrast: '#f1ebdf', muted: '#b3aa9a', divider: '#3b3730', accent: '#f4b400', 'on-accent': '#12110f', 'accent-2': '#6fd3c1', alert: '#ff8577' };
const options = { mood: { label: 'M', choices: { variee: 'V' } }, strict: { label: 'S', choices: { equilibre: 'E' } }, harmony: { label: 'H', choices: { libre: 'L' } } };
const i18n = { title: 'Palette', generate: 'Gen', keep: 'k', score: 'Indice %d/100', ok: 'ok', bad: 'bad %s', pair: '%1$s on %2$s', apply: 'Apply', save: 's', screen: 'sc' };
const sandbox = { window: { wp, flPalette: core, flufflessPalette: { palette, roles, names: roles, options, screen: '/x', i18n } } };
sandbox.window.window = sandbox.window;
vm.runInNewContext( readFileSync( root + 'palette-editor.js', 'utf8' ), sandbox.window );

const find = ( node, pred, out = [] ) => { if ( node && 'object' === typeof node ) { if ( pred( node ) ) out.push( node ); ( node.children || [] ).forEach( ( c ) => find( c, pred, out ) ); } return out; };
const render = () => { slot = 0; const side = plugin.render(); return side.children[ 0 ].type( side.children[ 0 ].props ); };
const fail = ( m ) => { console.error( 'ÉCHEC : ' + m ); process.exit( 1 ); };

if ( ! plugin ) fail( 'plugin non enregistré' );
let tree = render();
const labels = find( tree, ( n ) => 'CheckboxControl' === n.type ).map( ( n ) => n.props.label );
if ( 9 !== labels.length || ! labels.includes( 'ACCENT #e0a800' ) ) fail( 'couleurs en cours d’édition non lues : ' + labels.join( ', ' ) );
const btn = ( t ) => find( tree, ( n ) => 'Button' === n.type && n.children.includes( t ) )[ 0 ];
if ( ! btn( 'Apply' ).props.disabled ) fail( 'Appliquer actif sans palette générée' );
find( tree, ( n ) => 'CheckboxControl' === n.type )[ 5 ].props.onChange( true ); // garde l'accent
tree = render();
btn( 'Gen' ).props.onClick();
tree = render();
if ( btn( 'Apply' ).props.disabled ) fail( 'Appliquer inactif après génération' );
btn( 'Apply' ).props.onClick();
const theme = edits[ 0 ]?.[ 3 ]?.settings?.color?.palette?.theme;
if ( 'globalStyles' !== edits[ 0 ]?.[ 1 ] || 7 !== edits[ 0 ][ 2 ] || 9 !== theme?.length ) fail( 'écriture des styles globaux : ' + JSON.stringify( edits ) );
if ( 1 !== edits[ 0 ][ 3 ].settings.custom?.keep ) fail( 'clé étrangère perdue' );
const written = Object.fromEntries( theme.map( ( c ) => [ c.slug, c.color ] ) );
if ( '#e0a800' !== written.accent ) fail( 'couleur gardée non respectée : ' + written.accent );
if ( core.check( written ).length ) fail( 'palette écrite illisible' );
if ( 'button-bg' in written ) fail( 'button-bg écrit sans avoir été déclaré' );

// Rôle facultatif déclaré dans les styles en cours : une palette générée le garde.
record.settings.color.palette.theme.push( { slug: 'button-bg', name: 'Fond des boutons', color: '#6FD3C1' }, { slug: 'marque-3', name: 'Marque 3', color: '#123456' }, { slug: 'border', name: 'Ancien filet', color: '#3b3730' } );
state.length = 0;
edits.length = 0;
tree = render();
btn( 'Gen' ).props.onClick();
tree = render();
btn( 'Apply' ).props.onClick();
const kept = edits[ 0 ]?.[ 3 ]?.settings?.color?.palette?.theme ?? [];
if ( 11 !== kept.length || '#6fd3c1' !== kept.find( ( c ) => 'button-bg' === c.slug )?.color ) fail( 'button-bg perdu à l’application : ' + JSON.stringify( kept ) );
if ( '#123456' !== kept.find( ( c ) => 'marque-3' === c.slug )?.color || kept.some( ( c ) => 'border' === c.slug ) ) fail( 'couleur hors rôles perdue ou border recopié : ' + JSON.stringify( kept ) );

// Sans palette dans les styles en cours, les couleurs hors rôles viennent du site (variation).
delete record.settings.color.palette;
sandbox.window.flufflessPalette.extras = [ { slug: 'marque-4', name: 'Marque 4', color: '#654321' } ];
state.length = 0;
edits.length = 0;
tree = render();
btn( 'Gen' ).props.onClick();
tree = render();
btn( 'Apply' ).props.onClick();
const fromSite = edits[ 0 ]?.[ 3 ]?.settings?.color?.palette?.theme ?? [];
if ( 10 !== fromSite.length || '#654321' !== fromSite[ 9 ].color ) fail( 'couleur hors rôles du site perdue : ' + JSON.stringify( fromSite ) );
console.log( 'OK : panneau rendu, génération avec couleur gardée, écriture des 9 rôles dans les styles globaux, button-bg et couleurs hors rôles gardés.' );
