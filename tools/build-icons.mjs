#!/usr/bin/env node
/**
 * Génère le sous-ensemble curé d'icônes Lucide du bloc fluffless/icone.
 *
 * Source : paquet npm lucide-static (devDependency), fichier icon-nodes.json.
 * Sorties :
 *   packages/fluffless-suite/blocks/icone/icons.php  (nom -> contenu interne du SVG)
 *   packages/fluffless-suite/blocks/icone/LICENSE     (ISC Lucide + MIT Feather)
 *
 * Usage : npm run icons:build
 * Échoue (code 1) si une icône listée n'existe pas dans la version installée
 * ou si un nœud contient une balise ou un attribut hors liste blanche.
 */
import { readFileSync, writeFileSync, copyFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire( import.meta.url );
const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const lucideDir = dirname( require.resolve( 'lucide-static/package.json' ) );
const outDir = join( root, 'packages/fluffless-suite/blocks/icone' );

/** Catégories curées pour PME et OBNL. L'ordre fixe l'ordre de la grille dans l'éditeur. */
const CATEGORIES = [
	[ 'commerce', 'Commerce', [ 'shopping-cart', 'shopping-bag', 'store', 'tag', 'receipt', 'credit-card', 'wallet', 'package', 'truck', 'gift', 'percent', 'badge-percent', 'banknote', 'coins', 'piggy-bank' ] ],
	[ 'services', 'Services', [ 'briefcase', 'wrench', 'hammer', 'settings', 'lightbulb', 'rocket', 'target', 'handshake', 'clipboard-check', 'clipboard-list', 'file-text', 'file-check', 'files', 'layers', 'armchair', 'palette', 'key', 'award', 'badge-check' ] ],
	[ 'contact', 'Contact et lieux', [ 'phone', 'mail', 'map-pin', 'map', 'message-circle', 'message-circle-question-mark', 'message-square', 'send', 'globe', 'at-sign', 'clock', 'navigation', 'compass', 'inbox', 'house', 'building' ] ],
	[ 'nature', 'Nature et écologie', [ 'leaf', 'sprout', 'tree-pine', 'trees', 'flower', 'flower-2', 'sun', 'cloud', 'droplet', 'waves-horizontal', 'wind', 'mountain', 'recycle', 'bike', 'zap', 'battery-charging', 'earth', 'flame' ] ],
	[ 'communaute', 'Communauté', [ 'users', 'user', 'user-plus', 'user-check', 'hand-heart', 'heart-handshake', 'hand-helping', 'megaphone', 'party-popper', 'baby', 'accessibility' ] ],
	[ 'dons', 'Dons et appréciation', [ 'heart', 'hand-coins', 'star', 'sparkles', 'thumbs-up', 'circle-dollar-sign' ] ],
	[ 'fleches', 'Flèches', [ 'arrow-right', 'arrow-left', 'arrow-up', 'arrow-down', 'arrow-up-right', 'chevron-right', 'chevron-left', 'chevron-down', 'chevron-up', 'external-link', 'refresh-cw', 'repeat', 'download', 'upload' ] ],
	[ 'medias', 'Médias et partage', [ 'share', 'share-2', 'rss', 'link', 'image', 'camera', 'video', 'play', 'mic', 'radio', 'newspaper', 'bookmark' ] ],
	[ 'statistiques', 'Statistiques', [ 'chart-bar', 'chart-line', 'chart-pie', 'chart-column', 'trending-up', 'trending-down', 'activity', 'gauge', 'calculator' ] ],
	[ 'calendrier', 'Calendrier et temps', [ 'calendar', 'calendar-days', 'calendar-check', 'calendar-clock', 'alarm-clock', 'hourglass', 'timer' ] ],
	[ 'securite', 'Sécurité et état', [ 'shield', 'shield-check', 'lock', 'eye', 'circle-check', 'check', 'x', 'info', 'circle-alert', 'triangle-alert', 'circle-question-mark', 'life-buoy' ] ],
	[ 'education', 'Éducation', [ 'graduation-cap', 'book', 'book-open', 'library', 'school', 'notebook-pen', 'pencil', 'brain', 'puzzle' ] ],
	[ 'sante', 'Santé', [ 'stethoscope', 'heart-pulse', 'pill', 'hospital', 'syringe', 'dumbbell', 'thermometer' ] ],
	[ 'alimentation', 'Alimentation', [ 'apple', 'carrot', 'coffee', 'utensils', 'chef-hat', 'cooking-pot', 'wheat', 'salad' ] ],
];

const ALLOWED_TAGS = new Set( [ 'path', 'circle', 'rect', 'line', 'ellipse', 'polyline', 'polygon' ] );
const ALLOWED_ATTRS = new Set( [ 'd', 'cx', 'cy', 'r', 'x', 'y', 'width', 'height', 'rx', 'ry', 'x1', 'x2', 'y1', 'y2', 'fill', 'points' ] );
const SAFE_VALUE = /^[0-9A-Za-z .,-]*$/;
const KEBAB = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

const nodes = JSON.parse( readFileSync( join( lucideDir, 'icon-nodes.json' ), 'utf8' ) );
const version = JSON.parse( readFileSync( join( lucideDir, 'package.json' ), 'utf8' ) ).version;

const errors = [];
const icons = new Map();

/**
 * Ajoute une icône à la table, après vérification du nom et des nœuds.
 *
 * @param {string}              name  Nom Lucide.
 * @param {Map<string, string>} table Table de sortie.
 */
function addIcon( name, table ) {
	if ( ! KEBAB.test( name ) ) {
		errors.push( `${ name } : nom hors kebab-case` );
		return;
	}
	if ( table.has( name ) ) {
		errors.push( `${ name } : listée deux fois` );
		return;
	}
	const iconNodes = nodes[ name ];
	if ( ! Array.isArray( iconNodes ) ) {
		errors.push( `${ name } : absente de lucide-static ${ version }` );
		return;
	}
	const inner = iconNodes.map( ( [ tag, attrs ] ) => {
		if ( ! ALLOWED_TAGS.has( tag ) ) {
			errors.push( `${ name } : balise <${ tag }> refusée` );
		}
		const attrText = Object.entries( attrs )
			.filter( ( [ key ] ) => key !== 'key' )
			.map( ( [ key, value ] ) => {
				const text = String( value );
				if ( ! ALLOWED_ATTRS.has( key ) || ! SAFE_VALUE.test( text ) ) {
					errors.push( `${ name } : attribut ${ key }="${ text }" refusé` );
				}
				return `${ key }="${ text }"`;
			} )
			.join( ' ' );
		return `<${ tag } ${ attrText }/>`;
	} ).join( '' );
	table.set( name, inner );
}

for ( const [ , , names ] of CATEGORIES ) {
	for ( const name of names ) {
		addIcon( name, icons );
	}
}

/**
 * Icônes des écrans d'administration (groupes, modules, accueil) : jeu à part, absent du sélecteur du bloc.
 * Source : pixelarticons (MIT), pixel art 24 px en aplats (fill), un fichier SVG par icône.
 */
const pixelDir = dirname( require.resolve( 'pixelarticons/package.json' ) );
const pixelVersion = JSON.parse( readFileSync( join( pixelDir, 'package.json' ), 'utf8' ) ).version;
const ADMIN = [
	'feather', 'search', 'speed-fast', 'colors-swatch', 'refresh', 'blocks',
	'brush', 'image', 'shield', 'database', 'target', 'clipboard-note', 'leaf',
	'chart-line', 'pen-square', 'shapes', 'dock', 'eye', 'layout', 'camera',
	'heading', 'sparkles', 'collapse', 'checkbox-on', 'power', 'scale', 'arrow-right', 'message', 'calendar', 'cloud', 'link',
	// Onglets et messages d'état.
	'sliders-horizontal', 'images', 'archive', 'check', 'warning-diamond', 'square-alert', 'circle-info',
	// Éditeur de pages : types de pages, catégories de sections, boutons.
	'home', 'user', 'briefcase', 'mail', 'heart', 'coins', 'users', 'file', 'plus', 'unlock', 'flag', 'article', 'book-open', 'trophy', 'zap', 'grid-3x3', 'layout-solid',
];
const adminIcons = new Map();
for ( const name of ADMIN ) {
	if ( ! KEBAB.test( name ) || adminIcons.has( name ) ) {
		errors.push( `${ name } : nom invalide ou listé deux fois` );
		continue;
	}
	let svg;
	try {
		svg = readFileSync( join( pixelDir, 'svg', `${ name }.svg` ), 'utf8' );
	} catch {
		errors.push( `${ name } : absente de pixelarticons ${ pixelVersion }` );
		continue;
	}
	const body = svg.replace( /^\s*<svg\b[^>]*>/, '' ).replace( /<\/svg>\s*$/, '' ).trim();
	const paths = [ ...body.matchAll( /<path d="([^"]*)"\s*\/>/g ) ];
	// Tout ce qui n'est pas un <path d="…"/> est refusé : le reste du corps doit être vide.
	if ( paths.length === 0 || body.replace( /<path d="[^"]*"\s*\/>/g, '' ).trim() !== '' ) {
		errors.push( `${ name } : contenu hors liste blanche (seul <path d> est permis)` );
		continue;
	}
	const inner = paths.map( ( [ , d ] ) => {
		if ( ! SAFE_VALUE.test( d ) ) {
			errors.push( `${ name } : attribut d="${ d }" refusé` );
		}
		return `<path d="${ d }"/>`;
	} ).join( '' );
	adminIcons.set( name, inner );
}

if ( errors.length > 0 ) {
	console.error( `build-icons : ${ errors.length } erreur(s)\n  ${ errors.join( '\n  ' ) }` );
	process.exit( 1 );
}

// Les valeurs ne contiennent ni apostrophe ni barre oblique inverse (SAFE_VALUE) : aucun échappement PHP requis.
const php = [
	'<?php',
	'/**',
	` * Fichier généré par tools/build-icons.mjs depuis lucide-static ${ version }. Ne pas modifier à la main.`,
	' * Licence des icônes : ISC (Lucide) et MIT (Feather), voir LICENSE dans ce dossier.',
	' *',
	' * @package Fluffless_Suite',
	' */',
	'',
	'// phpcs:ignoreFile -- fichier généré.',
	'',
	'return array(',
	`\t'version'    => '${ version }',`,
	"\t'categories' => array(",
	...CATEGORIES.map( ( [ slug, label, names ] ) =>
		`\t\tarray( 'slug' => '${ slug }', 'label' => '${ label.replace( /'/g, "\\'" ) }', 'names' => array( ${ names.map( ( n ) => `'${ n }'` ).join( ', ' ) } ) ),`
	),
	'\t),',
	"\t'icons'      => array(",
	...[ ...icons ].map( ( [ name, inner ] ) => `\t\t'${ name }' => '${ inner }',` ),
	'\t),',
	');',
	'',
].join( '\n' );

writeFileSync( join( outDir, 'icons.php' ), php );
copyFileSync( join( lucideDir, 'LICENSE' ), join( outDir, 'LICENSE' ) );

const bytes = Buffer.byteLength( php );
console.log( `build-icons : ${ icons.size } icônes, ${ CATEGORIES.length } catégories, icons.php ${ bytes } octets (lucide-static ${ version })` );

const adminDir = join( root, 'packages/fluffless-suite/assets/admin' );
const adminPhp = [
	'<?php',
	'/**',
	` * Fichier généré par tools/build-icons.mjs depuis pixelarticons ${ pixelVersion }. Ne pas modifier à la main.`,
	' * Icônes des écrans Fluffless (Admin\\Icon). Licence : MIT (Gerrit Halfmann), voir LICENSE-icons.',
	' *',
	' * @package Fluffless_Suite',
	' */',
	'',
	'// phpcs:ignoreFile -- fichier généré.',
	'',
	'return array(',
	...[ ...adminIcons ].map( ( [ name, inner ] ) => `\t'${ name }' => '${ inner }',` ),
	');',
	'',
].join( '\n' );
writeFileSync( join( adminDir, 'icons.php' ), adminPhp );
copyFileSync( join( pixelDir, 'LICENSE' ), join( adminDir, 'LICENSE-icons' ) );
console.log( `build-icons : ${ adminIcons.size } icônes d’administration, ${ Buffer.byteLength( adminPhp ) } octets (pixelarticons ${ pixelVersion })` );
