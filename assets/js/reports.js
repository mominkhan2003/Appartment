/* ==========================================================================
   FlatMate  |  Reports
   --------------------------------------------------------------------------
   Admin-only read-only view of the apartment's financial position: lifetime
   expense and settlement totals, each member's net balance, and the most
   recent ledger activity.

   This lives in its own module rather than an inline <script> because `$` is
   deliberately module-scoped in this codebase. Every page file that inlined
   JavaScript was reaching for a `$` that only existed inside another file's
   IIFE, which throws ReferenceError and leaves the page showing its skeleton.
   ========================================================================== */

(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);

  const DIRECTION_CLASS = { debit: 'text-danger', credit: 'text-success' };

  /* ---------------------------------------------------------------------
     Balances
     --------------------------------------------------------------------- */
  function balanceRow(b) {
    const tone = DIRECTION_CLASS[b.direction] || '';
    return `
      <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
        <span>${esc(b.full_name)}</span>
        <span class="${tone}">${Fmt.money(b.net_cents, { sign: true })}</span>
      </div>`;
  }

  function renderSummary(r) {
    const balances = r.balances || [];

    $('#summary').innerHTML = `
      <div class="row g-3">
        <div class="col-md-6">
          <div class="p-3 border rounded h-100">
            <div class="text-muted small">Total Expenses</div>
            <h3 class="mb-0">${Fmt.money(Math.round(r.total_expenses * 100))}</h3>
          </div>
        </div>
        <div class="col-md-6">
          <div class="p-3 border rounded h-100">
            <div class="text-muted small">Total Settlements</div>
            <h3 class="mb-0">${Fmt.money(Math.round(r.total_settlements * 100))}</h3>
          </div>
        </div>
      </div>

      <div class="mt-4">
        <h5>Balances</h5>
        ${balances.length
          ? balances.map(balanceRow).join('')
          : '<div class="text-muted">No balances yet</div>'}
      </div>`;
  }

  /* ---------------------------------------------------------------------
     Recent activity
     --------------------------------------------------------------------- */
  function activityRow(a) {
    // Ledger timestamps arrive as 'YYYY-MM-DD HH:MM:SS' or ISO-8601 with a T.
    const stamp = String(a.created_at || '').slice(0, 19).replace('T', ' ');
    return `
      <div class="py-2 border-bottom small">
        <div>${esc(a.summary || '')}</div>
        <div class="text-muted">${Fmt.date(stamp)}</div>
      </div>`;
  }

  function renderActivity(r) {
    const recent = r.recent || [];
    $('#activity').innerHTML = recent.length
      ? recent.map(activityRow).join('')
      : '<div class="text-muted">No activity yet</div>';
  }

  /* ---------------------------------------------------------------------
     Load
     --------------------------------------------------------------------- */
  async function load() {
    const r = await App.guard(() => API.get('report.summary'));
    if (!r) return;

    renderSummary(r);
    renderActivity(r);
  }

  document.addEventListener('DOMContentLoaded', load);
})();
