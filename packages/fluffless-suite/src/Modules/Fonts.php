<?php
/**
 * Module Polices libres : collection curée dans la Bibliothèque de polices de WordPress.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\GlobalStyles;
use Fluffless\Suite\Core\DeclaresHosts;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Sanitize;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Parcours : la personne ouvre la Bibliothèque de polices (éditeur de site), choisit une famille
 * de la collection « Fluffless » et clique sur Installer. Le navigateur de l'administration
 * télécharge alors UNE fois le fichier WOFF2 (banque Fluffless sur canner.ca), que WordPress copie dans
 * wp-content/fonts/. Les pages publiques servent ensuite ce fichier depuis le domaine du site.
 * Raccourci : « Appliquer ce duo » (écran Polices) fait la même installation côté serveur, puis
 * pose les deux familles sur les titres et le texte dans les styles globaux.
 *
 * Source des données (mesurées le 12 sept. 2026, sous-ensemble latin, style droit seulement) :
 * fichiers variables de l'API Google Fonts css2, licence et axes lus dans METADATA.pb du dépôt
 * github.com/google/fonts (dossier ofl/), axes réels et couverture vérifiés avec fontTools.
 * Couverture du français : tout le français courant ; manquent Ÿ (U+0178, dans latin-ext) et
 * l'espace fine insécable U+202F, que le navigateur prend dans la police de secours.
 *
 * La collection n'est enregistrée que pour l'API REST (que la Bibliothèque de polices appelle) :
 * les visiteurs n'en paient pas le coût.
 *
 * Deux provenances. Familles OFL : fichier servi par la banque Fluffless ; src est l'hôte
 * d'origine de secours, vide pour celles converties depuis l'archive (banque seulement).
 * Familles 'licence' => 'itf-ffl' (Fontshare) : le fichier vient toujours de cdn.fontshare.com,
 * qui reste le distributeur ; sa licence interdit de le remettre sur un serveur public tiers.
 *
 * Chaque famille porte l'empreinte SHA-256 de son fichier : celle de la banque (collection.json)
 * pour les familles OFL, celle du fichier de cdn.fontshare.com (mesurée le 14 sept. 2026) pour les
 * familles Fontshare. Un fichier téléchargé qui ne la reproduit pas est refusé, d'où qu'il vienne. Un site qui vise
 * sa propre copie (filtre fluffless_font_src) donne son empreinte par le filtre fluffless_font_sha256.
 *
 * @phpstan-type Family array{name: string, category: string, weight: string, stretch?: string, axes: string, bytes: int, sha256: string, src: string, designer: string, licence?: string}
 */
final class Fonts implements Module, DeclaresHosts {

	public const COLLECTION = 'fluffless';
	public const PAGE       = 'fluffless-fonts';

	/** Budget polices du projet (docs/PLAN.md, budgets de sobriété). */
	public const BUDGET_BYTES = 100000;
	public const BUDGET_FILES = 2;

	/** Formulaire « Appliquer ce duo » et option qui garde les polices d'avant le premier duo. */
	public const ACTION = 'fluffless_fonts_pair';
	public const OPTION = 'fluffless_fonts';

	/** Chemins des styles globaux touchés par un duo. */
	public const PATH_BODY    = 'styles.typography.fontFamily';
	public const PATH_HEADING = 'styles.elements.heading.typography.fontFamily';
	public const PATH_CUSTOM  = 'settings.typography.fontFamilies.custom';

	/** Plafond d'un fichier téléchargé : le plus lourd de la collection pèse moins de 100 ko. */
	private const MAX_FILE_BYTES = 200000;

	/** Banque de polices Fluffless par défaut. */
	public const BANK = 'https://fluffless-polices.canner.app';

	/** Licence gratuite de Fontshare (Indian Type Foundry). */
	public const FONTSHARE_LICENCE = 'https://www.fontshare.com/licenses/itf-ffl';

	/** Sous-ensemble latin de Google Fonts : couvre le français (é, ç, œ, « », ’, €). */
	public const LATIN = 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD';

	/** Borne de lecture des fichiers installés. */
	private const MAX_FACES = 100;

