<?php
/**
 * WP-CLI commands for Static2WP.
 *
 * Files are never uploaded through HTTP. Pass a path on the machine running
 * WP-CLI (--file) or a URL that the server can download (--url).
 *
 * @package Static2WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Static2WP landings from the command line.
 *
 * There is no HTTP upload. Use --file (path on this machine) or --url
 * (server download). Mutating commands require --user. Full reference:
 * docs/wp-cli.md in the plugin folder.
 *
 * ## EXAMPLES
 *
 *     wp s2wp list
 *     wp s2wp add --file=./landing.zip --page=about --user=admin
 *     wp s2wp add --url=https://example.com/site.zip --name="Summer" --user=admin
 *     wp s2wp version s2wp_abc123 --file=./v2.zip --user=admin
 */
class S2WP_CLI {

	/**
	 * List all landings.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp s2wp list
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function list( $args, $assoc_args ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.listFound
		$rows = array();
		foreach ( S2WP_Store::get_all() as $id => $record ) {
			$page = ! empty( $record['page_id'] ) ? get_post( (int) $record['page_id'] ) : null;
			$rows[] = array(
				'id'      => $id,
				'name'    => isset( $record['name'] ) ? $record['name'] : '',
				'page'    => $page ? $page->post_name : (string) ( isset( $record['page_id'] ) ? $record['page_id'] : '' ),
				'page_id' => isset( $record['page_id'] ) ? (int) $record['page_id'] : 0,
				'status'  => ! empty( $record['active'] ) ? 'on' : 'off',
				'type'    => isset( $record['type'] ) ? $record['type'] : '',
				'version' => isset( $record['current_version'] ) ? (int) $record['current_version'] : 1,
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( 'ids' === $format ) {
			WP_CLI::log( implode( ' ', wp_list_pluck( $rows, 'id' ) ) );
			return;
		}
		if ( 'count' === $format ) {
			WP_CLI::log( (string) count( $rows ) );
			return;
		}

		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'name', 'page', 'page_id', 'status', 'type', 'version' ) );
	}

	/**
	 * Show one landing.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Landing ID (s2wp_…).
	 *
	 * ## EXAMPLES
	 *
	 *     wp s2wp get s2wp_abc123def456
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional args.
	 */
	public function get( $args ) {
		$id     = S2WP_Store::validate_id( $args[0] );
		$record = $id ? S2WP_Store::get( $id ) : null;
		if ( ! $record ) {
			WP_CLI::error( 'Landing not found.' );
		}

		$record['id']       = $id;
		$record['versions'] = S2WP_Store::normalize_versions( $record );
		WP_CLI::print_value( $record, array( 'format' => 'yaml' ) );
	}

