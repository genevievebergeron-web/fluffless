// Éditeur du bloc Formulaire : les cases proposées pour l'infolettre portent le nom que le serveur
// donnera au champ (Form::field() : nom ou libellé passé en slug, puis -2, -3 pour un nom déjà pris).
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const fail = ( m ) => {
	console.error( 'ÉCHEC : ' + m );
	process.exit( 1 );
};

const blocks = {
	a: { clientId: 'a', name: 'fluffless/champ', attributes: { type: 'consent', label: 'Infolettre' } },
	b: { clientId: 'b', name: 'fluffless/champ', attributes: { type: 'consent', label: 'Infolettre' } },
	c: { clientId: 'c', name: 'fluffless/champ', attributes: { type: 'consent', name: 'Mon Champ', label: 'Autre' } },
	d: { clientId: 'd', name: 'fluffless/champ', attributes: { type: 'radio', label: 'Infolettre', options: 'Oui\nNon' } },
};
const select = () => ( {
	getClientIdsOfDescendants: () => [ 'd', 'a', 'b', 'c' ],
	getBlock: ( id ) => blocks[ id ],
} );
let edit = null;
const el = ( type, props, ...children ) => ( { type, props: props || {}, children } );
const wp = {
	element: { createElement: el, useEffect: () => {}, Fragment: 'Fragment' },
	data: { useSelect: ( fn ) => fn( select ), useDispatch: () => ( { updateBlockAttributes: () => {} } ) },
	i18n: { __: ( s ) => s },
	blocks: { registerBlockType: ( name, settings ) => ( edit = settings.edit ) },
	blockEditor: {
		useBlockProps: ( p ) => p,
		useInnerBlocksProps: () => ( {} ),
		InnerBlocks: { Content: 'Content' },
		InspectorControls: 'InspectorControls',
	},
	components: { Notice: 'Notice', PanelBody: 'PanelBody', SelectControl: 'SelectControl', TextControl: 'TextControl', TextareaControl: 'TextareaControl', ToggleControl: 'ToggleControl' },
};
const source = readFileSync( new URL( '../blocks/formulaire/index.js', import.meta.url ), 'utf8' );
runInNewContext( source, { window: { wp } } );
if ( ! edit ) fail( 'bloc non enregistré' );

const tree = edit( { attributes: { formId: 'abcd1234', newsletter: true, newsletterConsent: '' }, setAttributes: () => {}, clientId: 'root' } );
const find = ( node, test ) => {
	if ( ! node || 'object' !== typeof node ) return null;
	if ( test( node ) ) return node;
	for ( const child of ( node.children || [] ).flat() ) {
		const hit = find( child, test );
		if ( hit ) return hit;
	}
	return null;
};
const control = find( tree, ( n ) => 'SelectControl' === n.type && 'Newsletter consent box' === n.props.label );
if ( ! control ) fail( 'liste des cases de l’infolettre absente' );
const names = control.props.options.map( ( o ) => o.value ).filter( Boolean );
// Le radio « Infolettre » vient en premier dans le document : il prend « infolettre », les cases suivent.
const expected = [ 'infolettre-2', 'infolettre-3', 'mon-champ' ];
if ( JSON.stringify( expected ) !== JSON.stringify( names ) ) fail( 'noms des cases : ' + JSON.stringify( names ) + ', attendu ' + JSON.stringify( expected ) );
const route = find( tree, ( n ) => 'SelectControl' === n.type && Array.isArray( n.props.options ) && n.props.options.some( ( o ) => 'infolettre' === o.value ) );
if ( ! route ) fail( 'champ à choix absent de l’acheminement (nom « infolettre »)' );
// Le script a changé depuis la 0.2.0 : la version de l'asset casse le cache de l'éditeur (X5-83).
const asset = readFileSync( new URL( '../blocks/formulaire/index.asset.php', import.meta.url ), 'utf8' );
if ( ! /'version'\s*=>\s*'0\.3\.0'/.test( asset ) ) fail( 'version de index.asset.php : ' + ( asset.match( /'version'\s*=>\s*'([^']*)'/ ) || [] )[ 1 ] );
console.log( 'OK : éditeur du Formulaire, noms des cases alignés sur le serveur.' );
