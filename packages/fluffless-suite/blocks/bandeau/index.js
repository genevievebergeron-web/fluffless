/**
 * Éditeur du bloc fluffless/bandeau. JavaScript natif, sans étape de build.
 * Le site reçoit le rendu serveur (Blocks\Bandeau::render()). L'aperçu reprend les mêmes classes,
 * immobile et replié, avec les séparateurs, les corps, les bornes et les mots par défaut passés
 * par PHP (window.flufflessBandeau) : une seule source de vérité.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __, sprintf } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls, useSettings } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl, Button, Flex, FlexBlock, FlexItem } = wp.components;

	const data = Object.assign(
		{ separators: {}, sizes: {}, defaults: [], text: {}, minItems: 2, maxItems: 12, maxItem: 60 },
		window.flufflessBandeau || {}
	);

	const SEPARATORS = () => [
		{ value: 'dot', label: __( 'Dot', 'fluffless-suite' ) },
		{ value: 'star', label: __( 'Star', 'fluffless-suite' ) },
		{ value: 'slash', label: __( 'Slash', 'fluffless-suite' ) },
		{ value: 'arrow', label: __( 'Arrow', 'fluffless-suite' ) },
	];
	const SPEEDS = () => [
		{ value: 'slow', label: __( 'Slow', 'fluffless-suite' ) },
		{ value: 'medium', label: __( 'Medium', 'fluffless-suite' ) },
		{ value: 'fast', label: __( 'Fast', 'fluffless-suite' ) },
	];
	const DIRECTIONS = () => [
		{ value: 'left', label: __( 'To the left', 'fluffless-suite' ) },
		{ value: 'right', label: __( 'To the right', 'fluffless-suite' ) },
	];
	const LOOKS = () => [
		{ value: 'accent', label: __( 'Accent band', 'fluffless-suite' ) },
		{ value: 'outline', label: __( 'Lines above and below', 'fluffless-suite' ) },
		{ value: 'stroke', label: __( 'Stroked letters', 'fluffless-suite' ) },
	];
	const SIZES = () => [
		{ value: 'medium', label: __( 'Medium', 'fluffless-suite' ) },
		{ value: 'large', label: __( 'Large', 'fluffless-suite' ) },
		{ value: 'giant', label: __( 'Giant', 'fluffless-suite' ) },
	];

	// Palette du thème : les mêmes couleurs que le reste de l'éditeur, jamais de valeur libre.
	const usePalette = () => {
		const settings = useSettings ? useSettings( 'color.palette' ) : [ [] ];
		const palette = Array.isArray( settings[ 0 ] ) ? settings[ 0 ] : [];
		return [ { value: '', label: __( 'Automatic', 'fluffless-suite' ) } ].concat( palette.map( ( c ) => ( { value: c.slug, label: c.name } ) ) );
	};

	const preset = ( slug ) => 'var(--wp--preset--color--' + slug + ')';

	registerBlockType( 'fluffless/bandeau', {
		edit( { attributes, setAttributes } ) {
			const { items, separator, speed, direction, look, size, colour } = attributes;
			const palette = usePalette();
			// Sous le minimum, le site montre les mots par défaut : la liste éditée part d'eux.
			const words = Array.isArray( items ) && items.length >= data.minItems ? items : data.defaults;
			const setWords = ( next ) => setAttributes( { items: next.slice( 0, data.maxItems ) } );

			const style = {};
			if ( data.sizes[ size ] ) {
				style[ '--fl-b-s' ] = data.sizes[ size ];
			}
			if ( 'accent' === look ) {
				const background = colour || 'accent';
				style[ '--fl-b-bg' ] = preset( background );
				style[ '--fl-b-fg' ] = preset( data.text[ background ] || 'contrast' );
			} else if ( colour ) {
				style[ '--fl-b-fg' ] = preset( colour );
			}
			const blockProps = useBlockProps( { className: 'fl-bandeau is-look-' + look, style } );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Keywords', 'fluffless-suite' ) },
						el(
							'p',
							{ className: 'components-base-control__help' },
							sprintf(
								/* translators: 1: minimum number of keywords, 2: maximum number of keywords, 3: maximum number of characters. */
								__( '%1$d to %2$d keywords, %3$d characters at most each.', 'fluffless-suite' ),
								data.minItems,
								data.maxItems,
								data.maxItem
							)
						),
						words.map( ( word, index ) =>
							el(
								Flex,
								{ key: index, align: 'flex-end' },
								el(
									FlexBlock,
									null,
									el( TextControl, {
										/* translators: %d: position of the keyword in the list. */
										label: sprintf( __( 'Keyword %d', 'fluffless-suite' ), index + 1 ),
										value: word,
										maxLength: data.maxItem,
										onChange: ( value ) => setWords( words.map( ( w, i ) => ( i === index ? value : w ) ) ),
										__nextHasNoMarginBottom: true,
									} )
								),
								el(
									FlexItem,
									null,
									el( Button, {
										icon: 'trash',
										/* translators: %d: position of the keyword in the list. */
										label: sprintf( __( 'Remove keyword %d', 'fluffless-suite' ), index + 1 ),
										disabled: words.length <= data.minItems,
										onClick: () => setWords( words.filter( ( w, i ) => i !== index ) ),
									} )
								)
							)
						),
						el(
							Button,
							{
								variant: 'secondary',
								disabled: words.length >= data.maxItems,
								onClick: () => setWords( words.concat( __( 'Keyword', 'fluffless-suite' ) ) ),
								style: { marginTop: '12px' },
							},
							__( 'Add a keyword', 'fluffless-suite' )
						)
					),
					el(
						PanelBody,
						{ title: __( 'Scrolling and look', 'fluffless-suite' ) },
						el( SelectControl, {
							label: __( 'Separator', 'fluffless-suite' ),
							value: separator,
							options: SEPARATORS(),
							onChange: ( value ) => setAttributes( { separator: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( SelectControl, {
							label: __( 'Speed', 'fluffless-suite' ),
							help: __( 'On the site, visitors get a Pause button, and nothing moves for those who asked for reduced motion.', 'fluffless-suite' ),
							value: speed,
							options: SPEEDS(),
							onChange: ( value ) => setAttributes( { speed: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( SelectControl, {
							label: __( 'Direction', 'fluffless-suite' ),
							value: direction,
							options: DIRECTIONS(),
							onChange: ( value ) => setAttributes( { direction: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( SelectControl, {
							label: __( 'Look', 'fluffless-suite' ),
							value: look,
							options: LOOKS(),
							onChange: ( value ) => setAttributes( { look: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( SelectControl, {
							label: __( 'Size', 'fluffless-suite' ),
							value: size,
							options: SIZES(),
							onChange: ( value ) => setAttributes( { size: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						palette.length > 1 &&
							el( SelectControl, {
								label: __( 'Colour', 'fluffless-suite' ),
								help:
									'accent' === look
										? __( 'Colour of the band. The text colour is picked from the palette to stay readable on it.', 'fluffless-suite' )
										: __( 'Colour of the text and lines. Automatic keeps the text colour of the section. Check that it stays readable on the background.', 'fluffless-suite' ),
								value: colour,
								options: palette,
								onChange: ( value ) => setAttributes( { colour: value } ),
								__nextHasNoMarginBottom: true,
							} )
					)
				),
				el(
					'div',
					blockProps,
					el(
						'div',
						{ className: 'fl-bd-band' },
						el(
							'div',
							{ className: 'fl-bd-track' },
							el(
								'ul',
								{ className: 'fl-bd-list' },
								words.map( ( word, index ) =>
									el( 'li', { key: index }, word, el( 'span', { className: 'fl-bd-sep', 'aria-hidden': 'true' }, data.separators[ separator ] || '' ) )
								)
							)
						)
					)
				)
			);
		},
		save: () => null,
	} );
} )( window.wp );
