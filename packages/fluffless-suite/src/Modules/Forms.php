<?php
/**
 * Formulaires sobres : les extensions de formulaires établies restent sur les pages qui en ont.
 *
 * Contact Form 7 et Formidable envoient leurs feuilles et scripts sur toutes les pages
 * publiques ; WPForms, Fluent Forms et Gravity Forms seulement là où un formulaire est rendu,
 * mais avec jQuery. Ce module retire tout cela des pages sans formulaire et lit leurs
 * réglages de captcha pour le Bilan de sobriété. L'accord visuel (couleurs, polices, formes,
 * focus du thème) vit dans le thème : assets/css/forms.css.
 *
 * @package Fluffless_Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Module Formulaires sobres.
 *
 * @phpstan-type Plugin array{label: string, active: string, shortcodes: list<string>, blocks: list<string>, handles: list<string>}
 */
final class Forms implements Module {

	/** Services de captcha tiers reconnus, par domaine chargé. */
	private const CAPTCHA_HOSTS = array(
		'google.com/recaptcha'      => 'reCAPTCHA',
		'gstatic.com/recaptcha'     => 'reCAPTCHA',
		'recaptcha.net'             => 'reCAPTCHA',
		'hcaptcha.com'              => 'hCaptcha',
		'challenges.cloudflare.com' => 'Turnstile',
	);

