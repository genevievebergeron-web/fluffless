/**
 * Barre d'enregistrement : ressort (classe is-dirty) dès qu'un champ du formulaire change.
 * Sans ce script, la barre reste visible et utilisable, simplement discrète.
 */
( function () {
	document.querySelectorAll( '[data-fl-savebar]' ).forEach( function ( bar ) {
		const form = bar.closest( 'form' );
		if ( ! form ) {
			return;
		}
		const mark = function () {
			bar.classList.add( 'is-dirty' );
		};
		form.addEventListener( 'input', mark, { once: true } );
		form.addEventListener( 'change', mark, { once: true } );
	} );
} )();
