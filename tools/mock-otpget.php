<?php
/**
 * A fake OTPGet, for development only. Never upload this file.
 *
 *   php -S localhost:8001 tools/mock-otpget.php
 *
 * then set the OTPGet base URL in the admin to http://localhost:8001/stubs
 * and use any key of eight characters or more ("BAD_KEY_TEST" is refused).
 *
 * It speaks the protocol the documentation describes - plain text for money,
 * JSON for listings, a plain-text code for every refusal - closely enough
 * that the shop cannot tell the difference, and it keeps its numbers in a
 * JSON file in the system temp folder so they survive between requests.
 *
 * Knobs, as environment variables when the server is started:
 *
 *   MOCK_SMS_DELAY=20      seconds after purchase before the code arrives
 *   MOCK_CANCEL_AFTER=10   seconds after purchase before a cancel is allowed
 *   MOCK_LATENCY_MS=400    how long every reply takes, like the real network
 *
 * To see requests that go out together (the catalogue sync) arrive together,
 * give the built-in server workers: PHP_CLI_SERVER_WORKERS=16 php -S ...
 *
 * And a few choices that misbehave on purpose:
 *
 *   country "Antarctica"  NO_NUMBERS on every purchase
 *   Indonesia, server 1   NO_NUMBERS, while the other servers have stock
                           (exercises buying from another server instead)
 *   service "Netflix"     the SMS never arrives (exercises expiry and change number)
 *   service "Tinder"      ERROR_PROVIDER_1 on purchase
 */

declare(strict_types=1);

$path  = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$query = $_GET + $_POST;
$state = sys_get_temp_dir() . '/mock-otpget-state.json';

$smsDelay    = (int) (getenv('MOCK_SMS_DELAY') ?: 20);
$cancelAfter = (int) (getenv('MOCK_CANCEL_AFTER') ?: 10);

if (($latency = (int) getenv('MOCK_LATENCY_MS')) > 0) {
    usleep($latency * 1000);
}

header('Content-Type: text/plain; charset=utf-8');

$text = static function (string $body): never { echo $body; exit; };
$json = static function ($data): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$load = static function () use ($state): array {
    $data = is_file($state) ? json_decode((string) file_get_contents($state), true) : null;
    return is_array($data) ? $data : ['next' => 1000, 'orders' => [], 'balance' => 5000.0];
};
$save = static function (array $data) use ($state): void {
    file_put_contents($state, json_encode($data), LOCK_EX);
};

$key = (string) ($query['api_key'] ?? '');
if (strlen($key) < 8 || $key === 'BAD_KEY_TEST') {
    $text('BAD_KEY');
}

// -------------------------------------------------------------------------
// The catalogue. Prices in PKR; each server sells a slightly different set.
// -------------------------------------------------------------------------
$countries = [
    '1' => [['1', 'Pakistan'], ['2', 'India'], ['3', 'United States of America'], ['4', 'United Kingdom'],
            ['5', 'Indonesia'], ['6', 'Russia'], ['7', 'Canada'], ['8', 'Brazil'], ['9', 'Philippines'],
            ['10', 'Bangladesh'], ['11', 'Nigeria'], ['12', 'Antarctica']],
    '2' => [['21', 'Pakistan'], ['22', 'Indonesia'], ['23', 'Vietnam'], ['24', 'Kenya'], ['25', 'Malaysia']],
    '3' => [['31', 'United States'], ['32', 'Germany'], ['33', 'France'], ['34', 'Netherlands'], ['35', 'Spain']],
    '4' => [['41', 'Saudi Arabia'], ['42', 'UAE'], ['43', 'Egypt'], ['44', 'Turkey']],
    '5' => [],
    '6' => [['61', 'Pakistan'], ['62', 'Thailand'], ['63', 'Colombia']],
];
$services = [
    ['12', 'Telegram', 45], ['13', 'WhatsApp', 60], ['14', 'Google', 30], ['15', 'Facebook', 25],
    ['16', 'Instagram', 28], ['17', 'TikTok', 22], ['18', 'Snapchat', 20], ['19', 'Twitter', 24],
    ['20', 'Amazon', 35], ['21', 'Netflix', 40], ['22', 'Uber', 26], ['23', 'Microsoft', 18],
    ['24', 'Discord', 21], ['25', 'Tinder', 55], ['26', 'OpenAI / ChatGPT', 70], ['27', 'Viber', 19],
    ['28', 'Signal', 33], ['29', 'PayPal', 65], ['30', 'Binance', 80], ['31', 'Yahoo', 15],
];

