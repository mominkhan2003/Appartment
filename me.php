<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  My profile
 * ---------------------------------------------------------------------------
 * Self-service account page: the things a resident may change about
 * themselves, and nothing else.
 *
 * Room, duty group, status and role are deliberately absent. Those are admin
 * decisions, and putting them here would only create a second, weaker path to
 * them. Residents who need one of those changed ask an admin.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

require_login();

/* Authoritative read rather than the session copy: the session may predate a
   change made on another device. */
$profile = ResidentService::find(Auth::apartmentId(), (int) Auth::id());

$pageTitle  = 'My profile';
$pageIcon   = 'bi-person-circle';
$nav        = 'profile';
$pageScripts = ['js/profile.js'];

require __DIR__ . '/includes/head.php';
?>

<div class="d-flex flex-wrap align-items-end gap-2 mb-3">
  <div>
    <h1 class="h5 mb-0">My profile</h1>
    <p class="fm-sub text-muted-2 mb-0" style="font-size:.85rem">
      How you show up to the rest of the flat.
    </p>
  </div>
</div>

<div class="row g-3">

  <!-- ================= identity card ================= -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body text-center">
        <span class="fm-avatar mx-auto mb-2 d-flex align-items-center justify-content-center"
              style="width:84px;height:84px;font-size:1.7rem;background:<?= e($profile['avatar_color'] ?? '#64748b') ?>"
              data-avatar>
          <?= e($profile['initials'] ?? initials($profile['full_name'] ?? '?')) ?>
        </span>

        <div class="fw-semibold" data-avatar-name><?= e($profile['full_name'] ?? '') ?></div>
        <div class="text-faint" style="font-size:.78rem"><?= e($profile['email'] ?? '') ?></div>

        <div class="d-flex justify-content-center gap-2 mt-2 flex-wrap">
          <?php if (!empty($profile['room_code'])): ?>
            <span class="badge text-bg-light text-dark"><?= e($profile['room_code']) ?></span>
          <?php endif; ?>
          <?php if (!empty($profile['duty_group_name'])): ?>
            <span class="badge text-bg-light text-dark"><?= e($profile['duty_group_name']) ?></span>
          <?php endif; ?>
          <span class="badge text-bg-light text-dark"><?= e($profile['participant_code'] ?? '') ?></span>
        </div>

        <hr>

        <div class="d-flex justify-content-between small">
          <span class="text-faint">You are owed</span>
          <span class="fw-semibold text-success"><?= e(Money::format((int) ($profile['balance']['net_cents'] ?? 0))) ?></span>
        </div>
        <div class="d-flex justify-content-between small mt-1">
          <span class="text-faint">Status</span>
          <span class="fw-semibold"><?= e(ucfirst((string) ($profile['status'] ?? 'active'))) ?></span>
        </div>
        <div class="d-flex justify-content-between small mt-1">
          <span class="text-faint">Account type</span>
          <span class="fw-semibold"><?= e(ucfirst((string) ($profile['role'] ?? 'resident'))) ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- ================= details ================= -->
  <div class="col-lg-8">

    <div class="card mb-3">
      <div class="card-header py-2 fw-semibold">
        <i class="bi bi-pencil-square me-1"></i>Details
      </div>
      <div class="card-body">
        <form data-profile-form novalidate>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="full_name">Full name</label>
              <input class="form-control" type="text" id="full_name" name="full_name"
                     maxlength="120" required autocomplete="name"
                     value="<?= e($profile['full_name'] ?? '') ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="phone">Phone <span class="text-faint">(optional)</span></label>
              <input class="form-control" type="tel" id="phone" name="phone"
                     maxlength="32" autocomplete="tel"
                     value="<?= e($profile['phone'] ?? '') ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="email">Email</label>
              <input class="form-control" type="email" id="email" name="email"
                     autocomplete="email" value="<?= e($profile['email'] ?? '') ?>">
              <div class="form-text">
                This is your sign-in address. Changing it signs out your
                remembered devices.
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="avatar_color">Avatar colour</label>
              <div class="input-group">
                <input class="form-control form-control-color" type="color" id="avatar_color"
                       name="avatar_color" value="<?= e($profile['avatar_color'] ?? '#64748b') ?>">
                <span class="input-group-text font-monospace" style="font-size:.78rem"
                      data-colour-label><?= e($profile['avatar_color'] ?? '#64748b') ?></span>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label" for="favourite_food">Favourite food <span class="text-faint">(optional)</span></label>
              <input class="form-control" type="text" id="favourite_food" name="favourite_food"
                     maxlength="120" value="<?= e($profile['favourite_food'] ?? '') ?>">
            </div>

            <div class="col-12">
              <label class="form-label" for="bio">Bio <span class="text-faint">(optional)</span></label>
              <textarea class="form-control" id="bio" name="bio" rows="3"
                        maxlength="255"><?= e($profile['bio'] ?? '') ?></textarea>
            </div>

            <!-- Only demanded when the email actually changes. -->
            <div class="col-12 d-none" data-email-proof>
              <div class="alert alert-warning py-2 mb-0 small">
                <i class="bi bi-shield-lock me-1"></i>
                You are changing your sign-in email. Confirm your current
                password to continue.
                <input class="form-control mt-2" type="password" id="current_password"
                       name="current_password" autocomplete="current-password"
                       placeholder="Current password">
              </div>
            </div>
          </div>

          <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary btn-sm" type="submit" data-save>
              <i class="bi bi-check-lg"></i> Save changes
            </button>
            <button class="btn btn-outline-secondary btn-sm" type="reset" data-reload>
              Discard
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ================= password ================= -->
    <div class="card">
      <div class="card-header py-2 fw-semibold">
        <i class="bi bi-key me-1"></i>Password
      </div>
      <div class="card-body">
        <?php if (empty($profile['has_password'])): ?>
          <p class="text-faint mb-0" style="font-size:.85rem">
            <i class="bi bi-info-circle me-1"></i>
            You sign in with a one-time link, so there is no password to change.
          </p>
        <?php else: ?>
          <form data-password-form novalidate>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label" for="pw_current">Current password</label>
                <input class="form-control" type="password" id="pw_current"
                       name="current_password" autocomplete="current-password" required>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="pw_new">New password</label>
                <input class="form-control" type="password" id="pw_new"
                       name="new_password" autocomplete="new-password" required>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="pw_confirm">Repeat new password</label>
                <input class="form-control" type="password" id="pw_confirm"
                       name="confirm_password" autocomplete="new-password" required>
                <div class="invalid-feedback" data-pw-mismatch>These do not match.</div>
              </div>
            </div>
            <div class="form-text mt-2" data-pw-rules></div>
            <button class="btn btn-outline-primary btn-sm mt-3" type="submit">
              <i class="bi bi-shield-check"></i> Change password
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>