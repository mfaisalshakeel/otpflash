<?php
/**
 * The one class in the codebase: OTPGet's API.
 *
 * OTPGet is five endpoints with one key between them, and they do not agree
 * on a format. The SMS, Premium and Cheap APIs speak an old colon-separated
 * plain-text protocol for anything that moves money and JSON for the
 * listings - except that a listing that fails answers in plain text too.
 * Rentals and Email are JSON throughout, with the outcome in a `status`
 * field rather than the HTTP code. This wraps all of it so that nothing
 * outside this file has to know which is which:
 *
 *   $api = otpget();                                  // from Settings
 *   $api->balance();                                  // ['balance' => 125.0]
 *   $api->smsCountries(1);                            // [['id' => '1', 'name' => 'Canada'], ...]
 *   $api->smsServices('1', 1);                        // [['id' => '12', 'name' => 'Telegram', 'price' => 45.0], ...]
 *   $api->buy('sms', '1', '1', '12');                 // ['order_id' => ..., 'phone' => ..., 'price' => 45.0]
 *   $api->status('sms', $orderId);                    // ['state' => 'received', 'code' => '123456']
 *   $api->cancel('sms', $orderId);                    // ['ok' => true]
 *
 * Every method returns an array. A failure - the network, a refusal, a reply
 * nobody could read - is always ['error' => CODE, 'message' => words], so a
 * caller checks one key. CODE is OTPGet's own (NO_NUMBERS, BAD_KEY...) where
 * it gave one, which is what lets a caller tell "try another country" from
 * "the shop is misconfigured" without reading prose.
 *
 * Amounts come back in the currency the account is billed in (PKR unless
 * the `currency` setting says otherwise) and are never converted here.
 */
final class OtpGet
{
    /** Which file each product lives at, under the base URL. */
    public const ENDPOINTS = [
        'sms'     => 'handler_api.php',
        'premium' => 'premium-handler.php',
        'cheap'   => 'cheap_handler.php',
        'rental'  => 'rental-handler.php',
        'email'   => 'email-handler.php',
    ];

    /**
     * What each plain-text code means, in words an admin can act on.
     *
     * The customer never reads these - they get the gentler line from
     * number_trouble() - but the order log and the admin's error column do,
     * and "NO_BALANCE" alone has sent people looking in the wrong place.
     */
    public const MESSAGES = [
        'BAD_KEY'                 => 'OTPGet did not accept the API key.',
        'ACCOUNT_BLOCKED'         => 'The OTPGet account is suspended.',
        'BAD_CURRENCY'            => 'OTPGet does not know that currency code.',
        'BAD_ACTION'              => 'OTPGet did not recognise the request.',
        'BAD_COUNTRY'             => 'That country is not available on this server.',
        'BAD_SERVICE'             => 'That service is not available for this country.',
        'BAD_SERVER'              => 'That server is not available.',
        'BAD_TYPE'                => 'That server number is not valid.',
        'BAD_ID'                  => 'No order id was sent.',
        'BAD_STATUS'              => 'OTPGet refused that status change.',
        'NO_SERVICE'              => 'That service does not exist for this country.',
        'NO_SERVICES'             => 'Nothing is on sale there right now.',
        'NO_SERVERS'              => 'No server is available right now.',
        'NO_BALANCE'              => 'The OTPGet wallet is too low to buy this number. Top it up.',
        'LOW_BALANCE'             => 'The OTPGet wallet is too low to buy this number. Top it up.',
        'NO_BALANCE_FOR_NEW_PRICE'=> 'The live price went above the OTPGet wallet balance. Top it up.',
        'NO_NUMBERS'              => 'No numbers are in stock for this choice right now.',
        'NO_ACTIVATION'           => 'OTPGet has no order with that id on this account.',
        'EARLY_CANCEL_DENIED'     => 'Too early to cancel - OTPGet allows it two minutes after purchase.',
        'NO_SMS_RECEIVED'         => 'No SMS has arrived on that number yet.',
        'STATUS_ALREADY_CHANGED'  => 'That number was already finished or cancelled.',
        'PROVIDER_NOT_CONFIGURED' => 'Premium is switched off on OTPGet right now.',
        'DATABASE_ERROR'          => 'OTPGet could not save the order and reversed the charge.',
        'ERROR_PROVIDER'          => 'The OTPGet server had a problem. Nothing was charged.',
        'EMPTY'                   => 'OTPGet sent back an empty reply.',
        'UNREADABLE'              => 'OTPGet sent back something that could not be read.',
        'CONNECTION'              => 'Could not reach OTPGet.',
        'HTTP'                    => 'OTPGet answered with an HTTP error.',
    ];

