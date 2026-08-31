=== Simple Multilang Blocks ===
Contributors: asgru
Tags: multilingual, gutenberg, woocommerce, wpml migration
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.7.0
License: GPL-2.0-or-later

A lightweight multilingual layer for block-based WordPress sites.

== Features ==

* Post, page, WooCommerce product and taxonomy translation relationships.
* Language-prefixed URLs, a styled automatic/shortcode language switcher and hreflang tags.
* Per-theme switcher design controls: header pill, rounded selector or vertical list; spacing, flags/names, floating position and optional colours.
* Shared and language-specific classic menus, plus Site Editor Navigation block links, follow the active language.
* WPML migration for content relationships and String Translation entries.
* Public-interface string screen for the active theme and selected plugins.
* Manual draft creation and editor-triggered DeepL/OpenAI translations through a bounded background queue, always marked for review.
* Responses API-compatible OpenAI translation requests and a curated GPT-5 mini/nano model selector.
* Translation Review workspace with queue status and safe retry for a failed provider request.
* PO import/export for the plugin's editable public-interface string catalogue.
* Gutenberg Language Switcher block, alongside the automatic and shortcode variants.
* WooCommerce product and related-product lists follow the active language without affecting checkout, account, REST or admin requests.
* Create or queue linked category, attribute and other taxonomy-term translations directly from the term editor, with their own review and queue views.
* WordPress update notifications from signed GitHub Release assets.

== Updating from GitHub ==

For a public GitHub repository, create a release whose tag is a semantic version (for example `v1.3.0`) and attach an asset named `simple-multilang-blocks.zip`. WordPress then shows the normal update notification; no token or paid service is needed.

For a private repository, set a fine-grained read-only token in `wp-config.php`:

`define( 'SML_GITHUB_TOKEN', 'github_pat_...' );`

The token is never stored in the database or displayed in WordPress.

== Automatic translation ==

Select DeepL or OpenAI in **Settings → Simple Multilang**. Credentials are intentionally configured only in `wp-config.php`:

`define( 'SML_DEEPL_API_KEY', '...' );`

or

`define( 'SML_OPENAI_API_KEY', '...' );`

The model selector offers a reviewed, economical list: **GPT-5 mini** (recommended for written translation) and **GPT-5 nano** (simple high-volume text after editorial evaluation). A `SML_OPENAI_MODEL` value in `wp-config.php` can override the selector, but the plugin accepts only these reviewed choices unless a developer extends `sml_openai_translation_models`.

The plugin never sends content to a provider until an editor queues a translation. The request runs through a small WordPress background queue and retries a temporary provider problem up to three times. Successful post results are linked drafts; taxonomy terms use the same queue and a review marker. Neither is published automatically. Failed jobs remain visible in **Tools → Translation review**. If a provider is unavailable, no duplicate content, term or frontend error is shown.

== Visitor-triggered drafts ==

**Settings → Simple Multilang → Automatic translation** can opt in to queue a review-only draft when a visitor opens public source content through an untranslated language URL. It is disabled by default to protect provider budget from crawlers and has a daily cap of 10 queued drafts (maximum 100). A visitor never waits for an API call, no visitor data is stored, and the public page stays on its safe source content until an editor verifies and publishes the draft. Repeated requests for one item and failed jobs do not consume budget again.

== Interface strings ==

Open **Settings → Interface strings** to edit strings from the active theme and selected active plugins. POT catalogues are scanned where available; otherwise a selected plugin's visible public strings are captured using the text WordPress already displays. The editable catalogue can be exported and imported as PO. The plugin applies these values only to the public interface through standard gettext filters; it does not modify theme/plugin source, PO or MO files.

== Language switcher ==

The switcher can be automatic or shortcode-only, and is also available as a Gutenberg block. Its appearance is stored per active theme: theme colours, light, dark or minimal. Add a theme-specific CSS class when a project needs its own styling layer.

**Settings → Simple Multilang → Language switcher → Quick design** configures a header pill, rounded selector or vertical list; regular or compact spacing; flags and names; four floating positions; and optional theme-specific colours. The shortcode and block inherit these settings, so a header needs no custom template code.

Themes can use `sml_language_switcher_design`, `sml_language_switcher_args`, `sml_language_switcher_links`, `sml_language_switcher_languages`, `sml_language_switcher_classes`, `sml_language_switcher_item`, `sml_language_switcher_css_variables`, `sml_language_switcher_html` and `sml_automatic_language_switcher_html` to customise output safely. When WPML is inactive, the plugin also supports the common `wpml_current_language`, `wpml_object_id` and `wpml_active_languages` integration filters plus `icl_get_languages()`.

== Language-specific home pages ==

WordPress has one static homepage setting. **Settings → Simple Multilang → Language home pages** shows the source page plus every language counterpart, its publication state and safe edit/create actions. The source page's **Language & translations** panel shows the same homepage context. A published linked counterpart automatically becomes the homepage at its language root (such as `/ru/`), receives the normal `is_front_page()` theme behaviour, and replaces home links such as the site logo. The old translated-page slug URL redirects to that language root.

== Menus ==

A shared menu maps linked page, product and term links to the active language. If no counterpart is available, the item and its descendants are omitted instead of creating a wrong-language link. For language-specific custom links or labels, create one classic menu per language and connect them under **Appearance → Multilingual menus**. `wp_nav_menu()` then picks the matching menu. Navigation blocks in the Site Editor map linked links and `wp_navigation` references in the same way. Use **Scan navigation labels** to expose hand-written menu labels in **Settings → Interface strings**.

== License ==

GPL-2.0-or-later. This plugin is free software: you may use, study, modify and redistribute it under the GNU General Public License version 2 or later. See the included LICENSE file.

== Migrating from WPML ==

Take a database backup. In the plugin settings run **Migration preflight** first, then use **Import WPML data**, or run `wp sml migrate_wpml --dry-run` followed by `wp sml migrate_wpml --yes`.

The importer does not delete WPML tables, settings or plugin files. It recreates relationships only where WPML has a real multi-language translation group; standalone records remain language-labelled and are never guessed into a relationship. Hierarchical page and term parents are reconciled only with their linked counterpart, preventing mixed-language URLs. Verify representative pages, product categories and translated interface strings before removing WPML from disk.
