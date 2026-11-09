<?php
/**
 * Réglages de Fluffless SEO : une seule option autochargée, fluffless_seo_settings.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Tout le réglage tient dans une option autochargée : lue avec les autres options au
 * démarrage de WordPress, elle ne coûte aucune requête SQL sur le site public.
 *
 * Familles (interrupteurs) : chaque clé de FAMILIES allume ou éteint un groupe de crochets.
 * Les gabarits de titres et de descriptions vivent dans la clé templates : clé de contexte => titre, description.
 *
 * @phpstan-type Field array{type: 'bool'|'int'|'choice'|'multi'|'text'|'textarea'|'lines'|'url'|'long'|'token', label: string, help?: string, min?: int, max?: int, options?: array<string, string>, https?: bool}
 */
final class Settings {

	public const NAME  = 'fluffless_seo_settings';
	public const GROUP = 'fluffless_seo';

	/** Longueur d'un gabarit, d'un robots.txt, d'un llms.txt. */
	public const MAX_TEMPLATE = 200;
	public const MAX_LONG     = 8000;

	/** Contextes de gabarits au plus : types, taxonomies et archives confondus. */
	private const MAX_TEMPLATES = 120;

	/** Familles et leur état sur une installation neuve. */
	public const FAMILIES = array(
		'titles'      => true,
		'social'      => true,
		'schema'      => true,
		'faq_schema'  => true,
		'breadcrumbs' => true,
		'sitemaps'    => true,
		'redirects'   => true,
		'log404'      => true,
		'hreflang'    => true,
		'analysis'    => true,
		'robots_txt'  => false,
		'llms'        => false,
		'indexnow'    => false,
	);

	/** Séparateurs de titre proposés (aucun tiret cadratin). */
	public const SEPARATORS = array( '|', '-', '–', '·', '•', '›', '/' );

