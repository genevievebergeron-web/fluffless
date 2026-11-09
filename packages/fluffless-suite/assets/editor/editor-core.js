/* Éditeur de pages Fluffless : cœur sans DOM ni wp, partagé par l'éditeur et ses tests (Node).
   Les blocs sont de simples objets { name, attributes, innerBlocks } ; l'éditeur passe
   wp.blocks.cloneBlock comme fabrique pour obtenir de vrais blocs. */
( function ( root, factory ) {
	const api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.flEditorCore = api;
	}
} )( typeof window !== 'undefined' ? window : globalThis, function () {
	'use strict';

	// Aucune composition n'imbrique autant : au-delà, c'est une boucle.
	const MAX_DEPTH = 50;
	// Blocs dont le texte vit dans l'attribut content (RichText).
	const TEXT_BLOCKS = [ 'core/heading', 'core/paragraph' ];
	// Sections verrouillables : conteneurs de premier niveau.
	const SECTIONS = [ 'core/group', 'core/cover', 'core/columns', 'core/media-text' ];

	const plain = ( block, attributes, innerBlocks ) => ( { ...block, attributes, innerBlocks } );

	function escapeHtml( text ) {
		return String( text ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	/** Texte saisi en HTML de RichText : échappé, retours de ligne en <br>. */
	function toRichText( text ) {
		return escapeHtml( String( text ).trim() ).replace( /\r?\n/g, '<br>' );
	}

	/**
	 * Remplit les blocs repérés par metadata.name avec les réponses (nom => texte).
	 * Une réponse vide ou absente laisse la consigne en place. Rend un nouvel arbre.
	 */
	function fill( blocks, answers, make = plain, depth = 0 ) {
		if ( depth > MAX_DEPTH ) {
			throw new Error( 'fill : imbrication de plus de ' + MAX_DEPTH + ' niveaux' );
		}
		return blocks.map( ( block ) => {
			const attrs = block.attributes || {};
			const name = attrs.metadata && attrs.metadata.name;
			const answer = name ? answers[ name ] : undefined;
			const next = 'string' === typeof answer && answer.trim() && TEXT_BLOCKS.includes( block.name ) ? { ...attrs, content: toRichText( answer ) } : attrs;
			return make( block, next, fill( block.innerBlocks || [], answers, make, depth + 1 ) );
		} );
	}

	/** Noms metadata.name présents dans l'arbre, dans l'ordre de lecture. */
	function names( blocks, out = [], depth = 0 ) {
		if ( depth > MAX_DEPTH ) {
			throw new Error( 'names : imbrication de plus de ' + MAX_DEPTH + ' niveaux' );
		}
		for ( const block of blocks ) {
			const name = block.attributes && block.attributes.metadata && block.attributes.metadata.name;
			if ( name ) {
				out.push( name );
			}
			names( block.innerBlocks || [], out, depth + 1 );
		}
		return out;
	}

	/** Sections de premier niveau verrouillées en contentOnly (mode Simple). Le reste est intact. */
	function lockSections( blocks, make = plain ) {
		return blocks.map( ( block ) => ( SECTIONS.includes( block.name ) ? make( block, { ...block.attributes, templateLock: 'contentOnly' }, block.innerBlocks || [] ) : block ) );
	}

	/** Retire le verrou contentOnly des sections de premier niveau (mode Complet). Les autres verrous restent. */
	function unlockSections( blocks, make = plain ) {
		return blocks.map( ( block ) => {
			if ( ! block.attributes || 'contentOnly' !== block.attributes.templateLock ) {
				return block;
			}
			const { templateLock, ...rest } = block.attributes;
			return make( block, rest, block.innerBlocks || [] );
		} );
	}

	// Texte visible par bloc, pour la relecture : attribut qui le porte.
	const REVIEW_TEXT = { 'core/heading': 'content', 'core/paragraph': 'content', 'core/list-item': 'content', 'core/button': 'text', 'core/quote': 'citation', 'core/details': 'summary' };

	/** Texte brut d'un attribut RichText (chaîne ou RichTextData) : sans balises, espaces réduits. */
	function plainText( value ) {
		return String( null == value ? '' : value ).replace( /<[^>]*>/g, ' ' ).replace( /&nbsp;|&#160;/g, ' ' ).replace( /&amp;/g, '&' ).replace( /\s+/g, ' ' ).trim();
	}

	/** Textes visibles d'un bloc pour la relecture : l'attribut de REVIEW_TEXT, ou chaque cellule d'un tableau. */
	function visibleTexts( block ) {
		const attrs = block.attributes || {};
		if ( 'core/table' === block.name ) {
			return [ 'head', 'body', 'foot' ]
				.flatMap( ( part ) => ( Array.isArray( attrs[ part ] ) ? attrs[ part ] : [] ) )
				.flatMap( ( row ) => ( row && Array.isArray( row.cells ) ? row.cells : [] ) )
				.map( ( cell ) => plainText( cell && cell.content ) )
				.filter( Boolean );
		}
		const key = REVIEW_TEXT[ block.name ];
		const text = key ? plainText( attrs[ key ] ) : '';
		return text ? [ text ] : [];
	}

	/** Valeurs d'exemple des compositions : tout texte visible, sans seuil de longueur (prix, chiffres, signatures). */
	function placeholders( blocks, out = new Set(), depth = 0 ) {
		if ( depth > MAX_DEPTH ) {
			throw new Error( 'placeholders : imbrication de plus de ' + MAX_DEPTH + ' niveaux' );
		}
		for ( const block of blocks ) {
			visibleTexts( block ).forEach( ( text ) => out.add( text ) );
			placeholders( block.innerBlocks || [], out, depth + 1 );
		}
		return out;
	}

	/** Chemin interne d'un lien (« contact » pour https://site/contact/), '' pour l'accueil, null si externe. */
	function internalSlug( url, home ) {
		const base = String( home ).replace( /\/+$/, '' ) + '/';
		if ( ! url.startsWith( base ) && url + '/' !== base ) {
			return null;
		}
		const path = url.slice( base.length ).split( /[?#]/ )[ 0 ].replace( /\/+$/, '' );
		return path.split( '/' ).pop();
	}

	/** Page visée par un bouton de composition (classe repère `fluffless-page-<chemin>`), dernier segment du chemin, ou null. */
	function markedSlug( className ) {
		const found = /(?:^|\s)fluffless-page-([a-z0-9_-]+)(?:\s|$)/i.exec( String( className || '' ) );
		return found ? found[ 1 ].split( '--' ).pop() : null;
	}

	/** Adresse d'origine d'un bouton de composition : vide, « # » ou un domaine d'exemple (reliée au rendu par le thème). */
	function isStarterUrl( url ) {
		return '' === url || '#' === url || /^(?:https?:)?\/\/(?:[a-z0-9-]+\.)*exemple\.ca(?:[/?#:]|$)/i.test( url );
	}

	// Rappel laissé par la migration dans un bloc HTML : commentaire invisible pour les visiteurs, mais à traiter avant de publier.
	const REMINDER = /^\s*<!--\s*fluffless-todo\s*:\s*([\s\S]*?)\s*-->\s*$/;

	/**
	 * Ce qui reste à faire avant de publier : consignes intactes, boutons sans lien ou vers
	 * une page interne à vérifier (slug), rappels de migration. Chaque entrée garde le clientId pour aller au bloc.
	 */
	function review( blocks, known, home, out = { texts: [], links: [], reminders: [] }, depth = 0 ) {
		if ( depth > MAX_DEPTH ) {
			throw new Error( 'review : imbrication de plus de ' + MAX_DEPTH + ' niveaux' );
		}
		for ( const block of blocks ) {
			const attrs = block.attributes || {};
			const key = REVIEW_TEXT[ block.name ];
			const text = key ? plainText( attrs[ key ] ) : '';
			visibleTexts( block ).filter( ( value ) => known.has( value ) ).forEach( ( value ) => out.texts.push( { clientId: block.clientId, text: value } ) );
			if ( 'core/html' === block.name ) {
				const reminder = REMINDER.exec( String( attrs.content || block.originalContent || '' ) );
				if ( reminder ) {
					out.reminders.push( { clientId: block.clientId, text: reminder[ 1 ] } );
				}
			}
			if ( 'core/button' === block.name ) {
				const url = String( attrs.url || '' ).trim();
				const marked = markedSlug( attrs.className );
				// Bouton de composition encore à son adresse d'origine : la page visée décide, pas l'adresse.
				const slug = marked && isStarterUrl( url ) ? marked : ( url && '#' !== url ? internalSlug( url, home ) : undefined );
				if ( undefined === slug || ( slug !== null && slug !== '' ) ) {
					out.links.push( { clientId: block.clientId, text, url, slug: slug === undefined ? null : slug } );
				}
			}
			review( block.innerBlocks || [], known, home, out, depth + 1 );
		}
		return out;
	}

	return { fill, names, lockSections, unlockSections, placeholders, plainText, review, internalSlug, markedSlug, toRichText, MAX_DEPTH };
} );
