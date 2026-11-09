=== Fluffless Consent ===
Contributors: genevievebergeron
Tags: cookie consent, consent, privacy, gdpr, cookie banner
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A light cookie consent banner, shown only when a non-essential service is declared. Nothing from the services it lists loads before a yes.

== Description ==

Fluffless Consent shows a consent banner only when your site declares at least one non-essential service, such as Google Analytics or an embedded video. With no service declared, the plugin adds nothing to your pages.

When a service is declared, the plugin adds the banner HTML, a few lines of inline CSS and one deferred script of 2 KB or less (compressed). Scripts from the declared services load only after the visitor accepts their category.

Features:

* Choices by category: audience measurement, advertising and embedded content. No box is checked in advance.
* "Refuse all" has the same prominence as "Accept all".
* The choice is kept in the visitor's browser for 1 to 13 months (6 by default). A new list of services or a new legal profile asks again.
* Three legal profiles: Quebec (Law 25, the default), the rest of Canada (PIPEDA) and the European Union (GDPR). Each profile sets the banner text and suggests a section for the WordPress privacy policy tool.
* On small screens, the banner takes at most 40% of the screen height, with 44 px touch targets and a visible focus. No animation.
* The Escape key closes the banner once a choice exists.
* Embedded content: third-party iframes and embeds from known services (YouTube, Vimeo, Google Maps, Spotify and others) are replaced on the server with a placeholder and a direct link. They load only after the visitor accepts embedded content. Use the `fluffless_consent_embed_hosts` filter to add hosts.
* A "Manage my cookies" link through the `[fluffless_consent_link]` shortcode or the `fluffless_consent_link()` function. When a page shows no such link, the plugin adds one at the bottom of the page so visitors can always withdraw consent.
* Works with any theme.

What it does not do:

* It keeps no proof of consent. The choice stays in the visitor's browser and nothing is logged on your server.
* It does not support Google Consent Mode v2. Google Analytics 4 loads only after a yes and sends nothing before.
* It does not control tags added elsewhere (theme, other plugin, pasted code).
* The profile texts are a starting point, not legal advice.

= Declaring a service =

Enter your Google Analytics 4 ID in Settings > Cookie consent. Declare any other service from a theme or plugin with the `fluffless_consent_services` filter:

`
add_filter( 'fluffless_consent_services', fn( $services ) => $services + array(
	'youtube' => array(
		'category' => 'media', // analytics, marketing or media
		'label'    => 'YouTube',
		'location' => 'United States',
		'src'      => 'https://example.com/script.js', // optional, HTTPS only
	),
) );
`

An inert script such as `<script type="text/plain" data-fluffless-consent="media">` is also activated after a yes, and the `fluffless:consent` DOM event announces the accepted categories. The plugin accepts up to 20 services and ignores invalid entries.

= Declaring a browser setting =

A display setting that the visitor chooses and that is kept in their browser (such as a dark mode choice) can be declared separately with the `fluffless_consent_storage` filter: it is listed in the suggested privacy policy text and does not show the banner. Use this filter only for settings written after the visitor's own choice, never for an identifier, measurement or tracking: those are services for `fluffless_consent_services`. Check that the exemption applies under your site's legal profile.

`
add_filter( 'fluffless_consent_storage', fn( $items ) => $items + array(
	'my-theme-text-size' => array( 'label' => 'Text size' ), // local storage key
) );
`

Keys are 1 to 64 characters (letters, digits, `_ . : -`, not digits only), a label is required, and up to 20 entries are accepted.

== Installation ==

1. In your admin area, go to Plugins > Add New Plugin and search for "Fluffless Consent".
2. Click Install Now, then Activate.
3. Go to Settings > Cookie consent to choose a legal profile, a retention period and, if you use it, your Google Analytics 4 ID.

== Frequently Asked Questions ==

= Why don't I see the banner? =

The banner appears only when at least one non-essential service is declared, either with a Google Analytics ID in the settings or through the `fluffless_consent_services` filter.

= Does the plugin set cookies? =

No. The visitor's choice is stored in the browser's local storage, not in a cookie. Google Analytics sets its own cookies once a visitor accepts audience measurement.

= What happens when a visitor withdraws consent? =

The plugin stores the new choice and deletes the Google Analytics cookies that the site can reach (those whose names start with `_ga`, `_gid` or `_gat`, including the per-property `_ga_*` cookie of Google Analytics 4). Cookies set by other services you declare are not deleted. The page then reloads without the withdrawn services.

= Does it work with the Fluffless theme? =

Yes, and with any other theme. With the separate Fluffless Suite plugin, the "Manage my cookies" link sits in the footer's legal line. Otherwise, the plugin adds it at the bottom of the page when a service is declared, or you can place it yourself with the `[fluffless_consent_link]` shortcode.

= Can I prove that a visitor consented? =

