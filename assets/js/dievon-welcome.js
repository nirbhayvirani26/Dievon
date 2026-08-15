/* ============================================================
 *  Dievon – Welcome / Join popup
 *
 *  Appears once to a first-time visitor a few seconds after the page settles.
 *
 *  Three rules keep it from being an annoyance:
 *    1. Once dismissed it never returns — the flag is in localStorage, so it
 *       survives closing the browser, unlike a session cookie.
 *    2. It waits, rather than firing on load. Someone who lands and leaves in
 *       two seconds never sees it, and the page gets to render first.
 *    3. Escape and the backdrop both close it, and there is an explicit
 *       "No thanks" — a popup with only a tiny × reads as a trap.
 * ============================================================ */
(function () {
    'use strict';

    var KEY   = 'dievon_welcome_seen';
    var DELAY = 6000;   // ms before it appears

    function overlay() { return document.getElementById('welcomeOverlay'); }

    function markSeen() {
        try { localStorage.setItem(KEY, String(Date.now())); } catch (e) { /* private mode */ }
    }

    window.closeWelcome = function () {
        var el = overlay();
        if (!el) { return; }
        el.classList.remove('is-open');
        document.body.classList.remove('welcome-open');
        markSeen();
    };

    window.submitWelcomeEmail = function (event, form) {
        event.preventDefault();
        var msg   = document.getElementById('welcomeMsg');
        var email = (form.email.value || '').trim();
        if (!email) { return false; }

        var btn = form.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.textContent = 'Joining…'; }

        var fd = new FormData();
        fd.append('email', email);
        if (window.csrfToken) { fd.append('csrf_token', window.csrfToken); }

        fetch(window.SITE_URL + '/actions/newsletter_action.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (btn) { btn.disabled = false; btn.textContent = 'Join'; }
                msg.textContent = data.message || (data.success ? 'Thank you for joining.' : 'Please try again.');
                msg.className = 'welcome-msg ' + (data.success ? 'is-ok' : 'is-error');
                if (data.success) {
                    markSeen();
                    form.reset();
                    setTimeout(window.closeWelcome, 1800);
                }
            })
            .catch(function () {
                if (btn) { btn.disabled = false; btn.textContent = 'Join'; }
                msg.textContent = 'Network error. Please try again.';
                msg.className = 'welcome-msg is-error';
            });
        return false;
    };

    function open() {
        var el = overlay();
        if (!el) { return; }
        el.classList.add('is-open');
        document.body.classList.add('welcome-open');
        var first = el.querySelector('.welcome-input');
        if (first) { setTimeout(function () { try { first.focus({ preventScroll: true }); } catch (e) {} }, 260); }
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay() && overlay().classList.contains('is-open')) {
            window.closeWelcome();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        if (!overlay()) { return; }               // suppressed server-side
        var seen = null;
        try { seen = localStorage.getItem(KEY); } catch (e) { seen = '1'; }  // no storage → do not nag
        if (seen) { return; }
        setTimeout(open, DELAY);
    });
})();
