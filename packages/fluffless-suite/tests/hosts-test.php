<?php
/**
 * Tests de l'écran « Serveurs contactés » (Core\Hosts, Admin\Hosts) et de Http::effective_allowed().
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/hosts-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * Les réglages et les options du cœur sont simulés par pre_option_* : rien n'est écrit, sauf le
 * transitoire du Bilan, sauvegardé puis remis. pre_http_request compte les appels : il doit rester à 0.
 *
 * @package Fluffless_Suite
 */

// Le test pose des crochets du cœur (pre_http_request, pre_option_*, get_avatar_url) : ces noms ne portent pas le préfixe.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

use Fluffless\Suite\Admin\Hosts as Screen;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Blocks\Sections;
use Fluffless\Suite\Core\Api;
use Fluffless\Suite\Core\Hosts;
use Fluffless\Suite\Core\Http;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Audit;
use Fluffless\Suite\Modules\Cleanup;
use Fluffless\Suite\Modules\Newsletter;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Hosts::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé, ou Core\\Hosts absent.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_hooks  = array();
$fluffless_hook   = static function ( string $hook, callable $callback ) use ( &$fluffless_hooks ): void {
	add_filter( $hook, $callback, 10, 3 );
	$fluffless_hooks[] = array( $hook, $callback );
};
$fluffless_unhook = static function () use ( &$fluffless_hooks ): void {
	foreach ( $fluffless_hooks as [ $hook, $callback ] ) {
		remove_filter( $hook, $callback, 10 );
	}
	$fluffless_hooks = array();
	Options::flush();
};

// Réglages simulés de la Suite et options du cœur, posés ensemble pour chaque cas.
$fluffless_case = static function ( array $settings, array $core = array() ) use ( $fluffless_hook, $fluffless_unhook ): void {
	$fluffless_unhook();
	$core += array(
		'blog_public'           => '1',
		'ping_sites'            => 'https://rpc.pingomatic.com/',
		'default_pingback_flag' => '1',
		'show_avatars'          => '1',
		Sections::VIDEO_OPTION  => '0',
	);
	$fluffless_hook( 'pre_option_' . Options::NAME, static fn(): array => $settings );
	foreach ( $core as $name => $value ) {
		$fluffless_hook( 'pre_option_' . $name, static fn() => $value );
	}
	Options::flush();
};

// Recherche dans le registre.
$fluffless_find   = static function ( array $entries, string $host, string $side = '' ): ?array {
	foreach ( $entries as $entry ) {
		if ( $host === $entry['host'] && ( '' === $side || $side === $entry['side'] ) ) {
			return $entry;
		}
	}
	return null;
};
$fluffless_linked = static function ( array $entries, string $needle ): ?array {
	foreach ( $entries as $entry ) {
		if ( 'linked' === $entry['scope'] && str_contains( implode( ' ', $entry['service'] ), $needle ) ) {
			return $entry;
		}
	}
	return null;
};
$fluffless_render = static function (): string {
	ob_start();
	Screen::render();
	return (string) ob_get_clean();
};

$fluffless_calls = 0;
$fluffless_net   = static function ( $pre ) use ( &$fluffless_calls ) {
	++$fluffless_calls;
	return $pre;
};
add_filter( 'pre_http_request', $fluffless_net, 1 );

$fluffless_backup_report = get_transient( Lifecycle::TRANSIENT_AUDIT_REPORT );
$fluffless_admins        = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$fluffless_admin         = array() === $fluffless_admins ? 0 : (int) $fluffless_admins[0];
$fluffless_env_key       = getenv( 'FLUFFLESS_STATS_API_KEY' );
$fluffless_env_news      = getenv( 'FLUFFLESS_NEWSLETTER_KEY' );

