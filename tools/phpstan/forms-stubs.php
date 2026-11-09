<?php
/**
 * Signatures des extensions de formulaires établies, lues par PHPStan sans être chargées.
 * Seules celles que le module Formulaires sobres appelle sous class_exists().
 *
 * @package Fluffless
 */

// phpcs:disable

/**
 * Formidable Forms.
 */
class FrmAppHelper {

	/**
	 * Adresse du dossier de l'extension, sans barre finale.
	 */
	public static function plugin_url(): string {
		return '';
	}
}
