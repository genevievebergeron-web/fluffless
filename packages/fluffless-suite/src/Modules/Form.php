<?php
/**
 * Module Formulaire : un bloc natif, un champ par bloc enfant, envoi par wp_mail(), sans JavaScript
 * (sauf la preuve de travail, en option).
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;
use Fluffless\Suite\Form\Altcha;
use WP_Block;
use WP_Post;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Bloc fluffless/formulaire (parent) et fluffless/champ (un champ par bloc).
 *
 * Fonctionnement, sans JavaScript côté public :
 * - Le parent rend un <form method="post"> vers la page courante ; les champs se rendent eux-mêmes
 *   (contexte de bloc fluffless/formId) et le parent relit sa définition dans les blocs enfants.
 * - La définition (destinataires, sujet, champs) est mémorisée côté serveur dans l'option
 *   fluffless_forms, sous une clé « identifiant + empreinte du contenu ». Le formulaire posté ne
 *   porte que cette clé : rien de la configuration ne transite par le navigateur, ni les adresses.
 * - Le POST est traité sur template_redirect : erreurs réaffichées dans la page, champ par champ,
 *   avec un sommaire lié aux champs ; succès redirigé (PRG) vers la même page avec ?fluffless-sent.
 * - Anti-pourriel sans tiers : pot de miel, jeton signé qui date le rendu (délai minimal), plafond
 *   par adresse sur 10 minutes, l'adresse étant hachée avec un sel du jour et jamais conservée.
 * - Pas de nonce : le formulaire doit rester valide dans une page mise en cache plusieurs jours.
 *   Le jeton signé remplit ce rôle et n'expire qu'après TOKEN_MAX_AGE.
 * - Au choix (réglage « altcha », éteint par défaut) : preuve de travail au format ALTCHA, calculée
 *   par un petit script ; seule exception au « zéro JavaScript », voir Fluffless\Suite\Form\Altcha.
 *   Exigée des seuls formulaires rendus après l'activation (option ALTCHA_SINCE) : une page en cache
 *   d'avant, sans le script, reste envoyable jusqu'à l'échéance de son jeton (TOKEN_MAX_AGE).
 * - Destinataires selon une réponse : une liste, un choix ou des cases désignés dans le bloc, et des
 *   adresses par option ; une option choisie sans adresse ajoute les destinataires du formulaire.
 * - Rien n'est conservé en base par défaut. Un formulaire qui coche « garder les réponses » les
 *   enregistre dans un type de contenu privé (fluffless_response), purgé après keep_days jours à
 *   chaque nouvelle réponse et à chaque visite du panneau ; export CSV et suppression depuis les réglages.
 *   La réponse est gardée avant l'envoi du courriel, marquée « non envoyée » jusqu'à ce que l'envoi
 *   réussisse : un échec (ou un envoi qui plante) la laisse marquée dans le panneau et l'export, et
 *   la personne voit l'erreur. Si elle renvoie, la réponse renvoyée est gardée à part.
 *
 * @phpstan-type Field array{name: string, type: string, label: string, required: bool, placeholder: string, autocomplete: string, help: string, options: list<string>, value: string, when: string, is: string, step: int}
 * @phpstan-type Definition array{key: string, id: string, recipients: list<string>, route_field: string, routes: array<string, list<string>>, subject: string, confirm: bool, keep: bool, keep_days?: int, success: string, submit: string, newsletter: bool, newsletter_list: string, newsletter_consent: string, steps: list<string>, fields: list<Field>}
 * @phpstan-type Stored array{id: int, time: int, form: string, fields: list<array{name: string, label: string}>, values: array<string, string|list<string>>, unsent: bool}
 * @phpstan-type Summary array{form: string, subject: string, count: int, unsent: int, first: int, last: int}
 * @phpstan-type Result array{ok: bool, silent: bool, id: string, step: int, errors: array<string, string>, values: array<string, string|list<string>>, message: string}
 */
final class Form implements Module {

	public const FORM  = 'fluffless/formulaire';
	public const FIELD = 'fluffless/champ';
	public const STEP  = 'fluffless/etape';
	public const STYLE = 'fluffless-formulaire';

	public const OPTION = Lifecycle::OPTION_FORMS;

	/** Réponses conservées : type de contenu privé, sans interface ; une méta porte l'identifiant du formulaire. */
	public const POST_TYPE   = Lifecycle::POST_TYPE_RESPONSE;
	public const META_FORM   = '_fluffless_form';
	public const META_FIELDS = '_fluffless_fields';
	public const META_VALUES = '_fluffless_values';
	/** Durée de conservation propre à un formulaire (jours); absente, le réglage du module vaut. */
	public const META_KEEP_DAYS = '_fluffless_keep_days';
	public const KEEP_DAYS_MAX  = 365;

	/** Marque d'une réponse gardée dont le courriel au site n'est pas parti. */
	public const META_UNSENT = '_fluffless_unsent';

	/** Tâche quotidienne : les réponses échues s'effacent même sans nouvel envoi ni visite du panneau (C3-1-03). */
	public const CRON = Lifecycle::CRON_FORM_PURGE;

	/** Actions admin-post : export CSV et suppression des réponses d'un formulaire. */
	public const ACTION_EXPORT = 'fluffless_form_export';
	public const ACTION_DELETE = 'fluffless_form_delete';

	/** Champ du nonce de « Tout supprimer » (distinct du _wpnonce des réglages). */
	public const DELETE_NONCE = 'fl_delete_nonce';

	/** Identifiant du formulaire POST de « Tout supprimer ». */
	public const DELETE_FORM_ID = 'fl-form-delete';

	/** Lots de MAX_EXPORT effacés au plus par clic. */
	public const DELETE_BATCHES = 20;
	public const MAX_EXPORT     = 5000;

	/** Types de champ, dans l'ordre proposé à l'éditeur. */
	public const TYPES = array( 'text', 'email', 'phone', 'number', 'date', 'select', 'checkboxes', 'radio', 'textarea', 'consent', 'hidden' );

	/** Types qui portent une liste d'options. */
	public const CHOICE_TYPES = array( 'select', 'checkboxes', 'radio' );

	/** Types rendus par un <input> texte (valeur et espace réservé). */
	public const INPUT_TYPES = array(
		'text'   => 'text',
		'email'  => 'email',
		'phone'  => 'tel',
		'number' => 'number',
		'date'   => 'date',
	);

	/** Jetons autocomplete acceptés (WCAG 1.3.5), à l'exclusion de tout autre : liste blanche. */
	public const AUTOCOMPLETE_TOKENS = array(
		'name',
		'honorific-prefix',
		'given-name',
		'additional-name',
		'family-name',
		'nickname',
		'organization-title',
		'organization',
		'email',
		'tel',
		'tel-national',
		'street-address',
		'address-line1',
		'address-line2',
		'address-level1',
		'address-level2',
		'postal-code',
		'country',
		'country-name',
		'bday',
		'url',
	);

	public const MAX_FORMS      = 100;
	public const MAX_FIELDS     = 40;
	public const MAX_OPTIONS    = 50;
	public const MAX_RECIPIENTS = 10;

	/** Instant d'activation de la preuve de travail : les jetons plus anciens en sont dispensés. */
	public const ALTCHA_SINCE = Lifecycle::OPTION_ALTCHA_SINCE;

	/** Résultat mémorisé (une heure) de l'essai de la route du défi, vue d'un visiteur anonyme. */
	public const ALTCHA_PROBE = 'fluffless_form_altcha_probe';

	/** Résultat de l'essai : la route répond, mais toujours le même défi (cache). */
	public const PROBE_CACHED = -1;
	public const LABEL_MAX    = 1000; // Un consentement Loi 25 dépasse souvent 400 caractères.
	public const SUBJECT_MAX  = 200;
	public const HELP_MAX     = 500;
	public const TEXT_MAX     = 2000;
	public const TEXTAREA_MAX = 10000;
	public const SUCCESS_MAX  = 1000;

	/** Noms des champs techniques du formulaire posté. */
	public const FIELD_KEY   = 'fluffless_form';
	public const FIELD_TOKEN = 'fluffless_token';
	public const FIELD_DATA  = 'fl';
	public const FIELD_STEP  = 'fl_step';
	public const FIELD_STATE = 'fl_state';
	public const FIELD_BACK  = 'fl_back';
	public const HONEYPOT    = 'fl_site';
	public const QUERY_SENT  = 'fluffless-sent';

	/** Types de champ qui peuvent commander l'affichage d'un autre champ (condition sans JavaScript). */
	public const CONTROL_TYPES = array( 'select', 'radio', 'checkboxes', 'consent' );
	public const MAX_STEPS     = 10;

	/** Le jeton date le rendu : au-delà, on demande de recharger la page (cache long compris). */
	public const TOKEN_MAX_AGE = 7 * DAY_IN_SECONDS;
	/**
	 * Forme du jeton « temps.signature » (temps à 0 quand les contrôles de temps sont éteints) ;
	 * public pour qu'un outil de comparaison de HTML masque le jeton d'une seule expression.
	 */
	public const TOKEN_PATTERN = '/^(0|\d{9,11})\.([a-f0-9]{20})$/';
	public const RATE_WINDOW   = 10 * MINUTE_IN_SECONDS;

	/** Les envois en échec comptent à part, avec un plafond de ce multiple du plafond des envois réussis (une panne passagère ne bloque pas, un abus si). */
	public const FAIL_FACTOR = 3;
	public const RATE_PREFIX = 'fluffless_form_rate_';

	/**
	 * Copies à la personne par jour pour tout le site : au-delà, le site reçoit toujours le message mais
	 * la copie ne part plus. Le plafond par adresse ne suffit pas, un robot change d'adresse (P6a-01).
	 */
	public const COPY_CAP    = 50;
	public const COPY_PREFIX = 'fluffless_form_copies_';

	/**
	 * État de la requête courante après un POST refusé : réaffiché par le rendu.
	 *
	 * @var Result|null
	 */
	private static ?array $state = null;

	/**
	 * Noms déjà attribués par formulaire pendant le rendu des champs (unicité des id et des noms).
	 *
	 * @var array<string, list<string>>
	 */
	private static array $names = array();

	/**
	 * Étapes déjà rendues par formulaire (le rang d'une étape est son ordre dans le document).
	 *
	 * @var array<string, int>
	 */
	private static array $steps = array();

	/** {@inheritDoc} */
	public static function id(): string {
		return 'form';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Form', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'A native form block, one field per block: validation, spam protection without a third party, email to the site and a copy to the sender. No JavaScript unless the optional proof of work is on, nothing kept by default.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'min_delay' => 3,
			'rate'      => 5,
			'keep_days' => 30,
			'keep_data' => false,
			'altcha'    => false,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		return Sanitize::section( $input, self::fields(), self::defaults() );
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array(
			'min_delay' => array(
				'type'  => 'int',
				'label' => __( 'Minimum seconds before a submission counts', 'fluffless-suite' ),
				'min'   => 0,
				'max'   => 60,
				'help'  => __( 'Robots fill a form in under a second. A person needs a few. 0 disables the check.', 'fluffless-suite' ),
			),
			'rate'      => array(
				'type'  => 'int',
				'label' => __( 'Messages per connection every 10 minutes', 'fluffless-suite' ),
				'min'   => 1,
				'max'   => 100,
				'help'  => __( 'Counted on a daily hash of the address; the address itself is never stored.', 'fluffless-suite' ),
			),
			'keep_days' => array(
				'type'  => 'int',
				'label' => __( 'Days to keep responses', 'fluffless-suite' ),
				'min'   => 1,
				'max'   => 365,
				'help'  => __( 'Only for forms with “Keep responses” on. Older responses are deleted when a new one arrives and when this page opens.', 'fluffless-suite' ),
			),
			'keep_data' => array(
				'type'  => 'bool',
				'label' => __( 'Keep my data when the plugin is deleted', 'fluffless-suite' ),
				'help'  => __( 'Off by default: deleting the plugin from the Plugins screen permanently erases the kept form responses, the settings and the Reviewer role. Turn this on to leave all of it in place, or set FLUFFLESS_KEEP_DATA to true in wp-config.php. Export the responses first if unsure.', 'fluffless-suite' ),
			),
			'altcha'    => array(
				'type'  => 'bool',
				'label' => __( 'Ask the browser for a proof of work (ALTCHA)', 'fluffless-suite' ),
				'help'  => __( 'Filters out robots that do not run JavaScript and slows down the others. Adds a script under 6 KB to pages with a form, which computes for a fraction of a second, with no third party and no cookie. Sending then needs JavaScript and HTTPS.', 'fluffless-suite' ),
			),
		);
	}

