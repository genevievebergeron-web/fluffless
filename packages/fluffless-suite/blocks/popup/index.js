/**
 * Éditeur du bloc fluffless/popup. JavaScript natif, sans étape de build.
 * Dans l'éditeur, le contenu reste affiché sous le bouton pour être modifié ; le site reçoit
 * le rendu serveur (Expression::render_popup()) : popover ou dialog natif, 0 JS.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, useInnerBlocksProps, InnerBlocks, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl } = wp.components;

	const TEMPLATE = [
		[ 'core/heading', { level: 2 } ],
		[ 'core/paragraph', {} ],
	];

	registerBlockType( 'fluffless/popup', {
		edit( { attributes, setAttributes } ) {
			const { mode, label, closeLabel } = attributes;
			const isBanner = mode === 'banner';
			const blockProps = useBlockProps( { className: 'fluffless-popup is-' + mode } );
			const innerBlocksProps = useInnerBlocksProps(
				{ className: isBanner ? 'fluffless-popup__content' : 'fluffless-popup__panel' },
				{ template: TEMPLATE }
			);
			const closeText = closeLabel || __( 'Close', 'fluffless-suite' );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Pop-up', 'fluffless-suite' ) },
						el( SelectControl, {
							label: __( 'Type', 'fluffless-suite' ),
							help: isBanner
								? __( 'Shown when the page loads. Visitors can close it.', 'fluffless-suite' )
								: __( 'Opens only when a visitor clicks the button. Escape or a click outside closes it.', 'fluffless-suite' ),
							value: mode,
							options: [
								{ value: 'popup', label: __( 'Window opened by a button', 'fluffless-suite' ) },
								{ value: 'banner', label: __( 'Announcement bar', 'fluffless-suite' ) },
							],
							onChange: ( value ) => setAttributes( { mode: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( TextControl, {
							label: isBanner ? __( 'Name for screen readers', 'fluffless-suite' ) : __( 'Button text', 'fluffless-suite' ),
							placeholder: __( 'Learn more', 'fluffless-suite' ),
							value: label,
							maxLength: 80,
							onChange: ( value ) => setAttributes( { label: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( TextControl, {
							label: __( 'Close button text', 'fluffless-suite' ),
							placeholder: __( 'Close', 'fluffless-suite' ),
							value: closeLabel,
							maxLength: 80,
							onChange: ( value ) => setAttributes( { closeLabel: value } ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el(
					'div',
					blockProps,
					! isBanner && el( 'span', { className: 'wp-element-button' }, label || __( 'Learn more', 'fluffless-suite' ) ),
					el( 'div', innerBlocksProps ),
					el( 'span', { className: 'fluffless-popup__close' }, closeText )
				)
			);
		},
		save: () => el( InnerBlocks.Content ),
	} );
} )( window.wp );
