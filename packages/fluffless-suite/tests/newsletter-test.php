<?php
/**
 * Tests du module Infolettre.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/newsletter-test.php
 * Aucun appel réseau : pre_http_request rend des réponses préparées, et aucun courriel ne part
 * (pre_wp_mail). La clé d'essai passe par la variable d'environnement, jamais par la base.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Http;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Form;
use Fluffless\Suite\Modules\Newsletter;
use Fluffless\Suite\Newsletter\Cyberimpact;
use Fluffless\Suite\Newsletter\Mailchimp;
use Fluffless\Suite\Newsletter\Provider;
use Fluffless\Suite\Newsletter\Reason;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- clé d'essai par l'environnement, jamais en base.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- vérification de l'authentification Basic.
// phpcs:disable Generic.Commenting.DocComment.MissingShort -- annotations de type pour phpstan : les fermetures modifient ces tableaux par référence.

if ( ! class_exists( Newsletter::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_backup_option = get_option( Options::NAME );
$fluffless_cyber_key     = str_repeat( 'a', 40 ) . '.' . str_repeat( 'b', 40 );
$fluffless_chimp_key     = str_repeat( '0123456789abcdef', 2 ) . '-us21';
$fluffless_ip            = '203.0.113.7';

/**
 * Réglages du module, enregistrés comme le ferait la page de réglages.
 *
 * @param array<string, mixed> $section Réglages du module Infolettre.
 * @param bool                 $enabled Module actif.
 * @param bool                 $http    Module Requêtes sortantes actif.
 */
$fluffless_set = static function ( array $section, bool $enabled = true, bool $http = false ): void {
	update_option(
		Options::NAME,
		Options::sanitize(
			array(
				'modules'    => array(
					'newsletter' => $enabled,
					'form'       => true,
					'http'       => $http,
				),
				'newsletter' => $section + Newsletter::defaults(),
			)
		)
	);
	Options::flush();
};

