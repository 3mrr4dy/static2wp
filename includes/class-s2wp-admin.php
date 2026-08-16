<?php
/**
 * Admin layer: menu page, uploads, AJAX endpoints, landing management.
 *
 * @package Static2WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class S2WP_Admin
 */
class S2WP_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var S2WP_Admin|null
	 */
	private static $instance = null;

	/**
	 * Admin page slug.
	 */
	const MENU_SLUG = 'static2wp';

	/**
	 * Get instance.
	 *
	 * @return S2WP_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_s2wp_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_s2wp_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_s2wp_new_version', array( $this, 'ajax_new_version' ) );
		add_action( 'wp_ajax_s2wp_rollback', array( $this, 'ajax_rollback' ) );
		add_action( 'wp_ajax_s2wp_delete_version', array( $this, 'ajax_delete_version' ) );
		add_action( 'wp_ajax_s2wp_delete', array( $this, 'ajax_delete' ) );
		add_action( 'admin_post_s2wp_save', array( $this, 'post_save' ) );
		add_action( 'admin_post_s2wp_settings', array( $this, 'post_settings' ) );
	}

	/**
	 * Add "HTML Landing" under Pages.
	 */
	public function register_menu() {
		add_pages_page(
			__( 'Static2WP', 'static2wp' ),
			__( 'Static2WP', 'static2wp' ),
			'edit_pages',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load CSS/JS only on our page.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'pages_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 's2wp-admin', S2WP_PLUGIN_URL . 'assets/admin.css', array(), S2WP_VERSION );
		wp_enqueue_script( 's2wp-admin', S2WP_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), S2WP_VERSION, true );
		wp_localize_script(
			's2wp-admin',
			'S2WP',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 's2wp_save' ),
				'maxSize' => S2WP_Store::MAX_UPLOAD,
				'strings' => array(
					'noFile'         => __( 'Choose a file first.', 'static2wp' ),
					'badType'        => __( 'Only HTML or ZIP files are allowed.', 'static2wp' ),
					'tooBig'         => __( 'File is larger than 100 MB.', 'static2wp' ),
					'uploading'      => __( 'Uploading…', 'static2wp' ),
					'error'          => __( 'Something went wrong. Try again.', 'static2wp' ),
					'confirmVersion' => __( 'Delete this older file?', 'static2wp' ),
					'confirmDelete'  => __( 'Remove the file from this page? The page itself stays.', 'static2wp' ),
					'showEditor'     => __( 'Edit page text', 'static2wp' ),
					'hideEditor'     => __( 'Hide page text', 'static2wp' ),
					'activeBadge'    => __( 'Active', 'static2wp' ),
					'viewPage'       => __( 'View page', 'static2wp' ),
					'reloadNote'     => __( 'Reload this page to manage your landings.', 'static2wp' ),
					'dismissNotice'  => __( 'Dismiss this notice.', 'static2wp' ),
				),
			)
		);
		wp_set_script_translations( 's2wp-admin', 'static2wp' );
	}

	/* ---------------------------------------------------------------------
	 * Page render
	 * ------------------------------------------------------------------- */

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'static2wp' ) );
		}

		$notice  = isset( $_GET['s2wp_notice'] ) ? sanitize_key( wp_unslash( $_GET['s2wp_notice'] ) ) : '';
		$message = isset( $_GET['s2wp_message'] ) ? sanitize_text_field( wp_unslash( $_GET['s2wp_message'] ) ) : '';
		?>
		<div class="wrap s2wp-wrap">
			<h1><?php esc_html_e( 'Static2WP', 'static2wp' ); ?></h1>
			<p class="s2wp-subtitle"><?php esc_html_e( 'Upload a file. We create a page for it. Visitors see the file — not the theme, not a builder.', 'static2wp' ); ?></p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo 'success' === $notice ? 'success' : 'error'; ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<div class="s2wp-card">
				<form id="s2wp-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="s2wp_save">
					<?php wp_nonce_field( 's2wp_save', 's2wp_nonce' ); ?>

					<div id="s2wp-dropzone" class="s2wp-dropzone" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Drop a file here', 'static2wp' ); ?>">
						<strong><?php esc_html_e( 'Drop a file here', 'static2wp' ); ?></strong>
						<span><?php esc_html_e( 'or click to choose — HTML or ZIP', 'static2wp' ); ?></span>
						<span id="s2wp-filename" class="s2wp-filename"></span>
					</div>
					<input type="file" id="s2wp-file" name="landing_file" accept=".html,.htm,.zip" hidden>

					<div class="s2wp-fields">
						<label>
							<?php esc_html_e( 'Page name', 'static2wp' ); ?>
							<input type="text" name="landing_name" id="s2wp-name" placeholder="<?php esc_attr_e( 'Summer offer', 'static2wp' ); ?>">
						</label>
						<label>
							<?php esc_html_e( 'Or attach to an existing page', 'static2wp' ); ?>
							<?php $this->pages_dropdown(); ?>
						</label>
					</div>

					<button type="submit" id="s2wp-submit" class="button button-primary" disabled>
						<?php esc_html_e( 'Publish page', 'static2wp' ); ?>
					</button>
					<div id="s2wp-log" class="s2wp-log" hidden></div>
					<div id="s2wp-result" class="s2wp-result" hidden></div>
				</form>
			</div>

			<?php $this->render_landings_table(); ?>

			<?php $this->render_settings_card(); ?>
		</div>
		<?php
	}

	/**
	 * Render the pages dropdown: unused published pages only.
	 */
	private function pages_dropdown() {
		$used = array();
		foreach ( S2WP_Store::get_all() as $record ) {
			if ( ! empty( $record['page_id'] ) ) {
				$used[] = (int) $record['page_id'];
			}
		}

		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'post_title',
				'sort_order'  => 'ASC',
				'parent'      => -1,
			)
		);
		?>
		<select name="page_id" id="s2wp-page">
			<option value="0"><?php esc_html_e( 'New page', 'static2wp' ); ?></option>
			<?php foreach ( (array) $pages as $p ) : ?>
				<?php
				if ( in_array( (int) $p->ID, $used, true ) ) {
					continue;
				}
				$label = '' !== trim( $p->post_title ) ? $p->post_title : __( '(no title)', 'static2wp' );
				?>
				<option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render the table of existing landings.
	 */
	private function render_landings_table() {
		$all = S2WP_Store::get_all();
		?>
		<div class="s2wp-card">
			<div class="s2wp-table-head">
				<h2><?php esc_html_e( 'Pages with a file', 'static2wp' ); ?></h2>
				<?php if ( ! empty( $all ) ) : ?>
					<input type="search" id="s2wp-search" class="s2wp-search" placeholder="<?php esc_attr_e( 'Search…', 'static2wp' ); ?>">
				<?php endif; ?>
			</div>

			<?php if ( empty( $all ) ) : ?>
				<div class="s2wp-empty">
					<span class="dashicons dashicons-welcome-widgets-menus"></span>
					<strong><?php esc_html_e( 'No pages yet', 'static2wp' ); ?></strong>
					<p><?php esc_html_e( 'Upload a file above, or open any page and upload it there.', 'static2wp' ); ?></p>
				</div>
			<?php else : ?>
			<table class="widefat striped s2wp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'static2wp' ); ?></th>
						<th><?php esc_html_e( 'Page', 'static2wp' ); ?></th>
						<th><?php esc_html_e( 'Visitors see', 'static2wp' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'static2wp' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $all as $id => $record ) : ?>
					<?php
					$page      = ! empty( $record['page_id'] ) ? get_post( (int) $record['page_id'] ) : null;
					$view_url  = $page ? get_permalink( $page ) : '';
					$is_active = ! empty( $record['active'] );
					$edit_url  = $page ? get_edit_post_link( $page, 'raw' ) : '';
					?>
					<tr data-id="<?php echo esc_attr( $id ); ?>">
						<td><strong><?php echo esc_html( $record['name'] ); ?></strong></td>
						<td>
							<?php if ( $page ) : ?>
								<a href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $page->post_title ); ?></a>
							<?php else : ?>
								<span><?php esc_html_e( 'Page deleted', 'static2wp' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo $is_active ? esc_html__( 'The file', 'static2wp' ) : esc_html__( 'Normal page', 'static2wp' ); ?>
						</td>
						<td class="s2wp-actions">
							<?php if ( $page ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'static2wp' ); ?></a>
							<?php endif; ?>
							<?php if ( $edit_url ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'static2wp' ); ?></a>
							<?php endif; ?>
							<button class="button button-small s2wp-toggle" data-id="<?php echo esc_attr( $id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 's2wp_toggle_' . $id ) ); ?>">
								<?php echo $is_active ? esc_html__( 'Use normal page', 'static2wp' ) : esc_html__( 'Show file', 'static2wp' ); ?>
							</button>
							<button class="button button-small s2wp-delete" data-id="<?php echo esc_attr( $id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 's2wp_delete_' . $id ) ); ?>">
								<?php esc_html_e( 'Remove file', 'static2wp' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="s2wp-no-results" hidden><?php esc_html_e( 'Nothing matches.', 'static2wp' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the global settings card (tracking codes + SEO injection).
	 */
	private function render_settings_card() {
		$settings = S2WP_Store::settings();
		?>
		<div class="s2wp-card">
			<h2><?php esc_html_e( 'Tracking codes', 'static2wp' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="s2wp_settings">
				<?php wp_nonce_field( 's2wp_settings', 's2wp_settings_nonce' ); ?>

				<div class="s2wp-settings-grid">
					<label>
						<strong><?php esc_html_e( 'Head code', 'static2wp' ); ?></strong>
						<span class="s2wp-field-note"><?php esc_html_e( 'Analytics or pixels — added to the top of the page.', 'static2wp' ); ?></span>
						<textarea name="head_code" class="s2wp-code" rows="5" spellcheck="false" placeholder="<!-- GTM / analytics / pixel code -->"><?php echo esc_textarea( $settings['head_code'] ); ?></textarea>
					</label>
					<label>
						<strong><?php esc_html_e( 'Body code', 'static2wp' ); ?></strong>
						<span class="s2wp-field-note"><?php esc_html_e( 'Added as soon as the page opens.', 'static2wp' ); ?></span>
						<textarea name="body_code" class="s2wp-code" rows="5" spellcheck="false" placeholder="<!-- GTM <noscript> -->"><?php echo esc_textarea( $settings['body_code'] ); ?></textarea>
					</label>
				</div>

				<div class="s2wp-options">
					<label><input type="checkbox" name="inject_all" value="1" <?php checked( $settings['inject_all'] ); ?>> <?php esc_html_e( 'Also add these codes on the rest of the site', 'static2wp' ); ?></label>
					<label><input type="checkbox" name="seo_meta" value="1" <?php checked( $settings['seo_meta'] ); ?>> <?php esc_html_e( 'Use the page title and description in Google', 'static2wp' ); ?></label>
				</div>

				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Save', 'static2wp' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------- */

	/**
	 * Save global settings (classic POST).
	 *
	 * Flags need edit_pages only; the raw tracking-code fields additionally
	 * require unfiltered_html — without it the existing codes are kept.
	 */
	public function post_settings() {
		if ( ! isset( $_POST['s2wp_settings_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['s2wp_settings_nonce'] ) ), 's2wp_settings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'static2wp' ) );
		}
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'static2wp' ) );
		}

		$existing = S2WP_Store::settings();

		if ( current_user_can( 'unfiltered_html' ) ) {
			$head_code = isset( $_POST['head_code'] ) ? trim( (string) wp_unslash( $_POST['head_code'] ) ) : '';
			$body_code = isset( $_POST['body_code'] ) ? trim( (string) wp_unslash( $_POST['body_code'] ) ) : '';
		} else {
			$head_code = $existing['head_code'];
			$body_code = $existing['body_code'];
		}

		S2WP_Store::save_settings(
			array(
				'head_code'  => $head_code,
				'body_code'  => $body_code,
				'inject_all' => ! empty( $_POST['inject_all'] ),
				'seo_meta'   => ! empty( $_POST['seo_meta'] ),
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					's2wp_notice'  => 'success',
					's2wp_message' => __( 'Settings saved.', 'static2wp' ),
				),
				admin_url( 'edit.php?post_type=page' )
			)
		);
		exit;
	}

	/**
	 * One-click deactivate was removed (dead GET side-effect endpoint).
	 */

	/**
	 * AJAX: upload + publish a new landing.
	 *
	 * Raw HTML becomes a public document, so it requires unfiltered_html —
	 * the same capability WordPress uses for raw post content.
	 */
	public function ajax_save() {
		check_ajax_referer( 's2wp_save', 'nonce' );

		if ( ! current_user_can( 'edit_pages' ) || ! current_user_can( 'unfiltered_html' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied. Publishing a raw HTML page requires the "unfiltered_html" capability.', 'static2wp' ) ), 403 );
		}

		$outcome = $this->handle_save( $_POST, 'landing_file' );

		if ( $outcome['success'] ) {
			wp_send_json_success( $outcome );
		}
		wp_send_json_error( $outcome );
	}

	/**
	 * No-JS fallback: classic form POST.
	 */
	public function post_save() {
		if ( ! isset( $_POST['s2wp_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['s2wp_nonce'] ) ), 's2wp_save' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'static2wp' ) );
		}
		if ( ! current_user_can( 'edit_pages' ) || ! current_user_can( 'unfiltered_html' ) ) {
			wp_die( esc_html__( 'Permission denied. Publishing a raw HTML page requires the "unfiltered_html" capability.', 'static2wp' ) );
		}

		$outcome = $this->handle_save( $_POST, 'landing_file' );

		$args = array(
			'page'        => self::MENU_SLUG,
			's2wp_notice'  => $outcome['success'] ? 'success' : 'error',
			's2wp_message' => $outcome['success']
				/* translators: %s: landing name */
				? sprintf( __( '"%s" is now live.', 'static2wp' ), $outcome['name'] )
				: $outcome['error'],
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php?post_type=page' ) ) );
		exit;
	}

	/**
	 * AJAX: toggle a landing active/inactive.
	 */
	public function ajax_toggle() {
		$id = $this->verify_row_request( 's2wp_toggle_' );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
		}

		$record            = S2WP_Store::get( $id );
		$record['active']  = empty( $record['active'] );
		S2WP_Store::save( $id, $record );

		$response = array(
			'active'  => $record['active'],
			'message' => $record['active']
				? __( 'Visitors will see the file.', 'static2wp' )
				: __( 'Visitors will see the normal page.', 'static2wp' ),
		);
		$response = $this->with_editor_html( $response, $record );
		wp_send_json_success( $response );
	}

	/**
	 * AJAX: upload a new version of an existing landing (old versions kept).
	 *
	 * Raw HTML becomes a public document → unfiltered_html required.
	 */
	public function ajax_new_version() {
		$id = $this->verify_row_request( 's2wp_version_' );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
		}

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied. Publishing a raw HTML page requires the "unfiltered_html" capability.', 'static2wp' ) ), 403 );
		}

		$outcome = array(
			'success' => false,
			'log'     => array(),
			'error'   => '',
		);

		$file_error = S2WP_Store::validate_upload( 'landing_file' );
		if ( $file_error ) {
			$outcome['error'] = $file_error;
			wp_send_json_error( $outcome );
		}

		$record   = S2WP_Store::get( $id );
		$versions = S2WP_Store::normalize_versions( $record );

		$v   = 1;
		$now = current_time( 'mysql' );
		foreach ( $versions as $version ) {
			$v = max( $v, (int) $version['v'] + 1 );
		}

		$dir = trailingslashit( S2WP_Store::landing_dir( $id ) ) . 'v-' . $v;
		try {
			$stored = S2WP_Store::store_upload( $dir, $_FILES['landing_file'] );
		} catch ( Exception $e ) {
			S2WP_Store::remove_dir( $dir );
			$outcome['log']   = array( $e->getMessage() );
			$outcome['error'] = $e->getMessage();
			wp_send_json_error( $outcome );
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
		S2WP_Store::save( $id, $record );

		/* translators: %d: version number */
		$outcome['success'] = true;
		$outcome['log']     = $stored['log'];
		$outcome['type']    = $stored['type'];
		$outcome['message'] = __( 'The new file is now live.', 'static2wp' );
		$outcome            = $this->with_editor_html( $outcome, $record );
		wp_send_json_success( $outcome );
	}

	/**
	 * AJAX: roll back to a previous version.
	 */
	public function ajax_rollback() {
		$outcome = $this->handle_version_action(
			's2wp_version_',
			function ( &$record, $version, $v ) {
				$record['current_version'] = $v;
				$record['entry']           = $version['entry'];
				$record['type']            = $version['type'];
				$record['updated']         = current_time( 'mysql' );
				/* translators: %d: version number */
				return sprintf( __( 'Rolled back to version %d — it is now live.', 'static2wp' ), $v );
			}
		);
		wp_send_json_success( $outcome );
	}

	/**
	 * AJAX: delete one version (the live version cannot be the last one).
	 */
	public function ajax_delete_version() {
		$outcome = $this->handle_version_action(
			's2wp_version_',
			function ( &$record, $version, $v ) {
				if ( 1 === count( $record['versions'] ) ) {
					return new WP_Error( 's2wp_last', __( 'This is the only version — delete the landing itself instead.', 'static2wp' ) );
				}

				S2WP_Store::delete_version_files( $record['id'], $version );

				$remaining = array();
				foreach ( $record['versions'] as $keep ) {
					if ( (int) $keep['v'] !== $v ) {
						$remaining[] = $keep;
					}
				}
				$record['versions'] = $remaining;

				// If the deleted version was live, fall back to the newest remaining.
				if ( (int) $record['current_version'] === $v ) {
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
				/* translators: %d: version number */
				return __( 'Older file deleted.', 'static2wp' );
			}
		);
		wp_send_json_success( $outcome );
	}

	/**
	 * Shared pipeline for version-level actions (rollback / delete).
	 *
	 * @param string   $action_prefix Nonce prefix.
	 * @param callable $callback      Receives (&$record, $version, $v), returns
	 *                                message string or WP_Error.
	 * @return array
	 */
	private function handle_version_action( $action_prefix, $callback ) {
		$id = $this->verify_row_request( $action_prefix );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
		}

		$v      = isset( $_POST['v'] ) ? absint( $_POST['v'] ) : 0;
		$record = S2WP_Store::get( $id );
		$record['id'] = $id;

		$record['versions'] = S2WP_Store::normalize_versions( $record );
		$version            = null;
		foreach ( $record['versions'] as $candidate ) {
			if ( (int) $candidate['v'] === $v ) {
				$version = $candidate;
				break;
			}
		}
		if ( ! $version ) {
			wp_send_json_error( array( 'message' => __( 'Version not found.', 'static2wp' ) ), 400 );
		}

		$result = $callback( $record, $version, $v );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		S2WP_Store::save( $id, $record );

		$outcome = array(
			'success' => true,
			'message' => $result,
		);
		return $this->with_editor_html( $outcome, $record );
	}

	/**
	 * Attach a freshly rendered canvas to an AJAX response when the request
	 * came from the page editor.
	 *
	 * @param array $outcome Response array.
	 * @param array $record  Landing record.
	 * @return array
	 */
	private function with_editor_html( $outcome, $record ) {
		if ( $this->is_editor_request() && ! empty( $record['page_id'] ) ) {
			$post = get_post( (int) $record['page_id'] );
			if ( $post ) {
				$outcome['canvas_html'] = S2WP_Metabox::get_canvas_html( $post );
			}
		}
		return $outcome;
	}

	/**
	 * AJAX: delete a landing (files + record). The WordPress page is kept.
	 */
	public function ajax_delete() {
		$id = $this->verify_row_request( 's2wp_delete_' );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
		}

		$record = S2WP_Store::get( $id );

		S2WP_Store::delete( $id );

		$response = array( 'message' => __( 'File removed from the page.', 'static2wp' ) );
		if ( $this->is_editor_request() && ! empty( $record['page_id'] ) ) {
			$post = get_post( (int) $record['page_id'] );
			if ( $post ) {
				$response['canvas_html'] = S2WP_Metabox::get_canvas_html( $post );
			}
		}
		wp_send_json_success( $response );
	}

	/* ---------------------------------------------------------------------
	 * Shared pipeline + helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Shared upload pipeline for AJAX and classic POST.
	 *
	 * @param array  $request  Request data.
	 * @param string $file_key Key in $_FILES.
	 * @return array Outcome: success, id, name, view_url, page_id, log, error, canvas_html.
	 */
	private function handle_save( $request, $file_key ) {
		$name    = isset( $request['landing_name'] ) ? sanitize_text_field( wp_unslash( $request['landing_name'] ) ) : '';
		$page_id = isset( $request['page_id'] ) ? absint( $request['page_id'] ) : 0;

		$outcome = S2WP_Store::create_for_page( $page_id, $name, $file_key );

		// create_for_page resolves "new page" itself — use its page, not the request's 0.
		if ( $outcome['success'] && $this->is_editor_request() && ! empty( $outcome['page_id'] ) ) {
			$post = get_post( (int) $outcome['page_id'] );
			if ( $post ) {
				$outcome['canvas_html'] = S2WP_Metabox::get_canvas_html( $post );
			}
		}
		return $outcome;
	}

	/**
	 * Whether the current AJAX request originates from the page editor.
	 *
	 * @return bool
	 */
	private function is_editor_request() {
		return isset( $_POST['context'] ) && in_array( sanitize_key( wp_unslash( $_POST['context'] ) ), array( 'editor' ), true );
	}

	/**
	 * Verify capability + nonce + landing existence for row-level AJAX actions.
	 *
	 * @param string $action_prefix Nonce action prefix (e.g. 's2wp_toggle_').
	 * @return string|WP_Error Landing ID or error.
	 */
	private function verify_row_request( $action_prefix ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new WP_Error( 's2wp_cap', __( 'Permission denied.', 'static2wp' ) );
		}

		$id = isset( $_POST['id'] ) ? S2WP_Store::validate_id( wp_unslash( $_POST['id'] ) ) : '';
		if ( '' === $id || empty( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), $action_prefix . $id ) ) {
			return new WP_Error( 's2wp_nonce', __( 'Security check failed. Please reload the page and try again.', 'static2wp' ) );
		}

		$record = S2WP_Store::get( $id );
		if ( ! $record ) {
			return new WP_Error( 's2wp_missing', __( 'This landing page no longer exists.', 'static2wp' ) );
		}

		// Post-level capability: the actor must be allowed to edit the page
		// this landing owns, not just any page on the site.
		if ( empty( $record['page_id'] ) || ! current_user_can( 'edit_post', (int) $record['page_id'] ) ) {
			return new WP_Error( 's2wp_cap', __( 'Permission denied.', 'static2wp' ) );
		}

		return $id;
	}
}
