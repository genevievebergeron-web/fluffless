#!/usr/bin/env node
/**
 * Mesure la page d'accueil du banc d'essai dans Chromium (Playwright) et, en option, Lighthouse.
 * Usage : node tools/bench/measure.mjs --label <nom> [--url http://127.0.0.1:8883/] [--versions '<json>']
 * Variables : BENCH_RUNS (chargements mesurés, défaut 3), BENCH_LIGHTHOUSE=0 pour omettre Lighthouse.
 * Résultat fusionné dans tools/reports/bench-AAAA-MM-JJ.json, clé = --label.
 *
 * Conditions : fenêtre 1920 x 1080 (celle d'EcoIndex), cache vide à chaque chargement
 * (nouveau contexte), un chargement de préchauffage non compté (Elementor y génère son CSS).
 */
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import { chromium } from 'playwright-core';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const REPORTS = join( ROOT, 'tools', 'reports' );
const LIGHTHOUSE_VERSION = '13.4.1';
const SETTLE_MS = 1000; // Laisse finir les requêtes différées après l'événement load.
const MAX_RUNS = 10;

const { values: opts } = parseArgs( {
	options: {
		label: { type: 'string' },
		url: { type: 'string', default: 'http://127.0.0.1:8883/' },
		versions: { type: 'string', default: '{}' },
	},
} );
if ( ! opts.label ) {
	throw new Error( 'measure.mjs : --label est obligatoire (ex. --label fluffless).' );
}
const runs = Number( process.env.BENCH_RUNS ?? 3 );
if ( ! Number.isInteger( runs ) || runs < 1 || runs > MAX_RUNS ) {
	throw new Error( `measure.mjs : BENCH_RUNS doit être un entier de 1 à ${ MAX_RUNS } (reçu : ${ process.env.BENCH_RUNS }).` );
}
let versions;
try {
	versions = JSON.parse( opts.versions );
} catch ( error ) {
	throw new Error( `measure.mjs : --versions n'est pas du JSON valide (reçu : ${ opts.versions.slice( 0, 80 ) }).`, { cause: error } );
}
const origin = new URL( opts.url ).origin;

/* ---------- EcoIndex ---------- */
// Repris tels quels de ecoindex-compute 5.9.0 (PyPI, cnumr), ecoindex/data/quantiles.py et grades.py.
const Q_DOM = [ 0, 47, 75, 159, 233, 298, 358, 417, 476, 537, 603, 674, 753, 843, 949, 1076, 1237, 1459, 1801, 2479, 594601 ];
const Q_REQ = [ 0, 2, 15, 25, 34, 42, 49, 56, 63, 70, 78, 86, 95, 105, 117, 130, 147, 170, 205, 281, 3920 ];
const Q_SIZE = [ 0, 1.37, 144.7, 319.53, 479.46, 631.97, 783.38, 937.91, 1098.62, 1265.47, 1448.32, 1648.27, 1876.08, 2142.06, 2465.37, 2866.31, 3401.59, 4155.73, 5400.08, 8037.54, 223212.26 ];
const GRADES = [ [ 'A', 80 ], [ 'B', 70 ], [ 'C', 55 ], [ 'D', 40 ], [ 'E', 25 ], [ 'F', 10 ] ];

const quantile = ( q, value ) => {
	for ( let i = 1; i < q.length; i++ ) {
		if ( value < q[ i ] ) {
			return i - 1 + ( value - q[ i - 1 ] ) / ( q[ i ] - q[ i - 1 ] );
		}
	}
	return q.length - 1;
};
export const ecoindex = ( dom, sizeKb, requests ) => {
	const score = Math.round( 100 - ( 5 * ( 3 * quantile( Q_DOM, dom ) + 2 * quantile( Q_REQ, requests ) + quantile( Q_SIZE, sizeKb ) ) ) / 6 );
	const grade = GRADES.find( ( [ , min ] ) => score > min )?.[ 0 ] ?? 'G';
	return {
		score,
		grade,
		gesGramsCO2e: Math.round( 100 * ( 2 + ( 2 * ( 50 - score ) ) / 100 ) ) / 100,
		waterCl: Math.round( 100 * ( 3 + ( 3 * ( 50 - score ) ) / 100 ) ) / 100,
	};
};

/* ---------- Chargement mesuré ---------- */

const TYPE_MAP = { Document: 'html', Stylesheet: 'css', Script: 'js', Font: 'font', Image: 'image' };

