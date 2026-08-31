<?php

defined( 'ABSPATH' ) || exit;

final class SML_CLI_Command {
    /**
     * Imports languages, post and term translation groups, and String Translation values from WPML.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would be imported without writing to the database.
     *
     * [--yes]
     * : Perform the import. A database backup is required first.

     * [--strings-only]
     * : Import only WPML String Translation values. Post, term, menu, URL and language settings are not changed.
     *
     * ## EXAMPLES
     *
     *     wp sml migrate_wpml --dry-run
     *     wp sml migrate_wpml --yes
     *     wp sml migrate_wpml --strings-only --yes
     */
    public function migrate_wpml( $args, $assoc_args ) {
        $dry_run = empty( $assoc_args['yes'] );
        if ( ! empty( $assoc_args['dry-run'] ) ) {
            $dry_run = true;
        }
        $strings_only = ! empty( $assoc_args['strings-only'] );
        if ( ! $dry_run ) {
            WP_CLI::warning( $strings_only ? 'Writes only imported interface-string tables. Posts, terms, menus, URLs and language settings are not changed.' : 'Writes post meta, term meta, plugin options and imported string tables. WPML data is not deleted.' );
        }
        try {
            $result = $strings_only ? SML_WPML_Migrator::run_strings_only( $dry_run ) : SML_WPML_Migrator::run( $dry_run );
        } catch ( Throwable $error ) {
            WP_CLI::error( $error->getMessage() );
            return;
        }
        foreach ( $result as $name => $count ) {
            WP_CLI::log( $name . ': ' . $count );
        }
        if ( $dry_run ) {
            WP_CLI::success( 'Dry run complete. Run again with --yes after taking a database backup.' );
            return;
        }
        if ( ! $strings_only ) {
            SML_Core::schedule_rewrite_flush();
        }
        WP_CLI::success( $strings_only ? 'WPML interface strings imported. Content, relationships and URLs were not changed.' : 'WPML data imported and rewrite rules refreshed.' );
    }
}

WP_CLI::add_command( 'sml', 'SML_CLI_Command' );
