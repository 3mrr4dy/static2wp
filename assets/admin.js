/**
 * Static2WP — admin UI.
 *
 * Works in two contexts:
 *  - Pages → Page file screen (full form, landings table)
 *  - Page editor canvas (classic + block editor)
 *
 * All feedback uses native WordPress admin notices instead of browser alerts.
 */
(function ($) {
	'use strict';

	/* ---------------------------------------------------------------
	 * Native WP notices
	 * ------------------------------------------------------------- */

	/**
	 * Show a WordPress admin notice (success / error / warning).
	 *
	 * @param {string}  message Message text.
	 * @param {string}  type    Notice type: success|error|warning|info.
	 * @param {boolean} sticky  Keep it until dismissed (errors always stick).
	 * @return {jQuery} The notice element.
	 */
	function showNotice(message, type, sticky) {
		if (!message) {
			return $();
		}
		type = type || 'success';

		var $notice = $(
			'<div class="notice notice-' + type + ' is-dismissible s2wp-notice">' +
			'<p></p>' +
			'<button type="button" class="notice-dismiss"><span class="screen-reader-text"></span></button>' +
			'</div>'
		);
		$notice.find('p').text(message);
		$notice.find('.screen-reader-text').text(S2WP.strings.dismissNotice);

		if ($('#s2wp-canvas').length) {
			$('#s2wp-canvas').prepend($notice);
		} else {
			var $anchor = $('.wp-header-end').first();
			if (!$anchor.length) {
				$anchor = $('.wrap h1').first();
			}
			if ($anchor.length) {
				$notice.insertAfter($anchor);
			} else {
				$('#wpbody-content').prepend($notice);
			}
		}

		$notice.on('click', '.notice-dismiss', function () {
			$notice.fadeOut(150, function () {
				$(this).remove();
			});
		});

		if (type !== 'error' && !sticky) {
			window.setTimeout(function () {
				$notice.fadeOut(250, function () {
					$(this).remove();
				});
			}, 5000);
		}

		return $notice;
	}

	/**
	 * Extract a human message from a failed jqXHR (handles JSON error bodies
	 * sent with HTTP 4xx status codes, where jQuery jumps to .fail()).
	 *
	 * @param {jqXHR}  xhr      The xhr object.
	 * @param {string} fallback Fallback text.
	 * @return {string}
	 */
	function xhrMessage(xhr, fallback) {
		if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
			return xhr.responseJSON.data.message || xhr.responseJSON.data.error || fallback;
		}
		if (xhr && xhr.responseText) {
			try {
				var parsed = JSON.parse(xhr.responseText);
				if (parsed && parsed.data) {
					return parsed.data.message || parsed.data.error || fallback;
				}
			} catch (e) {
				/* Not JSON (e.g. fatal error output) — use fallback. */
			}
		}
		return fallback;
	}

	/**
	 * Show the best available error message from an AJAX response.
	 *
	 * @param {Object|null} resp     Parsed response (when .done ran).
	 * @param {jqXHR|null}  xhr      The xhr object (when .fail ran).
	 * @param {string}      fallback Fallback text.
	 */
	function errorNotice(resp, xhr, fallback) {
		var msg = (resp && resp.data && (resp.data.message || resp.data.error)) || xhrMessage(xhr, fallback || S2WP.strings.error);
		showNotice(msg, 'error');
	}

	/* ---------------------------------------------------------------
	 * Upload UI (admin screen form + editor canvas uploader)
	 * ------------------------------------------------------------- */

	function initUploadUI() {
		var $form = $('#s2wp-form');
		if (!$form.length) {
			return;
		}

		var $dropzone = $('#s2wp-dropzone');
		var $fileInput = $('#s2wp-file');
		var $filename = $('#s2wp-filename');
		var $submit = $('#s2wp-submit');
		var $name = $('#s2wp-name');

		$dropzone.on('click keydown', function (e) {
			if (e.type === 'click' || e.which === 13 || e.which === 32) {
				e.preventDefault();
				$fileInput.trigger('click');
			}
		});

		$fileInput.on('change', function () {
			if (this.files && this.files.length) {
				acceptFile(this.files[0]);
			}
		});

		['dragenter', 'dragover'].forEach(function (evt) {
			$dropzone.on(evt, function (e) {
				e.preventDefault();
				e.stopPropagation();
				$dropzone.addClass('s2wp-dragover');
			});
		});

		['dragleave', 'drop'].forEach(function (evt) {
			$dropzone.on(evt, function (e) {
				e.preventDefault();
				e.stopPropagation();
				$dropzone.removeClass('s2wp-dragover');
			});
		});

		$dropzone.on('drop', function (e) {
			var dt = e.originalEvent.dataTransfer;
			if (dt && dt.files && dt.files.length) {
				$fileInput[0].files = dt.files;
				acceptFile(dt.files[0]);
			}
		});

		/**
		 * Validate the chosen file and reflect it in the UI.
		 *
		 * @param {File} file File object.
		 */
		function acceptFile(file) {
			clearLog();

			if (!/\.(html?|zip)$/i.test(file.name)) {
				logLine(S2WP.strings.badType, 'error');
				$submit.prop('disabled', true);
				return;
			}
			if (file.size > S2WP.maxSize) {
				logLine(S2WP.strings.tooBig, 'error');
				$submit.prop('disabled', true);
				return;
			}

			$filename.text(file.name + ' (' + formatSize(file.size) + ')');
			$dropzone.addClass('s2wp-has-file');
			$submit.prop('disabled', false);

			// Pre-fill the landing name from the file name if empty.
			if (!$name.val()) {
				$name.val(file.name.replace(/\.(html?|zip)$/i, '').replace(/[-_]+/g, ' ').trim());
			}
		}

		if ($form.is('form')) {
			$form.on('submit', function (e) {
				e.preventDefault();
				doUpload();
			});
		} else {
			$submit.on('click', function (e) {
				e.preventDefault();
				doUpload();
			});
		}
	}

	/* ---------------------------------------------------------------
	 * AJAX publish
	 * ------------------------------------------------------------- */

	function doUpload() {
		var $form = $('#s2wp-form');
		var $fileInput = $('#s2wp-file');
		var $submit = $('#s2wp-submit');
		var $result = $('#s2wp-result');

		if (!$fileInput[0].files.length) {
			showNotice(S2WP.strings.noFile, 'warning');
			return;
		}
		var pageId = $('#s2wp-page').length ? $('#s2wp-page').val() : '0';

		var data = new FormData();
		data.append('action', 's2wp_save');
		data.append('nonce', S2WP.nonce);
		data.append('landing_file', $fileInput[0].files[0]);
		data.append('landing_name', $('#s2wp-name').val() || '');
		data.append('page_id', pageId || '0');
		var ctx = editorContext();
		if (ctx) {
			data.append('context', ctx);
		}

		$submit.prop('disabled', true);
		$form.toggleClass('s2wp-busy', true);
		clearLog();
		logLine(S2WP.strings.uploading, 'info');
		$result.prop('hidden', true).empty();

		$.ajax({
			url: S2WP.ajaxUrl,
			type: 'POST',
			data: data,
			processData: false,
			contentType: false
		})
			.done(function (resp) {
				if (resp && resp.success) {
					if (editorContext()) {
						window.location.reload();
						return;
					}
					renderSuccess(resp.data);
				} else {
					errorNotice(resp, null);
				}
			})
			.fail(function (xhr) {
				errorNotice(null, xhr);
			})
			.always(function () {
				$submit.prop('disabled', false);
				$form.toggleClass('s2wp-busy', false);
			});
	}

	/**
	 * Render success panel with view link (admin screen only).
	 *
	 * @param {Object} data Response data.
	 */
	function renderSuccess(data) {
		var viewUrl = data.view_url ? encodeURI(data.view_url) : '';
		var html =
			'<div class="s2wp-success">' +
			'<span class="dashicons dashicons-yes-alt"></span>' +
			'<div>' +
			'<strong>' + escapeHtml(data.name) + '</strong> ' +
			'<span class="s2wp-badge s2wp-badge-active">' + escapeHtml(S2WP.strings.activeBadge) + '</span>' +
			'<div class="s2wp-success-actions">' +
			'<a class="button button-primary" href="' + viewUrl + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(S2WP.strings.viewPage) + '</a>' +
			'</div>' +
			'<p class="s2wp-success-note">' + escapeHtml(S2WP.strings.reloadNote) + '</p>' +
			'</div>' +
			'</div>';
		$('#s2wp-result').html(html).prop('hidden', false);
	}

	/* ---------------------------------------------------------------
	 * Editor canvas: occupy the classic / block editor slot.
	 * Classic: PHP already printed #s2wp-canvas after the title.
	 * Gutenberg: the same markup lives in a hidden #s2wp-canvas-root
	 * footer template — a canvas still inside that root is NOT mounted.
	 * Lift it into the skeleton. No MutationObserver.
	 * ------------------------------------------------------------- */

	var canvasHtml = '';

	function gutenbergMountTarget() {
		var selectors = [
			'.interface-interface-skeleton__content',
			'.edit-post-layout__content',
			'.edit-post-visual-editor',
			'.editor-visual-editor'
		];
		for (var i = 0; i < selectors.length; i++) {
			var $target = $(selectors[i]).first();
			if ($target.length) {
				return $target;
			}
		}
		return $();
	}

	function isInsideRoot($el) {
		return $el.length > 0 && $el.closest('#s2wp-canvas-root').length > 0;
	}

	function canvasNode() {
		return $('#s2wp-canvas').filter(function () {
			return !isInsideRoot($(this));
		});
	}

	function isCanvasMounted($canvas) {
		if (!$canvas.length || isInsideRoot($canvas)) {
			return false;
		}
		if ($canvas.closest('.interface-interface-skeleton__content, .edit-post-layout__content').length) {
			return true;
		}
		if ($('#postdivrich').length) {
			return true;
		}
		return false;
	}

	function syncEditorToggle() {
		var $link = $('#s2wp-show-editor');
		if (!$link.length) {
			return;
		}
		$link.text($('body').hasClass('s2wp-editor-revealed') ? S2WP.strings.hideEditor : S2WP.strings.showEditor);
	}

	function markLandingState() {
		var $canvas = canvasNode();
		if (!$canvas.length || $canvas.hasClass('s2wp-canvas-start')) {
			return;
		}
		if (!isCanvasMounted($canvas)) {
			return;
		}
		$('body').removeClass('s2wp-picking-file').addClass('s2wp-has-landing s2wp-canvas-mounted');
		syncEditorToggle();
	}

	function insertCanvas(html) {
		if (!html) {
			return false;
		}

		var $existing = canvasNode();
		if ($existing.length) {
			$existing.replaceWith(html);
			markLandingState();
			return true;
		}

		var $classic = $('#postdivrich').first();
		if ($classic.length) {
			$classic.before(html);
			markLandingState();
			return true;
		}

		var $gutenberg = gutenbergMountTarget();
		if ($gutenberg.length) {
			$gutenberg.prepend(html);
			markLandingState();
			return true;
		}

		return false;
	}

	function quietHiddenEditor() {
		if (window.tinymce && tinymce.remove) {
			try {
				tinymce.remove();
			} catch (e) {
				/* ignore */
			}
		}
		$('#postdivrich iframe').each(function () {
			this.src = 'about:blank';
		});
	}

	function mountBlockCanvas() {
		var $canvas = $('#s2wp-canvas');

		// Classic (or a previous successful mount): already in the editor slot.
		if ($canvas.length && !isInsideRoot($canvas)) {
			markLandingState();
			return true;
		}

		var $root = $('#s2wp-canvas-root');
		if ($root.length) {
			canvasHtml = $root.html();
			$root.remove();
		}
		if (!canvasHtml) {
			return true;
		}

		return insertCanvas(canvasHtml);
	}

	$(function () {
		try {
			/*
			 * Only silence the native editor when a file is actually live
			 * (PHP already flagged the body). Otherwise TinyMCE would die on
			 * every page, file or no file.
			 */
			if (document.body.className.indexOf('s2wp-has-landing') !== -1) {
				quietHiddenEditor();
			}

			if (mountBlockCanvas()) {
				return;
			}

			var attempts = 0;
			var timer = window.setInterval(function () {
				attempts++;
				if (mountBlockCanvas() || attempts > 20) {
					window.clearInterval(timer);
				}
			}, 250);
		} catch (e) {
			/* Never block the rest of the editor (TinyMCE / Elementor). */
		}
	});

	$(document).on('click', '#s2wp-start-file', function (e) {
		e.preventDefault();
		$(this).hide();
		$('#s2wp-first-upload').prop('hidden', false);
		$('body').addClass('s2wp-picking-file');
	});

	$(document).on('click', '#s2wp-cancel-start', function (e) {
		e.preventDefault();
		$('#s2wp-first-upload').prop('hidden', true);
		$('#s2wp-start-file').show();
		$('body').removeClass('s2wp-picking-file');
	});

	$(document).on('click', '#s2wp-open-replace', function (e) {
		e.preventDefault();
		$('#s2wp-replace-wrap').prop('hidden', false);
		$(this).hide();
	});

	$(document).on('click', '#s2wp-show-editor', function (e) {
		e.preventDefault();
		$('body').toggleClass('s2wp-editor-revealed');
		syncEditorToggle();
	});

	/**
	 * Shared post-processing of editor-origin AJAX responses.
	 *
	 * @param {Object} data resp.data payload.
	 */
	function applyEditorHtml(data) {
		if (!data) {
			return;
		}
		if (data.canvas_html) {
			insertCanvas(data.canvas_html);
		}
	}

	function editorContext() {
		return $('#s2wp-canvas').length || $('#s2wp-canvas-root').length ? 'editor' : '';
	}

	/* ---------------------------------------------------------------
	 * Row actions: toggle, new version, rollback, delete version, delete
	 * ------------------------------------------------------------- */

	$(document).on('click', '.s2wp-toggle', function () {
		var $btn = $(this);
		$btn.prop('disabled', true);

		var data = {
			action: 's2wp_toggle',
			id: $btn.data('id'),
			nonce: $btn.data('nonce')
		};
		var ctx = editorContext();
		if (ctx) {
			data.context = ctx;
		}

		$.post(S2WP.ajaxUrl, data)
			.done(function (resp) {
				if (resp && resp.success) {
					if (ctx) {
						applyEditorHtml(resp.data);
						showNotice(resp.data.message, 'success');
					} else {
						window.location.reload();
					}
				} else {
					errorNotice(resp, null);
					$btn.prop('disabled', false);
				}
			})
			.fail(function (xhr) {
				errorNotice(null, xhr);
				$btn.prop('disabled', false);
			});
	});

	function uploadNewVersion(file, id, nonce, $busy) {
		if (!file || !id || !nonce) {
			return;
		}
		if (!/\.(html?|zip)$/i.test(file.name)) {
			showNotice(S2WP.strings.badType, 'error');
			return;
		}
		if (file.size > S2WP.maxSize) {
			showNotice(S2WP.strings.tooBig, 'error');
			return;
		}

		var data = new FormData();
		data.append('action', 's2wp_new_version');
		data.append('id', id);
		data.append('nonce', nonce);
		data.append('landing_file', file);
		var ctx = editorContext();
		if (ctx) {
			data.append('context', ctx);
		}

		if ($busy) {
			$busy.addClass('s2wp-busy').prop('disabled', true);
		}

		$.ajax({
			url: S2WP.ajaxUrl,
			type: 'POST',
			data: data,
			processData: false,
			contentType: false
		})
			.done(function (resp) {
				if (resp && resp.success) {
					if (ctx) {
						applyEditorHtml(resp.data);
					} else {
						window.location.reload();
					}
					showNotice(resp.data.message, 'success');
				} else {
					errorNotice(resp, null);
				}
			})
			.fail(function (xhr) {
				errorNotice(null, xhr);
			})
			.always(function () {
				if ($busy) {
					$busy.removeClass('s2wp-busy').prop('disabled', false);
				}
			});
	}

	var pendingCanvasFile = null;

	function stageCanvasFile(file) {
		if (!file) {
			return;
		}
		if (!/\.(html?|zip)$/i.test(file.name)) {
			showNotice(S2WP.strings.badType, 'error');
			return;
		}
		if (file.size > S2WP.maxSize) {
			showNotice(S2WP.strings.tooBig, 'error');
			return;
		}
		pendingCanvasFile = file;
		$('#s2wp-drop-name').text(file.name + ' (' + formatSize(file.size) + ')');
		$('#s2wp-canvas-drop').addClass('s2wp-ready');
		$('.s2wp-drop-idle').prop('hidden', true);
		$('.s2wp-drop-ready').prop('hidden', false);
	}

	function clearCanvasFile() {
		pendingCanvasFile = null;
		$('#s2wp-canvas-file').val('');
		$('#s2wp-canvas-drop').removeClass('s2wp-ready');
		$('.s2wp-drop-idle').prop('hidden', false);
		$('.s2wp-drop-ready').prop('hidden', true);
	}

	$(document).on('click keydown', '#s2wp-canvas-drop', function (e) {
		if ($(e.target).closest('#s2wp-drop-confirm, #s2wp-drop-cancel').length) {
			return;
		}
		if ($('#s2wp-canvas-drop').hasClass('s2wp-ready')) {
			return;
		}
		if (e.type === 'keydown' && e.which !== 13 && e.which !== 32) {
			return;
		}
		e.preventDefault();
		$('#s2wp-canvas-file').trigger('click');
	});

	$(document).on('change', '#s2wp-canvas-file', function () {
		if (this.files && this.files.length) {
			stageCanvasFile(this.files[0]);
		}
	});

	$(document).on('click', '#s2wp-drop-confirm', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var $drop = $('#s2wp-canvas-drop');
		if (!pendingCanvasFile) {
			return;
		}
		uploadNewVersion(pendingCanvasFile, $drop.data('id'), $drop.data('nonce'), $drop);
	});

	$(document).on('click', '#s2wp-drop-cancel', function (e) {
		e.preventDefault();
		e.stopPropagation();
		clearCanvasFile();
	});

	$(document).on('dragenter dragover', '#s2wp-canvas-drop', function (e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).addClass('s2wp-dragover');
	});

	$(document).on('dragleave drop', '#s2wp-canvas-drop', function (e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).removeClass('s2wp-dragover');
	});

	$(document).on('drop', '#s2wp-canvas-drop', function (e) {
		var dt = e.originalEvent.dataTransfer;
		if (dt && dt.files && dt.files.length) {
			stageCanvasFile(dt.files[0]);
		}
	});

	$(document).on('click', '.s2wp-rollback, .s2wp-del-version', function () {
		var $btn = $(this);
		var isDelete = $btn.hasClass('s2wp-del-version');
		var v = $btn.data('v');

		if (isDelete && !window.confirm(S2WP.strings.confirmVersion)) {
			return;
		}

		$btn.prop('disabled', true);

		var data = {
			action: isDelete ? 's2wp_delete_version' : 's2wp_rollback',
			id: $btn.data('id'),
			v: v,
			nonce: $btn.data('nonce')
		};
		var ctx = editorContext();
		if (ctx) {
			data.context = ctx;
		}

		$.post(S2WP.ajaxUrl, data)
			.done(function (resp) {
				if (resp && resp.success) {
					if (ctx) {
						applyEditorHtml(resp.data);
						showNotice(resp.data.message, 'success');
					} else {
						window.location.reload();
					}
				} else {
					errorNotice(resp, null);
					$btn.prop('disabled', false);
				}
			})
			.fail(function (xhr) {
				errorNotice(null, xhr);
				$btn.prop('disabled', false);
			});
	});

	$(document).on('click', '.s2wp-delete', function () {
		var $btn = $(this);

		if (!window.confirm(S2WP.strings.confirmDelete)) {
			return;
		}

		$btn.prop('disabled', true);

		var data = {
			action: 's2wp_delete',
			id: $btn.data('id'),
			nonce: $btn.data('nonce')
		};
		var ctx = editorContext();
		if (ctx) {
			data.context = ctx;
		}

		$.post(S2WP.ajaxUrl, data)
			.done(function (resp) {
				if (resp && resp.success) {
					if (ctx) {
						window.location.reload();
						return;
					} else {
						var $row = $('tr[data-id="' + $btn.data('id') + '"]');
						$row.fadeOut(300, function () {
							$(this).remove();
						});
						showNotice(resp.data.message, 'success');
					}
				} else {
					errorNotice(resp, null);
					$btn.prop('disabled', false);
				}
			})
			.fail(function (xhr) {
				errorNotice(null, xhr);
				$btn.prop('disabled', false);
			});
	});

	/* ---------------------------------------------------------------
	 * Live filter on the landings table
	 * ------------------------------------------------------------- */

	$(document).on('input', '#s2wp-search', function () {
		var q = this.value.toLowerCase();
		var visible = 0;

		$('.s2wp-table tbody tr').each(function () {
			var match = $(this).text().toLowerCase().indexOf(q) !== -1;
			$(this).toggle(match);
			if (match) {
				visible++;
			}
		});

		$('.s2wp-no-results').prop('hidden', visible > 0);
	});

	/* ---------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------- */

	function logLine(text, kind) {
		var $log = $('#s2wp-log');
		if (!$log.length) {
			return;
		}
		$log.find('.s2wp-log-empty').remove();
		var cls = kind ? ' s2wp-log-' + kind : '';
		$('<div class="s2wp-log-line' + cls + '"></div>').text(text).appendTo($log);
		$log.scrollTop($log[0].scrollHeight);
	}

	function clearLog() {
		$('#s2wp-log').empty();
	}

	function formatSize(bytes) {
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
		return (bytes / 1048576).toFixed(1) + ' MB';
	}

	function escapeHtml(text) {
		return $('<div>').text(text).html();
	}

	$(initUploadUI);
})(jQuery);
