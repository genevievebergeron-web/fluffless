<?php
/**
 * Identifiants de rendu stables : dérivés du bloc, pas d'un compteur global.
 *
 * La fonction wp_unique_id() compte tout ce qui a été rendu avant dans la requête : le même bloc
 * change d'id d'une page à l'autre, et un diff HTML ou un cache voit des changements partout. Ici l'id
 * vient d'un condensé de la graine (attributs du bloc, contenu enregistré) ; deux blocs
 * identiques sur une page sont distingués par un suffixe dans l'ordre du document. Un id ne
 * change donc que si le bloc change.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Presentation;

defined( 'ABSPATH' ) || exit;

/**
 * Fabrique d'identifiants par requête.
 */
final class Ids {

	/**
	 * Longueur du condensé hexadécimal.
	 */
	private const LENGTH = 8;

	/**
	 * Identifiants déjà rendus dans la requête : id de base => nombre de fois.
	 *
	 * @var array<string, int>
	 */
	private static array $seen = array();

	/**
	 * Identifiant stable pour une graine : préfixe + 8 hexadécimaux, puis -2, -3… pour les
	 * répétitions dans la même requête. Le préfixe doit commencer par une lettre pour que
	 * l'id soit valide partout (attribut name, aria-labelledby, popovertarget).
	 *
	 * @param string $prefix Préfixe, ex. « fluffless-popup- ».
	 * @param string $seed   Graine : attributs encodés et contenu enregistré, jamais la sortie rendue.
	 */
	public static function stable( string $prefix, string $seed ): string {
		$base = $prefix . substr( md5( $seed ), 0, self::LENGTH );
		$n    = ( self::$seen[ $base ] ?? 0 ) + 1;

		self::$seen[ $base ] = $n;
		return 1 === $n ? $base : $base . '-' . $n;
	}

	/**
	 * Oublie les identifiants rendus (tests, rendu de plusieurs pages dans un même processus).
	 */
	public static function reset(): void {
		self::$seen = array();
	}
}
