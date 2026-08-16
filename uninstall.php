<?php
/**
 * Plugin uninstall: remove plugin options and uploaded landing files.
 *
 * The WordPress pages the landings were assigned to are left untouched.
 *
 * @package HTML_Landing_Pages
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'hlp_landings' );
delete_option( 'hlp_settings' );

$uploads = wp_upload_dir();
if ( empty( $uploads['error'] ) ) {
	$base = trailingslashit( $uploads['basedir'] ) . 'html-landing-pages';
	if ( is_dir( $base ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $base );
	}
}
