/**
 * Shared helpers for Borrowed Items & Inventory admin pages (demo UI).
 */
(function (global) {
  function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('is-open');
  }

  function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('is-open');
  }

  function bindModalClosers() {
    document.querySelectorAll('[data-bi-close]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const id = btn.getAttribute('data-bi-close');
        if (id) closeModal(id);
      });
    });
    document.querySelectorAll('.bi-modal').forEach(function (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target === modal) modal.classList.remove('is-open');
      });
    });
  }

  function initSidebarIframe() {
    function applyAdminSidebarState(collapsed) {
      document.documentElement.classList.toggle('admin-sidebar-expanded', !collapsed);
    }
    applyAdminSidebarState(false);
    global.addEventListener('message', function (event) {
      if (event.data && event.data.type === 'bc-admin-sidebar') {
        applyAdminSidebarState(!!event.data.collapsed);
      }
    });
    if (global.parent !== global) {
      try {
        global.parent.postMessage({ type: 'bc-admin-sidebar-request' }, '*');
      } catch (err) { /* ignore */ }
    }
  }

  function notifyNav(section) {
    if (global.BarangayConnectNavPersist) {
      global.BarangayConnectNavPersist.notifyParentIframePage(section);
    }
  }

  global.BorrowInventoryUI = {
    openModal,
    closeModal,
    bindModalClosers,
    initSidebarIframe,
    notifyNav,
  };
})(window);
