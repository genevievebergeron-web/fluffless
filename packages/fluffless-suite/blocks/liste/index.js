/**
 * Éditeur du bloc fluffless/liste. JavaScript natif, sans étape de build.
 * « Numéros » bascule la liste native en liste ordonnée (ol) : la numérotation reste dans le HTML,
 * pas seulement dans le style. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const ACTION = () => __( 'Name the next step', 'fluffless-suite' );
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'Name the promise this list keeps', 'fluffless-suite' ) } ],
		[ 'core/paragraph', { placeholder: __( 'One sentence of context: who this list is for and when it helps.', 'fluffless-suite' ) } ],
		[
			'core/list',
			{},
			[
				[ 'core/list-item', { placeholder: __( 'One benefit per line, starting with a verb', 'fluffless-suite' ) } ],
				[ 'core/list-item', {} ],
				[ 'core/list-item', {} ],
			],
		],
	];

	wp.blocks.registerBlockType( 'fluffless/liste', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const kids = S.useChildren( clientId );
			const list = kids.find( S.isBlock( 'core/list' ) );
			const hasButton = kids.some( S.isBlock( 'core/buttons' ) );
			const setMarker = ( value ) => {
				setAttributes( { marker: value } );
				if ( list ) {
					wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( list.clientId, { ordered: 'number' === value } );
				}
			};

			return S.shell( 'liste', props, {
				keys: [ 'marker', 'columns' ],
				template: TEMPLATE,
				title: __( 'List', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Markers', 'fluffless-suite' ),
						attributes.marker,
						[
							{ value: 'bullet', label: __( 'Bullets', 'fluffless-suite' ) },
							{ value: 'check', label: __( 'Check marks', 'fluffless-suite' ) },
							{ value: 'number', label: __( 'Numbers', 'fluffless-suite' ) },
						],
						setMarker
					),
					S.choice(
						__( 'Columns', 'fluffless-suite' ),
						String( attributes.columns ),
						[
							{ value: '1', label: '1' },
							{ value: '2', label: '2' },
						],
						( value ) => setAttributes( { columns: parseInt( value, 10 ) } ),
						__( 'Two columns from tablet width up. On phones, the list stays on one column.', 'fluffless-suite' )
					),
					S.toggle( __( 'Button', 'fluffless-suite' ), hasButton, ( value ) =>
						S.setChild( clientId, S.isBlock( 'core/buttons' ), value, () => S.buttons( 1, ACTION() ) )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
