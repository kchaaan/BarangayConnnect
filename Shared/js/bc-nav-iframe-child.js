/** Tell parent portal the current iframe page (for refresh restore). */
(function () {
  function ping() {
    if (window.BarangayConnectNavPersist) {
      window.BarangayConnectNavPersist.notifyParentIframePage();
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ping);
  } else {
    ping();
  }
})();
