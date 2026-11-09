<?php
/**
 * Fonctions publiques de Fluffless Consent, pour le thème et les autres extensions.
 *
 * @package Fluffless_Consent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'fluffless_consent_link' ) ) {
	/**
	 * Bouton « Gérer mes témoins », '' si aucun service non essentiel n'est déclaré.
	 * Même rendu que le code court [fluffless_consent_link].
	 */
	function fluffless_consent_link(): string {
		return \Fluffless\Consent\Consent::shortcode();
	}
}
