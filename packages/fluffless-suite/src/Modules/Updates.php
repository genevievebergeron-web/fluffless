<?php
/**
 * Mises à jour signées du thème, de l'extension et des extensions Fluffless inscrites.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Api;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\DeclaresHosts;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Branché sur les filtres natifs update_plugins_{hôte} et update_themes_{hôte} (WordPress 5.8 et 6.1) :
 * l'écran des mises à jour et les mises à jour automatiques restent ceux de WordPress.
 *
 * Crochet public : filtre fluffless_update_packages, slug => array{type, file, version}.
 *
 * Sécurité : sans clé publique ed25519, aucune mise à jour n'est proposée (sauf FLUFFLESS_ALLOW_UNSIGNED,
 * pris en compte seulement en environnement local ou development). Au téléchargement, le lien est
 * redemandé à l'API (il expire après 15 minutes), la signature ed25519 des octets exacts du zip
 * (en-tête X-Content-Signature ou <package>.sig) est vérifiée ici, puis la somme SHA-256 annoncée par
 * l'API est comparée. La vérification native de WordPress n'est pas utilisée : elle attend une
 * signature de l'empreinte SHA-384, un format que le serveur ne produit pas.
 * La licence voyage dans l'en-tête X-Fluffless-Licence, jamais dans l'adresse.
 */
final class Updates implements Module, DeclaresHosts {

	/** Hôte des en-têtes Update URI ; distinct de FLUFFLESS_API_URL, qui peut pointer ailleurs en dev. */
	public const UPDATE_HOST = 'api.fluffless.dev';

	/** Clé publique livrée (base64, 32 octets) ; la constante FLUFFLESS_SIGNING_PUBLIC_KEY la remplace. */
	public const SIGNING_PUBLIC_KEY = '1p1kWweWP8Zgzd9DOalpdbl881quJAK+VpKhzIZS+n8=';

	public const LICENCE_CONSTANT = 'FLUFFLESS_LICENCE_KEY';

	/**
	 * Une réponse est fraîche 12 h. Si l'API ne répond plus, elle sert de repli une semaine au plus,
	 * comptée depuis la dernière réponse reçue : les échecs espacent les appels sans prolonger ce délai.
	 */
	private const FRESH = 12 * HOUR_IN_SECONDS;
	private const KEEP  = WEEK_IN_SECONDS;

	/** Après une panne réseau ou serveur, l'API n'est plus appelée pendant 1 h, pour tous les paquets. */
	private const SILENT = HOUR_IN_SECONDS;

	/** Nombre maximal de paquets inscrits par le filtre (borne du parcours). */
	private const MAX_PACKAGES = 20;

	/**
	 * Paquets gratuits servis par Suite sans en-tête Update URI (slug => dossier du thème ou plugin_basename).
	 * WordPress ne leur applique pas update_{type}_{hôte} : leur offre est injectée dans les transitoires.
	 */
	private const FREE = array(
		'fluffless'         => 'fluffless',
		'fluffless-consent' => 'fluffless-consent/fluffless-consent.php',
	);

	/** Action admin-post du formulaire de libération, et nom de son jeton. */
	public const RELEASE_ACTION = 'fluffless_release_seat';

	/** Avis de libération retenu pour la carte, une fois, par personne (suffixe : id). */
	private const RELEASE_NOTICE = 'fluffless_release_notice_';

	/**
	 * La carte a affiché des boutons de libération : le formulaire séparé doit suivre.
	 *
	 * @var bool
	 */
	private static bool $release_form = false;

	/** Marqueur d'état : la vérification de la clé n'a pas eu de réponse (hors LICENCE_STATES). */
	private const UNVERIFIED = 'unverified';

	private const LICENCE_STATES = array( 'valid', 'licence_invalid', 'licence_revoked', 'licence_expired', 'site_limit', 'licence_review', 'dev_limit' );

