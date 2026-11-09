#!/usr/bin/env node
// Veille d'entretien (docs/VEILLE.md) : vérifications automatiques, sans télécharger de fichier.
// Usage : npm run veille [-- --fontshare]
//   --fontshare  télécharge aussi les 7 WOFF2 Fontshare (environ 251 ko) et compare taille et SHA-256.
// Signalements : lus seulement si FLUFFLESS_API_URL et FLUFFLESS_ADMIN_TOKEN_FILE (ou
// FLUFFLESS_ADMIN_TOKEN) sont définis ; le jeton passe à bin/admin.mjs sans être lu ici.
// Sortie : 0 si tout va bien, 1 s'il y a au moins une ligne ATTENTION.
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const fontshare = process.argv.includes( '--fontshare' );

// Version de MariaDB en production (PlanetHoster, voir docs/PLAN.md) : pas lisible dans le dépôt.
const PRODUCTION_MARIADB = '10.6';
// Signalements laissés trop longtemps sans suite (les statuts nouveau et vu ne sont jamais purgés).
const REPORT_DAYS = { nouveau: 7, vu: 30 };
// Fin de soutien à signaler d'avance.
const EOL_NOTICE_DAYS = 120;

let problems = 0;
const section = ( title ) => console.log( `\n== ${ title }` );
const ok = ( message ) => console.log( `  OK : ${ message }` );
const info = ( message ) => console.log( `  ${ message }` );
const warn = ( message ) => {
	problems++;
	console.log( `  ATTENTION : ${ message }` );
};
const read = ( path ) => readFileSync( `${ root }${ path }`, 'utf8' );
const timeout = () => AbortSignal.timeout( 20_000 );

async function head( url ) {
	try {
		const res = await fetch( url, { method: 'HEAD', signal: timeout() } );
		const length = res.headers.get( 'content-length' );
		return { status: res.status, length: length === null ? null : Number( length ) };
	} catch ( error ) {
		return { status: 0, error: error.cause?.code ?? error.name };
	}
}

async function json( url ) {
	const res = await fetch( url, { signal: timeout() } );
	if ( ! res.ok ) {
		throw new Error( `HTTP ${ res.status }` );
	}
	return res.json();
}

// Familles de Fonts.php : slug, octets, empreinte, adresse d'origine, licence.
function families() {
	const php = read( 'packages/fluffless-suite/src/Modules/Fonts.php' );
	const start = php.indexOf( 'private const FAMILIES = array(' );
	const block = php.slice( start, php.indexOf( '\n\t);', start ) );
	const list = [];
	for ( const [ , slug, body ] of block.matchAll( /\n\t\t'([a-z0-9-]+)'\s+=> array\(([\s\S]*?)\n\t\t\),/g ) ) {
		const field = ( name ) => body.match( new RegExp( `'${ name }'\\s+=> '([^']*)'` ) )?.[ 1 ];
		list.push( {
			slug,
			bytes: Number( body.match( /'bytes'\s+=> (\d+)/ )?.[ 1 ] ),
			sha256: field( 'sha256' ),
			src: field( 'src' ) ?? '',
			licence: field( 'licence' ),
		} );
	}
	return { list, bank: php.match( /const BANK\s+= '([^']+)'/ )?.[ 1 ] };
}

