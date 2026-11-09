/**
 * Éditeur du bloc fluffless/mode-couleur. JavaScript natif, sans étape de build.
 * Le site reçoit le rendu serveur (Blocks\ModeCouleur::render()) et le script en ligne de <head>.
 * L'aperçu montre le bouton, inerte ; quand la fonction est éteinte, une note renvoie à l'écran
 * Palette (window.flufflessModeCouleur, passé par PHP).
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps } = wp.blockEditor;

	const data = Object.assign( { active: false, url: '' }, window.flufflessModeCouleur || {} );

	const moon = el(
		'svg',
		{ viewBox: '0 0 24 24', width: 20, height: 20, 'aria-hidden': 'true', focusable: 'false', fill: 'none', stroke: 'currentColor', strokeWidth: 2 },
		el( 'path', { d: 'M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z' } )
	);

	registerBlockType( 'fluffless/mode-couleur', {
		edit: () => {
			const blockProps = useBlockProps( { className: 'fl-scheme-editor' } );
			const off = ! data.active && el(
				'p',
				{ className: 'fl-scheme-editor__note' },
				__( 'Hidden on the site: turn on Follow the device light or dark setting first.', 'fluffless-suite' ),
				data.url && ' ',
				data.url && el( 'a', { href: data.url, target: '_blank', rel: 'noopener' }, __( 'Open the Palette screen', 'fluffless-suite' ) )
			);
			return el(
				'div',
				blockProps,
				el(
					'span',
					{ className: 'fl-scheme-toggle', role: 'img', 'aria-label': __( 'Dark mode', 'fluffless-suite' ), style: { display: 'inline-grid', placeItems: 'center', width: 44, height: 44, border: '1px solid currentColor', borderRadius: '50%' } },
					moon
				),
				off
			);
		},
		save: () => null,
	} );
} )( window.wp );
