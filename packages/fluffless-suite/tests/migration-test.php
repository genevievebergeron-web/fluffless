<?php
/**
 * Tests de la Migration depuis un constructeur : contrat et branchement, écriture des blocs et
 * texte riche, repérage, conversion d'une page du constructeur E (structure, colonnes, ancre,
 * couleurs gardées ou écartées, gadget inconnu, formulaire), rapport, remise en place, formulaire
 * et écran ; gadgets payants (tableau et liste de prix, articles, diaporama, boîte à retournement,
 * gabarits insérés), gabarits du constructeur de thème et réglages globaux (palette candidate et duo).
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/migration-test.php
 * Les pages créées sont supprimées à la fin ; réglages et compte restaurés.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Migration;
use Fluffless\Suite\Modules\Migration\Blocks;
use Fluffless\Suite\Modules\Migration\Divi;
use Fluffless\Suite\Modules\Migration\Elementor;
use Fluffless\Suite\Modules\Migration\Globals;
use Fluffless\Suite\Modules\Migration\Migrator;
use Fluffless\Suite\Modules\Migration\Report;
use Fluffless\Suite\Modules\Palette;
use Fluffless\Suite\Modules\Security;
use Fluffless\Suite\Modules\Security\Journal;

defined( 'ABSPATH' ) || exit;

// Les textes attendus sont ceux du rapport et de l'écran en anglais, quelle que soit la langue du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_user_before     = get_current_user_id();
$fluffless_pages           = array();
$fluffless_settings_before = get_option( Options::NAME );
$fluffless_kit_before      = get_option( Elementor::OPTION_KIT );
$fluffless_theme_before    = get_option( Divi::OPTION_THEME );
$fluffless_colors_before   = get_option( Divi::OPTION_COLORS );
$fluffless_palette_applied = false;
$fluffless_journal_row     = 0;

$fluffless_set = static function ( string $id, bool $enabled ): void {
	$all = get_option( Options::NAME );
	$all = is_array( $all ) ? $all : array();
	if ( ! isset( $all['modules'] ) || ! is_array( $all['modules'] ) ) {
		$all['modules'] = array();
	}
	$all['modules'][ $id ] = $enabled;
	update_option( Options::NAME, $all );
	Options::flush();
};

/**
 * Crée un contenu de test avec ses métadonnées ; lève une exception en cas d'échec.
 *
 * @param array<string, mixed> $post Champs du contenu.
 * @param array<string, mixed> $meta Métadonnées.
 * @throws RuntimeException Quand wp_insert_post échoue.
 */
function fluffless_make_post( array $post, array $meta ): int {
	$id = wp_insert_post( $post, true );
	if ( ! is_int( $id ) ) {
		throw new RuntimeException( 'Contenu de test non créé.' );
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
}

/**
 * Arbre du constructeur E : en-tête (conteneur colonne avec titre, texte, bouton), services
 * (conteneur avec titre puis rangée de trois colonnes), appel à l'action (fond, textes blancs),
 * un gadget inconnu et un formulaire.
 */
$fluffless_tree = array(
	array(
		'id'       => 'a1',
		'elType'   => 'container',
		'settings' => array(
			'_element_id'    => 'top',
			'flex_direction' => 'column',
		),
		'elements' => array(
			array(
				'id'         => 'a2',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array(
					'title'       => 'Bienvenue à l’atelier',
					'header_size' => 'h1',
					'align'       => 'center',
				),
			),
			array(
				'id'         => 'a3',
				'elType'     => 'widget',
				'widgetType' => 'text-editor',
				'settings'   => array( 'editor' => '<p>Première <strong>phrase</strong> à l’écran.</p><ul><li>Un</li><li>Deux</li></ul>' ),
			),
			array(
				'id'         => 'a4',
				'elType'     => 'widget',
				'widgetType' => 'button',
				'settings'   => array(
					'text' => 'Nous écrire',
					'link' => array(
						'url'         => 'https://example.com/contact',
						'is_external' => 'on',
					),
				),
			),
		),
	),
	array(
		'id'       => 'b1',
		'elType'   => 'container',
		'settings' => array(
			'_element_id'    => 'services',
			'flex_direction' => 'column',
		),
		'elements' => array(
			array(
				'id'         => 'b2',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array(
					'title'       => 'Services',
					'header_size' => 'h2',
				),
			),
			array(
				'id'       => 'b3',
				'elType'   => 'container',
				'settings' => array( 'flex_direction' => 'row' ),
				'elements' => array(
					array(
						'id'       => 'b4',
						'elType'   => 'container',
						'settings' => array(
							'width' => array(
								'size' => 50,
								'unit' => '%',
							),
						),
						'elements' => array(
							array(
								'id'         => 'b5',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array(
									'title'       => 'Un',
									'header_size' => 'h3',
								),
							),
						),
					),
					array(
						'id'       => 'b6',
						'elType'   => 'container',
						'settings' => array(
							'width' => array(
								'size' => 25,
								'unit' => '%',
							),
						),
						'elements' => array(
							array(
								'id'         => 'b7',
								'elType'     => 'widget',
								'widgetType' => 'image',
								'settings'   => array(
									'image' => array(
										'url' => 'https://example.com/photo.jpg',
										'id'  => 0,
										'alt' => 'Photo',
									),
								),
							),
						),
					),
					array(
						'id'       => 'b8',
						'elType'   => 'container',
						'settings' => array(
							'width' => array(
								'size' => 25,
								'unit' => '%',
							),
						),
						'elements' => array(
							array(
								'id'         => 'b9',
								'elType'     => 'widget',
								'widgetType' => 'icon-list',
								'settings'   => array(
									'icon_list' => array(
										array( 'text' => 'Point A' ),
										array(
											'text' => 'Point B',
											'link' => array( 'url' => 'https://example.com/b' ),
										),
									),
								),
							),
						),
					),
				),
			),
		),
	),
	array(
		'id'       => 'c1',
		'elType'   => 'container',
		'settings' => array(
			'_element_id'           => 'contact',
			'background_background' => 'classic',
			'background_color'      => '#1F2A37',
		),
		'elements' => array(
			array(
				'id'         => 'c2',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array(
					'title'                 => 'On se parle ?',
					'header_size'           => 'h2',
					'title_color'           => '#ffffff',
					'typography_typography' => 'custom',
				),
			),
			array(
				'id'         => 'c3',
				'elType'     => 'widget',
				'widgetType' => 'mystery-widget',
				'settings'   => array(
					'_private'    => 'Caché',
					'headline'    => 'Un texte gardé par le gadget inconnu',
					'color'       => '#ff0000',
					'url'         => 'https://example.com',
					'size'        => '12',
					'keyword'     => 'yes',
					'description' => 'Une deuxième phrase gardée.',
				),
			),
			array(
				'id'         => 'c4',
				'elType'     => 'widget',
				'widgetType' => 'form',
				'settings'   => array(
					'form_name'   => 'Contact',
					'form_fields' => array(
						array(
							'field_label' => 'Nom',
							'field_type'  => 'text',
						),
						array(
							'field_label' => 'Courriel',
							'field_type'  => 'email',
						),
					),
				),
			),
			array(
				'id'         => 'c5',
				'elType'     => 'widget',
				'widgetType' => 'video',
				'settings'   => array(
					'video_type'  => 'youtube',
					'youtube_url' => 'https://www.youtube.com/watch?v=abc123',
				),
			),
		),
	),
);

$fluffless_section_tree = array(
	array(
		'id'       => 's1',
		'elType'   => 'section',
		'settings' => array( 'layout' => 'full_width' ),
		'elements' => array(
			array(
				'id'       => 's2',
				'elType'   => 'column',
				'settings' => array( '_column_size' => 50 ),
				'elements' => array(
					array(
						'id'         => 's3',
						'elType'     => 'widget',
						'widgetType' => 'text-editor',
						'settings'   => array( 'editor' => 'Texte nu' ),
					),
				),
			),
			array(
				'id'       => 's4',
				'elType'   => 'column',
				'settings' => array( '_column_size' => 50 ),
				'elements' => array(
					array(
						'id'         => 's5',
						'elType'     => 'widget',
						'widgetType' => 'divider',
						'settings'   => array(),
					),
				),
			),
		),
	),
);

/**
 * Cherche un bloc par nom dans une liste, enfants compris.
 *
 * @param array<int, mixed> $blocks Blocs.
 * @param string            $name   Nom.
 * @return list<array<string, mixed>>
 */
function fluffless_find_blocks( array $blocks, string $name ): array {
	$out = array();
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		if ( ( $block['blockName'] ?? '' ) === $name ) {
			$out[] = $block;
		}
		if ( is_array( $block['innerBlocks'] ?? null ) ) {
			foreach ( fluffless_find_blocks( $block['innerBlocks'], $name ) as $found ) {
				$out[] = $found;
			}
		}
	}
	return $out;
}

/**
 * Ligne repérée d'une page.
 *
 * @param array<int, array<string, mixed>> $found Pages repérées.
 * @param int                              $id    Identifiant.
 * @return array<string, mixed>|null
 */
function fluffless_find_row( array $found, int $id ): ?array {
	foreach ( $found as $row ) {
		if ( ( $row['id'] ?? 0 ) === $id ) {
			return $row;
		}
	}
	return null;
}

