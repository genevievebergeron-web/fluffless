// Serveur statique minimal de la banque : fichiers de public/ seulement, sans dépendance.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, normalize } from 'node:path';

const TYPES = {
	'.html': 'text/html; charset=utf-8',
	'.json': 'application/json; charset=utf-8',
	'.txt': 'text/plain; charset=utf-8',
	'.woff2': 'font/woff2',
};

createServer( async ( req, res ) => {
	const path = normalize( decodeURIComponent( new URL( req.url, 'http://x' ).pathname ) ).replace( /^(\.\.[/\\])+/, '' );
	const file = '/' === path ? '/index.html' : path;
	const type = TYPES[ extname( file ) ];
	if ( ! type || ! [ 'GET', 'HEAD' ].includes( req.method ) ) {
		res.writeHead( 404, { 'content-type': 'text/plain; charset=utf-8' } ).end( 'Introuvable' );
		return;
	}
	try {
		const data = await readFile( `public${ file }` );
		res.writeHead( 200, {
			'content-type': type,
			'content-length': data.length,
			'cache-control': '.woff2' === extname( file ) ? 'public, max-age=31536000, immutable' : 'public, max-age=3600',
			'access-control-allow-origin': '*',
			'x-content-type-options': 'nosniff',
		} );
		res.end( 'HEAD' === req.method ? undefined : data );
	} catch {
		res.writeHead( 404, { 'content-type': 'text/plain; charset=utf-8' } ).end( 'Introuvable' );
	}
} ).listen( Number( process.env.PORT ) || 3000 );
