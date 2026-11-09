/**
 * Écran « Médiathèque WebP » : lance les lots un par un, sur clic, jusqu'à la fin ou la pause.
 * JavaScript natif, sans build, administration seulement. apiFetch ajoute le nonce REST.
 */
( function ( wp ) {
	const { __, sprintf } = wp.i18n;
	const start = document.getElementById( 'fluffless-webp-start' );
	const stop = document.getElementById( 'fluffless-webp-stop' );
	const bar = document.getElementById( 'fluffless-webp-progress' );
	const log = document.getElementById( 'fluffless-webp-log' );
	const stats = document.getElementById( 'fluffless-webp-stats' );
	if ( ! start || ! bar || ! log || ! stats ) {
		return;
	}
	const MAX_BATCHES = 2000; // Borne : 2000 lots de 5 images, bien au-delà d'une médiathèque réelle.
	let paused = false;
	let saved = 0;

	function say( text ) {
		const p = document.createElement( 'p' );
		p.textContent = text;
		log.replaceChildren( p );
	}

	async function run() {
		paused = false;
		start.disabled = true;
		stop.hidden = false;
		const total = Number( stats.dataset.remaining ) || 0;
		bar.max = Math.max( 1, total );
		let done = 0;
		try {
			for ( let i = 0; i < MAX_BATCHES && ! paused; i++ ) {
				const data = await wp.apiFetch( { path: '/fluffless/v1/webp/batch', method: 'POST', data: { limit: 5 } } );
				done += data.processed.length;
				saved += data.processed.reduce( ( sum, item ) => sum + item.saved, 0 );
				bar.value = Math.min( bar.max, done );
				say(
					sprintf(
						/* translators: 1: images processed; 2: images in total; 3: kilobytes saved */
						__( '%1$d of %2$d images processed. %3$s KB saved.', 'fluffless-suite' ),
						done,
						total,
						Math.round( saved / 1000 ).toLocaleString()
					)
				);
				if ( data.remaining === 0 || data.processed.length === 0 ) {
					say( sprintf( __( 'Finished. %s KB saved on this run.', 'fluffless-suite' ), Math.round( saved / 1000 ).toLocaleString() ) );
					stop.hidden = true;
					return;
				}
			}
			if ( paused ) {
				say( __( 'Paused. Click again to continue.', 'fluffless-suite' ) );
			}
		} catch ( error ) {
			say( ( error && error.message ) || __( 'The conversion stopped because of an error. Try again.', 'fluffless-suite' ) );
		}
		start.disabled = false;
		stop.hidden = true;
	}

	start.addEventListener( 'click', run );
	stop.addEventListener( 'click', () => {
		paused = true;
	} );
} )( window.wp );
