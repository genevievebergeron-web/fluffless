<?php
/**
 * Signatures de Sensei LMS, lues par PHPStan sans être chargées.
 * Seules celles que le thème et le module Cours sobres appellent sous function_exists().
 *
 * @package Fluffless
 */

// phpcs:disable

/**
 * La requête en cours est une page de Sensei.
 */
function is_sensei(): bool {
	return false;
}
