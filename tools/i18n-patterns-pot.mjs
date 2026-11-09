#!/usr/bin/env node
// Ajoute au .pot de Fluffless Suite les titres et descriptions de ses patterns.
// wp i18n make-pot ne lit les en-têtes de patterns (Title, Description) que dans un thème ;
// Suite les traduit pourtant avec les contextes « Pattern title » et « Pattern description »
// (src/Presentation/Patterns.php), comme le cœur le fait pour un thème.
// Usage : node tools/i18n-patterns-pot.mjs (lancé par npm run i18n:pot, après make-pot).
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const pkg = join( root, 'packages', 'fluffless-suite' );
const potFile = join( pkg, 'languages', 'fluffless-suite.pot' );
const HEADERS = [
	[ 'Title', 'Pattern title' ],
	[ 'Description', 'Pattern description' ],
];
// Un en-tête de pattern tient sur une ligne ; au-delà, c'est un fichier mal formé.
const MAX_HEADER = 500;

const quote = ( s ) => '"' + s.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ) + '"';

const pot = readFileSync( potFile, 'utf8' );
if ( ! pot.startsWith( '# ' ) && ! pot.startsWith( 'msgid' ) ) {
	throw new Error( `${ potFile } : .pot illisible, lancer d'abord npm run i18n:pot:tools.` );
}

// Entrées déjà présentes (contexte + chaîne) : le script peut tourner deux fois sans doublon.
const known = new Set();
for ( const [ , ctx, id ] of pot.matchAll( /^msgctxt ("(?:[^"\\]|\\.)*")\nmsgid ("(?:[^"\\]|\\.)*")$/gm ) ) {
	known.add( ctx + id );
}

const entries = new Map();
const files = readdirSync( join( pkg, 'patterns' ) ).filter( ( f ) => f.endsWith( '.php' ) ).sort();
for ( const file of files ) {
	const head = readFileSync( join( pkg, 'patterns', file ), 'utf8' ).slice( 0, 4096 );
	for ( const [ header, context ] of HEADERS ) {
		const match = head.match( new RegExp( `^\\s*\\*\\s*${ header }:\\s*(.+?)\\s*$`, 'm' ) );
		if ( ! match ) {
			if ( 'Title' === header ) {
				throw new Error( `patterns/${ file } : en-tête Title absent.` );
			}
			continue;
		}
		if ( match[ 1 ].length > MAX_HEADER ) {
			throw new Error( `patterns/${ file } : en-tête ${ header } de ${ match[ 1 ].length } caractères (plafond ${ MAX_HEADER }).` );
		}
		const key = quote( context ) + quote( match[ 1 ] );
		if ( known.has( key ) ) {
			continue;
		}
		const entry = entries.get( key ) ?? { context, text: match[ 1 ], refs: [] };
		entry.refs.push( `patterns/${ file }` );
		entries.set( key, entry );
	}
}

const block = [ ...entries.values() ]
	.map( ( e ) => `#: ${ e.refs.join( ' ' ) }\nmsgctxt ${ quote( e.context ) }\nmsgid ${ quote( e.text ) }\nmsgstr ""\n` )
	.join( '\n' );
writeFileSync( potFile, pot.replace( /\n*$/, '\n' ) + ( block ? '\n' + block : '' ) );
console.log( `OK : ${ entries.size } en-tête(s) de pattern ajouté(s) à ${ potFile.slice( root.length + 1 ) } (${ files.length } patterns lus).` );
