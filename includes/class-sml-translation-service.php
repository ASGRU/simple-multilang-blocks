<?php

defined( 'ABSPATH' ) || exit;

/**
 * Creates linked drafts and talks to an explicitly configured translation API.
 *
 * API credentials are deliberately read from wp-config.php constants only. This
 * keeps credentials out of the database, exports and WordPress admin screens.
 */
final class SML_Translation_Service {
    const OPTION_PROVIDER       = 'sml_translation_provider';
    const OPTION_OPENAI_MODEL   = 'sml_openai_model';
    const OPTION_DEEPL_ENDPOINT = 'sml_deepl_endpoint';

    public static function provider() {
        $provider = (string) get_option( self::OPTION_PROVIDER, '' );
        return in_array( $provider, array( 'deepl', 'openai' ), true ) ? $provider : '';
    }

    public static function provider_label() {
        $provider = self::provider();
        if ( 'deepl' === $provider ) {
            return 'DeepL';
        }
        if ( 'openai' === $provider ) {
            return 'OpenAI';
        }
        return __( 'Not configured', 'simple-multilang-blocks' );
    }

    public static function is_available() {
        if ( 'deepl' === self::provider() ) {
            return defined( 'SML_DEEPL_API_KEY' ) && '' !== trim( (string) SML_DEEPL_API_KEY );
        }

        if ( 'openai' === self::provider() ) {
            return defined( 'SML_OPENAI_API_KEY' ) && '' !== trim( (string) SML_OPENAI_API_KEY );
        }

        return false;
    }

    /**
     * Creates a linked manual draft. No remote request is made.
     *
     * @return int|WP_Error
     */
    public static function create_manual_draft( $source_post_id, $target_language ) {
        return self::create_draft( $source_post_id, $target_language, array(), 'manual' );
    }

    /**
     * Generates a linked draft. The provider is contacted before a draft is
     * created, so an unavailable provider never leaves an unwanted duplicate.
     *
     * @return int|WP_Error
     */
    public static function create_machine_draft( $source_post_id, $target_language ) {
        $source_post_id = absint( $source_post_id );
        $target_language = sanitize_key( $target_language );
        $source = get_post( $source_post_id );

        if ( ! $source || ! in_array( $source->post_type, SML_Core::get_post_types(), true ) ) {
            return new WP_Error( 'sml_source_missing', __( 'The source content is unavailable.', 'simple-multilang-blocks' ) );
        }

        $source_language = SML_Core::get_post_language( $source_post_id );
        if ( $source_language === $target_language ) {
            return new WP_Error( 'sml_same_language', __( 'Choose a different target language.', 'simple-multilang-blocks' ) );
        }

        $translated = self::translate_fields(
            array(
                'title'   => (string) $source->post_title,
                'excerpt' => (string) $source->post_excerpt,
                'content' => (string) $source->post_content,
            ),
            $source_language,
            $target_language
        );

        if ( is_wp_error( $translated ) ) {
            return $translated;
        }

        return self::create_draft( $source_post_id, $target_language, $translated, self::provider() );
    }

    /**
     * @return int|WP_Error
     */
    private static function create_draft( $source_post_id, $target_language, $translated, $provider ) {
        $source_post_id = absint( $source_post_id );
        $target_language = sanitize_key( $target_language );
        $source = get_post( $source_post_id );
        $languages = SML_Core::get_languages();

        if ( ! $source || ! isset( $languages[ $target_language ] ) ) {
            return new WP_Error( 'sml_invalid_target', __( 'The requested target language is unavailable.', 'simple-multilang-blocks' ) );
        }

        $translations = SML_Core::get_post_translations( $source_post_id );
        if ( ! $translations ) {
            $translations[ SML_Core::get_post_language( $source_post_id ) ] = $source_post_id;
        }
        if ( ! empty( $translations[ $target_language ] ) ) {
            return new WP_Error( 'sml_translation_exists', __( 'A translation already exists for this language.', 'simple-multilang-blocks' ), array( 'post_id' => absint( $translations[ $target_language ] ) ) );
        }

        $post_data = array(
            'post_type'    => $source->post_type,
            'post_status'  => 'draft',
            'post_title'   => isset( $translated['title'] ) ? sanitize_text_field( $translated['title'] ) : $source->post_title,
            'post_content' => isset( $translated['content'] ) ? wp_kses_post( $translated['content'] ) : $source->post_content,
            'post_excerpt' => isset( $translated['excerpt'] ) ? wp_kses_post( $translated['excerpt'] ) : $source->post_excerpt,
            'post_author'  => get_current_user_id() ? get_current_user_id() : $source->post_author,
            'post_parent'  => $source->post_parent,
            'menu_order'   => $source->menu_order,
        );
        $new_post_id = wp_insert_post( wp_slash( $post_data ), true );

        if ( is_wp_error( $new_post_id ) || ! $new_post_id ) {
            return is_wp_error( $new_post_id ) ? $new_post_id : new WP_Error( 'sml_create_failed', __( 'The translation draft could not be created.', 'simple-multilang-blocks' ) );
        }

        self::copy_post_data( $source_post_id, $new_post_id, $target_language );

        $translations[ $target_language ] = (int) $new_post_id;
        SML_Core::sync_post_translations( $translations );

        update_post_meta( $new_post_id, '_sml_translation_status', 'manual' === $provider ? 'draft' : 'needs_review' );
        update_post_meta( $new_post_id, '_sml_translation_source', $source_post_id );
        update_post_meta( $new_post_id, '_sml_translation_provider', sanitize_key( $provider ) );
        update_post_meta( $new_post_id, '_sml_translation_created_at', current_time( 'mysql', true ) );

        return (int) $new_post_id;
    }