	/**
	 * Réglages lus dans la requête.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/** Déclare l'option (formulaire et API REST). */
	public static function register(): void {
		register_setting(
			self::GROUP,
			self::NAME,
			array(
				'type'              => 'object',
				'default'           => array(),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Valeurs par défaut (hors gabarits, dont le défaut se calcule par contexte).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return self::FAMILIES + array(
			'sep'                 => '|',
			'templates'           => array(),
			'noindex_archives'    => array( 'date' ),
			'noindex_taxonomies'  => array(),
			'default_image'       => 0,
			'x_site'              => '',
			'entity'              => 'Organization',
			'entity_name'         => '',
			'logo'                => '',
			'same_as'             => array(),
			'business_type'       => 'LocalBusiness',
			'street'              => '',
			'city'                => '',
			'region'              => '',
			'postal'              => '',
			'country'             => '',
			'phone'               => '',
			'price_range'         => '',
			'hours'               => array(),
			'area_served'         => array(),
			'bc_home'             => '',
			'hreflang_default'    => '',
			'sitemap_exclude'     => array(),
			'sitemap_exclude_tax' => array(),
			'sitemap_users'       => false,
			'sitemap_lastmod'     => true,
			'redirect_everywhere' => false,
			'robots_txt_content'  => '',
			'llms_content'        => '',
			'verify_google'       => '',
			'verify_bing'         => '',
			'indexnow_key'        => '',
			'keep_data'           => false,
			// Version du schéma des tables ; écrite par Store::install(), jamais par le formulaire.
			'db'                  => 0,
		);
	}

	/**
	 * Tous les réglages, fusionnés avec les valeurs par défaut.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::NAME, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/** Vrai quand la suppression de l'extension doit tout garder : constante vraie de wp-config.php (sa valeur compte) ou option cochée. */
	public static function keeps_data(): bool {
		return filter_var( defined( 'FLUFFLESS_SEO_KEEP_DATA' ) ? constant( 'FLUFFLESS_SEO_KEEP_DATA' ) : false, FILTER_VALIDATE_BOOLEAN ) || true === self::all()['keep_data'];
	}

	/** Oublie la lecture (tests, écriture dans la même requête). */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Famille allumée ?
	 *
	 * @param string $family Clé de FAMILIES.
	 */
	public static function on( string $family ): bool {
		return true === ( self::all()[ $family ] ?? false );
	}

	/**
	 * Valeur texte.
	 *
	 * @param string $key Clé.
	 */
	public static function string( string $key ): string {
		$value = self::all()[ $key ] ?? '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Valeur entière.
	 *
	 * @param string $key Clé.
	 */
	public static function int( string $key ): int {
		$value = self::all()[ $key ] ?? 0;
		return is_int( $value ) ? $value : 0;
	}

	/**
	 * Liste de chaînes.
	 *
	 * @param string $key Clé.
	 * @return list<string>
	 */
	public static function list( string $key ): array {
		$value = self::all()[ $key ] ?? array();
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * Gabarit enregistré pour un contexte et un champ ; null s'il n'y en a pas (le défaut s'applique).
	 *
	 * @param string $context Clé de contexte : post, tax:category, home, search...
	 * @param string $field   title ou desc.
	 */
	public static function template( string $context, string $field ): ?string {
		$templates = self::all()['templates'] ?? array();
		$value     = is_array( $templates ) && is_array( $templates[ $context ] ?? null ) ? ( $templates[ $context ][ $field ] ?? null ) : null;
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Écrit les réglages (fusion avec l'existant), sans passer par le formulaire.
	 *
	 * @param array<string, mixed> $changes Clés à changer.
	 */
	public static function update( array $changes ): void {
		$current = get_option( self::NAME, array() );
		update_option( self::NAME, array_merge( is_array( $current ) ? $current : array(), $changes ), true );
		self::flush();
	}

	/**
	 * Champs du formulaire, clé => description. Calculés à la demande : types et taxonomies
	 * publics ne sont connus qu'après init, et le site public n'en a jamais besoin.
	 *
	 * @return array<string, Field>
	 */
	public static function fields(): array {
		$types      = self::public_types();
		$taxonomies = self::public_taxonomies();
		$fields     = array(
			'sep'                 => array(
				'type'    => 'choice',
				'label'   => __( 'Title separator', 'fluffless-seo' ),
				'options' => array_combine( self::SEPARATORS, self::SEPARATORS ),
			),
			'noindex_archives'    => array(
				'type'    => 'multi',
				'label'   => __( 'Keep out of search results', 'fluffless-seo' ),
				'options' => array(
					'author'     => __( 'Author archives', 'fluffless-seo' ),
					'date'       => __( 'Date archives', 'fluffless-seo' ),
					'attachment' => __( 'Media attachment pages', 'fluffless-seo' ),
				),
			),
			'noindex_taxonomies'  => array(
				'type'    => 'multi',
				'label'   => __( 'Taxonomies kept out of search results', 'fluffless-seo' ),
				'options' => $taxonomies,
			),
			'default_image'       => array(
				'type'  => 'int',
				'label' => __( 'Default sharing image (media ID)', 'fluffless-seo' ),
				'help'  => __( 'Used when a page has no featured image. 0: the logo is used.', 'fluffless-seo' ),
				'min'   => 0,
				'max'   => PHP_INT_MAX,
			),
			'x_site'              => array(
				'type'  => 'text',
				'label' => __( 'X account of the site', 'fluffless-seo' ),
				'help'  => __( 'Written as @name. Empty: no twitter:site tag.', 'fluffless-seo' ),
			),
			'entity'              => array(
				'type'    => 'choice',
				'label'   => __( 'This site represents', 'fluffless-seo' ),
				'options' => array(
					'Organization'  => __( 'An organization', 'fluffless-seo' ),
					'LocalBusiness' => __( 'A local business', 'fluffless-seo' ),
					'Person'        => __( 'A person', 'fluffless-seo' ),
				),
			),
			'entity_name'         => array(
				'type'  => 'text',
				'label' => __( 'Name', 'fluffless-seo' ),
				'help'  => __( 'Empty: the site title.', 'fluffless-seo' ),
			),
			'logo'                => array(
				'type'  => 'url',
				'label' => __( 'Logo or photo address', 'fluffless-seo' ),
				'help'  => __( 'https only. Empty: the site logo, then the site icon.', 'fluffless-seo' ),
			),
			'same_as'             => array(
				'type'  => 'lines',
				'https' => true,
				'label' => __( 'Profiles elsewhere', 'fluffless-seo' ),
				'help'  => __( 'One https address per line: LinkedIn, Mastodon, directory listings.', 'fluffless-seo' ),
			),
			'business_type'       => array(
				'type'    => 'choice',
				'label'   => __( 'Kind of business', 'fluffless-seo' ),
				'options' => array(
					'LocalBusiness'               => __( 'General local business', 'fluffless-seo' ),
					'ProfessionalService'         => __( 'Professional services', 'fluffless-seo' ),
					'Store'                       => __( 'Store', 'fluffless-seo' ),
					'FoodEstablishment'           => __( 'Restaurant or café', 'fluffless-seo' ),
					'HealthAndBeautyBusiness'     => __( 'Health and beauty', 'fluffless-seo' ),
					'HomeAndConstructionBusiness' => __( 'Home and construction', 'fluffless-seo' ),
					'LegalService'                => __( 'Legal services', 'fluffless-seo' ),
					'FinancialService'            => __( 'Financial services', 'fluffless-seo' ),
				),
			),
			'street'              => array(
				'type'  => 'text',
				'label' => __( 'Street address', 'fluffless-seo' ),
			),
			'city'                => array(
				'type'  => 'text',
				'label' => __( 'City', 'fluffless-seo' ),
			),
			'region'              => array(
				'type'  => 'text',
				'label' => __( 'Province or state', 'fluffless-seo' ),
				'help'  => __( 'Two-letter code, e.g. QC.', 'fluffless-seo' ),
			),
			'postal'              => array(
				'type'  => 'text',
				'label' => __( 'Postal code', 'fluffless-seo' ),
			),
			'country'             => array(
				'type'  => 'text',
				'label' => __( 'Country', 'fluffless-seo' ),
				'help'  => __( 'Two-letter code, e.g. CA.', 'fluffless-seo' ),
			),
			'phone'               => array(
				'type'  => 'text',
				'label' => __( 'Phone', 'fluffless-seo' ),
			),
			'price_range'         => array(
				'type'  => 'text',
				'label' => __( 'Price range', 'fluffless-seo' ),
				'help'  => __( 'e.g. $$ or 80 to 150 CAD.', 'fluffless-seo' ),
			),
			'hours'               => array(
				'type'  => 'lines',
				'label' => __( 'Opening hours', 'fluffless-seo' ),
				'help'  => __( 'One range per line, in schema.org form: Mo-Fr 09:00-17:00', 'fluffless-seo' ),
			),
			'area_served'         => array(
				'type'  => 'lines',
				'label' => __( 'Area served', 'fluffless-seo' ),
				'help'  => __( 'One place per line: city, region or country.', 'fluffless-seo' ),
			),
			'bc_home'             => array(
				'type'  => 'text',
				'label' => __( 'First breadcrumb label', 'fluffless-seo' ),
				'help'  => __( 'Empty: Home, translated.', 'fluffless-seo' ),
			),
			'hreflang_default'    => array(
				'type'    => 'choice',
				'label'   => __( 'Default language for x-default', 'fluffless-seo' ),
				'help'    => __( 'Page shown to people whose language has no translation. Written only when the page exists in this language.', 'fluffless-seo' ),
				'options' => array( '' => __( 'None', 'fluffless-seo' ) ) + self::languages(),
			),
			'sitemap_exclude'     => array(
				'type'    => 'multi',
				'label'   => __( 'Content types left out of the sitemap', 'fluffless-seo' ),
				'options' => $types,
			),
			'sitemap_exclude_tax' => array(
				'type'    => 'multi',
				'label'   => __( 'Taxonomies left out of the sitemap', 'fluffless-seo' ),
				'options' => $taxonomies,
			),
			'sitemap_users'       => array(
				'type'  => 'bool',
				'label' => __( 'List author pages in the sitemap', 'fluffless-seo' ),
			),
			'sitemap_lastmod'     => array(
				'type'  => 'bool',
				'label' => __( 'Add the last modified date', 'fluffless-seo' ),
			),
			'redirect_everywhere' => array(
				'type'  => 'bool',
				'label' => __( 'Check redirects on every page', 'fluffless-seo' ),
				'help'  => __( 'Off: only addresses that would show a not found page are checked, at no cost for other pages. On: one database query per page, to redirect addresses that still exist.', 'fluffless-seo' ),
			),
			'robots_txt_content'  => array(
				'type'  => 'long',
				'label' => __( 'robots.txt content', 'fluffless-seo' ),
				'help'  => __( 'Replaces the file WordPress generates. The sitemap line is added if missing.', 'fluffless-seo' ),
			),
			'llms_content'        => array(
				'type'  => 'long',
				'label' => __( 'llms.txt content', 'fluffless-seo' ),
				'help'  => __( 'Empty: a summary is built from the site title, tagline and main pages.', 'fluffless-seo' ),
			),
			'verify_google'       => array(
				'type'  => 'token',
				'label' => __( 'Google Search Console code', 'fluffless-seo' ),
				'help'  => __( 'The content value of the verification tag, not the whole tag.', 'fluffless-seo' ),
			),
			'verify_bing'         => array(
				'type'  => 'token',
				'label' => __( 'Bing Webmaster Tools code', 'fluffless-seo' ),
			),
			'keep_data'           => array(
				'type'  => 'bool',
				'label' => __( 'Keep my data when the plugin is deleted', 'fluffless-seo' ),
				'help'  => __( 'Off: deleting the plugin erases its settings, the redirect rules, the not found log and the fields written on each page. On: all of it stays in the database.', 'fluffless-seo' ),
			),
		);
		return $fields;
	}

	/**
	 * Langues de Polylang : locale => nom ; vide sans Polylang.
	 *
	 * @return array<string, string>
	 */
	public static function languages(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}
		$locales = pll_languages_list( array( 'fields' => 'locale' ) );
		$names   = pll_languages_list( array( 'fields' => 'name' ) );
		if ( ! is_array( $locales ) || ! is_array( $names ) ) {
			return array();
		}
		$languages = array();
		foreach ( $locales as $index => $locale ) {
			if ( is_string( $locale ) && 1 === preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$/', $locale ) ) {
				$languages[ $locale ] = is_string( $names[ $index ] ?? null ) ? $names[ $index ] : $locale;
			}
		}
		return $languages;
	}

	/**
	 * Types de contenu publics : nom => libellé.
	 *
	 * @return array<string, string>
	 */
	public static function public_types(): array {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $type ) {
			if ( 'attachment' !== $name ) {
				$types[ (string) $name ] = (string) $type->label;
			}
		}
		return $types;
	}

	/**
	 * Taxonomies publiques : nom => libellé.
	 *
	 * @return array<string, string>
	 */
	public static function public_taxonomies(): array {
		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $name => $taxonomy ) {
			if ( 'post_format' !== $name ) {
				$taxonomies[ (string) $name ] = (string) $taxonomy->label;
			}
		}
		return $taxonomies;
	}

	/**
	 * Assainit l'option reçue du formulaire. Une clé absente garde sa valeur enregistrée
	 * (le formulaire peut n'envoyer qu'un onglet) ; une valeur invalide aussi.
	 *
	 * @param mixed $input Valeur brute.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$stored = get_option( self::NAME, array() );
		$clean  = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		if ( ! is_array( $input ) ) {
			return $clean;
		}
		foreach ( array_keys( self::FAMILIES ) as $family ) {
			if ( array_key_exists( $family, $input ) ) {
				$clean[ $family ] = Sanitize::to_bool( $input[ $family ] ) ?? $clean[ $family ];
			}
		}
		foreach ( self::fields() as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = self::field( $input[ $key ], $field );
			if ( null !== $value ) {
				$clean[ $key ] = $value;
			}
		}
		if ( array_key_exists( 'templates', $input ) ) {
			$clean['templates'] = self::templates( $input['templates'] );
		}
		if ( true === $clean['indexnow'] && '' === $clean['indexnow_key'] ) {
			$clean['indexnow_key'] = bin2hex( random_bytes( 16 ) );
		}
		return $clean;
	}

	/**
	 * Valeur d'un champ, ou null si invalide.
	 *
	 * @param mixed $raw   Valeur reçue.
	 * @param array $field Description.
	 * @phpstan-param Field $field
	 * @return bool|int|string|list<string>|null
	 */
	public static function field( $raw, array $field ) {
		return match ( $field['type'] ) {
			'long'  => is_string( $raw ) ? mb_substr( sanitize_textarea_field( $raw ), 0, self::MAX_LONG ) : null,
			'token' => is_string( $raw ) ? substr( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $raw ), 0, 100 ) : null,
			'lines' => self::lines( $raw, $field ),
			default => Sanitize::field( $raw, $field ),
		};
	}

	/**
	 * Lignes ; avec la clé https, seules les adresses https valides sont gardées.
	 *
	 * @param mixed $raw   Valeur reçue.
	 * @param array $field Description.
	 * @phpstan-param Field $field
	 * @return list<string>|null
	 */
	private static function lines( $raw, array $field ): ?array {
		$lines = Sanitize::lines( $raw );
		if ( null === $lines || true !== ( $field['https'] ?? false ) ) {
			return $lines;
		}
		return array_values( array_filter( array_map( array( Sanitize::class, 'https_url' ), $lines ), static fn( ?string $url ): bool => null !== $url && '' !== $url ) );
	}

	/**
	 * Gabarits : clé de contexte bornée => titre et description en texte brut.
	 *
	 * @param mixed $raw Valeur reçue.
	 * @return array<string, array{title: string, desc: string}>
	 */
	public static function templates( $raw ): array {
		$clean = array();
		if ( ! is_array( $raw ) ) {
			return $clean;
		}
		foreach ( array_slice( $raw, 0, self::MAX_TEMPLATES, true ) as $context => $pair ) {
			if ( ! is_string( $context ) || 1 !== preg_match( '/^[a-z0-9_:\-]{1,64}$/', $context ) || ! is_array( $pair ) ) {
				continue;
			}
			$title = is_string( $pair['title'] ?? null ) ? mb_substr( sanitize_text_field( $pair['title'] ), 0, self::MAX_TEMPLATE ) : '';
			$desc  = is_string( $pair['desc'] ?? null ) ? mb_substr( sanitize_text_field( $pair['desc'] ), 0, self::MAX_TEMPLATE ) : '';
			if ( '' !== $title || '' !== $desc ) {
				$clean[ $context ] = array(
					'title' => $title,
					'desc'  => $desc,
				);
			}
		}
		return $clean;
	}
}
