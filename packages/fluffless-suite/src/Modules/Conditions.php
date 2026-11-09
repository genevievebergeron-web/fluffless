<?php
/**
 * Module Conditions d'affichage : assigner un gabarit du site ou un en-tête à une catégorie,
 * un type de contenu ou des pages précises, sans ouvrir l'éditeur de site.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Les règles vivent dans l'option fluffless_conditions, une liste ordonnée ; la première qui
 * correspond à la visite gagne. Une règle porte :
 * - where    : front, home, search, 404, pages (identifiants), type:<type>, archive:<type>,
 *              tax:<taxonomie> ou term:<taxonomie>:<id> ;
 * - ids      : identifiants de pages, pour where = pages ;
 * - template : slug d'un gabarit du site ('' : le gabarit habituel) ;
 * - part     : slug d'une partie d'en-tête ('' : l'en-tête habituel).
 *
 * Gabarit : le slug est mis en tête de la hiérarchie de gabarits de WordPress, qui le cherche
 * ensuite comme d'habitude (fichier du thème ou gabarit enregistré). En-tête : le bloc
 * Partie de gabarit « header » du gabarit rendu prend le slug choisi. Rien côté visiteur.
 *
 * @phpstan-type Rule array{where: string, ids: list<int>, template: string, part: string}
 */
final class Conditions implements Module {

	public const PAGE       = 'fluffless-conditions';
	public const ACTION     = 'fluffless_conditions_save';
	public const MAX_RULES  = 50;
	public const MAX_TERMS  = 50;
	public const HEADER     = 'header';
	public const SIMPLE     = array( 'front', 'home', 'search', '404', 'pages' );
	private const TYPES     = array( 'index', '404', 'archive', 'author', 'category', 'tag', 'taxonomy', 'date', 'home', 'frontpage', 'privacypolicy', 'page', 'paged', 'search', 'single', 'singular', 'attachment' );
	private const SLUG_RULE = '/^[a-z0-9-]{1,80}$/';

