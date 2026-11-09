<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- nom imposé par WordPress pour block.json.
/**
 * Dépendances du script d'éditeur (pas d'étape de build : fichier écrit à la main).
 * fluffless-canvas-editor passe les droits de la personne et l'alerte de poids ; le bloc
 * n'est enregistré que si le module Canevas est actif, donc ce script l'est aussi.
 *
 * @package Fluffless_Suite
 */

return array(
	'dependencies' => array( 'fluffless-canvas-editor', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
	'version'      => '0.1.0',
);
