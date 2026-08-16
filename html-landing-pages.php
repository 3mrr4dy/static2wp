<?php
/**
 * Plugin Name:       HTML Landing Pages
 * Plugin URI:        https://github.com/amrrady/html-landing-pages
 * Description:       Upload an HTML or ZIP file and that page shows the file to visitors — not the theme. Create a page from a file, or attach one while editing.
 * Version:           1.6.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Amr Rady
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       html-landing-pages
 *
 * @package HTML_Landing_Pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'HLP_VERSION', '1.6.0' );
define( 'HLP_PLUGIN_FILE', __FILE__ );
define( 'HLP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HLP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once HLP_PLUGIN_DIR . 'includes/class-hlp-store.php';
require_once HLP_PLUGIN_DIR . 'includes/class-hlp-renderer.php';
require_once HLP_PLUGIN_DIR . 'includes/class-hlp-admin.php';
require_once HLP_PLUGIN_DIR . 'includes/class-hlp-metabox.php';

/**
 * Boot the plugin.
 */
function hlp_init() {
	HLP_Renderer::init();
	if ( is_admin() ) {
		HLP_Admin::instance();
		HLP_Metabox::init();
	}
}
add_action( 'plugins_loaded', 'hlp_init' );
