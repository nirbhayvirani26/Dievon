/* ============================================================
 *  Dievon – Show/hide password
 *
 *  Attaches to every <input type="password"> that opts in with
 *  data-toggle-visibility. Not applied to every password field
 *  automatically: a PIN field on a shared screen is not somewhere
 *  a reveal button belongs.
 *
 *  The button is built in JS rather than written into each template so
 *  the markup stays identical across register / reset / account, and so
 *  a browser with JS disabled simply gets a normal password field.
 * ============================================================ */
(function () {
    'use strict';

    function attach(input) {
        if (input.dataset.toggleAttached === '1') { return; }
        input.dataset.toggleAttached = '1';

        var wrap = document.createElement('div');
        wrap.className = 'pw-field-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';              // never submits the form
        btn.className = 'pw-toggle-btn';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('aria-pressed', 'false');
        btn.innerHTML = '<i class="fa-regular fa-eye"></i>';

        btn.addEventListener('click', function () {
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
            btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            btn.innerHTML = showing
                ? '<i class="fa-regular fa-eye"></i>'
                : '<i class="fa-regular fa-eye-slash"></i>';
            // Keep the caret where it was rather than jumping to the start.
            input.focus();
        });

        wrap.appendChild(btn);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[type="password"][data-toggle-visibility]').forEach(attach);
    });
})();