    private static function copy_post_data( $source_post_id, $target_post_id, $target_language ) {
        $meta = get_post_meta( $source_post_id );
        $ignored = array(
            '_sml_language',
            '_sml_translations',
            '_sml_visible_in',
            '_sml_translation_status',
            '_sml_translation_source',
            '_sml_translation_provider',
            '_sml_translation_created_at',
            '_edit_lock',
            '_edit_last',
        );

        foreach ( $meta as $key => $values ) {
            if ( in_array( $key, $ignored, true ) || 0 === strpos( $key, '_sml_' ) ) {
                continue;
            }
            foreach ( (array) $values as $value ) {
                add_post_meta( $target_post_id, $key, maybe_unserialize( $value ) );
            }
        }

        foreach ( get_object_taxonomies( get_post_type( $source_post_id ) ) as $taxonomy ) {
            $term_ids = wp_get_object_terms( $source_post_id, $taxonomy, array( 'fields' => 'ids' ) );
            if ( is_wp_error( $term_ids ) ) {
                continue;
            }
            $mapped = array();
            foreach ( $term_ids as $term_id ) {
                $term_translations = SML_Core::get_term_translations( $term_id );
                $mapped[] = ! empty( $term_translations[ $target_language ] ) ? absint( $term_translations[ $target_language ] ) : absint( $term_id );
            }
            wp_set_object_terms( $target_post_id, $mapped, $taxonomy, false );
        }
    }

    /**
     * @return array|WP_Error
     */
    public static function translate_fields( $fields, $source_language, $target_language ) {
        $source_language = sanitize_key( $source_language );
        $target_language = sanitize_key( $target_language );
        $languages = SML_Core::get_languages();

        if ( ! isset( $languages[ $source_language ], $languages[ $target_language ] ) || $source_language === $target_language ) {
            return new WP_Error( 'sml_invalid_language', __( 'The language pair is invalid.', 'simple-multilang-blocks' ) );
        }
        if ( ! self::is_available() ) {
            return new WP_Error( 'sml_provider_unavailable', __( 'The selected translation service is unavailable. No draft was created; you can create a manual draft instead.', 'simple-multilang-blocks' ) );
        }

        if ( 'deepl' === self::provider() ) {
            return self::translate_with_deepl( $fields, $source_language, $target_language );
        }
        if ( 'openai' === self::provider() ) {
            return self::translate_with_openai( $fields, $source_language, $target_language );
        }

        return new WP_Error( 'sml_provider_missing', __( 'Select DeepL or OpenAI in Simple Multilang settings.', 'simple-multilang-blocks' ) );
    }

