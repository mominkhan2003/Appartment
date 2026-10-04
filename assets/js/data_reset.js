/* ==========================================================================
   FlatMate  |  Data reset
   --------------------------------------------------------------------------
   Tick boxes, live counts, preview, then a typed confirmation.

   The order of operations is the whole design:

     tick  ->  preview (server counts)  ->  type DELETE  ->  purge

   The preview is a separate server call on purpose. Counting locally would mean
   shipping every count to the browser and trusting arithmetic in a screen whose
   entire job is to be trustworthy about what it is about to destroy.
   ========================================================================== */

(() => {
  'use strict';

  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const GROUPS = window.RESET_GROUPS || {};

  let scopes = [];
  let preview = null;

  const byKey = (key) => scopes.find((s) => s.key === key);

  function esc(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  const ticked = () => $$('[data-scope]:checked').map((el) => el.dataset.scope);

  /* ------------------------------------------------------------------ */
  /*  Render                                                              */
  /* ------------------------------------------------------------------ */

  function scopeRow(scope) {
    const needs = (scope.requires || []).map((k) => byKey(k)?.label).filter(Boolean);

    return `
      <label class="d-flex gap-2 align-items-start py-2 px-2 rounded
                    ${scope.has_data ? '' : 'opacity-50'}"
             data-row="${esc(scope.key)}">
        <input class="form-check-input mt-1 flex-shrink-0" type="checkbox"
               data-scope="${esc(scope.key)}"
               data-requires="${esc((scope.requires || []).join(','))}"
               ${scope.has_data ? '' : 'disabled'}>
        <span class="flex-grow-1" style="min-width:0">
          <span class="d-flex align-items-center gap-2">
            <i class="bi ${esc(scope.icon || 'bi-dot')} text-secondary"></i>
            <span style="font-size:.88rem;font-weight:600">${esc(scope.label)}</span>
            <span class="badge text-bg-light text-dark ms-auto">${Number(scope.total || 0)}</span>
          </span>
          <span class="d-block text-faint" style="font-size:.76rem">${esc(scope.description)}</span>
          ${needs.length
            ? `<span class="d-block mt-1" style="font-size:.72rem">
                 <i class="bi bi-link-45deg"></i>
                 needs ${esc(needs.join(' and '))} ticked too
               </span>`
            : ''}
        </span>
      </label>`;
  }

  function render() {
    const host = $('[data-reset-scope]');
    if (!host) return;

    const order = ['daily', 'money', 'flat', 'people'];
    const groups = order
      .map((g) => ({ key: g, rows: scopes.filter((s) => s.group === g) }))
      .filter((g) => g.rows.length);

    host.innerHTML = groups.map((g) => `
      <div class="card mb-3">
        <div class="card-header py-2 fw-semibold">
          ${esc(GROUPS[g.key]?.label || g.key)}
          <span class="text-faint fw-normal d-block" style="font-size:.75rem">
            ${esc(GROUPS[g.key]?.hint || '')}
          </span>
        </div>
        <div class="card-body py-1">
          ${g.rows.map(scopeRow).join('')}
        </div>
      </div>`).join('')
      + `
      <div class="card border-danger">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
          <div class="flex-grow-1">
            <div style="font-size:.9rem;font-weight:600">
              <span data-total-rows>0</span> row(s) selected
            </div>
            <div class="text-faint" style="font-size:.78rem" data-total-detail>
              Tick something above to see what it would remove.
            </div>
          </div>
          <button class="btn btn-danger btn-sm" data-preview disabled>
            <i class="bi bi-eye"></i> Review before deleting
          </button>
        </div>
      </div>`;

    wire();
  }

  /* ------------------------------------------------------------------ */
  /*  Behaviour                                                           */
  /* ------------------------------------------------------------------ */

  function refreshTotals() {
    const chosen = ticked();
    const button = $('[data-preview]');
    const rows   = $('[data-total-rows]');
    const detail = $('[data-total-detail]');

    if (button) button.disabled = chosen.length === 0;

    /* Cheap local sum purely for the running counter. The authoritative number
       still comes back from data.reset_preview before anything is destroyed. */
    const local = chosen.reduce((sum, key) => sum + Number(byKey(key)?.total || 0), 0);
    if (rows) rows.textContent = local;

    if (detail) {
      const names = chosen.map((k) => byKey(k)?.label).filter(Boolean);
      detail.textContent = chosen.length
        ? `${names.join(', ')}`
        : 'Tick something above to see what it would remove.';
    }
    preview = null;
  }

  function wire() {
    $('[data-reset-scope]')?.addEventListener('change', (ev) => {
      const box = ev.target.closest('[data-scope]');
      if (!box) return;

      /* Dependency: ticking something destructive pulls its requirement in with
         it, because the alternative is a silent cascade the admin never asked
         for. Unticking leaves the dependency alone -- it may be wanted anyway. */
      if (box.checked) {
        (box.dataset.requires || '').split(',').filter(Boolean).forEach((need) => {
          const target = $(`[data-scope="${CSS.escape(need)}"]`);
          if (target && !target.checked) {
            target.checked = true;
            App.toast(`Also ticked "${byKey(need)?.label || need}" - it is required.`, 'warn');
          }
        });
      }
      refreshTotals();
    });

    $('[data-preview]')?.addEventListener('click', openConfirm);
  }

  function openConfirm() {
    const chosen = ticked();
    if (!chosen.length) return;

    App.guard(() => API.post('data.reset_preview', { scopes: chosen }).then((result) => {
      preview = result;

      if (!result?.ok) {
        const first = Object.values(result?.errors || {})[0];
        App.toast(first || 'Those choices cannot be combined.', 'err');
        return;
      }

      const list = $('[data-confirm-list]');
      if (list) {
        list.innerHTML = chosen.map((key) => {
          const scope = byKey(key);
          const rows  = scope?.rows || {};
          const detail = Object.entries(rows)
            .filter(([, n]) => Number(n) > 0)
            .map(([table, n]) => `${table} (${n})`)
            .join(', ');
          return `<li><strong>${esc(scope?.label || key)}</strong>
                  <span class="text-faint"> &mdash; ${esc(detail || `${scope?.total || 0} row(s)`)}</span></li>`;
        }).join('');
      }

      const count = $('[data-confirm-count]');
      if (count) count.textContent = result.row_count;

      const input = $('[data-confirm-input]');
      const run   = $('[data-confirm-run]');
      const err   = $('[data-confirm-error]');
      if (input) input.value = '';
      if (run) run.disabled = true;
      if (err) err.textContent = '';

      bootstrap.Modal.getOrCreateInstance($('#confirmReset')).show();
    }));
  }

  function wireConfirm() {
    const input = $('[data-confirm-input]');
    const run   = $('[data-confirm-run]');
    const err   = $('[data-confirm-error]');

    const word = (document.querySelector('[data-confirm-word]')?.textContent || 'DELETE').trim();

    input?.addEventListener('input', () => {
      const ok = input.value.trim() === word;
      if (run) run.disabled = !ok;
      if (err) err.textContent = ok ? '' : `Type ${word} exactly.`;
    });

    run?.addEventListener('click', () => {
      if (!preview) return;

      const label = run.innerHTML;
      run.disabled = true;
      run.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Deleting';

      /* App.guard already toasts and handles session expiry, so onError only
         fills in the inline hint. Two toasts for one failure is noise. */
      App.guard(() => API.post('data.reset_run', {
        scopes:  preview.scopes,
        confirm: input.value.trim(),
      }).then((result) => {
        preview = null;
        bootstrap.Modal.getOrCreateInstance($('#confirmReset')).hide();
        App.toast(`Removed ${result.row_count} row(s).`, 'ok');
        return load();
      }).finally(() => {
        /* On success the modal is gone; on failure this re-arms the button in
           step with whatever is currently typed. */
        run.innerHTML = label;
        run.disabled = !preview || (input.value.trim() !== word);
      }), {
        onError: (e) => {
          if (err && e instanceof API.ApiError && e.fields) {
            err.textContent = Object.values(e.fields)[0] || '';
          }
        },
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /*  Load                                                                */
  /* ------------------------------------------------------------------ */

  async function load() {
    try {
      const data = await API.get('data.reset_scopes');
      scopes = data?.scopes || [];
      render();
      refreshTotals();
    } catch (e) {
      const host = $('[data-reset-scope]');
      const msg = e instanceof API.ApiError && e.fields?.schema
        ? e.fields.schema
        : 'Could not load the reset options.';
      if (host) {
        host.innerHTML = `<div class="alert alert-warning small">${esc(msg)}</div>`;
      }
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    wireConfirm();
    load();
  });
})();