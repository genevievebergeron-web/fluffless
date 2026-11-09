=== Fluffless Suite ===
Contributors: genevievebergeron
Tags: blocks, patterns, performance, images, sustainability
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced patterns, block styles and digital sobriety tools for the Fluffless theme.

== Description ==

Fluffless Suite adds blocks, patterns, block styles and a set of modules that keep pages light. It is distributed from fluffless.dev. Each module can be turned on or off in Fluffless > Modules.

= What you get =

* Full pages for the usual needs (home, about, services, contact, pricing, team and more), with a short questionnaire that writes a first draft.
* Ready-made sections, block styles and server-rendered blocks that fit the Fluffless theme.
* Forms built from native blocks, with no third-party captcha or form service, and responses kept on your site only when you ask for it.
* Sobriety you can measure: page weight and emission estimates in the editor, and a check of your hosting on demand.
* Modules you turn on or off one at a time in Fluffless > Modules.

= Blocks =

49 server-rendered blocks, plus 7 inner blocks that only go inside another one (Bar in Progress bars, Form field in Form, Form step in Form, Menu panel in the core Navigation block, Price in Product, Timeline step in Timeline, Term in Glossary).

The HTML anchor set in the editor's Advanced panel becomes the `id` of the block's first element on the page, on every block of the suite, so a `#section` link or a table of contents finds it. A theme can move it to another element with the `fluffless_suite_anchor_target` filter, which receives `null` and the parsed block and returns a tag name in lower case.

The ids a block needs on the page (icon, scrolling words, pop-up, tabs, circled photo) are derived from its settings and saved content rather than from a counter, so the same block keeps the same id from one page to the next and a page cache or an HTML comparison sees no spurious change; two identical blocks on one page are told apart by a `-2`, `-3` suffix.

Structure and interaction:

* **Breadcrumb** (the path to the current page in a navigation landmark: Home, the parent pages, the page itself as text with `aria-current`; the posts page or the post type archive for an entry; the archive, search or 404 title otherwise; nothing on the front page)

    The Home item and the current item, recognised by their address, can be hidden. The separator is a CSS pseudo-element that screen readers skip. The `muted` colour and `small` font size of the palette are used when present. No script. The SEO module writes the matching BreadcrumbList JSON-LD, the block none. A theme adds or removes items with the `fluffless_suite_breadcrumb_trail` filter, which receives the list of `name`/`url` pairs and the current post.
* **Icon** (163 icons in 14 categories, inline SVG)
* **Layer**
* **Wave** (a shape, line or accent mark with adjustable transparency between two sections)
* **Scrolling story**
* **Pop-up**
* **Menu panel** (a mega menu inside the core Navigation block, native Details, no script of its own; the Fluffless theme closes it with the Escape key)
* **Floating element** (a pill button, turning seal or status dot fixed in a corner of the screen, readable text colour from your palette, no script)
* **Scrolling keywords** (a band of 2 to 12 words that scrolls in CSS only, with a Pause button, still under reduced motion, a plain list in print, no script)
* **Chart** (a pie, donut or bar chart of 2 to 8 rows drawn in CSS only, figures in a real table beside it, palette colours, no script and no image)
* **Tabs**
* **Carousel**
* **Table of contents**
* **Data table** (the core Table block with seven adjustments: density, lines, stripes, accent or sticky header row, bold first column, right-aligned numbers, and rows that scroll sideways or stack on small screens, no script)

Content sections:

* **Heading, text and columns**
* **Heading, text and list**
* **Text and photo**
* **About us**
* **Quote**
* **Social networks**
* **Call to action**
* **Testimonials**
* **Team**
* **Gallery**
* **Values**
* **Services**
* **Frequently asked questions**
* **Glossary** (terms and their definitions as a native description list, one or two columns read top to bottom; each Term block has its own anchor, so a link in the text can point to an entry; a term left empty is not rendered; put the heading before the block)
* **Key figures**
* **Partner logos**
* **Pricing table**
* **Timeline**
* **Progress bars**
* **Countdown**
* **Before and after**
* **Video** (YouTube, Vimeo or a file from the media library behind a poster, nothing loaded before the click)
* **Map**
* **Loop card** (shows each post of a Query Loop with the card chosen once per content type in Appearance, Loop cards, no script)

Commerce, contact, legal and utility:

* **Product and Price** (simple selling: one product or service with a button to a hosted payment link, no cart, no customer data, Product and Offer data for search engines)
* **Form**
* **Contact details**
* **Share buttons**
* **Newsletter link**
* **Resource download** (a file from your media library to download, with its format and size shown on the link)
* **Certifications**
* **Hosting badge**
* **Page footprint**
* **Legal bar**
* **Event calendar** (a month of events as a grid of days built on the server, links to the previous and next months, a list of the busy days only in a narrow column, no script; needs the Events module)

= Patterns and block styles =

78 patterns and 58 block styles.

Patterns are grouped in the inserter by what they do. Openers: **Activist poster**, **Editorial opening**, **Full-bleed image**, **Soft opening** and **Section with a wave**.

Showcase: **Carousel**, **Details in a pop-up**, **Editorial numbered list**, **Flip cards**, **Heading, text and columns**, **Heading, text and list**, **Map**, **Photo gallery**, **Services**, **Sliding gallery**, **Soft rounded section**, **Soft services**, **Strengths mosaic**, **Tabs**, **Team**, **Text and photo**, **Upcoming events**, **Values band**, **Values** and **Video**.