$priceFor = static function (string $type, string $country, int $base): float {
    // Deterministic spread so the same offer keeps its price between syncs.
    $factor = 0.7 + (crc32($type . '-' . $country) % 90) / 100;
    return round($base * $factor * (1 + ((int) $type - 1) * 0.15), 2);
};

$countryName = static function (string $type, string $id) use ($countries): string {
    foreach ($countries[$type] ?? [] as [$cid, $name]) {
        if ($cid === $id) {
            return $name;
        }
    }
    return '';
};

$serviceRow = static function (string $id) use ($services): ?array {
    foreach ($services as $row) {
        if ($row[0] === $id) {
            return $row;
        }
    }
    return null;
};

$premiumCountries = [['1', 'Pakistan'], ['2', 'Indonesia'], ['3', 'United Kingdom']];
$cheap = [
    '1' => [['1', 'Guinea WhatsApp', 50.0], ['2', 'Pakistan Telegram', 30.0], ['3', 'Indonesia Facebook', 12.0]],
    '2' => [['7', 'USA Google', 40.0]],
];

$newOrder = static function (string $product, string $serviceName, float $price, string $prefix)
    use ($load, $save): array {
    $data = $load();
    $id   = ($product === 'sms' ? '' : strtoupper($product) . '_') . bin2hex(random_bytes(4)) . $data['next']++;
    $data['orders'][$id] = [
        'product' => $product, 'service' => $serviceName, 'created' => time(),
        'status' => 'wait', 'code' => '', 'phone' => $prefix . random_int(100000000, 999999999),
        'price' => $price,
    ];
    $data['balance'] -= $price;
    $save($data);
    return [$id, $data['orders'][$id]];
};

$statusOf = static function (string $id) use ($load, $save, $smsDelay): ?array {
    $data  = $load();
    $order = $data['orders'][$id] ?? null;
    if ($order === null) {
        return null;
    }
    $age = time() - (int) $order['created'];
    if ($order['status'] === 'wait' && $order['service'] !== 'Netflix' && $age >= $smsDelay) {
        $order['status'] = 'ok';
        $order['code']   = (string) random_int(100000, 999999);
    }
    // Premium and cheap numbers lapse after their validity and refund themselves.
    if ($order['status'] === 'wait' && $order['product'] !== 'sms' && $age > 1200) {
        $order['status'] = 'cancel';
        $data['balance'] += $order['price'];
    }
    $data['orders'][$id] = $order;
    $save($data);
    return $order;
};

$setStatus = static function (string $id, int $status) use ($load, $save, $statusOf, $cancelAfter, $text): never {
    $order = $statusOf($id);
    if ($order === null) {
        $text('NO_ACTIVATION');
    }
    $data = $load();
    if ($status === 8) {
        if ($order['status'] === 'ok') {
            $text($order['product'] === 'sms' ? 'STATUS_ALREADY_CHANGED' : 'BAD_STATUS');
        }
        if ($order['status'] === 'cancel') {
            $text($order['product'] === 'sms' ? 'STATUS_ALREADY_CHANGED' : 'ACCESS_READY');
        }
        if ($order['product'] === 'sms' && time() - (int) $order['created'] < $cancelAfter) {
            $text('EARLY_CANCEL_DENIED');
        }
        $data['orders'][$id]['status'] = 'cancel';
        $data['balance'] += $order['price'];
        $save($data);
        $text('ACCESS_READY');
    }
    if ($status === 3 || $status === 6) {
        if ($order['status'] !== 'ok') {
            $text('NO_SMS_RECEIVED');
        }
        $data['orders'][$id]['status'] = 'done';
        $save($data);
        $text('ACCESS_FINISH');
    }
    $text('BAD_STATUS');
};

