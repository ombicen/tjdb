(function () {
	'use strict';

	var root = document.getElementById('tjdb-builder-root');
	if (!root || typeof tjdbBuilderData === 'undefined') {
		return;
	}

	var config = tjdbBuilderData;

	// Every Nivoda-supported shape — used as the diamond-shape filter when
	// there's no setting yet to constrain it (diamond-first flow).
	var ALL_SHAPES = ['ROUND', 'PRINCESS', 'CUSHION', 'EMERALD', 'OVAL', 'PEAR', 'MARQUISE', 'RADIANT', 'ASSCHER', 'HEART'];

	var COLORS = ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N'];
	var CLARITIES = ['FL', 'IF', 'VVS1', 'VVS2', 'VS1', 'VS2', 'SI1', 'SI2', 'SI3', 'I1', 'I2', 'I3'];
	var CUTS = [
		{ value: 'ID', label: 'Ideal' },
		{ value: 'EX', label: 'Excellent' },
		{ value: 'VG', label: 'Very Good' },
		{ value: 'GD', label: 'Good' },
		{ value: 'FR', label: 'Fair' },
		{ value: 'PR', label: 'Poor' },
	];
	// Every value a diamond can actually be sorted by — including
	// color/clarity/cut, which are also reachable by clicking their list-view
	// column headers (see LIST_COLUMNS below). Keeping them here too means
	// the dropdown always reflects the active sort instead of going blank
	// when it was set via a column click.
	var SORT_OPTIONS = [
		{ value: 'popular:DESC', label: 'Most Popular' },
		{ value: 'price:ASC', label: 'Price: Low to High' },
		{ value: 'price:DESC', label: 'Price: High to Low' },
		{ value: 'size:DESC', label: 'Carat: High to Low' },
		{ value: 'size:ASC', label: 'Carat: Low to High' },
		{ value: 'color:ASC', label: 'Color: D to Z' },
		{ value: 'color:DESC', label: 'Color: Z to D' },
		{ value: 'clarity:ASC', label: 'Clarity: Best to Worst' },
		{ value: 'clarity:DESC', label: 'Clarity: Worst to Best' },
		{ value: 'cut:ASC', label: 'Cut: Best to Worst' },
		{ value: 'cut:DESC', label: 'Cut: Worst to Best' },
	];

	// List-view columns, in display order. sortType is the Nivoda
	// DiamondOrderType this column maps to, or null if it can't be
	// server-sorted (there's no "shape" order field, and Compare is a
	// selection column, not data).
	var LIST_COLUMNS = [
		{ key: 'shape', label: 'Shape', sortType: null },
		{ key: 'price', label: 'Price', sortType: 'price' },
		{ key: 'carat', label: 'Carat', sortType: 'size' },
		{ key: 'cut', label: 'Cut', sortType: 'cut' },
		{ key: 'color', label: 'Color', sortType: 'color' },
		{ key: 'clarity', label: 'Clarity', sortType: 'clarity' },
		{ key: 'compare', label: 'Compare', sortType: null },
	];

	// Hand-authored (not downloaded — avoids third-party licensing/CDN
	// dependency for something this small) line-icon outlines for the shape
	// picker, one per Nivoda shape. Plain <path>/<circle>/<ellipse> markup
	// only, dropped straight into a shared 24x24 <svg> wrapper by
	// shapeIconSvg() so every icon picks up stroke=currentColor from CSS.
	var SHAPE_ICON_PATHS = {
		ROUND: '<circle cx="12" cy="12" r="8"/><path d="M12 4v16M4 12h16M6.34 6.34l11.32 11.32M17.66 6.34L6.34 17.66"/>',
		PRINCESS: '<rect x="5" y="5" width="14" height="14"/><path d="M5 5l14 14M19 5L5 19"/>',
		CUSHION: '<rect x="5" y="5" width="14" height="14" rx="5"/>',
		EMERALD: '<path d="M7 5h10l2 3v8l-2 3H7l-2-3V8l2-3z"/>',
		OVAL: '<ellipse cx="12" cy="12" rx="6" ry="9"/>',
		PEAR: '<path d="M12 3c3 4 7 8 7 12a7 7 0 11-14 0c0-4 4-8 7-12z"/>',
		MARQUISE: '<path d="M12 2c5 4 8 7 8 10s-3 6-8 10c-5-4-8-7-8-10s3-6 8-10z"/>',
		RADIANT: '<path d="M7 4h10l3 4v8l-3 4H7l-3-4V8l3-4z"/>',
		ASSCHER: '<path d="M9 4h6l5 5v6l-5 5H9l-5-5V9l5-5z"/>',
		HEART: '<path d="M12 21s-7-4.5-9.5-9C1 8 2 4 6 4c2 0 4 1.5 6 4 2-2.5 4-4 6-4 4 0 5 4 3.5 8-2.5 4.5-9.5 9-9.5 9z"/>',
	};

	function shapeIconSvg(shape) {
		var inner = SHAPE_ICON_PATHS[shape] || SHAPE_ICON_PATHS.ROUND;
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">' + inner + '</svg>';
	}

	// Worst-to-best display order for the Cut/Color/Clarity range sliders —
	// distinct from CUTS/COLORS/CLARITIES above (kept as-is since they're
	// also used for the list-view columns and modal spec grid, where D-to-N
	// / FL-to-I3 best-first order is the correct convention). CUTS in
	// particular isn't in grade order at all (it's ID, EX, VG, GD, FR, PR),
	// so a slider over it needs its own explicitly-ordered copy.
	var CUT_SLIDER_OPTIONS = [
		{ value: 'PR', label: 'Poor' },
		{ value: 'FR', label: 'Fair' },
		{ value: 'GD', label: 'Good' },
		{ value: 'VG', label: 'Very Good' },
		{ value: 'EX', label: 'Excellent' },
		{ value: 'ID', label: 'Ideal' },
	];
	var COLOR_SLIDER_OPTIONS = COLORS.slice().reverse();
	var CLARITY_SLIDER_OPTIONS = CLARITIES.slice().reverse();

	var state = {
		startMode: 'setting', // 'setting' | 'diamond' — whichever the customer picked first
		setting: null,
		variation: null,
		selectedAttributes: null, // { attribute_key: value } — chosen ring-size/metal etc. options for the current setting
		diamond: null, // summary object from search results
		step: 'diamond', // 'diamond' | 'setting' | 'complete'
		shape: null,
		caratFrom: null,
		caratTo: null,
		color: [],
		clarity: [],
		cut: [],
		sort: 'popular:DESC',
		viewMode: 'grid', // 'grid' | 'list'
		searchToken: 0,
		settingsSearchToken: 0,
		compareDiamonds: {}, // diamond_id -> diamond object, persists across re-searches
	};

	// Accumulated diamond search results — separate from `state` since it's
	// not something to restore from the URL, just paged results for the
	// current filters. Reset on every fresh (non-append) search.
	var searchResults = { items: [], total: 0, offset: 0 };

	// loupe360 (video and the 360° spin) bakes a fixed pixel canvas into the
	// embed URL itself (".../500/500?..."). Shrinking the iframe's own CSS
	// box squeezes that third-party page into a smaller viewport, which
	// makes IT show its own internal scrollbars — the page doesn't reflow,
	// it just stops fitting. Rendering at native size and scaling the whole
	// iframe down visually with a CSS transform avoids that. Shared by the
	// diamond modal and the archive-card hover spinner below.
	var IFRAME_NATIVE_SIZE = 500;

	function scaledIframeMarkup(src) {
		return (
			'<div class="tjdb-video-scaler" style="width:' + IFRAME_NATIVE_SIZE + 'px;height:' + IFRAME_NATIVE_SIZE + 'px;">' +
			'<iframe class="tjdb-video-frame" src="' + esc(src) + '" width="' + IFRAME_NATIVE_SIZE + '" height="' + IFRAME_NATIVE_SIZE + '" scrolling="no" allowfullscreen></iframe>' +
			'</div>'
		);
	}

	function fitScaledIframe(containerEl) {
		var scaler = containerEl.querySelector('.tjdb-video-scaler');
		if (!scaler) return;
		var containerSize = Math.min(containerEl.clientWidth, containerEl.clientHeight);
		scaler.style.transform = 'scale(' + containerSize / IFRAME_NATIVE_SIZE + ')';
	}

	// Swaps a result card's static image for its live 360° spin on hover,
	// and back again on hover-out — see renderDiamondCard()'s comment for
	// why this is hover-triggered rather than rendered up front. The spin
	// iframe is built at most once per card (a diamond.spin_url from the
	// API is trusted as-is) and then just shown/hidden — never torn down
	// and rebuilt — so re-hovering the same card doesn't reload it.
	function bindCardSpinHover(container) {
		container.querySelectorAll('.tjdb-card-media[data-spin-url]').forEach(function (mediaEl) {
			var spinUrl = mediaEl.getAttribute('data-spin-url');
			var hoverTimer = null;
			var holder = null;

			mediaEl.addEventListener('mouseenter', function () {
				// A brief delay avoids firing an iframe load for every card
				// the cursor merely passes over on its way elsewhere.
				hoverTimer = setTimeout(function () {
					if (!holder) {
						holder = document.createElement('div');
						holder.className = 'tjdb-card-spin-holder';
						holder.innerHTML = scaledIframeMarkup(spinUrl);
						mediaEl.appendChild(holder);
						fitScaledIframe(holder);
					}
					mediaEl.classList.add('tjdb-spin-active');
				}, 200);
			});

			mediaEl.addEventListener('mouseleave', function () {
				clearTimeout(hoverTimer);
				mediaEl.classList.remove('tjdb-spin-active');
			});
		});
	}

	// The order the 3 steps appear in — whichever was picked first, then
	// the other, then Complete. Ring size (when the setting has variations)
	// is chosen inline on Complete, not a step of its own.
	function stepOrder() {
		var first = state.startMode;
		var second = first === 'setting' ? 'diamond' : 'setting';
		return [first, second, 'complete'];
	}

	function nextStepAfter(key) {
		var order = stepOrder();
		var index = order.indexOf(key);
		return order[index + 1] || 'complete';
	}

	function esc(str) {
		var div = document.createElement('div');
		div.textContent = str == null ? '' : String(str);
		return div.innerHTML;
	}

	function formatPrice(cents) {
		var decimals = config.currencyDecimals !== undefined && config.currencyDecimals !== null ? config.currencyDecimals : 2;
		var symbol = config.currencySymbol || '£';
		return symbol + (Number(cents || 0) / 100).toFixed(decimals);
	}

	function apiRequest(path, options) {
		options = options || {};
		var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce };
		return fetch(config.restUrl + path, {
			method: options.method || 'GET',
			headers: headers,
			body: options.body ? JSON.stringify(options.body) : undefined,
		}).then(function (response) {
			return response.json().then(function (json) {
				if (!response.ok) {
					var error = new Error(json && json.message ? json.message : 'Request failed.');
					error.code = json && json.code;
					error.data = json && json.data;
					throw error;
				}
				return json;
			});
		});
	}

	function fetchSetting(id) {
		return apiRequest('/settings/' + id);
	}

	// Fetches the *real* [tjdb_settings_archive] page (a normal page request,
	// not a REST call) filtered by ?shape=&carat=, then pulls the product
	// grid out of it. This — not a REST endpoint returning the same markup —
	// is what makes the diamond-first "choose your setting" step look
	// pixel-identical to the real archive page: Woodmart's theme CSS for
	// swatches/labels/hover-effects etc. is only queued into a page's own
	// wp_footer during a genuine (non-REST) page render, so a REST-rendered
	// copy of the same HTML always came out unstyled no matter what markup
	// it returned. Any styles the current page is still missing (this is
	// its first time showing product cards) are copied in from the fetched
	// page's <head>.
	function fetchCompatibleSettingsHtml(diamond) {
		if (!config.archiveUrl) {
			return Promise.reject(new Error('Settings archive is not configured.'));
		}

		var params = new URLSearchParams();
		if (diamond) {
			params.set('shape', diamond.certificate.shape);
			params.set('carat', diamond.certificate.carats);
		}

		var joiner = config.archiveUrl.indexOf('?') === -1 ? '?' : '&';

		return fetch(config.archiveUrl + joiner + params.toString(), { credentials: 'same-origin' })
			.then(function (response) {
				if (!response.ok) {
					throw new Error('Unable to load settings right now.');
				}
				return response.text();
			})
			.then(function (text) {
				var doc = new DOMParser().parseFromString(text, 'text/html');
				syncThemeStyles(doc);
				var grid = doc.querySelector('.wd-products-element') || doc.querySelector('.products');
				return { html: grid ? grid.outerHTML : '<p>' + esc('No settings currently support this diamond. Try a different diamond.') + '</p>' };
			});
	}

	// Copies any <style id="..."> or <link id="..." rel="stylesheet"> from a
	// fetched page's <head> into the current document, skipping ones already
	// present (matched by id) — see fetchCompatibleSettingsHtml() above.
	function syncThemeStyles(doc) {
		doc.querySelectorAll('head style[id], head link[rel="stylesheet"][id]').forEach(function (el) {
			if (el.id && !document.getElementById(el.id)) {
				document.head.appendChild(el.cloneNode(true));
			}
		});
	}

	function searchDiamonds(filters) {
		return apiRequest('/diamonds/search', { method: 'POST', body: filters });
	}

	function fetchDiamondDetail(diamondId) {
		return apiRequest('/diamonds/detail', { method: 'POST', body: { diamond_id: diamondId } });
	}

	function addBundleToCart(params) {
		return apiRequest('/cart/add', { method: 'POST', body: params });
	}

	function getSettingIdFromUrl() {
		var params = new URLSearchParams(window.location.search);
		var raw = params.get('tjdb_setting_id');
		var id = raw ? parseInt(raw, 10) : NaN;
		return isFinite(id) && id > 0 ? id : null;
	}

	function settingPriceCents() {
		if (!state.setting) return 0;
		return state.variation ? state.variation.price_cents : state.setting.price_cents;
	}

	// ---- Stepper ----

	function buildSteps() {
		var defs = {
			setting: {
				key: 'setting',
				label: 'Setting',
				detail: state.setting ? state.setting.name + ' — ' + formatPrice(settingPriceCents()) : null,
				image: state.setting ? state.setting.image : null,
			},
			diamond: {
				key: 'diamond',
				label: 'Diamond',
				detail: state.diamond
					? state.diamond.certificate.carats.toFixed(2) + 'ct ' + state.diamond.certificate.shape + ' — ' + formatPrice(state.diamond.price_cents)
					: null,
				image: state.diamond ? state.diamond.image : null,
			},
			complete: {
				key: 'complete',
				label: 'Review Your Ring',
				detail: state.setting && state.setting.has_variations ? 'Select Ring Size' : null,
				image: null,
			},
		};

		return stepOrder().map(function (key) {
			return defs[key];
		});
	}

	function completedKeys() {
		var done = [];
		if (state.setting) done.push('setting');
		if (state.diamond) done.push('diamond');
		return done;
	}

	function renderStepper() {
		var steps = buildSteps();
		var completed = completedKeys();

		var html = '<ol class="tjdb-stepper">';
		steps.forEach(function (step, index) {
			var isCompleted = completed.indexOf(step.key) !== -1;
			var isCurrent = step.key === state.step;
			// Before anything's been picked at all, "Setting" and "Diamond"
			// are freely interchangeable entry points — let the user click
			// whichever one they actually want to start from.
			var canSwitchEntryPoint = !state.setting && !state.diamond && !isCurrent && step.key !== 'complete';
			var isClickable = isCompleted || canSwitchEntryPoint;
			var classes = 'tjdb-stepper-item' + (isCurrent ? ' is-current' : '') + (isCompleted ? ' is-completed' : '') + (isClickable ? ' is-clickable' : '');

			html += '<li class="' + classes + '">';
			html += '<button type="button" class="tjdb-stepper-button" data-step-jump="' + esc(step.key) + '"' + (isClickable ? '' : ' disabled') + '>';
			html += '<span class="tjdb-stepper-circle">' + (isCompleted && !isCurrent ? '&#10003;' : index + 1) + '</span>';
			html += '<span class="tjdb-stepper-text">';
			html += '<span class="tjdb-stepper-label">' + esc(step.label) + '</span>';
			if (step.detail) {
				html += '<span class="tjdb-stepper-detail">' + esc(step.detail) + '</span>';
			}
			if (isCompleted && !isCurrent) {
				html += '<span class="tjdb-stepper-change">Change</span>';
			}
			html += '</span>';
			if (step.image) {
				html += '<img class="tjdb-stepper-thumb" src="' + esc(step.image) + '" alt="">';
			} else {
				html += '<svg class="tjdb-stepper-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true"><path d="M4 9L8 3h8l4 6-10 12L4 9z"/><path d="M4 9h16M8 3l1.5 6L12 21l2.5-12L16 3"/></svg>';
			}
			html += '</button></li>';
		});
		html += '</ol>';

		return html;
	}

	function bindStepperEvents(container) {
		container.querySelectorAll('[data-step-jump]').forEach(function (button) {
			if (button.disabled) return;
			button.addEventListener('click', function () {
				jumpTo(button.getAttribute('data-step-jump'));
			});
		});
	}

	// Clicking a completed step is a "Change" action — it lands back on that
	// step's own picker to browse a replacement, but deliberately does NOT
	// clear the existing selection: only actually picking something new
	// (selectSettingAndAdvance/selectDiamondAndAdvance) overwrites it. This
	// way clicking "Change" and then changing your mind (navigating away, or
	// jumping straight to Complete via the stepper) leaves the original
	// choice intact instead of forcing a reselection with no way back.
	// renderSettingStep() looks and behaves identically whether or not a
	// diamond is already selected, so there's never a need to leave the page
	// for this, even if the setting was originally chosen externally on a
	// product/archive page. Clicking the *other, not-yet-started* step
	// before anything's been picked instead just switches which one to
	// start from (see canSwitchEntryPoint above).
	function jumpTo(key) {
		if (key === 'setting') {
			state.startMode = state.diamond ? state.startMode : 'setting';
			state.step = 'setting';
			render();
			return;
		}
		if (key === 'diamond') {
			state.startMode = state.diamond ? state.startMode : 'diamond';
			state.step = 'diamond';
			render();
			return;
		}
		if (key === 'complete') {
			state.step = 'complete';
			render();
		}
	}

	// ---- Steps ----

	function availableShapes() {
		return state.setting ? state.setting.allowed_shapes : ALL_SHAPES;
	}

	// The search itself is normally scoped to the setting's shape/carat
	// range, but nothing stops a widened carat filter, a stale comparison
	// pick from before the setting changed, or a diamond opened via a
	// direct link from landing here anyway — CartEndpoint enforces this
	// server-side regardless, but rejecting it here too means the customer
	// finds out before investing effort in a selection that could never
	// have worked, not after clicking Add to Cart at the end.
	function isDiamondCompatibleWithSetting(diamond) {
		if (diamond.availability !== 'AVAILABLE') return false;
		if (!state.setting) return true;

		var cert = diamond.certificate;
		var allowed = state.setting.allowed_shapes;
		if (allowed && allowed.length && allowed.indexOf(cert.shape) === -1) return false;
		if (state.setting.min_carat && cert.carats < state.setting.min_carat) return false;
		if (state.setting.max_carat && cert.carats > state.setting.max_carat) return false;

		return true;
	}

	function renderDiamondStep() {
		var shapes = availableShapes();

		if (!state.shape || shapes.indexOf(state.shape) === -1) {
			state.shape = shapes[0] || null;
		}
		if (state.caratFrom === null && state.setting) {
			state.caratFrom = state.setting.min_carat || null;
		}
		if (state.caratTo === null && state.setting) {
			state.caratTo = state.setting.max_carat || null;
		}

		var html = '<div class="tjdb-step tjdb-step-diamonds">';
		if (state.setting) {
			html += '<button class="tjdb-back" data-action="back-to-setting">← Back to ' + esc(state.setting.name) + '</button>';
		}
		html += '<h2>Choose your diamond</h2>';

		html += '<div class="tjdb-diamond-layout">';
		html += renderFiltersPanel();
		html += '<div class="tjdb-results-panel">';
		html += renderToolbar();
		html += '<div id="tjdb-diamond-results"><p class="tjdb-loading">Searching diamonds…</p></div>';
		html += '</div>';
		html += '</div>';
		html += '</div>';

		root.innerHTML = renderStepper() + html;
		bindStepperEvents(root);

		var backBtn = root.querySelector('[data-action="back-to-setting"]');
		if (backBtn) {
			backBtn.addEventListener('click', function () {
				window.location.href = state.setting.permalink;
			});
		}

		bindFilterEvents();
		bindToolbarEvents();

		runDiamondSearch();
	}

	// ---- Filters panel: shape grid + dual-handle range sliders ----
	// Modelled on brilliantearth.com's "Start with a Diamond" filter sidebar
	// (icon-based shape picker, collapsible sections, dual-handle sliders,
	// a removable-chip summary of what's active) — reimplemented against
	// our own data/state rather than copying any of their markup or code.

	function shapeLabel(shape) {
		return shape.charAt(0) + shape.slice(1).toLowerCase();
	}

	// Normalizes a plain string list (COLOR_SLIDER_OPTIONS, CLARITY_SLIDER_OPTIONS)
	// and a {value,label} list (CUT_SLIDER_OPTIONS) to the same shape.
	function sliderOptions(list) {
		return list.map(function (item) {
			return typeof item === 'string' ? { value: item, label: item } : item;
		});
	}

	// Where the current state[key] selection sits within an ordered option
	// list, as a [lowIndex, highIndex] pair — full range when nothing (or
	// something no longer in the list) is selected.
	function selectedIndexRange(options, selected) {
		var lastIndex = options.length - 1;
		if (!selected.length) return [0, lastIndex];
		var indexes = selected
			.map(function (v) { return options.findIndex(function (o) { return o.value === v; }); })
			.filter(function (i) { return i !== -1; });
		if (!indexes.length) return [0, lastIndex];
		return [Math.min.apply(null, indexes), Math.max.apply(null, indexes)];
	}

	function renderShapeSection() {
		var shapes = availableShapes();
		if (shapes.length <= 1) return '';
		var html = '<details class="tjdb-filter-section" open><summary>Diamond Shape</summary>';
		html += '<div class="tjdb-shape-grid">';
		shapes.forEach(function (s) {
			var active = s === state.shape ? ' is-active' : '';
			html += '<button type="button" class="tjdb-shape-option' + active + '" data-shape="' + esc(s) + '">';
			html += '<span class="tjdb-shape-icon">' + shapeIconSvg(s) + '</span>';
			html += '<span class="tjdb-shape-label">' + esc(shapeLabel(s)) + '</span>';
			html += '</button>';
		});
		html += '</div></details>';
		return html;
	}

	function caratBounds() {
		return [
			state.setting && state.setting.min_carat ? state.setting.min_carat : 0,
			state.setting && state.setting.max_carat ? state.setting.max_carat : 10,
		];
	}

	function renderCaratSection() {
		var bounds = caratBounds();
		var lo = state.caratFrom !== null ? state.caratFrom : bounds[0];
		var hi = state.caratTo !== null ? state.caratTo : bounds[1];

		var html = '<details class="tjdb-filter-section" open><summary>Carat</summary>';
		if (state.setting) {
			html += '<p class="tjdb-filter-hint">This setting fits ' + bounds[0] + '–' + (state.setting.max_carat || '∞') + 'ct.</p>';
		}
		html += '<div class="tjdb-range-slider" data-slider-key="carat">';
		html += '<div class="tjdb-range-track"><div class="tjdb-range-fill"></div></div>';
		html += '<input type="range" class="tjdb-range-input tjdb-range-min" min="' + bounds[0] + '" max="' + bounds[1] + '" step="0.01" value="' + lo + '" aria-label="Minimum carat">';
		html += '<input type="range" class="tjdb-range-input tjdb-range-max" min="' + bounds[0] + '" max="' + bounds[1] + '" step="0.01" value="' + hi + '" aria-label="Maximum carat">';
		html += '</div>';
		html += '<div class="tjdb-range-values"><span data-carat-from>' + lo.toFixed(2) + 'ct</span><span data-carat-to>' + hi.toFixed(2) + 'ct</span></div>';
		html += '</details>';
		return html;
	}

	function renderCategorySection(title, key, rawOptions) {
		var options = sliderOptions(rawOptions);
		var range = selectedIndexRange(options, state[key]);
		var lastIndex = options.length - 1;

		var html = '<details class="tjdb-filter-section" open><summary>' + esc(title) + '</summary>';
		html += '<div class="tjdb-range-slider" data-slider-key="' + esc(key) + '">';
		html += '<div class="tjdb-range-track"><div class="tjdb-range-fill"></div></div>';
		html += '<input type="range" class="tjdb-range-input tjdb-range-min" min="0" max="' + lastIndex + '" step="1" value="' + range[0] + '" aria-label="Minimum ' + esc(title) + '">';
		html += '<input type="range" class="tjdb-range-input tjdb-range-max" min="0" max="' + lastIndex + '" step="1" value="' + range[1] + '" aria-label="Maximum ' + esc(title) + '">';
		html += '</div>';
		html += '<div class="tjdb-range-scale">';
		options.forEach(function (o, i) {
			html += '<span class="' + (i >= range[0] && i <= range[1] ? 'is-active' : '') + '">' + esc(o.label) + '</span>';
		});
		html += '</div></details>';
		return html;
	}

	// What the active-filter chip row shows — 'clear' is null for the shape
	// chip (there's always exactly one shape selected; nothing sensible to
	// revert it to) and the state key to reset for every other chip.
	function activeChips() {
		var chips = [];
		if (state.shape) {
			chips.push({ label: shapeLabel(state.shape), clear: null });
		}

		var bounds = caratBounds();
		var lo = state.caratFrom !== null ? state.caratFrom : bounds[0];
		var hi = state.caratTo !== null ? state.caratTo : bounds[1];
		if (lo > bounds[0] || hi < bounds[1]) {
			chips.push({ label: lo.toFixed(2) + ' – ' + hi.toFixed(2) + 'ct', clear: 'carat' });
		}

		if (state.cut.length) chips.push({ label: 'Cut: ' + state.cut.length + ' selected', clear: 'cut' });
		if (state.color.length) chips.push({ label: 'Color: ' + state.color.slice().sort().join(', '), clear: 'color' });
		if (state.clarity.length) chips.push({ label: 'Clarity: ' + state.clarity.slice().sort().join(', '), clear: 'clarity' });

		return chips;
	}

	function filterChipsHtml() {
		var chips = activeChips();
		if (!chips.length) return '';
		var hasClearable = chips.some(function (c) { return c.clear; });

		var html = '<div class="tjdb-filter-chips">';
		chips.forEach(function (chip) {
			html += '<span class="tjdb-chip">' + esc(chip.label);
			if (chip.clear) {
				html += '<button type="button" class="tjdb-chip-remove" data-clear-filter="' + esc(chip.clear) + '" aria-label="Remove ' + esc(chip.label) + ' filter">&times;</button>';
			}
			html += '</span>';
		});
		if (hasClearable) {
			html += '<button type="button" class="tjdb-chip tjdb-chip-reset" id="tjdb-clear-filters">Reset All &times;</button>';
		}
		html += '</div>';
		return html;
	}

	function renderFiltersPanel() {
		var html = '<div class="tjdb-filters-panel">';
		html += filterChipsHtml();
		html += renderShapeSection();
		html += renderCaratSection();
		html += renderCategorySection('Cut', 'cut', CUT_SLIDER_OPTIONS);
		html += renderCategorySection('Color', 'color', COLOR_SLIDER_OPTIONS);
		html += renderCategorySection('Clarity', 'clarity', CLARITY_SLIDER_OPTIONS);
		html += '</div>';
		return html;
	}

	function compareCount() {
		return Object.keys(state.compareDiamonds).length;
	}

	function renderToolbar() {
		var html = '<div class="tjdb-toolbar">';
		html += '<div class="tjdb-view-toggle">';
		html += '<button type="button" data-view="grid" class="' + (state.viewMode === 'grid' ? 'active' : '') + '">Grid</button>';
		html += '<button type="button" data-view="list" class="' + (state.viewMode === 'list' ? 'active' : '') + '">List</button>';
		html += '</div>';
		html += '<button type="button" class="tjdb-compare-toggle" id="tjdb-open-compare"' + (compareCount() ? '' : ' disabled') + '>Compare (' + compareCount() + ')</button>';
		html += '<label class="tjdb-sort-select">Sort by ';
		html += '<select id="tjdb-sort">';
		SORT_OPTIONS.forEach(function (opt) {
			var selected = opt.value === state.sort ? ' selected' : '';
			html += '<option value="' + esc(opt.value) + '"' + selected + '>' + esc(opt.label) + '</option>';
		});
		html += '</select></label>';
		html += '</div>';
		return html;
	}

	function clearAllFilters() {
		state.color = [];
		state.clarity = [];
		state.cut = [];
		state.caratFrom = state.setting ? state.setting.min_carat || null : null;
		state.caratTo = state.setting ? state.setting.max_carat || null : null;
		renderDiamondStep();
	}

	function clearSingleFilter(key) {
		if (key === 'carat') {
			state.caratFrom = state.setting ? state.setting.min_carat || null : null;
			state.caratTo = state.setting ? state.setting.max_carat || null : null;
		} else {
			state[key] = [];
		}
		renderDiamondStep();
	}

	function bindChipEvents(container) {
		container.querySelectorAll('[data-clear-filter]').forEach(function (button) {
			button.addEventListener('click', function () {
				clearSingleFilter(button.getAttribute('data-clear-filter'));
			});
		});
		var clearBtn = container.querySelector('#tjdb-clear-filters');
		if (clearBtn) {
			clearBtn.addEventListener('click', clearAllFilters);
		}
	}

	// Shared by every dual-handle slider (carat, and the Cut/Color/Clarity
	// category sliders) — two overlapping native <input type=range>
	// elements is the standard lightweight way to get a real dual-handle
	// slider without a library; onCommit fires on every drag frame
	// (committed=false, for live fill/label feedback) and once more on
	// release (committed=true, when the actual filter + search should run).
	function bindRangeSlider(wrap, onUpdate) {
		var minInput = wrap.querySelector('.tjdb-range-min');
		var maxInput = wrap.querySelector('.tjdb-range-max');
		var fill = wrap.querySelector('.tjdb-range-fill');
		var lo = parseFloat(minInput.min);
		var hi = parseFloat(minInput.max);
		var span = hi - lo || 1;

		function updateFill() {
			var a = parseFloat(minInput.value);
			var b = parseFloat(maxInput.value);
			fill.style.left = ((a - lo) / span * 100) + '%';
			fill.style.right = (100 - (b - lo) / span * 100) + '%';
		}
		updateFill();

		[minInput, maxInput].forEach(function (input) {
			input.addEventListener('input', function () {
				if (parseFloat(minInput.value) > parseFloat(maxInput.value)) {
					if (input === minInput) minInput.value = maxInput.value;
					else maxInput.value = minInput.value;
				}
				updateFill();
				onUpdate(parseFloat(minInput.value), parseFloat(maxInput.value), false);
			});
			input.addEventListener('change', function () {
				onUpdate(parseFloat(minInput.value), parseFloat(maxInput.value), true);
			});
		});
	}

	function bindFilterEvents() {
		bindChipEvents(root);

		root.querySelectorAll('[data-shape]').forEach(function (button) {
			button.addEventListener('click', function () {
				state.shape = button.getAttribute('data-shape');
				renderDiamondStep();
			});
		});

		var caratWrap = root.querySelector('.tjdb-range-slider[data-slider-key="carat"]');
		if (caratWrap) {
			var bounds = caratBounds();
			var fromLabel = caratWrap.parentElement.querySelector('[data-carat-from]');
			var toLabel = caratWrap.parentElement.querySelector('[data-carat-to]');
			bindRangeSlider(caratWrap, function (loValue, hiValue, committed) {
				if (fromLabel) fromLabel.textContent = loValue.toFixed(2) + 'ct';
				if (toLabel) toLabel.textContent = hiValue.toFixed(2) + 'ct';
				if (!committed) return;
				state.caratFrom = loValue <= bounds[0] ? (state.setting ? bounds[0] : null) : loValue;
				state.caratTo = hiValue >= bounds[1] ? (state.setting ? (state.setting.max_carat || null) : null) : hiValue;
				runDiamondSearch();
				refreshFilterChips();
			});
		}

		[
			['cut', CUT_SLIDER_OPTIONS],
			['color', COLOR_SLIDER_OPTIONS],
			['clarity', CLARITY_SLIDER_OPTIONS],
		].forEach(function (pair) {
			var key = pair[0];
			var options = sliderOptions(pair[1]);
			var wrap = root.querySelector('.tjdb-range-slider[data-slider-key="' + key + '"]');
			if (!wrap) return;
			var lastIndex = options.length - 1;
			bindRangeSlider(wrap, function (loValue, hiValue, committed) {
				if (!committed) return;
				var loIndex = Math.round(loValue);
				var hiIndex = Math.round(hiValue);
				state[key] = (loIndex === 0 && hiIndex === lastIndex) ? [] : options.slice(loIndex, hiIndex + 1).map(function (o) { return o.value; });
				runDiamondSearch();
				refreshFilterChips();
			});
		});
	}

	// Rewriting just the chip row after a slider commit avoids a full panel
	// re-render mid-interaction, which would rebuild the very slider the
	// customer's pointer is still on.
	function refreshFilterChips() {
		var panel = root.querySelector('.tjdb-filters-panel');
		if (!panel) return;
		var existing = panel.querySelector('.tjdb-filter-chips');
		var html = filterChipsHtml();
		if (existing) {
			existing.outerHTML = html;
		} else if (html) {
			panel.insertAdjacentHTML('afterbegin', html);
		}
		bindChipEvents(panel);
	}

	function bindToolbarEvents() {
		root.querySelectorAll('[data-view]').forEach(function (button) {
			button.addEventListener('click', function () {
				state.viewMode = button.getAttribute('data-view');
				renderDiamondStep();
			});
		});

		var sortSelect = root.querySelector('#tjdb-sort');
		if (sortSelect) {
			sortSelect.addEventListener('change', function () {
				state.sort = sortSelect.value;
				runDiamondSearch();
			});
		}

		var compareBtn = root.querySelector('#tjdb-open-compare');
		if (compareBtn) {
			compareBtn.addEventListener('click', openCompareModal);
		}
	}

	function setSort(sortType, direction) {
		state.sort = sortType + ':' + direction;
		var sortSelect = root.querySelector('#tjdb-sort');
		if (sortSelect) sortSelect.value = state.sort;
		runDiamondSearch();
	}

	function sortIndicator(column) {
		var parts = state.sort.split(':');
		if (parts[0] !== column.sortType) return '';
		return parts[1] === 'ASC' ? ' ↑' : ' ↓';
	}

	// ---- Modals ----

	function closeModal() {
		var overlay = document.getElementById('tjdb-modal-overlay');
		if (overlay) overlay.remove();
	}

	function selectDiamondAndAdvance(diamond) {
		state.diamond = diamond;
		state.step = nextStepAfter('diamond');
		closeModal();
		render();
	}

	function selectSettingAndAdvance(setting) {
		if (!state.setting || state.setting.id !== setting.id) {
			state.variation = null;
			state.selectedAttributes = null;
		}
		state.setting = setting;
		state.step = nextStepAfter('setting');
		render();
	}

	function diamondSpecPairs(diamond) {
		var cert = diamond.certificate;
		return [
			['Carat', cert.carats.toFixed(2)],
			['Cut', cert.cut],
			['Color', cert.color],
			['Clarity', cert.clarity],
			['Lab', cert.lab],
			['Certificate #', cert.cert_number],
			['Polish', cert.polish],
			['Symmetry', cert.symmetry],
		];
	}

	/**
	 * Media panel + right-hand info panel, mirroring the reference layout:
	 * a large viewer with a thumbnail strip (video/image/certificate) on
	 * the left, title/price/spec-grid/CTA on the right — see
	 * https://www.brilliantearth.com/engagement-rings/start-with-a-diamond/
	 */
	function openDiamondModal(diamond) {
		if (!diamond) return;

		var cert = diamond.certificate;
		var media = [];
		if (diamond.spin_url) media.push({ key: 'spin', label: '360° Spin View' });
		if (diamond.video) media.push({ key: 'video', label: 'Real Diamond Video' });
		if (diamond.image) media.push({ key: 'image', label: 'Diamond Image' });
		(diamond.extra_images || []).forEach(function (url, i) {
			// Distinct from the primary image — additional angles Nivoda
			// supplies for some stones (certificate.product_images).
			media.push({ key: 'image-' + i, label: 'Additional Image ' + (i + 1), url: url });
		});
		if (diamond.bowtie) media.push({ key: 'bowtie', label: 'Bowtie Effect' });
		if (cert.cert_pdf_url) media.push({ key: 'cert', label: 'Certificate' });

		var overlay = document.createElement('div');
		overlay.id = 'tjdb-modal-overlay';
		overlay.className = 'tjdb-modal-overlay';

		// loupe360's video URL bakes in a fixed pixel canvas
		// (".../video/500/500?..."). Shrinking the iframe's own box (via CSS
		// width/height) squeezes that page into a smaller viewport, which
		// makes IT show its own internal scrollbars — the page doesn't
		// reflow, it just doesn't fit anymore. Rendering it at its native
		// 500x500 size (so it never needs to scroll) and scaling the whole
		// iframe down visually with a CSS transform avoids that entirely.
		var VIDEO_NATIVE_SIZE = 500;

		// loupe360 serves the interactive 360° spin the same way as the fixed
		// video (an iframe at a native 500x500 canvas) — just a different
		// URL (certificate.product_videos[].loupe360_url, "type=360" vs the
		// regular video field's "type=api") — so it uses the same
		// scale-to-fit treatment as 'video' below.
		function mediaMarkup(key) {
			if (key === 'video' || key === 'spin') {
				var src = key === 'spin' ? diamond.spin_url : diamond.video;
				return (
					'<div class="tjdb-modal-video-scaler" style="width:' + VIDEO_NATIVE_SIZE + 'px;height:' + VIDEO_NATIVE_SIZE + 'px;">' +
					'<iframe class="tjdb-modal-media-frame" src="' + esc(src) + '" width="' + VIDEO_NATIVE_SIZE + '" height="' + VIDEO_NATIVE_SIZE + '" scrolling="no" allowfullscreen></iframe>' +
					'</div>'
				);
			}
			if (key === 'image') {
				return '<img class="tjdb-modal-image" src="' + esc(diamond.image) + '" alt="' + esc(cert.shape || 'diamond') + '">';
			}
			if (key === 'bowtie') {
				return '<img class="tjdb-modal-image" src="' + esc(diamond.bowtie) + '" alt="Bowtie effect">';
			}
			if (key.indexOf('image-') === 0) {
				var found = media.filter(function (m) { return m.key === key; })[0];
				return found ? '<img class="tjdb-modal-image" src="' + esc(found.url) + '" alt="' + esc(cert.shape || 'diamond') + '">' : '';
			}
			if (key === 'cert') {
				// The PDF itself can fail to render inline for the same
				// reason loupe360's embeds sometimes do (a third-party
				// document that may refuse framing, or serve as a download
				// instead) — the "open in a new tab" link is a safety net,
				// not a duplicate control.
				return (
					'<div class="tjdb-modal-cert-preview">' +
					'<iframe class="tjdb-modal-media-frame" src="' + esc(cert.cert_pdf_url) + '"></iframe>' +
					'<a class="tjdb-modal-cert-link" href="' + esc(cert.cert_pdf_url) + '" target="_blank" rel="noopener">Open Certificate PDF ↗</a>' +
					'</div>'
				);
			}
			return '<div class="tjdb-modal-media-empty">No media available for this diamond.</div>';
		}

		function fitVideoScaler(viewerEl) {
			var scaler = viewerEl.querySelector('.tjdb-modal-video-scaler');
			if (!scaler) return;
			var containerSize = Math.min(viewerEl.clientWidth, viewerEl.clientHeight);
			scaler.style.transform = 'scale(' + containerSize / VIDEO_NATIVE_SIZE + ')';
		}

		var currentKey = media.length ? media[0].key : null;

		var thumbsHtml = '';
		if (media.length > 1) {
			thumbsHtml = '<div class="tjdb-modal-thumbs">';
			media.forEach(function (m, i) {
				thumbsHtml += '<button type="button" class="tjdb-modal-thumb tjdb-modal-thumb-' + esc(m.key.replace(/[^a-z0-9]/gi, '-')) + (i === 0 ? ' active' : '') + '" data-thumb="' + esc(m.key) + '">';
				if (m.key === 'video') thumbsHtml += '<span class="tjdb-modal-thumb-play">&#9658;</span>';
				if (m.key === 'spin') thumbsHtml += '<span class="tjdb-modal-thumb-spin">360°</span>';
				if (m.key === 'cert') thumbsHtml += '<span class="tjdb-modal-thumb-cert">CERT</span>';
				if (m.key === 'image' && diamond.image) thumbsHtml += '<img src="' + esc(diamond.image) + '" alt="">';
				if (m.key === 'bowtie' && diamond.bowtie) thumbsHtml += '<img src="' + esc(diamond.bowtie) + '" alt="">';
				if (m.key.indexOf('image-') === 0) thumbsHtml += '<img src="' + esc(m.url) + '" alt="">';
				thumbsHtml += '</button>';
			});
			thumbsHtml += '</div>';
		}

		var compatible = isDiamondCompatibleWithSetting(diamond);

		var badges = [];
		if (diamond.availability !== 'AVAILABLE') badges.push('UNAVAILABLE');
		if (diamond.availability === 'AVAILABLE' && !compatible) badges.push('DOESN’T FIT THIS SETTING');
		if (diamond.supplier) badges.push(esc(diamond.supplier));

		var specHtml = '<div class="tjdb-modal-spec-grid">';
		diamondSpecPairs(diamond).forEach(function (row) {
			specHtml += '<div class="tjdb-modal-spec-item"><span>' + esc(row[0]) + '</span><strong>' + esc(row[1]) + '</strong></div>';
		});
		specHtml += '</div>';

		var html = '<div class="tjdb-modal tjdb-diamond-modal">';
		html += '<button type="button" class="tjdb-modal-close" id="tjdb-modal-close" aria-label="Close">&times;</button>';
		html += '<div class="tjdb-modal-media-col">';
		html += '<div class="tjdb-modal-viewer" id="tjdb-modal-viewer">' + mediaMarkup(currentKey) + '</div>';
		if (currentKey) {
			html += '<p class="tjdb-modal-media-caption" id="tjdb-modal-media-caption">' + esc((media[0] || {}).label || '') + '</p>';
		}
		html += thumbsHtml;
		html += '</div>';

		html += '<div class="tjdb-modal-info-col">';
		html += '<h3>' + diamond.certificate.carats.toFixed(2) + 'ct ' + esc(cert.shape) + ' Diamond</h3>';
		html += '<div class="tjdb-modal-price">' + formatPrice(diamond.price_cents) + '</div>';
		if (badges.length) {
			html += '<div class="tjdb-modal-badges">' + badges.map(function (b) { return '<span class="tjdb-modal-badge">' + b + '</span>'; }).join('') + '</div>';
		}
		html += specHtml;
		if (diamond.availability === 'AVAILABLE' && !compatible) {
			html += '<p class="tjdb-error">This diamond doesn’t fit the selected setting’s shape/carat range.</p>';
		}
		html += '<button type="button" class="tjdb-add-to-cart tjdb-modal-select" id="tjdb-modal-select"' + (compatible ? '' : ' disabled') + '>Select This Diamond</button>';
		html += '</div>';
		html += '</div>';

		overlay.innerHTML = html;
		document.body.appendChild(overlay);

		var viewerEl = document.getElementById('tjdb-modal-viewer');

		function onResize() {
			fitVideoScaler(viewerEl);
		}

		if (currentKey === 'video' || currentKey === 'spin') {
			fitVideoScaler(viewerEl);
		}
		window.addEventListener('resize', onResize);

		function closeAndCleanup() {
			window.removeEventListener('resize', onResize);
			closeModal();
		}

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) closeAndCleanup();
		});
		document.getElementById('tjdb-modal-close').addEventListener('click', closeAndCleanup);
		document.getElementById('tjdb-modal-select').addEventListener('click', function () {
			window.removeEventListener('resize', onResize);
			selectDiamondAndAdvance(diamond);
		});

		overlay.querySelectorAll('[data-thumb]').forEach(function (thumbButton) {
			thumbButton.addEventListener('click', function () {
				overlay.querySelectorAll('[data-thumb]').forEach(function (b) {
					b.classList.remove('active');
				});
				thumbButton.classList.add('active');
				var key = thumbButton.getAttribute('data-thumb');
				viewerEl.innerHTML = mediaMarkup(key);
				if (key === 'video' || key === 'spin') {
					fitVideoScaler(viewerEl);
				}
				var caption = document.getElementById('tjdb-modal-media-caption');
				if (caption) {
					var found = media.filter(function (m) { return m.key === key; })[0];
					caption.textContent = found ? found.label : '';
				}
			});
		});
	}

	function openCompareModal() {
		var diamonds = Object.keys(state.compareDiamonds).map(function (id) {
			return state.compareDiamonds[id];
		});
		if (!diamonds.length) return;

		var rows = [
			['Shape', function (d) { return d.certificate.shape; }],
			['Carat', function (d) { return d.certificate.carats.toFixed(2); }],
			['Color', function (d) { return d.certificate.color; }],
			['Clarity', function (d) { return d.certificate.clarity; }],
			['Cut', function (d) { return d.certificate.cut; }],
			['Price', function (d) { return formatPrice(d.price_cents); }],
		];

		var overlay = document.createElement('div');
		overlay.id = 'tjdb-modal-overlay';
		overlay.className = 'tjdb-modal-overlay';

		var html = '<div class="tjdb-modal tjdb-compare-modal">';
		html += '<button type="button" class="tjdb-modal-close" id="tjdb-modal-close" aria-label="Close">&times;</button>';
		html += '<h3>Compare Diamonds</h3>';
		html += '<table class="tjdb-compare-table"><tbody>';

		rows.forEach(function (row) {
			html += '<tr><th>' + esc(row[0]) + '</th>';
			diamonds.forEach(function (d) {
				html += '<td>' + esc(row[1](d)) + '</td>';
			});
			html += '</tr>';
		});

		// A diamond saved to Compare can outlive the setting it was
		// compatible with at the time (or was never compatible with the
		// current one at all) — re-checked here rather than trusting it's
		// still selectable just because it made it into this list.
		if (state.setting) {
			html += '<tr><th>Fits selected setting</th>';
			diamonds.forEach(function (d) {
				html += '<td>' + (isDiamondCompatibleWithSetting(d) ? 'Yes' : 'No') + '</td>';
			});
			html += '</tr>';
		}

		html += '<tr><th></th>';
		diamonds.forEach(function (d) {
			html += '<td><button type="button" class="tjdb-add-to-cart" data-select-compare="' + esc(d.diamond_id) + '"' + (isDiamondCompatibleWithSetting(d) ? '' : ' disabled') + '>Select</button></td>';
		});
		html += '</tr>';
		html += '</tbody></table></div>';

		overlay.innerHTML = html;
		document.body.appendChild(overlay);

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) closeModal();
		});
		document.getElementById('tjdb-modal-close').addEventListener('click', closeModal);

		overlay.querySelectorAll('[data-select-compare]').forEach(function (button) {
			button.addEventListener('click', function () {
				selectDiamondAndAdvance(state.compareDiamonds[button.getAttribute('data-select-compare')]);
			});
		});
	}

	function renderDiamondCard(diamond) {
		var disabled = diamond.availability !== 'AVAILABLE' ? ' disabled' : '';
		var html = '<button class="tjdb-card" data-diamond-id="' + esc(diamond.diamond_id) + '"' + disabled + '>';
		if (diamond.spin_url) {
			// The static image is what actually loads; the 360° spin only
			// swaps in on hover (see bindCardSpinHover()) rather than up
			// front — an interactive embed per card, times a whole grid of
			// them, would mean dozens of loupe360 iframes loading at once.
			html += '<div class="tjdb-card-media" data-spin-url="' + esc(diamond.spin_url) + '">';
			if (diamond.image) {
				html += '<img src="' + esc(diamond.image) + '" alt="' + esc(diamond.certificate.shape || 'diamond') + '">';
			}
			html += '<span class="tjdb-card-spin-badge">360°</span>';
			html += '</div>';
		} else if (diamond.image) {
			html += '<img src="' + esc(diamond.image) + '" alt="' + esc(diamond.certificate.shape || 'diamond') + '">';
		}
		html += '<div class="tjdb-card-name">' + diamond.certificate.carats.toFixed(2) + 'ct ' + esc(diamond.certificate.shape) + '</div>';
		html += '<div class="tjdb-card-spec">' + esc(diamond.certificate.color) + '/' + esc(diamond.certificate.clarity) + ', ' + esc(diamond.certificate.cut) + ' cut</div>';
		html += '<div class="tjdb-card-price">' + formatPrice(diamond.price_cents) + '</div>';
		html += '</button>';
		return html;
	}

	function renderDiamondListRow(diamond) {
		var disabled = diamond.availability !== 'AVAILABLE';
		var checked = state.compareDiamonds[diamond.diamond_id] ? ' checked' : '';
		var html = '<div class="tjdb-list-row' + (disabled ? ' is-disabled' : '') + '" data-diamond-id="' + esc(diamond.diamond_id) + '">';
		html += '<button type="button" class="tjdb-list-cell tjdb-list-cell-shape" data-open-modal="' + esc(diamond.diamond_id) + '"' + (disabled ? ' disabled' : '') + '>' + diamond.certificate.carats.toFixed(2) + 'ct ' + esc(diamond.certificate.shape) + '</button>';
		html += '<button type="button" class="tjdb-list-cell tjdb-list-cell-price" data-open-modal="' + esc(diamond.diamond_id) + '"' + (disabled ? ' disabled' : '') + '>' + formatPrice(diamond.price_cents) + '</button>';
		html += '<button type="button" class="tjdb-list-cell" data-open-modal="' + esc(diamond.diamond_id) + '"' + (disabled ? ' disabled' : '') + '>' + diamond.certificate.carats.toFixed(2) + '</button>';
		html += '<button type="button" class="tjdb-list-cell" data-open-modal="' + esc(diamond.diamond_id) + '"' + (disabled ? ' disabled' : '') + '>' + esc(diamond.certificate.cut) + '</button>';
		html += '<button type="button" class="tjdb-list-cell" data-open-modal="' + esc(diamond.diamond_id) + '"' + (disabled ? ' disabled' : '') + '>' + esc(diamond.certificate.color) + '</button>';
		html += '<button type="button" class="tjdb-list-cell" data-open-modal="' + esc(diamond.diamond_id) + '"' + (disabled ? ' disabled' : '') + '>' + esc(diamond.certificate.clarity) + '</button>';
		html += '<span class="tjdb-list-cell tjdb-list-cell-compare"><input type="checkbox" data-compare-id="' + esc(diamond.diamond_id) + '"' + checked + '></span>';
		html += '</div>';
		return html;
	}

	function runDiamondSearch(append) {
		var token = append ? state.searchToken : ++state.searchToken;
		var resultsEl = root.querySelector('#tjdb-diamond-results');

		if (!append) {
			searchResults = { items: [], total: 0, offset: 0 };
			if (resultsEl) {
				resultsEl.innerHTML = '<p class="tjdb-loading">Searching diamonds…</p>';
			}
		}

		var sortParts = state.sort.split(':');

		searchDiamonds({
			shapes: state.shape ? [state.shape] : availableShapes(),
			carat_from: state.caratFrom || undefined,
			carat_to: state.caratTo || undefined,
			color: state.color.length ? state.color : undefined,
			clarity: state.clarity.length ? state.clarity : undefined,
			cut: state.cut.length ? state.cut : undefined,
			sort_type: sortParts[0],
			sort_direction: sortParts[1],
			limit: 24,
			offset: searchResults.offset,
		})
			.then(function (res) {
				if (token !== state.searchToken || !resultsEl) return;

				searchResults.items = searchResults.items.concat(res.items);
				searchResults.total = res.total_count;
				searchResults.offset = searchResults.items.length;

				if (!searchResults.items.length) {
					resultsEl.innerHTML = '<p>No diamonds match these filters. Try widening your search.</p>';
					return;
				}

				var html = '<p class="tjdb-result-count">Showing ' + searchResults.items.length + ' of ' + searchResults.total + ' diamonds.</p>';
				if (state.viewMode === 'list') {
					html += '<div class="tjdb-list">';
					html += '<div class="tjdb-list-head">';
					LIST_COLUMNS.forEach(function (column) {
						var extraClass = column.key === 'shape' ? ' tjdb-list-cell-shape' : column.key === 'price' ? ' tjdb-list-cell-price' : column.key === 'compare' ? ' tjdb-list-cell-compare' : '';
						if (column.sortType) {
							html += '<button type="button" class="tjdb-list-cell tjdb-list-cell-sortable' + extraClass + '" data-sort-column="' + esc(column.sortType) + '">' + esc(column.label) + sortIndicator(column) + '</button>';
						} else {
							html += '<span class="tjdb-list-cell' + extraClass + '">' + esc(column.label) + '</span>';
						}
					});
					html += '</div>';
					searchResults.items.forEach(function (diamond) {
						html += renderDiamondListRow(diamond);
					});
					html += '</div>';
				} else {
					html += '<div class="tjdb-grid">';
					searchResults.items.forEach(function (diamond) {
						html += renderDiamondCard(diamond);
					});
					html += '</div>';
				}

				if (searchResults.items.length < searchResults.total) {
					html += '<button type="button" class="tjdb-back" id="tjdb-load-more-diamonds">Load more diamonds</button>';
				}

				resultsEl.innerHTML = html;

				var loadMoreBtn = root.querySelector('#tjdb-load-more-diamonds');
				if (loadMoreBtn) {
					loadMoreBtn.addEventListener('click', function () {
						runDiamondSearch(true);
					});
				}

				var diamondsById = {};
				searchResults.items.forEach(function (d) {
					diamondsById[d.diamond_id] = d;
				});

				resultsEl.querySelectorAll('[data-sort-column]').forEach(function (button) {
					button.addEventListener('click', function () {
						var column = button.getAttribute('data-sort-column');
						var parts = state.sort.split(':');
						var direction = parts[0] === column && parts[1] === 'ASC' ? 'DESC' : 'ASC';
						setSort(column, direction);
					});
				});

				resultsEl.querySelectorAll('[data-open-modal]').forEach(function (button) {
					button.addEventListener('click', function () {
						openDiamondModal(diamondsById[button.getAttribute('data-open-modal')]);
					});
				});

				resultsEl.querySelectorAll('[data-diamond-id].tjdb-card').forEach(function (card) {
					card.addEventListener('click', function () {
						openDiamondModal(diamondsById[card.getAttribute('data-diamond-id')]);
					});
				});

				bindCardSpinHover(resultsEl);

				resultsEl.querySelectorAll('[data-compare-id]').forEach(function (checkbox) {
					checkbox.addEventListener('click', function (e) {
						e.stopPropagation();
					});
					checkbox.addEventListener('change', function () {
						var id = checkbox.getAttribute('data-compare-id');
						if (checkbox.checked) {
							state.compareDiamonds[id] = diamondsById[id];
						} else {
							delete state.compareDiamonds[id];
						}
						var compareBtn = root.querySelector('#tjdb-open-compare');
						if (compareBtn) {
							compareBtn.textContent = 'Compare (' + compareCount() + ')';
							compareBtn.disabled = !compareCount();
						}
					});
				});
			})
			.catch(function (e) {
				if (token !== state.searchToken || !resultsEl) return;
				resultsEl.innerHTML = '<p class="tjdb-error">' + esc(e.message) + '</p>';
			});
	}

	/**
	 * Diamond-first flow only: once a diamond is chosen, browse settings
	 * that can actually accept it (shape allowed, carat in range).
	 */
	function renderSettingStep() {
		var html = '<div class="tjdb-step tjdb-step-settings">';
		if (state.diamond) {
			html += '<button class="tjdb-back" data-action="back-to-diamond">← Back</button>';
		}
		// No heading here on purpose — this step's card grid is meant to be
		// visually identical to the [tjdb_settings_archive] page, which has
		// none either (just its own page title above the stepper).
		html += '<div id="tjdb-settings-results"><p class="tjdb-loading">Loading settings…</p></div>';
		html += '</div>';

		root.innerHTML = renderStepper() + html;
		bindStepperEvents(root);

		var backBtn = root.querySelector('[data-action="back-to-diamond"]');
		if (backBtn) {
			backBtn.addEventListener('click', function () {
				state.step = 'diamond';
				render();
			});
		}

		var token = ++state.settingsSearchToken;
		var resultsEl = root.querySelector('#tjdb-settings-results');

		// Server-rendered with the theme's own product-card loop (same
		// markup as the [tjdb_settings_archive] page) so this step looks
		// like a plain archive page, not a hand-rolled JS grid. Only the
		// "Choose This Setting" button is intercepted (selects inline); the
		// rest of the card behaves like a normal archive card and navigates
		// to the product page.
		fetchCompatibleSettingsHtml(state.diamond)
			.then(function (res) {
				if (token !== state.settingsSearchToken || !resultsEl) return;

				resultsEl.innerHTML = res.html;

				// Carry the selected diamond onto every outgoing product-page
				// link (image/title), not just the "Choose This Setting"
				// button — so following one of those links (to look at the
				// setting properly) and then clicking "Choose This Setting"
				// from the product page itself restores this diamond instead
				// of losing it. get_builder_url() on the PHP side reads this
				// same param back out.
				if (state.diamond) {
					resultsEl.querySelectorAll('.product a[href]').forEach(function (link) {
						try {
							var url = new URL(link.href, window.location.href);
							url.searchParams.set('tjdb_diamond_id', state.diamond.diamond_id);
							link.href = url.href;
						} catch (err) {
							// Malformed href — leave it untouched.
						}
					});
				}

				resultsEl.addEventListener('click', function (e) {
					// Only the "Choose This Setting" button selects inline —
					// clicking anywhere else on the card (image, title) is a
					// real link to the product page and should navigate
					// there normally, same as on the plain archive page.
					var chooseBtn = e.target.closest('.tjdb-archive-build-button');
					if (!chooseBtn) return;

					var card = chooseBtn.closest('.product');
					if (!card) return;

					e.preventDefault();

					var match = card.className.match(/\bpost-(\d+)\b/);
					if (!match) return;

					var productId = parseInt(match[1], 10);
					resultsEl.innerHTML = '<p class="tjdb-loading">Loading…</p>';

					fetchSetting(productId)
						.then(function (setting) {
							selectSettingAndAdvance(setting);
						})
						.catch(function (err) {
							if (token !== state.settingsSearchToken || !resultsEl) return;
							resultsEl.innerHTML = '<p class="tjdb-error">' + esc(err.message) + '</p>';
						});
				});
			})
			.catch(function (e) {
				if (token !== state.settingsSearchToken || !resultsEl) return;
				resultsEl.innerHTML = '<p class="tjdb-error">' + esc(e.message) + '</p>';
			});
	}

	/**
	 * Final step — merges what used to be separate "ring size" and
	 * "review" steps: ring size (when the setting has variations) is
	 * picked inline, right above the total and Add to Cart.
	 */
	function renderCompleteStep() {
		var html = '<div class="tjdb-step tjdb-step-complete">';
		html += '<button class="tjdb-back" data-action="back">← Back</button>';
		html += '<h2>Complete your ring</h2>';
		html += '<div id="tjdb-complete-body"><p class="tjdb-loading">Verifying diamond…</p></div>';
		html += '</div>';

		root.innerHTML = renderStepper() + html;
		bindStepperEvents(root);

		root.querySelector('[data-action="back"]').addEventListener('click', function () {
			state.step = stepOrder()[1];
			render();
		});

		fetchDiamondDetail(state.diamond.diamond_id)
			.then(renderCompleteBody)
			.catch(function (e) {
				root.querySelector('#tjdb-complete-body').innerHTML = '<p class="tjdb-error">' + esc(e.message) + '</p>';
			});
	}

	function renderCompleteBody(diamond) {
		var body = root.querySelector('#tjdb-complete-body');

		// Kept on `state` (not a local var reset every render) so choosing
		// options, then navigating away via "Change" and coming back,
		// doesn't lose them — only picking a different setting resets it
		// (see selectSettingAndAdvance()).
		if (!state.selectedAttributes) {
			state.selectedAttributes = {};
			if (state.variation) {
				state.setting.variation_attributes.forEach(function (attr) {
					state.selectedAttributes[attr.attribute_key] = state.variation.attributes[attr.attribute_key];
				});
			}
		}
		var selectedAttrs = state.selectedAttributes;
		var priceChangedNotice = '';

		// A variation attribute value of '' is WooCommerce's "Any <attribute>"
		// wildcard — it matches whatever the customer picked for that slot,
		// not literally an empty selection. Treating it as an exact-match
		// requirement (the previous behaviour) meant those variations could
		// never actually be selected.
		function variationMatchesSelection(variation) {
			return Object.keys(selectedAttrs).every(function (key) {
				return variation.attributes[key] === '' || variation.attributes[key] === selectedAttrs[key];
			});
		}

		function attrKeys() {
			return state.setting.variation_attributes.map(function (a) { return a.attribute_key; });
		}

		function isComplete() {
			return attrKeys().every(function (k) { return selectedAttrs[k]; });
		}

		function findMatching() {
			if (!isComplete()) return null;
			return state.setting.variations.filter(variationMatchesSelection)[0] || null;
		}

		// An option is "impossible" if no in-stock, purchasable variation
		// matches it together with whatever's already chosen for the other
		// attributes — shown disabled instead of letting the customer pick
		// a combination that was never going to work.
		function optionIsPossible(attrKey, value) {
			var trial = {};
			Object.keys(selectedAttrs).forEach(function (k) { trial[k] = selectedAttrs[k]; });
			trial[attrKey] = value;

			return state.setting.variations.some(function (variation) {
				if (!variation.in_stock || variation.purchasable === false) return false;
				return Object.keys(trial).every(function (key) {
					return variation.attributes[key] === '' || variation.attributes[key] === trial[key];
				});
			});
		}

		function renderInner() {
			var matched = state.setting.has_variations ? findMatching() : null;
			var settingPriceKnown = state.setting.has_variations ? matched : { price_cents: state.setting.price_cents, in_stock: true };
			var canCheckout = diamond.availability === 'AVAILABLE' && !!settingPriceKnown && settingPriceKnown.in_stock;

			state.variation = matched;

			var html = '';

			if (diamond.availability !== 'AVAILABLE') {
				html += '<p class="tjdb-error">This diamond is no longer available. Please go back and choose another.</p>';
			}

			if (state.setting.has_variations) {
				state.setting.variation_attributes.forEach(function (attr) {
					html += '<div class="tjdb-variation-row">';
					html += '<label for="tjdb-attr-' + esc(attr.attribute_key) + '">' + esc(attr.label) + '</label>';
					html += '<select id="tjdb-attr-' + esc(attr.attribute_key) + '" data-attr-key="' + esc(attr.attribute_key) + '">';
					html += '<option value="">Select ' + esc(attr.label.toLowerCase()) + '</option>';
					attr.options.forEach(function (option) {
						var sel = selectedAttrs[attr.attribute_key] === option.value ? ' selected' : '';
						var disabled = optionIsPossible(attr.attribute_key, option.value) ? '' : ' disabled';
						html += '<option value="' + esc(option.value) + '"' + sel + disabled + '>' + esc(option.label) + '</option>';
					});
					html += '</select></div>';
				});

				if (isComplete() && !matched) {
					html += '<p class="tjdb-error">This combination isn\'t available. Please choose different options.</p>';
				} else if (matched && (!matched.in_stock || matched.purchasable === false)) {
					html += '<p class="tjdb-error">This combination is currently out of stock.</p>';
				} else if (!isComplete()) {
					var missing = state.setting.variation_attributes.filter(function (a) { return !selectedAttrs[a.attribute_key]; }).map(function (a) { return a.label; });
					html += '<p class="tjdb-hint">Select ' + esc(missing.join(', ')) + ' to see your total.</p>';
				}
			}

			if (priceChangedNotice) {
				html += '<p class="tjdb-error">' + esc(priceChangedNotice) + '</p>';
			}

			html += '<div class="tjdb-summary-row"><span>' + esc(state.setting.name) + '</span><span>' + (settingPriceKnown ? formatPrice(settingPriceKnown.price_cents) : '—') + '</span></div>';
			html += '<div class="tjdb-summary-row"><span>' + diamond.certificate.carats.toFixed(2) + 'ct ' + esc(diamond.certificate.shape) + ', ' + esc(diamond.certificate.color) + '/' + esc(diamond.certificate.clarity) + ', ' + esc(diamond.certificate.cut) + ' cut</span><span>' + formatPrice(diamond.price_cents) + '</span></div>';

			if (canCheckout) {
				html += '<div class="tjdb-summary-row tjdb-summary-total"><strong>Total</strong><strong>' + formatPrice(settingPriceKnown.price_cents + diamond.price_cents) + '</strong></div>';
			}

			html += '<button class="tjdb-add-to-cart" id="tjdb-add-to-cart-btn"' + (canCheckout ? '' : ' disabled') + '>Add to Cart</button>';
			html += '<p class="tjdb-error" id="tjdb-add-to-cart-error"></p>';

			body.innerHTML = html;

			body.querySelectorAll('[data-attr-key]').forEach(function (select) {
				select.addEventListener('change', function () {
					selectedAttrs[select.getAttribute('data-attr-key')] = select.value;
					renderInner();
				});
			});

			var addButton = document.getElementById('tjdb-add-to-cart-btn');
			if (addButton && canCheckout) {
				addButton.addEventListener('click', function () {
					addButton.disabled = true;
					addButton.textContent = 'Adding…';

					addBundleToCart({
						product_id: state.setting.id,
						diamond_id: diamond.diamond_id,
						variation_id: matched && state.setting.has_variations ? matched.variation_id : undefined,
						// What was actually shown on screen — if the server's
						// price no longer matches this, it rejects the add
						// (tjdb_price_changed) instead of silently charging
						// whatever it now computes.
						expected_total_cents: settingPriceKnown.price_cents + diamond.price_cents,
					})
						.then(function (result) {
							window.location.href = result.cart_url;
						})
						.catch(function (e) {
							addButton.disabled = false;
							addButton.textContent = 'Add to Cart';

							if (e.code === 'tjdb_price_changed' && e.data) {
								diamond.price_cents = e.data.diamond_price_cents;
								if (matched) {
									matched.price_cents = e.data.setting_price_cents;
								} else {
									state.setting.price_cents = e.data.setting_price_cents;
								}
								priceChangedNotice = e.message;
								renderInner();
								return;
							}

							document.getElementById('tjdb-add-to-cart-error').textContent = e.message;
						});
				});
			}
		}

		renderInner();
	}

	function render() {
		syncUrlState();

		if (state.step === 'diamond') {
			renderDiamondStep();
		} else if (state.step === 'setting') {
			renderSettingStep();
		} else if (state.step === 'complete') {
			renderCompleteStep();
		}
	}

	// Keeps the current selection in the URL (tjdb_setting_id, tjdb_diamond_id,
	// tjdb_step) so refreshing the page resumes where the user left off
	// instead of resetting the whole flow. Pushes a new history entry when
	// the *step* actually changes (so browser Back/Forward moves between
	// diamond/setting/complete, handled by the popstate listener below),
	// but replaces in place for updates within the same step so those don't
	// pile up as separate history entries.
	var lastPushedStep = null;
	var restoringFromHistory = false;

	function syncUrlState() {
		if (restoringFromHistory) {
			lastPushedStep = state.step;
			return;
		}

		var params = new URLSearchParams(window.location.search);

		if (!state.setting && !state.diamond) {
			params.delete('tjdb_setting_id');
			params.delete('tjdb_diamond_id');
			params.delete('tjdb_step');
		} else {
			if (state.setting) {
				params.set('tjdb_setting_id', state.setting.id);
			} else {
				params.delete('tjdb_setting_id');
			}

			if (state.diamond) {
				params.set('tjdb_diamond_id', state.diamond.diamond_id);
			} else {
				params.delete('tjdb_diamond_id');
			}

			params.set('tjdb_step', state.step);
		}

		var query = params.toString();
		var newUrl = window.location.pathname + (query ? '?' + query : '');

		if (newUrl !== window.location.pathname + window.location.search) {
			if (lastPushedStep !== null && state.step !== lastPushedStep) {
				window.history.pushState({ tjdbStep: state.step }, '', newUrl);
			} else {
				window.history.replaceState({ tjdbStep: state.step }, '', newUrl);
			}
		}

		lastPushedStep = state.step;
	}

	// Rebuilds state from the current URL (used both on first load and when
	// the user navigates with browser Back/Forward — see the popstate
	// listener below) so those buttons move between steps instead of
	// leaving the SPA or doing nothing.
	function restoreFromUrl() {
		var settingId = getSettingIdFromUrl();
		var diamondIdParam = new URLSearchParams(window.location.search).get('tjdb_diamond_id');
		var stepParam = new URLSearchParams(window.location.search).get('tjdb_step');

		state.startMode = settingId ? 'setting' : 'diamond';

		if (!settingId && !diamondIdParam) {
			state.setting = null;
			state.diamond = null;
			state.variation = null;
			state.step = 'diamond';
			render();
			restoringFromHistory = false;
			return;
		}

		root.innerHTML = '<p class="tjdb-loading">Loading…</p>';

		Promise.all([
			settingId ? fetchSetting(settingId) : Promise.resolve(null),
			diamondIdParam ? fetchDiamondDetail(diamondIdParam) : Promise.resolve(null),
		])
			.then(function (results) {
				state.setting = results[0];
				state.diamond = results[1];

				var validSteps = stepOrder();
				if (stepParam === 'complete' && state.setting && state.diamond) {
					state.step = 'complete';
				} else if (validSteps.indexOf(stepParam) !== -1) {
					state.step = stepParam;
				} else if (state.setting && state.diamond) {
					state.step = 'complete';
				} else if (state.setting) {
					state.step = nextStepAfter('setting');
				} else if (state.diamond) {
					state.step = nextStepAfter('diamond');
				} else {
					state.step = state.startMode;
				}

				render();
			})
			.catch(function (e) {
				root.innerHTML = '<p class="tjdb-error">' + esc(e.message) + '</p>';
			})
			.finally(function () {
				restoringFromHistory = false;
			});
	}

	window.addEventListener('popstate', function () {
		restoringFromHistory = true;
		restoreFromUrl();
	});

	// ---- Init ----
	//
	// Two entry points: arriving with ?tjdb_setting_id (from a product
	// page's "Choose This Setting") starts setting-first; arriving with no
	// params starts diamond-first — browse and pick a diamond, then a
	// compatible setting. The stepper order follows whichever came first.
	// A refresh mid-flow can carry both tjdb_setting_id and tjdb_diamond_id
	// (plus tjdb_step) — see syncUrlState() — so both are restored together.
	// Same logic services browser Back/Forward via restoreFromUrl() above.

	restoreFromUrl();
})();
