(function () {
  'use strict';

  const form = document.getElementById('unifiedLoginForm');
  if (!form) return;

  const error = document.getElementById('unifiedLoginError');
  const button = document.getElementById('unifiedLoginSubmit');
  const identifier = document.getElementById('loginIdentifier');
  const password = document.getElementById('loginPassword');
  const captchaImage = document.getElementById('loginCaptchaImage');
  const captchaPlaceholder = document.getElementById('loginCaptchaPlaceholder');
  const captchaAnswer = document.getElementById('loginCaptchaAnswer');
  const captchaDigits = Array.from(document.querySelectorAll('.login-captcha__digit'));
  const captchaDigitsGroup = document.getElementById('loginCaptchaDigits');
  const captchaStatus = document.getElementById('loginCaptchaStatus');
  const captchaRefresh = document.getElementById('refreshLoginCaptcha');
  const captchaRefreshIcon = document.getElementById('loginCaptchaRefreshIcon');
  const captchaRefreshSpinner = document.getElementById('loginCaptchaRefreshSpinner');
  const captchaLabel = document.getElementById('loginCaptchaLabel');
  const captchaChallengeRow = document.getElementById('loginCaptchaChallengeRow');
  const robotCheckRow = document.getElementById('loginRobotCheckRow');
  const robotCheck = document.getElementById('loginRobotCheck');
  const robotSpinner = document.getElementById('loginRobotSpinner');
  const robotCheckmark = document.getElementById('loginRobotCheckmark');
  const robotErrorIcon = document.getElementById('loginRobotErrorIcon');
  let captchaVerified = false;
  let robotVerified = false;
  let captchaToken = '';
  let captchaTimer = null;
  let robotTimer = null;
  let captchaRequest = null;
  let captchaImageUrl = '';
  let captchaRequestNumber = 0;
  let verificationRequest = null;

  function residentPrintReturnUrl(role) {
    if (!['resident', 'admin', 'superadmin'].includes(role)) return '';
    const requested = new URLSearchParams(window.location.search).get('return_to');
    if (!requested) return '';
    try {
      const target = new URL(requested, window.location.href);
      const allowedPath = target.pathname.endsWith('/Residents/html/ResidentPrint.html')
        && target.searchParams.has('resident');
      return target.origin === window.location.origin && allowedPath ? target.href : '';
    } catch (e) {
      return '';
    }
  }

  function showError(message) {
    if (!error) return;
    error.textContent = message || '';
  }

  async function confirmServerSession(expectedRole) {
    const endpoint = '../php/supabase_session.php';
    // The login response rotates PHPSESSID. Confirm that the browser accepted
    // the replacement cookie before navigating into a portal that requires it.
    for (const delay of [0, 180, 420]) {
      if (delay) await new Promise((resolve) => setTimeout(resolve, delay));
      try {
        const response = await fetch(`${endpoint}?_=${Date.now()}`, {
          credentials: 'same-origin',
          cache: 'no-store',
        });
        if (!response.ok) continue;
        const session = await response.json().catch(() => null);
        if (session?.authenticated && session.role === expectedRole) return session;
      } catch (e) {
        // Retry brief network hiccups; never route into a portal with an
        // unverified PHP session.
      }
    }
    const sessionError = new Error('Sign-in succeeded, but your secure session could not be confirmed. Please try again.');
    sessionError.code = 'session_unavailable';
    throw sessionError;
  }

  function updateButton() {
    if (button) button.disabled = !(captchaVerified && identifier.value.trim() && password.value);
  }

  function setCaptchaStatus(message, isError = false, isSuccess = false, isLoading = false) {
    captchaStatus.textContent = message;
    captchaStatus.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    captchaStatus.classList.remove('is-error', 'is-success', 'is-loading');
    if (isError) {
      void captchaStatus.offsetWidth;
      captchaStatus.classList.add('is-error');
    }
    if (isSuccess) captchaStatus.classList.add('is-success');
    if (isLoading) captchaStatus.classList.add('is-loading');
  }

  function setCaptchaDigitsDisabled(disabled) {
    captchaDigits.forEach((digit) => { digit.disabled = disabled; });
  }

  function setCaptchaRefreshLoading(loading) {
    captchaRefresh.classList.toggle('is-loading', loading);
    captchaRefresh.setAttribute('aria-busy', loading ? 'true' : 'false');
    captchaRefreshIcon.hidden = loading;
    captchaRefreshSpinner.hidden = !loading;
  }

  function syncCaptchaAnswer() {
    captchaAnswer.value = captchaDigits.map((digit) => digit.value).join('');
  }

  function setRobotState(state) {
    const wasError = robotCheck.classList.contains('is-error');
    robotCheck.classList.toggle('is-checking', state === 'checking');
    robotCheck.classList.toggle('is-verified', state === 'verified');
    robotCheck.classList.remove('is-error');
    if (state === 'error') {
      if (wasError) void robotCheck.offsetWidth;
      robotCheck.classList.add('is-error');
    }
    robotCheck.setAttribute('aria-pressed', state === 'verified' ? 'true' : 'false');
    robotCheck.disabled = state === 'checking' || state === 'verified';
    robotSpinner.hidden = state !== 'checking';
    robotCheckmark.hidden = state !== 'verified';
    robotErrorIcon.hidden = state !== 'error';
  }

  function clearChallenge(message = 'Enter your username and password to continue.', preserveRobotCheck = false) {
    captchaVerified = false;
    if (!preserveRobotCheck) robotVerified = false;
    captchaToken = '';
    captchaAnswer.value = '';
    captchaAnswer.disabled = true;
    captchaDigits.forEach((digit) => { digit.value = ''; });
    setCaptchaDigitsDisabled(true);
    captchaRefresh.disabled = true;
    setCaptchaRefreshLoading(false);
    captchaLabel.hidden = true;
    captchaChallengeRow.hidden = true;
    captchaDigitsGroup.hidden = true;
    captchaImage.hidden = true;
    captchaImage.removeAttribute('src');
    captchaPlaceholder.hidden = false;
    captchaPlaceholder.textContent = 'Enter your username and password';
    robotCheckRow.hidden = false;
    setRobotState(robotVerified ? 'verified' : 'idle');
    if (captchaImageUrl) URL.revokeObjectURL(captchaImageUrl);
    captchaImageUrl = '';
    setCaptchaStatus(message);
    updateButton();
  }

  async function loadChallenge() {
    if (!identifier.value.trim() || !password.value || !robotVerified) {
      setCaptchaStatus(robotVerified
        ? 'Enter your username and password to load the CAPTCHA.'
        : 'Check the box to continue.');
      return;
    }

    captchaRequest?.abort();
    const currentRequest = new AbortController();
    captchaRequest = currentRequest;
    const requestNumber = ++captchaRequestNumber;
    const loadingStartedAt = Date.now();
    async function showLoadingBriefly() {
      const remaining = 550 - (Date.now() - loadingStartedAt);
      if (remaining > 0) await new Promise((resolve) => setTimeout(resolve, remaining));
    }
    captchaVerified = false;
    captchaToken = '';
    captchaAnswer.value = '';
    captchaDigits.forEach((digit) => { digit.value = ''; });
    captchaRefresh.disabled = true;
    setCaptchaRefreshLoading(true);
    captchaAnswer.disabled = true;
    captchaLabel.hidden = false;
    captchaChallengeRow.hidden = false;
    captchaDigitsGroup.hidden = false;
    captchaImage.hidden = true;
    captchaPlaceholder.hidden = false;
    captchaPlaceholder.textContent = 'Loading a new code…';
    setCaptchaStatus('Loading CAPTCHA…', false, false, true);
    try {
      const response = await fetch(`../php/login_captcha.php?t=${Date.now()}`, {
        credentials: 'same-origin', cache: 'no-store', signal: currentRequest.signal
      });
      if (!response.ok) {
        await showLoadingBriefly();
        if (requestNumber !== captchaRequestNumber || currentRequest.signal.aborted) return;
        throw new Error('Unable to load the CAPTCHA. Please try again.');
      }
      const imageBlob = await response.blob();
      await showLoadingBriefly();
      if (requestNumber !== captchaRequestNumber || currentRequest.signal.aborted) return;
      if (captchaImageUrl) URL.revokeObjectURL(captchaImageUrl);
      captchaImageUrl = URL.createObjectURL(imageBlob);
      captchaImage.src = captchaImageUrl;
      captchaImage.hidden = false;
      captchaPlaceholder.hidden = true;
      captchaAnswer.disabled = false;
      setCaptchaDigitsDisabled(false);
      setCaptchaRefreshLoading(false);
      captchaRefresh.disabled = false;
      captchaDigits[0]?.focus();
      setCaptchaStatus('Enter the 6-character code shown in the image.');
    } catch (requestError) {
      await showLoadingBriefly();
      if (requestError.name === 'AbortError' || currentRequest.signal.aborted) return;
      captchaVerified = false;
      robotVerified = false;
      captchaToken = '';
      captchaLabel.hidden = true;
      captchaChallengeRow.hidden = true;
      captchaDigitsGroup.hidden = true;
      captchaImage.hidden = true;
      setCaptchaRefreshLoading(false);
      captchaRefresh.disabled = true;
      robotCheckRow.hidden = false;
      setRobotState('idle');
      setCaptchaStatus(requestError.message || 'Unable to load the CAPTCHA. Please try again.', true);
      updateButton();
    }
  }

  function resetCaptchaFlow() {
    const keepRobotCheck = robotVerified;
    clearTimeout(robotTimer);
    robotTimer = null;
    clearTimeout(captchaTimer);
    captchaTimer = null;
    verificationRequest?.abort();
    showError('');
    captchaRequest?.abort();
    captchaRequestNumber += 1;
    clearChallenge(identifier.value.trim() && password.value
      ? (keepRobotCheck ? 'Verified. Loading CAPTCHA…' : 'Check the box to load the CAPTCHA.')
      : (keepRobotCheck ? 'Verified. Enter your username and password to load the CAPTCHA.' : 'Enter your username and password to continue.'), keepRobotCheck);
    if (keepRobotCheck && identifier.value.trim() && password.value) {
      captchaTimer = setTimeout(() => {
        captchaTimer = null;
        loadChallenge();
      }, 700);
    }
  }

  function beginRobotCheck() {
    if (robotCheck.disabled) return;
    if (!identifier.value.trim() || !password.value) {
      robotVerified = false;
      setRobotState('error');
      const missingField = !identifier.value.trim() ? identifier : password;
      setCaptchaStatus(!identifier.value.trim() && !password.value
        ? 'Enter your username and password before completing this check.'
        : !identifier.value.trim()
          ? 'Enter your username or email before completing this check.'
          : 'Enter your password before completing this check.', true);
      missingField.focus();
      return;
    }
    clearTimeout(robotTimer);
    robotVerified = false;
    setRobotState('checking');
    setCaptchaStatus('Verifying…');
    robotTimer = setTimeout(() => {
      robotTimer = null;
      if (!(identifier.value.trim() && password.value)) {
        resetCaptchaFlow();
        return;
      }
      robotVerified = true;
      setRobotState('verified');
      if (identifier.value.trim() && password.value) {
        setCaptchaStatus('Verified. Loading CAPTCHA…');
        loadChallenge();
      } else {
        setCaptchaStatus('Verified. Enter your username and password to load the CAPTCHA.');
      }
    }, 650);
  }

  async function verifyAnswer() {
    const answer = captchaAnswer.value.trim().toUpperCase();
    if (answer.length !== 6 || captchaAnswer.disabled) return;
    verificationRequest?.abort();
    const currentVerification = new AbortController();
    verificationRequest = currentVerification;
    const loadingStartedAt = Date.now();
    async function showLoadingBriefly() {
      const remaining = 550 - (Date.now() - loadingStartedAt);
      if (remaining > 0) await new Promise((resolve) => setTimeout(resolve, remaining));
    }
    captchaAnswer.disabled = true;
    setCaptchaDigitsDisabled(true);
    captchaRefresh.disabled = true;
    setCaptchaStatus('Verifying CAPTCHA…', false, false, true);
    try {
      const payload = new URLSearchParams({
        action: 'verify', answer,
        identifier: identifier.value.trim()
      });
      const response = await fetch('../php/login_captcha.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload, signal: currentVerification.signal
      });
      const result = await response.json();
      await showLoadingBriefly();
      if (currentVerification.signal.aborted) return;
      if (!response.ok || !result.success) throw new Error(result.error || 'Incorrect CAPTCHA code.');
      captchaVerified = true;
      captchaToken = result.token;
      captchaAnswer.disabled = false;
      setCaptchaDigitsDisabled(false);
      captchaRefresh.disabled = false;
      setCaptchaStatus('CAPTCHA verified. You can log in now.', false, true);
      updateButton();
    } catch (requestError) {
      await showLoadingBriefly();
      if (requestError.name === 'AbortError' || currentVerification.signal.aborted) return;
      captchaAnswer.disabled = false;
      setCaptchaDigitsDisabled(false);
      captchaRefresh.disabled = false;
      const firstFilledDigit = captchaDigits.find((digit) => digit.value);
      (firstFilledDigit || captchaDigits[0])?.focus();
      firstFilledDigit?.select();
      setCaptchaStatus(requestError.message || 'Unable to verify the CAPTCHA.', true);
      captchaVerified = false;
      captchaToken = '';
      updateButton();
    }
  }

  [identifier, password].forEach((field) => field.addEventListener('input', resetCaptchaFlow));
  robotCheck.addEventListener('click', beginRobotCheck);
  function handleCaptchaDigitsInput() {
    syncCaptchaAnswer();
    captchaVerified = false;
    captchaToken = '';
    updateButton();
    if (captchaAnswer.value.length === 6) verifyAnswer();
    else setCaptchaStatus('Enter the 6-character code shown in the image.');
  }

  captchaDigits.forEach((digit, index) => {
    digit.addEventListener('input', () => {
      digit.value = digit.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(-1);
      handleCaptchaDigitsInput();
      if (digit.value && index < captchaDigits.length - 1) captchaDigits[index + 1].focus();
    });
    digit.addEventListener('keydown', (event) => {
      if (event.key === 'Backspace' && !digit.value && index > 0) {
        captchaDigits[index - 1].focus();
      } else if (event.key === 'ArrowLeft' && index > 0) {
        captchaDigits[index - 1].focus();
      } else if (event.key === 'ArrowRight' && index < captchaDigits.length - 1) {
        captchaDigits[index + 1].focus();
      }
    });
    digit.addEventListener('paste', (event) => {
      const pasted = event.clipboardData.getData('text').toUpperCase().replace(/[^A-Z0-9]/g, '');
      if (!pasted) return;
      event.preventDefault();
      pasted.slice(0, captchaDigits.length).split('').forEach((character, pastedIndex) => {
        if (captchaDigits[pastedIndex]) captchaDigits[pastedIndex].value = character;
      });
      handleCaptchaDigitsInput();
      captchaDigits[Math.min(pasted.length, captchaDigits.length) - 1]?.focus();
    });
  });
  captchaRefresh.addEventListener('click', () => {
    verificationRequest?.abort();
    loadChallenge();
  });
  clearChallenge();
  updateButton();

  ['loginBtn', 'loginBtn2'].forEach((id) => {
    document.getElementById(id)?.addEventListener('click', () => {
      if (identifier.value.trim() && password.value && !robotCheckRow.hidden) robotCheck.focus();
    });
  });

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    showError('');

    if (!captchaVerified || !captchaToken) {
      showError('Complete the CAPTCHA before logging in.');
      return;
    }

    button.disabled = true;
    button.textContent = 'Signing in…';
    try {
      const data = new URLSearchParams();
      data.set('identifier', document.getElementById('loginIdentifier').value.trim());
      data.set('password', document.getElementById('loginPassword').value);
      data.set('captcha-verification', captchaToken);
      const response = await fetch('../php/supabase_login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data,
        credentials: 'same-origin'
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        const loginError = new Error(result.error || 'Login failed.');
        loginError.code = result.code || '';
        throw loginError;
      }

      await confirmServerSession(result.role);

      const session = window.BarangayConnectSession;
      const residentPrintReturn = residentPrintReturnUrl(result.role);
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
        window.location.replace(residentPrintReturn || new URL('../../Admin/html/AdminPage.html', location.href).href);
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
        window.location.replace(residentPrintReturn || new URL('../../Residents/html/ResidentPage.html', location.href).href);
        return;
      }
      throw new Error('Walang BarangayConnect role na naka-assign sa account.');
    } catch (e) {
      const errorMessages = {
        captcha_invalid: 'CAPTCHA verification expired or is invalid. Enter the new code to continue.',
        invalid_credentials: 'Incorrect username/email or password. Check your sign-in details and try again.',
        missing_credentials: 'Enter your username/email and password.',
        rate_limited: 'Too many login attempts. Try again in 15 minutes.'
      };
      showError(errorMessages[e.code] || e.message || 'Login failed. Please try again.');
      captchaVerified = false;
      captchaToken = '';
      if (identifier.value.trim() && password.value) loadChallenge();
    } finally {
      button.textContent = 'Login';
      updateButton();
    }
  });
})();
