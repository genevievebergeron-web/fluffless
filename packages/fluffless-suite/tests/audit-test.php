<?php
/**
 * Tests du Bilan de sobriété (garde des sauvegardes et du cache de page) et du transfert des réglages de la Suite (export, import).
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/audit-test.php
 * Les réglages sont remis à leur état de départ à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Admin\AuditFix;
use Fluffless\Suite\Admin\Transfer;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Audit;
use Fluffless\Suite\Modules\Footprint;
use Fluffless\Suite\Modules\Images;
use Fluffless\Suite\Modules\Shop;

defined( 'ABSPATH' ) || exit;
// Les contrôles lisent les textes en_US : indépendants de la langue du site (fr_CA sur l'instance MariaDB 10.6).
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
$fluffless_media         = array();

/**
 * Pièce jointe de test : un fichier réel de $bytes octets dans le dossier des téléversements.
 *
 * @param string $name  Nom du fichier.
 * @param string $mime  Type MIME.
 * @param int    $bytes Taille.
 * @param string $title Titre.
 */
$fluffless_attach = static function ( string $name, string $mime, int $bytes, string $title ): int {
	$uploads = wp_upload_dir();
	$path    = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], $name );
	file_put_contents( $path, str_repeat( 'x', $bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => $mime,
			'post_title'     => $title,
			'post_status'    => 'inherit',
		),
		$path,
		0,
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( esc_html( 'Pièce jointe de test refusée : ' . $id->get_error_message() ) );
	}
	return $id;
};

/**
 * Pose la section du Bilan et vide le cache.
 *
 * @param array<string, mixed> $section Section brute.
 */
$fluffless_set = static function ( array $section ): void {
	$all = get_option( Options::NAME );
	$all = is_array( $all ) ? $all : array();
	if ( ! isset( $all['modules'] ) || ! is_array( $all['modules'] ) ) {
		$all['modules'] = array();
	}
	$all['modules'][ Audit::id() ] = true;
	$all[ Audit::id() ]            = Audit::sanitize( $section );
	update_option( Options::NAME, $all );
	Options::flush();
};

