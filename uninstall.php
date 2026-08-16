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

// Remove both namespaces: legacy (pre-1.7 hlp_*) and current (s2wp_*).
$landings = get_option( 's2wp_landings', array() );
$legacy   = get_option( 'hlp_landings', array() );
$page_ids = array();
foreach ( array( $landings, $legacy ) as $records ) {
	if ( is_array( $records ) ) {
		foreach ( $records as $record ) {
			if ( ! empty( $record['page_id'] ) ) {
				$page_ids[] = (int) $record['page_id'];
			}
		}
	}
}

foreach ( array_unique( $page_ids ) as $page_id ) {
	delete_post_meta( $page_id, '_s2wp_landing' );
	delete_post_meta( $page_id, '_hlp_landing' );
}

delete_option( 's2wp_landings' );
delete_option( 's2wp_settings' );
delete_option( 'hlp_landings' );
delete_option( 'hlp_settings' );

$uploads = wp_upload_dir();
if ( empty( $uploads['error'] ) ) {
	foreach ( array( 'static2wp', 'html-landing-pages' ) as $sub ) {
		$base = trailingslashit( $uploads['basedir'] ) . $sub;
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
}
