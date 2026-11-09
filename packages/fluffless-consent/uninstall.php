<?php
/**
 * Désinstallation : retire la section « consent » de l'option partagée fluffless_settings.
 * Le reste de l'option appartient à Fluffless Suite, qui l'efface à sa propre désinstallation.
 * L'option fluffless_consent_embeds (présence de contenus intégrés) est retirée aussi.
 * Le choix des visiteurs vit dans leur navigateur (localStorage) : rien à effacer côté serveur.
 *
 * Rien n'est retiré quand FLUFFLESS_KEEP_DATA est vraie (wp-config.php) ou que la case « Garder mes données »
 * de Fluffless Suite est cochée : même règle que Fluffless Suite (Lifecycle::keeps_data), qui garde alors
 * tout le réseau de réglages dont cette section fait partie.
 *
 * @package Fluffless_Consent
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// L'extension n'est pas chargée ici : lecture brute de la constante (sa valeur compte) et de la case.
if ( filter_var( defined( 'FLUFFLESS_KEEP_DATA' ) ? constant( 'FLUFFLESS_KEEP_DATA' ) : false, FILTER_VALIDATE_BOOLEAN ) ) {
	return;
}

$fluffless_consent_option = get_option( 'fluffless_settings' );
if ( is_array( $fluffless_consent_option ) && is_array( $fluffless_consent_option['form'] ?? null ) && true === ( $fluffless_consent_option['form']['keep_data'] ?? false ) ) {
	return;
}

if ( is_array( $fluffless_consent_option ) && array_key_exists( 'consent', $fluffless_consent_option ) ) {
	unset( $fluffless_consent_option['consent'] );
	if ( array() === $fluffless_consent_option ) {
		delete_option( 'fluffless_settings' );
	} else {
		// Sans ce retrait, l'assainissement de Fluffless Suite (active) remettrait la section qu'on vient d'enlever.
		remove_all_filters( 'sanitize_option_fluffless_settings' );
		update_option( 'fluffless_settings', $fluffless_consent_option );
	}
}

delete_option( 'fluffless_consent_embeds' );
