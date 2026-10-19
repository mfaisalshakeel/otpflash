<?php
/** Admin dashboard: how the shop is doing, and what needs attention. */
require_once APP_PATH . '/helpers/stats.php';
require_once APP_PATH . '/helpers/charts.php';

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

[$earning, $earningValues] = earning_clause();

$daily    = stats_daily(30);
$month    = stats_window(30);
$prevMonth= stats_previous_window(30);
$week     = stats_window(7);
$finish   = stats_completion_rate(30);

$stats = [
    'orders_today'  => (int) col('SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?', [$today], 0),
    'orders_yday'   => (int) col('SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?', [$yesterday], 0),
    'revenue_today' => (float) col(
        "SELECT COALESCE(SUM(price),0) FROM orders WHERE DATE(created_at) = ? AND {$earning}",
        array_merge([$today], $earningValues), 0),
    'revenue_yday'  => (float) col(
        "SELECT COALESCE(SUM(price),0) FROM orders WHERE DATE(created_at) = ? AND {$earning}",
        array_merge([$yesterday], $earningValues), 0),
    // Pending with a reference only: a pending order with nothing submitted is
    // a customer who has not paid yet, not something waiting on the admin.
    'pending'       => (int) col("SELECT COUNT(*) FROM orders WHERE status = 'pending' AND trx_id <> ''", [], 0),
    'api_errors'    => (int) col("SELECT COUNT(*) FROM orders WHERE status = 'api_error'", [], 0),
    'open'          => (int) col("SELECT COUNT(*) FROM orders WHERE status = 'processing'", [], 0),
    'offers'        => (int) col('SELECT COUNT(*) FROM offers WHERE is_active = 1 AND missing = 0', [], 0),
    'countries'     => (int) col('SELECT COUNT(DISTINCT country_id) FROM offers WHERE is_active = 1 AND missing = 0', [], 0),
    'unread'        => (int) col('SELECT COUNT(*) FROM messages WHERE is_read = 0', [], 0),
    'avg_order'     => $month['orders'] > 0 ? $month['revenue'] / $month['orders'] : 0.0,
];

$money = static fn(float $value): string => money($value);
$count = static fn(float $value): string => qty_fmt((int) $value);

view('admin/index', [
    'title'     => 'Dashboard',
    'subtitle'  => 'The last 30 days, and what needs you today',
    'stats'     => $stats,
    'month'     => $month,
    'prevMonth' => $prevMonth,
    'week'      => $week,
    'finish'    => $finish,
    'charts'    => [
        'revenue' => chart_area(
            array_map(static fn(array $d): array => ['label' => $d['label'], 'value' => $d['revenue']], $daily),
            'Revenue, last 30 days', $money, 'revenue'),
        'orders'  => chart_columns(
            array_map(static fn(array $d): array => ['label' => $d['label'], 'value' => (float) $d['orders']], $daily),
            'Orders, last 30 days', $count, 'orders'),
        'profit'  => chart_area(
            array_map(static fn(array $d): array =>
                ['label' => $d['label'], 'value' => max(0, $d['revenue'] - $d['cost'])], $daily),
            'Profit, last 30 days', $money, 'profit'),
    ],
    'statuses'  => stats_by_status(),
    'topServices' => stats_top_services(30, 6),
    'countryMix'  => stats_by_country(30),
    'productMix'  => stats_by_product(30),
    'customers'   => stats_customers(30),
    'balance'     => otpget_balance_known(),
    'configured'  => otpget_configured(),
    'syncedAt'    => (string) setting('catalogue_synced_at', ''),
    'recent'    => all('SELECT * FROM orders ORDER BY created_at DESC LIMIT 8'),
], 'layouts/admin');
