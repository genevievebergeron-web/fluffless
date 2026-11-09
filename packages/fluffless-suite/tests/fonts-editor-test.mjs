// Panneau Polices de l'éditeur de site : wp simulé, Appliquer un duo, duo système, retour aux polices du thème.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const root = new URL( '../assets/', import.meta.url ).pathname;
const state = [];
let slot = 0;
const edits = [];
const calls = [];
const other = { slug: 'maison', name: 'Maison', fontFamily: 'Maison' };
let record = {
	settings: { typography: { fontFamilies: { custom: [ other, { slug: 'gloock' } ] } }, custom: { keep: 1 } },
	styles: { typography: { fontFamily: 'var:preset|font-family|serif' }, color: { text: 'x' } },
};
const h = ( type, props, ...children ) => ( { type: type.displayName || type, props: props || {}, children: children.flat() } );
const comp = ( name ) => Object.assign( ( props ) => h( name, props ), { displayName: name } );
let plugin = null;
let reply = null;
const wp = {
	element: { createElement: h, useState: ( init ) => { const i = slot++; if ( ! ( i in state ) ) state[ i ] = init; return [ state[ i ], ( v ) => { state[ i ] = v; } ]; } },
	data: {
		useSelect: ( fn ) => fn( () => ( { __experimentalGetCurrentGlobalStylesId: () => 7, getEditedEntityRecord: () => record } ) ),
		useDispatch: () => ( { editEntityRecord: ( ...a ) => { edits.push( a ); record = { ...record, ...a[ 3 ] }; } } ),
	},
	apiFetch: ( req ) => { calls.push( req ); return reply(); },
	components: Object.fromEntries( [ 'PanelBody', 'Button', 'Notice', 'ExternalLink' ].map( ( n ) => [ n, comp( n ) ] ) ),
	editor: { PluginSidebar: comp( 'PluginSidebar' ) },
	plugins: { registerPlugin: ( name, s ) => { plugin = s; } },
};
const pairs = [
	{ heading: 'instrument-serif', body: 'instrument-sans', names: [ 'IS', 'ISans' ], weight: '60 ko', note: 'n', title: '/t.svg', text: '/p.webp' },
	{ heading: 'recursive', body: 'system', names: [ 'Rec', 'Sys' ], weight: '40 ko', note: 'n', title: '/r.webp', text: '' },
];
const i18n = { title: 'Polices', intro: 'i', pair: '%1$s + %2$s', apply: 'Apply', busy: 'Busy', current: 'In use', reset: 'Reset', show: 'Show', hide: 'Hide', save: 's', screen: 'sc', sample: 'Sample paragraph set in %s' };
const sandbox = { window: { wp, flufflessFonts: { pairs, collection: [ 'instrument-serif', 'instrument-sans', 'recursive', 'gloock' ], backup: null, screen: '/x', i18n } } };
sandbox.window.window = sandbox.window;
vm.runInNewContext( readFileSync( root + 'fonts-editor.js', 'utf8' ), sandbox.window );

const find = ( node, pred, out = [] ) => { if ( node && 'object' === typeof node ) { if ( pred( node ) ) out.push( node ); ( node.children || [] ).forEach( ( c ) => find( c, pred, out ) ); } return out; };
const render = () => { slot = 0; const side = plugin.render(); return side.children[ 0 ].type( side.children[ 0 ].props ); };
const fail = ( m ) => { console.error( 'ÉCHEC : ' + m ); process.exit( 1 ); };
const buttons = ( tree, label ) => find( tree, ( n ) => 'Button' === n.type && n.children.includes( label ) );
const fam = ( slug ) => ( { slug, name: slug, fontFamily: slug, fontFace: [ { src: '/wp-content/fonts/' + slug + '.woff2' } ] } );
const backup = { body: 'var:preset|font-family|serif', heading: null };

