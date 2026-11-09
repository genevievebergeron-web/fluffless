/**
 * Éditeur du bloc fluffless/barres. JavaScript natif, sans étape de build.
 * Un titre puis un groupe natif de blocs fluffless/barre (libellé et valeur de 0 à 100).
 * Piste et remplissage sont du CSS seulement. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const NONE = [];
	const LIST_CLASS = 'fluffless-barres__list';
	const bar = ( i ) => S.createBlock( 'fluffless/barre', { value: [ 80, 65, 45 ][ i % 3 ] } );
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'Where we stand', 'fluffless-suite' ) } ],
		[
			'core/group',
			{ className: LIST_CLASS, layout: { type: 'default' } },
			[
				[ 'fluffless/barre', { value: 80 } ],
				[ 'fluffless/barre', { value: 65 } ],
				[ 'fluffless/barre', { value: 45 } ],
			],
		],
	];

	wp.blocks.registerBlockType( 'fluffless/barres', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const state = wp.data.useSelect(
				( s ) => {
					const be = s( 'core/block-editor' );
					const items = be.getBlocks( clientId ).find( S.hasClass( LIST_CLASS ) );
					return { items, list: items ? be.getBlocks( items.clientId ) : NONE };
				},
				[ clientId ]
			);

			return S.shell( 'barres', props, {
				keys: [ 'size', 'values' ],
				template: TEMPLATE,
				title: __( 'Progress bars', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of bars', 'fluffless-suite' ),
						String( state.list.length ),
						[ '2', '3', '4', '5', '6', '7', '8' ].map( ( n ) => ( { value: n, label: n } ) ),
						( raw ) => state.items && S.setCount( state.items.clientId, parseInt( raw, 10 ), bar ),
						__( 'Removing a bar also removes its label.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Thickness', 'fluffless-suite' ),
						attributes.size,
						[
							{ value: 'thin', label: __( 'Thin', 'fluffless-suite' ) },
							{ value: 'thick', label: __( 'Thick', 'fluffless-suite' ) },
						],
						( next ) => setAttributes( { size: next } )
					),
					S.toggle(
						__( 'Show the values', 'fluffless-suite' ),
						'shown' === attributes.values,
						( on ) => setAttributes( { values: on ? 'shown' : 'hidden' } ),
						__( 'The value stays in the page for screen readers either way.', 'fluffless-suite' )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
