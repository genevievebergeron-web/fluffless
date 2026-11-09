<?php
/**
 * Réglages de la bannière : section « consent » de l'option fluffless_settings, et écran Réglages → Cookie consent (« Consentement aux témoins » en fr_CA).
 *
 * @package Fluffless_Consent
 */

declare( strict_types=1 );

namespace Fluffless\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * L'option est partagée avec Fluffless Suite : cette extension ne lit et n'écrit que sa section.
 * Pas de register_setting() : Suite déclare déjà l'option (schéma REST, rappel d'assainissement) et
 * une seconde déclaration écraserait la sienne. L'écran passe donc par le filtre allowed_options,
 * et l'assainissement par sanitize_option_fluffless_settings, après celui de Suite (priorité 20).
 *
 * @phpstan-type Section array{profile: string, ga_id: string, retention_months: int}
 */
final class Settings {

	public const NAME       = 'fluffless_settings';
	public const SECTION    = 'consent';
	public const GROUP      = 'fluffless_consent';
	public const PAGE       = 'fluffless-consent';
	public const CAPABILITY = 'manage_options';

	/** Durée de conservation du choix, en mois. */
	private const MIN_MONTHS = 1;
	private const MAX_MONTHS = 13;

	/**
	 * Valeurs par défaut.
	 *
	 * @return Section
	 */
	public static function defaults(): array {
		return array(
			'profile'          => 'quebec',
			'ga_id'            => '',
			'retention_months' => 6,
		);
	}

	/**
	 * Section courante : valeurs stockées du bon type, défauts pour le reste.
	 *
	 * @return Section
	 */
	public static function all(): array {
		$option  = get_option( self::NAME, array() );
		$stored  = is_array( $option ) && is_array( $option[ self::SECTION ] ?? null ) ? $option[ self::SECTION ] : array();
		$section = self::defaults();
		foreach ( $section as $key => $default ) {
			if ( array_key_exists( $key, $stored ) && gettype( $stored[ $key ] ) === gettype( $default ) ) {
				$section[ $key ] = $stored[ $key ];
			}
		}
		return $section;
	}

	/**
	 * Assainit une section : valeur valide ou défaut, clés inconnues écartées.
	 *
	 * @param array<mixed> $input Section brute.
	 * @return Section
	 */
	public static function sanitize( array $input ): array {
		$clean   = self::defaults();
		$profile = $input['profile'] ?? null;
		if ( is_string( $profile ) && isset( Consent::profiles()[ $profile ] ) ) {
			$clean['profile'] = $profile;
		}
		if ( is_string( $input['ga_id'] ?? null ) ) {
			$clean['ga_id'] = Consent::ga_id( $input['ga_id'] );
		}
		$months = filter_var( $input['retention_months'] ?? null, FILTER_VALIDATE_INT );
		if ( false !== $months ) {
			$clean['retention_months'] = max( self::MIN_MONTHS, min( self::MAX_MONTHS, $months ) );
		}
		return $clean;
	}

	/**
	 * Rappel de sanitize_option_fluffless_settings. Le formulaire de cet écran n'envoie que la section
	 * « consent » : le reste de l'option est repris tel qu'il est stocké, puis la section est assainie.
	 *
	 * @param mixed $value Valeur reçue (déjà passée par Suite si elle est active).
	 * @return array<mixed>
	 */
	public static function sanitize_option( $value ): array {
		$stored                 = get_option( self::NAME, array() );
		$value                  = ( is_array( $value ) ? $value : array() ) + ( is_array( $stored ) ? $stored : array() );
		$section                = is_array( $value[ self::SECTION ] ?? null ) ? $value[ self::SECTION ] : array();
		$value[ self::SECTION ] = self::sanitize( $section + self::all() );
		return $value;
	}

	/**
	 * Autorise options.php à enregistrer l'option depuis le groupe de cet écran.
	 *
	 * @param mixed $allowed Groupe => noms d'options.
	 * @return mixed
	 */
	public static function allow( $allowed ) {
		if ( is_array( $allowed ) ) {
			$allowed[ self::GROUP ] = array( self::NAME );
		}
		return $allowed;
	}

	/** Ajoute Réglages → Cookie consent. */
	public static function add_page(): void {
		add_options_page( __( 'Cookie consent', 'fluffless-consent' ), __( 'Cookie consent', 'fluffless-consent' ), self::CAPABILITY, self::PAGE, array( self::class, 'render' ) );
	}

	/** Écran : état, puis les trois réglages dans un tableau de réglages natif. */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$section = self::all();
		$name    = static fn( string $key ): string => self::NAME . '[' . self::SECTION . '][' . $key . ']';

		echo '<div class="wrap"><h1>' . esc_html__( 'Cookie consent', 'fluffless-consent' ) . '</h1>';
		echo '<p>' . esc_html( Consent::status() ) . '</p>';
		echo '<form action="' . esc_url( admin_url( 'options.php' ) ) . '" method="post">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';

		$options = '';
		foreach ( Consent::profiles() as $value => $label ) {
			$options .= '<option value="' . esc_attr( $value ) . '"' . selected( $section['profile'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		self::row( 'profile', __( 'Legal profile', 'fluffless-consent' ), '<select id="fluffless-consent-profile" name="' . esc_attr( $name( 'profile' ) ) . '">' . $options . '</select>', __( 'Adapts the banner and the privacy policy text to where your visitors live.', 'fluffless-consent' ) );
		self::row( 'ga_id', __( 'Google Analytics measurement ID', 'fluffless-consent' ), '<input type="text" class="regular-text" id="fluffless-consent-ga_id" name="' . esc_attr( $name( 'ga_id' ) ) . '" value="' . esc_attr( $section['ga_id'] ) . '" maxlength="20" autocomplete="off">', __( 'For example G-ABC123XYZ. Loaded only after the visitor accepts audience measurement. A cookieless tool such as Umami or Plausible usually needs no banner.', 'fluffless-consent' ) );
		self::row( 'retention_months', __( 'Keep the choice for (months)', 'fluffless-consent' ), '<input type="number" class="small-text" id="fluffless-consent-retention_months" name="' . esc_attr( $name( 'retention_months' ) ) . '" value="' . esc_attr( (string) $section['retention_months'] ) . '" min="' . self::MIN_MONTHS . '" max="' . self::MAX_MONTHS . '">', __( 'After this delay, the banner asks again. 13 months at most.', 'fluffless-consent' ) );

		echo '</tbody></table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Une ligne du tableau. Le contrôle est déjà échappé par l'appelant.
	 *
	 * @param string $key     Clé du réglage (suffixe de l'identifiant du champ).
	 * @param string $label   Libellé.
	 * @param string $control Balisage du contrôle.
	 * @param string $help    Aide sous le champ.
	 */
	private static function row( string $key, string $label, string $control, string $help ): void {
		$id = 'fluffless-consent-' . $key;
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>%3$s<p class="description" id="%1$s-help">%4$s</p></td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			str_replace( ' id="' . $id . '"', ' id="' . $id . '" aria-describedby="' . $id . '-help"', $control ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- contrôle échappé dans render().
			esc_html( $help )
		);
	}
}
