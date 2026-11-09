/**
 * Éditeur du bloc fluffless/compte-a-rebours. JavaScript natif, sans étape de build.
 * Un titre et un paragraphe ; la date se règle dans le panneau et le serveur calcule les jours
 * qui restent à chaque affichage. Rendu du site : Sections::render() et Sections::countdown().
 */
( function ( wp, S ) {
	const { __, sprintf } = wp.i18n;
	const { DateTimePicker, TextControl } = wp.components;
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'Doors open soon', 'fluffless-suite' ) } ],
		[ 'core/paragraph', { placeholder: __( 'What happens on that date, and what to do before.', 'fluffless-suite' ) } ],
	];
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	const local = ( iso ) => {
		if ( ! iso ) {
			return '';
		}
		const d = new Date( iso );
		return isNaN( d ) ? '' : d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() ) + 'T' + pad( d.getHours() ) + ':' + pad( d.getMinutes() );
	};
	const preview = ( date ) => {
		if ( ! date ) {
			return __( 'Pick a date: the site shows how many days are left.', 'fluffless-suite' );
		}
		const left = Math.ceil( ( new Date( date ) - Date.now() ) / 86400000 );
		return left > 0 ? sprintf( __( '%d days left', 'fluffless-suite' ), left ) : __( 'This date has passed.', 'fluffless-suite' );
	};

	wp.blocks.registerBlockType( 'fluffless/compte-a-rebours', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			return S.shell( 'compte-a-rebours', props, {
				keys: [ 'size' ],
				template: TEMPLATE,
				title: __( 'Countdown', 'fluffless-suite' ),
				after: S.el(
					'p',
					{ className: 'fluffless-compte-a-rebours__figure' },
					S.el( 'span', { className: 'fluffless-compte-a-rebours__number' }, preview( attributes.date ) )
				),
				controls: [
					S.el( DateTimePicker, {
						currentDate: attributes.date || null,
						onChange: ( next ) => setAttributes( { date: local( next ) } ),
						is12Hour: false,
					} ),
					S.choice(
						__( 'Size', 'fluffless-suite' ),
						attributes.size,
						[
							{ value: 'large', label: __( 'Large', 'fluffless-suite' ) },
							{ value: 'compact', label: __( 'Compact', 'fluffless-suite' ) },
						],
						( next ) => setAttributes( { size: next } )
					),
					S.el( TextControl, {
						label: __( 'Once the date has passed', 'fluffless-suite' ),
						value: attributes.after,
						placeholder: __( 'This date has passed.', 'fluffless-suite' ),
						onChange: ( after ) => setAttributes( { after } ),
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
					} ),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
