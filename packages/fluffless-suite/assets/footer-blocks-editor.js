/**
 * Éditeur des blocs du pied de page. JavaScript natif, sans étape de build.
 * L'aperçu vient du rendu serveur ; quand le bloc ne rend rien, l'éditeur dit pourquoi.
 * Le site, lui, ne reçoit que le HTML statique, sans script.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps } = wp.blockEditor;
	const ServerSideRender = wp.serverSideRender;

	const EMPTY = {
		'fluffless/badge-hebergement': __( 'Nothing shows on the site: this badge appears only when the Fluff check confirms your host with the Green Web Foundation, and when it is turned on in Fluffless → Modules → Footer.', 'fluffless-suite' ),
		'fluffless/reseaux': __( 'No network to show. Add your addresses in Fluffless → Modules → Footer.', 'fluffless-suite' ),
		'fluffless/certifications': __( 'No certification to show. Add image IDs in Fluffless → Modules → Footer.', 'fluffless-suite' ),
		'fluffless/lien-infolettre': __( 'No newsletter address. Add it in Fluffless → Modules → Footer.', 'fluffless-suite' ),
		'fluffless/legal': __( 'The Footer module is off: the legal bar shows nothing.', 'fluffless-suite' ),
	};

	Object.keys( EMPTY ).forEach( ( name ) => {
		registerBlockType( name, {
			edit( { attributes } ) {
				return el(
					'div',
					useBlockProps(),
					el( ServerSideRender, {
						block: name,
						attributes,
						EmptyResponsePlaceholder: () => el( 'p', null, EMPTY[ name ] ),
					} )
				);
			},
			save: () => null,
		} );
	} );
} )( window.wp );
