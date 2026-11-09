/**
 * Bouton « Copier » des écrans d'administration : chaque [data-fl-copy] copie le champ visé
 * (sa valeur, ou son texte). Messages lus dans data-fl-copy-done et data-fl-copy-fail, traduits
 * par PHP. Sans presse-papiers, le bouton reste caché : le champ se sélectionne à la main.
 */
( function () {
	if ( ! navigator.clipboard ) {
		return;
	}
	document.querySelectorAll( '[data-fl-copy]' ).forEach( ( button ) => {
		const status = button.parentNode.querySelector( '[data-fl-copy-status]' );
		button.hidden = false;
		button.addEventListener( 'click', () => {
			const source = document.getElementById( button.dataset.flCopy );
			const text = source ? ( 'value' in source ? source.value : source.textContent ) : '';
			navigator.clipboard.writeText( text ).then(
				() => { if ( status ) { status.textContent = button.dataset.flCopyDone || ''; } },
				() => { if ( status ) { status.textContent = button.dataset.flCopyFail || ''; } }
			);
		} );
	} );
} )();
