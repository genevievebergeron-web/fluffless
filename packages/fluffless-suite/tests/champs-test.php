<?php
/**
 * Tests du module Champs personnalisés : types et champs assainis, préréglage « Membre d'équipe »,
 * type et métadonnées enregistrés pour l'éditeur, source de liaison fluffless/champ (natif, puis
 * ACF, puis Meta Box ; image, courriel et texte multiligne adaptés à l'attribut), rendu de blocs liés
 * avec le contexte du contenu, fiche de boucle listée, gabarits enregistrés, boîte de saisie,
 * lecture du formulaire, écran pour un administrateur seulement, données de l'éditeur.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/champs-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Modules\Fields;
use Fluffless\Suite\Modules\Loops;

defined( 'ABSPATH' ) || exit;

// Les contrôles lisent les libellés en en_US (« Role ») : indépendants de la langue du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// Doublures d'ACF et de Meta Box quand les extensions ne sont pas installées.
if ( ! function_exists( 'get_field' ) ) {
	/**
	 * Doublure d'ACF : un champ texte et un champ image.
	 *
	 * @param string $key     Clé.
	 * @param int    $post_id Contenu.
	 * @return mixed
	 */
	function get_field( string $key, int $post_id = 0 ) { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- doublure d'ACF.
		if ( 'acf_texte' === $key ) {
			return 'Depuis ACF';
		}
		if ( 'acf_image' === $key ) {
			return array( 'ID' => 2424 );
		}
		return false;
	}
}
if ( ! function_exists( 'rwmb_meta' ) ) {
	/**
	 * Doublure de Meta Box.
	 *
	 * @param string       $key     Clé.
	 * @param array<mixed> $args    Options.
	 * @param int          $post_id Contenu.
	 * @return mixed
	 */
	function rwmb_meta( string $key, array $args = array(), int $post_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- doublure de Meta Box.
		return 'mb_texte' === $key ? 'Depuis Meta Box' : null;
	}
}

$fluffless_image = get_posts(
	array(
		'post_type'   => 'attachment',
		'post_status' => 'inherit',
		'numberposts' => 1,
		'fields'      => 'ids',
		'post__in'    => array( 2424 ),
	)
);
$fluffless_image = array() !== $fluffless_image ? (int) $fluffless_image[0] : 0;

$fluffless_saved = get_option( Lifecycle::OPTION_CHAMPS, null );
$fluffless_id    = 0;
$fluffless_user  = get_current_user_id();
$fluffless_reset = static function () use ( &$fluffless_id, $fluffless_saved, $fluffless_user ): void {
	if ( (int) $fluffless_id > 0 ) { // @phpstan-ignore-line -- valeur posée plus tard par référence.
		wp_delete_post( $fluffless_id, true );
	}
	if ( null === $fluffless_saved ) {
		delete_option( Lifecycle::OPTION_CHAMPS );
	} else {
		update_option( Lifecycle::OPTION_CHAMPS, $fluffless_saved, false );
	}
	wp_set_current_user( $fluffless_user );
};

