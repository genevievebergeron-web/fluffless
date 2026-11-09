<?php
/**
 * Export et import des réglages de la Suite : un fichier JSON pour rejouer la configuration sur un autre site.
 *
 * Aucune sauvegarde du site ici (contenu, médias, base) : c'est le travail de l'hébergeur.
 * L'option fluffless_settings voyage, réassainie à l'import comme si elle venait du formulaire, avec
 * les gabarits de pages enregistrés par l'agence (Editor\Templates), réassainis de même.
 *
 * @package Fluffless\Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Admin;

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Editor\Templates;
use WP_Error;

use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Section « Exporter et importer les réglages » sous l'écran Modules, et ses deux actions admin-post.
 */
final class Transfer {

	public const ACTION_EXPORT  = 'fluffless_settings_export';
	public const ACTION_IMPORT  = 'fluffless_settings_import';
	public const ACTION_CONFIRM = 'fluffless_settings_confirm';
	public const ACTION_UNDO    = 'fluffless_settings_undo';

	/** Option (hors chargement automatique) qui garde l'état d'avant le dernier import, pour l'annuler. */
	public const PREVIOUS = 'fluffless_transfer_previous';

	/** Modules qu'un fichier importé n'active jamais sans case cochée à l'écran de confirmation. */
	public const SENSITIVE = array( 'security' );

	/** Champs qui ne voyagent pas : ni exportés, ni importés (module => champs). */
	public const SECRET_FIELDS = array( 'security' => array( 'login_slug' ) );

	/** Durée de garde du fichier en attente de confirmation. */
	public const STAGE_TTL = HOUR_IN_SECONDS;

	/** Durée pendant laquelle l'état d'avant un import peut être rétabli, puis il est effacé. */
	public const UNDO_TTL = 7 * DAY_IN_SECONDS;

	/** Taille maximale du fichier importé (réglages de quelques ko, plus les gabarits de pages, 100 ko chacun au plus). */
	public const MAX_BYTES = 2097152;