	/**
	 * Attach an HTML/ZIP file to a page (creates the page if --page is omitted).
	 *
	 * Pass a file that already exists on this machine, or a URL the server can fetch.
	 * WP-CLI cannot receive a browser upload.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Path to a .html or .zip file on this machine (absolute, or relative to the current directory).
	 *
	 * [--url=<url>]
	 * : HTTP(S) URL of a .html or .zip file. Downloaded to a temp file, then ingested.
	 *
	 * [--page=<page>]
	 * : Existing page ID, slug, or URL. Omit to create a new published page.
	 *
	 * [--name=<name>]
	 * : Landing / page title. Defaults to the file name or the page title.
	 *
	 * ## EXAMPLES
	 *
 *     wp s2wp add --file=./dist/index.html --name="Summer" --user=admin
 *     wp s2wp add --file=/tmp/campaign.zip --page=about --user=admin
 *     wp s2wp add --url=https://example.com/site.zip --page=42 --user=admin
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function add( $args, $assoc_args ) {
		$this->require_user();

		$path    = $this->resolve_source( $assoc_args );
		$page_id = $this->resolve_page( isset( $assoc_args['page'] ) ? $assoc_args['page'] : '' );
		$name    = isset( $assoc_args['name'] ) ? sanitize_text_field( $assoc_args['name'] ) : '';

		if ( $page_id ) {
			$this->require_caps( array( 'unfiltered_html' ) );
			if ( ! current_user_can( 'edit_post', $page_id ) ) {
				$this->cleanup_temp( $path, $assoc_args );
				WP_CLI::error( 'You are not allowed to edit that page.' );
			}
		} else {
			$this->require_caps( array( 'unfiltered_html', 'publish_pages' ) );
		}

		if ( $page_id && S2WP_Store::find_by_page( $page_id ) ) {
			$this->cleanup_temp( $path, $assoc_args );
			WP_CLI::error( 'That page already has a landing. Use `wp s2wp version <id> --file=…` instead.' );
		}

		if ( ! $page_id ) {
			if ( '' === $name ) {
				$name = pathinfo( $path, PATHINFO_FILENAME );
			}
			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $name,
					'post_content' => '',
				),
				true
			);
			if ( is_wp_error( $page_id ) || ! $page_id ) {
				$this->cleanup_temp( $path, $assoc_args );
				WP_CLI::error( 'Could not create the page.' );
			}
		}

		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			$this->cleanup_temp( $path, $assoc_args );
			WP_CLI::error( 'Please pass a valid page ID, slug, or URL.' );
		}

		if ( '' === $name ) {
			$name = $page->post_title;
		}

		$id  = S2WP_Store::new_id();
		$dir = S2WP_Store::landing_dir( $id );

		try {
			$stored = S2WP_Store::store_from_path( $dir, $path );
		} catch ( Exception $e ) {
			S2WP_Store::remove_dir( $dir );
			$this->cleanup_temp( $path, $assoc_args );
			WP_CLI::error( $e->getMessage() );
		}

		$this->cleanup_temp( $path, $assoc_args );

		$now = current_time( 'mysql' );
		S2WP_Store::save(
			$id,
			array(
				'name'            => $name,
				'page_id'         => (int) $page_id,
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

		foreach ( $stored['log'] as $line ) {
			WP_CLI::log( $line );
		}

		WP_CLI::success(
			sprintf(
				'Landing %s is live on %s (%s)',
				$id,
				get_permalink( $page ),
				$stored['type']
			)
		);
	}

	/**
	 * Upload a new version of an existing landing and make it live.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Landing ID.
	 *
	 * [--file=<path>]
	 * : Path to a .html or .zip file on this machine.
	 *
	 * [--url=<url>]
	 * : HTTP(S) URL of a .html or .zip file.
	 *
	 * ## EXAMPLES
	 *
 *     wp s2wp version s2wp_abc123 --file=./v2.zip --user=admin
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function version( $args, $assoc_args ) {
		$this->require_user();
		$this->require_caps( array( 'unfiltered_html' ) );

		$id     = $this->require_landing( $args[0] );
		$path   = $this->resolve_source( $assoc_args );
		$result = S2WP_Store::add_version_from_path( $id, $path );
		$this->cleanup_temp( $path, $assoc_args );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] );
		}

		foreach ( $result['log'] as $line ) {
			WP_CLI::log( $line );
		}

		WP_CLI::success( sprintf( 'Version %d is now live.', (int) $result['v'] ) );
	}

	/**
	 * Show the uploaded file to visitors.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Landing ID.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional args.
	 */
	public function activate( $args ) {
		$this->require_caps( array( 'edit_pages' ) );
		$this->set_active( $args[0], true );
		WP_CLI::success( 'Visitors will see the file.' );
	}

	/**
	 * Show the normal WordPress page to visitors.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Landing ID.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional args.
	 */
	public function deactivate( $args ) {
		$this->require_caps( array( 'edit_pages' ) );
		$this->set_active( $args[0], false );
		WP_CLI::success( 'Visitors will see the normal page.' );
	}

