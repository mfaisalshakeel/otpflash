/* Admin panel behaviour. Small enough to stay in one file. */
(function () {
  'use strict';

  // --- mobile sidebar ------------------------------------------------------
  function openSide()  { document.body.classList.add('side-open'); }
  function closeSide() { document.body.classList.remove('side-open'); }

  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-side-open]'))  { openSide(); }
    if (event.target.closest('[data-side-close]')) { closeSide(); }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { closeSide(); }
  });

  // --- the bell's dropdown -------------------------------------------------
  // The bell is a link to the feed; this turns it into a dropdown and only
  // then stops it navigating, so a browser that never runs this still has a
  // working bell.
  (function () {
    var bell = document.getElementById('bellBtn');
    var drop = document.getElementById('bellDrop');
    if (!bell || !drop) { return; }

    function close() {
      drop.hidden = true;
      bell.setAttribute('aria-expanded', 'false');
    }

    bell.addEventListener('click', function (event) {
      event.preventDefault();
      var opening = drop.hidden;
      drop.hidden = !opening;
      bell.setAttribute('aria-expanded', opening ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
      if (!drop.hidden && !event.target.closest('.bellwrap')) { close(); }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { close(); }
    });
  })();

  // --- confirm destructive actions ----------------------------------------
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });

  // --- copy buttons --------------------------------------------------------
  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-copy]');
    if (!btn) { return; }
    var text = btn.getAttribute('data-copy');
    var done = function () {
      var original = btn.textContent;
      btn.textContent = 'Copied';
      setTimeout(function () { btn.textContent = original; }, 1400);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, done);
      return;
    }

    // Older browsers, and any page not served over HTTPS: the clipboard API
    // is missing there, and saying "Copied" without copying is worse than
    // not offering it.
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

  // --- finding your way around a long list of boxes ------------------------
  // The boxes are the control and always were; this only hides the ones you
  // are not looking for, and says how many are ticked so a choice scrolled
  // out of sight is still accounted for.
  document.querySelectorAll('[data-picker-bar]').forEach(function (bar) {
    var picker = bar.closest('.picker');
    var input  = bar.querySelector('[data-picker-find]');
    var count  = bar.querySelector('[data-picker-count]');
    var none   = picker ? picker.querySelector('[data-picker-none]') : null;
    if (!picker || !input) { return; }

    var rows = Array.prototype.slice.call(picker.querySelectorAll('.pick'));
    bar.hidden = false;

    function tally() {
      if (!count) { return; }
      var n = picker.querySelectorAll('input:checked').length;
      count.textContent = n ? n + ' of ' + rows.length : rows.length + ' to choose from';
    }

    function filter() {
      var needle = input.value.trim().toLowerCase();
      var shown  = 0;

      rows.forEach(function (row) {
        // A ticked one never hides: it is part of the answer, and an answer
        // that disappears while you search for the next one is how two get
        // chosen where three were meant.
        var box  = row.querySelector('input');
        var keep = needle === '' || (box && box.checked)
          || row.textContent.toLowerCase().indexOf(needle) > -1;
        row.hidden = !keep;
        shown += keep ? 1 : 0;
      });

      if (none) { none.hidden = shown > 0; }
    }

    input.addEventListener('input', filter);
    picker.addEventListener('change', tally);
    tally();
  });

  // --- saying so without reloading -----------------------------------------
  // A flash message is drawn by the server on the next page. Where there is
  // no next page - an import that stays put, an order saved in place - this
  // says the same thing in the same words, and takes itself away.
  window.adminToast = function (message, kind) {
    var tray = document.getElementById('toasts');
    if (!tray) {
      tray = document.createElement('div');
      tray.id = 'toasts';
      tray.className = 'toasts';
      document.body.appendChild(tray);
    }

    var toast = document.createElement('div');
    toast.className = 'toast toast-' + (kind || 'success');
    toast.setAttribute('role', 'status');
    toast.textContent = message;
    tray.appendChild(toast);

    // Long enough to read twice, which is what people do with a number.
    setTimeout(function () {
      toast.classList.add('going');
      setTimeout(function () { toast.remove(); }, 400);
    }, 4200);
  };

  // --- dragging a list into order ------------------------------------------
  // Pointer events rather than HTML5 drag-and-drop: the same code then works
  // with a mouse, a finger and a stylus, and a table row is not a thing the
  // browser drags well anyway. The rows are only moved on screen while the
  // pointer is down; the order is sent once, when it is let go.
  document.querySelectorAll('[data-sortable]').forEach(function (list) {
    var table = list.getAttribute('data-sortable');
    var start = parseInt(list.getAttribute('data-start') || '0', 10) || 0;
    var token = list.getAttribute('data-token') || '';
    var url   = list.getAttribute('data-url') || '';
    var held  = null;

    function rows() {
      return Array.prototype.slice.call(list.querySelectorAll('[data-id]'));
    }

    list.addEventListener('pointerdown', function (event) {
      var handle = event.target.closest('[data-drag]');
      if (!handle) { return; }

      held = handle.closest('[data-id]');
      if (!held) { return; }

      // Without this the browser starts selecting text, or scrolls the page
      // under the finger instead of moving the row.
      event.preventDefault();
      handle.setPointerCapture(event.pointerId);
      held.classList.add('is-dragging');
      document.body.classList.add('is-sorting');
    });

    list.addEventListener('pointermove', function (event) {
      if (!held) { return; }

      var under = document.elementFromPoint(event.clientX, event.clientY);
      var over  = under && under.closest('[data-id]');
      if (!over || over === held || over.parentNode !== held.parentNode) { return; }

      // Which side of it the pointer is on decides whether the held row goes
      // above or below, so a slow drag does not flip back and forth.
      var box = over.getBoundingClientRect();
      var below = event.clientY > box.top + (box.height / 2);
      over.parentNode.insertBefore(held, below ? over.nextSibling : over);
    });

    function drop() {
      if (!held) { return; }

      held.classList.remove('is-dragging');
      document.body.classList.remove('is-sorting');
      held = null;

      if (!url || !token) { return; }

      var body = new FormData();
      body.append('_token', token);
      body.append('table', table);
      body.append('start', String(start));
      rows().forEach(function (row) { body.append('ids[]', row.getAttribute('data-id')); });

      list.classList.add('is-saving');
      fetch(url, {
        method: 'POST', body: body,
        headers: { 'X-Requested-With': 'fetch' },
        credentials: 'same-origin'
      })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          list.classList.remove('is-saving');
          if (!result || !result.ok) {
            // Saying nothing would leave the admin believing an order that
            // is not written down anywhere.
            alert((result && result.error) || 'That order could not be saved.');
          }
        })
        .catch(function () {
          list.classList.remove('is-saving');
          alert('That order could not be saved - check your connection and try again.');
        });
    }

    list.addEventListener('pointerup', drop);
    list.addEventListener('pointercancel', drop);
  });

  // --- the updater ---------------------------------------------------------
  //
  // Each step is its own request. The browser asks for the plan first, so
  // pressing the button twice cannot run the same migration twice: the
  // second plan comes back without it.
  (function () {
    var panel = document.querySelector('[data-update]');
    if (!panel) { return; }

    var form    = panel.querySelector('[data-update-form]');
    var button  = panel.querySelector('[data-update-go]');
    var barWrap = panel.querySelector('[data-bar-wrap]');
    var bar     = panel.querySelector('[data-bar]');
    var note    = panel.querySelector('[data-bar-note]');
    var label   = panel.querySelector('[data-bar-label]');
    var count   = panel.querySelector('[data-bar-count]');
    var errBox  = panel.querySelector('[data-update-error]');
    var doneBox = panel.querySelector('[data-update-done]');
    var token   = panel.getAttribute('data-token');

    function post(url, body) {
      body.append('_token', token);
      return fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'fetch' },
        body: body,
        credentials: 'same-origin'
      }).then(function (response) {
        if (!response.ok) { throw new Error('Server returned ' + response.status); }
        return response.json();
      });
    }

    function row(key) {
      return panel.querySelector('.upd-step[data-key="' + key + '"]');
    }

    function progress(done, total, busy) {
      var percent = total ? Math.round((done / total) * 100) : 100;
      bar.style.width = percent + '%';
      bar.classList.toggle('busy', !!busy);
      count.textContent = done + ' / ' + total;
    }

    function fail(message) {
      bar.classList.remove('busy');
      bar.classList.add('bad');
      errBox.textContent = message;
      errBox.hidden = false;
      button.removeAttribute('aria-busy');
      button.innerHTML = 'Try again';
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      button.setAttribute('aria-busy', 'true');
      button.innerHTML = '<span class="spin"></span> ' + (button.getAttribute('data-busy') || 'Updating\u2026');
      errBox.hidden = true;
      barWrap.hidden = false;
      note.hidden = false;
      label.textContent = 'Working out what to do\u2026';

      post(panel.getAttribute('data-plan'), new FormData()).then(function (plan) {
        var steps = plan.steps || [];
        var total = steps.length;
        var index = 0;

        // Rows the plan no longer contains are already done; grey them out
        // rather than leave them looking as if they are waiting their turn.
        panel.querySelectorAll('.upd-step').forEach(function (element) {
          var wanted = steps.some(function (step) {
            return step.key === element.getAttribute('data-key');
          });
          if (!wanted) { element.classList.add('ok'); }
        });

        function next() {
          if (index >= total) {
            progress(total, total, false);
            label.textContent = plan.done || ('Done \u2014 now on version ' + plan.to);
            button.hidden = true;
            doneBox.hidden = false;

            // The version, the banner and the sidebar dot are all rendered
            // server-side, so the page is out of date the moment the last
            // step finishes. Reload it rather than leave the admin looking at
            // a screen that still says an update is waiting.
            var seconds = 3;
            var note = doneBox.querySelector('[data-done-note]');
            var tick = setInterval(function () {
              seconds--;
              if (note) {
                note.textContent = seconds > 0
                  ? 'Reloading in ' + seconds + '\u2026'
                  : 'Reloading\u2026';
              }
              if (seconds <= 0) {
                clearInterval(tick);
                window.location.reload();
              }
            }, 1000);
            return;
          }

          var step = steps[index];
          var element = row(step.key);
          if (element) { element.classList.add('on'); }
          label.textContent = step.label;
          progress(index, total, true);

          var body = new FormData();
          body.append('key', step.key);

          post(panel.getAttribute('data-step'), body).then(function (result) {
            if (element) {
              element.classList.remove('on');
              element.classList.add(result.ok ? 'ok' : 'bad');
              var slot = element.querySelector('[data-note]');
              if (slot) { slot.textContent = result.note || ''; }
            }

            if (!result.ok && !plan.carryOn) {
              fail((result.errors || ['That step failed.']).join(' '));
              return;
            }

            index++;
            // A breath between steps, so a fast database still reads as
            // progress rather than one instant jump.
            setTimeout(next, 180);
          }).catch(function (error) {
            if (element) { element.classList.remove('on'); element.classList.add('bad'); }
            fail(error.message);
          });
        }

        next();
      }).catch(function (error) {
        fail(error.message);
      });
    });
  })();

  // --- chart tooltips ------------------------------------------------------
  //
  // The values are in the page already - every chart folds a table of them
  // underneath - so this is an enhancement, not the only way to read them.
  (function () {
    function show(plot, element) {
      var tip = plot.querySelector('[data-tip]');
      if (!tip) { return; }

      tip.innerHTML = '';
      var value = document.createElement('span');
      value.textContent = element.getAttribute('data-value');
      var label = document.createElement('small');
      label.textContent = element.getAttribute('data-label');
      tip.appendChild(value);
      tip.appendChild(label);
      tip.hidden = false;

      var box  = plot.getBoundingClientRect();
      var mark = element.getBoundingClientRect();
      tip.style.left = (mark.left - box.left + mark.width / 2) + 'px';
      tip.style.top  = (element.hasAttribute('data-cy')
        ? (parseFloat(element.getAttribute('data-cy')) / 200) * box.height
        : mark.top - box.top) + 'px';
    }

    document.addEventListener('mouseover', function (event) {
      var mark = event.target.closest('.ch-hit, .ch-col');
      if (!mark) { return; }
      var plot = mark.closest('.chart-plot');
      if (plot) { show(plot, mark); }
    });

    document.addEventListener('mouseout', function (event) {
      var plot = event.target.closest('.chart-plot');
      if (!plot || plot.contains(event.relatedTarget)) { return; }
      var tip = plot.querySelector('[data-tip]');
      if (tip) { tip.hidden = true; }
    });
  })();

  // --- image fields --------------------------------------------------------
  //
  // The input still does the submitting; this only adds the preview and the
  // drop target. With the script blocked the label opens the picker and the
  // form works exactly as before.
  (function () {
    function bytes(size) {
      return size > 1048576
        ? (size / 1048576).toFixed(1) + ' MB'
        : Math.max(1, Math.round(size / 1024)) + ' KB';
    }

    function show(field, file) {
      var chosen = field.querySelector('[data-chosen]');
      var name   = field.querySelector('[data-chosen-name]');
      var thumb  = field.querySelector('[data-thumb]');
      var title  = field.querySelector('[data-upload-title]');

      if (!file) {
        if (chosen) { chosen.hidden = true; }
        return;
      }

      if (name) { name.textContent = file.name + ' \u00b7 ' + bytes(file.size); }
      if (chosen) { chosen.hidden = false; }
      if (title) { title.textContent = 'Pick a different image'; }

      if (thumb && file.type.indexOf('image/') === 0) {
        var url = URL.createObjectURL(file);
        thumb.innerHTML = '';
        var img = document.createElement('img');
        img.src = url;
        img.alt = '';
        img.onload = function () { URL.revokeObjectURL(url); };
        thumb.appendChild(img);
        thumb.removeAttribute('data-empty');
      }
    }

    document.addEventListener('change', function (event) {
      var input = event.target.closest('[data-upload-input]');
      if (!input) { return; }
      show(input.closest('[data-upload]'), input.files && input.files[0]);
    });

    document.addEventListener('click', function (event) {
      var clear = event.target.closest('[data-upload-clear]');
      if (!clear) { return; }
      var field = clear.closest('[data-upload]');
      var input = field.querySelector('[data-upload-input]');
      input.value = '';
      show(field, null);
    });

    ['dragenter', 'dragover'].forEach(function (type) {
      document.addEventListener(type, function (event) {
        var field = event.target.closest('[data-upload]');
        if (!field) { return; }
        event.preventDefault();
        field.classList.add('is-over');
      });
    });

    ['dragleave', 'drop'].forEach(function (type) {
      document.addEventListener(type, function (event) {
        var field = event.target.closest('[data-upload]');
        if (!field) { return; }
        field.classList.remove('is-over');
      });
    });

    document.addEventListener('drop', function (event) {
      var field = event.target.closest('[data-upload]');
      if (!field || !event.dataTransfer || !event.dataTransfer.files.length) { return; }
      event.preventDefault();

      var input = field.querySelector('[data-upload-input]');
      // DataTransfer is the only way to put a dropped file into a file input.
      try {
        var carrier = new DataTransfer();
        carrier.items.add(event.dataTransfer.files[0]);
        input.files = carrier.files;
        show(field, input.files[0]);
      } catch (error) {
        // An older browser: the picker still works, so say so rather than
        // leaving a drop that silently did nothing.
        input.click();
      }
    });

    // A page-wide guard, so a file dropped anywhere else does not navigate
    // away from a half-filled form.
    ['dragover', 'drop'].forEach(function (type) {
      document.addEventListener(type, function (event) {
        if (!event.target.closest('[data-upload]')) { event.preventDefault(); }
      });
    });
  })();

  // --- searchable selects --------------------------------------------------
  //
  // The real <select> stays in the DOM and stays the thing that submits: this
  // draws a button and a popup over it and writes the choice back. With the
  // script blocked the native control is still there, still styled, still
  // works - which is why the enhancement is built this way round.
  //
  // Every select gets it, so they all look alike; the search box inside
  // appears once there are enough options for scrolling to be a chore, or
  // where the markup asks for it with data-search.
  (function () {
    // Five, not eight: a shop with six providers still wants to type three
    // letters rather than read the list, and the box costs nothing when it
    // is not used.
    var SEARCH_FROM = 5;
    var open = null;

    function labelOf(select) {
      var option = select.options[select.selectedIndex];
      return option ? option.textContent.trim() : '';
    }

    /* A short tag drawn before the text - a service id, say. It is searchable
       as well as visible, because "which service is #1042" is exactly the
       question somebody types into the box. */
    function badgeOf(option) {
      return (option.getAttribute('data-badge') || '').trim();
    }

    function withBadge(target, option, text) {
      target.textContent = '';
      var badge = badgeOf(option);
      if (badge) {
        var tag = document.createElement('span');
        tag.className = 'sel-badge';
        tag.textContent = badge;
        target.appendChild(tag);
      }
      target.appendChild(document.createTextNode(text));
    }

    /* A .box clips what is inside it so its corners stay round, and the
       popup is inside it - which is why the list came out sliced off at the
       card's edge with one row showing. The box is told to let it out while
       it is open, and only while it is open. */
    function liftClipping(wrap, on) {
      var box = wrap.closest('.box, .pad, .filters');
      while (box) {
        box.classList.toggle('is-overflowing', on);
        box = box.parentElement ? box.parentElement.closest('.box, .pad') : null;
      }
    }

    function close() {
      if (!open) { return; }
      liftClipping(open.wrap, false);
      open.wrap.classList.remove('is-open');
      open.button.setAttribute('aria-expanded', 'false');
      open.pop.hidden = true;
      open = null;
    }

    function build(select, searchable) {
      var wrap = document.createElement('div');
      wrap.className = 'sel' + (searchable ? ' sel-searchable' : '');

      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'sel-btn';
      button.setAttribute('aria-haspopup', 'listbox');
      button.setAttribute('aria-expanded', 'false');
      button.innerHTML = '<span class="sel-label"></span>';

      var pop = document.createElement('div');
      pop.className = 'sel-pop';
      pop.hidden = true;
      pop.innerHTML = '<div class="sel-search"><input type="text" placeholder="Search\u2026"'
                    + ' autocomplete="off" spellcheck="false"></div>'
                    + '<ul class="sel-list" role="listbox"></ul>'
                    + '<p class="sel-none" hidden>Nothing matches that.</p>';

      select.parentNode.insertBefore(wrap, select);
      wrap.appendChild(select);
      wrap.appendChild(button);
      wrap.appendChild(pop);
      select.classList.add('sel-native');

      var search = pop.querySelector('input');
      var list   = pop.querySelector('.sel-list');
      var none   = pop.querySelector('.sel-none');

      function paint() {
        var option = select.options[select.selectedIndex];
        var target = button.querySelector('.sel-label');
        if (option) {
          withBadge(target, option, labelOf(select));
        } else {
          target.textContent = '';
        }
      }

      function render(term) {
        var needle = term.toLowerCase();
        var shown  = 0;
        list.innerHTML = '';

        Array.prototype.forEach.call(select.options, function (option, index) {
          var text  = option.textContent.trim();
          var badge = badgeOf(option);
          // Ids are part of what is searched, so typing 1042 finds the service
          // even though no id appears in its name. data-find may carry more
          // than the badge shows - ours and the provider's both.
          var hay = (badge + ' ' + (option.getAttribute('data-find') || '') + ' ' + text)
                      .toLowerCase();
          if (needle && hay.indexOf(needle) === -1) { return; }

          var item = document.createElement('li');
          item.className = 'sel-opt' + (index === select.selectedIndex ? ' is-on' : '');
          item.setAttribute('role', 'option');
          item.setAttribute('aria-selected', index === select.selectedIndex ? 'true' : 'false');
          item.dataset.index = index;
          withBadge(item, option, text);
          list.appendChild(item);
          shown++;
        });

        none.hidden = shown > 0;
      }

      function choose(index) {
        select.selectedIndex = index;
        paint();
        close();
        // Whatever the page already does on change - submit a filter form,
        // repaint a card - still has to happen.
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }

      button.addEventListener('click', function () {
        if (open && open.wrap === wrap) { close(); return; }
        close();
        render('');
        search.value = '';
        pop.hidden = false;
        wrap.classList.add('is-open');
        button.setAttribute('aria-expanded', 'true');
        open = { wrap: wrap, pop: pop, button: button, list: list };
        liftClipping(wrap, true);
        if (searchable) { search.focus(); }
      });

      search.addEventListener('input', function () { render(search.value); });

      search.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { close(); button.focus(); return; }
        if (event.key === 'Enter') {
          event.preventDefault();
          var first = list.querySelector('.sel-opt');
          if (first) { choose(parseInt(first.dataset.index, 10)); button.focus(); }
          return;
        }
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          var item = list.querySelector('.sel-opt');
          if (item) { item.focus ? item.focus() : null; item.classList.add('is-cursor'); }
        }
      });

      list.addEventListener('click', function (event) {
        var item = event.target.closest('.sel-opt');
        if (item) { choose(parseInt(item.dataset.index, 10)); button.focus(); }
      });

      paint();
      // A select changed by something else (the service picker, a reset) has
      // to be reflected on the button.
      select.addEventListener('change', paint);
    }

    function enhance(root) {
      var selects = (root || document).querySelectorAll('select:not(.sel-native)');
      Array.prototype.forEach.call(selects, function (select) {
        if (select.multiple || select.closest('.sel')) { return; }
        build(select, select.hasAttribute('data-search') || select.options.length >= SEARCH_FROM);
      });
    }

    document.addEventListener('click', function (event) {
      if (open && !event.target.closest('.sel')) { close(); }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { close(); }
    });

    enhance(document);
  })();

  // --- tabs ----------------------------------------------------------------
  // The panels are only hidden once the script runs, so a browser that never
  // runs it shows them all rather than showing none.
  (function () {
    var strip = document.querySelector('[data-tabs]');
    if (!strip) { return; }

    var items  = strip.querySelectorAll('[data-tab]');
    var panels = document.querySelectorAll('[data-panel]');

    function select(name, remember) {
      Array.prototype.forEach.call(items, function (item) {
        var on = item.getAttribute('data-tab') === name;
        item.classList.toggle('on', on);
        item.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      Array.prototype.forEach.call(panels, function (panel) {
        panel.hidden = panel.getAttribute('data-panel') !== name;
      });
      if (remember) {
        try { sessionStorage.setItem('admin-tab:' + location.pathname, name); } catch (error) { /* private mode */ }
      }
    }

    strip.addEventListener('click', function (event) {
      var item = event.target.closest('[data-tab]');
      if (!item) { return; }
      event.preventDefault();
      select(item.getAttribute('data-tab'), true);
    });

    // Coming back after a save should land on the tab you were editing.
    var wanted = (location.hash || '').replace('#', '');
    if (!wanted) {
      try { wanted = sessionStorage.getItem('admin-tab:' + location.pathname) || ''; } catch (error) { wanted = ''; }
    }
    var known = Array.prototype.some.call(items, function (item) {
      return item.getAttribute('data-tab') === wanted;
    });
    select(known ? wanted : items[0].getAttribute('data-tab'), false);
  })();

  // --- adding a missing currency from the import screen --------------------
  //
  // Three things have to happen before the catalogue means anything: the
  // currency exists, it has a rate, and the page is re-read with both. The
  // form posts normally without the script; this only puts the three steps
  // on screen instead of a wait.
  (function () {
    var panel = document.querySelector('[data-currency-fix]');
    if (!panel) { return; }

    var form    = panel.querySelector('[data-fix-form]');
    if (!form) { return; }

    var button  = panel.querySelector('[data-fix-go]');
    var barWrap = panel.querySelector('[data-bar-wrap]');
    var bar     = panel.querySelector('[data-bar]');
    var note    = panel.querySelector('[data-bar-note]');
    var label   = panel.querySelector('[data-bar-label]');
    var errBox  = panel.querySelector('[data-fix-error]');
    var code    = panel.getAttribute('data-code');

    function step(percent, text, busy) {
      bar.style.width = percent + '%';
      bar.classList.toggle('busy', !!busy);
      label.textContent = text;
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      button.setAttribute('aria-busy', 'true');
      button.innerHTML = '<span class="spin"></span> Adding ' + code + '\u2026';
      errBox.hidden = true;
      barWrap.hidden = false;
      note.hidden = false;
      step(25, 'Adding ' + code + ' to your currencies\u2026', true);

      var body = new FormData(form);

      fetch(form.action, {
        method: 'POST',
        body: body,
        headers: { 'X-Requested-With': 'fetch' },
        credentials: 'same-origin'
      }).then(function (response) {
        if (!response.ok) { throw new Error('The server answered ' + response.status + '.'); }
        step(65, 'Fetching today\u2019s rate\u2026', true);
        return response.json();
      }).then(function (result) {
        if (!result.ok) { throw new Error(result.message); }
        step(100, result.message + ' Loading the catalogue\u2026', false);
        window.location.reload();
      }).catch(function (error) {
        bar.classList.remove('busy');
        bar.classList.add('bad');
        errBox.textContent = error.message;
        errBox.hidden = false;
        button.removeAttribute('aria-busy');
        button.textContent = 'Try again';
      });
    });
  })();

  // --- the bulk feature-lines box ------------------------------------------
  document.addEventListener('click', function (event) {
    var box = document.querySelector('[data-features]');
    if (!box) { return; }
    if (event.target.closest('[data-features-open]')) { box.hidden = false; box.querySelector('textarea').focus(); }
    if (event.target.closest('[data-features-close]')) { box.hidden = true; }
  });

  // --- the "how this works" note -------------------------------------------
  // Open until it has been read once, then shut on every later visit.
  (function () {
    var notes = document.querySelectorAll('[data-explain]');
    if (!notes.length) { return; }

    Array.prototype.forEach.call(notes, function (note) {
      var key = 'admin-explain:' + note.getAttribute('data-explain');
      var seen;
      try { seen = localStorage.getItem(key); } catch (error) { seen = null; }
      note.open = !seen;

      note.addEventListener('toggle', function () {
        try {
          if (note.open) { localStorage.removeItem(key); } else { localStorage.setItem(key, '1'); }
        } catch (error) { /* private mode: it just opens every time */ }
      });
    });
  })();

  // --- the rich text editor -----------------------------------------------
  //
  // Summernote, loaded only on a screen that has a [data-editor] box, from
  // assets/vendor - so no other screen pays for it and nothing is fetched
  // from a CDN. Until it loads (or if it never does) the box is the plain
  // textarea it always was, and saving works either way: the editor writes
  // its HTML back into that same textarea.
  (function () {
    var boxes = document.querySelectorAll('textarea[data-editor]');
    if (!boxes.length) { return; }

    var root = document.querySelector('meta[name="asset-root"]');
    var base = root ? root.getAttribute('content') : '';
    var token = (document.querySelector('input[name="_token"]') || {}).value || '';
    var upload = document.querySelector('meta[name="editor-upload"]');

    function load(src, done) {
      var tag = document.createElement('script');
      tag.src = src;
      tag.onload = done;
      document.head.appendChild(tag);
    }
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = base + 'assets/vendor/summernote/summernote-lite.min.css';
    document.head.appendChild(css);

    load(base + 'assets/vendor/jquery/jquery.min.js', function () {
      load(base + 'assets/vendor/summernote/summernote-lite.min.js', function () {
        boxes.forEach(function (box) {
          var $box = window.jQuery(box);
          $box.summernote({
            height: parseInt(box.getAttribute('data-height') || '380', 10),
            placeholder: box.getAttribute('placeholder') || '',
            toolbar: [
              ['style', ['style']],
              ['font', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
              ['para', ['ul', 'ol', 'paragraph']],
              ['insert', ['link', 'picture', 'video', 'table', 'hr']],
              ['view', ['codeview', 'fullscreen', 'help']]
            ],
            styleTags: ['p', 'h2', 'h3', 'h4', 'blockquote', 'pre'],
            callbacks: {
              // Pictures are uploaded and linked, never pasted in as base64:
              // a post carrying megabytes of inline image is a slow page and
              // a database row nobody can back up.
              onImageUpload: function (files) {
                if (!upload) { return; }
                Array.prototype.forEach.call(files, function (file) {
                  var body = new FormData();
                  body.append('file', file);
                  body.append('_token', token);
                  fetch(upload.getAttribute('content'), {
                    method: 'POST', body: body, credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'fetch' }
                  }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.ok) {
                      $box.summernote('insertImage', data.url, function ($image) {
                        $image.attr('alt', (file.name || '').replace(/\.[a-z]+$/i, '').replace(/[-_]+/g, ' '));
                        $image.attr('loading', 'lazy');
                        $image.css('max-width', '100%');
                      });
                    } else {
                      window.alert((data && data.error) || 'That image could not be uploaded.');
                    }
                  });
                });
              },
              onChange: function () {
                box.dispatchEvent(new Event('input', { bubbles: true }));
              }
            }
          });
        });
      });
    });
  })();

  // --- the SEO checklist and the search preview ---------------------------
  //
  // Recounted as the post is written, from the same rules the server uses
  // when it draws the list, so the ticks move while you type.
  (function () {
    var form = document.querySelector('[data-seo-form]');
    if (!form) { return; }

    function field(name) { return form.querySelector('[name="' + name + '"]'); }
    function text(name) { var f = field(name); return f ? f.value : ''; }

    function update() {
      var title = text('meta_title') || text('title');
      var desc = text('meta_description');
      var slug = text('slug');
      var preview = document.querySelector('[data-serp]');
      if (preview) {
        preview.querySelector('[data-serp-title]').textContent = title || 'Your title';
        preview.querySelector('[data-serp-url]').textContent = (preview.getAttribute('data-base') || '') + slug;
        preview.querySelector('[data-serp-desc]').textContent = desc || 'Write a meta description - otherwise search engines pick a sentence for you.';
      }
      document.querySelectorAll('[data-len]').forEach(function (counter) {
        var f = field(counter.getAttribute('data-len'));
        var max = parseInt(counter.getAttribute('data-max'), 10);
        var n = f ? f.value.length : 0;
        counter.textContent = n + ' / ' + max;
        counter.classList.toggle('warn', n > max);
      });
    }
    form.addEventListener('input', update);
    update();

    // A slug follows the title until somebody types one of their own.
    var title = field('title');
    var slug = field('slug');
    if (title && slug) {
      var touched = slug.value !== '';
      slug.addEventListener('input', function () { touched = slug.value !== ''; });
      title.addEventListener('input', function () {
        if (touched) { return; }
        slug.value = title.value.toLowerCase().normalize('NFKD').replace(/[^\w\s-]/g, '')
          .trim().replace(/[\s_-]+/g, '-').slice(0, 90);
        update();
      });
    }
  })();

  // --- ticking rows for a bulk action --------------------------------------
  //
  // The row boxes belong to the bulk form through their form= attribute, so
  // they post with it from wherever they sit in the table. The bar only
  // shows while something is ticked.
  (function () {
    var form = document.querySelector('[data-bulk]');
    if (!form) { return; }
    var bar = form.querySelector('[data-bulk-bar]');
    var count = form.querySelector('[data-bulk-count]');
    var all = document.querySelector('[data-bulk-all]');
    function rows() { return Array.prototype.slice.call(document.querySelectorAll('[data-bulk-row]')); }
    function sync() {
      var ticked = rows().filter(function (box) { return box.checked; }).length;
      bar.classList.toggle('on', ticked > 0);
      count.textContent = ticked;
      if (all) { all.checked = ticked > 0 && ticked === rows().length; }
    }
    document.addEventListener('change', function (event) {
      if (event.target === all) {
        rows().forEach(function (box) { box.checked = all.checked; });
      }
      if (event.target === all || event.target.matches('[data-bulk-row]')) { sync(); }
    });
    sync();
  })();
})();
