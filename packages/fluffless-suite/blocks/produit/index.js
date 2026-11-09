/**
 * Éditeur du bloc fluffless/produit (Vente simple). JavaScript natif, sans étape de build.
 * Photo, nom, description et bouton restent des blocs natifs ; le prix est le bloc enfant
 * fluffless/prix, rendu par le serveur et repris en données Product par le module SEO.
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const TEMPLATE = [
		[ 'core/image', { sizeSlug: 'large' } ],
		[ 'core/heading', { level: 3, placeholder: __( 'Product name', 'fluffless-suite' ) } ],
		[ 'fluffless/prix', {} ],
		[ 'core/paragraph', { placeholder: __( 'What it is, who it is for, what is included.', 'fluffless-suite' ) } ],
		[ 'core/buttons', {}, [ [ 'core/button', { placeholder: __( 'Buy now', 'fluffless-suite' ) } ] ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/produit', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			return S.shell( 'produit', props, {
				keys: [ 'photo' ],
				template: TEMPLATE,
				title: __( 'Product', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Photo', 'fluffless-suite' ),
						attributes.photo,
						[
							{ value: 'side', label: __( 'Beside', 'fluffless-suite' ) },
							{ value: 'top', label: __( 'Above', 'fluffless-suite' ) },
						],
						( photo ) => setAttributes( { photo } )
					),
					S.el(
						'p',
						{ className: 'components-base-control__help' },
						__( 'Point the button to a hosted payment link (Helcim, Square, Stripe, PayPal): no cart, no customer data on this site. Taxes and receipts stay with the payment provider.', 'fluffless-suite' )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
