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
  function copyText(text, button) {
    function done() {
      if (!button) { toast('Copied'); return; }
      var was = button.innerHTML;
      button.classList.add('is-copied');
      button.textContent = 'Copied';
      setTimeout(function () { button.innerHTML = was; button.classList.remove('is-copied'); }, 1500);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text); done(); });
      return;
    }
    legacyCopy(text);
    done();
  }
  function legacyCopy(text) {
    var carrier = document.createElement('textarea');
    carrier.value = text;
    carrier.setAttribute('readonly', '');
    carrier.style.position = 'fixed';
    carrier.style.left = '-9999px';
    document.body.appendChild(carrier);
    carrier.select();
    try { document.execCommand('copy'); } catch (error) { /* nothing else to try */ }
    document.body.removeChild(carrier);
  }
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy]');
    if (!button) { return; }
    var text = button.getAttribute('data-copy');
    if (text) { copyText(text, button); }
  });

  // ================================================================ picker ==
  var box = $('[data-picker]');
  if (box) { initPicker(box); }

  function initPicker(box) {
    box.classList.add('is-live');
    var tab = box.dataset.tab;
    var chosen = { service: parseInt(box.dataset.service, 10) || 0, country: parseInt(box.dataset.country, 10) || 0 };
    var flags = box.dataset.flags;
    var brands = box.dataset.brands;
    var request = 0;

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
      field.addEventListener('input', function () { applyFilter(field.dataset.filter); });
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
    function drawList(pane, rows) {
      var ul = list(pane);
      if (!ul) { return; }
      if (!rows.length) {
        ul.innerHTML = '<li class="pempty">Nothing on sale here right now.</li>';
      } else {
        ul.innerHTML = rows.map(function (row) { return '<li>' + itemHtml(pane, row, hrefFor(pane, row)) + '</li>'; }).join('');
      }
      applyFilter(pane);
    }

    // ---- asking the server what the choice means
    function refresh(which, keepUrl) {
      var mine = ++request;
      var params = new URLSearchParams({ tab: tab });
      if (chosen.service) { params.set('service', chosen.service); }
      if (chosen.country) { params.set('country', chosen.country); }
      var slot = optionsSlot();
      if (slot) { slot.setAttribute('aria-busy', 'true'); slot.style.opacity = '.55'; }

      return fetch(CFG.base.replace(/\/$/, '') + '/api/picker?' + params.toString(), {
        credentials: 'same-origin', headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (mine !== request || !data || !data.ok) { return; }
        // A choice re-prices the other list: picking WhatsApp shows each
        // country's WhatsApp price, picking Pakistan each app's.
        if (tab === 'numbers') {
          if (which !== 'country') { drawList('country', data.countries); }
          if (which !== 'service') { drawList('service', data.services); }
        } else if (which === 'all') {
          if (list('service')) { drawList('service', data.services); }
          if (list('country')) { drawList('country', data.countries); }
        }
        if (slot) {
          slot.innerHTML = data.html;
          slot.removeAttribute('aria-busy');
          slot.style.opacity = '';
          bindOptions(slot);
        }
        if (!keepUrl && data.url && window.history && history.replaceState) {
          history.replaceState({ picker: true }, '', data.url);
        }
      }).catch(function () {
        if (slot) { slot.removeAttribute('aria-busy'); slot.style.opacity = ''; }
        toast('Could not load prices. Check your connection and try again.');
      });
    }

    box.addEventListener('click', function (event) {
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
      }
    });

    // The tabs are links to their own pages; nothing to take over there.
    bindOptions(optionsSlot());

    // Coming back with Back: the address names a choice the page no longer shows.
    window.addEventListener('popstate', function () { window.location.reload(); });
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
      $$('[data-buy]', form).forEach(function (button) {
        if (button.dataset.label) {
          button.textContent = button.dataset.label.replace('{price}', radio.dataset.money || money(price));
        }
        // A wallet that cannot cover the choice says so before it is pressed.
        if (button.value === 'wallet' && CFG.user) {
          var short = CFG.user.balance + 0.001 < price;
          button.toggleAttribute('data-short', short);
          if (short) { button.textContent = 'Add funds to pay ' + (radio.dataset.money || money(price)); }
        }
      });
    }
    form.addEventListener('change', relabel);
    relabel();

    form.addEventListener('submit', function (event) {
      var button = event.submitter || $('[data-buy]', form);
      if (button && button.hasAttribute('data-short')) {
        event.preventDefault();
        var radio = current();
        var need = radio ? Math.ceil(parseFloat(radio.dataset.price) - CFG.user.balance) : 0;
        window.location.href = CFG.base.replace(/\/$/, '') + '/account/wallet' + (need > 0 ? '?amount=' + need : '');
        return;
      }
      event.preventDefault();
      var body = new FormData(form);
      if (button && button.name) { body.set(button.name, button.value); }
      busy(button, true);
      clearFormError(form);

      post(form.action, body).then(function (data) {
        if (data && data.ok && data.pay) {
          busy(button, false);
          showPay(data);
          return;
        }
        if (data && data.ok && data.redirect) {
          window.location.href = data.redirect;
          return;
        }
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
        busy(button, false);
        form.submit();
      });
    });
  }

  function clearFormError(form) {
    var slot = $('[data-form-error]', form);
    if (slot) { slot.remove(); }
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
    var after = $('.opts', form) || form.firstChild;
    after.parentNode.insertBefore(slot, after.nextSibling);
    slot.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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
    syncPaymentFields();
    openModal(modal);
  }

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
  syncPaymentFields();

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
  var live = $('[data-live]');
  if (live) { initLive(live); }

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
          label.textContent = 'Change number in ' + fmtClock(releaseIn);
        } else {
          label.textContent = 'Change number';
          changeBtn.disabled = false;
          var cancel = $('[data-cancel]', root);
          if (cancel) { cancel.disabled = false; }
        }
      }
    }
    if (timer) {
      var bar = $('[data-timer-bar]', timer);
      if (bar) { bar.style.strokeDashoffset = '0'; }
    }
    setInterval(tick, 1000);

    function apply(state) {
      if (!state) { return; }
      if (state.status !== status) {
        // A new stage of the order is a new page: paid becomes a number, a
        // number becomes a code or a refund. Reloading draws it the same way
        // a first visit would.
        stopped = true;
        window.location.reload();
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
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { return; }
        var button = $('button[type="submit"]', form);
        busy(button, true);
        stopped = true;
        post(form.action, new FormData(form)).then(function (data) {
          if (data && data.message) { toast(data.message); }
          setTimeout(function () { window.location.reload(); }, data && data.ok ? 300 : 1600);
        }).catch(function () { form.submit(); });
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
  $$('form[data-numact]').forEach(function (form) {
    if (form.closest('[data-live]')) { return; }
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { return; }
      var button = $('button[type="submit"]', form);
      busy(button, true);
      post(form.action, new FormData(form)).then(function (data) {
        if (data && data.message) { toast(data.message); }
        setTimeout(function () { window.location.reload(); }, data && data.ok ? 300 : 1600);
      }).catch(function () { form.submit(); });
    });
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
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.matches('form[data-confirm]') || form.matches('[data-numact]')) { return; }
    if (!window.confirm(form.dataset.confirm)) { event.preventDefault(); }
  }, true);

  // ========================================================= rental inbox ==
  var inbox = $('[data-inbox]');
  if (inbox) {
    var known = parseInt(inbox.dataset.count, 10) || 0;
    (function poll() {
      post(inbox.dataset.url, {}).then(function (data) {
        if (data && data.ok && data.count > known) {
          known = data.count;
          inbox.innerHTML = data.html;
          toast('New SMS received');
          flashTitle('new SMS');
        }
      }).catch(function () {}).then(function () { setTimeout(poll, document.hidden ? 30000 : 10000); });
    })();
  }
})();
