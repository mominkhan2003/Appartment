<?php
/**
 * Dashboard — the everyday home screen.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

require_login();

$pageTitle = 'Dashboard';
$pageIcon  = 'bi-grid-1x2-fill';
$nav       = 'dashboard';

$pageScripts = ['js/dashboard.js'];

require __DIR__ . '/includes/head.php';
?>

<div class="d-flex flex-wrap align-items-end gap-2 mb-3">
  <div>
    <h1 class="h5 mb-0" data-greeting>Loading…</h1>
    <p class="fm-sub text-muted-2 mb-0" style="font-size:.85rem" data-today></p>
  </div>
  <div class="fm-actions">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('chores.php')) ?>">
      <i class="bi bi-check2-square"></i> Chores
    </a>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#quickExpense">
      <i class="bi bi-plus-lg"></i> Add expense
    </button>
  </div>
</div>

<!-- ======================= balance headline ======================= -->
<div class="fm-grid fm-grid-3 mb-3" data-balance-stats>
  <div class="fm-card fm-stat">
    <div class="fm-skeleton" style="width:60%"></div>
    <div class="fm-skeleton mt-2" style="width:80%;height:1.7rem"></div>
  </div>
  <div class="fm-card fm-stat">
    <div class="fm-skeleton" style="width:50%"></div>
    <div class="fm-skeleton mt-2" style="width:70%;height:1.7rem"></div>
  </div>
  <div class="fm-card fm-stat">
    <div class="fm-skeleton" style="width:55%"></div>
    <div class="fm-skeleton mt-2" style="width:75%;height:1.7rem"></div>
  </div>
</div>

<div class="fm-grid fm-split-wide">

  <!-- ======================= left column ======================= -->
  <div class="fm-grid" style="align-content:start">

    <!-- today's chores -->
    <section class="fm-card">
      <div class="fm-card-head">
        <h2><i class="bi bi-check2-square text-primary"></i> Your chores today</h2>
        <div class="fm-end">
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('chores.php')) ?>">Full board</a>
        </div>
      </div>
      <div data-chores-today>
        <div class="p-3"><div class="fm-skeleton" style="height:2.5rem"></div></div>
      </div>
    </section>

    <!-- this week's chores -->
    <section class="fm-card">
      <div class="fm-card-head">
        <h2><i class="bi bi-calendar-week text-primary"></i> The next 7 days</h2>
      </div>
      <div class="fm-card-body" data-chore-week>
        <div class="fm-skeleton" style="height:5rem"></div>
      </div>
    </section>

    <!-- today's meals -->
    <section class="fm-card">
      <div class="fm-card-head">
        <h2><i class="bi bi-cup-hot text-primary"></i> Meals today</h2>
        <div class="fm-end">
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('meals.php')) ?>">Planner</a>
        </div>
      </div>
      <div class="fm-meal-row" data-meal-day>
        <div class="fm-meal-day">…</div>
        <div class="fm-slot" style="flex:1">
          <div class="fm-skeleton" style="height:2rem;width:100%"></div>
        </div>
      </div>
    </section>

    <!-- notices -->
    <section class="fm-card">
      <div class="fm-card-head">
        <h2><i class="bi bi-megaphone text-primary"></i> Notice board</h2>
        <div class="fm-end">
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('notices.php')) ?>">All notices</a>
        </div>
      </div>
      <div class="fm-card-body" data-notices>
        <div class="fm-skeleton" style="height:3rem"></div>
      </div>
    </section>
  </div>

  <!-- ======================= right column ======================= -->
  <div class="fm-grid" style="align-content:start">

    <!-- settlement suggestion -->
    <section class="fm-card">
      <div class="fm-card-head">
        <h2><i class="bi bi-diagram-3 text-primary"></i> Settle up</h2>
        <div class="fm-end">
          <select class="form-select form-select-sm" style="width:auto" data-strategy>
            <option value="auto" selected>Auto</option>
            <option value="greedy">Fewest people</option>
            <option value="optimal">Fewest transfers</option>
          </select>
        </div>
      </div>
      <div class="fm-card-body" data-settle-plan>
        <div class="fm-skeleton" style="height:4rem"></div>
      </div>
      <div class="fm-card-foot">
        <a class="btn btn-sm btn-primary w-100" href="<?= e(base_url('expenses.php#balances')) ?>">
          Open the ledger
        </a>
      </div>
    </section>

    <!-- household -->
    <section class="fm-card">
      <div class="fm-card-head">
        <h2><i class="bi bi-people text-primary"></i> Household</h2>
      </div>
      <div data-household>
        <div class="p-3"><div class="fm-skeleton" style="height:3rem"></div></div>
      </div>
    </section>

    <!-- fairness -->
    <section class="fm-card">
      <div class="fm-card-head">
        <h2><i class="bi bi-bar-chart text-primary"></i> Chore fairness</h2>
      </div>
      <div class="fm-card-body" data-fairness>
        <div class="fm-skeleton" style="height:5rem"></div>
      </div>
    </section>

    <!-- activity -->
    <section class="fm-card">
      <div class="fm-card-head">
        <h2><i class="bi bi-activity text-primary"></i> Recent activity</h2>
      </div>
      <div class="fm-card-body" data-activity>
        <div class="fm-skeleton" style="height:4rem"></div>
      </div>
    </section>
  </div>
</div>

<!-- ======================= quick add expense ======================= -->
<div class="modal fade" id="quickExpense" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content fm-card">
      <form data-form="expense">
        <div class="modal-header">
          <h5 class="modal-title">Add an expense</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label" for="q-title">What was it for?</label>
            <input class="form-control" id="q-title" name="title" required
                   placeholder="e.g. Gas bill — March">
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label" for="q-amount">Amount</label>
              <div class="input-group">
                <span class="input-group-text"><?= e((string) config('app.currency', '\u{20AC}')) ?></span>
                <input class="form-control" id="q-amount" name="amount" required
                       inputmode="decimal" placeholder="0.00">
              </div>
            </div>
            <div class="col-6">
              <label class="form-label" for="q-split">Split</label>
              <select class="form-select" id="q-split" name="split_type">
                <option value="equal">Everyone equally</option>
                <option value="meal_based">Only who ate</option>
                <option value="selective">Selected people</option>
                <option value="shares">Custom shares</option>
              </select>
            </div>
          </div>

          <div class="mt-3 d-none" data-when="selective">
            <label class="form-label">Who is in this split?</label>
            <div class="d-flex flex-wrap gap-1" data-picker></div>
          </div>

          <div class="mt-3 d-none" data-when="shares">
            <label class="form-label">Shares per person</label>
            <div class="d-flex flex-wrap gap-2" data-weights></div>
            <div class="form-hint">Use 1 for a normal share, 2 for double, 0 to exclude.</div>
          </div>

          <div class="mt-3">
            <label class="form-label" for="q-cat">Category</label>
            <select class="form-select" id="q-cat" name="category_id">
              <option value="">Uncategorised</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Save expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>