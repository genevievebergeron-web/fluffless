/**
 * Éditeur du bloc fluffless/barre (enfant de fluffless/barres). JavaScript natif, sans étape de
 * build. Un libellé en texte riche et une valeur de 0 à 100 réglée par un curseur ; la piste est
 * dessinée comme sur le site. Rendu du site : Sections::render_barre().
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const { RichText, useBlockProps } = wp.blockEditor;
	const { RangeControl } = wp.components;

	wp.blocks.registerBlockType( 'fluffless/barre', {
		edit( { attributes, setAttributes } ) {
			const value = Math.max( 0, Math.min( 100, parseInt( attributes.value, 10 ) || 0 ) );
			return S.el(
				'div',
				useBlockProps( { className: 'fluffless-barre' } ),
				S.el( RichText, {
					tagName: 'span',
					className: 'fluffless-barre__label',
					value: attributes.label,
					allowedFormats: [ 'core/bold', 'core/italic' ],
					placeholder: __( 'What this bar measures', 'fluffless-suite' ),
					onChange: ( label ) => setAttributes( { label } ),
				} ),
				S.el( 'span', { className: 'fluffless-barre__value' }, value + ' %' ),
				S.el(
					'span',
					{ className: 'fluffless-barre__track', 'aria-hidden': true },
					S.el( 'span', { className: 'fluffless-barre__fill', style: { '--fl-v': value + '%' } } )
				),
				S.el( RangeControl, {
					label: __( 'Value', 'fluffless-suite' ),
					value,
					min: 0,
					max: 100,
					onChange: ( next ) => setAttributes( { value: next } ),
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} )
			);
		},
		save: () => null,
	} );
} )( window.wp, window.flufflessSections );
