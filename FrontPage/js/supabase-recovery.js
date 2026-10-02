(function () {
  'use strict';

  const hash = new URLSearchParams(window.location.hash.replace(/^#/, ''));
  const accessToken = hash.get('access_token') || '';
  const isRecovery = hash.get('type') === 'recovery' && Boolean(accessToken);
  const isExpiredRecovery = hash.get('error_code') === 'otp_expired';
  if (!isRecovery && !isExpiredRecovery) return;

  window.BC_PASSWORD_RECOVERY = true;

  document.addEventListener('DOMContentLoaded', () => {
    const gate = document.getElementById('passwordRecoveryGate');
    const form = document.getElementById('passwordRecoveryForm');
    const message = document.getElementById('passwordRecoveryMessage');
    const error = document.getElementById('passwordRecoveryError');
    const submit = document.getElementById('passwordRecoverySubmit');
    if (!gate || !form || !message || !error || !submit) return;

    gate.hidden = false;
    document.body.classList.add('privacy-gate-open');
    Array.from(document.body.children).forEach((node) => {
      if (node !== gate) node.inert = true;
    });

    if (!isRecovery) {
      message.textContent = 'This recovery link has expired or was already used. Request a new password recovery email, then open the newest link.';
      form.hidden = true;
    }

    document.getElementById('passwordRecoveryBack')?.addEventListener('click', () => {
      history.replaceState(null, '', location.pathname + location.search);
      window.location.reload();
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      error.textContent = '';
      const password = document.getElementById('recoveryPassword')?.value || '';
      const confirmation = document.getElementById('recoveryPasswordConfirm')?.value || '';
      if (password.length < 8) {
        error.textContent = 'Gumamit ng password na hindi bababa sa 8 character.';
        return;
      }
      if (password !== confirmation) {
        error.textContent = 'Hindi magkapareho ang dalawang password.';
        return;
      }

      submit.disabled = true;
      submit.textContent = 'Saving…';
      try {
        const response = await fetch('../php/supabase_password_recovery.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ access_token: accessToken, password })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Hindi na-update ang password. Humingi ng bagong recovery link.');

        history.replaceState(null, '', location.pathname + location.search);
        form.hidden = true;
        message.textContent = 'Your password has been updated. Return to the login page and sign in with your new password.';
        submit.textContent = 'Password updated';
      } catch (e) {
        error.textContent = e.message || 'Hindi na-update ang password. Humingi ng bagong recovery link.';
        submit.disabled = false;
        submit.textContent = 'Save new password';
      }
    });
  });
})();

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('recoveryOtpForm');
    const message = document.getElementById('recoveryOtpMessage');
    const submit = document.getElementById('recoveryOtpSubmit');
    if (!form || !message || !submit) return;

    const codeInput = document.getElementById('recoveryOtpCode');
    codeInput?.addEventListener('input', () => {
      codeInput.value = codeInput.value.replace(/\D/g, '').slice(0, 6);
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      message.classList.remove('is-success');
      message.textContent = '';

      const email = document.getElementById('recoveryOtpEmail')?.value.trim() || '';
      const token = codeInput?.value.trim() || '';
      const password = document.getElementById('recoveryOtpNewPassword')?.value || '';
      const confirmation = document.getElementById('recoveryOtpConfirmPassword')?.value || '';
      if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        message.textContent = 'Enter the email address registered to your account.';
        return;
      }
      if (!/^\d{6}$/.test(token)) {
        message.textContent = 'Enter the six-digit recovery code from your email.';
        return;
      }
      if (password.length < 8) {
        message.textContent = 'Use a password with at least 8 characters.';
        return;
      }
      if (password !== confirmation) {
        message.textContent = 'The new passwords do not match.';
        return;
      }

      submit.disabled = true;
      submit.textContent = 'Verifying…';
      try {
        const response = await fetch('../php/supabase_password_recovery_otp.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          cache: 'no-store',
          body: JSON.stringify({ email, token, password, confirm_password: confirmation })
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
          throw new Error(result.error || 'Could not reset the password. Ask staff to send a new code.');
        }

        form.reset();
        form.querySelectorAll('input').forEach((input) => { input.disabled = true; });
        message.classList.add('is-success');
        message.textContent = 'Your password was updated. Return to the login page and sign in with your new password.';
        submit.textContent = 'Password updated';
      } catch (error) {
        message.textContent = error.message || 'Password reset is temporarily unavailable. Try again later.';
        submit.disabled = false;
        submit.textContent = 'Verify code and save new password';
      }
    });
  });
})();
