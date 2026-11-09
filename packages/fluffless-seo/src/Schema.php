<?php
/**
 * Données structurées schema.org, en un seul graphe JSON-LD (@graph) par page.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

use Fluffless\Suite\Modules\Seo as LightSeo;

defined( 'ABSPATH' ) || exit;

/**
 * Nœuds reliés par @id : WebSite, l'entité du site (Organization, LocalBusiness ou Person),
 * WebPage, puis selon le contenu Article/BlogPosting, Service, Event, Product, SoftwareApplication,
 * Person, BreadcrumbList, FAQPage (questions tirées des blocs Détails par Fluffless Suite) et Product
 * (blocs Produit de la Vente simple, lus par Fluffless Suite).
 * Seuls les champs remplis sont écrits : un nœud vide ne sert à personne et pèse.
 */
final class Schema {

	/** Disponibilités de produit acceptées => adresse schema.org. */
	private const AVAILABILITY = array(
		'InStock'             => 'https://schema.org/InStock',
		'OutOfStock'          => 'https://schema.org/OutOfStock',
		'PreOrder'            => 'https://schema.org/PreOrder',
		'SoldOut'             => 'https://schema.org/SoldOut',
		'OnlineOnly'          => 'https://schema.org/OnlineOnly',
		'LimitedAvailability' => 'https://schema.org/LimitedAvailability',
	);

