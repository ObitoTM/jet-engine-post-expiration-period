<?php
/**
 * Plugin Name: JetEngine Post Expiration Period Module
 * Plugin URI:
 * Description: Also supports JetFormBuilder.
 * Version:     1.1.2
 * Author:      Crocoblock
 * Author URI:  https://crocoblock.com/
 * Text Domain: jet-engine-post-expiration-period
 * License:     GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path: /languages
 */


// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die();
}

add_action( 'plugins_loaded', 'jet_engine_post_ep_init' );
register_deactivation_hook( __FILE__, 'jet_engine_post_ep_deactivate' );

function jet_engine_post_ep_deactivate( $network_wide = false ) {
    if ( ! is_multisite() || ! $network_wide ) {
        wp_clear_scheduled_hook( 'jet-engine-pep/daily_check_expirations' );
        return;
    }
    $offset = 0;
    do {
        $sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) );
        foreach ( $sites as $site_id ) {
            switch_to_blog( $site_id );
            try {
                wp_clear_scheduled_hook( 'jet-engine-pep/daily_check_expirations' );
            } finally {
                restore_current_blog();
            }
        }
        $offset += count( $sites );
    } while ( count( $sites ) === 100 );
}

function jet_engine_post_ep_init() {

    define( 'JET_ENGINE_POST_EP_VERSION', '1.1.2' );

    define( 'JET_ENGINE_POST_EP__FILE__', __FILE__ );
    define( 'JET_ENGINE_POST_EP_PLUGIN_BASE', plugin_basename( JET_ENGINE_POST_EP__FILE__ ) );
    define( 'JET_ENGINE_POST_EP_PATH', plugin_dir_path( JET_ENGINE_POST_EP__FILE__ ) );
    define( 'JET_ENGINE_POST_EP_URL', plugins_url( '/', JET_ENGINE_POST_EP__FILE__ ) );

    require JET_ENGINE_POST_EP_PATH . 'plugin.php';
}
