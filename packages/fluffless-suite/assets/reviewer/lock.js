/**
 * Relecture (rôle Réviseur), confort seulement. JavaScript natif, sans build.
 *
 * Le texte des blocs reste sélectionnable, pour y poser une note, mais la saisie est refusée dans
 * le canevas (frappe, collage, dépôt, coupe, Entrée, effacement). Les boutons d'enregistrement
 * sont masqués par la feuille de Reviewer::hide_inserter_for_reviewer() et un avis dit, à la
 * deuxième personne, que les notes s'enregistrent seules. La sûreté ne repose pas là-dessus : la
 * liste blanche REST refuse de toute façon l'écriture du contenu.
 * Données : window.flufflessReviewerLock (notice).
 */
( function ( wp, config ) {
	if ( ! wp || ! config ) {
		return;
	}
	const EDITS = [ 'beforeinput', 'paste', 'drop', 'cut' ];
	const KEYS = [ 'Enter', 'Backspace', 'Delete' ];
	const NOTICE = 'fluffless-reviewer-lock';

	/** Zone de texte du canevas (blocs, titre) ; les notes et la barre latérale n'en sont pas. */
	const inCanvas = ( target ) => !! ( target && 'function' === typeof target.closest && target.closest( '.editor-styles-wrapper' ) && target.closest( '[contenteditable="true"]' ) );

	const refuse = ( event ) => {
		if ( 'keydown' === event.type && ! KEYS.includes( event.key ) ) {
			return;
		}
		if ( inCanvas( event.target ) ) {
			event.preventDefault();
			event.stopPropagation();
		}
	};

	// Le canevas vit dans une iframe que l'éditeur peut recréer (aperçu mobile, gabarit) : on garde chaque document une fois.
	const seen = new WeakSet();
	const guard = ( doc ) => {
		if ( ! doc || seen.has( doc ) ) {
			return;
		}
		seen.add( doc );
		EDITS.concat( 'keydown' ).forEach( ( type ) => doc.addEventListener( type, refuse, true ) );
	};
	const watch = () => {
		guard( document );
		document.querySelectorAll( 'iframe[name="editor-canvas"]' ).forEach( ( frame ) => {
			guard( frame.contentDocument );
			if ( ! seen.has( frame ) ) {
				seen.add( frame );
				frame.addEventListener( 'load', () => guard( frame.contentDocument ) );
			}
		} );
	};

	wp.domReady( () => {
		watch();
		new window.MutationObserver( watch ).observe( document.body, { childList: true, subtree: true } );
		wp.data.dispatch( 'core/notices' ).createNotice( 'info', config.notice, { id: NOTICE, isDismissible: false } );
	} );
}( window.wp, window.flufflessReviewerLock ) );
