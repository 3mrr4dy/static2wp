<?php
/**
 * Plugin Name:       Static2WP
 * Plugin URI:        https://github.com/3mrr4dy/static2wp
 * Description:       Upload an HTML or ZIP file and that page shows the file to visitors — not the theme. Create a page from a file, or attach one while editing.
 * Version:           1.7.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Amr Rady
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       static2wp
 *
 * @package Static2WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'S2WP_VERSION', '1.7.0' );
define( 'S2WP_PLUGIN_FILE', __FILE__ );
define( 'S2WP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'S2WP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once S2WP_PLUGIN_DIR . 'includes/class-s2wp-store.php';
require_once S2WP_PLUGIN_DIR . 'includes/class-s2wp-renderer.php';
require_once S2WP_PLUGIN_DIR . 'includes/class-s2wp-admin.php';
require_once S2WP_PLUGIN_DIR . 'includes/class-s2wp-metabox.php';

/**
 * Boot the plugin.
 */
function s2wp_init() {
	S2WP_Renderer::init();
	if ( is_admin() ) {
		S2WP_Admin::instance();
		S2WP_Metabox::init();
	}
}
add_action( 'plugins_loaded', 's2wp_init' );
