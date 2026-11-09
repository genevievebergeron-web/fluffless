<?php
/**
 * Cours sobres : Sensei LMS reste sur les pages de cours.
 *
 * Sensei envoie ses quatre feuilles et la police d'icônes de WordPress (dashicons, 35 ko)
 * sur toutes les pages publiques, même sans cours dessus. Ce module les retire ailleurs.
 *
 * @package Fluffless_Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\PluginAction;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Module Cours sobres.
 */
final class Courses implements Module {

	/** Identifiant de la police d'icônes de WordPress. */
	public const DASHICONS = 'dashicons';

	/** {@inheritDoc} */
	public static function id(): string {
		return 'courses';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Lean courses', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Keeps Sensei LMS on the pages that teach. Its styles and the WordPress icon font leave the rest of the site.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'assets'    => true,
			'dashicons' => true,
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
			'assets'    => array(
				'type'  => 'bool',
				'label' => __( 'Load Sensei only on course pages', 'fluffless-suite' ),
				'help'  => __( 'A page counts as a course page when it is a course, a lesson, a quiz, a module, a course list, a learner profile, one of the pages Sensei is set to use, or when it holds a Sensei block. Everywhere else, Sensei’s styles and scripts are not sent.', 'fluffless-suite' ),
			),
			'dashicons' => array(
				'type'  => 'bool',
				'label' => __( 'Keep the WordPress icon font off other pages for visitors', 'fluffless-suite' ),
				'help'  => __( 'Sensei loads the icon font on every page. Outside course pages it is dropped for visitors; anyone who sees the admin bar keeps it, the bar needs it.', 'fluffless-suite' ),
			),
		);
	}

	/** Sensei LMS est actif. */
	public static function sensei_active(): bool {
		return class_exists( 'Sensei_Main' ) && defined( 'SENSEI_LMS_PLUGIN_FILE' );
	}

	/** {@inheritDoc} */
	public static function status(): string {
		if ( ! self::sensei_active() ) {
			return __( 'Sensei LMS is not active: this module has nothing to do until it is. To use it: 1) go to Plugins → Add Plugin and install Sensei LMS (or find it under Installed Plugins), 2) activate it, 3) come back here and choose what to keep off course pages.', 'fluffless-suite' );
		}
		return '';
	}

	/**
	 * Bouton sous l'état : activer ou installer Sensei LMS.
	 *
	 * @return array{text: string, url: string}|null
	 */
	public static function status_action(): ?array {
		return self::sensei_active() ? null : PluginAction::for_plugin( 'sensei-lms/sensei-lms.php', 'Sensei LMS' );
	}

	/** {@inheritDoc} */
	public function register(): void {
		if ( ! self::sensei_active() ) {
			return;
		}
		if ( Options::get_bool( self::id(), 'assets' ) || Options::get_bool( self::id(), 'dashicons' ) ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'dequeue_off_courses' ), 100 );
		}
	}

	/** La page en cours est une page de cours : Sensei y garde tout. */
	public static function is_course_page(): bool {
		$course = function_exists( 'is_sensei' ) && is_sensei();
		/**
		 * Force ou empêche le retrait de Sensei sur la page en cours.
		 *
		 * @param bool $course True si la page a besoin de Sensei.
		 */
		return (bool) apply_filters( 'fluffless_course_page', $course );
	}

	/** Adresse du dossier de Sensei, base de ses feuilles et scripts. */
	public static function sensei_url(): string {
		return defined( 'SENSEI_LMS_PLUGIN_FILE' ) ? plugin_dir_url( (string) constant( 'SENSEI_LMS_PLUGIN_FILE' ) ) : '';
	}

	/**
	 * L'identifiant enregistré pointe dans le dossier de Sensei.
	 *
	 * @param \_WP_Dependency|null $item Dépendance enregistrée.
	 */
	private static function from_sensei( ?\_WP_Dependency $item ): bool {
		$base = self::sensei_url();
		return null !== $item && '' !== $base && is_string( $item->src ) && str_starts_with( $item->src, $base );
	}

	/** Retire les feuilles et scripts de Sensei, et la police d'icônes, quand la page n'enseigne pas. */
	public static function dequeue_off_courses(): void {
		if ( self::is_course_page() ) {
			return;
		}
		$styles  = wp_styles();
		$scripts = wp_scripts();
		if ( Options::get_bool( self::id(), 'assets' ) ) {
			foreach ( $styles->queue as $handle ) {
				if ( self::from_sensei( $styles->registered[ $handle ] ?? null ) ) {
					wp_dequeue_style( $handle );
				}
			}
			foreach ( $scripts->queue as $handle ) {
				if ( self::from_sensei( $scripts->registered[ $handle ] ?? null ) ) {
					wp_dequeue_script( $handle );
				}
			}
		}
		if ( Options::get_bool( self::id(), 'dashicons' ) && ! is_admin_bar_showing() ) {
			wp_dequeue_style( self::DASHICONS );
		}
	}
}
