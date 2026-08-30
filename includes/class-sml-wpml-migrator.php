<?php

defined( 'ABSPATH' ) || exit;

final class SML_WPML_Migrator {
    public static function run( $dry_run = true ) {
        global $wpdb;

        $translations_table = $wpdb->prefix . 'icl_translations';
        $languages_table = $wpdb->prefix . 'icl_languages';
        $strings_table = $wpdb->prefix . 'icl_strings';
        $string_translations_table = $wpdb->prefix . 'icl_string_translations';

        if ( ! self::table_exists( $translations_table ) || ! self::table_exists( $languages_table ) ) {
            throw new RuntimeException( 'WPML translation tables were not found.' );
        }

        $result = array(
            'languages'           => 0,
            'post_groups'         => 0,
            'linked_post_groups'  => 0,
            'unlinked_post_groups'=> 0,
            'special_post_groups' => 0,
            'posts'               => 0,
            'term_groups'         => 0,
            'linked_term_groups'  => 0,
            'unlinked_term_groups'=> 0,
            'menu_groups'         => 0,
            'terms'               => 0,
            'strings'             => 0,
            'string_translations' => 0,
        );

        $settings = get_option( 'icl_sitepress_settings', array() );
        $languages = self::migrate_languages( $languages_table, $settings, $dry_run );
        $result['languages'] = count( $languages );
        if ( ! $languages ) {
            throw new RuntimeException( 'WPML has no active languages to import.' );
        }

        $default_language = self::get_default_language( $languages );
        $post_result = self::migrate_posts( $translations_table, array_keys( $languages ), $default_language, $dry_run );
        $term_result = self::migrate_terms( $translations_table, array_keys( $languages ), $default_language, $dry_run );
        $result = array_merge( $result, $post_result, $term_result );

        if ( self::table_exists( $strings_table ) && self::table_exists( $string_translations_table ) ) {
            $result = array_merge( $result, self::migrate_strings( $strings_table, $string_translations_table, array_keys( $languages ), $default_language, $dry_run ) );
        }

        if ( ! $dry_run ) {
            self::migrate_supported_settings( $settings, $languages, $default_language, $result );
        }

        return $result;
    }

    private static function get_default_language( $languages ) {
        foreach ( $languages as $slug => $language ) {
            if ( ! empty( $language['is_default'] ) ) {
                return $slug;
            }
        }

        return (string) key( $languages );
    }

