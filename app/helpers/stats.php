<?php
/**
 * The numbers behind the dashboard.
 *
 * Kept out of the controller because every one of them is a decision about
 * what counts - and they have to agree with each other. The rules throughout:
 * an order only counts as money once it is paid, so `pending`, `cancelled`
 * and `refunded` are left out of every revenue, cost and profit figure; and a
 * wallet deposit is never revenue - it is the customer's money held for them,
 * and it becomes ours only when it buys something, which is its own order.
 * Both kinds are still counted as orders, because they happened.
 *
 * Dates are worked out in PHP and bound, never asked of the database: MySQL
 * and SQLite disagree about INTERVAL.
 */

/** The statuses that represent money actually taken. */
function earning_statuses(): array
{
    return ['paid', 'processing', 'completed', 'expired', 'api_error'];
}

/** `status IN (?, ?, ...) AND product <> 'deposit'` plus the values to bind. */
function earning_clause(string $column = 'status'): array
{
    $statuses = earning_statuses();
    $product  = str_contains($column, '.') ? substr($column, 0, strpos($column, '.') + 1) . 'product' : 'product';
    return [
        $column . ' IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ") AND {$product} <> 'deposit'",
        $statuses,
    ];
}

/** Midnight, n days ago, as the database stores it. */
function days_ago(int $days): string
{
    return date('Y-m-d 00:00:00', strtotime('-' . $days . ' days'));
}

/**
 * One row per day for the last $days, with no gaps.
 *
 * A missing day is a real zero, and a chart that skips it draws a straight
 * line through a quiet week as if it never happened.
 *
 * @return array<int, array{date:string, label:string, orders:int, revenue:float, cost:float}>
 */
function stats_daily(int $days = 30): array
{
    $since = days_ago($days - 1);

    // Two queries rather than one with CASE WHEN, because the status list
    // would then appear twice in the same statement and every placeholder
    // after the first set would be bound to the wrong value.
    $counted = [];
    foreach (all(
        'SELECT DATE(created_at) AS d, COUNT(*) AS orders
           FROM orders WHERE created_at >= ? GROUP BY DATE(created_at)',
        [$since]
    ) as $row) {
        $counted[$row['d']] = (int) $row['orders'];
    }

    [$earning, $values] = earning_clause();
    $earned = [];
    foreach (all(
        "SELECT DATE(created_at) AS d,
                COALESCE(SUM(price), 0) AS revenue,
                COALESCE(SUM(cost), 0)  AS cost
           FROM orders
          WHERE created_at >= ? AND {$earning}
       GROUP BY DATE(created_at)",
        array_merge([$since], $values)
    ) as $row) {
        $earned[$row['d']] = $row;
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime('-' . $i . ' days'));
        $series[] = [
            'date'    => $date,
            'label'   => date('j M', strtotime($date)),
            'orders'  => $counted[$date] ?? 0,
            'revenue' => (float) ($earned[$date]['revenue'] ?? 0),
            'cost'    => (float) ($earned[$date]['cost'] ?? 0),
        ];
    }

    return $series;
}

/** Revenue, cost, profit and order count over a window ending now. */
function stats_window(int $days): array
{
    $since = days_ago($days - 1);

    $orders = (int) col('SELECT COUNT(*) FROM orders WHERE created_at >= ?', [$since], 0);

    [$earning, $values] = earning_clause();
    $row = one(
        "SELECT COALESCE(SUM(price), 0) AS revenue, COALESCE(SUM(cost), 0) AS cost
           FROM orders WHERE created_at >= ? AND {$earning}",
        array_merge([$since], $values)
    ) ?: [];

    $revenue = (float) ($row['revenue'] ?? 0);
    $cost    = (float) ($row['cost'] ?? 0);

    return [
        'orders'  => $orders,
        'revenue' => $revenue,
        'cost'    => $cost,
        'profit'  => $revenue - $cost,
        'margin'  => $revenue > 0 ? ($revenue - $cost) / $revenue * 100 : 0.0,
    ];
}

/** The same window, ending where the current one began - for "vs" lines. */
function stats_previous_window(int $days): array
{
    [$earning, $values] = earning_clause();

    $row = one(
        "SELECT COUNT(*) AS orders, COALESCE(SUM(price), 0) AS revenue
           FROM orders
          WHERE created_at >= ? AND created_at < ? AND {$earning}",
        array_merge([days_ago(($days * 2) - 1), days_ago($days - 1)], $values)
    ) ?: [];

    return [
        'orders'  => (int) ($row['orders'] ?? 0),
        'revenue' => (float) ($row['revenue'] ?? 0),
    ];
}

