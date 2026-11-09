<?php
/**
 * Module Élagage du CSS en ligne : retire, sur le front seulement, le CSS du cœur que la page n'utilise pas.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Deux élagages, tous deux prudents :
 * 1. les classes de préréglages (has-<slug>-color, -background-color, -border-color, -font-size,
 *    -font-family, -gradient-background) absentes du rendu des blocs, qui précède wp_head dans un
 *    thème de blocs. Seuls les sélecteurs « (:root )(:where(.wp-block-x)).has-<slug>-<type> » sont
 *    candidats : rien qui contienne :not(, un combinateur ou une autre classe ;
 * 2. la visionneuse d'images (lightbox) du bloc Image, quand aucune image de la page ne l'active.
 *
 * Chaînes et commentaires CSS sont sautés : une accolade ou une virgule qu'ils contiennent ne coupe rien.
 * Rien n'est retiré de l'éditeur : il ne passe ni par wp_head ni par ce module.
 */
final class PruneCss implements Module {

	/** Types de préréglages dont la classe seule peut partir. */
	private const PRESET = '/^(?::root\s+)?(?::where\(\.wp-block-[a-z0-9-]+\))?\.(has-[a-z0-9-]+-(?:color|background-color|border-color|font-size|font-family|gradient-background))$/';

	/** Ce qui sert à la visionneuse du bloc Image. */
	private const LIGHTBOX = '/lightbox|@keyframes (turn-on-visibility|turn-off-visibility|show-content-image)/';

	/** Poignées dont le CSS en ligne porte les préréglages. */
	private const PRESET_HANDLES = array( 'global-styles', 'wp-block-library' );

	/**
	 * Classes vues dans le rendu des blocs de la page.
	 *
	 * @var array<string, true>
	 */
	private static array $classes = array();

	/** {@inheritDoc} */
	public static function id(): string {
		return 'prune-css';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Inline CSS pruning', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Removes from the inline CSS of your pages the color, size and font classes no block uses, and the image lightbox styles when no image opens one. Visitors only: the editor keeps everything.', 'fluffless-suite' );
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

	/** {@inheritDoc} */
	public function register(): void {
		if ( is_admin() ) {
			return;
		}
		add_filter( 'render_block', array( self::class, 'collect' ), PHP_INT_MAX );
		// wp_maybe_inline_styles (priorité 1) a déjà versé les feuilles en ligne ; wp_print_styles imprime à 8.
		add_action( 'wp_head', array( self::class, 'apply' ), 7 );
	}

	/**
	 * Inventorie les classes d'un rendu de bloc, après tous les autres filtres (mise en page, supports).
	 * Les attributs class se lisent entre guillemets doubles, simples ou sans guillemets.
	 *
	 * @param mixed $html Rendu du bloc.
	 * @return mixed Rendu inchangé.
	 */
	public static function collect( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( preg_match_all( '/\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$names = preg_split( '/\s+/', trim( implode( ' ', array_slice( $match, 1 ) ) ) );
				foreach ( false === $names ? array() : $names as $class ) {
					if ( '' !== $class ) {
						self::$classes[ $class ] = true;
					}
				}
			}
		}
		return $html;
	}

	/**
	 * Classes inventoriées jusqu'ici.
	 *
	 * @return array<string, true>
	 */
	public static function classes(): array {
		return self::$classes;
	}

	/** Oublie l'inventaire (tests). */
	public static function reset(): void {
		self::$classes = array();
	}

	/** Élague le CSS en ligne déjà attaché aux poignées, juste avant l'impression. */
	public static function apply(): void {
		if ( array() === self::$classes ) {
			return; // Rien d'inventorié : on ne touche à rien.
		}
		$rules = array_fill_keys( self::PRESET_HANDLES, array( self::class, 'keep_preset' ) );
		if ( ! self::uses_lightbox() ) {
			$rules['wp-block-image'] = array( self::class, 'keep_without_lightbox' );
		}
		$styles = wp_styles();
		foreach ( $rules as $handle => $keep ) {
			if ( empty( $styles->registered[ $handle ]->extra['after'] ) ) {
				continue;
			}
			foreach ( $styles->registered[ $handle ]->extra['after'] as $key => $css ) {
				if ( is_string( $css ) ) {
					$styles->registered[ $handle ]->extra['after'][ $key ] = self::prune( $css, $keep );
				}
			}
		}
	}