try {
	wp_set_current_user( 1 );

	// Contrat et branchement.
	$fluffless_assert( 'migration' === Migration::id(), 'Identifiant du module.' );
	$fluffless_assert( ! Migration::enabled_by_default(), 'Éteint d’office.' );
	$fluffless_assert( array() === Migration::defaults() && array() === Migration::fields(), 'Aucun réglage.' );
	$fluffless_assert( isset( Loader::modules()['migration'] ) && Migration::class === Loader::modules()['migration'], 'Déclaré dans le Loader.' );
	$fluffless_assert(
		array(
			'elementor' => Elementor::class,
			'divi'      => Divi::class,
		) === Migrator::readers(),
		'Deux lecteurs : constructeurs E et D.'
	);
	foreach ( array( Migration::label(), Migration::description() ) as $fluffless_text ) {
		$fluffless_assert( false === stripos( $fluffless_text, 'elementor' ) && false === stripos( $fluffless_text, 'divi' ), 'Aucun nom de constructeur dans les chaînes affichées.' );
	}

	// Écriture des blocs.
	$fluffless_html = Blocks::serialize( array( Blocks::heading( 2, 'Titre', 'center', 'ici' ), Blocks::paragraph( 'Texte' ) ) );
	$fluffless_assert( str_contains( $fluffless_html, '<!-- wp:heading {"textAlign":"center","anchor":"ici"} -->' ) && str_contains( $fluffless_html, '<h2 id="ici" class="wp-block-heading has-text-align-center">Titre</h2>' ), 'Titre sérialisé avec alignement et ancre.' );
	$fluffless_parsed = parse_blocks( $fluffless_html );
	$fluffless_assert( 2 === count( $fluffless_parsed ) && 'core/paragraph' === $fluffless_parsed[1]['blockName'], 'Sérialisation relue par parse_blocks.' );
	$fluffless_rich = Blocks::rich( '<p>Première <strong>phrase</strong> à l’écran.</p><h3>Sous-titre</h3><ul><li>Un</li><li>Deux</li></ul>' );
	$fluffless_assert( 3 === count( $fluffless_rich ) && 'core/paragraph' === $fluffless_rich[0]['blockName'] && 'core/heading' === $fluffless_rich[1]['blockName'] && 'core/list' === $fluffless_rich[2]['blockName'], 'Texte riche découpé en paragraphe, titre et liste.' );
	$fluffless_assert( str_contains( $fluffless_rich[0]['innerHTML'], 'Première <strong>phrase</strong> à l’écran.' ), 'Accents et gras gardés tels quels dans le texte riche.' );
	$fluffless_assert( 2 === count( $fluffless_rich[2]['innerBlocks'] ) && 'core/list-item' === $fluffless_rich[2]['innerBlocks'][0]['blockName'], 'Liste avec deux éléments.' );
	$fluffless_assert( 1 === count( Blocks::rich( 'Texte nu' ) ) && 'core/paragraph' === Blocks::rich( 'Texte nu' )[0]['blockName'], 'Texte nu en un paragraphe.' );
	$fluffless_assert( array() === Blocks::rich( '  ' ), 'Texte vide sans bloc.' );
	$fluffless_assert( ! str_contains( Blocks::serialize( Blocks::rich( '<script>alert(1)</script>' ) ), '<script' ), 'Script écarté par kses.' );

	// Repérage : une page du constructeur, une page ordinaire, une corbeille.
	$fluffless_page = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Page du constructeur (test)',
			'post_content' => '<div class="elementor">rendu figé</div>',
		),
		true
	);
	$fluffless_assert( is_int( $fluffless_page ), 'Page de test créée.' );
	if ( ! is_int( $fluffless_page ) ) {
		throw new RuntimeException( 'Création de la page de test impossible.' );
	}
	$fluffless_pages[] = $fluffless_page;
	update_post_meta( $fluffless_page, Elementor::META_DATA, wp_slash( (string) wp_json_encode( $fluffless_tree ) ) );
	update_post_meta( $fluffless_page, Elementor::META_MODE, 'builder' );
	$fluffless_plain = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => 'Page ordinaire (test)',
		),
		true
	);
	if ( ! is_int( $fluffless_plain ) ) {
		throw new RuntimeException( 'Création de la page ordinaire impossible.' );
	}
	$fluffless_pages[] = $fluffless_plain;
	$fluffless_found   = Migrator::detect();
	$fluffless_ids     = array_column( $fluffless_found, 'id' );
	$fluffless_assert( in_array( $fluffless_page, $fluffless_ids, true ), 'Page du constructeur repérée.' );
	$fluffless_assert( ! in_array( $fluffless_plain, $fluffless_ids, true ), 'Page ordinaire ignorée.' );
	$fluffless_row = fluffless_find_row( $fluffless_found, $fluffless_page );
	$fluffless_assert( null !== $fluffless_row && 'elementor' === $fluffless_row['builder'] && 18 === $fluffless_row['elements'] && '' === $fluffless_row['migrated'] && 'draft' === $fluffless_row['status'], 'Ligne repérée : lecteur, 18 éléments, non convertie, brouillon.' );
	$fluffless_assert( Elementor::supports( $fluffless_page ) && ! Elementor::supports( $fluffless_plain ), 'Prise en charge par page.' );
	$fluffless_counts = Elementor::count( $fluffless_tree );
	$fluffless_assert( 4 === $fluffless_counts['heading'] && 7 === $fluffless_counts['container'] && 1 === $fluffless_counts['form'], 'Comptes par sorte.' );

	// Conversion sans les couleurs.
	$fluffless_report = new Report();
	$fluffless_blocks = Elementor::convert( $fluffless_tree, false, $fluffless_report );
	$fluffless_assert( 3 === count( $fluffless_blocks ) && 'core/group' === $fluffless_blocks[0]['blockName'] && 'top' === $fluffless_blocks[0]['attrs']['anchor'], 'Trois groupes de premier niveau, ancre du premier.' );
	$fluffless_top = $fluffless_blocks[0]['innerBlocks'];
	$fluffless_assert( 'core/heading' === $fluffless_top[0]['blockName'] && 1 === $fluffless_top[0]['attrs']['level'] && str_contains( $fluffless_top[0]['innerHTML'], 'Bienvenue à l’atelier' ), 'En-tête : h1 en premier avec ses accents.' );
	$fluffless_assert( 'core/paragraph' === $fluffless_top[1]['blockName'] && 'core/list' === $fluffless_top[2]['blockName'] && 'core/buttons' === $fluffless_top[3]['blockName'], 'En-tête : paragraphe, liste, boutons dans l’ordre.' );
	$fluffless_button = $fluffless_top[3]['innerBlocks'][0];
	$fluffless_assert( 'core/button' === $fluffless_button['blockName'] && str_contains( $fluffless_button['innerHTML'], 'href="https://example.com/contact"' ) && str_contains( $fluffless_button['innerHTML'], 'target="_blank"' ) && str_contains( $fluffless_button['innerHTML'], 'Nous écrire' ), 'Bouton : adresse, nouvel onglet, texte.' );
	$fluffless_services = $fluffless_blocks[1]['innerBlocks'];
	$fluffless_assert( 'core/heading' === $fluffless_services[0]['blockName'] && 'core/columns' === $fluffless_services[1]['blockName'] && 3 === count( $fluffless_services[1]['innerBlocks'] ), 'Services : h2 puis trois colonnes.' );
	$fluffless_assert( '50%' === ( $fluffless_services[1]['innerBlocks'][0]['attrs']['width'] ?? '' ) && '25%' === ( $fluffless_services[1]['innerBlocks'][1]['attrs']['width'] ?? '' ), 'Largeurs des colonnes en pourcentage.' );
	$fluffless_assert( 'core/heading' === $fluffless_services[1]['innerBlocks'][0]['innerBlocks'][0]['blockName'] && 3 === $fluffless_services[1]['innerBlocks'][0]['innerBlocks'][0]['attrs']['level'], 'Première colonne : h3.' );
	$fluffless_image = $fluffless_services[1]['innerBlocks'][1]['innerBlocks'][0];
	$fluffless_assert( 'core/image' === $fluffless_image['blockName'] && str_contains( $fluffless_image['innerHTML'], 'src="https://example.com/photo.jpg"' ) && str_contains( $fluffless_image['innerHTML'], 'alt="Photo"' ), 'Deuxième colonne : image par adresse avec son texte de remplacement.' );
	$fluffless_list = $fluffless_services[1]['innerBlocks'][2]['innerBlocks'][0];
	$fluffless_assert( 'core/list' === $fluffless_list['blockName'] && str_contains( $fluffless_list['innerBlocks'][1]['innerHTML'], '<a href="https://example.com/b">Point B</a>' ), 'Troisième colonne : liste à icônes en liste avec lien.' );
	$fluffless_cta = $fluffless_blocks[2];
	$fluffless_assert( 'contact' === $fluffless_cta['attrs']['anchor'] && ! isset( $fluffless_cta['attrs']['style'] ) && ! str_contains( $fluffless_cta['innerHTML'], 'has-background' ), 'Appel à l’action : ancre, fond écarté sans les couleurs.' );
	$fluffless_assert( ! isset( $fluffless_cta['innerBlocks'][0]['attrs']['style'] ), 'Couleur du titre écartée.' );
	$fluffless_assert( 3 === $fluffless_report->styles, 'Trois réglages de style écartés : fond, couleur de titre, typographie.' );
	$fluffless_assert( array( 'mystery-widget' => 1 ) === $fluffless_report->kept, 'Gadget inconnu compté comme gardé en texte.' );
	$fluffless_unknown = array_filter( $fluffless_cta['innerBlocks'], static fn( array $b ): bool => str_contains( $b['innerHTML'], 'gadget inconnu' ) || str_contains( $b['innerHTML'], 'deuxième phrase' ) );
	$fluffless_assert( 2 === count( $fluffless_unknown ), 'Gadget inconnu : deux textes gardés, réglages techniques écartés.' );
	$fluffless_assert( ! str_contains( Blocks::serialize( $fluffless_blocks ), 'Caché' ) && ! str_contains( Blocks::serialize( $fluffless_blocks ), '#ff0000' ), 'Clé privée et couleur du gadget inconnu absentes.' );
	$fluffless_assert( 1 === count( $fluffless_report->todo ) && str_contains( $fluffless_report->todo[0], 'Nom' ) && str_contains( $fluffless_report->todo[0], 'Courriel (email)' ), 'Formulaire : à refaire, champs listés.' );
	$fluffless_public = trim( preg_replace( '/<!--.*?-->/s', '', Blocks::serialize( $fluffless_blocks ) ) ?? '' );
	$fluffless_assert( ! str_contains( $fluffless_public, 'Form to rebuild' ) && ! str_contains( $fluffless_public, 'Formulaire à refaire' ), 'Formulaire : la phrase d’instruction ne reste pas dans le contenu public.' );
	$fluffless_assert( 1 === preg_match( '/<!--\s*fluffless-todo\s*:\s*\S[^>]*-->/', Blocks::serialize( $fluffless_blocks ) ), 'Formulaire : le rappel porte le repère fluffless-todo que la relecture de l’éditeur compte.' );
	$fluffless_embeds = fluffless_find_blocks( $fluffless_blocks, 'core/embed' );
	$fluffless_assert( 1 === count( $fluffless_embeds ) && 'youtube' === $fluffless_embeds[0]['attrs']['providerNameSlug'], 'Vidéo YouTube en intégration.' );
	$fluffless_assert( array( 'https://example.com/photo.jpg' ) === $fluffless_report->images, 'Image sans fichier joint notée au rapport.' );
	$fluffless_assert( $fluffless_report->blocks > 20, 'Blocs comptés, enfants compris.' );
	$fluffless_assert( Report::from_array( $fluffless_report->to_array() )->to_array() === $fluffless_report->to_array(), 'Rapport rangé puis relu à l’identique.' );

	// Conversion avec les couleurs.
	$fluffless_kept = Elementor::convert( $fluffless_tree, true, new Report() );
	$fluffless_assert( '#1f2a37' === ( $fluffless_kept[2]['attrs']['style']['color']['background'] ?? '' ) && str_contains( $fluffless_kept[2]['innerHTML'], 'has-background' ), 'Fond gardé sur demande.' );
	$fluffless_assert( '#ffffff' === ( $fluffless_kept[2]['innerBlocks'][0]['attrs']['style']['color']['text'] ?? '' ), 'Couleur du titre gardée sur demande.' );

	// Sections et colonnes classiques.
	$fluffless_sections = Elementor::convert( $fluffless_section_tree, false, new Report() );
	$fluffless_assert( 1 === count( $fluffless_sections ) && 'full' === ( $fluffless_sections[0]['attrs']['align'] ?? '' ) && 'core/columns' === $fluffless_sections[0]['innerBlocks'][0]['blockName'], 'Section pleine largeur en groupe alignfull avec colonnes.' );
	$fluffless_assert( ! isset( $fluffless_sections[0]['innerBlocks'][0]['innerBlocks'][0]['attrs']['width'] ) && 'core/separator' === $fluffless_sections[0]['innerBlocks'][0]['innerBlocks'][1]['innerBlocks'][0]['blockName'], 'Colonnes égales sans largeur, séparateur dans la deuxième.' );

	// Conversion sur place.
	$fluffless_outcome = Migrator::convert( $fluffless_page, false );
	$fluffless_assert( $fluffless_outcome['ok'] && 'converted' === $fluffless_outcome['code'], 'Conversion réussie.' );
	$fluffless_content = (string) get_post_field( 'post_content', $fluffless_page, 'raw' );
	$fluffless_assert( str_starts_with( $fluffless_content, '<!-- wp:group' ) && str_contains( $fluffless_content, 'Bienvenue à l’atelier' ), 'Contenu remplacé par des blocs.' );
	$fluffless_assert( array() !== parse_blocks( $fluffless_content ) && array() === array_filter( parse_blocks( $fluffless_content ), static fn( array $b ): bool => null === $b['blockName'] && '' !== trim( $b['innerHTML'] ) ), 'Contenu relu sans bloc libre.' );
	$fluffless_assert( '' === get_post_meta( $fluffless_page, Elementor::META_MODE, true ), 'Marqueur du constructeur retiré.' );
	$fluffless_assert( '' !== get_post_meta( $fluffless_page, Elementor::META_DATA, true ), 'Données du constructeur gardées.' );
	$fluffless_backup = get_post_meta( $fluffless_page, Migrator::META_BACKUP, true );
	$fluffless_assert( is_array( $fluffless_backup ) && '<div class="elementor">rendu figé</div>' === $fluffless_backup['content'] && 'builder' === $fluffless_backup['mode'], 'Copie d’avant gardée avec le mode.' );
	$fluffless_done = get_post_meta( $fluffless_page, Migrator::META_DONE, true );
	$fluffless_assert( is_array( $fluffless_done ) && 'elementor' === $fluffless_done['builder'] && is_string( $fluffless_done['date'] ), 'Marqueur de conversion posé.' );
	$fluffless_assert( Migrator::report( $fluffless_page ) instanceof Report && Migrator::report( $fluffless_page )->blocks === $fluffless_report->blocks, 'Rapport rangé sur la page.' );
	$fluffless_found = Migrator::detect();
	$fluffless_row   = fluffless_find_row( $fluffless_found, $fluffless_page );
	$fluffless_assert( null !== $fluffless_row && '' !== $fluffless_row['migrated'], 'Page repérée comme convertie.' );

	// Deuxième conversion : la copie d'avant n'est pas écrasée.
	Migrator::convert( $fluffless_page, true );
	$fluffless_backup = get_post_meta( $fluffless_page, Migrator::META_BACKUP, true );
	$fluffless_assert( is_array( $fluffless_backup ) && '<div class="elementor">rendu figé</div>' === $fluffless_backup['content'], 'Copie d’avant intacte après une deuxième conversion.' );
	$fluffless_assert( str_contains( (string) get_post_field( 'post_content', $fluffless_page, 'raw' ), 'has-background' ), 'Deuxième conversion avec les couleurs.' );

	// C-S5-08 : « Put back » demande une confirmation qui dit ce qui est remplacé et où retrouver les changements.
	ob_start();
	Migration::render();
	$fluffless_screen = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_screen, 'name="revert" value="' . $fluffless_page . '" class="button" onclick="return window.confirm(' ), 'Remise en place : confirmation avant l’action.' );
	$fluffless_assert( str_contains( $fluffless_screen, 'stay in the page revisions' ), 'Remise en place : la confirmation mentionne les révisions.' );
	$fluffless_revisions_off = static fn(): int => 0;
	add_filter( 'wp_revisions_to_keep', $fluffless_revisions_off );
	ob_start();
	Migration::render();
	$fluffless_screen = (string) ob_get_clean();
	remove_filter( 'wp_revisions_to_keep', $fluffless_revisions_off );
	$fluffless_assert( str_contains( $fluffless_screen, 'cannot be recovered' ), 'Remise en place : révisions éteintes, la confirmation le dit.' );

	// Refus.
	$fluffless_assert( 'unsupported' === Migrator::convert( $fluffless_plain, false )['code'], 'Page ordinaire refusée.' );
	$fluffless_assert( 'missing' === Migrator::convert( 999999999, false )['code'], 'Page absente refusée.' );
	$fluffless_assert( ! Migrator::revert( $fluffless_plain ), 'Rien à remettre sur une page ordinaire.' );

	// Remise en place.
	$fluffless_assert( Migrator::revert( $fluffless_page ), 'Remise en place réussie.' );
	$fluffless_assert( '<div class="elementor">rendu figé</div>' === (string) get_post_field( 'post_content', $fluffless_page, 'raw' ), 'Contenu d’avant remis.' );
	$fluffless_assert( 'builder' === get_post_meta( $fluffless_page, Elementor::META_MODE, true ), 'Marqueur du constructeur remis.' );
	$fluffless_assert( '' === get_post_meta( $fluffless_page, Migrator::META_DONE, true ) && '' === get_post_meta( $fluffless_page, Migrator::META_BACKUP, true ) && null === Migrator::report( $fluffless_page ), 'Marqueurs de conversion retirés.' );
	$fluffless_assert( ! Migrator::revert( $fluffless_page ), 'Deuxième remise en place refusée.' );

	// Formulaire.
	$fluffless_url = Migration::run(
		array(
			'convert'     => (string) $fluffless_page,
			'keep_colors' => '1',
		)
	);
	$fluffless_assert( str_contains( $fluffless_url, 'fl_migrated=' . $fluffless_page ) && str_contains( (string) get_post_field( 'post_content', $fluffless_page, 'raw' ), 'has-background' ), 'Formulaire : conversion avec les couleurs.' );
	$fluffless_assert( str_contains( Migration::run( array( 'revert' => (string) $fluffless_page ) ), 'fl_reverted=' . $fluffless_page ), 'Formulaire : remise en place.' );
	$fluffless_assert( str_contains( Migration::run( array( 'revert' => (string) $fluffless_page ) ), 'fl_migration_error=revert' ), 'Formulaire : rien à remettre.' );
	$fluffless_assert( str_contains( Migration::run( array( 'convert' => (string) $fluffless_plain ) ), 'fl_migration_error=unsupported' ), 'Formulaire : page ordinaire refusée.' );
	$fluffless_assert( str_contains( Migration::run( array() ), 'fl_migration_error=nothing' ), 'Formulaire : rien demandé.' );

	// Livraison 2 : gabarits enregistrés, gadgets payants.
	$fluffless_lib     = fluffless_make_post(
		array(
			'post_type'   => Elementor::TYPE_LIBRARY,
			'post_status' => 'publish',
			'post_title'  => 'Bloc réutilisable (test)',
		),
		array(
			Elementor::META_TEMPLATE_TYPE => 'section',
			Elementor::META_DATA          => wp_slash(
				(string) wp_json_encode(
					array(
						array(
							'id'         => 'l1',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array(
								'title'       => 'Bloc réutilisable',
								'header_size' => 'h3',
							),
						),
					)
				)
			),
		)
	);
	$fluffless_pages[] = $fluffless_lib;
	$fluffless_loop    = fluffless_make_post(
		array(
			'post_type'   => Elementor::TYPE_LIBRARY,
			'post_status' => 'publish',
			'post_title'  => 'Boucle (test)',
		),
		array( Elementor::META_TEMPLATE_TYPE => 'section' )
	);
	$fluffless_pages[] = $fluffless_loop;
	update_post_meta(
		$fluffless_loop,
		Elementor::META_DATA,
		wp_slash(
			(string) wp_json_encode(
				array(
					array(
						'id'         => 'l2',
						'elType'     => 'widget',
						'widgetType' => 'template',
						'settings'   => array( 'template_id' => (string) $fluffless_loop ),
					),
				)
			)
		)
	);
	foreach ( array(
		'header' => 'En-tête principal (test)',
		'popup'  => 'Infolettre (test)',
	) as $fluffless_type => $fluffless_title ) {
		$fluffless_pages[] = fluffless_make_post(
			array(
				'post_type'   => Elementor::TYPE_LIBRARY,
				'post_status' => 'publish',
				'post_title'  => $fluffless_title,
			),
			array(
				Elementor::META_TEMPLATE_TYPE => $fluffless_type,
				Elementor::META_DATA          => '[]',
			)
		);
	}
	$fluffless_templates = Migrator::templates();
	$fluffless_assert( isset( $fluffless_templates['header'], $fluffless_templates['popup'] ) && ! isset( $fluffless_templates['section'] ) && in_array( 'En-tête principal (test)', $fluffless_templates['header'], true ), 'Gabarits du constructeur listés par sorte, sections insérables exclues.' );

	$fluffless_pro    = array(
		array(
			'id'       => 'p1',
			'elType'   => 'container',
			'settings' => array( 'flex_direction' => 'column' ),
			'elements' => array(
				array(
					'id'         => 'p2',
					'elType'     => 'widget',
					'widgetType' => 'price-table',
					'settings'   => array(
						'heading'                => 'Forfait Pro',
						'sub_heading'            => 'Pour les équipes',
						'currency_symbol'        => 'dollar',
						'currency_format'        => ',',
						'price'                  => '29',
						'fractional_part'        => '99',
						'period'                 => 'par mois',
						'show_ribbon'            => 'yes',
						'ribbon_title'           => 'Populaire',
						'features_list'          => array(
							array( 'item_text' => 'Dix pages' ),
							array( 'item_text' => 'Soutien courriel' ),
							array( 'item_text' => '' ),
						),
						'button_text'            => 'Choisir',
						'link'                   => array( 'url' => 'https://exemple.test/pro' ),
						'footer_additional_info' => 'Taxes en sus',
						'header_bg_color'        => '#1f2a37',
					),
				),
				array(
					'id'         => 'p3',
					'elType'     => 'widget',
					'widgetType' => 'price-list',
					'settings'   => array(
						'price_list' => array(
							array(
								'title'            => 'Café',
								'price'            => '3 $',
								'item_description' => 'Torréfié ici',
							),
							array(
								'title'            => '',
								'price'            => '',
								'item_description' => 'vide',
							),
						),
					),
				),
				array(
					'id'         => 'p4',
					'elType'     => 'widget',
					'widgetType' => 'posts',
					'settings'   => array(
						'_skin'           => 'classic',
						'classic_columns' => '2',
						'posts_per_page'  => '4',
						'posts_post_type' => 'post',
					),
				),
				array(
					'id'         => 'p5',
					'elType'     => 'widget',
					'widgetType' => 'slides',
					'settings'   => array(
						'slides' => array(
							array(
								'heading'          => 'Première diapo',
								'description'      => 'Texte de la première.',
								'button_text'      => 'Voir',
								'link'             => array( 'url' => 'https://exemple.test/a' ),
								'background_color' => '#f3ede4',
							),
							array(
								'heading'     => 'Deuxième diapo',
								'description' => 'Texte de la deuxième.',
							),
						),
					),
				),
				array(
					'id'         => 'p6',
					'elType'     => 'widget',
					'widgetType' => 'flip-box',
					'settings'   => array(
						'title_text_a'       => 'Devant',
						'description_text_a' => 'Face avant.',
						'title_text_b'       => 'Derrière',
						'description_text_b' => 'Face arrière.',
						'button_text'        => 'En savoir plus',
						'link'               => array( 'url' => 'https://exemple.test/b' ),
					),
				),
				array(
					'id'         => 'p7',
					'elType'     => 'widget',
					'widgetType' => 'template',
					'settings'   => array( 'template_id' => (string) $fluffless_lib ),
				),
				array(
					'id'         => 'p8',
					'elType'     => 'widget',
					'widgetType' => 'global',
					'templateID' => $fluffless_lib,
					'settings'   => array(),
				),
				array(
					'id'         => 'p9',
					'elType'     => 'widget',
					'widgetType' => 'template',
					'settings'   => array( 'template_id' => '999999999' ),
				),
				array(
					'id'         => 'p10',
					'elType'     => 'widget',
					'widgetType' => 'nav-menu',
					'settings'   => array( 'menu' => 'principal' ),
				),
			),
		),
	);
	$fluffless_report = new Report();
	$fluffless_blocks = Elementor::convert( $fluffless_pro, false, $fluffless_report );
	$fluffless_html   = Blocks::serialize( $fluffless_blocks );
	$fluffless_assert( str_contains( $fluffless_html, 'Forfait Pro <em>(Populaire)</em>' ) && str_contains( $fluffless_html, '<strong>29,99' . "\u{00A0}" . '$</strong> par mois' ) && str_contains( $fluffless_html, '<li>Soutien courriel</li>' ) && str_contains( $fluffless_html, 'href="https://exemple.test/pro">Choisir' ) && str_contains( $fluffless_html, '<em>Taxes en sus</em>' ), 'Tableau de prix : titre et ruban, prix formaté, liste, bouton, note.' );
	$fluffless_assert( 1 === substr_count( $fluffless_html, '<li>Dix pages</li>' ) && ! str_contains( $fluffless_html, '<li></li>' ), 'Tableau de prix : ligne vide écartée.' );
	$fluffless_assert( str_contains( $fluffless_html, '<strong>Café' . "\u{00A0}" . ': 3 $</strong><br>Torréfié ici' ) && ! str_contains( $fluffless_html, 'vide' ), 'Liste de prix : titre, prix et description ; ligne sans titre ni prix écartée.' );
	$fluffless_query = fluffless_find_blocks( $fluffless_blocks, 'core/query' );
	$fluffless_assert( 1 === count( $fluffless_query ) && 4 === $fluffless_query[0]['attrs']['query']['perPage'] && 'post' === $fluffless_query[0]['attrs']['query']['postType'] && 2 === $fluffless_query[0]['innerBlocks'][0]['attrs']['layout']['columnCount'] && str_contains( $fluffless_html, '<!-- wp:post-title {"isLink":true} /-->' ), 'Articles : bloc Requête à quatre contenus sur deux colonnes, titre lié.' );
	$fluffless_assert( str_contains( $fluffless_html, '<h2 class="wp-block-heading">Première diapo</h2>' ) && str_contains( $fluffless_html, 'Texte de la première.' ) && ! str_contains( $fluffless_html, 'Texte de la deuxième.' ), 'Diaporama : première diapositive gardée, la deuxième non.' );
	$fluffless_assert( str_contains( $fluffless_html, '<h3 class="wp-block-heading">Devant</h3>' ) && str_contains( $fluffless_html, '<h4 class="wp-block-heading">Derrière</h4>' ) && str_contains( $fluffless_html, 'href="https://exemple.test/b">En savoir plus' ), 'Boîte à retournement : deux faces et bouton.' );
	$fluffless_assert( 2 === substr_count( $fluffless_html, '<h3 class="wp-block-heading">Bloc réutilisable</h3>' ), 'Gabarit inséré et gadget global convertis sur place.' );
	$fluffless_todo = implode( "\n", $fluffless_report->todo );
	$fluffless_assert( str_contains( $fluffless_todo, '999999999' ) && str_contains( $fluffless_todo, 'nav-menu' ) && str_contains( $fluffless_todo, '2 slides' ) && str_contains( $fluffless_todo, 'Deuxième diapo' ) && str_contains( $fluffless_todo, 'Query block' ), 'Rapport : gabarit absent, menu, autres diapositives et requête à revoir.' );
	$fluffless_assert( 2 === $fluffless_report->styles && 1 === ( $fluffless_report->skipped['nav-menu'] ?? 0 ), 'Rapport : couleurs du tableau et de la diapositive écartées et comptées, menu laissé de côté.' );
	$fluffless_assert( str_contains( Blocks::serialize( Elementor::convert( $fluffless_pro, true, new Report() ) ), 'background-color:#f3ede4' ), 'Diaporama : fond gardé sur demande.' );
	$fluffless_report = new Report();
	$fluffless_assert( array() === Elementor::convert( Elementor::tree( $fluffless_loop ), false, $fluffless_report ) && ( $fluffless_report->skipped['template'] ?? 0 ) >= 1, 'Gabarit qui s’insère lui-même : arrêté au troisième niveau.' );

	// Réglages globaux.
	delete_option( Elementor::OPTION_KIT );
	$fluffless_assert( null === Migrator::globals(), 'Sans kit, aucun réglage global.' );
	$fluffless_kit     = fluffless_make_post(
		array(
			'post_type'   => Elementor::TYPE_LIBRARY,
			'post_status' => 'publish',
			'post_title'  => 'Kit (test)',
		),
		array(
			Elementor::META_TEMPLATE_TYPE => 'kit',
			Elementor::META_KIT           => array(
				'system_colors'     => array(
					array(
						'_id'   => 'primary',
						'title' => 'Primary',
						'color' => '#6EC1E4',
					),
					array(
						'_id'   => 'secondary',
						'title' => 'Secondary',
						'color' => '#54595F',
					),
					array(
						'_id'   => 'text',
						'title' => 'Text',
						'color' => '#7A7A7A',
					),
					array(
						'_id'   => 'accent',
						'title' => 'Accent',
						'color' => '#61CE70',
					),
				),
				'system_typography' => array(
					array(
						'_id'                    => 'primary',
						'title'                  => 'Primary',
						'typography_font_family' => 'Roboto',
					),
					array(
						'_id'                    => 'text',
						'title'                  => 'Text',
						'typography_font_family' => 'Roboto',
					),
				),
			),
		)
	);
	$fluffless_pages[] = $fluffless_kit;
	update_option( Elementor::OPTION_KIT, $fluffless_kit );
	$fluffless_proposal = Migrator::globals();
	$fluffless_assert( null !== $fluffless_proposal && 'elementor' === $fluffless_proposal['builder'] && '#6ec1e4' === ( $fluffless_proposal['colors']['primary'] ?? '' ) && null !== $fluffless_proposal['palette'] && array() !== $fluffless_proposal['failures'] && null === $fluffless_proposal['pair'], 'Kit d’origine : palette candidate sous le seuil, aucun duo.' );
	$fluffless_assert( 'fail' === Migrator::apply_palette() && 'error' === Migrator::apply_fonts(), 'Kit d’origine : palette refusée, duo introuvable.' );
	update_post_meta(
		$fluffless_kit,
		Elementor::META_KIT,
		array(
			'system_colors'           => array(
				array(
					'_id'   => 'primary',
					'title' => 'Primary',
					'color' => '#1F4E79',
				),
				array(
					'_id'   => 'secondary',
					'title' => 'Secondary',
					'color' => '#54595f',
				),
				array(
					'_id'   => 'text',
					'title' => 'Text',
					'color' => '#1f2a37',
				),
				array(
					'_id'   => 'accent',
					'title' => 'Accent',
					'color' => '#b3261e',
				),
			),
			'custom_colors'           => array(
				array(
					'_id'   => 'c1',
					'title' => 'Sable',
					'color' => '#f3ede4',
				),
				array(
					'_id'   => 'c2',
					'title' => 'Nuit',
					'color' => '#123',
				),
				array(
					'_id'   => 'c3',
					'title' => 'Transparente',
					'color' => 'rgba(0,0,0,.5)',
				),
			),
			'body_background_color'   => '#ffffff',
			'button_background_color' => '#1f4e79',
			'button_text_color'       => '#ffffff',
			'system_typography'       => array(
				array(
					'_id'                    => 'primary',
					'title'                  => 'Primary',
					'typography_font_family' => 'Gloock',
				),
				array(
					'_id'                    => 'text',
					'title'                  => 'Text',
					'typography_font_family' => 'Outfit',
				),
			),
		)
	);
	$fluffless_proposal = Migrator::globals();
	$fluffless_assert( null !== $fluffless_proposal && '#112233' === ( $fluffless_proposal['colors']['Nuit'] ?? '' ) && '#f3ede4' === ( $fluffless_proposal['colors']['Sable'] ?? '' ) && ! isset( $fluffless_proposal['colors']['Transparente'] ) && '#1f4e79' === ( $fluffless_proposal['colors']['button'] ?? '' ), 'Kit lisible : couleurs sur mesure par titre, HEX court étendu, rgba écarté, bouton lu.' );
	$fluffless_assert( null !== $fluffless_proposal && array() === $fluffless_proposal['failures'] && '#1f4e79' === ( $fluffless_proposal['palette']['accent'] ?? '' ) && '#1f2a37' === ( $fluffless_proposal['palette']['contrast'] ?? '' ) && '#1f4e79' === ( $fluffless_proposal['palette']['button-bg'] ?? '' ) && 9 === $fluffless_proposal['pair'], 'Kit lisible : palette qui passe les contrastes, duo Gloock et Outfit reconnu.' );
	$fluffless_assert(
		7 === Globals::pair(
			array(
				'primary' => 'Recursive',
				'text'    => 'Arial',
			)
		) && 6 === Globals::pair( array( 'secondary' => 'Bricolage Grotesque' ) ) && null === Globals::pair(
			array(
				'primary' => 'Gloock',
				'text'    => 'Roboto',
			)
		) && null === Globals::pair( array( 'text' => 'Outfit' ) ),
		'Duo : texte système reconnu, titres seuls, familles hors banque refusées.'
	);
	$fluffless_assert( 'instrument-serif' === Globals::slug( '"INSTRUMENT SERIF"' ) && null === Globals::slug( 'Comic Sans' ) && null === Globals::palette( array( 'primary' => '#1f4e79' ) ), 'Famille trouvée sans tenir compte de la casse ni des guillemets ; palette refusée sans couleur de texte.' );
	$fluffless_set( Palette::id(), false );
	$fluffless_set( 'fonts', false );
	$fluffless_assert( 'off' === Migrator::apply_palette() && 'off' === Migrator::apply_fonts(), 'Modules éteints : rien d’appliqué.' );
	$fluffless_assert( str_contains( Migration::run( array( 'apply_fonts' => '1' ) ), 'fl_fonts=off' ), 'Formulaire : duo avec le module éteint.' );
	$fluffless_set( Palette::id(), true );
	$fluffless_palette_applied = true;
	$fluffless_assert( str_contains( Migration::run( array( 'apply_palette' => '1' ) ), 'fl_palette=ok' ) && '#1f4e79' === ( Palette::current()['accent'] ?? '' ), 'Formulaire : palette du constructeur appliquée au site.' );
	$fluffless_assert( Palette::reset(), 'Palette d’avant remise.' );
	$fluffless_palette_applied = false;
	ob_start();
	Migration::render();
	$fluffless_screen = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_screen, 'Gloock + Outfit' ) && str_contains( $fluffless_screen, 'name="apply_palette"' ) && str_contains( $fluffless_screen, 'name="apply_fonts"' ) && str_contains( $fluffless_screen, 'En-tête principal (test)' ) && str_contains( $fluffless_screen, 'background:#1f4e79' ), 'Écran : cartes des réglages globaux et des gabarits.' );
	$_GET['fl_palette'] = 'off';
	ob_start();
	Migration::render();
	$fluffless_screen = (string) ob_get_clean();
	unset( $_GET['fl_palette'] );
	$fluffless_assert( str_contains( $fluffless_screen, 'fl-note fl-note--bad' ) && str_contains( $fluffless_screen, 'The Palette module is off' ), 'Écran : note du résultat de la palette.' );

	// Constructeur D : arbre de codes courts sur trois niveaux.
	$fluffless_d_global = fluffless_make_post(
		array(
			'post_type'    => Divi::TYPE_LIBRARY,
			'post_status'  => 'publish',
			'post_title'   => 'Module global (test)',
			'post_content' => '[et_pb_text _builder_version="4.9"]<p>Texte du module global.</p>[/et_pb_text]',
		),
		array()
	);
	$fluffless_pages[]  = $fluffless_d_global;
	$fluffless_d_loop   = fluffless_make_post(
		array(
			'post_type'    => Divi::TYPE_LIBRARY,
			'post_status'  => 'publish',
			'post_title'   => 'Module qui boucle (test)',
			'post_content' => '',
		),
		array()
	);
	$fluffless_pages[]  = $fluffless_d_loop;
	wp_update_post(
		array(
			'ID'           => $fluffless_d_loop,
			'post_content' => '[et_pb_text global_module="' . $fluffless_d_loop . '"][/et_pb_text]',
		)
	);
	$fluffless_d_header = fluffless_make_post(
		array(
			'post_type'    => 'et_header_layout',
			'post_status'  => 'publish',
			'post_title'   => 'En-tête du thème D (test)',
			'post_content' => '[et_pb_section][/et_pb_section]',
		),
		array()
	);
	$fluffless_pages[]  = $fluffless_d_header;
	$fluffless_d_source = '[et_pb_section fb_built="1" module_id="haut" fullwidth=on _builder_version="4.9"]'
		. '[et_pb_row column_structure="1_3,2_3"]'
		. '[et_pb_column type="1_3"][et_pb_text text_text_color="#123456"]<h2>Bienvenue</h2><p>Premier paragraphe avec un « guillemet ».</p>[/et_pb_text][/et_pb_column]'
		. '[et_pb_column type="2_3"][et_pb_button button_text="Nous joindre" button_url="https://exemple.test/contact" url_new_window="on" button_alignment="center" /]'
		. '[et_pb_blurb title="Service %91un%93" url="https://exemple.test/un" use_icon="on" font_icon="&#x1;"]<p>Détail du service.</p>[/et_pb_blurb]'
		. '[et_pb_image src="https://exemple.test/photo.jpg" alt="Photo" align="center" /]'
		. '[et_pb_testimonial author="Ariane" job_title="Cheffe" company_name="Atelier"]<p>Un travail soigné.</p>[/et_pb_testimonial]'
		. '[et_pb_toggle title="Question"]<p>Réponse.</p>[/et_pb_toggle]'
		. '[et_pb_accordion][et_pb_accordion_item title="Premier"]<p>Un.</p>[/et_pb_accordion_item][et_pb_accordion_item title="Second"]<p>Deux.</p>[/et_pb_accordion_item][/et_pb_accordion]'
		. '[et_pb_divider show_divider="on" /]'
		. '[et_pb_video src="https://www.youtube.com/watch?v=abc" /]'
		. '[et_pb_pricing_tables][et_pb_pricing_table title="Base" subtitle="Pour commencer" currency="$" sum="29,99" per="par mois" button_text="Choisir" button_url="https://exemple.test/base"]+ Un site
