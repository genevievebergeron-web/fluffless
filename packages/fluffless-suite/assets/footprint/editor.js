/**
 * Panneau « Empreinte de cette page » de Fluffless Suite. JavaScript natif, sans build, éditeur seulement.
 *
 * Mesure : l'aperçu de la page est chargé dans un cadre caché (barre d'administration retirée
 * côté serveur), puis l'API Resource Timing donne le poids compressé réel de chaque fichier.
 * Les images différées (loading="lazy") hors écran sont ajoutées par requête HEAD.
 * Le poids part au serveur, qui calcule (SWDM v4) et enregistre : le panneau n'invente aucun chiffre.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { useState, useEffect, useRef, useCallback } = wp.element;
	const { __, sprintf, _n } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editor;
	const { Button, ExternalLink, Notice, Spinner } = wp.components;
	const { useSelect, subscribe, select } = wp.data;
	const apiFetch = wp.apiFetch;
	const { addQueryArgs } = wp.url;

	const config = window.flufflessFootprint || {};
	const LOAD_TIMEOUT_MS = 20000; // Borne : une page qui ne finit pas de charger n'est pas mesurée.
	const SETTLE_MS = 1500; // Laisse partir les requêtes tardives (polices, images) après onload.
	const MAX_ENTRIES = 300; // Borne sur le nombre de fichiers comptés.
	const locale = document.documentElement.lang || undefined;

	/** Deux chiffres significatifs, comme Footprint::format_grams(). */
	function grams( value ) {
		if ( ! value || value <= 0 ) {
			return '0';
		}
		const decimals = Math.max( 0, Math.min( 4, 1 - Math.floor( Math.log10( value ) ) ) );
		return new Intl.NumberFormat( locale, { minimumFractionDigits: decimals, maximumFractionDigits: decimals } ).format( value );
	}

	function kilobytes( bytes ) {
		return new Intl.NumberFormat( locale, { maximumFractionDigits: 0 } ).format( bytes / 1000 );
	}

	/** Charge l'adresse dans un cadre caché et additionne les poids transférés. */
	async function measurePage( url ) {
		const frame = document.createElement( 'iframe' );
		frame.setAttribute( 'aria-hidden', 'true' );
		frame.tabIndex = -1;
		frame.style.cssText = 'position:absolute;left:-10000px;top:0;width:1280px;height:800px;visibility:hidden;border:0';
		frame.src = addQueryArgs( url, { [ config.measureArg ]: 1 } );
		try {
			await new Promise( ( resolve, reject ) => {
				const timer = setTimeout( () => reject( new Error( 'timeout' ) ), LOAD_TIMEOUT_MS );
				frame.addEventListener( 'load', () => {
					clearTimeout( timer );
					resolve();
				} );
				document.body.appendChild( frame );
			} );
			await new Promise( ( resolve ) => setTimeout( resolve, SETTLE_MS ) );

			const win = frame.contentWindow;
			const perf = win.performance;
			const nav = perf.getEntriesByType( 'navigation' )[ 0 ];
			let bytes = nav ? nav.encodedBodySize || nav.transferSize || 0 : 0;
			let requests = 1;
			let unknown = 0;
			let largest = { url: '', bytes: 0 };
			const seen = new Set();

			for ( const entry of perf.getEntriesByType( 'resource' ).slice( 0, MAX_ENTRIES ) ) {
				seen.add( entry.name );
				requests++;
				// Fichier tiers sans Timing-Allow-Origin : taille illisible, signalée plutôt que devinée.
				const size = entry.encodedBodySize || entry.transferSize || 0;
				if ( ! size ) {
					unknown++;
				}
				bytes += size;
				if ( size > largest.bytes ) {
					largest = { url: entry.name, bytes: size };
				}
			}

			const lazy = Array.from( win.document.images )
				.map( ( img ) => img.currentSrc || img.src )
				.filter( ( src ) => src && ! seen.has( src ) && new URL( src, url ).origin === window.location.origin );
			for ( const src of [ ...new Set( lazy ) ].slice( 0, MAX_ENTRIES ) ) {
				const response = await fetch( src, { method: 'HEAD', credentials: 'same-origin' } );
				const size = Number( response.headers.get( 'content-length' ) ) || 0;
				requests++;
				if ( ! size ) {
					unknown++;
				}
				bytes += size;
				if ( size > largest.bytes ) {
					largest = { url: src, bytes: size };
				}
			}
			return { bytes, requests, unknown, largest };
		} finally {
			frame.remove();
		}
	}

	function hostLine( host ) {
		if ( 'yes' === host ) {
			return __( 'Host recognized by the Green Web Foundation (Fluff check).', 'fluffless-suite' );
		}
		if ( 'no' === host ) {
			return __( 'Host not recognized by the Green Web Foundation (Fluff check).', 'fluffless-suite' );
		}
		return __( 'Host not checked yet: run the Fluff check to include it.', 'fluffless-suite' );
	}

	function FootprintPanel() {
		const { postId, previewLink, status, permalink, stored } = useSelect( ( sel ) => {
			const editor = sel( 'core/editor' );
			const meta = editor.getEditedPostAttribute( 'meta' ) || {};
			return {
				postId: editor.getCurrentPostId(),
				previewLink: editor.getEditedPostPreviewLink(),
				status: editor.getEditedPostAttribute( 'status' ),
				permalink: editor.getPermalink(),
				stored: meta[ config.metaKey ] || null,
			};
		}, [] );
		const [ result, setResult ] = useState( null );
		const [ unknown, setUnknown ] = useState( 0 );
		const [ busy, setBusy ] = useState( false );
		const [ error, setError ] = useState( '' );
		const busyRef = useRef( false );

		const run = useCallback( async () => {
			if ( busyRef.current || ! postId || ! previewLink ) {
				return;
			}
			busyRef.current = true;
			setBusy( true );
			setError( '' );
			try {
				const page = await measurePage( previewLink );
				const saved = await apiFetch( {
					path: '/fluffless/v1/footprint/' + postId,
					method: 'POST',
					data: {
						bytes: Math.round( page.bytes ),
						requests: page.requests,
						largest_url: page.largest.url.slice( 0, 2048 ),
						largest_bytes: Math.round( page.largest.bytes ),
					},
				} );
				setResult( saved );
				setUnknown( page.unknown );
			} catch ( e ) {
				setError(
					/* translators: %s: technical error message */
					sprintf( __( 'The page could not be measured (%s). Save it, then try again.', 'fluffless-suite' ), e.message || e.code || '?' )
				);
			} finally {
				busyRef.current = false;
				setBusy( false );
			}
		}, [ postId, previewLink ] );

		// Nouvelle mesure après chaque enregistrement réussi (hors sauvegarde automatique).
		useEffect( () => {
			let wasSaving = false;
			return subscribe( () => {
				const editor = select( 'core/editor' );
				const saving = editor.isSavingPost() && ! editor.isAutosavingPost();
				if ( wasSaving && ! saving && editor.didPostSaveRequestSucceed() ) {
					run();
				}
				wasSaving = saving;
			} );
		}, [ run ] );

		const shown = result || stored;
		const children = [];
		if ( shown && shown.measured ) {
			const host = result ? result.host : shown.host;
			children.push(
				el( 'p', { key: 'estimate' },
					el( 'strong', null,
						sprintf(
							/* translators: 1: estimate for a new visit in grams, 2: estimate for a returning visit in grams */
							__( 'SWDM v4 estimate: %1$s g CO₂e (new visit), %2$s g (returning visit)', 'fluffless-suite' ),
							grams( shown.new ),
							grams( shown.return )
						)
					)
				),
				el( 'p', { key: 'grade' },
					/* translators: %s: rating from A+ to F */
					sprintf( __( 'Rating: %s (A+ to F scale of the Sustainable Web Design model)', 'fluffless-suite' ), shown.grade )
				),
				el( 'p', { key: 'weight' },
					sprintf(
						/* translators: 1: page weight in kilobytes, 2: number of requests */
						_n( 'Measured weight: %1$s KB, %2$s request', 'Measured weight: %1$s KB, %2$s requests', shown.requests, 'fluffless-suite' ),
						kilobytes( shown.bytes ),
						shown.requests
					)
				),
				el( 'p', { key: 'host' }, hostLine( host ) ),
				el( 'p', { key: 'grid' },
					sprintf(
						/* translators: 1: country name, 2: grid intensity in grams per kWh */
						__( 'Data centre electricity: %1$s, %2$s g CO₂e/kWh (Fluffless → Modules).', 'fluffless-suite' ),
						config.country,
						new Intl.NumberFormat( locale ).format( config.intensity )
					)
				),
				el( 'p', { key: 'date' },
					sprintf(
						/* translators: %s: date and time of the measurement */
						__( 'Measured on %s.', 'fluffless-suite' ),
						new Intl.DateTimeFormat( locale, { dateStyle: 'long', timeStyle: 'short' } ).format( new Date( shown.measured * 1000 ) )
					)
				)
			);
			if ( result && result.tip ) {
				children.push( el( Notice, { key: 'tip', status: 'info', isDismissible: false }, result.tip ) );
			}
			if ( unknown > 0 ) {
				children.push(
					el( 'p', { key: 'unknown' },
						sprintf(
							/* translators: %s: number of files */
							_n( 'Estimate: %s external file does not share its size and is not counted.', 'Estimate: %s external files do not share their size and are not counted.', unknown, 'fluffless-suite' ),
							unknown
						)
					)
				);
			}
		} else {
			children.push( el( 'p', { key: 'empty' }, __( 'Not measured yet. The page is measured each time you save it.', 'fluffless-suite' ) ) );
		}
		if ( error ) {
			children.push( el( Notice, { key: 'error', status: 'error', isDismissible: false }, error ) );
		}
		if ( 'unknown' === config.host ) {
			children.push( el( 'p', { key: 'audit' }, el( 'a', { href: config.auditUrl }, __( 'Open the Fluff check', 'fluffless-suite' ) ) ) );
		}
		children.push(
			el( 'p', { key: 'actions' },
				el( Button, { variant: 'secondary', onClick: run, disabled: busy, __next40pxDefaultSize: true },
					busy ? __( 'Measuring…', 'fluffless-suite' ) : __( 'Measure now', 'fluffless-suite' )
				),
				busy && el( Spinner )
			)
		);
		if ( 'publish' === status && permalink ) {
			children.push(
				el( 'p', { key: 'carbon' },
					el( ExternalLink, { href: config.carbonUrl }, __( 'Check on Website Carbon', 'fluffless-suite' ) ),
					el( 'br' ),
					/* translators: %s: address of the published page */
					sprintf( __( 'Paste this address there: %s', 'fluffless-suite' ), permalink )
				)
			);
		}
		return el(
			PluginDocumentSettingPanel,
			{ name: 'fluffless-footprint', title: __( 'Page footprint', 'fluffless-suite' ) },
			children
		);
	}

	registerPlugin( 'fluffless-footprint', { render: FootprintPanel } );
} )( window.wp );
