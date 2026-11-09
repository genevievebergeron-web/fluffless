#!/usr/bin/env node
// Échoue si une traduction fr_CA contient une espace ordinaire (U+0020) là où la
// typographie québécoise exige une insécable : avant « : ; ? ! », ou entre un
// nombre et une unité (9 h, 6 min, 2,4 Mo, 92 %).
import { readFileSync } from 'node:fs';

const FILES = [
	'packages/fluffless/languages/fr_CA.po',
	'packages/fluffless-suite/languages/fluffless-suite-fr_CA.po',
	'packages/fluffless-seo/languages/fluffless-seo-fr_CA.po',
	'packages/fluffless-consent/languages/fluffless-consent-fr_CA.po',
];
const RULES = [
	[ / [:;?!]/, 'espace ordinaire avant : ; ? ! (attendu U+00A0 avant « : », U+202F avant « ; ? ! »)' ],
	[ /\d (h|min|s|ko|Mo|Go|%|ans?)(?![\p{L}])/u, 'espace ordinaire entre un nombre et son unité (attendu U+00A0)' ],
	// Décision du 14 sept. 2026 : « ko » en français (symbole OQLF), « KB » reste dans les chaînes anglaises.
	[ /(^|[\s \d"])Ko(?![\p{L}])/u, 'symbole « Ko » : écrire « ko » en français' ],
];

let failures = 0;
for ( const file of FILES ) {
	const lines = readFileSync( file, 'utf8' ).split( '\n' );
	let inMsgstr = false;
	let seenHeader = false;
	lines.forEach( ( line, i ) => {
		inMsgstr = line.startsWith( 'msgstr' ) || ( inMsgstr && line.startsWith( '"' ) );
		// Le premier msgstr est l'en-tête du fichier (Plural-Forms, etc.) : hors contrôle.
		if ( line.startsWith( 'msgstr' ) && ! seenHeader ) {
			seenHeader = true;
			inMsgstr = false;
		}
		if ( ! inMsgstr ) {
			return;
		}
		for ( const [ pattern, message ] of RULES ) {
			if ( pattern.test( line ) ) {
				console.error( `${ file }:${ i + 1 } : ${ message }\n  ${ line }` );
				failures++;
			}
		}
	} );
}

if ( failures > 0 ) {
	console.error( `${ failures } écart(s) typographique(s) dans les traductions fr_CA.` );
	process.exit( 1 );
}
console.log( 'OK : espaces insécables conformes dans les traductions fr_CA.' );
