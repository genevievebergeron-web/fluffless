/**
 * Panneau « SEO » de l'éditeur de blocs : apparence dans les résultats, partage,
 * réglages avancés et analyse du mot-clé. JavaScript natif, sans étape de build,
 * chargé dans l'éditeur seulement.
 */
( function ( wp, config, analysis ) {
	if ( ! wp || ! config || ! analysis ) {
		return;
	}
	const el = wp.element.createElement;
	const { useState, useEffect, Fragment } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { useSelect, useDispatch } = wp.data;
	const { PanelBody, TextControl, TextareaControl, CheckboxControl, SelectControl, Button, BaseControl } = wp.components;
	const { MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editor;

	const KEY = {
		title: '_fluffless_seo_title',
		desc: '_fluffless_description',
		canonical: '_fluffless_seo_canonical',
		robots: '_fluffless_seo_robots',
		ogTitle: '_fluffless_seo_og_title',
		ogDesc: '_fluffless_seo_og_desc',
		ogImage: '_fluffless_seo_og_image',
		keyword: '_fluffless_seo_keyword',
		schema: '_fluffless_seo_schema',
		schemaData: '_fluffless_seo_schema_data',
	};
	const SIDEBAR = 'fluffless-seo';

	// Avec Fluffless Suite, son panneau Qualité dit quels gabarits affichent déjà le titre en H1
	// (Sections et Toile vierge ne le font pas) : les deux panneaux donnent alors le même conseil.
	function titleIsH1( template ) {
		const quality = window.flufflessQuality;
		if ( ! quality || ! quality.templates ) {
			return true;
		}
		return false !== quality.templates[ template || quality.defaultTemplate || '' ];
	}
	const STATUS = {
		good: __( 'Good', 'fluffless-seo' ),
		warn: __( 'To improve', 'fluffless-seo' ),
		bad: __( 'Problem', 'fluffless-seo' ),
	};

	/** Message d'une vérification de l'analyse. */
	function message( check ) {
		const d = check.data;
		switch ( check.id ) {
			case 'title_length':
				/* translators: 1: width in pixels, 2: limit in pixels. */
				return sprintf( __( 'Title width: about %1$d of %2$d px.', 'fluffless-seo' ), d.width, d.max );
			case 'desc_length':
				/* translators: 1: width in pixels, 2: limit in pixels. */
				return sprintf( __( 'Description width: about %1$d of %2$d px.', 'fluffless-seo' ), d.width, d.max );
			case 'content_length':
				/* translators: 1: word count, 2: suggested minimum. */
				return sprintf( __( '%1$d words (suggested: %2$d or more).', 'fluffless-seo' ), d.words, d.min );
			case 'headings':
				if ( false === d.titleIsH1 && 1 !== d.h1s ) {
					return d.h1s
						? __( 'More than one level 1 heading: keep one per page.', 'fluffless-seo' )
						: __( 'No level 1 heading: this template does not show the page title, so the content needs one.', 'fluffless-seo' );
				}
				if ( false !== d.titleIsH1 && d.hasH1 ) {
					return __( 'The content holds a level 1 heading: the page title already is one.', 'fluffless-seo' );
				}
				if ( d.skipped ) {
					return __( 'A heading level is skipped (for example, level 2 then level 4).', 'fluffless-seo' );
				}
				return d.hasH2 ? __( 'Heading levels follow each other.', 'fluffless-seo' ) : __( 'No level 2 heading to structure a long text.', 'fluffless-seo' );
			case 'links_internal':
				/* translators: %d: number of links. */
				return sprintf( __( 'Links to this site: %d.', 'fluffless-seo' ), d.count );
			case 'links_external':
				/* translators: %d: number of links. */
				return sprintf( __( 'Links to other sites: %d.', 'fluffless-seo' ), d.count );
			case 'keyword_title':
				return __( 'Keyword in the SEO title.', 'fluffless-seo' );
			case 'keyword_desc':
				return __( 'Keyword in the description.', 'fluffless-seo' );
			case 'keyword_intro':
				return __( 'Keyword in the first paragraph.', 'fluffless-seo' );
			case 'keyword_h2':
				return __( 'Keyword in a level 2 heading.', 'fluffless-seo' );
			case 'keyword_url':
				return __( 'Keyword in the address (slug).', 'fluffless-seo' );
			case 'keyword_alt':
				return d.images ? __( 'Keyword in an image text alternative.', 'fluffless-seo' ) : __( 'No image in the content.', 'fluffless-seo' );
			case 'keyword_density':
				/* translators: 1: number of occurrences, 2: density in percent. */
				return sprintf( __( 'Keyword found %1$d times (%2$s %% of words).', 'fluffless-seo' ), d.occurrences, String( d.density ) );
		}
		return check.id;
	}

	/** Valeur différée : l'analyse ne tourne pas à chaque frappe. */
	function useDebounced( value, delay ) {
		const [ current, setCurrent ] = useState( value );
		useEffect( () => {
			const timer = setTimeout( () => setCurrent( value ), delay );
			return () => clearTimeout( timer );
		}, [ value, delay ] );
		return current;
	}

	/** Compteur de largeur : texte et état, jamais la couleur seule. */
	function counter( text, limits ) {
		const width = analysis.textWidth( text, limits.size );
		const state = width > limits.max ? STATUS.bad : width < limits.min ? STATUS.warn : STATUS.good;
		/* translators: 1: width in pixels, 2: limit in pixels, 3: state label. */
		return sprintf( __( 'About %1$d of %2$d px. %3$s', 'fluffless-seo' ), width, limits.max, text ? state : '' );
	}

	function Panel() {
		const { meta, postTitle, excerpt, content, slug, permalink, template } = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return {
				meta: editor.getEditedPostAttribute( 'meta' ) || {},
				postTitle: editor.getEditedPostAttribute( 'title' ) || '',
				excerpt: editor.getEditedPostAttribute( 'excerpt' ) || '',
				content: editor.getEditedPostContent() || '',
				slug: editor.getEditedPostSlug ? editor.getEditedPostSlug() || '' : '',
				permalink: editor.getPermalink ? editor.getPermalink() || '' : '',
				template: editor.getEditedPostAttribute( 'template' ) || '',
			};
		}, [] );
		const { editPost } = useDispatch( 'core/editor' );
		const set = ( key, value ) => editPost( { meta: { [ key ]: value } } );
		const text = ( key ) => ( typeof meta[ key ] === 'string' ? meta[ key ] : '' );

		const plainContent = analysis.shorten( String( content ).replace( /<[^>]+>/g, ' ' ), 160 );
		const values = {
			title: postTitle,
			sitename: config.sitename,
			sitedesc: config.tagline,
			sep: config.sep,
			excerpt: analysis.shorten( excerpt, 160 ) || plainContent,
			currentyear: String( new Date().getFullYear() ),
			page: '',
		};
		const seoTitle = analysis.fillTemplate( text( KEY.title ) || config.titleTemplate, values, config.sep );
		const seoDesc = analysis.shorten( analysis.fillTemplate( text( KEY.desc ) || config.descTemplate, values, config.sep ), 160 );

		const debounced = useDebounced( { content, seoTitle, seoDesc, slug, keyword: text( KEY.keyword ) }, 600 );
		const result = config.analysis
			? analysis.analyse( {
					keyword: debounced.keyword,
					title: debounced.seoTitle,
					description: debounced.seoDesc,
					slug: debounced.slug,
					html: debounced.content,
					home: config.home,
					titleIsH1: titleIsH1( template ),
			  } )
			: null;

		const robots = Array.isArray( meta[ KEY.robots ] ) ? meta[ KEY.robots ] : [];
		const toggleRobot = ( value, on ) => set( KEY.robots, on ? [ ...robots.filter( ( r ) => r !== value ), value ] : robots.filter( ( r ) => r !== value ) );
		const preview = robots.find( ( r ) => r.indexOf( 'max-image-preview:' ) === 0 ) || '';
		const schemaData = meta[ KEY.schemaData ] && typeof meta[ KEY.schemaData ] === 'object' ? meta[ KEY.schemaData ] : {};
		const setData = ( field, value ) => {
			const next = Object.assign( {}, schemaData );
			if ( value ) {
				next[ field ] = value;
			} else {
				delete next[ field ];
			}
			set( KEY.schemaData, next );
		};
		const schema = text( KEY.schema ) || '';
		const effectiveSchema = schema || config.defaultSchema;
		const imageId = Number( meta[ KEY.ogImage ] ) || 0;

		const dataField = ( field, label, type ) =>
			el( TextControl, {
				key: field,
				label,
				type: type || 'text',
				value: schemaData[ field ] || '',
				onChange: ( v ) => setData( field, v ),
				__nextHasNoMarginBottom: true,
			} );

		return el(
			Fragment,
			null,
			el(
				PanelBody,
				{ title: __( 'Search appearance', 'fluffless-seo' ), initialOpen: true },
				el(
					'div',
					{ className: 'fluffless-seo-serp', style: { fontFamily: 'Arial, sans-serif', padding: '8px 0 12px' } },
					el( 'div', { style: { fontSize: '12px', color: '#3c4043', overflowWrap: 'anywhere' } }, permalink ),
					el( 'div', { style: { fontSize: '18px', color: '#1a0dab', lineHeight: 1.3 } }, seoTitle || postTitle ),
					el( 'div', { style: { fontSize: '13px', color: '#4d5156', lineHeight: 1.4 } }, seoDesc )
				),
				el( TextControl, {
					label: __( 'SEO title', 'fluffless-seo' ),
					value: text( KEY.title ),
					placeholder: config.titleTemplate,
					help: counter( seoTitle, analysis.TITLE ),
					onChange: ( v ) => set( KEY.title, v ),
					__nextHasNoMarginBottom: true,
				} ),
				el( TextareaControl, {
					label: __( 'Meta description', 'fluffless-seo' ),
					value: text( KEY.desc ),
					placeholder: seoDesc,
					help: counter( seoDesc, analysis.DESC ),
					onChange: ( v ) => set( KEY.desc, v ),
					__nextHasNoMarginBottom: true,
				} ),
				el( 'p', { className: 'description' }, __( 'Variables: %%title%%, %%sitename%%, %%sep%%, %%excerpt%%. Widths are estimates.', 'fluffless-seo' ) )
			),
			config.social &&
				el(
					PanelBody,
					{ title: __( 'Sharing', 'fluffless-seo' ), initialOpen: false },
					el( TextControl, {
						label: __( 'Title when shared', 'fluffless-seo' ),
						value: text( KEY.ogTitle ),
						placeholder: postTitle,
						onChange: ( v ) => set( KEY.ogTitle, v ),
						__nextHasNoMarginBottom: true,
					} ),
					el( TextareaControl, {
						label: __( 'Description when shared', 'fluffless-seo' ),
						value: text( KEY.ogDesc ),
						placeholder: seoDesc,
						onChange: ( v ) => set( KEY.ogDesc, v ),
						__nextHasNoMarginBottom: true,
					} ),
					el(
						BaseControl,
						{ label: __( 'Image when shared', 'fluffless-seo' ), help: __( 'Empty: the featured image, then the default image.', 'fluffless-seo' ), __nextHasNoMarginBottom: true },
						el(
							MediaUploadCheck,
							null,
							el( MediaUpload, {
								allowedTypes: [ 'image' ],
								value: imageId,
								onSelect: ( media ) => set( KEY.ogImage, media && media.id ? media.id : 0 ),
								render: ( { open } ) =>
									el(
										'div',
										{ style: { display: 'flex', gap: '8px', flexWrap: 'wrap' } },
										el( Button, { variant: 'secondary', onClick: open }, imageId ? __( 'Replace image', 'fluffless-seo' ) : __( 'Choose image', 'fluffless-seo' ) ),
										imageId ? el( Button, { variant: 'tertiary', isDestructive: true, onClick: () => set( KEY.ogImage, 0 ) }, __( 'Remove image', 'fluffless-seo' ) ) : null
									),
							} )
						)
					)
				),
			el(
				PanelBody,
				{ title: __( 'Advanced', 'fluffless-seo' ), initialOpen: false },
				el( TextControl, {
					label: __( 'Canonical address', 'fluffless-seo' ),
					type: 'url',
					value: text( KEY.canonical ),
					help: __( 'Empty: this page’s own address. https only.', 'fluffless-seo' ),
					onChange: ( v ) => set( KEY.canonical, v ),
					__nextHasNoMarginBottom: true,
				} ),
				el(
					'fieldset',
					null,
					el( 'legend', null, __( 'Search engine directives', 'fluffless-seo' ) ),
					[
						[ 'noindex', __( 'Keep out of search results (noindex)', 'fluffless-seo' ) ],
						[ 'nofollow', __( 'Do not follow links (nofollow)', 'fluffless-seo' ) ],
						[ 'noarchive', __( 'No cached copy (noarchive)', 'fluffless-seo' ) ],
						[ 'nosnippet', __( 'No text snippet (nosnippet)', 'fluffless-seo' ) ],
					].map( ( [ value, label ] ) =>
						el( CheckboxControl, {
							key: value,
							label,
							checked: robots.includes( value ),
							onChange: ( on ) => toggleRobot( value, on ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el( SelectControl, {
					label: __( 'Image preview size', 'fluffless-seo' ),
					value: preview,
					options: [
						{ value: '', label: __( 'Large (default)', 'fluffless-seo' ) },
						{ value: 'max-image-preview:standard', label: __( 'Standard', 'fluffless-seo' ) },
						{ value: 'max-image-preview:none', label: __( 'None', 'fluffless-seo' ) },
					],
					onChange: ( v ) => set( KEY.robots, [ ...robots.filter( ( r ) => r.indexOf( 'max-image-preview:' ) !== 0 ), ...( v ? [ v ] : [] ) ] ),
					__nextHasNoMarginBottom: true,
				} ),
				config.schema &&
					el( SelectControl, {
						label: __( 'Structured data type', 'fluffless-seo' ),
						value: schema,
						options: [ { value: '', label: __( 'Automatic', 'fluffless-seo' ) } ].concat(
							config.schemaTypes.map( ( type ) => ( { value: type, label: type === 'none' ? __( 'None', 'fluffless-seo' ) : type } ) )
						),
						onChange: ( v ) => set( KEY.schema, v ),
						__nextHasNoMarginBottom: true,
					} ),
				config.schema && effectiveSchema === 'Event' && [
					dataField( 'start', __( 'Start (date and time)', 'fluffless-seo' ), 'datetime-local' ),
					dataField( 'end', __( 'End (date and time)', 'fluffless-seo' ), 'datetime-local' ),
					dataField( 'place', __( 'Venue name', 'fluffless-seo' ) ),
					dataField( 'address', __( 'Venue address', 'fluffless-seo' ) ),
					el( CheckboxControl, {
						key: 'online',
						label: __( 'Online event', 'fluffless-seo' ),
						checked: schemaData.online === '1',
						onChange: ( on ) => setData( 'online', on ? '1' : '' ),
						__nextHasNoMarginBottom: true,
					} ),
				],
				config.schema && [ 'Event', 'Product', 'Service', 'SoftwareApplication' ].includes( effectiveSchema ) && [
					dataField( 'price', __( 'Price', 'fluffless-seo' ) ),
					dataField( 'currency', __( 'Currency (e.g. CAD)', 'fluffless-seo' ) ),
				],
				config.schema && effectiveSchema === 'Product' && [
					dataField( 'sku', __( 'SKU', 'fluffless-seo' ) ),
					dataField( 'brand', __( 'Brand', 'fluffless-seo' ) ),
					el( SelectControl, {
						key: 'availability',
						label: __( 'Availability', 'fluffless-seo' ),
						value: schemaData.availability || '',
						options: [ '', 'InStock', 'OutOfStock', 'PreOrder', 'SoldOut', 'OnlineOnly', 'LimitedAvailability' ].map( ( v ) => ( { value: v, label: v || '-' } ) ),
						onChange: ( v ) => setData( 'availability', v ),
						__nextHasNoMarginBottom: true,
					} ),
				],
				config.schema && effectiveSchema === 'Service' && [
					dataField( 'service_type', __( 'Kind of service', 'fluffless-seo' ) ),
					dataField( 'area', __( 'Area served', 'fluffless-seo' ) ),
				],
				config.schema && effectiveSchema === 'SoftwareApplication' && [
					dataField( 'app_category', __( 'Application category (e.g. WebApplication)', 'fluffless-seo' ) ),
					dataField( 'os', __( 'Operating system or platform (e.g. WordPress)', 'fluffless-seo' ) ),
				],
				config.schema && effectiveSchema === 'Person' && dataField( 'job_title', __( 'Job title', 'fluffless-seo' ) )
			),
			result &&
				el(
					PanelBody,
					{ title: __( 'Analysis', 'fluffless-seo' ), initialOpen: false },
					el( TextControl, {
						label: __( 'Target keyword', 'fluffless-seo' ),
						value: text( KEY.keyword ),
						help: __( 'Checked in this browser only: nothing is sent anywhere.', 'fluffless-seo' ),
						onChange: ( v ) => set( KEY.keyword, v ),
						__nextHasNoMarginBottom: true,
					} ),
					el(
						'ul',
						{ className: 'fluffless-seo-checks', style: { listStyle: 'none', margin: '12px 0 0', padding: 0 } },
						result.checks.map( ( check ) =>
							el(
								'li',
								{ key: check.id, style: { margin: '0 0 8px', paddingLeft: '8px', borderLeft: '4px solid ' + ( check.status === 'good' ? '#00a32a' : check.status === 'warn' ? '#dba617' : '#d63638' ) } },
								el( 'strong', null, STATUS[ check.status ] + ' : ' ),
								message( check )
							)
						)
					)
				)
		);
	}

	wp.plugins.registerPlugin( SIDEBAR, {
		icon: 'search',
		render: () =>
			el(
				Fragment,
				null,
				el( PluginSidebarMoreMenuItem, { target: SIDEBAR }, __( 'SEO', 'fluffless-seo' ) ),
				el( PluginSidebar, { name: SIDEBAR, title: __( 'SEO', 'fluffless-seo' ) }, el( Panel ) )
			),
	} );
} )( window.wp, window.flufflessSeo, window.flufflessSeoAnalysis );
