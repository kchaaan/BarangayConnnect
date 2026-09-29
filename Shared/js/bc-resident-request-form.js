/**
 * Shared resident request form — personal details, address, prefill, PHP submit.
 * Used by category request pages (same layout as R_Personal-Identification.html).
 */
(function (global) {
  const ADDR_IDS = {
    houseUnitId: 'houseUnit',
    blockLotId: 'blockLot',
    streetId: 'streetSelect',
    barangayId: 'barangay',
    cityId: 'city',
    provinceId: 'province',
    regionId: 'region',
  };

  const PERSONAL_FIELDS_HTML = `
    <div class="form-row">
      <div class="form-group">
        <label class="form-label"><i class="fas fa-user"></i> First Name <span class="required">*</span></label>
        <input type="text" id="firstName" name="firstName" class="form-input" required>
      </div>
      <div class="form-group">
        <div class="label-row">
          <label class="form-label"><i class="fas fa-user"></i> Middle Name</label>
          <label class="checkbox-label"><input type="checkbox" id="middleNameNA"> <span>N/A</span></label>
        </div>
        <input type="text" id="middleName" name="middleName" class="form-input">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label"><i class="fas fa-user"></i> Last Name <span class="required">*</span></label>
        <input type="text" id="lastName" name="lastName" class="form-input" required>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-signature"></i> Suffix</label>
        <select id="suffix" name="suffix" class="form-select">
          <option value="">Select suffix...</option>
          <option value="Jr.">Jr.</option>
          <option value="Sr.">Sr.</option>
          <option value="III">III</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label"><i class="fas fa-venus-mars"></i> Gender <span class="required">*</span></label>
        <select id="gender" name="gender" class="form-select" required>
          <option value="">Select gender...</option>
          <option value="male">Male</option>
          <option value="female">Female</option>
          <option value="prefer-not-to-say">Prefer not to say</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-calendar"></i> Date of Birth <span class="required">*</span></label>
        <input type="date" id="birthdate" name="birthdate" class="form-input" required>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label"><i class="fas fa-hourglass-half"></i> Age</label>
        <input type="number" id="age" name="age" class="form-input" readonly placeholder="Auto-computed">
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-map-pin"></i> Place of Birth <span class="required">*</span></label>
        <input type="text" id="placeOfBirth" name="placeOfBirth" class="form-input" required>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label"><i class="fas fa-ring"></i> Civil Status <span class="required">*</span></label>
        <select id="civilStatus" name="civilStatus" class="form-select" required>
          <option value="">Select civil status...</option>
          <option value="single">Single</option>
          <option value="married">Married</option>
          <option value="widowed">Widowed</option>
          <option value="separated">Separated</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-flag"></i> Citizenship <span class="required">*</span></label>
        <input type="text" id="citizenship" name="citizenship" class="form-input" required>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group form-group--full">
        <label class="form-label" for="religionSelect"><i class="fas fa-praying-hands"></i> Religion <span class="required">*</span></label>
        <select id="religionSelect" name="religionSelect" class="form-select" required>
          <option value="">Select religion...</option>
          <option value="Roman Catholic">Roman Catholic</option>
          <option value="Islam">Islam</option>
          <option value="Iglesia ni Cristo">Iglesia ni Cristo</option>
          <option value="Philippine Independent Church (Aglipayan)">Philippine Independent Church (Aglipayan)</option>
          <option value="Seventh-day Adventist">Seventh-day Adventist</option>
          <option value="Bible Baptist">Bible Baptist</option>
          <option value="United Church of Christ in the Philippines">United Church of Christ in the Philippines</option>
          <option value="Jehovah's Witnesses">Jehovah's Witnesses</option>
          <option value="Church of Jesus Christ of Latter-day Saints">Church of Jesus Christ of Latter-day Saints</option>
          <option value="Members Church of God International">Members Church of God International</option>
          <option value="Evangelical / Born Again">Evangelical / Born Again</option>
          <option value="Buddhism">Buddhism</option>
          <option value="Indigenous / Ancestral">Indigenous / Ancestral</option>
          <option value="None / Not religious">None / Not religious</option>
          <option value="__other__">Other — specify below</option>
        </select>
        <div id="religionOtherWrap" class="religion-other-wrap" hidden>
          <label class="form-label religion-other-label" for="religionOther">Specify religion</label>
          <input type="text" id="religionOther" name="religionOther" class="form-input" placeholder="Enter denomination or affiliation" autocomplete="off">
        </div>
      </div>
    </div>
    <h3 class="form-subsection-title"><i class="fas fa-map-marker-alt"></i> Address Information</h3>
    <div class="form-group form-group--full">
      <label class="form-label" for="streetSelect"><i class="fas fa-road"></i> Street <span class="required">*</span></label>
      <select id="streetSelect" name="street" class="form-select" required>
        <option value="" disabled selected>Select street...</option>
      </select>
      <div id="streetOtherWrap" class="street-other-wrap religion-other-wrap" hidden>
        <label class="form-label religion-other-label" for="streetOther">Specify street name</label>
        <input type="text" id="streetOther" name="streetOther" class="form-input" placeholder="Enter street name" autocomplete="off">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="blockLot"><i class="fas fa-th"></i> Block / Lot</label>
        <input type="text" id="blockLot" name="blockLot" class="form-input" placeholder="Blk / Lot">
      </div>
      <div class="form-group">
        <label class="form-label" for="houseUnit"><i class="fas fa-home"></i> House No. / Unit</label>
        <input type="text" id="houseUnit" name="houseUnit" class="form-input" placeholder="No. or unit">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="barangay"><i class="fas fa-building"></i> Barangay <span class="required">*</span></label>
        <input type="text" id="barangay" name="barangay" class="form-input" value="Tambo" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="city"><i class="fas fa-city"></i> City / Municipality <span class="required">*</span></label>
        <input type="text" id="city" name="city" class="form-input" value="Parañaque City" required>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="province"><i class="fas fa-flag"></i> Province <span class="required">*</span></label>
        <input type="text" id="province" name="province" class="form-input" value="Metro Manila" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="region"><i class="fas fa-globe-asia"></i> Region <span class="required">*</span></label>
        <input type="text" id="region" name="region" class="form-input" value="NCR" required>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label"><i class="fas fa-phone"></i> Contact Number <span class="required">*</span></label>
        <input type="tel" id="contactNumber" name="contactNumber" class="form-input"
          inputmode="numeric" maxlength="11" pattern="[0-9]{11}"
          placeholder="09XXXXXXXXX (11 digits)"
          title="Enter exactly 11 digits, numbers only (e.g. 09171234567)" required>
        <p class="field-hint">11-digit Philippine mobile number only (numbers, no letters).</p>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-envelope"></i> Email Address</label>
        <input type="email" id="email" name="email" class="form-input">
      </div>
    </div>
  `;

  function $(id) {
    return document.getElementById(id);
  }

  function notify() {
    return (
      global.bcNotify || {
        error: (m, o) => global.alert(m),
        success: (m, o) => global.alert(m),
        info: (m, o) => global.alert(m),
      }
    );
  }

  function computeAge(dateValue) {
    if (!dateValue) return '';
    const dob = new Date(dateValue);
    if (Number.isNaN(dob.getTime())) return '';
    const today = new Date();
    let computed = today.getFullYear() - dob.getFullYear();
    const monthDiff = today.getMonth() - dob.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) computed -= 1;
    return computed >= 0 ? String(computed) : '';
  }

  function getReligionValue() {
    const sel = $('religionSelect');
    if (!sel || !sel.value) return '';
    if (sel.value === '__other__') return ($('religionOther')?.value || '').trim();
    return sel.value;
  }

  function syncReligionOtherField() {
    const sel = $('religionSelect');
    const wrap = $('religionOtherWrap');
    const other = $('religionOther');
    if (!sel || !wrap) return;
    const isOther = sel.value === '__other__';
    wrap.hidden = !isOther;
    if (other) {
      other.required = isOther;
      if (!isOther) other.value = '';
    }
  }

  function applyMiddleNameNA(checked) {
    const input = $('middleName');
    const na = $('middleNameNA');
    if (!input || !na) return;
    na.checked = !!checked;
    if (checked) {
      input.value = 'N/A';
      input.disabled = true;
    } else {
      input.disabled = false;
      if (input.value === 'N/A') input.value = '';
    }
  }

  function injectPersonalFields(containerId) {
    const el = $(containerId);
    if (!el) return;
    // Only skip when the full block is already present (not an old partial inject).
    if (el.querySelector('#gender') && el.querySelector('#streetSelect')) return;
    el.innerHTML = PERSONAL_FIELDS_HTML;
  }

  /**
   * Run init on load and again when the browser restores this page from bfcache
   * (iframe back/forward without re-running scripts).
   */
  function registerRequestFormLifecycle(initFn) {
    async function run() {
      if (typeof initFn !== 'function') return;
      await initFn();
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', run);
    } else {
      run();
    }
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) run();
    });
  }

  function initFileProtocolBanner(pageFileName) {
    const banner = $('bcFileProtocolBanner');
    if (!banner) return;
    if (global.location.protocol === 'file:') {
      banner.hidden = false;
      const link = $('bcLocalhostPageLink');
      if (link && pageFileName) {
        link.href =
          'http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/Residents/html/' +
          pageFileName;
        link.textContent = link.href;
      }
    }
  }

  function initPersonalFields() {
    $('religionSelect')?.addEventListener('change', syncReligionOtherField);
    syncReligionOtherField();

    if (global.BarangayConnectAddress) {
      global.BarangayConnectAddress.initStreetField($('streetSelect'));
    }
    if (global.BarangayConnectPhone) {
      global.BarangayConnectPhone.bindMobileDigitsOnly($('contactNumber'));
    }

    $('middleNameNA')?.addEventListener('change', function () {
      applyMiddleNameNA(this.checked);
    });

    const dobInput = $('birthdate');
    const ageInput = $('age');
    if (dobInput && ageInput) {
      dobInput.addEventListener('change', function () {
        ageInput.value = computeAge(this.value);
      });
    }

    document.querySelectorAll('.file-input').forEach(function (input) {
      input.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        const label = this.closest('.upload-item')?.querySelector('.upload-label');
        if (!label) return;
        const name = file.name.length > 30 ? file.name.substring(0, 30) + '...' : file.name;
        label.innerHTML = '<i class="fas fa-check-circle"></i> ' + name;
        label.style.color = 'var(--clr-1)';
      });
    });
  }

  async function prefillFromProfile() {
    const residentId = localStorage.getItem('residentId');
    if (!residentId) return;

    try {
      const url = new URL('../../FrontPage/php/resident_profile.php', global.location.href);
      url.searchParams.set('id', residentId);
      const res = await fetch(url.href);
      if (!res.ok) return;
      const u = await res.json();
      if (!u || u.success === false) return;

      const set = (id, val) => {
        const el = $(id);
        if (el && val != null && val !== '') el.value = val;
      };

      set('firstName', u.firstName);
      set('middleName', u.middleName);
      set('lastName', u.lastName);
      set('suffix', u.suffix);
      if (u.gender) set('gender', u.gender.toLowerCase());
      set('birthdate', u.dateOfBirth);
      set('placeOfBirth', u.placeOfBirth);
      if (u.civilStatus) set('civilStatus', u.civilStatus.toLowerCase());
      set('citizenship', u.citizenship);
      set('email', u.email);
      if (u.mobile) {
        const normalized = global.BarangayConnectPhone
          ? global.BarangayConnectPhone.normalizeMobile11(u.mobile)
          : String(u.mobile).replace(/\D/g, '').slice(0, 11);
        set('contactNumber', normalized);
      }
      set('barangay', u.barangay || 'Tambo');
      set('city', u.city || 'Parañaque City');
      set('province', u.province || 'Metro Manila');

      const extra =
        typeof u.profile_extra === 'string'
          ? (function () {
              try {
                return JSON.parse(u.profile_extra);
              } catch {
                return {};
              }
            })()
          : u.profileExtra || {};

      set('region', extra.region || 'NCR');
      set('blockLot', extra.blockLot);
      set('houseUnit', extra.houseUnit);

      const streetEl = $('streetSelect');
      if (streetEl && extra.street) {
        global.BarangayConnectAddress?.setStreetValue(streetEl, extra.street);
      } else if (streetEl && u.street) {
        const match = global.BarangayConnectAddress?.STREETS?.find((s) => u.street.includes(s));
        global.BarangayConnectAddress?.setStreetValue(streetEl, match || u.street);
      }

      if (u.religion) {
        const relSel = $('religionSelect');
        const opts = relSel ? [...relSel.options] : [];
        const hit = opts.find((o) => o.value === u.religion);
        if (hit && relSel) {
          relSel.value = u.religion;
        } else if (relSel) {
          relSel.value = '__other__';
          set('religionOther', u.religion);
          syncReligionOtherField();
        }
      }

      const dob = $('birthdate');
      if (dob?.value) set('age', computeAge(dob.value));
    } catch (e) {
      console.warn('Profile prefill:', e);
    }
  }

  function validatePersonalFields() {
    const n = notify();
    const religionVal = getReligionValue();
    if (!$('religionSelect')?.value) {
      n.error('Please select your religion.', { title: 'Missing information' });
      return null;
    }
    if ($('religionSelect')?.value === '__other__' && !religionVal) {
      n.error('Please specify your religion.', { title: 'Missing information' });
      return null;
    }

    const streetVal = global.BarangayConnectAddress?.getStreetValue('streetSelect') || '';
    const barangayVal = $('barangay')?.value.trim();
    const cityVal = $('city')?.value.trim();
    const provinceVal = $('province')?.value.trim();
    const regionVal = $('region')?.value.trim();
    if (!streetVal || !barangayVal || !cityVal || !provinceVal || !regionVal) {
      n.error('Please complete all required address fields (street, barangay, city, province, region).', {
        title: 'Missing information',
      });
      return null;
    }
    if ($('streetSelect')?.value === '__other__' && !streetVal) {
      n.error('Please specify your street name.', { title: 'Missing information' });
      return null;
    }

    const contactEl = $('contactNumber');
    const contactRaw = contactEl?.value.trim() || '';
    const contactDigits = global.BarangayConnectPhone
      ? global.BarangayConnectPhone.normalizeMobile11(contactRaw)
      : contactRaw.replace(/\D/g, '');
    if (contactEl) contactEl.value = contactDigits;
    if (!global.BarangayConnectPhone?.validateMobile11(contactDigits)) {
      n.error('Contact number must be exactly 11 digits (numbers only, e.g. 09171234567).', {
        title: 'Invalid contact number',
      });
      contactEl?.focus();
      return null;
    }

    const houseStreet = global.BarangayConnectAddress
      ? global.BarangayConnectAddress.buildHouseStreetLine(ADDR_IDS)
      : streetVal;
    const fullAddress = global.BarangayConnectAddress
      ? global.BarangayConnectAddress.buildFullAddress(ADDR_IDS)
      : [houseStreet, barangayVal, cityVal, provinceVal, regionVal].join(', ');

    return {
      firstName: $('firstName')?.value.trim() || '',
      middleName: $('middleName')?.value.trim() || '',
      lastName: $('lastName')?.value.trim() || '',
      suffix: $('suffix')?.value || '',
      gender: $('gender')?.value || '',
      birthdate: $('birthdate')?.value || '',
      age: Number($('age')?.value) || null,
      placeOfBirth: $('placeOfBirth')?.value.trim() || '',
      civilStatus: $('civilStatus')?.value || '',
      citizenship: $('citizenship')?.value.trim() || '',
      religion: religionVal,
      email: $('email')?.value.trim() || '',
      mobile: contactDigits,
      houseStreet,
      blockLot: $('blockLot')?.value.trim() || '',
      houseUnit: $('houseUnit')?.value.trim() || '',
      street: streetVal,
      barangay: barangayVal,
      city: cityVal,
      province: provinceVal,
      region: regionVal,
      address: fullAddress,
    };
  }

  function buildBasePayload(requestType, documentTypeLabel, extra) {
    const residentId = localStorage.getItem('residentId');
    const personal = validatePersonalFields();
    if (!personal) return null;

    const residentCode = global.localStorage.getItem('residentCode') || '';
    const base = {
        residentId,
        requestType,
        documentType: documentTypeLabel,
        submissionDate: new Date().toISOString(),
        status: 'pending',
        requestNotes: $('notes')?.value.trim() || '',
      };
    if (residentCode) {
      base.residentIdLocal = residentCode;
    }
    return Object.assign(base, personal, extra || {});
  }

  async function submitMultipart(dataPayload, uploadItems, options) {
    const n = notify();
    const api = global.BarangayConnectRequestsApi;
    const residentId = localStorage.getItem('residentId');

    if (!residentId) {
      n.error('No logged-in resident found. Please log in from the home page first.', { title: 'Not signed in' });
      return false;
    }

    if (!uploadItems || uploadItems.length === 0) {
      n.error('Please upload the required files.', { title: 'Missing files' });
      return false;
    }

    const multipart = new FormData();
    multipart.append('data', new Blob([JSON.stringify(dataPayload)], { type: 'application/json' }));

    if (api?.appendRequestFiles) {
      api.appendRequestFiles(multipart, uploadItems);
    } else {
      uploadItems.forEach(function (item) {
        if (!item?.file) return;
        multipart.append('file_labels[]', item.label || 'Attachment');
        multipart.append('files[]', item.file);
      });
    }

    if (!api?.canSubmitToServer?.()) {
      n.error(
        'Start Apache in XAMPP, then open this page through http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/...',
        { title: 'Cannot submit here' }
      );
      return false;
    }

    if (api.isFileProtocol?.()) {
      const ok = global.bcConfirm
        ? await global.bcConfirm(
            'This page was opened as a file (file://). Submit will try to send data to XAMPP on localhost.\n\nFor best results, use the localhost link in the red banner. Continue anyway?',
            { title: 'File mode warning', okText: 'Continue', cancelText: 'Cancel' }
          )
        : global.confirm(
            'This page was opened as a file (file://). Submit will try to send data to XAMPP on localhost.\n\nFor best results, use the localhost link in the red banner. Continue anyway?'
          );
      if (!ok) return false;
    }

    try {
      const res = await fetch(api.submitUrl(), { method: 'POST', body: multipart });
      const text = await res.text();
      let json = null;
      try {
        json = JSON.parse(text);
      } catch {
        console.warn('Non-JSON response:', text.slice(0, 300));
      }

      if (json?.success) {
        const ref = json.requestId || 'REQ-' + String(json.id || '').padStart(3, '0');
        const redirectUrl =
          api.isFileProtocol?.()
            ? api.XAMPP_HTTP_BASE + 'Residents/html/R_Request-management.html'
            : 'R_Request-management.html';
        n.success('Your request has been submitted successfully!', {
          title: 'Request submitted',
          onOk: function () {
            global.location.href = redirectUrl;
          },
        });
        return true;
      }

      if (json?.error) {
        n.error(json.error, { title: 'Submission failed' });
        return false;
      }

      n.error(
        'Could not save to the database (HTTP ' + res.status + ').\n\n' + (text ? text.slice(0, 200) : ''),
        { title: 'Server error' }
      );
      return false;
    } catch (err) {
      console.error(err);
      n.error(
        'Could not reach the server. Start Apache and MySQL in XAMPP, then open this page via http://localhost/...',
        { title: 'Connection failed' }
      );
      return false;
    }
  }

  global.BarangayConnectResidentRequestForm = {
    injectPersonalFields,
    registerRequestFormLifecycle,
    initPersonalFields,
    initFileProtocolBanner,
    prefillFromProfile,
    validatePersonalFields,
    buildBasePayload,
    submitMultipart,
    getReligionValue,
    syncReligionOtherField,
    applyMiddleNameNA,
    computeAge,
  };
})(window);
