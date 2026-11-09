<?php
/**
 * Chargeur de modules : n'instancie que les modules actifs.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

defined( 'ABSPATH' ) || exit;

use Fluffless\Suite\Admin\Transfer;
use Fluffless\Suite\Modules\Audit;
use Fluffless\Suite\Modules\Canvas;
use Fluffless\Suite\Modules\Conditions;
use Fluffless\Suite\Modules\Fields;
use Fluffless\Suite\Modules\Kits;
use Fluffless\Suite\Modules\Loops;
use Fluffless\Suite\Modules\Editor;
use Fluffless\Suite\Modules\Carbon;
use Fluffless\Suite\Modules\CarbonGlance;
use Fluffless\Suite\Modules\Cleanup;
use Fluffless\Suite\Modules\Events;
use Fluffless\Suite\Modules\Expression;
use Fluffless\Suite\Modules\Fonts;
use Fluffless\Suite\Modules\Footer;
use Fluffless\Suite\Modules\Footprint;
use Fluffless\Suite\Modules\Housekeeping;
use Fluffless\Suite\Modules\Images;
use Fluffless\Suite\Modules\Courses;
use Fluffless\Suite\Modules\Dashboard;
use Fluffless\Suite\Modules\Form;
use Fluffless\Suite\Modules\Forms;
use Fluffless\Suite\Modules\Mail;
use Fluffless\Suite\Modules\Migration;
use Fluffless\Suite\Modules\Newsletter;
use Fluffless\Suite\Modules\Maintenance;
use Fluffless\Suite\Modules\Security;
use Fluffless\Suite\Modules\Status;
use Fluffless\Suite\Modules\Review;
use Fluffless\Suite\Modules\Reviewer;
use Fluffless\Suite\Modules\Styles;
use Fluffless\Suite\Modules\MediaWebp;
use Fluffless\Suite\Modules\Minify;
use Fluffless\Suite\Modules\PruneCss;
use Fluffless\Suite\Modules\Palette;
use Fluffless\Suite\Modules\Pexels;
use Fluffless\Suite\Modules\Quality;
use Fluffless\Suite\Modules\Seo;
use Fluffless\Suite\Modules\Shape;
use Fluffless\Suite\Modules\Shop;
use Fluffless\Suite\Modules\Stats;
use Fluffless\Suite\Modules\Updates;
use Fluffless\Suite\Modules\Utm;
use Fluffless\Suite\Modules\Visibility;

/**
 * Crochets publics :
 * - filtre  fluffless_modules : array<id, class-string<Module>>, pour ajouter ou retirer un module ;
 * - action  fluffless_loaded  : list<id> des modules actifs, une fois tous enregistrés.
 */
final class Loader {

	/**
	 * LA liste des modules de l'extension : classe => identifiants des modules requis.
	 * L'ordre est celui de la page de réglages. C'est le seul endroit à modifier pour
	 * ajouter un module (un tiers passe par le filtre fluffless_modules).
	 *
	 * Un module requis inactif ne bloque pas le dépendant : celui-ci est enregistré quand
	 * même, doit se dégrader (Loader::is_active() pour le savoir) et l'administration
	 * affiche ce qui manque. Déclarer ici toute classe d'un autre module appelée par le code.
	 */
	private const MODULES = array(
		// Noyau.
		Cleanup::class      => array(),
		Images::class       => array(),
		Visibility::class   => array(),
		Http::class         => array(),
		// Vie privée.
		Seo::class          => array(),
		Stats::class        => array(),
		// Mesure. Footprint et Footer lisent le statut d'hébergeur du Bilan (Audit::host_status()).
		Audit::class        => array(),
		Footprint::class    => array( 'audit' ),
		Housekeeping::class => array(),
		// Footer : badge d'hébergement (Bilan) et choix d'appareil par élément (Visibilité, demandée
		// seulement quand un élément vise un appareil : Footer::needs()).
		Footer::class       => array( 'audit', 'visibility' ),
		// Présentation : forme, densité, déverrouillage des sections et mode Studio ; palette du site.
		Shape::class        => array(),
		Canvas::class       => array( 'shape' ),
		Palette::class      => array(),
		Editor::class       => array(),
		Kits::class         => array(),
		Migration::class    => array(),
		Conditions::class   => array(),
		Loops::class        => array(),
		Fields::class       => array(),
		// Expressivité : Vague, Récit défilant et Pop-up (phase 3).
		Expression::class   => array(),
		Styles::class       => array(),
		Review::class       => array(),
		Reviewer::class     => array(),
		// Qualité du contenu : panneau de l'éditeur (titres, textes de remplacement, liens vagues).
		Quality::class      => array(),
		// Guide du tableau de bord : premières étapes et gestes courants à la connexion.
		Dashboard::class    => array(),
		// Performance et médias, tous désactivés par défaut.
		Minify::class       => array(),
		PruneCss::class     => array(),
		MediaWebp::class    => array(),
		Pexels::class       => array(),
		Fonts::class        => array(),
		// Entretien : mises à jour signées du thème et des extensions Fluffless.
		Updates::class      => array(),
		// Commerce : ne fait rien tant que WooCommerce est absent.
		Shop::class         => array(),
		// Cours sobres : Sensei LMS reste sur les pages de cours.
		Courses::class      => array(),
		// Formulaires sobres : les extensions de formulaires établies restent sur les pages qui en ont.
		Forms::class        => array(),
		// Courriel sortant : SMTP par le site, conseillé au module Formulaire.
		Mail::class         => array(),
		// Formulaire : bloc natif, envoi par wp_mail() ; le SMTP du module Courriel sortant est conseillé, pas requis.
		Form::class         => array(),
		// Infolettre : inscrit dans l'outil de la cliente, depuis un bloc Formulaire.
		Newsletter::class   => array( 'form' ),
		// Événements : type de contenu daté, flux .ics, JSON-LD Event et bloc Calendrier, sans JS sur le site.
		Events::class       => array(),
		Carbon::class       => array(
			'stats',
			'footprint',
		),
		// Aperçu carbone : grammes d'Empreinte, visites et totaux du Rapport carbone quand il est actif.
		CarbonGlance::class => array( 'footprint' ),
		// Liens de campagne : construit des adresses UTM dans l'administration, rien sur le site.
		Utm::class          => array(),
		Maintenance::class  => array(),
		Security::class     => array(),
		// État du site : route REST protégée par clé, lit le Bilan, la sauvegarde déclarée et le journal.
		Status::class       => array(),
		// Modules à venir :
		// une ligne chacun ici, « Module::class => array( 'id-requis' ), », dans le groupe qui
		// lui correspond, plus son use en tête de fichier.
	);

