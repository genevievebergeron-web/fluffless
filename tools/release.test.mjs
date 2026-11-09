// tools/release.sh, construit sur une copie fraîche des sources suivies par git (SKIP_CHECKS=1,
// RELEASE_UNSIGNED=1) : dist/public/ ne contient que les deux paquets gratuits et leur SHA256SUMS
// (F-telecharger-02), sans rien de la Suite ni de SEO ; deux constructions des mêmes sources, à des
// dates et sous des fuseaux différents, donnent les mêmes octets (F-telecharger-04).
// Usage : node --test tools/release.test.mjs   (demande rsync, zip, unzip, jq et shasum)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const version = JSON.parse( readFileSync( join( root, 'package.json' ), 'utf8' ) ).version;
const temporaires = [];

// La copie n'a pas de dépôt git : la date des fichiers du zip vient de SOURCE_DATE_EPOCH.
const epoque = '1767225600'; // 2026-01-01T00:00:00Z

// Copie des fichiers suivis (packages, tools, package.json) : dates de copie, pas celles des sources.
function copie() {
	const dossier = mkdtempSync( join( tmpdir(), 'fluffless-release-' ) );
	temporaires.push( dossier );
	const liste = execFileSync( 'git', [ 'ls-files', '-z', 'packages', 'tools', 'package.json' ], { cwd: root } );
	execFileSync( 'rsync', [ '-r', '--from0', '--files-from=-', './', `${ dossier }/` ], { cwd: root, input: liste } );
	return dossier;
}

function construire( dossier, env = {} ) {
	execFileSync( 'bash', [ 'tools/release.sh' ], {
		cwd: dossier,
		env: { ...process.env, SKIP_CHECKS: '1', RELEASE_UNSIGNED: '1', FLUFFLESS_SIGNING_KEY: '', SOURCE_DATE_EPOCH: epoque, ...env },
		stdio: 'pipe',
	} );
	return join( dossier, 'dist' );
}

process.on( 'exit', () => temporaires.forEach( ( d ) => rmSync( d, { recursive: true, force: true } ) ) );

const gratuits = [ `fluffless-${ version }.zip`, `fluffless-consent-${ version }.zip` ];
const dist = construire( copie() );
const publique = join( dist, 'public' );

test( 'dist/public/ contient exactement les deux paquets gratuits et SHA256SUMS', () => {
	assert.deepEqual( readdirSync( publique ).sort(), [ 'SHA256SUMS', ...gratuits ].sort() );
} );

test( 'dist/public/SHA256SUMS : deux lignes au nom nu, qui passent shasum -c', () => {
	const lignes = readFileSync( join( publique, 'SHA256SUMS' ), 'utf8' ).trimEnd().split( '\n' );
	assert.equal( lignes.length, 2 );
	for ( const ligne of lignes ) {
		assert.match( ligne, /^[0-9a-f]{64} {2}fluffless(?:-consent)?-\d+\.\d+\.\d+\.zip$/ );
	}
	const sortie = execFileSync( 'shasum', [ '-a', '256', '-c', 'SHA256SUMS' ], { cwd: publique, encoding: 'utf8' } );
	assert.equal( ( sortie.match( /: OK$/gm ) || [] ).length, 2 );
} );

test( 'les paquets gratuits ne contiennent ni Suite, ni SEO, ni signature, ni update.json', () => {
	for ( const zip of gratuits ) {
		const contenu = execFileSync( 'unzip', [ '-Z1', join( publique, zip ) ], { encoding: 'utf8' } );
		assert.doesNotMatch( contenu, /fluffless-suite|fluffless-seo|\.sig$|update\.json/m, zip );
	}
} );

test( 'deux constructions des mêmes sources donnent les mêmes octets', () => {
	// 2,1 s : la seconde copie reçoit d'autres dates de fichier (les dates DOS vont par pas de 2 s).
	Atomics.wait( new Int32Array( new SharedArrayBuffer( 4 ) ), 0, 0, 2100 );
	const autre = construire( copie(), { TZ: 'Asia/Tokyo' } );
	const somme = ( f ) => createHash( 'sha256' ).update( readFileSync( f ) ).digest( 'hex' );
	for ( const zip of readdirSync( dist ).filter( ( f ) => f.endsWith( '.zip' ) ) ) {
		assert.equal( somme( join( autre, zip ) ), somme( join( dist, zip ) ), zip );
	}
} );
