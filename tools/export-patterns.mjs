#!/usr/bin/env node
// Demande à WP-CLI (conteneur Docker) le contenu rendu de chaque pattern fluffless/*
// en fr_CA et en en_CA, puis l'écrit dans tools/reports/patterns.json.
// Usage : node tools/export-patterns.mjs  (le WordPress local doit tourner : npm run wp:up)
import { spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const OUTPUT = join( root, 'tools', 'reports', 'patterns.json' );
const LOCALES = [ 'fr_CA', 'en_CA' ];
const MARKER = /<<<FLUFFLESS_JSON\n([\s\S]*)\nFLUFFLESS_JSON>>>/;
const php = readFileSync( join( root, 'tools', 'export-patterns.php' ) );

function fail( message ) {
	console.error( message );
	process.exit( 2 );
}

// Un processus par langue : voir l'en-tête de export-patterns.php.
function exportLocale( locale ) {
	const run = spawnSync(
		'docker',
		[ 'compose', 'run', '--rm', '-T', 'cli', 'wp', 'eval-file', '-', locale ],
		{ cwd: root, input: php, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }
	);
	if ( run.error || run.status !== 0 ) {
		fail( `Échec de WP-CLI pour ${ locale } (code ${ run.status }). Le WordPress local tourne-t-il ? (npm run wp:up)\n${ run.error?.message ?? run.stderr }` );
	}
	const match = run.stdout.match( MARKER );
	if ( ! match ) {
		fail( `Sortie WP-CLI sans marqueurs JSON pour ${ locale }. Début reçu : ${ run.stdout.slice( 0, 500 ) }` );
	}
	const patterns = JSON.parse( match[ 1 ] );
	const entries = Object.entries( patterns ?? {} );
	if ( entries.length === 0 ) {
		fail( `Aucun pattern fluffless/* exporté pour ${ locale } : le thème est-il actif ?` );
	}
	const bad = entries.find( ( [ , content ] ) => typeof content !== 'string' || content.trim() === '' );
	if ( bad ) {
		fail( `Contenu vide ou non textuel pour ${ bad[ 0 ] } (${ locale }).` );
	}
	return patterns;
}

const data = Object.fromEntries( LOCALES.map( ( locale ) => [ locale, exportLocale( locale ) ] ) );
mkdirSync( dirname( OUTPUT ), { recursive: true } );
writeFileSync( OUTPUT, JSON.stringify( data, null, '\t' ) );
const summary = LOCALES.map( ( l ) => `${ l } : ${ Object.keys( data[ l ] ).length }` ).join( ', ' );
console.log( `Patterns exportés (${ summary }) dans tools/reports/patterns.json` );