Action: **Announcement bar**, **Call to action banner**, **Contact details**, **Contact form**, **Countdown**, **Donate or sign up**, **Event registration**, **Job application**, **Newsletter sign-up**, **Pricing table**, **Quote request**, **Resource download**, **Share buttons** and **Soft call to action**.

Storytelling: **Blog as an editorial list**, **Blog: featured post**, **Editorial front page**, **Editorial magazine spread**, **About us**, **Scrolling story**, **Before and after** and **Timeline**, the last two each also in a plain, fixed version alongside their adjustable one.

Proof: **Editorial pull quote**, **Frequently asked questions**, **Key figures**, **Progress bars**, **Quote**, **Soft sideways cards**, **Testimonials** and **Partner logos**, also in a plain, fixed version.

Structure: **Columns footer**, **Compact footer**, **Data table**, **Editorial footer**, **Loop card: team member**, **Manifesto footer**, **Section divider with an ornament** and **Table of contents**.

Full pages: **Case study page**, **Contact page with booking**, **Donate page**, **Page not found**, **Pillar content page**, **Portfolio page**, **Pricing page**, **Restaurant menu page**, **Sales page (honest)**, **Search results** and **Team page**.

Two variations of the core Group, **Section** (a `section` landmark with constrained width) and **Section header** (overline, title, introduction), name the structure of a page without a new block: on the site they stay plain Groups, recognised in the editor by their `fluffless-conteneur` and `fluffless-en-tete` classes.

Several of the patterns above are already the adjustable version, exposing their design as block-level settings instead of fixed markup; Before and after, Timeline and Partner logos additionally ship a plain, fixed twin.

Block styles:

* **Banner**
* **Cut corner**
* **Slanted top edge**
* **Slanted bottom edge**
* **Numbered**
* **Anchor index** (a sticky row of links to the sections of the page)
* **Horizontal scroll**
* **Scrolling band** (with a Pause button that needs no script)
* **Timeline**
* **Soft shape**
* **Accent shape** (a disc, half-moon, ring, drop or dot grid in the accent colour behind the content, with placement, size and transparency)
* **Grain**
* **Reveal on scroll**
* **Flip card**
* **Fade in**
* **Slide in**
* **Grow in**
* **Lift on hover**
* **Zoom on hover**
* **Sticky, shrinks on scroll** (for the header group)

Typography: **Mono index** (an automatic number before the heading, or a slash or dot variant), **Eyebrow**, **Oversized**, **Outlined text**, **Gradient text** (from the accent to the second accent colour, on a diagonal or vertical), **Handwritten word** and **Highlighter** (both applied to the italic words of the heading), and **Vertical label** (turns the first paragraph of a group into a label in the left margin on wide screens).

Structure and cards:

* **Blueprint** (hard rules between columns and rows, with a glowing accent-colour variant)
* **Ruled list** (a rule between rows that keeps the list announced as a list)
* **Clipped disc** (an accent disc behind the image of a card)
* **Sticker** (tilted left or right)
* **Closing block** (a full-width band in the accent colour with a large link or button)
* **Alternating rhythm** (one full-width item then three in a row)
* **Floating watermark** (turns the first paragraph of a group into a giant, faint word drifting behind the content, still read as text by assistive technology)
* **Stacked sections** (each child of the group sticks to the top and the next slides over it, switched off in print, under reduced motion and in short windows)
* **Inverts over backgrounds** (a difference blend meant for logos, icons and decoration rather than body text)

Images: **Circle**, **Pill** (a portrait orientation is available), **Arch**, **Offset corners** (a mirrored orientation is available), **Ringed photo** (a round photo whose caption turns around it as an inline drawing hidden from assistive technology, while the caption stays available to screen readers) and **Backdrop** (a plate in the second accent colour offset behind the picture, towards the bottom and the reading end; the picture keeps its proportions and nothing overflows, and the native Shadow setting replaces the plate).

The crop and backdrop styles also apply to the Featured image and to the picture of Text and photo.

Picture proportions: the Proportion setting of the Image block (Styles tab, Dimensions panel) is native and stays in charge inside Text and photo and Team, whose square photo gives way to the proportion chosen on the picture; the pictures of a Photo gallery follow the gallery's own Proportion and Crop images settings instead. A shape style such as Circle, Pill or Arch brings its own proportion, which a proportion chosen on the block replaces (a circle then becomes an oval). The proportions offered come from the theme; see the theme's readme to change them from a child theme.

Cover: **Photo panel** and **Photo panel, accent** put the text in an opaque panel (surface or accent colour) with soft corners, placed by the block's Content position setting (centre by default), with no veil on the picture at the starting overlay opacity of 50; any other opacity brings the veil back, a cover without a picture keeps its colour at any opacity other than 50 (it starts at 100), and the text colour set on the block wins over the panel's.

Hover styles:

* For a paragraph or a Navigation block, **Live highlight**, **Contrast swap**, **Stretching underline** and **Tilt** (the same on hover and on keyboard focus, never on buttons nor on links around an image).
* For the root group of the header, **Floating capsule** (a rounded header that floats under the top of the window with a blurred backdrop when its background is Base) and **Detaches on scroll** (starts flush and becomes the capsule over the first screen of scroll, a plain sticky header where the browser cannot follow the scroll without a script, a fixed capsule under reduced motion, static in print).
* For buttons, **Pixel**, **Retro shadow** (an offset hard shadow that also fits a group or a Card block, pressed on hover and on focus), **Invert on hover**, **Animated underline**, **Scroll cue** (with its bobbing arrow), **Label swap** (the label slides out and comes back in from below, written once in the page so it is never read twice) and **Ripple** (an outlined button filled by a disc that grows from its centre).

