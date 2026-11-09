/**
 * Panneau Polices de Fluffless Suite, dans l'éditeur de site. JavaScript natif, sans étape de build.
 * « Appliquer ce duo » installe les familles côté serveur (route fluffless/v1/fonts/pair), puis les
 * écrit dans les styles globaux en cours d'édition ; le bouton Enregistrer de l'éditeur les garde.
 * Même logique que Fonts::apply_pair() et Fonts::reset(). Données et textes : window.flufflessFonts.
 */
( function ( wp ) {
	const data = window.flufflessFonts;
	const Sidebar = ( wp.editor && wp.editor.PluginSidebar ) || ( wp.editSite && wp.editSite.PluginSidebar );
	if ( ! data || ! wp.plugins || ! wp.apiFetch || ! Sidebar ) {
		return;
	}
	const el = wp.element.createElement;
	const { useState } = wp.element;
	const { useSelect, useDispatch } = wp.data;
	const { PanelBody, Button, Notice, ExternalLink } = wp.components;
	const t = data.i18n;
	const PRESET = 'var:preset|font-family|';
	const preset = ( slug ) => PRESET + slug;
	const fromCollection = ( value ) => 'string' === typeof value && value.startsWith( PRESET ) && data.collection.includes( value.slice( PRESET.length ) );
	const fill = ( text, ...args ) => {
		let n = 0;
		return String( text || '' ).replace( /%(\d\$)?[sd]/g, ( m, pos ) => String( args[ pos ? parseInt( pos, 10 ) - 1 : n++ ] ) );
	};
	const get = ( obj, path ) => path.reduce( ( node, key ) => ( node && 'object' === typeof node ? node[ key ] : undefined ), obj );
	// Copie avec une valeur posée ; null retire la clé et les parents devenus vides (GlobalStyles::put()).
	const put = ( obj, path, value ) => {
		const [ key, ...rest ] = path;
		const copy = { ...( obj && 'object' === typeof obj ? obj : {} ) };
		const next = rest.length ? put( copy[ key ], rest, value ) : value;
		if ( null === next || undefined === next || ( rest.length && ! Object.keys( next ).length ) ) {
			delete copy[ key ];
		} else {
			copy[ key ] = next;
		}
		return copy;
	};
	const CUSTOM = [ 'settings', 'typography', 'fontFamilies', 'custom' ];
	const HEADING = [ 'styles', 'elements', 'heading', 'typography', 'fontFamily' ];
	const BODY = [ 'styles', 'typography', 'fontFamily' ];

	// Familles d'autres sources gardées, celles de la collection remplacées par celles du duo.
	const families = ( record, added ) => {
		const list = ( get( record, CUSTOM ) || [] ).filter( ( f ) => ! ( f && data.collection.includes( f.slug ) ) ).concat( added );
		return list.length ? list : null;
	};

	function Panel() {
		const { id, record } = useSelect( ( select ) => {
			const store = select( 'core' );
			const gid = store.__experimentalGetCurrentGlobalStylesId();
			return { id: gid, record: gid ? store.getEditedEntityRecord( 'root', 'globalStyles', gid ) : null };
		}, [] );
		const { editEntityRecord } = useDispatch( 'core' );
		const [ busy, setBusy ] = useState( null );
		const [ error, setError ] = useState( null );
		const [ backup, setBackup ] = useState( data.backup );
		const [ open, setOpen ] = useState( null );

		const heading = get( record, HEADING );
		const body = get( record, BODY );
		const current = data.pairs.findIndex( ( p ) => preset( p.heading ) === heading && ( 'system' === p.body ? ! fromCollection( body ) : preset( p.body ) === body ) );

		const write = ( next ) => editEntityRecord( 'root', 'globalStyles', id, { settings: next.settings || {}, styles: next.styles || {} } );
		const apply = ( index ) => {
			const pair = data.pairs[ index ];
			setBusy( index );
			setError( null );
			return wp
				.apiFetch( { path: '/fluffless/v1/fonts/pair', method: 'POST', data: { pair: index } } )
				.then( ( res ) => {
					let next = put( record, CUSTOM, families( record, res.families ) );
					next = put( next, HEADING, preset( pair.heading ) );
					next = put( next, BODY, 'system' === pair.body ? ( res.backup && res.backup.body ) || null : preset( pair.body ) );
					write( next );
					setBackup( res.backup );
				} )
				.catch( ( err ) => setError( ( err && err.message ) || String( err ) ) )
				.finally( () => setBusy( null ) );
		};
		const reset = () => {
			let next = put( record, CUSTOM, families( record, [] ) );
			[ [ 'heading', HEADING ], [ 'body', BODY ] ].forEach( ( [ key, path ] ) => {
				// Sans copie d'origine, seule une police de la collection est retirée.
				const value = get( next, path );
				next = put( next, path, backup ? backup[ key ] || null : fromCollection( value ) ? null : value );
			} );
			write( next );
		};

		return el(
			PanelBody,
			{ className: 'fl-fonts-panel' },
			el( 'p', null, t.intro ),
			error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
			el(
				'ul',
				{ className: 'fl-fonts-panel__pairs' },
				data.pairs.map( ( p, i ) =>
					el(
						'li',
						{ key: p.heading + '/' + p.body },
						el( 'strong', null, fill( t.pair, p.names[ 0 ], p.names[ 1 ] ) ),
						// Titre décoratif (le nom est écrit juste au-dessus) ; paragraphe chargé sur demande, nommé une fois déplié.
						p.title ? el( 'img', { src: p.title, alt: '', loading: 'lazy', decoding: 'async' } ) : null,
						el( 'p', { className: 'components-base-control__help' }, p.weight + ' · ' + p.note ),
						p.text ? el( Button, { variant: 'link', 'aria-expanded': open === i, onClick: () => setOpen( open === i ? null : i ) }, open === i ? t.hide : t.show ) : null,
						p.text && open === i ? el( 'img', { src: p.text, alt: fill( t.sample, p.names[ 1 ] ), decoding: 'async' } ) : null,
						current === i
							? el( 'p', null, t.current )
							: el( Button, { variant: 'secondary', onClick: () => apply( i ), disabled: ! id || null !== busy, isBusy: busy === i }, busy === i ? t.busy : t.apply )
					)
				)
			),
			fromCollection( heading ) || fromCollection( body ) ? el( Button, { variant: 'tertiary', onClick: reset, disabled: ! id || null !== busy }, t.reset ) : null,
			el( 'p', { className: 'components-base-control__help', 'aria-live': 'polite' }, t.save ),
			el( ExternalLink, { href: data.screen }, t.screen )
		);
	}

	wp.plugins.registerPlugin( 'fluffless-fonts', {
		render: () => el( Sidebar, { name: 'fluffless-fonts', title: t.title, icon: 'editor-textcolor' }, el( Panel ) ),
	} );
}( window.wp ) );
