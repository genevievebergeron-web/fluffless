// Panneau Événement de l'éditeur (P4-10) : wp simulé ; un événement sans date ouvre le panneau, une seule fois.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const root = new URL( '../assets/', import.meta.url ).pathname;
const fail = ( m ) => {
	console.error( 'ÉCHEC : ' + m );
	process.exit( 1 );
};
const PANEL = 'fluffless-evenement/fluffless-evenement';

const run = ( { start, opened } ) => {
	const toggles = [];
	const effects = [];
	let plugin = null;
	const h = ( type, props, ...children ) => ( { type: type.displayName || type, props: props || {}, children: children.flat() } );
	const comp = ( name ) => Object.assign( ( props ) => h( name, props ), { displayName: name } );
	const wp = {
		element: {
			createElement: h,
			useState: ( init ) => [ init, () => {} ],
			useEffect: ( fn ) => effects.push( fn ),
			useRef: ( init ) => ( { current: init } ),
		},
		i18n: { __: ( s ) => s, _x: ( s ) => s, sprintf: ( s ) => s },
		data: {
			useSelect: ( fn ) =>
				fn( ( store ) =>
					'core/editor' === store
						? { getCurrentPostType: () => 'fluffless_event', isEditorPanelOpened: ( id ) => opened && PANEL === id }
						: {}
				),
			dispatch: ( store ) => ( { toggleEditorPanelOpened: ( id ) => toggles.push( [ store, id ] ) } ),
		},
		coreData: { useEntityProp: () => [ { _fluffless_event_start: start }, () => {} ] },
		components: Object.fromEntries( [ 'TextControl', 'SelectControl', 'ToggleControl', 'Notice', 'ExternalLink' ].map( ( n ) => [ n, comp( n ) ] ) ),
		editor: { PluginDocumentSettingPanel: comp( 'PluginDocumentSettingPanel' ) },
		plugins: { registerPlugin: ( name, s ) => { plugin = s; } },
		blocks: { registerBlockBindingsSource: () => {} },
	};
	const win = { wp, flufflessEvenements: { type: 'fluffless_event', keys: { start: '_fluffless_event_start', end: '_fluffless_event_end' } } };
	win.window = win;
	vm.runInNewContext( readFileSync( root + 'evenements-editor.js', 'utf8' ), win );
	if ( ! plugin ) fail( 'plugin non enregistré' );
	plugin.render( {} );
	effects.forEach( ( fn ) => fn() );
	return toggles;
};

const empty = run( { start: '', opened: false } );
if ( 1 !== empty.length || 'core/editor' !== empty[ 0 ][ 0 ] || PANEL !== empty[ 0 ][ 1 ] ) fail( 'événement sans date : le panneau Événement devrait s\'ouvrir, reçu ' + JSON.stringify( empty ) );
if ( 0 !== run( { start: '', opened: true } ).length ) fail( 'panneau déjà ouvert : il ne doit pas se refermer' );
if ( 0 !== run( { start: '2026-11-03 19:00', opened: false } ).length ) fail( 'événement daté : le choix de la personne (panneau fermé) est gardé' );
console.log( 'OK : panneau Événement, 3 cas.' );
