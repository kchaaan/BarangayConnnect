/**
 * PHP transparency API (XAMPP) — admin A_Transparency.html & resident R_Transparency.html.
 */
(function (global) {
  const XAMPP_HTTP_BASE =
    'http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/';

  function isFileProtocol() {
    return global.location.protocol === 'file:';
  }

  function phpFile(fromHref) {
    if (isFileProtocol()) {
      return XAMPP_HTTP_BASE + 'FrontPage/php/transparency_api.php';
    }
    return new URL('../../FrontPage/php/transparency_api.php', fromHref || global.location.href).href;
  }

  function apiUrl(action, id, fromHref) {
    const u = new URL(phpFile(fromHref));
    u.searchParams.set('action', action);
    if (id != null && id !== '') {
      u.searchParams.set('id', String(id));
    }
    return u.href;
  }

  function uploadBase(fromHref) {
    if (isFileProtocol()) {
      return XAMPP_HTTP_BASE;
    }
    return new URL('../../', fromHref || global.location.href).href;
  }

  global.BarangayConnectTransparencyApi = {
    XAMPP_HTTP_BASE,
    isFileProtocol,
    apiUrl,
    uploadBase,
  };
})(window);