if ( ! plugin ) fail( 'plugin non enregistré' );
let tree = render();
if ( 2 !== buttons( tree, 'Apply' ).length || buttons( tree, 'Reset' ).length ) fail( 'état initial : deux duos à appliquer, pas de retour' );
const imgs = () => find( tree, ( n ) => 'img' === n.type ).map( ( n ) => n.props.src ).join( ',' );
if ( '/t.svg,/r.webp' !== imgs() || 1 !== buttons( tree, 'Show' ).length ) fail( 'aperçus : ' + imgs() );
buttons( tree, 'Show' )[ 0 ].props.onClick();
tree = render();
if ( '/t.svg,/p.webp,/r.webp' !== imgs() || 1 !== buttons( tree, 'Hide' ).length ) fail( 'paragraphe non affiché : ' + imgs() );
// P2-08 : le paragraphe déplié s'annonce aux lecteurs d'écran, avec le nom de la police du texte.
const sample = find( tree, ( n ) => 'img' === n.type && '/p.webp' === n.props.src )[ 0 ];
if ( 'Sample paragraph set in ISans' !== sample?.props?.alt ) fail( 'paragraphe sans texte de remplacement : ' + JSON.stringify( sample?.props?.alt ) );
buttons( tree, 'Hide' )[ 0 ].props.onClick();
tree = render();

// 1. Duo à deux familles.
reply = () => Promise.resolve( { families: [ fam( 'instrument-serif' ), fam( 'instrument-sans' ) ], backup } );
await buttons( tree, 'Apply' )[ 0 ].props.onClick();
if ( 0 !== calls[ 0 ]?.data?.pair || 'POST' !== calls[ 0 ].method ) fail( 'appel REST : ' + JSON.stringify( calls ) );
let w = edits[ 0 ]?.[ 3 ];
if ( 'globalStyles' !== edits[ 0 ]?.[ 1 ] || 7 !== edits[ 0 ][ 2 ] ) fail( 'écriture hors des styles globaux' );
const slugs = w.settings.typography.fontFamilies.custom.map( ( f ) => f.slug ).join( ',' );
if ( 'maison,instrument-serif,instrument-sans' !== slugs ) fail( 'familles actives : ' + slugs );
if ( 'var:preset|font-family|instrument-serif' !== w.styles.elements.heading.typography.fontFamily || 'var:preset|font-family|instrument-sans' !== w.styles.typography.fontFamily ) fail( 'titres ou texte : ' + JSON.stringify( w.styles ) );
if ( 1 !== w.settings.custom?.keep || 'x' !== w.styles.color?.text ) fail( 'clé étrangère perdue' );
tree = render();
if ( ! find( tree, ( n ) => 'p' === n.type && n.children.includes( 'In use' ) ).length || 1 !== buttons( tree, 'Reset' ).length ) fail( 'duo en place non signalé' );

// 2. Duo avec la pile système : le texte reprend la valeur d'origine.
reply = () => Promise.resolve( { families: [ fam( 'recursive' ) ], backup } );
await buttons( tree, 'Apply' )[ 0 ].props.onClick();
w = edits[ 1 ][ 3 ];
if ( 'var:preset|font-family|serif' !== w.styles.typography.fontFamily || 'maison,recursive' !== w.settings.typography.fontFamilies.custom.map( ( f ) => f.slug ).join( ',' ) ) fail( 'duo système : ' + JSON.stringify( w ) );

// 3. Échec de téléchargement : message affiché, rien d'écrit.
tree = render();
reply = () => Promise.reject( { message: 'Pas de fichier' } );
await buttons( tree, 'Apply' )[ 0 ].props.onClick();
tree = render();
if ( 2 !== edits.length || ! find( tree, ( n ) => 'Notice' === n.type && n.children.includes( 'Pas de fichier' ) ).length ) fail( 'échec non signalé ou écriture quand même' );

// 4. Retour aux polices du thème.
buttons( tree, 'Reset' )[ 0 ].props.onClick();
w = edits[ 2 ][ 3 ];
if ( w.styles.elements || 'var:preset|font-family|serif' !== w.styles.typography.fontFamily || 'maison' !== w.settings.typography.fontFamilies.custom.map( ( f ) => f.slug ).join( ',' ) ) fail( 'retour : ' + JSON.stringify( w ) );
console.log( 'OK : aperçus et paragraphe sur demande, duo appliqué dans les styles en cours d’édition, duo système, échec signalé, retour aux polices du thème.' );
