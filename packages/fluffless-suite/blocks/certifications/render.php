<?php
/**
 * Rendu serveur du bloc fluffless/certifications.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Tout est échappé dans PiedDePage::certifications().
echo \Fluffless\Suite\Blocks\PiedDePage::certifications(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
