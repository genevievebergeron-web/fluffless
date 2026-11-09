/* Fluffless Consent : aucun service non essentiel avant un oui. Choix en localStorage, durée bornée. */
( () => {
	const root = document.getElementById( 'fluffless-consent' );
	if ( ! root ) {
		return;
	}
	const cfg = JSON.parse( root.dataset.config );
	const boxes = [ ...root.querySelectorAll( 'input[data-category]' ) ];
	let opener = null;

	const read = () => {
		try {
			const saved = JSON.parse( localStorage.getItem( cfg.k ) );
			return saved && saved.v === cfg.v && Date.now() - saved.t < cfg.d * 864e5 ? saved.c : null;
		} catch {
			return null;
		}
	};

	// Iframe créée après un oui à « media ».
	const embeds = [ ...document.querySelectorAll( '[data-fluffless-embed]' ) ];
	const media = root.querySelector( 'input[data-category="media"]' );
	document.querySelectorAll( '[data-fluffless-consent-open][hidden]' ).forEach( ( b ) => ( b.hidden = ! media && !! b.closest( '.fluffless-embed' ) ) );

	const activate = () => {
		embeds.splice( 0 ).forEach( ( box ) => {
			try {
				const data = JSON.parse( box.dataset.flufflessEmbed );
				if ( ! /^https:\/\//i.test( data.s ) ) {
					return;
				}
				const frame = document.createElement( 'iframe' );
				Object.entries( data.a || {} ).forEach( ( [ k, v ] ) => /^(width|height|title|allow(fullscreen)?|loading|referrerpolicy|sandbox|name)$/.test( k ) && frame.setAttribute( k, v ) );
				frame.src = data.s;
				box.replaceChildren( frame );
			} catch {}
		} );
	};

	const inject = ( src ) => {
		const tag = document.createElement( 'script' );
		tag.src = src;
		tag.async = true;
		document.head.append( tag );
	};

	const load = ( allowed ) => {
		cfg.s.forEach( ( s ) => {
			if ( ! allowed[ s.c ] || s.done ) {
				return;
			}
			s.done = true;
			if ( s.ga ) {
				window.dataLayer = window.dataLayer || [];
				window.gtag = function () {
					window.dataLayer.push( arguments );
				};
				window.gtag( 'js', new Date() );
				window.gtag( 'config', s.ga );
				inject( 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent( s.ga ) );
			}
			if ( s.src ) {
				inject( s.src );
			}
		} );
		document.querySelectorAll( 'script[type="text/plain"][data-fluffless-consent]' ).forEach( ( inert ) => {
			if ( allowed[ inert.dataset.flufflessConsent ] ) {
				const tag = document.createElement( 'script' );
				[ ...inert.attributes ].forEach( ( a ) => a.name !== 'type' && tag.setAttribute( a.name, a.value ) );
				tag.text = inert.text;
				inert.replaceWith( tag );
			}
		} );
		if ( allowed.media ) {
			activate();
		}
		document.dispatchEvent( new CustomEvent( 'fluffless:consent', { detail: allowed } ) );
	};

	// Google réécrit _ga_<ID> en quittant : on purge à chaque chargement.
	const purge = () => {
		const parts = location.hostname.split( '.' );
		document.cookie.split( ';' ).forEach( ( c ) => {
			const name = c.split( '=' )[ 0 ].trim();
			if ( /^_g(a|id|at)(_|$)/.test( name ) ) {
				parts.forEach( ( _, i ) => {
					document.cookie = name + '=;Max-Age=0;path=/;domain=.' + parts.slice( i ).join( '.' );
				} );
				document.cookie = name + '=;Max-Age=0;path=/';
			}
		} );
	};

	// Hauteur de la bannière réservée au bas de la page.
	const pad = () => {
		document.documentElement.style.scrollPaddingBottom = root.hidden ? '' : root.offsetHeight + 16 + 'px';
	};
	addEventListener( 'resize', pad, { passive: true } );

	const show = ( focus ) => {
		const saved = read() || {};
		boxes.forEach( ( b ) => {
			b.checked = !! saved[ b.dataset.category ];
		} );
		root.hidden = false;
		pad();
		if ( focus ) {
			root.querySelector( 'h2' ).focus();
		}
	};

	const close = () => {
		root.hidden = true;
		pad();
		if ( opener ) {
			( opener.isConnected ? opener : opener.box?.firstChild )?.focus();
			opener = null;
		}
	};

	const decide = ( action ) => {
		const before = read() || {};
		const allowed = {};
		boxes.forEach( ( b ) => {
			allowed[ b.dataset.category ] = action === 'accept' || ( action === 'save' && b.checked );
		} );
		try {
			localStorage.setItem( cfg.k, JSON.stringify( { v: cfg.v, t: Date.now(), c: allowed } ) );
		} catch {}
		if ( Object.keys( before ).some( ( c ) => before[ c ] && ! allowed[ c ] ) ) {
			root.hidden = true;
			purge();
			location.reload();
			return;
		}
		load( allowed );
		close();
	};

	root.addEventListener( 'click', ( e ) => {
		const button = e.target.closest( '[data-consent]' );
		if ( button ) {
			decide( button.dataset.consent );
		}
	} );
	// Échap ferme le panneau rouvert, pas à la première visite.
	root.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' && read() ) {
			close();
		}
	} );
	document.addEventListener( 'click', ( e ) => {
		const link = e.target.closest( '[data-fluffless-consent-open]' );
		if ( link ) {
			opener = link;
			link.box = link.closest( '.fluffless-embed' );
			show( true );
		}
	} );

	const saved = read();
	if ( cfg.s.some( ( s ) => s.ga && ! ( saved && saved[ s.c ] ) ) ) {
		purge();
	}
	// Catégorie absente : bannière redemandée.
	if ( saved ) {
		load( saved );
	}
	if ( ! saved || ! boxes.every( ( b ) => b.dataset.category in saved ) ) {
		show( false );
	}
} )();
