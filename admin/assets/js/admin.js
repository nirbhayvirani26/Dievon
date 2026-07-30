// ── Orders ──────────────────────────────────────────────────
function toggleDetail(id) {
    const row  = document.getElementById('detail-' + id);
    const icon = document.getElementById('icon-' + id);
    const isOpen = row.style.display === 'table-row';
    row.style.display  = isOpen ? 'none' : 'table-row';
    icon.style.transform = isOpen ? 'rotate(0)' : 'rotate(180deg)';
    icon.style.transition = 'transform 0.3s ease';
}

function updateStatus(orderId, orderCode) {
    const status = document.getElementById('status-' + orderId).value;
    const msgEl  = document.getElementById('status-msg-' + orderId);

    fetch('update_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'order_id=' + orderId + '&status=' + encodeURIComponent(status),
    })
    .then(r => r.json())
    .then(data => {
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
        body: 'order_id=' + orderId + '&payment_status=' + encodeURIComponent(ps),
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
                <img src="../uploads/gallery/${data.filename}" alt="${data.caption || 'Gallery'}">
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
            resultEl.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${data.message}</div>`;
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

function startRename(id, currentName) {
    const newName = prompt('Rename category:', currentName);
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
    catMsgEl.innerHTML = `<div class="alert alert-${type}" style="margin-bottom:12px;">${msg}</div>`;
    setTimeout(() => { catMsgEl.innerHTML = ''; }, 3500);
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ═══════════════ PROMO CODES JS ═══════════════════════════
function createPromo() {
    const code  = document.getElementById('promoCode').value.trim().toUpperCase();
    const desc  = document.getElementById('promoDesc').value.trim();
    const type  = document.getElementById('promoType').value;
    const value = document.getElementById('promoValue').value;
    const min   = document.getElementById('promoMin').value || 0;
    const maxU  = document.getElementById('promoMax').value;
    const exp   = document.getElementById('promoExpires').value;
    const active = document.getElementById('promoActive').checked ? 1 : 0;

    if (!code || !value) { alert('Code and discount value are required.'); return; }

    fetch('../promo_handler.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=create&code=${encodeURIComponent(code)}&description=${encodeURIComponent(desc)}&discount_type=${type}&discount_value=${value}&min_order=${min}&max_uses=${maxU}&expires_at=${exp}&active=${active}`,
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { alert(data.message || 'Error'); return; }
        location.reload();
    });
}

function togglePromo(id) {
    fetch('../promo_handler.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=toggle&id=${id}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const badge = document.getElementById('promo-badge-' + id);
            const btn   = document.getElementById('promo-toggle-' + id);
            if (data.active) {
                badge.textContent = 'Active'; badge.style.background = 'rgba(16,185,129,0.15)'; badge.style.color = '#10b981';
                btn.innerHTML = '<i class="fa-solid fa-toggle-on"></i> Disable';
            } else {
                badge.textContent = 'Disabled'; badge.style.background = 'rgba(100,100,100,0.12)'; badge.style.color = 'var(--text-muted)';
                btn.innerHTML = '<i class="fa-solid fa-toggle-off"></i> Enable';
            }
        }
    });
}

function deletePromo(id, code) {
    if (!confirm(`Delete promo code "${code}"? This cannot be undone.`)) return;
    fetch('../promo_handler.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=delete&id=${id}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('promorow-' + id);
            if (row) { row.style.opacity = '0'; row.style.transition = 'opacity 0.3s'; setTimeout(() => row.remove(), 300); }
        }
    });
}