	/**
	 * Collection curée : SIL Open Font License 1.1, sauf les familles Fontshare (licence ITF).
	 * Choix : caractère affirmé ou lisibilité éprouvée, en évitant les polices vues partout (Inter, Roboto, Montserrat, Poppins).
	 * Familles de l'archive (mesurées le 14 sept. 2026) : sous-ensemble latin, métadonnées de licence
	 * gardées ; CirrusCumulus, Lineal et Terminal Grotesque restent complètes (nom de police réservé :
	 * un sous-ensemble serait une version modifiée, qui devrait changer de nom).
	 *
	 * @var array<string, Family>
	 */
	private const FAMILIES = array(
		'bricolage-grotesque'        => array(
			'name'     => 'Bricolage Grotesque',
			'category' => 'sans-serif',
			'weight'   => '200 800',
			'axes'     => 'wght 200-800',
			'bytes'    => 41236,
			'sha256'   => '4fd48b2c1ab27220e71f15f990550261b35245c3bdfd8d8025b4bdac0459ee2d',
			'src'      => 'https://fonts.gstatic.com/s/bricolagegrotesque/v9/3y9H6as8bTXq_nANBjzKo3IeZx8z6up5BeSl5jBNz_19PpbpMXuECpwUxJBOm_OJWiawA1XphjhQYg.woff2',
			'designer' => 'Mathieu Triay',
		),
		'instrument-sans'            => array(
			'name'     => 'Instrument Sans',
			'category' => 'sans-serif',
			'weight'   => '400 700',
			'axes'     => 'wght 400-700',
			'bytes'    => 29904,
			'sha256'   => '6219bc4bfdfc5d9b2201dcdf046218b122a758f932e25ed5f168f929b7ca2311',
			'src'      => 'https://fonts.gstatic.com/s/instrumentsans/v4/pxiTypc9vsFDm051Uf6KVwgkfoSxQ0GsQv8ToedPibnr0SZe1ZuWi3g.woff2',
			'designer' => 'Rodrigo Fuenzalida, Jordan Egstad',
		),
		'instrument-serif'           => array(
			'name'     => 'Instrument Serif',
			'category' => 'serif',
			'weight'   => '400',
			'axes'     => 'static 400',
			'bytes'    => 15040,
			'sha256'   => '60c06664b5a95c7de6cc3e00d1f9034d78bd1e40b564016b241674449a067d4d',
			'src'      => 'https://fonts.gstatic.com/s/instrumentserif/v5/jizBRFtNs2ka5fXjeivQ4LroWlx-6zUTjnTLgNs.woff2',
			'designer' => 'Rodrigo Fuenzalida, Jordan Egstad',
		),
		'anybody'                    => array(
			'name'     => 'Anybody',
			'category' => 'display',
			'weight'   => '100 900',
			'stretch'  => '50% 150%',
			'axes'     => 'wdth 50-150, wght 100-900',
			'bytes'    => 56860,
			'sha256'   => '8dfc02ccf3330af0d00deaaf7a0f415bdbffd74eab80f944f6c4fcaf1948b1e5',
			'src'      => 'https://fonts.gstatic.com/s/anybody/v13/VuJxdNvK2Ib2ppdWSKHdOaNkaRs.woff2',
			'designer' => 'Tyler Finck',
		),
		'atkinson-hyperlegible-next' => array(
			'name'     => 'Atkinson Hyperlegible Next',
			'category' => 'sans-serif',
			'weight'   => '200 800',
			'axes'     => 'wght 200-800',
			'bytes'    => 34024,
			'sha256'   => '1e4cea71d75ec427581d6259fc07148a2e60d60d16cabf4b4f5360487b3f9dc3',
			'src'      => 'https://fonts.gstatic.com/s/atkinsonhyperlegiblenext/v7/NaPNcYPdHfdVxJw0IfIP0lvYFqijb-UxCtm5_wdGseiJn3q0pkZ_.woff2',
			'designer' => 'Braille Institute, Applied Design Works, Elliott Scott, Megan Eiswerth, Letters From Sweden',
		),
		'literata'                   => array(
			'name'     => 'Literata',
			'category' => 'serif',
			'weight'   => '200 900',
			'axes'     => 'wght 200-900 (opsz axis left out to save weight)',
			'bytes'    => 52720,
			'sha256'   => '0636e63f70c9a3c644bf6579c445339df38015a2e2b21c6a898813f4c8581e0e',
			'src'      => 'https://fonts.gstatic.com/s/literata/v40/or38Q6P12-iJxAIgLa78DkrbXsDgk0oVDaDlbJ5W7i5aOg.woff2',
			'designer' => 'TypeTogether',
		),
		'newsreader'                 => array(
			'name'     => 'Newsreader',
			'category' => 'serif',
			'weight'   => '200 800',
			'axes'     => 'wght 200-800 (opsz axis left out to save weight)',
			'bytes'    => 58152,
			'sha256'   => '2a69ec1c0fb79a464de0e19957cdb3a65b5f626d85fffd164f9de557d3a64878',
			'src'      => 'https://fonts.gstatic.com/s/newsreader/v26/cY9VfjOCX1hbuyalUrK49dLac06G1ZGsZBtoBAbNJYQ5ayZC.woff2',
			'designer' => 'Production Type',
		),
		'young-serif'                => array(
			'name'     => 'Young Serif',
			'category' => 'serif',
			'weight'   => '400',
			'axes'     => 'static 400',
			'bytes'    => 18520,
			'sha256'   => 'a46a84bfaa83dea2fa2d034dbf9b8f119a0872d78d28aa2cfe06ced9c1d2ef9e',
			'src'      => 'https://fonts.gstatic.com/s/youngserif/v2/3qTpojO2nS2VtkB3KtkQZ1t93kY6ah7E.woff2',
			'designer' => 'Bastien Sozeau',
		),
		'hanken-grotesk'             => array(
			'name'     => 'Hanken Grotesk',
			'category' => 'sans-serif',
			'weight'   => '100 900',
			'axes'     => 'wght 100-900',
			'bytes'    => 34664,
			'sha256'   => '1f21c6eaa0000f3329cfcfac966b43d5bebf5aa610303e33294ac31bc6f4bb59',
			'src'      => 'https://fonts.gstatic.com/s/hankengrotesk/v12/ieVn2YZDLWuGJpnzaiwFXS9tYtpd59CxCis4.woff2',
			'designer' => 'Alfredo Marco Pradil, Hanken Design Co.',
		),
		'familjen-grotesk'           => array(
			'name'     => 'Familjen Grotesk',
			'category' => 'sans-serif',
			'weight'   => '400 700',
			'axes'     => 'wght 400-700',
			'bytes'    => 18884,
			'sha256'   => '5c2a822104a1c4d658b6f070043548efbe19243aff2aab567494877056527f17',
			'src'      => 'https://fonts.gstatic.com/s/familjengrotesk/v11/Qw3GZR9ZHiDnImG6-NEMQ41wby8WbHoEj7E49PA.woff2',
			'designer' => 'Familjen STHLM AB',
		),
		'recursive'                  => array(
			'name'     => 'Recursive',
			'category' => 'monospace',
			'weight'   => '300 1000',
			'axes'     => 'wght 300-1000 (casual, cursive, mono and slant axes left out)',
			'bytes'    => 55848,
			'sha256'   => 'b79700b06c097ecdb36ef71d0296ac2825ad7461d6761d3accc12a87c961c07e',
			'src'      => 'https://fonts.gstatic.com/s/recursive/v44/8vI-7wMr0mhh-RQChyHEH06TlXhq_gukbYrFMk1QuAIcyEwG_X-dpEfaE5YaERmK-CImKsvxvU-MXGX2fSqasNfUlTGZnI14ZeY.woff2',
			'designer' => 'Arrow Type, Stephen Nixon',
		),
		'jetbrains-mono'             => array(
			'name'     => 'JetBrains Mono',
			'category' => 'monospace',
			'weight'   => '100 800',
			'axes'     => 'wght 100-800',
			'bytes'    => 40480,
			'sha256'   => '1e06740a02a443fb7f3eeda8fcaa685a0f6c620e3f01e6666e847295469ce3ad',
			'src'      => 'https://fonts.gstatic.com/s/jetbrainsmono/v24/tDbV2o-flEEny0FZhsfKu5WU4xD7OwGtT0rU.woff2',
			'designer' => 'Philipp Nurullin, Konstantin Bulenkov',
		),
		'outfit'                     => array(
			'name'     => 'Outfit',
			'category' => 'sans-serif',
			'weight'   => '100 900',
			'axes'     => 'wght 100-900',
			'bytes'    => 32228,
			'sha256'   => '92684e4acde79ef07758cd09380b7e01e9824d8b061eddeda046f78c166d7b12',
			'src'      => 'https://fonts.gstatic.com/s/outfit/v15/QGYvz_MVcBeNP4NJtEtqUYLknw.woff2',
			'designer' => 'Rodrigo Fuenzalida',
		),
		'roundo'                     => array(
			'name'     => 'Roundo',
			'category' => 'sans-serif',
			'weight'   => '200 700',
			'axes'     => 'wght 200-700',
			'bytes'    => 19588,
			'sha256'   => '234d5ca647f6f8f648af85e31c6ce51ceafc553d3e32daafa2faae13ec099085',
			'src'      => '',
			'designer' => 'Shiva Nallaperumal',
		),
		'space-grotesk'              => array(
			'name'     => 'Space Grotesk',
			'category' => 'sans-serif',
			'weight'   => '300 700',
			'axes'     => 'wght 300-700',
			'bytes'    => 22320,
			'sha256'   => 'a0d054c4af557de20afd6ca59f47ab353bcaec49c63ff04b6c9d39d0f8910557',
			'src'      => 'https://fonts.gstatic.com/s/spacegrotesk/v22/V8mDoQDjQSkFtoMM3T6r8E7mPbF4C_k3HqU.woff2',
			'designer' => 'Florian Karsten',
		),
		'terminal-grotesque'         => array(
			'name'     => 'Terminal Grotesque',
			'category' => 'display',
			'weight'   => '400',
			'axes'     => 'static 400 (complete file)',
			'bytes'    => 13124,
			'sha256'   => 'cfce72295356df96c692a2ad9c1e3f7794c862470ba23ae2573509d20b22abd4',
			'src'      => '',
			'designer' => 'Raphaël Bastide',
		),
		'cirrus-cumulus'             => array(
			'name'     => 'CirrusCumulus',
			'category' => 'display',
			'weight'   => '400',
			'axes'     => 'static 400 (complete file)',
			'bytes'    => 16516,
			'sha256'   => '98027e385f291c5a2dc1e0de0115fbf0e60fb30dfe788fe311fa5d88ce5bc9ea',
			'src'      => '',
			'designer' => 'Clara Sambot',
		),
		'lineal'                     => array(
			'name'     => 'Lineal',
			'category' => 'display',
			'weight'   => '1 1000',
			'axes'     => 'wght 0-1500 (official Velvetyne file; CSS reaches up to 1000)',
			'bytes'    => 25860,
			'sha256'   => '53e3dcd4f08b3363466a23016c10868d5613cdf5df16c48bbfafc945990872eb',
			'src'      => '',
			'designer' => 'Frank Adebiaye',
		),
		'gloock'                     => array(
			'name'     => 'Gloock',
			'category' => 'serif',
			'weight'   => '400',
			'axes'     => 'static 400',
			'bytes'    => 17576,
			'sha256'   => 'b862aac1a16f7dafbf1d2f0115a78f3d216c8a8a83ecd154eb4afb54ade82245',
			'src'      => 'https://fonts.gstatic.com/s/gloock/v8/Iurb6YFw84WUY4NJiBakJLRBjA.woff2',
			'designer' => 'Duarte Pinto',
		),
		'handjet'                    => array(
			'name'     => 'Handjet',
			'category' => 'display',
			'weight'   => '100 900',
			'axes'     => 'wght 100-900, ELSH 0-16, ELGR 1-2',
			'bytes'    => 18600,
			'sha256'   => '01d4832d760bdfb045449cdf15bc7401ed82e3e41487033a5483fc4a7d8af3ee',
			'src'      => 'https://fonts.gstatic.com/s/handjet/v22/oY1e8eXHq7n1OnbQhuoXD_53Y4U.woff2',
			'designer' => 'David Březina',
		),
		'satoshi'                    => array(
			'name'     => 'Satoshi',
			'category' => 'sans-serif',
			'weight'   => '300 900',
			'axes'     => 'wght 300-900',
			'bytes'    => 42588,
			'sha256'   => 'e739aff9b4d02c264341d6d4872edcda28e79373aeda936f659566a1cd3eb47f',
			'src'      => 'https://cdn.fontshare.com/wf/NWBQYJIM7GCZ5XWD7D26ARB3VDY55ZRT/K63EV2KZIGKLE7RANQ2U42S6SVHU5RJ7/X6XYTKIVDUW7GZTZPZNN4EUM5KH54KHF.woff2',
			'designer' => 'Deni Anggara',
			'licence'  => 'itf-ffl',
		),
		'cabinet-grotesk'            => array(
			'name'     => 'Cabinet Grotesk',
			'category' => 'sans-serif',
			'weight'   => '100 900',
			'axes'     => 'wght 100-900',
			'bytes'    => 41920,
			'sha256'   => '8d5d1213c3913508dcf8b2c76c8c0a91a559529736fab33ad89a5c7030a1d8d9',
			'src'      => 'https://cdn.fontshare.com/wf/J2PZYZURCR7HNQKXCZ4VXYA3K5FFCCLT/PZSZLWHMBCE7FFGOUYDEU33FAESUMA3X/JFDL5FBAQ2WMYL3LGKSCZKAIFCS2UQ63.woff2',
			'designer' => 'Indian Type Foundry',
			'licence'  => 'itf-ffl',
		),
		'clash-display'              => array(
			'name'     => 'Clash Display',
			'category' => 'display',
			'weight'   => '200 700',
			'axes'     => 'wght 200-700',
			'bytes'    => 29432,
			'sha256'   => 'e0ec5644c93b04de82f06a076beabfd0e3688ff89655affecf17c92e2747a45d',
			'src'      => 'https://cdn.fontshare.com/wf/DK2FOA46SRWJ5HXWWU5TK4N4CMHYD236/FPEAXZZSH5L2K5MTJFRIWD2MC32IJMN3/THOOS4VOCKT7H2XEB27NQDYM2NYS4AAR.woff2',
			'designer' => 'Indian Type Foundry',
			'licence'  => 'itf-ffl',
		),
		'general-sans'               => array(
			'name'     => 'General Sans',
			'category' => 'sans-serif',
			'weight'   => '200 700',
			'axes'     => 'wght 200-700',
			'bytes'    => 38132,
			'sha256'   => '49d3fbd2f1bcc9850d8d939cabf107d6ade508ce08419fca466b06879e4a0a8e',
			'src'      => 'https://cdn.fontshare.com/wf/LHQJ5KSAL7VGAEIDSTEXCCOIUKFLT2I6/GW57XUEG4ZBVMLZZTQZTGYPROITRRQ5W/JA3IZUEMJ2J6WWT2OQVJOAWDXO3YL4YG.woff2',
			'designer' => 'Frode Helland',
			'licence'  => 'itf-ffl',
		),
		'melodrama'                  => array(
			'name'     => 'Melodrama',
			'category' => 'display',
			'weight'   => '300 700',
			'axes'     => 'wght 300-700',
			'bytes'    => 40564,
			'sha256'   => 'a9e5a59a94204ac073bffd2a984550aa60b04a4d6ef2f1ea125b74a8d61feabb',
			'src'      => 'https://cdn.fontshare.com/wf/LC7IMMWVM27SA7ALSOK4NI36ZAQ55VBY/FNO72OGA42HOJR66E5WCXLYW2ONKD6RY/YDIY5IEHM22WUOHX7EQUCQJQULHZUNOV.woff2',
			'designer' => 'Shaily Patel',
			'licence'  => 'itf-ffl',
		),
		'pilcrow-rounded'            => array(
			'name'     => 'Pilcrow Rounded',
			'category' => 'sans-serif',
			'weight'   => '400 900',
			'axes'     => 'wght 400-900',
			'bytes'    => 38032,
			'sha256'   => '062e86b1882ea204499b64cb51265cd517a1803757d72d00248d86da8dfdae48',
			'src'      => 'https://cdn.fontshare.com/wf/6BPKZQONSPZRUGAYFK3OXMIMXAA6ECYA/46XRW624YURY7J4KLTCHCI2RRW425Z5X/7CQ4CLZVQJ65VIHM7WRCJLHHONFNVPQE.woff2',
			'designer' => 'Satya Rajpurohit',
			'licence'  => 'itf-ffl',
		),
		'stardom'                    => array(
			'name'     => 'Stardom',
			'category' => 'display',
			'weight'   => '400',
			'axes'     => 'static 400',
			'bytes'    => 20204,
			'sha256'   => 'bc9aa154b4367c2c0720dbe7af5c6a1b756a5a05e9cd2e82a4c7698017f3eef2',
			'src'      => 'https://cdn.fontshare.com/wf/55LJGATMQKPK2BTD7N2IXUHALPWNZDNS/GHNSWJKY6KQGQZNHIGVGKP3PVRORBVBW/NPPNOUBFWCNOP7IJUAHBBDRSDI2ZAE7F.woff2',
			'designer' => 'Indian Type Foundry',
			'licence'  => 'itf-ffl',
		),
	);

