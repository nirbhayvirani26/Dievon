/**
 * Visual editor for the blog article body.
 *
 * Progressive enhancement: the page ships a normal <textarea>, and this script
 * hides it and puts a contenteditable surface in front. If the script fails to
 * load, or an error stops it, the textarea is still there and still submits —
 * an admin can always write a post, just without the buttons.
 *
 * Everything typed here is rebuilt server-side from an allowlist
 * (includes/blog_content.php) before it is stored. Nothing in this file is a
 * security control; it is convenience only.
 */
(function () {
    'use strict';

    var textarea = document.querySelector('textarea[data-rich-editor]');
    if (!textarea || !document.execCommand) { return; }

    // A hidden field with `required` cannot be focused, so the browser blocks
    // submission with a validation message pointing at something invisible.
    // The check moves into JS below.
    textarea.removeAttribute('required');

    var TOOLS = [
        { cmd: 'bold',          label: 'B',  title: 'Bold  (Ctrl+B)',       style: 'font-weight:700;' },
        { cmd: 'italic',        label: 'I',  title: 'Italic  (Ctrl+I)',     style: 'font-style:italic;' },
        { cmd: 'underline',     label: 'U',  title: 'Underline  (Ctrl+U)',  style: 'text-decoration:underline;' },
        { sep: true },
        { block: 'h2',          label: 'H2', title: 'Large heading' },
        { block: 'h3',          label: 'H3', title: 'Small heading' },
        { block: 'p',           label: '¶',  title: 'Normal paragraph' },
        { sep: true },
        { cmd: 'insertUnorderedList', label: '• List', title: 'Bullet list' },
        { cmd: 'insertOrderedList',   label: '1. List', title: 'Numbered list' },
        { block: 'blockquote',  label: '❝',  title: 'Quote' },
        { sep: true },
        { link: true,           label: '🔗', title: 'Add link' },
        { unlink: true,         label: '⛓',  title: 'Remove link' },
        { clear: true,          label: '✕',  title: 'Clear formatting' }
    ];

    var wrap = document.createElement('div');
    wrap.className = 'rte-wrap';

    var bar = document.createElement('div');
    bar.className = 'rte-toolbar';

    var surface = document.createElement('div');
    surface.className = 'rte-surface form-control';
    surface.contentEditable = 'true';
    surface.setAttribute('role', 'textbox');
    surface.setAttribute('aria-multiline', 'true');
    surface.setAttribute('aria-label', 'Article body');

    // Existing posts are plain text, not HTML. Feeding that straight into
    // innerHTML would collapse every paragraph break into one run-on block, so
    // convert it the same way the storefront always has: blank line = new
    // paragraph, leading "## " = heading.
    var initial = textarea.value.trim();
    if (initial === '') {
        surface.innerHTML = '<p><br></p>';
    } else if (/<(p|br|h2|h3|ul|ol|li|strong|em|u|a|blockquote)\b[^>]*>/i.test(initial)) {
        surface.innerHTML = initial;
    } else {
        surface.innerHTML = initial.split(/\n\s*\n/).map(function (block) {
            var t = block.trim();
            if (t === '') { return ''; }
            if (t.indexOf('## ') === 0) { return '<h2>' + escapeHtml(t.slice(3).trim()) + '</h2>'; }
            return '<p>' + escapeHtml(t).replace(/\n/g, '<br>') + '</p>';
        }).join('');
    }

    function escapeHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function exec(command, value) {
        surface.focus();
        try { document.execCommand(command, false, value || null); } catch (e) {}
        sync();
        refreshActive();
    }

    TOOLS.forEach(function (tool) {
        if (tool.sep) {
            var sep = document.createElement('span');
            sep.className = 'rte-sep';
            bar.appendChild(sep);
            return;
        }
        var btn = document.createElement('button');
        btn.type = 'button';                 // never submit the form
        btn.className = 'rte-btn';
        btn.title = tool.title;
        btn.textContent = tool.label;
        if (tool.style) { btn.setAttribute('style', tool.style); }
        if (tool.cmd) { btn.dataset.cmd = tool.cmd; }

        btn.addEventListener('mousedown', function (e) {
            // Stop the button stealing focus, which would collapse the
            // selection before the command runs.
            e.preventDefault();
        });

        btn.addEventListener('click', function () {
            if (tool.cmd)   { exec(tool.cmd); return; }
            if (tool.block) { exec('formatBlock', '<' + tool.block + '>'); return; }
            if (tool.unlink){ exec('unlink'); return; }
            if (tool.clear) { exec('removeFormat'); exec('unlink'); return; }
            if (tool.link) {
                var url = window.prompt('Link address:\n\nExample: https://dievon.com/shop', 'https://');
                if (!url) { return; }
                url = url.trim();
                // Mirrors the server allowlist so the admin finds out now rather
                // than having the link silently dropped on save.
                if (!/^(https?:\/\/|mailto:|\/|#)/i.test(url)) {
                    window.alert('That link was not added.\n\nUse a full address starting with https://, an email link (mailto:...), or a path on this site starting with /');
                    return;
                }
                exec('createLink', url);
            }
        });

        bar.appendChild(btn);
    });

    // From here on this post is HTML. Set as soon as the editor takes over, not
    // on submit, so a post saved without touching the body still records the
    // format its content is actually in.
    var formatField = document.getElementById('content_format');
    if (formatField) { formatField.value = 'html'; }

    function sync() {
        var html = surface.innerHTML.trim();
        // An "empty" contenteditable still contains a placeholder break; storing
        // it would make a blank article look filled to the required-check below.
        if (html === '<br>' || html === '<p><br></p>' || html === '<div><br></div>') { html = ''; }
        textarea.value = html;
    }

    function refreshActive() {
        bar.querySelectorAll('.rte-btn[data-cmd]').forEach(function (b) {
            var on = false;
            try { on = document.queryCommandState(b.dataset.cmd); } catch (e) {}
            b.classList.toggle('is-active', !!on);
        });
    }

    surface.addEventListener('input', sync);
    surface.addEventListener('keyup', refreshActive);
    surface.addEventListener('mouseup', refreshActive);

    // Paste as plain text. Pasting from Word or a web page otherwise drags in
    // font tags, class names and colour styles — all of which the server strips
    // anyway, so the author would see formatting in the editor that silently
    // vanishes from the published article.
    surface.addEventListener('paste', function (e) {
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text/plain');
        document.execCommand('insertText', false, text);
        sync();
    });

    var form = textarea.closest('form');
    if (form) {
        form.addEventListener('submit', function (e) {
            sync();
            if (textarea.value.trim() === '') {
                e.preventDefault();
                window.alert('Please write the article body before saving.');
                surface.focus();
            }
        });
    }

    textarea.classList.add('rte-source');
    textarea.parentNode.insertBefore(wrap, textarea);
    wrap.appendChild(bar);
    wrap.appendChild(surface);
    wrap.appendChild(textarea);

    // Emit <b>/<i>/<u> rather than styled spans. The server normalises b and i
    // to strong and em; spans would simply be unwrapped and the formatting lost.
    try { document.execCommand('styleWithCSS', false, false); } catch (e) {}

    // Press Enter and get a <p>, not a <div>.
    //
    // Chrome and Safari default to <div> for a new line once the caret has been
    // inside a heading or a list, which produced articles whose paragraphs were
    // divs. Set before the first sync so it applies from the opening keystroke,
    // not from whenever the author first clicks a toolbar button.
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}

    sync();
})();