	/** Branche les deux actions (administration seulement). */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( self::class, 'export' ) );
		add_action( 'admin_post_' . self::ACTION_IMPORT, array( self::class, 'import' ) );
		add_action( 'admin_post_' . self::ACTION_CONFIRM, array( self::class, 'confirm' ) );
		add_action( 'admin_post_' . self::ACTION_UNDO, array( self::class, 'undo_action' ) );
	}

	/**
	 * Contenu du fichier exporté.
	 *
	 * @return array{fluffless_suite: string, schema: int, exported: string, site: string, settings: array<string, mixed>, page_templates: list<array{id: string, title: string, content: string}>}
	 */
	public static function payload(): array {
		return array(
			'fluffless_suite' => VERSION,
			'schema'          => Options::SCHEMA,
			'exported'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'site'            => home_url(),
			'settings'        => self::without_secrets( Options::all() ),
			'page_templates'  => Templates::all(),
		);
	}

	/**
	 * Vide les champs secrets (adresse de connexion cachée) d'un jeu de réglages.
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @return array<string, mixed>
	 */
	public static function without_secrets( array $settings ): array {
		foreach ( self::SECRET_FIELDS as $module => $fields ) {
			if ( is_array( $settings[ $module ] ?? null ) ) {
				foreach ( $fields as $field ) {
					if ( array_key_exists( $field, $settings[ $module ] ) ) {
						$settings[ $module ][ $field ] = '';
					}
				}
			}
		}
		return $settings;
	}

	/** Nom du fichier : fluffless-settings-<hôte>-<date>.json. */
	public static function filename(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = sanitize_title( $host );
		return 'fluffless-settings-' . ( '' === $host ? 'site' : $host ) . '-' . gmdate( 'Y-m-d' ) . '.json';
	}

	/**
	 * Valide un fichier décodé.
	 *
	 * @param mixed $data Fichier décodé.
	 * @return true|WP_Error
	 */
	private static function check( $data ) {
		if ( ! is_array( $data ) || ! is_array( $data['settings'] ?? null ) || ! isset( $data['fluffless_suite'] ) ) {
			return new WP_Error( 'format', __( 'This file is not a Fluffless Suite settings export.', 'fluffless-suite' ) );
		}
		if ( (int) ( $data['schema'] ?? 0 ) > Options::SCHEMA ) {
			return new WP_Error( 'schema', __( 'This file comes from a newer version of the suite. Update the suite first.', 'fluffless-suite' ) );
		}
		return true;
	}

	/**
	 * Réglages qui seraient écrits : même assainissement que le formulaire, adresse de connexion
	 * jamais reprise du fichier, modules sensibles inchangés tant qu'ils ne sont pas confirmés.
	 *
	 * @param array<string, mixed> $data      Fichier décodé et validé.
	 * @param array<int, string>   $confirmed Modules sensibles confirmés par l'administratrice.
	 * @return array<string, mixed>
	 */
	private static function prepared( array $data, array $confirmed ): array {
		$current  = Options::all();
		$incoming = (array) $data['settings'];
		foreach ( self::SECRET_FIELDS as $module => $fields ) {
			$section = is_array( $incoming[ $module ] ?? null ) ? $incoming[ $module ] : array();
			foreach ( $fields as $field ) {
				$section[ $field ] = $current[ $module ][ $field ] ?? '';
			}
			$incoming[ $module ] = $section;
		}
		$settings = Options::sanitize( $incoming );
		foreach ( self::SENSITIVE as $module ) {
			if ( ! in_array( $module, $confirmed, true ) ) {
				$settings['modules'][ $module ] = $current['modules'][ $module ] ?? false;
				if ( isset( $current[ $module ] ) ) {
					$settings[ $module ] = $current[ $module ];
				}
			}
		}
		return $settings;
	}

	/**
	 * Aperçu d'un import, sans rien écrire. Il se calcule avec prepared(), la fonction même qu'apply()
	 * exécute, et les mêmes modules confirmés : ce qui est annoncé est ce qui sera écrit.
	 *
	 * - enable, disable, sections : l'effet réel de l'import avec $confirmed (aucun module sensible par défaut).
	 * - sensitive : modules sensibles que le fichier voudrait activer, confirmés ou non.
	 * - held : parmi eux, ceux qui restent tels quels faute de confirmation.
	 * - templates : nombre de gabarits qui seraient gardés, après assainissement (pas le nombre brut du fichier).
	 *
	 * @param mixed              $data      Fichier décodé.
	 * @param array<int, string> $confirmed Modules sensibles confirmés par l'administratrice.
	 * @return array{enable: list<string>, disable: list<string>, sensitive: list<string>, held: list<string>, sections: list<string>, templates: int|null, exported: string|null, site: string|null}|WP_Error
	 */
	public static function plan( $data, array $confirmed = array() ) {
		$ok = self::check( $data );
		if ( true !== $ok ) {
			return $ok;
		}
		assert( is_array( $data ) );
		$current   = Options::all();
		$asked     = self::prepared( $data, self::SENSITIVE );
		$next      = self::prepared( $data, $confirmed );
		$enable    = array();
		$disable   = array();
		$sensitive = array();
		foreach ( (array) $next['modules'] as $id => $on ) {
			$was = (bool) ( $current['modules'][ $id ] ?? false );
			if ( $on && ! $was ) {
				$enable[] = (string) $id;
			} elseif ( ! $on && $was ) {
				$disable[] = (string) $id;
			}
		}
		foreach ( self::SENSITIVE as $id ) {
			if ( ! empty( $asked['modules'][ $id ] ) && empty( $current['modules'][ $id ] ) ) {
				$sensitive[] = $id;
			}
		}
		$stored   = get_option( Options::NAME, array() );
		$sections = array();
		foreach ( $next as $id => $value ) {
			// Une section d'une autre extension (Options::FOREIGN) n'est pas dans Options::all() : on la compare à l'option stockée, que sanitize() reprend telle quelle.
			$before = array_key_exists( $id, $current ) ? $current[ $id ] : ( is_array( $stored ) ? ( $stored[ $id ] ?? null ) : null );
			if ( 'modules' !== $id && 'version' !== $id && ! self::same( $before, $value ) ) {
				$sections[] = (string) $id;
			}
		}
		return array(
			'enable'    => $enable,
			'disable'   => $disable,
			'sensitive' => $sensitive,
			'held'      => array_values( array_diff( $sensitive, $confirmed ) ),
			'sections'  => $sections,
			'templates' => array_key_exists( 'page_templates', $data ) ? count( Templates::sanitize( $data['page_templates'] ) ) : null,
			'exported'  => is_string( $data['exported'] ?? null ) ? $data['exported'] : null,
			'site'      => is_string( $data['site'] ?? null ) ? $data['site'] : null,
		);
	}

	/**
	 * Deux valeurs de réglages sont-elles les mêmes ? L'ordre des clés ne compte pas : un tableau relu de la
	 * base et le même tableau assaini peuvent ne pas les ranger pareil sans que rien ait changé.
	 *
	 * @param mixed $a Première valeur.
	 * @param mixed $b Seconde valeur.
	 */
	private static function same( $a, $b ): bool {
		if ( is_array( $a ) && is_array( $b ) ) {
			ksort( $a );
			ksort( $b );
			if ( array_keys( $a ) !== array_keys( $b ) ) {
				return false;
			}
			foreach ( $a as $key => $value ) {
				if ( ! self::same( $value, $b[ $key ] ) ) {
					return false;
				}
			}
			return true;
		}
		return $a === $b;
	}

	/**
	 * Textes de l'aperçu, en phrases simples : le même jeu pour l'écran Modules et pour WP-CLI.
	 *
	 * @param array{enable: list<string>, disable: list<string>, sensitive: list<string>, held: list<string>, sections: list<string>, templates: int|null, exported: string|null, site: string|null} $plan Aperçu.
	 * @return list<string>
	 */
	public static function preview_lines( array $plan ): array {
		$modules = Loader::modules();
		$label   = static function ( string $id ) use ( $modules ): string {
			return isset( $modules[ $id ] ) ? $modules[ $id ]::label() : $id;
		};
		$lines   = array();
		if ( null !== $plan['exported'] ) {
			$time    = strtotime( $plan['exported'] );
			$lines[] = sprintf(
				/* translators: 1: date of the export file, 2: address of the site it comes from. */
				__( 'File exported on %1$s from %2$s.', 'fluffless-suite' ),
				false === $time ? $plan['exported'] : wp_date( (string) get_option( 'date_format' ), $time ),
				null === $plan['site'] ? '?' : $plan['site']
			);
		}
		$lines[] = __( 'The settings in the file replace the current ones. Modules and settings the file leaves out stay as they are.', 'fluffless-suite' );
		if ( array() !== $plan['enable'] ) {
			/* translators: %s: comma-separated module names. */
			$lines[] = sprintf( __( 'Will be turned on: %s.', 'fluffless-suite' ), implode( ', ', array_map( $label, $plan['enable'] ) ) );
		}
		if ( array() !== $plan['disable'] ) {
			/* translators: %s: comma-separated module names. */
			$lines[] = sprintf( __( 'Will be turned off: %s.', 'fluffless-suite' ), implode( ', ', array_map( $label, $plan['disable'] ) ) );
		}
		if ( array() !== $plan['held'] ) {
			/* translators: %s: comma-separated module names. */
			$lines[] = sprintf( __( 'Will stay as it is unless you confirm it: %s.', 'fluffless-suite' ), implode( ', ', array_map( $label, $plan['held'] ) ) );
		}
		if ( array() !== $plan['sections'] ) {
			/* translators: %s: comma-separated names of the settings sections that change. */
			$lines[] = sprintf( __( 'Settings that will change: %s.', 'fluffless-suite' ), implode( ', ', array_map( $label, $plan['sections'] ) ) );
		}
		if ( null !== $plan['templates'] ) {
			$lines[] = sprintf(
				/* translators: %d: number of page templates kept from the file. */
				_n( 'The saved page templates will be replaced by the %d template in the file.', 'The saved page templates will be replaced by the %d templates in the file.', $plan['templates'], 'fluffless-suite' ),
				$plan['templates']
			);
		}
		$days    = (int) ( self::UNDO_TTL / DAY_IN_SECONDS );
		$lines[] = sprintf(
			/* translators: %d: number of days. */
			_n( 'The earlier state is kept for %d day so you can restore it after the import.', 'The earlier state is kept for %d days so you can restore it after the import.', $days, 'fluffless-suite' ),
			$days
		);
		return $lines;
	}

	/**
	 * Applique un fichier décodé après avoir gardé l'état précédent.
	 *
	 * @param mixed              $data      Fichier décodé.
	 * @param array<int, string> $confirmed Modules sensibles que l'administratrice a confirmés.
	 * @return true|WP_Error
	 */
	public static function apply( $data, array $confirmed = array() ) {
		$ok = self::check( $data );
		if ( true !== $ok ) {
			return $ok;
		}
		assert( is_array( $data ) );
		$settings = self::prepared( $data, $confirmed );
		update_option(
			self::PREVIOUS,
			array(
				'at'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'settings'  => get_option( Options::NAME, array() ),
				'templates' => Templates::all(),
			),
			false
		);
		update_option( Options::NAME, $settings );
		Options::flush();
		if ( array_key_exists( 'page_templates', $data ) ) {
			Templates::store( Templates::sanitize( $data['page_templates'] ) );
		}
		return true;
	}

	/**
	 * État gardé avant le dernier import, tant qu'il n'a pas expiré (UNDO_TTL) : passé ce délai, ou sans
	 * date lisible, il est effacé et il n'y a plus rien à rétablir.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function previous(): ?array {
		$previous = get_option( self::PREVIOUS, false );
		if ( ! is_array( $previous ) || ! array_key_exists( 'settings', $previous ) ) {
			return null;
		}
		$at = strtotime( (string) ( $previous['at'] ?? '' ) );
		if ( false === $at || $at < time() - self::UNDO_TTL ) {
			delete_option( self::PREVIOUS );
			return null;
		}
		return $previous;
	}

	/**
	 * Rétablit l'état d'avant le dernier import.
	 *
	 * @return true|WP_Error
	 */
	public static function undo() {
		$previous = self::previous();
		if ( null === $previous ) {
			return new WP_Error( 'nothing', __( 'There is no earlier state to restore.', 'fluffless-suite' ) );
		}
		update_option( Options::NAME, is_array( $previous['settings'] ) ? $previous['settings'] : array() );
		Options::flush();
		Templates::store( Templates::sanitize( $previous['templates'] ?? array() ) );
		delete_option( self::PREVIOUS );
		return true;
	}

	/** Téléchargement du JSON (admin-post). */
	public static function export(): void {
		check_admin_referer( self::ACTION_EXPORT );
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to export these settings.', 'fluffless-suite' ), '', array( 'response' => 403 ) );
		}
		$json = wp_json_encode( self::payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::filename() . '"' );
		echo false === $json ? '{}' : $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON téléchargé, pas de HTML.
		exit;
	}

	/** Lecture du fichier téléversé, puis retour à l'écran Modules avec l'aperçu à confirmer (admin-post). */
	public static function import(): void {
		check_admin_referer( self::ACTION_IMPORT );
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import settings.', 'fluffless-suite' ), '', array( 'response' => 403 ) );
		}
		$result = self::read_upload( $_FILES['fluffless_settings_file'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- lu et validé dans read_upload().
		$url    = SettingsPage::url( 'fl-transfer' );
		$url    = true === $result ? add_query_arg( 'fl_confirm', '1', $url ) : add_query_arg( 'fl_import_error', $result->get_error_code(), $url );
		wp_safe_redirect( $url );
		exit;
	}

	/** Application (ou abandon) du fichier en attente, une fois l'aperçu lu (admin-post). */
	public static function confirm(): void {
		check_admin_referer( self::ACTION_CONFIRM );
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import settings.', 'fluffless-suite' ), '', array( 'response' => 403 ) );
		}
		$url = SettingsPage::url( 'fl-transfer' );
		if ( isset( $_POST['fl_cancel'] ) ) {
			delete_transient( self::stage_key() );
			wp_safe_redirect( add_query_arg( 'fl_cancelled', '1', $url ) );
			exit;
		}
		$confirmed = array();
		$posted    = isset( $_POST['fl_confirm_module'] ) && is_array( $_POST['fl_confirm_module'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['fl_confirm_module'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- liste d'identifiants assainie par sanitize_key.
		foreach ( $posted as $module ) {
			if ( in_array( $module, self::SENSITIVE, true ) ) {
				$confirmed[] = $module;
			}
		}
		$raw = get_transient( self::stage_key() );
		delete_transient( self::stage_key() );
		$result = is_string( $raw ) ? self::apply_json( $raw, $confirmed ) : new WP_Error( 'expired', __( 'The file is no longer waiting. Choose it again.', 'fluffless-suite' ) );
		$url    = true === $result ? add_query_arg( 'fl_imported', '1', $url ) : add_query_arg( 'fl_import_error', $result->get_error_code(), $url );
		wp_safe_redirect( $url );
		exit;
	}

	/** Retour à l'état d'avant le dernier import (admin-post). */
	public static function undo_action(): void {
		check_admin_referer( self::ACTION_UNDO );
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import settings.', 'fluffless-suite' ), '', array( 'response' => 403 ) );
		}
		$result = self::undo();
		$url    = SettingsPage::url( 'fl-transfer' );
		$url    = true === $result ? add_query_arg( 'fl_undone', '1', $url ) : add_query_arg( 'fl_import_error', $result->get_error_code(), $url );
		wp_safe_redirect( $url );
		exit;
	}

	/** Clé de la copie en attente, propre à l'utilisatrice. */
	private static function stage_key(): string {
		return 'fluffless_transfer_stage_' . get_current_user_id();
	}

	/**
	 * Valide le fichier reçu et le met en attente de confirmation : rien n'est écrit dans les réglages.
	 *
	 * @param mixed $file Entrée de $_FILES.
	 * @return true|WP_Error
	 */
	public static function read_upload( $file ) {
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'upload', __( 'No file received. Choose a JSON file exported from the suite.', 'fluffless-suite' ) );
		}
		$path = (string) $file['tmp_name'];
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES || ! is_uploaded_file( $path ) ) {
			return new WP_Error( 'upload', __( 'No file received. Choose a JSON file exported from the suite.', 'fluffless-suite' ) );
		}
		$raw = file_get_contents( $path, false, null, 0, self::MAX_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier temporaire du téléversement.
		return self::stage_json( false === $raw ? '' : $raw );
	}

	/**
	 * Vérifie du JSON brut et le garde une heure, en attente de confirmation.
	 *
	 * @param string $raw Texte du fichier.
	 * @return true|WP_Error
	 */
	public static function stage_json( string $raw ) {
		$plan = self::plan( strlen( $raw ) > self::MAX_BYTES ? null : json_decode( $raw, true ) );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		set_transient( self::stage_key(), $raw, self::STAGE_TTL );
		return true;
	}

	/**
	 * Décode puis applique du JSON brut.
	 *
	 * @param string             $raw       Texte du fichier.
	 * @param array<int, string> $confirmed Modules sensibles confirmés.
	 * @return true|WP_Error
	 */
	public static function apply_json( string $raw, array $confirmed = array() ) {
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new WP_Error( 'format', __( 'This file is not a Fluffless Suite settings export.', 'fluffless-suite' ) );
		}
		$data = json_decode( $raw, true );
		return self::apply( $data, $confirmed );
	}

	/**
	 * Message d'une erreur d'import, par code.
	 *
	 * @param string $code Code renvoyé dans l'adresse.
	 */
	public static function error_message( string $code ): string {
		switch ( $code ) {
			case 'schema':
				return __( 'This file comes from a newer version of the suite. Update the suite first.', 'fluffless-suite' );
			case 'upload':
				return __( 'No file received. Choose a JSON file exported from the suite.', 'fluffless-suite' );
			case 'expired':
				return __( 'The file is no longer waiting. Choose it again.', 'fluffless-suite' );
			case 'nothing':
				return __( 'There is no earlier state to restore.', 'fluffless-suite' );
			default:
				return __( 'This file is not a Fluffless Suite settings export.', 'fluffless-suite' );
		}
	}

	/** Un résultat d'import, d'annulation ou de retour arrière vient-il d'être renvoyé par la redirection ? */
	public static function has_result(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- simples indicateurs d'affichage après redirection.
		return isset( $_GET['fl_imported'] ) || isset( $_GET['fl_undone'] ) || isset( $_GET['fl_cancelled'] ) || isset( $_GET['fl_import_error'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/** Résultat de la dernière action d'import, rendu tout en haut de l'écran Modules, avant les autres messages. */
	public static function render_result(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- simples indicateurs d'affichage après redirection.
		$failed = isset( $_GET['fl_import_error'] ) ? sanitize_key( wp_unslash( (string) $_GET['fl_import_error'] ) ) : '';
		if ( isset( $_GET['fl_imported'] ) ) {
			echo Screen::note( 'good', __( 'Settings imported. Reload the screen to see them. The earlier state is kept: you can restore it below.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		} elseif ( isset( $_GET['fl_undone'] ) ) {
			echo Screen::note( 'good', __( 'The settings from before the last import are back.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		} elseif ( isset( $_GET['fl_cancelled'] ) ) {
			echo Screen::note( 'idle', __( 'Import cancelled. Nothing was changed.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		} elseif ( '' !== $failed ) {
			echo Screen::note( 'bad', self::error_message( $failed ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/** Section rendue sous le formulaire de l'écran Modules. */
	public static function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		echo '<section class="fl-card fl-transfer" id="fl-transfer">';
		echo '<h2>' . esc_html__( 'Export and import settings', 'fluffless-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'The export is a JSON file with the settings of the suite: modules on or off, palette, fonts, forms, security, plus the page templates saved from the editor. Import it on another site to replay the same configuration. It holds no other content, no media and no key, and it is not a backup of the site: that job belongs to the host.', 'fluffless-suite' ) . '</p>';
		echo '<p>' . esc_html__( 'The hidden login address stays out of the file, in both directions: set it again on the new site in the Basic security module.', 'fluffless-suite' ) . '</p>';

		self::render_pending();

		echo '<div class="fl-fields">';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_EXPORT ) . '">';
		wp_nonce_field( self::ACTION_EXPORT );
		submit_button( __( 'Export settings', 'fluffless-suite' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_IMPORT ) . '">';
		wp_nonce_field( self::ACTION_IMPORT );
		echo '<label for="fluffless_settings_file">' . esc_html__( 'Import a file', 'fluffless-suite' ) . '</label> ';
		echo '<input type="file" id="fluffless_settings_file" name="fluffless_settings_file" accept="application/json,.json" required> ';
		submit_button( __( 'Review import', 'fluffless-suite' ), 'secondary', 'submit', false );
		echo '</form>';
		$previous = self::previous();
		if ( null !== $previous ) {
			$saved = strtotime( (string) $previous['at'] );
			echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_UNDO ) . '">';
			wp_nonce_field( self::ACTION_UNDO );
			submit_button(
				sprintf(
					/* translators: 1: date and time the earlier state was saved, 2: date after which it can no longer be restored. */
					__( 'Restore the settings from before the last import (saved on %1$s, available until %2$s)', 'fluffless-suite' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $saved ),
					wp_date( get_option( 'date_format' ), (int) $saved + self::UNDO_TTL )
				),
				'secondary',
				'submit',
				false
			);
			echo '</form>';
		}
		echo '</div></section>';
	}

	/** Aperçu du fichier en attente : ce qui sera activé, éteint et écrasé, avec une case par module sensible. */
	private static function render_pending(): void {
		$raw = get_transient( self::stage_key() );
		if ( ! is_string( $raw ) ) {
			return;
		}
		$plan = self::plan( json_decode( $raw, true ) );
		if ( is_wp_error( $plan ) ) {
			return;
		}
		$modules = Loader::modules();
		$label   = static function ( string $id ) use ( $modules ): string {
			return isset( $modules[ $id ] ) ? $modules[ $id ]::label() : $id;
		};
		echo '<div class="fl-note fl-note--warn" role="status"><div>';
		echo '<p><strong>' . esc_html__( 'Review before applying. Nothing has been changed yet.', 'fluffless-suite' ) . '</strong></p>';
		echo '<ul>';
		foreach ( self::preview_lines( $plan ) as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '<li>' . esc_html__( 'The file waits one hour for your confirmation, then it is discarded.', 'fluffless-suite' ) . '</li>';
		echo '</ul>';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CONFIRM ) . '">';
		wp_nonce_field( self::ACTION_CONFIRM );
		foreach ( $plan['sensitive'] as $id ) {
			printf(
				'<p><label><input type="checkbox" name="fl_confirm_module[]" value="%1$s"> %2$s</label></p>',
				esc_attr( $id ),
				esc_html(
					sprintf(
						/* translators: %s: module name. */
						__( 'Also turn on %s. It can change how you log in; leave this unchecked to keep it as it is now.', 'fluffless-suite' ),
						$label( $id )
					)
				)
			);
		}
		submit_button( __( 'Apply the import', 'fluffless-suite' ), 'primary', 'submit', false );
		echo ' ';
		submit_button( __( 'Cancel', 'fluffless-suite' ), 'secondary', 'fl_cancel', false );
		echo '</form></div></div>';
	}
}
