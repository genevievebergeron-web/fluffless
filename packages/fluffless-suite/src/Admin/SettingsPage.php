<?php
/**
 * Fluffless → Modules (groupe Réglages) : Settings API native, sans React.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Admin;

use Fluffless\Suite\Core\Http;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;
use Fluffless\Suite\Modules\Form;
use Fluffless\Suite\Modules\Images;
use Fluffless\Suite\Modules\Mail;
use Fluffless\Suite\Modules\Newsletter;
use Fluffless\Suite\Modules\Updates;
use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Le formulaire poste vers options.php : nonce (settings_fields), contrôle de capacité
 * et appel de Options::sanitize() sont faits par WordPress.
 *
 * Écran regroupé par intention (Alléger, Être trouvé, Mesurer, Présenter) : un tableau par groupe,
 * une ligne par module, ses réglages dans un panneau qui s'ouvre sous la ligne.
 *
 * Crochets publics :
 * - filtre fluffless_settings_groups  : array<id, array{title: string, lead: string, modules: list<string>}> ;
 * - filtre fluffless_visitor_scripts  : array<module, list<string|int>>, JS servi aux visiteurs : chemin relatif à
 *                                        l'extension ou absolu (fichier), entier (octets d'un script écrit en ligne).
 *                                        Hors modules, 'theme' et 'consent' sont admis.
 *   par chaque module (chemins relatifs à l'extension), pour l'accueil et la colonne « Effet ».
 *
 * @phpstan-import-type Field from Module
 * @phpstan-type Group array{title: string, lead: string, modules: list<string>}
 */
final class SettingsPage {

	public const SLUG       = 'fluffless-settings';
	public const CAPABILITY = 'manage_options';

	/** Déclare l'onglet Réglages → Modules, et la peau commune des écrans Fluffless. */
	public static function add_page(): void {
		Screen::register();
		Menu::add_tab( 'settings', self::SLUG, static fn(): string => __( 'Modules', 'fluffless-suite' ), array( self::class, 'render' ), 10 );
	}

	/**
	 * Adresse de l'écran, avec une ancre facultative.
	 *
	 * @param string $anchor Identifiant d'ancre (fl-title-updates, fl-group-present...), sans #.
	 */
	public static function url( string $anchor = '' ): string {
		return Menu::url( self::SLUG ) . ( '' === $anchor ? '' : '#' . $anchor );
	}

	/** Déclare l'option ; l'écran est rendu par render(), sans tableau de la Settings API. */
	public static function register_fields(): void {
		Options::register();
	}

