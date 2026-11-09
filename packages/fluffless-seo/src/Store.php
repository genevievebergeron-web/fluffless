<?php
/**
 * Tables de l'extension : règles de redirection et journal des adresses introuvables.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Deux tables plutôt que des options : une règle ou une adresse introuvable par ligne,
 * cherchée par empreinte indexée, jamais chargée en entier en mémoire.
 *
 * Le journal ne garde que le chemin (sans chaîne de requête), un compteur et la date du
 * dernier passage : aucune adresse IP, aucun agent utilisateur, aucun référent (Loi 25).
 */
final class Store {

	public const CRON = 'fluffless_seo_purge';

	/** Version du schéma des tables. */
	public const DB_VERSION = 1;

	/** Codes de réponse acceptés. */
	public const CODES = array( 301, 302, 307, 410 );

	/** Jours de conservation du journal. */
	public const KEEP_DAYS = 90;

	/** Lignes supprimées au plus par purge (tâche quotidienne). */
	private const PURGE_BATCH = 2000;

	/** Lignes gardées au plus dans le journal des adresses introuvables. */
	public const MAX_LOG = 5000;

	/** Lignes lues au plus dans un fichier CSV. */
	public const MAX_CSV_LINES = 5000;

	/** Règles regex examinées au plus par requête. */
	public const MAX_REGEX = 200;

	/** Longueur maximale d'un chemin enregistré. */
	private const MAX_PATH = 255;