$action = (string) ($query['action'] ?? '');
$file   = basename($path);

// -------------------------------------------------------------------------
// SMS OTP API
// -------------------------------------------------------------------------
if ($file === 'handler_api.php') {
    switch ($action) {
        case 'getBalance':
            $text('ACCESS_BALANCE:' . number_format($load()['balance'], 2, '.', ''));
        case 'getCurrencies':
            $json(['status' => '200', 'default' => 'PKR', 'count' => 2, 'currencies' => [
                ['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => 'Rs', 'rate' => 1],
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'rate' => 0.0036]]]);
        case 'getServers':
            $servers = [];
            foreach (array_keys($countries) as $type) {
                $servers[] = ['id' => (string) $type, 'active' => $countries[$type] !== []];
            }
            $json(['status' => '200', 'count' => count($servers), 'servers' => $servers]);
        case 'getCountries':
            $type = (string) ($query['type'] ?? '');
            if ($type === '') {
                $all = [];
                foreach ($countries as $t => $rows) {
                    $all[$t] = array_map(fn ($r) => ['id' => $r[0], 'name' => $r[1]], $rows);
                }
                $json($all);
            }
            if (!isset($countries[$type])) {
                $json([]);
            }
            $json([$type => array_map(fn ($r) => ['id' => $r[0], 'name' => $r[1]], $countries[$type])]);
        case 'getServices':
            $type    = (string) ($query['type'] ?? '1');
            $country = (string) ($query['country'] ?? '');
            if ($country === '') {
                $text('BAD_COUNTRY');
            }
            if (!isset($countries[$type])) {
                $text('BAD_TYPE');
            }
            if ($countryName($type, $country) === '') {
                $json([]);
            }
            $out = [];
            foreach ($services as $i => [$id, $name, $base]) {
                // Not every country sells everything.
                if ((crc32($type . $country . $id) % 5) === 0) {
                    continue;
                }
                $out[$id] = $name . ' - ' . number_format($priceFor($type, $country, $base), 2, '.', '') . ' PKR';
            }
            $json($out);
        case 'getNumber':
            $type    = (string) ($query['type'] ?? '1');
            $country = (string) ($query['country'] ?? '');
            $service = $serviceRow((string) ($query['service'] ?? ''));
            $name    = $countryName($type, $country);
            if ($name === '') {
                $text('BAD_COUNTRY');
            }
            if ($service === null) {
                $text('BAD_SERVICE');
            }
            if ($name === 'Antarctica' || ($name === 'Indonesia' && $type === '1')) {
                $text('NO_NUMBERS');
            }
            if ($service[1] === 'Tinder') {
                $text('ERROR_PROVIDER_' . $type . ':Server ' . $type . ' error');
            }
            $price = $priceFor($type, $country, (int) $service[2]);
            if ($load()['balance'] < $price) {
                $text('NO_BALANCE');
            }
            [$id, $order] = $newOrder('sms', $service[1], $price, '92300');
            $text('ACCESS_NUMBER:' . $id . ':' . $order['phone'] . ':' . number_format($price, 2, '.', ''));
        case 'getStatus':
        case 'setStatus':
            break;
        default:
            $text('');
    }
}

