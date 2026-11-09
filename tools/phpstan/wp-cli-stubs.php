<?php
/**
 * Signatures minimales de WP-CLI pour PHPStan (le paquet de stubs complet n'est pas installé).
 * Seules les méthodes appelées par les extensions du dépôt sont décrites.
 *
 * @package Fluffless
 */

// phpcs:ignoreFile -- fichier de signatures, jamais chargé par WordPress.

namespace {
	class WP_CLI {
		/**
		 * @param string               $name     Nom de la commande.
		 * @param callable|class-string $callable Commande.
		 * @param array<string, mixed> $args     Description.
		 */
		public static function add_command( string $name, $callable, array $args = array() ): bool {}

		/** @return never */
		public static function error( string $message ): void {}

		public static function success( string $message ): void {}

		public static function warning( string $message ): void {}

		public static function log( string $message ): void {}

		/**
		 * @param string               $question   Question posée.
		 * @param array<string, mixed> $assoc_args Options reçues (--yes répond oui d'avance).
		 */
		/**
		 * @param string               $command Commande sans « wp ».
		 * @param array<string, mixed> $options Options de WP_CLI::runcommand().
		 * @return mixed
		 */
		public static function runcommand( string $command, array $options = array() ) {}

		public static function confirm( string $question, array $assoc_args = array() ): void {}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string                           $format Format.
	 * @param iterable<array<string, mixed>>   $items  Lignes.
	 * @param list<string>|string              $fields Colonnes.
	 */
	function format_items( string $format, iterable $items, $fields ): void {}
}
