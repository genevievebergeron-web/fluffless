<?php
/**
 * Diagnostic technique joint (sur consentement) à un signalement, et affiché par WP-CLI.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

use Fluffless\Suite\Modules\Updates;

use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Versions et réglages techniques seulement : aucune adresse, aucun nom, aucun courriel.
 * Le site n'est désigné que par l'empreinte SHA-256 de son adresse.
 */
final class Diagnostic {

	/**
	 * Champs du contrat de l'API (/v1/reports, diagnostics).
	 *
	 * @return array{wp: string, php: string, theme: string, theme_version: string, tools_version: string, seo_version?: string, modules: list<string>, multisite: bool, locale: string}
	 */
	public static function collect(): array {
		$theme = wp_get_theme();
		$data  = array(
			'wp'            => (string) get_bloginfo( 'version' ),
			'php'           => PHP_VERSION,
			'theme'         => $theme->get_stylesheet(),
			'theme_version' => (string) $theme->get( 'Version' ),
			'tools_version' => VERSION,
			'modules'       => Loader::active(),
			'multisite'     => is_multisite(),
			'locale'        => determine_locale(),
		);
		$seo   = Updates::packages()['fluffless-seo']['version'] ?? '';
		if ( '' !== $seo ) {
			$data['seo_version'] = $seo;
		}
		return $data;
	}

	/** Texte lisible, une ligne par champ, empreinte du site comprise : ce qui part, tel quel. */
	public static function text(): string {
		$lines = array();
		foreach ( self::collect() as $key => $value ) {
			$lines[] = $key . ': ' . match ( true ) {
				is_bool( $value )  => $value ? 'yes' : 'no',
				is_array( $value ) => implode( ', ', $value ),
				default            => (string) $value,
			};
		}
		$lines[] = 'site (sha256): ' . Api::site_hash();
		return implode( "\n", $lines );
	}
}
