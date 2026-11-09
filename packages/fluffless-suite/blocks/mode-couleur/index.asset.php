<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- nom imposé par WordPress pour block.json.
/**
 * Dépendances du script d'éditeur (pas d'étape de build : fichier écrit à la main).
 * Blocks\ModeCouleur ajoute window.flufflessModeCouleur (état, lien vers Palette) avant ce script.
 *
 * @package Fluffless_Suite
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n' ),
	'version'      => '0.1.0',
);