	/**
	 * État sous la description : preuve de travail sans HTTPS ou route du défi bloquée, puis
	 * courriel sortant non configuré (le serveur envoie alors par ses propres moyens).
	 */
	public static function status(): string {
		$notes = array();
		if ( self::altcha() ) {
			$code = self::altcha_secure() ? self::altcha_probe() : 0;
			if ( ! self::altcha_secure() ) {
				$notes[] = __( 'The proof of work needs HTTPS: browsers only compute it on a secure page. Until the site uses HTTPS, it is skipped and forms rely on the other checks.', 'fluffless-suite' );
			} elseif ( self::PROBE_CACHED === $code ) {
				$notes[] = __( 'A cache serves the same proof of work challenge to every visitor: exclude the REST API (/wp-json/) from the page cache, otherwise forms refuse most messages.', 'fluffless-suite' );
			} elseif ( $code > 0 && 200 !== $code ) {
				$notes[] = sprintf(
					/* translators: %d: HTTP status code returned by the challenge route. */
					__( 'The proof of work route does not answer visitors (HTTP %d): a security extension may block the REST API. Until it answers, forms refuse every message.', 'fluffless-suite' ),
					$code
				);
			}
			if ( ! self::timing_checks() ) {
				$notes[] = __( 'Time checks are off: pages cached before the proof of work was turned on refuse messages until the page cache is cleared.', 'fluffless-suite' );
			}
		}
		if ( ! ( class_exists( Mail::class ) && Mail::ready() ) ) {
			$notes[] = __( 'Outgoing email is not set up: the server sends messages on its own. If they do not arrive, set up the Outgoing email module.', 'fluffless-suite' );
		}
		return implode( ' ', $notes );
	}

	/**
	 * Code HTTP de la route du défi appelée deux fois sans témoin, comme le script d'un visiteur ;
	 * PROBE_CACHED si les deux réponses portent le même sel (un cache sert le même défi à tous) ;
	 * 0 si le serveur ne peut pas s'appeler lui-même (aucune conclusion). Mémorisé une heure.
	 */
	public static function altcha_probe(): int {
		$code = get_transient( self::ALTCHA_PROBE );
		if ( false === $code ) {
			$salts = array();
			for ( $i = 0; $i < 2; $i++ ) {
				$response = wp_remote_get(
					add_query_arg( '_', wp_generate_password( 8, false ), Altcha::url() ),
					array(
						'timeout'     => 3,
						'redirection' => 0,
					)
				);
				$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
				if ( 200 !== $code ) {
					break;
				}
				$data    = json_decode( wp_remote_retrieve_body( $response ), true );
				$salts[] = is_array( $data ) ? (string) ( $data['salt'] ?? '' ) : '';
			}
			if ( 2 === count( $salts ) && $salts[0] === $salts[1] ) {
				$code = self::PROBE_CACHED;
			}
			set_transient( self::ALTCHA_PROBE, $code, HOUR_IN_SECONDS );
		}
		return (int) $code;
	}

	/**
	 * Note l'instant où la preuve de travail s'allume (et l'oublie quand elle s'éteint) ; essai de
	 * la route refait au prochain affichage des réglages.
	 *
	 * @param mixed $before Réglages avant.
	 * @param mixed $after  Réglages après.
	 */
	public static function altcha_switched( $before, $after ): void {
		$was = is_array( $before ) && is_array( $before[ self::id() ] ?? null ) && true === ( $before[ self::id() ]['altcha'] ?? false );
		$now = is_array( $after ) && is_array( $after[ self::id() ] ?? null ) && true === ( $after[ self::id() ]['altcha'] ?? false );
		if ( $now && ! $was ) {
			update_option( self::ALTCHA_SINCE, time(), false );
		} elseif ( $was && ! $now ) {
			delete_option( self::ALTCHA_SINCE );
		}
		delete_transient( self::ALTCHA_PROBE );
	}

