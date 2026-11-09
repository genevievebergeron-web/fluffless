// Script de bascule clair ou sombre (blocks/mode-couleur/head.js) : document et fenêtre simulés.
// Choix relu avant l'affichage, valeur inconnue ignorée, localStorage qui lève sans effet, bouton
// permis seulement avec light-dark(), clic retenu ou retour à l'appareil, aria-pressed suivi,
// y compris quand une extension retarde le script après le chargement.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync( new URL( '../blocks/mode-couleur/head.js', import.meta.url ), 'utf8' );
let checks = 0;
const fail = ( m ) => { console.error( 'ÉCHEC : ' + m ); process.exit( 1 ); };
const ok = ( condition, m ) => { checks++; if ( ! condition ) fail( m ); };

const BUTTON = '.wp-block-fluffless-mode-couleur button';

// Une page : <html>, des boutons, localStorage, matchMedia et CSS.supports réglables.
function page( { stored = null, dark = false, supports = true, throws = false, buttons = 1, ready = 'loading' } = {} ) {
	const attrs = new Map();
	const root = {
		setAttribute: ( k, v ) => attrs.set( k, String( v ) ),
		getAttribute: ( k ) => ( attrs.has( k ) ? attrs.get( k ) : null ),
		removeAttribute: ( k ) => attrs.delete( k ),
	};
	const list = Array.from( { length: buttons }, () => {
		const b = { attrs: new Map(), setAttribute: ( k, v ) => b.attrs.set( k, String( v ) ) };
		b.closest = ( sel ) => ( sel === BUTTON ? b : null );
		return b;
	} );
	const listeners = {};
	const doc = {
		documentElement: root,
		readyState: ready,
		querySelectorAll: ( sel ) => ( sel === BUTTON ? list : [] ),
		addEventListener: ( type, fn ) => { ( listeners[ type ] ||= [] ).push( fn ); },
	};
	const store = new Map( null === stored ? [] : [ [ 'fluffless-scheme', stored ] ] );
	const guard = ( fn ) => ( ...a ) => { if ( throws ) throw new Error( 'SecurityError' ); return fn( ...a ); };
	const media = { matches: dark, fns: [], addEventListener: ( type, fn ) => { if ( 'change' === type ) media.fns.push( fn ); } };
	const win = {
		localStorage: {
			getItem: guard( ( k ) => ( store.has( k ) ? store.get( k ) : null ) ),
			setItem: guard( ( k, v ) => store.set( k, String( v ) ) ),
			removeItem: guard( ( k ) => store.delete( k ) ),
		},
		matchMedia: ( q ) => { ok( '(prefers-color-scheme: dark)' === q, 'requête média attendue' ); return media; },
		CSS: { supports: ( p, v ) => supports && 'color' === p && v.startsWith( 'light-dark(' ) },
	};
	vm.runInNewContext( source, { document: doc, window: win } );
	const fire = ( type, event = {} ) => ( listeners[ type ] || [] ).forEach( ( fn ) => fn( event ) );
	return {
		attrs, store, list, fire,
		click: ( target ) => fire( 'click', { target } ),
		systemChange: ( value ) => { media.matches = value; media.fns.forEach( ( fn ) => fn() ); },
		pressed: () => list.map( ( b ) => b.attrs.get( 'aria-pressed' ) ),
	};
}

// 1. Aucun choix : rien sur la racine, bouton permis, aria-pressed selon l'appareil.
let p = page();
ok( ! p.attrs.has( 'data-fluffless-scheme' ), 'aucun choix : pas d\'attribut' );
ok( p.attrs.has( 'data-fluffless-toggle' ), 'light-dark() compris : bouton permis' );
p.fire( 'DOMContentLoaded' );
ok( 'false' === p.pressed()[ 0 ], 'appareil clair : aria-pressed false' );
p = page( { dark: true, buttons: 2 } );
p.fire( 'DOMContentLoaded' );
ok( p.pressed().every( ( v ) => 'true' === v ), 'appareil sombre : aria-pressed true sur chaque bouton' );

