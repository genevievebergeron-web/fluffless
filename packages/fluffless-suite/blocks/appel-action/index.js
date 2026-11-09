/**
 * Éditeur du bloc fluffless/appel-action. JavaScript natif, sans étape de build.
 * L'image de fond est un attribut (identifiant de la médiathèque) : le serveur la rend avec les
 * tailles responsives du cœur, sous un voile qui garde le texte lisible. Rendu : Sections::render().
 */
( function ( wp, S ) {
	const el = S.el;
	const { __ } = wp.i18n;
	const { MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const { Button } = wp.components;
	const ACTION = () => __( 'Name the action with a verb', 'fluffless-suite' );
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'Invite the reader to take one clear step', 'fluffless-suite' ) } ],
		[ 'core/paragraph', { placeholder: __( 'Say what happens after the click: how long it takes, whether it is free, and that there is no obligation.', 'fluffless-suite' ) } ],
		[ 'core/buttons', {}, [ [ 'core/button', { placeholder: ACTION() } ] ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/appel-action', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const group = S.useChildren( clientId ).find( S.isBlock( 'core/buttons' ) );
			const count = S.useChildren( group && group.clientId ).length;
			const isImage = 'image' === attributes.background;
			const media = wp.data.useSelect(
				( s ) => ( attributes.imageId ? s( 'core' ).getMedia( attributes.imageId ) : null ),
				[ attributes.imageId ]
			);
			const setButtons = ( value ) => {
				const n = parseInt( value, 10 );
				if ( group ) {
					S.setCount( group.clientId, n, ( rank ) => S.button( rank, ACTION() ) );
				} else {
					S.setChild( clientId, S.isBlock( 'core/buttons' ), true, () => S.buttons( n, ACTION() ) );
				}
			};

			const picker =
				isImage &&
				el(
					MediaUploadCheck,
					null,
					el( MediaUpload, {
						allowedTypes: [ 'image' ],
						value: attributes.imageId,
						onSelect: ( chosen ) => setAttributes( { imageId: chosen.id } ),
						render: ( { open } ) =>
							el(
								Button,
								{ variant: 'secondary', onClick: open, __next40pxDefaultSize: true },
								attributes.imageId ? __( 'Replace image', 'fluffless-suite' ) : __( 'Choose an image', 'fluffless-suite' )
							),
					} )
				);
			const remove =
				isImage &&
				attributes.imageId > 0 &&
				el( Button, { variant: 'link', isDestructive: true, onClick: () => setAttributes( { imageId: 0 } ) }, __( 'Remove image', 'fluffless-suite' ) );

			return S.shell( 'appel-action', props, {
				keys: [ 'background', 'justify', 'size' ],
				template: TEMPLATE,
				title: __( 'Banner', 'fluffless-suite' ),
				before: isImage && media ? el( 'img', { className: 'fluffless-appel-action__image', src: media.source_url, alt: '' } ) : null,
				controls: [
					S.choice(
						__( 'Buttons', 'fluffless-suite' ),
						count > 0 ? String( Math.min( 2, count ) ) : '',
						[
							{ value: '1', label: '1' },
							{ value: '2', label: '2' },
						],
						setButtons,
						__( 'The second button is outlined, so one action stays the main one.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Background', 'fluffless-suite' ),
						attributes.background,
						[
							{ value: 'surface', label: __( 'Plain', 'fluffless-suite' ) },
							{ value: 'accent', label: __( 'Accent', 'fluffless-suite' ) },
							{ value: 'image', label: __( 'Image', 'fluffless-suite' ) },
						],
						( value ) => setAttributes( { background: value } ),
						isImage ? __( 'The image sits under a veil in your background colour, so the text stays readable.', 'fluffless-suite' ) : undefined
					),
					picker,
					remove,
					S.choice(
						__( 'Alignment', 'fluffless-suite' ),
						attributes.justify,
						[
							{ value: 'center', label: __( 'Centred', 'fluffless-suite' ) },
							{ value: 'start', label: __( 'Left', 'fluffless-suite' ) },
						],
						( value ) => setAttributes( { justify: value } )
					),
					S.choice(
						__( 'Spacing', 'fluffless-suite' ),
						attributes.size,
						[
							{ value: 'thin', label: __( 'Thin', 'fluffless-suite' ) },
							{ value: 'generous', label: __( 'Generous', 'fluffless-suite' ) },
						],
						( value ) => setAttributes( { size: value } )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