	/**
	 * Règle gagnante de la requête, calculée une fois ('' : aucune ; null : pas encore).
	 *
	 * @var Rule|null|false
	 */
	private static $current = null;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'conditions';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Display conditions', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Assigns a site template or a header to a category, a content type or precise pages, without opening the Site Editor. The first matching rule wins. Nothing is added to pages.', 'fluffless-suite' );
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
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		unset( $input );
		return array();
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function register(): void {
		foreach ( self::TYPES as $type ) {
			add_filter( $type . '_template_hierarchy', array( self::class, 'template_hierarchy' ) );
		}
		add_filter( 'render_block_data', array( self::class, 'swap_header' ) );
		if ( is_admin() ) {
			Menu::add_tab( 'appearance', self::PAGE, static fn(): string => __( 'Display conditions', 'fluffless-suite' ), array( self::class, 'render' ), 40 );
			add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_save' ) );
		}
	}

	/**
	 * Règles enregistrées.
	 *
	 * @return list<Rule>
	 */
	public static function rules(): array {
		$stored = get_option( Lifecycle::OPTION_CONDITIONS, array() );
		return self::sanitize_rules( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Assainit une liste de règles : where reconnu, identifiants entiers, slugs bornés ;
	 * une règle sans gabarit ni en-tête est retirée. Cinquante règles au plus.
	 *
	 * @param array<mixed> $raw Liste brute.
	 * @return list<Rule>
	 */
	public static function sanitize_rules( array $raw ): array {
		$rules = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$where = is_string( $item['where'] ?? null ) ? $item['where'] : '';
			if ( ! self::valid_where( $where ) ) {
				continue;
			}
			$ids = array();
			if ( 'pages' === $where ) {
				$raw_ids = $item['ids'] ?? array();
				if ( is_string( $raw_ids ) ) {
					$raw_ids = preg_split( '/[\s,;]+/', $raw_ids );
				}
				foreach ( is_array( $raw_ids ) ? $raw_ids : array() as $id ) {
					$id = (int) $id;
					if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
						$ids[] = $id;
					}
				}
				if ( array() === $ids ) {
					continue;
				}
			}
			$template = self::slug( $item['template'] ?? '' );
			$part     = self::slug( $item['part'] ?? '' );
			if ( '' === $template && '' === $part ) {
				continue;
			}
			$rules[] = array(
				'where'    => $where,
				'ids'      => $ids,
				'template' => $template,
				'part'     => $part,
			);
			if ( count( $rules ) >= self::MAX_RULES ) {
				break;
			}
		}
		return $rules;
	}

	/**
	 * Un slug de gabarit ou de partie, ou ''.
	 *
	 * @param mixed $value Valeur brute.
	 */
	private static function slug( $value ): string {
		return is_string( $value ) && 1 === preg_match( self::SLUG_RULE, $value ) ? $value : '';
	}

	/**
	 * Une cible reconnue : simple, type ou archive d'un type public, taxonomie publique ou l'un de ses termes.
	 *
	 * @param string $where Cible.
	 */
	public static function valid_where( string $where ): bool {
		if ( in_array( $where, self::SIMPLE, true ) ) {
			return true;
		}
		$parts = explode( ':', $where );
		if ( 2 === count( $parts ) && in_array( $parts[0], array( 'type', 'archive' ), true ) ) {
			$type = get_post_type_object( $parts[1] );
			return $type instanceof \WP_Post_Type && $type->public && ( 'type' === $parts[0] || $type->has_archive );
		}
		if ( 2 === count( $parts ) && 'tax' === $parts[0] ) {
			$tax = get_taxonomy( $parts[1] );
			return $tax instanceof \WP_Taxonomy && $tax->public;
		}
		if ( 3 === count( $parts ) && 'term' === $parts[0] ) {
			$tax = get_taxonomy( $parts[1] );
			return $tax instanceof \WP_Taxonomy && $tax->public && (int) $parts[2] > 0 && term_exists( (int) $parts[2], $parts[1] );
		}
		return false;
	}

	/**
	 * Cibles proposées dans l'écran : valeur => libellé, dans l'ordre d'affichage (la clé « 404 »
	 * devient un entier en PHP). Les termes d'une taxonomie sont listés seulement si elle en compte
	 * cinquante ou moins.
	 *
	 * @return array<int|string, string>
	 */
	public static function choices(): array {
		$choices = array(
			'front'  => __( 'Front page', 'fluffless-suite' ),
			'home'   => __( 'Posts page', 'fluffless-suite' ),
			'search' => __( 'Search results', 'fluffless-suite' ),
			'404'    => __( 'Page not found', 'fluffless-suite' ),
			'pages'  => __( 'Precise pages (identifiers below)', 'fluffless-suite' ),
		);
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			/* translators: %s: content type name */
			$choices[ 'type:' . $type->name ] = sprintf( __( 'Every %s', 'fluffless-suite' ), $type->labels->singular_name );
			if ( $type->has_archive ) {
				/* translators: %s: content type name */
				$choices[ 'archive:' . $type->name ] = sprintf( __( '%s archive', 'fluffless-suite' ), $type->labels->singular_name );
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( 'post_format' === $tax->name ) {
				continue;
			}
			/* translators: %s: taxonomy name */
			$choices[ 'tax:' . $tax->name ] = sprintf( __( 'Every %s archive', 'fluffless-suite' ), $tax->labels->singular_name );
			$count                          = wp_count_terms( array( 'taxonomy' => $tax->name ) );
			if ( ! is_numeric( $count ) || (int) $count > self::MAX_TERMS ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $tax->name,
					'hide_empty' => false,
					'number'     => self::MAX_TERMS,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$choices[ 'term:' . $tax->name . ':' . (string) $term->term_id ] = $tax->labels->singular_name . ' : ' . $term->name;
			}
		}
		return $choices;
	}

	/**
	 * La règle correspond-elle à la requête principale ?
	 *
	 * @param array<string, mixed> $rule Règle assainie.
	 * @phpstan-param Rule $rule
	 */
	public static function matches( array $rule ): bool {
		$where = $rule['where'];
		switch ( $where ) {
			case 'front':
				return is_front_page();
			case 'home':
				return is_home();
			case 'search':
				return is_search();
			case '404':
				return is_404();
			case 'pages':
				return is_page( $rule['ids'] );
		}
		$parts = explode( ':', $where );
		if ( 'type' === $parts[0] ) {
			return is_singular( $parts[1] );
		}
		if ( 'archive' === $parts[0] ) {
			return is_post_type_archive( $parts[1] );
		}
		if ( 'tax' === $parts[0] ) {
			return self::on_taxonomy( $parts[1] );
		}
		if ( 'term' === $parts[0] && isset( $parts[2] ) ) {
			return self::on_taxonomy( $parts[1] ) && (int) get_queried_object_id() === (int) $parts[2];
		}
		return false;
	}

	/**
	 * Archive d'un terme de cette taxonomie (catégorie, étiquette ou taxonomie sur mesure).
	 *
	 * @param string $taxonomy Taxonomie.
	 */
	private static function on_taxonomy( string $taxonomy ): bool {
		if ( 'category' === $taxonomy ) {
			return is_category();
		}
		if ( 'post_tag' === $taxonomy ) {
			return is_tag();
		}
		return is_tax( $taxonomy );
	}

	/**
	 * Première règle qui correspond à la visite, ou null.
	 *
	 * @return Rule|null
	 */
	public static function current(): ?array {
		if ( null === self::$current ) {
			self::$current = false;
			foreach ( self::rules() as $rule ) {
				if ( self::matches( $rule ) ) {
					self::$current = $rule;
					break;
				}
			}
		}
		return false === self::$current ? null : self::$current;
	}

	/** Oublie la règle calculée (tests, ou nouvelle requête). */
	public static function reset(): void {
		self::$current = null;
	}

	/**
	 * Met le gabarit choisi en tête de la hiérarchie.
	 *
	 * @param mixed $templates Liste de fichiers candidats.
	 * @return mixed
	 */
	public static function template_hierarchy( $templates ) {
		if ( ! is_array( $templates ) ) {
			return $templates;
		}
		$rule = self::current();
		if ( null === $rule || '' === $rule['template'] ) {
			return $templates;
		}
		array_unshift( $templates, $rule['template'] . '.php' );
		return $templates;
	}

	/**
	 * Remplace la partie d'en-tête du gabarit par celle de la règle.
	 *
	 * @param mixed $parsed Bloc analysé.
	 * @return mixed
	 */
	public static function swap_header( $parsed ) {
		if ( ! is_array( $parsed ) || 'core/template-part' !== ( $parsed['blockName'] ?? '' ) || is_admin() ) {
			return $parsed;
		}
		$attrs = is_array( $parsed['attrs'] ?? null ) ? $parsed['attrs'] : array();
		if ( self::HEADER !== ( $attrs['slug'] ?? '' ) ) {
			return $parsed;
		}
		$rule = self::current();
		if ( null === $rule || '' === $rule['part'] || self::HEADER === $rule['part'] ) {
			return $parsed;
		}
		$parsed['attrs']['slug'] = $rule['part'];
		return $parsed;
	}

	/**
	 * Gabarits du site : slug => titre.
	 *
	 * @return array<string, string>
	 */
	public static function templates(): array {
		return self::list_block_templates( array(), 'wp_template' );
	}

	/**
	 * Parties d'en-tête : slug => titre.
	 *
	 * @return array<string, string>
	 */
	public static function headers(): array {
		return self::list_block_templates( array( 'area' => self::HEADER ), 'wp_template_part' );
	}

	/**
	 * Lit les gabarits ou parties disponibles (fichiers du thème et enregistrés).
	 *
	 * @param array<string, mixed>             $query Filtre de get_block_templates().
	 * @param 'wp_template'|'wp_template_part' $type  wp_template ou wp_template_part.
	 * @return array<string, string>
	 */
	private static function list_block_templates( array $query, string $type ): array {
		$out = array();
		foreach ( get_block_templates( $query, $type ) as $template ) {
			if ( 1 === preg_match( self::SLUG_RULE, $template->slug ) ) {
				$out[ $template->slug ] = '' !== $template->title ? $template->title : $template->slug;
			}
		}
		return $out;
	}

	/** Enregistre les règles de l'écran. */
	public static function handle_save(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change display conditions.', 'fluffless-suite' ) );
		}
		check_admin_referer( self::ACTION );
		$raw   = isset( $_POST['rule'] ) && is_array( $_POST['rule'] ) ? wp_unslash( $_POST['rule'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- assaini par sanitize_rules().
		$rules = array();
		foreach ( $raw as $item ) {
			if ( is_array( $item ) && empty( $item['delete'] ) ) {
				$rules[] = $item;
			}
		}
		$rules = self::sanitize_rules( $rules );
		if ( array() === $rules ) {
			delete_option( Lifecycle::OPTION_CONDITIONS );
		} else {
			update_option( Lifecycle::OPTION_CONDITIONS, $rules, false );
		}
		wp_safe_redirect( add_query_arg( 'fl_conditions', 'saved', Menu::url( self::PAGE ) ) );
		exit;
	}

	/** Écran Conditions d'affichage. */
	public static function render(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		echo '<div class="wrap">';
		Screen::header( __( 'Display conditions', 'fluffless-suite' ), __( 'Give a category, a content type or precise pages their own template or header. Rules are read from top to bottom; the first that matches wins.', 'fluffless-suite' ) );
		$done = isset( $_GET['fl_conditions'] ) ? sanitize_key( wp_unslash( $_GET['fl_conditions'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage du résultat.
		if ( 'saved' === $done ) {
			echo Screen::note( 'good', __( 'Saved. Visitors see the new templates and headers right away.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		$templates = self::templates();
		$headers   = self::headers();
		$choices   = self::choices();
		$rules     = self::rules();
		$rules[]   = array(
			'where'    => '',
			'ids'      => array(),
			'template' => '',
			'part'     => '',
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<table class="widefat striped fl-conditions"><thead><tr><th scope="col">' . esc_html__( 'Where', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Page identifiers', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Template', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Header', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Remove', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
		foreach ( $rules as $i => $rule ) {
			$name = 'rule[' . (int) $i . ']';
			$last = count( $rules ) - 1 === $i;
			echo '<tr>';
			echo '<td><label class="screen-reader-text" for="fl-where-' . (int) $i . '">' . esc_html__( 'Where', 'fluffless-suite' ) . '</label><select id="fl-where-' . (int) $i . '" name="' . esc_attr( $name ) . '[where]">';
			if ( $last ) {
				echo '<option value="">' . esc_html__( 'Add a rule…', 'fluffless-suite' ) . '</option>';
			}
			foreach ( $choices as $value => $label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( $rule['where'], (string) $value, false ), esc_html( $label ) );
			}
			echo '</select></td>';
			echo '<td><label class="screen-reader-text" for="fl-ids-' . (int) $i . '">' . esc_html__( 'Page identifiers', 'fluffless-suite' ) . '</label><input type="text" id="fl-ids-' . (int) $i . '" name="' . esc_attr( $name ) . '[ids]" value="' . esc_attr( implode( ', ', $rule['ids'] ) ) . '" placeholder="12, 34" inputmode="numeric"></td>';
			echo '<td>' . self::select( $name . '[template]', 'fl-template-' . (int) $i, __( 'Template', 'fluffless-suite' ), __( 'Usual template', 'fluffless-suite' ), $templates, $rule['template'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans select().
			echo '<td>' . self::select( $name . '[part]', 'fl-part-' . (int) $i, __( 'Header', 'fluffless-suite' ), __( 'Usual header', 'fluffless-suite' ), $headers, $rule['part'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans select().
			echo '<td>';
			if ( ! $last ) {
				echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '[delete]" value="1"> ' . esc_html__( 'Remove', 'fluffless-suite' ) . '</label>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Page identifiers count only for “Precise pages”: the number in the page address in the admin, separated by commas. Templates and headers listed are those of the theme and those saved in the Site Editor; a header made there appears here as soon as it is saved in the Header area.', 'fluffless-suite' ) . '</p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save the rules', 'fluffless-suite' ) . '</button></p></form>';
		echo '</div>';
	}

	/**
	 * Liste déroulante d'un slug, avec un premier choix vide.
	 *
	 * @param string                $name    Nom du champ.
	 * @param string                $id      Identifiant HTML.
	 * @param string                $label   Libellé (lecteur d'écran).
	 * @param string                $none    Libellé du choix vide.
	 * @param array<string, string> $options slug => titre.
	 * @param string                $value   Valeur courante.
	 */
	private static function select( string $name, string $id, string $label, string $none, array $options, string $value ): string {
		$html = '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $none ) . '</option>';
		if ( '' !== $value && ! isset( $options[ $value ] ) ) {
			/* translators: %s: template slug */
			$options[ $value ] = sprintf( __( '%s (missing)', 'fluffless-suite' ), $value );
		}
		foreach ( $options as $slug => $title ) {
			$html .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $slug ), selected( $value, (string) $slug, false ), esc_html( $title ) );
		}
		return $html . '</select>';
	}
}
