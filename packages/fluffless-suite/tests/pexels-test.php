<?php
/**
 * Tests du module Pexels, avec réponses HTTP simulées (aucune requête réelle vers Pexels).
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/pexels-test.php
 *
 * @package Fluffless_Suite
 */

// Le test simule des réponses par le crochet du cœur pre_http_request et définit la constante de clé.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

use Fluffless\Suite\Modules\Pexels;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Pexels::class ) ) {
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

$fluffless_key     = 'cle-de-test-' . wp_generate_password( 12, false );
$fluffless_calls   = array();
$fluffless_status  = 200;
$fluffless_created = array();
$fluffless_photo   = static fn( int $id, string $host = 'images.pexels.com' ): array => array(
	'id'               => $id,
	'width'            => 2400,
	'height'           => 1600,
	'url'              => "https://www.pexels.com/photo/lac-{$id}/",
	'photographer'     => 'Camille <b>Tremblay</b>',
	'photographer_url' => 'https://www.pexels.com/@camille',
	'alt'              => 'Un lac calme au lever du soleil',
	'src'              => array(
		'original' => "https://{$host}/photos/{$id}/pexels-photo-{$id}.jpeg",
		'medium'   => "https://images.pexels.com/photos/{$id}/pexels-photo-{$id}.jpeg?h=350",
	),
);

// Réponses simulées : API (recherche, photo) et fichier image (JPEG 2400 × 1600 écrit dans le fichier demandé).
$fluffless_mock = static function ( $pre, $args, $url ) use ( &$fluffless_calls, &$fluffless_status, $fluffless_photo ) {
	$fluffless_calls[] = array(
		'url'  => $url,
		'auth' => $args['headers']['Authorization'] ?? '',
	);
	$host              = wp_parse_url( $url, PHP_URL_HOST );
	if ( 'api.pexels.com' === $host ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body = str_starts_with( $path, '/v1/search' )
			? array(
				'total_results' => 2,
				'photos'        => array( $fluffless_photo( 101 ), $fluffless_photo( 102, 'evil.example.com' ) ),
			)
			: ( str_ends_with( $path, '/666' ) ? $fluffless_photo( 666, 'evil.example.com' ) : $fluffless_photo( (int) basename( $path ) ) );
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $fluffless_status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	if ( 'images.pexels.com' === $host && ! empty( $args['filename'] ) ) {
		$image = imagecreatetruecolor( 2400, 1600 );
		imagefill( $image, 0, 0, (int) imagecolorallocate( $image, 40, 90, 140 ) );
		imagejpeg( $image, $args['filename'], 90 );
		return array(
			'headers'  => array( 'content-type' => 'image/jpeg' ),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'],
		);
	}
	return new WP_Error( 'test_unexpected', 'Requête inattendue : ' . $url );
};
/**
 * Requêtes vues par le filtre ; lues par une fonction, car le filtre remplit le tableau par référence.
 *
 * @return list<array{url: string, auth: mixed}>
 */
$fluffless_log = static function () use ( &$fluffless_calls ): array {
	return $fluffless_calls;
};
add_filter( 'pre_http_request', $fluffless_mock, 1, 3 );

$fluffless_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );

try {
	// 1. Sans clé : message clair, aucune requête.
	if ( ! defined( Pexels::KEY_CONSTANT ) ) {
		$fluffless_error = Pexels::search( 'lac', 1 );
		$fluffless_assert( is_wp_error( $fluffless_error ) && 'fluffless_pexels_no_key' === $fluffless_error->get_error_code() && str_contains( $fluffless_error->get_error_message(), 'https://www.pexels.com/api/' ), 'sans clé : message ou lien absent' );
		$fluffless_assert( array() === $fluffless_calls, 'sans clé : une requête est partie' );
		$fluffless_assert( str_contains( Pexels::status(), 'https://www.pexels.com/api/' ), 'sans clé : état sans lien' );
		define( Pexels::KEY_CONSTANT, $fluffless_key ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- nom imposé par le module.
	}
	$fluffless_key = (string) constant( Pexels::KEY_CONSTANT );

	// 2. Recherche : clé en en-tête seulement, photos d'un autre domaine écartées, cache 15 minutes.
	delete_transient( 'fluffless_pexels_' . md5( 'lac|1|' . get_locale() ) );
	$fluffless_found = Pexels::search( 'Lac', 1 );
	$fluffless_assert( is_array( $fluffless_found ) && 1 === count( $fluffless_found['photos'] ) && 101 === $fluffless_found['photos'][0]['id'], 'recherche : filtrage des photos incorrect' );
	$fluffless_assert( 1 === count( $fluffless_calls ) && $fluffless_key === $fluffless_calls[0]['auth'], 'recherche : clé absente de l\'en-tête Authorization' );
	$fluffless_assert( ! str_contains( $fluffless_calls[0]['url'], $fluffless_key ), 'recherche : clé dans l\'adresse' );
	$fluffless_assert( is_array( $fluffless_found ) && 'Camille Tremblay' === $fluffless_found['photos'][0]['photographer'], 'recherche : nom du photographe non assaini' );
	Pexels::search( 'lac', 1 );
	$fluffless_assert( 1 === count( $fluffless_calls ), 'recherche : cache non utilisé' );
	$fluffless_assert( ! str_contains( (string) wp_json_encode( $fluffless_found ), $fluffless_key ), 'recherche : clé renvoyée au navigateur' );

	// 3. Erreurs de l'API traduites en messages clairs.
	$fluffless_status = 401;
	$fluffless_error  = Pexels::search( 'erreur-401', 1 );
	$fluffless_assert( is_wp_error( $fluffless_error ) && 'fluffless_pexels_key' === $fluffless_error->get_error_code(), 'API 401 : mauvais message' );
	$fluffless_status = 429;
	$fluffless_error  = Pexels::search( 'erreur-429', 1 );
	$fluffless_assert( is_wp_error( $fluffless_error ) && 'fluffless_pexels_limit' === $fluffless_error->get_error_code(), 'API 429 : mauvais message' );
	$fluffless_status = 200;

	// 4. Routes REST : protégées par upload_files.
	Pexels::register_routes();
	$fluffless_subscriber = wp_insert_user(
		array(
			'user_login' => 'fluffless_abonne_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	$fluffless_subscriber = is_wp_error( $fluffless_subscriber ) ? 0 : $fluffless_subscriber;
	wp_set_current_user( $fluffless_subscriber );
	$fluffless_request = new WP_REST_Request( 'GET', '/fluffless/v1/pexels/search' );
	$fluffless_request->set_param( 'query', 'lac' );
	$fluffless_assert( 403 === rest_do_request( $fluffless_request )->get_status() || 401 === rest_do_request( $fluffless_request )->get_status(), 'REST : accessible sans upload_files' );
	wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );
	$fluffless_assert( 200 === rest_do_request( $fluffless_request )->get_status(), 'REST : refusée à une personne qui peut téléverser' );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $fluffless_subscriber );

	// 5. Import : WebP, largeur réglée, texte alternatif prérempli, crédit avec liens, métadonnée.
	$fluffless_calls = array();
	$fluffless_id    = Pexels::import( 101 );
	$fluffless_assert( is_int( $fluffless_id ), 'import : échec : ' . ( is_wp_error( $fluffless_id ) ? $fluffless_id->get_error_message() : '' ) );
	if ( is_int( $fluffless_id ) ) {
		$fluffless_created[] = $fluffless_id;
		$fluffless_meta      = wp_get_attachment_metadata( $fluffless_id );
		$fluffless_assert( 'image/webp' === get_post_mime_type( $fluffless_id ), 'import : pas en WebP' );
		$fluffless_assert( is_array( $fluffless_meta ) && 1600 === (int) ( $fluffless_meta['width'] ?? 0 ), 'import : largeur différente de 1600 px' );
		$fluffless_assert( 'Un lac calme au lever du soleil' === get_post_meta( $fluffless_id, '_wp_attachment_image_alt', true ), 'import : texte alternatif non prérempli' );
		$fluffless_caption = (string) get_post_field( 'post_excerpt', $fluffless_id );
		$fluffless_assert( str_contains( $fluffless_caption, 'href="https://www.pexels.com/@camille"' ) && str_contains( $fluffless_caption, 'href="https://www.pexels.com/photo/lac-101/"' ), 'import : crédit sans liens' );
		$fluffless_credit = get_post_meta( $fluffless_id, Pexels::META, true );
		$fluffless_assert( is_array( $fluffless_credit ) && 'Camille Tremblay' === $fluffless_credit['photographer'], 'import : métadonnée de crédit absente' );
		$fluffless_assert( str_contains( $fluffless_log()[1]['url'] ?? '', 'w=1600' ), 'import : largeur non demandée à Pexels' );
		$fluffless_calls = array();
		$fluffless_assert( Pexels::import( 101 ) === $fluffless_id && array() === $fluffless_log(), 'import : photo téléchargée deux fois' );
	}

	// 6. Adresse de fichier hors images.pexels.com : refusée avant tout téléchargement.
	$fluffless_calls = array();
	$fluffless_error = Pexels::import( 666 );
	$fluffless_assert( is_wp_error( $fluffless_error ) && 1 === count( $fluffless_log() ), 'import : domaine d\'image non vérifié' );

	// 7. La clé n'est jamais en base ni dans les données de l'éditeur.
	global $wpdb;
	$fluffless_like = '%' . $wpdb->esc_like( $fluffless_key ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- vérification de test.
	$fluffless_db = (int) $wpdb->get_var( $wpdb->prepare( "SELECT (SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s) + (SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s)", $fluffless_like, $fluffless_like ) );
	$fluffless_assert( 0 === $fluffless_db, 'sécurité : clé trouvée en base' );
	Pexels::editor_assets();
	$fluffless_inline = implode( '', (array) wp_scripts()->get_data( Pexels::HANDLE, 'before' ) );
	$fluffless_assert( str_contains( $fluffless_inline, '"hasKey":true' ) && ! str_contains( $fluffless_inline, $fluffless_key ), 'sécurité : clé dans les données de l\'éditeur' );
} finally {
	remove_filter( 'pre_http_request', $fluffless_mock, 1 );
	foreach ( $fluffless_created as $fluffless_attachment ) {
		wp_delete_attachment( $fluffless_attachment, true );
	}
	delete_transient( 'fluffless_pexels_' . md5( 'lac|1|' . get_locale() ) );
	wp_set_current_user( 0 );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Pexels, {$fluffless_checks} vérifications réussies.\n" );
