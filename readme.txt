=== Simple Multilang Blocks ===
Contributors: asgru
Tags: multilingual, gutenberg, woocommerce, wpml migration
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPL-2.0-or-later

A lightweight multilingual layer for block-based WordPress sites.

== Features ==

* Post, page, WooCommerce product and taxonomy translation relationships.
* Language-prefixed URLs, language switcher shortcode and hreflang tags.
* WPML migration for content relationships and String Translation entries.
* WordPress update notifications from signed GitHub Release assets.

== Updating from GitHub ==

Create a GitHub Release whose tag is a semantic version (for example `v1.1.1`) and attach an asset named `simple-multilang-blocks.zip`. WordPress then shows the normal update notification.

For a private repository, set a fine-grained read-only token in `wp-config.php`:

`define( 'SML_GITHUB_TOKEN', 'github_pat_...' );`

The token is never stored in the database or displayed in WordPress.

== Migrating from WPML ==

Take a database backup. In the plugin settings use **Import WPML data**, or run `wp sml migrate_wpml --dry-run` followed by `wp sml migrate_wpml --yes`.

The importer does not delete WPML tables, settings or plugin files. Verify representative pages, product categories and translated interface strings before removing WPML from disk.
