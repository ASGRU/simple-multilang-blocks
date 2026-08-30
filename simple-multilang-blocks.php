<?php
/**
 * Plugin Name:       Simple Multilang Blocks
 * Plugin URI:        https://github.com/ASGRU/simple-multilang-blocks
 * Description:       A lightweight multilingual layer for block-based WordPress sites.
 * Version:           1.2.4
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            ASGRU
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       simple-multilang-blocks
 * Update URI:        https://github.com/ASGRU/simple-multilang-blocks
 */

defined( 'ABSPATH' ) || exit;

define( 'SML_VERSION', '1.2.4' );
define( 'SML_FILE', __FILE__ );
define( 'SML_DIR', plugin_dir_path( __FILE__ ) );
define( 'SML_BASENAME', plugin_basename( __FILE__ ) );
define( 'SML_GITHUB_REPOSITORY', 'ASGRU/simple-multilang-blocks' );

require_once SML_DIR . 'includes/class-sml-core.php';
require_once SML_DIR . 'includes/class-sml-github-updater.php';
require_once SML_DIR . 'includes/class-sml-wpml-migrator.php';

register_activation_hook( SML_FILE, array( 'SML_Core', 'activate' ) );
register_deactivation_hook( SML_FILE, array( 'SML_Core', 'deactivate' ) );

SML_Core::instance();
SML_GitHub_Updater::instance();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once SML_DIR . 'includes/class-sml-cli.php';
}

/*
 * Compatibility for themes that used the public WPML single-string API.
 * These shims are deliberately only defined when WPML is not active.
 */
if ( ! function_exists( 'wpml_register_single_string' ) ) {
    function wpml_register_single_string( $context, $name, $value = '' ) {
        SML_Core::register_string( $context, $name, '' === $value ? $name : $value );
    }
}

if ( ! function_exists( 'icl_t' ) ) {
    function icl_t( $context, $name, $value = '' ) {
        return SML_Core::translate_string( $context, $name, '' === $value ? $name : $value );
    }
}
