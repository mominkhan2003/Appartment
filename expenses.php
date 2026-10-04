<?php
/**
 * Expenses — the shared ledger, plus the settle-up view.
 *
 * Money is stored in integer cents end to end; formatting happens only in the
 * client (Fmt.money) and in the few server-rendered labels below.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';
require_login();

$pageTitle   = 'Expenses';
$pageIcon    = 'bi-wallet2';
$nav         = 'expenses';
$pageScripts = ['js/expenses.js'];

require __DIR__ . '/includes/head.php';
?>

<ul class="nav nav-pills mb-3" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-ledger"
            type="button" role="tab">Ledger</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-settle"
            type="button" role="tab">Settle up</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-fund"
            type="button" role="tab">House fund</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-categories"
            type="button" role="tab">Breakdown</button>
  </li>
</ul>


<div class="tab-content">

  <!-- ================= ledger ================= -->
  <div class="tab-pane fade show active" id="tab-ledger">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <div class="input-group" style="max-width:280px">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input class="form-control" data-filter-q placeholder="Search title or reference">
      </div>

      <select class="form-select" style="max-width:190px" data-filter-category>
        <option value="">All categories</option>
      </select>

      <select class="form-select" style="max-width:180px" data-filter-split>
        <option value="">Any split</option>
        <option value="equal">Equal</option>
        <option value="selective">Selective</option>
        <option value="shares">By shares</option>
        <option value="custom">Custom amounts</option>
      </select>

      <select class="form-select" style="max-width:190px" data-filter-scope>
        <option value="">Everyone</option>
        <option value="mine">Shared with me</option>
        <option value="paid">Paid by me</option>
      </select>

      <div class="ms-auto">
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newExpense">
          <i class="bi bi-plus-lg"></i> Add expense
        </button>
      </div>
    </div>

    <div data-ledger><div class="fm-card p-3"><div class="fm-skeleton" style="height:9rem"></div></div></div>

    <div class="d-flex align-items-center gap-2 mt-3">
      <button class="btn btn-sm btn-outline-secondary" data-page="-1" disabled>
        <i class="bi bi-chevron-left"></i>
      </button>
      <span class="text-faint" style="font-size:.82rem" data-page-label>&mdash;</span>
      <button class="btn btn-sm btn-outline-secondary" data-page="1">
        <i class="bi bi-chevron-right"></i>
      </button>
    </div>
  </div>

  <!-- ================= settle up ================= -->
  <div class="tab-pane fade" id="tab-settle">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <div class="btn-group btn-group-sm" role="group" aria-label="Settlement strategy">
        <button class="btn btn-outline-primary active" data-strategy="auto" data-bs-toggle="button">
          Minimum transfers
        </button>
        <button class="btn btn-outline-primary" data-strategy="greedy" data-bs-toggle="button">
          Greedy
        </button>
        <button class="btn btn-outline-primary" data-strategy="direct" data-bs-toggle="button">
          Direct only
        </button>
      </div>
      <span class="text-faint" style="font-size:.8rem">
        Fewer payments means less chasing, but some people pay someone they
        never shared a meal with.
      </span>
    </div>

    <div class="fm-grid fm-split-wide">
      <div class="fm-card">
        <div class="fm-card-head">
          <h2><i class="bi bi-arrow-left-right text-primary"></i> Who pays whom</h2>
          <div class="fm-end" data-plan-count></div>
        </div>
        <div data-settle><div class="p-3"><div class="fm-skeleton" style="height:5rem"></div></div></div>
        <div class="fm-card-foot">
          <p class="text-faint mb-0" style="font-size:.75rem">
            A payment is only recorded once both sides confirm nothing is owed.
            FlatMate never moves money itself.
          </p>
        </div>
      </div>

      <div class="fm-card">
        <div class="fm-card-head">
          <h2><i class="bi bi-people text-primary"></i> Balances</h2>
        </div>
        <div data-balances><div class="p-3"><div class="fm-skeleton" style="height:5rem"></div></div></div>
      </div>
    </div>

    <div class="fm-card mt-3">
      <div class="fm-card-head">
        <h2><i class="bi bi-clock-history text-primary"></i> Settlement history</h2>
      </div>
      <div data-settlements><div class="p-3"><div class="fm-skeleton" style="height:3rem"></div></div></div>
    </div>
  </div>

  <!-- ================= house fund ================= -->
  <div class="tab-pane fade" id="tab-fund">
    <div class="fm-grid fm-split-wide">
      <div class="fm-card">
        <div class="fm-card-head">
          <h2><i class="bi bi-piggy-bank text-primary"></i> Cash in the fund</h2>
          <div class="fm-end">
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newContribution">
              <i class="bi bi-plus-lg"></i> Add money
            </button>
          </div>
        </div>
        <div data-fund-totals><div class="p-3"><div class="fm-skeleton" style="height:5rem"></div></div></div>
        <div class="fm-card-foot">
          <p class="text-faint mb-0" style="font-size:.75rem">
            Money handed over goes up; groceries bought from it come down.
            Expenses marked &ldquo;from the house fund&rdquo; stay off the
            settle-up tab, so nobody is chased for the pot twice.
          </p>
        </div>
      </div>

      <div class="fm-card">
        <div class="fm-card-head">
          <h2><i class="bi bi-person-exclamation text-primary"></i> Still to collect</h2>
          <div class="fm-end" data-collect-total></div>
        </div>
        <div data-fund-collectors><div class="p-3"><div class="fm-skeleton" style="height:5rem"></div></div></div>
      </div>
    </div>

    <div class="fm-card mt-3">
      <div class="fm-card-head">
        <h2><i class="bi bi-clock-history text-primary"></i> Money in and out of the fund</h2>
      </div>
      <div data-fund-log><div class="p-3"><div class="fm-skeleton" style="height:6rem"></div></div></div>
    </div>
  </div>

  <!-- ================= breakdown ================= -->

  <div class="tab-pane fade" id="tab-categories">
    <div class="fm-card">
      <div class="fm-card-head">
        <h2><i class="bi bi-pie-chart text-primary"></i> Where the money goes</h2>
        <div class="fm-end">
          <select class="form-select form-select-sm" style="width:auto" data-breakdown-month>
            <option value="">All time</option>
          </select>
        </div>
      </div>
      <div data-breakdown><div class="p-3"><div class="fm-skeleton" style="height:6rem"></div></div></div>
    </div>
  </div>
</div>

<!-- ================= add money to the fund ================= -->
<div class="modal fade" id="newContribution" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content fm-card">
      <form data-form="contribution">
        <div class="modal-header">
          <h5 class="modal-title">Add money to the house fund</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <p class="text-faint">
            Use this when a resident hands over cash for groceries or bills.
            Their outstanding share comes down by the same amount.
          </p>

          <div class="mb-2">
            <label class="form-label" for="k-from">Who paid in?</label>
            <select class="form-select" id="k-from" name="from_user_id" data-payers required></select>
          </div>

          <div class="row g-2">
            <div class="col-6 mb-2">
              <label class="form-label" for="k-amount">Amount (<?= e((string) config('app.currency', '\u{20AC}')) ?>)</label>
              <input class="form-control" id="k-amount" name="amount" inputmode="decimal"
                     placeholder="0.00" required>
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="k-method">How?</label>
              <select class="form-select" id="k-method" name="method">
                <option value="cash">Cash</option>
                <option value="bkash">bKash</option>
                <option value="nagad">Nagad</option>
                <option value="bank">Bank</option>
                <option value="other">Other</option>
              </select>
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label" for="k-on">Date</label>
            <input class="form-control" id="k-on" name="contributed_on" type="date" required>
          </div>

          <div class="mb-0">
            <label class="form-label" for="k-note">Note (optional)</label>
            <input class="form-control" id="k-note" name="note" placeholder="e.g. March grocery share">
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add money</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================= new expense ================= -->

<div class="modal fade" id="newExpense" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content fm-card">
      <form data-form="expense">
        <div class="modal-header">
          <h5 class="modal-title">Add an expense</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6 mb-2">
              <label class="form-label" for="e-title">What was it?</label>
              <input class="form-control" id="e-title" name="title" required
                     placeholder="e.g. Groceries for the week">
            </div>
            <div class="col-md-3 mb-2">
              <label class="form-label" for="e-amount">Amount (<?= e((string) config('app.currency', '\u{20AC}')) ?>)</label>
              <input class="form-control" id="e-amount" name="amount" required
                     inputmode="decimal" placeholder="0.00">
            </div>
            <div class="col-md-3 mb-2">
              <label class="form-label" for="e-date">Date</label>
              <input type="date" class="form-control" id="e-date" name="expense_date"
                     value="<?= e(DutyScheduler::today()) ?>">
            </div>
          </div>

          <div class="row g-2">
            <div class="col-md-4 mb-2">
              <label class="form-label" for="e-category">Category</label>
              <select class="form-select" id="e-category" name="category_id" data-categories>
                <option value="">Uncategorised</option>
              </select>
            </div>
            <div class="col-md-4 mb-2">
              <label class="form-label" for="e-payer">Paid by</label>
              <select class="form-select" id="e-payer" name="paid_by_user_id" data-payers required></select>
            </div>
            <div class="col-md-4 mb-2">
              <label class="form-label" for="e-split">How to split?</label>
              <select class="form-select" id="e-split" name="split_type">
                <option value="equal">Equally between everyone</option>
                <option value="selective">Only the people I pick</option>
                <option value="shares">By shares / weight</option>
                <option value="meal_based">Only the people who ate</option>
              </select>
            </div>
          </div>

          <div class="mb-2 d-none" data-when="selective">
            <label class="form-label">Who is in?</label>
            <div class="d-flex flex-wrap gap-1" data-picker></div>
          </div>

          <div class="mb-2 d-none" data-when="shares">
            <label class="form-label">Shares (weight per person)</label>
            <div class="d-flex flex-wrap gap-2" data-weights></div>
          </div>

          <div class="mb-2 d-none" data-when="meal_based">
            <label class="form-label" for="e-meal-scope">Who counts as eating?</label>
            <select class="form-select" id="e-meal-scope" name="meal_scope">
              <option value="current_week">Everyone who opted in as eating this week</option>
              <option value="explicit">Only the people I pick below</option>
            </select>
            <div class="form-hint">
              Uses the meal planner's opt-ins for the week of the expense date.
              Nobody here is affected by chore duty.
            </div>
            <div class="d-flex flex-wrap gap-1 mt-2 d-none" data-eater-picker></div>
          </div>

          <div class="mb-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="e-fund"
                     name="paid_from_fund" value="1">
              <label class="form-check-label" for="e-fund">
                Paid from the house fund
              </label>
            </div>
            <div class="form-hint">
              Tick this for groceries and bills bought with the household's cash.
              The shopper is still recorded above, but the money is tracked in
              the House fund tab instead of being owed to one person.
            </div>
          </div>

          <div class="mb-0">
            <label class="form-label" for="e-note">Note (optional)</label>
            <input class="form-control" id="e-note" name="description" placeholder="Anything worth remembering">

          </div>
        </div>

        <div class="modal-footer">
          <span class="me-auto text-faint" style="font-size:.78rem" data-share-preview></span>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Save expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================= settle modal ================= -->
<div class="modal fade" id="settleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content fm-card">
      <form data-form="settle">
        <div class="modal-header">
          <h5 class="modal-title">Record a payment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-faint" data-settle-summary></p>

          <div class="mb-2">
            <label class="form-label" for="s-amount">Amount (<?= e((string) config('app.currency', '\u{20AC}')) ?>)</label>
            <input class="form-control" id="s-amount" name="amount" required inputmode="decimal">
          </div>

          <div class="mb-2">
            <label class="form-label" for="s-method">How?</label>
            <select class="form-select" id="s-method" name="method">
              <option value="cash">Cash</option>
              <option value="bkash">bKash</option>
              <option value="nagad">Nagad</option>
              <option value="bank">Bank transfer</option>
              <option value="other">Something else</option>
            </select>
          </div>

          <div class="row g-2">
            <div class="col-6 mb-2">
              <label class="form-label" for="s-ref">Reference</label>
              <input class="form-control" id="s-ref" name="reference" placeholder="Transaction ID (optional)">
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="s-date">Date</label>
              <input type="date" class="form-control" id="s-date" name="settled_at"
                     value="<?= e(DutyScheduler::today()) ?>">
            </div>
          </div>

          <div class="mb-0">
            <label class="form-label" for="s-note">Note</label>
            <input class="form-control" id="s-note" name="note" placeholder="Optional">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Mark as settled</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================= expense detail ================= -->
<div class="modal fade" id="expenseDetail" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content fm-card">
      <div class="modal-header">
        <h5 class="modal-title" data-detail-title>Expense</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" data-detail-body></div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>\r\n<!-- receipt upload support -->\r\n