The Palette screen also has a Link hover effect setting that applies one of the four link effects to every link of the content and of the Navigation block, off by default, under 600 bytes of inline CSS. Each style is CSS only, loaded inline on the pages that use it, and the ones that move respect the reduced-motion preference. They register only when the Fluffless theme is active. A pattern that needs an inactive module is not offered, so the editor never inserts an unregistered block.

A theme can ship its own sheet for a block style. WordPress registers the styles declared in the theme's `styles/**/*.json` files; Suite then loads `assets/block-styles/<slug>.css` from the child theme, else from the parent theme, inline on the pages where a block carries `is-style-<slug>` and in the editor, as it does for its own styles, as long as the style is registered for that block type. Relative `url()` references in the sheet are resolved against the theme, and comments are kept.

The same file name in the child theme replaces the Suite sheet for that slug (not for the `survol-*` hover styles, which have no file). A style whose CSS lives in `style.css` needs no such file. Suite does not read the theme's JSON files, and a slug registered both by Suite and by a theme keeps Suite's label; a theme that wants its own label for a Suite slug unregisters and re-registers the style on `init` after Suite.

= Modules =

* Cleanup: removes code WordPress adds to every page and that most sites never use (emoji scripts, oEmbed discovery, legacy head links, comment feeds, Heartbeat outside editors, XML-RPC). Global styles are left alone.
* Images: converts uploaded JPEG and PNG images to WebP (or AVIF when the server supports it), skips unneeded core sizes, warns above 400 KB and refuses above 2,048 KB. Both limits can be changed. A video file above 20 MB is never refused, but comes with the recipe to encode it on your computer first (HandBrake, H.264 or AV1, 1080p at most): the suite never encodes video on the server.
* WebP media library: converts images already in the media library, in small batches, when you click.
* Show by device: adds a "Show on" choice to every block (everywhere, desktop only or mobile only), plus a separate padding, text alignment and text size for mobile. A few lines of CSS, only on pages that use them.
* Display conditions: gives a category, a content type or precise pages their own site template or header, from a screen of the Fluffless menu, without opening the Site Editor. The first matching rule wins. Nothing is added to pages.
* Loop cards: chooses once, per content type, how each post looks in every Query Loop (card, list, mosaic from the theme, or a synced pattern of yours), from a screen of the Fluffless menu; change it in one place and all grids and archives follow. A Loop card block places it by hand. No script.
* Custom fields: declares content types and their fields from a screen (a Team member type with role, email, phone, LinkedIn and short bio comes ready), enters them in a Fields box on the edit screen, and binds a paragraph, heading, image or button to a field from the editor's Custom field panel; native, ACF and Meta Box fields are read through one block binding. A loop card and two templates (member, team archive) come with the Team members case. No script on the site.
* Canvas: background images on Columns, Column, Details and Media & Text blocks, a separate background for small screens and free decorative image layers.
* Expression: wave dividers, scrolling stories with a sticky scene, pop-ups that open only on click, a dismissible announcement bar and a floating element fixed in a corner of the screen. Motion stops when visitors ask for reduced motion.
* Shape and density: corner rounding, slanted edges and spacing for the whole site, and what editors may change.
* Palette: builds a readable colour palette and estimates the screen power darker colours can save on OLED screens. It can also alternate the backgrounds of the sections of a page between two palette colours; sections that already have a background keep it, and the Data table block uses the same pair for its stripes.
* Style presets: create block styles without code (colours, spacing, corners, border, shadow, text size) from the site styles. They show up in the editor's Styles panel.
* Free fonts: adds a curated collection of free fonts to the Font Library. A font is copied to your site once, when you install it, then served from your own domain.
* Footer: choose which footer elements appear, and on which devices. The “Made with Fluffless” credit, a link to fluffless.dev, is on by default like the other elements; untick it in the Footer settings of Fluffless > Modules to remove it.
* Page editor: a welcome screen for new pages with twelve full pages (home, about, services, contact, donation, pricing, team, sales page, portfolio, pillar guide, contact with booking, case study), a short questionnaire that writes a first draft for seven of them, an "Add a section" library and a simple editing mode. Anyone who manages options can save the current page as a page template from the Fluffless panel: it becomes a card on the welcome screen, removable from there, and travels with the settings export.
* Light SEO: description, canonical link, social sharing tags and structured data. It turns itself off when another SEO plugin is active. The Details blocks of a page are described as an FAQ unless the `faq_schema` setting is turned off (a theme can also answer the `fluffless_suite_seo_faq` filter). The sharing image, used by `og:image`, `twitter:image` and the structured data, goes through the `fluffless_seo_image` filter (the resolved address and the post ID; return an empty string to publish no image), the same filter name as in Fluffless SEO.
* Statistics: counts visits with a Plausible or Umami instance, with no cookie.
* Pexels photos: searches free Pexels photos from the editor and imports only the photo you pick, resized, converted to WebP and credited.
* Lean shop: keeps WooCommerce on the pages that sell. Its scripts and styles leave the rest of the site, order tracking is off or follows the visitor's consent, and product images are served at a sensible size.
* Lean courses: keeps Sensei LMS on the pages that teach. Its styles and the WordPress icon font leave the rest of the site.
* Lean forms: keeps Contact Form 7, WPForms, Fluent Forms, Formidable and Gravity Forms on the pages that hold a form. Their styles and scripts (jQuery included) leave the rest of the site, and the theme dresses their forms in its own colours, fonts and shapes. The audit flags any third-party captcha.
* Outgoing email: sends the site's email through an SMTP account, with a test send and a log of the last failures. The password lives in wp-config.php, never in the database. Off by default.
* Form: a native form block, one field per block. Validation, a honeypot and a minimum fill time instead of a third-party captcha, an email to the site and a copy to the sender. Responses kept on the site only when a form asks for it, for a set number of days, with CSV export and one-click deletion from the settings. Fields shown only when a choice or a consent box has a given value, and forms split into steps, both without JavaScript on the page. Five ready-made forms: contact, quote request, newsletter sign-up, event registration and job application.

    Each option of a list, choice or checkbox field can send to its own addresses, never shown in the page. An optional proof of work (ALTCHA format, hosted by the site, under 6 KB of script, no third party or cookie) filters out robots that do not run JavaScript and slows down the others; it needs JavaScript, is skipped on a site without HTTPS, is asked only on the last step, spares pages cached before it was turned on (unless the time checks are off), and is off by default. The settings warn when the challenge route is blocked or cached. Nothing kept by default.

    When a submission has errors, the summary is announced and the first field in error takes the focus. The form token carries the time the page was rendered: a page served by a full-page cache for more than seven days answers "This page is out of date" until the cache is refreshed. The `fluffless_suite_form_timing_checks` filter (return false) turns the time checks off; the token is then the same at every render, and the public `Form::TOKEN_PATTERN` constant lets an HTML comparison mask it in one expression.
