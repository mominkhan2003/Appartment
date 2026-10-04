<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Data reset
 * ---------------------------------------------------------------------------
 * The "start fresh" screen.
 *
 * Nothing here is a single blunt "delete everything" button. Each group of
 * records is a separate tick, each tick shows how many rows it would remove
 * right now, and the confirm step lists the exact total before anything is
 * destroyed. DataResetService holds the rules; this page only presents them.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

require_admin();

if (!Auth::can('data.purge')) {
    flash('danger', 'Your role does not include resetting household data.');
    redirect('index.php');
}

$pageTitle   = 'Data reset';
$pageIcon    = 'bi-eraser-fill';
$nav         = 'data_reset';
$pageScripts = ['js/data_reset.js'];

/* Grouped copy for the page. The scope keys themselves come from the service,
   so a new scope shows up here automatically rather than being forgotten. */
$groupMeta = [
    'daily' => [
        'label' => 'Meals and chores',
        'hint'  => 'Start a new rota or a new set of weekly meal plans.',
    ],
    'money' => [
        'label' => 'Money',
        'hint'  => 'Clear the ledger without touching who lives here.',
    ],
    'flat'  => [
        'label' => 'Flat admin',
        'hint'  => 'Notices, reminders and the audit trail.',
    ],
    'people' => [
        'label' => 'People',
        'hint'  => 'The dangerous end. Read these before ticking them.',
    ],
];

require __DIR__ . '/includes/head.php';
?>

<div class="d-flex flex-wrap align-items-end gap-2 mb-3">
  <div>
    <h1 class="h5 mb-0">Data reset</h1>
    <p class="fm-sub text-muted-2 mb-0" style="font-size:.85rem">
      Tick only what you want gone. Everything else stays exactly as it is.
    </p>
  </div>
</div>

<div class="alert alert-danger py-2 small d-flex gap-2">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
  <div>
    These deletions are permanent and cannot be undone. There is no undo button
    and no backup taken here &mdash; if you want the old records, export them first.
    You will be asked to type the word <code data-confirm-word>DELETE</code> before
    anything is removed.
  </div>
</div>

<div data-reset-scope>
  <div class="text-center text-faint py-5">
    <span class="spinner-border spinner-border-sm me-2"></span>Counting what is here...
  </div>
</div>

<!-- ================= confirm ================= -->
<div class="modal fade" id="confirmReset" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title text-danger">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>
          Delete <span data-confirm-count>0</span> row(s) permanently?
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <p class="small mb-2">This removes:</p>
        <ul class="small mb-3" data-confirm-list
            style="max-height:220px;overflow:auto"></ul>

        <div class="alert alert-warning py-2 small mb-3">
          <i class="bi bi-info-circle me-1"></i>
          Removing residents also removes the expenses they paid for, which is
          why that box will not untick the ledger on its own.
        </div>

        <label class="form-label" for="confirmInput">
          Type <strong data-confirm-word>DELETE</strong> to confirm
        </label>
        <input class="form-control font-monospace" type="text" id="confirmInput"
               data-confirm-input autocomplete="off" spellcheck="false"
               placeholder="DELETE">
        <div class="form-text" data-confirm-error></div>
      </div>

      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
          Cancel
        </button>
        <button type="button" class="btn btn-sm btn-danger" data-confirm-run disabled>
          <i class="bi bi-trash3"></i> Delete permanently
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  window.RESET_GROUPS = <?= json_encode($groupMeta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>