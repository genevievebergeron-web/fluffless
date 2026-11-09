/**
 * Éditeur du bloc fluffless/colonnes. JavaScript natif, sans étape de build.
 * Les colonnes sont celles du cœur (core/columns) : leur mise en page et leur empilement sur
 * téléphone ne demandent aucun CSS de plus. Nombre, visuel et bouton se lisent dans les enfants.
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const MEDIA = { 'fluffless/icone': 'icon', 'core/image': 'image' };
	const isMedia = ( block ) => Boolean( MEDIA[ block.name ] );
	const ACTION = () => __( 'Name the next step', 'fluffless-suite' );
	const TITLE = () => __( 'Name this point in a few words', 'fluffless-suite' );
	const TEXT = () => __( 'Explain it in one or two sentences, with a concrete example.', 'fluffless-suite' );

	const makeMedia = ( media ) =>
		'icon' === media ? S.createBlock( 'fluffless/icone', {} ) : S.createBlock( 'core/image', { sizeSlug: 'medium' } );

	const column = ( media, button ) =>
		S.createBlock(
			'core/column',
			{},
			[]
				.concat( 'none' === media ? [] : [ makeMedia( media ) ] )
				.concat( [ S.createBlock( 'core/heading', { level: 3, placeholder: TITLE() } ), S.createBlock( 'core/paragraph', { placeholder: TEXT() } ) ] )
				.concat( button ? [ S.buttons( 1, ACTION() ) ] : [] )
		);

	const COLUMN = [ 'core/column', {}, [ [ 'core/heading', { level: 3, placeholder: TITLE() } ], [ 'core/paragraph', { placeholder: TEXT() } ] ] ];
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'Name what these columns have in common', 'fluffless-suite' ) } ],
		[ 'core/paragraph', { placeholder: __( 'One sentence that says why these points matter to your reader.', 'fluffless-suite' ) } ],
		[ 'core/columns', {}, [ COLUMN, COLUMN, COLUMN ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/colonnes', {
		edit( props ) {
			const { clientId } = props;
			const group = S.useChildren( clientId ).find( S.isBlock( 'core/columns' ) );
			const cols = S.useChildren( group && group.clientId );
			const first = S.useChildren( cols[ 0 ] && cols[ 0 ].clientId );
			const media = ( first[ 0 ] && MEDIA[ first[ 0 ].name ] ) || 'none';
			const hasButton = first.some( S.isBlock( 'core/buttons' ) );

			const setMedia = ( value ) =>
				cols.forEach( ( col ) => {
					S.setChild( col.clientId, isMedia, false );
					if ( 'none' !== value ) {
						S.setChild( col.clientId, isMedia, true, () => makeMedia( value ), 0 );
					}
				} );

			return S.shell( 'colonnes', props, {
				template: TEMPLATE,
				title: __( 'Columns', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of columns', 'fluffless-suite' ),
						String( cols.length ),
						[
							{ value: '2', label: '2' },
							{ value: '3', label: '3' },
							{ value: '4', label: '4' },
						],
						( value ) => group && S.setCount( group.clientId, parseInt( value, 10 ), () => column( media, hasButton ) ),
						__( 'Removing a column also removes its content.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Visual in each column', 'fluffless-suite' ),
						media,
						[
							{ value: 'none', label: __( 'None', 'fluffless-suite' ) },
							{ value: 'icon', label: __( 'Icon', 'fluffless-suite' ) },
							{ value: 'image', label: __( 'Image', 'fluffless-suite' ) },
						],
						setMedia
					),
					S.toggle( __( 'Button in each column', 'fluffless-suite' ), hasButton, ( value ) =>
						cols.forEach( ( col ) => S.setChild( col.clientId, S.isBlock( 'core/buttons' ), value, () => S.buttons( 1, ACTION() ) ) )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
