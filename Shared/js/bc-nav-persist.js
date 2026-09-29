/**
 * Preserve admin/resident portal section, iframe page, and form wizard step across refresh.
 */
(function (global) {
  const PREFIX = 'bc_nav_';

  function storageKey(role, suffix) {
    return PREFIX + role + '_' + suffix;
  }

  function parseHash() {
    const raw = (global.location.hash || '').replace(/^#/, '').trim();
    if (!raw) return { section: null, page: null };
    const params = new URLSearchParams(raw.includes('=') ? raw : 'section=' + raw);
    return {
      section: params.get('section') || null,
      page: params.get('page') || null,
    };
  }

  function buildHash(section, page) {
    if (!section) return '';
    const params = new URLSearchParams();
    params.set('section', section);
    if (page) params.set('page', page);
    return '#' + params.toString();
  }

  function writeHash(section, page) {
    if (!section) return;
    const next = buildHash(section, page);
    const url = global.location.pathname + global.location.search + next;
    if (global.location.hash !== next) {
      global.history.replaceState(null, '', url);
    }
  }

  function savePortalState(role, section, iframePage) {
    if (!role || !section) return;
    try {
      sessionStorage.setItem(storageKey(role, 'section'), section);
      if (iframePage) {
        sessionStorage.setItem(storageKey(role, 'iframe_' + section), iframePage);
      } else {
        sessionStorage.removeItem(storageKey(role, 'iframe_' + section));
      }
    } catch (e) {
      /* ignore */
    }
    writeHash(section, iframePage || null);
  }

  function loadPortalState(role) {
    const fromHash = parseHash();
    let section = fromHash.section;
    let page = fromHash.page;
    try {
      if (!section) section = sessionStorage.getItem(storageKey(role, 'section'));
      if (!page && section) {
        page = sessionStorage.getItem(storageKey(role, 'iframe_' + section));
      }
    } catch (e) {
      /* ignore */
    }
    return { section: section || null, page: page || null };
  }

  function portalUrlWithSavedState(role, baseUrl) {
    const state = loadPortalState(role);
    if (!state.section) return baseUrl;
    return baseUrl.replace(/#.*$/, '') + buildHash(state.section, state.page);
  }

  function getSectionElement(sectionName) {
    return global.document.getElementById(sectionName + '-section');
  }

  function normalizeIframeFile(page) {
    if (!page) return '';
    return page.split('/').pop().split('?')[0];
  }

  function iframeSrcWithBust(file) {
    const base = normalizeIframeFile(file);
    if (!base) return '';
    return base + '?v=' + Date.now();
  }

  /** In-iframe navigation — always bypass browser cache for HTML + scripts. */
  function navigatePage(file) {
    const base = normalizeIframeFile(file);
    if (!base) return;
    global.location.href = base + '?v=' + Date.now();
  }

  function setIframePage(sectionEl, page) {
    if (!sectionEl || !page) return;
    const iframe = sectionEl.querySelector('iframe');
    if (!iframe) return;
    iframe.src = iframeSrcWithBust(page);
  }

  function readIframePage(sectionEl) {
    const iframe = sectionEl && sectionEl.querySelector('iframe');
    if (!iframe) return null;
    try {
      const href = iframe.contentWindow.location.href;
      return normalizeIframeFile(href.split('/').pop() || '');
    } catch (e) {
      return normalizeIframeFile(iframe.getAttribute('src') || '');
    }
  }

  /** @type {Record<string, string>} */
  let portalSectionDefaults = {};

  function getSectionDefaultPage(sectionName, sectionEl) {
    if (portalSectionDefaults[sectionName]) {
      return normalizeIframeFile(portalSectionDefaults[sectionName]);
    }
    const iframe = sectionEl && sectionEl.querySelector('iframe');
    if (!iframe) return '';
    const fallback =
      iframe.getAttribute('data-bc-default-src') || iframe.getAttribute('src') || '';
    return normalizeIframeFile(fallback);
  }

  /** @type {Record<string, string[]>} */
  const SECTION_PAGE_ALLOWLIST = {
    dashboard: ['A_Home-Dashboard.html'],
    reports: ['A_Reports.html'],
    requests: [
      'A_Request-Management.html',
      'A_Request.html',
      'A_InPrgrs-Request.html',
      'A_Cmptd-Request.html',
    ],
    'borrowed-items': [
      'A_Borrow-Requests.html',
      'A_Borrowed-Items-Monitoring.html',
      'A_Borrowing-History.html',
    ],
    inventory: [
      'A_Item-Inventory.html',
      'A_Item-Categories.html',
    ],
    borrow: [
      'R_Borrow-Available-Items.html',
      'R_My-Borrow-Requests.html',
      'R_Borrow-Items.html',
    ],
    transparency: ['A_Transparency.html'],
    announcements: ['A_Announcements.html'],
    users: ['A_User-Management.html'],
    emergency: ['A_Emergency.html'],
    feedback: ['A_Feedback.html'],
  };

  function isAllowedSectionPage(sectionName, page) {
    const file = normalizeIframeFile(page);
    if (!file || !sectionName) return false;
    const list = SECTION_PAGE_ALLOWLIST[sectionName];
    if (!list) return false;
    return list.indexOf(file) !== -1;
  }

  function resolveSectionFromEventSource(sourceWindow) {
    if (!sourceWindow) return null;
    var matched = null;
    global.document.querySelectorAll('.content-section iframe').forEach(function (iframe) {
      if (matched) return;
      try {
        if (iframe.contentWindow === sourceWindow) {
          var sectionId = iframe.closest('.content-section').id || '';
          matched = sectionId.replace(/-section$/, '') || null;
        }
      } catch (e) {
        /* ignore */
      }
    });
    return matched;
  }

  function sanitizeRestorePage(sectionName, page) {
    if (page && isAllowedSectionPage(sectionName, page)) {
      return normalizeIframeFile(page);
    }
    return portalSectionDefaults[sectionName]
      ? normalizeIframeFile(portalSectionDefaults[sectionName])
      : null;
  }

  function resolveIframePage(role, sectionName, sectionEl, explicitPage) {
    if (explicitPage && isAllowedSectionPage(sectionName, explicitPage)) {
      return normalizeIframeFile(explicitPage);
    }

    const def =
      getSectionDefaultPage(sectionName, sectionEl) ||
      (portalSectionDefaults[sectionName]
        ? normalizeIframeFile(portalSectionDefaults[sectionName])
        : '');

    const fromHash = parseHash();
    if (fromHash.section === sectionName && fromHash.page && isAllowedSectionPage(sectionName, fromHash.page)) {
      return normalizeIframeFile(fromHash.page);
    }
    try {
      const saved = sessionStorage.getItem(storageKey(role, 'iframe_' + sectionName));
      if (saved && isAllowedSectionPage(sectionName, saved)) {
        return normalizeIframeFile(saved);
      }
    } catch (e) {
      /* ignore */
    }
    return def;
  }

  function reloadSectionIframe(role, sectionName, sectionEl, page) {
    const iframe = sectionEl && sectionEl.querySelector('iframe');
    if (!iframe) return null;
    const target = resolveIframePage(role, sectionName, sectionEl, page);
    if (!target) return null;
    setIframePage(sectionEl, target);
    savePortalState(role, sectionName, target);
    return target;
  }

  function syncNavHighlight(sectionName, page) {
    global.document.querySelectorAll('.nav-item').forEach(function (item) {
      item.classList.remove('active');
    });
    global.document.querySelectorAll('.nav-sublink').forEach(function (link) {
      link.classList.remove('active');
    });
    global.document.querySelectorAll('.nav-group').forEach(function (group) {
      group.classList.remove('is-active-group');
    });

    const normalizedPage = page ? normalizeIframeFile(page) : '';
    let sublink = null;
    if (normalizedPage) {
      sublink = global.document.querySelector(
        '.nav-sublink[data-section="' + sectionName + '"][data-page="' + normalizedPage + '"]'
      );
    }
    if (!sublink) {
      sublink = global.document.querySelector('.nav-sublink[data-section="' + sectionName + '"]');
    }
    if (sublink) {
      sublink.classList.add('active');
      const group = sublink.closest('.nav-group');
      if (group) {
        group.classList.add('is-expanded', 'is-active-group');
        const toggle = group.querySelector('.nav-group-toggle');
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
      }
      return;
    }

    const link = global.document.querySelector('.nav-link[data-section="' + sectionName + '"]');
    if (link && link.parentElement) {
      link.parentElement.classList.add('active');
    }
  }

  function activatePortalSection(role, sectionName, iframePage) {
    global.document.querySelectorAll('.content-section').forEach(function (section) {
      section.classList.remove('active');
    });

    const sectionEl = getSectionElement(sectionName);
    if (!sectionEl) return false;
    sectionEl.classList.add('active');

    let loadedPage = iframePage || null;
    if (sectionEl.querySelector('iframe')) {
      loadedPage = reloadSectionIframe(role, sectionName, sectionEl, iframePage || null);
    } else {
      savePortalState(role, sectionName, iframePage || null);
    }

    syncNavHighlight(sectionName, loadedPage || iframePage || portalSectionDefaults[sectionName]);
    return true;
  }

  function bindIframeTracking(role) {
    global.document.querySelectorAll('.content-section iframe').forEach(function (iframe) {
      if (iframe.dataset.bcNavBound === '1') return;
      iframe.dataset.bcNavBound = '1';

      iframe.addEventListener('load', function () {
        const sectionEl = iframe.closest('.content-section');
        if (!sectionEl || !sectionEl.classList.contains('active')) return;
        const sectionId = sectionEl.id || '';
        const sectionName = sectionId.replace(/-section$/, '');
        if (!sectionName) return;
        const page = readIframePage(sectionEl);
        if (page) savePortalState(role, sectionName, page);
      });
    });
  }

  function initPortal(options) {
    const role = options.role;
    const defaultSection = options.defaultSection || 'dashboard';
    portalSectionDefaults = options.sectionDefaults || {};
    const state = loadPortalState(role);

    if (state.section) {
      activatePortalSection(role, state.section, sanitizeRestorePage(state.section, state.page));
    }

    global.document.querySelectorAll('.nav-link[data-section]').forEach(function (link) {
      if (link.dataset.bcNavBound === '1') return;
      link.dataset.bcNavBound = '1';

      link.addEventListener('click', function (e) {
        e.preventDefault();
        const sectionName = link.getAttribute('data-section');
        if (!sectionName) return;
        activatePortalSection(role, sectionName, portalSectionDefaults[sectionName] || null);
      });
    });

    global.document.querySelectorAll('.nav-sublink[data-section][data-page]').forEach(function (link) {
      if (link.dataset.bcNavBound === '1') return;
      link.dataset.bcNavBound = '1';

      link.addEventListener('click', function (e) {
        e.preventDefault();
        const sectionName = link.getAttribute('data-section');
        const page = link.getAttribute('data-page');
        if (!sectionName || !page) return;
        activatePortalSection(role, sectionName, page);
      });
    });

    global.document.querySelectorAll('.nav-group-toggle').forEach(function (btn) {
      if (btn.dataset.bcNavBound === '1') return;
      btn.dataset.bcNavBound = '1';

      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const group = btn.closest('.nav-group');
        if (!group) return;
        const expanded = group.classList.toggle('is-expanded');
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      });
    });

    bindIframeTracking(role);

    global.addEventListener('hashchange', function () {
      const fromHash = parseHash();
      if (fromHash.section) {
        activatePortalSection(
          role,
          fromHash.section,
          sanitizeRestorePage(fromHash.section, fromHash.page)
        );
      }
    });

    global.addEventListener('message', function (event) {
      const data = event.data;
      if (!data || data.type !== 'bc-nav-iframe') return;
      const sourceSection = resolveSectionFromEventSource(event.source);
      const section = sourceSection || data.section || loadPortalState(role).section;
      if (!section || !data.page) return;
      // Every portal iframe can load in the background. Only the active section
      // may update the URL and highlighted navigation item.
      const sectionEl = getSectionElement(section);
      if (!sectionEl || !sectionEl.classList.contains('active')) return;
      if (!isAllowedSectionPage(section, data.page)) return;
      const normalized = normalizeIframeFile(data.page);
      savePortalState(role, section, normalized);
      syncNavHighlight(section, normalized);
    });

    if (!state.section) {
      savePortalState(role, defaultSection, null);
    }

    return state;
  }

  /** Notify parent portal when this page runs inside an iframe */
  function notifyParentIframePage(sectionHint) {
    if (global.parent === global) return;
    try {
      global.parent.postMessage(
        {
          type: 'bc-nav-iframe',
          page: global.location.pathname.split('/').pop(),
          section: sectionHint || null,
        },
        '*'
      );
    } catch (e) {
      /* ignore */
    }
  }

  function saveRegisterWizard(step, modalOpen) {
    try {
      if (step != null) {
        sessionStorage.setItem(storageKey('front', 'register_step'), String(step));
      }
      if (modalOpen != null) {
        if (modalOpen) {
          sessionStorage.setItem(storageKey('front', 'register_open'), '1');
        } else {
          sessionStorage.removeItem(storageKey('front', 'register_open'));
          sessionStorage.removeItem(storageKey('front', 'register_step'));
        }
      }
    } catch (e) {
      /* ignore */
    }
  }

  function restoreRegisterWizard(openModalFn, setStepFn) {
    try {
      if (sessionStorage.getItem(storageKey('front', 'register_open')) !== '1') return;
      const step = Number(sessionStorage.getItem(storageKey('front', 'register_step')) || '1');
      if (typeof openModalFn === 'function') openModalFn();
      if (typeof setStepFn === 'function') setStepFn(Math.max(1, Math.min(4, step)));
    } catch (e) {
      /* ignore */
    }
  }

  function saveStandalonePath(role) {
    try {
      sessionStorage.setItem(
        storageKey(role, 'standalone'),
        global.location.pathname + global.location.search + global.location.hash
      );
    } catch (e) {
      /* ignore */
    }
  }

  function trackStandalonePage(role) {
    saveStandalonePath(role);
    global.addEventListener('beforeunload', function () {
      saveStandalonePath(role);
    });
    notifyParentIframePage();
  }

  global.BarangayConnectNavPersist = {
    parseHash,
    buildHash,
    savePortalState,
    loadPortalState,
    portalUrlWithSavedState,
    activatePortalSection,
    initPortal,
    navigatePage,
    iframeSrcWithBust,
    notifyParentIframePage,
    trackStandalonePage,
    saveRegisterWizard,
    restoreRegisterWizard,
  };

  /** Shorthand for onclick handlers in request pages */
  global.bcNavigatePage = function (file) {
    navigatePage(file);
  };
})(window);
