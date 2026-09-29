/**
 * PHP chatbot API (XAMPP) — sessions, chat, history.
 */
(function (global) {
  const XAMPP_HTTP_BASE =
    'http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/';

  function isFileProtocol() {
    return global.location.protocol === 'file:';
  }

  function phpFile(fromHref) {
    if (isFileProtocol()) {
      return XAMPP_HTTP_BASE + 'FrontPage/php/chatbot_api.php';
    }
    return new URL('../../FrontPage/php/chatbot_api.php', fromHref || global.location.href).href;
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

  async function startSession(meta, fromHref) {
    const res = await fetch(apiUrl('start', null, fromHref), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(meta || {}),
    });
    if (!res.ok) {
      const err = await res.json().catch(function () {
        return {};
      });
      throw new Error(err.error || 'Failed to start chat session (' + res.status + ')');
    }
    return res.json();
  }

  async function sendMessage(sessionId, message, fromHref) {
    const res = await fetch(apiUrl('chat', null, fromHref), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ sessionId: sessionId, message: message }),
    });
    if (!res.ok) {
      const err = await res.json().catch(function () {
        return {};
      });
      throw new Error(err.error || 'Failed to send message (' + res.status + ')');
    }
    return res.json();
  }

  async function fetchHistory(sessionId, fromHref) {
    const res = await fetch(apiUrl('history', { sessionId: sessionId }, fromHref));
    if (!res.ok) {
      throw new Error('Failed to load chat history (' + res.status + ')');
    }
    return res.json();
  }

  global.BarangayConnectChatbotApi = {
    XAMPP_HTTP_BASE,
    isFileProtocol,
    apiUrl,
    startSession,
    sendMessage,
    fetchHistory,
  };
})(window);