	/**
	 * Duos titre + texte, deux fichiers au plus. « system » = pile système, 0 octet.
	 *
	 * Leur usage conseillé est dans pair_note(), au même rang.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	public const PAIRS = array(
		array( 'instrument-serif', 'instrument-sans' ),
		array( 'young-serif', 'hanken-grotesk' ),
		array( 'bricolage-grotesque', 'atkinson-hyperlegible-next' ),
		array( 'anybody', 'familjen-grotesk' ),
		array( 'familjen-grotesk', 'literata' ),
		array( 'newsreader', 'hanken-grotesk' ),
		array( 'bricolage-grotesque', 'system' ),
		array( 'recursive', 'system' ),
		array( 'clash-display', 'general-sans' ),
		array( 'gloock', 'outfit' ),
		array( 'terminal-grotesque', 'space-grotesk' ),
		array( 'stardom', 'satoshi' ),
	);

	/**
	 * Articles créés par l'appel en cours de install_pair() : la famille quand elle est nouvelle,
	 * sinon la face ajoutée. Retirés si le duo échoue.
	 *
	 * @var list<int>
	 */
	private static array $created = array();

	/**
	 * Cause du dernier échec de download() ou install() : download, integrity ou write.
	 *
	 * @var string
	 */
	private static string $failure = 'download';

