<?php
/**
 * Tests des commandes wp fluffless settings export|import|undo : fichier sans l'adresse de connexion,
 * aperçu identique à celui de l'écran, rien d'écrit avec --dry-run, module sensible retenu sans
 * --confirm-module, annulation qui rétablit l'état d'avant.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/transfer-cli-test.php
 * Les réglages sont remis à leur état de départ à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Admin\Transfer;
use Fluffless\Suite\Core\Options;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_backup_option = get_option( Options::NAME );
$fluffless_backup_prev   = get_option( Transfer::PREVIOUS );

/**
 * Lance une commande dans ce processus et rend sortie, erreurs et code de retour.
 *
 * @param string $command Commande sans « wp ».
 * @return array{stdout: string, stderr: string, return_code: int}
 */
$fluffless_run = static function ( string $command ): array {
	$result = WP_CLI::runcommand(
		$command,
		array(
			'return'     => 'all',
			'launch'     => false,
			'exit_error' => false,
		)
	);
	return array(
		'stdout'      => (string) ( $result->stdout ?? '' ),
		'stderr'      => (string) ( $result->stderr ?? '' ),
		'return_code' => (int) ( $result->return_code ?? 1 ),
	);
};

$fluffless_secret = 'entree-secrete-cli';
$fluffless_dir    = trailingslashit( get_temp_dir() ) . 'fluffless-transfer-cli-' . wp_generate_password( 8, false );
wp_mkdir_p( $fluffless_dir );
$fluffless_file = $fluffless_dir . '/reglages.json';

update_option(
	Options::NAME,
	array(
		'version'  => Options::SCHEMA,
		'modules'  => array( 'security' => false ),
		'security' => array( 'login_slug' => $fluffless_secret ),
	)
);
Options::flush();
delete_option( Transfer::PREVIOUS );

