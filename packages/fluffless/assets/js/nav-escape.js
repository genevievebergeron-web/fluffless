/**
 * Échap ferme le <details data-fluffless-esc> ouvert qui contient le focus et rend le focus à
 * son summary. Quand le focus sort d'un tel panneau (tabulation au-delà), le panneau se ferme
 * aussi, pour ne pas rester ouvert par-dessus le contenu. Rien d'autre : les <details> sans
 * l'attribut (FAQ) gardent leur comportement.
 *
 * @package Fluffless
 */
( function () {
	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key || event.defaultPrevented ) {
			return;
		}
		var active = document.activeElement;
		var details = active && active.closest ? active.closest( 'details[data-fluffless-esc][open]' ) : null;
		if ( ! details ) {
			return;
		}
		details.removeAttribute( 'open' );
		var summary = details.querySelector( ':scope > summary' );
		if ( summary ) {
			summary.focus();
		}
		event.preventDefault();
	} );
	document.addEventListener( 'focusout', function ( event ) {
		var details = event.target && event.target.closest ? event.target.closest( 'details[data-fluffless-esc][open]' ) : null;
		var next = event.relatedTarget;
		if ( details && next && ! details.contains( next ) ) {
			details.removeAttribute( 'open' );
		}
	} );
} )();
