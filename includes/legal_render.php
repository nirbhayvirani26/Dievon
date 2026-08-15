<?php
/**
 * Dievon – renderer for the policy pages.
 *
 * The privacy notice and the terms are long documents that need to stay
 * readable, be editable without touching markup, and — critically — must never
 * state a fact about the business that nobody has supplied. So they are stored
 * as plain text with two conventions:
 *
 *   "## " at the start of a line   → a section heading (and an anchor)
 *   "- "  at the start of a line   → a bullet
 *   anything else                  → a paragraph
 *
 * and {{TOKENS}} for the facts only the owner knows. A token with a value
 * prints it; a token without one prints a marked gap, so an unfilled field is
 * visible on the page rather than silently reading as though it were answered.
 *
 * Everything is escaped. Nothing in the source text can inject markup.
 */

/**
 * Substitute the {{TOKENS}} in a policy document.
 *
 * Returns HTML-safe fragments, so this must run AFTER escaping — hence the
 * placeholder dance in legalRenderDocument().
 */
function legalTokenMap(?PDO $pdo): array
{
    $id = legalIdentity($pdo);
    return [
        'LEGAL_NAME'  => legalValue($id['legal_name'],   'registered legal name'),
        'ADDRESS'     => legalValue($id['address'],      'registered address'),
        'GSTIN'       => legalValue($id['gstin'],        'GSTIN'),
        'CIN'         => legalValue($id['cin'],          'CIN'),
        'EMAIL'       => legalValue($id['email'],        'contact email'),
        'PHONE'       => legalValue($id['phone'],        'contact phone'),
        'GRIEVANCE'   => legalValue($id['grievance_name'],  'grievance officer name'),
        'GRIEVANCE_EMAIL' => legalValue($id['grievance_email'], 'grievance officer email'),
        'GRIEVANCE_PHONE' => legalValue($id['grievance_phone'], 'grievance officer phone'),
        'COURIERS'    => legalValue($id['couriers'],     'courier partners'),
        'JURISDICTION_CITY' => legalValue($id['jurisdiction'], 'jurisdiction city'),
        'TRADING_NAME' => '<span class="legal-value">' . htmlspecialchars($id['trading_name']) . '</span>',
        'RETURN_DAYS' => '<span class="legal-value">' . (int)RETURN_WINDOW_DAYS . '</span>',
        // Money read from Store Settings, so the terms cannot quote a delivery
        // charge that differs from the one checkout actually applies.
        'DELIVERY_FEE' => '<span class="legal-value">'
            . htmlspecialchars(formatPrice((float)storeSetting($pdo, 'standard_shipping_fee', 99))) . '</span>',
        'FREE_OVER'    => '<span class="legal-value">'
            . htmlspecialchars(formatPrice((float)storeSetting($pdo, 'free_shipping_min', 2000))) . '</span>',
    ];
}

/** Anchor id for a heading, so the table of contents and deep links work. */
function legalAnchor(string $heading): string
{
    return 'sec-' . slugify($heading, 60);
}

/**
 * Turn the plain-text policy source into HTML.
 *
 * Returns ['html' => string, 'toc' => [['id','title'], …]].
 */
