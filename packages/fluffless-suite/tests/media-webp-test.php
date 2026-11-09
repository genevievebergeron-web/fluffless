<?php
/**
 * Tests du module Médiathèque WebP et de la quarantaine des images.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/media-webp-test.php
 * Crée ses propres images et contenus de test, puis les retire (et restaure le logo du site).
 *
 * @package Fluffless_Suite
 */

// Le test déclenche wp_scheduled_delete, crochet du cœur : ce nom ne peut pas porter le préfixe.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Modules\Images;
use Fluffless\Suite\Modules\Media\Quarantine;
use Fluffless\Suite\Modules\MediaWebp;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( MediaWebp::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

/**
 * Image JPEG d'allure photographique (dégradé + bruit), téléversée comme un envoi normal.
 *
 * @param string $name   Nom de fichier.
 * @param int    $width  Largeur.
 * @param int    $height Hauteur.
 */
$fluffless_upload = static function ( string $name, int $width, int $height ): int {
	$image = imagecreatetruecolor( max( 1, $width ), max( 1, $height ) );
	for ( $y = 0; $y < $height; $y += 4 ) {
		for ( $x = 0; $x < $width; $x += 4 ) {
			$noise = wp_rand( 0, 36 ) - 18;
			$color = (int) imagecolorallocate( $image, max( 0, min( 255, (int) ( 255 * $x / $width ) + $noise ) ), max( 0, min( 255, 120 + $noise ) ), max( 0, min( 255, (int) ( 255 * $y / $height ) + $noise ) ) );
			imagefilledrectangle( $image, $x, $y, $x + 3, $y + 3, $color );
		}
	}
	$tmp = wp_tempnam( $name );
	imagejpeg( $image, $tmp, 88 );
	$id = media_handle_sideload(
		array(
			'name'     => $name . '.jpg',
			'tmp_name' => $tmp,
		),
		0
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( esc_html( 'Téléversement de test impossible : ' . $id->get_error_message() ) );
	}
	return $id;
};

/**
 * Empreinte des fichiers d'un média (chemin => md5) et de ses métadonnées.
 *
 * @param int $id Média.
 * @return array{files: array<string, string>, meta: mixed}
 */
$fluffless_fingerprint = static function ( int $id ): array {
	$files = array();
	$meta  = wp_get_attachment_metadata( $id );
	foreach ( MediaWebp::files( (string) get_attached_file( $id, true ), is_array( $meta ) ? $meta : array() ) as $file ) {
		$files[ $file ] = (string) md5_file( $file );
	}
	return array(
		'files' => $files,
		'meta'  => $meta,
	);
};

$fluffless_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );
$fluffless_logo_before = get_option( 'site_logo', null );
// Les images de test dépassent le seuil d'alerte du module Images : son message en attente est remis comme avant.
$fluffless_notices_before = get_transient( Lifecycle::TRANSIENT_UPLOAD_NOTICES );
$fluffless_media          = array();
$fluffless_posts          = array();
$fluffless_leftovers      = array();
// Téléversement « d'avant » : tailles en JPEG, comme une médiathèque existante.
remove_filter( 'image_editor_output_format', array( Images::class, 'output_format' ) );
$fluffless_suffix = strtolower( wp_generate_password( 6, false ) );

