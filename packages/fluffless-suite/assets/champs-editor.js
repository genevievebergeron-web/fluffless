/**
 * Module Champs personnalisés de Fluffless Suite : source de liaison fluffless/champ côté éditeur
 * et panneau « Champ personnalisé » dans l'inspecteur des blocs Paragraphe, Titre, Image, Bouton
 * et, à partir de WP 7.1, Élément de liste. JavaScript natif, sans étape de build. Chargé dans l'éditeur seulement.
 * Le panneau écrit metadata.bindings, la mécanique native du cœur ; un champ déclaré par la Suite
 * passe par core/post-meta (valeur vivante dans l'éditeur), un champ ACF ou Meta Box par
 * fluffless/champ (rendu par le serveur).
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __, sprintf } = wp.i18n;
	const { addFilter } = wp.hooks;
	const { createHigherOrderComponent } = wp.compose;
	const { InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl } = wp.components;
	const { useSelect } = wp.data;

	const SOURCE = 'fluffless/champ';
	const DATA = window.flufflessChamps || { types: {}, attributes: {} };
	// Blocs et attributs liables, fournis par le serveur selon la version de WordPress
	// (Fields::bindable : l'Élément de liste à partir de 7.1 seulement).
	const ATTRIBUTES = DATA.attributes || {};

	if ( wp.blocks.registerBlockBindingsSource && ! ( wp.blocks.getBlockBindingsSource && wp.blocks.getBlockBindingsSource( SOURCE ) ) ) {
		wp.blocks.registerBlockBindingsSource( {
			name: SOURCE,
			label: __( 'Custom field (Fluffless)', 'fluffless-suite' ),
			usesContext: [ 'postType' ],
			getValues( { bindings } ) {
				const values = {};
				Object.keys( bindings ).forEach( ( attribute ) => {
					const key = bindings[ attribute ].args && bindings[ attribute ].args.key;
					/* translators: %s: custom field key */
					values[ attribute ] = key ? sprintf( __( 'Field: %s', 'fluffless-suite' ), key ) : '';
				} );
				return values;
			},
			canUserEditValue() {
				return false;
			},
		} );
	}

	// Champs proposés pour un type : ceux déclarés dans la Suite puis ceux d'ACF.
	const fieldsFor = ( postType ) => DATA.types[ postType ] || [];

	// Liaison courante d'un attribut : clé du champ, quelle que soit la source.
	const currentKey = ( bindings, attribute ) => {
		const binding = bindings && bindings[ attribute ];
		if ( ! binding || ! binding.args ) {
			return '';
		}
		return binding.args.key || '';
	};

	const bindingFor = ( field ) => ( field.native ? { source: 'core/post-meta', args: { key: field.key } } : { source: SOURCE, args: { key: field.key } } );

	const attributeLabel = ( attribute ) => {
		switch ( attribute ) {
			case 'url':
				return __( 'Link or image address', 'fluffless-suite' );
			case 'alt':
				return __( 'Alternative text', 'fluffless-suite' );
			case 'title':
				return __( 'Title', 'fluffless-suite' );
			case 'text':
				return __( 'Button text', 'fluffless-suite' );
			default:
				return __( 'Content', 'fluffless-suite' );
		}
	};

	const withPanel = createHigherOrderComponent( ( BlockEdit ) => ( props ) => {
		const attributes = ATTRIBUTES[ props.name ];
		const postType = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return props.context && props.context.postType ? props.context.postType : editor && editor.getCurrentPostType ? editor.getCurrentPostType() : '';
		}, [ props.context ] );
		if ( ! attributes ) {
			return el( BlockEdit, props );
		}
		const fields = fieldsFor( postType );
		if ( ! fields.length ) {
			return el( BlockEdit, props );
		}
		const metadata = props.attributes.metadata || {};
		const bindings = metadata.bindings || {};
		const options = [ { value: '', label: __( 'None', 'fluffless-suite' ) } ].concat( fields.map( ( f ) => ( { value: f.key, label: f.label + ( f.native ? '' : ' (ACF)' ) } ) ) );
		const setBinding = ( attribute, key ) => {
			const next = { ...bindings };
			const field = fields.find( ( f ) => f.key === key );
			if ( field ) {
				next[ attribute ] = bindingFor( field );
			} else {
				delete next[ attribute ];
			}
			const nextMetadata = { ...metadata };
			if ( Object.keys( next ).length ) {
				nextMetadata.bindings = next;
			} else {
				delete nextMetadata.bindings;
			}
			props.setAttributes( { metadata: Object.keys( nextMetadata ).length ? nextMetadata : undefined } );
		};
		return el(
			wp.element.Fragment,
			null,
			el( BlockEdit, props ),
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Custom field', 'fluffless-suite' ), initialOpen: false },
					attributes.map( ( attribute ) =>
						el( SelectControl, {
							key: attribute,
							label: attributeLabel( attribute ),
							value: currentKey( bindings, attribute ),
							options,
							onChange: ( key ) => setBinding( attribute, key ),
							__nextHasNoMarginBottom: true,
						} )
					),
					el( 'p', { className: 'description' }, __( 'The value comes from the entry being shown; edit it in the Fields box of that entry.', 'fluffless-suite' ) )
				)
			)
		);
	}, 'withFlufflessChamps' );

	addFilter( 'editor.BlockEdit', 'fluffless/champs/panel', withPanel );
} )( window.wp );
