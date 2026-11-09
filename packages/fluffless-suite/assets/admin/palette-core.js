/* Générateur de palettes durables : cœur sans DOM, partagé par l'écran Palette et ses tests.
   Porté du générateur de palettes durables de Geneviève Bergeron. Critères gardés : modèle OLED
   (somme RVB, bleu pénalisé), pondération par surface occupée, jamais de blanc pur, correction
   automatique au contraste AA 4,5:1. Ses 5 rôles deviennent les 9 de theme.json : Texte, Texte sur
   accent, Bordure et Alerte sont dérivés puis validés. Une couleur verrouillée n'est jamais
   modifiée : si elle échoue au contraste, check() le signale au lieu de la corriger en douce. */
( function ( root, factory ) {
	const api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.flPalette = api;
	}
} )( typeof window !== 'undefined' ? window : globalThis, function () {
	'use strict';

	// Coût énergétique par canal sur écran OLED (bleu pénalisé d'environ 25 %).
	const CH = { r: 1, g: 1, b: 1.25 };
	const WHITE = 255 * ( CH.r + CH.g + CH.b );
	// Part de l'écran occupée par chaque rôle (le fond couvre le plus de surface).
	const WEIGHT = { base: 0.55, surface: 0.2, muted: 0.12, accent: 0.08, 'accent-2': 0.05 };
	const AA = 4.5;

	const ROLES = [ 'base', 'surface', 'contrast', 'muted', 'divider', 'accent', 'on-accent', 'accent-2', 'alert' ];
	// Rôles facultatifs (Palette::OPTIONAL_ROLES) : jamais générés, vérifiés quand ils sont présents.
	const OPTIONAL = [ 'button-bg' ];
	const UI = 3;

	// Paires texte/fond de tools/check-contrast.mjs : [texte, fond], 4,5:1 partout.
	const PAIRS = [
		[ 'contrast', 'base' ],
		[ 'contrast', 'surface' ],
		[ 'muted', 'base' ],
		[ 'muted', 'surface' ],
		[ 'accent', 'base' ],
		[ 'accent', 'surface' ],
		[ 'on-accent', 'accent' ],
		[ 'accent-2', 'base' ],
		[ 'accent-2', 'surface' ],
		[ 'base', 'accent-2' ],
		[ 'alert', 'base' ],
		[ 'base', 'contrast' ],
		[ 'on-accent', 'button-bg' ],
	];

	// Contour d'un composant contre son fond (WCAG 1.4.11) : [composant, fond], 3:1.
	const UI_PAIRS = [
		[ 'button-bg', 'base' ],
		[ 'button-bg', 'surface' ],
	];

	// Bordure de bouton déclarée par le thème (Palette::button()) : elle remplace UI_PAIRS.
	const BORDER_PAIRS = [
		[ 'button-border', 'base' ],
		[ 'button-border', 'surface' ],
	];

	// Ambiances : plage de teinte d'ancrage et saturation.
	const MOODS = {
		variee: { range: [ 0, 360 ], sat: [ 22, 52 ] },
		terreuse: { range: [ 12, 48 ], sat: [ 24, 46 ] },
		foret: { range: [ 95, 165 ], sat: [ 20, 42 ] },
		crepuscule: { range: [ 200, 290 ], sat: [ 22, 40 ] },
		prune: { range: [ 300, 350 ], sat: [ 24, 48 ] },
		lagon: { range: [ 160, 200 ], sat: [ 22, 44 ] },
		bijou: { range: [ 0, 360 ], sat: [ 40, 62 ] },
		neutre: { range: [ 0, 360 ], sat: [ 6, 16 ] },
	};
	// Plages de luminosité selon la rigueur énergétique (plus sombre = moins d'énergie).
	const STRICT = {
		max: { bgL: [ 7, 11 ], surfL: [ 13, 17 ], mutedL: [ 40, 52 ], accL: [ 44, 56 ], acc2L: [ 38, 50 ] },
		equilibre: { bgL: [ 9, 14 ], surfL: [ 16, 22 ], mutedL: [ 48, 60 ], accL: [ 52, 64 ], acc2L: [ 44, 58 ] },
		souple: { bgL: [ 12, 18 ], surfL: [ 20, 28 ], mutedL: [ 56, 68 ], accL: [ 58, 72 ], acc2L: [ 50, 64 ] },
	};
	const HARMONIES = [ 'libre', 'mono', 'analogue', 'complementaire', 'triade' ];

	const clamp = ( v, a, b ) => Math.max( a, Math.min( b, v ) );
	const wrap = ( h ) => ( ( h % 360 ) + 360 ) % 360;

	function hslToRgb( h, s, l ) {
		h = wrap( h );
		s /= 100;
		l /= 100;
		const c = ( 1 - Math.abs( 2 * l - 1 ) ) * s;
		const x = c * ( 1 - Math.abs( ( ( h / 60 ) % 2 ) - 1 ) );
		const m = l - c / 2;
		const [ r, g, b ] = [ [ c, x, 0 ], [ x, c, 0 ], [ 0, c, x ], [ 0, x, c ], [ x, 0, c ], [ c, 0, x ] ][ Math.floor( h / 60 ) % 6 ];
		return [ r, g, b ].map( ( v ) => Math.round( ( v + m ) * 255 ) );
	}
	const hex = ( rgb ) => '#' + rgb.map( ( v ) => clamp( v, 0, 255 ).toString( 16 ).padStart( 2, '0' ) ).join( '' );
	const rgb = ( h ) => [ 1, 3, 5 ].map( ( i ) => parseInt( h.slice( i, i + 2 ), 16 ) );

	function luminance( h ) {
		const a = rgb( h ).map( ( v ) => {
			v /= 255;
			return v <= 0.04045 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * a[ 0 ] + 0.7152 * a[ 1 ] + 0.0722 * a[ 2 ];
	}
	function ratio( a, b ) {
		const x = luminance( a );
		const y = luminance( b );
		return ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 );
	}
	function hueOf( h ) {
		const [ r, g, b ] = rgb( h ).map( ( v ) => v / 255 );
		const mx = Math.max( r, g, b );
		const d = mx - Math.min( r, g, b );
		if ( 0 === d ) {
			return 0;
		}
		let hue;
		if ( mx === r ) {
			hue = ( ( g - b ) / d + 6 ) % 6;
		} else if ( mx === g ) {
			hue = ( b - r ) / d + 2;
		} else {
			hue = ( r - g ) / d + 4;
		}
		return wrap( hue * 60 );
	}
	// Mélange linéaire en RVB : t = part de b.
	const mix = ( a, b, t ) => {
		const x = rgb( a );
		const y = rgb( b );
		return hex( x.map( ( v, i ) => Math.round( v * ( 1 - t ) + y[ i ] * t ) ) );
	};

	// Le bleu coûte plus : dans 200-270°, saturation et luminosité bridées.
	function swatch( h, s, l ) {
		h = wrap( h );
		const blue = h >= 200 && h <= 270;
		return hex( hslToRgb( h, blue ? Math.min( s, 42 ) : s, clamp( blue ? l - 4 : l, 0, 100 ) ) );
	}

	// Éclaircit (fond sombre) ou fonce (fond clair) jusqu'à 4,5:1 sur chaque fond donné.
	// Bornes 6 et 94 : jamais de noir ni de blanc purs.
	function readable( h, s, l, grounds, up ) {
		let color = swatch( h, s, l );
		while ( grounds.some( ( g ) => ratio( color, g ) < AA ) && l > 6 && l < 94 ) {
			l += up ? 2 : -2;
			color = swatch( h, s, l );
		}
		return color;
	}

	const tempOf = ( h ) => ( wrap( h ) >= 60 && wrap( h ) < 300 ? 'froid' : 'chaud' );

	// Teintes des deux accents selon l'harmonie, à partir de la teinte du fond.
	function accentHues( anchor, harmony, between, pick ) {
		let h;
		let d;
		switch ( harmony ) {
			case 'mono':
				h = wrap( anchor + between( -8, 8 ) );
				return [ h, wrap( h + between( -12, 12 ) ) ];
			case 'analogue':
				d = pick( [ 1, -1 ] );
				return [ wrap( anchor + d * between( 25, 45 ) ), wrap( anchor - d * between( 20, 40 ) ) ];
			case 'complementaire':
				return [ wrap( anchor + 180 + between( -12, 12 ) ), wrap( anchor + 180 + pick( [ 1, -1 ] ) * between( 25, 40 ) ) ];
			case 'triade':
				return [ wrap( anchor + 120 + between( -10, 10 ) ), wrap( anchor + 240 + between( -10, 10 ) ) ];
			default: {
				// Libre : accent dans le groupe de température opposé, 30° au moins entre les deux.
				const target = 'froid' === tempOf( anchor ) ? 'chaud' : 'froid';
				h = wrap( anchor + 180 + between( -35, 35 ) );
				for ( let guard = 0; tempOf( h ) !== target && guard < 12; guard++ ) {
					h = wrap( h + 30 );
				}
				return [ h, wrap( h + ( between( 0, 1 ) < 0.5 ? 1 : -1 ) * between( 30, 70 ) ) ];
			}
		}
	}

	/**
	 * Palette complète { rôle: '#rrggbb' }.
	 * opts : mood, strict, harmony, locked { rôle: '#rrggbb' }, rand (fonction 0-1, pour les tests).
	 */
	function generate( opts ) {
		const o = opts || {};
		const rand = 'function' === typeof o.rand ? o.rand : Math.random;
		const between = ( a, b ) => a + rand() * ( b - a );
		const pick = ( arr ) => arr[ Math.floor( rand() * arr.length ) ];
		const M = MOODS[ o.mood ] || MOODS.variee;
		const S = STRICT[ o.strict ] || STRICT.equilibre;
		const lock = {};
		for ( const role of ROLES ) {
			const value = o.locked && o.locked[ role ];
			if ( 'string' === typeof value && /^#[0-9a-f]{6}$/i.test( value ) ) {
				lock[ role ] = value.toLowerCase();
			}
		}

		const anchor = lock.base ? hueOf( lock.base ) : wrap( between( M.range[ 0 ], M.range[ 1 ] ) );
		const sat = between( M.sat[ 0 ], M.sat[ 1 ] );
		const hues = accentHues( anchor, o.harmony, between, pick );
		const p = {};

		p.base = lock.base || swatch( anchor, sat * 0.6, between( ...S.bgL ) );
		// Fond clair verrouillé (marque) : les textes foncent au lieu d'éclaircir.
		const up = luminance( p.base ) < 0.18;
		p.surface = lock.surface || ( up ? swatch( anchor + between( -6, 6 ), sat * 0.7, between( ...S.surfL ) ) : mix( p.base, '#000000', 0.06 ) );
		const grounds = [ p.base, p.surface ];
		p.contrast = lock.contrast || readable( anchor, sat * 0.25, up ? between( 88, 93 ) : between( 8, 12 ), grounds, up );
		p.muted = lock.muted || readable( anchor + between( -25, 25 ), sat * 0.8, up ? between( ...S.mutedL ) : 100 - between( ...S.mutedL ), grounds, up );
		p.accent = lock.accent || readable( hues[ 0 ], between( M.sat[ 0 ] + 15, M.sat[ 1 ] + 18 ), up ? between( ...S.accL ) : 100 - between( ...S.accL ), grounds, up );
		p[ 'accent-2' ] = lock[ 'accent-2' ] || readable( hues[ 1 ], between( M.sat[ 0 ], M.sat[ 1 ] ), up ? between( ...S.acc2L ) : 100 - between( ...S.acc2L ), grounds, up );
		p.alert = lock.alert || readable( between( 2, 14 ), between( 55, 70 ), up ? between( ...S.accL ) : 100 - between( ...S.accL ), grounds, up );
		p[ 'on-accent' ] = lock[ 'on-accent' ] || ( ratio( p.base, p.accent ) >= ratio( p.contrast, p.accent ) ? p.base : p.contrast );
		p.divider = lock.divider || mix( p.base, p.muted, 0.3 );

		const out = {};
		for ( const role of ROLES ) {
			out[ role ] = p[ role ];
		}
		return out;
	}

	/**
	 * Paires sous leur seuil (4,5:1 texte, 3:1 texte large du bouton et composant), au format
	 * 'premier plan/fond'. button : { border, large } de Palette::button() ; border est un rôle,
	 * un #rrggbb ou vide.
	 */
	function check( pal, button = { border: '', large: false } ) {
		const border = button.border || '';
		const all = { ...pal };
		if ( border ) {
			const color = border.startsWith( '#' ) ? border : pal[ border ];
			if ( color ) {
				all[ 'button-border' ] = color;
			}
		}
		const low = ( [ t, b ] ) => {
			const min = button.large && 'button-bg' === b ? UI : AA;
			return all[ t ] && all[ b ] && ratio( all[ t ], all[ b ] ) < min;
		};
		const lowUi = ( [ t, b ] ) => all[ t ] && all[ b ] && ratio( all[ t ], all[ b ] ) < UI;
		return [ ...PAIRS.filter( low ), ...( border ? BORDER_PAIRS : UI_PAIRS ).filter( lowUi ) ].map( ( [ t, b ] ) => t + '/' + b );
	}

	/** Indice de 0 à 100 : part d'énergie évitée par rapport au blanc pur sur la même surface (écran OLED). */
	function score( pal ) {
		let total = 0;
		for ( const role of Object.keys( WEIGHT ) ) {
			const [ r, g, b ] = rgb( pal[ role ] );
			total += ( ( r * CH.r + g * CH.g + b * CH.b ) / WHITE ) * WEIGHT[ role ];
		}
		return clamp( Math.round( ( 1 - total ) * 100 ), 0, 100 );
	}

	return { ROLES, OPTIONAL, PAIRS, UI_PAIRS, BORDER_PAIRS, MOODS, STRICT, HARMONIES, AA, generate, check, score, ratio, mix };
} );
