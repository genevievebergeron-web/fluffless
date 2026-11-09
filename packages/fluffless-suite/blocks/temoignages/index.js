/**
 * Éditeur du bloc fluffless/temoignages. JavaScript natif, sans étape de build.
 * Un titre puis un groupe en grille native (layout grid du cœur) : un groupe par témoignage,
 * avec une citation native. Photo et logo sont des images repérées par leur classe.
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const PHOTO = 'fluffless-temoignages__photo';
	const LOGO = 'fluffless-temoignages__logo';
	const NONE = [];
	const QUOTE = () => __( 'Quote the client word for word: the result they got, in their own words.', 'fluffless-suite' );

	const photo = () => S.createBlock( 'core/image', { className: PHOTO, sizeSlug: 'thumbnail' } );
	const logo = () => S.createBlock( 'core/image', { className: LOGO, sizeSlug: 'medium' } );
	const testimonial = ( withPhoto, withLogo ) =>
		S.createBlock(
			'core/group',
			{ metadata: { name: __( 'Testimonial', 'fluffless-suite' ) } },
			[]
				.concat( withPhoto ? [ photo() ] : [] )
				.concat( [ S.createBlock( 'core/quote', {}, [ S.createBlock( 'core/paragraph', { placeholder: QUOTE() } ) ] ) ] )
				.concat( withLogo ? [ logo() ] : [] )
		);

	const ITEM = [
		'core/group',
		{ metadata: { name: __( 'Testimonial', 'fluffless-suite' ) } },
		[
			[ 'core/image', { className: PHOTO, sizeSlug: 'thumbnail' } ],
			[ 'core/quote', {}, [ [ 'core/paragraph', { placeholder: QUOTE() } ] ] ],
		],
	];
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'Let your clients say it for you', 'fluffless-suite' ) } ],
		[ 'core/group', { layout: { type: 'grid', columnCount: 3, minimumColumnWidth: '16rem' } }, [ ITEM, ITEM, ITEM ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/temoignages', {
		edit( props ) {
			const { clientId } = props;
			const state = wp.data.useSelect(
				( s ) => {
					const be = s( 'core/block-editor' );
					const items = be.getBlocks( clientId ).find( S.isBlock( 'core/group' ) );
					const list = items ? be.getBlocks( items.clientId ) : NONE;
					const first = list[ 0 ] ? be.getBlocks( list[ 0 ].clientId ) : NONE;
					return { items, list, photo: first.some( S.hasClass( PHOTO ) ), logo: first.some( S.hasClass( LOGO ) ) };
				},
				[ clientId ]
			);

			const setCount = ( value ) => {
				const n = parseInt( value, 10 );
				if ( ! state.items ) {
					return;
				}
				S.setCount( state.items.clientId, n, () => testimonial( state.photo, state.logo ) );
				wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( state.items.clientId, {
					layout: Object.assign( {}, state.items.attributes.layout, { columnCount: n } ),
				} );
			};

			return S.shell( 'temoignages', props, {
				template: TEMPLATE,
				title: __( 'Testimonials', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of testimonials', 'fluffless-suite' ),
						String( state.list.length ),
						[
							{ value: '1', label: '1' },
							{ value: '2', label: '2' },
							{ value: '3', label: '3' },
						],
						setCount,
						__( 'Removing a testimonial also removes its content.', 'fluffless-suite' )
					),
					S.toggle( __( 'Photo', 'fluffless-suite' ), state.photo, ( value ) =>
						state.list.forEach( ( item ) => S.setChild( item.clientId, S.hasClass( PHOTO ), value, photo, 0 ) )
					),
					S.toggle(
						__( 'Company logo', 'fluffless-suite' ),
						state.logo,
						( value ) => state.list.forEach( ( item ) => S.setChild( item.clientId, S.hasClass( LOGO ), value, logo ) ),
						__( 'Describe each photo and logo in its alternative text: the person’s name, or the company name.', 'fluffless-suite' )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