    private static function table_exists( $table ) {
        global $wpdb;
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    private static function migrate_languages( $table, $settings, $dry_run ) {
        global $wpdb;
        $default = ! empty( $settings['default_language'] ) ? sanitize_key( $settings['default_language'] ) : '';
        $fallbacks = SML_Core::default_languages();
        $rows = $wpdb->get_results( "SELECT code, english_name, default_locale FROM {$table} WHERE active = 1 ORDER BY code", ARRAY_A );
        $languages = array();
        foreach ( $rows as $row ) {
            $slug = sanitize_key( $row['code'] );
            if ( ! $slug ) {
                continue;
            }
            $code = str_replace( '_', '-', sanitize_text_field( $row['default_locale'] ) );
            if ( ! preg_match( '/^[a-z]{2,3}-[A-Z]{2}$/', $code ) && ! empty( $fallbacks[ $slug ]['code'] ) ) {
                $code = $fallbacks[ $slug ]['code'];
            }
            $languages[ $slug ] = array(
                'slug'       => $slug,
                'code'       => $code,
                'name'       => ! empty( $fallbacks[ $slug ]['name'] ) ? $fallbacks[ $slug ]['name'] : sanitize_text_field( $row['english_name'] ),
                'flag'       => SML_Core::get_language_flag( $slug ),
                'is_default' => $slug === $default,
            );
        }
        if ( $languages && ! $default ) {
            $first = key( $languages );
            $languages[ $first ]['is_default'] = true;
        }
        if ( ! $dry_run && $languages ) {
            update_option( SML_Core::OPTION_LANGUAGES, $languages );
        }
        return $languages;
    }

    private static function migrate_posts( $table, $languages, $default_language, $dry_run ) {
        global $wpdb;
        $public = self::get_routable_post_types();
        $special = self::get_related_post_types();
        $all_post_types = array_values( array_unique( array_merge( $public, $special ) ) );
        if ( ! $all_post_types ) {
            return array( 'post_groups' => 0, 'linked_post_groups' => 0, 'unlinked_post_groups' => 0, 'special_post_groups' => 0, 'posts' => 0 );
        }

        $element_types = array();
        foreach ( $all_post_types as $post_type ) {
            $element_types[] = 'post_' . $post_type;
        }
        $placeholders = implode( ',', array_fill( 0, count( $element_types ), '%s' ) );
        $sql = "SELECT tr.trid, tr.element_type, tr.element_id, tr.language_code FROM {$table} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.element_id WHERE tr.element_type IN ({$placeholders}) AND p.post_type IN (" . implode( ',', array_fill( 0, count( $all_post_types ), '%s' ) ) . ')';
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $element_types, $all_post_types ) ), ARRAY_A );
        $groups = self::group_rows( $rows, $languages );
        $special_groups = self::filter_related_groups( $groups, $special );
        $public_groups = self::filter_groups_for_post_types( $groups, $public );
        $linked_public_groups = self::filter_linked_groups( $public_groups );
        $groups_to_sync = array_merge( $public_groups, $special_groups );

        if ( ! $dry_run ) {
            update_option( SML_Core::OPTION_POST_TYPES, array_values( $public ) );
            foreach ( $groups_to_sync as $translations ) {
                SML_Core::sync_post_translations( $translations, false );
            }
            SML_Core::sync_all_post_hierarchy( $public );
        }

        $fallback_posts = $dry_run ? self::count_unmapped_posts( $public ) : self::backfill_unmapped_posts( $public, $default_language );

        return array(
            'post_groups'    => count( $public_groups ),
            'linked_post_groups' => count( $linked_public_groups ),
            'unlinked_post_groups' => count( $public_groups ) - count( $linked_public_groups ),
            'special_post_groups' => count( $special_groups ),
            'posts'          => self::count_grouped_objects( $groups_to_sync ) + $fallback_posts,
            'fallback_posts' => $fallback_posts,
        );
    }

    private static function migrate_terms( $table, $languages, $default_language, $dry_run ) {
        global $wpdb;
        $taxonomies = self::get_migratable_taxonomies();
        if ( ! $taxonomies ) {
            return array( 'term_groups' => 0, 'linked_term_groups' => 0, 'unlinked_term_groups' => 0, 'terms' => 0 );
        }
        $element_types = array();
        foreach ( $taxonomies as $taxonomy ) {
            $element_types[] = 'tax_' . $taxonomy;
        }
        $placeholders = implode( ',', array_fill( 0, count( $element_types ), '%s' ) );
        $sql = "SELECT tr.trid, tr.element_type, tt.term_id AS element_id, tr.language_code FROM {$table} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.element_id WHERE tr.element_type IN ({$placeholders})";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $element_types ), ARRAY_A );
        $groups = self::group_rows( $rows, $languages );
        $linked_groups = self::filter_linked_groups( $groups );
        $menu_groups = self::filter_groups_for_taxonomies( $groups, array( 'nav_menu' ) );

        if ( ! $dry_run ) {
            update_option( SML_Core::OPTION_TAXONOMIES, array_values( $taxonomies ) );
            foreach ( $groups as $translations ) {
                SML_Core::sync_term_translations( $translations, false );
            }
            SML_Core::sync_all_term_hierarchy( $taxonomies );
        }

        $fallback_terms = $dry_run ? self::count_unmapped_terms( $taxonomies ) : self::backfill_unmapped_terms( $taxonomies, $default_language );

        return array(
            'term_groups'   => count( $groups ),
            'linked_term_groups' => count( $linked_groups ),
            'unlinked_term_groups' => count( $groups ) - count( $linked_groups ),
            'menu_groups'   => count( $menu_groups ),
            'terms'         => self::count_grouped_objects( $groups ) + $fallback_terms,
            'fallback_terms'=> $fallback_terms,
        );
    }

    private static function unmapped_post_ids( $post_types ) {
        return get_posts(
            array(
                'post_type'        => $post_types,
                'post_status'      => array( 'publish', 'private' ),
                'posts_per_page'   => -1,
                'fields'           => 'ids',
                'suppress_filters' => true,
                'meta_query'       => array(
                    array(
                        'key'     => '_sml_language',
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            )
        );
    }

    private static function count_unmapped_posts( $post_types ) {
        return count( self::unmapped_post_ids( $post_types ) );
    }

    private static function backfill_unmapped_posts( $post_types, $default_language ) {
        $ids = self::unmapped_post_ids( $post_types );
        foreach ( $ids as $post_id ) {
            SML_Core::sync_post_translations( array( $default_language => (int) $post_id ) );
        }

        return count( $ids );
    }

    private static function unmapped_term_ids( $taxonomies ) {
        $terms = get_terms(
            array(
                'taxonomy'   => $taxonomies,
                'hide_empty' => false,
                'fields'     => 'ids',
                'meta_query' => array(
                    array(
                        'key'     => '_sml_language',
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            )
        );

        return is_wp_error( $terms ) ? array() : array_map( 'absint', $terms );
    }

    private static function count_unmapped_terms( $taxonomies ) {
        return count( self::unmapped_term_ids( $taxonomies ) );
    }

    private static function backfill_unmapped_terms( $taxonomies, $default_language ) {
        $ids = self::unmapped_term_ids( $taxonomies );
        foreach ( $ids as $term_id ) {
            SML_Core::sync_term_translations( array( $default_language => (int) $term_id ) );
        }

        return count( $ids );
    }

    private static function group_rows( $rows, $languages ) {
        $groups = array();
        foreach ( $rows as $row ) {
            $language = sanitize_key( $row['language_code'] );
            $object_id = absint( $row['element_id'] );
            $trid = absint( $row['trid'] );
            if ( ! $object_id || ! $trid || ! in_array( $language, $languages, true ) ) {
                continue;
            }
            $group_key = ( isset( $row['element_type'] ) ? sanitize_key( $row['element_type'] ) : '' ) . ':' . $trid;
            $groups[ $group_key ][ $language ] = $object_id;
        }
        return $groups;
    }

    private static function get_routable_post_types() {
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        unset( $post_types['attachment'] );
        return array_values( $post_types );
    }

    private static function get_related_post_types() {
        $supported = array( 'product_variation', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_block' );
        $existing = get_post_types( array(), 'names' );
        return array_values( array_intersect( $supported, $existing ) );
    }

    private static function get_migratable_taxonomies() {
        $taxonomies = get_taxonomies( array( 'show_ui' => true ), 'names' );
        if ( taxonomy_exists( 'nav_menu' ) ) {
            $taxonomies[] = 'nav_menu';
        }
        $excluded = array( 'product_visibility', 'translation_priority', 'wp_theme', 'wp_template_part_area' );
        return array_values( array_diff( array_unique( $taxonomies ), $excluded ) );
    }

    private static function filter_groups_for_post_types( $groups, $post_types ) {
        $result = array();
        foreach ( $groups as $key => $group ) {
            $element_type = strtok( $key, ':' );
            if ( 0 === strpos( $element_type, 'post_' ) && in_array( substr( $element_type, 5 ), $post_types, true ) ) {
                $result[ $key ] = $group;
            }
        }
        return $result;
    }

    private static function filter_groups_for_taxonomies( $groups, $taxonomies ) {
        $result = array();
        foreach ( $groups as $key => $group ) {
            $element_type = strtok( $key, ':' );
            if ( 0 === strpos( $element_type, 'tax_' ) && in_array( substr( $element_type, 4 ), $taxonomies, true ) ) {
                $result[ $key ] = $group;
            }
        }
        return $result;
    }

    private static function filter_related_groups( $groups, $post_types ) {
        return self::filter_linked_groups( self::filter_groups_for_post_types( $groups, $post_types ) );
    }

    private static function filter_linked_groups( $groups ) {
        foreach ( $groups as $key => $group ) {
            // A singleton has no translated counterpart; its language label is
            // still imported, but no relationship can be reconstructed.
            if ( count( $group ) < 2 ) {
                unset( $groups[ $key ] );
            }
        }
        return $groups;
    }

    private static function count_grouped_objects( $groups ) {
        $count = 0;
        foreach ( $groups as $group ) {
            $count += count( $group );
        }
        return $count;
    }

    private static function migrate_strings( $strings_table, $translations_table, $languages, $default_language, $dry_run ) {
        global $wpdb;
        $result = array( 'strings' => 0, 'string_translations' => 0 );
        $source_rows = $wpdb->get_results( "SELECT id, language, context, name, value FROM {$strings_table}", ARRAY_A );
        $mapped_ids = array();
        $destination = SML_Core::strings_table();
        if ( ! $dry_run ) {
            SML_Core::create_string_tables();
        }

        foreach ( $source_rows as $row ) {
            $context = (string) $row['context'];
            $name = (string) $row['name'];
            if ( '' === $context || '' === $name ) {
                continue;
            }
            ++$result['strings'];
            if ( $dry_run ) {
                continue;
            }
            $key = hash( 'sha256', $context . "\0" . $name );
            $source_language = sanitize_key( $row['language'] );
            if ( ! in_array( $source_language, $languages, true ) ) {
                $source_language = $default_language;
            }
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$destination} (string_key, context, name, source_language, source_value) VALUES (%s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE source_language = VALUES(source_language), source_value = VALUES(source_value)", $key, $context, $name, $source_language, (string) $row['value'] ) );
            $mapped_ids[ (int) $row['id'] ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$destination} WHERE string_key = %s", $key ) );
        }

        $source_translation_rows = $wpdb->get_results( "SELECT string_id, language, value FROM {$translations_table} WHERE value IS NOT NULL AND value != ''", ARRAY_A );
        if ( $dry_run ) {
            foreach ( $source_translation_rows as $row ) {
                if ( in_array( sanitize_key( $row['language'] ), $languages, true ) ) {
                    ++$result['string_translations'];
                }
            }
            return $result;
        }

        $destination_translations = SML_Core::string_translations_table();
        foreach ( $source_translation_rows as $row ) {
            $language = sanitize_key( $row['language'] );
            $string_id = isset( $mapped_ids[ (int) $row['string_id'] ] ) ? $mapped_ids[ (int) $row['string_id'] ] : 0;
            if ( ! $string_id || ! in_array( $language, $languages, true ) ) {
                continue;
            }
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$destination_translations} (string_id, language, value, status, updated_at) VALUES (%d, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE value = VALUES(value), status = VALUES(status), updated_at = VALUES(updated_at)", $string_id, $language, (string) $row['value'], 'verified', current_time( 'mysql', true ) ) );
            ++$result['string_translations'];
        }

        return $result;
    }

    /**
     * Records only the WPML settings that SML can safely preserve. URL mode,
     * add-ons and WPML's internal custom-field rules deliberately stay owned by
     * WPML; SML has one predictable language-folder routing model.
     */
    private static function migrate_supported_settings( $settings, $languages, $default_language, $result ) {
        $custom_types = ! empty( $settings['custom_types_translation'] ) && is_array( $settings['custom_types_translation'] ) ? $settings['custom_types_translation'] : array();
        $taxonomies = ! empty( $settings['taxonomies_translation'] ) && is_array( $settings['taxonomies_translation'] ) ? $settings['taxonomies_translation'] : array();

        update_option(
            'sml_wpml_migration_summary',
            array(
                'imported_at'                    => current_time( 'mysql', true ),
                'source_default_language'         => $default_language,
                'active_languages'                => array_keys( $languages ),
                'source_language_negotiation'     => isset( $settings['language_negotiation_type'] ) ? absint( $settings['language_negotiation_type'] ) : 0,
                'source_custom_post_type_rules'   => array_keys( $custom_types ),
                'source_taxonomy_rules'           => array_keys( $taxonomies ),
                'url_mode'                        => 'sml-language-folders',
                'last_import_counts'              => array_intersect_key( $result, array_flip( array( 'post_groups', 'linked_post_groups', 'term_groups', 'linked_term_groups', 'strings', 'string_translations' ) ) ),
            ),
            false
        );
    }
}
