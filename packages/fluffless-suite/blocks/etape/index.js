/**
 * Éditeur du bloc fluffless/etape. JavaScript natif, sans étape de build.
 * Le site reçoit le rendu serveur (Form::render_step()) : une étape à la fois, 0 JS.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, useInnerBlocksProps, InnerBlocks, RichText } = wp.blockEditor;

	const ALLOWED = [ 'fluffless/champ', 'core/paragraph', 'core/heading', 'core/group', 'core/columns' ];
	const TEMPLATE = [ [ 'fluffless/champ', { type: 'text', label: '' } ] ];

	registerBlockType( 'fluffless/etape', {
		edit( { attributes, setAttributes } ) {
			const blockProps = useBlockProps( { className: 'fluffless-etape' } );
			const innerBlocksProps = useInnerBlocksProps( {}, { allowedBlocks: ALLOWED, template: TEMPLATE } );
			return el(
				'fieldset',
				blockProps,
				el( RichText, {
					tagName: 'legend',
					value: attributes.title,
					placeholder: __( 'Step title', 'fluffless-suite' ),
					allowedFormats: [],
					withoutInteractiveFormatting: true,
					onChange: ( v ) => setAttributes( { title: v } ),
				} ),
				el( 'div', innerBlocksProps )
			);
		},
		save: () => el( InnerBlocks.Content ),
	} );
} )( window.wp );
