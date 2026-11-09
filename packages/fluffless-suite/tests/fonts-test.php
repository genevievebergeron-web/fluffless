<?php
/**
 * Tests du module Polices libres.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/fonts-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Modules\Fonts;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Fonts::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// 1. Collection : 20 à 30 familles, une face WOFF2 chacune, sous le budget à elle seule.
// Source : Google Fonts (secours de la banque), vide (banque seulement) ou Fontshare (licence ITF).
$fluffless_families = Fonts::catalogue();
$fluffless_assert( count( $fluffless_families ) >= 20 && count( $fluffless_families ) <= 30, 'collection : 20 à 30 familles attendues' );
foreach ( $fluffless_families as $fluffless_slug => $fluffless_family ) {
	$fluffless_src  = $fluffless_family['src'];
	$fluffless_host = isset( $fluffless_family['licence'] ) ? 'https://cdn.fontshare.com/' : 'https://fonts.gstatic.com/';
	$fluffless_assert( ( '' === $fluffless_src && ! isset( $fluffless_family['licence'] ) ) || ( str_starts_with( $fluffless_src, $fluffless_host ) && str_ends_with( $fluffless_src, '.woff2' ) ), "collection : source WOFF2 invalide pour {$fluffless_slug}" );
	$fluffless_assert( $fluffless_family['bytes'] > 0 && $fluffless_family['bytes'] < Fonts::BUDGET_BYTES, "collection : poids hors budget pour {$fluffless_slug}" );
	// Empreinte (banque ou Fontshare), vérifiée au téléchargement.
	$fluffless_assert( 1 === preg_match( '/^[0-9a-f]{64}$/', $fluffless_family['sha256'] ), "collection : empreinte SHA-256 absente pour {$fluffless_slug}" );
}

// 2. Duos (12, nombre garanti par l'analyse statique de la constante) : deux fichiers au plus, sous le budget polices.
foreach ( Fonts::PAIRS as $fluffless_pair ) {
	$fluffless_assert( isset( $fluffless_families[ $fluffless_pair[0] ] ), "duo : famille de titre inconnue {$fluffless_pair[0]}" );
	$fluffless_assert( 'system' === $fluffless_pair[1] || isset( $fluffless_families[ $fluffless_pair[1] ] ), "duo : famille de texte inconnue {$fluffless_pair[1]}" );
	$fluffless_assert( Fonts::pair_bytes( $fluffless_pair[0], $fluffless_pair[1] ) <= Fonts::BUDGET_BYTES, "duo hors budget : {$fluffless_pair[0]} + {$fluffless_pair[1]}" );
}

// 3. Enregistrement : WordPress accepte la collection et garde unicodeRange, fontDisplay et fontStretch.
Fonts::register_collection();
$fluffless_collection = WP_Font_Library::get_instance()->get_font_collection( Fonts::COLLECTION );
$fluffless_assert( $fluffless_collection instanceof WP_Font_Collection, 'enregistrement : collection absente' );
if ( $fluffless_collection instanceof WP_Font_Collection ) {
	$fluffless_data = $fluffless_collection->get_data();
	$fluffless_assert( ! is_wp_error( $fluffless_data ), 'enregistrement : données refusées par WordPress' );
	if ( is_array( $fluffless_data ) ) {
		$fluffless_assert( count( $fluffless_data['font_families'] ) === count( $fluffless_families ), 'enregistrement : familles perdues à l\'assainissement' );
		$fluffless_faces = array_merge( ...array_map( static fn( array $f ): array => $f['font_family_settings']['fontFace'], $fluffless_data['font_families'] ) );
		$fluffless_assert( array() === array_filter( $fluffless_faces, static fn( array $f ): bool => Fonts::LATIN !== ( $f['unicodeRange'] ?? '' ) || 'swap' !== ( $f['fontDisplay'] ?? '' ) ), 'enregistrement : unicodeRange ou fontDisplay perdu' );
		$fluffless_assert( in_array( '50% 150%', array_column( $fluffless_faces, 'fontStretch' ), true ), 'enregistrement : fontStretch d\'Anybody perdu' );
	}
	// Double appel sans avertissement (collection déjà enregistrée).
	Fonts::register_collection();
	++$fluffless_checks;
}

// 4. Aucun visiteur ne paie la collection : elle n'est accrochée qu'à rest_api_init.
( new Fonts() )->register();
$fluffless_assert( false === has_action( 'init', array( Fonts::class, 'register_collection' ) ), 'coût visiteur : collection enregistrée sur init' );
$fluffless_assert( false !== has_action( 'rest_api_init', array( Fonts::class, 'register_collection' ) ), 'REST : collection non accrochée' );

// 5. Polices installées : lecture sans erreur, total cohérent.
$fluffless_installed = Fonts::installed();
$fluffless_sum       = array_sum( array_column( $fluffless_installed['families'], 'bytes' ) );
$fluffless_assert( $fluffless_sum === $fluffless_installed['bytes'], 'installées : total incohérent' );

// 6. Écran : rendu sans erreur pour une personne administratrice.
$fluffless_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );
ob_start();
Fonts::render();
$fluffless_html = (string) ob_get_clean();
$fluffless_assert( str_contains( $fluffless_html, 'Bricolage Grotesque' ) && str_contains( $fluffless_html, 'site-editor.php' ), 'écran : collection ou lien absent' );

// 7. Duo en un clic, sans réseau : le téléchargement est simulé avec un vrai WOFF2 du thème Twenty Twenty-Five.
$fluffless_fixture = WP_CONTENT_DIR . '/themes/twentytwentyfive/assets/fonts/fira-code/FiraCode-VariableFont_wght.woff2';
$fluffless_styles  = Fluffless\Suite\Core\GlobalStyles::user();
if ( ! is_readable( $fluffless_fixture ) || null === $fluffless_styles ) {
	$fluffless_assert( false, 'duo : fichier d\'essai ou styles globaux indisponibles' );
} else {
	$fluffless_before_posts = get_posts(
		array(
			'post_type'   => array( 'wp_font_family', 'wp_font_face' ),
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	$fluffless_option       = get_option( Fonts::OPTION );
	// Objet partagé : la fermeture et le test lisent le même mode réseau et le même compteur.
	$fluffless_net  = new ArrayObject(
		array(
			'mode'  => 'ok',
			'calls' => 0,
		)
	);
	$fluffless_mock = static function ( $pre, array $args, string $url ) use ( $fluffless_net, $fluffless_fixture ) {
		if ( ! str_starts_with( $url, 'https://fonts.gstatic.com/' ) && ! str_starts_with( $url, 'https://cdn.fontshare.com/' ) && ! str_starts_with( $url, Fonts::BANK . '/' ) ) {
			return $pre;
		}
		++$fluffless_net['calls'];
		if ( 'down' === $fluffless_net['mode'] ) {
			return new WP_Error( 'http_request_failed', 'hors ligne (essai)' );
		}
		if ( in_array( $fluffless_net['mode'], array( '404', '503' ), true ) ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => (int) $fluffless_net['mode'],
					'message' => '404' === $fluffless_net['mode'] ? 'Not Found' : 'Service Unavailable',
				),
				'cookies'  => array(),
				'filename' => $args['filename'],
			);
		}
		copy( 'html' === $fluffless_net['mode'] ? __FILE__ : $fluffless_fixture, (string) $args['filename'] );
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'],
		);
	};
	add_filter( 'pre_http_request', $fluffless_mock, 10, 3 );
	// C6-1-03 : filet de sécurité, aucune requête de l'essai ne sort pour de vrai ; les adresses bloquées sont notées.
	/**
	 * Adresses bloquées.
	 *
	 * @var ArrayObject<int, string> $fluffless_blocked
	 */
	$fluffless_blocked = new ArrayObject();
	$fluffless_guard   = static function ( $pre, array $args, string $url ) use ( $fluffless_blocked ) {
		unset( $args );
		if ( false !== $pre ) {
			return $pre;
		}
		$fluffless_blocked[] = $url;
		return new WP_Error( 'http_request_failed', 'requête bloquée par l’essai' );
	};
	add_filter( 'pre_http_request', $fluffless_guard, 100, 3 );
	delete_option( Fonts::OPTION );
	$fluffless_initial = Fluffless\Suite\Core\GlobalStyles::user()['data'] ?? array();
	$fluffless_read    = static fn( string $path ) => Fluffless\Suite\Core\GlobalStyles::get( Fluffless\Suite\Core\GlobalStyles::user()['data'] ?? array(), $path );
	$fluffless_slugs   = static fn(): array => array_column( (array) $fluffless_read( Fonts::PATH_CUSTOM ), 'slug' );

	// Adresse filtrée refusée si ce n'est pas un WOFF2 en HTTPS.
	$fluffless_http = static fn(): string => 'http://exemple.test/police.woff2';
	add_filter( 'fluffless_font_src', $fluffless_http );
	$fluffless_assert( '' === Fonts::source( 'anybody' ), 'source : adresse HTTP acceptée' );
	remove_filter( 'fluffless_font_src', $fluffless_http );

	// Banque Fluffless : par défaut, puis celle réglée ; sans banque valide, l'hôte d'origine.
	$fluffless_assert( Fonts::BANK . '/fonts/anybody.woff2' === Fonts::source( 'anybody' ), 'source : banque par défaut ignorée' );
	add_filter( 'fluffless_font_bank', '__return_empty_string' );
	$fluffless_origin = Fonts::source( 'anybody' );
	$fluffless_orphan = Fonts::source( 'lineal' );
	$fluffless_listed = array_column( array_column( Fonts::font_families(), 'font_family_settings' ), 'slug' );
	remove_filter( 'fluffless_font_bank', '__return_empty_string' );
	$fluffless_assert( str_starts_with( $fluffless_origin, 'https://fonts.gstatic.com/' ), 'source : hôte d’origine perdu sans banque' );
	$fluffless_assert( '' === $fluffless_orphan && ! in_array( 'lineal', $fluffless_listed, true ) && in_array( 'satoshi', $fluffless_listed, true ), 'sans banque : famille de l’archive proposée ou famille Fontshare perdue' );
	$fluffless_bank = static fn(): string => 'https://banque.exemple.test/';
	add_filter( 'fluffless_font_bank', $fluffless_bank );
	$fluffless_assert( 'https://banque.exemple.test/fonts/anybody.woff2' === Fonts::source( 'anybody' ), 'source : banque ignorée' );
	$fluffless_assert( 'https://banque.exemple.test/fonts/anybody.woff2' === ( Fonts::font_families()[3]['font_family_settings']['fontFace'][0]['src'] ?? '' ), 'collection : banque ignorée' );
	$fluffless_assert( '' === Fonts::source( 'inconnue' ), 'source : famille inconnue servie par la banque' );
	$fluffless_assert( 'https://banque.exemple.test/fonts/lineal.woff2' === Fonts::source( 'lineal' ), 'source : famille de l’archive hors banque' );
	$fluffless_assert( str_starts_with( Fonts::source( 'satoshi' ), 'https://cdn.fontshare.com/' ), 'source : famille Fontshare servie par la banque' );
	remove_filter( 'fluffless_font_bank', $fluffless_bank );
	$fluffless_bank = static fn(): string => 'http://banque.exemple.test';
	add_filter( 'fluffless_font_bank', $fluffless_bank );
	$fluffless_assert( '' === Fonts::bank() && Fonts::source( 'anybody' ) === $fluffless_origin, 'banque : adresse HTTP acceptée' );
	remove_filter( 'fluffless_font_bank', $fluffless_bank );

	// C6-1-01 : une cause par échec. Panne passagère (hors ligne, 503) : « download », réessayer
	// plus tard. Fichier refusé ou disparu (pas un WOFF2, 404) : « integrity », réessayer n'y fera rien.
	$fluffless_causes = array();
	$fluffless_listen = static function ( string $cause, string $slug ) use ( &$fluffless_causes ): void {
		$fluffless_causes[] = $cause . ':' . $slug;
	};
	add_action( 'fluffless_fonts_failure', $fluffless_listen, 10, 2 );
	foreach ( array(
		'down' => 'download',
		'503'  => 'download',
		'html' => 'integrity',
		'404'  => 'integrity',
	) as $fluffless_case => $fluffless_cause ) {
		$fluffless_net['mode'] = (string) $fluffless_case;
		$fluffless_result      = Fonts::apply_pair( 3 );
		$fluffless_assert( $fluffless_cause === $fluffless_result, "duo ({$fluffless_case}) : cause {$fluffless_cause} attendue, reçu {$fluffless_result}" );
		$fluffless_assert( ( Fluffless\Suite\Core\GlobalStyles::user()['data'] ?? null ) === $fluffless_initial, "duo ({$fluffless_case}) : styles globaux modifiés malgré l'échec" );
	}
	$fluffless_net['mode'] = 'ok';
	$fluffless_assert( array( 'download:anybody', 'download:anybody', 'integrity:anybody', 'integrity:anybody' ) === $fluffless_causes, 'journal : cause non transmise à fluffless_fonts_failure (' . implode( ', ', $fluffless_causes ) . ')' );
	remove_action( 'fluffless_fonts_failure', $fluffless_listen, 10 );
	// Les messages sont comparés dans leur langue source, quelle que soit celle du site.
	switch_to_locale( 'en_US' );
	$fluffless_assert( str_contains( Fonts::failure_message( 'download' ), 'Try again in a few minutes' ), 'message : panne passagère sans invitation à réessayer' );
	$fluffless_assert( ! str_contains( Fonts::failure_message( 'integrity' ), 'Try again' ) && str_contains( Fonts::failure_message( 'integrity' ), 'another pair' ), 'message : fichier changé, réessayer promis ou autre duo non proposé' );
	$fluffless_assert( str_contains( Fonts::failure_message( 'write' ), 'wp-content/fonts' ) && str_contains( Fonts::failure_message( 'write' ), 'host' ), 'message : dossier fermé sans renvoi à l’hébergeur' );
	restore_previous_locale();

	// Vrai WOFF2, mais pas le fichier attendu : empreinte refusée, rien n'est écrit.
	$fluffless_assert( 'integrity' === Fonts::apply_pair( 3 ), 'duo : fichier à l’empreinte inconnue accepté' );
	$fluffless_assert( ( Fluffless\Suite\Core\GlobalStyles::user()['data'] ?? null ) === $fluffless_initial, 'duo (empreinte) : styles globaux modifiés malgré l’échec' );

	// Empreinte vide : refusée aussi, pas de téléchargement sans vérification d'intégrité.
	$fluffless_blank = static fn(): string => '';
	add_filter( 'fluffless_font_sha256', $fluffless_blank );
	$fluffless_assert( 'integrity' === Fonts::apply_pair( 3 ), 'duo : fichier accepté sans empreinte' );
	remove_filter( 'fluffless_font_sha256', $fluffless_blank );

	// C6-1-03 : duo Fontshare (8), servi par cdn.fontshare.com, dont le fichier a changé : refusé.
	$fluffless_net['calls'] = 0;
	$fluffless_assert( 'integrity' === Fonts::apply_pair( 8 ), 'duo Fontshare : fichier à l’empreinte différente accepté' );
	$fluffless_assert( 1 === $fluffless_net->offsetGet( 'calls' ) && 0 === count( $fluffless_blocked ), 'duo Fontshare : requête non simulée (' . implode( ', ', (array) $fluffless_blocked->getArrayCopy() ) . ')' );
	$fluffless_assert( ( Fluffless\Suite\Core\GlobalStyles::user()['data'] ?? null ) === $fluffless_initial, 'duo Fontshare : styles globaux modifiés malgré l’échec' );

	// C6-1-03 : route du panneau en panne passagère : 502, code et message de la cause.
	switch_to_locale( 'en_US' );
	$fluffless_net['mode'] = 'down';
	$fluffless_down        = new WP_REST_Request( 'POST', '/fluffless/v1/fonts/pair' );
	$fluffless_down->set_param( 'pair', 3 );
	$fluffless_down        = rest_do_request( $fluffless_down );
	$fluffless_net['mode'] = 'ok';
	restore_previous_locale();
	$fluffless_error = (array) $fluffless_down->get_data();
	$fluffless_assert( 502 === $fluffless_down->get_status() && 'fluffless_fonts_download' === ( $fluffless_error['code'] ?? '' ), 'route en panne : 502 fluffless_fonts_download attendu, reçu ' . $fluffless_down->get_status() . ' ' . ( $fluffless_error['code'] ?? '' ) );
	$fluffless_assert( str_contains( (string) ( $fluffless_error['message'] ?? '' ), 'Try again in a few minutes' ), 'route en panne : message de la panne passagère absent' );
	$fluffless_assert( false === get_option( Fonts::OPTION ), 'route en panne : duo noté comme appliqué' );

	// C6-1-02 : duo dont la 2e famille échoue. La 1re, installée pendant l'appel, est retirée :
	// ni article, ni fichier de plus dans la Bibliothèque, donc « le site n'a pas changé » dit vrai.
	$fluffless_first      = static fn( $sha, $slug ) => 'instrument-serif' === $slug ? (string) hash_file( 'sha256', $fluffless_fixture ) : $sha;
	$fluffless_font_files = static fn(): array => (array) glob( trailingslashit( wp_get_font_dir()['path'] ) . '*' );
	$fluffless_family_ids = static fn(): array => get_posts(
		array(
			'post_type'   => array( 'wp_font_family', 'wp_font_face' ),
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	$fluffless_files_was  = $fluffless_font_files();
	$fluffless_ids_was    = $fluffless_family_ids();
	add_filter( 'fluffless_font_sha256', $fluffless_first, 10, 2 );
	$fluffless_assert( 'applied' !== Fonts::apply_pair( 0 ), 'duo partiel : appliqué malgré la 2e famille refusée' );
	remove_filter( 'fluffless_font_sha256', $fluffless_first, 10 );
	$fluffless_assert( array() === array_diff( $fluffless_family_ids(), $fluffless_ids_was ), 'duo partiel : articles de police laissés dans la Bibliothèque' );
	$fluffless_assert( array() === array_diff( $fluffless_font_files(), $fluffless_files_was ), 'duo partiel : fichier de police laissé dans wp-content/fonts' );
	$fluffless_assert( ( Fluffless\Suite\Core\GlobalStyles::user()['data'] ?? null ) === $fluffless_initial, 'duo partiel : styles globaux modifiés malgré l’échec' );

	// La suite accepte l'empreinte du fichier d'essai, comme le ferait un site qui vise sa propre copie.
	$fluffless_sha = static fn(): string => (string) hash_file( 'sha256', $fluffless_fixture );
	add_filter( 'fluffless_font_sha256', $fluffless_sha );

	// C6-1-01 : fichier reçu et vérifié, mais dossier des polices fermé à l'écriture : « write ».
	$fluffless_closed = trailingslashit( get_temp_dir() ) . 'fl-polices-fermees-' . wp_generate_password( 6, false );
	wp_mkdir_p( $fluffless_closed );
	chmod( $fluffless_closed, 0555 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- dossier d'essai en lecture seule.
	$fluffless_ro = static function ( array $dir ) use ( $fluffless_closed ): array {
		$dir['path'] = $fluffless_closed;
		return $dir;
	};
	add_filter( 'upload_dir', $fluffless_ro, 99 );
	$fluffless_assert( 'write' === Fonts::apply_pair( 3 ), 'duo (dossier fermé) : cause write attendue' );
	remove_filter( 'upload_dir', $fluffless_ro, 99 );
	chmod( $fluffless_closed, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- remise en écriture avant suppression.
	rmdir( $fluffless_closed ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- dossier vide créé par l'essai.
	$fluffless_assert( ( Fluffless\Suite\Core\GlobalStyles::user()['data'] ?? null ) === $fluffless_initial, 'duo (dossier fermé) : styles globaux modifiés malgré l’échec' );

	// C6-1-01 : fichier écrit, mais face refusée par la base : « save », pas « write » (le dossier n'y est pour rien).
	$fluffless_refuse = static fn( bool $is_empty, array $post ): bool => 'wp_font_face' === ( $post['post_type'] ?? '' ) ? true : $is_empty;
	add_filter( 'wp_insert_post_empty_content', $fluffless_refuse, 10, 2 );
	$fluffless_assert( 'save' === Fonts::apply_pair( 3 ), 'duo (base refusée) : cause save attendue' );
	remove_filter( 'wp_insert_post_empty_content', $fluffless_refuse, 10 );
	$fluffless_assert( ( Fluffless\Suite\Core\GlobalStyles::user()['data'] ?? null ) === $fluffless_initial, 'duo (base refusée) : styles globaux modifiés malgré l’échec' );
	$fluffless_save = Fonts::failure_message( 'save' );
	$fluffless_assert( str_contains( $fluffless_save, 'Try again' ) && ! str_contains( $fluffless_save, 'folder' ) && ! str_contains( $fluffless_save, 'downloaded' ), 'message (base refusée) : dossier ou téléchargement mis en cause à tort' );

	// Duo à deux polices : fichiers installés, familles actives, titres et texte posés.
	$fluffless_assert( 'applied' === Fonts::apply_pair( 0 ), 'duo 0 : non appliqué' );
	$fluffless_assert( 'var:preset|font-family|instrument-serif' === $fluffless_read( Fonts::PATH_HEADING ), 'duo 0 : titres non posés' );
	$fluffless_assert( 'var:preset|font-family|instrument-sans' === $fluffless_read( Fonts::PATH_BODY ), 'duo 0 : texte non posé' );
	$fluffless_assert( array() === array_diff( array( 'instrument-serif', 'instrument-sans' ), $fluffless_slugs() ), 'duo 0 : familles non activées' );
	$fluffless_custom = (array) $fluffless_read( Fonts::PATH_CUSTOM );
	$fluffless_font   = wp_get_font_dir();
	foreach ( $fluffless_custom as $fluffless_family ) {
		$fluffless_src = (string) ( $fluffless_family['fontFace'][0]['src'] ?? '' );
		$fluffless_assert( str_starts_with( $fluffless_src, $fluffless_font['url'] ) && is_readable( trailingslashit( $fluffless_font['path'] ) . wp_basename( $fluffless_src ) ), 'duo 0 : fichier non servi depuis le site' );
	}
	$fluffless_assert( 0 === Fonts::current_pair(), 'duo 0 : non reconnu comme en place' );
	$fluffless_installed = Fonts::installed();
	$fluffless_assert( isset( $fluffless_installed['families']['Instrument Serif'], $fluffless_installed['families']['Instrument Sans'] ), 'duo 0 : absent du budget polices' );

	// Réappliquer ne retélécharge rien.
	$fluffless_net['calls'] = 0;
	$fluffless_assert( 'applied' === Fonts::apply_pair( 0 ) && 0 === $fluffless_net->offsetGet( 'calls' ), 'duo 0 : retéléchargé alors que déjà installé' );

	// Duo titre libre + texte système : le texte revient à sa valeur d'origine, les autres familles de la collection sont désactivées.
	$fluffless_assert( 'applied' === Fonts::apply_pair( 6 ), 'duo 6 : non appliqué' );
	$fluffless_assert( Fluffless\Suite\Core\GlobalStyles::get( $fluffless_initial, Fonts::PATH_BODY ) === $fluffless_read( Fonts::PATH_BODY ), 'duo 6 : texte système non rétabli' );
	$fluffless_assert( array( 'bricolage-grotesque' ) === array_values( array_intersect( $fluffless_slugs(), array_keys( Fonts::catalogue() ) ) ), 'duo 6 : familles du duo précédent encore actives' );

	// Écran : duo en place marqué, bouton de retour présent, formulaires avec nonce.
	wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );
	switch_to_locale( 'en_US' );
	ob_start();
	Fonts::render();
	$fluffless_html = (string) ob_get_clean();
	restore_previous_locale();
	wp_set_current_user( 0 );
	// P2-08 : le paragraphe d'essai déplié dit ce qu'il montre ; le titre, lui, reste décoratif (nom écrit à côté).
	$fluffless_assert( 1 === preg_match( '#<details><summary>[^<]+</summary><img [^>]*alt="Sample paragraph set in [^"]+"#', $fluffless_html ), 'écran : paragraphe d’essai sans texte de remplacement' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="fl_pair"' ) && str_contains( $fluffless_html, 'name="fl_reset"' ) && str_contains( $fluffless_html, 'name="_wpnonce"' ), 'écran : formulaires de duo absents' );

	// Retour aux polices du thème : styles d'origine, option retirée.
	$fluffless_assert( Fonts::reset(), 'retour : échec' );
	$fluffless_assert( Fluffless\Suite\Core\GlobalStyles::get( $fluffless_initial, Fonts::PATH_HEADING ) === $fluffless_read( Fonts::PATH_HEADING ), 'retour : titres non rétablis' );
	$fluffless_assert( array() === array_intersect( $fluffless_slugs(), array_keys( Fonts::catalogue() ) ), 'retour : familles de la collection encore actives' );
	$fluffless_assert( false === get_option( Fonts::OPTION ), 'retour : option restée' );

	// Nettoyage : seulement ce que l'essai a créé.
	remove_filter( 'pre_http_request', $fluffless_mock, 10 );
	remove_filter( 'pre_http_request', $fluffless_guard, 100 );
	remove_filter( 'fluffless_font_sha256', $fluffless_sha );
	$fluffless_after = get_posts(
		array(
			'post_type'   => array( 'wp_font_family', 'wp_font_face' ),
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	foreach ( array_diff( $fluffless_after, $fluffless_before_posts ) as $fluffless_id ) {
		wp_delete_post( (int) $fluffless_id, true );
	}
	Fluffless\Suite\Core\GlobalStyles::save( $fluffless_styles['id'], $fluffless_styles['data'] );
	if ( false !== $fluffless_option ) {
		update_option( Fonts::OPTION, $fluffless_option, false );
	}
}

// Panneau de l'éditeur de site : route réservée à edit_theme_options, un duo par entrée.
$fluffless_routes = rest_get_server()->get_routes();
$fluffless_assert( isset( $fluffless_routes['/fluffless/v1/fonts/pair'] ), 'éditeur : route fonts/pair absente' );
$fluffless_editor = Fonts::editor_data();
$fluffless_assert( count( $fluffless_editor['pairs'] ) === count( Fonts::PAIRS ) && array_keys( Fonts::catalogue() ) === $fluffless_editor['collection'], 'éditeur : duos ou collection incomplets' );
// Aperçus : fichier de la bonne famille, ou rien (famille Fontshare pas encore générée) ; pas de paragraphe pour la pile système.
$fluffless_titles = 0;
foreach ( Fonts::PAIRS as $fluffless_index => $fluffless_pair ) {
	$fluffless_row     = $fluffless_editor['pairs'][ $fluffless_index ];
	$fluffless_titles += '' === $fluffless_row['title'] ? 0 : 1;
	$fluffless_assert( '' === $fluffless_row['title'] || 1 === preg_match( '#/assets/specimens/' . $fluffless_pair[0] . '-title\.(svg|webp)$#', $fluffless_row['title'] ), 'aperçu : titre ' . $fluffless_row['title'] );
	$fluffless_assert( 'system' === $fluffless_pair[1] ? '' === $fluffless_row['text'] : ( '' === $fluffless_row['text'] || str_ends_with( $fluffless_row['text'], '/assets/specimens/' . $fluffless_pair[1] . '-text.webp' ) ), 'aperçu : paragraphe ' . $fluffless_row['text'] );
}
$fluffless_assert( $fluffless_titles > 0, 'aperçu : aucun titre trouvé' );
wp_set_current_user( 0 );
$fluffless_request = new WP_REST_Request( 'POST', '/fluffless/v1/fonts/pair' );
$fluffless_request->set_param( 'pair', 0 );
$fluffless_assert( 401 === rest_do_request( $fluffless_request )->get_status(), 'éditeur : route ouverte sans connexion' );
$fluffless_request->set_param( 'pair', count( Fonts::PAIRS ) );
$fluffless_assert( 400 <= rest_do_request( $fluffless_request )->get_status(), 'éditeur : duo hors liste accepté' );

wp_set_current_user( 0 );

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Polices, {$fluffless_checks} vérifications réussies.\n" );
