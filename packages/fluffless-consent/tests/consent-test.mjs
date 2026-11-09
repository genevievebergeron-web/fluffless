// Comportement de consent.js dans un DOM simulé (jsdom) : aucune balise Google avant un oui,
// refus mémorisé, réouverture au clavier, chargement après acceptation, retrait.
// Lancer : node packages/fluffless-suite/tests/consent-test.mjs (code de sortie non nul en cas d'échec).
import { readFileSync } from 'node:fs';
import { JSDOM, VirtualConsole } from 'jsdom';

const code = readFileSync( new URL( '../assets/consent/consent.js', import.meta.url ), 'utf8' );
const config = JSON.stringify( { k: 'fluffless-consent', v: 'abc12345', d: 180, s: [ { c: 'analytics', ga: 'G-TEST12345' } ] } );
const markup = `<!doctype html><html><head></head><body>
<div id="fluffless-consent" data-config='${ config }' hidden>
<h2 tabindex="-1">Titre</h2>
<label><input type="checkbox" data-category="analytics"> Mesure</label>
<button type="button" data-consent="reject">Refuser</button>
<button type="button" data-consent="save">Enregistrer</button>
<button type="button" data-consent="accept">Accepter</button>
</div>
<button type="button" class="fluffless-consent-link" hidden data-fluffless-consent-open>Gérer mes témoins</button>
</body></html>`;

let failures = 0;
const check = ( condition, message ) => {
	if ( ! condition ) {
		failures++;
		console.error( 'Échec : ' + message );
	}
};

// Même origine pour toutes les « pages » : le localStorage est partagé entre elles.
const reloads = { count: 0 };
const virtualConsole = new VirtualConsole();
virtualConsole.on( 'jsdomError', ( error ) => {
	if ( /navigation/i.test( error.message ) ) {
		reloads.count++;
	} else {
		failures++;
		console.error( error );
	}
} );
let storage = null;
const page = () => {
	const dom = new JSDOM( markup, { url: 'https://site.example/', runScripts: 'outside-only', virtualConsole } );
	if ( storage ) {
		Object.entries( storage ).forEach( ( [ key, value ] ) => dom.window.localStorage.setItem( key, value ) );
	}
	dom.window.eval( code );
	return dom.window;
};
const save = ( win ) => {
	storage = { ...win.localStorage };
};
const googleTags = ( win ) => [ ...win.document.querySelectorAll( 'script[src]' ) ].filter( ( s ) => s.src.includes( 'googletagmanager' ) ).length;
const click = ( win, selector ) => win.document.querySelector( selector ).click();

// Première visite : bannière visible, rien de Google.
let win = page();
const banner = () => win.document.getElementById( 'fluffless-consent' );
check( ! banner().hidden, 'la bannière ne s\'affiche pas à la première visite' );
check( 0 === googleTags( win ) && undefined === win.gtag, 'balise Google présente avant tout choix' );
check( ! win.document.querySelector( 'input[data-category]' ).checked, 'case précochée' );
check( ! win.document.querySelector( '.fluffless-consent-link' ).hidden, 'C2-1-05 : bouton « Gérer mes témoins » resté caché avec le script' );

// Refus : rien chargé, choix mémorisé, bannière masquée.
click( win, '[data-consent="reject"]' );
check( banner().hidden && 0 === googleTags( win ), 'le refus charge Google ou laisse la bannière' );
check( false === JSON.parse( win.localStorage.getItem( 'fluffless-consent' ) ).c.analytics, 'refus non mémorisé' );
save( win );

// Visite suivante : refus respecté, bannière cachée.
win = page();
check( banner().hidden && 0 === googleTags( win ), 'refus ignoré à la visite suivante' );

// « Gérer mes témoins » : réouverture, focus sur le titre, puis acceptation.
click( win, '[data-fluffless-consent-open]' );
check( ! banner().hidden && win.document.activeElement === banner().querySelector( 'h2' ), 'réouverture sans focus sur le titre' );
click( win, '[data-consent="accept"]' );
check( 1 === googleTags( win ) && 'function' === typeof win.gtag, 'Google non chargé après acceptation' );
check( win.document.activeElement.hasAttribute( 'data-fluffless-consent-open' ), 'focus non rendu au lien « Gérer mes témoins »' );
save( win );