async function checkFonts() {
	section( 'Polices : adresses d\'origine (requêtes HEAD)' );
	const { list, bank } = families();
	if ( list.length === 0 ) {
		warn( 'aucune famille lue dans Fonts.php (format changé ?)' );
		return;
	}
	const origins = new Map( list.filter( ( f ) => f.src ).map( ( f ) => [ f.src, f ] ) );
	for ( const [ , slug, url ] of read( 'services/font-bank/build.mjs' ).matchAll( /^\t'?([a-z0-9-]+)'?: '(https:[^']+)',$/gm ) ) {
		if ( ! origins.has( url ) ) {
			origins.set( url, list.find( ( f ) => f.slug === slug ) ?? { slug } );
		}
	}
	for ( const [ url, family ] of origins ) {
		const res = await head( url );
		if ( res.status !== 200 ) {
			warn( `${ family.slug } : ${ url } répond ${ res.status || res.error }` );
		} else if ( res.length !== null && family.bytes && res.length !== family.bytes ) {
			warn( `${ family.slug } : ${ res.length } octets à l'origine, ${ family.bytes } attendus (fichier changé)` );
		}
	}
	ok( `${ origins.size } adresses vérifiées` );

	section( `Polices : banque ${ bank }` );
	try {
		const collection = await json( `${ bank }/collection.json` );
		const expected = new Map( list.map( ( f ) => [ f.slug, f ] ) );
		for ( const family of collection.families ) {
			if ( expected.get( family.slug )?.sha256 !== family.sha256 ) {
				warn( `${ family.slug } : empreinte de la banque différente de Fonts.php` );
			}
			const res = await head( `${ bank }/${ family.file }` );
			if ( res.status !== 200 || ( res.length !== null && res.length !== family.bytes ) ) {
				warn( `${ family.slug } : ${ family.file } répond ${ res.status || res.error }, ${ res.length } octets` );
			}
		}
		const ofl = list.filter( ( f ) => ! f.licence ).length;
		if ( collection.families.length !== ofl ) {
			warn( `${ collection.families.length } familles dans la banque, ${ ofl } familles OFL dans Fonts.php` );
		}
		ok( `${ collection.families.length } familles en ligne` );
	} catch ( error ) {
		warn( `collection.json illisible (${ error.message })` );
	}

	section( 'Polices : fichiers Fontshare' );
	if ( ! fontshare ) {
		info( 'Sauté : relancer avec --fontshare pour télécharger et comparer les empreintes (environ 251 ko).' );
		return;
	}
	for ( const family of list.filter( ( f ) => f.licence ) ) {
		try {
			const res = await fetch( family.src, { signal: timeout() } );
			const data = Buffer.from( await res.arrayBuffer() );
			const sha = createHash( 'sha256' ).update( data ).digest( 'hex' );
			if ( ! res.ok || data.length !== family.bytes || sha !== family.sha256 ) {
				warn( `${ family.slug } : HTTP ${ res.status }, ${ data.length } octets, empreinte ${ sha === family.sha256 ? 'conforme' : 'différente' }` );
			}
		} catch ( error ) {
			warn( `${ family.slug } : ${ error.message }` );
		}
	}
	ok( 'fichiers Fontshare comparés (rien n\'est gardé)' );
}

const cycleOf = ( range ) => range.match( /\d+(?:\.\d+)?/ )?.[ 0 ];
const newer = ( a, b ) => {
	const [ x, y ] = [ a, b ].map( ( v ) => v.split( '.' ).map( Number ) );
	return x[ 0 ] > y[ 0 ] || ( x[ 0 ] === y[ 0 ] && ( x[ 1 ] ?? 0 ) > ( y[ 1 ] ?? 0 ) );
};

async function checkEol( label, product, cycle ) {
	try {
		const row = ( await json( `https://endoflife.date/api/${ product }.json` ) ).find( ( r ) => r.cycle === cycle );
		if ( ! row ) {
			warn( `${ label } ${ cycle } : cycle inconnu d'endoflife.date` );
		} else if ( typeof row.eol === 'string' ) {
			const days = Math.round( ( Date.parse( row.eol ) - Date.now() ) / 86_400_000 );
			if ( days < 0 ) {
				warn( `${ label } ${ cycle } : fin de soutien depuis le ${ row.eol }` );
			} else if ( days < EOL_NOTICE_DAYS ) {
				warn( `${ label } ${ cycle } : fin de soutien le ${ row.eol } (dans ${ days } jours)` );
			} else {
				ok( `${ label } ${ cycle } soutenu jusqu'au ${ row.eol }` );
			}
		} else {
			( row.eol ? warn : ok )( `${ label } ${ cycle } : ${ row.eol ? 'fin de soutien' : 'soutenu' }` );
		}
	} catch ( error ) {
		warn( `${ label } : endoflife.date injoignable (${ error.message })` );
	}
}

async function checkVersions() {
	section( 'Versions minimales et de production' );
	await checkEol( 'PHP (minimum)', 'php', cycleOf( JSON.parse( read( 'composer.json' ) ).require.php ) );
	for ( const dir of [ '', 'services/api/', 'services/font-bank/' ] ) {
		await checkEol( `Node (${ dir || 'racine' })`, 'nodejs', cycleOf( JSON.parse( read( `${ dir }package.json` ) ).engines.node ) );
	}
	await checkEol( 'MariaDB (production)', 'mariadb', PRODUCTION_MARIADB );
	try {
		const tested = read( 'packages/fluffless/style.css' ).match( /^Tested up to: (.+)$/m )[ 1 ].trim();
		const latest = ( await json( 'https://endoflife.date/api/wordpress.json' ) )[ 0 ].cycle;
		( newer( latest, tested ) ? warn : ok )( `WordPress : dernière version ${ latest }, thème testé jusqu'à ${ tested }` );
	} catch ( error ) {
		warn( `WordPress : version non vérifiée (${ error.message })` );
	}
}

function run( command, args, cwd = root ) {
	const res = spawnSync( command, args, { cwd, encoding: 'utf8', timeout: 300_000 } );
	try {
		return JSON.parse( res.stdout || 'null' );
	} catch {
		return null;
	}
}

