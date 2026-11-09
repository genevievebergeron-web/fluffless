/**
 * Éditeur du bloc fluffless/fiche. JavaScript natif, sans étape de build.
 * Aperçu rendu par le serveur pour le contenu courant de la boucle ; choix de la fiche dans la
 * barre latérale (window.flufflessFiches, posé par Loops::editor_assets()). Rendu du site : Loops::render_block().
 */
( function ( wp ) {
	const { __ } = wp.i18n;
	const el = wp.element.createElement;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl } = wp.components;
	const data = window.flufflessFiches || { cards: [] };

	wp.blocks.registerBlockType( 'fluffless/fiche', {
		edit( props ) {
			const { attributes, setAttributes, context } = props;
			const options = [ { value: '', label: __( 'As set for this content type', 'fluffless-suite' ) } ].concat( data.cards );
			const args = context && context.postId ? { post_id: context.postId } : {};
			return el(
				'div',
				useBlockProps( { className: 'fluffless-fiche-editor' } ),
				el( InspectorControls, null, el( PanelBody, { title: __( 'Loop card', 'fluffless-suite' ) },
					el( SelectControl, { label: __( 'Card', 'fluffless-suite' ), value: attributes.card, options, onChange: ( card ) => setAttributes( { card } ), __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
					el( 'p', { className: 'components-base-control__help' }, __( 'The card for each content type is chosen under Appearance, Loop cards. Pick one here only to differ from it in this loop.', 'fluffless-suite' ) )
				) ),
				el( wp.serverSideRender, { block: 'fluffless/fiche', attributes, urlQueryArgs: args, EmptyResponsePlaceholder: () => el( 'p', { className: 'components-base-control__help' }, __( 'Loop card: shown for each post of the loop on the site.', 'fluffless-suite' ) ) } )
			);
		},
		save() {
			return null;
		},
	} );
} )( window.wp );
