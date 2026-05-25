document.addEventListener('DOMContentLoaded', function () {
	document.querySelectorAll('[data-ic-quality-target]').forEach(function (input) {
		var outputId = input.getAttribute('data-ic-quality-target');
		var output = outputId ? document.getElementById(outputId) : null;

		if (!output) {
			return;
		}

		var syncValue = function () {
			output.textContent = input.value;
		};

		input.addEventListener('input', syncValue);
		syncValue();
	});

	var adminConfig = window.imageCompressorAdmin || {};

	var formatNumber = function (value) {
		return new Intl.NumberFormat().format(value);
	};

	var formatDecimal = function (value) {
		return new Intl.NumberFormat(undefined, {
			minimumFractionDigits: 1,
			maximumFractionDigits: 1
		}).format(value);
	};

	var formatDuration = function (seconds) {
		if (!seconds || seconds < 1) {
			return 'under a second';
		}

		var rounded = Math.round(seconds);
		var minutes = Math.floor(rounded / 60);
		var remainingSeconds = rounded % 60;

		if (minutes < 1) {
			return rounded + 's';
		}

		if (remainingSeconds === 0) {
			return minutes + 'm';
		}

		return minutes + 'm ' + remainingSeconds + 's';
	};

	var initBatchForm = function (mode, elements, stateFactory, requestBuilder, progressUpdater, completionMessageBuilder) {
		var config = adminConfig[mode];
		var form = document.querySelector('[data-ic-batch-form="' + mode + '"]');

		if (!config || !form || !elements.progressBar) {
			return;
		}

		var submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
		var isRunning = false;

		var setButtonState = function (label, disabled) {
			if (!submitButton) {
				return;
			}

			if ('value' in submitButton) {
				submitButton.value = label;
			}

			submitButton.textContent = label;
			submitButton.disabled = disabled;
		};

		var showNotice = function (message, isError) {
			if (!elements.notice) {
				return;
			}

			elements.notice.hidden = false;
			elements.notice.className = 'notice inline ic-live-notice ' + (isError ? 'notice-error' : 'notice-success');
			elements.notice.innerHTML = '<p>' + message + '</p>';
		};

		var runBatch = function (state) {
			var body = new URLSearchParams();
			body.set('action', config.action);
			body.set('nonce', config.nonce);
			requestBuilder(body, state);

			fetch(config.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: body.toString(),
				credentials: 'same-origin'
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (payload) {
					if (!payload || !payload.success || !payload.data) {
						throw new Error((payload && payload.data && payload.data.message) || config.strings.error);
					}

					progressUpdater(state, payload.data, config, elements);

					if (payload.data.done) {
						isRunning = false;
						setButtonState(config.strings.buttonDone, true);
						elements.progressBar.style.width = '100%';
						elements.progressPercent.textContent = '100%';
						elements.progressMeta.textContent = config.strings.finished;
						showNotice(completionMessageBuilder(state, config), false);
						return;
					}

					runBatch(state);
				})
				.catch(function (error) {
					isRunning = false;
					setButtonState(config.strings.buttonStart, false);
					showNotice(error.message || config.strings.error, true);
				});
		};

		form.addEventListener('submit', function (event) {
			if (isRunning) {
				event.preventDefault();
				return;
			}

			event.preventDefault();
			isRunning = true;

			if (elements.progressBlock) {
				elements.progressBlock.hidden = false;
			}

			if (elements.notice) {
				elements.notice.hidden = true;
			}

			setButtonState(config.strings.buttonRunning, true);
			elements.progressStatus.textContent = config.strings.starting;
			elements.progressMeta.textContent = config.strings.etaPending;

			runBatch(stateFactory(config));
		});
	};

	initBatchForm(
		'optimize',
		{
			progressBlock: document.getElementById('ic-progress-block'),
			progressBar: document.getElementById('ic-progress-bar'),
			progressPercent: document.getElementById('ic-progress-percent'),
			progressStatus: document.getElementById('ic-progress-status'),
			progressMeta: document.getElementById('ic-progress-meta'),
			notice: document.getElementById('ic-live-notice'),
			statValues: document.querySelectorAll('.ic-stat-value')
		},
		function (config) {
			return {
				optimized: 0,
				skipped: 0,
				errors: 0,
				savedBytes: 0,
				libraryDone: Number(config.counts.optimized || 0),
				libraryPending: Number(config.counts.pending || 0),
				startedAt: Date.now()
			};
		},
		function (body, state) {
			body.set('optimized', state.optimized);
			body.set('skipped', state.skipped);
			body.set('errors', state.errors);
			body.set('saved_bytes', state.savedBytes);
		},
		function (state, data, config, elements) {
			var total = Number(config.counts.total || 0);
			var statValues = elements.statValues || [];

			state.optimized = Number(data.optimized || 0);
			state.skipped = Number(data.skipped || 0);
			state.errors = Number(data.errors || 0);
			state.savedBytes = Number(data.saved_bytes || 0);
			state.libraryDone = Number(data.library_done || 0);
			state.libraryPending = Number(data.library_pending || 0);

			var processed = total - state.libraryPending;
			var percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 100;
			var elapsedSeconds = (Date.now() - state.startedAt) / 1000;
			var remaining = Math.max(0, state.libraryPending);
			var etaSeconds = processed > 0 ? (elapsedSeconds / processed) * remaining : 0;

			elements.progressBar.style.width = percent + '%';
			elements.progressPercent.textContent = percent + '%';
			elements.progressStatus.textContent = config.strings.inProgress
				.replace('%1$s', formatNumber(processed))
				.replace('%2$s', formatNumber(total));
			elements.progressMeta.textContent = processed > 0
				? config.strings.etaLabel.replace('%s', formatDuration(etaSeconds))
				: config.strings.etaPending;

			if (statValues.length >= 3) {
				statValues[1].textContent = formatNumber(state.libraryDone);
				statValues[2].textContent = formatNumber(state.libraryPending);
			}
		},
		function (state, config) {
			return config.strings.complete
				.replace('%1$s', '<strong>' + formatNumber(state.optimized) + '</strong>')
				.replace('%2$s', '<strong>' + formatDecimal(state.savedBytes / 1024) + '</strong>')
				.replace('%3$s', formatNumber(state.skipped))
				.replace('%4$s', formatNumber(state.errors));
		}
	);

	initBatchForm(
		'restore',
		{
			progressBlock: document.getElementById('ic-restore-progress-block'),
			progressBar: document.getElementById('ic-restore-progress-bar'),
			progressPercent: document.getElementById('ic-restore-progress-percent'),
			progressStatus: document.getElementById('ic-restore-progress-status'),
			progressMeta: document.getElementById('ic-restore-progress-meta'),
			notice: document.getElementById('ic-restore-live-notice')
		},
		function (config) {
			return {
				restored: 0,
				errors: 0,
				remaining: Number(config.counts.remaining || 0),
				startedAt: Date.now()
			};
		},
		function (body, state) {
			body.set('restored', state.restored);
			body.set('errors', state.errors);
		},
		function (state, data, config, elements) {
			var total = Number(config.counts.total || 0);

			state.restored = Number(data.restored || 0);
			state.errors = Number(data.errors || 0);
			state.remaining = Number(data.remaining || 0);

			var processed = total - state.remaining;
			var percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 100;
			var elapsedSeconds = (Date.now() - state.startedAt) / 1000;
			var remaining = Math.max(0, state.remaining);
			var etaSeconds = processed > 0 ? (elapsedSeconds / processed) * remaining : 0;

			elements.progressBar.style.width = percent + '%';
			elements.progressPercent.textContent = percent + '%';
			elements.progressStatus.textContent = config.strings.inProgress
				.replace('%1$s', formatNumber(processed))
				.replace('%2$s', formatNumber(total));
			elements.progressMeta.textContent = processed > 0
				? config.strings.etaLabel.replace('%s', formatDuration(etaSeconds))
				: config.strings.etaPending;
		},
		function (state, config) {
			return config.strings.complete
				.replace('%1$s', '<strong>' + formatNumber(state.restored) + '</strong>')
				.replace('%2$s', formatNumber(state.errors));
		}
	);
});
