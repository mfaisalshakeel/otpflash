<?php
/**
 * The storefront: the picker, and everything around it.
 *
 * Reached many ways, all rendering this one page with something chosen:
 *
 *   /                          numbers, nothing chosen
 *   /whatsapp                  numbers, a service chosen         (_fallback.php)
 *   /whatsapp/pakistan         numbers, service and country      (_fallback.php)
 *   /country/pakistan          numbers, a country chosen         (country.php)
 *   /temporary-email[/slug]    email OTP                         (temporary-email.php)
 *   /rent-number[/slug]        rentals                           (rent-number.php)
 *
 * Every one of those is a real, indexable page with its own title, its own
 * words and its own structured data - the landing pages are the shop's SEO -
 * and every one is the same working picker, so a visitor from a search
 * result is one click from buying.
 */
require_once APP_PATH . '/helpers/storefront.php';
require_once APP_PATH . '/helpers/seo.php';

$choice  = $GLOBALS['__picker'] ?? [];
$tabs    = store_tabs();
$tab     = (string) ($choice['tab'] ?? 'numbers');

if (!$tabs) {
    view('_empty', [
        'seo' => ['title' => setting('site_name', 'OTP Flash'), 'robots' => 'noindex, follow'],
    ]);
}
if (!isset($tabs[$tab])) {
    $tab = (string) array_key_first($tabs);
}

// ?q= is the search box search engines are told about (SearchAction): it
// lands on the picker with the query typed in.
$query     = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
$serviceId = isset($choice['service']) ? (int) $choice['service']['id'] : null;
$countryId = isset($choice['country']) ? (int) $choice['country']['id'] : null;
$state     = picker_state($tab, $serviceId, $countryId);
$service   = $state['service'];
$country   = $state['country'];
$siteName  = (string) setting('site_name', 'OTP Flash');

// A landing page whose service or country has nothing on sale right now is
// still a real page - it just says so - but it is not one to rank.
$empty = ($serviceId && !$service) || ($countryId && !$country);

// ------------------------------------------------------------------ words --
$serviceRow = $serviceId ? one('SELECT * FROM services WHERE id = ?', [$serviceId]) : null;
$countryRow = $countryId ? one('SELECT * FROM countries WHERE id = ?', [$countryId]) : null;
$headline   = 'Virtual numbers for OTP verification';
$lead       = 'Pick an app and a country, pay, and your number is on screen in seconds. The code '
            . 'appears the moment the SMS lands - and if it never does, the next number is free.';
$intro      = '';
// The one line under the headline. The lead above is for search engines and
// link previews; on the page, three lines of prose between the headline and
// the picker only push the picker off a phone's screen.
$tagline    = 'Choose an app and a country. Your code shows up right here.';

if ($tab === 'numbers' && $serviceRow && $countryRow) {
    $headline = $serviceRow['name'] . ' number for ' . $countryRow['name'];
    $lead     = service_intro(['name' => $serviceRow['name']],
        ['name' => $countryRow['name'], 'dial' => $countryRow['dial_code']]);
    $tagline  = ($countryRow['dial_code'] !== '' ? 'A +' . $countryRow['dial_code'] : 'A ' . $countryRow['name'])
              . ' number for ' . $serviceRow['name'] . '. The code shows up right here.';
} elseif ($tab === 'numbers' && $serviceRow) {
    $headline = 'Virtual number for ' . $serviceRow['name'];
    $lead     = service_intro(['name' => $serviceRow['name']]);
    $intro    = (string) ($serviceRow['intro'] ?? '');
    $tagline  = 'Choose a country. Your ' . $serviceRow['name'] . ' code shows up right here.';
} elseif ($tab === 'numbers' && $countryRow) {
    $headline = $countryRow['name'] . ' virtual numbers';
    $lead     = 'Real ' . $countryRow['name'] . ' numbers (+' . $countryRow['dial_code'] . ') for receiving '
              . 'verification codes from WhatsApp, Telegram, Google and hundreds more apps.';
    $tagline  = 'Choose the app. Your ' . $countryRow['name'] . ' number is ready in seconds.';
} elseif ($tab === 'email') {
    $headline = $serviceRow ? 'Temporary email for ' . $serviceRow['name'] : 'Temporary email for OTP codes';
    $lead     = 'A fresh inbox on Gmail, Outlook and more, made for receiving a verification code. '
              . 'The code shows up here by itself - no inbox to log in to.';
    $tagline  = 'A fresh inbox for any sign-up. The code shows up right here.';
} elseif ($tab === 'rental') {
    $headline = $countryRow ? 'Rent a ' . $countryRow['name'] . ' number' : 'Rent a number for days or months';
    $lead     = 'Keep the same number for a day, a week, a month or a year, with every SMS it receives '
              . 'kept in your inbox. Renew whenever you like.';
    $tagline  = 'Your own number for days or months, with an inbox for every SMS.';
}

