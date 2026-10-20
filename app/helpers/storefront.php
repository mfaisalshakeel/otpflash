<?php
/**
 * What the storefront's picker is made of.
 *
 * The home page, the service and country landing pages and the JSON the
 * picker asks for while someone clicks around are all the same three lists
 * - services, countries, options - so they are built here once. A list that
 * is drawn on the server for the first paint and then rebuilt by the script
 * would otherwise be two lists that drift.
 */

require_once APP_PATH . '/helpers/catalog.php';
require_once APP_PATH . '/helpers/numbers.php';   // number_lifetime()

/** Which panes a tab has, left to right. */
const PICKER_PANES = [
    'numbers' => ['service', 'country', 'options'],
    'email'   => ['service', 'options'],
    'rental'  => ['country', 'options'],
];

/** The address a tab lives at. */
function tab_url(string $tab): string
{
    return match ($tab) {
        'email'  => url('temporary-email'),
        'rental' => url('rent-number'),
        default  => url(''),
    };
}

/**
 * The words a service is found by: its name, its slug and every alias we
 * know, so typing "gmail" finds Google and "twitter" finds X.
 */
function service_keywords(array $service): string
{
    $words = [mb_strtolower((string) $service['name']), (string) $service['slug']];
    $known = service_catalogue()[$service['slug']] ?? null;
    foreach ((array) ($known['aliases'] ?? []) as $alias) {
        $words[] = mb_strtolower($alias);
    }
    return implode(' ', array_unique($words));
}

/** The words a country is found by: name, ISO code, dial code and aliases. */
function country_keywords(array $country): string
{
    $words = [mb_strtolower((string) $country['name']), strtolower((string) $country['iso2'])];
    if ($country['dial_code'] !== '') {
        $words[] = '+' . $country['dial_code'];
    }
    $known = country_catalogue()[strtolower((string) $country['iso2'])] ?? null;
    foreach ((array) ($known['aliases'] ?? []) as $alias) {
        $words[] = mb_strtolower($alias);
    }
    return implode(' ', array_unique($words));
}

/** A service row, shaped for the picker. */
function picker_service(array $row): array
{
    return [
        'id'    => (int) $row['id'],
        'slug'  => (string) $row['slug'],
        'name'  => (string) $row['name'],
        'icon'  => (string) $row['icon'],
        'color' => (string) ($row['color'] ?: service_color_for((string) $row['name'])),
        'pop'   => (int) $row['is_popular'],
        'from'  => round((float) $row['from_price'], 2),
        'count' => (int) ($row['countries'] ?? $row['options'] ?? 0),
        'sub'   => '',
        'keys'  => service_keywords($row),
    ];
}

/**
 * The small line under a service: how many countries it is sold in - or,
 * once a country is chosen, how many ways it is sold there, because "1
 * country" under every row says nothing.
 */
function picker_service_sub(array $row, string $tab, bool $countryChosen): string
{
    if ($tab === 'email') {
        return $row['options'] . ' mailbox' . ((int) $row['options'] === 1 ? '' : 'es');
    }
    if ($countryChosen) {
        return $row['options'] . ' server' . ((int) $row['options'] === 1 ? '' : 's');
    }
    return $row['countries'] . ' ' . ((int) $row['countries'] === 1 ? 'country' : 'countries');
}

/** A country row, shaped for the picker. */
function picker_country(array $row): array
{
    return [
        'id'   => (int) $row['id'],
        'slug' => (string) $row['slug'],
        'name' => (string) $row['name'],
        'iso'  => strtolower((string) $row['iso2']),
        'dial' => (string) $row['dial_code'],
        'pop'  => (int) $row['is_popular'],
        'from' => round((float) $row['from_price'], 2),
        'keys' => country_keywords($row),
    ];
}

/** An offer, shaped for the picker. */
function picker_option(array $offer): array
{
    return [
        'id'      => (int) $offer['id'],
        'label'   => (string) $offer['label'],
        'price'   => (float) $offer['sale_price'],
        'product' => (string) $offer['product'],
        'note'    => match ((string) $offer['product']) {
            'premium' => 'Code by SMS or voice call',
            'cheap'   => 'Fixed range, held 20 minutes',
            'email'   => $offer['stock'] !== null ? qty_fmt((int) $offer['stock']) . ' in stock' : 'Inbox held 30 minutes',
            'rental'  => 'Every SMS kept in your inbox',
            default   => 'Held ' . number_lifetime('sms') . ' minutes',
        },
    ];
}

