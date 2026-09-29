(function () {
  'use strict';

  const form = document.getElementById('unifiedLoginForm');
  if (!form) return;

  const error = document.getElementById('unifiedLoginError');
  const button = document.getElementById('unifiedLoginSubmit');
  const host = document.getElementById('turnstileWidget');
  let widgetId = null;
  let siteKey = '';
  let localTestMode = false;
  let configReady = false;

  function showError(message) {
    if (!error) return;
    error.textContent = message || '';
    error.classList.toggle('unified-login-error--info', Boolean(message && message.startsWith('Local testing mode:')));
  }

  function setButtonReady() {
    if (button) button.disabled = !configReady || (!localTestMode && widgetId === null);
  }

  function renderWidget() {
    const modal = document.getElementById('loginModal');
    if (!host || !siteKey || !window.turnstile || widgetId !== null || !modal || modal.style.display === 'none') return;
    try {
      host.dataset.sitekey = siteKey;
      widgetId = window.turnstile.render(host, { sitekey: siteKey, theme: 'light' });
      setButtonReady();
      showError('');
    } catch (e) {
      showError('Hindi ma-load ang CAPTCHA. I-refresh ang page at subukan ulit.');
      setButtonReady();
    }
  }

  fetch('../php/turnstile_config.php', { credentials: 'same-origin', cache: 'no-store' })
    .then((response) => {
      if (!response.ok) throw new Error('CAPTCHA configuration request failed.');
      return response.json();
    })
    .then((data) => {
      localTestMode = data.localTestMode === true;
      siteKey = data.siteKey || '';
      configReady = true;

      if (localTestMode) {
        if (host) host.style.display = 'none';
        showError('Local testing mode: CAPTCHA is simulated only on this computer.');
        setButtonReady();
        return;
      }

      if (!siteKey) {
        showError('CAPTCHA is not configured. Contact the system administrator.');
        setButtonReady();
        return;
      }

      if (window.turnstile) {
        renderWidget();
      } else {
        const script = document.createElement('script');
        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        script.async = true;
        script.defer = true;
        script.onload = renderWidget;
        script.onerror = () => {
          showError('Hindi maabot ang CAPTCHA service. Suriin ang internet o browser extensions.');
          setButtonReady();
        };
        document.head.appendChild(script);
      }
    })
    .catch(() => {
      configReady = false;
      showError('Hindi mabasa ang CAPTCHA setup mula sa PHP server.');
      setButtonReady();
    });

  ['loginBtn', 'loginBtn2'].forEach((id) => {
    document.getElementById(id)?.addEventListener('click', () => setTimeout(renderWidget, 30));
  });

  const modal = document.getElementById('loginModal');
  if (modal && window.MutationObserver) {
    new MutationObserver(() => setTimeout(renderWidget, 30))
      .observe(modal, { attributes: true, attributeFilter: ['style', 'class'] });
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    showError('');

    if (!configReady) {
      showError('Sandali, inihahanda pa ang login. Subukan ulit pagkalipas ng ilang segundo.');
      return;
    }

    const token = localTestMode
      ? 'LOCAL_TURNSTILE_TEST_TOKEN'
      : (window.turnstile && widgetId !== null ? window.turnstile.getResponse(widgetId) : '');
    if (!token) {
      showError('Kumpletuhin muna ang CAPTCHA bago mag-login.');
      return;
    }

    button.disabled = true;
    button.textContent = 'Signing in…';
    try {
      const data = new URLSearchParams();
      data.set('identifier', document.getElementById('loginIdentifier').value.trim());
      data.set('password', document.getElementById('loginPassword').value);
      data.set('cf-turnstile-response', token);
      const response = await fetch('../php/supabase_login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data,
        credentials: 'same-origin'
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error || 'Login failed.');

      const session = window.BarangayConnectSession;
      if (result.role === 'admin' || result.role === 'superadmin') {
        if (localStorage.getItem('bcPrivacyNoticeAcknowledged_v1') === '1') {
          await fetch('../php/privacy_ack.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'version=2026-09-29' });
        }
        if (session) session.setAdminSession({ username: result.username || result.email, fullName: result.fullName });
        else {
          localStorage.setItem('adminLoggedIn', '1');
          localStorage.setItem('adminUsername', result.username || '');
          localStorage.setItem('adminFullName', result.fullName || 'Admin');
        }
        window.location.replace(new URL('../../Admin/html/AdminPage.html', location.href).href);
        return;
      }

      if (result.role === 'resident') {
        const resident = { id: result.id, residentId: result.residentId, firstname: result.firstname, lastname: result.lastname, status: 'ACTIVE' };
        if (session) session.setResidentSession(resident);
        else {
          localStorage.setItem('residentId', result.id);
          localStorage.setItem('residentCode', result.residentId);
          localStorage.setItem('residentFirstname', result.firstname);
          localStorage.setItem('residentLastname', result.lastname);
          localStorage.setItem('residentPortal', 'full');
        }
        if (localStorage.getItem('bcPrivacyNoticeAcknowledged_v1') === '1') {
          await fetch('../php/privacy_ack.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'version=2026-09-29' });
        }
        window.location.replace(new URL('../../Residents/html/ResidentPage.html', location.href).href);
        return;
      }
      throw new Error('Walang BarangayConnect role na naka-assign sa account.');
    } catch (e) {
      showError(e.message);
      if (!localTestMode && window.turnstile && widgetId !== null) window.turnstile.reset(widgetId);
    } finally {
      button.textContent = 'Login';
      setButtonReady();
    }
  });
})();
