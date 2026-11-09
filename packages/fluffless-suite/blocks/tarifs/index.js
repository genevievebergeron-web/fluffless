/**
 * Éditeur du bloc fluffless/tarifs. JavaScript natif, sans étape de build.
 * Un titre puis un groupe en grille native : un groupe par plan (nom, prix, pour qui, ce qui est
 * inclus, bouton). Le nombre de colonnes suit le nombre de plans ; le plan mis en avant est une
 * classe. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const NONE = [];
	const PLAN_CLASS = 'fluffless-tarifs__plan';
	const PRICE_CLASS = 'fluffless-tarifs__price';
	const NAME = () => __( 'Plan name', 'fluffless-suite' );
	const PRICE = () => __( '$49 / month', 'fluffless-suite' );
	const WHO = () => __( 'Who this plan is for, in one line.', 'fluffless-suite' );
	const CHOOSE = () => __( 'Choose this plan', 'fluffless-suite' );

	const list = () =>
		S.createBlock( 'core/list', {}, [ 1, 2, 3 ].map( () => S.createBlock( 'core/list-item', { placeholder: __( 'What is included', 'fluffless-suite' ) } ) ) );
	const plan = () =>
		S.createBlock( 'core/group', { className: PLAN_CLASS, metadata: { name: __( 'Plan', 'fluffless-suite' ) } }, [
			S.createBlock( 'core/heading', { level: 3, placeholder: NAME() } ),
			S.createBlock( 'core/paragraph', { className: PRICE_CLASS, placeholder: PRICE() } ),
			S.createBlock( 'core/paragraph', { placeholder: WHO() } ),
			list(),
			S.buttons( 1, CHOOSE() ),
		] );

	const LIST = [ 'core/list', {}, [ 1, 2, 3 ].map( () => [ 'core/list-item', { placeholder: __( 'What is included', 'fluffless-suite' ) } ] ) ];
	const ITEM = [
		'core/group',
		{ className: PLAN_CLASS, metadata: { name: __( 'Plan', 'fluffless-suite' ) } },
		[
			[ 'core/heading', { level: 3, placeholder: NAME() } ],
			[ 'core/paragraph', { className: PRICE_CLASS, placeholder: PRICE() } ],
			[ 'core/paragraph', { placeholder: WHO() } ],
			LIST,
			[ 'core/buttons', {}, [ [ 'core/button', { placeholder: CHOOSE() } ] ] ],
		],
	];
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'Clear prices, no surprises', 'fluffless-suite' ) } ],
		[ 'core/group', { layout: { type: 'grid', columnCount: 3, minimumColumnWidth: '14rem' } }, [ ITEM, ITEM, ITEM ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/tarifs', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const state = wp.data.useSelect(
				( s ) => {
					const be = s( 'core/block-editor' );
					const items = be.getBlocks( clientId ).find( S.isBlock( 'core/group' ) );
					return { items, list: items ? be.getBlocks( items.clientId ) : NONE };
				},
				[ clientId ]
			);

			const setCount = ( raw ) => {
				const n = parseInt( raw, 10 );
				if ( ! state.items ) {
					return;
				}
				S.setCount( state.items.clientId, n, plan );
				wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( state.items.clientId, {
					layout: Object.assign( {}, state.items.attributes.layout, { columnCount: n } ),
				} );
				if ( attributes.featured > n ) {
					setAttributes( { featured: 0 } );
				}
			};
			const ranks = [ { value: '0', label: __( 'None', 'fluffless-suite' ) } ].concat(
				state.list.map( ( _, i ) => ( { value: String( i + 1 ), label: String( i + 1 ) } ) )
			);

			return S.shell( 'tarifs', props, {
				keys: [ 'featured' ],
				template: TEMPLATE,
				title: __( 'Pricing table', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of plans', 'fluffless-suite' ),
						String( state.list.length ),
						[ '2', '3', '4' ].map( ( n ) => ( { value: n, label: n } ) ),
						setCount,
						__( 'Removing a plan also removes its content.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Highlighted plan', 'fluffless-suite' ),
						String( attributes.featured ),
						ranks,
						( next ) => setAttributes( { featured: parseInt( next, 10 ) } )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
