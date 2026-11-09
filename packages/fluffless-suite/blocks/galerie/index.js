/**
 * Éditeur du bloc fluffless/galerie. JavaScript natif, sans étape de build.
 * Un titre puis une galerie native (core/gallery) : colonnes et agrandissement au clic sont ses
 * propres réglages (columns, linkTo « lightbox »), donc rien n'est stocké en double. Grille ou
 * mosaïque et légendes affichées ou masquées sont des classes. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const IMAGE = [ 'core/image', { sizeSlug: 'large', linkDestination: 'none', lightbox: { enabled: true } } ];
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'Name what these photos show', 'fluffless-suite' ) } ],
		[ 'core/gallery', { columns: 3, linkTo: 'lightbox', sizeSlug: 'large' }, [ IMAGE, IMAGE, IMAGE ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/galerie', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const gallery = S.useChildren( clientId ).find( S.isBlock( 'core/gallery' ) );
			const columns = String( ( gallery && gallery.attributes.columns ) || 3 );
			const setGallery = ( next ) => gallery && wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( gallery.clientId, next );

			return S.shell( 'galerie', props, {
				keys: [ 'arrangement', 'captions' ],
				template: TEMPLATE,
				title: __( 'Photo gallery', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of columns', 'fluffless-suite' ),
						columns,
						[ '2', '3', '4' ].map( ( n ) => ( { value: n, label: n } ) ),
						( value ) => setGallery( { columns: parseInt( value, 10 ) } ),
						__( 'On phones, the gallery shows two columns.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Arrangement', 'fluffless-suite' ),
						attributes.arrangement,
						[
							{ value: 'grid', label: __( 'Regular grid', 'fluffless-suite' ) },
							{ value: 'mosaic', label: __( 'Mosaic', 'fluffless-suite' ) },
						],
						( value ) => setAttributes( { arrangement: value } )
					),
					S.toggle(
						__( 'Captions', 'fluffless-suite' ),
						'shown' === attributes.captions,
						( on ) => setAttributes( { captions: on ? 'shown' : 'hidden' } ),
						__( 'Hidden captions stay saved. Describe each photo in its alternative text either way.', 'fluffless-suite' )
					),
					S.toggle(
						__( 'Enlarge on click', 'fluffless-suite' ),
						gallery ? 'lightbox' === gallery.attributes.linkTo : true,
						( on ) => setGallery( { linkTo: on ? 'lightbox' : 'none' } ),
						__( 'Uses the viewer built into WordPress: no extra script.', 'fluffless-suite' )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
