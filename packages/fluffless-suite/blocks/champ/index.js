/**
 * Éditeur du bloc fluffless/champ. JavaScript natif, sans étape de build.
 * L'aperçu reprend les classes du rendu serveur (Form::render_field()) ; rien n'est enregistré
 * dans le contenu, le site reçoit le rendu serveur.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __, sprintf } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls, RichText } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl, TextareaControl, ToggleControl } = wp.components;
	const { useSelect, useDispatch } = wp.data;

	const TYPES = [
		{ value: 'text', label: __( 'Short text', 'fluffless-suite' ) },
		{ value: 'email', label: __( 'Email', 'fluffless-suite' ) },
		{ value: 'phone', label: __( 'Phone', 'fluffless-suite' ) },
		{ value: 'number', label: __( 'Number', 'fluffless-suite' ) },
		{ value: 'date', label: __( 'Date', 'fluffless-suite' ) },
		{ value: 'select', label: __( 'Drop-down list', 'fluffless-suite' ) },
		{ value: 'radio', label: __( 'One choice among several', 'fluffless-suite' ) },
		{ value: 'checkboxes', label: __( 'Several choices', 'fluffless-suite' ) },
		{ value: 'textarea', label: __( 'Long text', 'fluffless-suite' ) },
		{ value: 'consent', label: __( 'Consent box', 'fluffless-suite' ) },
		{ value: 'hidden', label: __( 'Hidden value', 'fluffless-suite' ) },
	];
	const INPUT = { text: 'text', email: 'email', phone: 'tel', number: 'number', date: 'date' };
	/** Jetons autocomplete (WCAG 1.3.5) ; même liste blanche que Form::AUTOCOMPLETE_TOKENS. */
	const AUTOCOMPLETE = [
		{ value: 'name', label: __( 'Full name', 'fluffless-suite' ) },
		{ value: 'honorific-prefix', label: __( 'Title (Ms, Mr, Dr)', 'fluffless-suite' ) },
		{ value: 'given-name', label: __( 'First name', 'fluffless-suite' ) },
		{ value: 'additional-name', label: __( 'Middle name', 'fluffless-suite' ) },
		{ value: 'family-name', label: __( 'Last name', 'fluffless-suite' ) },
		{ value: 'nickname', label: __( 'Nickname', 'fluffless-suite' ) },
		{ value: 'organization-title', label: __( 'Job title', 'fluffless-suite' ) },
		{ value: 'organization', label: __( 'Organization', 'fluffless-suite' ) },
		{ value: 'email', label: __( 'Email address', 'fluffless-suite' ) },
		{ value: 'tel', label: __( 'Phone number', 'fluffless-suite' ) },
		{ value: 'tel-national', label: __( 'Phone number without country code', 'fluffless-suite' ) },
		{ value: 'street-address', label: __( 'Street address', 'fluffless-suite' ) },
		{ value: 'address-line1', label: __( 'Address line 1', 'fluffless-suite' ) },
		{ value: 'address-line2', label: __( 'Address line 2', 'fluffless-suite' ) },
		{ value: 'address-level2', label: __( 'City', 'fluffless-suite' ) },
		{ value: 'address-level1', label: __( 'Province or state', 'fluffless-suite' ) },
		{ value: 'postal-code', label: __( 'Postal code', 'fluffless-suite' ) },
		{ value: 'country', label: __( 'Country code', 'fluffless-suite' ) },
		{ value: 'country-name', label: __( 'Country name', 'fluffless-suite' ) },
		{ value: 'bday', label: __( 'Birthday', 'fluffless-suite' ) },
		{ value: 'url', label: __( 'Website', 'fluffless-suite' ) },
	];
	const CHOICE = [ 'select', 'radio', 'checkboxes' ];
	const CONTROL = [ 'select', 'radio', 'checkboxes', 'consent' ];

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

	/** Champs à choix de la même étape (ou du même formulaire sans étapes) qui peuvent commander celui-ci. */
	function useControllers( clientId ) {
		return useSelect(
			( select ) => {
				const be = select( 'core/block-editor' );
				const forms = be.getBlockParentsByBlockName( clientId, 'fluffless/formulaire' );
				if ( ! forms.length ) {
					return [];
				}
				const form = forms[ forms.length - 1 ];
				const ownStep = be.getBlockParentsByBlockName( clientId, 'fluffless/etape' ).pop() || '';
				return be
					.getClientIdsOfDescendants( form )
					.map( ( id ) => be.getBlock( id ) )
					.filter( ( b ) => b && b.name === 'fluffless/champ' && b.clientId !== clientId && CONTROL.includes( b.attributes.type ) )
					.filter( ( b ) => ( be.getBlockParentsByBlockName( b.clientId, 'fluffless/etape' ).pop() || '' ) === ownStep )
					.map( ( b ) => ( {
						clientId: b.clientId,
						type: b.attributes.type,
						name: b.attributes.name || slug( b.attributes.label ),
						label: ( b.attributes.label || '' ).replace( /<[^>]*>/g, '' ) || b.attributes.name || __( 'Field', 'fluffless-suite' ),
						hasName: !! b.attributes.name,
						options: lines( b.attributes.options ),
					} ) );
			},
			[ clientId ]
		);
	}

	function lines( text ) {
		return ( text || '' ).split( '\n' ).map( ( s ) => s.trim() ).filter( Boolean );
	}

	function preview( attributes, labelNode ) {
		const { type, placeholder, help, value } = attributes;
		const helpNode = help ? el( 'p', { className: 'fluffless-champ__help' }, help ) : null;
		if ( type === 'hidden' ) {
			return el( 'p', { className: 'fluffless-champ__help' }, __( 'Hidden value', 'fluffless-suite' ) + ( value ? ' : ' + value : '' ) );
		}
		if ( type === 'consent' ) {
			return el(
				wp.element.Fragment,
				null,
				el( 'label', { className: 'fluffless-champ__choice' }, el( 'input', { type: 'checkbox', tabIndex: -1, readOnly: true, checked: false, onChange: () => {} } ), labelNode ),
				helpNode
			);
		}
		if ( type === 'radio' || type === 'checkboxes' ) {
			const items = lines( attributes.options );
			return el(
				'fieldset',
				null,
				el( 'legend', null, labelNode ),
				helpNode,
				items.length
					? items.map( ( option, i ) =>
						el( 'label', { className: 'fluffless-champ__choice', key: i }, el( 'input', { type: type === 'radio' ? 'radio' : 'checkbox', tabIndex: -1, readOnly: true, checked: false, onChange: () => {} } ), el( 'span', null, option ) )
					)
					: el( 'p', { className: 'fluffless-champ__help' }, __( 'Add one option per line in the block settings.', 'fluffless-suite' ) )
			);
		}
		let control;
		if ( type === 'select' ) {
			control = el( 'select', { tabIndex: -1, disabled: true }, el( 'option', null, placeholder || __( 'Choose…', 'fluffless-suite' ) ), lines( attributes.options ).map( ( option, i ) => el( 'option', { key: i }, option ) ) );
		} else if ( type === 'textarea' ) {
			control = el( 'textarea', { rows: 4, placeholder, tabIndex: -1, readOnly: true } );
		} else {
			control = el( 'input', { type: INPUT[ type ] || 'text', placeholder, tabIndex: -1, readOnly: true } );
		}
		return el( wp.element.Fragment, null, el( 'label', null, labelNode ), helpNode, control );
	}

	registerBlockType( 'fluffless/champ', {
		edit( { attributes, setAttributes, clientId } ) {
			const { type, label, name, required, placeholder, autocomplete, help, options, value, when, is } = attributes;
			const blockProps = useBlockProps( { className: 'fluffless-champ is-type-' + type + ( when ? ' has-condition' : '' ) } );
			const isChoice = CHOICE.includes( type );
			const hasPlaceholder = type === 'select' || type === 'textarea' || INPUT[ type ];
			const controllers = useControllers( clientId );
			const { updateBlockAttributes } = useDispatch( 'core/block-editor' );
			const controller = controllers.find( ( c ) => c.name === when );
			const whenOptions = [ { value: '', label: __( 'Always shown', 'fluffless-suite' ) } ].concat(
				controllers.map( ( c ) => ( { value: c.name, label: c.label } ) ),
				when && ! controller ? [ { value: when, label: __( 'Unknown field', 'fluffless-suite' ) + ' : ' + when } ] : []
			);
			const conditionNote = when
				? el(
					'p',
					{ className: 'fluffless-champ__help' },
					controller && controller.type === 'consent'
						? sprintf(
							/* translators: %s: libellé de la case de consentement qui commande. */
							__( 'Shown only if “%s” is checked.', 'fluffless-suite' ),
							controller.label
						)
						: sprintf(
							/* translators: 1: libellé du champ qui commande, 2: valeur attendue. */
							__( 'Shown only if “%1$s” is “%2$s”.', 'fluffless-suite' ),
							controller ? controller.label : when,
							is
						)
				)
				: null;
			const labelNode = el(
				wp.element.Fragment,
				null,
				el( RichText, {
					tagName: 'span',
					value: label,
					placeholder: type === 'consent' ? __( 'I agree to be contacted', 'fluffless-suite' ) : __( 'Label', 'fluffless-suite' ),
					allowedFormats: [],
					withoutInteractiveFormatting: true,
					onChange: ( v ) => setAttributes( { label: v } ),
				} ),
				required ? el( 'span', { className: 'fluffless-champ__required', 'aria-hidden': true }, ' *' ) : null
			);

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Field', 'fluffless-suite' ) },
						el( SelectControl, {
							label: __( 'Type', 'fluffless-suite' ),
							value: type,
							options: TYPES,
							onChange: ( v ) => setAttributes( { type: v } ),
							__nextHasNoMarginBottom: true,
						} ),
						type !== 'hidden' &&
							el( ToggleControl, {
								label: __( 'Required', 'fluffless-suite' ),
								checked: !! required,
								onChange: ( v ) => setAttributes( { required: v } ),
								__nextHasNoMarginBottom: true,
							} ),
						isChoice &&
							el( TextareaControl, {
								label: __( 'Options', 'fluffless-suite' ),
								help: __( 'One per line.', 'fluffless-suite' ),
								value: options,
								onChange: ( v ) => setAttributes( { options: v } ),
								__nextHasNoMarginBottom: true,
							} ),
						hasPlaceholder &&
							el( TextControl, {
								label: type === 'select' ? __( 'First line of the list', 'fluffless-suite' ) : __( 'Placeholder', 'fluffless-suite' ),
								value: placeholder,
								maxLength: 1000,
								onChange: ( v ) => setAttributes( { placeholder: v } ),
								__nextHasNoMarginBottom: true,
							} ),
						INPUT[ type ] &&
							el( SelectControl, {
								label: __( 'Browser autofill', 'fluffless-suite' ),
								help: __( 'Lets browsers and password managers fill this field. Empty: automatic for email and phone.', 'fluffless-suite' ),
								value: autocomplete,
								options: [ { value: '', label: __( 'Default', 'fluffless-suite' ) } ].concat( AUTOCOMPLETE ),
								onChange: ( v ) => setAttributes( { autocomplete: v } ),
								__nextHasNoMarginBottom: true,
							} ),
						type === 'hidden' &&
							el( TextControl, {
								label: __( 'Value', 'fluffless-suite' ),
								help: __( 'Sent with every message, for example the name of the page.', 'fluffless-suite' ),
								value: value,
								maxLength: 500,
								onChange: ( v ) => setAttributes( { value: v } ),
								__nextHasNoMarginBottom: true,
							} ),
						type !== 'hidden' &&
							el( TextControl, {
								label: __( 'Help text', 'fluffless-suite' ),
								value: help,
								maxLength: 500,
								onChange: ( v ) => setAttributes( { help: v } ),
								__nextHasNoMarginBottom: true,
							} ),
						el( TextControl, {
							label: __( 'Technical name', 'fluffless-suite' ),
							help: __( 'Empty: made from the label. Letters, digits and dashes.', 'fluffless-suite' ),
							value: name,
							maxLength: 40,
							onChange: ( v ) => setAttributes( { name: v } ),
							__nextHasNoMarginBottom: true,
						} )
					),
					type !== 'hidden' &&
						el(
							PanelBody,
							{ title: __( 'Condition', 'fluffless-suite' ), initialOpen: !! when },
							el( SelectControl, {
								label: __( 'Show only when', 'fluffless-suite' ),
								help: controllers.length
									? __( 'A list, choice or consent field of the same step. Without JavaScript: the field is hidden by a style rule and the server ignores it when hidden.', 'fluffless-suite' )
									: __( 'Add a list, choice or consent field to this step first.', 'fluffless-suite' ),
								value: when,
								options: whenOptions,
								onChange: ( v ) => {
									const chosen = controllers.find( ( c ) => c.name === v );
									if ( chosen && ! chosen.hasName ) {
										updateBlockAttributes( chosen.clientId, { name: chosen.name } );
									}
									setAttributes( { when: v, is: chosen && chosen.options.length ? chosen.options[ 0 ] : '' } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							controller && controller.type !== 'consent' &&
								el( SelectControl, {
									label: __( 'Has the value', 'fluffless-suite' ),
									value: is,
									options: controller.options.map( ( o ) => ( { value: o, label: o } ) ),
									onChange: ( v ) => setAttributes( { is: v } ),
									__nextHasNoMarginBottom: true,
								} )
						)
				),
				el( 'div', blockProps, conditionNote, preview( attributes, labelNode ) )
			);
		},
		save: () => null,
	} );
} )( window.wp );
