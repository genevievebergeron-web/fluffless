<?php
/**
 * Preuve de travail au format ALTCHA, hébergée par le site : défi signé, vérification, usage unique.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Form;

use Fluffless\Suite\Core\Lifecycle;
use WP_REST_Response;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Le navigateur cherche le nombre n tel que SHA-256(sel + n) donne le défi ; le serveur n'a qu'à
 * refaire un hachage et une signature. Même charge utile que le protocole ALTCHA (base64 d'un JSON
 * algorithm, challenge, number, salt, signature) que le composant officiel ; pour s'en servir, retirer
 * aussi le champ caché rendu par le module, le composant posant le sien.
 *
 * - Le défi vient d'une route REST appelée par le script, jamais du HTML : une page en cache
 *   plusieurs jours ne sert pas le même défi à tout le monde.
 * - Le sel porte son échéance (« ?expires= ») ; une charge utile acceptée est notée jusqu'à cette
 *   échéance par une ligne d'option insérée d'un coup (INSERT IGNORE) : deux envois simultanés de la
 *   même preuve ne passent pas tous les deux. La tâche quotidienne du module retire les marques échues.
 * - Rien sur la personne : ni adresse, ni témoin.
 */
final class Altcha {

	/** Champ posté par le script. */
	public const FIELD = 'altcha';

	public const REST_NS = 'fluffless/v1';
	public const ROUTE   = '/form/challenge';
	public const SCRIPT  = 'fluffless-form-altcha';

	/** Plus grand nombre cherché : 25 000 hachages en moyenne, une fraction de seconde. */
	public const MAX_NUMBER = 50000;
	public const TTL        = 20 * MINUTE_IN_SECONDS;
	public const USED       = 'fluffless_altcha_';

	/** Préfixe des marques de preuves servies (lignes d'option, valeur : échéance). */
	public const MARK = Lifecycle::OPTION_ALTCHA_MARK;

	public const SALT_PATTERN = '/^[a-f0-9]{24}\?expires=(\d{9,11})$/';

	/** Clé de signature propre au site, tirée des sels de wp-config. */
	private static function key(): string {
		return hash_hmac( 'sha256', 'fluffless-altcha', wp_salt( 'nonce' ) );
	}

	/** Plus grand nombre cherché, borné : trop bas ne coûte rien aux robots, trop haut fait attendre. */
	public static function max_number(): int {
		/**
		 * Plus grand nombre cherché par le navigateur (difficulté de la preuve de travail).
		 *
		 * @param int $max 50 000 par défaut.
		 */
		$max = (int) apply_filters( 'fluffless_form_altcha_max', self::MAX_NUMBER );
		return min( 1000000, max( 1000, $max ) );
	}

	/**
	 * Un défi neuf.
	 *
	 * @param int|null $now    Maintenant (tests).
	 * @param int|null $number Nombre à trouver (tests) ; tiré au hasard sinon.
	 * @return array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string}
	 */
	public static function challenge( ?int $now = null, ?int $number = null ): array {
		$max       = self::max_number();
		$salt      = bin2hex( random_bytes( 12 ) ) . '?expires=' . ( ( $now ?? time() ) + self::TTL );
		$number    = $number ?? random_int( 0, $max );
		$challenge = hash( 'sha256', $salt . $number );
		return array(
			'algorithm' => 'SHA-256',
			'challenge' => $challenge,
			'maxnumber' => $max,
			'salt'      => $salt,
			'signature' => hash_hmac( 'sha256', $challenge, self::key() ),
		);
	}

	/**
	 * Charge utile encodée comme le fait le script (tests, intégrations).
	 *
	 * @param array<string, mixed> $challenge Défi.
	 * @param int                  $number    Nombre trouvé.
	 */
	public static function payload( array $challenge, int $number ): string {
		$data = array(
			'algorithm' => $challenge['algorithm'] ?? '',
			'challenge' => $challenge['challenge'] ?? '',
			'number'    => $number,
			'salt'      => $challenge['salt'] ?? '',
			'signature' => $challenge['signature'] ?? '',
		);
		return base64_encode( (string) wp_json_encode( $data ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format de la charge utile ALTCHA.
	}

	/**
	 * Vrai si la charge utile résout un défi signé ici, non échu et jamais servi ; elle est alors notée.
	 *
	 * @param string   $payload Charge utile reçue (base64 d'un JSON).
	 * @param int|null $now     Maintenant (tests).
	 */
	public static function verify( string $payload, ?int $now = null ): bool {
		$now  = $now ?? time();
		$json = strlen( $payload ) > 2000 ? false : base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- format de la charge utile ALTCHA.
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || 'SHA-256' !== ( $data['algorithm'] ?? '' ) ) {
			return false;
		}
		$challenge = $data['challenge'] ?? '';
		$salt      = $data['salt'] ?? '';
		$number    = $data['number'] ?? null;
		$signature = $data['signature'] ?? '';
		if ( ! is_string( $challenge ) || ! is_string( $salt ) || ! is_string( $signature ) || ! is_int( $number ) ) {
			return false;
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $challenge ) || 1 !== preg_match( self::SALT_PATTERN, $salt, $m ) ) {
			return false;
		}
		$expires = (int) $m[1];
		if ( $number < 0 || $number > 1000000 || $expires < $now || $expires > $now + self::TTL + MINUTE_IN_SECONDS ) {
			return false;
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $challenge, self::key() ), $signature ) || ! hash_equals( $challenge, hash( 'sha256', $salt . $number ) ) ) {
			return false;
		}
		return self::claim( self::MARK . substr( $challenge, 0, 32 ), $expires + MINUTE_IN_SECONDS );
	}

	/**
	 * Note une preuve servie : une seule insertion gagne, même quand deux requêtes arrivent ensemble
	 * (une lecture puis une écriture laisserait passer les deux, P6a-05).
	 *
	 * @param string $name  Nom de la marque.
	 * @param int    $until Échéance de la marque.
	 */
	private static function claim( string $name, int $until ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- insertion atomique voulue, jamais relue par get_option().
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $until ) );
		return 1 === $inserted;
	}

	/**
	 * Retire les marques échues. Retourne le nombre retiré.
	 *
	 * @param int|null $now Maintenant (tests).
	 */
	public static function sweep( ?int $now = null ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ménage des marques, une requête par jour.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like( self::MARK ) . '%', $now ?? time() ) );
		return (int) $deleted;
	}

	/** Route publique du défi : sans cache, sans témoin. */
	public static function register_route(): void {
		register_rest_route(
			self::REST_NS,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'respond' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** Réponse de la route : un défi neuf, jamais mis en cache. */
	public static function respond(): WP_REST_Response {
		$response = new WP_REST_Response( self::challenge() );
		$response->set_headers( wp_get_nocache_headers() );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		// LiteSpeed Cache peut garder les réponses REST malgré les en-têtes : on le lui interdit.
		do_action( 'litespeed_control_set_nocache', 'fluffless altcha' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- crochet de LiteSpeed Cache.
		return $response;
	}

	/** Adresse de la route, posée dans le formulaire pour le script. */
	public static function url(): string {
		return rest_url( self::REST_NS . self::ROUTE );
	}

	/** Script du navigateur (moins de 6 ko, 2,1 ko compressé), différé, une fois par page. */
	public static function enqueue(): void {
		wp_enqueue_script(
			self::SCRIPT,
			plugins_url( 'assets/form-altcha.js', FILE ),
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}
