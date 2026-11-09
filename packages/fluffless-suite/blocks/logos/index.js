/**
 * Éditeur du bloc fluffless/logos. JavaScript natif, sans étape de build.
 * Une ligne de contexte puis un groupe en grille native d'images du cœur (3:2, contenues).
 * Grille ou rangée et couleur ou monochrome se règlent en CSS, sans toucher aux images.
 * Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const NONE = [];
	const IMAGE = [ 'core/image', { aspectRatio: '3/2', scale: 'contain', sizeSlug: 'medium' } ];
	const logo = () => S.createBlock( IMAGE[ 0 ], IMAGE[ 1 ] );

	const TEMPLATE = [
		[ 'core/paragraph', { align: 'center', textColor: 'muted', placeholder: __( 'They work with us.', 'fluffless-suite' ) } ],
		[ 'core/group', { layout: { type: 'grid', columnCount: 6, minimumColumnWidth: '7rem' } }, [ IMAGE, IMAGE, IMAGE, IMAGE, IMAGE, IMAGE ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/logos', {
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
			const pick = ( key, options ) =>
				S.choice(
					options.label,
					attributes[ key ],
					options.values,
					( next ) => setAttributes( { [ key ]: next } )
				);

			return S.shell( 'logos', props, {
				keys: [ 'arrangement', 'tone' ],
				template: TEMPLATE,
				title: __( 'Partner logos', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of logos', 'fluffless-suite' ),
						String( state.list.length ),
						[ '3', '4', '5', '6', '7', '8' ].map( ( n ) => ( { value: n, label: n } ) ),
						( raw ) => state.items && S.setCount( state.items.clientId, parseInt( raw, 10 ), logo ),
						__( 'Removing a logo also removes its image.', 'fluffless-suite' )
					),
					pick( 'arrangement', {
						label: __( 'Arrangement', 'fluffless-suite' ),
						values: [
							{ value: 'grid', label: __( 'Grid', 'fluffless-suite' ) },
							{ value: 'row', label: __( 'Row', 'fluffless-suite' ) },
						],
					} ),
					pick( 'tone', {
						label: __( 'Tone', 'fluffless-suite' ),
						values: [
							{ value: 'mono', label: __( 'Greyscale', 'fluffless-suite' ) },
							{ value: 'color', label: __( 'Colour', 'fluffless-suite' ) },
						],
					} ),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
