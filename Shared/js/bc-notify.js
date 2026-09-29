/**
 * BarangayConnect unified notifications.
 * - window.bcNotify.info/success/warning/error(message, {title})
 * - window.bcNotify.confirm(message, {title, okText, cancelText}) => Promise<boolean>
 * Also overrides window.alert() to use the themed modal.
 */
(function () {
  if (window.bcNotify && window.bcNotify.__ready) return;

  const STYLE_ID = "bc-notify-style";
  const ROOT_ID = "bc-notify-root";
  const ICON_ID = "bc-notify-icon";
  const TITLE_ID = "bc-notify-title";
  const SUBTITLE_ID = "bc-notify-subtitle";
  const MESSAGE_ID = "bc-notify-message";
  const CANCEL_ID = "bc-notify-cancel";
  const OK_ID = "bc-notify-ok";

  const originalAlert = window.alert ? window.alert.bind(window) : null;
  const queue = [];
  let active = false;

  function ensureUi() {
    if (!document || !document.body) return false;

    if (!document.getElementById(STYLE_ID)) {
      const style = document.createElement("style");
      style.id = STYLE_ID;
      style.textContent = `
        #${ROOT_ID}.bc-notify-overlay{
          position: fixed; inset: 0; display: none; align-items: center; justify-content: center;
          background: rgba(28, 38, 26, 0.45); z-index: 100000; padding: 16px;
        }
        #${ROOT_ID}.is-open{ display: flex; }
        #${ROOT_ID} .bc-notify-dialog{
          width: min(420px, 94vw);
          background: #fff;
          color: #2f3a2f;
          border: 1px solid rgba(72, 98, 68, 0.45);
          border-radius: 16px;
          box-shadow: 0 12px 40px rgba(30, 42, 26, 0.22);
          overflow: hidden;
          padding: 0;
          font-family: "Segoe UI", Arial, sans-serif;
        }
        #${ROOT_ID} .bc-notify-header{
          display:flex; align-items:flex-start; gap:.85rem; padding:1.15rem 1.25rem .4rem;
        }
        #${ROOT_ID} .bc-notify-icon{
          width:2rem; height:2rem; border-radius:50%; display:flex; align-items:center; justify-content:center;
          font-size:1rem; flex-shrink:0;
        }
        #${ROOT_ID}.tone-error .bc-notify-icon{ background:#fdecea; color:#c0392b; }
        #${ROOT_ID}.tone-success .bc-notify-icon{ background:#e8f4ec; color:#2d7a4f; }
        #${ROOT_ID}.tone-warning .bc-notify-icon{ background:#fff5df; color:#a66d00; }
        #${ROOT_ID}.tone-info .bc-notify-icon{ background:#e8f0fa; color:#1e4d7b; }
        #${ROOT_ID} .bc-notify-title{
          margin: 0;
          font-size: 32px;
          line-height: 1.2;
          font-weight: 700;
          color:#355230;
          font-size:1.1rem;
        }
        #${ROOT_ID} .bc-notify-subtitle{
          margin:.2rem 0 0; font-size:.8rem; color:#6b7c68; font-weight:500;
        }
        #${ROOT_ID} .bc-notify-message{
          margin: 0;
          font-size: .95rem;
          line-height: 1.55;
          white-space: pre-line;
          color: #2f3a2f;
          padding: .35rem 1.25rem 1rem;
        }
        #${ROOT_ID} .bc-notify-actions{
          display: flex;
          justify-content: flex-end;
          gap: .6rem;
          padding: .8rem 1.25rem 1.1rem;
          border-top: 1px solid rgba(95, 127, 88, 0.18);
        }
        #${ROOT_ID} .bc-notify-btn{
          border: none;
          border-radius: 10px;
          min-width: 86px;
          padding: .55rem 1.3rem;
          font-size: .85rem;
          font-weight:700;
          letter-spacing:.04em;
          text-transform:uppercase;
          cursor: pointer;
        }
        #${ROOT_ID} .bc-notify-btn:focus-visible{
          outline: 2px solid #8bb28a;
          outline-offset: 2px;
        }
        #${ROOT_ID} .bc-notify-btn.cancel{
          background: #eef3ee;
          color: #3f5a3f;
          border: 1px solid #d5e3d5;
        }
        #${ROOT_ID} .bc-notify-btn.ok{
          background: linear-gradient(135deg, #5f7f58 0%, #6d9a62 100%);
          color: #fff;
          box-shadow: 0 4px 14px rgba(95, 127, 88, 0.35);
        }
        #${ROOT_ID} .bc-notify-btn.ok:hover{ filter:brightness(1.06); }
      `;
      document.head.appendChild(style);
    }

    if (!document.getElementById(ROOT_ID)) {
      const root = document.createElement("div");
      root.id = ROOT_ID;
      root.className = "bc-notify-overlay";
      root.setAttribute("role", "presentation");
      root.innerHTML = `
        <div class="bc-notify-dialog" role="dialog" aria-modal="true" aria-labelledby="${TITLE_ID}" aria-describedby="${MESSAGE_ID}">
          <div class="bc-notify-header">
            <div id="${ICON_ID}" class="bc-notify-icon"><i class="fas fa-circle-info"></i></div>
            <div class="bc-notify-title-wrap">
              <h2 id="${TITLE_ID}" class="bc-notify-title">Notice</h2>
              <p id="${SUBTITLE_ID}" class="bc-notify-subtitle">BarangayConnect</p>
            </div>
          </div>
          <p id="${MESSAGE_ID}" class="bc-notify-message"></p>
          <div class="bc-notify-actions">
            <button type="button" id="${CANCEL_ID}" class="bc-notify-btn cancel">Cancel</button>
            <button type="button" id="${OK_ID}" class="bc-notify-btn ok">OK</button>
          </div>
        </div>
      `;
      document.body.appendChild(root);
    }

    return true;
  }

  function showNext() {
    if (active || queue.length === 0) return;
    if (!ensureUi()) {
      const item = queue.shift();
      if (item && originalAlert) originalAlert(item.message || "");
      if (item && item.resolve) item.resolve(item.mode === "confirm" ? false : undefined);
      active = false;
      return;
    }

    active = true;
    const item = queue.shift();
    const root = document.getElementById(ROOT_ID);
    const iconEl = document.getElementById(ICON_ID);
    const titleEl = document.getElementById(TITLE_ID);
    const msgEl = document.getElementById(MESSAGE_ID);
    const okBtn = document.getElementById(OK_ID);
    const cancelBtn = document.getElementById(CANCEL_ID);
    if (!root || !titleEl || !msgEl || !okBtn || !cancelBtn) {
      active = false;
      return;
    }

    root.classList.remove("tone-info", "tone-success", "tone-warning", "tone-error");
    root.classList.add(`tone-${item.tone || "info"}`);
    if (iconEl) {
      const tone = item.tone || "info";
      const iconMap = {
        info: 'fa-circle-info',
        success: 'fa-circle-check',
        warning: 'fa-triangle-exclamation',
        error: 'fa-circle-exclamation'
      };
      iconEl.innerHTML = '<i class="fas ' + (iconMap[tone] || iconMap.info) + '"></i>';
    }
    titleEl.textContent = item.title || "Notice";
    msgEl.textContent = item.message || "";
    okBtn.textContent = item.okText || "OK";
    cancelBtn.textContent = item.cancelText || "Cancel";
    cancelBtn.style.display = item.mode === "confirm" ? "" : "none";
    root.classList.add("is-open");

    const onKeyDown = (e) => {
      if (e.key === "Escape") {
        close(item.mode === "confirm" ? false : undefined);
      } else if (e.key === "Enter") {
        close(item.mode === "confirm" ? true : undefined);
      }
    };

    const close = (result) => {
      root.classList.remove("is-open");
      okBtn.onclick = null;
      cancelBtn.onclick = null;
      root.onclick = null;
      document.removeEventListener("keydown", onKeyDown);
      active = false;
      item.resolve(result);
      showNext();
    };

    okBtn.onclick = () => close(item.mode === "confirm" ? true : undefined);
    cancelBtn.onclick = () => close(false);
    root.onclick = (e) => {
      if (e.target === root && item.mode === "confirm") close(false);
      if (e.target === root && item.mode !== "confirm") close(undefined);
    };
    document.addEventListener("keydown", onKeyDown);

    okBtn.focus();
  }

  function enqueue(item) {
    return new Promise((resolve) => {
      queue.push({ ...item, resolve });
      showNext();
    });
  }

  function normalizeOpts(opts, defaultTitle) {
    if (typeof opts === "string") return { title: opts || defaultTitle };
    return { title: (opts && opts.title) || defaultTitle, okText: opts && opts.okText, cancelText: opts && opts.cancelText };
  }

  window.bcNotify = {
    __ready: true,
    info(message, opts) {
      const o = normalizeOpts(opts, "Notice");
      return enqueue({ mode: "alert", tone: "info", message, title: o.title, okText: o.okText });
    },
    success(message, opts) {
      const o = normalizeOpts(opts, "Success");
      return enqueue({ mode: "alert", tone: "success", message, title: o.title, okText: o.okText });
    },
    warning(message, opts) {
      const o = normalizeOpts(opts, "Warning");
      return enqueue({ mode: "alert", tone: "warning", message, title: o.title, okText: o.okText });
    },
    error(message, opts) {
      const o = normalizeOpts(opts, "Error");
      return enqueue({ mode: "alert", tone: "error", message, title: o.title, okText: o.okText });
    },
    confirm(message, opts) {
      const o = normalizeOpts(opts, "Please confirm");
      return enqueue({
        mode: "confirm",
        tone: "info",
        message,
        title: o.title,
        okText: o.okText || "OK",
        cancelText: o.cancelText || "Cancel",
      });
    },
  };

  window.bcConfirm = function (message, opts) {
    return window.bcNotify.confirm(message, opts);
  };

  window.alert = function (message) {
    window.bcNotify.info(String(message ?? ""));
  };
})();
/**
 * BarangayConnect styled notifications (replaces window.alert).
 */