No, by design. The choice stays in the visitor's browser and nothing is logged on your server, which protects visitors' privacy. If your legal profile requires you to demonstrate consent (for example under the GDPR, article 7), this plugin does not provide that record, so plan another way to keep it.

= Does it support Google Consent Mode v2? =

No. Google Analytics loads only after a yes and sends nothing before. The plugin does not send the Consent Mode signals (`ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`), so features that depend on them, such as advertising modelling, are not available.

= What are the known limits? =

Only the services on the list are covered: an embed from an unknown host, or one added after the page has loaded (AJAX, modal window), loads without consent. Content rendered by admin-ajax.php or the REST API also loads without consent, because the filter is attached on the `wp` action. Scripts printed outside the content (wp_head, wp_footer from another plugin, wp_print_inline_script_tag, block themes), inline loaders such as a Meta Pixel snippet, `img`, `audio` and `srcset` from listed hosts, WooCommerce short descriptions and term descriptions in a classic context are not covered.

= Is this legal advice? =

No. The profiles give you a starting text. For Quebec, the privacy policy section reminds you to carry out a privacy impact assessment (PIA) when required. Check your obligations with a qualified professional.

== Screenshots ==

1. The banner on a phone: "Refuse all" has the same prominence as "Accept all", and no box is checked in advance.
2. An embedded video replaced by a placeholder until the visitor accepts embedded content.
3. Settings > Cookie consent: legal profile, retention period and Google Analytics 4 ID.

== External services ==

The plugin itself contacts no external service: it makes no request from your server and loads nothing in the visitor's browser before consent.

Google Analytics 4 (Google LLC), only when you enter a measurement ID in Settings > Cookie consent. After the visitor accepts audience measurement, their browser loads `https://www.googletagmanager.com/gtag/js` with your measurement ID, and Google receives the data that Google Analytics collects (pages visited, browser details, IP address). Nothing is loaded if the visitor refuses or has not chosen yet. Terms of service: https://marketingplatform.google.com/about/analytics/terms/us/ and privacy policy: https://policies.google.com/privacy

Embedded videos and other embeds (YouTube, Vimeo and the other listed hosts) are added by you in your content, not by the plugin. The plugin holds them back until the visitor accepts embedded content; the host then receives what its player collects, under its own terms.

== Privacy ==

What the plugin stores:

* In the visitor's browser: one local storage entry named `fluffless-consent`, holding the accepted categories, the date of the choice and a version number. No cookie is set by the plugin, and nothing about the visitor's choice is sent to your server.
* In your database: the section `consent` of the `fluffless_settings` option (legal profile, Google Analytics ID and retention period). Fluffless Suite shares this option.

What the plugin sends:

* The plugin makes no request from your server.
* In the visitor's browser, only the services the visitor accepted are loaded. If you enter a Google Analytics ID and the visitor accepts audience measurement, the browser loads `https://www.googletagmanager.com/gtag/js`. Google's handling of that data is covered by its own privacy policy: https://policies.google.com/privacy
* Services declared through the filter load their own scripts only after consent. You are responsible for describing them in your privacy policy.

When you delete the plugin, it removes the `consent` section of the `fluffless_settings` option and the `fluffless_consent_embeds` option (set once a published post holds an embed from a listed service, or once a first visit renders one outside a post). The whole option is removed only if nothing else is left in it. Nothing is removed when `FLUFFLESS_KEEP_DATA` is true in wp-config.php or when “Keep my data when the plugin is deleted” is turned on in the Forms module of Fluffless Suite. Deactivating the plugin deletes nothing. Once set, the embeds option stays until the plugin is deleted. Embeds from hosts that are not on the list are not announced. Purge your page cache after activating the plugin.

== Changelog ==

= 0.2.1 =
* French (Canada): the four strings that were missing are now translated, among them the title of the suggested privacy policy section on settings kept in the browser and the data a service receives after a yes, shown on the Contacted servers screen of Fluffless Suite.

= 0.2.0 =
* New “Videos, maps and other external content” category: it is added to the banner as soon as a published page holds a video, a map or a social post from a listed service, so a banner can now appear on a site that had none.
* Embedded content from the listed services is neutralized when the page is built: iframes and social scripts load only after a yes, and the loaded iframe takes the place of the placeholder and gets the focus; videos and objects become a link.
* When a Google Analytics service is declared and not accepted, its cookies are cleared on every page load.
* The banner does not grab the keyboard focus when the page loads; it takes the focus only when it opens.
* The “Manage my cookies” button stays hidden until the script can open the banner.
* Uninstalling keeps the settings when FLUFFLESS_KEEP_DATA is true or the Fluffless Suite checkbox “Keep my data when the plugin is deleted” is checked.
* The services it can load are listed on the Contacted servers screen of Fluffless Suite.
* The readme says what the plugin does not do: no consent log, no Google Consent Mode v2.

= 0.1.0 =
* First release.
