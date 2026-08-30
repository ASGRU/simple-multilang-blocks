<?php

defined( 'ABSPATH' ) || exit;

/** Administrative controls for creating, reviewing and finding translations. */
final class SML_Admin {
    private static $instance;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        foreach ( SML_Core::get_post_types() as $post_type ) {
            add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_language_column' ) );
            add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_language_column' ), 10, 2 );
        }
        add_action( 'restrict_manage_posts', array( $this, 'render_language_filter' ) );
        add_action( 'pre_get_posts', array( $this, 'filter_admin_posts' ) );
        add_action( 'admin_post_sml_create_translation', array( $this, 'create_translation' ) );
        add_action( 'admin_post_sml_translate_post', array( $this, 'translate_post' ) );
        add_action( 'admin_post_sml_mark_translation_verified', array( $this, 'mark_translation_verified' ) );
        add_action( 'post_submitbox_misc_actions', array( $this, 'render_review_status' ) );
        add_action( 'admin_notices', array( $this, 'render_notices' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets() {
        wp_enqueue_style( 'simple-multilang-blocks-admin', plugins_url( 'assets/css/sml-admin.css', SML_FILE ), array(), SML_VERSION );
        wp_enqueue_script( 'simple-multilang-blocks-admin', plugins_url( 'assets/js/sml-admin.js', SML_FILE ), array(), SML_VERSION, true );
    }

    public function add_language_column( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'title' === $key ) {
                $new['sml_languages'] = __( 'Translations', 'simple-multilang-blocks' );
            }
        }
        if ( ! isset( $new['sml_languages'] ) ) {
            $new['sml_languages'] = __( 'Translations', 'simple-multilang-blocks' );
        }
        return $new;
    }

    public function render_language_column( $column, $post_id ) {
        if ( 'sml_languages' !== $column ) {
            return;
        }
        $translations = SML_Core::get_post_translations( $post_id );
        $current = SML_Core::get_post_language( $post_id );
        $can_edit = current_user_can( 'edit_post', $post_id );
        echo '<div class="sml-language-actions">';
        foreach ( SML_Core::get_languages() as $slug => $language ) {
            $flag = SML_Core::get_language_flag( $language );
            if ( ! empty( $translations[ $slug ] ) ) {
                $translation_id = absint( $translations[ $slug ] );
                $status = get_post_meta( $translation_id, '_sml_translation_status', true );
                printf( '<a class="sml-language-action is-existing" href="%1$s" title="%2$s"><span class="dashicons dashicons-edit"></span><span>%3$s</span></a>', esc_url( get_edit_post_link( $translation_id, '' ) ), esc_attr( sprintf( __( 'Edit %s translation', 'simple-multilang-blocks' ), $language['name'] ) ), esc_html( $flag ) );
                if ( 'needs_review' === $status ) {
                    echo '<span class="sml-review-dot" title="' . esc_attr__( 'Requires review', 'simple-multilang-blocks' ) . '"></span>';
                }
            } elseif ( $can_edit && $slug !== $current ) {
                $manual_url = wp_nonce_url( add_query_arg( array( 'action' => 'sml_create_translation', 'post' => $post_id, 'lang' => $slug ), admin_url( 'admin-post.php' ) ), 'sml_create_translation_' . $post_id . '_' . $slug );
                $machine_url = wp_nonce_url( add_query_arg( array( 'action' => 'sml_translate_post', 'post' => $post_id, 'lang' => $slug ), admin_url( 'admin-post.php' ) ), 'sml_translate_post_' . $post_id . '_' . $slug );
                printf( '<a class="sml-language-action is-missing" href="%1$s" title="%2$s">+<span>%3$s</span></a>', esc_url( $manual_url ), esc_attr( sprintf( __( 'Create a draft in %s', 'simple-multilang-blocks' ), $language['name'] ) ), esc_html( $flag ) );
                printf( '<a class="sml-language-action is-machine" href="%1$s" title="%2$s"><span class="dashicons dashicons-translation"></span><span>%3$s</span></a>', esc_url( $machine_url ), esc_attr( sprintf( __( 'Auto-translate into %s', 'simple-multilang-blocks' ), $language['name'] ) ), esc_html( $flag ) );
            }
        }
        echo '</div>';
    }

