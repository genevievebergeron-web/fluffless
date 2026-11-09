/**
 * Module Visibilité de Fluffless Suite : choix « Afficher sur » dans l'inspecteur de chaque bloc.
 * JavaScript natif, sans étape de build. Chargé dans l'éditeur seulement.
 * La classe CSS est ajoutée au rendu, côté serveur (Visibility::render()) : le balisage
 * enregistré ne change pas, donc aucun bloc ne devient invalide.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { addFilter } = wp.hooks;
	const { createHigherOrderComponent } = wp.compose;
	const { InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl } = wp.components;

	const ATTRIBUTE = 'flufflessVisibility';
	const MOBILE = 'flufflessMobile';
	const PRESETS = window.flufflessDevice || { spacing: [], sizes: [] };

	addFilter( 'blocks.registerBlockType', 'fluffless/visibility/attribute', ( settings ) => {
		if ( settings.attributes && settings.attributes[ ATTRIBUTE ] && settings.attributes[ MOBILE ] ) {
			return settings;
		}
		return {
			...settings,
			attributes: {
				...settings.attributes,
				[ ATTRIBUTE ]: { type: 'string', default: '' },
				[ MOBILE ]: { type: 'object', default: {} },
			},
		};
	} );

	// Liste d'options : « Comme partout » puis les préréglages du thème.
	const presetOptions = ( list ) => [ { value: '', label: __( 'Same as everywhere', 'fluffless-suite' ) } ].concat(
		list.map( ( p ) => ( { value: p.slug, label: p.name } ) )
	);

	// Panneau « Sur mobile » : trois surcharges rendues par le serveur, sous 782 px seulement.
	const mobilePanel = ( props ) => {
		const mobile = props.attributes[ MOBILE ] || {};
		const set = ( key, next ) => {
			const value = { ...mobile };
			if ( next ) {
				value[ key ] = next;
			} else {
				delete value[ key ];
			}
			props.setAttributes( { [ MOBILE ]: value } );
		};
		const active = Boolean( mobile.padding || mobile.align || mobile.size );
		return el(
			PanelBody,
			{ title: __( 'On mobile', 'fluffless-suite' ), initialOpen: active },
			el( SelectControl, {
				label: __( 'Padding on mobile', 'fluffless-suite' ),
				value: mobile.padding || '',
				options: presetOptions( PRESETS.spacing ),
				onChange: ( next ) => set( 'padding', next ),
				__nextHasNoMarginBottom: true,
			} ),
			el( SelectControl, {
				label: __( 'Text alignment on mobile', 'fluffless-suite' ),
				value: mobile.align || '',
				options: [
					{ value: '', label: __( 'Same as everywhere', 'fluffless-suite' ) },
					{ value: 'left', label: __( 'Left', 'fluffless-suite' ) },
					{ value: 'center', label: __( 'Center', 'fluffless-suite' ) },
					{ value: 'right', label: __( 'Right', 'fluffless-suite' ) },
				],
				onChange: ( next ) => set( 'align', next ),
				__nextHasNoMarginBottom: true,
			} ),
			el( SelectControl, {
				label: __( 'Text size on mobile', 'fluffless-suite' ),
				value: mobile.size || '',
				options: presetOptions( PRESETS.sizes ),
				help: __( 'Applied on the site under 782 px, not in this editor. Sizes are the theme presets, so the site stays consistent.', 'fluffless-suite' ),
				onChange: ( next ) => set( 'size', next ),
				__nextHasNoMarginBottom: true,
			} )
		);
	};

	const withVisibilityControl = createHigherOrderComponent(
		( BlockEdit ) => ( props ) => {
			if ( ! props.isSelected ) {
				return el( BlockEdit, props );
			}
			const value = props.attributes[ ATTRIBUTE ] || '';
			return el(
				wp.element.Fragment,
				null,
				el( BlockEdit, props ),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Show on', 'fluffless-suite' ), initialOpen: '' !== value },
						el( SelectControl, {
							label: __( 'Show this block', 'fluffless-suite' ),
							value,
							options: [
								{ value: '', label: __( 'Everywhere', 'fluffless-suite' ) },
								{ value: 'desktop', label: __( 'Desktop only', 'fluffless-suite' ) },
								{ value: 'mobile', label: __( 'Mobile only', 'fluffless-suite' ) },
							],
							help: __( 'Mobile means screens under 782 px wide. The hidden block stays in the page code.', 'fluffless-suite' ),
							onChange: ( next ) => props.setAttributes( { [ ATTRIBUTE ]: next } ),
							__nextHasNoMarginBottom: true,
						} )
					),
					mobilePanel( props )
				)
			);
		},
		'withFlufflessVisibility'
	);

	addFilter( 'editor.BlockEdit', 'fluffless/visibility/control', withVisibilityControl );
} )( window.wp );
