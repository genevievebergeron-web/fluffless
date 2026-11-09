<?php
/**
 * Title: Menu
 * Slug: fluffless/menu
 * Categories: fluffless-structure
 * Inserter: no
 * Description: Main menu shared by every header: a flat menu on wide screens and a Menu button that opens a panel under the header on phones. No script.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

/*
 * Les deux blocs référencent le même menu, celui des pieds de page (fluffless_navigation_id()) :
 * une seule modification dans l'éditeur du site le change partout. Chaque lien pointe vers la page
 * du même chemin quand elle existe; une entrée sans page publiée n'est pas affichée.
 */
echo fluffless_navigation_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- balise de bloc dont les attributs sont encodés en JSON.
	array(
		'ariaLabel'       => __( 'Main menu', 'fluffless' ),
		'overlayMenu'     => 'never',
		'showSubmenuIcon' => false,
		'className'       => 'fluffless-nav-desktop',
		'layout'          => array(
			'type'           => 'flex',
			'justifyContent' => 'right',
			'flexWrap'       => 'wrap',
		),
	)
);
?>

<!-- wp:details {"className":"fluffless-nav-mobile"} -->
<details class="wp-block-details fluffless-nav-mobile"><summary><?php esc_html_e( 'Menu', 'fluffless' ); ?></summary>
	<?php
	echo fluffless_navigation_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- balise de bloc dont les attributs sont encodés en JSON.
		array(
			'ariaLabel'       => __( 'Main menu, small screens', 'fluffless' ),
			'overlayMenu'     => 'never',
			'showSubmenuIcon' => false,
			'layout'          => array(
				'type'        => 'flex',
				'orientation' => 'vertical',
			),
		)
	);
	?>
</details>
<!-- /wp:details -->
