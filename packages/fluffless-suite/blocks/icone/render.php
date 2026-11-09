<?php
/**
 * Rendu serveur du bloc fluffless/icone.
 *
 * @package Fluffless_Suite
 *
 * @var array<string, mixed> $attributes Attributs du bloc, fournis par WordPress.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Tout est échappé dans Icone::render() ; le SVG interne vient d'un fichier généré sous liste blanche.
echo \Fluffless\Suite\Blocks\Icone::render( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
