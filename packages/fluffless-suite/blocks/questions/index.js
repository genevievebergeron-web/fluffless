/**
 * Éditeur du bloc fluffless/questions. JavaScript natif, sans étape de build.
 * Un titre puis un groupe de blocs Détails du cœur : une question par bloc, la réponse dedans.
 * Sans JavaScript côté visiteur (details natif) ; le module SEO lit ces blocs pour le balisage
 * FAQPage. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const NONE = [];
	const DETAILS = 'core/details';
	const LIST_CLASS = 'fluffless-questions__list';
	const ANSWER = () => __( 'The answer, in two or three sentences. If it needs more, it deserves its own page.', 'fluffless-suite' );

	const question = () =>
		S.createBlock( DETAILS, { className: 'is-style-bordure-brute' }, [ S.createBlock( 'core/paragraph', { placeholder: ANSWER() } ) ] );

	const ITEM = [ DETAILS, { className: 'is-style-bordure-brute' }, [ [ 'core/paragraph', { placeholder: ANSWER() } ] ] ];
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'The questions we hear every week', 'fluffless-suite' ) } ],
		[ 'core/group', { className: LIST_CLASS, layout: { type: 'default' } }, [ ITEM, ITEM, ITEM, ITEM ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/questions', {
		edit( props ) {
			const { clientId } = props;
			const state = wp.data.useSelect(
				( s ) => {
					const be = s( 'core/block-editor' );
					const items = be.getBlocks( clientId ).find( S.isBlock( 'core/group' ) );
					const list = items ? be.getBlocks( items.clientId ).filter( S.isBlock( DETAILS ) ) : NONE;
					return { items, list, open: !! ( list[ 0 ] && list[ 0 ].attributes.showContent ) };
				},
				[ clientId ]
			);
			const add = () => {
				if ( state.items ) {
					wp.data.dispatch( 'core/block-editor' ).insertBlock( question(), undefined, state.items.clientId, true );
				}
			};
			const setOpen = ( on ) => {
				if ( state.list[ 0 ] ) {
					wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( state.list[ 0 ].clientId, { showContent: on } );
				}
			};

			return S.shell( 'questions', props, {
				keys: [],
				template: TEMPLATE,
				title: __( 'Frequently asked questions', 'fluffless-suite' ),
				controls: [
					S.el(
						'p',
						{ className: 'fluffless-questions__count' },
						wp.i18n.sprintf(
							/* translators: %d: number of questions in the block. */
							wp.i18n._n( '%d question', '%d questions', state.list.length, 'fluffless-suite' ),
							state.list.length
						)
					),
					S.el( wp.components.Button, { variant: 'secondary', onClick: add, __next40pxDefaultSize: true }, __( 'Add a question', 'fluffless-suite' ) ),
					S.toggle(
						__( 'First answer open', 'fluffless-suite' ),
						state.open,
						setOpen,
						__( 'Visitors see the first answer without a click.', 'fluffless-suite' )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