async function measureOnce( browser ) {
	const context = await browser.newContext( { viewport: { width: 1920, height: 1080 } } );
	const page = await context.newPage();
	const cdp = await context.newCDPSession( page );
	await cdp.send( 'Network.enable' );
	await cdp.send( 'Network.setCacheDisabled', { cacheDisabled: true } );

	const requests = new Map();
	cdp.on( 'Network.requestWillBeSent', ( e ) => {
		// blob: et data: ne passent pas par le réseau : ni requête ni octet transféré.
		if ( ! /^https?:/.test( e.request.url ) ) {
			return;
		}
		requests.set( e.requestId, { url: e.request.url, type: TYPE_MAP[ e.type ] ?? 'other', transfer: 0, raw: 0, status: null } );
	} );
	cdp.on( 'Network.responseReceived', ( e ) => {
		const r = requests.get( e.requestId );
		if ( r ) {
			r.status = e.response.status;
			r.encoding = e.response.headers[ 'content-encoding' ] ?? e.response.headers[ 'Content-Encoding' ] ?? null;
		}
	} );
	cdp.on( 'Network.dataReceived', ( e ) => {
		const r = requests.get( e.requestId );
		if ( r ) {
			r.raw += e.dataLength;
		}
	} );
	cdp.on( 'Network.loadingFinished', ( e ) => {
		const r = requests.get( e.requestId );
		if ( r ) {
			r.transfer = e.encodedDataLength;
		}
	} );

	const response = await page.goto( opts.url, { waitUntil: 'load' } );
	if ( ! response || response.status() !== 200 ) {
		throw new Error( `measure.mjs : ${ opts.url } a répondu ${ response?.status() } au lieu de 200 (variante ${ opts.label }).` );
	}
	await page.waitForLoadState( 'networkidle' );
	await page.waitForTimeout( SETTLE_MS );

	const dom = await page.evaluate( () => {
		const bytes = ( s ) => new TextEncoder().encode( s ).length;
		const all = document.getElementsByTagName( '*' );
		const svgDescendants = document.querySelectorAll( 'svg *' ).length;
		const inlineScripts = [ ...document.querySelectorAll( 'script:not([src])' ) ];
		const executable = inlineScripts.filter( ( s ) => ! s.type || /javascript|module/.test( s.type ) );
		return {
			nodes: all.length,
			nodesWithoutSvgChildren: all.length - svgDescendants,
			inlineCssBytes: [ ...document.querySelectorAll( 'style' ) ].reduce( ( t, s ) => t + bytes( s.textContent ), 0 ),
			inlineStyleTags: document.querySelectorAll( 'style' ).length,
			inlineJsBytes: executable.reduce( ( t, s ) => t + bytes( s.textContent ), 0 ),
			inlineJsTags: executable.length,
			inlineDataScriptBytes: inlineScripts.filter( ( s ) => ! executable.includes( s ) ).reduce( ( t, s ) => t + bytes( s.textContent ), 0 ),
			elementorWidgets: document.querySelectorAll( '.elementor-widget' ).length,
		};
	} );
	await context.close();

	const list = [ ...requests.values() ];
	const byType = {};
	for ( const r of list ) {
		const t = ( byType[ r.type ] ??= { requests: 0, transferBytes: 0, rawBytes: 0 } );
		t.requests++;
		t.transferBytes += r.transfer;
		t.rawBytes += r.raw;
	}
	const thirdParty = list.filter( ( r ) => ! r.url.startsWith( origin ) && ! r.url.startsWith( 'data:' ) );
	const transferBytes = list.reduce( ( t, r ) => t + r.transfer, 0 );
	return {
		requests: list.length,
		thirdPartyRequests: thirdParty.length,
		thirdPartyUrls: thirdParty.map( ( r ) => r.url ),
		transferBytes,
		rawBytes: list.reduce( ( t, r ) => t + r.raw, 0 ),
		byType,
		dom,
		resources: list.map( ( { url, type, status, encoding, transfer, raw } ) => ( { url, type, status, encoding, transfer, raw } ) ),
		ecoindex: ecoindex( dom.nodes, transferBytes / 1024, list.length ),
	};
}

const median = ( values ) => {
	const s = [ ...values ].sort( ( a, b ) => a - b );
	return s.length % 2 ? s[ ( s.length - 1 ) / 2 ] : ( s[ s.length / 2 - 1 ] + s[ s.length / 2 ] ) / 2;
};

/* ---------- Lighthouse (CLI via npx, même Chromium) ---------- */

