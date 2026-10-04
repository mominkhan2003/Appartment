</main>

    <footer class="text-center text-faint pb-4" style="font-size:.75rem">
      <?= e(config('app.name', 'FlatMate')) ?> v<?= e(FLATMATE_VERSION) ?>
      &middot; balances in <?= e(config('app.currency', '\u{20AC}')) ?>
      &middot; <span data-clock></span>
    </footer>
  </div><!-- /.fm-main -->
</div><!-- /.fm-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php /* Diagnostics loads FIRST so it can capture errors from every script
         below it, including the ones that render the page content. */ ?>
<script src="<?= e(asset('js/diagnostics.js')) ?>"></script>
<script src="<?= e(asset('js/api.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach (($pageScripts ?? []) as $script): ?>
  <script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
<script>
  /* Footer clock + reminder bell are shell-level, so every page gets them. */
  document.addEventListener('DOMContentLoaded', function () {
    /* Guarded, and not for tidiness: if diagnostics.js failed to load, an
       unguarded ReferenceError here would abort this whole handler, taking the
       clock and the reminder bell with it. A diagnostics feature that can break
       the app it is diagnosing is worse than no diagnostics. */
    if (typeof Diag !== 'undefined') Diag.mount();

    var clock = document.querySelector('[data-clock]');
    if (clock) {
      var tick = function () {
        clock.textContent = new Date().toLocaleString('en-GB', {
          weekday: 'short', day: 'numeric', month: 'short',
          hour: '2-digit', minute: '2-digit'
        });
      };
      tick();
      setInterval(tick, 30000);
    }

    var badge = document.querySelector('[data-nav-count="reminders"]');
    var box   = document.querySelector('[data-reminder-list]');
    if (!badge) return;

    var loadReminders = function () {
      API.get('reminder.inbox', { limit: 8 }).then(function (rows) {
        var list = rows || [];
        var unread = list.filter(function (r) { return !r.read_at; }).length;
        badge.textContent = unread;
        badge.classList.toggle('d-none', unread === 0);
        if (!box) return;
        box.innerHTML = list.length
          ? list.map(function (r) {
              return '<div class="d-flex gap-2 p-2 rounded' + (r.read_at ? '' : ' bg-light') + '">'
                + '<i class="bi ' + esc(r.icon || 'bi-bell') + ' text-secondary"></i>'
                + '<div class="flex-grow-1" style="min-width:0">'
                + '<div style="font-size:.83rem;font-weight:600">' + esc(r.title) + '</div>'
                + '<div class="text-faint" style="font-size:.72rem">' + esc(Fmt.timeAgo(r.created_at)) + '</div>'
                + '</div></div>';
            }).join('')
          : '<div class="text-muted-2 p-3 text-center" style="font-size:.82rem">Nothing new.</div>';
      }).catch(function (err) {
        /* Silent on purpose: a failing bell should not toast every resident.
           Diag wraps fetch, so this failure is still captured in the report. */
        if (typeof Diag !== 'undefined') Diag.push('reminder_bell', err.message || String(err));
      });
    };

    loadReminders();
    setInterval(loadReminders, 60000);
  });
</script>
</body>
</html>