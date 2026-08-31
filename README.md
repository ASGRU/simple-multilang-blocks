# Simple Multilang Blocks

Simple Multilang Blocks is a small, self-contained multilingual layer for WordPress block sites. It stores language links in ordinary post and term metadata and does not depend on a SaaS service.

## Security model

- All write actions require WordPress capabilities and nonces.
- Translation IDs are checked against the object type before a relationship is saved.
- No content is sent to a translation service until an editor explicitly uses **Auto-translate**.
- API keys are read only from `wp-config.php`, never from the database or the WordPress settings screen.
- Machine translations are saved only as drafts marked **Requires review**. They are never automatically published.
- Navigation links are resolved only from validated post, term and menu relationships; unavailable links are omitted instead of pointing to another language.
- There is no telemetry and no request/content debug logging.
- GitHub updates are read from release metadata. Public repositories need no token or paid service; private repositories can use an optional read-only token defined in `wp-config.php`, never in a WordPress option.

## Release process

Push a semantic-version tag such as `v1.8.2`. The included GitHub Actions workflow packages `simple-multilang-blocks.zip`, including the GPL license, and creates a GitHub Release. WordPress discovers public releases on the usual plugin-update schedule; private repositories require the documented read-only token.

## License

Simple Multilang Blocks is free software, released under the GNU General Public License version 2 or later (GPL-2.0-or-later). You may use, study, modify and redistribute it under those terms. See [LICENSE](LICENSE).

## WPML migration

1. Back up the database.
2. Run `wp sml migrate_wpml --dry-run`.
3. Run `wp sml migrate_wpml --yes`.
4. Read the preflight warnings before importing: it reports WPML rows whose referenced objects no longer exist and groups with duplicate language assignments. If it reports no linked groups, the import cannot reconstruct content relationships; it can only preserve language labels and String Translation values.
5. Verify pages, WooCommerce product/category archives, navigation and translated interface strings. The importer recreates relationships only where WPML has a multi-language translation group; standalone source records keep their language label and are not guessed into a relationship.
6. Deactivate WPML only after verification. The importer never deletes WPML data.

If preflight reports no valid linked content groups, use **Import WPML interface strings only** instead. This narrowly writes only Simple Multilang’s editable string catalogue; it does not change posts, terms, menus, URLs or language settings. The equivalent command is `wp sml migrate_wpml --strings-only --yes` after a backup.

## Editing interface strings

The active theme's `languages/*.pot` file is catalogued on activation and can be rescanned under **Settings → Interface strings**. Select only the active plugins whose public labels should be translated; their POT files are scanned too. If a selected plugin has no POT file, its visible public strings are catalogued from the text already rendered by WordPress.

Translations are applied only to the public site interface by default. The WordPress admin, service actions, content translations, routes and language relationships are not altered. The theme/plugin source and shipped `.po` / `.mo` files remain untouched.

## Language switcher appearance

Switcher placement and appearance are stored per active theme. **Use theme colors** inherits `theme.json` colour presets when available; light, dark and minimal variants are also available. A theme-specific CSS class can be supplied for a project's own style layer.

**Settings → Simple Multilang → Language switcher → Quick design** provides a header-style pill (the default), a rounded vertical selector or a compact vertical list; regular/compact spacing; language names and flags independently; four floating positions; and five optional theme-specific colours. `[sml_language_switcher]` and the Language switcher block inherit these settings. Use the shortcode or block inside a header; automatic mode is the floating fallback.

Themes can customise the selector without copying plugin templates:

```php
add_filter( 'sml_language_switcher_design', function ( $design ) {
    $design['style'] = 'pills';
    $design['density'] = 'compact';
    return $design;
} );

add_filter( 'sml_language_switcher_css_variables', function ( $variables ) {
    $variables['--sml-accent'] = '#1d5db8';
    return $variables;
} );
```

Further extension points are `sml_language_switcher_args`, `sml_language_switcher_links`, `sml_language_switcher_languages`, `sml_language_switcher_classes`, `sml_language_switcher_item`, `sml_language_switcher_html` and `sml_automatic_language_switcher_html`. When WPML is inactive, the public `wpml_current_language`, `wpml_object_id`, `wpml_active_languages` filters and `icl_get_languages()` are also available for themes that use those common WPML integration points.

## Language-specific home pages

WordPress stores one static homepage in **Settings → Reading**. **Settings → Simple Multilang → Language home pages** shows the source page and the state of every language version in one table. Create a safe manual draft (or queue an available automatic provider) for a missing language, then review and publish it. The same controls and a clear homepage notice are available in the source page's **Language & translations** panel; do not configure each translation separately in WordPress. Once published, Simple Multilang serves the linked page from the language root (for example `/ru/`), makes `is_front_page()` true so the theme uses its homepage template, localises ordinary home links such as the site logo, and redirects the translated page's old slug URL to the language root.

## Menus

One shared classic menu stays structurally in sync: its linked page, product and taxonomy links are replaced with the counterpart in the active language. If an object is not available in that language, the menu item and its child items are omitted rather than linking visitors to the wrong language.

