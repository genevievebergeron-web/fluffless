<?php
/**
 * Tests du module Mises à jour, avec réponses HTTP simulées (aucune requête réelle vers l'API).
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/updates-test.php
 *
 * @package Fluffless_Suite
 */

// Le test simule des réponses par le crochet du cœur pre_http_request et définit la constante de clé publique.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Modules\Updates;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Updates::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_plugin  = 'fluffless-suite/fluffless-suite.php';
$fluffless_licence = 'FL-TEST-' . wp_generate_password( 16, false );
// Modifiés par référence dans la fermeture et par les étapes du test.
/**
 * Requêtes interceptées.
 *
 * @var list<array{url: string, args: array<string, mixed>}> $fluffless_calls
 */
$fluffless_calls = array();
/**
 * Réponse simulée par défaut de l'API : à jour. Donne aussi la forme des réponses des étapes suivantes.
 *
 * @param string $url Adresse demandée.
 * @return array{code?: int, body?: mixed, error?: bool}
 */
function fluffless_test_default_reply( string $url ): array {
	unset( $url );
	return array( 'code' => 204 );
}
// Réponse simulée de l'API, remplacée à chaque étape.
$fluffless_reply = 'fluffless_test_default_reply';
/**
 * Vrai ZIP dont le fichier principal porte la version donnée.
 *
 * @param string $version En-tête « Version: » du fichier principal.
 */
