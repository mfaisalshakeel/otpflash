<?php
/**
 * Generic listing driven by $spec['columns'].
 * @var array $spec  @var array $rows  @var string $search
 * @var array $paging  @var array $carry
 */
$columns = $spec['columns'];
$base    = $spec['base'];
?>
<div class="filters">
  <?php if (!empty($spec['search'])): ?>
    <form class="search wide" id="crudSearch" method="get" action="<?= e(url($base)) ?>">
      <svg class="icon"><use href="#i-search"></use></svg>
      <input name="q" value="<?= e($search) ?>" placeholder="<?= e($spec['search_hint'] ?? 'Search') ?>">
      <?php foreach ($spec['filters'] ?? [] as $key => $filter): ?>
        <input type="hidden" name="<?= e($key) ?>" value="<?= e($_GET[$key] ?? '') ?>">
      <?php endforeach; ?>
    </form>
    <?php /* Outside the pill it sits in, through `form`, because the pill is
             the search box's own shape and a button inside it is a button in
             the middle of the box. Without it the only way to search was a
             key nobody is told about. */ ?>
    <button class="btn btn-ghost" type="submit" form="crudSearch">Search</button>
  <?php endif; ?>

  <?php foreach ($spec['filters'] ?? [] as $key => $filter): ?>
    <form method="get" action="<?= e(url($base)) ?>" style="display:contents">
      <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
      <select name="<?= e($key) ?>" onchange="this.form.submit()">
        <option value=""><?= e($filter['all'] ?? 'All') ?></option>
        <?php foreach ($filter['options'] as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= (string) ($_GET[$key] ?? '') === (string) $value ? ' selected' : '' ?>>
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endforeach; ?>

  <?php if ($search !== '' || array_filter($_GET ?? [], 'strlen')): ?>
    <a class="btn btn-ghost" href="<?= e(url($base)) ?>">Reset</a>
  <?php endif; ?>

  <?php foreach ($spec['actions'] ?? [] as $action): ?>
    <?php if (array_key_exists('when', $action) && !$action['when']): continue; endif; ?>
    <?php if (!empty($action['post'])): ?>
      <form method="post" action="<?= e(url($action['href'])) ?>" style="margin-left:auto">
        <?= csrf_field() ?>
        <?php foreach ($action['fields'] ?? [] as $fieldName => $fieldValue): ?>
          <input type="hidden" name="<?= e($fieldName) ?>" value="<?= e($fieldValue) ?>">
        <?php endforeach; ?>
        <button class="btn btn-ghost" type="submit"><?= e($action['label']) ?></button>
      </form>
    <?php else: ?>
      <a class="btn btn-ghost" href="<?= e(url($action['href'])) ?>" style="margin-left:auto">
        <?= e($action['label']) ?></a>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php if (empty($spec['no_create'])): ?>
    <a class="btn btn-primary" href="<?= e(url($base . '/new')) ?>"
       <?= empty($spec['actions']) ? 'style="margin-left:auto"' : '' ?>>
      + Add <?= e(strtolower($spec['single'] ?? 'record')) ?>
    </a>
  <?php endif; ?>
</div>

<div class="box">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <?php if (!empty($spec['sortable']) && $search === ''): ?><th class="dragcell"></th><?php endif; ?>
          <?php foreach ($columns as $column): ?>
            <th class="<?= e($column['class'] ?? '') ?>"><?= e($column['label']) ?></th>
          <?php endforeach; ?>
          <th></th>
        </tr>
      </thead>
      <?php /* Sorting is offered where the table has a sort_order and the
               screen is ordered by it - dragging a list that is sorted by
               name would move rows that spring straight back. */ ?>
      <?php $sortable = !empty($spec['sortable']) && $search === ''; ?>
      <tbody<?= $sortable ? ' data-sortable="' . e($spec['table']) . '"'
          . ' data-url="' . e(url('admin/reorder')) . '"'
          . ' data-token="' . e(csrf_token()) . '"'
          . ' data-start="' . (int) (($paging['page'] - 1) * $paging['per']) . '"' : '' ?>>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= count($columns) + ($sortable ? 2 : 1) ?>" class="empty">
          <?= e($spec['empty'] ?? 'Nothing here yet.') ?>
        </td></tr>
      <?php else: foreach ($rows as $row): ?>
        <tr<?= $sortable ? ' data-id="' . (int) $row['id'] . '"' : '' ?>>
          <?php if ($sortable): ?>
            <td class="dragcell">
              <span class="rowdrag" data-drag title="Drag to reorder" aria-label="Drag to reorder">
                <svg class="icon"><use href="#i-list"></use></svg>
              </span>
            </td>
          <?php endif; ?>
          <?php foreach ($columns as $column): ?>
            <td class="<?= e($column['class'] ?? '') ?>" data-label="<?= e($column['label']) ?>">
              <?php
              if (isset($column['render']) && is_callable($column['render'])) {
                  echo $column['render']($row);   // renderers emit their own escaped markup
              } else {
                  echo e($row[$column['key']] ?? '');
              }
              ?>
            </td>
          <?php endforeach; ?>
          <td class="ta-r">
            <div class="rowacts">
              <?php
              // A screen can add its own buttons per row; they emit escaped markup.
              if (isset($spec['row_actions']) && is_callable($spec['row_actions'])) {
                  echo $spec['row_actions']($row);
              }
              ?>
              <?php if (!empty($spec['toggle'])): ?>
                <?php $on = (bool) $row[$spec['toggle']]; ?>
                <form method="post" action="<?= e(url($base . '/toggle')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                  <button class="iact <?= $on ? 'iact-on' : 'iact-off' ?>" type="submit"
                          title="<?= $on ? 'Switch off' : 'Switch on' ?>"
                          aria-label="<?= $on ? 'Switch off' : 'Switch on' ?>">
                    <svg class="icon"><use href="#i-power"></use></svg>
                  </button>
                </form>
              <?php endif; ?>
              <a class="iact iact-edit" href="<?= e(url($base . '/edit/' . $row['id'])) ?>"
                 title="Edit" aria-label="Edit">
                <svg class="icon"><use href="#i-edit"></use></svg>
              </a>
              <?php if (empty($spec['no_delete'])): ?>
                <form method="post" action="<?= e(url($base . '/delete')) ?>"
                      data-confirm="Delete this <?= e(strtolower($spec['single'] ?? 'record')) ?>? This cannot be undone.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                  <button class="iact iact-danger" type="submit" title="Delete" aria-label="Delete">
                    <svg class="icon"><use href="#i-trash"></use></svg>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($rows): ?>
    <?php partial('admin/_pager', [
      'paging' => $paging,
      'base'   => $base,
      'query'  => $carry,
      'noun'   => strtolower((string) ($spec['single'] ?? 'record')),
      'shown'  => count($rows),
    ]); ?>
  <?php endif; ?>
</div>
