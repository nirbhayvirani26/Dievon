// ── Orders ──────────────────────────────────────────────────
function toggleDetail(id) {
    const row  = document.getElementById('detail-' + id);
    const icon = document.getElementById('icon-' + id);
    const isOpen = row.style.display === 'table-row';
    row.style.display  = isOpen ? 'none' : 'table-row';
    icon.style.transform = isOpen ? 'rotate(0)' : 'rotate(180deg)';
    icon.style.transition = 'transform 0.3s ease';
}

function updateStatus(orderId, orderCode, confirmReopen) {
    const status = document.getElementById('status-' + orderId).value;
    const msgEl  = document.getElementById('status-msg-' + orderId);

    // Courier and AWB travel with the status change.
    //
    // update_order.php already read tracking_number and carrier from the POST —
    // and, when they were absent, fell back to whatever was already stored, so
    // nothing is wiped by saving a status change with the boxes untouched. The
    // fields simply never existed in the page to send.
    const carrierEl  = document.getElementById('carrier-' + orderId);
    const trackingEl = document.getElementById('tracking-' + orderId);
    let extra = '';
    if (carrierEl)  { extra += '&carrier=' + encodeURIComponent(carrierEl.value.trim()); }
    if (trackingEl) { extra += '&tracking_number=' + encodeURIComponent(trackingEl.value.trim()); }

    fetch('update_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        // csrf_token travels with every mutating POST. update_order.php checks it
        // on delete, refund and remittance; status and payment changes were the two
        // that did not, so a page the owner visited while signed in could move an
        // order through the shop silently.
        body: 'order_id=' + orderId + '&status=' + encodeURIComponent(status) + extra
            + (confirmReopen ? '&confirm_reopen=1' : '')
            + '&csrf_token=' + encodeURIComponent(window.ADMIN_CSRF_TOKEN || ''),
    })
    .then(r => r.json())
    .then(data => {
        /* Reopening an order the GST report has written off.
           The server refuses once and explains what it would cost; saying yes
           re-sends the same change carrying the confirmation. Asked here rather
           than before the request so the browser never has to work out which
           statuses matter — the server owns that rule. */
        if (data.needs_confirm && data.confirm_action === 'reopen') {
            msgEl.textContent = '';
            const ask = (typeof window.dievonConfirm === 'function')
                ? window.dievonConfirm(data.message, { type: 'warning', confirmText: 'Change it anyway', cancelText: 'Leave as is' })
                : Promise.resolve(window.confirm(data.message));
            ask.then(function (ok) {
                if (ok) { updateStatus(orderId, orderCode, true); }
                else {
                    msgEl.textContent = 'Left unchanged.';
                    setTimeout(() => msgEl.textContent = '', 3000);
                }
            });
            return;
        }
        if (data.success) {
            msgEl.textContent = '✅ Saved!';
            const badgeMap = { 'Pending':'status-pending','Processing':'status-processing','Delivered':'status-delivered','Cancelled':'status-cancelled' };
            const mainRow = document.getElementById('row-' + orderId);
            const badge = mainRow.querySelector('.status-badge');
            if (badge) { badge.textContent = status; badge.className = 'status-badge ' + (badgeMap[status] || ''); }
            setTimeout(() => msgEl.textContent = '', 3000);
        } else {
            msgEl.textContent = '❌ Failed to save.'; msgEl.style.color = 'var(--color-danger)';
        }
    })
    .catch(() => { msgEl.textContent = '❌ Network error.'; msgEl.style.color = 'var(--color-danger)'; });
}

