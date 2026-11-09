/**
 * Panneau « Photos Pexels » de Fluffless Suite. JavaScript natif, sans build, éditeur seulement.
 *
 * Le navigateur ne parle qu'au serveur WordPress (routes fluffless/v1/pexels/*, nonce REST
 * ajouté par apiFetch) : la clé d'API reste sur le serveur. Seules les vignettes viennent
 * de images.pexels.com, dans l'éditeur ; les visiteurs ne contactent jamais Pexels.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { useState } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editor;
	const { Button, ExternalLink, Notice, PanelBody, SearchControl, Spinner } = wp.components;
	const { select, dispatch } = wp.data;
	const apiFetch = wp.apiFetch;
	const { addQueryArgs } = wp.url;

	const config = window.flufflessPexels || {};
	const NAME = 'fluffless-pexels';

	function errorText( error ) {
		return ( error && error.message ) || __( 'Something went wrong. Try again.', 'fluffless-suite' );
	}

	function Panel() {
		const [ query, setQuery ] = useState( '' );
		const [ page, setPage ] = useState( 1 );
		const [ result, setResult ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const [ importing, setImporting ] = useState( 0 );
		const [ error, setError ] = useState( '' );
		const [ done, setDone ] = useState( '' );

		if ( ! config.hasKey ) {
			return el(
				Notice,
				{ status: 'warning', isDismissible: false },
				__( 'Pexels is not set up on this site yet. Ask the person who manages your hosting to add the free key (FLUFFLESS_PEXELS_KEY) to wp-config.php.', 'fluffless-suite' ),
				' ',
				el( ExternalLink, { href: config.keyHelp }, __( 'Get a key', 'fluffless-suite' ) )
			);
		}

		function search( nextPage ) {
			if ( ! query.trim() ) {
				return;
			}
			setBusy( true );
			setError( '' );
			setDone( '' );
			apiFetch( { path: addQueryArgs( '/fluffless/v1/pexels/search', { query: query.trim(), page: nextPage } ) } )
				.then( ( data ) => {
					setResult( data );
					setPage( nextPage );
				} )
				.catch( ( e ) => setError( errorText( e ) ) )
				.finally( () => setBusy( false ) );
		}

		function insert( photo ) {
			setImporting( photo.id );
			setError( '' );
			setDone( '' );
			const postId = select( 'core/editor' ).getCurrentPostId() || 0;
			apiFetch( { path: '/fluffless/v1/pexels/import', method: 'POST', data: { id: photo.id, post_id: postId } } )
				.then( ( media ) => {
					const block = wp.blocks.createBlock( 'core/image', {
						id: media.id,
						url: media.url,
						alt: media.alt,
						caption: media.caption,
					} );
					dispatch( 'core/block-editor' ).insertBlocks( block );
					setDone( __( 'Photo added. Check its alternative text: it comes from Pexels and may not describe your use.', 'fluffless-suite' ) );
				} )
				.catch( ( e ) => setError( errorText( e ) ) )
				.finally( () => setImporting( 0 ) );
		}

		const photos = ( result && result.photos ) || [];
		const lastPage = result ? Math.ceil( result.total / 15 ) : 1;

		return el(
			'div',
			null,
			el(
				'form',
				{
					onSubmit: ( event ) => {
						event.preventDefault();
						search( 1 );
					},
				},
				el( SearchControl, {
					__nextHasNoMarginBottom: true,
					label: __( 'Search a Pexels photo', 'fluffless-suite' ),
					value: query,
					onChange: setQuery,
				} ),
				el( Button, { variant: 'primary', type: 'submit', disabled: busy || ! query.trim(), style: { marginTop: '8px', minHeight: '44px' } }, __( 'Search', 'fluffless-suite' ) )
			),
			busy && el( Spinner ),
			error && el( Notice, { status: 'error', isDismissible: false }, error ),
			done && el( Notice, { status: 'success', isDismissible: false }, done ),
			result && ! photos.length && el( 'p', null, __( 'No photo found. Try other words.', 'fluffless-suite' ) ),
			photos.length > 0 &&
				el(
					'ul',
					{ style: { listStyle: 'none', margin: '12px 0', padding: 0, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px' } },
					photos.map( ( photo ) =>
						el(
							'li',
							{ key: photo.id },
							el(
								'button',
								{
									type: 'button',
									className: 'components-button',
									style: { display: 'block', width: '100%', height: 'auto', padding: 0, minHeight: '44px' },
									disabled: importing !== 0,
									onClick: () => insert( photo ),
									'aria-label': sprintf(
										/* translators: 1: photo description; 2: photographer name */
										__( 'Insert: %1$s, by %2$s', 'fluffless-suite' ),
										photo.alt || __( 'photo', 'fluffless-suite' ),
										photo.photographer
									),
								},
								el( 'img', { src: photo.thumb, alt: '', loading: 'lazy', style: { display: 'block', width: '100%', height: 'auto' } } )
							),
							el( 'small', null, importing === photo.id ? __( 'Importing…', 'fluffless-suite' ) : photo.photographer )
						)
					)
				),
			result &&
				lastPage > 1 &&
				el(
					'div',
					{ style: { display: 'flex', gap: '8px', justifyContent: 'space-between' } },
					el( Button, { variant: 'secondary', disabled: busy || page <= 1, onClick: () => search( page - 1 ) }, __( 'Previous', 'fluffless-suite' ) ),
					el( Button, { variant: 'secondary', disabled: busy || page >= Math.min( lastPage, 20 ), onClick: () => search( page + 1 ) }, __( 'Next', 'fluffless-suite' ) )
				),
			el( 'p', null, el( ExternalLink, { href: 'https://www.pexels.com' }, __( 'Photos provided by Pexels', 'fluffless-suite' ) ) )
		);
	}

	registerPlugin( NAME, {
		icon: 'format-image',
		render: () =>
			el(
				wp.element.Fragment,
				null,
				el( PluginSidebarMoreMenuItem, { target: NAME, icon: 'format-image' }, __( 'Pexels photos', 'fluffless-suite' ) ),
				el( PluginSidebar, { name: NAME, title: __( 'Pexels photos', 'fluffless-suite' ), icon: 'format-image' }, el( PanelBody, null, el( Panel ) ) )
			),
	} );
} )( window.wp );
