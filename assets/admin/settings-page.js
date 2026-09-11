(function () {
	'use strict';

	var ICONS = {
		check: '<svg class="tjdb-admin-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>',
		x: '<svg class="tjdb-admin-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>',
	};

	function setResult(result, icon, message) {
		result.innerHTML = ICONS[icon] || '';
		result.appendChild(document.createTextNode(' ' + message));
	}

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
						setResult(result, 'check', json.data.message);
						result.style.color = 'green';
					} else {
						setResult(result, 'x', json.data && json.data.message ? json.data.message : 'Connection failed.');
						result.style.color = '#b32d2e';
					}
				})
				.catch(function () {
					button.disabled = false;
					setResult(result, 'x', 'Request failed.');
					result.style.color = '#b32d2e';
				});
		});
	});
})();
