<?php
/**
 * Plugin uninstall: remove plugin options, page index meta, and uploaded landing files.
 *
 * The WordPress pages the landings were assigned to are left untouched.
 *
 * @package Static2WP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$landings = get_option( 's2wp_landings', array() );
$page_ids = array();
if ( is_array( $landings ) ) {
	foreach ( $landings as $record ) {
		if ( ! empty( $record['page_id'] ) ) {
			$page_ids[] = (int) $record['page_id'];
		}
	}
}

foreach ( $page_ids as $page_id ) {
	delete_post_meta( $page_id, '_s2wp_landing' );
}

delete_option( 's2wp_landings' );
delete_option( 's2wp_settings' );

$uploads = wp_upload_dir();
if ( empty( $uploads['error'] ) ) {
	$base = trailingslashit( $uploads['basedir'] ) . 'static2wp';
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
