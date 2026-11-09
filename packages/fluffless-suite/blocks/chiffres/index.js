/**
 * Éditeur du bloc fluffless/chiffres. JavaScript natif, sans étape de build.
 * Un titre puis un groupe en grille native : un groupe par chiffre (le nombre, puis ce qu'il
 * compte). Le nombre de colonnes suit le nombre de chiffres. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const NONE = [];
	const ITEM_CLASS = 'fluffless-chiffres__item';
	const NUMBER_CLASS = 'fluffless-chiffres__number';
	const NUMBER = () => __( '1,200', 'fluffless-suite' );
	const LABEL = () => __( 'What this number counts: people, hours, meals, files.', 'fluffless-suite' );

	const figure = () =>
		S.createBlock( 'core/group', { className: ITEM_CLASS, metadata: { name: __( 'Figure', 'fluffless-suite' ) } }, [
			S.createBlock( 'core/paragraph', { className: NUMBER_CLASS, placeholder: NUMBER() } ),
			S.createBlock( 'core/paragraph', { placeholder: LABEL() } ),
		] );

	const ITEM = [
		'core/group',
		{ className: ITEM_CLASS, metadata: { name: __( 'Figure', 'fluffless-suite' ) } },
		[
			[ 'core/paragraph', { className: NUMBER_CLASS, placeholder: NUMBER() } ],
			[ 'core/paragraph', { placeholder: LABEL() } ],
		],
	];
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'Facts, not adjectives', 'fluffless-suite' ) } ],
		[ 'core/group', { layout: { type: 'grid', columnCount: 3, minimumColumnWidth: '10rem' } }, [ ITEM, ITEM, ITEM ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/chiffres', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const state = wp.data.useSelect(
				( s ) => {
					const be = s( 'core/block-editor' );
					const items = be.getBlocks( clientId ).find( S.isBlock( 'core/group' ) );
					return { items, list: items ? be.getBlocks( items.clientId ) : NONE };
				},
				[ clientId ]
			);

			const setCount = ( raw ) => {
				const n = parseInt( raw, 10 );
				if ( ! state.items ) {
					return;
				}
				S.setCount( state.items.clientId, n, figure );
				wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( state.items.clientId, {
					layout: Object.assign( {}, state.items.attributes.layout, { columnCount: n } ),
				} );
			};

			return S.shell( 'chiffres', props, {
				keys: [ 'size' ],
				template: TEMPLATE,
				title: __( 'Key figures', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of figures', 'fluffless-suite' ),
						String( state.list.length ),
						[ '2', '3', '4' ].map( ( n ) => ( { value: n, label: n } ) ),
						setCount,
						__( 'Removing a figure also removes its content.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Size', 'fluffless-suite' ),
						attributes.size,
						[
							{ value: 'large', label: __( 'Large', 'fluffless-suite' ) },
							{ value: 'compact', label: __( 'Compact', 'fluffless-suite' ) },
						],
						( next ) => setAttributes( { size: next } )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
