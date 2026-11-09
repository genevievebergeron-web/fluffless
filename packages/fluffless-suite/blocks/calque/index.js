/**
 * Éditeur du bloc fluffless/calque. JavaScript natif, sans étape de build.
 * Le site reçoit le rendu serveur (Canvas::render_calque()) : une couche et une balise img.
 * Mêmes classes et variables ici (config.calqueLayer), sur l'enveloppe du bloc, pour que
 * l'aperçu suive le site.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls, BlockControls, MediaPlaceholder, MediaReplaceFlow } = wp.blockEditor;
	const { PanelBody, RangeControl, SelectControl, ToggleControl, TextControl, Notice } = wp.components;
	const UnitControl = wp.components.UnitControl || wp.components.__experimentalUnitControl;

	const config = () => window.flufflessCanvas || { canDesign: false };

	const BLEND_LABELS = () => [
		{ value: 'normal', label: __( 'Normal', 'fluffless-suite' ) },
		{ value: 'multiply', label: __( 'Multiply', 'fluffless-suite' ) },
		{ value: 'screen', label: __( 'Screen', 'fluffless-suite' ) },
		{ value: 'overlay', label: __( 'Overlay', 'fluffless-suite' ) },
		{ value: 'darken', label: __( 'Darken', 'fluffless-suite' ) },
		{ value: 'lighten', label: __( 'Lighten', 'fluffless-suite' ) },
		{ value: 'color-burn', label: __( 'Colour burn', 'fluffless-suite' ) },
		{ value: 'soft-light', label: __( 'Soft light', 'fluffless-suite' ) },
		{ value: 'difference', label: __( 'Difference', 'fluffless-suite' ) },
		{ value: 'luminosity', label: __( 'Luminosity', 'fluffless-suite' ) },
	];

	const PIN_LABELS = () => [
		{ value: 'center', label: __( 'Centre', 'fluffless-suite' ) },
		{ value: 'top-left', label: __( 'Top left corner', 'fluffless-suite' ) },
		{ value: 'top-right', label: __( 'Top right corner', 'fluffless-suite' ) },
		{ value: 'bottom-left', label: __( 'Bottom left corner', 'fluffless-suite' ) },
		{ value: 'bottom-right', label: __( 'Bottom right corner', 'fluffless-suite' ) },
	];

	const UNITS = [
		{ value: 'px', label: 'px', default: 240 },
		{ value: 'rem', label: 'rem', default: 15 },
	];

	/** Seulement l'image de la médiathèque : elle passe par les modules Images et MediaWebp. */
	const pick = ( setAttributes ) => ( media ) => {
		if ( media && media.id ) {
			setAttributes( { id: media.id, url: media.url || '' } );
		}
	};

	function range( label, key, min, max, attributes, setAttributes, help ) {
		return el( RangeControl, {
			label,
			help,
			value: attributes[ key ],
			min,
			max,
			onChange: ( value ) => setAttributes( { [ key ]: 'number' === typeof value ? value : undefined } ),
			allowReset: true,
			// Sans défaut (réglages petit écran) : la remise à zéro revient à « comme sur ordinateur ».
			resetFallbackValue: wp.blocks.getBlockType( 'fluffless/calque' ).attributes[ key ].default,
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
		} );
	}

	function select( label, key, options, attributes, onChange, help ) {
		return el( SelectControl, {
			label,
			help,
			value: attributes[ key ],
			options,
			onChange,
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
		} );
	}

	function length( label, key, attributes, setAttributes ) {
		return el( UnitControl, {
			label,
			value: attributes[ key ],
			units: UNITS,
			min: 0,
			onChange: ( value ) => setAttributes( { [ key ]: value || '' } ),
			__next40pxDefaultSize: true,
		} );
	}

	/** Aide des positions : elle nomme le point réellement placé et le bord d'où l'on mesure. */
	function positionHelp( pin ) {
		switch ( pin ) {
			case 'top-left':
				return [ __( 'From the left edge of the section to the left edge of the image.', 'fluffless-suite' ), __( 'From the top edge of the section to the top edge of the image.', 'fluffless-suite' ) ];
			case 'top-right':
				return [ __( 'From the right edge of the section to the right edge of the image.', 'fluffless-suite' ), __( 'From the top edge of the section to the top edge of the image.', 'fluffless-suite' ) ];
			case 'bottom-left':
				return [ __( 'From the left edge of the section to the left edge of the image.', 'fluffless-suite' ), __( 'From the bottom edge of the section to the bottom edge of the image.', 'fluffless-suite' ) ];
			case 'bottom-right':
				return [ __( 'From the right edge of the section to the right edge of the image.', 'fluffless-suite' ), __( 'From the bottom edge of the section to the bottom edge of the image.', 'fluffless-suite' ) ];
			default:
				return [ __( 'Where the centre of the image sits, from the left edge of its section.', 'fluffless-suite' ), __( 'Where the centre of the image sits, from the top edge of its section.', 'fluffless-suite' ) ];
		}
	}

	function Inspector( { attributes, setAttributes } ) {
		const { canDesign, SizeWarning } = config();
		if ( ! canDesign ) {
			return el(
				InspectorControls,
				null,
				el(
					PanelBody,
					null,
					el( 'p', null, __( 'Decorative layer placed by a designer. Studio mode unlocks its settings.', 'fluffless-suite' ) )
				)
			);
		}
		const [ helpX, helpY ] = positionHelp( attributes.pin );
		// Changer d'ancrage remet la position à son origine naturelle : le centre au milieu, un coin collé à son coin.
		const onPin = ( pin ) => {
			const origin = 'center' === pin ? 50 : 0;
			setAttributes( { pin, x: origin, y: origin, xMobile: undefined, yMobile: undefined } );
		};
		return el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Position', 'fluffless-suite' ) },
				SizeWarning ? el( SizeWarning, { id: attributes.id } ) : null,
				select( __( 'Anchor', 'fluffless-suite' ), 'pin', PIN_LABELS(), attributes, onPin, __( 'The point of the image that stays in place when the section grows or shrinks.', 'fluffless-suite' ) ),
				range( __( 'Horizontal position (%)', 'fluffless-suite' ), 'x', -50, 150, attributes, setAttributes, helpX ),
				range( __( 'Vertical position (%)', 'fluffless-suite' ), 'y', -50, 150, attributes, setAttributes, helpY ),
				range( __( 'Rotation (degrees)', 'fluffless-suite' ), 'rotate', -180, 180, attributes, setAttributes ),
				range( __( 'Opacity (%)', 'fluffless-suite' ), 'opacity', 0, 100, attributes, setAttributes )
			),
			el(
				PanelBody,
				{ title: __( 'Size', 'fluffless-suite' ) },
				range( __( 'Width (% of the section)', 'fluffless-suite' ), 'width', 2, 200, attributes, setAttributes, __( 'Follows the width of the section, not of the window.', 'fluffless-suite' ) ),
				length( __( 'Minimum width', 'fluffless-suite' ), 'minWidth', attributes, setAttributes ),
				length( __( 'Maximum width', 'fluffless-suite' ), 'maxWidth', attributes, setAttributes )
			),
			el(
				PanelBody,
				{ title: __( 'Small screens', 'fluffless-suite' ), initialOpen: false },
				el( 'p', null, __( 'Under 782 px wide. Leave empty to keep the computer settings.', 'fluffless-suite' ) ),
				el( ToggleControl, {
					label: __( 'Hide on small screens', 'fluffless-suite' ),
					help: __( 'The image is not downloaded there.', 'fluffless-suite' ),
					checked: attributes.hideMobile,
					onChange: ( hideMobile ) => setAttributes( { hideMobile } ),
					__nextHasNoMarginBottom: true,
				} ),
				attributes.hideMobile
					? null
					: [
							range( __( 'Horizontal position (%)', 'fluffless-suite' ), 'xMobile', -50, 150, attributes, setAttributes ),
							range( __( 'Vertical position (%)', 'fluffless-suite' ), 'yMobile', -50, 150, attributes, setAttributes ),
							range( __( 'Width (% of the section)', 'fluffless-suite' ), 'widthMobile', 2, 200, attributes, setAttributes ),
					  ].map( ( control, index ) => el( 'div', { key: index }, control ) )
			),
			el(
				PanelBody,
				{ title: __( 'Layer', 'fluffless-suite' ) },
				select(
					__( 'Place the image', 'fluffless-suite' ),
					'layer',
					[
						{ value: 'back', label: __( 'Behind the text', 'fluffless-suite' ) },
						{ value: 'front', label: __( 'In front of the text', 'fluffless-suite' ) },
					],
					attributes,
					( layer ) => setAttributes( { layer } )
				),
				select( __( 'Blend mode', 'fluffless-suite' ), 'blend', BLEND_LABELS(), attributes, ( blend ) => setAttributes( { blend } ) ),
				select(
					__( 'Edges', 'fluffless-suite' ),
					'clip',
					[
						{ value: 'clip', label: __( 'Stay inside the section', 'fluffless-suite' ) },
						{ value: 'overflow', label: __( 'Spill over above and below', 'fluffless-suite' ) },
					],
					attributes,
					( clip ) => setAttributes( { clip } ),
					__( 'Never wider than the section: no sideways scrolling on phones.', 'fluffless-suite' )
				),
				'clip' === attributes.clip ? range( __( 'Clip margin (px)', 'fluffless-suite' ), 'clipMargin', 0, 64, attributes, setAttributes, __( 'Lets the image go slightly past the top and bottom edges before it is cut.', 'fluffless-suite' ) ) : null
			),
			el(
				PanelBody,
				{ title: __( 'Accessibility', 'fluffless-suite' ), initialOpen: ! attributes.decorative },
				el( ToggleControl, {
					label: __( 'Decorative image', 'fluffless-suite' ),
					help: __( 'Hidden from screen readers, with empty alternative text. Turn off if the image carries meaning.', 'fluffless-suite' ),
					checked: attributes.decorative,
					onChange: ( decorative ) => setAttributes( { decorative } ),
					__nextHasNoMarginBottom: true,
				} ),
				attributes.decorative
					? null
					: el( TextControl, {
							label: __( 'Alternative text', 'fluffless-suite' ),
							value: attributes.alt,
							onChange: ( alt ) => setAttributes( { alt } ),
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
					  } )
			)
		);
	}

	registerBlockType( 'fluffless/calque', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			const { canDesign, calqueLayer } = config();
			const hasImage = !! ( attributes.id && attributes.url && calqueLayer );
			// Un seul appel du hook, quel que soit l'état : l'ordre des hooks reste stable.
			const blockProps = useBlockProps( hasImage ? calqueLayer( attributes ) : {} );
			if ( ! hasImage ) {
				return el(
					'div',
					blockProps,
					canDesign
						? el( MediaPlaceholder, {
								icon: 'images-alt2',
								labels: {
									title: __( 'Image layer', 'fluffless-suite' ),
									instructions: __( 'Choose an image from the media library. You then place it with the Position settings.', 'fluffless-suite' ),
								},
								allowedTypes: [ 'image' ],
								disableMediaButtons: false,
								onSelect: pick( setAttributes ),
						  } )
						: el( Notice, { status: 'info', isDismissible: false }, __( 'Empty image layer.', 'fluffless-suite' ) )
				);
			}
			return el(
				wp.element.Fragment,
				null,
				canDesign
					? el(
							BlockControls,
							{ group: 'other' },
							el( MediaReplaceFlow, {
								mediaId: attributes.id,
								mediaURL: attributes.url,
								allowedTypes: [ 'image' ],
								accept: 'image/*',
								onSelect: pick( setAttributes ),
								name: __( 'Replace', 'fluffless-suite' ),
							} )
					  )
					: null,
				el( Inspector, props ),
				el( 'span', blockProps, el( 'img', { className: 'fluffless-calque', src: attributes.url, alt: '', draggable: false } ) )
			);
		},
		save: () => null,
	} );
} )( window.wp );
