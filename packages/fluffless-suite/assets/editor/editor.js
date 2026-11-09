/**
 * Éditeur de pages Fluffless (module Éditeur). JavaScript natif, sans étape de build.
 * - écran de départ plein écran sur une page neuve et vide, avec aperçu réel des compositions ;
 * - questionnaire qui remplit les blocs nommés (metadata.name) de la composition choisie ;
 * - « Ajouter une section » entre les sections, bibliothèque par catégories ;
 * - mode Simple ou Complet par personne (barre latérale Fluffless et menu Options) ;
 * - gabarits de pages enregistrés par l'agence (route REST fluffless/v1/page-templates).
 * Données : window.flufflessEditor (Editor::editor_data()). Logique pure : window.flEditorCore.
 */
( function ( wp, core, config ) {
	if ( ! wp || ! core || ! config ) {
		return;
	}
	const el = wp.element.createElement;
	const { Fragment, useEffect, useMemo, useRef, useState } = wp.element;
	const { Modal, Button, TextControl, TextareaControl, ToggleControl, CheckboxControl, SearchControl, PanelBody, ToolbarGroup, ToolbarButton, VisuallyHidden } = wp.components;
	const { useSelect, select, dispatch } = wp.data;
	const { BlockPreview, BlockControls } = wp.blockEditor;
	const PatternsList = wp.blockEditor.__experimentalBlockPatternsList;
	const { PluginSidebar, PluginMoreMenuItem, PluginPrePublishPanel } = wp.editor;
	const { addFilter, addAction, removeAction, doAction } = wp.hooks;
	const { createHigherOrderComponent } = wp.compose;
	const { sprintf } = wp.i18n;
	const t = config.i18n;
	const OPEN_LIBRARY = 'fluffless.editor.openLibrary';
	const OPEN_START = 'fluffless.editor.openStart';
	const SIDEBAR = 'fluffless-editor/fluffless-editor';

	/** Icône pixel art (pixelarticons, tracés passés par PHP), décorative. null si absente. */
	const icon = ( name ) => {
		const paths = ( config.icons || {} )[ name ];
		return paths
			? el( 'svg', { className: 'fl-pixel', xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, fill: 'currentColor', shapeRendering: 'crispEdges', 'aria-hidden': 'true', focusable: 'false' },
				paths.map( ( d, i ) => el( 'path', { key: i, d } ) ) )
			: null;
	};
	const tile = ( name ) => el( 'span', { className: 'fl-pixel-tile' }, icon( name ) );

	// createBlock plutôt que cloneBlock : cloneBlock fusionne les attributs, un verrou retiré resterait.
	const make = ( block, attributes, innerBlocks ) => wp.blocks.createBlock( block.name, attributes, innerBlocks );
	// Mode Simple : verrou contentOnly sur les sections ; Complet : sections libres.
	const prepare = ( blocks ) => ( config.simple ? core.lockSections( blocks, make ) : core.unlockSections( blocks, make ) );

	// Contenu tel qu'inséré depuis un modèle : tant qu'il n'a pas changé, la page n'a rien à soi.
	let templateContent = null;

	function applyTemplate( page, answers ) {
		const blocks = prepare( core.fill( wp.blocks.parse( page.content ), answers || {}, make ) );
		dispatch( 'core/editor' ).resetEditorBlocks( blocks );
		// Titre vide : la page prend le nom de son type (Accueil, À propos...), modifiable ensuite.
		if ( ! select( 'core/editor' ).getEditedPostAttribute( 'title' ) ) {
			dispatch( 'core/editor' ).editPost( { title: page.title } );
		}
		// La composition porte son h1 : gabarit sans titre du thème, sauf si un gabarit a déjà été choisi.
		if ( config.fullTpl && ! select( 'core/editor' ).getEditedPostAttribute( 'template' ) ) {
			dispatch( 'core/editor' ).editPost( { template: config.fullTpl } );
		}
		templateContent = select( 'core/editor' ).getEditedPostContent();
		if ( answers ) {
			// Avis d'en-tête, pas flottant : à 375 px un avis flottant couvre le premier « Ajouter une section » (P3-02).
			dispatch( 'core/notices' ).createInfoNotice( t.ready, { id: 'fluffless-editor-ready', isDismissible: true, actions: [ { label: t.reviewOpen, onClick: () => dispatch( 'core/interface' ).enableComplementaryArea( 'core', SIDEBAR ) } ] } );
		}
	}

	/* ---------- Écran de départ ---------- */

	function Card( { page, onAsk, onTemplate, onRemove } ) {
		const blocks = useMemo( () => wp.blocks.parse( page.content ), [ page.content ] );
		const asks = ( page.questions || [] ).length > 0;
		// Les boutons se répètent d'une carte à l'autre : chacun renvoie au nom de sa carte (C1-10-04).
		const heading = 'fl-start-' + String( page.name ).replace( /[^\w-]/g, '-' );
		return el( 'li', { className: 'fl-start__card' + ( page.saved ? ' fl-start__card--saved' : '' ) },
			el( 'h2', { className: 'fl-start__name', id: heading }, tile( page.icon || ( config.pageIcons || {} )[ page.name ] ), page.title ),
			el( 'div', { className: 'fl-start__preview', role: 'img', 'aria-label': sprintf( t.preview, page.title ) },
				el( BlockPreview, { blocks, viewportWidth: 1400 } )
			),
			el( 'p', { className: 'fl-start__description' }, page.saved ? el( 'span', { className: 'fl-start__badge' }, t.savedBadge ) : null, page.description ),
			el( 'div', { className: 'fl-start__actions' },
				asks && el( Button, { variant: 'primary', 'data-fl-ask': page.name, 'aria-describedby': heading, onClick: onAsk }, t.ask ),
				el( Button, { variant: asks ? 'secondary' : 'primary', 'aria-describedby': heading, onClick: onTemplate }, t.template ),
				page.saved && onRemove && el( Button, { variant: 'tertiary', isDestructive: true, onClick: () => onRemove( page ) }, t.remove )
			)
		);
	}

	function Questions( { page, onBack, onDone, onDirty } ) {
		const [ step, setStep ] = useState( 0 );
		const [ answers, setAnswers ] = useState( {} );
		const form = useRef( null );
		const total = page.questions.length;
		const question = page.questions[ step ];
		const last = step === total - 1;

		useEffect( () => {
			const field = form.current && form.current.querySelector( 'input, textarea' );
			if ( field ) {
				field.focus();
			}
		}, [ step ] );

		const advance = ( next ) => ( last ? onDone( next ) : setStep( step + 1 ) );
		const submit = ( event ) => {
			event.preventDefault();
			advance( answers );
		};
		const skip = () => {
			const next = { ...answers, [ question.name ]: '' };
			setAnswers( next );
			advance( next );
		};
		const field = el( question.long ? TextareaControl : TextControl, {
			key: question.name,
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
			label: question.label,
			help: question.example,
			value: answers[ question.name ] || '',
			onChange: ( value ) => {
				const next = { ...answers, [ question.name ]: value };
				setAnswers( next );
				onDirty( Object.values( next ).some( ( answer ) => '' !== String( answer ).trim() ) );
			},
		} );

		return el( 'form', {
			ref: form,
			className: 'fl-questions',
			onSubmit: submit,
			onKeyDown: ( event ) => {
				if ( 'Enter' === event.key && ( event.ctrlKey || event.metaKey ) ) {
					submit( event );
				}
			},
		},
			el( 'h2', { className: 'fl-questions__page' }, tile( ( config.pageIcons || {} )[ page.name ] ), page.title ),
			el( 'p', { className: 'fl-questions__step', 'aria-live': 'polite' }, sprintf( t.step, step + 1, total ) ),
			field,
			el( 'p', { className: 'fl-questions__keys' }, t.keys ),
			el( 'div', { className: 'fl-questions__actions' },
				el( Button, { variant: 'tertiary', className: 'fl-questions__back', onClick: () => ( 0 === step ? onBack() : setStep( step - 1 ) ) }, 0 === step ? t.back : t.previous ),
				el( Button, { variant: 'secondary', onClick: skip }, t.skip ),
				el( Button, { variant: 'primary', type: 'submit' }, last ? t.finish : t.next )
			)
		);
	}

	function StartScreen( { pages, onClose, onRemove } ) {
		const [ asking, setAsking ] = useState( null );
		const [ dirty, setDirty ] = useState( false );
		// Retour aux types de pages : le focus revient sur le bouton « Répondre » de la carte quittée (C1-10-02).
		const back = useRef( null );
		useEffect( () => {
			if ( asking || ! back.current ) {
				return;
			}
			const name = back.current;
			back.current = null;
			const target = Array.from( document.querySelectorAll( '.fl-start [data-fl-ask]' ) ).find( ( button ) => button.getAttribute( 'data-fl-ask' ) === name );
			if ( target ) {
				target.focus();
			}
		}, [ asking ] );
		// Échap ou clic extérieur : les réponses saisies ne se perdent pas sans confirmation (C1-10-01).
		const close = () => {
			if ( asking && dirty && ! window.confirm( t.discard ) ) {
				return;
			}
			onClose();
		};
		const done = ( page, answers ) => {
			applyTemplate( page, answers );
			onClose();
		};
		return el( Modal, { title: t.title, isFullScreen: true, className: 'fl-start', onRequestClose: close },
			asking
				? el( Questions, { page: asking, onDirty: setDirty, onBack: () => { back.current = asking.name; setAsking( null ); setDirty( false ); }, onDone: ( answers ) => done( asking, answers ) } )
				: el( Fragment, null,
					el( 'p', { className: 'fl-start__intro' }, t.intro ),
					el( 'ul', { className: 'fl-start__grid' },
						pages.map( ( page ) => el( Card, { key: page.name, page, onAsk: () => setAsking( page ), onTemplate: () => done( page, null ), onRemove } ) ),
						el( 'li', { className: 'fl-start__card fl-start__card--blank' },
							el( 'h2', { className: 'fl-start__name' }, tile( 'file' ), t.blank ),
							el( 'p', { className: 'fl-start__description' }, t.blankHelp ),
							el( 'div', { className: 'fl-start__actions' }, el( Button, { variant: 'secondary', onClick: onClose }, t.blankStart ) )
						)
					)
				)
		);
	}

	/* ---------- Bibliothèque « Ajouter une section » ---------- */

	const matches = ( pattern, search ) => {
		if ( ! search ) {
			return true;
		}
		const haystack = [ pattern.title, pattern.description, ...( pattern.keywords || [] ) ].join( ' ' ).toLowerCase();
		return haystack.includes( search.trim().toLowerCase() );
	};

	function Library( { target, onClose } ) {
		const [ category, setCategory ] = useState( '' );
		const [ search, setSearch ] = useState( '' );
		const patterns = useSelect( ( s ) => s( 'core/block-editor' ).__experimentalGetAllowedPatterns( target.root ) || [], [ target.root ] );
		const shown = useMemo(
			// En-têtes et pieds de page (blockTypes core/template-part/*) : pas des sections de page.
			() => patterns.filter( ( p ) => ! ( p.blockTypes || [] ).some( ( b ) => b.startsWith( 'core/template-part' ) ) && ( p.categories || [] ).some( ( c ) => c in config.categories ) && ( ! category || p.categories.includes( category ) ) && matches( p, search ) ),
			[ patterns, category, search ]
		);
		// Nombre de sections annoncé après une recherche ou un filtre, pas à l'ouverture (C1-10-03).
		const opened = useRef( true );
		useEffect( () => {
			if ( opened.current ) {
				opened.current = false;
				return undefined;
			}
			const timer = setTimeout( () => {
				let count = t.noResults;
				if ( 1 === shown.length ) {
					count = t.resultsOne;
				} else if ( shown.length > 1 ) {
					count = sprintf( t.resultsMany, shown.length );
				}
				wp.a11y.speak( count );
			}, 500 );
			return () => clearTimeout( timer );
		}, [ shown.length, category, search ] );
		const insert = ( pattern, blocks ) => {
			dispatch( 'core/block-editor' ).insertBlocks( prepare( blocks.map( ( b ) => wp.blocks.cloneBlock( b ) ) ), target.index, target.root );
			onClose();
		};
		const tab = ( slug, label ) => el( Button, { key: slug || 'all', variant: 'tertiary', icon: slug ? icon( ( config.catIcons || {} )[ slug ] ) : null, isPressed: category === slug, onClick: () => setCategory( slug ) }, label );
		return el( Modal, { title: t.addSection, isFullScreen: true, className: 'fl-library', onRequestClose: onClose },
			el( 'div', { className: 'fl-library__bar' },
				el( SearchControl, { __nextHasNoMarginBottom: true, label: t.search, placeholder: t.search, value: search, onChange: setSearch } ),
				el( 'div', { className: 'fl-library__tabs', role: 'group', 'aria-label': t.categories },
					tab( '', t.all ),
					Object.keys( config.categories ).map( ( slug ) => tab( slug, config.categories[ slug ] ) )
				)
			),
			shown.length
				? el( PatternsList, { label: t.addSection, blockPatterns: shown, shownPatterns: shown, onClickPattern: insert } )
				: el( 'p', null, t.noResults ),
			// Tuile de fin : sections retenues par un module éteint, avec le lien vers son interrupteur.
			! search && ( config.locked || [] ).length > 0 && el( 'ul', { className: 'fl-library__locked' },
				config.locked.map( ( lock ) => el( 'li', { key: lock.url },
					el( 'p', null, 1 === lock.count ? sprintf( t.lockedOne, lock.label ) : sprintf( t.lockedMany, lock.count, lock.label ) ),
					el( Button, { variant: 'secondary', href: lock.url, target: '_blank', rel: 'noopener' }, t.lockedOpen, ' ', el( VisuallyHidden, null, t.newTab ) )
				) )
			)
		);
	}

	/* ---------- Canevas : boutons entre les sections, déverrouillage ---------- */

	/** Section de page : bloc de premier niveau du contenu (hors gabarit quand le gabarit est affiché). */
	function useSection( clientId ) {
		return useSelect( ( s ) => {
			const store = s( 'core/block-editor' );
			const root = store.getBlockRootClientId( clientId );
			const name = store.getBlockName( clientId );
			if ( 'core/post-content' === name || 'core/template-part' === name ) {
				return null;
			}
			if ( '' === root || null === root ) {
				return { root: undefined, index: store.getBlockIndex( clientId ) + 1 };
			}
			return 'core/post-content' === store.getBlockName( root ) ? { root, index: store.getBlockIndex( clientId ) + 1 } : null;
		}, [ clientId ] );
	}

	if ( config.simple ) {
		addFilter( 'editor.BlockListBlock', 'fluffless/editor/add-section', createHigherOrderComponent( ( BlockListBlock ) => ( props ) => {
			const section = useSection( props.clientId );
			// Aperçus (écran de départ, bibliothèque) : registre à part, isPreviewMode vrai.
			const preview = useSelect( ( s ) => !! s( 'core/block-editor' ).getSettings().isPreviewMode, [] );
			if ( ! section || preview ) {
				return el( BlockListBlock, props );
			}
			return el( Fragment, null,
				el( BlockListBlock, props ),
				el( 'div', { className: 'fl-add-section', contentEditable: false },
					el( Button, { className: 'fl-add-section__button', icon: icon( 'plus' ) || 'plus', onClick: () => doAction( OPEN_LIBRARY, section ) }, t.addSection )
				)
			);
		}, 'withFlufflessAddSection' ) );

		// Bloc HTML personnalisé rangé de l'outil d'insertion ; les blocs existants restent valides.
		addFilter( 'blocks.registerBlockType', 'fluffless/editor/html', ( settings, name ) => ( 'core/html' === name ? { ...settings, supports: { ...settings.supports, inserter: false } } : settings ) );
	} else {
		addFilter( 'editor.BlockEdit', 'fluffless/editor/unlock', createHigherOrderComponent( ( BlockEdit ) => ( props ) => {
			const section = useSection( props.clientId );
			if ( ! section || 'contentOnly' !== props.attributes.templateLock ) {
				return el( BlockEdit, props );
			}
			return el( Fragment, null,
				el( BlockEdit, props ),
				el( BlockControls, { group: 'other' },
					el( ToolbarGroup, null, el( ToolbarButton, { icon: icon( 'unlock' ) || 'unlock', label: t.unlock, onClick: () => props.setAttributes( { templateLock: undefined } ) } ) )
				)
			);
		}, 'withFlufflessUnlock' ) );
	}

	/* ---------- Relecture avant publication ---------- */

	// Pages internes déjà vérifiées (slug => { ok, at }), partagé entre le panneau et la publication.
	const slugCache = new Map();
	// Une page absente est revérifiée après ce délai : elle a pu être créée entre-temps, dans un autre onglet.
	const RECHECK_MS = 5000;
	const stale = ( slug ) => {
		const entry = slugCache.get( slug );
		return ! entry || ( false === entry.ok && Date.now() - entry.at > RECHECK_MS );
	};

	/** Consignes intactes, boutons à lier et rappels de migration dans la page, recalculés à chaque modification. */
	function useReview( known, again ) {
		const blocks = useSelect( ( s ) => s( 'core/editor' ).getEditorBlocks(), [] );
		const found = useMemo( () => core.review( blocks, known, config.home ), [ blocks, known ] );
		const [ , setChecked ] = useState( 0 );
		const pending = [ ...new Set( found.links.map( ( l ) => l.slug ).filter( ( slug ) => slug && stale( slug ) ) ) ];
		useEffect( () => {
			if ( ! pending.length ) {
				return;
			}
			pending.forEach( ( slug ) => slugCache.set( slug, { ok: true, at: Date.now() } ) );
			wp.apiFetch( { path: wp.url.addQueryArgs( '/wp/v2/pages', { slug: pending.join( ',' ), status: 'publish,future,draft,pending,private', context: 'edit', _fields: 'slug', per_page: 100 } ) } )
				.then( ( rows ) => {
					const exists = new Set( ( rows || [] ).map( ( r ) => r.slug ) );
					pending.forEach( ( slug ) => slugCache.set( slug, { ok: exists.has( slug ), at: Date.now() } ) );
				} )
				.catch( () => {} )
				.finally( () => setChecked( ( n ) => n + 1 ) );
		}, [ pending.join( ',' ), again ] );
		return { texts: found.texts, reminders: found.reminders, links: found.links.filter( ( l ) => ! l.slug || false === ( slugCache.get( l.slug ) || {} ).ok ) };
	}

	// Tant qu'il reste des consignes, Publier reste bloqué jusqu'à une confirmation explicite.
	// Le verrou vit dans Root (toujours monté) : replier la section ne le lève pas.
	function PublishGate( { review, confirmed, onConfirm } ) {
		return el( Fragment, null,
			el( ReviewList, { review } ),
			el( 'div', { className: 'fluffless-publish-gate' },
				el( CheckboxControl, { __nextHasNoMarginBottom: true, label: t.reviewConfirm, checked: confirmed, onChange: onConfirm } )
			)
		);
	}

	function ReviewList( { review } ) {
		const go = ( clientId ) => dispatch( 'core/block-editor' ).selectBlock( clientId );
		const item = ( entry, detail ) => el( 'li', { key: entry.clientId },
			el( Button, { variant: 'link', onClick: () => go( entry.clientId ) }, entry.text || entry.url || '…' ),
			detail ? el( 'span', { className: 'fl-review__detail' }, ' (' + detail + ')' ) : null
		);
		if ( ! review.texts.length && ! review.links.length && ! review.reminders.length ) {
			return el( 'p', { className: 'fl-review' }, t.reviewDone );
		}
		return el( 'div', { className: 'fl-review' },
			el( 'p', null, t.reviewHelp ),
			review.reminders.length > 0 && el( Fragment, null,
				el( 'h3', null, sprintf( t.reviewReminders, review.reminders.length ) ),
				el( 'ul', null, review.reminders.map( ( e ) => item( e ) ) )
			),
			review.texts.length > 0 && el( Fragment, null,
				el( 'h3', null, sprintf( t.reviewTexts, review.texts.length ) ),
				el( 'ul', null, review.texts.map( ( e ) => item( e ) ) )
			),
			review.links.length > 0 && el( Fragment, null,
				el( 'h3', null, sprintf( t.reviewLinks, review.links.length ) ),
				el( 'ul', null, review.links.map( ( e ) => item( e, e.slug ? sprintf( t.reviewMissing, '/' + e.slug + '/' ) : t.reviewNoLink ) ) )
			)
		);
	}

	/** Avis d'éditeur unique par identifiant : posé tant que `text` est vrai, retiré dès qu'il ne l'est plus. */
	function useNotice( id, text, actions ) {
		const key = text || '';
		useEffect( () => {
			const notices = dispatch( 'core/notices' );
			if ( key ) {
				notices.createWarningNotice( key, { id, isDismissible: false, actions: actions || [] } );
			}
			return () => notices.removeNotice( id );
		}, [ key ] );
	}

	/* ---------- Mode, barre latérale, racine ---------- */

	function setMode( next ) {
		const notices = dispatch( 'core/notices' );
		wp.apiFetch( { path: '/wp/v2/users/me', method: 'POST', data: { meta: { [ config.meta ]: next } } } )
			.then( () => {
				if ( ! select( 'core/editor' ).isEditedPostDirty() ) {
					window.location.reload();
					return;
				}
				notices.createInfoNotice( t.modeSaved, { actions: [ { label: t.reload, onClick: () => window.location.reload() } ] } );
			} )
			.catch( ( error ) => notices.createErrorNotice( sprintf( t.modeError, ( error && error.message ) || String( error ) ) ) );
	}

	/* ---------- Gabarits de pages enregistrés par l'agence ---------- */

	const TEMPLATES = '/fluffless/v1/page-templates';

	function SaveTemplate( { onClose, onSaved } ) {
		const [ title, setTitle ] = useState( () => select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '' );
		const [ busy, setBusy ] = useState( false );
		const submit = ( event ) => {
			event.preventDefault();
			if ( ! title.trim() || busy ) {
				return;
			}
			setBusy( true );
			wp.apiFetch( { path: TEMPLATES, method: 'POST', data: { title: title.trim(), content: select( 'core/editor' ).getEditedPostContent() } } )
				.then( ( saved ) => {
					dispatch( 'core/notices' ).createSuccessNotice( sprintf( t.saved, saved.title ), { type: 'snackbar' } );
					onSaved( saved );
					onClose();
				} )
				.catch( ( error ) => {
					setBusy( false );
					dispatch( 'core/notices' ).createErrorNotice( sprintf( t.saveError, ( error && error.message ) || String( error ) ) );
				} );
		};
		return el( Modal, { title: t.saveTemplate, className: 'fl-save-template', onRequestClose: onClose },
			el( 'form', { onSubmit: submit },
				el( TextControl, { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true, label: t.saveTitle, help: t.saveHelp, value: title, onChange: setTitle, maxLength: 80 } ),
				el( 'div', { className: 'fl-save-template__actions' },
					el( Button, { variant: 'tertiary', onClick: onClose }, t.cancel ),
					el( Button, { variant: 'primary', type: 'submit', isBusy: busy, disabled: busy || ! title.trim() }, t.saveConfirm )
				)
			)
		);
	}

	function removeTemplate( page ) {
		if ( ! window.confirm( sprintf( t.removeConfirm, page.title ) ) ) {
			return Promise.resolve( false );
		}
		return wp.apiFetch( { path: TEMPLATES + '/' + page.id, method: 'DELETE' } )
			.then( () => true )
			.catch( ( error ) => {
				dispatch( 'core/notices' ).createErrorNotice( ( error && error.message ) || String( error ) );
				return false;
			} );
	}

	function useHook( name, callback ) {
		useEffect( () => {
			addAction( name, 'fluffless/editor', callback );
			return () => removeAction( name, 'fluffless/editor' );
		}, [] );
	}

	function Root() {
		// Contenu des compositions : déjà chargé par l'éditeur (core-data), pas dupliqué dans la page.
		const registered = useSelect( ( s ) => s( 'core' ).getBlockPatterns(), [] );
		// Gabarits enregistrés ou retirés pendant la séance, sans recharger l'éditeur.
		const [ extra, setExtra ] = useState( [] );
		const [ removed, setRemoved ] = useState( [] );
		const canSave = !! ( config.templates && config.templates.save );
		const pages = useMemo( () => {
			const byName = new Map( ( registered || [] ).map( ( p ) => [ p.name, p.content ] ) );
			const known = config.pages.filter( ( p ) => byName.has( p.name ) && ! removed.includes( p.name ) ).map( ( p ) => ( { ...p, content: byName.get( p.name ) } ) );
			const names = new Set( known.map( ( p ) => p.name ) );
			return known.concat( extra.filter( ( p ) => ! names.has( p.name ) && ! removed.includes( p.name ) ) );
		}, [ registered, extra, removed ] );
		const onSaved = ( saved ) => setExtra( ( list ) => list.filter( ( p ) => p.name !== saved.name ).concat( [ saved ] ) );
		const onRemove = ( page ) => removeTemplate( page ).then( ( ok ) => ok && setRemoved( ( list ) => list.concat( [ page.name ] ) ) );
		// Consignes connues : textes des compositions Fluffless (pages et sections).
		const known = useMemo( () => core.placeholders( ( registered || [] ).filter( ( p ) => /^fluffless(-suite)?\//.test( p.name ) ).flatMap( ( p ) => wp.blocks.parse( p.content ) ) ), [ registered ] );
		const [ again, setAgain ] = useState( 0 );
		const review = useReview( known, again );
		const issues = review.texts.length + review.links.length + review.reminders.length;
		const publishOpen = useSelect( ( s ) => !! s( 'core/editor' ).isPublishSidebarOpened(), [] );
		// Préférence « vérifications avant publication » : désactivée, le panneau n'existe pas et rien ne retient Publier.
		const checksOn = useSelect( ( s ) => false !== s( 'core/preferences' ).get( 'core', 'isPublishSidebarEnabled' ), [] );
		const [ confirmed, setConfirmed ] = useState( false );
		const showSidebar = () => dispatch( 'core/interface' ).enableComplementaryArea( 'core', SIDEBAR );
		useEffect( () => {
			// Une page absente a pu être créée ailleurs : revérifier au retour dans l'onglet et à l'ouverture du panneau.
			const recheck = () => setAgain( ( n ) => n + 1 );
			window.addEventListener( 'focus', recheck );
			return () => window.removeEventListener( 'focus', recheck );
		}, [] );
		useEffect( () => setAgain( ( n ) => n + 1 ), [ publishOpen ] );
		useEffect( () => {
			// Chaque tentative de publication demande sa propre confirmation.
			if ( ! publishOpen ) {
				setConfirmed( false );
			}
		}, [ publishOpen ] );
		const locked = issues > 0 && ! confirmed && publishOpen;
		useEffect( () => {
			const editor = dispatch( 'core/editor' );
			if ( locked ) {
				editor.lockPostSaving( 'fluffless-review' );
			} else {
				editor.unlockPostSaving( 'fluffless-review' );
			}
			return () => editor.unlockPostSaving( 'fluffless-review' );
		}, [ locked ] );
		useNotice( 'fluffless-review-off', ! checksOn && issues > 0 ? sprintf( t.reviewOff, issues ) : '', [ { label: t.reviewOpen, onClick: showSidebar } ] );
		useNotice( 'fluffless-review-reminders', review.reminders.length > 0 ? sprintf( t.reviewRemindersNotice, review.reminders.length ) : '', [ { label: t.reviewOpen, onClick: showSidebar } ] );
		const [ starting, setStarting ] = useState( () => config.pages.length > 0 && select( 'core/editor' ).isEditedPostNew() && select( 'core/editor' ).isEditedPostEmpty() && ! new URLSearchParams( window.location.search ).has( 'fluffless_open' ) );
		const [ target, setTarget ] = useState( null );
		const [ saving, setSaving ] = useState( false );
		const ownContent = useSelect( ( s ) => {
			const editor = s( 'core/editor' );
			return ! editor.isEditedPostEmpty() && editor.getEditedPostContent() !== templateContent;
		}, [] );
		const end = useSelect( ( s ) => s( 'core/block-editor' ).getBlockCount(), [] );
		useHook( OPEN_LIBRARY, setTarget );
		useHook( OPEN_START, () => setStarting( true ) );

		useEffect( () => {
			if ( config.simple ) {
				// canEditCSS est posé par WordPress après block_editor_settings_all : rangé ici.
				dispatch( 'core/editor' ).updateEditorSettings( { canEditCSS: false } );
				dispatch( 'core/block-editor' ).updateSettings( { canEditCSS: false } );
			}
		}, [] );

		const other = config.simple ? 'full' : 'simple';
		return el( Fragment, null,
			starting && pages.length > 0 && el( StartScreen, { pages, onClose: () => setStarting( false ), onRemove: canSave ? onRemove : null } ),
			target && el( Library, { target, onClose: () => setTarget( null ) } ),
			saving && el( SaveTemplate, { onClose: () => setSaving( false ), onSaved } ),
			el( PluginMoreMenuItem, { icon: ( config.simple ? icon( 'pen-square' ) : icon( 'eye' ) ) || 'layout', onClick: () => setMode( other ) }, config.simple ? t.toFull : t.toSimple ),
			el( PluginSidebar, { name: 'fluffless-editor', title: t.sidebar, icon: icon( 'layout-solid' ) || 'layout' },
				el( PanelBody, { className: 'fl-editor-panel' },
					el( ToggleControl, { __nextHasNoMarginBottom: true, label: t.modeLabel, help: t.modeHelp, checked: 'simple' === config.mode, onChange: ( on ) => setMode( on ? 'simple' : 'full' ) } ),
					el( Button, { variant: 'primary', icon: icon( 'plus' ) || 'plus', onClick: () => setTarget( { root: undefined, index: end } ) }, t.addSection ),
					// Toujours visible ; avec du contenu propre à la page, une confirmation avant de l'écraser.
					config.pages.length > 0 && el( Button, { variant: 'secondary', onClick: () => { if ( ! ownContent || window.confirm( t.changeTemplateConfirm ) ) { doAction( OPEN_START ); } } }, t.changeTemplate ),
					canSave && ownContent && el( Button, { variant: 'secondary', icon: icon( 'clipboard-note' ) || 'saved', onClick: () => setSaving( true ) }, t.saveTemplate )
				),
				el( PanelBody, { title: t.reviewTitle, initialOpen: issues > 0 }, el( ReviewList, { review } ) )
			),
			PluginPrePublishPanel && issues > 0 && el( PluginPrePublishPanel, { title: t.reviewTitle, initialOpen: true }, el( PublishGate, { review, confirmed, onConfirm: setConfirmed } ) )
		);
	}

	wp.plugins.registerPlugin( 'fluffless-editor', { render: Root } );
} )( window.wp, window.flEditorCore, window.flufflessEditor );
