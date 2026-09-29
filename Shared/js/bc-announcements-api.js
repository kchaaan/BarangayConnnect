/**
 * PHP announcements API (XAMPP) — admin, resident, and front page.
 */
(function (global) {
  const XAMPP_HTTP_BASE =
    'http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/';

  function isFileProtocol() {
    return global.location.protocol === 'file:';
  }

  function phpFile(fromHref) {
    if (isFileProtocol()) {
      return XAMPP_HTTP_BASE + 'FrontPage/php/announcements_api.php';
    }
    return new URL('../../FrontPage/php/announcements_api.php', fromHref || global.location.href).href;
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
      throw new Error('Failed to load announcements (' + res.status + ')');
    }
    return res.json();
  }

  async function fetchLatest(limit, fromHref) {
    const res = await fetch(apiUrl('latest', { limit: limit || 5 }, fromHref));
    if (!res.ok) {
      throw new Error('Failed to load latest announcements (' + res.status + ')');
    }
    return res.json();
  }

  async function fetchPublished(fromHref) {
    const all = await fetchAll(fromHref);
    return (Array.isArray(all) ? all : []).filter(function (a) {
      return String(a.status || '').toLowerCase() === 'published';
    });
  }

  async function create(body, fromHref) {
    const res = await fetch(apiUrl('create', null, fromHref), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      throw new Error('Failed to create announcement (' + res.status + ')');
    }
    return res.json();
  }

  async function update(id, body, fromHref) {
    const res = await fetch(apiUrl('update', { id: id }, fromHref), {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      throw new Error('Failed to update announcement (' + res.status + ')');
    }
    return res.json();
  }

  async function remove(id, fromHref) {
    const res = await fetch(apiUrl('delete', { id: id }, fromHref), { method: 'DELETE' });
    if (!res.ok) {
      throw new Error('Failed to delete announcement (' + res.status + ')');
    }
    return res.json();
  }

  global.BarangayConnectAnnouncementsApi = {
    XAMPP_HTTP_BASE,
    isFileProtocol,
    apiUrl,
    fetchAll,
    fetchLatest,
    fetchPublished,
    create,
    update,
    remove,
  };
})(window);