// -------------------------------------------------------------------- SEO --
$canonical = choice_url($tab, $service ?? ($serviceRow ? ['slug' => $serviceRow['slug']] : null),
    $country ?? ($countryRow ? ['slug' => $countryRow['slug']] : null));
$isHome    = $tab === 'numbers' && !$serviceRow && !$countryRow;

$metaTitle = match (true) {
    $isHome => '',
    $serviceRow && $countryRow => 'Buy ' . $serviceRow['name'] . ' Virtual Number ' . $countryRow['name']
        . ' (+' . $countryRow['dial_code'] . ') - Receive OTP',
    $serviceRow !== null && $tab === 'numbers' => trim((string) $serviceRow['meta_title'])
        ?: 'Buy ' . $serviceRow['name'] . ' Virtual Number for OTP Verification',
    default => $headline,
};
$metaDescription = match (true) {
    $isHome => (string) setting('seo_description', ''),
    $serviceRow !== null && $countryRow === null && $tab === 'numbers'
        => trim((string) $serviceRow['meta_description']) ?: $lead,
    default => $lead,
};

$faqs   = all('SELECT question, answer FROM faqs WHERE is_active = 1 ORDER BY sort_order, id');
[$low, $high] = price_range($state['options']);
$trail  = [['Home', url('')]];
if ($tab !== 'numbers') {
    $trail[] = [STORE_TABS[$tab]['label'], tab_url($tab)];
}
if ($serviceRow) {
    $trail[] = [$serviceRow['name'], $tab === 'numbers' ? url($serviceRow['slug']) : url('temporary-email/' . $serviceRow['slug'])];
}
if ($countryRow) {
    $trail[] = [$countryRow['name'], $canonical];
}

$schema = [];
if ($isHome) {
    $schema[] = schema_website();
}
if (count($trail) > 1) {
    $schema[] = schema_breadcrumbs($trail);
}
if ($state['options']) {
    $schema[] = schema_product($headline, $lead, $canonical, $low, $high, count($state['options']));
} elseif ($serviceRow && $service) {
    $schema[] = schema_product($headline, $lead, $canonical, (float) $service['from'], (float) $service['from'],
        max(1, (int) $service['count']));
}
if ($faqs && ($isHome || $tab !== 'numbers')) {
    $schema[] = schema_faq($faqs);
}

view('home', [
    'seo' => [
        'title'       => $metaTitle,
        'description' => $metaDescription,
        'canonical'   => $canonical,
        'schema'      => $schema,
        'type'        => $state['options'] ? 'product' : 'website',
        'robots'      => $empty ? 'noindex, follow' : null,
        'home'        => $isHome,
    ],
    'tabs'      => $tabs,
    'state'     => $state,
    'headline'  => $headline,
    'lead'      => $lead,
    'tagline'   => $tagline,
    'intro'     => $intro,
    'isHome'    => $isHome,
    'empty'     => $empty,
    'query'     => $query,
    'trail'     => $trail,
    'faqs'      => $faqs,
    'posts'     => optional_all("SELECT slug, title, excerpt, cover_path, published_at FROM posts
                                  WHERE status = 'published' AND published_at <= ? AND noindex = 0
                               ORDER BY published_at DESC LIMIT 3", [date('Y-m-d H:i:s')]),
    'counts'    => [
        'countries' => (int) col('SELECT COUNT(DISTINCT country_id) FROM offers WHERE is_active = 1 AND missing = 0', [], 0),
        'services'  => (int) col('SELECT COUNT(DISTINCT service_id) FROM offers WHERE is_active = 1 AND missing = 0', [], 0),
    ],
    'user'      => current_user(),
]);
