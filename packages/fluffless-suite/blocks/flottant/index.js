/**
 * Éditeur du bloc fluffless/flottant. JavaScript natif, sans étape de build.
 * Le site reçoit le rendu serveur (Blocks\Flottant::render()). L'aperçu reprend les mêmes classes,
 * en place dans le canevas (jamais fixé), avec les icônes et les couleurs de texte passées par PHP
 * (window.flufflessFlottant) : une seule source de vérité. Le sceau est montré sans son anneau.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls, useSettings } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl, ToggleControl } = wp.components;

	const data = Object.assign( { icons: {}, text: {}, colour: 'accent', maxText: 80 }, window.flufflessFlottant || {} );

	const MODES = () => [
		{ value: 'bouton', label: __( 'Button', 'fluffless-suite' ) },
		{ value: 'sceau', label: __( 'Turning seal', 'fluffless-suite' ) },
		{ value: 'pastille', label: __( 'Status dot', 'fluffless-suite' ) },
	];
	const CORNERS = () => [
		{ value: 'bottom-right', label: __( 'Bottom right', 'fluffless-suite' ) },
		{ value: 'bottom-left', label: __( 'Bottom left', 'fluffless-suite' ) },
		{ value: 'top-right', label: __( 'Top right', 'fluffless-suite' ) },
		{ value: 'top-left', label: __( 'Top left', 'fluffless-suite' ) },
	];
	const ICONS = () => [
		{ value: '', label: __( 'No icon', 'fluffless-suite' ) },
		{ value: 'calendar', label: __( 'Calendar', 'fluffless-suite' ) },
		{ value: 'phone', label: __( 'Phone', 'fluffless-suite' ) },
		{ value: 'bubble', label: __( 'Speech bubble', 'fluffless-suite' ) },
		{ value: 'arrow', label: __( 'Arrow', 'fluffless-suite' ) },
	];

	// Palette du thème : les mêmes couleurs que le reste de l'éditeur, jamais de valeur libre.
	const usePalette = () => {
		const settings = useSettings ? useSettings( 'color.palette' ) : [ [] ];
		const palette = Array.isArray( settings[ 0 ] ) ? settings[ 0 ] : [];
		return palette.map( ( c ) => ( { value: c.slug, label: c.name } ) );
	};

	registerBlockType( 'fluffless/flottant', {
		edit( { attributes, setAttributes } ) {
			const { mode, text, url, newTab, icon, mobileBar, corner, colour } = attributes;
			const palette = usePalette();
			const shown = text || __( 'Book a call', 'fluffless-suite' );
			const blockProps = useBlockProps( {
				className: [ 'fl-flottant', 'fl-flottant--' + mode, 'is-' + corner ].join( ' ' ),
				style: {
					'--fl-f-bg': 'var(--wp--preset--color--' + colour + ')',
					'--fl-f-fg': 'var(--wp--preset--color--' + ( data.text[ colour ] || 'contrast' ) + ')',
				},
			} );
			// Tracés de la liste curée du bloc Icône, générés par tools/build-icons.mjs : jamais une saisie.
			const iconNode =
				icon && data.icons[ icon ] && 'pastille' !== mode
					? el( 'span', { className: 'fl-flottant__icon', dangerouslySetInnerHTML: { __html: data.icons[ icon ] } } )
					: null;

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Floating element', 'fluffless-suite' ) },
						el( SelectControl, {
							label: __( 'Kind', 'fluffless-suite' ),
							help: __( 'Only the first floating element of a page is shown on the site.', 'fluffless-suite' ),
							value: mode,
							options: MODES(),
							onChange: ( value ) => setAttributes( { mode: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( TextControl, {
							label: __( 'Text', 'fluffless-suite' ),
							help: 'sceau' === mode ? __( 'Keep it short: the text is repeated around the seal and cut if it is too long.', 'fluffless-suite' ) : undefined,
							value: text,
							placeholder: __( 'Book a call', 'fluffless-suite' ),
							maxLength: data.maxText,
							onChange: ( value ) => setAttributes( { text: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( TextControl, {
							label: __( 'Link', 'fluffless-suite' ),
							help:
								'bouton' === mode
									? __( 'A page address, an anchor (#contact), an email (mailto:) or a phone number (tel:). Without a link, the button is not shown on the site.', 'fluffless-suite' )
									: __( 'Optional. A page address, an anchor (#contact), an email (mailto:) or a phone number (tel:).', 'fluffless-suite' ),
							value: url,
							onChange: ( value ) => setAttributes( { url: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						'' !== url &&
							el( ToggleControl, {
								label: __( 'Open in a new tab', 'fluffless-suite' ),
								checked: newTab,
								onChange: ( value ) => setAttributes( { newTab: value } ),
								__nextHasNoMarginBottom: true,
							} ),
						'pastille' !== mode &&
							el( SelectControl, {
								label: __( 'Icon', 'fluffless-suite' ),
								value: icon,
								options: ICONS(),
								onChange: ( value ) => setAttributes( { icon: value } ),
								__nextHasNoMarginBottom: true,
							} ),
						el( SelectControl, {
							label: __( 'Corner', 'fluffless-suite' ),
							help: __( 'In a top corner, the element can cover a sticky header.', 'fluffless-suite' ),
							value: corner,
							options: CORNERS(),
							onChange: ( value ) => setAttributes( { corner: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						palette.length > 0 &&
							el( SelectControl, {
								label: __( 'Colour', 'fluffless-suite' ),
								help: __( 'The text colour is picked from the palette to stay readable on this background.', 'fluffless-suite' ),
								value: colour,
								options: palette,
								onChange: ( value ) => setAttributes( { colour: value } ),
								__nextHasNoMarginBottom: true,
							} ),
						'bouton' === mode &&
							el( ToggleControl, {
								label: __( 'Full-width bar on small screens', 'fluffless-suite' ),
								help: __( 'Under 600 px, the button becomes a bar at the bottom of the screen.', 'fluffless-suite' ),
								checked: mobileBar,
								onChange: ( value ) => setAttributes( { mobileBar: value } ),
								__nextHasNoMarginBottom: true,
							} )
					)
				),
				el(
					'div',
					blockProps,
					el(
						'span',
						{ className: 'fl-flottant__link' },
						'pastille' === mode && el( 'span', { className: 'fl-flottant__dot', 'aria-hidden': 'true' } ),
						'sceau' !== mode && iconNode,
						el( 'span', null, shown ),
						'sceau' === mode && iconNode
					)
				)
			);
		},
		save: () => null,
	} );
} )( window.wp );
