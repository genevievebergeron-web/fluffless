/**
 * Analyse locale d'un contenu pour un mot-clé : fonctions pures, sans réseau ni IA.
 * Exposée sur window.flufflessSeoAnalysis ; testée dans Node (tests/seo-analysis-test.mjs).
 *
 * Largeurs en pixels : Google coupe les titres vers 580 px (Arial 20 px) et les
 * descriptions vers 920 px (Arial 14 px) sur ordinateur. Ce sont des repères, pas des règles.
 */
( function ( root ) {
	const TITLE = { size: 20, max: 580, min: 200 };
	const DESC = { size: 14, max: 920, min: 400 };
	const MIN_WORDS = 300;
	const MAX_DENSITY = 3;

	// Largeur moyenne des glyphes d'Arial, en fraction de la taille : repli quand canvas manque.
	const NARROW = /[ijlI.,;:'|!tfr() ]/;
	const WIDE = /[mwMW@%]/;

	let canvas = null;

	/** Largeur approximative d'un texte en pixels, en Arial. */
	function textWidth( text, size ) {
		const doc = root.document;
		if ( doc && typeof doc.createElement === 'function' ) {
			canvas = canvas || doc.createElement( 'canvas' );
			const context = canvas.getContext ? canvas.getContext( '2d' ) : null;
			if ( context ) {
				context.font = size + 'px Arial, sans-serif';
				return Math.round( context.measureText( text ).width );
			}
		}
		let units = 0;
		for ( const char of String( text ) ) {
			if ( NARROW.test( char ) ) {
				units += 0.28;
			} else if ( WIDE.test( char ) ) {
				units += 0.83;
			} else if ( char !== char.toLowerCase() || /\d/.test( char ) ) {
				units += 0.64;
			} else {
				units += 0.5;
			}
		}
		return Math.round( units * size );
	}

	/** Minuscules, sans accents ni espaces superflues : « Écoconception » = « ecoconception ». */
	function normalize( text ) {
		return String( text || '' )
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase()
			.replace( /\s+/g, ' ' )
			.trim();
	}

	/** Forme d'adresse d'un texte. */
	function slugify( text ) {
		return normalize( text ).replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );
	}

	/** Le texte contient-il le mot-clé, en mots entiers ? */
	function contains( text, keyword ) {
		const needle = normalize( keyword );
		if ( ! needle ) {
			return false;
		}
		const escaped = needle.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
		return new RegExp( '(^|[^a-z0-9])' + escaped + '($|[^a-z0-9])' ).test( normalize( text ) );
	}

	/** Occurrences du mot-clé dans un texte. */
	function count( text, keyword ) {
		const needle = normalize( keyword );
		if ( ! needle ) {
			return 0;
		}
		return normalize( text ).split( needle ).length - 1;
	}

	/** Statut d'une vérification : good, warn ou bad. */
	function check( id, status, data ) {
		return { id, status, data: data || {} };
	}

	/**
	 * Analyse complète.
	 *
	 * @param {Object} input       keyword, title, description, slug, html, home.
	 * @param {Object} [options]   parse(html) => Document (par défaut, DOMParser du navigateur).
	 * @return {{checks: Array, words: number, titleWidth: number, descWidth: number}}
	 */
	function analyse( input, options ) {
		const parse =
			( options && options.parse ) ||
			( ( html ) => new root.DOMParser().parseFromString( html, 'text/html' ) );
		const doc = parse( '<body>' + ( input.html || '' ) + '</body>' );
		const body = doc.body;
		const text = ( body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
		const words = text ? text.split( ' ' ).length : 0;
		const keyword = String( input.keyword || '' ).trim();
		const title = String( input.title || '' );
		const description = String( input.description || '' );
		const titleWidth = textWidth( title, TITLE.size );
		const descWidth = textWidth( description, DESC.size );
		const checks = [];

		checks.push( check( 'title_length', ! title ? 'bad' : titleWidth > TITLE.max ? 'bad' : titleWidth < TITLE.min ? 'warn' : 'good', { width: titleWidth, max: TITLE.max } ) );
		checks.push( check( 'desc_length', ! description ? 'bad' : descWidth > DESC.max || descWidth < DESC.min ? 'warn' : 'good', { width: descWidth, max: DESC.max } ) );
		checks.push( check( 'content_length', words >= MIN_WORDS ? 'good' : 'warn', { words, min: MIN_WORDS } ) );

		// Titres : pas de H1 dans le contenu quand le gabarit affiche le titre en H1 (cas par défaut),
		// exactement un quand il ne l'affiche pas (titleIsH1 false) ; pas de niveau sauté.
		const titleIsH1 = false !== input.titleIsH1;
		const headings = Array.from( body.querySelectorAll( 'h1,h2,h3,h4,h5,h6' ) ).map( ( h ) => ( {
			level: Number( h.tagName.slice( 1 ) ),
			text: h.textContent || '',
		} ) );
		let skipped = false;
		let previous = 1;
		for ( const heading of headings ) {
			if ( heading.level > previous + 1 ) {
				skipped = true;
			}
			previous = heading.level;
		}
		const h1s = headings.filter( ( h ) => h.level === 1 ).length;
		const hasH1 = h1s > 0;
		const hasH2 = headings.some( ( h ) => h.level === 2 );
		const h1Wrong = titleIsH1 ? hasH1 : 1 !== h1s;
		checks.push( check( 'headings', h1Wrong || skipped ? 'bad' : ! hasH2 && words >= MIN_WORDS ? 'warn' : 'good', { hasH1, h1s, titleIsH1, skipped, hasH2 } ) );

		// Liens : internes et externes, d'après l'hôte du site.
		let host = '';
		try {
			host = new URL( input.home || '' ).host;
		} catch ( e ) {
			host = '';
		}
		let internal = 0;
		let external = 0;
		for ( const link of body.querySelectorAll( 'a[href]' ) ) {
			const href = link.getAttribute( 'href' ) || '';
			if ( /^(mailto:|tel:|#)/i.test( href ) ) {
				continue;
			}
			if ( /^https?:\/\//i.test( href ) ) {
				let linkHost = '';
				try {
					linkHost = new URL( href ).host;
				} catch ( e ) {
					linkHost = '';
				}
				if ( host && linkHost === host ) {
					internal++;
				} else {
					external++;
				}
			} else {
				internal++;
			}
		}
		checks.push( check( 'links_internal', internal > 0 ? 'good' : 'warn', { count: internal } ) );
		checks.push( check( 'links_external', external > 0 ? 'good' : 'warn', { count: external } ) );

		if ( keyword ) {
			const paragraphs = Array.from( body.querySelectorAll( 'p' ) ).map( ( p ) => p.textContent || '' ).filter( ( p ) => p.trim() );
			const intro = paragraphs.length ? paragraphs[ 0 ] : text.split( ' ' ).slice( 0, 100 ).join( ' ' );
			const alts = Array.from( body.querySelectorAll( 'img' ) ).map( ( img ) => img.getAttribute( 'alt' ) || '' );
			const occurrences = count( text, keyword );
			const density = words ? Math.round( ( occurrences * keyword.split( /\s+/ ).length * 1000 ) / words ) / 10 : 0;
			const slug = slugify( keyword );

			checks.push( check( 'keyword_title', contains( title, keyword ) ? 'good' : 'bad' ) );
			checks.push( check( 'keyword_desc', contains( description, keyword ) ? 'good' : 'warn' ) );
			checks.push( check( 'keyword_intro', contains( intro, keyword ) ? 'good' : 'warn' ) );
			checks.push( check( 'keyword_h2', headings.some( ( h ) => h.level === 2 && contains( h.text, keyword ) ) ? 'good' : 'warn' ) );
			checks.push( check( 'keyword_url', slug && String( input.slug || '' ).includes( slug ) ? 'good' : 'warn' ) );
			checks.push( check( 'keyword_alt', alts.some( ( alt ) => contains( alt, keyword ) ) ? 'good' : alts.length ? 'warn' : 'warn', { images: alts.length } ) );
			checks.push( check( 'keyword_density', occurrences === 0 ? 'bad' : density > MAX_DENSITY ? 'warn' : 'good', { density, occurrences } ) );
		}

		return { checks, words, titleWidth, descWidth };
	}

	/** Coupe au dernier mot entier avant la limite. */
	function shorten( text, limit ) {
		const clean = String( text || '' ).replace( /\s+/g, ' ' ).trim();
		if ( clean.length <= limit ) {
			return clean;
		}
		const cut = clean.slice( 0, limit - 1 );
		const space = cut.lastIndexOf( ' ' );
		return ( space > 0 ? cut.slice( 0, space ) : cut ).replace( /[\s,;:.-]+$/, '' ) + '…';
	}

	/**
	 * Remplace les variables connues du navigateur ; les autres disparaissent, comme au rendu serveur.
	 *
	 * @param {string} template Gabarit.
	 * @param {Object} values   nom => valeur.
	 * @param {string} sep      Séparateur.
	 */
	function fillTemplate( template, values, sep ) {
		const text = String( template || '' )
			.replace( /%%([a-z_]{1,30})%%/g, ( match, name ) => ( values[ name ] !== undefined ? String( values[ name ] ) : '' ) )
			.replace( /\s+/g, ' ' );
		const quoted = String( sep || '|' ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
		return text
			.replace( new RegExp( '(\\s*' + quoted + '\\s*){2,}', 'g' ), ' ' + sep + ' ' )
			.replace( new RegExp( '^\\s*' + quoted + '\\s*|\\s*' + quoted + '\\s*$', 'g' ), '' )
			.trim();
	}

	root.flufflessSeoAnalysis = { analyse, textWidth, normalize, slugify, contains, shorten, fillTemplate, TITLE, DESC };
} )( typeof window !== 'undefined' ? window : globalThis );
