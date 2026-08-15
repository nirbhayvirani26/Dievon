<?php
/**
 * Dievon – Time-based one-time passwords (RFC 6238).
 *
 * Implemented here rather than pulled in as a dependency: the whole algorithm
 * is base32 + HMAC-SHA1 + a truncation, and PHP has both built in. Adding a
 * composer dependency to a project with no composer.json would be a bigger
 * change than the code it replaces.
 *
 * Authenticator app, never SMS. SIM-swap attacks make SMS the weakest common
 * second factor, and it is the one people assume is safest.
 */
final class Totp
{
    private const PERIOD  = 30;   // seconds per code, per RFC 6238 and every app
    private const DIGITS  = 6;
    private const ALGO    = 'sha1';
    private const B32     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh 160-bit secret, base32 encoded (the length every app expects). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * The URI an authenticator app scans or accepts pasted.
     *
     * The issuer appears BOTH as a path prefix and as a parameter — older apps
     * read one, newer ones the other, and getting it wrong means the entry
     * shows up unlabelled among a dozen others.
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    /** The code for a given moment (defaults to now). */
    public static function codeAt(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), self::PERIOD);
        $key = self::base32Decode($secret);
        if ($key === '') { return ''; }

        // Counter as a 64-bit big-endian integer.
        $binCounter = pack('N*', 0, $counter);
        $hash = hash_hmac(self::ALGO, $binCounter, $key, true);

        // Dynamic truncation: the low nibble of the last byte picks the offset.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;

        return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Is this code valid right now?
     *
     * $window of 1 accepts the previous and next code as well as the current
     * one, covering roughly ±30 seconds of clock drift between the phone and
     * the server. Without it, a phone a few seconds fast is simply rejected and
     * the customer concludes 2FA is broken.
     *
     * hash_equals, not ===, so a wrong code cannot be narrowed down by timing.
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) { return false; }

        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = self::codeAt($secret, $now + ($i * self::PERIOD));
            if ($candidate !== '' && hash_equals($candidate, $code)) { return true; }
        }
        return false;
    }

    /**
     * Recovery codes, for the day the phone is lost or replaced.
     *
     * Without these, losing a phone means editing the database by hand to get
     * back into your own shop.
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            // Grouped for legibility when written down.
            $codes[] = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
        }
        return $codes;
    }

    public static function base32Encode(string $data): string
    {
        if ($data === '') { return ''; }
        $bits = '';
        foreach (str_split($data) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        // Strip the spaces people add when typing a secret in by hand, and the
        // '=' padding some apps show.
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
        if ($b32 === '') { return ''; }

        $bits = '';
        foreach (str_split($b32) as $ch) {
            $pos = strpos(self::B32, $ch);
            if ($pos === false) { return ''; }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) { $out .= chr(bindec($chunk)); }
        }
        return $out;
    }
}
