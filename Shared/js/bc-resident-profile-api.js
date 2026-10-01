/**
 * Authenticated resident profile API (Supabase-backed).
 */
(function (global) {
  const XAMPP_HTTP_BASE =
    'http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/';

  function isFileProtocol() {
    return global.location.protocol === 'file:';
  }

  function profileUrl(fromHref) {
    if (isFileProtocol()) {
      return XAMPP_HTTP_BASE + 'FrontPage/php/supabase_resident_profile.php';
    }
    return new URL('../../FrontPage/php/supabase_resident_profile.php', fromHref || global.location.href).href;
  }

  function apiUrl(residentId, fromHref) {
    // The endpoint takes the resident identity from the signed server session;
    // never trust a client-supplied profile ID for reads or updates.
    return profileUrl(fromHref);
  }

  async function fetchProfile(residentId, fromHref) {
    const res = await fetch(apiUrl(residentId, fromHref), { credentials: 'same-origin', cache: 'no-store' });
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
      credentials: 'same-origin',
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
