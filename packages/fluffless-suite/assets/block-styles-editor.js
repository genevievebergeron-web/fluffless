/**
 * Panneau des styles de bloc à options de la Suite (src/Presentation/BlockStyles.php) : forme
 * d'accent, index mono, dégradé dans le texte. JavaScript natif, sans étape de build ; chargé dans
 * l'éditeur seulement, jamais sur le site. Le panneau écrit des classes dans className : le site
 * ne reçoit que la feuille du style. Styles, blocs, têtes de classe et valeurs viennent de PHP
 * (window.flufflessBlockStyles.panels) : une seule source. Ici, seulement les libellés.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { addFilter } = wp.hooks;
	const { createHigherOrderComponent } = wp.compose;
	const { InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl } = wp.components;

	const panels = ( window.flufflessBlockStyles || {} ).panels || [];

	// Par style : titre du panneau, puis par groupe [ titre du réglage, libellé de chaque valeur ].
	// Une valeur sans libellé (la transparence) s'affiche en %.
	const LABELS = () => ( {
		'forme-accent': {
			title: __( 'Accent shape', 'fluffless-suite' ),
			shape: [
				__( 'Shape', 'fluffless-suite' ),
				{
					disc: __( 'Disc', 'fluffless-suite' ),
					'half-moon': __( 'Half-moon', 'fluffless-suite' ),
					ring: __( 'Ring', 'fluffless-suite' ),
					drop: __( 'Drop', 'fluffless-suite' ),
					'dot-grid': __( 'Dot grid', 'fluffless-suite' ),
				},
			],
			place: [
				__( 'Placement', 'fluffless-suite' ),
				{
					'top-right': __( 'Top right corner', 'fluffless-suite' ),
					'bottom-left': __( 'Bottom left corner', 'fluffless-suite' ),
					title: __( 'Behind the title', 'fluffless-suite' ),
					'right-edge': __( 'Right edge, full height', 'fluffless-suite' ),
				},
			],
			size: [
				__( 'Size', 'fluffless-suite' ),
				{
					small: __( 'Small', 'fluffless-suite' ),
					medium: __( 'Medium', 'fluffless-suite' ),
					large: __( 'Large', 'fluffless-suite' ),
				},
			],
			alpha: [ __( 'Transparency', 'fluffless-suite' ), {} ],
		},
		'index-mono': {
			title: __( 'Mono index', 'fluffless-suite' ),
			format: [
				__( 'Format', 'fluffless-suite' ),
				{
					brackets: __( 'Number in brackets: [01]', 'fluffless-suite' ),
					slash: __( 'Number and slash: 01 /', 'fluffless-suite' ),
					dot: __( 'Solid dot', 'fluffless-suite' ),
				},
			],
		},
		'degrade-texte': {
			title: __( 'Gradient text', 'fluffless-suite' ),
			angle: [
				__( 'Direction', 'fluffless-suite' ),
				{
					horizontal: __( 'Horizontal', 'fluffless-suite' ),
					diagonal: __( 'Diagonal', 'fluffless-suite' ),
					vertical: __( 'Vertical', 'fluffless-suite' ),
				},
			],
		},
		'plan': {
			title: __( 'Blueprint', 'fluffless-suite' ),
			line: [
				__( 'Rule', 'fluffless-suite' ),
				{
					plain: __( 'Text color', 'fluffless-suite' ),
					neon: __( 'Neon: accent color with a glow', 'fluffless-suite' ),
				},
			],
		},
		'autocollant': {
			title: __( 'Sticker', 'fluffless-suite' ),
			tilt: [
				__( 'Tilt', 'fluffless-suite' ),
				{
					gauche: __( 'To the left', 'fluffless-suite' ),
					droite: __( 'To the right', 'fluffless-suite' ),
				},
			],
		},
		'pilule': {
			title: __( 'Pill image', 'fluffless-suite' ),
			format: [
				__( 'Format', 'fluffless-suite' ),
				{
					large: __( 'Landscape', 'fluffless-suite' ),
					haute: __( 'Portrait', 'fluffless-suite' ),
				},
			],
		},
		'coins-decales': {
			title: __( 'Offset corners', 'fluffless-suite' ),
			diagonal: [
				__( 'Rounded corners', 'fluffless-suite' ),
				{
					normal: __( 'Top left and bottom right', 'fluffless-suite' ),
					miroir: __( 'Top right and bottom left', 'fluffless-suite' ),
				},
			],
		},
	} );

	const tokens = ( className ) => ( className || '' ).split( /\s+/ ).filter( Boolean );

	// Valeur d'un groupe lue dans className ; sans classe (ou classe inconnue) : le défaut, premier de la liste.
	const read = ( className, group ) => {
		const found = tokens( className ).find( ( t ) => t.startsWith( group.head ) );
		const value = found ? found.slice( group.head.length ) : '';
		return group.values.includes( value ) ? value : group.values[ 0 ];
	};

	// Remplace la classe du groupe ; le défaut n'écrit rien, pour garder le HTML court.
	const write = ( className, group, value ) => {
		const kept = tokens( className ).filter( ( t ) => ! t.startsWith( group.head ) );
		if ( value !== group.values[ 0 ] ) {
			kept.push( group.head + value );
		}
		return kept.join( ' ' );
	};

	const withPanel = createHigherOrderComponent(
		( BlockEdit ) => ( props ) => {
			const { name, attributes, setAttributes, isSelected } = props;
			const className = attributes.className || '';
			const classes = tokens( className );
			const panel = isSelected && panels.find( ( p ) => p.blocks.includes( name ) && classes.includes( 'is-style-' + p.style ) );
			if ( ! panel ) {
				return el( BlockEdit, props );
			}
			const labels = LABELS()[ panel.style ] || {};
			const controls = Object.keys( panel.groups ).map( ( key ) => {
				const group = panel.groups[ key ];
				const [ label, values ] = labels[ key ] || [ key, {} ];
				return el( SelectControl, {
					key,
					label,
					value: read( className, group ),
					options: group.values.map( ( value ) => ( { value, label: values[ value ] || value + ' %' } ) ),
					onChange: ( value ) => setAttributes( { className: write( className, group, value ) } ),
					__nextHasNoMarginBottom: true,
				} );
			} );
			return el(
				wp.element.Fragment,
				null,
				el( BlockEdit, props ),
				el( InspectorControls, null, el( PanelBody, { title: labels.title || panel.style }, controls ) )
			);
		},
		'withFlufflessStyleOptions'
	);

	addFilter( 'editor.BlockEdit', 'fluffless/style-options', withPanel );
} )( window.wp );