/** How many orders sit in each status, newest-relevant first. */
function stats_by_status(): array
{
    $counts = [];
    foreach (all('SELECT status, COUNT(*) AS n FROM orders GROUP BY status') as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }

    // A fixed order, so the list does not reshuffle as the numbers move.
    $order = ['pending', 'paid', 'processing', 'completed', 'expired',
              'api_error', 'cancelled', 'refunded'];

    $out = [];
    foreach ($order as $status) {
        $out[] = ['status' => $status, 'count' => $counts[$status] ?? 0];
    }
    return $out;
}

/** Best-selling services by revenue over a window. */
function stats_top_services(int $days = 30, int $limit = 6): array
{
    [$earning, $values] = earning_clause();

    return all(
        "SELECT service_name AS label, COUNT(*) AS orders, COALESCE(SUM(price), 0) AS revenue
           FROM orders
          WHERE created_at >= ? AND {$earning} AND service_name <> ''
       GROUP BY service_name
       ORDER BY revenue DESC
          LIMIT " . max(1, min(20, $limit)),
        array_merge([days_ago($days - 1)], $values)
    );
}

/** Revenue per country over a window. */
function stats_by_country(int $days = 30, int $limit = 8): array
{
    [$earning, $values] = earning_clause();

    return all(
        "SELECT CASE WHEN country_name = '' THEN 'No country' ELSE country_name END AS label,
                COUNT(*) AS orders, COALESCE(SUM(price), 0) AS revenue
           FROM orders
          WHERE created_at >= ? AND {$earning}
       GROUP BY CASE WHEN country_name = '' THEN 'No country' ELSE country_name END
       ORDER BY revenue DESC
          LIMIT " . max(1, min(20, $limit)),
        array_merge([days_ago($days - 1)], $values)
    );
}

/** What each OTPGet product has earned and cost over a window. */
function stats_by_product(int $days = 30): array
{
    [$earning, $values] = earning_clause();

    $rows = all(
        "SELECT product, COUNT(*) AS orders, COALESCE(SUM(price), 0) AS revenue,
                COALESCE(SUM(cost), 0) AS cost
           FROM orders
          WHERE created_at >= ? AND {$earning}
       GROUP BY product
       ORDER BY revenue DESC",
        array_merge([days_ago($days - 1)], $values)
    );
    foreach ($rows as &$row) {
        $row['label'] = PRODUCTS[$row['product']]['name']
            ?? ($row['product'] === 'renewal' ? 'Rental renewals' : ucfirst((string) $row['product']));
    }
    return $rows;
}

/** Customers, wallets and money held for them. */
function stats_customers(int $days = 30): array
{
    return [
        'total'    => optional_count('SELECT COUNT(*) FROM users'),
        'new'      => optional_count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [days_ago($days - 1)]),
        'held'     => (float) (optional_all('SELECT COALESCE(SUM(balance), 0) AS b FROM users')[0]['b'] ?? 0),
        'deposits' => (float) (optional_all("SELECT COALESCE(SUM(price), 0) AS d FROM orders
                         WHERE product = 'deposit' AND status = 'completed' AND created_at >= ?",
                         [days_ago($days - 1)])[0]['d'] ?? 0),
        'rentals'  => optional_count("SELECT COUNT(*) FROM rentals WHERE status = 'active'"),
    ];
}

/** Share of finished orders that actually completed. */
function stats_completion_rate(int $days = 30): array
{
    // Numbers only: a deposit always "completes", and counting it would
    // flatter the one rate here that says how often a customer got a code.
    $done = (int) col(
        "SELECT COUNT(*) FROM orders WHERE created_at >= ? AND status = 'completed'
            AND product IN ('sms','premium','cheap','email')",
        [days_ago($days - 1)], 0
    );
    $failed = (int) col(
        "SELECT COUNT(*) FROM orders WHERE created_at >= ? AND status IN ('expired','refunded','api_error')
            AND product IN ('sms','premium','cheap','email')",
        [days_ago($days - 1)], 0
    );
    $total = $done + $failed;

    return [
        'completed' => $done,
        'failed'    => $failed,
        'rate'      => $total > 0 ? $done / $total * 100 : 0.0,
    ];
}
