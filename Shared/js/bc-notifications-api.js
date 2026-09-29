/**
 * PHP resident notifications API (XAMPP).
 */
(function (global) {
  const XAMPP_HTTP_BASE =
    'http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/';

  function isFileProtocol() {
    return global.location.protocol === 'file:';
  }

  function phpBase(fromHref) {
    if (isFileProtocol()) {
      return new URL(XAMPP_HTTP_BASE + 'FrontPage/php/notifications_api.php');
    }
    return new URL('../../FrontPage/php/notifications_api.php', fromHref || global.location.href);
  }

  function apiUrl(action, params, fromHref) {
    const u = phpBase(fromHref);
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

  async function fetchCounts(residentId, fromHref) {
    const res = await fetch(apiUrl('counts', { resident_id: residentId }, fromHref));
    if (!res.ok) {
      throw new Error('Failed to load notification counts (' + res.status + ')');
    }
    return res.json();
  }

  async function fetchList(residentId, limit, fromHref) {
    const res = await fetch(
      apiUrl('list', { resident_id: residentId, limit: limit || 20 }, fromHref)
    );
    if (!res.ok) {
      throw new Error('Failed to load notifications (' + res.status + ')');
    }
    return res.json();
  }

  async function markRead(residentId, notificationId, fromHref) {
    const res = await fetch(
      apiUrl('read', { resident_id: residentId, id: notificationId }, fromHref),
      { method: 'PUT' }
    );
    if (!res.ok) {
      throw new Error('Failed to mark notification read (' + res.status + ')');
    }
    return res.json();
  }

  async function markAllRead(residentId, fromHref) {
    const res = await fetch(apiUrl('read_all', { resident_id: residentId }, fromHref), {
      method: 'PUT',
    });
    if (!res.ok) {
      throw new Error('Failed to mark all read (' + res.status + ')');
    }
    return res.json();
  }

  global.BarangayConnectNotificationsApi = {
    XAMPP_HTTP_BASE,
    isFileProtocol,
    apiUrl,
    fetchCounts,
    fetchList,
    markRead,
    markAllRead,
  };
})(window);
