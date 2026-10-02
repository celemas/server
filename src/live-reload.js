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
			swap(link);
		}
	});

	/**
	 * Swaps in a fresh copy of the stylesheet and removes the old one once
	 * the new one has loaded, which avoids a flash of unstyled content.
	 *
	 * The query parameter only reaches the linked file. Its imports keep
	 * their URLs, and the browser takes them from its cache, for good when
	 * they were served as immutable. Fetching them anew first replaces the
	 * cached copies.
	 */
	async function swap(link) {
		await Promise.allSettled(imports(link.sheet).map((url) => fetch(url, { cache: 'reload' })));
		const url = new URL(link.href);
		url.searchParams.set('celema-reload', Date.now().toString());
		const fresh = link.cloneNode();
		fresh.href = url.href;
		fresh.addEventListener('load', () => link.remove());
		fresh.addEventListener('error', () => fresh.remove());
		link.after(fresh);
	}

	/** The URLs of all stylesheets the sheet imports, directly or nested. */
	function imports(sheet, urls = []) {
		let rules = [];

		try {
			rules = sheet?.cssRules ?? [];
		} catch {
			// Cross-origin stylesheets hide their rules.
		}

		for (const rule of rules) {
			if (rule instanceof CSSImportRule) {
				urls.push(new URL(rule.href, sheet.href ?? location.href).href);
				imports(rule.styleSheet, urls);
			}
		}

		return urls;
	}
})();