function legalRenderDocument(string $source, ?PDO $pdo): array
{
    $tokens = legalTokenMap($pdo);

    // Escape first, then swap the tokens in. Doing it the other way round would
    // escape the markup legalValue() produces.
    $escapeAndFill = function (string $text) use ($tokens): string {
        $out = htmlspecialchars($text);
        foreach ($tokens as $name => $replacement) {
            $out = str_replace(htmlspecialchars('{{' . $name . '}}'), $replacement, $out);
            $out = str_replace('{{' . $name . '}}', $replacement, $out);
        }
        // Any token we do not know about must not survive to the page.
        $out = preg_replace('/\{\{[A-Z_]+\}\}/',
            '<span class="legal-missing">[to be added]</span>', $out);
        return $out;
    };

    $html = '';
    $toc  = [];
    $listOpen = false;

    foreach (preg_split("/\r\n|\n|\r/", $source) as $line) {
        $line = rtrim($line);

        // Editor notes at the top of the source file are not published.
        if (str_starts_with($line, '#') && !str_starts_with($line, '## ')) { continue; }

        if ($line === '') {
            if ($listOpen) { $html .= "</ul>\n"; $listOpen = false; }
            continue;
        }

        if (str_starts_with($line, '## ')) {
            if ($listOpen) { $html .= "</ul>\n"; $listOpen = false; }
            $heading = trim(substr($line, 3));
            $id = legalAnchor($heading);
            // Sub-points were drafted with "## " as well. A contents list of
            // fifty entries helps nobody, so anything marked with "### " (or a
            // heading shorter than a real section title) renders as an h3 and
            // stays out of the contents.
            $isSub = str_starts_with($heading, '# ');
            if ($isSub) { $heading = trim(substr($heading, 2)); }
            if ($isSub) {
                $html .= '<h3 id="' . htmlspecialchars($id) . '">' . $escapeAndFill($heading) . "</h3>\n";
            } else {
                $toc[] = ['id' => $id, 'title' => $heading];
                $html .= '<h2 id="' . htmlspecialchars($id) . '">' . $escapeAndFill($heading) . "</h2>\n";
            }
            continue;
        }

        if (str_starts_with($line, '- ')) {
            if (!$listOpen) { $html .= "<ul>\n"; $listOpen = true; }
            $html .= '<li>' . $escapeAndFill(trim(substr($line, 2))) . "</li>\n";
            continue;
        }

        if ($listOpen) { $html .= "</ul>\n"; $listOpen = false; }
        $html .= '<p>' . $escapeAndFill($line) . "</p>\n";
    }
    if ($listOpen) { $html .= "</ul>\n"; }

    return ['html' => $html, 'toc' => $toc];
}

/**
 * Render a complete policy page body: gap notice, last-updated line, contents,
 * then the document.
 */
function legalRenderPage(string $source, ?PDO $pdo, string $lastUpdated, array $summary = []): string
{
    $doc  = legalRenderDocument($source, $pdo);
    $gaps = legalIdentityGaps($pdo);

    ob_start();
    ?>
    <div class="legal-page">
        <?php if ($gaps): ?>
        <?php // Visible to shoppers on purpose. A policy page with the business's
              // own identity missing is not finished, and hiding that fact from
              // the page makes it easy to leave unfinished for months. ?>
        <div class="legal-gap-notice">
            <strong>This page is not finished.</strong>
            <?= htmlspecialchars(implode(', ', $gaps)) ?>
            <?= count($gaps) === 1 ? 'has' : 'have' ?> not been filled in yet.
            Please <a href="<?= SITE_URL ?>/contact">contact us</a> if you need any of this
            before ordering.
        </div>
        <?php endif; ?>

        <p class="legal-updated">Last updated: <?= htmlspecialchars($lastUpdated) ?></p>

        <?php if ($summary): ?>
        <?php // The short version, for someone who does not want to read the rest. ?>
        <div class="legal-summary">
            <h2>The short version</h2>
            <ul>
                <?php foreach ($summary as $point): ?>
                <li><?= $point /* trusted: written in the page file, not user input */ ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="legal-summary-note">This summary is here to save you time. The full terms below are what actually applies.</p>
        </div>
        <?php endif; ?>

        <?php // Printed plainly, not in accordions.
              //
              // These pages were collapsed section-by-section when the privacy notice
              // ran to 5,800 words. Cut to about 1,200 there is nothing to hide — and
              // a policy behind ten clicks is a policy nobody reads, which defeats the
              // point of publishing one. ?>
        <?= $doc['html'] ?>
    </div>
    <?php
    return (string)ob_get_clean();
}
