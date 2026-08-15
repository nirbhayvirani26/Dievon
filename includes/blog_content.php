<?php
/**
 * Blog article body: sanitising and rendering.
 *
 * The admin editor produces HTML, which means the article body is the one place
 * on this site where an admin's keystrokes become markup rather than text. That
 * is fine for the person who owns the shop, but it is not fine if their account
 * is ever borrowed, and it is not fine if something is pasted in from a page
 * that carried a payload. So nothing reaches the database until it has been
 * rebuilt from an allowlist here.
 *
 * The rule is rebuild, not strip. A blocklist ("remove <script>") loses to the
 * next encoding trick; an allowlist keeps only the dozen tags a fashion article
 * actually needs and discards everything else by default, including anything
 * this file has never heard of.
 */

/**
 * Tags an article body may contain, mapped to the attributes each may keep.
 *
 * Deliberately absent: img and iframe (no body images by design), div and span
 * (layout belongs to the stylesheet, not the author), and every form element.
 */
function blogAllowedTags(): array {
    return [
        'p'          => [],
        'br'         => [],
        'strong'     => [],
        'em'         => [],
        'u'          => [],
        'h2'         => [],
        'h3'         => [],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'blockquote' => [],
        'a'          => ['href'],
    ];
}

/**
 * Tags whose CONTENT is thrown away along with the tag.
 *
 * Everything else unknown is unwrapped instead — a <span> around a sentence
 * should lose the span and keep the sentence. But the text inside a <script>
 * or <style> is code, and unwrapping it would paste that code into the article
 * as visible text at best, or reconstitute it at worst.
 */
function blogStripWithContents(): array {
    return ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'form'];
}

/**
 * Rebuild an HTML fragment so it contains only allowed tags and attributes.
 *
 * Returns '' for empty input. Never throws on malformed HTML — libxml's parser
 * recovers from unclosed tags, which is the normal state of anything pasted.
 */
