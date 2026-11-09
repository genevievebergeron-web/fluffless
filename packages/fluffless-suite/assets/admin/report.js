/**
 * Écran « Report a problem » : réduit la capture dans le navigateur. La copie du diagnostic passe par copy.js.
 * Sans JavaScript, le formulaire marche quand même (le serveur refuse une capture de plus de 1 Mo).
 */
( function ( i18n ) {
	const MAX_BYTES = 1024 * 1024;
	const MAX_WIDTH = 1600;
	const __ = i18n.__;

	// Réencode en WebP, largeur bornée, qualité descendante jusqu'à passer sous 1 Mo (3 essais au plus).
	async function shrink( file ) {
		const bitmap = await createImageBitmap( file );
		const scale = Math.min( 1, MAX_WIDTH / bitmap.width );
		const canvas = document.createElement( 'canvas' );
		canvas.width = Math.round( bitmap.width * scale );
		canvas.height = Math.round( bitmap.height * scale );
		canvas.getContext( '2d' ).drawImage( bitmap, 0, 0, canvas.width, canvas.height );
		for ( const quality of [ 0.85, 0.7, 0.5 ] ) {
			const blob = await new Promise( ( done ) => canvas.toBlob( done, 'image/webp', quality ) );
			if ( blob && blob.type === 'image/webp' && blob.size <= MAX_BYTES ) {
				return new File( [ blob ], 'screenshot.webp', { type: 'image/webp' } );
			}
		}
		return null;
	}

	const input = document.getElementById( 'fl-report-shot' );
	if ( input && window.DataTransfer && window.createImageBitmap ) {
		input.addEventListener( 'change', async () => {
			const file = input.files && input.files[ 0 ];
			if ( ! file || ( file.size <= MAX_BYTES && /^image\/(png|webp)$/.test( file.type ) ) ) {
				return;
			}
			const smaller = await shrink( file ).catch( () => null );
			const list = new DataTransfer();
			if ( smaller ) {
				list.items.add( smaller );
			} else {
				input.setCustomValidity( __( 'This image could not be made smaller than 1 MB. Choose another one.', 'fluffless-suite' ) );
				input.reportValidity();
			}
			input.files = list.files;
		} );
		input.addEventListener( 'input', () => input.setCustomValidity( '' ) );
	}
} )( window.wp.i18n );
