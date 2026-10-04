/* ==========================================================================
   FlatMate  |  API client
   --------------------------------------------------------------------------
   Thin wrapper over fetch() for api/index.php. Every call returns a plain
   object; failures reject with an Error carrying .code and .details so the
   caller can map field-level messages onto form inputs.

   Auth is the session cookie (credentials: 'same-origin'). Mutating calls
   send the CSRF token, which the server bootstraps into the page.
   ========================================================================== */

const API = (() => {
  'use strict';

  const ENDPOINT = 'api/index.php';
  const base = document.body?.dataset.apiBase || ENDPOINT;

  /* ---- CSRF token, injected by PHP into <body data-csrf="..."> ---------- */
  let csrf = document.body?.dataset.csrf || '';

  function token() {
    return csrf;
  }

  async function refreshToken() {
    try {
      const res = await fetch(`${base}?action=auth.csrf`, { credentials: 'same-origin' });
      const json = await res.json();
      if (json?.data?.token) {
        csrf = json.data.token;
        return csrf;
      }
    } catch (_) { /* keep the old token; the server will reject and we retry */ }
    return csrf;
  }

  class ApiError extends Error {
    constructor(message, { code = 'error', status = 0, details = {} } = {}) {
      super(message);
      this.name = 'ApiError';
      this.code = code;
      this.status = status;
      this.details = details || {};
    }

    /** Field name -> message, for form rendering. */
    get fields() {
      return this.details;
    }
  }

  function buildUrl(action, params) {
    const url = new URL(base, window.location.href);
    url.searchParams.set('action', action);
    if (params) {
      Object.entries(params).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') {
          url.searchParams.set(k, v);
        }
      });
    }
    return url.toString();
  }

  async function request(action, { method = 'GET', params = null, body = null } = {}) {
    const isPost = method.toUpperCase() === 'POST';
    const url = buildUrl(action, isPost ? null : params);

    const init = {
      method: isPost ? 'POST' : method.toUpperCase(),
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    };

    if (isPost) {
      init.headers['Content-Type'] = 'application/json';
      init.headers['X-CSRF-Token'] = token();
      init.body = JSON.stringify({ ...(params || {}), ...(body || {}) });

      // One transparent retry if the token went stale mid-session.
      if (csrf) {
        const attempt = await send(url, init);
        if (attempt.status === 419) {
          await refreshToken();
          init.headers['X-CSRF-Token'] = token();
          return send(url, init);
        }
        return attempt.payload;
      }
      await refreshToken();
      init.headers['X-CSRF-Token'] = token();
    }

    return (await send(url, init)).payload;
  }

  async function send(url, init) {
    let res;
    try {
      res = await fetch(url, init);
    } catch (networkErr) {
      throw new ApiError('Cannot reach the server. Check your connection.', {
        code: 'network_error',
      });
    }

    let json = null;
    try {
      json = await res.json();
    } catch (_) {
      json = null;
    }

    if (!res.ok || !json || json.ok === false) {
      const err = json?.error || {};
      throw new ApiError(err.message || `Request failed (${res.status})`, {
        code: err.code || 'http_error',
        status: res.status,
        details: err.details || {},
      });
    }

    return { status: res.status, payload: json.data ?? json };
  }

  /* ---- Public surface -------------------------------------------------- */
  return {
    ApiError,
    token,
    refreshToken,

    /** GET */
    get: (action, params) => request(action, { params }),
    /** POST with a JSON body */
    post: (action, body) => request(action, { method: 'POST', body }),

    // --- convenience aliases for the most-used endpoints ---
    me:        ()            => request('auth.me'),
    meta:      ()            => request('meta'),
    dashboard: ()            => request('dashboard'),
    health:    ()            => request('health'),
    selfTest:  (action, p)   => request(`${action}`, { params: { ...(p || {}), verify: 1 } }),

    choreBoard:   (from, to) => request('chore.board', { params: { from, to } }),
    choreMine:    (days)     => request('chore.mine', { params: { days } }),
    choreComplete:(id, note) => request('chore.complete', { method: 'POST', body: { task_id: id, note } }),
    choreVerify:  (id, note) => request('chore.verify',   { method: 'POST', body: { task_id: id, note } }),
    choreSkip:    (id, why)  => request('chore.skip',     { method: 'POST', body: { task_id: id, reason: why } }),

    mealWeek:     (date)     => request('meal.week', { params: { date } }),
    mealVote:     (id, v)    => request('meal.vote', { method: 'POST', body: { suggestion_id: id, vote: v } }),
    mealRespond:  (id, s)    => request('meal.respond', { method: 'POST', body: { meal_id: id, status: s } }),
    mealBulk:     (s, d)     => request('meal.bulk_respond', { method: 'POST', body: { status: s, date: d } }),

    expenses:     (f)        => request('expense.list', { params: f }),
    expenseCreate:(b)        => request('expense.create', { method: 'POST', body: b }),
    settle:       (b)        => request('balance.settle', { method: 'POST', body: b }),
    ledger:       (s)        => request('balance.ledger', { params: { strategy: s } }),
  };
})();

