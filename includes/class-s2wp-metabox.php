<?php
/**
 * Page-editor integration: "HTML Landing Page" meta box + Pages list column.
 *
 * @package Static2WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class S2WP_Metabox
 */
class S2WP_Metabox {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'load-post.php', array( __CLASS__, 'maybe_quiet_editor' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'edit_form_after_title', array( __CLASS__, 'render_classic_canvas' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_block_canvas_template' ) );
		add_filter( 'manage_page_posts_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_page_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	/**
	 * Mark the page editor when a landing is assigned so CSS can occupy the
	 * classic editor slot without waiting for JavaScript.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || 'page' !== $screen->post_type ) {
			return $classes;
		}

		global $post;
		if ( $post && ! empty( $post->ID ) && S2WP_Store::find_by_page( $post->ID ) ) {
			$classes .= ' s2wp-has-landing';
		}

		return $classes;
	}

	/**
	 * TinyMCE's hidden iframe never fires "load" while #postdivrich is
	 * display:none — the browser tab spinner then runs forever. Turn off
	 * the visual editor when this page already has a landing.
	 */
	public static function maybe_quiet_editor() {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id || ! S2WP_Store::find_by_page( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( $post && function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return; // Keep the block canvas alive — our panel mounts into it.
		}

		// Keep the textarea (Save still works) but skip TinyMCE so the tab spinner cannot hang.
		add_filter( 'user_can_richedit', '__return_false' );
	}

	/**
	 * Load the shared admin CSS/JS on the page editor.
	 *
	 * @param string $hook Current admin hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'page' !== $screen->post_type ) {
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

	/**
	 * Print the canvas in the classic editor slot (after the title, where
	 * TinyMCE normally sits). CSS hides #postdivrich while a landing exists.
	 *
	 * @param WP_Post $post Current page.
	 */
	public static function render_classic_canvas( $post ) {
		if ( ! $post || empty( $post->ID ) || 'page' !== $post->post_type ) {
			return;
		}
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}

		try {
			$html = self::get_canvas_html( $post );
		} catch ( Throwable $e ) {
			return;
		}
		if ( '' !== $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally.
		}
	}

	/**
	 * Hidden template for the block editor. JS mounts it once into the
	 * Gutenberg canvas — no MutationObserver, so the tab cannot spin forever.
	 */
	public static function render_block_canvas_template() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || 'page' !== $screen->post_type ) {
			return;
		}

		global $post;
		if ( ! $post || empty( $post->ID ) ) {
			return;
		}
		if ( ! function_exists( 'use_block_editor_for_post' ) || ! use_block_editor_for_post( $post ) ) {
			return;
		}

