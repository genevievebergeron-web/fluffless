/* Avant et après : le curseur natif pose la variable CSS que la feuille lit et la valeur lue
   (modèle traduit dans data-fl-valuetext : {b} part d'avant, {a} part d'après). Aucune dépendance. */
document.querySelectorAll( '.fluffless-avant-apres__range' ).forEach( ( range ) => {
	range.addEventListener( 'input', () => {
		range.parentElement.style.setProperty( '--fl-p', range.value + '%' );
		const text = range.dataset.flValuetext;
		if ( text ) {
			range.setAttribute( 'aria-valuetext', text.replace( '{b}', range.value ).replace( '{a}', 100 - range.value ) );
		}
	} );
} );
