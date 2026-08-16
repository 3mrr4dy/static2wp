<?php
/**
 * Plugin Name:       Static2WP
 * Plugin URI:        https://github.com/3mrr4dy/static2wp
 * Description:       Upload an HTML or ZIP file and that page shows the file to visitors — not the theme. Create a page from a file, or attach one while editing.
 * Version:           1.7.2
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

define( 'S2WP_VERSION', '1.7.2' );
define( 'S2WP_PLUGIN_FILE', __FILE__ );
define( 'S2WP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'S2WP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once S2WP_PLUGIN_DIR . 'includes/class-s2wp-store.php';
require_once S2WP_PLUGIN_DIR . 'includes/class-s2wp-renderer.php';
require_once S2WP_PLUGIN_DIR . 'includes/class-s2wp-admin.php';
require_once S2WP_PLUGIN_DIR . 'includes/class-s2wp-metabox.php';

/**
 * Migrate data from the pre-1.7 "HTML Landing Pages" namespace (hlp_*) into
 * the Static2WP namespace (s2wp_*), then delete the legacy data.
 *
 * Runs on activation AND on every load while legacy data still exists, so an
 * in-place file update (which never fires the activation hook) migrates too.
 */
function s2wp_migrate_legacy() {
	if ( ! defined( 'ABSPATH' ) || ( function_exists( 'wp_installing' ) && wp_installing() && ! defined( 'WP_INSTALLING' ) ) ) {
		return;
	}

	// 1. Landings option: hlp_landings -> s2wp_landings.
	$legacy = get_option( 'hlp_landings' );
	if ( is_array( $legacy ) ) {
		$current = get_option( 's2wp_landings', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		if ( ! empty( $legacy ) ) {
			// Rename the page-meta index first (ids are case-preserving, unchanged).
			foreach ( $legacy as $id => $record ) {
				if ( ! empty( $record['page_id'] ) ) {
					$page_id = (int) $record['page_id'];
					$meta    = get_post_meta( $page_id, '_hlp_landing', true );
					if ( $meta ) {
						delete_post_meta( $page_id, '_hlp_landing' );
						update_post_meta( $page_id, '_s2wp_landing', $meta );
					} elseif ( ! get_post_meta( $page_id, '_s2wp_landing', true ) ) {
						update_post_meta( $page_id, '_s2wp_landing', $id );
					}
				}
			}

			update_option( 's2wp_landings', array_merge( $legacy, $current ), false );
		} elseif ( empty( $current ) ) {
			delete_option( 's2wp_landings' ); // Legacy cruft only — do not keep an empty twin.
		}

		delete_option( 'hlp_landings' );
	}

	// 2. Settings option: hlp_settings -> s2wp_settings.
	$legacy_settings = get_option( 'hlp_settings' );
	if ( is_array( $legacy_settings ) ) {
		$current_settings = get_option( 's2wp_settings', array() );
		if ( ! is_array( $current_settings ) ) {
			$current_settings = array();
		}
		if ( ! empty( $legacy_settings ) ) {
			update_option( 's2wp_settings', array_merge( $legacy_settings, $current_settings ), false );
		} elseif ( empty( $current_settings ) ) {
			delete_option( 's2wp_settings' );
		}
		delete_option( 'hlp_settings' );
	}

	// 3. Uploaded files: uploads/html-landing-pages -> uploads/static2wp.
	$uploads = wp_upload_dir();
	if ( empty( $uploads['error'] ) ) {
		$old_dir = trailingslashit( $uploads['basedir'] ) . 'html-landing-pages';
		$new_dir = trailingslashit( $uploads['basedir'] ) . S2WP_Store::BASE_DIR;

		if ( is_dir( $old_dir ) ) {
			if ( ! is_dir( $new_dir ) ) {
				// Landing IDs inside records are unchanged, so a plain rename keeps
				// every stored path valid.
				@rename( $old_dir, $new_dir );
			} else {
				// Merge: move everything, skip name clashes (new data wins).
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $old_dir, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::SELF_FIRST
				);
				foreach ( $it as $f ) {
					$rel  = substr( $f->getPathname(), strlen( $old_dir ) + 1 );
					$dest = $new_dir . '/' . $rel;
					if ( $f->isDir() ) {
						wp_mkdir_p( $dest );
					} elseif ( ! file_exists( $dest ) ) {
						wp_mkdir_p( dirname( $dest ) );
						@rename( $f->getPathname(), $dest );
					}
				}
				foreach ( new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $old_dir, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::CHILD_FIRST
				) as $f ) {
					$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
				}
				@rmdir( $old_dir );
			}
		}
	}
}

/**
 * Boot the plugin.
 */
function s2wp_init() {
	s2wp_migrate_legacy();

	S2WP_Renderer::init();
	if ( is_admin() ) {
		S2WP_Admin::instance();
		S2WP_Metabox::init();
	}
}
add_action( 'plugins_loaded', 's2wp_init' );
register_activation_hook( __FILE__, 's2wp_migrate_legacy' );
