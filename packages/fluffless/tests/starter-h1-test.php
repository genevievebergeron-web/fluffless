<?php
/**
 * Tests du site neuf : un seul H1 quand le contenu porte déjà le sien.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/starter-h1-test.php
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( 'fluffless' !== get_template() ) {
	throw new RuntimeException( 'Thème Fluffless inactif : wp theme activate fluffless.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};
$fluffless_created  = array();

// Un seul H1 : le titre de page s'efface quand le contenu a déjà le sien.
$fluffless_title   = static function ( int $post_id ): string {
	$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- le bloc lit l'article courant.
	setup_postdata( $post_id );
	$block = new WP_Block(
		parse_blocks( '<!-- wp:post-title {"level":1} /-->' )[0],
		array(
			'postId'   => $post_id,
			'postType' => 'page',
		)
	);
	$html  = $block->render();
	wp_reset_postdata();
	return $html;
};
$fluffless_with    = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Avec son H1',
		'post_content' => '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Mon accroche</h1><!-- /wp:heading -->',
	)
);
$fluffless_without = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Sans H1',
		'post_content' => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
	)
);
$fluffless_created = array_merge( $fluffless_created, array( $fluffless_with, $fluffless_without ) );
$fluffless_assert( ! str_contains( $fluffless_title( $fluffless_with ), '<h1' ), 'le titre de page doit disparaître quand le contenu a son H1' );
$fluffless_assert( str_contains( $fluffless_title( $fluffless_without ), '<h1' ), 'le titre de page doit rester sans H1 dans le contenu' );

// P18 : le H1 se décide sur les blocs, pas sur le texte brut.
$fluffless_page        = static function ( string $content ) use ( &$fluffless_created ): int {
	$id                  = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Page de test',
			'post_content' => $content,
		)
	);
	$fluffless_created[] = $id;
	return (int) $id;
};
$fluffless_synced      = wp_insert_post(
	array(
		'post_type'    => 'wp_block',
		'post_status'  => 'publish',
		'post_title'   => 'Motif synchronisé avec H1',
		'post_content' => '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Dans le motif</h1><!-- /wp:heading -->',
	)
);
$fluffless_created[]   = $fluffless_synced;
$fluffless_synced_flat = wp_insert_post(
	array(
		'post_type'    => 'wp_block',
		'post_status'  => 'publish',
		'post_title'   => 'Motif synchronisé sans H1',
		'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Sous-titre</h2><!-- /wp:heading -->',
	)
);
$fluffless_created[]   = $fluffless_synced_flat;
$fluffless_cases       = array(
	'un H1 dans un commentaire HTML ne compte pas' => array( '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph --><!-- <h1>ancien titre</h1> -->', true ),
	'un H1 cité dans un bloc Code ne compte pas'   => array( '<!-- wp:code --><pre class="wp-block-code"><code>&lt;h1&gt;Titre&lt;/h1&gt;</code></pre><!-- /wp:code -->', true ),
	'un H2 ne compte pas'                          => array( '<!-- wp:heading --><h2 class="wp-block-heading">Sous-titre</h2><!-- /wp:heading -->', true ),
	'un H1 dans un groupe imbriqué compte'         => array( '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Imbriqué</h1><!-- /wp:heading --></div><!-- /wp:group -->', false ),
	'un H1 dans un bloc HTML personnalisé compte'  => array( '<!-- wp:html --><h1>Titre HTML</h1><!-- /wp:html -->', false ),
	'un H1 dans un motif synchronisé compte'       => array( '<!-- wp:block {"ref":' . $fluffless_synced . '} /-->', false ),
	'un motif synchronisé sans H1 laisse le titre' => array( '<!-- wp:block {"ref":' . $fluffless_synced_flat . '} /-->', true ),
);
foreach ( $fluffless_cases as $fluffless_label => $fluffless_case ) {
	$fluffless_shown = str_contains( $fluffless_title( $fluffless_page( $fluffless_case[0] ) ), '<h1' );
	$fluffless_assert( $fluffless_case[1] === $fluffless_shown, 'P18 : ' . $fluffless_label );
}

// Page protégée par mot de passe : le contenu (et son H1) n'est pas rendu, le titre de la page reste le seul H1.
$fluffless_locked    = wp_insert_post(
	array(
		'post_type'     => 'page',
		'post_status'   => 'publish',
		'post_title'    => 'Page protégée',
		'post_password' => 'secret-du-test',
		'post_content'  => '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Caché derrière le mot de passe</h1><!-- /wp:heading -->',
	)
);
$fluffless_created[] = $fluffless_locked;
$fluffless_assert( str_contains( $fluffless_title( (int) $fluffless_locked ), '<h1' ), 'page protégée : le titre de page doit rester (aucun H1 sinon)' );

// Le résultat est le même à l'affichage suivant (lu une fois par requête et par article).
$fluffless_assert( '' === $fluffless_title( $fluffless_with ) && '' === $fluffless_title( $fluffless_with ), 'le titre reste effacé aux affichages répétés' );


foreach ( $fluffless_created as $fluffless_post_id ) {
	wp_delete_post( (int) $fluffless_post_id, true );
}

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( 'starter-h1 : ' . count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} :\n - " . implode( "\n - ", $fluffless_failures ) );
}
WP_CLI::success( "starter-h1 : {$fluffless_checks} vérifications passées." );
