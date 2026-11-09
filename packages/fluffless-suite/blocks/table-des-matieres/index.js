/**
 * Éditeur du bloc fluffless/table-des-matieres. JavaScript natif, sans étape de build.
 * Un titre en paragraphe (jamais compté dans la table) ; l'aperçu lit les blocs Titre de la
 * publication ouverte. Rendu du site : Sections::render() et Sections::toc(), qui recalcule
 * la liste depuis le contenu ; Sections::heading_id() pose les ancres sur les titres.
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const el = S.el;
	const TEMPLATE = [ [ 'core/paragraph', { className: 'fluffless-table-des-matieres__title', placeholder: __( 'On this page', 'fluffless-suite' ) } ] ];
	const strip = ( html ) => ( html || '' ).replace( /<[^>]+>/g, '' ).trim();
	const headings = ( blocks, out ) => {
		blocks.forEach( ( block ) => {
			if ( 'fluffless/table-des-matieres' === block.name ) {
				return;
			}
			if ( 'core/heading' === block.name ) {
				const level = block.attributes.level || 2;
				const text = strip( block.attributes.content && block.attributes.content.toString ? block.attributes.content.toString() : block.attributes.content );
				if ( ( 2 === level || 3 === level ) && text ) {
					out.push( { level, text } );
				}
			}
			headings( block.innerBlocks || [], out );
		} );
		return out;
	};

	wp.blocks.registerBlockType( 'fluffless/table-des-matieres', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			const items = wp.data.useSelect( ( s ) => headings( s( 'core/block-editor' ).getBlocks(), [] ), [] );
			const deep = 'h2-h3' === attributes.levels;
			const list = [];
			items.forEach( ( item ) => {
				if ( 2 === item.level || ! list.length ) {
					list.push( { text: item.text, children: [] } );
				} else if ( deep ) {
					list[ list.length - 1 ].children.push( item.text );
				}
			} );
			const preview = list.length
				? el(
						'nav',
						{ className: 'fluffless-table-des-matieres__nav' },
						el(
							'ol',
							{ className: 'fluffless-table-des-matieres__list' },
							...list.map( ( item, i ) =>
								el(
									'li',
									{ key: i },
									el( 'a', { href: '#', onClick: ( e ) => e.preventDefault() }, item.text ),
									item.children.length ? el( 'ol', null, ...item.children.map( ( text, j ) => el( 'li', { key: j }, el( 'a', { href: '#', onClick: ( e ) => e.preventDefault() }, text ) ) ) ) : null
								)
							)
						)
				  )
				: el( 'p', { className: 'components-base-control__help' }, __( 'Add level 2 headings to the page: the list fills itself.', 'fluffless-suite' ) );
			return S.shell( 'table-des-matieres', props, {
				keys: [ 'levels', 'numbering' ],
				template: TEMPLATE,
				title: __( 'Table of contents', 'fluffless-suite' ),
				after: preview,
				controls: [
					S.choice(
						__( 'Headings listed', 'fluffless-suite' ),
						attributes.levels,
						[
							{ value: 'h2', label: __( 'Level 2', 'fluffless-suite' ) },
							{ value: 'h2-h3', label: __( 'Levels 2 and 3', 'fluffless-suite' ) },
						],
						( next ) => setAttributes( { levels: next } )
					),
					S.toggle( __( 'Number the entries', 'fluffless-suite' ), 'numbered' === attributes.numbering, ( on ) => setAttributes( { numbering: on ? 'numbered' : 'plain' } ) ),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
