/* ============================================================
 *  Dievon – Quick View
 *
 *  Opens a product in a modal on the current page. The buttons previously did
 *  window.location.href to the product page, which is not a "quick" view at all
 *  — it lost the shopper's scroll position and any filters they had applied.
 *
 *  Data comes from actions/quick_view_action.php. Add-to-bag reuses the existing
 *  cart flow so there is one code path for adding items, not two.
 * ============================================================ */
(function () {
    'use strict';
    if (window.openQuickView) { return; }

    var lastFocused = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function overlay() { return document.getElementById('quickViewOverlay'); }

    window.closeQuickView = function () {
        var el = overlay();
        if (!el) { return; }
        el.classList.remove('is-open');
        document.body.classList.remove('qv-open');
        if (lastFocused && lastFocused.focus) { try { lastFocused.focus(); } catch (e) {} }
    };

    window.openQuickView = function (productId) {
        var el = overlay();
        var body = document.getElementById('qvBody');
        if (!el || !body) { return; }

        lastFocused = document.activeElement;
        body.innerHTML = '<div class="qv-loading">Loading…</div>';
        el.classList.add('is-open');
        document.body.classList.add('qv-open');

        fetch(window.SITE_URL + '/actions/quick_view_action.php?id=' + encodeURIComponent(productId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    body.innerHTML = '<div class="qv-loading">' + esc(data.message || 'Could not load this product.') + '</div>';
                    return;
                }
                body.innerHTML = render(data.product);
            })
            .catch(function () {
                body.innerHTML = '<div class="qv-loading">Could not load this product. Please try again.</div>';
            });
    };

    function render(p) {
        var img = p.images && p.images.length
            ? '<img src="' + esc(p.images[0]) + '" alt="' + esc(p.name) + '" class="qv-img">'
            : '<div class="qv-img-fallback">' + esc(p.emoji || '👗') + '</div>';

        var thumbs = '';
        if (p.images && p.images.length > 1) {
            thumbs = '<div class="qv-thumbs">' + p.images.map(function (u, i) {
                return '<img src="' + esc(u) + '" alt="" class="qv-thumb' + (i === 0 ? ' is-active' : '') +
                       '" onclick="qvSwapImage(this, \'' + esc(u) + '\')">';
            }).join('') + '</div>';
        }

        var price = '<span class="qv-price">' + esc(p.price) + '</span>';
        if (p.mrp) {
            price += ' <span class="qv-mrp">' + esc(p.mrp) + '</span>' +
                     ' <span class="qv-off">' + p.discount + '% OFF</span>';
        }

        // Sizes are radio-style buttons; one must be chosen before adding to bag,
        // matching the rule on the full product page.
        var sizes = '';
        if (p.sizes && p.sizes.length) {
            sizes = '<div class="qv-sizes"><span class="qv-label">Select Size</span><div class="qv-size-row">' +
                p.sizes.map(function (s) {
                    var out = s.stock <= 0;
                    // data-variant-id is what cart_action.php actually keys on; data-size is
                    // the stored variant name ("Size: M") it falls back to. The caption shows
                    // the short form ("M") so a 46px chip is not asked to render "Size: M".
                    // data-price is the figure the cart will actually charge for
                    // THIS chip — a colourway can carry its own price_override,
                    // and the panel used to show only the product's base price,
                    // so the bag came back dearer than the panel said.
                    return '<button type="button" class="qv-size' + (out ? ' is-out' : '') + '"' +
                           (out ? ' disabled title="Out of stock"' : '') +
                           ' data-variant-id="' + esc(s.id || '') + '"' +
                           ' data-price="' + esc(s.price || '') + '"' +
                           ' data-size="' + esc(s.value || s.label) + '" onclick="qvPickSize(this)">' +
                           esc(s.label) + '</button>';
                }).join('') + '</div></div>';
        }

        var attrs = '';
        if (p.attributes && Object.keys(p.attributes).length) {
            attrs = '<dl class="qv-attrs">' + Object.keys(p.attributes).map(function (k) {
                return '<dt>' + esc(k) + '</dt><dd>' + esc(p.attributes[k]) + '</dd>';
            }).join('') + '</dl>';
        }

        return '' +
            '<div class="qv-media">' + img + thumbs + '</div>' +
            '<div class="qv-info">' +
                (p.badge ? '<span class="qv-badge">' + esc(p.badge) + '</span>' : '') +
                '<span class="qv-cat">' + esc(p.category || '') + '</span>' +
                '<h2 class="qv-title" id="qvTitle">' + esc(p.name) + '</h2>' +
                '<div class="qv-price-row">' + price + '</div>' +
                '<p class="qv-desc">' + esc(p.description || '') + '</p>' +
                sizes + attrs +
                '<div class="qv-actions">' +
                    '<button type="button" class="btn-luxury qv-add" onclick="qvAddToBag(' + p.id + ', this)">Add to Bag</button>' +
                    '<a href="' + esc(p.url) + '" class="btn-luxury-outline qv-full">Full Details</a>' +
                '</div>' +
            '</div>';
    }

    window.qvSwapImage = function (thumb, url) {
        var main = document.querySelector('.qv-img');
        if (main) { main.src = url; }
        document.querySelectorAll('.qv-thumb').forEach(function (t) { t.classList.remove('is-active'); });
        thumb.classList.add('is-active');
    };

    window.qvPickSize = function (btn) {
        document.querySelectorAll('.qv-size').forEach(function (b) { b.classList.remove('is-selected'); });
        btn.classList.add('is-selected');

        // Show what THIS chip costs. The panel opened on the product's base
        // price and never moved, so a colourway with its own price — or a dearer
        // size — was added to the bag at a figure the shopper had not been shown.
        var priceEl = document.querySelector('.qv-price');
        var chipPrice = btn.getAttribute('data-price');
        if (priceEl && chipPrice) { priceEl.textContent = chipPrice; }
    };

    window.qvAddToBag = function (productId, btn) {
        var sizeBtns = document.querySelectorAll('.qv-size');
        var chosen = document.querySelector('.qv-size.is-selected');
        if (sizeBtns.length && !chosen) {
            (window.dievonAlert || alert)('Please choose a size first.', { type: 'warning' });
            return;
        }
        var fd = new FormData();
        fd.append('action', 'add');
        fd.append('product_id', productId);
        fd.append('quantity', 1);
        // cart_action.php keys on variant_id, then falls back to size. It never reads
        // variant_name — that is a field it *returns*. Sending variant_name meant every
        // quick-view add came back "Please select a garment size" even with one selected.
        if (chosen) {
            if (chosen.dataset.variantId) { fd.append('variant_id', chosen.dataset.variantId); }
            if (chosen.dataset.size)      { fd.append('size', chosen.dataset.size); }
        }
        if (window.csrfToken) { fd.append('csrf_token', window.csrfToken); }

        btn.disabled = true;
        var original = btn.textContent;
        btn.textContent = 'Adding…';

        fetch(window.SITE_URL + '/actions/cart_action.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false; btn.textContent = original;
                if (data.success) {
                    closeQuickView();
                    // Assign the new state BEFORE re-rendering. renderCart() and
                    // updateBadge() both read the global cartState; without this they
                    // redrew from the state as it was BEFORE the add, so a quick-view
                    // add appeared to do nothing in the drawer or the badge.
                    if (typeof cartState !== 'undefined') { cartState = data; }
                    if (typeof renderCart === 'function') { renderCart(); }
                    if (typeof updateBadge === 'function') { updateBadge(); }
                    // The same callback every other cart mutation fires, so the cart
                    // page and its totals stay in step with the drawer.
                    if (typeof onCartUpdated === 'function') { onCartUpdated(data); }
                    if (typeof showToast === 'function') { showToast('Added to Bag!', data.message || ''); }
                    else { (window.dievonAlert || alert)('Added to bag.'); }
                } else {
                    (window.dievonAlert || alert)(data.message || 'Could not add this item.', { type: 'error' });
                }
            })
            .catch(function () {
                btn.disabled = false; btn.textContent = original;
                (window.dievonAlert || alert)('Network error. Please try again.', { type: 'error' });
            });
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeQuickView(); }
    });
})();