For language-specific custom links or labels, create a classic menu per language and link them at **Appearance → Multilingual menus**. The normal `wp_nav_menu()` call then chooses the matching menu automatically. The same object-link mapping applies to the Site Editor's Navigation block, including linked `wp_navigation` posts. Use **Scan navigation labels** once to add manual classic-menu labels and custom Navigation-block labels to **Settings → Interface strings**; their translations remain public-interface-only.

## Automatic translations

Automatic translations are editor-triggered: use the language buttons on a post, page or product list, the **Language & translations** sidebar in the editor, or **Queue automatic translation** in a category, tag or attribute editor. The source language can be any language configured in Simple Multilang.

Choose a provider in **Settings → Simple Multilang** and define the corresponding secret outside the database:

```php
// wp-config.php — choose one provider.
define( 'SML_DEEPL_API_KEY', '...' );
// or
define( 'SML_OPENAI_API_KEY', '...' );

// Optional: use gpt-5.4-mini (recommended) or gpt-5.4-nano.
define( 'SML_OPENAI_MODEL', 'gpt-5.4-mini' );
```

The admin settings select the DeepL Free/Pro endpoint and a short OpenAI model list, but never accept or display API secrets. **GPT-5.4 mini** is the recommended default for written translations; **GPT-5.4 nano** is available only for simple high-volume work after editorial evaluation. The list can be extended by code through `sml_openai_translation_models`; the settings screen otherwise cannot select an unreviewed model. Before a provider sees content, Simple Multilang protects Gutenberg block comments, HTML markup, URLs, shortcodes and format placeholders with per-request opaque tokens. A response is accepted only when every token comes back exactly once; otherwise no draft is created, the job can retry safely and the editor can use a manual draft. Editor requests enter a small WordPress background queue, which retries a temporary provider failure up to three times. Posts become drafts and taxonomy terms receive a **Requires review** marker; both appear under **Tools → Translation review**. If the provider is disabled, unavailable or returns an invalid response, no duplicate content, term or frontend error is created.

### Visitor-triggered drafts

Under **Settings → Simple Multilang → Automatic translation**, an editor may opt in to create a review-only draft when a visitor opens public source content through a language URL that has no linked translation. This is off by default: public crawlers can otherwise consume a translation budget. The setting has a daily cap (10 by default, 100 maximum); repeated requests for the same item and failed jobs do not consume the budget again. No API call runs in the page response and no visitor identifiers are stored. The page remains safely available in its current source form until an editor reviews and publishes the generated draft. Use `sml_on_demand_translation_candidate` to reject individual source items, or `sml_on_demand_translation_enabled` and `sml_on_demand_translation_daily_limit` for code-level policy.

### Translation freshness

When an editor changes the title, excerpt or content of a post/page/product in a linked group, its other language versions are marked **Source updated**. No text is copied, replaced or published. The status appears in the editor, the post list and **Tools → Translation review**; after reviewing an affected version, use **Mark verified** to clear it. New manual and machine drafts record the exact source revision they were based on.

## PO exchange and switcher block

**Settings → Interface strings** can export translations for one language as a PO file and import the same PO format later. Use the **All sources** selector beside search to narrow the table to the active theme, one plugin or an imported catalogue; it remains combined with text search, saving and pagination. This only writes the plugin's own interface-string catalogue; it never replaces a theme or plugin's shipped PO/MO files. Entries marked `fuzzy` import as **Requires review**.

An administrator may select a source and target language below the visible table and choose **Auto-translate missing strings (up to 20)**. The action has a WordPress nonce and `manage_options` permission check. It sends only the displayed rows without an existing target translation, never overwrites editor-approved work, and saves a complete returned batch only after validating its protected markup and placeholders. All new values are marked **Requires review**.

The **Language switcher** block is available in the Block and Site editors. It renders the same current-page links, accessibility labels and theme-aware visual style as `[sml_language_switcher]`; no custom theme PHP is required.

## URLs, hierarchy and retries

When a linked hierarchical page or category has a translated parent, Simple Multilang keeps that relationship in the same language. If the parent has not been translated yet, the child stays at the language root instead of creating a mixed-language URL; the relation is restored automatically once both counterparts are linked. WPML imports reconcile all known page and term hierarchies after their translation groups are imported.

Failed automatic translations can be queued again from **Tools → Translation review**. Requeuing does not contact DeepL or OpenAI in the browser request; it restarts the same bounded background process.

WooCommerce product lists—including related-product and block queries—follow the active language. Cart, checkout, account, REST and other administrative/service requests are not filtered by the multilingual layer.

## Taxonomy terms

The term editor offers **Create linked term** and **Queue automatic translation** beside every missing language. Automatic translations use the same bounded background queue as posts, copy only safe custom term metadata, map an already translated parent and are marked **Requires review**. An editor can mark the completed term verified from its edit screen or **Tools → Translation review**. Existing terms remain linkable by ID, so the plugin never silently merges same-named categories.
