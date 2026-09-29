/**
 * Barangay Tambo street list — shared with Front.html registration.
 * Street fields use a standard <select> plus an optional "Other" text field (same pattern as religion).
 */
(function (global) {
  const STREET_OTHER = '__other__';

  const STREETS = [
    'PIO DE JESUS',
    'T.M GABRIEL',
    'D. DE LEON',
    'MEPAR 1 & 2',
    'LOPEZ DE LEON',
    'PABAGA',
    'SIZZLING',
    'SANDUGO',
    'AGRIPINA',
    'JUAN FERMIN',
    'KABESANG SILLIO (ILOG)',
    'KABESANG SILLIO (GITNA)',
    'ATO MENDOZA',
    'RIVERSIDE 3',
    'MAYUGA',
    'WHISKY 3',
    'POOK REAL',
    "MARTYR's",
    'GABRIEL ST.',
    'PUYAT',
    '16th ST. EXTENSION',
    '16 ST.',
    'RIVERSIDE I',
    'RIVERSIDE II',
    'SEASIDE BUKANA',
    'SEASIDE GITNA',
    'SEASIDE DULO',
    'SEASIDE HARAPAN',
    'GREEN HOUSE',
    'TALAHIB (c/o MATET)',
    'CAMP CALAUDIO',
    'C. SANTOS',
    'P. PAULINO',
  ];

  function otherInputIdFor(selectId) {
    if (selectId === 'streetSelect') return 'streetOther';
    if (selectId.endsWith('Select')) return selectId.replace(/Select$/, 'Other');
    return selectId + 'Other';
  }

  function otherWrapIdFor(selectId) {
    return otherInputIdFor(selectId) + 'Wrap';
  }

  function populateStreetSelect(selectEl) {
    if (!selectEl || selectEl.tagName !== 'SELECT') return selectEl;

    const current = selectEl.value;
    selectEl.innerHTML = '';
    const first = document.createElement('option');
    first.value = '';
    first.disabled = true;
    first.selected = !current;
    first.textContent = 'Select street...';
    selectEl.appendChild(first);

    STREETS.forEach((name) => {
      const opt = document.createElement('option');
      opt.value = name;
      opt.textContent = name;
      selectEl.appendChild(opt);
    });

    const otherOpt = document.createElement('option');
    otherOpt.value = STREET_OTHER;
    otherOpt.textContent = 'Other street — specify below';
    selectEl.appendChild(otherOpt);

    if (current && (STREETS.includes(current) || current === STREET_OTHER)) {
      selectEl.value = current;
    }

    return selectEl;
  }

  function ensureStreetOtherWrap(selectEl) {
    if (!selectEl) return;
    const otherId = otherInputIdFor(selectEl.id);
    const wrapId = otherWrapIdFor(selectEl.id);
    if (document.getElementById(otherId)) return;

    const wrap = document.createElement('div');
    wrap.id = wrapId;
    wrap.className = 'street-other-wrap religion-other-wrap';
    wrap.hidden = true;

    const label = document.createElement('label');
    label.className = 'form-label religion-other-label';
    label.htmlFor = otherId;
    label.textContent = 'Specify street name';

    const input = document.createElement('input');
    input.type = 'text';
    input.id = otherId;
    input.name = otherId;
    input.className = 'form-input';
    input.placeholder = 'Enter street name';
    input.autocomplete = 'off';

    wrap.appendChild(label);
    wrap.appendChild(input);
    selectEl.insertAdjacentElement('afterend', wrap);
  }

  function syncStreetOtherField(selectId) {
    const sel = document.getElementById(selectId);
    if (!sel || sel.tagName !== 'SELECT') return;

    const wrap = document.getElementById(otherWrapIdFor(selectId));
    const other = document.getElementById(otherInputIdFor(selectId));
    if (!wrap) return;

    const isOther = sel.value === STREET_OTHER;
    wrap.hidden = !isOther;
    if (other) {
      other.required = isOther;
      if (!isOther) other.value = '';
    }
  }

  /**
   * Populate street options, add "Other" field, and wire change handler.
   */
  function initStreetField(selectEl) {
    if (!selectEl) return null;

    if (selectEl.tagName === 'INPUT') {
      const id = selectEl.id || 'streetSelect';
      const name = selectEl.name || 'street';
      const required = selectEl.required;
      const className = selectEl.className.replace(/\bform-input\b/g, 'form-select').trim() || 'form-select';
      const parent = selectEl.parentNode;
      const saved = selectEl.value;

      const select = document.createElement('select');
      select.id = id;
      select.name = name;
      select.className = className;
      select.required = !!required;
      selectEl.replaceWith(select);
      if (saved) setStreetValue(select, saved);
      selectEl = select;
      parent.querySelector('datalist[id$="-streets"]')?.remove();
      parent.querySelector('.bc-street-combobox-hint')?.remove();
    }

    populateStreetSelect(selectEl);
    ensureStreetOtherWrap(selectEl);
    syncStreetOtherField(selectEl.id);

    if (selectEl.dataset.bcStreetBound !== '1') {
      selectEl.dataset.bcStreetBound = '1';
      selectEl.addEventListener('change', function () {
        syncStreetOtherField(selectEl.id);
      });
    }

    return selectEl;
  }

  function getStreetValue(selectId) {
    const sel = document.getElementById(selectId);
    if (!sel) return '';
    if (sel.tagName === 'INPUT') return (sel.value || '').trim();
    if (sel.value === STREET_OTHER) {
      return (document.getElementById(otherInputIdFor(selectId))?.value || '').trim();
    }
    return (sel.value || '').trim();
  }

  function setStreetValue(selectEl, value) {
    if (!selectEl || value == null || String(value).trim() === '') return;
    const v = String(value).trim();

    if (selectEl.tagName === 'INPUT') {
      selectEl.value = v;
      return;
    }

    if (STREETS.includes(v)) {
      selectEl.value = v;
    } else {
      selectEl.value = STREET_OTHER;
      const other = document.getElementById(otherInputIdFor(selectEl.id));
      if (other) other.value = v;
    }
    syncStreetOtherField(selectEl.id);
  }

  /**
   * @param {{ houseUnitId?: string, blockLotId?: string, streetId?: string }} ids
   */
  function buildHouseStreetLine(ids) {
    const houseUnitId = ids?.houseUnitId || 'houseUnit';
    const blockLotId = ids?.blockLotId || 'blockLot';
    const streetId = ids?.streetId || 'streetSelect';

    const unit = document.getElementById(houseUnitId)?.value.trim() || '';
    const blk = document.getElementById(blockLotId)?.value.trim() || '';
    const street = getStreetValue(streetId);
    const parts = [];
    if (unit) parts.push(`House/Unit ${unit}`);
    if (blk) parts.push(`Blk/Lot ${blk}`);
    if (street) parts.push(street);
    return parts.join(' · ') || street;
  }

  function buildFullAddress(ids) {
    const line = buildHouseStreetLine(ids);
    const barangay = document.getElementById(ids?.barangayId || 'barangay')?.value.trim() || '';
    const city = document.getElementById(ids?.cityId || 'city')?.value.trim() || '';
    const province = document.getElementById(ids?.provinceId || 'province')?.value.trim() || '';
    const region = document.getElementById(ids?.regionId || 'region')?.value.trim() || '';
    return [line, barangay, city, province, region].filter(Boolean).join(', ');
  }

  global.BarangayConnectAddress = {
    STREETS,
    STREET_OTHER,
    populateStreetSelect,
    initStreetField,
    getStreetValue,
    setStreetValue,
    syncStreetOtherField,
    buildHouseStreetLine,
    buildFullAddress,
  };
})(window);
