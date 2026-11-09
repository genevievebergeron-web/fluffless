/**
 * Éditeur du bloc fluffless/terme, une entrée du bloc Glossaire. JavaScript natif, sans étape de build.
 * Deux paragraphes verrouillés : le terme, puis sa définition. Rendu du site : Sections::render_terme().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const { useBlockProps, useInnerBlocksProps } = wp.blockEditor;
	const TEMPLATE = [
		[ 'core/paragraph', { placeholder: __( 'The term', 'fluffless-suite' ) } ],
		[ 'core/paragraph', { placeholder: __( 'Its definition in one or two sentences.', 'fluffless-suite' ) } ],
	];

	wp.blocks.registerBlockType( 'fluffless/terme', {
		edit() {
			return S.el( 'div', useInnerBlocksProps( useBlockProps( { className: 'fluffless-terme' } ), { template: TEMPLATE, templateLock: 'all' } ) );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