$fluffless_make_zip = static function ( string $version ): string {
	$path    = wp_tempnam( 'fluffless-zip' ) . '.zip';
	$archive = new ZipArchive();
	$archive->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$archive->addFromString( plugin_basename( Fluffless\Suite\FILE ), "<?php\n/**\n * Plugin Name: Fluffless Suite\n * Version: {$version}\n */\n" . str_repeat( '// fluffless', 200 ) );
	$archive->close();
	$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier temporaire du test.
	wp_delete_file( $path );
	wp_delete_file( substr( $path, 0, -4 ) );
	return $bytes;
};
$fluffless_zip      = $fluffless_make_zip( '9.9.0' );
$fluffless_pair     = sodium_crypto_sign_keypair();
// Format du serveur : signature des octets du zip.
$fluffless_sign = static fn( string $bytes ): string => base64_encode( sodium_crypto_sign_detached( $bytes, sodium_crypto_sign_secretkey( $fluffless_pair ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format de signature.
/**
 * Signature dans l'en-tête ; false la sert seulement à <package>.sig.
 *
 * @var bool $fluffless_header
 */
$fluffless_header  = true;
$fluffless_sig     = $fluffless_sign( $fluffless_zip );
$fluffless_package = 'https://api.fluffless.dev/dl/tmp-abc/fluffless-suite.zip';

// Réponses simulées : $fluffless_reply décrit la réponse de l'API ; le paquet est écrit dans le fichier demandé.
$fluffless_mock = static function ( $pre, $args, $url ) use ( &$fluffless_calls, &$fluffless_reply, &$fluffless_zip, &$fluffless_sig, &$fluffless_header ) {
	$fluffless_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);
	if ( str_contains( $url, '/dl/' ) ) {
		$is_sig = str_ends_with( $url, '.sig' );
		if ( ! $is_sig && ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $fluffless_zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fichier temporaire du test.
		}
		return array(
			'headers'  => $fluffless_header && ! $is_sig ? array( 'x-content-signature' => $fluffless_sig ) : array(),
			'body'     => $is_sig ? $fluffless_sig : '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
	}
	$reply = $fluffless_reply( $url );
	if ( isset( $reply['error'] ) ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
	}
	return array(
		'headers'  => array(),
		'body'     => isset( $reply['body'] ) ? (string) wp_json_encode( $reply['body'] ) : '',
		'response' => array(
			'code'    => $reply['code'] ?? 500,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
// Manifeste signé {slug, version, sha256} : même JSON compact que le serveur (services/api/src/crypto.mjs).
$fluffless_manifest = static function ( string $version, string $sha256, string $slug = 'fluffless-suite' ) use ( $fluffless_pair ): array {
	$manifest = (string) wp_json_encode(
		array(
			'slug'    => $slug,
			'version' => $version,
			'sha256'  => $sha256,
		)
	);
	return array(
		'manifest'           => $manifest,
		'manifest_signature' => base64_encode( sodium_crypto_sign_detached( $manifest, sodium_crypto_sign_secretkey( $fluffless_pair ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format de signature.
	);
};
// Réponse 200 simulée ; le paramètre reçu est l'adresse demandée, sans effet ici.
$fluffless_update = static fn(): array => array(
	'code' => 200,
	'body' => array(
		'slug'         => 'fluffless-suite',
		'version'      => '9.9.0',
		'package'      => $fluffless_package,
		'sha256'       => hash( 'sha256', $fluffless_zip ),
		'requires'     => '6.8',
		'requires_php' => '8.2',
		'tested'       => '7.1',
		'changelog'    => array(
			'fr' => 'Corrections.',
			'en' => 'Fixes.',
		),
	) + $fluffless_manifest( '9.9.0', hash( 'sha256', $fluffless_zip ) ),
);
$fluffless_reset  = static function () use ( &$fluffless_calls ): void {
	$fluffless_calls = array();
	// Cache, état de licence et marqueur « API muette » : tout transitoire fluffless_.
	Lifecycle::delete_prefixed_transients();
};

add_filter( 'pre_http_request', $fluffless_mock, 1, 3 );
$fluffless_module = new Updates();

try {
	update_option( Lifecycle::OPTION_LICENCE, $fluffless_licence, false );
	$fluffless_reset();

	// 1. Sans clé publique de signature : aucune requête, aucune mise à jour, avis clair.
	// Seule une clé livrée vide (Updates::SIGNING_PUBLIC_KEY) ou la constante peut faire taire ce cas : la clé réelle est livrée, on n'asserte donc l'absence que si elle est vide.
	if ( ! defined( 'FLUFFLESS_SIGNING_PUBLIC_KEY' ) ) {
		if ( '' === ( new ReflectionClassConstant( Updates::class, 'SIGNING_PUBLIC_KEY' ) )->getValue() ) { // Réflexion : phpstan fige sinon la clé livrée.
			$fluffless_reply = $fluffless_update;
			$fluffless_assert( false === $fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() ), 'sans signature : mise à jour proposée' );
			$fluffless_assert( array() === $fluffless_calls, 'sans signature : une requête est partie' );
			$fluffless_assert( str_contains( Updates::status(), 'signing key' ), 'sans signature : avis absent' );
		}
		define( 'FLUFFLESS_SIGNING_PUBLIC_KEY', base64_encode( sodium_crypto_sign_publickey( $fluffless_pair ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format des clés de WordPress.
	} else {
		$fluffless_assert( false, 'test 1 impossible : une clé publique est déjà définie' );
	}

	// 2. 204 : à jour, entrée « no_update » (version installée) pour garder l'interrupteur de mise à jour automatique.
	$fluffless_reset();
	$fluffless_reply = static fn(): array => array( 'code' => 204 );
	$fluffless_found = $fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( is_array( $fluffless_found ) && \Fluffless\Suite\VERSION === $fluffless_found['version'] && '' === $fluffless_found['package'], '204 : entrée à jour incorrecte' );
	$fluffless_query = array();
	wp_parse_str( (string) wp_parse_url( $fluffless_calls[0]['url'] ?? '', PHP_URL_QUERY ), $fluffless_query );
	$fluffless_param   = static fn( string $key ): string => is_string( $fluffless_query[ $key ] ?? null ) ? $fluffless_query[ $key ] : '';
	$fluffless_assert( 'fluffless-suite' === $fluffless_param( 'slug' ) && 'stable' === $fluffless_param( 'channel' ) && hash_equals( \Fluffless\Suite\Core\Api::site_hash(), $fluffless_param( 'site' ) ), '204 : paramètres de requête incorrects' );
	$fluffless_headers = $fluffless_calls[0]['args']['headers'] ?? array();
	$fluffless_assert( ! isset( $fluffless_query['licence'] ) && ! str_contains( $fluffless_calls[0]['url'] ?? '', $fluffless_licence ), '204 : licence dans l\'adresse' );
	$fluffless_assert( is_array( $fluffless_headers ) && ( $fluffless_headers['X-Fluffless-Licence'] ?? '' ) === $fluffless_licence, '204 : en-tête X-Fluffless-Licence absent' );
	$fluffless_assert( ! str_contains( (string) ( $fluffless_calls[0]['args']['user-agent'] ?? '' ), (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), 'Loi 25 : domaine en clair dans le user-agent' );
	$fluffless_assert( str_starts_with( $fluffless_calls[0]['url'] ?? '', 'https://api.fluffless.dev/v1/update?' ), '204 : mauvaise adresse d\'API' );

	// 3. Cache : un second appel ne repart pas sur le réseau.
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( 1 === count( $fluffless_calls ), 'cache : seconde requête envoyée' );

	// 4. 200 : mise à jour proposée, avec le paquet et la somme.
	$fluffless_reset();
	$fluffless_reply = $fluffless_update;
	$fluffless_found = $fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( is_array( $fluffless_found ) && '9.9.0' === $fluffless_found['version'] && $fluffless_package === $fluffless_found['package'], '200 : mise à jour absente' );

	// 5. Échec réseau après expiration : le dernier résultat reste.
	$fluffless_cache = get_transient( Lifecycle::TRANSIENT_UPDATES );
	if ( is_array( $fluffless_cache ) && isset( $fluffless_cache['fluffless-suite'] ) ) {
		$fluffless_cache['fluffless-suite']['checked'] = time() - DAY_IN_SECONDS;
		set_transient( Lifecycle::TRANSIENT_UPDATES, $fluffless_cache, WEEK_IN_SECONDS );
	}
	/**
	 * Journal remis à zéro ; la fermeture le remplit par référence.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}> $fluffless_calls
	 */
	$fluffless_calls = array();
	$fluffless_reply = static fn(): array => array( 'error' => true );
	$fluffless_found = $fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( 1 === count( $fluffless_calls ) && is_array( $fluffless_found ) && '9.9.0' === $fluffless_found['version'], 'échec réseau : dernier résultat perdu' );

	// 6. Téléchargement : signature valide acceptée, signature ou somme fausses refusées.
	$fluffless_file = Updates::pre_download( false, $fluffless_package, null, array() );
	$fluffless_assert( is_string( $fluffless_file ) && is_file( $fluffless_file ), 'téléchargement signé refusé : ' . ( is_wp_error( $fluffless_file ) ? $fluffless_file->get_error_message() : '' ) );
	if ( is_string( $fluffless_file ) ) {
		wp_delete_file( $fluffless_file );
	}
	// Même format servi à <package>.sig plutôt qu'en en-tête.
	$fluffless_header = false;
	$fluffless_file   = Updates::pre_download( false, $fluffless_package, null, array() );
	$fluffless_assert( is_string( $fluffless_file ), 'signature des octets à <package>.sig refusée' );
	if ( is_string( $fluffless_file ) ) {
		wp_delete_file( $fluffless_file );
	}
	// Ancien format (empreinte SHA-384 brute) : plus accepté, le serveur ne le produit pas.
	$fluffless_sig  = base64_encode( sodium_crypto_sign_detached( hash( 'sha384', $fluffless_zip, true ), sodium_crypto_sign_secretkey( $fluffless_pair ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format de signature.
	$fluffless_file = Updates::pre_download( false, $fluffless_package, null, array() );
	$fluffless_assert( is_wp_error( $fluffless_file ) && 'fluffless_package_signature' === $fluffless_file->get_error_code(), 'signature SHA-384 acceptée' );
	$fluffless_header = true;
	$fluffless_sig    = $fluffless_sign( 'autre contenu' );
	$fluffless_file   = Updates::pre_download( false, $fluffless_package, null, array() );
	$fluffless_assert( is_wp_error( $fluffless_file ), 'signature fausse : paquet accepté' );
	// Clé divergente : signature bien formée sur les octets exacts du zip, mais faite avec une autre clé que celle livrée (mises à jour en pause).
	$fluffless_other = sodium_crypto_sign_keypair();
	$fluffless_sig   = base64_encode( sodium_crypto_sign_detached( $fluffless_zip, sodium_crypto_sign_secretkey( $fluffless_other ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format de signature.
	$fluffless_file  = Updates::pre_download( false, $fluffless_package, null, array() );
	$fluffless_assert( is_wp_error( $fluffless_file ) && 'fluffless_package_signature' === $fluffless_file->get_error_code(), 'clé divergente : paquet accepté' );
	$fluffless_divergent = $fluffless_manifest( '9.9.0', hash( 'sha256', $fluffless_zip ) );
	$fluffless_assert(
		! Updates::manifest_valid(
			array(
				'slug'               => 'fluffless-suite',
				'version'            => '9.9.0',
				'sha256'             => hash( 'sha256', $fluffless_zip ),
				'manifest'           => $fluffless_divergent['manifest'],
				'manifest_signature' => base64_encode( sodium_crypto_sign_detached( $fluffless_divergent['manifest'], sodium_crypto_sign_secretkey( $fluffless_other ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format de signature.
			)
		),
		'clé divergente : manifeste accepté'
	);
	// Lien renouvelé : l'API donne un nouveau lien pour la même version, c'est lui qui est téléchargé.
	$fluffless_sig   = $fluffless_sign( $fluffless_zip );
	$fluffless_fresh = 'https://api.fluffless.dev/dl/tmp-new/fluffless-suite.zip';
	$fluffless_reply = static fn(): array => array( 'body' => array( 'package' => $fluffless_fresh ) + $fluffless_update()['body'] ) + $fluffless_update();
	/**
	 * Journal remis à zéro ; la fermeture le remplit par référence.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}> $fluffless_calls
	 */
	$fluffless_calls = array();
	$fluffless_file  = Updates::pre_download( false, $fluffless_package, null, array() );
	$fluffless_assert( is_string( $fluffless_file ) && in_array( $fluffless_fresh, array_column( $fluffless_calls, 'url' ), true ), 'lien expiré : paquet non redemandé à l\'API' );
	if ( is_string( $fluffless_file ) ) {
		wp_delete_file( $fluffless_file );
	}
	// Ancien paquet légitimement signé servi pour la version annoncée : refusé, pas de retour en arrière.
	$fluffless_good  = $fluffless_zip;
	$fluffless_zip   = $fluffless_make_zip( '0.0.1' );
	$fluffless_sig   = $fluffless_sign( $fluffless_zip );
	$fluffless_reply = static fn(): array => array(
		'body' => array(
			'package' => $fluffless_fresh,
			'sha256'  => hash( 'sha256', $fluffless_zip ),
		) + $fluffless_manifest( '9.9.0', hash( 'sha256', $fluffless_zip ) ) + $fluffless_update()['body'],
	) + $fluffless_update();
	$fluffless_file  = Updates::pre_download( false, $fluffless_fresh, null, array() );
	$fluffless_assert( is_wp_error( $fluffless_file ) && 'fluffless_package_version' === $fluffless_file->get_error_code(), 'ancien paquet signé accepté comme 9.9.0' );
	$fluffless_zip   = $fluffless_good;
	$fluffless_reply = static fn(): array => array( 'error' => true );
	$fluffless_zip  .= 'x';
	$fluffless_sig   = $fluffless_sign( $fluffless_zip );
	$fluffless_file  = Updates::pre_download( false, $fluffless_fresh, null, array() );
	$fluffless_assert( is_wp_error( $fluffless_file ) && 'fluffless_package_hash' === $fluffless_file->get_error_code(), 'somme SHA-256 fausse : paquet accepté' );
	$fluffless_assert( false === Updates::pre_download( false, 'https://downloads.wordpress.org/plugin/x.zip', null, array() ), 'paquet tiers intercepté' );

	// 6b. Manifeste signé (C10) : exigé avant tout téléchargement, signature vérifiée, champs comparés à la fiche.
	$fluffless_zip  = $fluffless_good;
	$fluffless_sig  = $fluffless_sign( $fluffless_zip );
	$fluffless_hash = hash( 'sha256', $fluffless_zip );
	$fluffless_with = static fn( array $extra ): Closure => static fn(): array => array( 'body' => $extra + array( 'package' => $fluffless_fresh ) + $fluffless_update()['body'] ) + $fluffless_update();
	$fluffless_bad  = array(
		'absent'           => array(
			'manifest'           => '',
			'manifest_signature' => '',
		),
		'signature fausse' => array( 'manifest_signature' => $fluffless_sign( 'autre contenu' ) ) + $fluffless_manifest( '9.9.0', $fluffless_hash ),
		'somme différente' => $fluffless_manifest( '9.9.0', hash( 'sha256', 'autre paquet' ) ),
		'autre version'    => $fluffless_manifest( '9.9.1', $fluffless_hash ),
		'autre slug'       => $fluffless_manifest( '9.9.0', $fluffless_hash, 'fluffless-seo' ),
		'texte altéré'     => array( 'manifest' => str_replace( '9.9.0', '9.9.0 ', $fluffless_manifest( '9.9.0', $fluffless_hash )['manifest'] ) ) + $fluffless_manifest( '9.9.0', $fluffless_hash ),
	);
	foreach ( $fluffless_bad as $fluffless_case => $fluffless_extra ) {
		$fluffless_reply = $fluffless_with( $fluffless_extra );
		/**
		 * Journal remis à zéro à chaque cas.
		 *
		 * @var list<array{url: string, args: array<string, mixed>}> $fluffless_calls
		 */
		$fluffless_calls = array();
		$fluffless_file  = Updates::pre_download( false, $fluffless_fresh, null, array() );
		$fluffless_assert( is_wp_error( $fluffless_file ) && 'fluffless_package_manifest' === $fluffless_file->get_error_code(), 'manifeste ' . $fluffless_case . ' : paquet accepté' );
		$fluffless_assert( ! in_array( $fluffless_fresh, array_column( $fluffless_calls, 'url' ), true ), 'manifeste ' . $fluffless_case . ' : paquet téléchargé quand même' );
	}
	$fluffless_reply = $fluffless_with( array() );
	$fluffless_file  = Updates::pre_download( false, $fluffless_fresh, null, array() );
	$fluffless_assert( is_string( $fluffless_file ), 'manifeste valide refusé : ' . ( is_wp_error( $fluffless_file ) ? $fluffless_file->get_error_message() : '' ) );
	if ( is_string( $fluffless_file ) ) {
		wp_delete_file( $fluffless_file );
	}
	$fluffless_assert(
		Updates::manifest_valid(
			array(
				'slug'    => 'fluffless-suite',
				'version' => '9.9.0',
				'sha256'  => $fluffless_hash,
			) + $fluffless_manifest( '9.9.0', strtoupper( $fluffless_hash ) )
		),
		'manifeste : somme en majuscules refusée'
	);

	// 7. 403 : pas de mise à jour, état de licence retenu, avis clair ; le site continue.
	$fluffless_reset();
	$fluffless_reply = static fn(): array => array(
		'code' => 403,
		'body' => array( 'code' => 'licence_expired' ),
	);
	$fluffless_found = $fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( false === $fluffless_found, '403 : mise à jour proposée' );
	$fluffless_assert( 'licence_expired' === Updates::licence_state(), '403 : état de licence non retenu' );
	$fluffless_assert( str_contains( Updates::status(), 'expired' ), '403 : avis absent' );

	// 8. Réponse 200 malformée : ignorée.
	$fluffless_reset();
	$fluffless_reply = static fn(): array => array(
		'code' => 200,
		'body' => array( 'version' => '<script>' ),
	);
	$fluffless_assert( false === $fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() ), '200 malformé : accepté' );

	// 9. Thème et Consent gratuits : inscrits par Suite sans en-tête Update URI, donc injectés dans les transitoires.
	$fluffless_reset();
	$fluffless_reply    = static fn(): array => array( 'code' => 204 );
	$fluffless_packages = Updates::packages();
	$fluffless_theme_v  = (string) wp_get_theme( 'fluffless' )->get( 'Version' );
	$fluffless_consent  = 'fluffless-consent/fluffless-consent.php';
	$fluffless_assert( array( 'theme', 'fluffless', $fluffless_theme_v ) === array_values( $fluffless_packages['fluffless'] ?? array() ), 'thème gratuit : absent des paquets' );
	$fluffless_assert( array( 'plugin', $fluffless_consent, Fluffless\Consent\VERSION ) === array_values( $fluffless_packages['fluffless-consent'] ?? array() ), 'Consent : absent des paquets' );
	$fluffless_assert( 'x' === $fluffless_module->plugin_update( 'x', array(), 'autre/autre.php', array() ), 'extension tierce : réponse modifiée' );
	$fluffless_assert( 'x' === Updates::inject_themes( 'x' ), 'transitoire illisible : modifié' );

	// 9a. 204 : entrée « à jour » ; la réponse d'un autre dépôt pour ce slug (WordPress.org) est retirée.
	$fluffless_themes = Updates::inject_themes(
		(object) array(
			'checked'  => array(
				'fluffless'        => $fluffless_theme_v,
				'twentytwentyfive' => '1.5',
			),
			'response' => array(
				'fluffless'        => array( 'new_version' => '99.0' ),
				'twentytwentyfive' => array( 'new_version' => '2.0' ),
			),
		)
	);
	$fluffless_assert( ! isset( $fluffless_themes->response['fluffless'] ) && ( $fluffless_themes->no_update['fluffless']['new_version'] ?? '' ) === $fluffless_theme_v, 'thème 204 : entrée à jour absente ou réponse étrangère gardée' );
	$fluffless_assert( '2.0' === ( $fluffless_themes->response['twentytwentyfive']['new_version'] ?? '' ), 'thème 204 : thème tiers touché' );
	$fluffless_assert( str_contains( $fluffless_calls[0]['url'] ?? '', 'slug=fluffless&' ), 'thème 204 : mauvais slug demandé' );

	// 9b. Sans licence : aucune réponse de Suite, le transitoire reste tel quel.
	$fluffless_reset();
	// Filtre plutôt que delete_option() : add_option_ relancerait la vérification de licence.
	add_filter( 'pre_option_' . Lifecycle::OPTION_LICENCE, '__return_empty_string' );
	$fluffless_untouched = (object) array(
		'checked'  => array( 'fluffless' => $fluffless_theme_v ),
		'response' => array( 'fluffless' => array( 'new_version' => '99.0' ) ),
	);
	$fluffless_assert( '99.0' === ( Updates::inject_themes( clone $fluffless_untouched )->response['fluffless']['new_version'] ?? '' ) && array() === $fluffless_calls, 'sans licence : transitoire du thème modifié' );
	remove_filter( 'pre_option_' . Lifecycle::OPTION_LICENCE, '__return_empty_string' );

	// 9c. 200 pour le thème : mise à jour proposée, puis paquet vérifié (signature, somme, version de style.css).
	$fluffless_reset();
	$fluffless_theme_pkg = 'https://api.fluffless.dev/dl/tmp-abc/fluffless.zip';
	$fluffless_theme_zip = static function ( string $version ): string {
		$path    = wp_tempnam( 'fluffless-zip' ) . '.zip';
		$archive = new ZipArchive();
		$archive->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$archive->addFromString( 'fluffless/style.css', "/*\nTheme Name: Fluffless\nVersion: {$version}\n*/\n" . str_repeat( '/* fluffless */', 200 ) );
		$archive->close();
		$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier temporaire du test.
		wp_delete_file( $path );
		wp_delete_file( substr( $path, 0, -4 ) );
		return $bytes;
	};
	$fluffless_suite_zip = $fluffless_zip;
	$fluffless_zip       = $fluffless_theme_zip( '9.9.0' );
	$fluffless_sig       = $fluffless_sign( $fluffless_zip );
	// Offre 9.9.0 pour le ZIP donné, signée et à la bonne somme.
	$fluffless_theme_offer = static fn( string $zip ): callable => static fn( string $url ): array => str_contains( $url, 'slug=fluffless&' ) ? array(
		'code' => 200,
		'body' => array(
			'slug'         => 'fluffless',
			'version'      => '9.9.0',
			'package'      => $fluffless_theme_pkg,
			'sha256'       => hash( 'sha256', $zip ),
			'requires'     => '6.8',
			'requires_php' => '8.2',
		) + $fluffless_manifest( '9.9.0', hash( 'sha256', $zip ), 'fluffless' ),
	) : array( 'code' => 204 );
	$fluffless_reply       = $fluffless_theme_offer( $fluffless_zip );
	$fluffless_themes      = Updates::inject_themes( (object) array( 'checked' => array( 'fluffless' => $fluffless_theme_v ) ) );
	$fluffless_offer       = $fluffless_themes->response['fluffless'] ?? array();
	$fluffless_assert( 'fluffless' === ( $fluffless_offer['theme'] ?? '' ) && '9.9.0' === ( $fluffless_offer['new_version'] ?? '' ) && ( $fluffless_offer['package'] ?? '' ) === $fluffless_theme_pkg && '8.2' === ( $fluffless_offer['requires_php'] ?? '' ), 'thème 200 : offre absente ou incomplète' );
	$fluffless_assert( ! isset( $fluffless_themes->no_update['fluffless'] ), 'thème 200 : aussi marqué à jour' );
	$fluffless_file = Updates::pre_download( false, $fluffless_theme_pkg, null, array( 'theme' => 'fluffless' ) );
	$fluffless_assert( is_string( $fluffless_file ) && is_file( $fluffless_file ), 'thème 200 : paquet valide refusé' . ( is_wp_error( $fluffless_file ) ? ' (' . $fluffless_file->get_error_code() . ')' : '' ) );
	if ( is_string( $fluffless_file ) ) {
		wp_delete_file( $fluffless_file );
	}
	// Même paquet signé, mais style.css d'une autre version : refusé.
	$fluffless_zip   = $fluffless_theme_zip( '9.8.0' );
	$fluffless_sig   = $fluffless_sign( $fluffless_zip );
	$fluffless_reply = $fluffless_theme_offer( $fluffless_zip );
	$fluffless_reset();
	Updates::inject_themes( (object) array( 'checked' => array( 'fluffless' => $fluffless_theme_v ) ) );
	$fluffless_file = Updates::pre_download( false, $fluffless_theme_pkg, null, array( 'theme' => 'fluffless' ) );
	$fluffless_assert( is_wp_error( $fluffless_file ) && 'fluffless_package_version' === $fluffless_file->get_error_code(), 'thème : version de style.css non vérifiée' );
	$fluffless_zip = $fluffless_suite_zip;
	$fluffless_sig = $fluffless_sign( $fluffless_zip );

	// 9d. Consent : offre injectée en objet, comme celles de WordPress.org ; Suite (en-tête Update URI) n'y passe pas.
	$fluffless_reset();
	$fluffless_reply   = static fn( string $url ): array => str_contains( $url, 'slug=fluffless-consent&' ) ? array(
		'code' => 200,
		'body' => array(
			'slug'    => 'fluffless-consent',
			'version' => '9.9.0',
			'package' => 'https://api.fluffless.dev/dl/tmp-abc/fluffless-consent.zip',
			'sha256'  => str_repeat( 'a', 64 ),
		),
	) : array( 'code' => 204 );
	$fluffless_plugins = Updates::inject_plugins(
		(object) array(
			'checked' => array(
				$fluffless_consent => Fluffless\Consent\VERSION,
				$fluffless_plugin  => \Fluffless\Suite\VERSION,
			),
		)
	);
	$fluffless_entry   = $fluffless_plugins->response[ $fluffless_consent ] ?? null;
	$fluffless_fields  = is_object( $fluffless_entry ) ? get_object_vars( $fluffless_entry ) : array();
	$fluffless_assert( array( 'fluffless-consent', $fluffless_consent, '9.9.0' ) === array( $fluffless_fields['slug'] ?? '', $fluffless_fields['plugin'] ?? '', $fluffless_fields['new_version'] ?? '' ), 'Consent 200 : offre absente ou mal formée' );
	$fluffless_assert( ! isset( $fluffless_plugins->response[ $fluffless_plugin ] ) && ! isset( $fluffless_plugins->no_update[ $fluffless_plugin ] ), 'Suite : injectée alors qu\'elle a son en-tête Update URI' );
	$fluffless_assert( 1 === count( $fluffless_calls ), 'Consent : une seule requête attendue' );

	// 10. Détails bilingues (plugins_api), en cache.
	$fluffless_reset();
	$fluffless_reply = static fn( string $url ): array => array(
		'code' => 200,
		'body' => array(
			'name'         => 'Fluffless Suite',
			'version'      => '9.9.0',
			'sections'     => array(
				'description' => str_contains( $url, 'fr_CA' ) ? 'Outils <script>x</script>sobres' : 'Sober tools',
				'changelog'   => '<ul><li>Fixes</li></ul>',
			),
			'requires'     => '6.8',
			'tested'       => '7.1',
			'requires_php' => '8.2',
			'last_updated' => '2026-09-12',
			'homepage'     => 'https://fluffless.dev',
		),
	);
	switch_to_locale( 'fr_CA' );
	$fluffless_info  = Updates::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'fluffless-suite' ) );
	restore_previous_locale();
	$fluffless_sections = is_object( $fluffless_info ) ? ( get_object_vars( $fluffless_info )['sections'] ?? null ) : null;
	$fluffless_assert( is_array( $fluffless_sections ) && 'Outils xsobres' === ( $fluffless_sections['description'] ?? '' ) && str_contains( $fluffless_calls[0]['url'] ?? '', 'locale=fr_CA' ), 'détails : langue ou assainissement incorrects' );
	// Deux appels en français (le second vient du cache), un en anglais : deux requêtes en tout.
	switch_to_locale( 'fr_CA' );
	Updates::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'fluffless-suite' ) );
	restore_previous_locale();
	Updates::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'fluffless-suite' ) );
	$fluffless_assert( 2 === count( $fluffless_calls ) && str_contains( $fluffless_calls[1]['url'], 'locale=en_CA' ), 'détails : une requête par langue attendue' );
	$fluffless_assert( false === Updates::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ), 'détails : extension tierce interceptée' );

	// 11. La clé n'est jamais affichée en entier.
	ob_start();
	Updates::render_licence();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( ! str_contains( $fluffless_html, $fluffless_licence ) && str_contains( $fluffless_html, substr( $fluffless_licence, -4 ) ), 'licence : clé affichée en entier ou pas du tout' );
	$fluffless_assert( str_contains( $fluffless_html, 'hash' ), 'Loi 25 : mention absente' );

	// 12. Assainissement du réglage : vide garde la clé, « remove » la retire.
	$fluffless_assert( Updates::sanitize_licence( array( 'key' => '' ) ) === $fluffless_licence, 'licence : champ vide a effacé la clé' );
	$fluffless_assert( '' === Updates::sanitize_licence( array( 'remove' => '1' ) ), 'licence : retrait sans effet' );
	$fluffless_assert( Updates::sanitize_licence( array( 'key' => 'bad key<>' ) ) === $fluffless_licence, 'licence : clé invalide acceptée' );

	// 13. /v1/licences/verify répond 200 même en cas de refus : on lit valid et code.
	$fluffless_reset();
	$fluffless_reply  = static fn(): array => array(
		'code' => 200,
		'body' => array(
			'valid'      => false,
			'code'       => 'site_limit',
			'plan'       => 'solo',
			'expires'    => '2099-01-01',
			'sites_used' => 1,
			'sites_max'  => 1,
		),
	);
	$fluffless_result = Updates::verify_licence();
	$fluffless_assert( is_array( $fluffless_result ) && false === $fluffless_result['valid'] && 'site_limit' === Updates::licence_state(), 'verify : code du refus ignoré' );
	$fluffless_verify = $fluffless_calls[0]['args'] ?? array();
	$fluffless_assert( ( $fluffless_verify['headers']['X-Fluffless-Licence'] ?? '' ) === $fluffless_licence && ! str_contains( (string) ( $fluffless_verify['body'] ?? '' ), $fluffless_licence ), 'verify : licence hors en-tête ou dans le corps' );

	// 15. FLUFFLESS_ALLOW_UNSIGNED : pris en compte en local et development seulement.
	$fluffless_assert( 'on' === Updates::unsigned_state( true, 'local' ) && 'on' === Updates::unsigned_state( true, 'development' ), 'non signé : refusé en local' );
	$fluffless_assert( 'ignored' === Updates::unsigned_state( true, 'staging' ) && 'ignored' === Updates::unsigned_state( true, 'production' ), 'non signé : accepté hors développement' );
	$fluffless_assert( 'off' === Updates::unsigned_state( false, 'local' ), 'non signé : actif sans la constante' );

	// 16. Empreinte du site : règle de normalisation partagée avec le serveur (docs/PUBLICATION.md).
	$fluffless_identity = array(
		'https://WWW.Exemple.ca:443/Blogue/' => 'www.exemple.ca/Blogue',
		'http://exemple.ca:80'               => 'exemple.ca',
		'http://localhost:8080/'             => 'localhost:8080',
		'https://exemple.ca:80/a'            => 'exemple.ca:80/a',
	);
	foreach ( $fluffless_identity as $fluffless_in => $fluffless_out ) {
		$fluffless_assert( \Fluffless\Suite\Core\Api::site_identity( $fluffless_in ) === $fluffless_out, 'empreinte : ' . $fluffless_in . ' donne ' . \Fluffless\Suite\Core\Api::site_identity( $fluffless_in ) );
	}

	// 14. Liste blanche HTTP : l'hôte de l'API passe toujours.
	$fluffless_http = new \Fluffless\Suite\Core\Http();
	$fluffless_assert( false === $fluffless_http->filter( false, array(), 'https://' . \Fluffless\Suite\Core\Api::host() . '/v1/update' ), 'liste blanche : API bloquée' );

	// 17. C1-11-01 : API muette plus d'une semaine, la mise à jour en cache n'est plus proposée.
	$fluffless_age = static function ( int $seconds ): void {
		$cache = get_transient( Lifecycle::TRANSIENT_UPDATES );
		foreach ( is_array( $cache ) ? array_keys( $cache ) : array() as $slug ) {
			foreach ( array( 'checked', 'ok' ) as $field ) {
				if ( is_int( $cache[ $slug ][ $field ] ?? null ) ) {
					$cache[ $slug ][ $field ] -= $seconds;
				}
			}
		}
		set_transient( Lifecycle::TRANSIENT_UPDATES, $cache, WEEK_IN_SECONDS );
	};
	$fluffless_reset();
	$fluffless_reply = $fluffless_update;
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_reply = static fn(): array => array( 'error' => true );
	$fluffless_age( 13 * HOUR_IN_SECONDS );
	$fluffless_found = $fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( is_array( $fluffless_found ) && '9.9.0' === $fluffless_found['version'], 'C1-11-01 : repli perdu avant une semaine' );
	for ( $fluffless_i = 0; $fluffless_i < 13; $fluffless_i++ ) {
		$fluffless_age( 13 * HOUR_IN_SECONDS );
		$fluffless_found = $fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	}
	$fluffless_assert( ! is_array( $fluffless_found ) || '' === $fluffless_found['package'], 'C1-11-01 : mise à jour encore proposée après 7 jours sans réponse' );
	// Lien en cache périmé et API muette : le message parle du serveur, pas de la licence.
	$fluffless_reset();
	$fluffless_stale = 'https://api.fluffless.dev/v1/perime/fluffless-suite.zip';
	$fluffless_reply = static fn(): array => array(
		'code' => 200,
		'body' => array(
			'slug'    => 'fluffless-suite',
			'version' => '9.9.0',
			'package' => $fluffless_stale,
			'sha256'  => hash( 'sha256', $fluffless_zip ),
		) + $fluffless_manifest( '9.9.0', hash( 'sha256', $fluffless_zip ) ),
	);
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_reply = static fn(): array => array( 'error' => true );
	$fluffless_file  = Updates::pre_download( false, $fluffless_stale, null, array() );
	$fluffless_assert( is_wp_error( $fluffless_file ) && Updates::outcome_message( 'unreachable' ) === $fluffless_file->get_error_message(), 'C1-11-01 : échec de téléchargement, API muette : ' . ( is_wp_error( $fluffless_file ) ? $fluffless_file->get_error_message() : 'aucune erreur' ) );
	$fluffless_reset();

	// 18. C1-11-02 : API muette sans entrée en cache : un seul appel par heure, pas un par vérification.
	$fluffless_reply = static fn(): array => array( 'error' => true );
	for ( $fluffless_i = 0; $fluffless_i < 3; $fluffless_i++ ) {
		$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	}
	$fluffless_assert( 1 === count( $fluffless_calls ), 'C1-11-02 : API muette relancée à chaque vérification (' . count( $fluffless_calls ) . ' appels)' );
	$fluffless_assert( 'unreachable' === Updates::outcome( 'fluffless-suite' ), 'C1-11-02 : issue sans appel : ' . Updates::outcome( 'fluffless-suite' ) );
	// La vérification forcée (WP-CLI, téléchargement) passe outre ; une réponse lève le marqueur.
	$fluffless_reply = static fn(): array => array( 'code' => 204 );
	Updates::check( 'fluffless-suite', true );
	$fluffless_assert( 2 === count( $fluffless_calls ), 'C1-11-02 : vérification forcée bloquée par le marqueur' );
	delete_transient( Lifecycle::TRANSIENT_UPDATES );
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( 3 === count( $fluffless_calls ), 'C1-11-02 : marqueur gardé après une réponse' );
	$fluffless_reset();

	// 19. C1-11-03 : clé enregistrée, serveur de licences muet : la carte le dit, sans parler de clé refusée.
	$fluffless_card  = static function (): string {
		ob_start();
		Updates::render_licence();
		return (string) ob_get_clean();
	};
	$fluffless_reply = static fn(): array => array( 'error' => true );
	Updates::licence_changed();
	$fluffless_html  = $fluffless_card();
	$fluffless_assert( str_contains( $fluffless_html, 'answering right now' ) && str_contains( $fluffless_html, 'Your key is saved' ), 'C1-11-03 : carte muette quand le serveur de licences ne répond pas' );
	$fluffless_assert( '' === Updates::licence_state() && ! Updates::needs_licence(), 'C1-11-03 : panne prise pour un refus de clé' );
	$fluffless_reply = static fn(): array => array(
		'code' => 200,
		'body' => array(
			'valid'      => true,
			'plan'       => 'solo',
			'expires'    => gmdate( 'c', time() + YEAR_IN_SECONDS ),
			'sites_used' => 1,
			'sites_max'  => 1,
		),
	);
	Updates::licence_changed();
	$fluffless_html  = $fluffless_card();
	$fluffless_assert( ! str_contains( $fluffless_html, 'answering right now' ) && 'valid' === Updates::licence_state(), 'C1-11-03 : avis de panne gardé après une réponse' );
	$fluffless_reset();
} finally {
	remove_filter( 'pre_http_request', $fluffless_mock, 1 );
	delete_option( Lifecycle::OPTION_LICENCE );
	delete_transient( Lifecycle::TRANSIENT_UPDATES );
	delete_transient( Lifecycle::TRANSIENT_LICENCE );
	Lifecycle::delete_prefixed_transients();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Mises à jour, {$fluffless_checks} vérifications réussies.\n" );