    public function render_language_filter( $post_type ) {
        if ( ! in_array( $post_type, SML_Core::get_post_types(), true ) ) {
            return;
        }
        $current = isset( $_GET['sml_lang'] ) ? sanitize_key( wp_unslash( $_GET['sml_lang'] ) ) : '';
        echo '<select name="sml_lang"><option value="">' . esc_html__( 'All languages', 'simple-multilang-blocks' ) . '</option>';
        foreach ( SML_Core::get_languages() as $slug => $language ) {
            printf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $current, $slug, false ), esc_html( SML_Core::get_language_flag( $language ) . ' ' . $language['name'] ) );
        }
        echo '</select>';
    }

    public function filter_admin_posts( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || ! isset( $_GET['sml_lang'] ) ) {
            return;
        }
        $language = sanitize_key( wp_unslash( $_GET['sml_lang'] ) );
        if ( ! isset( SML_Core::get_languages()[ $language ] ) ) {
            return;
        }
        $post_type = $query->get( 'post_type' );
        if ( ! in_array( $post_type, SML_Core::get_post_types(), true ) ) {
            return;
        }
        $meta_query = (array) $query->get( 'meta_query' );
        $meta_query[] = array( 'key' => '_sml_language', 'value' => $language );
        $query->set( 'meta_query', $meta_query );
    }

    public function create_translation() {
        $post_id = $this->request_post_id( 'sml_create_translation' );
        $language = $this->request_language();
        $result = SML_Translation_Service::create_manual_draft( $post_id, $language );
        $this->redirect_from_result( $result, $post_id );
    }

    public function translate_post() {
        $post_id = $this->request_post_id( 'sml_translate_post' );
        $language = $this->request_language();
        $result = SML_Translation_Service::create_machine_draft( $post_id, $language );
        $this->redirect_from_result( $result, $post_id );
    }

    public function mark_translation_verified() {
        $post_id = $this->request_post_id( 'sml_mark_translation_verified' );
        update_post_meta( $post_id, '_sml_translation_status', 'verified' );
        wp_safe_redirect( add_query_arg( 'sml_verified', '1', get_edit_post_link( $post_id, 'url' ) ) );
        exit;
    }

    public function render_review_status() {
        $post_id = get_the_ID();
        if ( ! $post_id || 'needs_review' !== get_post_meta( $post_id, '_sml_translation_status', true ) ) {
            return;
        }
        $url = wp_nonce_url( add_query_arg( array( 'action' => 'sml_mark_translation_verified', 'post' => $post_id ), admin_url( 'admin-post.php' ) ), 'sml_mark_translation_verified_' . $post_id );
        echo '<div class="misc-pub-section sml-review-status"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Machine translation — requires review.', 'simple-multilang-blocks' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Mark verified', 'simple-multilang-blocks' ) . '</a></div>';
    }

    public function render_notices() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }
        if ( isset( $_GET['sml_created'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Translation draft created. Review it before publishing.', 'simple-multilang-blocks' ) . '</p></div>';
        }
        if ( isset( $_GET['sml_verified'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Translation marked as verified.', 'simple-multilang-blocks' ) . '</p></div>';
        }
        if ( isset( $_GET['sml_error'] ) ) {
            $errors = array(
                'provider_unavailable' => __( 'The selected translation service is unavailable. No draft was created. You can create a manual draft instead.', 'simple-multilang-blocks' ),
                'translation_exists'  => __( 'A translation already exists for this language.', 'simple-multilang-blocks' ),
                'translation_failed'  => __( 'Translation could not be completed. No draft was created.', 'simple-multilang-blocks' ),
            );
            $code = sanitize_key( wp_unslash( $_GET['sml_error'] ) );
            if ( isset( $errors[ $code ] ) ) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $errors[ $code ] ) . '</p></div>';
            }
        }
    }

    private function request_post_id( $action ) {
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'You are not allowed to create this translation.', 'simple-multilang-blocks' ) );
        }
        check_admin_referer( $action . '_' . $post_id . ( isset( $_GET['lang'] ) ? '_' . sanitize_key( wp_unslash( $_GET['lang'] ) ) : '' ) );
        return $post_id;
    }

    private function request_language() {
        $language = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
        if ( ! isset( SML_Core::get_languages()[ $language ] ) ) {
            wp_die( esc_html__( 'The target language is invalid.', 'simple-multilang-blocks' ) );
        }
        return $language;
    }

    private function redirect_from_result( $result, $source_post_id ) {
        if ( is_wp_error( $result ) ) {
            if ( 'sml_translation_exists' === $result->get_error_code() && ! empty( $result->get_error_data()['post_id'] ) ) {
                wp_safe_redirect( get_edit_post_link( absint( $result->get_error_data()['post_id'] ), 'url' ) );
                exit;
            }
            $error = 'sml_provider_unavailable' === $result->get_error_code() ? 'provider_unavailable' : ( 'sml_translation_exists' === $result->get_error_code() ? 'translation_exists' : 'translation_failed' );
            wp_safe_redirect( add_query_arg( 'sml_error', $error, get_edit_post_link( $source_post_id, 'url' ) ) );
            exit;
        }
        wp_safe_redirect( add_query_arg( 'sml_created', '1', get_edit_post_link( absint( $result ), 'url' ) ) );
        exit;
    }
}
