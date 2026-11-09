<?php
/**
 * Test du rendu serveur du bloc fluffless/icone.
 *
 * Lancer : npm run test:icone
 * (docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/icone-render-test.php)
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;

$fluffless_assert = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'fluffless/icone' ) ) {
	throw new RuntimeException( 'fluffless/icone non enregistré : activer l\'extension (wp plugin activate fluffless-suite).' );
}

// 1. Nom valide, décoratif.
$fluffless_html = do_blocks( '<!-- wp:fluffless/icone {"icon":"leaf","size":"large","textColor":"accent"} /-->' );
$fluffless_assert( str_contains( $fluffless_html, '<svg ' ), 'leaf : SVG absent' );
$fluffless_assert( str_contains( $fluffless_html, 'aria-hidden="true"' ) && str_contains( $fluffless_html, 'focusable="false"' ), 'leaf : aria-hidden/focusable absents' );
$fluffless_assert( str_contains( $fluffless_html, 'stroke="currentColor"' ), 'leaf : stroke currentColor absent' );
$fluffless_assert( str_contains( $fluffless_html, 'is-size-large' ), 'leaf : classe de taille absente' );
$fluffless_assert( str_contains( $fluffless_html, 'has-accent-color' ), 'leaf : classe de couleur de palette absente' );
$fluffless_assert( ! str_contains( $fluffless_html, 'role="img"' ) && ! str_contains( $fluffless_html, '<title' ), 'leaf : role/title présents sur une icône décorative' );

// 2. Libellé : role img + title échappé.
$fluffless_html = do_blocks( '<!-- wp:fluffless/icone {"icon":"phone","label":"Appelez <b>\"nous\"</b> & co"} /-->' );
$fluffless_assert( str_contains( $fluffless_html, 'role="img"' ), 'label : role img absent' );
$fluffless_assert( ! str_contains( $fluffless_html, 'aria-hidden' ), 'label : aria-hidden présent' );
$fluffless_assert( 1 === preg_match( '#<title id="(fluffless-icone-[a-f0-9]{8}(?:-\d+)?)">Appelez &lt;b&gt;&quot;nous&quot;&lt;/b&gt; &amp; co</title>#', $fluffless_html, $fluffless_m ), 'label : title non échappé ou absent : ' . $fluffless_html );
$fluffless_assert( isset( $fluffless_m[1] ) && str_contains( $fluffless_html, 'aria-labelledby="' . $fluffless_m[1] . '"' ), 'label : aria-labelledby ne pointe pas vers le title' );
$fluffless_assert( ! str_contains( $fluffless_html, '<b>' ), 'label : balise non échappée' );

// 3. Taille invalide : repli sur medium.
$fluffless_html = do_blocks( '<!-- wp:fluffless/icone {"icon":"leaf","size":"enorme"} /-->' );
$fluffless_assert( str_contains( $fluffless_html, 'is-size-medium' ), 'taille invalide : pas de repli medium' );

// 4. Noms invalides ou traversée : aucune icône. Le commentaire WP_DEBUG est toléré, jamais le nom brut.
foreach ( array( '../../../wp-config', '..%2Fwp-config', 'Leaf', 'leaf ', 'leaf--x', 'inconnue-xyz', '', str_repeat( 'a', 65 ), '<script>' ) as $fluffless_bad ) {
	$fluffless_html = trim( do_blocks( '<!-- wp:fluffless/icone ' . wp_json_encode( array( 'icon' => $fluffless_bad ) ) . ' /-->' ) );
	$fluffless_assert( ! str_contains( $fluffless_html, '<svg' ), 'invalide « ' . $fluffless_bad . ' » : SVG rendu' );
	$fluffless_assert( '' === $fluffless_html || str_starts_with( $fluffless_html, '<!-- fluffless/icone' ), 'invalide « ' . $fluffless_bad . ' » : sortie inattendue : ' . $fluffless_html );
	$fluffless_assert( ! str_contains( $fluffless_html, '<script>' ) && ! str_contains( $fluffless_html, 'wp-config' ), 'invalide « ' . $fluffless_bad . ' » : nom brut réaffiché' );
}

// 5. Contrôles unitaires de l'assainissement.
$fluffless_assert( 'arrow-up-right' === \Fluffless\Suite\Blocks\Icone::sanitize_name( 'arrow-up-right' ), 'sanitize : nom valide refusé' );
$fluffless_assert( null === \Fluffless\Suite\Blocks\Icone::sanitize_name( array( 'leaf' ) ), 'sanitize : tableau accepté' );
$fluffless_assert( null === \Fluffless\Suite\Blocks\Icone::sanitize_name( "leaf\n" ), 'sanitize : saut de ligne final accepté' );

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}

echo esc_html( 'OK : ' . $fluffless_checks . " contrôles réussis (fluffless/icone).\n" );
