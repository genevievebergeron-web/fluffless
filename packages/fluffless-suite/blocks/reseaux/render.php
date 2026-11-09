<?php
/**
 * Rendu serveur du bloc fluffless/reseaux.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Tout est échappé dans PiedDePage::networks().
echo \Fluffless\Suite\Blocks\PiedDePage::networks(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
