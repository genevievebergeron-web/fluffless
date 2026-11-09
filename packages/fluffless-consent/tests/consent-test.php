<?php
/**
 * Tests de Fluffless Consent : services, bannière, réglages et section partagée de fluffless_settings.
 *
 * Lancer : npm run test:tools
 * (docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-consent/tests/consent-test.php)
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * Les réglages sont simulés par pre_option_fluffless_settings, sauf à l'étape 4 qui écrit l'option
 * puis la restaure telle qu'elle était. Aucune requête réseau : pre_http_request compte les appels.
 *
 * @package Fluffless_Consent
 */

// Le test déclenche des crochets du cœur (pre_http_request, allowed_options...) : ces noms ne portent pas le préfixe.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

use Fluffless\Consent\Consent;
use Fluffless\Consent\Embeds;
use Fluffless\Consent\Settings;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Consent::class ) ) {
	throw new RuntimeException( 'Fluffless Consent non chargé : activer l\'extension (wp plugin activate fluffless-consent).' );
}
if ( ! isset( $_SERVER['SERVER_NAME'] ) ) {
	$_SERVER['SERVER_NAME'] = 'localhost';
}
switch_to_locale( 'en_US' );
wp_set_current_user( 0 );

$fluffless_failures = array();
$fluffless_checks   = 0;

$fluffless_assert = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// Réglages simulés : l'option lue est celle donnée ici, l'option stockée n'est pas touchée.
$fluffless_settings_cb = null;
$fluffless_settings    = static function ( array $settings ) use ( &$fluffless_settings_cb ): void {
	if ( null !== $fluffless_settings_cb ) {
		remove_filter( 'pre_option_' . Settings::NAME, $fluffless_settings_cb );
	}
	$fluffless_settings_cb = static fn(): array => $settings;
	add_filter( 'pre_option_' . Settings::NAME, $fluffless_settings_cb );
};

// ArrayObject : un tableau capturé par référence serait vu comme toujours vide par l'analyse statique.
$fluffless_http_calls = new ArrayObject();
add_filter(
	'pre_http_request',
	static function () use ( $fluffless_http_calls ): array {
		$fluffless_http_calls->append( func_get_args() );
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '',
			'headers'  => array(),
		);
	},
	1
);

// Rendu complet d'une page : en-tête, ouverture du corps, pied.
$fluffless_render = static function (): string {
	ob_start();
	wp_head();
	wp_body_open();
	wp_footer();
	return (string) ob_get_clean();
};

$fluffless_backup = get_option( Settings::NAME, null );
$fluffless_embeds = get_option( Consent::EMBEDS_OPTION, null );
delete_option( Consent::EMBEDS_OPTION );
$fluffless_suite_cb = array( 'Fluffless\\Suite\\Core\\Options', 'sanitize' );
$fluffless_suite_on = false;

// La Suite (si présente) déclare ses propres services sur ce filtre selon la base du banc :
// on le vide pendant le test, comme sanitize_option plus bas, puis on le remet tel quel.
global $wp_filter;
$fluffless_services_hook = $wp_filter['fluffless_consent_services'] ?? null;
unset( $wp_filter['fluffless_consent_services'] );