// ── Sorting & Filtering for Products & Categories ─────────────
function filterAndSortProducts() {
    const selectedCategory = document.getElementById('prodFilterCategory').value;
    const sortValue = document.getElementById('prodSort').value;
    
    const tbody = document.querySelector('#productsTable tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('.product-row'));
    
    // Filter rows
    rows.forEach(row => {
        const cat = row.dataset.category;
        const show = (selectedCategory === 'all' || cat === selectedCategory);
        row.style.display = show ? '' : 'none';
    });
    
    // Sort rows
    rows.sort((a, b) => {
        if (sortValue === 'name_asc') {
            return a.dataset.name.localeCompare(b.dataset.name);
        } else if (sortValue === 'name_desc') {
            return b.dataset.name.localeCompare(a.dataset.name);
        } else if (sortValue === 'price_asc') {
            return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
        } else if (sortValue === 'price_desc') {
            return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
        } else if (sortValue === 'category_asc') {
            return a.dataset.category.localeCompare(b.dataset.category);
        } else {
            // default: Sort by ID desc (newest first)
            return parseInt(b.dataset.id) - parseInt(a.dataset.id);
        }
    });
    
    // Re-append sorted rows
    rows.forEach(row => tbody.appendChild(row));
}

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

function openStockEdit(productId, productName, curTotal, curDamage, curOffline) {
    stockEditProductId = productId;
    document.getElementById('stockEditTitle').textContent = '✏️ Edit Stock — ' + productName;
    document.getElementById('stockAddQty').value    = 0;
    document.getElementById('stockDamageQty').value = 0;
    document.getElementById('stockOfflineQty').value = 0;
    document.getElementById('stockEditMsg').textContent = '';
    document.getElementById('stockEditModal').style.display = 'flex';
    setTimeout(() => document.getElementById('stockAddQty').focus(), 50);
}

function closeStockEdit() {
    document.getElementById('stockEditModal').style.display = 'none';
    stockEditProductId = null;
}

function saveStockEdit() {
    if (!stockEditProductId) return;
    const addQty     = Math.max(0, parseInt(document.getElementById('stockAddQty').value,    10) || 0);
    const damageQty  = Math.max(0, parseInt(document.getElementById('stockDamageQty').value, 10) || 0);
    const offlineQty = Math.max(0, parseInt(document.getElementById('stockOfflineQty').value,10) || 0);
    const msgEl = document.getElementById('stockEditMsg');

    if (addQty === 0 && damageQty === 0 && offlineQty === 0) {
        msgEl.textContent = '⚠️ Enter at least one quantity greater than 0.';
        msgEl.style.color = '#f59e0b';
        return;
    }
    msgEl.textContent = 'Saving…';
    msgEl.style.color = 'var(--text-muted)';

    fetch('stock_handler.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=increment_stock&product_id=${stockEditProductId}&add_qty=${addQty}&damage_qty=${damageQty}&offline_qty=${offlineQty}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const id = stockEditProductId;
            const tsEl  = document.getElementById('val-total_stock-'  + id);
            const insEl = document.getElementById('val-in_stock-'     + id);
            const dmgEl = document.getElementById('val-damage_stock-' + id);
            const offEl = document.getElementById('val-sold_offline-' + id);
            if (tsEl)  tsEl.textContent  = data.total_stock;
            if (insEl) { insEl.textContent = data.in_stock; insEl.style.color = data.in_stock > 0 ? '#10b981' : '#ef4444'; }
            if (dmgEl) dmgEl.textContent = data.damage_stock;
            if (offEl) offEl.textContent = data.sold_offline;
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
    if (!confirm(`Delete order ${orderCode}?\nThis cannot be undone.`)) return;
    fetch('update_order.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete_order&order_id=${orderId}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Fade out and remove the main row + detail row
            const mainRow   = document.getElementById('row-'    + orderId);
            const detailRow = document.getElementById('detail-' + orderId);
            [mainRow, detailRow].forEach(el => {
                if (!el) return;
                el.style.transition = 'opacity 0.3s';
                el.style.opacity = '0';
            });
            setTimeout(() => {
                mainRow?.remove();
                detailRow?.remove();
            }, 320);
        } else {
            alert('Failed to delete order: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(() => alert('Network error. Please try again.'));
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
