<?php
/**
 * Tests du module Pied de page et de ses blocs.
 *
 * Lancer : npm run test:tools
 * (docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/footer-test.php)
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * L'option fluffless_settings, le statut d'hébergeur, la page de politique et la section Consent des réglages et l'option des contenus intégrés sont restaurés à la fin.
 *
 * @package Fluffless_Suite
 */

// Le test simule des crochets du cœur et de Polylang : ces noms ne peuvent pas porter le préfixe.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Footer;
use Fluffless\Suite\Modules\Footprint;
use Fluffless\Consent\Consent;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Footer::class ) || ! WP_Block_Type_Registry::get_instance()->is_registered( 'fluffless/legal' ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}
require_once ABSPATH . 'wp-admin/includes/image.php';

$fluffless_failures = array();
$fluffless_checks   = 0;

$fluffless_assert = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

/**
 * Enregistre la section footer (et l'état du module) comme le ferait la page de réglages.
 *
 * @param array<string, mixed> $section Réglages du module.
 * @param bool                 $enabled Module actif.
 */
$fluffless_set = static function ( array $section, bool $enabled = true ): void {
	update_option(
		Options::NAME,
		Options::sanitize(
			array(
				'modules' => array( 'footer' => $enabled ),
				'footer'  => $section + Footer::defaults(),
			)
		)
	);
	Options::flush();
};

$fluffless_render = static fn( string $block ): string => do_blocks( '<!-- wp:fluffless/' . $block . ' /-->' );

$fluffless_backup_option  = get_option( Options::NAME, null );
$fluffless_backup_host    = get_transient( Lifecycle::TRANSIENT_HOST_STATUS );
$fluffless_backup_privacy = (int) get_option( 'wp_page_for_privacy_policy', 0 );
// Une page publiée avec un contenu intégré pose cette option, qui déclare un service implicite : le contrôle « aucun service » la suppose absente.
$fluffless_hide_consent  = null;
$fluffless_backup_embeds = class_exists( Consent::class ) ? get_option( Consent::EMBEDS_OPTION, null ) : null;
$fluffless_attachment    = 0;
$fluffless_post          = 0;
$fluffless_blocks        = array( 'badge-hebergement', 'reseaux', 'certifications', 'lien-infolettre', 'legal' );

switch_to_locale( 'en_CA' );
remove_all_filters( 'fluffless_host_is_green' );
remove_all_filters( 'fluffless_consent_services' );
remove_all_filters( 'fluffless_footer_languages' );
update_option( 'wp_page_for_privacy_policy', 0 );
if ( class_exists( Consent::class ) ) {
	delete_option( Consent::EMBEDS_OPTION );
	// Un identifiant Google Analytics enregistré (section « consent » de l'option, que Options::sanitize reprend du stockage) déclare aussi un service : masquée à la lecture le temps du test.
	$fluffless_hide_consent = static function ( $value ) {
		if ( is_array( $value ) ) {
			unset( $value['consent'] );
		}
		return $value;
	};
	add_filter( 'option_' . Options::NAME, $fluffless_hide_consent );
	Options::flush();
	Consent::reset();
}
if ( false === has_filter( 'render_block_fluffless/empreinte', array( Footer::class, 'filter_footprint' ) ) ) {
	( new Footer() )->register();
}

