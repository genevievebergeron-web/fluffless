// Tests de l'analyse locale du panneau SEO (assets/analysis.js), sans navigateur :
// jsdom fournit le DOMParser, le script est chargé dans un contexte isolé.
// Lancer : node packages/fluffless-seo/tests/seo-analysis-test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { JSDOM } from 'jsdom';

const source = readFileSync( new URL( '../assets/analysis.js', import.meta.url ), 'utf8' );
const { window: dom } = new JSDOM( '' );
const window = {};
// Pas de document : la mesure passe par le repli sans canvas, comme dans un navigateur sans 2D.
vm.runInNewContext( source, { window } );
const api = window.flufflessSeoAnalysis;
const parse = ( html ) => new dom.DOMParser().parseFromString( html, 'text/html' );
const status = ( result, id ) => result.checks.find( ( c ) => c.id === id )?.status;
let checks = 0;
const ok = ( fn ) => {
	fn();
	checks++;
};

// Normalisation : accents et casse ignorés, mots entiers seulement.
ok( () => assert.equal( api.normalize( '  Écoconception   Web ' ), 'ecoconception web' ) );
ok( () => assert.equal( api.contains( 'Guide d’écoconception web', 'ecoconception WEB' ), true ) );
ok( () => assert.equal( api.contains( 'webinaire', 'web' ), false ) );
ok( () => assert.equal( api.contains( 'texte', '' ), false ) );
ok( () => assert.equal( api.slugify( 'Café à Montréal !' ), 'cafe-a-montreal' ) );

// Largeur : un titre long dépasse 580 px, un titre court reste sous le minimum.
ok( () => assert.ok( api.textWidth( 'x'.repeat( 80 ), api.TITLE.size ) > api.TITLE.max ) );
ok( () => assert.ok( api.textWidth( 'Accueil', api.TITLE.size ) < api.TITLE.min ) );

// Gabarits : variables remplacées, inconnues retirées, séparateurs orphelins nettoyés.
ok( () => assert.equal( api.fillTemplate( '%%title%% %%sep%% %%sitename%%', { title: 'Page', sitename: 'Site', sep: '|' }, '|' ), 'Page | Site' ) );
ok( () => assert.equal( api.fillTemplate( '%%title%% %%sep%% %%inconnue%% %%sep%% %%sitename%%', { title: 'Page', sitename: 'Site', sep: '|' }, '|' ), 'Page | Site' ) );
ok( () => assert.equal( api.fillTemplate( '%%sep%% %%sitename%%', { sitename: 'Site', sep: '|' }, '|' ), 'Site' ) );

// Coupe au mot entier, avec points de suspension.
ok( () => assert.equal( api.shorten( 'un deux trois quatre', 12 ), 'un deux…' ) );
ok( () => assert.equal( api.shorten( 'court', 12 ), 'court' ) );

// Chemin heureux : mot-clé présent partout, structure correcte.
{
	const words = Array.from( { length: 320 }, ( _, i ) => ( i % 160 === 0 ? 'jardin urbain' : 'mot' ) ).join( ' ' );
	const html = `<p>Le jardin urbain change la ville.</p><h2>Planter un jardin urbain</h2><p>${ words }</p>` +
		'<p><a href="/contact">contact</a> <a href="https://ailleurs.example/">source</a></p><img src="a.jpg" alt="Un jardin urbain">';
	const result = api.analyse(
		{ keyword: 'Jardin urbain', title: 'Créer un jardin urbain en ville, guide complet', description: 'Tout pour réussir un jardin urbain : sol, lumière, arrosage et plantes adaptées au balcon comme à la cour arrière, étape par étape.', slug: 'jardin-urbain-guide', html, home: 'https://site.example/' },
		{ parse }
	);
	for ( const id of [ 'title_length', 'content_length', 'headings', 'links_internal', 'links_external', 'keyword_title', 'keyword_desc', 'keyword_intro', 'keyword_h2', 'keyword_url', 'keyword_alt', 'keyword_density' ] ) {
		ok( () => assert.equal( status( result, id ), 'good', id ) );
	}
	ok( () => assert.ok( result.words >= 300 ) );
}

// Chemins d'erreur : H1 dans le contenu, niveau sauté, mot-clé absent, titre vide, bourrage.
{
	const result = api.analyse( { keyword: 'vélo', title: '', description: '', slug: 'autre', html: '<h1>Titre</h1><p>court</p>', home: 'https://site.example/' }, { parse } );
	ok( () => assert.equal( status( result, 'title_length' ), 'bad' ) );
	ok( () => assert.equal( status( result, 'desc_length' ), 'bad' ) );
	ok( () => assert.equal( status( result, 'headings' ), 'bad' ) );
	ok( () => assert.equal( status( result, 'keyword_title' ), 'bad' ) );
	ok( () => assert.equal( status( result, 'keyword_density' ), 'bad' ) );
	ok( () => assert.equal( status( result, 'content_length' ), 'warn' ) );
	ok( () => assert.equal( status( result, 'links_internal' ), 'warn' ) );

	// Gabarit sans titre (Sections, Toile vierge) : un H1 attendu, ni zéro ni deux.
	ok( () => assert.equal( status( api.analyse( { html: '<h1>A</h1><h2>B</h2>', titleIsH1: false }, { parse } ), 'headings' ), 'good' ) );
	ok( () => assert.equal( status( api.analyse( { html: '<h2>B</h2>', titleIsH1: false }, { parse } ), 'headings' ), 'bad' ) );
	ok( () => assert.equal( status( api.analyse( { html: '<h1>A</h1><h1>B</h1>', titleIsH1: false }, { parse } ), 'headings' ), 'bad' ) );

	const skipped = api.analyse( { html: '<h2>A</h2><h4>B</h4>' }, { parse } );
	ok( () => assert.equal( status( skipped, 'headings' ), 'bad' ) );

	const stuffed = api.analyse( { keyword: 'vélo', title: 'vélo', html: '<p>' + 'vélo '.repeat( 20 ) + 'fin</p>' }, { parse } );
	ok( () => assert.equal( status( stuffed, 'keyword_density' ), 'warn' ) );

	// Sans mot-clé : aucune vérification de mot-clé, pas d'exception.
	const plain = api.analyse( { html: '' }, { parse } );
	ok( () => assert.equal( plain.checks.some( ( c ) => c.id.startsWith( 'keyword_' ) ), false ) );
	// Hôte d'accueil invalide : les liens absolus comptent comme externes, sans exception.
	const badHome = api.analyse( { html: '<a href="https://x.example/">x</a>', home: 'pas une adresse' }, { parse } );
	ok( () => assert.equal( status( badHome, 'links_external' ), 'good' ) );
}

console.log( `OK : ${ checks } contrôles réussis (analyse SEO : normalisation, largeurs, gabarits, vérifications).` );
