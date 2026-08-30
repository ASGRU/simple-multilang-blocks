<?php

defined( 'ABSPATH' ) || exit;

final class SML_Core {
    const OPTION_LANGUAGES  = 'sml_languages';
    const OPTION_POST_TYPES = 'sml_post_types';
    const OPTION_TAXONOMIES = 'sml_taxonomies';
    const OPTION_REWRITE_FLUSH = 'sml_flush_rewrite_rules';
    const OPTION_SWITCHER_PLACEMENT = 'sml_switcher_placement';
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

        add_filter( 'post_link', array( $this, 'filter_post_link' ), 20, 2 );
        add_filter( 'page_link', array( $this, 'filter_page_link' ), 20, 3 );
        add_filter( 'post_type_link', array( $this, 'filter_post_type_link' ), 20, 2 );
        add_filter( 'term_link', array( $this, 'filter_term_link' ), 20, 3 );
        add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ), 20, 2 );
        add_action( 'wp_head', array( $this, 'output_hreflang' ), 1 );
        add_filter( 'redirect_canonical', array( $this, 'prevent_language_canonical_redirect' ), 10, 2 );
        add_action( 'template_redirect', array( $this, 'redirect_noncanonical_language_url' ), 1 );
        add_shortcode( 'sml_language_switcher', array( $this, 'language_switcher_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'wp_body_open', array( $this, 'render_automatic_switcher' ), 20 );
        add_action( 'wp_footer', array( $this, 'render_automatic_switcher_fallback' ), 1 );

        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) && ! class_exists( 'SitePress' ) ) {
            add_filter( 'wpml_current_language', array( $this, 'compat_current_language' ) );
            add_filter( 'wpml_object_id', array( $this, 'compat_object_id' ), 10, 5 );
        }

        add_action( 'add_meta_boxes', array( $this, 'register_post_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_post_language' ), 20, 2 );
        add_action( 'init', array( $this, 'register_term_language_fields' ), 20 );

        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_post_sml_save_settings', array( $this, 'save_settings' ) );
        add_action( 'admin_post_sml_import_wpml', array( $this, 'import_wpml' ) );
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

        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $post_name = sanitize_title( basename( $path ) );
        if ( ! $post_name ) {
            return 0;
        }

        $sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sml_language' LEFT JOIN {$wpdb->postmeta} visible ON visible.post_id = p.ID AND visible.meta_key = '_sml_visible_in' WHERE p.post_name = %s AND ( pm.meta_value = %s OR visible.meta_value = %s ) AND p.post_type IN ({$placeholders}) AND p.post_status IN ('publish', 'private') ORDER BY ( pm.meta_value = %s ) DESC, p.ID ASC LIMIT 1";
        $values = array_merge( array( $post_name, $language, $language ), $post_types, array( $language ) );
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
    }

    private static function find_term_by_slug( $taxonomy, $slug, $language ) {
        global $wpdb;

        $sql = "SELECT DISTINCT t.term_id, t.name, t.slug, tt.taxonomy FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id INNER JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id AND tm.meta_key = '_sml_language' LEFT JOIN {$wpdb->termmeta} visible ON visible.term_id = t.term_id AND visible.meta_key = '_sml_visible_in' WHERE tt.taxonomy = %s AND t.slug = %s AND ( tm.meta_value = %s OR visible.meta_value = %s ) ORDER BY ( tm.meta_value = %s ) DESC, t.term_id ASC LIMIT 1";
        $row = $wpdb->get_row( $wpdb->prepare( $sql, $taxonomy, sanitize_title( $slug ), $language, $language, $language ) );
        return $row ? get_term( (int) $row->term_id, $taxonomy ) : false;
    }

    public function filter_main_query_language( $query ) {
        if ( is_admin() || ! $query->is_main_query() || $query->get( 'p' ) || $query->get( 'page_id' ) ) {
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

        $language = self::get_current_language();
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

    private static function add_language_to_url( $url, $language ) {
        $url = str_replace( '/./', '/', $url );
        $languages = self::get_languages();
        if ( ! $language || ! isset( $languages[ $language ] ) || $language === self::get_default_language() ) {
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

        if ( preg_match( '#^/' . preg_quote( $language, '#' ) . '(?:/|$)#', $path ) ) {
            return $url;
        }

        $localized = home_url( '/' . rawurlencode( $language ) . '/' . ltrim( $path, '/' ) );
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
                $this->print_hreflang( $language, get_permalink( $post_id ) );
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
        $atts = shortcode_atts( array( 'class' => '', 'style' => 'dropdown', 'show_name' => '1' ), $atts, 'sml_language_switcher' );
        $translations = array();
        $current = self::get_current_language();
        $links = array();

        if ( is_singular() ) {
            $post_id = get_queried_object_id();
            $translations = self::get_post_translations( $post_id );
            if ( $translations ) {
                foreach ( $translations as $language => $post_id ) {
                    $links[ $language ] = get_permalink( $post_id );
                }
            } else {
                foreach ( self::get_languages() as $language => $data ) {
                    $links[ $language ] = self::add_language_to_url( get_permalink( $post_id ), $language );
                }
            }
        } elseif ( is_tax() || is_category() || is_tag() ) {
            $term = get_queried_object();
            if ( $term && ! is_wp_error( $term ) ) {
                $translations = self::get_term_translations( $term->term_id );
                if ( $translations ) {
                    foreach ( $translations as $language => $term_id ) {
                        $translated = get_term( $term_id, $term->taxonomy );
                        if ( $translated && ! is_wp_error( $translated ) ) {
                            $links[ $language ] = get_term_link( $translated );
                        }
                    }
                } else {
                    foreach ( self::get_languages() as $language => $data ) {
                        $links[ $language ] = self::add_language_to_url( get_term_link( $term ), $language );
                    }
                }
            }
        } else {
            foreach ( self::get_languages() as $language => $data ) {
                $links[ $language ] = self::add_language_to_url( home_url( '/' ), $language );
            }
        }

        if ( ! $links ) {
            return '';
        }

        $style = in_array( $atts['style'], array( 'dropdown', 'pills', 'list' ), true ) ? $atts['style'] : 'dropdown';
        $class = trim( 'sml-language-switcher sml-language-switcher--' . $style . ' ' . sanitize_html_class( $atts['class'] ) );
        $html = '<nav class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Language selector', 'simple-multilang-blocks' ) . '"><ul>';
        foreach ( self::get_languages() as $language => $data ) {
            if ( empty( $links[ $language ] ) ) {
                continue;
            }
            $active = $language === $current ? ' class="is-active"' : '';
            $label = '<span class="sml-language-switcher__flag" aria-hidden="true">' . self::get_language_flag_html( $data ) . '</span>';
            if ( '0' !== (string) $atts['show_name'] ) {
                $label .= '<span class="sml-language-switcher__name">' . esc_html( $data['name'] ) . '</span>';
            }
            $html .= sprintf( '<li%s><a href="%s" hreflang="%s" lang="%s" aria-current="%s">%s</a></li>', $active, esc_url( $links[ $language ] ), esc_attr( $data['code'] ), esc_attr( $data['code'] ), $language === $current ? 'true' : 'false', $label );
        }
        return $html . '</ul></nav>';
    }

    public function enqueue_frontend_assets() {
        wp_enqueue_style( 'simple-multilang-blocks', plugins_url( 'assets/css/sml-frontend.css', SML_FILE ), array(), SML_VERSION );
    }

    public function render_automatic_switcher() {
        if ( 'automatic' !== get_option( self::OPTION_SWITCHER_PLACEMENT, 'automatic' ) || is_admin() ) {
            return;
        }
        echo '<div class="sml-switcher-slot">' . do_shortcode( '[sml_language_switcher style="dropdown"]' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function render_automatic_switcher_fallback() {
        if ( 'automatic' !== get_option( self::OPTION_SWITCHER_PLACEMENT, 'automatic' ) || did_action( 'wp_body_open' ) || is_admin() ) {
            return;
        }
        echo '<div class="sml-switcher-slot">' . do_shortcode( '[sml_language_switcher style="dropdown"]' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function compat_current_language( $value = null ) {
        return self::get_current_language();
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
            ?>
                <a class="button button-small" href="<?php echo esc_url( $manual_url ); ?>"><?php esc_html_e( 'Create draft', 'simple-multilang-blocks' ); ?></a>
                <a class="button button-small button-secondary" href="<?php echo esc_url( $machine_url ); ?>"><?php esc_html_e( 'Auto-translate', 'simple-multilang-blocks' ); ?></a>
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
        <tr class="form-field"><th scope="row"><label><?php esc_html_e( 'Simple Multilang', 'simple-multilang-blocks' ); ?></label></th><td><?php $this->render_term_fields( $term->term_id ); ?></td></tr>
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
            printf( '<p><label>%s <input min="1" type="number" name="sml_term_translations[%s]" value="%s"></label></p>', esc_html( $data['name'] ), esc_attr( $slug ), esc_attr( isset( $translations[ $slug ] ) ? (int) $translations[ $slug ] : '' ) );
        }
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

    public static function sync_post_translations( $translations ) {
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
    }

    public static function sync_term_translations( $translations ) {
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
        ?>
        <div class="wrap sml-admin-wrap"><h1><?php esc_html_e( 'Simple Multilang settings', 'simple-multilang-blocks' ); ?></h1>
        <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Settings saved. Rewrite rules were refreshed.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>
        <?php if ( isset( $_GET['imported'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'WPML data was imported. Verify representative pages, categories and strings before uninstalling WPML files.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>
        <p><a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=sml-theme-strings' ) ); ?>"><?php esc_html_e( 'Translate theme strings', 'simple-multilang-blocks' ); ?></a></p>
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
        <h2><?php esc_html_e( 'Language switcher', 'simple-multilang-blocks' ); ?></h2><p><label><input type="radio" name="sml_switcher_placement" value="automatic" <?php checked( 'automatic', get_option( self::OPTION_SWITCHER_PLACEMENT, 'automatic' ) ); ?>> <?php esc_html_e( 'Show a floating switcher automatically', 'simple-multilang-blocks' ); ?></label><br><label><input type="radio" name="sml_switcher_placement" value="shortcode" <?php checked( 'shortcode', get_option( self::OPTION_SWITCHER_PLACEMENT, 'automatic' ) ); ?>> <?php esc_html_e( 'Use shortcode only', 'simple-multilang-blocks' ); ?></label></p><p class="description"><code>[sml_language_switcher style="dropdown"]</code> <?php esc_html_e( 'can be placed in any block, menu template or widget.', 'simple-multilang-blocks' ); ?></p>
        <h2><?php esc_html_e( 'Automatic translation', 'simple-multilang-blocks' ); ?></h2><p><label><?php esc_html_e( 'Provider', 'simple-multilang-blocks' ); ?> <select name="sml_translation_provider"><option value="" <?php selected( '', SML_Translation_Service::provider() ); ?>><?php esc_html_e( 'Disabled', 'simple-multilang-blocks' ); ?></option><option value="deepl" <?php selected( 'deepl', SML_Translation_Service::provider() ); ?>>DeepL</option><option value="openai" <?php selected( 'openai', SML_Translation_Service::provider() ); ?>>OpenAI</option></select></label></p><p><label><?php esc_html_e( 'OpenAI model', 'simple-multilang-blocks' ); ?> <input type="text" name="sml_openai_model" value="<?php echo esc_attr( get_option( SML_Translation_Service::OPTION_OPENAI_MODEL, 'gpt-5-mini' ) ); ?>"></label></p><p><label><?php esc_html_e( 'DeepL endpoint', 'simple-multilang-blocks' ); ?> <select name="sml_deepl_endpoint"><option value="free" <?php selected( 'free', get_option( SML_Translation_Service::OPTION_DEEPL_ENDPOINT, 'free' ) ); ?>>api-free.deepl.com</option><option value="pro" <?php selected( 'pro', get_option( SML_Translation_Service::OPTION_DEEPL_ENDPOINT, 'free' ) ); ?>>api.deepl.com</option></select></label></p><div class="notice notice-info inline"><p><?php esc_html_e( 'API keys are never stored in WordPress. Add either SML_DEEPL_API_KEY or SML_OPENAI_API_KEY to wp-config.php. Generated content is always a draft marked “Requires review”; if the service is unavailable, no draft is created and no public-page error is shown.', 'simple-multilang-blocks' ); ?></p></div>
        <p><button class="button button-primary"><?php esc_html_e( 'Save settings', 'simple-multilang-blocks' ); ?></button></p></form>
        <hr><h2><?php esc_html_e( 'WPML migration', 'simple-multilang-blocks' ); ?></h2><p><?php esc_html_e( 'Imports active languages, selected posts, selected taxonomies and String Translation values. It never deletes WPML tables or options.', 'simple-multilang-blocks' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sml_import_wpml' ); ?><input type="hidden" name="action" value="sml_import_wpml"><label><input required type="checkbox" name="sml_import_confirm" value="1"> <?php esc_html_e( 'I have a database backup and want to import now.', 'simple-multilang-blocks' ); ?></label><p><button class="button"> <?php esc_html_e( 'Import WPML data', 'simple-multilang-blocks' ); ?></button></p></form></div>
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
        update_option( SML_Translation_Service::OPTION_PROVIDER, isset( $_POST['sml_translation_provider'] ) && in_array( sanitize_key( wp_unslash( $_POST['sml_translation_provider'] ) ), array( 'deepl', 'openai' ), true ) ? sanitize_key( wp_unslash( $_POST['sml_translation_provider'] ) ) : '' );
        update_option( SML_Translation_Service::OPTION_OPENAI_MODEL, isset( $_POST['sml_openai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['sml_openai_model'] ) ) : 'gpt-5-mini' );
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
        SML_WPML_Migrator::run( false );
        self::schedule_rewrite_flush();
        wp_safe_redirect( add_query_arg( 'imported', '1', admin_url( 'options-general.php?page=simple-multilang-blocks' ) ) );
        exit;
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
        if ( isset( self::$string_cache[ $key ] ) ) {
            return self::$string_cache[ $key ];
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
            return false !== $wpdb->update( $table, $data, array( 'id' => $existing ), $formats, array( '%d' ) );
        }
        return false !== $wpdb->insert( $table, $data, $formats );
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
