/**
 * Éditeur du bloc fluffless/empreinte. JavaScript natif, sans étape de build.
 * Aperçu tiré de la métadonnée _fluffless_footprint (écrite par le panneau Empreinte de cette page) ;
 * le site, lui, reçoit le rendu serveur statique, sans script.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __, sprintf } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, TextControl, Notice } = wp.components;
	const { useSelect } = wp.data;

	const META_KEY = '_fluffless_footprint';
	const STALE_MS = 182 * 24 * 3600 * 1000; // 6 mois, comme Footprint::STALE_SECONDS.

	/** Deux chiffres significatifs, comme Footprint::format_grams(). */
	function grams( value, locale ) {
		if ( ! value || value <= 0 ) {
			return '0';
		}
		const decimals = Math.max( 0, Math.min( 4, 1 - Math.floor( Math.log10( value ) ) ) );
		return new Intl.NumberFormat( locale, { minimumFractionDigits: decimals, maximumFractionDigits: decimals } ).format( value );
	}

	registerBlockType( 'fluffless/empreinte', {
		edit( { attributes, setAttributes, context } ) {
			const result = useSelect(
				( select ) => {
					const editor = select( 'core/editor' );
					const meta = editor && editor.getEditedPostAttribute( 'meta' );
					if ( meta && meta[ META_KEY ] ) {
						return meta[ META_KEY ];
					}
					// Hors de l'éditeur de contenu (modèle, site) : lecture de l'enregistrement du contexte.
					if ( context.postId && context.postType ) {
						const record = select( 'core' ).getEntityRecord( 'postType', context.postType, context.postId );
						return record && record.meta ? record.meta[ META_KEY ] : null;
					}
					return null;
				},
				[ context.postId, context.postType ]
			);
			const locale = document.documentElement.lang || undefined;
			const measured = result && result.measured ? result.measured * 1000 : 0;
			const stale = measured > 0 && Date.now() - measured > STALE_MS;

			const body = measured
				? el(
						'p',
						null,
						sprintf(
							/* translators: 1: estimate for a new visit in grams, 2: estimate for a returning visit in grams, 3: rating from A+ to F, 4: date of measurement */
							__( 'SWDM v4 estimate: %1$s g CO₂e (new visit), %2$s g (returning visit) · rating %3$s · measured on %4$s', 'fluffless-suite' ),
							grams( result.new, locale ),
							grams( result.return, locale ),
							result.grade,
							new Intl.DateTimeFormat( locale, { dateStyle: 'long' } ).format( new Date( measured ) )
						),
						' · ',
						el( 'a', { href: attributes.methodUrl || undefined }, __( 'Method', 'fluffless-suite' ) )
				  )
				: el(
						'p',
						{ className: 'fluffless-empreinte-placeholder' },
						__( 'No measurement yet: this block shows nothing on the site until the page is measured in the Page footprint panel.', 'fluffless-suite' )
				  );

			return el(
				'div',
				useBlockProps(),
				stale &&
					el(
						Notice,
						{ status: 'warning', isDismissible: false },
						__( 'This measurement is more than 6 months old. Measure again from the Page footprint panel before you rely on it.', 'fluffless-suite' )
					),
				body,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Method', 'fluffless-suite' ) },
						el( TextControl, {
							label: __( 'Method page address', 'fluffless-suite' ),
							type: 'url',
							value: attributes.methodUrl,
							help: __( 'The page that explains the model, hypotheses and date. Empty: the Sustainable Web Design model page.', 'fluffless-suite' ),
							onChange: ( value ) => setAttributes( { methodUrl: value } ),
							__nextHasNoMarginBottom: true,
						} )
					)
				)
			);
		},
		save: () => null,
	} );
} )( window.wp );
