#!/usr/bin/env node
// Reproduit sans navigateur la validation de blocs de l'éditeur (« Ce bloc contient du
// contenu inattendu ou non valide ») sur chaque pattern exporté par export-patterns.mjs.
// Les paquets @wordpress/* sont épinglés (package.json, « overrides ») sur la publication
// Gutenberg la plus proche de celle livrée avec WP 7.1 : block-library 10.3.0 du 2026-07-29.
// Codes de sortie : 0 aucun bloc invalide, 1 bloc invalide, 2 outillage ou contrôle raté.
// Usage : node tools/validate-blocks.mjs [chemin/patterns.json]
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { format } from 'node:util';
import { createRequire } from 'node:module';
// jsdom et non happy-dom : hpq, qui lit les attributs « source », teste
// hasOwnProperty() sur NamedNodeMap, ce que happy-dom ne permet pas (href et class
// ressortaient vides, d'où de faux blocs invalides).
import { JSDOM } from 'jsdom';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const INPUT = process.argv[ 2 ] ?? join( root, 'tools', 'reports', 'patterns.json' );
const REPORT = join( root, 'tools', 'reports', 'blocks-validation.json' );
const BLOCKS_DIR = join( root, 'packages', 'fluffless-suite', 'blocks' );
const MAX_DEPTH = 50; // Aucun pattern n'imbrique autant : au-delà, c'est une boucle.
const EXCERPT = 90; // Caractères affichés de part et d'autre du premier écart.
const LEVELS = [ 'log', 'info', 'warn', 'error' ];

// L'analyse d'attributs (source: html) et certains modules de block-library lisent le DOM.
const { window: win } = new JSDOM( '<!doctype html><html><body></body></html>', { url: 'http://localhost/', pretendToBeVisual: true } );
for ( const key of [ 'window', 'document', 'navigator', 'DOMParser', 'Node', 'Element', 'HTMLElement',
	'getComputedStyle', 'requestAnimationFrame', 'cancelAnimationFrame', 'matchMedia', 'MutationObserver' ] ) {
	Object.defineProperty( globalThis, key, { value: key === 'window' ? win : win[ key ], configurable: true, writable: true } );
}

// Les journaux de validation et de dépréciation passent par console : on les capte.
// Les objets (type de bloc complet) sont remplacés pour garder des lignes lisibles.
const captured = [];
const original = { ...console };
const toText = ( args ) => format( ...args.map( ( a ) => ( a && typeof a === 'object' ? '[objet]' : a ) ) );
const mute = () => LEVELS.forEach( ( level ) => {
	console[ level ] = ( ...args ) => captured.push( toText( args ) );
} );
const unmute = () => Object.assign( console, original );
const release = () => captured.splice( 0 );

// Build CommonJS : le build ESM importe du JSON sans attribut « type », refusé par Node 22+.
const require = createRequire( import.meta.url );
mute();
const { parse, serialize, validateBlock, getSaveContent, registerBlockType } = require( '@wordpress/blocks' );
require( '@wordpress/block-library' ).registerCoreBlocks();
// Blocs dynamiques de l'extension : save() ne produit rien, le rendu se fait en PHP (render.php).
for ( const dir of readdirSync( BLOCKS_DIR, { withFileTypes: true } ).filter( ( d ) => d.isDirectory() ) ) {
	registerBlockType( JSON.parse( readFileSync( join( BLOCKS_DIR, dir.name, 'block.json' ), 'utf8' ) ), { edit: () => null, save: () => null } );
}
unmute();
const bootLogs = release();

function parseQuietly( content ) {
	mute();
	try {
		return parse( content );
	} finally {
		unmute();
	}
}

function excerptAround( text, index ) {
	const start = Math.max( 0, index - EXCERPT );
	const end = index + EXCERPT;
	return ( start > 0 ? '…' : '' ) + text.slice( start, end ).replace( /\s+/g, ' ' ) + ( end < text.length ? '…' : '' );
}

// Balisage produit par save() et balisage reçu, centrés sur le premier caractère qui diffère.
function diffExcerpts( block ) {
	const expected = getSaveContent( block.name, block.attributes, block.innerBlocks );
	const received = block.originalContent ?? '';
	let at = 0;
	while ( at < Math.min( expected.length, received.length ) && expected[ at ] === received[ at ] ) {
		at++;
	}
	return { expected: excerptAround( expected, at ), received: excerptAround( received, at ) };
}

// Parcourt l'arbre et classe chaque bloc : invalide, bloc inconnu, valide par dépréciation.
function inspect( blocks, path, findings, depth = 0 ) {
	if ( depth > MAX_DEPTH ) {
		throw new Error( `Profondeur > ${ MAX_DEPTH } à ${ path } : arbre de blocs anormal.` );
	}
	blocks.forEach( ( block, i ) => {
		const where = `${ path }/${ i }:${ block.name }`;
		findings.total++;
		if ( block.name === 'fluffless/icone' ) {
			findings.icone++;
		}
		if ( block.name === 'core/missing' ) {
			findings.invalid.push( { where, block: block.attributes.originalName, reason: 'bloc non enregistré' } );
		} else if ( ! block.isValid ) {
			const issues = ( block.validationIssues ?? [] ).map( ( { args } ) => toText( args ).split( '\n' )[ 0 ] );
			findings.invalid.push( { where, block: block.name, issues, ...diffExcerpts( block ) } );
		} else if ( ! validateBlock( block )[ 0 ] ) {
			// Valide pour parse() mais pas pour save() actuel : migré via une dépréciation.
			findings.deprecated.push( { where, block: block.name, ...diffExcerpts( block ) } );
		}
		inspect( block.innerBlocks, where, findings, depth + 1 );
	} );
}

