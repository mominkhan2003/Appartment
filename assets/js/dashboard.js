/* ==========================================================================
   FlatMate  |  Dashboard
   --------------------------------------------------------------------------
   Renders the home screen from a single `dashboard` API call.
   ========================================================================== */

(() => {
  'use strict';

  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  let me = null;
  let data = null;

  /* ------------------------------------------------------------------ */
  /*  Renderers                                                          */
  /* ------------------------------------------------------------------ */

  function renderHead(d) {
    $('[data-greeting]').textContent = d.greeting || 'Hello';
    $('[data-today]').textContent = Fmt.dateLong(d.today);
    document.title = `${d.greeting} · FlatMate`;
  }

  function renderBalanceStats(d) {
    // statementFor() -> { member, outgoing, incoming, paid_expenses, owed_expenses, ... }
    const stmt = d.money?.me || {};
    const net = stmt.member?.net_cents ?? 0;
    const outgoing = stmt.outgoing || [];
    const incoming = stmt.incoming || [];

    const tiles = [
      {
        label: 'You are owed',
        value: net > 0 ? Fmt.money(net) : '—',
        cls: net > 0 ? 'owing' : 'flat',
        hint: net > 0
          ? `${Fmt.plural(incoming.length, 'person')} owes you`
          : 'Nobody owes you right now',
      },
      {
        label: 'You owe',
        value: net < 0 ? Fmt.money(-net) : '—',
        cls: net < 0 ? 'owed' : 'flat',
        hint: net < 0
          ? `Across ${Fmt.plural(outgoing.length, 'person')}`
          : 'You are all square',
      },
      {
        label: 'Flat total this month',
        value: Fmt.moneyShort(d.money?.summary?.total_spend_cents ?? 0),
        cls: '',
        hint: `${Fmt.plural(d.money?.summary?.expense_count ?? 0, 'expense')}`,
      },
    ];

    $('[data-balance-stats]').innerHTML = tiles.map((t) => `
      <div class="fm-card fm-stat">
        <div class="fm-stat-label">${esc(t.label)}</div>
        <div class="fm-stat-value ${t.cls}">${esc(t.value)}</div>
        <div class="fm-stat-hint">${esc(t.hint)}</div>
      </div>`).join('');
  }

  function choreRow(task, { today = false } = {}) {
    const mine = task.assigned_user_id === me.id;
    const cls  = [
      'fm-chore',
      mine ? 'mine' : 'other',
      task.status === 'pending' && task.is_past ? 'past' : '',
    ].join(' ');

    const statusIcon = { done: 'bi-check-lg', verified: 'bi-patch-check-fill', skipped: 'bi-dash-lg' }[task.status];
    const statusCls  = { done: 'done', verified: 'done', skipped: 'skipped' }[task.status] || '';

    return `
      <div class="${cls}" data-task="${task.id}">
        <span class="fm-chore-icon ${statusCls}"><i class="bi ${esc(task.icon || 'bi-stars')}"></i></span>
        <div class="fm-chore-main">
          <div class="fm-chore-title">
            ${esc(task.area_name)}
            ${task.status !== 'pending' ? `<i class="bi ${statusIcon} text-success"></i>` : ''}
            ${task.is_mandatory ? '' : '<span class="fm-pill muted">optional</span>'}
          </div>
          <div class="fm-chore-meta">
            ${mine ? 'Yours' : esc(task.assignee_name || 'Unassigned')}
            ${today ? '' : ' &middot; ' + Fmt.date(task.task_date, { weekday: 'short', day: 'numeric', month: 'short' })}
          </div>
        </div>
        <div class="fm-chore-end">
          ${task.status === 'pending' && mine
            ? `<button class="btn btn-sm btn-primary" data-complete="${task.id}">Done</button>`
            : ''}
        </div>
      </div>`;
  }

  function renderChoresToday(d) {
    // forUser() buckets by relation to today: { today, upcoming, overdue, count }
    const rows = (d.chores?.mine_today?.today || []);
    $('[data-chores-today]').innerHTML = rows.length
      ? rows.map((t) => choreRow(t, { today: true })).join('')
      : `<div class="fm-empty">
           <i class="bi bi-cup-hot"></i>
           <h3>Nothing due today</h3>
           <p>You have no chores on your rotation for ${esc(Fmt.date(d.today))}. Enjoy it.</p>
         </div>`;
  }

  function renderChoreWeek(d) {
    const w = d.chores?.mine_week || {};
    const tasks = [...(w.overdue || []), ...(w.today || []), ...(w.upcoming || [])];

    // Bucket my chores by date so each day cell can show a count.
    const byDate = new Map();
    tasks.forEach((t) => {
      if (!byDate.has(t.task_date)) byDate.set(t.task_date, []);
      byDate.get(t.task_date).push(t);
    });

    // Build ISO dates from the server's "today" so we never drift on TZ.
    const start = new Date(`${d.today}T00:00:00Z`);
    let html = '<div class="fm-week">';
    for (let i = 0; i < 7; i++) {
      const dt = new Date(start);
      dt.setUTCDate(start.getUTCDate() + i);
      const iso = dt.toISOString().slice(0, 10);
      const dayTasks = byDate.get(iso) || [];
      const dow = dt.toLocaleDateString('en-GB', { weekday: 'short', timeZone: 'UTC' });
      const isToday = iso === d.today;

      html += `
        <div class="fm-day ${isToday ? 'today' : ''}" title="${esc(Fmt.dateLong(iso))}">
          <div class="fm-day-dow">${esc(dow)}</div>
          <div class="fm-day-num">${dt.getUTCDate()}</div>
          ${dayTasks.length
            ? `<div class="fm-day-dot mine"></div><div class="fm-day-count">${dayTasks.length}</div>`
            : '<div class="fm-day-count" style="opacity:.45">&mdash;</div>'}
        </div>`;
    }
    html += '</div>';

    // List what those tasks actually are.
    html += `<div class="mt-3">${tasks.length
      ? tasks.slice(0, 6).map((t) => choreRow(t)).join('')
      : '<p class="text-muted-2 mb-0" style="font-size:.85rem">No chores assigned to you this week.</p>'}</div>`;

    $('[data-chore-week]').innerHTML = html;
  }

  function renderMeals(d) {
    const host = $('[data-meal-day]');
    if (!host) return;

    const rows = d.meals.today || [];
    if (!rows.length) {
      host.innerHTML = `<div class="fm-empty">
        <i class="bi bi-calendar-x"></i><h3>No plan for today</h3>
        <p>An admin has not published a meal plan for this week yet.</p></div>`;
      return;
    }

    const slotHtml = rows.map((m) => `
      <div class="fm-slot-item ${m.menu_title ? '' : 'empty'}" title="${esc(m.menu_notes || '')}">
        <span class="fm-slot-type">${esc(m.meal_type.slice(0, 3))}</span>
        <span class="fm-slot-menu">${esc(m.menu_title || 'not planned')}</span>
        ${m.cook_name ? `<span class="fm-slot-cook">· ${esc(m.cook_name)}</span>` : ''}
      </div>`).join('');

    host.innerHTML = `
      <div class="fm-meal-row is-today">
        <div class="fm-meal-day today">${esc(Fmt.date(d.today, { weekday: 'short' }))}
          <small>${esc(Fmt.date(d.today))}</small></div>
        <div class="fm-slot">${slotHtml}</div>
        <div class="fm-meal-end">
          <a class="btn btn-sm btn-outline-secondary" href="${esc(baseHref('meals.php'))}">Open</a>
        </div>
      </div>`;
  }

  function renderNotices(d) {
    const rows = d.notices || [];
    $('[data-notices]').innerHTML = rows.length
      ? rows.map((n) => `
        <div class="fm-notice ${n.is_pinned_active ? 'pinned' : ''} ${n.is_read ? '' : 'unread'}">
          <div class="fm-notice-head">
            <i class="bi ${esc(n.icon)} text-${esc(n.category_class)}"></i>
            <span class="fm-notice-title">${esc(n.title)}</span>
            <span class="fm-pill muted">${esc(n.audience_label)}</span>
            <span class="text-faint ms-auto" style="font-size:.72rem">${esc(n.ago)}</span>
          </div>
          ${n.excerpt ? `<div class="fm-notice-body">${esc(n.excerpt)}</div>` : ''}
        </div>`).join('')
      : '<p class="text-muted-2 mb-0" style="font-size:.85rem">No notices yet.</p>';
  }

  function renderSettle(d) {
    const plan = d.money.suggestion || [];
    if (!plan.length) {
      $('[data-settle-plan]').innerHTML = `
        <div class="fm-empty" style="padding:1.25rem">
          <i class="bi bi-check2-circle text-success"></i>
          <h3>Everyone is settled</h3>
          <p class="mb-0">No payments are outstanding.</p>
        </div>`;
      return;
    }

    $('[data-settle-plan]').innerHTML =
      `<p class="fm-section-title">${plan.length} transfer${plan.length === 1 ? '' : 's'} clears everything</p>` +
      plan.map((t) => {
        const from = nameOf(t.from_user_id);
        const to   = nameOf(t.to_user_id);
        return `
          <div class="fm-settle-plan">
            <span class="fm-avatar sm" style="background:${esc(colorOf(t.from_user_id))}">${esc(Fmt.initials(from))}</span>
            <span style="font-size:.84rem">${esc(from)}</span>
            <i class="bi bi-arrow-right fm-arrow"></i>
            <span class="fm-avatar sm" style="background:${esc(colorOf(t.to_user_id))}">${esc(Fmt.initials(to))}</span>
            <span style="font-size:.84rem">${esc(to)}</span>
            <span class="fm-settle-amt">${esc(Fmt.money(t.amount_cents))}</span>
          </div>`;
      }).join('');
  }

  function renderHousehold(d) {
    const rows = d.apartment.household || [];
    const byStatus = { active: [], invited: [], suspended: [] };
    rows.forEach((u) => (byStatus[u.status] || byStatus.active).push(u));

    $('[data-household]').innerHTML = rows.map((u) => `
      <div class="fm-resident ${u.status !== 'active' ? u.status : ''}">
        <span class="fm-avatar" style="background:${esc(u.avatar)}">${esc(Fmt.initials(u.name))}</span>
        <div class="fm-resident-main">
          <div class="fm-resident-name">
            ${esc(u.name)}
            ${u.role === 'admin' ? '<span class="fm-pill info">admin</span>' : ''}
            ${u.status !== 'active' ? `<span class="fm-pill ${u.status === 'invited' ? 'warn' : 'muted'}">${esc(u.status)}</span>` : ''}
          </div>
        </div>
      </div>`).join('');
  }

  function renderFairness(d) {
    // fairness() returns { window_days, total_tasks, ideal_each, rows: [...] }
    const fair = d.chores?.fairness || {};
    const list = Array.isArray(fair) ? fair : (fair.rows || []);

    if (!list.length) {
      $('[data-fairness]').innerHTML = '<p class="text-muted-2 mb-0" style="font-size:.85rem">Not enough history yet.</p>';
      return;
    }

    // Fairness counts tasks per person (and points), not points alone.
    const max = Math.max(...list.map((r) => r.total || 0), 1);
    $('[data-fairness]').innerHTML = list.map((r) => {
      const pct = Math.round(((r.total || 0) / max) * 100);
      const cls = r.user_id === me.id ? 'me' : (r.verdict === 'over' ? 'over' : '');
      return `
        <div class="fm-fair-row">
          <div class="fm-fair-name fm-truncate">${esc(r.full_name || nameOf(r.user_id))}</div>
          <div class="fm-fair-track"><div class="fm-fair-fill ${cls}" style="width:${pct}%"></div></div>
          <div class="fm-fair-val">${r.completed ?? 0}/${r.total ?? 0}</div>
        </div>`;
    }).join('');
  }

  function renderActivity(d) {
    const rows = d.activity || [];
    $('[data-activity]').innerHTML = rows.length
      ? `<div class="fm-feed">${rows.map((a) => `
          <div class="fm-feed-item ${a.tone === 'primary' ? 'hi' : ''}">
            ${esc(a.summary)}
            <div class="fm-feed-time">${esc(a.ago)}</div>
          </div>`).join('')}</div>`
      : '<p class="text-muted-2 mb-0" style="font-size:.85rem">Nothing yet.</p>';
  }

  /* ------------------------------------------------------------------ */
  /*  Helpers                                                            */
  /* ------------------------------------------------------------------ */

  const householdIndex = () => {
    const map = new Map();
    (data?.apartment?.household || []).forEach((u) => map.set(u.id, u));
    return map;
  };
  const nameOf   = (id) => householdIndex().get(id)?.name || `#${id}`;
  const colorOf  = (id) => householdIndex().get(id)?.avatar || '#64748b';
  const baseHref = (page) => document.body.dataset.baseHref
    ? `${document.body.dataset.baseHref}/${page}`
    : page;

  function renderAll(d) {
    data = d;
    renderHead(d);
    renderBalanceStats(d);
    renderChoresToday(d);
    renderChoreWeek(d);
    renderMeals(d);
    renderNotices(d);
    renderSettle(d);
    renderHousehold(d);
    renderFairness(d);
    renderActivity(d);
  }

  function wireCompleteButtons() {
    on(document, 'click', '[data-complete]', async (ev, el) => {
      ev.preventDefault();
      const id = Number(el.dataset.complete);
      el.disabled = true;
      await App.guard(async () => {
        await API.choreComplete(id, 'Marked done from the dashboard');
        App.toast('Nice — chore marked done.', 'ok');
        await load();
      });
      el.disabled = false;
    });
  }

async function load() {
    const d = await API.get('dashboard');
    me = d.me || me || await API.me();
    renderAll(d);
}

  /* ------------------------------------------------------------------ */
  /*  Quick-add expense form                                             */
  /* ------------------------------------------------------------------ */

  async function initExpenseForm() {
    const form = $('[data-form="expense"]');
    if (!form) return;

    let roster = [];

    // roster() is bucketed: { rooms, duty_groups, residents }
    const bucket = await App.guard(() => API.get('resident.roster')) || {};
    roster = bucket.residents || [];

    const picker = $('[data-picker]', form);
    picker.innerHTML = roster.map((u) => `
      <button type="button" class="btn btn-sm btn-outline-secondary" data-pick="${u.id}">
        ${esc(Fmt.initials(u.full_name))} ${esc(u.full_name.split(' ')[0])}
      </button>`).join('');

    const weights = $('[data-weights]', form);
    weights.innerHTML = roster.map((u) => `
      <span class="d-inline-flex align-items-center gap-1" style="font-size:.78rem">
        <span class="fm-avatar sm" style="background:${esc(u.avatar_color || '#64748b')}">${esc(Fmt.initials(u.full_name))}</span>
        <input class="form-control form-control-sm" style="width:3.5rem" type="number"
               min="0" max="20" value="1" data-weight="${u.id}">
      </span>`).join('');

    // Show/hide the dependent blocks.
    form.addEventListener('change', (ev) => {
      if (ev.target.name !== 'split_type') return;
      const mode = ev.target.value;
      $$('[data-when]', form).forEach((block) => {
        block.classList.toggle('d-none', block.dataset.when !== mode);
      });
      $('[data-picker]', form).querySelectorAll('[data-pick]').forEach((b) => b.classList.remove('btn-primary'));
    });

    // Toggle a person in/out of a selective split.
    on(form, 'click', '[data-pick]', (ev, el) => {
      const btn = el.closest('[data-pick]');
      btn.classList.toggle('btn-primary');
      btn.classList.toggle('btn-outline-secondary');
    });

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      App.clearErrors(form);

      const data2 = Object.fromEntries(new FormData(form).entries());
      data2.paid_by_user_id = me.id;

      const mode = data2.split_type;
      if (mode === 'selective') {
        // ExpenseService::sharesFromInput() reads `user_ids`.
        data2.user_ids = $$('[data-pick].btn-primary', form).map((b) => Number(b.dataset.pick));
      }
      if (mode === 'shares') {
        // ...and `weights`, keyed by user id.
        const weights = {};
        $$('[data-weight]', form).forEach((input) => {
          const w = Number(input.value);
          if (w > 0) weights[input.dataset.weight] = w;
        });
        data2.weights = weights;
      }

      const submit = form.querySelector('[type="submit"]');
      submit.disabled = true;

      await App.guard(async () => {
        await API.expenseCreate(data2);
        App.toast('Expense saved.', 'ok');
        form.reset();
        App.closeModal($('#quickExpense'));
        await load();
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
  /*  Boot                                                               */
  /* ------------------------------------------------------------------ */

  document.addEventListener('DOMContentLoaded', async () => {
    // The app root is two levels above /assets/css/, so JS-built links work
    // when the app lives in a sub-directory such as /flatmate.
    const css = document.querySelector('link[href*="css/style.css"]');
    document.body.dataset.baseHref = css
      ? new URL('../../', css.href).pathname.replace(/\/$/, '')
      : '';

    // Strategy switch for the settle-up panel.
    const strategy = $('[data-strategy]');
    strategy?.addEventListener('change', async () => {
      await App.guard(async () => {
        const ledger = await API.get('balance.ledger', { strategy: strategy.value });
        // ledger() returns `transfers`; DebtSimplifier calls the same list `plan`.
        data.money.suggestion = ledger.transfers || [];
        renderSettle(data);
      });
    });

    me = await App.guard(() => API.me()) || me;
    // Delegated handlers are bound once, never per render.
    wireCompleteButtons();
    await App.guard(load);
    await initExpenseForm();
  });
})();