<?php
/**
 * Rendu serveur du bloc fluffless/badge-hebergement.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Tout est échappé dans PiedDePage::badge().
echo \Fluffless\Suite\Blocks\PiedDePage::badge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
