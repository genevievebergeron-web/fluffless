<?php
/**
 * Sonde WP-CLI, chargée par --require avant WordPress : signale un domaine de texte fluffless-seo
 * demandé avant init (avis « triggered too early » de WordPress 6.7). Lue par tools/test-seo.sh.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

// Le crochet est préinitialisé dans $wp_filter : add_action() n'existe pas encore à ce moment.
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- c'est le mécanisme prévu par WP_Hook::build_preinitialized_hooks().
$GLOBALS['wp_filter']['doing_it_wrong_run'][10]['fluffless_seo_early_textdomain'] = array(
	'function'      => static function ( $function_name, $message ): void {
		if ( '_load_textdomain_just_in_time' === $function_name && is_string( $message ) && str_contains( $message, 'fluffless-seo' ) ) {
			echo "FLUFFLESS_SEO_TEXTDOMAIN_TOO_EARLY\n";
		}
	},
	'accepted_args' => 2,
);