* Newsletter: a Form block signs people up to Cyberimpact (data in Canada) or Mailchimp (data in the United States) through the tool's API, from your server, after the page has answered. Only when the form names a newsletter consent box and the person checks it (the box can stay optional on a contact form); a box that accepts the privacy policy never counts. Double opt-in by default. Existing contacts are never changed, and an address that unsubscribed is never signed up again. The key lives in wp-config.php (`FLUFFLESS_NEWSLETTER_KEY`), never in the database.

    A failed sign-up is never lost silently: the site's administration address receives the list, at most once an hour (a sign-up added without its proof of consent is listed too), and the settings show the count of the last 30 days without any address. A connection test reads the list without signing anyone up. Off by default.
* Events: an Event content type with start and end, place and postal address, online and registration links, and a status (scheduled, postponed or cancelled), entered in an Event panel of the editor. Past events leave the archive and the Query Loops on their own; a Query Loop with the `is-past-events` class lists them instead, latest first, and the `fluffless_events_query_vars` filter adjusts both. Each event page carries Event structured data (rescheduled events keep their previous date; Fluffless SEO takes over when an event is given its own schema there) and an Add to my calendar file.

    The archive offers a calendar feed to subscribe to from Apple Calendar, Outlook or Google Calendar, which keeps the last 90 days and up to 200 events, upcoming ones first. An Event calendar block shows a month. The address of the archive is chosen once, from the site language and the pages already published. Blocks bound to an empty event detail are left out of the page. A password-protected event shows none of its details anywhere, the REST API, the sitemap and the site search included. No JavaScript on the site. Off by default.
* Minification: removes spaces and comments from pages and their inline CSS. Off by default.
* Inline CSS pruning: removes from the inline CSS of your pages the color, size and font classes no block uses, and the image lightbox styles when no image opens one. Visitors only: the editor keeps everything. Off by default.
* Housekeeping: lists extra revisions, inactive plugins and themes, old drafts, unused media and expired temporary data. It never deletes anything on its own.
* Fluff check: checks your hosting on demand (renewable-powered host, HTTP/2, compression, caching and home page weight), weighs the shop, a product page and the cart when WooCommerce is active (the checkout is reported as not measured when WooCommerce sends an empty cart back to the cart page), lists the images and videos hosted in your media library that weigh too much (images above the Images module thresholds, videos above its threshold or 1080p), and reminds you until you declare that the host backs up the site and that the host caches pages, each with the date of your last check.

    The suite never backs up the site or caches pages itself: both jobs belong to the host, and a page cache built into the theme would duplicate what most hosts already do while lagging behind a change on the page; if a shop is set up, the reminder also asks that the cart, checkout and account pages be excluded from that cache. Admin only.
* Settings export and import: a small JSON file with every setting of the suite, to replay the same configuration on another site. No content, no media, no key.
* Page footprint: an editor panel with page weight, two emission estimates and a rating, based on the Sustainable Web Design model.
* Content quality: an editor panel that flags headings out of order (a skipped level, an empty heading and, on block themes, an H1 the template already shows or a missing H1 when the template hides the title), images without alternative text or with a file name as alternative text (Image, Media & Text and Classic or Custom HTML blocks), and links whose text does not say where they lead (“click here”, “read more”, a bare web address), synced patterns included.

    Each line selects its block; an image can be marked as decorative and unmarked later; the panel comes back before publishing when something is left. Plain rules that run in the browser; nothing is sent anywhere.
