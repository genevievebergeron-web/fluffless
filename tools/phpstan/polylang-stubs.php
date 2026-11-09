<?php
/**
 * Signatures de Polylang lues par PHPStan seulement (scanFiles) : l'extension n'en dépend pas
 * et vérifie function_exists() avant chaque appel. Jamais chargé par WordPress.
 *
 * @package Fluffless_Suite
 */

/**
 * Sélecteur de langue de Polylang ; avec raw = 1, liste de langues (slug, name, url, current_lang...).
 *
 * @param array<string, mixed> $args Arguments d'affichage.
 * @return mixed
 */
function pll_the_languages( $args = array() ) {}

/**
 * Langues déclarées dans Polylang ; fields choisit la propriété (slug, locale, name...).
 *
 * @param array<string, mixed> $args Arguments.
 * @return mixed
 */
function pll_languages_list( $args = array() ) {}
