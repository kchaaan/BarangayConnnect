/**
 * GSAP-powered portal sidebar open / close animations.
 * Used by AdminPage.html and ResidentPage.html — requires GSAP 3.x loaded first.
 * On narrow viewports (≤900px) uses an off-canvas drawer instead of the 80px rail.
 */
(function (global) {
  var EXPANDED = 280;
  var COLLAPSED = 80;
  var MOBILE_BP = 900;
  var animating = false;
  var activeTween = null;
  var mobileTween = null;

  function sidebarEl() {
    return global.document.querySelector('.modern-sidebar');
  }

  function mainEl() {
    return global.document.querySelector('.admin-main');
  }

  function backdropEl() {
    return global.document.getElementById('bcSidebarBackdrop');
  }

  function labelEls() {
    var sidebar = sidebarEl();
    if (!sidebar) return [];
    return sidebar.querySelectorAll(
      '.nav-link span, .user-details h3, .user-details p, .nav-group-toggle span'
    );
  }

  function navItems() {
    var sidebar = sidebarEl();
    return sidebar ? sidebar.querySelectorAll('.nav-item, .nav-group') : [];
  }

  function mainWidth(sidebarPx) {
    return Math.max(0, global.innerWidth - sidebarPx);
  }

  function isCollapsed() {
    var sidebar = sidebarEl();
    return sidebar ? sidebar.classList.contains('collapsed') : false;
  }

  function isMobile() {
    return global.matchMedia('(max-width: ' + MOBILE_BP + 'px)').matches;
  }

  function isMobileOpen() {
    var sidebar = sidebarEl();
    return sidebar ? sidebar.classList.contains('mobile-open') : false;
  }

  function setAriaExpanded(open) {
    var sidebar = sidebarEl();
    var toggleBtn = sidebar && sidebar.querySelector('.sidebar-toggle');
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    var headerBtn = global.document.getElementById('headerMobileMenu');
    if (headerBtn) headerBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function resetSidebarLabels() {
    var sidebar = sidebarEl();
    if (!sidebar) return;
    var all = sidebar.querySelectorAll(
      '.nav-link span, .user-details h3, .user-details p, .nav-group-toggle span, ' +
      '.nav-group-chevron, .nav-item, .nav-group, .nav-sublink, .nav-submenu'
    );
    if (typeof gsap !== 'undefined') {
      gsap.set(all, { clearProps: 'opacity,transform,x,y,visibility' });
    }
    all.forEach(function (el) {
      el.style.removeProperty('opacity');
      el.style.removeProperty('transform');
      el.style.removeProperty('visibility');
    });
  }

  function clearInlineLayout() {
    var sidebar = sidebarEl();
    var adminMain = mainEl();
    if (typeof gsap !== 'undefined') {
      if (sidebar) gsap.set(sidebar, { clearProps: 'width,boxShadow,marginLeft,transform,x' });
      if (adminMain) gsap.set(adminMain, { clearProps: 'width,marginLeft' });
    }
  }

  function clearSidebarInlineLayout() {
    var sidebar = sidebarEl();
    if (!sidebar) return;
    if (typeof gsap !== 'undefined') {
      gsap.set(sidebar, { clearProps: 'transform,x,width,marginLeft,boxShadow' });
    } else {
      sidebar.style.removeProperty('transform');
      sidebar.style.removeProperty('width');
      sidebar.style.removeProperty('margin-left');
      sidebar.style.removeProperty('box-shadow');
    }
  }

  function hideBackdrop(backdrop) {
    if (!backdrop) return;
    backdrop.classList.remove('is-visible');
    backdrop.setAttribute('aria-hidden', 'true');
    if (typeof gsap !== 'undefined') {
      gsap.set(backdrop, { clearProps: 'opacity' });
    }
    backdrop.style.removeProperty('opacity');
  }

  function showBackdrop(backdrop) {
    if (!backdrop) return;
    backdrop.classList.add('is-visible');
    backdrop.setAttribute('aria-hidden', 'false');
  }

  function parkSidebarOffscreen(sidebar) {
    if (!sidebar) return;
    if (isMobile()) {
      if (typeof gsap !== 'undefined') {
        gsap.set(sidebar, { x: '-100%' });
      } else {
        sidebar.style.transform = 'translateX(-100%)';
      }
    } else {
      sidebar.style.removeProperty('transform');
      if (typeof gsap !== 'undefined') {
        gsap.set(sidebar, { clearProps: 'x,transform' });
      }
    }
  }

  function openMobileSidebar(instant) {
    var sidebar = sidebarEl();
    var backdrop = backdropEl();
    if (!sidebar) return;

    if (mobileTween) {
      mobileTween.kill();
      mobileTween = null;
    }

    clearSidebarInlineLayout();
    sidebar.classList.remove('collapsed');
    sidebar.classList.add('mobile-open');
    resetSidebarLabels();
    global.document.body.classList.add('bc-sidebar-drawer-open');
    showBackdrop(backdrop);
    setAriaExpanded(true);

    var navEls = navItems();

    if (typeof gsap === 'undefined' || instant) {
      parkSidebarOffscreen(sidebar);
      sidebar.style.transform = 'translateX(0)';
      if (backdrop) backdrop.style.opacity = '1';
      return;
    }

    gsap.killTweensOf([sidebar, backdrop, navEls]);
    gsap.set(sidebar, { x: '-100%' });
    gsap.set(navEls, { opacity: 0, x: -14 });
    if (backdrop) gsap.set(backdrop, { opacity: 0 });

    mobileTween = gsap.timeline({
      defaults: { ease: 'power3.out' },
      onComplete: function () {
        mobileTween = null;
        gsap.set(sidebar, { clearProps: 'x' });
        sidebar.style.transform = 'translateX(0)';
      },
    });

    mobileTween.to(sidebar, { x: 0, duration: 0.36 }, 0);
    if (backdrop) {
      mobileTween.to(backdrop, { opacity: 1, duration: 0.3, ease: 'power2.out' }, 0);
    }
    mobileTween.to(
      navEls,
      { opacity: 1, x: 0, duration: 0.3, stagger: 0.04, ease: 'power2.out' },
      0.12
    );
    mobileTween.from(
      sidebar.querySelectorAll('.user-details h3, .user-details p'),
      { opacity: 0, y: 8, duration: 0.28, stagger: 0.06, ease: 'power2.out' },
      0.08
    );
  }

  function closeMobileSidebar(instant) {
    var sidebar = sidebarEl();
    var backdrop = backdropEl();
    if (!sidebar) return;

    if (mobileTween) {
      mobileTween.kill();
      mobileTween = null;
    }

    var finishClose = function () {
      sidebar.classList.remove('mobile-open');
      clearSidebarInlineLayout();
      parkSidebarOffscreen(sidebar);
      hideBackdrop(backdrop);
      global.document.body.classList.remove('bc-sidebar-drawer-open');
      setAriaExpanded(false);
    };

    if (typeof gsap === 'undefined' || instant || !sidebar.classList.contains('mobile-open')) {
      finishClose();
      return;
    }

    var navEls = navItems();
    gsap.killTweensOf([sidebar, backdrop, navEls]);

    mobileTween = gsap.timeline({
      defaults: { ease: 'power3.in' },
      onComplete: function () {
        mobileTween = null;
        finishClose();
      },
    });

    mobileTween.to(navEls, { opacity: 0, x: -10, duration: 0.16, stagger: 0.02, ease: 'power2.in' }, 0);
    mobileTween.to(sidebar, { x: '-100%', duration: 0.32 }, 0.04);
    if (backdrop) {
      mobileTween.to(backdrop, { opacity: 0, duration: 0.26, ease: 'power2.in' }, 0.06);
    }
  }

  function setMobileMode() {
    var body = global.document.body;
    if (isMobile()) {
      body.classList.add('bc-mobile-nav');
      if (activeTween) activeTween.kill();
      animating = false;
      clearInlineLayout();
      var sidebar = sidebarEl();
      if (sidebar) sidebar.classList.remove('collapsed');
      if (!isMobileOpen()) closeMobileSidebar(true);
    } else {
      body.classList.remove('bc-mobile-nav', 'bc-sidebar-drawer-open');
      if (mobileTween) {
        mobileTween.kill();
        mobileTween = null;
      }
      closeMobileSidebar(true);
      var sidebar = sidebarEl();
      if (sidebar) {
        sidebar.style.removeProperty('transform');
        if (typeof gsap !== 'undefined') {
          gsap.set(sidebar, { clearProps: 'x,transform' });
        }
        resetSidebarLabels();
      }
      syncLayoutMetrics();
    }
  }

  function syncLayoutMetrics() {
    var sidebar = sidebarEl();
    var adminMain = mainEl();
    if (!sidebar || !adminMain || animating || isMobile()) return;

    var w = isCollapsed() ? COLLAPSED : EXPANDED;
    if (typeof gsap !== 'undefined') {
      gsap.set(sidebar, { width: w });
      gsap.set(adminMain, { marginLeft: w, width: mainWidth(w) });
    }
  }

  function toggle() {
    if (mobileTween) return;

    if (isMobile()) {
      if (isMobileOpen()) closeMobileSidebar(false);
      else openMobileSidebar(false);
      return;
    }

    var sidebar = sidebarEl();
    if (sidebar) {
      sidebar.classList.remove('mobile-open');
      sidebar.style.removeProperty('transform');
      if (typeof gsap !== 'undefined') {
        gsap.set(sidebar, { clearProps: 'x,transform' });
      }
      resetSidebarLabels();
    }
    hideBackdrop(backdropEl());
    global.document.body.classList.remove('bc-sidebar-drawer-open');

    if (typeof gsap === 'undefined') {
      var fallback = sidebarEl();
      if (fallback) fallback.classList.toggle('collapsed');
      if (typeof global.broadcastAdminSidebarState === 'function') {
        global.broadcastAdminSidebarState();
      }
      return;
    }

    var adminMain = mainEl();
    if (!sidebar || !adminMain || animating) return;

    var willCollapse = !isCollapsed();
    var lbl = labelEls();
    animating = true;

    if (activeTween) activeTween.kill();

    var tl = gsap.timeline({
      defaults: { ease: 'power3.inOut' },
      onComplete: function () {
        animating = false;
        sidebar.classList.toggle('collapsed', willCollapse);
        gsap.set(sidebar, { clearProps: 'width,boxShadow' });
        gsap.set(adminMain, { clearProps: 'width,marginLeft' });
        if (willCollapse) {
          gsap.set(lbl, { opacity: 0, x: -14 });
        } else {
          gsap.set(lbl, { clearProps: 'opacity,transform,x' });
        }
        if (typeof global.broadcastAdminSidebarState === 'function') {
          global.broadcastAdminSidebarState();
        }
        setAriaExpanded(!willCollapse);
      },
    });

    activeTween = tl;

    if (willCollapse) {
      tl.to(lbl, {
        opacity: 0,
        x: -14,
        duration: 0.24,
        stagger: 0.022,
        ease: 'power2.in',
      }, 0);

      tl.to(sidebar, {
        width: COLLAPSED,
        boxShadow: '2px 0 14px rgba(0, 0, 0, 0.1)',
        duration: 0.52,
      }, 0.08);

      tl.to(adminMain, {
        marginLeft: COLLAPSED,
        width: mainWidth(COLLAPSED),
        duration: 0.52,
      }, 0.08);

      tl.add(function () {
        sidebar.classList.add('collapsed');
      }, 0.3);
    } else {
      sidebar.classList.remove('collapsed');

      var currentW = sidebar.offsetWidth || COLLAPSED;
      gsap.set(lbl, { opacity: 0, x: -14 });
      gsap.set(sidebar, { width: currentW });
      gsap.set(adminMain, { marginLeft: currentW, width: mainWidth(currentW) });

      tl.to(sidebar, {
        width: EXPANDED,
        boxShadow: '6px 0 24px rgba(0, 0, 0, 0.14)',
        duration: 0.54,
      }, 0);

      tl.to(adminMain, {
        marginLeft: EXPANDED,
        width: mainWidth(EXPANDED),
        duration: 0.54,
      }, 0);

      tl.to(lbl, {
        opacity: 1,
        x: 0,
        duration: 0.36,
        stagger: 0.038,
        ease: 'power2.out',
      }, 0.2);
    }
  }

  function playEntrance() {
    if (typeof gsap === 'undefined' || isCollapsed() || isMobile()) return;

    var sidebar = sidebarEl();
    if (!sidebar || sidebar.dataset.bcSidebarEntrance === '1') return;
    sidebar.dataset.bcSidebarEntrance = '1';

    gsap.from(sidebar.querySelectorAll('.user-details h3, .user-details p'), {
      opacity: 0,
      y: 10,
      duration: 0.42,
      stagger: 0.07,
      ease: 'power2.out',
      delay: 0.08,
    });

    gsap.from(navItems(), {
      opacity: 0,
      x: -18,
      duration: 0.44,
      stagger: 0.055,
      ease: 'power2.out',
      delay: 0.14,
    });
  }

  function bindMobileNavClose() {
    global.document.addEventListener('click', function (e) {
      if (!isMobile() || !isMobileOpen() || mobileTween) return;
      var link = e.target.closest('.nav-link[data-section], .nav-sublink[data-section]');
      if (link) closeMobileSidebar(false);
    });
  }

  function init() {
    global.document.body.classList.add('bc-sidebar-gsap');
    setMobileMode();
    bindMobileNavClose();

    setAriaExpanded(isMobile() ? false : !isCollapsed());

    global.addEventListener('resize', function () {
      if (!animating && !mobileTween) setMobileMode();
    });

    if (global.document.readyState === 'loading') {
      global.document.addEventListener('DOMContentLoaded', playEntrance);
    } else {
      playEntrance();
    }
  }

  global.BarangayConnectAdminSidebar = {
    init: init,
    toggle: toggle,
    playEntrance: playEntrance,
    openMobileSidebar: openMobileSidebar,
    closeMobileSidebar: closeMobileSidebar,
    isMobile: isMobile,
  };

  global.BarangayConnectSidebarGsap = global.BarangayConnectAdminSidebar;
  global.closeMobileSidebar = function () {
    closeMobileSidebar(false);
  };
})(window);
