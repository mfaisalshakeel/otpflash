<?php
/**
 * The picker: choose a service, a country, an option, and buy.
 *
 * Drawn complete on the server - every list item is a real link to its own
 * landing page, and the options are real forms - so it works, and is
 * crawled, with no script at all. app.js then takes the clicks over: it
 * filters as you type, fetches only the list that changed, and keeps the
 * address bar on the landing page of whatever is chosen.
 *
 * The data-* names are what app.js reads. A theme that redraws this keeps
 * them: [data-picker], [data-pane], [data-list], [data-filter], [data-item],
 * [data-options], [data-step].
 *
 * @var array  $tabs   store_tabs()
 * @var array  $state  picker_state()
 * @var string $query  a search to start with
 * @var ?array $user
 */
$tab       = $state['tab'];
$panes     = $state['panes'];
$service   = $state['service'];
$country   = $state['country'];
$guestOk   = setting('guest_checkout', '1') === '1';

// Which step a phone shows first: the furthest one already chosen.
$step = $panes[0];
if ($tab === 'numbers') {
    $step = $service && $country ? 'options' : ($service ? 'country' : 'service');
} elseif ($state['options']) {
    $step = 'options';
}

// Questions a customer would ask themselves, not the shop's words for things:
// nobody arrives thinking "service" or "server".
$paneTitle = [
    'service' => 'Which app?',
    'country' => 'Which country?',
    'options' => $tab === 'rental' ? 'How long?' : ($tab === 'email' ? 'Get your inbox' : 'Buy your number'),
];
?>
<div class="buybox" data-picker data-tab="<?= e($tab) ?>" data-step="<?= e($step) ?>"
     data-service="<?= (int) ($service['id'] ?? 0) ?>" data-country="<?= (int) ($country['id'] ?? 0) ?>"
     data-flags="<?= e(url('assets/flags')) ?>" data-brands="<?= e(asset('assets/brands/brands.svg')) ?>">

  <?php if (count($tabs) > 1): ?>
    <nav class="buy-tabs" aria-label="What to buy">
      <?php foreach ($tabs as $key => $info): ?>
        <a href="<?= e(tab_url($key)) ?>" class="buy-tab<?= $key === $tab ? ' on' : '' ?>"
           <?= $key === $tab ? 'aria-current="page"' : '' ?> data-tab-link="<?= e($key) ?>">
          <svg class="icon"><use href="#<?= e($info['icon']) ?>"></use></svg>
          <span><?= e($info['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <div class="buy-grid cols-<?= count($panes) ?>">
    <?php foreach ($panes as $i => $pane): ?>
      <section class="pane pane-<?= e($pane) ?>" data-pane="<?= e($pane) ?>" aria-labelledby="pane-<?= e($pane) ?>-h">
        <div class="pane-head">
          <?php if ($i > 0): ?>
            <button class="pane-back" type="button" data-back="<?= e($panes[$i - 1]) ?>" aria-label="Back">
              <svg class="icon"><use href="#i-back"></use></svg>
            </button>
          <?php endif; ?>
          <span class="step-n"><?= $i + 1 ?></span>
          <h2 id="pane-<?= e($pane) ?>-h"><?= e($paneTitle[$pane]) ?></h2>
        </div>

        <?php if ($pane === 'service' || $pane === 'country'): ?>
          <?php $rows = $pane === 'service' ? $state['services'] : $state['countries']; ?>
          <?php $chosen = $pane === 'service' ? $service : $country; ?>
          <?php /* Popular first, which is what most people want, with the two
                   other orders a shopper reaches for: cheapest, and by name.
                   Script-only - without it the list is still in popular order. */ ?>
          <div class="pane-tools">
            <label class="psearch">
              <svg class="icon"><use href="#i-search"></use></svg>
              <input type="search" data-filter="<?= e($pane) ?>" autocomplete="off" spellcheck="false"
                     value="<?= $pane === 'service' && $tab !== 'rental' ? e($query) : '' ?>"
                     placeholder="<?= $pane === 'service' ? 'Type an app, e.g. WhatsApp' : 'Type a country or +92' ?>"
                     aria-label="<?= $pane === 'service' ? 'Search apps' : 'Search countries' ?>">
            </label>
            <?php if (count($rows) > 1): ?>
              <div class="psort" role="group" aria-label="<?= $pane === 'service' ? 'Sort apps' : 'Sort countries' ?>" data-sorts="<?= e($pane) ?>">
                <button type="button" class="on" data-sort="popular" aria-pressed="true">Popular</button>
                <button type="button" data-sort="price" aria-pressed="false">Cheapest</button>
                <button type="button" data-sort="name" aria-pressed="false">A&ndash;Z</button>
              </div>
            <?php endif; ?>
          </div>

          <ul class="plist" data-list="<?= e($pane) ?>"
              <?= $pane === 'service' ? 'data-total="' . (int) $state['services_total'] . '"' : '' ?>>
            <?php if (!$rows): ?>
              <li class="pempty"><?= $tab === 'numbers' && $pane === 'country' && !$service
                  ? 'No countries on sale yet.' : 'Nothing on sale here right now.' ?></li>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
              <?php
              $href = $pane === 'service'
                  ? choice_url($tab, $row, $tab === 'numbers' ? $country : null)
                  : choice_url($tab, $tab === 'numbers' ? $service : null, $row);
              ?>
              <li>
                <a class="pitem<?= $chosen && $chosen['id'] === $row['id'] ? ' on' : '' ?>" href="<?= e($href) ?>"
                   data-item="<?= e($pane) ?>" data-id="<?= (int) $row['id'] ?>" data-slug="<?= e($row['slug']) ?>"
                   data-name="<?= e($row['name']) ?>" data-keys="<?= e($row['keys']) ?>"
                   data-price="<?= e((string) $row['from']) ?>" data-pop="<?= (int) $row['pop'] ?>"
                   <?php if ($pane === 'service'): ?>data-icon="<?= e($row['icon']) ?>" data-color="<?= e($row['color']) ?>"<?php else: ?>data-iso="<?= e($row['iso']) ?>" data-dial="<?= e($row['dial']) ?>"<?php endif; ?>>
                  <?php if ($pane === 'service'): ?>
                    <?= service_icon_html($row) ?>
                    <span class="pname"><?= e($row['name']) ?>
                      <small><?= e($row['sub']) ?></small></span>
                  <?php else: ?>
                    <img class="flag" src="<?= e(flag_url($row['iso'])) ?>" alt="" width="28" height="21" loading="lazy" decoding="async">
                    <span class="pname"><?= e($row['name']) ?>
                      <?php if ($row['dial'] !== ''): ?><small>+<?= e($row['dial']) ?></small><?php endif; ?></span>
                  <?php endif; ?>
                  <span class="pprice"><small>from</small><?= price($row['from']) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
            <?php if ($pane === 'service' && $query === '' && $state['services_total'] > count($rows)): ?>
              <li class="pmore" data-more><?= qty_fmt($state['services_total'] - count($rows)) ?> more apps -
                type a name above to find any of them.</li>
            <?php endif; ?>
          </ul>

        <?php else: ?>
          <div class="pane-options" data-options aria-live="polite">
            <?php partial('partials/options', ['state' => $state, 'user' => $user,
                'guestOk' => $guestOk]); ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>
