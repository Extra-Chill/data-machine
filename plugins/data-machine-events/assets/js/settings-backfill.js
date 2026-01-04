/**
 * Venue Timezone Backfill UI
 *
 * Handles the backfill UI on the Events Settings page.
 * Fetches stats on load and processes venues in batches via REST API.
 *
 * @package DataMachineEvents
 */

(function() {
	'use strict';

	const BATCH_SIZE = 10;

	const elements = {
		statsContainer: null,
		backfillButton: null,
		progressContainer: null,
		resultsContainer: null,
	};

	const state = {
		isRunning: false,
		totals: {
			processed: 0,
			geocoded: 0,
			timezones_derived: 0,
			errors: 0,
		},
	};

	function init() {
		elements.statsContainer = document.getElementById('dm-backfill-stats');
		elements.backfillButton = document.getElementById('dm-backfill-button');
		elements.progressContainer = document.getElementById('dm-backfill-progress');
		elements.resultsContainer = document.getElementById('dm-backfill-results');

		if (!elements.statsContainer) {
			return;
		}

		loadStats();

		if (elements.backfillButton) {
			elements.backfillButton.addEventListener('click', startBackfill);
		}
	}

	async function loadStats() {
		elements.statsContainer.innerHTML = 'Loading venue statistics...';

		try {
			const response = await wp.apiFetch({
				path: '/' + dmEventsSettings.restNamespace + '/events/venues/backfill-stats',
				method: 'GET',
			});

			if (response.success) {
				renderStats(response.data);
			} else {
				elements.statsContainer.innerHTML = 'Failed to load statistics.';
			}
		} catch (error) {
			elements.statsContainer.innerHTML = 'Error: ' + error.message;
		}
	}

	function renderStats(data) {
		const stats = data.stats;
		const isConfigured = data.is_configured;

		let html = '<table class="widefat striped" style="max-width: 400px;">';
		html += '<tbody>';
		html += '<tr><td>Total venues</td><td><strong>' + stats.total + '</strong></td></tr>';
		html += '<tr><td>With coordinates</td><td>' + stats.with_coordinates + '</td></tr>';
		html += '<tr><td>With timezone</td><td>' + stats.with_timezone + '</td></tr>';
		html += '<tr><td>Needs timezone</td><td><strong>' + stats.needs_timezone + '</strong></td></tr>';
		html += '</tbody></table>';

		elements.statsContainer.innerHTML = html;

		if (elements.backfillButton) {
			if (!isConfigured) {
				elements.backfillButton.disabled = true;
				elements.backfillButton.title = 'Configure GeoNames username first';
			} else if (stats.needs_timezone === 0) {
				elements.backfillButton.disabled = true;
				elements.backfillButton.title = 'All venues already have timezones';
			} else {
				elements.backfillButton.disabled = false;
				elements.backfillButton.title = '';
			}
		}
	}

	async function startBackfill() {
		if (state.isRunning) {
			return;
		}

		state.isRunning = true;
		state.totals = { processed: 0, geocoded: 0, timezones_derived: 0, errors: 0 };

		elements.backfillButton.disabled = true;
		elements.backfillButton.textContent = 'Processing...';
		elements.progressContainer.style.display = 'block';
		elements.resultsContainer.innerHTML = '';

		await processNextBatch(0);
	}

	async function processNextBatch(offset) {
		updateProgress('Processing venues ' + (offset + 1) + '...');

		try {
			const response = await wp.apiFetch({
				path: '/' + dmEventsSettings.restNamespace + '/events/venues/backfill-timezones',
				method: 'POST',
				data: {
					batch_size: BATCH_SIZE,
					offset: offset,
				},
			});

			if (response.success) {
				const data = response.data;

				state.totals.processed += data.processed;
				state.totals.geocoded += data.geocoded;
				state.totals.timezones_derived += data.timezones_derived;
				state.totals.errors += data.errors;

				if (data.has_more) {
					await processNextBatch(data.next_offset);
				} else {
					finishBackfill();
				}
			} else {
				showError('Backfill failed');
				resetUI();
			}
		} catch (error) {
			showError('Error: ' + error.message);
			resetUI();
		}
	}

	function updateProgress(message) {
		elements.progressContainer.innerHTML = '<span class="spinner is-active" style="float: none; margin: 0 8px 0 0;"></span>' + message;
	}

	function finishBackfill() {
		state.isRunning = false;
		elements.progressContainer.style.display = 'none';

		let html = '<div class="notice notice-success inline" style="margin: 10px 0;">';
		html += '<p><strong>Backfill complete!</strong></p>';
		html += '<ul style="margin: 5px 0 5px 20px; list-style: disc;">';
		html += '<li>Venues processed: ' + state.totals.processed + '</li>';
		html += '<li>Coordinates geocoded: ' + state.totals.geocoded + '</li>';
		html += '<li>Timezones derived: ' + state.totals.timezones_derived + '</li>';
		if (state.totals.errors > 0) {
			html += '<li>Errors: ' + state.totals.errors + '</li>';
		}
		html += '</ul></div>';

		elements.resultsContainer.innerHTML = html;

		loadStats();

		elements.backfillButton.textContent = 'Backfill Venue Timezones';
	}

	function showError(message) {
		elements.resultsContainer.innerHTML = '<div class="notice notice-error inline" style="margin: 10px 0;"><p>' + message + '</p></div>';
	}

	function resetUI() {
		state.isRunning = false;
		elements.progressContainer.style.display = 'none';
		elements.backfillButton.disabled = false;
		elements.backfillButton.textContent = 'Backfill Venue Timezones';
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