	/**
	 * Instant d'activation de la preuve de travail ; noté maintenant s'il manque (réglage écrit hors
	 * de l'écran, par exemple) : les formulaires déjà rendus restent envoyables.
	 */
	public static function altcha_since(): int {
		$since = (int) get_option( self::ALTCHA_SINCE, 0 );
		if ( $since <= 0 ) {
			$since = time();
			update_option( self::ALTCHA_SINCE, $since, false );
		}
		return $since;
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_action( 'enqueue_block_assets', array( self::class, 'editor_styles' ) );
		add_action( 'template_redirect', array( self::class, 'handle_request' ), 1 );
		add_action( 'admin_notices', array( self::class, 'render_failure_notice' ) );
		add_action( 'admin_init', array( self::class, 'add_privacy_text' ) );
		add_action( 'update_option_' . Options::NAME, array( self::class, 'altcha_switched' ), 10, 2 );
		add_action( self::CRON, array( self::class, 'daily' ) );
		if ( false === wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
		if ( self::altcha() ) {
			add_action( 'rest_api_init', array( Altcha::class, 'register_route' ) );
		}
		if ( is_admin() ) {
			add_action( 'admin_post_' . self::ACTION_EXPORT, array( self::class, 'handle_export' ) );
			add_action( 'admin_post_' . self::ACTION_DELETE, array( self::class, 'handle_delete' ) );
		}
	}

	/** Paragraphe proposé pour la politique de confidentialité. */
	public static function add_privacy_text(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'Fluffless Suite: forms', 'fluffless-suite' ), wpautop( esc_html( self::privacy_text() ) ) );
		}
	}

	/** Texte de la mention : ce qui part, ce qui reste, combien de temps, et comment l'obtenir ou l'effacer. */
	public static function privacy_text(): string {
		return implode(
			' ',
			array(
				__( 'When you send a form on this site, what you enter is emailed to the site. Some forms also email you a copy.', 'fluffless-suite' ),
				sprintf(
					/* translators: 1: default number of days a response is kept, 2: longest duration a form can set, in days. */
					__( 'Some forms also keep a copy of your answers on this site: for %1$d days, or the duration that form sets (%2$d days at most). They are then deleted.', 'fluffless-suite' ),
					max( 1, Options::get_int( self::id(), 'keep_days' ) ),
					self::KEEP_DAYS_MAX
				),
				__( 'Your IP address is not kept with your answers.', 'fluffless-suite' ),
				__( 'You can ask for an export of the answers kept with your email address, or for their deletion.', 'fluffless-suite' ),
			)
		);
	}

	/** Preuve de travail allumée dans les réglages ? */
	public static function altcha(): bool {
		return Options::get_bool( self::id(), 'altcha' );
	}

	/**
	 * Les navigateurs peuvent-ils calculer la preuve ? Web Crypto n'existe que sur une page sûre :
	 * sans HTTPS, la preuve est sautée plutôt que de refuser tous les envois.
	 */
	public static function altcha_secure(): bool {
		/**
		 * Vrai si les pages du site sont servies en HTTPS (contexte sûr où la preuve se calcule).
		 *
		 * @param bool $secure wp_is_using_https() par défaut ; à forcer derrière un mandataire mal réglé.
		 */
		return (bool) apply_filters( 'fluffless_form_altcha_secure', wp_is_using_https() );
	}

	/** Preuve de travail demandée aux navigateurs : allumée et calculable. */
	public static function proof(): bool {
		return self::altcha() && self::altcha_secure();
	}

	/** Type de contenu des réponses : privé, sans interface ni requête publique, effacé à la désinstallation. */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Form responses', 'fluffless-suite' ),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/** Les trois blocs : le parent fournit son identifiant aux champs et aux étapes par le contexte. */
	public static function register_blocks(): void {
		$dir = dirname( FILE ) . '/blocks/';
		register_block_type( $dir . 'formulaire', array( 'render_callback' => array( self::class, 'render_form' ) ) );
		register_block_type( $dir . 'champ', array( 'render_callback' => array( self::class, 'render_field' ) ) );
		register_block_type( $dir . 'etape', array( 'render_callback' => array( self::class, 'render_step' ) ) );
	}

	/** Même feuille dans l'éditeur, pour que l'aperçu ressemble au site. */
	public static function editor_styles(): void {
		if ( ! is_admin() ) {
			return;
		}
		wp_register_style( self::STYLE . '-editor', false, array(), VERSION );
		wp_enqueue_style( self::STYLE . '-editor' );
		wp_add_inline_style( self::STYLE . '-editor', self::css() );
	}

	// ----- Définition -------------------------------------------------------------------------

	/**
	 * Attributs du parent, assainis.
	 *
	 * @param array<string, mixed> $attributes Attributs bruts.
	 * @return array{id: string, recipients: list<string>, route_field: string, routes: array<string, list<string>>, subject: string, confirm: bool, keep: bool, keep_days: int, success: string, submit: string, newsletter: bool, newsletter_list: string, newsletter_consent: string}
	 */
	public static function form_attributes( array $attributes ): array {
		$id = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $attributes['formId'] ?? '' ) ) ) ?? '';
		$id = substr( $id, 0, 20 );
		if ( strlen( $id ) < 4 ) {
			$id = 'form';
		}
		$route  = substr( preg_replace( '/[^a-z0-9_-]/', '', sanitize_title( (string) ( $attributes['routeField'] ?? '' ) ) ) ?? '', 0, 40 );
		$routes = array();
		if ( '' !== $route && is_array( $attributes['routes'] ?? null ) ) {
			foreach ( $attributes['routes'] as $option => $addresses ) {
				$option    = self::plain( $option, self::LABEL_MAX );
				$addresses = self::emails( $addresses );
				if ( '' !== $option && array() !== $addresses ) {
					$routes[ $option ] = $addresses;
				}
				if ( count( $routes ) >= self::MAX_OPTIONS ) {
					break;
				}
			}
		}
		return array(
			'id'                 => $id,
			'recipients'         => self::emails( $attributes['recipients'] ?? '' ),
			'route_field'        => array() !== $routes ? $route : '',
			'routes'             => $routes,
			'subject'            => self::plain( $attributes['subject'] ?? '', self::SUBJECT_MAX ),
			'confirm'            => Sanitize::to_bool( $attributes['confirm'] ?? true ) ?? true,
			'keep'               => true === Sanitize::to_bool( $attributes['keep'] ?? false ),
			'keep_days'          => max( 0, min( self::KEEP_DAYS_MAX, (int) ( $attributes['keepDays'] ?? 0 ) ) ),
			'success'            => self::plain( $attributes['success'] ?? '', self::SUCCESS_MAX, true ),
			'submit'             => self::plain( $attributes['submitLabel'] ?? '', 80 ),
			// Infolettre : la liste a la forme attendue par l'outil, vérifiée à l'envoi (module Infolettre).
			'newsletter'         => true === Sanitize::to_bool( $attributes['newsletter'] ?? false ),
			'newsletter_list'    => substr( preg_replace( '/[^A-Za-z0-9,]/', '', (string) ( $attributes['newsletterList'] ?? '' ) ) ?? '', 0, 200 ),
			'newsletter_consent' => substr( preg_replace( '/[^a-z0-9_-]/', '', (string) ( $attributes['newsletterConsent'] ?? '' ) ) ?? '', 0, 40 ),
		);
	}

	/**
	 * Adresses valides et distinctes d'une liste séparée par des virgules, points-virgules ou espaces.
	 *
	 * @param mixed $raw Liste brute.
	 * @return list<string>
	 */
	public static function emails( $raw ): array {
		$out   = array();
		$parts = preg_split( '/[\s,;]+/', is_string( $raw ) ? $raw : '' );
		foreach ( false === $parts ? array() : $parts as $address ) {
			$address = sanitize_email( $address );
			if ( '' !== $address && is_email( $address ) && ! in_array( $address, $out, true ) ) {
				$out[] = $address;
			}
			if ( count( $out ) >= self::MAX_RECIPIENTS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Un champ assaini, au nom unique dans son formulaire.
	 *
	 * @param array<string, mixed> $attributes Attributs bruts du bloc champ.
	 * @param array<int, string>   $names      Noms déjà pris ; complété.
	 * @param int                  $step       Rang de l'étape qui contient le champ (0 : hors étape).
	 * @phpstan-param list<string> $names
	 * @return Field
	 */
	public static function field( array $attributes, array &$names, int $step = 0 ): array {
		$type = (string) ( $attributes['type'] ?? 'text' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = 'text';
		}
		$label = self::plain( $attributes['label'] ?? '', self::LABEL_MAX );
		$name  = sanitize_title( (string) ( $attributes['name'] ?? '' ) );
		if ( '' === $label ) {
			if ( 'hidden' === $type && '' !== $name ) {
				$label = ucfirst( str_replace( array( '-', '_' ), ' ', $name ) );
			} else {
				$label = 'consent' === $type ? __( 'I agree', 'fluffless-suite' ) : __( 'Field', 'fluffless-suite' );
			}
		}
		if ( '' === $name ) {
			$name = sanitize_title( $label );
		}
		$name = substr( preg_replace( '/[^a-z0-9_-]/', '', $name ) ?? '', 0, 40 );
		if ( '' === $name || in_array( $name, array( self::FIELD_KEY, self::FIELD_TOKEN, self::HONEYPOT ), true ) ) {
			$name = 'field';
		}
		$base = $name;
		$n    = 1;
		while ( in_array( $name, $names, true ) ) {
			++$n;
			$name = $base . '-' . $n;
		}
		$names[] = $name;

		$options = array();
		if ( in_array( $type, self::CHOICE_TYPES, true ) ) {
			$lines = $attributes['options'] ?? '';
			$lines = is_string( $lines ) ? preg_split( '/\R/u', $lines ) : $lines;
			// Pas de Sanitize::lines : il borne chaque ligne à 200, sous LABEL_MAX.
			foreach ( array_slice( is_array( $lines ) ? $lines : array(), 0, Sanitize::MAX_LIST ) as $option ) {
				$option = self::plain( $option, self::LABEL_MAX );
				if ( '' !== $option && ! in_array( $option, $options, true ) ) {
					$options[] = $option;
				}
				if ( count( $options ) >= self::MAX_OPTIONS ) {
					break;
				}
			}
		}
		$when = substr( preg_replace( '/[^a-z0-9_-]/', '', sanitize_title( (string) ( $attributes['when'] ?? '' ) ) ) ?? '', 0, 40 );
		if ( 'hidden' === $type || $when === $name ) {
			$when = '';
		}
		return array(
			'name'         => $name,
			'type'         => $type,
			'label'        => $label,
			'required'     => Sanitize::to_bool( $attributes['required'] ?? false ) ?? false,
			'placeholder'  => self::plain( $attributes['placeholder'] ?? '', self::LABEL_MAX ),
			'autocomplete' => self::autocomplete( $attributes['autocomplete'] ?? '' ),
			'help'         => self::plain( $attributes['help'] ?? '', self::HELP_MAX ),
			'options'      => $options,
			'value'        => 'hidden' === $type ? self::plain( $attributes['value'] ?? '', self::HELP_MAX ) : '',
			'when'         => $when,
			'is'           => '' !== $when ? self::plain( $attributes['is'] ?? '', self::LABEL_MAX ) : '',
			'step'         => max( 0, $step ),
		);
	}

	/**
	 * Les champs d'un formulaire, dans l'ordre du document, à travers groupes, colonnes et compositions synchronisées.
	 *
	 * Un bloc étape ouvre une étape (titre ajouté à $steps, rang donné à ses champs) ; au-delà de
	 * MAX_STEPS ou déjà dans une étape, il ne compte que comme un conteneur.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Blocs analysés (parse_blocks ou enfants).
	 * @param array<int, string>                      $names  Noms déjà pris.
	 * @param int                                     $depth  Garde-fou de récursion.
	 * @param array<int, string>                      $steps  Titres des étapes rencontrées ; complété.
	 * @param int                                     $step   Rang de l'étape courante (0 : hors étape).
	 * @phpstan-param list<string> $names
	 * @phpstan-param list<string> $steps
	 * @return list<Field>
	 */
	public static function collect( array $blocks, array &$names, int $depth = 0, array &$steps = array(), int $step = 0 ): array {
		$fields = array();
		if ( $depth > 10 ) {
			return $fields;
		}
		foreach ( $blocks as $block ) {
			if ( count( $names ) >= self::MAX_FIELDS ) {
				break;
			}
			$name = (string) ( $block['blockName'] ?? '' );
			if ( self::FIELD === $name ) {
				$fields[] = self::field( (array) ( $block['attrs'] ?? array() ), $names, $step );
				continue;
			}
			if ( self::FORM === $name ) {
				continue; // Un formulaire dans un formulaire : ignoré, le HTML ne l'admet pas.
			}
			$inner = (array) ( $block['innerBlocks'] ?? array() );
			if ( self::STEP === $name && 0 === $step && count( $steps ) < self::MAX_STEPS ) {
				$steps[] = self::plain( $block['attrs']['title'] ?? '', self::LABEL_MAX );
				$fields  = array_merge( $fields, self::collect( $inner, $names, $depth + 1, $steps, count( $steps ) ) );
				continue;
			}
			if ( 'core/block' === $name && array() === $inner ) {
				$ref  = (int) ( $block['attrs']['ref'] ?? 0 );
				$post = $ref > 0 ? get_post( $ref ) : null;
				if ( $post instanceof WP_Post && 'wp_block' === $post->post_type ) {
					$inner = parse_blocks( $post->post_content );
				}
			}
			if ( array() !== $inner ) {
				$fields = array_merge( $fields, self::collect( $inner, $names, $depth + 1, $steps, $step ) );
			}
		}
		return $fields;
	}

	/**
	 * Définition complète d'un formulaire à partir de son bloc analysé.
	 *
	 * @param array<string, mixed>                    $attributes Attributs du parent.
	 * @param array<int|string, array<string, mixed>> $inner      Blocs enfants analysés.
	 * @return Definition
	 */
	public static function definition_from_blocks( array $attributes, array $inner ): array {
		$form   = self::form_attributes( $attributes );
		$names  = array();
		$steps  = array();
		$fields = self::collect( $inner, $names, 0, $steps );
		$hash   = substr( md5( (string) wp_json_encode( array( $form, $steps, $fields ) ) ), 0, 8 );
		return array_merge(
			$form,
			array(
				'key'    => $form['id'] . '-' . $hash,
				'steps'  => $steps,
				'fields' => $fields,
			)
		);
	}

	/**
	 * Mémorise une définition (une écriture par jour et par formulaire au plus), bornée à MAX_FORMS.
	 *
	 * @param array<string, mixed> $definition Définition.
	 * @phpstan-param Definition $definition
	 */
	public static function remember( array $definition ): void {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$now = time();
		$key = $definition['key'];
		if ( isset( $all[ $key ]['seen'] ) && (int) $all[ $key ]['seen'] > $now - DAY_IN_SECONDS ) {
			return;
		}
		$all[ $key ] = array_merge( $definition, array( 'seen' => $now ) );
		if ( count( $all ) > self::MAX_FORMS ) {
			uasort( $all, static fn( array $a, array $b ): int => (int) ( $b['seen'] ?? 0 ) <=> (int) ( $a['seen'] ?? 0 ) );
			$all = array_slice( $all, 0, self::MAX_FORMS, true );
		}
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Définition mémorisée sous une clé, ou null.
	 *
	 * @param string $key Clé (formId-hash).
	 * @return Definition|null
	 */
	public static function definition( string $key ): ?array {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $key ] ) || ! is_array( $all[ $key ] ) ) {
			return null;
		}
		$definition = $all[ $key ];
		unset( $definition['seen'] );
		// Définitions mémorisées par une version sans étapes, conditions, routage ni infolettre : clés manquantes complétées.
		$definition += array(
			'steps'              => array(),
			'route_field'        => '',
			'routes'             => array(),
			'newsletter'         => false,
			'newsletter_list'    => '',
			'newsletter_consent' => '',
		);
		foreach ( (array) ( $definition['fields'] ?? array() ) as $i => $field ) {
			$definition['fields'][ $i ] = (array) $field + array(
				'when' => '',
				'is'   => '',
				'step' => 0,
			);
		}
		/**
		 * Définition sans sa date de dernière vue.
		 *
		 * @var Definition $definition
		 */
		return $definition;
	}

	/** Oublie toutes les définitions (tests, désinstallation). */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	// ----- Jeton et plafond -------------------------------------------------------------------

	/**
	 * Jeton signé qui date le rendu d'un formulaire : « temps.signature ».
	 *
	 * @param string   $key  Clé du formulaire.
	 * @param int|null $time Instant du rendu ; maintenant par défaut.
	 */
	public static function token( string $key, ?int $time = null ): string {
		$time = $time ?? ( self::timing_checks() ? time() : 0 );
		return $time . '.' . substr( wp_hash( 'fluffless-form|' . $key . '|' . $time ), 0, 20 );
	}

	/**
	 * Les contrôles de temps (page périmée après TOKEN_MAX_AGE, envoi plus rapide que min_delay)
	 * sont-ils actifs ? Éteints, le jeton devient stable (temps à 0) : même HTML à chaque rendu,
	 * au prix de ces deux contrôles ; le pot de miel et le plafond par adresse restent.
	 */
	public static function timing_checks(): bool {
		/**
		 * Active ou non les contrôles de temps du formulaire.
		 *
		 * @param bool $enabled Vrai par défaut.
		 */
		return (bool) apply_filters( 'fluffless_suite_form_timing_checks', true );
	}

	/**
	 * Temps du rendu si la signature tient, sinon null.
	 *
	 * @param string $key   Clé du formulaire.
	 * @param string $token Jeton reçu.
	 */
	public static function token_time( string $key, string $token ): ?int {
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token, $m ) ) {
			return null;
		}
		$time = (int) $m[1];
		return hash_equals( self::token( $key, $time ), $token ) ? $time : null;
	}

	/**
	 * Réponses des étapes déjà passées, portées par le formulaire lui-même : JSON en base64 signé.
	 *
	 * @param string                             $key    Clé du formulaire.
	 * @param array<string, string|list<string>> $values Réponses.
	 */
	public static function state( string $key, array $values ): string {
		if ( array() === $values ) {
			return '';
		}
		$json = (string) wp_json_encode( $values );
		$data = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport d'un JSON signé dans un champ caché.
		return $data . '.' . substr( wp_hash( 'fluffless-form-state|' . $key . '|' . $data ), 0, 20 );
	}

	/**
	 * Réponses portées si la signature tient ; tableau vide sans état ; null si l'état est altéré.
	 *
	 * @param string $key   Clé du formulaire.
	 * @param string $state État reçu.
	 * @return array<string, string|list<string>>|null
	 */
	public static function state_values( string $key, string $state ): ?array {
		if ( '' === $state ) {
			return array();
		}
		if ( 1 !== preg_match( '/^([A-Za-z0-9_-]{1,60000})\.([a-f0-9]{20})$/', $state, $m ) ) {
			return null;
		}
		if ( ! hash_equals( substr( wp_hash( 'fluffless-form-state|' . $key . '|' . $m[1] ), 0, 20 ), $m[2] ) ) {
			return null;
		}
		$json = base64_decode( strtr( $m[1], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- même transport, signature vérifiée avant.
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$values = array();
		foreach ( $data as $name => $value ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$values[ $name ] = array_values( array_filter( $value, 'is_string' ) );
			} elseif ( is_string( $value ) ) {
				$values[ $name ] = $value;
			}
		}
		return $values;
	}

	/**
	 * Vrai si un champ s'affiche selon sa condition et les réponses (un champ sans condition s'affiche
	 * toujours ; si le champ qui commande est lui-même caché, le champ l'est aussi).
	 *
	 * @param array<string, mixed>               $field  Champ.
	 * @param array<int, array<string, mixed>>   $fields Tous les champs du formulaire.
	 * @param array<string, string|list<string>> $values Réponses.
	 * @param int                                $depth  Garde-fou (conditions en chaîne).
	 * @phpstan-param Field $field
	 * @phpstan-param list<Field> $fields
	 */
	public static function shown( array $field, array $fields, array $values, int $depth = 0 ): bool {
		if ( '' === $field['when'] || $depth > 10 ) {
			return true;
		}
		$control = null;
		foreach ( $fields as $candidate ) {
			if ( $candidate['name'] === $field['when'] ) {
				$control = $candidate;
				break;
			}
		}
		if ( null === $control || ! in_array( $control['type'], self::CONTROL_TYPES, true ) || $control['step'] !== $field['step'] ) {
			return true; // Condition sans champ qui la commande, ou d'une autre étape : ignorée.
		}
		if ( ! self::shown( $control, $fields, $values, $depth + 1 ) ) {
			return false;
		}
		$value = $values[ $field['when'] ] ?? '';
		if ( 'consent' === $control['type'] ) {
			return '' !== $value;
		}
		return is_array( $value ) ? in_array( $field['is'], $value, true ) : $value === $field['is'];
	}

	/**
	 * Vrai si un champ fait partie de l'étape à vérifier : celle qui le contient, ou la dernière (ou
	 * le formulaire sans étapes) pour un champ hors étape.
	 *
	 * @param array<string, mixed> $field Champ.
	 * @param int                  $step  Étape courante (0 : sans étapes).
	 * @param int                  $total Nombre d'étapes.
	 * @phpstan-param Field $field
	 */
	public static function in_step( array $field, int $step, int $total ): bool {
		return $field['step'] === $step || ( 0 === $field['step'] && $step === $total );
	}

	/**
	 * Clé de transitoire pour une adresse : haché avec un sel du jour, l'adresse ne s'en déduit pas.
	 *
	 * @param string $ip Adresse.
	 */
	public static function rate_key( string $ip ): string {
		return self::RATE_PREFIX . substr( wp_hash( 'fluffless-form-rate|' . $ip . '|' . gmdate( 'Y-m-d' ) ), 0, 24 );
	}

	/**
	 * Nombre de messages déjà envoyés dans la fenêtre courante.
	 *
	 * @param string $ip Adresse.
	 */
	public static function rate_count( string $ip ): int {
		return (int) get_transient( self::rate_key( $ip ) );
	}

	/**
	 * Compte un envoi accepté dans la fenêtre courante.
	 *
	 * @param string $ip Adresse.
	 */
	private static function rate_bump( string $ip ): void {
		$key = self::rate_key( $ip );
		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_WINDOW );
	}

	/**
	 * Nombre d'envois en échec dans la fenêtre courante (compteur distinct de celui des envois réussis).
	 *
	 * @param string $ip Adresse.
	 */
	public static function fail_count( string $ip ): int {
		return (int) get_transient( self::rate_key( $ip ) . 'f' );
	}

	/**
	 * Compte un envoi en échec dans la fenêtre courante.
	 *
	 * @param string $ip Adresse.
	 */
	private static function fail_bump( string $ip ): void {
		$key = self::rate_key( $ip ) . 'f';
		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_WINDOW );
	}

	// ----- Traitement -------------------------------------------------------------------------

	/** Sur template_redirect : traite un envoi, redirige en cas de succès, garde les erreurs pour le rendu. */
	public static function handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- pas de nonce : jeton signé et pot de miel, voir l'en-tête.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method || ! isset( $_POST[ self::FIELD_KEY ] ) ) {
			return;
		}
		$post = wp_unslash( $_POST );
		// phpcs:enable
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Adresse du visiteur derrière un mandataire, quand REMOTE_ADDR ne la donne pas.
		 *
		 * @param string $ip Adresse.
		 */
		$ip     = (string) apply_filters( 'fluffless_form_client_ip', $ip );
		$result = self::process( $post, $ip );
		if ( ! $result['ok'] ) {
			self::show_errors( $result );
			return;
		}
		wp_safe_redirect( self::sent_url( $result['id'] ) . '#fl-' . $result['id'], 303 );
		exit;
	}

	/**
	 * Chemin de la page courante, avec sa requête en permaliens simples où elle identifie la page
	 * (`/?page_id=6`) ; sans requête avec des permaliens propres. Vide sans adresse de requête (C-S06-09).
	 * Sert aussi à la preuve de consentement de l'infolettre (X5-114).
	 */
	public static function request_target(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$parts  = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		$path   = is_array( $parts ) ? (string) ( $parts['path'] ?? '' ) : '';
		$query  = is_array( $parts ) ? (string) ( $parts['query'] ?? '' ) : '';
		$target = $path;
		if ( '' === (string) get_option( 'permalink_structure' ) && '' !== $query ) {
			$args = array();
			wp_parse_str( $query, $args );
			unset( $args[ self::QUERY_SENT ] );
			if ( array() !== $args ) {
				$target = ( '' === $path ? '/' : $path ) . '?' . http_build_query( $args );
			}
		}
		return $target;
	}

	/**
	 * Adresse de la page après un envoi réussi.
	 *
	 * @param string $id Identifiant du formulaire.
	 */
	public static function sent_url( string $id ): string {
		$target = self::request_target();
		return add_query_arg( self::QUERY_SENT, $id, home_url( '' === $target ? '/' : $target ) );
	}

	/**
	 * Résultat refusé à réafficher par le rendu de la page courante (null : rien).
	 *
	 * @param Result|null $result Résultat de process().
	 */
	public static function show_errors( ?array $result ): void {
		self::$state = $result;
	}

	/**
	 * Vérifie et envoie un formulaire posté. Sans effet de bord hors le compteur et wp_mail().
	 *
	 * @param array<string, mixed> $post Données postées, déjà sans barres obliques.
	 * @param string               $ip   Adresse du visiteur.
	 * @return Result
	 */
	public static function process( array $post, string $ip ): array {
		$key        = sanitize_key( (string) ( $post[ self::FIELD_KEY ] ?? '' ) );
		$result     = array(
			'ok'      => false,
			'silent'  => false,
			'id'      => (string) strtok( $key, '-' ),
			'step'    => 0,
			'errors'  => array(),
			'values'  => array(),
			'message' => '',
		);
		$definition = self::definition( $key );
		if ( null === $definition ) {
			$result['message'] = __( 'This form has changed. Reload the page and try again.', 'fluffless-suite' );
			return $result;
		}
		$result['id'] = $definition['id'];

		// Pot de miel : un robot qui remplit le champ caché croit avoir réussi.
		if ( '' !== trim( (string) ( $post[ self::HONEYPOT ] ?? '' ) ) ) {
			$result['ok']     = true;
			$result['silent'] = true;
			return $result;
		}

		$time   = self::token_time( $key, (string) ( $post[ self::FIELD_TOKEN ] ?? '' ) );
		$timing = self::timing_checks();
		// Jeton stable (temps 0) accepté seulement quand les contrôles de temps sont éteints.
		if ( null === $time || ( $timing && ( 0 === $time || $time > time() + MINUTE_IN_SECONDS || $time < time() - self::TOKEN_MAX_AGE ) ) ) {
			$result['message'] = __( 'This page is out of date. Reload it and try again.', 'fluffless-suite' );
			return $result;
		}
		if ( $timing && time() - $time < Options::get_int( self::id(), 'min_delay' ) ) {
			$result['message'] = __( 'That was quick. Take a moment, then send again.', 'fluffless-suite' );
			return $result;
		}

		// Étapes : rang reçu borné, réponses des étapes passées portées par un état signé.
		$total   = count( $definition['steps'] );
		$step    = $total > 0 ? min( max( 1, (int) ( $post[ self::FIELD_STEP ] ?? 1 ) ), $total ) : 0;
		$carried = self::state_values( $key, (string) ( $post[ self::FIELD_STATE ] ?? '' ) );
		if ( null === $carried ) {
			$result['message'] = __( 'This page is out of date. Reload it and try again.', 'fluffless-suite' );
			return $result;
		}
		$result['step']   = $step;
		$result['values'] = $carried;
		if ( $step > 1 && '' !== (string) ( $post[ self::FIELD_BACK ] ?? '' ) ) {
			$result['step'] = $step - 1;
			return $result; // Retour à l'étape précédente : rien à vérifier, les réponses restent.
		}

		$data           = $post[ self::FIELD_DATA ] ?? array();
		$data           = is_array( $data ) ? $data : array();
		$checked_fields = array();
		foreach ( $definition['fields'] as $field ) {
			$current = self::in_step( $field, $step, $total );
			$earlier = $step === $total && $field['step'] > 0 && $field['step'] < $step; // Dernière étape : les réponses portées sont revérifiées.
			if ( ! $current && ! $earlier ) {
				continue;
			}
			$checked = self::validate( $field, $current ? ( $data[ $field['name'] ] ?? null ) : ( $carried[ $field['name'] ] ?? null ) );
			if ( null !== $checked['error'] ) {
				$result['errors'][ $field['name'] ] = $checked['error'];
			}
			$result['values'][ $field['name'] ] = $checked['value'];
			$checked_fields[]                   = $field;
		}
		// Champs cachés par leur condition : ni erreur ni réponse.
		foreach ( $checked_fields as $field ) {
			if ( ! self::shown( $field, $definition['fields'], $result['values'] ) ) {
				unset( $result['errors'][ $field['name'] ] );
				$result['values'][ $field['name'] ] = 'checkboxes' === $field['type'] ? array() : '';
			}
		}
		// Une erreur dans une étape passée (état périmé ou sauté) ramène à cette étape.
		foreach ( $checked_fields as $field ) {
			if ( isset( $result['errors'][ $field['name'] ] ) && $field['step'] > 0 && $field['step'] < $result['step'] ) {
				$result['step'] = $field['step'];
			}
		}
		if ( array() !== $result['errors'] ) {
			$result['message'] = sprintf(
				/* translators: %d: nombre de champs à corriger. */
				_n( 'Please check %d field.', 'Please check %d fields.', count( $result['errors'] ), 'fluffless-suite' ),
				count( $result['errors'] )
			);
			return $result;
		}

		if ( $step > 0 && $step < $total ) {
			$result['step'] = $step + 1;
			return $result; // Étape passée : la suivante s'affiche avec les réponses portées.
		}
		// Plafond vérifié après la validation : les champs saisis restent à l'écran (C-S06-10).
		$rate = Options::get_int( self::id(), 'rate' );
		if ( '' !== $ip && ( self::rate_count( $ip ) >= $rate || self::fail_count( $ip ) >= $rate * self::FAIL_FACTOR ) ) {
			$result['message'] = __( 'Too many messages from this connection. Try again in a few minutes.', 'fluffless-suite' );
			return $result;
		}
		// Preuve exigée des formulaires rendus depuis l'activation ; jeton stable (temps 0) : toujours.
		$proof = self::proof() && ( ! $timing || $time >= self::altcha_since() );
		$given = (string) ( $post[ Altcha::FIELD ] ?? '' );
		if ( $proof && ! Altcha::verify( $given ) ) {
			// « - » : le script a tourné mais n'a pas obtenu de défi (réseau, route bloquée).
			$result['message'] = '-' === $given
				? __( 'The anti-robot check could not finish. Check your connection, then send again.', 'fluffless-suite' )
				: __( 'The anti-robot check did not go through. Make sure JavaScript is on, then send again.', 'fluffless-suite' );
			return $result;
		}
		// Gardée et marquée avant l'envoi : un courriel qui échoue ou qui plante ne perd pas la réponse.
		$stored = $definition['keep'] ? self::store( $definition, $result['values'] ) : 0;
		$sent   = self::send( $definition, $result['values'] );
		if ( $sent && $stored > 0 ) {
			delete_post_meta( $stored, self::META_UNSENT );
		}
		if ( $definition['keep'] ) {
			self::purge(); // Après l'envoi : la purge ne retarde ni ne bloque le courriel.
		}
		if ( ! $sent ) {
			if ( '' !== $ip ) {
				self::fail_bump( $ip ); // Les échecs comptent à part, plus largement que les envois réussis.
			}
			$result['message'] = self::failure_message( $definition );
			return $result;
		}
		if ( '' !== $ip ) {
			self::rate_bump( $ip ); // Seuls les envois réussis comptent (C-S06-10).
		}
		$result['ok'] = true;
		return $result;
	}

	/**
	 * Message montré quand l'envoi échoue : sans adresse par défaut, une adresse publique seulement si le site la donne par le filtre (C-S06-02).
	 *
	 * @param array<string, mixed> $definition Définition.
	 * @phpstan-param Definition $definition
	 */
	private static function failure_message( array $definition ): string {
		// Aucune adresse par défaut : le premier destinataire est souvent une adresse privée. Un site qui veut
		// proposer une adresse publique la donne par le filtre.
		$contact = '';
		/**
		 * Adresse proposée à la visiteuse quand l'envoi échoue ; vide (par défaut) pour ne rien afficher.
		 *
		 * @param string     $contact    Adresse proposée, vide par défaut.
		 * @param Definition $definition Définition.
		 */
		$contact = (string) apply_filters( 'fluffless_form_failure_contact', $contact, $definition );
		if ( '' === $contact || ! is_email( $contact ) ) {
			return __( 'The message could not be sent. Try again later.', 'fluffless-suite' );
		}
		return sprintf(
			/* translators: %s: adresse courriel du site. */
			__( 'The message could not be sent. Try again later, or write to %s.', 'fluffless-suite' ),
			$contact
		);
	}

	/**
	 * Garde l'échec d'envoi même quand le module Courriel est inactif ou ne journalise pas.
	 *
	 * @param \WP_Error|null     $caught Erreur de wp_mail_failed captée pendant l'envoi, s'il y en a eu une.
	 * @param array<int, string> $to     Destinataires.
	 */
	private static function keep_failure( ?\WP_Error $caught, array $to ): void {
		if ( null !== $caught && false !== has_action( 'wp_mail_failed', array( Mail::class, 'record_failure' ) ) ) {
			return; // Le module Courriel l'a déjà gardé.
		}
		Mail::record_failure(
			$caught ?? new \WP_Error( 'wp_mail_failed', __( 'The mail function did not accept the message.', 'fluffless-suite' ), array( 'to' => $to ) )
		);
	}

	/** Avis d'administration : un courriel du site n'est pas parti dans la dernière semaine (C-S06-02). */
	public static function render_failure_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		if ( ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) && ! str_contains( $screen->id, SettingsPage::SLUG ) ) {
			return;
		}
		$recent = array_values(
			array_filter(
				Mail::failures(),
				static fn( array $failure ): bool => $failure['time'] > time() - WEEK_IN_SECONDS
			)
		);
		if ( array() === $recent ) {
			return;
		}
		$unsent = array_sum( array_column( self::summaries(), 'unsent' ) );
		$kept   = '';
		if ( $unsent > 0 ) {
			$kept = sprintf(
				' %1$s <a href="%2$s">%3$s</a>',
				esc_html(
					sprintf(
						/* translators: %d: number of kept form responses whose email was not sent. */
						_n( '%d form response was kept but not sent.', '%d form responses were kept but not sent.', $unsent, 'fluffless-suite' ),
						$unsent
					)
				),
				esc_url( SettingsPage::url( 'fl-title-' . self::id() ) ),
				esc_html__( 'Read them in the Form panel', 'fluffless-suite' )
			);
		}
		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a>%4$s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: date of the last failure, 2: recipient address. */
					__( 'An email from your site could not be sent on %1$s (to %2$s). A form message that fails this way reaches you only if the form keeps its responses: check the mail settings and answer visitors another way.', 'fluffless-suite' ),
					wp_date( (string) get_option( 'date_format' ), $recent[0]['time'] ),
					'' !== $recent[0]['to'] ? $recent[0]['to'] : '?'
				)
			),
			esc_url( SettingsPage::url( 'fl-title-' . Mail::id() ) ),
			esc_html__( 'Open the mail settings', 'fluffless-suite' ),
			$kept // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		);
	}

	// ----- Réponses conservées ------------------------------------------------------------------

	/**
	 * Enregistre une réponse (formulaire qui garde ses réponses), marquée non envoyée tant que le
	 * courriel n'est pas parti. La purge des anciennes se fait après l'envoi (process()).
	 *
	 * @param array<string, mixed>               $definition Définition.
	 * @param array<string, string|list<string>> $values     Valeurs validées.
	 * @phpstan-param Definition $definition
	 * @return int Identifiant du contenu, 0 en cas d'échec.
	 */
	public static function store( array $definition, array $values ): int {
		$fields = array();
		foreach ( $definition['fields'] as $field ) {
			$fields[] = array(
				'name'  => $field['name'],
				'label' => $field['label'],
			);
		}
		$keep_days = max( 0, min( self::KEEP_DAYS_MAX, (int) ( $definition['keep_days'] ?? 0 ) ) );
		$id        = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_title'   => '' !== $definition['subject'] ? $definition['subject'] : $definition['id'],
				'post_content' => '',
				'post_author'  => 0,
				'meta_input'   => array_merge(
					array(
						self::META_FORM   => $definition['id'],
						self::META_FIELDS => $fields,
						self::META_VALUES => $values,
						self::META_UNSENT => '1',
					),
					$keep_days > 0 ? array( self::META_KEEP_DAYS => $keep_days ) : array()
				),
			),
			false,
			false
		);
		return $id > 0 ? $id : 0;
	}

	/**
	 * Supprime les réponses échues. Une réponse sans durée propre suit keep_days du module; celle
	 * d'un formulaire qui fixe sa durée (liste d'attente : 365 jours) suit la sienne, pas celle du module.
	 * Retourne le nombre effacé.
	 */
	public static function purge(): int {
		$days  = max( 1, Options::get_int( self::id(), 'keep_days' ) );
		$limit = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$ids   = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- purge par lots, identifiants seulement.
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						'column' => 'post_date_gmt',
						'before' => $limit,
					),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- purge bornée, lots de 500.
					array(
						'key'     => self::META_KEEP_DAYS,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		// Les réponses à durée propre : une requête par durée en usage (peu de valeurs distinctes), bornée
		// par la date de cette durée, pour qu'un grand lot d'une durée n'en cache pas une autre.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- durées distinctes en usage, une requête courte.
		$durations = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 50", self::META_KEEP_DAYS ) );
		foreach ( $durations as $duration ) {
			$own_days = max( 1, min( self::KEEP_DAYS_MAX, (int) $duration ) );
			$ids      = array_merge(
				$ids,
				get_posts(
					array(
						'post_type'      => self::POST_TYPE,
						'post_status'    => 'any',
						'fields'         => 'ids',
						'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- purge par lots, identifiants seulement.
						'no_found_rows'  => true,
						'date_query'     => array(
							array(
								'column' => 'post_date_gmt',
								'before' => gmdate( 'Y-m-d H:i:s', time() - $own_days * DAY_IN_SECONDS ),
							),
						),
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- purge bornée, lots de 500.
							array(
								'key'   => self::META_KEEP_DAYS,
								'value' => (string) $duration,
							),
						),
					)
				)
			);
		}
		$ids = array_values( array_unique( $ids ) );
		return self::delete_posts( $ids );
	}

	/** Tâche quotidienne : purge des réponses échues et des marques de preuves échues. */
	public static function daily(): void {
		self::purge();
		Altcha::sweep();
	}

	/**
	 * Supprime toutes les réponses d'un formulaire. Retourne le nombre effacé.
	 *
	 * @param string $form Identifiant du formulaire.
	 */
	public static function delete_all( string $form ): int {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => self::MAX_EXPORT,
				'no_found_rows'  => true,
				'meta_key'       => self::META_FORM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- méta indexée par valeur courte, un formulaire à la fois.
				'meta_value'     => $form, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- idem.
			)
		);
		return self::delete_posts( $ids );
	}

	/**
	 * Efface des contenus sans passer par la corbeille.
	 *
	 * @param array<int|string, mixed> $ids Identifiants.
	 */
	private static function delete_posts( array $ids ): int {
		$n = 0;
		foreach ( $ids as $id ) {
			if ( is_int( $id ) && false !== wp_delete_post( $id, true ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Réponses d'un formulaire, de la plus récente à la plus ancienne.
	 *
	 * @param string $form  Identifiant du formulaire.
	 * @param int    $limit Nombre maximal.
	 * @return list<Stored>
	 */
	public static function responses( string $form, int $limit = self::MAX_EXPORT ): array {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => max( 1, min( self::MAX_EXPORT, $limit ) ),
				'no_found_rows'  => true,
				'orderby'        => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'meta_key'       => self::META_FORM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- méta indexée par valeur courte, un formulaire à la fois.
				'meta_value'     => $form, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- idem.
			)
		);
		return array_values( array_map( array( self::class, 'stored' ), $posts ) );
	}

	/**
	 * Une réponse gardée, lue depuis ses métas.
	 *
	 * @param \WP_Post $post Réponse.
	 * @return Stored
	 */
	private static function stored( \WP_Post $post ): array {
		$fields = get_post_meta( $post->ID, self::META_FIELDS, true );
		$values = get_post_meta( $post->ID, self::META_VALUES, true );
		$clean  = array();
		foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			if ( is_array( $field ) && isset( $field['name'], $field['label'] ) ) {
				$clean[] = array(
					'name'  => (string) $field['name'],
					'label' => (string) $field['label'],
				);
			}
		}
		$stored = array();
		foreach ( is_array( $values ) ? $values : array() as $name => $value ) {
			if ( is_array( $value ) ) {
				$stored[ (string) $name ] = array_values( array_map( 'strval', $value ) );
			} else {
				$stored[ (string) $name ] = (string) $value;
			}
		}
		return array(
			'id'     => $post->ID,
			'time'   => (int) get_post_time( 'U', true, $post ),
			'form'   => (string) get_post_meta( $post->ID, self::META_FORM, true ),
			'fields' => $clean,
			'values' => $stored,
			'unsent' => '1' === get_post_meta( $post->ID, self::META_UNSENT, true ),
		);
	}

	/**
	 * Réponses gardées dont une valeur est ce courriel (casse ignorée), pour l'exportateur et
	 * l'effaceur de WordPress.
	 *
	 * @param string $email Courriel cherché.
	 * @return list<Stored>
	 */
	public static function responses_with( string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- demande d'accès ou d'effacement, rare.
					array(
						'key'     => self::META_VALUES,
						'value'   => $email,
						'compare' => 'LIKE',
					),
				),
			)
		);
		$rows  = array();
		foreach ( $posts as $post ) {
			$row = self::stored( $post );
			foreach ( $row['values'] as $value ) {
				if ( in_array( $email, array_map( static fn( string $v ): string => strtolower( trim( $v ) ), (array) $value ), true ) ) {
					$rows[] = $row;
					break;
				}
			}
		}
		return $rows;
	}

	/**
	 * Efface des réponses gardées.
	 *
	 * @param array<int|string, mixed> $ids Réponses.
	 * @return int Nombre effacé.
	 */
	public static function delete_responses( array $ids ): int {
		return self::delete_posts( $ids );
	}

	/**
	 * Un résumé par formulaire qui a des réponses conservées : nombre, non envoyées, première et dernière.
	 *
	 * @return list<Summary>
	 */
	public static function summaries(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- agrégat par formulaire, une requête à l'ouverture des réglages.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_value AS form, COUNT(DISTINCT p.ID) AS n, COUNT(DISTINCT u.post_id) AS unsent, MIN(p.post_date_gmt) AS first, MAX(p.post_date_gmt) AS last, MAX(p.post_title) AS subject
				FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s
				WHERE p.post_type = %s GROUP BY m.meta_value ORDER BY last DESC LIMIT 100",
				self::META_FORM,
				self::META_UNSENT,
				self::POST_TYPE
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'form'    => (string) ( $row['form'] ?? '' ),
				'subject' => (string) ( $row['subject'] ?? '' ),
				'count'   => (int) ( $row['n'] ?? 0 ),
				'unsent'  => (int) ( $row['unsent'] ?? 0 ),
				'first'   => (int) strtotime( (string) ( $row['first'] ?? '' ) . ' UTC' ),
				'last'    => (int) strtotime( (string) ( $row['last'] ?? '' ) . ' UTC' ),
			);
		}
		return $out;
	}

	/**
	 * Export CSV d'un formulaire : une colonne par champ (union des libellés, plus récents d'abord), puis la date,
	 * puis une colonne « Envoi » seulement quand au moins une réponse n'a pas été envoyée.
	 * Séparateur virgule, UTF-8 avec marque d'ordre, cellules qui commencent par un signe de formule neutralisées.
	 *
	 * @param string $form Identifiant du formulaire.
	 */
	public static function csv( string $form ): string {
		$rows    = self::responses( $form );
		$columns = array();
		foreach ( $rows as $row ) {
			foreach ( $row['fields'] as $field ) {
				if ( ! isset( $columns[ $field['name'] ] ) ) {
					$columns[ $field['name'] ] = $field['label'];
				}
			}
		}
		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
		$handle = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flux mémoire pour fputcsv().
		if ( false === $handle ) {
			return '';
		}
		fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- flux mémoire, pas un fichier.
		$unsent = in_array( true, array_column( $rows, 'unsent' ), true );
		$header = array_merge( array_map( array( self::class, 'cell' ), array_values( $columns ) ), array( __( 'Date', 'fluffless-suite' ) ) );
		if ( $unsent ) {
			$header[] = _x( 'Delivery', 'CSV column: whether the email went out', 'fluffless-suite' );
		}
		fputcsv( $handle, $header, ',', '"', '\\' );
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( array_keys( $columns ) as $name ) {
				$value  = $row['values'][ $name ] ?? '';
				$line[] = self::cell( is_array( $value ) ? implode( ', ', $value ) : $value );
			}
			$date   = wp_date( $format, $row['time'] );
			$line[] = false === $date ? '' : $date;
			if ( $unsent ) {
				$line[] = $row['unsent'] ? __( 'Not sent', 'fluffless-suite' ) : __( 'Sent', 'fluffless-suite' );
			}
			fputcsv( $handle, $line, ',', '"', '\\' );
		}
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- flux mémoire.
		return false === $csv ? '' : $csv;
	}

	/**
	 * Cellule sûre pour un tableur : un signe de formule en tête est précédé d'une apostrophe.
	 *
	 * @param string $value Valeur.
	 */
	public static function cell( string $value ): string {
		return 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/** Bouton « Exporter » : vérifie, envoie le CSV du formulaire demandé. */
	public static function handle_export(): void {
		$form = self::admin_form( self::ACTION_EXPORT );
		$csv  = self::csv( $form );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'formulaire-' . $form . '-' . gmdate( 'Y-m-d' ) ) . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, pas du HTML.
		exit;
	}

	/**
	 * Bouton « Tout supprimer » : POST, nonce et case « Je comprends » cochée pour ce formulaire.
	 * Efface par lots de MAX_EXPORT, revient aux réglages avec le nombre supprimé.
	 */
	public static function handle_delete(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage form responses.', 'fluffless-suite' ), 403 );
		}
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Responses are deleted from the form settings only.', 'fluffless-suite' ), 405 );
		}
		check_admin_referer( self::ACTION_DELETE, self::DELETE_NONCE );
		$form    = isset( $_POST['form'] ) ? sanitize_key( wp_unslash( $_POST['form'] ) ) : '';
		$confirm = isset( $_POST['confirm'] ) && is_array( $_POST['confirm'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['confirm'] ) ) : array();
		if ( '' === $form ) {
			wp_die( esc_html__( 'Unknown form.', 'fluffless-suite' ), 400 );
		}
		$back = SettingsPage::url( 'fl-title-' . self::id() );
		if ( ! in_array( $form, $confirm, true ) ) {
			wp_safe_redirect( add_query_arg( 'fl_deleted', 'unconfirmed', $back ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'fl_deleted', (string) self::delete_all_batched( $form ), $back ) );
		exit;
	}

	/**
	 * Efface toutes les réponses d'un formulaire par lots, au plus DELETE_BATCHES lots par requête.
	 *
	 * @param string $form Identifiant du formulaire.
	 */
	public static function delete_all_batched( string $form ): int {
		$total = 0;
		for ( $i = 0; $i < self::DELETE_BATCHES; $i++ ) {
			$n      = self::delete_all( $form );
			$total += $n;
			if ( $n < self::MAX_EXPORT ) {
				break;
			}
		}
		return $total;
	}

	/** Formulaire POST des boutons « Tout supprimer », hors du formulaire des réglages (pas d'imbrication). */
	public static function render_delete_form(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		echo '<form id="' . esc_attr( self::DELETE_FORM_ID ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden>';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DELETE ) . '">';
		wp_nonce_field( self::ACTION_DELETE, self::DELETE_NONCE );
		echo '</form>';
	}

	/**
	 * Droit, nonce et identifiant du formulaire d'une action admin-post.
	 *
	 * @param string $action Action.
	 */
	private static function admin_form( string $action ): string {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage form responses.', 'fluffless-suite' ), 403 );
		}
		check_admin_referer( $action );
		$form = isset( $_GET['form'] ) ? sanitize_key( wp_unslash( $_GET['form'] ) ) : '';
		if ( '' === $form ) {
			wp_die( esc_html__( 'Unknown form.', 'fluffless-suite' ), 400 );
		}
		return $form;
	}

	/**
	 * Ligne sous l'extension dans l'écran Extensions : ce que « Supprimer » efface, avant que la personne clique.
	 * Absente quand aucune réponse n'est conservée ou que les données sont gardées (C-S05-01).
	 */
	public static function render_deletion_notice(): void {
		if ( Lifecycle::keeps_data() || ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		$total = 0;
		foreach ( self::summaries() as $summary ) {
			$total += $summary['count'];
		}
		if ( $total < 1 ) {
			return;
		}
		if ( ! Options::is_enabled( self::id() ) ) {
			// Module inactif : ni la case ni l'export ne sont à l'écran, la constante reste la seule voie.
			printf(
				'<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>%s</p></div></td></tr>',
				esc_html(
					sprintf(
						/* translators: %s: number of kept form responses. */
						_n(
							'Deleting this plugin permanently erases %s kept form response. To keep it, turn the Forms module back on to export it, or add define( \'FLUFFLESS_KEEP_DATA\', true ); to wp-config.php.',
							'Deleting this plugin permanently erases %s kept form responses. To keep them, turn the Forms module back on to export them, or add define( \'FLUFFLESS_KEEP_DATA\', true ); to wp-config.php.',
							$total,
							'fluffless-suite'
						),
						number_format_i18n( $total )
					)
				)
			);
			return;
		}
		$settings = SettingsPage::url( 'fl-title-' . self::id() );
		printf(
			'<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>%1$s <a href="%2$s">%3$s</a></p></div></td></tr>',
			esc_html(
				sprintf(
					/* translators: %s: number of kept form responses. */
					_n(
						'Deleting this plugin permanently erases %s kept form response. Export it first, or turn on “Keep my data when the plugin is deleted”.',
						'Deleting this plugin permanently erases %s kept form responses. Export them first, or turn on “Keep my data when the plugin is deleted”.',
						$total,
						'fluffless-suite'
					),
					number_format_i18n( $total )
				)
			),
			esc_url( $settings ),
			esc_html__( 'Open the form settings', 'fluffless-suite' )
		);
	}

	/** Panneau dans les réglages : les formulaires qui gardent des réponses, export et suppression. */
	public static function render_panel(): void {
		self::purge();
		$summaries = self::summaries();
		if ( array() === $summaries ) {
			echo '<p class="fl-muted">' . esc_html__( 'No response kept. A form keeps its responses only when its “Keep responses” setting is on.', 'fluffless-suite' ) . '</p>';
			return;
		}
		self::render_delete_notice();
		$format = (string) get_option( 'date_format' );
		echo '<div class="fl-table-scroll"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Kept responses', 'fluffless-suite' ) . '</caption><thead><tr><th scope="col">'
			. esc_html__( 'Form', 'fluffless-suite' ) . '</th><th scope="col">'
			. esc_html__( 'Responses', 'fluffless-suite' ) . '</th><th scope="col">'
			. esc_html__( 'From', 'fluffless-suite' ) . '</th><th scope="col">'
			. esc_html__( 'To', 'fluffless-suite' ) . '</th><th scope="col">'
			. esc_html__( 'Actions', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
		foreach ( $summaries as $summary ) {
			$export = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_EXPORT . '&form=' . rawurlencode( $summary['form'] ) ), self::ACTION_EXPORT );
			$first  = wp_date( $format, $summary['first'] );
			$last   = wp_date( $format, $summary['last'] );
			printf(
				'<tr><td>%1$s <span class="fl-muted">%2$s</span></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td><a class="button button-small" href="%6$s">%7$s</a> <label><input type="checkbox" form="%10$s" name="confirm[]" value="%8$s"> %11$s</label> <button type="submit" form="%10$s" class="button button-small button-link-delete" name="form" value="%8$s">%9$s</button></td></tr>',
				esc_html( $summary['subject'] ),
				esc_html( $summary['form'] ),
				esc_html( (string) $summary['count'] ) . wp_kses_post( self::unsent_pill( $summary['unsent'] ) ),
				esc_html( false === $first ? '' : $first ),
				esc_html( false === $last ? '' : $last ),
				esc_url( $export ),
				esc_html__( 'Export CSV', 'fluffless-suite' ),
				esc_attr( $summary['form'] ),
				esc_html__( 'Delete all', 'fluffless-suite' ),
				esc_attr( self::DELETE_FORM_ID ),
				esc_html__( 'I understand that these responses will be deleted for good.', 'fluffless-suite' )
			);
		}
		echo '</tbody></table></div>';
	}

	/** Résultat de « Tout supprimer », après la redirection. */
	private static function render_delete_notice(): void {
		if ( ! isset( $_GET['fl_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple message après redirection.
			return;
		}
		$raw = sanitize_key( wp_unslash( $_GET['fl_deleted'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
		if ( 'unconfirmed' === $raw ) {
			echo Screen::note( 'warn', __( 'Nothing was deleted: tick the confirmation box next to the button.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			return;
		}
		$n = absint( $raw );
		/* translators: %s: number of deleted form responses. */
		echo Screen::note( 'good', sprintf( _n( '%s response deleted.', '%s responses deleted.', $n, 'fluffless-suite' ), number_format_i18n( $n ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
	}

	/**
	 * Pastille des réponses gardées dont le courriel n'est pas parti, à relancer à la main.
	 *
	 * @param int $count Nombre de réponses non envoyées.
	 */
	private static function unsent_pill( int $count ): string {
		if ( $count < 1 ) {
			return '';
		}
		return ' <span class="fl-pill fl-pill--bad">' . esc_html(
			sprintf(
				/* translators: %d: nombre de réponses dont le courriel n'est pas parti. */
				_n( '%d not sent by email', '%d not sent by email', $count, 'fluffless-suite' ),
				$count
			)
		) . '</span>';
	}

	/**
	 * Valeur assainie et erreur éventuelle d'un champ.
	 *
	 * @param array<string, mixed> $field Champ.
	 * @param mixed                $raw   Valeur postée.
	 * @phpstan-param Field $field
	 * @return array{value: string|list<string>, error: string|null}
	 */
	public static function validate( array $field, $raw ): array {
		$required = __( 'This field is required.', 'fluffless-suite' );
		$type     = $field['type'];

		if ( 'hidden' === $type ) {
			return array(
				'value' => $field['value'],
				'error' => null,
			);
		}
		if ( 'checkboxes' === $type ) {
			$chosen = array();
			foreach ( is_array( $raw ) ? $raw : array() as $item ) {
				$item = is_scalar( $item ) ? (string) $item : '';
				if ( in_array( $item, $field['options'], true ) && ! in_array( $item, $chosen, true ) ) {
					$chosen[] = $item;
				}
			}
			return array(
				'value' => $chosen,
				'error' => $field['required'] && array() === $chosen ? $required : null,
			);
		}
		if ( 'consent' === $type ) {
			$on = is_scalar( $raw ) && '' !== (string) $raw;
			return array(
				'value' => $on ? __( 'Yes', 'fluffless-suite' ) : '',
				'error' => $field['required'] && ! $on ? $required : null,
			);
		}

		$value = is_scalar( $raw ) ? (string) $raw : '';
		$value = 'textarea' === $type ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		if ( '' === $value ) {
			return array(
				'value' => '',
				'error' => $field['required'] ? $required : null,
			);
		}
		$max = 'textarea' === $type ? self::TEXTAREA_MAX : self::TEXT_MAX;
		if ( mb_strlen( $value ) > $max ) {
			return array(
				'value' => mb_substr( $value, 0, $max ),
				/* translators: %s: nombre de caractères. */
				'error' => sprintf( __( 'Keep it under %s characters.', 'fluffless-suite' ), number_format_i18n( $max ) ),
			);
		}
		$error = null;
		switch ( $type ) {
			case 'email':
				if ( ! is_email( $value ) ) {
					$error = __( 'Enter a valid email address.', 'fluffless-suite' );
				}
				break;
			case 'phone':
				if ( 1 !== preg_match( '/^[0-9+()\-\s.#]{5,40}$/', $value ) ) {
					$error = __( 'Enter a phone number.', 'fluffless-suite' );
				}
				break;
			case 'number':
				$value = str_replace( ',', '.', $value );
				if ( ! is_numeric( $value ) || strlen( $value ) > 40 ) {
					$error = __( 'Enter a number.', 'fluffless-suite' );
				}
				break;
			case 'date':
				if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					$error = __( 'Enter a date.', 'fluffless-suite' );
				}
				break;
			case 'select':
			case 'radio':
				if ( ! in_array( $value, $field['options'], true ) ) {
					$error = __( 'Choose one of the options.', 'fluffless-suite' );
				}
				break;
		}
		return array(
			'value' => $value,
			'error' => $error,
		);
	}

	/**
	 * Courriel au site, puis copie à la personne si demandé. Vrai si le premier part.
	 *
	 * @param array<string, mixed>               $definition Définition.
	 * @param array<string, string|list<string>> $values     Valeurs validées.
	 * @phpstan-param Definition $definition
	 */
	public static function send( array $definition, array $values ): bool {
		$site    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$to      = self::recipients( $definition, $values );
		$subject = '' !== $definition['subject'] ? $definition['subject'] : __( 'New message from the site', 'fluffless-suite' );
		$subject = '[' . $site . '] ' . $subject;
		$sender  = self::sender( $definition, $values );
		$lines   = self::lines( $definition, $values );
		$body    = implode( "\n", $lines ) . "\n\n" . sprintf(
			/* translators: 1: date, 2: adresse de la page. */
			__( 'Sent on %1$s from %2$s', 'fluffless-suite' ),
			wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) ),
			home_url( '/' )
		);
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( '' !== $sender ) {
			$headers[] = 'Reply-To: ' . $sender;
		}
		/**
		 * Destinataires d'un formulaire, selon la définition et les réponses (routage par réponse).
		 *
		 * @param list<string>                       $to         Adresses.
		 * @param Definition                         $definition Définition.
		 * @param array<string, string|list<string>> $values     Réponses.
		 */
		$to     = (array) apply_filters( 'fluffless_form_recipients', $to, $definition, $values );
		$caught = null;
		$catch  = static function ( $error ) use ( &$caught ): void {
			if ( $error instanceof \WP_Error ) {
				$caught = $error;
			}
		};
		add_action( 'wp_mail_failed', $catch );
		$sent = wp_mail( $to, $subject, $body, $headers );
		remove_action( 'wp_mail_failed', $catch );
		if ( ! $sent ) {
			self::keep_failure( $caught, $to );
			return false;
		}
		if ( $definition['confirm'] && '' !== $sender && self::copy_allowed() ) {
			$copy = sprintf(
				/* translators: %s: nom du site. */
				__( 'Thank you. %s received your message. Here is a copy of what you sent.', 'fluffless-suite' ),
				$site
			) . "\n\n" . implode( "\n", $lines );
			wp_mail(
				$sender,
				/* translators: %s: nom du site. */
				sprintf( __( 'Your message to %s', 'fluffless-suite' ), $site ),
				$copy,
				// Réponse vers les destinataires du formulaire, jamais vers une adresse d'acheminement.
				array( 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . ( $definition['recipients'][0] ?? get_option( 'admin_email' ) ) )
			);
		}
		/**
		 * Après l'envoi réussi du courriel au site (jamais sur un échec, un pot de miel ni une erreur de validation).
		 * Point d'accroche des ponts vers un outil externe (CRM, liste) ; rien n'est gardé ici, la fonction
		 * accrochée décide de ce qu'elle transmet et doit gérer ses propres erreurs.
		 *
		 * @param Definition                         $definition Définition (id, sujet, champs, destinataires).
		 * @param array<string, string|list<string>> $values     Réponses validées, par nom de champ.
		 */
		do_action( 'fluffless_form_sent', $definition, $values );
		return true;
	}

	/**
	 * Vrai si une copie à la personne peut encore partir aujourd'hui ; la compte alors.
	 */
	private static function copy_allowed(): bool {
		/**
		 * Copies à la personne par jour pour tout le site (0 : aucune copie).
		 *
		 * @param int $cap 50 par défaut.
		 */
		$cap   = max( 0, (int) apply_filters( 'fluffless_form_copy_cap', self::COPY_CAP ) );
		$key   = self::COPY_PREFIX . gmdate( 'Y-m-d' );
		$count = (int) get_transient( $key );
		if ( $count >= $cap ) {
			return false;
		}
		set_transient( $key, $count + 1, DAY_IN_SECONDS );
		return true;
	}

	/**
	 * Destinataires : ceux des options choisies dans le champ d'acheminement ; une option choisie sans
	 * adresse (ou aucune option) y ajoute ceux du formulaire, sinon l'adresse d'administration.
	 *
	 * @param array<string, mixed>               $definition Définition.
	 * @param array<string, string|list<string>> $values     Réponses.
	 * @phpstan-param Definition $definition
	 * @return list<string>
	 */
	public static function recipients( array $definition, array $values ): array {
		$fallback = array() !== $definition['recipients'] ? $definition['recipients'] : array( (string) get_option( 'admin_email' ) );
		if ( '' === $definition['route_field'] ) {
			return $fallback;
		}
		$to      = array();
		$default = false;
		$chosen  = $values[ $definition['route_field'] ] ?? '';
		$chosen  = array_filter( is_array( $chosen ) ? $chosen : array( $chosen ), static fn( $option ) => '' !== $option );
		foreach ( $chosen as $option ) {
			$addresses = $definition['routes'][ $option ] ?? array();
			$default   = $default || array() === $addresses;
			$to        = array_merge( $to, $addresses );
		}
		if ( array() === $to || $default ) {
			$to = array_merge( $to, $fallback );
		}
		return array_slice( array_values( array_unique( $to ) ), 0, self::MAX_RECIPIENTS );
	}

	/**
	 * Adresse de la personne : le premier champ courriel rempli.
	 *
	 * @param array<string, mixed>               $definition Définition.
	 * @param array<string, string|list<string>> $values     Réponses.
	 * @phpstan-param Definition $definition
	 */
	public static function sender( array $definition, array $values ): string {
		foreach ( $definition['fields'] as $field ) {
			$value = $values[ $field['name'] ] ?? '';
			if ( 'email' === $field['type'] && is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Le message en texte brut : « Libellé : réponse », une ligne par champ.
	 *
	 * @param array<string, mixed>               $definition Définition.
	 * @param array<string, string|list<string>> $values     Réponses.
	 * @return list<string>
	 * @phpstan-param Definition $definition
	 */
	public static function lines( array $definition, array $values ): array {
		$lines = array();
		foreach ( $definition['fields'] as $field ) {
			$value = $values[ $field['name'] ] ?? '';
			$value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			if ( 'textarea' === $field['type'] ) {
				$lines[] = $field['label'] . " :\n" . $value;
				continue;
			}
			$lines[] = $field['label'] . ' : ' . ( '' === $value ? '–' : $value );
		}
		return $lines;
	}

	// ----- Rendu ------------------------------------------------------------------------------

	/**
	 * Bloc parent : le <form>, le sommaire d'erreurs, les champs déjà rendus, le pot de miel et le bouton.
	 *
	 * @param array<string, mixed> $attributes Attributs.
	 * @param string               $content    Champs et contenu rendus.
	 * @param WP_Block             $block      Bloc, pour relire les enfants analysés.
	 */
	public static function render_form( array $attributes, string $content, WP_Block $block ): string {
		$definition = self::definition_from_blocks( $attributes, (array) ( $block->parsed_block['innerBlocks'] ?? array() ) );
		$id         = $definition['id'];
		unset( self::$names[ $id ], self::$steps[ $id ] );
		if ( ! is_admin() ) {
			self::remember( $definition );
		}
		self::enqueue_inline( self::STYLE, self::css() );
		$rules = self::condition_css( $definition );
		if ( '' !== $rules ) {
			wp_add_inline_style( self::STYLE, $rules );
		}
		$anchor = 'fl-' . $id;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- drapeau d'affichage posé par la redirection, sans effet.
		$sent = isset( $_GET[ self::QUERY_SENT ] ) ? sanitize_key( (string) wp_unslash( $_GET[ self::QUERY_SENT ] ) ) : '';
		if ( $sent === $id ) {
			$message = '' !== $definition['success'] ? $definition['success'] : __( 'Thank you, your message has been sent.', 'fluffless-suite' );
			return sprintf(
				'<div %s><p class="fluffless-formulaire__success" role="status" tabindex="-1" id="%s" autofocus>%s</p></div>',
				get_block_wrapper_attributes( array( 'class' => 'fluffless-formulaire is-sent' ) ),
				esc_attr( $anchor ),
				nl2br( esc_html( $message ) )
			);
		}

		$state   = self::$state;
		$mine    = null !== $state && $state['id'] === $id;
		$total   = count( $definition['steps'] );
		$step    = $mine && $state['step'] > 0 ? min( $state['step'], max( 1, $total ) ) : ( $total > 0 ? 1 : 0 );
		$summary = '';
		if ( $mine && '' !== $state['message'] ) {
			$items = '';
			foreach ( $definition['fields'] as $field ) {
				if ( isset( $state['errors'][ $field['name'] ] ) ) {
					$items .= sprintf(
						'<li>%s</li>',
						sprintf(
							/* translators: 1: link to the field in error, 2: error message. French puts a space before the colon. */
							esc_html__( '%1$s: %2$s', 'fluffless-suite' ),
							sprintf( '<a href="#%1$s">%2$s</a>', esc_attr( $anchor . '-' . $field['name'] ), esc_html( $field['label'] ) ),
							esc_html( $state['errors'][ $field['name'] ] )
						)
					);
				}
			}
			// Le sommaire reçoit le focus, même pour une erreur sans champ (envoi raté, jeton périmé).
			// Une ancre qui vise un élément annule autofocus : l'action vise donc le sommaire lui-même
			// (tabindex -1), jamais le <form>, et l'ancre fait le travail si autofocus est ignoré.
			$summary = sprintf(
				'<div class="fluffless-formulaire__errors" role="alert" tabindex="-1" id="%s-errors" autofocus><p>%s</p>%s</div>',
				esc_attr( $anchor ),
				esc_html( $state['message'] ),
				'' !== $items ? '<ul>' . $items . '</ul>' : ''
			);
		}

		$submit = '' !== $definition['submit'] ? $definition['submit'] : __( 'Send', 'fluffless-suite' );
		$action = self::request_target();

		// Étapes : rang affiché, réponses des étapes passées portées signées, boutons Précédent et Suivant.
		$progress = '';
		$hidden   = '';
		$buttons  = '';
		if ( $total > 0 ) {
			$progress = sprintf(
				'<p class="fluffless-formulaire__progress" id="%s-progress" tabindex="-1"%s>%s</p>',
				esc_attr( $anchor ),
				$mine && '' === $summary ? ' autofocus' : '',
				/* translators: 1: rang de l'étape, 2: nombre d'étapes. */
				esc_html( sprintf( __( 'Step %1$d of %2$d', 'fluffless-suite' ), $step, $total ) )
			);
			$carried = array();
			if ( $mine ) {
				foreach ( $definition['fields'] as $field ) {
					if ( $field['step'] > 0 && $field['step'] < $step && isset( $state['values'][ $field['name'] ] ) ) {
						$carried[ $field['name'] ] = $state['values'][ $field['name'] ];
					}
				}
			}
			$hidden = sprintf(
				'<input type="hidden" name="%s" value="%d"><input type="hidden" name="%s" value="%s">',
				esc_attr( self::FIELD_STEP ),
				$step,
				esc_attr( self::FIELD_STATE ),
				esc_attr( self::state( $definition['key'], $carried ) )
			);
			if ( $step > 1 ) {
				$buttons .= sprintf(
					'<button type="submit" class="wp-element-button is-style-outline" name="%s" value="1" formnovalidate>%s</button>',
					esc_attr( self::FIELD_BACK ),
					esc_html__( 'Previous', 'fluffless-suite' )
				);
			}
			if ( $step < $total ) {
				$submit = __( 'Next', 'fluffless-suite' );
			}
		}
		$buttons .= sprintf( '<button type="submit" class="wp-element-button">%s</button>', esc_html( $submit ) );

		// Preuve de travail, à la dernière étape seulement : champ rempli par le script, zone d'état
		// annoncée pendant le calcul, avis pour qui a désactivé JavaScript.
		$proof = '';
		if ( self::proof() && ( 0 === $total || $step === $total ) ) {
			Altcha::enqueue();
			$proof = sprintf(
				'<input type="hidden" name="%s" value="" autocomplete="off"><p class="fluffless-formulaire__proof" role="status" data-fl-busy="%s"></p><noscript><p class="fluffless-formulaire__noscript">%s</p></noscript>',
				esc_attr( Altcha::FIELD ),
				esc_attr__( 'Checking that you are not a robot…', 'fluffless-suite' ),
				esc_html__( 'This form checks that you are not a robot with a short computation in your browser. Turn JavaScript on to send it.', 'fluffless-suite' )
			);
		}

		return sprintf(
			'<form %1$s id="%2$s" method="post" action="%3$s"%15$s>%4$s%5$s%6$s' .
			'<p class="fluffless-formulaire__trap" aria-hidden="true"><label for="%2$s-site">%7$s</label><input id="%2$s-site" type="text" name="%8$s" tabindex="-1" autocomplete="off" value=""></p>' .
			'<input type="hidden" name="%9$s" value="%10$s"><input type="hidden" name="%11$s" value="%12$s">%13$s%16$s' .
			'<p class="fluffless-formulaire__submit">%14$s</p></form>',
			get_block_wrapper_attributes( array( 'class' => 'fluffless-formulaire' . ( $total > 0 ? ' has-steps' : '' ) ) ),
			esc_attr( $anchor ),
			esc_url( '' === $action ? '' : $action . '#' . $anchor . '-errors' ),
			$summary,
			$progress,
			$content,
			esc_html__( 'Leave this field empty', 'fluffless-suite' ),
			esc_attr( self::HONEYPOT ),
			esc_attr( self::FIELD_KEY ),
			esc_attr( $definition['key'] ),
			esc_attr( self::FIELD_TOKEN ),
			esc_attr( self::token( $definition['key'] ) ),
			$hidden,
			$buttons,
			'' !== $proof ? ' data-fl-altcha="' . esc_url( Altcha::url() ) . '"' : '',
			$proof
		);
	}

	/**
	 * Bloc étape : ses champs seulement quand c'est l'étape affichée, dans un groupe titré ; au-delà
	 * de MAX_STEPS, simple conteneur.
	 *
	 * @param array<string, mixed> $attributes Attributs.
	 * @param string               $content    Champs rendus.
	 * @param WP_Block             $block      Bloc, pour le contexte du formulaire.
	 */
	public static function render_step( array $attributes, string $content, WP_Block $block ): string {
		$form_id                 = self::form_attributes( array( 'formId' => $block->context['fluffless/formId'] ?? '' ) )['id'];
		self::$steps[ $form_id ] = ( self::$steps[ $form_id ] ?? 0 ) + 1;
		$rank                    = self::$steps[ $form_id ];
		if ( $rank > self::MAX_STEPS ) {
			return $content;
		}
		$state   = self::$state;
		$current = null !== $state && $state['id'] === $form_id && $state['step'] > 0 ? $state['step'] : 1;
		if ( $rank !== $current ) {
			return '';
		}
		$title = self::plain( $attributes['title'] ?? '', self::LABEL_MAX );
		return sprintf(
			'<fieldset class="fluffless-etape" id="%s"><legend>%s</legend>%s</fieldset>',
			esc_attr( 'fl-' . $form_id . '-etape-' . $rank ),
			/* translators: %d: rang de l'étape. */
			esc_html( '' !== $title ? $title : sprintf( __( 'Step %d', 'fluffless-suite' ), $rank ) ),
			$content
		);
	}

	/**
	 * Règles CSS des conditions : sans JavaScript, un champ conditionnel reste caché tant que le
	 * champ qui le commande n'a pas la valeur attendue (:has(), navigateurs courants depuis 2023 ;
	 * ailleurs, le champ reste visible et le serveur tranche).
	 *
	 * @param array<string, mixed> $definition Définition.
	 * @phpstan-param Definition $definition
	 */
	public static function condition_css( array $definition ): string {
		$rules = '';
		$form  = '#fl-' . $definition['id'];
		foreach ( $definition['fields'] as $field ) {
			if ( '' === $field['when'] ) {
				continue;
			}
			$control = null;
			foreach ( $definition['fields'] as $candidate ) {
				if ( $candidate['name'] === $field['when'] ) {
					$control = $candidate;
					break;
				}
			}
			if ( null === $control || ! in_array( $control['type'], self::CONTROL_TYPES, true ) || $control['step'] !== $field['step'] ) {
				continue;
			}
			$id = $form . '-' . $control['name'];
			if ( 'consent' === $control['type'] ) {
				$test = $id . ':checked';
			} else {
				$value = str_replace( array( '\\', '"', '<', "\n", "\r" ), array( '\\\\', '\\"', '\\3c ', ' ', ' ' ), $field['is'] );
				$test  = $id . ' ' . ( 'select' === $control['type'] ? 'option' : 'input' ) . '[value="' . $value . '"]:checked';
			}
			$rules .= $form . ':not(:has(' . $test . ')) .fl-when-' . $field['name'] . '{display:none}';
		}
		return $rules;
	}

	/**
	 * Bloc champ : libellé, aide, contrôle, erreur, liés par id.
	 *
	 * @param array<string, mixed> $attributes Attributs.
	 * @param string               $content    Inutilisé (bloc dynamique).
	 * @param WP_Block             $block      Bloc, pour le contexte du formulaire.
	 */
	public static function render_field( array $attributes, string $content, WP_Block $block ): string {
		unset( $content );
		$form_id = self::form_attributes( array( 'formId' => $block->context['fluffless/formId'] ?? '' ) )['id'];
		if ( ! isset( self::$names[ $form_id ] ) ) {
			self::$names[ $form_id ] = array();
		}
		if ( count( self::$names[ $form_id ] ) >= self::MAX_FIELDS ) {
			return '';
		}
		$field = self::field( $attributes, self::$names[ $form_id ] );
		$state = self::$state;
		$error = null !== $state && $state['id'] === $form_id ? ( $state['errors'][ $field['name'] ] ?? '' ) : '';
		$value = null !== $state && $state['id'] === $form_id ? ( $state['values'][ $field['name'] ] ?? '' ) : '';
		$id    = 'fl-' . $form_id . '-' . $field['name'];
		$name  = self::FIELD_DATA . '[' . $field['name'] . ']';

		if ( 'hidden' === $field['type'] ) {
			return sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( $field['value'] ) );
		}

		$describe = array();
		$help     = '';
		if ( '' !== $field['help'] ) {
			$describe[] = $id . '-help';
			$help       = sprintf( '<p id="%s" class="fluffless-champ__help">%s</p>', esc_attr( $id . '-help' ), esc_html( $field['help'] ) );
		}
		$alert = '';
		if ( '' !== $error ) {
			$describe[] = $id . '-error';
			$alert      = sprintf( '<p id="%s" class="fluffless-champ__error">%s</p>', esc_attr( $id . '-error' ), esc_html( $error ) );
		}
		$aria = ( array() !== $describe ? ' aria-describedby="' . esc_attr( implode( ' ', $describe ) ) . '"' : '' ) . ( '' !== $error ? ' aria-invalid="true"' : '' );
		$mark = $field['required']
			? ' <span class="fluffless-champ__required" aria-hidden="true">*</span><span class="screen-reader-text"> ' . esc_html__( '(required)', 'fluffless-suite' ) . '</span>'
			: '';
		$cond = '' !== $field['when'];
		$req  = $field['required'] && ! $cond ? ' required' : ''; // Un champ caché par sa condition ne doit pas bloquer l'envoi natif.
		$wrap = 'fluffless-champ is-type-' . $field['type'] . ( '' !== $error ? ' has-error' : '' ) . ( $cond ? ' fl-when-' . $field['name'] : '' );

		if ( 'consent' === $field['type'] ) {
			return sprintf(
				'<div class="%1$s"><label class="fluffless-champ__choice" for="%2$s"><input id="%2$s" type="checkbox" name="%3$s" value="1"%4$s%5$s%6$s> <span>%7$s%8$s</span></label>%9$s%10$s</div>',
				esc_attr( $wrap ),
				esc_attr( $id ),
				esc_attr( $name ),
				'' !== $value ? ' checked' : '',
				$req,
				$aria,
				esc_html( $field['label'] ),
				$mark,
				$help,
				$alert
			);
		}

		if ( in_array( $field['type'], array( 'checkboxes', 'radio' ), true ) ) {
			$multiple = 'checkboxes' === $field['type'];
			$chosen   = is_array( $value ) ? $value : array( $value );
			$items    = '';
			foreach ( $field['options'] as $i => $option ) {
				$items .= sprintf(
					'<label class="fluffless-champ__choice" for="%1$s"><input id="%1$s" type="%2$s" name="%3$s" value="%4$s"%5$s%6$s> <span>%7$s</span></label>',
					esc_attr( $id . '-' . ( $i + 1 ) ),
					$multiple ? 'checkbox' : 'radio',
					esc_attr( $multiple ? $name . '[]' : $name ),
					esc_attr( $option ),
					in_array( $option, $chosen, true ) ? ' checked' : '',
					( $multiple || '' === $req ? '' : ' required' ),
					esc_html( $option )
				);
			}
			return sprintf(
				'<div class="%1$s"><fieldset id="%2$s" tabindex="-1"%3$s><legend>%4$s%5$s</legend>%6$s%7$s%8$s</fieldset></div>',
				esc_attr( $wrap ),
				esc_attr( $id ),
				$aria,
				esc_html( $field['label'] ),
				$mark,
				$help,
				$items,
				$alert
			);
		}

		$label = sprintf( '<label for="%s">%s%s</label>', esc_attr( $id ), esc_html( $field['label'] ), $mark );
		$value = is_string( $value ) ? $value : '';

		if ( 'select' === $field['type'] ) {
			$options = '<option value="">' . esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : __( 'Choose…', 'fluffless-suite' ) ) . '</option>';
			foreach ( $field['options'] as $option ) {
				$options .= sprintf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $option ), $option === $value ? ' selected' : '' );
			}
			$control = sprintf( '<select id="%s" name="%s"%s%s>%s</select>', esc_attr( $id ), esc_attr( $name ), $req, $aria, $options );
		} elseif ( 'textarea' === $field['type'] ) {
			$control = sprintf(
				'<textarea id="%s" name="%s" rows="6"%s%s%s>%s</textarea>',
				esc_attr( $id ),
				esc_attr( $name ),
				'' !== $field['placeholder'] ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '',
				$req,
				$aria,
				esc_textarea( $value )
			);
		} else {
			$auto    = array(
				'email' => 'email',
				'phone' => 'tel',
			);
			$token   = '' !== $field['autocomplete'] ? $field['autocomplete'] : ( $auto[ $field['type'] ] ?? '' );
			$control = sprintf(
				'<input id="%s" type="%s" name="%s" value="%s"%s%s%s%s%s>',
				esc_attr( $id ),
				esc_attr( self::INPUT_TYPES[ $field['type'] ] ),
				esc_attr( $name ),
				esc_attr( $value ),
				'' !== $field['placeholder'] ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '',
				'number' === $field['type'] ? ' inputmode="decimal" step="any"' : '',
				'' !== $token ? ' autocomplete="' . esc_attr( $token ) . '"' : '',
				$req,
				$aria
			);
		}
		return sprintf( '<div class="%s">%s%s%s%s</div>', esc_attr( $wrap ), $label, $help, $control, $alert );
	}

	/** Feuille du formulaire : palette et rayons du thème, replis neutres ailleurs. Moins de 1,5 ko. */
	public static function css(): string {
		return '.fluffless-formulaire{display:grid;gap:var(--wp--preset--spacing--40,1.25rem)}'
			. '.fluffless-formulaire>p{margin:0}'
			. '.fluffless-champ{display:grid;gap:.35em}'
			. '.fluffless-champ>label,.fluffless-champ legend{font-weight:700}'
			. '.fluffless-champ :is(input:not([type=checkbox],[type=radio]),select,textarea){width:100%;box-sizing:border-box;padding:.6em .8em;font:inherit;color:inherit;background:var(--wp--preset--color--base,#fff);border:2px solid var(--wp--preset--color--divider,currentColor);border-radius:var(--wp--custom--radius--soft,4px)}'
			// Safari ignore bordure et marges d'un select natif : apparence retirée, flèche en dégradé conique ;
			// en couleurs forcées, le dégradé disparaît et le select natif revient (C1-7-01).
			. '.fluffless-champ select:not([multiple]){appearance:none;padding-right:2em;background-image:conic-gradient(from -45deg at 50% 100%,currentColor 90deg,#0000 0);background-position:right .8em center;background-size:.6em .3em;background-repeat:no-repeat}'
			. '@media (forced-colors:active){.fluffless-champ select:not([multiple]){appearance:auto}}'
			. '.fluffless-champ textarea{min-height:8em;resize:vertical}'
			. '.fluffless-champ fieldset{border:0;padding:0;margin:0;min-width:0;display:grid;gap:.35em}'
			. '.fluffless-champ__choice{display:flex;gap:.5em;align-items:flex-start;font-weight:400}'
			. '.fluffless-champ__choice input{margin-top:.3em;flex:none;width:1.1em;height:1.1em;accent-color:var(--wp--preset--color--accent,currentColor)}'
			. '.fluffless-champ__help,.fluffless-champ__error{margin:0;font-size:var(--wp--preset--font-size--small,.9em)}'
			. '.fluffless-champ__help{color:var(--wp--preset--color--muted,inherit)}'
			. '.fluffless-champ__error,.fluffless-champ__required{color:var(--wp--preset--color--alert,#b00020)}'
			. '.fluffless-champ.has-error :is(input,select,textarea),.fluffless-champ :is(input,select,textarea):user-invalid{border-color:var(--wp--preset--color--alert,#b00020)}'
			. '.fluffless-formulaire__errors,.fluffless-formulaire__success{margin:0;padding:1em 1.2em;border:2px solid var(--wp--preset--color--alert,#b00020);border-radius:var(--wp--custom--radius--soft,4px)}'
			. '.fluffless-formulaire__success{border-color:var(--wp--preset--color--accent,currentColor)}'
			. '.fluffless-formulaire__errors p{margin:0}.fluffless-formulaire__errors ul{margin:.5em 0 0;padding-left:1.2em}'
			. '.fluffless-formulaire__trap{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}'
			. '.fluffless-formulaire__submit{margin:0;display:flex;flex-wrap:wrap;gap:.75em}'
			. '.fluffless-formulaire__proof{margin:0;font-size:var(--wp--preset--font-size--small,.9em)}'
			. '.fluffless-formulaire__progress{margin:0;font-size:var(--wp--preset--font-size--small,.9em);color:var(--wp--preset--color--muted,inherit)}'
			. '.fluffless-etape{border:0;padding:0;margin:0;min-width:0;display:grid;gap:var(--wp--preset--spacing--40,1.25rem)}'
			. '.fluffless-etape>legend{padding:0;margin-bottom:var(--wp--preset--spacing--20,.5rem);font-size:var(--wp--preset--font-size--large,1.25em);font-weight:700}';
	}

	/**
	 * Feuille en ligne, une fois par page, seulement si un formulaire est rendu.
	 *
	 * @param string $handle Identifiant de la feuille.
	 * @param string $css    Règles.
	 */
	private static function enqueue_inline( string $handle, string $css ): void {
		if ( wp_style_is( $handle, 'enqueued' ) ) {
			return;
		}
		wp_register_style( $handle, false, array(), VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, $css );
	}

	/**
	 * Jeton autocomplete valide (liste blanche), sinon chaîne vide.
	 *
	 * @param mixed $raw Valeur brute.
	 */
	private static function autocomplete( $raw ): string {
		$token = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		return in_array( $token, self::AUTOCOMPLETE_TOKENS, true ) ? $token : '';
	}

	/**
	 * Texte seul, borné ; en mode multiligne, les retours à la ligne restent.
	 *
	 * @param mixed $raw       Valeur brute.
	 * @param int   $max       Longueur maximale.
	 * @param bool  $multiline Garder les retours à la ligne.
	 */
	private static function plain( $raw, int $max, bool $multiline = false ): string {
		$text = is_scalar( $raw ) ? (string) $raw : '';
		// Nettoyage direct : Sanitize::text/textarea bornent à 200 et 600, sous LABEL_MAX et SUCCESS_MAX.
		$text = $multiline ? sanitize_textarea_field( $text ) : sanitize_text_field( $text );
		return mb_substr( $text, 0, $max );
	}
}
