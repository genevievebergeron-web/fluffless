<?php
/**
 * Bloc fluffless/empreinte : dernière mesure d'empreinte du contenu, en HTML statique.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Blocks;

use Fluffless\Suite\Modules\Footprint;

defined( 'ABSPATH' ) || exit;

/**
 * Aucun JS, aucune feuille de style, aucune requête : une phrase et un lien.
 * Rien n'est rendu tant que le contenu n'a pas été mesuré.
 * Vocabulaire encadré (loi C-59) : deux estimations étiquetées (nouvelle visite, visite de retour),
 * méthode et date. Jamais une « fourchette » : ce sont deux scénarios, pas une incertitude.
 */
final class Empreinte {

	/**
	 * Construit le balisage du bloc.
	 *
	 * @param array<string, mixed> $attributes Attributs du bloc.
	 * @param \WP_Block|null       $block      Bloc rendu (contexte postId).
	 */
	public static function render( array $attributes, ?\WP_Block $block = null ): string {
		$context = null === $block ? null : ( $block->context['postId'] ?? null );
		$post_id = is_numeric( $context ) ? (int) $context : (int) get_the_ID();
		$result  = Footprint::stored( $post_id );
		if ( null === $result ) {
			return '';
		}

		$method = is_string( $attributes['methodUrl'] ?? null ) ? esc_url( trim( $attributes['methodUrl'] ), array( 'http', 'https' ) ) : '';
		$method = '' === $method ? Footprint::DEFAULT_METHOD_URL : $method;
		$date   = wp_date( (string) get_option( 'date_format' ), $result['measured'] );

		$text = sprintf(
			/* translators: 1: estimate for a new visit in grams, 2: estimate for a returning visit in grams, 3: rating from A+ to F, 4: date of measurement */
			__( 'SWDM v4 estimate: %1$s g CO₂e (new visit), %2$s g (returning visit) · rating %3$s · measured on %4$s', 'fluffless-suite' ),
			Footprint::format_grams( $result['new'] ),
			Footprint::format_grams( $result['return'] ),
			$result['grade'],
			false === $date ? '' : $date
		);

		$wrapper = null !== $block && null !== \WP_Block_Supports::$block_to_render
			? get_block_wrapper_attributes()
			: 'class="wp-block-fluffless-empreinte"';

		return sprintf(
			'<p %1$s>%2$s · <a href="%3$s">%4$s</a></p>',
			$wrapper,
			esc_html( $text ),
			esc_url( $method ),
			esc_html__( 'Method', 'fluffless-suite' )
		);
	}
}
