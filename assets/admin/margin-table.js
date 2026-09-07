(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var table = document.getElementById('tjdb-margin-tiers-table');
		var addButton = document.getElementById('tjdb-add-tier');
		var template = document.getElementById('tjdb-tier-row-template');

		if (!table || !addButton || !template) {
			return;
		}

		addButton.addEventListener('click', function () {
			var row = template.content.firstElementChild.cloneNode(true);
			table.querySelector('tbody').appendChild(row);
		});

		table.addEventListener('click', function (event) {
			if (event.target.classList.contains('tjdb-remove-tier')) {
				event.target.closest('tr').remove();
			}
		});
	});
})();