	/** {@inheritDoc} */
	public static function id(): string {
		return 'updates';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Updates', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Brings Fluffless updates into Dashboard → Updates. Each package is checked against its signature before it is installed.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array( 'channel' => 'stable' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		return Sanitize::section( $input, self::fields(), self::defaults() );
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array(
			'channel' => array(
				'type'    => 'choice',
				'label'   => __( 'Channel', 'fluffless-suite' ),
				'options' => array(
					'stable' => __( 'Stable', 'fluffless-suite' ),
					'beta'   => __( 'Beta: new features sooner, a few rough edges', 'fluffless-suite' ),
				),
			),
		);
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_filter( 'update_plugins_' . self::UPDATE_HOST, array( $this, 'plugin_update' ), 10, 4 );
		add_filter( 'update_themes_' . self::UPDATE_HOST, array( $this, 'theme_update' ), 10, 4 );
		add_filter( 'pre_set_site_transient_update_themes', array( self::class, 'inject_themes' ), 20 );
		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'inject_plugins' ), 20 );
		add_filter( 'plugins_api', array( self::class, 'plugin_info' ), 10, 3 );
		add_filter( 'themes_api', array( self::class, 'theme_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( self::class, 'pre_download' ), 10, 4 );
		add_action( 'admin_init', array( self::class, 'register_licence_setting' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_post_' . self::RELEASE_ACTION, array( self::class, 'handle_release' ) );
		add_action( 'add_option_' . Lifecycle::OPTION_LICENCE, array( self::class, 'licence_changed' ) );
		add_action( 'update_option_' . Lifecycle::OPTION_LICENCE, array( self::class, 'licence_updated' ), 10, 2 );
	}

	/**
	 * Paquets suivis : slug => type, fichier (plugin_basename ou dossier du thème), version installée.
	 *
	 * @return array<string, array{type: 'plugin'|'theme', file: string, version: string}>
	 */
	public static function packages(): array {
		$packages = array(
			'fluffless-suite' => array(
				'type'    => 'plugin',
				'file'    => plugin_basename( FILE ),
				'version' => VERSION,
			),
		);
		// Thème et Consent gratuits, s'ils sont installés : sans Suite, ils se mettent à jour par WordPress.org.
		$theme = wp_get_theme( self::FREE['fluffless'] );
		if ( $theme->exists() ) {
			$packages['fluffless'] = array(
				'type'    => 'theme',
				'file'    => self::FREE['fluffless'],
				'version' => (string) $theme->get( 'Version' ),
			);
		}
		$consent = WP_PLUGIN_DIR . '/' . self::FREE['fluffless-consent'];
		if ( is_readable( $consent ) ) {
			$packages['fluffless-consent'] = array(
				'type'    => 'plugin',
				'file'    => self::FREE['fluffless-consent'],
				'version' => get_file_data( $consent, array( 'version' => 'Version' ) )['version'],
			);
		}
		/**
		 * Filtre les paquets dont Fluffless Suite cherche les mises à jour.
		 * Un paquet inscrit doit porter l'en-tête « Update URI: https://api.fluffless.dev/<slug> »
		 * (seuls le thème et Consent gratuits s'en passent, voir FREE).
		 *
		 * @param array<string, array{type: string, file: string, version: string}> $packages Slug => paquet.
		 */
		return self::valid_packages( apply_filters( 'fluffless_update_packages', $packages ) );
	}

	/**
	 * Paquets rendus par le filtre, validés : un tiers peut y avoir mis n'importe quoi.
	 *
	 * @param mixed $filtered Valeur du filtre fluffless_update_packages.
	 * @return array<string, array{type: 'plugin'|'theme', file: string, version: string}>
	 */
	private static function valid_packages( $filtered ): array {
		$valid = array();
		foreach ( array_slice( is_array( $filtered ) ? $filtered : array(), 0, self::MAX_PACKAGES, true ) as $slug => $package ) {
			if ( is_string( $slug ) && sanitize_key( $slug ) === $slug && is_array( $package )
				&& in_array( $package['type'] ?? '', array( 'plugin', 'theme' ), true )
				&& is_string( $package['file'] ?? null ) && '' !== $package['file']
				&& is_string( $package['version'] ?? null ) && self::is_version( $package['version'] ) ) {
				$valid[ $slug ] = array(
					'type'    => $package['type'],
					'file'    => $package['file'],
					'version' => $package['version'],
				);
			}
		}
		return $valid;
	}

	/**
	 * Filtre update_plugins_{hôte}.
	 *
	 * @param mixed        $update      Réponse d'un filtre précédent.
	 * @param array<mixed> $plugin_data En-têtes de l'extension.
	 * @param string       $plugin_file plugin_basename.
	 * @param array<mixed> $locales     Langues installées.
	 * @return mixed
	 */
	public function plugin_update( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $plugin_data, $locales );
		$slug = self::slug_for( 'plugin', (string) $plugin_file );
		if ( null === $slug ) {
			return $update;
		}
		$found = self::check( $slug );
		if ( null === $found ) {
			return false;
		}
		return array( 'slug' => $slug ) + $found;
	}

	/**
	 * Filtre update_themes_{hôte}.
	 *
	 * @param mixed        $update     Réponse d'un filtre précédent.
	 * @param array<mixed> $theme_data En-têtes du thème.
	 * @param string       $stylesheet Dossier du thème.
	 * @param array<mixed> $locales    Langues installées.
	 * @return mixed
	 */
	public function theme_update( $update, $theme_data, $stylesheet, $locales ) {
		unset( $theme_data, $locales );
		$slug = self::slug_for( 'theme', (string) $stylesheet );
		if ( null === $slug ) {
			return $update;
		}
		$found = self::check( $slug );
		if ( null === $found ) {
			return false;
		}
		return array( 'theme' => (string) $stylesheet ) + $found;
	}

	/**
	 * Filtre pre_set_site_transient_update_themes : offre du thème gratuit.
	 *
	 * @param mixed $value Transitoire update_themes.
	 * @return mixed
	 */
	public static function inject_themes( $value ) {
		return self::inject( 'theme', $value );
	}

	/**
	 * Filtre pre_set_site_transient_update_plugins : offre de Consent.
	 *
	 * @param mixed $value Transitoire update_plugins.
	 * @return mixed
	 */
	public static function inject_plugins( $value ) {
		return self::inject( 'plugin', $value );
	}

	/**
	 * Place l'offre de chaque paquet gratuit installé dans response ou no_update, au format du cœur
	 * (tableau pour un thème, objet pour une extension). Sans réponse de l'API (pas de licence,
	 * panne), le transitoire reste tel quel ; avec une réponse, celle d'un autre dépôt pour ce slug est remplacée.
	 *
	 * @param 'plugin'|'theme' $type  Type traité.
	 * @param mixed            $value Transitoire.
	 * @return mixed
	 */
	private static function inject( string $type, $value ) {
		if ( ! $value instanceof \stdClass || ! isset( $value->checked ) || ! is_array( $value->checked ) ) {
			return $value;
		}
		$packages = self::packages();
		foreach ( self::FREE as $slug => $file ) {
			if ( ( $packages[ $slug ]['type'] ?? '' ) !== $type || ! isset( $value->checked[ $file ] ) ) {
				continue;
			}
			$found = self::check( $slug );
			if ( null === $found ) {
				continue;
			}
			$entry    = array(
				'new_version'  => $found['version'],
				'url'          => $found['url'],
				'package'      => $found['package'],
				'requires'     => $found['requires'],
				'requires_php' => $found['requires_php'],
			);
			$entry    = 'theme' === $type
				? array( 'theme' => $file ) + $entry
				: (object) ( array(
					'id'     => 'https://' . self::UPDATE_HOST . '/' . $slug,
					'slug'   => $slug,
					'plugin' => $file,
					'tested' => $found['tested'],
				) + $entry );
			$list     = version_compare( $found['version'], $packages[ $slug ]['version'], '>' ) ? 'response' : 'no_update';
			$response = isset( $value->response ) && is_array( $value->response ) ? $value->response : array();
			$current  = isset( $value->no_update ) && is_array( $value->no_update ) ? $value->no_update : array();
			unset( $response[ $file ], $current[ $file ] );
			if ( 'response' === $list ) {
				$response[ $file ] = $entry;
			} else {
				$current[ $file ] = $entry;
			}
			$value->response  = $response;
			$value->no_update = $current;
		}
		return $value;
	}

	/**
	 * Slug du paquet suivi qui correspond à ce fichier, ou null.
	 *
	 * @param string $type plugin|theme.
	 * @param string $file plugin_basename ou dossier du thème.
	 */
	private static function slug_for( string $type, string $file ): ?string {
		foreach ( self::packages() as $slug => $package ) {
			if ( $type === $package['type'] && $file === $package['file'] ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * Issue de la dernière vérification faite par ce processus, par slug.
	 *
	 * @var array<string, string>
	 */
	private static array $outcomes = array();

	/**
	 * Issue de la dernière vérification d'un paquet : update, current, unpublished (aucune version publiée),
	 * unauthorized (401 ou 403 : la clé n'est pas acceptée), rate_limited (429 : trop de vérifications),
	 * unreachable (panne réseau ou serveur) ou invalid_package (réponse inutilisable). '' si aucune.
	 *
	 * @param string $slug Slug inscrit.
	 */
	public static function outcome( string $slug ): string {
		return self::$outcomes[ $slug ] ?? '';
	}

	/**
	 * Phrase lisible pour une issue de vérification, avec l'action utile ; '' pour une issue sans problème.
	 *
	 * @param string $outcome Valeur rendue par outcome().
	 */
	public static function outcome_message( string $outcome ): string {
		return match ( $outcome ) {
			'unpublished'     => __( 'The API has no published version for this package yet, so there is nothing to install.', 'fluffless-suite' ),
			'unreachable'     => __( 'The update server did not answer, so try again later. Your site keeps working as it is.', 'fluffless-suite' ),
			'unauthorized'    => __( 'The update server did not accept the licence key, so updates are paused. Check the key in Fluffless → Modules. Your site keeps working as it is.', 'fluffless-suite' ),
			'rate_limited'    => __( 'The update server asked for a pause because of too many checks, so try again in a few minutes. Your site keeps working as it is.', 'fluffless-suite' ),
			'invalid_package' => __( 'The update server sent a package address that is not https, so the update was ignored. Report a problem if it keeps happening.', 'fluffless-suite' ),
			default           => '',
		};
	}

	/**
	 * Mise à jour d'un paquet : réponse en cache si fraîche, sinon demande à l'API.
	 * Rend les champs attendus par WordPress, la version installée si tout est à jour, ou null.
	 *
	 * @param string $slug  Slug inscrit.
	 * @param bool   $force Ignorer la fraîcheur du cache (WP-CLI).
	 * @return array{version: string, package: string, url: string, requires: string, requires_php: string, tested: string, sha256: string, manifest: string, manifest_signature: string}|null
	 */
	public static function check( string $slug, bool $force = false ): ?array {
		unset( self::$outcomes[ $slug ] );
		$package = self::packages()[ $slug ] ?? null;
		$licence = self::licence();
		if ( null === $package || ! self::can_verify() || '' === $licence ) {
			return null;
		}
		$channel = Options::get_string( self::id(), 'channel' );
		$print   = md5( $package['version'] . '|' . $channel . '|' . $licence . '|' . Api::base() );
		$cache   = self::cache();
		$entry   = $cache[ $slug ] ?? null;
		if ( ! $force && null !== $entry && $print === $entry['print'] && $entry['checked'] > time() - self::FRESH ) {
			return $entry['update'];
		}
		self::$outcomes[ $slug ] = 'unreachable';
		// API muette depuis moins d'une heure : chaque vérification attendrait 10 s pour rien.
		if ( ! $force && false !== get_transient( Lifecycle::TRANSIENT_API_SILENT ) ) {
			return self::fallback( $slug, $cache, $entry );
		}

		$query = array(
			'slug'    => $slug,
			'version' => $package['version'],
			'channel' => 'beta' === $channel ? 'beta' : 'stable',
			'site'    => Api::site_hash(),
		);
		// Un site de développement ne prend aucune place : le drapeau suit l'empreinte, jamais l'adresse.
		if ( Api::current_site_is_dev() ) {
			$query['dev'] = '1';
		}
		$response = Api::get( '/v1/update', $query, $licence );
		$code     = is_wp_error( $response ) ? 0 : $response['code'];
		if ( 0 === $code || $code >= 500 ) {
			set_transient( Lifecycle::TRANSIENT_API_SILENT, 1, self::SILENT );
		} else {
			delete_transient( Lifecycle::TRANSIENT_API_SILENT );
		}
		$body   = is_wp_error( $response ) ? null : $response['body'];
		$update = null;
		$plain  = false;
		if ( 200 === $code ) {
			$update = self::valid_update( $slug, $body );
			$code   = null === $update ? 0 : $code;
			$plain  = null === $update && is_array( $body ) && is_string( $body['package'] ?? null ) && '' !== $body['package'] && ! str_starts_with( strtolower( $body['package'] ), 'https://' );
		} elseif ( 204 === $code ) {
			$update = self::current( $package['version'] );
		}
		self::$outcomes[ $slug ] = match ( true ) {
			$plain        => 'invalid_package',
			404 === $code => 'unpublished',
			204 === $code => 'current',
			200 === $code => 'update',
			401 === $code, 403 === $code => 'unauthorized',
			429 === $code => 'rate_limited',
			default       => 'unreachable',
		};

		// Panne réseau, erreur serveur, réponse illisible, pause demandée (429) ou refus sans état de
		// licence (401) : on garde le dernier résultat, sans bruit, plutôt que de le remplacer par « à jour ».
		if ( 0 === $code || $code >= 500 || 429 === $code || 401 === $code ) {
			return self::fallback( $slug, $cache, $entry );
		}

		if ( 403 === $code ) {
			$state = is_array( $body ) && is_string( $body['code'] ?? null ) ? $body['code'] : 'licence_invalid';
			$state = in_array( $state, self::LICENCE_STATES, true ) ? $state : 'licence_invalid';
			self::remember_licence( $state, 'licence_expired' === $state ? self::grace_details( $body ) : array() );
		} elseif ( 200 === $code || 204 === $code ) {
			$notice = is_array( $body ) && is_string( $body['notice_until'] ?? null ) ? $body['notice_until'] : ( is_wp_error( $response ) ? '' : $response['notice_until'] );
			self::remember_licence( 'valid', array( 'notice_until' => self::timestamp( $notice ) ) );
		}
		$cache[ $slug ] = array(
			'print'   => $print,
			'checked' => time(),
			'ok'      => time(),
			'update'  => $update,
		);
		set_transient( Lifecycle::TRANSIENT_UPDATES, $cache, self::KEEP );
		return $update;
	}

	/**
	 * Repli quand l'API ne donne rien d'utilisable : la dernière entrée, dont seul checked avance
	 * (les appels s'espacent). Plus d'une semaine après la dernière réponse, une entrée qui porte
	 * un paquet est retirée : la mise à jour n'est plus proposée.
	 *
	 * @param string                    $slug  Slug inscrit.
	 * @param array<string, mixed>      $cache Cache validé (voir cache()).
	 * @param array<string, mixed>|null $entry Entrée de ce slug, ou null.
	 * @return array{version: string, package: string, url: string, requires: string, requires_php: string, tested: string, sha256: string, manifest: string, manifest_signature: string}|null
	 */
	private static function fallback( string $slug, array $cache, ?array $entry ): ?array {
		if ( null === $entry ) {
			return null;
		}
		if ( $entry['ok'] <= time() - self::KEEP && is_array( $entry['update'] ) && '' !== $entry['update']['package'] ) {
			unset( $cache[ $slug ] );
			set_transient( Lifecycle::TRANSIENT_UPDATES, $cache, self::KEEP );
			return null;
		}
		$entry['checked'] = time();
		$cache[ $slug ]   = $entry;
		set_transient( Lifecycle::TRANSIENT_UPDATES, $cache, self::KEEP );
		return $entry['update'];
	}

	/**
	 * Entrée « à jour » : version installée, sans paquet. WordPress la range dans no_update,
	 * ce qui garde l'interrupteur des mises à jour automatiques.
	 *
	 * @param string $version Version installée.
	 * @return array{version: string, package: string, url: string, requires: string, requires_php: string, tested: string, sha256: string, manifest: string, manifest_signature: string}
	 */
	private static function current( string $version ): array {
		return array(
			'version'            => $version,
			'package'            => '',
			'url'                => 'https://fluffless.dev',
			'requires'           => '',
			'requires_php'       => '',
			'tested'             => '',
			'sha256'             => '',
			'manifest'           => '',
			'manifest_signature' => '',
		);
	}

	/**
	 * Réponse 200 validée, ou null si un champ manque ou détonne.
	 *
	 * @param string $slug Slug demandé.
	 * @param mixed  $body Corps décodé.
	 * @return array{version: string, package: string, url: string, requires: string, requires_php: string, tested: string, sha256: string, manifest: string, manifest_signature: string}|null
	 */
	private static function valid_update( string $slug, $body ): ?array {
		if ( ! is_array( $body ) || ( $body['slug'] ?? '' ) !== $slug ) {
			return null;
		}
		$version = is_string( $body['version'] ?? null ) ? $body['version'] : '';
		$package = is_string( $body['package'] ?? null ) ? esc_url_raw( $body['package'], array( 'https' ) ) : '';
		$sha256  = is_string( $body['sha256'] ?? null ) ? strtolower( $body['sha256'] ) : '';
		if ( ! self::is_version( $version ) || '' === $package || 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return null;
		}
		$short = static fn( string $key ): string => is_string( $body[ $key ] ?? null ) && self::is_version( $body[ $key ] ) ? $body[ $key ] : '';
		// Manifeste signé : gardé tel quel, vérifié au téléchargement (manifest_valid()). Absent sur un
		// serveur d'avant octobre 2026 : la fiche est proposée, l'installation sera refusée.
		$text = static fn( string $key, int $max ): string => is_string( $body[ $key ] ?? null ) && strlen( $body[ $key ] ) <= $max ? $body[ $key ] : '';
		return array(
			'version'            => $version,
			'package'            => $package,
			'url'                => 'https://fluffless.dev',
			'requires'           => $short( 'requires' ),
			'requires_php'       => $short( 'requires_php' ),
			'tested'             => $short( 'tested' ),
			'sha256'             => $sha256,
			'manifest'           => $text( 'manifest', self::MANIFEST_MAX ),
			'manifest_signature' => $text( 'manifest_signature', 128 ),
		);
	}

	/**
	 * Numéro de version plausible : 1.2, 1.2.3, 1.2.3-beta.1.
	 *
	 * @param string $version Valeur reçue.
	 */
	private static function is_version( string $version ): bool {
		return 1 === preg_match( '/^\d{1,4}(\.\d{1,4}){1,3}(-[0-9A-Za-z.]{1,20})?$/', $version );
	}

	/**
	 * Cache validé : slug => empreinte des paramètres, date du dernier appel, date de la dernière
	 * réponse reçue (ok), résultat.
	 *
	 * @return array<string, array{print: string, checked: int, ok: int, update: array{version: string, package: string, url: string, requires: string, requires_php: string, tested: string, sha256: string, manifest: string, manifest_signature: string}|null}>
	 */
	private static function cache(): array {
		$raw   = get_transient( Lifecycle::TRANSIENT_UPDATES );
		$clean = array();
		foreach ( is_array( $raw ) ? $raw : array() as $slug => $entry ) {
			if ( ! is_string( $slug ) || ! is_array( $entry ) || ! is_string( $entry['print'] ?? null ) || ! is_int( $entry['checked'] ?? null ) ) {
				continue;
			}
			$update = self::cached_update( $entry['update'] ?? null );
			// Une entrée « update » illisible invalide l'entrée : on redemandera à l'API.
			if ( null === $update && null !== ( $entry['update'] ?? null ) ) {
				continue;
			}
			$clean[ $slug ] = array(
				'print'   => $entry['print'],
				'checked' => $entry['checked'],
				// Entrée d'avant ce champ : la date du dernier appel en tient lieu.
				'ok'      => is_int( $entry['ok'] ?? null ) ? $entry['ok'] : $entry['checked'],
				'update'  => $update,
			);
		}
		return $clean;
	}

	/**
	 * Résultat en cache relu champ par champ, ou null.
	 *
	 * @param mixed $raw Valeur lue dans le transitoire.
	 * @return array{version: string, package: string, url: string, requires: string, requires_php: string, tested: string, sha256: string, manifest: string, manifest_signature: string}|null
	 */
	private static function cached_update( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$update = array();
		foreach ( array( 'version', 'package', 'url', 'requires', 'requires_php', 'tested', 'sha256', 'manifest', 'manifest_signature' ) as $key ) {
			if ( ! is_string( $raw[ $key ] ?? null ) ) {
				return null;
			}
			$update[ $key ] = $raw[ $key ];
		}
		return array(
			'version'            => $update['version'],
			'package'            => $update['package'],
			'url'                => $update['url'],
			'requires'           => $update['requires'],
			'requires_php'       => $update['requires_php'],
			'tested'             => $update['tested'],
			'sha256'             => $update['sha256'],
			'manifest'           => $update['manifest'],
			'manifest_signature' => $update['manifest_signature'],
		);
	}

	/**
	 * Vérification forcée de tous les paquets (WP-CLI) : slug => résultat lisible.
	 *
	 * @return array<string, array{installed: string, available: string, state: string, outcome: string}>
	 */
	public static function check_all(): array {
		$report = array();
		foreach ( self::packages() as $slug => $package ) {
			$update          = self::check( $slug, true );
			$report[ $slug ] = array(
				'installed' => $package['version'],
				'available' => null === $update ? '' : $update['version'],
				'state'     => self::blocker(),
				'outcome'   => self::outcome( $slug ),
			);
		}
		return $report;
	}

	/* ---------- Signature et téléchargement ---------- */

	/** Clé publique valide (32 octets en base64), ou ''. */
	public static function public_key(): string {
		$key = defined( 'FLUFFLESS_SIGNING_PUBLIC_KEY' ) ? constant( 'FLUFFLESS_SIGNING_PUBLIC_KEY' ) : self::SIGNING_PUBLIC_KEY;
		$key = is_string( $key ) ? trim( $key ) : '';
		$raw = base64_decode( $key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- format des clés de WordPress.
		return false !== $raw && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $raw ) ? $key : '';
	}

	/** Environnements où FLUFFLESS_ALLOW_UNSIGNED est pris en compte. */
	private const UNSIGNED_ENVIRONMENTS = array( 'local', 'development' );

	/**
	 * Sort de FLUFFLESS_ALLOW_UNSIGNED : 'off' (non demandé), 'on' (demandé en local ou development)
	 * ou 'ignored' (demandé ailleurs : refusé, avec un avis). Pure, pour être testée sans constante.
	 *
	 * @param bool   $requested   Constante définie à true.
	 * @param string $environment Valeur de wp_get_environment_type().
	 */
	public static function unsigned_state( bool $requested, string $environment ): string {
		if ( ! $requested ) {
			return 'off';
		}
		return in_array( $environment, self::UNSIGNED_ENVIRONMENTS, true ) ? 'on' : 'ignored';
	}

	/** État courant de FLUFFLESS_ALLOW_UNSIGNED sur ce site. */
	private static function unsigned_current(): string {
		$requested = defined( 'FLUFFLESS_ALLOW_UNSIGNED' ) && true === constant( 'FLUFFLESS_ALLOW_UNSIGNED' );
		return self::unsigned_state( $requested, wp_get_environment_type() );
	}

	/** Paquets non signés permis : constante de développement, en local ou development seulement. */
	private static function unsigned_allowed(): bool {
		return 'on' === self::unsigned_current();
	}

	/** Peut-on vérifier un paquet ? Sinon aucune mise à jour n'est proposée. */
	private static function can_verify(): bool {
		return '' !== self::public_key() || self::unsigned_allowed();
	}

	/**
	 * Signature ed25519 valide pour ce fichier : un seul format, celui du serveur, qui signe les octets
	 * exacts du zip à la mise en ligne.
	 *
	 * @param string $file      Paquet téléchargé.
	 * @param string $signature Signature en base64.
	 */
	private static function signature_valid( string $file, string $signature ): bool {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- format des signatures.
		$sig = base64_decode( trim( $signature ), true );
		$key = base64_decode( self::public_key(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- format des clés.
		$zip = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local temporaire.
		if ( false === $sig || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || false === $key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) || false === $zip ) {
			return false;
		}
		return sodium_crypto_sign_verify_detached( $sig, $zip, $key );
	}

	/** Taille maximale d'un manifeste : trois champs courts, rien d'autre. */
	private const MANIFEST_MAX = 1024;

	/**
	 * Manifeste signé {slug, version, sha256} : signature ed25519 vérifiée sur la chaîne exacte reçue
	 * (jamais recomposée), puis chaque champ comparé à la fiche annoncée. Lie la somme et la version
	 * à la clé du serveur : une fiche altérée en route ou en cache ne passe plus.
	 *
	 * @param array{slug: string, version: string, sha256: string, manifest: string, manifest_signature: string} $known Fiche annoncée.
	 */
	public static function manifest_valid( array $known ): bool {
		$manifest = $known['manifest'];
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- format des signatures.
		$sig = base64_decode( trim( $known['manifest_signature'] ), true );
		$key = base64_decode( self::public_key(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- format des clés.
		if ( '' === $manifest || strlen( $manifest ) > self::MANIFEST_MAX || false === $sig || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || false === $key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			return false;
		}
		if ( ! sodium_crypto_sign_verify_detached( $sig, $manifest, $key ) ) {
			return false;
		}
		$data = json_decode( $manifest, true );
		if ( ! is_array( $data ) || ! is_string( $data['sha256'] ?? null ) ) {
			return false;
		}
		return ( $data['slug'] ?? null ) === $known['slug']
			&& ( $data['version'] ?? null ) === $known['version']
			&& hash_equals( $known['sha256'], strtolower( $data['sha256'] ) );
	}

	/**
	 * Signature du paquet : en-tête X-Content-Signature, sinon <package>.sig ; '' si absente.
	 *
	 * @param array<mixed>|\WP_Error $response Réponse du téléchargement.
	 * @param string                 $package  Adresse du paquet.
	 */
	private static function signature_for( $response, string $package ): string {
		$header = is_wp_error( $response ) ? '' : wp_remote_retrieve_header( $response, 'x-content-signature' );
		if ( is_string( $header ) && '' !== $header ) {
			return $header;
		}
		$sig = wp_safe_remote_get(
			$package . '.sig',
			array(
				'timeout'             => 10,
				'limit_response_size' => 1024,
			)
		);
		return 200 === wp_remote_retrieve_response_code( $sig ) ? trim( wp_remote_retrieve_body( $sig ) ) : '';
	}

	/**
	 * Paquet frais pour ce slug : le lien de l'API expire après 15 minutes, le cache dure 12 h.
	 * Rend le paquet et la somme de la même réponse, ou ceux du cache si l'API ne répond pas.
	 *
	 * @param string $package Adresse du paquet connue de WordPress.
	 * @return array{slug: string, package: string, sha256: string, version: string, manifest: string, manifest_signature: string}|null Null si le paquet n'est pas à nous.
	 */
	private static function fresh_package( string $package ): ?array {
		foreach ( self::cache() as $slug => $entry ) {
			if ( is_array( $entry['update'] ) && $package === $entry['update']['package'] ) {
				$fresh = self::check( $slug, true );
				$found = is_array( $fresh ) && '' !== $fresh['package'] && $fresh['version'] === $entry['update']['version'] ? $fresh : $entry['update'];
				return array(
					'slug'               => (string) $slug,
					'package'            => $found['package'],
					'sha256'             => $found['sha256'],
					'version'            => $found['version'],
					'manifest'           => $found['manifest'],
					'manifest_signature' => $found['manifest_signature'],
				);
			}
		}
		return null;
	}

	/**
	 * Ajoute à un message d'échec d'installation ce qu'on peut faire ensuite.
	 *
	 * @param string $message Phrase qui dit ce qui a échoué.
	 */
	private static function retry_hint( string $message ): string {
		return $message . ' ' . __( 'Try again from Dashboard → Updates; if it keeps failing, check your licence in Fluffless → Modules or use Report a problem.', 'fluffless-suite' );
	}

	/**
	 * Télécharge un paquet Fluffless : lien renouvelé, signature vérifiée, puis somme SHA-256.
	 * Les paquets des autres sources passent sans changement.
	 *
	 * @param mixed $reply      Réponse d'un filtre précédent.
	 * @param mixed $package    Adresse du paquet.
	 * @param mixed $upgrader   Instance de WP_Upgrader.
	 * @param mixed $hook_extra Contexte.
	 * @return mixed
	 */
	public static function pre_download( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader, $hook_extra );
		if ( false !== $reply || ! is_string( $package ) || '' === $package ) {
			return $reply;
		}
		$known = self::fresh_package( $package );
		if ( null === $known ) {
			return $reply;
		}
		if ( ! self::can_verify() ) {
			return new \WP_Error( 'fluffless_unsigned', self::blocker_message( 'unsigned' ) );
		}
		// Manifeste vérifié avant tout téléchargement : sans lui, rien n'est installé.
		if ( ! self::unsigned_allowed() && ! self::manifest_valid( $known ) ) {
			return new \WP_Error( 'fluffless_package_manifest', self::retry_hint( __( 'The Fluffless update manifest could not be verified, so nothing was installed.', 'fluffless-suite' ) ) );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file     = wp_tempnam( $known['package'] );
		$response = wp_safe_remote_get(
			$known['package'],
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $file,
			)
		);
		$verified = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response )
			&& ( self::unsigned_allowed() || self::signature_valid( $file, self::signature_for( $response, $known['package'] ) ) );
		if ( ! $verified ) {
			wp_delete_file( $file );
			/* translators: 1: HTTP status code, e.g. 404 */
			$detail = is_wp_error( $response ) ? $response->get_error_message() : sprintf( __( 'the download server answered with HTTP %d, or the package signature did not match', 'fluffless-suite' ), (int) wp_remote_retrieve_response_code( $response ) );
			// API muette à la revérification et paquet introuvable : la cause est le serveur, pas la licence.
			$silent = 'unreachable' === self::outcome( $known['slug'] ) && ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) );
			$text   = $silent ? self::outcome_message( 'unreachable' ) : self::retry_hint( __( 'The Fluffless package could not be verified, so it was not installed.', 'fluffless-suite' ) );
			return new \WP_Error( 'fluffless_package_signature', $text, $detail );
		}
		$sha256 = $known['sha256'];
		if ( ! hash_equals( $sha256, (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'fluffless_package_hash', self::retry_hint( __( 'The Fluffless package does not match its checksum, so it was not installed.', 'fluffless-suite' ) ) );
		}
		// La signature couvre les octets, pas la version : un ancien paquet signé resterait valide.
		// Le paquet doit porter la version annoncée, plus récente que celle installée.
		$installed = self::packages()[ $known['slug'] ] ?? null;
		$inside    = null === $installed ? '' : self::zip_version( $file, 'theme' === $installed['type'] ? $installed['file'] . '/style.css' : $installed['file'] );
		if ( null === $installed || $inside !== $known['version'] || version_compare( $inside, $installed['version'], '<=' ) ) {
			wp_delete_file( $file );
			/* translators: %s: version number found inside the package */
			$found = '' === $inside ? __( 'no version number was found inside the package', 'fluffless-suite' ) : sprintf( __( 'the package holds version %s', 'fluffless-suite' ), $inside );
			return new \WP_Error( 'fluffless_package_version', self::retry_hint( __( 'The Fluffless package is not the expected version, so it was not installed.', 'fluffless-suite' ) ), $found );
		}
		return $file;
	}

	/**
	 * Version inscrite dans le fichier principal d'un ZIP (en-tête « Version: »), ou chaîne vide.
	 *
	 * @param string $zip   Paquet local.
	 * @param string $entry Chemin du fichier principal dans le ZIP (slug/fichier).
	 */
	private static function zip_version( string $zip, string $entry ): string {
		$head = '';
		if ( class_exists( 'ZipArchive' ) ) {
			$archive = new \ZipArchive();
			if ( true === $archive->open( $zip, \ZipArchive::RDONLY ) ) {
				$head = (string) $archive->getFromName( $entry, 8192 );
				$archive->close();
			}
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$archive = new \PclZip( $zip );
			// extract() lit ses options par func_get_args() : PHPStan n'en voit aucune.
			$found = $archive->extract( constant( 'PCLZIP_OPT_BY_NAME' ), $entry, constant( 'PCLZIP_OPT_EXTRACT_AS_STRING' ) ); // @phpstan-ignore arguments.count
			$head  = is_array( $found ) && is_string( $found[0]['content'] ?? null ) ? substr( $found[0]['content'], 0, 8192 ) : '';
		}
		// Même lecture que get_file_data() : première occurrence de l'en-tête, en début de ligne.
		if ( 1 !== preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $head, $match ) ) {
			return '';
		}
		$version = trim( $match[1] );
		return self::is_version( $version ) ? $version : '';
	}

	/* ---------- Détails (« Voir les détails ») ---------- */

	/**
	 * Filtre plugins_api.
	 *
	 * @param mixed $result Réponse d'un filtre précédent.
	 * @param mixed $action Action demandée.
	 * @param mixed $args   Arguments (objet avec slug).
	 * @return mixed
	 */
	public static function plugin_info( $result, $action, $args ) {
		return self::info_for( 'plugin', 'plugin_information', $result, $action, $args );
	}

	/**
	 * Filtre themes_api.
	 *
	 * @param mixed $result Réponse d'un filtre précédent.
	 * @param mixed $action Action demandée.
	 * @param mixed $args   Arguments (objet avec slug).
	 * @return mixed
	 */
	public static function theme_info( $result, $action, $args ) {
		return self::info_for( 'theme', 'theme_information', $result, $action, $args );
	}

	/**
	 * Détails bilingues d'un paquet suivi, en cache 12 h par langue.
	 *
	 * @param string $type     plugin|theme.
	 * @param string $expected Action traitée.
	 * @param mixed  $result   Réponse d'un filtre précédent.
	 * @param mixed  $action   Action demandée.
	 * @param mixed  $args     Arguments.
	 * @return mixed
	 */
	private static function info_for( string $type, string $expected, $result, $action, $args ) {
		$slug     = is_object( $args ) && isset( $args->slug ) && is_string( $args->slug ) ? $args->slug : '';
		$packages = self::packages();
		if ( $expected !== $action || ( $packages[ $slug ]['type'] ?? '' ) !== $type ) {
			return $result;
		}
		$locale    = str_starts_with( determine_locale(), 'fr' ) ? 'fr_CA' : 'en_CA';
		$cache_key = Lifecycle::PREFIX . 'info_' . md5( $slug . '|' . $locale . '|' . Api::base() );
		$info      = get_transient( $cache_key );
		if ( ! is_array( $info ) ) {
			$response = Api::get(
				'/v1/info',
				array(
					'slug'   => $slug,
					'locale' => $locale,
				),
				self::licence()
			);
			$info     = is_wp_error( $response ) || 200 !== $response['code'] ? null : self::valid_info( $response['body'] );
			if ( null === $info ) {
				return new \WP_Error( 'fluffless_info', __( 'Details are unavailable right now. Try again in a few minutes.', 'fluffless-suite' ) );
			}
			set_transient( $cache_key, $info, self::FRESH );
		}
		return (object) ( array( 'slug' => $slug ) + $info );
	}

	/**
	 * Détails validés et assainis (le HTML des sections passe par wp_kses_post).
	 *
	 * @param mixed $body Corps décodé.
	 * @return array<string, mixed>|null
	 */
	private static function valid_info( $body ) {
		if ( ! is_array( $body ) || ! is_string( $body['name'] ?? null ) || ! is_string( $body['version'] ?? null ) || ! self::is_version( $body['version'] ) ) {
			return null;
		}
		$sections = array();
		foreach ( array( 'description', 'changelog' ) as $section ) {
			$sections[ $section ] = is_string( $body['sections'][ $section ] ?? null ) ? wp_kses_post( $body['sections'][ $section ] ) : '';
		}
		$text = static fn( string $key ): string => is_string( $body[ $key ] ?? null ) ? sanitize_text_field( $body[ $key ] ) : '';
		return array(
			'name'         => sanitize_text_field( $body['name'] ),
			'version'      => $body['version'],
			'sections'     => $sections,
			'requires'     => $text( 'requires' ),
			'tested'       => $text( 'tested' ),
			'requires_php' => $text( 'requires_php' ),
			'last_updated' => $text( 'last_updated' ),
			'homepage'     => is_string( $body['homepage'] ?? null ) ? esc_url_raw( $body['homepage'], array( 'https' ) ) : '',
		);
	}

	/* ---------- Licence ---------- */

	/** Clé de licence : la constante FLUFFLESS_LICENCE_KEY gagne sur le réglage. */
	public static function licence(): string {
		$constant = defined( self::LICENCE_CONSTANT ) ? constant( self::LICENCE_CONSTANT ) : '';
		if ( is_string( $constant ) && '' !== trim( $constant ) ) {
			return trim( $constant );
		}
		$option = get_option( Lifecycle::OPTION_LICENCE, '' );
		return is_string( $option ) ? $option : '';
	}

	/** La clé vient-elle de wp-config.php ? */
	private static function licence_from_constant(): bool {
		$constant = defined( self::LICENCE_CONSTANT ) ? constant( self::LICENCE_CONSTANT ) : '';
		return is_string( $constant ) && '' !== trim( $constant );
	}

	/** Clé masquée : les 4 derniers caractères seulement. */
	public static function masked(): string {
		$licence = self::licence();
		return '' === $licence ? '' : '••••' . substr( $licence, -4 );
	}

	/**
	 * Vrai quand la dernière vérification de la clé n'a pas eu de réponse du serveur : la clé est
	 * enregistrée, son état reste inconnu.
	 */
	public static function licence_unverified(): bool {
		$state = get_transient( Lifecycle::TRANSIENT_LICENCE );
		return is_array( $state ) && self::UNVERIFIED === ( $state['state'] ?? null ) && '' !== self::licence();
	}

	/** Dernier état connu de la licence : un de LICENCE_STATES, ou ''. */
	public static function licence_state(): string {
		$state = get_transient( Lifecycle::TRANSIENT_LICENCE );
		return is_array( $state ) && is_string( $state['state'] ?? null ) && in_array( $state['state'], self::LICENCE_STATES, true ) ? $state['state'] : '';
	}

	/**
	 * Détails retenus avec l'état : places (sites_used, sites_max, unlimited).
	 *
	 * @return array<string, int|bool|string>
	 */
	public static function licence_details(): array {
		$state = get_transient( Lifecycle::TRANSIENT_LICENCE );
		if ( ! is_array( $state ) || ! is_array( $state['details'] ?? null ) ) {
			return array();
		}
		return array_filter( $state['details'], static fn( $value ): bool => is_int( $value ) || is_bool( $value ) || is_string( $value ) );
	}

	/**
	 * Retient l'état de la licence (sans la clé). Un même état garde les détails déjà appris
	 * (verify donne les places, /v1/update ne les redit pas) ; une valeur '' ou null en retire un.
	 *
	 * @param string                              $state   État.
	 * @param array<string, int|bool|string|null> $details Détails donnés par cette réponse.
	 */
	private static function remember_licence( string $state, array $details = array() ): void {
		$known   = self::licence_state() === $state ? self::licence_details() : array();
		$details = array_filter( array_merge( $known, $details ), static fn( $value ): bool => null !== $value && '' !== $value );
		set_transient(
			Lifecycle::TRANSIENT_LICENCE,
			array(
				'state'   => $state,
				'details' => $details,
			),
			self::KEEP
		);
	}

	/**
	 * Grâce d'une licence échue (prix gelé), lue dans un refus licence_expired : date de fin, date
	 * limite et lien signé de renouvellement. Sans grâce, ou avec un lien hors de l'API, les trois
	 * valent null pour effacer ce qui était retenu.
	 *
	 * @param mixed $body Corps décodé de la réponse.
	 * @return array{expires: int|null, grace_until: int|null, renew_url: string|null}
	 */
	private static function grace_details( $body ): array {
		$none = array(
			'expires'     => null,
			'grace_until' => null,
			'renew_url'   => null,
		);
		if ( ! is_array( $body ) || ! is_string( $body['expires'] ?? null ) || ! is_string( $body['grace_until'] ?? null ) || ! is_string( $body['renew_url'] ?? null ) ) {
			return $none;
		}
		$expires = strtotime( $body['expires'] );
		$until   = strtotime( $body['grace_until'] );
		$url     = esc_url_raw( $body['renew_url'], array( 'https', 'http' ) );
		if ( false === $expires || false === $until || $url !== $body['renew_url'] || ! str_starts_with( $url, Api::base() . '/v1/licences/renew?' ) ) {
			return $none;
		}
		return array(
			'expires'     => $expires,
			'grace_until' => $until,
			'renew_url'   => $url,
		);
	}

	/**
	 * Grâce en cours, si la licence est échue et que la date limite n'est pas passée.
	 *
	 * @return array{expires: int, grace_until: int, renew_url: string}|null
	 */
	private static function grace(): ?array {
		$details = self::licence_details();
		if ( 'licence_expired' !== self::licence_state() || ! is_int( $details['expires'] ?? null ) || ! is_int( $details['grace_until'] ?? null ) || ! is_string( $details['renew_url'] ?? null ) || $details['grace_until'] < time() ) {
			return null;
		}
		return array(
			'expires'     => $details['expires'],
			'grace_until' => $details['grace_until'],
			'renew_url'   => $details['renew_url'],
		);
	}

	/**
	 * Date ISO de l'API en horodatage, ou null.
	 *
	 * @param mixed $value Valeur reçue.
	 */
	private static function timestamp( $value ): ?int {
		$at = is_string( $value ) && '' !== $value ? strtotime( $value ) : false;
		return false === $at ? null : $at;
	}

	/**
	 * Avis écrit en cours sur l'usage de la licence, pour l'administration seulement ; '' sinon.
	 * Le courriel peut se perdre : la Suite le redit jusqu'à la date de réponse.
	 */
	public static function usage_notice(): string {
		$until = self::licence_details()['notice_until'] ?? null;
		if ( ! is_int( $until ) || $until < time() || ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return sprintf(
			/* translators: %s: date to answer by */
			__( 'I sent you a message about how your Fluffless licence is used. Please answer before %s; updates keep working until then.', 'fluffless-suite' ),
			wp_date( (string) get_option( 'date_format' ), $until )
		);
	}

	/**
	 * Adresse de renouvellement d'une licence échue : le lien signé de l'API pendant la grâce,
	 * sinon la page des prix ; '' si la licence n'est pas échue.
	 */
	public static function renew_url(): string {
		if ( 'licence_expired' !== self::licence_state() ) {
			return '';
		}
		$grace = self::grace();
		if ( null !== $grace ) {
			return $grace['renew_url'];
		}
		return str_starts_with( determine_locale(), 'fr' ) ? 'https://fluffless.dev/prix/' : 'https://fluffless.dev/en/pricing/';
	}

	/** Lien « Renew my licence » pour une licence échue, ou ''. */
	private static function renew_link(): string {
		$url = self::renew_url();
		return '' === $url ? '' : sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'Renew my licence', 'fluffless-suite' ) );
	}

	/** Déclare le réglage de licence, hors API REST : la clé ne quitte jamais le serveur. */
	public static function register_licence_setting(): void {
		register_setting(
			Options::GROUP,
			Lifecycle::OPTION_LICENCE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_licence' ),
				'show_in_rest'      => false,
				'default'           => '',
			)
		);
	}

	/**
	 * Assainit le champ de licence : vide garde la clé enregistrée, remove la retire.
	 *
	 * @param mixed $raw Valeur reçue : array{key?: string, remove?: string} du formulaire, ou chaîne.
	 */
	public static function sanitize_licence( $raw ): string {
		$current = get_option( Lifecycle::OPTION_LICENCE, '' );
		$current = is_string( $current ) ? $current : '';
		if ( is_array( $raw ) && ! empty( $raw['remove'] ) ) {
			return '';
		}
		$key = is_array( $raw ) ? ( $raw['key'] ?? '' ) : $raw;
		$key = is_string( $key ) ? trim( $key ) : '';
		return 1 === preg_match( '/^[A-Za-z0-9._-]{8,200}$/', $key ) ? $key : $current;
	}

	/** La clé a changé : on oublie l'état et le cache, puis on vérifie la nouvelle. */
	public static function licence_changed(): void {
		delete_transient( Lifecycle::TRANSIENT_UPDATES );
		delete_transient( Lifecycle::TRANSIENT_LICENCE );
		self::verify_licence();
	}

	/**
	 * La clé enregistrée a changé (update_option_ passe l'ancienne puis la nouvelle). On vérifie la
	 * nouvelle, puis l'ancienne libère la place de ce site si la clé est retirée, ou remplacée par
	 * une clé valide. Une clé refusée ne libère rien : l'ancienne peut revenir sans perdre sa place.
	 *
	 * @param mixed $old_value Ancienne clé.
	 * @param mixed $value     Nouvelle clé.
	 */
	public static function licence_updated( $old_value, $value ): void {
		$old = is_string( $old_value ) ? $old_value : '';
		$new = is_string( $value ) ? $value : '';
		self::licence_changed();
		if ( '' === $old || $old === $new || self::licence_from_constant() || ( '' !== $new && 'valid' !== self::licence_state() ) ) {
			return;
		}
		$response = Api::post( '/v1/licences/release', array( 'site' => Api::site_hash() ), $old );
		if ( self::release_unavailable( $response ) ) {
			set_transient(
				self::RELEASE_NOTICE . get_current_user_id(),
				array(
					'status'  => 'warn',
					'message' => self::release_unavailable_message( '' === $new ),
				),
				5 * MINUTE_IN_SECONDS
			);
			return;
		}
		if ( is_wp_error( $response ) || 0 === $response['code'] || $response['code'] >= 500 ) {
			set_transient(
				self::RELEASE_NOTICE . get_current_user_id(),
				array(
					'status'  => 'warn',
					'message' => '' === $new
						? __( "The seat couldn't be freed: the update server didn't answer. The key was removed anyway; try again later from another site, or write to me.", 'fluffless-suite' )
						: __( "The seat couldn't be freed: the update server didn't answer. Try again later, or write to me.", 'fluffless-suite' ),
				),
				5 * MINUTE_IN_SECONDS
			);
		}
	}

	/**
	 * Vérifie la licence auprès de l'API ; rend la réponse validée, ou null si l'API ne répond pas.
	 *
	 * @return array{valid: bool, plan: string, expires: string, sites_used: int, sites_max: int}|null
	 */
	public static function verify_licence(): ?array {
		$licence = self::licence();
		if ( '' === $licence ) {
			return null;
		}
		$data = array( 'site' => Api::site_hash() );
		if ( Api::current_site_is_dev() ) {
			$data['dev'] = true;
		}
		$response = Api::post( '/v1/licences/verify', $data, $licence );
		// Panne réseau ou serveur : l'état reste inconnu, et la carte le dit (licence_unverified()).
		if ( is_wp_error( $response ) || 0 === $response['code'] || $response['code'] >= 500 ) {
			set_transient( Lifecycle::TRANSIENT_LICENCE, array( 'state' => self::UNVERIFIED ), self::KEEP );
			return null;
		}
		if ( 200 !== $response['code'] || ! is_array( $response['body'] ) || ! is_bool( $response['body']['valid'] ?? null ) ) {
			return null;
		}
		$body   = $response['body'];
		$result = array(
			'valid'      => $body['valid'],
			'plan'       => is_string( $body['plan'] ?? null ) ? sanitize_text_field( $body['plan'] ) : '',
			'expires'    => is_string( $body['expires'] ?? null ) ? sanitize_text_field( $body['expires'] ) : '',
			'sites_used' => is_int( $body['sites_used'] ?? null ) ? $body['sites_used'] : 0,
			'sites_max'  => is_int( $body['sites_max'] ?? null ) ? $body['sites_max'] : 0,
		);
		// Le serveur répond toujours 200 : un refus porte valid:false et un code précis.
		$code = is_string( $body['code'] ?? null ) ? $body['code'] : '';
		if ( $result['valid'] ) {
			// Illimitée : l'API ne donne aucun maximum (sites_max null, unlimited true).
			self::remember_licence(
				'valid',
				array(
					'sites_used'   => $result['sites_used'],
					'sites_max'    => $result['sites_max'],
					'unlimited'    => true === ( $body['unlimited'] ?? null ),
					'notice_until' => self::timestamp( $body['notice_until'] ?? null ),
				)
			);
		} elseif ( 'valid' !== $code && in_array( $code, self::LICENCE_STATES, true ) ) {
			self::remember_licence( $code, 'licence_expired' === $code ? self::grace_details( $body ) : array() );
		} elseif ( $result['sites_max'] > 0 && $result['sites_used'] > $result['sites_max'] ) {
			self::remember_licence( 'site_limit' );
		} elseif ( '' !== $result['expires'] && strtotime( $result['expires'] ) < time() ) {
			self::remember_licence( 'licence_expired' );
		} else {
			self::remember_licence( 'licence_invalid' );
		}
		return $result;
	}

	/* ---------- Avis ---------- */

	/** Ce qui arrête les mises à jour : unsigned, no_licence, un état de licence refusé, ou ''. */
	private static function blocker(): string {
		if ( ! self::can_verify() ) {
			return 'unsigned';
		}
		if ( '' === self::licence() ) {
			return 'no_licence';
		}
		$state = self::licence_state();
		return 'valid' === $state ? '' : $state;
	}

	/**
	 * Message clair pour un blocage.
	 *
	 * @param string $blocker Code rendu par blocker().
	 */
	private static function blocker_message( string $blocker ): string {
		$grace = 'licence_expired' === $blocker ? self::grace() : null;
		if ( null !== $grace ) {
			$format = (string) get_option( 'date_format' );
			return sprintf(
				/* translators: 1: date the licence ended, 2: last day to renew at the same price */
				__( 'Your Fluffless licence ended on %1$s, so updates are paused. Your site keeps working. Renew by %2$s to keep your price.', 'fluffless-suite' ),
				wp_date( $format, $grace['expires'] ),
				wp_date( $format, $grace['grace_until'] )
			);
		}
		return match ( $blocker ) {
			'unsigned'        => __( 'Fluffless updates are paused: this copy has no signing key to check packages with. Your site keeps working; install the latest version by hand from fluffless.dev.', 'fluffless-suite' ),
			'no_licence'      => __( 'Fluffless updates are off until you add a licence key. Your site keeps working as it is.', 'fluffless-suite' ),
			'licence_expired' => __( 'Your Fluffless licence has expired, so updates are paused. Your site keeps working; renew at fluffless.dev to get updates again.', 'fluffless-suite' ),
			'site_limit'      => __( 'All the seats on your Fluffless licence are in use, so updates are paused here. Your site keeps working. Free the seat of a site you no longer manage in Fluffless → Modules, or move to a bigger licence at fluffless.dev.', 'fluffless-suite' ),
			'licence_invalid' => __( 'The Fluffless licence key was not recognized, so updates are paused. Your site keeps working; check the key in Fluffless → Modules.', 'fluffless-suite' ),
			// La clé est juste : en remettre une ne sert à rien, d'où needs_licence() faux.
			'dev_limit'       => __( 'Your licence has reached the number of development sites it can cover. Free the seat of one you no longer use, or write to me.', 'fluffless-suite' ),
			'licence_review'  => __( 'Your Fluffless licence needs a quick check before it can cover more sites. Your sites keep working and updating. Write to me at fluffless.dev.', 'fluffless-suite' ),
			'licence_revoked' => __( "Your Fluffless licence has been revoked, so updates are paused. Your site keeps working. Write to me at fluffless.dev if you think it's a mistake.", 'fluffless-suite' ),
			default           => '',
		};
	}

	/** Vrai quand les mises à jour attendent une clé de licence : absente, refusée ou échue. */
	public static function needs_licence(): bool {
		return in_array( self::blocker(), array( 'no_licence', 'licence_invalid', 'licence_expired' ), true );
	}

	/** État affiché sur la carte du module (voir Module). */
	public static function status(): string {
		return self::blocker_message( self::blocker() );
	}

	/** Avis sur les écrans Extensions, Thèmes et Mises à jour, pour qui peut mettre à jour. */
	public static function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! in_array( $screen->id, array( 'plugins', 'themes', 'update-core' ), true ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		if ( 'ignored' === self::unsigned_current() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'FLUFFLESS_ALLOW_UNSIGNED is ignored: it only works when the environment type is local or development. Unsigned Fluffless packages are still refused.', 'fluffless-suite' )
			);
		}
		$usage = self::usage_notice();
		if ( '' !== $usage ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $usage ) );
		}
		$message = self::status();
		if ( '' === $message ) {
			return;
		}
		$renew = self::renew_link();
		printf(
			'<div class="notice notice-warning"><p>%1$s %2$s<a href="%3$s">%4$s</a></p></div>',
			esc_html( $message ),
			'' === $renew ? '' : $renew . ' · ', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans renew_link().
			esc_url( SettingsPage::url( 'fl-title-updates' ) ),
			esc_html__( 'Update settings', 'fluffless-suite' )
		);
	}

	/** Ligne d'état des places : « 3 of 5 sites », ou le seul nombre de sites actifs pour l'illimitée. */
	private static function seats_line(): string {
		$details = self::licence_details();
		$used    = is_int( $details['sites_used'] ?? null ) ? $details['sites_used'] : 0;
		$max     = is_int( $details['sites_max'] ?? null ) ? $details['sites_max'] : 0;
		if ( true === ( $details['unlimited'] ?? null ) ) {
			/* translators: %d: number of active sites on an unlimited licence */
			return sprintf( _n( '%d active site', '%d active sites', $used, 'fluffless-suite' ), $used );
		}
		if ( $max <= 0 ) {
			return '';
		}
		/* translators: 1: sites using the licence, 2: sites the licence covers */
		return sprintf( _n( '%1$d of %2$d site', '%1$d of %2$d sites', $max, 'fluffless-suite' ), $used, $max );
	}

	/**
	 * Avis de la dernière libération, puis les commandes : une autre adresse d'abord (Entrée dans
	 * le champ vise son bouton), puis ce site. Elles appartiennent au formulaire séparé fl-release,
	 * rendu hors du formulaire des réglages (pas de formulaire imbriqué).
	 */
	private static function render_release_controls(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		$key    = self::RELEASE_NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) && is_string( $notice['status'] ?? null ) && is_string( $notice['message'] ?? null ) ) {
			delete_transient( $key );
			echo Screen::note( $notice['status'], $notice['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		if ( '' === self::licence() ) {
			return;
		}
		self::$release_form = true;
		printf(
			'<p><label for="fl-release-url">%1$s</label><br><input type="url" class="regular-text" id="fl-release-url" name="fluffless_release_url" form="fl-release" autocomplete="off" aria-describedby="fl-release-help"> <button type="submit" class="button" form="fl-release" name="fluffless_release" value="other">%2$s</button></p><p class="description" id="fl-release-help">%3$s</p>',
			esc_html__( 'Free the seat of another address', 'fluffless-suite' ),
			esc_html__( 'Free the seat', 'fluffless-suite' ),
			esc_html__( 'Enter the address of a site you no longer manage. Only its hash is sent.', 'fluffless-suite' )
		);
		printf(
			'<p><button type="submit" class="button" form="fl-release" name="fluffless_release" value="site">%s</button></p>',
			esc_html__( "Free this site's seat", 'fluffless-suite' )
		);
	}

	/**
	 * Vrai quand le serveur de mises à jour n'a pas encore la route de libération (404 not_found
	 * du routeur) : la place n'est pas libérée, mais la licence n'est pas en cause.
	 *
	 * @param array{code: int, body: mixed}|\WP_Error $response Réponse d'Api::post().
	 */
	private static function release_unavailable( $response ): bool {
		return ! is_wp_error( $response ) && 404 === $response['code'] && is_array( $response['body'] ) && 'not_found' === ( $response['body']['code'] ?? null );
	}

	/**
	 * Message quand la libération n'est pas encore offerte par le serveur.
	 *
	 * @param bool $key_removed Vrai quand la clé vient d'être retirée de ce site.
	 */
	private static function release_unavailable_message( bool $key_removed = false ): string {
		return $key_removed
			? __( "Freeing a seat from here is not available yet, so the seat is still taken. The key was removed anyway; write to me and I'll free the seat.", 'fluffless-suite' )
			: __( "Freeing a seat from here is not available yet, so the seat is still taken. Write to me and I'll free it.", 'fluffless-suite' );
	}

	/** Formulaire de libération, après le formulaire des réglages, si la carte en a besoin. */
	public static function render_release_form(): void {
		if ( ! self::$release_form ) {
			return;
		}
		self::$release_form = false;
		printf(
			'<form id="fl-release" action="%1$s" method="post"><input type="hidden" name="action" value="%2$s">%3$s</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::RELEASE_ACTION ),
			wp_nonce_field( self::RELEASE_ACTION, 'fluffless_release_nonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- champs du cœur.
		);
	}

	/** Reçoit le formulaire : droits, jeton, libération, avis retenu, retour à la carte. */
	public static function handle_release(): void {
		check_admin_referer( self::RELEASE_ACTION, 'fluffless_release_nonce' );
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to free a seat on this licence.', 'fluffless-suite' ), '', array( 'response' => 403 ) );
		}
		$other   = 'other' === sanitize_key( wp_unslash( $_POST['fluffless_release'] ?? '' ) );
		$address = $other ? trim( esc_url_raw( wp_unslash( (string) ( $_POST['fluffless_release_url'] ?? '' ) ), array( 'https', 'http' ) ) ) : '';
		$result  = $other && '' === $address ? self::invalid_address() : self::release_seat( $address );
		set_transient( self::RELEASE_NOTICE . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( SettingsPage::url( 'fl-title-updates' ) );
		exit;
	}

	/**
	 * Libère une place : celle de ce site ('') ou celle d'une autre adresse. Seule l'empreinte part,
	 * jamais l'adresse. La clé de ce site est retirée après succès, ou quand l'API ne répond pas.
	 *
	 * @param string $address Adresse d'un autre site, ou '' pour ce site.
	 * @return array{status: string, message: string}
	 */
	public static function release_seat( string $address ): array {
		$licence = self::licence();
		if ( '' === $licence ) {
			return array(
				'status'  => 'bad',
				'message' => self::blocker_message( 'licence_invalid' ),
			);
		}
		$this_site = '' === $address;
		if ( $this_site ) {
			$site = Api::site_hash();
		} else {
			$parts = wp_parse_url( $address );
			if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || '' === (string) ( $parts['host'] ?? '' ) ) {
				return self::invalid_address();
			}
			$site = hash( 'sha256', Api::site_identity( $address ) );
		}
		$response = Api::post( '/v1/licences/release', array( 'site' => $site ), $licence );
		$failed   = is_wp_error( $response ) || $response['code'] >= 500 || 0 === $response['code'];
		if ( $failed ) {
			if ( $this_site && ! self::licence_from_constant() ) {
				self::forget_licence();
				return array(
					'status'  => 'warn',
					'message' => __( "The seat couldn't be freed: the update server didn't answer. The key was removed anyway; try again later from another site, or write to me.", 'fluffless-suite' ),
				);
			}
			return array(
				'status'  => 'warn',
				'message' => __( "The seat couldn't be freed: the update server didn't answer. Try again later, or write to me.", 'fluffless-suite' ),
			);
		}
		if ( self::release_unavailable( $response ) ) {
			return array(
				'status'  => 'warn',
				'message' => self::release_unavailable_message(),
			);
		}
		$body = is_array( $response['body'] ) ? $response['body'] : array();
		if ( 429 === $response['code'] && 'release_limit' === ( $body['code'] ?? '' ) ) {
			$retry = self::timestamp( $body['retry_at'] ?? null ) ?? time() + DAY_IN_SECONDS;
			$max   = (int) ( self::licence_details()['sites_max'] ?? 1 );
			return array(
				'status'  => 'warn',
				'message' => sprintf(
					/* translators: 1: number of seats freed, 2: date */
					__( "You've freed %1\$d seats in the last 30 days. You can free another one from %2\$s, or write to me.", 'fluffless-suite' ),
					min( 50, max( 3, $max ) ),
					wp_date( (string) get_option( 'date_format' ), $retry )
				),
			);
		}
		if ( 200 !== $response['code'] ) {
			$code = is_string( $body['code'] ?? null ) ? $body['code'] : 'licence_invalid';
			return array(
				'status'  => 'bad',
				'message' => self::blocker_message( in_array( $code, self::LICENCE_STATES, true ) ? $code : 'licence_invalid' ),
			);
		}
		if ( ! $this_site ) {
			return true === ( $body['released'] ?? null )
				? array(
					'status'  => 'good',
					'message' => __( 'The seat of that address is free.', 'fluffless-suite' ),
				)
				: array(
					'status'  => 'warn',
					'message' => __( "That address doesn't use a seat on this licence.", 'fluffless-suite' ),
				);
		}
		if ( self::licence_from_constant() ) {
			delete_transient( Lifecycle::TRANSIENT_UPDATES );
			delete_transient( Lifecycle::TRANSIENT_LICENCE );
			return array(
				'status'  => 'warn',
				'message' => __( 'The seat is free. Remove FLUFFLESS_LICENCE_KEY from wp-config.php too, or the next check takes it back.', 'fluffless-suite' ),
			);
		}
		self::forget_licence();
		return array(
			'status'  => 'good',
			'message' => __( 'This site no longer uses a seat on your licence. Updates stop here until you add a key again.', 'fluffless-suite' ),
		);
	}

	/**
	 * Adresse refusée par le champ.
	 *
	 * @return array{status: string, message: string}
	 */
	private static function invalid_address(): array {
		return array(
			'status'  => 'bad',
			'message' => __( 'Enter a full address, starting with https://.', 'fluffless-suite' ),
		);
	}

	/** Retire la clé enregistrée sans la revérifier (delete_option ne passe pas par licence_changed). */
	private static function forget_licence(): void {
		delete_option( Lifecycle::OPTION_LICENCE );
		delete_transient( Lifecycle::TRANSIENT_UPDATES );
		delete_transient( Lifecycle::TRANSIENT_LICENCE );
	}

	/** Champ de licence et mention Loi 25, dans la carte du module. La clé n'est jamais réaffichée. */
	public static function render_licence(): void {
		$masked = self::masked();
		echo '<div class="fl-field"><label class="fl-field__label" for="fluffless-licence-key">' . esc_html__( 'Licence key', 'fluffless-suite' ) . '</label><div>';
		if ( self::licence_from_constant() ) {
			/* translators: %s: last characters of the licence key, e.g. ••••A1B2 */
			echo '<p>' . esc_html( sprintf( __( 'Set in wp-config.php (%s).', 'fluffless-suite' ), $masked ) ) . '</p>';
		} else {
			printf(
				'<input type="password" class="regular-text" id="fluffless-licence-key" name="%1$s[key]" value="" autocomplete="off" spellcheck="false" placeholder="%2$s" aria-describedby="fluffless-licence-help">',
				esc_attr( Lifecycle::OPTION_LICENCE ),
				esc_attr( $masked )
			);
			if ( '' !== $masked ) {
				printf( '<br><label><input type="checkbox" name="%s[remove]" value="1"> %s</label>', esc_attr( Lifecycle::OPTION_LICENCE ), esc_html__( 'Remove the saved key', 'fluffless-suite' ) );
			}
		}
		$usage = self::usage_notice();
		if ( '' !== $usage ) {
			echo '<p>' . esc_html( $usage ) . '</p>';
		}
		if ( self::licence_unverified() ) {
			echo Screen::note( 'warn', __( "The licence server isn't answering right now. Your key is saved and will be checked later. Your site keeps working.", 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		$renew = self::renew_link();
		if ( '' !== $renew ) {
			echo '<p>' . esc_html( self::status() ) . ' ' . $renew . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans renew_link().
		}
		if ( Api::current_site_is_dev() ) {
			echo Screen::pill( 'good', __( "Development site: it doesn't use a seat on your licence.", 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::pill().
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( ! Api::is_dev_host( is_string( $host ) ? $host : '' ) ) {
				echo '<p class="fl-muted">' . esc_html__( "This site is set as staging in WordPress (WP_ENVIRONMENT_TYPE): it doesn't use a seat on your licence.", 'fluffless-suite' ) . '</p>';
			}
		}
		echo '<p class="description" id="fluffless-licence-help">' . esc_html__( 'Leave empty to keep the saved key. You can also define FLUFFLESS_LICENCE_KEY in wp-config.php, which takes priority.', 'fluffless-suite' ) . '</p>';
		self::render_release_controls();
		if ( 'valid' === self::licence_state() ) {
			echo Screen::pill( 'good', __( 'Licence active', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::pill().
			$seats = self::seats_line();
			if ( '' !== $seats ) {
				echo '<p>' . esc_html( $seats ) . '</p>';
			}
		}
		echo '</div></div>';
		echo '<p class="fl-muted">' . esc_html__( 'Privacy (Quebec Law 25): to check for updates, your site sends a one-way hash of its address, your licence key, the installed Fluffless versions and whether it is a development site. Nothing about your visitors or your content.', 'fluffless-suite' ) . '</p>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function hosts(): array {
		return array(
			array(
				'host'    => Api::host(),
				'service' => __( 'Fluffless API: updates and licence', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'cron',
				'data'    => __( 'Plugin slug, version, update channel, a fingerprint of the site address and whether it is a development site; the licence key in a header', 'fluffless-suite' ),
			),
		);
	}
}
