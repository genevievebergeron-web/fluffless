/* Vidéo en façade : le lecteur n'existe qu'après le clic, et seulement quand l'extension de
   consentement est absente ou que le contenu intégré est accepté (script inerte activé par elle).
   Sinon le lien s'ouvre sur la page du fournisseur. Aucune dépendance. */
const allowed = () => ! document.getElementById( 'fluffless-consent' ) || '1' === document.documentElement.dataset.flMedia;
document.querySelectorAll( '.fluffless-video__facade' ).forEach( ( link ) => {
	link.addEventListener( 'click', ( event ) => {
		if ( ! allowed() ) {
			return;
		}
		event.preventDefault();
		const frame = document.createElement( 'iframe' );
		frame.className = 'fluffless-video__frame';
		frame.src = link.dataset.embed;
		frame.title = link.dataset.title || '';
		frame.allow = 'autoplay; fullscreen; picture-in-picture';
		frame.referrerPolicy = 'strict-origin-when-cross-origin';
		link.replaceWith( frame );
		frame.focus();
	} );
} );
