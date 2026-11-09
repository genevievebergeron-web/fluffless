/**
 * Éditeur du bloc fluffless/qui-nous-sommes. JavaScript natif, sans étape de build.
 * Un groupe de texte (titre, mission facultative, texte) puis un visuel : une image native, ou un
 * groupe de chiffres clés repéré par sa classe. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const MISSION = 'fluffless-qui-nous-sommes__mission';
	const FIGURES = 'fluffless-qui-nous-sommes__chiffres';
	const NUMBER = 'fluffless-qui-nous-sommes__nombre';
	const isVisual = ( block ) => 'core/image' === block.name || S.hasClass( FIGURES )( block );

	const MISSION_TEXT = () => __( 'The mission in one sentence: who is served and what changes for them.', 'fluffless-suite' );
	const mission = () => S.createBlock( 'core/paragraph', { className: MISSION, placeholder: MISSION_TEXT() } );
	const figure = () =>
		S.createBlock( 'core/group', { metadata: { name: __( 'Key figure', 'fluffless-suite' ) } }, [
			S.createBlock( 'core/paragraph', { className: NUMBER, placeholder: __( '12 years', 'fluffless-suite' ) } ),
			S.createBlock( 'core/paragraph', { placeholder: __( 'What this number measures, and its source', 'fluffless-suite' ) } ),
		] );
	const makeVisual = ( kind ) =>
		'figures' === kind
			? S.createBlock( 'core/group', { className: FIGURES }, [ figure(), figure(), figure() ] )
			: S.createBlock( 'core/image', { sizeSlug: 'large' } );

	const TEMPLATE = [
		[
			'core/group',
			{ metadata: { name: __( 'Text', 'fluffless-suite' ) } },
			[
				[ 'core/heading', { placeholder: __( 'Why this organization exists, in one sentence', 'fluffless-suite' ) } ],
				[ 'core/paragraph', { className: MISSION, placeholder: MISSION_TEXT() } ],
				[ 'core/paragraph', { placeholder: __( 'The moment it started: a problem seen, people to help. A story beats a mission statement.', 'fluffless-suite' ) } ],
			],
		],
		[ 'core/image', { sizeSlug: 'large' } ],
	];

	wp.blocks.registerBlockType( 'fluffless/qui-nous-sommes', {
		edit( props ) {
			const { clientId } = props;
			const kids = S.useChildren( clientId );
			const text = kids.find( S.isBlock( 'core/group' ) );
			const textId = text && ! S.hasClass( FIGURES )( text ) ? text.clientId : undefined;
			const hasMission = S.useChildren( textId ).some( S.hasClass( MISSION ) );
			const visual = kids.find( isVisual );
			const kind = visual && 'core/image' !== visual.name ? 'figures' : 'photo';

			const setVisual = ( next ) => {
				S.setChild( clientId, isVisual, false );
				S.setChild( clientId, isVisual, true, () => makeVisual( next ) );
			};

			return S.shell( 'qui-nous-sommes', props, {
				template: TEMPLATE,
				title: __( 'About us', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Beside the text', 'fluffless-suite' ),
						kind,
						[
							{ value: 'photo', label: __( 'Photo', 'fluffless-suite' ) },
							{ value: 'figures', label: __( 'Key figures', 'fluffless-suite' ) },
						],
						setVisual,
						__( 'Switching replaces the photo or the figures and their content.', 'fluffless-suite' )
					),
					S.toggle( __( 'Mission set apart', 'fluffless-suite' ), hasMission, ( value ) =>
						S.setChild( textId, S.hasClass( MISSION ), value, mission, 1 )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
