<?php
/**
 * Calls to OTPGet and the payment gateways, each one opening onto what was
 * asked and what came back word for word.
 *
 * Drawn on an order and on the API log, so the two read the same. Shut by
 * default: the summary line is usually the answer, and the raw reply is
 * there for the time it is not.
 *
 * @var array $calls  order_calls rows, newest first
 * @var bool  $withOrder  show which order each call was for
 */
$withOrder = $withOrder ?? false;
$actions = [
    'buy' => 'Buy number', 'getNumber' => 'Buy number', 'getStatus' => 'Check SMS', 'setStatus' => 'Change status',
    'start' => 'Start payment', 'submit' => 'Customer submitted', 'check' => 'Check payment',
    'webhook' => 'Webhook', 'qr' => 'QR code',
];
?>
<?php if (!$calls): ?>
  <p class="muted calls-empty">No calls yet. Every request to OTPGet or a payment gateway for this order will appear here,
    with the reply as it came.</p>
<?php else: ?>
  <div class="calls">
    <?php foreach ($calls as $call): ?>
      <details class="call call-<?= (int) $call['ok'] === 1 ? 'ok' : 'bad' ?>">
        <summary>
          <span class="call-dot" aria-hidden="true"></span>
          <span class="call-who">
            <b><?= e($call['party'] !== '' ? $call['party'] : $call['source']) ?></b>
            <span class="muted"><?= e($actions[$call['action']] ?? $call['action']) ?></span>
            <?php if ($withOrder && !empty($call['order_code'])): ?>
              <a class="call-order mono" href="<?= e(url('admin/orders/' . (int) $call['order_id'])) ?>"><?= e($call['order_code']) ?></a>
            <?php endif; ?>
          </span>
          <span class="call-what"><?= e($call['summary'] !== '' ? $call['summary'] : '(empty reply)') ?></span>
          <span class="call-when">
            <?= e(when($call['created_at'], 'd M, H:i:s')) ?>
            <?php if ((int) $call['repeats'] > 1): ?>
              <span class="call-rep" title="The same answer again, until <?= e(when($call['last_at'], 'd M, H:i:s')) ?>">&times;<?= (int) $call['repeats'] ?></span>
            <?php endif; ?>
          </span>
        </summary>
        <div class="call-body">
          <?php if (trim((string) $call['request']) !== ''): ?>
            <small>Request <span class="muted">(keys and tokens masked)</span></small>
            <pre><?= e((string) $call['request']) ?></pre>
          <?php endif; ?>
          <small>Reply<?= (int) $call['http_status'] ? ' &middot; HTTP ' . (int) $call['http_status'] : '' ?><?= (int) $call['ms'] ? ' &middot; ' . (int) $call['ms'] . ' ms' : '' ?></small>
          <pre><?= e(trim((string) $call['response']) !== '' ? (string) $call['response'] : '(nothing came back)') ?></pre>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