* Dashboard guide: a dashboard box that says where to start, at the top until the person has started, then among the core boxes. The person who manages the site sees the first steps still to do and the pending actions, everyone who writes sees links to edit or add a page, write a post and view the site. It can be hidden from Screen Options, like the core boxes.
* Review: lists every editor note on the site, page by page, open or resolved, with a link to the block it points to.
* Reviewer: a role that can leave notes on the pages it is assigned to and change nothing else.
* Carbon report: visits counted by Statistics multiplied by the grams measured by Page footprint, over a chosen period (last 30 days, month, quarter, year to date, last year or custom dates), with a CSV export. Pages never measured get the average of the measured ones and are marked as estimates. Reads your Plausible or Umami instance through an API key set as the FLUFFLESS_STATS_API_KEY constant in wp-config.php. Figures cover transfer and use only and are not an offset or a neutrality claim. An optional link to Planetair, off by default, can follow the method: reduce first, contribute after.
* Carbon at a glance: a dashboard box for the person who manages the site, with the grams of CO2e per view, the total over the last 30 days, the trend from the 30 days before and the five heaviest pages as plain bars. It reuses the figures of Page footprint and Carbon report and computes nothing new; without visits, it shows the average and the heaviest measured pages. No script.
* Maintenance mode: a waiting page in the site’s own styles (title, message, optional login link), either as maintenance (status 503, search engines come back later) or coming soon (status 200, not indexed). Anyone who can edit content keeps seeing the site, with a reminder in the admin bar. No script.
* Basic security: a hidden login address (the usual wp-login.php and wp-admin answer 404 to visitors), a pause after too many failed logins per IP address, the usual security headers, XML-RPC turned off, and a short activity log (logins and failures, plugins and theme, Suite settings, accounts; entries removed after 90 days, 200 shown). No firewall and no file scan: those belong to the host.
* Site status: a read-only REST route protected by a key (constant or environment variable, never an option) that answers with the site’s health for a dashboard watching several sites: versions, pending updates, sobriety check, declared backup, maintenance mode and the last activity log entries without any IP address. Off by default.
* Site kits: a whole site in one click for a sector (nonprofit, craftsperson, consultant, restaurant): the pages, the palette, the fonts and the shape of the corners, each part optional. Nothing is deleted, a page whose address already exists is left alone, the home page is set only if the site has none, and each part can be undone from its own screen. Agencies add their own kits with the `fluffless_kits` filter.
* Page builder migration: finds the pages made with either of the two supported page builders (JSON tree or nested shortcodes) and converts them in place to native blocks (headings, text, images, buttons, lists, columns, sections with their anchors, embeds, galleries, social links, details for tabs and accordions). Content and structure are kept, the look follows the site styles (fixed colours dropped unless asked), unknown elements are kept as plain text and counted, forms are listed as things to rebuild, and every page can be put back as it was.

    Price tables and lists, post lists (as a Query block), the first slide of a slideshow, flip boxes, blurbs, calls to action, full-width headers, team members, counters and saved templates or global modules inserted in a page are converted too; theme-builder templates and popups are listed. The global colours and fonts of the builder are read and proposed: the palette is offered only when it passes the contrast checks, the font duo only when the font bank has it, and nothing is applied without a click.

    Pages can be converted one at a time or all together (20 per pass, press again to continue), from the screen or with WP-CLI (`wp fluffless migrate`, dry run by default), and each conversion or restore is written to the activity journal when Basic security keeps one. The builder data is never deleted. Off by default.
* Outgoing requests: an allowlist of domains your server may contact. The site itself is always allowed.
* Contacted servers screen: the servers your site and its visitors' browsers contact with your current settings, WordPress included, with a list you can copy. Built without any request.
* Campaign links: an admin screen that builds a link with utm_source, utm_medium, utm_campaign, utm_term and utm_content (lowercase, hyphens for spaces, encoded), with a Copy button and up to 20 saved presets. Nothing is added to the pages of the site. Off by default.
* Updates: brings Fluffless updates into Dashboard > Updates. Each package is checked against its signature before it is installed.

The cookie consent banner is a separate free plugin, Fluffless Consent. When it is active, the Legal bar block shows its "Manage my cookies" link.

== Installation ==

1. Download Fluffless Suite from your fluffless.dev account.
2. In your admin area, go to Plugins > Add New Plugin > Upload Plugin, choose the ZIP file, then click Install Now and Activate.
3. Go to Fluffless > Modules to turn modules on or off and to enter your licence key.

== Frequently Asked Questions ==

= Does it work without the Fluffless theme? =

The modules work with any theme. Patterns and block styles register only under the Fluffless theme.

= Which modules are on by default? =

The modules that lighten pages, measure them and guide the work: Cleanup, Images, Light SEO, Fluff check, Page footprint, Housekeeping, Footer, Shape and density, Palette, Style presets, Page editor, Site kits, Form, Review, Reviewer, Content quality, Dashboard guide, Carbon at a glance and Updates. Lean shop, Lean courses and Lean forms are on as well, but do nothing until WooCommerce, Sensei LMS or a supported form plugin is active.

Everything else waits until you turn it on in Fluffless > Modules: the modules that change what visitors see (Canvas, Expression, Show by device), those that call an outside service or send email (Pexels photos, Statistics and the Carbon report that reads it, Outgoing email, Newsletter), those that rewrite files or content (Minification, WebP media library, Page builder migration), and Outgoing requests, Display conditions, Loop cards, Custom fields, Free fonts, Events, Maintenance mode, Basic security and Site status.

= Can I move a site made with a page builder to native blocks? =

Turn on the Page builder migration module, then open Content > Migration. The screen lists the pages made with either supported page builder. Convert one page, open it in the editor, and put it back if the result is not right: the builder data stays on the page until you decide to remove the builder yourself. Words, images, links, lists, columns and sections are kept; the look follows the site styles, so fixed colours are dropped unless you tick the box to keep them. Forms, popups, theme-builder templates and a few widgets without a native block are listed as things to rebuild. When the first pages look right, convert the rest in batches, or run `wp fluffless migrate --all --no-dry-run` on a large site. The builder plugin can then be deactivated, page by page checks done.

