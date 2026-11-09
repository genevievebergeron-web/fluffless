/**
 * Bloc Fil d'Ariane : aperçu rendu par le serveur dans l'éditeur, réglage du séparateur.
 * JavaScript natif, sans étape de build ; chargé dans l'éditeur seulement.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, TextControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'fluffless-seo/fil-ariane', {
		edit( { attributes, setAttributes } ) {
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Breadcrumb', 'fluffless-seo' ) },
						el( TextControl, {
							label: __( 'Separator', 'fluffless-seo' ),
							help: __( 'Three characters at most. Hidden from screen readers.', 'fluffless-seo' ),
							value: attributes.separator,
							maxLength: 3,
							onChange: ( separator ) => setAttributes( { separator } ),
						} )
					)
				),
				el( ServerSideRender, { block: 'fluffless-seo/fil-ariane', attributes } )
			);
		},
		save: () => null,
	} );
} )( window.wp );
