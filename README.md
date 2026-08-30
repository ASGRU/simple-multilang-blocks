# Simple Multilang Blocks

Simple Multilang Blocks is a small, self-contained multilingual layer for WordPress block sites. It stores language links in ordinary post and term metadata and does not depend on a SaaS service.

## Security model

- All write actions require WordPress capabilities and nonces.
- Translation IDs are checked against the object type before a relationship is saved.
- No content is sent to a translation service until an editor explicitly uses **Auto-translate**.
- API keys are read only from `wp-config.php`, never from the database or the WordPress settings screen.
- Machine translations are saved only as drafts marked **Requires review**. They are never automatically published.
- There is no telemetry and no request/content debug logging.
- GitHub updates are read from public release metadata. No token or paid service is required. Private forks can use an optional read-only token defined in `wp-config.php`, never in a WordPress option.

## Release process

Push a semantic-version tag such as `v1.2.5`. The included GitHub Actions workflow packages `simple-multilang-blocks.zip`, including the GPL license, and creates a public GitHub Release. WordPress discovers it on the usual plugin-update schedule.

## License

Simple Multilang Blocks is free software, released under the GNU General Public License version 2 or later (GPL-2.0-or-later). You may use, study, modify and redistribute it under those terms. See [LICENSE](LICENSE).

## WPML migration

1. Back up the database.
2. Run `wp sml migrate_wpml --dry-run`.
3. Run `wp sml migrate_wpml --yes`.
4. Verify pages, WooCommerce product/category archives, navigation and translated interface strings.
5. Deactivate WPML only after verification. The importer never deletes WPML data.

## Editing theme strings

The active theme's `languages/*.pot` file is catalogued on activation and can be rescanned under **Settings → Theme strings**. The translations are stored by Simple Multilang and applied through WordPress gettext filters, so the theme files and its shipped `.po` / `.mo` files remain untouched.

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
