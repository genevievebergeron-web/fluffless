<?php
/**
 * Boutons « Corriger » du Bilan : un constat qui cloche et qu'un module règle propose de le faire.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Admin;

use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Audit;
use Fluffless\Suite\Modules\Footprint;
use Fluffless\Suite\Modules\Images;
use Fluffless\Suite\Modules\Shop;

defined( 'ABSPATH' ) || exit;

/**
 * Seules les corrections de fixes() existent : le formulaire n'envoie qu'un identifiant, jamais un
 * module ni un réglage. Chacune se défait depuis l'écran Modules (ou Extensions pour Consent) ;
 * pas de boîte de confirmation, le libellé dit ce qui change. L'offre se calcule à l'affichage :
 * une fois appliquée, le bouton disparaît sans relancer le bilan.
 */
final class AuditFix {

	public const ACTION = 'fluffless_audit_fix';

	/** Paramètre de retour : correction appliquée. */
	public const DONE = 'fluffless_fixed';

	/** Paramètre de retour : correction refusée ou sans effet. */
	public const FAILED = 'fluffless_fix_failed';

	/** Extension Fluffless Consent, nom de base. */
	public const CONSENT_PLUGIN = 'fluffless-consent/fluffless-consent.php';

	/** Constat du bilan => correction qui le règle. */
	private const FOR_ITEM = array(
		'shop'    => 'shop',
		'images'  => 'images',
		'weight'  => 'footprint',
		'consent' => 'consent',
	);

	/** Accroché depuis Audit::register(), dans l'administration seulement. */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Corrections connues : libellé du bouton, avis après coup.
	 *
	 * @return array<string, array{label: string, done: string}>
	 */
	public static function fixes(): array {
		return array(
			'shop'      => array(
				'label' => __( 'Turn on the Lean shop module', 'fluffless-suite' ),
				'done'  => __( 'Lean shop module turned on.', 'fluffless-suite' ),
			),
			'images'    => array(
				'label' => __( 'Turn on the Images module for new uploads', 'fluffless-suite' ),
				'done'  => __( 'Images module turned on. Images already in the library are unchanged.', 'fluffless-suite' ),
			),
			'footprint' => array(
				'label' => __( 'Turn on the Page footprint module', 'fluffless-suite' ),
				'done'  => __( 'Page footprint module turned on: the editor now names the heaviest file of each page.', 'fluffless-suite' ),
			),
			'consent'   => array(
				'label' => __( 'Activate Fluffless Consent', 'fluffless-suite' ),
				'done'  => __( 'Fluffless Consent activated. Declare the services that need consent in its settings.', 'fluffless-suite' ),
			),
		);
	}

	/**
	 * La correction peut-elle s'appliquer maintenant ?
	 *
	 * @param string $fix Identifiant de fixes().
	 */
	public static function available( string $fix ): bool {
		switch ( $fix ) {
			case 'shop':
				return ! Options::is_enabled( Shop::id() );
			case 'images':
				return ! Options::is_enabled( Images::id() );
			case 'footprint':
				return ! Options::is_enabled( Footprint::id() );
			case 'consent':
				return self::consent_installed() && ! self::consent_active() && current_user_can( 'activate_plugins' );
		}
		return false;
	}

	/**
	 * Correction proposée pour un constat, ou null : seulement s'il cloche et qu'elle s'applique.
	 *
	 * @param string                $key  Clé du constat.
	 * @param array{status: string} $item Constat.
	 */
	public static function for_item( string $key, array $item ): ?string {
		$fix = self::FOR_ITEM[ $key ] ?? null;
		if ( null === $fix || ! in_array( $item['status'], array( 'warn', 'bad' ), true ) ) {
			return null;
		}
		return self::available( $fix ) ? $fix : null;
	}

	/**
	 * Applique une correction. Faux si inconnue, déjà faite ou refusée.
	 *
	 * @param string $fix Identifiant de fixes().
	 */
	public static function apply( string $fix ): bool {
		if ( ! isset( self::fixes()[ $fix ] ) || ! self::available( $fix ) ) {
			return false;
		}
		switch ( $fix ) {
			case 'shop':
				return self::enable( Shop::id() );
			case 'images':
				return self::enable( Images::id() );
			case 'footprint':
				return self::enable( Footprint::id() );
			case 'consent':
				if ( ! function_exists( 'activate_plugin' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				return null === activate_plugin( self::CONSENT_PLUGIN );
		}
		return false;
	}

	/** Formulaire du bouton : vérifie droits et nonce propre à la correction, applique, revient au Bilan. */
	public static function handle(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'fluffless-suite' ), 403 );
		}
		$fix = isset( $_POST['fix'] ) ? sanitize_key( wp_unslash( $_POST['fix'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié juste après, propre à cet identifiant.
		check_admin_referer( self::ACTION . '_' . $fix );
		$args = array( 'page' => Audit::PAGE );
		$args[ self::apply( $fix ) ? self::DONE : self::FAILED ] = '' === $fix ? 'unknown' : $fix;
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Bouton d'une correction.
	 *
	 * @param string $fix Identifiant de fixes().
	 */
	public static function form( string $fix ): string {
		$label = self::fixes()[ $fix ]['label'] ?? '';
		if ( '' === $label ) {
			return '';
		}
		return '<form class="fl-audit__fix" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="fix" value="' . esc_attr( $fix ) . '">'
			. wp_nonce_field( self::ACTION . '_' . $fix, '_wpnonce', true, false )
			. '<button type="submit" class="button button-primary">' . esc_html( $label ) . '</button>'
			. '</form>';
	}

	/** Avis après une correction, '' sans paramètre valide. */
	public static function notice(): string {
		if ( isset( $_GET[ self::FAILED ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- affichage seulement, valeur jamais réécrite.
			return Screen::note( 'warn', __( 'Nothing was changed: this fix no longer applies, or WordPress refused it. Run the check again to see the current state.', 'fluffless-suite' ) );
		}
		$fix  = isset( $_GET[ self::DONE ] ) ? sanitize_key( wp_unslash( $_GET[ self::DONE ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- affichage seulement, identifiant en liste blanche.
		$done = self::fixes()[ $fix ]['done'] ?? '';
		return '' === $done ? '' : Screen::note( 'good', $done . ' ' . __( 'Run the check again to measure the effect.', 'fluffless-suite' ) );
	}

	/** Fluffless Consent tourne-t-elle ? */
	public static function consent_active(): bool {
		return class_exists( '\\Fluffless\\Consent\\Consent', false );
	}

	/** Nombre de services déclarés dans Fluffless Consent, 0 si elle ne tourne pas. */
	public static function consent_services(): int {
		return self::consent_active() ? count( \Fluffless\Consent\Consent::services() ) : 0;
	}

	/** Fluffless Consent est-elle installée ? */
	public static function consent_installed(): bool {
		return file_exists( WP_PLUGIN_DIR . '/' . self::CONSENT_PLUGIN );
	}

	/**
	 * Allume un module, avec des réglages de sa section. Passe par Options::sanitize() : vrai aussi
	 * hors administration (WP-CLI), où le rappel de register_setting n'est pas branché.
	 *
	 * @param string               $module   Identifiant du module.
	 * @param array<string, mixed> $settings Réglages de sa section.
	 */
	private static function enable( string $module, array $settings = array() ): bool {
		$input = array( 'modules' => array( $module => true ) );
		if ( array() !== $settings ) {
			$input[ $module ] = $settings;
		}
		update_option( Options::NAME, Options::sanitize( $input ) );
		Options::flush();
		return Options::is_enabled( $module );
	}
}
