/**
 * Éditeur du bloc fluffless/services. JavaScript natif, sans étape de build.
 * Un titre puis un groupe en grille native : un groupe par service (icône facultative, titre de
 * niveau 3, une ligne, bouton facultatif). Le nombre de colonnes est celui de la grille du cœur ;
 * « En liste » passe sur une colonne en CSS. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const NONE = [];
	const ICON = 'fluffless/icone';
	const ITEM_CLASS = 'fluffless-services__item';
	const NAME = () => __( 'Name of the service', 'fluffless-suite' );
	const LINE = () => __( 'What people get, in one or two sentences. Name the result, not the method.', 'fluffless-suite' );
	const MORE = () => __( 'Learn more', 'fluffless-suite' );

	const icon = () => S.createBlock( ICON, { textColor: 'accent' } );
	const buttons = () => S.buttons( 1, MORE() );
	const service = ( withIcon, withButton ) =>
		S.createBlock(
			'core/group',
			{ className: ITEM_CLASS, metadata: { name: __( 'Service', 'fluffless-suite' ) } },
			[]
				.concat( withIcon ? [ icon() ] : [] )
				.concat( [ S.createBlock( 'core/heading', { level: 3, placeholder: NAME() } ), S.createBlock( 'core/paragraph', { placeholder: LINE() } ) ] )
				.concat( withButton ? [ buttons() ] : [] )
		);

	const ITEM = [
		'core/group',
		{ className: ITEM_CLASS, metadata: { name: __( 'Service', 'fluffless-suite' ) } },
		[
			[ ICON, { textColor: 'accent' } ],
			[ 'core/heading', { level: 3, placeholder: NAME() } ],
			[ 'core/paragraph', { placeholder: LINE() } ],
		],
	];
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'Three concrete ways we can help', 'fluffless-suite' ) } ],
		[ 'core/group', { layout: { type: 'grid', columnCount: 3, minimumColumnWidth: '14rem' } }, [ ITEM, ITEM, ITEM ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/services', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const state = wp.data.useSelect(
				( s ) => {
					const be = s( 'core/block-editor' );
					const items = be.getBlocks( clientId ).find( S.isBlock( 'core/group' ) );
					const list = items ? be.getBlocks( items.clientId ) : NONE;
					const first = list[ 0 ] ? be.getBlocks( list[ 0 ].clientId ) : NONE;
					const layout = items ? items.attributes.layout || {} : {};
					return {
						items,
						list,
						icon: first.some( S.isBlock( ICON ) ),
						button: first.some( S.isBlock( 'core/buttons' ) ),
						columns: String( layout.columnCount || 3 ),
					};
				},
				[ clientId ]
			);

			const setColumns = ( raw ) => {
				if ( ! state.items ) {
					return;
				}
				wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( state.items.clientId, {
					layout: Object.assign( {}, state.items.attributes.layout, { columnCount: parseInt( raw, 10 ) } ),
				} );
			};
			const count = ( n ) => ( { value: n, label: n } );

			return S.shell( 'services', props, {
				keys: [ 'display' ],
				template: TEMPLATE,
				title: __( 'Services', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of services', 'fluffless-suite' ),
						String( state.list.length ),
						[ '2', '3', '4', '5', '6' ].map( count ),
						( raw ) => state.items && S.setCount( state.items.clientId, parseInt( raw, 10 ), () => service( state.icon, state.button ) ),
						__( 'Removing a service also removes its content.', 'fluffless-suite' )
					),
					S.choice( __( 'Columns', 'fluffless-suite' ), state.columns, [ '2', '3', '4' ].map( count ), setColumns ),
					S.toggle( __( 'Icon', 'fluffless-suite' ), state.icon, ( on ) =>
						state.list.forEach( ( item ) => S.setChild( item.clientId, S.isBlock( ICON ), on, icon, 0 ) )
					),
					S.toggle( __( 'Button on each service', 'fluffless-suite' ), state.button, ( on ) =>
						state.list.forEach( ( item ) => S.setChild( item.clientId, S.isBlock( 'core/buttons' ), on, buttons ) )
					),
					S.choice(
						__( 'Layout', 'fluffless-suite' ),
						attributes.display,
						[
							{ value: 'cards', label: __( 'Cards', 'fluffless-suite' ) },
							{ value: 'list', label: __( 'List', 'fluffless-suite' ) },
						],
						( next ) => setAttributes( { display: next } )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
