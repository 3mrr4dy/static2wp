<?php
/**
 * Plugin uninstall: remove plugin options, page index meta, and uploaded landing files.
 *
 * The WordPress pages the landings were assigned to are left untouched.
 *
 * @package HTML_Landing_Pages
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$landings = get_option( 'hlp_landings', array() );
$page_ids = array();
if ( is_array( $landings ) ) {
	foreach ( $landings as $record ) {
		if ( ! empty( $record['page_id'] ) ) {
			$page_ids[] = (int) $record['page_id'];
		}
	}
}

foreach ( $page_ids as $page_id ) {
	delete_post_meta( $page_id, '_hlp_landing' );
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
