<?php
/**
 * Data + filesystem layer: landing records (option) and uploaded files (uploads dir).
 *
 * @package Static2WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class S2WP_Store
 */
class S2WP_Store {

	/**
	 * Option key holding all landing records, keyed by landing ID.
	 */
	const OPTION = 's2wp_landings';

	/**
	 * Subdirectory (inside uploads) holding all landing files.
	 */
	const BASE_DIR = 'static2wp';

	/**
	 * Only these file extensions may be extracted from an uploaded ZIP.
	 * SVG is deliberately excluded: it executes script when opened directly.
	 */
	const ALLOW_EXT = 'html|htm|css|js|mjs|map|json|txt|png|jpg|jpeg|gif|webp|avif|ico|bmp|woff|woff2|ttf|eot|otf|mp4|webm|ogv|ogg|mp3|wav|m4a|vtt';

	/**
	 * Max upload size (bytes) — 100 MB.
	 */
	const MAX_UPLOAD = 104857600;

	/**
	 * Max size of the HTML entry document (bytes) — keeps the runtime
	 * rewrite/strip passes bounded.
	 */
	const MAX_HTML = 2097152;

	/**
	 * Option key holding global plugin settings.
	 */
	const SETTINGS_OPTION = 's2wp_settings';

	/**
	 * Get global settings with defaults.
	 *
	 * @return array{head_code:string,body_code:string,inject_all:bool,seo_meta:bool}
	 */
	public static function settings() {
		$saved = get_option( self::SETTINGS_OPTION, array() );
		return array_merge(
			array(
				'head_code'  => '',
				'body_code'  => '',
				'inject_all' => false,
				'seo_meta'   => true,
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	/**
	 * Persist global settings.
	 *
	 * @param array $settings Settings array.
	 */
	public static function save_settings( $settings ) {
		update_option(
			self::SETTINGS_OPTION,
			array(
				'head_code'  => isset( $settings['head_code'] ) ? (string) $settings['head_code'] : '',
				'body_code'  => isset( $settings['body_code'] ) ? (string) $settings['body_code'] : '',
				'inject_all' => ! empty( $settings['inject_all'] ),
				'seo_meta'   => ! empty( $settings['seo_meta'] ),
			),
			false
		);
	}

	/**
	 * Get all landing records.
	 *
	 * @return array<string,array>
	 */
	public static function get_all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Get one landing record.
	 *
	 * @param string $id Landing ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::get_all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Find the landing assigned to a page (any status).
	 *
	 * A `_s2wp_landing` post-meta index avoids scanning the whole option on
	 * the public hot path; the option stays the source of truth.
	 *
	 * @param int    $page_id    Page ID.
	 * @param string $exclude_id Optional landing ID to ignore.
	 * @return array|null Record (with 'id') or null.
	 */
	public static function find_by_page( $page_id, $exclude_id = '' ) {
		$page_id = (int) $page_id;
		if ( $page_id < 1 ) {
			return null;
		}

		$all = self::get_all();

		$meta_id = get_post_meta( $page_id, '_s2wp_landing', true );
		if ( $meta_id && isset( $all[ $meta_id ] ) && (int) $all[ $meta_id ]['page_id'] === $page_id ) {
			if ( $meta_id === $exclude_id ) {
				return null;
			}
			$all[ $meta_id ]['id'] = $meta_id;
			return $all[ $meta_id ];
		}
		if ( $meta_id ) {
			delete_post_meta( $page_id, '_s2wp_landing' ); // Stale index.
		}

		foreach ( $all as $id => $record ) {
			if ( (int) $record['page_id'] === $page_id && $id !== $exclude_id ) {
				update_post_meta( $page_id, '_s2wp_landing', $id );
				$record['id'] = $id;
				return $record;
			}
		}
		return null;
	}

	/**
	 * Find the active landing assigned to a page.
	 *
	 * @param int $page_id Page ID.
	 * @return array|null Record (with 'id') or null.
	 */
	public static function find_active_by_page( $page_id ) {
		$record = self::find_by_page( $page_id );
		return ( $record && ! empty( $record['active'] ) ) ? $record : null;
	}

	/**
	 * Insert or update a landing record and refresh the page meta index.
	 *
	 * @param string $id   Landing ID.
	 * @param array  $data Record fields.
	 */
	public static function save( $id, $data ) {
		$all        = self::get_all();
		$all[ $id ] = $data;
		update_option( self::OPTION, $all, false );

		// Drop a stale index from a previous page owner.
		if ( ! empty( $data['page_id'] ) ) {
			$old = get_post_meta( (int) $data['page_id'], '_s2wp_landing', true );
			if ( $old && $old !== $id ) {
				delete_post_meta( (int) $data['page_id'], '_s2wp_landing' );
			}
			update_post_meta( (int) $data['page_id'], '_s2wp_landing', $id );
		}
	}

	/**
	 * Delete a landing record, its files and its page meta index.
	 *
	 * @param string $id Landing ID.
	 */
	public static function delete( $id ) {
		$all = self::get_all();
		if ( isset( $all[ $id ]['page_id'] ) ) {
			delete_post_meta( (int) $all[ $id ]['page_id'], '_s2wp_landing' );
		}
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		self::remove_dir( self::landing_dir( $id ) );
	}

	/**
	 * Generate a new unique landing ID (lowercase so sanitize_key can never
	 * alter it; older records with mixed case are still accepted by validate_id).
	 *
	 * @return string
	 */
	public static function new_id() {
		return 's2wp_' . strtolower( wp_generate_password( 12, false, false ) );
	}

	/**
	 * Validate a landing ID from a request WITHOUT changing its case —
	 * record keys and directory names are case-sensitive on Linux, so
	 * sanitize_key() here would break nonces and lookups.
	 *
	 * @param mixed $raw Raw ID from $_POST/$_GET.
	 * @return string Valid ID or ''.
	 */
	public static function validate_id( $raw ) {
		$raw = is_scalar( $raw ) ? trim( (string) wp_unslash( $raw ) ) : '';
		return preg_match( '/^[A-Za-z0-9_-]{4,64}$/', $raw ) ? $raw : '';
	}

	/**
	 * Absolute path of the base uploads dir for landings.
	 *
	 * @return string Empty string when uploads are unavailable.
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		return trailingslashit( $uploads['basedir'] ) . self::BASE_DIR;
	}

	/**
	 * Public URL of the base uploads dir for landings.
	 *
	 * @return string
	 */
	public static function base_url() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		return trailingslashit( $uploads['baseurl'] ) . self::BASE_DIR;
	}

	/**
	 * Absolute path of one landing's directory.
	 *
	 * @param string $id Landing ID.
	 * @return string
	 */
	public static function landing_dir( $id ) {
		return trailingslashit( self::base_dir() ) . $id;
	}

	/**
	 * Public URL of one landing's directory.
	 *
	 * @param string $id Landing ID.
	 * @return string
	 */
	public static function landing_url( $id ) {
		return trailingslashit( self::base_url() ) . $id;
	}

	/**
	 * Store an uploaded landing file (.html or .zip) into a landing directory.
	 *
	 * The directory is created when missing; it is NOT cleaned first — callers
	 * that replace files must clean it themselves.
	 *
	 * @param string $dir  Landing directory (absolute path).
	 * @param array  $file Single entry from $_FILES.
	 * @return array{entry:string,type:string,log:string[]}
	 * @throws Exception On validation or filesystem failure.
	 */
	public static function store_upload( $dir, $file ) {
		$log = array();
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! wp_mkdir_p( $dir ) ) {
			throw new Exception( __( 'Could not create the landing directory.', 'static2wp' ) );
		}
		self::harden_base_dir();

		if ( in_array( $ext, array( 'html', 'htm' ), true ) ) {
			$target = $dir . '/index.html';
			if ( ! move_uploaded_file( $file['tmp_name'], $target ) && ! copy( $file['tmp_name'], $target ) ) {
				throw new Exception( __( 'Could not store the uploaded file.', 'static2wp' ) );
			}
			$log[] = __( 'Stored single HTML file as index.html.', 'static2wp' );
			return array( 'entry' => 'index.html', 'type' => 'html', 'log' => $log );
		}

		if ( 'zip' !== $ext ) {
			throw new Exception( __( 'Only .html and .zip files are supported.', 'static2wp' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new Exception( __( 'The PHP ZipArchive extension is not available on this server.', 'static2wp' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file['tmp_name'] ) ) {
			throw new Exception( __( 'The uploaded file is not a valid ZIP archive.', 'static2wp' ) );
		}

		/*
		 * Build a safe extraction list: allow-listed extensions only, no
		 * zip-slip paths, no Windows drive letters, and a cap on the
		 * declared uncompressed size.
		 */
		$allowed    = array();
		$total_size = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) || empty( $stat['name'] ) ) {
				continue;
			}
			$name = str_replace( '\\', '/', $stat['name'] );

			if ( '' === $name || '/' === substr( $name, -1 ) ) {
				continue; // Directory entries are created on demand.
			}
			if ( false !== strpos( $name, '..' ) || 0 === strpos( $name, '/' ) || 0 === strpos( $name, '__MACOSX' ) ) {
				continue;
			}
			if ( preg_match( '/^[A-Za-z]:/', $name ) ) {
				continue; // Windows drive path.
			}
			if ( preg_match( '/(?:^|\/)\.[^\/]*$/', $name ) ) {
				continue; // Hidden files (.DS_Store, .htaccess, ...).
			}
			if ( ! preg_match( '/\.(?:' . self::ALLOW_EXT . ')$/i', $name ) ) {
				/* translators: %s: file name inside the ZIP */
				$log[] = sprintf( __( 'Skipped file (type not allowed): %s', 'static2wp' ), $name );
				continue;
			}
			if ( preg_match( '/\.html?$/i', $name ) && (int) $stat['size'] > self::MAX_HTML ) {
				/* translators: %s: file name inside the ZIP */
				$log[] = sprintf( __( 'Skipped HTML file over 2 MB: %s', 'static2wp' ), $name );
				continue;
			}
			$total_size += (int) $stat['size'];
			if ( $total_size > 524288000 ) { // 500 MB uncompressed.
				$zip->close();
				throw new Exception( __( 'The ZIP expands to more than 500 MB — refusing to extract it.', 'static2wp' ) );
			}
			$allowed[ $i ] = $name;
		}

		if ( empty( $allowed ) ) {
			$zip->close();
			throw new Exception( __( 'The ZIP contains no usable files.', 'static2wp' ) );
		}

		/*
		 * Extract member-by-member with plain file writes. ZipArchive::extractTo()
		 * recreates symlink entries on Unix (LFI / write-through risk) —
		 * file_put_contents() can never create one, and the destination is
		 * realpath-checked against the landing dir.
		 */
		$dir_real = realpath( $dir );
		foreach ( $allowed as $index => $name ) {
			$target = $dir . '/' . $name;
			$parent = dirname( $target );
			if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
				$zip->close();
				throw new Exception( __( 'Could not extract the ZIP archive.', 'static2wp' ) );
			}
			$parent_real = realpath( $parent );
			if ( false === $dir_real || false === $parent_real || 0 !== strpos( $parent_real, $dir_real ) ) {
				$zip->close();
				throw new Exception( __( 'The ZIP contains an unsafe path.', 'static2wp' ) );
			}
			$contents = $zip->getFromIndex( $index );
			if ( false === $contents || false === file_put_contents( $target, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$zip->close();
				throw new Exception( __( 'Could not extract the ZIP archive.', 'static2wp' ) );
			}
		}
		$zip->close();

		/* translators: %d: number of extracted files */
		$log[] = sprintf( _n( 'Extracted %d file.', 'Extracted %d files.', count( $allowed ), 'static2wp' ), count( $allowed ) );

		// If the ZIP wrapped everything in a single folder, move its contents up.
		$root = self::resolve_root( $dir );
		if ( $root !== $dir ) {
			self::move_contents_up( $root, $dir );
			/* translators: %s: folder name */
			$log[] = sprintf( __( 'Detected wrapping folder "%s" — moved contents up.', 'static2wp' ), basename( $root ) );
		}

		// Pick the entry HTML file: shallowest index.html wins, else first HTML.
		$html_files = self::find_files( $dir, '/\.html?$/i' );
		if ( empty( $html_files ) ) {
			throw new Exception( __( 'No .html file found in the uploaded ZIP.', 'static2wp' ) );
		}
		usort(
			$html_files,
			function ( $a, $b ) {
				return substr_count( $a, '/' ) <=> substr_count( $b, '/' ) ?: strcasecmp( $a, $b );
			}
		);

		$entry = '';
		foreach ( $html_files as $candidate ) {
			if ( 'index' === strtolower( pathinfo( $candidate, PATHINFO_FILENAME ) ) ) {
				$entry = $candidate;
				break;
			}
		}
		if ( '' === $entry ) {
			$entry = $html_files[0];
			/* translators: %s: file name */
			$log[] = sprintf( __( 'No index.html found — using "%s" as the entry page.', 'static2wp' ), $entry );
		}

		return array( 'entry' => $entry, 'type' => 'zip', 'log' => $log );
	}

	/**
	 * Drop an index.php + .htaccess into the landings base dir so directory
	 * listings are silenced and script execution is disabled where Apache
	 * honors it (defense in depth — the allow-list already blocks PHP).
	 *
	 * @return void
	 */
	private static function harden_base_dir() {
		$base = self::base_dir();
		if ( '' === $base || ! is_dir( $base ) ) {
			return;
		}

		$guard = $base . '/index.php';
		if ( ! file_exists( $guard ) ) {
			@file_put_contents( $guard, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$htaccess = $base . '/.htaccess';
		if ( ! file_exists( $htaccess ) && $GLOBALS['is_apache'] ) {
			@file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$htaccess,
				"Options -Indexes\n<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n"
			);
		}
	}

	/**
	 * If a directory holds exactly one visible folder, return its path.
	 *
	 * @param string $dir Directory.
	 * @return string The wrapping dir, or $dir itself.
	 */
	private static function resolve_root( $dir ) {
		$entries = array();
		foreach ( (array) scandir( $dir ) as $item ) {
			if ( '.' === $item[0] || '__MACOSX' === $item ) {
				continue;
			}
			$entries[] = $item;
		}
		if ( 1 === count( $entries ) && is_dir( $dir . '/' . $entries[0] ) ) {
			return $dir . '/' . $entries[0];
		}
		return $dir;
	}

	/**
	 * Move all contents of $from into $to, then remove $from.
	 *
	 * @param string $from Source dir.
	 * @param string $to   Destination dir.
	 */
	private static function move_contents_up( $from, $to ) {
		foreach ( (array) scandir( $from ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			rename( $from . '/' . $item, $to . '/' . $item );
		}
		rmdir( $from );
	}

	/**
	 * Shared creation pipeline used by the admin screen and the page-editor
	 * canvas: validate, upload, register.
	 *
	 * Capabilities: creating a new page requires publish_pages; attaching to
	 * an existing page requires edit_post for that page.
	 *
	 * @param int    $page_id     WordPress page ID (0 = create a new page).
	 * @param string $name        Landing name (falls back to the page title).
	 * @param string $file_key    Key in $_FILES.
	 * @param string $stage_token Optional token from a prior ajax_stage upload.
	 * @return array Outcome: success, id, name, view_url, page_id, page, type, log, error.
	 */
	public static function create_for_page( $page_id, $name, $file_key, $stage_token = '' ) {
		$outcome = array(
			'success'  => false,
			'id'       => '',
			'name'     => '',
			'view_url' => '',
			'page_id'  => 0,
			'page'     => '',
			'type'     => '',
			'log'      => array(),
			'error'    => '',
		);

		$staged = '' !== $stage_token ? self::get_stage( $stage_token ) : null;
		if ( $stage_token && ! $staged ) {
			$outcome['error'] = __( 'The uploaded file expired. Please choose the file again.', 'static2wp' );
			return $outcome;
		}

		$source_name = '';
		if ( $staged ) {
			$source_name = $staged['name'];
		} else {
			$file_error = self::validate_upload( $file_key );
			if ( $file_error ) {
				$outcome['error'] = $file_error;
				return $outcome;
			}
			$source_name = isset( $_FILES[ $file_key ]['name'] ) ? (string) $_FILES[ $file_key ]['name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		$page_id = absint( $page_id );
		$page    = $page_id ? get_post( $page_id ) : null;

		if ( ! $page && ! current_user_can( 'publish_pages' ) ) {
			$outcome['error'] = __( 'You are not allowed to publish new pages.', 'static2wp' );
			return $outcome;
		}

		if ( $page && ! current_user_can( 'edit_post', $page->ID ) ) {
			$outcome['error'] = __( 'You are not allowed to edit that page.', 'static2wp' );
			return $outcome;
		}

		if ( ! $page ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				$name = pathinfo( $source_name, PATHINFO_FILENAME );
			}
			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => sanitize_text_field( $name ),
					'post_content' => '',
				),
				true
			);
			if ( is_wp_error( $page_id ) || ! $page_id ) {
				$outcome['error'] = __( 'Could not create the page. Please try again.', 'static2wp' );
				return $outcome;
			}
			$page = get_post( $page_id );
		}

		if ( ! $page || 'page' !== $page->post_type ) {
			$outcome['error'] = __( 'Please choose a valid page.', 'static2wp' );
			return $outcome;
		}

		if ( 'auto-draft' === $page->post_status ) {
			// Matches the editor UI: unsaved pages are rejected, never published silently.
			$outcome['error'] = __( 'Save the page first, then upload the file.', 'static2wp' );
			return $outcome;
		}

		if ( self::find_by_page( $page_id ) ) {
			$outcome['error'] = __( 'That page already has a landing assigned. Replace its file or delete it first.', 'static2wp' );
			return $outcome;
		}

		if ( '' === self::base_dir() ) {
			$outcome['error'] = __( 'The uploads directory is not writable.', 'static2wp' );
			return $outcome;
		}

		$name = trim( (string) $name );
		if ( '' === $name ) {
			$name = '' !== trim( $page->post_title ) ? $page->post_title : pathinfo( $source_name, PATHINFO_FILENAME );
		}

		$id  = self::new_id();
		$dir = self::landing_dir( $id );

		try {
			if ( $staged ) {
				$stored = self::store_from_path( $dir, $staged['path'] );
			} else {
				$stored = self::store_upload( $dir, $_FILES[ $file_key ] );
			}
		} catch ( Exception $e ) {
			self::remove_dir( $dir );
			$outcome['log']   = array( $e->getMessage() );
			$outcome['error'] = $e->getMessage();
			return $outcome;
		}

		if ( $staged ) {
			self::release_stage( $stage_token );
		}

		$now = current_time( 'mysql' );

		self::save(
			$id,
			array(
				'name'            => $name,
				'page_id'         => $page_id,
				'entry'           => $stored['entry'],
				'type'            => $stored['type'],
				'active'          => true,
				'created'         => $now,
				'updated'         => $now,
				'current_version' => 1,
				'versions'        => array(
					array(
						'v'       => 1,
						'dir'     => '',
						'entry'   => $stored['entry'],
						'type'    => $stored['type'],
						'created' => $now,
					),
				),
			)
		);

		$outcome['success']  = true;
		$outcome['id']       = $id;
		$outcome['name']     = $name;
		$outcome['type']     = $stored['type'];
		$outcome['view_url'] = get_permalink( $page );
		$outcome['page_id']  = (int) $page_id;
		$outcome['page']     = $page->post_title;
		$outcome['log']      = $stored['log'];
		return $outcome;
	}

	/**
	 * Validate an uploaded file. Returns an error message or empty string.
	 *
	 * Real content check via wp_check_filetype_and_ext() — the filename
	 * extension alone is not trusted.
	 *
	 * @param string $file_key Key in $_FILES.
	 * @return string
	 */
	public static function validate_upload( $file_key ) {
		if ( empty( $_FILES[ $file_key ] ) || ! isset( $_FILES[ $file_key ]['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES[ $file_key ]['error'] ) {
			$code = isset( $_FILES[ $file_key ]['error'] ) ? (int) $_FILES[ $file_key ]['error'] : -1;
			switch ( $code ) {
				case UPLOAD_ERR_INI_SIZE:
				case UPLOAD_ERR_FORM_SIZE:
					return __( 'The file is too large for this server.', 'static2wp' );
				case UPLOAD_ERR_PARTIAL:
					return __( 'The upload was incomplete — please try again.', 'static2wp' );
				case UPLOAD_ERR_NO_FILE:
					return __( 'No file was uploaded.', 'static2wp' );
				default:
					return __( 'Upload failed. Please try again.', 'static2wp' );
			}
		}

		if ( $_FILES[ $file_key ]['size'] > self::MAX_UPLOAD ) {
			return __( 'File is larger than 100 MB.', 'static2wp' );
		}

		$ext = strtolower( pathinfo( $_FILES[ $file_key ]['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'html', 'htm', 'zip' ), true ) ) {
			return __( 'Only .html and .zip files are supported.', 'static2wp' );
		}

		$check = wp_check_filetype_and_ext(
			$_FILES[ $file_key ]['tmp_name'],
			$_FILES[ $file_key ]['name'],
			array(
				'html' => 'text/html',
				'htm'  => 'text/html',
				'zip'  => 'application/zip',
			)
		);
		if ( empty( $check['ext'] ) || ! in_array( $check['ext'], array( 'html', 'htm', 'zip' ), true ) ) {
			return __( 'That file does not look like a real .html or .zip file.', 'static2wp' );
		}

		if ( in_array( $ext, array( 'html', 'htm' ), true ) && (int) $_FILES[ $file_key ]['size'] > self::MAX_HTML ) {
			return __( 'The HTML file is larger than 2 MB.', 'static2wp' );
		}

		return '';
	}

	/**
	 * Park an HTTP upload in a staging folder and return a short-lived token.
	 *
	 * @param string $file_key Key in $_FILES.
	 * @return array{token?:string,name?:string,size?:int,error?:string}
	 */
	public static function stage_upload( $file_key ) {
		$error = self::validate_upload( $file_key );
		if ( $error ) {
			return array( 'error' => $error );
		}

		$token = strtolower( wp_generate_password( 16, false, false ) );
		$dir   = trailingslashit( self::base_dir() ) . '_staging/' . $token;
		if ( ! wp_mkdir_p( $dir ) ) {
			return array( 'error' => __( 'Could not create the landing directory.', 'static2wp' ) );
		}

		$name = sanitize_file_name( (string) $_FILES[ $file_key ]['name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $name ) {
			$name = 'upload.zip';
		}
		$dest = $dir . '/' . $name;
		if ( ! move_uploaded_file( $_FILES[ $file_key ]['tmp_name'], $dest ) && ! copy( $_FILES[ $file_key ]['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::remove_dir( $dir );
			return array( 'error' => __( 'Could not store the uploaded file.', 'static2wp' ) );
		}

		set_transient(
			's2wp_stage_' . $token,
			array(
				'user' => get_current_user_id(),
				'path' => $dest,
				'name' => $name,
			),
			HOUR_IN_SECONDS
		);

		return array(
			'token' => $token,
			'name'  => $name,
			'size'  => (int) filesize( $dest ),
		);
	}

	/**
	 * Read a staged file for the current user without consuming it.
	 *
	 * @param string $token Staging token.
	 * @return array{path:string,name:string}|null
	 */
	public static function get_stage( $token ) {
		$token = is_string( $token ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) ) : '';
		if ( '' === $token ) {
			return null;
		}

		$data = get_transient( 's2wp_stage_' . $token );
		if ( ! is_array( $data ) || (int) $data['user'] !== get_current_user_id() || empty( $data['path'] ) || ! is_file( $data['path'] ) ) {
			return null;
		}

		return array(
			'path' => $data['path'],
			'name' => isset( $data['name'] ) ? $data['name'] : basename( $data['path'] ),
		);
	}

	/**
	 * Delete a staged upload after it has been published.
	 *
	 * @param string $token Staging token.
	 */
	public static function release_stage( $token ) {
		$staged = self::get_stage( $token );
		$token  = is_string( $token ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) ) : '';
		if ( '' !== $token ) {
			delete_transient( 's2wp_stage_' . $token );
		}
		if ( $staged ) {
			self::remove_dir( dirname( $staged['path'] ) );
		}
	}

	/**
	 * List unused staged uploads on disk.
	 *
	 * @return array<int,array{token:string,name:string,size:int}>
	 */
	public static function list_staged() {
		$base = trailingslashit( self::base_dir() ) . '_staging';
		if ( '' === self::base_dir() || ! is_dir( $base ) ) {
			return array();
		}

		$user = get_current_user_id();
		$out  = array();
		$scan = scandir( $base );
		if ( ! is_array( $scan ) ) {
			return array();
		}

		foreach ( $scan as $token ) {
			if ( '.' === $token || '..' === $token ) {
				continue;
			}
			$token = preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) );
			$dir   = $base . '/' . $token;
			if ( '' === $token || ! is_dir( $dir ) ) {
				continue;
			}

			$data  = get_transient( 's2wp_stage_' . $token );
			$owner = is_array( $data ) && isset( $data['user'] ) ? (int) $data['user'] : 0;
			if ( $owner && $owner !== $user && ! current_user_can( 'manage_options' ) ) {
				continue;
			}

			$name = is_array( $data ) && ! empty( $data['name'] ) ? $data['name'] : '';
			$size = 0;
			if ( is_array( $data ) && ! empty( $data['path'] ) && is_file( $data['path'] ) ) {
				$size = (int) filesize( $data['path'] );
			}
			if ( '' === $name || ! $size ) {
				$files = glob( $dir . '/*' );
				if ( is_array( $files ) ) {
					foreach ( $files as $file ) {
						if ( is_file( $file ) ) {
							if ( '' === $name ) {
								$name = basename( $file );
							}
							if ( ! $size ) {
								$size = (int) filesize( $file );
							}
							break;
						}
					}
				}
			}

			$out[] = array(
				'token' => $token,
				'name'  => $name ? $name : $token,
				'size'  => $size,
			);
		}

		return $out;
	}

	/**
	 * Whether a landing file can be deleted (not attached to a live page).
	 *
	 * @param string $id Landing ID.
	 * @return bool
	 */
	public static function is_unlinked( $id ) {
		$record = self::get( $id );
		if ( ! $record ) {
			return false;
		}
		$page_id = isset( $record['page_id'] ) ? (int) $record['page_id'] : 0;
		if ( $page_id < 1 ) {
			return true;
		}
		$page = get_post( $page_id );
		return ! ( $page && 'page' === $page->post_type );
	}

	/**
	 * Delete a staged upload by token (current user, or admin for orphans).
	 *
	 * @param string $token Staging token.
	 * @return bool
	 */
	public static function delete_staged( $token ) {
		$token = is_string( $token ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) ) : '';
		if ( '' === $token ) {
			return false;
		}

		if ( self::get_stage( $token ) ) {
			self::release_stage( $token );
			return true;
		}

		$dir = trailingslashit( self::base_dir() ) . '_staging/' . $token;
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$data = get_transient( 's2wp_stage_' . $token );
		if ( is_array( $data ) && isset( $data['user'] ) && (int) $data['user'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		delete_transient( 's2wp_stage_' . $token );
		self::remove_dir( $dir );
		return true;
	}

	/**
	 * Validate a local filesystem path as an .html / .zip landing file.
	 *
	 * @param string $path Absolute or relative path.
	 * @return string Error message, or empty string when valid.
	 */
	public static function validate_local_file( $path ) {
		$path = is_string( $path ) ? $path : '';
		if ( '' === $path ) {
			return __( 'No file path given.', 'static2wp' );
		}

		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return __( 'That file does not exist or is not readable.', 'static2wp' );
		}

		$size = filesize( $real );
		if ( false === $size || $size > self::MAX_UPLOAD ) {
			return __( 'File is larger than 100 MB.', 'static2wp' );
		}

		$ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'html', 'htm', 'zip' ), true ) ) {
			return __( 'Only .html and .zip files are supported.', 'static2wp' );
		}

		$check = wp_check_filetype_and_ext(
			$real,
			basename( $real ),
			array(
				'html' => 'text/html',
				'htm'  => 'text/html',
				'zip'  => 'application/zip',
			)
		);
		if ( empty( $check['ext'] ) || ! in_array( $check['ext'], array( 'html', 'htm', 'zip' ), true ) ) {
			return __( 'That file does not look like a real .html or .zip file.', 'static2wp' );
		}

		if ( in_array( $ext, array( 'html', 'htm' ), true ) && $size > self::MAX_HTML ) {
			return __( 'The HTML file is larger than 2 MB.', 'static2wp' );
		}

		return '';
	}

	/**
	 * Store a local filesystem file into a landing directory (WP-CLI / scripts).
	 *
	 * The source file is copied, never deleted.
	 *
	 * @param string $dir  Landing directory.
	 * @param string $path Path to .html or .zip.
	 * @return array{entry:string,type:string,log:string[]}
	 * @throws Exception On validation or filesystem failure.
	 */
	public static function store_from_path( $dir, $path ) {
		$error = self::validate_local_file( $path );
		if ( $error ) {
			throw new Exception( $error );
		}

		$real = realpath( $path );
		return self::store_upload(
			$dir,
			array(
				'name'     => basename( $real ),
				'tmp_name' => $real,
				'size'     => filesize( $real ),
				'error'    => 0,
			)
		);
	}

	/**
	 * Append a new version from a local file and make it live.
	 *
	 * @param string $id   Landing ID.
	 * @param string $path Path to .html or .zip.
	 * @return array{success:bool,v:int,log:string[],error:string,record:array}
	 */
	public static function add_version_from_path( $id, $path ) {
		$record = self::get( $id );
		if ( ! $record ) {
			return array(
				'success' => false,
				'v'       => 0,
				'log'     => array(),
				'error'   => __( 'This landing page no longer exists.', 'static2wp' ),
				'record'  => array(),
			);
		}

		$versions = self::normalize_versions( $record );
		$v        = self::next_version_number( $id, $versions );
		$now      = current_time( 'mysql' );

		$dir = trailingslashit( self::landing_dir( $id ) ) . 'v-' . $v;
		try {
			$stored = self::store_from_path( $dir, $path );
		} catch ( Throwable $e ) {
			self::remove_dir( $dir );
			return array(
				'success' => false,
				'v'       => 0,
				'log'     => array( $e->getMessage() ),
				'error'   => $e->getMessage(),
				'record'  => $record,
			);
		}

		$versions[] = array(
			'v'       => $v,
			'dir'     => 'v-' . $v,
			'entry'   => $stored['entry'],
			'type'    => $stored['type'],
			'created' => $now,
		);

		$record['versions']        = $versions;
		$record['current_version'] = $v;
		$record['entry']           = $stored['entry'];
		$record['type']            = $stored['type'];
		$record['updated']         = $now;
		self::save( $id, $record );

		return array(
			'success' => true,
			'v'       => $v,
			'log'     => $stored['log'],
			'error'   => '',
			'record'  => $record,
		);
	}

	/**
	 * Next version number whose v-N folder does not already exist.
	 *
	 * @param string $id       Landing ID.
	 * @param array  $versions Existing versions.
	 * @return int
	 */
	public static function next_version_number( $id, $versions ) {
		$v = 1;
		foreach ( $versions as $version ) {
			$v = max( $v, (int) $version['v'] + 1 );
		}
		$base = trailingslashit( self::landing_dir( $id ) );
		while ( is_dir( $base . 'v-' . $v ) ) {
			$v++;
		}
		return $v;
	}

	/**
	 * Normalize the version list of a record. Legacy records (single upload,
	 * files at the landing-dir root) become version 1 with dir ''.
	 *
	 * @param array $record Landing record (with 'id').
	 * @return array[] List of versions: {v, dir, entry, type, created}.
	 */
	public static function normalize_versions( $record ) {
		if ( ! empty( $record['versions'] ) && is_array( $record['versions'] ) ) {
			return array_values( $record['versions'] );
		}
		return array(
			array(
				'v'       => 1,
				'dir'     => '',
				'entry'   => isset( $record['entry'] ) ? $record['entry'] : 'index.html',
				'type'    => isset( $record['type'] ) ? $record['type'] : 'html',
				'created' => ! empty( $record['created'] ) ? $record['created'] : '',
			),
		);
	}

	/**
	 * The currently active version of a record.
	 *
	 * @param array $record Landing record (with 'id').
	 * @return array|null Version or null.
	 */
	public static function current_version( $record ) {
		$current_v = isset( $record['current_version'] ) ? (int) $record['current_version'] : 1;
		foreach ( self::normalize_versions( $record ) as $version ) {
			if ( (int) $version['v'] === $current_v ) {
				return $version;
			}
		}
		$versions = self::normalize_versions( $record );
		return $versions ? $versions[0] : null;
	}

	/**
	 * Entry file (relative path) of the currently active version.
	 *
	 * @param array $record Landing record.
	 * @return string
	 */
	public static function current_entry( $record ) {
		$version = self::current_version( $record );
		return $version ? $version['entry'] : ( isset( $record['entry'] ) ? $record['entry'] : '' );
	}

	/**
	 * Absolute path of the currently active version's entry file.
	 *
	 * @param array $record Landing record (with 'id').
	 * @return string Empty string when unknown.
	 */
	public static function current_entry_path( $record ) {
		$version = self::current_version( $record );
		if ( ! $version || empty( $record['id'] ) ) {
			return '';
		}
		$base = trailingslashit( self::landing_dir( $record['id'] ) );
		return $base . ( '' !== $version['dir'] ? $version['dir'] . '/' : '' ) . $version['entry'];
	}

	/**
	 * Public URL of the directory containing the currently active version.
	 *
	 * @param array $record Landing record (with 'id').
	 * @return string
	 */
	public static function current_base_url( $record ) {
		if ( empty( $record['id'] ) ) {
			return '';
		}
		$version = self::current_version( $record );
		$base    = trailingslashit( self::landing_url( $record['id'] ) );
		return $base . ( $version && '' !== $version['dir'] ? $version['dir'] . '/' : '' );
	}

	/**
	 * Absolute directory of one version.
	 *
	 * @param string $id      Landing ID.
	 * @param array  $version Version record.
	 * @return string
	 */
	public static function version_dir( $id, $version ) {
		$base = trailingslashit( self::landing_dir( $id ) );
		return $base . ( '' !== $version['dir'] ? $version['dir'] : '' );
	}

	/**
	 * Delete the files of one version. For the legacy root version (''), only
	 * root-level entries are removed — sibling v-N/ directories are kept.
	 *
	 * @param string $id      Landing ID.
	 * @param array  $version Version record.
	 */
	public static function delete_version_files( $id, $version ) {
		$dir = self::version_dir( $id, $version );
		if ( ! is_dir( $dir ) ) {
			return;
		}

		if ( '' !== $version['dir'] ) {
			self::remove_dir( $dir );
			return;
		}

		foreach ( (array) scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && preg_match( '/^v-\d+$/', $item ) ) {
				continue; // Other versions live here.
			}
			is_dir( $path ) ? self::remove_dir( $path ) : unlink( $path );
		}
	}

	/**
	 * Recursively find files under a dir matching a pattern; returns paths
	 * relative to the dir, using forward slashes.
	 *
	 * @param string $dir     Base dir.
	 * @param string $pattern Regex for the relative path.
	 * @return string[]
	 */
	public static function find_files( $dir, $pattern ) {
		$out      = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			$pathname = str_replace( '\\', '/', $file->getPathname() );
			$rel      = ltrim( substr( $pathname, strlen( str_replace( '\\', '/', $dir ) ) ), '/' );
			if ( preg_match( $pattern, $rel ) ) {
				$out[] = $rel;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Dir.
	 */
	public static function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) || '' === $dir ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $dir );
	}
}
