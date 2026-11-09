/**
 * Module SEO de Fluffless Suite : champ « Description pour les moteurs » dans le panneau du document.
 * JavaScript natif, sans étape de build. Chargé dans l'éditeur seulement.
 * La valeur est la métadonnée _fluffless_description ; vide, l'extrait sert de repli.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __, sprintf } = wp.i18n;
	const { useSelect, useDispatch } = wp.data;
	const { TextareaControl } = wp.components;
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	const KEY = '_fluffless_description';
	const LIMIT = 160;

	function DescriptionPanel() {
		const meta = useSelect( ( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ), [] );
		const { editPost } = useDispatch( 'core/editor' );
		// Types de contenu sans champs personnalisés (gabarits, blocs réutilisables) : rien à afficher.
		if ( ! meta || ! ( KEY in meta ) ) {
			return null;
		}
		const value = meta[ KEY ] || '';
		return el(
			Panel,
			{ name: 'fluffless-seo', title: __( 'Search description', 'fluffless-suite' ) },
			el( TextareaControl, {
				label: __( 'Description for search engines', 'fluffless-suite' ),
				help: sprintf(
					/* translators: 1: characters typed, 2: recommended maximum */
					__( '%1$d of %2$d characters. Empty: the excerpt is used.', 'fluffless-suite' ),
					value.length,
					LIMIT
				),
				value,
				rows: 3,
				onChange: ( next ) => editPost( { meta: { [ KEY ]: next } } ),
			} )
		);
	}

	if ( Panel ) {
		wp.plugins.registerPlugin( 'fluffless-seo', { render: DescriptionPanel } );
	}
} )( window.wp );
