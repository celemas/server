// Served by the dev server's live reload endpoint, see ReloadResponse.php.
(() => {
	const script = document.currentScript;

	if (!script || window.celemaLiveReload) {
		return;
	}

	window.celemaLiveReload = true;
	const source = new EventSource(new URL('events', script.src));
	let opened = false;
	let lost = false;

	source.addEventListener('open', () => {
		// The dev server restarted, the served code may have changed.
		if (lost) {
			location.reload();
		}

		opened = true;
	});
	source.addEventListener('error', () => {
		lost = opened;
	});
	source.addEventListener('reload', () => location.reload());
	source.addEventListener('css', () => {
		for (const link of document.querySelectorAll('link[rel="stylesheet"][href]')) {
			// Swaps in a fresh copy and removes the old one once the new
			// one has loaded, which avoids a flash of unstyled content.
			const url = new URL(link.href);
			url.searchParams.set('celema-reload', Date.now().toString());
			const fresh = link.cloneNode();
			fresh.href = url.href;
			fresh.addEventListener('load', () => link.remove());
			fresh.addEventListener('error', () => fresh.remove());
			link.after(fresh);
		}
	});
})();
