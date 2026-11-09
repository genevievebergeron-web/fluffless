<?php
/**
 * Écran Réglages > Fluffless SEO : réglages, redirections et journal, importation.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo\Admin;

use Fluffless\Seo\Context;
use Fluffless\Seo\Importer;
use Fluffless\Seo\Settings;
use Fluffless\Seo\Store;
use Fluffless\Suite\Admin\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * Composants et feuille de style de Fluffless Suite (Admin\Screen), sans copie : l'écran
 * s'appelle fluffless-seo, donc la feuille commune se charge et la portée .fluffless-screen s'applique.
 *
 * Le formulaire des réglages poste vers options.php (nonce et capacité gérés par WordPress).
 * Les actions sur les tables passent par admin-post.php, chacune avec son nonce.
 */
final class SettingsScreen {

	public const PAGE       = 'fluffless-seo';
	public const CAPABILITY = 'manage_options';

	/** Envois CSV acceptés jusqu'à 1 Mo. */
	private const MAX_CSV_BYTES = 1048576;

	/** Actions admin-post => méthode. */
	private const ACTIONS = array(
		'fluffless_seo_redirect_add'    => 'handle_add',
		'fluffless_seo_redirect_delete' => 'handle_delete',
		'fluffless_seo_redirect_csv'    => 'handle_csv',
		'fluffless_seo_404_purge'       => 'handle_purge',
		'fluffless_seo_import'          => 'handle_import',
	);

