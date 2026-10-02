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

	// Updates run one after another, each on the page the last one left.
	// A failed update leaves the page in an unknown state, so it reloads.
	let updates = Promise.resolve();
	const update = (task) => {
		updates = updates.then(task).catch(() => location.reload());
	};

	source.addEventListener('reload', () => location.reload());
	source.addEventListener('css', () => update(restyle));
	source.addEventListener('morph', (event) => update(() => morph(event.data === 'css')));

	function restyle() {
		for (const link of document.querySelectorAll('link[rel="stylesheet"][href]')) {
			swap(link);
		}
	}

	/**
	 * Morphs the page into a freshly rendered copy, which keeps the scroll
	 * position, focus, and form input. Pages that keep state a morph would
	 * break, like client-side rendered markup, opt out with
	 * `<meta name="celema-live-reload" content="reload">`. Without an HTML
	 * page of the same URL to morph into, the page reloads.
	 */
	async function morph(css) {
		if (document.querySelector('meta[name="celema-live-reload"][content="reload"]')) {
			location.reload();

			return;
		}

		try {
			const response = await fetch(location.href, { cache: 'no-store' });

			if (response.redirected || !response.headers.get('Content-Type')?.includes('text/html')) {
				throw new Error('No page to morph into');
			}

			const page = new DOMParser().parseFromString(await response.text(), 'text/html');

			if (scripts(page) !== scripts(document)) {
				location.reload();

				return;
			}

			const { Idiomorph } = await import(new URL('idiomorph.js', script.src).href);
			keepSwapped(page);
			await preload(page);

			// Idiomorph would add head scripts again whose markup differs,
			// like by a per-request nonce, and the browser would run them.
			for (const element of page.head.querySelectorAll('script')) {
				element.remove();
			}

			// Idiomorph takes a whole document only as markup.
			Idiomorph.morph(document.documentElement, page.documentElement.outerHTML, {
				head: { shouldPreserve: (element) => element.matches('script') },
				callbacks: { beforeAttributeUpdated: (name, element) => !(edits.has(name) && edited(element)) },
			});
		} catch {
			location.reload();

			return;
		}

		if (css) {
			restyle();
		}

		document.dispatchEvent(new CustomEvent('celema:morphed'));
	}

	/**
	 * The scripts of a page, without data blocks like JSON. Scripts that
	 * ran cannot be replaced, so a page whose scripts changed reloads.
	 */
	function scripts(page) {
		const signatures = [];

		for (const element of page.querySelectorAll('script')) {
			const type = element.type.trim().toLowerCase();

			if (!type || type === 'module' || type === 'importmap' || type.includes('javascript')) {
				const src = element.getAttribute('src');
				signatures.push(`${type} ${src === null ? element.text : new URL(src, location.href).href}`);
			}
		}

		return signatures.join('\n');
	}

	/**
	 * Adds the stylesheets the new page links before the morph, so new
	 * markup never shows unstyled, and waits until they loaded or failed.
	 * Idiomorph keeps them, as their markup matches the new page. Its own
	 * blocking would wait for the load event of every added element with
	 * a URL, which never comes for a failed stylesheet or a canonical link.
	 */
	function preload(page) {
		const present = new Set([...document.head.children].map((element) => element.outerHTML));
		const loads = [];

		for (const link of page.head.children) {
			if (!link.matches('link[rel="stylesheet"][href]') || present.has(link.outerHTML)) {
				continue;
			}

			const fresh = document.importNode(link);
			loads.push(
				new Promise((resolve) => {
					fresh.addEventListener('load', resolve);
					fresh.addEventListener('error', resolve);
				}),
			);
			document.head.append(fresh);
		}

		// A stalled request must not hold up live reload for good.
		return Promise.race([Promise.all(loads), new Promise((resolve) => setTimeout(resolve, 5000))]);
	}

	// Idiomorph resets form controls to the rendered markup. Controls the
	// user changed keep their state instead.
	const edits = new Set(['value', 'checked', 'selected']);

	function edited(element) {
		if (element instanceof HTMLInputElement) {
			return element.value !== element.defaultValue || element.checked !== element.defaultChecked;
		}

		if (element instanceof HTMLTextAreaElement) {
			return element.value !== element.defaultValue;
		}

		return element instanceof HTMLOptionElement && element.selected !== element.defaultSelected;
	}

	/**
	 * Idiomorph keeps head elements whose markup did not change and
	 * replaces the others. A swapped stylesheet differs from the rendered
	 * one only by its reload parameter, and the replacement would bring
	 * back the stale cached copy, so the new page links the swapped one.
	 */
	function keepSwapped(page) {
		const swapped = new Map();

		for (const link of document.querySelectorAll('link[rel="stylesheet"][href]')) {
			swapped.set(bare(link.href), link.getAttribute('href'));
		}

		for (const link of page.querySelectorAll('link[rel="stylesheet"][href]')) {
			const href = swapped.get(bare(link.getAttribute('href')));

			if (href) {
				link.setAttribute('href', href);
			}
		}
	}

	function bare(href) {
		const url = new URL(href, location.href);
		url.searchParams.delete('celema-reload');

		return url.href;
	}

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
