#!/usr/bin/env node
/**
 * Génère le sous-ensemble d'icônes de réseaux du bloc fluffless/reseaux.
 *
 * Source : paquet npm simple-icons (devDependency épinglée, licence CC0-1.0).
 * Sorties :
 *   packages/fluffless-suite/blocks/reseaux/icons.php  (slug -> titre et tracé du SVG 24 x 24)
 *   packages/fluffless-suite/blocks/reseaux/LICENSE.md (CC0 de simple-icons)
 *
 * LinkedIn est absent de simple-icons (retiré à la demande de la marque) : le bloc
 * l'affiche en texte. Le courriel reprend l'icône « mail » de Lucide, déjà livrée par fluffless/icone.
 *
 * Usage : npm run icons:social
 * Échoue (code 1) si un slug listé manque dans la version installée ou si un tracé
 * contient un caractère hors liste blanche.
 */
import { readFileSync, writeFileSync, copyFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire( import.meta.url );
const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const siDir = dirname( require.resolve( 'simple-icons' ) ); // pas de ./package.json dans ses exports
const outDir = join( root, 'packages/fluffless-suite/blocks/reseaux' );

/** Réseaux livrés, dans l'ordre de Footer::NETWORKS (le bloc suit l'ordre saisi par la personne). */
const SLUGS = [ 'instagram', 'facebook', 'youtube', 'tiktok', 'bluesky', 'mastodon', 'threads', 'pinterest', 'vimeo', 'github', 'behance', 'dribbble', 'rss' ];
const SAFE_PATH = /^[0-9A-Za-z .,-]+$/;

const icons = require( 'simple-icons' );
const version = JSON.parse( readFileSync( join( siDir, 'package.json' ), 'utf8' ) ).version;
const bySlug = new Map( Object.values( icons ).filter( ( i ) => i && typeof i.slug === 'string' ).map( ( i ) => [ i.slug, i ] ) );

const errors = [];
const rows = [];
for ( const slug of SLUGS ) {
	const icon = bySlug.get( slug );
	if ( ! icon ) {
		errors.push( `${ slug } : absent de simple-icons ${ version }` );
		continue;
	}
	if ( ! SAFE_PATH.test( icon.path ) ) {
		errors.push( `${ slug } : tracé refusé (caractère hors liste blanche)` );
		continue;
	}
	rows.push( `\t'${ slug }' => array( 'title' => '${ icon.title.replace( /[\\']/g, '\\$&' ) }', 'path' => '${ icon.path }' ),` );
}

if ( errors.length > 0 ) {
	console.error( `build-social-icons : ${ errors.length } erreur(s)\n  ${ errors.join( '\n  ' ) }` );
	process.exit( 1 );
}

const php = [
	'<?php',
	'/**',
	` * Fichier généré par tools/build-social-icons.mjs depuis simple-icons ${ version }. Ne pas modifier à la main.`,
	' * Licence des tracés : CC0-1.0, voir LICENSE.md dans ce dossier. Les marques restent à leurs propriétaires.',
	' *',
	' * @package Fluffless_Suite',
	' */',
	'',
	'// phpcs:ignoreFile -- fichier généré.',
	'',
	'return array(',
	...rows,
	');',
	'',
].join( '\n' );

writeFileSync( join( outDir, 'icons.php' ), php );
copyFileSync( join( siDir, 'LICENSE.md' ), join( outDir, 'LICENSE.md' ) );
console.log( `build-social-icons : ${ rows.length } icônes, icons.php ${ Buffer.byteLength( php ) } octets (simple-icons ${ version })` );
