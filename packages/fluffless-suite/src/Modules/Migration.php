<?php
/**
 * Module Migration depuis un constructeur de pages : repère les pages faites avec un constructeur,
 * les convertit sur place en blocs natifs (contenu d'avant gardé, remise en place possible) et
 * rend compte de ce qui a été gardé, laissé en texte, ignoré ou à refaire.
 *
 * Une page convertie est un point de départ, pas une page finie : le contenu et la structure y
 * sont, la mise en forme suit les styles du site. Les données du constructeur ne sont jamais
 * supprimées.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Sanitize;
use Fluffless\Suite\Modules\Fonts;
use Fluffless\Suite\Modules\Media\Cli;
use Fluffless\Suite\Modules\Migration\Migrator;
use Fluffless\Suite\Modules\Migration\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Migration depuis un constructeur de pages.
 */
final class Migration implements Module {

	/** Écran et action admin-post. */
	public const PAGE   = 'fluffless-migration';
	public const ACTION = 'fluffless_migrate';

	/** Identifiant du module. */
	public static function id(): string {
		return 'migration';
	}

	/** Nom affiché. */
	public static function label(): string {
		return __( 'Page builder migration', 'fluffless-suite' );
	}

	/** Description affichée. */
	public static function description(): string {
		return __( 'Finds the pages made with a page builder and converts them in place to native blocks: content and structure are kept, the look follows the site styles, and every page can be put back as it was. The builder data is never deleted.', 'fluffless-suite' );
	}

	/** Éteint d'office : à allumer le temps d'une migration. */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** Aucun réglage. */
	public static function defaults(): array {
		return array();
	}

	/**
	 * Assainissement de la section.
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		return Sanitize::section( $input, self::fields(), self::defaults() );
	}

	/** Aucun champ. */
	public static function fields(): array {
		return array();
	}

	/** Aucun état particulier. */
	public static function status(): string {
		return '';
	}

