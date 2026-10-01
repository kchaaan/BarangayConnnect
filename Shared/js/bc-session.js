/**
 * BarangayConnect — keep resident/admin login on the same host (localhost vs 127.0.0.1).
 */
(function (global) {
  const ORIGIN_KEY = 'bc_auth_origin';

  function saveOrigin() {
    try {
      localStorage.setItem(ORIGIN_KEY, global.location.origin);
    } catch (e) {
      /* ignore */
    }
  }

  function originMismatch() {
    try {
      const saved = localStorage.getItem(ORIGIN_KEY);
      return saved && saved !== global.location.origin;
    } catch (e) {
      return false;
    }
  }

  function setResidentSession(resident) {
    if (!resident || resident.id == null) return;
    saveOrigin();
    localStorage.setItem('residentId', String(resident.id));
    if (resident.residentId != null && String(resident.residentId).trim() !== '') {
      localStorage.setItem('residentCode', String(resident.residentId).trim());
    }
    localStorage.setItem('residentPortal', 'full');
    if (resident.firstname != null) {
      localStorage.setItem('residentFirstname', String(resident.firstname));
    }
    if (resident.lastname != null) {
      localStorage.setItem('residentLastname', String(resident.lastname));
    }
    if (resident.status != null) {
      localStorage.setItem('residentStatus', String(resident.status));
    }
  }

  function clearResidentSession() {
    localStorage.removeItem('residentId');
    localStorage.removeItem('residentCode');
    localStorage.removeItem('residentPortal');
    localStorage.removeItem('residentFirstname');
    localStorage.removeItem('residentLastname');
    localStorage.removeItem('residentStatus');
  }

  function hasResidentSession() {
    return (
      localStorage.getItem('residentPortal') === 'full' &&
      !!localStorage.getItem('residentId')
    );
  }

  function getResidentId() {
    return localStorage.getItem('residentId');
  }

  function getResidentCode() {
    return localStorage.getItem('residentCode');
  }

  function cachedResidentUser() {
    const id = getResidentId();
    if (!id) return null;
    return {
      id,
      firstname: localStorage.getItem('residentFirstname') || 'Resident',
      firstName: localStorage.getItem('residentFirstname') || 'Resident',
      lastName: localStorage.getItem('residentLastname') || '',
      status: localStorage.getItem('residentStatus') || 'ACTIVE',
    };
  }

  function setAdminSession(admin) {
    saveOrigin();
    localStorage.setItem('adminLoggedIn', '1');
    if (admin && admin.username) {
      localStorage.setItem('adminUsername', String(admin.username));
    }
    if (admin && admin.fullName) {
      localStorage.setItem('adminFullName', String(admin.fullName));
    }
  }

  function clearAdminSession() {
    localStorage.removeItem('adminLoggedIn');
    localStorage.removeItem('adminUsername');
    localStorage.removeItem('adminFullName');
  }

  function hasAdminSession() {
    return localStorage.getItem('adminLoggedIn') === '1';
  }

  function residentPortalUrl(fromHref) {
    return new URL('../../Residents/html/ResidentPage.html', fromHref || global.location.href).href;
  }

  function frontPageUrl(fromHref) {
    return new URL('../../FrontPage/html/Front.html', fromHref || global.location.href).href;
  }

  function adminPortalUrl(fromHref) {
    return new URL('../../Admin/html/AdminPage.html', fromHref || global.location.href).href;
  }

  function phpApiUrl(file, fromHref) {
    return new URL(`../../FrontPage/php/${file}`, fromHref || global.location.href).href;
  }

  global.BarangayConnectSession = {
    saveOrigin,
    originMismatch,
    setResidentSession,
    clearResidentSession,
    hasResidentSession,
    getResidentId,
    getResidentCode,
    cachedResidentUser,
    setAdminSession,
    clearAdminSession,
    hasAdminSession,
    residentPortalUrl,
    frontPageUrl,
    adminPortalUrl,
    phpApiUrl,
  };
})(window);
