/**
 * Preuve de travail des formulaires Fluffless (format ALTCHA). Le défi est demandé au site quand
 * la personne entre dans le formulaire, résolu par lots avec Web Crypto, posé dans le champ caché ;
 * un envoi trop rapide attend la fin du calcul. Sans ce script, le serveur refuse l'envoi.
 */
( function () {
	'use strict';
	var forms = document.querySelectorAll( 'form[data-fl-altcha]' );
	if ( ! forms.length || ! window.fetch || ! window.crypto || ! window.crypto.subtle ) {
		return;
	}
	var BATCH = 500;
	// Défi valable 20 minutes côté serveur (Altcha::TTL) : redemandé passé 15, à l'horloge du poste.
	var FRESH = 15 * 60 * 1000;
	var encoder = new TextEncoder();

	function bytes( hex ) {
		var out = new Uint8Array( hex.length / 2 );
		for ( var i = 0; i < out.length; i++ ) {
			out[ i ] = parseInt( hex.substr( i * 2, 2 ), 16 );
		}
		return out;
	}

	function same( buffer, target ) {
		var view = new Uint8Array( buffer );
		for ( var i = 0; i < target.length; i++ ) {
			if ( view[ i ] !== target[ i ] ) {
				return false;
			}
		}
		return true;
	}

	function solve( c, start ) {
		var target = bytes( c.challenge );
		var batch = [];
		for ( var n = start; n < start + BATCH && n <= c.maxnumber; n++ ) {
			batch.push( window.crypto.subtle.digest( 'SHA-256', encoder.encode( c.salt + n ) ) );
		}
		if ( ! batch.length ) {
			return Promise.reject( new Error( 'unsolved' ) );
		}
		return Promise.all( batch ).then( function ( hashes ) {
			for ( var i = 0; i < hashes.length; i++ ) {
				if ( same( hashes[ i ], target ) ) {
					return start + i;
				}
			}
			return solve( c, start + BATCH );
		} );
	}

	Array.prototype.forEach.call( forms, function ( form ) {
		var input = form.querySelector( 'input[name="altcha"]' );
		var status = form.querySelector( '[data-fl-busy]' );
		var job = null;
		var fresh = 0;
		var waiting = false;
		var sent = false;
		var clicked = null;
		if ( ! input ) {
			return;
		}

		function reset() {
			input.value = '';
			job = null;
		}

		function run() {
			if ( ! job ) {
				var url = form.getAttribute( 'data-fl-altcha' );
				// Paramètre unique : un cache qui ignorerait no-store ne sert pas le même défi à tous.
				url += ( url.indexOf( '?' ) < 0 ? '?' : '&' ) + '_=' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2 );
				fresh = Date.now() + FRESH;
				job = fetch( url, { credentials: 'omit', cache: 'no-store' } )
					.then( function ( r ) {
						if ( ! r.ok ) {
							throw new Error( 'challenge ' + r.status );
						}
						return r.json();
					} )
					.then( function ( c ) {
						if ( ! c || typeof c.salt !== 'string' || typeof c.challenge !== 'string' ) {
							throw new Error( 'challenge' );
						}
						return solve( c, 0 ).then( function ( number ) {
							input.value = btoa( JSON.stringify( { algorithm: c.algorithm, challenge: c.challenge, number: number, salt: c.salt, signature: c.signature } ) );
						} );
					} )
					.catch( function () {
						input.value = '';
						job = null; // Réseau instable : nouvel essai au prochain envoi.
					} );
			}
			return job;
		}

		form.addEventListener( 'focusin', run, { once: true } );
		// Safari avant 15.4 ignore event.submitter : le dernier bouton cliqué en tient lieu.
		form.addEventListener( 'click', function ( event ) {
			clicked = event.target.closest ? event.target.closest( 'button[type="submit"], input[type="submit"]' ) : null;
		} );
		// Retour par l'historique : la preuve a pu servir, on en refait une.
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				reset();
				sent = false;
			}
		} );
		form.addEventListener( 'submit', function ( event ) {
			var submitter = event.submitter || clicked;
			if ( submitter && submitter.hasAttribute( 'formnovalidate' ) ) {
				return; // Bouton Précédent : rien à prouver.
			}
			if ( sent ) {
				event.preventDefault(); // Double clic : la preuve ne sert qu'une fois.
				return;
			}
			if ( input.value && input.value !== '-' && Date.now() > fresh ) {
				reset(); // Long formulaire : défi bientôt échu, on en demande un neuf.
			}
			if ( input.value ) {
				sent = true;
				return;
			}
			event.preventDefault();
			if ( waiting ) {
				return;
			}
			waiting = true;
			form.setAttribute( 'aria-busy', 'true' );
			if ( submitter ) {
				submitter.setAttribute( 'aria-disabled', 'true' );
			}
			if ( status ) {
				status.textContent = status.getAttribute( 'data-fl-busy' );
			}
			run().then( function () {
				waiting = false;
				form.removeAttribute( 'aria-busy' );
				if ( submitter ) {
					submitter.removeAttribute( 'aria-disabled' );
				}
				if ( status ) {
					status.textContent = '';
				}
				// Échec du calcul : l'envoi part quand même, le serveur explique le refus.
				input.value = input.value || '-';
				if ( form.requestSubmit ) {
					form.requestSubmit( submitter );
					return;
				}
				// Sans requestSubmit, form.submit() perd le nom du bouton : il est recopié.
				if ( submitter && submitter.name ) {
					var copy = document.createElement( 'input' );
					copy.type = 'hidden';
					copy.name = submitter.name;
					copy.value = submitter.value;
					form.appendChild( copy );
				}
				sent = true;
				form.submit();
			} );
		} );
	} );
} )();
