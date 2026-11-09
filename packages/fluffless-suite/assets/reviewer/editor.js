/**
 * Panneau « Réviseurs » de Fluffless Suite. JavaScript natif, sans build.
 *
 * Coche les comptes Réviseur confiés sur cette page ou cet article ; enregistre la liste dans
 * la méta _fluffless_reviewers avec le reste de l'article. Visible seulement pour un compte qui
 * a edit_others_posts (voir Reviewer::editor_assets()) : jamais pour le rôle Réviseur lui-même.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editor;
	const { CheckboxControl } = wp.components;
	const { useSelect, useDispatch } = wp.data;

	const config = window.flufflessReviewer || {};
	const reviewers = Array.isArray( config.reviewers ) ? config.reviewers : [];
	const metaKey = config.metaKey || '_fluffless_reviewers';

	function ReviewersPanel() {
		const assigned = useSelect( ( sel ) => {
			const meta = sel( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
			return Array.isArray( meta[ metaKey ] ) ? meta[ metaKey ] : [];
		}, [] );
		const { editPost } = useDispatch( 'core/editor' );

		function toggle( id, checked ) {
			const next = checked
				? [ ...assigned, id ].filter( ( value, index, all ) => all.indexOf( value ) === index )
				: assigned.filter( ( value ) => value !== id );
			editPost( { meta: { [ metaKey ]: next } } );
		}

		const children = reviewers.length
			? reviewers.map( ( reviewer ) =>
				el( CheckboxControl, {
					key: reviewer.id,
					label: reviewer.name,
					checked: assigned.includes( reviewer.id ),
					onChange: ( checked ) => toggle( reviewer.id, checked ),
					__nextHasNoMarginBottom: true,
				} )
			)
			: [ el( 'p', { key: 'empty' }, __( 'No Reviewer account yet. Add one under Users → Add User with the Reviewer role, then come back to assign this page.', 'fluffless-suite' ) ) ];

		return el(
			PluginDocumentSettingPanel,
			{ name: 'fluffless-reviewers', title: __( 'Reviewers', 'fluffless-suite' ) },
			children
		);
	}

	registerPlugin( 'fluffless-reviewers', { render: ReviewersPanel } );
} )( window.wp );
