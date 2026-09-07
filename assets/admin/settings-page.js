(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var button = document.getElementById('tjdb-test-connection');
		var result = document.getElementById('tjdb-test-connection-result');

		if (!button || !result || typeof tjdbSettings === 'undefined') {
			return;
		}

		button.addEventListener('click', function () {
			result.textContent = 'Testing...';
			button.disabled = true;

			var body = new URLSearchParams();
			body.set('action', 'tjdb_test_nivoda_connection');
			body.set('nonce', tjdbSettings.nonce);

			fetch(tjdbSettings.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			})
				.then(function (response) { return response.json(); })
				.then(function (json) {
					button.disabled = false;
					if (json.success) {
						result.textContent = '✓ ' + json.data.message;
						result.style.color = 'green';
					} else {
						result.textContent = '✗ ' + (json.data && json.data.message ? json.data.message : 'Connection failed.');
						result.style.color = '#b32d2e';
					}
				})
				.catch(function () {
					button.disabled = false;
					result.textContent = '✗ Request failed.';
					result.style.color = '#b32d2e';
				});
		});
	});
})();