// Courriels captés.
/** @var list<array<string, mixed>> $fluffless_mails */
$fluffless_mails   = array();
$fluffless_capture = static function ( $short, array $atts ) use ( &$fluffless_mails ) {
	unset( $short );
	$fluffless_mails[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $fluffless_capture, 10, 2 );

// Réseau : chaque requête est notée, la réponse vient de la file $fluffless_replies (200 vide par défaut).
/** @var list<array{url: string, args: array<string, mixed>}> $fluffless_requests */
$fluffless_requests = array();
/** @var list<array{0: int, 1: array<string, mixed>}|WP_Error> $fluffless_replies */
$fluffless_replies = array();
$fluffless_network = static function ( $pre, $args, $url ) use ( &$fluffless_requests, &$fluffless_replies ) {
	unset( $pre );
	$fluffless_requests[] = array(
		'url'  => $url,
		'args' => $args,
	);
	$reply                = array_shift( $fluffless_replies ) ?? array( 200, array() );
	if ( $reply instanceof WP_Error ) {
		return $reply;
	}
	return array(
		'headers'  => array(),
		'body'     => (string) wp_json_encode( $reply[1] ),
		'response' => array(
			'code'    => $reply[0],
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $fluffless_network, 5, 3 );

$fluffless_registry = WP_Block_Type_Registry::get_instance();
if ( ! $fluffless_registry->is_registered( Form::FORM ) ) {
	Form::register_blocks();
}

/**
 * Définition du modèle Infolettre, tirée du fichier du modèle.
 *
 * @return array<string, mixed>
 */
$fluffless_pattern = static function (): array {
	ob_start();
	include dirname( __DIR__ ) . '/patterns/formulaire-infolettre.php';
	$found = null;
	$walk  = static function ( array $blocks ) use ( &$walk, &$found ): void {
		foreach ( $blocks as $b ) {
			if ( Form::FORM === ( $b['blockName'] ?? '' ) ) {
				$found = $b;
				return;
			}
			$walk( (array) ( $b['innerBlocks'] ?? array() ) );
		}
	};
	$walk( parse_blocks( (string) ob_get_clean() ) );
	if ( null === $found ) {
		throw new RuntimeException( 'Modèle Infolettre illisible.' );
	}
	return Form::definition_from_blocks( (array) $found['attrs'], $found['innerBlocks'] );
};

try {
	// 1. Contrat du module.
	$fluffless_assert( 'newsletter' === Newsletter::id(), 'id' );
	$fluffless_assert( ! Newsletter::enabled_by_default(), 'actif par défaut' );
	$fluffless_assert( array( 'cyberimpact', 'mailchimp' ) === array_keys( Newsletter::PROVIDERS ), 'outils : Cyberimpact puis Mailchimp' ); // @phpstan-ignore identical.alwaysTrue (garde-fou si la liste change)
	$fluffless_assert( true === Newsletter::defaults()['double_optin'], 'double confirmation décochée par défaut' );

	// 2. Assainissement : une liste de la mauvaise forme pour l'outil se vide.
	$fluffless_clean = Newsletter::sanitize(
		array(
			'provider' => 'cyberimpact',
			'list'     => ' 12, 34 ',
		)
	);
	$fluffless_assert( '12,34' === $fluffless_clean['list'], 'groupes Cyberimpact : ' . wp_json_encode( $fluffless_clean['list'] ) );
	$fluffless_assert(
		'' === Newsletter::sanitize(
			array(
				'provider' => 'cyberimpact',
				'list'     => 'abc',
			)
		)['list'],
		'groupe non numérique gardé'
	);
	$fluffless_assert(
		'a1b2c3d4e5' === Newsletter::sanitize(
			array(
				'provider' => 'mailchimp',
				'list'     => 'a1b2c3d4e5',
			)
		)['list'],
		'audience Mailchimp refusée'
	);
	$fluffless_assert(
		'' === Newsletter::sanitize(
			array(
				'provider' => 'mailchimp',
				'list'     => '../x',
			)
		)['list'],
		'audience Mailchimp mal formée gardée'
	);
	$fluffless_assert( 'cyberimpact' === Newsletter::sanitize( array( 'provider' => 'autre' ) )['provider'], 'outil inconnu gardé' );

	// 3. Cyberimpact : requêtes.
	$fluffless_contact = array(
		'email'    => 'lea+test@example.com',
		'first'    => 'Léa',
		'last'     => '',
		'language' => 'fr_CA',
		'consent'  => 'J’accepte de recevoir l’infolettre.',
		'page'     => 'https://exemple.test/infolettre/',
		'time'     => 1760000000,
	);
	$fluffless_reqs    = Cyberimpact::subscribe(
		$fluffless_contact,
		array(
			'list'         => '12',
			'double_optin' => true,
			'key'          => $fluffless_cyber_key,
		)
	);
	$fluffless_body    = json_decode( $fluffless_reqs[0]['body'], true );
	$fluffless_assert( 1 === count( $fluffless_reqs ) && 'POST' === $fluffless_reqs[0]['method'] && 'https://api.cyberimpact.com/members/optins' === $fluffless_reqs[0]['url'], 'Cyberimpact double confirmation : un POST /members/optins' );
	$fluffless_assert( 'Bearer ' . $fluffless_cyber_key === $fluffless_reqs[0]['headers']['Authorization'], 'Cyberimpact : jeton porteur' );
	$fluffless_assert(
		array(
			'email'     => 'lea+test@example.com',
			'groups'    => '12',
			'language'  => 'fr_ca',
			'firstname' => 'Léa',
		) === $fluffless_body,
		'Cyberimpact : corps de /members/optins ' . wp_json_encode( $fluffless_body )
	);
	$fluffless_reqs = Cyberimpact::subscribe(
		array_merge( $fluffless_contact, array( 'language' => 'en_US' ) ),
		array(
			'list'         => '12,34',
			'double_optin' => false,
			'key'          => $fluffless_cyber_key,
		)
	);
	$fluffless_assert( array( 'unsubscribed', 'add', 'consent' ) === array_column( $fluffless_reqs, 'step' ), 'Cyberimpact sans confirmation : désabonnement vérifié, ajout, consentement' );
	$fluffless_assert( 'GET' === $fluffless_reqs[0]['method'] && str_ends_with( $fluffless_reqs[0]['url'], '/members/unsubscribed/lea%2Btest%40example.com' ) && '' === $fluffless_reqs[0]['body'], 'Cyberimpact : adresse encodée dans le chemin' );
	$fluffless_assert( 'en_ca' === ( json_decode( $fluffless_reqs[1]['body'], true )['language'] ?? '' ), 'Cyberimpact : langue anglaise' );
	$fluffless_consent = json_decode( $fluffless_reqs[2]['body'], true );
	$fluffless_assert( 'PUT' === $fluffless_reqs[2]['method'] && 'express-consent' === ( $fluffless_consent['relationType'] ?? '' ) && '2025-10-09' === ( $fluffless_consent['consentDate'] ?? '' ), 'Cyberimpact : consentement exprès daté ' . wp_json_encode( $fluffless_consent ) );
	// Date du consentement dans le fuseau du site : 22 h à Montréal reste le jour même.
	$fluffless_zone = get_option( 'timezone_string' );
	update_option( 'timezone_string', 'America/Toronto' );
	$fluffless_late = Cyberimpact::subscribe(
		array_merge( $fluffless_contact, array( 'time' => 1760061600 ) ),
		array(
			'list'         => '12',
			'double_optin' => false,
			'key'          => $fluffless_cyber_key,
		)
	);
	update_option( 'timezone_string', $fluffless_zone );
	$fluffless_assert( '2025-10-09' === ( json_decode( $fluffless_late[2]['body'], true )['consentDate'] ?? '' ), 'Cyberimpact : date du consentement en UTC plutôt que dans le fuseau du site' );
	$fluffless_assert( str_contains( (string) ( $fluffless_consent['consentProof'] ?? '' ), 'J’accepte de recevoir l’infolettre.' ) && str_contains( (string) ( $fluffless_consent['consentProof'] ?? '' ), 'https://exemple.test/infolettre/' ), 'Cyberimpact : preuve sans texte de la case ou page' );
	$fluffless_assert(
		'GET' === Cyberimpact::check(
			array(
				'list'         => '12,34',
				'double_optin' => true,
				'key'          => $fluffless_cyber_key,
			)
		)['method'] && str_ends_with(
			Cyberimpact::check(
				array(
					'list'         => '12,34',
					'double_optin' => true,
					'key'          => $fluffless_cyber_key,
				)
			)['url'],
			'/groups/12'
		),
		'Cyberimpact : test sur le premier groupe, en lecture'
	);

	// 4. Cyberimpact : sens des réponses.
	$fluffless_assert( Reason::UNSUBSCRIBED === Cyberimpact::outcome( 'unsubscribed', 200, array() ), 'désabonnée : arrêt' );
	$fluffless_assert( Provider::NEXT === Cyberimpact::outcome( 'unsubscribed', 404, array() ), 'jamais désabonnée : suite' );
	$fluffless_assert( Provider::NEXT === Cyberimpact::outcome( 'add', 201, array() ), 'ajout : suite' );
	$fluffless_assert( Provider::DONE === Cyberimpact::outcome( 'consent', 200, array() ), 'consentement : fini' );
	$fluffless_assert( Provider::DONE === Cyberimpact::outcome( 'consent', 422, array() ), 'consentement déjà fixé : fini' );
	$fluffless_assert( Provider::DONE === Cyberimpact::outcome( 'optin', 201, array() ), 'optin : fini' );
	$fluffless_assert( Reason::KEY === Cyberimpact::outcome( 'optin', 401, array() ), '401 : clé' );
	$fluffless_assert( Reason::LIST === Cyberimpact::outcome( 'optin', 404, array() ), '404 : groupe' );
	$fluffless_assert( Reason::THROTTLED === Cyberimpact::outcome( 'add', 429, array() ), '429 : trop de requêtes' );
	$fluffless_assert( Reason::UNREACHABLE === Cyberimpact::outcome( 'add', 503, array() ), '503 : injoignable' );
	$fluffless_assert( Reason::INVALID === Cyberimpact::outcome( 'add', 400, array() ), '400 : refus' );

	// 5. Mailchimp : clé, hôte, requête qui crée seulement.
	$fluffless_assert( Mailchimp::valid_key( $fluffless_chimp_key ) && 'us21.api.mailchimp.com' === Mailchimp::host( $fluffless_chimp_key ), 'Mailchimp : centre de données tiré de la clé' );
	$fluffless_assert( '' === Mailchimp::host( 'abc-us21.evil.example/x' ) && ! Mailchimp::valid_key( str_repeat( 'a', 32 ) . '-us21.evil' ), 'Mailchimp : clé mal formée acceptée' );
	$fluffless_reqs = Mailchimp::subscribe(
		$fluffless_contact,
		array(
			'list'         => 'a1b2c3d4e5',
			'double_optin' => true,
			'key'          => $fluffless_chimp_key,
		)
	);
	$fluffless_body = json_decode( $fluffless_reqs[0]['body'], true );
	$fluffless_assert( 1 === count( $fluffless_reqs ) && 'POST' === $fluffless_reqs[0]['method'] && 'https://us21.api.mailchimp.com/3.0/lists/a1b2c3d4e5/members' === $fluffless_reqs[0]['url'], 'Mailchimp : un POST qui crée seulement, jamais PUT' );
	$fluffless_assert( 'Basic ' . base64_encode( 'fluffless:' . $fluffless_chimp_key ) === $fluffless_reqs[0]['headers']['Authorization'], 'Mailchimp : authentification Basic' );
	$fluffless_assert( 'pending' === ( $fluffless_body['status'] ?? '' ) && ! isset( $fluffless_body['status_if_new'] ), 'Mailchimp : en attente de confirmation' );
	$fluffless_assert( array( 'FNAME' => 'Léa' ) === ( $fluffless_body['merge_fields'] ?? null ), 'Mailchimp : nom vide transmis' );
	$fluffless_assert( 'fr_CA' === ( $fluffless_body['language'] ?? '' ) && '2025-10-09T08:53:20+00:00' === ( $fluffless_body['timestamp_signup'] ?? '' ), 'Mailchimp : langue ou date ' . wp_json_encode( $fluffless_body ) );
	$fluffless_assert( 'fr' === Mailchimp::language( 'fr_FR' ) && 'en' === Mailchimp::language( 'en_CA' ), 'Mailchimp : langues' );
	$fluffless_body = json_decode(
		Mailchimp::subscribe(
			array_merge( $fluffless_contact, array( 'first' => '' ) ),
			array(
				'list'         => 'a1b2c3d4e5',
				'double_optin' => false,
				'key'          => $fluffless_chimp_key,
			)
		)[0]['body'],
		true
	);
	$fluffless_assert( 'subscribed' === ( $fluffless_body['status'] ?? '' ) && ! isset( $fluffless_body['merge_fields'] ), 'Mailchimp sans confirmation : abonnée, sans champs vides' );
	$fluffless_assert(
		'' === Mailchimp::subscribe(
			$fluffless_contact,
			array(
				'list'         => 'a1b2c3d4e5',
				'double_optin' => true,
				'key'          => 'mauvaise',
			)
		)[0]['url'],
		'Mailchimp : adresse construite sans clé valide'
	);
	$fluffless_assert( Provider::DONE === Mailchimp::outcome( 'add', 400, array( 'title' => 'Member Exists' ) ), 'Mailchimp : déjà présente laissée telle quelle' );
	$fluffless_assert( Reason::UNSUBSCRIBED === Mailchimp::outcome( 'add', 400, array( 'title' => 'Member In Compliance State' ) ), 'Mailchimp : désabonnée' );
	$fluffless_assert( Reason::UNSUBSCRIBED === Mailchimp::outcome( 'add', 400, array( 'title' => 'Forgotten Email Not Subscribed' ) ), 'Mailchimp : effacée' );
	$fluffless_assert( Reason::INVALID === Mailchimp::outcome( 'add', 400, array( 'title' => 'Invalid Resource' ) ), 'Mailchimp : autre refus' );
	$fluffless_assert( Reason::KEY === Mailchimp::outcome( 'add', 401, array() ) && Reason::LIST === Mailchimp::outcome( 'add', 404, array() ), 'Mailchimp : clé, audience' );

	// 6. Modèle Infolettre : tout réglé d'office.
	$fluffless_def = $fluffless_pattern();
	$fluffless_assert( $fluffless_def['newsletter'] && 'consentement' === $fluffless_def['newsletter_consent'] && ! $fluffless_def['confirm'], 'modèle : liaison, case désignée, sans copie' );
	$fluffless_assert( 'given-name' === $fluffless_def['fields'][0]['autocomplete'], 'modèle : prénom sans autocomplete given-name' );
	$fluffless_consent_field = $fluffless_def['fields'][2];
	$fluffless_assert( 'consent' === $fluffless_consent_field['type'] && $fluffless_consent_field['required'], 'modèle : case de consentement obligatoire' );

	// 7. Qui part : seulement la case désignée, obligatoire et cochée.
	$fluffless_values       = array(
		'prenom'       => ' Léa ',
		'courriel'     => 'lea@example.com',
		'consentement' => 'Yes',
	);
	$_SERVER['REQUEST_URI'] = '/infolettre/?utm_source=x';
	$fluffless_c            = Newsletter::contact( $fluffless_def, $fluffless_values );
	$fluffless_assert( null !== $fluffless_c && 'lea@example.com' === $fluffless_c['email'] && 'Léa' === $fluffless_c['first'] && '' === $fluffless_c['last'], 'contact : courriel ou prénom' );
	$fluffless_assert( null !== $fluffless_c && home_url( '/infolettre/' ) === $fluffless_c['page'], 'contact : page sans paramètres ' . ( $fluffless_c['page'] ?? '' ) );
	$fluffless_assert( null !== $fluffless_c && $fluffless_consent_field['label'] === $fluffless_c['consent'], 'contact : texte de la case' );
	// Permaliens simples : la requête identifie la page, la preuve la garde (X5-114).
	add_filter( 'pre_option_permalink_structure', '__return_empty_string' );
	$_SERVER['REQUEST_URI'] = '/?page_id=6';
	$fluffless_plain        = Newsletter::contact( $fluffless_def, $fluffless_values );
	remove_filter( 'pre_option_permalink_structure', '__return_empty_string' );
	$_SERVER['REQUEST_URI'] = '/infolettre/?utm_source=x';
	$fluffless_assert( null !== $fluffless_plain && home_url( '/?page_id=6' ) === $fluffless_plain['page'], 'contact en permaliens simples : page ' . ( $fluffless_plain['page'] ?? '' ) );
	$fluffless_assert( null === Newsletter::contact( $fluffless_def, array_merge( $fluffless_values, array( 'consentement' => '' ) ) ), 'case décochée : envoyé quand même' );
	$fluffless_assert( null === Newsletter::contact( array_merge( $fluffless_def, array( 'newsletter' => false ) ), $fluffless_values ), 'liaison éteinte : envoyé quand même' );
	$fluffless_assert( null === Newsletter::contact( array_merge( $fluffless_def, array( 'newsletter_consent' => '' ) ), $fluffless_values ), 'sans case désignée : envoyé quand même' );
	$fluffless_assert( null === Newsletter::contact( array_merge( $fluffless_def, array( 'newsletter_consent' => 'courriel' ) ), $fluffless_values ), 'champ désigné qui n\'est pas une case : envoyé quand même' );
	// Case facultative (formulaire de contact) : seule la case cochée compte.
	$fluffless_optional                          = $fluffless_def;
	$fluffless_optional['fields'][2]['required'] = false;
	$fluffless_assert( null !== Newsletter::contact( $fluffless_optional, $fluffless_values ), 'case facultative cochée : rien envoyé' ); // @phpstan-ignore argument.type (définition modifiée pour le test)
	$fluffless_assert( null === Newsletter::contact( $fluffless_optional, array_merge( $fluffless_values, array( 'consentement' => '' ) ) ), 'case facultative décochée : envoyé quand même' ); // @phpstan-ignore argument.type (définition modifiée pour le test)
	$fluffless_assert( null === Newsletter::contact( $fluffless_def, array_merge( $fluffless_values, array( 'courriel' => '' ) ) ), 'sans courriel : envoyé quand même' );
	// Une autre case (politique de confidentialité) cochée seule ne vaut pas inscription.
	$fluffless_two = Form::definition_from_blocks(
		array(
			'formId'            => 'deux1234',
			'newsletter'        => true,
			'newsletterConsent' => 'infolettre',
		),
		parse_blocks(
			'<!-- wp:fluffless/champ {"type":"text","label":"Nom","name":"nom","autocomplete":"family-name"} /-->'
			. '<!-- wp:fluffless/champ {"type":"email","label":"Courriel","name":"courriel","required":true} /-->'
			. '<!-- wp:fluffless/champ {"type":"consent","label":"J’accepte la politique","name":"politique","required":true} /-->'
			. '<!-- wp:fluffless/champ {"type":"consent","label":"Infolettre","name":"infolettre","required":true} /-->'
		)
	);
	$fluffless_assert(
		null === Newsletter::contact(
			$fluffless_two,
			array(
				'courriel'   => 'a@example.com',
				'politique'  => 'Yes',
				'infolettre' => '',
			)
		),
		'politique cochée seule : envoyé quand même'
	);
	$fluffless_c = Newsletter::contact(
		$fluffless_two,
		array(
			'nom'        => 'Tremblay',
			'courriel'   => 'a@example.com',
			'politique'  => 'Yes',
			'infolettre' => 'Yes',
		)
	);
	$fluffless_assert( null !== $fluffless_c && 'Tremblay' === $fluffless_c['last'], 'nom par autocomplete family-name' );
	// Un champ « nom » sans prénom contient souvent le nom complet : il ne devient pas le nom de famille.
	$fluffless_names = static function ( string $blocks ) use ( $fluffless_consent_field ): ?array {
		return Newsletter::contact(
			Form::definition_from_blocks(
				array(
					'formId'            => 'noms1234',
					'newsletter'        => true,
					'newsletterConsent' => 'infolettre',
				),
				parse_blocks(
					$blocks
					. '<!-- wp:fluffless/champ {"type":"email","label":"Courriel","name":"courriel","required":true} /-->'
					. '<!-- wp:fluffless/champ {"type":"consent","label":"' . $fluffless_consent_field['label'] . '","name":"infolettre"} /-->'
				)
			),
			array(
				'prenom'     => 'Léa',
				'nom'        => 'Léa Tremblay',
				'courriel'   => 'a@example.com',
				'infolettre' => 'Yes',
			)
		);
	};
	$fluffless_alone = $fluffless_names( '<!-- wp:fluffless/champ {"type":"text","label":"Nom","name":"nom"} /-->' );
	$fluffless_assert( null !== $fluffless_alone && '' === $fluffless_alone['last'] && '' === $fluffless_alone['first'], 'champ « nom » seul pris pour le nom de famille' );
	$fluffless_pair = $fluffless_names( '<!-- wp:fluffless/champ {"type":"text","label":"Prénom","name":"prenom"} /--><!-- wp:fluffless/champ {"type":"text","label":"Nom","name":"nom"} /-->' );
	$fluffless_assert( null !== $fluffless_pair && 'Léa' === $fluffless_pair['first'] && 'Léa Tremblay' === $fluffless_pair['last'], 'prénom et nom : nom de famille absent' );
	$fluffless_assert( 'abc,12x' === Form::form_attributes( array( 'newsletterList' => 'abc, 12;"<x>' ) )['newsletter_list'], 'liste du formulaire non assainie' );

	// 8. De bout en bout : envoi du formulaire, appel après coup, rien sans clé.
	$fluffless_set(
		array(
			'provider'     => 'cyberimpact',
			'list'         => '12',
			'double_optin' => true,
		)
	);
	$fluffless_assert( '' !== Newsletter::status(), 'état sans clé muet' );
	Newsletter::flush();
	/** @var list<array{url: string, args: array<string, mixed>}> $fluffless_requests */
	$fluffless_requests = array();
	$fluffless_assert( Reason::KEY === Newsletter::subscribe( $fluffless_contact ), 'sans clé : raison clé' );
	$fluffless_assert( array() === $fluffless_requests, 'sans clé : requête partie' );
	putenv( Newsletter::KEY_CONSTANT . '=' . $fluffless_cyber_key );
	$fluffless_assert( '' === Newsletter::status(), 'état avec clé et groupe : ' . Newsletter::status() );

	$fluffless_def['key'] = 'infolettre-test';
	Form::remember( $fluffless_def );
	add_action( 'fluffless_form_sent', array( Newsletter::class, 'on_sent' ), 10, 2 );
	$fluffless_post         = array(
		Form::FIELD_KEY   => 'infolettre-test',
		Form::FIELD_TOKEN => Form::token( 'infolettre-test', time() - 10 ),
		Form::FIELD_DATA  => array(
			'prenom'       => 'Léa',
			'courriel'     => 'lea@example.com',
			'consentement' => '1',
		),
	);
	$_SERVER['REMOTE_ADDR'] = $fluffless_ip;
	/** @var list<array<string, mixed>> $fluffless_mails */
	$fluffless_mails = array();
	$fluffless_r     = Form::process( $fluffless_post, $fluffless_ip );
	$fluffless_assert( $fluffless_r['ok'] && 1 === count( $fluffless_mails ), 'formulaire non envoyé : ' . $fluffless_r['message'] );
	$fluffless_assert( array() === $fluffless_requests, 'appel parti avant la fin de la requête' );
	Newsletter::flush();
	$fluffless_assert( 1 === count( $fluffless_requests ) && 'https://api.cyberimpact.com/members/optins' === $fluffless_requests[0]['url'], 'appel à Cyberimpact absent' );
	$fluffless_args = $fluffless_requests[0]['args'] ?? array();
	$fluffless_assert( 0 === ( $fluffless_args['redirection'] ?? -1 ) && ( $fluffless_args['timeout'] ?? 99 ) <= Newsletter::DEADLINE && true === ( $fluffless_args['reject_unsafe_urls'] ?? false ), 'appel : redirection suivie, délai ou adresse non vérifiée' );
	$fluffless_assert( ! str_contains( (string) wp_json_encode( $fluffless_requests ), $fluffless_ip ), 'adresse IP transmise' );
	$fluffless_assert( 'lea@example.com' === ( json_decode( (string) ( $fluffless_args['body'] ?? '' ), true )['email'] ?? '' ), 'courriel non transmis' );
	delete_transient( Form::rate_key( $fluffless_ip ) );

	// Pot de miel : rien ne part.
	/** @var list<array{url: string, args: array<string, mixed>}> $fluffless_requests */
	$fluffless_requests = array();
	Form::process( array_merge( $fluffless_post, array( Form::HONEYPOT => 'robot' ) ), $fluffless_ip );
	Newsletter::flush();
	$fluffless_assert( array() === $fluffless_requests, 'pot de miel transmis à l\'outil' );
	delete_transient( Form::rate_key( $fluffless_ip ) );

	// Case décochée dans un vrai envoi : courriel au site, rien à l'outil.
	$fluffless_def['fields'][2]['required'] = false;
	$fluffless_def['key']                   = 'infolettre-libre';
	Form::remember( $fluffless_def ); // @phpstan-ignore argument.type (définition modifiée pour le test)
	/** @var list<array{url: string, args: array<string, mixed>}> $fluffless_requests */
	$fluffless_requests = array();
	Form::process(
		array_merge(
			$fluffless_post,
			array(
				Form::FIELD_KEY   => 'infolettre-libre',
				Form::FIELD_TOKEN => Form::token( 'infolettre-libre', time() - 10 ),
				Form::FIELD_DATA  => array(
					'prenom'   => 'Léa',
					'courriel' => 'lea@example.com',
				),
			)
		),
		$fluffless_ip
	);
	Newsletter::flush();
	$fluffless_assert( array() === $fluffless_requests, 'case décochée transmise à l\'outil' );
	delete_transient( Form::rate_key( $fluffless_ip ) );
	remove_action( 'fluffless_form_sent', array( Newsletter::class, 'on_sent' ), 10 );

	// Liste propre au formulaire.
	/** @var list<array{url: string, args: array<string, mixed>}> $fluffless_requests */
	$fluffless_requests = array();
	Newsletter::subscribe( $fluffless_contact, '34' );
	$fluffless_assert( '34' === ( json_decode( (string) ( $fluffless_requests[0]['args']['body'] ?? '' ), true )['groups'] ?? '' ), 'liste du formulaire ignorée' );
	/** @var list<array{url: string, args: array<string, mixed>}> $fluffless_requests */
	$fluffless_requests = array();
	$fluffless_assert( Reason::LIST === Newsletter::subscribe( $fluffless_contact, 'abc' ) && array() === $fluffless_requests, 'liste du formulaire mal formée : envoyée quand même' );

	// Sans double confirmation : désabonnée, on s'arrête après la vérification.
	$fluffless_set(
		array(
			'provider'     => 'cyberimpact',
			'list'         => '12',
			'double_optin' => false,
		)
	);
	/** @var list<array{url: string, args: array<string, mixed>}> $fluffless_requests */
	$fluffless_requests = array();
	/** @var list<array{0: int, 1: array<string, mixed>}|WP_Error> $fluffless_replies */
	$fluffless_replies = array( array( 200, array( 'email' => 'lea+test@example.com' ) ) );
	$fluffless_assert( Reason::UNSUBSCRIBED === Newsletter::subscribe( $fluffless_contact ) && 1 === count( $fluffless_requests ), 'désabonnée réinscrite' );
	/** @var list<array{url: string, args: array<string, mixed>}> $fluffless_requests */
	$fluffless_requests = array();
	/** @var list<array{0: int, 1: array<string, mixed>}|WP_Error> $fluffless_replies */
	$fluffless_replies = array( array( 404, array() ), array( 201, array() ), array( 200, array() ) );
	$fluffless_assert( Provider::DONE === Newsletter::subscribe( $fluffless_contact ) && 3 === count( $fluffless_requests ), 'inscription sans confirmation incomplète' );
	/** @var list<array{0: int, 1: array<string, mixed>}|WP_Error> $fluffless_replies */
	$fluffless_replies = array( array( 404, array() ), array( 201, array() ), array( 500, array() ) );
	$fluffless_assert( Reason::NO_PROOF === Newsletter::subscribe( $fluffless_contact ), 'ajoutée sans preuve : raison propre attendue' );
	/** @var list<array{0: int, 1: array<string, mixed>}|WP_Error> $fluffless_replies */
	$fluffless_replies = array( array( 404, array() ), array( 409, array() ) );
	$fluffless_assert( Reason::INVALID === Newsletter::subscribe( $fluffless_contact ), 'ajout refusé pris pour une preuve manquante' );

	// 9. Échecs : courriel regroupé, une heure de pause, rien gardé après l'envoi.
	Newsletter::send_digest();
	delete_transient( Lifecycle::TRANSIENT_NEWSLETTER_LOCK );
	delete_transient( Lifecycle::TRANSIENT_NEWSLETTER_FAILURES );
	wp_clear_scheduled_hook( Newsletter::CRON_HOOK );
	$fluffless_set(
		array(
			'provider'     => 'cyberimpact',
			'list'         => '12',
			'double_optin' => true,
		)
	);
	add_action( 'fluffless_form_sent', array( Newsletter::class, 'on_sent' ), 10, 2 );
	/** @var list<array<string, mixed>> $fluffless_mails */
	$fluffless_mails = array();
	/** @var list<array{url: string, args: array<string, mixed>}> $fluffless_requests */
	$fluffless_requests = array();
	/** @var list<array{0: int, 1: array<string, mixed>}|WP_Error> $fluffless_replies */
	$fluffless_replies = array( array( 401, array( 'message' => 'Invalid token for lea@example.com' ) ) );
	Newsletter::on_sent( $fluffless_pattern(), $fluffless_values );
	Newsletter::flush();
	remove_action( 'fluffless_form_sent', array( Newsletter::class, 'on_sent' ), 10 );
	$fluffless_failed = Newsletter::failures();
	$fluffless_assert( 1 === count( $fluffless_failed ) && Reason::KEY === $fluffless_failed[0]['reason'], 'échec non noté' );
	$fluffless_assert( ! str_contains( (string) wp_json_encode( get_transient( Lifecycle::TRANSIENT_NEWSLETTER_FAILURES ) ), '@' ), 'adresse gardée avec les échecs' );
	$fluffless_assert( 1 === count( $fluffless_mails ) && str_contains( (string) $fluffless_mails[0]['message'], 'lea@example.com' ) && str_contains( (string) $fluffless_mails[0]['message'], 'Invite these people to confirm' ), 'premier échec : courriel au site absent ou incomplet' );
	$fluffless_assert( ! str_contains( (string) $fluffless_mails[0]['message'], 'Invalid token' ), 'texte brut de l\'outil dans le courriel' );
	$fluffless_assert( array() === Newsletter::pending() && false !== get_transient( Lifecycle::TRANSIENT_NEWSLETTER_LOCK ), 'après le courriel : adresses gardées ou pause absente' );
	/** @var list<array<string, mixed>> $fluffless_mails */
	$fluffless_mails = array();
	/** @var list<array{0: int, 1: array<string, mixed>}|WP_Error> $fluffless_replies */
	$fluffless_replies = array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
	Newsletter::record_failure( 'zoe@example.com', Newsletter::subscribe( $fluffless_contact ) );
	$fluffless_assert( array() === $fluffless_mails, 'second échec dans l\'heure : courriel envoyé' );
	$fluffless_assert( 1 === count( Newsletter::pending() ) && Reason::UNREACHABLE === Newsletter::pending()[0]['reason'], 'délai dépassé : raison injoignable attendue' );
	$fluffless_assert( false !== wp_next_scheduled( Newsletter::CRON_HOOK ), 'envoi différé non planifié' );
	Newsletter::send_digest();
	$fluffless_assert( 1 === count( $fluffless_mails ) && str_contains( (string) $fluffless_mails[0]['message'], 'zoe@example.com' ) && array() === Newsletter::pending(), 'courriel différé absent ou adresses gardées' );
	$fluffless_assert( 2 === count( Newsletter::failures() ), 'compte des échecs' );
	// Courriel au site refusé : les adresses restent pour un nouvel essai, un jour au plus.
	$fluffless_refuse = static fn() => false;
	add_filter( 'pre_wp_mail', $fluffless_refuse, 20 );
	delete_transient( Lifecycle::TRANSIENT_NEWSLETTER_LOCK );
	wp_clear_scheduled_hook( Newsletter::CRON_HOOK );
	Newsletter::record_failure( 'max@example.com', Reason::OTHER );
	$fluffless_assert( 1 === count( Newsletter::pending() ) && false !== wp_next_scheduled( Newsletter::CRON_HOOK ), 'courriel refusé : adresses perdues ou nouvel essai absent' );
	update_option(
		Lifecycle::OPTION_NEWSLETTER_PENDING,
		array(
			array(
				'time'   => time() - 2 * DAY_IN_SECONDS,
				'email'  => 'vieux@example.com',
				'reason' => Reason::OTHER,
			),
		),
		false
	);
	Newsletter::send_digest();
	$fluffless_assert( array() === Newsletter::pending(), 'courriel refusé : adresse de plus d\'un jour gardée' );
	remove_filter( 'pre_wp_mail', $fluffless_refuse, 20 );
	wp_clear_scheduled_hook( Newsletter::CRON_HOOK );

	// 10. Requêtes sortantes : l'hôte de l'outil passe, un autre reste bloqué.
	$fluffless_set(
		array(
			'provider'     => 'cyberimpact',
			'list'         => '12',
			'double_optin' => true,
		),
		true,
		true
	);
	$fluffless_http = new Http();
	$fluffless_assert( false === $fluffless_http->filter( false, array(), 'https://api.cyberimpact.com/members/optins' ), 'Requêtes sortantes bloque Cyberimpact' );
	$fluffless_assert( is_wp_error( $fluffless_http->filter( false, array(), 'https://evil.example/x' ) ), 'Requêtes sortantes laisse passer un autre domaine' );
	$fluffless_set( array( 'provider' => 'cyberimpact' ), false, true );
	$fluffless_assert( is_wp_error( $fluffless_http->filter( false, array(), 'https://api.cyberimpact.com/members' ) ), 'module éteint : Cyberimpact passe quand même' );
	$fluffless_set(
		array(
			'provider' => 'mailchimp',
			'list'     => 'a1b2c3d4e5',
		),
		true,
		true
	);
	putenv( Newsletter::KEY_CONSTANT . '=' . $fluffless_chimp_key );
	$fluffless_assert( false === $fluffless_http->filter( false, array(), 'https://us21.api.mailchimp.com/3.0/lists' ), 'Requêtes sortantes bloque Mailchimp' );
	$fluffless_assert( is_wp_error( $fluffless_http->filter( false, array(), 'https://us22.api.mailchimp.com/3.0/lists' ) ), 'autre centre de données Mailchimp laissé passer' );
	/** @var list<array{0: int, 1: array<string, mixed>}|WP_Error> $fluffless_replies */
	$fluffless_replies = array( new WP_Error( 'fluffless_http_blocked', 'bloqué' ) );
	$fluffless_assert( Reason::BLOCKED === Newsletter::subscribe( $fluffless_contact ), 'refus de Requêtes sortantes : raison' );
	putenv( Newsletter::KEY_CONSTANT . '=pas-une-cle' );
	/** @var list<array{url: string, args: array<string, mixed>}> $fluffless_requests */
	$fluffless_requests = array();
	$fluffless_assert( Reason::KEY === Newsletter::subscribe( $fluffless_contact ) && array() === $fluffless_requests, 'clé Mailchimp mal formée : requête partie' );
	$fluffless_assert( str_contains( Newsletter::status(), 'Mailchimp' ), 'état : clé mal formée non signalée' );

	// 11. Test de connexion : lecture seule, aucune inscription.
	putenv( Newsletter::KEY_CONSTANT . '=' . $fluffless_chimp_key );
	/** @var list<array{url: string, args: array<string, mixed>}> $fluffless_requests */
	$fluffless_requests = array();
	/** @var list<array{0: int, 1: array<string, mixed>}|WP_Error> $fluffless_replies */
	$fluffless_replies = array( array( 200, array( 'id' => 'a1b2c3d4e5' ) ) );
	$fluffless_test    = Newsletter::test();
	$fluffless_assert( Provider::DONE === $fluffless_test['outcome'] && 1 === count( $fluffless_requests ) && 'GET' === ( $fluffless_requests[0]['args']['method'] ?? '' ), 'test : réussite ou méthode' );
	/** @var list<array{0: int, 1: array<string, mixed>}|WP_Error> $fluffless_replies */
	$fluffless_replies = array( array( 404, array() ) );
	$fluffless_assert( Reason::LIST === Newsletter::test()['outcome'] && Reason::LIST === ( Newsletter::last_test()['outcome'] ?? '' ), 'test : audience introuvable' );
	ob_start();
	Newsletter::render_panel();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_html, 'action=' . Newsletter::ACTION ) && str_contains( $fluffless_html, '_wpnonce=' ), 'panneau : bouton de test sans nonce' );
	$fluffless_assert( ! str_contains( $fluffless_html, $fluffless_chimp_key ) && ! str_contains( $fluffless_html, '@example.com' ), 'panneau : clé ou adresse affichée' );

	// 12. Politique de confidentialité, branchements, nettoyage.
	$fluffless_assert( str_contains( Newsletter::privacy_text(), 'Mailchimp' ) && str_contains( Newsletter::privacy_text(), 'United States' ) && str_contains( Newsletter::privacy_text(), 'IP address is not sent' ), 'mention de confidentialité' );
	$fluffless_assert( str_contains( Newsletter::privacy_text(), 'date and time of your sign-up' ) && ! str_contains( Newsletter::privacy_text(), 'wording' ), 'mention Mailchimp : libellé annoncé à tort' );
	$fluffless_assert( str_contains( Newsletter::privacy_text(), 'site administrator' ), 'C3-1-02 : courriel de secours à admin_email absent de la mention' );
	$fluffless_set(
		array(
			'provider'     => 'cyberimpact',
			'list'         => '12',
			'double_optin' => false,
		)
	);
	$fluffless_assert( str_contains( Newsletter::privacy_text(), 'in Canada' ) && str_contains( Newsletter::privacy_text(), 'proof of consent' ) && ! str_contains( Newsletter::privacy_text(), 'link to confirm' ), 'mention Cyberimpact sans confirmation' );
	$fluffless_set(
		array(
			'provider'     => 'cyberimpact',
			'list'         => '12',
			'double_optin' => true,
		)
	);
	$fluffless_assert( str_contains( Newsletter::privacy_text(), 'link to confirm' ) && ! str_contains( Newsletter::privacy_text(), 'proof of consent' ), 'mention Cyberimpact avec confirmation' );
	( new Newsletter() )->register();
	$fluffless_assert( 10 === has_action( 'fluffless_form_sent', array( Newsletter::class, 'on_sent' ) ), 'accroche à fluffless_form_sent absente' );
	$fluffless_assert( false !== has_action( Newsletter::CRON_HOOK, array( Newsletter::class, 'send_digest' ) ), 'envoi différé non branché' );
	remove_action( 'fluffless_form_sent', array( Newsletter::class, 'on_sent' ), 10 );
	$fluffless_transients = ( new ReflectionClassConstant( Lifecycle::class, 'TRANSIENTS' ) )->getValue();
	$fluffless_assert( in_array( Lifecycle::TRANSIENT_NEWSLETTER_FAILURES, $fluffless_transients, true ) && in_array( Lifecycle::TRANSIENT_NEWSLETTER_LOCK, $fluffless_transients, true ), 'transitoires non nettoyés à la désactivation' );
	Newsletter::record_failure( 'x@example.com', Reason::OTHER );
	wp_schedule_single_event( time() + HOUR_IN_SECONDS, Newsletter::CRON_HOOK );
	update_option(
		Lifecycle::OPTION_NEWSLETTER_PENDING,
		array(
			array(
				'time'   => time(),
				'email'  => 'x@example.com',
				'reason' => Reason::OTHER,
			),
		),
		false
	);
	Lifecycle::deactivate();
	$fluffless_assert( false === get_option( Lifecycle::OPTION_NEWSLETTER_PENDING ) && false === wp_next_scheduled( Newsletter::CRON_HOOK ), 'désactivation : adresses ou envoi différé restés' );
} finally {
	putenv( Newsletter::KEY_CONSTANT );
	remove_filter( 'pre_http_request', $fluffless_network, 5 );
	remove_filter( 'pre_wp_mail', $fluffless_capture, 10 );
	if ( false === $fluffless_backup_option ) {
		delete_option( Options::NAME );
	} else {
		update_option( Options::NAME, $fluffless_backup_option );
	}
	Options::flush();
	delete_option( Lifecycle::OPTION_NEWSLETTER_PENDING );
	delete_transient( Lifecycle::TRANSIENT_NEWSLETTER_FAILURES );
	delete_transient( Lifecycle::TRANSIENT_NEWSLETTER_TEST );
	delete_transient( Lifecycle::TRANSIENT_NEWSLETTER_LOCK );
	wp_clear_scheduled_hook( Newsletter::CRON_HOOK );
	delete_transient( Form::rate_key( $fluffless_ip ) );
}

if ( $fluffless_failures ) {
	echo esc_html( "Échecs :\n- " . implode( "\n- ", $fluffless_failures ) . "\n" );
	exit( 1 );
}
echo esc_html( "OK : Infolettre, {$fluffless_checks} vérifications réussies.\n" );
