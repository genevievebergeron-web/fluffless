=== Fluffless SEO ===
Contributors: genevievebergeron
Tags: seo, structured data, sitemap, redirects, breadcrumbs
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Requires Plugins: fluffless-suite
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server-side SEO for any theme: titles, social tags, structured data, breadcrumbs, redirects and sitemaps. No JavaScript for visitors.

== Description ==

Fluffless SEO writes all its output on the server. Visitors download no script. It is distributed from fluffless.dev and requires Fluffless Suite. When it is active, the light SEO module of Fluffless Suite steps aside.

Features:

* An SEO panel in the editor: title and description with a preview and an estimated width in pixels, canonical URL, robots directives, social sharing (Open Graph and X), structured data type and a local keyword analysis.
* Title and description templates for each post type, taxonomy, archive, search results and 404 page, with variables such as `%%title%%`, `%%sitename%%`, `%%sep%%`, `%%excerpt%%` and `%%term%%`.
* A JSON-LD graph: WebSite, Organization, LocalBusiness or Person, WebPage, Article, BreadcrumbList, FAQPage (from Details blocks), Service, Event and Product.
* A server-rendered breadcrumb block, `fluffless-seo/fil-ariane`.
* Adjustments to the native WordPress sitemap: exclusions, last modified dates and removal of noindex content.
* Redirects (301, 302, 307 and 410) with regular expressions, and CSV import.
* A log of addresses not found (path, count and last visit, with no IP address or user agent), purged after 90 days and capped at 5,000 addresses.
* Virtual robots.txt and llms.txt files, and verification tags.
* IndexNow notifications, off by default.
* hreflang and og:locale with Polylang.
* Import from Yoast SEO, SEOPress and Rank Math, with a dry run first. Original data is kept.
* WP-CLI commands: `wp fluffless-seo redirect list|add|delete|import`, `wp fluffless-seo 404 list|purge` and `wp fluffless-seo import`.

== Installation ==

1. Install and activate Fluffless Suite.
2. Download Fluffless SEO from your fluffless.dev account.
3. In your admin area, go to Plugins > Add New Plugin > Upload Plugin, choose the ZIP file, then click Install Now and Activate.
4. Go to Settings > Fluffless SEO.

Updates are delivered through Fluffless Suite in Dashboard > Updates.

== Frequently Asked Questions ==

= Can I use it with another SEO plugin? =

Use one SEO plugin at a time. To move from Yoast SEO, SEOPress or Rank Math, run the importer in simulation mode first, then apply it.

= Why doesn't a custom post type show the SEO panel? =

The panel saves its fields through the REST API. The post type must declare support for `custom-fields`.

= What is deleted when I delete the plugin? =

The `fluffless_seo_settings` option, the redirects and 404 log tables, and the `_fluffless_seo_*` post fields. The `_fluffless_description` field, shared with Fluffless Suite, is kept. To keep everything, turn on “Keep my data when the plugin is deleted” in Settings > Fluffless SEO, or add `define( 'FLUFFLESS_SEO_KEEP_DATA', true );` to wp-config.php before deleting. The Plugins screen shows how many redirect rules a deletion would erase. Deactivating the plugin deletes nothing.

= Can a theme change the sharing image or the FAQ data? =

Yes. The `fluffless_seo_image` filter receives the resolved sharing image address and the post ID (the posts page when the blog index is shown, 0 elsewhere) and returns an address, or an empty string to publish no `og:image` and `twitter:image`; the structured data reads the same value. The filter has the same name in the light SEO module of Fluffless Suite, so a theme hooks it once. The `fluffless_seo_schema_faq` filter receives the FAQPage node and the post, and returns the node to publish or `null` to drop it; the FAQ data can also be turned off for the whole site in the Structured data settings, with the same `faq_schema` name as in Fluffless Suite.

= What are the known limits? =

The sitemap contains no images, because the native WordPress sitemap accepts only `loc`, `lastmod`, `changefreq` and `priority`. Taxonomy terms have no individual SEO settings: the templates apply. There is no bulk editing.

== Privacy ==

The 404 log stores the requested path, a counter and the date of the last visit. It stores no IP address and no user agent, and entries older than 90 days are purged. It keeps at most 5,000 addresses; when it is full, the least visited ones make room for new ones (filter `fluffless_seo_404_max_rows`).

= External services =

This plugin connects to one external service, only if you turn it on.

**IndexNow** (off by default)
When a public post is published or updated, the plugin sends a POST request to `https://api.indexnow.org/indexnow` with your site's host name, your IndexNow key, the key file address and the address of the published post. Nothing is sent from a private site (search engine visibility turned off) or from a `local` or `development` environment. IndexNow shares the notification with participating search engines.
Terms and privacy: https://www.indexnow.org/terms

Updates and licence checks for this plugin go through Fluffless Suite. See the Fluffless Suite readme.

== Changelog ==

= 0.2.1 =
* og:image: when the site converts its images to WebP or AVIF, the original JPEG or PNG is shared instead, if it is still on disk (5 MB at most) and shows the same picture (same size, no EXIF rotation, no edit in the media editor). Some networks, LinkedIn among them, show WebP poorly.
* With Fluffless Suite, the sharing image is chosen by the Suite; with an older Suite, the plugin falls back to the large size, as before.
* French (Canada): the four strings that were missing are now translated (IndexNow on the Contacted servers screen, level 1 heading analysis).

= 0.2.0 =
* The 404 log keeps at most 5000 addresses (`fluffless_seo_404_max_rows` filter); the least visited ones give way.
* FLUFFLESS_SEO_KEEP_DATA set to false now really deletes the data on uninstall, and a “Keep my data when the plugin is deleted” setting is offered, with a notice before anything is deleted.
* A notice when Rank Math is active without a finished setup.
* On a network, each new site gets its tables and its purge as soon as it is created.
* IndexNow is listed on the Contacted servers screen of Fluffless Suite.
* `faq_schema` setting (FAQPage can be turned off), `fluffless_seo_schema_faq` and `fluffless_seo_image` filters.
* Heading analysis: when the template does not show the title as an H1, the content must hold exactly one.
* WP-CLI commands registered on `cli_init`; the breadcrumb block is now called “Breadcrumb with structured data” in the inserter.

= 0.1.0 =
* First release.
* `faq_schema` setting and `fluffless_seo_schema_faq` filter.
* `fluffless_seo_image` filter on the sharing image.
