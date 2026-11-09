/**
 * Éditeur du bloc fluffless/frise. JavaScript natif, sans étape de build.
 * Un titre puis un groupe natif : un bloc Jalon (fluffless/jalon) par étape (date ou libellé, titre
 * de niveau 3, un paragraphe). Ligne, points et numéros sont du CSS seulement. Rendu du site : Sections::render().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const NONE = [];
	const LIST_CLASS = 'fluffless-frise__list';
	const ITEM = [ 'fluffless/jalon' ];
	const step = () => S.createBlock( 'fluffless/jalon' );
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'How we got here', 'fluffless-suite' ) } ],
		[ 'core/group', { className: LIST_CLASS, layout: { type: 'default' } }, [ ITEM, ITEM, ITEM ] ],
	];

	wp.blocks.registerBlockType( 'fluffless/frise', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const state = wp.data.useSelect(
				( s ) => {
					const be = s( 'core/block-editor' );
					const items = be.getBlocks( clientId ).find( S.hasClass( LIST_CLASS ) );
					return { items, list: items ? be.getBlocks( items.clientId ) : NONE };
				},
				[ clientId ]
			);

			return S.shell( 'frise', props, {
				keys: [ 'direction', 'marker' ],
				template: TEMPLATE,
				title: __( 'Timeline', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of steps', 'fluffless-suite' ),
						String( state.list.length ),
						[ '2', '3', '4', '5', '6', '7', '8' ].map( ( n ) => ( { value: n, label: n } ) ),
						( raw ) => state.items && S.setCount( state.items.clientId, parseInt( raw, 10 ), step ),
						__( 'Removing a step also removes its content.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Direction', 'fluffless-suite' ),
						attributes.direction,
						[
							{ value: 'vertical', label: __( 'Vertical', 'fluffless-suite' ) },
							{ value: 'horizontal', label: __( 'Horizontal', 'fluffless-suite' ) },
						],
						( next ) => setAttributes( { direction: next } ),
						__( 'Horizontal on wide screens; phones always stack the steps.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Marker', 'fluffless-suite' ),
						attributes.marker,
						[
							{ value: 'dot', label: __( 'Dot', 'fluffless-suite' ) },
							{ value: 'number', label: __( 'Number', 'fluffless-suite' ) },
						],
						( next ) => setAttributes( { marker: next } )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
