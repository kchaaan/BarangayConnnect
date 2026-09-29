/**
 * Resident profile API (XAMPP / MySQL).
 */
(function (global) {
  const XAMPP_HTTP_BASE =
    'http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/';

  function isFileProtocol() {
    return global.location.protocol === 'file:';
  }

  function profileUrl(fromHref) {
    if (isFileProtocol()) {
      return XAMPP_HTTP_BASE + 'FrontPage/php/resident_profile.php';
    }
    return new URL('../../FrontPage/php/resident_profile.php', fromHref || global.location.href).href;
  }

  function apiUrl(residentId, fromHref) {
    const u = new URL(profileUrl(fromHref));
    u.searchParams.set('id', String(residentId));
    return u.href;
  }

  async function fetchProfile(residentId, fromHref) {
    const res = await fetch(apiUrl(residentId, fromHref), { cache: 'no-store' });
    const data = await res.json().catch(function () {
      return {};
    });
    if (!res.ok || data.success === false) {
      throw new Error(data.error || 'Failed to load profile (' + res.status + ')');
    }
    return data;
  }

  async function updateProfile(residentId, body, fromHref) {
    const res = await fetch(apiUrl(residentId, fromHref), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(function () {
      return {};
    });
    if (!res.ok || data.success === false) {
      throw new Error(data.error || 'Failed to update profile (' + res.status + ')');
    }
    return data;
  }

  global.BarangayConnectResidentProfileApi = {
    fetchProfile,
    updateProfile,
  };
})(window);
