=== Fluffless ===
Contributors: genevievebergeron
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A light, expressive block theme. Bare but mighty.

== Description ==

Fluffless is a block theme built with native WordPress blocks only. It loads one tiny accessibility script for visitors (under 1 KB, so that Escape closes the mobile menu) and downloads no web fonts: every font choice uses fonts already installed on the visitor's device.

The theme includes:

* 11 templates: index, single, a post with a giant title, page, archive, archive in a grid, search, 404, a sections page and two blank canvas pages.
* Five header template parts to pick from in the Site Editor (default, plain, centred, with an announcement banner, shop with search and cart link), all sharing one menu, plus a footer part.
* 6 style variations, each a palette with its own duotone filters: Raw (concrete grey, uppercase industrial headings), Warm (saturated sand, serif headings), Light (cool near-white, teal), High contrast (pure black on pure white, navy), Soft (pastel lavender, rounded) and Editorial (ivory paper, crimson, display headings). The default palette is dark, with a yellow accent. Every palette names nine roles (base, surface, text, secondary text, line, accent, text on accent, second accent, alert), so a colour picked by role survives a change of palette.
* 4 typography presets: Friendly (rounded), Classic (didone), Technical (monospace) and Sturdy (slab serif), all on system fonts.
* 15 block styles for buttons, groups, columns, headings, images and lists: outlined button, arrow button, pixel outline and pixel arrow buttons (square corners notched by one pixel, arrow drawn on a 12 by 12 grid in the text colour), card group, raw border, top rule, diagonal, inverted, giant heading, highlighted heading (a marker band behind each line, in the accent colour), offset image frame, greyscale on hover, monochrome image and checkmark list.
* 31 block patterns: page openers, services, price list, portfolio, team, testimonial, figures, FAQ, newsletter, call to action, announcement banner, blog grid, three loop cards (card, list, mosaic) for any Query Loop, contact, headers and footers, plus complete home, about, services and contact pages.
* A mobile menu built on the native Details block, usable with the keyboard. Only the Escape key needs a script, under 1 KB.
* Balanced line breaks in headings and paragraphs where the browser supports them.
* Canadian French translation.

The footer patterns calculate the legal line (copyright symbol, current year and privacy policy link) on the server.

== Installation ==

1. In your admin area, go to Appearance > Themes and click Add New Theme.
2. Search for "Fluffless".
3. Click Install, then Activate.

To install from a ZIP file, go to Appearance > Themes > Add New Theme > Upload Theme, choose the file, then click Install Now and Activate.

== Frequently Asked Questions ==

= Does the theme load any web fonts? =

No. The font stacks in theme.json and in the typography presets list system fonts only, such as system-ui, Charter, Seravek or ui-monospace. Nothing is downloaded from a font service.

= How do I open and close the mobile menu? =

Below 600 px, the menu becomes a Details block labelled "Menu". Open or close it with a click, the Enter key or the Space bar. The Escape key closes it too and returns the focus to the "Menu" button: the theme marks that Details block with a data-fluffless-esc attribute at render time and loads a script of 1,200 bytes (about 580 compressed), only on pages where such a block is rendered. Other Details blocks (an FAQ, for instance) keep their native behaviour. A plugin or child theme can opt its own Details element in by adding the same attribute and calling do_action( 'fluffless_nav_escape' ) while it renders.

= How does the menu show the current section? =

WordPress marks the current page's link with aria-current="page" and the current-menu-item class. The theme adds the current-menu-ancestor class (and current-page-ancestor for pages) to any Navigation Link or Submenu whose target is a parent of the page being viewed, even when that page is not in the menu, and to a Submenu that contains such a link. On a term archive, a link to a parent term gets current-menu-ancestor as well. Style it with .current-menu-ancestor > .wp-block-navigation-item__content; the theme underlines it lightly. No aria-current is added for ancestors.

The theme's menu shows no submenu icon. If a child theme turns it on (showSubmenuIcon), the toggle button is at least 24 by 24 pixels while the arrow keeps its size.

Give every Navigation block a name (the "ariaLabel" attribute, or a menu name). When several unnamed blocks render on one page, WordPress names the second one " 2": the theme removes such a number-only label instead of leaving it to screen readers.

= How do I change one colour from a child theme? =

A child theme that declares `settings.color.palette` in its own theme.json replaces the whole palette of the parent, it does not add to it. Colours you leave out disappear (base, contrast, surface, muted, divider, on-accent, accent-2 and alert), and the site falls back to black on white with the WordPress default colours. Copy the nine colours of the parent theme.json into the child theme.json and change only the values you want:

`base`, `surface`, `contrast`, `muted`, `divider`, `accent`, `on-accent`, `accent-2`, `alert`.

If you only want another accent, the Palette module of Fluffless Suite and the style variations do it without a child theme.

= Why are the pattern images empty? =

The patterns ship with empty image blocks so the theme bundles no photos. Replace each one with an image from your media library and write its alt text.

= Is there a dark mode? =

