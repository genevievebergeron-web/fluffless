<?php
/**
 * Tests des blocs modulables (src/Blocks/Sections.php) : block.json et PHP d'accord sur les
 * réglages, attributs bornés, rendu serveur de chaque bloc, image de fond décorative, lien de
 * téléchargement avec format et taille, CSS chargé seulement si un bloc est rendu.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/sections-test.php
 * Les pièces jointes et fichiers créés, et les blocs enregistrés par le test, sont retirés à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Blocks\Sections;
use Fluffless\Suite\Modules\Expression;
use Fluffless\Suite\Modules\Form;
use Fluffless\Suite\Presentation\Ids;

defined( 'ABSPATH' ) || exit;

// Les contrôles lisent les textes en en_US : indépendants de la langue du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_registry = WP_Block_Type_Registry::get_instance();
$fluffless_names    = array_merge(
	array( Expression::VAGUE, 'fluffless/recit', Expression::POPUP ),
	array_map( static fn( $slug ) => 'fluffless/' . $slug, array_keys( Sections::OPTIONS ) ),
	array( 'fluffless/' . Sections::BAR )
);
$fluffless_before   = array_filter( $fluffless_names, array( $fluffless_registry, 'is_registered' ) );
$fluffless_posts    = array();
$fluffless_pages    = array();
$fluffless_files    = array();
$fluffless_dir      = dirname( __DIR__ ) . '/blocks/';
$fluffless_forms    = get_option( Form::OPTION );
$fluffless_had_form = $fluffless_registry->is_registered( Form::FORM );
$fluffless_mails    = array();
$fluffless_capture  = static function ( $short, array $atts ) use ( &$fluffless_mails ) {
	unset( $short );
	$fluffless_mails[] = $atts;
	return true;
};

/**
 * Pièce jointe de test : un fichier réel de $bytes octets dans le dossier des téléversements.
 *
 * @param string $name  Nom du fichier.
 * @param string $mime  Type MIME.
 * @param int    $bytes Taille.
 * @param string $title Titre.
 * @return array{0: int, 1: string} Identifiant et chemin.
 */
$fluffless_attach = static function ( string $name, string $mime, int $bytes, string $title ): array {
	$uploads = wp_upload_dir();
	$path    = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], $name );
	file_put_contents( $path, str_repeat( 'x', $bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => $mime,
			'post_title'     => $title,
			'post_status'    => 'inherit',
		),
		$path,
		0,
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( esc_html( 'Pièce jointe de test refusée : ' . $id->get_error_message() ) );
	}
	return array( $id, $path );
};

