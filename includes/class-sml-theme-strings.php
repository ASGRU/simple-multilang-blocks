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
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per_page = 20;
        $where = "(context LIKE 'theme:%' OR context LIKE 'plugin:%')";
        $args = array();
        if ( '' !== $search ) {
            $where .= ' AND (name LIKE %s OR source_value LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $args = array( $like, $like );
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
            <p class="description"><?php esc_html_e( 'Strings are sourced from the active theme and the selected plugins. Their existing source text is retained as a fallback; translations are applied only to the public site interface and never modify source, PO or MO files.', 'simple-multilang-blocks' ); ?></p>
            <?php if ( isset( $_GET['scanned'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Theme and selected plugin strings were catalogued.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'String translations saved.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>

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
                    <button class="button"><?php esc_html_e( 'Search', 'simple-multilang-blocks' ); ?></button>
                </form>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sml_save_theme_strings' ); ?>
                <input type="hidden" name="action" value="sml_save_theme_strings">
                <input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
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
            <?php
            $pages = max( 1, (int) ceil( $total / $per_page ) );
            if ( $pages > 1 ) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( array( 's' => $search, 'paged' => '%#%' ), $base_url ), 'format' => '', 'current' => $page, 'total' => $pages ) ) );
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
        $url = add_query_arg( array( 'saved' => '1', 's' => isset( $_POST['s'] ) ? sanitize_text_field( $_POST['s'] ) : '', 'paged' => absint( $_POST['paged'] ?? 1 ) ), admin_url( 'options-general.php?page=sml-theme-strings' ) );
        wp_safe_redirect( $url );
        exit;
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
