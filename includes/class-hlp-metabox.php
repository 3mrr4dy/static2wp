<?php
/**
 * Page-editor integration: "HTML Landing Page" meta box + Pages list column.
 *
 * @package HTML_Landing_Pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HLP_Metabox
 */
class HLP_Metabox {

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
		if ( $post && ! empty( $post->ID ) && HLP_Store::find_by_page( $post->ID ) ) {
			$classes .= ' hlp-has-landing';
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
		if ( ! $post_id || ! HLP_Store::find_by_page( $post_id ) ) {
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

		wp_enqueue_style( 'hlp-admin', HLP_PLUGIN_URL . 'assets/admin.css', array(), HLP_VERSION );
		wp_enqueue_script( 'hlp-admin', HLP_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), HLP_VERSION, true );
		wp_localize_script(
			'hlp-admin',
			'HLP',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'hlp_save' ),
				'maxSize' => HLP_Store::MAX_UPLOAD,
				'strings' => array(
					'noFile'         => __( 'Choose a file first.', 'html-landing-pages' ),
					'badType'        => __( 'Only HTML or ZIP files are allowed.', 'html-landing-pages' ),
					'tooBig'         => __( 'File is larger than 100 MB.', 'html-landing-pages' ),
					'uploading'      => __( 'Uploading…', 'html-landing-pages' ),
					'error'          => __( 'Something went wrong. Try again.', 'html-landing-pages' ),
					'confirmVersion' => __( 'Delete this older file?', 'html-landing-pages' ),
					'confirmDelete'  => __( 'Remove the file from this page? The page itself stays.', 'html-landing-pages' ),
					'showEditor'     => __( 'Edit page text', 'html-landing-pages' ),
					'hideEditor'     => __( 'Hide page text', 'html-landing-pages' ),
					'activeBadge'    => __( 'Active', 'html-landing-pages' ),
					'viewPage'       => __( 'View page', 'html-landing-pages' ),
					'reloadNote'     => __( 'Reload this page to manage your landings.', 'html-landing-pages' ),
					'dismissNotice'  => __( 'Dismiss this notice.', 'html-landing-pages' ),
				),
			)
		);
		wp_set_script_translations( 'hlp-admin', 'html-landing-pages' );
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
			echo '<div id="hlp-canvas-root" hidden>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally.
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

		$landing = HLP_Store::find_by_page( $post->ID );
		if ( ! $landing ) {
			return self::get_start_html( $post );
		}

		$id        = $landing['id'];
		$is_active = ! empty( $landing['active'] );
		$versions  = HLP_Store::normalize_versions( $landing );
		$current_v = isset( $landing['current_version'] ) ? (int) $landing['current_version'] : 1;
		$nonce_t   = wp_create_nonce( 'hlp_toggle_' . $id );
		$nonce_v   = wp_create_nonce( 'hlp_version_' . $id );
		$nonce_d   = wp_create_nonce( 'hlp_delete_' . $id );

		ob_start();
		?>
		<div id="hlp-canvas" class="hlp-canvas" data-id="<?php echo esc_attr( $id ); ?>">
			<div class="hlp-canvas-bar">
				<div class="hlp-canvas-bar-meta">
					<span class="hlp-dot<?php echo $is_active ? ' is-on' : ''; ?>" aria-hidden="true"></span>
					<strong><?php echo esc_html( $is_active ? __( 'Visitors see the file', 'html-landing-pages' ) : __( 'Visitors see the normal page', 'html-landing-pages' ) ); ?></strong>
				</div>
				<div class="hlp-canvas-bar-actions">
					<a class="button button-small" href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'html-landing-pages' ); ?></a>
					<button type="button" class="button button-small hlp-toggle<?php echo $is_active ? '' : ' button-primary'; ?>" data-id="<?php echo esc_attr( $id ); ?>" data-nonce="<?php echo esc_attr( $nonce_t ); ?>">
						<?php echo $is_active ? esc_html__( 'Use normal page', 'html-landing-pages' ) : esc_html__( 'Show file', 'html-landing-pages' ); ?>
					</button>
					<button type="button" class="button button-small hlp-delete" data-id="<?php echo esc_attr( $id ); ?>" data-nonce="<?php echo esc_attr( $nonce_d ); ?>">
						<?php esc_html_e( 'Remove file', 'html-landing-pages' ); ?>
					</button>
					<a href="#" id="hlp-show-editor" class="button button-small"><?php esc_html_e( 'Edit page text', 'html-landing-pages' ); ?></a>
				</div>
			</div>

			<div class="hlp-live">
				<p class="hlp-live-title"><?php echo esc_html( $landing['name'] ); ?></p>
				<p class="hlp-live-state">
					<?php echo $is_active ? esc_html__( 'This file is live. Visitors see it instead of the WordPress editor.', 'html-landing-pages' ) : esc_html__( 'File is saved. Visitors still see the normal page.', 'html-landing-pages' ); ?>
				</p>
				<p class="hlp-drop-actions">
					<a class="button button-primary" href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View page', 'html-landing-pages' ); ?></a>
					<button type="button" class="button" id="hlp-open-replace"><?php esc_html_e( 'Replace file', 'html-landing-pages' ); ?></button>
				</p>
			</div>

			<div id="hlp-replace-wrap" hidden>
				<input type="file" id="hlp-canvas-file" accept=".html,.htm,.zip" hidden>
				<div id="hlp-canvas-drop" class="hlp-canvas-stage hlp-canvas-drop" tabindex="0" role="button" data-id="<?php echo esc_attr( $id ); ?>" data-nonce="<?php echo esc_attr( $nonce_v ); ?>" aria-label="<?php esc_attr_e( 'Drop a file here', 'html-landing-pages' ); ?>">
					<div class="hlp-drop-idle">
						<p class="hlp-drop-title"><?php esc_html_e( 'Drop the new file', 'html-landing-pages' ); ?></p>
						<p class="hlp-drop-hint"><?php esc_html_e( 'or click to choose', 'html-landing-pages' ); ?></p>
					</div>
					<div class="hlp-drop-ready" hidden>
						<p class="hlp-drop-title" id="hlp-drop-name"></p>
						<div class="hlp-drop-actions">
							<button type="button" class="button button-primary" id="hlp-drop-confirm"><?php esc_html_e( 'Use this file', 'html-landing-pages' ); ?></button>
							<button type="button" class="button" id="hlp-drop-cancel"><?php esc_html_e( 'Cancel', 'html-landing-pages' ); ?></button>
						</div>
					</div>
				</div>
			</div>

			<?php if ( count( $versions ) > 1 ) : ?>
				<ul class="hlp-versions">
					<?php foreach ( array_reverse( $versions ) as $version ) : ?>
						<?php
						$is_current  = ( (int) $version['v'] === $current_v );
						$created_raw = ! empty( $version['created'] ) ? $version['created'] : '';
						$created_ts  = $created_raw ? strtotime( $created_raw ) : false;
						$created_fmt = $created_ts ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created_ts ) : '';
						?>
						<li class="<?php echo $is_current ? 'hlp-version-current' : ''; ?>">
							<span><?php echo esc_html( $created_fmt ); ?></span>
							<?php if ( $is_current ) : ?>
								<span><?php esc_html_e( 'Current', 'html-landing-pages' ); ?></span>
							<?php else : ?>
								<button type="button" class="button-link hlp-rollback" data-id="<?php echo esc_attr( $id ); ?>" data-v="<?php echo esc_attr( $version['v'] ); ?>" data-nonce="<?php echo esc_attr( $nonce_v ); ?>">
									<?php esc_html_e( 'Use this', 'html-landing-pages' ); ?>
								</button>
								<button type="button" class="button-link hlp-del-version" data-id="<?php echo esc_attr( $id ); ?>" data-v="<?php echo esc_attr( $version['v'] ); ?>" data-nonce="<?php echo esc_attr( $nonce_v ); ?>">
									<?php esc_html_e( 'Delete', 'html-landing-pages' ); ?>
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
		<div id="hlp-canvas" class="hlp-canvas hlp-canvas-start">
			<?php if ( ! $saved ) : ?>
				<p class="hlp-start-note"><?php esc_html_e( 'Save the page first, then you can show a file here.', 'html-landing-pages' ); ?></p>
			<?php else : ?>
				<button type="button" class="button button-primary button-hero" id="hlp-start-file">
					<?php esc_html_e( 'Show a file on this page', 'html-landing-pages' ); ?>
				</button>
				<div id="hlp-first-upload" hidden>
					<div id="hlp-form">
						<input type="file" id="hlp-file" accept=".html,.htm,.zip" hidden>
						<input type="hidden" id="hlp-name" value="<?php echo esc_attr( $post->post_title ); ?>">
						<input type="hidden" id="hlp-page" value="<?php echo esc_attr( $post->ID ); ?>">
						<div id="hlp-dropzone" class="hlp-canvas-drop" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Drop a file here', 'html-landing-pages' ); ?>">
							<div class="hlp-drop-idle">
								<p class="hlp-drop-title"><?php esc_html_e( 'Drop a file here', 'html-landing-pages' ); ?></p>
								<p class="hlp-drop-hint"><?php esc_html_e( 'or click to choose — HTML or ZIP', 'html-landing-pages' ); ?></p>
								<span id="hlp-filename" class="hlp-filename"></span>
							</div>
						</div>
						<p class="hlp-drop-actions">
							<button type="button" id="hlp-submit" class="button button-primary" disabled><?php esc_html_e( 'Show this file', 'html-landing-pages' ); ?></button>
							<button type="button" id="hlp-cancel-start" class="button"><?php esc_html_e( 'Cancel', 'html-landing-pages' ); ?></button>
						</p>
						<div id="hlp-log" hidden></div>
						<div id="hlp-result" hidden></div>
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
		$columns['hlp_landing'] = __( 'File', 'html-landing-pages' );
		return $columns;
	}

	/**
	 * Render the "Landing" column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Page ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'hlp_landing' !== $column ) {
			return;
		}

		$landing = HLP_Store::find_by_page( $post_id );
		if ( ! $landing ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		$is_active = ! empty( $landing['active'] );
		$edit_url  = get_edit_post_link( $post_id );
		if ( $is_active ) {
			echo esc_html__( 'On', 'html-landing-pages' );
		} else {
			echo esc_html__( 'Off', 'html-landing-pages' );
		}
		if ( $edit_url ) {
			echo ' <a href="' . esc_url( $edit_url ) . '">' . esc_html( $landing['name'] ) . '</a>';
		}
	}
}
