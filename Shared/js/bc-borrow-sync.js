/**
 * Real-time borrow request sync across admin & resident portals.
 * Uses BroadcastChannel + postMessage relay + light polling when tab is visible.
 */
(function (global) {
  var CHANNEL_NAME = 'bc-borrow-updates';
  var POLL_MS = 8000;
  var listeners = new Set();
  var channel = null;
  var pollTimer = null;
  var messageBound = false;
  var pollCallback = null;

  function initChannel() {
    if (channel || typeof BroadcastChannel === 'undefined') return;
    try {
      channel = new BroadcastChannel(CHANNEL_NAME);
      channel.onmessage = function (e) {
        if (e.data && e.data.type === 'bc-borrow-changed') {
          dispatch(e.data, 'channel');
        }
      };
    } catch (err) {
      channel = null;
    }
  }

  function bindMessageRelay() {
    if (messageBound) return;
    messageBound = true;
    global.addEventListener('message', function (e) {
      if (e.data && e.data.type === 'bc-borrow-changed') {
        dispatch(e.data, 'message');
      }
    });
  }

  function dispatch(payload, via) {
    listeners.forEach(function (fn) {
      try {
        fn(payload, via);
      } catch (err) { /* ignore listener errors */ }
    });
  }

  function notify(detail) {
    var payload = Object.assign(
      { type: 'bc-borrow-changed', at: Date.now() },
      detail || {}
    );
    initChannel();
    bindMessageRelay();
    if (channel) {
      try {
        channel.postMessage(payload);
      } catch (err) { /* ignore */ }
    }
    if (global.parent !== global) {
      try {
        global.parent.postMessage(payload, '*');
      } catch (err) { /* ignore */ }
    }
    dispatch(payload, 'local');
    return payload;
  }

  function startPolling(callback, pollMs) {
    if (pollTimer) return;
    pollCallback = callback;
    pollTimer = global.setInterval(function () {
      if (document.hidden || typeof pollCallback !== 'function') return;
      pollCallback({ type: 'bc-borrow-changed', at: Date.now(), source: 'poll' });
    }, pollMs || POLL_MS);
  }

  function stopPolling() {
    if (pollTimer) {
      global.clearInterval(pollTimer);
      pollTimer = null;
    }
    pollCallback = null;
  }

  function watch(callback, opts) {
    if (typeof callback !== 'function') return function () {};
    opts = opts || {};
    listeners.add(callback);
    initChannel();
    bindMessageRelay();
    if (opts.poll !== false) {
      startPolling(callback, opts.pollMs);
    }
    return function unwatch() {
      listeners.delete(callback);
      if (!listeners.size) stopPolling();
    };
  }

  function relayToIframes(payload) {
    if (!payload || payload.type !== 'bc-borrow-changed') return;
    document.querySelectorAll('iframe').forEach(function (iframe) {
      try {
        if (iframe.contentWindow) {
          iframe.contentWindow.postMessage(payload, '*');
        }
      } catch (err) { /* ignore */ }
    });
  }

  global.BarangayConnectBorrowSync = {
    notify: notify,
    watch: watch,
    relayToIframes: relayToIframes,
    POLL_MS: POLL_MS,
    stopPolling: stopPolling,
  };
})(window);