try {
	// 1. Assainissement : slugs réservés ou mal formés, clés mal formées, sortes inconnues, bornes.
	$fluffless_raw = array(
		'post'      => array( 'label' => 'Réservé' ),
		'Majuscule' => array( 'label' => 'Mal formé' ),
		'sans-nom'  => array( 'singular' => 'Sans pluriel' ),
		'projet'    => array(
			'label'  => '<b>Projets</b>',
			'fields' => array(
				'client'       => array(
					'label' => 'Client',
					'kind'  => 'text',
				),
				'Mauvaise clé' => array( 'label' => 'x' ),
				'sorte'        => array(
					'label' => '',
					'kind'  => 'inconnue',
				),
				7              => array( 'label' => 'numérique' ),
			),
		),
	);
	for ( $fluffless_i = 0; $fluffless_i < 25; $fluffless_i++ ) {
		$fluffless_raw[ 'type' . $fluffless_i ] = array( 'label' => 'Type ' . $fluffless_i );
	}
	$fluffless_types = Fields::sanitize_types( $fluffless_raw );
	$fluffless_assert( ! isset( $fluffless_types['post'], $fluffless_types['Majuscule'], $fluffless_types['sans-nom'] ), 'slug réservé, mal formé ou sans libellé accepté' );
	$fluffless_assert( isset( $fluffless_types['projet'] ) && 'Projets' === $fluffless_types['projet']['label'] && 'Projets' === $fluffless_types['projet']['singular'], 'libellé non nettoyé ou singulier non déduit' );
	$fluffless_assert( array( 'client', 'sorte' ) === array_keys( $fluffless_types['projet']['fields'] ), 'clés de champ mal filtrées : ' . implode( ',', array_keys( $fluffless_types['projet']['fields'] ) ) );
	$fluffless_assert( 'text' === $fluffless_types['projet']['fields']['sorte']['kind'] && 'sorte' === $fluffless_types['projet']['fields']['sorte']['label'], 'sorte inconnue ou libellé vide non ramenés' );
	$fluffless_assert( Fields::MAX_TYPES === count( $fluffless_types ), 'borne des types : ' . count( $fluffless_types ) );
	$fluffless_assert( ! Fields::valid_slug( 'page' ) && ! Fields::valid_slug( '1abc' ) && Fields::valid_slug( 'membre' ), 'validation du slug' );

	// 2. Valeurs selon la sorte.
	$fluffless_assert( 'a@b.ca' === Fields::sanitize_value( ' a@b.ca ', 'email' ) && '' === Fields::sanitize_value( 'pas un courriel', 'email' ), 'courriel' );
	$fluffless_assert( 'https://example.com/x' === Fields::sanitize_value( 'https://example.com/x', 'url' ) && '' === Fields::sanitize_value( 'javascript:alert(1)', 'url' ), 'adresse' );
	$fluffless_assert( '2026-09-18' === Fields::sanitize_value( '2026-09-18', 'date' ) && '' === Fields::sanitize_value( '18/09/2026', 'date' ), 'date' );
	$fluffless_assert( 12 === Fields::sanitize_value( '12', 'image' ) && 0 === Fields::sanitize_value( '-3', 'image' ), 'image' );
	$fluffless_assert( "Ligne 1\nLigne 2" === Fields::sanitize_value( "Ligne 1\n<script>Ligne 2", 'multiline' ), 'texte multiligne' );
	$fluffless_assert( '3.5' === Fields::sanitize_value( '3,5abc', 'number' ) || '3' === Fields::sanitize_value( '3,5abc', 'number' ), 'nombre' );

	// 3. Préréglage Membre d'équipe posé, avec un champ image ajouté, puis type et métadonnées enregistrés.
	$fluffless_membre                    = Fields::preset();
	$fluffless_membre['fields']['photo'] = array(
		'label' => 'Photo',
		'kind'  => 'image',
	);
	update_option( Lifecycle::OPTION_CHAMPS, array( 'membre' => $fluffless_membre ), false );
	$fluffless_assert( array( 'poste', 'courriel', 'telephone', 'linkedin', 'bio', 'photo' ) === array_keys( Fields::types()['membre']['fields'] ), 'préréglage relu' );
	if ( ! has_action( 'init', array( Fields::class, 'register_types' ) ) ) {
		( new Fields() )->register();
	}
	Fields::register_types();
	Fields::register_source();
	Fields::register_templates();
	$fluffless_type = get_post_type_object( 'membre' );
	$fluffless_assert( null !== $fluffless_type && $fluffless_type->public && $fluffless_type->has_archive && $fluffless_type->show_in_rest && post_type_supports( 'membre', 'custom-fields' ), 'type membre mal enregistré' );
	$fluffless_meta = get_registered_meta_keys( 'post', 'membre' );
	$fluffless_assert( isset( $fluffless_meta['poste'], $fluffless_meta['bio'], $fluffless_meta['photo'] ) && $fluffless_meta['poste']['show_in_rest'] && 'Role' === $fluffless_meta['poste']['label'] && 'integer' === $fluffless_meta['photo']['type'], 'métadonnées non exposées à l\'éditeur' );
	$fluffless_assert( 'email' === Fields::kind( 'membre', 'courriel' ) && '' === Fields::kind( 'membre', 'acf_texte' ), 'sorte d\'un champ' );

	// 4. Source de liaison enregistrée.
	$fluffless_source = get_block_bindings_source( Fields::SOURCE );
	$fluffless_assert( null !== $fluffless_source && in_array( 'postId', (array) $fluffless_source->uses_context, true ), 'source fluffless/champ absente' );

	// 5. Valeurs de la source sur un membre : natif, ACF, Meta Box, image, courriel, téléphone, multiligne.
	$fluffless_id = (int) wp_insert_post(
		array(
			'post_title'   => 'Membre essai',
			'post_content' => '<!-- wp:paragraph --><p>Texte libre.</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
			'post_type'    => 'membre',
			'meta_input'   => array(
				'poste'     => 'Directrice <b>générale</b>',
				'courriel'  => 'membre@example.com',
				'telephone' => '514 555-0199',
				'linkedin'  => 'https://www.linkedin.com/in/essai',
				'bio'       => "Première ligne.\nDeuxième ligne.",
				'photo'     => $fluffless_image,
			),
		)
	);
	$fluffless_assert( $fluffless_id > 0, 'membre d\'essai non créé' );
	// Le cœur pose le contexte de la source sur le bloc au rendu (process_block_bindings) ; ici on le pose à la main.
	$fluffless_block          = new WP_Block(
		array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
	$fluffless_block->context = array(
		'postId'   => $fluffless_id,
		'postType' => 'membre',
	);
	$fluffless_assert( 'Directrice générale' === Fields::value( array( 'key' => 'poste' ), $fluffless_block, 'content' ), 'valeur native : ' . (string) wp_json_encode( Fields::value( array( 'key' => 'poste' ), $fluffless_block, 'content' ) ) );
	$fluffless_assert( 'Depuis ACF' === Fields::value( array( 'key' => 'acf_texte' ), $fluffless_block, 'content' ), 'valeur ACF' );
	$fluffless_assert( 'Depuis Meta Box' === Fields::value( array( 'key' => 'mb_texte' ), $fluffless_block, 'content' ), 'valeur Meta Box' );
	$fluffless_assert( null === Fields::value( array( 'key' => 'inconnu' ), $fluffless_block, 'content' ) && null === Fields::value( array( 'key' => 'Mauvaise clé' ), $fluffless_block, 'content' ), 'clé inconnue ou mal formée' );
	$fluffless_assert( 'mailto:membre@example.com' === Fields::value( array( 'key' => 'courriel' ), $fluffless_block, 'url' ), 'courriel en mailto:' );
	$fluffless_assert( 'tel:5145550199' === Fields::value( array( 'key' => 'telephone' ), $fluffless_block, 'url' ) && '514 555-0199' === Fields::value( array( 'key' => 'telephone' ), $fluffless_block, 'content' ), 'téléphone en tel:' );
	$fluffless_assert( 'https://www.linkedin.com/in/essai' === Fields::value( array( 'key' => 'linkedin' ), $fluffless_block, 'url' ), 'adresse' );
	$fluffless_assert( "Première ligne.<br />\nDeuxième ligne." === Fields::value( array( 'key' => 'bio' ), $fluffless_block, 'content' ), 'multiligne : ' . (string) wp_json_encode( Fields::value( array( 'key' => 'bio' ), $fluffless_block, 'content' ) ) );
	if ( $fluffless_image > 0 ) {
		$fluffless_url = Fields::value( array( 'key' => 'photo' ), $fluffless_block, 'url' );
		$fluffless_assert( is_string( $fluffless_url ) && str_starts_with( $fluffless_url, 'http' ) && Fields::value( array( 'key' => 'photo' ), $fluffless_block, 'id' ) === $fluffless_image, 'image native : url et id' );
		$fluffless_acf = Fields::value( array( 'key' => 'acf_image' ), $fluffless_block, 'url' );
		$fluffless_assert( is_string( $fluffless_acf ) && str_starts_with( $fluffless_acf, 'http' ), 'image ACF' );
	}

	// 6. Blocs liés rendus avec le contexte du contenu : paragraphe, bouton, image.
	$fluffless_markup = '<!-- wp:group --><div class="wp-block-group">'
		. '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"fluffless/champ","args":{"key":"poste"}}}}} --><p></p><!-- /wp:paragraph -->'
		. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"url":{"source":"fluffless/champ","args":{"key":"courriel"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Écrire</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
		. ( $fluffless_image > 0 ? '<!-- wp:image {"metadata":{"bindings":{"url":{"source":"fluffless/champ","args":{"key":"photo"}}}}} --><figure class="wp-block-image"><img src="" alt=""/></figure><!-- /wp:image -->' : '' )
		. '</div><!-- /wp:group -->';
	$fluffless_parsed = parse_blocks( $fluffless_markup );
	$fluffless_html   = ( new WP_Block(
		$fluffless_parsed[0],
		array(
			'postId'   => $fluffless_id,
			'postType' => 'membre',
		)
	) )->render();
	$fluffless_assert( 1 === preg_match( '/<p[^>]*>Directrice générale<\\/p>/', $fluffless_html ), 'paragraphe lié non rendu' );
	$fluffless_assert( str_contains( $fluffless_html, 'href="mailto:membre@example.com"' ), 'bouton lié non rendu' );
	if ( $fluffless_image > 0 ) {
		$fluffless_assert( 1 === preg_match( '/<img[^>]+src="http[^"]+"/', $fluffless_html ), 'image liée non rendue' );
	}

	// 7. Fiche de boucle « membre » de la Suite listée par Fiches de boucle, avec sa liaison.
	$fluffless_cards = Loops::cards();
	$fluffless_assert( isset( $fluffless_cards['membre'] ), 'fiche membre absente : ' . implode( ',', array_keys( $fluffless_cards ) ) );
	$fluffless_pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'fluffless/fiche-membre' );
	$fluffless_assert( is_array( $fluffless_pattern ) && str_contains( (string) $fluffless_pattern['content'], '"source":"fluffless/champ","args":{"key":"poste"}' ) && in_array( 'core/post-template', (array) $fluffless_pattern['blockTypes'], true ), 'composition fiche-membre' );

	// 8. Gabarits du type membre enregistrés, texte traduit, aucun autre gabarit sans le type.
	$fluffless_registry = WP_Block_Templates_Registry::get_instance();
	$fluffless_assert( $fluffless_registry->is_registered( 'fluffless-suite//single-membre' ) && $fluffless_registry->is_registered( 'fluffless-suite//archive-membre' ), 'gabarits membre absents' );
	$fluffless_single = $fluffless_registry->get_registered( 'fluffless-suite//single-membre' );
	$fluffless_assert( null !== $fluffless_single && str_contains( $fluffless_single->content, 'Send an email' ) && ! str_contains( $fluffless_single->content, '[[email]]' ) && array( 'membre' ) === $fluffless_single->post_types, 'gabarit single-membre' );
	$fluffless_archive = $fluffless_registry->get_registered( 'fluffless-suite//archive-membre' );
	$fluffless_assert( null !== $fluffless_archive && str_contains( $fluffless_archive->content, '"slug":"fluffless/fiche-membre"' ), 'gabarit archive-membre' );

	// 9. Boîte de saisie : rendu par sorte et enregistrement avec nonce.
	$fluffless_post = get_post( $fluffless_id );
	if ( ! $fluffless_post instanceof WP_Post ) {
		throw new RuntimeException( 'membre d\'essai introuvable' );
	}
	ob_start();
	Fields::render_box( $fluffless_post );
	$fluffless_box = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_box, 'name="fluffless_champ[poste]"' ) && str_contains( $fluffless_box, '<textarea id="fl-champ-bio"' ) && str_contains( $fluffless_box, 'type="email"' ) && str_contains( $fluffless_box, 'type="url"' ) && str_contains( $fluffless_box, 'name="' . Fields::NONCE . '"' ), 'boîte de saisie' );
	$fluffless_admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	if ( array() !== $fluffless_admins ) {
		wp_set_current_user( (int) $fluffless_admins[0] );
		$_POST = array(
			Fields::NONCE     => wp_create_nonce( Fields::NONCE ),
			'fluffless_champ' => array(
				'poste'    => 'Nouveau poste',
				'courriel' => 'pas un courriel',
				'linkedin' => 'https://www.linkedin.com/in/autre',
				'inconnu'  => 'ignoré',
			),
		);
		Fields::save_box( $fluffless_id, $fluffless_post );
		$_POST = array();
		$fluffless_assert( 'Nouveau poste' === get_post_meta( $fluffless_id, 'poste', true ) && '' === get_post_meta( $fluffless_id, 'courriel', true ) && 'https://www.linkedin.com/in/autre' === get_post_meta( $fluffless_id, 'linkedin', true ) && '' === get_post_meta( $fluffless_id, 'inconnu', true ), 'enregistrement de la boîte' );
		$_POST = array( 'fluffless_champ' => array( 'poste' => 'Sans nonce' ) );
		Fields::save_box( $fluffless_id, $fluffless_post );
		$_POST = array();
		$fluffless_assert( 'Nouveau poste' === get_post_meta( $fluffless_id, 'poste', true ), 'enregistrement sans nonce' );
	}

	// 10. Lecture du formulaire de l'écran : ligne retirée, type retiré, clés normalisées.
	$fluffless_form = Fields::from_form(
		array(
			'membre' => array(
				'label'  => 'Équipe',
				'fields' => array(
					array(
						'key'   => 'poste',
						'label' => 'Poste',
						'kind'  => 'text',
					),
					array(
						'key'    => 'bio',
						'remove' => '1',
					),
					array(
						'key'   => 'Site Web',
						'label' => 'Site',
						'kind'  => 'url',
					),
					array( 'key' => '' ),
				),
			),
			'projet' => array(
				'label'  => 'Projets',
				'remove' => '1',
			),
		)
	);
	$fluffless_assert( isset( $fluffless_form['membre'] ) && ! isset( $fluffless_form['projet'] ) && array( 'poste', 'siteweb' ) === array_keys( $fluffless_form['membre']['fields'] ), 'lecture du formulaire : ' . wp_json_encode( $fluffless_form ) );

	// 11. Écran : rien sans capacité ; pour un administrateur, tableau du type et bouton du préréglage absent quand membre existe.
	wp_set_current_user( 0 );
	ob_start();
	Fields::render();
	$fluffless_anon = (string) ob_get_clean();
	$fluffless_assert( '' === $fluffless_anon, 'écran rendu sans capacité' );
	if ( array() !== $fluffless_admins ) {
		wp_set_current_user( (int) $fluffless_admins[0] );
		ob_start();
		Fields::render();
		$fluffless_screen = (string) ob_get_clean();
		$fluffless_assert( str_contains( $fluffless_screen, 'name="type[membre][fields][0][key]"' ) && str_contains( $fluffless_screen, 'value="poste"' ) && str_contains( $fluffless_screen, 'name="new[slug]"' ) && ! str_contains( $fluffless_screen, 'value="' . Fields::PRESET . '"' ), 'écran avec le type membre' );
		$fluffless_assert( str_contains( $fluffless_screen, 'name="_wpnonce"' ) && str_contains( $fluffless_screen, 'value="' . Fields::ACTION . '"' ), 'nonce ou action absents' );
		delete_option( Lifecycle::OPTION_CHAMPS );
		ob_start();
		Fields::render();
		$fluffless_screen = (string) ob_get_clean();
		$fluffless_assert( str_contains( $fluffless_screen, 'value="' . Fields::PRESET . '"' ), 'bouton du préréglage absent sans type membre' );
		update_option( Lifecycle::OPTION_CHAMPS, array( 'membre' => $fluffless_membre ), false );
	}

	// 12. Données de l'éditeur : script, types et champs transmis, traductions.
	Fields::editor_assets();
	$fluffless_before = wp_scripts()->get_data( 'fluffless-champs-editor', 'before' );
	$fluffless_before = is_array( $fluffless_before ) ? implode( "\n", array_filter( $fluffless_before, 'is_string' ) ) : '';
	$fluffless_assert( wp_script_is( 'fluffless-champs-editor', 'enqueued' ) && str_contains( $fluffless_before, '"key":"poste"' ) && str_contains( $fluffless_before, '"native":true' ), 'données de l\'éditeur' );
	$fluffless_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/champs-editor.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( str_contains( $fluffless_js, 'registerBlockBindingsSource' ) && str_contains( $fluffless_js, "addFilter( 'editor.BlockEdit'" ) && str_contains( $fluffless_js, "'core/post-meta'" ), 'script d\'éditeur' );
	$fluffless_assert( array() === Fields::external_fields( 'membre' ) || function_exists( 'acf_get_field_groups' ), 'champs externes sans ACF' );
	// C2-4-02 : l'Élément de liste n'accepte une liaison qu'à partir de WP 7.1 ; avant, le panneau ne le propose pas.
	$fluffless_assert( ! isset( Fields::bindable( '6.8' )['core/list-item'] ) && ! isset( Fields::bindable( '7.0.2' )['core/list-item'] ) && array( 'content' ) === ( Fields::bindable( '7.1' )['core/list-item'] ?? null ) && array( 'content' ) === ( Fields::bindable( '7.1-RC1' )['core/list-item'] ?? null ), 'Élément de liste proposé avant WP 7.1, ou absent en 7.1' );
	$fluffless_assert( array( 'url', 'alt', 'title' ) === ( Fields::bindable( '6.8' )['core/image'] ?? null ) && array( 'content' ) === ( Fields::bindable( '6.8' )['core/paragraph'] ?? null ), 'attributs liables de base absents en 6.8' );
	$fluffless_assert( str_contains( $fluffless_before, '"attributes":' . wp_json_encode( Fields::bindable( (string) get_bloginfo( 'version' ) ) ) ), 'attributs liables non transmis à l\'éditeur' );
	$fluffless_assert( str_contains( $fluffless_js, 'DATA.attributes' ) && ! str_contains( $fluffless_js, "'core/list-item'" ), 'script d\'éditeur : liste des blocs liables codée en dur' );

	// 13. Le module suit la mécanique commune : identifiant, désactivé par défaut, option retirée à la désinstallation.
	$fluffless_assert( 'champs' === Fields::id() && ! Fields::enabled_by_default() && str_starts_with( Lifecycle::OPTION_CHAMPS, 'fluffless_' ), 'mécanique du module' );
	$fluffless_lifecycle = (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/Lifecycle.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( str_contains( $fluffless_lifecycle, 'delete_option( self::OPTION_CHAMPS )' ), 'option non retirée à la désinstallation' );
} finally {
	$fluffless_reset();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Champs personnalisés : {$fluffless_checks} vérifications passées." );
