<?php
/**
 * Contrat facultatif d'un module qui contacte un serveur.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Le module dit quels serveurs il contacte avec les réglages courants, calculés par les mêmes
 * fonctions que le code qui appelle (aucune requête, aucune écriture). Lu par Hosts::all(),
 * seulement quand le module est allumé.
 *
 * @phpstan-import-type Entry from Hosts
 */
interface DeclaresHosts {

	/**
	 * Entrées brutes, nettoyées ensuite par Hosts.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function hosts(): array;
}
