<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';
require_admin();

$pageTitle   = 'Reports';
$pageIcon    = 'bi-file-earmark-bar-graph';
$nav         = 'reports';
$pageScripts = ['js/reports.js'];

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
<?php require __DIR__ . '/includes/foot.php'; ?>
