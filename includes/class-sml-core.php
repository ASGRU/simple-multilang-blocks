<?php

defined( 'ABSPATH' ) || exit;

final class SML_Core {
    const OPTION_LANGUAGES  = 'sml_languages';
    const OPTION_POST_TYPES = 'sml_post_types';
    const OPTION_TAXONOMIES = 'sml_taxonomies';
    const OPTION_REWRITE_FLUSH = 'sml_flush_rewrite_rules';
    const OPTION_SWITCHER_PLACEMENT = 'sml_switcher_placement';
    const OPTION_SWITCHER_APPEARANCE = 'sml_switcher_appearance';
    const OPTION_SWITCHER_CLASS = 'sml_switcher_class';
    const OPTION_SWITCHER_DESIGN = 'sml_switcher_design';
    const OPTION_DB_VERSION = 'sml_db_version';
    const DB_VERSION = '1.3.0';

    private static $instance;
    private static $request_language = null;
    private static $string_cache = array();

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'register_rewrite_rules' ), 1 );
        add_action( 'admin_init', array( $this, 'maybe_upgrade_database' ) );
        add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
        add_action( 'parse_request', array( $this, 'route_language_request' ) );
        add_action( 'pre_get_posts', array( $this, 'filter_main_query_language' ), 20 );
        add_action( 'woocommerce_product_query', array( $this, 'filter_woocommerce_product_query' ), 20 );

        add_filter( 'post_link', array( $this, 'filter_post_link' ), 20, 2 );
        add_filter( 'page_link', array( $this, 'filter_page_link' ), 20, 3 );
        add_filter( 'post_type_link', array( $this, 'filter_post_type_link' ), 20, 2 );
        add_filter( 'term_link', array( $this, 'filter_term_link' ), 20, 3 );
        add_filter( 'wp_nav_menu_args', array( $this, 'map_navigation_menu' ), 20 );
        add_filter( 'wp_nav_menu_objects', array( $this, 'map_navigation_menu_objects' ), 20, 2 );
        add_filter( 'render_block_data', array( $this, 'map_navigation_block_data' ), 20, 2 );
        add_filter( 'render_block', array( $this, 'hide_unavailable_navigation_block' ), 20, 2 );
        add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ), 20, 2 );
        add_action( 'wp_head', array( $this, 'output_hreflang' ), 1 );
        add_filter( 'redirect_canonical', array( $this, 'prevent_language_canonical_redirect' ), 10, 2 );
        add_action( 'template_redirect', array( $this, 'redirect_noncanonical_language_url' ), 1 );
        add_shortcode( 'sml_language_switcher', array( $this, 'language_switcher_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'wp_body_open', array( $this, 'render_automatic_switcher' ), 20 );
        add_action( 'wp_footer', array( $this, 'render_automatic_switcher_fallback' ), 1 );
        add_action( 'init', array( $this, 'register_blocks' ), 20 );

        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) && ! class_exists( 'SitePress' ) ) {
            add_filter( 'wpml_current_language', array( $this, 'compat_current_language' ) );
            add_filter( 'wpml_object_id', array( $this, 'compat_object_id' ), 10, 5 );
            add_filter( 'wpml_active_languages', array( $this, 'compat_active_languages' ), 10, 2 );
        }

        add_action( 'add_meta_boxes', array( $this, 'register_post_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_post_language' ), 20, 2 );
        add_action( 'init', array( $this, 'register_term_language_fields' ), 20 );

        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_menu', array( $this, 'register_menu_sync_page' ), 30 );
        add_action( 'admin_post_sml_save_settings', array( $this, 'save_settings' ) );
        add_action( 'admin_post_sml_save_menu_group', array( $this, 'save_menu_group' ) );
        add_action( 'admin_post_sml_scan_menu_strings', array( $this, 'scan_menu_strings' ) );
        add_action( 'admin_post_sml_preview_wpml', array( $this, 'preview_wpml' ) );
        add_action( 'admin_post_sml_import_wpml', array( $this, 'import_wpml' ) );
        add_action( 'admin_post_sml_create_term_translation', array( $this, 'create_term_translation' ) );
        add_action( 'admin_post_sml_translate_term', array( $this, 'queue_term_translation' ) );
        add_action( 'admin_post_sml_mark_term_translation_verified', array( $this, 'mark_term_translation_verified' ) );
    }

    public static function activate() {
        self::create_string_tables();
        update_option( self::OPTION_DB_VERSION, self::DB_VERSION );

        if ( ! get_option( self::OPTION_LANGUAGES ) ) {
            update_option( self::OPTION_LANGUAGES, self::default_languages() );
        }

        if ( false === get_option( self::OPTION_POST_TYPES, false ) ) {
            update_option( self::OPTION_POST_TYPES, array( 'post', 'page' ) );
        }

        if ( false === get_option( self::OPTION_TAXONOMIES, false ) ) {
            update_option( self::OPTION_TAXONOMIES, array( 'category', 'post_tag' ) );
        }

        self::instance()->register_rewrite_rules();
        if ( class_exists( 'SML_Theme_Strings' ) ) {
            SML_Theme_Strings::scan_active_theme();
        }
        flush_rewrite_rules();
    }

    public static function deactivate() {
        if ( class_exists( 'SML_Translation_Service' ) ) {
            SML_Translation_Service::clear_scheduled_jobs();
        }
        flush_rewrite_rules();
    }

    public function maybe_upgrade_database() {
        if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) ) {
            return;
        }
        self::create_string_tables();
        update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
    }

    public static function schedule_rewrite_flush() {
        update_option( self::OPTION_REWRITE_FLUSH, 1, false );
    }

    public static function default_languages() {
        return array(
            'en' => array( 'slug' => 'en', 'code' => 'en-US', 'name' => 'English', 'flag' => '🇬🇧', 'is_default' => true ),
            'et' => array( 'slug' => 'et', 'code' => 'et-EE', 'name' => 'Eesti', 'flag' => '🇪🇪', 'is_default' => false ),
            'ru' => array( 'slug' => 'ru', 'code' => 'ru-RU', 'name' => 'Русский', 'flag' => '🇷🇺', 'is_default' => false ),
        );
    }

    public static function get_languages() {
        $languages = get_option( self::OPTION_LANGUAGES, self::default_languages() );
        if ( ! is_array( $languages ) || ! $languages ) {
            return self::default_languages();
        }

        $normalized = array();
        foreach ( $languages as $language ) {
            if ( ! is_array( $language ) ) {
                continue;
            }

            $slug = isset( $language['slug'] ) ? sanitize_key( $language['slug'] ) : '';
            if ( ! $slug ) {
                continue;
            }

            $normalized[ $slug ] = array(
                'slug'       => $slug,
                'code'       => isset( $language['code'] ) ? sanitize_text_field( $language['code'] ) : $slug,
                'name'       => isset( $language['name'] ) ? sanitize_text_field( $language['name'] ) : strtoupper( $slug ),
                'flag'       => isset( $language['flag'] ) ? sanitize_text_field( $language['flag'] ) : self::get_language_flag( $slug ),
                'is_default' => ! empty( $language['is_default'] ),
            );
        }

        return $normalized ? $normalized : self::default_languages();
    }

    public static function get_language_flag( $language ) {
        if ( is_array( $language ) ) {
            if ( ! empty( $language['flag'] ) ) {
                return (string) $language['flag'];
            }
            $language = $language['slug'] ?? '';
        }
        $slug = sanitize_key( $language );
        $fallbacks = array( 'en' => '🇬🇧', 'et' => '🇪🇪', 'ru' => '🇷🇺', 'de' => '🇩🇪', 'fi' => '🇫🇮', 'lv' => '🇱🇻', 'lt' => '🇱🇹', 'sv' => '🇸🇪' );
        return $fallbacks[ $slug ] ?? strtoupper( $slug );
    }

    public static function get_language_flag_html( $language ) {
        $flag = self::get_language_flag( $language );
        if ( wp_http_validate_url( $flag ) ) {
            return '<img class="sml-language-flag-image" src="' . esc_url( $flag ) . '" alt="">';
        }
        return esc_html( $flag );
    }

    public static function get_switcher_appearance() {
        $styles = (array) get_option( self::OPTION_SWITCHER_APPEARANCE, array() );
        $style = isset( $styles[ get_stylesheet() ] ) ? sanitize_key( $styles[ get_stylesheet() ] ) : 'theme';
        return in_array( $style, array( 'theme', 'light', 'dark', 'minimal' ), true ) ? $style : 'theme';
    }

    public static function get_switcher_custom_class() {
        $classes = (array) get_option( self::OPTION_SWITCHER_CLASS, array() );
        return isset( $classes[ get_stylesheet() ] ) ? sanitize_html_class( $classes[ get_stylesheet() ] ) : '';
    }

    /** Per-theme presentation settings. URLs and language relationships stay separate. */
    public static function get_switcher_design() {
        $defaults = array(
            'style'           => 'pills',
            'density'         => 'regular',
            'position'        => 'top-right',
            'show_name'       => true,
            'show_flag'       => true,
            'surface'         => '',
            'foreground'      => '',
            'accent'          => '',
            'active_foreground' => '',
            'border'          => '',
        );
        $all = (array) get_option( self::OPTION_SWITCHER_DESIGN, array() );
        $saved = isset( $all[ get_stylesheet() ] ) && is_array( $all[ get_stylesheet() ] ) ? $all[ get_stylesheet() ] : array();
        $design = wp_parse_args( $saved, $defaults );
        $design = apply_filters( 'sml_language_switcher_design', $design, get_stylesheet() );
        $design = is_array( $design ) ? wp_parse_args( $design, $defaults ) : $defaults;
        $style = sanitize_key( (string) $design['style'] );
        $density = sanitize_key( (string) $design['density'] );
        $position = sanitize_key( (string) $design['position'] );
        $design['style'] = in_array( $style, array( 'pills', 'dropdown', 'list' ), true ) ? $style : $defaults['style'];
        $design['density'] = in_array( $density, array( 'regular', 'compact' ), true ) ? $density : $defaults['density'];
        $design['position'] = in_array( $position, array( 'top-right', 'top-left', 'bottom-right', 'bottom-left' ), true ) ? $position : $defaults['position'];
        $design['show_name'] = ! empty( $design['show_name'] );
        $design['show_flag'] = ! empty( $design['show_flag'] );
        foreach ( array( 'surface', 'foreground', 'accent', 'active_foreground', 'border' ) as $color ) {
            $design[ $color ] = isset( $design[ $color ] ) && is_string( $design[ $color ] ) ? ( sanitize_hex_color( $design[ $color ] ) ?: '' ) : '';
        }
        return $design;
    }

    public static function get_default_language() {
        foreach ( self::get_languages() as $slug => $language ) {
            if ( ! empty( $language['is_default'] ) ) {
                return $slug;
            }
        }

        $languages = self::get_languages();
        return (string) key( $languages );
    }

    public static function get_post_types() {
        $configured = get_option( self::OPTION_POST_TYPES, array( 'post', 'page' ) );
        $available  = get_post_types( array( 'show_ui' => true ), 'names' );
        $result     = array();

        foreach ( (array) $configured as $post_type ) {
            $post_type = sanitize_key( $post_type );
            if ( isset( $available[ $post_type ] ) && 'attachment' !== $post_type ) {
                $result[] = $post_type;
            }
        }

        return array_values( array_unique( $result ) );
    }

    public static function get_taxonomies() {
        $configured = get_option( self::OPTION_TAXONOMIES, array( 'category', 'post_tag' ) );
        $available  = get_taxonomies( array( 'show_ui' => true ), 'names' );
        $result     = array();

        foreach ( (array) $configured as $taxonomy ) {
            $taxonomy = sanitize_key( $taxonomy );
            if ( isset( $available[ $taxonomy ] ) ) {
                $result[] = $taxonomy;
            }
        }

        return array_values( array_unique( $result ) );
    }

    public static function get_current_language() {
        if ( null !== self::$request_language ) {
            return self::$request_language;
        }

        $languages = self::get_languages();
        $candidate = get_query_var( 'sml_language' );
        if ( ! $candidate && isset( $_GET['lang'] ) ) {
            $candidate = sanitize_key( wp_unslash( $_GET['lang'] ) );
        }

        if ( $candidate && isset( $languages[ $candidate ] ) ) {
            self::$request_language = $candidate;
            return $candidate;
        }

        if ( is_singular() ) {
            $post_id = get_queried_object_id();
            $language = $post_id ? get_post_meta( $post_id, '_sml_language', true ) : '';
            if ( $language && isset( $languages[ $language ] ) ) {
                self::$request_language = $language;
                return $language;
            }
        }

        if ( is_tax() || is_category() || is_tag() ) {
            $term = get_queried_object();
            $language = $term && ! is_wp_error( $term ) ? get_term_meta( $term->term_id, '_sml_language', true ) : '';
            if ( $language && isset( $languages[ $language ] ) ) {
                self::$request_language = $language;
                return $language;
            }
        }

        self::$request_language = self::get_default_language();
        return self::$request_language;
    }

    public function register_query_vars( $vars ) {
        $vars[] = 'sml_language';
        $vars[] = 'sml_path';
        $vars[] = 'sml_home';
        return array_unique( $vars );
    }

    public function register_rewrite_rules() {
        $slugs = array_keys( self::get_languages() );
        $default = self::get_default_language();
        $slugs = array_values( array_diff( $slugs, array( $default ) ) );
        if ( ! $slugs ) {
            return;
        }

        $pattern = implode( '|', array_map( 'preg_quote', $slugs ) );
        add_rewrite_rule( '^(' . $pattern . ')/?$', 'index.php?sml_language=$matches[1]&sml_home=1', 'top' );
        add_rewrite_rule( '^(' . $pattern . ')/(.*)/?$', 'index.php?sml_language=$matches[1]&sml_path=$matches[2]', 'top' );
    }

    public function maybe_flush_rewrite_rules() {
        if ( ! get_option( self::OPTION_REWRITE_FLUSH ) ) {
            return;
        }
        delete_option( self::OPTION_REWRITE_FLUSH );
        flush_rewrite_rules( false );
    }

    public function route_language_request( $wp ) {
        $languages = self::get_languages();
        $language = isset( $wp->query_vars['sml_language'] ) ? sanitize_key( $wp->query_vars['sml_language'] ) : '';
        if ( ! $language || ! isset( $languages[ $language ] ) ) {
            return;
        }

        self::$request_language = $language;

        if ( ! empty( $wp->query_vars['sml_home'] ) ) {
            $front_page = (int) get_option( 'page_on_front' );
            if ( $front_page ) {
                $translations = self::get_post_translations( $front_page );
                if ( ! empty( $translations[ $language ] ) && self::is_public_post( $translations[ $language ] ) ) {
                    $front_page = (int) $translations[ $language ];
                }
                $wp->query_vars['page_id'] = $front_page;
            }
            return;
        }

        if ( empty( $wp->query_vars['sml_path'] ) ) {
            return;
        }

        $path = trim( rawurldecode( (string) $wp->query_vars['sml_path'] ), '/' );
        unset( $wp->query_vars['sml_path'] );
        if ( '' === $path ) {
            return;
        }

        $term_route = self::get_term_route( $path, $language );
        if ( $term_route ) {
            $wp->query_vars['taxonomy'] = $term_route['taxonomy'];
            $wp->query_vars['term'] = $term_route['slug'];
            $wp->query_vars[ $term_route['taxonomy'] ] = $term_route['slug'];
            return;
        }

        $post_id = self::find_post_for_path( $path, $language );
        if ( $post_id ) {
            $wp->query_vars['p'] = $post_id;
            $wp->query_vars['post_type'] = get_post_type( $post_id );
            return;
        }

        /* Keep the normal WordPress 404 flow for routes that cannot be resolved. */
        $wp->query_vars['pagename'] = $path;
    }

    private static function get_term_route( $path, $language ) {
        foreach ( self::get_taxonomies() as $taxonomy ) {
            $object = get_taxonomy( $taxonomy );
            if ( ! $object || empty( $object->rewrite ) ) {
                continue;
            }

            $base = is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ? trim( $object->rewrite['slug'], '/' ) : $taxonomy;
            if ( '.' === $base || '' === $base ) {
                if ( false !== strpos( $path, '/' ) ) {
                    continue;
                }
                $slug = $path;
            } else {
                if ( $path === $base || 0 !== strpos( $path, $base . '/' ) ) {
                    continue;
                }
                $slug = basename( $path );
            }
            $term = self::find_term_by_slug( $taxonomy, $slug, $language );
            if ( $term ) {
                return array( 'taxonomy' => $taxonomy, 'slug' => $term->slug );
            }
        }

        return false;
    }

    private static function find_post_for_path( $path, $language ) {
        global $wpdb;

        $post_types = self::get_post_types();
        if ( ! $post_types ) {
            return 0;
        }

        // Resolve a complete hierarchical path first. A basename-only lookup
        // cannot distinguish pages such as /services/print/ and /blog/print/.
        $hierarchical = get_page_by_path( $path, OBJECT, $post_types );
        if ( $hierarchical && self::post_is_visible_in_language( $hierarchical->ID, $language ) ) {
            return (int) $hierarchical->ID;
        }

        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $post_name = sanitize_title( basename( $path ) );
        if ( ! $post_name ) {
            return 0;
        }

        $sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sml_language' LEFT JOIN {$wpdb->postmeta} visible ON visible.post_id = p.ID AND visible.meta_key = '_sml_visible_in' WHERE p.post_name = %s AND ( pm.meta_value = %s OR visible.meta_value = %s ) AND p.post_type IN ({$placeholders}) AND p.post_status IN ('publish', 'private') ORDER BY ( pm.meta_value = %s ) DESC, p.ID ASC LIMIT 1";
        $values = array_merge( array( $post_name, $language, $language ), $post_types, array( $language ) );
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
    }

    private static function post_is_visible_in_language( $post_id, $language ) {
        $post = get_post( $post_id );
        if ( ! $post || ! self::is_public_post( $post->ID ) ) {
            return false;
        }
        return self::get_post_language( $post->ID ) === $language || in_array( $language, get_post_meta( $post->ID, '_sml_visible_in', false ), true );
    }

    private static function is_public_post( $post_id ) {
        return 'publish' === get_post_status( $post_id );
    }

    private static function find_term_by_slug( $taxonomy, $slug, $language ) {
        global $wpdb;

        $sql = "SELECT DISTINCT t.term_id, t.name, t.slug, tt.taxonomy FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id INNER JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id AND tm.meta_key = '_sml_language' LEFT JOIN {$wpdb->termmeta} visible ON visible.term_id = t.term_id AND visible.meta_key = '_sml_visible_in' WHERE tt.taxonomy = %s AND t.slug = %s AND ( tm.meta_value = %s OR visible.meta_value = %s ) ORDER BY ( tm.meta_value = %s ) DESC, t.term_id ASC LIMIT 1";
        $row = $wpdb->get_row( $wpdb->prepare( $sql, $taxonomy, sanitize_title( $slug ), $language, $language, $language ) );
        return $row ? get_term( (int) $row->term_id, $taxonomy ) : false;
    }

    public function filter_main_query_language( $query ) {
        if ( is_admin() || wp_doing_ajax() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) || ! $query->is_main_query() || $query->get( 'p' ) || $query->get( 'page_id' ) ) {
            return;
        }

        $post_types = self::get_post_types();
        if ( ! $post_types ) {
            return;
        }

        $query_post_type = $query->get( 'post_type' );
        if ( $query_post_type && 'any' !== $query_post_type && ! in_array( $query_post_type, $post_types, true ) ) {
            return;
        }

        self::add_language_visibility_to_query( $query, self::get_current_language() );
    }

    /**
     * WooCommerce uses secondary WP_Query instances for related products and
     * blocks. Keep these public product lists in the active language too, while
     * leaving cart, checkout, API and admin operations untouched.
     */
    public function filter_woocommerce_product_query( $query ) {
        if ( is_admin() || wp_doing_ajax() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) || ! $query instanceof WP_Query || $query->is_main_query() ) {
            return;
        }
        self::add_language_visibility_to_query( $query, self::get_current_language() );
    }

    private static function add_language_visibility_to_query( $query, $language ) {
        $language = sanitize_key( $language );
        if ( ! isset( self::get_languages()[ $language ] ) ) {
            return;
        }
        $meta_query = (array) $query->get( 'meta_query' );
        $visibility_clause = array( 'key' => '_sml_visible_in', 'value' => $language );
        if ( $language === self::get_default_language() ) {
            $meta_query[] = array(
                'relation' => 'OR',
                $visibility_clause,
                array( 'key' => '_sml_visible_in', 'compare' => 'NOT EXISTS' ),
            );
        } else {
            $meta_query[] = $visibility_clause;
        }
        $query->set( 'meta_query', $meta_query );
    }

    public function filter_post_link( $url, $post ) {
        return self::add_language_to_url( $url, self::get_post_language( $post->ID ) );
    }

    public function filter_page_link( $url, $post_id ) {
        return self::add_language_to_url( $url, self::get_post_language( $post_id ) );
    }

    public function filter_post_type_link( $url, $post ) {
        return self::add_language_to_url( $url, self::get_post_language( $post->ID ) );
    }

    public function filter_term_link( $url, $term, $taxonomy ) {
        if ( ! $term instanceof WP_Term ) {
            return $url;
        }
        return self::add_language_to_url( $url, self::get_term_language( $term->term_id ) );
    }

    /**
     * Switch a separately translated classic menu before WordPress renders it.
     */
    public function map_navigation_menu( $args ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $args;
        }

        $menu = ! empty( $args['menu'] ) ? wp_get_nav_menu_object( $args['menu'] ) : false;
        if ( ! $menu && ! empty( $args['theme_location'] ) ) {
            $locations = get_nav_menu_locations();
            $menu = ! empty( $locations[ $args['theme_location'] ] ) ? wp_get_nav_menu_object( $locations[ $args['theme_location'] ] ) : false;
        }
        if ( ! $menu || is_wp_error( $menu ) ) {
            return $args;
        }

        $translations = self::get_term_translations( $menu->term_id );
        $target = ! empty( $translations[ self::get_current_language() ] ) ? absint( $translations[ self::get_current_language() ] ) : 0;
        $target_menu = $target ? get_term( $target, 'nav_menu' ) : false;
        if ( $target_menu && ! is_wp_error( $target_menu ) ) {
            $args['menu'] = $target;
        }

        return $args;
    }

    /**
     * A shared WordPress menu can keep its structure while linked objects
     * follow the active language. This also covers legacy WPML menu mappings.
     */
    public function map_navigation_menu_objects( $items, $menu ) {
        if ( ! self::should_map_navigation() ) {
            return $items;
        }

        $language = self::get_current_language();
        $hidden = array();
        foreach ( $items as $item ) {
            if ( 'post_type' === $item->type ) {
                if ( ! $this->map_menu_post_item( $item, $language ) ) {
                    $hidden[ absint( $item->ID ) ] = true;
                }
            } elseif ( 'taxonomy' === $item->type ) {
                if ( ! $this->map_menu_term_item( $item, $language ) ) {
                    $hidden[ absint( $item->ID ) ] = true;
                }
            } elseif ( 'custom' === $item->type ) {
                $item->title = self::menu_item_label_translation( $item, $language );
            }
        }

        return $this->remove_hidden_menu_items( $items, $hidden );
    }

    /**
     * Maps Site Editor navigation blocks as well as classic menus. A linked
     * wp_navigation post is selected first; standalone link blocks are then
     * mapped in the same way as classic menu items.
     */
    public function map_navigation_block_data( $parsed_block, $source_block ) {
        if ( ! self::should_map_navigation() || ! is_array( $parsed_block ) || empty( $parsed_block['blockName'] ) ) {
            return $parsed_block;
        }

        $name = (string) $parsed_block['blockName'];
        $attributes = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();
        $language = self::get_current_language();

        if ( 'core/navigation' === $name && ! empty( $attributes['ref'] ) ) {
            $translations = self::get_post_translations( absint( $attributes['ref'] ) );
            $target = ! empty( $translations[ $language ] ) ? absint( $translations[ $language ] ) : 0;
            if ( $target && 'wp_navigation' === get_post_type( $target ) && self::is_public_post( $target ) ) {
                $attributes['ref'] = $target;
            }
        } elseif ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
            $attributes = $this->map_navigation_link_attributes( $attributes, $language );
        }

        $parsed_block['attrs'] = $attributes;
        return $parsed_block;
    }

    /** Hides an unavailable navigation link without leaving a wrong-language URL. */
    public function hide_unavailable_navigation_block( $block_content, $block ) {
        if ( self::should_map_navigation() && ! empty( $block['attrs']['_sml_hidden'] ) ) {
            return '';
        }
        return $block_content;
    }

    private static function should_map_navigation() {
        return ! ( is_admin() && ! wp_doing_ajax() ) && ! ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() );
    }

    /** @return bool Whether the classic menu item remains available. */
    private function map_menu_post_item( $item, $language ) {
        $source_id = absint( $item->object_id );
        $source = $source_id ? get_post( $source_id ) : false;
        if ( ! $source || ! self::is_public_post( $source_id ) ) {
            return false;
        }
        $source_label = sanitize_text_field( wp_strip_all_tags( get_the_title( $source ) ) );
        $is_custom_label = self::menu_item_has_custom_label( $item->title, $source_label, $item->type );
        $translations = self::get_post_translations( $source_id );
        $target = ! empty( $translations[ $language ] ) ? absint( $translations[ $language ] ) : 0;
        if ( $target && self::is_public_post( $target ) ) {
            $item->object_id = $target;
            $item->url = get_permalink( $target );
            $item->title = $is_custom_label ? self::menu_item_label_translation( $item, $language ) : sanitize_text_field( wp_strip_all_tags( get_the_title( $target ) ) );
            return true;
        }
        if ( ! self::post_is_visible_in_language( $source_id, $language ) ) {
            return false;
        }
        $item->url = self::add_language_to_url( get_permalink( $source_id ), $language );
        if ( $is_custom_label ) {
            $item->title = self::menu_item_label_translation( $item, $language );
        }
        return true;
    }

    /** @return bool Whether the classic menu item remains available. */
    private function map_menu_term_item( $item, $language ) {
        $source_id = absint( $item->object_id );
        $source = $source_id ? get_term( $source_id, $item->object ) : false;
        if ( ! $source || is_wp_error( $source ) ) {
            return false;
        }
        $source_label = sanitize_text_field( wp_strip_all_tags( $source->name ) );
        $is_custom_label = self::menu_item_has_custom_label( $item->title, $source_label, $item->type );
        $translations = self::get_term_translations( $source_id );
        $target = ! empty( $translations[ $language ] ) ? absint( $translations[ $language ] ) : 0;
        $term = $target ? get_term( $target, $item->object ) : false;
        if ( $term && ! is_wp_error( $term ) ) {
            $item->object_id = $target;
            $item->url = get_term_link( $term );
            $item->title = $is_custom_label ? self::menu_item_label_translation( $item, $language ) : sanitize_text_field( wp_strip_all_tags( $term->name ) );
            return true;
        }
        if ( ! self::term_is_visible_in_language( $source_id, $language ) ) {
            return false;
        }
        $item->url = self::add_language_to_url( get_term_link( $source ), $language );
        if ( $is_custom_label ) {
            $item->title = self::menu_item_label_translation( $item, $language );
        }
        return true;
    }

    private static function term_is_visible_in_language( $term_id, $language ) {
        return self::get_term_language( $term_id ) === $language || in_array( $language, get_term_meta( $term_id, '_sml_visible_in', false ), true );
    }

    private static function menu_item_has_custom_label( $title, $source_label, $item_type ) {
        if ( 'custom' === $item_type ) {
            return true;
        }
        return '' !== trim( wp_strip_all_tags( (string) $title ) ) && trim( wp_strip_all_tags( (string) $title ) ) !== trim( wp_strip_all_tags( (string) $source_label ) );
    }

    private static function menu_item_label_translation( $item, $language ) {
        $fallback = sanitize_text_field( wp_strip_all_tags( (string) $item->title ) );
        $string_id = self::find_string_id( 'menu-item', 'item:' . absint( $item->ID ) );
        return $string_id ? sanitize_text_field( wp_strip_all_tags( self::get_string_translation( $string_id, $language, $fallback ) ) ) : $fallback;
    }

    private function remove_hidden_menu_items( $items, $hidden ) {
        do {
            $changed = false;
            foreach ( $items as $item ) {
                $item_id = absint( $item->ID );
                $parent_id = absint( $item->menu_item_parent );
                if ( ! isset( $hidden[ $item_id ] ) && $parent_id && isset( $hidden[ $parent_id ] ) ) {
                    $hidden[ $item_id ] = true;
                    $changed = true;
                }
            }
        } while ( $changed );

        return array_values( array_filter( $items, static function ( $item ) use ( $hidden ) {
            return ! isset( $hidden[ absint( $item->ID ) ] );
        } ) );
    }

    private function map_navigation_link_attributes( $attributes, $language ) {
        $source_attributes = $attributes;
        $kind = isset( $attributes['kind'] ) ? sanitize_key( $attributes['kind'] ) : '';
        $source_id = isset( $attributes['id'] ) ? absint( $attributes['id'] ) : 0;
        if ( 'custom' === $kind && ! empty( $attributes['label'] ) ) {
            $attributes['label'] = self::navigation_block_label_translation( $source_attributes, $language, $attributes['label'] );
            return $attributes;
        }
        if ( ! $source_id || ! in_array( $kind, array( 'post-type', 'taxonomy' ), true ) ) {
            return $attributes;
        }

        $source_label = '';
        $target_label = '';
        $target_id = 0;
        $fallback_url = '';
        $visible = false;
        if ( 'post-type' === $kind ) {
            $source = get_post( $source_id );
            if ( ! $source || ! self::is_public_post( $source_id ) ) {
                $attributes['_sml_hidden'] = true;
                return $attributes;
            }
            $source_label = sanitize_text_field( wp_strip_all_tags( get_the_title( $source ) ) );
            $translations = self::get_post_translations( $source_id );
            $target_id = ! empty( $translations[ $language ] ) ? absint( $translations[ $language ] ) : 0;
            if ( $target_id && self::is_public_post( $target_id ) ) {
                $target_label = sanitize_text_field( wp_strip_all_tags( get_the_title( $target_id ) ) );
                $fallback_url = get_permalink( $target_id );
            } else {
                $target_id = 0;
                $visible = self::post_is_visible_in_language( $source_id, $language );
                $fallback_url = self::add_language_to_url( get_permalink( $source_id ), $language );
            }
        } else {
            $taxonomy = isset( $attributes['type'] ) ? sanitize_key( $attributes['type'] ) : '';
            $source = $taxonomy ? get_term( $source_id, $taxonomy ) : false;
            if ( ! $source || is_wp_error( $source ) ) {
                $attributes['_sml_hidden'] = true;
                return $attributes;
            }
            $source_label = sanitize_text_field( wp_strip_all_tags( $source->name ) );
            $translations = self::get_term_translations( $source_id );
            $target_id = ! empty( $translations[ $language ] ) ? absint( $translations[ $language ] ) : 0;
            $target = $target_id ? get_term( $target_id, $taxonomy ) : false;
            if ( $target && ! is_wp_error( $target ) ) {
                $target_label = sanitize_text_field( wp_strip_all_tags( $target->name ) );
                $fallback_url = get_term_link( $target );
            } else {
                $target_id = 0;
                $visible = self::term_is_visible_in_language( $source_id, $language );
                $fallback_url = self::add_language_to_url( get_term_link( $source ), $language );
            }
        }

        if ( ! $target_id && ! $visible ) {
            $attributes['_sml_hidden'] = true;
            return $attributes;
        }

        $label = isset( $attributes['label'] ) ? (string) $attributes['label'] : '';
        $custom_label = self::menu_item_has_custom_label( $label, $source_label, 'post-type' === $kind ? 'post_type' : 'taxonomy' );
        if ( $target_id ) {
            $attributes['id'] = $target_id;
        }
        $attributes['url'] = $fallback_url;
        if ( ! $custom_label && '' !== $target_label ) {
            $attributes['label'] = $target_label;
        } elseif ( $custom_label ) {
            $attributes['label'] = self::navigation_block_label_translation( $source_attributes, $language, $label );
        }
        return $attributes;
    }

    private static function navigation_block_label_translation( $attributes, $language, $fallback ) {
        $fallback = sanitize_text_field( wp_strip_all_tags( (string) $fallback ) );
        $string_id = self::find_string_id( 'navigation-block', self::navigation_block_string_name( $attributes ) );
        return $string_id ? sanitize_text_field( wp_strip_all_tags( self::get_string_translation( $string_id, $language, $fallback ) ) ) : $fallback;
    }

    private static function navigation_block_string_name( $attributes ) {
        $identity = array(
            'id'    => absint( $attributes['id'] ?? 0 ),
            'kind'  => sanitize_key( $attributes['kind'] ?? '' ),
            'type'  => sanitize_key( $attributes['type'] ?? '' ),
            'url'   => esc_url_raw( $attributes['url'] ?? '' ),
            'label' => sanitize_text_field( wp_strip_all_tags( $attributes['label'] ?? '' ) ),
        );
        return 'link:' . hash( 'sha256', wp_json_encode( $identity ) );
    }

    private static function add_language_to_url( $url, $language ) {
        $url = str_replace( '/./', '/', $url );
        $languages = self::get_languages();
        if ( ! $language || ! isset( $languages[ $language ] ) ) {
            return $url;
        }

        if ( '' === get_option( 'permalink_structure' ) ) {
            return add_query_arg( 'lang', $language, $url );
        }

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
            return $url;
        }

        $home_parts = wp_parse_url( home_url( '/' ) );
        $home_path = isset( $home_parts['path'] ) ? trailingslashit( $home_parts['path'] ) : '/';
        $path = '/' . ltrim( $parts['path'], '/' );
        if ( '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
            $path = '/' . ltrim( substr( $path, strlen( $home_path ) ), '/' );
        }

        foreach ( array_keys( $languages ) as $known_language ) {
            if ( preg_match( '#^/' . preg_quote( $known_language, '#' ) . '(?:/|$)#', $path ) ) {
                $path = '/' . ltrim( substr( $path, strlen( $known_language ) + 1 ), '/' );
                break;
            }
        }

        $prefix = $language === self::get_default_language() ? '' : rawurlencode( $language ) . '/';
        $localized = home_url( '/' . $prefix . ltrim( $path, '/' ) );
        if ( ! empty( $parts['query'] ) ) {
            $localized .= '?' . $parts['query'];
        }
        if ( ! empty( $parts['fragment'] ) ) {
            $localized .= '#' . $parts['fragment'];
        }

        return $localized;
    }

    public function filter_language_attributes( $attributes ) {
        $languages = self::get_languages();
        $language = self::get_current_language();
        if ( empty( $languages[ $language ] ) ) {
            return $attributes;
        }

        $code = $languages[ $language ]['code'];
        $direction = preg_match( '/^(ar|fa|he|ur)(-|$)/i', $code ) ? 'rtl' : 'ltr';
        return sprintf( 'lang="%s" dir="%s"', esc_attr( $code ), esc_attr( $direction ) );
    }

    public function prevent_language_canonical_redirect( $redirect_url, $requested_url ) {
        if ( get_query_var( 'sml_language' ) ) {
            return false;
        }

        $path = wp_parse_url( $requested_url, PHP_URL_PATH );
        if ( ! is_string( $path ) || '' === $path ) {
            return $redirect_url;
        }

        $home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        if ( '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
            $path = substr( $path, strlen( $home_path ) );
        }

        $slug = sanitize_key( strtok( trim( $path, '/' ), '/' ) );
        $languages = self::get_languages();
        if ( $slug && isset( $languages[ $slug ] ) && $slug !== self::get_default_language() ) {
            return false;
        }

        return $redirect_url;
    }

    public function redirect_noncanonical_language_url() {
        if ( is_admin() || wp_doing_ajax() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
            return;
        }
        if ( get_query_var( 'sml_language' ) || isset( $_GET['lang'] ) ) {
            return;
        }
        if ( is_singular() ) {
            $post_id = get_queried_object_id();
            $post = $post_id ? get_post( $post_id ) : false;
            if ( $post && in_array( $post->post_type, self::get_post_types(), true ) && self::get_post_language( $post_id ) !== self::get_default_language() ) {
                wp_safe_redirect( get_permalink( $post_id ), 301 );
                exit;
            }
        }
        if ( is_tax() || is_category() || is_tag() ) {
            $term = get_queried_object();
            if ( $term && ! is_wp_error( $term ) && in_array( $term->taxonomy, self::get_taxonomies(), true ) && self::get_term_language( $term->term_id ) !== self::get_default_language() ) {
                wp_safe_redirect( get_term_link( $term ), 301 );
                exit;
            }
        }
    }

    public function output_hreflang() {
        $translations = array();
        if ( is_singular() ) {
            $post_id = get_queried_object_id();
            $translations = self::get_post_translations( $post_id );
            if ( ! $translations ) {
                $translations[ self::get_post_language( $post_id ) ] = $post_id;
            }
            foreach ( $translations as $language => $post_id ) {
                if ( self::is_public_post( $post_id ) ) {
                    $this->print_hreflang( $language, get_permalink( $post_id ) );
                }
            }
            return;
        }

        if ( is_tax() || is_category() || is_tag() ) {
            $term = get_queried_object();
            if ( ! $term || is_wp_error( $term ) ) {
                return;
            }
            $translations = self::get_term_translations( $term->term_id );
            if ( ! $translations ) {
                $translations[ self::get_term_language( $term->term_id ) ] = $term->term_id;
            }
            foreach ( $translations as $language => $term_id ) {
                $translated_term = get_term( $term_id, $term->taxonomy );
                if ( $translated_term && ! is_wp_error( $translated_term ) ) {
                    $this->print_hreflang( $language, get_term_link( $translated_term ) );
                }
            }
        }
    }

    private function print_hreflang( $language, $url ) {
        $languages = self::get_languages();
        if ( ! isset( $languages[ $language ] ) || is_wp_error( $url ) ) {
            return;
        }
        printf( "<link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n", esc_attr( $languages[ $language ]['code'] ), esc_url( $url ) );
    }

    public function language_switcher_shortcode( $atts ) {
        $defaults = array( 'class' => '', 'style' => '', 'appearance' => '', 'show_name' => '', 'show_flag' => '', 'density' => '' );
        $atts = shortcode_atts( $defaults, $atts, 'sml_language_switcher' );
        $current = self::get_current_language();
        $context = array(
            'current_language' => $current,
            'queried_object_id' => absint( get_queried_object_id() ),
            'is_singular'      => is_singular(),
            'is_taxonomy'      => is_tax() || is_category() || is_tag(),
        );
        $filtered_atts = apply_filters( 'sml_language_switcher_args', $atts, $context );
        $atts = is_array( $filtered_atts ) ? wp_parse_args( $filtered_atts, $defaults ) : $atts;
        $design = self::get_switcher_design();
        $style = '' === (string) $atts['style'] ? $design['style'] : sanitize_key( $atts['style'] );
        $style = in_array( $style, array( 'dropdown', 'pills', 'list' ), true ) ? $style : $design['style'];
        $appearance = '' === (string) $atts['appearance'] ? self::get_switcher_appearance() : sanitize_key( $atts['appearance'] );
        $appearance = in_array( $appearance, array( 'theme', 'light', 'dark', 'minimal' ), true ) ? $appearance : self::get_switcher_appearance();
        $density = '' === (string) $atts['density'] ? $design['density'] : sanitize_key( $atts['density'] );
        $density = in_array( $density, array( 'regular', 'compact' ), true ) ? $density : $design['density'];
        $show_name = '' === (string) $atts['show_name'] ? $design['show_name'] : '0' !== (string) $atts['show_name'];
        $show_flag = '' === (string) $atts['show_flag'] ? $design['show_flag'] : '0' !== (string) $atts['show_flag'];
        $links = apply_filters( 'sml_language_switcher_links', $this->language_switcher_links(), $context, $atts );

        if ( ! is_array( $links ) || ! $links ) {
            return '';
        }

        $languages = apply_filters( 'sml_language_switcher_languages', self::get_languages(), $context, $atts );
        $classes = apply_filters(
            'sml_language_switcher_classes',
            array( 'sml-language-switcher', 'sml-language-switcher--' . $style, 'sml-language-switcher--' . $density, self::get_switcher_custom_class(), sanitize_html_class( $atts['class'] ) ),
            $atts,
            $context
        );
        $classes = array_values( array_filter( array_map( 'sanitize_html_class', (array) $classes ) ) );
        $css_variables = self::switcher_css_variables( $design, $atts, $context );
        $inline_style = '';
        foreach ( $css_variables as $name => $color ) {
            if ( ! is_string( $name ) || ! is_string( $color ) || 0 !== strpos( $name, '--sml-' ) ) {
                continue;
            }
            $color = sanitize_hex_color( $color );
            if ( $color ) {
                $inline_style .= $name . ':' . $color . ';';
            }
        }
        $html = '<nav class="' . esc_attr( implode( ' ', $classes ) ) . '" data-sml-appearance="' . esc_attr( $appearance ) . '" data-sml-density="' . esc_attr( $density ) . '"' . ( $inline_style ? ' style="' . esc_attr( $inline_style ) . '"' : '' ) . ' aria-label="' . esc_attr__( 'Language selector', 'simple-multilang-blocks' ) . '"><ul>';
        $items = array();
        foreach ( (array) $languages as $language => $data ) {
            $language = sanitize_key( (string) $language );
            if ( ! isset( self::get_languages()[ $language ], $links[ $language ] ) || ! is_array( $data ) ) {
                continue;
            }
            $item = apply_filters(
                'sml_language_switcher_item',
                array(
                    'language'   => $language,
                    'name'       => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : self::get_languages()[ $language ]['name'],
                    'flag'       => isset( $data['flag'] ) ? sanitize_text_field( $data['flag'] ) : self::get_language_flag( $language ),
                    'url'        => $links[ $language ],
                    'is_current' => $language === $current,
                ),
                $context,
                $atts
            );
            if ( ! is_array( $item ) || empty( $item['url'] ) || ! is_string( $item['url'] ) ) {
                continue;
            }
            $url = esc_url_raw( $item['url'] );
            if ( ! $url ) {
                continue;
            }
            $item['name'] = isset( $item['name'] ) && is_scalar( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
            $item['flag'] = isset( $item['flag'] ) && is_scalar( $item['flag'] ) ? sanitize_text_field( (string) $item['flag'] ) : '';
            $item['is_current'] = ! empty( $item['is_current'] );
            $label = '';
            if ( $show_flag && '' !== $item['flag'] ) {
                $label .= '<span class="sml-language-switcher__flag" aria-hidden="true">' . self::get_language_flag_html( array( 'flag' => $item['flag'], 'slug' => $language ) ) . '</span>';
            }
            if ( $show_name && '' !== $item['name'] ) {
                $label .= '<span class="sml-language-switcher__name">' . esc_html( $item['name'] ) . '</span>';
            }
            if ( '' === $label ) {
                $label = '<span class="screen-reader-text">' . esc_html( self::get_languages()[ $language ]['name'] ) . '</span>';
            }
            $code = self::get_languages()[ $language ]['code'];
            $html .= sprintf( '<li%s><a href="%s" hreflang="%s" lang="%s" aria-current="%s">%s</a></li>', $item['is_current'] ? ' class="is-active"' : '', esc_url( $url ), esc_attr( $code ), esc_attr( $code ), $item['is_current'] ? 'true' : 'false', $label );
            $items[] = $item;
        }
        if ( ! $items ) {
            return '';
        }
        $html .= '</ul></nav>';
        $html = apply_filters( 'sml_language_switcher_html', $html, $items, $atts, $context );
        return is_string( $html ) ? $html : '';
    }

    private function language_switcher_links() {
        $links = array();
        if ( is_singular() ) {
            $post_id = get_queried_object_id();
            $translations = self::get_post_translations( $post_id );
            foreach ( self::get_languages() as $language => $data ) {
                $translated_post_id = ! empty( $translations[ $language ] ) ? absint( $translations[ $language ] ) : 0;
                $links[ $language ] = $translated_post_id && self::is_public_post( $translated_post_id ) ? get_permalink( $translated_post_id ) : self::add_language_to_url( get_permalink( $post_id ), $language );
            }
        } elseif ( is_tax() || is_category() || is_tag() ) {
            $term = get_queried_object();
            if ( $term && ! is_wp_error( $term ) ) {
                $translations = self::get_term_translations( $term->term_id );
                foreach ( self::get_languages() as $language => $data ) {
                    $translated_term_id = ! empty( $translations[ $language ] ) ? absint( $translations[ $language ] ) : 0;
                    $translated = $translated_term_id ? get_term( $translated_term_id, $term->taxonomy ) : false;
                    $links[ $language ] = $translated && ! is_wp_error( $translated ) ? get_term_link( $translated ) : self::add_language_to_url( get_term_link( $term ), $language );
                }
            }
        } else {
            foreach ( self::get_languages() as $language => $data ) {
                $links[ $language ] = self::add_language_to_url( home_url( '/' ), $language );
            }
        }
        return $links;
    }

    private static function switcher_css_variables( $design, $atts, $context ) {
        $variables = array(
            '--sml-surface'    => $design['surface'],
            '--sml-foreground' => $design['foreground'],
            '--sml-accent'     => $design['accent'],
            '--sml-active-foreground' => $design['active_foreground'],
            '--sml-border'     => $design['border'],
        );
        return (array) apply_filters( 'sml_language_switcher_css_variables', $variables, $atts, $context );
    }

    public function enqueue_frontend_assets() {
        wp_enqueue_style( 'simple-multilang-blocks', plugins_url( 'assets/css/sml-frontend.css', SML_FILE ), array(), SML_VERSION );
    }

    /** Makes the switcher available in the Site Editor without theme code. */
    public function register_blocks() {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }
        wp_register_style( 'simple-multilang-blocks-editor', plugins_url( 'assets/css/sml-frontend.css', SML_FILE ), array(), SML_VERSION );
        register_block_type(
            SML_DIR . 'blocks/language-switcher',
            array( 'render_callback' => array( $this, 'render_language_switcher_block' ) )
        );
    }

    public function render_language_switcher_block( $attributes ) {
        $attributes = is_array( $attributes ) ? $attributes : array();
        return $this->language_switcher_shortcode(
            array(
                'style'      => ! empty( $attributes['style'] ) ? sanitize_key( $attributes['style'] ) : '',
                'appearance' => isset( $attributes['appearance'] ) ? sanitize_key( $attributes['appearance'] ) : '',
                'show_name'  => array_key_exists( 'showName', $attributes ) ? ( empty( $attributes['showName'] ) ? '0' : '1' ) : '',
                'show_flag'  => array_key_exists( 'showFlag', $attributes ) ? ( empty( $attributes['showFlag'] ) ? '0' : '1' ) : '',
                'density'    => isset( $attributes['density'] ) ? sanitize_key( $attributes['density'] ) : '',
                'class'      => isset( $attributes['className'] ) ? sanitize_html_class( $attributes['className'] ) : '',
            )
        );
    }

    public function render_automatic_switcher() {
        if ( 'automatic' !== get_option( self::OPTION_SWITCHER_PLACEMENT, 'automatic' ) || is_admin() ) {
            return;
        }
        echo $this->automatic_switcher_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function render_automatic_switcher_fallback() {
        if ( 'automatic' !== get_option( self::OPTION_SWITCHER_PLACEMENT, 'automatic' ) || did_action( 'wp_body_open' ) || is_admin() ) {
            return;
        }
        echo $this->automatic_switcher_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function automatic_switcher_markup() {
        $design = self::get_switcher_design();
        $switcher = $this->language_switcher_shortcode(
            array(
                'style'      => $design['style'],
                'show_name'  => $design['show_name'] ? '1' : '0',
                'show_flag'  => $design['show_flag'] ? '1' : '0',
                'density'    => $design['density'],
            )
        );
        if ( ! $switcher ) {
            return '';
        }
        $markup = '<div class="sml-switcher-slot sml-switcher-slot--' . esc_attr( $design['position'] ) . '">' . $switcher . '</div>';
        $markup = apply_filters( 'sml_automatic_language_switcher_html', $markup, $design );
        return is_string( $markup ) ? $markup : '';
    }

    public function compat_current_language( $value = null ) {
        return self::get_current_language();
    }

    /** Compatibility data for themes that use WPML's public language-list filter. */
    public function compat_active_languages( $value = null, $args = '' ) {
        $current = self::get_current_language();
        $links = $this->language_switcher_links();
        $result = array();
        foreach ( self::get_languages() as $language => $data ) {
            if ( empty( $links[ $language ] ) ) {
                continue;
            }
            $result[ $language ] = array(
                'code'            => $language,
                'id'              => $language,
                'active'          => $language === $current ? 1 : 0,
                'default_locale'  => $data['code'],
                'native_name'     => $data['name'],
                'translated_name' => $data['name'],
                'url'             => $links[ $language ],
                'country_flag_url'=> wp_http_validate_url( self::get_language_flag( $data ) ) ? self::get_language_flag( $data ) : '',
            );
        }
        return apply_filters( 'sml_wpml_active_languages', $result, $args );
    }

    public function compat_object_id( $object_id, $object_type = '', $return_original_if_missing = true, $language_code = null ) {
        $object_id = absint( $object_id );
        $target = $language_code ? sanitize_key( $language_code ) : self::get_current_language();
        if ( ! $object_id || ! isset( self::get_languages()[ $target ] ) ) {
            return $return_original_if_missing ? $object_id : null;
        }
        $term_types = array_merge( array( 'nav_menu' ), self::get_taxonomies() );
        $translations = in_array( $object_type, $term_types, true ) ? self::get_term_translations( $object_id ) : self::get_post_translations( $object_id );
        if ( ! empty( $translations[ $target ] ) ) {
            return absint( $translations[ $target ] );
        }
        return $return_original_if_missing ? $object_id : null;
    }

    public function register_post_meta_boxes() {
        foreach ( self::get_post_types() as $post_type ) {
            add_meta_box( 'sml-language', __( 'Language & translations', 'simple-multilang-blocks' ), array( $this, 'render_post_meta_box' ), $post_type, 'side' );
        }
    }

    public function render_post_meta_box( $post ) {
        $languages = self::get_languages();
        $current = self::get_post_language( $post->ID );
        $translations = self::get_post_translations( $post->ID );
        wp_nonce_field( 'sml_save_post_language_' . $post->ID, 'sml_post_language_nonce' );
        ?>
        <p><label for="sml-language"><strong><?php esc_html_e( 'Language', 'simple-multilang-blocks' ); ?></strong></label><br>
        <select id="sml-language" name="sml_language" class="widefat">
            <?php foreach ( $languages as $slug => $data ) : ?>
                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>><?php echo esc_html( $data['name'] ); ?></option>
            <?php endforeach; ?>
        </select></p>
        <p class="description"><?php esc_html_e( 'Create a linked draft for a language, then edit and publish it only after verification. Machine translations are always created as drafts marked “Requires review”.', 'simple-multilang-blocks' ); ?></p>
        <?php foreach ( $languages as $slug => $data ) : ?>
            <p class="sml-editor-translation-row"><strong><?php echo esc_html( self::get_language_flag( $data ) . ' ' . $data['name'] ); ?></strong><br>
            <?php if ( ! empty( $translations[ $slug ] ) ) : $translation_id = absint( $translations[ $slug ] ); ?>
                <a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $translation_id, '' ) ); ?>"><?php esc_html_e( 'Edit translation', 'simple-multilang-blocks' ); ?></a>
                <?php if ( 'needs_review' === get_post_meta( $translation_id, '_sml_translation_status', true ) ) : ?><span class="sml-review-badge"><?php esc_html_e( 'Requires review', 'simple-multilang-blocks' ); ?></span><?php endif; ?>
            <?php elseif ( $slug !== $current ) :
                $manual_url = wp_nonce_url( add_query_arg( array( 'action' => 'sml_create_translation', 'post' => $post->ID, 'lang' => $slug ), admin_url( 'admin-post.php' ) ), 'sml_create_translation_' . $post->ID . '_' . $slug );
                $machine_url = wp_nonce_url( add_query_arg( array( 'action' => 'sml_translate_post', 'post' => $post->ID, 'lang' => $slug ), admin_url( 'admin-post.php' ) ), 'sml_translate_post_' . $post->ID . '_' . $slug );
                $job = class_exists( 'SML_Translation_Service' ) ? SML_Translation_Service::get_job( $post->ID, $slug ) : array();
            ?>
                <a class="button button-small" href="<?php echo esc_url( $manual_url ); ?>"><?php esc_html_e( 'Create draft', 'simple-multilang-blocks' ); ?></a>
                <?php if ( $job && in_array( $job['status'], array( 'queued', 'processing', 'retry' ), true ) ) : ?>
                    <span class="sml-review-badge"><?php esc_html_e( 'Automatic translation queued', 'simple-multilang-blocks' ); ?></span>
                <?php else : ?>
                    <a class="button button-small button-secondary" href="<?php echo esc_url( $machine_url ); ?>"><?php esc_html_e( 'Queue automatic translation', 'simple-multilang-blocks' ); ?></a>
                <?php endif; ?>
            <?php endif; ?></p>
        <?php endforeach; ?>
        <details><summary><?php esc_html_e( 'Link an existing translation by ID', 'simple-multilang-blocks' ); ?></summary><p class="description"><?php esc_html_e( 'Use this only when the translation already exists. IDs must have the same content type.', 'simple-multilang-blocks' ); ?></p><?php foreach ( $languages as $slug => $data ) : ?><p><label><?php echo esc_html( $data['name'] ); ?><input class="widefat" min="1" type="number" name="sml_translations[<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( isset( $translations[ $slug ] ) ? (int) $translations[ $slug ] : '' ); ?>"></label></p><?php endforeach; ?></details>
        <?php
    }

    public function save_post_language( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! in_array( $post->post_type, self::get_post_types(), true ) ) {
            return;
        }
        if ( empty( $_POST['sml_post_language_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sml_post_language_nonce'] ) ), 'sml_save_post_language_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $languages = self::get_languages();
        $language = isset( $_POST['sml_language'] ) ? sanitize_key( wp_unslash( $_POST['sml_language'] ) ) : self::get_default_language();
        if ( ! isset( $languages[ $language ] ) ) {
            $language = self::get_default_language();
        }

        $translations = array( $language => $post_id );
        $submitted = isset( $_POST['sml_translations'] ) && is_array( $_POST['sml_translations'] ) ? wp_unslash( $_POST['sml_translations'] ) : array();
        foreach ( $submitted as $slug => $candidate ) {
            $slug = sanitize_key( $slug );
            $candidate = absint( $candidate );
            if ( ! $candidate || ! isset( $languages[ $slug ] ) || isset( $translations[ $slug ] ) ) {
                continue;
            }
            $translated_post = get_post( $candidate );
            if ( ! $translated_post || $translated_post->post_type !== $post->post_type || ! current_user_can( 'edit_post', $candidate ) || in_array( $candidate, $translations, true ) ) {
                continue;
            }
            $translations[ $slug ] = $candidate;
        }

        self::sync_post_translations( $translations );
    }

    public function register_term_language_fields() {
        foreach ( self::get_taxonomies() as $taxonomy ) {
            add_action( $taxonomy . '_add_form_fields', function () use ( $taxonomy ) { $this->render_term_add_fields( $taxonomy ); } );
            add_action( $taxonomy . '_edit_form_fields', function ( $term ) use ( $taxonomy ) { $this->render_term_edit_fields( $term, $taxonomy ); } );
            add_action( 'created_' . $taxonomy, function ( $term_id ) use ( $taxonomy ) { $this->save_term_language( $term_id, $taxonomy ); }, 20 );
            add_action( 'edited_' . $taxonomy, function ( $term_id ) use ( $taxonomy ) { $this->save_term_language( $term_id, $taxonomy ); }, 20 );
        }
    }

    public function render_term_add_fields( $taxonomy ) {
        wp_nonce_field( 'sml_save_term_language_' . $taxonomy, 'sml_term_language_nonce' );
        $this->render_term_fields( 0 );
    }

    public function render_term_edit_fields( $term, $taxonomy ) {
        wp_nonce_field( 'sml_save_term_language_' . $taxonomy, 'sml_term_language_nonce' );
        ?>
        <tr class="form-field"><th scope="row"><label><?php esc_html_e( 'Simple Multilang', 'simple-multilang-blocks' ); ?></label></th><td>
            <?php if ( isset( $_GET['sml_term_queued'] ) ) : ?><p class="sml-review-badge"><?php esc_html_e( 'Automatic translation queued', 'simple-multilang-blocks' ); ?></p><?php endif; ?>
            <?php if ( isset( $_GET['sml_term_verified'] ) ) : ?><p class="sml-review-badge"><?php esc_html_e( 'Translation marked as verified.', 'simple-multilang-blocks' ); ?></p><?php endif; ?>
            <?php if ( isset( $_GET['sml_term_error'] ) ) : ?><p class="sml-review-badge"><?php esc_html_e( 'The term translation could not be created. Check the provider or link an existing term by ID.', 'simple-multilang-blocks' ); ?></p><?php endif; ?>
            <?php if ( 'needs_review' === get_term_meta( $term->term_id, '_sml_translation_status', true ) ) : ?>
                <?php $verify_url = wp_nonce_url( add_query_arg( array( 'action' => 'sml_mark_term_translation_verified', 'term' => $term->term_id ), admin_url( 'admin-post.php' ) ), 'sml_mark_term_translation_verified_' . $term->term_id ); ?>
                <p class="sml-review-badge"><?php esc_html_e( 'Machine translation — requires review.', 'simple-multilang-blocks' ); ?> <a href="<?php echo esc_url( $verify_url ); ?>"><?php esc_html_e( 'Mark verified', 'simple-multilang-blocks' ); ?></a></p>
            <?php endif; ?>
            <?php $this->render_term_fields( $term->term_id ); ?>
        </td></tr>
        <?php
    }

    private function render_term_fields( $term_id ) {
        $languages = self::get_languages();
        $current = $term_id ? self::get_term_language( $term_id ) : self::get_default_language();
        $translations = $term_id ? self::get_term_translations( $term_id ) : array();
        ?><p><label><?php esc_html_e( 'Language', 'simple-multilang-blocks' ); ?><select name="sml_term_language">
            <?php foreach ( $languages as $slug => $data ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>><?php echo esc_html( $data['name'] ); ?></option><?php endforeach; ?>
        </select></label></p><?php
        foreach ( $languages as $slug => $data ) {
            printf( '<p><label>%s <input min="1" type="number" name="sml_term_translations[%s]" value="%s"></label>', esc_html( $data['name'] ), esc_attr( $slug ), esc_attr( isset( $translations[ $slug ] ) ? (int) $translations[ $slug ] : '' ) );
            if ( $term_id && ! empty( $translations[ $slug ] ) ) {
                $translation_id = absint( $translations[ $slug ] );
                echo ' <a class="button button-small" href="' . esc_url( get_edit_term_link( $translation_id, get_term_field( 'taxonomy', $term_id ) ) ) . '">' . esc_html__( 'Edit translation', 'simple-multilang-blocks' ) . '</a>';
                if ( 'needs_review' === get_term_meta( $translation_id, '_sml_translation_status', true ) || 'draft' === get_term_meta( $translation_id, '_sml_translation_status', true ) ) {
                    echo ' <span class="sml-review-badge">' . esc_html__( 'Requires review', 'simple-multilang-blocks' ) . '</span>';
                }
            } elseif ( $term_id && $slug !== $current ) {
                $url = wp_nonce_url( add_query_arg( array( 'action' => 'sml_create_term_translation', 'term' => $term_id, 'lang' => $slug ), admin_url( 'admin-post.php' ) ), 'sml_create_term_translation_' . $term_id . '_' . $slug );
                $machine_url = wp_nonce_url( add_query_arg( array( 'action' => 'sml_translate_term', 'term' => $term_id, 'lang' => $slug ), admin_url( 'admin-post.php' ) ), 'sml_translate_term_' . $term_id . '_' . $slug );
                echo ' <a class="button button-small button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'Create linked term', 'simple-multilang-blocks' ) . '</a>';
                $job = SML_Translation_Service::get_term_job( $term_id, $slug );
                if ( $job && in_array( $job['status'], array( 'queued', 'processing', 'retry' ), true ) ) {
                    echo ' <span class="sml-review-badge">' . esc_html__( 'Automatic translation queued', 'simple-multilang-blocks' ) . '</span>';
                } else {
                    echo ' <a class="button button-small" href="' . esc_url( $machine_url ) . '">' . esc_html__( 'Queue automatic translation', 'simple-multilang-blocks' ) . '</a>';
                }
            }
            echo '</p>';
        }
    }

    public function create_term_translation() {
        list( $term, $language ) = $this->requested_term_translation( 'sml_create_term_translation' );
        $term_id = $term->term_id;
        $result = SML_Translation_Service::create_manual_term( $term_id, $language );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( 'sml_term_error', '1', get_edit_term_link( $term_id, $term->taxonomy ) ) );
            exit;
        }
        wp_safe_redirect( add_query_arg( 'sml_term_created', '1', get_edit_term_link( absint( $result ), $term->taxonomy ) ) );
        exit;
    }

    public function queue_term_translation() {
        list( $term, $language ) = $this->requested_term_translation( 'sml_translate_term' );
        $result = SML_Translation_Service::queue_machine_term( $term->term_id, $language );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( 'sml_term_error', '1', get_edit_term_link( $term->term_id, $term->taxonomy ) ) );
            exit;
        }
        wp_safe_redirect( add_query_arg( 'sml_term_queued', '1', get_edit_term_link( $term->term_id, $term->taxonomy ) ) );
        exit;
    }

    public function mark_term_translation_verified() {
        $term_id = isset( $_GET['term'] ) ? absint( $_GET['term'] ) : 0;
        $term = $term_id ? get_term( $term_id ) : false;
        $taxonomy = $term && ! is_wp_error( $term ) ? get_taxonomy( $term->taxonomy ) : false;
        if ( ! $term || is_wp_error( $term ) || ! $taxonomy || ! current_user_can( $taxonomy->cap->manage_terms ) ) {
            wp_die( esc_html__( 'You are not allowed to verify this term translation.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( 'sml_mark_term_translation_verified_' . $term_id );
        update_term_meta( $term_id, '_sml_translation_status', 'verified' );
        wp_safe_redirect( add_query_arg( 'sml_term_verified', '1', get_edit_term_link( $term_id, $term->taxonomy ) ) );
        exit;
    }

    /** @return array{0:WP_Term,1:string} */
    private function requested_term_translation( $action ) {
        $term_id = isset( $_GET['term'] ) ? absint( $_GET['term'] ) : 0;
        $language = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
        $term = $term_id ? get_term( $term_id ) : false;
        $taxonomy = $term && ! is_wp_error( $term ) ? get_taxonomy( $term->taxonomy ) : false;
        if ( ! $term || is_wp_error( $term ) || ! $taxonomy || ! current_user_can( $taxonomy->cap->manage_terms ) ) {
            wp_die( esc_html__( 'You are not allowed to create this term translation.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( $action . '_' . $term_id . '_' . $language );
        if ( ! isset( self::get_languages()[ $language ] ) ) {
            wp_die( esc_html__( 'The target language is invalid.', 'simple-multilang-blocks' ) );
        }
        return array( $term, $language );
    }

    public function save_term_language( $term_id, $taxonomy ) {
        $tax_object = get_taxonomy( $taxonomy );
        if ( ! $tax_object || ! current_user_can( $tax_object->cap->manage_terms ) || empty( $_POST['sml_term_language_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sml_term_language_nonce'] ) ), 'sml_save_term_language_' . $taxonomy ) ) {
            return;
        }
        $languages = self::get_languages();
        $language = isset( $_POST['sml_term_language'] ) ? sanitize_key( wp_unslash( $_POST['sml_term_language'] ) ) : self::get_default_language();
        if ( ! isset( $languages[ $language ] ) ) {
            $language = self::get_default_language();
        }
        $translations = array( $language => (int) $term_id );
        $submitted = isset( $_POST['sml_term_translations'] ) && is_array( $_POST['sml_term_translations'] ) ? wp_unslash( $_POST['sml_term_translations'] ) : array();
        foreach ( $submitted as $slug => $candidate ) {
            $slug = sanitize_key( $slug );
            $candidate = absint( $candidate );
            $term = $candidate ? get_term( $candidate, $taxonomy ) : false;
            if ( $term && ! is_wp_error( $term ) && isset( $languages[ $slug ] ) && ! in_array( $candidate, $translations, true ) ) {
                $translations[ $slug ] = $candidate;
            }
        }
        self::sync_term_translations( $translations );
    }

    public static function get_post_language( $post_id ) {
        $language = get_post_meta( $post_id, '_sml_language', true );
        return isset( self::get_languages()[ $language ] ) ? $language : self::get_default_language();
    }

    public static function get_term_language( $term_id ) {
        $language = get_term_meta( $term_id, '_sml_language', true );
        return isset( self::get_languages()[ $language ] ) ? $language : self::get_default_language();
    }

    public static function get_post_translations( $post_id ) {
        $translations = get_post_meta( $post_id, '_sml_translations', true );
        return is_array( $translations ) ? array_map( 'absint', $translations ) : array();
    }

    public static function get_term_translations( $term_id ) {
        $translations = get_term_meta( $term_id, '_sml_translations', true );
        return is_array( $translations ) ? array_map( 'absint', $translations ) : array();
    }

    public static function sync_post_translations( $translations, $sync_hierarchy = true ) {
        $visibility = self::build_visibility_map( $translations );
        foreach ( $translations as $language => $post_id ) {
            if ( $post_id && isset( self::get_languages()[ $language ] ) ) {
                update_post_meta( $post_id, '_sml_language', $language );
                update_post_meta( $post_id, '_sml_translations', $translations );
                delete_post_meta( $post_id, '_sml_visible_in' );
                foreach ( $visibility[ $post_id ] ?? array() as $visible_language ) {
                    add_post_meta( $post_id, '_sml_visible_in', $visible_language );
                }
            }
        }
        if ( $sync_hierarchy ) {
            self::sync_post_hierarchy_group( $translations );
        }
    }

    public static function sync_term_translations( $translations, $sync_hierarchy = true ) {
        $visibility = self::build_visibility_map( $translations );
        foreach ( $translations as $language => $term_id ) {
            if ( $term_id && isset( self::get_languages()[ $language ] ) ) {
                update_term_meta( $term_id, '_sml_language', $language );
                update_term_meta( $term_id, '_sml_translations', $translations );
                delete_term_meta( $term_id, '_sml_visible_in' );
                foreach ( $visibility[ $term_id ] ?? array() as $visible_language ) {
                    add_term_meta( $term_id, '_sml_visible_in', $visible_language );
                }
            }
        }
        if ( $sync_hierarchy ) {
            self::sync_term_hierarchy_group( $translations );
        }
    }

    /**
     * Keep a translated child page below the translated parent. Linking the
     * source parent would create a mixed-language permalink, so an untranslated
     * parent deliberately becomes the root until its own translation exists.
     */
    private static function sync_post_hierarchy_group( $translations ) {
        $reference_id = self::translation_reference_id( $translations );
        $reference = $reference_id ? get_post( $reference_id ) : false;
        if ( ! $reference || ! is_post_type_hierarchical( $reference->post_type ) ) {
            return;
        }
        $reference_parent = absint( $reference->post_parent );
        $parent_translations = $reference_parent ? self::get_post_translations( $reference_parent ) : array();
        foreach ( (array) $translations as $language => $post_id ) {
            $post_id = absint( $post_id );
            $post = $post_id ? get_post( $post_id ) : false;
            if ( ! $post || $post->post_type !== $reference->post_type ) {
                continue;
            }
            $target_parent = ! empty( $parent_translations[ $language ] ) ? absint( $parent_translations[ $language ] ) : 0;
            if ( $target_parent && ! get_post( $target_parent ) ) {
                $target_parent = 0;
            }
            if ( absint( $post->post_parent ) !== $target_parent ) {
                wp_update_post( array( 'ID' => $post_id, 'post_parent' => $target_parent ) );
            }
        }
    }

    /** Applies parent links after a bulk importer has written all page groups. */
    public static function sync_all_post_hierarchy( $post_types = array() ) {
        $post_types = $post_types ? (array) $post_types : self::get_post_types();
        $post_types = array_filter( $post_types, 'is_post_type_hierarchical' );
        if ( ! $post_types ) {
            return;
        }
        $ids = get_posts(
            array(
                'post_type'        => $post_types,
                'post_status'      => 'any',
                'posts_per_page'   => -1,
                'fields'           => 'ids',
                'suppress_filters' => true,
                'meta_key'         => '_sml_translations',
            )
        );
        $seen = array();
        foreach ( $ids as $post_id ) {
            $translations = self::get_post_translations( $post_id );
            $key = implode( ':', array_filter( array_map( 'absint', $translations ) ) );
            if ( ! $key || isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            self::sync_post_hierarchy_group( $translations );
        }
    }

    /** Maps hierarchical term parents to their counterpart in the same language. */
    private static function sync_term_hierarchy_group( $translations ) {
        $reference_id = self::translation_reference_id( $translations );
        $reference = $reference_id ? get_term( $reference_id ) : false;
        if ( ! $reference || is_wp_error( $reference ) ) {
            return;
        }
        $taxonomy = get_taxonomy( $reference->taxonomy );
        if ( ! $taxonomy || empty( $taxonomy->hierarchical ) ) {
            return;
        }
        $reference_parent = absint( $reference->parent );
        $parent_translations = $reference_parent ? self::get_term_translations( $reference_parent ) : array();
        foreach ( (array) $translations as $language => $term_id ) {
            $term_id = absint( $term_id );
            $term = $term_id ? get_term( $term_id, $reference->taxonomy ) : false;
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            $target_parent = ! empty( $parent_translations[ $language ] ) ? absint( $parent_translations[ $language ] ) : 0;
            if ( $target_parent && ! get_term( $target_parent, $reference->taxonomy ) ) {
                $target_parent = 0;
            }
            if ( absint( $term->parent ) !== $target_parent ) {
                wp_update_term( $term_id, $reference->taxonomy, array( 'parent' => $target_parent ) );
            }
        }
    }

    /** Applies term parents after all imported term groups are known. */
    public static function sync_all_term_hierarchy( $taxonomies = array() ) {
        $taxonomies = $taxonomies ? (array) $taxonomies : self::get_taxonomies();
        $taxonomies = array_filter( $taxonomies, static function ( $taxonomy ) {
            $object = get_taxonomy( $taxonomy );
            return $object && ! empty( $object->hierarchical );
        } );
        if ( ! $taxonomies ) {
            return;
        }
        $ids = get_terms(
            array(
                'taxonomy'   => $taxonomies,
                'hide_empty' => false,
                'fields'     => 'ids',
                'meta_query' => array( array( 'key' => '_sml_translations' ) ),
            )
        );
        if ( is_wp_error( $ids ) ) {
            return;
        }
        $seen = array();
        foreach ( $ids as $term_id ) {
            $translations = self::get_term_translations( $term_id );
            $key = implode( ':', array_filter( array_map( 'absint', $translations ) ) );
            if ( ! $key || isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            self::sync_term_hierarchy_group( $translations );
        }
    }

    private static function translation_reference_id( $translations ) {
        $translations = array_filter( array_map( 'absint', (array) $translations ) );
        if ( ! $translations ) {
            return 0;
        }
        $default = self::get_default_language();
        return ! empty( $translations[ $default ] ) ? absint( $translations[ $default ] ) : absint( reset( $translations ) );
    }

    private static function build_visibility_map( $translations ) {
        $translations = array_filter( array_map( 'absint', (array) $translations ) );
        if ( ! $translations ) {
            return array();
        }
        $default = self::get_default_language();
        $fallback_id = isset( $translations[ $default ] ) ? $translations[ $default ] : reset( $translations );
        $visibility = array();
        foreach ( self::get_languages() as $language => $data ) {
            $object_id = isset( $translations[ $language ] ) ? $translations[ $language ] : $fallback_id;
            $visibility[ $object_id ][] = $language;
        }
        return $visibility;
    }

    public function register_menu_sync_page() {
        add_theme_page(
            __( 'Multilingual menus', 'simple-multilang-blocks' ),
            __( 'Multilingual menus', 'simple-multilang-blocks' ),
            'edit_theme_options',
            'sml-menu-sync',
            array( $this, 'render_menu_sync_page' )
        );
    }

    /** A controlled UI for linking separate classic WordPress menus by language. */
    public function render_menu_sync_page() {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            return;
        }
        $menus = wp_get_nav_menus();
        $languages = self::get_languages();
        $groups = self::navigation_menu_groups( $menus );
        ?>
        <div class="wrap sml-admin-wrap">
            <h1><?php esc_html_e( 'Multilingual menus', 'simple-multilang-blocks' ); ?></h1>
            <p class="description"><?php esc_html_e( 'A shared classic menu automatically follows linked pages, posts, products and terms. Link separate menus below when a language needs its own custom links or labels; wp_nav_menu() will then select the matching menu automatically.', 'simple-multilang-blocks' ); ?></p>
            <?php if ( isset( $_GET['sml_menu_saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Menu language links saved.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['sml_menu_scanned'] ) ) : ?><div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Catalogued %d custom navigation labels.', 'simple-multilang-blocks' ), absint( $_GET['sml_menu_scanned'] ) ) ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['sml_menu_error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'Choose a menu only once in a language group. Nothing was changed.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>
            <?php if ( ! $menus ) : ?>
                <p><?php esc_html_e( 'Create a classic menu under Appearance → Menus first. Navigation blocks in block themes are mapped automatically when their links or wp_navigation posts are linked.', 'simple-multilang-blocks' ); ?></p>
            <?php else : ?>
                <h2><?php esc_html_e( 'Classic menu language groups', 'simple-multilang-blocks' ); ?></h2>
                <?php foreach ( $groups as $group ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sml-menu-group">
                        <?php wp_nonce_field( 'sml_save_menu_group' ); ?>
                        <input type="hidden" name="action" value="sml_save_menu_group">
                        <table class="widefat striped"><thead><tr><?php foreach ( $languages as $language ) : ?><th><?php echo esc_html( self::get_language_flag( $language ) . ' ' . $language['name'] ); ?></th><?php endforeach; ?></tr></thead><tbody><tr>
                            <?php foreach ( $languages as $slug => $language ) : ?><td><label class="screen-reader-text" for="sml-menu-<?php echo esc_attr( md5( wp_json_encode( $group ) ) . '-' . $slug ); ?>"><?php echo esc_html( $language['name'] ); ?></label><select id="sml-menu-<?php echo esc_attr( md5( wp_json_encode( $group ) ) . '-' . $slug ); ?>" name="sml_menu_translations[<?php echo esc_attr( $slug ); ?>]"><option value=""><?php esc_html_e( '— No separate menu —', 'simple-multilang-blocks' ); ?></option><?php foreach ( $menus as $menu ) : ?><option value="<?php echo esc_attr( $menu->term_id ); ?>" <?php selected( absint( $group[ $slug ] ?? 0 ), $menu->term_id ); ?>><?php echo esc_html( $menu->name ); ?></option><?php endforeach; ?></select></td><?php endforeach; ?>
                        </tr></tbody></table>
                        <p><button class="button button-primary"><?php esc_html_e( 'Save menu group', 'simple-multilang-blocks' ); ?></button></p>
                    </form>
                <?php endforeach; ?>
                <h2><?php esc_html_e( 'Custom labels', 'simple-multilang-blocks' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Page and term labels are replaced automatically. Scan manually entered classic-menu labels and custom navigation-block labels once so they appear under Settings → Interface strings for translation.', 'simple-multilang-blocks' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sml_scan_menu_strings' ); ?><input type="hidden" name="action" value="sml_scan_menu_strings"><button class="button button-secondary"><?php esc_html_e( 'Scan navigation labels', 'simple-multilang-blocks' ); ?></button></form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function save_menu_group() {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to edit menu language links.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( 'sml_save_menu_group' );
        $submitted = isset( $_POST['sml_menu_translations'] ) && is_array( $_POST['sml_menu_translations'] ) ? wp_unslash( $_POST['sml_menu_translations'] ) : array();
        $translations = array();
        $used = array();
        foreach ( self::get_languages() as $language => $data ) {
            $menu_id = absint( $submitted[ $language ] ?? 0 );
            if ( ! $menu_id ) {
                continue;
            }
            $menu = get_term( $menu_id, 'nav_menu' );
            if ( ! $menu || is_wp_error( $menu ) || isset( $used[ $menu_id ] ) ) {
                wp_safe_redirect( add_query_arg( 'sml_menu_error', '1', admin_url( 'themes.php?page=sml-menu-sync' ) ) );
                exit;
            }
            $translations[ $language ] = $menu_id;
            $used[ $menu_id ] = true;
        }
        if ( ! $translations ) {
            wp_safe_redirect( add_query_arg( 'sml_menu_error', '1', admin_url( 'themes.php?page=sml-menu-sync' ) ) );
            exit;
        }
        self::sync_navigation_menu_translations( $translations );
        wp_safe_redirect( add_query_arg( 'sml_menu_saved', '1', admin_url( 'themes.php?page=sml-menu-sync' ) ) );
        exit;
    }

    /**
     * Reassigns menu terms without leaving stale relationships in a previous
     * group. This is deliberately separate from generic term syncing because
     * a menu can be moved from one language group to another in one save.
     */
    public static function sync_navigation_menu_translations( $translations ) {
        $translations = self::valid_navigation_menu_translations( $translations );
        if ( ! $translations ) {
            return false;
        }
        $selected_ids = array_values( $translations );
        $old_groups = array();
        foreach ( $selected_ids as $menu_id ) {
            $old = self::valid_navigation_menu_translations( self::get_term_translations( $menu_id ) );
            if ( ! $old ) {
                $old = array( self::get_term_language( $menu_id ) => $menu_id );
            }
            ksort( $old );
            $old_groups[ md5( wp_json_encode( $old ) ) ] = $old;
        }
        foreach ( $old_groups as $old_group ) {
            $remaining = array();
            foreach ( $old_group as $language => $menu_id ) {
                delete_term_meta( $menu_id, '_sml_translations' );
                delete_term_meta( $menu_id, '_sml_visible_in' );
                if ( ! in_array( $menu_id, $selected_ids, true ) ) {
                    $remaining[ $language ] = $menu_id;
                }
            }
            if ( $remaining ) {
                self::sync_term_translations( $remaining, false );
            }
        }
        self::sync_term_translations( $translations, false );
        return true;
    }

    public function scan_menu_strings() {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to scan menu labels.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( 'sml_scan_menu_strings' );
        $count = 0;
        foreach ( wp_get_nav_menus() as $menu ) {
            foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
                $source_label = self::classic_menu_item_source_label( $item );
                if ( self::menu_item_has_custom_label( $item->title, $source_label, $item->type ) && '' !== trim( wp_strip_all_tags( $item->title ) ) ) {
                    self::register_string( 'menu-item', 'item:' . absint( $item->ID ), sanitize_text_field( wp_strip_all_tags( $item->title ) ) );
                    ++$count;
                }
            }
        }
        $navigation_posts = get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'any', 'posts_per_page' => -1, 'suppress_filters' => true ) );
        foreach ( $navigation_posts as $navigation ) {
            self::catalogue_navigation_block_strings( parse_blocks( $navigation->post_content ), $count );
        }
        wp_safe_redirect( add_query_arg( 'sml_menu_scanned', $count, admin_url( 'themes.php?page=sml-menu-sync' ) ) );
        exit;
    }

    private static function navigation_menu_groups( $menus ) {
        $groups = array();
        $seen = array();
        foreach ( (array) $menus as $menu ) {
            $menu_id = absint( $menu->term_id );
            if ( ! $menu_id ) {
                continue;
            }
            $translations = self::valid_navigation_menu_translations( self::get_term_translations( $menu_id ) );
            if ( ! $translations ) {
                $translations = array( self::get_term_language( $menu_id ) => $menu_id );
            }
            ksort( $translations );
            $key = md5( wp_json_encode( $translations ) );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $groups[] = $translations;
        }
        return $groups;
    }

    private static function valid_navigation_menu_translations( $translations ) {
        $result = array();
        foreach ( (array) $translations as $language => $menu_id ) {
            $language = sanitize_key( $language );
            $menu_id = absint( $menu_id );
            $menu = $menu_id ? get_term( $menu_id, 'nav_menu' ) : false;
            if ( ! isset( self::get_languages()[ $language ] ) || ! $menu || is_wp_error( $menu ) || in_array( $menu_id, $result, true ) ) {
                continue;
            }
            $result[ $language ] = $menu_id;
        }
        return $result;
    }

    private static function classic_menu_item_source_label( $item ) {
        if ( 'post_type' === $item->type ) {
            return sanitize_text_field( wp_strip_all_tags( get_the_title( absint( $item->object_id ) ) ) );
        }
        if ( 'taxonomy' === $item->type ) {
            $term = get_term( absint( $item->object_id ), $item->object );
            return $term && ! is_wp_error( $term ) ? sanitize_text_field( wp_strip_all_tags( $term->name ) ) : '';
        }
        return '';
    }

    private static function catalogue_navigation_block_strings( $blocks, &$count ) {
        foreach ( (array) $blocks as $block ) {
            $name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
            $attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
            if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
                $label = isset( $attributes['label'] ) ? (string) $attributes['label'] : '';
                $source_label = self::navigation_block_source_label( $attributes );
                $kind = sanitize_key( $attributes['kind'] ?? '' );
                if ( '' !== trim( wp_strip_all_tags( $label ) ) && self::menu_item_has_custom_label( $label, $source_label, 'custom' === $kind ? 'custom' : 'linked' ) ) {
                    self::register_string( 'navigation-block', self::navigation_block_string_name( $attributes ), sanitize_text_field( wp_strip_all_tags( $label ) ) );
                    ++$count;
                }
            }
            if ( ! empty( $block['innerBlocks'] ) ) {
                self::catalogue_navigation_block_strings( $block['innerBlocks'], $count );
            }
        }
    }

    private static function navigation_block_source_label( $attributes ) {
        $kind = sanitize_key( $attributes['kind'] ?? '' );
        $source_id = absint( $attributes['id'] ?? 0 );
        if ( 'post-type' === $kind && $source_id ) {
            return sanitize_text_field( wp_strip_all_tags( get_the_title( $source_id ) ) );
        }
        if ( 'taxonomy' === $kind && $source_id && ! empty( $attributes['type'] ) ) {
            $term = get_term( $source_id, sanitize_key( $attributes['type'] ) );
            return $term && ! is_wp_error( $term ) ? sanitize_text_field( wp_strip_all_tags( $term->name ) ) : '';
        }
        return '';
    }

    public function register_settings_page() {
        add_options_page( __( 'Simple Multilang', 'simple-multilang-blocks' ), __( 'Simple Multilang', 'simple-multilang-blocks' ), 'manage_options', 'simple-multilang-blocks', array( $this, 'render_settings_page' ) );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $languages = array_values( self::get_languages() );
        $post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
        $taxonomies = get_taxonomies( array( 'show_ui' => true ), 'objects' );
        $presets = self::language_presets();
        $plugin_domains = SML_Theme_Strings::available_plugin_domains();
        $selected_plugin_domains = SML_Theme_Strings::selected_plugin_domains();
        $wpml_preview = get_transient( $this->wpml_preview_key() );
        $switcher_design = self::get_switcher_design();
        $switcher_has_custom_colors = (bool) array_filter( array_intersect_key( $switcher_design, array_flip( array( 'surface', 'foreground', 'accent', 'active_foreground', 'border' ) ) ) );
        $openai_models = SML_Translation_Service::openai_models();
        $selected_openai_model = SML_Translation_Service::openai_model();
        ?>
        <div class="wrap sml-admin-wrap"><h1><?php esc_html_e( 'Simple Multilang settings', 'simple-multilang-blocks' ); ?></h1>
        <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Settings saved. Rewrite rules were refreshed.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>
        <?php if ( isset( $_GET['imported'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'WPML data was imported. Verify representative pages, categories and strings before uninstalling WPML files.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>
        <p><a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=sml-theme-strings' ) ); ?>"><?php esc_html_e( 'Translate interface strings', 'simple-multilang-blocks' ); ?></a></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sml_save_settings' ); ?><input type="hidden" name="action" value="sml_save_settings">
        <h2><?php esc_html_e( 'Languages', 'simple-multilang-blocks' ); ?></h2><p class="description"><?php esc_html_e( 'Default-language URLs stay at the site root. Flags can be emoji or a URL to an image.', 'simple-multilang-blocks' ); ?></p>
        <table class="widefat striped sml-language-settings-table"><thead><tr><th><?php esc_html_e( 'Slug', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Language code', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Name', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Flag', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Default', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Preset', 'simple-multilang-blocks' ); ?></th><th class="sml-remove-heading"><span class="screen-reader-text"><?php esc_html_e( 'Delete', 'simple-multilang-blocks' ); ?></span></th></tr></thead><tbody id="sml-language-rows">
        <?php foreach ( $languages as $index => $language ) : ?>
            <tr>
                <td><input type="text" name="sml_languages[<?php echo esc_attr( $index ); ?>][slug]" value="<?php echo esc_attr( $language['slug'] ); ?>"></td>
                <td><input type="text" name="sml_languages[<?php echo esc_attr( $index ); ?>][code]" value="<?php echo esc_attr( $language['code'] ); ?>"></td>
                <td><input type="text" name="sml_languages[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $language['name'] ); ?>"></td>
                <td><input type="text" name="sml_languages[<?php echo esc_attr( $index ); ?>][flag]" value="<?php echo esc_attr( self::get_language_flag( $language ) ); ?>"></td>
                <td class="sml-default-cell"><input type="radio" name="sml_default_language" value="<?php echo esc_attr( $index ); ?>" <?php checked( ! empty( $language['is_default'] ) ); ?>></td>
                <td><select class="sml-language-preset"><option value=""><?php esc_html_e( '— Choose —', 'simple-multilang-blocks' ); ?></option><?php foreach ( $presets as $preset ) : ?><option value="<?php echo esc_attr( wp_json_encode( $preset ) ); ?>"><?php echo esc_html( $preset['flag'] . ' ' . $preset['name'] . ' (' . $preset['code'] . ')' ); ?></option><?php endforeach; ?></select></td>
                <td><button type="button" class="button-link-delete sml-remove-language" aria-label="<?php esc_attr_e( 'Delete language', 'simple-multilang-blocks' ); ?>">×</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <p><button type="button" class="button" id="sml-add-language"><?php esc_html_e( 'Add language', 'simple-multilang-blocks' ); ?></button></p>
        <template id="sml-language-row-template"><tr><td><input type="text" data-field="slug"></td><td><input type="text" data-field="code"></td><td><input type="text" data-field="name"></td><td><input type="text" data-field="flag"></td><td class="sml-default-cell"><input type="radio" data-field="default"></td><td><select class="sml-language-preset"><option value=""><?php esc_html_e( '— Choose —', 'simple-multilang-blocks' ); ?></option><?php foreach ( $presets as $preset ) : ?><option value="<?php echo esc_attr( wp_json_encode( $preset ) ); ?>"><?php echo esc_html( $preset['flag'] . ' ' . $preset['name'] . ' (' . $preset['code'] . ')' ); ?></option><?php endforeach; ?></select></td><td><button type="button" class="button-link-delete sml-remove-language" aria-label="<?php esc_attr_e( 'Delete language', 'simple-multilang-blocks' ); ?>">×</button></td></tr></template>
        <h2><?php esc_html_e( 'Translatable content', 'simple-multilang-blocks' ); ?></h2><div class="sml-checkbox-grid">
        <?php foreach ( $post_types as $name => $object ) : if ( 'attachment' === $name ) { continue; } ?><label><input type="checkbox" name="sml_post_types[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, self::get_post_types(), true ) ); ?>> <?php echo esc_html( $object->labels->name ); ?></label><?php endforeach; ?>
        </div><h3><?php esc_html_e( 'Translatable taxonomies', 'simple-multilang-blocks' ); ?></h3><div class="sml-checkbox-grid">
        <?php foreach ( $taxonomies as $name => $object ) : ?><label><input type="checkbox" name="sml_taxonomies[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, self::get_taxonomies(), true ) ); ?>> <?php echo esc_html( $object->labels->name ); ?></label><?php endforeach; ?>
        </div>
        <h2><?php esc_html_e( 'Interface strings', 'simple-multilang-blocks' ); ?></h2><p class="description"><?php esc_html_e( 'Only public site-interface strings are translated. Content, WordPress admin, service actions and multilingual routing continue to work independently.', 'simple-multilang-blocks' ); ?></p><p><label><input type="checkbox" name="sml_interface_public_only" value="1" <?php checked( SML_Theme_Strings::is_public_interface_only() ); ?>> <?php esc_html_e( 'Translate on the public site only', 'simple-multilang-blocks' ); ?></label><br><label><input type="checkbox" name="sml_interface_string_capture" value="1" <?php checked( SML_Theme_Strings::is_capture_enabled() ); ?>> <?php esc_html_e( 'Add unknown visible interface strings to the catalogue using their current source text', 'simple-multilang-blocks' ); ?></label></p>
        <h3><?php esc_html_e( 'Plugin interface sources', 'simple-multilang-blocks' ); ?></h3><p class="description"><?php esc_html_e( 'Choose only plugins whose public-facing labels you want to translate. Known strings can be scanned from POT files; strings without a POT file are added when a visitor opens the relevant public page.', 'simple-multilang-blocks' ); ?></p><div class="sml-checkbox-grid">
        <?php if ( $plugin_domains ) : foreach ( $plugin_domains as $domain => $plugin ) : ?><label><input type="checkbox" name="sml_interface_plugin_domains[]" value="<?php echo esc_attr( $domain ); ?>" <?php checked( in_array( $domain, $selected_plugin_domains, true ) ); ?>> <?php echo esc_html( $plugin['name'] . ' (' . $domain . ')' ); ?></label><?php endforeach; else : ?><span class="description"><?php esc_html_e( 'No eligible active plugins were found.', 'simple-multilang-blocks' ); ?></span><?php endif; ?>
        </div>
        <h2><?php esc_html_e( 'Language switcher', 'simple-multilang-blocks' ); ?></h2>
        <p><label><input type="radio" name="sml_switcher_placement" value="automatic" <?php checked( 'automatic', get_option( self::OPTION_SWITCHER_PLACEMENT, 'automatic' ) ); ?>> <?php esc_html_e( 'Show a floating switcher automatically', 'simple-multilang-blocks' ); ?></label><br><label><input type="radio" name="sml_switcher_placement" value="shortcode" <?php checked( 'shortcode', get_option( self::OPTION_SWITCHER_PLACEMENT, 'automatic' ) ); ?>> <?php esc_html_e( 'Use shortcode or the Language switcher block in the header', 'simple-multilang-blocks' ); ?></label></p>
        <p><label><?php esc_html_e( 'Appearance for this theme', 'simple-multilang-blocks' ); ?> <select name="sml_switcher_appearance"><option value="theme" <?php selected( 'theme', self::get_switcher_appearance() ); ?>><?php esc_html_e( 'Use theme colors', 'simple-multilang-blocks' ); ?></option><option value="light" <?php selected( 'light', self::get_switcher_appearance() ); ?>><?php esc_html_e( 'Light', 'simple-multilang-blocks' ); ?></option><option value="dark" <?php selected( 'dark', self::get_switcher_appearance() ); ?>><?php esc_html_e( 'Dark', 'simple-multilang-blocks' ); ?></option><option value="minimal" <?php selected( 'minimal', self::get_switcher_appearance() ); ?>><?php esc_html_e( 'Minimal', 'simple-multilang-blocks' ); ?></option></select></label> <label><?php esc_html_e( 'Theme-specific CSS class', 'simple-multilang-blocks' ); ?> <input type="text" name="sml_switcher_custom_class" value="<?php echo esc_attr( self::get_switcher_custom_class() ); ?>" placeholder="my-theme-language-switcher"></label></p>
        <h3><?php esc_html_e( 'Quick design', 'simple-multilang-blocks' ); ?></h3>
        <p><label><?php esc_html_e( 'Layout', 'simple-multilang-blocks' ); ?> <select name="sml_switcher_design[style]"><option value="pills" <?php selected( 'pills', $switcher_design['style'] ); ?>><?php esc_html_e( 'Header pill', 'simple-multilang-blocks' ); ?></option><option value="dropdown" <?php selected( 'dropdown', $switcher_design['style'] ); ?>><?php esc_html_e( 'Rounded selector', 'simple-multilang-blocks' ); ?></option><option value="list" <?php selected( 'list', $switcher_design['style'] ); ?>><?php esc_html_e( 'Vertical list', 'simple-multilang-blocks' ); ?></option></select></label> <label><?php esc_html_e( 'Density', 'simple-multilang-blocks' ); ?> <select name="sml_switcher_design[density]"><option value="regular" <?php selected( 'regular', $switcher_design['density'] ); ?>><?php esc_html_e( 'Regular', 'simple-multilang-blocks' ); ?></option><option value="compact" <?php selected( 'compact', $switcher_design['density'] ); ?>><?php esc_html_e( 'Compact', 'simple-multilang-blocks' ); ?></option></select></label> <label><?php esc_html_e( 'Floating position', 'simple-multilang-blocks' ); ?> <select name="sml_switcher_design[position]"><option value="top-right" <?php selected( 'top-right', $switcher_design['position'] ); ?>><?php esc_html_e( 'Top right', 'simple-multilang-blocks' ); ?></option><option value="top-left" <?php selected( 'top-left', $switcher_design['position'] ); ?>><?php esc_html_e( 'Top left', 'simple-multilang-blocks' ); ?></option><option value="bottom-right" <?php selected( 'bottom-right', $switcher_design['position'] ); ?>><?php esc_html_e( 'Bottom right', 'simple-multilang-blocks' ); ?></option><option value="bottom-left" <?php selected( 'bottom-left', $switcher_design['position'] ); ?>><?php esc_html_e( 'Bottom left', 'simple-multilang-blocks' ); ?></option></select></label></p>
        <p><label><input type="checkbox" name="sml_switcher_design[show_name]" value="1" <?php checked( $switcher_design['show_name'] ); ?>> <?php esc_html_e( 'Show language names', 'simple-multilang-blocks' ); ?></label> <label><input type="checkbox" name="sml_switcher_design[show_flag]" value="1" <?php checked( $switcher_design['show_flag'] ); ?>> <?php esc_html_e( 'Show flags', 'simple-multilang-blocks' ); ?></label></p>
        <p><label><input type="checkbox" name="sml_switcher_custom_colors" value="1" <?php checked( $switcher_has_custom_colors ); ?>> <?php esc_html_e( 'Use custom colours for this theme', 'simple-multilang-blocks' ); ?></label></p>
        <p class="sml-switcher-colors"><label><?php esc_html_e( 'Background', 'simple-multilang-blocks' ); ?> <input type="color" name="sml_switcher_design[surface]" value="<?php echo esc_attr( $switcher_design['surface'] ?: '#ffffff' ); ?>"></label> <label><?php esc_html_e( 'Text', 'simple-multilang-blocks' ); ?> <input type="color" name="sml_switcher_design[foreground]" value="<?php echo esc_attr( $switcher_design['foreground'] ?: '#27364a' ); ?>"></label> <label><?php esc_html_e( 'Active background', 'simple-multilang-blocks' ); ?> <input type="color" name="sml_switcher_design[accent]" value="<?php echo esc_attr( $switcher_design['accent'] ?: '#174ea6' ); ?>"></label> <label><?php esc_html_e( 'Active text', 'simple-multilang-blocks' ); ?> <input type="color" name="sml_switcher_design[active_foreground]" value="<?php echo esc_attr( $switcher_design['active_foreground'] ?: '#ffffff' ); ?>"></label> <label><?php esc_html_e( 'Border', 'simple-multilang-blocks' ); ?> <input type="color" name="sml_switcher_design[border]" value="<?php echo esc_attr( $switcher_design['border'] ?: '#d6dce5' ); ?>"></label></p>
        <p class="description"><code>[sml_language_switcher]</code> <?php esc_html_e( 'inherits these settings. Themes can use the shortcode or the block inside a header; automatic mode is a floating fallback.', 'simple-multilang-blocks' ); ?></p>
        <h2><?php esc_html_e( 'Automatic translation', 'simple-multilang-blocks' ); ?></h2><p><label><?php esc_html_e( 'Provider', 'simple-multilang-blocks' ); ?> <select name="sml_translation_provider"><option value="" <?php selected( '', SML_Translation_Service::provider() ); ?>><?php esc_html_e( 'Disabled', 'simple-multilang-blocks' ); ?></option><option value="deepl" <?php selected( 'deepl', SML_Translation_Service::provider() ); ?>>DeepL</option><option value="openai" <?php selected( 'openai', SML_Translation_Service::provider() ); ?>><?php esc_html_e( 'OpenAI / ChatGPT', 'simple-multilang-blocks' ); ?></option></select></label></p><p><label><?php esc_html_e( 'OpenAI model', 'simple-multilang-blocks' ); ?> <select name="sml_openai_model"><?php foreach ( $openai_models as $model_id => $model ) : ?><option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $selected_openai_model, $model_id ); ?>><?php echo esc_html( $model['label'] . ' — ' . $model['description'] ); ?></option><?php endforeach; ?></select></label></p><?php if ( defined( 'SML_OPENAI_MODEL' ) ) : ?><p class="description"><?php esc_html_e( 'The SML_OPENAI_MODEL value in wp-config.php currently overrides this selection.', 'simple-multilang-blocks' ); ?></p><?php endif; ?><p><label><?php esc_html_e( 'DeepL endpoint', 'simple-multilang-blocks' ); ?> <select name="sml_deepl_endpoint"><option value="free" <?php selected( 'free', get_option( SML_Translation_Service::OPTION_DEEPL_ENDPOINT, 'free' ) ); ?>>api-free.deepl.com</option><option value="pro" <?php selected( 'pro', get_option( SML_Translation_Service::OPTION_DEEPL_ENDPOINT, 'free' ) ); ?>>api.deepl.com</option></select></label></p><div class="notice notice-info inline"><p><?php esc_html_e( 'API keys are never stored in WordPress. Generated posts remain drafts and generated taxonomy terms require review; if the service is unavailable, no content is created and no public-page error is shown. Add either SML_DEEPL_API_KEY or SML_OPENAI_API_KEY to wp-config.php.', 'simple-multilang-blocks' ); ?></p></div>
        <p><button class="button button-primary"><?php esc_html_e( 'Save settings', 'simple-multilang-blocks' ); ?></button></p></form>
        <hr><h2><?php esc_html_e( 'WPML migration', 'simple-multilang-blocks' ); ?></h2><p><?php esc_html_e( 'Imports active languages, selected posts, selected taxonomies and String Translation values. It never deletes WPML tables or options.', 'simple-multilang-blocks' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sml_preview_wpml' ); ?><input type="hidden" name="action" value="sml_preview_wpml"><p><button class="button button-secondary"> <?php esc_html_e( 'Run migration preflight', 'simple-multilang-blocks' ); ?></button></p></form>
        <?php if ( is_array( $wpml_preview ) && ! empty( $wpml_preview['error'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $wpml_preview['error'] ); ?></p></div><?php elseif ( is_array( $wpml_preview ) && ! empty( $wpml_preview['result'] ) && is_array( $wpml_preview['result'] ) ) : $preview = $wpml_preview['result']; ?>
            <div class="notice notice-info inline"><p><strong><?php esc_html_e( 'Preflight complete.', 'simple-multilang-blocks' ); ?></strong> <?php esc_html_e( 'Nothing was changed. Review the relationship counts before importing.', 'simple-multilang-blocks' ); ?></p><ul class="sml-migration-summary">
                <li><?php echo esc_html( sprintf( __( 'Languages: %d', 'simple-multilang-blocks' ), absint( $preview['languages'] ?? 0 ) ) ); ?></li>
                <li><?php echo esc_html( sprintf( __( 'Post groups: %1$d (%2$d linked, %3$d single-language)', 'simple-multilang-blocks' ), absint( $preview['post_groups'] ?? 0 ), absint( $preview['linked_post_groups'] ?? 0 ), absint( $preview['unlinked_post_groups'] ?? 0 ) ) ); ?></li>
                <li><?php echo esc_html( sprintf( __( 'Term groups: %1$d (%2$d linked, %3$d single-language)', 'simple-multilang-blocks' ), absint( $preview['term_groups'] ?? 0 ), absint( $preview['linked_term_groups'] ?? 0 ), absint( $preview['unlinked_term_groups'] ?? 0 ) ) ); ?></li>
                <li><?php echo esc_html( sprintf( __( 'Interface strings: %1$d (%2$d translations)', 'simple-multilang-blocks' ), absint( $preview['strings'] ?? 0 ), absint( $preview['string_translations'] ?? 0 ) ) ); ?></li>
            </ul><p class="description"><?php esc_html_e( 'Single-language groups receive their language label but are never guessed into a translation relationship.', 'simple-multilang-blocks' ); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sml_import_wpml' ); ?><input type="hidden" name="action" value="sml_import_wpml"><label><input required type="checkbox" name="sml_import_confirm" value="1"> <?php esc_html_e( 'I took a database backup and reviewed the preflight report.', 'simple-multilang-blocks' ); ?></label><p><button class="button" <?php disabled( ! is_array( $wpml_preview ) || empty( $wpml_preview['result'] ) ); ?>> <?php esc_html_e( 'Import WPML data', 'simple-multilang-blocks' ); ?></button></p></form></div>
        <?php
    }

    public function save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to change these settings.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( 'sml_save_settings' );
        $languages = isset( $_POST['sml_languages'] ) && is_array( $_POST['sml_languages'] ) ? wp_unslash( $_POST['sml_languages'] ) : array();
        if ( ! $languages && isset( $_POST['sml_languages_json'] ) ) {
            $languages = json_decode( wp_unslash( $_POST['sml_languages_json'] ), true );
        }
        $default_index = isset( $_POST['sml_default_language'] ) ? absint( $_POST['sml_default_language'] ) : -1;
        foreach ( $languages as $index => &$language ) {
            if ( is_array( $language ) ) {
                $language['is_default'] = (int) $index === $default_index;
            }
        }
        unset( $language );
        $languages = self::sanitize_languages( $languages );
        if ( ! $languages ) {
            wp_die( esc_html__( 'Add at least one valid language.', 'simple-multilang-blocks' ) );
        }
        update_option( self::OPTION_LANGUAGES, $languages );
        $available_post_types = get_post_types( array( 'show_ui' => true ), 'names' );
        $available_taxonomies = get_taxonomies( array( 'show_ui' => true ), 'names' );
        $post_types = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $_POST['sml_post_types'] ?? array() ) ), $available_post_types ) );
        $taxonomies = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $_POST['sml_taxonomies'] ?? array() ) ), $available_taxonomies ) );
        update_option( self::OPTION_POST_TYPES, $post_types );
        update_option( self::OPTION_TAXONOMIES, $taxonomies );
        update_option( self::OPTION_SWITCHER_PLACEMENT, isset( $_POST['sml_switcher_placement'] ) && 'shortcode' === sanitize_key( wp_unslash( $_POST['sml_switcher_placement'] ) ) ? 'shortcode' : 'automatic' );
        $appearance = isset( $_POST['sml_switcher_appearance'] ) ? sanitize_key( wp_unslash( $_POST['sml_switcher_appearance'] ) ) : 'theme';
        $appearance = in_array( $appearance, array( 'theme', 'light', 'dark', 'minimal' ), true ) ? $appearance : 'theme';
        $switcher_appearance = (array) get_option( self::OPTION_SWITCHER_APPEARANCE, array() );
        $switcher_appearance[ get_stylesheet() ] = $appearance;
        update_option( self::OPTION_SWITCHER_APPEARANCE, $switcher_appearance );
        $switcher_classes = (array) get_option( self::OPTION_SWITCHER_CLASS, array() );
        $switcher_classes[ get_stylesheet() ] = isset( $_POST['sml_switcher_custom_class'] ) ? sanitize_html_class( wp_unslash( $_POST['sml_switcher_custom_class'] ) ) : '';
        update_option( self::OPTION_SWITCHER_CLASS, $switcher_classes );
        $submitted_design = isset( $_POST['sml_switcher_design'] ) && is_array( $_POST['sml_switcher_design'] ) ? wp_unslash( $_POST['sml_switcher_design'] ) : array();
        $design = array(
            'style'             => isset( $submitted_design['style'] ) && in_array( sanitize_key( $submitted_design['style'] ), array( 'pills', 'dropdown', 'list' ), true ) ? sanitize_key( $submitted_design['style'] ) : 'pills',
            'density'           => isset( $submitted_design['density'] ) && 'compact' === sanitize_key( $submitted_design['density'] ) ? 'compact' : 'regular',
            'position'          => isset( $submitted_design['position'] ) && in_array( sanitize_key( $submitted_design['position'] ), array( 'top-right', 'top-left', 'bottom-right', 'bottom-left' ), true ) ? sanitize_key( $submitted_design['position'] ) : 'top-right',
            'show_name'         => ! empty( $submitted_design['show_name'] ),
            'show_flag'         => ! empty( $submitted_design['show_flag'] ),
            'surface'           => '',
            'foreground'        => '',
            'accent'            => '',
            'active_foreground' => '',
            'border'            => '',
        );
        if ( ! empty( $_POST['sml_switcher_custom_colors'] ) ) {
            foreach ( array( 'surface', 'foreground', 'accent', 'active_foreground', 'border' ) as $color ) {
                $design[ $color ] = isset( $submitted_design[ $color ] ) ? ( sanitize_hex_color( $submitted_design[ $color ] ) ?: '' ) : '';
            }
        }
        $switcher_designs = (array) get_option( self::OPTION_SWITCHER_DESIGN, array() );
        $switcher_designs[ get_stylesheet() ] = $design;
        update_option( self::OPTION_SWITCHER_DESIGN, $switcher_designs );
        SML_Theme_Strings::save_interface_settings(
            isset( $_POST['sml_interface_plugin_domains'] ) && is_array( $_POST['sml_interface_plugin_domains'] ) ? wp_unslash( $_POST['sml_interface_plugin_domains'] ) : array(),
            ! empty( $_POST['sml_interface_string_capture'] ),
            ! empty( $_POST['sml_interface_public_only'] )
        );
        update_option( SML_Translation_Service::OPTION_PROVIDER, isset( $_POST['sml_translation_provider'] ) && in_array( sanitize_key( wp_unslash( $_POST['sml_translation_provider'] ) ), array( 'deepl', 'openai' ), true ) ? sanitize_key( wp_unslash( $_POST['sml_translation_provider'] ) ) : '' );
        $openai_models = SML_Translation_Service::openai_models();
        $openai_model = isset( $_POST['sml_openai_model'] ) ? sanitize_key( wp_unslash( $_POST['sml_openai_model'] ) ) : SML_Translation_Service::DEFAULT_OPENAI_MODEL;
        update_option( SML_Translation_Service::OPTION_OPENAI_MODEL, isset( $openai_models[ $openai_model ] ) ? $openai_model : SML_Translation_Service::DEFAULT_OPENAI_MODEL );
        update_option( SML_Translation_Service::OPTION_DEEPL_ENDPOINT, isset( $_POST['sml_deepl_endpoint'] ) && 'pro' === sanitize_key( wp_unslash( $_POST['sml_deepl_endpoint'] ) ) ? 'pro' : 'free' );
        self::schedule_rewrite_flush();
        wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=simple-multilang-blocks' ) ) );
        exit;
    }

    public function import_wpml() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to import translations.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( 'sml_import_wpml' );
        if ( empty( $_POST['sml_import_confirm'] ) ) {
            wp_die( esc_html__( 'Confirm the import after taking a database backup.', 'simple-multilang-blocks' ) );
        }
        $preview = get_transient( $this->wpml_preview_key() );
        if ( ! is_array( $preview ) || empty( $preview['result'] ) ) {
            wp_die( esc_html__( 'Run the WPML migration preflight and review its results before importing.', 'simple-multilang-blocks' ) );
        }
        try {
            SML_WPML_Migrator::run( false );
        } catch ( Throwable $error ) {
            set_transient( $this->wpml_preview_key(), array( 'error' => __( 'The WPML migration could not be completed. No WPML data was deleted; review the preflight and database connection, then try again.', 'simple-multilang-blocks' ) ), 10 * MINUTE_IN_SECONDS );
            wp_safe_redirect( add_query_arg( 'wpml_import_failed', '1', admin_url( 'options-general.php?page=simple-multilang-blocks' ) ) );
            exit;
        }
        self::schedule_rewrite_flush();
        delete_transient( $this->wpml_preview_key() );
        wp_safe_redirect( add_query_arg( 'imported', '1', admin_url( 'options-general.php?page=simple-multilang-blocks' ) ) );
        exit;
    }

    public function preview_wpml() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to inspect migration data.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( 'sml_preview_wpml' );
        try {
            $preview = array( 'result' => SML_WPML_Migrator::run( true ) );
        } catch ( Throwable $error ) {
            $preview = array( 'error' => __( 'WPML tables could not be inspected. No data was changed.', 'simple-multilang-blocks' ) );
        }
        set_transient( $this->wpml_preview_key(), $preview, 30 * MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( 'wpml_preflight', '1', admin_url( 'options-general.php?page=simple-multilang-blocks' ) ) );
        exit;
    }

    private function wpml_preview_key() {
        return 'sml_wpml_preflight_' . get_current_user_id();
    }

    private static function sanitize_languages( $languages ) {
        if ( ! is_array( $languages ) ) {
            return array();
        }
        $result = array();
        $default_seen = false;
        foreach ( $languages as $language ) {
            if ( ! is_array( $language ) ) {
                continue;
            }
            $slug = isset( $language['slug'] ) ? sanitize_key( $language['slug'] ) : '';
            if ( ! $slug || isset( $result[ $slug ] ) ) {
                continue;
            }
            $is_default = ! empty( $language['is_default'] ) && ! $default_seen;
            $default_seen = $default_seen || $is_default;
            $result[ $slug ] = array(
                'slug'       => $slug,
                'code'       => isset( $language['code'] ) ? sanitize_text_field( $language['code'] ) : $slug,
                'name'       => isset( $language['name'] ) ? sanitize_text_field( $language['name'] ) : strtoupper( $slug ),
                'flag'       => isset( $language['flag'] ) ? sanitize_text_field( $language['flag'] ) : self::get_language_flag( $slug ),
                'is_default' => $is_default,
            );
        }
        if ( $result && ! $default_seen ) {
            $first = key( $result );
            $result[ $first ]['is_default'] = true;
        }
        return $result;
    }

    private static function language_presets() {
        return array(
            array( 'slug' => 'et', 'code' => 'et-EE', 'name' => 'Eesti', 'flag' => '🇪🇪' ),
            array( 'slug' => 'en', 'code' => 'en-US', 'name' => 'English', 'flag' => '🇬🇧' ),
            array( 'slug' => 'ru', 'code' => 'ru-RU', 'name' => 'Русский', 'flag' => '🇷🇺' ),
            array( 'slug' => 'de', 'code' => 'de-DE', 'name' => 'Deutsch', 'flag' => '🇩🇪' ),
            array( 'slug' => 'fi', 'code' => 'fi-FI', 'name' => 'Suomi', 'flag' => '🇫🇮' ),
            array( 'slug' => 'lv', 'code' => 'lv-LV', 'name' => 'Latviešu', 'flag' => '🇱🇻' ),
            array( 'slug' => 'lt', 'code' => 'lt-LT', 'name' => 'Lietuvių', 'flag' => '🇱🇹' ),
            array( 'slug' => 'sv', 'code' => 'sv-SE', 'name' => 'Svenska', 'flag' => '🇸🇪' ),
        );
    }

    public static function create_string_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $strings = self::strings_table();
        $translations = self::string_translations_table();
        dbDelta( "CREATE TABLE {$strings} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, string_key char(64) NOT NULL, context longtext NOT NULL, name longtext NOT NULL, source_language varchar(20) NOT NULL, source_value longtext NOT NULL, PRIMARY KEY  (id), UNIQUE KEY string_key (string_key) ) {$charset};" );
        dbDelta( "CREATE TABLE {$translations} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, string_id bigint(20) unsigned NOT NULL, language varchar(20) NOT NULL, value longtext NOT NULL, status varchar(20) NOT NULL DEFAULT 'verified', updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY string_language (string_id,language), KEY language (language), KEY status (status) ) {$charset};" );

        // dbDelta does not reliably add a column to every older custom-table
        // variant, so complete this backwards-compatible migration explicitly.
        $has_status = $wpdb->get_var( "SHOW COLUMNS FROM {$translations} LIKE 'status'" );
        if ( ! $has_status ) {
            $wpdb->query( "ALTER TABLE {$translations} ADD COLUMN status varchar(20) NOT NULL DEFAULT 'verified' AFTER value" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }

    public static function strings_table() {
        global $wpdb;
        return $wpdb->prefix . 'sml_strings';
    }

    public static function string_translations_table() {
        global $wpdb;
        return $wpdb->prefix . 'sml_string_translations';
    }

    public static function register_string( $context, $name, $value ) {
        global $wpdb;
        $context = (string) $context;
        $name = (string) $name;
        $value = (string) $value;
        if ( '' === $context || '' === $name ) {
            return 0;
        }
        $key = hash( 'sha256', $context . "\0" . $name );
        if ( isset( self::$string_cache[ $key ] ) && (int) self::$string_cache[ $key ] > 0 ) {
            return (int) self::$string_cache[ $key ];
        }
        $table = self::strings_table();
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE string_key = %s", $key ) );
        if ( ! $id ) {
            $wpdb->insert( $table, array( 'string_key' => $key, 'context' => $context, 'name' => $name, 'source_language' => self::get_default_language(), 'source_value' => $value ), array( '%s', '%s', '%s', '%s', '%s' ) );
            $id = (int) $wpdb->insert_id;
        }
        self::$string_cache[ $key ] = $id;
        return $id;
    }

    public static function find_string_id( $context, $name ) {
        global $wpdb;
        $context = (string) $context;
        $name = (string) $name;
        if ( '' === $context || '' === $name ) {
            return 0;
        }
        $key = hash( 'sha256', $context . "\0" . $name );
        if ( isset( self::$string_cache[ $key ] ) ) {
            return (int) self::$string_cache[ $key ];
        }
        $id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::strings_table() . ' WHERE string_key = %s', $key ) );
        self::$string_cache[ $key ] = $id;
        return $id;
    }

    public static function update_string_source( $string_id, $source_value ) {
        global $wpdb;
        $string_id = absint( $string_id );
        $source_value = (string) $source_value;
        if ( ! $string_id || '' === trim( $source_value ) ) {
            return false;
        }
        return false !== $wpdb->update( self::strings_table(), array( 'source_value' => $source_value ), array( 'id' => $string_id ), array( '%s' ), array( '%d' ) );
    }

    public static function get_string_translation( $string_id, $language, $fallback = '' ) {
        global $wpdb;
        $string_id = absint( $string_id );
        $language = sanitize_key( $language );
        if ( ! $string_id || ! $language ) {
            return $fallback;
        }
        $cache_key = 'translation:' . $string_id . ':' . $language;
        if ( array_key_exists( $cache_key, self::$string_cache ) ) {
            return '' !== self::$string_cache[ $cache_key ] ? self::$string_cache[ $cache_key ] : $fallback;
        }
        $value = $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM ' . self::string_translations_table() . ' WHERE string_id = %d AND language = %s', $string_id, $language ) );
        self::$string_cache[ $cache_key ] = null === $value ? '' : (string) $value;
        return '' !== self::$string_cache[ $cache_key ] ? self::$string_cache[ $cache_key ] : $fallback;
    }

    public static function get_string_translation_status( $string_id, $language ) {
        global $wpdb;
        $string_id = absint( $string_id );
        $language = sanitize_key( $language );
        if ( ! $string_id || ! $language ) {
            return 'verified';
        }
        $status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . self::string_translations_table() . ' WHERE string_id = %d AND language = %s', $string_id, $language ) );
        return 'needs_review' === $status ? 'needs_review' : 'verified';
    }

    public static function save_string_translation( $string_id, $language, $value, $status = 'verified' ) {
        global $wpdb;
        $string_id = absint( $string_id );
        $language = sanitize_key( $language );
        if ( ! $string_id || ! isset( self::get_languages()[ $language ] ) ) {
            return false;
        }
        $table = self::string_translations_table();
        $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE string_id = %d AND language = %s", $string_id, $language ) );
        $data = array(
            'string_id'  => $string_id,
            'language'   => $language,
            'value'      => (string) $value,
            'status'     => 'needs_review' === $status ? 'needs_review' : 'verified',
            'updated_at' => current_time( 'mysql', true ),
        );
        $formats = array( '%d', '%s', '%s', '%s', '%s' );
        if ( $existing ) {
            $saved = false !== $wpdb->update( $table, $data, array( 'id' => $existing ), $formats, array( '%d' ) );
        } else {
            $saved = false !== $wpdb->insert( $table, $data, $formats );
        }
        if ( $saved ) {
            unset( self::$string_cache[ 'translation:' . $string_id . ':' . $language ], self::$string_cache[ $string_id . ':' . $language ] );
        }
        return $saved;
    }

    public static function translate_string( $context, $name, $fallback = '' ) {
        global $wpdb;
        $id = self::register_string( $context, $name, $fallback );
        if ( ! $id ) {
            return $fallback;
        }
        $language = self::get_current_language();
        $cache_key = $id . ':' . $language;
        if ( array_key_exists( $cache_key, self::$string_cache ) ) {
            return self::$string_cache[ $cache_key ];
        }
        self::$string_cache[ $cache_key ] = self::get_string_translation( $id, $language, $fallback );
        return self::$string_cache[ $cache_key ];
    }
}
