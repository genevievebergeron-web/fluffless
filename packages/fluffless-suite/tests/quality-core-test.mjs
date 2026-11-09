// Qualité du contenu : titres, textes de remplacement et textes de lien relevés sans DOM.
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const core = require( '../assets/quality/quality-core.js' );
let checks = 0;
const fail = ( m ) => {
	console.error( 'ÉCHEC : ' + m );
	process.exit( 1 );
};
const ok = ( condition, m ) => {
	checks++;
	if ( ! condition ) {
		fail( m );
	}
};
let next = 0;
const block = ( name, attributes = {}, innerBlocks = [] ) => ( { name, attributes, innerBlocks, clientId: 'b' + ++next } );
const heading = ( level, content = 'Titre' ) => block( 'core/heading', { level, content } );
const types = ( issues ) => issues.map( ( i ) => i.type ).join( ',' );

// 1. Titres : gabarit qui montre le titre en H1.
let tree = [ heading( 2 ), heading( 3 ), heading( 2 ), block( 'core/group', {}, [ heading( 4 ) ] ) ];
let issues = core.audit( tree, { titleIsH1: true } );
ok( 'heading-skip' === types( issues ), 'H4 après H2 : ' + types( issues ) );
ok( 4 === issues[ 0 ].level && 2 === issues[ 0 ].previous && tree[ 3 ].innerBlocks[ 0 ].clientId === issues[ 0 ].clientId, 'niveau, précédent et bloc du saut' );
issues = core.audit( [ heading( 3 ) ], { titleIsH1: true } );
ok( 'heading-skip' === types( issues ) && true === issues[ 0 ].first, 'H3 d’entrée, premier titre' );
ok( false === core.audit( [ heading( 2 ), heading( 4 ) ], { titleIsH1: true } )[ 0 ].first, 'saut après un titre du contenu' );
ok( 'h1-duplicate' === types( core.audit( [ heading( 1 ), heading( 2 ) ], { titleIsH1: true } ) ), 'H1 en double du titre' );
ok( '' === types( core.audit( [ block( 'core/heading', { content: 'Sans niveau' } ) ], { titleIsH1: true } ) ), 'niveau absent = H2' );
issues = core.audit( [ heading( 2, '  <br> ' ), heading( 4, '&nbsp;' ), heading( 3 ) ], { titleIsH1: true } );
ok( 'heading-empty,heading-empty,heading-skip' === types( issues ), 'titres vides hors plan : ' + types( issues ) );

// 2. Titres : gabarit sans titre (Sections, Toile vierge) et gabarit inconnu.
ok( 'h1-missing' === types( core.audit( [ heading( 2 ) ], { titleIsH1: false } ) ), 'H1 manquant' );
ok( null === core.audit( [ heading( 2 ) ], { titleIsH1: false } )[ 0 ].clientId, 'H1 manquant : aucun bloc' );
ok( 'h1-extra' === types( core.audit( [ heading( 1 ), heading( 2 ), heading( 1 ) ], { titleIsH1: false } ) ), 'second H1' );
ok( '' === types( core.audit( [ heading( 1 ), heading( 2 ), heading( 3 ) ], { titleIsH1: false } ) ), 'plan propre sans titre de gabarit' );
ok( '' === types( core.audit( [ heading( 1 ), heading( 1 ) ], {} ) ), 'gabarit inconnu : pas de règle H1' );
ok( 'heading-skip' === types( core.audit( [ heading( 1 ), heading( 3 ) ], null ) ), 'gabarit inconnu : les sauts comptent' );

