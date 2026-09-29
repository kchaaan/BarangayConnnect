/**
 * PHP emergency API (XAMPP) — hotlines & procedures.
 */
(function (global) {
  const XAMPP_HTTP_BASE =
    'http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/';

  function isFileProtocol() {
    return global.location.protocol === 'file:';
  }

  function phpFile(fromHref) {
    if (isFileProtocol()) {
      return XAMPP_HTTP_BASE + 'FrontPage/php/emergency_api.php';
    }
    return new URL('../../FrontPage/php/emergency_api.php', fromHref || global.location.href).href;
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

  async function fetchContacts(fromHref) {
    const res = await fetch(apiUrl('contacts', null, fromHref));
    if (!res.ok) throw new Error('Failed to load emergency contacts (' + res.status + ')');
    return res.json();
  }

  async function fetchProcedures(fromHref) {
    const res = await fetch(apiUrl('procedures', null, fromHref));
    if (!res.ok) throw new Error('Failed to load emergency procedures (' + res.status + ')');
    return res.json();
  }

  async function createContact(body, fromHref) {
    const res = await fetch(apiUrl('contact', null, fromHref), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error('Failed to create contact (' + res.status + ')');
    return res.json();
  }

  async function updateContact(id, body, fromHref) {
    const res = await fetch(apiUrl('contact', { id: id }, fromHref), {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error('Failed to update contact (' + res.status + ')');
    return res.json();
  }

  async function removeContact(id, fromHref) {
    const res = await fetch(apiUrl('contact', { id: id }, fromHref), { method: 'DELETE' });
    if (!res.ok) throw new Error('Failed to delete contact (' + res.status + ')');
    return res.json();
  }

  async function createProcedure(body, fromHref) {
    const res = await fetch(apiUrl('procedure', null, fromHref), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error('Failed to create procedure (' + res.status + ')');
    return res.json();
  }

  async function updateProcedure(id, body, fromHref) {
    const res = await fetch(apiUrl('procedure', { id: id }, fromHref), {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error('Failed to update procedure (' + res.status + ')');
    return res.json();
  }

  async function removeProcedure(id, fromHref) {
    const res = await fetch(apiUrl('procedure', { id: id }, fromHref), { method: 'DELETE' });
    if (!res.ok) throw new Error('Failed to delete procedure (' + res.status + ')');
    return res.json();
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>\"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', "'": '&#39;' }[char];
    });
  }

  function telUri(displayNumber) {
    const text = String(displayNumber || '').trim();
    const extension = text.match(/\blocal\s*(\d+)\b/i);
    const base = text
      .replace(/\s+to\s+\d+\b/i, '')
      .replace(/\blocal\s*\d+\b/i, '');
    const digits = base.replace(/\D/g, '');
    if (digits.length < 3 || digits.length > 15) return '';

    let dial = base.trim().startsWith('+') ? '+' + digits : digits;
    if (!dial.startsWith('+') && dial.startsWith('0') && digits.length >= 10) {
      dial = '+63' + digits.slice(1);
    }
    if (extension) dial += ';ext=' + extension[1];
    return dial;
  }

  function renderPhoneLinks(number, serviceName) {
    const parts = String(number || '').split(/\s*\/\s*/).map(function (part) { return part.trim(); }).filter(Boolean);
    if (!parts.length) return escapeHtml(number);

    return parts.map(function (part, index) {
      const href = telUri(part);
      const safePart = escapeHtml(part);
      const safeService = escapeHtml(serviceName || 'emergency contact');
      const link = href
        ? '<a class="emergency-phone-link" href="tel:' + escapeHtml(href) + '" aria-label="Call ' + safeService + ' at ' + safePart + '">' + safePart + ' <i class="fas fa-phone" aria-hidden="true"></i></a>'
        : safePart;
      return (index ? '<span class="emergency-phone-separator" aria-hidden="true"> / </span>' : '') + link;
    }).join('');
  }

  function renderCallButtons(number, serviceName) {
    const parts = String(number || '').split(/\s*\/\s*/).map(function (part) { return part.trim(); }).filter(Boolean);
    const safeService = escapeHtml(serviceName || 'emergency contact');
    return parts.map(function (part) {
      const href = telUri(part);
      if (!href) return '';
      const safePart = escapeHtml(part);
      return '<a class="emergency-call-btn" href="tel:' + escapeHtml(href) + '" aria-label="Call ' + safeService + ' at ' + safePart + '"><i class="fas fa-phone" aria-hidden="true"></i><span>Call ' + safePart + '</span></a>';
    }).filter(Boolean).join('');
  }

  global.BarangayConnectEmergencyApi = {
    XAMPP_HTTP_BASE,
    isFileProtocol,
    apiUrl,
    fetchContacts,
    fetchProcedures,
    createContact,
    updateContact,
    removeContact,
    createProcedure,
    updateProcedure,
    removeProcedure,
    renderPhoneLinks,
    renderCallButtons,
  };
})(window);
