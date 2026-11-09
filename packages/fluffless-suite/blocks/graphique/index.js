/**
 * Éditeur du bloc fluffless/graphique. JavaScript natif, sans étape de build.
 * Le site reçoit le rendu serveur (Blocks\Graphique::render()). L'aperçu reprend les mêmes classes
 * et le même calcul des parts (plus grands restes, total de 100), avec les couleurs, les tailles et
 * les bornes passées par PHP (window.flufflessGraphique) : une seule source de vérité.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __, sprintf } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl, ToggleControl, Button, Flex, FlexBlock, FlexItem } = wp.components;

	const data = Object.assign(
		{ colours: [], sizes: {}, minRows: 2, maxRows: 8, maxLabel: 40, maxTitle: 120, maxUnit: 12 },
		window.flufflessGraphique || {}
	);

	const TYPES = () => [
		{ value: 'donut', label: __( 'Donut', 'fluffless-suite' ) },
		{ value: 'pie', label: __( 'Pie', 'fluffless-suite' ) },
		{ value: 'bars', label: __( 'Bars', 'fluffless-suite' ) },
	];
	const SIZES = () => [
		{ value: 'small', label: __( 'Small', 'fluffless-suite' ) },
		{ value: 'medium', label: __( 'Medium', 'fluffless-suite' ) },
		{ value: 'large', label: __( 'Large', 'fluffless-suite' ) },
	];

	// Rangées valides, comme le rendu : une étiquette et une valeur finie, positive ou nulle.
	const validRows = ( rows ) =>
		( Array.isArray( rows ) ? rows : [] )
			.map( ( row ) => ( { label: String( ( row && row.label ) || '' ).trim(), value: Number( row && row.value ) } ) )
			.filter( ( row ) => row.label !== '' && Number.isFinite( row.value ) && row.value >= 0 )
			.slice( 0, data.maxRows );

	// Parts entières qui totalisent 100, même méthode que Graphique::shares().
	const shares = ( values ) => {
		const total = values.reduce( ( sum, value ) => sum + value, 0 );
		if ( total <= 0 ) {
			return [];
		}
		const exact = values.map( ( value ) => ( value / total ) * 100 );
		const result = exact.map( Math.floor );
		let missing = 100 - result.reduce( ( sum, value ) => sum + value, 0 );
		exact
			.map( ( value, index ) => ( { index, rest: value - result[ index ] } ) )
			.sort( ( a, b ) => b.rest - a.rest || a.index - b.index )
			.forEach( ( { index } ) => {
				if ( missing > 0 ) {
					result[ index ]++;
					missing--;
				}
			} );
		return result;
	};

	/* translators: %s: share of the total, a whole number from 0 to 100. */
	const percent = ( share ) => sprintf( __( '%s%%', 'fluffless-suite' ), share );

	registerBlockType( 'fluffless/graphique', {
		edit( { attributes, setAttributes } ) {
			const { type, title, rows, unit, showValues, size } = attributes;
			const list = Array.isArray( rows ) ? rows : [];
			const setRows = ( next ) => setAttributes( { rows: next.slice( 0, data.maxRows ) } );
			const setRow = ( index, patch ) => setRows( list.map( ( row, i ) => ( i === index ? Object.assign( {}, row, patch ) : row ) ) );

			const valid = validRows( list );
			const parts = shares( valid.map( ( row ) => row.value ) );
			const drawable = valid.length >= data.minRows && parts.length > 0;
			const bars = 'bars' === type;
			const max = valid.reduce( ( top, row ) => Math.max( top, row.value ), 0 );

			const style = {};
			valid.forEach( ( row, index ) => {
				style[ '--g' + ( index + 1 ) ] = data.colours[ index ] || 'currentColor';
			} );
			if ( data.sizes[ size ] ) {
				style[ '--fl-g-s' ] = data.sizes[ size ];
			}
			const blockProps = useBlockProps( { className: 'fl-graphique is-type-' + type, style } );

			let sum = 0;
			const stops = [];
			parts.forEach( ( share, index ) => {
				if ( share > 0 ) {
					sum += share;
					stops.push( 'var(--g' + ( index + 1 ) + ') 0 ' + sum + '%' );
				}
			} );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Chart', 'fluffless-suite' ) },
						el( SelectControl, {
							label: __( 'Type', 'fluffless-suite' ),
							help: __( 'The drawing is decorative. The table under or beside it carries the figures for everyone, screen readers included.', 'fluffless-suite' ),
							value: type,
							options: TYPES(),
							onChange: ( value ) => setAttributes( { type: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( TextControl, {
							label: __( 'Title', 'fluffless-suite' ),
							value: title,
							maxLength: data.maxTitle,
							onChange: ( value ) => setAttributes( { title: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( TextControl, {
							label: __( 'Unit', 'fluffless-suite' ),
							help: __( 'Shown after each value, for example kg or $. Leave empty for none.', 'fluffless-suite' ),
							value: unit,
							maxLength: data.maxUnit,
							onChange: ( value ) => setAttributes( { unit: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( ToggleControl, {
							label: __( 'Show the values', 'fluffless-suite' ),
							help: __( 'Turn off to show the shares in percent only.', 'fluffless-suite' ),
							checked: showValues,
							onChange: ( value ) => setAttributes( { showValues: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						! bars &&
							el( SelectControl, {
								label: __( 'Size', 'fluffless-suite' ),
								value: size,
								options: SIZES(),
								onChange: ( value ) => setAttributes( { size: value } ),
								__nextHasNoMarginBottom: true,
							} )
					),
					el(
						PanelBody,
						{ title: __( 'Rows', 'fluffless-suite' ) },
						el(
							'p',
							{ className: 'components-base-control__help' },
							sprintf(
								/* translators: 1: minimum number of rows, 2: maximum number of rows. */
								__( '%1$d to %2$d rows, each with a label and a value of zero or more. The colours follow the theme palette in a fixed order.', 'fluffless-suite' ),
								data.minRows,
								data.maxRows
							)
						),
						list.map( ( row, index ) =>
							el(
								Flex,
								{ key: index, align: 'flex-end' },
								el(
									FlexBlock,
									null,
									el( TextControl, {
										/* translators: %d: position of the row in the list. */
										label: sprintf( __( 'Label %d', 'fluffless-suite' ), index + 1 ),
										value: ( row && row.label ) || '',
										maxLength: data.maxLabel,
										onChange: ( value ) => setRow( index, { label: value } ),
										__nextHasNoMarginBottom: true,
									} )
								),
								el(
									FlexItem,
									{ style: { width: '5.5em' } },
									el( TextControl, {
										/* translators: %d: position of the row in the list. */
										label: sprintf( __( 'Value %d', 'fluffless-suite' ), index + 1 ),
										type: 'number',
										min: 0,
										step: 'any',
										value: row && Number.isFinite( row.value ) ? row.value : '',
										onChange: ( value ) => setRow( index, { value: value === '' ? 0 : Math.max( 0, Number( value ) || 0 ) } ),
										__nextHasNoMarginBottom: true,
									} )
								),
								el(
									FlexItem,
									null,
									el( Button, {
										icon: 'trash',
										/* translators: %d: position of the row in the list. */
										label: sprintf( __( 'Remove row %d', 'fluffless-suite' ), index + 1 ),
										onClick: () => setRows( list.filter( ( r, i ) => i !== index ) ),
									} )
								)
							)
						),
						el(
							Button,
							{
								variant: 'secondary',
								disabled: list.length >= data.maxRows,
								onClick: () => setRows( list.concat( { label: '', value: 0 } ) ),
								style: { marginTop: '12px' },
							},
							__( 'Add a row', 'fluffless-suite' )
						)
					)
				),
				el(
					'figure',
					blockProps,
					title && el( 'figcaption', { className: 'fl-gr-title' }, title ),
					! drawable &&
						el(
							'p',
							null,
							sprintf(
								/* translators: %d: minimum number of rows. */
								__( 'Chart: add at least %d rows with a label, and one value above zero, in the block settings. Nothing shows on the site until then.', 'fluffless-suite' ),
								data.minRows
							)
						),
					drawable &&
						el(
							'div',
							{ className: 'fl-gr-body' },
							! bars &&
								el( 'div', {
									className: 'fl-gr-draw',
									'aria-hidden': 'true',
									style: { background: 'conic-gradient(' + stops.join( ',' ).replace( / \d+%$/, '' ) + ')' },
								} ),
							el(
								'table',
								{ className: 'fl-gr-table' },
								el(
									'thead',
									null,
									el(
										'tr',
										null,
										el( 'th', { scope: 'col' }, __( 'Label', 'fluffless-suite' ) ),
										bars && el( 'td', null ),
										showValues && el( 'th', { scope: 'col' }, __( 'Value', 'fluffless-suite' ) ),
										el( 'th', { scope: 'col' }, __( 'Share', 'fluffless-suite' ) )
									)
								),
								el(
									'tbody',
									null,
									valid.map( ( row, index ) =>
										el(
											'tr',
											{ key: index },
											el(
												'th',
												{ scope: 'row' },
												! bars && el( 'span', { className: 'fl-gr-chip', 'aria-hidden': 'true', style: { color: 'var(--g' + ( index + 1 ) + ')' } } ),
												row.label
											),
											bars &&
												el(
													'td',
													{ className: 'fl-gr-bar', 'aria-hidden': 'true' },
													el( 'span', { style: { color: 'var(--g' + ( index + 1 ) + ')', '--v': max > 0 ? Math.round( ( row.value / max ) * 1000 ) / 10 : 0 } } )
												),
											showValues && el( 'td', null, row.value.toLocaleString() + ( unit ? '\u00a0' + unit : '' ) ),
											el( 'td', null, percent( parts[ index ] ) )
										)
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