	/** Pose les crochets : onglet Contenu et action de conversion. */
	public function register(): void {
		Cli::add( 'fluffless migrate', array( self::class, 'cli_migrate' ) );
		if ( ! is_admin() ) {
			return;
		}
		add_action(
			'admin_menu',
			static function (): void {
				Menu::add_tab( 'content', self::PAGE, static fn(): string => __( 'Migration', 'fluffless-suite' ), array( self::class, 'render' ), 40 );
			}
		);
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/** Reçoit le formulaire : droit, nonce, conversion ou remise en place, retour à l'écran. */
	public static function handle(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'fluffless-suite' ), 403 );
		}
		check_admin_referer( self::ACTION );
		wp_safe_redirect( self::run( (array) wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Lit les champs du formulaire, agit et renvoie l'adresse de retour, résultat en paramètres.
	 *
	 * @param array<mixed> $input Champs envoyés.
	 */
	public static function run( array $input ): string {
		$url     = admin_url( 'admin.php?page=' . self::PAGE );
		$convert = is_numeric( $input['convert'] ?? null ) ? (int) $input['convert'] : 0;
		$revert  = is_numeric( $input['revert'] ?? null ) ? (int) $input['revert'] : 0;
		if ( $convert > 0 ) {
			$outcome = Migrator::convert( $convert, ! empty( $input['keep_colors'] ) );
			if ( $outcome['ok'] ) {
				return add_query_arg( 'fl_migrated', (string) $convert, $url );
			}
			return add_query_arg( 'fl_migration_error', $outcome['code'], $url );
		}
		if ( $revert > 0 ) {
			if ( Migrator::revert( $revert ) ) {
				return add_query_arg( 'fl_reverted', (string) $revert, $url );
			}
			return add_query_arg( 'fl_migration_error', 'revert', $url );
		}
		if ( ! empty( $input['convert_all'] ) ) {
			$batch = Migrator::convert_all( ! empty( $input['keep_colors'] ) );
			return add_query_arg(
				array(
					'fl_batch'     => (string) $batch['converted'],
					'fl_failed'    => (string) $batch['failed'],
					'fl_remaining' => (string) $batch['remaining'],
				),
				$url
			);
		}
		if ( ! empty( $input['apply_palette'] ) ) {
			return add_query_arg( 'fl_palette', Migrator::apply_palette(), $url );
		}
		if ( ! empty( $input['apply_fonts'] ) ) {
			return add_query_arg( 'fl_fonts', Migrator::apply_fonts(), $url );
		}
		return add_query_arg( 'fl_migration_error', 'nothing', $url );
	}

	/** Écran Migration. */
	public static function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap">';
		Screen::header( __( 'Migration', 'fluffless-suite' ), __( 'The pages made with a page builder, ready to become native blocks. Convert one page, look at it, and put it back if needed.', 'fluffless-suite' ) );
		self::render_result();
		echo '<p>' . esc_html__( 'A converted page is a draft to review: the words, images, links and sections are kept, the look follows the site styles, and fixed colours are dropped unless asked. The builder data stays on the page, so the conversion can be undone at any time. Putting a page back asks for confirmation first, because it replaces the page content with the copy kept before the conversion.', 'fluffless-suite' ) . '</p>';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="fl-migration">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		self::render_globals();
		self::render_templates();
		$found = Migrator::detect();
		if ( array() === $found ) {
			echo Screen::note( 'good', __( 'No page made with a page builder was found.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			echo '</form></div>';
			return;
		}
		echo '<p><label><input type="checkbox" name="keep_colors" value="1"> ' . esc_html__( 'Keep the fixed colours of the builder (backgrounds and text). Otherwise the site palette applies.', 'fluffless-suite' ) . '</label></p>';
		$pending = count( array_filter( $found, static fn( array $page ): bool => '' === $page['migrated'] ) );
		if ( $pending > 0 ) {
			echo '<p><button type="submit" class="button button-secondary" name="convert_all" value="1">' . esc_html(
				sprintf(
					/* translators: %d: number of pages */
					_n( 'Convert the %d page still to do', 'Convert the %d pages still to do', $pending, 'fluffless-suite' ),
					$pending
				)
			) . '</button> <span class="description">' . esc_html(
				sprintf(
					/* translators: %d: number of pages per batch */
					__( '%d pages at a time; press again to continue. Each page keeps its builder data and can be put back.', 'fluffless-suite' ),
					Migrator::BATCH
				)
			) . '</span></p>';
		}
		echo '<div class="fl-table-scroll"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Pages made with a page builder', 'fluffless-suite' ) . '</caption>';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Page', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Type', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Elements', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'State', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Action', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
		foreach ( $found as $page ) {
			self::render_row( $page );
		}
		echo '</tbody></table></div></form></div>';
	}

	/**
	 * Une ligne du tableau.
	 *
	 * @param array{id: int, title: string, type: string, status: string, builder: string, elements: int, migrated: string} $page Page repérée.
	 */
	private static function render_row( array $page ): void {
		$edit = get_edit_post_link( $page['id'], 'raw' );
		$view = get_permalink( $page['id'] );
		$type = get_post_type_object( $page['type'] );
		echo '<tr><td>';
		if ( is_string( $edit ) && '' !== $edit ) {
			echo '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $page['title'] ) . '</strong></a>';
		} else {
			echo '<strong>' . esc_html( $page['title'] ) . '</strong>';
		}
		if ( is_string( $view ) && 'publish' === $page['status'] ) {
			echo ' <a href="' . esc_url( $view ) . '" class="fl-muted">' . esc_html__( 'View', 'fluffless-suite' ) . '</a>';
		}
		echo '</td><td>' . esc_html( null !== $type && isset( $type->labels->singular_name ) ? (string) $type->labels->singular_name : $page['type'] );
		if ( 'publish' !== $page['status'] ) {
			echo ' <span class="fl-muted">(' . esc_html( $page['status'] ) . ')</span>';
		}
		echo '</td><td>' . esc_html( number_format_i18n( $page['elements'] ) ) . '</td><td>';
		if ( '' !== $page['migrated'] ) {
			/* translators: %s: date */
			echo esc_html( sprintf( __( 'Converted on %s', 'fluffless-suite' ), wp_date( (string) get_option( 'date_format' ), (int) strtotime( $page['migrated'] . ' UTC' ) ) ) );
		} else {
			echo esc_html__( 'Page builder', 'fluffless-suite' );
		}
		echo '</td><td>';
		if ( '' !== $page['migrated'] ) {
			echo '<button type="submit" name="revert" value="' . esc_attr( (string) $page['id'] ) . '" class="button" onclick="return window.confirm(' . esc_attr( (string) wp_json_encode( self::revert_warning( $page['id'] ) ) ) . ');">' . esc_html__( 'Put back', 'fluffless-suite' ) . '</button> ';
			echo '<button type="submit" name="convert" value="' . esc_attr( (string) $page['id'] ) . '" class="button">' . esc_html__( 'Convert again', 'fluffless-suite' ) . '</button>';
		} else {
			echo '<button type="submit" name="convert" value="' . esc_attr( (string) $page['id'] ) . '" class="button button-primary">' . esc_html__( 'Convert', 'fluffless-suite' ) . '</button>';
		}
		echo '</td></tr>';
	}

	/**
	 * Avertissement affiché avant « Put back » : la page reprend la copie d'avant la conversion, les
	 * modifications faites depuis ne restent que dans les révisions de WordPress (C-S5-08).
	 *
	 * @param int $post_id Page.
	 */
	private static function revert_warning( int $post_id ): string {
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && 0 === wp_revisions_to_keep( $post ) ) {
			return __( 'Putting this page back replaces its content with the copy kept before the conversion. Revisions are turned off on this site, so changes made since the conversion cannot be recovered. Continue?', 'fluffless-suite' );
		}
		return __( 'Putting this page back replaces its content with the copy kept before the conversion. Changes made since the conversion are not lost: they stay in the page revisions, in the page editor. Continue?', 'fluffless-suite' );
	}

	/** Note de résultat après une conversion ou une remise en place, avec le rapport. */
	private static function render_result(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- lecture d'un résultat de redirection, aucune action.
		$migrated = isset( $_GET['fl_migrated'] ) ? absint( $_GET['fl_migrated'] ) : 0;
		$reverted = isset( $_GET['fl_reverted'] ) ? absint( $_GET['fl_reverted'] ) : 0;
		$error    = isset( $_GET['fl_migration_error'] ) ? sanitize_key( (string) wp_unslash( $_GET['fl_migration_error'] ) ) : '';
		$palette  = isset( $_GET['fl_palette'] ) ? sanitize_key( (string) wp_unslash( $_GET['fl_palette'] ) ) : '';
		$fonts    = isset( $_GET['fl_fonts'] ) ? sanitize_key( (string) wp_unslash( $_GET['fl_fonts'] ) ) : '';
		$batch    = isset( $_GET['fl_batch'] ) ? absint( $_GET['fl_batch'] ) : -1;
		$failed   = isset( $_GET['fl_failed'] ) ? absint( $_GET['fl_failed'] ) : 0;
		$left     = isset( $_GET['fl_remaining'] ) ? absint( $_GET['fl_remaining'] ) : 0;
		// phpcs:enable
		if ( $batch >= 0 ) {
			/* translators: 1: pages converted, 2: pages that failed, 3: pages still to do */
			$text = sprintf( __( '%1$d page(s) converted, %2$d failed, %3$d still to do.', 'fluffless-suite' ), $batch, $failed, $left );
			if ( $left > 0 ) {
				$text .= ' ' . __( 'Press the button again to continue.', 'fluffless-suite' );
			}
			echo Screen::note( $failed > 0 ? 'bad' : 'good', $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			return;
		}
		if ( $migrated > 0 ) {
			$title = get_the_title( $migrated );
			/* translators: %s: page title */
			echo Screen::note( 'good', sprintf( __( '“%s” is now made of native blocks. Open it in the editor to review it.', 'fluffless-suite' ), '' === $title ? (string) $migrated : $title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			$report = Migrator::report( $migrated );
			if ( null !== $report ) {
				self::render_report( $report );
			}
		} elseif ( $reverted > 0 ) {
			$title = get_the_title( $reverted );
			/* translators: %s: page title */
			echo Screen::note( 'good', sprintf( __( '“%s” is back as it was before the conversion.', 'fluffless-suite' ), '' === $title ? (string) $reverted : $title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		} elseif ( '' !== $palette ) {
			$messages = array(
				'ok'   => array( 'good', __( 'The colours of the page builder are now the site palette. The Palette screen can undo it.', 'fluffless-suite' ) ),
				'off'  => array( 'bad', __( 'The Palette module is off: turn it on in Fluffless → Modules to apply these colours.', 'fluffless-suite' ) ),
				'fail' => array( 'bad', __( 'These colours could not be applied.', 'fluffless-suite' ) ),
			);
			$note     = $messages[ $palette ] ?? $messages['fail'];
			echo Screen::note( $note[0], $note[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		} elseif ( '' !== $fonts ) {
			$messages = array(
				'applied'   => array( 'good', __( 'The font duo is applied to the site. The Fonts screen can undo it.', 'fluffless-suite' ) ),
				'download'  => array( 'bad', __( 'A font file could not be downloaded: the site is unchanged.', 'fluffless-suite' ) ),
				'integrity' => array( 'bad', Fonts::failure_message( 'integrity' ) ),
				'write'     => array( 'bad', Fonts::failure_message( 'write' ) ),
				'save'      => array( 'bad', Fonts::failure_message( 'save' ) ),
				'off'       => array( 'bad', __( 'The Fonts module is off: turn it on in Fluffless → Modules to apply this duo.', 'fluffless-suite' ) ),
				'error'     => array( 'bad', __( 'This duo could not be applied.', 'fluffless-suite' ) ),
			);
			$note     = $messages[ $fonts ] ?? $messages['error'];
			echo Screen::note( $note[0], $note[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		} elseif ( '' !== $error ) {
			$messages = array(
				'missing'     => __( 'This page no longer exists.', 'fluffless-suite' ),
				'unsupported' => __( 'This page was not made with a supported page builder.', 'fluffless-suite' ),
				'empty'       => __( 'Nothing could be converted on this page: it stays as it is.', 'fluffless-suite' ),
				'write'       => __( 'The page could not be saved.', 'fluffless-suite' ),
				'revert'      => __( 'Nothing to put back on this page.', 'fluffless-suite' ),
				'nothing'     => __( 'Nothing was asked.', 'fluffless-suite' ),
			);
			echo Screen::note( 'bad', $messages[ $error ] ?? __( 'Something went wrong.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
	}

	/**
	 * WP-CLI : `wp fluffless migrate [--all] [--post=<id>] [--revert=<id>] [--keep-colors] [--dry-run]`.
	 * Sans option, liste les pages repérées. --dry-run est vrai par défaut : rien n'est écrit sans
	 * --no-dry-run. Les messages restent en anglais, comme les autres commandes de la Suite.
	 *
	 * @param array<int, string>   $args       Arguments positionnels (ignorés).
	 * @param array<string, mixed> $assoc_args Options.
	 * @return array{action: string, done: int, failed: int, remaining: int}
	 */
	public static function cli_migrate( array $args, array $assoc_args ): array {
		unset( $args );
		$dry    = Cli::flag( $assoc_args, 'dry-run', true );
		$keep   = Cli::flag( $assoc_args, 'keep-colors', false );
		$post   = is_numeric( $assoc_args['post'] ?? null ) ? (int) $assoc_args['post'] : 0;
		$revert = is_numeric( $assoc_args['revert'] ?? null ) ? (int) $assoc_args['revert'] : 0;
		$all    = Cli::flag( $assoc_args, 'all', false );
		$prefix = $dry ? '[dry-run] ' : '';
		if ( $revert > 0 ) {
			$ok = $dry ? is_array( get_post_meta( $revert, Migrator::META_BACKUP, true ) ) : Migrator::revert( $revert );
			Cli::log( sprintf( '%s#%d %s', $prefix, $revert, $ok ? 'put back' : 'nothing to put back' ) );
			Cli::success( $dry ? 'Add --no-dry-run to do it.' : 'Done.' );
			return self::cli_result( 'revert', $ok ? 1 : 0, $ok ? 0 : 1, 0 );
		}
		if ( $post > 0 ) {
			if ( $dry ) {
				$ok   = null !== Migrator::reader( $post );
				$code = $ok ? 'converted' : 'unsupported';
			} else {
				$outcome = Migrator::convert( $post, $keep );
				$ok      = $outcome['ok'];
				$code    = $outcome['code'];
			}
			Cli::log( sprintf( '%s#%d %s', $prefix, $post, $ok ? 'converted' : 'not converted (' . $code . ')' ) );
			Cli::success( $dry ? 'Add --no-dry-run to do it.' : 'Done.' );
			return self::cli_result( 'post', $ok ? 1 : 0, $ok ? 0 : 1, 0 );
		}
		$found   = Migrator::detect();
		$pending = array_filter( $found, static fn( array $page ): bool => '' === $page['migrated'] );
		if ( $all && ! $dry ) {
			$batch = Migrator::convert_all( $keep, Migrator::LIMIT );
			Cli::success( sprintf( '%d page(s) converted, %d failed, %d still to do.', $batch['converted'], $batch['failed'], $batch['remaining'] ) );
			return self::cli_result( 'all', $batch['converted'], $batch['failed'], $batch['remaining'] );
		}
		foreach ( $found as $page ) {
			Cli::log( sprintf( '%s#%d %s (%s, %d elements)%s', $prefix, $page['id'], $page['title'], $page['type'], $page['elements'], '' === $page['migrated'] ? '' : ' converted on ' . $page['migrated'] ) );
		}
		Cli::success( sprintf( $all ? '%d page(s) would be converted. Add --no-dry-run to do it.' : '%d page(s) still to convert. Add --all --no-dry-run to convert them.', count( $pending ) ) );
		return self::cli_result( 'list', 0, 0, count( $pending ) );
	}

	/**
	 * Résultat d'une commande, pour les tests.
	 *
	 * @param string $action    Action menée.
	 * @param int    $done      Pages traitées.
	 * @param int    $failed    Pages ratées.
	 * @param int    $remaining Pages restantes.
	 * @return array{action: string, done: int, failed: int, remaining: int}
	 */
	private static function cli_result( string $action, int $done, int $failed, int $remaining ): array {
		return array(
			'action'    => $action,
			'done'      => $done,
			'failed'    => $failed,
			'remaining' => $remaining,
		);
	}

	/** Carte des réglages globaux relus du constructeur : palette candidate et duo de polices. */
	private static function render_globals(): void {
		$proposal = Migrator::globals();
		if ( null === $proposal ) {
			return;
		}
		echo '<div class="fl-card fl-migration-globals"><h2>' . esc_html__( 'Global settings of the page builder', 'fluffless-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Read from the builder, proposed and never applied on their own.', 'fluffless-suite' ) . '</p>';
		if ( array() !== $proposal['colors'] ) {
			echo '<p><strong>' . esc_html__( 'Colours', 'fluffless-suite' ) . '</strong></p><ul class="fl-migration-colors">';
			foreach ( $proposal['colors'] as $hint => $color ) {
				echo '<li><span class="fl-swatch" style="background:' . esc_attr( $color ) . '" aria-hidden="true"></span> ' . esc_html( $hint ) . ' <code>' . esc_html( $color ) . '</code></li>';
			}
			echo '</ul>';
			if ( null === $proposal['palette'] ) {
				echo '<p>' . esc_html__( 'Not enough colours to propose a palette: at least a text colour and a primary colour are needed.', 'fluffless-suite' ) . '</p>';
			} elseif ( array() !== $proposal['failures'] ) {
				/* translators: %s: list of colour pairs */
				echo '<p>' . esc_html( sprintf( __( 'These colours do not pass the contrast checks (%s): adjust them in the Palette screen.', 'fluffless-suite' ), implode( ', ', $proposal['failures'] ) ) ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'These colours pass the contrast checks.', 'fluffless-suite' ) . ' <button type="submit" name="apply_palette" value="1" class="button">' . esc_html__( 'Use them as the site palette', 'fluffless-suite' ) . '</button></p>';
			}
		}
		if ( array() !== $proposal['families'] ) {
			$parts = array();
			foreach ( $proposal['families'] as $hint => $family ) {
				$parts[] = $hint . ' : ' . $family;
			}
			/* translators: %s: list of font families */
			echo '<p><strong>' . esc_html__( 'Fonts', 'fluffless-suite' ) . '</strong> ' . esc_html( implode( ', ', $parts ) ) . '</p>';
			if ( null !== $proposal['pair'] ) {
				$pair = Fonts::PAIRS[ $proposal['pair'] ];
				/* translators: 1: heading family, 2: body family */
				echo '<p>' . esc_html( sprintf( __( 'Matches the duo %1$s + %2$s of the font bank.', 'fluffless-suite' ), self::bank_name( $pair[0] ), self::bank_name( $pair[1] ) ) ) . ' <button type="submit" name="apply_fonts" value="1" class="button">' . esc_html__( 'Apply this duo', 'fluffless-suite' ) . '</button></p>';
			} else {
				echo '<p>' . esc_html__( 'No duo of the font bank matches these families: choose one in the Fonts screen.', 'fluffless-suite' ) . '</p>';
			}
		}
		echo '</div>';
	}

	/** Carte des gabarits du constructeur à refaire à la main. */
	private static function render_templates(): void {
		$templates = Migrator::templates();
		if ( array() === $templates ) {
			return;
		}
		echo '<div class="fl-card fl-migration-templates"><h2>' . esc_html__( 'Templates of the page builder', 'fluffless-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Headers, footers, post and archive templates are rebuilt in the Site Editor. Popups have no native equivalent: their content can be moved into a page.', 'fluffless-suite' ) . '</p><ul>';
		foreach ( $templates as $type => $titles ) {
			echo '<li><strong>' . esc_html( $type ) . '</strong> (' . esc_html( number_format_i18n( count( $titles ) ) ) . ') : ' . esc_html( implode( ', ', $titles ) ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Nom d'une famille de la banque, ou « system ».
	 *
	 * @param string $slug Identifiant.
	 */
	private static function bank_name( string $slug ): string {
		$family = Fonts::catalogue()[ $slug ] ?? null;
		return null === $family ? __( 'system fonts', 'fluffless-suite' ) : $family['name'];
	}

	/**
	 * Rapport d'une conversion.
	 *
	 * @param Report $report Rapport.
	 */
	private static function render_report( Report $report ): void {
		echo '<div class="fl-card"><ul>';
		/* translators: %s: number */
		echo '<li>' . esc_html( sprintf( _n( '%s block written.', '%s blocks written.', $report->blocks, 'fluffless-suite' ), number_format_i18n( $report->blocks ) ) ) . '</li>';
		if ( array() !== $report->kept ) {
			/* translators: %s: list of element kinds with counts */
			echo '<li>' . esc_html( sprintf( __( 'Kept as plain text, to redo with a block: %s.', 'fluffless-suite' ), self::counts( $report->kept ) ) ) . '</li>';
		}
		if ( array() !== $report->skipped ) {
			/* translators: %s: list of element kinds with counts */
			echo '<li>' . esc_html( sprintf( __( 'Left out, nothing to keep: %s.', 'fluffless-suite' ), self::counts( $report->skipped ) ) ) . '</li>';
		}
		if ( $report->styles > 0 ) {
			/* translators: %s: number */
			echo '<li>' . esc_html( sprintf( _n( '%s fixed colour or font setting dropped: the site styles apply.', '%s fixed colour or font settings dropped: the site styles apply.', $report->styles, 'fluffless-suite' ), number_format_i18n( $report->styles ) ) ) . '</li>';
		}
		if ( array() !== $report->images ) {
			/* translators: %s: number */
			echo '<li>' . esc_html( sprintf( _n( '%s image is not in the media library: it is kept by its address.', '%s images are not in the media library: they are kept by their address.', count( $report->images ), 'fluffless-suite' ), number_format_i18n( count( $report->images ) ) ) ) . '</li>';
		}
		foreach ( $report->todo as $todo ) {
			echo '<li>' . esc_html( $todo ) . '</li>';
		}
		echo '<li>' . self::seo_import_line() . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans seo_import_line().
		echo '</ul></div>';
	}

	/**
	 * Dernière ligne du rapport : les titres et descriptions SEO ne sont pas convertis ici, Fluffless SEO les importe (C-S05-09).
	 * Lien vers l'onglet d'importation quand l'extension est active ; texte seul sinon.
	 */
	private static function seo_import_line(): string {
		$text = esc_html__( 'SEO titles and descriptions written with Yoast SEO, Rank Math or SEOPress are not part of this conversion. Fluffless SEO imports them.', 'fluffless-suite' );
		if ( ! class_exists( 'Fluffless\\Seo\\Plugin' ) ) {
			return $text;
		}
		return $text . ' <a href="' . esc_url( admin_url( 'options-general.php?page=fluffless-seo&tab=import' ) ) . '">' . esc_html__( 'Open the SEO import', 'fluffless-suite' ) . '</a>';
	}

	/**
	 * « sorte (n), sorte (n) ».
	 *
	 * @param array<string, int> $counts Comptes par sorte.
	 */
	private static function counts( array $counts ): string {
		$parts = array();
		foreach ( $counts as $kind => $count ) {
			$parts[] = $kind . ' (' . number_format_i18n( $count ) . ')';
		}
		return implode( ', ', $parts );
	}
}
