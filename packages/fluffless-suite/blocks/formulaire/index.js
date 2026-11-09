/**
 * Éditeur du bloc fluffless/formulaire. JavaScript natif, sans étape de build.
 * Le site reçoit le rendu serveur (Form::render_form()) : <form method="post">, 0 JS.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { useEffect } = wp.element;
	const { useSelect, useDispatch } = wp.data;
	const { __ } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, useInnerBlocksProps, InnerBlocks, InspectorControls } = wp.blockEditor;
	const { Notice, PanelBody, SelectControl, TextControl, TextareaControl, ToggleControl } = wp.components;

	const ALLOWED = [ 'fluffless/champ', 'fluffless/etape', 'core/paragraph', 'core/heading', 'core/group', 'core/columns' ];
	const TEMPLATE = [
		[ 'fluffless/champ', { type: 'text', label: __( 'Name', 'fluffless-suite' ), required: true } ],
		[ 'fluffless/champ', { type: 'email', label: __( 'Email', 'fluffless-suite' ), required: true } ],
		[ 'fluffless/champ', { type: 'textarea', label: __( 'Message', 'fluffless-suite' ), required: true } ],
	];

	const CHOICE = [ 'select', 'radio', 'checkboxes' ];

	/** Nom technique tiré d'un libellé, comme sanitize_title() côté serveur pour un texte ASCII. */
	function slug( text ) {
		return ( text || '' )
			.replace( /<[^>]*>/g, '' )
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9_-]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.slice( 0, 40 );
	}

	/**
	 * Champs du formulaire avec le nom que le serveur leur donnera (Form::field()) : nom ou libellé
	 * passé en slug, puis -2, -3 pour un nom déjà pris, dans l'ordre du document. Commun aux champs
	 * à choix et aux cases de consentement (X5-113).
	 */
	function useFieldNames( clientId ) {
		return useSelect(
			( select ) => {
				const be = select( 'core/block-editor' );
				const taken = [];
				return be
					.getClientIdsOfDescendants( clientId )
					.map( ( id ) => be.getBlock( id ) )
					.filter( ( b ) => b && b.name === 'fluffless/champ' )
					.map( ( b ) => {
						const base = slug( b.attributes.name ) || slug( b.attributes.label ) || slug( __( 'Field', 'fluffless-suite' ) ) || 'field';
						let name = base;
						for ( let n = 2; taken.includes( name ); n++ ) {
							name = base + '-' + n;
						}
						taken.push( name );
						return { b, name };
					} );
			},
			[ clientId ]
		);
	}

	/** Champs à choix du formulaire qui peuvent décider des destinataires. */
	function choiceFields( named ) {
		return named
			.filter( ( { b } ) => CHOICE.includes( b.attributes.type ) )
			.map( ( { b, name } ) => ( {
				clientId: b.clientId,
				hasName: !! b.attributes.name,
				name,
				label: ( b.attributes.label || '' ).replace( /<[^>]*>/g, '' ) || b.attributes.name || __( 'Field', 'fluffless-suite' ),
				options: ( b.attributes.options || '' ).split( '\n' ).map( ( o ) => o.trim() ).filter( Boolean ),
			} ) );
	}

	/** Cases de consentement du formulaire, pour désigner celle de l'infolettre. */
	function consentFields( named ) {
		return named
			.filter( ( { b } ) => b.attributes.type === 'consent' )
			.map( ( { b, name } ) => ( {
				clientId: b.clientId,
				name,
				label: ( b.attributes.label || '' ).replace( /<[^>]*>/g, '' ) || b.attributes.name || __( 'Consent box', 'fluffless-suite' ),
				hasName: !! b.attributes.name,
			} ) );
	}

	registerBlockType( 'fluffless/formulaire', {
		edit( { attributes, setAttributes, clientId } ) {
			const { formId, recipients, routeField, routes, subject, confirm, keep, success, submitLabel, newsletter, newsletterList, newsletterConsent } = attributes;
			const named = useFieldNames( clientId );
			const choices = choiceFields( named );
			const routing = choices.find( ( f ) => f.name === routeField );
			const consents = consentFields( named );
			const chosen = consents.find( ( c ) => c.name === newsletterConsent );
			const { updateBlockAttributes } = useDispatch( 'core/block-editor' );
			// Adresses gardées pour des options renommées ou retirées : ignorées à l'envoi.
			const orphans = routing ? Object.keys( routes || {} ).filter( ( o ) => routes[ o ] && ! routing.options.includes( o ) ) : [];
			useEffect( () => {
				if ( ! formId ) {
					setAttributes( { formId: 'f' + Math.random().toString( 36 ).slice( 2, 10 ) } );
				}
			}, [ formId ] );
			const blockProps = useBlockProps( { className: 'fluffless-formulaire' } );
			const innerBlocksProps = useInnerBlocksProps( {}, { allowedBlocks: ALLOWED, template: TEMPLATE } );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Sending', 'fluffless-suite' ) },
						el( TextControl, {
							label: __( 'Recipients', 'fluffless-suite' ),
							help: __( 'Comma-separated. Empty: the site administration address.', 'fluffless-suite' ),
							value: recipients,
							onChange: ( value ) => setAttributes( { recipients: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( SelectControl, {
							label: __( 'Recipients by answer', 'fluffless-suite' ),
							help: choices.length
								? __( 'A list, choice or checkbox field. Each option can send to its own addresses; an option left empty uses the recipients above.', 'fluffless-suite' )
								: __( 'Add a list, choice or checkbox field to send each answer to different addresses.', 'fluffless-suite' ),
							value: routeField,
							options: [ { value: '', label: __( 'Same recipients for every answer', 'fluffless-suite' ) } ].concat(
								choices.map( ( f ) => ( { value: f.name, label: f.label } ) ),
								routeField && ! routing ? [ { value: routeField, label: __( 'Unknown field', 'fluffless-suite' ) + ' : ' + routeField } ] : []
							),
							onChange: ( value ) => {
								const chosen = choices.find( ( f ) => f.name === value );
								// Nom figé sur le champ : le serveur le retrouve même si le libellé change.
								if ( chosen && ! chosen.hasName ) {
									updateBlockAttributes( chosen.clientId, { name: chosen.name } );
								}
								setAttributes( { routeField: value, routes: value === routeField ? routes : {} } );
							},
							__nextHasNoMarginBottom: true,
						} ),
						routing &&
							routing.options.map( ( option ) =>
								el( TextControl, {
									key: option,
									label: option,
									help: __( 'Comma-separated addresses.', 'fluffless-suite' ),
									value: ( routes && routes[ option ] ) || '',
									onChange: ( value ) => setAttributes( { routes: Object.assign( {}, routes, { [ option ]: value } ) } ),
									__nextHasNoMarginBottom: true,
								} )
							),
						orphans.length > 0 &&
							el(
								'p',
								{ className: 'components-base-control__help' },
								__( 'Addresses kept for options that no longer exist, ignored when sending:', 'fluffless-suite' ) + ' ' + orphans.join( ', ' )
							),
						el( TextControl, {
							label: __( 'Subject', 'fluffless-suite' ),
							placeholder: __( 'New message from the site', 'fluffless-suite' ),
							value: subject,
							maxLength: 200,
							onChange: ( value ) => setAttributes( { subject: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( ToggleControl, {
							label: __( 'Send a copy to the person', 'fluffless-suite' ),
							help: __( 'To the first email field they filled in.', 'fluffless-suite' ),
							checked: !! confirm,
							onChange: ( value ) => setAttributes( { confirm: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( ToggleControl, {
							label: __( 'Keep responses', 'fluffless-suite' ),
							help: __( 'Kept on the site for the number of days set in the module, then deleted. CSV export from the Fluffless settings. Off: nothing is stored.', 'fluffless-suite' ),
							checked: !! keep,
							onChange: ( value ) => setAttributes( { keep: value } ),
							__nextHasNoMarginBottom: true,
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Newsletter', 'fluffless-suite' ), initialOpen: !! newsletter },
						el( ToggleControl, {
							label: __( 'Sign up to the newsletter', 'fluffless-suite' ),
							help: __( 'Sends the person to the newsletter tool set in Fluffless → Modules → Newsletter, only if they check the box chosen below.', 'fluffless-suite' ),
							checked: !! newsletter,
							onChange: ( value ) => setAttributes( { newsletter: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						!! newsletter &&
							el( SelectControl, {
								label: __( 'Newsletter consent box', 'fluffless-suite' ),
								help: __( 'A box about the newsletter only, never pre-checked. It can stay optional on a contact form: only people who check it are signed up. A box that accepts the privacy policy never counts.', 'fluffless-suite' ),
								value: newsletterConsent,
								options: [ { value: '', label: __( 'Choose a consent box', 'fluffless-suite' ) } ].concat(
									consents.map( ( c ) => ( { value: c.name, label: c.label } ) )
								),
								onChange: ( value ) => {
									const box = consents.find( ( c ) => c.name === value );
									if ( box && ! box.hasName ) {
										updateBlockAttributes( box.clientId, { name: box.name } );
									}
									setAttributes( { newsletterConsent: value } );
								},
								__nextHasNoMarginBottom: true,
							} ),
						!! newsletter &&
							! chosen &&
							el(
								Notice,
								{ status: 'warning', isDismissible: false },
								__( 'No consent box chosen: nothing is sent to the newsletter tool.', 'fluffless-suite' )
							),
						!! newsletter &&
							el( TextControl, {
								label: __( 'List or group for this form', 'fluffless-suite' ),
								help: __( 'Optional. Empty: the one set in the Newsletter module.', 'fluffless-suite' ),
								value: newsletterList,
								maxLength: 200,
								onChange: ( value ) => setAttributes( { newsletterList: value } ),
								__nextHasNoMarginBottom: true,
							} )
					),
					el(
						PanelBody,
						{ title: __( 'Texts', 'fluffless-suite' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Button text', 'fluffless-suite' ),
							placeholder: __( 'Send', 'fluffless-suite' ),
							value: submitLabel,
							maxLength: 80,
							onChange: ( value ) => setAttributes( { submitLabel: value } ),
							__nextHasNoMarginBottom: true,
						} ),
						el( TextareaControl, {
							label: __( 'Message after sending', 'fluffless-suite' ),
							placeholder: __( 'Thank you, your message has been sent.', 'fluffless-suite' ),
							value: success,
							onChange: ( value ) => setAttributes( { success: value } ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( 'div', innerBlocksProps ),
					el(
						'p',
						{ className: 'fluffless-formulaire__submit' },
						el( 'span', { className: 'wp-element-button' }, submitLabel || __( 'Send', 'fluffless-suite' ) )
					)
				)
			);
		},
		save: () => el( InnerBlocks.Content ),
	} );
} )( window.wp );
