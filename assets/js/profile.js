/* ==========================================================================
   FlatMate  |  My profile
   --------------------------------------------------------------------------
   Two independent forms on one page: details (self-service) and password.

   The page is rendered server-side from the current database row, so it is
   already correct before this file runs. Everything here is about saving
   without a reload and keeping the sidebar identity in step afterwards.
   ========================================================================== */

(() => {
  'use strict';

  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  /* Mirrors Auth::validatePassword(). The server stays the authority -- this
     only avoids a pointless round trip and gives instant feedback. */
  const PW_RULES = [
    { test: (v) => v.length >= 8,       text: 'at least 8 characters' },
    { test: (v) => /[A-Za-z]/.test(v), text: 'one letter' },
    { test: (v) => /\d/.test(v),       text: 'one number' },
  ];

  /* ------------------------------------------------------------------ */
  /*  Shared helpers                                                      */
  /* ------------------------------------------------------------------ */

  /** Repaint avatar + name everywhere they appear after a save. */
  function paintIdentity(user) {
    if (!user) return;

    const name = user.full_name || '';
    const init = user.initials
      || name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
    const colour = user.avatar_color || '#64748b';

    /* This page's own card. */
    const av = $('[data-avatar]');
    if (av) {
      av.style.background = colour;
      av.textContent = init;
    }
    const nameEl = $('[data-avatar-name]');
    if (nameEl) nameEl.textContent = name;

    /* Sidebar avatar(s) and the identity line above the sign-out button. */
    $$('.fm-avatar:not([data-avatar])').forEach((el) => {
      el.style.background = colour;
      el.textContent = init;
    });
    const footName = $('.fm-sidebar-foot .fm-truncate > span');
    if (footName) footName.textContent = name;
  }

  /** Colour input + hex label, kept in step. */
  function wireColourPicker() {
    const input = $('#avatar_color');
    const label = $('[data-colour-label]');
    if (!input || !label) return;

    const sync = () => { label.textContent = input.value; };
    input.addEventListener('input', sync);
    sync();
  }

  /** Disable a submit button for the life of one request. */
  function withBusy(button, fn) {
    if (!button) return fn();
    const label = button.innerHTML;
    button.disabled = true;
    return Promise.resolve()
      .then(fn)
      .finally(() => {
        button.disabled = false;
        button.innerHTML = label;
      });
  }

  /* ------------------------------------------------------------------ */
  /*  Details form                                                        */
  /* ------------------------------------------------------------------ */

  function wireDetails() {
    const form = $('[data-profile-form]');
    if (!form) return;

    const email  = $('#email', form);
    const proof  = $('[data-email-proof]', form);
    const button = $('[data-save]', form);

    /* Mutable on purpose: after a successful save the "original" email becomes
       whatever was just sent, so the proof box stops demanding a password. */
    let originalEmail = (email?.value || '').trim().toLowerCase();

    function syncEmailProof() {
      if (!email || !proof) return;
      const changed = (email.value || '').trim().toLowerCase() !== originalEmail;
      proof.classList.toggle('d-none', !changed);
      if (!changed) {
        const box = $('#current_password', form);
        if (box) box.value = '';
      }
    }
    email?.addEventListener('input', syncEmailProof);
    syncEmailProof();

    /* Discard is a native reset, so restore the proof box afterwards. */
    $('[data-reload]', form)?.addEventListener('click', () => {
      setTimeout(syncEmailProof, 0);
    });

    form.addEventListener('submit', (ev) => {
      ev.preventDefault();
      App.clearErrors(form);

      const payload = {
        full_name:      $('#full_name', form).value.trim(),
        phone:          $('#phone', form).value.trim(),
        bio:            $('#bio', form).value.trim(),
        favourite_food: $('#favourite_food', form).value.trim(),
        avatar_color:   $('#avatar_color', form).value,
      };

      if (email) {
        payload.email = email.value.trim();
        if (payload.email.toLowerCase() !== originalEmail) {
          payload.current_password = $('#current_password', form).value;
        }
      }

      /* App.guard owns the toast and the session-expiry reload; onError only
         has to add the part it cannot do, which is per-field highlighting. */
      App.guard(() => withBusy(button, () => API.post('me.update', payload).then((user) => {
        /* Repaint from the saved row rather than from what we sent: the server
           trims and normalises, and the sidebar should show what is stored. */
        paintIdentity(user);
        App.toast('Profile saved.', 'ok');

        if (user?.email) originalEmail = user.email.trim().toLowerCase();
        form.reset();
        wireColourPicker();
        syncEmailProof();
      })), {
        onError: (err) => {
          if (err instanceof API.ApiError && err.fields) {
            App.showErrors(form, err.fields);
          }
        },
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /*  Password form                                                       */
  /* ------------------------------------------------------------------ */

  function wirePassword() {
    const form = $('[data-password-form]');
    if (!form) return;

    const cur    = $('#pw_current', form);
    const next   = $('#pw_new', form);
    const conf   = $('#pw_confirm', form);
    const button = $('button[type=submit]', form);
    const rules  = $('[data-pw-rules]', form);

    function paintRules() {
      if (!rules) return;
      const v = next?.value || '';
      const missing = PW_RULES.filter((r) => !r.test(v));
      rules.textContent = v === ''
        ? `Needs ${PW_RULES.map((r) => r.text).join(', ')}.`
        : (missing.length
            ? `Still needs ${missing.map((r) => r.text).join(', ')}.`
            : 'Looks good.');
      rules.classList.toggle('text-success', v !== '' && missing.length === 0);
    }

    function checkMatch() {
      const bad = (conf?.value || '') !== '' && conf.value !== next.value;
      conf?.classList.toggle('is-invalid', bad);
      return !bad;
    }

    next?.addEventListener('input', () => { paintRules(); checkMatch(); });
    conf?.addEventListener('input', checkMatch);
    paintRules();

    form.addEventListener('submit', (ev) => {
      ev.preventDefault();
      App.clearErrors(form);

      if (!checkMatch()) {
        App.toast('The two new passwords do not match.', 'err');
        return;
      }

      /* App.guard owns the toast and the session-expiry reload; onError only
         has to add the part it cannot do, which is per-field highlighting. */
      App.guard(() => withBusy(button, () => API.post('me.password', {
        current_password: cur.value,
        new_password:     next.value,
      }).then(() => {
        App.toast('Password changed. Other devices were signed out.', 'ok');
        form.reset();
        paintRules();
      })), {
        onError: (err) => {
          if (err instanceof API.ApiError && err.fields) {
            App.showErrors(form, err.fields);
          }
        },
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /*  Boot                                                                */
  /* ------------------------------------------------------------------ */

  document.addEventListener('DOMContentLoaded', () => {
    wireColourPicker();
    wireDetails();
    wirePassword();
  });
})();