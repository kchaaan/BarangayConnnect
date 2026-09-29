/**
 * Philippine mobile: 11 digits, numbers only (matches Front.html registration).
 */
(function (global) {
  function validateMobile11(value) {
    const digits = String(value || '').replace(/\D/g, '');
    return /^\d{11}$/.test(digits);
  }

  function normalizeMobile11(value) {
    return String(value || '').replace(/\D/g, '').slice(0, 11);
  }

  function bindMobileDigitsOnly(el) {
    if (!el) return;

    const digitsOnly = () => {
      const cleaned = normalizeMobile11(el.value);
      if (el.value !== cleaned) el.value = cleaned;
    };

    el.addEventListener('beforeinput', (e) => {
      if (e.inputType === 'insertText' || e.inputType === 'insertCompositionText') {
        if (e.data && /[^\d]/.test(e.data)) e.preventDefault();
      }
    });

    el.addEventListener('paste', (e) => {
      e.preventDefault();
      const paste = e.clipboardData?.getData('text') || '';
      const start = el.selectionStart ?? 0;
      const end = el.selectionEnd ?? 0;
      el.value = normalizeMobile11(
        el.value.slice(0, start) + paste + el.value.slice(end)
      );
    });

    el.addEventListener('keydown', (e) => {
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      if (e.key.length === 1 && !/[0-9]/.test(e.key)) e.preventDefault();
    });

    el.addEventListener('compositionend', digitsOnly);
    el.addEventListener('input', digitsOnly);
  }

  global.BarangayConnectPhone = {
    validateMobile11,
    normalizeMobile11,
    bindMobileDigitsOnly,
  };
})(window);
