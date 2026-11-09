/**
 * Panneau Palette de Fluffless Suite, dans l'éditeur de site. JavaScript natif, sans étape de build.
 * Le générateur (assets/admin/palette-core.js) propose une palette lisible ; « Appliquer » l'écrit
 * dans les styles globaux en cours d'édition, et le bouton Enregistrer de l'éditeur la garde.
 * Données et textes : window.flufflessPalette (Palette::editor_data()). Rien de ceci n'arrive sur le site.
 */
( function ( wp, core ) {
	const data = window.flufflessPalette;
	const Sidebar = ( wp.editor && wp.editor.PluginSidebar ) || ( wp.editSite && wp.editSite.PluginSidebar );
	if ( ! core || ! data || ! wp.plugins || ! Sidebar ) {
		return;
	}
	const el = wp.element.createElement;
	const { useState } = wp.element;
	const { useSelect, useDispatch } = wp.data;
	const { PanelBody, SelectControl, Button, CheckboxControl, ColorIndicator, Notice, ExternalLink } = wp.components;
	const t = data.i18n;
	const fill = ( text, ...args ) => {
		let n = 0;
		return String( text || '' ).replace( /%(\d\$)?[sd]/g, ( m, pos ) => String( args[ pos ? parseInt( pos, 10 ) - 1 : n++ ] ) );
	};

	function Panel() {
		const { id, record } = useSelect( ( select ) => {
			const store = select( 'core' );
			const gid = store.__experimentalGetCurrentGlobalStylesId();
			return { id: gid, record: gid ? store.getEditedEntityRecord( 'root', 'globalStyles', gid ) : null };
		}, [] );
		const { editEntityRecord } = useDispatch( 'core' );
		const [ opts, setOpts ] = useState( { mood: 'variee', strict: 'equilibre', harmony: 'libre' } );
		const [ locked, setLocked ] = useState( {} );
		const [ draft, setDraft ] = useState( null );

		// Palette en cours d'édition : celle du thème, recouverte par les styles globaux non enregistrés.
		const settings = ( record && record.settings ) || {};
		const color = settings.color || {};
		const current = { ...data.palette };
		( ( color.palette && color.palette.theme ) || [] ).forEach( ( c ) => {
			if ( c && ( core.ROLES.includes( c.slug ) || core.OPTIONAL.includes( c.slug ) ) && 'string' === typeof c.color ) {
				current[ c.slug ] = c.color.toLowerCase();
			}
		} );
		// Une palette générée garde les rôles facultatifs en place : ils restent vérifiés et écrits.
		const optional = {};
		core.OPTIONAL.forEach( ( r ) => {
			if ( current[ r ] ) {
				optional[ r ] = current[ r ];
			}
		} );
		const pal = draft ? { ...draft, ...optional } : current;
		const bad = core.check( pal, data.button ).map( ( p ) => {
			const [ fg, bg ] = p.split( '/' );
			return fill( t.pair, data.roles[ fg ], data.roles[ bg ] );
		} );

		const run = () => {
			const keep = {};
			core.ROLES.forEach( ( r ) => {
				if ( locked[ r ] ) {
					keep[ r ] = pal[ r ];
				}
			} );
			setDraft( core.generate( { ...opts, locked: keep } ) );
		};
		const apply = () => {
			// Les couleurs hors rôles restent : celles des styles en cours, sinon celles du site.
			const known = core.ROLES.concat( core.OPTIONAL, [ 'border' ] );
			const edited = color.palette && color.palette.theme;
			const extras = ( edited || data.extras || [] ).filter( ( c ) => c && 'string' === typeof c.slug && ! known.includes( c.slug ) );
			const theme = core.ROLES.concat( Object.keys( optional ) ).map( ( r ) => ( { slug: r, name: data.names[ r ] || r, color: pal[ r ] } ) ).concat( extras );
			editEntityRecord( 'root', 'globalStyles', id, { settings: { ...settings, color: { ...color, palette: { ...( color.palette || {} ), theme } } } } );
			setDraft( null );
		};

		const select = ( key ) =>
			el( SelectControl, {
				key,
				label: data.options[ key ].label,
				value: opts[ key ],
				options: Object.entries( data.options[ key ].choices ).map( ( [ value, label ] ) => ( { value, label } ) ),
				onChange: ( value ) => setOpts( { ...opts, [ key ]: value } ),
				__nextHasNoMarginBottom: true,
			} );

		return el(
			PanelBody,
			{ className: 'fl-palette-panel' },
			[ 'mood', 'strict', 'harmony' ].map( select ),
			el( Button, { variant: 'secondary', onClick: run }, t.generate ),
			el(
				'ul',
				{ className: 'fl-palette-panel__roles' },
				core.ROLES.map( ( r ) =>
					el(
						'li',
						{ key: r },
						el( ColorIndicator, { colorValue: pal[ r ] } ),
						el( CheckboxControl, {
							label: data.roles[ r ] + ' ' + pal[ r ],
							help: null,
							checked: !! locked[ r ],
							onChange: ( on ) => setLocked( { ...locked, [ r ]: on } ),
							__nextHasNoMarginBottom: true,
						} )
					)
				)
			),
			el( 'p', { className: 'components-base-control__help' }, t.keep ),
			el( 'p', { 'aria-live': 'polite' }, fill( t.score, core.score( pal ) ) ),
			bad.length ? el( Notice, { status: 'warning', isDismissible: false }, fill( t.bad, bad.join( ', ' ) ) ) : el( 'p', null, t.ok ),
			el( Button, { variant: 'primary', onClick: apply, disabled: ! draft || ! id || bad.length > 0 }, t.apply ),
			el( 'p', { className: 'components-base-control__help' }, t.save ),
			el( ExternalLink, { href: data.screen }, t.screen )
		);
	}

	wp.plugins.registerPlugin( 'fluffless-palette', {
		render: () => el( Sidebar, { name: 'fluffless-palette', title: t.title, icon: 'art' }, el( Panel ) ),
	} );
}( window.wp, window.flPalette ) );