// 2. Choix relu avant l'affichage ; valeur inconnue ignorée.
ok( 'dark' === page( { stored: 'dark' } ).attrs.get( 'data-fluffless-scheme' ), 'choix sombre relu' );
ok( 'light' === page( { stored: 'light', dark: true } ).attrs.get( 'data-fluffless-scheme' ), 'choix clair relu' );
for ( const junk of [ 'auto', 'DARK', '"><script>', '' ] ) {
	ok( ! page( { stored: junk } ).attrs.has( 'data-fluffless-scheme' ), `valeur inconnue ignorée : ${ JSON.stringify( junk ) }` );
}

// 3. Navigateur sans light-dark() : bouton jamais permis.
ok( ! page( { supports: false } ).attrs.has( 'data-fluffless-toggle' ), 'sans light-dark() : bouton caché' );

// 4. Clic : retenu quand il diffère de l'appareil, retour à l'auto quand il l'égale.
p = page();
p.click( p.list[ 0 ] );
ok( 'dark' === p.attrs.get( 'data-fluffless-scheme' ) && 'dark' === p.store.get( 'fluffless-scheme' ), 'clic sur appareil clair : sombre retenu' );
ok( 'true' === p.pressed()[ 0 ], 'clic : aria-pressed true' );
p.click( p.list[ 0 ] );
ok( ! p.attrs.has( 'data-fluffless-scheme' ) && ! p.store.has( 'fluffless-scheme' ), 'second clic : retour au réglage de l\'appareil, clé retirée' );
ok( 'false' === p.pressed()[ 0 ], 'second clic : aria-pressed false' );
p = page( { dark: true } );
p.click( p.list[ 0 ] );
ok( 'light' === p.attrs.get( 'data-fluffless-scheme' ) && 'light' === p.store.get( 'fluffless-scheme' ), 'clic sur appareil sombre : clair retenu' );
p.click( { closest: () => null } );
ok( 'light' === p.attrs.get( 'data-fluffless-scheme' ), 'clic ailleurs : rien ne change' );
p.click( null );
ok( 'light' === p.attrs.get( 'data-fluffless-scheme' ), 'clic sans cible : rien ne change' );

// 5. Réglage de l'appareil qui change : aria-pressed suit tant que rien n'est choisi.
p = page();
p.fire( 'DOMContentLoaded' );
p.systemChange( true );
ok( 'true' === p.pressed()[ 0 ], 'appareil passé au sombre : aria-pressed true' );
p = page( { stored: 'light' } );
p.systemChange( true );
ok( 'false' === p.pressed()[ 0 ], 'choix clair gardé quand l\'appareil passe au sombre' );

// 6. localStorage qui lève (navigation privée, cookies bloqués) : la bascule marche pour la page.
p = page( { throws: true, stored: 'dark' } );
ok( ! p.attrs.has( 'data-fluffless-scheme' ), 'localStorage qui lève : aucun choix relu, aucune erreur' );
p.click( p.list[ 0 ] );
ok( 'dark' === p.attrs.get( 'data-fluffless-scheme' ) && 'true' === p.pressed()[ 0 ], 'localStorage qui lève : le clic bascule quand même' );

// 7. Script retardé après le chargement (extension d'optimisation) : aria-pressed posé tout de suite.
p = page( { dark: true, ready: 'complete' } );
ok( 'true' === p.pressed()[ 0 ], 'script retardé : aria-pressed posé sans DOMContentLoaded' );
p = page( { ready: 'interactive' } );
ok( 'false' === p.pressed()[ 0 ], 'script retardé, appareil clair : aria-pressed false' );

// 8. Fenêtre sans matchMedia ni CSS : aucune erreur, réglage clair supposé.
{
	const doc = { documentElement: { setAttribute() {}, getAttribute: () => null, removeAttribute() {} }, querySelectorAll: () => [], addEventListener() {} };
	let threw = false;
	try {
		vm.runInNewContext( source, { document: doc, window: {} } );
	} catch ( e ) {
		threw = true;
	}
	ok( ! threw, 'fenêtre minimale : aucune erreur' );
}

console.log( `OK : bascule clair ou sombre, ${ checks } vérifications.` );
