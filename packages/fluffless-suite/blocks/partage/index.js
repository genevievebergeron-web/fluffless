/**
 * Éditeur du bloc fluffless/partage. JavaScript natif, sans étape de build.
 * Un paragraphe d'invitation ; les liens de partage sont écrits par le serveur pour la page
 * affichée. L'éditeur en montre l'aperçu. Rendu du site : Sections::render() et Sections::share_links().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const NETWORKS = [
		[ 'linkedin', 'LinkedIn' ],
		[ 'facebook', 'Facebook' ],
		[ 'bluesky', 'Bluesky' ],
		[ 'threads', 'Threads' ],
		[ 'email', __( 'Email', 'fluffless-suite' ) ],
	];
	const TEMPLATE = [ [ 'core/paragraph', { placeholder: __( 'Found this useful? Pass it on.', 'fluffless-suite' ) } ] ];

	wp.blocks.registerBlockType( 'fluffless/partage', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			return S.shell( 'partage', props, {
				keys: [ 'display' ],
				template: TEMPLATE,
				title: __( 'Share buttons', 'fluffless-suite' ),
				after: S.el(
					'ul',
					{ className: 'fluffless-partage__list' },
					NETWORKS.filter( ( n ) => false !== attributes[ n[ 0 ] ] ).map( ( n ) =>
						S.el( 'li', { key: n[ 0 ] }, S.el( 'span', { className: 'fluffless-partage__link' }, n[ 1 ] ) )
					)
				),
				controls: [
					S.choice(
						__( 'Display', 'fluffless-suite' ),
						attributes.display,
						[
							{ value: 'icons', label: __( 'Icons', 'fluffless-suite' ) },
							{ value: 'labels', label: __( 'Icons and labels', 'fluffless-suite' ) },
						],
						( next ) => setAttributes( { display: next } )
					),
				].concat(
					NETWORKS.map( ( n ) =>
						S.toggle( n[ 1 ], false !== attributes[ n[ 0 ] ], ( on ) => setAttributes( { [ n[ 0 ] ]: on } ) )
					)
				),
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