try {
	// 0. Le module Expressivité est inactif par défaut (donc sur la CI) : ses blocs et les crochets de
	// Sections::register (titres, vidéo, consentement) sont posés ici au besoin, retirés à la fin.
	if ( count( $fluffless_before ) < count( $fluffless_names ) ) {
		Expression::register_block();
	}

	// 1. block.json et Sections::OPTIONS disent la même chose : valeurs, défaut, feuille commune.
	foreach ( Sections::OPTIONS as $fluffless_slug => $fluffless_options ) {
		$fluffless_json = wp_json_file_decode( $fluffless_dir . $fluffless_slug . '/block.json', array( 'associative' => true ) );
		$fluffless_assert( is_array( $fluffless_json ) && ( $fluffless_json['name'] ?? '' ) === 'fluffless/' . $fluffless_slug, "{$fluffless_slug} : block.json absent ou mal nommé" );
		$fluffless_json = is_array( $fluffless_json ) ? $fluffless_json : array();
		foreach ( $fluffless_options as $fluffless_name => $fluffless_allowed ) {
			$fluffless_attr = $fluffless_json['attributes'][ $fluffless_name ] ?? array();
			$fluffless_assert( ( $fluffless_attr['enum'] ?? null ) === $fluffless_allowed, "{$fluffless_slug}.{$fluffless_name} : enum différent de Sections::OPTIONS" );
			$fluffless_assert( ( $fluffless_attr['default'] ?? null ) === $fluffless_allowed[0], "{$fluffless_slug}.{$fluffless_name} : défaut différent de la première valeur" );
		}
		$fluffless_style = (array) ( $fluffless_json['style'] ?? array() );
		$fluffless_assert( in_array( Sections::STYLE, $fluffless_style, true ), "{$fluffless_slug} : la feuille commune doit être déclarée dans style" );
		$fluffless_assert( ! isset( $fluffless_json['viewScript'] ) && ! isset( $fluffless_json['script'] ), "{$fluffless_slug} : aucun script classique côté visiteur" );
		if ( in_array( $fluffless_slug, Sections::VIEW, true ) ) {
			$fluffless_view = $fluffless_dir . $fluffless_slug . '/view.js';
			$fluffless_assert( 'file:./view.js' === ( $fluffless_json['viewScriptModule'] ?? null ) && file_exists( $fluffless_view ) && filesize( $fluffless_view ) < 1024, "{$fluffless_slug} : module view.js attendu, sous 1 ko" );
		} else {
			$fluffless_assert( ! isset( $fluffless_json['viewScriptModule'] ), "{$fluffless_slug} : aucun script côté visiteur" );
		}
		$fluffless_css = $fluffless_dir . $fluffless_slug . '/style.css';
		if ( file_exists( $fluffless_css ) ) {
			$fluffless_assert( filesize( $fluffless_css ) < ( in_array( $fluffless_slug, Sections::WIDE_CSS, true ) ? 2048 : 1024 ), "{$fluffless_slug} : style.css au-delà du budget (1 ko, 2 ko pour " . implode( ', ', Sections::WIDE_CSS ) . ')' );
			$fluffless_assert( 1 !== preg_match( '/#[0-9a-f]{3,8}\b/i', (string) file_get_contents( $fluffless_css ) ), "{$fluffless_slug} : couleur HEX dans style.css" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}
	$fluffless_assert( strlen( Sections::shared_css() ) < 1024, 'CSS commun au-delà de 1 ko' );
	$fluffless_assert( 1 !== preg_match( '/#[0-9a-f]{3,8}\b/i', Sections::shared_css() ), 'couleur HEX dans le CSS commun' );

	// 2. Attributs : défauts, valeurs hors liste, identifiant de fichier, classes assainies.
	$fluffless_a = Sections::attributes( 'texte-photo', array() );
	$fluffless_assert( 'end' === $fluffless_a['media'] && '50-50' === $fluffless_a['ratio'] && 'surface' === $fluffless_a['background'], 'texte-photo : défauts inattendus' );
	$fluffless_a = Sections::attributes(
		'liste',
		array(
			'marker'    => 'etoile',
			'columns'   => '2',
			'align'     => 'centre',
			'className' => 'ok x"><script>',
		)
	);
	$fluffless_assert( 'bullet' === $fluffless_a['marker'], 'liste : marqueur hors liste accepté' );
	$fluffless_assert( 1 === $fluffless_a['columns'], 'liste : colonnes en chaîne acceptées (le type doit être un entier)' );
	$fluffless_assert( '' === $fluffless_a['align'], 'alignement hors liste accepté' );
	$fluffless_assert( ! str_contains( (string) $fluffless_a['className'], '<' ) && ! str_contains( (string) $fluffless_a['className'], '"' ), 'className non assaini' );
	$fluffless_assert( 2 === Sections::attributes( 'liste', array( 'columns' => 2 ) )['columns'], 'liste : 2 colonnes refusées' );
	$fluffless_assert( 0 === Sections::attributes( 'ressource', array( 'fileId' => -4 ) )['media_id'], 'ressource : identifiant négatif accepté' );
	$fluffless_assert( 0 === Sections::attributes( 'ressource', array( 'fileId' => '12' ) )['media_id'], 'ressource : identifiant en chaîne accepté' );
	$fluffless_assert( 12 === Sections::attributes( 'appel-action', array( 'imageId' => 12 ) )['media_id'], 'appel-action : identifiant valide refusé' );

	// 3. Rendu générique : enveloppe à classes, contenu intact, alignement.
	$fluffless_inner = '<h2 class="wp-block-heading">Titre</h2><p>Texte</p>';
	$fluffless_html  = Sections::render(
		'texte-photo',
		array(
			'media'      => 'start',
			'ratio'      => '60-40',
			'background' => 'accent',
			'align'      => 'wide',
		),
		$fluffless_inner
	);
	$fluffless_assert( str_starts_with( $fluffless_html, '<div class="wp-block-fluffless-texte-photo fluffless-section fluffless-texte-photo is-media-start is-ratio-60-40 is-background-accent alignwide">' ), 'texte-photo : enveloppe inattendue : ' . substr( $fluffless_html, 0, 160 ) );
	$fluffless_assert( str_contains( $fluffless_html, $fluffless_inner ), 'texte-photo : contenu des blocs enfants modifié' );
	$fluffless_assert(
		str_contains(
			Sections::render(
				'liste',
				array(
					'marker'  => 'number',
					'columns' => 2,
				),
				''
			),
			'is-marker-number is-columns-2'
		),
		'liste : classes absentes'
	);
	$fluffless_assert( str_contains( Sections::render( 'colonnes', array(), '' ), 'class="wp-block-fluffless-colonnes fluffless-section fluffless-colonnes"' ), 'colonnes : enveloppe inattendue' );
	$fluffless_assert( str_contains( Sections::render( 'temoignages', array(), 'Q' ), 'fluffless-temoignages">Q</div>' ), 'temoignages : enveloppe inattendue' );
	$fluffless_assert( str_contains( Sections::render( 'appel-action', array(), '' ), 'is-background-accent is-justify-center is-size-generous' ), 'appel-action : défauts absents' );
	$fluffless_assert( '' !== Sections::render( 'liste', 'x', null ), 'attributs non tableau : rendu par défaut attendu' );

	// 3 bis. Vague 2 : défauts et valeurs hors liste de chaque bloc.
	$fluffless_assert( str_contains( Sections::render( 'citation', array(), '' ), 'fluffless-citation is-size-large is-mark-rule"' ), 'citation : défauts absents' );
	$fluffless_a = Sections::attributes(
		'citation',
		array(
			'size' => 'geant',
			'mark' => 'quotes',
		)
	);
	$fluffless_assert( 'large' === $fluffless_a['size'] && 'quotes' === $fluffless_a['mark'], 'citation : taille hors liste acceptée ou guillemets refusés' );
	$fluffless_assert( str_contains( Sections::render( 'valeurs', array( 'display' => 'grille' ), '' ), 'fluffless-valeurs is-display-columns"' ), 'valeurs : disposition hors liste acceptée' );
	$fluffless_assert( str_contains( Sections::render( 'valeurs', array( 'display' => 'list' ), '' ), 'is-display-list' ), 'valeurs : liste refusée' );
	$fluffless_assert( str_contains( Sections::render( 'qui-nous-sommes', array( 'align' => 'full' ), 'Q' ), 'class="wp-block-fluffless-qui-nous-sommes fluffless-section fluffless-qui-nous-sommes alignfull">Q</div>' ), 'qui-nous-sommes : enveloppe inattendue' );
	$fluffless_assert( str_contains( Sections::render( 'equipe', array( 'photo' => 'ovale' ), '' ), 'fluffless-equipe is-photo-round"' ), 'equipe : forme de photo hors liste acceptée' );
	$fluffless_assert( str_contains( Sections::render( 'equipe', array( 'photo' => 'square' ), '' ), 'is-photo-square' ), 'equipe : photo carrée refusée' );
	$fluffless_a = Sections::attributes(
		'galerie',
		array(
			'arrangement' => 'carrousel',
			'captions'    => true,
		)
	);
	$fluffless_assert( 'grid' === $fluffless_a['arrangement'] && 'shown' === $fluffless_a['captions'], 'galerie : valeurs hors liste acceptées' );
	$fluffless_assert(
		str_contains(
			Sections::render(
				'galerie',
				array(
					'arrangement' => 'mosaic',
					'captions'    => 'hidden',
				),
				''
			),
			'is-arrangement-mosaic is-captions-hidden'
		),
		'galerie : classes absentes'
	);

	// 3 ter. Vague 3 : défauts, valeurs hors liste, et la FAQ lue par le module SEO.
	$fluffless_assert( str_contains( Sections::render( 'services', array( 'display' => 'tuiles' ), '' ), 'fluffless-services is-display-cards"' ), 'services : disposition hors liste acceptée' );
	$fluffless_assert( str_contains( Sections::render( 'services', array( 'display' => 'list' ), '' ), 'is-display-list' ), 'services : liste refusée' );
	$fluffless_assert( str_contains( Sections::render( 'questions', array( 'align' => 'wide' ), 'Q' ), 'class="wp-block-fluffless-questions fluffless-section fluffless-questions alignwide">Q</div>' ), 'questions : enveloppe inattendue' );
	$fluffless_assert( str_contains( Sections::render( 'chiffres', array( 'size' => 'geant' ), '' ), 'fluffless-chiffres is-size-large"' ), 'chiffres : taille hors liste acceptée' );
	$fluffless_assert( str_contains( Sections::render( 'chiffres', array( 'size' => 'compact' ), '' ), 'is-size-compact' ), 'chiffres : compact refusé' );
	$fluffless_a = Sections::attributes(
		'logos',
		array(
			'arrangement' => 'carrousel',
			'tone'        => 'sepia',
		)
	);
	$fluffless_assert( 'grid' === $fluffless_a['arrangement'] && 'mono' === $fluffless_a['tone'], 'logos : valeurs hors liste acceptées' );
	$fluffless_assert(
		str_contains(
			Sections::render(
				'logos',
				array(
					'arrangement' => 'row',
					'tone'        => 'color',
				),
				''
			),
			'is-arrangement-row is-tone-color'
		),
		'logos : classes absentes'
	);
	$fluffless_faq   = new WP_Post(
		(object) array(
			'ID'           => 0,
			'post_content' => '<!-- wp:fluffless/questions --><!-- wp:heading --><h2 class="wp-block-heading">FAQ</h2><!-- /wp:heading -->'
				. '<!-- wp:group {"className":"fluffless-questions__list","layout":{"type":"default"}} --><div class="wp-block-group fluffless-questions__list">'
				. '<!-- wp:details --><details class="wp-block-details"><summary>Combien ?</summary><!-- wp:paragraph --><p>Un prix de départ.</p><!-- /wp:paragraph --></details><!-- /wp:details -->'
				. '<!-- wp:details --><details class="wp-block-details"><summary>Quand ?</summary><!-- wp:paragraph --><p>Un délai réaliste.</p><!-- /wp:paragraph --></details><!-- /wp:details -->'
				. '</div><!-- /wp:group --><!-- /wp:fluffless/questions -->',
		)
	);
	$fluffless_pairs = \Fluffless\Suite\Modules\Seo::faq_pairs( $fluffless_faq );
	$fluffless_assert( 2 === count( $fluffless_pairs ) && 'Combien ?' === $fluffless_pairs[0][0] && str_contains( $fluffless_pairs[1][1], 'délai' ), 'questions : le module SEO ne lit pas les questions du bloc' );

	// 3 quater. Vague 4 : défauts, valeurs hors liste, et les Détails des onglets groupés par un name propre au rendu.
	$fluffless_assert( str_contains( Sections::render( 'onglets', array( 'display' => 'wizard' ), '' ), 'fluffless-onglets is-display-tabs"' ), 'onglets : affichage hors liste accepté' );
	$fluffless_assert( str_contains( Sections::render( 'onglets', array( 'display' => 'accordion' ), '' ), 'is-display-accordion' ), 'onglets : accordéon refusé' );
	$fluffless_html = Sections::render( 'onglets', array(), '<details class="wp-block-details"><summary>A</summary><p>a</p></details><details class="wp-block-details" name="mien"><summary>B</summary></details>' );
	$fluffless_assert( 1 === preg_match( '/<details name="(fluffless-onglets-[a-f0-9]{8}(?:-\d+)?)" class="wp-block-details"><summary>A/', $fluffless_html, $fluffless_m ), 'onglets : name absent sur le premier Détails : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, 'name="mien"' ) && ! str_contains( $fluffless_html, 'name="mien" name=' ), 'onglets : un name existant doit rester' );
	$fluffless_html2 = Sections::render( 'onglets', array(), '<details><summary>C</summary></details>' );
	$fluffless_assert( 1 === preg_match( '/name="(fluffless-onglets-[a-f0-9]{8}(?:-\d+)?)"/', $fluffless_html2, $fluffless_m2 ) && ( $fluffless_m[1] ?? '' ) !== $fluffless_m2[1], 'onglets : deux blocs doivent recevoir des names différents' );
	$fluffless_assert( ! str_contains( Sections::render( 'questions', array(), '<details><summary>Q</summary></details>' ), 'name=' ), 'questions : aucun name ne doit être posé hors des onglets' );
	$fluffless_assert( str_contains( Sections::render( 'carrousel', array( 'width' => 'quarter' ), '' ), 'fluffless-carrousel is-width-third"' ), 'carrousel : largeur hors liste acceptée' );
	$fluffless_assert( str_contains( Sections::render( 'carrousel', array( 'width' => 'full' ), '' ), 'is-width-full' ), 'carrousel : pleine largeur refusée' );
	// Carrousel au clavier (C-S3-01) : piste focalisable et nommée, diapositives repérables, liens d'ancrage sans JavaScript.
	$fluffless_track  = '<h2>Titre</h2><div class="wp-block-group fluffless-carrousel__track">';
	$fluffless_track .= '<div class="wp-block-group fluffless-carrousel__item"><p>A</p></div><div class="wp-block-group fluffless-carrousel__item"><p>B</p></div><div class="wp-block-group fluffless-carrousel__item"><p>C</p></div></div>';
	$fluffless_html   = Sections::render( 'carrousel', array(), $fluffless_track );
	$fluffless_assert( 1 === preg_match( '/<div[^>]*class="wp-block-group fluffless-carrousel__track"[^>]*>/', $fluffless_html, $fluffless_m ) && str_contains( $fluffless_m[0], 'tabindex="0"' ) && str_contains( $fluffless_m[0], 'role="region"' ) && str_contains( $fluffless_m[0], 'aria-label="' ), 'carrousel : la piste doit avoir tabindex, role et nom : ' . $fluffless_html );
	$fluffless_assert( 3 === preg_match_all( '/<div(?=[^>]* id="[^"]+")[^>]*fluffless-carrousel__item"/', $fluffless_html ), 'carrousel : chaque diapositive doit avoir un id' );
	$fluffless_assert( 3 === preg_match_all( '/<a href="#[^"]+"[^>]*>/', $fluffless_html ), 'carrousel : un lien d\'ancrage par diapositive' );
	$fluffless_assert( 1 === preg_match( '/aria-label="[^"]*1[^"]*3[^"]*"/', $fluffless_html ), 'carrousel : la position (1 sur 3) doit être annoncée' );
	$fluffless_assert( Sections::render( 'carrousel', array(), $fluffless_track ) !== $fluffless_html, 'carrousel : deux blocs doivent recevoir des id différents' );
	$fluffless_assert( ! str_contains( Sections::render( 'carrousel', array(), '' ), '<nav' ), 'carrousel : sans diapositive, aucune navigation' );
	// K6b : compte par classe (P11), identifiants des doublons (P12), noms distincts (M1).
	$fluffless_track2  = '<div class="wp-block-group fluffless-carrousel__track">';
	$fluffless_track2 .= '<div class="wp-block-group fluffless-carrousel__item has-background"><p>A</p></div><div class="wp-block-group fluffless-carrousel__item"><p>B</p></div><div class="has-background fluffless-carrousel__item has-text-color"><p>C</p></div></div>';
	Ids::reset();
	$fluffless_first  = Sections::render( 'carrousel', array(), $fluffless_track2 );
	$fluffless_second = Sections::render( 'carrousel', array(), $fluffless_track2 );
	$fluffless_assert( 6 === preg_match_all( '/aria-label="[^"]*\d[^"]*\b3"/', $fluffless_first ), 'carrousel : une diapositive qui porte une autre classe après « item » doit être comptée (3 sur 3) : ' . $fluffless_first );
	$fluffless_assert( 0 === preg_match( '/aria-label="[^"]*\b2"/', $fluffless_first ), 'carrousel : le total annoncé ne doit pas être 2' );
	preg_match_all( '/ id="([^"]+)"/', $fluffless_first . $fluffless_second, $fluffless_ids );
	$fluffless_assert( 6 === count( $fluffless_ids[1] ) && count( array_unique( $fluffless_ids[1] ) ) === 6, 'carrousel : deux blocs au contenu identique doivent avoir six identifiants tous distincts : ' . implode( ',', $fluffless_ids[1] ) );
	$fluffless_assert( 1 === preg_match( '/id="(fluffless-carrousel-[a-f0-9]{8})-1"/', $fluffless_first, $fluffless_b1 ) && str_contains( $fluffless_second, 'id="' . $fluffless_b1[1] . '-2-1"' ), 'carrousel : le second bloc identique doit suffixer sa base (base-2)' );
	$fluffless_label   = static function ( string $html, string $pattern ): string {
		return 1 === preg_match( $pattern, $html, $fluffless_hit ) ? $fluffless_hit[1] : '';
	};
	$fluffless_region1 = $fluffless_label( $fluffless_first, '/aria-label="([^"]+)" role="region"/' );
	$fluffless_nav1    = $fluffless_label( $fluffless_first, '/<nav[^>]*aria-label="([^"]+)"/' );
	$fluffless_region2 = $fluffless_label( $fluffless_second, '/aria-label="([^"]+)" role="region"/' );
	$fluffless_nav2    = $fluffless_label( $fluffless_second, '/<nav[^>]*aria-label="([^"]+)"/' );
	$fluffless_assert( '' !== $fluffless_region1 && '' !== $fluffless_nav1 && $fluffless_region1 !== $fluffless_nav1, 'carrousel : la piste et la navigation doivent porter des noms différents (' . $fluffless_region1 . ' / ' . $fluffless_nav1 . ')' );
	$fluffless_assert( $fluffless_region1 !== $fluffless_region2 && $fluffless_nav1 !== $fluffless_nav2, 'carrousel : deux carrousels sur la page doivent avoir des noms différents' );
	$fluffless_assert( str_contains( Sections::render( 'tarifs', array( 'featured' => 7 ), '' ), 'fluffless-tarifs is-featured-0"' ), 'tarifs : rang hors liste accepté' );
	$fluffless_assert( str_contains( Sections::render( 'tarifs', array( 'featured' => '2' ), '' ), 'is-featured-0' ), 'tarifs : rang en chaîne accepté (entier attendu)' );
	$fluffless_assert( str_contains( Sections::render( 'tarifs', array( 'featured' => 2 ), '' ), 'is-featured-2' ), 'tarifs : deuxième plan refusé' );
	$fluffless_a = Sections::attributes(
		'frise',
		array(
			'direction' => 'diagonal',
			'marker'    => 'star',
		)
	);
	$fluffless_assert( 'vertical' === $fluffless_a['direction'] && 'dot' === $fluffless_a['marker'], 'frise : valeurs hors liste acceptées' );
	$fluffless_assert(
		str_contains(
			Sections::render(
				'frise',
				array(
					'direction' => 'horizontal',
					'marker'    => 'number',
				),
				''
			),
			'is-direction-horizontal is-marker-number'
		),
		'frise : classes absentes'
	);

	// 3 quinquies. Vague 5 : barres bornées, compte à rebours calculé par le serveur, liens de partage sans script, carte sans incorporation.
	$fluffless_html = Sections::render_barre(
		array(
			'label' => 'Sites <em>migrés</em><script>x</script>',
			'value' => 72,
		)
	);
	$fluffless_assert( str_contains( $fluffless_html, 'style="--fl-v:72%"' ) && str_contains( $fluffless_html, 'aria-hidden="true"' ), 'barre : piste absente : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, '<em>migrés</em>' ) && ! str_contains( $fluffless_html, '<script' ), 'barre : libellé mal filtré' );
	$fluffless_assert( str_contains( Sections::render_barre( array( 'value' => 140 ) ), '--fl-v:100%' ), 'barre : valeur non bornée à 100' );
	$fluffless_assert( str_contains( Sections::render_barre( array( 'value' => -3 ) ), '--fl-v:0%' ), 'barre : valeur non bornée à 0' );
	$fluffless_assert( str_contains( Sections::render_barre( array( 'value' => '38' ) ), '--fl-v:38%' ), 'barre : chaîne numérique refusée' );
	$fluffless_assert( str_contains( Sections::render_barre( array( 'value' => 'abc' ) ), '--fl-v:0%' ), 'barre : valeur non numérique acceptée' );
	$fluffless_assert( str_contains( Sections::render( 'barres', array( 'values' => 'hidden' ), '' ), 'fluffless-barres is-size-thin is-values-hidden"' ), 'barres : classes absentes' );
	$fluffless_assert( '' === Sections::countdown( array() ) && '' === Sections::countdown( array( 'date' => 'pas une date' ) ), 'compte à rebours : date vide ou illisible doit rendre vide' );
	$fluffless_future = wp_date( 'Y-m-d\TH:i', time() + 10 * DAY_IN_SECONDS );
	$fluffless_html   = Sections::countdown( array( 'date' => $fluffless_future ) );
	$fluffless_assert( 1 === preg_match( '/__number">(9|10|11)<\/span> <span class="fluffless-compte-a-rebours__unit">days left/', $fluffless_html ), 'compte à rebours : jours attendus : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, '<time class="fluffless-compte-a-rebours__date" datetime="' . $fluffless_future . '"' ), 'compte à rebours : élément time absent' );
	$fluffless_html = Sections::countdown( array( 'date' => wp_date( 'Y-m-d\TH:i', time() + 5 * HOUR_IN_SECONDS ) ) );
	$fluffless_assert( str_contains( $fluffless_html, 'hours left' ) && ! str_contains( $fluffless_html, 'days' ), 'compte à rebours : heures attendues le dernier jour : ' . $fluffless_html );
	$fluffless_assert( str_contains( Sections::countdown( array( 'date' => '2020-01-01T09:00' ) ), 'is-past">This date has passed.' ), 'compte à rebours : phrase par défaut après la date' );
	$fluffless_assert(
		str_contains(
			Sections::countdown(
				array(
					'date'  => '2020-01-01T09:00',
					'after' => 'Merci <b>à tous</b>',
				)
			),
			'is-past">Merci &lt;b&gt;à tous&lt;/b&gt;'
		),
		'compte à rebours : ligne après la date non échappée'
	);
	$fluffless_html = Sections::render( 'compte-a-rebours', array( 'date' => $fluffless_future ), '<h2>T</h2>' );
	$fluffless_assert( str_contains( $fluffless_html, '<h2>T</h2><p class="fluffless-compte-a-rebours__figure">' ), 'compte à rebours : chiffre absent après le contenu' );
	$fluffless_assert( ! str_contains( Sections::render( 'compte-a-rebours', array(), '' ), 'time' ), 'compte à rebours : rien ne doit suivre sans date' );
	$fluffless_html = Sections::render( 'partage', array(), '<p>P</p>' );
	$fluffless_assert( 5 === substr_count( $fluffless_html, '<li><a class="fluffless-partage__link"' ), 'partage : cinq liens attendus : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, 'href="https://www.linkedin.com/sharing/share-offsite/?url=' ) && str_contains( $fluffless_html, 'href="mailto:?subject=' ), 'partage : adresses de partage absentes' );
	$fluffless_assert( 4 === substr_count( $fluffless_html, 'rel="noopener noreferrer" target="_blank"' ) && ! str_contains( $fluffless_html, '<script' ), 'partage : target ou script inattendus' );
	$fluffless_assert( 5 === substr_count( $fluffless_html, 'class="screen-reader-text"' ) && str_contains( $fluffless_html, '>Share by email</span>' ) && str_contains( $fluffless_html, '>Share on Bluesky</span>' ), 'partage : libellés lisibles attendus : ' . $fluffless_html );
	$fluffless_assert( 4 === substr_count( $fluffless_html, '<svg ' ) && str_contains( $fluffless_html, 'wp-block-fluffless-reseaux__text">LinkedIn' ), 'partage : quatre pictogrammes et LinkedIn en texte attendus' );
	$fluffless_html = Sections::render(
		'partage',
		array(
			'display'  => 'labels',
			'facebook' => false,
			'threads'  => false,
			'email'    => false,
		),
		''
	);
	$fluffless_assert( 2 === substr_count( $fluffless_html, '<li>' ) && ! str_contains( $fluffless_html, 'facebook' ) && 2 === substr_count( $fluffless_html, 'class="fluffless-partage__label"' ), 'partage : réseaux décochés ou libellés visibles : ' . $fluffless_html );
	$fluffless_assert(
		! str_contains(
			Sections::render(
				'partage',
				array(
					'linkedin' => false,
					'facebook' => false,
					'bluesky'  => false,
					'threads'  => false,
					'email'    => false,
				),
				''
			),
			'<ul'
		),
		'partage : aucune liste quand tout est décoché'
	);
	$fluffless_assert( str_contains( Sections::render( 'carte', array( 'layout' => 'floating' ), '' ), 'fluffless-carte is-layout-side"' ), 'carte : disposition hors liste acceptée' );
	$fluffless_assert( str_contains( Sections::render( 'carte', array( 'layout' => 'stacked' ), '<figure>F</figure>' ), 'is-layout-stacked"><figure>F</figure></div>' ), 'carte : rien ne doit être ajouté au contenu' );
	$fluffless_bar_json = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/blocks/barre/block.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( is_array( $fluffless_bar_json ) && array( 'fluffless/barres' ) === ( $fluffless_bar_json['parent'] ?? null ) && ! isset( $fluffless_bar_json['style'] ), 'barre : bloc enfant mal déclaré' );

	// 3 sexies. Vague 6 : avant et après, vidéo en façade, table des matières, coordonnées.
	$fluffless_html = Sections::compare_frame( '<figure>A</figure><figure>B</figure>', array() );
	$fluffless_assert( str_contains( $fluffless_html, '__frame" style="--fl-p:50%"><figure>A</figure><figure>B</figure><span class="fluffless-avant-apres__label is-before">Before</span>' ), 'avant-après : cadre ou libellé par défaut absents : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, '<input type="range" class="fluffless-avant-apres__range" min="0" max="100" step="1" value="50" aria-label="Compare before and after" aria-valuetext="Before 50%, After 50%"' ), 'avant-après : curseur natif absent ou mal borné' );
	$fluffless_html = Sections::compare_frame(
		'',
		array(
			'start'  => 25,
			'before' => 'Hier <b>x</b>',
			'after'  => 'Demain',
		)
	);
	$fluffless_assert( str_contains( $fluffless_html, 'value="25"' ) && str_contains( $fluffless_html, 'is-before">Hier &lt;b&gt;x&lt;/b&gt;</span>' ) && str_contains( $fluffless_html, 'is-after">Demain</span>' ), 'avant-après : libellés ou départ non repris : ' . $fluffless_html );
	// C3-2-04 : la valeur lue dit la part de chaque image avec les libellés du bloc ; la poignée est
	// dessinée sur le trait (anneau en couleur de contraste --c sur fond de base --b, contraste d'au moins 3:1).
	$fluffless_assert( str_contains( $fluffless_html, 'aria-valuetext="Hier &lt;b&gt;x&lt;/b&gt; 25%, Demain 75%"' ) && str_contains( $fluffless_html, 'data-fl-valuetext="Hier &lt;b&gt;x&lt;/b&gt; {b}%, Demain {a}%"' ), 'avant-après : aria-valuetext absent ou faux : ' . $fluffless_html );
	$fluffless_compare = $fluffless_dir . 'avant-apres/';
	$fluffless_assert( str_contains( (string) file_get_contents( $fluffless_compare . 'view.js' ), 'aria-valuetext' ), 'avant-après : view.js ne suit pas aria-valuetext' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( 1 === preg_match( '/__frame::after\{[^}]*radial-gradient\(circle,var\(--b\)[^}]*var\(--c\)/', (string) file_get_contents( $fluffless_compare . 'style.css' ) ), 'avant-après : poignée visible absente' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( str_contains( Sections::compare_frame( '', array( 'start' => 33 ) ), 'value="50"' ), 'avant-après : départ hors liste accepté' );
	$fluffless_assert( ! str_contains( Sections::compare_frame( '', array( 'labels' => 'hidden' ) ), '<span' ), 'avant-après : libellés rendus malgré le réglage' );
	$fluffless_assert( str_contains( Sections::render( 'avant-apres', array( 'start' => 75 ), '<figure>A</figure>' ), 'fluffless-avant-apres is-start-75 is-labels-shown"><div class="fluffless-avant-apres__frame" style="--fl-p:75%"><figure>A</figure>' ), 'avant-après : rendu complet inattendu' );
	$fluffless_assert( null === Sections::video( 'https://example.org/video.mp4' ) && null === Sections::video( '' ) && null === Sections::video( 'https://www.youtube.com/' ), 'vidéo : adresse non reconnue acceptée' );
	foreach ( array( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=3s', 'https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube.com/shorts/dQw4w9WgXcQ' ) as $fluffless_url ) {
		$fluffless_video = Sections::video( $fluffless_url );
		$fluffless_assert( is_array( $fluffless_video ) && 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' === $fluffless_video['page'] && 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0' === $fluffless_video['embed'], 'vidéo : YouTube non reconnu : ' . $fluffless_url );
	}
	$fluffless_video = Sections::video( 'https://vimeo.com/123456789' );
	$fluffless_assert( is_array( $fluffless_video ) && 'vimeo' === $fluffless_video['provider'] && 'https://player.vimeo.com/video/123456789?autoplay=1&dnt=1' === $fluffless_video['embed'] && 'Vimeo' === $fluffless_video['label'], 'vidéo : Vimeo non reconnu' );
	$fluffless_assert( '' === Sections::video_facade( array( 'url' => 'https://example.org/x' ), 0 ), 'vidéo : façade rendue sans adresse reconnue' );
	$fluffless_html = Sections::video_facade(
		array(
			'url'   => 'https://youtu.be/dQw4w9WgXcQ',
			'title' => 'Visite <b>guidée</b>',
		),
		0
	);
	$fluffless_assert( str_contains( $fluffless_html, '<a class="fluffless-video__facade" href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" target="_blank" rel="noopener noreferrer" data-embed="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&#038;rel=0" data-title="Visite guidée">' ), 'vidéo : lien de façade inattendu : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, '<svg ' ) && str_contains( $fluffless_html, 'Play the video “Visite guidée” Opens on YouTube until embedded content is allowed.</span>' ), 'vidéo : bouton ou libellé absents : ' . $fluffless_html );
	$fluffless_assert( ! str_contains( $fluffless_html, '<iframe' ) && ! str_contains( $fluffless_html, 'youtube.com/embed' ), 'vidéo : le lecteur ne doit pas être rendu au serveur' );
	$fluffless_assert( class_exists( '\\Fluffless\\Consent\\Consent', false ) === str_contains( $fluffless_html, 'data-fluffless-consent="media"' ), 'vidéo : script inerte de consentement attendu seulement avec l\'extension' );
	$fluffless_assert( ! str_contains( Sections::video_facade( array( 'url' => 'https://vimeo.com/12345' ), 0 ), 'data-fluffless-consent' ), 'vidéo : le script inerte ne doit sortir qu\'une fois par page' );
	$fluffless_assert( str_contains( Sections::render( 'video', array( 'url' => 'https://vimeo.com/12345' ), '<p>P</p>' ), 'is-ratio-16-9"><a class="fluffless-video__facade"' ), 'vidéo : façade absente avant le contenu' );
	$fluffless_video_option = get_option( Sections::VIDEO_OPTION );
	delete_option( Sections::VIDEO_OPTION );
	$fluffless_assert( array( 'a' => 1 ) === Sections::consent_services( array( 'a' => 1 ) ) && 'x' === Sections::consent_services( 'x' ), 'vidéo : service déclaré sans bloc enregistré' );
	$fluffless_page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Test vidéo',
			'post_content' => '<!-- wp:fluffless/video {"url":"https://vimeo.com/12345"} --><!-- /wp:fluffless/video -->',
		),
		true
	);
	$fluffless_pages[] = is_int( $fluffless_page_id ) ? $fluffless_page_id : 0;
	$fluffless_assert( '1' === (string) get_option( Sections::VIDEO_OPTION ), 'vidéo : option non posée à l\'enregistrement d\'une page qui porte le bloc' );
	$fluffless_services = Sections::consent_services( array() );
	$fluffless_assert( 'media' === ( $fluffless_services['video-embeds']['category'] ?? null ) && 'YouTube and Vimeo videos' === ( $fluffless_services['video-embeds']['label'] ?? null ), 'vidéo : service de consentement absent une fois l\'option posée' );
	// Vidéo hébergée ici : le lecteur du navigateur, affiche et preload="none", sans lien ni consentement.
	[ $fluffless_clip_id ]   = $fluffless_attach( 'fluffless-clip.mp4', 'video/mp4', 10, 'Clip' );
	$fluffless_posts[]       = $fluffless_clip_id;
	[ $fluffless_poster_id ] = $fluffless_attach( 'fluffless-affiche.png', 'image/png', 10, 'Affiche' );
	$fluffless_posts[]       = $fluffless_poster_id;
	$fluffless_html          = Sections::video_facade(
		array(
			'videoId' => $fluffless_clip_id,
			'url'     => 'https://vimeo.com/12345',
			'title'   => 'Clip <b>maison</b>',
		),
		$fluffless_poster_id
	);
	$fluffless_assert( str_contains( $fluffless_html, '<video class="fluffless-video__player" controls playsinline preload="none"' ) && str_contains( $fluffless_html, ' src="' . wp_get_attachment_url( $fluffless_clip_id ) . '"' ), 'vidéo hébergée : lecteur absent ou sans preload none : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, ' poster="' . (string) wp_get_attachment_image_url( $fluffless_poster_id, 'large' ) . '"' ) && str_contains( $fluffless_html, ' title="Clip maison"' ), 'vidéo hébergée : affiche ou titre absents : ' . $fluffless_html );
	$fluffless_assert( ! str_contains( $fluffless_html, 'fluffless-video__facade' ) && ! str_contains( $fluffless_html, 'vimeo' ) && ! str_contains( $fluffless_html, 'data-fluffless-consent' ), 'vidéo hébergée : le fichier doit passer avant le lien, sans consentement' );
	$fluffless_mixed = Sections::video_facade(
		array(
			'videoId' => $fluffless_poster_id,
			'url'     => 'https://vimeo.com/12345',
		),
		0
	);
	$fluffless_assert( str_contains( $fluffless_mixed, 'fluffless-video__facade' ), 'vidéo hébergée : une image choisie comme vidéo devrait laisser la place au lien' );
	$fluffless_assert( '' === Sections::video_facade( array( 'videoId' => $fluffless_poster_id ), 0 ), 'vidéo hébergée : rien sans vidéo ni lien' );
	$fluffless_assert( str_contains( Sections::render( 'video', array( 'videoId' => $fluffless_clip_id ), '<p>P</p>' ), 'is-ratio-16-9"><video class="fluffless-video__player"' ), 'vidéo hébergée : lecteur absent avant le contenu' );
	delete_option( Sections::VIDEO_OPTION );
	$fluffless_page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Test vidéo hébergée',
			'post_content' => '<!-- wp:group --><!-- wp:fluffless/video {"videoId":' . $fluffless_clip_id . '} --><!-- /wp:fluffless/video --><!-- /wp:group -->',
		),
		true
	);
	$fluffless_pages[] = is_int( $fluffless_page_id ) ? $fluffless_page_id : 0;
	$fluffless_assert( false === get_option( Sections::VIDEO_OPTION ), 'vidéo hébergée : le service YouTube et Vimeo ne doit pas être déclaré pour un fichier hébergé ici' );
	$fluffless_page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Test vidéo imbriquée',
			'post_content' => '<!-- wp:group --><!-- wp:fluffless/video {"url":"https://youtu.be/dQw4w9WgXcQ"} --><!-- /wp:fluffless/video --><!-- /wp:group -->',
		),
		true
	);
	$fluffless_pages[] = is_int( $fluffless_page_id ) ? $fluffless_page_id : 0;
	$fluffless_assert( '1' === (string) get_option( Sections::VIDEO_OPTION ), 'vidéo : option non posée pour un bloc imbriqué avec un lien' );
	if ( false === $fluffless_video_option ) {
		delete_option( Sections::VIDEO_OPTION );
	}
	$fluffless_toc_id  = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Test table',
			'post_content' => '<!-- wp:fluffless/table-des-matieres {"levels":"h2-h3"} --><!-- wp:paragraph {"className":"fluffless-table-des-matieres__title"} --><p class="fluffless-table-des-matieres__title">Sur cette page</p><!-- /wp:paragraph --><!-- /wp:fluffless/table-des-matieres -->'
				. '<!-- wp:heading --><h2 class="wp-block-heading">Première étape</h2><!-- /wp:heading -->'
				. '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Détail <em>un</em></h3><!-- /wp:heading -->'
				. '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading {"level":3,"anchor":"mon-ancre"} --><h3 class="wp-block-heading" id="mon-ancre">Détail deux</h3><!-- /wp:heading --></div><!-- /wp:group -->'
				. '<!-- wp:heading --><h2 class="wp-block-heading">Première étape</h2><!-- /wp:heading -->'
				. '<!-- wp:heading {"level":4} --><h4 class="wp-block-heading">Ignoré</h4><!-- /wp:heading -->',
		),
		true
	);
	$fluffless_toc_id  = is_int( $fluffless_toc_id ) ? $fluffless_toc_id : 0;
	$fluffless_pages[] = $fluffless_toc_id;
	$fluffless_assert( '' === Sections::toc( array() ) || ! get_post() instanceof WP_Post, 'table : rien sans publication courante' );
	$fluffless_toc = get_post( $fluffless_toc_id );
	$fluffless_assert( $fluffless_toc instanceof WP_Post, 'table : page de test non créée' );
	$GLOBALS['post'] = $fluffless_toc; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $fluffless_toc_id );
	$fluffless_html = Sections::toc( array( 'levels' => 'h2-h3' ) );
	$fluffless_assert(
		'<nav class="fluffless-table-des-matieres__nav" aria-label="Table of contents"><ol class="fluffless-table-des-matieres__list"><li><a href="#premiere-etape">Première étape</a><ol><li><a href="#detail-un">Détail un</a></li><li><a href="#mon-ancre">Détail deux</a></li></ol></li><li><a href="#premiere-etape-2">Première étape</a></li></ol></nav>' === $fluffless_html,
		'table : liste imbriquée inattendue : ' . $fluffless_html
	);
	$fluffless_html = Sections::toc( array( 'levels' => 'h2' ) );
	$fluffless_assert( 2 === substr_count( $fluffless_html, '<li>' ) && ! str_contains( $fluffless_html, 'detail' ), 'table : niveau 3 listé malgré le réglage : ' . $fluffless_html );
	$fluffless_assert( str_contains( Sections::render( 'table-des-matieres', array( 'numbering' => 'numbered' ), '<p>T</p>' ), 'is-levels-h2 is-numbering-numbered"><p>T</p><nav ' ), 'table : rendu complet inattendu' );
	$fluffless_assert( false !== has_filter( 'render_block_core/heading', array( Sections::class, 'heading_id' ) ) && 1 === has_filter( 'the_content', array( Sections::class, 'reset_headings' ) ), 'table : crochets des titres absents' );
	$fluffless_html = apply_filters( 'the_content', $fluffless_toc instanceof WP_Post ? $fluffless_toc->post_content : '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$fluffless_assert( str_contains( $fluffless_html, '<h2 id="premiere-etape" class="wp-block-heading">' ) && str_contains( $fluffless_html, '<h2 id="premiere-etape-2" class="wp-block-heading">' ) && str_contains( $fluffless_html, '<h3 id="detail-un" class="wp-block-heading">' ), 'table : identifiants des titres absents ou différents : ' . $fluffless_html );
	$fluffless_assert( 1 === substr_count( $fluffless_html, 'id="mon-ancre"' ) && ! str_contains( $fluffless_html, '<h4 id=' ), 'table : ancre existante doublée ou niveau 4 identifié' );
	$fluffless_assert( str_contains( $fluffless_html, 'href="#premiere-etape-2"' ), 'table : la liste rendue par do_blocks ne suit pas les identifiants' );
	wp_reset_postdata();
	$GLOBALS['post'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$fluffless_assert( '<h2 class="x">T</h2>' === Sections::heading_id( '<h2 class="x">T</h2>', array( 'attrs' => array() ) ), 'table : identifiant posé sans table des matières sur la page' );
	if ( class_exists( '\\Fluffless\\Seo\\Settings', false ) ) {
		$fluffless_seo_option = get_option( \Fluffless\Seo\Settings::NAME );
		update_option(
			\Fluffless\Seo\Settings::NAME,
			array(
				'street'  => '12, rue des <b>Érables</b>',
				'city'    => 'Rimouski',
				'region'  => 'QC',
				'postal'  => 'G5L 1A1',
				'country' => 'Canada',
				'phone'   => '+1 418 555-0100',
				'hours'   => array( 'Mo-Fr 09:00-17:00', 'Sa 10:00-14:00', 'sur rendez-vous' ),
			)
		);
		\Fluffless\Seo\Settings::flush();
		$fluffless_html = Sections::contact_details( array() );
		$fluffless_assert( str_contains( $fluffless_html, '<address class="fluffless-coordonnees__address">12, rue des &lt;b&gt;Érables&lt;/b&gt;<br>Rimouski, QC G5L 1A1<br>Canada</address>' ), 'coordonnées : adresse inattendue : ' . $fluffless_html );
		$fluffless_assert( str_contains( $fluffless_html, '<a href="tel:+14185550100">+1 418 555-0100</a>' ), 'coordonnées : téléphone inattendu : ' . $fluffless_html );
		$fluffless_clock = static function ( string $hm ): string {
			$t = DateTimeImmutable::createFromFormat( '!H:i', $hm, wp_timezone() );
			return false === $t ? $hm : (string) wp_date( (string) get_option( 'time_format', 'H:i' ), $t->getTimestamp() );
		};
		$fluffless_open  = $fluffless_clock( '09:00' );
		$fluffless_close = $fluffless_clock( '17:00' );
		$fluffless_assert( str_contains( $fluffless_html, '<dl class="fluffless-coordonnees__hours"><div><dt>Monday to Friday</dt><dd>' . $fluffless_open . ' to ' . $fluffless_close . '</dd></div><div><dt>Saturday</dt>' ), 'coordonnées : heures inattendues : ' . $fluffless_html );
		$fluffless_assert( str_contains( $fluffless_html, '<div><dd>sur rendez-vous</dd></div></dl>' ), 'coordonnées : ligne libre perdue' );
		$fluffless_assert( ! str_contains( Sections::contact_details( array( 'hours' => 'hidden' ) ), '<dl' ), 'coordonnées : heures rendues malgré le réglage' );
		$fluffless_assert( str_contains( Sections::render( 'coordonnees', array( 'hours' => 'hidden' ), '<h2>T</h2>' ), 'fluffless-coordonnees is-hours-hidden"><h2>T</h2><address ' ), 'coordonnées : rendu complet inattendu' );
		update_option( \Fluffless\Seo\Settings::NAME, array() );
		\Fluffless\Seo\Settings::flush();
		$fluffless_assert( '' === Sections::contact_details( array() ), 'coordonnées : rendu sans réglage' );
		if ( false === $fluffless_seo_option ) {
			delete_option( \Fluffless\Seo\Settings::NAME );
		} else {
			update_option( \Fluffless\Seo\Settings::NAME, $fluffless_seo_option );
		}
		\Fluffless\Seo\Settings::flush();
	}

	// 4. Appel à l'action sur image : image décorative sous le contenu, rien si l'identifiant n'est pas une image.
	list( $fluffless_image, $fluffless_path ) = $fluffless_attach( 'fluffless-fond.jpg', 'image/jpeg', 64, 'Fond' );
	$fluffless_posts[]                        = $fluffless_image;
	$fluffless_files[]                        = $fluffless_path;
	$fluffless_html                           = Sections::render(
		'appel-action',
		array(
			'background' => 'image',
			'imageId'    => $fluffless_image,
		),
		'<p>Go</p>'
	);
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-appel-action__image' ) && str_contains( $fluffless_html, 'alt=""' ), 'appel-action : image de fond absente ou non décorative' );
	$fluffless_assert( strpos( $fluffless_html, '<img' ) < strpos( $fluffless_html, '<p>Go</p>' ), 'appel-action : l\'image doit précéder le contenu (sous le voile)' );
	$fluffless_html = Sections::render(
		'appel-action',
		array(
			'background' => 'accent',
			'imageId'    => $fluffless_image,
		),
		''
	);
	$fluffless_assert( ! str_contains( $fluffless_html, '<img' ), 'appel-action : image rendue alors que le fond est l\'accent' );

	// 5. Ressource : format et taille dans le texte du lien, repli sur la taille du disque.
	list( $fluffless_pdf, $fluffless_path ) = $fluffless_attach( 'fluffless-guide.pdf', 'application/pdf', 2048, 'Guide <b>pratique</b>' );
	$fluffless_posts[]                      = $fluffless_pdf;
	$fluffless_files[]                      = $fluffless_path;
	$fluffless_details                      = Sections::file_details( $fluffless_pdf );
	$fluffless_assert( 'PDF' === $fluffless_details['format'], 'ressource : format attendu PDF, reçu ' . $fluffless_details['format'] );
	$fluffless_assert( size_format( 2048 ) === $fluffless_details['size'], 'ressource : taille du disque attendue, reçu ' . $fluffless_details['size'] );
	wp_update_attachment_metadata( $fluffless_pdf, array( 'filesize' => 245760 ) );
	$fluffless_html = Sections::render_ressource(
		array(
			'fileId'  => $fluffless_pdf,
			'preview' => 'icon',
		),
		'<h3>T</h3>'
	);
	$fluffless_assert( str_contains( $fluffless_html, 'PDF, ' . size_format( 245760 ) . ')' ), 'ressource : taille des métadonnées absente du lien : ' . wp_strip_all_tags( $fluffless_html ) );
	$fluffless_assert( str_contains( $fluffless_html, ' download>' ) && str_contains( $fluffless_html, esc_url( (string) wp_get_attachment_url( $fluffless_pdf ) ) ), 'ressource : lien de téléchargement absent' );
	$fluffless_assert( str_contains( $fluffless_html, '&lt;b&gt;' ) && ! str_contains( $fluffless_html, '<b>' ), 'ressource : titre non échappé' );
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-ressource__icon' ) && str_contains( $fluffless_html, 'aria-hidden="true"' ), 'ressource : icône décorative absente' );
	$fluffless_assert( strpos( $fluffless_html, '<h3>T</h3>' ) < strpos( $fluffless_html, 'fluffless-ressource__link' ), 'ressource : le lien doit suivre le texte' );
	$fluffless_html = Sections::render_ressource( array( 'fileId' => $fluffless_pdf ), '' );
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-ressource__icon' ), 'ressource : PDF sans aperçu, repli sur l\'icône attendu' );
	$fluffless_html = Sections::render_ressource( array( 'fileId' => $fluffless_image ), '' );
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-ressource__preview' ) && str_contains( $fluffless_html, 'alt=""' ), 'ressource : aperçu d\'image absent ou non décoratif' );
	$fluffless_html = Sections::render_ressource( array( 'fileId' => 999999999 ), '<p>Texte</p>' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<a ' ) && ! str_contains( $fluffless_html, '<svg' ) && str_contains( $fluffless_html, '<p>Texte</p>' ), 'ressource : sans fichier, ni lien ni aperçu, mais le texte reste' );

	// 5b. « Demander un courriel d'abord » : formulaire du module Formulaire à la place du lien, lien rendu une fois envoyé.
	$fluffless_attrs = array(
		'fileId'     => $fluffless_pdf,
		'preview'    => 'icon',
		'emailFirst' => true,
		'recipients' => 'leads@example.com, pas-une-adresse',
		'consent'    => 'J\'accepte de recevoir des nouvelles <b>du site</b>',
	);
	if ( ! $fluffless_had_form ) {
		Form::register_blocks();
	}
	Form::forget();
	add_filter( 'pre_wp_mail', $fluffless_capture, 10, 2 );
	$fluffless_form_id = 'ressource' . $fluffless_pdf;
	$fluffless_html    = Sections::render_ressource( $fluffless_attrs, '<h3>T</h3>' );
	$fluffless_assert( str_contains( $fluffless_html, '<form' ) && ! str_contains( $fluffless_html, 'fluffless-ressource__link' ) && ! str_contains( $fluffless_html, ' download>' ), 'courriel d\'abord : le lien doit céder la place au formulaire' );
	$fluffless_assert( ! str_contains( $fluffless_html, esc_url( (string) wp_get_attachment_url( $fluffless_pdf ) ) ), 'courriel d\'abord : l\'adresse du fichier ne doit pas être dans la page' );
	$fluffless_assert( str_contains( $fluffless_html, 'type="email"' ) && str_contains( $fluffless_html, 'name="fl[email]"' ) && str_contains( $fluffless_html, 'required' ), 'courriel d\'abord : champ courriel obligatoire absent' );
	$fluffless_assert( str_contains( $fluffless_html, 'type="checkbox"' ) && str_contains( $fluffless_html, 'J&#039;accepte de recevoir des nouvelles du site' ) && ! str_contains( $fluffless_html, '<b>' ), 'courriel d\'abord : consentement absent ou non nettoyé' );
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-ressource__ask' ) && str_contains( $fluffless_html, '<h3>T</h3>' ), 'courriel d\'abord : consigne ou texte absent' );
	$fluffless_assert( 1 === preg_match( '/name="fluffless_form" value="(' . preg_quote( $fluffless_form_id, '/' ) . '-[a-f0-9]{8})"/', $fluffless_html, $fluffless_key ), 'courriel d\'abord : clé du formulaire absente' );
	$fluffless_html_nocons = Sections::render_ressource( array_merge( $fluffless_attrs, array( 'consent' => '' ) ), '' );
	$fluffless_assert( str_contains( $fluffless_html_nocons, '<form' ) && ! str_contains( $fluffless_html_nocons, 'type="checkbox"' ), 'courriel d\'abord : sans phrase, aucune case attendue' );
	$fluffless_html_off = Sections::render_ressource( array_merge( $fluffless_attrs, array( 'emailFirst' => false ) ), '' );
	$fluffless_assert( ! str_contains( $fluffless_html_off, '<form' ) && str_contains( $fluffless_html_off, ' download>' ), 'courriel d\'abord : désactivé, le lien direct est attendu' );
	$fluffless_html_str = Sections::render_ressource( array_merge( $fluffless_attrs, array( 'emailFirst' => 'oui' ) ), '' );
	$fluffless_assert( str_contains( $fluffless_html_str, ' download>' ) && ! str_contains( $fluffless_html_str, '<form' ), 'courriel d\'abord : valeur non booléenne acceptée' );
	$fluffless_html_nofile = Sections::render_ressource( array_merge( $fluffless_attrs, array( 'fileId' => 999999999 ) ), '<p>Texte</p>' );
	$fluffless_assert( ! str_contains( $fluffless_html_nofile, '<form' ) && str_contains( $fluffless_html_nofile, '<p>Texte</p>' ), 'courriel d\'abord : sans fichier valide, aucun formulaire' );

	// Envoi : la définition mémorisée reçoit le courriel, le courriel part au site avec l'adresse, aucune copie.
	$fluffless_key  = $fluffless_key[1] ?? '';
	$fluffless_post = array(
		Form::FIELD_KEY   => $fluffless_key,
		Form::FIELD_TOKEN => Form::token( $fluffless_key, time() - 10 ),
		Form::FIELD_DATA  => array( 'email' => 'lea@example.com' ),
	);
	$fluffless_r    = Form::process( $fluffless_post, '' );
	$fluffless_assert( ! $fluffless_r['ok'] && isset( $fluffless_r['errors']['consent'] ) && array() === $fluffless_mails, 'courriel d\'abord : consentement exigé avant l\'envoi' );
	$fluffless_post[ Form::FIELD_DATA ]['consent'] = '1';
	$fluffless_r                                   = Form::process( $fluffless_post, '' );
	$fluffless_assert( $fluffless_r['ok'] && $fluffless_r['id'] === $fluffless_form_id, 'courriel d\'abord : envoi refusé : ' . $fluffless_r['message'] );
	$fluffless_assert( 1 === count( $fluffless_mails ) && array( 'leads@example.com' ) === $fluffless_mails[0]['to'], 'courriel d\'abord : un seul courriel, aux destinataires valides' );
	$fluffless_assert( str_contains( (string) $fluffless_mails[0]['subject'], 'Guide pratique' ) && str_contains( (string) $fluffless_mails[0]['message'], 'lea@example.com' ), 'courriel d\'abord : sujet ou adresse absents du courriel' );
	$fluffless_mails = array();

	// Page redirigée : le lien de téléchargement apparaît, à la place du formulaire.
	$_GET[ Form::QUERY_SENT ] = $fluffless_form_id;
	$fluffless_html           = Sections::render_ressource( $fluffless_attrs, '<h3>T</h3>' );
	unset( $_GET[ Form::QUERY_SENT ] );
	$fluffless_assert( ! str_contains( $fluffless_html, '<form' ) && str_contains( $fluffless_html, ' download>' ) && str_contains( $fluffless_html, 'role="status" tabindex="-1" id="fl-' . $fluffless_form_id . '"' ), 'courriel d\'abord : lien attendu après l\'envoi' );
	$_GET[ Form::QUERY_SENT ] = 'ressource1';
	$fluffless_html           = Sections::render_ressource( $fluffless_attrs, '' );
	unset( $_GET[ Form::QUERY_SENT ] );
	$fluffless_assert( str_contains( $fluffless_html, '<form' ) && ! str_contains( $fluffless_html, ' download>' ), 'courriel d\'abord : le drapeau d\'un autre formulaire ne doit rien ouvrir' );

	// Sans le module Formulaire, le lien s'affiche tel quel (une barrière sans formulaire ne mènerait nulle part).
	unregister_block_type( Form::FORM );
	$fluffless_html = Sections::render_ressource( $fluffless_attrs, '' );
	$fluffless_assert( str_contains( $fluffless_html, ' download>' ) && ! str_contains( $fluffless_html, '<form' ), 'courriel d\'abord : sans module Formulaire, lien direct attendu' );
	Form::register_blocks();
	remove_filter( 'pre_wp_mail', $fluffless_capture, 10 );

	// 6. Blocs réels : enregistrés depuis block.json (en tête du test), rendus par do_blocks(), CSS chargé à ce moment.
	foreach ( array_merge( array_keys( Sections::OPTIONS ), array( Sections::BAR ) ) as $fluffless_slug ) {
		$fluffless_assert( $fluffless_registry->is_registered( 'fluffless/' . $fluffless_slug ), "fluffless/{$fluffless_slug} non enregistré" );
	}
	$fluffless_html = do_blocks( '<!-- wp:fluffless/barres --><!-- wp:group {"className":"fluffless-barres__list"} --><div class="wp-block-group fluffless-barres__list"><!-- wp:fluffless/barre {"label":"A","value":55} /--></div><!-- /wp:group --><!-- /wp:fluffless/barres -->' );
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-barres is-size-thin is-values-shown' ) && str_contains( $fluffless_html, '--fl-v:55%' ), 'do_blocks : barres et barre enfant non rendues : ' . $fluffless_html );
	wp_dequeue_style( Sections::STYLE );
	$fluffless_assert( ! wp_style_is( Sections::STYLE, 'enqueued' ), 'CSS commun chargé avant tout rendu' );
	$fluffless_html = do_blocks( '<!-- wp:fluffless/liste {"marker":"check","columns":2} --><!-- wp:paragraph --><p>Un</p><!-- /wp:paragraph --><!-- /wp:fluffless/liste -->' );
	$fluffless_assert( str_contains( $fluffless_html, 'is-marker-check is-columns-2' ) && str_contains( $fluffless_html, '>Un</p>' ), 'do_blocks ne passe pas par Sections::render : ' . $fluffless_html );
	$fluffless_assert( wp_style_is( Sections::STYLE, 'enqueued' ), 'CSS commun non chargé après un rendu' );
	$fluffless_html = do_blocks( '<!-- wp:fluffless/ressource {"fileId":' . $fluffless_pdf . '} --><!-- wp:paragraph --><p>R</p><!-- /wp:paragraph --><!-- /wp:fluffless/ressource -->' );
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-ressource__link' ), 'do_blocks ne passe pas par Sections::render_ressource' );

	// 7. Galerie : l'agrandissement au clic est celui du cœur (lightbox), aucun script à nous.
	$fluffless_html = do_blocks(
		'<!-- wp:fluffless/galerie --><!-- wp:gallery {"columns":2,"linkTo":"lightbox"} --><figure class="wp-block-gallery has-nested-images columns-2 is-cropped">'
		. '<!-- wp:image {"lightbox":{"enabled":true},"linkDestination":"none"} --><figure class="wp-block-image"><img src="https://example.org/a.jpg" alt="A"/></figure><!-- /wp:image -->'
		. '</figure><!-- /wp:gallery --><!-- /wp:fluffless/galerie -->'
	);
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-galerie is-arrangement-grid is-captions-shown' ), 'galerie : enveloppe absente du rendu' );
	$fluffless_assert( str_contains( $fluffless_html, 'wp-lightbox-container' ), 'galerie : agrandissement natif absent' );
	$fluffless_assert( str_contains( $fluffless_html, 'alt="A"' ), 'galerie : texte de remplacement perdu' );

	// 8. Tableau : libellés de colonne posés sur les cellules quand les rangées s'empilent, cellules numériques repérées.
	$fluffless_table = '<figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th>Nom</th><th>Prix <em>mensuel</em></th><th>Taux</th></tr></thead>'
		. '<tbody><tr><td>Plan A</td><td class="has-text-align-center">29 $</td><td>1 200,5 %</td></tr><tr><td>Plan B</td><td>−49,00</td><td></td></tr></tbody></table></figure>';
	$fluffless_html  = Sections::table_cells( $fluffless_table, Sections::attributes( 'tableau', array( 'mobile' => 'stack' ) ) );
	$fluffless_assert( str_contains( $fluffless_html, '<td data-label="Plan A"' ) === false && str_contains( $fluffless_html, '<td data-label="Nom">Plan A</td>' ), 'tableau : libellé de la première colonne absent : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, '<td class="has-text-align-center" data-label="Prix mensuel" class="is-num">' ) === false && str_contains( $fluffless_html, '<td class="is-num has-text-align-center" data-label="Prix mensuel">29 $</td>' ), 'tableau : libellé sans balise ou classe is-num mal fusionnée : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, 'data-label="Taux" class="is-num">1 200,5 %</td>' ) && str_contains( $fluffless_html, 'data-label="Prix mensuel" class="is-num">−49,00</td>' ), 'tableau : nombre avec espace, virgule, signe ou pourcentage non repéré : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, '<td data-label="Taux"></td>' ), 'tableau : cellule vide marquée numérique' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'Plan A</td>' ) || ! str_contains( $fluffless_html, 'is-num">Plan A' ), 'tableau : texte marqué numérique' );
	$fluffless_assert( 3 === substr_count( $fluffless_html, '<th scope="col">' ) && str_contains( $fluffless_html, '<th scope="col">Prix <em>mensuel</em></th>' ), 'tableau : en-tête réécrit ou scope="col" absent : ' . $fluffless_html );
	$fluffless_html = Sections::table_cells( $fluffless_table, Sections::attributes( 'tableau', array() ) );
	$fluffless_assert( ! str_contains( $fluffless_html, 'data-label' ) && 3 === substr_count( $fluffless_html, 'is-num' ) && 3 === substr_count( $fluffless_html, 'scope="col"' ), 'tableau : défilement avec libellés, ou nombres ou scope non repérés par défaut : ' . $fluffless_html );
	$fluffless_html = Sections::table_cells( $fluffless_table, Sections::attributes( 'tableau', array( 'numbers' => 'left' ) ) );
	$fluffless_assert( $fluffless_table !== $fluffless_html && 3 === substr_count( $fluffless_html, 'scope="col"' ) && str_replace( ' scope="col"', '', $fluffless_html ) === $fluffless_table, 'tableau : sans empilement ni alignement, seul scope="col" doit s\'ajouter : ' . $fluffless_html );
	$fluffless_html = Sections::table_cells( str_replace( array( '<thead><tr><th>Nom</th><th>Prix <em>mensuel</em></th><th>Taux</th></tr></thead>', '' ), '', $fluffless_table ), Sections::attributes( 'tableau', array( 'mobile' => 'stack' ) ) );
	$fluffless_assert( ! str_contains( $fluffless_html, 'data-label' ) && str_contains( $fluffless_html, 'is-num' ), 'tableau : sans en-tête, aucun libellé mais les nombres restent repérés' );
	$fluffless_html = Sections::table_cells( '<table><thead><tr><th scope="row">Nom</th></tr></thead><tbody><tr><td>A</td></tr></tbody></table>', Sections::attributes( 'tableau', array() ) );
	$fluffless_assert( str_contains( $fluffless_html, '<th scope="row">Nom</th>' ), 'tableau : scope déjà posé par l\'autrice ne doit pas être remplacé : ' . $fluffless_html );
	$fluffless_html = do_blocks( '<!-- wp:fluffless/tableau {"density":"compact","lines":"grid","header":"sticky","mobile":"stack"} --><!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>A</th></tr></thead><tbody><tr><td>12</td></tr></tbody></table></figure><!-- /wp:table --><!-- /wp:fluffless/tableau -->' );
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-tableau is-density-compact is-lines-grid is-stripes-plain is-header-sticky is-heads-plain is-numbers-right is-mobile-stack"' ) && str_contains( $fluffless_html, '<td data-label="A" class="is-num">12</td>' ), 'do_blocks : tableau non rendu par Sections::render : ' . $fluffless_html );
	$fluffless_assert( ! str_contains( $fluffless_html, '<script' ), 'tableau : script inattendu' );
} finally {
	remove_filter( 'pre_wp_mail', $fluffless_capture, 10 );
	if ( false === $fluffless_forms ) {
		delete_option( Form::OPTION );
	} else {
		update_option( Form::OPTION, $fluffless_forms, false );
	}
	if ( ! $fluffless_had_form ) {
		foreach ( array( Form::FORM, Form::FIELD, Form::STEP ) as $fluffless_form_block ) {
			if ( $fluffless_registry->is_registered( $fluffless_form_block ) ) {
				unregister_block_type( $fluffless_form_block );
			}
		}
	}
	foreach ( $fluffless_posts as $fluffless_post ) {
		wp_delete_attachment( $fluffless_post, true );
	}
	foreach ( $fluffless_pages as $fluffless_page ) {
		wp_delete_post( $fluffless_page, true );
	}
	foreach ( $fluffless_files as $fluffless_file ) {
		if ( file_exists( $fluffless_file ) ) {
			wp_delete_file( $fluffless_file );
		}
	}
	foreach ( array_diff( $fluffless_names, $fluffless_before ) as $fluffless_name ) {
		if ( $fluffless_registry->is_registered( $fluffless_name ) ) {
			unregister_block_type( $fluffless_name );
		}
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Blocs modulables : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Blocs modulables : {$fluffless_checks} vérifications passées." );