try {
	// 1. Rien sans service ; avec service, bannière et aucune balise tierce avant un oui.
	$fluffless_settings( array() );
	$fluffless_assert( array() === Consent::services() && '' === Consent::banner_html(), 'bannière rendue sans service déclaré' );
	$fluffless_assert( false !== has_filter( 'fluffless_outgoing_hosts', array( Consent::class, 'outgoing_hosts' ) ), 'Serveurs contactés : Consent non branché sur fluffless_outgoing_hosts' );
	$fluffless_known = array( array( 'host' => 'deja.exemple.ca' ) );
	$fluffless_assert( Consent::outgoing_hosts( $fluffless_known ) === $fluffless_known, 'Serveurs contactés : entrée ajoutée sans service déclaré' );
	Consent::enqueue();
	$fluffless_assert( ! wp_script_is( 'fluffless-consent', 'enqueued' ), 'script chargé sans service déclaré' );
	$fluffless_assert( false === has_action( 'wp_body_open', array( Consent::class, 'print_banner' ) ), 'bannière accrochée sans service déclaré' );
	$fluffless_assert( false !== has_action( 'wp', array( Consent::class, 'start_if_needed' ) ), 'activation automatique non branchée' );
	$fluffless_assert( '' === do_shortcode( '[' . Consent::SHORTCODE . ']' ), 'code court affiché en clair ou rendu sans service' );
	$fluffless_assert( '' === fluffless_consent_link(), 'fluffless_consent_link() rend un bouton sans service' );

	$fluffless_assert( 'G-ABC1234' === Consent::ga_id( ' g-abc1234 ' ) && '' === Consent::ga_id( 'UA-12345-1' ) && '' === Consent::ga_id( 'G-<script>' ), 'identifiant Google Analytics mal validé' );
	$fluffless_extra = static fn( array $services ): array => $services + array(
		'bad-category' => array(
			'category' => 'bogus',
			'label'    => 'X',
		),
		'no-label'     => array( 'category' => 'media' ),
		'http-src'     => array(
			'category' => 'media',
			'label'    => 'Vidéo',
			'src'      => 'http://video.example/embed.js',
		),
	);
	add_filter( 'fluffless_consent_services', $fluffless_extra );
	$fluffless_settings( array( 'consent' => array( 'ga_id' => 'G-TEST12345' ) ) );
	$fluffless_services = Consent::services();
	$fluffless_assert( array( 'google-analytics', 'http-src' ) === array_keys( $fluffless_services ) && '' === $fluffless_services['http-src']['src'], 'services invalides conservés : ' . wp_json_encode( $fluffless_services ) );

	// F-serveurs-contactes-01 : chaque service dont l'adresse est connue devient une entrée navigateur, après un oui.
	$fluffless_map   = static fn( array $services ): array => $services + array(
		'carte' => array(
			'category' => 'media',
			'label'    => 'Cartes',
			'location' => 'Canada',
			'src'      => 'https://Cartes.Exemple.ca/js/a.js',
		),
	);
	add_filter( 'fluffless_consent_services', $fluffless_map );
	$fluffless_hosts = Consent::outgoing_hosts( $fluffless_known );
	remove_filter( 'fluffless_consent_services', $fluffless_map );
	$fluffless_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/consent/consent.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
	$fluffless_assert( str_contains( $fluffless_js, "'" . Consent::GA_SCRIPT . "?id='" ), 'Serveurs contactés : Consent::GA_SCRIPT ne suit plus consent.js' );
	$fluffless_found = array();
	foreach ( is_array( $fluffless_hosts ) ? array_slice( $fluffless_hosts, 1 ) : array() as $fluffless_entry ) {
		if ( 'browser' === ( $fluffless_entry['side'] ?? '' ) && 'after_consent' === ( $fluffless_entry['when'] ?? '' ) ) {
			$fluffless_found[] = ( $fluffless_entry['host'] ?? '' ) . '|' . ( $fluffless_entry['service'] ?? '' ) . '|' . ( $fluffless_entry['location'] ?? '' );
		}
	}
	$fluffless_assert(
		is_array( $fluffless_hosts ) && 3 === count( $fluffless_hosts ) && $fluffless_known[0] === $fluffless_hosts[0]
		&& array( 'www.googletagmanager.com|Google Analytics|United States', 'cartes.exemple.ca|Cartes|Canada' ) === $fluffless_found,
		'Serveurs contactés : entrées de Consent inattendues (le service sans adresse https doit être absent) : ' . wp_json_encode( $fluffless_hosts )
	);

	Consent::start_if_needed();
	$fluffless_assert( false !== has_action( 'wp_body_open', array( Consent::class, 'print_banner' ) ), 'pas d\'activation automatique avec un service déclaré' );
	$fluffless_http_calls->exchangeArray( array() );
	Consent::reset();
	$fluffless_page = $fluffless_render();
	$fluffless_assert( 0 === $fluffless_http_calls->count(), 'requête sortante pendant le rendu' );
	$fluffless_assert( 1 === substr_count( $fluffless_page, 'id="fluffless-consent"' ) && str_contains( $fluffless_page, ' hidden>' ), 'bannière absente, en double ou visible sans JS' );
	foreach ( array( 'googletagmanager', 'google-analytics.com', 'gtag(', 'dataLayer' ) as $fluffless_needle ) {
		$fluffless_assert( ! str_contains( $fluffless_page, $fluffless_needle ), '« ' . $fluffless_needle . ' » dans le HTML avant consentement' );
	}
	$fluffless_home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	preg_match_all( '#<(?:script|img|iframe|source|video|audio)\b[^>]*\ssrc=["\']([^"\']+)|<link\b[^>]*\shref=["\']([^"\']+)#i', $fluffless_page, $fluffless_urls );
	foreach ( array_filter( array_merge( $fluffless_urls[1], $fluffless_urls[2] ) ) as $fluffless_url ) {
		$fluffless_host = wp_parse_url( html_entity_decode( $fluffless_url ), PHP_URL_HOST );
		$fluffless_assert( null === $fluffless_host || $fluffless_home_host === $fluffless_host, 'ressource tierce avant consentement : ' . $fluffless_url );
	}
	$fluffless_assert( 1 === preg_match( '#<script\b(?=[^>]*\sdefer\b)[^>]*fluffless-consent/assets/consent/consent\.js#', $fluffless_page ), 'consent.js absent, servi hors de l\'extension ou sans defer' );
	// Le tableau de bord de la Suite doit compter ce script : déclaré quand un service existe, absent sinon.
	$fluffless_declared = Consent::declare_visitor_scripts( array() );
	$fluffless_assert( isset( $fluffless_declared['consent'][0] ) && is_readable( (string) $fluffless_declared['consent'][0] ) && str_ends_with( (string) $fluffless_declared['consent'][0], 'assets/consent/consent.js' ), 'consent.js non déclaré au tableau de bord' );
	$fluffless_banner = Consent::banner_html();
	$fluffless_assert( ! str_contains( $fluffless_banner, 'checked' ), 'case précochée' );
	$fluffless_assert( str_contains( $fluffless_banner, '<button type="button" data-consent="reject">' ) && str_contains( $fluffless_banner, '<button type="button" data-consent="accept">' ), '« Refuser » et « Accepter » pas au même niveau' );
	$fluffless_assert( str_contains( $fluffless_banner, 'role="region"' ) && str_contains( $fluffless_banner, 'aria-labelledby="fluffless-consent-title"' ) && str_contains( $fluffless_banner, '<legend>' ), 'attributs ARIA ou légende absents' );
	$fluffless_assert( str_contains( $fluffless_page, 'data-fluffless-consent-open' ), 'lien « Gérer mes témoins » absent de la page' );
	$fluffless_assert( str_contains( do_shortcode( '[' . Consent::SHORTCODE . ']' ), 'data-fluffless-consent-open' ), 'code court sans lien' );
	$fluffless_assert( str_contains( fluffless_consent_link(), 'data-fluffless-consent-open' ), 'fluffless_consent_link() sans bouton avec un service déclaré' );
	// C2-1-04 : séparateur traduisible ; en anglais, aucune espace avant le deux-points.
	$fluffless_assert( str_contains( Consent::banner_html(), '</strong>: ' ) && ! str_contains( Consent::banner_html(), '</strong> : ' ), 'C2-1-04 : séparateur « : » codé en dur dans les cases de catégorie' );
	// C2-1-05 : sans JavaScript, le bouton ne ferait rien ; il est imprimé caché et consent.js le révèle.
	$fluffless_assert( 1 === preg_match( '#<button\b(?=[^>]*\shidden\b)[^>]*data-fluffless-consent-open#', fluffless_consent_link() ), 'C2-1-05 : bouton « Gérer mes témoins » imprimé visible sans JavaScript' );
	$fluffless_css = Consent::css();
	$fluffless_assert( str_contains( $fluffless_css, 'var(--wp--preset--color--base' ) && ! preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_css ), 'couleurs hors jetons du thème' );
	$fluffless_assert( str_contains( $fluffless_css, 'max-height:40vh' ) && str_contains( $fluffless_css, 'h2:focus' ), 'bannière non limitée à 40 % de l\'écran sur mobile, ou titre sans focus visible' );
	$fluffless_assert( str_contains( $fluffless_css, 'position:sticky' ) && str_contains( $fluffless_css, 'scroll-padding-bottom' ), 'mobile : boutons non collés au bas du panneau, ou focus masquable par leur bande' );
	$fluffless_assert( str_contains( Consent::status(), 'Google Analytics' ), 'état des réglages sans la liste des services' );

	// 2. Profils juridiques.
	foreach ( array(
		'quebec' => 'outside Quebec',
		'canada' => 'outside Canada',
		'europe' => 'GDPR',
	) as $fluffless_profile => $fluffless_needle ) {
		$fluffless_settings(
			array(
				'consent' => array(
					'ga_id'   => 'G-TEST12345',
					'profile' => $fluffless_profile,
				),
			)
		);
		$fluffless_assert( str_contains( Consent::banner_texts()['intro'], $fluffless_needle ), 'texte de bannière du profil ' . $fluffless_profile . ' inattendu' );
		$fluffless_assert( ( 'quebec' === $fluffless_profile ) === str_contains( Consent::privacy_html(), 'privacy-policy-tutorial' ), 'consigne EFVP mal placée pour le profil ' . $fluffless_profile );
	}
	remove_filter( 'fluffless_consent_services', $fluffless_extra );

	// 2 b. Réglages du navigateur déclarés : listés dans la politique, sans service ni bannière.
	$fluffless_settings( array( 'consent' => array( 'ga_id' => '' ) ) );
	$fluffless_storage = static fn(): array => array(
		'fluffless-scheme' => array( 'label' => 'Dark mode choice' ),
		'cle invalide'     => array( 'label' => 'Espace dans la clé' ),
		'sans-libelle'     => array( 'label' => '' ),
		'pas-un-tableau'   => 'texte',
		7                  => array( 'label' => 'Clé numérique' ),
	);
	add_filter( 'fluffless_consent_storage', $fluffless_storage, 99 );
	$fluffless_assert( array( 'fluffless-scheme' => 'Dark mode choice' ) === Consent::storage(), 'réglages du navigateur : seules les entrées valides sont gardées' );
	$fluffless_assert( array() === Consent::services(), 'réglages du navigateur : aucun service, donc aucune bannière' );
	$fluffless_privacy = Consent::privacy_html();
	$fluffless_assert( str_contains( $fluffless_privacy, 'Dark mode choice (fluffless-scheme)' ) && ! str_contains( $fluffless_privacy, 'non-essential' ), 'politique : réglage listé avec sa clé, sans paragraphe des services' );
	$fluffless_settings( array( 'consent' => array( 'ga_id' => 'G-TEST12345' ) ) );
	$fluffless_privacy = Consent::privacy_html();
	$fluffless_assert( str_contains( $fluffless_privacy, 'non-essential' ) && str_contains( $fluffless_privacy, '(fluffless-scheme)' ), 'politique : services et réglages côte à côte' );
	$fluffless_settings( array( 'consent' => array( 'ga_id' => '' ) ) );
	remove_filter( 'fluffless_consent_storage', $fluffless_storage, 99 );
	$fluffless_many = static function (): array {
		$items = array();
		for ( $i = 0; $i < Consent::MAX_SERVICES + 5; $i++ ) {
			$items[ 'cle-' . $i ] = array( 'label' => 'Réglage ' . $i );
		}
		return $items;
	};
	add_filter( 'fluffless_consent_storage', $fluffless_many, 99 );
	$fluffless_assert( Consent::MAX_SERVICES === count( Consent::storage() ), 'réglages du navigateur : plafond non respecté' );
	remove_filter( 'fluffless_consent_storage', $fluffless_many, 99 );
	add_filter( 'fluffless_consent_storage', '__return_empty_array', 99 );
	$fluffless_assert( '' === Consent::privacy_html(), 'politique : vide sans service ni réglage' );
	remove_filter( 'fluffless_consent_storage', '__return_empty_array', 99 );

	// 3. Poids (X5-10) : plafond de 2048 octets, mesuré en gzip niveau 9 (gzencode, sans en-tête de nom) sur consent.js
	// tel que livré, commentaires compris (le fichier n'est pas minifié). Aucune marge réservée : le test échoue au 2049e octet.
	$fluffless_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/consent/consent.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local de l'extension.
	$fluffless_gz = strlen( (string) gzencode( $fluffless_js, 9 ) );
	$fluffless_assert( $fluffless_gz <= 2048, 'consent.js pèse ' . $fluffless_gz . ' o compressé (plafond 2048 o)' );

	// 4. Réglages : valeur valide ou défaut ; la section partagée ne détruit pas le reste de l'option.
	$fluffless_settings( array( 'consent' => array( 'retention_months' => 'douze' ) ) );
	$fluffless_assert( 6 === Settings::all()['retention_months'], 'valeur stockée d\'un autre type non ramenée au défaut' );
	$fluffless_assert(
		array(
			'profile'          => 'quebec',
			'ga_id'            => '',
			'retention_months' => 13,
		) === Settings::sanitize(
			array(
				'profile'          => 'mars',
				'ga_id'            => 'UA-1',
				'retention_months' => '99',
				'inventee'         => true,
			)
		),
		'assainissement : profil, identifiant, borne ou clé inconnue mal traités'
	);
	$fluffless_assert(
		array(
			'profile'          => 'europe',
			'ga_id'            => 'G-ABC1234',
			'retention_months' => 3,
		) === Settings::sanitize(
			array(
				'profile'          => 'europe',
				'ga_id'            => ' g-abc1234 ',
				'retention_months' => '3',
			)
		),
		'assainissement : valeurs valides non conservées'
	);
	$fluffless_assert( array( Settings::NAME ) === ( apply_filters( 'allowed_options', array() )[ Settings::GROUP ] ?? null ), 'écran de réglages non autorisé à écrire l\'option' );

	if ( null !== $fluffless_settings_cb ) {
		remove_filter( 'pre_option_' . Settings::NAME, $fluffless_settings_cb );
		$fluffless_settings_cb = null;
	}
	// Le filtre de Suite (branché dès que rest_api_init a tourné, par exemple sous WooCommerce)
	// écarte les sections inconnues comme « autre » : ce contrôle ne vise que celui de l'extension.
	$fluffless_suite_on = 10 === has_filter( 'sanitize_option_' . Settings::NAME, $fluffless_suite_cb );
	remove_filter( 'sanitize_option_' . Settings::NAME, $fluffless_suite_cb, 10 );
	update_option(
		Settings::NAME,
		array(
			'version' => 1,
			'autre'   => array( 'garde' => true ),
		)
	);
	// Ce qu'envoie le formulaire de l'écran : la section consent seulement.
	update_option(
		Settings::NAME,
		array(
			'consent' => array(
				'ga_id'   => 'G-FORM1234',
				'profile' => 'canada',
			),
		)
	);
	$fluffless_stored = get_option( Settings::NAME );
	$fluffless_assert( is_array( $fluffless_stored ) && true === ( $fluffless_stored['autre']['garde'] ?? null ), 'enregistrement de la section consent : reste de l\'option perdu' );
	$fluffless_assert(
		is_array( $fluffless_stored ) && array(
			'profile'          => 'canada',
			'ga_id'            => 'G-FORM1234',
			'retention_months' => 6,
		) === ( $fluffless_stored['consent'] ?? null ),
		'enregistrement de la section consent : section non assainie ou incomplète : ' . wp_json_encode( $fluffless_stored )
	);
	$fluffless_odd = Settings::sanitize_option( 'pas un tableau' );
	$fluffless_assert( is_array( $fluffless_odd['consent'] ?? null ) && 'G-FORM1234' === $fluffless_odd['consent']['ga_id'], 'valeur non tableau : section stockée non reprise' );

	// 5. Contenus intégrés : neutralisés au rendu, sans lire de témoin ; un site sans service déclaré obtient quand même la bannière.
	$fluffless_settings( array() );
	delete_option( Consent::EMBEDS_OPTION );
	Consent::reset();
	$fluffless_yt    = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315" allowfullscreen onload="alert(1)"></iframe>';
	$fluffless_cases = array(
		'guillemets doubles'         => $fluffless_yt,
		'guillemets simples, //'     => "<iframe width='560' height='315' src='//player.vimeo.com/video/76979871'></iframe>",
		'guillemet contenant >'      => '<iframe title="a>b" src="https://www.openstreetmap.org/export/embed.html?bbox=1,2,3,4"></iframe>',
		'adresse sans guillemets'    => '<iframe src=https://www.youtube-nocookie.com/embed/abcdef12 onload="alert(1)"></iframe>',
		'iframe imbriquée'           => '<iframe src="https://www.youtube.com/embed/abcdef12"><iframe src="https://vimeo.com/2"></iframe></iframe>',
		'embed'                      => '<embed src="https://www.youtube.com/v/abcdef12">',
		'carte Google'               => '<iframe src="https://www.google.com/maps/embed?pb=1"></iframe>',
		'iframe collée dans un bloc' => '<!-- wp:html --><div><iframe src="https://player.vimeo.com/video/1" frameborder="0"></iframe></div><!-- /wp:html -->',
	);
	foreach ( $fluffless_cases as $fluffless_label => $fluffless_html ) {
		$fluffless_out = Embeds::filter( $fluffless_html );
		$fluffless_assert( is_string( $fluffless_out ) && 0 === preg_match( '#<(?:iframe|embed)\b#i', $fluffless_out ), 'balise tierce conservée (' . $fluffless_label . ') : ' . wp_json_encode( $fluffless_out ) );
		$fluffless_assert( is_string( $fluffless_out ) && str_contains( $fluffless_out, 'data-fluffless-embed=' ) && str_contains( $fluffless_out, 'href="https://' ), 'espace réservé ou lien de lecture absent (' . $fluffless_label . ')' );
		$fluffless_assert( is_string( $fluffless_out ) && ! str_contains( $fluffless_out, 'onload' ) && ! str_contains( $fluffless_out, 'alert(' ), 'gestionnaire d\'événement recopié (' . $fluffless_label . ')' );
		$fluffless_assert( Embeds::filter( $fluffless_out ) === $fluffless_out, 'filtre non idempotent (' . $fluffless_label . ')' );
	}
	$fluffless_assert( str_contains( (string) Embeds::filter( $fluffless_yt ), 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ), 'lien de lecture YouTube sans page publique' );
	$fluffless_assert( str_contains( (string) Embeds::filter( '<iframe src="https://YouTube.com./embed/dQw4w9WgXcQ"></iframe>' ), 'fluffless-embed' ), 'hôte avec point final non neutralisé' );
	$fluffless_assert( str_contains( (string) Embeds::filter( $fluffless_yt ), 'aspect-ratio:560/315' ), 'proportions de l\'iframe perdues' );
	foreach ( array(
		'hôte inconnu'       => '<iframe src="https://inconnu.example/x"></iframe>',
		'srcdoc'             => '<iframe srcdoc="&lt;p&gt;a&lt;/p&gt;"></iframe>',
		'même site'          => '<iframe src="' . home_url( '/page/' ) . '"></iframe>',
		'Google hors cartes' => '<iframe src="https://www.google.com/recaptcha/api2/anchor"></iframe>',
		'sans adresse'       => '<iframe></iframe>',
		'adresse relative'   => '<iframe src="/local/"></iframe>',
		'texte sans balise'  => '<p>Une vidéo YouTube.</p>',
	) as $fluffless_label => $fluffless_html ) {
		$fluffless_assert( Embeds::filter( $fluffless_html ) === $fluffless_html, 'balise non concernée modifiée (' . $fluffless_label . ')' );
	}
	$fluffless_assert( ! Embeds::in_source( '<p>x</p>' ) && Embeds::in_source( $fluffless_yt ) && Embeds::in_source( '<!-- wp:embed {"url":"https://youtu.be/x"} -->' ), 'détection dans la source brute' );

	// Même HTML avec et sans témoin de consentement : le serveur ne lit rien.
	unset( $_COOKIE[ Consent::STORAGE_KEY ] );
	$fluffless_sans                  = Embeds::filter( $fluffless_yt );
	$_COOKIE[ Consent::STORAGE_KEY ] = '{"v":"x","c":{"media":true}}';
	$_SERVER['HTTP_COOKIE']          = Consent::STORAGE_KEY . '=accepte; _ga=GA1.1.1';
	$fluffless_avec                  = Embeds::filter( $fluffless_yt );
	unset( $_COOKIE[ Consent::STORAGE_KEY ], $_SERVER['HTTP_COOKIE'] );
	$fluffless_assert( $fluffless_sans === $fluffless_avec, 'HTML différent avec et sans témoin de consentement' );

	// Sans service déclaré, un contenu neutralisé fait exister la bannière (catégorie « media ») et son script.
	Consent::reset();
	$fluffless_assert( array() === Consent::services(), 'service implicite avant tout contenu intégré' );
	Embeds::filter( $fluffless_yt );
	$fluffless_assert( array( 'embedded-content' ) === array_keys( Consent::services() ) && 'media' === Consent::services()['embedded-content']['category'], 'service implicite absent après neutralisation' );
	$fluffless_assert( false !== has_action( 'wp_footer', array( Consent::class, 'print_late' ) ), 'bannière tardive non accrochée' );
	// Requête neuve : les files de scripts et de styles des rendus précédents sont vides.
	unset( $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );
	$fluffless_page = $fluffless_render();
	$fluffless_assert( 1 === substr_count( $fluffless_page, 'id="fluffless-consent"' ) && str_contains( $fluffless_page, 'data-category="media"' ), 'bannière absente ou sans la catégorie media' );
	$fluffless_assert( 1 === preg_match( '#<script\b(?=[^>]*\sdefer\b)[^>]*fluffless-consent/assets/consent/consent\.js#', $fluffless_page ), 'consent.js absent avec un contenu intégré' );
	$fluffless_assert( false !== get_option( Consent::EMBEDS_OPTION, false ), 'option du service implicite non posée' );
	$fluffless_hash_a = json_decode( (string) html_entity_decode( (string) preg_replace( '#.*data-config="([^"]+)".*#s', '$1', Consent::banner_html() ) ), true );
	Embeds::reset();
	$fluffless_hash_b = json_decode( (string) html_entity_decode( (string) preg_replace( '#.*data-config="([^"]+)".*#s', '$1', Consent::banner_html() ) ), true );
	$fluffless_assert( is_array( $fluffless_hash_a ) && is_array( $fluffless_hash_b ) && $fluffless_hash_a['v'] === $fluffless_hash_b['v'], 'version du choix variable d\'une page à l\'autre' );
	delete_option( Consent::EMBEDS_OPTION );
	remove_action( 'wp_footer', array( Consent::class, 'print_late' ), 1 );
	remove_action( 'wp_footer', array( Consent::class, 'print_manage_fallback' ), 2 );
	Consent::reset();

	// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- HTML d'essai, aucun script n'est mis en file.
	// 6. K1c : périmètre élargi (scripts sociaux, object, video, widget HTML), adresses déguisées, erreur PCRE, extraits et flux.
	Embeds::reset();
	$fluffless_social = array(
		'X'         => '<blockquote class="twitter-tweet"><p>Salut</p></blockquote><script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>',
		'Instagram' => '<blockquote class="instagram-media"><a href="https://www.instagram.com/p/x/">x</a></blockquote><script async src="//www.instagram.com/embed.js"></script>',
		'TikTok'    => '<blockquote class="tiktok-embed"><section></section></blockquote><script async src="https://www.tiktok.com/embed.js"></script>',
		'Facebook'  => '<div id="fb-root"></div><script async defer crossorigin="anonymous" src="https://connect.facebook.net/fr_CA/sdk.js#xfbml=1"></script>',
	);
	foreach ( $fluffless_social as $fluffless_label => $fluffless_html ) {
		Embeds::reset();
		$fluffless_out = Embeds::filter( $fluffless_html );
		$fluffless_assert( is_string( $fluffless_out ) && 1 === preg_match( '#<script\b[^>]*\stype="text/plain"[^>]*\sdata-fluffless-consent="media"#', $fluffless_out ), 'script social non rendu inerte (' . $fluffless_label . ') : ' . wp_json_encode( $fluffless_out ) );
		$fluffless_assert( ( is_string( $fluffless_out ) && str_contains( $fluffless_out, 'src="' ) && str_contains( $fluffless_out, strtolower( $fluffless_label ) ) ) || 'X' === $fluffless_label, 'src du script perdu (' . $fluffless_label . ')' );
		$fluffless_assert( Embeds::filter( $fluffless_out ) === $fluffless_out, 'script inerte non idempotent (' . $fluffless_label . ')' );
		$fluffless_assert( Embeds::found() && false !== has_action( 'wp_footer', array( Consent::class, 'print_late' ) ), 'bannière non annoncée (' . $fluffless_label . ')' );
		$fluffless_assert( Embeds::in_source( $fluffless_html ), 'détection dans la source brute (' . $fluffless_label . ')' );
		remove_action( 'wp_footer', array( Consent::class, 'print_late' ), 1 );
	}
	$fluffless_assert( str_contains( (string) Embeds::filter( $fluffless_social['X'] ), '<blockquote class="twitter-tweet"><p>Salut</p></blockquote>' ), 'citation sociale altérée' );
	$fluffless_assert( Embeds::in_source( '<blockquote class="twitter-tweet"><p>x</p></blockquote>' ) && Embeds::in_source( '<object data="https://www.youtube.com/v/abcdef12"></object>' ) && Embeds::in_source( '<video src="https://vimeo.com/a.mp4"></video>' ), 'détection object, video ou citation sociale' );
	foreach ( array(
		'script inconnu'       => '<script src="https://cdn.exemple.test/app.js"></script>',
		'script en ligne'      => '<script>window.a = "<iframe src=\"https://www.youtube.com/embed/x\"></iframe>";</script>',
		'script du site'       => '<script src="' . home_url( '/a.js' ) . '"></script>',
		'script json'          => '<script type="application/json">{"a":1}</script>',
		'video locale'         => '<video src="/media/a.mp4" controls></video>',
		'object local'         => '<object data="/media/a.pdf" type="application/pdf"></object>',
		'object inconnu'       => '<object data="https://inconnu.example/a.swf"></object>',
		'blockquote ordinaire' => '<blockquote><p>Citation</p></blockquote>',
	) as $fluffless_label => $fluffless_html ) {
		$fluffless_assert( Embeds::filter( $fluffless_html ) === $fluffless_html, 'balise non concernée modifiée (' . $fluffless_label . ')' );
	}
	foreach ( array(
		'object'       => '<object data="https://www.youtube.com/v/abcdef12" type="application/x-shockwave-flash"><param name="movie" value="x"><embed src="https://www.youtube.com/v/abcdef12"></object>',
		'video src'    => '<video src="https://player.vimeo.com/external/1.mp4" controls></video>',
		'video source' => '<video controls><source src="https://www.dailymotion.com/cdn/a.mp4" type="video/mp4"></video>',
	) as $fluffless_label => $fluffless_html ) {
		Embeds::reset();
		$fluffless_out = Embeds::filter( $fluffless_html );
		$fluffless_assert( is_string( $fluffless_out ) && 0 === preg_match( '#<(?:object|video|embed|source|iframe)\b#i', $fluffless_out ), 'balise tierce conservée (' . $fluffless_label . ') : ' . wp_json_encode( $fluffless_out ) );
		$fluffless_assert( is_string( $fluffless_out ) && str_contains( $fluffless_out, 'href="https://' ) && ! str_contains( $fluffless_out, 'data-fluffless-embed=' ), 'lien direct absent ou activation en place promise (' . $fluffless_label . ')' );
		$fluffless_assert( Embeds::filter( (string) $fluffless_out ) === $fluffless_out, 'filtre non idempotent (' . $fluffless_label . ')' );
		$fluffless_assert( Embeds::found(), 'bannière non annoncée (' . $fluffless_label . ')' );
	}
	remove_action( 'wp_footer', array( Consent::class, 'print_late' ), 1 );

	// Adresses déguisées : le navigateur les lit comme des adresses https, PHP n'y voyait pas d'hôte.
	foreach ( array(
		'barres inverses'       => 'https:\\\\www.youtube.com\\embed\\abcdef12',
		'une seule barre'       => 'https:/www.youtube.com/embed/abcdef12',
		'schéma en majuscules'  => 'HTTPS:\\\\www.youtube.com\\embed\\abcdef12',
		'trois barres'          => '///www.youtube.com/embed/abcdef12',
		'tabulation'            => "https://www.you\ttube.com/embed/abcdef12",
		'espace et saut'        => " \n https://www.youtube.com/embed/abcdef12 ",
		'http'                  => 'http://www.youtube.com/embed/abcdef12',
		'https sans barres'     => 'https:www.youtube.com/embed/abcdef12',
		'http sans barres'      => 'http:www.youtube.com/embed/abcdef12',
		'hôte en pleine chasse' => "https://\u{FF57}\u{FF57}\u{FF57}.youtube.com/embed/abcdef12",
		'pleine chasse encodée' => 'https://www.%EF%BD%99outube.com/embed/abcdef12',
	) as $fluffless_label => $fluffless_src ) {
		$fluffless_out = Embeds::filter( '<iframe src="' . esc_attr( $fluffless_src ) . '"></iframe>' );
		$fluffless_assert( is_string( $fluffless_out ) && str_contains( $fluffless_out, 'data-fluffless-embed=' ) && 0 === preg_match( '#<iframe\b#i', $fluffless_out ), 'adresse déguisée non neutralisée (' . $fluffless_label . ') : ' . wp_json_encode( $fluffless_out ) );
		$fluffless_assert( is_string( $fluffless_out ) && str_contains( $fluffless_out, 'https://www.youtube.com/watch?v=abcdef12' ), 'lien de lecture absent (' . $fluffless_label . ')' );
		$fluffless_assert( is_string( $fluffless_out ) && ! str_contains( $fluffless_out, 'http://' ) && ! str_contains( html_entity_decode( $fluffless_out ), '"s":"http:' ), 'adresse stockée en http (' . $fluffless_label . ')' );
	}
	foreach ( array(
		'Google Forms'          => '<iframe src="https://docs.google.com/forms/d/e/abc/viewform?embedded=true"></iframe>',
		'relative'              => '<iframe src="/chemin/"></iframe>',
		'about:blank'           => '<iframe src="about:blank"></iframe>',
		'requête seule'         => '<iframe src="?x=1"></iframe>',
		'hôte inconnu http'     => '<iframe src="http://inconnu.example/x"></iframe>',
		'inconnu sans barres'   => '<iframe src="https:inconnu.example/x"></iframe>',
		'inconnu pleine chasse' => "<iframe src=\"https://\u{FF49}nconnu.example/x\"></iframe>",
	) as $fluffless_label => $fluffless_html ) {
		$fluffless_assert( Embeds::filter( $fluffless_html ) === $fluffless_html, 'balise non concernée modifiée (' . $fluffless_label . ')' );
	}
	foreach ( array( 'https:///', 'https://', '//', 'HTTP:\\\\\\' ) as $fluffless_src ) {
		$fluffless_out = Embeds::filter( '<p>a</p><iframe src="' . esc_attr( $fluffless_src ) . '"></iframe><p>b</p>' );
		$fluffless_assert( is_string( $fluffless_out ) && 0 === preg_match( '#<iframe\b#i', $fluffless_out ) && str_starts_with( $fluffless_out, '<p>a</p>' ) && str_ends_with( $fluffless_out, '<p>b</p>' ), 'adresse sans hôte non retirée (' . $fluffless_src . ') : ' . wp_json_encode( $fluffless_out ) );
		$fluffless_assert( is_string( $fluffless_out ) && ! str_contains( $fluffless_out, 'href=' ), 'lien sur une adresse sans hôte (' . $fluffless_src . ')' );
	}

	// Iframe imbriquée : aucune fermeture orpheline.
	$fluffless_out = Embeds::filter( '<p>a</p><iframe src="https://www.youtube.com/embed/abcdef12"><iframe src="https://vimeo.com/2"></iframe></iframe><p>b</p>' );
	$fluffless_assert( is_string( $fluffless_out ) && ! str_contains( $fluffless_out, '</iframe' ) && str_ends_with( $fluffless_out, '<p>b</p>' ), 'fermeture orpheline après une iframe imbriquée : ' . wp_json_encode( $fluffless_out ) );

	// Erreur PCRE : l'ancien code rendait le HTML d'origine ; la balise tierce ne doit pas survivre avec son adresse.
	$fluffless_big   = '<iframe src="https://www.youtube.com/embed/abcdef12" width="560">' . str_repeat( '<p>texte <b>gras</b> et <i>italique</i></p>', 400 ) . '</iframe><p>texte</p><script src="https://platform.twitter.com/widgets.js"></script>';
	$fluffless_jit   = ini_get( 'pcre.jit' );
	$fluffless_limit = ini_get( 'pcre.backtrack_limit' );
	ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- le test force l'erreur PCRE.
	ini_set( 'pcre.backtrack_limit', '10' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- idem.
	$fluffless_forced = null === @preg_replace_callback( (string) ( new ReflectionClass( Embeds::class ) )->getConstant( 'PATTERN' ), static fn( array $m ): string => $m[0], $fluffless_big ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- sonde de l'erreur.
	$fluffless_out    = Embeds::filter( $fluffless_big );
	ini_set( 'pcre.jit', false === $fluffless_jit ? '1' : $fluffless_jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- remise en état.
	ini_set( 'pcre.backtrack_limit', false === $fluffless_limit ? '1000000' : $fluffless_limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- remise en état.
	$fluffless_assert( $fluffless_forced, 'le test ne parvient pas à forcer une erreur PCRE' );
	$fluffless_assert( is_string( $fluffless_out ) && ! str_contains( $fluffless_out, 'embed/abcdef12' ) && 0 === preg_match( '#<script\b(?![^>]*text/plain)[^>]*platform\.twitter\.com#', $fluffless_out ), 'échec PCRE : contenu tiers rendu tel quel' );
	$fluffless_assert( is_string( $fluffless_out ) && str_contains( $fluffless_out, 'texte' ), 'échec PCRE : texte de la page perdu' );

	// Extraits : jamais de texte d'espace réservé ; flux : un simple lien.
	Embeds::register();
	$fluffless_post = wp_insert_post(
		array(
			'post_title'   => 'Essai extrait',
			'post_status'  => 'publish',
			'post_content' => "<!-- wp:paragraph -->\n<p>Avant la vidéo.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:html -->\n" . $fluffless_yt . "\n<!-- /wp:html -->\n\n<!-- wp:paragraph -->\n<p>Après la vidéo.</p>\n<!-- /wp:paragraph -->",
		)
	);
	$fluffless_assert( $fluffless_post > 0, 'article d\'essai non créé' );
	if ( $fluffless_post > 0 ) {
		$fluffless_excerpt = get_the_excerpt( $fluffless_post );
		$fluffless_assert( str_contains( $fluffless_excerpt, 'Avant la vidéo.' ) && str_contains( $fluffless_excerpt, 'Après la vidéo.' ), 'extrait sans le texte de l\'article : ' . $fluffless_excerpt );
		$fluffless_assert( 1 !== preg_match( '/blocked|Load after|Open on|YouTube/i', $fluffless_excerpt ), 'texte d\'espace réservé dans l\'extrait : ' . $fluffless_excerpt );
		$fluffless_after = (string) apply_filters( 'the_content', get_post_field( 'post_content', $fluffless_post ) );
		$fluffless_assert( str_contains( $fluffless_after, 'data-fluffless-embed=' ), 'espace réservé absent du contenu après la génération d\'un extrait' );
		$GLOBALS['wp_query']->is_feed = true;
		$fluffless_feed               = (string) apply_filters( 'the_content', get_post_field( 'post_content', $fluffless_post ) );
		$GLOBALS['wp_query']->is_feed = false;
		$fluffless_assert( str_contains( $fluffless_feed, 'href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"' ) && ! str_contains( $fluffless_feed, 'stays blocked' ) && ! str_contains( $fluffless_feed, '<button' ) && 0 === preg_match( '#<iframe\b#i', $fluffless_feed ), 'flux : lien simple attendu : ' . $fluffless_feed );
		wp_delete_post( $fluffless_post, true );
	}
	$fluffless_assert( false !== has_filter( 'widget_custom_html_content', array( Embeds::class, 'filter' ) ), 'widget HTML personnalisé non filtré' );
	Embeds::unregister();
	remove_action( 'wp_footer', array( Consent::class, 'print_late' ), 1 );
	remove_action( 'wp_footer', array( Consent::class, 'print_manage_fallback' ), 2 );
	delete_option( Consent::EMBEDS_OPTION );
	Embeds::reset();
	Consent::reset();
	// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
} finally {
	unset( $wp_filter['fluffless_consent_services'] );
	if ( null !== $fluffless_services_hook ) {
		$wp_filter['fluffless_consent_services'] = $fluffless_services_hook; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- remise en place du crochet retiré pour le test.
	}
	if ( null !== $fluffless_settings_cb ) {
		remove_filter( 'pre_option_' . Settings::NAME, $fluffless_settings_cb );
	}
	if ( $fluffless_suite_on ) {
		add_filter( 'sanitize_option_' . Settings::NAME, $fluffless_suite_cb, 10 );
	}
	// Restauration exacte : sans le filtre de l'extension, qui fusionnerait avec la valeur de test.
	remove_filter( 'sanitize_option_' . Settings::NAME, array( Settings::class, 'sanitize_option' ), 20 );
	if ( null === $fluffless_backup ) {
		delete_option( Settings::NAME );
	} else {
		update_option( Settings::NAME, $fluffless_backup );
	}
	add_filter( 'sanitize_option_' . Settings::NAME, array( Settings::class, 'sanitize_option' ), 20 );
	if ( null === $fluffless_embeds ) {
		delete_option( Consent::EMBEDS_OPTION );
	} else {
		update_option( Consent::EMBEDS_OPTION, $fluffless_embeds, false );
	}
	Consent::reset();
	restore_previous_locale();
}

// K1d : l'option des contenus intégrés ne se pose que pour un service de la liste, dans un contenu publié.
{
	$fluffless_before   = get_option( Consent::EMBEDS_OPTION, null );
	$fluffless_post     = static function ( string $status, string $content ): WP_Post {
		return new WP_Post(
			(object) array(
				'ID'           => 999999,
				'post_status'  => $status,
				'post_content' => $content,
				'post_type'    => 'post',
			)
		);
	};
	$fluffless_yt_block = '<!-- wp:embed {"url":"https://youtu.be/x","providerNameSlug":"youtube"} /-->';
	$fluffless_assert( Embeds::in_source( $fluffless_yt_block ) && Embeds::in_source( '[embed]https://youtu.be/x[/embed]' ), 'K1d : bloc et code court d\'un service connu non détectés' );
	$fluffless_assert( ! Embeds::in_source( '<!-- wp:embed {"url":"https://inconnu.example/v/1"} /-->' ) && ! Embeds::in_source( '[embed]https://inconnu.example/v/1[/embed]' ), 'K1d : bloc ou code court d\'un hôte inconnu annoncé' );
	foreach ( array( 'draft', 'private', 'trash', 'pending' ) as $fluffless_status ) {
		delete_option( Consent::EMBEDS_OPTION );
		Consent::note_embeds( 999999, $fluffless_post( $fluffless_status, $fluffless_yt_block ) );
		$fluffless_assert( false === get_option( Consent::EMBEDS_OPTION, false ), 'K1d : option posée par un contenu ' . $fluffless_status );
	}
	delete_option( Consent::EMBEDS_OPTION );
	Consent::note_embeds( 999999, $fluffless_post( 'publish', $fluffless_yt_block ) );
	$fluffless_assert( false !== get_option( Consent::EMBEDS_OPTION, false ), 'K1d : option non posée par un contenu publié' );
	$fluffless_uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local de l'extension.
	$fluffless_assert( str_contains( $fluffless_uninstall, "delete_option( 'fluffless_consent_embeds' )" ), 'K1d : désinstallation sans retrait de fluffless_consent_embeds' );
	if ( null === $fluffless_before ) {
		delete_option( Consent::EMBEDS_OPTION );
	} else {
		update_option( Consent::EMBEDS_OPTION, $fluffless_before, false );
	}
	}

	// K1e : rattrapage des contrôles de K1c.
	{
		Embeds::reset();
		$fluffless_yt = '<iframe src="https://www.youtube.com/embed/abcdef12" width="560" height="315"></iframe>';

		// 1. Apostrophe dans un attribut sans guillemets : l'iframe est neutralisée et le texte voisin intact.
		$fluffless_out = Embeds::filter( '<p>a</p><iframe title=l\'été src="https://www.youtube.com/embed/abcdef12"></iframe><p>Texte de l\'auteur</p>' );
		$fluffless_assert( is_string( $fluffless_out ) && 0 === preg_match( '#<iframe\b#i', $fluffless_out ) && str_contains( $fluffless_out, 'data-fluffless-embed=' ), 'K1e 1 : iframe à apostrophe non neutralisée : ' . wp_json_encode( $fluffless_out ) );
		$fluffless_out = Embeds::filter( '<iframe title=l\'été src="https://www.youtube.com/embed/abcdef12"></iframe><p>Texte de l\'auteur</p><p>Une autre l\'idée</p>' );
		$fluffless_assert( is_string( $fluffless_out ) && str_contains( $fluffless_out, 'Texte de l\'auteur' ) && str_contains( $fluffless_out, 'Une autre l\'idée' ), 'K1e 1 : texte supprimé après une apostrophe : ' . wp_json_encode( $fluffless_out ) );

		// 2. object fermé par une autre balise : rien n'est avalé.
		$fluffless_out = Embeds::filter( '<p>a</p><object data="https://www.youtube.com/embed/abcdef12">Texte intérieur</video><p>b</p>' );
		$fluffless_assert( is_string( $fluffless_out ) && str_contains( $fluffless_out, 'Texte intérieur' ) && str_ends_with( $fluffless_out, '<p>b</p>' ), 'K1e 2 : object fermé par </video> avale du texte : ' . wp_json_encode( $fluffless_out ) );

		// 3. Extrait imbriqué : la neutralisation reste silencieuse jusqu'à la fin de l'extrait extérieur.
		$fluffless_seen   = null;
		$fluffless_inner  = false;
		$fluffless_nested = static function ( $excerpt ) use ( &$fluffless_seen, &$fluffless_inner, $fluffless_yt ) {
			if ( ! $fluffless_inner ) {
				$fluffless_inner = true;
				apply_filters( 'get_the_excerpt', 'interne' );
				$fluffless_seen = Embeds::filter( $fluffless_yt );
			}
			return $excerpt;
		};
		Embeds::register();
		add_filter( 'get_the_excerpt', $fluffless_nested, 10 );
		apply_filters( 'get_the_excerpt', 'externe' );
		remove_filter( 'get_the_excerpt', $fluffless_nested, 10 );
		Embeds::unregister();
		$fluffless_assert( '' === $fluffless_seen, 'K1e 3 : espace réservé dans un extrait imbriqué : ' . wp_json_encode( $fluffless_seen ) );
		Embeds::reset();

		// 4. Repli PCRE : erreur journalisée, lien « Open on … » à la place d'un carré blanc.
		$fluffless_log   = (string) tempnam( sys_get_temp_dir(), 'flc' );
		$fluffless_oldlg = ini_get( 'error_log' );
		$fluffless_jit   = ini_get( 'pcre.jit' );
		$fluffless_limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'error_log', $fluffless_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- le test lit le journal.
		ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- le test force l'erreur PCRE.
		ini_set( 'pcre.backtrack_limit', '10' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- idem.
		$fluffless_out = Embeds::filter( '<iframe src="https://www.youtube.com/embed/abcdef12" width="560">' . str_repeat( '<p>texte <b>gras</b> et <i>italique</i></p>', 400 ) . '</iframe><p>fin</p>' );
		ini_set( 'pcre.jit', false === $fluffless_jit ? '1' : $fluffless_jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- remise en état.
		ini_set( 'pcre.backtrack_limit', false === $fluffless_limit ? '1000000' : $fluffless_limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- idem.
		ini_set( 'error_log', false === $fluffless_oldlg ? '' : $fluffless_oldlg ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- idem.
		$fluffless_logged = (string) file_get_contents( $fluffless_log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier temporaire local.
		wp_delete_file( $fluffless_log );
		$fluffless_assert( str_contains( $fluffless_logged, 'preg_last_error' ), 'K1e 4 : erreur PCRE non journalisée : ' . wp_json_encode( $fluffless_logged ) );
		$fluffless_assert( is_string( $fluffless_out ) && str_contains( $fluffless_out, 'youtube.com/watch?v=abcdef12' ) && str_contains( $fluffless_out, 'Open on YouTube' ), 'K1e 4 : repli PCRE sans lien « Open on YouTube » : ' . wp_json_encode( $fluffless_out ) );
		$fluffless_assert( is_string( $fluffless_out ) && 0 === preg_match( '#<iframe\b[^>]*\ssrc=#i', $fluffless_out ), 'K1e 4 : repli PCRE avec adresse tierce en src' );
		Embeds::reset();

		// 5. Tailles : le ratio garde aussi la largeur d'origine, et la hauteur 100 % ne vaut que dans une boîte à ratio.
		$fluffless_css = Consent::css();
		$fluffless_out = Embeds::filter( $fluffless_yt );
		$fluffless_assert( is_string( $fluffless_out ) && str_contains( $fluffless_out, 'style="aspect-ratio:560/315;max-width:560px"' ), 'K1e 5 : largeur maximale absente du style : ' . wp_json_encode( $fluffless_out ) );
		$fluffless_assert( str_contains( $fluffless_css, '.fluffless-embed[style] iframe{height:100%}' ) && ! str_contains( $fluffless_css, '.fluffless-embed iframe{width:100%;height:100%' ), 'K1e 5 : hauteur 100 % encore inconditionnelle' );
		Embeds::reset();

		// 6. Marqueurs Facebook et filtre de widget appelé pour de vrai.
		foreach ( array( 'fb-post', 'fb-page', 'fb-video' ) as $fluffless_class ) {
			$fluffless_assert( Embeds::in_source( '<div class="' . $fluffless_class . '" data-href="https://www.facebook.com/x/posts/1"></div>' ), 'K1e 6 : marqueur ' . $fluffless_class . ' non reconnu' );
		}
		Embeds::register();
		$fluffless_widget = apply_filters( 'widget_custom_html_content', '<p>w</p>' . $fluffless_yt );
		$fluffless_assert( is_string( $fluffless_widget ) && str_contains( $fluffless_widget, 'data-fluffless-embed=' ) && 0 === preg_match( '#<iframe\b#i', $fluffless_widget ), 'K1e 6 : widget HTML personnalisé non neutralisé : ' . wp_json_encode( $fluffless_widget ) );
		Embeds::unregister();
		Embeds::reset();

		// 8b. Fragment Meta Pixel : le chargeur en ligne et l'image de repli ne sont pas des balises à adresse tierce (limite nommée dans le readme).
		$fluffless_pixel = "<script>!function(f,b,e,v){var t=b.createElement(e);t.src=v;}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');</script><noscript><img height=\"1\" width=\"1\" src=\"https://www.facebook.com/tr?id=1&ev=PageView&noscript=1\"/></noscript>";
		$fluffless_assert( Embeds::filter( $fluffless_pixel ) === $fluffless_pixel, 'K1e 8b : fragment Meta Pixel modifié, la limite nommée ne décrit plus le code' );

		// 8e. Hôte encodé en pourcentage : lu comme le navigateur.
		$fluffless_out = Embeds::filter( '<iframe src="https://www.you%74ube.com/embed/abcdef12"></iframe>' );
		$fluffless_assert( is_string( $fluffless_out ) && 0 === preg_match( '#<iframe\b#i', $fluffless_out ) && str_contains( $fluffless_out, 'data-fluffless-embed=' ), 'K1e 8e : hôte encodé en pourcentage non neutralisé : ' . wp_json_encode( $fluffless_out ) );
		Embeds::reset();

		// 8f. Vidéo de couverture d'un hôte connu : remplacée par un lien, le texte du bloc reste.
		$fluffless_cover = '<div class="wp-block-cover"><video class="wp-block-cover__video-background" autoplay muted loop src="https://player.vimeo.com/video/123456"></video><div class="wp-block-cover__inner-container"><p>Titre de couverture</p></div></div>';
		$fluffless_out   = Embeds::filter( $fluffless_cover );
		$fluffless_assert( is_string( $fluffless_out ) && 0 === preg_match( '#<video\b#i', $fluffless_out ) && str_contains( $fluffless_out, 'Titre de couverture' ) && str_contains( $fluffless_out, 'Open on Vimeo' ), 'K1e 8f : vidéo de couverture : ' . wp_json_encode( $fluffless_out ) );
		Embeds::reset();
		Consent::reset();
		}

		if ( array() !== $fluffless_failures ) {
			throw new RuntimeException( esc_html( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles :\n- " . implode( "\n- ", $fluffless_failures ) ) );
		}

		echo esc_html( 'OK : ' . $fluffless_checks . " contrôles réussis (Fluffless Consent : services, bannière, profils, réglages, contenus intégrés).\n" );
