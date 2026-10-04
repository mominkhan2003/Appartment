<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';
require_admin();

$pageTitle   = 'Reports';
$pageIcon    = 'bi-file-earmark-bar-graph';
$nav         = 'reports';

require __DIR__ . '/includes/head.php';
?>
<div class="fm-grid">
  <div class="fm-card">
    <div class="fm-card-head">
      <h2><i class="bi bi-bar-chart-line"></i> Financial Summary</h2>
    </div>
    <div class="fm-card-body" id="summary">
      <div class="fm-skeleton" style="height:8rem"></div>
    </div>
  </div>
  <div class="fm-card">
    <div class="fm-card-head">
      <h2><i class="bi bi-clock-history"></i> Recent Activity</h2>
    </div>
    <div class="fm-card-body" id="activity">
      <div class="fm-skeleton" style="height:8rem"></div>
    </div>
  </div>
</div>
<script>
(() => {
  async function load() {
    const r = await App.guard(() => API.get('report.summary'));
    if (!r) return;
    const totalExp = Fmt.money(Math.round(r.total_expenses * 100));
    const totalSet = Fmt.money(Math.round(r.total_settlements * 100));
    $('#summary').innerHTML = `
      <div class="row g-3">
        <div class="col-md-6">
          <div class="p-3 border rounded">
            <div class="text-muted small">Total Expenses</div>
            <h3 class="mb-0">${totalExp}</h3>
          </div>
        </div>
        <div class="col-md-6">
          <div class="p-3 border rounded">
            <div class="text-muted small">Total Settlements</div>
            <h3 class="mb-0">${totalSet}</h3>
          </div>
        </div>
      </div>
      <div class="mt-3">
        <h5>Balances</h5>
        ${(r.balances || []).map(b => `
          <div class="d-flex justify-content-between py-2 border-bottom">
            <span>${esc(b.full_name)}</span>
            <span class="${b.direction === 'debit' ? 'text-danger' : (b.direction === 'credit' ? 'text-success' : '')}">${Fmt.money(b.net_cents, {sign:true})}</span>
          </div>
        `).join('')}
      </div>
    `;
    $('#activity').innerHTML = (r.recent || []).map(a => `
      <div class="py-2 border-bottom small">
        <div>${esc(a.summary || '')}</div>
        <div class="text-muted">${Fmt.date(String(a.created_at).slice(0,19).replace('T',' '))}</div>
      </div>
    `).join('') || '<div class="text-muted">No activity yet</div>';
  }
  document.addEventListener('DOMContentLoaded', load);
})();
</script>
<?php require __DIR__ . '/includes/foot.php'; ?>