// 3. Images.
const image = ( attrs ) => block( 'core/image', { url: 'https://exemple.test/a.webp', ...attrs } );
issues = core.audit( [ image( {} ), image( { alt: '  ' } ), image( { alt: 'Équipe au marché' } ) ], {} );
ok( 'alt-missing,alt-missing' === types( issues ) && true === issues[ 0 ].canMarkDecorative, 'alt manquant : ' + types( issues ) );
ok( '' === types( core.audit( [ image( { metadata: { flufflessDecorative: true } } ) ], {} ) ), 'image marquée décorative' );
ok( 'alt-linked' === types( core.audit( [ image( { href: '/a', metadata: { flufflessDecorative: true } } ) ], {} ) ), 'image liée sans alt, même décorative' );
ok( '' === types( core.audit( [ block( 'core/image', {} ) ], {} ) ), 'image vide ignorée' );
for ( const weak of [ 'IMG_2041.JPG', 'photo.webp', 'DSC 0042', 'Image', 'photo', 'Capture-12', 'logo' ] ) {
	ok( 'alt-weak' === types( core.audit( [ image( { alt: weak } ) ], {} ) ), 'alt faible non relevé : ' + weak );
}
ok( '' === types( core.audit( [ image( { alt: 'Photo de l’atelier, 2026' } ) ], {} ) ), 'alt qui décrit, même avec « photo »' );
for ( const fine of [ 'Photo 1945 de la rue Saint-Jean', 'Image 2 : schéma du processus de commande' ] ) {
	ok( '' === types( core.audit( [ image( { alt: fine } ) ], {} ) ), 'alt qui décrit, après un numéro : ' + fine );
}
ok( 'alt-weak' === types( core.audit( [ image( { alt: 'PXL_20260101_123456' } ) ], {} ) ), 'nom de téléphone' );
ok( 'equipe été.webp' === core.audit( [ image( { url: 'https://exemple.test/2026/equipe%20%C3%A9t%C3%A9.webp?x=1' } ) ], {} )[ 0 ].file, 'nom de fichier dans le message' );
ok( 'alt-missing' === types( core.audit( [ block( 'core/media-text', { mediaUrl: 'x.webp' } ) ], {} ) ), 'média et texte sans alt' );
ok( '' === types( core.audit( [ block( 'core/media-text', { mediaUrl: 'x.mp4', mediaType: 'video' } ) ], {} ) ), 'vidéo de média et texte ignorée' );
ok( '' === types( core.audit( [ block( 'fluffless/calque', { url: 'x.webp' } ) ], {} ) ), 'calque décoratif par défaut' );
issues = core.audit( [ block( 'fluffless/calque', { url: 'x.webp', decorative: false } ) ], {} );
ok( 'alt-missing' === types( issues ) && false === issues[ 0 ].canMarkDecorative, 'calque non décoratif sans alt' );
ok( 'alt-missing' === types( core.audit( [ block( 'core/gallery', {}, [ image( {} ) ] ) ], {} ) ), 'image de galerie' );

// 3 bis. Blocs Classique et HTML personnalisé : titres et images lus dans le HTML.
issues = core.audit( [ block( 'core/freeform', { content: '<h1>Accueil</h1><p>x</p><h3>Plus loin</h3>' } ) ], { titleIsH1: false } );
ok( 'heading-skip' === types( issues ) && 'Plus loin' === issues[ 0 ].text, 'titres du bloc Classique : ' + types( issues ) );
ok( '' === types( core.audit( [ block( 'core/html', { content: '<h2>A</h2>' } ), heading( 3 ) ], { titleIsH1: true } ) ), 'H2 en HTML puis H3 natif' );
issues = core.audit( [ block( 'core/html', { content: '<img src="/a/plan.png"><img src="b.png" alt=""><img src="c.png" alt="IMG_1.jpg">' } ) ], {} );
ok( 'alt-missing,alt-weak' === types( issues ) && false === issues[ 0 ].canMarkDecorative && 'plan.png' === issues[ 0 ].file, 'images en HTML : ' + types( issues ) );

// 3 ter. Images marquées décoratives, pour pouvoir revenir en arrière.
const marked = core.decoratives( [ image( { metadata: { flufflessDecorative: true } } ), image( { alt: 'A', metadata: { flufflessDecorative: true } } ), image( {} ) ] );
ok( 1 === marked.length && 'a.webp' === marked[ 0 ].file, 'images décoratives listées : ' + marked.length );