	/** Pose les crochets d'administration. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( Settings::class, 'register' ) );
		add_action( 'after_plugin_row_' . plugin_basename( \Fluffless\Seo\FILE ), array( self::class, 'render_deletion_notice' ), 10, 0 );
		foreach ( self::ACTIONS as $action => $method ) {
			add_action( 'admin_post_' . $action, array( self::class, $method ) );
		}
	}

	/**
	 * Ligne sous l'extension dans l'écran Extensions : ce que « Supprimer » efface, avant que la personne clique.
	 * Absente quand aucune règle n'existe ou que les données sont gardées (C-S05-04).
	 */
	public static function render_deletion_notice(): void {
		if ( Settings::keeps_data() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$total = Store::rule_count();
		if ( $total < 1 ) {
			return;
		}
		printf(
			'<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>%1$s <a href="%2$s">%3$s</a></p></div></td></tr>',
			esc_html(
				sprintf(
					/* translators: %s: number of redirect rules. */
					_n(
						'Deleting this plugin permanently erases %s redirect rule, the not found log and the SEO settings. Turn on “Keep my data when the plugin is deleted” to keep them.',
						'Deleting this plugin permanently erases %s redirect rules, the not found log and the SEO settings. Turn on “Keep my data when the plugin is deleted” to keep them.',
						$total,
						'fluffless-seo'
					),
					number_format_i18n( $total )
				)
			),
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE . '#fl-group-crawl' ) ),
			esc_html__( 'Open the SEO settings', 'fluffless-seo' )
		);
	}

	/** Page sous Réglages. */
	public static function add_page(): void {
		add_options_page( __( 'Fluffless SEO', 'fluffless-seo' ), __( 'Fluffless SEO', 'fluffless-seo' ), self::CAPABILITY, self::PAGE, array( self::class, 'render' ) );
		Screen::register();
	}

	/** Onglet demandé. */
	private static function tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation, aucune écriture.
		return in_array( $tab, array( 'settings', 'redirects', 'import' ), true ) ? $tab : 'settings';
	}

	/**
	 * Adresse de l'écran.
	 *
	 * @param string $tab Onglet.
	 */
	public static function url( string $tab = 'settings' ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	}

	/** Rendu. */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$tab = self::tab();
		echo '<div class="wrap fl-settings">';
		Screen::header( __( 'Fluffless SEO', 'fluffless-seo' ), __( 'Titles, sharing, structured data and redirects, written on the server. Your visitors download no script for it.', 'fluffless-seo' ) );
		self::notice();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Fluffless SEO sections', 'fluffless-seo' ) . '">';
		foreach ( array(
			'settings'  => __( 'Settings', 'fluffless-seo' ),
			'redirects' => __( 'Redirects and not found', 'fluffless-seo' ),
			'import'    => __( 'Import', 'fluffless-seo' ),
		) as $id => $label ) {
			printf(
				'<a class="nav-tab%1$s" href="%2$s"%3$s>%4$s</a>',
				$id === $tab ? ' nav-tab-active' : '',
				esc_url( self::url( $id ) ),
				$id === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		match ( $tab ) {
			'redirects' => self::render_redirects(),
			'import'    => self::render_import(),
			default     => self::render_settings(),
		};
		echo '</div>';
	}

	/**
	 * Groupes de l'onglet Réglages : titre, phrase, cartes (famille, titre, description, champs).
	 *
	 * @return array<string, array{title: string, help: string, cards: list<array{family: string, title: string, desc: string, fields: list<string>}>}>
	 */
	private static function groups(): array {
		return array(
			'search'  => array(
				'title' => __( 'Search results', 'fluffless-seo' ),
				'help'  => __( 'What search engines show for each page.', 'fluffless-seo' ),
				'cards' => array(
					array(
						'family' => 'titles',
						'title'  => __( 'Titles and descriptions', 'fluffless-seo' ),
						'desc'   => __( 'Title tag, meta description and canonical address, from each page’s fields or the templates below.', 'fluffless-seo' ),
						'fields' => array( 'sep', 'noindex_archives', 'noindex_taxonomies' ),
					),
					array(
						'family' => 'analysis',
						'title'  => __( 'Keyword analysis in the editor', 'fluffless-seo' ),
						'desc'   => __( 'Checks run in the writer’s browser. Nothing is sent anywhere.', 'fluffless-seo' ),
						'fields' => array(),
					),
				),
			),
			'sharing' => array(
				'title' => __( 'Sharing', 'fluffless-seo' ),
				'help'  => __( 'The card shown when a page is shared on social networks and messaging apps.', 'fluffless-seo' ),
				'cards' => array(
					array(
						'family' => 'social',
						'title'  => __( 'Open Graph and X cards', 'fluffless-seo' ),
						'desc'   => __( 'Title, description and image for sharing.', 'fluffless-seo' ),
						'fields' => array( 'default_image', 'x_site' ),
					),
				),
			),
			'schema'  => array(
				'title' => __( 'Structured data', 'fluffless-seo' ),
				'help'  => __( 'One JSON-LD graph per page, describing who publishes the site and what the page is.', 'fluffless-seo' ),
				'cards' => array(
					array(
						'family' => 'schema',
						'title'  => __( 'Identity and page types', 'fluffless-seo' ),
						'desc'   => __( 'The type of each page is chosen in the editor; articles are described automatically.', 'fluffless-seo' ),
						'fields' => array( 'entity', 'entity_name', 'logo', 'same_as' ),
					),
					array(
						'family' => '',
						'title'  => __( 'Local business details', 'fluffless-seo' ),
						'desc'   => __( 'Used when this site represents a local business.', 'fluffless-seo' ),
						'fields' => array( 'business_type', 'street', 'city', 'region', 'postal', 'country', 'phone', 'price_range', 'hours', 'area_served' ),
					),
					array(
						'family' => 'faq_schema',
						'title'  => __( 'FAQ page data', 'fluffless-seo' ),
						'desc'   => __( 'Describes the Details blocks of a page as questions and answers. Turn off when the questions are not a real FAQ.', 'fluffless-seo' ),
						'fields' => array(),
					),
					array(
						'family' => 'breadcrumbs',
						'title'  => __( 'Breadcrumbs', 'fluffless-seo' ),
						'desc'   => __( 'Adds the Breadcrumb block and its structured data.', 'fluffless-seo' ),
						'fields' => array( 'bc_home' ),
					),
				),
			),
			'crawl'   => array(
				'title' => __( 'Crawlers', 'fluffless-seo' ),
				'help'  => __( 'Files and signals read by search engines and other robots.', 'fluffless-seo' ),
				'cards' => array(
					array(
						'family' => 'sitemaps',
						'title'  => __( 'Sitemap', 'fluffless-seo' ),
						'desc'   => __( 'Adjusts the sitemap built into WordPress. Pages set to noindex are left out.', 'fluffless-seo' ),
						'fields' => array( 'sitemap_exclude', 'sitemap_exclude_tax', 'sitemap_users', 'sitemap_lastmod' ),
					),
					array(
						'family' => 'robots_txt',
						'title'  => __( 'Custom robots.txt', 'fluffless-seo' ),
						'desc'   => __( 'Served on the fly; no file is written on the server.', 'fluffless-seo' ),
						'fields' => array( 'robots_txt_content' ),
					),
					array(
						'family' => 'llms',
						'title'  => __( 'llms.txt', 'fluffless-seo' ),
						'desc'   => __( 'A plain text summary of the site for language model tools.', 'fluffless-seo' ),
						'fields' => array( 'llms_content' ),
					),
					array(
						'family' => 'indexnow',
						'title'  => __( 'IndexNow', 'fluffless-seo' ),
						'desc'   => __( 'Tells Bing, Yandex and others when a page is published. Sends one request to api.indexnow.org per publication, without waiting for the answer. Allow this host in the Fluffless Suite outgoing requests list if it is filtered.', 'fluffless-seo' ),
						'fields' => array(),
					),
					array(
						'family' => '',
						'title'  => __( 'Webmaster tools verification', 'fluffless-seo' ),
						'desc'   => __( 'Written on the home page only.', 'fluffless-seo' ),
						'fields' => array( 'verify_google', 'verify_bing' ),
					),
					array(
						'family' => '',
						'title'  => __( 'When the plugin is deleted', 'fluffless-seo' ),
						'desc'   => __( 'WordPress asks no confirmation before deleting a plugin. Turn this on to keep your redirects and settings.', 'fluffless-seo' ),
						'fields' => array( 'keep_data' ),
					),
				),
			),
			'moves'   => array(
				'title' => __( 'Moved pages', 'fluffless-seo' ),
				'help'  => __( 'Rules and the not found log are managed in the Redirects tab.', 'fluffless-seo' ),
				'cards' => array(
					array(
						'family' => 'redirects',
						'title'  => __( 'Redirects', 'fluffless-seo' ),
						'desc'   => __( '301, 302, 307 and 410, with optional regular expressions.', 'fluffless-seo' ),
						'fields' => array( 'redirect_everywhere' ),
					),
					array(
						'family' => 'log404',
						'title'  => __( 'Not found log', 'fluffless-seo' ),
						'desc'   => __( 'Keeps the path, a counter and the last visit date for 90 days. No IP address, no browser details.', 'fluffless-seo' ),
						'fields' => array(),
					),
				),
			),
			'lang'    => array(
				'title' => __( 'Languages', 'fluffless-seo' ),
				'help'  => __( 'With Polylang, each page points to its translations.', 'fluffless-seo' ),
				'cards' => array(
					array(
						'family' => 'hreflang',
						'title'  => __( 'hreflang and og:locale', 'fluffless-seo' ),
						'desc'   => __( 'Alternate language links and sharing locale. Without Polylang, only the site language is written.', 'fluffless-seo' ),
						'fields' => array( 'hreflang_default' ),
					),
				),
			),
		);
	}

	/** Onglet Réglages. */
	private static function render_settings(): void {
		$groups = self::groups();
		echo '<nav aria-label="' . esc_attr__( 'Setting groups', 'fluffless-seo' ) . '"><ul class="fl-jump">';
		foreach ( $groups as $gid => $group ) {
			printf( '<li><a href="#fl-group-%1$s">%2$s</a></li>', esc_attr( $gid ), esc_html( $group['title'] ) );
		}
		echo '<li><a href="#fl-group-templates">' . esc_html__( 'Templates', 'fluffless-seo' ) . '</a></li></ul></nav>';
		echo '<form action="' . esc_url( admin_url( 'options.php' ) ) . '" method="post">';
		settings_fields( Settings::GROUP );
		$fields = Settings::fields();
		foreach ( $groups as $gid => $group ) {
			printf(
				'<section class="fl-group" id="fl-group-%1$s" aria-labelledby="fl-group-%1$s-title"><h2 id="fl-group-%1$s-title">%2$s</h2><p>%3$s</p><div class="fl-grid">',
				esc_attr( $gid ),
				esc_html( $group['title'] ),
				esc_html( $group['help'] )
			);
			foreach ( $group['cards'] as $index => $card ) {
				self::card( $gid . '-' . $index, $card, $fields );
			}
			echo '</div></section>';
		}
		self::templates();
		echo '<div class="fl-savebar">';
		submit_button( __( 'Save changes', 'fluffless-seo' ), 'primary', 'submit', false );
		echo '</div></form>';
	}

	/**
	 * Carte : titre, description, interrupteur de famille, champs.
	 *
	 * @param string                                                                   $id     Identifiant de carte.
	 * @param array{family: string, title: string, desc: string, fields: list<string>} $card   Carte.
	 * @param array<string, array<string, mixed>>                                      $fields Champs connus.
	 */
	private static function card( string $id, array $card, array $fields ): void {
		$title_id = 'fl-card-' . $id . '-title';
		$desc_id  = 'fl-card-' . $id . '-desc';
		printf( '<section class="fl-card" aria-labelledby="%s"><div class="fl-card__head"><div>', esc_attr( $title_id ) );
		printf( '<h3 id="%1$s">%2$s</h3><p id="%3$s" class="fl-muted">%4$s</p></div>', esc_attr( $title_id ), esc_html( $card['title'] ), esc_attr( $desc_id ), esc_html( $card['desc'] ) );
		if ( '' !== $card['family'] ) {
			echo Screen::switch_control( self::name( $card['family'] ), self::id( $card['family'] ), Settings::on( $card['family'] ), $title_id, $desc_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::switch_control().
		}
		echo '</div>';
		foreach ( $card['fields'] as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				self::field( $key, $fields[ $key ] );
			}
		}
		echo '</section>';
	}

	/**
	 * Nom de champ du formulaire.
	 *
	 * @param string ...$keys Clés imbriquées.
	 */
	private static function name( string ...$keys ): string {
		return Settings::NAME . '[' . implode( '][', $keys ) . ']';
	}

	/**
	 * Identifiant HTML d'un champ.
	 *
	 * @param string ...$keys Clés imbriquées.
	 */
	private static function id( string ...$keys ): string {
		return 'fl-seo-' . sanitize_html_class( implode( '-', $keys ) );
	}

	/**
	 * Un champ, selon son type.
	 *
	 * @param string               $key   Clé.
	 * @param array<string, mixed> $field Description.
	 */
	private static function field( string $key, array $field ): void {
		$type      = is_string( $field['type'] ?? null ) ? $field['type'] : 'text';
		$label     = is_string( $field['label'] ?? null ) ? $field['label'] : $key;
		$help      = is_string( $field['help'] ?? null ) ? $field['help'] : '';
		$value     = Settings::all()[ $key ] ?? '';
		$id        = self::id( $key );
		$name      = self::name( $key );
		$help_id   = '' === $help ? '' : $id . '-help';
		$described = '' === $help_id ? '' : ' aria-describedby="' . esc_attr( $help_id ) . '"';
		echo '<div class="fl-field">';
		if ( 'multi' === $type ) {
			$options = is_array( $field['options'] ?? null ) ? $field['options'] : array();
			$checked = is_array( $value ) ? $value : array();
			echo '<fieldset' . $described . '><legend class="fl-field__label">' . esc_html( $label ) . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribut échappé ci-dessus.
			// Case vide envoyée : décocher toutes les options enregistre une liste vide.
			echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="">';
			foreach ( $options as $option => $option_label ) {
				printf(
					'<label><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label><br>',
					esc_attr( $name ),
					esc_attr( (string) $option ),
					in_array( (string) $option, $checked, true ) ? ' checked' : '',
					esc_html( (string) $option_label )
				);
			}
			echo '</fieldset>';
		} elseif ( 'bool' === $type ) {
			printf( '<span class="fl-field__label" id="%1$s-label">%2$s</span>', esc_attr( $id ), esc_html( $label ) );
			echo Screen::switch_control( $name, $id, true === $value, $id . '-label', $help_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::switch_control().
		} else {
			printf( '<label class="fl-field__label" for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( $label ) );
			if ( 'choice' === $type ) {
				$options = is_array( $field['options'] ?? null ) ? $field['options'] : array();
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $described . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribut échappé ci-dessus.
				foreach ( $options as $option => $option_label ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $option ), selected( (string) $option, is_scalar( $value ) ? (string) $value : '', false ), esc_html( (string) $option_label ) );
				}
				echo '</select>';
			} elseif ( in_array( $type, array( 'lines', 'long', 'textarea' ), true ) ) {
				$text = is_array( $value ) ? implode( "\n", array_map( 'strval', $value ) ) : ( is_scalar( $value ) ? (string) $value : '' );
				printf( '<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text code"%4$s>%5$s</textarea>', esc_attr( $id ), esc_attr( $name ), 'long' === $type ? 8 : 4, $described, esc_textarea( $text ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribut échappé ci-dessus.
			} else {
				$input = match ( $type ) {
					'int'   => 'number',
					'url'   => 'url',
					default => 'text',
				};
				printf( '<input id="%1$s" name="%2$s" type="%3$s" value="%4$s" class="regular-text"%5$s>', esc_attr( $id ), esc_attr( $name ), esc_attr( $input ), esc_attr( is_scalar( $value ) ? (string) $value : '' ), $described ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribut échappé ci-dessus.
			}
		}
		if ( '' !== $help ) {
			printf( '<p class="fl-muted" id="%1$s">%2$s</p>', esc_attr( $help_id ), esc_html( $help ) );
		}
		echo '</div>';
	}

	/**
	 * Contextes des gabarits : clé => libellé.
	 *
	 * @return array<array-key, string> Clés numériques possibles quand un type porte un nom numérique : convertir en chaîne.
	 */
	public static function contexts(): array {
		$contexts = array(
			'front' => __( 'Home page', 'fluffless-seo' ),
			'home'  => __( 'Posts page', 'fluffless-seo' ),
		);
		foreach ( Settings::public_types() as $type => $label ) {
			$contexts[ $type ] = $label;
			$object            = get_post_type_object( $type );
			if ( null !== $object && $object->has_archive ) {
				/* translators: %s: content type name. */
				$contexts[ 'archive:' . $type ] = sprintf( __( '%s archive', 'fluffless-seo' ), $label );
			}
		}
		foreach ( Settings::public_taxonomies() as $taxonomy => $label ) {
			$contexts[ 'tax:' . $taxonomy ] = $label;
		}
		return $contexts + array(
			'author' => __( 'Author archives', 'fluffless-seo' ),
			'date'   => __( 'Date archives', 'fluffless-seo' ),
			'search' => __( 'Search results', 'fluffless-seo' ),
			'404'    => __( 'Page not found', 'fluffless-seo' ),
		);
	}

	/** Gabarits par contexte, repliés sous « Afficher » pour garder l'écran court. */
	private static function templates(): void {
		echo '<section class="fl-group" id="fl-group-templates" aria-labelledby="fl-group-templates-title"><h2 id="fl-group-templates-title">' . esc_html__( 'Templates', 'fluffless-seo' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: list of variables. */
				__( 'Empty fields use the default shown in grey. Variables: %s.', 'fluffless-seo' ),
				implode( ', ', array_map( static fn( string $name ): string => '%%' . $name . '%%', Context::VARIABLES ) )
			)
		) . '</p><div class="fl-grid">';
		foreach ( self::contexts() as $context => $label ) {
			$context  = (string) $context;
			$title_id = self::id( 'tpl', $context, 'title' );
			$desc_id  = self::id( 'tpl', $context, 'desc' );
			printf( '<fieldset class="fl-card"><legend><strong>%s</strong></legend>', esc_html( $label ) );
			printf(
				'<div class="fl-field"><label class="fl-field__label" for="%1$s">%2$s</label><input id="%1$s" name="%3$s" type="text" class="large-text" value="%4$s" placeholder="%5$s"></div>',
				esc_attr( $title_id ),
				esc_html__( 'Title', 'fluffless-seo' ),
				esc_attr( self::name( 'templates', $context, 'title' ) ),
				esc_attr( (string) Settings::template( $context, 'title' ) ),
				esc_attr( Context::default_template( $context, 'title' ) )
			);
			printf(
				'<div class="fl-field"><label class="fl-field__label" for="%1$s">%2$s</label><input id="%1$s" name="%3$s" type="text" class="large-text" value="%4$s" placeholder="%5$s"></div>',
				esc_attr( $desc_id ),
				esc_html__( 'Description', 'fluffless-seo' ),
				esc_attr( self::name( 'templates', $context, 'desc' ) ),
				esc_attr( (string) Settings::template( $context, 'desc' ) ),
				esc_attr( Context::default_template( $context, 'desc' ) )
			);
			echo '</fieldset>';
		}
		echo '</div></section>';
	}

	/** Onglet Redirections. */
	private static function render_redirects(): void {
		$source = isset( $_GET['source'] ) ? Store::normalize_path( sanitize_text_field( wp_unslash( $_GET['source'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- préremplissage d'un champ, aucune écriture.
		if ( ! Settings::on( 'redirects' ) ) {
			echo Screen::note( 'warn', __( 'Redirects are off: rules below are kept but not applied. Turn them on in the Settings tab.', 'fluffless-seo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		if ( ! Settings::keeps_data() ) {
			echo Screen::note( 'idle', __( 'Deleting the plugin from the Plugins screen erases these rules and the not found log. To keep them, turn on “Keep my data when the plugin is deleted” in the Settings tab.', 'fluffless-seo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		echo '<section class="fl-group" aria-labelledby="fl-add-title"><h2 id="fl-add-title">' . esc_html__( 'Add a redirect', 'fluffless-seo' ) . '</h2>';
		self::form_open( 'fluffless_seo_redirect_add' );
		echo '<div class="fl-grid"><div class="fl-card">';
		printf( '<div class="fl-field"><label class="fl-field__label" for="fl-r-source">%1$s</label><input id="fl-r-source" name="source" type="text" class="large-text code" required value="%2$s" aria-describedby="fl-r-source-help"><p class="fl-muted" id="fl-r-source-help">%3$s</p></div>', esc_html__( 'From', 'fluffless-seo' ), esc_attr( '' !== $source && '/' !== $source ? $source : '' ), esc_html__( 'A path such as /old-page, or a pattern such as ^/blog/(.*)$ with the option below.', 'fluffless-seo' ) );
		printf( '<div class="fl-field"><label class="fl-field__label" for="fl-r-target">%1$s</label><input id="fl-r-target" name="target" type="text" class="large-text code" aria-describedby="fl-r-target-help"><p class="fl-muted" id="fl-r-target-help">%2$s</p></div>', esc_html__( 'To', 'fluffless-seo' ), esc_html__( 'A path such as /new-page or a full address. $1, $2 reuse pattern groups. Empty for 410.', 'fluffless-seo' ) );
		echo '<div class="fl-field"><label class="fl-field__label" for="fl-r-code">' . esc_html__( 'Type', 'fluffless-seo' ) . '</label><select id="fl-r-code" name="code">';
		foreach ( array(
			301 => __( '301 moved permanently', 'fluffless-seo' ),
			302 => __( '302 moved temporarily', 'fluffless-seo' ),
			307 => __( '307 temporary, same method', 'fluffless-seo' ),
			410 => __( '410 gone for good', 'fluffless-seo' ),
		) as $code => $label ) {
			printf( '<option value="%1$d">%2$s</option>', (int) $code, esc_html( $label ) );
		}
		echo '</select></div>';
		echo '<div class="fl-field"><label><input type="checkbox" name="regex" value="1"> ' . esc_html__( 'The source is a regular expression', 'fluffless-seo' ) . '</label></div>';
		submit_button( __( 'Add redirect', 'fluffless-seo' ), 'primary', 'submit', false, array( 'id' => 'fl-r-submit' ) );
		echo '</div></div></form></section>';

		$rules = Store::redirects( 200 );
		echo '<section class="fl-group" aria-labelledby="fl-rules-title"><h2 id="fl-rules-title">' . esc_html__( 'Rules', 'fluffless-seo' ) . '</h2>';
		if ( array() === $rules ) {
			echo '<p>' . esc_html__( 'No redirect yet.', 'fluffless-seo' ) . '</p>';
		} else {
			echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'From', 'fluffless-seo' ) . '</th><th scope="col">' . esc_html__( 'To', 'fluffless-seo' ) . '</th><th scope="col">' . esc_html__( 'Type', 'fluffless-seo' ) . '</th><th scope="col">' . esc_html__( 'Uses', 'fluffless-seo' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'fluffless-seo' ) . '</span></th></tr></thead><tbody>';
			foreach ( $rules as $rule ) {
				printf(
					'<tr><td><code>%1$s</code>%2$s</td><td><code>%3$s</code></td><td>%4$d</td><td>%5$d</td><td>',
					esc_html( $rule['source'] ),
					$rule['is_regex'] ? ' ' . Screen::pill( 'idle', __( 'regex', 'fluffless-seo' ) ) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::pill().
					esc_html( $rule['target'] ),
					(int) $rule['code'],
					(int) $rule['hits']
				);
				self::form_open( 'fluffless_seo_redirect_delete' );
				printf( '<input type="hidden" name="id" value="%d">', (int) $rule['id'] );
				submit_button(
					__( 'Delete', 'fluffless-seo' ),
					'link-delete small',
					'submit',
					false,
					array(
						'id'         => 'fl-r-delete-' . (int) $rule['id'],
						/* translators: %s: redirect source. */
						'aria-label' => sprintf( __( 'Delete the redirect from %s', 'fluffless-seo' ), $rule['source'] ),
					)
				);
				echo '</form></td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';

		echo '<section class="fl-group" aria-labelledby="fl-csv-title"><h2 id="fl-csv-title">' . esc_html__( 'Import a CSV file', 'fluffless-seo' ) . '</h2><p>' . esc_html__( 'One rule per line: source,target,code,regex. Up to 5,000 lines and 1 MB. Existing sources are replaced.', 'fluffless-seo' ) . '</p>';
		self::form_open( 'fluffless_seo_redirect_csv', true );
		echo '<label for="fl-csv">' . esc_html__( 'CSV file', 'fluffless-seo' ) . '</label> <input id="fl-csv" type="file" name="csv" accept=".csv,text/csv" required> ';
		submit_button( __( 'Import', 'fluffless-seo' ), 'secondary', 'submit', false, array( 'id' => 'fl-csv-submit' ) );
		echo '</form></section>';

		$log = Store::not_found( 100 );
		echo '<section class="fl-group" aria-labelledby="fl-404-title"><h2 id="fl-404-title">' . esc_html__( 'Not found addresses', 'fluffless-seo' ) . '</h2><p>' . esc_html__( 'Kept 90 days: path, count and last visit only.', 'fluffless-seo' ) . '</p>';
		if ( array() === $log ) {
			echo '<p>' . esc_html( Settings::on( 'log404' ) ? __( 'Nothing logged yet.', 'fluffless-seo' ) : __( 'The log is off.', 'fluffless-seo' ) ) . '</p>';
		} else {
			echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Path', 'fluffless-seo' ) . '</th><th scope="col">' . esc_html__( 'Visits', 'fluffless-seo' ) . '</th><th scope="col">' . esc_html__( 'Last visit (UTC)', 'fluffless-seo' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'fluffless-seo' ) . '</span></th></tr></thead><tbody>';
			foreach ( $log as $row ) {
				printf(
					'<tr><td><code>%1$s</code></td><td>%2$d</td><td>%3$s</td><td><a href="%4$s">%5$s</a></td></tr>',
					esc_html( $row['url'] ),
					(int) $row['hits'],
					esc_html( $row['last_seen'] ),
					esc_url( add_query_arg( 'source', rawurlencode( $row['url'] ), self::url( 'redirects' ) ) . '#fl-add-title' ),
					esc_html__( 'Redirect', 'fluffless-seo' )
				);
			}
			echo '</tbody></table></div>';
			self::form_open( 'fluffless_seo_404_purge' );
			submit_button( __( 'Clear the log', 'fluffless-seo' ), 'secondary', 'submit', false, array( 'id' => 'fl-404-purge' ) );
			echo '</form>';
		}
		echo '</section>';
	}

	/** Onglet Importation. */
	private static function render_import(): void {
		echo '<section class="fl-group" aria-labelledby="fl-import-title"><h2 id="fl-import-title">' . esc_html__( 'Import from another SEO plugin', 'fluffless-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Copies titles, descriptions, canonical addresses, sharing fields, robots directives and keywords. Run a simulation first. Fields already filled here are never replaced, and the original data is never deleted.', 'fluffless-seo' ) . '</p><div class="fl-grid">';
		foreach ( array(
			'yoast'    => 'Yoast SEO',
			'seopress' => 'SEOPress',
			'rankmath' => 'Rank Math',
		) as $source => $label ) {
			printf( '<section class="fl-card" aria-labelledby="fl-imp-%1$s"><h3 id="fl-imp-%1$s">%2$s</h3>', esc_attr( $source ), esc_html( $label ) );
			self::form_open( 'fluffless_seo_import' );
			printf( '<input type="hidden" name="source" value="%s">', esc_attr( $source ) );
			submit_button(
				__( 'Simulate', 'fluffless-seo' ),
				'secondary',
				'simulate',
				false,
				array(
					'id'         => 'fl-imp-simulate-' . $source,
					/* translators: %s: plugin name. */
					'aria-label' => sprintf( __( 'Simulate the import from %s', 'fluffless-seo' ), $label ),
				)
			);
			echo ' ';
			submit_button(
				__( 'Import', 'fluffless-seo' ),
				'primary',
				'apply',
				false,
				array(
					'id'         => 'fl-imp-apply-' . $source,
					/* translators: %s: plugin name. */
					'aria-label' => sprintf( __( 'Import from %s', 'fluffless-seo' ), $label ),
				)
			);
			echo '</form></section>';
		}
		echo '</div></section>';
	}

	/**
	 * Ouvre un formulaire admin-post avec son action et son nonce.
	 *
	 * @param string $action    Action admin-post.
	 * @param bool   $multipart Envoi de fichier.
	 */
	private static function form_open( string $action, bool $multipart = false ): void {
		printf( '<form method="post" action="%1$s"%2$s style="display:inline">', esc_url( admin_url( 'admin-post.php' ) ), $multipart ? ' enctype="multipart/form-data"' : '' );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		// Champ écrit à la main : wp_nonce_field() pose id="_wpnonce", répété à chaque formulaire de la page.
		printf( '<input type="hidden" name="_wpnonce" value="%s">', esc_attr( wp_create_nonce( $action ) ) );
	}

	/** Message laissé par la dernière action, affiché une fois. */
	private static function notice(): void {
		$key    = 'fluffless_seo_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) && is_string( $notice[0] ?? null ) && is_array( $notice[1] ?? null ) ) {
			delete_transient( $key );
			foreach ( $notice[1] as $message ) {
				echo Screen::note( $notice[0], (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			}
		}
	}

	/**
	 * Vérifie capacité et nonce d'une action.
	 *
	 * @param string $action Action admin-post.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change SEO settings.', 'fluffless-seo' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Mémorise le message et revient à l'onglet.
	 *
	 * @param string   $tab      Onglet.
	 * @param string   $status   good, warn ou bad.
	 * @param string[] $messages Messages.
	 * @return never
	 */
	private static function back( string $tab, string $status, array $messages ): void {
		set_transient( 'fluffless_seo_notice_' . get_current_user_id(), array( $status, $messages ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/**
	 * Champ texte posté.
	 *
	 * @param string $key Clé.
	 */
	private static function posted( string $key ): string {
		return isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans guard().
	}

	/** Ajout d'une règle. */
	public static function handle_add(): void {
		self::guard( 'fluffless_seo_redirect_add' );
		$regex = '1' === self::posted( 'regex' );
		try {
			// Le motif garde ses caractères spéciaux : sanitize_text_field ne touche ni ^ ni $ ni ( ).
			Store::add_redirect( self::posted( 'source' ), self::posted( 'target' ), (int) self::posted( 'code' ), $regex );
		} catch ( \InvalidArgumentException $error ) {
			self::back( 'redirects', 'bad', array( $error->getMessage() ) );
		}
		if ( ! $regex ) {
			Store::forget_404( Store::normalize_path( self::posted( 'source' ) ) );
		}
		self::back( 'redirects', 'good', array( __( 'Redirect saved.', 'fluffless-seo' ) ) );
	}

	/** Suppression d'une règle. */
	public static function handle_delete(): void {
		self::guard( 'fluffless_seo_redirect_delete' );
		$deleted = Store::delete_redirect( (int) self::posted( 'id' ) );
		self::back( 'redirects', $deleted ? 'good' : 'bad', array( $deleted ? __( 'Redirect deleted.', 'fluffless-seo' ) : __( 'This redirect no longer exists. Reload the page.', 'fluffless-seo' ) ) );
	}

	/** Importation CSV. */
	public static function handle_csv(): void {
		self::guard( 'fluffless_seo_redirect_csv' );
		$file = $_FILES['csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce vérifié dans guard(), fichier ci-dessous (fichier téléversé, taille).
		$tmp  = is_array( $file ) && is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		$size = is_array( $file ) ? (int) ( $file['size'] ?? 0 ) : 0;
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) || $size < 1 || $size > self::MAX_CSV_BYTES ) {
			self::back( 'redirects', 'bad', array( __( 'No valid CSV file received. Choose a file of 1 MB or less and try again.', 'fluffless-seo' ) ) );
		}
		try {
			$result = Store::import_csv( $tmp );
		} catch ( \RuntimeException $error ) {
			self::back( 'redirects', 'bad', array( $error->getMessage() ) );
		}
		/* translators: %d: number of redirects. */
		$messages = array( sprintf( _n( '%d redirect imported.', '%d redirects imported.', $result['added'], 'fluffless-seo' ), $result['added'] ) );
		self::back( 'redirects', array() === $result['errors'] ? 'good' : 'warn', array_merge( $messages, $result['errors'] ) );
	}

	/** Vidage du journal. */
	public static function handle_purge(): void {
		self::guard( 'fluffless_seo_404_purge' );
		$count = Store::purge( true );
		/* translators: %d: number of entries. */
		self::back( 'redirects', 'good', array( sprintf( _n( '%d entry deleted.', '%d entries deleted.', $count, 'fluffless-seo' ), $count ) ) );
	}

	/** Simulation ou importation depuis une autre extension. */
	public static function handle_import(): void {
		self::guard( 'fluffless_seo_import' );
		$apply = isset( $_POST['apply'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans guard().
		try {
			$report = Importer::run( self::posted( 'source' ), $apply );
		} catch ( \InvalidArgumentException $error ) {
			self::back( 'import', 'bad', array( $error->getMessage() ) );
		}
		$summary = sprintf(
			/* translators: 1: posts scanned, 2: fields written or to write, 3: fields kept because already filled. */
			__( '%1$d posts scanned, %2$d fields, %3$d kept as they were already filled.', 'fluffless-seo' ),
			$report['posts'],
			$report['written'],
			$report['kept']
		);
		self::back( 'import', 'good', array( $apply ? __( 'Import done.', 'fluffless-seo' ) : __( 'Simulation: nothing was written.', 'fluffless-seo' ), $summary ) );
	}
}
