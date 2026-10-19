<?php
/**
 * The QR payment screen, whatever gateway drew the code
 * Clean, minimalistic design matching exact mobile specifications.
 * No headers, no footers, centered white card on light background.
 *
 * @var array  $order   @var array $method  @var array $qr  @var string $svg
 */
$siteName = (string) setting('site_name', 'DARK ADDA');
$amount = $qr['amount'] !== '' ? $qr['amount'] : money((float) $order['price']);
// The code, not the row's id: it is what the admin searches by, so it is
// the only number worth putting in front of a customer.
$orderId = (string) $order['code'];

// Where the popup sends them: the home page opens its tracking panel on this
// parameter, fills the order in and looks it up by itself.
$trackUrl = url('?track=' . urlencode($orderId));
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <title>Pay <?= e($amount) ?> | <?= e($siteName) ?></title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <?php if ($icon = setting('favicon_path')): ?>
    <link rel="icon" href="<?= e(url($icon)) ?>">
  <?php endif; ?>
  <style>
    *, *::before, *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    body {
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      background-color: #f4f5fa;
      color: #0f172a;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px 14px;
      -webkit-font-smoothing: antialiased;
    }
    .qr-page-container {
      width: 100%;
      max-width: 410px;
      margin: 0 auto;
    }
    .qr-card {
      background: #ffffff;
      border-radius: 24px;
      box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05), 0 0 1px 1px rgba(0, 0, 0, 0.02);
      padding: 30px 24px 24px;
      text-align: center;
      width: 100%;
      position: relative;
    }
    .brand-title {
      font-size: 22px;
      font-weight: 800;
      color: #1e1b4b;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      margin-bottom: 4px;
    }
    .payable-subtitle {
      font-size: 13.5px;
      font-weight: 500;
      color: #64748b;
      margin-bottom: 4px;
    }
    .payable-amount {
      font-size: 34px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -0.02em;
      margin-bottom: 18px;
    }
    .qr-box-wrapper {
      display: inline-block;
      background: #ffffff;
      padding: 14px;
      border-radius: 18px;
      border: 1.5px solid #e2e8f0;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
      margin-bottom: 4px;
    }
    .qr-box-wrapper svg,
    .qr-box-wrapper img {
      display: block;
      width: 230px;
      height: 230px;
      max-width: 100%;
      height: auto;
      margin: 0 auto;
      border-radius: 6px;
    }
    /* Under the code, side by side and equal: neither is the thing to do
       first - which one you want depends on whether the bank app is on this
       screen or another one. */
    /* The steps, under the code and above the chrome. Numbered because they
       happen in an order; small because the code above them is the thing to
       look at. */
    .qr-steps {
      margin: 16px 0 0;
      padding: 0 0 0 20px;
      text-align: left;
      font-size: 13px;
      line-height: 1.55;
      color: #475569;
    }
    .qr-steps li { margin-bottom: 7px; }
    .qr-steps li:last-child { margin-bottom: 0; }
    .qr-steps b { color: #0f172a; font-weight: 700; }
    .qr-steps i { font-style: normal; font-weight: 600; color: #334155; }
    .qr-steps-note {
      margin: 12px 0 0;
      padding: 10px 12px;
      background: #f1f5f9;
      border-radius: 10px;
      font-size: 12.5px;
      line-height: 1.5;
      color: #475569;
      text-align: left;
    }

    .qr-actions {
      display: flex;
      gap: 10px;
      margin: 14px 0 2px;
    }
    .qr-action {
      flex: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      padding: 11px 10px;
      background: #f1f5f9;
      color: #334155;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      font-family: inherit;
      font-size: 13.5px;
      font-weight: 700;
      cursor: pointer;
      transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }
    .qr-action:hover {
      background: #e2e8f0;
      border-color: #cbd5e1;
    }
    .qr-action:active {
      transform: translateY(1px);
    }
    .qr-action.done {
      background: #dcfce7;
      border-color: #bbf7d0;
      color: #15803d;
    }
    .qr-action svg {
      width: 16px;
      height: 16px;
      fill: none;
      stroke: currentColor;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
      flex: none;
    }

    .timer-display {
      font-size: 24px;
      font-weight: 800;
      color: #1e1b4b;
      margin-top: 14px;
      margin-bottom: 3px;
      letter-spacing: 0.02em;
    }
    .status-note {
      font-size: 13px;
      font-weight: 500;
      color: #64748b;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }
    .pulse-dot {
      display: inline-block;
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #6366f1;
      animation: pulseAnim 1.4s ease-in-out infinite;
    }
    @keyframes pulseAnim {
      0%, 100% { opacity: 0.3; transform: scale(0.85); }
      50% { opacity: 1; transform: scale(1.15); }
    }
    .divider-line {
      height: 1px;
      background: #f1f5f9;
      margin: 16px 0 14px;
      border: none;
    }
    .secure-note {
      font-size: 13.5px;
      font-weight: 500;
      color: #475569;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }

    /* Payment confirmed: a popup over the page rather than another screen,
       because the page behind it is the one the customer was watching and
       tracking opens by itself a moment later. */
    .pay-done {
      position: fixed;
      inset: 0;
      z-index: 90;
      background: rgba(15, 23, 42, 0.45);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .pay-done[hidden] { display: none; }
    .pay-done-card {
      background: #ffffff;
      border-radius: 22px;
      padding: 34px 28px 24px;
      width: 100%;
      max-width: 320px;
      text-align: center;
      box-shadow: 0 24px 60px rgba(15, 23, 42, 0.3);
      animation: payPop 0.25s cubic-bezier(0.2, 0.9, 0.3, 1.2);
    }
    @keyframes payPop {
      from { opacity: 0; transform: translateY(14px) scale(0.96); }
      to   { opacity: 1; transform: none; }
    }
    .pay-done-tick {
      width: 62px;
      height: 62px;
      border-radius: 50%;
      margin: 0 auto 16px;
      background: #16a34a;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .pay-done-tick svg {
      width: 30px;
      height: 30px;
      fill: none;
      stroke: currentColor;
      stroke-width: 3;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .pay-done-title {
      font-size: 19px;
      font-weight: 800;
      color: #0f172a;
      margin-bottom: 6px;
    }
    .pay-done-sub {
      font-size: 13px;
      color: #64748b;
    }
    .pay-done-next {
      margin-top: 16px;
      font-size: 12.5px;
      color: #94a3b8;
    }
    .pay-done-link {
      display: inline-block;
      margin-top: 8px;
      font-size: 13px;
      font-weight: 700;
      color: #6366f1;
      text-decoration: none;
    }

    /* Success & Expired Screens */
    .state-screen {
      padding: 10px 4px 6px;
    }
    .icon-circle {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 14px;
    }
    .icon-circle.success {
      background: #dcfce7;
      color: #16a34a;
    }
    .icon-circle.expired {
      background: #fef3c7;
      color: #d97706;
    }
    .icon-circle svg {
      width: 32px;
      height: 32px;
      fill: none;
      stroke: currentColor;
      stroke-width: 2.5;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .screen-title {
      font-size: 21px;
      font-weight: 800;
      color: #0f172a;
      margin-bottom: 6px;
    }
    .screen-desc {
      font-size: 13.5px;
      color: #64748b;
      margin-bottom: 20px;
      line-height: 1.5;
    }
    .order-id-box {
      background: #f8fafc;
      border: 1.5px dashed #cbd5e1;
      border-radius: 14px;
      padding: 14px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 22px;
    }
    .order-id-box .lbl {
      font-size: 12px;
      color: #64748b;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      text-align: left;
    }
    .order-id-box .val {
      font-size: 18px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: 0.02em;
      text-align: left;
    }
    .copy-btn {
      background: #e0e7ff;
      color: #4338ca;
      border: none;
      border-radius: 8px;
      padding: 8px 14px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .copy-btn:hover {
      background: #6366f1;
      color: #ffffff;
    }
    .action-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      padding: 13px;
      background: #0f172a;
      color: #ffffff;
      text-decoration: none;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 700;
      transition: opacity 0.2s ease;
      border: none;
      cursor: pointer;
    }
    .action-btn:hover {
      opacity: 0.9;
    }
    [hidden] {
      display: none !important;
    }
  </style>
</head>
<body>

<div class="qr-page-container">
  <div class="qr-card">

    <!-- 1. LIVE QR PAYMENT STATE (ACTIVE) -->
    <div id="livePaymentState">
      <h1 class="brand-title"><?= e($siteName) ?></h1>
      <p class="payable-subtitle">Payable Amount</p>
      <div class="payable-amount"><?= e($amount) ?></div>

      <div class="qr-box-wrapper">
        <?= $svg /* Built by qr_svg() helper */ ?>
      </div>

      <div class="timer-display" id="timerDisplay">30:00</div>
      <p class="status-note" id="statusNote">
        <span class="pulse-dot"></span> Waiting for payment confirmation...
      </p>

      <?php /* Two ways to take the code away with you, because the phone
               holding the bank app is often not the screen showing this.
               Saving gives you a picture to scan out of the gallery; copying
               gives you the payload itself, which is what a bank app's
               "paste QR" box wants. No "open in your app" button: that is an
               Android intent an iPhone cannot follow, and a second way to pay
               only asks the customer to choose between two things that do the
               same job. */ ?>
      <div class="qr-actions">
        <button type="button" class="qr-action" id="saveQrBtn">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>
          </svg>
          <span class="qr-action-label">Save QR</span>
        </button>
        <button type="button" class="qr-action" id="copyQrBtn"
                data-qr="<?= e((string) ($qr['text'] ?? '')) ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M9 9h10v10H9zM5 15H4V5h10v1"/>
          </svg>
          <span class="qr-action-label">Copy code</span>
        </button>
      </div>

      <?php /* Three lines, because a customer standing with a phone in one
               hand does not read a paragraph. The gateway's own caption says
               which apps work; this says what to do with them, in the order
               it is done. The last line is the one people ask about: nothing
               to send us, nothing to type, do not close the page. */ ?>
      <ol class="qr-steps">
        <li><b>Open your bank or wallet app</b> and find <i>Scan QR</i> &mdash; it is usually
          on the home screen, or under Raast, Payments or Send Money.</li>
        <li><b>Scan this code</b> from the screen. Paying from this same phone? Press
          <i>Save QR</i> above and scan the picture from your gallery instead.</li>
        <li><b>Pay <?= e($amount) ?></b> &mdash; the amount is already in the code, so there is
          nothing to type.</li>
      </ol>
      <p class="qr-steps-note">Keep this page open. It confirms by itself the moment the
        payment lands &mdash; you do not have to send us anything.</p>

      <hr class="divider-line">
      <div class="secure-note">
        <?php /* The method's own name, not a payment network written into
                 the page: this screen draws a UPI intent for one gateway and
                 a Raast QR for another, and a shop in Pakistan being told it
                 is taking "UPI" is the page talking about itself rather than
                 about the payment. */ ?>
        <span>🔒</span> Secure payment<?= ($method['name'] ?? '') !== ''
            ? ' &middot; ' . e($method['name']) : '' ?>
      </div>
    </div>

    <!-- 3. PAYMENT EXPIRED / FAILED STATE -->
    <div id="expiredPaymentState" class="state-screen" hidden>
      <div class="icon-circle expired">
        <svg viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="10"></circle>
          <polyline points="12 6 12 12 16 14"></polyline>
        </svg>
      </div>
      <h2 class="screen-title" id="failTitle">Payment Expired</h2>
      <p class="screen-desc" id="failDesc">This payment session has timed out. If you have already sent money, please wait a minute or contact support with your reference.</p>

      <a href="<?= e(url('')) ?>" class="action-btn">
        Return to Home
      </a>
    </div>

  </div>
</div>

<?php /* Shown the moment the gateway confirms the money. The page behind
         stays where it was - this is the answer, and tracking opens on its
         own a few seconds later with the order already looked up. The link
         is for the browser that will not follow a timed navigation. */ ?>
<div class="pay-done" id="paidPopup" hidden>
  <div class="pay-done-card" role="alertdialog" aria-live="assertive">
    <div class="pay-done-tick">
      <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
    </div>
    <h2 class="pay-done-title">Payment Successful</h2>
    <p class="pay-done-sub">Your order has been received</p>
    <p class="pay-done-next">Opening your order tracking in <b id="paidCount">5</b>s</p>
    <a class="pay-done-link" href="<?= e($trackUrl) ?>">Track it now &rarr;</a>
  </div>
</div>

<script>
(function () {
  var checkUrl = '<?= e(url('order/' . $order['code'] . '/check')) ?>';
  var orderCode = '<?= e($order['code']) ?>';
  var orderId = '<?= e($orderId) ?>';
  var csrfToken = '<?= e(csrf_token()) ?>';

  var trackUrl = '<?= e($trackUrl) ?>';

  var liveState = document.getElementById('livePaymentState');
  var expiredState = document.getElementById('expiredPaymentState');
  var paidPopup = document.getElementById('paidPopup');
  var paidCount = document.getElementById('paidCount');
  var timerEl = document.getElementById('timerDisplay');
  var noteEl = document.getElementById('statusNote');

  // --- Countdown Timer (30:00 -> 1800 seconds) ---
  var totalSeconds = 1800; // 30 minutes
  var timerInterval = setInterval(function () {
    totalSeconds--;
    if (totalSeconds <= 0) {
      clearInterval(timerInterval);
      showExpired('Payment Expired', 'The QR code session has expired. Please place a new order if you have not paid yet.');
      return;
    }
    var mins = Math.floor(totalSeconds / 60);
    var secs = totalSeconds % 60;
    timerEl.textContent = (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs;
  }, 1000);

  // The popup, then tracking by itself. The countdown is on screen rather
  // than silent, because five seconds with nothing moving reads as a page
  // that has stopped.
  function showSuccess() {
    clearInterval(timerInterval);

    if (!paidPopup) { window.location.href = trackUrl; return; }

    paidPopup.hidden = false;

    var left = 5;
    var countdown = setInterval(function () {
      left--;
      if (paidCount) { paidCount.textContent = left > 0 ? left : 0; }
      if (left <= 0) {
        clearInterval(countdown);
        window.location.href = trackUrl;
      }
    }, 1000);
  }

  function showExpired(title, desc) {
    clearInterval(timerInterval);
    if (liveState) { liveState.hidden = true; }
    if (expiredState) {
      document.getElementById('failTitle').textContent = title || 'Payment Expired';
      document.getElementById('failDesc').textContent = desc || 'The payment session has ended.';
      expiredState.hidden = false;
    }
  }

  // --- Taking the code away with you --------------------------------------
  // The QR is an inline SVG, so saving it means drawing it onto a canvas and
  // handing back a PNG - a picture a bank app can scan out of the gallery.
  // An SVG file would be smaller and most gallery apps cannot open one.
  var saveBtn = document.getElementById('saveQrBtn');
  var copyBtn = document.getElementById('copyQrBtn');

  function flash(button, word) {
    var label = button.querySelector('.qr-action-label');
    if (!label) { return; }
    var was = label.textContent;
    label.textContent = word;
    button.classList.add('done');
    setTimeout(function () {
      label.textContent = was;
      button.classList.remove('done');
    }, 1800);
  }

  function download(href, name) {
    var link = document.createElement('a');
    link.href = href;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      var svg = document.querySelector('.qr-box-wrapper svg');
      if (!svg) { return; }

      var markup = new XMLSerializer().serializeToString(svg);
      var source = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup);
      var name   = 'qr-' + orderCode;

      var image = new Image();
      image.onload = function () {
        // Drawn at four times the size: a phone camera reading this off
        // another screen needs the squares to survive being scaled.
        var side   = 920;
        var canvas = document.createElement('canvas');
        canvas.width = side;
        canvas.height = side;

        var pen = canvas.getContext('2d');
        pen.fillStyle = '#ffffff';
        pen.fillRect(0, 0, side, side);
        pen.drawImage(image, 20, 20, side - 40, side - 40);

        try {
          download(canvas.toDataURL('image/png'), name + '.png');
          flash(saveBtn, 'Saved');
        } catch (error) {
          download(source, name + '.svg');
          flash(saveBtn, 'Saved');
        }
      };
      // Canvas refused it, or the browser is old: the file itself still
      // downloads, and a scanner that opens SVG can read it.
      image.onerror = function () {
        download(source, name + '.svg');
        flash(saveBtn, 'Saved');
      };
      image.src = source;
    });
  }

  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var text = copyBtn.getAttribute('data-qr') || '';
      if (text === '') { return; }

      var done = function () { flash(copyBtn, 'Copied'); };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, done);
        return;
      }

      var carrier = document.createElement('textarea');
      carrier.value = text;
      carrier.setAttribute('readonly', '');
      carrier.style.position = 'fixed';
      carrier.style.left = '-9999px';
      document.body.appendChild(carrier);
      carrier.select();
      try { document.execCommand('copy'); } catch (error) { /* nothing else to try */ }
      document.body.removeChild(carrier);
      done();
    });
  }

  // --- Payment Status Polling ---
  // Asking costs a PHP process and an outbound call to the gateway, and the
  // shop runs on shared hosting. A code is usually scanned in the first
  // minute or two, so the asking is quick to begin with and then eases off -
  // half an hour of waiting is about a hundred and twenty asks instead of
  // four hundred and fifty. Anyone who closes the tab is caught by cron.
  var POLL_FIRST = 4000;
  var POLL_MOST  = 15000;
  var pollWait   = POLL_FIRST;

  function pollAgain() {
    setTimeout(checkPayment, pollWait);
    pollWait = Math.min(POLL_MOST, Math.round(pollWait * 1.4));
  }
  var isChecking = false;

  function checkPayment() {
    if (isChecking || totalSeconds <= 0) return;
    isChecking = true;

    var formData = new FormData();
    // The name csrf_field() writes and csrf_verify() reads. Anything else is
    // a 419 the page cannot see: the reply is not JSON, so the poll lands in
    // .catch() and retries for ever while the order stays pending.
    formData.append('_token', csrfToken);

    fetch(checkUrl, {
      method: 'POST',
      body: formData,
      headers: {
        'X-Requested-With': 'fetch',
        'Accept': 'application/json'
      },
      credentials: 'same-origin'
    })
      .then(function (res) { return res.json(); })
      .then(function (out) {
        isChecking = false;
        if (out && out.state === 'paid') {
          showSuccess();
          return;
        }
        if (out && out.state === 'failed') {
          showExpired('Payment Failed', out.message || 'Payment could not be verified.');
          return;
        }
        // Still waiting - poll again
        pollAgain();
      })
      .catch(function () {
        isChecking = false;
        // Network blip, retry
        pollAgain();
      });
  }

  // Start polling after 3 seconds
  setTimeout(checkPayment, 3000);
})();
</script>

</body>
</html>
