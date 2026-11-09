<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- nom imposé par WordPress pour block.json.
/**
 * Dépendances du script d'éditeur (pas d'étape de build : fichier écrit à la main).
 * fluffless-sections-editor : aides communes, enregistrées par Sections::register().
 * wp-core-data et wp-html-entities : lecture et affichage du fichier choisi dans l'éditeur.
 *
 * @package Fluffless_Suite
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-core-data', 'wp-data', 'wp-element', 'wp-html-entities', 'wp-i18n', 'fluffless-sections-editor' ),
	'version'      => '0.1.0',
);
