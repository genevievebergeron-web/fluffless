/* Qualité du contenu : cœur sans DOM ni wp, partagé par le panneau de l'éditeur et ses tests (Node).
   Lit l'arbre des blocs ({ name, attributes, innerBlocks, clientId }) et relève trois familles :
   titres (ordre, H1, vides), textes de remplacement des images, textes de lien vagues.
   Rien ne quitte le navigateur : listes de mots et règles sont ici. */
( function ( root, factory ) {
	const api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.flQualityCore = api;
	}
} )( typeof window !== 'undefined' ? window : globalThis, function () {
	'use strict';

	// Aucune page n'imbrique autant : au-delà, c'est une boucle.
	const MAX_DEPTH = 50;
	// Profondeur des attributs fouillés pour les liens (cellules de tableau : body[].cells[].content).
	const MAX_ATTR_DEPTH = 6;
	// Clé posée dans metadata quand une image est marquée décorative depuis le panneau.
	const DECORATIVE = 'flufflessDecorative';

	// Textes de lien qui ne disent pas où ils mènent, comparés après normalize().
	const VAGUE = new Set( [
		// Français.
		'ici', 'cliquez ici', 'clique ici', 'cliquer ici', 'en cliquant ici', 'cliquez', 'clique', 'cliquer',
		'lien', 'ce lien', 'le lien', 'en savoir plus', 'pour en savoir plus', 'en savoir davantage',
		'lire la suite', 'lire plus', 'la suite', 'suite', 'voir plus', 'voir', 'plus',
		'plus d\'info', 'plus d\'infos', 'plus d\'information', 'plus d\'informations', 'détails',
		'plus de détails', 'découvrir', 'découvrez', 'continuer', 'continuer la lecture', 'cette page',
		// Anglais.
		'here', 'click here', 'click', 'this link', 'link', 'read more', 'learn more', 'more', 'more info',
		'more information', 'details', 'see more', 'continue', 'continue reading', 'find out more', 'go',
		'this', 'this page',
	] );

	// Textes de remplacement qui ne décrivent rien.
	const WEAK_ALT = new Set( [
		'image', 'photo', 'picture', 'img', 'illustration', 'graphic', 'graphique', 'icon', 'icône',
		'untitled', 'sans titre', 'logo', 'banner', 'bannière', 'placeholder',
	] );
	const FILE_NAME = /\.(jpe?g|png|gif|webp|avif|svg|heic|tiff?)$/i;
	// Nom donné par l'appareil ou la capture, sans rien d'autre : « IMG_2041 », « PXL_20260101_1234 ».
	const CAMERA_NAME = /^(img|dsc|dscn|pxl|screenshot|capture|photo|image)[\s_-]*\d+(?:[\s_.-]+\d+)*$/i;
	const ADDRESS = /^(https?:\/\/|www\.)\S+$/i;

	const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: '\'', nbsp: ' ', rsquo: '’', lsquo: '‘', hellip: '…', laquo: '«', raquo: '»' };

	function decode( text ) {
		return text.replace( /&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, ( whole, code ) => {
			if ( '#' === code[ 0 ] ) {
				const n = 'x' === code[ 1 ].toLowerCase() ? parseInt( code.slice( 2 ), 16 ) : parseInt( code.slice( 1 ), 10 );
				return n > 0 && n < 0x110000 ? String.fromCodePoint( n ) : whole;
			}
			const named = ENTITIES[ code.toLowerCase() ];
			return undefined === named ? whole : named;
		} );
	}

	/** Valeur RichText (chaîne ou RichTextData) en texte brut. */
	function plainText( value ) {
		return decode( String( null == value ? '' : value ).replace( /<[^>]*>/g, ' ' ) ).replace( /[\s\u00a0\u202f]+/g, ' ' ).trim();
	}

	/** Forme comparable d'un texte de lien : minuscules, apostrophe droite, ponctuation de bord retirée. */
	function normalize( text ) {
		return text
			.toLowerCase()
			.replace( /[’‘`]/g, '\'' )
			.replace( /^[\s"'«»“”(\[]+|[\s.…:;,!?»«"'”)\]→›>\-–]+$/g, '' )
			.replace( /\s+/g, ' ' );
	}

	function isVague( text ) {
		return VAGUE.has( normalize( text ) );
	}

	function isWeakAlt( alt ) {
		const text = alt.trim();
		return FILE_NAME.test( text ) || CAMERA_NAME.test( text ) || WEAK_ALT.has( normalize( text ) );
	}

	/** Valeur d'un attribut HTML entre guillemets ; null s'il est absent. */
	function attributeOrNull( attrs, name ) {
		const match = new RegExp( '\\b' + name + '\\s*=\\s*("([^"]*)"|\'([^\']*)\')', 'i' ).exec( attrs );
		return match ? decode( undefined !== match[ 2 ] ? match[ 2 ] : match[ 3 ] ).trim() : null;
	}

	function attribute( attrs, name ) {
		return attributeOrNull( attrs, name ) || '';
	}

	/** Dernier segment d'une adresse d'image, pour la nommer dans un message. */
	function fileName( url ) {
		const path = String( url || '' ).split( /[?#]/ )[ 0 ];
		const last = path.slice( path.lastIndexOf( '/' ) + 1 );
		try {
			return decodeURIComponent( last );
		} catch ( e ) {
			return last;
		}
	}

	/** Nom accessible d'un lien de RichText : aria-label, sinon son texte, sinon le alt de ses images. */
	function linkName( attrs, inner ) {
		const label = attribute( attrs, 'aria-label' );
		if ( label ) {
			return label;
		}
		const text = plainText( inner );
		if ( text ) {
			return text;
		}
		const alts = [];
		inner.replace( /<img\b([^>]*)>/gi, ( whole, imgAttrs ) => {
			alts.push( attribute( imgAttrs, 'alt' ) );
			return whole;
		} );
		return alts.join( ' ' ).trim();
	}

	/** Juge un nom de lien ; renvoie le type de problème ou null. */
	function linkIssue( name ) {
		if ( '' === name ) {
			return 'link-empty';
		}
		if ( ADDRESS.test( name ) ) {
			return 'link-url';
		}
		return isVague( name ) ? 'link-vague' : null;
	}

	/** Liens <a> trouvés dans les attributs texte d'un bloc (contenu, légendes, cellules). */
	function richLinks( value, out, depth ) {
		if ( depth > MAX_ATTR_DEPTH || null == value ) {
			return out;
		}
		if ( Array.isArray( value ) ) {
			value.forEach( ( item ) => richLinks( item, out, depth + 1 ) );
			return out;
		}
		const isRich = 'object' === typeof value && 'function' === typeof value.toHTMLString;
		if ( 'object' === typeof value && ! isRich ) {
			Object.keys( value ).forEach( ( key ) => richLinks( value[ key ], out, depth + 1 ) );
			return out;
		}
		const html = isRich ? value.toHTMLString() : String( value );
		if ( html.indexOf( '<a' ) === -1 ) {
			return out;
		}
		const re = /<a\b([^>]*)>([\s\S]*?)<\/a>/gi;
		let match;
		while ( ( match = re.exec( html ) ) !== null ) {
			// Une ancre sans href (<a id="haut">, contenu migré) n'est pas un lien.
			if ( /\bhref\s*=/i.test( match[ 1 ] ) ) {
				out.push( linkName( match[ 1 ], match[ 2 ] ) );
			}
		}
		return out;
	}

	function isDecorative( attrs ) {
		return !! ( attrs.metadata && true === attrs.metadata[ DECORATIVE ] );
	}

	/**
	 * Titres et images écrits en HTML (blocs Classique et HTML personnalisé), dans l'ordre :
	 * { heading: niveau, text } ou { image: true, alt (null si l'attribut manque), file }.
	 */
	function htmlParts( html ) {
		const parts = [];
		const re = /<h([1-6])\b[^>]*>([\s\S]*?)<\/h\1\s*>|<img\b([^>]*)>/gi;
		let match;
		while ( ( match = re.exec( html ) ) !== null ) {
			if ( match[ 1 ] ) {
				parts.push( { heading: Number( match[ 1 ] ), text: plainText( match[ 2 ] ) } );
			} else {
				parts.push( { image: true, alt: attributeOrNull( match[ 3 ], 'alt' ), file: fileName( attribute( match[ 3 ], 'src' ) ) } );
			}
		}
		return parts;
	}

	/** Image d'un bloc : { alt, linked, decorative, file } ou null si le bloc n'en montre pas. */
	function imageOf( block ) {
		const attrs = block.attributes || {};
		switch ( block.name ) {
			case 'core/image':
				if ( ! attrs.url && ! attrs.id ) {
					return null;
				}
				return { alt: String( attrs.alt || '' ), linked: !! attrs.href, decorative: isDecorative( attrs ), file: fileName( attrs.url ) };
			case 'core/media-text':
				if ( 'image' !== ( attrs.mediaType || 'image' ) || ( ! attrs.mediaUrl && ! attrs.mediaId ) ) {
					return null;
				}
				return { alt: String( attrs.mediaAlt || '' ), linked: !! attrs.href, decorative: isDecorative( attrs ), file: fileName( attrs.mediaUrl ) };
			case 'fluffless/calque':
				if ( ! attrs.url ) {
					return null;
				}
				// Le calque a sa propre case « décoratif », cochée par défaut.
				return { alt: String( attrs.alt || '' ), linked: false, decorative: false !== attrs.decorative, file: fileName( attrs.url ) };
			default:
				return null;
		}
	}

	function walk( blocks, visit, depth ) {
		if ( depth > MAX_DEPTH ) {
			throw new Error( 'quality: blocks nested deeper than ' + MAX_DEPTH + ' levels' );
		}
		for ( const block of blocks || [] ) {
			visit( block );
			walk( block.innerBlocks, visit, depth + 1 );
		}
	}

	/**
	 * Relève les problèmes de la page, dans l'ordre du document.
	 *
	 * @param {Array}  blocks  Arbre des blocs.
	 * @param {Object} context { titleIsH1: true (le gabarit montre le titre en H1), false (il ne le montre pas), null (inconnu) }.
	 * @return {Array} Problèmes { type, clientId, text?, file?, level?, previous?, first? }.
	 */
	function audit( blocks, context ) {
		const titleIsH1 = context && 'boolean' === typeof context.titleIsH1 ? context.titleIsH1 : null;
		const issues = [];
		// Le plan part du titre de la page (H1) ; « first » tant qu'aucun titre du contenu n'est passé.
		let previous = 1;
		let first = true;
		let h1s = 0;

		const heading = ( level, text, id ) => {
			if ( '' === text ) {
				issues.push( { type: 'heading-empty', clientId: id, level } );
				return;
			}
			const shown = text.length > 60 ? text.slice( 0, 59 ) + '…' : text;
			if ( 1 === level ) {
				h1s++;
				if ( true === titleIsH1 ) {
					issues.push( { type: 'h1-duplicate', clientId: id, text: shown } );
				} else if ( false === titleIsH1 && h1s > 1 ) {
					issues.push( { type: 'h1-extra', clientId: id, text: shown } );
				}
			}
			if ( level > previous + 1 ) {
				issues.push( { type: 'heading-skip', clientId: id, level, previous, first, text: shown } );
			}
			previous = level;
			first = false;
		};

		const image = ( found, id, canMarkDecorative ) => {
			const alt = found.alt.trim();
			if ( '' === alt && found.linked ) {
				issues.push( { type: 'alt-linked', clientId: id, file: found.file } );
			} else if ( '' === alt && ! found.decorative ) {
				issues.push( { type: 'alt-missing', clientId: id, file: found.file, canMarkDecorative } );
			} else if ( '' !== alt && isWeakAlt( alt ) ) {
				issues.push( { type: 'alt-weak', clientId: id, text: alt } );
			}
		};

		walk( blocks, ( block ) => {
			const attrs = block.attributes || {};
			const id = block.clientId || null;

			if ( 'core/heading' === block.name ) {
				heading( Number( attrs.level ) || 2, plainText( attrs.content ), id );
			}
			if ( 'core/freeform' === block.name || 'core/html' === block.name ) {
				htmlParts( String( attrs.content || '' ) ).forEach( ( part ) => {
					if ( part.heading ) {
						heading( part.heading, part.text, id );
					} else if ( null === part.alt ) {
						// Sans attribut alt, le lecteur d'écran lit le nom du fichier ; alt="" vaut décoratif.
						image( { alt: '', linked: false, decorative: false, file: part.file }, id, false );
					} else {
						image( { alt: part.alt, linked: false, decorative: true, file: part.file }, id, false );
					}
				} );
			}

			const found = imageOf( block );
			if ( found ) {
				image( found, id, 'fluffless/calque' !== block.name );
			}

			const names = [];
			if ( 'core/button' === block.name ) {
				if ( String( attrs.url || '' ).trim() ) {
					names.push( plainText( attrs.text ) );
				}
			} else {
				Object.keys( attrs ).forEach( ( key ) => {
					if ( 'metadata' !== key ) {
						richLinks( attrs[ key ], names, 0 );
					}
				} );
			}
			names.forEach( ( name ) => {
				const type = linkIssue( name );
				if ( type ) {
					issues.push( { type, clientId: id, text: name } );
				}
			} );
		}, 0 );

		if ( false === titleIsH1 && 0 === h1s ) {
			issues.unshift( { type: 'h1-missing', clientId: null } );
		}
		return issues;
	}

	/** Images marquées décoratives depuis le panneau (sans texte de remplacement) : { clientId, file }. */
	function decoratives( blocks ) {
		const out = [];
		walk( blocks, ( block ) => {
			if ( 'core/image' !== block.name && 'core/media-text' !== block.name ) {
				return;
			}
			const found = imageOf( block );
			if ( found && found.decorative && '' === found.alt.trim() && ! found.linked ) {
				out.push( { clientId: block.clientId || null, file: found.file } );
			}
		}, 0 );
		return out;
	}

	/** Famille d'un problème, pour grouper l'affichage. */
	function group( type ) {
		if ( 'alt' === type.slice( 0, 3 ) ) {
			return 'images';
		}
		return 'link' === type.slice( 0, 4 ) ? 'links' : 'headings';
	}

	return { audit, decoratives, group, plainText, normalize, isVague, isWeakAlt, linkIssue, DECORATIVE, MAX_DEPTH };
} );
