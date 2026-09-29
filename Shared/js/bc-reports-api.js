/**
 * PHP community reports API (XAMPP) — resident R_Reports.html & admin A_Reports.html.
 */
(function (global) {
  const XAMPP_HTTP_BASE =
    'http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/';

  function isFileProtocol() {
    return global.location.protocol === 'file:';
  }

  function phpFile(file, fromHref) {
    if (isFileProtocol()) {
      return XAMPP_HTTP_BASE + 'FrontPage/php/' + file;
    }
    return new URL('../../FrontPage/php/' + file, fromHref || global.location.href).href;
  }

  function apiUrl(action, id, fromHref) {
    const u = new URL(phpFile('reports_api.php', fromHref));
    u.searchParams.set('action', action);
    if (id != null && id !== '') {
      u.searchParams.set('id', String(id));
    }
    return u.href;
  }

  function submitUrl(fromHref) {
    return phpFile('reports_submit.php', fromHref);
  }

  global.BarangayConnectReportsApi = {
    XAMPP_HTTP_BASE,
    isFileProtocol,
    apiUrl,
    submitUrl,
  };
})(window);
