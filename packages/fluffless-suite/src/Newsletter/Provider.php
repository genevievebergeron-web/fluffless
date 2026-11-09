<?php
/**
 * Outil d'infolettre : les requêtes d'une inscription et la lecture des réponses, sans réseau.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Newsletter;

defined( 'ABSPATH' ) || exit;

/**
 * Fonctions pures : le module Infolettre envoie les requêtes, l'outil dit seulement lesquelles
 * et ce que veut dire chaque réponse. Les tests vérifient les requêtes sans toucher au réseau.
 *
 * Une inscription est une suite d'étapes. Après chaque réponse, outcome() rend :
 * - self::NEXT : passer à l'étape suivante ;
 * - self::DONE : terminé sans erreur (y compris « déjà abonnée », laissée telle quelle) ;
 * - une raison de la liste fermée Newsletter::REASONS : échec.
 *
 * @phpstan-type Contact array{email: string, first: string, last: string, language: string, consent: string, page: string, time: int}
 * @phpstan-type Settings array{list: string, double_optin: bool, key: string}
 * @phpstan-type Request array{step: string, method: string, url: string, headers: array<string, string>, body: string}
 */
interface Provider {

	public const NEXT = 'next';
	public const DONE = 'done';

	/** Identifiant stocké dans les réglages. */
	public static function id(): string;

	/** Nom affiché. */
	public static function label(): string;

	/** Où l'outil garde les données, avec la préposition (« in Canada »), pour la politique de confidentialité. */
	public static function country(): string;

	/**
	 * Liste ou groupe valide pour cet outil ? Sinon le réglage se vide.
	 *
	 * @param string $list_id Valeur saisie.
	 */
	public static function valid_list( string $list_id ): bool;

	/**
	 * Clé de la forme attendue par cet outil ?
	 *
	 * @param string $key Clé lue dans wp-config.php.
	 */
	public static function valid_key( string $key ): bool;

	/**
	 * Domaine joint, ajouté à la liste du module Requêtes sortantes. Vide si la clé est mal formée.
	 *
	 * @param string $key Clé.
	 */
	public static function host( string $key ): string;

	/**
	 * Requêtes d'une inscription, dans l'ordre.
	 *
	 * @param array<string, mixed> $contact  Personne à inscrire.
	 * @param array<string, mixed> $settings Réglages et clé.
	 * @phpstan-param Contact  $contact
	 * @phpstan-param Settings $settings
	 * @return list<Request>
	 */
	public static function subscribe( array $contact, array $settings ): array;

	/**
	 * Requête en lecture seule du test de connexion : la clé est-elle acceptée, la liste existe-t-elle ?
	 *
	 * @param array<string, mixed> $settings Réglages et clé.
	 * @phpstan-param Settings $settings
	 * @return Request
	 */
	public static function check( array $settings ): array;

	/**
	 * Sens d'une réponse : self::NEXT, self::DONE ou une raison d'échec.
	 *
	 * @param string               $step Étape de la requête.
	 * @param int                  $code Code HTTP.
	 * @param array<string, mixed> $body Corps JSON décodé (vide si illisible).
	 */
	public static function outcome( string $step, int $code, array $body ): string;
}
