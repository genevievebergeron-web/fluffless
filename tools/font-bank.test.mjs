#!/usr/bin/env node
// Test hors réseau de tools/font-bank.mjs : banque factice (fichier à en-tête WOFF2), puis
// vérification des refus (licence, poids, en-tête, fiche).
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { build, check, MAX_BYTES } from './font-bank.mjs';

const root = mkdtempSync( join( tmpdir(), 'font-bank-' ) );
const fixture = join( root, 'fixture.woff2' );
writeFileSync( fixture, Buffer.concat( [ Buffer.from( 'wOF2' ), Buffer.alloc( 2048, 7 ) ] ) );
const bank = join( root, 'banque' );
const out = join( root, 'site' );
const meta = { name: 'Fira Code', category: 'monospace', weight: '300 700', axes: 'wght 300-700', designer: 'Nikita Prokopov', licence: 'OFL-1.1' };
const family = ( slug, data = meta ) => {
	mkdirSync( join( bank, slug ), { recursive: true } );
	writeFileSync( join( bank, slug, 'font.json' ), JSON.stringify( data ) );
};
let checks = 0;
const ok = ( fn ) => {
	fn();
	checks++;
};

try {
	mkdirSync( join( bank, 'LICENCES' ), { recursive: true } );
	writeFileSync( join( bank, 'LICENCES', 'OFL-1.1.txt' ), 'SIL Open Font License 1.1' );
	family( 'fira-code' );
	copyFileSync( fixture, join( bank, 'fira-code', 'fira-code.woff2' ) );
	ok( () => assert.deepEqual( check( bank, 'fira-code' ), [] ) );

	const families = build( bank, out );
	const data = readFileSync( fixture );
	ok( () => assert.equal( families.length, 1 ) );
	ok( () => assert.equal( families[ 0 ].sha256, createHash( 'sha256' ).update( data ).digest( 'hex' ) ) );
	ok( () => assert.equal( families[ 0 ].bytes, data.length ) );
	ok( () => assert.ok( existsSync( join( out, 'fonts', 'fira-code.woff2' ) ) ) );
	ok( () => assert.ok( existsSync( join( out, 'LICENCES', 'OFL-1.1.txt' ) ) ) );
	const collection = JSON.parse( readFileSync( join( out, 'collection.json' ), 'utf8' ) );
	ok( () => assert.equal( collection.families[ 0 ].file, 'fonts/fira-code.woff2' ) );
	ok( () => assert.match( readFileSync( join( out, 'index.html' ), 'utf8' ), /Fira Code/ ) );

	family( 'marque', { ...meta, licence: 'Commerciale' } );
	copyFileSync( fixture, join( bank, 'marque', 'marque.woff2' ) );
	ok( () => assert.match( check( bank, 'marque' ).join(), /licence « Commerciale » refusée/ ) );

	family( 'lourde' );
	writeFileSync( join( bank, 'lourde', 'lourde.woff2' ), Buffer.concat( [ Buffer.from( 'wOF2' ), Buffer.alloc( MAX_BYTES ) ] ) );
	ok( () => assert.match( check( bank, 'lourde' ).join(), /hors de la limite/ ) );

	family( 'deguisee' );
	writeFileSync( join( bank, 'deguisee', 'deguisee.woff2' ), '<!doctype html>' );
	ok( () => assert.match( check( bank, 'deguisee' ).join(), /pas un WOFF2/ ) );

	family( 'incomplete', { name: 'Sans fiche' } );
	ok( () => assert.match( check( bank, 'incomplete' ).join(), /champ « designer » manquant/ ) );
	ok( () => assert.match( check( bank, 'incomplete' ).join(), /fichier incomplete\.woff2 absent/ ) );
	ok( () => assert.match( check( bank, 'Mauvais_Nom' ).join(), /minuscules et tirets/ ) );

	ok( () => assert.throws( () => build( bank, join( root, 'refus' ) ), ( e ) => 4 <= e.problems.length ) );
	ok( () => assert.ok( ! existsSync( join( root, 'refus' ) ) ) );
	console.log( `OK : font-bank, ${ checks } vérifications.` );
} finally {
	rmSync( root, { recursive: true, force: true } );
}
