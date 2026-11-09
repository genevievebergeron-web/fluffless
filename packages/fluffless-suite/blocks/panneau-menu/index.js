/**
 * Éditeur du bloc fluffless/panneau-menu. JavaScript natif, sans étape de build.
 * S'insère dans un bloc Navigation du cœur (parent déclaré dans block.json). Dans l'éditeur, le
 * panneau reste ouvert sous son libellé pour être modifié ; le site reçoit le rendu serveur
 * (Expression::render_panneau()) : un <details> natif, 0 JS.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, useInnerBlocksProps, InnerBlocks, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl } = wp.components;

	const column = ( title ) => [
		'core/column',
		{},
		[
			[ 'core/heading', { level: 3, placeholder: title } ],
			[ 'core/paragraph', { placeholder: __( 'A line that says what this part offers.', 'fluffless-suite' ) } ],
		],
	];
	const TEMPLATE = [
		[
			'core/columns',
			{},
			[ column( __( 'First group', 'fluffless-suite' ) ), column( __( 'Second group', 'fluffless-suite' ) ), column( __( 'Third group', 'fluffless-suite' ) ) ],
		],
	];

	registerBlockType( 'fluffless/panneau-menu', {
		edit( { attributes, setAttributes } ) {
			const { label, width } = attributes;
			const blockProps = useBlockProps( { className: 'wp-block-navigation-item fluffless-panneau-menu is-width-' + width } );
			const innerBlocksProps = useInnerBlocksProps( { className: 'fluffless-panneau-menu__panel' }, { template: TEMPLATE } );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Menu panel', 'fluffless-suite' ) },
						el( TextControl, {
							label: __( 'Label in the menu', 'fluffless-suite' ),
							placeholder: __( 'Services', 'fluffless-suite' ),
							value: label,
							maxLength: 80,
							onChange: ( value ) => setAttributes( { label: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( SelectControl, {
							label: __( 'Panel width', 'fluffless-suite' ),
							help: __( 'On phones the panel stacks inside the menu either way. A click on the label opens and closes it; only one panel stays open at a time.', 'fluffless-suite' ),
							value: width,
							options: [
								{ value: 'full', label: __( 'Full width of the header', 'fluffless-suite' ) },
								{ value: 'content', label: __( 'As wide as its content', 'fluffless-suite' ) },
							],
							onChange: ( value ) => setAttributes( { width: value } ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el(
					'li',
					blockProps,
					el( 'span', { className: 'wp-block-navigation-item__content fluffless-panneau-menu__summary' }, label || __( 'Services', 'fluffless-suite' ) ),
					el( 'div', innerBlocksProps )
				)
			);
		},
		save: () => el( InnerBlocks.Content ),
	} );
} )( window.wp );
