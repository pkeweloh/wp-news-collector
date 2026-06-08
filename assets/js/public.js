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
		page: NC_DATA.page || 1,
		hasNext: NC_DATA.hasNext || false,
		pageSize: NC_DATA.pageSize || 20,
		source: NC_DATA.source || '',

		init: function () {
			if (!this.hasNext) return;
			var sentinel = document.querySelector('.nc-feed-sentinel');
			if (!sentinel) return;
			var self = this;
			var observer = new IntersectionObserver(
				function (entries) { if (entries[0].isIntersecting) self.loadMore(); },
				{ rootMargin: '300px' }
			);
			observer.observe(sentinel);
			this._sentinel = sentinel;
			this._observer = observer;
		},

		loadMore: function () {
			if (this.loading || !this.hasNext) return;
			this.loading = true;
			this._setLoadingVisible(true);

			var self = this;
			// feedUrl may already carry a query string (plain permalinks yield
			// ".../?rest_route=/nc/v1/feed"), so pick the right separator.
			var sep = NC_DATA.feedUrl.indexOf('?') === -1 ? '?' : '&';
			var url = NC_DATA.feedUrl + sep
				+ 'page=' + encodeURIComponent(this.page + 1)
				+ '&page_size=' + encodeURIComponent(this.pageSize)
				+ (this.source ? '&source=' + encodeURIComponent(this.source) : '');

			fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
				.then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
				.then(function (data) {
					self.page = data.page;
					self.hasNext = data.has_next;
					if (data.items_html) {
						var feed = document.querySelector('.nc-feed');
						if (feed && self._sentinel) {
							var tmp = document.createElement('div');
							tmp.innerHTML = data.items_html;
							initAllInlineVideos(tmp);
							while (tmp.firstChild) feed.insertBefore(tmp.firstChild, self._sentinel);
						}
					}
					if (!self.hasNext && self._sentinel) {
						self._observer.disconnect();
						self._sentinel.parentNode.removeChild(self._sentinel);
					}
				})
				.catch(function () { /* silently stop on error */ self.hasNext = false; })
				.finally(function () {
					self.loading = false;
					self._setLoadingVisible(false);
				});
		},

		_setLoadingVisible: function (visible) {
			var el = document.querySelector('.nc-feed-loading');
			if (el) el.style.display = visible ? '' : 'none';
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
		initAllInlineVideos();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