try {
	$fluffless_a     = $fluffless_upload( 'fluffless-essai-lac-' . $fluffless_suffix, 1920, 1280 );
	$fluffless_b     = $fluffless_upload( 'fluffless-essai-foret-' . $fluffless_suffix, 1600, 1067 );
	$fluffless_c     = $fluffless_upload( 'fluffless-essai-ville-' . $fluffless_suffix, 1200, 800 );
	$fluffless_logo  = $fluffless_upload( 'fluffless-essai-logo-' . $fluffless_suffix, 400, 400 );
	$fluffless_d     = $fluffless_upload( 'fluffless-essai-extra-' . $fluffless_suffix, 1000, 700 );
	$fluffless_media = array( $fluffless_a, $fluffless_b, $fluffless_c, $fluffless_logo, $fluffless_d );
	update_option( 'site_logo', $fluffless_logo );

	// Image A dans un BROUILLON, image B en image mise en avant, C inutilisée, D pour la route REST.
	$fluffless_large  = (string) wp_get_attachment_image_url( $fluffless_a, 'large' );
	$fluffless_draft  = wp_insert_post(
		array(
			'post_title'   => 'Brouillon de test Fluffless',
			'post_status'  => 'draft',
			'post_content' => '<!-- wp:image {"id":' . $fluffless_a . '} --><figure class="wp-block-image"><img src="' . esc_url( $fluffless_large ) . '" class="wp-image-' . $fluffless_a . '"/></figure><!-- /wp:image -->',
		)
	);
	$fluffless_public = wp_insert_post(
		array(
			'post_title'  => 'Article de test Fluffless',
			'post_status' => 'publish',
		)
	);
	$fluffless_posts  = array( (int) $fluffless_draft, (int) $fluffless_public );
	set_post_thumbnail( (int) $fluffless_public, $fluffless_b );
	add_filter( 'image_editor_output_format', array( Images::class, 'output_format' ) );

	// 1. Détection : logo, image mise en avant, image d'un brouillon jamais proposés.
	$fluffless_assert( 'used' === Quarantine::check( $fluffless_logo )['state'], 'détection : logo proposé' );
	$fluffless_assert( 'used' === Quarantine::check( $fluffless_b )['state'], 'détection : image mise en avant proposée' );
	$fluffless_assert( 'used' === Quarantine::check( $fluffless_a )['state'], 'détection : image d\'un brouillon proposée' );
	$fluffless_assert( 'unused' === Quarantine::check( $fluffless_c )['state'], 'détection : image inutilisée non reconnue' );
	$fluffless_before_scan = $fluffless_fingerprint( $fluffless_c );
	$fluffless_scan        = Quarantine::scan();
	$fluffless_proposed    = array_column( $fluffless_scan['unused'], 'id' );
	$fluffless_assert( ! array_intersect( array( $fluffless_logo, $fluffless_a, $fluffless_b ), $fluffless_proposed ), 'scan : image utilisée proposée' );
	$fluffless_assert( in_array( $fluffless_c, $fluffless_proposed, true ), 'scan : image inutilisée absente' );

	// 2. Rien n'est supprimé ni déplacé sans action.
	$fluffless_assert( $fluffless_before_scan === $fluffless_fingerprint( $fluffless_c ) && 'inherit' === get_post_field( 'post_status', $fluffless_c ), 'scan : un fichier a bougé sans action' );
	$fluffless_assert( is_wp_error( Quarantine::quarantine_attachment( $fluffless_a ) ), 'quarantaine : image utilisée acceptée' );

	// 3. Conversion WebP de trois images : plus légères, contenu réécrit, originaux gardés par défaut.
	$fluffless_a_files = MediaWebp::files( (string) get_attached_file( $fluffless_a, true ), (array) wp_get_attachment_metadata( $fluffless_a ) );
	foreach ( array( $fluffless_a, $fluffless_b, $fluffless_c ) as $fluffless_id ) {
		$fluffless_result = MediaWebp::convert( $fluffless_id );
		$fluffless_data   = get_post_meta( $fluffless_id, MediaWebp::META, true );
		$fluffless_assert( 'converted' === $fluffless_result['status'] && $fluffless_result['saved'] > 0, "conversion #{$fluffless_id} : {$fluffless_result['status']} {$fluffless_result['message']}" );
		$fluffless_assert( 'image/webp' === get_post_mime_type( $fluffless_id ) && Quarantine::webp_is_valid( $fluffless_id ), "conversion #{$fluffless_id} : WebP invalide" );
		if ( is_array( $fluffless_data ) ) {
			$fluffless_leftovers = array_merge( $fluffless_leftovers, (array) $fluffless_data['old_files'] );
			echo esc_html( sprintf( 'WebP #%d : %d octets avant (toutes tailles), %d après, %d économisés.', $fluffless_id, $fluffless_data['old_bytes'], $fluffless_data['new_bytes'], $fluffless_data['old_bytes'] - $fluffless_data['new_bytes'] ) ) . "\n";
		}
	}
	$fluffless_meta = wp_get_attachment_metadata( $fluffless_a );
	$fluffless_assert( is_array( $fluffless_meta ) && ! empty( $fluffless_meta['sizes'] ) && str_ends_with( reset( $fluffless_meta['sizes'] )['file'], '.webp' ), 'conversion : tailles non régénérées en WebP' );
	$fluffless_content = (string) get_post_field( 'post_content', (int) $fluffless_draft );
	$fluffless_assert( ! str_contains( $fluffless_content, '.jpg' ) && str_contains( $fluffless_content, '.webp' ), 'conversion : contenu du brouillon non réécrit' );
	$fluffless_assert( array() === array_filter( $fluffless_a_files, static fn( string $f ): bool => ! is_file( $f ) ), 'conversion : un original a disparu (il doit être gardé par défaut)' );
	$fluffless_assert( 'used' === Quarantine::check( $fluffless_a )['state'], 'détection : image convertie du brouillon proposée' );
	$fluffless_assert( array() === MediaWebp::candidates( 50, false ) || ! array_intersect( array( $fluffless_a, $fluffless_b, $fluffless_c ), MediaWebp::candidates( 50, false ) ), 'file : image convertie encore en attente' );

	// 4. Route REST : upload_files exigé ; un lot traite l'image D.
	MediaWebp::register_routes();
	$fluffless_request = new WP_REST_Request( 'POST', '/fluffless/v1/webp/batch' );
	$fluffless_request->set_param( 'limit', 20 );
	wp_set_current_user( 0 );
	$fluffless_assert( in_array( rest_do_request( $fluffless_request )->get_status(), array( 401, 403 ), true ), 'REST : lot lancé sans connexion' );
	wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );
	$fluffless_response = rest_do_request( $fluffless_request );
	$fluffless_body     = (array) $fluffless_response->get_data();
	$fluffless_done     = is_array( $fluffless_body['processed'] ?? null ) ? array_column( $fluffless_body['processed'], 'id' ) : array();
	$fluffless_assert( 200 === $fluffless_response->get_status() && in_array( $fluffless_d, $fluffless_done, true ), 'REST : image D non traitée par le lot' );
	$fluffless_data = get_post_meta( $fluffless_d, MediaWebp::META, true );
	if ( is_array( $fluffless_data ) && isset( $fluffless_data['old_files'] ) ) {
		$fluffless_leftovers = array_merge( $fluffless_leftovers, (array) $fluffless_data['old_files'] );
	}

	// 5. Quarantaine puis restauration : fichiers et métadonnées intacts.
	$fluffless_before = $fluffless_fingerprint( $fluffless_c );
	$fluffless_assert( true === Quarantine::quarantine_attachment( $fluffless_c ), 'quarantaine : échec sur une image inutilisée' );
	$fluffless_root = trailingslashit( wp_upload_dir()['basedir'] ) . Quarantine::DIR;
	$fluffless_assert( is_file( $fluffless_root . '/.htaccess' ) && is_file( $fluffless_root . '/index.php' ), 'quarantaine : dossier non protégé' );
	$fluffless_assert( array() === array_filter( array_keys( $fluffless_before['files'] ), 'is_file' ), 'quarantaine : fichiers encore à leur place' );
	$fluffless_assert( Quarantine::STATUS === get_post_field( 'post_status', $fluffless_c ), 'quarantaine : média encore visible' );
	$fluffless_assert(
		! in_array(
			$fluffless_c,
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			),
			true
		),
		'quarantaine : média encore dans la médiathèque'
	);
	$fluffless_assert( in_array( $fluffless_c, array_column( Quarantine::items(), 'id' ), true ), 'quarantaine : élément absent de la liste' );
	do_action( 'wp_scheduled_delete' );
	$fluffless_assert( 'attachment' === get_post_type( $fluffless_c ), 'quarantaine : supprimée par une tâche planifiée' );
	$fluffless_assert( true === Quarantine::restore( $fluffless_c ), 'restauration : échec' );
	$fluffless_after = $fluffless_fingerprint( $fluffless_c );
	$fluffless_assert( $fluffless_before === $fluffless_after && 'inherit' === get_post_field( 'post_status', $fluffless_c ), 'restauration : fichiers ou métadonnées différents' );
	$fluffless_assert( '' === get_post_meta( $fluffless_c, Quarantine::META, true ), 'restauration : métadonnée de quarantaine restée' );

	// 6. Originaux convertis : quarantaine seulement si le WebP est valide, puis restauration.
	$fluffless_originals = array_column( Quarantine::originals(), 'id' );
	$fluffless_assert( in_array( $fluffless_a, $fluffless_originals, true ), 'originaux : image convertie non proposée' );
	$fluffless_assert( true === Quarantine::quarantine_originals( $fluffless_a ), 'originaux : échec de la quarantaine' );
	$fluffless_assert( array() === array_filter( $fluffless_a_files, 'is_file' ) && Quarantine::webp_is_valid( $fluffless_a ), 'originaux : fichiers restés ou WebP abîmé' );
	$fluffless_assert( true === Quarantine::restore( $fluffless_a, true ) && array() === array_filter( $fluffless_a_files, static fn( string $f ): bool => ! is_file( $f ) ), 'originaux : restauration incomplète' );

	// 6 bis. Restauration par formulaire : un auteur ne remet pas en ligne l'image d'une autre personne.
	Quarantine::quarantine_attachment( $fluffless_c );
	$fluffless_user   = wp_insert_user(
		array(
			'user_login' => 'fluffless-auteur-' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'author',
		)
	);
	$fluffless_author = is_int( $fluffless_user ) ? $fluffless_user : 0;
	$fluffless_assert( $fluffless_author > 0, 'restauration : auteur d\'essai non créé' );
	// handle_post() finit par exit : la redirection lève une exception pour rendre la main au test.
	$fluffless_stop = static function ( string $location ): string {
		if ( '' !== $location ) {
			throw new RuntimeException( 'redirection' );
		}
		return $location;
	};
	add_filter( 'wp_redirect', $fluffless_stop, 0 );
	try {
		wp_set_current_user( $fluffless_author );
		$_POST['restore']     = 'file:' . $fluffless_c;
		$_REQUEST['_wpnonce'] = wp_create_nonce( Quarantine::ACTION );
		try {
			Quarantine::handle_post();
		} catch ( RuntimeException $fluffless_e ) {
			unset( $fluffless_e );
		}
		$fluffless_assert( Quarantine::STATUS === get_post_field( 'post_status', $fluffless_c ), 'restauration : un auteur a remis en ligne l\'image d\'une autre personne' );
		$fluffless_assert( '' !== (string) get_transient( Quarantine::ACTION . '_error_' . $fluffless_author ), 'restauration refusée : aucun message' );
	} finally {
		remove_filter( 'wp_redirect', $fluffless_stop, 0 );
		unset( $_POST['restore'], $_REQUEST['_wpnonce'] );
		delete_transient( Quarantine::ACTION . '_error_' . $fluffless_author );
		wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $fluffless_author );
	}
	$fluffless_assert( true === Quarantine::restore( $fluffless_c ), 'restauration : échec après le refus' );

	// 7. Purge seulement sur appel explicite : fichiers et média retirés.
	Quarantine::quarantine_attachment( $fluffless_c );
	$fluffless_freed = Quarantine::purge( $fluffless_c );
	$fluffless_assert( is_int( $fluffless_freed ) && $fluffless_freed > 0 && null === get_post( $fluffless_c ), 'purge : média ou fichiers restés' );
	$fluffless_assert( array() === array_filter( array_keys( $fluffless_after['files'] ), 'is_file' ), 'purge : fichier resté sur le disque' );
	$fluffless_media = array_diff( $fluffless_media, array( $fluffless_c ) );
} finally {
	add_filter( 'image_editor_output_format', array( Images::class, 'output_format' ) );
	foreach ( $fluffless_posts as $fluffless_post ) {
		wp_delete_post( $fluffless_post, true );
	}
	foreach ( $fluffless_media as $fluffless_id ) {
		wp_delete_attachment( (int) $fluffless_id, true );
	}
	foreach ( array_unique( $fluffless_leftovers ) as $fluffless_relative ) {
		wp_delete_file( trailingslashit( wp_upload_dir()['basedir'] ) . $fluffless_relative );
	}
	null === $fluffless_logo_before ? delete_option( 'site_logo' ) : update_option( 'site_logo', $fluffless_logo_before );
	false === $fluffless_notices_before ? delete_transient( Lifecycle::TRANSIENT_UPLOAD_NOTICES ) : set_transient( Lifecycle::TRANSIENT_UPLOAD_NOTICES, $fluffless_notices_before, DAY_IN_SECONDS );
	delete_option( MediaWebp::REPORT );
	wp_set_current_user( 0 );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Médiathèque WebP et quarantaine, {$fluffless_checks} vérifications réussies.\n" );
