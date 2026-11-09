<?php
/**
 * Tests du module Minification.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/minify-test.php
 * Facultatif : FLUFFLESS_MINIFY_PAGES=/chemin/a.html,/chemin/b.html pour vérifier aussi des pages réelles enregistrées.
 *
 * @package Fluffless_Suite
 */

// DOMDocument et DOMNode imposent leurs noms de propriétés (textContent, nodeName, nodeValue).
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

use Fluffless\Suite\Modules\Minify;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Minify::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

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

/**
 * Texte visible et nombre d'éléments d'un document, espaces regroupés : ce que la minification ne doit pas changer.
 *
 * @param string $html Document.
 * @return array{text: string, elements: int, pre: list<string>, attrs: list<string>}
 */
$fluffless_signature = static function ( string $html ): array {
	$doc = new DOMDocument();
	// libxml ignore les balises HTML5 inconnues en émettant des avertissements sans conséquence ici.
	$previous = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOBLANKS | LIBXML_COMPACT );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	$pre = array();
	foreach ( array( 'pre', 'textarea', 'script' ) as $name ) {
		foreach ( $doc->getElementsByTagName( $name ) as $node ) {
			$pre[] = $name . ':' . $node->textContent;
		}
	}
	$attrs = array();
	$nodes = ( new DOMXPath( $doc ) )->query( '//@*' );
	foreach ( false === $nodes ? array() : $nodes as $attr ) {
		$attrs[] = $attr->nodeName . '=' . $attr->nodeValue;
	}
	$body = $doc->getElementsByTagName( 'body' )->item( 0 );
	return array(
		'text'     => trim( (string) preg_replace( '/\s+/', ' ', $body ? $body->textContent : '' ) ),
		'elements' => $doc->getElementsByTagName( '*' )->length,
		'pre'      => $pre,
		'attrs'    => $attrs,
	);
};

