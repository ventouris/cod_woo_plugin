/**
 * Delivery refusal dashboard charts: dependency-free SVG columns and line,
 * redrawn on resize, with hover/focus tooltips. Every value is also in the data table.
 */
(function () {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';
	var PLOT_HEIGHT = 200;
	var AXIS_BAND = 28;
	var LEFT = 40;
	var TOP = 18;
	var RIGHT = 12;
	var i18n = window.drpmDashboard || { refused: 'Refused', completed: 'Completed', rate: 'Refusal rate' };

	function el(name, attrs, parent) {
		var node = document.createElementNS(NS, name);
		Object.keys(attrs || {}).forEach(function (key) {
			node.setAttribute(key, attrs[key]);
		});
		if (parent) {
			parent.appendChild(node);
		}
		return node;
	}

	/**
	 * Clean axis: about 4 ticks on a 1/2/5 step; whole-number steps for counts.
	 */
	function niceScale(value, integer) {
		if (value <= 0) {
			return { max: integer ? 4 : 1, step: integer ? 1 : 0.25 };
		}
		var raw = value / 4;
		var exp = Math.pow(10, Math.floor(Math.log10(raw)));
		var step = [1, 2, 5, 10].map(function (m) { return m * exp; }).filter(function (s) { return s >= raw; })[0];
		if (integer) {
			step = Math.max(1, Math.ceil(step));
		}
		return { max: Math.ceil(value / step) * step, step: step };
	}

	function compact(n) {
		if (n >= 1000) {
			return (n / 1000).toFixed(n >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'K';
		}
		return String(Math.round(n * 10) / 10);
	}

	function draw(container) {
		var type = container.getAttribute('data-chart');
		var points = JSON.parse(container.getAttribute('data-points') || '[]');
		var key = type === 'line' ? 'rate' : 'refused';
		var suffix = type === 'line' ? '%' : '';
		var width = container.clientWidth;
		if (!points.length || width < 50) {
			return;
		}

		container.innerHTML = '';
		var plotW = width - LEFT - RIGHT;
		var svg = el('svg', {
			width: width,
			height: TOP + PLOT_HEIGHT + AXIS_BAND,
			viewBox: '0 0 ' + width + ' ' + (TOP + PLOT_HEIGHT + AXIS_BAND),
			'aria-hidden': 'true'
		}, container);

		var val = function (p) { return p[key] === null ? 0 : p[key]; };
		var scale = niceScale(Math.max.apply(null, points.map(val)), type !== 'line');
		var max = Math.min(scale.max, type === 'line' ? 100 : Infinity);
		var y = function (v) { return TOP + PLOT_HEIGHT - (v / max) * PLOT_HEIGHT; };
		var band = plotW / points.length;
		var cx = function (i) { return LEFT + band * i + band / 2; };

		// Recessive solid hairline grid + y ticks.
		for (var t = 0; t * scale.step <= max + 1e-9; t++) {
			var value = scale.step * t;
			var gy = Math.round(y(value)) + 0.5;
			el('line', { x1: LEFT, x2: width - RIGHT, y1: gy, y2: gy, class: t === 0 ? 'drpm-axis' : 'drpm-gridline' }, svg);
			var tick = el('text', { x: LEFT - 8, y: gy + 4, class: 'drpm-tick', 'text-anchor': 'end' }, svg);
			tick.textContent = compact(value) + suffix;
		}

		// X labels, thinned so they never collide.
		var every = Math.max(1, Math.ceil(points.length / Math.max(1, Math.floor(plotW / 64))));
		points.forEach(function (p, i) {
			if (i % every === 0) {
				var lbl = el('text', { x: cx(i), y: TOP + PLOT_HEIGHT + 18, class: 'drpm-tick', 'text-anchor': 'middle' }, svg);
				lbl.textContent = p.label;
			}
		});

		var marks = el('g', {}, svg);
		var hover = el('g', { class: 'drpm-hover' }, svg);
		var maxIndex = 0;
		points.forEach(function (p, i) {
			if (val(p) > val(points[maxIndex])) {
				maxIndex = i;
			}
		});

		if (type === 'columns') {
			var barW = Math.max(2, Math.min(24, band * 0.6));
			var r = Math.min(4, barW / 2);
			points.forEach(function (p, i) {
				if (p.refused <= 0) {
					return;
				}
				var x = cx(i) - barW / 2;
				var top = y(p.refused);
				var base = TOP + PLOT_HEIGHT;
				var h = base - top;
				var rr = Math.min(r, h);
				// Rounded data-end, square at the baseline.
				el('path', {
					class: 'drpm-mark',
					d: 'M' + x + ',' + base + 'V' + (top + rr) + 'Q' + x + ',' + top + ' ' + (x + rr) + ',' + top +
						'H' + (x + barW - rr) + 'Q' + (x + barW) + ',' + top + ' ' + (x + barW) + ',' + (top + rr) + 'V' + base + 'Z'
				}, marks);
			});
		} else {
			// Periods without delivery outcomes have no rate: break the line there.
			var segments = [];
			var current = [];
			points.forEach(function (p, i) {
				if (p.rate === null) {
					if (current.length) {
						segments.push(current);
					}
					current = [];
				} else {
					current.push(i);
				}
			});
			if (current.length) {
				segments.push(current);
			}
			var base = TOP + PLOT_HEIGHT;
			segments.forEach(function (seg) {
				var d = seg.map(function (i, n) { return (n ? 'L' : 'M') + cx(i) + ',' + y(points[i].rate); }).join('');
				el('path', { class: 'drpm-area', d: d + 'L' + cx(seg[seg.length - 1]) + ',' + base + 'L' + cx(seg[0]) + ',' + base + 'Z' }, marks);
				el('path', { class: 'drpm-line', d: d }, marks);
				if (seg.length === 1) {
					el('circle', { class: 'drpm-dot', cx: cx(seg[0]), cy: y(points[seg[0]].rate), r: 3 }, marks);
				}
			});
			if (segments.length) {
				var lastSeg = segments[segments.length - 1];
				var last = lastSeg[lastSeg.length - 1];
				el('circle', { class: 'drpm-dot', cx: cx(last), cy: y(points[last].rate), r: 4 }, marks);
			}
		}

		// Label only the extreme value.
		if (val(points[maxIndex]) > 0) {
			var peak = el('text', {
				x: cx(maxIndex),
				y: y(val(points[maxIndex])) - 7,
				class: 'drpm-peak',
				'text-anchor': maxIndex === 0 ? 'start' : (maxIndex === points.length - 1 ? 'end' : 'middle')
			}, svg);
			peak.textContent = compact(points[maxIndex][key]) + suffix;
		}

		// Tooltip + hit targets larger than the marks (the whole band).
		var tip = document.createElement('div');
		tip.className = 'drpm-tooltip';
		tip.setAttribute('role', 'status');
		container.appendChild(tip);

		var guide = el('line', { class: 'drpm-crosshair', y1: TOP, y2: TOP + PLOT_HEIGHT }, hover);
		var ring = el('circle', { class: 'drpm-dot', r: 4 }, hover);

		function show(i) {
			var p = points[i];
			hover.style.display = 'block';
			guide.setAttribute('x1', cx(i));
			guide.setAttribute('x2', cx(i));
			if (type === 'line' && p.rate !== null) {
				ring.setAttribute('cx', cx(i));
				ring.setAttribute('cy', y(p.rate));
				ring.style.display = '';
				guide.style.display = '';
			} else {
				ring.style.display = 'none';
				guide.style.display = type === 'line' ? '' : 'none';
			}
			tip.innerHTML = '';
			var title = document.createElement('strong');
			title.textContent = p.label;
			tip.appendChild(title);
			[
				[i18n.refused, p.refused],
				[i18n.completed, p.delivered],
				[i18n.rate, p.rate === null ? '—' : p.rate + '%']
			].forEach(function (row) {
				var line = document.createElement('span');
				line.textContent = row[0] + ': ' + row[1];
				tip.appendChild(line);
			});
			tip.style.display = 'block';
			var left = cx(i) + 12;
			if (left + tip.offsetWidth > width) {
				left = cx(i) - tip.offsetWidth - 12;
			}
			tip.style.left = Math.max(0, left) + 'px';
			tip.style.top = TOP + 'px';
		}

		function hide() {
			hover.style.display = 'none';
			tip.style.display = 'none';
		}

		points.forEach(function (p, i) {
			var hit = el('rect', {
				class: 'drpm-hit',
				x: LEFT + band * i,
				y: TOP,
				width: band,
				height: PLOT_HEIGHT,
				tabindex: 0,
				'aria-label': p.label + ': ' + i18n.refused + ' ' + p.refused + ', ' + i18n.rate + ' ' + (p.rate === null ? '—' : p.rate + '%')
			}, svg);
			hit.addEventListener('focus', function () { show(i); });
			hit.addEventListener('blur', hide);
		});

		// Pointer: snap to the nearest period anywhere over the plot.
		var active = -1;
		svg.addEventListener('mousemove', function (event) {
			var box = svg.getBoundingClientRect();
			var x = event.clientX - box.left;
			var yPos = event.clientY - box.top;
			if (x < LEFT || x > width - RIGHT || yPos < TOP || yPos > TOP + PLOT_HEIGHT) {
				active = -1;
				hide();
				return;
			}
			var i = Math.min(points.length - 1, Math.max(0, Math.floor((x - LEFT) / band)));
			if (i !== active) {
				active = i;
				show(i);
			}
		});
		svg.addEventListener('mouseleave', function () {
			active = -1;
			hide();
		});
		hide();
	}

	function init() {
		var charts = document.querySelectorAll('.drpm-chart');
		charts.forEach(draw);
		if ('ResizeObserver' in window) {
			var lastWidths = new WeakMap();
			var observer = new ResizeObserver(function (entries) {
				entries.forEach(function (entry) {
					var w = Math.round(entry.contentRect.width);
					if (lastWidths.get(entry.target) !== w) {
						lastWidths.set(entry.target, w);
						draw(entry.target);
					}
				});
			});
			charts.forEach(function (chart) { observer.observe(chart); });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