	/** Bloc script JSON-LD, ou '' si le graphe ne peut pas être encodé. */
	public static function json_ld(): string {
		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => self::graph(),
			),
			// JSON_HEX_TAG : aucun « </script> » possible dans le bloc, quel que soit le titre.
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
		);
		return false === $json ? '' : wp_get_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
	}

	/**
	 * Nœuds du graphe de la requête courante, passés au filtre fluffless_seo_graph.
	 *
	 * Le filtre reçoit la liste des nœuds et le contenu affiché (WP_Post ou null) ; il peut
	 * ajouter, retirer ou modifier des nœuds. Seuls les nœuds qui sont des tableaux sont gardés ;
	 * une réponse qui n'est pas un tableau laisse le graphe d'origine.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function graph(): array {
		$graph    = self::build_graph();
		$filtered = apply_filters( 'fluffless_seo_graph', $graph, Context::post() );
		if ( ! is_array( $filtered ) ) {
			return $graph;
		}
		return array_values( array_filter( $filtered, static fn( $node ): bool => is_array( $node ) && array() !== $node ) );
	}

	/**
	 * Nœuds calculés, avant filtre.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function build_graph(): array {
		$home      = home_url( '/' );
		$entity    = self::entity( $home );
		$entity_id = array( '@id' => $entity['@id'] );
		$graph     = array(
			array_filter(
				array(
					'@type'      => 'WebSite',
					'@id'        => $home . '#website',
					'name'       => Context::plain( get_bloginfo( 'name' ) ),
					'url'        => $home,
					'inLanguage' => get_bloginfo( 'language' ),
					'publisher'  => $entity_id,
				)
			),
			$entity,
		);
		$url       = Context::canonical();
		if ( '' === $url || is_404() ) {
			return $graph;
		}
		$post        = Context::post();
		$choice      = null !== $post ? Meta::text( $post->ID, Meta::SCHEMA ) : '';
		$page_id     = $url . '#webpage';
		$breadcrumb  = Settings::on( 'breadcrumbs' ) && ! is_front_page() ? Breadcrumbs::schema( $url ) : null;
		$webpage     = array(
			'@type'      => self::page_type( $choice ),
			'@id'        => $page_id,
			'url'        => $url,
			'name'       => '' !== Context::title() ? Context::title() : Context::plain( wp_get_document_title() ),
			'isPartOf'   => array( '@id' => $home . '#website' ),
			'inLanguage' => Context::language(),
		);
		$description = Context::description();
		if ( '' !== $description ) {
			$webpage['description'] = $description;
		}
		if ( null !== $breadcrumb ) {
			$webpage['breadcrumb'] = array( '@id' => $breadcrumb['@id'] );
		}
		if ( null !== $post && is_singular() ) {
			$webpage['datePublished'] = (string) get_the_date( 'c', $post );
			$webpage['dateModified']  = (string) get_the_modified_date( 'c', $post );
		}
		$graph[] = $webpage;
		if ( null !== $post && is_singular() ) {
			foreach ( self::main_nodes( $post, $choice, $url, $page_id, $entity_id ) as $node ) {
				$graph[] = $node;
			}
			$faq = self::faq( $post, $url );
			if ( null !== $faq ) {
				$graph[] = $faq;
			}
			foreach ( LightSeo::products( $post, $url ) as $product ) {
				$graph[] = $product;
			}
		}
		if ( null !== $breadcrumb ) {
			$graph[] = $breadcrumb;
		}
		return $graph;
	}

	/**
	 * Entité qui publie le site.
	 *
	 * @param string $home Adresse de l'accueil.
	 * @return array<string, mixed>
	 */
	public static function entity( string $home ): array {
		$kind = Settings::string( 'entity' );
		$name = Settings::string( 'entity_name' );
		$logo = Context::logo();
		$node = array(
			'@type' => 'Person' === $kind ? 'Person' : ( 'LocalBusiness' === $kind ? Settings::string( 'business_type' ) : 'Organization' ),
			'@id'   => $home . '#identity',
			'name'  => '' !== $name ? $name : Context::plain( get_bloginfo( 'name' ) ),
			'url'   => $home,
		);
		if ( '' !== $logo ) {
			$node[ 'Person' === $kind ? 'image' : 'logo' ] = $logo;
		}
		$same_as = Settings::list( 'same_as' );
		if ( array() !== $same_as ) {
			$node['sameAs'] = $same_as;
		}
		if ( 'LocalBusiness' !== $kind ) {
			return $node;
		}
		if ( '' !== $logo ) {
			$node['image'] = $logo;
		}
		$address = array_filter(
			array(
				'streetAddress'   => Settings::string( 'street' ),
				'addressLocality' => Settings::string( 'city' ),
				'addressRegion'   => Settings::string( 'region' ),
				'postalCode'      => Settings::string( 'postal' ),
				'addressCountry'  => Settings::string( 'country' ),
			)
		);
		if ( array() !== $address ) {
			$node['address'] = array( '@type' => 'PostalAddress' ) + $address;
		}
		return $node + array_filter(
			array(
				'telephone'    => Settings::string( 'phone' ),
				'priceRange'   => Settings::string( 'price_range' ),
				'openingHours' => Settings::list( 'hours' ),
				'areaServed'   => Settings::list( 'area_served' ),
			)
		);
	}

	/**
	 * Type de la page elle-même.
	 *
	 * @param string $choice Type choisi pour le contenu.
	 */
	private static function page_type( string $choice ): string {
		if ( in_array( $choice, array( 'AboutPage', 'ContactPage' ), true ) ) {
			return $choice;
		}
		if ( is_search() ) {
			return 'SearchResultsPage';
		}
		return is_singular() || is_front_page() ? 'WebPage' : 'CollectionPage';
	}

	/**
	 * Nœud principal du contenu, selon le type choisi ou automatique (Article pour les articles).
	 *
	 * @param \WP_Post             $post      Contenu.
	 * @param string               $choice    Type choisi ('' : automatique).
	 * @param string               $url       Adresse canonique.
	 * @param string               $page_id   @id de la WebPage.
	 * @param array{'@id': string} $entity_id Référence de l'entité du site.
	 * @return list<array<string, mixed>>
	 */
	private static function main_nodes( \WP_Post $post, string $choice, string $url, string $page_id, array $entity_id ): array {
		$type = '' === $choice ? ( 'post' === $post->post_type ? 'Article' : 'none' ) : $choice;
		if ( in_array( $type, array( 'none', 'WebPage', 'AboutPage', 'ContactPage' ), true ) ) {
			return array();
		}
		$data  = Meta::schema_data( $post->ID );
		$title = Context::plain( get_the_title( $post ) );
		$image = Context::image();
		$base  = array_filter(
			array(
				'@type'            => $type,
				'@id'              => $url . '#main',
				'name'             => $title,
				'description'      => Context::description(),
				'image'            => $image,
				'url'              => $url,
				'mainEntityOfPage' => array( '@id' => $page_id ),
			)
		);
		return match ( $type ) {
			'Article', 'BlogPosting', 'NewsArticle' => self::article( $post, $base, $entity_id ),
			'Service' => array(
				$base + array_filter(
					array(
						'provider'    => $entity_id,
						'serviceType' => $data['service_type'] ?? '',
						'areaServed'  => isset( $data['area'] ) ? $data['area'] : Settings::list( 'area_served' ),
						'offers'      => self::offer( $data, $url ),
					)
				),
			),
			'Event'   => isset( $data['start'] ) ? array( $base + self::event( $data, $url, $entity_id ) ) : array(),
			'Product' => array(
				$base + array_filter(
					array(
						'sku'    => $data['sku'] ?? '',
						'brand'  => isset( $data['brand'] ) ? array(
							'@type' => 'Brand',
							'name'  => $data['brand'],
						) : array(),
						'offers' => self::offer( $data, $url ),
					)
				),
			),
			'SoftwareApplication' => array(
				$base + array_filter(
					array(
						'applicationCategory' => $data['app_category'] ?? '',
						'operatingSystem'     => $data['os'] ?? '',
						'publisher'           => $entity_id,
						'offers'              => self::offer( $data, $url ),
					)
				),
			),
			'Person'  => array( $base + array_filter( array( 'jobTitle' => $data['job_title'] ?? '' ) ) ),
			default   => array(),
		};
	}

	/**
	 * Article, avec son auteur en nœud Person.
	 *
	 * @param \WP_Post             $post      Contenu.
	 * @param array<string, mixed> $base      Champs communs.
	 * @param array{'@id': string} $entity_id Éditeur.
	 * @return list<array<string, mixed>>
	 */
	private static function article( \WP_Post $post, array $base, array $entity_id ): array {
		$author_id = (int) $post->post_author;
		$person_id = home_url( '/' ) . '#author-' . $author_id;
		unset( $base['name'], $base['url'] );
		$article = $base + array(
			'headline'      => Context::plain( get_the_title( $post ) ),
			'datePublished' => (string) get_the_date( 'c', $post ),
			'dateModified'  => (string) get_the_modified_date( 'c', $post ),
			'author'        => array( '@id' => $person_id ),
			'publisher'     => $entity_id,
			'inLanguage'    => Context::language(),
		);
		$person  = array(
			'@type' => 'Person',
			'@id'   => $person_id,
			'name'  => Context::plain( (string) get_the_author_meta( 'display_name', $author_id ) ),
			'url'   => get_author_posts_url( $author_id ),
		);
		return array( $article, $person );
	}

	/**
	 * Événement : date, lieu physique ou en ligne, organisateur, billet.
	 *
	 * @param array<string, string> $data      Détails du contenu.
	 * @param string                $url       Adresse de la page.
	 * @param array{'@id': string}  $entity_id Organisateur.
	 * @return array<string, mixed>
	 */
	private static function event( array $data, string $url, array $entity_id ): array {
		$online   = in_array( $data['online'] ?? '', array( '1', 'yes', 'true' ), true );
		$location = $online
			? array(
				'@type' => 'VirtualLocation',
				'url'   => $url,
			)
			: array_filter(
				array(
					'@type'   => 'Place',
					'name'    => $data['place'] ?? '',
					'address' => $data['address'] ?? '',
				)
			);
		return array_filter(
			array(
				'startDate'           => $data['start'] ?? '',
				'endDate'             => $data['end'] ?? '',
				'eventAttendanceMode' => 'https://schema.org/' . ( $online ? 'OnlineEventAttendanceMode' : 'OfflineEventAttendanceMode' ),
				'eventStatus'         => 'https://schema.org/EventScheduled',
				'location'            => $location,
				'organizer'           => $entity_id,
				'offers'              => self::offer( $data, $url ),
			)
		);
	}

	/**
	 * Offre (prix, devise, disponibilité) ; vide sans prix.
	 *
	 * @param array<string, string> $data Détails du contenu.
	 * @param string                $url  Adresse de la page.
	 * @return array<string, string>
	 */
	private static function offer( array $data, string $url ): array {
		if ( ! isset( $data['price'] ) ) {
			return array();
		}
		// Prix gardé hors de array_filter : « 0 » (logiciel ou événement gratuit) est un vrai prix.
		return array(
			'@type' => 'Offer',
			'price' => $data['price'],
		) + array_filter(
			array(
				'priceCurrency' => strtoupper( $data['currency'] ?? 'CAD' ),
				'availability'  => self::AVAILABILITY[ $data['availability'] ?? '' ] ?? '',
				'url'           => $url,
			)
		);
	}

	/**
	 * FAQPage à partir des blocs Détails du contenu (lecture faite par Fluffless Suite).
	 *
	 * @param \WP_Post $post Contenu.
	 * @param string   $url  Adresse canonique.
	 * @return array<string, mixed>|null
	 */
	private static function faq( \WP_Post $post, string $url ): ?array {
		if ( ! Settings::on( 'faq_schema' ) ) {
			return null;
		}
		$pairs = LightSeo::faq_pairs( $post );
		if ( array() === $pairs ) {
			return null;
		}
		$node = array(
			'@type'      => 'FAQPage',
			'@id'        => $url . '#faq',
			'inLanguage' => Context::language(),
			'mainEntity' => array_map(
				static fn( array $pair ): array => array(
					'@type'          => 'Question',
					'name'           => $pair[0],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $pair[1],
					),
				),
				$pairs
			),
		);
		/**
		 * Nœud FAQPage de la page; rendre null pour ne rien émettre.
		 *
		 * @param array<string, mixed>|null $node Nœud.
		 * @param \WP_Post                  $post Contenu.
		 */
		$filtered = apply_filters( 'fluffless_seo_schema_faq', $node, $post );
		return is_array( $filtered ) ? $filtered : null;
	}
}
