// Cœur de l'éditeur de pages : remplissage par metadata.name, verrouillage des sections, bornes.
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const core = require( '../assets/editor/editor-core.js' );
const fail = ( m ) => {
	console.error( 'ÉCHEC : ' + m );
	process.exit( 1 );
};
const block = ( name, attributes = {}, innerBlocks = [] ) => ( { name, attributes, innerBlocks } );
const named = ( n ) => ( { metadata: { name: n } } );

const tree = [
	block( 'core/group', { templateLock: 'all' }, [
		block( 'core/heading', { ...named( 'fl:titre' ), content: 'Consigne du titre', level: 1 } ),
		block( 'core/group', {}, [ block( 'core/paragraph', { ...named( 'fl:texte' ), content: 'Consigne du texte' } ) ] ),
		block( 'core/button', { ...named( 'fl:bouton' ), text: 'Bouton' } ),
	] ),
	block( 'core/paragraph', { content: 'Hors section' } ),
	block( 'core/cover', { templateLock: 'contentOnly' }, [] ),
];
const snapshot = JSON.stringify( tree );

// 1. Remplissage : titre et texte remplis, échappés ; question passée = consigne ; bloc non texte ignoré.
const filled = core.fill( tree, { 'fl:titre': ' Des livres <à jour> & rien ', 'fl:texte': '', 'fl:bouton': 'Non', 'fl:inconnu': 'x' } );
const [ section ] = filled;
if ( 'Des livres &lt;à jour&gt; &amp; rien' !== section.innerBlocks[ 0 ].attributes.content ) fail( 'titre : ' + section.innerBlocks[ 0 ].attributes.content );
if ( 1 !== section.innerBlocks[ 0 ].attributes.level ) fail( 'autres attributs perdus' );
if ( 'Consigne du texte' !== section.innerBlocks[ 1 ].innerBlocks[ 0 ].attributes.content ) fail( 'question passée : consigne remplacée' );
if ( 'Bouton' !== section.innerBlocks[ 2 ].attributes.text || 'content' in section.innerBlocks[ 2 ].attributes ) fail( 'bloc non texte modifié' );
if ( JSON.stringify( tree ) !== snapshot ) fail( 'arbre d’origine modifié' );
if ( 'a<br>b' !== core.toRichText( 'a\nb' ) ) fail( 'retour de ligne : ' + core.toRichText( 'a\nb' ) );

// 2. Fabrique : appelée pour chaque bloc, avec ses enfants déjà faits.
let calls = 0;
core.fill( tree, {}, ( b, attributes, innerBlocks ) => {
	calls++;
	return { ...b, attributes, innerBlocks, made: true };
} );
if ( 7 !== calls ) fail( 'fabrique appelée ' + calls + ' fois (attendu 7)' );

// 3. Noms dans l'ordre de lecture.
if ( 'fl:titre,fl:texte,fl:bouton' !== core.names( tree ).join( ',' ) ) fail( 'noms : ' + core.names( tree ) );

// 4. Verrouillage : sections de premier niveau seulement ; déverrouillage : contentOnly seulement.
const locked = core.lockSections( tree );
if ( 'contentOnly' !== locked[ 0 ].attributes.templateLock || 'contentOnly' !== locked[ 2 ].attributes.templateLock ) fail( 'sections non verrouillées' );
if ( 'templateLock' in locked[ 1 ].attributes ) fail( 'paragraphe hors section verrouillé' );
if ( 'templateLock' in locked[ 0 ].innerBlocks[ 1 ].attributes ) fail( 'groupe imbriqué verrouillé' );
const open = core.unlockSections( tree );
if ( 'all' !== open[ 0 ].attributes.templateLock ) fail( 'verrou « all » retiré à tort' );
if ( 'templateLock' in open[ 2 ].attributes ) fail( 'verrou contentOnly resté' );

// 5. Relecture : consignes intactes et boutons à lier.
const modele = [ block( 'core/group', {}, [
	block( 'core/heading', { content: 'Dis en <strong>cinq mots</strong> ce qui change' } ),
	block( 'core/paragraph', { content: 'Services' } ),
	block( 'core/button', { text: 'Écris-nous maintenant', url: 'https://site.test/contact/' } ),
] ) ];
const known = core.placeholders( modele );
if ( ! known.has( 'Services' ) ) fail( 'P9a-03 : texte court d’exemple non retenu' );
if ( ! known.has( 'Dis en cinq mots ce qui change' ) ) fail( 'consigne non retenue : ' + [ ...known ] );
const page = [ { ...block( 'core/heading', { content: 'Dis en cinq mots ce qui change' } ), clientId: 'h' },
	{ ...block( 'core/paragraph', { content: 'Mon vrai texte, écrit par moi.' } ), clientId: 'p' },
	{ ...block( 'core/button', { text: 'Contact', url: 'https://site.test/contact/' } ), clientId: 'b1' },
	{ ...block( 'core/button', { text: 'Accueil', url: 'https://site.test/' } ), clientId: 'b2' },
	{ ...block( 'core/button', { text: 'Ailleurs', url: 'https://autre.test/x/' } ), clientId: 'b3' },
	{ ...block( 'core/button', { text: 'Vide', url: '#' } ), clientId: 'b4' },
	{ ...block( 'core/button', { text: 'Rien' } ), clientId: 'b5' } ];
const r = core.review( page, known, 'https://site.test' );
if ( 'h' !== r.texts.map( ( x ) => x.clientId ).join( ',' ) ) fail( 'consignes : ' + JSON.stringify( r.texts ) );
if ( 'b1:contact,b4:null,b5:null' !== r.links.map( ( x ) => x.clientId + ':' + x.slug ).join( ',' ) ) fail( 'liens : ' + JSON.stringify( r.links ) );
if ( 'a-propos' !== core.internalSlug( 'https://site.test/a-propos/?x=1', 'https://site.test/' ) ) fail( 'slug interne' );

