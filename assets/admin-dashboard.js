(function () {
	'use strict';

	function makeChart(canvasId, type, chartData, extraOptions) {
		var el = document.getElementById(canvasId);
		if (!el || !chartData) {
			return;
		}
		if (typeof Chart === 'undefined') {
			if (!el.dataset.vcChartNotice) {
				el.dataset.vcChartNotice = '1';
				el.insertAdjacentHTML(
					'afterend',
					'<p class="description">Chart library is unavailable in this environment.</p>'
				);
			}
			return;
		}
		var opts = {
			responsive: true,
			maintainAspectRatio: false,
			plugins: {
				legend: { display: type === 'pie' },
			},
		};
		if (extraOptions && typeof extraOptions === 'object') {
			Object.keys(extraOptions).forEach(function (k) {
				opts[k] = extraOptions[k];
			});
		}
		// eslint-disable-next-line no-new
		new Chart(el, { type: type, data: chartData, options: opts });
	}

	document.addEventListener('DOMContentLoaded', function () {
		if (typeof viewerCounterDash === 'undefined') {
			return;
		}
		var d = viewerCounterDash;

		makeChart('vc-chart-daily', 'line', d.daily, {
			scales: {
				y: { beginAtZero: true, ticks: { precision: 0 } },
				x: { ticks: { maxRotation: 45, minRotation: 0 } },
			},
		});

		makeChart('vc-chart-weekly', 'bar', d.weekly, {
			scales: {
				y: { beginAtZero: true, ticks: { precision: 0 } },
				x: { ticks: { maxRotation: 45, minRotation: 0 } },
			},
		});

		makeChart('vc-chart-monthly', 'bar', d.monthly, {
			scales: {
				y: { beginAtZero: true, ticks: { precision: 0 } },
				x: { ticks: { maxRotation: 45, minRotation: 0 } },
			},
		});
	});
})();
