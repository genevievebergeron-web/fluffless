<?php
/**
 * Liens de campagne : construit une adresse avec les paramètres UTM et garde quelques préréglages.
 *
 * Noms standard (utm_source, utm_medium, utm_campaign, utm_term, utm_content), ceux que lisent
 * Plausible et Umami. Aucun paramètre n'est lu ni gardé côté visiteur : l'outil ne fait que l'adresse.
 *
 * @package Fluffless_Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Module Liens de campagne.
 *
 * Le formulaire fonctionne sans script (envoi GET, adresse construite par le serveur) ; un script en
 * ligne de quelques lignes ajoute le bouton Copier. Les préréglages vivent dans une option à part.
 *
 * @phpstan-type Preset array{name: string, utm_source: string, utm_medium: string, utm_campaign: string, utm_term: string, utm_content: string}
 */
final class Utm implements Module {

	/** Paramètres, dans l'ordre de l'adresse. */
	public const PARAMS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );

	/** Option des préréglages. */
	public const OPTION = 'fluffless_utm_presets';

	/** Préréglages gardés au plus. */
	public const MAX_PRESETS = 20;

	/** Longueur maximale d'une valeur. */
	public const MAX_LENGTH = 100;

	/** Écran d'administration. */
	public const PAGE = 'fluffless-utm';

	/** Action admin-post des préréglages, aussi le nom du jeton. */
	public const ACTION = 'fluffless_utm_preset';

	/** Droit exigé : qui écrit des pages prépare aussi les campagnes. */
	public const CAPABILITY = 'edit_pages';

	/** {@inheritDoc} */
	public static function id(): string {
		return 'utm';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Campaign links', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Builds a link with utm_source, utm_medium, utm_campaign, utm_term and utm_content, ready to copy, and keeps a few saved presets. Plausible and Umami read these names. Nothing is added to your pages.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array();
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
		return array();
	}

	/** {@inheritDoc} */
	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action(
			'admin_menu',
			static function (): void {
				Menu::add_tab( 'content', self::PAGE, __( 'Campaign links', 'fluffless-suite' ), array( self::class, 'render' ), 80, self::CAPABILITY );
			}
		);
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Valeur normalisée : minuscules, espaces et soulignés remplacés par un trait d'union,
	 * caractères de contrôle retirés. L'encodage se fait à la construction de l'adresse.
	 *
	 * @param string $value Texte saisi.
	 */
	public static function normalize( string $value ): string {
		$value = sanitize_text_field( $value );
		$value = mb_strtolower( $value, 'UTF-8' );
		$value = (string) preg_replace( '/[\s_]+/u', '-', trim( $value ) );
		$value = trim( (string) preg_replace( '/-{2,}/', '-', $value ), '-' );
		return mb_substr( $value, 0, self::MAX_LENGTH, 'UTF-8' );
	}

	/**
	 * Adresse de campagne : les paramètres non vides ajoutés à l'adresse, encodés, ceux déjà
	 * présents remplacés. Vide si l'adresse n'est pas http(s).
	 *
	 * @param string                $url    Adresse de départ.
	 * @param array<string, string> $params Paramètres bruts.
	 */
	public static function build( string $url, array $params ): string {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}
		$url   = remove_query_arg( self::PARAMS, $url );
		$query = array();
		foreach ( self::PARAMS as $name ) {
			$value = self::normalize( (string) ( $params[ $name ] ?? '' ) );
			if ( '' !== $value ) {
				$query[] = $name . '=' . rawurlencode( $value );
			}
		}
		if ( array() === $query ) {
			return $url . $fragment;
		}
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . implode( '&', $query ) . $fragment;
	}

	/**
	 * Préréglages enregistrés, assainis.
	 *
	 * @return list<Preset>
	 */
	public static function presets(): array {
		$raw = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $preset ) {
			if ( is_array( $preset ) ) {
				$clean = self::preset( $preset );
				if ( null !== $clean ) {
					$out[] = $clean;
				}
			}
		}
		return array_slice( $out, 0, self::MAX_PRESETS );
	}

	/**
	 * Un préréglage assaini, ou null sans nom ni source.
	 *
	 * @param array<mixed> $raw Valeurs reçues.
	 * @return Preset|null
	 */
	public static function preset( array $raw ): ?array {
		$name = mb_substr( sanitize_text_field( (string) ( is_scalar( $raw['name'] ?? null ) ? $raw['name'] : '' ) ), 0, self::MAX_LENGTH, 'UTF-8' );
		$out  = array( 'name' => $name );
		foreach ( self::PARAMS as $param ) {
			$out[ $param ] = self::normalize( is_scalar( $raw[ $param ] ?? null ) ? (string) $raw[ $param ] : '' );
		}
		if ( '' === $name || '' === $out['utm_source'] ) {
			return null;
		}
		/**
		 * Préréglage complet.
		 *
		 * @var Preset $out
		 */
		return $out;
	}

	/**
	 * Ajoute ou remplace (même nom) un préréglage, en tête de liste.
	 *
	 * @param array<mixed> $raw Valeurs reçues.
	 */
	public static function save_preset( array $raw ): bool {
		$preset = self::preset( $raw );
		if ( null === $preset ) {
			return false;
		}
		$others = array_values( array_filter( self::presets(), static fn( array $p ): bool => $p['name'] !== $preset['name'] ) );
		update_option( self::OPTION, array_slice( array_merge( array( $preset ), $others ), 0, self::MAX_PRESETS ), false );
		return true;
	}

	/**
	 * Retire un préréglage par son nom.
	 *
	 * @param string $name Nom.
	 */
	public static function delete_preset( string $name ): void {
		$kept = array_values( array_filter( self::presets(), static fn( array $p ): bool => $p['name'] !== $name ) );
		if ( array() === $kept ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $kept, false );
	}

	/** Enregistre ou retire un préréglage, puis revient à l'écran. */
	public static function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fluffless-suite' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$status = 'saved';
		if ( isset( $_POST['delete'] ) ) {
			self::delete_preset( sanitize_text_field( wp_unslash( (string) $_POST['delete'] ) ) );
			$status = 'deleted';
		} else {
			$raw = array( 'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '' );
			foreach ( self::PARAMS as $param ) {
				$raw[ $param ] = isset( $_POST[ $param ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $param ] ) ) : '';
			}
			$status = self::save_preset( $raw ) ? 'saved' : 'invalid';
		}
		wp_safe_redirect( add_query_arg( 'fl_utm', $status, Menu::url( self::PAGE ) ) );
		exit;
	}

	/**
	 * Libellés des paramètres.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return array(
			'utm_source'   => __( 'Source (utm_source), for example newsletter', 'fluffless-suite' ),
			'utm_medium'   => __( 'Medium (utm_medium), for example email', 'fluffless-suite' ),
			'utm_campaign' => __( 'Campaign (utm_campaign), for example spring-sale', 'fluffless-suite' ),
			'utm_term'     => __( 'Term (utm_term), optional', 'fluffless-suite' ),
			'utm_content'  => __( 'Content (utm_content), optional', 'fluffless-suite' ),
		);
	}

	/** Écran : formulaire, adresse construite avec son bouton Copier, préréglages. */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- lecture seule : le formulaire GET ne fait que construire une adresse.
		$values = array( 'url' => isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( (string) $_GET['url'] ) ) : home_url( '/' ) );
		foreach ( self::PARAMS as $param ) {
			$values[ $param ] = isset( $_GET[ $param ] ) ? self::normalize( sanitize_text_field( wp_unslash( (string) $_GET[ $param ] ) ) ) : '';
		}
		$status = isset( $_GET['fl_utm'] ) ? sanitize_key( wp_unslash( (string) $_GET['fl_utm'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$notices = array(
			'saved'   => __( 'Preset saved.', 'fluffless-suite' ),
			'deleted' => __( 'Preset deleted.', 'fluffless-suite' ),
			'invalid' => __( 'A preset needs a name and a source.', 'fluffless-suite' ),
		);
		if ( isset( $notices[ $status ] ) ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', 'invalid' === $status ? 'error' : 'success', esc_html( $notices[ $status ] ) );
		}
		echo '<p>' . esc_html__( 'Values are put in lowercase, spaces become hyphens and special characters are encoded.', 'fluffless-suite' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><table class="form-table" role="presentation">';
		printf( '<tr><th scope="row"><label for="fl-utm-url">%1$s</label></th><td><input type="url" id="fl-utm-url" name="url" class="large-text" value="%2$s" required></td></tr>', esc_html__( 'Page address', 'fluffless-suite' ), esc_attr( $values['url'] ) );
		foreach ( self::labels() as $param => $label ) {
			printf( '<tr><th scope="row"><label for="fl-%1$s">%2$s</label></th><td><input type="text" id="fl-%1$s" name="%1$s" class="regular-text" value="%3$s" maxlength="%4$d"></td></tr>', esc_attr( $param ), esc_html( $label ), esc_attr( $values[ $param ] ), (int) self::MAX_LENGTH );
		}
		echo '</table>';
		submit_button( __( 'Build the link', 'fluffless-suite' ), 'primary', '', false );
		echo '</form>';

		$link = self::build( $values['url'], $values );
		if ( '' !== $link && '' !== $values['utm_source'] ) {
			echo '<h2>' . esc_html__( 'Your link', 'fluffless-suite' ) . '</h2>';
			printf( '<p><label class="screen-reader-text" for="fl-utm-result">%1$s</label><input type="text" id="fl-utm-result" class="large-text code" readonly value="%2$s"></p>', esc_html__( 'Your link', 'fluffless-suite' ), esc_attr( $link ) );
			printf( '<p><button type="button" class="button" id="fl-utm-copy" data-done="%1$s">%2$s</button> <span id="fl-utm-copied" role="status"></span></p>', esc_attr__( 'Copied.', 'fluffless-suite' ), esc_html__( 'Copy', 'fluffless-suite' ) );
			echo "<script>document.getElementById('fl-utm-copy').addEventListener('click',function(){var f=document.getElementById('fl-utm-result'),b=this;f.select();(navigator.clipboard?navigator.clipboard.writeText(f.value):Promise.reject()).catch(function(){document.execCommand('copy');}).finally(function(){document.getElementById('fl-utm-copied').textContent=b.dataset.done;});});</script>";
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			wp_nonce_field( self::ACTION );
			foreach ( self::PARAMS as $param ) {
				printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $param ), esc_attr( $values[ $param ] ) );
			}
			printf( '<p><label for="fl-utm-name">%1$s</label> <input type="text" id="fl-utm-name" name="name" maxlength="%2$d" required> ', esc_html__( 'Save these values as a preset named', 'fluffless-suite' ), (int) self::MAX_LENGTH );
			submit_button( __( 'Save the preset', 'fluffless-suite' ), 'secondary', '', false );
			echo '</p></form>';
		}

		$presets = self::presets();
		echo '<h2>' . esc_html__( 'Presets', 'fluffless-suite' ) . '</h2>';
		if ( array() === $presets ) {
			echo '<p>' . esc_html__( 'No preset yet. Build a link, then save its values.', 'fluffless-suite' ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $presets as $preset ) {
			$args = array_merge(
				array(
					'page' => self::PAGE,
					'url'  => rawurlencode( $values['url'] ),
				),
				array_map( 'rawurlencode', array_diff_key( $preset, array( 'name' => '' ) ) )
			);
			printf(
				'<li><a href="%1$s">%2$s</a> <code>%3$s</code> <form method="post" action="%4$s" style="display:inline"><input type="hidden" name="action" value="%5$s"><input type="hidden" name="delete" value="%6$s">',
				esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ),
				esc_html( $preset['name'] ),
				esc_html( implode( ' / ', array_filter( array_diff_key( $preset, array( 'name' => '' ) ) ) ) ),
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( self::ACTION ),
				esc_attr( $preset['name'] )
			);
			wp_nonce_field( self::ACTION );
			printf( '<button type="submit" class="button-link">%1$s<span class="screen-reader-text"> %2$s</span></button></form></li>', esc_html__( 'Delete', 'fluffless-suite' ), esc_html( $preset['name'] ) );
		}
		echo '</ul>';
	}
}
