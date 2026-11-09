/**
 * Éditeur du bloc fluffless/avant-apres. JavaScript natif, sans étape de build.
 * Deux blocs Image natifs (la première est l'avant, la seconde l'après) ; position de départ,
 * étiquettes et leur texte dans le panneau. Rendu du site : Sections::render() et
 * Sections::compare_frame() ; le curseur vit dans view.js.
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const { TextControl } = wp.components;
	const TEMPLATE = [
		[ 'core/image', { sizeSlug: 'large' } ],
		[ 'core/image', { sizeSlug: 'large' } ],
	];

	wp.blocks.registerBlockType( 'fluffless/avant-apres', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			return S.shell( 'avant-apres', props, {
				keys: [ 'start', 'labels' ],
				template: TEMPLATE,
				title: __( 'Before and after', 'fluffless-suite' ),
				controls: [
					S.el( 'p', { className: 'components-base-control__help' }, __( 'The first image is the “before”, the second the “after”. Same size for both, or the taller one is cropped.', 'fluffless-suite' ) ),
					S.choice(
						__( 'Starting position', 'fluffless-suite' ),
						String( attributes.start ),
						[
							{ value: '50', label: __( 'Middle', 'fluffless-suite' ) },
							{ value: '25', label: __( 'Mostly after', 'fluffless-suite' ) },
							{ value: '75', label: __( 'Mostly before', 'fluffless-suite' ) },
						],
						( next ) => setAttributes( { start: parseInt( next, 10 ) } )
					),
					S.toggle( __( 'Show the labels', 'fluffless-suite' ), 'shown' === attributes.labels, ( on ) => setAttributes( { labels: on ? 'shown' : 'hidden' } ) ),
					S.el( TextControl, {
						label: __( 'Label of the first image', 'fluffless-suite' ),
						value: attributes.before,
						placeholder: __( 'Before', 'fluffless-suite' ),
						onChange: ( before ) => setAttributes( { before } ),
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
					} ),
					S.el( TextControl, {
						label: __( 'Label of the second image', 'fluffless-suite' ),
						value: attributes.after,
						placeholder: __( 'After', 'fluffless-suite' ),
						onChange: ( after ) => setAttributes( { after } ),
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
					} ),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
