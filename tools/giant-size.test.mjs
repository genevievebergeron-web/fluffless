// Taille « Géante » : la classe .has-giant-font-size doit rendre exactement la taille du préréglage
// (aucun plafond posé par la feuille du thème), un mot de 13 à 14 lettres doit tenir sur une ligne
// à 320 px, 375 px et 768 px, et la feuille du thème doit poser l'interligne serré et la coupure des mots
// trop longs. La mesure se fait dans un vrai navigateur, sur la page d'accueil de l'instance, avec les
// feuilles réellement servies (styles globaux et style.css du thème).
// Les règles de la classe vivent dans assets/css/blocks/taille-geante.css, que le thème met en ligne
// seulement quand un bloc rendu porte la classe. Le titre mesuré ici est ajouté en JavaScript et ne passe
// pas par ce rendu : le test pose donc la feuille servie après style.css, là où le chargeur la met.
// Que le rendu d'un vrai titre Géant appelle bien la feuille, packages/fluffless/tests/block-styles-test.php le vérifie.
// Usage : BASE=http://127.0.0.1:8894 node tools/giant-size.test.mjs   (BASE par défaut : http://127.0.0.1:8888)
// Navigateur : celui de playwright-core, ou CHROMIUM_PATH pour en viser un autre.
import { chromium } from 'playwright-core';

const BASE = ( process.env.BASE || 'http://127.0.0.1:8888' ).replace( /\/$/, '' );
const WIDTHS = [ 320, 375, 768, 1440 ];
// Mots réels du français, 13 et 14 lettres : le plus long cas que la taille Géante doit contenir.
const WORDS = [ 'Développement', 'Accompagnement' ];
const PADDING_PX = 16;

const browser = await chromium.launch( process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {} );
const failures = [];
const rows = [];
let checks = 0;

for ( const width of WIDTHS ) {
	const page = await browser.newPage( { viewport: { width, height: 900 } } );
	const response = await page.goto( BASE + '/', { waitUntil: 'load' } );
	if ( ! response || ! response.ok() ) {
		failures.push( `${ BASE }/ a répondu ${ response ? response.status() : 'rien' } à ${ width } px` );
		await page.close();
		continue;
	}
	// La feuille servie par le thème, lue à côté de la feuille fluffless-css, quel que soit le slug du dossier.
	const themeCss = await page.evaluate( () => document.querySelector( 'link#fluffless-css' )?.href || '' );
	const sheetUrl = themeCss ? new URL( 'assets/css/blocks/taille-geante.css', themeCss ).href : '';
	const sheet = sheetUrl ? await page.request.get( sheetUrl ) : null;
	if ( ! sheet || ! sheet.ok() ) {
		failures.push( `${ width } px : taille-geante.css introuvable (${ sheetUrl || 'feuille fluffless-css absente de la page' })` );
		await page.close();
		continue;
	}
	await page.addStyleTag( { content: await sheet.text() } );
	const measure = await page.evaluate(
		( { words, padding } ) => {
			const host = document.createElement( 'div' );
			host.style.cssText = `padding:0 ${ padding }px;box-sizing:border-box;width:100%`;
			// Un témoin sans classe, dont la taille est celle du préréglage lu tel quel.
			const probe = document.createElement( 'p' );
			probe.style.cssText = 'font-size:var(--wp--preset--font-size--giant);margin:0';
			probe.textContent = 'x';
			host.appendChild( probe );
			const spans = words.map( ( word ) => {
				const heading = document.createElement( 'h2' );
				heading.className = 'has-giant-font-size';
				const span = document.createElement( 'span' );
				span.textContent = word;
				heading.appendChild( span );
				host.appendChild( heading );
				return span;
			} );
			document.body.prepend( host );
			const out = {
				preset: parseFloat( getComputedStyle( probe ).fontSize ),
				classSize: parseFloat( getComputedStyle( spans[ 0 ].parentElement ).fontSize ),
				lineHeight: parseFloat( getComputedStyle( spans[ 0 ].parentElement ).lineHeight ) / parseFloat( getComputedStyle( spans[ 0 ].parentElement ).fontSize ),
				overflowWrap: getComputedStyle( spans[ 0 ].parentElement ).overflowWrap,
				lines: spans.map( ( span ) => span.getClientRects().length ),
				pageOverflow: document.documentElement.scrollWidth - window.innerWidth,
			};
			host.remove();
			return out;
		},
		{ words: WORDS, padding: PADDING_PX }
	);
	rows.push( { width, ...measure } );
	checks += 4 + ( width <= 768 ? 1 : 0 );
	if ( measure.overflowWrap !== 'break-word' ) {
		failures.push( `${ width } px : overflow-wrap ${ measure.overflowWrap } (un mot trop long ferait défiler la page au lieu de se couper)` );
	}
	if ( measure.lineHeight > 1.06 ) {
		failures.push( `${ width } px : interligne ${ measure.lineHeight.toFixed( 2 ) }, plus lâche que le serré (1,05)` );
	}
	if ( Math.abs( measure.classSize - measure.preset ) > 0.5 ) {
		failures.push( `${ width } px : la classe rend ${ measure.classSize } px, le préréglage ${ measure.preset } px (la feuille du thème plafonne la taille)` );
	}
	if ( width <= 768 && measure.lines.some( ( n ) => n !== 1 ) ) {
		failures.push( `${ width } px : un mot de 13 à 14 lettres se coupe (${ WORDS.map( ( w, i ) => `${ w } sur ${ measure.lines[ i ] } ligne(s)` ).join( ', ' ) })` );
	}
	if ( measure.pageOverflow > 0 ) {
		failures.push( `${ width } px : défilement horizontal de ${ measure.pageOverflow } px` );
	}
	await page.close();
}
await browser.close();

for ( const row of rows ) {
	console.log( `${ String( row.width ).padStart( 4 ) } px : classe ${ row.classSize.toFixed( 1 ) } px, préréglage ${ row.preset.toFixed( 1 ) } px, interligne ${ row.lineHeight.toFixed( 2 ) }, ${ row.overflowWrap }, lignes ${ row.lines.join( '/' ) }, débordement ${ row.pageOverflow }` );
}
if ( failures.length > 0 ) {
	console.error( `\nÉCHEC (${ failures.length }) :\n- ${ failures.join( '\n- ' ) }` );
	process.exit( 1 );
}
console.log( `\n${ checks } contrôles passés.` );
