// Récupère chaque WOFF2 à son adresse d'origine et refuse tout fichier dont l'empreinte
// SHA-256 diffère de celle de collection.json (calculée sur l'archive maîtresse Proton Drive).
// Une famille sans hôte public (convertie depuis l'archive) voyage avec le code, dans local/.
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const ORIGINS = {
	'bricolage-grotesque': 'https://fonts.gstatic.com/s/bricolagegrotesque/v9/3y9H6as8bTXq_nANBjzKo3IeZx8z6up5BeSl5jBNz_19PpbpMXuECpwUxJBOm_OJWiawA1XphjhQYg.woff2',
	'instrument-sans': 'https://fonts.gstatic.com/s/instrumentsans/v4/pxiTypc9vsFDm051Uf6KVwgkfoSxQ0GsQv8ToedPibnr0SZe1ZuWi3g.woff2',
	'instrument-serif': 'https://fonts.gstatic.com/s/instrumentserif/v5/jizBRFtNs2ka5fXjeivQ4LroWlx-6zUTjnTLgNs.woff2',
	anybody: 'https://fonts.gstatic.com/s/anybody/v13/VuJxdNvK2Ib2ppdWSKHdOaNkaRs.woff2',
	'atkinson-hyperlegible-next': 'https://fonts.gstatic.com/s/atkinsonhyperlegiblenext/v7/NaPNcYPdHfdVxJw0IfIP0lvYFqijb-UxCtm5_wdGseiJn3q0pkZ_.woff2',
	literata: 'https://fonts.gstatic.com/s/literata/v40/or38Q6P12-iJxAIgLa78DkrbXsDgk0oVDaDlbJ5W7i5aOg.woff2',
	newsreader: 'https://fonts.gstatic.com/s/newsreader/v26/cY9VfjOCX1hbuyalUrK49dLac06G1ZGsZBtoBAbNJYQ5ayZC.woff2',
	'young-serif': 'https://fonts.gstatic.com/s/youngserif/v2/3qTpojO2nS2VtkB3KtkQZ1t93kY6ah7E.woff2',
	'hanken-grotesk': 'https://fonts.gstatic.com/s/hankengrotesk/v12/ieVn2YZDLWuGJpnzaiwFXS9tYtpd59CxCis4.woff2',
	'familjen-grotesk': 'https://fonts.gstatic.com/s/familjengrotesk/v11/Qw3GZR9ZHiDnImG6-NEMQ41wby8WbHoEj7E49PA.woff2',
	recursive: 'https://fonts.gstatic.com/s/recursive/v44/8vI-7wMr0mhh-RQChyHEH06TlXhq_gukbYrFMk1QuAIcyEwG_X-dpEfaE5YaERmK-CImKsvxvU-MXGX2fSqasNfUlTGZnI14ZeY.woff2',
	'jetbrains-mono': 'https://fonts.gstatic.com/s/jetbrainsmono/v24/tDbV2o-flEEny0FZhsfKu5WU4xD7OwGtT0rU.woff2',
	outfit: 'https://fonts.gstatic.com/s/outfit/v15/QGYvz_MVcBeNP4NJtEtqUYLknw.woff2',
	'space-grotesk': 'https://fonts.gstatic.com/s/spacegrotesk/v22/V8mDoQDjQSkFtoMM3T6r8E7mPbF4C_k3HqU.woff2',
	gloock: 'https://fonts.gstatic.com/s/gloock/v8/Iurb6YFw84WUY4NJiBakJLRBjA.woff2',
	handjet: 'https://fonts.gstatic.com/s/handjet/v22/oY1e8eXHq7n1OnbQhuoXD_53Y4U.woff2',
	lineal: 'https://gitlab.com/velvetyne/lineal/-/raw/d461925b710c3d62239e605545598547fe172818/fonts/Variable/LinealVF.woff2',
};

const LIVE = 'https://fluffless-polices.canner.app';

const { families } = JSON.parse( readFileSync( 'public/collection.json', 'utf8' ) );
mkdirSync( 'public/fonts', { recursive: true } );
for ( const family of families ) {
	const origin = ORIGINS[ family.slug ];
	let data;
	if ( origin ) {
		const response = await fetch( origin );
		if ( ! response.ok ) {
			throw new Error( `${ family.slug } : téléchargement refusé (${ response.status })` );
		}
		data = Buffer.from( await response.arrayBuffer() );
	} else if ( existsSync( `local/${ family.slug }.woff2` ) ) {
		data = readFileSync( `local/${ family.slug }.woff2` );
	} else {
		// Déploiement par étapes : une famille absente de local/ est reprise de la banque
		// en ligne (le déploiement précédent), sous le même contrôle d'empreinte.
		const response = await fetch( `${ LIVE }/${ family.file }` );
		if ( ! response.ok ) {
			throw new Error( `${ family.slug } : ni adresse d'origine, ni fichier dans local/, ni copie en ligne (${ response.status })` );
		}
		data = Buffer.from( await response.arrayBuffer() );
	}
	const sha = createHash( 'sha256' ).update( data ).digest( 'hex' );
	if ( sha !== family.sha256 || data.length !== family.bytes ) {
		throw new Error( `${ family.slug } : empreinte différente de l'archive (${ sha })` );
	}
	writeFileSync( `public/${ family.file }`, data );
	console.log( `${ family.slug } : ${ data.length } octets, empreinte conforme` );
}
