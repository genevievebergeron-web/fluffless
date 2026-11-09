// Comportement de editor-canvas-classes.js dans un DOM simulé (jsdom) : rien hors iframe, classes
// posées quand le corps devient celui du canevas, reposées après réécriture, plafond de 50.
// Lancer : node packages/fluffless/tests/editor-canvas-classes-test.mjs (code de sortie non nul en cas d'échec).
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const code = readFileSync( new URL( '../assets/js/editor-canvas-classes.js', import.meta.url ), 'utf8' );
let failures = 0;
const check = ( condition, message ) => {
	if ( ! condition ) {
		failures++;
		console.error( 'Échec : ' + message );
	}
};
const tick = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

// Le script ne lit que window.flufflessEditorCanvasClasses et window.frameElement : une fenêtre
// factice suffit pour simuler l'iframe (frameElement présent) ou le document principal (absent).
const run = ( classes, inFrame, html = '<!doctype html><html><head></head><body></body></html>' ) => {
	const dom = new JSDOM( html );
	const fakeWindow = { flufflessEditorCanvasClasses: classes, frameElement: inFrame ? {} : null };
	new Function( 'window', 'document', 'MutationObserver', code )( fakeWindow, dom.window.document, dom.window.MutationObserver );
	return dom.window.document;
};

// 1. Document principal (pas d'iframe) : rien.
let doc = run( [ 'rx', 'rx-page' ], false, '<!doctype html><html><body class="block-editor-iframe__body"></body></html>' );
await tick();
check( doc.body.className === 'block-editor-iframe__body', 'hors iframe, le corps reste intact' );

// 2. Iframe dont le corps n'est pas (encore) le canevas : rien tant que la classe du canevas manque.
doc = run( [ 'rx', 'rx-page' ], true );
await tick();
check( doc.body.className === '', 'corps sans classe de canevas : rien' );
doc.body.className = 'block-editor-iframe__body post-type-page';
await tick();
check( doc.body.classList.contains( 'rx' ) && doc.body.classList.contains( 'rx-page' ), 'classes posées quand le corps devient le canevas' );
check( doc.body.classList.contains( 'post-type-page' ), 'les classes existantes sont gardées' );

// 3. Réécriture de l'attribut par l'éditeur : classes reposées.
doc.body.className = 'block-editor-iframe__body editor-styles-wrapper';
await tick();
check( doc.body.classList.contains( 'rx' ) && doc.body.classList.contains( 'editor-styles-wrapper' ), 'classes reposées après réécriture' );

// 4. Corps remplacé : le nouveau corps est observé.
const fresh = doc.createElement( 'body' );
fresh.className = 'block-editor-iframe__body';
doc.documentElement.replaceChild( fresh, doc.body );
await tick();
check( doc.body === fresh && fresh.classList.contains( 'rx-page' ), 'nouveau corps pris en charge' );

// 5. Plafond : après 50 reposes, le script lâche prise.
doc = run( [ 'rx' ], true, '<!doctype html><html><body class="block-editor-iframe__body"></body></html>' );
await tick();
check( doc.body.classList.contains( 'rx' ), 'pose initiale (1 de 50)' );
for ( let i = 0; i < 49; i++ ) {
	doc.body.className = 'block-editor-iframe__body';
	await tick();
}
check( doc.body.classList.contains( 'rx' ), 'reposée jusqu’au plafond' );
doc.body.className = 'block-editor-iframe__body';
await tick();
check( ! doc.body.classList.contains( 'rx' ), 'au-delà du plafond, plus de repose' );

// 6. Entrées invalides ignorées, liste vide : rien.
doc = run( [ 'rx', 7, '' ], true, '<!doctype html><html><body class="block-editor-iframe__body"></body></html>' );
await tick();
check( doc.body.className === 'block-editor-iframe__body rx', 'entrées non textuelles ignorées : ' + doc.body.className );
doc = run( [], true, '<!doctype html><html><body class="block-editor-iframe__body"></body></html>' );
await tick();
check( doc.body.className === 'block-editor-iframe__body', 'liste vide : rien' );

if ( failures > 0 ) {
	process.exit( 1 );
}
console.log( 'OK : editor-canvas-classes.js' );
