/**
 * Éditeur du bloc fluffless/texte-photo. JavaScript natif, sans étape de build.
 * Un groupe de texte puis une image, toujours dans cet ordre : la photo passe à gauche en CSS,
 * l'ordre de lecture ne change pas. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const ACTION = () => __( 'Name the next step', 'fluffless-suite' );
	const TEMPLATE = [
		[
			'core/group',
			{ metadata: { name: __( 'Text', 'fluffless-suite' ) } },
			[
				[ 'core/heading', { placeholder: __( 'Say in one sentence what you offer', 'fluffless-suite' ) } ],
				[ 'core/paragraph', { placeholder: __( 'Who it is for and what changes for them, in two or three sentences.', 'fluffless-suite' ) } ],
				[ 'core/buttons', {}, [ [ 'core/button', { placeholder: ACTION() } ] ] ],
			],
		],
		[ 'core/image', { sizeSlug: 'large' } ],
	];

	wp.blocks.registerBlockType( 'fluffless/texte-photo', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const text = S.useChildren( clientId ).find( S.isBlock( 'core/group' ) );
			const textId = text && text.clientId;
			const hasButton = S.useChildren( textId ).some( S.isBlock( 'core/buttons' ) );

			return S.shell( 'texte-photo', props, {
				keys: [ 'media', 'ratio', 'background' ],
				template: TEMPLATE,
				title: __( 'Layout', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Photo side', 'fluffless-suite' ),
						attributes.media,
						[
							{ value: 'start', label: __( 'Left', 'fluffless-suite' ) },
							{ value: 'end', label: __( 'Right', 'fluffless-suite' ) },
						],
						( value ) => setAttributes( { media: value } ),
						__( 'On phones, the photo always comes after the text.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Proportion', 'fluffless-suite' ),
						attributes.ratio,
						[
							{ value: '50-50', label: '50 / 50' },
							{ value: '60-40', label: __( 'Text wider', 'fluffless-suite' ) },
						],
						( value ) => setAttributes( { ratio: value } )
					),
					S.toggle( __( 'Button', 'fluffless-suite' ), hasButton, ( value ) =>
						S.setChild( textId, S.isBlock( 'core/buttons' ), value, () => S.buttons( 1, ACTION() ) )
					),
					S.choice(
						__( 'Background', 'fluffless-suite' ),
						attributes.background,
						[
							{ value: 'surface', label: __( 'Plain', 'fluffless-suite' ) },
							{ value: 'accent', label: __( 'Accent', 'fluffless-suite' ) },
						],
						( value ) => setAttributes( { background: value } )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
