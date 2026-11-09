/**
 * Module Canevas de Fluffless Suite, côté éditeur. JavaScript natif, sans étape de build.
 * - image de fond native ajoutée à Colonnes, Colonne, Détails, Média et texte (graphistes) ;
 * - fond propre au petit écran, sur tout bloc qui a l'image de fond ;
 * - alerte quand une image dépasse le seuil du module Images ;
 * - bouton Visuel / HTML dans l'en-tête, et « Modifier en HTML » dans les sections contentOnly.
 * Données : window.flufflessCanvas (Canvas::editor_data()). Rien de ceci n'arrive sur le site.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { Fragment, createPortal, useEffect, useState } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { addFilter } = wp.hooks;
	const { createHigherOrderComponent } = wp.compose;
	const { getBlockSupport, hasBlockSupport } = wp.blocks;
	const { InspectorControls, MediaUpload, MediaUploadCheck, BlockControls } = wp.blockEditor;
	const { PanelBody, Button, ToggleControl, Notice, ToolbarButton } = wp.components;
	const { useSelect, useDispatch } = wp.data;

	const config = window.flufflessCanvas || { canDesign: false, blocks: [], warnKb: 0, breakpoint: 782 };
	const MOBILE = 'flufflessMobileBg';
	const CALQUE = 'fluffless/calque';

	const hasBackgroundImage = ( name ) => true === getBlockSupport( name, [ 'background', 'backgroundImage' ] );

	/* ---------- Supports et attributs ---------- */

	// L'attribut de fond mobile est déclaré pour tout le monde : sans lui, une personne en mode
	// Simple qui modifie la page perdrait le réglage d'une graphiste à l'enregistrement.
	// Le support d'image de fond, lui, n'est ajouté que pour les graphistes : WordPress ne
	// l'écrit pas dans le balisage enregistré, donc aucun bloc ne devient invalide.
	addFilter( 'blocks.registerBlockType', 'fluffless/canvas/supports', ( settings, name ) => {
		let next = settings;
		const supports = next.supports || {};
		if ( config.canDesign && config.blocks.includes( name ) ) {
			next = { ...next, supports: { ...supports, background: { backgroundImage: true, backgroundSize: true, ...( supports.background || {} ) } } };
		}
		if ( CALQUE === name && ! config.canDesign ) {
			next = { ...next, supports: { ...next.supports, inserter: false } };
		}
		const background = ( next.supports || {} ).background;
		if ( ( background && true === background.backgroundImage ) || config.blocks.includes( name ) ) {
			next = { ...next, attributes: { ...next.attributes, [ MOBILE ]: { type: 'object' } } };
		}
		return next;
	} );

	/* ---------- Alerte de poids ---------- */

	/** Avertit si l'image de la médiathèque dépasse le seuil d'alerte du module Images. */
	function SizeWarning( { id } ) {
		const bytes = useSelect(
			( select ) => {
				if ( ! id || ! config.warnKb ) {
					return 0;
				}
				const media = select( 'core' ).getMedia( id, { context: 'view' } );
				return ( media && media.media_details && media.media_details.filesize ) || 0;
			},
			[ id ]
		);
		if ( ! bytes || bytes <= config.warnKb * 1024 ) {
			return null;
		}
		return el(
			Notice,
			{ status: 'warning', isDismissible: false },
			sprintf(
				/* translators: 1: image weight in KB, 2: warning threshold in KB. */
				__( 'This image weighs %1$d KB, above the %2$d KB warning threshold of the Images module. A lighter file loads faster and uses less energy.', 'fluffless-suite' ),
				Math.round( bytes / 1024 ),
				config.warnKb
			)
		);
	}
	config.SizeWarning = SizeWarning;

	/* ---------- Couche du calque ---------- */

	const PINS = [ 'center', 'top-left', 'top-right', 'bottom-left', 'bottom-right' ];
	const LENGTH_MAX = { px: 4000, rem: 250 };
	const bounded = ( raw, min, max ) => ( 'number' === typeof raw && isFinite( raw ) ? Math.max( min, Math.min( max, Math.round( raw ) ) ) : null );
	const length = ( raw ) => {
		const match = 'string' === typeof raw ? /^(\d{1,4}(?:\.\d{1,2})?)(px|rem)$/.exec( raw ) : null;
		return match && parseFloat( match[ 1 ] ) <= LENGTH_MAX[ match[ 2 ] ] ? raw : null;
	};

	/**
	 * Classes et variables de la couche, miroir de Canvas::layer_classes() et layer_style() :
	 * l'aperçu de l'éditeur et le site lisent le même CSS (Canvas::calque_css()).
	 * La classe wp-block-fluffless-calque est ajoutée par useBlockProps.
	 */
	config.calqueLayer = ( a ) => {
		const pick = ( raw, min, max, fallback ) => bounded( raw, min, max ) ?? fallback;
		const clip = 'overflow' === a.clip ? 'overflow' : 'clip';
		const className = [
			'fluffless-calque-layer',
			'front' === a.layer ? 'is-front' : 'is-back',
			'is-pin-' + ( PINS.includes( a.pin ) ? a.pin : 'center' ),
			'is-' + clip,
			true === a.hideMobile ? 'is-hide-mobile' : '',
		].filter( Boolean ).join( ' ' );
		const opacity = pick( a.opacity, 0, 100, 100 );
		const style = {
			'--fluffless-x': pick( a.x, -50, 150, 50 ) + '%',
			'--fluffless-y': pick( a.y, -50, 150, 50 ) + '%',
			'--fluffless-w': String( pick( a.width, 2, 200, 30 ) ),
			'--fluffless-r': pick( a.rotate, -180, 180, 0 ) + 'deg',
			'--fluffless-o': String( opacity / 100 ),
		};
		const clipMargin = pick( a.clipMargin, 0, 64, 0 );
		const withUnit = ( value, unit ) => ( null === value ? null : value + unit );
		const extra = {
			blend: a.blend && 'normal' !== a.blend ? a.blend : null,
			min: length( a.minWidth ),
			max: length( a.maxWidth ),
			clip: clipMargin > 0 ? clipMargin + 'px' : null,
			xm: withUnit( bounded( a.xMobile, -50, 150 ), '%' ),
			ym: withUnit( bounded( a.yMobile, -50, 150 ), '%' ),
			wm: withUnit( bounded( a.widthMobile, 2, 200 ), '' ),
		};
		Object.keys( extra ).forEach( ( key ) => {
			if ( null !== extra[ key ] ) {
				style[ '--fluffless-' + key ] = extra[ key ];
			}
		} );
		return { className, style };
	};

	/* ---------- Fond petit écran ---------- */

	function MobilePanel( { attributes, setAttributes } ) {
		const value = attributes[ MOBILE ] || {};
		const desktop = attributes.style && attributes.style.background && attributes.style.background.backgroundImage;
		const set = ( next ) => setAttributes( { [ MOBILE ]: next } );
		return el(
			InspectorControls,
			{ group: 'styles' },
			el(
				PanelBody,
				{ title: __( 'Small-screen background', 'fluffless-suite' ), initialOpen: !! ( value.id || value.hide ) },
				el( SizeWarning, { id: desktop && desktop.id } ),
				el( ToggleControl, {
					label: __( 'No background image on small screens', 'fluffless-suite' ),
					checked: true === value.hide,
					onChange: ( hide ) => set( hide ? { hide: true } : undefined ),
					__nextHasNoMarginBottom: true,
				} ),
				value.hide
					? null
					: el(
							MediaUploadCheck,
							null,
							el( MediaUpload, {
								allowedTypes: [ 'image' ],
								value: value.id,
								onSelect: ( media ) => media && media.id && set( { id: media.id, url: media.url || '' } ),
								render: ( { open } ) =>
									el(
										'div',
										{ className: 'fluffless-canvas-mobile' },
										value.url ? el( 'img', { src: value.url, alt: '', style: { display: 'block', maxWidth: '100%', marginBottom: '8px' } } ) : null,
										el( Button, { variant: 'secondary', onClick: open, __next40pxDefaultSize: true }, value.id ? __( 'Replace image', 'fluffless-suite' ) : __( 'Choose image', 'fluffless-suite' ) ),
										value.id ? el( Button, { variant: 'tertiary', isDestructive: true, onClick: () => set( undefined ), __next40pxDefaultSize: true }, __( 'Remove', 'fluffless-suite' ) ) : null
									),
							} )
					  ),
				el( SizeWarning, { id: value.id } ),
				el(
					'p',
					{ className: 'components-base-control__help' },
					sprintf(
						/* translators: %d: width in pixels. */
						__( 'Replaces the background image under %d px wide. Phones then skip the large desktop image.', 'fluffless-suite' ),
						config.breakpoint
					)
				)
			)
		);
	}

	addFilter(
		'editor.BlockEdit',
		'fluffless/canvas/mobile-background',
		createHigherOrderComponent(
			( BlockEdit ) => ( props ) => {
				if ( ! config.canDesign || ! props.isSelected || ! hasBackgroundImage( props.name ) ) {
					return el( BlockEdit, props );
				}
				return el( Fragment, null, el( BlockEdit, props ), el( MobilePanel, props ) );
			},
			'withFlufflessMobileBackground'
		)
	);

	// Aperçu fidèle : même classe et même variable que le rendu serveur (Canvas::render_block()).
	addFilter(
		'editor.BlockListBlock',
		'fluffless/canvas/mobile-preview',
		createHigherOrderComponent(
			( BlockListBlock ) => ( props ) => {
				const value = props.attributes && props.attributes[ MOBILE ];
				if ( ! value || ( ! value.hide && ! value.url ) ) {
					return el( BlockListBlock, props );
				}
				const css = value.hide ? 'none' : 'url("' + encodeURI( value.url ).replace( /"/g, '%22' ) + '")';
				const wrapperProps = props.wrapperProps || {};
				return el( BlockListBlock, {
					...props,
					className: [ props.className, 'fluffless-bg-m' ].filter( Boolean ).join( ' ' ),
					wrapperProps: { ...wrapperProps, style: { ...( wrapperProps.style || {} ), '--fluffless-bg-m': css } },
				} );
			},
			'withFlufflessMobilePreview'
		)
	);

	/* ---------- Bascule Visuel / HTML ---------- */

	/**
	 * Bouton dans la barre d'en-tête. WordPress n'offre pas d'emplacement public à cet endroit :
	 * le bouton est posé par un portail au début de .editor-header__settings. Si cette classe
	 * disparaît un jour, le bouton ne s'affiche pas et la commande native reste dans le menu
	 * Options (Éditeur de code) et au clavier.
	 */
	function ModeSwitch() {
		const { mode, shortcut, enabled } = useSelect(
			( select ) => ( {
				mode: select( 'core/editor' ).getEditorMode(),
				shortcut: select( 'core/keyboard-shortcuts' ).getShortcutRepresentation( 'core/editor/toggle-mode' ),
				enabled: select( 'core/block-editor' ).getSettings().codeEditingEnabled,
			} ),
			[]
		);
		const { switchEditorMode } = useDispatch( 'core/editor' );
		const [ host, setHost ] = useState( null );
		useEffect( () => {
			const bar = document.querySelector( '.editor-header__settings' );
			if ( ! bar ) {
				return undefined;
			}
			const span = document.createElement( 'span' );
			span.className = 'fluffless-mode-switch';
			bar.prepend( span );
			setHost( span );
			return () => span.remove();
		}, [] );
		if ( ! enabled || ! host ) {
			return null;
		}
		const toCode = 'visual' === mode;
		const label = toCode ? __( 'Switch to HTML', 'fluffless-suite' ) : __( 'Switch to visual editing', 'fluffless-suite' );
		return createPortal(
			el(
				Button,
				{
					variant: 'secondary',
					size: 'compact',
					icon: toCode ? 'editor-code' : 'visibility',
					onClick: () => switchEditorMode( toCode ? 'text' : 'visual' ),
					label: shortcut ? label + ' (' + shortcut + ')' : label,
					showTooltip: true,
				},
				toCode ? __( 'HTML', 'fluffless-suite' ) : __( 'Visual', 'fluffless-suite' )
			),
			host
		);
	}

	/* ---------- « Modifier en HTML » dans une section verrouillée ---------- */

	/**
	 * En mode contentOnly, le cœur ne rend pas le menu du bloc qui porte « Modifier en HTML »
	 * (block-editor.js : `!isContentOnly && BlockSettingsMenuControls.Slot`). La barre d'outils
	 * du bloc, elle, reste affichée : même action, même libellé, rendue seulement dans ce cas-là.
	 */
	function LockedHtmlButton( { clientId, name } ) {
		const info = useSelect(
			( select ) => {
				const editor = select( 'core/block-editor' );
				const block = editor.getBlock( clientId );
				return {
					valid: !! block && block.isValid,
					mode: editor.getBlockMode( clientId ),
					editing: editor.getBlockEditingMode( clientId ),
					code: editor.getSettings().codeEditingEnabled,
				};
			},
			[ clientId ]
		);
		const { toggleBlockMode } = useDispatch( 'core/block-editor' );
		if ( 'contentOnly' !== info.editing || ! info.code || ! info.valid || ! hasBlockSupport( name, 'html', true ) ) {
			return null;
		}
		const toCode = 'visual' === info.mode;
		return el(
			BlockControls,
			{ group: 'other' },
			el(
				ToolbarButton,
				{ icon: toCode ? 'editor-code' : 'visibility', onClick: () => toggleBlockMode( clientId ) },
				toCode ? __( 'Edit as HTML', 'fluffless-suite' ) : __( 'Edit visually', 'fluffless-suite' )
			)
		);
	}

	if ( config.canDesign ) {
		addFilter(
			'editor.BlockEdit',
			'fluffless/canvas/locked-html',
			createHigherOrderComponent(
				( BlockEdit ) => ( props ) =>
					el(
						Fragment,
						null,
						el( BlockEdit, props ),
						props.isSelected ? el( LockedHtmlButton, { clientId: props.clientId, name: props.name } ) : null
					),
				'withFlufflessLockedHtml'
			)
		);
	}

	if ( config.canDesign && wp.plugins ) {
		wp.plugins.registerPlugin( 'fluffless-canvas', { render: () => el( ModeSwitch ) } );
	}
} )( window.wp );