= What is deleted when I delete the plugin? =

Everything the plugin created, on every site of a network: the `fluffless_settings` option (the `consent` section used by Fluffless Consent stays), the other options (shape, palette, licence, forms, conditions, cards, fields, page templates, styles), the activity log table, the kept form responses, the events, the Reviewer role (accounts that only had it become Subscribers), the per-page Reviewer, footprint and migration tracking data, the closed reduced-mode notice of each account, the other module options (event feed address, section video, settings saved before an import, fonts list), and the temporary data. Quarantined media, converted images, Pexels credits, installed fonts and the original copy of pages converted from a page builder stay. Deactivating the plugin removes only temporary data.

Nothing asks for confirmation when you delete a plugin in WordPress. To keep it all, tick "Keep my data when the plugin is deleted" in the Form module settings, or add `define( 'FLUFFLESS_KEEP_DATA', true );` to wp-config.php. The Plugins screen shows a warning under Fluffless Suite while form responses are kept. Export them from the form settings first if unsure.

= Which constants can I set in wp-config.php? =

All of them are optional. Add them before the line "That's all, stop editing!". A constant wins over the matching setting on screen, and secrets are only read from a constant or the environment, never from the database.

* `FLUFFLESS_LICENCE_KEY`: your licence key (updates and support). Wins over the key typed in Fluffless > Modules.
* `FLUFFLESS_SMTP_PASSWORD`: the password of the Email delivery module. The environment variable of the same name works too. The other SMTP settings stay on screen.
* `FLUFFLESS_STATUS_KEY`: the key of the read-only status route (at least 16 characters), sent in the `X-Fluffless-Key` header. The environment variable of the same name works too.
* `FLUFFLESS_STATS_API_KEY`: the API key of your statistics instance, used by the Carbon report. The environment variable of the same name works too.
* `FLUFFLESS_PEXELS_KEY`: your free Pexels API key, used by the Pexels photos module. Constant only, no environment variable.
* `FLUFFLESS_FONT_BANK`: HTTPS address of the font bank used by the Free fonts module. Set it to an empty string to download each font file from its original host instead.
* `FLUFFLESS_KEEP_DATA`: set to `true` to keep all data when the plugin is deleted (see above).
* `FLUFFLESS_API_URL`: address of the Fluffless API, for a test or staging server. The default is the public API.
* `FLUFFLESS_SIGNING_PUBLIC_KEY`: replaces the public key that checks the signature of updates. Without a valid key no update is offered.
* `FLUFFLESS_ALLOW_UNSIGNED`: set to `true` to accept unsigned packages. Honoured only when the environment type is `local` or `development`; anywhere else it is ignored and an admin notice says so.

Fluffless SEO adds `FLUFFLESS_SEO_KEEP_DATA`, described in its own readme.

= What are the known limits? =

The heavy image warning appears on the next classic admin screen, not in the block editor. The refusal above the upper limit is immediate. With XML-RPC turned off, the `system.*` introspection methods still answer.

== Privacy ==

Fluffless Suite adds no cookie and no tracking script to your pages. When the automatic dark mode is on and a visitor uses its button, their choice is kept in their browser's local storage (`fluffless-scheme` key), and Fluffless Consent lists it in its suggested privacy policy text. The Statistics module, when you turn it on, loads the Plausible or Umami script you configure.

Some personal data stays on your own site and is sent nowhere. The form responses of a form that asks to keep them, 30 days by default and 365 days at most, then deleted. With Basic security on, the activity log: the username of each login, plus the IP address and the username typed for each failed login or lockout, removed after 90 days (a duration you can change). With Outgoing email on, the address of a recipient whose email could not be sent, in the log of the last failures, removed after 30 days.

= External services =

The plugin contacts the services below. Most requests happen only when you turn on a module or click a button. When the Outgoing requests module is on, each domain must be on its allowlist.

Fluffless > Settings > Contacted servers shows these services as your settings use them, with what each one receives and whether Outgoing requests blocks it.

**Fluffless API (api.fluffless.dev)**
Used by the Updates module to check for updates, download signed packages and verify your licence key. Each request sends the plugin slug, installed version, update channel (stable or beta) and a SHA-256 fingerprint of your site address. The site address itself is never sent. The licence key is sent in a request header, never in the address.
When you send a problem report from the admin area, the report sends your description, the expected result, your admin language and, if you provide them, a reply email address and a screenshot. If you tick the diagnostics box, it also sends the WordPress and PHP versions, active theme and version, plugin version, active modules, multisite status, locale and the site fingerprint.
Terms and privacy: https://fluffless.dev

**Green Web Foundation (api.thegreenwebfoundation.org)**
Used by the Fluff check, only when you run it. The plugin sends your site's host name to `https://api.thegreenwebfoundation.org/api/v3/greencheck/` to learn whether your host is listed as running on renewable energy. No request is made for a local domain. The result is kept for 30 days and used by the Hosting badge block.
Privacy policy: https://www.thegreenwebfoundation.org/privacy-policy/

**Your home page and the files it loads**
The Fluff check also requests your own home page (and, with WooCommerce, the shop, one product page, the cart and the checkout), then sends HEAD requests to each file it loads, including files on third-party domains, to read their weight and caching headers; a file whose HEAD answer gives no size is downloaded once to weigh it. Those third parties receive a request from your server's IP address.

