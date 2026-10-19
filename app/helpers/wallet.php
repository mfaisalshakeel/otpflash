<?php
/**
 * The customer wallet.
 *
 * `users.balance` is the number and `wallet_transactions` is the story of
 * it: every change to one writes a row in the other, in the same database
 * transaction, carrying the balance it left behind. So a balance anyone
 * questions can be read back line by line, and the two can never disagree
 * because one was written and the other was not.
 *
 * The balance only moves through the two functions below, and both move it
 * with arithmetic inside one UPDATE - never "read it, add, write it back",
 * which is how two purchases in two tabs both spend the same rupee.
 */

/** What each kind of line is called on the statement. */
const WALLET_TYPES = [
    'deposit'  => 'Deposit',
    'purchase' => 'Purchase',
    'refund'   => 'Refund',
    'rental'   => 'Rental',
    'renewal'  => 'Rental renewal',
    'adjust'   => 'Adjustment',
];

/**
 * Add money.
 *
 * @return float the balance afterwards
 */
function wallet_credit(int $userId, float $amount, string $type, ?int $orderId = null,
                       string $note = '', ?int $adminId = null): float
{
    return wallet_move($userId, round(abs($amount), 2), $type, $orderId, $note, $adminId);
}

/**
 * Take money, only if it is there.
 *
 * The balance test is inside the UPDATE, so a second purchase racing the
 * first finds the money already gone rather than taking it twice.
 *
 * @return bool false when the balance was too low, and nothing was taken
 */
function wallet_debit(int $userId, float $amount, string $type, ?int $orderId = null,
                      string $note = '', ?int $adminId = null): bool
{
    return wallet_move($userId, -round(abs($amount), 2), $type, $orderId, $note, $adminId) !== null;
}

/** The one place the balance changes. Null when a debit would go below zero. */
function wallet_move(int $userId, float $amount, string $type, ?int $orderId, string $note, ?int $adminId): ?float
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }

    try {
        $now  = date('Y-m-d H:i:s');
        $sql  = 'UPDATE users SET balance = balance + ?, updated_at = ? WHERE id = ?';
        $args = [$amount, $now, $userId];
        if ($amount < 0) {
            $sql   .= ' AND balance >= ?';
            $args[] = -$amount;
        }

        if (q($sql, $args)->rowCount() !== 1) {
            if ($own) {
                $pdo->rollBack();
            }
            return null;
        }

        $after = round((float) col('SELECT balance FROM users WHERE id = ?', [$userId], 0), 2);

        insert_row('wallet_transactions', [
            'user_id'       => $userId,
            'type'          => array_key_exists($type, WALLET_TYPES) ? $type : 'adjust',
            'amount'        => $amount,
            'balance_after' => $after,
            'order_id'      => $orderId,
            'note'          => mb_substr($note, 0, 255),
            'admin_id'      => $adminId,
            'created_at'    => $now,
        ]);

        if ($own) {
            $pdo->commit();
        }
        return $after;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * A paid deposit order lands in the wallet.
 *
 * Claimed from paid to completed first, so a deposit confirmed by the QR
 * page and by cron in the same second is credited once.
 *
 * @return array{0: bool, 1: string}
 */
function wallet_deposit_complete(array $order): array
{
    if (empty($order['user_id'])) {
        return [false, 'A deposit with no account to put it in.'];
    }

    $now = date('Y-m-d H:i:s');
    if (!claim('orders', ['status' => 'completed', 'completed_at' => $now, 'updated_at' => $now],
            "id = ? AND product = 'deposit' AND status = 'paid'", [(int) $order['id']])) {
        return [false, 'This deposit was already added.'];
    }

    $amount = round((float) $order['price'], 2);
    $after  = wallet_credit((int) $order['user_id'], $amount, 'deposit', (int) $order['id'],
        'Deposit ' . $order['code']);

    order_log((int) $order['id'], 'Added ' . money($amount) . ' to the wallet. Balance now ' . money($after) . '.');
    audit('wallet.deposit', ['entity' => 'users', 'entity_id' => (int) $order['user_id'],
        'summary' => $order['code'] . ': ' . money($amount) . ' deposited']);

    return [true, 'Added to the wallet.'];
}

/** A page of one customer's statement, newest first. */
function wallet_statement(int $userId, int $limit = 50, int $offset = 0): array
{
    return all(
        'SELECT w.*, o.code AS order_code
           FROM wallet_transactions w
           LEFT JOIN orders o ON o.id = w.order_id
          WHERE w.user_id = ?
       ORDER BY w.id DESC
          LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
        [$userId]
    );
}