try {
	// Image de certification réelle (PNG 2 x 2) : wp_attachment_is_image() doit la reconnaître.
	$fluffless_png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEklEQVR4nGP4z8DAwMDAxMDAAAAYGAEBtOJmSwAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- image de test fixe.
	$fluffless_bits = wp_upload_bits( 'fluffless-certification-test.png', null, (string) $fluffless_png );
	if ( '' !== (string) $fluffless_bits['error'] ) {
		throw new RuntimeException( 'Téléversement de test impossible : ' . $fluffless_bits['error'] );
	}
	$fluffless_attachment = (int) wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'B Corp',
			'post_status'    => 'inherit',
		),
		$fluffless_bits['file']
	);
	wp_update_attachment_metadata( $fluffless_attachment, wp_generate_attachment_metadata( $fluffless_attachment, $fluffless_bits['file'] ) );

	$fluffless_all_on  = array(
		'network_urls'      => "https://www.linkedin.com/company/exemple\nhttps://instagram.com/exemple\nhttps://mastodon.social/@exemple\nhttps://exemple.ca/feed/\nmailto:bonjour@exemple.ca\nhttps://inconnu.example/page\nhttp://facebook.com/exemple\n<script>alert(1)</script>",
		'certification_ids' => "abc\n{$fluffless_attachment}, 999999",
		'newsletter_url'    => 'https://exemple.ca/infolettre',
		'privacy_url'       => 'https://exemple.ca/confidentialite',
		'land_text'         => 'Nous travaillons sur le territoire <b>non cédé</b> de la Nation.',
	);
	$fluffless_checked = time();
	set_transient(
		Lifecycle::TRANSIENT_HOST_STATUS,
		array(
			'green'   => true,
			'checked' => $fluffless_checked,
		),
		HOUR_IN_SECONDS
	);

	// 1. Assainissement : réseaux non reconnus, http et identifiants invalides écartés.
	$fluffless_set( $fluffless_all_on );
	$fluffless_urls = Options::get_list( 'footer', 'network_urls' );
	$fluffless_assert( 5 === count( $fluffless_urls ), 'sanitize : 5 réseaux attendus, reçu ' . count( $fluffless_urls ) . ' (' . implode( ' ', $fluffless_urls ) . ')' );
	$fluffless_assert( array( (string) $fluffless_attachment ) === Options::get_list( 'footer', 'certification_ids' ), 'sanitize : seule l\'image réelle doit rester' );
	$fluffless_assert( ! str_contains( Options::get_string( 'footer', 'land_text' ), '<b>' ), 'sanitize : balise conservée dans la reconnaissance territoriale' );
	$fluffless_assert( 'mastodon' === Footer::network_of( 'https://mastodon.social/@exemple' ) && null === Footer::network_of( 'https://exemple.ca/@a/b' ), 'network_of : reconnaissance Mastodon' );
	$fluffless_assert( null === Footer::network_of( 'mailto:pas-un-courriel' ) && null === Footer::network_of( 'javascript:alert(1)' ), 'network_of : adresse invalide acceptée' );

	// 2. Tous les éléments actifs : chaque bloc rend quelque chose.
	foreach ( $fluffless_blocks as $fluffless_block ) {
		$fluffless_assert( '' !== trim( $fluffless_render( $fluffless_block ) ), "tout actif : $fluffless_block vide" );
	}

	// 3. Badge : présent si l'hébergeur est vert, lien vers la vérification publique du domaine, SVG local.
	$fluffless_html = $fluffless_render( 'badge-hebergement' );
	$fluffless_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$fluffless_assert( str_contains( $fluffless_html, 'green-web-check/?url=' . rawurlencode( $fluffless_host ) ), 'badge : lien de vérification du domaine absent' );
	$fluffless_assert( str_contains( $fluffless_html, '<svg ' ) && str_contains( $fluffless_html, 'aria-hidden="true"' ), 'badge : SVG décoratif absent' );
	// Loi C-59 : ce que la fondation affirme (répertorié) et la date du Bilan, jamais « verified » seul.
	$fluffless_badge_text = wp_strip_all_tags( $fluffless_html );
	$fluffless_assert(
		str_contains( $fluffless_badge_text, 'Green Web Foundation' ) && str_contains( $fluffless_badge_text, (string) wp_date( (string) get_option( 'date_format' ), $fluffless_checked ) ) && 0 === preg_match( '/\bverified\b|renewable/i', $fluffless_badge_text ),
		'badge : libellé sans date du Bilan, ou « verified » / « renewable » : ' . $fluffless_badge_text
	);
	set_transient( Lifecycle::TRANSIENT_HOST_STATUS, array( 'green' => true ), HOUR_IN_SECONDS );
	$fluffless_assert( '' === $fluffless_render( 'badge-hebergement' ), 'badge : affiché sans date de vérification' );
	set_transient( Lifecycle::TRANSIENT_HOST_STATUS, array( 'green' => false ), HOUR_IN_SECONDS );
	$fluffless_assert( '' === $fluffless_render( 'badge-hebergement' ), 'badge : affiché alors que l\'hébergeur n\'est pas vert' );
	delete_transient( Lifecycle::TRANSIENT_HOST_STATUS );
	$fluffless_assert( '' === $fluffless_render( 'badge-hebergement' ), 'badge : affiché sans vérification' );
	$fluffless_assert( str_contains( Footer::status(), 'hidden' ), 'status : le badge caché doit être expliqué' );
	set_transient(
		Lifecycle::TRANSIENT_HOST_STATUS,
		array(
			'green'   => true,
			'checked' => $fluffless_checked,
		),
		HOUR_IN_SECONDS
	);

	// 4. Réseaux : SVG en ligne, libellés accessibles, aucune ressource externe chargée.
	$fluffless_html = $fluffless_render( 'reseaux' );
	$fluffless_site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$fluffless_assert( 5 === substr_count( $fluffless_html, '<li>' ), 'réseaux : 5 éléments attendus' );
	$fluffless_assert( 4 === substr_count( $fluffless_html, '<svg ' ), 'réseaux : 4 SVG attendus (LinkedIn en texte)' );
	$fluffless_assert( str_contains( $fluffless_html, 'aria-label="' . esc_attr( $fluffless_site . ' on Instagram' ) . '"' ), 'réseaux : aria-label Instagram absent' );
	$fluffless_assert( str_contains( $fluffless_html, 'aria-label="' . esc_attr( 'Email ' . $fluffless_site ) . '"' ) && str_contains( $fluffless_html, 'href="mailto:bonjour@exemple.ca"' ), 'réseaux : lien courriel' );
	$fluffless_assert( 1 !== preg_match( '/<(img|script|link|iframe|use)\b|\ssrc=|xlink:href/i', $fluffless_html ), 'réseaux : ressource externe chargée' );

	// 5. Certifications : image de la médiathèque, texte de remplacement tiré du titre, chargement différé.
	$fluffless_html = $fluffless_render( 'certifications' );
	$fluffless_assert( str_contains( $fluffless_html, 'alt="B Corp"' ) && str_contains( $fluffless_html, 'loading="lazy"' ), 'certifications : alt ou loading absent' );

	// 6. Barre légale : ©, année, politique, reconnaissance, crédit ; pas de lien témoins sans service déclaré.
	$fluffless_html = $fluffless_render( 'legal' );
	$fluffless_assert( str_contains( $fluffless_html, '© ' . wp_date( 'Y' ) ), 'légal : année courante absente' );
	$fluffless_assert( str_contains( $fluffless_html, 'https://exemple.ca/confidentialite' ), 'légal : politique absente' );
	$fluffless_assert( str_contains( $fluffless_html, 'non cédé' ) && str_contains( $fluffless_html, 'fluffless.dev' ), 'légal : reconnaissance ou crédit absent' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'data-fluffless-consent-open' ), 'légal : lien témoins sans service déclaré' );
	$fluffless_assert( ! str_contains( $fluffless_html, '·' ), 'légal : séparateur sans sélecteur de langue' );

	// Le lien vient de l'extension gratuite Fluffless Consent (fluffless_consent_link()), si elle est active.
	if ( function_exists( 'fluffless_consent_link' ) ) {
		add_filter(
			'fluffless_consent_services',
			static fn(): array => array(
				'video' => array(
					'category' => 'media',
					'label'    => 'Vidéo',
				),
			)
		);
		Consent::reset();
		$fluffless_assert( str_contains( $fluffless_render( 'legal' ), 'data-fluffless-consent-open' ), 'légal : lien témoins absent alors qu\'un service est déclaré' );
		remove_all_filters( 'fluffless_consent_services' );
		Consent::reset();
	}

	// 7. Langues : un « · » seulement entre deux langues rendues ; rien avec une seule langue.
	$fluffless_languages = array(
		array(
			'slug'    => 'fr',
			'name'    => 'Français',
			'url'     => home_url( '/' ),
			'current' => true,
		),
		array(
			'slug' => 'en',
			'name' => 'English',
			'url'  => home_url( '/en/' ),
		),
		array(
			'slug' => '<x>',
			'url'  => 'https://exemple.ca',
		),
	);
	add_filter( 'fluffless_footer_languages', static fn(): array => $fluffless_languages );
	$fluffless_html = $fluffless_render( 'legal' );
	$fluffless_assert( 1 === substr_count( $fluffless_html, '·' ), 'langues : un seul séparateur attendu entre deux langues' );
	$fluffless_assert( str_contains( $fluffless_html, 'hreflang="en"' ) && str_contains( $fluffless_html, 'aria-current="true"' ), 'langues : lien ou langue courante absent' );
	$fluffless_assert( 1 !== preg_match( '/·\s*<\/span>\s*<\/span>|<span>\s*<span aria-hidden="true">/', $fluffless_html ), 'langues : séparateur orphelin' );
	remove_all_filters( 'fluffless_footer_languages' );
	add_filter( 'fluffless_footer_languages', static fn(): array => array_slice( $fluffless_languages, 0, 1 ) );
	$fluffless_html = $fluffless_render( 'legal' );
	$fluffless_assert( ! str_contains( $fluffless_html, '·' ) && ! str_contains( $fluffless_html, 'hreflang' ), 'langues : sélecteur affiché pour une seule langue' );
	remove_all_filters( 'fluffless_footer_languages' );

	// 8. Chaque élément éteint : son bloc est vide, ou absent de la barre légale.
	$fluffless_off = array();
	foreach ( Footer::ELEMENTS as $fluffless_element ) {
		$fluffless_off[ 'show_' . $fluffless_element ] = false;
	}
	$fluffless_set( $fluffless_off + $fluffless_all_on );
	foreach ( array( 'badge-hebergement', 'reseaux', 'certifications', 'lien-infolettre' ) as $fluffless_block ) {
		$fluffless_assert( '' === $fluffless_render( $fluffless_block ), "élément éteint : $fluffless_block non vide" );
	}
	$fluffless_html = $fluffless_render( 'legal' );
	$fluffless_assert( 1 === substr_count( $fluffless_html, '<span' ) && ! str_contains( $fluffless_html, '<a ' ), 'élément éteint : la barre légale doit se réduire au ©' );

	// 9. Contenu manquant : élément actif mais vide, bloc vide.
	$fluffless_set(
		array(
			'network_urls'      => '',
			'certification_ids' => '',
			'newsletter_url'    => '',
			'privacy_url'       => '',
			'land_text'         => '',
		)
	);
	foreach ( array( 'reseaux', 'certifications', 'lien-infolettre' ) as $fluffless_block ) {
		$fluffless_assert( '' === $fluffless_render( $fluffless_block ), "contenu vide : $fluffless_block non vide" );
	}
	$fluffless_assert( ! str_contains( $fluffless_render( 'legal' ), 'Privacy policy' ), 'contenu vide : lien de politique sans adresse' );

	// 10. Module éteint : tous les blocs vides.
	$fluffless_set( $fluffless_all_on, false );
	foreach ( $fluffless_blocks as $fluffless_block ) {
		$fluffless_assert( '' === $fluffless_render( $fluffless_block ), "module éteint : $fluffless_block non vide" );
	}

	// 11. Classes d'appareil (celles du module Visibilité) et leur CSS en ligne.
	$fluffless_set(
		array(
			'networks_device' => 'mobile',
			'privacy_device'  => 'desktop',
			'credit_device'   => 'bogus',
		) + $fluffless_all_on
	);
	$fluffless_assert( str_contains( $fluffless_render( 'reseaux' ), 'fluffless-hide-desktop' ), 'appareil : classe mobile absente des réseaux' );
	$fluffless_html = $fluffless_render( 'legal' );
	$fluffless_assert( str_contains( $fluffless_html, '<span class="fluffless-hide-mobile"><a href="https://exemple.ca/confidentialite"' ), 'appareil : classe ordinateur absente de la politique' );
	$fluffless_assert( 'all' === Options::get_string( 'footer', 'credit_device' ), 'appareil : valeur hors liste conservée' );
	$fluffless_assert( wp_style_is( 'fluffless-visibility', 'enqueued' ), 'appareil : CSS de visibilité non ajouté' );

	// 12. Empreinte placée dans une zone de pied de page : suit son interrupteur et son appareil.
	$fluffless_post = (int) wp_insert_post(
		array(
			'post_title'  => 'Empreinte test',
			'post_status' => 'publish',
		)
	);
	update_post_meta(
		$fluffless_post,
		Footprint::META,
		array(
			'return'   => 0.08,
			'new'      => 0.12,
			'grade'    => 'A',
			'measured' => time(),
		)
	);
	$fluffless_post_object = get_post( $fluffless_post );
	if ( ! $fluffless_post_object instanceof WP_Post ) {
		throw new RuntimeException( 'Article de test introuvable (wp_insert_post a échoué).' );
	}
	$GLOBALS['post'] = $fluffless_post_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- contexte de rendu du bloc Empreinte.
	setup_postdata( $fluffless_post_object );
	$fluffless_zone = '<!-- wp:group {"className":"fluffless-zone-confiance"} --><div class="wp-block-group fluffless-zone-confiance"><!-- wp:fluffless/empreinte /--></div><!-- /wp:group -->';
	$fluffless_set( array( 'footprint_device' => 'desktop' ) + $fluffless_all_on );
	$fluffless_html = do_blocks( $fluffless_zone );
	$fluffless_assert( str_contains( $fluffless_html, 'wp-block-fluffless-empreinte' ) && str_contains( $fluffless_html, 'fluffless-hide-mobile' ), 'empreinte : absente ou sans classe d\'appareil dans la zone' );
	$fluffless_set( array( 'show_footprint' => false ) + $fluffless_all_on );
	$fluffless_assert( ! str_contains( do_blocks( $fluffless_zone ), 'wp-block-fluffless-empreinte' ), 'empreinte : affichée dans la zone alors qu\'elle est éteinte' );
	$fluffless_assert( str_contains( $fluffless_render( 'empreinte' ), 'wp-block-fluffless-empreinte' ), 'empreinte : hors zone, elle ne doit pas dépendre du pied de page' );
	wp_reset_postdata();

	// 13. Réglages : l'interrupteur et son choix d'appareil sur la même ligne, libellé relié.
	$fluffless_fields = Footer::fields();
	ob_start();
	SettingsPage::render_field(
		array(
			'module' => 'footer',
			'key'    => 'show_badge',
			'field'  => $fluffless_fields['show_badge'],
			'paired' => $fluffless_fields['badge_device'],
		)
	);
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_html, 'type="checkbox"' ) && str_contains( $fluffless_html, '<label for="fluffless-footer-badge-device">' ) && str_contains( $fluffless_html, '<select id="fluffless-footer-badge-device"' ), 'réglages : interrupteur et appareil non couplés' );
} finally {
	if ( null !== $fluffless_hide_consent ) {
		remove_filter( 'option_' . Options::NAME, $fluffless_hide_consent );
		Options::flush();
	}
	if ( null === $fluffless_backup_option ) {
		delete_option( Options::NAME );
	} else {
		update_option( Options::NAME, $fluffless_backup_option );
	}
	if ( false === $fluffless_backup_host ) {
		delete_transient( Lifecycle::TRANSIENT_HOST_STATUS );
	} else {
		set_transient( Lifecycle::TRANSIENT_HOST_STATUS, $fluffless_backup_host, DAY_IN_SECONDS );
	}
	update_option( 'wp_page_for_privacy_policy', $fluffless_backup_privacy );
	if ( class_exists( Consent::class ) && null !== $fluffless_backup_embeds ) {
		update_option( Consent::EMBEDS_OPTION, $fluffless_backup_embeds, false );
	}
	if ( $fluffless_attachment > 0 ) {
		wp_delete_attachment( $fluffless_attachment, true );
	}
	if ( $fluffless_post > 0 ) {
		wp_delete_post( $fluffless_post, true );
	}
	Options::flush();
	restore_previous_locale();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles (pied de page) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}

echo esc_html( 'OK : ' . $fluffless_checks . " contrôles réussis (pied de page : réglages, badge, réseaux, certifications, infolettre, barre légale, empreinte, appareils).\n" );
