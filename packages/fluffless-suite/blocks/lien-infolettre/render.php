<?php
/**
 * Rendu serveur du bloc fluffless/lien-infolettre.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Tout est échappé dans PiedDePage::newsletter().
echo \Fluffless\Suite\Blocks\PiedDePage::newsletter(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
