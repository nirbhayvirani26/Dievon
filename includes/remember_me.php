<?php
/**
 * ============================================================
 *  Dievon — "Keep me signed in" for customer accounts
 * ============================================================
 *
 * Signing in used to last exactly as long as the browser stayed open, because
 * the session cookie has lifetime 0. Every returning customer had to type their
 * password again, and a 12-character minimum makes that a real deterrent to
 * coming back.
 *
 * ── Why a selector and a validator, rather than one long token ──
 *
 * The obvious design stores a random token and looks the row up by it. That
 * forces a choice between two bad options: store the token in the clear (a
 * database leak hands over every account), or store only its hash (which cannot
 * be looked up without scanning and comparing every row).
 *
 * So the cookie carries two halves, "selector:validator":
 *
 *   selector   — public, indexed, finds exactly one row. Not a secret.
 *   validator  — secret. Only its SHA-256 is stored, and the comparison is
 *                hash_equals(), so neither a leaked table nor the time the
 *                comparison takes gives an attacker anything usable.
 *
 * ── Rotation ──
 *
 * A validator is used once. Every automatic sign-in issues a fresh one and
 * throws the old away, so a cookie copied off a machine stops working the
 * moment either party uses it — and the real owner's next visit invalidates the
 * copy rather than silently coexisting with it.
 *
 * Tokens are destroyed on sign-out, on password change, and on account
 * deletion. A password change that left old cookies alive would defeat the
 * usual reason people change a password.
 */

const REMEMBER_COOKIE = 'dievon_remember';
const REMEMBER_DAYS   = 30;

/**
 * Cookie flags, matched to the session cookie set in config/config.php.
 * secure is conditional for the same reason it is there: MAMP serves plain
 * HTTP, and a secure cookie would never be sent back locally.
 */
function rememberCookieOptions(int $expires): array
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    return [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,   // JS must never be able to read this — it is a password equivalent
        'samesite' => 'Lax',
    ];
}

/** True when the remember-me table has been created by update_new_database.php. */
function rememberTableReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $pdo->query("SELECT 1 FROM customer_remember_tokens LIMIT 1");
        $ready = true;
    } catch (PDOException $e) {
        // The site must keep working on a database that has not run the
        // migration yet; customers simply do not stay signed in.
        $ready = false;
    }
    return $ready;
}

