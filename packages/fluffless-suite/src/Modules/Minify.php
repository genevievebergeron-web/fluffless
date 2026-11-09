<?php
/**
 * Module Minification : HTML servi aux visiteurs et CSS en ligne, sans rien casser.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Règles :
 * - pre, textarea et script ne sont jamais modifiés ; un style d'un autre type que CSS non plus ;
 * - les commentaires HTML disparaissent, sauf les commentaires conditionnels (<!--[if ...]>) ;
 * - les valeurs d'attributs entre guillemets sont gardées telles quelles ;
 * - si une étape échoue (limite PCRE, sortie partielle déjà envoyée), la page part intacte.
 *
 * La mesure avant/après (brut et compressé) est prise sur une page par jour et affichée
 * dans les réglages : le gain réel se juge une fois la compression du serveur appliquée.
 */
final class Minify implements Module {

	public const TRANSIENT = Lifecycle::TRANSIENT_MINIFY;

	/** Borne : au-delà, la page est servie telle quelle (aucune page normale n'approche ce poids). */
	public const MAX_BYTES = 3 * MB_IN_BYTES;

	/** Sous ce gain compressé (en part du poids compressé), le gain est dit négligeable. */
	private const NEGLIGIBLE_RATIO = 0.01;

	/** Balises dont le contenu ne se touche pas, et commentaires. */
	private const PROTECTED_PATTERN = '#(<pre\b.*?</pre\s*>|<textarea\b.*?</textarea\s*>|<script\b.*?</script\s*>|<style\b.*?</style\s*>|<!--.*?-->)#is';

	/** Une balise, attributs entre guillemets compris (un > dans une valeur ne la coupe pas). */
	private const TAG = '<[a-zA-Z/!?](?:"[^"]*"|\'[^\']*\'|[^\'">])*>';

	/** Balises sans rendu : l'espace qui les entoure ne s'affiche jamais. */
	private const SILENT_TAGS = '(?:/?(?:html|head|body|meta|link|title|base)\b|!doctype\b)';

