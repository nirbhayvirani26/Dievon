/* ============================================================
 *  Dievon – Compare up to 3 products
 *
 *  Selection lives in sessionStorage so it survives paging through the listing
 *  and the load-more fetch, but does not linger for a future visit.
 *
 *  The 3-product cap is enforced here AND in actions/compare_action.php — the
 *  browser copy is a courtesy, the server copy is the real limit.
 * ============================================================ */
(function () {
    'use strict';
    if (window.toggleCompare) { return; }

    var KEY = 'dievon_compare';
    var MAX = 3;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function load() {
        try { return JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { return []; }
    }
    function save(list) {
        try { sessionStorage.setItem(KEY, JSON.stringify(list)); } catch (e) {}
    }

    window.toggleCompare = function (input) {
        var list = load();
        var id = String(input.value);
        var name = input.dataset.name || 'Product';
        var idx = list.findIndex(function (x) { return String(x.id) === id; });

        if (input.checked) {
            if (idx === -1) {
                if (list.length >= MAX) {
                    input.checked = false;
                    (window.dievonAlert || alert)(
                        'You can compare up to ' + MAX + ' products at a time. Remove one first.',
                        { type: 'warning', title: 'Compare limit reached' }
                    );
                    return;
                }
                list.push({ id: id, name: name });
            }
        } else if (idx > -1) {
            list.splice(idx, 1);
        }
        save(list);
        renderBar();
    };

    window.removeFromCompare = function (id) {
        var list = load().filter(function (x) { return String(x.id) !== String(id); });
        save(list);
        // untick the matching card if it is on screen
        document.querySelectorAll('.compare-check').forEach(function (c) {
            if (String(c.value) === String(id)) { c.checked = false; }
        });
        renderBar();
        if (document.getElementById('compareOverlay').classList.contains('is-open')) {
            list.length ? openCompare() : closeCompare();
        }
    };

    window.clearCompare = function () {
        save([]);
        document.querySelectorAll('.compare-check').forEach(function (c) { c.checked = false; });
        renderBar();
        closeCompare();
    };

    function renderBar() {
        var bar = document.getElementById('compareBar');
        var items = document.getElementById('compareBarItems');
        var count = document.getElementById('compareCount');
        var openBtn = document.getElementById('compareOpenBtn');
        if (!bar || !items) { return; }

        var list = load();
        bar.classList.toggle('is-visible', list.length > 0);
        items.innerHTML = list.map(function (p) {
            return '<span class="cmp-chip">' + esc(p.name) +
                   '<button type="button" onclick="removeFromCompare(\'' + esc(p.id) + '\')" aria-label="Remove">&times;</button></span>';
        }).join('');
        if (count) { count.textContent = list.length + ' of ' + MAX + ' selected'; }
        // Comparing one product against nothing is not a comparison.
        if (openBtn) { openBtn.disabled = list.length < 2; }
    }

    // Re-tick boxes for anything already selected — needed after load-more injects
    // new cards, and after a page change within the same session.
    window.syncCompareChecks = function () {
        var ids = load().map(function (x) { return String(x.id); });
        document.querySelectorAll('.compare-check').forEach(function (c) {
            c.checked = ids.indexOf(String(c.value)) > -1;
        });
        renderBar();
    };

    window.closeCompare = function () {
        var el = document.getElementById('compareOverlay');
        if (el) { el.classList.remove('is-open'); document.body.classList.remove('cmp-open'); }
    };

    window.openCompare = function () {
        var list = load();
        if (list.length < 2) { return; }
        var el = document.getElementById('compareOverlay');
        var body = document.getElementById('compareBody');
        if (!el || !body) { return; }

        body.innerHTML = '<div class="cmp-loading">Loading…</div>';
        el.classList.add('is-open');
        document.body.classList.add('cmp-open');

        var ids = list.map(function (x) { return x.id; }).join(',');
        fetch(window.SITE_URL + '/actions/compare_action.php?ids=' + encodeURIComponent(ids))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.products.length) {
                    body.innerHTML = '<div class="cmp-loading">Could not load the comparison.</div>';
                    return;
                }
                body.innerHTML = renderTable(data.products);
            })
            .catch(function () {
                body.innerHTML = '<div class="cmp-loading">Network error. Please try again.</div>';
            });
    };

    function renderTable(products) {
        var rows = [
            ['Price',    function (p) { return '<span class="cmp-price">' + esc(p.price) + '</span>' +
                                                (p.mrp ? ' <s class="cmp-mrp">' + esc(p.mrp) + '</s>' +
                                                         ' <em class="cmp-off">' + p.discount + '% off</em>' : ''); }],
            ['Category', function (p) { return esc(p.category); }],
            ['Fabric',   function (p) { return esc(p.fabric); }],
            ['Colour',   function (p) { return esc(p.colour); }],
            ['Sizes',    function (p) { return esc(p.sizes); }],
            ['Sleeve',   function (p) { return esc(p.sleeve); }],
            ['Neck',     function (p) { return esc(p.neck); }],
            ['Pattern',  function (p) { return esc(p.pattern); }],
            ['Occasion', function (p) { return esc(p.occasion); }]
        ];

        // Highlight the cheapest so the table answers the obvious question at a glance.
        var cheapest = Math.min.apply(null, products.map(function (p) { return p.priceRaw; }));

        var head = '<tr><th class="cmp-rowlabel"></th>' + products.map(function (p) {
            return '<th>' +
                (p.image ? '<img src="' + esc(p.image) + '" alt="' + esc(p.name) + '" class="cmp-img">'
                         : '<div class="cmp-img-fallback">' + esc(p.emoji || '👗') + '</div>') +
                '<a href="' + esc(p.url) + '" class="cmp-name">' + esc(p.name) + '</a>' +
                (p.priceRaw === cheapest && products.length > 1 ? '<span class="cmp-best">Lowest price</span>' : '') +
                '<button type="button" class="cmp-remove" onclick="removeFromCompare(\'' + esc(p.id) + '\')">Remove</button>' +
                '</th>';
        }).join('') + '</tr>';

        var bodyRows = rows.map(function (r) {
            var cells = products.map(function (p) { return '<td>' + r[1](p) + '</td>'; }).join('');
            return '<tr><th class="cmp-rowlabel">' + r[0] + '</th>' + cells + '</tr>';
        }).join('');

        return '<div class="cmp-scroll"><table class="cmp-table"><thead>' + head +
               '</thead><tbody>' + bodyRows + '</tbody></table></div>';
    }

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeCompare(); } });
    document.addEventListener('DOMContentLoaded', window.syncCompareChecks);
})();
