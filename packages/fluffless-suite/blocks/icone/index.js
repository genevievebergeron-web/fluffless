/**
 * Éditeur du bloc fluffless/icone. JavaScript natif, sans étape de build.
 * La liste d'icônes arrive par window.flufflessIcones (script en ligne ajouté
 * seulement dans l'éditeur, voir Icone::add_editor_data()).
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { useState, useMemo } = wp.element;
	const { __ } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SearchControl, SelectControl, TextControl, Button } = wp.components;

	const data = window.flufflessIcones || { categories: [], icons: {} };

	/** Normalise pour une recherche insensible aux accents et à la casse. */
	const fold = ( text ) => text.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ).toLowerCase();

	const catalog = data.categories.flatMap( ( category ) =>
		category.names.map( ( name ) => ( { name, haystack: fold( name + ' ' + category.label ) } ) )
	);

	function Svg( { name, title } ) {
		const inner = data.icons[ name ];
		if ( ! inner ) {
			return null;
		}
		return el( 'svg', {
			xmlns: 'http://www.w3.org/2000/svg',
			width: '1em',
			height: '1em',
			viewBox: '0 0 24 24',
			fill: 'none',
			stroke: 'currentColor',
			strokeWidth: 2,
			strokeLinecap: 'round',
			strokeLinejoin: 'round',
			'aria-hidden': title ? undefined : 'true',
			role: title ? 'img' : undefined,
			'aria-label': title || undefined,
			focusable: 'false',
			// Contenu issu du fichier généré sous liste blanche (tools/build-icons.mjs).
			dangerouslySetInnerHTML: { __html: inner },
		} );
	}

	function IconGrid( { value, onChange } ) {
		const [ query, setQuery ] = useState( '' );
		const matches = useMemo( () => {
			const needle = fold( query.trim() );
			return needle ? catalog.filter( ( item ) => item.haystack.includes( needle ) ) : catalog;
		}, [ query ] );

		return el(
			'div',
			null,
			el( SearchControl, {
				label: __( 'Search icons', 'fluffless-suite' ),
				value: query,
				onChange: setQuery,
				__nextHasNoMarginBottom: true,
			} ),
			el(
				'div',
				{
					role: 'listbox',
					'aria-label': __( 'Icons', 'fluffless-suite' ),
					style: {
						display: 'grid',
						gridTemplateColumns: 'repeat(auto-fill, minmax(44px, 1fr))',
						gap: '4px',
						maxHeight: '260px',
						overflowY: 'auto',
						marginTop: '8px',
						fontSize: '22px',
					},
				},
				matches.map( ( item ) =>
					el( Button, {
						key: item.name,
						label: item.name,
						showTooltip: true,
						isPressed: item.name === value,
						'aria-selected': item.name === value,
						role: 'option',
						onClick: () => onChange( item.name ),
						style: { width: '44px', height: '44px', justifyContent: 'center' },
						icon: el( Svg, { name: item.name } ),
					} )
				)
			),
			matches.length === 0 &&
				el( 'p', null, __( 'No icons match.', 'fluffless-suite' ) )
		);
	}

	registerBlockType( 'fluffless/icone', {
		edit( { attributes, setAttributes } ) {
			const { icon, size, label } = attributes;
			const blockProps = useBlockProps( { className: 'is-size-' + size } );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Icon', 'fluffless-suite' ) },
						el( IconGrid, { value: icon, onChange: ( next ) => setAttributes( { icon: next } ) } )
					),
					el(
						PanelBody,
						{ title: __( 'Settings', 'fluffless-suite' ) },
						el( SelectControl, {
							label: __( 'Size', 'fluffless-suite' ),
							value: size,
							options: [
								{ value: 'small', label: __( 'Small', 'fluffless-suite' ) },
								{ value: 'medium', label: __( 'Medium', 'fluffless-suite' ) },
								{ value: 'large', label: __( 'Large', 'fluffless-suite' ) },
								{ value: 'xl', label: __( 'Extra large', 'fluffless-suite' ) },
							],
							onChange: ( next ) => setAttributes( { size: next } ),
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
						} ),
						el( TextControl, {
							label: __( 'Alternative text (leave empty if decorative)', 'fluffless-suite' ),
							value: label,
							onChange: ( next ) => setAttributes( { label: next } ),
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el(
					'div',
					blockProps,
					data.icons[ icon ]
						? el( Svg, { name: icon, title: label } )
						: el( 'span', null, __( 'Icon not found', 'fluffless-suite' ) )
				)
			);
		},
		save: () => null,
	} );
} )( window.wp );