    public function __construct(
        private string $baseUrl,
        private string $key,
        private string $currency = '',
        private int $timeout = 30
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Set while parallel() is learning what its work will ask. */
    private bool $recording = false;

    /** The request the work under recording tried to make. */
    private ?array $recorded = null;

    /** Replies fetched together by parallel(), keyed by URL, each used once. */
    private array $prefetched = [];

    private const DEFERRED = 7301;

    /**
     * Run several pieces of work whose OTPGet requests go out together.
     *
     * OTPGet has no bulk listing: a catalogue is one request per server and
     * country, and nine hundred of them one after another is a quarter of an
     * hour. Here each piece runs twice. The first time it is stopped at its
     * first request, which is only noted; then every noted request goes out
     * at once, `$width` at a time; then each piece runs for real and is
     * answered from what came back. So the parsing stays in the one method
     * that already does it, and nothing here knows what a reply looks like.
     *
     * The rule this rests on: a piece must make its first OTPGet request
     * before it writes anything, because the first run is thrown away at
     * that request. Every sync unit does. A piece that makes a second
     * request makes it live, which is slower but still correct.
     *
     * @param array<int|string, callable> $work
     * @return array<int|string, mixed> each piece's return value, same keys
     */
    public function parallel(array $work, int $width = 12): array
    {
        $requests = [];
        $this->recording = true;
        try {
            foreach ($work as $name => $piece) {
                $this->recorded = null;
                try {
                    $piece();
                } catch (RuntimeException $e) {
                    if ($e->getCode() !== self::DEFERRED) {
                        throw $e;
                    }
                }
                if ($this->recorded !== null) {
                    $requests[$this->recorded['url']] = $this->recorded;
                }
            }
        } finally {
            $this->recording = false;
            $this->recorded  = null;
        }

        $this->fetchAll(array_values($requests), max(1, $width));

        $results = [];
        try {
            foreach ($work as $name => $piece) {
                $results[$name] = $piece();
            }
        } finally {
            $this->prefetched = [];
        }
        return $results;
    }

    /** Fetch many URLs at once, at most $width in flight, into $prefetched. */
    private function fetchAll(array $requests, int $width): void
    {
        if (!$requests) {
            return;
        }
        $multi   = curl_multi_init();
        $flying  = [];
        $next    = 0;
        $launch  = function () use (&$next, &$flying, $requests, $multi): void {
            $request = $requests[$next++];
            $handle  = $this->handle($request['url'], $request['timeout']);
            curl_multi_add_handle($multi, $handle);
            $flying[spl_object_id($handle)] = [$handle, $request['url']];
        };

        while ($next < count($requests) && count($flying) < $width) {
            $launch();
        }
        do {
            curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 1.0);
            }
            while ($info = curl_multi_info_read($multi)) {
                $handle = $info['handle'];
                [, $url] = $flying[spl_object_id($handle)];
                $this->prefetched[$url] = $this->answer(
                    curl_multi_getcontent($handle),
                    (int) $info['result'],
                    $info['result'] ? curl_strerror($info['result']) : '',
                    (int) curl_getinfo($handle, CURLINFO_HTTP_CODE)
                );
                unset($flying[spl_object_id($handle)]);
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                if ($next < count($requests)) {
                    $launch();
                }
            }
        } while ($flying);
        curl_multi_close($multi);
    }

    // =======================================================================
    // Account
    // =======================================================================

    /** @return array{balance: float}|array{error: string, message: string} */
    public function balance(): array
    {
        $body = $this->get('sms', ['action' => 'getBalance'], 15);
        if (isset($body['error'])) {
            return $body;
        }
        if (preg_match('/^ACCESS_BALANCE:\s*(-?[\d.]+)/', $body['text'], $m)) {
            return ['balance' => (float) $m[1]];
        }
        return $this->refusal($body['text']);
    }

    /** Every currency OTPGet can quote in, with its rate per 1 PKR. */
    public function currencies(): array
    {
        $data = $this->json('sms', ['action' => 'getCurrencies'], 15);
        if (isset($data['error'])) {
            return $data;
        }
        return ['currencies' => array_values(array_filter((array) ($data['currencies'] ?? []), 'is_array'))];
    }

    // =======================================================================
    // SMS OTP API - servers (`type`) 1 to 6, then countries, then services
    // =======================================================================

    /** @return array{servers: array<int, array{id: string, active: bool}>}|array{error: string} */
    public function smsServers(): array
    {
        $data = $this->json('sms', ['action' => 'getServers'], 15);
        if (isset($data['error'])) {
            return $data;
        }

        $servers = [];
        foreach ((array) ($data['servers'] ?? []) as $row) {
            if (is_array($row) && isset($row['id'])) {
                $servers[] = ['id' => (string) $row['id'], 'active' => !empty($row['active'])];
            }
        }
        return ['servers' => $servers];
    }

    /**
     * Countries on one server.
     *
     * @return array<int, array{id: string, name: string}>|array{error: string}
     */
    public function smsCountries(int $type): array
    {
        $data = $this->json('sms', ['action' => 'getCountries', 'type' => $type], 20);
        if (isset($data['error'])) {
            return $data;
        }

        $rows = $data[(string) $type] ?? $data[$type] ?? [];
        $out  = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) && isset($row['id'], $row['name'])) {
                $out[] = ['id' => (string) $row['id'], 'name' => trim((string) $row['name'])];
            }
        }
        return $out;
    }

    /**
     * What one country sells on one server, and for how much.
     *
     * An empty array is an ordinary answer - the country has nothing in stock
     * - rather than an error.
     *
     * @return array<int, array{id: string, name: string, price: float}>|array{error: string}
     */
    public function smsServices(string $country, int $type): array
    {
        $data = $this->json('sms', ['action' => 'getServices', 'country' => $country, 'type' => $type], 20);
        if (isset($data['error'])) {
            return $data;
        }
        return self::priceList($data);
    }

    // =======================================================================
    // Premium - "servers" are countries here, then services per country
    // =======================================================================

    /** @return array<int, array{id: string, name: string, price: float}>|array{error: string} */
    public function premiumCountries(): array
    {
        $data = $this->json('premium', ['action' => 'getServers'], 20);
        if (isset($data['error'])) {
            return $data['error'] === 'NO_SERVERS' ? [] : $data;
        }

        // "Pakistan — from Rs90.00": the price is the cheapest service, which
        // is only worth keeping as a hint - the services carry the real ones.
        $out = [];
        foreach ($data as $id => $label) {
            if (!is_scalar($label)) {
                continue;
            }
            $parts = preg_split('/\s+[—–-]\s+from\s+/u', (string) $label, 2);
            $out[] = [
                'id'    => (string) $id,
                'name'  => trim((string) $parts[0]),
                'price' => isset($parts[1]) ? self::amount($parts[1]) : 0.0,
            ];
        }
        return $out;
    }

    /** @return array<int, array{id: string, name: string, price: float}>|array{error: string} */
    public function premiumServices(string $server): array
    {
        $data = $this->json('premium', ['action' => 'getServices', 'server' => $server], 20);
        if (isset($data['error'])) {
            return $data['error'] === 'NO_SERVICES' ? [] : $data;
        }
        return self::priceList($data);
    }

    // =======================================================================
    // Cheap numbers - a fixed range at a fixed price, no country to choose
    // =======================================================================

    /**
     * Every cheap server, flattened out of its group.
     *
     * The group (`type`) has to travel with the id: a purchase names both.
     *
     * @return array<int, array{id: string, type: string, name: string, price: float}>|array{error: string}
     */
    public function cheapServers(): array
    {
        $data = $this->json('cheap', ['action' => 'getServers'], 20);
        if (isset($data['error'])) {
            return $data['error'] === 'NO_SERVERS' ? [] : $data;
        }

        $out = [];
        foreach ($data as $type => $rows) {
            foreach ((array) $rows as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }
                $out[] = [
                    'id'    => (string) $row['id'],
                    'type'  => (string) $type,
                    'name'  => trim((string) ($row['name'] ?? '')),
                    'price' => (float) ($row['price'] ?? 0),
                ];
            }
        }
        return $out;
    }

    // =======================================================================
    // Buying and watching a number - the same three calls on all three APIs
    // =======================================================================

    /**
     * Buy a number.
     *
     *   sms      $server = type 1-6, $country, $service
     *   premium  $country = the premium "server" id, $service
     *   cheap    $country = the server id, $server = its group (type)
     *
     * `validity` is in seconds where OTPGet says it (Premium and Cheap) and
     * null where it does not, so the caller falls back to its own setting.
     * `price` is what was really charged where OTPGet says it (SMS) and null
     * where it does not.
     *
     * @return array{order_id: string, phone: string, price: ?float, validity: ?int}|array{error: string, message: string}
     */
    public function buy(string $product, string $server, string $country, string $service): array
    {
        $query = match ($product) {
            'sms'     => ['action' => 'getNumber', 'type' => $server, 'country' => $country, 'service' => $service],
            'premium' => ['action' => 'buyNumber', 'server' => $country, 'service' => $service],
            'cheap'   => ['action' => 'buyNumber', 'server' => $country] + ($server !== '' ? ['type' => $server] : []),
            default   => null,
        };
        if ($query === null) {
            return ['error' => 'BAD_ACTION', 'message' => 'Unknown product ' . $product . '.'];
        }

        // A purchase can sit on OTPGet's own upstream for a while, and giving
        // up early is the worst outcome there is: the number may well have
        // been bought and charged, and we would never know its id.
        $body = $this->get($product, $query, 60);
        if (isset($body['error'])) {
            return $body;
        }
        $text = $body['text'];

        if ($product === 'sms') {
            // ACCESS_NUMBER:order_id:phone:price
            $parts = explode(':', $text);
            if (($parts[0] ?? '') === 'ACCESS_NUMBER' && count($parts) >= 3 && $parts[1] !== '') {
                return [
                    'order_id' => trim($parts[1]),
                    'phone'    => preg_replace('/[^\d+]/', '', $parts[2]),
                    'price'    => isset($parts[3]) && is_numeric(trim($parts[3])) ? (float) $parts[3] : null,
                    'validity' => null,
                ];
            }
            return $this->refusal($text);
        }

        // order_id,number,validity_seconds
        $parts = array_map('trim', explode(',', $text));
        if (count($parts) >= 2 && $parts[0] !== '' && preg_match('/^\+?\d{5,}$/', $parts[1])) {
            return [
                'order_id' => $parts[0],
                'phone'    => $parts[1],
                'price'    => null,
                'validity' => isset($parts[2]) && ctype_digit($parts[2]) ? (int) $parts[2] : null,
            ];
        }
        return $this->refusal($text);
    }

    /**
     * Has the code arrived?
     *
     * `state` is 'waiting', 'received' or 'cancelled', and nothing else. A
     * reply this does not recognise is an error rather than a guess - the
     * caller keeps waiting on an error, which is the safe direction: a
     * number wrongly called finished loses the customer their code.
     *
     * @return array{state: string, code: string}|array{error: string, message: string}
     */
    public function status(string $product, string $orderId): array
    {
        $body = $this->get($product, ['action' => 'getStatus', 'id' => $orderId], 15);
        if (isset($body['error'])) {
            return $body;
        }
        $text = $body['text'];

        if ($text === 'STATUS_WAIT_CODE' || str_starts_with($text, 'STATUS_WAIT')) {
            return ['state' => 'waiting', 'code' => ''];
        }
        if (str_starts_with($text, 'STATUS_OK:')) {
            return ['state' => 'received', 'code' => trim(substr($text, strlen('STATUS_OK:')))];
        }
        if ($text === 'STATUS_CANCEL') {
            return ['state' => 'cancelled', 'code' => ''];
        }
        return $this->refusal($text);
    }

    /**
     * Give a number back for a refund (status 8).
     *
     * `refunded` is true when OTPGet says the money came back. It says
     * STATUS_ALREADY_CHANGED both for "already cancelled" and for "the code
     * was delivered first", so that answer is passed up as an error and the
     * caller reads the status to find out which.
     *
     * @return array{ok: true, refunded: bool}|array{error: string, message: string}
     */
    public function cancel(string $product, string $orderId): array
    {
        $body = $this->get($product, ['action' => 'setStatus', 'id' => $orderId, 'status' => 8], 30);
        if (isset($body['error'])) {
            return $body;
        }
        if ($body['text'] === 'ACCESS_READY') {
            return ['ok' => true, 'refunded' => true];
        }
        return $this->refusal($body['text']);
    }

    /** Close a number that has had its code (status 6). */
    public function finish(string $product, string $orderId): array
    {
        $body = $this->get($product, ['action' => 'setStatus', 'id' => $orderId, 'status' => 6], 20);
        if (isset($body['error'])) {
            return $body;
        }
        if ($body['text'] === 'ACCESS_FINISH') {
            return ['ok' => true];
        }
        return $this->refusal($body['text']);
    }

    // =======================================================================
    // Email OTP
    // =======================================================================

    /** Server 1: the domains that have stock. */
    public function emailDomains(): array
    {
        $data = $this->json('email', ['action' => 'getEmailDomains', 'server' => 1], 20);
        if (isset($data['error'])) {
            return $data;
        }
        return ['domains' => array_values(array_filter((array) ($data['domains'] ?? []), 'is_array'))];
    }

    /**
     * Server 1: services on a domain. Server 2: domains for a site.
     *
     * Passed through as OTPGet wrote them, because the two servers answer
     * with different lists and the caller already knows which it asked.
     */
    public function emailServices(int $server, string $domainOrSite): array
    {
        $query = ['action' => 'getEmailServices', 'server' => $server]
            + ($server === 2 ? ['site' => $domainOrSite] : ['domain' => $domainOrSite]);

        return $this->json('email', $query, 20);
    }

    /** @return array{email_id: string, email: string, price: float}|array{error: string, message: string} */
    public function buyEmail(int $server, string $domain, string $serviceOrSite): array
    {
        $query = ['action' => 'buyEmail', 'server' => $server, 'domain' => $domain]
            + ($server === 2 ? ['site' => $serviceOrSite] : ['service_id' => $serviceOrSite]);

        $data = $this->json('email', $query, 60);
        if (isset($data['error'])) {
            return $data;
        }
        if (empty($data['email_id']) || empty($data['email'])) {
            return ['error' => 'UNREADABLE', 'message' => 'OTPGet did not return an email address.'];
        }
        return [
            'email_id' => (string) $data['email_id'],
            'email'    => (string) $data['email'],
            'price'    => (float) ($data['price'] ?? 0),
        ];
    }

    /** @return array{state: string, code: string}|array{error: string, message: string} */
    public function emailCode(string $emailId): array
    {
        $data = $this->json('email', ['action' => 'getEmailCode', 'email_id' => $emailId], 15, ['202', '300']);
        if (isset($data['error'])) {
            return $data;
        }

        $status = (string) ($data['status'] ?? '');
        if ($status === '300') {
            return ['state' => 'cancelled', 'code' => ''];
        }
        if ($status === '202') {
            return ['state' => 'waiting', 'code' => ''];
        }

        $codes = [];
        foreach ((array) ($data['messages'] ?? []) as $message) {
            if (is_array($message) && trim((string) ($message['code'] ?? '')) !== '') {
                $codes[] = trim((string) $message['code']);
            }
        }
        return $codes ? ['state' => 'received', 'code' => end($codes)] : ['state' => 'waiting', 'code' => ''];
    }

    public function cancelEmail(string $emailId): array
    {
        $data = $this->json('email', ['action' => 'cancelEmail', 'email_id' => $emailId], 30);
        if (isset($data['error'])) {
            return $data;
        }
        return ['ok' => true, 'refunded' => isset($data['refunded']), 'code' => (string) ($data['code'] ?? '')];
    }

    // =======================================================================
    // Rentals
    // =======================================================================

    public function rentalCountries(?int $provider = null): array
    {
        return $this->json('rental', ['action' => 'getRentalCountries']
            + ($provider ? ['provider' => $provider] : []), 20);
    }

    public function rentalPricing(string $countryCode, int $provider): array
    {
        return $this->json('rental', ['action' => 'getRentalPricing',
            'country_code' => $countryCode, 'provider' => $provider], 20);
    }

    public function rentNumber(string $countryCode, int $provider, int $hours): array
    {
        return $this->json('rental', ['action' => 'rentNumber', 'country_code' => $countryCode,
            'provider' => $provider, 'duration_hours' => $hours], 60);
    }

    public function rentalSms(string $orderId): array
    {
        return $this->json('rental', ['action' => 'getRentalSMS', 'order_id' => $orderId], 15);
    }

    public function myRentals(): array
    {
        return $this->json('rental', ['action' => 'getMyRentals'], 20);
    }

    public function renewRental(string $rentalId, int $hours): array
    {
        return $this->json('rental', ['action' => 'renewRental', 'rental_id' => $rentalId,
            'duration_hours' => $hours], 60);
    }

    public function cancelRental(string $rentalId): array
    {
        return $this->json('rental', ['action' => 'cancelRental', 'rental_id' => $rentalId], 30);
    }

    // =======================================================================
    // Plumbing
    // =======================================================================

    /**
     * Turn "Telegram - 45.00 PKR" / "Telegram - Rs90.00" lists into rows.
     *
     * The price is everything after the **last** " - ", because a service
     * name is allowed to contain one ("Google - Gmail - 45.00 PKR") and the
     * price never does.
     *
     * @return array<int, array{id: string, name: string, price: float}>
     */
    public static function priceList(array $data): array
    {
        $out = [];
        foreach ($data as $id => $label) {
            if (!is_scalar($label)) {
                continue;
            }
            $label = trim((string) $label);
            $cut   = strrpos($label, ' - ');
            $name  = $cut === false ? $label : trim(substr($label, 0, $cut));
            $price = $cut === false ? 0.0 : self::amount(substr($label, $cut + 3));

            if ($name === '') {
                continue;
            }
            $out[] = ['id' => (string) $id, 'name' => $name, 'price' => $price];
        }
        return $out;
    }

    /** The first number in "Rs90.00", "₨50.00" or "45.00 PKR". */
    public static function amount(string $text): float
    {
        return preg_match('/(\d[\d,]*(?:\.\d+)?)/', $text, $m)
            ? (float) str_replace(',', '', $m[1])
            : 0.0;
    }

    /** A plain-text refusal as an error, with words beside the code. */
    private function refusal(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['error' => 'EMPTY', 'message' => self::MESSAGES['EMPTY']];
        }

        // ERROR_PROVIDER_2:Server 2 is temporarily unavailable
        // NO_BALANCE_FOR_NEW_PRICE:52.50
        $code   = strtoupper((string) strtok($text, ':'));
        $detail = trim((string) substr($text, strlen($code) + 1));
        $key    = str_starts_with($code, 'ERROR_PROVIDER') ? 'ERROR_PROVIDER' : $code;

        if (isset(self::MESSAGES[$key])) {
            return ['error' => $code, 'message' => self::MESSAGES[$key]
                . ($detail !== '' ? ' (' . mb_substr($detail, 0, 120) . ')' : '')];
        }

        // A code nobody wrote down, or prose. Kept as it came, shortened.
        return ['error' => preg_match('/^[A-Z_]{3,40}$/', $code) ? $code : 'UNREADABLE',
                'message' => 'OTPGet said: ' . mb_substr($text, 0, 180)];
    }

    /**
     * A JSON action. A plain-text body here is a refusal (BAD_KEY and its
     * friends), and a JSON one with a `status` other than 200 - or one of the
     * extra statuses the caller expects - is a refusal in JSON's clothes.
     */
    private function json(string $product, array $query, int $timeout, array $alsoOk = []): array
    {
        $body = $this->get($product, $query, $timeout);
        if (isset($body['error'])) {
            return $body;
        }

        $data = json_decode($body['text'], true);
        if (!is_array($data)) {
            return $this->refusal($body['text']);
        }

        if (isset($data['status']) && is_scalar($data['status'])) {
            $status = (string) $data['status'];
            if ($status !== '200' && !in_array($status, $alsoOk, true)) {
                $message = trim((string) ($data['message'] ?? ''));
                return ['error' => 'STATUS_' . $status,
                        'message' => $message !== '' ? 'OTPGet said: ' . mb_substr($message, 0, 200)
                                                     : 'OTPGet refused the request (' . $status . ').'];
            }
        }

        return $data;
    }

    /** GET the action with the key, and hand back the trimmed body. */
    private function get(string $product, array $query, int $timeout): array
    {
        $file = self::ENDPOINTS[$product] ?? null;
        if ($file === null) {
            return ['error' => 'BAD_ACTION', 'message' => 'Unknown product ' . $product . '.'];
        }
        if ($this->key === '') {
            return ['error' => 'BAD_KEY', 'message' => 'No OTPGet API key is saved yet.'];
        }

        $query = ['api_key' => $this->key] + $query;
        if ($this->currency !== '' && strtoupper($this->currency) !== 'PKR') {
            $query['currency'] = $this->currency;
        }
        $url = $this->baseUrl . '/' . $file . '?' . http_build_query($query);

        if ($this->recording) {
            $this->recorded = ['url' => $url, 'timeout' => $timeout];
            throw new RuntimeException('Deferred to a parallel fetch.', self::DEFERRED);
        }
        if (array_key_exists($url, $this->prefetched)) {
            $reply = $this->prefetched[$url];
            unset($this->prefetched[$url]);
            return $reply;
        }

        $ch     = $this->handle($url, $timeout);
        $body   = curl_exec($ch);
        $reply  = $this->answer($body, curl_errno($ch), curl_error($ch), (int) curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);
        return $reply;
    }

    /** One request, set up the same way whether it goes alone or in a crowd. */
    private function handle(string $url, int $timeout): CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => min($timeout, $this->timeout * 2),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'OTPFlash/' . (defined('APP_VERSION') ? APP_VERSION : '1'),
            CURLOPT_HTTPHEADER     => ['Accept: application/json, text/plain'],
        ]);
        return $ch;
    }

    /** What a finished request means: its text, or why there is none. */
    private function answer(string|false|null $body, int $errNo, string $errMsg, int $code): array
    {
        if ($errNo !== 0) {
            return ['error' => 'CONNECTION', 'message' => self::MESSAGES['CONNECTION'] . ' ' . $errMsg];
        }
        if ($code >= 400) {
            return ['error' => 'HTTP', 'message' => self::MESSAGES['HTTP'] . ' (' . $code . ')'];
        }

        // A byte-order mark or a stray newline is not part of the protocol.
        $text = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $body));

        return ['text' => $text];
    }
}
