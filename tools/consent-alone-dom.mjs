// Vérifications DOM de tools/test-consent-alone.sh, sur le HTML servi par le banc (jsdom, aucun réseau).
//   node tools/consent-alone-dom.mjs resources <page.html> <hôte local>
//     Ressources que le navigateur chargerait sans script : aucune ne doit viser un autre hôte.
//     Les adresses tierces écrites dans un script en ligne actif sont signalées à part (sortie 1 si elles
//     visent un service tenu par Consent, avertissement sinon : chargeur d'émojis du cœur, par exemple).
//   node tools/consent-alone-dom.mjs choices <page.html> <consent.js>
//     Refus puis acceptation avec la vraie bannière : rien de Google ni d'iframe YouTube avant un oui.
import { readFileSync } from 'node:fs';
import { JSDOM, VirtualConsole } from 'jsdom';

const [ mode, file, extra ] = process.argv.slice( 2 );
const html = readFileSync( file, 'utf8' );
let failures = 0;
const fail = ( message ) => {
	failures++;
	console.error( 'Échec : ' + message );
};
// Hôtes des services que Consent bloque dans ce banc (GA4 et contenu intégré YouTube).
const tracked = /googletagmanager\.com|google-analytics\.com|youtube(-nocookie)?\.com|ytimg\.com/;

if ( 'resources' === mode ) {
	const local = extra;
	const { document } = new JSDOM( html, { url: `http://${ local }/` } ).window;
	const urls = [];
	const add = ( value, where ) => value && urls.push( { value, where } );
	document.querySelectorAll( 'script[src]' ).forEach( ( el ) => add( el.getAttribute( 'src' ), 'script' ) );
	document.querySelectorAll( 'link[href]' ).forEach( ( el ) => {
		if ( /\b(stylesheet|preload|prefetch|preconnect|dns-prefetch|modulepreload|icon)\b/i.test( el.getAttribute( 'rel' ) || '' ) ) {
			add( el.getAttribute( 'href' ), 'link ' + el.getAttribute( 'rel' ) );
		}
	} );
	document.querySelectorAll( 'img, iframe, video, audio, source, embed, track, input[type="image"]' ).forEach( ( el ) => {
		add( el.getAttribute( 'src' ), el.tagName.toLowerCase() );
		add( el.getAttribute( 'poster' ), el.tagName.toLowerCase() + ' poster' );
		( el.getAttribute( 'srcset' ) || '' ).split( ',' ).forEach( ( part ) => add( part.trim().split( /\s+/ )[ 0 ], el.tagName.toLowerCase() + ' srcset' ) );
	} );
	document.querySelectorAll( 'object[data]' ).forEach( ( el ) => add( el.getAttribute( 'data' ), 'object' ) );
	const styles = [ ...document.querySelectorAll( 'style' ) ].map( ( el ) => el.textContent ).concat( [ ...document.querySelectorAll( '[style]' ) ].map( ( el ) => el.getAttribute( 'style' ) ) );
	styles.forEach( ( css ) => {
		for ( const match of css.matchAll( /url\(\s*['"]?([^'")]+)/g ) ) {
			add( match[ 1 ], 'css url()' );
		}
		for ( const match of css.matchAll( /@import\s+['"]([^'"]+)/g ) ) {
			add( match[ 1 ], 'css @import' );
		}
	} );
	for ( const { value, where } of urls ) {
		let host;
		try {
			host = new URL( value, `http://${ local }/` ).host;
		} catch {
			continue;
		}
		if ( host && host !== local && ! value.startsWith( 'data:' ) ) {
			fail( `ressource externe avant consentement (${ where }) : ${ value }` );
		}
	}
	// Scripts en ligne actifs (pas text/plain, pas JSON) : adresses tierces écrites dans le code.
	document.querySelectorAll( 'script:not([src])' ).forEach( ( el ) => {
		const type = ( el.getAttribute( 'type' ) || 'text/javascript' ).toLowerCase();
		if ( ! /javascript|module/.test( type ) ) {
			return;
		}
		for ( const match of el.textContent.matchAll( /https?:(?:\\?\/){2}([a-z0-9.-]+)/gi ) ) {
			if ( match[ 1 ] === local.split( ':' )[ 0 ] ) {
				continue;
			}
			if ( tracked.test( match[ 1 ] ) ) {
				fail( `service tenu par Consent dans un script en ligne actif : ${ match[ 1 ] }` );
			} else {
				console.log( `Avertissement : adresse tierce dans un script en ligne actif (hors Consent) : ${ match[ 1 ] }` );
			}
		}
	} );
	console.log( `${ urls.length } ressources examinées.` );
} else if ( 'choices' === mode ) {
	const code = readFileSync( extra, 'utf8' );
	const virtualConsole = new VirtualConsole();
	virtualConsole.on( 'jsdomError', ( error ) => {
		if ( ! /navigation/i.test( error.message ) ) {
			fail( error.message );
		}
	} );
	let storage = null;
	const page = () => {
		const dom = new JSDOM( html, { url: 'http://banc.example/', runScripts: 'outside-only', virtualConsole } );
		if ( storage ) {
			Object.entries( storage ).forEach( ( [ key, value ] ) => dom.window.localStorage.setItem( key, value ) );
		}
		dom.window.eval( code );
		return dom.window;
	};
	const google = ( win ) => win.document.querySelectorAll( 'script[src*="googletagmanager"]' ).length;
	const youtube = ( win ) => win.document.querySelectorAll( 'iframe[src*="youtube"]' ).length;
	const banner = ( win ) => win.document.getElementById( 'fluffless-consent' );

	let win = page();
	if ( ! banner( win ) || banner( win ).hidden ) {
		fail( 'bannière absente ou cachée à la première visite' );
	}
	if ( google( win ) || youtube( win ) ) {
		fail( 'Google ou YouTube présent avant tout choix' );
	}
	if ( [ ...win.document.querySelectorAll( '#fluffless-consent input[type="checkbox"]' ) ].some( ( box ) => box.checked ) ) {
		fail( 'case précochée' );
	}
	const reject = win.document.querySelector( '[data-consent="reject"]' );
	const accept = win.document.querySelector( '[data-consent="accept"]' );
	if ( ! reject || ! accept || reject.parentElement !== accept.parentElement ) {
		fail( '« Refuser tout » et « Accepter tout » pas au même niveau' );
	}
	reject?.click();
	if ( ! banner( win ).hidden || google( win ) || youtube( win ) ) {
		fail( 'le refus laisse la bannière ou charge un service' );
	}
	storage = { ...win.localStorage };
	win = page();
	if ( ! banner( win ).hidden || google( win ) || youtube( win ) ) {
		fail( 'refus ignoré à la visite suivante' );
	}
	win.document.querySelector( '[data-fluffless-consent-open]' )?.click();
	if ( banner( win ).hidden ) {
		fail( '« Gérer mes témoins » ne rouvre pas la bannière' );
	}
	win.document.querySelector( '[data-consent="accept"]' ).click();
	if ( 1 !== google( win ) ) {
		fail( 'Google Analytics non chargé après acceptation' );
	}
	if ( 0 === youtube( win ) ) {
		fail( 'iframe YouTube non recréée après acceptation' );
	}
	console.log( 'Refus, réouverture et acceptation vérifiés sur la bannière servie.' );
} else {
	fail( 'mode inconnu : ' + mode );
}

process.exit( failures ? 1 : 0 );
