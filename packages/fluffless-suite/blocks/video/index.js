/**
 * Éditeur du bloc fluffless/video. JavaScript natif, sans étape de build.
 * Adresse YouTube ou Vimeo ou fichier vidéo de la médiathèque (le fichier passe avant le lien),
 * affiche, titre et format dans le panneau ; aperçu de la façade dans le canevas, blocs libres
 * dessous (légende). Rendu du site : Sections::render() et Sections::video_facade() ; le lecteur
 * des plateformes vit dans view.js, le fichier hébergé joue dans le lecteur du navigateur.
 */
( function ( wp, S ) {
	const { __, sprintf } = wp.i18n;
	const { TextControl, Button } = wp.components;
	const { MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const el = S.el;
	const PROVIDERS = [
		{ label: 'YouTube', pattern: /^https?:\/\/(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{6,20})/ },
		{ label: 'Vimeo', pattern: /^https?:\/\/(?:www\.)?(?:vimeo\.com\/(?:video\/)?|player\.vimeo\.com\/video\/)(\d{5,12})/ },
	];
	const provider = ( url ) => PROVIDERS.find( ( p ) => p.pattern.test( ( url || '' ).trim() ) );
	const icons = window.flufflessIcones && window.flufflessIcones.icons;
	const play = () =>
		icons && icons.play
			? el( wp.element.RawHTML, null, '<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + icons.play + '</svg>' )
			: '▶';

	wp.blocks.registerBlockType( 'fluffless/video', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			const media = wp.data.useSelect(
				( s ) => ( attributes.posterId ? s( 'core' ).getMedia( attributes.posterId ) : null ),
				[ attributes.posterId ]
			);
			const clip = wp.data.useSelect(
				( s ) => ( attributes.videoId ? s( 'core' ).getMedia( attributes.videoId ) : null ),
				[ attributes.videoId ]
			);
			const hosted = attributes.videoId > 0;
			const found = provider( attributes.url );
			const help = hosted
				? __( 'A video file from the media library is chosen: it plays instead of this link.', 'fluffless-suite' )
				: attributes.url
					? found
						/* translators: %s: YouTube or Vimeo */
						? sprintf( __( '%s video recognized. Nothing loads from the platform before the click.', 'fluffless-suite' ), found.label )
						: __( 'Only YouTube and Vimeo links are recognized.', 'fluffless-suite' )
					: __( 'Paste the link of the video page, or choose a video file below.', 'fluffless-suite' );
			const facade = el(
				'div',
				{ className: 'fluffless-video__facade' },
				media ? el( 'img', { className: 'fluffless-video__poster', src: media.source_url, alt: '' } ) : null,
				el( 'span', { className: 'fluffless-video__play' }, play() )
			);
			return S.shell( 'video', props, {
				keys: [ 'ratio' ],
				title: __( 'Video', 'fluffless-suite' ),
				before: hosted || found ? facade : el( 'p', { className: 'fluffless-video__empty' }, __( 'Paste a YouTube or Vimeo link in the panel, or choose a video file.', 'fluffless-suite' ) ),
				controls: [
					el( TextControl, {
						label: __( 'Video link', 'fluffless-suite' ),
						value: attributes.url,
						help,
						onChange: ( url ) => setAttributes( { url } ),
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
					} ),
					el( TextControl, {
						label: __( 'Title', 'fluffless-suite' ),
						value: attributes.title,
						help: __( 'Read by screen readers and set on the player.', 'fluffless-suite' ),
						onChange: ( title ) => setAttributes( { title } ),
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
					} ),
					el(
						MediaUploadCheck,
						null,
						el( MediaUpload, {
							allowedTypes: [ 'image' ],
							value: attributes.posterId,
							onSelect: ( chosen ) => setAttributes( { posterId: chosen.id } ),
							render: ( { open } ) =>
								el(
									Button,
									{ variant: 'secondary', onClick: open, __next40pxDefaultSize: true },
									attributes.posterId ? __( 'Replace the poster', 'fluffless-suite' ) : __( 'Choose a poster', 'fluffless-suite' )
								),
						} )
					),
					attributes.posterId > 0 &&
						el( Button, { variant: 'link', isDestructive: true, onClick: () => setAttributes( { posterId: 0 } ) }, __( 'Remove the poster', 'fluffless-suite' ) ),
					el(
						MediaUploadCheck,
						null,
						el( MediaUpload, {
							allowedTypes: [ 'video' ],
							value: attributes.videoId,
							onSelect: ( chosen ) => setAttributes( { videoId: chosen.id } ),
							render: ( { open } ) =>
								el(
									Button,
									{ variant: 'secondary', onClick: open, __next40pxDefaultSize: true },
									hosted ? __( 'Replace the video file', 'fluffless-suite' ) : __( 'Choose a video file', 'fluffless-suite' )
								),
						} )
					),
					el(
						'p',
						{ className: 'components-base-control__help' },
						hosted && clip && clip.title
							/* translators: %s: file name */
							? sprintf( __( 'Hosted here: %s. It plays in the browser’s own player; nothing downloads before the click.', 'fluffless-suite' ), clip.title.rendered || clip.slug )
							: __( 'A file hosted here plays in the browser’s own player, with no platform. Encode it at 1080p at most before uploading.', 'fluffless-suite' )
					),
					hosted &&
						el( Button, { variant: 'link', isDestructive: true, onClick: () => setAttributes( { videoId: 0 } ) }, __( 'Remove the video file', 'fluffless-suite' ) ),
					S.choice(
						__( 'Format', 'fluffless-suite' ),
						attributes.ratio,
						[
							{ value: '16-9', label: '16:9' },
							{ value: '4-3', label: '4:3' },
							{ value: '1-1', label: '1:1' },
						],
						( next ) => setAttributes( { ratio: next } )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
