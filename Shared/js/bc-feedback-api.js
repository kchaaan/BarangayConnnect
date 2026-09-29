/**
 * PHP feedback API (XAMPP) — residents submit; admin view-only.
 */
(function (global) {
  const XAMPP_HTTP_BASE =
    'http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/';

  function isFileProtocol() {
    return global.location.protocol === 'file:';
  }

  function phpFile(fromHref) {
    if (isFileProtocol()) {
      return XAMPP_HTTP_BASE + 'FrontPage/php/feedback_api.php';
    }
    return new URL('../../FrontPage/php/feedback_api.php', fromHref || global.location.href).href;
  }

  function apiUrl(action, params, fromHref) {
    const u = new URL(phpFile(fromHref));
    u.searchParams.set('action', action);
    if (params) {
      Object.keys(params).forEach(function (key) {
        if (params[key] != null && params[key] !== '') {
          u.searchParams.set(key, String(params[key]));
        }
      });
    }
    return u.href;
  }

  async function fetchAll(fromHref) {
    const res = await fetch(apiUrl('list', null, fromHref));
    if (!res.ok) {
      throw new Error('Failed to load feedback (' + res.status + ')');
    }
    return res.json();
  }

  async function fetchOne(id, fromHref) {
    const res = await fetch(apiUrl('get', { id: id }, fromHref));
    if (!res.ok) {
      throw new Error('Failed to load feedback (' + res.status + ')');
    }
    return res.json();
  }

  async function create(body, fromHref) {
    const res = await fetch(apiUrl('create', null, fromHref), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      const err = await res.json().catch(function () {
        return {};
      });
      throw new Error(err.error || 'Failed to submit feedback (' + res.status + ')');
    }
    return res.json();
  }

  async function markRead(id, fromHref) {
    const res = await fetch(apiUrl('read', { id: id }, fromHref), { method: 'PUT' });
    if (!res.ok) {
      throw new Error('Failed to mark feedback as read (' + res.status + ')');
    }
    return res.json();
  }

  global.BarangayConnectFeedbackApi = {
    XAMPP_HTTP_BASE,
    isFileProtocol,
    apiUrl,
    fetchAll,
    fetchOne,
    create,
    markRead,
  };
})(window);
