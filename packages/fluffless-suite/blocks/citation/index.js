/**
 * Éditeur du bloc fluffless/citation. JavaScript natif, sans étape de build.
 * Une citation native, puis un paragraphe d'auteur repéré par sa classe, hors du blockquote :
 * l'attribution n'appartient pas aux mots cités. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const AUTHOR = 'fluffless-citation__author';
	const WHO = () => __( 'First and last name, role', 'fluffless-suite' );
	const author = () => S.createBlock( 'core/paragraph', { className: AUTHOR, placeholder: WHO() } );
	const TEMPLATE = [
		[
			'core/quote',
			{},
			[ [ 'core/paragraph', { placeholder: __( 'The sentence a reader would underline: short enough to remember, specific enough to be true.', 'fluffless-suite' ) } ] ],
		],
		[ 'core/paragraph', { className: AUTHOR, placeholder: WHO() } ],
	];

	wp.blocks.registerBlockType( 'fluffless/citation', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const hasAuthor = S.useChildren( clientId ).some( S.hasClass( AUTHOR ) );

			return S.shell( 'citation', props, {
				keys: [ 'size', 'mark' ],
				template: TEMPLATE,
				title: __( 'Quote', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Size', 'fluffless-suite' ),
						attributes.size,
						[
							{ value: 'large', label: __( 'Large', 'fluffless-suite' ) },
							{ value: 'compact', label: __( 'Compact', 'fluffless-suite' ) },
						],
						( value ) => setAttributes( { size: value } )
					),
					S.choice(
						__( 'Mark', 'fluffless-suite' ),
						attributes.mark,
						[
							{ value: 'rule', label: __( 'Fine rule', 'fluffless-suite' ) },
							{ value: 'quotes', label: __( 'Quotation marks', 'fluffless-suite' ) },
						],
						( value ) => setAttributes( { mark: value } )
					),
					S.toggle(
						__( 'Author', 'fluffless-suite' ),
						hasAuthor,
						( value ) => S.setChild( clientId, S.hasClass( AUTHOR ), value, author ),
						__( 'Ask permission before publishing a name.', 'fluffless-suite' )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
