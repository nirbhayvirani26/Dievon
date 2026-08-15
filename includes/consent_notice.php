<?php
/**
 * Dievon – Cookie consent notice.
 *
 * Deliberately dormant. It renders NOTHING while no analytics or advertising
 * tracker is configured, which is the case today: the site sets a session cookie
 * for the shopping bag and a CSRF token, both strictly necessary, and nothing
 * else. Putting a consent banner in front of zero trackers is a click the
 * shopper does not owe anyone, and it trains people to dismiss the real one.
 *
 * The moment META_PIXEL_ID (or any future tracker) is filled in,
 * dievonTrackersConfigured() flips true and this appears — and until the visitor
 * answers, dievonMayLoadTrackers() stays false, so the tracker does not fire.
 * The gate is in the code rather than in a comment someone has to remember.
 *
 * The choice is stored in a first-party cookie for a year. "Denied" is stored
 * too, not just "granted", so someone who says no is not asked on every page.
 */

if (!function_exists('dievonNeedsConsentNotice') || !dievonNeedsConsentNotice()) {
    return;
}
?>
<div class="consent-notice" id="consentNotice" role="dialog" aria-live="polite"
     aria-labelledby="consentNoticeTitle" aria-describedby="consentNoticeBody">
    <div class="consent-notice-inner">
        <div>
            <h2 class="consent-notice-title" id="consentNoticeTitle">Cookies on this site</h2>
            <p class="consent-notice-body" id="consentNoticeBody">
                We need a couple of cookies to keep your shopping bag and your sign-in working —
                those are always on. We would also like to use analytics and advertising cookies to
                understand how the site is used. Those only run if you say yes.
                <a href="<?= SITE_URL ?>/privacy#cookies">Read our cookie notice</a>.
            </p>
        </div>
        <div class="consent-notice-actions">
            <button type="button" class="btn-luxury-outline" data-consent="denied">Only essential</button>
            <button type="button" class="btn-luxury" data-consent="granted">Accept all</button>
        </div>
    </div>
</div>

<script>
(function () {
    var notice = document.getElementById('consentNotice');
    if (!notice) { return; }

    notice.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-consent]');
        if (!btn) { return; }
        var choice = btn.getAttribute('data-consent');

        // First-party, one year, SameSite=Lax. Lax rather than Strict for the same
        // reason the session cookie uses it: the shopper returns to this site from
        // the payment gateway, and a Strict cookie is withheld on that navigation.
        var secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = 'dievon_consent=' + choice
            + '; path=/; max-age=' + (60 * 60 * 24 * 365) + '; SameSite=Lax' + secure;

        notice.remove();

        // Only a "yes" needs the page again — the trackers are rendered server-side
        // and there is nothing to load for a "no".
        if (choice === 'granted') { location.reload(); }
    });
})();
</script>
