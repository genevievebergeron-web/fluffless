/**
 * Panneau « Page style » de la barre latérale : une variation du thème pour cette page seulement,
 * toujours aux couleurs du site. L'éditeur calcule ses styles lui-même, sans la variation : le panneau
 * demande donc la feuille de styles de la variation (route fluffless/v1/page-style) et la pose dans
 * le canevas, pour un aperçu en direct dès qu'on change de style.
 * JavaScript natif, sans étape de build.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	const config = window.flufflessPageStyle || { choices: [] };
	if ( ! Panel || ! wp.plugins || ! config.choices.length ) {
		return;
	}
	const META = '_fluffless_page_style';
	const ID = 'fluffless-page-style-preview';
	const cache = {};

	// Documents où poser l'aperçu : le canevas en iframe s'il existe, sinon la page de l'éditeur.
	const documents = () => {
		const frame = document.querySelector( 'iframe[name="editor-canvas"]' );
		return frame && frame.contentDocument ? [ frame.contentDocument ] : [ document ];
	};
	const put = ( css ) => documents().forEach( ( doc ) => {
		let tag = doc.getElementById( ID );
		if ( ! tag ) {
			tag = doc.createElement( 'style' );
			tag.id = ID;
			doc.body.appendChild( tag );
			// L'éditeur pose ses styles globaux dans le body, après coup : l'aperçu reste le dernier pour l'emporter.
			new window.MutationObserver( () => {
				if ( doc.body.lastElementChild !== tag ) {
					doc.body.appendChild( tag );
				}
			} ).observe( doc.body, { childList: true } );
		}
		tag.textContent = css;
	} );
	const preview = ( slug ) => {
		if ( ! slug ) {
			put( '' );
			return;
		}
		if ( undefined !== cache[ slug ] ) {
			put( cache[ slug ] );
			return;
		}
		wp.apiFetch( { path: '/fluffless/v1/page-style/' + slug } ).then( ( res ) => {
			cache[ slug ] = res.css || '';
			put( cache[ slug ] );
		} ).catch( () => {} );
	};

	const Style = () => {
		const meta = wp.data.useSelect( ( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {}, [] );
		const slug = meta[ META ] || '';
		// Réappliqué quand le style change, et une seconde plus tard pour un canevas qui charge encore.
		wp.element.useEffect( () => {
			preview( slug );
			const later = window.setTimeout( () => preview( slug ), 1000 );
			return () => window.clearTimeout( later );
		}, [ slug ] );
		const options = [ { value: '', label: __( 'Same as the site', 'fluffless-suite' ) } ].concat( config.choices );
		return el(
			Panel,
			{ name: 'fluffless-page-style', title: __( 'Page style', 'fluffless-suite' ) },
			el( wp.components.SelectControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __( 'Style of this page', 'fluffless-suite' ),
				value: slug,
				options,
				onChange: ( value ) => wp.data.dispatch( 'core/editor' ).editPost( { meta: { [ META ]: value } } ),
				help: __( 'Shapes, fonts and spacing change; your site colours stay. The page shows it right away; save to keep it.', 'fluffless-suite' ),
			} )
		);
	};

	wp.plugins.registerPlugin( 'fluffless-page-style', { render: Style } );
} )( window.wp );