No. The theme does not switch colours with the system setting, because a site should look the same to everyone who opens it and because the switch would need a second palette to check for contrast. For a dark site, pick a dark style variation in the Site Editor, or duplicate one and set the base and surface roles to dark colours; the contrast check applies to whichever palette is active.

= Does the theme work without other plugins? =

Yes. All templates, parts and patterns use core blocks only.

= Can a child theme add a class to the editor canvas? =

Yes. WordPress only copies the admin colour scheme, post type and embed classes to the body of the editor iframe, so a child stylesheet scoped to a body class would not apply in the editor. Declare the classes in the child theme and the parent adds them to the canvas body (and to iframed previews) in the editor only:

`add_theme_support( 'fluffless-editor-canvas-classes', array( 'my-site', 'my-site-page' ) );`

Up to 20 class names, cleaned with sanitize_html_class(). The same list applies to every post type; use the post-type-* classes to tell them apart. The editor without an iframe is not covered.

= Can a child theme change the proportions offered on images? =

Yes. The Proportion setting of the Image block (Styles tab, Dimensions panel) is native and lists the WordPress ratios (square, 4:3, 3:2, 16:9 and their portrait versions); the theme declares none, so a child theme gets that list. To offer its own, declare them in its theme.json and, to replace the native ones rather than add to them, turn the default list off:

`{ "settings": { "dimensions": { "defaultAspectRatios": false, "aspectRatios": [ { "name": "Wide", "slug": "wide", "ratio": "21/9" } ] } } }`

The chosen proportion is written on the picture as an inline style with object-fit cover, so it wins over any stylesheet rule that does not use !important; the theme and the Suite force none.

= Why do my pattern images get width and height attributes? =

WordPress only adds width, height, loading and decoding to images from the media library. An image served from the theme folder (a child theme's pattern, for instance) had none, so the page shifted while it loaded. For the Image and Media & Text blocks, the theme reads the size from the file, when the address points inside the active theme or its parent and the file is a webp, png, jpeg, gif, avif or svg (viewBox or width and height attributes, first 4 KB), and writes width and height. WordPress then adds decoding and loading as it does for uploads. An image that already has one of the two attributes, an upload or an external image is left alone.

= Why does a table receive the keyboard focus? =

WordPress lets a wide table scroll sideways inside its figure. A scrolling area that cannot take the focus is out of reach for keyboard users, and it has no name. The theme gives every Table block's figure tabindex="0", role="region" and a name: the caption (which gets a stable id) when there is one, otherwise "Table" (then "Table 2", "Table 3" for the next caption-less tables on the page, so each landmark keeps its own name). Without a script, the tab stop exists even when the table does not overflow; that is the documented trade-off. To name a caption-less table after a nearby heading, use the fluffless_table_region_label filter (default label and parsed block as arguments). Attributes already present on the figure are kept. To opt out: remove_filter( 'render_block_core/table', 'fluffless_table_scroll_region' ).

== Changelog ==

= 0.2.1 =
* The login page styling moved to Fluffless Suite: plugin territory under the theme review rules. The fluffless_login_styles filter keeps working there.
* The shared Navigation menu is no longer created when the theme is activated, only the first time a header or footer needs it.
* French (Canada): the strings of the Home, About, Contact, Team and Price list patterns that were missing from the translation catalogue are now translated.

= 0.2.0 =
* New: Price list and Announcement banner patterns, Post with a giant title template, Top rule group style, Pixel outline and Pixel arrow button styles.
* One shared menu: the header, the phone menu and the footers use the same Navigation menu; with Fluffless Suite, the pages of a kit are added to it before Contact.
* Menus, footers and buttons no longer lead to a missing page: links to pages not created yet resolve when the page is shown.
* One H1 per page, decided from the blocks, synced patterns included.
* Sample contact details and sentences in the footers show only in the editor.
* Accessibility: a focus ring readable on bright sections, the menu panel closes with Escape or when the focus leaves it, and a wide table can be scrolled with the keyboard.
* Giant text size follows the width of its column: no cut word and no horizontal scroll at 320 px.
* WooCommerce pages keep their contrast on the seven palettes.
* Pattern fixes: zigzag order on phones, FAQ, placeholders for images not chosen yet.

= 0.1.0 =
* First release.
* Theme support fluffless-editor-canvas-classes: a child theme declares body classes for the editor canvas.
* Image and Media & Text: width and height read from the file for an image served from the theme folder.
* Table: the figure becomes a focusable, named region (caption or "Table", filter fluffless_table_region_label).
* Navigation: current-menu-ancestor on links and submenus that lead to a parent of the current page or term; Escape closes the mobile menu (action fluffless_nav_escape for other Details elements); 24 px submenu toggle; number-only aria-label removed.

== Copyright ==

Fluffless WordPress Theme, Copyright 2026 Geneviève Bergeron.
Fluffless is distributed under the terms of the GNU GPL.

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

Bundled resources:

Fluffless bundles no font files, photos or icon sets. Font stacks reference fonts already installed on the visitor's device. Pattern image blocks are empty placeholders.

screenshot.png: a capture of the theme's own demo home page, made for Fluffless and released under the same licence (GPLv2 or later). It contains no third-party images.
