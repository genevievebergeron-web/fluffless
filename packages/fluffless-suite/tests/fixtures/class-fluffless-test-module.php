<?php
/**
 * Module factice pour le test du Loader : compte ses instances et pose un seul crochet.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Actif par défaut ; le test le désactive par l'option pour vérifier qu'il n'est ni instancié ni accroché.
 */
final class Fluffless_Test_Module implements \Fluffless\Suite\Core\Module {

	/**
	 * Nombre d'instances créées.
	 *
	 * @var int
	 */
	public static int $instances = 0;

	/** Compte les instances. */
	public function __construct() {
		++self::$instances;
	}

	/** {@inheritDoc} */
	public static function id(): string {
		return 'fluffless_test';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return 'Test';
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return 'Test module.';
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
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
		add_action( 'fluffless_test_ping', array( self::class, 'noop' ) );
	}

	/** Rappel sans effet. */
	public static function noop(): void {
	}
}
