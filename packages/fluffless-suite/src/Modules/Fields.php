<?php
/**
 * Module Champs personnalisés : types de contenu et champs déclarés sans code depuis un écran,
 * enregistrés comme types publics et métadonnées natives (visibles dans le panneau Attributs de
 * l'éditeur par la source core/post-meta), saisis dans une boîte sur l'écran d'édition, et une
 * source de liaison fluffless/champ qui lit un champ natif, ACF ou Meta Box par la même clé.
 * Préréglage « Membre d'équipe » : type, champs, fiche de boucle et gabarits d'article et d'archive.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;
use WP_Block;
use WP_Post;
use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Option fluffless_champs : slug de type => array( 'label', 'singular', 'fields' => key => array( 'label', 'kind' ) ).
 * Les clés de champ sont aussi les clés de métadonnées ; un thème enfant ou ACF peut en définir
 * d'autres, lues par la même liaison.
 */
final class Fields implements Module {

	public const PAGE       = 'fluffless-champs';
	public const ACTION     = 'fluffless_champs_save';
	public const PRESET     = 'fluffless_champs_preset';
	public const SOURCE     = 'fluffless/champ';
	public const NONCE      = 'fluffless_champs_box';
	public const MAX_TYPES  = 20;
	public const MAX_FIELDS = 30;
	public const KINDS      = array( 'text', 'multiline', 'number', 'url', 'email', 'date', 'image' );
	public const TEMPLATES  = array( 'single-membre', 'archive-membre' );
	private const SLUG_RULE = '/^[a-z][a-z0-9_-]{0,19}$/';
	private const HANDLE    = 'fluffless-champs-editor';
	private const DEPS      = array( 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-compose', 'wp-data', 'wp-element', 'wp-hooks', 'wp-i18n' );
	private const RESERVED  = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_global_styles', 'product', 'shop_order', 'shop_coupon', 'action', 'author', 'order', 'theme', 'type', 'wp_router_page' );

	/** {@inheritDoc} */
	public static function id(): string {
		return 'champs';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Custom fields', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Declares content types and their fields from a screen (a team member with a role, an email and a short bio, say). Fields are entered on the edit screen and placed once in a template or a loop card; every new entry follows. Reads native, ACF and Meta Box fields through one block binding. Nothing is added to pages.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Réglages bruts.
	 * @return array<string, bool|int|string|list<string>>
	 */
	public static function sanitize( array $input ): array {
		return array();
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_types' ), 5 );
		add_action( 'init', array( self::class, 'register_source' ) );
		add_action( 'init', array( self::class, 'register_templates' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( self::class, 'add_boxes' ) );
			add_action( 'save_post', array( self::class, 'save_box' ), 10, 2 );
			Menu::add_tab( 'content', self::PAGE, static fn(): string => __( 'Custom fields', 'fluffless-suite' ), array( self::class, 'render' ), 10 );
			add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_save' ) );
			add_action( 'admin_post_' . self::PRESET, array( self::class, 'handle_preset' ) );
		}
	}

	/* ---------- Réglage ---------- */

	/**
	 * Types déclarés, assainis : slug => array( label, singular, fields ).
	 *
	 * @return array<string, array{label: string, singular: string, fields: array<string, array{label: string, kind: string}>}>
	 */
	public static function types(): array {
		$raw = get_option( Lifecycle::OPTION_CHAMPS, array() );
		return self::sanitize_types( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Garde vingt types au plus, aux slugs bien formés et non réservés, chacun avec trente champs au
	 * plus, aux clés bien formées et d'une sorte connue ; libellés en texte seul.
	 *
	 * @param array<mixed> $raw Types bruts.
	 * @return array<string, array{label: string, singular: string, fields: array<string, array{label: string, kind: string}>}>
	 */
	public static function sanitize_types( array $raw ): array {
		$out = array();
		foreach ( $raw as $slug => $type ) {
			if ( count( $out ) >= self::MAX_TYPES || ! is_string( $slug ) || ! is_array( $type ) || ! self::valid_slug( $slug ) ) {
				continue;
			}
			$label    = self::text( $type['label'] ?? '' );
			$singular = self::text( $type['singular'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$fields = array();
			foreach ( is_array( $type['fields'] ?? null ) ? $type['fields'] : array() as $key => $field ) {
				if ( count( $fields ) >= self::MAX_FIELDS || ! is_string( $key ) || ! is_array( $field ) || 1 !== preg_match( self::SLUG_RULE, $key ) ) {
					continue;
				}
				$kind           = is_string( $field['kind'] ?? null ) && in_array( $field['kind'], self::KINDS, true ) ? $field['kind'] : 'text';
				$field_label    = self::text( $field['label'] ?? '' );
				$fields[ $key ] = array(
					'label' => '' !== $field_label ? $field_label : $key,
					'kind'  => $kind,
				);
			}
			$out[ $slug ] = array(
				'label'    => $label,
				'singular' => '' !== $singular ? $singular : $label,
				'fields'   => $fields,
			);
		}
		return $out;
	}

	/**
	 * Un slug de type est accepté quand il a la forme voulue et ne désigne pas un type réservé.
	 *
	 * @param string $slug Slug de type.
	 */
	public static function valid_slug( string $slug ): bool {
		return 1 === preg_match( self::SLUG_RULE, $slug ) && ! in_array( $slug, self::RESERVED, true );
	}

	/**
	 * Texte seul, borné à cent caractères.
	 *
	 * @param mixed $value Valeur brute.
	 */
	private static function text( $value ): string {
		return is_string( $value ) ? mb_substr( sanitize_text_field( $value ), 0, 100 ) : '';
	}

	/**
	 * Préréglage « Membre d'équipe » : type membre et cinq champs.
	 *
	 * @return array{label: string, singular: string, fields: array<string, array{label: string, kind: string}>}
	 */
	public static function preset(): array {
		return array(
			'label'    => __( 'Team members', 'fluffless-suite' ),
			'singular' => __( 'Team member', 'fluffless-suite' ),
			'fields'   => array(
				'poste'     => array(
					'label' => __( 'Role', 'fluffless-suite' ),
					'kind'  => 'text',
				),
				'courriel'  => array(
					'label' => __( 'Email', 'fluffless-suite' ),
					'kind'  => 'email',
				),
				'telephone' => array(
					'label' => __( 'Phone', 'fluffless-suite' ),
					'kind'  => 'text',
				),
				'linkedin'  => array(
					'label' => __( 'LinkedIn profile', 'fluffless-suite' ),
					'kind'  => 'url',
				),
				'bio'       => array(
					'label' => __( 'Short bio', 'fluffless-suite' ),
					'kind'  => 'multiline',
				),
			),
		);
	}

	/* ---------- Types et métadonnées ---------- */

	/** Enregistre chaque type déclaré et ses champs comme métadonnées natives exposées à l'éditeur. */
	public static function register_types(): void {
		foreach ( self::types() as $slug => $type ) {
			if ( post_type_exists( $slug ) || '' === $slug ) {
				continue;
			}
			register_post_type(
				strtolower( $slug ),
				array(
					'labels'       => array(
						'name'          => $type['label'],
						'singular_name' => $type['singular'],
					),
					'public'       => true,
					'has_archive'  => true,
					'show_in_rest' => true,
					'menu_icon'    => 'dashicons-index-card',
					'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ),
					'rewrite'      => array( 'slug' => $slug ),
				)
			);
			foreach ( $type['fields'] as $key => $field ) {
				register_post_meta(
					$slug,
					$key,
					array(
						'type'              => 'image' === $field['kind'] ? 'integer' : 'string',
						'single'            => true,
						'default'           => 'image' === $field['kind'] ? 0 : '',
						'label'             => $field['label'],
						'description'       => $field['label'],
						'show_in_rest'      => true,
						'sanitize_callback' => static function ( $value ) use ( $field ) {
							return self::sanitize_value( $value, $field['kind'] );
						},
						'auth_callback'     => static function (): bool {
							return current_user_can( 'edit_posts' );
						},
					)
				);
			}
		}
	}

	/**
	 * Valeur d'un champ selon sa sorte.
	 *
	 * @param mixed  $value Valeur brute.
	 * @param string $kind  Sorte du champ.
	 * @return string|int
	 */
	public static function sanitize_value( $value, string $kind ) {
		if ( 'image' === $kind ) {
			return max( 0, (int) $value );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = (string) $value;
		switch ( $kind ) {
			case 'multiline':
				return sanitize_textarea_field( $value );
			case 'number':
				return '' === trim( $value ) ? '' : (string) (float) $value;
			case 'url':
				return esc_url_raw( $value );
			case 'email':
				return sanitize_email( $value );
			case 'date':
				return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Sorte d'un champ déclaré pour un type ('' quand il n'est pas déclaré : champ ACF, Meta Box ou libre).
	 *
	 * @param string $type Type de contenu.
	 * @param string $key  Clé du champ.
	 */
	public static function kind( string $type, string $key ): string {
		return self::types()[ $type ]['fields'][ $key ]['kind'] ?? '';
	}

	/* ---------- Source de liaison ---------- */

	/** Source fluffless/champ : un champ par sa clé, natif d'abord, puis ACF, puis Meta Box. */
	public static function register_source(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) || null !== get_block_bindings_source( self::SOURCE ) ) {
			return;
		}
		register_block_bindings_source(
			self::SOURCE,
			array(
				'label'              => __( 'Custom field (Fluffless)', 'fluffless-suite' ),
				'get_value_callback' => array( self::class, 'value' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * Valeur d'une liaison : lit la clé demandée pour le contenu du contexte et l'adapte à l'attribut lié
	 * (adresse d'image pour url, texte alternatif pour alt, mailto: et tel: pour l'adresse d'un bouton).
	 *
	 * @param array<string, mixed> $args           Arguments de la liaison (key).
	 * @param WP_Block|null        $block          Bloc lié.
	 * @param string               $attribute_name Attribut lié.
	 * @return string|int|null
	 */
	public static function value( array $args, $block = null, string $attribute_name = 'content' ) {
		$key = is_string( $args['key'] ?? null ) ? $args['key'] : '';
		if ( 1 !== preg_match( self::SLUG_RULE, $key ) ) {
			return null;
		}
		$context = $block instanceof WP_Block ? $block->context : array();
		$post_id = (int) ( $context['postId'] ?? get_the_ID() );
		if ( $post_id <= 0 || post_password_required( $post_id ) ) {
			return null;
		}
		$raw = self::read( $post_id, $key );
		if ( null === $raw ) {
			return null;
		}
		$kind = self::kind( (string) get_post_type( $post_id ), $key );
		if ( 'image' === $kind || ( is_int( $raw ) && in_array( $attribute_name, array( 'url', 'alt', 'title', 'id', 'caption' ), true ) ) ) {
			$image_id = (int) $raw;
			switch ( $attribute_name ) {
				case 'id':
					return $image_id > 0 ? $image_id : null;
				case 'alt':
					return (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true );
				case 'title':
					return (string) get_the_title( $image_id );
				case 'caption':
					return (string) wp_get_attachment_caption( $image_id );
				default:
					$url = wp_get_attachment_image_url( $image_id, 'large' );
					return is_string( $url ) ? $url : null;
			}
		}
		$text = (string) $raw;
		if ( 'url' === $attribute_name ) {
			if ( 'email' === $kind || is_email( $text ) ) {
				return 'mailto:' . $text;
			}
			if ( 1 === preg_match( '/^\+?[0-9 ().-]{6,}$/', $text ) ) {
				return 'tel:' . preg_replace( '/[^0-9+]/', '', $text );
			}
			return esc_url( $text );
		}
		if ( 'multiline' === $kind || str_contains( $text, "\n" ) ) {
			return nl2br( esc_html( $text ) );
		}
		return esc_html( $text );
	}

	/**
	 * Lit un champ : métadonnée native non vide, sinon ACF (get_field), sinon Meta Box (rwmb_meta).
	 *
	 * @param int    $post_id Contenu.
	 * @param string $key     Clé du champ.
	 * @return string|int|null
	 */
	public static function read( int $post_id, string $key ) {
		$native = get_post_meta( $post_id, $key, true );
		if ( is_scalar( $native ) && '' !== $native && 0 !== $native ) {
			return is_int( $native ) ? $native : (string) $native;
		}
		if ( function_exists( 'get_field' ) ) {
			$acf = \get_field( $key, $post_id );
			if ( is_array( $acf ) && isset( $acf['ID'] ) ) {
				return (int) $acf['ID'];
			}
			if ( is_scalar( $acf ) && '' !== $acf && false !== $acf ) {
				return is_int( $acf ) ? $acf : (string) $acf;
			}
		}
		if ( function_exists( 'rwmb_meta' ) ) {
			$mb = \rwmb_meta( $key, array(), $post_id );
			if ( is_array( $mb ) && isset( $mb['ID'] ) ) {
				return (int) $mb['ID'];
			}
			if ( is_scalar( $mb ) && '' !== $mb && false !== $mb ) {
				return is_int( $mb ) ? $mb : (string) $mb;
			}
		}
		if ( is_scalar( $native ) && '' !== $native ) {
			return is_int( $native ) ? $native : (string) $native;
		}
		return null;
	}

	/* ---------- Gabarits du préréglage ---------- */

	/** Gabarits d'article et d'archive du type membre, fournis par la Suite quand le type existe. */
	public static function register_templates(): void {
		if ( ! function_exists( 'register_block_template' ) || ! isset( self::types()['membre'] ) ) {
			return;
		}
		foreach ( self::TEMPLATES as $slug ) {
			$file = dirname( FILE ) . '/templates/' . $slug . '.html';
			if ( ! is_readable( $file ) ) {
				continue;
			}
			register_block_template(
				'fluffless-suite//' . $slug,
				array(
					'title'       => 'single-membre' === $slug ? __( 'Team member', 'fluffless-suite' ) : __( 'Team members archive', 'fluffless-suite' ),
					'description' => 'single-membre' === $slug ? __( 'One team member: photo, name, role, contact details and short bio, then the free content.', 'fluffless-suite' ) : __( 'The team in a grid of member cards.', 'fluffless-suite' ),
					'content'     => strtr( (string) file_get_contents( $file ), array( '[[email]]' => esc_html__( 'Send an email', 'fluffless-suite' ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					'post_types'  => array( 'membre' ),
					'plugin'      => 'fluffless-suite',
				)
			);
		}
	}

	/* ---------- Boîte de saisie ---------- */

	/** Une boîte « Champs » sur l'écran d'édition de chaque type déclaré. */
	public static function add_boxes(): void {
		foreach ( self::types() as $slug => $type ) {
			if ( array() !== $type['fields'] ) {
				add_meta_box( 'fluffless-champs', __( 'Fields', 'fluffless-suite' ), array( self::class, 'render_box' ), $slug, 'normal', 'high' );
			}
		}
	}

	/**
	 * Rendu de la boîte : un contrôle par champ selon sa sorte.
	 *
	 * @param WP_Post $post Contenu édité.
	 */
	public static function render_box( WP_Post $post ): void {
		$type = self::types()[ $post->post_type ] ?? null;
		if ( null === $type ) {
			return;
		}
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<table class="form-table fl-champs-box"><tbody>';
		foreach ( $type['fields'] as $key => $field ) {
			$id    = 'fl-champ-' . $key;
			$value = get_post_meta( $post->ID, $key, true );
			$value = is_scalar( $value ) ? (string) $value : '';
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';
			switch ( $field['kind'] ) {
				case 'multiline':
					echo '<textarea id="' . esc_attr( $id ) . '" name="fluffless_champ[' . esc_attr( $key ) . ']" rows="4" class="large-text">' . esc_textarea( $value ) . '</textarea>';
					break;
				case 'image':
					$image_id = (int) $value;
					echo '<input type="number" min="0" step="1" id="' . esc_attr( $id ) . '" name="fluffless_champ[' . esc_attr( $key ) . ']" value="' . esc_attr( $image_id > 0 ? (string) $image_id : '' ) . '" class="small-text"> ';
					echo '<span class="description">' . esc_html__( 'Media library image ID', 'fluffless-suite' ) . '</span>';
					if ( $image_id > 0 ) {
						echo '<br>' . wp_get_attachment_image( $image_id, 'thumbnail' );
					}
					break;
				default:
					$html_type = in_array( $field['kind'], array( 'number', 'url', 'email', 'date' ), true ) ? $field['kind'] : 'text';
					echo '<input type="' . esc_attr( $html_type ) . '" id="' . esc_attr( $id ) . '" name="fluffless_champ[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="regular-text"' . ( 'number' === $html_type ? ' step="any"' : '' ) . '>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Enregistre les champs de la boîte à la sauvegarde d'un contenu d'un type déclaré.
	 *
	 * @param int     $post_id Contenu.
	 * @param WP_Post $post    Contenu.
	 */
	public static function save_box( int $post_id, WP_Post $post ): void {
		$type = self::types()[ $post->post_type ] ?? null;
		if ( null === $type || ! isset( $_POST[ self::NONCE ] ) || ! is_string( $_POST[ self::NONCE ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$raw = isset( $_POST['fluffless_champ'] ) && is_array( $_POST['fluffless_champ'] ) ? wp_unslash( $_POST['fluffless_champ'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- assaini champ par champ par sanitize_value().
		foreach ( $type['fields'] as $key => $field ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$value = self::sanitize_value( $raw[ $key ], $field['kind'] );
			if ( '' === $value || 0 === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	/* ---------- Éditeur ---------- */

	/**
	 * Blocs et attributs que le panneau propose de lier, selon la version de WordPress : le cœur
	 * n'accepte une liaison sur l'Élément de liste qu'à partir de 7.1 (avant, elle serait inopérante).
	 *
	 * @param string $wp_version Version de WordPress.
	 * @return array<string, list<string>>
	 */
	public static function bindable( string $wp_version ): array {
		$attributes = array(
			'core/paragraph' => array( 'content' ),
			'core/heading'   => array( 'content' ),
			'core/image'     => array( 'url', 'alt', 'title' ),
			'core/button'    => array( 'url', 'text' ),
		);
		if ( version_compare( $wp_version, '7.1-alpha', '>=' ) ) {
			$attributes['core/list-item'] = array( 'content' );
		}
		return $attributes;
	}

	/** Panneau « Champ personnalisé » et source côté éditeur : JS natif, sans build. */
	public static function editor_assets(): void {
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/champs-editor.js', FILE ), self::DEPS, VERSION, true );
		$types = array();
		foreach ( self::types() as $slug => $type ) {
			$fields = array();
			foreach ( $type['fields'] as $key => $field ) {
				$fields[] = array(
					'key'    => $key,
					'label'  => $field['label'],
					'kind'   => $field['kind'],
					'native' => true,
				);
			}
			foreach ( self::external_fields( $slug ) as $key => $label ) {
				if ( ! isset( $type['fields'][ $key ] ) ) {
					$fields[] = array(
						'key'    => $key,
						'label'  => $label,
						'kind'   => 'text',
						'native' => false,
					);
				}
			}
			$types[ $slug ] = $fields;
		}
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $slug ) {
			if ( ! isset( $types[ $slug ] ) ) {
				$external = array();
				foreach ( self::external_fields( $slug ) as $key => $label ) {
					$external[] = array(
						'key'    => $key,
						'label'  => $label,
						'kind'   => 'text',
						'native' => false,
					);
				}
				if ( array() !== $external ) {
					$types[ $slug ] = $external;
				}
			}
		}
		$data = array(
			'types'      => $types,
			'attributes' => self::bindable( (string) get_bloginfo( 'version' ) ),
		);
		wp_add_inline_script( self::HANDLE, 'window.flufflessChamps = ' . wp_json_encode( $data ) . ';', 'before' );
		wp_set_script_translations( self::HANDLE, 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
	}

	/**
	 * Champs ACF déclarés pour un type (groupes de champs assignés au type) : clé => libellé. Vide sans ACF.
	 *
	 * @param string $type Type de contenu.
	 * @return array<string, string>
	 */
	public static function external_fields( string $type ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}
		$out    = array();
		$groups = \acf_get_field_groups( array( 'post_type' => $type ) );
		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			$fields = is_array( $group ) && isset( $group['key'] ) ? \acf_get_fields( $group['key'] ) : array();
			foreach ( is_array( $fields ) ? $fields : array() as $field ) {
				$name = is_array( $field ) && is_string( $field['name'] ?? null ) ? $field['name'] : '';
				if ( 1 === preg_match( self::SLUG_RULE, $name ) && count( $out ) < self::MAX_FIELDS ) {
					$out[ $name ] = is_string( $field['label'] ?? null ) && '' !== $field['label'] ? $field['label'] : $name;
				}
			}
		}
		return $out;
	}

	/* ---------- Écran ---------- */

	/** Enregistre les types et leurs champs depuis l'écran, puis renouvelle les permaliens. */
	public static function handle_save(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change custom fields.', 'fluffless-suite' ) );
		}
		check_admin_referer( self::ACTION );
		$raw   = isset( $_POST['type'] ) && is_array( $_POST['type'] ) ? wp_unslash( $_POST['type'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- assaini par sanitize_types().
		$types = self::from_form( $raw );
		$new   = isset( $_POST['new'] ) && is_array( $_POST['new'] ) ? wp_unslash( $_POST['new'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- assaini par sanitize_types().
		$slug  = is_string( $new['slug'] ?? null ) ? sanitize_key( $new['slug'] ) : '';
		if ( '' !== $slug && ! isset( $types[ $slug ] ) ) {
			$types[ $slug ] = array(
				'label'    => $new['label'] ?? '',
				'singular' => $new['singular'] ?? '',
				'fields'   => array(),
			);
		}
		self::store( self::sanitize_types( $types ) );
		wp_safe_redirect( add_query_arg( 'fl_champs', 'saved', Menu::url( self::PAGE ) ) );
		exit;
	}

	/** Ajoute le type « Membre d'équipe » et ses champs (sans écraser un type membre existant). */
	public static function handle_preset(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change custom fields.', 'fluffless-suite' ) );
		}
		check_admin_referer( self::PRESET );
		$types = self::types();
		if ( ! isset( $types['membre'] ) ) {
			$types['membre'] = self::preset();
			self::store( self::sanitize_types( $types ) );
		}
		wp_safe_redirect( add_query_arg( 'fl_champs', 'preset', Menu::url( self::PAGE ) ) );
		exit;
	}

	/**
	 * Types lus depuis le formulaire : lignes de champs (clé, libellé, sorte), case Retirer par type et par champ.
	 *
	 * @param array<mixed> $raw Formulaire.
	 * @return array<string, array<string, mixed>>
	 */
	public static function from_form( array $raw ): array {
		$types = array();
		foreach ( $raw as $slug => $type ) {
			if ( ! is_string( $slug ) || ! is_array( $type ) || ! empty( $type['remove'] ) ) {
				continue;
			}
			$fields = array();
			foreach ( is_array( $type['fields'] ?? null ) ? $type['fields'] : array() as $row ) {
				if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
					continue;
				}
				$key = is_string( $row['key'] ?? null ) ? sanitize_key( $row['key'] ) : '';
				if ( '' !== $key ) {
					$fields[ $key ] = array(
						'label' => $row['label'] ?? '',
						'kind'  => $row['kind'] ?? 'text',
					);
				}
			}
			$types[ $slug ] = array(
				'label'    => $type['label'] ?? '',
				'singular' => $type['singular'] ?? '',
				'fields'   => $fields,
			);
		}
		return $types;
	}

	/**
	 * Enregistre l'option (retirée quand vide) et renouvelle les permaliens des archives.
	 *
	 * @param array<string, mixed> $types Types assainis.
	 */
	private static function store( array $types ): void {
		if ( array() === $types ) {
			delete_option( Lifecycle::OPTION_CHAMPS );
		} else {
			update_option( Lifecycle::OPTION_CHAMPS, $types, false );
		}
		self::register_types();
		flush_rewrite_rules( false );
	}

	/** Écran Champs personnalisés. */
	public static function render(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		echo '<div class="wrap">';
		Screen::header( __( 'Custom fields', 'fluffless-suite' ), __( 'Declare a content type and its fields here, without code. Each type gets its own menu entry, an edit screen with a Fields box, an archive and, in the editor, an Attributes panel to bind a paragraph, heading, image or button to a field. Place the fields once in a template or a loop card; every new entry follows.', 'fluffless-suite' ) );
		$done = isset( $_GET['fl_champs'] ) ? sanitize_key( wp_unslash( $_GET['fl_champs'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage du résultat.
		if ( 'saved' === $done ) {
			echo Screen::note( 'good', __( 'Saved. Content types and fields are registered; permalinks were refreshed.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		} elseif ( 'preset' === $done ) {
			echo Screen::note( 'good', __( 'Team members added: a Team member type with role, email, phone, LinkedIn and short bio, a loop card and two templates (member, team archive).', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		$types = self::types();
		$kinds = array(
			'text'      => __( 'Text', 'fluffless-suite' ),
			'multiline' => __( 'Multiline text', 'fluffless-suite' ),
			'number'    => __( 'Number', 'fluffless-suite' ),
			'url'       => __( 'Web address', 'fluffless-suite' ),
			'email'     => __( 'Email address', 'fluffless-suite' ),
			'date'      => __( 'Date', 'fluffless-suite' ),
			'image'     => __( 'Image', 'fluffless-suite' ),
		);
		if ( ! isset( $types['membre'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fl-champs-preset"><input type="hidden" name="action" value="' . esc_attr( self::PRESET ) . '">';
			wp_nonce_field( self::PRESET );
			echo '<p>' . esc_html__( 'Start from a ready case: a Team member type with role, email, phone, LinkedIn profile and short bio, its loop card and its member and archive templates.', 'fluffless-suite' ) . ' <button type="submit" class="button">' . esc_html__( 'Add Team members', 'fluffless-suite' ) . '</button></p></form>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		foreach ( $types as $slug => $type ) {
			echo '<h2>' . esc_html( $type['label'] ) . ' <code>' . esc_html( $slug ) . '</code></h2>';
			echo '<p><label>' . esc_html__( 'Plural name', 'fluffless-suite' ) . ' <input type="text" name="type[' . esc_attr( $slug ) . '][label]" value="' . esc_attr( $type['label'] ) . '" class="regular-text"></label> ';
			echo '<label>' . esc_html__( 'Singular name', 'fluffless-suite' ) . ' <input type="text" name="type[' . esc_attr( $slug ) . '][singular]" value="' . esc_attr( $type['singular'] ) . '" class="regular-text"></label> ';
			echo '<label><input type="checkbox" name="type[' . esc_attr( $slug ) . '][remove]" value="1"> ' . esc_html__( 'Remove this type (its entries stay in the database, hidden until the type is declared again)', 'fluffless-suite' ) . '</label></p>';
			echo '<table class="widefat striped fl-champs"><thead><tr><th scope="col">' . esc_html__( 'Key', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Label', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Kind', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Remove', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
			$rows = array();
			foreach ( $type['fields'] as $key => $field ) {
				$rows[] = array(
					'key'   => (string) $key,
					'label' => $field['label'],
					'kind'  => $field['kind'],
				);
			}
			for ( $i = 0; $i < 3; $i++ ) {
				$rows[] = array(
					'key'   => '',
					'label' => '',
					'kind'  => 'text',
				);
			}
			foreach ( $rows as $i => $field ) {
				$key  = $field['key'];
				$name = 'type[' . esc_attr( $slug ) . '][fields][' . (int) $i . ']';
				echo '<tr><td><input type="text" name="' . $name . '[key]" value="' . esc_attr( $key ) . '" pattern="[a-z][a-z0-9_-]{0,19}" placeholder="' . esc_attr__( 'key', 'fluffless-suite' ) . '"' . ( '' !== $key ? ' readonly' : '' ) . '></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name échappé ci-dessus.
				echo '<td><input type="text" name="' . $name . '[label]" value="' . esc_attr( $field['label'] ) . '" class="regular-text"></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name échappé ci-dessus.
				echo '<td><select name="' . $name . '[kind]">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name échappé ci-dessus.
				foreach ( $kinds as $kind => $kind_label ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $kind ), selected( $field['kind'], $kind, false ), esc_html( $kind_label ) );
				}
				echo '</select></td>';
				echo '<td>' . ( '' !== $key ? '<input type="checkbox" name="' . $name . '[remove]" value="1">' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name échappé ci-dessus.
			}
			echo '</tbody></table>';
		}
		echo '<h2>' . esc_html__( 'New content type', 'fluffless-suite' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'Slug', 'fluffless-suite' ) . ' <input type="text" name="new[slug]" pattern="[a-z][a-z0-9_-]{0,19}" placeholder="membre" class="regular-text"></label> ';
		echo '<label>' . esc_html__( 'Plural name', 'fluffless-suite' ) . ' <input type="text" name="new[label]" class="regular-text"></label> ';
		echo '<label>' . esc_html__( 'Singular name', 'fluffless-suite' ) . ' <input type="text" name="new[singular]" class="regular-text"></label></p>';
		echo '<p class="description">' . esc_html__( 'Fields are also plain post meta: a field declared here shows in the editor’s Attributes panel (core/post-meta binding); a field from ACF or Meta Box with the same key is read by the Fluffless binding when no native value is set.', 'fluffless-suite' ) . '</p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save types and fields', 'fluffless-suite' ) . '</button></p></form>';
		echo '</div>';
	}
}
