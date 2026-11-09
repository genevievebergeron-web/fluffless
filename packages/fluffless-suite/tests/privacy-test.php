<?php
/**
 * Tests des modules SEO et Statistiques de Fluffless Suite (la bannière de consentement est testée dans Fluffless Consent).
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/privacy-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * Les réglages sont simulés par pre_option_fluffless_settings : l'option stockée n'est jamais écrite.
 * Le contenu de test est supprimé à la fin, même en cas d'échec. Aucune requête réseau réelle :
 * pre_http_request répond à la place des fournisseurs et compte les appels.
 *
 * @package Fluffless_Suite
 */

// Le test déclenche des crochets du cœur (pre_http_request, wp_robots, the_title...) : ces noms ne portent pas le préfixe.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Seo;
use Fluffless\Suite\Modules\Stats;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Seo::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

// Polylang simulé : $GLOBALS['fluffless_pll'] associe un contenu à sa locale ; tous forment un groupe de traductions.
// Mêmes noms que les fonctions de Polylang : le préfixe est impossible.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
$GLOBALS['fluffless_pll'] = array();
if ( ! function_exists( 'pll_get_post_language' ) ) {
	/**
	 * Langue simulée d'un contenu.
	 *
	 * @param int    $post_id Contenu.
	 * @param string $field   slug ou locale.
	 * @return string|false
	 */
	function pll_get_post_language( $post_id, $field = 'slug' ) {
		$locale = $GLOBALS['fluffless_pll'][ (int) $post_id ] ?? false;
		if ( ! is_string( $locale ) ) {
			return false;
		}
		return 'locale' === $field ? $locale : substr( $locale, 0, 2 );
	}
}
if ( ! function_exists( 'pll_get_post_translations' ) ) {
	/**
	 * Traductions simulées : slug => contenu.
	 *
	 * @param int $post_id Contenu.
	 * @return array<string, int>
	 */
	function pll_get_post_translations( $post_id ) {
		$group = is_array( $GLOBALS['fluffless_pll'] ) ? $GLOBALS['fluffless_pll'] : array();
		if ( ! isset( $group[ (int) $post_id ] ) ) {
			return array();
		}
		$translations = array();
		foreach ( $group as $id => $locale ) {
			$translations[ substr( (string) $locale, 0, 2 ) ] = (int) $id;
		}
		return $translations;
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

$fluffless_failures = array();
$fluffless_checks   = 0;

$fluffless_assert = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// Réglages simulés : les sections données remplacent les défauts, le reste reste par défaut.
$fluffless_settings_cb = null;
$fluffless_settings    = static function ( array $settings ) use ( &$fluffless_settings_cb ): void {
	if ( null !== $fluffless_settings_cb ) {
		remove_filter( 'pre_option_' . Options::NAME, $fluffless_settings_cb );
	}
	$fluffless_settings_cb = static fn(): array => $settings;
	add_filter( 'pre_option_' . Options::NAME, $fluffless_settings_cb );
	Options::flush();
};

// Requêtes sortantes interceptées : [ url, arguments ] ; la réponse est choisie par $fluffless_http_reply.
/**
 * Journal des requêtes sortantes interceptées.
 *
 * @var ArrayObject<int, array<int, mixed>> $fluffless_http_calls
 */
$fluffless_http_calls = new ArrayObject();
$fluffless_http_reply = array(
	'response' => array( 'code' => 202 ),
	'body'     => 'ok',
);
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( $fluffless_http_calls, &$fluffless_http_reply ) {
		$fluffless_http_calls[] = array( $url, $args );
		return $fluffless_http_reply + array( 'headers' => array() );
	},
	1,
	3
);

// Charge une requête comme si le visiteur ouvrait ce contenu.
$fluffless_visit = static function ( int $post_id ): void {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	$GLOBALS['wp_query']     = new WP_Query(
		array(
			'p'         => $post_id,
			'post_type' => 'any',
		)
	);
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	$GLOBALS['post']         = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	Seo::reset(); // Chaque visite est une nouvelle requête : mémos (blocs analysés, image de partage) oubliés.
};

// Rendu complet d'une page : en-tête, ouverture du corps, pied.
$fluffless_render = static function (): string {
	ob_start();
	wp_head();
	wp_body_open();
	wp_footer();
	return (string) ob_get_clean();
};

if ( ! isset( $_SERVER['SERVER_NAME'] ) ) {
	$_SERVER['SERVER_NAME'] = 'localhost';
}
switch_to_locale( 'en_US' );
wp_set_current_user( 0 );

$fluffless_posts = array();

try {
	// 1. SEO : balises présentes, échappées, JSON-LD valide et fermé une seule fois.
	$fluffless_settings( array() );
	$fluffless_post_id = wp_insert_post(
		array(
			'post_title'   => 'Titre de test',
			'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'Du contenu sans fluff pour tester la coupe. ', 10 ) . '</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		),
		true
	);
	if ( is_wp_error( $fluffless_post_id ) ) {
		throw new RuntimeException( 'Création de l\'article de test impossible : ' . esc_html( $fluffless_post_id->get_error_message() ) );
	}
	$fluffless_posts[] = $fluffless_post_id;
	$fluffless_hostile = 'Tom </script><b>&</b> "Jerry"';
	// Le titre hostile arrive par les filtres, comme depuis une extension ou une importation.
	$fluffless_title_filter = static fn(): string => $fluffless_hostile;
	add_filter( 'the_title', $fluffless_title_filter );
	add_filter( 'single_post_title', $fluffless_title_filter );
	update_post_meta( $fluffless_post_id, Seo::META_DESCRIPTION, 'Description « guillemets » "x" & y <script>z</script>' );
	$fluffless_visit( $fluffless_post_id );

	$fluffless_head = Seo::head_tags();
	$fluffless_assert( str_contains( $fluffless_head, '<meta name="description" content="Description « guillemets » &quot;x&quot; &amp; y">' ), 'seo : description absente, non assainie ou mal échappée : ' . $fluffless_head );
	$fluffless_assert( str_contains( $fluffless_head, '<link rel="canonical" href="' . esc_url( (string) get_permalink( $fluffless_post_id ) ) . '">' ), 'seo : canonique absente ou fausse' );
	$fluffless_assert( str_contains( $fluffless_head, '<meta property="og:type" content="article">' ), 'seo : og:type article absent' );
	$fluffless_assert( str_contains( $fluffless_head, '<meta name="twitter:card" content="' ), 'seo : twitter:card absent' );
	$fluffless_assert( ! str_contains( $fluffless_head, '<b>' ) && ! str_contains( $fluffless_head, '"Jerry"' ), 'seo : titre hostile non échappé dans les balises meta' );
	$fluffless_assert( 1 === substr_count( $fluffless_head, '</script>' ), 'seo : « </script> » du titre sorti tel quel du bloc JSON-LD' );
	$fluffless_assert( 1 === preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', $fluffless_head, $fluffless_match ), 'seo : bloc JSON-LD absent' );
	$fluffless_ld    = json_decode( $fluffless_match[1] ?? '', true );
	$fluffless_types = is_array( $fluffless_ld ) ? array_column( $fluffless_ld['@graph'] ?? array(), '@type' ) : array();
	$fluffless_assert( array( 'WebSite', 'Organization', 'Article', 'BreadcrumbList' ) === $fluffless_types, 'seo : types JSON-LD inattendus : ' . wp_json_encode( $fluffless_types ) );
	$fluffless_article = is_array( $fluffless_ld ) ? ( $fluffless_ld['@graph'][2] ?? array() ) : array();
	$fluffless_assert( 'Tom & "Jerry"' === ( $fluffless_article['headline'] ?? null ), 'seo : titre de l\'Article mal décodé : ' . wp_json_encode( $fluffless_article['headline'] ?? null ) );
	remove_filter( 'the_title', $fluffless_title_filter );
	remove_filter( 'single_post_title', $fluffless_title_filter );

	// Repli : sans description, début du texte coupé au mot, 160 caractères au plus.
	delete_post_meta( $fluffless_post_id, Seo::META_DESCRIPTION );
	$fluffless_description = Seo::current_description();
	$fluffless_assert( mb_strlen( $fluffless_description ) <= Seo::DESCRIPTION_LENGTH && str_ends_with( $fluffless_description, '…' ) && str_starts_with( $fluffless_description, 'Du contenu' ), 'seo : repli sur le texte mal coupé : ' . $fluffless_description );
	wp_update_post(
		array(
			'ID'           => $fluffless_post_id,
			'post_excerpt' => 'Un extrait court.',
		)
	);
	$fluffless_visit( $fluffless_post_id );
	$fluffless_assert( 'Un extrait court.' === Seo::current_description(), 'seo : l\'extrait ne sert pas de repli' );

	// Page : pas d'Article ; une seule canonique dans l'en-tête complet (celle du cœur retirée).
	$fluffless_page_id = wp_insert_post(
		array(
			'post_title'  => 'Page de test',
			'post_status' => 'publish',
			'post_type'   => 'page',
		)
	);
	$fluffless_posts[] = $fluffless_page_id;
	$fluffless_visit( $fluffless_page_id );
	$fluffless_assert( ! str_contains( Seo::json_ld(), '"Article"' ), 'seo : Article sur une page' );
	ob_start();
	wp_head();
	$fluffless_full_head = (string) ob_get_clean();
	$fluffless_assert( 1 === preg_match_all( '/rel=["\']canonical["\']/', $fluffless_full_head ), 'seo : canonique absente ou en double dans wp_head' );

	// Site privé : noindex.
	add_filter( 'pre_option_blog_public', '__return_zero' );
	$fluffless_robots = apply_filters( 'wp_robots', array() );
	$fluffless_assert( is_array( $fluffless_robots ) && true === ( $fluffless_robots['noindex'] ?? false ), 'seo : noindex absent sur un site privé' );
	remove_filter( 'pre_option_blog_public', '__return_zero' );
	$fluffless_assert( array() === Seo::robots( array() ) || '0' === (string) get_option( 'blog_public' ), 'seo : noindex ajouté sur un site public' );

	// Réglages : adresse http refusée pour le logo.
	$fluffless_clean = Seo::sanitize(
		array(
			'logo_url' => 'http://example.org/logo.png',
			'org_name' => '<b>Mon OBNL</b>',
		)
	);
	$fluffless_assert( '' === $fluffless_clean['logo_url'] && 'Mon OBNL' === $fluffless_clean['org_name'], 'seo : réglages mal assainis : ' . wp_json_encode( $fluffless_clean ) );

	// Extension SEO simulée : aucun crochet posé, et les réglages le disent.
	remove_action( 'wp_head', array( Seo::class, 'print_head' ), 1 );
	remove_filter( 'wp_robots', array( Seo::class, 'robots' ) );
	$fluffless_conflict = static fn(): string => 'Yoast SEO';
	add_filter( 'fluffless_seo_conflict', $fluffless_conflict );
	( new Seo() )->register();
	$fluffless_assert( false === has_action( 'wp_head', array( Seo::class, 'print_head' ) ) && false === has_filter( 'wp_robots', array( Seo::class, 'robots' ) ), 'seo : crochets posés malgré une extension SEO active' );
	$fluffless_assert( str_contains( Seo::status(), 'Yoast SEO' ), 'seo : désactivation automatique non signalée dans les réglages' );
	remove_filter( 'fluffless_seo_conflict', $fluffless_conflict );
	// Fluffless SEO peut être active sur le banc : c'est une compagne, jamais un conflit.
	$fluffless_status_ok = Seo::companion() ? str_contains( Seo::status(), 'Fluffless SEO' ) : '' === Seo::status();
	$fluffless_assert( $fluffless_status_ok && null === Seo::conflict(), 'seo : conflit signalé sans extension SEO tierce' );

	// Page en sections : premier vrai paragraphe, à toute profondeur ; lien de langue, consigne et vide écartés.
	$fluffless_new_page = static function ( string $title, string $content ) use ( &$fluffless_posts ): int {
		$id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);
		if ( ! is_int( $id ) ) {
			throw new RuntimeException( 'Création de la page de test « ' . esc_html( $title ) . ' » impossible.' );
		}
		$fluffless_posts[] = $id;
		return $id;
	};
	$fluffless_sections = $fluffless_new_page(
		'Page en sections',
		'<!-- wp:cover --><div class="wp-block-cover"><div class="wp-block-cover__inner-container"><!-- wp:group --><div class="wp-block-group">'
		. '<!-- wp:paragraph --><p><a href="/en/" hreflang="en" lang="en">English</a></p><!-- /wp:paragraph -->'
		. '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->'
		. '<!-- wp:paragraph --><p>[Écrivez ici votre accroche]</p><!-- /wp:paragraph -->'
		. '<!-- wp:paragraph --><p>Texte réel de la section imbriquée.</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group --></div></div><!-- /wp:cover -->'
	);
	Seo::reset();
	$fluffless_visit( $fluffless_sections );
	$fluffless_assert( 'Texte réel de la section imbriquée.' === Seo::current_description(), 'seo : description d\'une page en sections : « ' . Seo::current_description() . ' »' );
	$fluffless_slogan = static fn(): string => 'Slogan de test';
	add_filter( 'pre_option_blogdescription', $fluffless_slogan );
	$fluffless_visit( $fluffless_new_page( 'Page vide', '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p></p><!-- /wp:paragraph --></div><!-- /wp:group -->' ) );
	$fluffless_assert( 'Slogan de test' === Seo::current_description(), 'seo : pas de repli sur le slogan pour une page sans texte : « ' . Seo::current_description() . ' »' );
	remove_filter( 'pre_option_blogdescription', $fluffless_slogan );

	// FAQPage : une question par bloc Détails (même imbriqué) dont le résumé finit par « ? » ;
	// un résumé vide ou sans point d'interrogation (Détails servant de section repliable) est écarté.
	$fluffless_faq_id = $fluffless_new_page(
		'FAQ',
		'<!-- wp:group --><div class="wp-block-group">'
		. '<!-- wp:details --><details class="wp-block-details"><summary>Combien ça coûte?</summary><!-- wp:paragraph --><p>Rien du tout.</p><!-- /wp:paragraph --></details><!-- /wp:details -->'
		. '<!-- wp:details --><details class="wp-block-details"><summary></summary><!-- wp:paragraph --><p>Réponse sans question.</p><!-- /wp:paragraph --></details><!-- /wp:details -->'
		. '<!-- wp:details --><details class="wp-block-details"><summary>Délais et livraison</summary><!-- wp:paragraph --><p>Section repliable, pas une question.</p><!-- /wp:paragraph --></details><!-- /wp:details -->'
		. '<!-- wp:details --><details class="wp-block-details"><summary><strong>Et le soutien&nbsp;?</strong> </summary><!-- wp:paragraph --><p>Par courriel.</p><!-- /wp:paragraph --></details><!-- /wp:details -->'
		. '</div><!-- /wp:group -->'
	);
	$fluffless_visit( $fluffless_faq_id );
	preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', Seo::json_ld(), $fluffless_match );
	$fluffless_ld        = json_decode( $fluffless_match[1] ?? '', true );
	$fluffless_faq       = array_values( array_filter( is_array( $fluffless_ld ) && is_array( $fluffless_ld['@graph'] ?? null ) ? $fluffless_ld['@graph'] : array(), static fn( $node ): bool => is_array( $node ) && 'FAQPage' === ( $node['@type'] ?? null ) ) );
	$fluffless_questions = is_array( $fluffless_faq[0]['mainEntity'] ?? null ) ? $fluffless_faq[0]['mainEntity'] : array();
	$fluffless_assert( 2 === count( $fluffless_questions ) && 'Combien ça coûte?' === ( $fluffless_questions[0]['name'] ?? null ) && 'Rien du tout.' === ( $fluffless_questions[0]['acceptedAnswer']['text'] ?? null ), 'seo : FAQPage absente ou fausse : ' . wp_json_encode( $fluffless_faq ) );
	$fluffless_assert( 1 === preg_match( '/^Et le soutien[\s\x{00A0}]\?$/u', (string) ( $fluffless_questions[1]['name'] ?? '' ) ), 'seo : question à espace insécable avant « ? » écartée : ' . wp_json_encode( $fluffless_questions ) );

	// Détails employés seulement comme sections repliables : aucune FAQPage.
	$fluffless_visit(
		$fluffless_new_page(
			'Fonctionnalités',
			'<!-- wp:details --><details class="wp-block-details"><summary>Modules</summary><!-- wp:paragraph --><p>Liste des modules.</p><!-- /wp:paragraph --></details><!-- /wp:details -->'
			. '<!-- wp:details --><details class="wp-block-details"><summary>Blocs</summary><!-- wp:paragraph --><p>Liste des blocs.</p><!-- /wp:paragraph --></details><!-- /wp:details -->'
		)
	);
	$fluffless_assert( ! str_contains( Seo::json_ld(), '"FAQPage"' ), 'seo : FAQPage tirée de sections repliables : ' . Seo::json_ld() );

	// Retour à la page FAQ pour les contrôles du réglage.
	$fluffless_visit( $fluffless_faq_id );

	// Réglage faq_schema éteint : aucune paire, donc aucun FAQPage, même avec des blocs Détails imbriqués; allumé, comportement inchangé.
	$fluffless_count_faq = static function (): int {
		Seo::reset();
		preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', Seo::json_ld(), $match );
		$ld    = json_decode( $match[1] ?? '', true );
		$graph = is_array( $ld ) && is_array( $ld['@graph'] ?? null ) ? $ld['@graph'] : array();
		return count( array_filter( $graph, static fn( $node ): bool => is_array( $node ) && 'FAQPage' === ( $node['@type'] ?? null ) ) );
	};
	$fluffless_settings( array( 'seo' => array( 'faq_schema' => false ) ) );
	$fluffless_assert( 0 === $fluffless_count_faq(), 'seo : FAQPage émise malgré faq_schema éteint' );
	$fluffless_faq_post = get_post( $fluffless_faq_id );
	$fluffless_assert( $fluffless_faq_post instanceof WP_Post && array() === Seo::faq_pairs( $fluffless_faq_post ), 'seo : faq_pairs non vide malgré faq_schema éteint' );
	$fluffless_settings( array() );
	$fluffless_assert( 1 === $fluffless_count_faq(), 'seo : FAQPage absente avec faq_schema par défaut' );
	add_filter( 'fluffless_suite_seo_faq', '__return_false' );
	$fluffless_assert( 0 === $fluffless_count_faq(), 'seo : le filtre fluffless_suite_seo_faq n\'éteint pas la FAQPage' );
	remove_filter( 'fluffless_suite_seo_faq', '__return_false' );
	$fluffless_assert( 1 === $fluffless_count_faq(), 'seo : FAQPage absente une fois le filtre retiré' );

	// Polylang : langue du contenu dans og:locale, traductions en hreflang, liens de Polylang retirés pour éviter le doublon.
	$fluffless_english        = $fluffless_new_page( 'Page EN', '' );
	$GLOBALS['fluffless_pll'] = array(
		$fluffless_sections => 'fr_CA',
		$fluffless_english  => 'en_CA',
	);
	$fluffless_visit( $fluffless_english );
	$fluffless_head = Seo::head_tags();
	$fluffless_assert( str_contains( $fluffless_head, '<meta property="og:locale" content="en_CA">' ) && str_contains( $fluffless_head, '<meta property="og:locale:alternate" content="fr_CA">' ), 'seo : og:locale du contenu ignorée : ' . $fluffless_head );
	$fluffless_assert( str_contains( $fluffless_head, '<link rel="alternate" hreflang="fr-CA" href="' . esc_url( (string) get_permalink( $fluffless_sections ) ) . '">' ) && str_contains( $fluffless_head, 'hreflang="en-CA"' ), 'seo : hreflang absents : ' . $fluffless_head );
	$fluffless_assert( array() === Seo::polylang_hreflang( array( 'fr' => 'x' ) ), 'seo : hreflang de Polylang imprimés en double' );
	$GLOBALS['fluffless_pll'] = array();
	$fluffless_assert( array( 'fr' => 'x' ) === Seo::polylang_hreflang( array( 'fr' => 'x' ) ), 'seo : hreflang de Polylang retirés sans traduction' );

	// Image de partage par défaut : ID de média ; carte large dès qu'il y a une image.
	$fluffless_share   = wp_insert_attachment(
		array(
			'post_title'     => 'Partage',
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		),
		false,
		0
	);
	$fluffless_posts[] = $fluffless_share;
	update_post_meta( $fluffless_share, '_wp_attached_file', '2020/01/fluffless-partage.jpg' );
	$fluffless_settings( array( 'seo' => array( 'social_image_id' => $fluffless_share ) ) );
	$fluffless_visit( $fluffless_english );
	$fluffless_head = Seo::head_tags();
	$fluffless_assert( str_contains( $fluffless_head, 'fluffless-partage.jpg' ) && str_contains( $fluffless_head, '<meta name="twitter:card" content="summary_large_image">' ), 'seo : image de partage par défaut (ID de média) ignorée : ' . $fluffless_head );

	// Filtre fluffless_seo_image : reçoit l'adresse résolue et le contenu, appliqué une fois, vide = pas d'image.
	$fluffless_image_calls = array();
	$fluffless_image_cb    = static function ( string $url, int $post_id ) use ( &$fluffless_image_calls ): string {
		$fluffless_image_calls[] = array( $url, $post_id );
		return 'https://example.invalid/filtre.png?a=1&b=2';
	};
	add_filter( 'fluffless_seo_image', $fluffless_image_cb, 10, 2 );
	Seo::reset();
	$fluffless_head = Seo::head_tags();
	remove_filter( 'fluffless_seo_image', $fluffless_image_cb, 10 );
	$fluffless_assert( str_contains( $fluffless_head, '<meta property="og:image" content="https://example.invalid/filtre.png?a=1&#038;b=2">' ), 'seo : filtre fluffless_seo_image ignoré : ' . $fluffless_head );
	$fluffless_assert( 1 === count( $fluffless_image_calls ) && str_contains( $fluffless_image_calls[0][0], 'fluffless-partage.jpg' ) && $fluffless_english === $fluffless_image_calls[0][1], 'seo : filtre fluffless_seo_image appelé plus d\'une fois ou sans l\'adresse et le contenu : ' . wp_json_encode( $fluffless_image_calls ) );
	add_filter( 'fluffless_seo_image', '__return_empty_string' );
	Seo::reset();
	$fluffless_head = Seo::head_tags();
	remove_filter( 'fluffless_seo_image', '__return_empty_string' );
	$fluffless_assert( ! str_contains( $fluffless_head, 'og:image' ) && str_contains( $fluffless_head, '<meta name="twitter:card" content="summary">' ), 'seo : image retirée par le filtre, og:image ou carte large encore là : ' . $fluffless_head );
	Seo::reset();
	$fluffless_settings( array() );

	// Conflits : Slim SEO et Squirrly reconnus. Squirrly simulé par sa constante, en dernier : le module ne se réactive plus ensuite.
	$fluffless_conflicts = ( new ReflectionClassConstant( Seo::class, 'CONFLICTS' ) )->getValue();
	$fluffless_assert( is_array( $fluffless_conflicts ) && 'Slim SEO' === ( $fluffless_conflicts['SLIM_SEO_VER'] ?? null ), 'seo : Slim SEO absent des conflits' );
	define( 'SQ_VERSION', 'test' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante de Squirrly SEO, simulée.
	$fluffless_assert( 'Squirrly SEO' === Seo::conflict(), 'seo : Squirrly SEO non détecté' );

	// 2. Statistiques : absentes si inactives, en defer si actives, https seulement.
	$fluffless_assert( false === has_action( 'wp_head', array( Stats::class, 'print_script' ) ), 'stats : module inactif mais script accroché' );
	$fluffless_assert( ! str_contains( $fluffless_full_head, 'data-domain' ) && ! str_contains( $fluffless_full_head, 'plausible' ), 'stats : script présent alors que le module est inactif' );
	$fluffless_stats = array(
		'provider'     => 'plausible',
		'script_url'   => 'https://plausible.io/js/script.js',
		'site_id'      => 'example.org',
		'skip_editors' => true,
		'proxy'        => false,
	);
	$fluffless_settings(
		array(
			'modules' => array( 'stats' => true ),
			'stats'   => $fluffless_stats,
		)
	);
	$fluffless_tag = Stats::script_tag();
	$fluffless_assert( str_contains( $fluffless_tag, 'src="https://plausible.io/js/script.js"' ) && str_contains( $fluffless_tag, ' defer' ) && str_contains( $fluffless_tag, 'data-domain="example.org"' ), 'stats : balise inattendue : ' . $fluffless_tag );
	$fluffless_assert( ! str_contains( $fluffless_tag, 'async' ), 'stats : async au lieu de defer' );
	foreach ( array( 'http://plausible.io/js/script.js', 'javascript:alert(1)', 'https://moi:secret@plausible.io/js/script.js', '//plausible.io/js/script.js' ) as $fluffless_bad_url ) {
		$fluffless_assert( '' === Stats::sanitize( array( 'script_url' => $fluffless_bad_url ) )['script_url'], 'stats : adresse refusée acceptée : ' . $fluffless_bad_url );
	}
	$fluffless_assert( '' === Stats::sanitize( array( 'site_id' => 'x" onload="alert(1)' ) )['site_id'], 'stats : identifiant de site hostile accepté' );

	$fluffless_admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );
	$fluffless_assert( '' === Stats::script_tag(), 'stats : administratrice connectée comptée' );
	// Audit C3 : le compteur global n'écrit qu'aux paliers, pas à chaque événement.
	$fluffless_assert( 1 === Stats::global_rate_step( 0 ), 'stats : premier événement, le compteur global doit s\'écrire' );
	foreach ( array( 1, 5, 9, 11, 19 ) as $fluffless_n ) {
		$fluffless_assert( 0 === Stats::global_rate_step( $fluffless_n ), 'stats : pas d\'écriture globale hors palier (' . $fluffless_n . ')' );
	}
	foreach ( array( 10, 20, 990 ) as $fluffless_n ) {
		$fluffless_assert( Stats::GLOBAL_RATE_STEP === Stats::global_rate_step( $fluffless_n ), 'stats : écriture au palier ' . $fluffless_n );
	}
	$fluffless_settings(
		array(
			'modules' => array( 'stats' => true ),
			'stats'   => array( 'skip_editors' => false ) + $fluffless_stats,
		)
	);
	$fluffless_assert( '' !== Stats::script_tag(), 'stats : réglage « compter les personnes qui éditent » ignoré' );
	wp_set_current_user( 0 );

	$fluffless_settings(
		array(
			'modules' => array( 'stats' => true ),
			'stats'   => array(
				'provider'   => 'umami',
				'script_url' => 'https://stats.example.ca/script.js',
				'site_id'    => '3f2c7b1e-0000-4000-8000-000000000000',
			) + $fluffless_stats,
		)
	);
	$fluffless_assert( str_contains( Stats::script_tag(), 'data-website-id="3f2c7b1e-0000-4000-8000-000000000000"' ), 'stats : attribut Umami absent' );

	// Relais : destination fixe, en-têtes minimaux, corps borné, débit borné.
	$fluffless_settings(
		array(
			'modules' => array( 'stats' => true ),
			'stats'   => array( 'proxy' => true ) + $fluffless_stats,
		)
	);
	$fluffless_attributes = Stats::script_attributes();
	$fluffless_assert( str_contains( (string) ( $fluffless_attributes['src'] ?? '' ), 'fluffless/v1/stats/script' ) && str_contains( (string) ( $fluffless_attributes['data-api'] ?? '' ), 'fluffless/v1/stats/event' ), 'stats : relais non utilisé par la balise' );
	// L'aide du relais dit ce que reçoit le fournisseur (décision 8) et l'effet sur le compte des visites.
	$fluffless_proxy_help = (string) ( Stats::fields()['proxy']['help'] ?? '' );
	$fluffless_assert( str_contains( $fluffless_proxy_help, 'only gets a shortened IP address' ) && str_contains( $fluffless_proxy_help, 'may count as one visitor' ), 'stats : aide du relais muette sur l\'adresse tronquée : ' . $fluffless_proxy_help );
	// Serveur REST déjà lancé (WooCommerce le démarre en ligne de commande) : la route s'enregistre tout de suite.
	if ( did_action( 'rest_api_init' ) ) {
		Stats::register_routes();
	} else {
		add_action( 'rest_api_init', array( Stats::class, 'register_routes' ) );
	}
	$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	$fluffless_event        = new WP_REST_Request( 'POST', '/fluffless/v1/stats/event' );
	$fluffless_event->set_header( 'Content-Type', 'application/json' );
	$fluffless_event->set_header( 'User-Agent', 'Test/1.0' );
	$fluffless_event->set_header( 'Cookie', 'secret=1' );
	$fluffless_event->set_body( '{"n":"pageview","u":"https://evil.example/","d":"example.org"}' );
	$fluffless_http_calls->exchangeArray( array() );
	$fluffless_response = rest_do_request( $fluffless_event );
	$fluffless_assert( 202 === $fluffless_response->get_status(), 'stats : relais d\'événement en échec (' . $fluffless_response->get_status() . ')' );
	$fluffless_call = $fluffless_http_calls[0] ?? array( '', array() );
	$fluffless_assert( 'https://plausible.io/api/event' === $fluffless_call[0], 'stats : destination du relais inattendue : ' . $fluffless_call[0] );
	$fluffless_headers = array_keys( $fluffless_call[1]['headers'] ?? array() );
	sort( $fluffless_headers );
	$fluffless_assert( array( 'Content-Type', 'User-Agent', 'X-Forwarded-For' ) === $fluffless_headers, 'stats : en-têtes relayés non minimaux : ' . wp_json_encode( $fluffless_headers ) );
	// Décision 8 : le fournisseur reçoit l'adresse tronquée (/24 en IPv4, /64 en IPv6), jamais l'adresse entière.
	$fluffless_forwarded = static function () use ( $fluffless_event, $fluffless_http_calls ): string {
		$fluffless_http_calls->exchangeArray( array() );
		rest_do_request( $fluffless_event );
		return (string) ( $fluffless_http_calls[0][1]['headers']['X-Forwarded-For'] ?? '' );
	};
	$fluffless_seen      = (string) ( $fluffless_call[1]['headers']['X-Forwarded-For'] ?? '' );
	$fluffless_assert( '203.0.113.0' === $fluffless_seen, 'stats : IPv4 transmise entière : ' . $fluffless_seen );
	$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:a:b:c:d';
	$fluffless_seen         = $fluffless_forwarded();
	$fluffless_assert( '2001:db8:1:2::' === $fluffless_seen, 'stats : IPv6 transmise entière : ' . $fluffless_seen );
	$fluffless_proxy_ip = static fn() => '198.51.100.23';
	add_filter( 'fluffless_stats_client_ip', $fluffless_proxy_ip );
	$fluffless_seen     = $fluffless_forwarded();
	remove_filter( 'fluffless_stats_client_ip', $fluffless_proxy_ip );
	$fluffless_assert( '198.51.100.0' === $fluffless_seen, 'stats : adresse du filtre de mandataire non tronquée : ' . $fluffless_seen );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	$fluffless_truncations  = array(
		'203.0.113.7'           => '203.0.113.0',
		'2001:db8:1:2:a:b:c:d'  => '2001:db8:1:2::',
		'2001:db8::1'           => '2001:db8::',
		'::ffff:203.0.113.7'    => '203.0.113.0',
		'::1'                   => '::',
		'0.0.0.0'               => '0.0.0.0',
		'255.255.255.255'       => '255.255.255.0',
		''                      => '0.0.0.0',
		'abc'                   => '0.0.0.0',
		'fe80::1%eth0'          => '0.0.0.0',
		'203.0.113.7, 10.0.0.1' => '0.0.0.0',
	);
	foreach ( $fluffless_truncations as $fluffless_in => $fluffless_out ) {
		$fluffless_got = Stats::truncate_ip( (string) $fluffless_in );
		$fluffless_assert( $fluffless_out === $fluffless_got, 'stats : truncate_ip( ' . $fluffless_in . ' ) = ' . $fluffless_got . ', attendu ' . $fluffless_out );
	}
	foreach ( array( '203.0.113.7', '2001:db8:1:2:a:b:c:d', '198.51.100.23' ) as $fluffless_ip ) {
		delete_transient( Stats::rate_key( $fluffless_ip ) );
	}
	$fluffless_event->set_body( str_repeat( 'x', Stats::MAX_BODY + 1 ) );
	$fluffless_assert( 400 === rest_do_request( $fluffless_event )->get_status(), 'stats : corps trop long relayé' );
	$fluffless_event->set_body( 'pas du JSON' );
	$fluffless_assert( 400 === rest_do_request( $fluffless_event )->get_status(), 'stats : corps non JSON relayé' );
	$fluffless_limited = true;
	for ( $fluffless_i = 0; $fluffless_i < Stats::RATE_LIMIT; $fluffless_i++ ) {
		$fluffless_limited = Stats::within_rate_limit( '198.51.100.9' ) && $fluffless_limited;
	}
	$fluffless_assert( $fluffless_limited && ! Stats::within_rate_limit( '198.51.100.9' ), 'stats : limite de débit non appliquée après ' . Stats::RATE_LIMIT . ' événements' );
	// La limite reste par adresse entière : la voisine du même /24 n'est pas bloquée (réseau d'un campus derrière un NAT).
	$fluffless_assert( Stats::within_rate_limit( '198.51.100.10' ), 'stats : limite de débit appliquée à tout le /24' );
	delete_transient( Stats::rate_key( '198.51.100.10' ) );
	delete_transient( 'fluffless_stats_rl_' . substr( wp_hash( '198.51.100.9' ), 0, 20 ) );
	delete_transient( 'fluffless_stats_rl_' . substr( wp_hash( '203.0.113.7' ), 0, 20 ) );

	$fluffless_http_reply = array(
		'response' => array( 'code' => 200 ),
		'body'     => 'console.log(1)',
	);
	delete_transient( 'fluffless_stats_script' );
	$fluffless_script = rest_do_request( new WP_REST_Request( 'GET', '/fluffless/v1/stats/script' ) );
	$fluffless_assert( 'console.log(1)' === $fluffless_script->get_data() && str_contains( (string) ( $fluffless_script->get_headers()['Content-Type'] ?? '' ), 'javascript' ), 'stats : script relayé inattendu' );
	$fluffless_http_calls->exchangeArray( array() );
	rest_do_request( new WP_REST_Request( 'GET', '/fluffless/v1/stats/script' ) );
	$fluffless_assert( 0 === count( $fluffless_http_calls ), 'stats : script redemandé au fournisseur malgré le cache' );
	delete_transient( 'fluffless_stats_script' );

	// Panne du fournisseur : 502 générique (aucun hôte révélé), échec mémorisé, dernière copie valide servie.
	$fluffless_http_reply = array(
		'response' => array( 'code' => 500 ),
		'body'     => '',
	);
	delete_transient( Lifecycle::TRANSIENT_STATS_FAILURE );
	$fluffless_failed  = rest_do_request( new WP_REST_Request( 'GET', '/fluffless/v1/stats/script' ) );
	$fluffless_message = (string) wp_json_encode( $fluffless_failed->get_data() );
	$fluffless_assert( 502 === $fluffless_failed->get_status() && ! str_contains( $fluffless_message, 'plausible.io' ), 'stats : panne du script sans 502 générique : ' . $fluffless_message );
	$fluffless_http_calls->exchangeArray( array() );
	rest_do_request( new WP_REST_Request( 'GET', '/fluffless/v1/stats/script' ) );
	$fluffless_assert( 0 === count( $fluffless_http_calls ), 'stats : fournisseur en panne rappelé à chaque page vue' );
	set_transient(
		Lifecycle::TRANSIENT_STATS_SCRIPT,
		array(
			'url'         => 'https://plausible.io/js/script.js',
			'body'        => 'console.log(2)',
			'fresh_until' => time() - 1,
		),
		DAY_IN_SECONDS
	);
	delete_transient( Lifecycle::TRANSIENT_STATS_FAILURE );
	$fluffless_stale = rest_do_request( new WP_REST_Request( 'GET', '/fluffless/v1/stats/script' ) );
	$fluffless_assert( 200 === $fluffless_stale->get_status() && 'console.log(2)' === $fluffless_stale->get_data() && false !== get_transient( Lifecycle::TRANSIENT_STATS_FAILURE ), 'stats : dernière copie valide non servie pendant la panne, ou panne non mémorisée' );
	delete_transient( Lifecycle::TRANSIENT_STATS_SCRIPT );
	delete_transient( Lifecycle::TRANSIENT_STATS_FAILURE );
	$fluffless_event->set_body( '{"n":"pageview","u":"https://example.org/","d":"example.org"}' );
	$fluffless_upstream = rest_do_request( $fluffless_event );
	$fluffless_message  = (string) wp_json_encode( $fluffless_upstream->get_data() );
	$fluffless_assert( 502 === $fluffless_upstream->get_status() && ! str_contains( $fluffless_message, 'plausible.io' ), 'stats : panne du relais sans 502 générique : ' . $fluffless_message );

	// Limite de débit : un /64 IPv6 compte comme une seule adresse ; plafond global pour tout le site.
	$fluffless_assert( Stats::rate_key( '2001:db8:1:2::1' ) === Stats::rate_key( '2001:db8:1:2:ffff:ffff:ffff:9' ) && Stats::rate_key( '2001:db8:1:2::1' ) !== Stats::rate_key( '2001:db8:1:3::1' ), 'stats : limite IPv6 par adresse au lieu du /64' );
	set_transient( Stats::GLOBAL_RATE_KEY, Stats::GLOBAL_RATE_LIMIT, MINUTE_IN_SECONDS );
	$fluffless_assert( ! Stats::within_rate_limit( '192.0.2.77' ), 'stats : plafond global ignoré' );
	delete_transient( Stats::GLOBAL_RATE_KEY );
	delete_transient( Stats::rate_key( '203.0.113.7' ) );
	$fluffless_assert( str_contains( Stats::privacy_text(), 'Plausible Analytics' ) && str_contains( Stats::privacy_text(), 'no cookie' ), 'stats : mention de confidentialité inattendue' );
} finally {
	foreach ( $fluffless_posts as $fluffless_id ) {
		wp_delete_post( (int) $fluffless_id, true );
	}
	if ( null !== $fluffless_settings_cb ) {
		remove_filter( 'pre_option_' . Options::NAME, $fluffless_settings_cb );
	}
	Options::flush();
	restore_previous_locale();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}

echo esc_html( 'OK : ' . $fluffless_checks . " contrôles réussis (Fluffless Suite : SEO, statistiques).\n" );
