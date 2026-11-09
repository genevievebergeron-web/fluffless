/**
 * Éditeur du bloc fluffless/prix (prix d'un Produit). JavaScript natif, sans étape de build.
 * Montant, devise et disponibilité en réglages ; aperçu en place, rendu final par le serveur
 * (Sections::render_prix) et repris en offre schema.org par le module SEO.
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, TextControl } = wp.components;
	const CURRENCIES = [ 'CAD', 'USD', 'EUR' ].map( ( c ) => ( { value: c, label: c } ) );
	const LABELS = () => ( {
		soldout: __( 'Sold out', 'fluffless-suite' ),
		preorder: __( 'Pre-order', 'fluffless-suite' ),
	} );

	wp.blocks.registerBlockType( 'fluffless/prix', {
		edit( { attributes, setAttributes } ) {
			const set = ( key ) => ( value ) => setAttributes( { [ key ]: value } );
			const price = ( attributes.price || '' ).trim();
			const label = LABELS()[ attributes.availability ];
			return S.el(
				wp.element.Fragment,
				null,
				S.el(
					InspectorControls,
					null,
					S.el(
						PanelBody,
						{ title: __( 'Price', 'fluffless-suite' ) },
						S.el( TextControl, {
							label: __( 'Amount', 'fluffless-suite' ),
							value: attributes.price,
							onChange: set( 'price' ),
							help: __( 'Numbers only, such as 49.99. Leave empty to show only the availability.', 'fluffless-suite' ),
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} ),
						S.choice( __( 'Currency', 'fluffless-suite' ), attributes.currency, CURRENCIES, set( 'currency' ) ),
						S.choice(
							__( 'Availability', 'fluffless-suite' ),
							attributes.availability,
							[
								{ value: 'available', label: __( 'Available', 'fluffless-suite' ) },
								{ value: 'soldout', label: __( 'Sold out', 'fluffless-suite' ) },
								{ value: 'preorder', label: __( 'Pre-order', 'fluffless-suite' ) },
							],
							set( 'availability' )
						)
					)
				),
				S.el(
					'p',
					useBlockProps( { className: 'fluffless-produit__price' } ),
					S.el( 'data', { className: 'fluffless-produit__amount' }, price ? price + ' ' + attributes.currency : __( 'Price', 'fluffless-suite' ) ),
					label ? S.el( 'span', { className: 'fluffless-produit__availability' }, label ) : null
				)
			);
		},
		save: () => null,
	} );
} )( window.wp, window.flufflessSections );