// -------------------------------------------------------------------------
// Premium
// -------------------------------------------------------------------------
if ($file === 'premium-handler.php') {
    switch ($action) {
        case 'getBalance':
            $text('ACCESS_BALANCE:' . number_format($load()['balance'], 2, '.', ''));
        case 'getServers':
            $out = [];
            foreach ($premiumCountries as [$id, $name]) {
                $out[$id] = $name . ' — from Rs' . number_format(90 + (int) $id * 40, 2, '.', '');
            }
            $json($out);
        case 'getServices':
            $server = (string) ($query['server'] ?? '');
            if ($server === '') {
                $text('BAD_SERVER');
            }
            $out = [];
            foreach (array_slice($services, 0, 8) as [$id, $name, $base]) {
                $out[$id] = $name . ' - Rs' . number_format($base * 2 + (int) $server * 10, 2, '.', '');
            }
            $json($out);
        case 'buyNumber':
            $server  = (string) ($query['server'] ?? '');
            $service = $serviceRow((string) ($query['service'] ?? ''));
            if ($server === '') {
                $text('BAD_SERVER');
            }
            if ($service === null) {
                $text('BAD_SERVICE');
            }
            [$id, $order] = $newOrder('premium', $service[1], (float) $service[2] * 2, '44700');
            $text($id . ',' . $order['phone'] . ',1200');
        case 'getStatus':
        case 'setStatus':
            break;
        default:
            $text('BAD_ACTION');
    }
}

// -------------------------------------------------------------------------
// Cheap numbers
// -------------------------------------------------------------------------
if ($file === 'cheap_handler.php') {
    switch ($action) {
        case 'getBalance':
            $text('ACCESS_BALANCE:' . number_format($load()['balance'], 2, '.', ''));
        case 'getServers':
            $out = [];
            foreach ($cheap as $type => $rows) {
                $out[$type] = array_map(fn ($r) => ['id' => (int) $r[0], 'name' => $r[1], 'price' => $r[2],
                    'currency' => '₨', 'label' => $r[1] . ' — ₨' . number_format($r[2], 2)], $rows);
            }
            $json($out);
        case 'buyNumber':
            $server = (string) ($query['server'] ?? '');
            $type   = (string) ($query['type'] ?? '1');
            foreach ($cheap[$type] ?? [] as [$id, $name, $price]) {
                if ($id === $server) {
                    [$orderId, $order] = $newOrder('cheap', $name, $price, '224');
                    $text($orderId . ',' . $order['phone'] . ',1200');
                }
            }
            $text('BAD_SERVER');
        case 'getStatus':
        case 'setStatus':
            break;
        default:
            $text('BAD_ACTION');
    }
}

