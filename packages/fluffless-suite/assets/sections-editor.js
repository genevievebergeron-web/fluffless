/**
 * Aides communes aux éditeurs des blocs modulables (src/Blocks/Sections.php). JavaScript natif,
 * sans étape de build ; chargé dans l'éditeur seulement, jamais sur le site.
 * Les interrupteurs de structure ajoutent ou retirent des blocs natifs dans le conteneur ; ceux de
 * présentation posent des classes, les mêmes que Sections::classes() côté serveur.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { createBlock } = wp.blocks;
	const { __ } = wp.i18n;
	const { select, dispatch, useSelect } = wp.data;
	const { useBlockProps, useInnerBlocksProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, ToggleControl } = wp.components;
	const Group = wp.components.ToggleGroupControl || wp.components.__experimentalToggleGroupControl;
	const Option = wp.components.ToggleGroupControlOption || wp.components.__experimentalToggleGroupControlOption;
	const STORE = 'core/block-editor';
	const NONE = [];
	// Borne du nombre d'enfants qu'un interrupteur peut créer (4 colonnes au plus aujourd'hui).
	const MAX_CHILDREN = 12;

	// Choix exclusif court : boutons côte à côte, ou liste déroulante si le composant manque.
	const choice = ( label, value, options, onChange, help ) =>
		Group && Option
			? el(
					Group,
					{ label, help, value, onChange, isBlock: true, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true },
					options.map( ( o ) => el( Option, { key: o.value, value: o.value, label: o.label } ) )
			  )
			: el( SelectControl, { label, help, value, options, onChange, __nextHasNoMarginBottom: true } );

	const toggle = ( label, checked, onChange, help ) =>
		el( ToggleControl, { label, help, checked, onChange, __nextHasNoMarginBottom: true } );

	const isBlock = ( name ) => ( block ) => block.name === name;
	const hasClass = ( name ) => ( block ) => ( ' ' + ( block.attributes.className || '' ) + ' ' ).includes( ' ' + name + ' ' );

	// Enfants directs d'un bloc : réactif (hook) ou lu sur le moment (dans un gestionnaire).
	const useChildren = ( id ) => useSelect( ( s ) => ( id ? s( STORE ).getBlocks( id ) : NONE ), [ id ] );
	const children = ( id ) => ( id ? select( STORE ).getBlocks( id ) : NONE );

	/**
	 * Présence d'un enfant : l'ajoute (make() crée le bloc, à index, sinon à la fin) ou le retire.
	 * Retirer supprime le contenu de ce bloc : c'est le sens de « non ».
	 */
	const setChild = ( parentId, test, present, make, index ) => {
		if ( ! parentId ) {
			return;
		}
		const found = children( parentId ).find( test );
		if ( present && ! found ) {
			dispatch( STORE ).insertBlock( make(), index, parentId, false );
		} else if ( ! present && found ) {
			dispatch( STORE ).removeBlock( found.clientId, false );
		}
	};

	/** Nombre d'enfants : complète avec make( rang ) ou retire les derniers. */
	const setCount = ( parentId, count, make ) => {
		if ( ! parentId ) {
			return;
		}
		const n = Math.max( 1, Math.min( MAX_CHILDREN, count ) );
		const list = children( parentId );
		if ( list.length < n ) {
			const added = Array.from( { length: n - list.length }, ( _, i ) => make( list.length + i ) );
			dispatch( STORE ).insertBlocks( added, list.length, parentId, false );
		} else if ( list.length > n ) {
			dispatch( STORE ).removeBlocks( list.slice( n ).map( ( b ) => b.clientId ), false );
		}
	};

	// Bouton natif ; à partir du deuxième, style contour pour garder une seule action principale.
	const button = ( rank, placeholder ) =>
		createBlock( 'core/button', rank > 0 ? { placeholder, className: 'is-style-outline' } : { placeholder } );
	const buttons = ( count, placeholder ) =>
		createBlock( 'core/buttons', {}, Array.from( { length: count }, ( _, i ) => button( i, placeholder ) ) );

	/**
	 * Enveloppe d'éditeur commune : un panneau de réglages et le conteneur de blocs natifs.
	 * options : keys (réglages rendus en classes), template, title, controls, before, after.
	 */
	const shell = ( slug, props, options ) => {
		const classes = [ 'fluffless-section', 'fluffless-' + slug ].concat(
			( options.keys || NONE ).map( ( key ) => 'is-' + key + '-' + props.attributes[ key ] )
		);
		const inner = useInnerBlocksProps( useBlockProps( { className: classes.join( ' ' ) } ), { template: options.template } );
		return el(
			wp.element.Fragment,
			null,
			el( InspectorControls, null, el( PanelBody, { title: options.title }, ...options.controls ) ),
			el( 'div', inner, options.before || null, inner.children, options.after || null )
		);
	};

	const save = () => el( wp.blockEditor.InnerBlocks.Content );

	/*
	 * Deux variations du Groupe natif, pour nommer sans nouveau bloc ni rendu serveur :
	 * Section (balise section, largeur contrainte) et En-tête de section (sur-titre, titre, texte).
	 * Reconnue par sa classe (jeton entier, où qu'il soit dans className) : tout ce qui est dans le
	 * contenu reste un Groupe pour le site et pour l'extraction.
	 */
	const hasToken = ( attributes, variationAttributes ) =>
		( ' ' + ( attributes.className || '' ) + ' ' ).indexOf( ' ' + variationAttributes.className + ' ' ) !== -1;
	const SCOPE = [ 'inserter', 'block', 'transform' ];
	wp.blocks.registerBlockVariation( 'core/group', {
		name: 'fluffless-conteneur',
		title: __( 'Section', 'fluffless-suite' ),
		description: __( 'A section of the page: a landmark with constrained width, ready for a section header and content.', 'fluffless-suite' ),
		icon: 'editor-insertmore',
		scope: SCOPE,
		attributes: { tagName: 'section', className: 'fluffless-conteneur', layout: { type: 'constrained' } },
		isActive: hasToken,
	} );
	wp.blocks.registerBlockVariation( 'core/group', {
		name: 'fluffless-en-tete',
		title: __( 'Section header', 'fluffless-suite' ),
		description: __( 'An overline, a section title and an introduction paragraph.', 'fluffless-suite' ),
		icon: 'heading',
		scope: SCOPE,
		attributes: { className: 'fluffless-en-tete', layout: { type: 'constrained' } },
		innerBlocks: [
			[ 'core/paragraph', { fontSize: 'small', textColor: 'muted', placeholder: __( 'Overline', 'fluffless-suite' ) } ],
			[ 'core/heading', { level: 2, placeholder: __( 'Section title', 'fluffless-suite' ) } ],
			[ 'core/paragraph', { placeholder: __( 'One or two sentences introducing the section.', 'fluffless-suite' ) } ],
		],
		isActive: hasToken,
	} );

	window.flufflessSections = { el, createBlock, choice, toggle, isBlock, hasClass, useChildren, children, setChild, setCount, button, buttons, shell, save };
} )( window.wp );
