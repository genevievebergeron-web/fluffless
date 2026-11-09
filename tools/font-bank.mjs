#!/usr/bin/env node
// Banque de polices Fluffless : l'archive maîtresse vit dans Proton Drive (Fluffless/polices/),
// ce script la vérifie et en tire un site statique (fichiers WOFF2 + collection.json) à héberger
// sur canner.ca. Le module Polices télécharge ensuite depuis ce site au lieu de fonts.gstatic.com.
//
// Usage :
//   node tools/font-bank.mjs seed  <banque> <catalogue.json> [--download]
//   node tools/font-bank.mjs build <banque> [--out dist/font-bank]
//
// seed : crée <banque>/<slug>/font.json pour chaque famille du catalogue (export de
//        Fonts::catalogue()). Avec --download, récupère aussi le WOFF2 d'origine.
// build : vérifie chaque famille (fiche, licence, en-tête WOFF2, poids), copie les fichiers,
//         écrit collection.json et index.html, puis affiche l'empreinte SHA-256 de chaque fichier.
//
// Structure d'une banque :
//   <banque>/LICENCES/OFL-1.1.txt
//   <banque>/<slug>/font.json   { name, category, weight, stretch?, axes, designer, licence, origin }
//   <banque>/<slug>/<slug>.woff2
import { createHash } from 'node:crypto';
import { copyFileSync, existsSync, mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

export const MAX_BYTES = 200000;
export const LICENCES = { 'OFL-1.1': 'LICENCES/OFL-1.1.txt' };
const CATEGORIES = [ 'sans-serif', 'serif', 'display', 'monospace' ];
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const REQUIRED = [ 'name', 'category', 'weight', 'axes', 'designer', 'licence' ];

const escape = ( s ) => String( s ).replace( /[&<>"']/g, ( c ) => `&#${ c.charCodeAt( 0 ) };` );

/** Vérifie une famille ; renvoie la liste des problèmes (vide si elle est prête). */
export function check( bank, slug ) {
	const problems = [];
	if ( ! SLUG.test( slug ) ) {
		return [ `${ slug } : nom de dossier attendu en minuscules et tirets` ];
	}
	const metaPath = join( bank, slug, 'font.json' );
	const filePath = join( bank, slug, `${ slug }.woff2` );
	let meta = null;
	try {
		meta = JSON.parse( readFileSync( metaPath, 'utf8' ) );
	} catch {
		return [ `${ slug } : font.json absent ou illisible` ];
	}
	for ( const key of REQUIRED ) {
		if ( 'string' !== typeof meta[ key ] || '' === meta[ key ].trim() ) {
			problems.push( `${ slug } : champ « ${ key } » manquant dans font.json` );
		}
	}
	if ( meta.category && ! CATEGORIES.includes( meta.category ) ) {
		problems.push( `${ slug } : catégorie « ${ meta.category } » inconnue (${ CATEGORIES.join( ', ' ) })` );
	}
	if ( meta.licence && ! LICENCES[ meta.licence ] ) {
		problems.push( `${ slug } : licence « ${ meta.licence } » refusée ; la banque publique n'accepte que ${ Object.keys( LICENCES ).join( ', ' ) }` );
	} else if ( meta.licence && ! existsSync( join( bank, LICENCES[ meta.licence ] ) ) ) {
		problems.push( `${ slug } : texte de licence absent (${ LICENCES[ meta.licence ] })` );
	}
	if ( ! existsSync( filePath ) ) {
		problems.push( `${ slug } : fichier ${ slug }.woff2 absent` );
		return problems;
	}
	const bytes = statSync( filePath ).size;
	if ( bytes < 1 || bytes > MAX_BYTES ) {
		problems.push( `${ slug } : ${ bytes } octets, hors de la limite de ${ MAX_BYTES }` );
	}
	if ( 'wOF2' !== readFileSync( filePath ).subarray( 0, 4 ).toString( 'latin1' ) ) {
		problems.push( `${ slug } : ce fichier n'est pas un WOFF2` );
	}
	return problems;
}

/** Construit le site statique ; renvoie les familles publiées. */
export function build( bank, out ) {
	const slugs = readdirSync( bank, { withFileTypes: true } )
		.filter( ( d ) => d.isDirectory() && 'LICENCES' !== d.name && ! d.name.startsWith( '.' ) )
		.map( ( d ) => d.name )
		.sort();
	const problems = slugs.flatMap( ( slug ) => check( bank, slug ) );
	if ( 0 === slugs.length ) {
		problems.push( 'banque vide : aucun dossier de famille' );
	}
	if ( problems.length ) {
		const error = new Error( problems.join( '\n' ) );
		error.problems = problems;
		throw error;
	}
	mkdirSync( join( out, 'fonts' ), { recursive: true } );
	mkdirSync( join( out, 'LICENCES' ), { recursive: true } );
	const families = slugs.map( ( slug ) => {
		const meta = JSON.parse( readFileSync( join( bank, slug, 'font.json' ), 'utf8' ) );
		const data = readFileSync( join( bank, slug, `${ slug }.woff2` ) );
		copyFileSync( join( bank, slug, `${ slug }.woff2` ), join( out, 'fonts', `${ slug }.woff2` ) );
		return {
			slug,
			name: meta.name,
			category: meta.category,
			weight: meta.weight,
			...( meta.stretch ? { stretch: meta.stretch } : {} ),
			axes: meta.axes,
			designer: meta.designer,
			licence: meta.licence,
			...( meta.copyright ? { copyright: meta.copyright } : {} ),
			file: `fonts/${ slug }.woff2`,
			bytes: data.length,
			sha256: createHash( 'sha256' ).update( data ).digest( 'hex' ),
		};
	} );
	for ( const licence of new Set( families.map( ( f ) => f.licence ) ) ) {
		copyFileSync( join( bank, LICENCES[ licence ] ), join( out, LICENCES[ licence ] ) );
	}
	writeFileSync( join( out, 'collection.json' ), JSON.stringify( { version: 1, families }, null, '\t' ) + '\n' );
	const rows = families.map( ( f ) => `<tr><td>${ escape( f.name ) }</td><td>${ escape( f.designer ) }</td><td>${ ( f.bytes / 1000 ).toFixed( 1 ).replace( '.', ',' ) }&nbsp;ko</td><td><a href="${ escape( LICENCES[ f.licence ] ) }">${ escape( f.licence ) }</a></td></tr>` ).join( '\n' );
	writeFileSync(
		join( out, 'index.html' ),
		`<!doctype html>
<html lang="fr-CA">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Banque de polices Fluffless</title>
<style>body{font:1rem/1.5 system-ui,sans-serif;max-width:48rem;margin:2rem auto;padding:0 1rem;color:#1b1a17;background:#faf8f3}table{border-collapse:collapse;width:100%}td,th{text-align:left;padding:.4rem .6rem;border-bottom:1px solid #d8d2c4}</style>
<h1>Banque de polices Fluffless</h1>
<p>Polices libres utilisées par l’extension Fluffless Suite. Chaque site copie une seule fois le fichier dont il a besoin, puis l’héberge sur son propre domaine.</p>
<table><thead><tr><th>Famille</th><th>Conception</th><th>Taille</th><th>Licence</th></tr></thead><tbody>
${ rows }
</tbody></table>
<p><a href="collection.json">Liste complète des polices (collection.json)</a></p>
</html>
`
	);
	return families;
}

/** Crée les fiches font.json d'après le catalogue ; télécharge le WOFF2 d'origine sur demande. */
export async function seed( bank, catalogue, download = false ) {
	const created = [];
	for ( const [ slug, family ] of Object.entries( catalogue ) ) {
		const dir = join( bank, slug );
		mkdirSync( dir, { recursive: true } );
		const metaPath = join( dir, 'font.json' );
		if ( ! existsSync( metaPath ) ) {
			const meta = {
				name: family.name,
				category: family.category,
				weight: family.weight,
				...( family.stretch ? { stretch: family.stretch } : {} ),
				axes: family.axes,
				designer: family.designer,
				licence: 'OFL-1.1',
				origin: family.src,
			};
			writeFileSync( metaPath, JSON.stringify( meta, null, '\t' ) + '\n' );
			created.push( slug );
		}
		const filePath = join( dir, `${ slug }.woff2` );
		if ( download && ! existsSync( filePath ) ) {
			const response = await fetch( family.src );
			if ( ! response.ok ) {
				throw new Error( `${ slug } : téléchargement refusé (${ response.status })` );
			}
			writeFileSync( filePath, Buffer.from( await response.arrayBuffer() ) );
		}
	}
	return created;
}

const main = async ( argv ) => {
	const [ command, bankArg, ...rest ] = argv;
	if ( ! bankArg || ! [ 'seed', 'build' ].includes( command ) ) {
		console.error( 'Usage : node tools/font-bank.mjs seed <banque> <catalogue.json> [--download]\n        node tools/font-bank.mjs build <banque> [--out dist/font-bank]' );
		return 2;
	}
	const bank = resolve( bankArg );
	if ( 'seed' === command ) {
		const catalogue = JSON.parse( readFileSync( resolve( rest[ 0 ] ?? '' ), 'utf8' ) );
		const created = await seed( bank, catalogue, rest.includes( '--download' ) );
		console.log( `OK : ${ created.length } fiche(s) créée(s) dans ${ bank }.` );
		return 0;
	}
	const outIndex = rest.indexOf( '--out' );
	const out = resolve( outIndex >= 0 ? rest[ outIndex + 1 ] : 'dist/font-bank' );
	try {
		const families = build( bank, out );
		for ( const f of families ) {
			console.log( `${ f.slug }\t${ f.bytes }\t${ f.sha256 }` );
		}
		console.log( `OK : ${ families.length } famille(s) publiée(s) dans ${ out }.` );
		return 0;
	} catch ( error ) {
		console.error( `ÉCHEC :\n- ${ ( error.problems ?? [ error.message ] ).join( '\n- ' ) }` );
		return 1;
	}
};

if ( import.meta.url === `file://${ process.argv[ 1 ] }` ) {
	process.exitCode = await main( process.argv.slice( 2 ) );
}
