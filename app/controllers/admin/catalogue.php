<?php
/**
 * The catalogue: every offer synced from OTPGet, at our price.
 *
 *   GET  /admin/catalogue            filterable list
 *   POST /admin/catalogue/price      pin (or clear) one offer's price
 *   POST /admin/catalogue/toggle     switch one offer on or off
 *   POST /admin/catalogue/bulk       the same for every ticked row
 *
 * Offers are made by the sync and their cost is always OTPGet's; what is the
 * admin's is whether one is sold and, if they want, a price pinned by hand,
 * which no sync or markup change ever overwrites.
 */
require_once APP_PATH . '/helpers/catalog.php';
require_once APP_PATH . '/helpers/audit.php';
require_once APP_PATH . '/helpers/paging.php';

$action = $params[0] ?? 'index';
$back   = static function (): never {
    $query = (string) ($_POST['back'] ?? '');
    redirect('admin/catalogue' . ($query !== '' ? '?' . $query : ''));
};

if ($action === 'price' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id    = (int) ($_POST['id'] ?? 0);
    $typed = trim((string) ($_POST['price'] ?? ''));
    $value = $typed === '' ? null : round(max(0, (float) $typed), 2);
    $offer = one('SELECT * FROM offers WHERE id = ?', [$id]);
    if ($offer) {
        update_row('offers', ['price_override' => $value], 'id = ?', [$id]);
        audit('offer.price', ['entity' => 'offers', 'entity_id' => $id, 'severity' => 'warn',
            'summary' => $offer['provider_name'] . ': price ' . ($value === null ? 'back to automatic' : 'pinned at ' . money($value))]);
        flash('success', $value === null ? 'Back to the automatic price.' : 'Price pinned at ' . money($value) . '.');
    }
    $back();
}

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    q('UPDATE offers SET is_active = 1 - is_active WHERE id = ?', [$id]);
    audit('offer.toggled', ['entity' => 'offers', 'entity_id' => $id, 'summary' => 'Offer #' . $id . ' switched']);
    $back();
}

if ($action === 'bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $do  = (string) ($_POST['do'] ?? '');
    if (!$ids) {
        flash('error', 'Tick at least one offer first.');
        $back();
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    switch ($do) {
        case 'on':
            q("UPDATE offers SET is_active = 1 WHERE id IN ({$in})", $ids);
            break;
        case 'off':
            q("UPDATE offers SET is_active = 0 WHERE id IN ({$in})", $ids);
            break;
        case 'unpin':
            q("UPDATE offers SET price_override = NULL WHERE id IN ({$in})", $ids);
            break;
        case 'pin':
            $price = round(max(0, (float) ($_POST['price'] ?? 0)), 2);
            if ($price <= 0) {
                flash('error', 'Type the price to pin first.');
                $back();
            }
            q("UPDATE offers SET price_override = ? WHERE id IN ({$in})", array_merge([$price], $ids));
            break;
        default:
            flash('error', 'Unknown action.');
            $back();
    }
    audit('offer.bulk', ['entity' => 'offers', 'severity' => 'warn',
        'summary' => count($ids) . ' offer(s): ' . $do]);
    flash('success', qty_fmt(count($ids)) . ' offer(s) updated.');
    $back();
}

// ------------------------------------------------------------------ list --
$filters = [
    'q'       => trim((string) ($_GET['q'] ?? '')),
    'product' => (string) ($_GET['product'] ?? ''),
    'server'  => (string) ($_GET['server'] ?? ''),
    'state'   => (string) ($_GET['state'] ?? ''),
    'service' => (string) ($_GET['service'] ?? ''),
    'country' => (string) ($_GET['country'] ?? ''),
    'sort'    => (string) ($_GET['sort'] ?? ''),
];

$where = [];
$binds = [];
if ($filters['q'] !== '') {
    $where[] = '(o.provider_name LIKE ? OR s.name LIKE ? OR c.name LIKE ? OR o.provider_country LIKE ? OR o.provider_service = ?)';
    $like    = '%' . $filters['q'] . '%';
    array_push($binds, $like, $like, $like, $like, $filters['q']);
}
if (isset(PRODUCTS[$filters['product']])) {
    $where[] = 'o.product = ?';
    $binds[] = $filters['product'];
}
if ($filters['server'] !== '') {
    $where[] = 'o.server = ?';
    $binds[] = $filters['server'];
}
if (ctype_digit($filters['service'])) {
    $where[] = 'o.service_id = ?';
    $binds[] = (int) $filters['service'];
}
if (ctype_digit($filters['country'])) {
    $where[] = 'o.country_id = ?';
    $binds[] = (int) $filters['country'];
}
$where[] = match ($filters['state']) {
    'off'     => 'o.is_active = 0',
    'missing' => 'o.missing = 1',
    'pinned'  => 'o.price_override IS NOT NULL',
    'noprice' => 'o.price <= 0',
    'all'     => '1 = 1',
    default   => 'o.missing = 0',
};

$whereSql = ' WHERE ' . implode(' AND ', $where);
$from     = ' FROM offers o LEFT JOIN services s ON s.id = o.service_id LEFT JOIN countries c ON c.id = o.country_id';
$total    = (int) col('SELECT COUNT(*)' . $from . $whereSql, $binds, 0);
$pages    = paging($total);
$order    = match ($filters['sort']) {
    'price'  => 'COALESCE(o.price_override, o.price) ASC',
    'margin' => '(COALESCE(o.price_override, o.price) - o.cost) ASC',
    'synced' => 'o.synced_at DESC',
    default  => 's.name, c.name, o.product, o.server',
};

$offers = all('SELECT o.*, s.name AS service_name, s.icon, s.color, c.name AS country_name, c.iso2' . $from . $whereSql
    . ' ORDER BY ' . $order . ' LIMIT ' . $pages['per'] . ' OFFSET ' . $pages['offset'], $binds);

view('admin/catalogue', [
    'title'     => 'Catalogue',
    'subtitle'  => qty_fmt($total) . ' offers match',
    'offers'    => $offers,
    'filters'   => $filters,
    'paging'    => $pages,
    'services'  => array_column(all('SELECT id, name FROM services ORDER BY name'), 'name', 'id'),
    'countries' => array_column(all('SELECT id, name FROM countries ORDER BY name'), 'name', 'id'),
], 'layouts/admin');
