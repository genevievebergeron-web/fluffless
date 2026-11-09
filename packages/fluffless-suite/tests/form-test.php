<?php
/**
 * Tests du module Formulaire : définition relue des blocs, mémoire côté serveur, jeton signé,
 * pot de miel, délai, plafond par adresse, validation par type, courriel et copie, rendu
 * accessible (erreurs liées aux champs) et état « envoyé ».
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/form-test.php
 * Les réglages, l'option des formulaires et les transitoires sont remis en état à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Form\Altcha;
use Fluffless\Suite\Modules\Form;

defined( 'ABSPATH' ) || exit;

// Les contrôles lisent les textes en en_US : indépendants de la langue du site (le passage en fr_CA
// plus bas part ainsi toujours d'une autre langue).
switch_to_locale( 'en_US' );

if ( ! class_exists( Form::class ) ) {
	throw new RuntimeException( 'Module Formulaire absent.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_original = get_option( Options::NAME );
$fluffless_forms    = get_option( Form::OPTION );
$fluffless_set      = static function ( array $section ): void {
	update_option( Options::NAME, Options::sanitize( array( 'form' => $section ) ) );
	Options::flush();
};

$fluffless_mails   = array();
$fluffless_fail    = false;
$fluffless_capture = static function ( $short, array $atts ) use ( &$fluffless_mails, &$fluffless_fail ) {
	unset( $short );
	$fluffless_mails[] = $atts;
	return ! $fluffless_fail; // @phpstan-ignore booleanNot.alwaysTrue
};
add_filter( 'pre_wp_mail', $fluffless_capture, 10, 2 );

$fluffless_registry = WP_Block_Type_Registry::get_instance();
$fluffless_had      = $fluffless_registry->is_registered( Form::FORM );
if ( ! $fluffless_had ) {
	Form::register_blocks();
}
$fluffless_ip     = '203.0.113.7';
$fluffless_server = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sauvegarde brute, remise telle quelle en fin de test.

$fluffless_content = '<!-- wp:fluffless/formulaire {"formId":"abcd1234","recipients":"a@example.com, a@example.com pas-une-adresse b@example.com","subject":"Devis"} -->'
	. '<!-- wp:fluffless/champ {"type":"text","label":"Nom","required":true} /-->'
	. '<!-- wp:core/group --><div class="wp-block-group">'
	. '<!-- wp:fluffless/champ {"type":"email","label":"Courriel","required":true,"help":"Pour la réponse."} /-->'
	. '</div><!-- /wp:core/group -->'
	. '<!-- wp:fluffless/champ {"type":"radio","label":"Sujet","options":"Devis\nAutre","required":true} /-->'
	. '<!-- wp:fluffless/champ {"type":"checkboxes","label":"Intérêts","options":"A\nB\nA"} /-->'
	. '<!-- wp:fluffless/champ {"type":"select","label":"Ville","options":"Québec\nMontréal"} /-->'
	. '<!-- wp:fluffless/champ {"type":"textarea","label":"Message"} /-->'
	. '<!-- wp:fluffless/champ {"type":"consent","label":"J\'accepte d\'être contacté","required":true} /-->'
	. '<!-- wp:fluffless/champ {"type":"hidden","name":"source","value":"page-x"} /-->'
	. '<!-- /wp:fluffless/formulaire -->';

try {
	Form::forget();
	delete_transient( Form::COPY_PREFIX . gmdate( 'Y-m-d' ) ); // Plafond global des copies : chaque passage repart de zéro.
	$fluffless_set( Form::defaults() );
	$_SERVER['REQUEST_URI'] = '/contact/?x=1';

	// 1. Contrat du module et réglages bornés.
	$fluffless_assert( 'form' === Form::id() && Form::enabled_by_default(), 'identifiant ou activation par défaut' );
	$fluffless_assert( Loader::is_active( 'form' ), 'module inactif' );
	$fluffless_s = Form::sanitize(
		array(
			'min_delay' => '7',
			'rate'      => '0',
		)
	);
	$fluffless_assert( 7 === $fluffless_s['min_delay'] && 1 === $fluffless_s['rate'], 'assainissement des entiers' );
	// 2,75 ko : la flèche du select et son repli en couleurs forcées (C1-7-01) ajoutent environ 280 octets.
	$fluffless_assert( strlen( Form::css() ) < 2816 && ! str_contains( Form::css(), '<' ), 'feuille trop lourde : ' . strlen( Form::css() ) );

	// 2. Attributs du parent : identifiant nettoyé, destinataires validés sans doublon, copie par défaut.
	$fluffless_f = Form::form_attributes(
		array(
			'formId'     => 'AB-12_cd!!',
			'recipients' => 'x@example.com;x@example.com nope',
		)
	);
	$fluffless_assert( 'ab12cd' === $fluffless_f['id'], 'identifiant : ' . $fluffless_f['id'] );
	$fluffless_assert( array( 'x@example.com' ) === $fluffless_f['recipients'], 'destinataires' );
	$fluffless_assert( $fluffless_f['confirm'], 'copie par défaut' );
	$fluffless_assert( 'form' === Form::form_attributes( array( 'formId' => 'ab' ) )['id'], 'identifiant trop court : repli' );

	// 3. Champ : type inconnu, nom tiré du libellé, unicité, options, noms réservés.
	$fluffless_names = array();
	$fluffless_c     = Form::field(
		array(
			'type'     => 'file',
			'label'    => '<b>Prénom</b>',
			'required' => '1',
		),
		$fluffless_names
	);
	$fluffless_assert( 'text' === $fluffless_c['type'] && 'Prénom' === $fluffless_c['label'] && 'prenom' === $fluffless_c['name'] && $fluffless_c['required'], 'champ de base' );
	$fluffless_c = Form::field( array( 'label' => 'Prénom' ), $fluffless_names );
	$fluffless_assert( 'prenom-2' === $fluffless_c['name'], 'nom en double non suffixé' );
	$fluffless_c = Form::field(
		array(
			'name'  => Form::HONEYPOT,
			'label' => 'x',
		),
		$fluffless_names
	);
	$fluffless_assert( 'field' === $fluffless_c['name'], 'nom réservé accepté' );
	$fluffless_c = Form::field(
		array(
			'type'    => 'select',
			'options' => " A \n\nB\nA\n<i>C</i>",
		),
		$fluffless_names
	);
	$fluffless_assert( array( 'A', 'B', 'C' ) === $fluffless_c['options'], 'options : ' . implode( '|', $fluffless_c['options'] ) );
	$fluffless_c = Form::field(
		array(
			'type'    => 'text',
			'options' => "A\nB",
		),
		$fluffless_names
	);
	$fluffless_assert( array() === $fluffless_c['options'], 'options gardées sur un type sans choix' );
	$fluffless_c = Form::field( array( 'type' => 'consent' ), $fluffless_names );
	$fluffless_assert( '' !== $fluffless_c['label'], 'consentement sans libellé par défaut' );
	// Libellé long (texte de consentement Loi 25, 400 caractères et plus) : gardé en entier jusqu'au plafond.
	$fluffless_long = str_repeat( 'J’accepte que mes renseignements servent à répondre à ma demande. ', 8 ) . 'Fin.';
	$fluffless_c    = Form::field(
		array(
			'type'  => 'consent',
			'label' => $fluffless_long,
		),
		$fluffless_names
	);
	$fluffless_assert( mb_strlen( $fluffless_long ) > 400 && $fluffless_long === $fluffless_c['label'], 'libellé de consentement long tronqué : ' . mb_strlen( $fluffless_c['label'] ) );
	$fluffless_c = Form::field(
		array(
			'type'  => 'consent',
			'label' => str_repeat( 'a', Form::LABEL_MAX + 50 ),
		),
		$fluffless_names
	);
	$fluffless_assert( Form::LABEL_MAX === mb_strlen( $fluffless_c['label'] ), 'libellé non borné' );
	$fluffless_c = Form::field(
		array(
			'type'    => 'radio',
			'options' => $fluffless_long . "\nCourt",
		),
		$fluffless_names
	);
	$fluffless_assert( array( $fluffless_long, 'Court' ) === $fluffless_c['options'], 'option longue tronquée' );

	// 4. Collecte : groupes traversés, formulaire imbriqué ignoré, composition synchronisée relue.
	$fluffless_ref    = wp_insert_post(
		array(
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_title'   => 'Champ synchronisé',
			'post_content' => '<!-- wp:fluffless/champ {"type":"phone","label":"Téléphone"} /-->',
		)
	);
	$fluffless_blocks = parse_blocks(
		'<!-- wp:core/group --><div class="wp-block-group"><!-- wp:fluffless/champ {"label":"Un"} /--></div><!-- /wp:core/group -->'
		. '<!-- wp:fluffless/formulaire --><!-- wp:fluffless/champ {"label":"Imbriqué"} /--><!-- /wp:fluffless/formulaire -->'
		. '<!-- wp:block {"ref":' . (int) $fluffless_ref . '} /-->'
	);
	$fluffless_names  = array();
	$fluffless_got    = array_column( Form::collect( $fluffless_blocks, $fluffless_names ), 'name' );
	$fluffless_assert( array( 'un', 'telephone' ) === $fluffless_got, 'collecte : ' . implode( '|', $fluffless_got ) );
	wp_delete_post( (int) $fluffless_ref, true );

	// 5. Définition : clé stable, mémoire bornée.
	$fluffless_d1 = Form::definition_from_blocks( array( 'formId' => 'abcd1234' ), $fluffless_blocks );
	$fluffless_d2 = Form::definition_from_blocks( array( 'formId' => 'abcd1234' ), $fluffless_blocks );
	$fluffless_d3 = Form::definition_from_blocks(
		array(
			'formId'  => 'abcd1234',
			'subject' => 'Autre',
		),
		$fluffless_blocks
	);
	$fluffless_assert( $fluffless_d1['key'] === $fluffless_d2['key'] && $fluffless_d1['key'] !== $fluffless_d3['key'], 'clé instable ou aveugle au contenu' );
	$fluffless_assert( str_starts_with( $fluffless_d1['key'], 'abcd1234-' ), 'clé sans identifiant' );
	$fluffless_assert( null === Form::definition( 'inconnue' ), 'définition inconnue non nulle' );
	for ( $fluffless_i = 0; $fluffless_i < Form::MAX_FORMS + 5; $fluffless_i++ ) {
		Form::remember( array_merge( $fluffless_d1, array( 'key' => 'k' . $fluffless_i ) ) );
	}
	$fluffless_all = get_option( Form::OPTION );
	$fluffless_assert( is_array( $fluffless_all ) && count( $fluffless_all ) === Form::MAX_FORMS, 'mémoire non bornée' );
	$fluffless_assert( false === wp_cache_get( 'alloptions', 'options' ) || ! isset( wp_cache_get( 'alloptions', 'options' )[ Form::OPTION ] ), 'option autochargée' );
	Form::forget();

	// 6. Jeton signé et clé de plafond.
	$fluffless_t = Form::token( 'abcd1234-x', 1700000000 );
	$fluffless_assert( 1700000000 === Form::token_time( 'abcd1234-x', $fluffless_t ), 'jeton non relu' );
	$fluffless_assert( null === Form::token_time( 'autre-clé', $fluffless_t ), 'jeton accepté pour une autre clé' );
	$fluffless_assert( null === Form::token_time( 'abcd1234-x', '1700000001.' . substr( $fluffless_t, 11 ) ), 'jeton falsifié accepté' );
	$fluffless_assert( ! str_contains( Form::rate_key( $fluffless_ip ), '203' ) && 0 === Form::rate_count( $fluffless_ip ), 'clé de plafond ou compteur initial' );

	// 7. Rendu : formulaire complet, sans script, champs liés à leurs libellés.
	$fluffless_html = do_blocks( $fluffless_content );
	$fluffless_def  = Form::definition_from_blocks(
		array(
			'formId'     => 'abcd1234',
			'recipients' => 'a@example.com, a@example.com pas-une-adresse b@example.com',
			'subject'    => 'Devis',
		),
		parse_blocks( $fluffless_content )[0]['innerBlocks']
	);
	$fluffless_key  = $fluffless_def['key'];
	$fluffless_assert( str_contains( $fluffless_html, '<form ' ) && str_contains( $fluffless_html, 'method="post"' ) && str_contains( $fluffless_html, 'id="fl-abcd1234"' ), 'balise form' );
	$fluffless_assert( str_contains( $fluffless_html, 'action="/contact/#fl-abcd1234-errors"' ), 'action sans la chaîne de requête, ancre sur le sommaire (pas sur le formulaire)' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<script' ), 'script dans le rendu' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="' . Form::FIELD_KEY . '" value="' . $fluffless_key . '"' ), 'clé de définition absente' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'a@example.com' ), 'destinataire visible dans la page' );
	$fluffless_assert( 1 === preg_match( '/name="' . Form::FIELD_TOKEN . '" value="(\d+\.[a-f0-9]{20})"/', $fluffless_html, $fluffless_m ) && null !== Form::token_time( $fluffless_key, $fluffless_m[1] ), 'jeton absent ou invalide' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="' . Form::HONEYPOT . '" tabindex="-1" autocomplete="off"' ), 'pot de miel' );
	$fluffless_assert( str_contains( $fluffless_html, '<label for="fl-abcd1234-nom">Nom' ) && str_contains( $fluffless_html, '<input id="fl-abcd1234-nom" type="text" name="fl[nom]" value="" required>' ), 'champ texte' );
	$fluffless_assert( str_contains( $fluffless_html, 'type="email" name="fl[courriel]" value="" autocomplete="email" required aria-describedby="fl-abcd1234-courriel-help"' ), 'champ courriel dans le groupe, aide liée' );
	$fluffless_assert( str_contains( $fluffless_html, '<legend>Sujet' ) && str_contains( $fluffless_html, 'type="radio" name="fl[sujet]" value="Devis" required' ), 'choix unique' );
	$fluffless_assert( str_contains( $fluffless_html, 'type="checkbox" name="fl[interets][]" value="A"' ) && 1 === substr_count( $fluffless_html, 'value="A"' ), 'cases à cocher (doublon d\'option gardé)' );
	$fluffless_assert( str_contains( $fluffless_html, '<select id="fl-abcd1234-ville" name="fl[ville]">' ) && str_contains( $fluffless_html, '<option value="Québec">Québec</option>' ), 'liste' );
	$fluffless_assert( str_contains( $fluffless_html, '<textarea id="fl-abcd1234-message" name="fl[message]" rows="6"></textarea>' ), 'zone de texte' );
	$fluffless_assert( 1 === preg_match( '/<input id="fl-abcd1234-jaccepte-detre-contacte" type="checkbox"[^>]*>/', $fluffless_html, $fluffless_m ) && ! str_contains( $fluffless_m[0], 'checked' ), 'consentement précoché ou absent' );
	$fluffless_assert( str_contains( $fluffless_html, '<input type="hidden" name="fl[source]" value="page-x">' ), 'champ caché' );
	$fluffless_assert( str_contains( $fluffless_html, '<button type="submit" class="wp-element-button">' ), 'bouton d\'envoi' );
	$fluffless_assert( str_contains( $fluffless_html, '(required)' ) && str_contains( $fluffless_html, 'aria-hidden="true">*</span>' ), 'marque d\'obligation' );
	preg_match_all( '/ id="([^"]+)"/', $fluffless_html, $fluffless_ids );
	$fluffless_assert( count( $fluffless_ids[1] ) === count( array_unique( $fluffless_ids[1] ) ), 'id en double' );
	$fluffless_assert( wp_style_is( Form::STYLE, 'enqueued' ), 'feuille non mise en file' );
	$fluffless_stored = Form::definition( $fluffless_key );
	$fluffless_assert( null !== $fluffless_stored && 8 === count( $fluffless_stored['fields'] ) && array( 'a@example.com', 'b@example.com' ) === $fluffless_stored['recipients'], 'définition non mémorisée au rendu' );

	// 8. Traitement : clé inconnue, pot de miel, jeton, délai, expiration.
	$fluffless_base = array(
		Form::FIELD_KEY   => $fluffless_key,
		Form::FIELD_TOKEN => Form::token( $fluffless_key, time() - 10 ),
		'fl'              => array(
			'nom'                     => 'Alice',
			'courriel'                => 'alice@example.com',
			'sujet'                   => 'Devis',
			'interets'                => array( 'B', 'Z' ),
			'ville'                   => 'Québec',
			'message'                 => "Bonjour,\n<b>gras</b>",
			'jaccepte-detre-contacte' => '1',
			'source'                  => 'triché',
		),
	);
	$fluffless_r    = Form::process( array( Form::FIELD_KEY => 'inconnue' ), $fluffless_ip );
	$fluffless_assert( ! $fluffless_r['ok'] && '' !== $fluffless_r['message'], 'clé inconnue acceptée' );
	$fluffless_r = Form::process( array_merge( $fluffless_base, array( Form::HONEYPOT => 'http://spam' ) ), $fluffless_ip );
	$fluffless_assert( $fluffless_r['ok'] && $fluffless_r['silent'] && array() === $fluffless_mails, 'pot de miel : faux succès sans courriel' );
	$fluffless_r = Form::process( array_merge( $fluffless_base, array( Form::FIELD_TOKEN => 'x' ) ), $fluffless_ip );
	$fluffless_assert( ! $fluffless_r['ok'] && array() === $fluffless_mails, 'jeton absent accepté' );
	$fluffless_r = Form::process( array_merge( $fluffless_base, array( Form::FIELD_TOKEN => Form::token( $fluffless_key ) ) ), $fluffless_ip );
	$fluffless_assert( ! $fluffless_r['ok'] && str_contains( $fluffless_r['message'], 'quick' ), 'envoi immédiat accepté' );
	$fluffless_r = Form::process( array_merge( $fluffless_base, array( Form::FIELD_TOKEN => Form::token( $fluffless_key, time() - Form::TOKEN_MAX_AGE - 60 ) ) ), $fluffless_ip );
	$fluffless_assert( ! $fluffless_r['ok'] && str_contains( $fluffless_r['message'], 'Reload' ), 'jeton expiré accepté' );
	$fluffless_assert( 0 === Form::rate_count( $fluffless_ip ), 'refus compté au plafond' );
	// 8b. Jeton stable (filtre fluffless_suite_form_timing_checks à faux) : même HTML à chaque rendu,
	// envoi immédiat accepté ; contrôles rallumés, ce jeton est refusé comme périmé.
	$fluffless_assert( 1 === preg_match( Form::TOKEN_PATTERN, Form::token( $fluffless_key ) ) && 1 === preg_match( Form::TOKEN_PATTERN, '0.' . str_repeat( 'a', 20 ) ) && 0 === preg_match( Form::TOKEN_PATTERN, '12.' . str_repeat( 'a', 20 ) ), 'TOKEN_PATTERN : forme du jeton' );
	add_filter( 'fluffless_suite_form_timing_checks', '__return_false' );
	$fluffless_stable = Form::token( $fluffless_key );
	$fluffless_assert( str_starts_with( $fluffless_stable, '0.' ) && Form::token( $fluffless_key ) === $fluffless_stable && 0 === Form::token_time( $fluffless_key, $fluffless_stable ), 'contrôles de temps éteints : jeton stable' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_content ), 'value="' . $fluffless_stable . '"' ), 'contrôles de temps éteints : le rendu porte le jeton stable' );
	$fluffless_r = Form::process(
		array_merge(
			$fluffless_base,
			array(
				Form::FIELD_TOKEN => $fluffless_stable,
				Form::FIELD_DATA  => array(
					'nom'      => 'Ada',
					'courriel' => 'ada@example.com',
					'message'  => 'Bonjour',
				),
			)
		),
		$fluffless_ip
	);
	$fluffless_assert( ! str_contains( (string) $fluffless_r['message'], 'quick' ) && ! str_contains( (string) $fluffless_r['message'], 'Reload' ), 'contrôles de temps éteints : envoi immédiat refusé : ' . $fluffless_r['message'] );
	remove_filter( 'fluffless_suite_form_timing_checks', '__return_false' );
	$fluffless_r = Form::process( array_merge( $fluffless_base, array( Form::FIELD_TOKEN => $fluffless_stable ) ), $fluffless_ip );
	$fluffless_assert( ! $fluffless_r['ok'] && str_contains( $fluffless_r['message'], 'Reload' ), 'contrôles de temps actifs : jeton stable accepté' );
	/**
	 * Courriel de l'envoi réussi ci-dessus, oublié pour la suite.
	 *
	 * @var list<array<string, mixed>> $fluffless_mails
	 */
	$fluffless_mails = array();

	// 9. Validation : requis, formats, options ; champ caché imposé par la définition.
	$fluffless_bad       = $fluffless_base;
	$fluffless_bad['fl'] = array(
		'courriel' => 'pas-un-courriel',
		'sujet'    => 'Hors liste',
		'ville'    => 'Paris',
		'message'  => str_repeat( 'a', Form::TEXTAREA_MAX + 1 ),
	);
	$fluffless_r         = Form::process( $fluffless_bad, $fluffless_ip );
	$fluffless_assert( ! $fluffless_r['ok'] && array() === $fluffless_mails, 'formulaire invalide envoyé' );
	$fluffless_assert( array( 'nom', 'courriel', 'sujet', 'ville', 'message', 'jaccepte-detre-contacte' ) === array_keys( $fluffless_r['errors'] ), 'erreurs : ' . implode( '|', array_keys( $fluffless_r['errors'] ) ) );
	$fluffless_assert( str_contains( $fluffless_r['message'], '6' ), 'sommaire sans le compte' );
	$fluffless_assert( 'pas-un-courriel' === ( $fluffless_r['values']['courriel'] ?? null ) && 'page-x' === $fluffless_r['values']['source'], 'valeurs réaffichées ou champ caché écrasé' );
	$fluffless_v = static fn( string $type, $raw, array $extra = array() ) => Form::validate(
		array_merge(
			array(
				'name'         => 'x',
				'type'         => $type,
				'label'        => 'x',
				'required'     => false,
				'placeholder'  => '',
				'autocomplete' => '',
				'help'         => '',
				'options'      => array(),
				'value'        => '',
				'when'         => '',
				'is'           => '',
				'step'         => 0,
			),
			$extra
		),
		$raw
	);
	$fluffless_assert( null === $fluffless_v( 'phone', '+1 (418) 555-0199' )['error'] && null !== $fluffless_v( 'phone', 'abc' )['error'], 'téléphone' );
	$fluffless_assert( '12.5' === $fluffless_v( 'number', '12,5' )['value'] && null !== $fluffless_v( 'number', '1e' )['error'], 'nombre' );
	$fluffless_assert( null === $fluffless_v( 'date', '2026-09-18' )['error'] && null !== $fluffless_v( 'date', '2026-02-30' )['error'], 'date' );
	$fluffless_assert( null === $fluffless_v( 'text', '' )['error'] && null !== $fluffless_v( 'text', '', array( 'required' => true ) )['error'], 'requis' );
	$fluffless_assert( array( 'A' ) === $fluffless_v( 'checkboxes', array( 'A', 'Z', 'A' ), array( 'options' => array( 'A', 'B' ) ) )['value'], 'cases filtrées' );
	$fluffless_assert(
		null !== $fluffless_v(
			'checkboxes',
			'A',
			array(
				'options'  => array( 'A' ),
				'required' => true,
			)
		)['error'],
		'cases : chaîne au lieu d\'un tableau'
	);
	$fluffless_assert( '' === $fluffless_v( 'consent', '' )['value'] && null !== $fluffless_v( 'consent', '', array( 'required' => true ) )['error'], 'consentement' );
	$fluffless_assert( ! str_contains( implode( '', (array) $fluffless_v( 'textarea', "a\n<script>x</script>" )['value'] ), '<script' ), 'balise gardée dans la zone de texte' );

	// 10. Envoi : courriel au site (Reply-To la personne), copie à la personne (Reply-To le site), plafond compté.
	$fluffless_r = Form::process( $fluffless_base, $fluffless_ip );
	$fluffless_assert( $fluffless_r['ok'] && ! $fluffless_r['silent'] && 'abcd1234' === $fluffless_r['id'], 'envoi valide refusé : ' . $fluffless_r['message'] );
	$fluffless_assert( 2 === count( $fluffless_mails ), 'deux courriels attendus, ' . count( $fluffless_mails ) );
	$fluffless_m1 = $fluffless_mails[0] ?? array();
	$fluffless_m2 = $fluffless_mails[1] ?? array();
	$fluffless_assert( array( 'a@example.com', 'b@example.com' ) === ( $fluffless_m1['to'] ?? null ), 'destinataires du site' );
	$fluffless_assert( str_ends_with( (string) ( $fluffless_m1['subject'] ?? '' ), '] Devis' ), 'sujet : ' . ( $fluffless_m1['subject'] ?? '' ) );
	$fluffless_assert( str_contains( (string) ( $fluffless_m1['message'] ?? '' ), 'Nom : Alice' ) && str_contains( (string) $fluffless_m1['message'], 'Intérêts : B' ) && str_contains( (string) $fluffless_m1['message'], 'Source : page-x' ) && str_contains( (string) $fluffless_m1['message'], "Message :\nBonjour," ), 'corps : ' . ( $fluffless_m1['message'] ?? '' ) );
	$fluffless_assert( ! str_contains( (string) $fluffless_m1['message'], '<b>' ), 'HTML dans le corps' );
	$fluffless_assert( in_array( 'Reply-To: alice@example.com', (array) ( $fluffless_m1['headers'] ?? array() ), true ), 'Reply-To vers la personne' );
	$fluffless_assert( 'alice@example.com' === ( $fluffless_m2['to'] ?? null ) && in_array( 'Reply-To: a@example.com', (array) ( $fluffless_m2['headers'] ?? array() ), true ), 'copie à la personne' );
	$fluffless_assert( str_contains( (string) ( $fluffless_m2['message'] ?? '' ), 'Nom : Alice' ), 'copie sans les réponses' );
	$fluffless_assert( 1 === Form::rate_count( $fluffless_ip ), 'plafond non compté' );

	// 11. Plafond, sans copie, sans destinataire (adresse d'administration), routage par filtre, échec d'envoi.
	$fluffless_set( array( 'rate' => 1 ) );
	$fluffless_r = Form::process( $fluffless_base, $fluffless_ip );
	$fluffless_assert( ! $fluffless_r['ok'] && str_contains( $fluffless_r['message'], 'connection' ) && 2 === count( $fluffless_mails ), 'plafond non appliqué' );
	$fluffless_assert( Form::process( $fluffless_base, '' )['ok'], 'sans adresse : le plafond ne s\'applique pas' );
	$fluffless_set( Form::defaults() );
	delete_transient( Form::rate_key( $fluffless_ip ) );
	/**
	 * Courriels captés, remis à zéro.
	 *
	 * @var list<array<string, mixed>> $fluffless_mails
	 */
	$fluffless_mails = array();
	Form::remember(
		array_merge(
			$fluffless_def,
			array(
				'key'        => 'abcd1234-plain',
				'recipients' => array(),
				'confirm'    => false,
				'subject'    => '',
			)
		)
	);
	$fluffless_plain = array_merge(
		$fluffless_base,
		array(
			Form::FIELD_KEY   => 'abcd1234-plain',
			Form::FIELD_TOKEN => Form::token( 'abcd1234-plain', time() - 10 ),
		)
	);
	$fluffless_r     = Form::process( $fluffless_plain, $fluffless_ip );
	$fluffless_assert( $fluffless_r['ok'] && 1 === count( $fluffless_mails ) && array( get_option( 'admin_email' ) ) === $fluffless_mails[0]['to'], 'sans destinataire ni copie' );
	$fluffless_route = static fn( array $to, array $definition, array $values ) => 'Devis' === ( $values['sujet'] ?? '' ) ? array( 'devis@example.com' ) : $to;
	add_filter( 'fluffless_form_recipients', $fluffless_route, 10, 3 );
	/**
	 * Courriels captés, remis à zéro.
	 *
	 * @var list<array<string, mixed>> $fluffless_mails
	 */
	$fluffless_mails = array();
	Form::process( $fluffless_plain, $fluffless_ip );
	remove_filter( 'fluffless_form_recipients', $fluffless_route, 10 );
	$fluffless_assert( array( 'devis@example.com' ) === ( $fluffless_mails[0]['to'] ?? null ), 'routage par réponse' );
	$fluffless_count  = static function (): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compte brut, le type de contenu n'est pas encore inscrit.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", Form::POST_TYPE ) );
	};
	$fluffless_before = $fluffless_count();
	$fluffless_fail   = true;
	$fluffless_r      = Form::process( $fluffless_plain, $fluffless_ip );
	$fluffless_fail   = false;
	$fluffless_assert( ! $fluffless_r['ok'] && str_contains( $fluffless_r['message'], 'could not be sent' ), 'échec d\'envoi non signalé' );
	$fluffless_assert( $fluffless_count() === $fluffless_before, 'échec d\'envoi sans conservation : réponse gardée quand même' );
	delete_transient( Form::rate_key( $fluffless_ip ) );

	// Crochet fluffless_form_sent : une fois par envoi réussi, avec la définition et les réponses ; jamais sur un échec.
	$fluffless_sent = array();
	$fluffless_bond = static function ( array $definition, array $values ) use ( &$fluffless_sent ): void {
		$fluffless_sent[] = array( $definition, $values );
	};
	add_action( 'fluffless_form_sent', $fluffless_bond, 10, 2 );
	$fluffless_fail = true;
	Form::process( $fluffless_plain, $fluffless_ip );
	$fluffless_fail = false;
	$fluffless_assert( array() === $fluffless_sent, 'crochet fluffless_form_sent déclenché malgré un échec d\'envoi' );
	delete_transient( Form::rate_key( $fluffless_ip ) );
	$fluffless_r = Form::process( $fluffless_plain, $fluffless_ip );
	$fluffless_assert( $fluffless_r['ok'] && 1 === count( $fluffless_sent ), 'crochet fluffless_form_sent non déclenché une seule fois à l\'envoi réussi' );
	$fluffless_assert( 'abcd1234-plain' === ( $fluffless_sent[0][0]['key'] ?? null ), 'crochet : définition absente' );
	$fluffless_assert( ( $fluffless_sent[0][1] ?? null ) === $fluffless_r['values'], 'crochet : réponses différentes de celles validées' );
	/**
	 * Appels du crochet captés, remis à zéro.
	 *
	 * @var list<array{0: array<string, mixed>, 1: array<string, mixed>}> $fluffless_sent
	 */
	$fluffless_sent  = array();
	$fluffless_honey = array_merge( $fluffless_plain, array( Form::HONEYPOT => 'robot' ) );
	Form::process( $fluffless_honey, $fluffless_ip );
	$fluffless_assert( array() === $fluffless_sent, 'crochet fluffless_form_sent déclenché par le pot de miel' );
	remove_action( 'fluffless_form_sent', $fluffless_bond, 10 );
	delete_transient( Form::rate_key( $fluffless_ip ) );

	// 12. Rendu des erreurs : sommaire lié aux champs, aria-invalid, valeurs réaffichées.
	$fluffless_r = Form::process( $fluffless_bad, $fluffless_ip );
	Form::show_errors( $fluffless_r );
	$fluffless_html = do_blocks( $fluffless_content );
	Form::show_errors( null );
	$fluffless_assert( str_contains( $fluffless_html, 'role="alert" tabindex="-1" id="fl-abcd1234-errors"' ), 'sommaire d\'erreurs' );
	$fluffless_assert( 1 === substr_count( $fluffless_html, ' autofocus' ) && str_contains( $fluffless_html, 'role="alert" tabindex="-1" id="fl-abcd1234-errors" autofocus><p>' ), 'focus sur le sommaire, et lui seul' );
	$fluffless_assert( str_contains( $fluffless_html, '<a href="#fl-abcd1234-nom">Nom</a>' ), 'lien vers le champ en erreur' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="fl[courriel]" value="pas-un-courriel" autocomplete="email" required aria-describedby="fl-abcd1234-courriel-help fl-abcd1234-courriel-error" aria-invalid="true"' ), 'champ en erreur : aide et erreur liées, valeur gardée' );
	$fluffless_assert( str_contains( $fluffless_html, '<p id="fl-abcd1234-courriel-error" class="fluffless-champ__error">' ), 'message d\'erreur du champ' );
	$fluffless_assert( str_contains( $fluffless_html, 'class="fluffless-champ is-type-text has-error"' ), 'classe d\'erreur' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'is-type-select has-error' ) || str_contains( $fluffless_html, 'fl-abcd1234-ville-error' ), 'liste hors options sans message' );
	$fluffless_assert( str_contains( $fluffless_html, '<fieldset id="fl-abcd1234-sujet" tabindex="-1" aria-describedby="fl-abcd1234-sujet-error" aria-invalid="true">' ), 'groupe de choix en erreur' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'checked' ), 'case cochée sans envoi' );
	Form::show_errors(
		array(
			'ok'      => false,
			'silent'  => false,
			'id'      => 'abcd1234',
			'step'    => 0,
			'errors'  => array(),
			'values'  => array(),
			'message' => 'The message could not be sent. Try again later.',
		)
	);
	$fluffless_html = do_blocks( $fluffless_content );
	Form::show_errors( null );
	$fluffless_assert( 1 === substr_count( $fluffless_html, ' autofocus' ) && str_contains( $fluffless_html, 'id="fl-abcd1234-errors" autofocus><p>The message could not be sent.' ), 'erreur sans champ (envoi raté) : focus sur le sommaire' );

	// 13. État envoyé : message de succès, plus de formulaire.
	$_GET[ Form::QUERY_SENT ] = 'abcd1234';
	$fluffless_html           = do_blocks( $fluffless_content );
	unset( $_GET[ Form::QUERY_SENT ] );
	$fluffless_assert( ! str_contains( $fluffless_html, '<form' ) && str_contains( $fluffless_html, 'role="status" tabindex="-1" id="fl-abcd1234" autofocus>' ), 'état envoyé' );
	$fluffless_assert( str_contains( $fluffless_html, 'has been sent' ), 'message de succès par défaut' );
	$_GET[ Form::QUERY_SENT ] = 'autre';
	$fluffless_html           = do_blocks( $fluffless_content );
	unset( $_GET[ Form::QUERY_SENT ] );
	$fluffless_assert( str_contains( $fluffless_html, '<form' ), 'autre formulaire marqué envoyé' );

	// 14. Réponses conservées : rien par défaut, enregistrement quand le formulaire le demande, CSV, purge, suppression.
	Form::register_post_type();
	$fluffless_assert( post_type_exists( Form::POST_TYPE ), 'type de contenu absent' );
	$fluffless_assert( array() === Form::responses( 'abcd1234' ), 'réponse conservée sans demande' );
	Form::remember(
		array_merge(
			$fluffless_def,
			array(
				'key'        => 'abcd1234-keep',
				'recipients' => array(),
				'confirm'    => false,
				'keep'       => true,
			)
		)
	);
	$fluffless_keep                      = $fluffless_base;
	$fluffless_keep[ Form::FIELD_KEY ]   = 'abcd1234-keep';
	$fluffless_keep[ Form::FIELD_TOKEN ] = Form::token( 'abcd1234-keep', time() - 10 );
	$fluffless_keep['fl']['nom']         = '=1+1';
	delete_transient( Form::rate_key( $fluffless_ip ) );
	$fluffless_r = Form::process( $fluffless_keep, $fluffless_ip );
	$fluffless_assert( $fluffless_r['ok'], 'envoi avec conservation refusé : ' . $fluffless_r['message'] );
	$fluffless_kept = Form::responses( 'abcd1234' );
	$fluffless_assert( 1 === count( $fluffless_kept ) && '=1+1' === $fluffless_kept[0]['values']['nom'] && array( 'B' ) === $fluffless_kept[0]['values']['interets'] && 'page-x' === $fluffless_kept[0]['values']['source'], 'réponse non conservée' );
	$fluffless_assert( 'Nom' === $fluffless_kept[0]['fields'][0]['label'] && abs( time() - $fluffless_kept[0]['time'] ) < 60, 'libellés ou date de la réponse' );
	$fluffless_csv = Form::csv( 'abcd1234' );
	$fluffless_assert( str_starts_with( $fluffless_csv, "\xEF\xBB\xBF" ) && str_contains( $fluffless_csv, 'Nom,Courriel,' ) && str_contains( $fluffless_csv, ",Date\n" ), 'en-tête CSV : ' . substr( $fluffless_csv, 3, 80 ) );
	$fluffless_assert( str_contains( $fluffless_csv, "'=1+1,alice@example.com,Devis,B,Québec," ) && ! str_contains( $fluffless_csv, '<b>' ), 'lignes CSV : ' . $fluffless_csv );
	$fluffless_assert( false === $fluffless_kept[0]['unsent'] && '' === get_post_meta( $fluffless_kept[0]['id'], Form::META_UNSENT, true ), 'envoi réussi : réponse marquée non envoyée' );
	$fluffless_assert( 0 === Form::summaries()[0]['unsent'] && ! str_contains( $fluffless_csv, ',Delivery' ), 'envoi réussi : non-envoi signalé au résumé ou au CSV' );

	// Courriel qui échoue : la réponse est gardée avant l'envoi, marquée, et la personne voit l'erreur.
	$fluffless_keep[ Form::FIELD_TOKEN ] = Form::token( 'abcd1234-keep', time() - 10 );
	$fluffless_keep['fl']['nom']         = 'Bea';
	delete_transient( Form::rate_key( $fluffless_ip ) );
	$fluffless_seen = null;
	$fluffless_spy  = static function ( $short ) use ( &$fluffless_seen ) {
		$fluffless_seen = array_map( static fn( array $row ): bool => $row['unsent'], Form::responses( 'abcd1234' ) );
		return $short;
	};
	add_filter( 'pre_wp_mail', $fluffless_spy, 5 );
	$fluffless_fail = true;
	$fluffless_r    = Form::process( $fluffless_keep, $fluffless_ip );
	$fluffless_fail = false;
	remove_filter( 'pre_wp_mail', $fluffless_spy, 5 );
	$fluffless_assert( array( true, false ) === $fluffless_seen, 'réponse non gardée ou non marquée au moment de l\'envoi : ' . wp_json_encode( $fluffless_seen ) );
	$fluffless_assert( ! $fluffless_r['ok'] && str_contains( $fluffless_r['message'], 'could not be sent' ), 'échec d\'envoi avec conservation : succès montré' );
	$fluffless_kept = Form::responses( 'abcd1234' );
	$fluffless_assert( 2 === count( $fluffless_kept ) && 'Bea' === $fluffless_kept[0]['values']['nom'] && true === $fluffless_kept[0]['unsent'] && false === $fluffless_kept[1]['unsent'], 'échec d\'envoi : réponse perdue ou non marquée : ' . wp_json_encode( $fluffless_kept ) );
	$fluffless_assert( 1 === Form::summaries()[0]['unsent'] && 2 === Form::summaries()[0]['count'], 'échec d\'envoi : résumé sans le compte des non envoyées' );
	$fluffless_csv = Form::csv( 'abcd1234' );
	$fluffless_assert( str_contains( $fluffless_csv, ",Date,Delivery\n" ) && str_contains( $fluffless_csv, ',"Not sent"' ) && str_contains( $fluffless_csv, ",Sent\n" ), 'échec d\'envoi : colonne Envoi du CSV : ' . $fluffless_csv );
	ob_start();
	Form::render_panel();
	$fluffless_panel = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_panel, '<span class="fl-pill fl-pill--bad">1 not sent by email</span>' ), 'échec d\'envoi : panneau sans mention : ' . $fluffless_panel );
	delete_transient( Form::rate_key( $fluffless_ip ) );
	$fluffless_old = wp_insert_post(
		array(
			'post_type'     => Form::POST_TYPE,
			'post_status'   => 'private',
			'post_title'    => 'vieux',
			'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
			'meta_input'    => array(
				Form::META_FORM   => 'abcd1234',
				Form::META_UNSENT => '1',
			),
		)
	);
	$fluffless_assert( $fluffless_old > 0 && 3 === count( Form::responses( 'abcd1234' ) ), 'vieille réponse non insérée' );
	$fluffless_assert( 1 === Form::purge() && 2 === count( Form::responses( 'abcd1234' ) ) && null === get_post( $fluffless_old ), 'purge (vieille réponse non envoyée comprise)' );
	// Durée propre au formulaire (liste d'attente : 365 jours) : le délai du module (30 jours) ne la purge pas.
	$fluffless_attrs = Form::form_attributes(
		array(
			'formId'   => 'listeattente',
			'keepDays' => 9999,
		)
	);
	$fluffless_assert( Form::KEEP_DAYS_MAX === $fluffless_attrs['keep_days'] && 0 === Form::form_attributes( array( 'formId' => 'abcd1234' ) )['keep_days'], 'keepDays borné à 365, absent = 0' );
	$fluffless_long = array();
	$fluffless_ages = array(
		40  => 365,
		400 => 365,
		100 => 0,
	);
	foreach ( $fluffless_ages as $fluffless_age => $fluffless_own ) {
		$fluffless_long[ $fluffless_age ] = wp_insert_post(
			array(
				'post_type'     => Form::POST_TYPE,
				'post_status'   => 'private',
				'post_title'    => 'longue-' . $fluffless_age,
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - $fluffless_age * DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $fluffless_age * DAY_IN_SECONDS ),
				'meta_input'    => array_merge( array( Form::META_FORM => 'listeattente' ), $fluffless_own > 0 ? array( Form::META_KEEP_DAYS => $fluffless_own ) : array() ),
			)
		);
	}
	Form::purge();
	$fluffless_assert( 'private' === get_post_status( $fluffless_long[40] ), 'réponse de 40 jours à durée propre de 365 : gardée malgré les 30 jours du module' );
	$fluffless_assert( false === get_post_status( $fluffless_long[400] ), 'réponse de 400 jours à durée propre de 365 : purgée' );
	$fluffless_assert( false === get_post_status( $fluffless_long[100] ), 'réponse de 100 jours sans durée propre : purgée par le module' );
	Form::delete_all( 'listeattente' );
	$fluffless_sum = Form::summaries();
	$fluffless_assert( 1 === count( $fluffless_sum ) && 'abcd1234' === $fluffless_sum[0]['form'] && 2 === $fluffless_sum[0]['count'] && 1 === $fluffless_sum[0]['unsent'] && 'Devis' === $fluffless_sum[0]['subject'], 'résumés : ' . wp_json_encode( $fluffless_sum ) );
	$fluffless_assert( 2 === Form::delete_all( 'abcd1234' ) && array() === Form::summaries() && "Date\n" === substr( Form::csv( 'abcd1234' ), 3 ), 'suppression' );
	$fluffless_assert( "'-x" === Form::cell( '-x' ) && 'x' === Form::cell( 'x' ), 'cellule de formule' );

	// 14 b. « Tout supprimer » (audit C1) : POST hors du formulaire des réglages, nonce propre, case « Je comprends ».
	wp_set_current_user( 1 );
	$fluffless_seed3 = static function ( int $n ): void {
		for ( $i = 1; $i <= $n; $i++ ) {
			wp_insert_post(
				array(
					'post_type'   => Form::POST_TYPE,
					'post_status' => 'private',
					'post_title'  => 'à effacer ' . $i,
					'meta_input'  => array( Form::META_FORM => 'c1c1c1c1' ),
				)
			);
		}
	};
	$fluffless_seed3( 3 );
	ob_start();
	Form::render_panel();
	$fluffless_panel = (string) ob_get_clean();
	$fluffless_assert( ! str_contains( $fluffless_panel, 'action=' . Form::ACTION_DELETE ), 'C1 : plus de lien GET de suppression : ' . $fluffless_panel );
	$fluffless_assert( str_contains( $fluffless_panel, '<button type="submit" form="' . Form::DELETE_FORM_ID . '"' ) && str_contains( $fluffless_panel, 'name="form" value="c1c1c1c1"' ), 'C1 : bouton lié au formulaire POST' );
	$fluffless_assert( str_contains( $fluffless_panel, 'form="' . Form::DELETE_FORM_ID . '" name="confirm[]" value="c1c1c1c1"' ), 'C1 : case de confirmation par formulaire' );
	ob_start();
	Form::render_delete_form();
	$fluffless_del_form = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_del_form, 'id="' . Form::DELETE_FORM_ID . '" method="post"' ) && str_contains( $fluffless_del_form, 'name="' . Form::DELETE_NONCE . '"' ) && str_contains( $fluffless_del_form, 'value="' . Form::ACTION_DELETE . '"' ), 'C1 : formulaire POST avec nonce : ' . $fluffless_del_form );
	// Les gestionnaires finissent par exit : la redirection et wp_die lèvent une exception attrapée ici.
	$fluffless_stop  = static function ( $location ): never {
		throw new RuntimeException( 'redirect:' . esc_url_raw( $location ) );
	};
	$fluffless_die   = static function () {
		return static function ( $message, $title = '', $args = array() ) {
			throw new RuntimeException( 'die:' . ( is_array( $args ) ? (int) ( $args['response'] ?? 0 ) : 0 ) );
		};
	};
	$fluffless_call  = static function ( callable $handler, string $method, array $post ) use ( $fluffless_stop, $fluffless_die ): string {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_POST                     = $post;
		$_REQUEST                  = $post;
		add_filter( 'wp_redirect', $fluffless_stop, 1 ); // @phpstan-ignore return.missing
		add_filter( 'wp_die_handler', $fluffless_die, PHP_INT_MAX );
		try {
			$handler();
			return 'no-exit';
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $fluffless_stop, 1 );
			remove_filter( 'wp_die_handler', $fluffless_die, PHP_INT_MAX );
			$_POST    = array();
			$_REQUEST = array();
			unset( $_SERVER['REQUEST_METHOD'] );
		}
	};
	$fluffless_h     = array( Form::class, 'handle_delete' );
	$fluffless_nonce = wp_create_nonce( Form::ACTION_DELETE );
	$fluffless_ok    = array(
		'form'             => 'c1c1c1c1',
		'confirm'          => array( 'c1c1c1c1' ),
		Form::DELETE_NONCE => $fluffless_nonce,
	);
	$fluffless_out   = $fluffless_call( $fluffless_h, 'GET', $fluffless_ok );
	$fluffless_assert( 'die:405' === $fluffless_out && 3 === count( Form::responses( 'c1c1c1c1' ) ), 'C1 : GET refusé (405), rien effacé : ' . $fluffless_out );
	$fluffless_out = $fluffless_call( $fluffless_h, 'POST', array_diff_key( $fluffless_ok, array( 'confirm' => 1 ) ) );
	$fluffless_assert( str_contains( $fluffless_out, 'fl_deleted=unconfirmed' ) && 3 === count( Form::responses( 'c1c1c1c1' ) ), 'C1 : sans la case, rien effacé : ' . $fluffless_out );
	$fluffless_out = $fluffless_call( $fluffless_h, 'POST', array_merge( $fluffless_ok, array( 'confirm' => array( 'autre123' ) ) ) );
	$fluffless_assert( str_contains( $fluffless_out, 'fl_deleted=unconfirmed' ) && 3 === count( Form::responses( 'c1c1c1c1' ) ), 'C1 : case d\'un autre formulaire, rien effacé : ' . $fluffless_out );
	$fluffless_out = $fluffless_call( $fluffless_h, 'POST', array_merge( $fluffless_ok, array( Form::DELETE_NONCE => 'faux' ) ) );
	$fluffless_assert( str_starts_with( $fluffless_out, 'die:' ) && 3 === count( Form::responses( 'c1c1c1c1' ) ), 'C1 : nonce faux refusé : ' . $fluffless_out );
	$fluffless_out = $fluffless_call( $fluffless_h, 'POST', $fluffless_ok );
	$fluffless_assert( str_contains( $fluffless_out, 'fl_deleted=3' ) && array() === Form::responses( 'c1c1c1c1' ), 'C1 : POST confirmé, 3 réponses effacées et comptées : ' . $fluffless_out );
	$fluffless_seed3( 1 );
	$_GET['fl_deleted'] = '3';
	ob_start();
	Form::render_panel();
	$fluffless_panel = (string) ob_get_clean();
	unset( $_GET['fl_deleted'] );
	$fluffless_assert( str_contains( $fluffless_panel, '3 responses deleted.' ), 'C1 : message « N réponses supprimées » : ' . $fluffless_panel );
	$fluffless_assert( 1 === Form::delete_all_batched( 'c1c1c1c1' ) && array() === Form::responses( 'c1c1c1c1' ), 'C1 : suppression par lots' );

	// 15. Crochets du module.
	( new Form() )->register();
	$fluffless_assert( 1 === has_action( 'template_redirect', array( Form::class, 'handle_request' ) ), 'traitement non branché' );
	$fluffless_assert( false !== has_action( 'init', array( Form::class, 'register_blocks' ) ), 'blocs non branchés' );
	$fluffless_assert( false !== has_action( 'init', array( Form::class, 'register_post_type' ) ), 'type de contenu non branché' );

	// 16. Conditions : champ commandé par un choix ou un consentement, caché par CSS, ignoré par le serveur quand la condition ne tient pas.
	$fluffless_cond   = '<!-- wp:fluffless/formulaire {"formId":"cond1234","recipients":"a@example.com","confirm":false} -->'
		. '<!-- wp:fluffless/champ {"type":"radio","label":"Type","name":"type","options":"Particulier\nEntreprise","required":true} /-->'
		. '<!-- wp:fluffless/champ {"type":"text","label":"Entreprise","name":"societe","required":true,"when":"type","is":"Entreprise"} /-->'
		. '<!-- wp:fluffless/champ {"type":"consent","label":"Me rappeler","name":"rappel"} /-->'
		. '<!-- wp:fluffless/champ {"type":"phone","label":"Téléphone","name":"tel","required":true,"when":"rappel"} /-->'
		. '<!-- wp:fluffless/champ {"type":"text","label":"Boucle","name":"boucle","when":"boucle","is":"x"} /-->'
		. '<!-- wp:fluffless/champ {"type":"text","label":"Orphelin","name":"orphelin","required":true,"when":"absent","is":"x"} /-->'
		. '<!-- /wp:fluffless/formulaire -->';
	$fluffless_blocks = parse_blocks( $fluffless_cond );
	$fluffless_cdef   = Form::definition_from_blocks( (array) $fluffless_blocks[0]['attrs'], $fluffless_blocks[0]['innerBlocks'] );
	$fluffless_assert( array() === $fluffless_cdef['steps'] && 'type' === $fluffless_cdef['fields'][1]['when'] && 'Entreprise' === $fluffless_cdef['fields'][1]['is'] && 'rappel' === $fluffless_cdef['fields'][3]['when'], 'conditions non lues' );
	$fluffless_assert( '' === $fluffless_cdef['fields'][4]['when'], 'condition sur soi-même gardée' );
	$fluffless_css = Form::condition_css( $fluffless_cdef );
	$fluffless_assert( str_contains( $fluffless_css, '#fl-cond1234:not(:has(#fl-cond1234-type input[value="Entreprise"]:checked)) .fl-when-societe{display:none}' ), 'règle CSS du choix : ' . $fluffless_css );
	$fluffless_assert( str_contains( $fluffless_css, '#fl-cond1234:not(:has(#fl-cond1234-rappel:checked)) .fl-when-tel{display:none}' ), 'règle CSS du consentement' );
	$fluffless_assert( ! str_contains( $fluffless_css, 'orphelin' ), 'règle sans champ qui commande' );
	Form::remember( array_merge( $fluffless_cdef, array( 'key' => 'cond1234-k' ) ) );
	$fluffless_cpost = static fn( array $data ) => array(
		Form::FIELD_KEY   => 'cond1234-k',
		Form::FIELD_TOKEN => Form::token( 'cond1234-k', time() - 10 ),
		Form::FIELD_DATA  => $data,
	);
	$fluffless_r     = Form::process( $fluffless_cpost( array( 'type' => 'Particulier' ) ), '' );
	$fluffless_assert( ! $fluffless_r['ok'] && array( 'orphelin' ) === array_keys( $fluffless_r['errors'] ) && '' === $fluffless_r['values']['societe'], 'champ caché exigé : ' . implode( '|', array_keys( $fluffless_r['errors'] ) ) );
	$fluffless_r = Form::process(
		$fluffless_cpost(
			array(
				'type'     => 'Entreprise',
				'orphelin' => 'o',
			)
		),
		''
	);
	$fluffless_assert( array( 'societe' ) === array_keys( $fluffless_r['errors'] ), 'champ affiché non exigé' );
	$fluffless_r = Form::process(
		$fluffless_cpost(
			array(
				'type'     => 'Entreprise',
				'societe'  => 'ACME',
				'rappel'   => '1',
				'orphelin' => 'o',
			)
		),
		''
	);
	$fluffless_assert( array( 'tel' ) === array_keys( $fluffless_r['errors'] ), 'consentement coché : téléphone exigé' );
	$fluffless_r = Form::process(
		$fluffless_cpost(
			array(
				'type'     => 'Particulier',
				'societe'  => 'ACME',
				'orphelin' => 'o',
			)
		),
		''
	);
	$fluffless_assert( $fluffless_r['ok'] && '' === $fluffless_r['values']['societe'], 'réponse d\'un champ caché gardée' );
	$fluffless_html = do_blocks( $fluffless_cond );
	$fluffless_assert( str_contains( $fluffless_html, 'class="fluffless-champ is-type-text fl-when-societe"' ), 'classe de condition' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="fl[societe]" value=""' ) && ! str_contains( $fluffless_html, 'name="fl[societe]" value="" required' ), 'required natif sur un champ conditionnel' );
	$fluffless_assert( str_contains( implode( '', (array) wp_styles()->get_data( Form::STYLE, 'after' ) ), '.fl-when-societe{display:none}' ), 'règles de condition non jointes à la feuille' );
	Form::show_errors( null );

	// 17. Étapes : une à la fois, réponses portées signées, retour, dernière étape qui revérifie tout.
	$fluffless_steps  = '<!-- wp:fluffless/formulaire {"formId":"step1234","recipients":"a@example.com","confirm":false} -->'
		. '<!-- wp:fluffless/etape {"title":"Vous"} --><!-- wp:fluffless/champ {"type":"text","label":"Nom","name":"nom","required":true} /--><!-- /wp:fluffless/etape -->'
		. '<!-- wp:fluffless/etape {"title":"Détails"} --><!-- wp:fluffless/champ {"type":"select","label":"Ville","name":"ville","options":"Québec\nMontréal"} /-->'
		. '<!-- wp:fluffless/champ {"type":"text","label":"Rue","name":"rue","required":true,"when":"ville","is":"Québec"} /--><!-- /wp:fluffless/etape -->'
		. '<!-- wp:fluffless/champ {"type":"hidden","name":"source","value":"p"} /-->'
		. '<!-- /wp:fluffless/formulaire -->';
	$fluffless_blocks = parse_blocks( $fluffless_steps );
	$fluffless_sdef   = Form::definition_from_blocks( (array) $fluffless_blocks[0]['attrs'], $fluffless_blocks[0]['innerBlocks'] );
	$fluffless_assert( array( 'Vous', 'Détails' ) === $fluffless_sdef['steps'], 'étapes non lues' );
	$fluffless_assert( array( 1, 2, 2, 0 ) === array_column( $fluffless_sdef['fields'], 'step' ), 'rang des champs : ' . implode( ',', array_column( $fluffless_sdef['fields'], 'step' ) ) );
	Form::remember( array_merge( $fluffless_sdef, array( 'key' => 'step1234-k' ) ) );
	/**
	 * Courriels captés, remis à zéro.
	 *
	 * @var list<array<string, mixed>> $fluffless_mails
	 */
	$fluffless_mails = array();
	$fluffless_spost = static fn( int $step, array $data, string $state = '', bool $back = false ) => array(
		Form::FIELD_KEY   => 'step1234-k',
		Form::FIELD_TOKEN => Form::token( 'step1234-k', time() - 10 ),
		Form::FIELD_STEP  => (string) $step,
		Form::FIELD_STATE => $state,
		Form::FIELD_BACK  => $back ? '1' : '',
		Form::FIELD_DATA  => $data,
	);
	$fluffless_r     = Form::process( $fluffless_spost( 1, array( 'nom' => '' ) ), '' );
	$fluffless_assert( ! $fluffless_r['ok'] && 1 === $fluffless_r['step'] && isset( $fluffless_r['errors']['nom'] ), 'étape 1 en erreur' );
	$fluffless_r = Form::process(
		$fluffless_spost(
			1,
			array(
				'nom'   => 'Alice',
				'ville' => 'Québec',
			)
		),
		''
	);
	$fluffless_assert( ! $fluffless_r['ok'] && 2 === $fluffless_r['step'] && '' === $fluffless_r['message'] && array( 'nom' => 'Alice' ) === $fluffless_r['values'] && array() === $fluffless_mails, 'passage à l\'étape 2' );
	$fluffless_state = Form::state( 'step1234-k', array( 'nom' => 'Alice' ) );
	$fluffless_assert( array( 'nom' => 'Alice' ) === Form::state_values( 'step1234-k', $fluffless_state ) && array() === Form::state_values( 'step1234-k', '' ) && null === Form::state_values( 'step1234-k', substr( $fluffless_state, 0, -1 ) . 'x' ) && null === Form::state_values( 'autre', $fluffless_state ), 'état signé' );
	$fluffless_r = Form::process( $fluffless_spost( 2, array( 'ville' => 'Québec' ), $fluffless_state ), '' );
	$fluffless_assert( ! $fluffless_r['ok'] && array( 'rue' ) === array_keys( $fluffless_r['errors'] ) && 2 === $fluffless_r['step'], 'condition dans une étape' );
	$fluffless_r = Form::process( $fluffless_spost( 2, array( 'ville' => 'Montréal' ), $fluffless_state ), '' );
	$fluffless_assert( $fluffless_r['ok'] && 1 === count( $fluffless_mails ) && 'p' === $fluffless_r['values']['source'] && 'Alice' === $fluffless_r['values']['nom'], 'envoi à la dernière étape' );
	$fluffless_assert( str_contains( (string) $fluffless_mails[0]['message'], 'Nom : Alice' ) && str_contains( (string) $fluffless_mails[0]['message'], 'Ville : Montréal' ), 'réponses des deux étapes dans le courriel' );
	$fluffless_r = Form::process( $fluffless_spost( 2, array( 'ville' => 'Montréal' ), '' ), '' );
	$fluffless_assert( ! $fluffless_r['ok'] && 1 === $fluffless_r['step'] && isset( $fluffless_r['errors']['nom'] ), 'étape sautée acceptée' );
	$fluffless_r = Form::process( $fluffless_spost( 2, array( 'ville' => 'Montréal' ), 'abc.00000000000000000000' ), '' );
	$fluffless_assert( ! $fluffless_r['ok'] && str_contains( $fluffless_r['message'], 'out of date' ), 'état altéré accepté' );
	$fluffless_r = Form::process( $fluffless_spost( 2, array(), $fluffless_state, true ), '' );
	$fluffless_assert( ! $fluffless_r['ok'] && 1 === $fluffless_r['step'] && 'Alice' === $fluffless_r['values']['nom'] && array() === $fluffless_r['errors'], 'retour à l\'étape 1' );
	$fluffless_assert( 1 === count( $fluffless_mails ), 'envoi hors dernière étape' );
	$fluffless_html = do_blocks( $fluffless_steps );
	$fluffless_assert( str_contains( $fluffless_html, 'class="fluffless-formulaire has-steps wp-block-fluffless-formulaire"' ) && str_contains( $fluffless_html, '>Step 1 of 2</p>' ), 'progression' );
	$fluffless_assert( str_contains( $fluffless_html, '<fieldset class="fluffless-etape" id="fl-step1234-etape-1"><legend>Vous</legend>' ) && str_contains( $fluffless_html, 'name="fl[nom]"' ) && ! str_contains( $fluffless_html, 'name="fl[ville]"' ), 'étape 1 seule affichée' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="fl_step" value="1"' ) && str_contains( $fluffless_html, 'name="fl_state" value=""' ) && str_contains( $fluffless_html, 'name="fl[source]"' ), 'champs techniques des étapes' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'name="fl_back"' ) && str_contains( $fluffless_html, '<button type="submit" class="wp-element-button">Next</button>' ), 'boutons de la première étape' );
	Form::show_errors( Form::process( $fluffless_spost( 1, array( 'nom' => 'Alice' ) ), '' ) );
	$fluffless_html = do_blocks( $fluffless_steps );
	Form::show_errors( null );
	$fluffless_assert( str_contains( $fluffless_html, 'id="fl-step1234-progress" tabindex="-1" autofocus>Step 2 of 2</p>' ) && ! str_contains( $fluffless_html, 'role="alert"' ), 'progression de l\'étape 2' );
	$fluffless_assert( str_contains( $fluffless_html, '<legend>Détails</legend>' ) && str_contains( $fluffless_html, 'name="fl[ville]"' ) && ! str_contains( $fluffless_html, 'name="fl[nom]"' ), 'étape 2 seule affichée' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="fl_state" value="' . esc_attr( Form::state( $fluffless_sdef['key'], array( 'nom' => 'Alice' ) ) ) . '"' ), 'réponses portées' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="fl_back" value="1" formnovalidate>Previous</button><button type="submit" class="wp-element-button">Send</button>' ), 'boutons de la dernière étape' );

	// 18. Compositions : cinq modèles de formulaire, lisibles par le module.
	foreach ( array( 'contact', 'devis', 'infolettre', 'evenement', 'candidature' ) as $fluffless_model ) {
		$fluffless_file = dirname( __DIR__ ) . '/patterns/formulaire-' . $fluffless_model . '.php';
		$fluffless_assert( is_file( $fluffless_file ), 'modèle absent : ' . $fluffless_model );
		ob_start();
		include $fluffless_file;
		$fluffless_found = null;
		$fluffless_walk  = static function ( array $blocks ) use ( &$fluffless_walk, &$fluffless_found ): void {
			foreach ( $blocks as $b ) {
				if ( Form::FORM === ( $b['blockName'] ?? '' ) ) {
					$fluffless_found = $b;
					return;
				}
				$fluffless_walk( (array) ( $b['innerBlocks'] ?? array() ) );
			}
		};
		$fluffless_walk( parse_blocks( (string) ob_get_clean() ) );
		$fluffless_mdef = null === $fluffless_found ? null : Form::definition_from_blocks( (array) $fluffless_found['attrs'], $fluffless_found['innerBlocks'] );
		$fluffless_assert( null !== $fluffless_mdef && count( $fluffless_mdef['fields'] ) >= 2 && in_array( $fluffless_mdef['fields'][0]['type'], array( 'text', 'email' ), true ) && count( $fluffless_mdef['fields'] ) === count( array_unique( array_column( $fluffless_mdef['fields'], 'name' ) ) ), 'modèle illisible : ' . $fluffless_model );
		if ( null !== $fluffless_mdef ) {
			foreach ( $fluffless_mdef['fields'] as $fluffless_mf ) {
				$fluffless_assert( '' === $fluffless_mf['when'] || in_array( $fluffless_mf['when'], array_column( $fluffless_mdef['fields'], 'name' ), true ), 'condition orpheline dans ' . $fluffless_model . ' : ' . $fluffless_mf['when'] );
			}
		}
	}
	// 13. Saisie automatique (WCAG 1.3.5) : jeton choisi, liste blanche, défaut par type.
	$fluffless_ac_content = '<!-- wp:fluffless/formulaire {"formId":"abcd1234","recipients":"a@example.com"} -->'
		. '<!-- wp:fluffless/champ {"type":"text","label":"Prénom et nom","autocomplete":"name"} /-->'
		. '<!-- wp:fluffless/champ {"type":"text","label":"Société","autocomplete":" Organization "} /-->'
		. '<!-- wp:fluffless/champ {"type":"text","label":"Piégé","autocomplete":"off\" onfocus=\"x"} /-->'
		. '<!-- wp:fluffless/champ {"type":"text","label":"Inconnu","autocomplete":"banane"} /-->'
		. '<!-- wp:fluffless/champ {"type":"email","label":"Courriel","autocomplete":"work-email"} /-->'
		. '<!-- wp:fluffless/champ {"type":"email","label":"Courriel perso","autocomplete":"email"} /-->'
		. '<!-- wp:fluffless/champ {"type":"phone","label":"Téléphone"} /-->'
		. '<!-- wp:fluffless/champ {"type":"textarea","label":"Message","autocomplete":"name"} /-->'
		. '<!-- /wp:fluffless/formulaire -->';
	$fluffless_html       = do_blocks( $fluffless_ac_content );
	$fluffless_assert( str_contains( $fluffless_html, 'name="fl[prenom-et-nom]" value="" autocomplete="name">' ), 'autocomplete choisi émis sur un champ texte' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="fl[societe]" value="" autocomplete="organization">' ), 'jeton normalisé (casse, espaces)' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="fl[piege]" value="">' ), 'jeton piégé écarté, pas d\'attribut injecté' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'onfocus' ) && ! str_contains( $fluffless_html, 'banane' ), 'jeton hors liste blanche écarté' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="fl[courriel]" value="" autocomplete="email">' ), 'jeton invalide : défaut du type courriel' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="fl[courriel-perso]" value="" autocomplete="email">' ), 'jeton explicite égal au défaut' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="fl[telephone]" value="" autocomplete="tel">' ), 'défaut du type téléphone' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<textarea id="fl-abcd1234-message" name="fl[message]" rows="6" autocomplete' ), 'zone de texte sans autocomplete' );
	foreach ( Form::AUTOCOMPLETE_TOKENS as $fluffless_token ) {
		$fluffless_assert( 1 === preg_match( '/^[a-z0-9-]+$/', $fluffless_token ), 'jeton de liste blanche mal formé : ' . $fluffless_token );
	}
	$fluffless_block_json = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/blocks/champ/block.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du paquet.
	$fluffless_assert( is_array( $fluffless_block_json ) && 'string' === ( $fluffless_block_json['attributes']['autocomplete']['type'] ?? null ) && '' === ( $fluffless_block_json['attributes']['autocomplete']['default'] ?? null ), 'attribut autocomplete déclaré dans block.json' );

	// 14. Sommaire d'erreurs : séparateur traduisible (espace avant les deux-points seulement en français).
	$fluffless_r = Form::process( $fluffless_bad, $fluffless_ip );
	Form::show_errors( $fluffless_r );
	$fluffless_html = do_blocks( $fluffless_content );
	Form::show_errors( null );
	$fluffless_assert( 1 === preg_match( '~<li><a href="#fl-abcd1234-nom">Nom</a>: [^<]+</li>~', $fluffless_html ), 'sommaire en anglais : deux-points collés' );
	if ( switch_to_locale( 'fr_CA' ) ) {
		Form::show_errors( $fluffless_r );
		$fluffless_html = do_blocks( $fluffless_content );
		Form::show_errors( null );
		restore_previous_locale();
		$fluffless_assert( 1 === preg_match( '~<li><a href="#fl-abcd1234-nom">Nom</a>\x{00A0}: [^<]+</li>~u', $fluffless_html ), 'sommaire en fr_CA : espace insécable avant les deux-points' );
	} else {
		$fluffless_assert( false, 'langue fr_CA non installée dans l\'instance de test' );
	}
	// 20. Destinataires selon une réponse : adresses par option, jamais dans la page ; repli sur ceux du formulaire.
	delete_transient( Form::rate_key( $fluffless_ip ) );
	$fluffless_route = '<!-- wp:fluffless/formulaire {"formId":"rout1234","recipients":"base@example.com","confirm":false,"routeField":"service","routes":{"Ventes":"ventes@example.com, v2@example.com ventes@example.com","Soutien":"soutien@example.com","Autre":"pas-une-adresse","Fantôme":"fantome@example.com"}} -->'
		. '<!-- wp:fluffless/champ {"type":"radio","label":"Service","options":"Ventes\nSoutien\nAutre\nPresse","required":true} /-->'
		. '<!-- wp:fluffless/champ {"type":"email","label":"Courriel","required":true} /-->'
		. '<!-- /wp:fluffless/formulaire -->';
	$fluffless_html  = do_blocks( $fluffless_route );
	$fluffless_assert( 1 === preg_match( '/name="' . Form::FIELD_KEY . '" value="(rout1234-[a-f0-9]{8})"/', $fluffless_html, $fluffless_m ), 'clé du formulaire acheminé' );
	$fluffless_rkey = $fluffless_m[1] ?? '';
	$fluffless_assert( ! str_contains( $fluffless_html, 'ventes@example.com' ) && ! str_contains( $fluffless_html, 'base@example.com' ), 'adresse d\'acheminement visible dans la page' );
	$fluffless_rdef = Form::definition( $fluffless_rkey );
	$fluffless_assert( null !== $fluffless_rdef && 'service' === $fluffless_rdef['route_field'] && array( 'ventes@example.com', 'v2@example.com' ) === ( $fluffless_rdef['routes']['Ventes'] ?? null ) && ! isset( $fluffless_rdef['routes']['Autre'] ), 'acheminement mal lu (doublon, adresse invalide)' );
	$fluffless_rpost = static fn( string $service, array $extra = array() ) => array_merge(
		array(
			Form::FIELD_KEY   => $fluffless_rkey,
			Form::FIELD_TOKEN => Form::token( $fluffless_rkey, time() - 10 ),
			Form::FIELD_DATA  => array(
				'service'  => $service,
				'courriel' => 'alice@example.com',
			),
		),
		$extra
	);
	/**
	 * Courriels captés, remis à zéro.
	 *
	 * @var list<array<string, mixed>> $fluffless_mails
	 */
	$fluffless_mails = array();
	$fluffless_r     = Form::process( $fluffless_rpost( 'Ventes' ), '' );
	$fluffless_assert( $fluffless_r['ok'] && 1 === count( $fluffless_mails ) && array( 'ventes@example.com', 'v2@example.com' ) === $fluffless_mails[0]['to'], 'option Ventes non acheminée' );
	/**
	 * Courriels captés, remis à zéro.
	 *
	 * @var list<array<string, mixed>> $fluffless_mails
	 */
	$fluffless_mails = array();
	$fluffless_r     = Form::process( $fluffless_rpost( 'Presse' ), '' );
	$fluffless_assert( $fluffless_r['ok'] && array( 'base@example.com' ) === ( $fluffless_mails[0]['to'] ?? null ), 'option sans adresse : destinataires du formulaire' );
	/**
	 * Courriels captés, remis à zéro.
	 *
	 * @var list<array<string, mixed>> $fluffless_mails
	 */
	$fluffless_mails = array();
	$fluffless_r     = Form::process( $fluffless_rpost( 'Fantôme' ), '' );
	$fluffless_assert( ! $fluffless_r['ok'] && array() === $fluffless_mails, 'option inconnue acceptée' );
	if ( null !== $fluffless_rdef ) {
		Form::send(
			array_merge( $fluffless_rdef, array( 'confirm' => true ) ),
			array(
				'service'  => 'Ventes',
				'courriel' => 'alice@example.com',
			)
		);
		$fluffless_copy = $fluffless_mails[1] ?? array();
		$fluffless_assert( 'alice@example.com' === ( $fluffless_copy['to'] ?? '' ) && in_array( 'Reply-To: base@example.com', (array) ( $fluffless_copy['headers'] ?? array() ), true ), 'copie : réponse vers une adresse d\'acheminement' );
	}
	$fluffless_assert( str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/Lifecycle.php' ), 'delete_option( self::OPTION_ALTCHA_SINCE );' ), 'instant d\'activation gardé à la désinstallation' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( null !== $fluffless_rdef ) {
		$fluffless_cdef = array_merge(
			$fluffless_rdef,
			array(
				'route_field' => 'x',
				'routes'      => array(
					'A' => array( 'a@example.com' ),
					'B' => array( 'b@example.com', 'a@example.com' ),
				),
				'recipients'  => array(),
			)
		);
		$fluffless_assert( array( 'a@example.com', 'b@example.com' ) === Form::recipients( $fluffless_cdef, array( 'x' => array( 'A', 'B' ) ) ), 'cases : union des adresses sans doublon' );
		$fluffless_assert( array( get_option( 'admin_email' ) ) === Form::recipients( $fluffless_cdef, array( 'x' => array() ) ), 'aucune case : adresse d\'administration' );
		$fluffless_cdef['recipients'] = array( 'base@example.com' );
		$fluffless_assert( array( 'a@example.com', 'base@example.com' ) === Form::recipients( $fluffless_cdef, array( 'x' => array( 'A', 'C' ) ) ), 'case sans adresse : destinataires du formulaire ajoutés' );
	}
	$fluffless_assert( array() === Form::form_attributes( array( 'routes' => array( 'A' => 'a@example.com' ) ) )['routes'], 'adresses sans champ d\'acheminement gardées' );
	$fluffless_all                = get_option( Form::OPTION );
	$fluffless_all['vieux1234-k'] = array(
		'id'         => 'vieux1234',
		'recipients' => array(),
		'fields'     => array(),
	);
	update_option( Form::OPTION, $fluffless_all, false );
	$fluffless_old = Form::definition( 'vieux1234-k' );
	$fluffless_assert( null !== $fluffless_old && '' === $fluffless_old['route_field'] && array() === $fluffless_old['routes'], 'définition d\'avant l\'acheminement non complétée' );

	// 21. Preuve de travail (ALTCHA) : éteinte par défaut ; allumée, exigée au seul envoi final, une fois.
	$fluffless_assert( false === Form::defaults()['altcha'] && ! str_contains( $fluffless_html, 'data-fl-altcha' ) && ! wp_script_is( Altcha::SCRIPT, 'enqueued' ), 'preuve de travail allumée par défaut' );
	delete_option( Form::ALTCHA_SINCE );
	add_filter( 'fluffless_form_altcha_secure', '__return_true' ); // Instance de test en HTTP.
	$fluffless_set( array( 'altcha' => true ) );
	$fluffless_hooked = false !== has_action( 'update_option_' . Options::NAME, array( Form::class, 'altcha_switched' ) );
	$fluffless_assert( ! $fluffless_hooked || abs( time() - (int) get_option( Form::ALTCHA_SINCE ) ) < 5, 'instant d\'activation non noté' );
	update_option( Form::ALTCHA_SINCE, time() - 60, false );
	$fluffless_html = do_blocks( $fluffless_route );
	$fluffless_assert( str_contains( $fluffless_html, ' data-fl-altcha="' . esc_url( Altcha::url() ) . '"' ) && str_contains( $fluffless_html, '<input type="hidden" name="altcha" value="" autocomplete="off">' ) && str_contains( $fluffless_html, '<noscript>' ), 'champ ou adresse du défi absents' );
	$fluffless_assert( str_contains( $fluffless_html, 'role="status" data-fl-busy="Checking that you are not a robot…"' ), 'zone d\'état du calcul absente' );
	$fluffless_assert( wp_script_is( Altcha::SCRIPT, 'enqueued' ), 'script de la preuve non mis en file' );
	/**
	 * Courriels captés, remis à zéro.
	 *
	 * @var list<array<string, mixed>> $fluffless_mails
	 */
	$fluffless_mails = array();
	$fluffless_r     = Form::process( $fluffless_rpost( 'Ventes' ), '' );
	$fluffless_assert( ! $fluffless_r['ok'] && str_contains( $fluffless_r['message'], 'JavaScript' ) && array() === $fluffless_mails, 'envoi sans preuve accepté' );
	$fluffless_r = Form::process( $fluffless_rpost( 'Ventes', array( Altcha::FIELD => '-' ) ), '' );
	$fluffless_assert( ! $fluffless_r['ok'] && str_contains( $fluffless_r['message'], 'connection' ) && array() === $fluffless_mails, 'défi non obtenu : message' );
	// Page rendue avant l'activation (en cache) : envoyable sans preuve jusqu'à l'échéance du jeton.
	$fluffless_r = Form::process( $fluffless_rpost( 'Ventes', array( Form::FIELD_TOKEN => Form::token( $fluffless_rkey, time() - 120 ) ) ), '' );
	$fluffless_assert( $fluffless_r['ok'] && 1 === count( $fluffless_mails ), 'page rendue avant l\'activation refusée' );
	/**
	 * Courriels captés, remis à zéro.
	 *
	 * @var list<array<string, mixed>> $fluffless_mails
	 */
	$fluffless_mails = array();
	$fluffless_c     = Altcha::challenge( null, 1234 );
	$fluffless_assert( 1 === preg_match( Altcha::SALT_PATTERN, $fluffless_c['salt'] ) && hash( 'sha256', $fluffless_c['salt'] . '1234' ) === $fluffless_c['challenge'], 'défi mal formé' );
	$fluffless_assert( ! Altcha::verify( Altcha::payload( $fluffless_c, 1235 ) ), 'mauvais nombre accepté' );
	$fluffless_r = Form::process( $fluffless_rpost( 'Ventes', array( Altcha::FIELD => Altcha::payload( $fluffless_c, 1234 ) ) ), '' );
	$fluffless_assert( $fluffless_r['ok'] && 1 === count( $fluffless_mails ), 'preuve valide refusée' );
	$fluffless_r = Form::process( $fluffless_rpost( 'Ventes', array( Altcha::FIELD => Altcha::payload( $fluffless_c, 1234 ) ) ), '' );
	$fluffless_assert( ! $fluffless_r['ok'] && 1 === count( $fluffless_mails ), 'preuve rejouée acceptée' );
	$fluffless_used                = array( $fluffless_c['challenge'] );
	$fluffless_forged              = Altcha::challenge( null, 7 );
	$fluffless_forged['signature'] = str_repeat( '0', 64 );
	$fluffless_assert( ! Altcha::verify( Altcha::payload( $fluffless_forged, 7 ) ), 'signature forgée acceptée' );
	$fluffless_late = Altcha::challenge( time() - Altcha::TTL - 120, 7 );
	$fluffless_assert( ! Altcha::verify( Altcha::payload( $fluffless_late, 7 ) ), 'défi échu accepté' );
	$fluffless_far = Altcha::challenge( time() + DAY_IN_SECONDS, 7 );
	$fluffless_assert( ! Altcha::verify( Altcha::payload( $fluffless_far, 7 ) ), 'échéance lointaine acceptée' );
	$fluffless_assert( ! Altcha::verify( '' ) && ! Altcha::verify( '-' ) && ! Altcha::verify( base64_encode( 'pas du json' ) ) && ! Altcha::verify( str_repeat( 'A', 3000 ) ), 'charge utile vide ou illisible acceptée' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- charge utile de test.
	// Défi servi par la route : sans cache ; résolu par force brute comme le ferait le navigateur.
	$fluffless_response = Altcha::respond();
	$fluffless_served   = $fluffless_response->get_data();
	$fluffless_assert( 'no-store, max-age=0' === ( $fluffless_response->get_headers()['Cache-Control'] ?? '' ) && Altcha::MAX_NUMBER === $fluffless_served['maxnumber'], 'réponse du défi' );
	$fluffless_found = -1;
	for ( $fluffless_n = 0; $fluffless_n <= $fluffless_served['maxnumber']; $fluffless_n++ ) {
		if ( hash( 'sha256', $fluffless_served['salt'] . $fluffless_n ) === $fluffless_served['challenge'] ) {
			$fluffless_found = $fluffless_n;
			break;
		}
	}
	$fluffless_assert( $fluffless_found >= 0 && Altcha::verify( Altcha::payload( $fluffless_served, $fluffless_found ) ), 'défi servi insoluble ou refusé' );
	$fluffless_used[] = $fluffless_served['challenge'];
	// Étapes : la preuve n'est demandée qu'à la dernière.
	$fluffless_r = Form::process( $fluffless_spost( 1, array( 'nom' => 'Alice' ) ), '' );
	$fluffless_assert( ! $fluffless_r['ok'] && 2 === $fluffless_r['step'] && '' === $fluffless_r['message'], 'preuve exigée à une étape intermédiaire' );
	$fluffless_html = do_blocks( $fluffless_steps );
	$fluffless_assert( ! str_contains( $fluffless_html, 'data-fl-altcha' ) && ! str_contains( $fluffless_html, 'name="altcha"' ), 'preuve posée à une étape intermédiaire' );
	Form::show_errors( $fluffless_r );
	$fluffless_html = do_blocks( $fluffless_steps );
	Form::show_errors( null );
	$fluffless_assert( str_contains( $fluffless_html, 'data-fl-altcha' ) && str_contains( $fluffless_html, 'name="altcha"' ), 'preuve absente de la dernière étape' );
	// État du module : route bloquée aux visiteurs, puis servie par un cache (essai mémorisé).
	set_transient( Form::ALTCHA_PROBE, 403, MINUTE_IN_SECONDS );
	$fluffless_status = Form::status();
	$fluffless_assert( str_contains( $fluffless_status, '(HTTP 403)' ) && ! str_contains( $fluffless_status, 'needs HTTPS' ) && ! str_contains( $fluffless_status, 'Time checks' ), 'route bloquée non signalée : ' . $fluffless_status );
	set_transient( Form::ALTCHA_PROBE, Form::PROBE_CACHED, MINUTE_IN_SECONDS );
	$fluffless_assert( str_contains( Form::status(), 'same proof of work challenge' ), 'défi en cache non signalé' );
	add_filter( 'fluffless_suite_form_timing_checks', '__return_false' );
	$fluffless_assert( str_contains( Form::status(), 'Time checks are off' ), 'contrôles de temps coupés non signalés' );
	remove_filter( 'fluffless_suite_form_timing_checks', '__return_false' );
	// Sans HTTPS, la preuve est sautée (Web Crypto absent) plutôt que de tout refuser.
	remove_filter( 'fluffless_form_altcha_secure', '__return_true' );
	add_filter( 'fluffless_form_altcha_secure', '__return_false' );
	$fluffless_assert( ! Form::proof() && str_contains( Form::status(), 'it is skipped' ), 'absence de HTTPS non signalée' );
	$fluffless_assert( ! str_contains( do_blocks( $fluffless_route ), 'data-fl-altcha' ), 'preuve posée sans HTTPS' );
	/**
	 * Courriels captés, remis à zéro.
	 *
	 * @var list<array<string, mixed>> $fluffless_mails
	 */
	$fluffless_mails = array();
	$fluffless_r     = Form::process( $fluffless_rpost( 'Ventes' ), '' );
	$fluffless_assert( $fluffless_r['ok'] && 1 === count( $fluffless_mails ), 'envoi refusé sans HTTPS' );
	remove_filter( 'fluffless_form_altcha_secure', '__return_false' );
	$fluffless_set( Form::defaults() );
	$fluffless_assert( ! $fluffless_hooked || ( false === get_option( Form::ALTCHA_SINCE ) && false === get_transient( Form::ALTCHA_PROBE ) ), 'instant d\'activation gardé après l\'arrêt' );

	// Plugin Check : get_posts() supprime déjà les filtres, la clé redondante est signalée (P6a-07).
	$fluffless_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Modules/Form.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lecture locale du code testé.
	$fluffless_assert( ! str_contains( $fluffless_source, "'suppress_filters'" ), 'clé suppress_filters redondante dans get_posts()' );

	// Purge quotidienne : une réponse échue s'efface sans nouvel envoi ni visite du panneau (C3-1-03).
	$fluffless_cron = 'fluffless_form_purge';
	$fluffless_assert( false !== wp_next_scheduled( $fluffless_cron ), 'purge quotidienne non planifiée' );
	$fluffless_old = Form::store( Form::definition( $fluffless_key ) ?? throw new RuntimeException( 'Définition du formulaire de test absente.' ), array( 'nom' => 'Ancienne' ) );
	wp_update_post(
		array(
			'ID'            => $fluffless_old,
			'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ),
		)
	);
	do_action( 'fluffless_form_purge' );
	$fluffless_assert( $fluffless_old > 0 && null === get_post( $fluffless_old ), 'réponse échue gardée après la tâche quotidienne' );
	// Course sur la preuve : deux envois lisent « jamais servie » avant que l'un ne la note (P6a-05).
	$fluffless_race   = Altcha::challenge( null, 42 );
	$fluffless_marker = Altcha::USED . substr( $fluffless_race['challenge'], 0, 32 ); // Ancien nom (transitoire).
	$fluffless_stale  = static fn() => false;
	add_filter( 'transient_' . $fluffless_marker, $fluffless_stale ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- crochet de WordPress, lecture périmée simulée.
	$fluffless_first  = Altcha::verify( Altcha::payload( $fluffless_race, 42 ) );
	$fluffless_second = Altcha::verify( Altcha::payload( $fluffless_race, 42 ) );
	remove_filter( 'transient_' . $fluffless_marker, $fluffless_stale ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- idem.
	$fluffless_assert( $fluffless_first && ! $fluffless_second, 'preuve acceptée deux fois en course' );
	$fluffless_used[] = $fluffless_race['challenge'];
	foreach ( $fluffless_used as $fluffless_challenge ) {
		delete_option( Altcha::MARK . substr( $fluffless_challenge, 0, 32 ) );
	}
	// Ménage quotidien : une marque échue part, une marque en cours reste.
	add_option( Altcha::MARK . 'echue', (string) ( time() - 10 ), '', false );
	add_option( Altcha::MARK . 'encours', (string) ( time() + 600 ), '', false );
	Altcha::sweep();
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( Altcha::MARK . 'echue', 'options' );
	wp_cache_delete( Altcha::MARK . 'encours', 'options' );
	$fluffless_assert( false === get_option( Altcha::MARK . 'echue' ) && false !== get_option( Altcha::MARK . 'encours' ), 'ménage des marques de preuve' );
	delete_option( Altcha::MARK . 'encours' );
	// Copie à la personne : plafond global par jour, le formulaire ne sert pas de relais (P6a-01).
	$fluffless_cap = static fn() => 1;
	add_filter( 'fluffless_form_copy_cap', $fluffless_cap );
	Form::process( $fluffless_base, '' );
	/**
	 * Courriels captés.
	 *
	 * @var list<array<string, mixed>> $fluffless_mails
	 */
	$fluffless_mails = array();
	$fluffless_r     = Form::process( $fluffless_base, '' );
	remove_filter( 'fluffless_form_copy_cap', $fluffless_cap );
	$fluffless_to = array_map( static fn( array $mail ) => $mail['to'] ?? null, $fluffless_mails );
	$fluffless_assert( $fluffless_r['ok'] && 1 === count( $fluffless_mails ) && ! in_array( 'alice@example.com', $fluffless_to, true ), 'copie envoyée au-delà du plafond global : ' . count( $fluffless_mails ) );
	// Safari garde le select natif sans appearance:none : court, bordure fine (C1-7-01).
	// C3-1-01 : Outils > Exporter / Effacer les données personnelles trouvent le courriel dans la Suite.
	$fluffless_mail_saved = get_transient( \Fluffless\Suite\Core\Lifecycle::TRANSIENT_MAIL_FAILURES );
	$fluffless_pending    = get_option( \Fluffless\Suite\Core\Lifecycle::OPTION_NEWSLETTER_PENDING );
	$fluffless_who        = 'Visiteuse.Effacer@example.com';
	$fluffless_def_ok     = Form::definition( $fluffless_key );
	$fluffless_mine       = Form::store(
		$fluffless_def_ok,
		array(
			'nom'      => 'Visiteuse',
			'courriel' => 'visiteuse.effacer@example.com',
		)
	);
	$fluffless_other      = Form::store(
		$fluffless_def_ok,
		array(
			'nom'      => 'Autre',
			'courriel' => 'xvisiteuse.effacer@example.com',
		)
	);
	set_transient(
		\Fluffless\Suite\Core\Lifecycle::TRANSIENT_MAIL_FAILURES,
		array(
			array(
				'time'    => time(),
				'to'      => 'visiteuse.effacer@example.com',
				'message' => 'refus',
			),
			array(
				'time'    => time(),
				'to'      => 'site@example.com',
				'message' => 'refus',
			),
		),
		HOUR_IN_SECONDS
	);
	update_option(
		\Fluffless\Suite\Core\Lifecycle::OPTION_NEWSLETTER_PENDING,
		array(
			array(
				'time'   => time(),
				'email'  => 'visiteuse.effacer@example.com',
				'reason' => 'network',
			),
		),
		false
	);
	$fluffless_journal = \Fluffless\Suite\Modules\Security\Journal::table_exists();
	if ( $fluffless_journal ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- ligne de test retirée par l'effaceur.
		$GLOBALS['wpdb']->insert(
			\Fluffless\Suite\Modules\Security\Journal::table(),
			array(
				'created' => gmdate( 'Y-m-d H:i:s' ),
				'event'   => 'login_failed',
				'subject' => 'visiteuse.effacer@example.com',
				'ip'      => '203.0.113.9',
			)
		);
	}
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtres du cœur.
	$fluffless_exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$fluffless_erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$fluffless_assert( isset( $fluffless_exporters['fluffless-suite'], $fluffless_erasers['fluffless-suite'] ), 'C3-1-01 : exportateur ou effaceur absent' );
	if ( isset( $fluffless_exporters['fluffless-suite'], $fluffless_erasers['fluffless-suite'] ) ) {
		$fluffless_export = call_user_func( $fluffless_exporters['fluffless-suite']['callback'], $fluffless_who, 1 );
		$fluffless_flat   = wp_json_encode( $fluffless_export );
		$fluffless_items  = array_count_values( array_column( $fluffless_export['data'], 'group_id' ) );
		$fluffless_assert( true === $fluffless_export['done'] && 1 === ( $fluffless_items['fluffless-form-responses'] ?? 0 ) && str_contains( (string) $fluffless_flat, 'Visiteuse' ) && ! str_contains( (string) $fluffless_flat, 'Autre' ), 'C3-1-01 : export des réponses : ' . $fluffless_flat );
		$fluffless_assert( 1 === ( $fluffless_items['fluffless-mail-failures'] ?? 0 ) && 1 === ( $fluffless_items['fluffless-newsletter-pending'] ?? 0 ), 'C3-1-01 : export des échecs : ' . $fluffless_flat );
		$fluffless_assert( ! $fluffless_journal || ( 1 === ( $fluffless_items['fluffless-activity-log'] ?? 0 ) && str_contains( (string) $fluffless_flat, '203.0.113.9' ) ), 'C3-1-01 : export du journal : ' . $fluffless_flat );
		$fluffless_erase = call_user_func( $fluffless_erasers['fluffless-suite']['callback'], $fluffless_who, 1 );
		$fluffless_assert( true === $fluffless_erase['done'] && true === $fluffless_erase['items_removed'], 'C3-1-01 : effacement non signalé : ' . wp_json_encode( $fluffless_erase ) );
		$fluffless_assert( null === get_post( $fluffless_mine ) && null !== get_post( $fluffless_other ), 'C3-1-01 : réponse non effacée, ou autre réponse effacée' );
		$fluffless_assert( array( 'site@example.com' ) === array_column( \Fluffless\Suite\Modules\Mail::failures(), 'to' ) && array() === \Fluffless\Suite\Modules\Newsletter::pending(), 'C3-1-01 : échecs non effacés' );
		$fluffless_again = call_user_func( $fluffless_exporters['fluffless-suite']['callback'], $fluffless_who, 1 );
		$fluffless_assert( array() === $fluffless_again['data'], 'C3-1-01 : données restantes après effacement : ' . wp_json_encode( $fluffless_again ) );
	}
	if ( $fluffless_journal ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ligne de test, retirée même en cas d'échec.
		$GLOBALS['wpdb']->delete( \Fluffless\Suite\Modules\Security\Journal::table(), array( 'subject' => 'visiteuse.effacer@example.com' ) );
	}
	wp_delete_post( $fluffless_mine, true );
	wp_delete_post( $fluffless_other, true );
	if ( false === $fluffless_mail_saved ) {
		delete_transient( \Fluffless\Suite\Core\Lifecycle::TRANSIENT_MAIL_FAILURES );
	} else {
		set_transient( \Fluffless\Suite\Core\Lifecycle::TRANSIENT_MAIL_FAILURES, $fluffless_mail_saved, HOUR_IN_SECONDS );
	}
	if ( false === $fluffless_pending ) {
		delete_option( \Fluffless\Suite\Core\Lifecycle::OPTION_NEWSLETTER_PENDING );
	} else {
		update_option( \Fluffless\Suite\Core\Lifecycle::OPTION_NEWSLETTER_PENDING, $fluffless_pending, false );
	}

	// C3-1-02 : paragraphe proposé pour la politique : champs gardés, durée réglée, export et effacement.
	$fluffless_privacy = Form::privacy_text();
	$fluffless_assert( str_contains( $fluffless_privacy, (string) Options::get_int( Form::id(), 'keep_days' ) . ' days' ) && str_contains( $fluffless_privacy, 'export' ) && str_contains( $fluffless_privacy, 'IP address' ), 'C3-1-02 : mention du formulaire absente ou incomplète : ' . $fluffless_privacy );
	$fluffless_assert( false !== has_action( 'admin_init', array( Form::class, 'add_privacy_text' ) ), 'C3-1-02 : mention du formulaire non proposée' );

	$fluffless_css = Form::css();
	$fluffless_assert( 1 === preg_match( '/\.fluffless-champ select[^{]*\{[^}]*(?<![-\w])appearance:none/', $fluffless_css ), 'select sans appearance:none' );
	$fluffless_assert( 1 === preg_match( '/\.fluffless-champ select[^{]*\{[^}]*background-image:[a-z-]*gradient/', $fluffless_css ) && str_contains( $fluffless_css, 'forced-colors' ), 'select sans flèche ou sans repli en couleurs forcées' );
	\Fluffless\Suite\Core\Lifecycle::deactivate();
	$fluffless_assert( false === wp_next_scheduled( $fluffless_cron ), 'purge quotidienne gardée après la désactivation' );
} finally {
	remove_filter( 'pre_wp_mail', $fluffless_capture, 10 );
	delete_transient( Form::COPY_PREFIX . gmdate( 'Y-m-d' ) );
	Form::delete_all( 'abcd1234' );
	delete_transient( Form::rate_key( '' ) );
	delete_transient( Form::rate_key( $fluffless_ip ) );
	delete_option( Form::ALTCHA_SINCE );
	delete_transient( Form::ALTCHA_PROBE );
	remove_all_filters( 'fluffless_form_altcha_secure' );
	Form::show_errors( null );
	if ( false === $fluffless_forms ) {
		Form::forget();
	} else {
		update_option( Form::OPTION, $fluffless_forms, false );
	}
	if ( false === $fluffless_original ) {
		delete_option( Options::NAME );
	} else {
		update_option( Options::NAME, $fluffless_original );
	}
	Options::flush();
	if ( null === $fluffless_server ) {
		unset( $_SERVER['REQUEST_URI'] );
	} else {
		$_SERVER['REQUEST_URI'] = $fluffless_server;
	}
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		echo esc_html( 'ÉCHEC : ' . $fluffless_failure ) . "\n";
	}
	exit( 1 );
}
echo esc_html( sprintf( 'Formulaire : %d vérifications réussies.', $fluffless_checks ) ) . "\n";
