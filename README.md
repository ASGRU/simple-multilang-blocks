# Simple Multilang Blocks

Simple Multilang Blocks is a small, self-contained multilingual layer for WordPress block sites. It stores language links in ordinary post and term metadata and does not depend on a SaaS service.

## Security model

- All write actions require WordPress capabilities and nonces.
- Translation IDs are checked against the object type before a relationship is saved.
- No content is sent to a translation service until an editor explicitly uses **Auto-translate**.
- API keys are read only from `wp-config.php`, never from the database or the WordPress settings screen.
- Machine translations are saved only as drafts marked **Requires review**. They are never automatically published.
- There is no telemetry and no request/content debug logging.
- GitHub updates are read from release metadata. Public repositories need no token or paid service; private repositories can use an optional read-only token defined in `wp-config.php`, never in a WordPress option.

## Release process

Push a semantic-version tag such as `v1.3.0`. The included GitHub Actions workflow packages `simple-multilang-blocks.zip`, including the GPL license, and creates a GitHub Release. WordPress discovers public releases on the usual plugin-update schedule; private repositories require the documented read-only token.

## License

Simple Multilang Blocks is free software, released under the GNU General Public License version 2 or later (GPL-2.0-or-later). You may use, study, modify and redistribute it under those terms. See [LICENSE](LICENSE).

## WPML migration

1. Back up the database.
2. Run `wp sml migrate_wpml --dry-run`.
3. Run `wp sml migrate_wpml --yes`.
4. Verify pages, WooCommerce product/category archives, navigation and translated interface strings. The importer recreates relationships only where WPML has a multi-language translation group; standalone source records keep their language label and are not guessed into a relationship.
5. Deactivate WPML only after verification. The importer never deletes WPML data.

## Editing interface strings

The active theme's `languages/*.pot` file is catalogued on activation and can be rescanned under **Settings → Interface strings**. Select only the active plugins whose public labels should be translated; their POT files are scanned too. If a selected plugin has no POT file, its visible public strings are catalogued from the text already rendered by WordPress.

Translations are applied only to the public site interface by default. The WordPress admin, service actions, content translations, routes and language relationships are not altered. The theme/plugin source and shipped `.po` / `.mo` files remain untouched.

## Language switcher appearance

Switcher placement and appearance are stored per active theme. **Use theme colors** inherits `theme.json` colour presets when available; light, dark and minimal variants are also available. A theme-specific CSS class can be supplied for a project's own style layer.

## Automatic translations

Automatic translations are editor-triggered: use the language buttons on a post, page or product list, or the **Language & translations** sidebar in the editor. The source language can be any language configured in Simple Multilang.

Choose a provider in **Settings → Simple Multilang** and define the corresponding secret outside the database:

```php
// wp-config.php — choose one provider.
define( 'SML_DEEPL_API_KEY', '...' );
// or
define( 'SML_OPENAI_API_KEY', '...' );

// Optional: defaults to gpt-5-mini when not defined in wp-config.php.
define( 'SML_OPENAI_MODEL', 'gpt-5-mini' );
```

The admin settings select the DeepL Free/Pro endpoint and the OpenAI model name, but never accept or display API secrets. If a provider is disabled, unavailable or returns an invalid response, the plugin shows an editor-only notice and creates no duplicate draft or frontend error.