	/**
	 * Un formulaire d'une extension établie a été rendu sur la page en cours.
	 *
	 * @var bool
	 */
	private static bool $rendered = false;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'forms';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Lean forms', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Keeps Contact Form 7, WPForms, Fluent Forms, Formidable and Gravity Forms on the pages that hold a form. Their styles and scripts leave the rest of the site.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'assets' => true,
		);
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
		return array(
			'assets' => array(
				'type'  => 'bool',
				'label' => __( 'Load form extensions only on pages with a form', 'fluffless-suite' ),
				'help'  => __( 'A page counts when it holds one of their blocks or shortcodes, in its content or in its template. Everywhere else, their styles and scripts (jQuery included) are not sent. The theme dresses their forms in its own colours, fonts and shapes on the pages that keep them.', 'fluffless-suite' ),
			),
		);
	}

	/**
	 * Extensions reconnues : libellé, constante ou classe qui dit qu'elle est active, codes courts,
	 * préfixes de blocs et identifiants à retirer en plus des fichiers de son dossier.
	 *
	 * @return array<string, Plugin>
	 */
	public static function plugins(): array {
		return array(
			'cf7'        => array(
				'label'      => 'Contact Form 7',
				'active'     => 'WPCF7_PLUGIN',
				'shortcodes' => array( 'contact-form-7', 'contact-form' ),
				'blocks'     => array( 'contact-form-7/' ),
				'handles'    => array(),
			),
			'wpforms'    => array(
				'label'      => 'WPForms',
				'active'     => 'WPFORMS_PLUGIN_URL',
				'shortcodes' => array( 'wpforms' ),
				'blocks'     => array( 'wpforms/' ),
				'handles'    => array(),
			),
			'fluent'     => array(
				'label'      => 'Fluent Forms',
				'active'     => 'FLUENTFORM_DIR_PATH',
				'shortcodes' => array( 'fluentform', 'fluentform_info', 'fluentform_modal' ),
				'blocks'     => array( 'fluentfom/', 'fluentform/' ),
				'handles'    => array(),
			),
			'formidable' => array(
				'label'      => 'Formidable',
				'active'     => 'FrmAppHelper',
				'shortcodes' => array( 'formidable', 'display-frm-data', 'frm_gated_content' ),
				'blocks'     => array( 'formidable/' ),
				'handles'    => array( 'formidable', 'frm-custom-theme' ),
			),
			'gravity'    => array(
				'label'      => 'Gravity Forms',
				'active'     => 'GF_PLUGIN_DIR_PATH',
				'shortcodes' => array( 'gravityform', 'gravityforms' ),
				'blocks'     => array( 'gravityforms/' ),
				'handles'    => array(),
			),
		);
	}

	/**
	 * Clés des extensions actives.
	 *
	 * @return list<string>
	 */
	public static function active_plugins(): array {
		$active = array();
		foreach ( self::plugins() as $key => $plugin ) {
			if ( defined( $plugin['active'] ) || class_exists( $plugin['active'] ) ) {
				$active[] = $key;
			}
		}
		return $active;
	}

	/** {@inheritDoc} */
	public static function status(): string {
		if ( array() === self::active_plugins() ) {
			return __( 'No known form extension is active: this module has nothing to do until one is. The Form block of this suite needs nothing from it. If you only use the Form block, you can turn this module off.', 'fluffless-suite' );
		}
		return '';
	}

	/** {@inheritDoc} */
	public function register(): void {
		if ( array() === self::active_plugins() || ! Options::get_bool( self::id(), 'assets' ) ) {
			return;
		}
		add_filter( 'render_block', array( self::class, 'note_block' ), 10, 2 );
		add_filter( 'do_shortcode_tag', array( self::class, 'note_shortcode' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'dequeue_off_forms' ), 100 );
		add_action( 'wp_print_footer_scripts', array( self::class, 'dequeue_off_forms' ), 1 );
		// Contact Form 7 demande avant d'ajouter sa feuille et ses scripts : lui répondre.
		add_filter( 'wpcf7_load_js', array( self::class, 'is_form_page' ) );
		add_filter( 'wpcf7_load_css', array( self::class, 'is_form_page' ) );
	}

	/**
	 * Note qu'un bloc d'une extension de formulaires a été rendu (les thèmes de blocs rendent le gabarit avant wp_head).
	 *
	 * @param mixed $content Rendu du bloc.
	 * @param mixed $block   Bloc analysé.
	 * @return mixed
	 */
	public static function note_block( $content, $block ) {
		$name = is_array( $block ) ? ( $block['blockName'] ?? '' ) : '';
		if ( is_string( $name ) && self::is_form_block( $name ) ) {
			self::$rendered = true;
		}
		return $content;
	}

	/**
	 * Note qu'un code court d'une extension de formulaires a été rendu.
	 *
	 * @param mixed $output Rendu du code court.
	 * @param mixed $tag    Nom du code court.
	 * @return mixed
	 */
	public static function note_shortcode( $output, $tag ) {
		if ( is_string( $tag ) && self::is_form_shortcode( $tag ) ) {
			self::$rendered = true;
		}
		return $output;
	}

	/** Oublie le formulaire noté (tests). */
	public static function reset(): void {
		self::$rendered = false;
	}

	/**
	 * Nom de bloc d'une extension active.
	 *
	 * @param string                  $name    Nom du bloc.
	 * @param array<int, string>|null $plugins Clés à considérer ; les extensions actives par défaut.
	 */
	public static function is_form_block( string $name, ?array $plugins = null ): bool {
		$table = self::plugins();
		foreach ( $plugins ?? self::active_plugins() as $key ) {
			foreach ( $table[ $key ]['blocks'] ?? array() as $prefix ) {
				if ( str_starts_with( $name, $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Code court d'une extension active.
	 *
	 * @param string                  $tag     Nom du code court.
	 * @param array<int, string>|null $plugins Clés à considérer ; les extensions actives par défaut.
	 */
	public static function is_form_shortcode( string $tag, ?array $plugins = null ): bool {
		$table = self::plugins();
		foreach ( $plugins ?? self::active_plugins() as $key ) {
			if ( in_array( $tag, $table[ $key ]['shortcodes'] ?? array(), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Le contenu contient un bloc ou un code court d'une extension active.
	 *
	 * @param string                  $content Contenu brut d'un article ou d'une page.
	 * @param array<int, string>|null $plugins Clés à considérer ; les extensions actives par défaut.
	 */
	public static function has_form_content( string $content, ?array $plugins = null ): bool {
		$plugins ??= self::active_plugins();
		if ( array() === $plugins ) {
			return false;
		}
		preg_match_all( '/<!-- wp:([a-z0-9-]+\/[a-z0-9-]+)/', $content, $blocks );
		foreach ( $blocks[1] as $name ) {
			if ( self::is_form_block( $name, $plugins ) ) {
				return true;
			}
		}
		if ( ! str_contains( $content, '[' ) ) {
			return false;
		}
		preg_match_all( '/\[([a-z0-9_-]+)/', $content, $tags );
		foreach ( $tags[1] as $tag ) {
			if ( self::is_form_shortcode( $tag, $plugins ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * La page en cours porte un formulaire d'une extension établie : elle y garde tout.
	 *
	 * Signature souple : Contact Form 7 passe son propre booléen par filtre, ignoré ici.
	 *
	 * @param mixed $ignored Valeur passée par un filtre tiers.
	 */
	public static function is_form_page( $ignored = null ): bool {
		unset( $ignored );
		$form = self::$rendered;
		if ( ! $form && is_singular() ) {
			$post = get_post();
			$form = $post instanceof \WP_Post && self::has_form_content( (string) $post->post_content );
		}
		/**
		 * Force ou empêche le retrait des extensions de formulaires sur la page en cours.
		 *
		 * @param bool $form True si la page a besoin d'une extension de formulaires.
		 */
		return (bool) apply_filters( 'fluffless_forms_page', $form );
	}

	/**
	 * Adresses des dossiers des extensions actives, base de leurs feuilles et scripts.
	 *
	 * @return list<string>
	 */
	public static function plugin_urls(): array {
		$urls = array();
		foreach ( self::active_plugins() as $key ) {
			$url = self::plugin_url( $key );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	/**
	 * Adresse du dossier d'une extension active.
	 *
	 * @param string $key Clé de l'extension.
	 */
	public static function plugin_url( string $key ): string {
		switch ( $key ) {
			case 'cf7':
				return defined( 'WPCF7_PLUGIN' ) ? plugin_dir_url( (string) constant( 'WPCF7_PLUGIN' ) ) : '';
			case 'wpforms':
				return defined( 'WPFORMS_PLUGIN_URL' ) ? trailingslashit( (string) constant( 'WPFORMS_PLUGIN_URL' ) ) : '';
			case 'fluent':
				return defined( 'FLUENTFORM_DIR_PATH' ) ? plugin_dir_url( trailingslashit( (string) constant( 'FLUENTFORM_DIR_PATH' ) ) . 'fluentform.php' ) : '';
			case 'formidable':
				return class_exists( 'FrmAppHelper' ) ? trailingslashit( \FrmAppHelper::plugin_url() ) : '';
			case 'gravity':
				return defined( 'GF_PLUGIN_DIR_PATH' ) ? plugin_dir_url( trailingslashit( (string) constant( 'GF_PLUGIN_DIR_PATH' ) ) . 'gravityforms.php' ) : '';
		}
		return '';
	}

	/**
	 * Identifiants à retirer par leur nom, quand la source ne pointe pas dans le dossier de l'extension
	 * (Formidable sert sa feuille par admin-ajax.php).
	 *
	 * @return list<string>
	 */
	public static function extra_handles(): array {
		$handles = array();
		foreach ( self::active_plugins() as $key ) {
			foreach ( self::plugins()[ $key ]['handles'] as $handle ) {
				$handles[] = $handle;
			}
		}
		return $handles;
	}

	/**
	 * L'identifiant enregistré pointe dans le dossier d'une extension de formulaires.
	 *
	 * @param \_WP_Dependency|null $item  Dépendance enregistrée.
	 * @param array<int, string>   $bases Adresses des dossiers.
	 */
	private static function from_plugin( ?\_WP_Dependency $item, array $bases ): bool {
		if ( null === $item || ! is_string( $item->src ) ) {
			return false;
		}
		foreach ( $bases as $base ) {
			if ( str_starts_with( $item->src, $base ) ) {
				return true;
			}
		}
		return false;
	}

	/** Retire les feuilles et scripts des extensions de formulaires quand la page n'a pas de formulaire. */
	public static function dequeue_off_forms(): void {
		if ( self::is_form_page() ) {
			return;
		}
		$bases   = self::plugin_urls();
		$extra   = self::extra_handles();
		$styles  = wp_styles();
		$scripts = wp_scripts();
		foreach ( $styles->queue as $handle ) {
			if ( in_array( $handle, $extra, true ) || self::from_plugin( $styles->registered[ $handle ] ?? null, $bases ) ) {
				wp_dequeue_style( $handle );
			}
		}
		foreach ( $scripts->queue as $handle ) {
			if ( in_array( $handle, $extra, true ) || self::from_plugin( $scripts->registered[ $handle ] ?? null, $bases ) ) {
				wp_dequeue_script( $handle );
			}
		}
	}

	/**
	 * Services de captcha tiers dont le HTML charge un script ou un cadre.
	 *
	 * @param string $html Page rendue.
	 * @return list<string> Noms de services, sans doublon.
	 */
	public static function captcha_hosts_in_html( string $html ): array {
		$found = array();
		foreach ( self::CAPTCHA_HOSTS as $host => $service ) {
			if ( str_contains( $html, $host ) && ! in_array( $service, $found, true ) ) {
				$found[] = $service;
			}
		}
		return $found;
	}

	/**
	 * Services de captcha tiers réglés dans les extensions actives (clé publique renseignée).
	 *
	 * @return array<string, string> Libellé de l'extension => service.
	 */
	public static function captcha_services(): array {
		$services = array();
		foreach ( self::active_plugins() as $key ) {
			$service = self::captcha_service( $key );
			if ( '' !== $service ) {
				$services[ self::plugins()[ $key ]['label'] ] = $service;
			}
		}
		return $services;
	}

	/**
	 * Service de captcha réglé dans une extension, ou chaîne vide.
	 *
	 * @param string $key Clé de l'extension.
	 */
	public static function captcha_service( string $key ): string {
		switch ( $key ) {
			case 'cf7':
				$all  = get_option( 'wpcf7' );
				$keys = is_array( $all ) ? ( $all['recaptcha'] ?? array() ) : array();
				return is_array( $keys ) && array() !== array_filter( array_keys( $keys ) ) ? 'reCAPTCHA' : '';
			case 'wpforms':
				$settings = get_option( 'wpforms_settings' );
				$settings = is_array( $settings ) ? $settings : array();
				$provider = (string) ( $settings['captcha-provider'] ?? '' );
				$keys     = array(
					'recaptcha' => 'reCAPTCHA',
					'hcaptcha'  => 'hCaptcha',
					'turnstile' => 'Turnstile',
				);
				return isset( $keys[ $provider ] ) && '' !== (string) ( $settings[ $provider . '-site-key' ] ?? '' ) ? $keys[ $provider ] : '';
			case 'fluent':
				$options = array(
					'_fluentform_reCaptcha_details' => 'reCAPTCHA',
					'_fluentform_hCaptcha_details'  => 'hCaptcha',
					'_fluentform_turnstile_details' => 'Turnstile',
				);
				foreach ( $options as $option => $service ) {
					$details = get_option( $option );
					if ( is_array( $details ) && '' !== (string) ( $details['siteKey'] ?? '' ) ) {
						return $service;
					}
				}
				return '';
			case 'formidable':
				$settings = get_option( 'frm_options' );
				$settings = is_object( $settings ) ? get_object_vars( $settings ) : ( is_array( $settings ) ? $settings : array() );
				$active   = (string) ( $settings['active_captcha'] ?? 'recaptcha' );
				$keys     = array(
					'recaptcha' => array( 'pubkey', 'reCAPTCHA' ),
					'hcaptcha'  => array( 'hcaptcha_pubkey', 'hCaptcha' ),
					'turnstile' => array( 'turnstile_pubkey', 'Turnstile' ),
				);
				return isset( $keys[ $active ] ) && '' !== (string) ( $settings[ $keys[ $active ][0] ] ?? '' ) ? $keys[ $active ][1] : '';
			case 'gravity':
				return '' !== (string) get_option( 'rg_gforms_captcha_public_key', '' ) ? 'reCAPTCHA' : '';
		}
		return '';
	}
}