/**
 * Everything one picker needs to draw itself, and to be drawn without the
 * script: the lists, what is chosen, and the options for the choice.
 *
 * @return array{tab: string, panes: string[], services: array, countries: array,
 *               options: array, service: ?array, country: ?array}
 */
function picker_state(string $tab, ?int $serviceId = null, ?int $countryId = null): array
{
    $panes = PICKER_PANES[$tab] ?? PICKER_PANES['numbers'];

    $services = [];
    if (in_array('service', $panes, true)) {
        $narrowed = $tab === 'numbers' ? $countryId : null;
        foreach (store_services($tab, $narrowed) as $row) {
            $services[] = ['sub' => picker_service_sub($row, $tab, $narrowed !== null)] + picker_service($row);
        }
    }
    $countries = in_array('country', $panes, true)
        ? array_map('picker_country', store_countries($tab, $tab === 'numbers' ? $serviceId : null)) : [];

    $service = null;
    foreach ($services as $row) {
        if ($row['id'] === $serviceId) {
            $service = $row;
        }
    }
    $country = null;
    foreach ($countries as $row) {
        if ($row['id'] === $countryId) {
            $country = $row;
        }
    }

    $ready = match ($tab) {
        'numbers' => $service && $country,
        'email'   => (bool) $service,
        'rental'  => (bool) $country,
        default   => false,
    };
    $options = $ready
        ? array_map('picker_option', store_options($tab, $service['id'] ?? null, $country['id'] ?? null))
        : [];

    return ['tab' => $tab, 'panes' => $panes, 'services' => $services, 'countries' => $countries,
            'options' => $options, 'service' => $service, 'country' => $country];
}

/** The address of a choice: /whatsapp, /whatsapp/pakistan, /country/pakistan. */
function choice_url(string $tab, ?array $service, ?array $country): string
{
    if ($tab === 'numbers') {
        if ($service && $country) {
            return url($service['slug'] . '/' . $country['slug']);
        }
        if ($service) {
            return url($service['slug']);
        }
        if ($country) {
            return url('country/' . $country['slug']);
        }
        return url('');
    }
    if ($tab === 'email' && $service) {
        return url('temporary-email/' . $service['slug']);
    }
    if ($tab === 'rental' && $country) {
        return url('rent-number/' . $country['slug']);
    }
    return tab_url($tab);
}

/** The cheapest and dearest sale price among options, for the Product schema. */
function price_range(array $options): array
{
    $prices = array_column($options, 'price');
    return $prices ? [min($prices), max($prices)] : [0.0, 0.0];
}

/** The service's own SEO intro, or a sensible generated one. */
function service_intro(array $service, ?array $country = null): string
{
    $name = $service['name'];
    if ($country) {
        return 'Get a real ' . $country['name'] . ' phone number (+' . $country['dial'] . ') to receive your '
            . $name . ' verification code online. Pay once, copy the number, and the OTP appears on '
            . 'your screen the moment it arrives - or a new number is free.';
    }
    return 'Buy a virtual number for ' . $name . ' verification in seconds. Choose from '
        . 'numbers in many countries, receive the ' . $name . ' OTP online, and get a free '
        . 'replacement if the SMS never arrives.';
}

/**
 * The picker's last step for the choice an offer belongs to, as HTML.
 *
 * What the order form answers with when the price moved under the customer:
 * the same options, freshly priced, so the button they press next says what
 * they will pay.
 */
function picker_options_html(array $offer): string
{
    $tab = match ((string) $offer['product']) {
        'email'  => 'email',
        'rental' => 'rental',
        default  => 'numbers',
    };
    $state = picker_state(
        $tab,
        $tab !== 'rental' && !empty($offer['service_id']) ? (int) $offer['service_id'] : null,
        $tab !== 'email' && !empty($offer['country_id']) ? (int) $offer['country_id'] : null
    );
    return render('partials/options', [
        'state'    => $state,
        'user'     => current_user(),
        'guestOk'  => setting('guest_checkout', '1') === '1',
        'askEmail' => setting('ask_email', '0') === '1',
    ]);
}
