// Wait for ALL HTML to load first
document.addEventListener('DOMContentLoaded', () => {
  console.log("✅ Script loaded without crashing!");

  /** PHP endpoints (same folder as Front.html → ../php/). Broken if you open the page as file:// */
  const phpApiUrl = (file) => new URL(`../php/${file}`, window.location.href).href;
  if (window.location.protocol === 'file:') {
    console.warn(
      'BarangayConnect requires the configured PHP server and Supabase backend. Opening this file directly (file://) will not run authentication or data services.'
    );
  }

  /** Use shared notification UI for consistency across the system. */
  function showAppAlert(message, title) {
    if (window.bcNotify) {
      window.bcNotify.info(message, { title: title || 'Notice' });
      return;
    }
    window.alert(message);
  }

  function getRegisterFieldContainer(field) {
    if (!field) return null;
    if (field.id === 'regTermsCheckbox') {
      return field.closest('.terms-consent');
    }
    if (field.type === 'radio' && field.name) {
      return field.closest('[role="group"]') || field.closest('.register-voter-row, .register-toggle-row') || field.parentElement;
    }
    return (
      field.closest(
        '.register-field-col, .register-field-full, .reg-religion-other, .reg-checkbox-row, .terms-consent, .register-voter-row, .register-toggle-row'
      ) || field.parentElement
    );
  }

  function clearRegisterErrors(scope) {
    const root = scope || document.getElementById('registerForm');
    if (!root) return;
    root.querySelectorAll('.register-field--error').forEach((el) => {
      el.classList.remove('register-field--error');
    });
    root.querySelectorAll('.register-field-error-msg').forEach((el) => el.remove());
    root.querySelectorAll('[aria-invalid="true"]').forEach((el) => el.removeAttribute('aria-invalid'));
  }

  function setRegisterFieldError(target, message) {
    const control =
      typeof target === 'string'
        ? document.getElementById(target)
        : target?.matches?.('input, select, textarea')
          ? target
          : null;
    const container =
      typeof target === 'string'
        ? getRegisterFieldContainer(control)
        : control
          ? getRegisterFieldContainer(control)
          : target;

    if (!container || !message) return;

    container.classList.add('register-field--error');
    if (control) control.setAttribute('aria-invalid', 'true');

    let msgEl = container.querySelector(':scope > .register-field-error-msg');
    if (!msgEl) {
      msgEl = document.createElement('p');
      msgEl.className = 'register-field-error-msg';
      msgEl.setAttribute('role', 'alert');
      container.appendChild(msgEl);
    }
    msgEl.textContent = message;
  }

  function clearRegisterFieldError(target) {
    const control =
      typeof target === 'string'
        ? document.getElementById(target)
        : target?.matches?.('input, select, textarea')
          ? target
          : null;
    const container =
      typeof target === 'string'
        ? getRegisterFieldContainer(control)
        : control
          ? getRegisterFieldContainer(control)
          : target;

    if (!container) return;
    container.classList.remove('register-field--error');
    container.querySelectorAll(':scope > .register-field-error-msg').forEach((el) => el.remove());
    if (control) control.removeAttribute('aria-invalid');
  }

  function focusFirstRegisterError(scope) {
    const root = scope || document.getElementById('registerForm');
    if (!root) return;
    const invalid =
      root.querySelector(
        '.register-field--error input:not([type="checkbox"]):not([type="radio"]):not([disabled]), .register-field--error select:not([disabled]), .register-field--error textarea:not([disabled])'
      ) ||
      root.querySelector('.register-field--error input[type="radio"]:not([disabled])');
    if (!invalid) return;
    invalid.focus({ preventScroll: true });
    invalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function getPasswordStrength(password) {
    const value = String(password || '');
    if (!value) return 'empty';

    const normalized = value.toLowerCase();
    const commonPatterns = [
      'password', 'qwerty', 'letmein', 'welcome', 'admin', 'barangay', 'tambo',
      'iloveyou', '123456', '654321', '111111'
    ];
    const hasCommonPattern = commonPatterns.some((pattern) => normalized.includes(pattern));
    const sequenceText = normalized.replace(/[^a-z0-9]/g, '');
    let sequenceRun = 1;
    let sequenceDirection = 0;
    let hasLongSequence = /(?:qwerty|asdfgh|zxcvbn|qwert|asdf|zxcv)/i.test(value);
    for (let i = 1; i < sequenceText.length && !hasLongSequence; i += 1) {
      const previous = sequenceText[i - 1];
      const current = sequenceText[i];
      const sameCharacterType = /[a-z]/.test(previous) === /[a-z]/.test(current);
      const difference = current.charCodeAt(0) - previous.charCodeAt(0);
      if (sameCharacterType && Math.abs(difference) === 1 && (!sequenceDirection || difference === sequenceDirection)) {
        sequenceDirection = difference;
        sequenceRun += 1;
        if (sequenceRun >= 4) hasLongSequence = true;
      } else {
        sequenceDirection = 0;
        sequenceRun = 1;
      }
    }
    const isMostlyRepeated = value.length >= 4 && new Set(value).size <= Math.max(2, Math.floor(value.length / 4));
    const groups = [/[a-z]/.test(value), /[A-Z]/.test(value), /\d/.test(value), /[^a-zA-Z0-9]/.test(value)]
      .filter(Boolean).length;
    const uniqueCharacters = new Set(normalized).size;
    const words = normalized.trim().split(/\s+/).filter((word) => word.length >= 3);
    const isLongPassphrase = value.length >= 16 && words.length >= 3 && new Set(words).size >= 3;

    if (value.length < 8 || hasCommonPattern || hasLongSequence || isMostlyRepeated) return 'weak';
    if ((value.length >= 12 && groups >= 3 && uniqueCharacters >= 8) || isLongPassphrase) return 'strong';
    if (value.length >= 8 && groups >= 2 && uniqueCharacters >= 6) return 'medium';
    return 'weak';
  }

  function updateRegisterPasswordStrength() {
    const input = document.getElementById('regPassword');
    const meter = document.getElementById('regPasswordStrength');
    if (!input || !meter) return;

    const level = getPasswordStrength(input.value);
    meter.dataset.level = level;
    const label = meter.querySelector('.password-strength__label');
    if (label) {
      label.textContent = ({
        empty: 'Password strength',
        weak: 'Very Weak',
        medium: 'Medium',
        strong: 'Very Strong'
      })[level];
    }
  }

  function hideAppAlert() {
    document.getElementById('customAlert')?.classList.remove('show');
  }

  document.getElementById('customAlertOkBtn')?.addEventListener('click', hideAppAlert);
  document.querySelector('#customAlert .custom-alert-backdrop')?.addEventListener('click', hideAppAlert);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.getElementById('customAlert')?.classList.contains('show')) {
      hideAppAlert();
    }
  });

  // ===============================
  // OPEN MODALS
  // ===============================
  const registerBtn = document.getElementById('registerBtn');
  const loginBtn = document.getElementById('loginBtn');

  // ----- Register wizard helpers (multi-step) -----
  function setRegisterStep(step) {
    const wizard = document.querySelector('#registerForm .register-wizard');
    if (!wizard) return;
    const s = Math.max(1, Math.min(4, Number(step) || 1));
    wizard.dataset.currentStep = String(s);
    document.querySelectorAll('#registerForm .register-step').forEach((el) => {
      const n = Number(el.dataset.step);
      el.classList.toggle('is-active', n === s);
    });
    document.querySelectorAll('#registerForm .register-progress__step').forEach((el) => {
      const n = Number(el.dataset.step);
      el.classList.toggle('is-current', n === s);
      el.classList.toggle('is-done', n < s);
      if (n === s) el.setAttribute('aria-current', 'step');
      else el.removeAttribute('aria-current');
    });
    const stepNumEl = document.getElementById('regWizardStepNum');
    if (stepNumEl) stepNumEl.textContent = String(s);
    if (window.BarangayConnectNavPersist) {
      const regModal = document.getElementById('registerModal');
      const regOpen =
        regModal && regModal.style.display && regModal.style.display !== 'none';
      window.BarangayConnectNavPersist.saveRegisterWizard(s, regOpen);
    }
  }

  function resetRegisterWizard() {
    const form = document.getElementById('registerForm');
    if (form) form.reset();
    if (form) form.dataset.stage = 'details';
    const wizard = form?.querySelector('.register-wizard');
    if (wizard) wizard.hidden = false;
    const otpPanel = document.getElementById('registerOtpPanel');
    if (otpPanel) otpPanel.hidden = true;
    const otpCode = document.getElementById('registerOtpCode');
    if (otpCode) otpCode.value = '';
    const otpMessage = document.getElementById('registerOtpMessage');
    if (otpMessage) {
      otpMessage.textContent = '';
      otpMessage.classList.remove('is-success');
    }
    const noMid = document.getElementById('regNoMiddle');
    const mid = document.getElementById('regMiddleName');
    const wrap = document.getElementById('regMiddleWrap');
    if (noMid) noMid.checked = false;
    if (mid) {
      mid.disabled = false;
      mid.value = '';
    }
    if (wrap) wrap.classList.remove('is-muted');
    const ageEl = document.getElementById('regAge');
    if (ageEl) ageEl.value = '';
    setRegisterStep(1);
    if (window.BarangayConnectNavPersist) {
      window.BarangayConnectNavPersist.saveRegisterWizard(1, false);
    }
    const pw = document.getElementById('regPassword');
    const cpw = document.getElementById('regConfirmPassword');
    if (pw) pw.type = 'password';
    if (cpw) cpw.type = 'password';
    updateRegisterPasswordStrength();
    const showPw = document.getElementById('showRegPassword');
    if (showPw) showPw.checked = false;

    const voterSel = document.getElementById('regVoterStatus');
    if (voterSel) voterSel.selectedIndex = 0;
    const vehSel = document.getElementById('regVehicleOwner');
    if (vehSel) vehSel.selectedIndex = 0;
    const sectors = document.getElementById('regSectors');
    if (sectors) sectors.value = '';
    document.getElementById('regVehicleType')?.removeAttribute('required');
    document.getElementById('regPlateNumber')?.removeAttribute('required');
    const relSel = document.getElementById('regReligionSelect');
    if (relSel) relSel.selectedIndex = 0;
    const relOther = document.getElementById('regReligionOther');
    if (relOther) relOther.value = '';
    const relHid = document.getElementById('regReligion');
    if (relHid) relHid.value = '';
    syncRegReligionHidden();
    updateResidentTypeConditionals();
    updateVoterPrecinct();
    updateVehicleFields();
    updateEmploymentConditionals();
    updateCitizenshipConditionals();
    clearRegisterErrors();
  }

  function computeAgeFromIsoDate(isoDate) {
    if (!isoDate) return '';
    const birth = new Date(`${isoDate}T12:00:00`);
    if (Number.isNaN(birth.getTime())) return '';
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    const m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age -= 1;
    return age >= 0 && age < 130 ? String(age) : '';
  }

  function validateRegisterStep1() {
    const stepEl = document.querySelector('#registerForm .register-step[data-step="1"]');
    clearRegisterErrors(stepEl);

    const first = document.getElementById('regFirstName')?.value.trim();
    const last = document.getElementById('regLastName')?.value.trim();
    const noMiddle = document.getElementById('regNoMiddle')?.checked;
    const middle = document.getElementById('regMiddleName')?.value.trim();
    const gender = document.getElementById('regGender')?.value;
    const birth = document.getElementById('regBirthdate')?.value;
    const civil = document.getElementById('regCivilStatus')?.value;
    const place = document.getElementById('regPlaceOfBirth')?.value.trim();
    const nat = document.getElementById('regNationality')?.value.trim();
    let ok = true;

    if (!first) {
      setRegisterFieldError('regFirstName', 'First name is required.');
      ok = false;
    }
    if (!last) {
      setRegisterFieldError('regLastName', 'Last name is required.');
      ok = false;
    }
    if (!noMiddle && !middle) {
      setRegisterFieldError('regMiddleName', 'Enter middle name or check "I don\'t have a middle name".');
      ok = false;
    }
    if (!gender) {
      setRegisterFieldError('regGender', 'Please select your gender.');
      ok = false;
    }
    if (!birth) {
      setRegisterFieldError('regBirthdate', 'Please select your date of birth.');
      ok = false;
    } else {
      const ageStr = computeAgeFromIsoDate(birth);
      if (!ageStr) {
        setRegisterFieldError('regBirthdate', 'Please enter a valid date of birth.');
        ok = false;
      } else {
        const ageEl = document.getElementById('regAge');
        if (ageEl) ageEl.value = ageStr;
      }
    }
    if (!civil) {
      setRegisterFieldError('regCivilStatus', 'Please select your civil status.');
      ok = false;
    }
    if (!place) {
      setRegisterFieldError('regPlaceOfBirth', 'Place of birth is required.');
      ok = false;
    }
    if (!nat) {
      setRegisterFieldError('regNationality', 'Nationality is required.');
      ok = false;
    }

    const selRel = document.getElementById('regReligionSelect')?.value;
    if (!selRel) {
      setRegisterFieldError('regReligionSelect', 'Please select your religion.');
      ok = false;
    } else if (selRel === '__other__' && !document.getElementById('regReligionOther')?.value.trim()) {
      setRegisterFieldError('regReligionOther', 'Please specify your religion.');
      ok = false;
    }

    syncRegReligionHidden();
    const rel = document.getElementById('regReligion')?.value.trim();
    if (ok && selRel && !rel) {
      setRegisterFieldError('regReligionSelect', 'Please complete your religion.');
      ok = false;
    }

    if (!ok) focusFirstRegisterError(stepEl);
    return ok;
  }

  function validateRegisterStep2() {
    const stepEl = document.querySelector('#registerForm .register-step[data-step="2"]');
    clearRegisterErrors(stepEl);

    const street = document.getElementById('regStreet')?.value.trim();
    const brgy = document.getElementById('regBarangay')?.value.trim();
    const city = document.getElementById('regCity')?.value.trim();
    const prov = document.getElementById('regProvince')?.value.trim();
    const region = document.getElementById('regRegion')?.value.trim();
    const resType = document.getElementById('regResidentType')?.value;
    const years = document.getElementById('regYearsResidency')?.value;
    const yearsNum = years !== '' && years != null ? Number(years) : NaN;
    let ok = true;

    if (!street) {
      setRegisterFieldError('regStreet', 'Please select your street.');
      ok = false;
    }
    if (!brgy) {
      setRegisterFieldError('regBarangay', 'Barangay is required.');
      ok = false;
    }
    if (!city) {
      setRegisterFieldError('regCity', 'City / municipality is required.');
      ok = false;
    }
    if (!prov) {
      setRegisterFieldError('regProvince', 'Province is required.');
      ok = false;
    }
    if (!region) {
      setRegisterFieldError('regRegion', 'Region is required.');
      ok = false;
    }
    if (!resType) {
      setRegisterFieldError('regResidentType', 'Please select your resident type.');
      ok = false;
    }
    if (years === '' || years == null || Number.isNaN(yearsNum) || yearsNum < 0) {
      setRegisterFieldError('regYearsResidency', 'Enter years of residency (0 or greater).');
      ok = false;
    }

    const needsOwner = ['Renter', 'Boarder', 'Caretaker', 'Relative Owner'].includes(resType);
    const needsEmployer = ['Kasambahay', 'Barracks'].includes(resType);
    if (needsOwner && !document.getElementById('regOwnerName')?.value.trim()) {
      setRegisterFieldError('regOwnerName', 'Name of owner is required.');
      ok = false;
    }
    if (needsEmployer) {
      if (!document.getElementById('regEmployerName')?.value.trim()) {
        setRegisterFieldError('regEmployerName', 'Employer name is required.');
        ok = false;
      }
      if (!document.getElementById('regEmployerAddress')?.value.trim()) {
        setRegisterFieldError('regEmployerAddress', 'Employer address is required.');
        ok = false;
      }
    }

    if (!ok) focusFirstRegisterError(stepEl);
    return ok;
  }

  function validateRegisterStep3() {
    const stepEl = document.querySelector('#registerForm .register-step[data-step="3"]');
    clearRegisterErrors(stepEl);

    const phone = document.getElementById('regMobile')?.value.trim();
    const email = document.getElementById('regEmail')?.value.trim();
    const employmentStatus = document.getElementById('regEmploymentStatus')?.value || '';
    const citizenshipStatus = document.getElementById('regCitizenshipStatus')?.value || '';
    const voterStatus = document.getElementById('regVoterStatus')?.value || '';
    const precinct = document.getElementById('regPrecinctNumber')?.value.trim();
    let ok = true;

    if (!/^\d{11}$/.test(phone || '')) {
      setRegisterFieldError('regMobile', 'Mobile number must be exactly 11 digits.');
      ok = false;
    }
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setRegisterFieldError('regEmail', 'Please enter a valid email address.');
      ok = false;
    }
    if (!employmentStatus) {
      setRegisterFieldError('regEmploymentStatus', 'Please select your employment status.');
      ok = false;
    } else if (employmentStatusNeedsDetail(employmentStatus)) {
      const detail = document.getElementById('regEmploymentDetail')?.value.trim();
      if (!detail) {
        setRegisterFieldError('regEmploymentDetail', 'This field is required for your employment status.');
        ok = false;
      }
    }
    if (!citizenshipStatus) {
      setRegisterFieldError('regCitizenshipStatus', 'Please select your citizenship.');
      ok = false;
    } else if (citizenshipStatus === 'dual' && !document.getElementById('regSecondCitizenship')?.value.trim()) {
      setRegisterFieldError('regSecondCitizenship', 'Second citizenship is required.');
      ok = false;
    } else if (citizenshipStatus === 'foreign' && !document.getElementById('regForeignNationality')?.value.trim()) {
      setRegisterFieldError('regForeignNationality', 'Nationality is required.');
      ok = false;
    } else if (citizenshipStatus === 'others' && !document.getElementById('regCitizenshipOther')?.value.trim()) {
      setRegisterFieldError('regCitizenshipOther', 'Please specify your citizenship.');
      ok = false;
    }
    if (!voterStatus) {
      setRegisterFieldError('regVoterStatus', 'Please select voter status (Yes or No).');
      ok = false;
    } else if (voterStatus === 'Yes' && !precinct) {
      setRegisterFieldError('regPrecinctNumber', 'Precinct number is required for registered voters.');
      ok = false;
    }

    const vehicleOwner = document.getElementById('regVehicleOwner')?.value || '';
    if (!vehicleOwner) {
      setRegisterFieldError('regVehicleOwner', 'Please select vehicle owner status (Yes or No).');
      ok = false;
    } else if (vehicleOwner === 'yes') {
      if (!document.getElementById('regVehicleType')?.value.trim()) {
        setRegisterFieldError('regVehicleType', 'Vehicle type is required.');
        ok = false;
      }
      if (!document.getElementById('regPlateNumber')?.value.trim()) {
        setRegisterFieldError('regPlateNumber', 'Plate number is required.');
        ok = false;
      }
    }

    if (!ok) focusFirstRegisterError(stepEl);
    return ok;
  }

  function validateRegisterStep4() {
    const stepEl = document.querySelector('#registerForm .register-step[data-step="4"]');
    clearRegisterErrors(stepEl);

    const username = document.getElementById('regUsername')?.value.trim();
    const password = document.getElementById('regPassword')?.value.trim();
    const confirmPassword = document.getElementById('regConfirmPassword')?.value.trim();
    const termsChecked = document.getElementById('regTermsCheckbox')?.checked;
    let ok = true;

    if (!username) {
      setRegisterFieldError('regUsername', 'Please choose a username.');
      ok = false;
    }
    if (!password) {
      setRegisterFieldError('regPassword', 'Password is required.');
      ok = false;
    }
    if (!confirmPassword) {
      setRegisterFieldError('regConfirmPassword', 'Please confirm your password.');
      ok = false;
    } else if (password && password !== confirmPassword) {
      setRegisterFieldError('regConfirmPassword', 'Passwords do not match.');
      ok = false;
    }
    if (!termsChecked) {
      setRegisterFieldError('regTermsCheckbox', 'You must agree to the Terms & Conditions.');
      ok = false;
    }

    if (!ok) focusFirstRegisterError(stepEl);
    return ok;
  }

  function buildHouseStreetLine() {
    const unit = document.getElementById('regHouseUnit')?.value.trim() || '';
    const blk = document.getElementById('regBlockLot')?.value.trim() || '';
    const street = document.getElementById('regStreet')?.value.trim() || '';
    const parts = [];
    if (unit) parts.push(`House/Unit ${unit}`);
    if (blk) parts.push(`Blk/Lot ${blk}`);
    if (street) parts.push(street);
    return parts.join(' · ') || street;
  }

  function normalizeIdNumber(value) {
    return String(value || '').trim().toUpperCase().replace(/[\s-]+/g, '');
  }

  function buildResidentIdentityKey(first, middle, last, suffix, birthdate, street, barangay, city, province) {
    const norm = (s) => String(s || '').toLowerCase().trim().replace(/\s+/g, ' ');
    const nameParts = [norm(first), norm(middle), norm(last), norm(suffix)].filter(Boolean);
    const addrParts = [norm(street), norm(barangay), norm(city), norm(province)].filter(Boolean);
    return `${nameParts.join('|')}||${birthdate || ''}||${addrParts.join('|')}`;
  }

  function collectProfileExtra() {
    syncRegReligionHidden();
    const voterStatus = document.getElementById('regVoterStatus')?.value || '';
    const vehicleOwner = document.getElementById('regVehicleOwner')?.value || '';
    const sectorOrg = document.getElementById('regSectors')?.value?.trim() || '';
    const sectors = sectorOrg ? [sectorOrg] : [];
    return {
      suffix: document.getElementById('regSuffix')?.value.trim() || '',
      barangayIdNumber: normalizeIdNumber(document.getElementById('regBarangayIdNumber')?.value),
      nationalIdNumber: normalizeIdNumber(document.getElementById('regNationalIdNumber')?.value),
      voterIdNumber: normalizeIdNumber(document.getElementById('regVoterIdNumber')?.value),
      placeOfBirth: document.getElementById('regPlaceOfBirth')?.value.trim() || '',
      nationality: document.getElementById('regNationality')?.value.trim() || '',
      religion: document.getElementById('regReligion')?.value.trim() || '',
      blockLot: document.getElementById('regBlockLot')?.value.trim() || '',
      houseUnit: document.getElementById('regHouseUnit')?.value.trim() || '',
      region: document.getElementById('regRegion')?.value.trim() || '',
      residentType: document.getElementById('regResidentType')?.value || '',
      ownerName: document.getElementById('regOwnerName')?.value.trim() || '',
      employerName: document.getElementById('regEmployerName')?.value.trim() || '',
      employerAddress: document.getElementById('regEmployerAddress')?.value.trim() || '',
      yearsResidency: document.getElementById('regYearsResidency')?.value ?? '',
      employment: buildEmploymentValue(),
      employmentStatus: document.getElementById('regEmploymentStatus')?.value || '',
      employmentDetail: document.getElementById('regEmploymentDetail')?.value.trim() || '',
      citizenship: buildCitizenshipValue(),
      citizenshipStatus: document.getElementById('regCitizenshipStatus')?.value || '',
      secondCitizenship: document.getElementById('regSecondCitizenship')?.value.trim() || '',
      foreignNationality: document.getElementById('regForeignNationality')?.value.trim() || '',
      citizenshipOther: document.getElementById('regCitizenshipOther')?.value.trim() || '',
      voterStatus: voterStatus,
      precinctNumber: document.getElementById('regPrecinctNumber')?.value.trim() || '',
      bloodType: document.getElementById('regBloodType')?.value || '',
      vehicleOwner: vehicleOwner,
      vehicleType: document.getElementById('regVehicleType')?.value.trim() || '',
      plateNumber: document.getElementById('regPlateNumber')?.value.trim() || '',
      sectors
    };
  }

  const EMPLOYMENT_STATUS_LABELS = {
    employed_government: 'Employed – Government',
    employed_private: 'Employed – Private Sector',
    self_employed: 'Self-Employed',
    business_owner: 'Business Owner',
    ofw: 'OFW',
    student: 'Student',
    unemployed: 'Unemployed',
    retired: 'Retired',
    homemaker: 'Homemaker',
    others: 'Others (Specify)',
  };

  const EMPLOYMENT_DETAIL_CONFIG = {
    employed_government: {
      label: 'Occupation / Employer',
      placeholder: 'e.g. Administrative Aide, City Hall',
    },
    employed_private: {
      label: 'Occupation / Employer',
      placeholder: 'e.g. Sales Associate, ABC Corp.',
    },
    self_employed: {
      label: 'Type of Work / Business',
      placeholder: 'e.g. Freelance graphic design',
    },
    business_owner: {
      label: 'Business Name',
      placeholder: 'e.g. Juan Sari-Sari Store',
    },
    ofw: {
      label: 'Country of Employment',
      placeholder: 'e.g. Saudi Arabia, UAE',
    },
    others: {
      label: 'Specify Employment',
      placeholder: 'Describe your employment status',
    },
  };

  function employmentStatusNeedsDetail(status) {
    return Object.prototype.hasOwnProperty.call(EMPLOYMENT_DETAIL_CONFIG, status);
  }

  function buildEmploymentValue() {
    const status = document.getElementById('regEmploymentStatus')?.value || '';
    const label = EMPLOYMENT_STATUS_LABELS[status] || status;
    if (!status) return '';
    if (!employmentStatusNeedsDetail(status)) return label;
    const detail = document.getElementById('regEmploymentDetail')?.value.trim() || '';
    return detail ? `${label}: ${detail}` : label;
  }

  function buildCitizenshipValue() {
    const status = document.getElementById('regCitizenshipStatus')?.value || 'filipino';
    if (status === 'filipino') return 'Filipino';
    if (status === 'dual') {
      const second = document.getElementById('regSecondCitizenship')?.value.trim() || '';
      return second ? `Dual Citizenship (Filipino + ${second})` : 'Dual Citizenship';
    }
    if (status === 'foreign') {
      const nat = document.getElementById('regForeignNationality')?.value.trim() || '';
      return nat ? `Foreign National: ${nat}` : 'Foreign National';
    }
    if (status === 'stateless') return 'Stateless';
    if (status === 'others') {
      const other = document.getElementById('regCitizenshipOther')?.value.trim() || '';
      return other ? `Others: ${other}` : 'Others (Specify)';
    }
    return '';
  }

  function updateEmploymentConditionals() {
    const status = document.getElementById('regEmploymentStatus')?.value || '';
    const block = document.getElementById('regEmploymentDetailBlock');
    const detail = document.getElementById('regEmploymentDetail');
    const labelEl = document.getElementById('regEmploymentDetailLabel');
    const cfg = EMPLOYMENT_DETAIL_CONFIG[status];
    const show = !!cfg;

    block?.classList.toggle('is-visible', show);
    if (labelEl && cfg) {
      labelEl.innerHTML = `${cfg.label} <span aria-hidden="true">*</span>`;
    }
    if (detail) {
      detail.required = show;
      detail.disabled = !show;
      if (cfg?.placeholder) detail.placeholder = cfg.placeholder;
      if (!show) detail.value = '';
    }
  }

  function updateCitizenshipConditionals() {
    const status = document.getElementById('regCitizenshipStatus')?.value || 'filipino';
    const dualBlock = document.getElementById('regSecondCitizenshipBlock');
    const foreignBlock = document.getElementById('regForeignNationalityBlock');
    const otherBlock = document.getElementById('regCitizenshipOtherBlock');
    const second = document.getElementById('regSecondCitizenship');
    const foreignNat = document.getElementById('regForeignNationality');
    const other = document.getElementById('regCitizenshipOther');

    dualBlock?.classList.toggle('is-visible', status === 'dual');
    foreignBlock?.classList.toggle('is-visible', status === 'foreign');
    otherBlock?.classList.toggle('is-visible', status === 'others');

    if (second) {
      second.required = status === 'dual';
      second.disabled = status !== 'dual';
      if (status !== 'dual') second.value = '';
    }
    if (foreignNat) {
      foreignNat.required = status === 'foreign';
      foreignNat.disabled = status !== 'foreign';
      if (status !== 'foreign') foreignNat.value = '';
    }
    if (other) {
      other.required = status === 'others';
      other.disabled = status !== 'others';
      if (status !== 'others') other.value = '';
    }
  }

  function updateResidentTypeConditionals() {
    const type = document.getElementById('regResidentType')?.value || '';
    const ownerBlock = document.getElementById('regOwnerNameBlock');
    const empBlock = document.getElementById('regEmployerBlock');
    const needsOwner = ['Renter', 'Boarder', 'Caretaker', 'Relative Owner'].includes(type);
    const needsEmployer = ['Kasambahay', 'Barracks'].includes(type);
    ownerBlock?.classList.toggle('is-visible', needsOwner);
    empBlock?.classList.toggle('is-visible', needsEmployer);
    const on = document.getElementById('regOwnerName');
    if (on) on.required = needsOwner;
    const en = document.getElementById('regEmployerName');
    const ea = document.getElementById('regEmployerAddress');
    if (en) en.required = needsEmployer;
    if (ea) ea.required = needsEmployer;
  }

  function updateVoterPrecinct() {
    const status = document.getElementById('regVoterStatus')?.value || '';
    const yes = status === 'Yes';
    document.getElementById('regPrecinctBlock')?.classList.toggle('is-visible', yes);
    const pr = document.getElementById('regPrecinctNumber');
    if (pr) {
      pr.required = yes;
      pr.disabled = !yes;
      if (!yes) pr.value = '';
    }
  }

  function updateVehicleFields() {
    const owner = document.getElementById('regVehicleOwner')?.value || '';
    const yes = owner === 'yes';
    const vehicleFields = document.getElementById('regVehicleFields');
    vehicleFields?.classList.toggle('is-visible', yes);
    const vt = document.getElementById('regVehicleType');
    const pn = document.getElementById('regPlateNumber');
    if (vt) {
      vt.required = yes;
      vt.disabled = !yes;
      if (!yes) vt.value = '';
    }
    if (pn) {
      pn.required = yes;
      pn.disabled = !yes;
      if (!yes) pn.value = '';
    }
  }

  function syncRegReligionHidden() {
    const sel = document.getElementById('regReligionSelect');
    const other = document.getElementById('regReligionOther');
    const hid = document.getElementById('regReligion');
    const wrap = document.getElementById('regReligionOtherWrap');
    if (!hid) return;
    const v = sel?.value || '';
    const isOther = v === '__other__';
    wrap?.classList.toggle('is-visible', isOther);
    if (isOther) {
      hid.value = (other?.value || '').trim();
    } else {
      hid.value = v;
    }
  }

  // Open register modal
  registerBtn?.addEventListener('click', () => {
    document.getElementById('registerModal')?.style.setProperty('display', 'block');
    resetRegisterWizard();
    const pendingOtp = sessionStorage.getItem('bcPendingRegistration');
    const legacyPendingEmail = sessionStorage.getItem('bcPendingSignupEmail');
    if (pendingOtp) {
      try { showRegistrationOtp(JSON.parse(pendingOtp)); } catch { sessionStorage.removeItem('bcPendingRegistration'); }
    } else if (legacyPendingEmail) {
      showRegistrationOtp({ method: 'email', target: legacyPendingEmail, email: legacyPendingEmail, phone: '' });
    }
  });

  function closeRegisterModal() {
    document.getElementById('registerModal')?.style.setProperty('display', 'none');
    if (window.BarangayConnectNavPersist) {
      window.BarangayConnectNavPersist.saveRegisterWizard(null, false);
    }
    resetRegisterWizard();
  }

  // Open login modal
  loginBtn?.addEventListener('click', () => {
    document.getElementById('loginModal')?.style.setProperty('display', 'block');
  });

  // ===============================
  // FORGOT PASSWORD
  // ===============================
  const forgotLink = document.getElementById('forgotPasswordLink');
  forgotLink?.addEventListener('click', (e) => {
    e.preventDefault();
    document.getElementById('forgotPasswordModal')?.style.setProperty('display', 'block');
  });

  // ===============================
  // CLOSE MODALS (X buttons)
  // ===============================
  document.querySelectorAll('.close').forEach(btn => {
    btn?.addEventListener('click', e => {
      const modalId = e.currentTarget.dataset.close;
      if (modalId === 'registerModal') {
        closeRegisterModal();
        return;
      }
      if (modalId) document.getElementById(modalId)?.style.setProperty('display', 'none');
    });
  });

  // ===============================
  // LOGIN MODAL TABS (Resident/Admin)
  // ===============================
  const tabButtons = document.querySelectorAll('.tab-button');
  const residentPanel = document.getElementById('residentLoginPanel');
  const adminPanel = document.getElementById('adminLoginPanel');

  if (tabButtons?.length > 0) {
    tabButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        tabButtons.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const target = btn.dataset.target;

        residentPanel?.classList.toggle('hidden', target !== 'residentLoginPanel');
        adminPanel?.classList.toggle('hidden', target !== 'adminLoginPanel');
      });
    });
  }

  // ===============================
  // OPTIONAL ACTIVITY LOG SUPPORT
  // ===============================
  // If activity-log.js is loaded BEFORE this script and defines window.recordActivity,
  // then this will work. Otherwise it safely does nothing.
  const recordActivity = window.recordActivity;

  // ===============================
  // RESIDENT REGISTRATION — mobile: digits only, exactly 11 (block letters)
  // ===============================
  (function bindRegMobileDigitsOnly() {
    const el = document.getElementById("regMobile");
    if (!el) return;

    const digitsOnly = () => {
      const cleaned = el.value.replace(/\D/g, "").slice(0, 11);
      if (el.value !== cleaned) el.value = cleaned;
    };

    el.addEventListener("beforeinput", (e) => {
      if (e.inputType === "insertText" || e.inputType === "insertCompositionText") {
        if (e.data && /[^\d]/.test(e.data)) e.preventDefault();
      }
    });

    el.addEventListener("paste", (e) => {
      e.preventDefault();
      const paste = e.clipboardData?.getData("text") || "";
      const start = el.selectionStart ?? 0;
      const end = el.selectionEnd ?? 0;
      el.value = (el.value.slice(0, start) + paste + el.value.slice(end))
        .replace(/\D/g, "")
        .slice(0, 11);
    });

    el.addEventListener("keydown", (e) => {
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      if (e.key.length === 1 && !/[0-9]/.test(e.key)) e.preventDefault();
    });

    el.addEventListener("compositionend", digitsOnly);
    el.addEventListener("input", digitsOnly);
  })();

  // ===============================
  // RESIDENT REGISTRATION LOGIC
  // ===============================
  const registerForm = document.getElementById('registerForm');
  let pendingRegistrationOtp = { method: 'email', target: '', email: '', phone: '' };

  const verificationMethodSelect = document.getElementById('regVerificationMethod');
  const verificationMethodNote = document.getElementById('regVerificationMethodNote');
  fetch(phpApiUrl('registration_methods.php'), { credentials: 'same-origin', cache: 'no-store' })
    .then((response) => response.json())
    .then((methods) => {
      const smsOption = verificationMethodSelect?.querySelector('option[value="sms"]');
      if (smsOption && methods?.sms === true) {
        smsOption.disabled = false;
        smsOption.textContent = 'Mobile number (SMS)';
        if (verificationMethodNote) verificationMethodNote.textContent = 'Choose where you want to receive your six-digit verification code.';
      }
    })
    .catch(() => {});

  verificationMethodSelect?.addEventListener('change', () => {
    if (!verificationMethodNote) return;
    verificationMethodNote.textContent = verificationMethodSelect.value === 'sms'
      ? 'A text-message code will be sent to the mobile number above.'
      : 'A verification code will be sent to the email address above.';
  });

  function showRegistrationOtp(details) {
    pendingRegistrationOtp = typeof details === 'string'
      ? { method: 'email', target: details, email: details, phone: '' }
      : { method: details.method || 'email', target: details.target || details.email || '', email: details.email || '', phone: details.phone || '' };
    sessionStorage.setItem('bcPendingRegistration', JSON.stringify(pendingRegistrationOtp));
    sessionStorage.setItem('bcPendingSignupEmail', pendingRegistrationOtp.email || '');
    registerForm.dataset.stage = 'otp';
    const wizard = registerForm.querySelector('.register-wizard');
    if (wizard) wizard.hidden = true;
    const otpPanel = document.getElementById('registerOtpPanel');
    if (otpPanel) otpPanel.hidden = false;
    const destinationLabel = document.getElementById('registerOtpDestination');
    if (destinationLabel) destinationLabel.textContent = pendingRegistrationOtp.target;
    const title = document.getElementById('registerOtpTitle');
    if (title) title.textContent = pendingRegistrationOtp.method === 'sms' ? 'Verify your mobile number' : 'Verify your email';
    const codeLabel = document.querySelector('label[for="registerOtpCode"]');
    if (codeLabel) codeLabel.textContent = pendingRegistrationOtp.method === 'sms' ? 'SMS verification code' : 'Email verification code';
    const resendButton = document.getElementById('registerOtpResendBtn');
    if (resendButton) {
      resendButton.disabled = false;
      resendButton.textContent = 'Resend code';
    }
    const code = document.getElementById('registerOtpCode');
    if (code) {
      code.value = '';
      code.focus();
    }
  }

  function setRegisterOtpMessage(message, success = false) {
    const status = document.getElementById('registerOtpMessage');
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('is-success', success);
  }

  async function verifyRegistrationOtp() {
    const code = document.getElementById('registerOtpCode');
    const button = document.getElementById('registerOtpVerifyBtn');
    const token = (code?.value || '').replace(/\D/g, '');
    if (!/^\d{6}$/.test(token)) {
      setRegisterOtpMessage(`Enter the six-digit code from your ${pendingRegistrationOtp.method === 'sms' ? 'text message' : 'email'}.`);
      code?.focus();
      return;
    }
    button.disabled = true;
    setRegisterOtpMessage('Checking your code…');
    try {
      const response = await fetch(phpApiUrl('verify_register_otp.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ method: pendingRegistrationOtp.method, target: pendingRegistrationOtp.target, token })
      });
      const result = await response.json().catch(() => null);
      if (!response.ok || !result?.success) {
        setRegisterOtpMessage(result?.error || 'Could not verify the code. Try again.');
        code?.focus();
        return;
      }
      localStorage.setItem('residentId', String(result.id || ''));
      localStorage.setItem('residentPortal', 'full');
      localStorage.setItem('residentFirstname', String(result.firstName || ''));
      localStorage.setItem('residentLastname', String(result.lastName || ''));
      sessionStorage.removeItem('bcPendingSignupEmail');
      sessionStorage.removeItem('bcPendingRegistration');
      if (typeof recordActivity === 'function') {
        recordActivity('REGISTER', 'RESIDENT', pendingRegistrationOtp.target, result.fullName || '', { status: 'ACTIVE' });
      }
      closeRegisterModal();
      showAppAlert('Your contact is verified and your BarangayConnect account is registered. You can now sign in.', 'Registered successfully');
    } catch (error) {
      setRegisterOtpMessage('Could not reach the verification service. Your code is still here; try again.');
    } finally {
      button.disabled = false;
    }
  }

  document.getElementById('registerOtpVerifyBtn')?.addEventListener('click', verifyRegistrationOtp);
  document.getElementById('registerOtpCode')?.addEventListener('input', (event) => {
    event.currentTarget.value = event.currentTarget.value.replace(/\D/g, '').slice(0, 6);
    setRegisterOtpMessage('');
  });
  document.getElementById('registerOtpResendBtn')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    button.disabled = true;
    setRegisterOtpMessage('Requesting a new code…');
    try {
      const response = await fetch(phpApiUrl('resend_register_otp.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ method: pendingRegistrationOtp.method, target: pendingRegistrationOtp.target })
      });
      const result = await response.json().catch(() => null);
      if (!response.ok || !result?.success) {
        setRegisterOtpMessage(result?.error || 'Could not resend the code. Try again later.');
        button.disabled = false;
        return;
      }
      setRegisterOtpMessage(pendingRegistrationOtp.method === 'sms'
        ? 'A new code was requested. Check your text messages.'
        : 'A new code was requested. Check your inbox and spam folder.', true);
      let remaining = 60;
      button.textContent = `Resend in ${remaining}s`;
      const timer = window.setInterval(() => {
        remaining -= 1;
        if (remaining <= 0) {
          window.clearInterval(timer);
          button.disabled = false;
          button.textContent = 'Resend code';
          return;
        }
        button.textContent = `Resend in ${remaining}s`;
      }, 1000);
    } catch (error) {
      setRegisterOtpMessage('Could not reach the email service. Try again later.');
      button.disabled = false;
    }
  });

  // ----- Register wizard: birthdate bounds, middle-name toggle, step nav -----
  const regBirthdateEl = document.getElementById('regBirthdate');
  if (regBirthdateEl) {
    const t = new Date();
    const y = t.getFullYear();
    const m = String(t.getMonth() + 1).padStart(2, '0');
    const d = String(t.getDate()).padStart(2, '0');
    regBirthdateEl.max = `${y}-${m}-${d}`;
    regBirthdateEl.min = `${y - 120}-01-01`;
  }
  regBirthdateEl?.addEventListener('change', () => {
    const a = computeAgeFromIsoDate(regBirthdateEl.value);
    const ageEl = document.getElementById('regAge');
    if (ageEl) ageEl.value = a || '';
  });

  document.getElementById('regNoMiddle')?.addEventListener('change', function () {
    const mid = document.getElementById('regMiddleName');
    const wrap = document.getElementById('regMiddleWrap');
    if (this.checked) {
      if (mid) {
        mid.value = '';
        mid.disabled = true;
      }
      wrap?.classList.add('is-muted');
      clearRegisterFieldError('regMiddleName');
    } else if (mid) {
      mid.disabled = false;
      wrap?.classList.remove('is-muted');
    }
  });

  document.getElementById('regStep1Proceed')?.addEventListener('click', () => {
    if (!validateRegisterStep1()) return;
    setRegisterStep(2);
  });
  document.getElementById('regStep2Next')?.addEventListener('click', () => {
    if (!validateRegisterStep2()) return;
    setRegisterStep(3);
  });
  document.getElementById('regStep2Back')?.addEventListener('click', () => setRegisterStep(1));
  document.getElementById('regStep3Next')?.addEventListener('click', () => {
    if (!validateRegisterStep3()) return;
    setRegisterStep(4);
  });
  document.getElementById('regStep3Back')?.addEventListener('click', () => setRegisterStep(2));
  document.getElementById('regStep4Back')?.addEventListener('click', () => setRegisterStep(3));

  document.getElementById('regResidentType')?.addEventListener('change', updateResidentTypeConditionals);
  document.getElementById('regEmploymentStatus')?.addEventListener('change', () => {
    const detail = document.getElementById('regEmploymentDetail');
    if (detail) detail.value = '';
    clearRegisterFieldError('regEmploymentDetail');
    updateEmploymentConditionals();
  });
  document.getElementById('regCitizenshipStatus')?.addEventListener('change', () => {
    ['regSecondCitizenship', 'regForeignNationality', 'regCitizenshipOther'].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.value = '';
      clearRegisterFieldError(id);
    });
    updateCitizenshipConditionals();
  });
  document.getElementById('regVoterStatus')?.addEventListener('change', () => {
    clearRegisterFieldError('regVoterStatus');
    clearRegisterFieldError('regPrecinctNumber');
    updateVoterPrecinct();
  });
  document.getElementById('regVehicleOwner')?.addEventListener('change', () => {
    clearRegisterFieldError('regVehicleOwner');
    clearRegisterFieldError('regVehicleType');
    clearRegisterFieldError('regPlateNumber');
    updateVehicleFields();
  });
  document.getElementById('regReligionSelect')?.addEventListener('change', syncRegReligionHidden);
  document.getElementById('regReligionOther')?.addEventListener('input', syncRegReligionHidden);

  updateEmploymentConditionals();
  updateCitizenshipConditionals();
  updateVoterPrecinct();
  updateVehicleFields();

  registerForm?.addEventListener('input', (e) => {
    const t = e.target;
    if (!t.matches('#registerForm input, #registerForm select, #registerForm textarea')) return;
    if (t.id === 'regPassword') updateRegisterPasswordStrength();
    clearRegisterFieldError(t);
  });

  registerForm?.addEventListener('change', (e) => {
    const t = e.target;
    if (!t.matches('#registerForm input, #registerForm select, #registerForm textarea')) return;
    clearRegisterFieldError(t);
    if (t.id === 'regTermsCheckbox') clearRegisterFieldError('regTermsCheckbox');
  });

  registerForm?.addEventListener('submit', async e => {
    e.preventDefault();
    if (registerForm.dataset.stage === 'otp') {
      verifyRegistrationOtp();
      return;
    }

    if (!validateRegisterStep1()) {
      setRegisterStep(1);
      return;
    }
    if (!validateRegisterStep2()) {
      setRegisterStep(2);
      return;
    }
    if (!validateRegisterStep3()) {
      setRegisterStep(3);
      return;
    }
    if (!validateRegisterStep4()) {
      setRegisterStep(4);
      return;
    }

    const firstNameRaw = document.getElementById('regFirstName')?.value.trim();
    const lastname = document.getElementById('regLastName')?.value.trim();
    const noMiddle = document.getElementById('regNoMiddle')?.checked;
    const middle = noMiddle ? '' : (document.getElementById('regMiddleName')?.value.trim() || '');
    const phone = document.getElementById('regMobile')?.value.trim();
    const email = document.getElementById('regEmail')?.value.trim();
    const username = document.getElementById('regUsername')?.value.trim();
    const password = document.getElementById('regPassword')?.value.trim();
    const confirmPassword = document.getElementById('regConfirmPassword')?.value.trim();
    const termsChecked = document.getElementById('regTermsCheckbox')?.checked;

    const gender = document.getElementById('regGender')?.value;
    const birthdate = document.getElementById('regBirthdate')?.value;
    const civilStatus = document.getElementById('regCivilStatus')?.value;
    const ageStr = document.getElementById('regAge')?.value.trim();
    const street = buildHouseStreetLine();
    const barangay = document.getElementById('regBarangay')?.value.trim();
    const city = document.getElementById('regCity')?.value.trim();
    const province = document.getElementById('regProvince')?.value.trim();
    const profileExtra = collectProfileExtra();

    const apiFirstname =
      middle ? `${firstNameRaw} ${middle}`.trim() : firstNameRaw;

    const displayFullName = [firstNameRaw, middle, lastname].filter(Boolean).join(' ');

    const finishRegisterSuccess = (resident) => {
      localStorage.setItem('residentId', String(resident.id));
      localStorage.setItem('residentPortal', 'full');
      localStorage.setItem('residentFirstname', firstNameRaw);
      localStorage.setItem('residentLastname', lastname);
      if (typeof recordActivity === 'function') {
        recordActivity(
          'REGISTER',
          'RESIDENT',
          email || phone,
          displayFullName,
          { status: resident.status || 'ACTIVE' }
        );
      }
      closeRegisterModal();
      window.location.href = '../../Residents/html/ResidentPage.html';
    };

    const localPayload = {
      firstname: firstNameRaw,
      lastname,
      middlename: middle,
      phoneNum: phone,
      email,
      password,
      username,
      gender,
      birthdate,
      civilStatus,
      age: ageStr,
      street,
      barangay,
      city,
      province,
      profileExtra
    };

    // Supabase Auth + PostgreSQL via the PHP server endpoint.
    const phpBody = new URLSearchParams();
    phpBody.append('first_name', firstNameRaw);
    phpBody.append('middle_name', middle);
    phpBody.append('last_name', lastname);
    phpBody.append('gender', gender || '');
    phpBody.append('birthdate', birthdate || '');
    phpBody.append('age', ageStr || '');
    phpBody.append('civil_status', civilStatus || '');
    phpBody.append('contact_number', phone);
    phpBody.append('email', email);
    phpBody.append('verification_method', verificationMethodSelect?.value || 'email');
    phpBody.append('house_street', street);
    phpBody.append('barangay', barangay);
    phpBody.append('city', city);
    phpBody.append('province', province);
    phpBody.append('username', username);
    phpBody.append('password', password);
    phpBody.append('confirm_password', confirmPassword);
    phpBody.append('barangay_id_number', profileExtra.barangayIdNumber || '');
    phpBody.append('national_id_number', profileExtra.nationalIdNumber || '');
    phpBody.append('voter_id_number', profileExtra.voterIdNumber || '');
    try {
      phpBody.append('profile_extra', JSON.stringify(profileExtra));
    } catch {
      phpBody.append('profile_extra', '');
    }

    const isHttpPage =
      window.location.protocol === 'http:' || window.location.protocol === 'https:';

    try {
      const phpRes = await fetch(phpApiUrl('register.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: phpBody
      });
      const phpText = await phpRes.text();
      let phpJson = null;
      try {
        phpJson = JSON.parse(phpText);
      } catch {
        /* not JSON — PHP may be missing or returned an error page */
      }
      if (phpJson && phpJson.success) {
        showRegistrationOtp({
          method: phpJson.verification_method || 'email',
          target: phpJson.verification_target || phpJson.email || email,
          email: phpJson.email || email,
          phone: phpJson.phone || phone
        });
        return;
      }
      if (phpJson && phpJson.error) {
        showAppAlert(phpJson.error);
        return;
      }
      /* When opened via http://localhost/..., MySQL is the real store. Do not fall through
         to Spring/localStorage silently — that saves only in the browser and looks "successful". */
      if (isHttpPage) {
        if (!phpRes.ok) {
          showAppAlert(
            'Could not save registration to the database (HTTP ' +
              phpRes.status +
              '). Start Apache in XAMPP and open the site from http://localhost/... (not as a file).'
          );
        } else {
          showAppAlert(
            'Registration did not save to MySQL (server returned an unexpected response). Check PHP/Apache error logs or FrontPage/php/register.php.'
          );
        }
        return;
      }
    } catch (e) {
      console.warn('PHP register not reachable:', e);
      if (isHttpPage) {
        showAppAlert(
          'Could not reach PHP registration. Use http://localhost/.../FrontPage/html/Front.html with Apache running so data can be saved to MySQL.'
        );
        return;
      }
    }

    showAppAlert('Registration could not reach Supabase. Check the PHP server and Supabase configuration.');
    return;

    // Legacy registration fallback disabled; browser and Spring data stores are not used.
    try {
      const formData = new URLSearchParams();
      formData.append('firstname', apiFirstname);
      formData.append('lastname', lastname);
      formData.append('phoneNum', phone);
      formData.append('email', email);
      formData.append('password', password);

      const response = await fetch('http://localhost:8080/api/resident/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
      });

      if (response.ok) {
        const resident = await response.json();
        finishRegisterSuccess(resident);
        return;
      }

      showAppAlert('Registration failed. Please check your details.');
      return;
    } catch (err) {
      console.warn('Register API unavailable, using local store:', err);
      const BC = window.BarangayConnectResidents;
      if (!BC) {
        showAppAlert('Could not reach the server and local registration is unavailable.');
        console.error(err);
        return;
      }
      try {
        if (window.location.protocol === 'file:') {
          showAppAlert(
            'Cannot save to MySQL from a file:// page. Use the red banner link (http://localhost/...) with Apache running.'
          );
        } else {
          showAppAlert(
            'Registration will be saved only in this browser (not in MySQL). Open the site with Apache at http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/FrontPage/html/Front.html'
          );
        }
        const resident = BC.register(localPayload);
        finishRegisterSuccess(resident);
      } catch (e) {
        if (e.message === 'DUPLICATE_PHONE') {
          showAppAlert('This mobile number is already registered.');
        } else if (e.message === 'DUPLICATE_EMAIL') {
          showAppAlert('This email is already registered.');
        } else if (e.message === 'DUPLICATE_USERNAME') {
          showAppAlert('This username is already taken.');
        } else if (e.message === 'DUPLICATE_BARANGAY_ID') {
          showAppAlert('This Barangay Resident ID is already linked to an account.');
        } else if (e.message === 'DUPLICATE_NATIONAL_ID') {
          showAppAlert('This National ID number is already registered.');
        } else if (e.message === 'DUPLICATE_VOTER_ID') {
          showAppAlert('This Voter\'s ID number is already registered.');
        } else if (e.message === 'DUPLICATE_IDENTITY') {
          showAppAlert('An account already exists for this person (same name, birth date, and address).');
        } else {
          showAppAlert('Registration could not be completed.');
        }
      }
    }
  });

  // Register modal: no backdrop click-to-close (avoids accidental data loss)

  // ===============================
  // RESIDENT LOGIN
  // ===============================
  const residentLoginForm = document.getElementById('residentLoginForm');

  residentLoginForm?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const phone = document.getElementById('loginResidentEmail')?.value.trim();
    const password = document.getElementById('loginResidentPassword')?.value.trim();

    if (!phone || !password) {
      showAppAlert("Please enter your phone number and password.");
      return;
    }

    const afterResidentLogin = (resident) => {
      if (resident.status === "REJECTED") {
        showAppAlert("Your registration was not approved. Please contact the barangay hall.");
        return;
      }

      const S = window.BarangayConnectSession;
      if (S) {
        S.setResidentSession(resident);
      } else {
        localStorage.setItem("residentId", String(resident.id));
        if (resident.firstname != null) {
          localStorage.setItem("residentFirstname", String(resident.firstname));
        }
        if (resident.lastname != null) {
          localStorage.setItem("residentLastname", String(resident.lastname));
        }
        localStorage.setItem("residentPortal", "full");
      }

      if (typeof recordActivity === "function") {
        recordActivity(
          "LOGIN",
          "RESIDENT",
          phone,
          `${resident.firstname || ""} ${resident.lastname || ""}`.trim() || "Resident",
          { status: resident.status }
        );
      }

      const portalUrl = S
        ? S.residentPortalUrl()
        : new URL("../../Residents/html/ResidentPage.html", window.location.href).href;
      const Nav = window.BarangayConnectNavPersist;
      window.location.replace(
        Nav ? Nav.portalUrlWithSavedState('resident', portalUrl) : portalUrl
      );
    };

    try {
      const formData = new URLSearchParams();
      formData.append('identifier', phone);
      formData.append('password', password);

      // MySQL via PHP — successful login returns full resident portal access
      try {
        const phpRes = await fetch(phpApiUrl('login.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: formData
        });
        const phpText = await phpRes.text();
        let phpJson = null;
        try {
          phpJson = JSON.parse(phpText);
        } catch {
          /* not JSON */
        }
        if (phpJson && phpJson.success) {
          afterResidentLogin({
            id: phpJson.id,
            residentId: phpJson.residentId || '',
            firstname: phpJson.firstname,
            lastname: phpJson.lastname,
            status: phpJson.status || 'ACTIVE'
          });
          return;
        }
        if (phpJson && phpJson.error) {
          showAppAlert(phpJson.error);
          return;
        }
      } catch (e) {
        console.warn('PHP login not reachable:', e);
      }

      const res = await fetch("http://localhost:8080/api/resident/login", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: formData
      });

      if (res.ok) {
        const resident = await res.json();
        afterResidentLogin(resident);
        return;
      }

      const BC = window.BarangayConnectResidents;
      const localUser = BC ? BC.login(phone, password) : null;
      if (localUser) {
        afterResidentLogin(localUser);
        return;
      }

      if (typeof recordActivity === "function") {
        recordActivity(
          "FAILED_LOGIN",
          "RESIDENT",
          phone,
          "Unknown Resident",
          { reason: "Invalid credentials" }
        );
      }
      showAppAlert("Invalid login credentials!");
      return;
    } catch (err) {
      console.warn("Login API unavailable, using local store:", err);
      const BC = window.BarangayConnectResidents;
      if (!BC) {
        showAppAlert("Could not connect to server.");
        console.error(err);
        return;
      }
      const resident = BC.login(phone, password);
      if (!resident) {
        if (typeof recordActivity === "function") {
          recordActivity(
            "FAILED_LOGIN",
            "RESIDENT",
            phone,
            "Unknown Resident",
            { reason: "Invalid credentials" }
          );
        }
        showAppAlert("Invalid login credentials!");
        return;
      }
      afterResidentLogin(resident);
    }
  });

  // ===============================
  // ADMIN LOGIN
  // ===============================
  const adminLoginForm = document.getElementById('adminLoginForm');

  adminLoginForm?.addEventListener('submit', async e => {
    e.preventDefault();

    const identifier = document.getElementById('loginAdminEmail')?.value.trim();
    const password = document.getElementById('loginAdminPassword')?.value.trim();

    if (!identifier || !password) {
      showAppAlert("Please enter your admin username/email and password.");
      return;
    }

    try {
      const formData = new URLSearchParams();
      formData.append("identifier", identifier);
      formData.append("password", password);

      try {
        const phpRes = await fetch(phpApiUrl('admin_login.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: formData
        });
        const phpText = await phpRes.text();
        let phpJson = null;
        try {
          phpJson = JSON.parse(phpText);
        } catch {
          /* not JSON */
        }
        if (phpJson && phpJson.success) {
          const S = window.BarangayConnectSession;
          if (S) {
            S.setAdminSession({ username: phpJson.username || identifier, fullName: phpJson.fullName || 'Admin' });
          } else {
            localStorage.setItem('adminLoggedIn', '1');
            localStorage.setItem('adminUsername', phpJson.username || identifier);
            localStorage.setItem('adminFullName', phpJson.fullName || 'Admin');
          }
          if (typeof recordActivity === 'function') {
            recordActivity('LOGIN', 'ADMIN', identifier, phpJson.fullName || 'Admin');
          }
          showAppAlert('Admin login successful!');
          const adminBase = S
            ? S.adminPortalUrl()
            : new URL('../../Admin/html/AdminPage.html', window.location.href).href;
          const NavA = window.BarangayConnectNavPersist;
          window.location.replace(
            NavA ? NavA.portalUrlWithSavedState('admin', adminBase) : adminBase
          );
          return;
        }
        if (phpJson && phpJson.error) {
          showAppAlert(phpJson.error);
          return;
        }
      } catch (e) {
        console.warn('PHP admin login not reachable:', e);
      }

      const response = await fetch("http://localhost:8080/api/admin/login", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: formData
      });

      if (response.ok) {
        const S = window.BarangayConnectSession;
        if (S) {
          S.setAdminSession({ username: identifier, fullName: 'Admin' });
        } else {
          localStorage.setItem('adminLoggedIn', '1');
        }
        if (typeof recordActivity === "function") {
          recordActivity(
            "LOGIN",
            "ADMIN",
            identifier,
            "Admin"
          );
        }

        showAppAlert("Admin login successful!");
        const adminBase2 = S
          ? S.adminPortalUrl()
          : new URL('../../Admin/html/AdminPage.html', window.location.href).href;
        const NavA2 = window.BarangayConnectNavPersist;
        window.location.replace(
          NavA2 ? NavA2.portalUrlWithSavedState('admin', adminBase2) : adminBase2
        );

      } else {
        // Activity log: failed admin login
        if (typeof recordActivity === "function") {
          recordActivity(
            "FAILED_LOGIN",
            "ADMIN",
            identifier,
            "Unknown Admin",
            { reason: "Invalid credentials" }
          );
        }

        showAppAlert("Invalid admin credentials!");
      }

    } catch (err) {
      showAppAlert("Server not reachable. Make sure Spring Boot is running.");
      console.error(err);
    }
  });

  // ===============================
  // TERMS & CONDITIONS MODAL
  // ===============================
  const termsModal = document.getElementById("termsModal");

  function closeTermsModal() {
    if (!termsModal) return;
    termsModal.style.display = "none";
    termsModal.setAttribute("aria-hidden", "true");
  }

  function openTermsModal(scrollToId) {
    if (!termsModal) return;
    termsModal.style.display = "block";
    termsModal.setAttribute("aria-hidden", "false");
    const bodyEl = termsModal.querySelector(".terms-modal-body");
    if (!bodyEl) return;
    if (scrollToId) {
      const target = bodyEl.querySelector("#" + scrollToId);
      requestAnimationFrame(() => {
        if (target) {
          target.scrollIntoView({ block: "start", behavior: "smooth" });
        }
      });
    } else {
      bodyEl.scrollTop = 0;
    }
  }

  document.querySelectorAll(".terms-modal-trigger").forEach((el) => {
    el.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      const scroll = el.getAttribute("data-terms-scroll");
      openTermsModal(scroll && scroll.length ? scroll : null);
    });
  });

  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape" || !termsModal || termsModal.style.display !== "block") return;
    closeTermsModal();
  });

  if (window.BarangayConnectNavPersist) {
    window.BarangayConnectNavPersist.restoreRegisterWizard(
      () => document.getElementById('registerModal')?.style.setProperty('display', 'block'),
      setRegisterStep
    );
  }

}); // END DOMContentLoaded
