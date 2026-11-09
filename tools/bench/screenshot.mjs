/**
 * Capture d'une page en Chromium sans tête (mêmes binaires que measure.mjs), pour vérifier à l'œil
 * l'accord du thème avec une extension : node tools/bench/screenshot.mjs --url U --out fichier.png [--width 1100]
 */
import { parseArgs } from 'node:util';
import { chromium } from 'playwright-core';

const { values: opts } = parseArgs( {
	options: {
		url: { type: 'string' },
		out: { type: 'string' },
		width: { type: 'string', default: '1100' },
		focus: { type: 'string', default: '' },
	},
} );
if ( ! opts.url || ! opts.out ) {
	console.error( 'Usage : node tools/bench/screenshot.mjs --url U --out fichier.png [--width 1100] [--focus sélecteur]' );
	process.exit( 1 );
}
const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: Number( opts.width ), height: 900 } } );
await page.goto( opts.url, { waitUntil: 'networkidle' } );
if ( opts.focus ) {
	await page.focus( opts.focus ).catch( () => {} );
	await page.keyboard.press( 'Tab' );
	await page.keyboard.press( 'Shift+Tab' );
}
const form = page.locator( 'form' ).first();
if ( await form.count() ) {
	await form.scrollIntoViewIfNeeded();
	await form.screenshot( { path: opts.out } );
} else {
	await page.screenshot( { path: opts.out, fullPage: true } );
}
await browser.close();
console.log( opts.out );
