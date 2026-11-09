/**
 * Éditeur du bloc fluffless/coordonnees. JavaScript natif, sans étape de build.
 * Un titre ; l'aperçu est le rendu du serveur passé par Sections::editor_assets()
 * (window.flufflessCoordonnees). Rendu du site : Sections::render() et Sections::contact_details().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const el = S.el;
	const data = window.flufflessCoordonnees || { seo: false, html: '' };
	const TEMPLATE = [ [ 'core/heading', { level: 2, placeholder: __( 'Find us', 'fluffless-suite' ) } ] ];

	wp.blocks.registerBlockType( 'fluffless/coordonnees', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			let preview;
			if ( ! data.seo ) {
				preview = el( 'p', { className: 'components-base-control__help' }, __( 'Activate Fluffless SEO: this block reads its Local business settings.', 'fluffless-suite' ) );
			} else if ( ! data.html ) {
				preview = el( 'p', { className: 'components-base-control__help' }, __( 'Fill in the address, phone and hours in Fluffless SEO, Local business.', 'fluffless-suite' ) );
			} else {
				preview = el( wp.element.RawHTML, null, data.html );
			}
			return S.shell( 'coordonnees', props, {
				keys: [ 'hours' ],
				template: TEMPLATE,
				title: __( 'Contact details', 'fluffless-suite' ),
				after: preview,
				controls: [
					S.toggle( __( 'Show the opening hours', 'fluffless-suite' ), 'shown' === attributes.hours, ( on ) => setAttributes( { hours: on ? 'shown' : 'hidden' } ) ),
					el( 'p', { className: 'components-base-control__help' }, __( 'Address, phone and hours come from Fluffless SEO, Local business: change them there.', 'fluffless-suite' ) ),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
