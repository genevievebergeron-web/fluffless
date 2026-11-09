/**
 * Éditeur du bloc fluffless/vague. JavaScript natif, sans étape de build.
 * Le site reçoit le rendu serveur (Expression::render_vague()). L'aperçu reprend les mêmes
 * classes et les tracés passés par PHP (window.flufflessVague) : une seule source de vérité.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls, useSettings } = wp.blockEditor;
	const { PanelBody, RangeControl, SelectControl, ToggleControl } = wp.components;

	const data = Object.assign(
		{ shapes: {}, lines: [], accents: [], opacities: [ 10, 25, 50, 100 ], marks: {}, presets: { low: 32, medium: 64, high: 128 }, height: [ 16, 240, 64 ], colour: 'surface' },
		window.flufflessVague || {}
	);
	const isLine = ( shape ) => data.lines.includes( shape );
	const isAccent = ( shape ) => data.accents.includes( shape );

	const SHAPES = () => [
		{ value: 'wave', label: __( 'Wave', 'fluffless-suite' ) },
		{ value: 'soft', label: __( 'Soft curve', 'fluffless-suite' ) },
		{ value: 'peaks', label: __( 'Peaks', 'fluffless-suite' ) },
		{ value: 'tilt', label: __( 'Slant', 'fluffless-suite' ) },
		{ value: 'line', label: __( 'Thin line', 'fluffless-suite' ) },
		{ value: 'dots', label: __( 'Dotted line', 'fluffless-suite' ) },
		{ value: 'three-dots', label: __( 'Three dots', 'fluffless-suite' ) },
		{ value: 'ornament', label: __( 'Centred ornament', 'fluffless-suite' ) },
		{ value: 'disc', label: __( 'Disc', 'fluffless-suite' ) },
		{ value: 'half-moon', label: __( 'Half-moon', 'fluffless-suite' ) },
		{ value: 'ring', label: __( 'Ring', 'fluffless-suite' ) },
		{ value: 'drop', label: __( 'Drop', 'fluffless-suite' ) },
		{ value: 'dot-grid', label: __( 'Dot grid', 'fluffless-suite' ) },
	];

	const PRESETS = () => [
		{ value: 'low', label: __( 'Low', 'fluffless-suite' ) },
		{ value: 'medium', label: __( 'Medium', 'fluffless-suite' ) },
		{ value: 'high', label: __( 'High', 'fluffless-suite' ) },
		{ value: 'custom', label: __( 'Custom', 'fluffless-suite' ) },
	];
	const presetOf = ( height ) => Object.keys( data.presets ).find( ( key ) => data.presets[ key ] === height ) || 'custom';

	const POSITIONS = () => [
		{ value: 'bottom', label: __( 'Bottom of a section', 'fluffless-suite' ) },
		{ value: 'top', label: __( 'Top of a section', 'fluffless-suite' ) },
		{ value: 'both', label: __( 'Top and bottom', 'fluffless-suite' ) },
	];

	// Palette du thème : les mêmes couleurs que le reste de l'éditeur, jamais de valeur libre.
	const usePalette = () => {
		const settings = useSettings ? useSettings( 'color.palette' ) : [ [] ];
		const palette = Array.isArray( settings[ 0 ] ) ? settings[ 0 ] : [];
		return palette.map( ( c ) => ( { value: c.slug, label: c.name } ) );
	};

	registerBlockType( 'fluffless/vague', {
		edit( { attributes, setAttributes } ) {
			const { shape, position, height, flip, colour, opacity } = attributes;
			const palette = usePalette();
			const line = isLine( shape );
			const accent = isAccent( shape );
			const path = data.shapes[ shape ] || data.shapes.wave || '';
			const mark = data.marks[ shape ];
			const className = [ 'fluffless-vague', 'is-shape-' + shape, line ? 'is-line' : '', accent ? 'is-mark' : '', 'is-' + position, flip ? 'is-flip' : '' ].filter( Boolean ).join( ' ' );
			// Une ligne en couleur surface disparaît sur un fond surface : on passe au filet du thème ;
			// une forme d'accent prend la couleur accent. Une couleur choisie exprès reste.
			const setShape = ( value ) => {
				const next = { shape: value };
				if ( colour === data.colour && isLine( value ) ) {
					next.colour = 'divider';
				} else if ( colour === data.colour && isAccent( value ) ) {
					next.colour = 'accent';
				}
				setAttributes( next );
			};
			const preview = line || accent
				? mark && el( 'svg', { viewBox: mark[ 0 ], focusable: 'false', 'aria-hidden': 'true' }, el( 'path', { fill: 'currentColor', d: mark[ 1 ] } ) )
				: [ 'top', 'bottom' ].slice( 'both' === position ? 0 : 1 ).map( ( key ) =>
						el( 'svg', { key, viewBox: '0 0 1200 120', preserveAspectRatio: 'none', focusable: 'false', 'aria-hidden': 'true' }, el( 'path', { fill: 'currentColor', d: path } ) )
				  );
			const blockProps = useBlockProps( {
				className,
				style: { '--fluffless-vague-h': height + 'px', color: 'var(--wp--preset--color--' + colour + ')', opacity: opacity < 100 ? opacity / 100 : undefined },
			} );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Shape', 'fluffless-suite' ) },
						el( SelectControl, {
							label: __( 'Shape', 'fluffless-suite' ),
							value: shape,
							options: SHAPES(),
							onChange: setShape,
							__nextHasNoMarginBottom: true,
						} ),
						! line &&
							! accent &&
							el( SelectControl, {
							label: __( 'Position', 'fluffless-suite' ),
							help: __( 'At the top, the shape is mirrored so it points into the section.', 'fluffless-suite' ),
							value: position,
							options: POSITIONS(),
							onChange: ( value ) => setAttributes( { position: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( SelectControl, {
							label: __( 'Height', 'fluffless-suite' ),
							value: presetOf( height ),
							options: PRESETS(),
							onChange: ( value ) => data.presets[ value ] && setAttributes( { height: data.presets[ value ] } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( RangeControl, {
							label: __( 'Height (px)', 'fluffless-suite' ),
							value: height,
							min: data.height[ 0 ],
							max: data.height[ 1 ],
							onChange: ( value ) => setAttributes( { height: value ?? data.height[ 2 ] } ),
							__nextHasNoMarginBottom: true,
						} ),
						! line &&
							! accent &&
							el( ToggleControl, {
								label: __( 'Mirror horizontally', 'fluffless-suite' ),
								checked: flip,
								onChange: ( value ) => setAttributes( { flip: value } ),
								__nextHasNoMarginBottom: true,
							} ),
						palette.length > 0 &&
							el( SelectControl, {
								label: __( 'Colour', 'fluffless-suite' ),
								help: __( 'Usually the background colour of the next section.', 'fluffless-suite' ),
								value: colour,
								options: palette,
								onChange: ( value ) => setAttributes( { colour: value } ),
								__nextHasNoMarginBottom: true,
							} ),
						el( SelectControl, {
							label: __( 'Transparency', 'fluffless-suite' ),
							value: String( opacity ),
							options: data.opacities.map( ( o ) => ( { value: String( o ), label: o + ' %' } ) ),
							onChange: ( value ) => setAttributes( { opacity: Number( value ) } ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el( 'div', blockProps, preview || null )
			);
		},
		save: () => null,
	} );
} )( window.wp );
