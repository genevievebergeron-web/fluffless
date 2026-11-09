// Comportement de nav-escape.js dans un DOM simulé (jsdom) : Échap ferme le <details data-fluffless-esc>
// ouvert qui contient le focus et rend le focus au summary ; rien pour un <details> sans l'attribut,
// une autre touche, un événement déjà traité (defaultPrevented) ou un focus hors du panneau.
// Lancer : node packages/fluffless/tests/nav-escape-test.mjs (code de sortie non nul en cas d'échec).
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const code = readFileSync( new URL( '../assets/js/nav-escape.js', import.meta.url ), 'utf8' );
let failures = 0;
const check = ( condition, message ) => {
	if ( ! condition ) {
		failures++;
		console.error( 'Échec : ' + message );
	}
};
const html = `<!doctype html><html><body>
<details id="menu" data-fluffless-esc open><summary id="s">Menu</summary><a id="a" href="/x">Lien</a></details>
<details id="faq" open><summary id="fs">FAQ</summary><a id="fa" href="/y">Réponse</a></details>
<a id="out" href="/z">Dehors</a>
</body></html>`;
const run = () => {
	const dom = new JSDOM( html );
	new Function( 'document', code )( dom.window.document );
	return dom.window;
};
const press = ( win, key, prevented = false ) => {
	const event = new win.KeyboardEvent( 'keydown', { key, bubbles: true, cancelable: true } );
	if ( prevented ) {
		event.preventDefault();
	}
	win.document.activeElement.dispatchEvent( event );
	return event;
};

// 1. Focus dans le menu, Échap : fermé, focus sur le summary, événement consommé.
let win = run();
win.document.getElementById( 'a' ).focus();
let event = press( win, 'Escape' );
check( ! win.document.getElementById( 'menu' ).open, 'menu ouvert après Échap' );
check( win.document.activeElement === win.document.getElementById( 's' ), 'focus non rendu au summary' );
check( event.defaultPrevented, 'événement non consommé' );

// 2. Autre touche : rien.
win = run();
win.document.getElementById( 'a' ).focus();
press( win, 'Enter' );
check( win.document.getElementById( 'menu' ).open, 'menu fermé par Entrée' );

// 3. Événement déjà traité : rien.
win = run();
win.document.getElementById( 'a' ).focus();
press( win, 'Escape', true );
check( win.document.getElementById( 'menu' ).open, 'menu fermé malgré defaultPrevented' );

// 4. <details> sans l'attribut (FAQ) : rien.
win = run();
win.document.getElementById( 'fa' ).focus();
event = press( win, 'Escape' );
check( win.document.getElementById( 'faq' ).open && ! event.defaultPrevented, 'FAQ fermée ou événement consommé' );

// 5. Focus hors de tout panneau : rien.
win = run();
win.document.getElementById( 'out' ).focus();
press( win, 'Escape' );
check( win.document.getElementById( 'menu' ).open, 'menu fermé depuis l’extérieur' );

// 6. Menu fermé, focus sur le summary : rien à faire, pas d'erreur.
win = run();
win.document.getElementById( 'menu' ).open = false;
win.document.getElementById( 's' ).focus();
event = press( win, 'Escape' );
check( ! event.defaultPrevented, 'événement consommé sur un menu fermé' );

// 7. C3-2-03 : le focus quitte le panneau resté ouvert (tabulation) : le panneau se ferme, le focus
// reste où il va.
win = run();
win.document.getElementById( 'a' ).focus();
win.document.getElementById( 'out' ).focus();
check( ! win.document.getElementById( 'menu' ).open, 'menu resté ouvert après la sortie du focus' );
check( win.document.activeElement === win.document.getElementById( 'out' ), 'focus détourné à la sortie du panneau' );

// 8. Le focus va du lien au summary, dans le panneau : il reste ouvert.
win = run();
win.document.getElementById( 'a' ).focus();
win.document.getElementById( 's' ).focus();
check( win.document.getElementById( 'menu' ).open, 'menu fermé par un déplacement interne' );

// 9. Focus perdu sans cible (clic sur un texte du panneau) : il reste ouvert.
win = run();
win.document.getElementById( 'a' ).focus();
win.document.getElementById( 'a' ).dispatchEvent( new win.FocusEvent( 'focusout', { bubbles: true, relatedTarget: null } ) );
check( win.document.getElementById( 'menu' ).open, 'menu fermé sans cible de focus' );

// 10. Sortie du focus d'un <details> sans l'attribut (FAQ) : rien.
win = run();
win.document.getElementById( 'fa' ).focus();
win.document.getElementById( 'out' ).focus();
check( win.document.getElementById( 'faq' ).open, 'FAQ fermée à la sortie du focus' );

if ( failures ) {
	console.error( failures + ' échec(s)' );
	process.exit( 1 );
}
console.log( 'nav-escape : 13 vérifications passées.' );
