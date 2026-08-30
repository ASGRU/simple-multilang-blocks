# Simple Multilang Blocks

Simple Multilang Blocks is a small, self-contained multilingual layer for WordPress block sites. It stores language links in ordinary post and term metadata and does not depend on a SaaS service.

## Security model

- All write actions require WordPress capabilities and nonces.
- Translation IDs are checked against the object type before a relationship is saved.
- There is no automatic translation service, telemetry or debug logging.
- GitHub updates are read from public release metadata. No token or paid service is required. Private forks can use an optional read-only token defined in `wp-config.php`, never in a WordPress option.

## Release process

Push a semantic-version tag such as `v1.2.1`. The included GitHub Actions workflow packages `simple-multilang-blocks.zip` and creates a public GitHub Release. WordPress discovers it on the usual plugin-update schedule.

## License

Simple Multilang Blocks is free software, released under the GNU General Public License version 2 or later (GPL-2.0-or-later). You may use, study, modify and redistribute it under those terms. See [LICENSE](LICENSE).

## WPML migration

1. Back up the database.
2. Run `wp sml migrate_wpml --dry-run`.
3. Run `wp sml migrate_wpml --yes`.
4. Verify pages, WooCommerce product/category archives, navigation and translated interface strings.
5. Deactivate WPML only after verification. The importer never deletes WPML data.
