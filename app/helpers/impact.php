<?php
/**
 * What a delete takes with it.
 *
 * Asked before the row goes, so the admin is shown the consequences rather
 * than told about them afterwards. The counts are read live because "3
 * orders" is only worth printing if it is true at the moment of pressing.
 *
 * Every line says one of two things: `goes` for data that is deleted with it,
 * `stays` for data that survives in a changed shape. The second kind is the
 * one that needs saying out loud - an order whose service is deleted still
 * reads correctly, and nobody believes that until it is written down.
 */
require_once APP_PATH . '/helpers/paging.php';   // plural()

/**
 * @return array{lines: array<int, array{text: string, effect: string}>, blocked: ?string}
 */
function delete_impact(string $table, int $id): array
{
    $lines   = [];
    $blocked = null;

    $goes  = static fn(string $text): array => ['text' => $text, 'effect' => 'goes'];
    $stays = static fn(string $text): array => ['text' => $text, 'effect' => 'stays'];

    if ($table === 'services' || $table === 'countries') {
        $column = $table === 'services' ? 'service_id' : 'country_id';
        $offers = (int) col("SELECT COUNT(*) FROM offers WHERE {$column} = ?", [$id], 0);
        $orders = (int) col("SELECT COUNT(*) FROM orders WHERE {$column} = ?", [$id], 0);
        $open   = (int) col("SELECT COUNT(*) FROM orders WHERE {$column} = ? AND status IN ('paid','processing')",
                            [$id], 0);

        if ($offers) {
            $lines[] = $stays(qty_fmt($offers) . ' ' . plural('offer', $offers)
                . ' lose their ' . ($table === 'services' ? 'service' : 'country')
                . ' and drop off the shop. The next sync puts '
                . ($offers === 1 ? 'it' : 'them') . ' back under a fresh row');
        }
        if ($table === 'services') {
            $lines[] = $goes('its SEO text and page');
        }
        if ($orders) {
            $lines[] = $stays(qty_fmt($orders) . ' ' . plural('order', $orders)
                . ' - they keep the names and prices they were placed with');
        }
        if ($open) {
            $blocked = qty_fmt($open) . ' ' . plural('order', $open) . ' ' . ($open === 1 ? 'is' : 'are')
                . ' still waiting on a number here. Let ' . ($open === 1 ? 'it' : 'them')
                . ' finish first. To take it off the shop now, switch it off instead.';
        }
    }

    if ($table === 'payment_methods') {
        $orders  = (int) col('SELECT COUNT(*) FROM orders WHERE payment_method_id = ?', [$id], 0);
        $waiting = (int) col("SELECT COUNT(*) FROM orders WHERE payment_method_id = ? AND status = 'pending'",
                             [$id], 0);
        $lines[] = $goes('its account details and gateway keys');
        if ($orders) {
            $lines[] = $stays(qty_fmt($orders) . ' ' . plural('order', $orders)
                . ' paid this way keep their transaction ids');
        }
        if ($waiting) {
            $blocked = qty_fmt($waiting) . ' unpaid ' . plural('order', $waiting) . ' ' . ($waiting === 1 ? 'is' : 'are')
                . ' waiting to be confirmed through this method. Switch it off instead, '
                . 'and delete it once they are settled.';
        }
    }

    if ($table === 'post_categories') {
        $posts = (int) col('SELECT COUNT(*) FROM posts WHERE category_id = ?', [$id], 0);
        if ($posts) {
            $lines[] = $stays(qty_fmt($posts) . ' ' . plural('post', $posts)
                . ' stay published, with no category');
        }
    }

    return ['lines' => $lines, 'blocked' => $blocked];
}
