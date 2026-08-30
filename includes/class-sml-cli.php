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
     *
     * ## EXAMPLES
     *
     *     wp sml migrate_wpml --dry-run
     *     wp sml migrate_wpml --yes
     */
    public function migrate_wpml( $args, $assoc_args ) {
        $dry_run = empty( $assoc_args['yes'] );
        if ( ! empty( $assoc_args['dry-run'] ) ) {
            $dry_run = true;
        }
        if ( ! $dry_run ) {
            WP_CLI::warning( 'Writes post meta, term meta, plugin options and imported string tables. WPML data is not deleted.' );
        }
        try {
            $result = SML_WPML_Migrator::run( $dry_run );
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
        SML_Core::schedule_rewrite_flush();
        WP_CLI::success( 'WPML data imported and rewrite rules refreshed.' );
    }
}

WP_CLI::add_command( 'sml', 'SML_CLI_Command' );