	/** Table des règles. */
	public static function redirects_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fluffless_seo_redirects';
	}

	/** Table du journal. */
	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fluffless_seo_404';
	}

	/** Crée ou met à jour les tables. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::redirects_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(255) NOT NULL,
			source_hash char(32) NOT NULL,
			target varchar(2048) NOT NULL DEFAULT '',
			code smallint(3) unsigned NOT NULL DEFAULT 301,
			is_regex tinyint(1) unsigned NOT NULL DEFAULT 0,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime NULL DEFAULT NULL,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_hash (source_hash),
			KEY is_regex (is_regex)
		) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::log_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(255) NOT NULL,
			url_hash char(32) NOT NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 1,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY last_seen (last_seen)
		) $charset;"
		);
		Settings::update( array( 'db' => self::DB_VERSION ) );
	}

	/** Tables absentes ou anciennes (mise à jour sans réactivation) : on les crée. */
	public static function maybe_upgrade(): void {
		if ( Settings::int( 'db' ) < self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Chemin normalisé : sans schéma, hôte, requête ni fragment ; décodé ; sans barre finale.
	 *
	 * @param string $url Adresse ou chemin.
	 */
	public static function normalize_path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = '/' . ltrim( rawurldecode( is_string( $path ) ? $path : '' ), '/' );
		$path = '/' === $path ? $path : untrailingslashit( $path );
		return mb_substr( $path, 0, self::MAX_PATH );
	}

	/**
	 * Ajoute ou remplace une règle (même source).
	 *
	 * @param string $source   Chemin, ou motif regex sans délimiteurs.
	 * @param string $target   Chemin du site ou adresse http(s) ; vide pour 410.
	 * @param int    $code     301, 302, 307 ou 410.
	 * @param bool   $is_regex La source est un motif.
	 * @throws \InvalidArgumentException Règle invalide, avec la valeur reçue.
	 */
	public static function add_redirect( string $source, string $target, int $code = 301, bool $is_regex = false ): int {
		global $wpdb;
		if ( ! in_array( $code, self::CODES, true ) ) {
			/* translators: %d: HTTP status code received. */
			throw new \InvalidArgumentException( sprintf( __( 'Status code %d is not supported. Use 301, 302, 307 or 410.', 'fluffless-seo' ), $code ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- texte brut, échappé à l'affichage (Screen::note, WP_CLI).
		}
		$source = $is_regex ? self::check_pattern( $source ) : self::normalize_path( trim( $source ) );
		$target = 410 === $code ? '' : self::check_target( $target );
		$table  = self::redirects_table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table de l'extension, sans cache objet pertinent.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table interne.
				"INSERT INTO {$table} (source, source_hash, target, code, is_regex, created) VALUES (%s, %s, %s, %d, %d, %s)
				ON DUPLICATE KEY UPDATE target = VALUES(target), code = VALUES(code), is_regex = VALUES(is_regex)",
				$source,
				self::hash( $source, $is_regex ),
				$target,
				$code,
				$is_regex ? 1 : 0,
				current_time( 'mysql', true )
			)
		);
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_hash = %s", self::hash( $source, $is_regex ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
		return (int) $id;
	}

	/**
	 * Empreinte d'une source ; le préfixe sépare un chemin d'un motif identique.
	 *
	 * @param string $source   Source normalisée.
	 * @param bool   $is_regex Motif.
	 */
	private static function hash( string $source, bool $is_regex ): string {
		return md5( ( $is_regex ? 're:' : 'path:' ) . $source );
	}

	/**
	 * Motif regex compilable, borné.
	 *
	 * @param string $pattern Motif sans délimiteurs.
	 * @throws \InvalidArgumentException Motif vide, trop long ou invalide.
	 */
	private static function check_pattern( string $pattern ): string {
		$pattern = trim( $pattern );
		if ( '' === $pattern || mb_strlen( $pattern ) > self::MAX_PATH ) {
			throw new \InvalidArgumentException( __( 'The pattern must hold 1 to 255 characters.', 'fluffless-seo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- texte brut, échappé à l'affichage (Screen::note, WP_CLI).
		}
		// Le motif est compilé une fois pour rejeter une erreur de syntaxe avant l'enregistrement.
		if ( false === @preg_match( self::delimit( $pattern ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- l'avertissement est remplacé par un message clair.
			/* translators: %s: regular expression received. */
			throw new \InvalidArgumentException( sprintf( __( 'The pattern %s is not a valid regular expression.', 'fluffless-seo' ), $pattern ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- texte brut, échappé à l'affichage (Screen::note, WP_CLI).
		}
		return $pattern;
	}

	/**
	 * Motif avec délimiteurs et drapeau Unicode.
	 *
	 * @param string $pattern Motif enregistré.
	 */
	public static function delimit( string $pattern ): string {
		return '#' . str_replace( '#', '\#', $pattern ) . '#u';
	}

	/**
	 * Cible valide : chemin du site (commence par une seule barre) ou adresse http(s).
	 *
	 * @param string $target Cible reçue.
	 * @throws \InvalidArgumentException Cible vide ou invalide.
	 */
	private static function check_target( string $target ): string {
		$target = trim( $target );
		if ( 1 === preg_match( '#^/(?![/\\\\])#', $target ) ) {
			return mb_substr( $target, 0, 2048 );
		}
		$url = esc_url_raw( $target, array( 'http', 'https' ) );
		if ( '' === $target || '' === $url || false === wp_http_validate_url( $url ) ) {
			/* translators: %s: target received. */
			throw new \InvalidArgumentException( sprintf( __( 'The target %s must be a path starting with / or an http(s) address.', 'fluffless-seo' ), $target ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- texte brut, échappé à l'affichage (Screen::note, WP_CLI).
		}
		return $url;
	}

	/**
	 * Règle qui s'applique à un chemin : correspondance exacte d'abord, puis motifs dans l'ordre d'ajout.
	 * Une seule requête, bornée à MAX_REGEX motifs.
	 *
	 * @param string $path Chemin normalisé.
	 * @return array{id: int, target: string, code: int, is_regex: bool, source: string}|null
	 */
	public static function match( string $path ): ?array {
		global $wpdb;
		$table = self::redirects_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- une requête bornée par page servie, voir Redirects.
			$wpdb->prepare(
				"SELECT id, source, target, code, is_regex FROM {$table} WHERE source_hash = %s OR is_regex = 1 ORDER BY is_regex ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
				self::hash( $path, false ),
				self::MAX_REGEX + 1
			),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$rule = array(
				'id'       => (int) $row['id'],
				'source'   => (string) $row['source'],
				'target'   => (string) $row['target'],
				'code'     => (int) $row['code'],
				'is_regex' => '1' === (string) $row['is_regex'],
			);
			if ( ! $rule['is_regex'] ) {
				return $rule;
			}
			$target = self::apply_pattern( $rule['source'], $rule['target'], $path );
			if ( null !== $target ) {
				return array( 'target' => $target ) + $rule;
			}
		}
		return null;
	}

	/**
	 * Cible d'un motif pour un chemin, avec $1, $2... remplacés ; null si le motif ne correspond pas
	 * ou si le remplacement ferait sortir la cible de son hôte (redirection ouverte).
	 *
	 * @param string $pattern Motif enregistré.
	 * @param string $target  Cible, avec références éventuelles.
	 * @param string $path    Chemin demandé.
	 */
	public static function apply_pattern( string $pattern, string $target, string $path ): ?string {
		$groups = array();
		if ( 1 !== @preg_match( self::delimit( $pattern ), $path, $groups ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- motif validé à l'ajout ; un échec vaut non-correspondance.
			return null;
		}
		$result = (string) preg_replace_callback(
			'/\$(\d{1,2})/',
			// Groupe encodé (barres gardées) : un groupe capturé ne peut pas injecter « ? », « # » ni un hôte.
			static fn( array $ref ): string => str_replace( '%2F', '/', rawurlencode( (string) ( $groups[ (int) $ref[1] ] ?? '' ) ) ),
			$target
		);
		// La partie fixe avant la première référence doit rester intacte, et un chemin ne peut pas devenir « //hôte ».
		$fixed = strstr( $target, '$', true );
		$fixed = false === $fixed ? $target : $fixed;
		if ( ! str_starts_with( $result, $fixed ) || 1 === preg_match( '#^/[/\\\\]#', $result ) ) {
			return null;
		}
		return $result;
	}

	/**
	 * Compte un passage sur une règle.
	 *
	 * @param int $id Règle.
	 */
	public static function hit( int $id ): void {
		global $wpdb;
		$table = self::redirects_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d", current_time( 'mysql', true ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- compteur, table interne.
	}

	/**
	 * Supprime une règle.
	 *
	 * @param int $id Règle.
	 */
	public static function delete_redirect( int $id ): bool {
		global $wpdb;
		return 1 === $wpdb->delete( self::redirects_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table interne.
	}

	/**
	 * Règles, les plus récentes d'abord.
	 *
	 * @param int $limit  Nombre de lignes (1 à 500).
	 * @param int $offset Décalage.
	 * @return list<array{id: int, source: string, target: string, code: int, is_regex: bool, hits: int, last_hit: string}>
	 */
	public static function redirects( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$table = self::redirects_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, source, target, code, is_regex, hits, last_hit FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", max( 1, min( 500, $limit ) ), max( 0, $offset ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
		return array_map(
			static fn( array $row ): array => array(
				'id'       => (int) $row['id'],
				'source'   => (string) $row['source'],
				'target'   => (string) $row['target'],
				'code'     => (int) $row['code'],
				'is_regex' => '1' === (string) $row['is_regex'],
				'hits'     => (int) $row['hits'],
				'last_hit' => (string) ( $row['last_hit'] ?? '' ),
			),
			is_array( $rows ) ? array_values( $rows ) : array()
		);
	}

	/** Nombre de règles enregistrées (0 si la table manque). */
	public static function rule_count(): int {
		global $wpdb;
		$table = self::redirects_table();
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- contrôle d'existence.
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
	}

	/**
	 * Importe des règles depuis un CSV : source,cible,code,regex (en-tête facultatif).
	 *
	 * @param string $file Chemin du fichier.
	 * @return array{added: int, errors: list<string>}
	 * @throws \RuntimeException Fichier illisible.
	 */
	public static function import_csv( string $file ): array {
		$handle = is_readable( $file ) ? fopen( $file, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lecture en flux d'un envoi local.
		if ( false === $handle ) {
			/* translators: %s: file path. */
			throw new \RuntimeException( sprintf( __( 'Cannot read %s.', 'fluffless-seo' ), $file ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- texte brut, échappé à l'affichage (Screen::note, WP_CLI).
		}
		$added  = 0;
		$errors = array();
		try {
			for ( $line = 1; $line <= self::MAX_CSV_LINES; $line++ ) {
				$row = fgetcsv( $handle, 4096, ',', '"', '' );
				if ( false === $row ) {
					break;
				}
				$cells = array_map( static fn( $cell ): string => trim( (string) $cell ), $row );
				if ( '' === $cells[0] || ( 1 === $line && 'source' === strtolower( $cells[0] ) ) ) {
					continue;
				}
				try {
					self::add_redirect( $cells[0], $cells[1] ?? '', (int) ( $cells[2] ?? 301 ) > 0 ? (int) ( $cells[2] ?? 301 ) : 301, in_array( strtolower( $cells[3] ?? '' ), array( '1', 'yes', 'true', 'regex' ), true ) );
					++$added;
				} catch ( \InvalidArgumentException $error ) {
					if ( count( $errors ) < 50 ) {
						/* translators: 1: CSV line number, 2: error message. */
						$errors[] = sprintf( __( 'Line %1$d: %2$s', 'fluffless-seo' ), $line, $error->getMessage() );
					}
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pendant de fopen.
		}
		return array(
			'added'  => $added,
			'errors' => $errors,
		);
	}

	/**
	 * Note une adresse introuvable : chemin seulement, compteur, date du dernier passage.
	 *
	 * @param string $path Chemin normalisé.
	 */
	public static function log_404( string $path ): void {
		global $wpdb;
		$table = self::log_table();
		/**
		 * Lignes gardées au plus dans le journal des adresses introuvables.
		 *
		 * @param int $max Plafond (5000 par défaut).
		 */
		$max   = max( 10, (int) apply_filters( 'fluffless_seo_404_max_rows', self::MAX_LOG ) );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne, une lecture par page introuvable.
		if ( $count >= $max ) {
			// Journal plein (rempli par des visiteurs anonymes, par exemple) : les adresses les moins vues
			// cèdent leur place par lot d'un dixième, pour que les nouvelles restent visibles.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} ORDER BY hits ASC, last_seen ASC LIMIT %d", $count - $max + max( 1, intdiv( $max, 10 ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- une écriture par page introuvable.
			$wpdb->prepare(
				"INSERT INTO {$table} (url, url_hash, hits, last_seen) VALUES (%s, %s, 1, %s) ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
				$path,
				md5( $path ),
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Adresses introuvables, les plus fréquentes d'abord.
	 *
	 * @param int $limit Nombre de lignes (1 à 500).
	 * @return list<array{url: string, hits: int, last_seen: string}>
	 */
	public static function not_found( int $limit = 100 ): array {
		global $wpdb;
		$table = self::log_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT url, hits, last_seen FROM {$table} ORDER BY hits DESC, last_seen DESC LIMIT %d", max( 1, min( 500, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
		return array_map(
			static fn( array $row ): array => array(
				'url'       => (string) $row['url'],
				'hits'      => (int) $row['hits'],
				'last_seen' => (string) $row['last_seen'],
			),
			is_array( $rows ) ? array_values( $rows ) : array()
		);
	}

	/**
	 * Oublie une adresse du journal (une règle vient de la couvrir, par exemple).
	 *
	 * @param string $path Chemin normalisé.
	 */
	public static function forget_404( string $path ): void {
		global $wpdb;
		$wpdb->delete( self::log_table(), array( 'url_hash' => md5( $path ) ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table interne.
	}

	/**
	 * Purge du journal : entrées vues il y a plus de 90 jours (ou toutes), par lot borné.
	 *
	 * @param bool $all Vide tout le journal.
	 * @return int Lignes supprimées.
	 */
	public static function purge( bool $all = false ): int {
		global $wpdb;
		$table = self::log_table();
		if ( $all ) {
			return (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne, aucune donnée externe.
		}
		$limit = gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s LIMIT %d", $limit, self::PURGE_BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
	}

	/** Supprime les deux tables (désinstallation). */
	public static function drop(): void {
		global $wpdb;
		foreach ( array( self::redirects_table(), self::log_table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- désinstallation.
		}
	}
}
