/**
 * Éditeur du bloc fluffless/carrousel. JavaScript natif, sans étape de build.
 * Un titre puis une piste (groupe natif) : une diapositive par groupe (image, puis une ligne).
 * Le défilement et l'aimantation sont du CSS natif (scroll-snap), aucun script côté visiteur.
 * Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const NONE = [];
	const TRACK_CLASS = 'fluffless-carrousel__track';
	const ITEM_CLASS = 'fluffless-carrousel__item';
	const LINE = () => __( 'One line under the picture: what it shows, or where it was taken.', 'fluffless-suite' );

	const slide = () =>
		S.createBlock( 'core/group', { className: ITEM_CLASS, metadata: { name: __( 'Slide', 'fluffless-suite' ) } }, [
			S.createBlock( 'core/image', { sizeSlug: 'large' } ),
			S.createBlock( 'core/paragraph', { placeholder: LINE() } ),
		] );

	const ITEM = [
		'core/group',
		{ className: ITEM_CLASS, metadata: { name: __( 'Slide', 'fluffless-suite' ) } },
		[
			[ 'core/image', { sizeSlug: 'large' } ],
			[ 'core/paragraph', { placeholder: LINE() } ],
		],
	];
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'A few moments from the field', 'fluffless-suite' ) } ],
		[ 'core/group', { className: TRACK_CLASS, layout: { type: 'default' } }, [ ITEM, ITEM, ITEM, ITEM ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/carrousel', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const state = wp.data.useSelect(
				( s ) => {
					const be = s( 'core/block-editor' );
					const items = be.getBlocks( clientId ).find( S.hasClass( TRACK_CLASS ) );
					return { items, list: items ? be.getBlocks( items.clientId ) : NONE };
				},
				[ clientId ]
			);

			return S.shell( 'carrousel', props, {
				keys: [ 'width' ],
				template: TEMPLATE,
				title: __( 'Carousel', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of slides', 'fluffless-suite' ),
						String( state.list.length ),
						[ '2', '3', '4', '5', '6', '7', '8' ].map( ( n ) => ( { value: n, label: n } ) ),
						( raw ) => state.items && S.setCount( state.items.clientId, parseInt( raw, 10 ), slide ),
						__( 'Removing a slide also removes its content.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Slides in view', 'fluffless-suite' ),
						attributes.width,
						[
							{ value: 'third', label: '3' },
							{ value: 'half', label: '2' },
							{ value: 'full', label: '1' },
						],
						( next ) => setAttributes( { width: next } ),
						__( 'On wide screens. Phones always show one slide and a glimpse of the next.', 'fluffless-suite' )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
