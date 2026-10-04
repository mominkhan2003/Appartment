<?php
/**
 * Meal planner — 7 days x 3 slots, suggestions, voting and opt-in.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';
require_login();

$pageTitle   = 'Meal planner';
$pageIcon    = 'bi-cup-hot-fill';
$nav         = 'meals';
$pageScripts = ['js/meals.js'];

require __DIR__ . '/includes/head.php';
?>

<div class="d-flex flex-wrap align-items-end gap-2 mb-3">
  <div>
    <h1 class="h5 mb-0">Meal planner</h1>
    <p class="text-muted-2 mb-0" style="font-size:.85rem" data-week-label>Loading…</p>
  </div>
  <div class="fm-actions">
    <button class="btn btn-sm btn-outline-secondary" data-week-shift="-1">
      <i class="bi bi-chevron-left"></i> Prev
    </button>
    <button class="btn btn-sm btn-outline-secondary" data-week-shift="0">This week</button>
    <button class="btn btn-sm btn-outline-secondary" data-week-shift="1">
      Next <i class="bi bi-chevron-right"></i>
    </button>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#grocery">
      <i class="bi bi-basket"></i> Grocery list
    </button>
  </div>
</div>

<!-- Bulk opt-in controls -->
<div class="fm-card mb-3">
  <div class="fm-card-body tight d-flex flex-wrap align-items-center gap-2">
    <span class="fm-section-title mb-0 me-2">Me, for this week:</span>
    <div class="fm-optin" role="group" aria-label="Set my meal participation for the week">
      <button data-bulk="eating">Eating</button>
      <button data-bulk="maybe">Maybe</button>
      <button data-bulk="opting_out">Opting out</button>
    </div>
    <span class="text-faint ms-auto" style="font-size:.78rem">
      Chores are <strong>not</strong> affected by this — they run on their own rotation.
    </span>
  </div>
</div>

<!-- The planner grid -->
<div class="fm-card">
  <div class="fm-card-head">
    <h2><i class="bi bi-calendar3 text-primary"></i> Week plan</h2>
    <div class="fm-end">
      <span class="text-faint" style="font-size:.75rem">7 days &times; 3 meals</span>
    </div>
  </div>
  <div data-planner>
    <div class="p-3">
      <div class="fm-skeleton" style="height:9rem"></div>
    </div>
  </div>
</div>

<!-- ============ suggest a dish for a slot ============ -->
<div class="modal fade" id="suggest" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content fm-card">
      <form data-form="suggest">
        <div class="modal-header">
          <h5 class="modal-title">Suggest a dish</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted-2" style="font-size:.85rem">
            <span data-suggest-when></span>
          </p>
          <div class="mb-3">
            <label class="form-label" for="s-title">Dish</label>
            <input class="form-control" id="s-title" name="title" required
                   placeholder="e.g. Chicken biryani">
          </div>
          <div class="mb-3">
            <label class="form-label" for="s-cost">Estimated cost (optional)</label>
            <div class="input-group">
              <span class="input-group-text"><?= e((string) config('app.currency', '\u{20AC}')) ?></span>
              <input class="form-control" id="s-cost" name="estimated_cost"
                     inputmode="decimal" placeholder="0.00">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="s-notes">Notes / ingredients</label>
            <textarea class="form-control" id="s-notes" name="notes" rows="3"
                      placeholder="What does it need?"></textarea>
          </div>
          <p class="text-faint mb-0" style="font-size:.78rem">
            Everyone votes, and the winner becomes that slot's menu.
          </p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Add suggestion</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============ grocery list ============ -->
<div class="modal fade" id="grocery" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content fm-card">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-basket"></i> Grocery list</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" data-grocery-body>
        <div class="fm-skeleton" style="height:6rem"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-print>Print</button>
        <button class="btn btn-primary" data-copy>Copy list</button>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>