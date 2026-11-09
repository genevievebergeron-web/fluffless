<?php
/**
 * Désinstallation : supprime les options, métadonnées et transitoires de l'extension
 * (liste complète dans Lifecycle). Les médias en quarantaine, leurs fichiers et les polices
 * installées restent : ce sont les données de l'utilisatrice (voir Lifecycle::uninstall()).
 * WordPress n'a pas chargé le fichier principal ici : on n'inclut que le strict nécessaire.
 *
 * @package Fluffless_Suite
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Core/Options.php';
require_once __DIR__ . '/src/Core/Lifecycle.php';

\Fluffless\Suite\Core\Lifecycle::uninstall();
