(function () {
  'use strict';

  const eyeIcon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
  const eyeOffIcon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a16 16 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10.5 10.5 0 0 0 4.1-.8"/></svg>';

  document.querySelectorAll('[data-password-targets]').forEach((button) => {
    const fields = (button.dataset.passwordTargets || '')
      .split(/\s+/)
      .map((id) => document.getElementById(id))
      .filter(Boolean);
    if (!fields.length) return;

    button.addEventListener('click', () => {
      const shouldShow = button.getAttribute('aria-pressed') !== 'true';
      fields.forEach((field) => { field.type = shouldShow ? 'text' : 'password'; });
      button.setAttribute('aria-pressed', String(shouldShow));
      button.setAttribute('aria-label', shouldShow ? 'Itago ang password' : 'Ipakita ang password');
      button.innerHTML = shouldShow ? eyeOffIcon : eyeIcon;
    });
  });
})();
