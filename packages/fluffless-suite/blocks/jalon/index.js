/**
 * Éditeur du bloc fluffless/jalon, une étape du bloc Frise. JavaScript natif, sans étape de build.
 * Date ou libellé, titre de niveau 3, un paragraphe. Rendu du site : Sections::render_jalon().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const { useBlockProps, useInnerBlocksProps } = wp.blockEditor;
	const TEMPLATE = [
		[ 'core/paragraph', { className: 'fluffless-frise__date', fontSize: 'small', textColor: 'muted', placeholder: __( '2019', 'fluffless-suite' ) } ],
		[ 'core/heading', { level: 3, placeholder: __( 'What happened', 'fluffless-suite' ) } ],
		[ 'core/paragraph', { placeholder: __( 'One or two sentences: what changed at this step, and for whom.', 'fluffless-suite' ) } ],
	];

	wp.blocks.registerBlockType( 'fluffless/jalon', {
		edit() {
			return S.el( 'div', useInnerBlocksProps( useBlockProps( { className: 'fluffless-frise__step' } ), { template: TEMPLATE } ) );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