// Retrait : témoins Google effacés, rechargement demandé.
win = page();
check( 1 === googleTags( win ), 'acceptation ignorée à la visite suivante' );
win.document.cookie = '_ga=GA1.1.123; path=/';
click( win, '[data-fluffless-consent-open]' );
click( win, '[data-consent="reject"]' );
check( ! win.document.cookie.includes( '_ga=' ), 'témoin _ga conservé après retrait' );
check( 1 === reloads.count, 'pas de rechargement après retrait' );

// Version changée (nouveaux services) : le choix est redemandé.
storage[ 'fluffless-consent' ] = storage[ 'fluffless-consent' ].replace( 'abc12345', 'ancienne' );
win = page();
check( ! banner().hidden && 0 === googleTags( win ), 'choix d\'une ancienne liste de services réutilisé' );

// Bannière ouverte : la page réserve sa hauteur au bas du défilement. Échap sans choix enregistré : rien ne se ferme.
const escape = ( w ) => w.document.querySelector( '#fluffless-consent h2' ).dispatchEvent( new w.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
check( '' !== win.document.documentElement.style.scrollPaddingBottom, 'scroll-padding-bottom absent quand la bannière est ouverte' );
escape( win );
check( ! banner().hidden, 'Échap ferme la bannière alors qu\'aucun choix n\'est enregistré' );

// Panneau rouvert : Échap le ferme, rend le focus au lien et libère la réserve de défilement.
click( win, '[data-consent="reject"]' );
save( win );
win = page();
click( win, '[data-fluffless-consent-open]' );
check( '' !== win.document.documentElement.style.scrollPaddingBottom, 'scroll-padding-bottom absent au panneau rouvert' );
escape( win );
check(
	banner().hidden && win.document.activeElement.hasAttribute( 'data-fluffless-consent-open' ) && '' === win.document.documentElement.style.scrollPaddingBottom,
	'Échap ne ferme pas le panneau rouvert, perd le focus ou garde la réserve de défilement'
);

// C2-1-01 : « Charger » dans l'espace réservé d'une vidéo. Le bouton disparaît avec l'iframe créée : le focus va à l'iframe.
{
	const media = JSON.stringify( { k: 'fluffless-consent-media', v: 'm1', d: 180, s: [] } );
	const embed = JSON.stringify( { s: 'https://www.youtube-nocookie.com/embed/x', a: { title: 'Vidéo' } } );
	const dom = new JSDOM(
		`<!doctype html><body><div id="fluffless-consent" data-config='${ media }' hidden><h2 tabindex="-1">Titre</h2>`
			+ '<label><input type="checkbox" data-category="media"> Médias</label><button type="button" data-consent="save">Enregistrer</button></div>'
			+ `<div class="fluffless-embed" data-fluffless-embed='${ embed }'><p><button type="button" hidden data-fluffless-consent-open>Charger</button></p></div></body>`,
		{ url: 'https://media.example/', runScripts: 'outside-only', virtualConsole }
	);
	const w = dom.window;
	w.localStorage.setItem( 'fluffless-consent-media', JSON.stringify( { v: 'm1', t: Date.now(), c: { media: false } } ) );
	w.eval( code );
	const load = w.document.querySelector( '.fluffless-embed button' );
	check( ! load.hidden, 'bouton « Charger » de l\'espace réservé resté caché' );
	load.click();
	w.document.querySelector( 'input[data-category="media"]' ).checked = true;
	w.document.querySelector( '[data-consent="save"]' ).click();
	const frame = w.document.querySelector( '.fluffless-embed iframe' );
	check( frame && w.document.activeElement === frame, 'focus perdu après « Charger » : ' + w.document.activeElement.outerHTML.slice( 0, 60 ) );
}

if ( failures > 0 ) {
	console.error( `${ failures } échec(s).` );
	process.exit( 1 );
}
console.log( 'OK : consent.js, 21 contrôles réussis (première visite, refus, réouverture, acceptation, retrait, version, Échap, défilement, focus après « Charger »).' );
