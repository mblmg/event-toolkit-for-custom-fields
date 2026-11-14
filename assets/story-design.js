/* Event Toolkit for Custom Fields: settings of the story images – looks, background, live preview. */
(function () {
	'use strict';

	var data = window.etcfStoryDesign;
	var form = document.querySelector('form[action="options.php"]');
	var bg = document.getElementById('etcf-sharepic_bg');
	if (!data || !form || !bg) {
		return;
	}
	var t = data.i18n;

	function field(key) {
		return form.querySelector('[name="etcf_settings[' + key + ']"]');
	}

	/* Rows for gradient or picture only. */
	function toggleRows() {
		form.querySelectorAll('tr[data-etcf-bg]').forEach(function (row) {
			row.hidden = row.getAttribute('data-etcf-bg') !== bg.value;
		});
	}
	bg.addEventListener('change', toggleRows);
	toggleRows();

	/* Veil: show the percentage while sliding. */
	var veil = document.getElementById('etcf-sharepic_veil');
	if (veil) {
		veil.addEventListener('input', function () {
			var out = form.querySelector('output[for="etcf-sharepic_veil"]');
			if (out) {
				out.textContent = veil.value + ' %';
			}
		});
	}

	/* Looks: fill in the fields, the preview follows. */
	form.querySelectorAll('.etcf-preset').forEach(function (button) {
		button.addEventListener('click', function () {
			var preset = data.presets[button.getAttribute('data-preset')];
			if (!preset) {
				return;
			}
			Object.keys(preset.values).forEach(function (key) {
				var input = field(key);
				if (!input) {
					return;
				}
				if (input.type === 'checkbox') {
					input.checked = !!Number(preset.values[key]);
				} else {
					input.value = preset.values[key];
				}
			});
			form.querySelectorAll('.etcf-preset').forEach(function (b) {
				b.classList.toggle('is-active', b === button);
			});
			toggleRows();
			schedule();
		});
	});

	/* Picture from the media library. */
	var media = form.querySelector('.etcf-media');
	if (media && window.wp && wp.media) {
		var input = media.querySelector('input');
		var thumb = media.querySelector('.etcf-media-thumb');
		var remove = media.querySelector('.etcf-media-remove');
		var frame = null;
		media.querySelector('.etcf-media-choose').addEventListener('click', function () {
			if (!frame) {
				frame = wp.media({ title: t.choose, button: { text: t.use }, library: { type: 'image' }, multiple: false });
				frame.on('select', function () {
					var file = frame.state().get('selection').first().toJSON();
					var size = (file.sizes && (file.sizes.medium || file.sizes.full)) || file;
					input.value = file.id;
					thumb.src = size.url;
					thumb.hidden = false;
					remove.hidden = false;
					bg.value = 'image';
					toggleRows();
					schedule();
				});
			}
			frame.open();
		});
		remove.addEventListener('click', function () {
			input.value = '0';
			thumb.hidden = true;
			remove.hidden = true;
			schedule();
		});
	}

	/* Live preview: renders the three sample images with the values of the form. */
	var images = document.querySelectorAll('.etcf-story-preview img[data-frame]');
	var contrast = document.querySelector('.etcf-contrast');
	var state = document.querySelector('.etcf-preview-state');
	var timer = null;
	var round = 0;

	function schedule() {
		clearTimeout(timer);
		timer = setTimeout(update, 350);
	}

	function update() {
		var mine = ++round;
		if (state) {
			state.hidden = false;
		}
		images.forEach(function (img) {
			var body = new FormData(form);
			body.set('action', 'etcf_sharepic_preview');
			body.set('_wpnonce', data.nonce);
			body.set('frame', img.getAttribute('data-frame'));
			img.classList.add('is-loading');
			fetch(data.url, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (r) {
					if (!r.ok) {
						throw new Error('HTTP ' + r.status);
					}
					var report = r.headers.get('X-ETCF-Contrast');
					return r.blob().then(function (blob) {
						return { blob: blob, report: report };
					});
				})
				.then(function (res) {
					if (mine !== round) {
						return; // A newer preview is on its way.
					}
					if (img.src.indexOf('blob:') === 0) {
						URL.revokeObjectURL(img.src);
					}
					img.src = URL.createObjectURL(res.blob);
					img.classList.remove('is-loading');
					if (res.report) {
						showContrast(JSON.parse(res.report));
					}
				})
				.catch(function () {
					if (mine === round) {
						img.classList.remove('is-loading');
						if (contrast) {
							contrast.textContent = t.failed;
						}
					}
				});
		});
	}

	/* Contrast: text 4.5 : 1, info lines and web address 3 : 1 (WCAG). */
	function showContrast(report) {
		if (!contrast) {
			return;
		}
		var needs = { text: 4.5, muted: 3, accent: 3 };
		var list = document.createElement('ul');
		['light', 'dark'].forEach(function (mode) {
			Object.keys(needs).forEach(function (part) {
				var value = report[mode][part];
				if (value >= needs[part]) {
					return;
				}
				var li = document.createElement('li');
				li.className = 'is-weak';
				li.textContent = t[mode] + ', ' + t[part] + ': ' + String(value).replace('.', ',') + ' : 1, ' +
					t.weak.replace('%s', String(needs[part]).replace('.', ',') + ' : 1');
				list.appendChild(li);
			});
		});
		var weak = list.children.length > 0;
		if (!weak) {
			var li = document.createElement('li');
			li.className = 'is-good';
			li.textContent = t.good;
			list.appendChild(li);
		}
		contrast.textContent = '';
		contrast.appendChild(list);
		if (weak) {
			var hint = document.createElement('p');
			hint.textContent = t.hint;
			contrast.appendChild(hint);
		}
		contrast.classList.toggle('has-weak', weak);
	}

	form.addEventListener('input', function (e) {
		// Text fields (fonts, bottom line) update when they are left, not with every letter.
		if (e.target.type !== 'text') {
			schedule();
		}
	});
	form.addEventListener('change', schedule);
	if (images.length) {
		update(); // Shows the contrast of the saved settings right away.
		if (state) {
			state.hidden = true;
		}
	}
})();
