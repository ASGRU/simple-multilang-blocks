<?php

defined( 'ABSPATH' ) || exit;

/**
 * Makes theme gettext strings editable in the Simple Multilang database.
 *
 * The active theme's POT file is the source of truth. Runtime filters only
 * read translations that were explicitly catalogued, avoiding frontend writes
 * and avoiding accidental capture of strings from unrelated plugins.
 */
final class SML_Theme_Strings {
    const OPTION_PLUGIN_DOMAINS = 'sml_interface_plugin_domains';
    const OPTION_CAPTURE = 'sml_interface_string_capture';
    const OPTION_PUBLIC_ONLY = 'sml_interface_public_only';

    private static $instance;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'gettext', array( $this, 'filter_gettext' ), 20, 3 );
        add_filter( 'gettext_with_context', array( $this, 'filter_gettext_with_context' ), 20, 4 );
        add_action( 'admin_menu', array( $this, 'register_page' ) );
        add_action( 'admin_post_sml_scan_theme_strings', array( $this, 'scan_theme_strings' ) );
        add_action( 'admin_post_sml_save_theme_strings', array( $this, 'save_theme_strings' ) );
        add_action( 'admin_post_sml_auto_translate_theme_strings', array( $this, 'auto_translate_theme_strings' ) );
        add_action( 'admin_post_sml_export_po', array( $this, 'export_po' ) );
        add_action( 'admin_post_sml_import_po', array( $this, 'import_po' ) );
    }

    public static function available_plugin_domains() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        $active = (array) get_option( 'active_plugins', array() );
        if ( is_multisite() ) {
            $active = array_unique( array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
        }
        $result = array();
        foreach ( $active as $file ) {
            if ( empty( $all_plugins[ $file ] ) || SML_BASENAME === $file ) {
                continue;
            }
            $data = $all_plugins[ $file ];
            $domain = ! empty( $data['TextDomain'] ) ? sanitize_key( $data['TextDomain'] ) : sanitize_key( dirname( $file ) );
            if ( ! $domain || '.' === $domain ) {
                continue;
            }
            $result[ $domain ] = array(
                'name' => ! empty( $data['Name'] ) ? (string) $data['Name'] : $domain,
                'file' => $file,
                'path' => WP_PLUGIN_DIR . '/' . $file,
            );
        }
        uasort( $result, static function ( $left, $right ) {
            return strcasecmp( $left['name'], $right['name'] );
        } );
        return $result;
    }

    public static function selected_plugin_domains() {
        return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) get_option( self::OPTION_PLUGIN_DOMAINS, array() ) ) ) ) );
    }

    public static function is_public_interface_only() {
        return '0' !== (string) get_option( self::OPTION_PUBLIC_ONLY, '1' );
    }

    public static function is_capture_enabled() {
        return '0' !== (string) get_option( self::OPTION_CAPTURE, '1' );
    }

    public static function save_interface_settings( $domains, $capture, $public_only ) {
        $available = self::available_plugin_domains();
        $domains = array_values( array_intersect( array_map( 'sanitize_key', (array) $domains ), array_keys( $available ) ) );
        update_option( self::OPTION_PLUGIN_DOMAINS, $domains );
        update_option( self::OPTION_CAPTURE, $capture ? '1' : '0' );
        update_option( self::OPTION_PUBLIC_ONLY, $public_only ? '1' : '0' );
    }

    public function register_page() {
        add_submenu_page(
            'options-general.php',
            __( 'Interface strings', 'simple-multilang-blocks' ),
            __( 'Interface strings', 'simple-multilang-blocks' ),
            'manage_options',
            'sml-theme-strings',
            array( $this, 'render_page' )
        );
    }

    public function filter_gettext( $translation, $text, $domain ) {
        if ( ! $this->can_translate_domain( $domain ) || '' === (string) $text ) {
            return $translation;
        }
        return $this->translated_value( $domain, '', $text, $translation );
    }

    public function filter_gettext_with_context( $translation, $text, $context, $domain ) {
        if ( ! $this->can_translate_domain( $domain ) || '' === (string) $text ) {
            return $translation;
        }
        return $this->translated_value( $domain, $context, $text, $translation );
    }

    private function translated_value( $domain, $gettext_context, $text, $fallback ) {
        $context = self::catalogue_context( $domain );
        $name = self::string_name( $gettext_context, $text );
        $string_id = SML_Core::find_string_id( $context, $name );
        if ( ! $string_id && self::is_capture_enabled() && self::is_safe_source_string( $text ) ) {
            $string_id = SML_Core::register_string( $context, $name, $fallback );
        }
        if ( $string_id && SML_Core::get_current_language() === SML_Core::get_default_language() && self::is_safe_source_string( $fallback ) ) {
            SML_Core::update_string_source( $string_id, $fallback );
        }
        if ( ! $string_id ) {
            return $fallback;
        }
        return SML_Core::get_string_translation( $string_id, SML_Core::get_current_language(), $fallback );
    }

    private function can_translate_domain( $domain ) {
        if ( self::is_public_interface_only() && is_admin() && ! wp_doing_ajax() ) {
            return false;
        }
        if ( ! is_string( $domain ) || '' === $domain ) {
            return false;
        }
        return $this->is_theme_domain( $domain ) || in_array( sanitize_key( $domain ), self::selected_plugin_domains(), true );
    }

    private function is_theme_domain( $domain ) {
        $domain = (string) $domain;
        if ( '' === $domain ) {
            return false;
        }
        $theme = wp_get_theme();
        $parent = $theme->parent();
        return $domain === (string) $theme->get( 'TextDomain' ) || ( $parent && $domain === (string) $parent->get( 'TextDomain' ) );
    }

    private static function string_context( $domain ) {
        return 'theme:' . sanitize_key( $domain );
    }

    private static function catalogue_context( $domain ) {
        $theme = self::instance()->is_theme_domain( $domain );
        return ( $theme ? 'theme:' : 'plugin:' ) . sanitize_key( $domain );
    }

    private static function is_safe_source_string( $text ) {
        $text = (string) $text;
        return '' !== trim( $text ) && strlen( $text ) <= 5000;
    }

    private static function string_name( $gettext_context, $text ) {
        return '' !== (string) $gettext_context ? (string) $gettext_context . "\004" . (string) $text : (string) $text;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $strings_table = SML_Core::strings_table();
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $sources = self::catalogue_sources();
        $available_sources = wp_list_pluck( $sources, 'context' );
        $source = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
        // The selected value must be one of the catalogue contexts actually
        // stored in this site. This keeps the SQL exact and makes imported
        // WPML contexts filterable alongside themes and plugins.
        if ( ! in_array( $source, $available_sources, true ) ) {
            $source = '';
        }
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per_page = 20;
        // Imported WPML String Translation records keep their original context
        // so a site can continue to edit its known interface strings.
        $where = '1=1';
        $args = array();
        if ( '' !== $search ) {
            $where .= ' AND (context LIKE %s OR name LIKE %s OR source_value LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $args = array( $like, $like, $like );
        }
        if ( '' !== $source ) {
            $where .= ' AND context = %s';
            $args[] = $source;
        }
        $count_sql = "SELECT COUNT(*) FROM {$strings_table} WHERE {$where}";
        $total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
        $rows_sql = "SELECT id, context, name, source_value FROM {$strings_table} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d";
        $row_args = array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $row_args ) );
        $languages = SML_Core::get_languages();
        $base_url = admin_url( 'options-general.php?page=sml-theme-strings' );
        ?>
        <div class="wrap sml-admin-wrap">
            <h1><?php esc_html_e( 'Interface strings', 'simple-multilang-blocks' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Strings come from the active theme, selected plugins and imported WPML String Translation records. Their existing source text is retained as a fallback; translations apply only to the public site interface and never modify source, PO or MO files.', 'simple-multilang-blocks' ); ?></p>
            <?php if ( isset( $_GET['scanned'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Theme and selected plugin strings were catalogued.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'String translations saved.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['auto_translated'] ) ) : ?><div class="notice notice-success"><p><?php echo esc_html( sprintf( _n( 'Machine-translated %d interface string. It requires review before public use.', 'Machine-translated %d interface strings. They require review before public use.', absint( $_GET['auto_translated'] ), 'simple-multilang-blocks' ), absint( $_GET['auto_translated'] ) ) ); ?><?php if ( ! empty( $_GET['auto_skipped'] ) ) : ?> <?php echo esc_html( sprintf( _n( '%d existing translation was left unchanged.', '%d existing translations were left unchanged.', absint( $_GET['auto_skipped'] ), 'simple-multilang-blocks' ), absint( $_GET['auto_skipped'] ) ) ); ?><?php endif; ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['auto_error'] ) ) : ?><div class="notice notice-warning"><p><?php echo esc_html( self::auto_translate_error_message( sanitize_key( wp_unslash( $_GET['auto_error'] ) ) ) ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['po_imported'] ) ) : ?><div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Imported %d interface-string translations from the PO file.', 'simple-multilang-blocks' ), absint( $_GET['po_imported'] ) ) ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['po_error'] ) ) : ?><div class="notice notice-warning"><p><?php esc_html_e( 'The PO file could not be imported. Select a small valid .po file exported by Simple Multilang.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>

            <div class="sml-toolbar">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'sml_scan_theme_strings' ); ?>
                    <input type="hidden" name="action" value="sml_scan_theme_strings">
                    <button class="button button-secondary"><?php esc_html_e( 'Scan theme and selected plugins', 'simple-multilang-blocks' ); ?></button>
                </form>
                <form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">
                    <input type="hidden" name="page" value="sml-theme-strings">
                    <label class="screen-reader-text" for="sml-string-search"><?php esc_html_e( 'Search strings', 'simple-multilang-blocks' ); ?></label>
                    <input id="sml-string-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search source text', 'simple-multilang-blocks' ); ?>">
                    <label class="screen-reader-text" for="sml-string-source-filter"><?php esc_html_e( 'Filter by source', 'simple-multilang-blocks' ); ?></label>
                    <select id="sml-string-source-filter" name="source">
                        <option value=""><?php esc_html_e( 'All sources', 'simple-multilang-blocks' ); ?></option>
                        <?php foreach ( $sources as $catalogue_source ) : ?>
                            <option value="<?php echo esc_attr( $catalogue_source['context'] ); ?>" <?php selected( $source, $catalogue_source['context'] ); ?>><?php echo esc_html( sprintf( _n( '%1$s (%2$d string)', '%1$s (%2$d strings)', $catalogue_source['count'], 'simple-multilang-blocks' ), $catalogue_source['label'], $catalogue_source['count'] ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button"><?php esc_html_e( 'Search', 'simple-multilang-blocks' ); ?></button>
                </form>
                <form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'sml_export_po' ); ?>
                    <input type="hidden" name="action" value="sml_export_po">
                    <label><span class="screen-reader-text"><?php esc_html_e( 'Export language', 'simple-multilang-blocks' ); ?></span><select name="language"><?php foreach ( $languages as $slug => $language ) : ?><option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $language['name'] ); ?></option><?php endforeach; ?></select></label>
                    <button class="button"><?php esc_html_e( 'Export PO', 'simple-multilang-blocks' ); ?></button>
                </form>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'sml_import_po' ); ?>
                    <input type="hidden" name="action" value="sml_import_po">
                    <label><span class="screen-reader-text"><?php esc_html_e( 'Import language', 'simple-multilang-blocks' ); ?></span><select name="language"><?php foreach ( $languages as $slug => $language ) : ?><option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $language['name'] ); ?></option><?php endforeach; ?></select></label>
                    <input type="file" name="sml_po_file" accept=".po,text/x-gettext-translation">
                    <button class="button"><?php esc_html_e( 'Import PO', 'simple-multilang-blocks' ); ?></button>
                </form>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sml_save_theme_strings' ); ?>
                <input type="hidden" name="action" value="sml_save_theme_strings">
                <input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
                <input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>">
                <input type="hidden" name="paged" value="<?php echo esc_attr( $page ); ?>">
                <table class="widefat fixed striped sml-strings-table">
                    <thead><tr><th><?php esc_html_e( 'Source', 'simple-multilang-blocks' ); ?></th><?php foreach ( $languages as $language ) : ?><th><?php echo esc_html( SML_Core::get_language_flag( $language ) . ' ' . $language['name'] ); ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php if ( ! $rows ) : ?>
                        <tr><td colspan="<?php echo esc_attr( count( $languages ) + 1 ); ?>"><?php esc_html_e( 'No strings yet. Scan the theme and selected plugins first, or visit the relevant public page to capture a known interface string.', 'simple-multilang-blocks' ); ?></td></tr>
                    <?php else : foreach ( $rows as $row ) : ?>
                        <tr>
                            <th scope="row"><code><?php echo esc_html( $row->source_value ); ?></code><p class="description"><span class="sml-string-source"><?php echo esc_html( $row->context ); ?></span><?php if ( false !== strpos( $row->name, "\004" ) ) : ?> · <?php echo esc_html( substr( $row->name, 0, strpos( $row->name, "\004" ) ) ); ?><?php endif; ?></p></th>
                            <?php foreach ( $languages as $slug => $language ) : $value = SML_Core::get_string_translation( $row->id, $slug, '' ); $status = SML_Core::get_string_translation_status( $row->id, $slug ); ?>
                                <td><textarea rows="3" name="sml_strings[<?php echo esc_attr( $row->id ); ?>][<?php echo esc_attr( $slug ); ?>][value]" aria-label="<?php echo esc_attr( $language['name'] ); ?>"><?php echo esc_textarea( $value ); ?></textarea><label><input type="checkbox" name="sml_strings[<?php echo esc_attr( $row->id ); ?>][<?php echo esc_attr( $slug ); ?>][needs_review]" value="1" <?php checked( 'needs_review', $status ); ?>> <?php esc_html_e( 'Requires review', 'simple-multilang-blocks' ); ?></label></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <?php if ( $rows ) : ?><p><button class="button button-primary"><?php esc_html_e( 'Save translations', 'simple-multilang-blocks' ); ?></button></p><?php endif; ?>
            </form>
            <?php if ( $rows ) : $default_language = SML_Core::get_default_language(); $default_target = ''; foreach ( $languages as $language_slug => $language ) { if ( $language_slug !== $default_language ) { $default_target = $language_slug; break; } } ?>
                <form class="sml-string-auto-translate" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'sml_auto_translate_theme_strings' ); ?>
                    <input type="hidden" name="action" value="sml_auto_translate_theme_strings">
                    <input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
                    <input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>">
                    <input type="hidden" name="paged" value="<?php echo esc_attr( $page ); ?>">
                    <?php foreach ( $rows as $row ) : ?><input type="hidden" name="sml_string_ids[]" value="<?php echo esc_attr( $row->id ); ?>"><?php endforeach; ?>
                    <strong><?php esc_html_e( 'Automatic translation', 'simple-multilang-blocks' ); ?></strong>
                    <label><?php esc_html_e( 'Source language', 'simple-multilang-blocks' ); ?> <select name="source_language"><?php foreach ( $languages as $language_slug => $language ) : ?><option value="<?php echo esc_attr( $language_slug ); ?>" <?php selected( $default_language, $language_slug ); ?>><?php echo esc_html( $language['name'] ); ?></option><?php endforeach; ?></select></label>
                    <label><?php esc_html_e( 'Translate to', 'simple-multilang-blocks' ); ?> <select name="target_language"><?php foreach ( $languages as $language_slug => $language ) : ?><option value="<?php echo esc_attr( $language_slug ); ?>" <?php selected( $default_target, $language_slug ); ?>><?php echo esc_html( $language['name'] ); ?></option><?php endforeach; ?></select></label>
                    <button class="button button-secondary" <?php disabled( ! SML_Translation_Service::is_available() ); ?>><?php esc_html_e( 'Auto-translate missing strings (up to 20)', 'simple-multilang-blocks' ); ?></button>
                    <p class="description"><?php esc_html_e( 'Only visible strings without a saved translation are sent. The request is available only to administrators, is protected by a nonce, and each returned value is marked Requires review.', 'simple-multilang-blocks' ); ?><?php if ( ! SML_Translation_Service::is_available() ) : ?> <?php esc_html_e( 'Configure a translation provider and its wp-config.php credential first.', 'simple-multilang-blocks' ); ?><?php endif; ?></p>
                </form>
            <?php endif; ?>
            <?php
            $pages = max( 1, (int) ceil( $total / $per_page ) );
            if ( $pages > 1 ) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( array( 's' => $search, 'source' => $source, 'paged' => '%#%' ), $base_url ), 'format' => '', 'current' => $page, 'total' => $pages ) ) );
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    public function scan_theme_strings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to scan theme strings.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( 'sml_scan_theme_strings' );
        self::scan_selected_sources();
        wp_safe_redirect( add_query_arg( 'scanned', '1', admin_url( 'options-general.php?page=sml-theme-strings' ) ) );
        exit;
    }

    public static function scan_active_theme() {
        $theme = wp_get_theme();
        $domain = (string) $theme->get( 'TextDomain' );
        $directories = array_unique( array_filter( array( get_stylesheet_directory(), get_template_directory() ) ) );
        foreach ( $directories as $directory ) {
            $candidates = glob( trailingslashit( $directory ) . 'languages/*.pot' );
            self::scan_catalogue_files( $candidates, self::string_context( $domain ) );
        }
    }

    /**
     * Returns source contexts that exist in the editable catalogue, with a
     * human-readable label and count for the source filter.
     *
     * @return array<int,array{context:string,label:string,count:int}>
     */
    private static function catalogue_sources() {
        global $wpdb;
        $strings_table = SML_Core::strings_table();
        $rows = $wpdb->get_results( "SELECT context, COUNT(*) AS string_count FROM {$strings_table} GROUP BY context ORDER BY context ASC" );
        $sources = array();
        foreach ( (array) $rows as $row ) {
            $context = isset( $row->context ) ? (string) $row->context : '';
            if ( '' === $context ) {
                continue;
            }
            $sources[] = array(
                'context' => $context,
                'label'   => self::catalogue_source_label( $context ),
                'count'   => absint( $row->string_count ?? 0 ),
            );
        }
        usort( $sources, static function ( $left, $right ) {
            return strcasecmp( $left['label'], $right['label'] );
        } );
        return $sources;
    }

    /** Gives catalogue contexts a readable label without changing their key. */
    private static function catalogue_source_label( $context ) {
        $context = (string) $context;
        if ( 0 === strpos( $context, 'theme:' ) ) {
            $domain = substr( $context, strlen( 'theme:' ) );
            $theme = wp_get_theme();
            $parent = $theme->parent();
            if ( $domain === (string) $theme->get( 'TextDomain' ) ) {
                return sprintf( __( 'Theme: %1$s', 'simple-multilang-blocks' ), $theme->get( 'Name' ) );
            }
            if ( $parent && $domain === (string) $parent->get( 'TextDomain' ) ) {
                return sprintf( __( 'Parent theme: %1$s', 'simple-multilang-blocks' ), $parent->get( 'Name' ) );
            }
            return sprintf( __( 'Theme: %1$s', 'simple-multilang-blocks' ), $domain );
        }
        if ( 0 === strpos( $context, 'plugin:' ) ) {
            $domain = substr( $context, strlen( 'plugin:' ) );
            $plugins = self::available_plugin_domains();
            $name = isset( $plugins[ $domain ]['name'] ) ? $plugins[ $domain ]['name'] : $domain;
            return sprintf( __( 'Plugin: %1$s', 'simple-multilang-blocks' ), $name );
        }
        return sprintf( __( 'Imported: %1$s', 'simple-multilang-blocks' ), $context );
    }

    public static function scan_selected_sources() {
        self::scan_active_theme();
        $available = self::available_plugin_domains();
        foreach ( self::selected_plugin_domains() as $domain ) {
            if ( empty( $available[ $domain ] ) ) {
                continue;
            }
            $plugin_file = $available[ $domain ]['path'];
            $directory = dirname( $plugin_file );
            $candidates = array_merge(
                (array) glob( trailingslashit( $directory ) . 'languages/*.pot' ),
                (array) glob( trailingslashit( $directory ) . '*.pot' )
            );
            self::scan_catalogue_files( array_unique( $candidates ), 'plugin:' . $domain );
        }
    }

    private static function scan_catalogue_files( $files, $context ) {
        foreach ( (array) $files as $file ) {
            foreach ( self::parse_pot( $file ) as $entry ) {
                SML_Core::register_string( $context, self::string_name( $entry['context'], $entry['id'] ), $entry['id'] );
            }
        }
    }

    public function save_theme_strings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to change string translations.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( 'sml_save_theme_strings' );
        $submitted = isset( $_POST['sml_strings'] ) && is_array( $_POST['sml_strings'] ) ? wp_unslash( $_POST['sml_strings'] ) : array();
        $languages = SML_Core::get_languages();
        foreach ( $submitted as $string_id => $translations ) {
            $string_id = absint( $string_id );
            if ( ! $string_id || ! is_array( $translations ) ) {
                continue;
            }
            foreach ( $translations as $language => $entry ) {
                $language = sanitize_key( $language );
                if ( ! isset( $languages[ $language ] ) || ! is_array( $entry ) ) {
                    continue;
                }
                $value = isset( $entry['value'] ) ? wp_kses_post( $entry['value'] ) : '';
                SML_Core::save_string_translation( $string_id, $language, $value, ! empty( $entry['needs_review'] ) ? 'needs_review' : 'verified' );
            }
        }
        $url = add_query_arg( array( 'saved' => '1', 's' => isset( $_POST['s'] ) ? sanitize_text_field( $_POST['s'] ) : '', 'source' => isset( $_POST['source'] ) ? sanitize_text_field( $_POST['source'] ) : '', 'paged' => absint( $_POST['paged'] ?? 1 ) ), admin_url( 'options-general.php?page=sml-theme-strings' ) );
        wp_safe_redirect( $url );
        exit;
    }

    /** Runs an administrator-confirmed, bounded machine-translation batch. */
    public function auto_translate_theme_strings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to automatically translate interface strings.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( 'sml_auto_translate_theme_strings' );
        $source_language = isset( $_POST['source_language'] ) ? sanitize_key( wp_unslash( $_POST['source_language'] ) ) : '';
        $target_language = isset( $_POST['target_language'] ) ? sanitize_key( wp_unslash( $_POST['target_language'] ) ) : '';
        $source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
        $ids = isset( $_POST['sml_string_ids'] ) && is_array( $_POST['sml_string_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['sml_string_ids'] ) ) ) ) ) : array();
        $ids = array_slice( $ids, 0, SML_Translation_Service::INTERFACE_STRING_BATCH_SIZE );
        $return_args = array(
            's'      => isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '',
            'source' => $source,
            'paged'  => max( 1, absint( $_POST['paged'] ?? 1 ) ),
        );
        if ( ! $ids ) {
            $this->redirect_auto_translate_result( array_merge( $return_args, array( 'auto_error' => 'sml_string_batch_empty' ) ) );
        }

        $rows = self::auto_translate_candidates( $ids, $target_language, $source );
        if ( ! $rows ) {
            $this->redirect_auto_translate_result( array_merge( $return_args, array( 'auto_error' => 'sml_string_batch_empty' ) ) );
        }
        $strings = array();
        foreach ( $rows as $row ) {
            $strings[] = array(
                'id'    => absint( $row->id ),
                'value' => (string) $row->source_value,
            );
        }
        $translated = SML_Translation_Service::translate_interface_strings( $strings, $source_language, $target_language );
        if ( is_wp_error( $translated ) ) {
            $this->redirect_auto_translate_result( array_merge( $return_args, array( 'auto_error' => sanitize_key( $translated->get_error_code() ) ) ) );
        }

        $saved = 0;
        foreach ( $translated as $string_id => $value ) {
            if ( '' !== trim( (string) $value ) && SML_Core::save_string_translation( $string_id, $target_language, wp_kses_post( $value ), 'needs_review' ) ) {
                ++$saved;
            }
        }
        if ( ! $saved ) {
            $this->redirect_auto_translate_result( array_merge( $return_args, array( 'auto_error' => 'sml_string_batch_incomplete' ) ) );
        }
        $this->redirect_auto_translate_result( array_merge( $return_args, array( 'auto_translated' => $saved, 'auto_skipped' => max( 0, count( $ids ) - $saved ) ) ) );
    }

    /** Returns at most one safe editor batch, never overwriting saved work. */
    private static function auto_translate_candidates( $ids, $target_language, $source ) {
        global $wpdb;
        $ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) ), 0, SML_Translation_Service::INTERFACE_STRING_BATCH_SIZE );
        $target_language = sanitize_key( $target_language );
        $source = sanitize_text_field( (string) $source );
        if ( ! $ids || ! isset( SML_Core::get_languages()[ $target_language ] ) ) {
            return array();
        }
        $strings = SML_Core::strings_table();
        $translations = SML_Core::string_translations_table();
        $id_placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "SELECT s.id, s.source_value FROM {$strings} s LEFT JOIN {$translations} t ON t.string_id = s.id AND t.language = %s WHERE s.id IN ({$id_placeholders}) AND s.source_value != '' AND (t.id IS NULL OR t.value = '')";
        $args = array_merge( array( $target_language ), $ids );
        if ( '' !== $source ) {
            $sql .= ' AND s.context = %s';
            $args[] = $source;
        }
        $sql .= ' ORDER BY s.id ASC LIMIT ' . SML_Translation_Service::INTERFACE_STRING_BATCH_SIZE;
        return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
    }

    private function redirect_auto_translate_result( $args ) {
        wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php?page=sml-theme-strings' ) ) );
        exit;
    }

    private static function auto_translate_error_message( $code ) {
        $messages = array(
            'sml_provider_unavailable'  => __( 'The translation provider is not configured or is temporarily unavailable. No interface strings were changed.', 'simple-multilang-blocks' ),
            'sml_invalid_language'      => __( 'Choose two different configured languages before translating interface strings.', 'simple-multilang-blocks' ),
            'sml_string_batch_empty'    => __( 'There are no missing interface-string translations in this visible batch.', 'simple-multilang-blocks' ),
            'sml_translation_structure' => __( 'The provider could not preserve protected placeholders or markup. No interface strings were changed.', 'simple-multilang-blocks' ),
        );
        return $messages[ $code ] ?? __( 'The interface-string translation could not be completed. No strings were changed.', 'simple-multilang-blocks' );
    }

    /** Exports the editable catalogue rather than changing a theme's shipped files. */
    public function export_po() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to export string translations.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( 'sml_export_po' );
        $language = isset( $_GET['language'] ) ? sanitize_key( wp_unslash( $_GET['language'] ) ) : '';
        $languages = SML_Core::get_languages();
        if ( ! isset( $languages[ $language ] ) ) {
            wp_die( esc_html__( 'The export language is invalid.', 'simple-multilang-blocks' ) );
        }

        global $wpdb;
        $strings = SML_Core::strings_table();
        $translations = SML_Core::string_translations_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.context, s.name, s.source_value, COALESCE(t.value, '') AS translation, COALESCE(t.status, '') AS status FROM {$strings} s LEFT JOIN {$translations} t ON t.string_id = s.id AND t.language = %s ORDER BY s.id ASC",
                $language
            )
        );

        nocache_headers();
        header( 'Content-Type: text/x-gettext-translation; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="simple-multilang-' . rawurlencode( $language ) . '.po"' );
        echo '# Simple Multilang Blocks interface-string export' . "\n";
        echo 'msgid ""' . "\n";
        echo 'msgstr ""' . "\n";
        echo '"Language: ' . self::po_quote( $languages[ $language ]['code'] ) . '\\n"' . "\n";
        echo '"Content-Type: text/plain; charset=UTF-8\\n"' . "\n";
        echo '"Content-Transfer-Encoding: 8bit\\n"' . "\n\n";
        foreach ( $rows as $row ) {
            $parts = self::split_string_name( $row->name );
            echo '#. sml-context: ' . rawurlencode( (string) $row->context ) . "\n";
            if ( 'needs_review' === $row->status ) {
                echo '#, fuzzy' . "\n";
            }
            if ( '' !== $parts['context'] ) {
                echo 'msgctxt "' . self::po_quote( $parts['context'] ) . '"' . "\n";
            }
            // msgid is the stable gettext key, so an exported PO imports back
            // into the exact same catalogue row even if its visible fallback
            // was changed by a later theme update.
            echo 'msgid "' . self::po_quote( $parts['id'] ) . '"' . "\n";
            echo 'msgstr "' . self::po_quote( $row->translation ) . '"' . "\n\n";
        }
        exit;
    }

    /** Imports only ordinary PO text, never executable files or theme MO files. */
    public function import_po() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to import string translations.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( 'sml_import_po' );
        $language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
        $languages = SML_Core::get_languages();
        $file = isset( $_FILES['sml_po_file'] ) && is_array( $_FILES['sml_po_file'] ) ? $_FILES['sml_po_file'] : array();
        $filename = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
        $tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
        $size = isset( $file['size'] ) ? absint( $file['size'] ) : 0;
        if ( ! isset( $languages[ $language ] ) || empty( $file ) || UPLOAD_ERR_OK !== absint( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! preg_match( '/\.po$/i', $filename ) || $size > 5 * MB_IN_BYTES || ! $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
            $this->redirect_po_import( 0, true );
        }
        $contents = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false === $contents || '' === $contents ) {
            $this->redirect_po_import( 0, true );
        }

        $imported = 0;
        foreach ( self::parse_po( $contents ) as $entry ) {
            if ( '' === $entry['id'] || '' === $entry['translation'] || '' === $entry['sml_context'] ) {
                continue;
            }
            $context = sanitize_text_field( $entry['sml_context'] );
            if ( '' === $context || strlen( $context ) > 191 ) {
                continue;
            }
            $name = self::string_name( $entry['context'], $entry['id'] );
            $string_id = SML_Core::find_string_id( $context, $name );
            if ( ! $string_id ) {
                $string_id = SML_Core::register_string( $context, $name, $entry['id'] );
            }
            if ( $string_id ) {
                SML_Core::save_string_translation( $string_id, $language, wp_kses_post( $entry['translation'] ), ! empty( $entry['fuzzy'] ) ? 'needs_review' : 'verified' );
                ++$imported;
            }
        }
        $this->redirect_po_import( $imported, false );
    }

    private function redirect_po_import( $imported, $error ) {
        $args = $error ? array( 'po_error' => '1' ) : array( 'po_imported' => absint( $imported ) );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php?page=sml-theme-strings' ) ) );
        exit;
    }

    private static function split_string_name( $name ) {
        $name = (string) $name;
        $separator = strpos( $name, "\004" );
        return array(
            'context' => false === $separator ? '' : substr( $name, 0, $separator ),
            'id'      => false === $separator ? $name : substr( $name, $separator + 1 ),
        );
    }

    private static function po_quote( $value ) {
        return str_replace( array( "\\", '"', "\r", "\n" ), array( "\\\\", '\\"', '', '\\n' ), (string) $value );
    }

    /** @return array<int,array{context:string,id:string,translation:string,sml_context:string,fuzzy:bool}> */
    private static function parse_po( $contents ) {
        $entries = array();
        $entry = array( 'context' => '', 'id' => '', 'translation' => '', 'sml_context' => '', 'fuzzy' => false );
        $field = '';
        $flush = static function () use ( &$entries, &$entry, &$field ) {
            if ( '' !== $entry['id'] ) {
                $entries[] = $entry;
            }
            $entry = array( 'context' => '', 'id' => '', 'translation' => '', 'sml_context' => '', 'fuzzy' => false );
            $field = '';
        };
        foreach ( preg_split( '/\R/', (string) $contents ) as $line ) {
            if ( '' === trim( $line ) ) {
                $flush();
                continue;
            }
            if ( 0 === strpos( $line, '#. sml-context:' ) ) {
                $entry['sml_context'] = rawurldecode( trim( substr( $line, strlen( '#. sml-context:' ) ) ) );
                continue;
            }
            if ( 0 === strpos( $line, '#,' ) && false !== strpos( $line, 'fuzzy' ) ) {
                $entry['fuzzy'] = true;
                continue;
            }
            if ( 0 === strpos( $line, '#' ) ) {
                continue;
            }
            if ( preg_match( '/^(msgctxt|msgid|msgstr(?:\[0\])?)\s+"(.*)"$/', $line, $match ) ) {
                if ( 'msgctxt' === $match[1] ) {
                    $field = 'context';
                } elseif ( 'msgid' === $match[1] ) {
                    $field = 'id';
                } else {
                    $field = 'translation';
                }
                $entry[ $field ] = stripcslashes( $match[2] );
                continue;
            }
            if ( $field && preg_match( '/^"(.*)"$/', $line, $match ) ) {
                $entry[ $field ] .= stripcslashes( $match[1] );
            }
        }
        $flush();
        return $entries;
    }

    private static function parse_pot( $file ) {
        $lines = @file( $file, FILE_IGNORE_NEW_LINES );
        if ( ! is_array( $lines ) ) {
            return array();
        }
        $entries = array();
        $entry = array( 'context' => '', 'id' => '', 'plural' => '' );
        $field = '';
        $flush = static function () use ( &$entries, &$entry, &$field ) {
            if ( '' !== $entry['id'] ) {
                $entries[] = $entry;
            }
            $entry = array( 'context' => '', 'id' => '', 'plural' => '' );
            $field = '';
        };
        foreach ( $lines as $line ) {
            if ( '' === trim( $line ) ) {
                $flush();
                continue;
            }
            if ( 0 === strpos( $line, '#' ) ) {
                continue;
            }
            if ( preg_match( '/^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?)\s+"(.*)"$/', $line, $match ) ) {
                $token = $match[1];
                if ( 'msgctxt' === $token ) {
                    $field = 'context';
                } elseif ( 'msgid' === $token ) {
                    $field = 'id';
                } elseif ( 'msgid_plural' === $token ) {
                    $field = 'plural';
                } else {
                    $field = '';
                }
                if ( $field ) {
                    $entry[ $field ] = stripcslashes( $match[2] );
                }
                continue;
            }
            if ( $field && preg_match( '/^"(.*)"$/', $line, $match ) ) {
                $entry[ $field ] .= stripcslashes( $match[1] );
            }
        }
        $flush();
        return $entries;
    }
}