	/**
	 * Roll back to a previous version.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Landing ID.
	 *
	 * --v=<n>
	 * : Version number.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function rollback( $args, $assoc_args ) {
		$this->require_caps( array( 'edit_pages' ) );
		$id      = $this->require_landing( $args[0] );
		$record  = S2WP_Store::get( $id );
		$v       = isset( $assoc_args['v'] ) ? absint( $assoc_args['v'] ) : 0;
		$version = $this->find_version( $record, $v );

		$record['current_version'] = $v;
		$record['entry']           = $version['entry'];
		$record['type']            = $version['type'];
		$record['updated']         = current_time( 'mysql' );
		S2WP_Store::save( $id, $record );

		WP_CLI::success( sprintf( 'Rolled back to version %d.', $v ) );
	}

	/**
	 * Delete one stored version (not the last remaining one).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Landing ID.
	 *
	 * --v=<n>
	 * : Version number.
	 *
	 * @subcommand delete-version
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function delete_version( $args, $assoc_args ) {
		$this->require_caps( array( 'edit_pages' ) );
		$id      = $this->require_landing( $args[0] );
		$record  = S2WP_Store::get( $id );
		$record['id']       = $id;
		$record['versions'] = S2WP_Store::normalize_versions( $record );
		$v                  = isset( $assoc_args['v'] ) ? absint( $assoc_args['v'] ) : 0;
		$version            = $this->find_version( $record, $v );

		if ( 1 === count( $record['versions'] ) ) {
			WP_CLI::error( 'This is the only version — delete the landing itself instead.' );
		}

		S2WP_Store::delete_version_files( $id, $version );

		$remaining = array();
		foreach ( $record['versions'] as $keep ) {
			if ( (int) $keep['v'] !== $v ) {
				$remaining[] = $keep;
			}
		}
		$record['versions'] = $remaining;

		if ( (int) ( isset( $record['current_version'] ) ? $record['current_version'] : 1 ) === $v ) {
			$newest_v = 0;
			$newest   = null;
			foreach ( $remaining as $keep ) {
				if ( (int) $keep['v'] > $newest_v ) {
					$newest_v = (int) $keep['v'];
					$newest   = $keep;
				}
			}
			if ( $newest ) {
				$record['current_version'] = (int) $newest['v'];
				$record['entry']           = $newest['entry'];
				$record['type']            = $newest['type'];
			}
		}
		$record['updated'] = current_time( 'mysql' );
		S2WP_Store::save( $id, $record );

		WP_CLI::success( sprintf( 'Version %d deleted.', $v ) );
	}

	/**
	 * Delete a landing (the WordPress page is kept).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Landing ID.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function delete( $args, $assoc_args ) {
		$this->require_caps( array( 'edit_pages' ) );
		$id = $this->require_landing( $args[0] );
		WP_CLI::confirm( 'Delete this landing? The WordPress page is kept.', $assoc_args );
		S2WP_Store::delete( $id );
		WP_CLI::success( 'Landing deleted.' );
	}

	/**
	 * Resolve --file or --url into a local path.
	 *
	 * @param array $assoc_args Assoc args.
	 * @return string Absolute path.
	 */
	private function resolve_source( $assoc_args ) {
		$file = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';
		$url  = isset( $assoc_args['url'] ) ? (string) $assoc_args['url'] : '';

		if ( '' !== $file && '' !== $url ) {
			WP_CLI::error( 'Pass either --file or --url, not both.' );
		}

		if ( '' !== $file ) {
			$error = S2WP_Store::validate_local_file( $file );
			if ( $error ) {
				WP_CLI::error( $error );
			}
			return realpath( $file );
		}

		if ( '' === $url ) {
			WP_CLI::error( 'Pass --file=/path/to/file.zip (on this machine) or --url=https://…' );
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			WP_CLI::error( 'URL must start with http:// or https://.' );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			WP_CLI::error( 'Download failed: ' . $tmp->get_error_message() );
		}

		$ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'html', 'htm', 'zip' ), true ) ) {
			@unlink( $tmp );
			WP_CLI::error( 'URL must point to a .html or .zip file.' );
		}

		$named = $tmp . '.' . $ext;
		if ( ! @rename( $tmp, $named ) ) {
			@unlink( $tmp );
			WP_CLI::error( 'Could not store the downloaded file.' );
		}

		$error = S2WP_Store::validate_local_file( $named );
		if ( $error ) {
			@unlink( $named );
			WP_CLI::error( $error );
		}

		return $named;
	}

	/**
	 * Delete a temp download created from --url.
	 *
	 * @param string $path       Path.
	 * @param array  $assoc_args Assoc args.
	 */
	private function cleanup_temp( $path, $assoc_args ) {
		if ( empty( $assoc_args['url'] ) || ! is_string( $path ) || ! is_file( $path ) ) {
			return;
		}
		@unlink( $path );
	}

	/**
	 * Resolve a page ID, slug, or URL.
	 *
	 * @param string $raw Raw page identifier.
	 * @return int Page ID or 0.
	 */
	private function resolve_page( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return 0;
		}
		if ( ctype_digit( $raw ) ) {
			return (int) $raw;
		}
		if ( preg_match( '#^https?://#i', $raw ) ) {
			return (int) url_to_postid( $raw );
		}
		$page = get_page_by_path( $raw );
		return $page ? (int) $page->ID : 0;
	}

	/**
	 * Require a landing ID that exists.
	 *
	 * @param string $raw Raw ID.
	 * @return string
	 */
	private function require_landing( $raw ) {
		$id = S2WP_Store::validate_id( $raw );
		if ( ! $id || ! S2WP_Store::get( $id ) ) {
			WP_CLI::error( 'Landing not found. Run `wp s2wp list`.' );
		}
		return $id;
	}

	/**
	 * Find a version record or error.
	 *
	 * @param array $record Landing record.
	 * @param int   $v      Version number.
	 * @return array
	 */
	private function find_version( $record, $v ) {
		foreach ( S2WP_Store::normalize_versions( $record ) as $version ) {
			if ( (int) $version['v'] === $v ) {
				return $version;
			}
		}
		WP_CLI::error( 'Version not found.' );
	}

	/**
	 * Toggle active flag.
	 *
	 * @param string $raw    Landing ID.
	 * @param bool   $active Active.
	 */
	private function set_active( $raw, $active ) {
		$id               = $this->require_landing( $raw );
		$record           = S2WP_Store::get( $id );
		$record['active'] = (bool) $active;
		S2WP_Store::save( $id, $record );
	}

	/**
	 * Require a logged-in WordPress user (`wp … --user=`).
	 */
	private function require_user() {
		if ( ! get_current_user_id() ) {
			WP_CLI::error( 'Pass --user=<id|login> so the command runs as a WordPress user.' );
		}
	}

	/**
	 * Require WP-CLI to run as a capable user (`--user=`).
	 *
	 * @param string[] $caps Capabilities.
	 */
	private function require_caps( $caps ) {
		$this->require_user();
		foreach ( $caps as $cap ) {
			if ( ! current_user_can( $cap ) ) {
				WP_CLI::error( sprintf( 'This user cannot %s.', $cap ) );
			}
		}
	}
}
