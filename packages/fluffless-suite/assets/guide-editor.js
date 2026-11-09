/**
 * Guide de Fluffless Suite : panneau « Where to find what » ouvert d'office dans la barre latérale
 * du document. Chaque élément agit au lieu d'expliquer : il ouvre les blocs, les sections, le choix
 * du modèle ou les réglages du bloc. JavaScript natif, sans étape de build.
 * Le lien ?fluffless_open=blocks|patterns (widget du tableau de bord) ouvre la bibliothèque au chargement.
 * « Hide this guide » désactive le panneau dans les préférences de l'éditeur ; Préférences > Général > Panneaux le ramène.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel || ! wp.plugins || ! wp.plugins.registerPlugin ) {
		return;
	}
	const PANEL = 'fluffless-guide/fluffless-guide';
	const OPEN = new URLSearchParams( window.location.search ).get( 'fluffless_open' );

	const editor = () => wp.data.dispatch( 'core/editor' );
	const inserter = ( tab ) => () => editor().setIsInserterOpened( { tab } );
	const sidebar = ( name ) => {
		const ui = wp.data.dispatch( 'core/interface' );
		if ( ui && ui.enableComplementaryArea ) {
			ui.enableComplementaryArea( 'core', name );
		}
	};
	// Ouvre l'onglet Page et clique sur le réglage Modèle, s'il existe pour ce type de contenu.
	const template = () => {
		sidebar( 'edit-post/document' );
		window.setTimeout( () => {
			const button = document.querySelector( '.editor-post-template__dropdown button, .edit-post-post-template__toggle' ) || Array.from( document.querySelectorAll( '.editor-post-panel__row-control .components-dropdown-menu__toggle' ) ).find( ( b ) => b.closest( '.editor-post-panel__row' ) && /template|mod[eè]le/i.test( b.closest( '.editor-post-panel__row' ).textContent + ' ' + ( b.getAttribute( 'aria-label' ) || '' ) ) );
			if ( button ) {
				button.click();
			}
		}, 100 );
	};

	// Ouvre l'onglet Page et le panneau « Page style » (assets/page-style.js), s'il est chargé.
	const style = () => {
		sidebar( 'edit-post/document' );
		const name = 'fluffless-page-style/fluffless-page-style';
		if ( ! wp.data.select( 'core/editor' ).isEditorPanelOpened( name ) ) {
			editor().toggleEditorPanelOpened( name );
		}
		window.setTimeout( () => {
			const field = document.querySelector( '.fluffless-page-style select, [class*="fluffless-page-style"] select' );
			if ( field ) {
				field.scrollIntoView( { block: 'center' } );
				field.focus();
			}
		}, 150 );
	};

	const actions = () => [
		[ 'plus', __( 'Add a block', 'fluffless-suite' ), __( 'Paragraph, image, button… Tip: type / on an empty line.', 'fluffless-suite' ), inserter( 'blocks' ) ],
		[ 'layout', __( 'Add a ready-made section', 'fluffless-suite' ), __( 'Openings, services, testimonials, contact and more.', 'fluffless-suite' ), inserter( 'patterns' ) ],
		[ 'page', __( 'Choose a page template', 'fluffless-suite' ), __( 'The frame around your content.', 'fluffless-suite' ), template ],
		[ 'art', __( 'Choose the style of this page', 'fluffless-suite' ), __( 'Shapes and fonts for this page only, in your site colours.', 'fluffless-suite' ), style ],
		[ 'admin-generic', __( 'Settings of a block', 'fluffless-suite' ), __( 'Colours, spacing and options of the selected block.', 'fluffless-suite' ), () => sidebar( 'edit-post/block' ) ],
	];

	// Ouvre le panneau une seule fois par personne ; ensuite, son état ouvert ou fermé est respecté.
	const openOnce = () => {
		const prefs = wp.data.select( 'core/preferences' );
		const state = wp.data.select( 'core/editor' );
		if ( 'blocks' === OPEN || 'patterns' === OPEN ) {
			inserter( OPEN )();
		}
		if ( ! prefs || ! state || prefs.get( 'fluffless/guide', 'opened' ) ) {
			return;
		}
		if ( ! state.isEditorPanelOpened( PANEL ) ) {
			editor().toggleEditorPanelOpened( PANEL );
		}
		wp.data.dispatch( 'core/preferences' ).set( 'fluffless/guide', 'opened', true );
	};

	const Guide = () => {
		wp.element.useEffect( openOnce, [] );
		const hide = () => editor().toggleEditorPanelEnabled( PANEL );
		return el(
			Panel,
			{ name: 'fluffless-guide', title: __( 'Where to find what', 'fluffless-suite' ), initialOpen: true, className: 'fl-guide-panel' },
			el(
				'ul',
				{ className: 'fl-guide-actions' },
				actions().map( ( action, i ) =>
					el(
						'li',
						{ key: i },
						el(
							'button',
							{ type: 'button', onClick: action[ 3 ] },
							el( wp.components.Dashicon, { icon: action[ 0 ] } ),
							el( 'span', null, el( 'strong', null, action[ 1 ] ), ' ', action[ 2 ] )
						)
					)
				)
			),
			el( wp.components.Button, { variant: 'link', className: 'fl-guide-hide', onClick: hide }, __( 'Hide this guide', 'fluffless-suite' ) )
		);
	};

	wp.plugins.registerPlugin( 'fluffless-guide', { render: Guide } );
} )( window.wp );
