/**
 * Éditeur du bloc fluffless/calendrier. JavaScript natif, sans étape de build.
 * L'aperçu est le rendu du serveur (Blocks\Calendrier::render()), le même que sur le site.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, Disabled } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType( 'fluffless/calendrier', {
		edit( { attributes, setAttributes } ) {
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Settings', 'fluffless-suite' ) },
						el( SelectControl, {
							label: __( 'Month title level', 'fluffless-suite' ),
							help: __( 'Pick the level that follows the heading above the calendar.', 'fluffless-suite' ),
							value: String( attributes.level ),
							options: [ 2, 3, 4 ].map( ( n ) => ( { value: String( n ), label: 'H' + n } ) ),
							onChange: ( value ) => setAttributes( { level: parseInt( value, 10 ) } ),
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} )
					)
				),
				el( Disabled, null, el( ServerSideRender, { block: 'fluffless/calendrier', attributes } ) )
			);
		},
		save: () => null,
	} );
} )( window.wp );
