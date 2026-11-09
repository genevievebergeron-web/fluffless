<?php
/**
 * Fonctions publiques de Fluffless Suite, pour le thème et les autres extensions.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'fluffless_host_is_green' ) ) {
	/**
	 * L'hébergeur est-il reconnu par la Green Web Foundation, d'après le dernier Bilan ?
	 * Faux tant que rien n'a été vérifié, si la vérification a échoué ou date de plus de 30 jours :
	 * une affirmation énergétique ne s'affiche que sur une preuve récente (loi C-59).
	 */
	function fluffless_host_is_green(): bool {
		/**
		 * Filtre le statut de l'hébergeur (badge conditionnel du pied de page).
		 *
		 * @param bool $is_green Vrai seulement si la Green Web Foundation l'a confirmé.
		 */
		return true === apply_filters( 'fluffless_host_is_green', 'yes' === \Fluffless\Suite\Modules\Audit::host_status() );
	}
}

if ( ! function_exists( 'fluffless_suite_navigation_block' ) ) {
	/**
	 * Bloc Navigation d'un pied de page : le menu partagé du thème (une seule entité wp_navigation,
	 * la même que l'en-tête) quand il est actif, sinon les liens de fluffless_suite_footer_nav().
	 *
	 * @param array<string, mixed> $attrs Attributs du bloc (libellé, mise en page, taille).
	 */
	function fluffless_suite_navigation_block( array $attrs ): string {
		if ( function_exists( 'fluffless_navigation_block' ) ) {
			return fluffless_navigation_block( $attrs );
		}
		return '<!-- wp:navigation ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . " -->\n" . fluffless_suite_footer_nav() . '<!-- /wp:navigation -->';
	}
}

if ( ! function_exists( 'fluffless_suite_footer_nav' ) ) {
	/**
	 * Liens du pied de page : la source unique du thème quand il est actif, sinon un lien vers l'accueil.
	 * La Suite doit fonctionner sans le thème et ne peut pas deviner les adresses de ses pages.
	 */
	function fluffless_suite_footer_nav(): string {
		if ( function_exists( 'fluffless_footer_nav' ) ) {
			return fluffless_footer_nav();
		}
		$attrs = array(
			'label'          => __( 'Home', 'fluffless-suite' ),
			'url'            => home_url( '/' ),
			'kind'           => 'custom',
			'isTopLevelLink' => true,
		);
		return '<!-- wp:navigation-link ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . " /-->\n";
	}
}

/**
 * Texte d'un fragment HTML ramené à une forme comparable (balises retirées, entités décodées,
 * espaces insécables remplacées, espaces compressées).
 *
 * @param string $html Fragment HTML.
 */
function fluffless_suite_comparable_text( string $html ): string {
	$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = str_replace( array( "\u{00A0}", "\u{202F}" ), ' ', $text );
	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Au rendu public, un bloc d'exemple des motifs de la Suite, marqué `fluffless-example`, disparaît
 * tant que son texte est celui d'origine. Si le texte a changé mais que le lien garde l'adresse
 * d'exemple, le texte se publie sans le lien. Même règle que le thème, sans dépendre de lui.
 *
 * @param string               $content Rendu du bloc.
 * @param array<string, mixed> $block   Bloc analysé.
 */
function fluffless_suite_hide_example_content( string $content, array $block ): string {
	$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
	$class = is_string( $attrs['className'] ?? null ) ? $attrs['className'] : '';
	if ( 1 !== preg_match( '/(?:^|\s)fluffless-example(?:\s|$)/', $class ) ) {
		return $content;
	}
	$known = array(
		'Write the sentence you want people to remember as they leave your site.',
		'Your email address',
		'Your phone number',
		'Monday to Friday, 9 a.m. to 5 p.m.',
		__( 'Write the sentence you want people to remember as they leave your site.', 'fluffless-suite' ),
		__( 'Your email address', 'fluffless-suite' ),
		__( 'Your phone number', 'fluffless-suite' ),
		__( 'Monday to Friday, 9 a.m. to 5 p.m.', 'fluffless-suite' ),
	);
	$text  = fluffless_suite_comparable_text( $content );
	if ( '' === $text || in_array( $text, array_map( 'fluffless_suite_comparable_text', $known ), true ) ) {
		return '';
	}
	return (string) preg_replace_callback(
		'#<a\b[^>]*>(.*?)</a>#is',
		static function ( array $found ): string {
			$tags = new WP_HTML_Tag_Processor( $found[0] );
			$href = $tags->next_tag( array( 'tag_name' => 'A' ) ) ? $tags->get_attribute( 'href' ) : null;
			$fake = is_string( $href ) && 1 === preg_match( '#^(?:mailto:[^@]*@(?:[a-z0-9-]+\.)*exemple\.ca|tel:\+?1?5555550123)#i', trim( $href ) );
			return $fake ? $found[1] : $found[0];
		},
		$content
	);
}
add_filter( 'render_block_core/paragraph', 'fluffless_suite_hide_example_content', 11, 2 );
add_filter( 'render_block_core/button', 'fluffless_suite_hide_example_content', 11, 2 );
