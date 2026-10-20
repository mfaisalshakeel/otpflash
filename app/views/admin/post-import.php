<?php
/**
 * Getting posts in by the batch: the prompt to give an AI, and the place to
 * bring back what it wrote.
 *
 * @var string $prompt  blog_prompt_fill() with the options below
 * @var array  $ask     those options
 * @var int    $posts   how many posts the shop has, for the prompt's note
 */
?>
<a class="lnk back" href="<?= e(url('admin/posts')) ?>">&larr; Back to posts</a>

<div class="split2 import-split">
  <div class="box">
    <div class="box-head"><b>1. Get the articles from an AI</b></div>
    <div class="pad">
      <p class="muted import-lead">Set the batch, copy the prompt, and paste it into Claude, ChatGPT, Gemini or any
        AI chat. It already knows this shop: what it sells, the <?= qty_fmt($posts) ?> post<?= $posts === 1 ? '' : 's' ?>
        written so far (so it will not repeat them), the real pages to link to, and the exact CSV to send back.</p>

      <form method="get" action="<?= e(url('admin/posts/import')) ?>" data-prompt-form>
        <div class="field2">
          <div class="field">
            <label for="p-count">How many articles</label>
            <input id="p-count" type="number" name="count" min="1" max="30" value="<?= (int) $ask['count'] ?>">
          </div>
          <div class="field">
            <label for="p-mode">When they go live</label>
            <select id="p-mode" name="mode">
              <option value="schedule"<?= $ask['mode'] === 'schedule' ? ' selected' : '' ?>>Scheduled, one after another</option>
              <option value="live"<?= $ask['mode'] === 'live' ? ' selected' : '' ?>>Live as soon as imported</option>
              <option value="draft"<?= $ask['mode'] === 'draft' ? ' selected' : '' ?>>Drafts, for me to review</option>
            </select>
          </div>
        </div>
        <div class="field2" data-when="schedule">
          <div class="field">
            <label for="p-start">First one goes out</label>
            <input id="p-start" type="datetime-local" name="start" value="<?= e(str_replace(' ', 'T', $ask['start'])) ?>">
          </div>
          <div class="field">
            <label for="p-every">Then one every (hours)</label>
            <input id="p-every" type="number" name="every_hours" min="1" max="720" value="<?= (int) $ask['every_hours'] ?>">
            <small>24 is one a day; 168 one a week.</small>
          </div>
        </div>
        <div class="field2">
          <div class="field">
            <label for="p-audience">Readers</label>
            <input id="p-audience" name="audience" value="<?= e($ask['audience']) ?>">
          </div>
          <div class="field">
            <label for="p-language">Language</label>
            <input id="p-language" name="language" value="<?= e($ask['language']) ?>">
          </div>
        </div>
        <div class="field">
          <label for="p-topics">Topics or keywords you want <span class="muted">(optional)</span></label>
          <textarea id="p-topics" name="topics" rows="3"
                    placeholder="One per line, e.g. receive SMS online for Telegram&#10;Leave empty and the AI picks topics the blog has not covered"><?= e($ask['topics']) ?></textarea>
        </div>
        <?php /* Without the script this is how the prompt is redrawn; with it,
                 the prompt follows the boxes and this button is not needed. */ ?>
        <button class="btn btn-ghost" type="submit" data-prompt-refresh>Update the prompt</button>
      </form>

      <div class="prompt-box">
        <div class="prompt-head">
          <b>The prompt</b>
          <button class="btn btn-primary btn-sm" type="button" data-copy-from="#aiPrompt">
            <svg class="icon"><use href="#i-copy"></use></svg> Copy prompt</button>
        </div>
        <textarea id="aiPrompt" class="prompt-text" rows="14" readonly data-prompt-url="<?= e(url('admin/posts/import/prompt')) ?>"><?= e($prompt) ?></textarea>
        <small class="muted">Long batches: if the AI stops early, reply <b>continue</b> and paste the extra rows under the first ones.</small>
      </div>
    </div>
  </div>

  <div>
    <div class="box">
      <div class="box-head"><b>2. Bring the CSV back</b></div>
      <div class="pad">
        <form method="post" action="<?= e(url('admin/posts/import/check')) ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="field">
            <label for="i-file">CSV file</label>
            <input id="i-file" type="file" name="csv" accept=".csv,.txt,text/csv,text/plain">
          </div>
          <div class="field">
            <label for="i-paste">or paste it here</label>
            <textarea id="i-paste" name="paste" rows="7" placeholder="title,slug,focus_keyword,..."></textarea>
            <small>Paste the AI's whole reply - the ```csv fence around it is ignored.</small>
          </div>
          <div class="field">
            <label for="i-mode">Publishing</label>
            <select id="i-mode" name="mode">
              <option value="as_file">As each row says (status and publish_at)</option>
              <option value="schedule">Schedule them all, one after another</option>
              <option value="live">Publish them all now</option>
              <option value="draft">Save them all as drafts</option>
            </select>
          </div>
          <div class="field2" data-when-import="schedule">
            <div class="field">
              <label for="i-start">First one goes out</label>
              <input id="i-start" type="datetime-local" name="start" value="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>T10:00">
            </div>
            <div class="field">
              <label for="i-every">Then one every (hours)</label>
              <input id="i-every" type="number" name="every_hours" min="1" max="720" value="24">
            </div>
          </div>
          <label class="switch block">
            <input type="checkbox" name="update" value="1">
            <span class="track"></span> Replace posts with the same slug
          </label>
          <button class="btn btn-primary btn-block" type="submit" style="margin-top:14px">Check the file</button>
          <small class="muted import-note">Nothing is saved yet: the next screen shows every row - new, duplicate, broken
            link - and saves only when you say so.</small>
        </form>
      </div>
    </div>

    <details class="box columns-box">
      <summary class="box-head"><b>The columns</b></summary>
      <div class="pad">
        <dl class="columns-list">
          <?php foreach (BLOG_CSV_COLUMNS as $name => $what): ?>
            <div><dt class="mono"><?= e($name) ?><?= in_array($name, ['title', 'content'], true) ? ' *' : '' ?></dt>
              <dd><?= e(str_replace('{site}', (string) setting('site_name', 'OTP Flash'), $what)) ?></dd></div>
          <?php endforeach; ?>
        </dl>
        <p class="muted">* required. The first line names the columns; any order works, and unknown columns are ignored.</p>
      </div>
    </details>
  </div>
</div>