    /**
     * DeepL supports HTML tags, so content and excerpts keep their block markup.
     *
     * @return array|WP_Error
     */
    private static function translate_with_deepl( $fields, $source_language, $target_language ) {
        $languages = SML_Core::get_languages();
        $endpoint = 'pro' === get_option( self::OPTION_DEEPL_ENDPOINT, 'free' ) ? 'https://api.deepl.com/v2/translate' : 'https://api-free.deepl.com/v2/translate';
        $body = array(
            'auth_key'    => (string) SML_DEEPL_API_KEY,
            'target_lang' => self::deepl_code( $languages[ $target_language ]['code'], true ),
            'tag_handling' => 'html',
        );
        $source_code = self::deepl_code( $languages[ $source_language ]['code'], false );
        if ( $source_code ) {
            $body['source_lang'] = $source_code;
        }

        $keys = array_keys( $fields );
        foreach ( $keys as $key ) {
            $body['text'][] = (string) $fields[ $key ];
        }
        $response = wp_remote_post( $endpoint, array(
            'timeout' => 25,
            'headers' => array( 'Accept' => 'application/json' ),
            'body'    => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'sml_deepl_connection', __( 'DeepL is temporarily unavailable. No draft was created.', 'simple-multilang-blocks' ) );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status < 200 || $status >= 300 || empty( $data['translations'] ) || ! is_array( $data['translations'] ) ) {
            return new WP_Error( 'sml_deepl_response', __( 'DeepL could not complete the translation. No draft was created.', 'simple-multilang-blocks' ) );
        }

        $result = array();
        foreach ( $keys as $index => $key ) {
            if ( ! isset( $data['translations'][ $index ]['text'] ) ) {
                return new WP_Error( 'sml_deepl_incomplete', __( 'DeepL returned an incomplete translation. No draft was created.', 'simple-multilang-blocks' ) );
            }
            $result[ $key ] = (string) $data['translations'][ $index ]['text'];
        }
        return $result;
    }

    /**
     * Uses the current Responses API. The response is not stored by this plugin.
     *
     * @return array|WP_Error
     */
    private static function translate_with_openai( $fields, $source_language, $target_language ) {
        $languages = SML_Core::get_languages();
        $model = defined( 'SML_OPENAI_MODEL' ) ? (string) SML_OPENAI_MODEL : (string) get_option( self::OPTION_OPENAI_MODEL, 'gpt-5-mini' );
        $model = sanitize_text_field( $model );
        if ( '' === $model ) {
            return new WP_Error( 'sml_openai_model', __( 'An OpenAI model must be configured before translating.', 'simple-multilang-blocks' ) );
        }

        $instructions = sprintf(
            'Translate the supplied WordPress content from %1$s to %2$s. Preserve HTML tags, Gutenberg block comments, placeholders, URLs, shortcodes, product codes and line breaks. Do not add commentary. Return one JSON object with exactly the keys title, excerpt and content; use empty strings for empty source fields.',
            $languages[ $source_language ]['name'],
            $languages[ $target_language ]['name']
        );
        $payload = array(
            'model'        => $model,
            'store'        => false,
            'instructions' => $instructions,
            'input'        => wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'text'         => array( 'format' => array( 'type' => 'json_object' ) ),
        );
        $response = wp_remote_post( 'https://api.openai.com/v1/responses', array(
            'timeout' => 40,
            'headers' => array(
                'Authorization' => 'Bearer ' . (string) SML_OPENAI_API_KEY,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'sml_openai_connection', __( 'OpenAI is temporarily unavailable. No draft was created.', 'simple-multilang-blocks' ) );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
            return new WP_Error( 'sml_openai_response', __( 'OpenAI could not complete the translation. No draft was created.', 'simple-multilang-blocks' ) );
        }

        $output = self::openai_output_text( $data );
        $result = json_decode( $output, true );
        if ( ! is_array( $result ) || ! array_key_exists( 'title', $result ) || ! array_key_exists( 'excerpt', $result ) || ! array_key_exists( 'content', $result ) ) {
            return new WP_Error( 'sml_openai_invalid', __( 'OpenAI returned an invalid translation. No draft was created.', 'simple-multilang-blocks' ) );
        }
        return array(
            'title'   => (string) $result['title'],
            'excerpt' => (string) $result['excerpt'],
            'content' => (string) $result['content'],
        );
    }

    private static function openai_output_text( $data ) {
        if ( isset( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
            return $data['output_text'];
        }
        foreach ( (array) ( $data['output'] ?? array() ) as $item ) {
            foreach ( (array) ( $item['content'] ?? array() ) as $content ) {
                if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
                    return $content['text'];
                }
            }
        }
        return '';
    }

    private static function deepl_code( $code, $is_target ) {
        $code = strtoupper( str_replace( '_', '-', (string) $code ) );
        if ( 'EN' === $code ) {
            return $is_target ? 'EN-US' : 'EN';
        }
        return preg_match( '/^[A-Z]{2,3}(?:-[A-Z]{2})?$/', $code ) ? $code : '';
    }
}