	/** {@inheritDoc} */
	public static function id(): string {
		return 'fonts';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Free fonts', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Adds a curated collection of free fonts to the Font Library. A font is copied to your site once, when you install it, then served from your own domain.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array();
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
		return array();
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_collection' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( self::class, 'add_page' ) );
			add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_pair' ) );
		}
	}

	/** Déclare la collection auprès de la Bibliothèque de polices. */
	public static function register_collection(): void {
		if ( ! function_exists( 'wp_register_font_collection' ) || \WP_Font_Library::get_instance()->get_font_collection( self::COLLECTION ) instanceof \WP_Font_Collection ) {
			return;
		}
		wp_register_font_collection(
			self::COLLECTION,
			array(
				'name'          => __( 'Fluffless free fonts', 'fluffless-suite' ),
				'description'   => __( 'Free fonts (SIL Open Font License or ITF Free Font License from Fontshare), one file per family. Installing copies the file to your site.', 'fluffless-suite' ),
				'font_families' => self::font_families(),
				'categories'    => array(
					array(
						'name' => __( 'Sans serif', 'fluffless-suite' ),
						'slug' => 'sans-serif',
					),
					array(
						'name' => __( 'Serif', 'fluffless-suite' ),
						'slug' => 'serif',
					),
					array(
						'name' => __( 'Display', 'fluffless-suite' ),
						'slug' => 'display',
					),
					array(
						'name' => __( 'Monospace', 'fluffless-suite' ),
						'slug' => 'monospace',
					),
				),
			)
		);
	}

	/**
	 * Familles au format des collections de polices de WordPress.
	 *
	 * @return list<array{font_family_settings: array<string, mixed>, categories: list<string>}>
	 */
	public static function font_families(): array {
		$list = array();
		foreach ( self::FAMILIES as $slug => $family ) {
			$src = self::source( $slug );
			if ( '' === $src ) {
				continue;
			}
			$face = array(
				'fontFamily'   => $family['name'],
				'fontStyle'    => 'normal',
				'fontWeight'   => $family['weight'],
				'fontDisplay'  => 'swap',
				'src'          => $src,
				'unicodeRange' => self::LATIN,
			);
			if ( isset( $family['stretch'] ) ) {
				$face['fontStretch'] = $family['stretch'];
			}
			$list[] = array(
				'font_family_settings' => array(
					'name'       => $family['name'],
					'slug'       => $slug,
					'fontFamily' => '"' . $family['name'] . '", ' . self::fallback( $family['category'] ),
					'fontFace'   => array( $face ),
				),
				'categories'           => array( $family['category'] ),
			);
		}
		return $list;
	}

	/**
	 * Pile de secours affichée pendant le chargement (font-display: swap).
	 *
	 * @param string $category Catégorie de la famille.
	 */
	private static function fallback( string $category ): string {
		return match ( $category ) {
			'serif'     => 'Georgia, serif',
			'monospace' => 'ui-monospace, monospace',
			default     => 'system-ui, sans-serif',
		};
	}

	/**
	 * Collection curée, pour l'écran et les tests.
	 *
	 * @return array<string, Family>
	 */
	public static function catalogue(): array {
		return self::FAMILIES;
	}

	/**
	 * Poids d'un duo en octets (0 pour la pile système).
	 *
	 * @param string $heading Famille des titres.
	 * @param string $body    Famille du texte, ou « system ».
	 */
	public static function pair_bytes( string $heading, string $body ): int {
		$bytes = 0;
		foreach ( array_unique( array( $heading, $body ) ) as $slug ) {
			$bytes += self::FAMILIES[ $slug ]['bytes'] ?? 0;
		}
		return $bytes;
	}

	/**
	 * Pourquoi cette famille est dans la collection.
	 *
	 * @param string $slug Famille.
	 */
	private static function why( string $slug ): string {
		return match ( $slug ) {
			'bricolage-grotesque'        => __( 'Expressive grotesque, punchy in large headings.', 'fluffless-suite' ),
			'instrument-sans'            => __( 'Clean, slightly narrow sans for body text and interfaces.', 'fluffless-suite' ),
			'instrument-serif'           => __( 'Condensed display serif, elegant in headings only.', 'fluffless-suite' ),
			'anybody'                    => __( 'Width axis from very narrow to very wide: made for brutalist headings.', 'fluffless-suite' ),
			'atkinson-hyperlegible-next' => __( 'Designed for low vision readers: distinct letter shapes.', 'fluffless-suite' ),
			'literata'                   => __( 'Book serif made for long reading on screens.', 'fluffless-suite' ),
			'newsreader'                 => __( 'Editorial serif, strong in headings and comfortable in text.', 'fluffless-suite' ),
			'young-serif'                => __( 'Warm, heavy old-style serif for friendly headings.', 'fluffless-suite' ),
			'hanken-grotesk'             => __( 'Neutral, open sans for body text, with a wide weight range.', 'fluffless-suite' ),
			'familjen-grotesk'           => __( 'Compact grotesque with character, very light file.', 'fluffless-suite' ),
			'recursive'                  => __( 'Technical sans with a code feel, for tech or playful brands.', 'fluffless-suite' ),
			'jetbrains-mono'             => __( 'Code font made for reading: tall lowercase, clear shapes.', 'fluffless-suite' ),
			'outfit'                     => __( 'Round, friendly geometric sans, light file.', 'fluffless-suite' ),
			'roundo'                     => __( 'Soft rounded sans for welcoming brands.', 'fluffless-suite' ),
			'space-grotesk'              => __( 'Quirky grotesque with a tech edge.', 'fluffless-suite' ),
			'terminal-grotesque'         => __( 'Pixel grotesque, very light file, for headings that stand out.', 'fluffless-suite' ),
			'cirrus-cumulus'             => __( 'Airy, cloud-like display font for short headings.', 'fluffless-suite' ),
			'lineal'                     => __( 'Monoline sans from Velvetyne, with a very wide weight range.', 'fluffless-suite' ),
			'gloock'                     => __( 'High-contrast serif, dramatic in headings.', 'fluffless-suite' ),
			'handjet'                    => __( 'Dot-matrix display font with adjustable elements.', 'fluffless-suite' ),
			'satoshi'                    => __( 'Modernist sans, clean in text and headings.', 'fluffless-suite' ),
			'cabinet-grotesk'            => __( 'Grotesque with character and a wide weight range, for strong headings.', 'fluffless-suite' ),
			'clash-display'              => __( 'Tight display sans for large, punchy headings.', 'fluffless-suite' ),
			'general-sans'               => __( 'Neutral, versatile sans for body text and interfaces.', 'fluffless-suite' ),
			'melodrama'                  => __( 'Theatrical display font for expressive headings.', 'fluffless-suite' ),
			'pilcrow-rounded'            => __( 'Warm rounded sans for friendly brands.', 'fluffless-suite' ),
			'stardom'                    => __( 'Sharp display serif, one weight, very light file.', 'fluffless-suite' ),
			default                     => '',
		};
	}

	/**
	 * Usage conseillé d'un duo, au rang de PAIRS.
	 *
	 * @param int $index Rang du duo.
	 */
	private static function pair_note( int $index ): string {
		return match ( $index ) {
			0       => __( 'Elegant and light: same foundry, built to work together.', 'fluffless-suite' ),
			1       => __( 'Warm and welcoming: community groups, food, crafts.', 'fluffless-suite' ),
			2       => __( 'Bold headings, highly legible text: public services, health.', 'fluffless-suite' ),
			3       => __( 'Brutalist: wide headings, compact text.', 'fluffless-suite' ),
			4       => __( 'Editorial: modern headings, long-reading serif.', 'fluffless-suite' ),
			5       => __( 'Magazine: serif headings, neutral text.', 'fluffless-suite' ),
			6       => __( 'Best punch for the weight: one free font, system text.', 'fluffless-suite' ),
			7       => __( 'Technical: code-like headings, system text.', 'fluffless-suite' ),
			8       => __( 'Confident and contemporary: same foundry, for studios and startups.', 'fluffless-suite' ),
			9       => __( 'Dramatic headings, friendly text: portfolios, cultural venues.', 'fluffless-suite' ),
			10      => __( 'Retro tech: pixel headings, quirky sans text.', 'fluffless-suite' ),
			11      => __( 'Sharp and refined: fashion, beauty, arts.', 'fluffless-suite' ),
			default => '',
		};
	}

	/**
	 * Polices installées sur le site : fichiers réellement présents dans le dossier des polices.
	 *
	 * @return array{families: array<string, array{files: int, bytes: int}>, files: int, bytes: int}
	 */
	public static function installed(): array {
		$dir    = trailingslashit( wp_get_font_dir()['path'] );
		$faces  = get_posts(
			array(
				'post_type'     => 'wp_font_face',
				'post_status'   => 'publish',
				'numberposts'   => self::MAX_FACES,
				'no_found_rows' => true,
			)
		);
		$result = array(
			'families' => array(),
			'files'    => 0,
			'bytes'    => 0,
		);
		foreach ( $faces as $face ) {
			$family = $face->post_parent > 0 ? get_the_title( $face->post_parent ) : '';
			$family = '' !== $family ? $family : __( '(unknown family)', 'fluffless-suite' );
			foreach ( get_post_meta( $face->ID, '_wp_font_face_file', false ) as $file ) {
				$path = $dir . wp_basename( (string) $file );
				if ( ! is_readable( $path ) ) {
					continue;
				}
				$bytes                                  = (int) filesize( $path );
				$result['families'][ $family ]['files'] = ( $result['families'][ $family ]['files'] ?? 0 ) + 1;
				$result['families'][ $family ]['bytes'] = ( $result['families'][ $family ]['bytes'] ?? 0 ) + $bytes;
				$result['files']                       += 1;
				$result['bytes']                       += $bytes;
			}
		}
		return $result;
	}

	/* ---------- Duos appliqués en un clic ---------- */

	/**
	 * Adresse de la banque de polices Fluffless (services/font-bank, hébergée sur canner.ca),
	 * sans barre finale. La constante FLUFFLESS_FONT_BANK ou le filtre fluffless_font_bank
	 * la remplacent ; vide : les fichiers viennent de leur hôte d'origine.
	 */
	public static function bank(): string {
		$bank = defined( 'FLUFFLESS_FONT_BANK' ) ? (string) constant( 'FLUFFLESS_FONT_BANK' ) : self::BANK;
		$bank = rtrim( (string) apply_filters( 'fluffless_font_bank', $bank ), '/' );
		return str_starts_with( $bank, 'https://' ) ? $bank : '';
	}

	/**
	 * Adresse du WOFF2 d'une famille : la banque Fluffless si elle est réglée, sinon l'hôte
	 * d'origine. Une famille Fontshare vient toujours de Fontshare. Le filtre fluffless_font_src
	 * permet encore de viser un autre fichier.
	 *
	 * @param string $slug Famille de la collection.
	 * @return string Vide si l'adresse n'est pas un WOFF2 en HTTPS.
	 */
	public static function source( string $slug ): string {
		$family = self::FAMILIES[ $slug ] ?? null;
		$bank   = null !== $family && ! isset( $family['licence'] ) ? self::bank() : '';
		$src    = '' !== $bank ? $bank . '/fonts/' . $slug . '.woff2' : ( $family['src'] ?? '' );
		$src    = (string) apply_filters( 'fluffless_font_src', $src, $slug );
		$path   = (string) wp_parse_url( $src, PHP_URL_PATH );
		return str_starts_with( $src, 'https://' ) && str_ends_with( $path, '.woff2' ) ? $src : '';
	}

	/**
	 * Installe une famille sur le site comme le fait la Bibliothèque de polices : fichier dans
	 * wp-content/fonts/, articles wp_font_family et wp_font_face. Déjà installée : réutilisée.
	 *
	 * @param string $slug Famille de la collection.
	 * @return array<string, mixed>|null Réglages de la famille pour les styles globaux, null en cas d'échec.
	 *
	 * @phpstan-impure Remplit self::$created.
	 */
	public static function install( string $slug ): ?array {
		$settings = null;
		foreach ( self::font_families() as $family ) {
			if ( $slug === $family['font_family_settings']['slug'] ) {
				$settings = $family['font_family_settings'];
			}
		}
		if ( null === $settings ) {
			return null;
		}
		$face  = (array) $settings['fontFace'][0];
		$found = self::installed_family( $slug );
		if ( '' !== $found['src'] ) {
			$face['src']          = $found['src'];
			$settings['fontFace'] = array( $face );
			return $settings;
		}
		$file = self::download( $slug );
		if ( null === $file ) {
			return null;
		}
		$face['src'] = $file['url'];
		$family_id   = $found['family'] > 0 ? $found['family'] : wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'wp_font_family',
					'post_status'  => 'publish',
					'post_title'   => (string) $settings['name'],
					'post_name'    => $slug,
					'post_content' => (string) wp_json_encode( array( 'fontFamily' => $settings['fontFamily'] ) ),
				)
			),
			true
		);
		$title       = \WP_Font_Utils::get_font_face_slug( $face );
		$face_id     = is_wp_error( $family_id ) ? $family_id : wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'wp_font_face',
					'post_status'  => 'publish',
					'post_parent'  => $family_id,
					'post_title'   => $title,
					'post_name'    => sanitize_title( $title ),
					'post_content' => (string) wp_json_encode( $face ),
				)
			),
			true
		);
		if ( is_wp_error( $face_id ) ) {
			wp_delete_file( $file['file'] );
			if ( 0 === $found['family'] && ! is_wp_error( $family_id ) ) {
				wp_delete_post( $family_id, true );
			}
			// Fichier bien écrit : c'est l'enregistrement qui a échoué, pas le dossier.
			return self::fail( 'save', $slug, $face_id->get_error_message() );
		}
		add_post_meta( $face_id, '_wp_font_face_file', wp_basename( $file['file'] ) );
		// Le second insert n'a pas échoué (retour plus haut) : la famille, créée ou trouvée, est un identifiant.
		assert( ! is_wp_error( $family_id ) );
		self::$created[]      = 0 === $found['family'] ? $family_id : $face_id;
		$settings['fontFace'] = array( $face );
		return $settings;
	}

	/**
	 * Famille déjà installée : son article et l'adresse d'un fichier réellement présent.
	 *
	 * @param string $slug Famille.
	 * @return array{family: int, src: string} family 0 si absente ; src vide sans fichier lisible.
	 */
	private static function installed_family( string $slug ): array {
		$families = get_posts(
			array(
				'post_type'     => 'wp_font_family',
				'name'          => $slug,
				'numberposts'   => 1,
				'no_found_rows' => true,
			)
		);
		if ( array() === $families ) {
			return array(
				'family' => 0,
				'src'    => '',
			);
		}
		$id    = (int) $families[0]->ID;
		$dir   = wp_get_font_dir();
		$faces = get_posts(
			array(
				'post_type'     => 'wp_font_face',
				'post_parent'   => $id,
				'numberposts'   => self::MAX_FACES,
				'no_found_rows' => true,
			)
		);
		foreach ( $faces as $face ) {
			foreach ( get_post_meta( $face->ID, '_wp_font_face_file', false ) as $file ) {
				$name = wp_basename( (string) $file );
				if ( is_readable( trailingslashit( $dir['path'] ) . $name ) ) {
					return array(
						'family' => $id,
						'src'    => trailingslashit( $dir['url'] ) . $name,
					);
				}
			}
		}
		return array(
			'family' => $id,
			'src'    => '',
		);
	}

	/**
	 * Le fichier téléchargé est-il celui de la banque ? Compare son SHA-256 à l'empreinte de la
	 * famille, que le filtre fluffless_font_sha256 peut remplacer. Sans empreinte valide (filtre
	 * qui renvoie une chaîne vide, famille ajoutée sans elle), le fichier est refusé.
	 *
	 * @param string $slug Famille.
	 * @param string $file Fichier temporaire local.
	 */
	private static function expected( string $slug, string $file ): bool {
		$sha    = strtolower( (string) apply_filters( 'fluffless_font_sha256', self::FAMILIES[ $slug ]['sha256'] ?? '', $slug ) );
		$actual = hash_file( 'sha256', $file );
		return 1 === preg_match( '/^[0-9a-f]{64}$/', $sha ) && is_string( $actual ) && hash_equals( $sha, $actual );
	}

	/**
	 * Note la cause d'un échec et la transmet au journal, pour le soutien.
	 *
	 * @param string $cause  download (passager), integrity (fichier refusé ou disparu), write (dossier) ou save (base).
	 * @param string $slug   Famille.
	 * @param string $detail Détail technique, jamais montré à l'écran.
	 * @return null Toujours, pour finir l'appel qui échoue.
	 */
	private static function fail( string $cause, string $slug, string $detail ) {
		self::$failure = $cause;
		/**
		 * Échec d'installation d'une police libre, avec sa cause.
		 *
		 * @param string $cause  download, integrity, write ou save.
		 * @param string $slug   Famille.
		 * @param string $detail Détail technique.
		 */
		do_action( 'fluffless_fonts_failure', $cause, $slug, $detail );
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'Fluffless Suite, fonts: ' . $cause . ': ' . $slug . ': ' . $detail ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return null;
	}

	/**
	 * Message d'un échec de duo, selon sa cause : réessayer seulement quand la panne est passagère.
	 *
	 * @param string $cause download, integrity, write ou save.
	 */
	public static function failure_message( string $cause ): string {
		switch ( $cause ) {
			case 'integrity':
				return __( 'Not applied: the font file of this pair has changed at its distributor, so your site is unchanged. Choose another pair until the next Fluffless Suite update.', 'fluffless-suite' );
			case 'write':
				return __( 'Not applied: the fonts folder does not accept files, so your site is unchanged. Ask your host to allow writing in wp-content/fonts.', 'fluffless-suite' );
			case 'save':
				return __( 'Not applied: the font could not be saved in the Font Library, so your site is unchanged. Try again in a few minutes.', 'fluffless-suite' );
			default:
				return __( 'Not applied: a font file could not be downloaded, so your site is unchanged. Try again in a few minutes.', 'fluffless-suite' );
		}
	}

	/**
	 * Télécharge le WOFF2 d'une famille, le vérifie, puis le range dans le dossier des polices.
	 *
	 * @param string $slug Famille.
	 * @return array{file: string, url: string}|null
	 */
	private static function download( string $slug ): ?array {
		$src = self::source( $slug );
		if ( '' === $src ) {
			return self::fail( 'download', $slug, 'adresse du fichier vide ou refusée' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $src, 30 );
		if ( is_wp_error( $tmp ) ) {
			// Adresse disparue (404, 410) : réessayer n'y changera rien. Le reste est passager.
			$data = $tmp->get_error_data();
			$code = is_array( $data ) ? (int) ( $data['code'] ?? 0 ) : 0;
			return self::fail( in_array( $code, array( 404, 410 ), true ) ? 'integrity' : 'download', $slug, $src . ' : ' . $tmp->get_error_message() );
		}
		$size = (int) filesize( $tmp );
		$head = (string) file_get_contents( $tmp, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier temporaire local, 4 octets.
		if ( $size < 1 || $size > self::MAX_FILE_BYTES || 'wOF2' !== $head || ! self::expected( $slug, $tmp ) ) {
			wp_delete_file( $tmp );
			return self::fail( 'integrity', $slug, $src . ' : ' . $size . ' o, signature ou empreinte refusée' );
		}
		$mimes = \WP_Font_Utils::get_allowed_font_mime_types();
		add_filter( 'upload_mimes', array( \WP_Font_Utils::class, 'get_allowed_font_mime_types' ) );
		add_filter( 'upload_dir', '_wp_filter_font_directory' );
		$upload = array(
			'name'     => $slug . '.woff2',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => $size,
		);
		$file   = wp_handle_sideload(
			$upload,
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);
		remove_filter( 'upload_dir', '_wp_filter_font_directory' );
		remove_filter( 'upload_mimes', array( \WP_Font_Utils::class, 'get_allowed_font_mime_types' ) );
		if ( ! isset( $file['file'], $file['url'] ) ) {
			wp_delete_file( $tmp );
			return self::fail( 'write', $slug, (string) $file['error'] );
		}
		return array(
			'file' => (string) $file['file'],
			'url'  => (string) $file['url'],
		);
	}

	/**
	 * Applique un duo : installe ses familles, puis les pose sur les titres et le texte.
	 *
	 * @param int $index Rang du duo dans PAIRS.
	 * @return string 'applied' ; site inchangé : 'download' (panne passagère), 'integrity' (fichier
	 *                refusé ou disparu), 'write' (dossier des polices fermé), 'save' (base refusée) ; ou 'error'.
	 */
	public static function apply_pair( int $index ): string {
		$pair   = self::PAIRS[ $index ] ?? null;
		$styles = GlobalStyles::user();
		if ( null === $pair || null === $styles ) {
			return 'error';
		}
		$families = self::install_pair( $pair );
		if ( null === $families ) {
			return self::$failure;
		}
		$data   = $styles['data'];
		$stored = self::remember( $data );
		$backup = $stored['backup'];
		$data   = GlobalStyles::put( $data, self::PATH_CUSTOM, self::active_families( $data, $families ) );
		$data   = GlobalStyles::put( $data, self::PATH_HEADING, self::preset( $pair[0] ) );
		$data   = GlobalStyles::put( $data, self::PATH_BODY, 'system' === $pair[1] ? ( $backup['body'] ?? null ) : self::preset( $pair[1] ) );
		if ( ! GlobalStyles::save( $styles['id'], $data ) ) {
			return 'error';
		}
		$stored['pair'] = $index;
		update_option( self::OPTION, $stored, false );
		return 'applied';
	}

	/**
	 * Installe les familles d'un duo (la pile système n'a rien à installer).
	 *
	 * @param array{0: string, 1: string} $pair Titres, texte.
	 * @return list<array<string, mixed>>|null Réglages des familles ; null si un fichier manque.
	 */
	private static function install_pair( array $pair ): ?array {
		$families      = array();
		self::$created = array();
		self::$failure = 'download';
		foreach ( array_unique( $pair ) as $slug ) {
			if ( 'system' === $slug ) {
				continue;
			}
			$settings = self::install( $slug );
			if ( null === $settings ) {
				// Duo incomplet : retirer ce que l'appel a installé, pour que le site reste tel quel.
				foreach ( self::$created as $id ) {
					wp_delete_post( $id, true );
				}
				self::$created = array();
				return null;
			}
			$families[] = $settings;
		}
		return $families;
	}

	/**
	 * Option du module avec la copie des polices d'avant le premier duo (prise au besoin).
	 *
	 * @param array<string, mixed> $data Styles globaux enregistrés.
	 * @return array<string, mixed> Option, 'backup' toujours présent (tableau).
	 */
	private static function remember( array $data ): array {
		$stored = self::stored();
		if ( ! is_array( $stored['backup'] ?? null ) ) {
			$stored['backup'] = array(
				'body'    => GlobalStyles::get( $data, self::PATH_BODY ),
				'heading' => GlobalStyles::get( $data, self::PATH_HEADING ),
			);
		}
		return $stored;
	}

	/** Revient aux polices d'avant le premier duo ; les fichiers restent dans la Bibliothèque de polices. */
	public static function reset(): bool {
		$styles = GlobalStyles::user();
		if ( null === $styles ) {
			return false;
		}
		$stored = self::stored();
		$backup = is_array( $stored['backup'] ?? null ) ? $stored['backup'] : null;
		$data   = GlobalStyles::put( $styles['data'], self::PATH_CUSTOM, self::active_families( $styles['data'], array() ) );
		foreach ( array(
			'heading' => self::PATH_HEADING,
			'body'    => self::PATH_BODY,
		) as $key => $path ) {
			// Sans copie d'origine, seule une police de la collection est retirée.
			$value = GlobalStyles::get( $data, $path );
			$data  = GlobalStyles::put( $data, $path, null !== $backup ? ( $backup[ $key ] ?? null ) : ( self::from_collection( $value ) ? null : $value ) );
		}
		if ( $data !== $styles['data'] && ! GlobalStyles::save( $styles['id'], $data ) ) {
			return false;
		}
		delete_option( self::OPTION );
		return true;
	}

	/** Rang du duo en place, ou null (polices du thème, ou titres changés depuis dans l'éditeur). */
	public static function current_pair(): ?int {
		$index  = self::stored()['pair'] ?? null;
		$styles = GlobalStyles::user();
		if ( ! is_int( $index ) || ! isset( self::PAIRS[ $index ] ) || null === $styles ) {
			return null;
		}
		return self::preset( self::PAIRS[ $index ][0] ) === GlobalStyles::get( $styles['data'], self::PATH_HEADING ) ? $index : null;
	}

	/**
	 * Familles personnalisées actives : celles d'autres sources restent ; celles de la collection
	 * hors du duo sont désactivées (leurs fichiers restent dans la Bibliothèque de polices).
	 *
	 * @param array<string, mixed>       $data     Styles globaux.
	 * @param list<array<string, mixed>> $families Familles du duo.
	 * @return list<mixed>|null Null quand la liste est vide.
	 */
	private static function active_families( array $data, array $families ): ?array {
		$list   = array();
		$custom = GlobalStyles::get( $data, self::PATH_CUSTOM );
		foreach ( is_array( $custom ) ? $custom : array() as $family ) {
			$slug = is_array( $family ) && is_string( $family['slug'] ?? null ) ? $family['slug'] : '';
			if ( ! isset( self::FAMILIES[ $slug ] ) ) {
				$list[] = $family;
			}
		}
		foreach ( $families as $family ) {
			$list[] = $family;
		}
		return array() === $list ? null : $list;
	}

	/**
	 * Valeur theme.json qui pointe vers une famille.
	 *
	 * @param string $slug Famille.
	 */
	private static function preset( string $slug ): string {
		return 'var:preset|font-family|' . $slug;
	}

	/**
	 * Vrai si la valeur pointe vers une famille de la collection.
	 *
	 * @param mixed $value Valeur lue dans les styles globaux.
	 */
	private static function from_collection( $value ): bool {
		return is_string( $value ) && str_starts_with( $value, 'var:preset|font-family|' ) && isset( self::FAMILIES[ substr( $value, strlen( 'var:preset|font-family|' ) ) ] );
	}

	/**
	 * Option du module.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) ? $stored : array();
	}

	/** Formulaire : appliquer un duo ou revenir aux polices du thème. */
	public static function handle_pair(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change the fonts of this site.', 'fluffless-suite' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		if ( isset( $_POST['fl_reset'] ) ) {
			$done = self::reset() ? 'reset' : 'error';
		} else {
			$done = isset( $_POST['fl_pair'] ) ? self::apply_pair( absint( wp_unslash( $_POST['fl_pair'] ) ) ) : 'error';
		}
		wp_safe_redirect( add_query_arg( 'fl_fonts', $done, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Petit formulaire d'un bouton, nonce compris.
	 *
	 * @param string $field  Nom du champ envoyé.
	 * @param string $value  Valeur du champ.
	 * @param string $label  Texte du bouton.
	 * @param string $nonce  Nonce de ACTION.
	 */
	private static function button_form( string $field, string $value, string $label, string $nonce ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '"><button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
	}

	/** Route du panneau de l'éditeur de site : installer les familles d'un duo. */
	public static function register_routes(): void {
		register_rest_route(
			Pexels::REST_NS,
			'/fonts/pair',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_pair' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_theme_options' ),
				'args'                => array(
					'pair' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
						'maximum'  => count( self::PAIRS ) - 1,
					),
				),
			)
		);
	}

	/**
	 * Installe les familles d'un duo sans toucher aux styles globaux : le panneau les écrit dans
	 * les styles en cours d'édition, et le bouton Enregistrer de l'éditeur les garde.
	 *
	 * @param \WP_REST_Request $request Requête.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_pair( \WP_REST_Request $request ) {
		$index  = (int) $request->get_param( 'pair' );
		$pair   = self::PAIRS[ $index ] ?? null;
		$styles = GlobalStyles::user();
		if ( null === $pair || null === $styles ) {
			return new \WP_Error( 'fluffless_fonts_error', __( 'The pair could not be applied and your site is unchanged. Check that the active theme is a block theme, then try again.', 'fluffless-suite' ), array( 'status' => 400 ) );
		}
		$families = self::install_pair( $pair );
		if ( null === $families ) {
			return new \WP_Error( 'fluffless_fonts_' . self::$failure, self::failure_message( self::$failure ), array( 'status' => in_array( self::$failure, array( 'write', 'save' ), true ) ? 500 : 502 ) );
		}
		$stored         = self::remember( $styles['data'] );
		$stored['pair'] = $index;
		update_option( self::OPTION, $stored, false );
		return rest_ensure_response(
			array(
				'families' => $families,
				'backup'   => $stored['backup'],
			)
		);
	}

	/** Panneau Polices de l'éditeur de site. */
	public static function editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'site-editor' !== $screen->id || ! current_user_can( 'edit_theme_options' ) || ! wp_is_block_theme() ) {
			return;
		}
		wp_enqueue_script( 'fluffless-fonts-editor', plugins_url( 'assets/fonts-editor.js', FILE ), array( 'wp-api-fetch', 'wp-components', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-plugins' ), VERSION, true );
		wp_add_inline_script( 'fluffless-fonts-editor', 'window.flufflessFonts=' . wp_json_encode( self::editor_data() ) . ';', 'before' );
		wp_register_style( 'fluffless-fonts-editor', false, array(), VERSION );
		wp_enqueue_style( 'fluffless-fonts-editor' );
		wp_add_inline_style( 'fluffless-fonts-editor', '.fl-fonts-panel__pairs{margin:1rem 0;padding:0;list-style:none}.fl-fonts-panel__pairs li{margin-bottom:1rem}.fl-fonts-panel__pairs p{margin:4px 0}.fl-fonts-panel__pairs img{display:block;width:100%;height:auto;margin:4px 0}' );
	}

	/**
	 * Données et textes du panneau de l'éditeur de site.
	 *
	 * @return array<string, mixed>
	 */
	public static function editor_data(): array {
		$pairs = array();
		foreach ( self::PAIRS as $index => $pair ) {
			$pairs[] = array(
				'heading' => $pair[0],
				'body'    => $pair[1],
				'names'   => array( self::FAMILIES[ $pair[0] ]['name'], 'system' === $pair[1] ? __( 'System fonts (0 KB)', 'fluffless-suite' ) : self::FAMILIES[ $pair[1] ]['name'] ),
				'weight'  => self::kb( self::pair_bytes( $pair[0], $pair[1] ) ),
				'note'    => self::pair_note( $index ),
				'title'   => self::specimen( $pair[0], 'title' ),
				'text'    => 'system' === $pair[1] ? '' : self::specimen( $pair[1], 'text' ),
			);
		}
		$backup = self::stored()['backup'] ?? null;
		return array(
			'pairs'      => $pairs,
			'collection' => array_keys( self::FAMILIES ),
			'backup'     => is_array( $backup ) ? $backup : null,
			'screen'     => admin_url( 'admin.php?page=' . self::PAGE ),
			'i18n'       => array(
				'title'   => __( 'Free fonts', 'fluffless-suite' ),
				'intro'   => __( '“Apply this pair” copies the font files to your site once, then applies them to headings and text. You can go back to the theme fonts at any time.', 'fluffless-suite' ),
				/* translators: 1: heading font, 2: text font. */
				'pair'    => __( '%1$s + %2$s', 'fluffless-suite' ),
				'apply'   => __( 'Apply this pair', 'fluffless-suite' ),
				'busy'    => __( 'Installing the fonts…', 'fluffless-suite' ),
				'current' => __( 'In use', 'fluffless-suite' ),
				'reset'   => __( 'Back to the theme fonts', 'fluffless-suite' ),
				'show'    => __( 'See a paragraph', 'fluffless-suite' ),
				'hide'    => __( 'Hide the paragraph', 'fluffless-suite' ),
				/* translators: %s: font name. */
				'sample'  => __( 'Sample paragraph set in %s', 'fluffless-suite' ),
				'save'    => __( 'The editor shows the new fonts right away. Save the site to keep them.', 'fluffless-suite' ),
				'screen'  => __( 'Font budget and collection', 'fluffless-suite' ),
			),
		);
	}

	/**
	 * Adresse d'un aperçu produit par tools/font-specimens.py ; chaîne vide sans fichier.
	 *
	 * @param string $slug Famille.
	 * @param string $kind 'title' (SVG, ou WebP si le tracé est trop lourd) ou 'text' (WebP).
	 */
	private static function specimen( string $slug, string $kind ): string {
		foreach ( 'title' === $kind ? array( 'svg', 'webp' ) : array( 'webp' ) as $ext ) {
			$file = 'assets/specimens/' . $slug . '-' . $kind . '.' . $ext;
			if ( is_readable( plugin_dir_path( FILE ) . $file ) ) {
				return plugins_url( $file, FILE );
			}
		}
		return '';
	}

	/**
	 * Titre d'essai d'une famille (image décorative : le nom est écrit à côté).
	 *
	 * @param string $slug Famille.
	 */
	private static function specimen_title( string $slug ): string {
		$url = self::specimen( $slug, 'title' );
		return '' === $url ? '' : '<img src="' . esc_url( $url ) . '" alt="" loading="lazy" decoding="async" style="display:block;width:100%;max-width:20rem;height:auto;margin-top:4px">';
	}

	/**
	 * Paragraphe d'essai, chargé seulement quand on ouvre « Voir un paragraphe ». Une fois déplié,
	 * il s'annonce aux lecteurs d'écran avec le nom de sa police.
	 *
	 * @param string $slug Famille.
	 */
	private static function specimen_text( string $slug ): string {
		$url = self::specimen( $slug, 'text' );
		/* translators: %s: font name. */
		$alt = sprintf( __( 'Sample paragraph set in %s', 'fluffless-suite' ), self::FAMILIES[ $slug ]['name'] ?? $slug );
		return '' === $url ? '' : '<details><summary>' . esc_html__( 'See a paragraph', 'fluffless-suite' ) . '</summary><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async" style="display:block;width:100%;max-width:35rem;height:auto;margin-top:4px"></details>';
	}

	/** Ajoute Fluffless → Polices. */
	public static function add_page(): void {
		Menu::add_tab( 'appearance', self::PAGE, static fn(): string => __( 'Fonts', 'fluffless-suite' ), array( self::class, 'render' ), 20 );
	}

	/**
	 * Poids lisible en ko décimaux (1 ko = 1000 octets, comme les budgets).
	 *
	 * @param int $bytes Octets.
	 */
	private static function kb( int $bytes ): string {
		/* translators: %s: size in kilobytes, e.g. 41.2 */
		return sprintf( __( '%s KB', 'fluffless-suite' ), html_entity_decode( number_format_i18n( $bytes / 1000, 1 ), ENT_QUOTES, 'UTF-8' ) );
	}

	/** Écran : polices installées et budget, collection, duos. */
	public static function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		$installed = self::installed();
		$over      = $installed['bytes'] > self::BUDGET_BYTES || $installed['files'] > self::BUDGET_FILES;
		$library   = admin_url( 'site-editor.php?p=%2Fstyles&section=%2Ftypography' );

		echo '<div class="wrap">';
		Screen::header( __( 'Free fonts', 'fluffless-suite' ), __( 'Your site uses system fonts by default: 0 KB to download. To add a free font, open the Font Library, choose the “Fluffless free fonts” collection, then click Install. The file is copied to your site once; visitors never contact Google.', 'fluffless-suite' ) );
		echo '<p><a class="button button-primary" href="' . esc_url( $library ) . '">' . esc_html__( 'Open the Font Library', 'fluffless-suite' ) . '</a></p>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage du résultat.
		$done  = isset( $_GET['fl_fonts'] ) ? sanitize_key( wp_unslash( $_GET['fl_fonts'] ) ) : '';
		$notes = array(
			'applied'   => array( 'good', __( 'Applied. Headings and text now use this pair, and the font files are on your site.', 'fluffless-suite' ) ),
			'reset'     => array( 'good', __( 'Back to the theme fonts. The installed files stay in the Font Library: remove the ones you no longer use.', 'fluffless-suite' ) ),
			'download'  => array( 'bad', self::failure_message( 'download' ) ),
			'integrity' => array( 'bad', self::failure_message( 'integrity' ) ),
			'write'     => array( 'bad', self::failure_message( 'write' ) ),
			'save'      => array( 'bad', self::failure_message( 'save' ) ),
			'error'     => array( 'bad', __( 'The pair could not be applied and your site is unchanged. Check that the active theme is a block theme, then try again.', 'fluffless-suite' ) ),
		);
		if ( isset( $notes[ $done ] ) ) {
			echo Screen::note( $notes[ $done ][0], $notes[ $done ][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		$current = self::current_pair();
		$can     = current_user_can( 'edit_theme_options' );
		$nonce   = wp_create_nonce( self::ACTION );

		echo '<h2>' . esc_html__( 'Installed on your site', 'fluffless-suite' ) . '</h2>';
		if ( 0 === $installed['files'] ) {
			echo '<p>' . esc_html__( 'No font file installed: your pages use system fonts.', 'fluffless-suite' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Family', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Files', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Weight', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
			foreach ( $installed['families'] as $name => $row ) {
				echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( number_format_i18n( $row['files'] ) ) . '</td><td>' . esc_html( self::kb( $row['bytes'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		printf(
			'<div class="notice %1$s inline"><p>%2$s</p></div>',
			$over ? 'notice-warning' : 'notice-success',
			esc_html(
				sprintf(
					/* translators: 1: installed weight, e.g. 75.3 KB; 2: number of files; 3: budget weight, e.g. 100.0 KB; 4: budget file count */
					__( 'Font budget: %1$s in %2$s files, for a budget of %3$s in %4$s files.', 'fluffless-suite' ),
					self::kb( $installed['bytes'] ),
					number_format_i18n( $installed['files'] ),
					self::kb( self::BUDGET_BYTES ),
					number_format_i18n( self::BUDGET_FILES )
				) . ' ' . ( $over ? __( 'Over budget: remove a font you do not use in the Font Library.', 'fluffless-suite' ) : __( 'Within budget.', 'fluffless-suite' ) )
			)
		);

		echo '<h2>' . esc_html__( 'Suggested pairs', 'fluffless-suite' ) . '</h2>';
		echo '<p>' . esc_html__( '“Apply this pair” copies the font files to your site once, then applies them to headings and text. You can go back to the theme fonts at any time.', 'fluffless-suite' ) . '</p>';
		echo '<div class="fl-table-scroll"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Headings', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Text', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Weight', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'When to use it', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Action', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
		foreach ( self::PAIRS as $index => $pair ) {
			$body = 'system' === $pair[1] ? esc_html__( 'System fonts (0 KB)', 'fluffless-suite' ) : esc_html( self::FAMILIES[ $pair[1] ]['name'] ) . self::specimen_text( $pair[1] );
			if ( $current === $index ) {
				$action = Screen::pill( 'good', __( 'In use', 'fluffless-suite' ) );
			} else {
				$action = $can ? self::button_form( 'fl_pair', (string) $index, __( 'Apply this pair', 'fluffless-suite' ), $nonce ) : '';
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $action, $body et les aperçus échappés à la source.
			echo '<tr><td>' . esc_html( self::FAMILIES[ $pair[0] ]['name'] ) . self::specimen_title( $pair[0] ) . '</td><td>' . $body . '</td><td>' . esc_html( self::kb( self::pair_bytes( $pair[0], $pair[1] ) ) ) . '</td><td>' . esc_html( self::pair_note( $index ) ) . '</td><td>' . $action . '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( $can && ( null !== $current || array_key_exists( 'backup', self::stored() ) ) ) {
			echo '<p>' . self::button_form( 'fl_reset', '1', __( 'Back to the theme fonts', 'fluffless-suite' ), $nonce ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans button_form().
		}

		echo '<h2>' . esc_html__( 'The collection', 'fluffless-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Fonts under the SIL Open Font License 1.1 come from the Fluffless font bank. Fontshare fonts are under the ITF Free Font License, which allows commercial use; their file comes straight from Fontshare. All cover French, except the rare capital Ÿ and the narrow no-break space, which the browser takes from the fallback font.', 'fluffless-suite' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Family', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Weight', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Axes', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Designer', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'License', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Why', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
		foreach ( self::FAMILIES as $slug => $family ) {
			$fontshare = isset( $family['licence'] );
			$licence   = '<a href="' . esc_url( $fontshare ? self::FONTSHARE_LICENCE : 'https://openfontlicense.org/' ) . '">' . esc_html( $fontshare ? 'ITF Free Font License' : 'SIL OFL 1.1' ) . '</a>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $licence échappé juste au-dessus.
			echo '<tr><td>' . esc_html( $family['name'] ) . self::specimen_title( $slug ) . '</td><td>' . esc_html( self::kb( $family['bytes'] ) ) . '</td><td>' . esc_html( $family['axes'] ) . '</td><td>' . esc_html( $family['designer'] ) . '</td><td>' . $licence . '</td><td>' . esc_html( self::why( $slug ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><a href="' . esc_url( 'https://openfontlicense.org/' ) . '">' . esc_html__( 'Read the SIL Open Font License', 'fluffless-suite' ) . '</a></p></div>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function hosts(): array {
		$hosts = array( '' !== self::bank() ? (string) wp_parse_url( self::bank(), PHP_URL_HOST ) : 'fonts.gstatic.com' );
		foreach ( self::FAMILIES as $family ) {
			if ( isset( $family['licence'] ) ) {
				$hosts[] = (string) wp_parse_url( $family['src'], PHP_URL_HOST );
			}
		}
		$entries = array();
		foreach ( array_unique( $hosts ) as $host ) {
			$entries[] = array(
				'host'    => $host,
				'service' => __( 'Free fonts', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'action',
				'data'    => __( 'A file request, once per font installed', 'fluffless-suite' ),
			);
		}
		return $entries;
	}
}