	/** Écran complet. */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		wp_enqueue_script(
			'fluffless-modules-search',
			plugins_url( 'assets/admin/modules-search.js', FILE ),
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_enqueue_script(
			'fluffless-savebar',
			plugins_url( 'assets/admin/savebar.js', FILE ),
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$modules = Loader::modules();
		echo '<div class="wrap fl-settings">';
		// Une seule touche d'humour sur cet écran, et jamais dans une erreur.
		Screen::header( __( 'Modules', 'fluffless-suite' ), __( 'Each switch removes something your visitors never asked for. Less <em>fluff</em>, same site.', 'fluffless-suite' ) );
		// Le résultat d'un import passe avant tout autre message : il répond à ce que la personne vient de faire.
		Transfer::render_result();
		// Hors de Réglages de WordPress, le message « Réglages enregistrés » n'est pas affiché d'office.
		settings_errors();
		self::render_consent_hint();

		$groups = self::groups( $modules );
		self::render_finder( $groups );

		echo '<form action="' . esc_url( admin_url( 'options.php' ) ) . '" method="post">';
		settings_fields( Options::GROUP );
		foreach ( $groups as $gid => $group ) {
			self::render_group( $gid, $group, $modules );
		}
		echo '<div class="fl-savebar" data-fl-savebar>';
		submit_button( __( 'Save changes', 'fluffless-suite' ), 'primary', 'submit', false );
		echo '</div></form>';
		Form::render_delete_form();
		Updates::render_release_form();
		Transfer::render();
		echo '</div>';
	}

	/**
	 * Aller à un groupe (liens d'ancre, utiles sans JavaScript) et chercher un module (champ révélé par admin/modules-search.js).
	 *
	 * @param array<string, array> $groups Groupes affichés.
	 * @phpstan-param array<string, Group> $groups
	 */
	private static function render_finder( array $groups ): void {
		echo '<div class="fl-finder"><nav aria-label="' . esc_attr__( 'Module groups', 'fluffless-suite' ) . '"><ul>';
		foreach ( $groups as $gid => $group ) {
			printf( '<li><a href="#fl-group-%s">%s</a></li>', esc_attr( $gid ), esc_html( $group['title'] ) );
		}
		echo '</ul></nav>';
		printf(
			'<p class="fl-finder__search" hidden><label for="fl-modules-q">%1$s</label><input type="search" id="fl-modules-q" data-fl-modules-search autocomplete="off"><span class="fl-finder__count" role="status" data-fl-modules-count data-one="%2$s" data-many="%3$s" data-none="%4$s"></span></p></div>',
			esc_html__( 'Search modules', 'fluffless-suite' ),
			esc_attr__( '1 module shown', 'fluffless-suite' ),
			/* translators: %s: number of modules */
			esc_attr__( '%s modules shown', 'fluffless-suite' ),
			esc_attr__( 'No module matches', 'fluffless-suite' )
		);
	}

	/**
	 * Un groupe : tableau à quatre colonnes, un corps (tbody) par module pour garder la ligne et son panneau ensemble.
	 *
	 * @param string                              $gid     Identifiant du groupe.
	 * @param array                               $group   Groupe.
	 * @param array<string, class-string<Module>> $modules Modules connus.
	 * @phpstan-param Group $group
	 */
	private static function render_group( string $gid, array $group, array $modules ): void {
		printf(
			'<table class="fl-modules fl-hue-%1$s" id="fl-group-%1$s"><caption><h2 class="fl-modules__title">%8$s%2$s</h2>%3$s</caption><thead><tr><th scope="col">%4$s</th><th scope="col">%5$s</th><th scope="col">%6$s</th><th scope="col">%7$s</th></tr></thead>',
			esc_attr( $gid ),
			esc_html( $group['title'] ),
			'' === $group['lead'] ? '' : ' <span class="fl-modules__lead">' . esc_html( $group['lead'] ) . '</span>',
			esc_html__( 'Module', 'fluffless-suite' ),
			esc_html__( 'Effect for visitors', 'fluffless-suite' ),
			esc_html__( 'Settings', 'fluffless-suite' ),
			esc_html__( 'Active', 'fluffless-suite' ),
			Icon::tile( Icon::for_group( $gid ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG généré, balises et attributs en liste blanche (tools/build-icons.mjs).
		);
		foreach ( $group['modules'] as $id ) {
			self::render_row( $id, $modules[ $id ] );
		}
		echo '</table>';
	}

	/**
	 * Un service non essentiel est déclaré mais Fluffless Consent est absente : rien ne le charge,
	 * et aucune bannière ne le propose. Recommande l'extension gratuite.
	 */
	private static function render_consent_hint(): void {
		if ( function_exists( 'fluffless_consent_link' ) || ! self::declares_consent_services() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html__( 'A non-essential service is declared (Google Analytics, an embed or an ad pixel), but no consent banner is active, so nothing loads it. Install and activate the free Fluffless Consent plugin: the banner appears and the service loads after a yes.', 'fluffless-suite' )
		);
	}

	/**
	 * Vrai si un service soumis au consentement est déclaré : identifiant Google Analytics
	 * stocké dans la section consent, ou entrée du filtre fluffless_consent_services.
	 */
	public static function declares_consent_services(): bool {
		$option = get_option( Options::NAME, array() );
		$ga     = is_array( $option ) && is_array( $option['consent'] ?? null ) ? ( $option['consent']['ga_id'] ?? '' ) : '';
		/** Ce filtre est documenté dans Fluffless Consent (Consent::services()). */
		$services = apply_filters( 'fluffless_consent_services', array() );
		return ( is_string( $ga ) && '' !== trim( $ga ) ) || ( is_array( $services ) && array() !== $services );
	}

	/**
	 * Composants qui servent du JS aux visiteurs sans être des modules : le thème et Fluffless Consent
	 * (qui ne déclarent leurs scripts que lorsqu'ils sont actifs).
	 */
	private const SCRIPT_COMPONENTS = array( 'theme', 'consent' );

	/**
	 * Poids du JS que les modules actifs peuvent servir aux visiteurs, en octets non compressés.
	 * Seul ce que les modules déclarent est compté.
	 *
	 * @param array<string, class-string<Module>> $modules Modules connus.
	 */
	public static function visitor_js_bytes( array $modules ): int {
		$bytes = 0;
		foreach ( self::visitor_scripts() as $id => $files ) {
			if ( isset( $modules[ $id ] ) && Options::is_enabled( $id ) ) {
				$bytes += self::declared_bytes( $files );
			}
		}
		return $bytes;
	}

	/**
	 * Poids du JS servi par le thème et par Fluffless Consent, quand ils le déclarent.
	 */
	public static function component_js_bytes(): int {
		$bytes = 0;
		foreach ( self::visitor_scripts() as $id => $files ) {
			if ( in_array( $id, self::SCRIPT_COMPONENTS, true ) ) {
				$bytes += self::declared_bytes( $files );
			}
		}
		return $bytes;
	}

	/**
	 * Total affiché au tableau de bord : modules actifs, thème et bannière de consentement.
	 *
	 * @param array<string, class-string<Module>> $modules Modules connus.
	 */
	public static function visitor_js_total( array $modules ): int {
		return self::visitor_js_bytes( $modules ) + self::component_js_bytes();
	}

	/**
	 * Octets d'une liste de déclarations : fichiers (chemin relatif à l'extension ou absolu) et
	 * scripts en ligne (entier).
	 *
	 * @param list<string|int> $files Déclarations valides.
	 */
	private static function declared_bytes( array $files ): int {
		$bytes = 0;
		foreach ( $files as $file ) {
			if ( is_int( $file ) ) {
				$bytes += max( 0, $file );
				continue;
			}
			$path   = path_is_absolute( $file ) ? $file : dirname( FILE ) . '/' . ltrim( $file, '/' );
			$bytes += is_readable( $path ) ? (int) filesize( $path ) : 0;
		}
		return $bytes;
	}

	/**
	 * JS servi aux visiteurs, par module.
	 *
	 * @return array<string, list<string|int>>
	 */
	private static function visitor_scripts(): array {
		/**
		 * Filtre le JS public de chaque module.
		 *
		 * @param array<string, list<string|int>> $scripts Module => chemins (relatifs à l'extension ou absolus) ou octets d'un script en ligne.
		 */
		return self::valid_scripts( apply_filters( 'fluffless_visitor_scripts', array() ) );
	}

	/**
	 * Garde les entrées valides du filtre ; un tiers peut y renvoyer n'importe quoi.
	 *
	 * @param mixed $scripts Valeur rendue par le filtre.
	 * @return array<string, list<string|int>>
	 */
	private static function valid_scripts( $scripts ): array {
		$valid = array();
		foreach ( is_array( $scripts ) ? $scripts : array() as $id => $files ) {
			if ( is_string( $id ) && is_array( $files ) ) {
				$valid[ $id ] = array_values(
					array_filter(
						$files,
						static fn( $file ): bool => is_string( $file ) || is_int( $file )
					)
				);
			}
		}
		return $valid;
	}

	/**
	 * Groupes par intention. Tout module absent des groupes tombe dans « Autres ».
	 *
	 * @param array<string, class-string<Module>> $modules Modules connus.
	 * @return array<string, Group>
	 */
	public static function groups( array $modules ): array {
		$defaults = array(
			'lighten' => array(
				'title'   => __( 'Lighten', 'fluffless-suite' ),
				'lead'    => __( 'Less weight on every page and in the database.', 'fluffless-suite' ),
				'modules' => array( 'cleanup', 'images', 'http', 'housekeeping', 'minify', 'prune-css', 'media_webp' ),
			),
			'found'   => array(
				'title'   => __( 'Get found', 'fluffless-suite' ),
				'lead'    => __( 'Search engines understand your pages, with no extra script.', 'fluffless-suite' ),
				'modules' => array( 'seo' ),
			),
			'measure' => array(
				'title'   => __( 'Measure', 'fluffless-suite' ),
				'lead'    => __( 'Know what your site weighs and how it is visited, without following anyone.', 'fluffless-suite' ),
				'modules' => array( 'audit', 'footprint', 'stats', 'carbone', 'carbon-glance' ),
			),
			'present' => array(
				'title'   => __( 'Present', 'fluffless-suite' ),
				'lead'    => __( 'How the site looks, and what editors can change.', 'fluffless-suite' ),
				'modules' => array( 'editor', 'shape', 'footer', 'visibility', 'palette', 'styles', 'fonts', 'pexels', 'canvas', 'expression' ),
			),
			'content' => array(
				'title'   => __( 'Build and write', 'fluffless-suite' ),
				'lead'    => __( 'Ready-made sites, dynamic content, review and help for the people who write.', 'fluffless-suite' ),
				'modules' => array( 'kits', 'migration', 'conditions', 'fiches', 'champs', 'review', 'reviewer', 'quality', 'dashboard' ),
			),
			'care'    => array(
				'title'   => __( 'Stay up to date', 'fluffless-suite' ),
				'lead'    => __( 'Signed updates for the Fluffless theme and plugins.', 'fluffless-suite' ),
				'modules' => array( 'updates' ),
			),
			'protect' => array(
				'title'   => __( 'Protect and watch', 'fluffless-suite' ),
				'lead'    => __( 'Basic security, a maintenance page and the site status for whoever looks after it.', 'fluffless-suite' ),
				'modules' => array( 'security', 'maintenance', 'status' ),
			),
			'reach'   => array(
				'title'   => __( 'Reach people', 'fluffless-suite' ),
				'lead'    => __( 'Email sent by the site arrives where it should, visitors can write to you, and your events reach their calendars.', 'fluffless-suite' ),
				'modules' => array( 'mail', 'form', 'newsletter', 'events', 'utm' ),
			),
			'extend'  => array(
				'title'   => __( 'Work with extensions', 'fluffless-suite' ),
				'lead'    => __( 'WooCommerce, Sensei LMS and the established form extensions stay on the pages that need them.', 'fluffless-suite' ),
				'modules' => array( 'shop', 'courses', 'forms' ),
			),
		);
		/**
		 * Filtre les groupes de la page de réglages.
		 *
		 * @param array<string, Group> $groups Groupes, dans l'ordre d'affichage.
		 */
		return self::valid_groups( apply_filters( 'fluffless_settings_groups', $defaults ), $modules );
	}

	/**
	 * Garde les groupes valides ; tout module connu absent des groupes tombe dans « Autres ».
	 *
	 * @param mixed                               $filtered Valeur rendue par le filtre.
	 * @param array<string, class-string<Module>> $modules  Modules connus.
	 * @return array<string, Group>
	 */
	private static function valid_groups( $filtered, array $modules ): array {
		$groups = array();
		$placed = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $gid => $group ) {
			if ( ! is_string( $gid ) || ! is_array( $group ) ) {
				continue;
			}
			$ids = array();
			foreach ( is_array( $group['modules'] ?? null ) ? $group['modules'] : array() as $id ) {
				if ( is_string( $id ) && isset( $modules[ $id ] ) && ! isset( $placed[ $id ] ) ) {
					$ids[]         = $id;
					$placed[ $id ] = true;
				}
			}
			if ( array() !== $ids ) {
				$groups[ sanitize_key( $gid ) ] = array(
					'title'   => is_string( $group['title'] ?? null ) ? $group['title'] : $gid,
					'lead'    => is_string( $group['lead'] ?? null ) ? $group['lead'] : '',
					'modules' => $ids,
				);
			}
		}
		$others = array_values( array_diff( array_keys( $modules ), array_keys( $placed ) ) );
		if ( array() !== $others ) {
			$groups['other'] = array(
				'title'   => __( 'Other modules', 'fluffless-suite' ),
				'lead'    => '',
				'modules' => $others,
			);
		}
		return $groups;
	}

	/**
	 * Ligne d'un module (nom et phrase, effet, réglages, interrupteur), puis son panneau de réglages.
	 * Le panneau vit dans une seconde ligne pleine largeur ; le CSS le masque tant que le details est fermé.
	 *
	 * @param string $id           Identifiant du module.
	 * @param string $module_class Classe du module.
	 * @phpstan-param class-string<Module> $module_class
	 */
	private static function render_row( string $id, string $module_class ): void {
		$title_id = 'fl-title-' . $id;
		$desc_id  = 'fl-desc-' . $id;
		$panel_id = 'fl-panel-' . $id;

		ob_start();
		self::render_extras( $module_class );
		$fields = $module_class::fields();
		// Un champ nommé dans « with » s'affiche sur la ligne de son hôte, pas sur la sienne.
		$paired = array_filter( array_map( static fn( array $f ): string => $f['with'] ?? '', $fields ) );
		$shown  = array_diff_key( $fields, array_flip( $paired ) );
		foreach ( $shown as $key => $field ) {
			self::render_field_row( $id, $key, $field, $fields );
		}
		$panel = (string) ob_get_clean();

		echo '<tbody class="fl-module">';
		// Icône : SVG généré, balises et attributs en liste blanche (tools/build-icons.mjs).
		printf( '<tr><th scope="row"><div class="fl-module__head">%5$s<div><span class="fl-module__name" id="%1$s">%2$s</span><span class="fl-module__desc" id="%3$s">%4$s</span>', esc_attr( $title_id ), esc_html( $module_class::label() ), esc_attr( $desc_id ), esc_html( $module_class::description() ), Icon::tile( Icon::for_module( $id ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- voir ci-dessus.
		self::render_status( $module_class );
		echo '</div></div></th>';

		$js = in_array( $id, array_keys( self::visitor_scripts() ), true ) ? self::visitor_js_bytes( array( $id => $module_class ) ) : 0;
		printf( '<td data-label="%s">', esc_attr__( 'Effect for visitors', 'fluffless-suite' ) );
		if ( $js > 0 ) {
			/* translators: %s: file size, e.g. 3.5 KB */
			echo Screen::pill( 'warn', sprintf( __( 'Adds %s of JavaScript for visitors', 'fluffless-suite' ), size_format( $js, 1 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::pill().
		} else {
			echo '<span class="fl-muted">' . esc_html__( '0 KB', 'fluffless-suite' ) . '</span>';
		}
		echo '</td>';

		printf( '<td data-label="%s">', esc_attr__( 'Settings', 'fluffless-suite' ) );
		if ( '' === $panel ) {
			echo '<span class="fl-muted">' . esc_html__( 'No settings', 'fluffless-suite' ) . '</span>';
		} else {
			$summary = array() === $shown
				? __( 'Settings', 'fluffless-suite' )
				/* translators: %s: number of settings */
				: sprintf( _n( '%s setting', '%s settings', count( $shown ), 'fluffless-suite' ), number_format_i18n( count( $shown ) ) );
			printf( '<details class="fl-module__toggle"><summary aria-controls="%1$s">%2$s</summary></details>', esc_attr( $panel_id ), esc_html( $summary ) );
		}
		echo '</td>';

		printf( '<td class="fl-module__switch" data-label="%s">', esc_attr__( 'Active', 'fluffless-suite' ) );
		echo self::switch_for_module( $id, $title_id, $desc_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::switch_control().
		echo '</td></tr>';

		if ( '' !== $panel ) {
			printf( '<tr class="fl-module__panel" id="%1$s"><td colspan="4">%2$s</td></tr>', esc_attr( $panel_id ), $panel ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendu échappé champ par champ ci-dessus.
		}
		echo '</tbody>';
	}

	/**
	 * Un réglage : libellé à gauche, champ et aide à droite.
	 *
	 * @param string               $id     Identifiant du module.
	 * @param string               $key    Clé du réglage.
	 * @param array                $field  Description du champ.
	 * @param array<string, array> $fields Tous les champs du module, pour le champ jumelé.
	 * @phpstan-param Field $field
	 * @phpstan-param array<string, Field> $fields
	 */
	private static function render_field_row( string $id, string $key, array $field, array $fields ): void {
		echo '<div class="fl-field">';
		if ( 'multi' === $field['type'] ) {
			echo '<span class="fl-field__label">' . esc_html( $field['label'] ) . '</span>';
		} else {
			printf( '<label class="fl-field__label" for="%1$s">%2$s</label>', esc_attr( self::field_id( $id, $key ) ), esc_html( $field['label'] ) );
		}
		echo '<div>';
		self::render_field(
			array(
				'module' => $id,
				'key'    => $key,
				'field'  => $field,
				'paired' => isset( $field['with'] ) ? ( $fields[ $field['with'] ] ?? null ) : null,
			)
		);
		echo '</div></div>';
	}

	/**
	 * Interrupteur du module, nommé par le nom du module dans sa ligne.
	 *
	 * @param string $module   Identifiant du module.
	 * @param string $title_id Identifiant du nom du module.
	 * @param string $desc_id  Identifiant de la description.
	 */
	private static function switch_for_module( string $module, string $title_id, string $desc_id ): string {
		return Screen::switch_control( self::field_name( 'modules', $module ), self::field_id( 'modules', $module ), Options::is_enabled( $module ), $title_id, $desc_id );
	}

	/**
	 * Interrupteur du module (compatibilité : ancien rappel de add_settings_field).
	 *
	 * @param array{module: string} $args Arguments.
	 */
	public static function render_toggle( array $args ): void {
		echo Screen::switch_control( self::field_name( 'modules', $args['module'] ), self::field_id( 'modules', $args['module'] ), Options::is_enabled( $args['module'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::switch_control().
	}

	/**
	 * États utiles à la décision, visibles sans ouvrir le panneau : désactivation automatique, AVIF.
	 *
	 * @param string $module_class Classe du module.
	 * @phpstan-param class-string<Module> $module_class
	 */
	private static function render_status( string $module_class ): void {
		// État facultatif du module (voir Module) : désactivation ou activation automatique.
		$status_callback = array( $module_class, 'status' );
		if ( is_callable( $status_callback ) ) {
			$status = $status_callback();
			if ( is_string( $status ) && '' !== $status ) {
				echo Screen::note( 'warn', $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			}
		}
		// Bouton facultatif (voir Module) : là où on règle la situation.
		$action_callback = array( $module_class, 'status_action' );
		$action          = is_callable( $action_callback ) ? $action_callback() : null;
		if ( is_array( $action ) && isset( $action['text'], $action['url'] ) ) {
			printf( '<p class="fl-module__action"><a class="button button-secondary" href="%1$s">%2$s</a></p>', esc_url( (string) $action['url'] ), esc_html( (string) $action['text'] ) );
		}
		if ( Images::class === $module_class && ! wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
			echo Screen::note( 'idle', __( 'This server’s image library cannot write AVIF: WebP is used instead.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
	}

	/**
	 * Contenu propre à un module, en tête de son panneau : licence des mises à jour, refus récents du pare-feu.
	 *
	 * @param string $module_class Classe du module.
	 * @phpstan-param class-string<Module> $module_class
	 */
	private static function render_extras( string $module_class ): void {
		if ( Updates::class === $module_class ) {
			Updates::render_licence();
			return;
		}
		if ( Mail::class === $module_class ) {
			Mail::render_panel();
			return;
		}
		if ( Form::class === $module_class ) {
			Form::render_panel();
			return;
		}
		if ( Newsletter::class === $module_class ) {
			Newsletter::render_panel();
			return;
		}
		if ( Http::class !== $module_class ) {
			return;
		}
		printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( Hosts::url() ), esc_html__( 'See which servers your site contacts', 'fluffless-suite' ) );
		$refusals = Http::refusals();
		if ( array() === $refusals ) {
			return;
		}
		echo '<div class="fl-table-scroll"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Recently blocked domains', 'fluffless-suite' ) . '</caption><thead><tr><th scope="col">'
			. esc_html__( 'Blocked domain', 'fluffless-suite' ) . '</th><th scope="col">'
			. esc_html__( 'Attempts', 'fluffless-suite' ) . '</th><th scope="col">'
			. esc_html__( 'Last attempt', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
		foreach ( $refusals as $host => $entry ) {
			$date = wp_date( $format, $entry['last'] );
			printf(
				'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( $host ),
				esc_html( number_format_i18n( $entry['count'] ) ),
				esc_html( false === $date ? '' : $date )
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Champ d'un réglage de module, selon son type.
	 *
	 * @param array{module: string, key: string, field: Field, paired?: Field|null} $args Arguments.
	 */
	public static function render_field( array $args ): void {
		$field  = $args['field'];
		$paired = $args['paired'] ?? null;
		self::render_control( $args['module'], $args['key'], $field );
		if ( isset( $field['with'] ) && is_array( $paired ) ) {
			printf( ' <label for="%1$s">%2$s</label> ', esc_attr( self::field_id( $args['module'], $field['with'] ) ), esc_html( $paired['label'] ) );
			self::render_control( $args['module'], $field['with'], $paired );
		}
		if ( isset( $field['help'] ) ) {
			echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
		}
	}

	/**
	 * Contrôle de formulaire d'un champ, sans libellé ni aide.
	 *
	 * @param string $module Identifiant du module.
	 * @param string $key    Clé du réglage.
	 * @param array  $field  Description du champ.
	 * @phpstan-param Field $field
	 */
	private static function render_control( string $module, string $key, array $field ): void {
		$name  = self::field_name( $module, $key );
		$id    = self::field_id( $module, $key );
		$value = Options::get( $module, $key );

		switch ( $field['type'] ) {
			case 'bool':
				echo Screen::switch_control( $name, $id, true === $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::switch_control().
				break;
			case 'int':
				printf(
					'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" step="1">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_int( $value ) ? (string) $value : '' ),
					(int) ( $field['min'] ?? 0 ),
					(int) ( $field['max'] ?? PHP_INT_MAX )
				);
				break;
			case 'choice':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $field['options'] ?? array() as $option => $label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;
			case 'multi':
				$chosen = is_array( $value ) ? $value : array();
				printf( '<fieldset><legend class="screen-reader-text">%1$s</legend><input type="hidden" name="%2$s[]" value="">', esc_html( $field['label'] ), esc_attr( $name ) );
				foreach ( $field['options'] ?? array() as $option => $label ) {
					printf(
						'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label><br>',
						esc_attr( $name ),
						esc_attr( $option ),
						checked( in_array( $option, $chosen, true ), true, false ),
						esc_html( $label )
					);
				}
				echo '</fieldset>';
				break;
			case 'text':
			case 'url':
				printf(
					'<input type="%1$s" class="regular-text" id="%2$s" name="%3$s" value="%4$s" maxlength="%5$d"%6$s>',
					'url' === $field['type'] ? 'url' : 'text',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_string( $value ) ? $value : '' ),
					(int) Sanitize::MAX_TEXT,
					'url' === $field['type'] ? ' pattern="https://.+" placeholder="https://"' : ''
				);
				break;
			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="3" cols="50" class="large-text" maxlength="%3$d">%4$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) Sanitize::MAX_LONG_TEXT,
					esc_textarea( is_string( $value ) ? $value : '' )
				);
				break;
			case 'lines':
			case 'hosts':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="4" cols="40" class="code" spellcheck="false">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( implode( "\n", is_array( $value ) ? array_filter( $value, 'is_string' ) : array() ) )
				);
				break;
		}
	}

	/**
	 * Nom HTML : fluffless_settings[section][clé].
	 *
	 * @param string $section Section de l'option.
	 * @param string $key     Clé.
	 */
	private static function field_name( string $section, string $key ): string {
		return Options::NAME . '[' . $section . '][' . $key . ']';
	}

	/**
	 * Identifiant HTML stable.
	 *
	 * @param string $section Section de l'option.
	 * @param string $key     Clé.
	 */
	private static function field_id( string $section, string $key ): string {
		return 'fluffless-' . $section . '-' . str_replace( '_', '-', $key );
	}
}