try {
	// 1. Contrat : quatre réglages (sauvegardes, cache de page), tous vides par défaut.
	$fluffless_fields = Audit::fields();
	$fluffless_assert( array( 'backups', 'backups_checked', 'page_cache', 'page_cache_checked' ) === array_keys( $fluffless_fields ), 'Quatre réglages dans l’ordre.' );
	$fluffless_assert( 'bool' === $fluffless_fields['backups']['type'] && 'text' === $fluffless_fields['backups_checked']['type'], 'Types des réglages de sauvegarde.' );
	$fluffless_assert( 'bool' === $fluffless_fields['page_cache']['type'] && 'text' === $fluffless_fields['page_cache_checked']['type'], 'Types des réglages de cache de page.' );
	$fluffless_assert(
		array(
			'backups'            => false,
			'backups_checked'    => '',
			'page_cache'         => false,
			'page_cache_checked' => '',
		) === Audit::defaults(),
		'Défauts : rien de déclaré.'
	);

	// 2. Assainissement de la date.
	$fluffless_assert( '2026-09-01' === Audit::sanitize_date( ' 2026-09-01 ' ), 'Date valide gardée, espaces retirés.' );
	$fluffless_assert( '' === Audit::sanitize_date( '2026-02-30' ), 'Date impossible refusée.' );
	$fluffless_assert( '' === Audit::sanitize_date( '01/09/2026' ), 'Autre format refusé.' );
	$fluffless_assert( '' === Audit::sanitize_date( '2099-01-01' ), 'Date future refusée.' );
	$fluffless_assert( '' === Audit::sanitize_date( '' ), 'Vide reste vide.' );
	$fluffless_assert( wp_date( 'Y-m-d' ) === Audit::sanitize_date( (string) wp_date( 'Y-m-d' ) ), 'Aujourd’hui accepté.' );
	$fluffless_section = Audit::sanitize(
		array(
			'backups'         => '1',
			'backups_checked' => 'hier',
			'page_cache'      => '1',
			'inconnu'         => 'x',
		)
	);
	$fluffless_assert(
		array(
			'backups'            => true,
			'backups_checked'    => '',
			'page_cache'         => true,
			'page_cache_checked' => '',
		) === $fluffless_section,
		'Section assainie : cases cochées, date invalide vidée, clé inconnue écartée.'
	);

	// 3. Trois états du point « sauvegardes ».
	$fluffless_set( array() );
	$fluffless_assert(
		array(
			'declared' => false,
			'checked'  => '',
		) === Audit::backups(),
		'Rien de déclaré.'
	);
	$fluffless_item = Audit::check_backups();
	$fluffless_assert( 'bad' === $fluffless_item['status'] && '' !== $fluffless_item['fix'], 'Aucune sauvegarde déclarée : à corriger, avec la marche à suivre.' );
	$fluffless_assert( false !== strpos( $fluffless_item['fix'], 'Modules' ), 'La marche à suivre renvoie à l’écran Modules.' );

	$fluffless_set( array( 'backups' => true ) );
	$fluffless_item = Audit::check_backups();
	$fluffless_assert( 'warn' === $fluffless_item['status'] && '' === $fluffless_item['fix'], 'Déclarée sans date : à revoir.' );

	$fluffless_old = gmdate( 'Y-m-d', time() - Audit::BACKUP_MAX_AGE - 2 * DAY_IN_SECONDS );
	$fluffless_set(
		array(
			'backups'         => true,
			'backups_checked' => $fluffless_old,
		)
	);
	$fluffless_assert( Audit::backups()['checked'] === $fluffless_old, 'Vieille date gardée.' );
	$fluffless_item = Audit::check_backups();
	$fluffless_assert( 'warn' === $fluffless_item['status'] && false !== strpos( $fluffless_item['detail'], 'more than a year' ), 'Déclarée il y a plus d’un an : à revoir.' );

	$fluffless_recent = gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS );
	$fluffless_set(
		array(
			'backups'         => true,
			'backups_checked' => $fluffless_recent,
		)
	);
	$fluffless_item = Audit::check_backups();
	$fluffless_assert( 'good' === $fluffless_item['status'], 'Déclarée et vérifiée récemment : bon.' );
	$fluffless_assert( false !== strpos( $fluffless_item['detail'], (string) wp_date( get_option( 'date_format' ), (int) strtotime( $fluffless_recent . ' 00:00:00 UTC' ) ) ), 'La date du bilan suit le format du site.' );

	// 3bis. Trois états du point « cache de page », même patron que les sauvegardes.
	$fluffless_set( array() );
	$fluffless_assert(
		array(
			'declared' => false,
			'checked'  => '',
		) === Audit::page_cache(),
		'Rien de déclaré.'
	);
	$fluffless_item = Audit::check_page_cache();
	$fluffless_assert( 'bad' === $fluffless_item['status'] && '' !== $fluffless_item['fix'], 'Aucun cache de page déclaré : à corriger, avec la marche à suivre.' );
	$fluffless_assert( false !== strpos( $fluffless_item['fix'], 'Modules' ), 'La marche à suivre renvoie à l’écran Modules.' );

	$fluffless_set( array( 'page_cache' => true ) );
	$fluffless_item = Audit::check_page_cache();
	$fluffless_assert( 'warn' === $fluffless_item['status'] && '' === $fluffless_item['fix'], 'Déclaré sans date : à revoir.' );

	$fluffless_cache_old = gmdate( 'Y-m-d', time() - Audit::PAGE_CACHE_MAX_AGE - 2 * DAY_IN_SECONDS );
	$fluffless_set(
		array(
			'page_cache'         => true,
			'page_cache_checked' => $fluffless_cache_old,
		)
	);
	$fluffless_assert( Audit::page_cache()['checked'] === $fluffless_cache_old, 'Vieille date gardée.' );
	$fluffless_item = Audit::check_page_cache();
	$fluffless_assert( 'warn' === $fluffless_item['status'] && false !== strpos( $fluffless_item['detail'], 'more than a year' ), 'Déclaré il y a plus d’un an : à revoir.' );

	$fluffless_cache_recent = gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS );
	$fluffless_set(
		array(
			'backups'            => true,
			'backups_checked'    => $fluffless_recent,
			'page_cache'         => true,
			'page_cache_checked' => $fluffless_cache_recent,
		)
	);
	$fluffless_item = Audit::check_page_cache();
	$fluffless_assert( 'good' === $fluffless_item['status'], 'Déclaré et vérifié récemment : bon.' );
	$fluffless_assert( false !== strpos( $fluffless_item['detail'], (string) wp_date( get_option( 'date_format' ), (int) strtotime( $fluffless_cache_recent . ' 00:00:00 UTC' ) ) ), 'La date du bilan suit le format du site.' );

	// 4. Écran : rien pour un visiteur ; la carte des sauvegardes pour un administrateur, même sans bilan en cache.
	wp_set_current_user( 0 );
	ob_start();
	Audit::render();
	$fluffless_assert( '' === trim( (string) ob_get_clean() ), 'Rien pour un visiteur.' );

	$fluffless_admin = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	$fluffless_assert( array() !== $fluffless_admin, 'Un administrateur existe pour le test.' );
	wp_set_current_user( (int) ( $fluffless_admin[0] ?? 0 ) );
	$fluffless_report = get_transient( Lifecycle::TRANSIENT_AUDIT_REPORT );
	delete_transient( Lifecycle::TRANSIENT_AUDIT_REPORT );
	ob_start();
	Audit::render();
	$fluffless_html = (string) ob_get_clean();
	if ( false !== $fluffless_report ) {
		set_transient( Lifecycle::TRANSIENT_AUDIT_REPORT, $fluffless_report, DAY_IN_SECONDS );
	}
	$fluffless_assert( false !== strpos( $fluffless_html, 'Backups by the host' ), 'Carte des sauvegardes affichée sans bilan en cache.' );
	$fluffless_assert( false !== strpos( $fluffless_html, 'Page cache by the host' ), 'Carte du cache de page affichée sans bilan en cache.' );
	$fluffless_assert( false !== strpos( $fluffless_html, 'checked on' ), 'Détail du point affiché.' );
	$fluffless_assert( false === strpos( $fluffless_html, '<script' ), 'Aucun script sur l’écran.' );

	// 5. Export : contenu et nom du fichier.
	$fluffless_payload = Transfer::payload();
	$fluffless_assert( Options::SCHEMA === $fluffless_payload['schema'] && home_url() === $fluffless_payload['site'], 'Schéma et site dans l’export.' );
	$fluffless_assert( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $fluffless_payload['exported'] ), 'Date d’export en UTC.' );
	$fluffless_assert( Transfer::without_secrets( Options::all() ) === $fluffless_payload['settings'], 'Tous les réglages exportés (hors adresse de connexion cachée).' );
	$fluffless_assert( true === ( $fluffless_payload['settings'][ Audit::id() ]['backups'] ?? null ) && ( $fluffless_payload['settings'][ Audit::id() ]['backups_checked'] ?? '' ) === $fluffless_recent, 'La section du Bilan voyage (sauvegardes).' );
	$fluffless_assert( true === ( $fluffless_payload['settings'][ Audit::id() ]['page_cache'] ?? null ) && ( $fluffless_payload['settings'][ Audit::id() ]['page_cache_checked'] ?? '' ) === $fluffless_cache_recent, 'La section du Bilan voyage (cache de page).' );
	$fluffless_assert( 1 === preg_match( '/^fluffless-settings-[a-z0-9-]+-\d{4}-\d{2}-\d{2}\.json$/', Transfer::filename() ), 'Nom du fichier.' );
	$fluffless_json = wp_json_encode( $fluffless_payload );
	$fluffless_assert( is_string( $fluffless_json ) && strlen( $fluffless_json ) < Transfer::MAX_BYTES, 'Export bien en deçà de la borne d’import.' );

	// 6. Import : aller-retour, réassainissement, refus des fichiers douteux.
	$fluffless_set( array() );
	$fluffless_assert( false === Audit::backups()['declared'] && false === Audit::page_cache()['declared'], 'Réglages remis à zéro avant l’import.' );
	$fluffless_assert( true === Transfer::apply_json( (string) $fluffless_json ), 'Import du fichier exporté.' );
	$fluffless_assert(
		array(
			'declared' => true,
			'checked'  => $fluffless_recent,
		) === Audit::backups(),
		'Réglage sauvegardes rejoué par l’import.'
	);
	$fluffless_assert(
		array(
			'declared' => true,
			'checked'  => $fluffless_cache_recent,
		) === Audit::page_cache(),
		'Réglage cache de page rejoué par l’import.'
	);

	$fluffless_payload['settings'][ Audit::id() ]['backups_checked']    = 'n’importe quoi';
	$fluffless_payload['settings'][ Audit::id() ]['page_cache_checked'] = 'n’importe quoi';
	$fluffless_payload['settings']['inconnu']                           = array( 'x' => 1 );
	$fluffless_assert( true === Transfer::apply( $fluffless_payload ), 'Import d’un fichier retouché.' );
	$fluffless_assert( '' === Audit::backups()['checked'] && '' === Audit::page_cache()['checked'] && ! isset( Options::all()['inconnu'] ), 'Valeurs réassainies et section inconnue écartée.' );

	$fluffless_error = Transfer::apply_json( 'pas du json' );
	$fluffless_assert( is_wp_error( $fluffless_error ) && 'format' === $fluffless_error->get_error_code(), 'Texte qui n’est pas du JSON refusé.' );
	$fluffless_error = Transfer::apply( array( 'settings' => array() ) );
	$fluffless_assert( is_wp_error( $fluffless_error ) && 'format' === $fluffless_error->get_error_code(), 'Fichier sans signature de la Suite refusé.' );
	$fluffless_error = Transfer::apply(
		array(
			'fluffless_suite' => '9.0.0',
			'schema'          => Options::SCHEMA + 1,
			'settings'        => array(),
		)
	);
	$fluffless_assert( is_wp_error( $fluffless_error ) && 'schema' === $fluffless_error->get_error_code(), 'Schéma plus récent refusé.' );
	$fluffless_assert( is_wp_error( Transfer::apply_json( str_repeat( ' ', Transfer::MAX_BYTES + 1 ) ) ), 'Fichier trop gros refusé.' );
	$fluffless_error = Transfer::read_upload( null );
	$fluffless_assert( is_wp_error( $fluffless_error ) && 'upload' === $fluffless_error->get_error_code(), 'Aucun téléversement : erreur upload.' );
	$fluffless_assert( '' !== Transfer::error_message( 'upload' ) && Transfer::error_message( 'zzz' ) === Transfer::error_message( 'format' ), 'Messages d’erreur par code.' );

	// 7. Section de transfert : deux formulaires nonce pour un administrateur, rien pour un visiteur.
	delete_option( Transfer::PREVIOUS );
	ob_start();
	Transfer::render();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( 2 === substr_count( $fluffless_html, 'admin-post.php' ), 'Deux formulaires vers admin-post.' );
	$fluffless_assert( false !== strpos( $fluffless_html, Transfer::ACTION_EXPORT ) && false !== strpos( $fluffless_html, Transfer::ACTION_IMPORT ), 'Actions d’export et d’import.' );
	$fluffless_assert( 2 === substr_count( $fluffless_html, 'name="_wpnonce"' ), 'Un nonce par formulaire.' );
	$fluffless_assert( false !== strpos( $fluffless_html, 'enctype="multipart/form-data"' ) && false !== strpos( $fluffless_html, 'type="file"' ), 'Champ de fichier.' );
	$fluffless_assert( false !== strpos( $fluffless_html, 'hidden login address' ), 'Avertissement sur l’adresse de connexion.' );
	$fluffless_assert( false === strpos( $fluffless_html, '<script' ), 'Aucun script dans la section.' );
	wp_set_current_user( 0 );
	ob_start();
	Transfer::render();
	$fluffless_assert( '' === trim( (string) ob_get_clean() ), 'Rien pour un visiteur.' );

	// 8. Vidéos de la médiathèque : poids lu dans les métadonnées de WordPress, sinon sur le disque.
	$fluffless_videos = Audit::check_videos();
	$fluffless_assert( 'good' === $fluffless_videos['status'] && 'Videos in the media library' === $fluffless_videos['title'] && '' === $fluffless_videos['fix'], 'Sans vidéo : point bon sans correction.' );
	$fluffless_video_id = $fluffless_attach( 'fluffless-lourde.mp4', 'video/mp4', 10, 'Visite lourde' );
	$fluffless_media[]  = $fluffless_video_id;
	$fluffless_meta     = static function ( int $mb, int $width, int $height ) use ( $fluffless_video_id ): void {
		wp_update_attachment_metadata(
			$fluffless_video_id,
			array(
				'filesize' => $mb * MB_IN_BYTES,
				'width'    => $width,
				'height'   => $height,
				'bitrate'  => 8000000,
				'length'   => 90,
			)
		);
	};
	$fluffless_meta( 150, 1920, 1080 );
	$fluffless_videos = Audit::check_videos();
	$fluffless_assert( 'bad' === $fluffless_videos['status'] && str_contains( $fluffless_videos['detail'], 'Visite lourde' ) && str_contains( $fluffless_videos['detail'], '150' ) && str_contains( $fluffless_videos['detail'], '1920' ), 'Vidéo de 150 Mo non signalée à corriger : ' . $fluffless_videos['detail'] );
	$fluffless_assert( str_contains( $fluffless_videos['fix'], 'HandBrake' ) && str_contains( $fluffless_videos['fix'], '1080p' ) && str_contains( $fluffless_videos['fix'], 'Video block' ), 'Recette d’encodage absente de la correction : ' . $fluffless_videos['fix'] );
	$fluffless_meta( 30, 3840, 2160 );
	$fluffless_videos = Audit::check_videos();
	$fluffless_assert( 'warn' === $fluffless_videos['status'] && str_contains( $fluffless_videos['detail'], '2160' ) && str_contains( $fluffless_videos['detail'], '20 MB' ), 'Vidéo de 30 Mo en 2160p non signalée à revoir : ' . $fluffless_videos['detail'] );
	$fluffless_meta( 5, 3840, 2160 );
	$fluffless_assert( 'warn' === Audit::check_videos()['status'], 'Vidéo légère mais au-dessus de 1080p non signalée.' );
	$fluffless_meta( 5, 1920, 1080 );
	$fluffless_videos = Audit::check_videos();
	$fluffless_assert( 'good' === $fluffless_videos['status'] && str_contains( $fluffless_videos['detail'], '20 MB' ) && ! str_contains( $fluffless_videos['detail'], 'Visite lourde' ), 'Vidéo de 5 Mo en 1080p signalée à tort : ' . $fluffless_videos['detail'] );
	// Le seuil est celui du module Images.
	$fluffless_all = get_option( Options::NAME );
	$fluffless_all = is_array( $fluffless_all ) ? $fluffless_all : array();
	update_option( Options::NAME, array_merge( $fluffless_all, array( 'images' => array_merge( Images::defaults(), array( 'video_warn_mb' => 4 ) ) ) ) );
	Options::flush();
	$fluffless_videos = Audit::check_videos();
	$fluffless_assert( 'warn' === $fluffless_videos['status'] && str_contains( $fluffless_videos['detail'], '4 MB' ), 'Seuil du module Images ignoré : ' . $fluffless_videos['detail'] );
	update_option( Options::NAME, $fluffless_all );
	Options::flush();
	// Sans métadonnées : le poids vient du fichier (10 octets).
	delete_post_meta( $fluffless_video_id, '_wp_attachment_metadata' );
	$fluffless_assert( 'good' === Audit::check_videos()['status'], 'Sans métadonnées, le fichier sur le disque devrait suffire.' );
	wp_set_current_user( 1 );
	ob_start();
	Audit::render();
	$fluffless_screen = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_screen, 'Videos in the media library' ), 'Point des vidéos absent de l’écran du Bilan.' );

	// 8 bis. Images de la médiathèque : fichier servi en taille complète, seuils du module Images.
	$fluffless_before = Images::image_library();
	// Point gardé en cache : chaque vérification part d'un calcul neuf.
	$fluffless_check_images = static function (): array {
		Audit::forget_images();
		return Audit::check_images();
	};
	if ( array() !== $fluffless_before && class_exists( 'WP_CLI' ) ) {
		WP_CLI::warning( 'Médiathèque non vide : cas des seuils des images sautés, à vérifier sur une instance neuve.' );
	}
	if ( array() === $fluffless_before ) {
		$fluffless_images = $fluffless_check_images();
		$fluffless_assert( 'good' === $fluffless_images['status'] && 'Images in the media library' === $fluffless_images['title'] && '' === $fluffless_images['fix'], 'Sans image : point bon sans correction.' );
	}
	$fluffless_photo_id = $fluffless_attach( 'fluffless-photo.jpg', 'image/jpeg', 10, 'Photo lourde' );
	$fluffless_media[]  = $fluffless_photo_id;
	$fluffless_photo    = static function ( int $kb, int $width, int $height ) use ( $fluffless_photo_id ): void {
		wp_update_attachment_metadata(
			$fluffless_photo_id,
			array(
				'filesize' => $kb * KB_IN_BYTES,
				'width'    => $width,
				'height'   => $height,
				'file'     => 'fluffless-photo.jpg',
				'sizes'    => array(),
			)
		);
	};
	$fluffless_photo( 3000, 2560, 1707 );
	$fluffless_library = Images::image_library();
	$fluffless_assert( ( $fluffless_library[0]['id'] ?? 0 ) === $fluffless_photo_id && 3000 * KB_IN_BYTES === $fluffless_library[0]['bytes'] && 2560 === $fluffless_library[0]['width'], 'Image la plus récente en tête, poids et largeur lus dans les métadonnées.' );
	$fluffless_images = $fluffless_check_images();
	$fluffless_assert( 'bad' === $fluffless_images['status'] && str_contains( $fluffless_images['detail'], 'Photo lourde' ) && str_contains( $fluffless_images['detail'], '2560 × 1707 px' ) && str_contains( $fluffless_images['detail'], '2 MB' ), 'Image de 3 Mo non signalée à corriger : ' . $fluffless_images['detail'] );
	$fluffless_assert( str_contains( $fluffless_images['fix'], '2000 px' ) && str_contains( $fluffless_images['fix'], 'Replace' ) && str_contains( $fluffless_images['fix'], 'Media > Library' ) && str_contains( $fluffless_images['fix'], '400 KB' ) && str_contains( $fluffless_images['fix'], 'product photo' ), 'Correction sans la marche à suivre : ' . $fluffless_images['fix'] );
	if ( array() === $fluffless_before ) {
		$fluffless_photo( 600, 2000, 1333 );
		$fluffless_images = $fluffless_check_images();
		$fluffless_assert( 'warn' === $fluffless_images['status'] && str_contains( $fluffless_images['detail'], '400 KB' ) && str_contains( $fluffless_images['detail'], '2560 px' ) && str_contains( $fluffless_images['detail'], 'Photo lourde' ), 'Image de 600 ko non signalée à revoir : ' . $fluffless_images['detail'] );
		$fluffless_photo( 200, 4000, 3000 );
		$fluffless_assert( 'warn' === $fluffless_check_images()['status'], 'Image légère mais au-delà de 2560 px non signalée.' );
		$fluffless_photo( 200, 2000, 1333 );
		$fluffless_images = $fluffless_check_images();
		$fluffless_assert( 'good' === $fluffless_images['status'] && str_contains( $fluffless_images['detail'], '1 image' ) && ! str_contains( $fluffless_images['detail'], 'Photo lourde' ), 'Image de 200 ko signalée à tort : ' . $fluffless_images['detail'] );
		// Seuils du module Images.
		$fluffless_all = get_option( Options::NAME );
		$fluffless_all = is_array( $fluffless_all ) ? $fluffless_all : array();
		update_option(
			Options::NAME,
			array_merge(
				$fluffless_all,
				array(
					'images' => array_merge(
						Images::defaults(),
						array(
							'warn_kb' => 100,
							'max_kb'  => 150,
						)
					),
				)
			)
		);
		Options::flush();
		$fluffless_images = $fluffless_check_images();
		$fluffless_assert( 'bad' === $fluffless_images['status'] && str_contains( $fluffless_images['detail'], '150 KB' ), 'Plafond du module Images ignoré : ' . $fluffless_images['detail'] );
		update_option( Options::NAME, $fluffless_all );
		Options::flush();
		// Sans métadonnées : le poids vient du fichier (10 octets), dimensions inconnues.
		delete_post_meta( $fluffless_photo_id, '_wp_attachment_metadata' );
		$fluffless_assert( 'good' === $fluffless_check_images()['status'], 'Sans métadonnées, le fichier sur le disque devrait suffire.' );
		// Fichier absent du serveur (déporté ailleurs) : poids inconnu, jamais compté comme léger.
		$fluffless_file = get_post_meta( $fluffless_photo_id, '_wp_attached_file', true );
		update_post_meta( $fluffless_photo_id, '_wp_attached_file', 'ailleurs/absente.jpg' );
		$fluffless_images = $fluffless_check_images();
		$fluffless_assert( 'unknown' === $fluffless_images['status'] && str_contains( $fluffless_images['detail'], 'weight of 1 image is unknown' ) && ! str_contains( $fluffless_images['detail'], 'all under' ), 'Image sans fichier comptée comme légère : ' . $fluffless_images['detail'] );
		update_post_meta( $fluffless_photo_id, '_wp_attached_file', $fluffless_file );
		// Cache : un changement sans crochet ne se voit pas, le filtre de métadonnées le vide.
		$fluffless_images = $fluffless_check_images();
		update_post_meta( $fluffless_photo_id, '_wp_attached_file', 'ailleurs/absente.jpg' );
		$fluffless_assert( Audit::check_images() === $fluffless_images, 'Point des images recalculé à chaque appel.' );
		$fluffless_assert( 'meta' === Audit::forget_images_filter( 'meta' ) && 'unknown' === Audit::check_images()['status'], 'Filtre des métadonnées : valeur rendue telle quelle, cache vidé.' );
		update_post_meta( $fluffless_photo_id, '_wp_attached_file', $fluffless_file );
		Audit::forget_images();
	}
	// Une vidéo n'est pas une image, une image n'est pas une vidéo.
	$fluffless_ids = array_column( Images::image_library(), 'id' );
	$fluffless_assert( in_array( $fluffless_photo_id, $fluffless_ids, true ) && ! in_array( $fluffless_video_id, $fluffless_ids, true ) && ! in_array( $fluffless_photo_id, array_column( Images::video_library(), 'id' ), true ), 'Images et vidéos mêlées.' );
	ob_start();
	Audit::render();
	$fluffless_screen = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_screen, 'Images in the media library' ), 'Point des images absent de l’écran du Bilan.' );

	// 8 ter. Pages de boutique : réponses simulées, aucun appel réseau réel.
	if ( ! function_exists( 'wc_get_page_id' ) ) {
		$fluffless_assert( array() === Audit::shop_pages(), 'Sans WooCommerce, aucune page de boutique à mesurer.' );
	}
	$fluffless_requests = array();
	$fluffless_shop_net = static function ( $pre, $args, $url ) use ( &$fluffless_requests ) {
		$fluffless_requests[] = array( $url, $args['redirection'] ?? null, $args['method'] ?? 'GET' );
		$page                 = static fn( int $code, array $headers, string $body = '' ): array => array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
		switch ( $url ) {
			case 'https://boutique.example/produit/':
				return $page( 200, array( 'content-length' => '20000' ), '<html><body><img src="/photo.jpg"><link rel="stylesheet" href="https://cdn.example/woo.css"></body></html>' ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- page simulée.
			case 'https://boutique.example/photo.jpg':
				return $page( 200, array( 'content-length' => '700000' ) );
			case 'https://cdn.example/woo.css':
				// Compressé à la volée : HEAD tait la taille, GET la donne.
				return 'HEAD' === ( $args['method'] ?? 'GET' ) ? $page( 200, array() ) : $page( 200, array(), str_repeat( 'a', 3000 ) );
			case 'https://boutique.example/partielle/':
				return $page( 200, array( 'content-length' => '5000' ), '<html><body><script src="/lourd.js"></script></body></html>' ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- page simulée.
			case 'https://boutique.example/lourd.js':
				return 'HEAD' === ( $args['method'] ?? 'GET' ) ? $page( 200, array() ) : $page( 500, array() );
			case 'https://boutique.example/?page_id=7':
				return $page( 200, array( 'content-length' => '40000' ), '<html></html>' );
			case 'https://boutique.example/?page_id=8':
				return $page( 302, array( 'location' => 'https://boutique.example/?page_id=7' ) );
			case 'https://boutique.example/?page_id=9':
				return $page( 302, array( 'location' => 'https://boutique.example/' ) );
			case 'https://boutique.example/panier/':
				return $page( 200, array( 'content-length' => '50000' ), '<html></html>' );
			case 'https://boutique.example/caisse/':
				return $page( 302, array( 'location' => 'https://boutique.example/panier/' ) );
			case 'https://boutique.example/caisse-ailleurs/':
				return $page( 302, array( 'location' => 'https://boutique.example/connexion/' ) );
			case 'https://boutique.example/longue/':
				return $page( 200, array( 'content-length' => '5000' ), '<html><body>' . implode( '', array_map( static fn( int $n ): string => '<img src="/i' . $n . '.jpg">', range( 1, 130 ) ) ) . '</body></html>' );
		}
		if ( 1 === preg_match( '#^https://boutique\.example/i\d+\.jpg$#', $url ) ) {
			return $page( 200, array( 'content-length' => '1000' ) );
		}
		return new WP_Error( 'fluffless_test', 'adresse inattendue : ' . $url );
	};
	add_filter( 'pre_http_request', $fluffless_shop_net, 1, 3 );
	try {
		$fluffless_pages       = array(
			array(
				'label'  => 'Product page',
				'url'    => 'https://boutique.example/produit/',
				'budget' => Audit::SHOP_BUDGET_BYTES,
			),
			array(
				'key'    => 'cart',
				'label'  => 'Cart',
				'url'    => 'https://boutique.example/panier/',
				'budget' => Audit::CHECKOUT_BUDGET_BYTES,
			),
			array(
				'key'    => 'checkout',
				'label'  => 'Checkout',
				'url'    => 'https://boutique.example/caisse/',
				'budget' => Audit::CHECKOUT_BUDGET_BYTES,
			),
		);
		$fluffless_shop_on     = static function ( bool $on ): void {
			$all            = get_option( Options::NAME );
			$all            = is_array( $all ) ? $all : array();
			$all['modules'] = array_merge( is_array( $all['modules'] ?? null ) ? $all['modules'] : array(), array( 'shop' => $on ) );
			update_option( Options::NAME, $all );
			Options::flush();
		};
		$fluffless_shop_backup = get_option( Options::NAME );
		$fluffless_shop_on( true );
		$fluffless_lean = Options::is_enabled( Shop::id() );
		$fluffless_shop = Audit::check_shop( $fluffless_pages );
		$fluffless_assert( 'Shop pages weight' === $fluffless_shop['title'], 'Titre du point boutique.' );
		$fluffless_assert( str_contains( $fluffless_shop['detail'], 'Product page: ' . size_format( 723000 ) . ' in 3 requests' ), 'Poids de la fiche faux : ' . $fluffless_shop['detail'] );
		$fluffless_assert( str_contains( $fluffless_shop['detail'], 'Cart: ' . size_format( 50000 ) . ' in 1 request,' ), 'Poids du panier faux : ' . $fluffless_shop['detail'] );
		$fluffless_assert( str_contains( $fluffless_shop['detail'], 'Checkout: not measured. With an empty cart, WooCommerce sends the visitor to the cart' ) && ! str_contains( $fluffless_shop['detail'], 'Checkout: ' . size_format( 50000 ) ), 'Caisse renvoyée au panier confondue avec lui : ' . $fluffless_shop['detail'] );
		$fluffless_assert( str_contains( $fluffless_shop['detail'], 'Measured with an empty cart' ) && ! str_contains( $fluffless_shop['detail'], 'is unknown' ), 'Note du panier vide absente, ou feuille de style relue par GET restée inconnue : ' . $fluffless_shop['detail'] );
		$fluffless_gets = array_column( array_filter( $fluffless_requests, static fn( array $r ): bool => 'GET' === $r[2] ), 1, 0 );
		$fluffless_assert( 0 === ( $fluffless_gets['https://boutique.example/caisse/'] ?? null ) && 5 === ( $fluffless_gets['https://boutique.example/panier/'] ?? null ), 'Seule la caisse refuse les redirections : ' . wp_json_encode( $fluffless_gets ) );
		$fluffless_count = count( $fluffless_requests );
		Audit::check_shop( array( $fluffless_pages[0], $fluffless_pages[0] ) );
		$fluffless_css = array_filter( array_slice( $fluffless_requests, $fluffless_count ), static fn( array $r ): bool => 'https://cdn.example/woo.css' === $r[0] );
		$fluffless_assert( 2 === count( $fluffless_css ), 'Un même fichier relu pour chaque page (attendu : un HEAD, un GET) : ' . wp_json_encode( array_values( $fluffless_css ) ) );
		// Caisse renvoyée au panier : jamais « bon », même si le panier l'est.
		$fluffless_assert( 'unknown' === Audit::check_shop( array( $fluffless_pages[1], $fluffless_pages[2] ) )['status'], 'Caisse non mesurée, point dit bon.' );
		// Permaliens simples : le panier se reconnaît à sa requête, l'accueil n'est pas pris pour lui.
		$fluffless_plain = static fn( int $id, string $key ): array => array(
			'key'    => $key,
			'label'  => 'cart' === $key ? 'Cart' : 'Checkout',
			'url'    => 'https://boutique.example/?page_id=' . $id,
			'budget' => Audit::CHECKOUT_BUDGET_BYTES,
		);
		$fluffless_assert( str_contains( Audit::check_shop( array( $fluffless_plain( 7, 'cart' ), $fluffless_plain( 8, 'checkout' ) ) )['detail'], 'Checkout: not measured. With an empty cart' ), 'Permaliens simples : renvoi au panier non reconnu.' );
		$fluffless_assert( str_contains( Audit::check_shop( array( $fluffless_plain( 7, 'cart' ), $fluffless_plain( 9, 'checkout' ) ) )['detail'], 'Checkout: sends the visitor to https://boutique.example/' ), 'Permaliens simples : renvoi à l’accueil pris pour le panier.' );
		// Script de taille inconnue : « au moins », jamais bon.
		$fluffless_partial = Audit::check_shop(
			array(
				array(
					'key'    => 'shop',
					'label'  => 'Shop',
					'url'    => 'https://boutique.example/partielle/',
					'budget' => Audit::SHOP_BUDGET_BYTES,
				),
			)
		);
		$fluffless_assert( 'unknown' === $fluffless_partial['status'] && str_contains( $fluffless_partial['detail'], 'Shop: at least ' . size_format( 5000 ) . ' in 2 requests' ), 'Script de taille inconnue, total donné comme complet : ' . $fluffless_partial['status'] . ' ' . $fluffless_partial['detail'] );
		// Délai dépassé : plus aucune requête, rien de dit bon.
		$fluffless_count = count( $fluffless_requests );
		$fluffless_memo  = array();
		$fluffless_late  = Audit::check_shop( array( $fluffless_pages[1] ), 0, $fluffless_memo, microtime( true ) - 1 );
		$fluffless_assert( 'unknown' === $fluffless_late['status'] && str_contains( $fluffless_late['detail'], 'Cart: not measured, the check ran out of time.' ) && count( $fluffless_requests ) === $fluffless_count, 'Délai dépassé, mesure lancée quand même : ' . $fluffless_late['detail'] );
		if ( ! $fluffless_lean && class_exists( 'WP_CLI' ) ) {
			WP_CLI::warning( 'Module Boutique sobre indisponible : cas « Boutique sobre active » sautés.' );
		}
		if ( $fluffless_lean ) {
			$fluffless_assert( 'warn' === $fluffless_shop['status'] && str_contains( $fluffless_shop['fix'], 'product photos' ) && ! str_contains( $fluffless_shop['fix'], 'Lean shop' ), 'Fiche de 720 ko pour 600 : à revoir, sans renvoi vers Boutique sobre déjà en marche : ' . $fluffless_shop['status'] . ' ' . $fluffless_shop['fix'] );
		}
		$fluffless_light = Audit::check_shop( array( $fluffless_pages[1] ) );
		$fluffless_assert( ! $fluffless_lean || ( 'good' === $fluffless_light['status'] && '' === $fluffless_light['fix'] ), 'Panier léger avec Boutique sobre : bon.' );
		$fluffless_shop_on( false );
		$fluffless_off = Audit::check_shop( array( $fluffless_pages[1] ) );
		$fluffless_assert( 'good' === $fluffless_off['status'] && str_contains( $fluffless_off['fix'], 'Lean shop' ) && ! str_contains( $fluffless_off['fix'], 'product photos' ), 'Boutique sobre éteinte, pages sous leur seuil : couleur du poids, renvoi au module, sans conseil sur les photos : ' . $fluffless_off['status'] . ' ' . $fluffless_off['fix'] );
		$fluffless_shop_on( true );
		$fluffless_heavy = Audit::check_shop(
			array(
				array(
					'label'  => 'Product page',
					'url'    => 'https://boutique.example/produit/',
					'budget' => 400000,
				),
			)
		);
		$fluffless_assert( 'bad' === $fluffless_heavy['status'], 'Fiche à 1,8 fois son seuil : à corriger.' );
		$fluffless_none = Audit::check_shop( array( $fluffless_pages[2] ) );
		$fluffless_assert( 'unknown' === $fluffless_none['status'] && '' === $fluffless_none['fix'] && str_contains( $fluffless_none['detail'], 'sends the visitor to https://boutique.example/panier/' ), 'Aucune page mesurée, caisse sans panier connu : adresse donnée : ' . $fluffless_none['detail'] );
		$fluffless_away = Audit::check_shop(
			array(
				$fluffless_pages[1],
				array(
					'key'    => 'checkout',
					'label'  => 'Checkout',
					'url'    => 'https://boutique.example/caisse-ailleurs/',
					'budget' => Audit::CHECKOUT_BUDGET_BYTES,
				),
			)
		);
		$fluffless_assert( str_contains( $fluffless_away['detail'], 'Checkout: sends the visitor to https://boutique.example/connexion/' ), 'Caisse renvoyée ailleurs qu’au panier : adresse donnée : ' . $fluffless_away['detail'] );
		// Page tronquée : jamais bonne, « au moins ».
		$fluffless_long = Audit::check_shop(
			array(
				array(
					'key'    => 'shop',
					'label'  => 'Shop',
					'url'    => 'https://boutique.example/longue/',
					'budget' => Audit::SHOP_BUDGET_BYTES,
				),
			)
		);
		$fluffless_assert( ( ! $fluffless_lean || 'unknown' === $fluffless_long['status'] ) && str_contains( $fluffless_long['detail'], 'at least' ) && str_contains( $fluffless_long['detail'], 'more than 121 requests' ), 'Page tronquée donnée comme complète : ' . $fluffless_long['status'] . ' ' . $fluffless_long['detail'] );
		// Comparaison avec l'accueil.
		$fluffless_ratio = Audit::check_shop( array( $fluffless_pages[0] ), 100000 );
		$fluffless_assert( str_contains( $fluffless_ratio['detail'], 'That is 7.2 times the home page.' ), 'Rapport à l’accueil absent : ' . $fluffless_ratio['detail'] );
		$fluffless_assert( ! str_contains( Audit::check_shop( array( $fluffless_pages[1] ), 100000 )['detail'], 'times the home page' ), 'Panier plus léger que 1,5 fois l’accueil : pas de rapport.' );
		// Boutique fermée (mode Bientôt en ligne) : aucune mesure.
		$fluffless_count = count( $fluffless_requests );
		update_option( 'woocommerce_coming_soon', 'yes' );
		$fluffless_closed = Audit::check_shop( $fluffless_pages );
		delete_option( 'woocommerce_coming_soon' );
		$fluffless_assert( 'unknown' === $fluffless_closed['status'] && str_contains( $fluffless_closed['detail'], 'not open to visitors' ) && count( $fluffless_requests ) === $fluffless_count, 'Boutique fermée mesurée comme ouverte : ' . $fluffless_closed['detail'] );
		if ( false === $fluffless_shop_backup ) {
			delete_option( Options::NAME );
		} else {
			update_option( Options::NAME, $fluffless_shop_backup );
		}
		Options::flush();
	} finally {
		remove_filter( 'pre_http_request', $fluffless_shop_net, 1 );
	}

	// 9. Styles de blocs du cœur écrits dans la page : coût de WordPress, mesuré à part.
	$fluffless_core = Audit::check_core_css( '<html><head><style id="fluffless-x-inline-css">a{}</style></head><body></body></html>' );
	$fluffless_assert( 'good' === $fluffless_core['status'] && 'Block styles written by WordPress' === $fluffless_core['title'] && '' === $fluffless_core['fix'], 'Sans style du cœur : point bon.' );
	$fluffless_html   = '<style id="wp-block-navigation-inline-css">' . str_repeat( 'a', 20861 ) . '</style><style id=\'wp-block-image-inline-css\'>' . str_repeat( 'b', 1000 ) . '</style><style id="core-block-supports-inline-css">' . str_repeat( 'c', 500 ) . '</style><style id="global-styles-inline-css">' . str_repeat( 'd', 9000 ) . '</style>';
	$fluffless_sheets = Audit::core_sheets( $fluffless_html );
	$fluffless_assert(
		array(
			'navigation'     => 20861,
			'image'          => 1000,
			'block-supports' => 500,
		) === $fluffless_sheets,
		'Feuilles du cœur mal relevées : ' . wp_json_encode( $fluffless_sheets )
	);
	$fluffless_core = Audit::check_core_css( $fluffless_html );
	$fluffless_assert( 'good' === $fluffless_core['status'] && str_contains( $fluffless_core['detail'], 'navigation' ) && str_contains( $fluffless_core['detail'], '3 style sheets' ) && str_contains( $fluffless_core['detail'], 'apart from its budget' ), 'Détail des feuilles du cœur : ' . $fluffless_core['detail'] );
	$fluffless_core = Audit::check_core_css( $fluffless_html . '<style id="wp-block-gallery-inline-css">' . str_repeat( 'e', 9000 ) . '</style>' );
	$fluffless_assert( 'warn' === $fluffless_core['status'] && str_contains( $fluffless_core['fix'], 'Nothing to fix in the theme' ), 'Au-delà de 30 ko : point à revoir avec sa note : ' . $fluffless_core['fix'] );
	$fluffless_core = Audit::check_core_css( '<style id="wp-block-button-inline-css">x</style>' );
	$fluffless_assert( str_contains( $fluffless_core['detail'], '1 style sheet,' ) && str_contains( $fluffless_core['detail'], 'for the button block' ), 'Singulier : ' . $fluffless_core['detail'] );

	// 10. Branchement.
	$fluffless_assert( false !== has_action( 'admin_post_' . Transfer::ACTION_EXPORT, array( Transfer::class, 'export' ) ) || ! is_admin(), 'Export branché en administration.' );
	$fluffless_plugin = (string) file_get_contents( dirname( __DIR__ ) . '/fluffless-suite.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( false !== strpos( $fluffless_plugin, 'Admin\Transfer::register()' ), 'Transfert enregistré par l’extension.' );
	$fluffless_page = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/SettingsPage.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( false !== strpos( $fluffless_page, 'Transfer::render()' ), 'Section rendue sous l’écran Modules.' );

	// 11. Boutons « Corriger » : liste blanche, offre seulement si le constat cloche, effet réel.
	$fluffless_toggle = static function ( string $module, bool $on, array $section = array() ): void {
		$all                       = get_option( Options::NAME );
		$all                       = is_array( $all ) ? $all : array();
		$all['modules']            = is_array( $all['modules'] ?? null ) ? $all['modules'] : array();
		$all['modules'][ $module ] = $on;
		if ( array() !== $section ) {
			$all[ $module ] = array_merge( is_array( $all[ $module ] ?? null ) ? $all[ $module ] : array(), $section );
		}
		update_option( Options::NAME, $all );
		Options::flush();
	};
	$fluffless_assert( array( 'shop', 'images', 'footprint', 'consent' ) === array_keys( AuditFix::fixes() ), 'Quatre corrections connues, pas une de plus.' );
	$fluffless_assert( false === AuditFix::apply( 'cleanup' ) && false === AuditFix::apply( '' ), 'Identifiant inconnu refusé.' );

	$fluffless_toggle( Shop::id(), false );
	$fluffless_assert( 'shop' === AuditFix::for_item( 'shop', array( 'status' => 'warn' ) ), 'Boutique sobre éteinte : bouton proposé sur un constat à améliorer.' );
	$fluffless_assert( null === AuditFix::for_item( 'shop', array( 'status' => 'good' ) ) && null === AuditFix::for_item( 'shop', array( 'status' => 'unknown' ) ), 'Constat bon ou inconnu : aucun bouton.' );
	$fluffless_assert( null === AuditFix::for_item( 'protocol', array( 'status' => 'bad' ) ), 'Constat d’hébergeur : conseil seulement.' );
	$fluffless_before = get_option( Options::NAME );
	$fluffless_assert( AuditFix::apply( 'shop' ) && Options::is_enabled( Shop::id() ), 'Corriger allume Boutique sobre.' );
	$fluffless_after = get_option( Options::NAME );
	$fluffless_assert( is_array( $fluffless_before ) && is_array( $fluffless_after ) && ( $fluffless_before[ Audit::id() ] ?? null ) === ( $fluffless_after[ Audit::id() ] ?? null ), 'Les autres sections restent intactes.' );
	$fluffless_assert( null === AuditFix::for_item( 'shop', array( 'status' => 'warn' ) ) && false === AuditFix::apply( 'shop' ), 'Déjà fait : plus de bouton, rien à refaire.' );

	foreach ( array(
		'images' => Images::id(),
		'weight' => Footprint::id(),
	) as $fluffless_key => $fluffless_module ) {
		$fluffless_toggle( $fluffless_module, false );
		$fluffless_fix = AuditFix::for_item( $fluffless_key, array( 'status' => 'bad' ) );
		$fluffless_assert( null !== $fluffless_fix && AuditFix::apply( $fluffless_fix ) && Options::is_enabled( $fluffless_module ), 'Corriger « ' . $fluffless_key . ' » allume ' . $fluffless_module . '.' );
	}

	$fluffless_assert( 'good' === Audit::check_consent( array() )['status'], 'Aucun domaine tiers : consentement bon.' );
	$fluffless_consent = Audit::check_consent( array( 'cdn.exemple.test' ) );
	$fluffless_assert( ( AuditFix::consent_services() > 0 ? 'good' : 'warn' ) === $fluffless_consent['status'], 'Domaine tiers : bon seulement si Consent déclare au moins un service.' );
	if ( AuditFix::consent_active() ) {
		add_filter( 'fluffless_consent_services', '__return_empty_array', 99 );
		$fluffless_assert( 'warn' === Audit::check_consent( array( 'cdn.exemple.test' ) )['status'], 'Consent active sans service déclaré : à améliorer, jamais bon.' );
		remove_filter( 'fluffless_consent_services', '__return_empty_array', 99 );
	}
	$fluffless_assert( str_contains( $fluffless_consent['detail'], 'cdn.exemple.test' ), 'Domaines nommés : ' . $fluffless_consent['detail'] );
	wp_set_current_user( 0 );
	$fluffless_assert( ! AuditFix::available( 'consent' ), 'Sans droit d’activer une extension : aucun bouton Consent.' );

	// Formulaire : nonce propre à la correction, identifiant seulement.
	$fluffless_form = AuditFix::form( 'shop' );
	$fluffless_assert( str_contains( $fluffless_form, 'name="fix" value="shop"' ) && str_contains( $fluffless_form, 'value="' . AuditFix::ACTION . '"' ) && str_contains( $fluffless_form, '_wpnonce' ), 'Formulaire : action, identifiant, nonce.' );
	$fluffless_assert( '' === AuditFix::form( 'cleanup' ), 'Aucun formulaire pour un identifiant inconnu.' );
	$fluffless_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AuditFix.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( str_contains( $fluffless_source, "check_admin_referer( self::ACTION . '_' . \$fix )" ) && str_contains( $fluffless_source, 'current_user_can( SettingsPage::CAPABILITY )' ), 'Traitement : droits et nonce vérifiés.' );
} finally {
	wp_set_current_user( 0 );
	foreach ( $fluffless_media as $fluffless_medium ) {
		wp_delete_attachment( $fluffless_medium, true );
	}
	delete_option( Transfer::PREVIOUS );
	if ( false === $fluffless_backup_option ) {
		delete_option( Options::NAME );
	} else {
		update_option( Options::NAME, $fluffless_backup_option );
	}
	Options::flush();
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		WP_CLI::warning( $fluffless_failure );
	}
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . ' vérifications.' );
}
WP_CLI::success( 'Bilan et transfert : ' . $fluffless_checks . ' vérifications passées.' );
