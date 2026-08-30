<?php

defined( 'ABSPATH' ) || exit;

final class SML_GitHub_Updater {
    const TRANSIENT_KEY = 'sml_github_release_1_1';

    private static $instance;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
        add_filter( 'http_request_args', array( $this, 'authenticate_private_release_download' ), 20, 2 );
        add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
    }

    public function inject_update( $transient ) {
        if ( ! is_object( $transient ) || empty( $transient->checked[ SML_BASENAME ] ) ) {
            return $transient;
        }

        $release = $this->get_release();
        if ( ! $release || empty( $release['version'] ) || empty( $release['package'] ) ) {
            return $transient;
        }

        if ( version_compare( SML_VERSION, $release['version'], '>=' ) ) {
            return $transient;
        }

        $transient->response[ SML_BASENAME ] = (object) array(
            'slug'        => 'simple-multilang-blocks',
            'plugin'      => SML_BASENAME,
            'new_version' => $release['version'],
            'url'         => 'https://github.com/' . SML_GITHUB_REPOSITORY,
            'package'     => $release['package'],
            'icons'       => array(),
            'banners'     => array(),
            'tested'      => isset( $release['tested'] ) ? $release['tested'] : '',
            'requires'    => '6.4',
            'requires_php'=> '7.4',
        );

        return $transient;
    }

    public function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || 'simple-multilang-blocks' !== $args->slug ) {
            return $result;
        }

        $release = $this->get_release();
        if ( ! $release ) {
            return $result;
        }

        return (object) array(
            'name'          => 'Simple Multilang Blocks',
            'slug'          => 'simple-multilang-blocks',
            'version'       => $release['version'],
            'author'        => '<a href="https://github.com/ASGRU">ASGRU</a>',
            'homepage'      => 'https://github.com/' . SML_GITHUB_REPOSITORY,
            'requires'      => '6.4',
            'requires_php'  => '7.4',
            'download_link' => $release['package'],
            'sections'      => array(
                'description' => 'A lightweight multilingual layer for block-based WordPress sites.',
                'changelog'   => isset( $release['notes'] ) ? wp_kses_post( wpautop( $release['notes'] ) ) : '',
            ),
        );
    }

    public function clear_cache( $upgrader, $options ) {
        if ( empty( $options['action'] ) || 'update' !== $options['action'] || empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
            return;
        }

        delete_site_transient( self::TRANSIENT_KEY );
        delete_site_transient( 'update_plugins' );
    }

    public function authenticate_private_release_download( $args, $url ) {
        $asset_prefix = 'https://api.github.com/repos/' . SML_GITHUB_REPOSITORY . '/releases/assets/';
        if ( ! defined( 'SML_GITHUB_TOKEN' ) || ! SML_GITHUB_TOKEN || 0 !== strpos( $url, $asset_prefix ) ) {
            return $args;
        }

        $args['headers']['Accept'] = 'application/octet-stream';
        $args['headers']['Authorization'] = 'Bearer ' . SML_GITHUB_TOKEN;
        return $args;
    }

    private function get_release() {
        $cached = get_site_transient( self::TRANSIENT_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $headers = array(
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'Simple-Multilang-Blocks/' . SML_VERSION,
        );

        /* For private repositories define SML_GITHUB_TOKEN in wp-config.php. */
        if ( defined( 'SML_GITHUB_TOKEN' ) && SML_GITHUB_TOKEN ) {
            $headers['Authorization'] = 'Bearer ' . SML_GITHUB_TOKEN;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . SML_GITHUB_REPOSITORY . '/releases/latest',
            array(
                'headers' => $headers,
                'timeout' => 12,
            )
        );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            set_site_transient( self::TRANSIENT_KEY, array(), HOUR_IN_SECONDS );
            return array();
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
            set_site_transient( self::TRANSIENT_KEY, array(), HOUR_IN_SECONDS );
            return array();
        }

        $package = '';
        foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
            if ( isset( $asset['name'], $asset['url'] ) && 'simple-multilang-blocks.zip' === $asset['name'] ) {
                $package = esc_url_raw( $asset['url'] );
                break;
            }
        }

        if ( ! $package ) {
            set_site_transient( self::TRANSIENT_KEY, array(), HOUR_IN_SECONDS );
            return array();
        }

        $normalized = ltrim( (string) $release['tag_name'], "vV" );
        if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $normalized ) ) {
            set_site_transient( self::TRANSIENT_KEY, array(), HOUR_IN_SECONDS );
            return array();
        }

        $data = array(
            'version' => $normalized,
            'package' => $package,
            'notes'   => isset( $release['body'] ) ? (string) $release['body'] : '',
            'tested'  => isset( $release['target_commitish'] ) ? '' : '',
        );

        set_site_transient( self::TRANSIENT_KEY, $data, 12 * HOUR_IN_SECONDS );
        return $data;
    }
}
