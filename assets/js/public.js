/**
 * Public-feed interactions for [news_feed] and /noticia/{id}.
 *
 * Two independent overlays:
 *  - MediaLightbox: click on a `.nc-media-cell` opens the media (image/video)
 *    in a fullscreen lightbox with keyboard + swipe navigation.
 *  - ItemModal: click on a `.nc-item` opens the full item content as a modal,
 *    pushes the canonical /noticia/{id} URL to history, and restores the
 *    previous URL on close. Mirrors the Instagram/Twitter pattern.
 *
 * Hydration data is provided by `window.NC_DATA` (see class-shortcode.php).
 */
(function () {
	'use strict';

	var NC_DATA = window.NC_DATA || { restUrl: '/wp-json/nc/v1/item/' };

	function el(tag, className, attrs) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (attrs) for (var k in attrs) if (Object.prototype.hasOwnProperty.call(attrs, k)) node.setAttribute(k, attrs[k]);
		return node;
	}

	function parseJSON(str) {
		if (!str) return null;
		try { return JSON.parse(str); } catch (e) { return null; }
	}

	// -------------------------------------------------------------------------
	// MediaLightbox — unchanged from the previous iteration.
	// -------------------------------------------------------------------------
	function MediaLightbox(items, startIndex) {
		this.items = items;
		this.index = Math.max(0, Math.min(startIndex || 0, items.length - 1));
		this.touchX = null;
		this.onKey = this.onKey.bind(this);
	}
	MediaLightbox.prototype.open = function () {
		this.backdrop = el('div', 'nc-lightbox', { role: 'dialog', 'aria-modal': 'true' });
		var close = el('button', 'nc-lightbox__close', { type: 'button', 'aria-label': 'Cerrar' });
		close.textContent = '✕';
		close.addEventListener('click', this.close.bind(this));
		this.backdrop.appendChild(close);

		this.prevBtn = el('button', 'nc-lightbox__arrow nc-lightbox__arrow--prev', { type: 'button', 'aria-label': 'Anterior' });
		this.prevBtn.textContent = '‹';
		this.prevBtn.addEventListener('click', this.stopAnd(this.prev.bind(this)));
		this.nextBtn = el('button', 'nc-lightbox__arrow nc-lightbox__arrow--next', { type: 'button', 'aria-label': 'Siguiente' });
		this.nextBtn.textContent = '›';
		this.nextBtn.addEventListener('click', this.stopAnd(this.next.bind(this)));
		this.backdrop.appendChild(this.prevBtn);
		this.backdrop.appendChild(this.nextBtn);

		this.mediaWrap = el('div', 'nc-lightbox__media');
		this.mediaWrap.addEventListener('click', function (e) { e.stopPropagation(); });
		this.backdrop.appendChild(this.mediaWrap);

		if (this.items.length > 1) {
			this.dotsWrap = el('div', 'nc-lightbox__dots');
			this.dotsWrap.addEventListener('click', function (e) { e.stopPropagation(); });
			for (var i = 0; i < this.items.length; i++) {
				(function (idx, self) {
					var dot = el('button', 'nc-lightbox__dot', { type: 'button', 'aria-label': 'Elemento ' + (idx + 1) });
					dot.addEventListener('click', function () { self.setIndex(idx); });
					self.dotsWrap.appendChild(dot);
				})(i, this);
			}
			this.backdrop.appendChild(this.dotsWrap);
		}

		this.backdrop.addEventListener('click', this.close.bind(this));
		this.backdrop.addEventListener('touchstart', this.onTouchStart.bind(this), { passive: true });
		this.backdrop.addEventListener('touchend', this.onTouchEnd.bind(this));

		document.body.appendChild(this.backdrop);
		ScrollLock.acquire();
		document.addEventListener('keydown', this.onKey);
		this.render();
	};
	MediaLightbox.prototype.stopAnd = function (fn) { return function (e) { e.stopPropagation(); fn(); }; };
	MediaLightbox.prototype.close = function () {
		if (!this.backdrop) return;
		document.removeEventListener('keydown', this.onKey);
		if (this.backdrop.parentNode) this.backdrop.parentNode.removeChild(this.backdrop);
		this.backdrop = null;
		ScrollLock.release();
	};
	MediaLightbox.prototype.prev = function () { this.setIndex(this.index - 1); };
	MediaLightbox.prototype.next = function () { this.setIndex(this.index + 1); };
	MediaLightbox.prototype.setIndex = function (i) {
		if (i < 0 || i >= this.items.length || i === this.index) return;
		this.index = i;
		this.render();
	};
	MediaLightbox.prototype.render = function () {
		var current = this.items[this.index];
		while (this.mediaWrap.firstChild) this.mediaWrap.removeChild(this.mediaWrap.firstChild);
		if (current.kind === 'video') {
			var v = el('video', 'nc-lightbox__video');
			v.src = current.src;
			if (current.poster) v.setAttribute('poster', current.poster);
			v.setAttribute('controls', '');
			v.setAttribute('autoplay', '');
			v.setAttribute('playsinline', '');
			this.mediaWrap.appendChild(v);
		} else {
			var img = el('img', 'nc-lightbox__img');
			img.src = current.src;
			img.alt = '';
			img.draggable = false;
			this.mediaWrap.appendChild(img);
		}
		if (this.prevBtn) this.prevBtn.style.visibility = this.index > 0 ? 'visible' : 'hidden';
		if (this.nextBtn) this.nextBtn.style.visibility = this.index < this.items.length - 1 ? 'visible' : 'hidden';
		if (this.dotsWrap) {
			var dots = this.dotsWrap.children;
			for (var i = 0; i < dots.length; i++) dots[i].classList.toggle('nc-lightbox__dot--active', i === this.index);
		}
	};
	MediaLightbox.prototype.onKey = function (e) {
		if (e.key === 'Escape') {
			// Stop other keydown handlers (e.g. the modal) from also firing.
			e.stopImmediatePropagation();
			this.close();
		} else if (e.key === 'ArrowLeft') {
			this.prev();
		} else if (e.key === 'ArrowRight') {
			this.next();
		}
	};
	MediaLightbox.prototype.onTouchStart = function (e) { this.touchX = e.touches[0].clientX; };
	MediaLightbox.prototype.onTouchEnd = function (e) {
		if (this.touchX === null) return;
		var dx = e.changedTouches[0].clientX - this.touchX;
		this.touchX = null;
		if (Math.abs(dx) > 50) { if (dx > 0) this.prev(); else this.next(); }
	};

	// -------------------------------------------------------------------------
	// VideoManager — ensures only one inline video plays at a time.
	// Mirrors alerta-boe/lib/videoManager.ts
	// -------------------------------------------------------------------------
	var VideoManager = {
		current: null,
		claim: function (el) {
			if (this.current && this.current !== el) {
				this.current.pause();
			}
			this.current = el;
		},
		release: function (el) {
			if (this.current === el) this.current = null;
		},
		pauseAll: function () {
			if (this.current) {
				this.current.pause();
				this.current = null;
			}
		}
	};

	// -------------------------------------------------------------------------
	// ScrollLock — refcounted so multiple overlays don't fight over body overflow.
	// -------------------------------------------------------------------------
	var ScrollLock = {
		count: 0,
		prev: '',
		acquire: function () {
			if (this.count === 0) {
				this.prev = document.body.style.overflow;
				document.body.style.overflow = 'hidden';
			}
			this.count++;
		},
		release: function () {
			this.count = Math.max(0, this.count - 1);
			if (this.count === 0) document.body.style.overflow = this.prev || '';
		}
	};

	// -------------------------------------------------------------------------
	// ItemModal — full-item content with History API integration.
	// -------------------------------------------------------------------------
	var currentItemModal = null;

	function ItemModal(itemId, permalink, opts) {
		this.itemId = itemId;
		this.permalink = permalink;
		this.opts = opts || {};
		this.onKey = this.onKey.bind(this);
		this.prevTitle = document.title;
	}
	ItemModal.prototype.open = function () {
		this.backdrop = el('div', 'nc-item-modal', { role: 'dialog', 'aria-modal': 'true' });
		var close = el('button', 'nc-item-modal__close', { type: 'button', 'aria-label': 'Cerrar' });
		close.textContent = '✕';
		close.addEventListener('click', this.requestClose.bind(this));
		this.backdrop.appendChild(close);

		this.content = el('div', 'nc-item-modal__content');
		this.content.innerHTML = '<div class="nc-item-modal__loading">' + escapeText(this.opts.loadingLabel || 'Cargando…') + '</div>';
		this.backdrop.appendChild(this.content);

		// Close only when clicking the backdrop itself, not its children.
		// (Removing stopPropagation on content so lightbox triggers inside the modal work.)
		var self = this;
		this.backdrop.addEventListener('click', function (e) {
			if (e.target === self.backdrop) self.requestClose();
		});
		document.body.appendChild(this.backdrop);
		ScrollLock.acquire();
		document.addEventListener('keydown', this.onKey);
		VideoManager.pauseAll();

		currentItemModal = this;
		this.fetchAndRender();
	};
	ItemModal.prototype.fetchAndRender = function () {
		var self = this;
		var url = NC_DATA.restUrl + encodeURIComponent(this.itemId);
		fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function (data) {
				if (!self.backdrop) return; // already closed
				self.content.innerHTML = data.html || '';
				if (data.title) {
					document.title = data.title + ' — ' + self.prevTitle.split(' — ').slice(-1)[0];
				}
			})
			.catch(function () {
				if (!self.backdrop) return;
				self.content.innerHTML = '<div class="nc-item-modal__error">' + escapeText(self.opts.errorLabel || 'No se pudo cargar la noticia.') + '</div>';
			});
	};
	ItemModal.prototype.requestClose = function () {
		// Closing should pop history so the URL reverts to the previous page.
		if (this.opts.pushed) {
			history.back();
		} else {
			this.close();
		}
	};
	ItemModal.prototype.close = function () {
		if (!this.backdrop) return;
		document.removeEventListener('keydown', this.onKey);
		if (this.backdrop.parentNode) this.backdrop.parentNode.removeChild(this.backdrop);
		this.backdrop = null;
		ScrollLock.release();
		document.title = this.prevTitle;
		if (currentItemModal === this) currentItemModal = null;
	};
	ItemModal.prototype.onKey = function (e) {
		if (e.key === 'Escape') this.requestClose();
	};

	function escapeText(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	// -------------------------------------------------------------------------
	// Click delegation
	// -------------------------------------------------------------------------
	function findAncestor(node, predicate) {
		while (node && node !== document.body) {
			if (predicate(node)) return node;
			node = node.parentNode;
		}
		return null;
	}

	function onDocumentClick(e) {
		// 1) Media cell — opens the lightbox UNLESS the grid is marked
		//    data-nc-disable-lightbox (used by list cards, where the whole
		//    card opens the news modal instead).
		var mediaTrigger = findAncestor(e.target, function (n) {
			return n.hasAttribute && n.hasAttribute('data-nc-media-index');
		});
		if (mediaTrigger) {
			var grid = mediaTrigger.closest('[data-nc-media]');
			if (grid && !grid.hasAttribute('data-nc-disable-lightbox')) {
				var items = parseJSON(grid.getAttribute('data-nc-media')) || [];
				if (items.length > 0) {
					e.preventDefault();
					var index = parseInt(mediaTrigger.getAttribute('data-nc-media-index'), 10) || 0;
					new MediaLightbox(items, index).open();
					return;
				}
			}
			// Otherwise fall through to the card-click handler below.
		}

		// 2) Item card → item modal (with pushState). Skip when a real
		//    anchor was clicked (article, inline link, permalink with
		//    modifier keys) so the browser navigates normally.
		var card = findAncestor(e.target, function (n) {
			return n.hasAttribute && n.hasAttribute('data-nc-item-id') && n.hasAttribute('data-nc-permalink');
		});
		if (!card) return;

		var anchor = findAncestor(e.target, function (n) {
			return n.nodeName === 'A' && n.hasAttribute('href');
		});
		// Modifier keys / non-primary button → let the browser handle.
		var modified = (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey);
		if (anchor) {
			if (anchor.classList.contains('nc-item-permalink')) {
				// Plain click on the permalink icon → open modal; modified click
				// (Ctrl/Meta/middle) → let the browser open a new tab.
				if (modified) return;
				e.preventDefault();
				openItemModalWithHistory(card.getAttribute('data-nc-item-id'), card.getAttribute('data-nc-permalink'));
				return;
			}
			// Any other anchor (article card, inline text link, "Ver en YouTube") → let it navigate.
			return;
		}
		if (modified) return;

		e.preventDefault();
		openItemModalWithHistory(card.getAttribute('data-nc-item-id'), card.getAttribute('data-nc-permalink'));
	}

	function openItemModalWithHistory(itemId, permalink) {
		if (!itemId || !permalink) return;
		// Push state so Back closes the modal.
		try {
			history.pushState({ ncItem: String(itemId) }, '', permalink);
		} catch (e) { /* same-origin / non-supported environments */ }
		var modal = new ItemModal(itemId, permalink, { pushed: true });
		modal.open();
	}

	function onPopState(e) {
		var state = e.state || {};
		if (currentItemModal) {
			// Going back from an item URL → close the modal.
			if (!state.ncItem || String(state.ncItem) !== String(currentItemModal.itemId)) {
				currentItemModal.opts.pushed = false; // already popped
				currentItemModal.close();
			}
		} else if (state.ncItem) {
			// Going forward to a state we pushed earlier → reopen the modal.
			var modal = new ItemModal(state.ncItem, location.href, { pushed: true });
			modal.open();
		}
	}

	// -------------------------------------------------------------------------
	// InfiniteScroll — IntersectionObserver on .nc-feed-sentinel.
	// -------------------------------------------------------------------------
	var FeedPager = {
		loading: false,
		// wp_localize_script serializes everything as strings, so coerce the
		// numerics — otherwise "1" + 1 === "11" and we'd request a bogus page.
		page: parseInt(NC_DATA.page, 10) || 1,
		hasNext: !!NC_DATA.hasNext,
		pageSize: parseInt(NC_DATA.pageSize, 10) || 20,
		source: NC_DATA.source || '',
		// The shortcode's own source restriction; clearing all filters returns
		// here (usually '' = all sources).
		baseSource: NC_DATA.source || '',
		_pendingSource: null,

		init: function () {
			this._feed = document.querySelector('.nc-feed');
			if (this._feed && this.hasNext) this._arm();
		},

		// (Re)bind the IntersectionObserver + "Load more" button to whatever
		// sentinel/button currently live in the DOM. Safe to call after a rebuild.
		_arm: function () {
			var self = this;

			// Fallback "Load more" button: works even if IntersectionObserver
			// never fires (e.g. feed inside a scrollable container).
			this._button = document.querySelector('.nc-feed-load-more');
			if (this._button) {
				this._button.addEventListener('click', function () { self.loadMore(); });
			}

			// Insertion target is the sentinel; keep a reference regardless of
			// whether the observer is available (the button path needs it too).
			this._sentinel = document.querySelector('.nc-feed-sentinel');
			if (this._sentinel && 'IntersectionObserver' in window) {
				var observer = new IntersectionObserver(
					function (entries) { if (entries[0].isIntersecting) self.loadMore(); },
					{ rootMargin: '300px' }
				);
				observer.observe(this._sentinel);
				this._observer = observer;
			}
		},

		// feedUrl may already carry a query string (plain permalinks yield
		// ".../?rest_route=/nc/v1/feed"), so pick the right separator.
		_fetch: function (page) {
			var sep = NC_DATA.feedUrl.indexOf('?') === -1 ? '?' : '&';
			var url = NC_DATA.feedUrl + sep
				+ 'page=' + encodeURIComponent(page)
				+ '&page_size=' + encodeURIComponent(this.pageSize)
				+ (this.source ? '&source=' + encodeURIComponent(this.source) : '');
			return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
				.then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
		},

		loadMore: function () {
			if (this.loading || !this.hasNext) return;
			this.loading = true;
			this._setLoadingVisible(true);

			var self = this;
			this._fetch(this.page + 1)
				.then(function (data) {
					self.page = data.page;
					self.hasNext = data.has_next;
					if (data.items_html) {
						var feed = self._feed || document.querySelector('.nc-feed');
						if (feed) {
							var tmp = document.createElement('div');
							tmp.innerHTML = data.items_html;
							initAllInlineVideos(tmp);
							// Insert before the sentinel when present, else append.
							while (tmp.firstChild) {
								feed.insertBefore(tmp.firstChild, self._sentinel || null);
							}
						}
					}
					if (!self.hasNext) {
						self._teardown();
					} else if (self._observer && self._sentinel) {
						// Re-arm the observer in case the sentinel is still in view
						// (no fresh intersection callback fires otherwise).
						self._observer.unobserve(self._sentinel);
						self._observer.observe(self._sentinel);
					}
				})
				.catch(function () { /* silently stop on error */ self.hasNext = false; })
				.finally(function () {
					self.loading = false;
					self._setLoadingVisible(false);
					self._drainPending();
				});
		},

		// Replace the feed with a fresh page 1 for the given source handles
		// (multi-select, comma-joined). Empty list falls back to baseSource.
		// Rapid toggles while a request is in flight are coalesced.
		applyFilter: function (sources) {
			var feed = this._feed || document.querySelector('.nc-feed');
			if (!feed) return;
			this._feed = feed;
			this._pendingSource = (sources && sources.length) ? sources.join(',') : this.baseSource;
			if (this.loading) return; // picked up by _drainPending()
			this._runFilter();
		},

		_drainPending: function () {
			if (this._pendingSource !== null && this._pendingSource !== this.source) {
				this._runFilter();
			}
		},

		_runFilter: function () {
			var feed = this._feed;
			if (!feed) return;
			this.source = this._pendingSource;
			this.loading = true;
			this._detach();
			feed.setAttribute('aria-busy', 'true');

			var self = this;
			this._fetch(1)
				.then(function (data) {
					self.page = data.page || 1;
					self.hasNext = !!data.has_next;
					self._clear(feed);
					if (data.items_html) {
						var tmp = document.createElement('div');
						tmp.innerHTML = data.items_html;
						initAllInlineVideos(tmp);
						while (tmp.firstChild) feed.appendChild(tmp.firstChild);
						if (self.hasNext) {
							self._appendTrailing(feed);
							self._arm();
						}
					} else {
						self.hasNext = false;
						feed.appendChild(self._emptyState());
					}
				})
				.catch(function () { self.hasNext = false; })
				.finally(function () {
					self.loading = false;
					feed.removeAttribute('aria-busy');
					self._drainPending();
				});
		},

		// Drop observer/button references ahead of a full rebuild (the nodes
		// themselves are removed by _clear()).
		_detach: function () {
			if (this._observer) { this._observer.disconnect(); this._observer = null; }
			this._button = null;
			this._sentinel = null;
		},

		_clear: function (feed) {
			var nodes = feed.querySelectorAll('.nc-item, .nc-feed-empty, .nc-feed-sentinel, .nc-feed-loading, .nc-feed-load-more');
			for (var i = 0; i < nodes.length; i++) {
				if (nodes[i].parentNode) nodes[i].parentNode.removeChild(nodes[i]);
			}
		},

		_appendTrailing: function (feed) {
			var i18n = NC_DATA.i18n || {};
			var sentinel = el('div', 'nc-feed-sentinel', { 'aria-hidden': 'true' });
			var loading = el('p', 'nc-feed-loading');
			loading.style.display = 'none';
			loading.textContent = i18n.loading || 'Loading…';
			var button = el('button', 'nc-feed-load-more', { type: 'button' });
			button.textContent = i18n.loadMore || 'Load more';
			feed.appendChild(sentinel);
			feed.appendChild(loading);
			feed.appendChild(button);
		},

		_emptyState: function () {
			var i18n = NC_DATA.i18n || {};
			var article = el('article', 'nc-feed-empty');
			var h2 = document.createElement('h2');
			h2.textContent = i18n.noNews || 'No news';
			var p = document.createElement('p');
			p.textContent = i18n.noNewsBody || '';
			article.appendChild(h2);
			article.appendChild(p);
			return article;
		},

		_teardown: function () {
			if (this._observer) this._observer.disconnect();
			if (this._sentinel && this._sentinel.parentNode) {
				this._sentinel.parentNode.removeChild(this._sentinel);
			}
			if (this._button && this._button.parentNode) {
				this._button.parentNode.removeChild(this._button);
			}
		},

		_setLoadingVisible: function (visible) {
			var loadingEl = document.querySelector('.nc-feed-loading');
			if (loadingEl) loadingEl.style.display = visible ? '' : 'none';
			if (this._button) {
				this._button.disabled = visible;
				this._button.style.display = visible ? 'none' : '';
			}
		}
	};

	// -------------------------------------------------------------------------
	// SourceFilter — upgrades the [news_sources] panel into a multi-select feed
	// filter. Progressive enhancement: only activates when a .nc-feed exists on
	// the page; otherwise the rows stay inert text references.
	// -------------------------------------------------------------------------
	var SourceFilter = {
		active: {},

		init: function () {
			if (!document.querySelector('.nc-feed')) return;
			var list = document.querySelector('.nc-feed-source-list[data-nc-filterable]');
			if (!list) return;
			var rows = list.querySelectorAll('.nc-feed-source-item[data-nc-source]');
			if (!rows.length) return;

			list.classList.add('is-interactive');
			var self = this;
			this._rows = rows;

			Array.prototype.forEach.call(rows, function (row) {
				row.setAttribute('role', 'button');
				row.setAttribute('tabindex', '0');
				row.setAttribute('aria-pressed', 'false');
				row.addEventListener('click', function (e) {
					// Let the Telegram link work without toggling the filter.
					if (e.target.closest && e.target.closest('.nc-feed-source-tg')) return;
					self.toggle(row);
				});
				row.addEventListener('keydown', function (e) {
					if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
						e.preventDefault();
						self.toggle(row);
					}
				});
			});

			this._clearBtn = document.querySelector('.nc-feed-source-clear');
			if (this._clearBtn) {
				this._clearBtn.addEventListener('click', function () { self.clear(); });
			}
		},

		toggle: function (row) {
			var handle = row.getAttribute('data-nc-source');
			if (!handle) return;
			if (this.active[handle]) {
				delete this.active[handle];
				row.setAttribute('aria-pressed', 'false');
				row.classList.remove('is-active');
			} else {
				this.active[handle] = true;
				row.setAttribute('aria-pressed', 'true');
				row.classList.add('is-active');
			}
			this._apply();
		},

		clear: function () {
			this.active = {};
			Array.prototype.forEach.call(this._rows || [], function (row) {
				row.setAttribute('aria-pressed', 'false');
				row.classList.remove('is-active');
			});
			this._apply();
		},

		_apply: function () {
			var handles = Object.keys(this.active);
			if (this._clearBtn) this._clearBtn.hidden = handles.length === 0;
			FeedPager.applyFilter(handles);
		}
	};

	// -------------------------------------------------------------------------
	// Inline video autoplay — IntersectionObserver, plays when ≥50% visible.
	// -------------------------------------------------------------------------
	function initInlineVideo(video) {
		var observer = new IntersectionObserver(
			function (entries) {
				if (entries[0].isIntersecting) {
					VideoManager.claim(video);
					video.play().catch(function () {});
				} else {
					video.pause();
					VideoManager.release(video);
				}
			},
			{ threshold: 0.5 }
		);
		observer.observe(video);
	}

	function initAllInlineVideos(root) {
		var videos = (root || document).querySelectorAll('[data-nc-inline-video]');
		for (var i = 0; i < videos.length; i++) {
			initInlineVideo(videos[i]);
		}
	}

	function init() {
		document.addEventListener('click', onDocumentClick);
		window.addEventListener('popstate', onPopState);
		FeedPager.init();
		SourceFilter.init();
		initAllInlineVideos();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
