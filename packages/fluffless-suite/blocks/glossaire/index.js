/**
 * Éditeur du bloc fluffless/glossaire. JavaScript natif, sans étape de build.
 * Une liste de blocs Terme (fluffless/terme), rien d'autre : sur le site, une liste de définitions
 * native (dl), en une ou deux colonnes en CSS. Le titre va avant le bloc. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const ITEM = [ 'fluffless/terme' ];
	const term = () => S.createBlock( 'fluffless/terme' );
	const TEMPLATE = [ ITEM, ITEM, ITEM ];

	wp.blocks.registerBlockType( 'fluffless/glossaire', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const list = S.useChildren( clientId );
			const add = () => wp.data.dispatch( 'core/block-editor' ).insertBlock( term(), undefined, clientId, true );

			return S.shell( 'glossaire', props, {
				keys: [ 'columns' ],
				template: TEMPLATE,
				title: __( 'Glossary', 'fluffless-suite' ),
				controls: [
					S.el(
						'p',
						{ className: 'fluffless-glossaire__count' },
						wp.i18n.sprintf(
							/* translators: %d: number of terms in the block. */
							wp.i18n._n( '%d term', '%d terms', list.length, 'fluffless-suite' ),
							list.length
						)
					),
					S.el( wp.components.Button, { variant: 'secondary', onClick: add, __next40pxDefaultSize: true }, __( 'Add a term', 'fluffless-suite' ) ),
					S.choice(
						__( 'Columns', 'fluffless-suite' ),
						String( attributes.columns ),
						[
							{ value: '1', label: '1' },
							{ value: '2', label: '2' },
						],
						( raw ) => setAttributes( { columns: parseInt( raw, 10 ) } ),
						__( 'Two columns on wide screens, read top to bottom; phones always show one.', 'fluffless-suite' )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
