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
        add_action( 'admin_post_sml_retry_translation', array( $this, 'retry_translation' ) );
        add_action( 'admin_post_sml_mark_translation_verified', array( $this, 'mark_translation_verified' ) );
        add_action( 'admin_menu', array( $this, 'register_review_page' ) );
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
                } elseif ( 'outdated' === $status ) {
                    echo '<span class="sml-outdated-dot" title="' . esc_attr__( 'Source updated — review this translation', 'simple-multilang-blocks' ) . '"></span>';
                }
            } elseif ( $can_edit && $slug !== $current ) {
                $manual_url = wp_nonce_url( add_query_arg( array( 'action' => 'sml_create_translation', 'post' => $post_id, 'lang' => $slug ), admin_url( 'admin-post.php' ) ), 'sml_create_translation_' . $post_id . '_' . $slug );
                $machine_url = wp_nonce_url( add_query_arg( array( 'action' => 'sml_translate_post', 'post' => $post_id, 'lang' => $slug ), admin_url( 'admin-post.php' ) ), 'sml_translate_post_' . $post_id . '_' . $slug );
                printf( '<a class="sml-language-action is-missing" href="%1$s" title="%2$s">+<span>%3$s</span></a>', esc_url( $manual_url ), esc_attr( sprintf( __( 'Create a draft in %s', 'simple-multilang-blocks' ), $language['name'] ) ), esc_html( $flag ) );
                $job = SML_Translation_Service::get_job( $post_id, $slug );
                if ( $job && in_array( $job['status'], array( 'queued', 'processing', 'retry' ), true ) ) {
                    printf( '<span class="sml-queue-dot" title="%s"></span>', esc_attr__( 'Automatic translation queued', 'simple-multilang-blocks' ) );
                } else {
                    printf( '<a class="sml-language-action is-machine" href="%1$s" title="%2$s"><span class="dashicons dashicons-translation"></span><span>%3$s</span></a>', esc_url( $machine_url ), esc_attr( sprintf( __( 'Queue automatic translation into %s', 'simple-multilang-blocks' ), $language['name'] ) ), esc_html( $flag ) );
                }
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
        $result = SML_Translation_Service::queue_machine_draft( $post_id, $language );
        $this->redirect_from_result( $result, $post_id );
    }

    /** Requeues a failed request; this does not contact a provider in the browser request. */
    public function retry_translation() {
        $post_id = $this->request_post_id( 'sml_retry_translation' );
        $language = $this->request_language();
        $result = SML_Translation_Service::queue_machine_draft( $post_id, $language );
        $url = admin_url( 'tools.php?page=sml-translation-review' );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( 'sml_retry_error', '1', $url ) );
            exit;
        }
        wp_safe_redirect( add_query_arg( 'sml_requeued', '1', $url ) );
        exit;
    }

    public function register_review_page() {
        add_management_page(
            __( 'Translation review', 'simple-multilang-blocks' ),
            __( 'Translation review', 'simple-multilang-blocks' ),
            'edit_posts',
            'sml-translation-review',
            array( $this, 'render_review_page' )
        );
    }

    /** A focused editor workspace for drafts produced by a translation API. */
    public function render_review_page() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }
        $query = new WP_Query(
            array(
                'post_type'              => SML_Core::get_post_types(),
                'post_status'            => array( 'draft', 'pending', 'private', 'publish' ),
                'posts_per_page'         => 50,
                'orderby'                => 'modified',
                'order'                  => 'DESC',
                'meta_query'             => array(
                    'relation' => 'OR',
                    array( 'key' => '_sml_translation_status', 'value' => 'needs_review' ),
                    array( 'key' => '_sml_translation_status', 'value' => 'outdated' ),
                ),
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'suppress_filters'       => true,
            )
        );
        $jobs = SML_Translation_Service::get_jobs( array( 'queued', 'processing', 'retry', 'failed' ) );
        $term_jobs = SML_Translation_Service::get_term_jobs( array( 'queued', 'processing', 'retry', 'failed' ) );
        $term_review = get_terms(
            array(
                'taxonomy'   => SML_Core::get_taxonomies(),
                'hide_empty' => false,
                'number'     => 50,
                'orderby'    => 'term_id',
                'order'      => 'DESC',
                'meta_query' => array(
                    array(
                        'key'   => '_sml_translation_status',
                        'value' => 'needs_review',
                    ),
                ),
            )
        );
        if ( is_wp_error( $term_review ) ) {
            $term_review = array();
        }
        $languages = SML_Core::get_languages();
        ?>
        <div class="wrap sml-admin-wrap">
            <h1><?php esc_html_e( 'Translation review', 'simple-multilang-blocks' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Machine translations remain drafts until an editor verifies and publishes them. When a linked source changes, its counterpart is marked as outdated for review. The queue keeps only request status and never stores source text or API credentials.', 'simple-multilang-blocks' ); ?></p>
            <?php if ( isset( $_GET['sml_requeued'] ) ) : ?><div class="notice notice-success inline"><p><?php esc_html_e( 'The translation was added to the queue again.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['sml_retry_error'] ) ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'The translation could not be queued. Check the source, target language and translation provider.', 'simple-multilang-blocks' ); ?></p></div><?php endif; ?>

            <h2><?php esc_html_e( 'Translations needing review', 'simple-multilang-blocks' ); ?></h2>
            <?php if ( $query->have_posts() ) : ?>
                <table class="widefat striped sml-review-table"><thead><tr><th><?php esc_html_e( 'Translation', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Source', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Language', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Status', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Provider', 'simple-multilang-blocks' ); ?></th><th></th></tr></thead><tbody>
                <?php foreach ( $query->posts as $post ) :
                    if ( ! current_user_can( 'edit_post', $post->ID ) ) { continue; }
                    $source_id = absint( get_post_meta( $post->ID, '_sml_translation_source', true ) );
                    $source = $source_id ? get_post( $source_id ) : false;
                    $language = SML_Core::get_post_language( $post->ID );
                    $status = get_post_meta( $post->ID, '_sml_translation_status', true );
                ?>
                    <tr><td><strong><?php echo esc_html( get_the_title( $post ) ); ?></strong><br><span class="description"><?php echo esc_html( get_post_status( $post ) ); ?></span></td><td><?php if ( $source && current_user_can( 'edit_post', $source->ID ) ) : ?><a href="<?php echo esc_url( get_edit_post_link( $source->ID, '' ) ); ?>"><?php echo esc_html( get_the_title( $source ) ); ?></a><?php else : ?>—<?php endif; ?></td><td><?php echo esc_html( isset( $languages[ $language ] ) ? SML_Core::get_language_flag( $languages[ $language ] ) . ' ' . $languages[ $language ]['name'] : $language ); ?></td><td><span class="sml-translation-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( 'outdated' === $status ? __( 'Source updated', 'simple-multilang-blocks' ) : __( 'Requires review', 'simple-multilang-blocks' ) ); ?></span></td><td><?php echo esc_html( get_post_meta( $post->ID, '_sml_translation_provider', true ) ); ?></td><td><a class="button button-primary" href="<?php echo esc_url( get_edit_post_link( $post->ID, '' ) ); ?>"><?php esc_html_e( 'Review', 'simple-multilang-blocks' ); ?></a></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php else : ?><p><?php esc_html_e( 'There are no machine translations waiting for review.', 'simple-multilang-blocks' ); ?></p><?php endif; wp_reset_postdata(); ?>

            <h2><?php esc_html_e( 'Automatic translation queue', 'simple-multilang-blocks' ); ?></h2>
            <?php if ( $jobs ) : ?><table class="widefat striped sml-review-table"><thead><tr><th><?php esc_html_e( 'Source', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Target', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Status', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Attempts', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Updated', 'simple-multilang-blocks' ); ?></th></tr></thead><tbody>
                <?php foreach ( $jobs as $job ) : $source = get_post( $job['source_post'] ); $target = $job['target_lang']; ?>
                    <tr><td><?php if ( $source && current_user_can( 'edit_post', $source->ID ) ) : ?><a href="<?php echo esc_url( get_edit_post_link( $source->ID, '' ) ); ?>"><?php echo esc_html( get_the_title( $source ) ); ?></a><?php else : ?>#<?php echo esc_html( $job['source_post'] ); ?><?php endif; ?></td><td><?php echo esc_html( isset( $languages[ $target ] ) ? SML_Core::get_language_flag( $languages[ $target ] ) . ' ' . $languages[ $target ]['name'] : $target ); ?></td><td><span class="sml-job-status is-<?php echo esc_attr( $job['status'] ); ?>"><?php echo esc_html( self::job_status_label( $job['status'] ) ); ?></span><?php if ( 'failed' === $job['status'] ) : ?><br><span class="description"><?php echo esc_html( self::job_error_label( $job['error'] ) ); ?></span><?php if ( $source && current_user_can( 'edit_post', $source->ID ) ) : $retry = wp_nonce_url( add_query_arg( array( 'action' => 'sml_retry_translation', 'post' => $source->ID, 'lang' => $target ), admin_url( 'admin-post.php' ) ), 'sml_retry_translation_' . $source->ID . '_' . $target ); ?><br><a class="button button-small" href="<?php echo esc_url( $retry ); ?>"><?php esc_html_e( 'Queue again', 'simple-multilang-blocks' ); ?></a><?php endif; ?><?php endif; ?></td><td><?php echo esc_html( $job['attempts'] ); ?>/<?php echo esc_html( SML_Translation_Service::MAX_ATTEMPTS ); ?></td><td><?php echo esc_html( $job['updated_at'] ?? '' ); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php else : ?><p><?php esc_html_e( 'The queue is empty.', 'simple-multilang-blocks' ); ?></p><?php endif; ?>

            <h2><?php esc_html_e( 'Taxonomy terms requiring review', 'simple-multilang-blocks' ); ?></h2>
            <?php if ( $term_review ) : ?><table class="widefat striped sml-review-table"><thead><tr><th><?php esc_html_e( 'Translation', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Source', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Language', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Provider', 'simple-multilang-blocks' ); ?></th><th></th></tr></thead><tbody>
                <?php foreach ( $term_review as $term ) : $taxonomy = get_taxonomy( $term->taxonomy ); if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->manage_terms ) ) { continue; } $source_id = absint( get_term_meta( $term->term_id, '_sml_translation_source', true ) ); $source = $source_id ? get_term( $source_id, $term->taxonomy ) : false; $language = SML_Core::get_term_language( $term->term_id ); ?>
                    <tr><td><strong><?php echo esc_html( $term->name ); ?></strong><br><span class="description"><?php echo esc_html( $term->taxonomy ); ?></span></td><td><?php if ( $source && ! is_wp_error( $source ) ) : ?><a href="<?php echo esc_url( get_edit_term_link( $source->term_id, $source->taxonomy ) ); ?>"><?php echo esc_html( $source->name ); ?></a><?php else : ?>—<?php endif; ?></td><td><?php echo esc_html( isset( $languages[ $language ] ) ? SML_Core::get_language_flag( $languages[ $language ] ) . ' ' . $languages[ $language ]['name'] : $language ); ?></td><td><?php echo esc_html( get_term_meta( $term->term_id, '_sml_translation_provider', true ) ); ?></td><td><a class="button button-primary" href="<?php echo esc_url( get_edit_term_link( $term->term_id, $term->taxonomy ) ); ?>"><?php esc_html_e( 'Review', 'simple-multilang-blocks' ); ?></a></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php else : ?><p><?php esc_html_e( 'There are no machine-translated taxonomy terms waiting for review.', 'simple-multilang-blocks' ); ?></p><?php endif; ?>

            <h2><?php esc_html_e( 'Automatic taxonomy term queue', 'simple-multilang-blocks' ); ?></h2>
            <?php if ( $term_jobs ) : ?><table class="widefat striped sml-review-table"><thead><tr><th><?php esc_html_e( 'Source', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Target', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Status', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Attempts', 'simple-multilang-blocks' ); ?></th><th><?php esc_html_e( 'Updated', 'simple-multilang-blocks' ); ?></th></tr></thead><tbody>
                <?php foreach ( $term_jobs as $job ) : $source = get_term( $job['source_term'] ); $source_taxonomy = $source && ! is_wp_error( $source ) ? get_taxonomy( $source->taxonomy ) : false; $target = $job['target_lang']; ?>
                    <tr><td><?php if ( $source && ! is_wp_error( $source ) && $source_taxonomy && current_user_can( $source_taxonomy->cap->manage_terms ) ) : ?><a href="<?php echo esc_url( get_edit_term_link( $source->term_id, $source->taxonomy ) ); ?>"><?php echo esc_html( $source->name ); ?></a><?php else : ?>#<?php echo esc_html( $job['source_term'] ); ?><?php endif; ?></td><td><?php echo esc_html( isset( $languages[ $target ] ) ? SML_Core::get_language_flag( $languages[ $target ] ) . ' ' . $languages[ $target ]['name'] : $target ); ?></td><td><span class="sml-job-status is-<?php echo esc_attr( $job['status'] ); ?>"><?php echo esc_html( self::job_status_label( $job['status'] ) ); ?></span><?php if ( 'failed' === $job['status'] ) : ?><br><span class="description"><?php echo esc_html( self::job_error_label( $job['error'] ) ); ?></span><?php endif; ?></td><td><?php echo esc_html( $job['attempts'] ); ?>/<?php echo esc_html( SML_Translation_Service::MAX_ATTEMPTS ); ?></td><td><?php echo esc_html( $job['updated_at'] ?? '' ); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php else : ?><p><?php esc_html_e( 'The taxonomy term queue is empty.', 'simple-multilang-blocks' ); ?></p><?php endif; ?>
        </div>
        <?php
    }

    public function mark_translation_verified() {
        $post_id = $this->request_post_id( 'sml_mark_translation_verified' );
        update_post_meta( $post_id, '_sml_translation_status', 'verified' );
        $source_id = absint( get_post_meta( $post_id, '_sml_translation_source', true ) );
        if ( $source_id ) {
            update_post_meta( $post_id, '_sml_translation_source_hash', SML_Core::post_translation_content_hash( $source_id ) );
        }
        delete_post_meta( $post_id, '_sml_translation_outdated_at' );
        wp_safe_redirect( add_query_arg( 'sml_verified', '1', get_edit_post_link( $post_id, 'url' ) ) );
        exit;
    }

    public function render_review_status() {
        $post_id = get_the_ID();
        $status = $post_id ? get_post_meta( $post_id, '_sml_translation_status', true ) : '';
        if ( ! $post_id || ! in_array( $status, array( 'needs_review', 'outdated' ), true ) ) {
            return;
        }
        $url = wp_nonce_url( add_query_arg( array( 'action' => 'sml_mark_translation_verified', 'post' => $post_id ), admin_url( 'admin-post.php' ) ), 'sml_mark_translation_verified_' . $post_id );
        $message = 'outdated' === $status ? __( 'The linked source was updated — review this translation.', 'simple-multilang-blocks' ) : __( 'Machine translation — requires review.', 'simple-multilang-blocks' );
        echo '<div class="misc-pub-section sml-review-status is-' . esc_attr( $status ) . '"><span class="dashicons dashicons-warning"></span> ' . esc_html( $message ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Mark verified', 'simple-multilang-blocks' ) . '</a></div>';
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
        if ( isset( $_GET['sml_queued'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Automatic translation was queued. It will be saved as a draft requiring review.', 'simple-multilang-blocks' ) . '</p></div>';
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
        if ( is_array( $result ) && ! empty( $result['job_id'] ) ) {
            wp_safe_redirect( add_query_arg( 'sml_queued', '1', get_edit_post_link( $source_post_id, 'url' ) ) );
            exit;
        }
        wp_safe_redirect( add_query_arg( 'sml_created', '1', get_edit_post_link( absint( $result ), 'url' ) ) );
        exit;
    }

    private static function job_status_label( $status ) {
        $labels = array(
            'queued'     => __( 'Queued', 'simple-multilang-blocks' ),
            'processing' => __( 'Processing', 'simple-multilang-blocks' ),
            'retry'      => __( 'Retry scheduled', 'simple-multilang-blocks' ),
            'failed'     => __( 'Needs attention', 'simple-multilang-blocks' ),
        );
        return $labels[ $status ] ?? __( 'Unknown', 'simple-multilang-blocks' );
    }

    private static function job_error_label( $error ) {
        if ( in_array( $error, array( 'sml_provider_unavailable', 'sml_deepl_connection', 'sml_openai_connection' ), true ) ) {
            return __( 'The translation provider is unavailable. Check its configuration, then queue the translation again.', 'simple-multilang-blocks' );
        }
        return __( 'The provider could not complete this translation. No draft was created.', 'simple-multilang-blocks' );
    }
}