function updatePaymentStatus(orderId) {
    const ps    = document.getElementById('pstatus-' + orderId).value;
    const msgEl = document.getElementById('status-msg-' + orderId);

    fetch('update_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'order_id=' + orderId + '&payment_status=' + encodeURIComponent(ps)
            + '&csrf_token=' + encodeURIComponent(window.ADMIN_CSRF_TOKEN || ''),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            msgEl.textContent = '✅ Payment updated!';

            // Update the payment badge in the main row
            const badge = document.getElementById('pay-badge-' + orderId);
            if (badge) {
                const map = {
                    'Paid':   { icon: '<i class="fa-solid fa-circle-check" style="color:#10b981;"></i>', label: 'Paid Online',     color: '#10b981', bg: 'rgba(16,185,129,0.1)' },
                    'Cash':   { icon: '<i class="fa-solid fa-money-bill-wave" style="color:#f59e0b;"></i>', label: 'Cash Received', color: '#f59e0b', bg: 'rgba(245,158,11,0.1)' },
                    'Unpaid': { icon: '<i class="fa-solid fa-clock" style="color:var(--text-muted);"></i>', label: 'Not Paid',     color: 'var(--text-muted)', bg: 'rgba(100,100,100,0.08)' },
                };
                const m = map[ps] || map['Unpaid'];
                badge.innerHTML = m.icon + ' ' + m.label;
                badge.style.color      = m.color;
                badge.style.background = m.bg;
            }

            const mainRow = document.getElementById('row-' + orderId);
            if (mainRow) {
                mainRow.setAttribute('data-payment-status', ps);
            }
            if (typeof sortOrders === 'function') {
                sortOrders();
            }

            setTimeout(() => msgEl.textContent = '', 3000);
        } else {
            msgEl.textContent = '❌ Failed.'; msgEl.style.color = 'var(--color-danger)';
        }
    })
    .catch(() => { msgEl.textContent = '❌ Network error.'; msgEl.style.color = 'var(--color-danger)'; });
}

// ── COD Remittance ────────────────────────────────────────
// Delivery marks a COD order "Cash Received"; REMITTANCE is the courier actually
// paying the shop, usually days later. Recording it here is what lets the COD
// report tell the shop how much courier cash is still outstanding.
function recordRemittance(orderId, defaultAmount) {
    const msgEl = document.getElementById('status-msg-' + orderId);
    if (!msgEl) { return; }

    const ask = prompt('Remittance amount received from the courier (₹):', Number(defaultAmount).toFixed(2));
    if (ask === null) { return; }
    const amount = parseFloat(ask);
    if (isNaN(amount) || amount <= 0) {
        msgEl.textContent = '❌ Enter a valid amount.';
        msgEl.style.color = 'var(--color-danger)';
        return;
    }

    msgEl.textContent = '⏳ Recording…';
    msgEl.style.color = 'var(--color-secondary)';
    fetch('update_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=record_remittance'
            + '&order_id=' + encodeURIComponent(orderId)
            + '&amount=' + encodeURIComponent(amount)
            + '&csrf_token=' + encodeURIComponent(window.ADMIN_CSRF_TOKEN || ''),
    })
    .then(r => r.json())
    .then(data => {
        msgEl.textContent = data.success ? '✅ ' + (data.message || 'Remittance recorded.') : '❌ ' + (data.message || 'Failed.');
        msgEl.style.color = data.success ? '#10b981' : 'var(--color-danger)';
        if (data.success) { setTimeout(() => window.location.reload(), 1200); }
    })
    .catch(() => { msgEl.textContent = '❌ Network error.'; msgEl.style.color = 'var(--color-danger)'; });
}

