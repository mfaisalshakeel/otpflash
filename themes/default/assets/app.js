/* ===========================================================================
   OTP Flash - front-site script.

   Everything here is an improvement on a page that already works: every
   list item is a link, every option is a form, and every action posts
   normally when this file never loads. The script takes the clicks over,
   fetches only what changed, and keeps the number page live.
   =========================================================================== */
(function () {
  'use strict';

  var CFG = window.OTPF || { base: '', token: '', currency: 'Rs ' };
  document.documentElement.classList.remove('no-js');
  document.documentElement.classList.add('js');

  // ------------------------------------------------------------- helpers --
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  // Matches money() on the server: a symbol ending in a letter needs air
  // before the figure, a glyph like $ does not.
  function money(amount) {
    var symbol = CFG.currency || '';
    var text = Number(amount).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    var gap = /[A-Za-z]$/.test(symbol) && !/\s$/.test(symbol) ? ' ' : '';
    return symbol + gap + text;
  }

  function esc(text) {
    return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var toastTimer = null;
  function toast(message) {
    var box = $('#toast');
    if (!box || !message) { return; }
    box.textContent = message;
    box.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { box.hidden = true; }, 3200);
  }

  function post(url, data) {
    var body = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) {
      Object.keys(data || {}).forEach(function (key) { body.append(key, data[key]); });
    }
    if (!body.has('_token')) { body.append('_token', CFG.token); }
    return fetch(url, {
      method: 'POST', body: body, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (response) { return response.json(); });
  }

  function busy(button, on) {
    if (!button) { return; }
    if (on) {
      button.dataset.was = button.innerHTML;
      button.disabled = true;
      button.classList.add('btn-wait');
      if (button.dataset.wait) { button.textContent = button.dataset.wait; }
    } else {
      button.disabled = false;
      button.classList.remove('btn-wait');
      if (button.dataset.was) { button.innerHTML = button.dataset.was; }
    }
  }

  // ------------------------------------------------------------- dialogs --
  var lastFocus = null;
  function openModal(modal) {
    if (!modal) { return; }
    lastFocus = document.activeElement;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    var panel = $('.modal', modal);
    // A text field focused as the dialog opens brings a phone's keyboard up
    // over what the dialog is showing, so the panel takes the focus instead.
    if (panel) { panel.focus(); }
  }
  function closeModal(modal) {
    if (!modal || modal.hidden) { return; }
    modal.hidden = true;
    if (!$('.modal-back:not([hidden])')) { document.body.style.overflow = ''; }
    if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
  }
  document.addEventListener('click', function (event) {
    var back = event.target.closest('.modal-back');
    if (back && (event.target === back || event.target.closest('[data-close]'))) {
      closeModal(back);
    }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { $$('.modal-back').forEach(closeModal); }
  });

  // ------------------------------------------- the account menu --------
  // The chip is a link to the dashboard; with the script it opens the menu
  // instead. Any click outside it, or Escape, puts it away.
  function acctMenu(open, box) {
    $$('[data-acct]').forEach(function (other) {
      var on = open && other === box;
      other.classList.toggle('open', on);
      $('[data-acct-menu]', other).hidden = !on;
      $('[data-acct-toggle]', other).setAttribute('aria-expanded', on ? 'true' : 'false');
    });
  }
  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-acct-toggle]');
    if (toggle) {
      event.preventDefault();
      var box = toggle.closest('[data-acct]');
      acctMenu(!box.classList.contains('open'), box);
      return;
    }
    if (!event.target.closest('[data-acct-menu]')) { acctMenu(false); }
  });

  // The portal's sidebar, slid in from the menu button on a narrow screen.
  function sideMenu(open) {
    document.body.classList.toggle('side-open', open);
    var button = $('[data-side-open]');
    if (button) { button.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  }
  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-side-open]')) { sideMenu(true); }
    else if (event.target.closest('[data-side-close]')) { sideMenu(false); }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { acctMenu(false); sideMenu(false); }
  });

  // ------------------------------------------- our own confirm and alert --
  // The browser's confirm() is a grey box that names the website and looks
  // like a warning from the phone itself; this is ours, in the shop's
  // colours, and says the same thing. Resolves true for the main button.
  //   ask({ title, message, ok, cancel, tone: 'danger' | 'info', alert: true })
  function ask(options) {
    var back = $('#askModal');
    if (!back) {
      back = document.createElement('div');
      back.className = 'modal-back';
      back.id = 'askModal';
      back.hidden = true;
      back.innerHTML = '<div class="modal modal-sm ask" role="alertdialog" aria-modal="true" aria-labelledby="askTitle" tabindex="-1">' +
        '<span class="ask-ico" aria-hidden="true"></span><h2 class="modal-title" id="askTitle"></h2><p class="ask-text"></p>' +
        '<div class="ask-acts"><button class="btn btn-ghost" type="button" data-ask="no"></button>' +
        '<button class="btn btn-primary" type="button" data-ask="yes"></button></div></div>';
      document.body.appendChild(back);
    }
    var tone = options.tone || (options.alert ? 'info' : 'danger');
    $('.ask', back).className = 'modal modal-sm ask ask-' + tone;
    $('#askTitle', back).textContent = options.title || (options.alert ? 'Heads up' : 'Are you sure?');
    $('.ask-text', back).textContent = options.message || '';
    var yes = $('[data-ask="yes"]', back), no = $('[data-ask="no"]', back);
    yes.textContent = options.ok || (options.alert ? 'OK' : 'Yes, go ahead');
    yes.className = 'btn ' + (tone === 'danger' && !options.alert ? 'btn-danger' : 'btn-primary');
    no.textContent = options.cancel || 'Go back';
    no.hidden = !!options.alert;
    return new Promise(function (resolve) {
      function finish(answer) {
        back.removeEventListener('click', onClick);
        document.removeEventListener('keydown', onKey);
        closeModal(back);
        resolve(answer);
      }
      function onClick(event) {
        var button = event.target.closest('[data-ask]');
        if (button) { finish(button.dataset.ask === 'yes'); return; }
        if (event.target === back) { finish(false); }
      }
      function onKey(event) { if (event.key === 'Escape') { finish(false); } }
      back.addEventListener('click', onClick);
      document.addEventListener('keydown', onKey);
      openModal(back);
      setTimeout(function () { yes.focus(); }, 30);
    });
  }
  // A form's own words for its confirmation: data-confirm, and optionally
  // data-confirm-title / data-confirm-ok / data-confirm-tone.
  function askForm(form) {
    return ask({ title: form.dataset.confirmTitle, message: form.dataset.confirm,
                 ok: form.dataset.confirmOk, tone: form.dataset.confirmTone });
  }

  // ---------------------------------------------------------- the drawer --
  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-drawer]');
    if (!toggle) { return; }
    var drawer = $('#drawer');
    if (!drawer) { return; }
    var open = drawer.classList.toggle('show');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  // ------------------------------------------------------------- copying --
  // The clipboard API exists only on HTTPS pages, so a shop served over
  // plain http (or an old phone) falls back to selecting the text and asking
  // the browser to copy it - and when even that is refused, the text is put
  // on screen, selected, to long-press. Saying "Copied" without copying is
  // the one thing this must never do.
  function copyText(text, button) {
    function done() {
      if (!button) { toast('Copied'); return; }
      var was = button.innerHTML;
      button.classList.add('is-copied');
      button.textContent = 'Copied';
      setTimeout(function () { button.innerHTML = was; button.classList.remove('is-copied'); }, 1500);
    }
    function fallback() {
      if (legacyCopy(text)) { done(); } else { showToCopy(text); }
    }
    if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, fallback);
      return;
    }
    fallback();
  }
  function legacyCopy(text) {
    var carrier = document.createElement('textarea');
    carrier.value = text;
    // iOS selects nothing in a read-only field and scrolls to one placed far
    // off screen, so it is editable, tiny, transparent and where we are.
    carrier.setAttribute('aria-hidden', 'true');
    carrier.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;padding:0;border:0;opacity:0;font-size:16px';
    document.body.appendChild(carrier);
    carrier.focus();
    carrier.select();
    carrier.setSelectionRange(0, text.length);
    var copied = false;
    try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
    document.body.removeChild(carrier);
    return copied;
  }
  function showToCopy(text) {
    var box = $('#copyBox');
    if (!box) {
      box = document.createElement('div');
      box.id = 'copyBox';
      box.className = 'copy-box';
      box.setAttribute('role', 'dialog');
      box.innerHTML = '<span>Press and hold to copy</span><input readonly><button type="button" aria-label="Close">&times;</button>';
      document.body.appendChild(box);
      $('button', box).addEventListener('click', function () { box.hidden = true; });
    }
    var field = $('input', box);
    field.value = text;
    box.hidden = false;
    field.focus();
    field.setSelectionRange(0, text.length);
  }
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy]');
    if (!button) { return; }
    var text = button.getAttribute('data-copy');
    if (text) { copyText(text, button); }
  });

  // ============================================================ loading ==
  // A thin bar along the top for any navigation the script does not take
  // over, so a tap is answered at once even on a slow phone network.
  var bar = null;
  var barTimer = 0;
  function barStart() {
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'navbar-load';
      bar.setAttribute('aria-hidden', 'true');
      document.body.appendChild(bar);
    }
    clearTimeout(barTimer);
    bar.classList.remove('is-done');
    bar.style.width = '0';
    void bar.offsetWidth;
    bar.classList.add('is-on');
    bar.style.width = '82%';
  }
  function barDone() {
    if (!bar || !bar.classList.contains('is-on')) { return; }
    bar.style.width = '100%';
    bar.classList.add('is-done');
    barTimer = setTimeout(function () { bar.classList.remove('is-on', 'is-done'); bar.style.width = '0'; }, 380);
  }
  // Coming back with Back restores the page from memory, bar and all.
  window.addEventListener('pageshow', function () {
    barDone();
    $$('.btn-wait').forEach(function (button) { busy(button, false); });
  });

  // Registered last (see the end of this file), so every other handler has
  // had its say: a click or a submit the script took over is not a page load.
  function watchNavigation() {
  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
    var link = event.target.closest('a[href]');
    if (!link || link.target || link.hasAttribute('download') || link.origin !== window.location.origin) { return; }
    var href = link.getAttribute('href');
    if (!href || href.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(href)) { return; }
    if (link.pathname === window.location.pathname && link.search === window.location.search && link.hash) { return; }
    barStart();
  });
  document.addEventListener('submit', function (event) {
    if (event.defaultPrevented) { return; }
    var form = event.target;
    if (form.target) { return; }
    var button = event.submitter || $('button[type="submit"], button:not([type])', form);
    barStart();
    // After this tick: a button disabled now would be left out of the post.
    if (button && !button.disabled) { setTimeout(function () { busy(button, true); }, 0); }
  });
  }

  // ------------------------------------------------------- skeletons --
  function skRows(count) {
    var html = '';
    for (var i = 0; i < count; i++) {
      html += '<li class="sk-item" aria-hidden="true"><span class="sk sk-ico"></span>' +
        '<span class="sk-lines"><span class="sk sk-t" style="width:' + (48 + (i * 17) % 34) + '%"></span>' +
        '<span class="sk sk-s"></span></span><span class="sk sk-p"></span></li>';
    }
    return html;
  }
  function skOptions() {
    return '<div class="opt-sk" aria-hidden="true">' +
      '<div class="sk-sum"><span class="sk sk-ico-lg"></span><span class="sk-lines">' +
      '<span class="sk sk-t" style="width:58%"></span><span class="sk sk-s"></span></span></div>' +
      '<span class="sk sk-opt"></span><span class="sk sk-more"></span>' +
      '<span class="sk sk-btn"></span><span class="sk sk-s sk-center"></span></div>';
  }
  function skPanes(count) {
    var html = '';
    for (var i = 0; i < count; i++) {
      var last = i === count - 1;
      html += '<section class="pane" aria-hidden="true"><div class="pane-head"><span class="sk sk-step"></span>' +
        '<span class="sk sk-h"></span></div>' +
        (last ? '<div class="pane-options">' + skOptions() + '</div>'
              : '<div class="pane-tools"><span class="sk sk-search"></span></div><ul class="plist">' + skRows(7) + '</ul>') +
        '</section>';
    }
    return html;
  }

  // ------------------------------------------------- swapping the page --
  // A page the script can draw without leaving: fetch it, put its <main> in
  // place of ours, and start whatever lives in it. Anything unexpected falls
  // back to an ordinary page load, which is what the link would have done.
  var cleanups = [];
  var swapping = 0;
  function swapPage(url, push) {
    var mine = ++swapping;
    barStart();
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html', 'X-Requested-With': 'fetch' } })
      .then(function (response) {
        if (!response.ok || response.redirected && response.url.split('#')[0] !== url.split('#')[0] && push) {
          throw new Error('not here');
        }
        return response.text();
      })
      .then(function (html) {
        if (mine !== swapping) { return; }
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.getElementById('main');
        var main = document.getElementById('main');
        if (!fresh || !main) { throw new Error('no main'); }
        // A page drawn in the other layout cannot be swapped into this one.
        if (doc.body && doc.body.dataset.layout !== document.body.dataset.layout) { throw new Error('other layout'); }
        cleanups.splice(0).forEach(function (stop) { stop(); });
        main.innerHTML = fresh.innerHTML;
        document.title = doc.title;
        if (doc.body) { document.body.className = doc.body.className; }
        ['meta[name="description"]', 'link[rel="canonical"]', 'meta[name="robots"]'].forEach(function (sel) {
          var mineTag = $(sel, document.head), theirs = $(sel, doc.head);
          if (mineTag && theirs) { mineTag.replaceWith(theirs.cloneNode(true)); }
        });
        if (push && window.history && history.pushState) { history.pushState({ soft: true }, '', url); }
        boot(main);
        barDone();
      })
      .catch(function () {
        if (mine !== swapping) { return; }
        window.location.href = url;
      });
  }
  if (window.history && history.replaceState) {
    history.replaceState(Object.assign({}, history.state || {}, { soft: true }), '');
  }
  window.addEventListener('popstate', function (event) {
    if (event.state && (event.state.soft || event.state.picker)) { swapPage(window.location.href, false); }
  });

  // ================================================================ picker ==
  function boot(root) {
    var box = $('[data-picker]', root);
    if (box) { initPicker(box); }
    var liveRoot = $('[data-live]', root);
    if (liveRoot) { initLive(liveRoot); }
    bindStateActions(root);
    holdClocks();
    var inboxRoot = $('[data-inbox]', root);
    if (inboxRoot) { initInbox(inboxRoot); }
    syncPaymentFields();
  }

  function initPicker(box) {
    box.classList.add('is-live');
    var tab = box.dataset.tab;
    var chosen = { service: parseInt(box.dataset.service, 10) || 0, country: parseInt(box.dataset.country, 10) || 0 };
    var flags = box.dataset.flags;
    var brands = box.dataset.brands;
    var request = 0;
    var sortBy = { service: 'popular', country: 'popular' };

    function list(pane) { return $('[data-list="' + pane + '"]', box); }
    function input(pane) { return $('[data-filter="' + pane + '"]', box); }
    function optionsSlot() { return $('[data-options]', box); }

    function setStep(step) {
      box.dataset.step = step;
      // On a phone the pane that just appeared is the thing to look at.
      if (window.matchMedia('(max-width: 900px)').matches) {
        box.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }

    // ---- search: every word typed has to appear somewhere in the item's
    // keywords, so "united k" and "+44" and "uk" all find the same row.
    function applyFilter(pane) {
      var field = input(pane);
      var ul = list(pane);
      if (!field || !ul) { return; }
      var words = field.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
      var shown = 0;
      $$('[data-item]', ul).forEach(function (item) {
        var keys = (item.dataset.keys || '') + ' ' + (item.dataset.name || '').toLowerCase();
        var hit = words.every(function (w) { return keys.indexOf(w) !== -1; });
        item.parentElement.hidden = !hit;
        if (hit) { shown++; }
      });
      var more = $('[data-more]', ul);
      if (more) { more.hidden = words.length > 0; }
      var empty = $('.pempty[data-filtered]', ul);
      if (!shown && $$('[data-item]', ul).length) {
        if (!empty) {
          empty = document.createElement('li');
          empty.className = 'pempty';
          empty.setAttribute('data-filtered', '');
          ul.appendChild(empty);
        }
        empty.textContent = 'Nothing matches "' + field.value.trim() + '".';
        empty.hidden = false;
      } else if (empty) {
        empty.hidden = true;
      }
    }

    $$('[data-filter]', box).forEach(function (field) {
      field.addEventListener('input', function () {
        applyFilter(field.dataset.filter);
        searchServer(field.dataset.filter);
      });
      // Enter picks the only match, which is what a person who typed the
      // whole name expects to happen.
      field.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') { return; }
        event.preventDefault();
        var first = $$('[data-item]', list(field.dataset.filter)).filter(function (item) {
          return !item.parentElement.hidden;
        })[0];
        if (first) { first.click(); }
      });
      if (field.value) { applyFilter(field.dataset.filter); }
    });

    // ---- drawing a list the server sent
    function iconHtml(s) {
      var color = /^#[0-9a-f]{3,8}$/i.test(s.color || '') ? s.color : '#6c4df6';
      if (s.icon) {
        return '<span class="svc-ico" style="--c:' + color + '" aria-hidden="true"><svg><use href="' +
          esc(brands) + '#b-' + esc(s.icon) + '"></use></svg></span>';
      }
      return '<span class="svc-ico is-letter" style="--c:' + color + '" aria-hidden="true">' +
        esc((s.name || '?').charAt(0).toUpperCase()) + '</span>';
    }
    function itemHtml(pane, row, href) {
      var on = (pane === 'service' ? chosen.service : chosen.country) === row.id ? ' on' : '';
      var head = '<a class="pitem' + on + '" href="' + esc(href) + '" data-item="' + pane + '" data-id="' + row.id +
        '" data-slug="' + esc(row.slug) + '" data-name="' + esc(row.name) + '" data-keys="' + esc(row.keys) +
        '" data-price="' + row.from + '" data-pop="' + row.pop + '"';
      if (pane === 'service') {
        var sub = row.sub || (row.count + (row.count === 1 ? ' country' : ' countries'));
        return head + ' data-icon="' + esc(row.icon) + '" data-color="' + esc(row.color) + '">' + iconHtml(row) +
          '<span class="pname">' + esc(row.name) + '<small>' + sub + '</small></span>' +
          '<span class="pprice"><small>from</small>' + esc(money(row.from)) + '</span></a>';
      }
      var flag = flags + '/' + (row.iso || 'xx') + '.svg';
      return head + ' data-iso="' + esc(row.iso) + '" data-dial="' + esc(row.dial) + '">' +
        '<img class="flag" src="' + esc(flag) + '" alt="" width="28" height="21" loading="lazy" decoding="async" onerror="this.src=\'' + esc(flags) + '/xx.svg\'">' +
        '<span class="pname">' + esc(row.name) + (row.dial ? '<small>+' + esc(row.dial) + '</small>' : '') + '</span>' +
        '<span class="pprice"><small>from</small>' + esc(money(row.from)) + '</span></a>';
    }
    function hrefFor(pane, row) {
      var base = CFG.base.replace(/\/$/, '');
      var service = pane === 'service' ? row : findChosen('service');
      var country = pane === 'country' ? row : findChosen('country');
      if (tab === 'email') { return base + '/temporary-email/' + (service ? service.slug : ''); }
      if (tab === 'rental') { return base + '/rent-number/' + (country ? country.slug : ''); }
      if (service && country) { return base + '/' + service.slug + '/' + country.slug; }
      if (service) { return base + '/' + service.slug; }
      return base + '/country/' + (country ? country.slug : '');
    }
    function findChosen(pane) {
      var id = chosen[pane];
      if (!id) { return null; }
      var item = $('[data-item="' + pane + '"][data-id="' + id + '"]', box);
      return item ? { id: id, slug: item.dataset.slug } : null;
    }
    // ---- sorting: the order the server drew is "popular", kept on each row
    // so it can always be gone back to.
    function numberRows(pane) {
      $$('[data-item]', list(pane)).forEach(function (item, index) { item.parentElement.dataset.order = index; });
    }
    function sortLocal(pane) {
      var ul = list(pane);
      if (!ul) { return; }
      var how = sortBy[pane];
      var rows = $$('[data-item]', ul).map(function (item) { return item.parentElement; });
      rows.sort(function (a, b) {
        var x = a.firstElementChild.dataset, y = b.firstElementChild.dataset;
        if (how === 'price') { return (parseFloat(x.price) - parseFloat(y.price)) || x.name.localeCompare(y.name); }
        if (how === 'name') { return x.name.localeCompare(y.name); }
        return a.dataset.order - b.dataset.order;
      });
      var tail = $$('.pmore, .pempty', ul);
      rows.concat(tail).forEach(function (li) { ul.appendChild(li); });
      ul.scrollTop = 0;
    }
    ['service', 'country'].forEach(numberRows);

    function drawList(pane, rows, total) {
      var ul = list(pane);
      if (!ul) { return; }
      if (!rows.length) {
        ul.innerHTML = '<li class="pempty">Nothing on sale here right now.</li>';
      } else {
        ul.innerHTML = rows.map(function (row) { return '<li>' + itemHtml(pane, row, hrefFor(pane, row)) + '</li>'; }).join('');
        numberRows(pane);
        if (pane === 'country') { sortLocal(pane); }
      }
      // Only the most-bought apps are drawn; the rest are a search away.
      if (pane === 'service' && typeof total === 'number') {
        ul.dataset.total = total;
        var field = input(pane);
        if (total > rows.length && !(field && field.value.trim())) {
          var more = document.createElement('li');
          more.className = 'pmore';
          more.setAttribute('data-more', '');
          more.textContent = (total - rows.length).toLocaleString() + ' more apps - type a name above to find any of them.';
          ul.appendChild(more);
        }
      }
      applyFilter(pane);
    }

    // The list on the page is the top of a much longer one, so a search the
    // page cannot answer is asked of the server - after a pause, and only
    // the answer to the latest words is drawn.
    var searchTimer = 0;
    function searchServer(pane, now) {
      var ul = list(pane);
      var field = input(pane);
      if (pane !== 'service' || !ul || !field) { return; }
      if (parseInt(ul.dataset.total || '0', 10) <= $$('[data-item]', ul).length && !ul.dataset.searched && !now) { return; }
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        var words = field.value.trim();
        var params = new URLSearchParams({ tab: tab });
        if (words) { params.set('q', words); }
        if (sortBy.service !== 'popular') { params.set('sort', sortBy.service); }
        if (chosen.service) { params.set('service', chosen.service); }
        if (chosen.country && tab === 'numbers') { params.set('country', chosen.country); }
        fetch(CFG.base.replace(/\/$/, '') + '/api/picker?' + params.toString(), {
          credentials: 'same-origin', headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (data) {
          if (!data || !data.ok || field.value.trim() !== words) { return; }
          ul.dataset.searched = words ? '1' : '';
          drawList(pane, data.services, data.services_total);
        }).catch(function () { /* the drawn list still filters locally */ });
      }, now ? 0 : 250);
    }

    // ---- asking the server what the choice means
    function refresh(which, keepUrl) {
      var mine = ++request;
      var params = new URLSearchParams({ tab: tab });
      if (chosen.service) { params.set('service', chosen.service); }
      if (chosen.country) { params.set('country', chosen.country); }
      var typed = input('service') ? input('service').value.trim() : '';
      if (typed) { params.set('q', typed); }
      if (sortBy.service !== 'popular') { params.set('sort', sortBy.service); }
      var slot = optionsSlot();
      var other = '';
      var before;
      // The shape of what is coming, where it is coming: the options, and
      // the list this choice re-prices. The list just tapped stays put.
      if (slot) { slot.setAttribute('aria-busy', 'true'); slot.innerHTML = skOptions(); }
      if (tab === 'numbers') {
        other = which === 'service' ? 'country' : (which === 'country' ? 'service' : '');
        if (other && list(other)) { before = list(other).innerHTML; list(other).innerHTML = skRows(6); }
      }

      return fetch(CFG.base.replace(/\/$/, '') + '/api/picker?' + params.toString(), {
        credentials: 'same-origin', headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (mine !== request || !data || !data.ok) { return; }
        // A choice re-prices the other list: picking WhatsApp shows each
        // country's WhatsApp price, picking Pakistan each app's.
        if (tab === 'numbers') {
          if (which !== 'country') { drawList('country', data.countries); }
          if (which !== 'service') { drawList('service', data.services, data.services_total); }
        } else if (which === 'all') {
          if (list('service')) { drawList('service', data.services, data.services_total); }
          if (list('country')) { drawList('country', data.countries); }
        }
        if (slot) {
          slot.innerHTML = data.html;
          slot.removeAttribute('aria-busy');
          bindOptions(slot);
        }
        if (!keepUrl && data.url && window.history && history.replaceState) {
          history.replaceState({ picker: true, soft: true }, '', data.url);
        }
      }).catch(function () {
        if (mine !== request) { return; }
        if (slot) {
          slot.removeAttribute('aria-busy');
          slot.innerHTML = '<div class="opt-empty"><b>Could not load prices</b>' +
            '<p>Check your connection, then tap your choice again.</p></div>';
        }
        if (other && list(other) && before !== undefined) { list(other).innerHTML = before; }
      });
    }

    box.addEventListener('click', function (event) {
      var sorter = event.target.closest('[data-sort]');
      if (sorter) {
        var group = sorter.closest('[data-sorts]');
        var which = group.dataset.sorts;
        $$('[data-sort]', group).forEach(function (b) {
          b.classList.toggle('on', b === sorter);
          b.setAttribute('aria-pressed', b === sorter ? 'true' : 'false');
        });
        sortBy[which] = sorter.dataset.sort;
        var ul = list(which);
        // Every country is on the page; the apps are the top of a longer
        // list, so their "cheapest" has to come from the server.
        if (which === 'service' && ul && parseInt(ul.dataset.total || '0', 10) > $$('[data-item]', ul).length) {
          ul.innerHTML = skRows(6);
          searchServer('service', true);
        } else {
          sortLocal(which);
        }
        return;
      }
      var item = event.target.closest('[data-item]');
      if (item) {
        event.preventDefault();
        var pane = item.dataset.item;
        var id = parseInt(item.dataset.id, 10);
        chosen[pane] = id;
        $$('[data-item="' + pane + '"]', box).forEach(function (a) { a.classList.toggle('on', a === item); });

        var panes = Array.prototype.map.call($$('[data-pane]', box), function (p) { return p.dataset.pane; });
        var next = panes[panes.indexOf(pane) + 1] || 'options';
        // Numbers need both: after a service the country is next unless
        // one is already chosen, and the other way round.
        if (tab === 'numbers') {
          next = chosen.service && chosen.country ? 'options' : (chosen.service ? 'country' : 'service');
        }
        setStep(next);
        refresh(pane);
        return;
      }
      var back = event.target.closest('[data-back]');
      if (back) {
        setStep(back.dataset.back);
        return;
      }
      // The tabs are links to their own pages, drawn here without leaving:
      // the new tab lights at once and its panes appear as skeletons.
      var tabLink = event.target.closest('[data-tab-link]');
      if (tabLink && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) {
        event.preventDefault();
        if (tabLink.classList.contains('on')) { return; }
        $$('[data-tab-link]', box).forEach(function (a) {
          a.classList.toggle('on', a === tabLink);
          if (a === tabLink) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
        });
        var count = tabLink.dataset.tabLink === 'numbers' ? 3 : 2;
        var grid = $('.buy-grid', box);
        if (grid) {
          grid.className = 'buy-grid cols-' + count + ' is-loading';
          grid.innerHTML = skPanes(count);
        }
        swapPage(tabLink.href, true);
      }
    });

    bindOptions(optionsSlot());
  }

  // ---- the last step: options and the order form
  function bindOptions(slot) {
    if (!slot) { return; }
    var form = $('[data-order-form]', slot);
    if (!form || form.dataset.bound) { return; }
    form.dataset.bound = '1';

    function current() { return $('input[name="offer_id"]:checked', form); }
    function relabel() {
      var radio = current();
      $$('.opt', form).forEach(function (opt) { opt.classList.toggle('on', opt.contains(radio)); });
      if (!radio) { return; }
      var price = parseFloat(radio.dataset.price);
      var shown = radio.dataset.money || money(price);
      $$('[data-buy]', form).forEach(function (button) {
        // Signed in: the wallet pays when it covers this option, and when it
        // does not the same button pays directly - never a trip to top up.
        if (button.dataset.walletLabel) {
          var covers = parseFloat(button.dataset.balance || '0') + 0.001 >= price;
          var direct = !covers && button.dataset.gateway === '1';
          button.value = direct ? 'gateway' : 'wallet';
          button.dataset.wait = direct ? 'Placing your order…' : 'Getting your number…';
          button.textContent = (direct ? button.dataset.label : button.dataset.walletLabel).replace('{price}', shown);
          var note = $('[data-wallet-short]', form);
          if (note) { note.hidden = !direct; }
          var other = $('[data-buy-gateway]', form);
          if (other) { other.hidden = direct; }
          return;
        }
        if (button.dataset.label) {
          button.textContent = button.dataset.label.replace('{price}', shown);
        }
      });
    }
    form.addEventListener('change', relabel);
    relabel();

    // A returning guest's contact boxes are filled from last time, in this
    // browser only. Storage can be refused (a private window) - then they
    // simply start empty.
    $$('[data-remember]', form).forEach(function (field) {
      try { field.value = field.value || localStorage.getItem('otpf-' + field.dataset.remember) || ''; } catch (e) { /* empty is fine */ }
    });
    function remember() {
      $$('[data-remember]', form).forEach(function (field) {
        try { localStorage.setItem('otpf-' + field.dataset.remember, field.value.trim()); } catch (e) { /* nothing to keep it in */ }
      });
    }

    form.addEventListener('submit', function (event) {
      var button = event.submitter || $('[data-buy]', form);
      event.preventDefault();
      var body = new FormData(form);
      if (button && button.name) { body.set(button.name, button.value); }
      busy(button, true);
      clearFormError(form);
      var cover = buying(form, button && button.value === 'wallet');

      post(form.action, body).then(function (data) {
        if (data && data.ok) { remember(); }
        // The number was got, but it costs more than the price shown: the
        // customer is asked before anything is charged.
        if (data && data.quote) {
          remember();
          cover.stop();
          busy(button, false);
          showQuote(data.quote);
          return;
        }
        if (data && data.ok && data.pay) {
          cover.stop();
          busy(button, false);
          showPay(data);
          return;
        }
        if (data && data.ok && data.redirect) {
          cover.say('Opening your order…');
          barStart();
          window.location.href = data.redirect;
          return;
        }
        cover.stop();
        busy(button, false);
        // The price moved: the server sends the options again, priced as
        // they are now, and the message goes on the new form.
        if (data && data.repick) {
          var holder = form.parentNode;
          holder.innerHTML = data.repick;
          bindOptions(holder);
          var fresh = $('[data-order-form]', holder);
          if (fresh) { formError(fresh, data.error, data); }
          return;
        }
        formError(form, (data && data.error) || 'That did not go through. Please try again.', data);
      }).catch(function () {
        cover.stop();
        busy(button, false);
        formError(form, 'We could not reach the shop. Check your connection and tap the button again.');
      });
    });
  }

  // A cover over the order form while it is being placed. Buying from the
  // wallet gets the number in the same request, which can take a few
  // seconds, so the words move on with it rather than sitting on one line.
  function buying(form, wallet) {
    var lines = wallet
      ? ['Checking today\'s price…', 'Reserving your number…', 'Almost there…']
      : ['Checking today\'s price…', 'Creating your order…'];
    var cover = document.createElement('div');
    cover.className = 'buying';
    cover.setAttribute('role', 'status');
    cover.innerHTML = '<span class="buying-spin" aria-hidden="true"></span><b></b><small>Please keep this page open.</small>';
    var text = $('b', cover);
    var step = 0;
    text.textContent = lines[0];
    form.classList.add('is-busy');
    form.appendChild(cover);
    var timer = setInterval(function () {
      if (step < lines.length - 1) { text.textContent = lines[++step]; }
    }, 1700);
    return {
      say: function (words) { clearInterval(timer); text.textContent = words; },
      stop: function () { clearInterval(timer); form.classList.remove('is-busy'); cover.remove(); }
    };
  }

  function clearFormError(form) {
    var slot = $('[data-form-error]', form);
    if (slot) { slot.remove(); }
    $$('[aria-invalid]', form).forEach(function (field) { field.removeAttribute('aria-invalid'); });
  }
  function formError(form, message, data) {
    clearFormError(form);
    var slot = document.createElement('div');
    slot.className = 'form-err';
    slot.setAttribute('data-form-error', '');
    slot.textContent = message;
    if (data && (data.topup || data.login)) {
      var link = document.createElement('a');
      link.href = data.topup || data.login;
      link.textContent = data.topup ? ' Add funds' : ' Sign in';
      link.style.fontWeight = '800';
      link.style.textDecoration = 'underline';
      slot.appendChild(link);
    }
    // A box the customer typed into wrongly gets the message under it.
    var field = data && data.field ? $('[name="' + data.field + '"]', form) : null;
    if (field) { field.setAttribute('aria-invalid', 'true'); }
    var after = (field && field.closest('.ofield')) || $('.opts', form) || form.firstChild;
    after.parentNode.insertBefore(slot, after.nextSibling);
    slot.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    if (field) { field.focus(); }
  }

  // ------------------------------------------------- paying, in the box --
  function showPay(data) {
    var modal = $('#payModal');
    if (!modal) { window.location.href = data.redirect; return; }
    $('[data-pay-slot]', modal).innerHTML = data.pay;
    $('[data-pay-code]', modal).textContent = data.code;
    $('[data-pay-copy]', modal).setAttribute('data-copy', data.code);
    $('[data-pay-summary]', modal).textContent = (data.label || '') + ' · ' + (data.amount || '');
    $('[data-pay-link]', modal).href = data.redirect;
    var hold = $('[data-pay-hold]', modal);
    if (hold) {
      hold.hidden = !(data.hold_left > 0);
      var clock = $('[data-hold-left]', hold);
      clock.setAttribute('data-hold-left', String(data.hold_left || 0));
      clock.textContent = fmtClock(data.hold_left || 0);
      delete clock.dataset.until;
      holdClocks();
    }
    syncPaymentFields();
    openModal(modal);
  }

  // ----------------------------------------- a held number's new price --
  function showQuote(html) {
    var modal = $('#quoteModal');
    var slot = modal && $('[data-quote-slot]', modal);
    if (!slot) { window.location.reload(); return; }
    slot.innerHTML = html;
    holdClocks();
    openModal(modal);
  }

  function quoteError(form, message) {
    var box = form.closest('[data-quote]') || form;
    var slot = $('[data-form-error]', box);
    if (!slot) {
      slot = document.createElement('div');
      slot.className = 'form-err';
      slot.setAttribute('data-form-error', '');
      box.insertBefore(slot, form);
    }
    slot.textContent = message;
  }

  // Agreeing answers exactly as placing the order does - the wallet pays
  // and the order opens, or the pay step comes up - so it lands in the same
  // places. Turning it down cancels the unpaid order; nothing was charged.
  document.addEventListener('submit', function (event) {
    var form = event.target.closest('[data-quote-accept], [data-quote-decline]');
    if (!form) { return; }
    event.preventDefault();
    var button = $('button', form);
    var accept = form.hasAttribute('data-quote-accept');
    var inModal = form.closest('#quoteModal');
    busy(button, true);
    post(form.action, new FormData(form)).then(function (data) {
      busy(button, false);
      if (!accept) {
        if (inModal) {
          closeModal(inModal);
          toast('No problem - nothing was charged.');
        } else {
          refreshPage();
        }
        return;
      }
      if (data && data.ok && data.pay) {
        if (inModal) { closeModal(inModal); }
        showPay(data);
        return;
      }
      if (data && data.ok && data.redirect) {
        barStart();
        window.location.href = data.redirect;
        return;
      }
      quoteError(form, (data && data.error) || 'That did not go through. Please try again.');
    }).catch(function () {
      busy(button, false);
      quoteError(form, 'We could not reach the shop. Check your connection and try again.');
    });
  });

  // How long a held number stays held, counted down wherever it is drawn -
  // in markup that came with the page or was put in later. At nought the
  // price can no longer be agreed to, and the box says so.
  var holdTicker = null;
  function holdClocks() {
    if (holdTicker) { return; }
    holdTicker = setInterval(function () {
      var clocks = $$('[data-hold-left]');
      clocks.forEach(function (clock) {
        if (!clock.dataset.until) {
          clock.dataset.until = String(Date.now() + (parseInt(clock.getAttribute('data-hold-left'), 10) || 0) * 1000);
        }
        var left = Math.max(0, (parseInt(clock.dataset.until, 10) - Date.now()) / 1000);
        clock.textContent = fmtClock(left);
        var quote = clock.closest('[data-quote]');
        if (left <= 0 && quote && !quote.classList.contains('is-over')) {
          quote.classList.add('is-over');
          var go = $('[data-quote-accept] button', quote);
          if (go) { go.disabled = true; }
          quoteError($('[data-quote-accept]', quote), 'We are no longer holding this number. Close this and pick again.');
        }
      });
      if (!clocks.length) { clearInterval(holdTicker); holdTicker = null; }
    }, 1000);
  }
  holdClocks();

  // Payment method tiles: light the chosen one and open only its step.
  document.addEventListener('change', function (event) {
    var radio = event.target.closest('[data-pm-radio]');
    if (!radio) { return; }
    var scope = radio.closest('form') || document;
    $$('[data-pay-opt]', scope).forEach(function (opt) { opt.classList.toggle('on', opt.contains(radio)); });
    $$('[data-pay-for]', scope).forEach(function (step) {
      step.classList.toggle('on', step.getAttribute('data-pay-for') === radio.value);
    });
    syncPaymentFields();
  });

  // Only the chosen manual method's questions are sent: the rest are
  // disabled, which also stops a hidden required field blocking the submit.
  function syncPaymentFields() {
    $$('#payForm').forEach(function (form) {
      var chosenRadio = $('[data-pm-radio]:checked', form);
      var chosenId = chosenRadio ? chosenRadio.value : null;
      $$('[data-pf-for]', form).forEach(function (group) {
        var mine = group.getAttribute('data-pf-for') === chosenId;
        $$('input, select, textarea', group).forEach(function (field) {
          field.disabled = !mine;
          field.required = mine && field.hasAttribute('data-pf-required');
        });
      });
      $$('[data-pay-for]', form).forEach(function (step) {
        var mine = step.getAttribute('data-pay-for') === chosenId;
        $$('input[name="paid_amount"]', step).forEach(function (field) { field.disabled = !mine; });
      });
    });
  }

  // The pay step posts over fetch, so a refusal is answered in the box the
  // customer is standing in rather than by loading another page.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || form.id !== 'payForm') { return; }
    if (!form.checkValidity()) { return; }
    event.preventDefault();

    var button = event.submitter || $('[data-pay-go]', form);
    var action = (button && button.getAttribute('formaction')) || form.action;
    var body = new FormData(form);
    busy(button, true);

    fetch(action, {
      method: 'POST', body: body, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data && data.ok && data.redirect) { window.location.href = data.redirect; return; }
      busy(button, false);
      var slot = $('[data-pay-error]', form);
      if (!slot) {
        slot = document.createElement('div');
        slot.className = 'form-err';
        slot.setAttribute('data-pay-error', '');
        form.insertBefore(slot, form.firstChild);
      }
      slot.textContent = (data && data.error) || 'That payment could not be started.';
      slot.scrollIntoView({ block: 'nearest' });
      $$('[name^="pf_"]', form).forEach(function (field) {
        var key = field.getAttribute('name').slice(3);
        field.toggleAttribute('aria-invalid', !!(data && data.fields && data.fields[key]));
      });
    }).catch(function () { busy(button, false); form.submit(); });
  });

  // ======================================================== number page ==
  // The page as the server would draw it now, put in place without a reload.
  function refreshPage() {
    var card = $('[data-live]') || $('#main');
    if (card) { card.classList.add('is-updating'); }
    return swapPage(window.location.href, false);
  }

  function fmtClock(seconds) {
    seconds = Math.max(0, Math.round(seconds));
    var m = Math.floor(seconds / 60), s = seconds % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function initLive(root) {
    var status = root.dataset.status;
    var url = root.dataset.url;
    var timer = $('[data-timer]', root);
    var left = timer ? parseInt(timer.dataset.seconds, 10) || 0 : 0;
    var total = Math.max(left, 1);
    var changeBtn = $('[data-change]', root);
    var releaseIn = changeBtn ? parseInt(changeBtn.dataset.release, 10) || 0 : 0;
    var lastCode = ($('[data-code-text]', root) || {}).textContent || '';
    var stopped = false;

    // The clock the customer watches, every second, without asking anyone.
    function tick() {
      if (timer) {
        left = Math.max(0, left - 1);
        var bar = $('[data-timer-bar]', timer);
        if (bar) { bar.style.strokeDashoffset = String(100 - (left / total) * 100); }
        $('[data-timer-text]', timer).textContent = fmtClock(left);
        timer.classList.toggle('is-low', left < 120);
      }
      if (changeBtn && releaseIn > 0) {
        releaseIn--;
        var label = $('[data-change-text]', changeBtn);
        if (releaseIn > 0) {
          label.textContent = 'Free change in ' + fmtClock(releaseIn);
        } else {
          // Cancel opens either way; a change only while free ones are left.
          var cancel = $('[data-cancel]', root);
          if (cancel) { cancel.disabled = false; }
          if (changeBtn.dataset.left === '0') {
            label.textContent = 'No free changes left';
          } else {
            label.textContent = 'Change number - free';
            changeBtn.disabled = false;
          }
        }
      }
    }
    if (timer) {
      var bar = $('[data-timer-bar]', timer);
      if (bar) { bar.style.strokeDashoffset = '0'; }
    }
    var ticker = setInterval(tick, 1000);
    cleanups.push(function () { stopped = true; clearInterval(ticker); });

    function apply(state) {
      if (!state) { return; }
      if (state.status !== status) {
        // A new stage of the order is a new page: paid becomes a number, a
        // number becomes a code or a refund. Drawing it again from the server
        // shows it the same way a first visit would.
        stopped = true;
        refreshPage();
        return;
      }
      if (state.code && state.code !== lastCode) {
        lastCode = state.code;
        var sms = $('[data-sms]', root);
        if (sms) {
          sms.classList.add('has-code');
          $('.nc-waiting', sms).hidden = true;
          var codeBox = $('.nc-code', sms);
          codeBox.hidden = false;
          codeBox.classList.remove('is-new');
          void codeBox.offsetWidth;
          codeBox.classList.add('is-new');
          $('[data-code-text]', sms).textContent = state.code;
          $('[data-code-copy]', sms).setAttribute('data-copy', state.code);
          var codes = $('[data-codes]', sms);
          if (codes && state.codes && state.codes.length > 1) {
            codes.innerHTML = state.codes.map(function (c) {
              return '<li><b>' + esc(c.code) + '</b><small>' + esc(c.at) + '</small></li>';
            }).join('');
            codes.hidden = false;
          }
        }
        toast('Your code arrived: ' + state.code);
        if (navigator.vibrate) { navigator.vibrate(120); }
        flashTitle(state.code);
      }
      if (typeof state.seconds_left === 'number' && Math.abs(state.seconds_left - left) > 3) {
        left = state.seconds_left;
      }
    }

    // One request at a time, every few seconds; slower once the code is in.
    function poll() {
      if (stopped) { return; }
      post(url, {}).then(function (data) {
        apply(data && data.state);
      }).catch(function () { /* the next poll will try again */ })
        .then(function () {
          if (!stopped) { setTimeout(poll, document.hidden ? 12000 : (lastCode ? 8000 : 4000)); }
        });
    }
    setTimeout(poll, 2500);

    // The number's actions post over fetch and come back to a fresh page.
    $$('form[data-numact]', root).forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        (form.dataset.confirm ? askForm(form) : Promise.resolve(true)).then(function (go) {
          if (!go) { return; }
          var button = $('button[type="submit"]', form);
          busy(button, true);
          stopped = true;
          post(form.action, new FormData(form)).then(function (data) {
            if (data && data.ok) {
              if (data.message) { toast(data.message); }
              refreshPage();
              return;
            }
            // A refusal is said in a box that waits to be read, not a toast
            // that is gone before the reason is.
            busy(button, false);
            stopped = false;
            ask({ alert: true, title: 'That did not go through', message: (data && data.message) || 'Please try again.' })
              .then(refreshPage);
          }).catch(function () {
            busy(button, false);
            stopped = false;
            ask({ alert: true, title: 'No connection', message: 'We could not reach the shop. Check your connection and try again.' });
          });
        });
      });
    });
  }

  // Bring the tab back to someone who went to the app to ask for the code.
  var titleTimer = null;
  function flashTitle(code) {
    var original = document.title;
    var on = false;
    clearInterval(titleTimer);
    titleTimer = setInterval(function () {
      document.title = on ? original : 'Code: ' + code;
      on = !on;
    }, 1000);
    window.addEventListener('focus', function stop() {
      clearInterval(titleTimer);
      document.title = original;
      window.removeEventListener('focus', stop);
    });
  }

  // State-panel actions (retry, get a new number) outside a live page.
  function bindStateActions(root) {
    $$('form[data-numact]', root).forEach(function (form) {
      if (form.closest('[data-live]')) { return; }
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        (form.dataset.confirm ? askForm(form) : Promise.resolve(true)).then(function (go) {
          if (!go) { return; }
          var button = $('button[type="submit"]', form);
          busy(button, true);
          post(form.action, new FormData(form)).then(function (data) {
            if (data && data.ok) {
              if (data.message) { toast(data.message); }
              refreshPage();
              return;
            }
            busy(button, false);
            ask({ alert: true, title: 'That did not go through', message: (data && data.message) || 'Please try again.' })
              .then(refreshPage);
          }).catch(function () {
            busy(button, false);
            ask({ alert: true, title: 'No connection', message: 'We could not reach the shop. Check your connection and try again.' });
          });
        });
      });
    });
  }

  // ------------------------------------------------------ cancelling --
  // The choice folds out under its button; nothing is sent until one of
  // the buttons inside it is pressed.
  document.addEventListener('click', function (event) {
    var opener = event.target.closest('[data-cancel-open]');
    var closer = event.target.closest('[data-cancel-close]');
    if (!opener && !closer) { return; }
    var scope = (opener || closer).closest('.numcard, .panel, main') || document;
    var boxEl = $('[data-cancel-box]', scope);
    if (!boxEl) { return; }
    boxEl.hidden = !!closer;
    if (opener) { boxEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  });

  // ============================================================== tracker ==
  var trackModal = $('#trackModal');
  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-track-open]');
    if (!trigger || !trackModal) { return; }
    event.preventDefault();
    var drawer = $('#drawer');
    if (drawer) { drawer.classList.remove('show'); }
    $('[data-track-error]', trackModal).hidden = true;
    $('[data-track-card]', trackModal).hidden = true;
    openModal(trackModal);
    setTimeout(function () { var field = $('#tkInput'); if (field) { field.focus(); } }, 60);
  });
  var trackForm = $('#trackForm');
  if (trackForm) {
    trackForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var code = $('#tkInput').value.trim();
      if (!code) { return; }
      var button = $('[data-track-go]', trackForm);
      var error = $('[data-track-error]', trackModal);
      var card = $('[data-track-card]', trackModal);
      busy(button, true);
      error.hidden = true;
      card.hidden = true;
      fetch(trackForm.dataset.api + '?code=' + encodeURIComponent(code), {
        credentials: 'same-origin', headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); }).then(function (data) {
        busy(button, false);
        if (data && data.ok) {
          $$('[data-tk]', card).forEach(function (el) { el.textContent = data.order[el.dataset.tk] || ''; });
          $('[data-tk-open]', card).href = data.order.url;
          card.hidden = false;
        } else {
          error.textContent = (data && data.error) || 'We could not find that order.';
          error.hidden = false;
        }
      }).catch(function () {
        busy(button, false);
        error.textContent = 'Network error. Please try again.';
        error.hidden = false;
      });
    });
  }

  // =============================================================== wallet ==
  // Preset amounts fill the box; the box is still the thing that submits.
  document.addEventListener('click', function (event) {
    var preset = event.target.closest('[data-amount]');
    if (!preset) { return; }
    var field = $('#depositAmount');
    if (!field) { return; }
    field.value = preset.dataset.amount;
    $$('[data-amount]').forEach(function (b) { b.classList.toggle('on', b === preset); });
  });

  // ============================================== confirm before posting ==
  // Asked first, on the way down, so nothing else sees the submit until the
  // answer is yes - then the form is sent again with the answer on it.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.matches('form[data-confirm]') || form.matches('[data-numact]')) { return; }
    if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
    event.preventDefault();
    event.stopPropagation();
    var submitter = event.submitter;
    askForm(form).then(function (go) {
      if (!go) { return; }
      form.dataset.confirmed = '1';
      if (form.requestSubmit) { form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); } else { form.submit(); }
    });
  }, true);

  // ========================================================= rental inbox ==
  function initInbox(inbox) {
    var known = parseInt(inbox.dataset.count, 10) || 0;
    var stopped = false;
    var timer = 0;
    cleanups.push(function () { stopped = true; clearTimeout(timer); });
    (function poll() {
      if (stopped) { return; }
      post(inbox.dataset.url, {}).then(function (data) {
        if (!stopped && data && data.ok && data.count > known) {
          known = data.count;
          inbox.innerHTML = data.html;
          toast('New SMS received');
          flashTitle('new SMS');
        }
      }).catch(function () {}).then(function () {
        if (!stopped) { timer = setTimeout(poll, document.hidden ? 30000 : 10000); }
      });
    })();
  }

  boot(document);
  watchNavigation();
})();