+ Un courriel
- Pas de boutique[/et_pb_pricing_table][/et_pb_pricing_tables]'
		. '[et_pb_contact_form title="Écrivez-nous"][et_pb_contact_field field_title="Nom" field_type="input" /][et_pb_contact_field field_title="Courriel" field_type="email" /][/et_pb_contact_form]'
		. '[et_pb_slider][et_pb_slide heading="Première diapo" button_text="Voir" button_link="https://exemple.test/a" background_color="#f3ede4"]<p>Texte de la première.</p>[/et_pb_slide][et_pb_slide heading="Deuxième diapo"]<p>Texte de la deuxième.</p>[/et_pb_slide][/et_pb_slider]'
		. '[et_pb_blog posts_number="4" fullwidth="off" /]'
		. '[et_pb_social_media_follow][et_pb_social_media_follow_network social_network="facebook" url="https://facebook.com/exemple"]facebook[/et_pb_social_media_follow_network][/et_pb_social_media_follow]'
		. '[et_pb_code]<div class="perso">%22Code%22 %91gardé%93</div>[/et_pb_code]'
		. '[et_pb_text global_module="' . $fluffless_d_global . '"][/et_pb_text]'
		. '[et_pb_text global_module="' . $fluffless_d_loop . '"][/et_pb_text]'
		. '[et_pb_text global_module="999999999"][/et_pb_text]'
		. '[et_pb_mystery_module titre="Gadget mystère" couleur="#ff0000"]<p>Texte du gadget inconnu.</p>[/et_pb_mystery_module]'
		. '[et_pb_search /]'
		. '[/et_pb_column][/et_pb_row]'
		. '[et_pb_row_inner][et_pb_column_inner type="4_4"][et_pb_cta title="Prêt ?" button_text="Allons-y" button_url="https://exemple.test/go" background_color="#1f4e79"]<p>On commence.</p>[/et_pb_cta][/et_pb_column_inner][/et_pb_row_inner]'
		. '[/et_pb_section]'
		. '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_fullwidth_header title="Titre plein" subhead="Sous-titre" button_one_text="Un" button_one_url="https://exemple.test/1" /][/et_pb_column][/et_pb_row][/et_pb_section]';
	$fluffless_d_tree   = Divi::parse( $fluffless_d_source );
	$fluffless_assert( 2 === count( $fluffless_d_tree ) && 'section' === $fluffless_d_tree[0]['tag'] && 'haut' === $fluffless_d_tree[0]['attrs']['module_id'] && 'on' === $fluffless_d_tree[0]['attrs']['fullwidth'], 'D : deux sections, attributs avec et sans guillemets.' );
	$fluffless_assert( 'row' === $fluffless_d_tree[0]['children'][0]['tag'] && 'column' === $fluffless_d_tree[0]['children'][0]['children'][0]['tag'] && 'text' === $fluffless_d_tree[0]['children'][0]['children'][0]['children'][0]['tag'], 'D : trois niveaux imbriqués.' );
	$fluffless_d_text = $fluffless_d_tree[0]['children'][0]['children'][0]['children'][0];
	$fluffless_assert( str_contains( $fluffless_d_text['content'], '<h2>Bienvenue</h2>' ) && array() === $fluffless_d_text['children'], 'D : contenu HTML gardé tel quel sous un module feuille.' );
	$fluffless_d_counts = Divi::count( $fluffless_d_tree );
	$fluffless_assert( 2 === $fluffless_d_counts['section'] && 3 === $fluffless_d_counts['column'] && 4 === $fluffless_d_counts['text'] && 2 === $fluffless_d_counts['accordion_item'] && 1 === $fluffless_d_counts['mystery_module'], 'D : comptes par sorte sans préfixe.' );
	$fluffless_report   = new Report();
	$fluffless_d_blocks = Divi::convert( $fluffless_d_tree, false, $fluffless_report );
	$fluffless_d_html   = Blocks::serialize( $fluffless_d_blocks );
	$fluffless_assert( 2 === count( $fluffless_d_blocks ) && 'core/group' === $fluffless_d_blocks[0]['blockName'] && 'haut' === $fluffless_d_blocks[0]['attrs']['anchor'] && 'full' === $fluffless_d_blocks[0]['attrs']['align'], 'D : section en groupe ancré pleine largeur.' );
	$fluffless_d_cols = $fluffless_d_blocks[0]['innerBlocks'][0];
	$fluffless_assert( 'core/columns' === $fluffless_d_cols['blockName'] && '33.33%' === $fluffless_d_cols['innerBlocks'][0]['attrs']['width'] && '66.67%' === $fluffless_d_cols['innerBlocks'][1]['attrs']['width'], 'D : rangée en colonnes 1/3 et 2/3.' );
	$fluffless_assert( str_contains( $fluffless_d_html, '<h2 class="wp-block-heading">Bienvenue</h2>' ) && str_contains( $fluffless_d_html, '<p>Premier paragraphe avec un « guillemet ».</p>' ), 'D : texte riche découpé en titre et paragraphe.' );
	$fluffless_assert( str_contains( $fluffless_d_html, 'href="https://exemple.test/contact" target="_blank" rel="noreferrer noopener">Nous joindre' ), 'D : bouton avec nouvel onglet.' );
	$fluffless_assert( str_contains( $fluffless_d_html, '<a href="https://exemple.test/un">Service [un]</a>' ) && str_contains( $fluffless_d_html, '<cite>Ariane, Cheffe, Atelier</cite>' ), 'D : présentation liée avec attribut décodé, témoignage cité.' );
	$fluffless_assert( 3 === substr_count( $fluffless_d_html, '<!-- wp:details' ) && str_contains( $fluffless_d_html, '<summary>Question</summary>' ) && str_contains( $fluffless_d_html, '<summary>Second</summary>' ), 'D : bascule et accordéon en détails.' );
	$fluffless_assert( str_contains( $fluffless_d_html, 'wp:separator' ) && str_contains( $fluffless_d_html, 'wp:embed {"url":"https://www.youtube.com/watch?v=abc"' ), 'D : séparateur et vidéo intégrée.' );
	$fluffless_assert( str_contains( $fluffless_d_html, '<strong>29,99' . "\u{00A0}" . '$</strong> par mois' ) && str_contains( $fluffless_d_html, '<li>Un courriel</li>' ) && str_contains( $fluffless_d_html, '<li><s>Pas de boutique</s></li>' ), 'D : tableau de prix avec avantages et manque barré.' );
	$fluffless_assert( str_contains( $fluffless_d_html, 'Écrivez-nous. Fields: Nom, Courriel (email).' ), 'D : formulaire à refaire avec ses champs.' );
	$fluffless_assert( str_contains( $fluffless_d_html, 'Première diapo' ) && ! str_contains( $fluffless_d_html, 'Texte de la deuxième' ) && in_array( 'Slideshow of 2 slides: only the first one is kept as a section. Other slides: Deuxième diapo.', $fluffless_report->todo, true ), 'D : première diapositive seulement, autres listées.' );
	$fluffless_assert( str_contains( $fluffless_d_html, 'wp:query {"query":{"perPage":4,' ) && str_contains( $fluffless_d_html, '"postType":"post"' ) && str_contains( $fluffless_d_html, '"columnCount":3' ), 'D : blogue en requête à quatre articles sur trois colonnes.' );
	$fluffless_assert( str_contains( $fluffless_d_html, 'wp:social-link {"url":"https://facebook.com/exemple","service":"facebook"}' ), 'D : réseau social suivi.' );
	$fluffless_assert( str_contains( $fluffless_d_html, '<div class="perso">"Code" [gardé]</div>' ), 'D : code HTML décodé.' );
	$fluffless_assert( 1 === substr_count( $fluffless_d_html, 'Texte du module global.' ), 'D : module global inséré depuis la mise en page enregistrée.' );
	$fluffless_assert( 1 === ( $fluffless_report->skipped['template'] ?? 0 ) && in_array( 'A saved template of the page builder (999999999) no longer exists: nothing was kept from it.', $fluffless_report->todo, true ), 'D : module qui boucle arrêté, module absent listé.' );
	$fluffless_assert( str_contains( $fluffless_d_html, 'Gadget mystère' ) && str_contains( $fluffless_d_html, 'Texte du gadget inconnu.' ) && ! str_contains( $fluffless_d_html, '#ff0000' ) && 1 === ( $fluffless_report->kept['mystery_module'] ?? 0 ), 'D : module inconnu gardé en texte sans couleur.' );
	$fluffless_assert( 1 === ( $fluffless_report->skipped['search'] ?? 0 ) && in_array( 'A “search” element has no native block: rebuild it with a block, a pattern or a Suite module.', $fluffless_report->todo, true ), 'D : recherche laissée de côté et listée.' );
	$fluffless_assert( str_contains( $fluffless_d_html, '<h3 class="wp-block-heading">Prêt ?</h3>' ) && str_contains( $fluffless_d_html, 'Allons-y' ) && ! str_contains( $fluffless_d_html, '#1f4e79' ) && ! str_contains( $fluffless_d_html, '#123456' ) && 3 === $fluffless_report->styles, 'D : appel à l’action, rangée intérieure aplatie, trois couleurs fixes écartées.' );
	$fluffless_assert( str_contains( $fluffless_d_html, '<h1 class="wp-block-heading">Titre plein</h1>' ) && str_contains( $fluffless_d_html, '<p>Sous-titre</p>' ) && str_contains( $fluffless_d_html, 'https://exemple.test/1' ), 'D : en-tête pleine largeur en titre, sous-titre et bouton.' );
	$fluffless_report = new Report();
	$fluffless_d_kept = Blocks::serialize( Divi::convert( $fluffless_d_tree, true, $fluffless_report ) );
	$fluffless_assert( str_contains( $fluffless_d_kept, '#1f4e79' ) && str_contains( $fluffless_d_kept, '#123456' ) && 0 === $fluffless_report->styles, 'D : couleurs fixes gardées sur demande.' );

	// Constructeur D : attribut encodé en JSON (version 5, états de survol) : module gardé en
	// texte plutôt que converti avec une valeur à moitié lue.
	$fluffless_d_json_source = '[et_pb_button button_text="Cliquez ici" button_url="https://exemple.test/dej" button_bg_color="{%22desktop%22:{%22value%22:%22#1f4e79%22}}" /]'
		. '[et_pb_image src="{%22desktop%22:{%22value%22:%22https://exemple.test/photo.jpg%22}}" alt="Photo" /]';
	$fluffless_d_json_tree   = Divi::parse( $fluffless_d_json_source );
	$fluffless_d_json_report = new Report();
	$fluffless_d_json_blocks = Divi::convert( $fluffless_d_json_tree, false, $fluffless_d_json_report );
	$fluffless_d_json_html   = Blocks::serialize( $fluffless_d_json_blocks );
	$fluffless_assert( ! str_contains( $fluffless_d_json_html, 'desktop' ) && ! str_contains( $fluffless_d_json_html, '#1f4e79' ), 'D : attribut JSON (V5) jamais recopié tel quel dans un bloc.' );
	$fluffless_assert( str_contains( $fluffless_d_json_html, 'Cliquez ici' ) && 1 === ( $fluffless_d_json_report->kept['button'] ?? 0 ), 'D : bouton V5 gardé en texte plutôt que converti à moitié.' );
	$fluffless_assert( 1 === ( $fluffless_d_json_report->skipped['image'] ?? 0 ), 'D : image V5 sans rien à garder en texte, ignorée plutôt que fausse.' );
	$fluffless_assert(
		in_array( 'The "button" module uses page builder version 5 values (hover, responsive breakpoints) that this reader does not decode: check it in the editor.', $fluffless_d_json_report->todo, true )
		&& in_array( 'The "image" module uses page builder version 5 values (hover, responsive breakpoints) that this reader does not decode: check it in the editor.', $fluffless_d_json_report->todo, true ),
		'D : avertissement au rapport nommant le module concerné.'
	);

	// Constructeur D : attribut JSON (V5) chez un enfant (point de carte), pas chez le module lui-
	// même : passe sous le repère du module mais est quand même filtré avant d'être gardé en texte.
	$fluffless_d_map_source = '[et_pb_map][et_pb_map_pin title="Boutique du centre-ville" pin_style="{%22desktop%22:{%22value%22:%22red%22}}"]Adresse de la boutique[/et_pb_map_pin][/et_pb_map]';
	$fluffless_d_map_tree   = Divi::parse( $fluffless_d_map_source );
	$fluffless_d_map_report = new Report();
	$fluffless_d_map_blocks = Divi::convert( $fluffless_d_map_tree, false, $fluffless_d_map_report );
	$fluffless_d_map_html   = Blocks::serialize( $fluffless_d_map_blocks );
	$fluffless_assert( ! str_contains( $fluffless_d_map_html, 'desktop' ) && ! str_contains( $fluffless_d_map_html, 'pin_style' ), 'D : attribut JSON (V5) d’un point de carte jamais recopié tel quel dans un bloc.' );
	$fluffless_assert( str_contains( $fluffless_d_map_html, 'Boutique du centre-ville' ) && str_contains( $fluffless_d_map_html, 'Adresse de la boutique' ), 'D : texte du point de carte gardé malgré l’attribut JSON filtré.' );

	// Constructeur D : repérage, conversion, remise en place.
	$fluffless_d_page  = fluffless_make_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Page du constructeur D (test)',
			'post_content' => $fluffless_d_source,
		),
		array( Divi::META_MODE => 'on' )
	);
	$fluffless_pages[] = $fluffless_d_page;
	$fluffless_assert( Divi::supports( $fluffless_d_page ) && ! Divi::supports( $fluffless_plain ) && ! Elementor::supports( $fluffless_d_page ) && Divi::class === Migrator::reader( $fluffless_d_page ), 'D : prise en charge et lecteur choisi.' );
	$fluffless_row = fluffless_find_row( Migrator::detect(), $fluffless_d_page );
	$fluffless_assert( null !== $fluffless_row && 'divi' === $fluffless_row['builder'] && array_sum( $fluffless_d_counts ) === $fluffless_row['elements'] && '' === $fluffless_row['migrated'], 'D : ligne repérée avec son lecteur et ses éléments.' );
	$fluffless_result = Migrator::convert( $fluffless_d_page, false );
	$fluffless_assert( $fluffless_result['ok'] && 'converted' === $fluffless_result['code'], 'D : conversion réussie.' );
	$fluffless_backup = get_post_meta( $fluffless_d_page, Migrator::META_BACKUP, true );
	$fluffless_assert( is_array( $fluffless_backup ) && $fluffless_d_source === $fluffless_backup['content'] && 'on' === $fluffless_backup['mode'] && 'divi' === $fluffless_backup['builder'], 'D : copie d’avant avec marqueur et lecteur.' );
	$fluffless_assert( 'off' === get_post_meta( $fluffless_d_page, Divi::META_MODE, true ) && str_starts_with( (string) get_post_field( 'post_content', $fluffless_d_page ), '<!-- wp:group' ), 'D : marqueur à off, contenu en blocs.' );
	$fluffless_row = fluffless_find_row( Migrator::detect(), $fluffless_d_page );
	$fluffless_assert( null !== $fluffless_row && 'divi' === $fluffless_row['builder'] && '' !== $fluffless_row['migrated'] && array_sum( $fluffless_d_counts ) === $fluffless_row['elements'], 'D : page convertie encore listée depuis sa copie.' );
	$fluffless_result = Migrator::convert( $fluffless_d_page, true );
	$fluffless_backup = get_post_meta( $fluffless_d_page, Migrator::META_BACKUP, true );
	$fluffless_assert( $fluffless_result['ok'] && is_array( $fluffless_backup ) && $fluffless_d_source === $fluffless_backup['content'] && str_contains( (string) get_post_field( 'post_content', $fluffless_d_page ), '#1f4e79' ), 'D : seconde conversion depuis la copie, couleurs gardées.' );
	$fluffless_reverted = Migrator::revert( $fluffless_d_page );
	$fluffless_content  = get_post_field( 'post_content', $fluffless_d_page );
	$fluffless_assert( $fluffless_reverted && $fluffless_d_source === $fluffless_content && 'on' === get_post_meta( $fluffless_d_page, Divi::META_MODE, true ) && '' === get_post_meta( $fluffless_d_page, Migrator::META_DONE, true ), 'D : remise en place avec marqueur à on.' );

	// Constructeur D : gabarits du constructeur de thème et réglages globaux.
	$fluffless_templates = Migrator::templates();
	$fluffless_assert( in_array( 'En-tête du thème D (test)', $fluffless_templates['header'] ?? array(), true ) && ! in_array( 'Module global (test)', array_merge( ...array_values( $fluffless_templates ) ), true ), 'D : en-tête listé, mise en page enregistrée non listée.' );
	delete_option( Elementor::OPTION_KIT );
	delete_option( Divi::OPTION_THEME );
	delete_option( Divi::OPTION_COLORS );
	$fluffless_assert( null === Migrator::globals(), 'D : sans réglages, rien de proposé.' );
	update_option(
		Divi::OPTION_THEME,
		array(
			'font_color'             => '#222',
			'accent_color'           => '#1f4e79',
			'link_color'             => 'rgba(1,2,3,0.5)',
			'all_buttons_bg_color'   => '#1f4e79',
			'all_buttons_text_color' => '#ffffff',
			'body_font'              => 'Outfit',
			'heading_font'           => 'Gloock',
		)
	);
	update_option(
		Divi::OPTION_COLORS,
		array(
			'gcid-primary-color' => array(
				'color'  => '#abcdef',
				'active' => 'yes',
			),
			'gcid-old'           => array(
				'color'  => '#000000',
				'active' => 'no',
			),
		)
	);
	$fluffless_d_globals = Divi::globals();
	$fluffless_assert( '#222222' === $fluffless_d_globals['colors']['text'] && '#1f4e79' === $fluffless_d_globals['colors']['primary'] && ! isset( $fluffless_d_globals['colors']['link'] ) && '#abcdef' === $fluffless_d_globals['colors']['primary-color'] && ! isset( $fluffless_d_globals['colors']['old'] ), 'D : couleurs du thème et couleurs globales actives, rgba écarté.' );
	$fluffless_assert( 'Outfit' === $fluffless_d_globals['families']['body'] && 'Gloock' === $fluffless_d_globals['families']['primary'], 'D : familles relues.' );
	$fluffless_proposal = Migrator::globals();
	$fluffless_assert( null !== $fluffless_proposal && 'divi' === $fluffless_proposal['builder'] && 9 === $fluffless_proposal['pair'] && null !== $fluffless_proposal['palette'], 'D : proposition avec duo Gloock et Outfit et palette candidate.' );
	delete_option( Divi::OPTION_THEME );
	delete_option( Divi::OPTION_COLORS );
	if ( false !== $fluffless_kit_before ) {
		update_option( Elementor::OPTION_KIT, $fluffless_kit_before );
	}

	// Écran.
	ob_start();
	Migration::render();
	$fluffless_screen = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_screen, 'Page du constructeur (test)' ) && str_contains( $fluffless_screen, 'name="convert" value="' . $fluffless_page . '"' ) && str_contains( $fluffless_screen, 'name="keep_colors"' ), 'Écran : page listée avec son bouton et la case des couleurs.' );
	$fluffless_assert( ! str_contains( $fluffless_screen, 'Page ordinaire (test)' ) && ! str_contains( $fluffless_screen, '<script' ), 'Écran : page ordinaire absente, aucun script.' );
	$fluffless_assert( false === stripos( $fluffless_screen, 'elementor' ), 'Écran : aucun nom de constructeur.' );
	$_GET['fl_migrated'] = (string) $fluffless_page;
	Migrator::convert( $fluffless_page, false );
	ob_start();
	Migration::render();
	$fluffless_screen = (string) ob_get_clean();
	unset( $_GET['fl_migrated'] );
	$fluffless_assert( str_contains( $fluffless_screen, 'Fluffless SEO imports them.' ), 'Rapport : renvoi vers l’import de Fluffless SEO.' );
	$fluffless_assert( class_exists( 'Fluffless\\Seo\\Plugin' ) === str_contains( $fluffless_screen, 'page=fluffless-seo&#038;tab=import' ) || class_exists( 'Fluffless\\Seo\\Plugin' ) === str_contains( $fluffless_screen, 'page=fluffless-seo&tab=import' ), 'Rapport : lien vers l’onglet d’importation seulement si Fluffless SEO est active.' );
	$fluffless_assert( str_contains( $fluffless_screen, 'name="revert" value="' . $fluffless_page . '"' ) && str_contains( $fluffless_screen, 'mystery-widget (1)' ) && str_contains( $fluffless_screen, 'Courriel (email)' ), 'Écran : bouton Remettre, rapport avec gadget inconnu et formulaire.' );
	wp_set_current_user( 0 );
	ob_start();
	Migration::render();
	$fluffless_assert( '' === ob_get_clean(), 'Écran vide pour un visiteur.' );
	wp_set_current_user( 1 );

	// WP-CLI : liste, essai à blanc, conversion, remise en place ; journal d'activité.
	$fluffless_pending = static fn(): int => count( array_filter( Migrator::detect(), static fn( array $page ): bool => '' === $page['migrated'] ) );
	$fluffless_pages[] = fluffless_make_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Page du lot (test)',
			'post_content' => '',
		),
		array(
			Elementor::META_DATA => wp_slash( (string) wp_json_encode( $fluffless_tree ) ),
			Elementor::META_MODE => 'builder',
		)
	);
	$fluffless_before  = $fluffless_pending();
	$fluffless_assert( $fluffless_before >= 2, 'Au moins deux pages encore à convertir avant le lot.' );
	$fluffless_cli = Migration::cli_migrate( array(), array() );
	$fluffless_assert( 'list' === $fluffless_cli['action'] && $fluffless_before === $fluffless_cli['remaining'], 'WP-CLI sans option : liste avec le compte des pages à convertir.' );
	$fluffless_cli = Migration::cli_migrate( array(), array( 'all' => true ) );
	$fluffless_assert( 'list' === $fluffless_cli['action'] && $fluffless_before === $fluffless_pending(), 'WP-CLI --all à blanc : rien de converti.' );
	$fluffless_cli = Migration::cli_migrate( array(), array( 'post' => $fluffless_d_page ) );
	$fluffless_assert( 'post' === $fluffless_cli['action'] && 1 === $fluffless_cli['done'] && '' === (string) get_post_meta( $fluffless_d_page, Migrator::META_DONE, true ), 'WP-CLI --post à blanc : prise en charge dite, page intacte.' );
	$fluffless_labels = Journal::labels();
	$fluffless_assert( count( array_intersect( array( 'page_converted', 'page_reverted' ), Journal::EVENTS ) ) === 2 && isset( $fluffless_labels['page_converted'], $fluffless_labels['page_reverted'] ), 'Journal : deux événements de migration reconnus et libellés.' );
	$fluffless_set( Security::id(), true );
	if ( ! Journal::table_exists() ) {
		Journal::install();
	}
	$fluffless_assert( Journal::enabled(), 'Journal en service pour le test.' );
	$fluffless_recent      = Journal::recent( 1 );
	$fluffless_journal_row = array() === $fluffless_recent ? 1 : $fluffless_recent[0]['id'] + 1;
	$fluffless_cli         = Migration::cli_migrate(
		array(),
		array(
			'post'    => $fluffless_d_page,
			'dry-run' => false,
		)
	);
	$fluffless_done        = get_post_meta( $fluffless_d_page, Migrator::META_DONE, true );
	$fluffless_assert( 1 === $fluffless_cli['done'] && is_array( $fluffless_done ) && 'divi' === $fluffless_done['builder'], 'WP-CLI --post --no-dry-run : page convertie.' );
	$fluffless_rows = array_filter( Journal::recent( 5 ), static fn( array $e ): bool => 'page_converted' === $e['event'] && '#' . $fluffless_d_page . ' Page du constructeur D (test)' === $e['subject'] && $e['id'] >= $fluffless_journal_row );
	$fluffless_assert( 1 === count( $fluffless_rows ), 'Journal : conversion notée avec identifiant et titre.' );
	$fluffless_cli = Migration::cli_migrate(
		array(),
		array(
			'post'    => $fluffless_plain,
			'dry-run' => false,
		)
	);
	$fluffless_assert( 0 === $fluffless_cli['done'] && 1 === $fluffless_cli['failed'], 'WP-CLI --post sur une page ordinaire : refus compté.' );
	$fluffless_cli = Migration::cli_migrate(
		array(),
		array(
			'revert'  => $fluffless_d_page,
			'dry-run' => false,
		)
	);
	$fluffless_assert( 'revert' === $fluffless_cli['action'] && 1 === $fluffless_cli['done'] && 'on' === get_post_meta( $fluffless_d_page, Divi::META_MODE, true ), 'WP-CLI --revert --no-dry-run : page remise en place.' );
	$fluffless_rows = array_filter( Journal::recent( 5 ), static fn( array $e ): bool => 'page_reverted' === $e['event'] && $e['id'] >= $fluffless_journal_row );
	$fluffless_assert( 1 === count( $fluffless_rows ), 'Journal : remise en place notée.' );
	$fluffless_cli = Migration::cli_migrate( array(), array( 'revert' => $fluffless_plain ) );
	$fluffless_assert( 0 === $fluffless_cli['done'] && 1 === $fluffless_cli['failed'], 'WP-CLI --revert sans copie d’avant : rien à remettre.' );
	$fluffless_set( Security::id(), false );
	Migrator::convert( $fluffless_d_page, false );
	$fluffless_assert( count( array_filter( Journal::recent( 5 ), static fn( array $e ): bool => 'page_converted' === $e['event'] && $e['id'] >= $fluffless_journal_row ) ) === 1, 'Journal éteint : conversion non notée.' );
	Migrator::revert( $fluffless_d_page );

	// Lot : reprise, page déjà convertie sautée, rien à reprendre.
	$fluffless_batch = Migrator::convert_all( false, 1 );
	$fluffless_assert( 1 === $fluffless_batch['converted'] + $fluffless_batch['failed'] && $fluffless_before - 1 === $fluffless_batch['remaining'] && $fluffless_before - 1 === $fluffless_pending(), 'Lot de 1 : une page traitée, reste compté.' );
	$fluffless_batch = Migrator::convert_all( false, Migrator::LIMIT );
	$fluffless_assert( $fluffless_before - 1 === $fluffless_batch['converted'] + $fluffless_batch['failed'] && 0 === $fluffless_batch['remaining'] && 0 === $fluffless_pending(), 'Reprise : le reste converti en une passe.' );
	$fluffless_batch = Migrator::convert_all( false );
	$fluffless_assert( 0 === $fluffless_batch['converted'] && 0 === $fluffless_batch['failed'] && 0 === $fluffless_batch['remaining'], 'Lot vide : rien à reprendre, pages converties sautées.' );
	$fluffless_cli = Migration::cli_migrate(
		array(),
		array(
			'all'     => true,
			'dry-run' => false,
		)
	);
	$fluffless_assert( 'all' === $fluffless_cli['action'] && 0 === $fluffless_cli['done'] && 0 === $fluffless_cli['remaining'], 'WP-CLI --all --no-dry-run : plus rien à convertir.' );
	$fluffless_url = Migration::run( array( 'convert_all' => '1' ) );
	$fluffless_assert( str_contains( $fluffless_url, 'fl_batch=0' ) && str_contains( $fluffless_url, 'fl_remaining=0' ), 'Formulaire : bouton du lot renvoie les comptes.' );
	ob_start();
	Migration::render();
	$fluffless_screen = (string) ob_get_clean();
	$fluffless_assert( ! str_contains( $fluffless_screen, 'name="convert_all"' ), 'Écran : bouton du lot absent quand tout est converti.' );
	Migrator::revert( $fluffless_d_page );
	$_GET['fl_batch']     = '3';
	$_GET['fl_failed']    = '0';
	$_GET['fl_remaining'] = '2';
	ob_start();
	Migration::render();
	$fluffless_screen = (string) ob_get_clean();
	unset( $_GET['fl_batch'], $_GET['fl_failed'], $_GET['fl_remaining'] );
	$fluffless_assert( str_contains( $fluffless_screen, '3 page(s) converted, 0 failed, 2 still to do. Press the button again to continue.' ) && str_contains( $fluffless_screen, 'name="convert_all"' ) && str_contains( $fluffless_screen, 'Convert the 1 page still to do' ), 'Écran : note du lot et bouton avec le compte des pages à faire.' );
} finally {
	if ( $fluffless_journal_row > 0 && Journal::table_exists() ) {
		global $wpdb;
		$fluffless_table = Journal::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nettoyage des lignes de test.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$fluffless_table} WHERE id >= %d", $fluffless_journal_row ) );
	}
	if ( $fluffless_palette_applied ) {
		Palette::reset();
	}
	foreach ( $fluffless_pages as $fluffless_id ) {
		wp_delete_post( $fluffless_id, true );
	}
	if ( false === $fluffless_kit_before ) {
		delete_option( Elementor::OPTION_KIT );
	} else {
		update_option( Elementor::OPTION_KIT, $fluffless_kit_before );
	}
	foreach ( array(
		Divi::OPTION_THEME  => $fluffless_theme_before,
		Divi::OPTION_COLORS => $fluffless_colors_before,
	) as $fluffless_option => $fluffless_value ) {
		if ( false === $fluffless_value ) {
			delete_option( $fluffless_option );
		} else {
			update_option( $fluffless_option, $fluffless_value );
		}
	}
	update_option( Options::NAME, $fluffless_settings_before );
	Options::flush();
	wp_set_current_user( $fluffless_user_before );
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		WP_CLI::warning( $fluffless_failure );
	}
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . ' vérifications.' );
}
WP_CLI::success( 'Migration depuis un constructeur : ' . $fluffless_checks . ' vérifications passées.' );