// ── Gallery ─────────────────────────────────────────────────
function triggerGalleryUpload(input) {
    if (!input.files || !input.files[0]) return;
    const caption = document.getElementById('galleryCaption').value;
    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('gallery_image', input.files[0]);
    formData.append('caption', caption);

    const resultEl = document.getElementById('galleryUploadResult');
    resultEl.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Uploading…</div>';

    fetch('gallery_handler.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            resultEl.innerHTML = '<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> Photo uploaded!</div>';
            setTimeout(() => resultEl.innerHTML = '', 3000);

            // Add to grid
            const emptyMsg = document.getElementById('galleryEmptyMsg');
            if (emptyMsg) emptyMsg.remove();
            const grid = document.getElementById('adminGalleryGrid');
            const div = document.createElement('div');
            div.className = 'admin-gallery-item';
            div.id = 'gitem-' + data.id;
            div.innerHTML = `
                <img src="${window.ADMIN_UPLOADS_URL}/gallery/${data.filename}" alt="${data.caption || 'Gallery'}">
                <div class="admin-gallery-item-meta">
                    <div class="admin-gallery-item-caption">${data.caption || '—'}</div>
                </div>
                <button class="admin-gallery-del" onclick="deleteGalleryItem(${data.id})" title="Delete">
                    <i class="fa-solid fa-xmark"></i>
                </button>`;
            grid.prepend(div);

            // Reset form
            document.getElementById('galleryCaption').value = '';
            input.value = '';
        } else {
            resultEl.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${escHtml(data.message)}</div>`;
        }
    })
    .catch(() => {
        resultEl.innerHTML = '<div class="alert alert-danger">Upload failed. Please try again.</div>';
    });
}

function deleteGalleryItem(id) {
    if (!confirm('Delete this photo? This cannot be undone.')) return;
    fetch('gallery_handler.php?action=delete&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const el = document.getElementById('gitem-' + id);
            if (el) { el.style.opacity = '0'; el.style.transition = 'opacity 0.3s'; setTimeout(() => el.remove(), 300); }
        } else {
            alert('Failed to delete: ' + data.message);
        }
    });
}

// ── Categories ──────────────────────────────────────────────
window.getCatMsgEl = function() { return document.getElementById('catFormMsg'); };

function addCategory() {
    const name = document.getElementById('newCatName').value.trim();
    if (!name) { showCatMsg('Please enter a category name.', 'danger'); return; }

    fetch('category_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=add&name=' + encodeURIComponent(name) + '&csrf_token=' + encodeURIComponent(window.ADMIN_CSRF_TOKEN || ''),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showCatMsg('✅ Category added!', 'success');
            document.getElementById('newCatName').value = '';
            const container = document.getElementById('catListContainer');
            const div = document.createElement('div');
            div.className = 'cat-list-item';
            div.id = 'catrow-' + data.id;
            div.setAttribute('data-order', data.sort_order || 999);
            div.innerHTML = `
                <div style="display:flex;align-items:center;gap:12px;">
                    <i class="fa-solid fa-grip-vertical" style="color:var(--text-muted);font-size:13px;"></i>
                    <span class="cat-name" id="catname-${data.id}">${escHtml(data.name)}</span>
                </div>
                <div class="action-group">
                    <button class="btn-sm btn-sm-outline" onclick="startRename(${data.id},'${data.name.replace(/'/g,"\\'")}')">
                        <i class="fa-solid fa-pen"></i> Rename
                    </button>
                    <button class="btn-sm btn-sm-danger" onclick="deleteCategory(${data.id},'${data.name.replace(/'/g,"\\'")}')">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>`;
            container.appendChild(div);
            if (typeof filterAndSortCategories === 'function') {
                filterAndSortCategories();
            }
        } else {
            showCatMsg(data.message, 'danger');
        }
    });
}

async function startRename(id, currentName) {
    /* The shop's own dialog, not the browser's.
       prompt() draws a grey system box with the URL printed in it, in the middle
       of a panel that otherwise looks like the shop — and it is the one control
       here a person is asked to type into. dievonPrompt() lives in
       assets/js/dievon-dialog.js beside dievonAlert and dievonConfirm, which this
       panel already uses everywhere else.

       Falls back to prompt() if the library has not loaded, so a renaming still
       works on a page where the script is missing rather than doing nothing. */
    const ask = (typeof dievonPrompt === 'function')
        ? dievonPrompt('Enter a new name for this category.', currentName, {
            title: 'Rename category',
            confirmText: 'Save name',
            placeholder: 'Category name'
          })
        : Promise.resolve(prompt('Rename category:', currentName));

    const newName = await ask;
    if (!newName || newName.trim() === currentName) return;

    fetch('category_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=rename&id=' + id + '&name=' + encodeURIComponent(newName.trim()) + '&csrf_token=' + encodeURIComponent(window.ADMIN_CSRF_TOKEN || ''),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('catname-' + id).textContent = newName.trim();
            showCatMsg('✅ Renamed!', 'success');
        } else {
            alert('Failed: ' + data.message);
        }
    });
}

function deleteCategory(id, name) {
    if (!confirm('Delete category "' + name + '"? Products using it will still exist.')) return;
    fetch('category_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete&id=' + id + '&csrf_token=' + encodeURIComponent(window.ADMIN_CSRF_TOKEN || ''),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const el = document.getElementById('catrow-' + id);
            if (el) { el.style.opacity = '0'; el.style.transition = 'opacity 0.3s'; setTimeout(() => el.remove(), 300); }
        } else {
            alert(data.message);
        }
    });
}