// Contrôles de cohérence : le validateur doit détecter un bloc faux, repérer une migration
// par dépréciation et accepter l'icône auto-fermante.
function selfCheck() {
	const failures = [];
	if ( ! parseQuietly( '<!-- wp:paragraph --><p>ok</p><!-- /wp:paragraph -->' )[ 0 ].isValid ) {
		failures.push( 'un paragraphe conforme est jugé invalide' );
	}
	if ( parseQuietly( '<!-- wp:heading {"level":3} --><h2 class="wp-block-heading">ko</h2><!-- /wp:heading -->' )[ 0 ].isValid ) {
		failures.push( 'un titre h2 déclaré de niveau 3 passe la validation' );
	}
	// Attribut « source » lu dans le HTML (a[href] vers url) : garde-fou contre un DOM incomplet.
	const [ button ] = parseQuietly( '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.org/">ok</a></div><!-- /wp:button -->' );
	if ( ! button.isValid || button.attributes.url !== 'https://example.org/' ) {
		failures.push( `attribut source mal lu par le DOM (url reçue : ${ button.attributes.url })` );
	}
	// Accepté par une dépréciation de core/paragraph : doit ressortir comme tel, pas comme conforme.
	const [ migrated ] = parseQuietly( '<!-- wp:paragraph --><div>ko</div><!-- /wp:paragraph -->' );
	if ( ! migrated.isValid || validateBlock( migrated )[ 0 ] ) {
		failures.push( 'un paragraphe migré par dépréciation n\'est pas détecté comme tel' );
	}
	const iconeSource = '<!-- wp:fluffless/icone {"icon":"mail","textColor":"accent"} /-->';
	const [ icone ] = parseQuietly( iconeSource );
	if ( icone.name !== 'fluffless/icone' || ! icone.isValid ) {
		failures.push( 'fluffless/icone auto-fermant rejeté' );
	} else if ( serialize( [ icone ] ) !== iconeSource ) {
		failures.push( `fluffless/icone ne se resérialise pas à l'identique : ${ serialize( [ icone ] ) }` );
	}
	release();
	return failures;
}

const selfFailures = selfCheck();
if ( selfFailures.length ) {
	console.error( 'Contrôle de cohérence raté, résultats non fiables :\n- ' + selfFailures.join( '\n- ' ) );
	process.exit( 2 );
}

let data;
try {
	data = JSON.parse( readFileSync( INPUT, 'utf8' ) );
} catch ( error ) {
	console.error( `Lecture impossible de ${ INPUT } (${ error.message }). Lancer d'abord : node tools/export-patterns.mjs` );
	process.exit( 2 );
}

const report = { bootWarnings: bootLogs, locales: {} };
const totals = { patterns: 0, blocks: 0, icone: 0, invalid: 0, deprecated: 0 };

for ( const [ locale, patterns ] of Object.entries( data ) ) {
	report.locales[ locale ] = {};
	for ( const [ slug, content ] of Object.entries( patterns ) ) {
		const findings = { total: 0, icone: 0, invalid: [], deprecated: [] };
		inspect( parseQuietly( content ), slug, findings );
		// Migrations annoncées par parse() et avis de @wordpress/deprecated ; les échecs de
		// validation sont déjà décrits bloc par bloc ci-dessus.
		findings.logs = [ ...new Set( release().map( ( t ) => t.split( '\n' )[ 0 ] )
			.filter( ( t ) => /^Block successfully updated|\bdeprecated\b/i.test( t ) ) ) ];
		report.locales[ locale ][ slug ] = findings;

		totals.patterns++;
		totals.blocks += findings.total;
		totals.icone += findings.icone;
		totals.invalid += findings.invalid.length;
		totals.deprecated += findings.deprecated.length;

		const warnings = findings.deprecated.length + findings.logs.length;
		const status = findings.invalid.length ? 'ÉCHEC' : ( warnings ? 'avert' : 'ok   ' );
		const notes = [
			findings.invalid.length && `${ findings.invalid.length } invalide(s)`,
			warnings && `${ warnings } signal(s) de dépréciation`,
		].filter( Boolean );
		console.log( `${ status } ${ locale } ${ slug } (${ findings.total } blocs${ findings.icone ? `, ${ findings.icone } icône(s)` : '' })${ notes.length ? ' : ' + notes.join( ', ' ) : '' }` );
		for ( const item of findings.invalid ) {
			console.log( `  x ${ item.where } [${ item.block }] ${ item.reason ?? '' }` );
			( item.issues ?? [] ).forEach( ( issue ) => console.log( `    ${ issue }` ) );
			if ( item.expected !== undefined ) {
				console.log( `    attendu : ${ item.expected }\n    reçu    : ${ item.received }` );
			}
		}
		for ( const item of findings.deprecated ) {
			console.log( `  ! ${ item.where } valide seulement via une dépréciation (migré à l'insertion)` );
			console.log( `    attendu : ${ item.expected }\n    reçu    : ${ item.received }` );
		}
		findings.logs.forEach( ( text ) => console.log( `  ! ${ text }` ) );
	}
}

writeFileSync( REPORT, JSON.stringify( report, null, '\t' ) );
console.log( `\n${ totals.patterns } patterns (toutes langues), ${ totals.blocks } blocs dont ${ totals.icone } fluffless/icone, `
	+ `${ totals.invalid } invalide(s), ${ totals.deprecated } migré(s) par dépréciation. Rapport : tools/reports/blocks-validation.json` );
process.exit( totals.invalid > 0 ? 1 : 0 );
