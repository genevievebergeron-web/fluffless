<?php
/**
 * Rendu serveur du bloc fluffless/empreinte.
 *
 * @package Fluffless_Suite
 *
 * @var array<string, mixed> $attributes Attributs du bloc, fournis par WordPress.
 * @var WP_Block             $block      Bloc rendu, fourni par WordPress.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Tout est échappé dans Empreinte::render().
echo \Fluffless\Suite\Blocks\Empreinte::render( $attributes, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
