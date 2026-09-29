/**
 * PHP requests API URLs (XAMPP) — used by resident submit & admin request pages.
 */
(function (global) {
  /** Use when the HTML file is opened as file:// — still posts to XAMPP Apache */
  const XAMPP_HTTP_BASE =
    'http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/';

  function isFileProtocol() {
    return global.location.protocol === 'file:';
  }

  function phpBase(fromHref) {
    if (isFileProtocol()) {
      return new URL(XAMPP_HTTP_BASE + 'FrontPage/php/requests_api.php');
    }
    return new URL('../../FrontPage/php/requests_api.php', fromHref || global.location.href);
  }

  function apiUrl(action, id, fromHref) {
    const u = phpBase(fromHref);
    u.searchParams.set('action', action);
    if (id != null && id !== '') {
      u.searchParams.set('id', String(id));
    }
    return u.href;
  }

  function submitUrl(fromHref) {
    if (isFileProtocol()) {
      return XAMPP_HTTP_BASE + 'FrontPage/php/requests_submit.php';
    }
    return new URL('../../FrontPage/php/requests_submit.php', fromHref || global.location.href).href;
  }

  function canSubmitToServer() {
    return (
      global.location.protocol === 'http:' ||
      global.location.protocol === 'https:' ||
      isFileProtocol()
    );
  }

  async function fetchAllRequests(fromHref) {
    const res = await fetch(apiUrl('list', null, fromHref));
    if (!res.ok) {
      throw new Error('Failed to load requests (' + res.status + ')');
    }
    return res.json();
  }

  function myRequestsUrl(residentId, fromHref) {
    const u = phpBase(fromHref);
    u.searchParams.set('action', 'my');
    u.searchParams.set('resident_id', String(residentId));
    return u.href;
  }

  async function fetchMyRequests(residentId, fromHref) {
    if (!residentId) {
      return [];
    }
    const res = await fetch(myRequestsUrl(residentId, fromHref));
    if (!res.ok) {
      throw new Error('Failed to load your requests (' + res.status + ')');
    }
    return res.json();
  }

  /**
   * Append multiple uploads so PHP receives them as $_FILES['files'] arrays.
   * @param {FormData} formData
   * @param {{ label: string, file: File }[]} items
   */
  function appendRequestFiles(formData, items) {
    (items || []).forEach(function (item) {
      if (!item || !item.file) return;
      formData.append('file_labels[]', item.label || 'Attachment');
      formData.append('files[]', item.file);
    });
  }

  global.BarangayConnectRequestsApi = {
    XAMPP_HTTP_BASE,
    isFileProtocol,
    canSubmitToServer,
    apiUrl,
    submitUrl,
    fetchAllRequests,
    fetchMyRequests,
    myRequestsUrl,
    appendRequestFiles,
  };
})(window);
