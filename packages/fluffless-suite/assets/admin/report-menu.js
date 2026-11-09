/**
 * Entrée « Report a problem » du menu ⋮ de l'éditeur. Sans build : globales wp.*.
 * Chargé par enqueue_block_editor_assets seulement, pour qui peut signaler.
 */
( function ( wp, settings ) {
	const MenuItem = ( wp.editor && wp.editor.PluginMoreMenuItem ) || ( wp.editPost && wp.editPost.PluginMoreMenuItem );
	if ( ! MenuItem || ! wp.plugins || ! settings || ! settings.url ) {
		return;
	}
	wp.plugins.registerPlugin( 'fluffless-report', {
		render: () => wp.element.createElement( MenuItem, { href: settings.url, icon: 'warning' }, wp.i18n.__( 'Report a problem', 'fluffless-suite' ) ),
	} );
} )( window.wp, window.flufflessReport );
