/* ==========================================================================
   FlatMate  |  Expenses
   --------------------------------------------------------------------------
   Ledger with filters + paging, the settle-up board, and the category
   breakdown.

   Money is in integer cents everywhere on the wire. Amounts typed by the user
   are decimal strings; the server converts with Money::toCents(). The client
   previews an equal split purely for reassurance — the server always
   recomputes the shares, so a tampered preview changes nothing.
   ========================================================================== */

(() => {
  'use strict';

  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const PAGE_SIZE = 25;

  let me        = null;
  let roster    = [];
  let categories = [];
  let page      = 0;
  let total     = 0;
  let settleFor = null;      // the transfer currently being recorded

  /* ------------------------------------------------------------------ */
  /*  Delete permission                                                   */
  /* ------------------------------------------------------------------ */

  // Mirrors ExpenseService::delete(): an admin, the person who paid, or the
  // person who logged the expense may all remove it. Hiding the button from
  // admins and creators made deletion look broken even though the API allows it.
  function canDelete(expense) {
    if (me?.role === 'admin') return true;
    return Number(expense.paid_by_user_id) === me?.id
        || Number(expense.created_by) === me?.id;
  }

  /* ------------------------------------------------------------------ */
  /*  Filters                                                            */
  /* ------------------------------------------------------------------ */

  function filters() {
    const scope = $('[data-filter-scope]')?.value || '';
    const f = {
      q:            $('[data-filter-q]')?.value.trim() || '',
      category_id:  $('[data-filter-category]')?.value || '',
      split_type:   $('[data-filter-split]')?.value || '',
    };

    // Both scopes are server-side. user_id means "paid OR shared in";
    // payer_id means "paid by me" specifically. Filtering the latter in the
    // browser would narrow an already-paged result set, so the page and the
    // total would disagree.
    if (scope === 'mine') {
      f.user_id = me.id;
    } else if (scope === 'paid') {
      f.payer_id = me.id;
    }
    return f;
  }

  /* ------------------------------------------------------------------ */
  /*  Ledger                                                             */
  /* ------------------------------------------------------------------ */

  function expenseRow(e) {
    const mine = e.is_mine;
    return `
      <div class="fm-expense ${mine ? 'mine' : ''} ${e.is_disputed ? 'disputed' : ''}" data-open="${e.id}">
        <span class="fm-expense-icon"><i class="bi ${esc(e.category_icon || 'bi-question-circle')}"></i></span>

        <div class="fm-expense-main">
          <div class="fm-expense-title">
            ${esc(e.title)}
            ${e.is_meal_related ? '<span class="fm-pill muted">meal</span>' : ''}
            ${e.is_disputed ? '<span class="fm-pill bad">disputed</span>' : ''}
          </div>
          <div class="fm-expense-meta">
            ${e.category_name ? esc(e.category_name) + ' &middot; ' : ''}
            paid by ${esc(e.paid_by_name || 'someone')}
            &middot; ${esc(Fmt.date(e.expense_date))}
            &middot; ${esc(e.split_label)}
            ${e.reference_no ? ` &middot; ${esc(e.reference_no)}` : ''}
          </div>
        </div>

        <div class="fm-expense-end">
          <div class="fm-expense-amount">${esc(Fmt.money(Math.round(Number(e.amount) * 100)))}</div>
          <div class="fm-expense-share ${mine ? 'mine' : ''}">
            ${mine
              ? `you owe ${esc(Fmt.money(Math.round(Number(e.my_share) * 100)))}`
              : 'not in your split'}
          </div>
        </div>
      </div>`;
  }

  async function loadLedger() {
    const host = $('[data-ledger]');
    host.innerHTML = '<div class="fm-card p-3"><div class="fm-skeleton" style="height:9rem"></div></div>';

    const f = filters();
    const rows = await App.guard(() => API.get('expense.list', {
      ...f,
      limit: PAGE_SIZE,
      offset: page * PAGE_SIZE,
    })) || [];

    const count = await App.guard(() => API.get('expense.count', f)) || {};
    total = count.total ?? rows.length;

    const html = rows.map(expenseRow).filter(Boolean).join('');

    host.innerHTML = html
      ? `<div class="fm-card">${html}</div>`
      : `<div class="fm-card"><div class="fm-empty">
           <i class="bi bi-receipt"></i><h3>Nothing here</h3>
           <p>No expenses match these filters yet.</p>
         </div></div>`;

    const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    $('[data-page-label]').textContent = total === 0
      ? 'no results'
      : `Showing ${(page * PAGE_SIZE) + 1}–${Math.min((page + 1) * PAGE_SIZE, total)} of ${total}`;
    $('[data-page="-1"]').disabled = page <= 0;
    $('[data-page="1"]').disabled = page >= pages - 1;
  }

  async function openExpense(id) {
    const e = await App.guard(() => API.get('expense.find', { id }));
    if (!e || !e.id) return;

    $('[data-detail-title]').textContent = e.title;
    $('[data-detail-body]').innerHTML = `
      <div class="d-flex align-items-start gap-3 mb-3">
        <span class="fm-expense-icon"><i class="bi ${esc(e.category_icon || 'bi-question-circle')}"></i></span>
        <div>
          <div class="fm-expense-amount" style="font-size:1.3rem">
            ${esc(Fmt.money(Math.round(Number(e.amount) * 100)))}
          </div>
          <div class="text-faint" style="font-size:.8rem">
            ${esc(e.category_name || 'Uncategorised')} &middot; ${esc(e.reference_no || 'no reference')}
            &middot; ${esc(Fmt.date(e.expense_date))}
          </div>
        </div>
      </div>

      ${e.description ? `<p class="text-muted-2">${esc(e.description)}</p>` : ''}

      <p class="fm-section-title">Split ${esc(e.split_label)}</p>
      ${(e.splits || []).map((s) => `
        <div class="fm-resident">
          <span class="fm-avatar sm" style="background:${esc(s.avatar_color || '#64748b')}">
            ${esc(Fmt.initials(s.full_name))}
          </span>
          <div class="fm-resident-main">
            <div class="fm-resident-name">${esc(s.full_name)}</div>
            <div class="fm-resident-meta">${esc(s.participant_code || '')}</div>
          </div>
          <div class="text-end">
            <div style="font-weight:600">${esc(Fmt.money(Math.round(Number(s.share_amount) * 100)))}</div>
            ${s.is_settled ? '<span class="fm-pill ok">settled</span>'
                           : '<span class="fm-pill warn">open</span>'}
          </div>
        </div>`).join('')}

      <div class="fm-card-foot mt-3">
        <div class="d-flex justify-content-between align-items-center">
          <span class="text-faint" style="font-size:.78rem">
            Added by ${esc(e.created_by_name || e.paid_by_name)}
          </span>
          ${canDelete(e)
            ? `<button class="btn btn-sm btn-outline-danger" data-delete="${e.id}"
                       data-confirm-title="Delete this expense?"
                       data-confirm-text="It will disappear from everyone's balances. This cannot be undone."
                       data-confirm-ok="Delete" data-confirm-danger="1">Delete</button>`
            : ''}
        </div>
      </div>`;

    App.openModal($('#expenseDetail'));
  }

  /* ------------------------------------------------------------------ */
  /*  Settle up                                                          */
  /* ------------------------------------------------------------------ */

  function transferRow(t) {
    return `
      <div class="fm-resident">
        <span class="fm-avatar sm" style="background:${esc(t.from_avatar || '#64748b')}">
          ${esc(Fmt.initials(t.from_name))}
        </span>
        <div class="fm-resident-main">
          <div class="fm-resident-name">${esc(t.from_name)}</div>
          <div class="fm-resident-meta">pays ${esc(t.to_name)}</div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="fw-semibold">${esc(Fmt.money(t.amount_cents))}</span>
          <button class="btn btn-sm btn-primary" data-settle-from="${t.from_user_id}"
                  data-settle-to="${t.to_user_id}" data-settle-amount="${t.amount_cents}"
                  data-settle-from-name="${esc(t.from_name)}"
                  data-settle-to-name="${esc(t.to_name)}">
            Settle
          </button>
        </div>
      </div>`;
  }

  async function loadLedgerBoard() {
    const strategy = $('[data-strategy].active')?.dataset.strategy || 'auto';

    const ledger = await App.guard(() => API.get('balance.ledger', { strategy }));
    if (!ledger) return;

    const transfers = ledger.transfers || [];
    $('[data-plan-count]').innerHTML = transfers.length
      ? `<span class="fm-pill ok">${transfers.length} payment${transfers.length === 1 ? '' : 's'} to settle</span>`
      : '<span class="fm-pill ok">everyone is square</span>';

    $('[data-settle]').innerHTML = transfers.length
      ? `<div>${transfers.map(transferRow).join('')}</div>`
      : `<div class="fm-empty"><i class="bi bi-check2-circle"></i>
           <h3>All square</h3><p>Nobody owes anybody anything right now.</p></div>`;

    // Balances panel
    const balances = ledger.balances || [];
    const maxAbs = Math.max(...balances.map((b) => Math.abs(b.net_cents)), 1);
    $('[data-balances]').innerHTML = balances.map((b) => {
      const pct = Math.round((Math.abs(b.net_cents) / maxAbs) * 100);
      const kind = b.direction === 'credit' ? 'credit' : (b.direction === 'debit' ? 'debit' : 'flat');
      return `
        <div class="fm-fair-row">
          <span class="fm-avatar sm">${esc(Fmt.initials(b.full_name))}</span>
          <div class="fm-fair-name fm-truncate">${esc(b.full_name)}${b.user_id === me.id ? ' (you)' : ''}</div>
          <div class="fm-fair-track">
            <div class="fm-fair-fill ${kind === 'debit' ? 'over' : (kind === 'credit' ? 'me' : '')}"
                 style="width:${pct}%"></div>
          </div>
          <div class="fm-fair-val ${kind}">
            ${b.net_cents === 0 ? '—' : esc(Fmt.money(b.net_cents, { sign: true }))}
          </div>
        </div>`;
    }).join('');

    // History
    const history = await App.guard(() => API.get('balance.settlements', { limit: 15 })) || [];
    $('[data-settlements]').innerHTML = history.length
      ? `<div>${history.map((s) => `
          <div class="fm-resident">
            <span class="fm-avatar sm"><i class="bi bi-arrow-left-right"></i></span>
            <div class="fm-resident-main">
              <div class="fm-resident-name">${esc(s.label || `${s.from_name} → ${s.to_name}`)}</div>
              <div class="fm-resident-meta">
                ${esc(String(s.method || 'cash'))} &middot; ${esc(Fmt.date(String(s.settled_at || '').slice(0, 10)))}
                ${s.note ? ` &middot; ${esc(s.note)}` : ''}
              </div>
            </div>
          </div>`).join('')}</div>`
      : '<p class="text-muted-2 mb-0" style="font-size:.85rem">No payments recorded yet.</p>';
  }

  async function loadBreakdown() {
    const month = $('[data-breakdown-month]')?.value || '';
    const rows = await App.guard(() => API.get('balance.categories', month ? { month } : {})) || [];

    $('[data-breakdown]').innerHTML = rows.length
      ? `<div class="fm-card-body">
          ${rows.map((r) => `
            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span style="font-size:.9rem">
                  <i class="bi ${esc(r.icon)} text-secondary"></i> ${esc(r.category)}
                  <span class="text-faint">(${r.count})</span>
                </span>
                <span class="fw-semibold">${esc(Fmt.money(Math.round(Number(r.amount) * 100)))}</span>
              </div>
              <div class="fm-fair-track">
                <div class="fm-fair-fill" style="width:${Math.min(100, r.percent)}%"></div>
              </div>
            </div>`).join('')}
         </div>`
      : '<p class="text-muted-2 mb-0 p-3" style="font-size:.85rem">No expenses to break down yet.</p>';
  }

  /* ------------------------------------------------------------------ */
  /*  Add-expense form                                                   */
  /* ------------------------------------------------------------------ */

  const pickerHtml = (selected) => roster.map((u) => `
      <button type="button" class="btn btn-sm ${selected ? 'btn-primary' : 'btn-outline-secondary'}"
              data-pick="${u.id}">
        <span class="fm-avatar sm" style="background:${esc(u.avatar_color || '#64748b')}">
          ${esc(Fmt.initials(u.full_name))}
        </span> ${esc(u.full_name.split(' ')[0])}
      </button>`).join('');

  /** Live preview, purely informational — the server always recomputes. */
  function previewSplit() {
    const mode = $('[name="split_type"]').value;
    const cents = Math.round(parseFloat($('[name="amount"]').value || '0') * 100);
    const out = $('[data-share-preview]');
    if (!out) return;
    if (!cents) { out.textContent = ''; return; }

    if (mode === 'equal') {
      const heads = roster.length || 1;
      const each = Math.floor(cents / heads);
      const odd = cents - (each * heads);
      out.textContent = `${Fmt.money(each)} each across ${heads} people`
        + (odd ? ` (${Fmt.money(odd)} absorbed)` : '');
    } else if (mode === 'selective') {
      const n = $$('[data-picker] [data-pick].btn-primary').length;
      out.textContent = n
        ? `${Fmt.money(Math.floor(cents / n))} each across ${n} people`
        : 'Pick who is in';
    } else if (mode === 'shares') {
      const total = $$('[data-weight]').reduce((s, i) => s + (Number(i.value) || 0), 0);
      out.textContent = total ? 'Weighted by the shares on the left' : 'Set at least one share';
    } else if (mode === 'meal_based') {
      const scope = $('[name="meal_scope"]')?.value || 'current_week';
      const n = scope === 'explicit'
        ? $$('[data-eater-picker] [data-pick].btn-primary').length
        : null;
      if (scope === 'explicit') {
        out.textContent = n
          ? `${Fmt.money(Math.floor(cents / n))} each across ${n} eaters`
          : 'Pick who ate, or switch to this week’s opt-ins';
      } else {
        out.textContent = 'Splitting between this week’s opt-in eaters';
      }
    }
  }

  async function initForm() {
    const form = $('[data-form="expense"]');
    if (!form) return;

    const rosterData = await App.guard(() => API.get('resident.roster')) || {};
    roster = rosterData.residents || [];
    categories = await App.guard(() => API.get('expense.categories')) || [];

    // Pickers for selective and explicit meal-based splits
    $('[data-picker]', form).innerHTML = pickerHtml(false);
    $('[data-eater-picker]', form).innerHTML = pickerHtml(true);

    // Weights for share splits
    $('[data-weights]', form).innerHTML = roster.map((u) => `
      <span class="d-inline-flex align-items-center gap-1" style="font-size:.78rem">
        <span class="fm-avatar sm" style="background:${esc(u.avatar_color || '#64748b')}">
          ${esc(Fmt.initials(u.full_name))}
        </span>
        <input class="form-control form-control-sm" style="width:3.5rem" type="number"
               min="0" max="20" value="1" data-weight="${u.id}">
      </span>`).join('');

// Payer + category pickers
    $('[data-payers]', form).innerHTML = roster.map((u) =>
      `<option value="${u.id}" ${u.id === me.id ? 'selected' : ''}>${esc(u.full_name)}</option>`).join('');

    $('[data-categories]', form).innerHTML = '<option value="">Uncategorised</option>'
      + categories.map((c) =>
        `<option value="${c.id}">${esc(c.icon ? c.icon + ' ' : '')}${esc(c.name)}</option>`).join('');

    // Also feed the ledger's category filter
    $('[data-filter-category]').innerHTML = '<option value="">All categories</option>'
      + categories.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('');

    // Show/hide the dependent blocks for the chosen split rule.
    const syncBlocks = () => {
      const mode = $('[name="split_type"]').value;
      $$('[data-when]', form).forEach((b) =>
        b.classList.toggle('d-none', b.dataset.when !== mode));
      // The explicit eater list only matters for meal_scope = explicit.
      const eaters = $('[data-eater-picker]');
      if (eaters) {
        eaters.classList.toggle('d-none', $('[name="meal_scope"]').value !== 'explicit');
      }
      previewSplit();
    };

    form.addEventListener('change', (ev) => {
      if (ev.target.name === 'split_type') {
        // Default "selective" to everyone in, which is what people expect.
        if (ev.target.value === 'selective') {
          $$('[data-picker] [data-pick]', form).forEach((b) => b.classList.add('btn-primary'));
        }
        syncBlocks();
        return;
      }
      if (ev.target.name === 'meal_scope') { syncBlocks(); return; }
      if (ev.target.name === 'amount') previewSplit();
    });

    form.addEventListener('input', (ev) => {
      if (ev.target.name === 'amount' || ev.target.hasAttribute('data-weight')) previewSplit();
    });

    on(form, 'click', '[data-pick]', (ev, el) => {
      el.classList.toggle('btn-primary');
      el.classList.toggle('btn-outline-secondary');
      previewSplit();
    });

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      App.clearErrors(form);

      const payload = Object.fromEntries(new FormData(form).entries());
      const mode = payload.split_type;

      if (mode === 'selective') {
        // ExpenseService::resolveSplit() reads `user_ids`.
        payload.user_ids = $$('[data-picker] [data-pick].btn-primary', form)
          .map((b) => Number(b.dataset.pick));
      } else if (mode === 'shares') {
        const weights = {};
        $$('[data-weight]', form).forEach((i) => {
          const w = Number(i.value);
          if (w > 0) weights[i.dataset.weight] = w;
        });
        payload.weights = weights;
      } else if (mode === 'meal_based') {
        if ((payload.meal_scope || 'current_week') === 'explicit') {
          payload.user_ids = $$('[data-eater-picker] [data-pick].btn-primary', form)
            .map((b) => Number(b.dataset.pick));
        }
      }

      const submit = form.querySelector('[type="submit"]');
      submit.disabled = true;
      await App.guard(async () => {
        await API.expenseCreate(payload);
        App.toast('Expense saved.', 'ok');
        form.reset();
        $('[name="meal_scope"]').value = 'current_week';
        syncBlocks();
        App.closeModal($('#newExpense'));
        page = 0;
        await Promise.all([loadLedger(), loadLedgerBoard(), loadBreakdown()]);
      }, {
        onError: (err) => {
          if (err instanceof API.ApiError && err.code === 'validation_failed') {
            App.showErrors(form, err.details);
          }
        },
      });
      submit.disabled = false;
    });
  }

  /* ------------------------------------------------------------------ */
  /*  Settle form                                                        */
  /* ------------------------------------------------------------------ */

  function initSettleForm() {
    const form = $('[data-form="settle"]');
    if (!form) return;

    // form.elements, not form.<name>: `method` is a native HTMLFormElement
    // property, so form.method is the string "get" rather than the select.
    const field = (name) => form.elements.namedItem(name)?.value ?? '';

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!settleFor) return;
      App.clearErrors(form);

      const submit = form.querySelector('[type="submit"]');
      submit.disabled = true;
      await App.guard(async () => {
        await API.settle({
          from_user_id: settleFor.from_user_id,
          to_user_id: settleFor.to_user_id,
          amount: (Number(settleFor.amount_cents) / 100).toFixed(2),
          method: field('method') || 'cash',
          reference: field('reference') || null,
          note: field('note') || null,
          settled_at: field('settled_at') || null,
        });
        App.toast('Payment recorded.', 'ok');
        form.reset();
        App.closeModal($('#settleModal'));
        settleFor = null;
        await Promise.all([loadLedgerBoard(), loadLedger(), loadBreakdown()]);
      }, {
        onError: (err) => {
          if (err instanceof API.ApiError && err.code === 'validation_failed') {
            App.showErrors(form, err.details);
          }
        },
      });
      submit.disabled = false;
    });
  }

  /* ------------------------------------------------------------------ */
  /*  Wiring                                                             */
  /* ------------------------------------------------------------------ */

  function wire() {
    // Filters (debounced on the text box)
    let timer = null;
    $('[data-filter-q]')?.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => { page = 0; loadLedger(); }, 300);
    });
    ['[data-filter-category]', '[data-filter-split]', '[data-filter-scope]'].forEach((sel) => {
      $(sel)?.addEventListener('change', () => { page = 0; loadLedger(); });
    });

    // Paging
    $$('[data-page]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const next = page + Number(btn.dataset.page);
        if (next < 0) return;
        page = next;
        loadLedger();
      });
    });

    // Open an expense
    on(document, 'click', '[data-open]', (ev, el) => {
      if (ev.target.closest('[data-confirm-text]')) return;
      openExpense(Number(el.dataset.open));
    });

    // Delete an expense
    on(document, 'click', '[data-delete]', async (ev, el) => {
      await App.guard(async () => {
        await API.post('expense.delete', { id: Number(el.dataset.delete) });
        App.toast('Expense deleted.', 'ok');
        App.closeModal($('#expenseDetail'));
        await Promise.all([loadLedger(), loadLedgerBoard(), loadBreakdown()]);
      });
    });

    // Start a settlement
    on(document, 'click', '[data-settle-from]', (ev, el) => {
      settleFor = {
        from_user_id: Number(el.dataset.settleFrom),
        to_user_id: Number(el.dataset.settleTo),
        amount_cents: Number(el.dataset.settleAmount),
        from_name: el.dataset.settleFromName || '',
        to_name: el.dataset.settleToName || '',
      };
      const form = $('[data-form="settle"]');
      $('[data-settle-summary]').textContent =
        `Recording a payment of ${Fmt.money(settleFor.amount_cents)} from `
        + `${settleFor.from_name} to ${settleFor.to_name}.`;
      form.elements.namedItem('amount').value = (settleFor.amount_cents / 100).toFixed(2);
      App.openModal($('#settleModal'));
    });

    // Strategy buttons
    $$('[data-strategy]').forEach((btn) => {
      btn.addEventListener('click', () => {
        $$('[data-strategy]').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        loadLedgerBoard();
      });
    });

    // Breakdown month
    $('[data-breakdown-month]')?.addEventListener('change', loadBreakdown);

    // Lazily load each tab.
    $$('[data-bs-toggle="pill"]').forEach((tab) => {
      tab.addEventListener('shown.bs.tab', () => {
        if (tab.dataset.bsTarget === '#tab-settle') loadLedgerBoard();
        if (tab.dataset.bsTarget === '#tab-categories') loadBreakdown();
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /*  Month options for the breakdown filter                              */
  /* ------------------------------------------------------------------ */

  function initMonthPicker() {
    const sel = $('[data-breakdown-month]');
    if (!sel) return;
    const now = new Date();
    const opts = [];
    for (let i = 0; i < 12; i++) {
      const d = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - i, 1));
      opts.push(`<option value="${d.toISOString().slice(0, 7)}">
        ${d.toLocaleDateString('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' })}
      </option>`);
    }
    sel.insertAdjacentHTML('beforeend', opts.join(''));
  }

  document.addEventListener('DOMContentLoaded', async () => {
    me = await App.guard(() => API.me());
    if (!me) return;

    initMonthPicker();
    wire();
    initSettleForm();
    await initForm();
    await loadLedger();
  });
})();