// -------------------------------------------------------------------------
// Email OTP
// -------------------------------------------------------------------------
if ($file === 'email-handler.php') {
    $domains = ['gmail.com' => 'Gmail', 'outlook.com' => 'Outlook', 'mailnestpro.com' => 'MailNest'];
    switch ($action) {
        case 'getBalance':
            $json(['status' => '200', 'currency' => 'PKR', 'balance' => $load()['balance']]);
        case 'getEmailDomains':
            $out = [];
            foreach ($domains as $domain => $name) {
                $out[] = ['domain' => $domain, 'name' => $name, 'stock' => 500, 'services' => 4];
            }
            $json(['status' => '200', 'server' => 1, 'count' => count($out), 'domains' => $out]);
        case 'getEmailServices':
            if ((string) ($query['server'] ?? '1') === '2') {
                $site = (string) ($query['site'] ?? 'telegram.com');
                $json(['status' => '200', 'server' => 2, 'currency' => 'PKR', 'count' => 2, 'site' => $site,
                       'domains' => [['domain' => 'gmail.com', 'price' => 15.6, 'count' => 820],
                                     ['domain' => 'mail.com', 'price' => 3.2, 'count' => 9900]]]);
            }
            $domain = (string) ($query['domain'] ?? 'gmail.com');
            if (!isset($domains[$domain])) {
                $json(['status' => '400', 'message' => 'Invalid domain. Use action=getEmailDomains for the list']);
            }
            $json(['status' => '200', 'server' => 1, 'currency' => 'PKR', 'count' => 4, 'domain' => $domain,
                   'services' => [
                       ['service_code' => 'ig', 'service_name' => 'Instagram', 'price' => 120.0, 'count' => 48],
                       ['service_code' => 'tg', 'service_name' => 'Telegram', 'price' => 95.0, 'count' => 120],
                       ['service_code' => 'fb', 'service_name' => 'Facebook', 'price' => 80.0, 'count' => 30],
                       ['service_code' => 'tw', 'service_name' => 'Twitter', 'price' => 70.0, 'count' => 10]]]);
        case 'buyEmail':
            $domain = (string) ($query['domain'] ?? 'gmail.com');
            [$id, $order] = $newOrder('email', (string) ($query['service_id'] ?? $query['site'] ?? 'x'), 50.0, '');
            $data = $load();
            $data['orders'][$id]['phone'] = 'temp.' . substr(md5($id), 0, 8) . '@' . $domain;
            $save($data);
            $json(['status' => '200', 'message' => 'Email activated successfully', 'email_id' => $id,
                   'email' => $data['orders'][$id]['phone'], 'domain' => $domain, 'currency' => 'PKR',
                   'price' => 50.0, 'balance' => $data['balance'], 'server' => (int) ($query['server'] ?? 1)]);
        case 'getEmailCode':
            $order = $statusOf((string) ($query['email_id'] ?? ''));
            if ($order === null) {
                $json(['status' => '404', 'message' => 'Email activation not found']);
            }
            if ($order['status'] === 'cancel') {
                $json(['status' => '300', 'message' => 'Activation was cancelled']);
            }
            if ($order['status'] === 'ok' || $order['status'] === 'done') {
                $json(['status' => '200', 'email_id' => $query['email_id'], 'server' => 1,
                       'messages' => [['email' => $order['phone'], 'code' => $order['code']]]]);
            }
            $json(['status' => '202', 'message' => 'Waiting for code...', 'messages' => []]);
        case 'cancelEmail':
            $id    = (string) ($query['email_id'] ?? '');
            $order = $statusOf($id);
            if ($order === null) {
                $json(['status' => '404', 'message' => 'Email activation not found']);
            }
            if ($order['status'] === 'ok') {
                $json(['status' => '200', 'message' => 'Completed — OTP already received', 'code' => $order['code']]);
            }
            $data = $load();
            $data['orders'][$id]['status'] = 'cancel';
            $data['balance'] += $order['price'];
            $save($data);
            $json(['status' => '200', 'message' => 'Cancelled and refunded', 'currency' => 'PKR',
                   'refunded' => $order['price'], 'balance' => $data['balance']]);
        default:
            $json(['status' => '400', 'message' => 'Unknown action']);
    }
}

