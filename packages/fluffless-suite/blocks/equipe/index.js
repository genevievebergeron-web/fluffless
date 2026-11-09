/**
 * Éditeur du bloc fluffless/equipe. JavaScript natif, sans étape de build.
 * Un titre, un texte, puis un groupe en grille native : un groupe par personne (photo, nom en
 * titre de niveau 3, rôle, bio, lien LinkedIn natif). Rôle, bio et lien sont repérés par leur
 * classe ; un lien LinkedIn sans adresse n'est pas affiché sur le site (comportement du cœur).
 */
( function ( wp, S ) {
	const { __ } = wp.i18n;
	const NONE = [];
	const PHOTO = 'fluffless-equipe__photo';
	const ROLE = 'fluffless-equipe__role';
	const BIO = 'fluffless-equipe__bio';
	const LINKEDIN = 'fluffless-equipe__linkedin';
	const ROLE_TEXT = () => __( 'Role, in plain words', 'fluffless-suite' );
	const BIO_TEXT = () => __( 'One thing this person does that nobody else on the team does.', 'fluffless-suite' );

	const role = () => S.createBlock( 'core/paragraph', { className: ROLE, placeholder: ROLE_TEXT() } );
	const bio = () => S.createBlock( 'core/paragraph', { className: BIO, placeholder: BIO_TEXT() } );
	const linkedin = () => S.createBlock( 'core/social-links', { className: LINKEDIN }, [ S.createBlock( 'core/social-link', { service: 'linkedin' } ) ] );

	const ITEM = [
		'core/group',
		{ metadata: { name: __( 'Person', 'fluffless-suite' ) } },
		[
			[ 'core/image', { className: PHOTO, sizeSlug: 'medium' } ],
			[ 'core/heading', { level: 3, placeholder: __( 'First and last name', 'fluffless-suite' ) } ],
			[ 'core/paragraph', { className: ROLE, placeholder: ROLE_TEXT() } ],
			[ 'core/paragraph', { className: BIO, placeholder: BIO_TEXT() } ],
		],
	];
	const TEMPLATE = [
		[ 'core/heading', { placeholder: __( 'The people behind the work', 'fluffless-suite' ) } ],
		[ 'core/paragraph', { placeholder: __( 'Why this team, for these clients? One or two sentences.', 'fluffless-suite' ) } ],
		[ 'core/group', { layout: { type: 'grid', columnCount: 3, minimumColumnWidth: '12rem' } }, [ ITEM, ITEM, ITEM ] ],
	];

	// Rang d'insertion : juste après le dernier bloc présent parmi ceux qui précèdent.
	const after = ( id, tests ) => {
		const list = S.children( id );
		const last = list.reduce( ( found, block, i ) => ( tests.some( ( t ) => t( block ) ) ? i : found ), -1 );
		return last + 1;
	};

	wp.blocks.registerBlockType( 'fluffless/equipe', {
		edit( props ) {
			const { attributes, setAttributes, clientId } = props;
			const state = wp.data.useSelect(
				( s ) => {
					const be = s( 'core/block-editor' );
					const grid = be.getBlocks( clientId ).find( S.isBlock( 'core/group' ) );
					const list = grid ? be.getBlocks( grid.clientId ) : NONE;
					const first = list[ 0 ] ? be.getBlocks( list[ 0 ].clientId ) : NONE;
					return {
						grid,
						list,
						role: first.some( S.hasClass( ROLE ) ),
						bio: first.some( S.hasClass( BIO ) ),
						linkedin: first.some( S.hasClass( LINKEDIN ) ),
					};
				},
				[ clientId ]
			);
			const columns = String( ( state.grid && state.grid.attributes.layout && state.grid.attributes.layout.columnCount ) || 3 );
			const heading = S.isBlock( 'core/heading' );

			const setColumns = ( value ) =>
				state.grid &&
				wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( state.grid.clientId, {
					layout: Object.assign( {}, state.grid.attributes.layout, { columnCount: parseInt( value, 10 ) } ),
				} );
			const each = ( test, on, make, where ) =>
				state.list.forEach( ( item ) => S.setChild( item.clientId, test, on, make, where ? after( item.clientId, where ) : undefined ) );

			return S.shell( 'equipe', props, {
				keys: [ 'photo' ],
				template: TEMPLATE,
				title: __( 'Team', 'fluffless-suite' ),
				controls: [
					S.choice(
						__( 'Number of columns', 'fluffless-suite' ),
						columns,
						[ '2', '3', '4' ].map( ( n ) => ( { value: n, label: n } ) ),
						setColumns,
						__( 'To add a person, duplicate a card.', 'fluffless-suite' )
					),
					S.choice(
						__( 'Photo', 'fluffless-suite' ),
						attributes.photo,
						[
							{ value: 'round', label: __( 'Round', 'fluffless-suite' ) },
							{ value: 'square', label: __( 'Square', 'fluffless-suite' ) },
						],
						( value ) => setAttributes( { photo: value } ),
						__( 'Describe each photo in its alternative text, with the person’s name.', 'fluffless-suite' )
					),
					S.toggle( __( 'Role', 'fluffless-suite' ), state.role, ( on ) => each( S.hasClass( ROLE ), on, role, [ heading ] ) ),
					S.toggle( __( 'Short bio', 'fluffless-suite' ), state.bio, ( on ) => each( S.hasClass( BIO ), on, bio, [ heading, S.hasClass( ROLE ) ] ) ),
					S.toggle(
						__( 'LinkedIn link', 'fluffless-suite' ),
						state.linkedin,
						( on ) => each( S.hasClass( LINKEDIN ), on, linkedin ),
						__( 'A link without an address stays hidden on the site. Give each link a label with the person’s name.', 'fluffless-suite' )
					),
				],
			} );
		},
		save: S.save,
	} );
} )( window.wp, window.flufflessSections );