	/**
	 * Liste filtrée et validée, calculée une fois par requête.
	 *
	 * @var array<string, class-string<Module>>|null
	 */
	private static ?array $modules = null;

	/**
	 * Modules actifs de la requête.
	 *
	 * @var list<string>
	 */
	private static array $active = array();

	/**
	 * Dépendances manquantes des modules actifs : id => ids requis inactifs.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $unmet = array();

	/**
	 * Modules connus, actifs ou non. Une entrée du filtre est ignorée si sa classe
	 * n'implémente pas Module ou si sa clé ne correspond pas à son id().
	 *
	 * @return array<string, class-string<Module>>
	 */
	public static function modules(): array {
		if ( null !== self::$modules ) {
			return self::$modules;
		}
		/**
		 * Filtre la liste des modules de Fluffless Suite.
		 *
		 * @param array<string, class-string<Module>> $modules Identifiant => classe.
		 */
		self::$modules = self::validate( apply_filters( 'fluffless_modules', self::defaults() ) );
		return self::$modules;
	}

	/**
	 * Liste de base, avant le filtre : identifiant => classe.
	 *
	 * @return array<string, class-string<Module>>
	 */
	public static function defaults(): array {
		$modules = array();
		foreach ( array_keys( self::MODULES ) as $class ) {
			$modules[ $class::id() ] = $class;
		}
		return $modules;
	}

	/**
	 * Identifiants des modules requis par un module de la liste de base ; vide pour un module tiers.
	 *
	 * @param string $id Identifiant du module.
	 * @return list<string>
	 */
	public static function requires( string $id ): array {
		$class = self::defaults()[ $id ] ?? null;
		return null === $class ? array() : self::MODULES[ $class ];
	}

