<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Roles
 * ---------------------------------------------------------------------------
 * Make new roles and hand them to residents.
 *
 * Roles are additive: a role describes what somebody may do, while the older
 * users.role column still decides who gets into the admin area at all. A role
 * carrying admin.access promotes somebody to admin; anything else does not.
 * The last active admin cannot be demoted, which RoleService enforces as well
 * as this page advertising.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

require_admin();

/* require_admin() covers "not signed in" and "not an admin". A plain resident
   can still hold role.manage in a flat that allows it, so check the fine-grained
   permission too -- and bounce rather than render an empty page. */
if (!Auth::can('role.manage')) {
    flash('danger', 'Your role does not include managing roles.');
    redirect('index.php');
}

$pageTitle   = 'Roles';
$pageIcon    = 'bi-person-badge-fill';
$nav         = 'roles';
$pageScripts = ['js/roles.js'];

require __DIR__ . '/includes/head.php';
?>

<div class="d-flex flex-wrap align-items-end gap-2 mb-3">
  <div>
    <h1 class="h5 mb-0">Roles</h1>
    <p class="fm-sub text-muted-2 mb-0" style="font-size:.85rem">
      Describe what people can do, then give them one.
    </p>
  </div>
  <div class="ms-auto fm-actions">
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newRole">
      <i class="bi bi-plus-lg"></i> New role
    </button>
  </div>
</div>

<div class="alert alert-info py-2 small">
  <i class="bi bi-info-circle me-1"></i>
  A role with <strong>Admin access</strong> (or <em>Everything</em>) makes somebody an
  admin. A role without it does not, even for someone who is already an admin.
</div>

<div class="row g-3">

  <!-- ================= role catalogue ================= -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header py-2 fw-semibold">
        <i class="bi bi-collection me-1"></i>All roles
        <span class="text-faint fw-normal" style="font-size:.78rem" data-role-count></span>
      </div>
      <div class="card-body py-2" data-role-list>
        <div class="text-center text-faint py-4" style="font-size:.85rem">
          <span class="spinner-border spinner-border-sm me-2"></span>Loading roles...
        </div>
      </div>
    </div>
  </div>

  <!-- ================= assignment ================= -->
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header py-2 fw-semibold">
        <i class="bi bi-person-check me-1"></i>Who has what
      </div>
      <div class="card-body py-2">
        <p class="text-faint mb-2" style="font-size:.78rem">
          Change a person's role straight from the list. The last active admin
          cannot be demoted.
        </p>
        <div data-assign-list>
          <div class="text-center text-faint py-4" style="font-size:.85rem">
            <span class="spinner-border spinner-border-sm me-2"></span>Loading residents...
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- ================= new / edit role ================= -->
<div class="modal fade" id="newRole" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" data-role-modal-title>New role</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form data-role-form novalidate>
        <div class="modal-body">
          <input type="hidden" name="id" value="">

          <div class="row g-3 mb-1">
            <div class="col-md-6">
              <label class="form-label" for="role_name">Name</label>
              <input class="form-control" type="text" id="role_name" name="name"
                     maxlength="60" required placeholder="Weekend cook">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="role_slug">Slug</label>
              <input class="form-control font-monospace" type="text" id="role_slug" name="slug"
                     maxlength="60" placeholder="weekend_cook">
              <div class="form-text">Lowercase letters, digits and underscores.</div>
            </div>
            <div class="col-12">
              <label class="form-label" for="role_description">Description <span class="text-faint">(optional)</span></label>
              <input class="form-control" type="text" id="role_description" name="description"
                     maxlength="255" placeholder="Cooks the weekend meals and confirms the shopping">
            </div>
          </div>

          <hr>

          <div class="d-flex align-items-center justify-content-between mb-2">
            <label class="form-label mb-0 fw-semibold">Permissions</label>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" id="role_everything" data-everything>
              <label class="form-check-label small" for="role_everything">Everything</label>
            </div>
          </div>

          <div class="row g-2" data-permission-grid>
            <!-- rendered by roles.js from the PERMISSION_LABELS below -->
          </div>
          <div class="form-text mt-2">
            Built-in templates are read-only. Copy one into a new role to change it.
          </div>
        </div>

        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-primary" data-role-save>
            <i class="bi bi-check-lg"></i> Save role
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
/* Rendered into the permission grid server-side so the labels live in one
   place and are correct even before roles.js loads. */
$permissionLabels = [
    'admin.access'    => 'Admin access',
    'resident.view'   => 'View residents',
    'resident.manage' => 'Add and edit residents',
    'expense.manage'  => 'Manage expenses',
    'chore.verify'    => 'Verify chores',
    'chore.manage'    => 'Manage the rota',
    'notice.manage'   => 'Post notices',
    'report.view'     => 'View reports',
    'role.manage'     => 'Manage roles',
    'data.purge'      => 'Reset household data',
];
?>
<script>
  window.ROLE_PERMISSIONS = <?= json_encode(
      array_map(
          static fn(string $key, string $label): array => ['key' => $key, 'label' => $label],
          array_keys($permissionLabels),
          $permissionLabels
      ),
      JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
  ) ?>;
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>