/**
 * Pose sur le corps du canevas de l'éditeur (l'iframe) les classes déclarées par le thème :
 * add_theme_support( 'fluffless-editor-canvas-classes', array( 'ma-classe' ) ).
 * WordPress ne copie dans l'iframe que admin-color-*, post-type-* et wp-embed-responsive ;
 * une feuille préfixée par une classe du corps y resterait sans effet.
 * Chargé par enqueue_block_assets : ne fait rien hors d'une iframe. Les classes sont reposées
 * quand l'éditeur réécrit l'attribut class, 50 fois au plus par document.
 *
 * @package Fluffless
 */
( function () {
	var list = window.flufflessEditorCanvasClasses;
	if ( ! Array.isArray( list ) || ! list.length || ! window.frameElement ) {
		return;
	}
	var MAX = 50;
	var applied = 0;
	var observed = null;
	var bodyObserver = new MutationObserver( apply );
	var rootObserver = new MutationObserver( function () {
		observeBody();
		apply();
	} );

	function apply() {
		var body = document.body;
		if ( ! body || ! body.classList.contains( 'block-editor-iframe__body' ) ) {
			return;
		}
		var missing = list.filter( function ( name ) {
			return typeof name === 'string' && name !== '' && ! body.classList.contains( name );
		} );
		if ( ! missing.length ) {
			return;
		}
		if ( applied >= MAX ) {
			bodyObserver.disconnect();
			rootObserver.disconnect();
			return;
		}
		applied++;
		body.classList.add.apply( body.classList, missing );
	}

	function observeBody() {
		if ( document.body && document.body !== observed ) {
			bodyObserver.disconnect();
			bodyObserver.observe( document.body, { attributes: true, attributeFilter: [ 'class' ] } );
			observed = document.body;
		}
	}

	apply();
	observeBody();
	rootObserver.observe( document.documentElement, { childList: true } );
} )();
