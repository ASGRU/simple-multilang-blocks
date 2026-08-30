=== Simple Multilang Blocks ===
Contributors: asgru
Tags: multilingual, gutenberg, woocommerce, wpml migration
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-2.0-or-later

A lightweight multilingual layer for block-based WordPress sites.

== Features ==

* Post, page, WooCommerce product and taxonomy translation relationships.
* Language-prefixed URLs, a styled automatic/shortcode language switcher and hreflang tags.
* WPML migration for content relationships and String Translation entries.
* Public-interface string screen for the active theme and selected plugins.
* Manual draft creation and editor-triggered DeepL/OpenAI translations, always marked for review.
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

Optional: `define( 'SML_OPENAI_MODEL', 'gpt-5-mini' );`

The plugin never sends content to a provider until an editor presses **Auto-translate**. Successful results are linked drafts with a **Requires review** marker; they are never published automatically. If a provider is unavailable, no draft is created and no frontend error is shown.

== Interface strings ==

Open **Settings → Interface strings** to edit strings from the active theme and selected active plugins. POT catalogues are scanned where available; otherwise a selected plugin's visible public strings are captured using the text WordPress already displays. The plugin applies these values only to the public interface through standard gettext filters; it does not modify theme/plugin source, PO or MO files.

== Language switcher ==

The switcher can be automatic or shortcode-only. Its appearance is stored per active theme: theme colours, light, dark or minimal. Add a theme-specific CSS class when a project needs its own styling layer.

== License ==

GPL-2.0-or-later. This plugin is free software: you may use, study, modify and redistribute it under the GNU General Public License version 2 or later. See the included LICENSE file.

== Migrating from WPML ==

Take a database backup. In the plugin settings use **Import WPML data**, or run `wp sml migrate_wpml --dry-run` followed by `wp sml migrate_wpml --yes`.

The importer does not delete WPML tables, settings or plugin files. It recreates relationships only where WPML has a real multi-language translation group; standalone records remain language-labelled and are never guessed into a relationship. Verify representative pages, product categories and translated interface strings before removing WPML from disk.
