/* ==========================================================================
   FlatMate  |  shared app shell
   --------------------------------------------------------------------------
   Loaded on every authenticated page. Provides:
     * toast notifications
     * the off-canvas nav drawer on small screens
     * modal helpers (Bootstrap 5)
     * confirm-and-act wiring for chore actions
     * error rendering that maps ApiError.details onto form fields
   ========================================================================== */

const App = (() => {
  'use strict';

  /* ---- toasts ---------------------------------------------------------- */
  function toastEl() {
    let host = document.querySelector('.fm-toasts');
    if (!host) {
      host = document.createElement('div');
      host.className = 'fm-toasts';
      host.setAttribute('aria-live', 'polite');
      host.setAttribute('aria-atomic', 'true');
      document.body.appendChild(host);
    }
    return host;
  }

  function toast(message, kind = 'info', ms = 3800) {
    const host = toastEl();
    const el = document.createElement('div');
    el.className = `fm-toast ${kind}`;
    const icon = { ok: 'bi-check-circle-fill', err: 'bi-exclamation-octagon-fill', warn: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' }[kind] || 'bi-info-circle-fill';
    el.innerHTML = `<i class="bi ${icon}"></i><div>${esc(message)}</div>`;
    host.appendChild(el);
    setTimeout(() => {
      el.style.transition = 'opacity .2s';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 220);
    }, ms);
  }

  /* ---- off-canvas navigation ------------------------------------------ */
  function initNav() {
    const burger = document.querySelector('[data-nav-toggle]');
    if (!burger) return;

    burger.addEventListener('click', () => {
      document.body.classList.toggle('fm-nav-open');
    });

    // Tapping the scrim or a link closes the drawer.
    document.addEventListener('click', (ev) => {
      if (!document.body.classList.contains('fm-nav-open')) return;
      if (ev.target.closest('.fm-sidebar') || ev.target.closest('[data-nav-toggle]')) return;
      document.body.classList.remove('fm-nav-open');
    });

    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') document.body.classList.remove('fm-nav-open');
    });

    // Close after navigating (the page will reload anyway, but be safe).
    on(document, 'click', '.fm-nav-link', () => document.body.classList.remove('fm-nav-open'));
  }

  /* ---- modals ---------------------------------------------------------- */
  function openModal(el) {
    if (!el) return;
    const inst = bootstrap.Modal.getOrCreateInstance(el);
    inst.show();
  }

  function closeModal(el) {
    if (!el) return;
    bootstrap.Modal.getOrCreateInstance(el).hide();
  }

  function confirmModal({ title, body, confirmText = 'Confirm', danger = false }) {
    const id = 'fmConfirm';
    let el = document.getElementById(id);
    if (!el) {
      el = document.createElement('div');
      el.id = id;
      el.className = 'modal fade';
      el.tabIndex = -1;
      el.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content fm-card">
            <div class="modal-header">
              <h5 class="modal-title"></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body"></div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" data-confirm></button>
            </div>
          </div>
        </div>`;
      document.body.appendChild(el);
    }
    el.querySelector('.modal-title').textContent = title;
    el.querySelector('.modal-body').innerHTML = body;
    const btn = el.querySelector('[data-confirm]');
    btn.textContent = confirmText;
    btn.className = `btn ${danger ? 'btn-danger' : 'btn-primary'}`;
    openModal(el);
    return el;
  }

  /**
   * Ask for a single line of text (used for skip reasons).
   * Resolves to the string, or null when cancelled.
   */
  function promptModal({ title, label, placeholder = '', confirmText = 'Save' }) {
    const id = 'fmPrompt';
    let el = document.getElementById(id);
    if (!el) {
      el = document.createElement('div');
      el.id = id;
      el.className = 'modal fade';
      el.tabIndex = -1;
      el.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content fm-card">
            <div class="modal-header">
              <h5 class="modal-title"></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <label class="form-label" data-label></label>
              <input type="text" class="form-control" data-input>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" data-confirm></button>
            </div>
          </div>
        </div>`;
      document.body.appendChild(el);
    }
    el.querySelector('.modal-title').textContent = title;
    el.querySelector('[data-label]').textContent = label;
    const input = el.querySelector('[data-input]');
    input.placeholder = placeholder;
    input.value = '';
    el.querySelector('[data-confirm]').textContent = confirmText;

    return new Promise((resolve) => {
      const done = (value) => {
        el.removeEventListener('shown.bs.modal', onShown);
        el.removeEventListener('hidden.bs.modal', onHidden);
        resolve(value);
      };
      const onShown = () => input.focus();
      const onHidden = () => done(null);

      el.addEventListener('shown.bs.modal', onShown, { once: true });
      el.addEventListener('hidden.bs.modal', onHidden, { once: true });
      el.querySelector('[data-confirm]').onclick = () => {
        const value = input.value.trim();
        closeModal(el);
        done(value);
      };
      openModal(el);
    });
  }

  /* ---- form error mapping ---------------------------------------------- */
  function clearErrors(form) {
    form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
    form.querySelectorAll('.invalid-feedback').forEach((el) => el.remove());
  }

  function showErrors(form, details) {
    clearErrors(form);
    let first = null;
    Object.entries(details || {}).forEach(([field, message]) => {
      const input = form.querySelector(`[name="${CSS.escape(field)}"]`);
      const target = input || form.querySelector(`#${CSS.escape(field)}`);
      if (target) {
        target.classList.add('is-invalid');
        const fb = document.createElement('div');
        fb.className = 'invalid-feedback';
        fb.textContent = message;
        target.parentNode.appendChild(fb);
        if (!first) first = target;
      }
    });
    first?.focus();
  }

  /**
   * Wrap an async action so failures always become a toast, and CSRF/session
   * expiry is handled once, centrally.
   */
  async function guard(fn, { onError } = {}) {
    try {
      return await fn();
    } catch (err) {
      if (err instanceof API.ApiError) {
        if (err.code === 'csrf_failed' || err.code === 'unauthenticated') {
          toast('Your session expired — reloading…', 'warn');
          setTimeout(() => window.location.reload(), 1200);
          return undefined;
        }
        if (err.code === 'validation_failed' && err.fields && Object.keys(err.fields).length) {
          toast(Object.values(err.fields)[0], 'err');
        } else {
          toast(err.message, 'err');
        }
      } else {
        console.error(err);
        toast('Something went wrong. Check the console.', 'err');
      }
      onError?.(err);
      return undefined;
    }
  }

  /* ---- boot ------------------------------------------------------------ */
  document.addEventListener('DOMContentLoaded', () => {
    initNav();

    /* Any [data-confirm-text] element asks before running its action.

       This is registered in the CAPTURE phase on purpose. Page controllers
       register their own delegated handlers on document in the bubble phase,
       so an earlier bubble-phase confirm handler would run preventDefault()
       (which stops navigation, not propagation) and the action would still
       fire before the dialog was ever shown.

       On confirm we re-dispatch the click with a one-shot marker attribute.
       The marker is cleared immediately after, because el.click() dispatches
       synchronously -- so a persistent button still asks again next time. */
    document.addEventListener('click', (ev) => {
      const el = ev.target instanceof Element
        ? ev.target.closest('[data-confirm-text]')
        : null;
      if (!el || el.dataset.fmConfirming === '1') return;

      ev.preventDefault();
      ev.stopPropagation();

      confirmModal({
        title: el.dataset.confirmTitle || 'Are you sure?',
        body: el.dataset.confirmText,
        confirmText: el.dataset.confirmOk || 'Confirm',
        danger: el.dataset.confirmDanger === '1',
      }).querySelector('[data-confirm]').onclick = () => {
        if (el.tagName === 'A') {
          window.location.href = el.href;
          return;
        }
        el.dataset.fmConfirming = '1';
        try {
          el.click();
        } finally {
          delete el.dataset.fmConfirming;
        }
      };
    }, true);

    // Bootstrap tooltips, if any are present.
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
      new bootstrap.Tooltip(el);
    });

    /* Adopt the server-configured currency before any page renders money,
       so every Fmt.money() call on this page uses the right symbol. */
    API.get('meta')
      .then((m) => m && Fmt.setCurrency(m.currency))
      .catch(() => { /* keep the built-in default */ });
  });

  return { toast, openModal, closeModal, confirmModal, promptModal, guard, showErrors, clearErrors };
})();