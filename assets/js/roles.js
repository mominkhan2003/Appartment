/* ==========================================================================
   FlatMate  |  Roles
   --------------------------------------------------------------------------
   Two panels sharing one load: the catalogue of roles, and the list of people
   with the role each of them currently holds.

   Server-side render is not an option here -- roles live in a table that did
   not exist until the migration ran, and the whole point of this page is to be
   useful immediately after that. So it fetches, and degrades to an explanation
   if the schema is missing.
   ========================================================================== */

(() => {
  'use strict';

  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const PERMS = window.ROLE_PERMISSIONS || [];

  let roles = [];
  let users = [];

  const roleById = (id) => roles.find((r) => Number(r.id) === Number(id)) || null;

  /* ------------------------------------------------------------------ */
  /*  Small render helpers                                                */
  /* ------------------------------------------------------------------ */

  function esc(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function avatar(user) {
    const name = user.full_name || '?';
    const init = name.split(/\s+/).filter(Boolean).slice(0, 2)
      .map((w) => w[0]).join('').toUpperCase();
    return `<span class="fm-avatar sm" style="background:${esc(user.avatar_color || '#64748b')}">`
         + `${esc(init)}</span>`;
  }

  /** "Everything", or a short list of labels. */
  function summarise(role) {
    if (role.is_wildcard) return 'Everything';
    const labels = role.labels || [];
    if (!labels.length) return 'No permissions';
    const shown = labels.slice(0, 3).join(', ');
    return labels.length > 3 ? `${shown} +${labels.length - 3} more` : shown;
  }

  function emptyState(container, html) {
    container.innerHTML = `<div class="text-center text-faint py-4" style="font-size:.85rem">${html}</div>`;
  }

  /* ------------------------------------------------------------------ */
  /*  Catalogue                                                          */
  /* ------------------------------------------------------------------ */

  function renderRoles() {
    const box = $('[data-role-list]');
    if (!box) return;

    const count = $('[data-role-count]');
    if (count) count.textContent = roles.length ? `${roles.length} total` : '';

    if (!roles.length) {
      emptyState(box, 'No roles yet. Create one to get started.');
      return;
    }

    box.innerHTML = roles.map((role) => {
      const tags = [
        role.is_global ? '<span class="badge text-bg-secondary">Built-in</span>' : '',
        role.is_wildcard ? '<span class="badge text-bg-danger">Everything</span>' : '',
        Number(role.member_count || 0) > 0
          ? `<span class="badge text-bg-light text-dark">${Number(role.member_count)}</span>` : '',
      ].filter(Boolean).join(' ');

      return `
        <div class="d-flex align-items-start gap-2 py-2 border-bottom">
          <div class="flex-grow-1" style="min-width:0">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="fw-semibold" style="font-size:.88rem">${esc(role.name)}</span>
              <code class="text-faint" style="font-size:.72rem">${esc(role.slug)}</code>
              ${tags}
            </div>
            <div class="text-faint" style="font-size:.76rem">${esc(summarise(role))}</div>
            ${role.description ? `<div style="font-size:.78rem">${esc(role.description)}</div>` : ''}
          </div>
          <div class="d-flex gap-1 flex-shrink-0">
            ${role.editable
              ? `<button class="btn btn-sm btn-outline-secondary" data-edit="${esc(role.id)}"
                       title="Edit role"><i class="bi bi-pencil"></i></button>`
              : ''}
            ${role.editable
              ? `<button class="btn btn-sm btn-outline-danger" data-delete="${esc(role.id)}"
                       title="Delete role"><i class="bi bi-trash"></i></button>`
              : ''}
          </div>
        </div>`;
    }).join('');
  }

  /* ------------------------------------------------------------------ */
  /*  Assignment list                                                    */
  /* ------------------------------------------------------------------ */

  function renderUsers() {
    const box = $('[data-assign-list]');
    if (!box) return;

    if (!users.length) {
      emptyState(box, 'No residents to assign yet.');
      return;
    }

    box.innerHTML = users.map((user) => {
      const current = user.role_id ? roleById(user.role_id) : null;
      const options = [
        `<option value="">${user.role === 'admin' ? 'Admin (no role)' : 'No role'}</option>`,
        ...roles.map((r) => {
          const selected = Number(r.id) === Number(user.role_id) ? ' selected' : '';
          return `<option value="${esc(r.id)}"${selected}>${esc(r.name)}</option>`;
        }),
      ].join('');

      const badge = user.role === 'admin'
        ? '<span class="badge text-bg-warning text-dark">admin</span>'
        : '';

      return `
        <div class="d-flex align-items-center gap-2 py-2 border-bottom">
          ${avatar(user)}
          <div class="flex-grow-1" style="min-width:0">
            <div class="d-flex align-items-center gap-1">
              <span class="fm-truncate" style="font-size:.84rem;font-weight:600">${esc(user.full_name)}</span>
              ${badge}
            </div>
            <div class="text-faint" style="font-size:.72rem">
              ${esc(user.room_code || user.participant_code || '')}
              ${current ? ` &middot; ${esc(summarise(current))}` : ''}
            </div>
          </div>
          <select class="form-select form-select-sm" style="max-width:170px"
                  data-assign="${esc(user.id)}" aria-label="Role for ${esc(user.full_name)}">
            ${options}
          </select>
        </div>`;
    }).join('');
  }

  /* ------------------------------------------------------------------ */
  /*  Permission grid                                                    */
  /* ------------------------------------------------------------------ */

  function renderPermissionGrid(selected = []) {
    const grid = $('[data-permission-grid]');
    if (!grid) return;
    const on = new Set(selected);

    grid.innerHTML = PERMS.map((p) => `
      <div class="col-sm-6 col-lg-4">
        <div class="form-check border rounded px-2 py-1 h-100">
          <input class="form-check-input" type="checkbox" name="permissions[]"
                 value="${esc(p.key)}" id="perm_${esc(p.key)}"${on.has(p.key) ? ' checked' : ''}>
          <label class="form-check-label" for="perm_${esc(p.key)}" style="font-size:.8rem">
            ${esc(p.label)}
          </label>
        </div>
      </div>`).join('');
  }

  /* ------------------------------------------------------------------ */
  /*  Modal: create / edit                                               */
  /* ------------------------------------------------------------------ */

  function openModal(role) {
    const form    = $('[data-role-form]');
    const title   = $('[data-role-modal-title]');
    const every   = $('[data-everything]');
    if (!form) return;

    App.clearErrors(form);
    form.reset();
    form.querySelector('[name=id]').value = role?.id ?? '';
    form.querySelector('[name=name]').value = role?.name ?? '';
    form.querySelector('[name=slug]').value = role?.slug ?? '';
    form.querySelector('[name=description]').value = role?.description ?? '';

    if (title) title.textContent = role ? `Edit ${role.name}` : 'New role';

    const perms = role ? (role.permissions || []).filter((p) => p !== '*') : [];
    renderPermissionGrid(perms);
    if (every) every.checked = Boolean(role?.is_wildcard);

    /* Slug is derived from the name only while creating; editing keeps whatever
       is stored so an existing role never changes identity under its feet. */
    const slug = form.querySelector('[name=slug]');
    const name = form.querySelector('[name=name]');
    slug.readOnly = Boolean(role);
    if (!role) {
      name.addEventListener('input', () => {
        slug.value = name.value.toLowerCase()
          .replace(/[^a-z0-9]+/g, '_')
          .replace(/^_+|_+$/g, '')
          .slice(0, 60);
      });
    }

    bootstrap.Modal.getOrCreateInstance($('#newRole')).show();
  }

  function wireForm() {
    const form  = $('[data-role-form]');
    const every = $('[data-everything]');
    if (!form) return;

    /* "Everything" supersedes the individual boxes, so grey them out rather
       than leaving contradictory ticks on screen. */
    every?.addEventListener('change', () => {
      $$('[name="permissions[]"]', form).forEach((box) => {
        box.disabled = every.checked;
        if (every.checked) box.checked = false;
      });
    });

    form.addEventListener('submit', (ev) => {
      ev.preventDefault();
      App.clearErrors(form);

      const id = form.querySelector('[name=id]').value;
      const permissions = every?.checked
        ? ['*']
        : $$('[name="permissions[]"]', form).filter((b) => b.checked).map((b) => b.value);

      const payload = {
        name:        form.querySelector('[name=name]').value.trim(),
        description: form.querySelector('[name=description]').value.trim(),
        permissions,
      };
      /* Slug is fixed once a role exists. */
      if (!id) payload.slug = form.querySelector('[name=slug]').value.trim();

      const button = $('[data-role-save]', form);
      const label  = button.innerHTML;
      button.disabled = true;

      App.guard(() => API.post(id ? 'role.update' : 'role.create', { ...payload, id })
        .then(() => {
          App.toast(id ? 'Role updated.' : 'Role created.', 'ok');
          bootstrap.Modal.getOrCreateInstance($('#newRole')).hide();
          return load();
        })
        .finally(() => {
          button.disabled = false;
          button.innerHTML = label;
        }), {
        onError: (err) => {
          if (err instanceof API.ApiError && err.fields) {
            App.showErrors(form, err.fields);
          }
        },
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /*  Wiring                                                             */
  /* ------------------------------------------------------------------ */

  function wireCatalogue() {
    $('[data-role-list]')?.addEventListener('click', (ev) => {
      const edit = ev.target.closest('[data-edit]');
      if (edit) {
        openModal(roleById(edit.dataset.edit));
        return;
      }

      const del = ev.target.closest('[data-delete]');
      if (!del) return;

      const role = roleById(del.dataset.delete);
      if (!role) return;

      /* confirmModal() renders the dialog and hands back the element; the caller
         owns the confirm button. Same shape app.js uses internally. */
      App.confirmModal({
        title: `Delete "${role.name}"?`,
        body: Number(role.member_count || 0) > 0
          ? `${role.member_count} resident(s) currently hold this role. They will be left without one.`
          : 'Nobody holds this role.',
        confirmText: 'Delete role',
        danger: true,
      }).querySelector('[data-confirm]').onclick = () => {
        App.guard(() => API.post('role.delete', { id: role.id }).then(() => {
          App.toast('Role deleted.', 'ok');
          return load();
        }));
      };
    });

    /* The header button opens the modal blank. */
    $('[data-bs-target="#newRole"]')?.addEventListener('click', () => openModal(null));
  }

  function wireAssignments() {
    $('[data-assign-list]')?.addEventListener('change', (ev) => {
      const select = ev.target.closest('[data-assign]');
      if (!select) return;

      const userId = Number(select.dataset.assign);
      const roleId = select.value ? Number(select.value) : null;
      const person = users.find((u) => Number(u.id) === userId);
      const role   = roleById(roleId);
      const previous = select.value;

      App.guard(() => API.post('role.assign', { user_id: userId, role_id: roleId })
        .then(() => {
          App.toast(
            role
              ? `${person?.full_name || 'Resident'} is now ${role.name}.`
              : `Removed the role from ${person?.full_name || 'that resident'}.`,
            'ok'
          );
          return load();
        }), {
        onError: () => {
          /* The server is the authority on the last-admin rule, so put the
             control back the way it was instead of leaving a lie on screen. */
          select.value = previous;
        },
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /*  Load                                                               */
  /* ------------------------------------------------------------------ */

  async function load() {
    try {
      const [roleData, userData] = await Promise.all([
        API.get('role.list'),
        API.get('role.users'),
      ]);
      roles = roleData?.roles || [];
      users = userData?.users || [];
      renderRoles();
      renderUsers();
    } catch (err) {
      const msg = err instanceof API.ApiError && err.fields?.schema
        ? err.fields.schema
        : 'Could not load roles.';
      emptyState($('[data-role-list]'), esc(msg));
      emptyState($('[data-assign-list]'), esc(msg));
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    renderPermissionGrid();
    wireForm();
    wireCatalogue();
    wireAssignments();
    load();
  });
})();