/**
 * HTML Landing Pages — admin UI.
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
			'<div class="notice notice-' + type + ' is-dismissible hlp-notice">' +
			'<p></p>' +
			'<button type="button" class="notice-dismiss"><span class="screen-reader-text"></span></button>' +
			'</div>'
		);
		$notice.find('p').text(message);
		$notice.find('.screen-reader-text').text(HLP.strings.dismissNotice);

		if ($('#hlp-canvas').length) {
			$('#hlp-canvas').prepend($notice);
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
		var msg = (resp && resp.data && (resp.data.message || resp.data.error)) || xhrMessage(xhr, fallback || HLP.strings.error);
		showNotice(msg, 'error');
	}

	/* ---------------------------------------------------------------
	 * Upload UI (admin screen form + editor canvas uploader)
	 * ------------------------------------------------------------- */

	function initUploadUI() {
		var $form = $('#hlp-form');
		if (!$form.length) {
			return;
		}

		var $dropzone = $('#hlp-dropzone');
		var $fileInput = $('#hlp-file');
		var $filename = $('#hlp-filename');
		var $submit = $('#hlp-submit');
		var $name = $('#hlp-name');

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
				$dropzone.addClass('hlp-dragover');
			});
		});

		['dragleave', 'drop'].forEach(function (evt) {
			$dropzone.on(evt, function (e) {
				e.preventDefault();
				e.stopPropagation();
				$dropzone.removeClass('hlp-dragover');
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
				logLine(HLP.strings.badType, 'error');
				$submit.prop('disabled', true);
				return;
			}
			if (file.size > HLP.maxSize) {
				logLine(HLP.strings.tooBig, 'error');
				$submit.prop('disabled', true);
				return;
			}

			$filename.text(file.name + ' (' + formatSize(file.size) + ')');
			$dropzone.addClass('hlp-has-file');
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
		var $form = $('#hlp-form');
		var $fileInput = $('#hlp-file');
		var $submit = $('#hlp-submit');
		var $result = $('#hlp-result');

		if (!$fileInput[0].files.length) {
			showNotice(HLP.strings.noFile, 'warning');
			return;
		}
		var pageId = $('#hlp-page').length ? $('#hlp-page').val() : '0';

		var data = new FormData();
		data.append('action', 'hlp_save');
		data.append('nonce', HLP.nonce);
		data.append('landing_file', $fileInput[0].files[0]);
		data.append('landing_name', $('#hlp-name').val() || '');
		data.append('page_id', pageId || '0');
		var ctx = editorContext();
		if (ctx) {
			data.append('context', ctx);
		}

		$submit.prop('disabled', true);
		$form.toggleClass('hlp-busy', true);
		clearLog();
		logLine(HLP.strings.uploading, 'info');
		$result.prop('hidden', true).empty();

		$.ajax({
			url: HLP.ajaxUrl,
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
				$form.toggleClass('hlp-busy', false);
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
			'<div class="hlp-success">' +
			'<span class="dashicons dashicons-yes-alt"></span>' +
			'<div>' +
			'<strong>' + escapeHtml(data.name) + '</strong> ' +
			'<span class="hlp-badge hlp-badge-active">' + escapeHtml(HLP.strings.activeBadge) + '</span>' +
			'<div class="hlp-success-actions">' +
			'<a class="button button-primary" href="' + viewUrl + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(HLP.strings.viewPage) + '</a>' +
			'</div>' +
			'<p class="hlp-success-note">' + escapeHtml(HLP.strings.reloadNote) + '</p>' +
			'</div>' +
			'</div>';
		$('#hlp-result').html(html).prop('hidden', false);
	}

	/* ---------------------------------------------------------------
	 * Editor canvas: occupy the classic / block editor slot.
	 * Classic: PHP already printed #hlp-canvas after the title.
	 * Gutenberg: the same markup lives in a hidden #hlp-canvas-root
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
		return $el.length > 0 && $el.closest('#hlp-canvas-root').length > 0;
	}

	function canvasNode() {
		return $('#hlp-canvas').filter(function () {
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
		var $link = $('#hlp-show-editor');
		if (!$link.length) {
			return;
		}
		$link.text($('body').hasClass('hlp-editor-revealed') ? HLP.strings.hideEditor : HLP.strings.showEditor);
	}

	function markLandingState() {
		var $canvas = canvasNode();
		if (!$canvas.length || $canvas.hasClass('hlp-canvas-start')) {
			return;
		}
		if (!isCanvasMounted($canvas)) {
			return;
		}
		$('body').removeClass('hlp-picking-file').addClass('hlp-has-landing hlp-canvas-mounted');
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
		var $canvas = $('#hlp-canvas');

		// Classic (or a previous successful mount): already in the editor slot.
		if ($canvas.length && !isInsideRoot($canvas)) {
			markLandingState();
			return true;
		}

		var $root = $('#hlp-canvas-root');
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
			if (document.body.className.indexOf('hlp-has-landing') !== -1) {
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

	$(document).on('click', '#hlp-start-file', function (e) {
		e.preventDefault();
		$(this).hide();
		$('#hlp-first-upload').prop('hidden', false);
		$('body').addClass('hlp-picking-file');
	});

	$(document).on('click', '#hlp-cancel-start', function (e) {
		e.preventDefault();
		$('#hlp-first-upload').prop('hidden', true);
		$('#hlp-start-file').show();
		$('body').removeClass('hlp-picking-file');
	});

	$(document).on('click', '#hlp-open-replace', function (e) {
		e.preventDefault();
		$('#hlp-replace-wrap').prop('hidden', false);
		$(this).hide();
	});

	$(document).on('click', '#hlp-show-editor', function (e) {
		e.preventDefault();
		$('body').toggleClass('hlp-editor-revealed');
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
		return $('#hlp-canvas').length || $('#hlp-canvas-root').length ? 'editor' : '';
	}

	/* ---------------------------------------------------------------
	 * Row actions: toggle, new version, rollback, delete version, delete
	 * ------------------------------------------------------------- */

	$(document).on('click', '.hlp-toggle', function () {
		var $btn = $(this);
		$btn.prop('disabled', true);

		var data = {
			action: 'hlp_toggle',
			id: $btn.data('id'),
			nonce: $btn.data('nonce')
		};
		var ctx = editorContext();
		if (ctx) {
			data.context = ctx;
		}

		$.post(HLP.ajaxUrl, data)
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
			showNotice(HLP.strings.badType, 'error');
			return;
		}
		if (file.size > HLP.maxSize) {
			showNotice(HLP.strings.tooBig, 'error');
			return;
		}

		var data = new FormData();
		data.append('action', 'hlp_new_version');
		data.append('id', id);
		data.append('nonce', nonce);
		data.append('landing_file', file);
		var ctx = editorContext();
		if (ctx) {
			data.append('context', ctx);
		}

		if ($busy) {
			$busy.addClass('hlp-busy').prop('disabled', true);
		}

		$.ajax({
			url: HLP.ajaxUrl,
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
					$busy.removeClass('hlp-busy').prop('disabled', false);
				}
			});
	}

	var pendingCanvasFile = null;

	function stageCanvasFile(file) {
		if (!file) {
			return;
		}
		if (!/\.(html?|zip)$/i.test(file.name)) {
			showNotice(HLP.strings.badType, 'error');
			return;
		}
		if (file.size > HLP.maxSize) {
			showNotice(HLP.strings.tooBig, 'error');
			return;
		}
		pendingCanvasFile = file;
		$('#hlp-drop-name').text(file.name + ' (' + formatSize(file.size) + ')');
		$('#hlp-canvas-drop').addClass('hlp-ready');
		$('.hlp-drop-idle').prop('hidden', true);
		$('.hlp-drop-ready').prop('hidden', false);
	}

	function clearCanvasFile() {
		pendingCanvasFile = null;
		$('#hlp-canvas-file').val('');
		$('#hlp-canvas-drop').removeClass('hlp-ready');
		$('.hlp-drop-idle').prop('hidden', false);
		$('.hlp-drop-ready').prop('hidden', true);
	}

	$(document).on('click keydown', '#hlp-canvas-drop', function (e) {
		if ($(e.target).closest('#hlp-drop-confirm, #hlp-drop-cancel').length) {
			return;
		}
		if ($('#hlp-canvas-drop').hasClass('hlp-ready')) {
			return;
		}
		if (e.type === 'keydown' && e.which !== 13 && e.which !== 32) {
			return;
		}
		e.preventDefault();
		$('#hlp-canvas-file').trigger('click');
	});

	$(document).on('change', '#hlp-canvas-file', function () {
		if (this.files && this.files.length) {
			stageCanvasFile(this.files[0]);
		}
	});

	$(document).on('click', '#hlp-drop-confirm', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var $drop = $('#hlp-canvas-drop');
		if (!pendingCanvasFile) {
			return;
		}
		uploadNewVersion(pendingCanvasFile, $drop.data('id'), $drop.data('nonce'), $drop);
	});

	$(document).on('click', '#hlp-drop-cancel', function (e) {
		e.preventDefault();
		e.stopPropagation();
		clearCanvasFile();
	});

	$(document).on('dragenter dragover', '#hlp-canvas-drop', function (e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).addClass('hlp-dragover');
	});

	$(document).on('dragleave drop', '#hlp-canvas-drop', function (e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).removeClass('hlp-dragover');
	});

	$(document).on('drop', '#hlp-canvas-drop', function (e) {
		var dt = e.originalEvent.dataTransfer;
		if (dt && dt.files && dt.files.length) {
			stageCanvasFile(dt.files[0]);
		}
	});

	$(document).on('click', '.hlp-rollback, .hlp-del-version', function () {
		var $btn = $(this);
		var isDelete = $btn.hasClass('hlp-del-version');
		var v = $btn.data('v');

		if (isDelete && !window.confirm(HLP.strings.confirmVersion)) {
			return;
		}

		$btn.prop('disabled', true);

		var data = {
			action: isDelete ? 'hlp_delete_version' : 'hlp_rollback',
			id: $btn.data('id'),
			v: v,
			nonce: $btn.data('nonce')
		};
		var ctx = editorContext();
		if (ctx) {
			data.context = ctx;
		}

		$.post(HLP.ajaxUrl, data)
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

	$(document).on('click', '.hlp-delete', function () {
		var $btn = $(this);

		if (!window.confirm(HLP.strings.confirmDelete)) {
			return;
		}

		$btn.prop('disabled', true);

		var data = {
			action: 'hlp_delete',
			id: $btn.data('id'),
			nonce: $btn.data('nonce')
		};
		var ctx = editorContext();
		if (ctx) {
			data.context = ctx;
		}

		$.post(HLP.ajaxUrl, data)
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

	$(document).on('input', '#hlp-search', function () {
		var q = this.value.toLowerCase();
		var visible = 0;

		$('.hlp-table tbody tr').each(function () {
			var match = $(this).text().toLowerCase().indexOf(q) !== -1;
			$(this).toggle(match);
			if (match) {
				visible++;
			}
		});

		$('.hlp-no-results').prop('hidden', visible > 0);
	});

	/* ---------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------- */

	function logLine(text, kind) {
		var $log = $('#hlp-log');
		if (!$log.length) {
			return;
		}
		$log.find('.hlp-log-empty').remove();
		var cls = kind ? ' hlp-log-' + kind : '';
		$('<div class="hlp-log-line' + cls + '"></div>').text(text).appendTo($log);
		$log.scrollTop($log[0].scrollHeight);
	}

	function clearLog() {
		$('#hlp-log').empty();
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