// 4. Liens.
const para = ( content ) => block( 'core/paragraph', { content } );
issues = core.audit( [
	para( 'Les tarifs sont <a href="/tarifs">ici</a>. <a href="/a">Cliquez ICI&nbsp;!</a> <a href="/b">Lire la suite…</a>' ),
	para( '<a href="/c">Read more →</a> <a href="/d">Plus d&rsquo;infos</a> <a href="/e">Nos tarifs 2026</a>' ),
], {} );
ok( 'link-vague,link-vague,link-vague,link-vague,link-vague' === types( issues ), 'liens vagues : ' + types( issues ) );
ok( 'Cliquez ICI !' === issues[ 1 ].text, 'texte du lien rendu : ' + issues[ 1 ].text );
ok( '' === types( core.audit( [ para( '<a href="/t" aria-label="Lire la suite : nos tarifs">Lire la suite</a>' ) ], {} ) ), 'aria-label qui nomme' );
ok( 'link-vague' === types( core.audit( [ para( '<a href="/t" aria-label=\'ici\'>Nos tarifs</a>' ) ], {} ) ), 'aria-label vague' );
ok( 'link-url' === types( core.audit( [ para( '<a href="https://fluffless.dev">https://fluffless.dev/fr/</a>' ) ], {} ) ), 'adresse en texte de lien' );
ok( 'link-empty' === types( core.audit( [ para( '<a href="/x"><img src="a.png" alt=""></a>' ) ], {} ) ), 'lien vide' );
ok( '' === types( core.audit( [ para( '<a id="haut"></a>Texte <a name="s1">ici</a>' ) ], {} ) ), 'ancres sans href ignorées' );
ok( '' === types( core.audit( [ para( '<a href="/x"><img src="a.png" alt="Accueil"></a>' ) ], {} ) ), 'lien nommé par son image' );
ok( 'link-vague' === types( core.audit( [ block( 'core/button', { text: 'En savoir plus', url: '/a' } ) ], {} ) ), 'bouton vague' );
ok( '' === types( core.audit( [ block( 'core/button', { text: 'En savoir plus' } ) ], {} ) ), 'bouton sans lien ignoré' );
ok( 'link-vague' === types( core.audit( [ block( 'core/table', { body: [ { cells: [ { content: '<a href="/x">here</a>' } ] } ] } ) ], {} ) ), 'lien de cellule' );
ok( 'link-vague' === types( core.audit( [ image( { alt: 'Plan', caption: '<a href="/p">ici</a>' } ) ], {} ) ), 'lien de légende' );
const rich = { toHTMLString: () => '<a href="/x">click here</a>' };
ok( 'link-vague' === types( core.audit( [ block( 'core/paragraph', { content: rich } ) ], {} ) ), 'RichTextData' );
ok( '' === types( core.audit( [ block( 'core/paragraph', { metadata: { name: '<a>ici</a>' } } ) ], {} ) ), 'metadata ignoré' );
ok( 'link-vague' === types( core.audit( [ heading( 2, 'Titre <a href="/a">ici</a>' ) ], { titleIsH1: true } ) ), 'lien dans un titre' );

// 5. Familles, bornes et arbre intact.
ok( 'headings' === core.group( 'h1-missing' ) && 'images' === core.group( 'alt-weak' ) && 'links' === core.group( 'link-url' ), 'familles' );
tree = [ heading( 2 ) ];
const snapshot = JSON.stringify( tree );
core.audit( tree, { titleIsH1: false } );
ok( JSON.stringify( tree ) === snapshot, 'arbre modifié' );
let deep = [ heading( 2 ) ];
for ( let i = 0; i <= core.MAX_DEPTH + 1; i++ ) {
	deep = [ block( 'core/group', {}, deep ) ];
}
let threw = false;
try {
	core.audit( deep, {} );
} catch ( e ) {
	threw = /nested/.test( e.message );
}
ok( threw, 'imbrication sans fin non bornée' );
ok( '' === types( core.audit( [], { titleIsH1: true } ) ) && '' === types( core.audit( undefined, {} ) ), 'page vide' );

console.log( 'quality-core : ' + checks + ' vérifications OK' );
