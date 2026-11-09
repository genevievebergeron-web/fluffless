#!/usr/bin/env node
// Version unique de la suite. Source de vérité : le champ « version » du package.json racine.
// Usage :
//   node tools/version.mjs check          échoue (code 1) si un fichier porte une autre version
//   node tools/version.mjs set 0.2.0      écrit la version dans package.json puis la propage
// Cibles : en-tête Version de style.css (thème), en-tête Version et constante VERSION de
// fluffless-suite.php, fluffless-seo.php, fluffless-consent.php, champ « version » de chaque block.json sous packages/*/blocks/,
// et « Stable tag » du readme.txt de chaque extension (le readme du thème n'en porte pas : WordPress.org lit style.css).
// Les fichiers de traduction (.pot, .po) ne sont pas touchés : wp i18n make-pot les régénère.
import { readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const SEMVER = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/;

/**
 * Une cible = un fichier et les motifs qui encadrent la version (groupe 1 avant, groupe 2 après).
 * Chaque motif doit trouver exactement une occurrence : un en-tête disparu fait échouer le contrôle.
 */
function targets() {
	const list = [
		{ file: 'packages/fluffless/style.css', patterns: [ /^(Version:\s*)[^\s]+()$/m ] },
		{
			file: 'packages/fluffless-suite/fluffless-suite.php',
			patterns: [ /^( \* Version:\s*)[^\s]+()$/m, /^(const VERSION = ')[^']+(';)$/m ],
		},
		{ file: 'packages/fluffless-suite/readme.txt', patterns: [ /^(Stable tag:\s*)[^\s]+()$/m ] },
		{
			file: 'packages/fluffless-seo/fluffless-seo.php',
			patterns: [ /^( \* Version:\s*)[^\s]+()$/m, /^(const VERSION = ')[^']+(';)$/m ],
		},
		{ file: 'packages/fluffless-seo/readme.txt', patterns: [ /^(Stable tag:\s*)[^\s]+()$/m ] },
		{
			file: 'packages/fluffless-consent/fluffless-consent.php',
			patterns: [ /^( \* Version:\s*)[^\s]+()$/m, /^(const VERSION = ')[^']+(';)$/m ],
		},
		{ file: 'packages/fluffless-consent/readme.txt', patterns: [ /^(Stable tag:\s*)[^\s]+()$/m ] },
	];
	const packages = join( root, 'packages' );
	for ( const pkg of readdirSync( packages ) ) {
		const blocks = join( packages, pkg, 'blocks' );
		if ( ! existsSync( blocks ) ) {
			continue;
		}
		for ( const block of readdirSync( blocks ).sort() ) {
			const file = join( blocks, block, 'block.json' );
			if ( existsSync( file ) ) {
				list.push( { file: relative( root, file ), patterns: [ /^(\t"version": ")[^"]+(",?)$/m ] } );
			}
		}
	}
	return list;
}

function readVersion() {
	const version = JSON.parse( readFileSync( join( root, 'package.json' ), 'utf8' ) ).version;
	if ( typeof version !== 'string' || ! SEMVER.test( version ) ) {
		fail( `package.json : champ « version » absent ou invalide (reçu : ${ JSON.stringify( version ) }, attendu : 1.2.3).` );
	}
	return version;
}

function fail( message ) {
	console.error( message );
	process.exit( 1 );
}

/** Rend [versions trouvées] pour une cible ; null si un motif ne trouve pas exactement une occurrence. */
function found( text, pattern ) {
	const global = new RegExp( pattern.source, pattern.flags + 'g' );
	const matches = [ ...text.matchAll( global ) ];
	if ( matches.length !== 1 ) {
		return null;
	}
	return matches[ 0 ][ 0 ].slice( matches[ 0 ][ 1 ].length, matches[ 0 ][ 0 ].length - matches[ 0 ][ 2 ].length );
}

function check( version ) {
	const errors = [];
	let count = 0;
	for ( const { file, patterns } of targets() ) {
		const text = readFileSync( join( root, file ), 'utf8' );
		for ( const pattern of patterns ) {
			const value = found( text, pattern );
			count++;
			if ( null === value ) {
				errors.push( `${ file } : motif ${ pattern } introuvable ou en double.` );
			} else if ( value !== version ) {
				errors.push( `${ file } : ${ value } au lieu de ${ version }.` );
			}
		}
	}
	if ( errors.length ) {
		fail( `Versions divergentes (source : package.json = ${ version }). Corriger avec npm run version:set ${ version }\n- ${ errors.join( '\n- ' ) }` );
	}
	console.log( `OK : ${ count } emplacements à la version ${ version }.` );
}

function set( version ) {
	if ( ! SEMVER.test( version ?? '' ) ) {
		fail( `Version attendue au format 1.2.3 (reçu : ${ JSON.stringify( version ) }).` );
	}
	const pkgFile = join( root, 'package.json' );
	const pkg = readFileSync( pkgFile, 'utf8' );
	const nextPkg = pkg.replace( /^(\t"version": ")[^"]+(",)$/m, `$1${ version }$2` );
	if ( nextPkg === pkg && JSON.parse( pkg ).version !== version ) {
		fail( 'package.json : ligne « "version": "…", » introuvable (indentation par tabulation attendue).' );
	}
	writeFileSync( pkgFile, nextPkg );
	for ( const { file, patterns } of targets() ) {
		const path = join( root, file );
		let text = readFileSync( path, 'utf8' );
		for ( const pattern of patterns ) {
			if ( null === found( text, pattern ) ) {
				fail( `${ file } : motif ${ pattern } introuvable ou en double, rien n'a été écrit pour ce fichier.` );
			}
			text = text.replace( pattern, `$1${ version }$2` );
		}
		writeFileSync( path, text );
	}
	check( version );
}

const [ command, value ] = process.argv.slice( 2 );
if ( 'check' === command ) {
	check( readVersion() );
} else if ( 'set' === command ) {
	set( value );
} else {
	fail( 'Usage : node tools/version.mjs check | set <1.2.3>' );
}
