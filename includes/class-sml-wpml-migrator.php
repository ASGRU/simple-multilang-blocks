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
            'posts'               => 0,
            'term_groups'         => 0,
            'terms'               => 0,
            'strings'             => 0,
            'string_translations' => 0,
        );

        $languages = self::migrate_languages( $languages_table, $dry_run );
        $result['languages'] = count( $languages );
        if ( ! $languages ) {
            throw new RuntimeException( 'WPML has no active languages to import.' );
        }

        $default_language = self::get_default_language( $languages );
        $post_result = self::migrate_posts( $translations_table, array_keys( $languages ), $default_language, $dry_run );
        $term_result = self::migrate_terms( $translations_table, array_keys( $languages ), $default_language, $dry_run );
        $result = array_merge( $result, $post_result, $term_result );

        if ( self::table_exists( $strings_table ) && self::table_exists( $string_translations_table ) ) {
            $result = array_merge( $result, self::migrate_strings( $strings_table, $string_translations_table, array_keys( $languages ), $dry_run ) );
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

    private static function migrate_languages( $table, $dry_run ) {
        global $wpdb;
        $settings = get_option( 'icl_sitepress_settings', array() );
        $default = ! empty( $settings['default_language'] ) ? sanitize_key( $settings['default_language'] ) : '';
        $rows = $wpdb->get_results( "SELECT code, english_name, default_locale FROM {$table} WHERE active = 1 ORDER BY code", ARRAY_A );
        $languages = array();
        foreach ( $rows as $row ) {
            $slug = sanitize_key( $row['code'] );
            if ( ! $slug ) {
                continue;
            }
            $languages[ $slug ] = array(
                'slug'       => $slug,
                'code'       => str_replace( '_', '-', sanitize_text_field( $row['default_locale'] ) ),
                'name'       => sanitize_text_field( $row['english_name'] ),
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
        $public = get_post_types( array( 'public' => true ), 'names' );
        unset( $public['attachment'] );
        if ( ! $public ) {
            return array( 'post_groups' => 0, 'posts' => 0 );
        }

        $element_types = array();
        foreach ( $public as $post_type ) {
            $element_types[] = 'post_' . $post_type;
        }
        $placeholders = implode( ',', array_fill( 0, count( $element_types ), '%s' ) );
        $sql = "SELECT tr.trid, tr.element_type, tr.element_id, tr.language_code FROM {$table} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.element_id WHERE tr.element_type IN ({$placeholders}) AND p.post_type IN (" . implode( ',', array_fill( 0, count( $public ), '%s' ) ) . ')';
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $element_types, array_values( $public ) ) ), ARRAY_A );
        $groups = self::group_rows( $rows, $languages );
        $post_types = array_values( $public );

        if ( ! $dry_run ) {
            update_option( SML_Core::OPTION_POST_TYPES, $post_types );
            foreach ( $groups as $translations ) {
                SML_Core::sync_post_translations( $translations );
            }
        }

        $fallback_posts = $dry_run ? self::count_unmapped_posts( $post_types ) : self::backfill_unmapped_posts( $post_types, $default_language );

        return array(
            'post_groups'    => count( $groups ),
            'posts'          => self::count_grouped_objects( $groups ) + $fallback_posts,
            'fallback_posts' => $fallback_posts,
        );
    }

    private static function migrate_terms( $table, $languages, $default_language, $dry_run ) {
        global $wpdb;
        $taxonomies = get_taxonomies( array( 'show_ui' => true ), 'names' );
        if ( ! $taxonomies ) {
            return array( 'term_groups' => 0, 'terms' => 0 );
        }
        $element_types = array();
        foreach ( $taxonomies as $taxonomy ) {
            $element_types[] = 'tax_' . $taxonomy;
        }
        $placeholders = implode( ',', array_fill( 0, count( $element_types ), '%s' ) );
        $sql = "SELECT tr.trid, tr.element_type, tt.term_id AS element_id, tr.language_code FROM {$table} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.element_id WHERE tr.element_type IN ({$placeholders})";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $element_types ), ARRAY_A );
        $groups = self::group_rows( $rows, $languages );

        if ( ! $dry_run ) {
            update_option( SML_Core::OPTION_TAXONOMIES, array_values( $taxonomies ) );
            foreach ( $groups as $translations ) {
                SML_Core::sync_term_translations( $translations );
            }
        }

        $fallback_terms = $dry_run ? self::count_unmapped_terms( $taxonomies ) : self::backfill_unmapped_terms( $taxonomies, $default_language );

        return array(
            'term_groups'   => count( $groups ),
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

    private static function count_grouped_objects( $groups ) {
        $count = 0;
        foreach ( $groups as $group ) {
            $count += count( $group );
        }
        return $count;
    }

    private static function migrate_strings( $strings_table, $translations_table, $languages, $dry_run ) {
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
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$destination} (string_key, context, name, source_language, source_value) VALUES (%s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE source_language = VALUES(source_language), source_value = VALUES(source_value)", $key, $context, $name, sanitize_key( $row['language'] ), (string) $row['value'] ) );
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
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$destination_translations} (string_id, language, value, updated_at) VALUES (%d, %s, %s, %s) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)", $string_id, $language, (string) $row['value'], current_time( 'mysql', true ) ) );
            ++$result['string_translations'];
        }

        return $result;
    }
}
