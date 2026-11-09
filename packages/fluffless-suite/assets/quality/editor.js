/**
 * Panneau « Qualité du contenu » de Fluffless Suite. JavaScript natif, sans build, éditeur seulement.
 *
 * Relit les blocs à chaque modification (quality-core.js) : ordre des titres, textes de
 * remplacement, textes de lien vagues. Aucun appel réseau : des règles et des listes de mots.
 * Chaque ligne sélectionne le bloc en cause ; une image peut être marquée décorative.
 */
( function ( wp, core ) {
	const el = wp.element.createElement;
	const { useMemo, useRef } = wp.element;
	const { __, sprintf, _n } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel, PluginPrePublishPanel } = wp.editor;
	const { Button, Notice } = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const speak = wp.a11y && wp.a11y.speak ? wp.a11y.speak : () => {};

	const config = window.flufflessQuality || {};
	// Types de contenu qui ne sont pas une page lue par quelqu'un : la règle du H1 ne s'y applique pas.
	const NOT_PAGES = [ 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation' ];

	/** Le gabarit choisi montre-t-il le titre en H1 ? true, false ou null (inconnu). */
	function titleIsH1( postType, template ) {
		if ( ! postType || NOT_PAGES.indexOf( postType ) !== -1 ) {
			return null;
		}
		const templates = config.templates || {};
		const slug = template || config.defaultTemplate || '';
		return 'boolean' === typeof templates[ slug ] ? templates[ slug ] : null;
	}

	function message( issue ) {
		switch ( issue.type ) {
			case 'heading-empty':
				return __( 'Empty heading: write it or remove it.', 'fluffless-suite' );
			case 'heading-skip':
				return issue.first
					? sprintf(
						/* translators: 1: heading level used, 2: heading text */
						__( 'First heading “%2$s” is H%1$d: start at H2 so the outline has no gap.', 'fluffless-suite' ),
						issue.level,
						issue.text
					)
					: sprintf(
						/* translators: 1: heading level used, 2: level of the heading before it, 3: level to use, 4: heading text */
						__( 'H%1$d “%4$s” right after H%2$d: use H%3$d so the outline has no gap.', 'fluffless-suite' ),
						issue.level,
						issue.previous,
						issue.previous + 1,
						issue.text
					);
			case 'h1-duplicate':
				/* translators: %s: heading text */
				return sprintf( __( 'H1 “%s” in the content: the template already shows the page title as H1. Use H2.', 'fluffless-suite' ), issue.text );
			case 'h1-missing':
				return __( 'No H1: this template does not show the page title, so the page needs one H1 heading.', 'fluffless-suite' );
			case 'h1-extra':
				/* translators: %s: heading text */
				return sprintf( __( 'Second H1 “%s”: keep one per page and use H2 here.', 'fluffless-suite' ), issue.text );
			case 'alt-missing':
				return issue.canMarkDecorative
					/* translators: %s: image file name */
					? sprintf( __( 'Image %s without alternative text. Describe what it shows, or mark it as decorative if it adds nothing.', 'fluffless-suite' ), issue.file )
					/* translators: %s: image file name */
					: sprintf( __( 'Image %s without alternative text. Describe what it shows.', 'fluffless-suite' ), issue.file );
			case 'alt-linked':
				/* translators: %s: image file name */
				return sprintf( __( 'Linked image %s without alternative text: the link has no name. Say where it leads.', 'fluffless-suite' ), issue.file );
			case 'alt-weak':
				/* translators: %s: alternative text of the image */
				return sprintf( __( 'Alternative text “%s” reads like a file name or a generic word. Say what the image shows.', 'fluffless-suite' ), issue.text );
			case 'link-vague':
				/* translators: %s: text of the link */
				return sprintf( __( 'Link “%s” does not say where it leads. Screen readers list links out of context: name the destination.', 'fluffless-suite' ), issue.text );
			case 'link-url':
				/* translators: %s: web address used as link text */
				return sprintf( __( 'Link text is a web address (%s): screen readers spell it out. Name the page instead.', 'fluffless-suite' ), issue.text );
			case 'link-empty':
				return __( 'Link without text: screen readers announce an empty link.', 'fluffless-suite' );
			default:
				return issue.type;
		}
	}

	// Intertitre discret, comme ceux des réglages de WordPress, dans le panneau comme à la publication.
	const GROUP_TITLE = { fontSize: '11px', fontWeight: 500, lineHeight: 1.4, margin: '16px 0 8px', textTransform: 'uppercase' };

	const GROUPS = [
		[ 'headings', () => __( 'Headings', 'fluffless-suite' ) ],
		[ 'images', () => __( 'Images', 'fluffless-suite' ) ],
		[ 'links', () => __( 'Links', 'fluffless-suite' ) ],
	];

	const SYNCED = 'synced:';

	/** Identifiants des compositions synchronisées : leur contenu n'est pas dans l'arbre de la page. */
	function syncedIds( blocks, inner, out, depth ) {
		if ( depth > core.MAX_DEPTH ) {
			return out;
		}
		( blocks || [] ).forEach( ( block ) => {
			if ( 'core/block' === block.name ) {
				out.push( block.clientId );
				syncedIds( inner( block.clientId ), inner, out, depth + 1 );
			}
			syncedIds( block.innerBlocks, inner, out, depth + 1 );
		} );
		return out;
	}

	/** L'arbre de la page, compositions synchronisées dépliées. */
	function expand( blocks, selected, depth ) {
		if ( depth > core.MAX_DEPTH ) {
			return blocks;
		}
		return ( blocks || [] ).map( ( block ) => {
			const inner = 'core/block' === block.name ? selected[ SYNCED + block.clientId ] || [] : block.innerBlocks;
			return inner && inner.length ? { ...block, innerBlocks: expand( inner, selected, depth + 1 ) } : block;
		} );
	}

	/**
	 * Les blocs de la publication, avec les identifiants de l'éditeur de blocs (pour les sélectionner
	 * et les modifier). Quand la page s'affiche dans son gabarit, ils vivent sous le bloc Contenu
	 * de la publication ; getEditorBlocks() en dernier recours, ses identifiants peuvent différer.
	 */
	function pageBlocks( editor, blockEditor ) {
		const mode = editor.getRenderingMode ? editor.getRenderingMode() : 'post-only';
		if ( 'post-only' === mode ) {
			return blockEditor.getBlocks();
		}
		const content = blockEditor.getBlocksByName ? blockEditor.getBlocksByName( 'core/post-content' ) : [];
		return content.length ? blockEditor.getBlocks( content[ 0 ] ) : editor.getEditorBlocks();
	}

	function useIssues() {
		const selected = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			const blockEditor = select( 'core/block-editor' );
			const out = {
				blocks: pageBlocks( editor, blockEditor ),
				postType: editor.getCurrentPostType(),
				template: editor.getEditedPostAttribute( 'template' ),
			};
			// Une clé par composition : useSelect compare clé par clé, l'objet reste stable.
			syncedIds( out.blocks, ( id ) => blockEditor.getBlocks( id ), [], 0 ).forEach( ( id ) => {
				out[ SYNCED + id ] = blockEditor.getBlocks( id );
			} );
			return out;
		}, [] );
		return useMemo( () => {
			try {
				const blocks = expand( selected.blocks, selected, 0 );
				return {
					issues: core.audit( blocks, { titleIsH1: titleIsH1( selected.postType, selected.template ) } ),
					decorative: core.decoratives( blocks ),
					error: '',
				};
			} catch ( e ) {
				return { issues: [], decorative: [], error: __( 'Blocks are nested too deeply to be checked.', 'fluffless-suite' ) };
			}
		}, [ selected ] );
	}

	function summary( count ) {
		return 0 === count
			? __( 'Nothing to check: headings, alternative text and link text look right.', 'fluffless-suite' )
			/* translators: %s: number of items to check */
			: sprintf( _n( '%s thing to check.', '%s things to check.', count, 'fluffless-suite' ), count );
	}

	/** Clé stable d'un problème : le bouton suivant ne prend pas la place de celui qu'on vient d'utiliser. */
	function keys( issues ) {
		const seen = {};
		return issues.map( ( issue ) => {
			const base = issue.type + ':' + issue.clientId;
			seen[ base ] = ( seen[ base ] || 0 ) + 1;
			return base + ':' + seen[ base ];
		} );
	}

	function IssueList( { issues, decorative, onChange } ) {
		const { selectBlock, updateBlockAttributes } = useDispatch( 'core/block-editor' );
		const getAttributes = useSelect( ( select ) => select( 'core/block-editor' ).getBlockAttributes, [] );
		const setDecorative = ( clientId, on ) => {
			const metadata = { ...( ( getAttributes( clientId ) || {} ).metadata || {} ) };
			if ( on ) {
				metadata[ core.DECORATIVE ] = true;
			} else {
				delete metadata[ core.DECORATIVE ];
			}
			updateBlockAttributes( clientId, { metadata } );
			onChange( on ? __( 'Image marked as decorative.', 'fluffless-suite' ) : __( 'Image no longer marked as decorative.', 'fluffless-suite' ) );
		};
		const children = [];
		GROUPS.forEach( ( [ key, title ] ) => {
			const items = issues.filter( ( issue ) => core.group( issue.type ) === key );
			if ( ! items.length ) {
				return;
			}
			const itemKeys = keys( items );
			children.push( el( 'h3', { key: key + '-title', style: GROUP_TITLE }, title() ) );
			children.push(
				el( 'ul', { key },
					items.map( ( issue, i ) => el( 'li', { key: itemKeys[ i ], style: { marginBottom: '8px' } },
						issue.clientId
							? el( Button, { variant: 'link', onClick: () => selectBlock( issue.clientId ) }, message( issue ) )
							: message( issue ),
						issue.canMarkDecorative && el( 'div', { style: { marginTop: '4px' } },
							el( Button, { variant: 'secondary', size: 'small', onClick: () => setDecorative( issue.clientId, true ) },
								__( 'Mark as decorative', 'fluffless-suite' )
							)
						)
					) )
				)
			);
		} );
		if ( decorative.length ) {
			children.push( el( 'h3', { key: 'decorative-title', style: GROUP_TITLE }, __( 'Marked as decorative', 'fluffless-suite' ) ) );
			children.push(
				el( 'ul', { key: 'decorative' },
					decorative.map( ( image ) => el( 'li', { key: image.clientId, style: { marginBottom: '8px' } },
						el( Button, { variant: 'link', onClick: () => selectBlock( image.clientId ) }, image.file || __( 'Image', 'fluffless-suite' ) ),
						' ',
						el( Button, { variant: 'secondary', size: 'small', onClick: () => setDecorative( image.clientId, false ) },
							__( 'Unmark', 'fluffless-suite' )
						)
					) )
				)
			);
		}
		return el( 'div', null, children );
	}

	function Body( { result } ) {
		const { issues, decorative, error } = result;
		const summaryRef = useRef( null );
		// Après un marquage, la ligne disparaît : le focus revient au sommaire et le changement est annoncé.
		const onChange = ( text ) => {
			speak( text );
			if ( summaryRef.current ) {
				summaryRef.current.focus();
			}
		};
		return el( 'div', null,
			error && el( Notice, { status: 'error', isDismissible: false }, error ),
			el( 'p', { ref: summaryRef, tabIndex: -1 }, summary( issues.length ) ),
			( issues.length > 0 || decorative.length > 0 ) && el( IssueList, { issues, decorative, onChange } )
		);
	}

	function QualityBody() {
		return el( Body, { result: useIssues() } );
	}

	function QualityPanel() {
		return el( PluginDocumentSettingPanel, { name: 'fluffless-quality', title: __( 'Content quality', 'fluffless-suite' ) }, el( QualityBody ) );
	}

	function PrePublishBody() {
		const result = useIssues();
		if ( ! result.issues.length ) {
			return null;
		}
		return el( PluginPrePublishPanel, { title: __( 'Content quality', 'fluffless-suite' ), initialOpen: true }, el( Body, { result } ) );
	}

	// La relecture ne tourne pour la publication que si son volet est ouvert.
	function QualityPrePublish() {
		const opened = useSelect( ( select ) => select( 'core/editor' ).isPublishSidebarOpened(), [] );
		return opened ? el( PrePublishBody ) : null;
	}

	registerPlugin( 'fluffless-quality', {
		render: () => el( wp.element.Fragment, null, el( QualityPanel ), PluginPrePublishPanel && el( QualityPrePublish ) ),
	} );
} )( window.wp, window.flQualityCore );