function showCatMsg(msg, type) {
    const catMsgEl = typeof window.getCatMsgEl === 'function' ? window.getCatMsgEl() : document.getElementById('catFormMsg');
    if (!catMsgEl) return;
    // Only allow known alert types to prevent class-injection XSS.
    var safeType = /^(success|danger|info|warning)$/.test(type) ? type : 'info';
    catMsgEl.innerHTML = `<div class="alert alert-${safeType}" style="margin-bottom:12px;">${escHtml(msg)}</div>`;
    setTimeout(() => { catMsgEl.innerHTML = ''; }, 3500);
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Promo code buttons live in admin/promo.php, not here ──────────────
//
// createPromo(), togglePromo() and deletePromo() used to be defined here too.
// promo.php declares its own working versions inline, then includes the footer,
// which loads THIS file afterwards — so these older copies redefined the page's
// functions and won. Create failed with "CSRF security token invalid or missing",
// and Enable/Disable and Delete silently did nothing at all, leaving no way to
// switch off a live coupon from the panel.
//
// promo.php is the only page that calls them, so the copies are simply gone
// rather than renamed.

// ── Sorting & Filtering for Products & Categories ─────────────
/* filterAndSortProducts() used to be defined here as well as inline in
 * admin/products.php, and THIS copy was the one that ran — admin.js is loaded
 * after the page's own <script>, so it silently replaced the working version.
 *
 * The two had drifted. This copy selected rows with `tbody.querySelectorAll('.product-row')`
 * and sorted the default case on `dataset.id`; the rows products.php actually
 * renders carry neither — no class="product-row", no data-id. So it matched zero
 * rows and returned having done nothing, which is exactly what "Sort By does
 * nothing" looked like from the outside: no error, no console warning, no change.
 *
 * Deleted rather than repaired. products.php has a working implementation beside
 * the markup it belongs to, and nothing else on the site renders .product-row —
 * a second copy here can only drift again.
 */

function filterAndSortCategories() {
    const searchQuery = (document.getElementById('catSearch').value || '').toLowerCase().trim();
    const sortValue = document.getElementById('catSort').value;
    
    const container = document.getElementById('catListContainer');
    if (!container) return;
    const items = Array.from(container.querySelectorAll('.cat-list-item'));
    
    // Filter items
    items.forEach(item => {
        const nameEl = item.querySelector('.cat-name');
        if (!nameEl) return;
        const name = nameEl.textContent.toLowerCase();
        const show = name.includes(searchQuery);
        item.style.display = show ? 'flex' : 'none';
    });
    
    // Sort items
    items.sort((a, b) => {
        const nameAEl = a.querySelector('.cat-name');
        const nameBEl = b.querySelector('.cat-name');
        if (!nameAEl || !nameBEl) return 0;
        
        const nameA = nameAEl.textContent.trim();
        const nameB = nameBEl.textContent.trim();
        
        const orderA = parseInt(a.dataset.order) || 0;
        const orderB = parseInt(b.dataset.order) || 0;
        
        if (sortValue === 'name_asc') {
            return nameA.localeCompare(nameB);
        } else if (sortValue === 'name_desc') {
            return nameB.localeCompare(nameA);
        } else {
            // default: sort_order asc
            return orderA - orderB;
        }
    });
    
    // Re-append sorted items
    items.forEach(item => container.appendChild(item));
}

// ── Customer Name Filter for Orders ──────────────────────────
function filterOrdersByName(query) {
    const q = query.toLowerCase().trim();
    const tbody = document.querySelector('#ordersTable tbody');
    if (!tbody) return;
    const mainRows = Array.from(tbody.querySelectorAll('.order-row'));
    let visibleCount = 0;
    mainRows.forEach(row => {
        // Get customer name from the 3rd <td> (index 2)
        const cells = row.querySelectorAll('td');
        const nameTd = cells[2];
        const name = nameTd ? nameTd.textContent.toLowerCase() : '';
        const show = q === '' || name.includes(q);
        row.style.display = show ? '' : 'none';
        // Also hide/show the detail row
        const orderId = row.dataset.id;
        const detailRow = document.getElementById('detail-' + orderId);
        if (detailRow) detailRow.style.display = 'none'; // always collapse detail on filter
        if (show) visibleCount++;
    });
    const countEl = document.getElementById('orderFilterCount');
    if (countEl) {
        countEl.textContent = q ? `Showing ${visibleCount} of ${mainRows.length} orders` : '';
    }
    const totalDispEl = document.getElementById('ordersTotalDisplayCount');
    if (totalDispEl) {
        totalDispEl.textContent = visibleCount;
    }
}

// ── Stock Tab JS (incremental edit modal) ────────────────────
var stockEditProductId = typeof stockEditProductId !== 'undefined' ? stockEditProductId : null;

function openStockEdit(productId, productName, curTotal, curDamage, curOffline, variants) {
    stockEditProductId = productId;
    document.getElementById('stockEditTitle').textContent = '✏️ Edit Stock — ' + productName;
    document.getElementById('stockAddQty').value     = 0;
    document.getElementById('stockDamageQty').value  = 0;
    document.getElementById('stockOfflineQty').value = 0;
    document.getElementById('stockRemoveQty').value  = 0;
    document.getElementById('stockRemoveReason').value = '';
    document.getElementById('stockEditMsg').textContent = '';

    /* Ask which size, but only when the product has sizes.
       The shop sells from the size rows whenever any exist, so an addition has to
       name one. Each option carries the quantity that size holds right now, which
       is the number you need in front of you when deciding what to add — and the
       one the old dialog never showed. */
    var row = document.getElementById('stockVariantRow');
    var sel = document.getElementById('stockVariantId');
    var list = Array.isArray(variants) ? variants : [];
    sel.innerHTML = '';
    if (list.length) {
        list.forEach(function (v) {
            var o = document.createElement('option');
            o.value = v.id;
            o.textContent = (v.label || 'Size') + ' — holds ' + v.qty;
            sel.appendChild(o);
        });
        row.style.display = '';
    } else {
        row.style.display = 'none';
    }

    /* The reason box only appears once a removal is actually typed. Showing it
       always added a permanent empty dropdown to a dialog that is four numbers
       long, and it reads as required for every save when it is required for one. */
    var remQty    = document.getElementById('stockRemoveQty');
    var reasonRow = document.getElementById('stockRemoveReasonRow');
    var syncReason = function () {
        reasonRow.style.display = (parseInt(remQty.value, 10) > 0) ? '' : 'none';
    };
    if (!remQty.dataset.reasonBound) {
        remQty.addEventListener('input', syncReason);
        remQty.dataset.reasonBound = '1';
    }
    syncReason();

    /* display has to flip BEFORE opacity, or there is nothing laid out for the
       transition to run on and the dialog just appears. requestAnimationFrame gives
       the browser one frame to apply display:flex, then the class drives the fade —
       the same two-step the lightbox overlay uses. */
    var modal = document.getElementById('stockEditModal');
    modal.style.display = 'flex';
    requestAnimationFrame(function () { modal.classList.add('is-open'); });
    setTimeout(function () { document.getElementById('stockAddQty').focus(); }, 60);
}

function closeStockEdit() {
    /* Fade out, then hide. Setting display:none straight away would cut the
       transition off at frame one and the dialog would vanish, which is the very
       thing being fixed. The timeout matches the .22s in the stylesheet. */
    var modal = document.getElementById('stockEditModal');
    modal.classList.remove('is-open');
    stockEditProductId = null;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) { modal.style.display = 'none'; return; }
    setTimeout(function () {
        // Only hide if it has not been reopened in the meantime.
        if (!modal.classList.contains('is-open')) { modal.style.display = 'none'; }
    }, 220);
}

