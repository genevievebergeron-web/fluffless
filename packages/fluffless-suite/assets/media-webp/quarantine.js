/**
 * Écran « Images inutilisées » : total des octets cochés, confirmation avant la purge.
 * Amélioration progressive : sans JavaScript, le formulaire fonctionne (case de confirmation côté serveur).
 */
( function ( wp ) {
	const { __ } = wp.i18n;
	const total = document.getElementById( 'fluffless-quarantine-total' );
	const boxes = document.querySelectorAll( 'input[name="ids[]"], input[name="originals[]"]' );

	function update() {
		let bytes = 0;
		boxes.forEach( ( box ) => {
			if ( box.checked ) {
				bytes += Number( box.dataset.bytes ) || 0;
			}
		} );
		total.textContent = ( bytes / 1000 ).toLocaleString( undefined, { maximumFractionDigits: 0 } ) + ' ' + __( 'KB', 'fluffless-suite' );
	}

	if ( total ) {
		boxes.forEach( ( box ) => box.addEventListener( 'change', update ) );
		update();
	}

	const purge = document.querySelector( 'button[value="purge"]' );
	if ( purge ) {
		purge.addEventListener( 'click', ( event ) => {
			// eslint-disable-next-line no-alert -- confirmation native avant une suppression définitive.
			if ( ! window.confirm( purge.dataset.confirm ) ) {
				event.preventDefault();
			}
		} );
	}
} )( window.wp );