function blogSanitizeHtml(string $html): string {
    $html = trim($html);
    if ($html === '') { return ''; }

    $doc = new DOMDocument();

    // Two things this line is doing. The XML declaration forces UTF-8, without
    // which DOMDocument assumes Latin-1 and turns every ₹, — and é in the
    // article into mojibake. The wrapper div gives a single known root to read
    // the children back out of.
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML(
        '<?xml encoding="UTF-8"?><div id="dievon-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$ok) { return ''; }

    $root = $doc->getElementById('dievon-root');
    if (!$root) {
        // getElementById needs a DTD to know 'id' is an ID attribute; fall back
        // to the first element rather than silently returning an empty article.
        $root = $doc->documentElement;
    }
    if (!$root) { return ''; }

    blogSanitizeNode($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

/**
 * Recursively clean one node's children.
 *
 * Children are copied into a plain array before iterating: removing or
 * replacing a node mutates the live DOMNodeList underneath the loop, which
 * silently skips siblings — the classic way a sanitiser lets the second
 * <script> through while catching the first.
 */
function blogSanitizeNode(DOMNode $node): void {
    $allowed = blogAllowedTags();
    $killed  = blogStripWithContents();

    $children = [];
    foreach ($node->childNodes as $child) { $children[] = $child; }

    foreach ($children as $child) {
        // Comments can carry markup that some parsers resurrect. Nothing in an
        // article needs them.
        if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
            $child->parentNode->removeChild($child);
            continue;
        }
        if ($child->nodeType === XML_TEXT_NODE) { continue; }
        if ($child->nodeType !== XML_ELEMENT_NODE) {
            $child->parentNode->removeChild($child);
            continue;
        }

        /** @var DOMElement $child */
        $tag = strtolower($child->nodeName);

        // The editor emits <b>/<i>; the stylesheet and screen readers both
        // prefer the semantic pair. Normalise rather than allow four tags.
        if ($tag === 'b') { $child = blogRenameElement($child, 'strong'); $tag = 'strong'; }
        elseif ($tag === 'i') { $child = blogRenameElement($child, 'em'); $tag = 'em'; }

        if (in_array($tag, $killed, true)) {
            $child->parentNode->removeChild($child);
            continue;
        }

        if (!isset($allowed[$tag])) {
            // Clean the subtree FIRST, so anything dangerous inside is gone
            // before its children are promoted or the wrapper is renamed.
            blogSanitizeNode($child);

            // A block-level unknown becomes a paragraph rather than dissolving.
            //
            // Browsers emit <div> for a new line in a contenteditable region
            // once the caret has been inside a heading or a list. Unwrapping
            // those divs deleted the only thing separating one paragraph from
            // the next, so an article written normally came out as a single
            // run-on sentence — the author's line breaks silently discarded
            // between the editor and the published page.
            $fallback = blogBlockFallback($tag);
            if ($fallback !== null && !blogHasBlockChild($child)) {
                $renamed = blogRenameElement($child, $fallback);
                blogSanitizeAttributes($renamed, $fallback, []);
                continue;
            }

            // Inline unknown (<span>, <font>), or a block that already contains
            // real blocks and so cannot legally become a <p>: keep the words,
            // drop the wrapper.
            while ($child->firstChild) {
                $child->parentNode->insertBefore($child->firstChild, $child);
            }
            $child->parentNode->removeChild($child);
            continue;
        }

        blogSanitizeAttributes($child, $tag, $allowed[$tag]);
        blogSanitizeNode($child);
    }
}

/**
 * What a disallowed BLOCK-level tag should become, or null if it is inline.
 *
 * Returning null means "unwrap" — correct for <span> and <font>, where the
 * wrapper carries no structure. Block tags carry the article's shape, so they
 * are converted to the nearest thing on the allowlist instead of being lost.
 */
function blogBlockFallback(string $tag): ?string {
    static $map = [
        'div' => 'p', 'section' => 'p', 'article' => 'p', 'aside' => 'p',
        'main' => 'p', 'header' => 'p', 'footer' => 'p', 'figure' => 'p',
        'figcaption' => 'p', 'address' => 'p', 'pre' => 'p', 'dd' => 'p',
        'dt' => 'p', 'dl' => 'p', 'hr' => 'p',
        // h1 belongs to the page title, never the body; demote rather than drop.
        'h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3',
    ];
    return $map[$tag] ?? null;
}

/**
 * Does this element already contain block-level content?
 *
 * A <div> wrapping two <p>s cannot become a <p> — a paragraph inside a
 * paragraph is invalid, and the browser silently splits it, scrambling the
 * order. Those get unwrapped instead, which is the right answer there.
 */
function blogHasBlockChild(DOMElement $el): bool {
    static $blocks = ['p', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'div'];
    foreach ($el->childNodes as $c) {
        if ($c->nodeType === XML_ELEMENT_NODE && in_array(strtolower($c->nodeName), $blocks, true)) {
            return true;
        }
    }
    return false;
}

/** Replace an element with an identically-populated one under a new tag name. */
function blogRenameElement(DOMElement $el, string $newName): DOMElement {
    $new = $el->ownerDocument->createElement($newName);
    while ($el->firstChild) { $new->appendChild($el->firstChild); }
    $el->parentNode->replaceChild($new, $el);
    return $new;
}

/**
 * Strip every attribute the tag is not explicitly allowed to keep.
 *
 * This is what removes onclick, onerror, onmouseover and the rest without
 * needing to know their names — they are simply not on the list. Iterating a
 * copy again, because removeAttribute mutates the live attributes collection.
 */
function blogSanitizeAttributes(DOMElement $el, string $tag, array $allowedAttrs): void {
    $names = [];
    foreach ($el->attributes as $attr) { $names[] = $attr->nodeName; }

    foreach ($names as $name) {
        if (!in_array(strtolower($name), $allowedAttrs, true)) {
            $el->removeAttribute($name);
        }
    }

    if ($tag !== 'a') { return; }

    // A link is the one attribute value an author controls, so the scheme is
    // checked rather than the string: javascript:, data: and vbscript: all
    // execute, and all of them survive a naive "does it start with http" test
    // once whitespace, tabs or newlines are inserted into the scheme.
    $href = trim($el->getAttribute('href'));
    $href = preg_replace('/[\x00-\x20]/', '', $href);

    $safe = false;
    if ($href !== '') {
        // "//evil.com" also starts with a slash, and a browser resolves it to a
        // fully off-site address. Treating it as same-site let an external link
        // through without the target/rel hardening applied to every other one.
        if (str_starts_with($href, '//')) {
            $safe = false;
        } elseif (str_starts_with($href, '/') || str_starts_with($href, '#')) {
            $safe = true;                       // same-site or in-page anchor
        } else {
            $scheme = strtolower((string)parse_url($href, PHP_URL_SCHEME));
            $safe = in_array($scheme, ['http', 'https', 'mailto'], true);
        }
    }

    if (!$safe) {
        // Keep the words, lose the link.
        $el->removeAttribute('href');
        return;
    }

    $el->setAttribute('href', $href);

    // Anything leaving the site opens in a new tab and cannot reach back
    // through window.opener to rewrite the page it came from.
    if (str_starts_with($href, 'http')) {
        $el->setAttribute('target', '_blank');
        $el->setAttribute('rel', 'noopener noreferrer');
    }
}

/**
 * Turn a stored article body into display HTML.
 *
 * Articles written before the visual editor are plain text, and there are live
 * posts in that format — so the old renderer is kept and chosen automatically.
 * Detection is on a real tag rather than a bare '<', because a plain-text
 * article may legitimately contain "sizes < 40" and must not be mistaken for
 * markup and then have its paragraph breaks discarded.
 */
function blogRenderContent(?string $content, ?string $format = null): string {
    $content = (string)$content;
    if (trim($content) === '') { return ''; }

    // Format is RECORDED, not guessed, whenever the caller knows it.
    //
    // Sniffing the bytes was wrong in a way that destroyed text. A legacy
    // plain-text article containing an ordinary sentence — "anything under
    // <a lakh is a steal" — matched the "this is HTML" test, so it was handed
    // to the sanitiser, which read <a lakh ...> as a tag and threw the words
    // away. Re-saving such a post deleted part of it from the database
    // permanently. The column removes the guess entirely; the sniff below is
    // kept only for rows written before the column existed.
    $format = strtolower(trim((string)$format));
    if ($format === 'html') { return blogSanitizeHtml($content); }
    if ($format === 'text') { return blogRenderPlainText($content); }

    $looksLikeHtml = (bool)preg_match('/<(p|br|h2|h3|ul|ol|li|strong|em|u|a|blockquote)\s*[^>]*>/i', $content)
                  && (bool)preg_match('#</(p|h2|h3|ul|ol|li|strong|em|u|a|blockquote)\s*>#i', $content);

    if ($looksLikeHtml) {
        // Sanitised again on the way out, not only on the way in. Rows written
        // before this file existed, or edited directly in phpMyAdmin, never
        // passed through the save path.
        return blogSanitizeHtml($content);
    }

    return blogRenderPlainText($content);
}

/**
 * The original plain-text renderer, unchanged.
 *
 * Blank line separates paragraphs, a leading "## " is a heading, everything is
 * escaped. Kept byte-identical so the ten articles written before the visual
 * editor render exactly as they always have.
 */
function blogRenderPlainText(string $content): string {
    $out = '';
    foreach (explode("\n\n", $content) as $blockText) {
        $blockText = trim($blockText);
        if ($blockText === '') { continue; }
        if (str_starts_with($blockText, '## ')) {
            $out .= '<h2 class="article-heading">' . htmlspecialchars(trim(substr($blockText, 3))) . '</h2>';
            continue;
        }
        $out .= '<p class="article-para">' . nl2br(htmlspecialchars($blockText)) . '</p>';
    }
    return $out;
}
