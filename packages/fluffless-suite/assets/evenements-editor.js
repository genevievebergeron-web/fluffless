/**
 * Module Événements de Fluffless Suite : panneau « Événement » de l'éditeur (date, heure, lieu,
 * adresse, liens, statut) et source de liaison fluffless/evenement côté éditeur. JavaScript natif,
 * sans étape de build. Chargé dans l'éditeur seulement ; le site n'en reçoit rien.
 * Les métas sont écrites telles que le serveur les attend : début « AAAA-MM-JJ » ou « AAAA-MM-JJ HH:MM »,
 * fin sous la même forme ou une heure seule (le jour du début).
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { useState, useEffect, useRef } = wp.element;
	const { __, _x, sprintf } = wp.i18n;
	const { useSelect } = wp.data;
	const { useEntityProp } = wp.coreData;
	const { TextControl, SelectControl, ToggleControl, Notice, ExternalLink } = wp.components;
	const PluginDocumentSettingPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	const DATA = window.flufflessEvenements || { keys: {} };
	const KEYS = DATA.keys || {};
	const SOURCE = DATA.source || 'fluffless/evenement';

	const DATE = /^(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2}))?$/;
	const TIME = /^\d{2}:\d{2}$/;

	// Coupe une valeur stockée en { date, time } ; une heure seule n'a pas de date.
	const split = ( value ) => {
		if ( TIME.test( value || '' ) ) {
			return { date: '', time: value };
		}
		const m = DATE.exec( value || '' );
		return m ? { date: m[ 1 ], time: m[ 2 ] || '' } : { date: '', time: '' };
	};

	const statusLabel = ( status ) => {
		switch ( status ) {
			case 'cancelled':
				return _x( 'Cancelled', 'event status', 'fluffless-suite' );
			case 'postponed':
				return _x( 'Postponed', 'event status', 'fluffless-suite' );
			default:
				return '';
		}
	};

	// Texte d'aperçu d'une clé, à partir des métas en cours d'édition ; null sans contenu.
	const preview = ( key, meta ) => {
		const start = split( meta[ KEYS.start ] );
		switch ( key ) {
			case 'when':
				if ( ! start.date ) {
					return null;
				}
				return wp.date.dateI18n( DATA.dateFormat || 'Y-m-d', start.date + 'T' + ( start.time || '00:00' ) + ':00' ) + ( start.time ? ' ' + start.time : '' );
			case 'place':
				return meta[ KEYS.place ] || null;
			case 'address':
				return [ meta[ KEYS.street ], [ meta[ KEYS.city ], meta[ KEYS.region ] ].filter( Boolean ).join( ', ' ), meta[ KEYS.postal ] ].filter( Boolean ).join( ' ' ) || null;
			case 'signup':
				return meta[ KEYS.signup ] || null;
			case 'online':
				return meta[ KEYS.online ] || null;
			case 'status':
				return statusLabel( meta[ KEYS.status ] ) || null;
			default:
				return null;
		}
	};

	const placeholder = ( key ) => {
		/* translators: %s: name of an event detail, such as when or place */
		return sprintf( __( 'Event: %s', 'fluffless-suite' ), key );
	};

	if ( wp.blocks.registerBlockBindingsSource && ! ( wp.blocks.getBlockBindingsSource && wp.blocks.getBlockBindingsSource( SOURCE ) ) ) {
		wp.blocks.registerBlockBindingsSource( {
			name: SOURCE,
			label: __( 'Event (Fluffless)', 'fluffless-suite' ),
			usesContext: [ 'postId', 'postType' ],
			getValues( { select, context, bindings } ) {
				const values = {};
				const record = context && context.postId && context.postType === DATA.type ? select( 'core' ).getEditedEntityRecord( 'postType', context.postType, context.postId ) : null;
				const meta = ( record && record.meta ) || null;
				Object.keys( bindings ).forEach( ( attribute ) => {
					const key = bindings[ attribute ].args && bindings[ attribute ].args.key;
					if ( 'feed' === key || 'webcal' === key || 'google' === key ) {
						values[ attribute ] = DATA[ key ] || '';
						return;
					}
					const value = meta ? preview( key, meta ) : null;
					values[ attribute ] = null === value ? ( 'url' === attribute ? '' : placeholder( key || '' ) ) : value;
				} );
				return values;
			},
			canUserEditValue() {
				return false;
			},
		} );
	}

	// Identifiant du panneau dans les préférences de l'éditeur : nom de l'extension, puis nom du panneau.
	const PANEL = 'fluffless-evenement/fluffless-evenement';

	const Panel = () => {
		const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );
		const [ meta, setMeta ] = useEntityProp( 'postType', DATA.type, 'meta' );
		const values = meta || {};
		const start = split( values[ KEYS.start ] );
		const end = split( values[ KEYS.end ] );
		const [ allDay, setAllDay ] = useState( !! start.date && ! start.time );
		const opened = useSelect( ( select ) => select( 'core/editor' ).isEditorPanelOpened( PANEL ), [] );
		const asked = useRef( false );

		// Un événement sans date ouvre son panneau, une fois par chargement : la date est la première chose à saisir (P4-10).
		useEffect( () => {
			if ( asked.current || postType !== DATA.type || ! meta || start.date || opened ) {
				return;
			}
			asked.current = true;
			wp.data.dispatch( 'core/editor' ).toggleEditorPanelOpened( PANEL );
		} );

		if ( postType !== DATA.type || ! meta ) {
			return null;
		}

		const set = ( key, value ) => setMeta( { ...values, [ key ]: value } );
		const setMany = ( next ) => setMeta( { ...values, ...next } );

		// Recompose début et fin ; une journée entière n'a pas d'heure.
		const write = ( s, e, wholeDay ) => {
			const startValue = s.date ? s.date + ( ! wholeDay && s.time ? ' ' + s.time : '' ) : '';
			let endValue = '';
			if ( wholeDay ) {
				endValue = e.date;
			} else if ( e.date && e.time ) {
				endValue = e.date + ' ' + e.time;
			} else {
				endValue = e.time || e.date;
			}
			setMany( { [ KEYS.start ]: startValue, [ KEYS.end ]: endValue } );
		};

		const field = ( props ) => el( TextControl, { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true, ...props } );

		return el(
			PluginDocumentSettingPanel,
			{ name: 'fluffless-evenement', title: __( 'Event', 'fluffless-suite' ), className: 'fluffless-evenement-panel' },
			DATA.tzWarning &&
				el(
					Notice,
					{ status: 'warning', isDismissible: false },
					__( 'The site time zone is a fixed offset or UTC: times will be one hour off during daylight saving time.', 'fluffless-suite' ),
					' ',
					el( 'a', { href: DATA.tzUrl }, __( 'Pick a city', 'fluffless-suite' ) )
				),
			el( ToggleControl, {
				__nextHasNoMarginBottom: true,
				label: __( 'All day', 'fluffless-suite' ),
				checked: allDay,
				onChange: ( on ) => {
					setAllDay( on );
					write( start, end, on );
				},
			} ),
			field( { type: 'date', label: __( 'Start date', 'fluffless-suite' ), value: start.date, onChange: ( v ) => write( { ...start, date: v }, end, allDay ) } ),
			! allDay && field( { type: 'time', label: __( 'Start time', 'fluffless-suite' ), value: start.time, onChange: ( v ) => write( { ...start, time: v }, end, allDay ) } ),
			field( {
				type: 'date',
				label: __( 'End date', 'fluffless-suite' ),
				help: allDay ? __( 'Leave empty for a one-day event.', 'fluffless-suite' ) : __( 'Leave empty when it ends the same day.', 'fluffless-suite' ),
				value: end.date,
				onChange: ( v ) => write( start, { ...end, date: v }, allDay ),
			} ),
			! allDay &&
				field( {
					type: 'time',
					label: __( 'End time', 'fluffless-suite' ),
					help: __( 'An end time earlier than the start means the next day.', 'fluffless-suite' ),
					value: end.time,
					onChange: ( v ) => write( start, { ...end, time: v }, allDay ),
				} ),
			el( SelectControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __( 'Status', 'fluffless-suite' ),
				value: values[ KEYS.status ] || 'scheduled',
				options: [
					{ value: 'scheduled', label: _x( 'Scheduled', 'event status', 'fluffless-suite' ) },
					{ value: 'postponed', label: __( 'Postponed, new date to come', 'fluffless-suite' ) },
					{ value: 'cancelled', label: _x( 'Cancelled', 'event status', 'fluffless-suite' ) },
				],
				help: __( 'To move an event, change its date: the old date stays known to search engines.', 'fluffless-suite' ),
				onChange: ( v ) => set( KEYS.status, v ),
			} ),
			field( { label: __( 'Place', 'fluffless-suite' ), help: __( 'Name of the venue, for example a hall or a café.', 'fluffless-suite' ), value: values[ KEYS.place ] || '', onChange: ( v ) => set( KEYS.place, v ) } ),
			field( { label: __( 'Street address', 'fluffless-suite' ), autoComplete: 'off', value: values[ KEYS.street ] || '', onChange: ( v ) => set( KEYS.street, v ) } ),
			field( { label: __( 'City', 'fluffless-suite' ), autoComplete: 'off', value: values[ KEYS.city ] || '', onChange: ( v ) => set( KEYS.city, v ) } ),
			field( { label: __( 'Province or state', 'fluffless-suite' ), autoComplete: 'off', value: values[ KEYS.region ] || '', onChange: ( v ) => set( KEYS.region, v ) } ),
			field( { label: __( 'Postal code', 'fluffless-suite' ), autoComplete: 'off', value: values[ KEYS.postal ] || '', onChange: ( v ) => set( KEYS.postal, v ) } ),
			field( {
				label: __( 'Country code', 'fluffless-suite' ),
				help: __( 'Two letters, for example CA.', 'fluffless-suite' ),
				maxLength: 2,
				autoComplete: 'off',
				value: values[ KEYS.country ] || 'CA',
				onChange: ( v ) => set( KEYS.country, v.toUpperCase() ),
			} ),
			field( { type: 'url', label: __( 'Online link', 'fluffless-suite' ), help: __( 'Video call or live stream, if any.', 'fluffless-suite' ), value: values[ KEYS.online ] || '', onChange: ( v ) => set( KEYS.online, v ) } ),
			field( { type: 'url', label: __( 'Registration link', 'fluffless-suite' ), value: values[ KEYS.signup ] || '', onChange: ( v ) => set( KEYS.signup, v ) } ),
			DATA.feed && el( 'p', null, el( ExternalLink, { href: DATA.feed }, __( 'Calendar feed of all events', 'fluffless-suite' ) ) )
		);
	};

	if ( PluginDocumentSettingPanel && wp.plugins && wp.plugins.registerPlugin ) {
		wp.plugins.registerPlugin( 'fluffless-evenement', { render: Panel } );
	}
} )( window.wp );