$fluffless_page = <<<'HTML'
<!DOCTYPE html>
<html lang="fr-CA">
  <head>
    <meta charset="utf-8" />
    <title>  Essai  </title>
    <!-- commentaire à retirer -->
    <!--[if IE]><p>Vieux navigateur</p><![endif]-->
    <style id="global-styles-inline-css">
      /* styles globaux */
      :root { --wp--preset--color--base : #fff ; }
      @media (min-width: 600px) and (max-width: 900px) {
        .a  >  .b , .c { margin : calc( 1px + 2px ) ; content: "  deux  espaces  " ; background: url( "data:image/svg+xml;utf8,<svg a='1 2'/>" ) ; }
      }
    </style>
    <style type="text/x-template">  garder   tel  quel  </style>
    <script type="application/ld+json">
      {  "name" :  "Essai   JSON"  }
    </script>
  </head>
  <body   class="home  page" >
    <p>Un <strong>mot</strong> et   <em>un autre</em>.</p>
    <pre class="wp-block-code"><code>  ligne 1
    ligne   2
</code></pre>
    <textarea name="t">  texte
      libre  </textarea>
    <div data-wp-context='{"a":"x  y"}' title="a  >  b">
      Fin
    </div>
    <script>
      var s = "  <!-- pas un commentaire -->  ";
    </script>
  </body>
</html>
HTML;

$fluffless_min = Minify::html( $fluffless_page );
$fluffless_assert( is_string( $fluffless_min ), 'html : échec sur la page d\'essai' );
$fluffless_min = (string) $fluffless_min;

$fluffless_before = $fluffless_signature( $fluffless_page );
$fluffless_after  = $fluffless_signature( $fluffless_min );
$fluffless_assert( $fluffless_before['text'] === $fluffless_after['text'], 'html : le texte visible a changé' );
$fluffless_assert( $fluffless_before['elements'] === $fluffless_after['elements'], 'html : nombre d\'éléments différent (HTML cassé ?)' );
$fluffless_assert( $fluffless_before['pre'] === $fluffless_after['pre'], 'html : pre, textarea ou script modifié' );
$fluffless_assert( $fluffless_before['attrs'] === $fluffless_after['attrs'], 'html : une valeur d\'attribut a changé' );
$fluffless_assert( strlen( $fluffless_min ) < strlen( $fluffless_page ), 'html : aucun gain' );

$fluffless_assert( str_contains( $fluffless_min, "<pre class=\"wp-block-code\"><code>  ligne 1\n    ligne   2\n</code></pre>" ), 'pre : contenu non intact' );
$fluffless_assert( str_contains( $fluffless_min, "<textarea name=\"t\">  texte\n      libre  </textarea>" ), 'textarea : contenu non intact' );
$fluffless_assert( str_contains( $fluffless_min, 'var s = "  <!-- pas un commentaire -->  ";' ), 'script : contenu modifié' );
$fluffless_assert( str_contains( $fluffless_min, '{  "name" :  "Essai   JSON"  }' ), 'script JSON : contenu modifié' );
$fluffless_assert( str_contains( $fluffless_min, '<style type="text/x-template">  garder   tel  quel  </style>' ), 'style non CSS : modifié' );
$fluffless_assert( ! str_contains( $fluffless_min, 'commentaire à retirer' ), 'commentaire HTML : non retiré' );
$fluffless_assert( str_contains( $fluffless_min, '<!--[if IE]><p>Vieux navigateur</p><![endif]-->' ), 'commentaire conditionnel : retiré' );
$fluffless_assert( str_contains( $fluffless_min, 'Un <strong>mot</strong> et <em>un autre</em>.' ), 'texte : espaces entre éléments en ligne perdus' );
$fluffless_assert( str_contains( $fluffless_min, '<body class="home  page">' ), 'balise : espaces hors guillemets non regroupés, ou valeur entre guillemets modifiée' );
$fluffless_assert( str_starts_with( $fluffless_min, '<!DOCTYPE html><html lang="fr-CA"><head><meta charset="utf-8" /><title>' ), 'tête : espaces non retirés' );

// CSS en ligne : chaînes, url() et calc() gardés ; commentaires et espaces retirés.
$fluffless_css = Minify::css( ".a  >  .b , .c { margin : calc( 1px + 2px ) ; content: \"  x  \" ; }\n/* c */ @media (min-width: 600px) and (max-width: 900px) { .d { color : red ; } }" );
$fluffless_assert( '.a > .b,.c{margin :calc( 1px + 2px );content:"  x  "}@media (min-width:600px) and (max-width:900px){.d{color :red}}' === $fluffless_css, 'css : sortie inattendue : ' . (string) $fluffless_css );
$fluffless_assert( str_contains( $fluffless_min, 'url( "data:image/svg+xml;utf8,<svg a=\'1 2\'/>" )' ), 'css : url() modifiée' );
$fluffless_assert( str_contains( $fluffless_min, '--wp--preset--color--base :#fff}' ), 'css : styles globaux non minifiés' );

// Tampon : un morceau partiel (après ob_flush d'un tiers) ou un contenu non HTML part intact.
$fluffless_assert( Minify::buffer( $fluffless_page, PHP_OUTPUT_HANDLER_START ) === $fluffless_page, 'buffer : morceau partiel modifié' );
$fluffless_assert( '{"a":  1}' === Minify::buffer( '{"a":  1}' ), 'buffer : JSON modifié' );
delete_transient( Minify::TRANSIENT );
$fluffless_assert( Minify::buffer( $fluffless_page ) === $fluffless_min, 'buffer : page complète non minifiée' );
$fluffless_measure = Minify::last_measure();
$fluffless_assert( null !== $fluffless_measure && $fluffless_measure['gzip_min'] <= $fluffless_measure['gzip_raw'], 'mesure : absente ou incohérente' );
$fluffless_assert( str_contains( Minify::status(), 'compressed bytes' ), 'status : gain compressé non affiché' );
delete_transient( Minify::TRANSIENT );

// Pages réelles enregistrées (facultatif) : même signature avant et après.
$fluffless_pages = array_filter( explode( ',', (string) getenv( 'FLUFFLESS_MINIFY_PAGES' ) ) );
foreach ( $fluffless_pages as $fluffless_file ) {
	$fluffless_raw = (string) file_get_contents( $fluffless_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local de test.
	$fluffless_out = (string) Minify::html( $fluffless_raw );
	$fluffless_a   = $fluffless_signature( $fluffless_raw );
	$fluffless_b   = $fluffless_signature( $fluffless_out );
	$fluffless_assert( $fluffless_a === $fluffless_b, 'page réelle : signature différente pour ' . $fluffless_file );
	$fluffless_m = Minify::measure( $fluffless_raw, $fluffless_out, basename( $fluffless_file ) );
	echo esc_html( (string) wp_json_encode( $fluffless_m ) ) . "\n";
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Minification, {$fluffless_checks} vérifications réussies.\n" );
