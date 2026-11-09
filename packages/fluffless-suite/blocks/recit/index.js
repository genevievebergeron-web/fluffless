/**
 * Éditeur du bloc fluffless/recit. JavaScript natif, sans étape de build.
 * Deux groupes : la scène (premier) et les étapes (second, un bloc par étape). Le site reçoit
 * le rendu serveur (Expression::render_recit()) ; l'éditeur montre la mise en page sans animation.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, useInnerBlocksProps, InnerBlocks, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl } = wp.components;

	const TEMPLATE = [
		[
			'core/group',
			{ metadata: { name: __( 'Scene', 'fluffless-suite' ) } },
			[ [ 'core/image', {} ] ],
		],
		[
			'core/group',
			{ metadata: { name: __( 'Steps', 'fluffless-suite' ) } },
			[
				[ 'core/group', { metadata: { name: __( 'Step', 'fluffless-suite' ) } }, [ [ 'core/heading', { level: 3 } ], [ 'core/paragraph', {} ] ] ],
				[ 'core/group', { metadata: { name: __( 'Step', 'fluffless-suite' ) } }, [ [ 'core/heading', { level: 3 } ], [ 'core/paragraph', {} ] ] ],
				[ 'core/group', { metadata: { name: __( 'Step', 'fluffless-suite' ) } }, [ [ 'core/heading', { level: 3 } ], [ 'core/paragraph', {} ] ] ],
			],
		],
	];

	registerBlockType( 'fluffless/recit', {
		edit( { attributes, setAttributes } ) {
			const { scene, effect } = attributes;
			const blockProps = useBlockProps( {
				className: [ 'fluffless-recit', 'is-scene-' + scene, 'is-effect-' + effect ].join( ' ' ),
			} );
			const innerBlocksProps = useInnerBlocksProps( blockProps, {
				template: TEMPLATE,
				allowedBlocks: [ 'core/group' ],
				renderAppender: false,
			} );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Story', 'fluffless-suite' ) },
						el( SelectControl, {
							label: __( 'Scene side', 'fluffless-suite' ),
							help: __( 'On screens under 782 px, the scene comes first and does not stay in place.', 'fluffless-suite' ),
							value: scene,
							options: [
								{ value: 'start', label: __( 'Left', 'fluffless-suite' ) },
								{ value: 'end', label: __( 'Right', 'fluffless-suite' ) },
							],
							onChange: ( value ) => setAttributes( { scene: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( SelectControl, {
							label: __( 'Step entrance', 'fluffless-suite' ),
							help: __( 'Visitors who ask for reduced motion always see every step, without motion.', 'fluffless-suite' ),
							value: effect,
							options: [
								{ value: 'rise', label: __( 'Rise and fade in', 'fluffless-suite' ) },
								{ value: 'fade', label: __( 'Fade in', 'fluffless-suite' ) },
								{ value: 'none', label: __( 'None (sticky scene only)', 'fluffless-suite' ) },
							],
							onChange: ( value ) => setAttributes( { effect: value } ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el( 'div', innerBlocksProps )
			);
		},
		save: () => el( InnerBlocks.Content ),
	} );
} )( window.wp );