try {
	wp_set_current_user( $fluffless_admin );

	// 1. Défauts : modules par défaut seulement.
	$fluffless_case( array() );
	$fluffless_all = Hosts::all();
	$fluffless_api = $fluffless_find( $fluffless_all, Api::host(), 'server' );
	$fluffless_assert( null !== $fluffless_api && in_array( 'cron', $fluffless_api['when'], true ), 'défauts : API Fluffless, serveur, WP-Cron' );
	$fluffless_assert( null !== $fluffless_find( $fluffless_all, 'api.wordpress.org', 'server' ), 'défauts : api.wordpress.org' );
	$fluffless_ping = $fluffless_find( $fluffless_all, 'rpc.pingomatic.com', 'server' );
	$fluffless_assert( null !== $fluffless_ping && in_array( 'on_publish', $fluffless_ping['when'], true ), 'défauts : rpc.pingomatic.com à la publication' );
	foreach ( array( 'pingbacks', 'Audio and video', 'embedded address' ) as $fluffless_needle ) {
		$fluffless_assert( null !== $fluffless_linked( $fluffless_all, $fluffless_needle ), 'défauts : entrée linked « ' . $fluffless_needle . ' »' );
	}
	$fluffless_gravatar = $fluffless_find( $fluffless_all, 'secure.gravatar.com' );
	$fluffless_assert( null !== $fluffless_gravatar && 'admin_browser' === $fluffless_gravatar['side'], 'défauts : Gravatar côté admin seulement' );
	$fluffless_assert( array() === Hosts::side( $fluffless_all, 'browser' ), 'défauts : aucune entrée côté visites : ' . wp_json_encode( Hosts::side( $fluffless_all, 'browser' ) ) );
	$fluffless_assert( null === $fluffless_find( $fluffless_all, 's.w.org', 'browser' ), 'défauts : émojis retirés, s.w.org absent côté visites' );
	$fluffless_assert( null !== $fluffless_find( $fluffless_all, 'api.thegreenwebfoundation.org', 'server' ), 'défauts : Bilan allumé, Green Web Foundation listée' );
	$fluffless_assert( null === $fluffless_find( $fluffless_all, 'api.pexels.com' ), 'défauts : Pexels éteint, absent' );
	foreach ( $fluffless_all as $fluffless_entry ) {
		$fluffless_assert( in_array( $fluffless_entry['status'], array( 'active', 'inactive', 'env_off' ), true ), 'défauts : Requêtes sortantes éteint, rien de bloqué (' . $fluffless_entry['host'] . ')' );
	}

	// 2. Services de mise à jour (ping_sites).
	$fluffless_case( array(), array( 'ping_sites' => "https://rpc.pingomatic.com/\nhttps://ping.exemple.net/xmlrpc\npas une url" ) );
	$fluffless_assert( array( 'rpc.pingomatic.com', 'ping.exemple.net' ) === Hosts::ping_hosts(), 'ping_sites : deux domaines, la ligne invalide ignorée : ' . wp_json_encode( Hosts::ping_hosts() ) );
	$fluffless_case( array(), array( 'blog_public' => '0' ) );
	$fluffless_assert( array() === Hosts::ping_hosts() && null === $fluffless_find( Hosts::all(), 'rpc.pingomatic.com' ), 'ping_sites : site non indexé, aucune entrée' );

	// 3. Pings selon l'environnement (WP 7.1 seulement).
	$fluffless_case( array() );
	if ( function_exists( 'wp_should_disable_pings_for_environment' ) ) {
		$fluffless_off  = wp_should_disable_pings_for_environment();
		$fluffless_ping = $fluffless_find( Hosts::all(), 'rpc.pingomatic.com' );
		$fluffless_assert( null !== $fluffless_ping && ( 'env_off' === $fluffless_ping['status'] ) === $fluffless_off, 'pings : état suit wp_should_disable_pings_for_environment()' );
	}

	// 4. Pingbacks.
	$fluffless_case( array(), array( 'default_pingback_flag' => '0' ) );
	$fluffless_pb = $fluffless_linked( Hosts::all(), 'pingbacks' );
	$fluffless_assert( null !== $fluffless_pb && 'active' !== $fluffless_pb['status'] && '' === $fluffless_pb['host'], 'pingbacks : drapeau à 0, entrée inactive sans domaine' );

	// 5. Avatars.
	$fluffless_case( array(), array( 'show_avatars' => '0' ) );
	$fluffless_assert( null === $fluffless_find( Hosts::all(), 'secure.gravatar.com' ), 'avatars : show_avatars à 0, aucune entrée' );
	$fluffless_case( array() );
	$fluffless_hook( 'get_avatar_url', static fn(): string => 'https://avatars.exemple.ca/x.png' );
	$fluffless_av = $fluffless_find( Hosts::all(), 'avatars.exemple.ca' );
	$fluffless_assert( null !== $fluffless_av && 'admin_browser' === $fluffless_av['side'], 'avatars : hôte rendu par get_avatar_url()' );

	// 6. Émojis gardés.
	$fluffless_case( array( 'cleanup' => array( 'emoji' => false ) ) );
	$fluffless_assert( null !== $fluffless_find( Hosts::all(), 's.w.org', 'browser' ), 'émojis gardés : s.w.org côté visites' );

	// 7. Statistiques, relais éteint puis allumé.
	$fluffless_stats = array(
		'modules' => array( 'stats' => true ),
		'stats'   => array(
			'script_url' => 'https://plausible.io/js/script.js',
			'site_id'    => 'exemple.ca',
		),
	);
	$fluffless_case( $fluffless_stats );
	$fluffless_st = $fluffless_find( Hosts::all(), 'plausible.io' );
	$fluffless_assert( null !== $fluffless_st && 'browser' === $fluffless_st['side'] && array( 'always' ) === $fluffless_st['when'] && 'active' === $fluffless_st['status'], 'statistiques sans relais : navigateur, chaque page' );
	$fluffless_stats['stats']['site_id'] = '';
	$fluffless_case( $fluffless_stats );
	$fluffless_st = $fluffless_find( Hosts::all(), 'plausible.io' );
	$fluffless_assert( null !== $fluffless_st && 'inactive' === $fluffless_st['status'], 'statistiques sans site_id : inactif' );
	$fluffless_stats['stats']['site_id'] = 'exemple.ca';
	$fluffless_stats['stats']['proxy']   = true;
	$fluffless_case( $fluffless_stats );
	$fluffless_assert( null !== $fluffless_find( Hosts::all(), 'plausible.io', 'server' ) && null === $fluffless_find( Hosts::all(), 'plausible.io', 'browser' ), 'statistiques avec relais : serveur seulement' );

	// 8. Rapport carbone sur Umami Cloud.
	$fluffless_umami = array(
		'modules' => array(
			'stats'   => true,
			'carbone' => true,
		),
		'stats'   => array(
			'provider'   => 'umami',
			'script_url' => 'https://cloud.umami.is/script.js',
			'site_id'    => 'abc',
		),
	);
	putenv( 'FLUFFLESS_STATS_API_KEY=x' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- variable lue par le code testé, remise à la fin.
	$fluffless_case( $fluffless_umami );
	$fluffless_cb = $fluffless_find( Hosts::all(), 'api.umami.is', 'server' );
	$fluffless_assert( null !== $fluffless_cb && 'active' === $fluffless_cb['status'], 'rapport carbone : api.umami.is avec la clé' );
	putenv( 'FLUFFLESS_STATS_API_KEY' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- variable lue par le code testé, remise à la fin.
	$fluffless_case( $fluffless_umami );
	$fluffless_cb = $fluffless_find( Hosts::all(), 'api.umami.is', 'server' );
	$fluffless_assert( null !== $fluffless_cb && 'inactive' === $fluffless_cb['status'], 'rapport carbone sans clé : inactif' );

	// 9. Mailchimp : le centre de données, jamais la clé.
	$fluffless_key = str_repeat( 'a1', 16 ) . '-us21';
	putenv( 'FLUFFLESS_NEWSLETTER_KEY=' . $fluffless_key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- variable lue par le code testé, remise à la fin.
	$fluffless_news = array(
		'modules'    => array(
			'form'       => true,
			'newsletter' => true,
		),
		'newsletter' => array( 'provider' => 'mailchimp' ),
	);
	$fluffless_case( $fluffless_news );
	$fluffless_assert( null !== $fluffless_find( Hosts::all(), 'us21.api.mailchimp.com', 'server' ), 'Mailchimp : us21.api.mailchimp.com' );
	$fluffless_html = $fluffless_render();
	$fluffless_assert( '' !== $fluffless_html && false === strpos( $fluffless_html, $fluffless_key ), 'Mailchimp : la clé n\'apparaît pas dans l\'écran' );
	putenv( 'FLUFFLESS_NEWSLETTER_KEY=pas-une-cle' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- variable lue par le code testé, remise à la fin.
	$fluffless_case( $fluffless_news );
	$fluffless_assert( null === $fluffless_find( Hosts::all(), 'us21.api.mailchimp.com' ), 'Mailchimp : clé invalide, aucune entrée' );
	putenv( 'FLUFFLESS_NEWSLETTER_KEY' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- variable lue par le code testé, remise à la fin.

	// 10. Polices.
	$fluffless_fonts = array( 'modules' => array( 'fonts' => true ) );
	$fluffless_case( $fluffless_fonts );
	$fluffless_all = Hosts::all();
	$fluffless_assert( null !== $fluffless_find( $fluffless_all, 'fluffless-polices.canner.app', 'server' ) && null !== $fluffless_find( $fluffless_all, 'cdn.fontshare.com', 'server' ), 'polices : banque par défaut et Fontshare' );
	foreach ( array( '', 'http://x' ) as $fluffless_bank ) {
		$fluffless_case( $fluffless_fonts );
		$fluffless_hook( 'fluffless_font_bank', static fn(): string => $fluffless_bank );
		$fluffless_all = Hosts::all();
		$fluffless_assert( null !== $fluffless_find( $fluffless_all, 'fonts.gstatic.com', 'server' ) && null === $fluffless_find( $fluffless_all, 'fluffless-polices.canner.app' ), 'polices : banque « ' . $fluffless_bank . ' », fichiers d\'origine' );
	}

	// 11. Pexels sans clé.
	$fluffless_case( array( 'modules' => array( 'pexels' => true ) ) );
	$fluffless_px = $fluffless_find( Hosts::all(), 'api.pexels.com' );
	$fluffless_assert( null !== $fluffless_px && ( defined( 'FLUFFLESS_PEXELS_KEY' ) || 'inactive' === $fluffless_px['status'] ), 'Pexels sans clé : inactif' );

	// 12. Bloc Vidéo.
	$fluffless_case( array(), array( Sections::VIDEO_OPTION => '1' ) );
	$fluffless_all = Hosts::all();
	foreach ( array( 'www.youtube-nocookie.com', 'player.vimeo.com' ) as $fluffless_host ) {
		$fluffless_v = $fluffless_find( $fluffless_all, $fluffless_host, 'browser' );
		$fluffless_assert( null !== $fluffless_v && array( 'after_click' ) === $fluffless_v['when'], 'bloc Vidéo : ' . $fluffless_host . ' après un clic' );
	}

	// 13. Filtre fluffless_outgoing_hosts : nettoyage et fusion.
	$fluffless_case( array() );
	$fluffless_hook(
		'fluffless_outgoing_hosts',
		static function ( $entries ) {
			$base = array(
				'side' => 'server',
				'when' => 'action',
				'data' => 'x',
			);
			foreach ( array( 'exemple.com/chemin', 'a b', '', 'https://' ) as $bad ) {
				$entries[] = array(
					'host'    => $bad,
					'service' => 'Mauvais',
				) + $base;
			}
			$entries[] = array(
				'host'    => 'cdn.exemple.org',
				'service' => 'Premier',
			) + $base;
			$entries[] = array(
				'host'    => 'CDN.exemple.org',
				'service' => 'Second',
			) + $base;
			$entries[] = array(
				'host'    => 'xss.exemple.org',
				'service' => '<script>alert(1)</script>',
			) + $base;
			return $entries;
		}
	);
	$fluffless_all = Hosts::all();
	$fluffless_cdn = $fluffless_find( $fluffless_all, 'cdn.exemple.org' );
	$fluffless_assert( null !== $fluffless_cdn && array( 'Premier', 'Second' ) === $fluffless_cdn['service'], 'filtre : deux entrées identiques, une ligne, deux services' );
	$fluffless_assert( array() === array_filter( $fluffless_all, static fn( array $e ): bool => in_array( 'Mauvais', $e['service'], true ) ), 'filtre : domaines invalides ignorés' );
	$fluffless_html = $fluffless_render();
	$fluffless_assert( ! str_contains( $fluffless_html, '<script>alert' ), 'échappement : aucun script venu du filtre' );

	// 14. Requêtes sortantes allumé, liste par défaut.
	$fluffless_case( array( 'modules' => array( 'http' => true ) ), array( Sections::VIDEO_OPTION => '1' ) );
	$fluffless_all = Hosts::all();
	$fluffless_assert( 'active' === ( $fluffless_find( $fluffless_all, 'api.wordpress.org', 'server' )['status'] ?? '' ), 'bloqué : api.wordpress.org permis (wordpress.org)' );
	$fluffless_assert( 'blocked' === ( $fluffless_find( $fluffless_all, 'rpc.pingomatic.com' )['status'] ?? '' ), 'bloqué : rpc.pingomatic.com' );
	$fluffless_assert( 'blocked' === ( $fluffless_find( $fluffless_all, 's.w.org', 'server' )['status'] ?? '' ), 'bloqué : s.w.org (Bibliothèque de polices)' );
	$fluffless_assert( 'blocked' === ( $fluffless_find( $fluffless_all, 'api.thegreenwebfoundation.org' )['status'] ?? '' ), 'bloqué : Green Web Foundation (F-serveurs-contactes-01, visible)' );
	$fluffless_assert( 'blocked_linked' === ( $fluffless_linked( $fluffless_all, 'embedded address' )['status'] ?? '' ), 'bloqué : oEmbed, sauf les domaines permis' );
	$fluffless_assert( 'active' === ( $fluffless_find( $fluffless_all, 'secure.gravatar.com' )['status'] ?? '' ) && 'active' === ( $fluffless_find( $fluffless_all, 'api.openverse.org' )['status'] ?? '' ), 'bloqué : navigateur jamais bloqué (Gravatar, Openverse)' );
	$fluffless_assert( 'active' === ( $fluffless_find( $fluffless_all, 'www.youtube-nocookie.com' )['status'] ?? '' ), 'bloqué : bloc Vidéo (navigateur) jamais bloqué' );
	$fluffless_assert( 'active' === ( $fluffless_find( $fluffless_all, Api::host() )['status'] ?? '' ), 'bloqué : API Fluffless toujours permise' );
	$fluffless_hook(
		'fluffless_outgoing_hosts',
		static fn( $entries ) => array_merge(
			(array) $entries,
			array(
				array(
					'host'    => 'sous.wordpress.org',
					'service' => 'Sous-domaine',
					'side'    => 'server',
				),
			)
		)
	);
	$fluffless_assert( 'active' === ( $fluffless_find( Hosts::all(), 'sous.wordpress.org' )['status'] ?? '' ), 'bloqué : sous-domaine d\'une entrée permise, permis' );
	$fluffless_case(
		array(
			'modules' => array(
				'http' => true,
				'mail' => true,
			),
			'mail'    => array( 'host' => 'smtp.exemple.ca' ),
		)
	);
	$fluffless_assert( 'active' === ( $fluffless_find( Hosts::all(), 'smtp.exemple.ca' )['status'] ?? '' ), 'bloqué : SMTP hors API HTTP, jamais bloqué' );

	// 15. Liste effective : réglage, API, infolettre (non-régression de Http::filter()).
	putenv( 'FLUFFLESS_NEWSLETTER_KEY=' . $fluffless_key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- variable lue par le code testé, remise à la fin.
	$fluffless_case(
		array(
			'modules'    => array(
				'http'       => true,
				'form'       => true,
				'newsletter' => true,
			),
			'http'       => array( 'allowed_hosts' => array( 'exemple.net' ) ),
			'newsletter' => array( 'provider' => 'mailchimp' ),
		)
	);
	$fluffless_assert( array( 'exemple.net', Api::host(), Newsletter::host() ) === Http::effective_allowed(), 'liste effective : ' . wp_json_encode( Http::effective_allowed() ) );
	putenv( 'FLUFFLESS_NEWSLETTER_KEY' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- variable lue par le code testé, remise à la fin.

	// 16. Écran : phrases, champ à copier, script partagé.
	$fluffless_case( array() );
	$fluffless_html = $fluffless_render();
	$fluffless_assert( str_contains( $fluffless_html, 'Every request from your server tells WordPress and the services above your site’s address, except the Fluffless API.' ), 'écran : phrase User-Agent' );
	$fluffless_assert( str_contains( $fluffless_html, 'Other plugins can contact other servers.' ), 'écran : phrase de limite' );
	$fluffless_assert( 1 === preg_match( '/<textarea[^>]*readonly[^>]*>[^<]*api\.wordpress\.org/', $fluffless_html ), 'écran : liste en lecture seule, lisible sans JS' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- domaine dans l'expression.
	$fluffless_assert( str_contains( $fluffless_html, 'data-fl-copy-done="List copied."' ) && str_contains( $fluffless_html, 'hidden>' ), 'écran : bouton caché, message de réussite rendu par PHP' );
	$fluffless_assert( wp_script_is( 'fluffless-copy', 'enqueued' ) && ! wp_script_is( 'fluffless-report', 'enqueued' ), 'écran : copy.js en file, pas report.js' );
	$fluffless_assert( str_contains( $fluffless_html, '<h2>Your server</h2>' ) && str_contains( $fluffless_html, 'Visitors’ browsers' ) && str_contains( $fluffless_html, 'People who manage the site' ), 'écran : trois côtés' );

	// 17. Vus au dernier Bilan.
	delete_transient( Lifecycle::TRANSIENT_AUDIT_REPORT );
	$fluffless_assert( str_contains( $fluffless_render(), 'Run the Fluff check to see what your home page loads.' ), 'bilan absent : message « Lance le Bilan »' );
	set_transient(
		Lifecycle::TRANSIENT_AUDIT_REPORT,
		array(
			'checked'           => time(),
			'url'               => home_url( '/' ),
			'local'             => true,
			'items'             => array(),
			'third_party_hosts' => array( 'cdn.exemple.net' ),
		),
		HOUR_IN_SECONDS
	);
	$fluffless_assert( str_contains( $fluffless_render(), '<code>cdn.exemple.net</code>' ), 'bilan : cdn.exemple.net affiché' );
	$fluffless_assert(
		array( 'cdn.exemple.net' ) === Audit::third_party_hosts(
			array(
				array(
					'url'  => 'https://cdn.exemple.net/a.js',
					'type' => 'js',
				),
				array(
					'url'  => home_url( '/wp-content/b.css' ),
					'type' => 'css',
				),
				array(
					'url'  => 'https://CDN.exemple.net/c.js',
					'type' => 'js',
				),
			)
		),
		'bilan : domaines tiers des fichiers, le site exclu'
	);

	// 17 bis. Requêtes sortantes : ses textes nomment ce qu'il bloque du cœur, son panneau renvoie à l'écran.
	$fluffless_assert( str_contains( Http::description(), 'Font Library' ) && str_contains( Http::description(), 'embeds' ), 'requêtes sortantes : la description ne nomme pas les intégrations ni la Bibliothèque de polices' );
	$fluffless_assert( str_contains( Cleanup::fields()['oembed']['help'] ?? '', 'unless Outgoing requests blocks them' ), 'nettoyage : l\'aide des intégrations ne parle pas de Requêtes sortantes' );
	$fluffless_case( array( 'modules' => array( 'http' => true ) ) );
	ob_start();
	SettingsPage::render();
	$fluffless_page = (string) ob_get_clean();
	$fluffless_assert( 1 === preg_match( '#<a href="[^"]*page=' . Screen::SLUG . '"[^>]*>See which servers your site contacts</a>#', $fluffless_page ), 'requêtes sortantes : aucun lien vers Serveurs contactés dans son panneau' );

	// 18. Capacité : rien pour un visiteur.
	wp_set_current_user( 0 );
	$fluffless_assert( '' === $fluffless_render(), 'capacité : rien sans manage_options' );
	wp_set_current_user( $fluffless_admin );

	// 19. Aucun appel réseau dans tout ce qui précède.
	$fluffless_assert( 0 === $fluffless_calls, 'aucun appel réseau : ' . $fluffless_calls );
	$fluffless_assert( false === get_option( 'fluffless_hosts' ), 'aucune option fluffless_hosts créée' );
} finally {
	$fluffless_unhook();
	remove_filter( 'pre_http_request', $fluffless_net, 1 );
	if ( false === $fluffless_backup_report ) {
		delete_transient( Lifecycle::TRANSIENT_AUDIT_REPORT );
	} else {
		set_transient( Lifecycle::TRANSIENT_AUDIT_REPORT, $fluffless_backup_report, DAY_IN_SECONDS );
	}
	putenv( false === $fluffless_env_key ? 'FLUFFLESS_STATS_API_KEY' : 'FLUFFLESS_STATS_API_KEY=' . $fluffless_env_key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- variable lue par le code testé, remise à la fin.
	putenv( false === $fluffless_env_news ? 'FLUFFLESS_NEWSLETTER_KEY' : 'FLUFFLESS_NEWSLETTER_KEY=' . $fluffless_env_news ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- variable lue par le code testé, remise à la fin.
	wp_set_current_user( 0 );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}

WP_CLI::success( 'Serveurs contactés : ' . $fluffless_checks . ' vérifications passées.' );