		try {
			$html = self::get_canvas_html( $post );
		} catch ( Throwable $e ) {
			return;
		}
		if ( '' !== $html ) {
			echo '<div id="s2wp-canvas-root" hidden>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally.
		}
	}

	/**
	 * Product surface in the editor slot — Elementor-style.
	 * No file: a start button above the editor.
	 * Has file: the editor hole becomes the file workspace.
	 *
	 * @param WP_Post $post Current page.
	 * @return string
	 */
	public static function get_canvas_html( $post ) {
		if ( ! $post || empty( $post->ID ) ) {
			return '';
		}

		$landing = S2WP_Store::find_by_page( $post->ID );
		if ( ! $landing ) {
			return self::get_start_html( $post );
		}

		$id        = $landing['id'];
		$is_active = ! empty( $landing['active'] );
		$versions  = S2WP_Store::normalize_versions( $landing );
		$current_v = isset( $landing['current_version'] ) ? (int) $landing['current_version'] : 1;
		$nonce_t   = wp_create_nonce( 's2wp_toggle_' . $id );
		$nonce_v   = wp_create_nonce( 's2wp_version_' . $id );
		$nonce_d   = wp_create_nonce( 's2wp_delete_' . $id );

		ob_start();
		?>
		<div id="s2wp-canvas" class="s2wp-canvas" data-id="<?php echo esc_attr( $id ); ?>">
			<div class="s2wp-canvas-bar">
				<div class="s2wp-canvas-bar-meta">
					<span class="s2wp-dot<?php echo $is_active ? ' is-on' : ''; ?>" aria-hidden="true"></span>
					<strong><?php echo esc_html( $is_active ? __( 'Visitors see the file', 'static2wp' ) : __( 'Visitors see the normal page', 'static2wp' ) ); ?></strong>
				</div>
				<div class="s2wp-canvas-bar-actions">
					<a class="button button-small" href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'static2wp' ); ?></a>
					<button type="button" class="button button-small s2wp-toggle<?php echo $is_active ? '' : ' button-primary'; ?>" data-id="<?php echo esc_attr( $id ); ?>" data-nonce="<?php echo esc_attr( $nonce_t ); ?>">
						<?php echo $is_active ? esc_html__( 'Use normal page', 'static2wp' ) : esc_html__( 'Show file', 'static2wp' ); ?>
					</button>
					<button type="button" class="button button-small s2wp-delete" data-id="<?php echo esc_attr( $id ); ?>" data-nonce="<?php echo esc_attr( $nonce_d ); ?>">
						<?php esc_html_e( 'Remove file', 'static2wp' ); ?>
					</button>
					<a href="#" id="s2wp-show-editor" class="button button-small"><?php esc_html_e( 'Edit page text', 'static2wp' ); ?></a>
				</div>
			</div>

			<div class="s2wp-live">
				<p class="s2wp-live-title"><?php echo esc_html( $landing['name'] ); ?></p>
				<p class="s2wp-live-state">
					<?php echo $is_active ? esc_html__( 'This file is live. Visitors see it instead of the WordPress editor.', 'static2wp' ) : esc_html__( 'File is saved. Visitors still see the normal page.', 'static2wp' ); ?>
				</p>
				<p class="s2wp-drop-actions">
					<a class="button button-primary" href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View page', 'static2wp' ); ?></a>
					<button type="button" class="button" id="s2wp-open-replace"><?php esc_html_e( 'Replace file', 'static2wp' ); ?></button>
				</p>
			</div>

			<div id="s2wp-replace-wrap" hidden>
				<input type="file" id="s2wp-canvas-file" accept=".html,.htm,.zip" hidden>
				<div id="s2wp-canvas-drop" class="s2wp-canvas-stage s2wp-canvas-drop" tabindex="0" role="button" data-id="<?php echo esc_attr( $id ); ?>" data-nonce="<?php echo esc_attr( $nonce_v ); ?>" aria-label="<?php esc_attr_e( 'Drop a file here', 'static2wp' ); ?>">
					<div class="s2wp-drop-idle">
						<p class="s2wp-drop-title"><?php esc_html_e( 'Drop the new file', 'static2wp' ); ?></p>
						<p class="s2wp-drop-hint"><?php esc_html_e( 'or click to choose', 'static2wp' ); ?></p>
					</div>
					<div class="s2wp-drop-ready" hidden>
						<p class="s2wp-drop-title" id="s2wp-drop-name"></p>
						<div class="s2wp-drop-actions">
							<button type="button" class="button button-primary" id="s2wp-drop-confirm"><?php esc_html_e( 'Use this file', 'static2wp' ); ?></button>
							<button type="button" class="button" id="s2wp-drop-cancel"><?php esc_html_e( 'Cancel', 'static2wp' ); ?></button>
						</div>
					</div>
				</div>
			</div>

			<?php if ( count( $versions ) > 1 ) : ?>
				<ul class="s2wp-versions">
					<?php foreach ( array_reverse( $versions ) as $version ) : ?>
						<?php
						$is_current  = ( (int) $version['v'] === $current_v );
						$created_raw = ! empty( $version['created'] ) ? $version['created'] : '';
						$created_ts  = $created_raw ? strtotime( $created_raw ) : false;
						$created_fmt = $created_ts ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created_ts ) : '';
						?>
						<li class="<?php echo $is_current ? 's2wp-version-current' : ''; ?>">
							<span><?php echo esc_html( $created_fmt ); ?></span>
							<?php if ( $is_current ) : ?>
								<span><?php esc_html_e( 'Current', 'static2wp' ); ?></span>
							<?php else : ?>
								<button type="button" class="button-link s2wp-rollback" data-id="<?php echo esc_attr( $id ); ?>" data-v="<?php echo esc_attr( $version['v'] ); ?>" data-nonce="<?php echo esc_attr( $nonce_v ); ?>">
									<?php esc_html_e( 'Use this', 'static2wp' ); ?>
								</button>
								<button type="button" class="button-link s2wp-del-version" data-id="<?php echo esc_attr( $id ); ?>" data-v="<?php echo esc_attr( $version['v'] ); ?>" data-nonce="<?php echo esc_attr( $nonce_v ); ?>">
									<?php esc_html_e( 'Delete', 'static2wp' ); ?>
								</button>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Start state: one button, like “Edit with Elementor”. Clicking it
	 * takes over the editor hole with a confirm-to-upload drop zone.
	 *
	 * @param WP_Post $post Current page.
	 * @return string
	 */
	private static function get_start_html( $post ) {
		$saved = ( 'auto-draft' !== $post->post_status );

		ob_start();
		?>
		<div id="s2wp-canvas" class="s2wp-canvas s2wp-canvas-start">
			<?php if ( ! $saved ) : ?>
				<p class="s2wp-start-note"><?php esc_html_e( 'Save the page first, then you can show a file here.', 'static2wp' ); ?></p>
			<?php else : ?>
				<button type="button" class="button button-primary button-hero" id="s2wp-start-file">
					<?php esc_html_e( 'Show a file on this page', 'static2wp' ); ?>
				</button>
				<div id="s2wp-first-upload" hidden>
					<div id="s2wp-form">
						<input type="file" id="s2wp-file" accept=".html,.htm,.zip" hidden>
						<input type="hidden" id="s2wp-name" value="<?php echo esc_attr( $post->post_title ); ?>">
						<input type="hidden" id="s2wp-page" value="<?php echo esc_attr( $post->ID ); ?>">
						<div id="s2wp-dropzone" class="s2wp-canvas-drop" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Drop a file here', 'static2wp' ); ?>">
							<div class="s2wp-drop-idle">
								<p class="s2wp-drop-title"><?php esc_html_e( 'Drop a file here', 'static2wp' ); ?></p>
								<p class="s2wp-drop-hint"><?php esc_html_e( 'or click to choose — HTML or ZIP', 'static2wp' ); ?></p>
								<span id="s2wp-filename" class="s2wp-filename"></span>
							</div>
						</div>
						<p class="s2wp-drop-actions">
							<button type="button" id="s2wp-submit" class="button button-primary" disabled><?php esc_html_e( 'Show this file', 'static2wp' ); ?></button>
							<button type="button" id="s2wp-cancel-start" class="button"><?php esc_html_e( 'Cancel', 'static2wp' ); ?></button>
						</p>
						<div id="s2wp-log" hidden></div>
						<div id="s2wp-result" hidden></div>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Add a "Landing" column to the Pages list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns['s2wp_landing'] = __( 'File', 'static2wp' );
		return $columns;
	}

	/**
	 * Render the "Landing" column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Page ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 's2wp_landing' !== $column ) {
			return;
		}

		$landing = S2WP_Store::find_by_page( $post_id );
		if ( ! $landing ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		$is_active = ! empty( $landing['active'] );
		$edit_url  = get_edit_post_link( $post_id );
		if ( $is_active ) {
			echo esc_html__( 'On', 'static2wp' );
		} else {
			echo esc_html__( 'Off', 'static2wp' );
		}
		if ( $edit_url ) {
			echo ' <a href="' . esc_url( $edit_url ) . '">' . esc_html( $landing['name'] ) . '</a>';
		}
	}
}