	/** {@inheritDoc} */
	public static function id(): string {
		return 'minify';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Minification', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Removes the spaces and comments visitors never see from your pages and their inline CSS. Off by default: when your server already compresses pages, the real gain is small.', 'fluffless-suite' );
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
		return Sanitize::section( $input, self::fields(), self::defaults() );
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array();
	}

	/** Dernière mesure, en clair, sous la description du module. */
	public static function status(): string {
		$measure = self::last_measure();
		if ( null === $measure ) {
			return __( 'No measurement yet: once the module is on, visit a page of your site, then come back.', 'fluffless-suite' );
		}
		$saved_raw = $measure['raw'] - $measure['min'];
		$saved_gz  = $measure['gzip_raw'] - $measure['gzip_min'];
		$message   = sprintf(
			/* translators: 1: page address path; 2: bytes saved before compression; 3: bytes saved after gzip compression */
			__( 'Last measured page (%1$s): %2$s bytes less before compression. Real gain: %3$s compressed bytes.', 'fluffless-suite' ),
			$measure['path'],
			self::plain_number( $saved_raw ),
			self::plain_number( $saved_gz )
		);
		if ( $measure['gzip_raw'] > 0 && $saved_gz / $measure['gzip_raw'] < self::NEGLIGIBLE_RATIO ) {
			$message .= ' ' . __( 'That is negligible: your server compression already does most of the work. You can leave this module off.', 'fluffless-suite' );
		}
		return $message;
	}

	/**
	 * Nombre localisé en texte brut : number_format_i18n() rend &nbsp; en français, que esc_html() afficherait tel quel.
	 *
	 * @param int $number Nombre.
	 */
	private static function plain_number( int $number ): string {
		return html_entity_decode( number_format_i18n( $number ), ENT_QUOTES, 'UTF-8' );
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'template_redirect', array( self::class, 'start' ), PHP_INT_MAX );
	}

	/** Ouvre le tampon de sortie pour les pages publiques seulement. */
	public static function start(): void {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || is_embed() || is_customize_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		ob_start( array( self::class, 'buffer' ) );
	}

	/**
	 * Rappel du tampon. Ne minifie que si la page arrive en un seul morceau :
	 * après un ob_flush() d'un tiers, un morceau pourrait couper un bloc pre ou script.
	 *
	 * @param string $buffer Sortie de la page.
	 * @param int    $phase  Drapeaux PHP_OUTPUT_HANDLER_*.
	 */
	public static function buffer( string $buffer, int $phase = PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL ): string {
		$whole = ( $phase & PHP_OUTPUT_HANDLER_START ) && ( $phase & PHP_OUTPUT_HANDLER_FINAL );
		if ( ! $whole || ! self::is_html_document( $buffer ) || ! self::sends_html() ) {
			return $buffer;
		}
		$min = self::html( $buffer );
		if ( null === $min ) {
			return $buffer;
		}
		if ( false === get_transient( self::TRANSIENT ) ) {
			$path = wp_parse_url( home_url( add_query_arg( array() ) ), PHP_URL_PATH );
			set_transient( self::TRANSIENT, self::measure( $buffer, $min, is_string( $path ) ? $path : '/' ), DAY_IN_SECONDS );
		}
		return $min;
	}

	/**
	 * Document HTML complet, sous la borne de poids ?
	 *
	 * @param string $buffer Sortie.
	 */
	private static function is_html_document( string $buffer ): bool {
		if ( strlen( $buffer ) > self::MAX_BYTES ) {
			return false;
		}
		$start = strtolower( ltrim( substr( $buffer, 0, 256 ) ) );
		return str_starts_with( $start, '<!doctype html' ) || str_starts_with( $start, '<html' );
	}

	/** L'en-tête Content-Type annoncé, s'il existe, est-il du HTML ? */
	private static function sends_html(): bool {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				return false !== stripos( $header, 'text/html' );
			}
		}
		return true;
	}

	/**
	 * Minifie un document HTML ; null si une expression régulière échoue.
	 *
	 * @param string $html Document.
	 */
	public static function html( string $html ): ?string {
		$parts = preg_split( self::PROTECTED_PATTERN, $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return null;
		}
		// Les commentaires retirés recollent le balisage qui les entourait : une seule passe d'espaces ensuite.
		$chunks = array();
		$markup = '';
		foreach ( $parts as $index => $part ) {
			if ( 0 === $index % 2 ) {
				$markup .= $part;
				continue;
			}
			if ( str_starts_with( $part, '<!--' ) && ! self::is_conditional_comment( $part ) ) {
				continue;
			}
			$chunks[] = array( 'markup', $markup );
			$chunks[] = array( 'kept', $part );
			$markup   = '';
		}
		$chunks[] = array( 'markup', $markup );

		$out   = '';
		$count = count( $chunks );
		foreach ( $chunks as $i => $chunk ) {
			if ( 'kept' === $chunk[0] ) {
				$style = str_starts_with( strtolower( $chunk[1] ), '<style' ) ? self::style_element( $chunk[1] ) : $chunk[1];
				if ( null === $style ) {
					return null;
				}
				$out .= $style;
				continue;
			}
			$min = self::markup( $chunk[1] );
			if ( null === $min ) {
				return null;
			}
			// Espace contre un script ou un style : jamais rendu dans head, sans effet dans body.
			if ( $i > 0 && self::is_head_element( $chunks[ $i - 1 ][1] ) ) {
				$min = ltrim( $min, ' ' );
			}
			if ( $i + 1 < $count && self::is_head_element( $chunks[ $i + 1 ][1] ) ) {
				$min = rtrim( $min, ' ' );
			}
			$out .= $min;
		}
		return $out;
	}

	/**
	 * Commentaire conditionnel (<!--[if IE]>...<![endif]-->) : il porte du sens, on le garde.
	 *
	 * @param string $comment Commentaire complet.
	 */
	private static function is_conditional_comment( string $comment ): bool {
		return 1 === preg_match( '#^<!--\s*(\[if\b|<!\[endif\])#i', $comment );
	}

	/**
	 * Élément script ou style, dont l'espace voisin ne s'affiche pas.
	 *
	 * @param string $part Morceau gardé.
	 */
	private static function is_head_element( string $part ): bool {
		return 1 === preg_match( '#^<(script|style)\b#i', $part );
	}

	/**
	 * Balisage hors zones protégées : espaces regroupés, balises sans rendu collées.
	 *
	 * @param string $markup Balisage.
	 */
	private static function markup( string $markup ): ?string {
		$collapsed = preg_replace_callback(
			'#(' . self::TAG . ')|[ \t\n\r\f]+#',
			static fn( array $m ): string => null !== $m[1] ? self::tag( $m[1] ) : ' ',
			$markup,
			-1,
			$count,
			PREG_UNMATCHED_AS_NULL
		);
		if ( null === $collapsed ) {
			return null;
		}
		return preg_replace( '# ?(<' . self::SILENT_TAGS . '(?:"[^"]*"|\'[^\']*\'|[^\'">])*>) ?#i', '$1', $collapsed );
	}

	/**
	 * Espaces d'une balise regroupés hors guillemets ; les valeurs d'attributs restent intactes.
	 *
	 * @param string $tag Balise complète.
	 */
	private static function tag( string $tag ): string {
		$min = preg_replace_callback(
			'#("[^"]*"|\'[^\']*\')|[ \t\n\r\f]+#',
			static fn( array $m ): string => null !== $m[1] ? $m[1] : ' ',
			$tag,
			-1,
			$count,
			PREG_UNMATCHED_AS_NULL
		);
		return null === $min ? $tag : (string) preg_replace( '# >$#', '>', $min );
	}

	/**
	 * Élément style : CSS minifié si le type est absent ou text/css, sinon intact.
	 *
	 * @param string $element Élément complet.
	 */
	private static function style_element( string $element ): ?string {
		if ( 1 !== preg_match( '#^(<style\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>)(.*)(</style\s*>)$#is', $element, $m ) ) {
			return $element;
		}
		if ( 1 === preg_match( '#\btype\s*=\s*["\']?([^"\'\s>]+)#i', $m[1], $type ) && 'text/css' !== strtolower( $type[1] ) ) {
			return $element;
		}
		$css = self::css( $m[2] );
		return null === $css ? null : $m[1] . $css . $m[3];
	}

	/**
	 * Minifie du CSS : commentaires retirés, espaces regroupés puis retirés autour de { } ; , et après :.
	 * Chaînes et url() sont gardées telles quelles ; les espaces de calc() autour de + et - aussi.
	 *
	 * @param string $css Feuille de style.
	 */
	public static function css( string $css ): ?string {
		$kept = '"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|url\((?:[^()"\']|"[^"]*"|\'[^\']*\')*\)';
		$css  = preg_replace_callback(
			'#(' . $kept . ')|(?:/\*.*?\*/|[ \t\n\r\f])+#is',
			static fn( array $m ): string => null !== $m[1] ? $m[1] : ' ',
			$css,
			-1,
			$count,
			PREG_UNMATCHED_AS_NULL
		);
		if ( null === $css ) {
			return null;
		}
		$css = preg_replace_callback(
			'#(' . $kept . ')| ?([{};,]) ?|: #is',
			static function ( array $m ): string {
				if ( null !== $m[1] ) {
					return $m[1];
				}
				return null !== $m[2] ? $m[2] : ':';
			},
			$css,
			-1,
			$count,
			PREG_UNMATCHED_AS_NULL
		);
		return null === $css ? null : trim( str_replace( ';}', '}', $css ) );
	}

	/**
	 * Poids avant et après, bruts et compressés (gzip niveau 6, Brotli si l'extension PHP existe).
	 *
	 * @param string $raw  Page d'origine.
	 * @param string $min  Page minifiée.
	 * @param string $path Chemin de la page mesurée.
	 * @return array{path: string, raw: int, min: int, gzip_raw: int, gzip_min: int, brotli_raw: int, brotli_min: int, time: int}
	 */
	public static function measure( string $raw, string $min, string $path ): array {
		$gzip   = static fn( string $s ): int => strlen( (string) gzencode( $s, 6 ) );
		$brotli = static fn( string $s ): int => function_exists( 'brotli_compress' ) ? strlen( (string) brotli_compress( $s ) ) : 0;
		return array(
			'path'       => $path,
			'raw'        => strlen( $raw ),
			'min'        => strlen( $min ),
			'gzip_raw'   => $gzip( $raw ),
			'gzip_min'   => $gzip( $min ),
			'brotli_raw' => $brotli( $raw ),
			'brotli_min' => $brotli( $min ),
			'time'       => time(),
		);
	}

	/**
	 * Mesure gardée, validée.
	 *
	 * @return array{path: string, raw: int, min: int, gzip_raw: int, gzip_min: int}|null
	 */
	public static function last_measure(): ?array {
		$stored = get_transient( self::TRANSIENT );
		if ( ! is_array( $stored ) || ! is_string( $stored['path'] ?? null ) ) {
			return null;
		}
		foreach ( array( 'raw', 'min', 'gzip_raw', 'gzip_min' ) as $key ) {
			if ( ! is_int( $stored[ $key ] ?? null ) ) {
				return null;
			}
		}
		return array(
			'path'     => $stored['path'],
			'raw'      => $stored['raw'],
			'min'      => $stored['min'],
			'gzip_raw' => $stored['gzip_raw'],
			'gzip_min' => $stored['gzip_min'],
		);
	}
}