// 1. Export : fichier valide, sans l'adresse cachée; un fichier existant n'est pas écrasé sans --force.
$fluffless_export = $fluffless_run( 'fluffless settings export ' . escapeshellarg( $fluffless_file ) );
$fluffless_assert( 0 === $fluffless_export['return_code'] && is_readable( $fluffless_file ), 'Export : fichier écrit.' );
$fluffless_json = is_readable( $fluffless_file ) ? (string) file_get_contents( $fluffless_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier temporaire du test.
$fluffless_data = json_decode( $fluffless_json, true );
$fluffless_assert( is_array( $fluffless_data ) && isset( $fluffless_data['fluffless_suite'], $fluffless_data['exported'] ), 'Export : JSON avec version et date.' );
$fluffless_assert( false === strpos( $fluffless_json, $fluffless_secret ), 'Export : l’adresse de connexion cachée est absente du fichier.' );
$fluffless_again = $fluffless_run( 'fluffless settings export ' . escapeshellarg( $fluffless_file ) );
$fluffless_assert( 0 !== $fluffless_again['return_code'], 'Export : refuse d’écraser sans --force.' );
$fluffless_forced = $fluffless_run( 'fluffless settings export ' . escapeshellarg( $fluffless_file ) . ' --force' );
$fluffless_assert( 0 === $fluffless_forced['return_code'], 'Export : --force écrase.' );

// 2. Fichier qui demande Sécurité : l'aperçu est celui de l'écran, --dry-run n'écrit rien, le module reste retenu.
$fluffless_data['settings']['modules']['security']    = true;
$fluffless_data['settings']['security']['login_slug'] = 'adresse-du-fichier';
file_put_contents( $fluffless_file, (string) wp_json_encode( $fluffless_data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fichier temporaire du test.
$fluffless_before = get_option( Options::NAME );
$fluffless_dry    = $fluffless_run( 'fluffless settings import ' . escapeshellarg( $fluffless_file ) . ' --dry-run' );
$fluffless_plan   = Transfer::plan( $fluffless_data );
$fluffless_assert( 0 === $fluffless_dry['return_code'], 'Import --dry-run : succès.' );
$fluffless_assert( get_option( Options::NAME ) === $fluffless_before, 'Import --dry-run : aucune écriture.' );
$fluffless_assert( is_array( $fluffless_plan ), 'Aperçu calculable.' );
if ( is_array( $fluffless_plan ) ) {
	foreach ( Transfer::preview_lines( $fluffless_plan ) as $fluffless_line ) {
		$fluffless_assert( false !== strpos( $fluffless_dry['stdout'], $fluffless_line ), 'Import --dry-run : la ligne « ' . $fluffless_line . ' » est celle de l’écran.' );
	}
}
$fluffless_assert( false !== strpos( $fluffless_dry['stdout'], '--confirm-module=security' ), 'Import --dry-run : indique comment confirmer Sécurité.' );

// 3. Import --yes sans --confirm-module : Sécurité reste éteint; l'état d'avant est gardé; undo le rétablit.
$fluffless_applied = $fluffless_run( 'fluffless settings import ' . escapeshellarg( $fluffless_file ) . ' --yes' );
Options::flush();
$fluffless_assert( 0 === $fluffless_applied['return_code'], 'Import --yes : succès.' );
$fluffless_assert( ! Options::is_enabled( 'security' ), 'Import --yes : Sécurité reste éteint sans --confirm-module.' );
$fluffless_assert( null !== Transfer::previous(), 'Import --yes : l’état d’avant est gardé.' );
$fluffless_undone = $fluffless_run( 'fluffless settings undo --yes' );
Options::flush();
$fluffless_assert( 0 === $fluffless_undone['return_code'], 'Undo --yes : succès.' );
$fluffless_assert( null === Transfer::previous(), 'Undo : l’état gardé est consommé.' );
$fluffless_nothing = $fluffless_run( 'fluffless settings undo --yes' );
$fluffless_assert( 0 !== $fluffless_nothing['return_code'], 'Undo sans état gardé : erreur.' );

// 4. Avec --confirm-module=security : Sécurité s'active, l'adresse du fichier n'est jamais reprise.
$fluffless_confirmed = $fluffless_run( 'fluffless settings import ' . escapeshellarg( $fluffless_file ) . ' --yes --confirm-module=security' );
Options::flush();
$fluffless_assert( 0 === $fluffless_confirmed['return_code'] && Options::is_enabled( 'security' ), 'Import --confirm-module=security : Sécurité activé.' );
$fluffless_assert( \Fluffless\Suite\Modules\Security::slug() === $fluffless_secret, 'Import confirmé : l’adresse du fichier n’est jamais reprise.' );

// 5. Garde-fous : module inconnu, fichier absent, fichier qui n'est pas un export.
$fluffless_unknown = $fluffless_run( 'fluffless settings import ' . escapeshellarg( $fluffless_file ) . ' --yes --confirm-module=form' );
$fluffless_assert( 0 !== $fluffless_unknown['return_code'], 'Import : module à confirmer inconnu refusé.' );
$fluffless_missing = $fluffless_run( 'fluffless settings import ' . escapeshellarg( $fluffless_dir . '/absent.json' ) . ' --yes' );
$fluffless_assert( 0 !== $fluffless_missing['return_code'], 'Import : fichier absent refusé.' );
file_put_contents( $fluffless_dir . '/faux.json', 'pas du json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fichier temporaire du test.
$fluffless_bad    = get_option( Options::NAME );
$fluffless_notexp = $fluffless_run( 'fluffless settings import ' . escapeshellarg( $fluffless_dir . '/faux.json' ) . ' --yes' );
$fluffless_assert( 0 !== $fluffless_notexp['return_code'] && get_option( Options::NAME ) === $fluffless_bad, 'Import : fichier invalide refusé, rien d’écrit.' );

// Remise à l'état de départ.
foreach ( (array) glob( $fluffless_dir . '/*' ) as $fluffless_tmp ) {
	wp_delete_file( (string) $fluffless_tmp );
}
rmdir( $fluffless_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- dossier temporaire du test.
if ( false === $fluffless_backup_option ) {
	delete_option( Options::NAME );
} else {
	update_option( Options::NAME, $fluffless_backup_option );
}
if ( false === $fluffless_backup_prev ) {
	delete_option( Transfer::PREVIOUS );
} else {
	update_option( Transfer::PREVIOUS, $fluffless_backup_prev, false );
}
Options::flush();

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		WP_CLI::warning( $fluffless_failure );
	}
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . ' vérifications.' );
}
WP_CLI::success( 'Transfert des réglages en ligne de commande : ' . $fluffless_checks . ' vérifications passées.' );
