<?php
/**
 * @var array $tasks  @var array $recent  @var array $runsPaging  @var string $cronUrl  @var string $cronCommand
 * @var bool  $orgConfigured  @var ?array $orgJob  @var array $orgHistory
 */
$neverRan = true;
foreach ($tasks as $task) {
    if ($task['last']) { $neverRan = false; break; }
}
?>
<?php if ($neverRan): ?>
  <div class="alert alert-warning">
    Nothing has run yet. Add one of the schedules below, or press <b>Run all now</b> to try it by hand.
  </div>
<?php endif; ?>

<div class="split2">
  <div>
    <div class="box">
      <div class="box-head">
        <b>Tasks</b>
        <form method="post" action="<?= e(url('admin/cron/run')) ?>" style="margin-left:auto">
          <?= csrf_field() ?>
          <input type="hidden" name="task" value="all">
          <button class="btn btn-primary btn-sm" type="submit">Run all now</button>
        </form>
      </div>
      <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr><th>Task</th><th>Runs</th><th>Last run</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($tasks as $task): ?>
            <tr>
              <td style="cursor:default" data-label="Task">
                <b><?= e($task['label']) ?></b>
                <?php if ($task['disabled']): ?>
                  <span class="st st-cancelled">off</span>
                <?php endif; ?>
                <small class="sub"><?= e($task['detail']) ?></small>
              </td>
              <td style="cursor:default" data-label="Runs"><?= e($task['every']) ?></td>
              <td style="cursor:default" data-label="Last run">
                <?php if (!$task['last']): ?>
                  <span class="muted">never</span>
                <?php else: ?>
                  <span class="st st-<?= $task['last']['ok'] ? 'completed' : 'api_error' ?>">
                    <?= $task['last']['ok'] ? 'ok' : 'failed' ?>
                  </span>
                  <small class="sub">
                    <?= e(when($task['last']['created_at'], 'd M, H:i')) ?>
                    &bull; <?= e($task['last']['summary']) ?>
                  </small>
                <?php endif; ?>
              </td>
              <td class="ta-r">
                <form method="post" action="<?= e(url('admin/cron/run')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="task" value="<?= e($task['key']) ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">Run now</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Recent runs</b>
        <?php partial('admin/_clear-log', ['kind' => 'cron', 'back' => 'admin/cron']); ?></div>
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr><th>When</th><th>Task</th><th>From</th><th>Result</th><th>Took</th></tr></thead>
          <tbody>
          <?php if (!$recent): ?>
            <tr><td colspan="5" class="empty">No runs recorded yet.</td></tr>
          <?php else: foreach ($recent as $run): ?>
            <tr style="cursor:default">
              <td data-label="When"><?= e(when($run['created_at'], 'd M, H:i:s')) ?></td>
              <td data-label="Task"><?= e(cron_tasks()[$run['task']]['label'] ?? $run['task']) ?></td>
              <td data-label="From"><span class="chipx"><?= e($run['source']) ?></span></td>
              <td data-label="Result">
                <span class="st st-<?= $run['ok'] ? 'completed' : 'api_error' ?>">
                  <?= $run['ok'] ? 'ok' : 'failed' ?></span>
                <small class="sub"><?= e($run['summary']) ?></small>
              </td>
              <td class="mono" data-label="Took"><?= qty_fmt($run['duration_ms']) ?> ms</td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php partial('admin/_pager', ['paging' => $runsPaging, 'base' => 'admin/cron', 'noun' => 'run',
          'shown' => count($recent)]); ?>
    </div>
  </div>

  <div>
    <div class="box">
      <div class="box-head"><b>Schedule it</b></div>
      <div class="pad">
        <p class="muted" style="margin-bottom:14px">
          Point any one of these at the panel every 5 minutes. Tasks that do not need to run
          that often keep their own interval, so running every 5 minutes is safe.
        </p>

        <div class="field">
          <label>cPanel cron job</label>
          <div class="copyrow">
            <input value="<?= e($cronCommand) ?>" readonly>
            <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($cronCommand) ?>">Copy</button>
          </div>
          <small>Set the interval to <span class="mono">*/5 * * * *</span></small>
        </div>

        <div class="field">
          <label>URL (for hosts with no CLI cron)</label>
          <div class="copyrow">
            <input value="<?= e($cronUrl) ?>" readonly>
            <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($cronUrl) ?>">Copy</button>
          </div>
          <small>Anything else under /cron returns 404, so the key is the only way in.</small>
        </div>

        <div class="field">
          <label>One task only</label>
          <div class="copyrow">
            <input value="<?= e($cronCommand) ?> sync_services" readonly>
            <button class="btn btn-ghost btn-sm" type="button"
                    data-copy="<?= e($cronCommand) ?> sync_services">Copy</button>
          </div>
          <small>Add a task name to either form:
            <span class="mono"><?= e(implode(', ', array_keys($tasks))) ?></span></small>
        </div>
      </div>
    </div>

    <div class="box">
      <div class="box-head">
        <b>cron-job.org</b>
        <?php if ($orgJob): ?>
          <span class="st st-completed">connected</span>
        <?php elseif ($orgConfigured): ?>
          <span class="st st-pending">no job yet</span>
        <?php endif; ?>
      </div>
      <div class="pad">
        <p class="muted" style="margin-bottom:14px">
          Free external scheduler. Useful when your host has no cron at all &mdash; it calls the
          URL above for you. Create an API key under Settings &rarr; API in your cron-job.org
          account and paste it here.
        </p>

        <form method="post" action="<?= e(url('admin/cron/cronjoborg')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="save_key">
          <div class="field">
            <label for="org-key">API key</label>
            <input id="org-key" type="password" name="api_key"
                   value="<?= e(setting('cronjob_org_key', '')) ?>" autocomplete="off">
          </div>
          <button class="btn btn-ghost btn-block" type="submit">Save key</button>
        </form>

        <?php if ($orgConfigured): ?>
          <div class="stack" style="margin-top:14px">
            <form method="post" action="<?= e(url('admin/cron/cronjoborg')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="create">
              <button class="btn btn-primary btn-block" type="submit">
                <?= $orgJob ? 'Update the job there' : 'Create the job there' ?>
              </button>
            </form>
            <?php if ($orgJob): ?>
              <form method="post" action="<?= e(url('admin/cron/cronjoborg')) ?>"
                    data-confirm="Remove the job from cron-job.org?">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="delete">
                <button class="btn btn-danger btn-block" type="submit">Remove it</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($orgJob): ?>
          <div style="margin-top:16px">
            <div class="drow"><span>Status</span>
              <b><?= !empty($orgJob['enabled']) ? 'enabled' : 'paused' ?></b></div>
            <?php if (!empty($orgJob['lastExecution'])): ?>
              <div class="drow"><span>Last execution</span>
                <b><?= e(when(date('Y-m-d H:i:s', (int) $orgJob['lastExecution']))) ?></b></div>
            <?php endif; ?>
            <?php if (isset($orgJob['lastStatus'])): ?>
              <div class="drow"><span>Last status</span>
                <b><?= (int) $orgJob['lastStatus'] === 1 ? 'ok' : 'code ' . (int) $orgJob['lastStatus'] ?></b></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($orgHistory): ?>
          <div style="margin-top:14px">
            <label style="font-size:12.5px;font-weight:700;color:var(--ink-2)">Their last runs</label>
            <ul class="log" style="margin-top:8px">
              <?php foreach ($orgHistory as $run): ?>
                <li>
                  <b><?= e(when(date('Y-m-d H:i:s', (int) ($run['date'] ?? time())), 'd M, H:i')) ?></b>
                  HTTP <?= (int) ($run['httpStatus'] ?? 0) ?>
                  <?= isset($run['duration']) ? ' &bull; ' . (int) $run['duration'] . ' ms' : '' ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