**Fluffless font bank (fluffless-polices.canner.app), Fontshare (cdn.fontshare.com), Google Fonts (fonts.gstatic.com)**
Used by the Free fonts module, only when you install a font. The plugin downloads the WOFF2 file once and serves it from your own domain afterwards. Fonts under the Fontshare licence come from cdn.fontshare.com. No visitor ever contacts these services. The font bank address can be changed with the `FLUFFLESS_FONT_BANK` constant or the `fluffless_font_bank` filter; when it is set to an empty value, the other fonts come straight from Google Fonts (fonts.gstatic.com).
Fontshare terms: https://www.fontshare.com/terms

**Pexels (api.pexels.com, images.pexels.com)**
Used by the Pexels photos module, only when an editor searches for photos. The plugin sends the search words, the page number and the site language to the Pexels API with your own API key (the `FLUFFLESS_PEXELS_KEY` constant). When you pick a photo, the plugin downloads it from images.pexels.com. Visitors never contact Pexels.
Terms: https://www.pexels.com/terms-of-service/ Privacy policy: https://www.pexels.com/privacy-policy/

**Plausible or Umami (address you configure)**
Used by the Statistics module, only when you turn it on and enter a script address and a site ID. By default, each visitor's browser loads the script and sends visit events directly to that host, so the service receives the visitor's full IP address and user agent. With Route through my domain on (off by default), your server fetches the script from that address and serves it from your own domain, and visit events go to your site, which forwards them to the `/api/event` (Plausible) or `/api/send` (Umami) endpoint of the same host with the user agent and a shortened IP address (the last part removed: /24 for IPv4, /64 for IPv6), enough to find the country. The service's own privacy policy applies. For Plausible: https://plausible.io/privacy For Umami: https://umami.is/privacy
The Carbon report module reads the same service through its API, only when an administrator opens the report and an API key is set (the `FLUFFLESS_STATS_API_KEY` constant): your server asks the host of the script address (api.umami.is for Umami Cloud) for page views and visitors over the chosen period, with the site ID and the key. Nothing about a visitor is sent.

**Planetair (planetair.ca)**
A link only, in the Carbon report module: a Quebec non-profit that funds climate projects. The link is off by default and appears only on the report screen, for administrators, once the "Show a contribution link under the method" setting is on. Nothing is sent to Planetair until someone clicks: the link carries no tracking parameter and no referrer (rel="noreferrer", referrerpolicy="no-referrer"), and it is never shown to visitors. Once there, Planetair's own terms and privacy policy apply.

**Cyberimpact (api.cyberimpact.com) or Mailchimp (<data center>.api.mailchimp.com)**
Used by the Newsletter module, only when you turn it on, set a key in wp-config.php and a visitor checks the newsletter consent box of a form that asks for it. Your server sends the email address, the first and last name if the form asks for them, the language of the page and, for Mailchimp, the sign-up date and time; for Cyberimpact without double opt-in, the date, the wording of the consent box and the page address. The visitor's IP address is never sent. The Test the connection button in the settings reads the list or group without signing anyone up. The module adds a paragraph to the privacy policy guide naming the tool and where it stores data.
Cyberimpact terms: https://app.cyberimpact.com/terms-and-conditions?l=en_ca Privacy policy: https://app.cyberimpact.com/privacy-policy?l=en_ca
Mailchimp terms and privacy: https://mailchimp.com/legal/

**YouTube (www.youtube-nocookie.com) or Vimeo (player.vimeo.com)**
Used by the Video block, only after a visitor clicks the play button. Until then, the block shows a poster and a link drawn by the plugin, and nothing is loaded from the video service. On click, the visitor's browser loads the player without cookies (youtube-nocookie.com, Vimeo with do not track); with Fluffless Consent active, only once the Embedded content category is accepted, otherwise the link opens the video page.
YouTube terms: https://www.youtube.com/t/terms Google privacy policy: https://policies.google.com/privacy Vimeo terms: https://vimeo.com/terms Vimeo privacy policy: https://vimeo.com/privacy

**Your email provider (SMTP server you configure)**
Used by the Outgoing email module, only when you turn it on and fill in the server. Every email the site sends (to you, your customers or the people who fill in a form) goes to that SMTP server with its recipients, subject and content, and the username and password of the account. The provider's own terms and privacy policy apply.

== Copyright ==

Fluffless Suite, Copyright 2026 Geneviève Bergeron. Distributed under the terms of the GNU GPL, version 2 or later.

Bundled resources:

* Lucide icons (Icon block), Copyright 2026 Lucide Icons and Contributors, ISC License. Parts derived from Feather, Copyright 2013-2023 Cole Bemis, MIT License. See blocks/icone/LICENSE. https://lucide.dev
* Simple Icons 16.30.0 (Social networks block), CC0 1.0 Universal. Brand names and logos remain the property of their owners. See blocks/reseaux/LICENSE.md. https://simpleicons.org
* Pixelarticons 2.4.1 (admin screen icons), Copyright 2019 Gerrit Halfmann, MIT License. See assets/admin/LICENSE-icons. https://pixelarticons.com
* Font previews (Free fonts screen and Site Editor panel): 36 images in assets/specimens, a sample title or paragraph drawn from each font offered by the module, as SVG outlines or WebP pictures. No font file is bundled. Each image follows the licence of its font: SIL Open Font License 1.1 for the Google Fonts families, ITF Free Font License for the Fontshare families.

