<?php
/**
 * Shared page head + topbar.
 *
 * Expects $pageTitle and $pageIcon. $me is pulled from Auth.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';

if (!defined('FLATMATE_ROOT')) {
    exit;
}

$pageTitle = $pageTitle ?? 'FlatMate';
$pageIcon  = $pageIcon  ?? 'bi-house-door';
$pageDesc  = $pageDesc  ?? '';
$bodyClass = $bodyClass ?? '';
$nav       = $nav ?? 'dashboard';
$me        = Auth::user() ?? [];
$isAdmin   = Auth::isAdmin();
$csrf      = csrf_token();

$flashes = take_flashes();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?= e($pageTitle) ?> &middot; <?= e(config('app.name', 'FlatMate')) ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(asset('css/style.css')) ?>" rel="stylesheet">
</head>

<body class="<?= e($bodyClass) ?>"
      data-api-base="<?= e(base_url('api/index.php')) ?>"
      data-csrf="<?= e($csrf) ?>">

<div class="fm-shell">

  <!-- ================= sidebar ================= -->
  <aside class="fm-sidebar" id="fmSidebar">
    <div class="fm-brand">
      <span class="fm-brand-mark"><i class="bi bi-house-heart-fill"></i></span>
      <span class="fm-brand-text">
        FlatMate
        <small><?= e($me['apartment_name'] ?? config('app.name', 'FlatMate')) ?></small>
      </span>
    </div>

    <nav class="fm-nav">
      <div class="fm-nav-label">Everyday</div>

      <a class="fm-nav-link <?= $nav === 'dashboard' ? 'active' : '' ?>" href="<?= e(base_url('index.php')) ?>">
        <i class="bi bi-grid-1x2-fill"></i> Dashboard
      </a>

      <a class="fm-nav-link <?= $nav === 'meals' ? 'active' : '' ?>" href="<?= e(base_url('meals.php')) ?>">
        <i class="bi bi-cup-hot-fill"></i> Meal planner
      </a>

      <a class="fm-nav-link <?= $nav === 'chores' ? 'active' : '' ?>" href="<?= e(base_url('chores.php')) ?>">
        <i class="bi bi-check2-square"></i> Chores
        <span class="fm-count d-none" data-nav-count="chores"></span>
      </a>

      <a class="fm-nav-link <?= $nav === 'expenses' ? 'active' : '' ?>" href="<?= e(base_url('expenses.php')) ?>">
        <i class="bi bi-wallet2"></i> Expenses
      </a>

      <div class="fm-nav-label">Flat</div>

      <a class="fm-nav-link <?= $nav === 'notices' ? 'active' : '' ?>" href="<?= e(base_url('notices.php')) ?>">
        <i class="bi bi-megaphone-fill"></i> Notices
        <span class="fm-count d-none" data-nav-count="notices"></span>
      </a>

      <?php if ($isAdmin): ?>
        <a class="fm-nav-link <?= $nav === 'reports' ? 'active' : '' ?>" href="<?= e(base_url('reports.php')) ?>">
          <i class="bi bi-file-earmark-bar-graph-fill"></i> Reports
        </a>
      <?php endif; ?>

      <a class="fm-nav-link <?= $nav === 'residents' ? 'active' : '' ?>" href="<?= e(base_url('residents.php')) ?>">
        <i class="bi bi-people-fill"></i> Residents
      </a>

      <?php if ($isAdmin): ?>
        <div class="fm-nav-label">Admin</div>
        <a class="fm-nav-link <?= $nav === 'admin' ? 'active' : '' ?>" href="<?= e(base_url('residents.php?tab=offboarding')) ?>">
          <i class="bi bi-person-dash-fill"></i> Offboarding
        </a>
        <a class="fm-nav-link" href="<?= e(base_url('chores.php?tab=areas')) ?>">
          <i class="bi bi-sliders"></i> Rotation rules
        </a>
        <?php if (Auth::can('role.manage')): ?>
          <a class="fm-nav-link <?= $nav === 'roles' ? 'active' : '' ?>" href="<?= e(base_url('roles.php')) ?>">
            <i class="bi bi-person-badge-fill"></i> Roles
          </a>
        <?php endif; ?>
        <?php if (Auth::can('data.purge')): ?>
          <a class="fm-nav-link <?= $nav === 'data_reset' ? 'active' : '' ?>" href="<?= e(base_url('data_reset.php')) ?>">
            <i class="bi bi-eraser-fill"></i> Data reset
          </a>
        <?php endif; ?>
      <?php endif; ?>
    </nav>

    <div class="fm-sidebar-foot">
<div class="d-flex align-items-center gap-2">
          <!-- The identity block is the profile link. Nested inside the flex row
               so the sign-out form below keeps its own place. -->
          <a class="fm-nav-identity d-flex align-items-center gap-2 flex-grow-1 text-decoration-none"
             href="<?= e(base_url('me.php')) ?>"
             title="Your profile">
            <span class="fm-avatar sm" style="background:<?= e($me['avatar_color'] ?? '#64748b') ?>">
              <?= e(initials($me['full_name'] ?? '?')) ?>
            </span>
            <span class="flex-grow-1 fm-truncate">
              <span class="fm-truncate d-block" style="font-size:.82rem;font-weight:600;color:inherit"><?= e($me['full_name'] ?? '') ?></span>
              <span class="text-faint d-block" style="font-size:.7rem">
                <?= e($me['participant_code'] ?? '') ?>
                <?= $isAdmin ? ' &middot; admin' : '' ?>
              </span>
            </span>
          </a>
          <!-- POST-only: a GET sign-out link could be triggered by any page. -->
          <form method="post" action="<?= e(base_url('logout.php')) ?>" class="d-inline">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-outline-secondary" type="submit" title="Sign out">
              <i class="bi bi-box-arrow-right"></i>
            </button>
          </form>
        </div>
    </div>
  </aside>

  <!-- ================= main ================= -->
  <div class="fm-main">

    <header class="fm-topbar">
      <button class="fm-burger" data-nav-toggle aria-label="Open navigation">
        <i class="bi bi-list"></i>
      </button>

      <i class="bi <?= e($pageIcon) ?> text-secondary"></i>
      <span class="fm-topbar-title"><?= e($pageTitle) ?></span>

      <span class="fm-spacer"></span>

      <button class="btn btn-sm btn-outline-secondary position-relative" data-bell
              data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-bell"></i>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger d-none"
              style="font-size:.6rem" data-nav-count="reminders">0</span>
      </button>

      <div class="dropdown-menu dropdown-menu-end fm-card" style="min-width:320px" data-reminder-box>
        <div class="fm-card-head"><h3>Reminders</h3></div>
        <div class="fm-card-body tight" data-reminder-list>
          <div class="text-muted-2 p-3 text-center">Loading…</div>
        </div>
      </div>
    </header>

    <main class="fm-page">

      <?php if ($flashes): ?>
        <?php foreach ($flashes as $f): ?>
          <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show d-flex align-items-center gap-2">
            <i class="bi bi-info-circle-fill"></i>
            <div class="flex-grow-1"><?= e($f['message']) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
