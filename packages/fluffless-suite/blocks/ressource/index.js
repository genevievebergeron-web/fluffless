/**
 * Éditeur du bloc fluffless/ressource. JavaScript natif, sans étape de build.
 * Le fichier est un attribut (identifiant de la médiathèque) ; format et taille sont lus côté
 * serveur au rendu (Sections::download_link()), jamais recopiés dans le contenu.
 * « Demander un courriel d'abord » : sur le site, un formulaire du module Formulaire (courriel, et
 * consentement si l'auteur en écrit un) remplace le lien jusqu'à l'envoi. Le bloc ne collecte rien
 * lui-même ; c'est une barrière douce, le fichier garde son adresse publique.
 */
( function ( wp, S ) {
	const el = S.el;
	const { __, sprintf } = wp.i18n;
	const { MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const { Button, TextControl, ToggleControl } = wp.components;
	const decode = ( text ) => ( wp.htmlEntities ? wp.htmlEntities.decodeEntities( text ) : text );
	const TEMPLATE = [
		[ 'core/heading', { level: 3, placeholder: __( 'Name the resource and the problem it solves', 'fluffless-suite' ) } ],
		[ 'core/paragraph', { placeholder: __( 'Say what is inside, how long it takes to read, and who it is for.', 'fluffless-suite' ) } ],
	];

	wp.blocks.registerBlockType( 'fluffless/ressource', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			const file = wp.data.useSelect(
				( s ) => ( attributes.fileId ? s( 'core' ).getMedia( attributes.fileId ) : null ),
				[ attributes.fileId ]
			);
			const name = file ? decode( ( file.title && file.title.rendered ) || file.source_url || '' ) : '';

			const picker = el(
				MediaUploadCheck,
				null,
				el( MediaUpload, {
					value: attributes.fileId,
					onSelect: ( chosen ) => setAttributes( { fileId: chosen.id } ),
					render: ( { open } ) =>
						el(
							Button,
							{ variant: 'secondary', onClick: open, __next40pxDefaultSize: true },
							attributes.fileId ? __( 'Replace file', 'fluffless-suite' ) : __( 'Choose a file', 'fluffless-suite' )
						),
				} )
			);

			return S.shell( 'ressource', props, {
				keys: [ 'preview' ],
				template: TEMPLATE,
				title: __( 'File', 'fluffless-suite' ),
				after: el(
					'p',
					{ className: 'fluffless-ressource__link' },
					el(
						'span',
						{ className: 'wp-element-button' },
						/* translators: %s: file title. */
						name ? sprintf( __( 'Download %s', 'fluffless-suite' ), name ) : __( 'Choose a file in the sidebar', 'fluffless-suite' )
					)
				),
				controls: [
					picker,
					el(
						'p',
						{ className: 'components-base-control__help' },
						__( 'On the site, the link also shows the file format and size, for example “PDF, 240 KB”. They are read from the file itself.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Preview', 'fluffless-suite' ),
						attributes.preview,
						[
							{ value: 'image', label: __( 'Thumbnail', 'fluffless-suite' ) },
							{ value: 'icon', label: __( 'Icon', 'fluffless-suite' ) },
						],
						( value ) => setAttributes( { preview: value } ),
						__( 'A thumbnail needs an image, or a PDF that WordPress made a preview of. Otherwise the icon shows.', 'fluffless-suite' )
					),
					el( ToggleControl, {
						label: __( 'Ask for an email first', 'fluffless-suite' ),
						help: __( 'On the site, a short form asks for an email before the link shows. It is a soft gate: the file keeps its public address in your media library. It needs the Form module; without it, the file downloads directly.', 'fluffless-suite' ),
						checked: !! attributes.emailFirst,
						onChange: ( value ) => setAttributes( { emailFirst: value } ),
						__nextHasNoMarginBottom: true,
					} ),
					attributes.emailFirst
						? el( TextControl, {
								label: __( 'Send emails to', 'fluffless-suite' ),
								help: __( 'One or more addresses, separated by commas. Empty: the site administrator.', 'fluffless-suite' ),
								value: attributes.recipients || '',
								onChange: ( value ) => setAttributes( { recipients: value } ),
								__next40pxDefaultSize: true,
								__nextHasNoMarginBottom: true,
						  } )
						: null,
					attributes.emailFirst
						? el( TextControl, {
								label: __( 'Consent sentence', 'fluffless-suite' ),
								help: __( 'Optional. When filled, the visitor must tick it before the file shows, for example to agree to receive news.', 'fluffless-suite' ),
								value: attributes.consent || '',
								onChange: ( value ) => setAttributes( { consent: value } ),
								__next40pxDefaultSize: true,
								__nextHasNoMarginBottom: true,
						  } )
						: null,
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
