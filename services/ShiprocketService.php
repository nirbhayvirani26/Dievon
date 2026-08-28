<?php
/**
 * Dievon – Shiprocket integration
 * ============================================================================
 * Books a shipment for an order that has already been placed and paid for (or
 * accepted as COD). Nothing here runs on its own: bookings are triggered by an
 * owner pressing a button on the Orders screen. An automatic push on order
 * placement would mean a shipment — and a courier charge — for every abandoned
 * or fraudulent order the shop ever takes.
 *
 * Credentials live in .env as SHIPROCKET_EMAIL / SHIPROCKET_PASSWORD, the same
 * file as the Razorpay keys, so they never reach the repository.
 *
 * The auth token is cached in store_settings. Shiprocket issues tokens valid
 * for 10 days and rate-limits the login endpoint; logging in on every request
 * would exhaust that limit on a busy afternoon and leave the shop unable to
 * book anything. Cached for 9 days, one day short, so a token is replaced
 * before it can expire mid-booking.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/EnvLoader.php';

class ShiprocketService
{
    private const BASE      = 'https://apiv2.shiprocket.in/v1/external';
    private const TOKEN_KEY = 'shiprocket_token';
    private const TOKEN_EXP = 'shiprocket_token_expires';
    private const TIMEOUT   = 20;

    private PDO $pdo;
    private string $lastError = '';

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function lastError(): string { return $this->lastError; }

    /** Pincode lookups within one request; a basket never has many. */
    private array $pinCache = [];

    /** Configured at all? Used to hide the button rather than fail on click. */
    public function isConfigured(): bool
    {
        return trim((string)EnvLoader::get('SHIPROCKET_EMAIL', '')) !== ''
            && trim((string)EnvLoader::get('SHIPROCKET_PASSWORD', '')) !== '';
    }

    /* ── HTTP ───────────────────────────────────────────────────────────────
       One place that talks to the network, so a timeout, a non-JSON body and an
       HTTP error are handled identically wherever they happen. Shiprocket
       answers with HTML when it is down, and json_decode on HTML returns null —
       which read as "no error" until this checked for it explicitly. */
    private function request(string $method, string $path, array $body = [], ?string $token = null): ?array
    {
        $ch = curl_init(self::BASE . $path);
        $headers = ['Content-Type: application/json'];
        if ($token !== null) { $headers[] = 'Authorization: Bearer ' . $token; }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }

        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->lastError = 'Could not reach Shiprocket: ' . $cErr;
            return null;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $this->lastError = "Shiprocket replied with something that is not JSON (HTTP $code). The service may be down.";
            return null;
        }
        if ($code >= 400) {
            // Their errors arrive as {message} or {errors:{field:[...]}}; show
            // whichever is present rather than a bare status code.
            $msg = $json['message'] ?? '';
            if (!empty($json['errors']) && is_array($json['errors'])) {
                $bits = [];
                foreach ($json['errors'] as $field => $errs) {
                    $bits[] = $field . ': ' . (is_array($errs) ? implode(', ', $errs) : $errs);
                }
                $msg = ($msg ? $msg . ' — ' : '') . implode('; ', $bits);
            }
            $this->lastError = $msg !== '' ? $msg : "Shiprocket returned HTTP $code";
            return null;
        }
        return $json;
    }

    /* ── Token ─────────────────────────────────────────────────────────── */
    private function token(): ?string
    {
        $cached  = (string)storeSetting($this->pdo, self::TOKEN_KEY, '');
        $expires = (int)storeSetting($this->pdo, self::TOKEN_EXP, 0);
        if ($cached !== '' && $expires > time()) { return $cached; }

        $res = $this->request('POST', '/auth/login', [
            'email'    => (string)EnvLoader::get('SHIPROCKET_EMAIL', ''),
            'password' => (string)EnvLoader::get('SHIPROCKET_PASSWORD', ''),
        ]);
        if (!$res || empty($res['token'])) {
            if ($this->lastError === '') { $this->lastError = 'Shiprocket did not return a token.'; }
            return null;
        }
        $this->putSetting(self::TOKEN_KEY, $res['token']);
        $this->putSetting(self::TOKEN_EXP, (string)(time() + 9 * 86400));
        return $res['token'];
    }

    private function putSetting(string $k, string $v): void
    {
        try {
            $this->pdo->prepare("INSERT INTO store_settings (setting_key, setting_value) VALUES (:k, :v)
                                 ON DUPLICATE KEY UPDATE setting_value = :v2")
                      ->execute([':k' => $k, ':v' => $v, ':v2' => $v]);
        } catch (PDOException $e) {
            error_log('Shiprocket: could not cache ' . $k . ' — ' . $e->getMessage());
        }
    }
    /* ── Weight and box size ────────────────────────────────────────────────
       Shiprocket REFUSES an order without weight, length, breadth and height.
       Not one of the fifteen live products carries either value, so a booking
       built from product data alone would fail every time — which is why this
       integration sat unbuilt.

       Resolved most-specific first:
         1. the product's own weight/dimensions, where the owner has filled them in
         2. the shop default in Store Settings
         3. a built-in fallback, so a booking is never blocked by a blank field

       The built-in figures suit Indian womenswear packed flat in a poly mailer:
       0.4kg and 30x25x5cm. They are a starting point, NOT a measurement.
       Shiprocket bills on whichever is greater, actual or volumetric weight, so
       an under-declared parcel is re-weighed at the hub and the difference
       charged back. Put real numbers in Store Settings before shipping volume.

       Weights ADD across an order's items; the box only needs to fit the
       largest single piece, so dimensions take the maximum rather than the sum.
       Two kurtis in one mailer weigh twice as much without being twice as long. */
    public const FALLBACK_WEIGHT_KG = 0.4;
    public const FALLBACK_BOX_CM    = ['length' => 30, 'breadth' => 25, 'height' => 5];

    private function parseDimensions(?string $raw): ?array
    {
        $raw = trim((string)$raw);
        if ($raw === '') { return null; }
        /* Accepts "30x25x5", "30 x 25 x 5 cm", "30*25*5" — the forms an owner
           actually types. Anything else is ignored rather than half-read, so a
           note like "small box" cannot become a 0cm dimension. */
        if (!preg_match('/(\d+(?:\.\d+)?)\s*[x*\/]\s*(\d+(?:\.\d+)?)\s*[x*\/]\s*(\d+(?:\.\d+)?)/i', $raw, $m)) {
            return null;
        }
        return ['length' => (float)$m[1], 'breadth' => (float)$m[2], 'height' => (float)$m[3]];
    }

    /** Total weight (kg) and box size (cm) for one order's items. */
    public function parcelFor(array $items): array
    {
        $defaultWeight = (float)storeSetting($this->pdo, 'default_parcel_weight_kg', self::FALLBACK_WEIGHT_KG);
        $defaultBox    = $this->parseDimensions((string)storeSetting($this->pdo, 'default_parcel_dimensions_cm', ''))
                         ?: self::FALLBACK_BOX_CM;

        $weight = 0.0;
        $box    = ['length' => 0.0, 'breadth' => 0.0, 'height' => 0.0];
        $guessed = [];

        foreach ($items as $it) {
            $qty = max(1, (int)($it['quantity'] ?? 1));
            $pid = (int)($it['product_id'] ?? 0);
            $w = null; $d = null;

            if ($pid > 0) {
                try {
                    $q = $this->pdo->prepare("SELECT weight, dimensions FROM products WHERE id = :id");
                    $q->execute([':id' => $pid]);
                    if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                        $w = ((float)$row['weight'] > 0) ? (float)$row['weight'] : null;
                        $d = $this->parseDimensions($row['dimensions'] ?? null);
                    }
                } catch (PDOException $e) { /* fall through to the defaults */ }
            }

            if ($w === null) { $w = $defaultWeight; $guessed[] = (string)($it['name'] ?? 'item'); }
            if ($d === null) { $d = $defaultBox; }

            $weight += $w * $qty;
            foreach (['length', 'breadth', 'height'] as $k) {
                $box[$k] = max($box[$k], (float)$d[$k]);
            }
        }

        return [
            'weight'  => round(max(0.1, $weight), 3),   // Shiprocket rejects 0
            'length'  => max(1, (float)$box['length']),
            'breadth' => max(1, (float)$box['breadth']),
            'height'  => max(1, (float)$box['height']),
            /* Named, not counted: the admin screen can then say WHICH garments
               were estimated, so the owner knows what to go and measure. A bare
               "3 items estimated" leaves them checking all of them. */
            'estimated_for' => array_values(array_unique($guessed)),
        ];
    }
    /* ── Book one order ─────────────────────────────────────────────────────
       Called from admin/shiprocket_handler.php when the owner presses the
       button. Never from checkout: a shipment booked the moment an order is
       placed is a courier charge for every abandoned and fraudulent order the
       shop ever takes, and Shiprocket bills whether or not the parcel moves.

       payment_method must read exactly "COD" or "Prepaid"; anything else is
       rejected. An order marked Paid is Prepaid whatever it was paid with —
       Shiprocket is being told whether the DRIVER collects money, not which
       gateway was used. Getting this wrong means a courier either asks the
       customer for money they have already paid, or hands over the parcel and
       collects nothing.

       The address is split back into first/last name because Shiprocket
       requires both. A single-word name gives a last name of "." rather than
       an empty string, which their validator refuses. */
    /**
     * City and state for an Indian pincode.
     *
     * The shop never asks for either. Checkout collects one free-text address
     * line, a pincode and a phone number, and the orders table stores exactly
     * those — so the two fields Shiprocket requires had nowhere to come from and
     * were filled from a pair of shop-wide settings instead. Left blank, every
     * order reached Shiprocket unshippable; filled in, every parcel carried the
     * same city no matter where the customer lived.
     *
     * An Indian pincode names its city and state on its own, and Shiprocket will
     * tell us which. Looked up per order, so a Mumbai customer is labelled Mumbai
     * whatever the shop's own address says.
     *
     * Returns null on any doubt — no token, no network, an unknown pincode, a
     * response in a shape we did not expect. The caller falls back to the
     * configured defaults, so a lookup that cannot answer costs nothing beyond
     * the behaviour that was already there.
     */
    public function locationForPincode(string $pincode, ?string $token = null): ?array
    {
        $pin = preg_replace('/\D+/', '', $pincode);
        if (strlen($pin) !== 6) { return null; }

        /* array_key_exists, not isset: a pincode Shiprocket does not know caches
           as null, and isset() reads a stored null as absent — so the miss was
           looked up again on every item in the basket. */
        if (array_key_exists($pin, $this->pinCache)) { return $this->pinCache[$pin]; }

        $token = $token ?? $this->token();
        if ($token === null) { return null; }

        $res = $this->request('GET', '/open/postcode/details?postcode=' . $pin, [], $token);

        /* Their payload has moved between a flat object and one nested under
           postcode_details across versions, so read both rather than trust one. */
        $row = $res['postcode_details'] ?? $res;
        $city  = trim((string)($row['city']  ?? ''));
        $state = trim((string)($row['state'] ?? ($row['state_name'] ?? '')));

        $out = ($city !== '' && $state !== '') ? ['city' => $city, 'state' => $state] : null;
        $this->pinCache[$pin] = $out;
        return $out;
    }

    public function bookOrder(array $order, array $items): ?array
    {
        $token = $this->token();
        if ($token === null) { return null; }

        $parcel = $this->parcelFor($items);

        $name  = trim((string)($order['customer_name'] ?? ''));
        $parts = preg_split('/\s+/', $name, 2);
        $first = $parts[0] ?? 'Customer';
        $last  = trim($parts[1] ?? '') !== '' ? $parts[1] : '.';

        $paid = strcasecmp((string)($order['payment_status'] ?? ''), 'Paid') === 0;

        $lines = [];
        $sub   = 0.0;
        foreach ($items as $it) {
            $qty   = max(1, (int)($it['quantity'] ?? 1));
            $price = (float)($it['price'] ?? 0);
            $sub  += $price * $qty;
            $lines[] = [
                'name'          => mb_substr((string)($it['name'] ?? 'Item'), 0, 90),
                'sku'           => (string)($it['sku'] ?? ('DV-' . (int)($it['product_id'] ?? 0))),
                'units'         => $qty,
                'selling_price' => round($price, 2),
            ];
        }

        /* The customer's own city and state, from their pincode, falling back to
           the shop-wide settings when the lookup cannot answer. Both blank is
           what put an order into Shiprocket that it would accept and then refuse
           to ship, so bookOrder() stops rather than creating another one — see
           the guard below. */
        $pin   = (string)($order['postcode'] ?? '');
        $loc   = $this->locationForPincode($pin, $token);
        $city  = $loc['city']  ?? (string)storeSetting($this->pdo, 'shiprocket_default_city', '');
        $state = $loc['state'] ?? (string)storeSetting($this->pdo, 'shiprocket_default_state', '');

        if (trim($city) === '' || trim($state) === '') {
            $this->lastError = 'No city or state for pincode "' . $pin . '". Shiprocket requires both and '
                . 'the shop does not collect them, so they are looked up from the pincode with the Shipping '
                . 'settings as a fallback. Set a default city and state in Settings > Shipping, or correct '
                . 'the pincode on this order. Nothing was sent.';
            return null;
        }

        $payload = [
            'order_id'               => (string)$order['order_code'],
            'order_date'             => date('Y-m-d H:i', strtotime((string)$order['created_at'])),
            'pickup_location'        => (string)storeSetting($this->pdo, 'shiprocket_pickup_location', 'Primary'),
            'billing_customer_name'  => $first,
            'billing_last_name'      => $last,
            'billing_address'        => (string)($order['address'] ?? ''),
            'billing_city'           => $city,
            'billing_pincode'        => $pin,
            'billing_state'          => $state,
            'billing_country'        => 'India',
            'billing_email'          => (string)($order['customer_email'] ?? ''),
            'billing_phone'          => preg_replace('/\D+/', '', (string)($order['phone'] ?? '')),
            'shipping_is_billing'    => true,
            'order_items'            => $lines,
            'payment_method'         => $paid ? 'Prepaid' : 'COD',
            'sub_total'              => round($sub, 2),
            'length'                 => $parcel['length'],
            'breadth'                => $parcel['breadth'],
            'height'                 => $parcel['height'],
            'weight'                 => $parcel['weight'],
        ];

        $res = $this->request('POST', '/orders/create/adhoc', $payload, $token);
        if (!$res) { return null; }

        return [
            'shiprocket_order_id' => $res['order_id']    ?? null,
            'shipment_id'         => $res['shipment_id'] ?? null,
            'status'              => $res['status']      ?? '',
            'awb'                 => $res['awb_code']    ?? null,
            'parcel'              => $parcel,
        ];
    }

    /** The payload only, for checking a booking before it is sent. */
    public function previewPayload(array $order, array $items): array
    {
        return ['parcel' => $this->parcelFor($items), 'items' => count($items)];
    }
}