/** Issue a fresh cookie and row. Called on sign-in and on every rotation. */
function rememberMeIssue(PDO $pdo, int $customerId): void
{
    if ($customerId <= 0 || headers_sent() || !rememberTableReady($pdo)) {
        return;
    }

    try {
        $selector  = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = time() + (REMEMBER_DAYS * 86400);

        $pdo->prepare(
            "INSERT INTO customer_remember_tokens (customer_id, selector, validator_hash, expires_at, user_agent)
             VALUES (:cid, :sel, :val, :exp, :ua)"
        )->execute([
            'cid' => $customerId,
            'sel' => $selector,
            'val' => hash('sha256', $validator),
            'exp' => date('Y-m-d H:i:s', $expiresAt),
            'ua'  => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, rememberCookieOptions($expiresAt));

        // setcookie() only queues a header; $_COOKIE still holds the value the
        // browser sent. Anything later in this same request — most importantly
        // logout.php, which runs after the auto-login hook — must see the token
        // that now exists, not the one that was just deleted.
        $_COOKIE[REMEMBER_COOKIE] = $selector . ':' . $validator;

        // Housekeeping, cheap and occasional: expired rows are dead weight and
        // every one of them is a stored credential hash we no longer need.
        if (random_int(1, 20) === 1) {
            $pdo->exec("DELETE FROM customer_remember_tokens WHERE expires_at < NOW()");
        }
    } catch (PDOException $e) {
        error_log('remember-me issue failed: ' . $e->getMessage());
    }
}

/** Drop the token this browser is holding, and clear its cookie. */
function rememberMeForget(PDO $pdo): void
{
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? '';

    if ($raw !== '' && rememberTableReady($pdo)) {
        [$selector] = array_pad(explode(':', $raw, 2), 2, '');
        if ($selector !== '') {
            try {
                $pdo->prepare("DELETE FROM customer_remember_tokens WHERE selector = :sel")
                    ->execute(['sel' => $selector]);
            } catch (PDOException $e) {}
        }
    }

    unset($_COOKIE[REMEMBER_COOKIE]);
    if (!headers_sent()) {
        setcookie(REMEMBER_COOKIE, '', rememberCookieOptions(time() - 42000));
    }
}

/**
 * Drop every token for one customer — used on password change and account
 * deletion, where the point is to end access from devices the customer may no
 * longer have.
 */
function rememberMeForgetAll(PDO $pdo, int $customerId): void
{
    if ($customerId <= 0 || !rememberTableReady($pdo)) {
        return;
    }
    try {
        $pdo->prepare("DELETE FROM customer_remember_tokens WHERE customer_id = :cid")
            ->execute(['cid' => $customerId]);
    } catch (PDOException $e) {}

    unset($_COOKIE[REMEMBER_COOKIE]);
    if (!headers_sent()) {
        setcookie(REMEMBER_COOKIE, '', rememberCookieOptions(time() - 42000));
    }
}

/**
 * Sign the customer in from their cookie, if it is valid. Called once per
 * request from config/db.php, and does nothing at all when there is no cookie,
 * which is the overwhelmingly common case.
 */
function rememberMeAutoLogin(PDO $pdo): void
{
    if (PHP_SAPI === 'cli'
        || isset($_SESSION['customer_id'])
        || session_status() !== PHP_SESSION_ACTIVE
        || empty($_COOKIE[REMEMBER_COOKIE])
        || headers_sent()
    ) {
        return;
    }

    if (!rememberTableReady($pdo)) {
        return;
    }

    $parts = explode(':', (string)$_COOKIE[REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) {
        rememberMeForget($pdo);
        return;
    }
    [$selector, $validator] = $parts;

    // Shape check before touching the database — a malformed cookie is either
    // corruption or someone probing, and neither deserves a query.
    if (!preg_match('/^[a-f0-9]{32}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        rememberMeForget($pdo);
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.customer_id, t.validator_hash, t.expires_at, c.id AS customer_exists
               FROM customer_remember_tokens t
               JOIN customers c ON c.id = t.customer_id
              WHERE t.selector = :sel
              LIMIT 1"
        );
        $stmt->execute(['sel' => $selector]);
        $row = $stmt->fetch();

        // No row, a deleted customer, or an expired token: clear the cookie so
        // the browser stops presenting it on every future request.
        if (!$row || strtotime((string)$row['expires_at']) < time()) {
            rememberMeForget($pdo);
            return;
        }

        if (!hash_equals((string)$row['validator_hash'], hash('sha256', $validator))) {
            // Right selector, wrong secret. That is not a typo — nobody types
            // these — so treat it as a stolen or guessed cookie and revoke
            // every token this customer has, forcing a real sign-in.
            error_log('remember-me validator mismatch for customer ' . (int)$row['customer_id']);
            rememberMeForgetAll($pdo, (int)$row['customer_id']);
            return;
        }

        // Valid. Single-use: this row goes now, and a fresh one replaces it.
        $pdo->prepare("DELETE FROM customer_remember_tokens WHERE id = :id")
            ->execute(['id' => $row['id']]);

        // Same reasoning as the password sign-in path in pages/login.php: the
        // session is gaining privileges, so it gets a new id at that moment.
        session_regenerate_id(true);
        $_SESSION['customer_id'] = (int)$row['customer_id'];

        // Marks the session as restored from a cookie rather than from a typed
        // password. Anything that should demand the password again — changing
        // it, deleting the account — can check this instead of assuming every
        // signed-in session proves who is at the keyboard.
        $_SESSION['auth_via_remember'] = true;

        $custStmt = $pdo->prepare("SELECT name, email FROM customers WHERE id = :cid");
        $custStmt->execute(['cid' => $row['customer_id']]);
        if ($cust = $custStmt->fetch()) {
            $_SESSION['customer_name']  = $cust['name'];
            $_SESSION['customer_email'] = $cust['email'];
        }

        rememberMeIssue($pdo, (int)$row['customer_id']);

        try {
            $pdo->prepare("INSERT INTO customer_logs (customer_id, action, ip_address, user_agent) VALUES (:cid, 'auto_login_remember', :ip, :ua)")
                ->execute([
                    'cid' => $row['customer_id'],
                    'ip'  => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    'ua'  => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]);
        } catch (PDOException $e) {}

    } catch (PDOException $e) {
        error_log('remember-me auto-login failed: ' . $e->getMessage());
    }
}