function saveStockEdit() {
    if (!stockEditProductId) return;
    const addQty     = Math.max(0, parseInt(document.getElementById('stockAddQty').value,    10) || 0);
    const damageQty  = Math.max(0, parseInt(document.getElementById('stockDamageQty').value, 10) || 0);
    const offlineQty = Math.max(0, parseInt(document.getElementById('stockOfflineQty').value,10) || 0);
    const removeQty  = Math.max(0, parseInt(document.getElementById('stockRemoveQty').value,  10) || 0);
    const removeReason = document.getElementById('stockRemoveReason').value;
    const variantRow = document.getElementById('stockVariantRow');
    const hasSizes   = variantRow && variantRow.style.display !== 'none';
    const variantId  = hasSizes ? (document.getElementById('stockVariantId').value || '') : '';
    const msgEl = document.getElementById('stockEditMsg');

    if (addQty === 0 && damageQty === 0 && offlineQty === 0 && removeQty === 0) {
        msgEl.textContent = '⚠️ Enter at least one quantity greater than 0.';
        msgEl.style.color = '#f59e0b';
        return;
    }
    /* Checked here as well as on the server. The server refuses either way, but
       catching it in the dialog means the reason is still on screen to pick,
       instead of the request bouncing back with the modal already half reset. */
    if (removeQty > 0 && !removeReason) {
        msgEl.textContent = '⚠️ Choose why the stock is leaving.';
        msgEl.style.color = '#f59e0b';
        return;
    }
    msgEl.textContent = 'Saving…';
    msgEl.style.color = 'var(--text-muted)';

    fetch('stock_handler.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=increment_stock&product_id=${stockEditProductId}&add_qty=${addQty}&damage_qty=${damageQty}&offline_qty=${offlineQty}`
            + `&remove_qty=${removeQty}&remove_reason=${encodeURIComponent(removeReason)}&variant_id=${encodeURIComponent(variantId)}`
            + `&csrf_token=${encodeURIComponent(window.ADMIN_CSRF_TOKEN || '')}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const id = stockEditProductId;
            const tsEl  = document.getElementById('val-total_stock-'  + id);
            const insEl = document.getElementById('val-in_stock-'     + id);
            const dmgEl = document.getElementById('val-damage_stock-' + id);
            const offEl = document.getElementById('val-sold_offline-' + id);
            // Classes rather than inline colours. This used to write
            // style.color = '#10b981' / '#ef4444' directly, which outranked the
            // stylesheet and put the old off-brand green and red back into the
            // row the moment anyone saved — the redesign survived until the
            // first edit and then reverted, one row at a time.
            const setNum = (el, value, badWhenSet) => {
                if (!el) { return; }
                el.textContent = value;
                const n = Number(value) || 0;
                el.classList.toggle('stock-num-zero', n === 0);
                if (badWhenSet) { el.classList.toggle('stock-num-bad', n > 0); }
            };
            setNum(tsEl,  data.total_stock,  false);
            setNum(dmgEl, data.damage_stock, true);
            setNum(offEl, data.sold_offline, false);
            if (insEl) {
                insEl.textContent = data.in_stock;
                const good = Number(data.in_stock) > 0;
                insEl.classList.toggle('stock-num-good', good);
                insEl.classList.toggle('stock-num-bad', !good);
            }
            closeStockEdit();
        } else {
            msgEl.textContent = '❌ ' + (data.message || 'Failed to save.');
            msgEl.style.color = '#ef4444';
        }
    })
    .catch(() => { msgEl.textContent = '❌ Network error.'; msgEl.style.color = '#ef4444'; });
}

document.getElementById('stockEditModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeStockEdit();
});

// ── Delete Order ──────────────────────────────────────────────
function deleteOrder(orderId, orderCode) {
    // Sends the CSRF token: this endpoint archives a tax invoice, so it is
    // protected like the refund handler rather than left open as it was.
    const proceed = () => {
        fetch('update_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=delete_order&order_id=' + encodeURIComponent(orderId)
                + '&csrf_token=' + encodeURIComponent(window.ADMIN_CSRF_TOKEN || ''),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) { window.location.reload(); }
            else { (window.dievonAlert || alert)(data.message || 'Could not archive this order.', { type: 'error' }); }
        })
        .catch(() => (window.dievonAlert || alert)('Network error.', { type: 'error' }));
    };

    const ask = 'Archive order ' + orderCode + '?\n\nIt is removed from this list but the record is kept, '
              + 'so its tax invoice can still be produced.';
    if (typeof window.dievonConfirm === 'function') {
        window.dievonConfirm(ask, { type: 'warning', confirmText: 'Archive' })
            .then(function (ok) { if (ok) { proceed(); } });
    } else if (window.confirm(ask)) {
        proceed();
    }
}


// ── Sorting for Orders ────────────────────────────────────────
var currentSortCol = typeof currentSortCol !== 'undefined' ? currentSortCol : 'date';
var currentSortDir = typeof currentSortDir !== 'undefined' ? currentSortDir : 'desc'; // default is newest first

function toggleSort(col) {
    if (currentSortCol === col) {
        currentSortDir = (currentSortDir === 'desc') ? 'asc' : 'desc';
    } else {
        currentSortCol = col;
        currentSortDir = (col === 'date') ? 'desc' : 'asc';
    }
    
    // Update sort icons
    const icons = ['date', 'payment', 'status'];
    icons.forEach(id => {
        const el = document.getElementById('sort-icon-' + id);
        if (!el) return;
        if (currentSortCol === id) {
            el.className = 'fa-solid ' + (currentSortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
            el.style.opacity = '1';
        } else {
            el.className = 'fa-solid fa-sort';
            el.style.opacity = '0.5';
        }
    });
    
    sortOrders();
}

function sortOrders() {
    const tbody = document.querySelector('#ordersTable tbody');
    if (!tbody) return;
    
    const mainRows = Array.from(tbody.querySelectorAll('.order-row'));
    const pairs = mainRows.map(row => {
        const orderId = row.dataset.id;
        const detailRow = document.getElementById('detail-' + orderId);
        return { main: row, detail: detailRow };
    });
    
    pairs.sort((a, b) => {
        if (currentSortCol === 'date') {
            const dateA = parseInt(a.main.dataset.date) || 0;
            const dateB = parseInt(b.main.dataset.date) || 0;
            return (currentSortDir === 'desc') ? (dateB - dateA) : (dateA - dateB);
        } else if (currentSortCol === 'payment') {
            const payA = a.main.dataset.paymentStatus || 'Unpaid';
            const payB = b.main.dataset.paymentStatus || 'Unpaid';
            const priority = { 'Paid': 1, 'Cash': 2, 'Unpaid': 3 };
            const pA = priority[payA] || 3;
            const pB = priority[payB] || 3;
            if (pA !== pB) {
                return (currentSortDir === 'asc') ? (pA - pB) : (pB - pA);
            }
            // Secondary sort by date
            const dateA = parseInt(a.main.dataset.date) || 0;
            const dateB = parseInt(b.main.dataset.date) || 0;
            return dateB - dateA;
        } else if (currentSortCol === 'status') {
            const stA = a.main.dataset.status || 'Pending';
            const stB = b.main.dataset.status || 'Pending';
            // Priority: Pending=1, Processing=2, Delivered=3, Cancelled=4
            const priority = { 'Pending': 1, 'Processing': 2, 'Delivered': 3, 'Cancelled': 4 };
            const pA = priority[stA] || 5;
            const pB = priority[stB] || 5;
            if (pA !== pB) {
                return (currentSortDir === 'asc') ? (pA - pB) : (pB - pA);
            }
            // Secondary sort by date descending
            const dateA = parseInt(a.main.dataset.date) || 0;
            const dateB = parseInt(b.main.dataset.date) || 0;
            return dateB - dateA;
        }
        return 0;
    });
    
    pairs.forEach(pair => {
        tbody.appendChild(pair.main);
        if (pair.detail) {
            tbody.appendChild(pair.detail);
        }
    });
}

/* ══════════════════════════════════════════════════════════════
   REFUNDS — sends real money back to the customer
   ══════════════════════════════════════════════════════════════
   Unlike the status handlers above, this one posts a CSRF token: a
   forged status change is an annoyance, a forged refund is cash out
   of the business. It also double-confirms, because there is no
   undo — once Razorpay accepts a refund it cannot be recalled. */
function issueRefund(orderId, orderCode, maxAmount, isOnline) {
    const amtEl      = document.getElementById('refund-amt-' + orderId);
    const reasonEl   = document.getElementById('refund-reason-' + orderId);
    const channelEl  = document.getElementById('refund-channel-' + orderId);
    const msgEl      = document.getElementById('refund-msg-' + orderId);
    if (!amtEl || !msgEl) { return; }

    const amount = parseFloat(amtEl.value);
    if (isNaN(amount) || amount <= 0) {
        msgEl.textContent = '❌ Enter an amount.';
        msgEl.className = 'refund-msg is-error';
        return;
    }
    if (amount > maxAmount + 0.005) {
        msgEl.textContent = '❌ Only ' + maxAmount.toFixed(2) + ' can still be refunded.';
        msgEl.className = 'refund-msg is-error';
        return;
    }

    const where = isOnline
        ? 'This sends the money back through Razorpay now. It cannot be undone.'
        : 'This records the refund only. You still have to hand the money back yourself.';
    const ask = 'Refund ' + amount.toFixed(2) + ' for order ' + orderCode + '?\n\n' + where;

    const proceed = () => {
        const btn = amtEl.parentElement.querySelector('button');
        if (btn) { btn.disabled = true; }
        msgEl.textContent = '⏳ Processing…';
        msgEl.className = 'refund-msg';

        fetch('update_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=refund_order'
                + '&order_id=' + encodeURIComponent(orderId)
                + '&amount=' + encodeURIComponent(amount)
                + '&reason=' + encodeURIComponent(reasonEl ? reasonEl.value : '')
                + '&payout_channel=' + encodeURIComponent(channelEl ? channelEl.value : '')
                + '&csrf_token=' + encodeURIComponent(window.ADMIN_CSRF_TOKEN || ''),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                msgEl.textContent = '✅ ' + (data.message || 'Refunded.')
                    + (data.reference ? ' Ref: ' + data.reference : '');
                msgEl.className = 'refund-msg is-ok';
                // Reload so the refundable figure and order status are re-read from
                // the database rather than guessed at in the browser.
                setTimeout(() => window.location.reload(), 1800);
            } else {
                msgEl.textContent = '❌ ' + (data.message || 'Refund failed.');
                msgEl.className = 'refund-msg is-error';
                if (btn) { btn.disabled = false; }
            }
        })
        .catch(() => {
            msgEl.textContent = '❌ Network error — the refund may not have gone through. Check Razorpay before retrying.';
            msgEl.className = 'refund-msg is-error';
            if (btn) { btn.disabled = false; }
        });
    };

    // dievon-dialog.js is loaded on admin pages (admin/includes/footer.php) and
    // gives the branded popup; dievonConfirm returns a Promise, it is not a
    // callback API. window.confirm is only the fallback if that script failed.
    if (typeof window.dievonConfirm === 'function') {
        window.dievonConfirm(ask, { type: 'warning', confirmText: 'Refund' })
            .then(function (ok) { if (ok) { proceed(); } });
    } else if (window.confirm(ask)) {
        proceed();
    }
}

/* ── Media Library: say which state an empty thumbnail is in ──────────
   A frame with no picture in it means one of two very different things:
   still downloading, or never arriving. They looked the same, so a slow
   page was indistinguishable from lost files.

   The shimmer in style.css runs until one of these fires. Images marked
   loading="lazy" simply fire later, when they are scrolled to.        */
(function () {
    var frames = document.querySelectorAll('.media-thumb');
    if (!frames.length) { return; }

    Array.prototype.forEach.call(frames, function (frame) {
        var img = frame.querySelector('img');
        if (!img) { frame.classList.add('is-ready'); return; }

        var settle = function () {
            // naturalWidth is 0 for a request that came back as anything
            // other than a usable image — 404, 403, or an HTML error page.
            if (img.naturalWidth > 0) { frame.classList.add('is-ready'); return; }

            // One retry on the full-size original before giving up, in case the
            // small WebP twin is the only thing missing.
            var fallback = img.getAttribute('data-fallback');
            if (fallback) {
                img.removeAttribute('data-fallback');
                img.src = fallback;
                return;
            }
            frame.classList.add('is-error');
        };

        // Listeners first, then settle: an image that is already complete still
        // needs them attached, or the fallback swap above would never resolve.
        img.addEventListener('load', settle);
        img.addEventListener('error', settle);
        if (img.complete) { settle(); }
    });
})();
