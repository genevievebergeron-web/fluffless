<?php
/**
 * Rendu serveur du bloc fluffless/legal.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Tout est échappé dans PiedDePage::legal().
echo \Fluffless\Suite\Blocks\PiedDePage::legal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