/* ==========================================================================
   Small formatting / DOM helpers shared by every page.
   ========================================================================== */
const Fmt = {
  /* Set from the server's `meta` response so the symbol is never hardcoded
     here. Falls back to the euro sign the app ships with. */
  currency: '\u20AC',

  /** Adopt the currency the server is configured for. */
  setCurrency(symbol) {
    if (typeof symbol === 'string' && symbol.trim()) this.currency = symbol.trim();
    return this.currency;
  },

  /** 1234 -> "€ 1,234.00" */
  money(cents, { sign = false, symbol } = {}) {
    const n = (Number(cents) || 0) / 100;
    const s = Math.abs(n).toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
    const prefix = n < 0 ? '-' : (sign && n > 0 ? '+' : '');
    return `${prefix}${symbol || Fmt.currency} ${s}`;
  },

  /** Compact form for stat tiles: € 12.4k */
  moneyShort(cents) {
    const n = Math.abs(Number(cents) || 0) / 100;
    if (n >= 100000) return `${Fmt.currency} ${(n / 100000).toFixed(1)}L`;
    if (n >= 1000)   return `${Fmt.currency} ${(n / 1000).toFixed(1)}k`;
    return Fmt.money(cents);
  },

  date(iso, opts = { day: 'numeric', month: 'short' }) {
    if (!iso) return '';
    const d = new Date(`${String(iso).slice(0, 10)}T00:00:00`);
    return isNaN(d) ? '' : d.toLocaleDateString('en-GB', opts);
  },

  dateLong(iso) {
    return Fmt.date(iso, { weekday: 'long', day: 'numeric', month: 'long' });
  },

  timeAgo(iso) {
    if (!iso) return '';
    const then = new Date(String(iso).replace(' ', 'T') + (String(iso).includes('Z') ? '' : 'Z'));
    const secs = Math.floor((Date.now() - then.getTime()) / 1000);
    if (isNaN(secs)) return '';
    const table = [
      [60, 'sec'], [60, 'min'], [24, 'hr'], [7, 'day'], [4.35, 'wk'], [12, 'mo'],
    ];
    let value = secs;
    for (const [step, unit] of table) {
      if (value < step) return `${Math.floor(value)}${unit} ago`;
      value /= step;
    }
    return `${Math.floor(value)}y ago`;
  },

  initials(name) {
    return String(name || '?')
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((p) => p[0]?.toUpperCase() ?? '')
      .join('');
  },

  plural(n, one, many) {
    return `${n} ${n === 1 ? one : (many || `${one}s`)}`;
  },
};

/** Escape before any interpolation into innerHTML. */
function esc(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** Delegated event binding: on(root, 'click', '[data-act]', handler). */
function on(root, type, selector, handler) {
  root.addEventListener(type, (ev) => {
    const target = ev.target.closest(selector);
    if (target && root.contains(target)) handler(ev, target);
  });
}