function lighthouseOnce( chromePath, outFile ) {
	const result = spawnSync(
		'npx',
		[
			'--yes',
			`lighthouse@${ LIGHTHOUSE_VERSION }`,
			opts.url,
			'--chrome-flags=--headless=new',
			'--only-categories=performance',
			'--output=json',
			`--output-path=${ outFile }`,
			'--quiet',
		],
		// Lighthouse 13 ignore --chrome-path : son lanceur (chrome-launcher) lit CHROME_PATH.
		{ encoding: 'utf8', timeout: 180_000, env: { ...process.env, CHROME_PATH: chromePath } }
	);
	if ( result.status !== 0 ) {
		const cause = ( result.stderr || '' ).split( '\n' ).find( ( l ) => /error/i.test( l ) ) ?? 'aucun message';
		throw new Error( `Lighthouse a échoué (code ${ result.status }) sur ${ opts.url } : ${ cause }` );
	}
	const lhr = JSON.parse( readFileSync( outFile, 'utf8' ) );
	const a = lhr.audits;
	return {
		performance: Math.round( lhr.categories.performance.score * 100 ),
		fcpMs: Math.round( a[ 'first-contentful-paint' ].numericValue ),
		lcpMs: Math.round( a[ 'largest-contentful-paint' ].numericValue ),
		tbtMs: Math.round( a[ 'total-blocking-time' ].numericValue ),
		cls: a[ 'cumulative-layout-shift' ].numericValue,
		totalByteWeight: a[ 'total-byte-weight' ].numericValue,
		formFactor: lhr.configSettings.formFactor,
		throttling: lhr.configSettings.throttlingMethod,
		lighthouseVersion: lhr.lighthouseVersion,
		report: outFile.replace( `${ ROOT }/`, '' ),
	};
}

/* ---------- Programme ---------- */

const browser = await chromium.launch();
const chromiumVersion = browser.version();
let measured;
try {
	await measureOnce( browser ); // Préchauffage non compté.
	measured = [];
	for ( let i = 0; i < runs; i++ ) {
		measured.push( await measureOnce( browser ) );
	}
} finally {
	await browser.close();
}

let lighthouse = null;
if ( process.env.BENCH_LIGHTHOUSE !== '0' ) {
	const dir = join( REPORTS, 'lighthouse-bench' );
	mkdirSync( dir, { recursive: true } );
	const lhRuns = [];
	for ( let i = 0; i < runs; i++ ) {
		lhRuns.push( lighthouseOnce( chromium.executablePath(), join( dir, `${ opts.label }-${ i + 1 }.json` ) ) );
	}
	const med = lhRuns.find( ( r ) => r.performance === median( lhRuns.map( ( x ) => x.performance ) ) ) ?? lhRuns[ 0 ];
	lighthouse = { median: med, runs: lhRuns };
}

const date = new Date().toISOString().slice( 0, 10 );
const outFile = join( REPORTS, `bench-${ date }.json` );
const report = existsSync( outFile ) ? JSON.parse( readFileSync( outFile, 'utf8' ) ) : { date, variants: {} };
report.conditions = {
	url: opts.url,
	viewport: '1920x1080',
	cache: 'désactivé, nouveau contexte par chargement',
	warmup: '1 chargement non compté',
	runs,
	chromium: chromiumVersion,
	node: process.version,
	ecoindexFormula: 'ecoindex-compute 5.9.0 (quantiles et seuils repris tels quels), DOM = getElementsByTagName("*")',
};
const stable = new Set( measured.map( ( m ) => m.transferBytes ) ).size === 1;
report.variants[ opts.label ] = {
	measuredAt: new Date().toISOString(),
	versions,
	stableAcrossRuns: stable,
	median: {
		transferBytes: median( measured.map( ( m ) => m.transferBytes ) ),
		rawBytes: median( measured.map( ( m ) => m.rawBytes ) ),
		requests: median( measured.map( ( m ) => m.requests ) ),
		domNodes: median( measured.map( ( m ) => m.dom.nodes ) ),
	},
	runs: measured,
	lighthouse,
};
mkdirSync( REPORTS, { recursive: true } );
writeFileSync( outFile, `${ JSON.stringify( report, null, '\t' ) }\n` );

const m = measured[ 0 ];
console.log(
	`${ opts.label } : ${ ( m.transferBytes / 1024 ).toFixed( 1 ) } ko transférés, ${ ( m.rawBytes / 1024 ).toFixed( 1 ) } ko bruts, ` +
		`${ m.requests } requêtes (${ m.thirdPartyRequests } tierces), DOM ${ m.dom.nodes }, EcoIndex ${ m.ecoindex.score } ${ m.ecoindex.grade }` +
		( lighthouse ? `, Lighthouse ${ lighthouse.median.performance }` : '' ) +
		( stable ? '' : ' (poids variable entre chargements : voir runs)' )
);
