function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const instantResultsGrid = document.getElementById('instantResultsGrid');
    const voiceSearchBtn = document.getElementById('voiceSearchBtn');
    let searchTimeout;

    if (!searchInput) return;

    // Load Recent Searches
    loadRecentSearches();

    // Instant Search logic
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        if (query.length < 2) {
            instantResultsGrid.innerHTML = '<p style="color: var(--text-muted); font-size: 14px;">Start typing to see results...</p>';
            return;
        }

        instantResultsGrid.innerHTML = '<div style="display:flex; justify-content:center; padding: 20px;"><i class="fa-solid fa-circle-notch fa-spin"></i></div>';

        searchTimeout = setTimeout(() => {
            // Absolute, not relative: on a clean-URL page like /product/<slug> or
            // /collections/<slug> a relative 'ajax_search.php' resolves to
            // /product/ajax_search.php and 404s — which is exactly the "search
            // returns no products" bug on product/collection pages.
            fetch(window.SITE_URL + '/ajax_search.php?q=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    if (data.length === 0) {
                        instantResultsGrid.innerHTML = '<p style="color: var(--text-muted); font-size: 14px;">No products found for "' + escapeHtml(query) + '".</p>';
                        return;
                    }

                    let html = '';
                    data.forEach(product => {
                        // Last-resort symbol only — formatted_price from the server, then
                        // formatPriceJS, are both preferred. This is a plain .js file so no
                        // PHP can be injected; the symbol comes from the global the page
                        // publishes. Defaults to the rupee, not the pound.
                        const curSym = (window.CURRENCY_RATES
                            && typeof getCurrentCurrency === 'function'
                            && window.CURRENCY_RATES[getCurrentCurrency()]
                            && window.CURRENCY_RATES[getCurrentCurrency()].symbol) || '₹';
                        const priceDisplay = product.formatted_price
                            ? escapeHtml(product.formatted_price)
                            : (typeof formatPriceJS === 'function'
                                ? formatPriceJS(product.price)
                                : curSym + parseFloat(product.price).toFixed(2));
                        const imgSrc = product.image_url ? product.image_url : (product.image ? window.SITE_URL + '/uploads/products/' + product.image : '');
                        const imgHtml = imgSrc ? `<img src="${escapeHtml(imgSrc)}" alt="${escapeHtml(product.name)}">` : `<div style="width:80px; height:100px; background:#f3f4f6; display:flex; align-items:center; justify-content:center;">${product.emoji || '✨'}</div>`;
                        
                        html += `
                            <a href="${window.SITE_URL}/product.php?id=${product.id}" class="search-product-card">
                                ${imgHtml}
                                <div class="search-product-details">
                                    <div class="search-product-title">${escapeHtml(product.name)}</div>
                                    <div class="search-product-category" style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">${escapeHtml(product.category || '')}</div>
                                    <div class="search-product-price">${priceDisplay}</div>
                                </div>
                            </a>
                        `;
                    });
                    instantResultsGrid.innerHTML = html;
                })
                .catch(err => {
                    console.error('Search error:', err);
                    instantResultsGrid.innerHTML = '<p style="color: red; font-size: 14px;">Error loading results.</p>';
                });
        }, 300); // 300ms debounce
    });

    // Save search on Enter key
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            const query = this.value.trim();
            if (query.length > 0) {
                saveRecentSearch(query);
                window.location.href = window.SITE_URL + '/shop?search=' + encodeURIComponent(query);
            }
        }
    });

    // Voice Search using Web Speech API
    if (voiceSearchBtn) {
        if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            const recognition = new SpeechRecognition();
            recognition.continuous = false;
            recognition.interimResults = false;

            recognition.onstart = function() {
                voiceSearchBtn.style.color = 'red';
                voiceSearchBtn.classList.add('fa-beat-fade');
                searchInput.placeholder = "Listening...";
            };

            recognition.onresult = function(event) {
                const transcript = event.results[0][0].transcript;
                searchInput.value = transcript;
                searchInput.dispatchEvent(new Event('input'));
            };

            recognition.onerror = function(event) {
                console.error("Voice search error", event.error);
                resetVoiceBtn();
            };

            recognition.onend = function() {
                resetVoiceBtn();
            };

            voiceSearchBtn.addEventListener('click', () => {
                try {
                    recognition.start();
                } catch(e) {}
            });
        } else {
            // Not supported
            voiceSearchBtn.style.display = 'none';
        }
    }

    function resetVoiceBtn() {
        voiceSearchBtn.style.color = 'var(--text-muted)';
        voiceSearchBtn.classList.remove('fa-beat-fade');
        searchInput.placeholder = "Search for products, categories...";
    }

    function saveRecentSearch(query) {
        let searches = JSON.parse(localStorage.getItem('recentSearches') || '[]');
        searches = searches.filter(s => s.toLowerCase() !== query.toLowerCase());
        searches.unshift(query);
        if (searches.length > 5) searches.pop();
        localStorage.setItem('recentSearches', JSON.stringify(searches));
    }

    function loadRecentSearches() {
        const recentList = document.getElementById('recentSearchesList');
        if (!recentList) return;
        
        let searches = JSON.parse(localStorage.getItem('recentSearches') || '[]');
        if (searches.length === 0) {
            recentList.innerHTML = '<li><span style="color:var(--text-muted); font-size:13px;">No recent searches</span></li>';
            return;
        }

        let html = '';
        searches.forEach(s => {
            html += `<li><a href="${window.SITE_URL}/shop?search=${encodeURIComponent(s)}"><i class="fa-solid fa-clock-rotate-left" style="font-size:12px; margin-right:8px; color:var(--text-muted);"></i> ${escapeHtml(s)}</a></li>`;
        });
        recentList.innerHTML = html;
    }

    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe.toString()
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }
});