// 5b. K2e : lien marqué par la classe de la page cible (K2c), rappel de migration, consigne modifiée.
const marques = [
	{ ...block( 'core/button', { text: 'Écrire', url: '#', className: 'is-style-fill fluffless-page-contact' } ), clientId: 'm1' },
	{ ...block( 'core/button', { text: 'Écrire', url: 'https://exemple.ca/contact/', className: 'fluffless-page-contact' } ), clientId: 'm2' },
	{ ...block( 'core/button', { text: 'Écrire', url: 'https://autre.test/x/', className: 'fluffless-page-contact' } ), clientId: 'm3' },
	{ ...block( 'core/button', { text: 'Sous-page', url: '#', className: 'fluffless-page-services--web' } ), clientId: 'm4' },
	{ ...block( 'core/paragraph', { content: 'Dis en cinq mots ce qui change, mais autrement.' } ), clientId: 'm5' },
	{ ...block( 'core/html', {} ), originalContent: '<!-- fluffless-todo: Form to rebuild with the Form module: Contact. Fields: Nom. -->', clientId: 'm6' },
	{ ...block( 'core/html', { content: '<!-- une note de la cliente -->' } ), clientId: 'm7' },
];
const rm = core.review( marques, known, 'https://site.test' );
if ( 'm1:contact,m2:contact,m4:web' !== rm.links.map( ( x ) => x.clientId + ':' + x.slug ).join( ',' ) ) fail( 'liens marqués : ' + JSON.stringify( rm.links ) );
if ( 0 !== rm.texts.length ) fail( 'consigne modifiée encore comptée : ' + JSON.stringify( rm.texts ) );
if ( 'm6' !== ( rm.reminders || [] ).map( ( x ) => x.clientId ).join( ',' ) ) fail( 'rappel de migration : ' + JSON.stringify( rm.reminders ) );
if ( ! /Form to rebuild/.test( ( rm.reminders || [ {} ] )[ 0 ].text || '' ) ) fail( 'texte du rappel absent : ' + JSON.stringify( rm.reminders ) );
if ( 'web' !== core.markedSlug( 'x fluffless-page-services--web y' ) || null !== core.markedSlug( 'fluffless-pagex' ) ) fail( 'markedSlug' );

// 5c. P9a-03 : prix, chiffres et signatures d'exemple signalés, sans seuil de longueur.
const exemples = [ block( 'core/group', {}, [
	block( 'core/paragraph', { content: '4 800 $' } ),
	block( 'core/heading', { content: '+38 %' } ),
	block( 'core/quote', { citation: 'Prénom Nom, rôle' }, [ block( 'core/paragraph', { content: 'Une citation d’exemple assez longue.' } ) ] ),
	block( 'core/details', { summary: 'Question ?' }, [ block( 'core/paragraph', { content: 'Réponse.' } ) ] ),
] ) ];
const knownEx = core.placeholders( exemples );
const pageEx = [
	{ ...block( 'core/paragraph', { content: '4 800 $' } ), clientId: 'e1' },
	{ ...block( 'core/heading', { content: '+38 %' } ), clientId: 'e2' },
	{ ...block( 'core/quote', { citation: 'Prénom Nom, rôle' } ), clientId: 'e3' },
	{ ...block( 'core/details', { summary: 'Question ?' } ), clientId: 'e4' },
	{ ...block( 'core/paragraph', { content: '2 400 $' } ), clientId: 'e5' },
];
const rex = core.review( pageEx, knownEx, 'https://site.test' );
if ( 'e1,e2,e3,e4' !== rex.texts.map( ( x ) => x.clientId ).join( ',' ) ) fail( 'P9a-03 : valeurs d’exemple : ' + JSON.stringify( rex.texts ) );

// 5d. C1-9-03 : cellules de tableau d'exemple (horaires) signalées, une entrée par cellule intacte.
const row = ( ...cells ) => ( { cells: cells.map( ( content ) => ( { content, tag: 'td' } ) ) } );
const horaires = [ block( 'core/table', { head: [ row( 'Jour', 'Heures' ) ], body: [ row( 'Du lundi au jeudi', 'De 9 h à 17 h' ), row( 'Vendredi', 'De 9 h à midi' ) ] } ) ];
const knownTab = core.placeholders( horaires );
const pageTab = [ { ...block( 'core/table', { body: [ row( 'Du lundi au jeudi', 'De 8 h à 16 h' ), row( 'Vendredi', 'De 9 h à midi' ) ] } ), clientId: 't1' } ];
const rtab = core.review( pageTab, knownTab, 'https://site.test' );
if ( 'Du lundi au jeudi|Vendredi|De 9 h à midi' !== rtab.texts.map( ( x ) => x.text ).join( '|' ) || rtab.texts.some( ( x ) => 't1' !== x.clientId ) ) fail( 'C1-9-03 : cellules d’exemple : ' + JSON.stringify( rtab.texts ) );

// 6. Borne : imbrication démesurée refusée avec un message clair.
let deep = block( 'core/paragraph' );
for ( let i = 0; i <= core.MAX_DEPTH + 1; i++ ) deep = block( 'core/group', {}, [ deep ] );
try {
	core.fill( [ deep ], {} );
	fail( 'imbrication démesurée acceptée' );
} catch ( e ) {
	if ( ! /imbrication/.test( e.message ) ) fail( 'message de borne : ' + e.message );
}

console.log( 'OK : remplissage par nom (échappé, question passée, bloc non texte), fabrique, noms, verrouillage, relecture, borne.' );