// -------------------------------------------------------------------------
// Rentals
// -------------------------------------------------------------------------
if ($file === 'rental-handler.php') {
    $rentalCountries = ['1' => [['ca', 'Canada'], ['us', 'United States of America'], ['gb', 'United Kingdom']],
                        '2' => [['pk', 'Pakistan'], ['de', 'Germany']]];
    $hours = ['1' => [12, 24, 168, 720], '2' => [720, 4320, 8640]];
    switch ($action) {
        case 'getRentalCountries':
            $out = [];
            foreach ($rentalCountries as $provider => $rows) {
                if (isset($query['provider']) && (string) $query['provider'] !== (string) $provider) {
                    continue;
                }
                foreach ($rows as $i => [$code, $name]) {
                    $out[] = ['id' => (string) ($i + 1), 'provider' => (string) $provider, 'name' => $name, 'country_code' => $code];
                }
            }
            $json(['status' => '200', 'countries' => $out]);
        case 'getRentalPricing':
            $provider = (string) ($query['provider'] ?? '');
            $code     = (string) ($query['country_code'] ?? '');
            $pricing  = [];
            foreach ($hours[$provider] ?? [] as $i => $h) {
                $pricing[] = ['id' => (string) ($i + 1), 'duration_hours' => (string) $h, 'duration_preset' => $h . 'h',
                              'price' => number_format(50 + $h * 1.6, 2, '.', ''), 'country_name' => strtoupper($code)];
            }
            if (!$pricing) {
                $json(['status' => '404', 'message' => 'No pricing found for this country and provider.']);
            }
            $json(['status' => '200', 'currency' => 'PKR', 'pricing' => $pricing]);
        case 'rentNumber':
            $h     = (int) ($query['duration_hours'] ?? 0);
            $price = 50 + $h * 1.6;
            [$id, $order] = $newOrder('rental', 'rental', $price, '+1555');
            $data = $load();
            $data['rentals'][] = ['id' => (string) (count($data['rentals'] ?? []) + 12), 'order_id' => $id,
                                  'phone' => $order['phone'], 'hours' => $h, 'created' => time(),
                                  'provider' => (string) ($query['provider'] ?? '1')];
            $save($data);
            $json(['status' => '200', 'message' => 'Number rented successfully', 'phone' => $order['phone'],
                   'order_id' => $id, 'server' => 'Server ' . ($query['provider'] ?? '1'), 'duration_hours' => $h,
                   'currency' => 'PKR', 'price_paid' => number_format($price, 2, '.', ''),
                   'new_balance' => number_format($data['balance'], 2, '.', ''),
                   'expires' => date('Y-m-d H:i:s', time() + $h * 3600)]);
        case 'getMyRentals':
            $out = [];
            foreach ($load()['rentals'] ?? [] as $r) {
                $out[] = ['id' => $r['id'], 'server' => 'Server ' . $r['provider'], 'phone_number' => $r['phone'],
                          'order_id' => $r['order_id'], 'status' => 'active', 'duration_hours' => (string) $r['hours'],
                          'expiry_time' => date('Y-m-d H:i:s', $r['created'] + $r['hours'] * 3600)];
            }
            $json(['status' => '200', 'currency' => 'PKR', 'total' => count($out), 'rentals' => $out]);
        case 'getRentalSMS':
            foreach ($load()['rentals'] ?? [] as $r) {
                if ($r['order_id'] === (string) ($query['order_id'] ?? '')) {
                    $messages = [];
                    $age = time() - $r['created'];
                    if ($age > $smsDelay) {
                        $messages[] = ['from' => 'Google', 'text' => 'G-482913 is your Google verification code.',
                                       'code' => '482913', 'timestamp' => date('Y-m-d H:i:s', $r['created'] + $smsDelay)];
                    }
                    if ($age > $smsDelay * 2) {
                        $messages[] = ['from' => 'WhatsApp', 'text' => 'Your WhatsApp code: 551-208',
                                       'code' => '551208', 'timestamp' => date('Y-m-d H:i:s', $r['created'] + $smsDelay * 2)];
                    }
                    $json(['status' => '200', 'phone_number' => $r['phone'], 'order_id' => $r['order_id'],
                           'total' => count($messages), 'messages' => $messages]);
                }
            }
            $json(['status' => '404', 'message' => 'Order not found or access denied.']);
        case 'renewRental':
            $h = (int) ($query['duration_hours'] ?? 0);
            $json(['status' => '200', 'message' => 'Renewed successfully', 'rental_id' => (int) ($query['rental_id'] ?? 0),
                   'duration_hours' => $h, 'currency' => 'PKR', 'price_paid' => number_format(50 + $h * 1.6, 2, '.', ''),
                   'new_balance' => '1000.00', 'new_expiry' => date('Y-m-d H:i:s', time() + $h * 3600 * 2)]);
        case 'cancelRental':
            $json(['status' => '400', 'message' => 'Cancel window expired. Rentals can only be cancelled within 20 minutes of purchase.']);
        default:
            $json(['status' => '400', 'message' => 'Unknown action']);
    }
}

// -------------------------------------------------------------------------
// The three calls every number API shares
// -------------------------------------------------------------------------
if (in_array($file, ['handler_api.php', 'premium-handler.php', 'cheap_handler.php'], true)) {
    $id = (string) ($query['id'] ?? '');
    if ($id === '') {
        $text('BAD_ID');
    }
    if ($action === 'getStatus') {
        $order = $statusOf($id);
        if ($order === null) {
            $text('NO_ACTIVATION');
        }
        $text(match ($order['status']) {
            'ok', 'done' => $order['code'] !== '' ? 'STATUS_OK:' . $order['code'] : 'STATUS_CANCEL',
            'cancel'     => 'STATUS_CANCEL',
            default      => 'STATUS_WAIT_CODE',
        });
    }
    $setStatus($id, (int) ($query['status'] ?? 0));
}

http_response_code(404);
$text('Not found');
