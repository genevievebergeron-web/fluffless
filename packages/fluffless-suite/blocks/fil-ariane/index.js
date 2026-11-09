/**
 * Éditeur du bloc fluffless/fil-ariane. JavaScript natif, sans étape de build.
 * Aperçu fixe (Accueil, page parente, cette page) : le vrai chemin dépend de la page affichée et
 * vient du serveur. Rendu du site : Sections::render_fil_ariane().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody } = wp.components;
	const SHOWN = [
		{ value: 'shown', label: __( 'Shown', 'fluffless-suite' ) },
		{ value: 'hidden', label: __( 'Hidden', 'fluffless-suite' ) },
	];

	wp.blocks.registerBlockType( 'fluffless/fil-ariane', {
		edit( { attributes, setAttributes } ) {
			const items = [
				attributes.home === 'shown' && __( 'Home', 'fluffless-suite' ),
				__( 'Parent page', 'fluffless-suite' ),
				attributes.current === 'shown' && __( 'This page', 'fluffless-suite' ),
			].filter( Boolean );
			return S.el(
				wp.element.Fragment,
				null,
				S.el(
					InspectorControls,
					null,
					S.el(
						PanelBody,
						{ title: __( 'Breadcrumb', 'fluffless-suite' ) },
						S.choice( __( 'Home link', 'fluffless-suite' ), attributes.home, SHOWN, ( home ) => setAttributes( { home } ) ),
						S.choice(
							__( 'Current page', 'fluffless-suite' ),
							attributes.current,
							SHOWN,
							( current ) => setAttributes( { current } ),
							__( 'The current page is text, not a link.', 'fluffless-suite' )
						)
					)
				),
				S.el(
					'nav',
					useBlockProps( {
						className: 'fluffless-fil-ariane is-home-' + attributes.home + ' is-current-' + attributes.current,
						'aria-label': __( 'Breadcrumb', 'fluffless-suite' ),
					} ),
					S.el(
						'ol',
						null,
						items.map( ( text, i ) => S.el( 'li', { key: i }, text ) )
					)
				)
			);
		},
		save: () => null,
	} );
} )( window.wp, window.flufflessSections );
