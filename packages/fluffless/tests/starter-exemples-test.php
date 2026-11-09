<?php
/**
 * Tests du site neuf : les coordonnées et la phrase d'exemple des pieds de page sont enregistrées
 * dans le gabarit (donc modifiables dans l'éditeur du site) mais ne se publient que modifiées.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/starter-exemples-test.php
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

$fluffless_sentence = 'Write the sentence you want people to remember as they leave your site.';
$fluffless_para     = static fn ( string $inner, string $marker = 'fluffless-example' ): string => '<!-- wp:paragraph {"className":"' . $marker . '"} --><p class="' . $marker . '">' . $inner . '</p><!-- /wp:paragraph -->';

// 1. Le gabarit enregistré (ce que l'éditeur du site relit et réécrit) contient les exemples marqués.
foreach ( array( 'fluffless/pied-de-page', 'fluffless/pied-de-page-compact' ) as $fluffless_slug ) {
	$fluffless_pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $fluffless_slug );
	$fluffless_content = is_array( $fluffless_pattern ) ? (string) $fluffless_pattern['content'] : '';
	$fluffless_assert( str_contains( $fluffless_content, 'exemple.ca' ) && str_contains( $fluffless_content, '5555550123' ), "{$fluffless_slug} : les exemples doivent exister dans le gabarit (P15)" );
	$fluffless_assert( str_contains( $fluffless_content, '"className":"fluffless-example' ), "{$fluffless_slug} : repère fluffless-example absent du gabarit" );
	$fluffless_assert( 0 === preg_match( '/is_admin|REST_REQUEST/', (string) file_get_contents( get_theme_file_path( 'patterns/' . substr( $fluffless_slug, strlen( 'fluffless/' ) ) . '.php' ) ) ), "{$fluffless_slug} : masquage par le contexte d'exécution" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	// 2. Rendu public du gabarit tel qu'enregistré : aucun exemple.
	$fluffless_html = do_blocks( $fluffless_content );
	$fluffless_assert( ! str_contains( $fluffless_html, 'exemple.ca' ) && ! str_contains( $fluffless_html, '5555550123' ), "{$fluffless_slug} : coordonnée factice publiée depuis le gabarit enregistré" );
	$fluffless_assert( ! str_contains( $fluffless_html, $fluffless_sentence ) && ! str_contains( $fluffless_html, 'Monday to Friday' ), "{$fluffless_slug} : phrase ou heures d'exemple publiées depuis le gabarit enregistré" );
}

// 3. Texte inchangé sous ses variantes d'enregistrement : entités, espaces insécables, espaces en trop.
$fluffless_variants = array(
	'apostrophes et entités' => 'Write the sentence you want people to remember as they leave your site.&nbsp;',
	'espaces compressées'    => "  Write  the sentence\n you want people to remember as they leave your site.  ",
	'insécable étroite'      => "Write the sentence you want people to remember as they leave your site.\u{202F}",
	'balises ajoutées'       => '<strong>Write the sentence</strong> you want people to remember as they leave your site.',
);
foreach ( $fluffless_variants as $fluffless_label => $fluffless_text ) {
	$fluffless_assert( '' === trim( do_blocks( $fluffless_para( $fluffless_text ) ) ), "texte inchangé ({$fluffless_label}) : doit rester masqué" );
}
$fluffless_fr = "Écrivez la phrase que vous voulez qu\u{2019}on retienne en quittant votre site\u{202F}:";
add_filter(
	'gettext',
	static function ( $translated, $text, $domain ) use ( $fluffless_sentence, $fluffless_fr ) {
		return ( $text === $fluffless_sentence && 'fluffless' === $domain ) ? $fluffless_fr : $translated;
	},
	10,
	3
);
$fluffless_assert( '' === trim( do_blocks( $fluffless_para( 'Écrivez la phrase que vous voulez qu&#8217;on retienne en quittant votre site&nbsp;:' ) ) ), 'texte traduit inchangé (entités, insécable) : doit rester masqué' );
$fluffless_assert( '' === trim( do_blocks( $fluffless_para( $fluffless_fr ) ) ), 'texte traduit tel quel : doit rester masqué' );

// 4. Texte modifié : publié, classe conservée.
$fluffless_mine = do_blocks( $fluffless_para( 'Notre phrase à nous.' ) );
$fluffless_assert( str_contains( $fluffless_mine, 'Notre phrase à nous.' ) && str_contains( $fluffless_mine, 'fluffless-example' ), 'texte modifié : doit se publier avec sa classe' );
$fluffless_assert( str_contains( do_blocks( $fluffless_para( '<a href="mailto:hello@vraie-entreprise.ca">hello@vraie-entreprise.ca</a>' ) ), 'mailto:hello@vraie-entreprise.ca' ), 'courriel remplacé : doit se publier avec son lien' );
// Le texte change mais l'adresse d'exemple reste dans le lien : le texte se publie sans le lien factice.
$fluffless_half = do_blocks( $fluffless_para( '<a href="mailto:bonjour@exemple.ca">hello@vraie-entreprise.ca</a>' ) );
$fluffless_assert( str_contains( $fluffless_half, 'hello@vraie-entreprise.ca' ) && ! str_contains( $fluffless_half, 'exemple.ca' ), 'texte changé, lien factice resté : lien retiré, texte publié' );
$fluffless_half = do_blocks( $fluffless_para( '<a href="tel:+15555550123">514 555 0000</a>' ) );
$fluffless_assert( str_contains( $fluffless_half, '514 555 0000' ) && ! str_contains( $fluffless_half, '5555550123' ), 'téléphone changé, lien factice resté : lien retiré' );
// Un paragraphe sans repère n'est jamais touché, même avec le texte d'exemple.
$fluffless_assert( str_contains( do_blocks( $fluffless_para( $fluffless_sentence, 'sans-repere' ) ), 'Write the sentence' ), 'paragraphe sans repère jamais touché' );

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( 'starter-exemples : ' . count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} :\n - " . implode( "\n - ", $fluffless_failures ) );
}
WP_CLI::success( "starter-exemples : {$fluffless_checks} vérifications passées." );