Fonts offered by the Free fonts module are not bundled. Each one is downloaded only when you install it. Families from Google Fonts use the SIL Open Font License 1.1 (https://openfontlicense.org/). Families from Fontshare use the ITF Free Font License (https://www.fontshare.com/licenses/itf-ffl).

== Changelog ==

= 0.2.1 =
* Login page in the site's colours (palette, font, corner radius, logo or site icon), under the Fluffless theme only, moved here from the theme. Return false on the fluffless_login_styles filter to keep the WordPress login page.
* Plain text (descriptions, og:description, JSON-LD): non-breaking spaces (U+00A0, U+202F) are kept instead of being replaced by an ordinary space. A blank mixing both kinds still collapses to one ordinary space.

= 0.2.0 =
* Content quality (new module, on by default): an editor panel that flags headings out of order, images without alternative text and links that do not say where they lead.
* Dashboard guide (new module, on by default): the first steps and pending actions for the person who manages the site, everyday tasks for everyone who writes.
* Review (new module, on by default): every editor note on the site, page by page. It reads the block notes of WordPress 7.1 and stays empty before.
* Reviewer (new module, on by default): a role that can leave notes on the pages it is assigned to and change nothing else. Like Review, it needs the block notes of WordPress 7.1.
* Style presets (new module, on by default): block styles without code.
* Events (new module, off by default): with the Event calendar block and the Upcoming events pattern.
* Newsletter (new module, off by default): server-side sign-up from the Form block to Cyberimpact or Mailchimp, with double opt-in, consent proof and a digest of failed sign-ups.
* Inline CSS pruning (new module, off by default).
* Security: the maintenance page and the protected-content form no longer reveal the hidden login address, and a direct POST to wp-login.php that contradicts its action gets a 404.
* Privacy: personal data exporter and eraser for the WordPress privacy tools, a daily purge of expired form responses, and the stats relay sends truncated IP addresses (/24, /64).
* Uninstall: the settings are kept when FLUFFLESS_KEEP_DATA is true or “Keep my data when the plugin is deleted” is checked; before, deleting the plugin erased its settings.
* Settings export and import, also with WP-CLI (`wp fluffless settings export`, `import` and `undo`): a preview and a confirmation before import, and the export leaves out the hidden login address.
* Form: a failed send is kept and reported to the administrator, with an address the visitor can write to; at most 50 copies to the visitor per day for the whole site (`fluffless_form_copy_cap` filter); recipients by answer; optional proof of work (ALTCHA format, `fluffless_form_altcha_max` filter); the first field in error takes the focus and the error summary is announced without taking it.
* Updates: the free Fluffless theme and Fluffless Consent are offered through the signed Fluffless channel, with the same checks (manifest signature, package signature, SHA-256, version inside the package).
* Licence and seats (Updates card, Fluffless > Modules): the seats in use (“3 of 5 sites”), a button to free this site's seat or the seat of another address, and a Renew my licence link. Development and staging sites (WP_ENVIRONMENT_TYPE) take no seat. An expired, revoked or under-review licence gets its own notice, and the site keeps working. The card says what the site sends to the update server (Quebec Law 25). The update server cannot free a seat yet: until it can, write to me and I'll free it.
* Carbon report: page views read from Umami 3 (`metrics/expanded`), with a fallback for Umami 2.
* Contacted servers tab (Fluffless > Modules): the servers your site contacts with your current settings.
* Admin menu: entries renamed Site kits and Modules.
* Fluff check, the new name of the audit module: new rules for the weight of media library and WooCommerce images, for videos, and when the host has no page cache.
* Page editor: Pricing page and Team page on the welcome screen, both with a questionnaire.
* New patterns: Article, Restaurant menu page and “Data table, adjustable”.
* New blocks: Scrolling keywords, Floating element, Chart, Data table, Dark mode button, Glossary and its Term block, Breadcrumb path, Timeline step.
* Timelines: each step is a Timeline step block, nameable and with its own anchor; timelines saved with Groups keep rendering.
* 37 new block styles, among them Photo panel and “Photo panel, accent” on the Cover block, Backdrop on images, Gradient text, Highlighter, Floating capsule, Detaches on scroll and four hover effects; group variations Section and Section header.
* Palette screen: light or dark by device, alternating section backgrounds, and a link hover effect for the whole site.
* SEO module: `faq_schema` setting (FAQPage can be turned off), and the BreadcrumbList follows the Breadcrumb path block, with the posts page and post type archives.
* SEO module: when your site converts uploads to WebP or AVIF, the share image (og:image) is the original JPEG or PNG if it shows the same picture. The share image no longer goes through the `post_thumbnail_url` filter: to change it, use `fluffless_seo_image`.
* Menu panel: the Fluffless theme closes it with the Escape key.
* Custom fields: a password-protected entry shows no bound field.
* Blocks: the HTML anchor becomes the `id` of every block, and ids stay stable from one page to the next.
* For child themes and other plugins: `assets/block-styles/<slug>.css` sheets, filters `fluffless_outgoing_hosts`, `fluffless_suite_breadcrumb_trail`, `fluffless_suite_anchor_target`, `fluffless_suite_seo_faq`, `fluffless_seo_image` and `fluffless_suite_form_timing_checks`, public constant `TOKEN_PATTERN`, `data-fluffless-esc` attribute and `fluffless_nav_escape` action.
* Some Suite screens still show in English on French sites; their translation comes with 0.2.1.

= 0.1.0 =
* First release.
