<?php
/**
 * Contrat d'un module Fluffless Suite.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Les métadonnées sont statiques : la page de réglages et l'assainissement les lisent
 * sans instancier le module. Seuls les modules actifs sont instanciés, puis register()
 * pose leurs crochets. Aucun effet de bord au chargement du fichier ni dans le constructeur.
 *
 * Types de champs reconnus par fields() :
 * - bool   : case à cocher ;
 * - int    : nombre entier, clés min et max obligatoires ;
 * - choice : une valeur parmi options (valeur => libellé) ;
 * - multi  : zéro ou plusieurs valeurs parmi options ;
 * - hosts  : liste de domaines, un par ligne ;
 * - text     : ligne de texte brut, 200 caractères au plus ;
 * - textarea : texte brut sur plusieurs lignes, 600 caractères au plus ;
 * - lines    : liste de lignes de texte brut (le module en valide le contenu) ;
 * - url      : adresse https seulement ('' pour vider le champ).
 *
 * Clé facultative with : clé d'un autre champ du module, affiché sur la même ligne des réglages
 * (interrupteur d'un élément et son choix d'appareil, par exemple).
 *
 * Dépendances entre modules : déclarées dans Loader::MODULES, pas ici. Un module qui en
 * requiert un autre teste Loader::is_active( 'id' ) avant de s'en servir (mode réduit sinon).
 *
 * Méthode statique facultative status(): string : état affiché sous la description
 * dans les réglages (désactivation automatique, activation automatique...).
 *
 * Méthode statique facultative status_action(): ?array{text: string, url: string} : bouton
 * affiché sous cet état, qui mène là où on règle la situation (activer l'extension requise,
 * créer un compte). Null quand rien n'est à faire ou que la personne n'en a pas le droit.
 *
 * @phpstan-type Field array{type: 'bool'|'int'|'choice'|'multi'|'hosts'|'text'|'textarea'|'lines'|'url', label: string, help?: string, min?: int, max?: int, options?: array<string, string>, with?: string}
 */
interface Module {

	/** Identifiant stable, clé de la section dans l'option fluffless_settings. */
	public static function id(): string;

	/** Nom affiché dans la page de réglages, en langage clair. */
	public static function label(): string;

	/** Une phrase qui dit ce que le module change pour le site. */
	public static function description(): string;

	/** Actif sur une installation neuve ? */
	public static function enabled_by_default(): bool;

	/**
	 * Valeurs par défaut de la section du module.
	 *
	 * @return array<string, bool|int|string|list<string>>
	 */
	public static function defaults(): array;

	/**
	 * Assainit la section reçue ; toute valeur absente ou hors liste reprend sa valeur par défaut.
	 *
	 * @param array<mixed> $input Section brute (formulaire ou API REST).
	 * @return array<string, bool|int|string|list<string>>
	 */
	public static function sanitize( array $input ): array;

	/**
	 * Champs de la page de réglages, dans l'ordre d'affichage.
	 *
	 * @return array<string, Field>
	 */
	public static function fields(): array;

	/** Pose les crochets du module. Appelé seulement si le module est actif. */
	public function register(): void;
}
