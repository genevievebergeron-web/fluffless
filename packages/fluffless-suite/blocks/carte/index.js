/**
 * Éditeur du bloc fluffless/carte. JavaScript natif, sans étape de build.
 * Une image fixe (plan ou photo de l'entrée), un titre, l'adresse et les heures, un bouton qui
 * ouvre l'application de cartes du visiteur. Aucune carte incorporée. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const TEMPLATE = [
		[ 'core/image', { className: 'fluffless-carte__image', sizeSlug: 'large' } ],
		[
			'core/group',
			{ className: 'fluffless-carte__text', layout: { type: 'default' } },
			[
				[ 'core/heading', { level: 3, placeholder: __( 'Where to find us', 'fluffless-suite' ) } ],
				[ 'core/paragraph', { placeholder: __( 'Street address, city, postal code. Then the hours.', 'fluffless-suite' ) } ],
				[ 'core/buttons', {}, [ [ 'core/button', { placeholder: __( 'Open in your maps app', 'fluffless-suite' ) } ] ] ],
			],
		],
	];

	wp.blocks.registerBlockType( 'fluffless/carte', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			return S.shell( 'carte', props, {
				keys: [ 'layout' ],
				template: TEMPLATE,
				title: __( 'Map', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Layout', 'fluffless-suite' ),
						attributes.layout,
						[
							{ value: 'side', label: __( 'Side by side', 'fluffless-suite' ) },
							{ value: 'stacked', label: __( 'Stacked', 'fluffless-suite' ) },
						],
						( next ) => setAttributes( { layout: next } ),
						__( 'Link the button to a maps address (geo: or a map website); nothing is embedded.', 'fluffless-suite' )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
