/**
 * Éditeur du bloc fluffless/tableau. JavaScript natif, sans étape de build.
 * Un titre puis un bloc Tableau du cœur : les cellules, la légende et les colonnes restent celles
 * de l'éditeur natif. Les sept réglages sont des classes posées sur l'enveloppe ; le serveur ajoute
 * les libellés de colonne (data-label) et repère les cellules numériques au rendu.
 * Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const cell = ( content, tag ) => ( tag ? { content, tag } : { content } );
	const head = [ { cells: [ cell( __( 'Feature', 'fluffless-suite' ), 'th' ), cell( __( 'Option A', 'fluffless-suite' ), 'th' ), cell( __( 'Option B', 'fluffless-suite' ), 'th' ) ] } ];
	const body = [ 1, 2, 3 ].map( () => ( { cells: [ cell( '' ), cell( '' ), cell( '' ) ] } ) );
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'Side by side, in numbers', 'fluffless-suite' ) } ],
		[ 'core/table', { hasFixedLayout: true, head, body } ],
	];
	const opt = ( value, label ) => ( { value, label } );

	wp.blocks.registerBlockType( 'fluffless/tableau', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			const set = ( key ) => ( value ) => setAttributes( { [ key ]: value } );
			return S.shell( 'tableau', props, {
				keys: [ 'density', 'lines', 'stripes', 'header', 'heads', 'numbers', 'mobile' ],
				template: TEMPLATE,
				title: __( 'Data table', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Density', 'fluffless-suite' ),
						attributes.density,
						[ opt( 'comfortable', __( 'Comfortable', 'fluffless-suite' ) ), opt( 'compact', __( 'Compact', 'fluffless-suite' ) ) ],
						set( 'density' )
					),
					S.choice(
						__( 'Lines', 'fluffless-suite' ),
						attributes.lines,
						[ opt( 'rows', __( 'Rows', 'fluffless-suite' ) ), opt( 'grid', __( 'Grid', 'fluffless-suite' ) ), opt( 'none', __( 'None', 'fluffless-suite' ) ) ],
						set( 'lines' )
					),
					S.choice(
						__( 'Stripes', 'fluffless-suite' ),
						attributes.stripes,
						[ opt( 'plain', __( 'Plain', 'fluffless-suite' ) ), opt( 'striped', __( 'Striped', 'fluffless-suite' ) ) ],
						set( 'stripes' ),
						__( 'Every other row on the surface colour.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Header row', 'fluffless-suite' ),
						attributes.header,
						[ opt( 'plain', __( 'Plain', 'fluffless-suite' ) ), opt( 'accent', __( 'Accent', 'fluffless-suite' ) ), opt( 'sticky', __( 'Sticky', 'fluffless-suite' ) ) ],
						set( 'header' ),
						__( 'Sticky keeps the header row in view while a long table scrolls. Turn on the header section of the Table block.', 'fluffless-suite' )
					),
					S.choice(
						__( 'First column', 'fluffless-suite' ),
						attributes.heads,
						[ opt( 'plain', __( 'Plain', 'fluffless-suite' ) ), opt( 'bold', __( 'Bold', 'fluffless-suite' ) ) ],
						set( 'heads' )
					),
					S.choice(
						__( 'Numbers', 'fluffless-suite' ),
						attributes.numbers,
						[ opt( 'right', __( 'Aligned right', 'fluffless-suite' ) ), opt( 'left', __( 'As typed', 'fluffless-suite' ) ) ],
						set( 'numbers' ),
						__( 'Cells that hold only a number, a price or a percentage line up on the right, with digits of equal width.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Small screens', 'fluffless-suite' ),
						attributes.mobile,
						[ opt( 'scroll', __( 'Scroll sideways', 'fluffless-suite' ) ), opt( 'stack', __( 'Stack rows', 'fluffless-suite' ) ) ],
						set( 'mobile' ),
						__( 'Stacked, each row becomes a short list with the column name before each value.', 'fluffless-suite' )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
