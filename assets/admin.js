/**
 * Cosmic Cache admin tools (vanilla JS, no build step).
 */
(function () {
	'use strict';

	function showResult(target, ok, message) {
		var el = document.querySelector(target);
		if (!el) {
			return;
		}
		el.className = 'cosmic-tool-result ' + (ok ? 'ok' : 'err');
		el.innerHTML = '<span class="spinner is-active"></span>' + message;
		setTimeout(function () {
			el.innerHTML = message;
		}, 400);
	}

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
		});
	}

	function renderReview(data) {
		var box = document.querySelector('.cosmic-critical-review');
		if (!box) {
			return;
		}
		var html = '<p class="description">Kept ' + esc(data.kept || 0)
			+ ', dropped ' + esc(data.dropped || 0)
			+ ', ' + esc(data.bytes || 0) + ' bytes.</p>';
		if (data.warnings && data.warnings.length) {
			html += '<ul class="description">';
			data.warnings.forEach(function (w) {
				html += '<li>' + esc(w) + '</li>';
			});
			html += '</ul>';
		}
		if (data.over_cap) {
			html += '<p class="description"><strong>Draft exceeds the inline size cap.</strong></p>';
		}
		html += '<p><button type="button" class="button button-primary" data-cosmic-action="critical_apply"'
			+ (data.over_cap ? ' data-over-cap="1"' : '') + '>Apply draft</button> '
			+ '<button type="button" class="button" data-cosmic-action="critical_discard">Discard draft</button></p>';
		box.innerHTML = html;
	}

	function post(action, extra) {
		var data = new URLSearchParams();
		data.set('action', 'cosmic_' + action);
		data.set('nonce', window.cosmicCache.nonce);
		Object.keys(extra || {}).forEach(function (k) {
			data.set(k, extra[k]);
		});
		return fetch(window.cosmicCache.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: data.toString()
		}).then(function (res) {
			return res.json();
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-cosmic-action]');
		if (!button) {
			return;
		}
		event.preventDefault();

		var action = button.getAttribute('data-cosmic-action');
		var data = new URLSearchParams();
		data.set('action', 'cosmic_' + action);
		data.set('nonce', window.cosmicCache.nonce);

		if (action === 'purge_urls') {
			var textarea = document.querySelector('[name="cosmic_purge_urls"]');
			data.set('urls', textarea ? textarea.value : '');
		}

		// Critical CSS review flow: generate renders metrics inline, apply
		// needs explicit confirmation past the size cap, state-changing
		// actions reload so the settings form is never stale.
		if (action === 'critical_generate') {
			button.disabled = true;
			showResult('.cosmic-tool-result', true, 'Generating — this may take several seconds…');
			post(action).then(function (json) {
				button.disabled = false;
				if (json && json.success) {
					showResult('.cosmic-tool-result', true, json.data.message);
					renderReview(json.data);
				} else {
					showResult('.cosmic-tool-result', false, (json && json.data && json.data.message) || 'Request failed.');
				}
			}).catch(function () {
				button.disabled = false;
				showResult('.cosmic-tool-result', false, 'Network error.');
			});
			return;
		}

		if (action === 'critical_apply' || action === 'critical_discard' || action === 'critical_revert') {
			var extra;
			if (action === 'critical_apply' && button.getAttribute('data-over-cap') === '1') {
				if (!window.confirm('Draft exceeds the inline size cap. Apply it anyway?')) {
					return;
				}
				extra = { force: '1' };
			}
			post(action, extra)
				.then(function (json) {
					if (json && json.success) {
						showResult('.cosmic-tool-result', true, json.data.message);
						setTimeout(function () {
							window.location.reload();
						}, 900);
					} else {
						showResult('.cosmic-tool-result', false, (json && json.data && json.data.message) || 'Request failed.');
					}
				})
				.catch(function () {
					showResult('.cosmic-tool-result', false, 'Network error.');
				});
			return;
		}

		fetch(window.cosmicCache.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: data.toString()
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (json) {
				if (json && json.success) {
					showResult('.cosmic-tool-result', true, json.data.message);
				} else {
					showResult('.cosmic-tool-result', false, (json && json.data && json.data.message) || 'Request failed.');
				}
			})
			.catch(function () {
				showResult('.cosmic-tool-result', false, 'Network error.');
			});
	});
})();
