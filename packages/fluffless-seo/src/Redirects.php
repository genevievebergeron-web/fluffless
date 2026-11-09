<?php
/**
 * Applique les redirections et note les adresses introuvables.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Par défaut, seules les requêtes qui mèneraient à une page introuvable consultent la table :
 * les autres pages ne paient aucune requête. L'option « partout » ajoute une requête par page.
 */
final class Redirects {

	/** Pose le crochet. */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'handle' ), 1 );
	}

	/** Point d'entrée sur template_redirect. */
	public static function handle(): void {
		if ( ! in_array( self::method(), array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$decision = self::decide( self::request_path(), is_404() );
		if ( null === $decision ) {
			return;
		}
		if ( 410 === $decision['code'] ) {
			global $wp_query;
			// Gabarit 404 du thème, servi avec le statut 410 (contenu retiré pour de bon).
			if ( $wp_query instanceof \WP_Query ) {
				$wp_query->set_404();
			}
			status_header( 410 );
			nocache_headers();
			return;
		}
		if ( wp_redirect( $decision['target'], $decision['code'], 'Fluffless SEO' ) ) { // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- cible choisie par une administratrice, validée à l'ajout.
			exit;
		}
	}

	/**
	 * Décision pour un chemin : redirection, 410, ou null (rien à faire). Note l'adresse
	 * introuvable au passage. Séparée de handle() pour être testée sans sortie HTTP.
	 *
	 * @param string $path   Chemin normalisé.
	 * @param bool   $is_404 La page demandée est introuvable.
	 * @return array{code: int, target: string}|null
	 */
	public static function decide( string $path, bool $is_404 ): ?array {
		if ( Settings::on( 'redirects' ) && ( $is_404 || Settings::on( 'redirect_everywhere' ) ) ) {
			$rule = Store::match( $path );
			if ( null !== $rule ) {
				Store::hit( $rule['id'] );
				return array(
					'code'   => $rule['code'],
					'target' => str_starts_with( $rule['target'], '/' ) ? home_url( $rule['target'] ) : $rule['target'],
				);
			}
		}
		if ( $is_404 && Settings::on( 'log404' ) ) {
			Store::log_404( $path );
		}
		return null;
	}

	/** Chemin de la requête, normalisé comme les sources de règles. */
	public static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- lu avec repli.
		return Store::normalize_path( $uri );
	}

	/** Méthode HTTP de la requête. */
	private static function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}
}
