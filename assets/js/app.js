/* ==================================================================
   TillPoint - client-side behaviour
   Progressive only. Every page works with JavaScript switched off: the
   till posts ordinary forms and the server does the arithmetic. What is
   here makes the till quick to use, and nothing here is a control -
   a limit enforced in the browser is not enforced.
   ================================================================== */
(function () {
  'use strict';

  /* ---- Confirmations -------------------------------------------- */
  // Anything that cannot be undone asks first. The message comes from the
  // markup so the page can say what specifically is about to happen.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var ask = form.getAttribute('data-confirm');
    if (ask && !window.confirm(ask)) {
      e.preventDefault();
      return;
    }
    // Stop a double-submitted sale or receipt. The guard is released after a
    // moment in case the request failed and the user needs to retry.
    var btn = form.querySelector('button[type=submit], input[type=submit]');
    if (btn && !form.hasAttribute('data-no-guard')) {
      btn.setAttribute('aria-disabled', 'true');
      var label = btn.textContent;
      btn.textContent = 'Working…';
      window.setTimeout(function () {
        btn.removeAttribute('aria-disabled');
        btn.textContent = label;
      }, 4000);
    }
  });

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm-link]');
    if (el && !window.confirm(el.getAttribute('data-confirm-link'))) {
      e.preventDefault();
    }
  });

  /* ---- Autofocus ------------------------------------------------ */
  var focusMe = document.querySelector('[data-autofocus]');
  if (focusMe) {
    focusMe.focus();
    if (focusMe.select) { focusMe.select(); }
  }

  /* ---- Till: product lookup ------------------------------------- */
  // A scanner types the barcode and presses Enter, which submits the form -
  // that path needs no JavaScript at all. This adds a live list for the
  // times there is no barcode to scan: loose produce, a torn label.
  var search = document.querySelector('[data-lookup]');
  var results = document.getElementById('lookup-results');

  if (search && results) {
    var timer = null;
    var lastTerm = '';

    search.addEventListener('input', function () {
      var term = search.value.trim();
      window.clearTimeout(timer);

      // A barcode is all digits and arrives in one burst from the scanner;
      // searching on it would fire a request per keystroke for nothing.
      if (term.length < 2 || /^\d{6,}$/.test(term)) {
        results.innerHTML = '';
        return;
      }
      if (term === lastTerm) { return; }

      timer = window.setTimeout(function () {
        lastTerm = term;
        fetch(results.getAttribute('data-url') + '?q=' + encodeURIComponent(term), {
          headers: { 'Accept': 'application/json' },
          credentials: 'same-origin'
        })
          .then(function (r) { return r.ok ? r.json() : { items: [] }; })
          .then(function (data) { renderLookup(data.items || []); })
          .catch(function () { results.innerHTML = ''; });
      }, 180);
    });

    function renderLookup(items) {
      if (!items.length) {
        results.innerHTML = '<div class="lookup__row muted">Nothing matched.</div>';
        return;
      }
      var html = '';
      for (var i = 0; i < items.length; i++) {
        var it = items[i];
        html += '<button type="button" class="lookup__row" data-pick="' + esc(it.code) + '">'
              + '<span class="nm">' + esc(it.name) + '</span>'
              + '<span class="st">' + esc(it.stock) + '</span>'
              + '<span class="pr">' + esc(it.price) + '</span>'
              + '</button>';
      }
      results.innerHTML = html;
    }

    results.addEventListener('click', function (e) {
      var row = e.target.closest('[data-pick]');
      if (!row) { return; }
      search.value = row.getAttribute('data-pick');
      results.innerHTML = '';
      // Submitting puts the item straight in the basket, which is what the
      // operator wanted when they clicked it.
      var form = search.closest('form');
      if (form) { form.requestSubmit ? form.requestSubmit() : form.submit(); }
    });
  }

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* ---- Till: change due ----------------------------------------- */
  // Shown as the cashier types, so they can count the change out while the
  // customer is still handing over notes. The server recomputes it; this is
  // only the display.
  var payForm = document.querySelector('[data-pay-form]');
  if (payForm) {
    var dueEl = document.getElementById('change-due');
    var totalDue = parseFloat(payForm.getAttribute('data-total') || '0');

    var recalc = function () {
      var paid = 0;
      payForm.querySelectorAll('[data-amount]').forEach(function (input) {
        var v = parseFloat(String(input.value).replace(/,/g, ''));
        if (!isNaN(v)) { paid += v; }
      });
      var change = paid - totalDue;
      if (dueEl) {
        dueEl.textContent = change >= 0 ? fmt(change) : '(' + fmt(Math.abs(change)) + ' short)';
        dueEl.className = change >= 0 ? 'ok-text' : 'bad-text';
      }
    };
    payForm.addEventListener('input', recalc);

    // "Exact" fills the cash field with the total - the commonest card or
    // transfer case, where there is never change.
    payForm.querySelectorAll('[data-exact]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = payForm.querySelector(btn.getAttribute('data-exact'));
        if (target) {
          target.value = totalDue.toFixed(2);
          recalc();
          target.focus();
        }
      });
    });

    // Note buttons add a denomination to whatever is already tendered, the
    // way a cashier actually takes money: 1000, then another 500.
    payForm.querySelectorAll('[data-note]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = payForm.querySelector(btn.getAttribute('data-note-target') || '[data-amount]');
        if (!target) { return; }
        var current = parseFloat(String(target.value).replace(/,/g, '')) || 0;
        target.value = (current + parseFloat(btn.getAttribute('data-note'))).toFixed(2);
        recalc();
      });
    });

    recalc();
  }

  function fmt(n) {
    return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /* ---- Count sheets: live variance ------------------------------ */
  // On a stock count the variance is what the counter is actually watching
  // for, so it updates in the row as they type rather than after a save.
  document.querySelectorAll('[data-counted]').forEach(function (input) {
    input.addEventListener('input', function () {
      var row = input.closest('tr');
      if (!row) { return; }
      var system = parseFloat(row.getAttribute('data-system') || '0');
      var cost = parseFloat(row.getAttribute('data-cost') || '0');
      var counted = parseFloat(String(input.value).replace(/,/g, ''));
      var qtyCell = row.querySelector('[data-var-qty]');
      var valCell = row.querySelector('[data-var-val]');

      if (isNaN(counted)) {
        if (qtyCell) { qtyCell.textContent = '–'; qtyCell.className = 'num'; }
        if (valCell) { valCell.textContent = '–'; valCell.className = 'num'; }
        return;
      }
      var diff = counted - system;
      if (qtyCell) {
        qtyCell.textContent = (diff > 0 ? '+' : '') + trimQty(diff);
        qtyCell.className = diff < 0 ? 'num neg' : 'num';
      }
      if (valCell) {
        valCell.textContent = (diff > 0 ? '+' : '') + fmt(diff * cost);
        valCell.className = diff < 0 ? 'num neg' : 'num';
      }
    });
  });

  function trimQty(n) {
    return Math.abs(n - Math.round(n)) < 0.0005 ? String(Math.round(n)) : n.toFixed(3);
  }

  /* ---- Select-all checkboxes ------------------------------------ */
  document.querySelectorAll('[data-check-all]').forEach(function (master) {
    master.addEventListener('change', function () {
      var scope = master.closest('form') || document;
      scope.querySelectorAll(master.getAttribute('data-check-all')).forEach(function (box) {
        box.checked = master.checked;
      });
    });
  });

  /* ---- Print ---------------------------------------------------- */
  // A receipt page opens straight into the print dialog, because that is the
  // only reason it was opened.
  if (document.body.hasAttribute('data-print-now')) {
    window.setTimeout(function () { window.print(); }, 250);
  }
  document.querySelectorAll('[data-print]').forEach(function (btn) {
    btn.addEventListener('click', function (e) { e.preventDefault(); window.print(); });
  });
}());