	/** Une image de la page ouvre-t-elle la visionneuse ? */
	private static function uses_lightbox(): bool {
		foreach ( array_keys( self::$classes ) as $class ) {
			if ( str_starts_with( (string) $class, 'wp-lightbox' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Garde un sélecteur sauf s'il est une classe de préréglage seule, absente de la page.
	 *
	 * @param string $selector Sélecteur simple.
	 */
	public static function keep_preset( string $selector ): bool {
		if ( 1 !== preg_match( self::PRESET, $selector, $m ) ) {
			return true;
		}
		return isset( self::$classes[ $m[1] ] );
	}

	/**
	 * Garde un sélecteur du bloc Image sauf ce qui sert à la visionneuse.
	 *
	 * @param string $selector Sélecteur simple ou « @keyframes nom ».
	 */
	public static function keep_without_lightbox( string $selector ): bool {
		return 1 !== preg_match( self::LIGHTBOX, $selector );
	}

	/**
	 * Élague une feuille règle par règle, en descendant dans @media, @supports, @layer et @container.
	 *
	 * @param string   $css  Feuille.
	 * @param callable $keep Reçoit un sélecteur simple (ou « @keyframes nom »), rend un booléen.
	 */
	public static function prune( string $css, callable $keep ): string {
		$out = '';
		$n   = strlen( $css );
		$i   = 0;
		while ( $i < $n ) {
			$open = self::find( $css, '{', $i );
			if ( false === $open ) {
				$out .= substr( $css, $i );
				break;
			}
			$prelude = trim( substr( $css, $i, $open - $i ) );
			// Accolade fermante correspondante.
			$depth = 1;
			$j     = $open + 1;
			while ( $j < $n && $depth > 0 ) {
				$skip = self::skip( $css, $j );
				if ( $skip > $j ) {
					$j = $skip;
					continue;
				}
				if ( '{' === $css[ $j ] ) {
					++$depth;
				} elseif ( '}' === $css[ $j ] ) {
					--$depth;
				}
				++$j;
			}
			$body = substr( $css, $open + 1, $j - $open - 2 );
			$i    = $j;

			if ( 1 === preg_match( '/^@(media|supports|layer|container)\b/', $prelude ) ) {
				$inner = self::prune( $body, $keep );
				if ( '' !== trim( $inner ) ) {
					$out .= $prelude . '{' . $inner . '}';
				}
				continue;
			}
			if ( '@' === ( $prelude[0] ?? '' ) ) {
				if ( 1 !== preg_match( '/^@(-webkit-)?keyframes\s+(\S+)/', $prelude, $k ) || $keep( '@keyframes ' . $k[2] ) ) {
					$out .= $prelude . '{' . $body . '}';
				}
				continue;
			}
			$kept = array_filter( self::selectors( $prelude ), $keep );
			if ( array() !== $kept ) {
				$out .= implode( ',', $kept ) . '{' . $body . '}';
			}
		}
		return $out;
	}

	/**
	 * Découpe une liste de sélecteurs aux virgules de premier niveau (pas dans :is(), :where()).
	 *
	 * @param string $selectors Liste de sélecteurs.
	 * @return list<string>
	 */
	public static function selectors( string $selectors ): array {
		$parts = array();
		$depth = 0;
		$start = 0;
		$n     = strlen( $selectors );
		for ( $i = 0; $i < $n; $i++ ) {
			$skip = self::skip( $selectors, $i );
			if ( $skip > $i ) {
				$i = $skip - 1;
				continue;
			}
			$c = $selectors[ $i ];
			if ( '(' === $c ) {
				++$depth;
			} elseif ( ')' === $c ) {
				--$depth;
			} elseif ( ',' === $c && 0 === $depth ) {
				$parts[] = trim( substr( $selectors, $start, $i - $start ) );
				$start   = $i + 1;
			}
		}
		$parts[] = trim( substr( $selectors, $start ) );
		return array_values( array_filter( $parts, static fn( string $s ): bool => '' !== $s ) );
	}

	/**
	 * Si une chaîne entre guillemets ou un commentaire commence à $i, rend l'index qui le suit ; sinon $i.
	 * Les échappements (\") restent dans la chaîne.
	 *
	 * @param string $css Feuille.
	 * @param int    $i   Position.
	 */
	private static function skip( string $css, int $i ): int {
		$n = strlen( $css );
		$c = $css[ $i ] ?? '';
		if ( '/' === $c && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
			$end = strpos( $css, '*/', $i + 2 );
			return false === $end ? $n : $end + 2;
		}
		if ( '"' === $c || "'" === $c ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( '\\' === $css[ $j ] ) {
					++$j;
				} elseif ( $c === $css[ $j ] ) {
					return $j + 1;
				}
			}
			return $n;
		}
		return $i;
	}

	/**
	 * Cherche un caractère hors chaînes et commentaires.
	 *
	 * @param string $css  Feuille.
	 * @param string $char Caractère cherché.
	 * @param int    $from Position de départ.
	 * @return int|false
	 */
	private static function find( string $css, string $char, int $from ) {
		$n = strlen( $css );
		for ( $i = $from; $i < $n; $i++ ) {
			$skip = self::skip( $css, $i );
			if ( $skip > $i ) {
				$i = $skip - 1;
				continue;
			}
			if ( $char === $css[ $i ] ) {
				return $i;
			}
		}
		return false;
	}
}