	/**
	 * Garde les entrées valides du filtre ; un tiers peut y renvoyer n'importe quoi.
	 *
	 * @param mixed $filtered Valeur rendue par le filtre fluffless_modules.
	 * @return array<string, class-string<Module>>
	 */
	private static function validate( $filtered ): array {
		$valid = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $id => $class ) {
			if ( is_string( $id ) && is_string( $class ) && is_a( $class, Module::class, true ) && $class::id() === $id ) {
				$valid[ $id ] = $class;
			} else {
				_doing_it_wrong( __METHOD__, esc_html( 'fluffless_modules : entrée ignorée (' . ( is_string( $id ) ? $id : gettype( $id ) ) . '), classe absente, sans l\'interface Module ou id() différent de la clé.' ), '0.1.0' );
			}
		}
		return $valid;
	}

	/** Point d'entrée, sur plugins_loaded. */
	public static function boot(): void {
		self::$active = self::boot_modules( self::modules() );
		PersonalData::hook();
		if ( array() !== self::$unmet ) {
			add_action( 'admin_notices', array( self::class, 'notice_unmet' ) );
			add_action( 'admin_post_' . self::DISMISS_ACTION, array( self::class, 'dismiss_action' ) );
		}
		/**
		 * Tous les modules actifs sont enregistrés.
		 *
		 * @param list<string> $active Identifiants des modules actifs.
		 */
		do_action( 'fluffless_loaded', self::$active );

		// Module Infolettre éteint après un échec : le courriel déjà prévu part quand même, puis plus rien n'est gardé.
		if ( ! in_array( Newsletter::id(), self::$active, true ) ) {
			add_action( Lifecycle::CRON_NEWSLETTER_DIGEST, array( Newsletter::class, 'send_digest' ) );
		}
	}

	/**
	 * Instancie et enregistre les modules actifs de la liste donnée, puis relève
	 * pour chacun les modules requis qui ne sont pas actifs.
	 *
	 * @param array<string, class-string<Module>> $modules Identifiant => classe.
	 * @return list<string> Identifiants enregistrés.
	 */
	public static function boot_modules( array $modules ): array {
		$active = array();
		foreach ( $modules as $id => $class ) {
			if ( Options::is_enabled( $id ) ) {
				( new $class() )->register();
				$active[] = $id;
			}
		}
		self::$unmet = array();
		foreach ( $active as $id ) {
			$class   = $modules[ $id ];
			$missing = array_values(
				array_filter(
					array_diff( self::requires( $id ), $active ),
					static fn( string $required ): bool => ! method_exists( $class, 'needs' ) || $class::needs( $required )
				)
			);
			if ( array() !== $missing ) {
				self::$unmet[ $id ] = $missing;
			}
		}
		return $active;
	}

	/**
	 * Modules actifs de la requête courante.
	 *
	 * @return list<string>
	 */
	public static function active(): array {
		return self::$active;
	}

	/**
	 * Le module est-il actif dans la requête courante ? À tester avant d'utiliser un module requis.
	 *
	 * @param string $id Identifiant du module.
	 */
	public static function is_active( string $id ): bool {
		return in_array( $id, self::$active, true );
	}

	/**
	 * Modules actifs dont un module requis est inactif : id => ids manquants.
	 *
	 * @return array<string, list<string>>
	 */
	public static function unmet(): array {
		return self::$unmet;
	}

	/** Action admin-post qui ferme l'avis de mode réduit pour la personne connectée. */
	public const DISMISS_ACTION = 'fluffless_dismiss_unmet';

	/** Méta d'utilisateur : empreinte des manques déjà fermés. */
	private const DISMISS_META = 'fluffless_unmet_dismissed';

	/** Empreinte de l'ensemble des modules en mode réduit : un manque nouveau rouvre l'avis. */
	private static function unmet_signature(): string {
		$unmet = self::$unmet;
		ksort( $unmet );
		return md5( (string) wp_json_encode( $unmet ) );
	}

	/**
	 * Retient que cette personne a fermé l'avis pour les manques actuels.
	 *
	 * @param int $user_id Identifiant de l'utilisateur.
	 */
	public static function remember_dismissal( int $user_id ): void {
		update_user_meta( $user_id, self::DISMISS_META, self::unmet_signature() );
	}

	/** Fermeture de l'avis (admin-post) : mémorisée, puis retour à l'écran d'où la personne vient. */
	public static function dismiss_action(): void {
		check_admin_referer( self::DISMISS_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fluffless-suite' ), '', array( 'response' => 403 ) );
		}
		self::remember_dismissal( get_current_user_id() );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/** Avis d'administration : un seul pour tous les modules en mode réduit, fermable et retenu par utilisateur. */
	public static function notice_unmet(): void {
		if ( ! current_user_can( 'manage_options' ) || array() === self::$unmet || Transfer::has_result() ) {
			return;
		}
		if ( self::unmet_signature() === get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}
		$modules = self::modules();
		$items   = '';
		foreach ( self::$unmet as $id => $missing ) {
			if ( ! isset( $modules[ $id ] ) ) {
				continue;
			}
			$labels = array();
			foreach ( $missing as $required ) {
				$labels[] = isset( $modules[ $required ] ) ? $modules[ $required ]::label() : $required;
			}
			$items .= '<li>' . esc_html(
				sprintf(
					/* translators: 1: module name, 2: comma-separated names of the turned-off modules it relies on. */
					__( '%1$s needs %2$s.', 'fluffless-suite' ),
					$modules[ $id ]::label(),
					implode( ', ', $labels )
				)
			) . '</li>';
		}
		if ( '' === $items ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );
		printf(
			'<div class="notice notice-warning"><p>%1$s</p><ul>%2$s</ul><p><a href="%3$s">%4$s</a> <a href="%5$s">%6$s</a></p></div>',
			esc_html__( 'Some modules run in reduced mode because a module they rely on is turned off:', 'fluffless-suite' ),
			$items, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- éléments échappés ci-dessus.
			esc_url( admin_url( 'admin.php?page=fluffless-settings' ) ),
			esc_html__( 'Open the module settings', 'fluffless-suite' ),
			esc_url( $dismiss ),
			esc_html__( 'Hide this notice', 'fluffless-suite' )
		);
	}

	/** Oublie la liste calculée (tests, ou filtre ajouté après coup). */
	public static function reset(): void {
		self::$modules = null;
		self::$unmet   = array();
		Options::flush();
	}
}
