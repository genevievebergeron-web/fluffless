/**
 * Test du verrou de saisie de la relecture (assets/reviewer/lock.js), sans navigateur : faux
 * document, fausse iframe du canevas, événements simulés.
 *
 * Lancer : node packages/fluffless-suite/tests/reviewer-lock-test.mjs
 */
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const root = new URL( '../assets/reviewer/', import.meta.url );
const fail = ( m ) => { console.error( 'ÉCHEC : ' + m ); process.exit( 1 ); };

const makeDoc = ( frames = [] ) => {
	const listeners = {};
	return {
		listeners,
		addEventListener: ( type, fn, capture ) => { ( listeners[ type ] ||= [] ).push( { fn, capture } ); },
		querySelectorAll: () => frames,
		body: {},
	};
};
const canvas = makeDoc();
const frame = { contentDocument: canvas, addEventListener: () => {} };
const main = makeDoc( [ frame ] );
const notices = [];
let ready = null;
const wp = {
	domReady: ( fn ) => { ready = fn; },
	data: { dispatch: () => ( { createNotice: ( ...a ) => notices.push( a ) } ) },
};
const sandbox = { window: { wp, flufflessReviewerLock: { notice: 'You are reviewing.' }, MutationObserver: class { observe() {} } }, document: main, WeakSet };
sandbox.window.window = sandbox.window;
sandbox.window.document = main;
vm.runInNewContext( readFileSync( new URL( 'lock.js', root ), 'utf8' ), sandbox.window );
if ( ! ready ) fail( 'script non lancé à domReady' );
ready();

// Élément du canevas : dans .editor-styles-wrapper et contenteditable ; élément d'une note : ni l'un ni l'autre.
const node = ( inside ) => ( { closest: ( sel ) => ( inside ? {} : null ) } );
const fire = ( doc, type, target, key ) => {
	const event = { type, key, target, prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; } };
	( doc.listeners[ type ] || [] ).forEach( ( l ) => { if ( ! l.capture ) fail( type + ' : écouteur hors capture' ); l.fn( event ); } );
	return event;
};

for ( const type of [ 'beforeinput', 'paste', 'drop', 'cut' ] ) {
	if ( ! fire( canvas, type, node( true ) ).prevented ) fail( type + ' : saisie acceptée dans le canevas' );
	if ( fire( canvas, type, node( false ) ).prevented ) fail( type + ' : saisie refusée hors du texte des blocs (note)' );
}
for ( const key of [ 'Enter', 'Backspace', 'Delete' ] ) {
	const event = fire( canvas, 'keydown', node( true ), key );
	if ( ! event.prevented || ! event.stopped ) fail( key + ' : touche acceptée dans le canevas' );
}
for ( const key of [ 'ArrowLeft', 'Tab', 'c' ] ) {
	if ( fire( canvas, 'keydown', node( true ), key ).prevented ) fail( key + ' : déplacement ou copie refusés' );
}
if ( ! main.listeners.beforeinput ) fail( 'document principal non gardé (éditeur sans iframe)' );
if ( 1 !== notices.length || 'info' !== notices[ 0 ][ 0 ] || 'You are reviewing.' !== notices[ 0 ][ 1 ] || false !== notices[ 0 ][ 2 ].isDismissible ) fail( 'avis de relecture : ' + JSON.stringify( notices ) );
ready();
if ( 1 !== canvas.listeners.beforeinput.length ) fail( 'canevas gardé deux fois' );

console.log( 'OK : relecture, saisie refusée dans le canevas, notes libres, avis affiché.' );