(function (global) {
  // Keep the unified notifier above as the single source of truth.
  // If already initialized, do not let this legacy block override it.
  if (global.bcNotify && global.bcNotify.__ready) return;
  let onOkCallback = null;

  function ensureModal() {
    if (document.getElementById('bcNotifyModal')) return;

    const wrap = document.createElement('div');
    wrap.id = 'bcNotifyModal';
    wrap.className = 'bc-notify-modal';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-modal', 'true');
    wrap.innerHTML = `
      <div class="bc-notify-backdrop" data-bc-notify-close></div>
      <div class="bc-notify-card bc-notify-card--info" id="bcNotifyCard">
        <div class="bc-notify-header">
          <div class="bc-notify-icon bc-notify-icon--info" id="bcNotifyIcon" aria-hidden="true">
            <i class="fas fa-info-circle"></i>
          </div>
          <div class="bc-notify-title-wrap">
            <h2 class="bc-notify-title" id="bcNotifyTitle">Notice</h2>
            <p class="bc-notify-subtitle" id="bcNotifySubtitle">BarangayConnect</p>
          </div>
        </div>
        <div class="bc-notify-body">
          <p class="bc-notify-message" id="bcNotifyMessage"></p>
          <div class="bc-notify-reference" id="bcNotifyReference" hidden>
            <span>Reference:</span> <strong id="bcNotifyRefValue"></strong>
          </div>
        </div>
        <div class="bc-notify-footer">
          <button type="button" class="bc-notify-btn bc-notify-btn--primary" id="bcNotifyOkBtn">OK</button>
        </div>
      </div>
    `;
    document.body.appendChild(wrap);

    document.getElementById('bcNotifyOkBtn')?.addEventListener('click', hideBcNotify);
    wrap.querySelector('[data-bc-notify-close]')?.addEventListener('click', hideBcNotify);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && wrap.classList.contains('is-open')) hideBcNotify();
    });
  }

  function hideBcNotify() {
    const modal = document.getElementById('bcNotifyModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    document.body.style.overflow = '';
    const cb = onOkCallback;
    onOkCallback = null;
    if (typeof cb === 'function') cb();
  }

  /**
   * @param {string} message
   * @param {{ title?: string, subtitle?: string, type?: 'success'|'error'|'info', reference?: string, onOk?: function }} [opts]
   */
  function showBcNotify(message, opts) {
    opts = opts || {};
    ensureModal();

    const type = opts.type || 'info';
    const title = opts.title || (type === 'success' ? 'Success' : type === 'error' ? 'Something went wrong' : 'Notice');
    const subtitle = opts.subtitle || 'BarangayConnect';

    const modal = document.getElementById('bcNotifyModal');
    const card = document.getElementById('bcNotifyCard');
    const icon = document.getElementById('bcNotifyIcon');
    const titleEl = document.getElementById('bcNotifyTitle');
    const subEl = document.getElementById('bcNotifySubtitle');
    const msgEl = document.getElementById('bcNotifyMessage');
    const refWrap = document.getElementById('bcNotifyReference');
    const refVal = document.getElementById('bcNotifyRefValue');

    card.className = 'bc-notify-card bc-notify-card--' + type;
    icon.className = 'bc-notify-icon bc-notify-icon--' + type;

    const icons = {
      success: 'fa-circle-check',
      error: 'fa-circle-exclamation',
      info: 'fa-circle-info',
    };
    icon.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '"></i>';

    titleEl.textContent = title;
    subEl.textContent = subtitle;
    msgEl.textContent = message || '';

    if (opts.reference && refWrap && refVal) {
      refVal.textContent = opts.reference;
      refWrap.hidden = false;
    } else if (refWrap) {
      refWrap.hidden = true;
    }

    onOkCallback = opts.onOk || null;
    modal.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    document.getElementById('bcNotifyOkBtn')?.focus();
  }

  global.showBcNotify = showBcNotify;
  global.hideBcNotify = hideBcNotify;
  global.bcNotify = {
    show: showBcNotify,
    hide: hideBcNotify,
    success: (msg, opts) => showBcNotify(msg, { ...opts, type: 'success' }),
    error: (msg, opts) => showBcNotify(msg, { ...opts, type: 'error' }),
    info: (msg, opts) => showBcNotify(msg, { ...opts, type: 'info' }),
  };
})(window);
