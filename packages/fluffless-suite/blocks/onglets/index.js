/**
 * Éditeur du bloc fluffless/onglets. JavaScript natif, sans étape de build.
 * Un groupe de blocs Détails du cœur ; le CSS du bloc les dispose en onglets sur écran large.
 * Sans JavaScript côté visiteur. Rendu du site : Sections::render(), qui donne le même « name »
 * à tous les Détails du bloc pour qu'un seul soit ouvert à la fois.
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const NONE = [];
	const DETAILS = 'core/details';
	const LIST_CLASS = 'fluffless-onglets__list';
	const TAB = () => __( 'Tab name', 'fluffless-suite' );
	const BODY = () => __( 'What this panel says. Keep each tab to one idea.', 'fluffless-suite' );

	const panel = ( open ) =>
		S.createBlock( DETAILS, { placeholder: TAB(), showContent: !! open }, [ S.createBlock( 'core/paragraph', { placeholder: BODY() } ) ] );

	const ITEM = ( open ) => [ DETAILS, { placeholder: TAB(), showContent: open }, [ [ 'core/paragraph', { placeholder: BODY() } ] ] ];
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'One place, three angles', 'fluffless-suite' ) } ],
		[ 'core/group', { className: LIST_CLASS, layout: { type: 'default' } }, [ ITEM( true ), ITEM( false ), ITEM( false ) ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/onglets', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const state = wp.data.useSelect(
				( s ) => {
					const be = s( 'core/block-editor' );
					const items = be.getBlocks( clientId ).find( S.isBlock( 'core/group' ) );
					const list = items ? be.getBlocks( items.clientId ).filter( S.isBlock( DETAILS ) ) : NONE;
					return { items, list };
				},
				[ clientId ]
			);

			return S.shell( 'onglets', props, {
				keys: [ 'display' ],
				template: TEMPLATE,
				title: __( 'Tabs', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of tabs', 'fluffless-suite' ),
						String( state.list.length ),
						[ '2', '3', '4', '5', '6' ].map( ( n ) => ( { value: n, label: n } ) ),
						( raw ) => state.items && S.setCount( state.items.clientId, parseInt( raw, 10 ), ( i ) => panel( 0 === i ) ),
						__( 'Removing a tab also removes its content.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Display', 'fluffless-suite' ),
						attributes.display,
						[
							{ value: 'tabs', label: __( 'Tabs', 'fluffless-suite' ) },
							{ value: 'accordion', label: __( 'Accordion', 'fluffless-suite' ) },
						],
						( next ) => setAttributes( { display: next } ),
						__( 'Tabs turn into an accordion on narrow screens either way.', 'fluffless-suite' )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
