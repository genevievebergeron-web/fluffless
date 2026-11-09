/**
 * Éditeur du bloc fluffless/valeurs. JavaScript natif, sans étape de build.
 * Un titre puis un groupe en grille native (layout grid du cœur) : un groupe par valeur, avec
 * icône facultative, titre de niveau 3 et une ligne. « En liste » passe la grille sur une
 * colonne en CSS, sans toucher au contenu. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const NONE = [];
	const ICON = 'fluffless/icone';
	const NAME = () => __( 'One or two words', 'fluffless-suite' );
	const LINE = () => __( 'One line on what this value changes for the people served, with a concrete example.', 'fluffless-suite' );
	// Colonnes de la grille selon le nombre de valeurs : 5 et 6 passent sur deux rangées de 3.
	const columnsFor = ( n ) => ( 4 === n ? 4 : 3 );

	const icon = () => S.createBlock( ICON, {} );
	const value = ( withIcon ) =>
		S.createBlock(
			'core/group',
			{ metadata: { name: __( 'Value', 'fluffless-suite' ) } },
			[]
				.concat( withIcon ? [ icon() ] : [] )
				.concat( [ S.createBlock( 'core/heading', { level: 3, placeholder: NAME() } ), S.createBlock( 'core/paragraph', { placeholder: LINE() } ) ] )
		);

	const ITEM = [
		'core/group',
		{ metadata: { name: __( 'Value', 'fluffless-suite' ) } },
		[
			[ 'core/heading', { level: 3, placeholder: NAME() } ],
			[ 'core/paragraph', { placeholder: LINE() } ],
		],
	];
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'What guides the work', 'fluffless-suite' ) } ],
		[ 'core/group', { layout: { type: 'grid', columnCount: 3, minimumColumnWidth: '12rem' } }, [ ITEM, ITEM, ITEM ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/valeurs', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const state = wp.data.useSelect(
				( s ) => {
					const be = s( 'core/block-editor' );
					const items = be.getBlocks( clientId ).find( S.isBlock( 'core/group' ) );
					const list = items ? be.getBlocks( items.clientId ) : NONE;
					const first = list[ 0 ] ? be.getBlocks( list[ 0 ].clientId ) : NONE;
					return { items, list, icon: first.some( S.isBlock( ICON ) ) };
				},
				[ clientId ]
			);

			const setCount = ( raw ) => {
				const n = parseInt( raw, 10 );
				if ( ! state.items ) {
					return;
				}
				S.setCount( state.items.clientId, n, () => value( state.icon ) );
				wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( state.items.clientId, {
					layout: Object.assign( {}, state.items.attributes.layout, { columnCount: columnsFor( n ) } ),
				} );
			};

			return S.shell( 'valeurs', props, {
				keys: [ 'display' ],
				template: TEMPLATE,
				title: __( 'Values', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of values', 'fluffless-suite' ),
						String( state.list.length ),
						[ '3', '4', '5', '6' ].map( ( n ) => ( { value: n, label: n } ) ),
						setCount,
						__( 'Removing a value also removes its content.', 'fluffless-suite' )
					),
					S.toggle( __( 'Icon', 'fluffless-suite' ), state.icon, ( on ) =>
						state.list.forEach( ( item ) => S.setChild( item.clientId, S.isBlock( ICON ), on, icon, 0 ) )
					),
					S.choice(
						__( 'Layout', 'fluffless-suite' ),
						attributes.display,
						[
							{ value: 'columns', label: __( 'Columns', 'fluffless-suite' ) },
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