function checkDependencies() {
	section( 'Dépendances' );
	for ( const dir of [ '', 'services/api/', 'services/font-bank/' ] ) {
		const outdated = run( 'npm', [ 'outdated', '--json' ], `${ root }${ dir }` ) ?? {};
		const names = Object.entries( outdated ).map( ( [ name, v ] ) => `${ name } ${ v.current ?? '?' } → ${ v.latest }` );
		( names.length ? info : ok )( `npm ${ dir || 'racine' } : ${ names.length ? names.join( ', ' ) : 'à jour' }` );
	}
	const audit = run( 'npm', [ 'audit', '--omit=dev', '--json' ], `${ root }services/api` );
	const vulns = audit?.metadata?.vulnerabilities?.total;
	if ( vulns === undefined ) {
		warn( 'npm audit (services/api) sans réponse' );
	} else {
		( vulns ? warn : ok )( `npm audit (services/api) : ${ vulns } vulnérabilité(s)` );
	}
	const composer = run( 'docker', [ 'compose', 'run', '--rm', '-T', 'php-tools', 'composer', 'outdated', '--direct', '--format=json' ] );
	if ( composer === null ) {
		info( 'Composer sauté : Docker indisponible.' );
		return;
	}
	const names = ( composer.installed ?? [] ).map( ( p ) => `${ p.name } ${ p.version } → ${ p.latest }` );
	( names.length ? info : ok )( `Composer : ${ names.length ? names.join( ', ' ) : 'à jour' }` );
	const composerAudit = run( 'docker', [ 'compose', 'run', '--rm', '-T', 'php-tools', 'composer', 'audit', '--format=json' ] );
	const advisories = Object.values( composerAudit?.advisories ?? {} ).flat().length;
	( advisories ? warn : ok )( `composer audit : ${ advisories } avis de sécurité` );
}

async function checkApi() {
	const base = process.env.FLUFFLESS_API_URL ?? 'https://api.fluffless.dev';
	section( `Serveur Fluffless ${ base }` );
	try {
		const res = await fetch( new URL( '/health', base ), { signal: timeout() } );
		( res.ok ? ok : warn )( `/health répond ${ res.status }` );
	} catch ( error ) {
		warn( `/health injoignable (${ error.cause?.code ?? error.name })` );
	}
}

// Relecture des signalements « Signaler un fluff » : âge, et indices de pourriel ou de contenu piégé.
// Le texte est affiché échappé (JSON) : un caractère de contrôle ne peut pas agir sur le terminal.
function checkReports() {
	section( 'Signalements « Signaler un fluff »' );
	const env = process.env;
	if ( ! env.FLUFFLESS_API_URL || ! ( env.FLUFFLESS_ADMIN_TOKEN || env.FLUFFLESS_ADMIN_TOKEN_FILE ) ) {
		info( 'Sautés : définir FLUFFLESS_API_URL et FLUFFLESS_ADMIN_TOKEN_FILE (voir services/api/README.md).' );
		return;
	}
	for ( const status of Object.keys( REPORT_DAYS ) ) {
		const reports = run( process.execPath, [ 'services/api/bin/admin.mjs', 'reports', '--status', status, '--limit', '500' ] )?.reports;
		if ( ! reports ) {
			warn( `liste des signalements « ${ status } » illisible` );
			continue;
		}
		info( `${ reports.length } signalement(s) « ${ status } »` );
		for ( const r of reports ) {
			const days = Math.floor( ( Date.now() - Date.parse( r.created_at ) ) / 86_400_000 );
			const text = `${ r.what } ${ r.expected ?? '' }`;
			const flags = [
				days > REPORT_DAYS[ status ] && `en attente depuis ${ days } jours`,
				( text.match( /https?:\/\//g ) ?? [] ).length > 2 && 'plusieurs liens (pourriel ?)',
				/<\s*(script|iframe|img|svg)|javascript:|\bon[a-z]+\s*=/i.test( text ) && 'balisage ou script dans le texte',
			].filter( Boolean );
			info( `  ${ r.id.slice( 0, 8 ) } ${ r.type }, ${ days } j${ r.has_screenshot ? ', capture' : '' }${ r.reply_email ? ', réponse demandée' : '' } : ${ JSON.stringify( r.what.slice( 0, 90 ) ) }` );
			flags.forEach( ( flag ) => warn( `${ r.id.slice( 0, 8 ) } : ${ flag }` ) );
		}
	}
}

await checkFonts();
await checkVersions();
checkDependencies();
await checkApi();
checkReports();
console.log( problems ? `\n${ problems } point(s) à regarder (voir docs/VEILLE.md).` : '\nRien à signaler.' );
process.exitCode = problems ? 1 : 0;
