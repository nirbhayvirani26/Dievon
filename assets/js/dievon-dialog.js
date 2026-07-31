/* ============================================================
 *  Dievon Dialog — branded replacement for window.alert / confirm
 *
 *  Why: the native dialogs render as the OS chrome ("localhost:8888 says…"),
 *  which looks like a browser security warning rather than part of the shop.
 *
 *  Usage:
 *      dievonAlert('Saved successfully');                  // fire and forget
 *      dievonAlert('Could not save', { type: 'error' });
 *      if (await dievonConfirm('Delete this order?')) { … } // caller must be async
 *
 *  window.alert is overridden here, so the ~80 existing alert() calls across the
 *  site become branded with no call-site changes. window.confirm CANNOT be
 *  overridden the same way — the native one blocks and returns a boolean
 *  synchronously, which a custom modal cannot do — so confirm() call sites are
 *  converted to `await dievonConfirm(...)` individually.
 * ============================================================ */
(function () {
    'use strict';

    if (window.dievonAlert) { return; }   // already loaded

    var overlay = null;
    var previouslyFocused = null;

    var ICONS = {
        success: '✓',
        error:   '✕',
        warning: '!',
        confirm: '?',
        info:    'i'
    };
    var TITLES = {
        success: 'Success',
        error:   'Something Went Wrong',
        warning: 'Please Note',
        confirm: 'Please Confirm',
        info:    'Notice'
    };

    function build() {
        if (overlay) { return overlay; }
        overlay = document.createElement('div');
        overlay.className = 'dv-dialog-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.innerHTML =
            '<div class="dv-dialog">' +
                '<div class="dv-dialog-icon" aria-hidden="true"></div>' +
                '<h2 class="dv-dialog-title"></h2>' +
                '<p class="dv-dialog-message"></p>' +
                '<div class="dv-dialog-actions"></div>' +
            '</div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    function close(result, resolve) {
        if (!overlay) { return; }
        overlay.classList.remove('is-open');
        document.body.classList.remove('dv-dialog-open');
        document.removeEventListener('keydown', overlay._keyHandler);
        if (previouslyFocused && previouslyFocused.focus) {
            try { previouslyFocused.focus(); } catch (e) {}
        }
        if (resolve) { resolve(result); }
    }

    /**
     * @param {string}  message
     * @param {object}  opts  { type, title, confirmText, cancelText, isConfirm, danger }
     * @returns {Promise<boolean>}
     */
    function open(message, opts) {
        opts = opts || {};
        var type = opts.type || (opts.isConfirm ? 'confirm' : 'info');
        var el = build();

        el.querySelector('.dv-dialog').className = 'dv-dialog dv-dialog--' + type;
        el.querySelector('.dv-dialog-icon').textContent = ICONS[type] || ICONS.info;
        el.querySelector('.dv-dialog-title').textContent = opts.title || TITLES[type] || TITLES.info;
        // textContent, never innerHTML — messages can contain user/server data.
        el.querySelector('.dv-dialog-message').textContent = String(message == null ? '' : message);

        var actions = el.querySelector('.dv-dialog-actions');
        actions.innerHTML = '';

        return new Promise(function (resolve) {
            if (opts.isConfirm) {
                var cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'dv-dialog-btn dv-dialog-btn--ghost';
                cancel.textContent = opts.cancelText || 'Cancel';
                cancel.onclick = function () { close(false, resolve); };
                actions.appendChild(cancel);
            }

            var ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'dv-dialog-btn ' + (opts.danger ? 'dv-dialog-btn--danger' : 'dv-dialog-btn--primary');
            ok.textContent = opts.confirmText || (opts.isConfirm ? 'Confirm' : 'OK');
            ok.onclick = function () { close(true, resolve); };
            actions.appendChild(ok);

            el._keyHandler = function (e) {
                if (e.key === 'Escape') { close(false, resolve); }
                if (e.key === 'Enter' && document.activeElement !== ok) { e.preventDefault(); close(true, resolve); }
            };
            document.addEventListener('keydown', el._keyHandler);

            // Click the backdrop to dismiss — same as pressing Cancel.
            el.onclick = function (e) { if (e.target === el) { close(false, resolve); } };

            previouslyFocused = document.activeElement;
            document.body.classList.add('dv-dialog-open');
            el.classList.add('is-open');
            setTimeout(function () { ok.focus(); }, 60);
        });
    }

    /** Guess a sensible style from the wording of legacy alert() strings. */
    function inferType(message) {
        var m = String(message == null ? '' : message).toLowerCase();
        if (/(error|failed|could not|cannot|invalid|denied|wrong|unable|not allowed)/.test(m)) { return 'error'; }
        if (/(success|added|saved|updated|created|submitted|sent|complete|thank)/.test(m))     { return 'success'; }
        if (/(please|select|required|must|warning|notice)/.test(m))                            { return 'warning'; }
        return 'info';
    }

    window.dievonAlert = function (message, opts) {
        opts = opts || {};
        if (!opts.type) { opts.type = inferType(message); }
        return open(message, opts);
    };

    window.dievonConfirm = function (message, opts) {
        opts = opts || {};
        opts.isConfirm = true;
        if (!opts.type) { opts.type = opts.danger ? 'warning' : 'confirm'; }
        return open(message, opts);
    };

    // Drop-in replacement so existing alert() calls are branded automatically.
    // The native one is kept on window.nativeAlert in case it is ever needed.
    window.nativeAlert = window.alert;
    window.alert = function (message) { window.dievonAlert(message); };

    /* ── Helpers for inline handlers ──────────────────────────────────────
     * `onclick="return confirm(...)"` relies on confirm() blocking and returning
     * a boolean synchronously. A custom modal cannot do that, so instead these
     * always cancel the default action first, then re-trigger it for real once
     * the user has confirmed.
     *
     *   <a href="x.php?delete=1" onclick="return dvConfirmLink(this, 'Delete this?')">
     *   <form onsubmit="return dvConfirmForm(this, 'Delete this?')">
     */
    window.dvConfirmLink = function (el, message, opts) {
        opts = Object.assign({ danger: true, confirmText: 'Delete', cancelText: 'Cancel' }, opts || {});
        window.dievonConfirm(message, opts).then(function (ok) {
            // Navigate directly rather than calling el.click(), which would re-enter
            // this handler and loop.
            if (ok && el && el.href) { window.location.href = el.href; }
        });
        return false;
    };

    window.dvConfirmForm = function (form, message, opts) {
        opts = Object.assign({ danger: true, confirmText: 'Delete', cancelText: 'Cancel' }, opts || {});
        window.dievonConfirm(message, opts).then(function (ok) {
            // HTMLFormElement.submit() deliberately does NOT fire onsubmit again,
            // so this cannot loop.
            if (ok && form) { form.submit(); }
        });
        return false;
    };
})();
