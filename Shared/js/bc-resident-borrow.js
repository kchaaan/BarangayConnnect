/**
 * Resident — Borrow Available Items & My Borrow Requests.
 * No item photos — category icons only.
 */
(function (global) {
  const API = global.BarangayConnectBorrowInventoryApi;
  const Session = global.BarangayConnectSession;
  if (!API) return;

  const CATEGORY_ICONS = {
    Furniture: 'fa-chair',
    Equipment: 'fa-toolbox',
    'Events & Setup': 'fa-tent',
    'Audio & Visual': 'fa-volume-high',
    Electrical: 'fa-plug',
    'Cleaning & Utilities': 'fa-broom',
    'Emergency & Medical': 'fa-kit-medical',
    'Medical / Emergency': 'fa-kit-medical',
    'Safety & Traffic': 'fa-traffic-light',
    'Tools & Maintenance': 'fa-screwdriver-wrench',
    Sports: 'fa-futbol',
  };

  let allItems = [];
  let selectedItem = null;
  let pendingBorrowSubmit = null;
  let myBorrowRows = [];

  const COMPACT_MQ = global.matchMedia ? global.matchMedia('(max-width: 1100px)') : null;

  function isCompactLayout() {
    return COMPACT_MQ ? COMPACT_MQ.matches : false;
  }

  function setSelectedCard(itemId) {
    document.querySelectorAll('.r-borrow-card.is-selected').forEach((el) => {
      el.classList.remove('is-selected');
    });
    if (itemId == null) return;
    document.querySelector(`.r-borrow-card[data-item-id="${itemId}"]`)?.classList.add('is-selected');
  }

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso.length === 10 ? iso + 'T12:00:00' : iso);
    if (Number.isNaN(d.getTime())) return esc(iso);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function itemIcon(item) {
    return CATEGORY_ICONS[item.categoryName] || 'fa-box';
  }

  function statusLabel(status) {
    const map = {
      available: ['Available', 'r-borrow-status--available'],
      low_stock: ['Low Stock', 'r-borrow-status--low'],
      unavailable: ['Unavailable', 'r-borrow-status--unavailable'],
    };
    const m = map[status] || ['Available', 'r-borrow-status--available'];
    return `<span class="r-borrow-status ${m[1]}">${m[0]}</span>`;
  }

  function borrowStatusBadge(status) {
    const labels = {
      pending: 'Pending', approved: 'Approved', rejected: 'Rejected',
      released: 'Released', returned: 'Returned', overdue: 'Overdue', damaged_lost: 'Damaged / Lost',
    };
    const cls = {
      pending: 'reports-status pending', approved: 'reports-status completed',
      rejected: 'reports-status cancelled', released: 'reports-status processing',
      returned: 'reports-status completed', overdue: 'reports-status pending',
      damaged_lost: 'reports-status cancelled',
    };
    return `<span class="${cls[status] || 'reports-status'}">${esc(labels[status] || status)}</span>`;
  }

  function residentId() {
    // Supabase Auth IDs are UUID strings; never coerce them to Number (which becomes NaN).
    return Session ? Session.getResidentId() : localStorage.getItem('residentId');
  }

  function notifyBorrowChange(detail) {
    if (global.BarangayConnectBorrowSync) {
      global.BarangayConnectBorrowSync.notify(
        Object.assign({ source: 'resident', residentUserId: residentId() }, detail || {})
      );
    }
  }

  function watchBorrowSync(callback) {
    if (global.BarangayConnectBorrowSync) {
      global.BarangayConnectBorrowSync.watch(callback);
    }
  }

  function openOverlay(id) {
    document.getElementById(id)?.classList.add('is-open');
  }

  function closeOverlay(id) {
    document.getElementById(id)?.classList.remove('is-open');
  }

  function bindCloseButtons() {
    document.querySelectorAll('[data-r-borrow-close]').forEach((btn) => {
      btn.addEventListener('click', () => closeOverlay(btn.getAttribute('data-r-borrow-close')));
    });
    document.querySelectorAll('.r-borrow-overlay').forEach((overlay) => {
      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) overlay.classList.remove('is-open');
      });
    });
  }

  function getFilters() {
    return {
      search: document.getElementById('rBorrowSearch')?.value?.trim().toLowerCase() || '',
      category: document.getElementById('rBorrowCategory')?.value || 'all',
      availability: document.getElementById('rBorrowAvailability')?.value || 'all',
    };
  }

  function filterItems(items, filters) {
    return items.filter((i) => {
      if (filters.category !== 'all' && String(i.categoryId) !== filters.category) return false;
      if (filters.availability !== 'all' && i.status !== filters.availability) return false;
      if (filters.search) {
        const hay = `${i.name} ${i.description} ${i.categoryName}`.toLowerCase();
        if (!hay.includes(filters.search)) return false;
      }
      return true;
    });
  }

  function renderItemCard(item) {
    const canBorrow = item.status !== 'unavailable' && item.availableQty > 0;
    return `
      <article class="r-borrow-card" data-item-id="${item.itemId}">
        <div class="r-borrow-card__icon"><i class="fas ${itemIcon(item)}" aria-hidden="true"></i></div>
        <h3>${esc(item.name)}</h3>
        <p class="r-borrow-card__meta">${esc(item.categoryName || 'General')} · ${statusLabel(item.status)}</p>
        <p class="r-borrow-card__meta"><strong>${item.availableQty}</strong> ${esc(item.unit)} available</p>
        <div class="r-borrow-card__actions">
          <button type="button" class="btn-ghost" data-view-item="${item.itemId}">View Details</button>
          <button type="button" class="btn-primary" data-borrow-item="${item.itemId}" ${canBorrow ? '' : 'disabled'}>
            <i class="fas fa-hand-holding" aria-hidden="true"></i> Borrow
          </button>
        </div>
      </article>`;
  }

  function showSidePanel(item) {
    selectedItem = item;
    setSelectedCard(item.itemId);
    if (isCompactLayout()) return;

    const placeholder = document.getElementById('rBorrowPanelPlaceholder');
    const content = document.getElementById('rBorrowPanelContent');
    if (!content) return;
    if (placeholder) placeholder.hidden = true;
    content.hidden = false;

    const iconEl = document.getElementById('rPanelIcon');
    if (iconEl) iconEl.innerHTML = `<i class="fas ${itemIcon(item)}"></i>`;
    document.getElementById('rPanelName').textContent = item.name;
    document.getElementById('rPanelCat').textContent = item.categoryName || 'General';
    document.getElementById('rPanelDesc').textContent = item.description || 'No description provided.';
    document.getElementById('rPanelAvail').innerHTML =
      `${statusLabel(item.status)} · <strong>${item.availableQty}</strong> ${esc(item.unit)} available`;

    const borrowBtn = document.getElementById('rPanelBorrowBtn');
    if (borrowBtn) {
      borrowBtn.disabled = !(item.availableQty > 0 && item.status !== 'unavailable');
    }
  }

  function hideSidePanel() {
    setSelectedCard(null);
    document.getElementById('rBorrowPanelPlaceholder')?.removeAttribute('hidden');
    const content = document.getElementById('rBorrowPanelContent');
    if (content) content.hidden = true;
  }

  function clearItemSelection() {
    selectedItem = null;
    setSelectedCard(null);
    hideSidePanel();
  }

  function openItemDetails(item) {
    if (!item) return;
    selectedItem = item;
    setSelectedCard(item.itemId);
    if (!isCompactLayout()) showSidePanel(item);
    renderDetailModal(item);
    openOverlay('rItemDetailModal');
  }

  function renderDetailModal(item) {
    const body = document.getElementById('rItemDetailBody');
    if (!body) return;
    const maxQty = Math.min(item.availableQty, 10);
    body.innerHTML = `
      <div class="r-borrow-detail-top">
        <div class="r-borrow-detail-top__icon"><i class="fas ${itemIcon(item)}"></i></div>
        <div>
          <h3 style="margin:0 0 0.25rem;font-size:1.1rem">${esc(item.name)}</h3>
          <p style="margin:0;color:#5f7f58;font-weight:600;font-size:0.85rem">${esc(item.categoryName || '')}</p>
          <p style="margin:0.5rem 0 0;font-size:0.88rem;color:#5f6d5b">${esc(item.description || '')}</p>
          <p style="margin:0.35rem 0 0">Unit: <strong>${esc(item.unit)}</strong></p>
        </div>
      </div>
      <div class="r-borrow-detail-grid">
        <div class="r-borrow-detail-section">
          <h3>Availability</h3>
          <p>${statusLabel(item.status)}</p>
          <p>Available Quantity: <strong>${item.availableQty} ${esc(item.unit)}</strong></p>
          <p>${item.availableQty > 0 ? 'This item is ready to borrow.' : 'Currently not available for borrowing.'}</p>
        </div>
        <div class="r-borrow-detail-section">
          <h3>Borrow Rules</h3>
          <ul>
            <li>Return on or before the expected return date and time.</li>
            <li>Item must be in good condition upon return.</li>
            <li>Maximum ${maxQty} ${esc(item.unit)} per request.</li>
            <li>Late returns may affect future borrowing.</li>
          </ul>
        </div>
        <div class="r-borrow-detail-section">
          <h3>Recommended Use</h3>
          <ul>
            <li>Community events and barangay activities</li>
            <li>Official resident-organized programs</li>
          </ul>
        </div>
        <div class="r-borrow-detail-section">
          <h3>Processing</h3>
          <p>Approval review: usually 1–2 working days.</p>
          <p>Pickup at Barangay Hall after approval notification.</p>
        </div>
      </div>`;
  }

  function bindCharCounter(inputId, countId, max) {
    const input = document.getElementById(inputId);
    const count = document.getElementById(countId);
    if (!input || !count) return;
    const update = () => {
      count.textContent = String((input.value || '').length);
      count.parentElement.classList.toggle('is-limit', (input.value || '').length >= max);
    };
    if (!input.dataset.bcCounterBound) {
      input.addEventListener('input', update);
      input.dataset.bcCounterBound = '1';
    }
    update();
  }

  function fmtReviewDate(val) {
    if (!val) return '—';
    const d = new Date(val.length === 10 ? val + 'T12:00:00' : val);
    if (Number.isNaN(d.getTime())) return val;
    return val.length > 10
      ? d.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
      : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function buildBorrowRemarksFromValues(v) {
    const lines = [];
    if (v.dateNeeded?.length > 10) lines.push(`Pickup Date & Time: ${fmtReviewDate(v.dateNeeded)}`);
    if (v.returnDate?.length > 10) lines.push(`Expected Return Date & Time: ${fmtReviewDate(v.returnDate)}`);
    if (v.event) lines.push(`Event/Activity: ${v.event}`);
    if (v.eventAt) lines.push(`Event Date & Time: ${fmtReviewDate(v.eventAt)}`);
    if (v.location) lines.push(`Location/Venue: ${v.location}`);
    if (v.remarks) lines.push(`Additional Notes: ${v.remarks}`);
    return lines.join('\n');
  }

  function buildBorrowRemarks() {
    return buildBorrowRemarksFromValues(readBorrowFormValues());
  }

  function readBorrowFormValues() {
    return {
      itemId: Number(document.getElementById('rBorrowItemId')?.value || 0),
      fullName: document.getElementById('rBorrowFullName')?.value?.trim() || '',
      residentCode: document.getElementById('rBorrowResidentCode')?.value?.trim() || '',
      contact: document.getElementById('rBorrowContact')?.value?.trim() || '',
      quantity: Number(document.getElementById('rBorrowQty')?.value || 1),
      dateNeeded: document.getElementById('rBorrowDateNeeded')?.value || '',
      returnDate: document.getElementById('rBorrowReturnDate')?.value || '',
      eventAt: document.getElementById('rBorrowEventAt')?.value || '',
      purpose: document.getElementById('rBorrowPurpose')?.value?.trim() || '',
      event: document.getElementById('rBorrowEvent')?.value?.trim() || '',
      location: document.getElementById('rBorrowLocation')?.value?.trim() || '',
      remarks: document.getElementById('rBorrowRemarks')?.value?.trim() || '',
    };
  }

  function notifyError(msg) {
    if (global.bcNotify?.error) global.bcNotify.error(msg);
    else if (global.bcNotify) global.bcNotify.warning(msg);
  }

  function notifySuccess(msg) {
    if (global.bcNotify?.success) global.bcNotify.success(msg);
    else if (global.bcNotify) global.bcNotify.info(msg);
  }

  function validateBorrowValues(v, item) {
    if (!v.fullName || !v.residentCode || !v.contact) {
      notifyError('Please complete your resident information.');
      return false;
    }
    if (!v.dateNeeded || !v.returnDate || !v.purpose) {
      notifyError('Please fill in all required borrow details.');
      return false;
    }
    const now = new Date();
    if (new Date(v.dateNeeded) < now) {
      notifyError('Pickup date and time must be in the future.');
      return false;
    }
    if (new Date(v.returnDate) <= new Date(v.dateNeeded)) {
      notifyError('Expected return date and time must be later than pickup.');
      return false;
    }
    if (v.eventAt && new Date(v.eventAt) < now) {
      notifyError('Event date and time cannot be in the past.');
      return false;
    }
    if (v.eventAt && (v.eventAt < v.dateNeeded || v.eventAt > v.returnDate)) {
      notifyError('The event schedule must fall between pickup and expected return.');
      return false;
    }
    const maxQty = item ? item.availableQty : 0;
    if (v.quantity < 1 || (maxQty && v.quantity > maxQty)) {
      notifyError(`Quantity must be between 1 and ${maxQty}.`);
      return false;
    }
    return true;
  }

  function validateBorrowForm(item) {
    return validateBorrowValues(readBorrowFormValues(), item);
  }

  function renderBorrowReview(item, v) {
    const values = v || readBorrowFormValues();
    const body = document.getElementById('rBorrowReviewBody');
    if (!body) return;
    const row = (label, value, full) => `
      <div class="r-borrow-review__item${full ? ' r-borrow-review__item--full' : ''}">
        <label>${label}</label><span>${esc(value || '—')}</span>
      </div>`;
    body.innerHTML = `
      <div class="r-borrow-review__block">
        <h3>Item</h3>
        <div class="r-borrow-review__grid">
          ${row('Item Name', item ? item.name : '—', true)}
          ${row('Quantity', `${values.quantity} ${item ? item.unit : ''}`)}
          ${row('Pickup Date & Time', fmtReviewDate(values.dateNeeded))}
          ${row('Expected Return Date & Time', fmtReviewDate(values.returnDate))}
        </div>
      </div>
      <div class="r-borrow-review__block">
        <h3>Resident Information</h3>
        <div class="r-borrow-review__grid">
          ${row('Full Name', values.fullName)}
          ${row('Resident ID', values.residentCode)}
          ${row('Contact Number', values.contact, true)}
        </div>
      </div>
      <div class="r-borrow-review__block">
        <h3>Request Details</h3>
        <div class="r-borrow-review__grid">
          ${row('Purpose', values.purpose, true)}
          ${row('Event / Activity', values.event || '—')}
          ${row('Event Date & Time', fmtReviewDate(values.eventAt))}
          ${row('Location / Venue', values.location || '—')}
          ${row('Additional Notes', values.remarks || '—', true)}
        </div>
      </div>`;
  }

  function openBorrowForm(item) {
    if (!item || item.availableQty <= 0 || item.status === 'unavailable') {
      notifyError('This item is not available to borrow.');
      return;
    }
    selectedItem = item;
    pendingBorrowSubmit = null;
    document.getElementById('rBorrowItemId').value = item.itemId;
    document.getElementById('rBorrowItemLabel').textContent =
      `Borrowing: ${item.name} — up to ${item.availableQty} ${item.unit} available`;
    const qty = document.getElementById('rBorrowQty');
    if (qty) {
      qty.max = item.availableQty;
      qty.value = '';
    }
    const hint = document.getElementById('rBorrowQtyHint');
    if (hint) hint.textContent = `Maximum available: ${item.availableQty}`;

    document.getElementById('rBorrowFullName').value = '';
    document.getElementById('rBorrowResidentCode').value = '';
    document.getElementById('rBorrowContact').value = '';
    document.getElementById('rBorrowPurpose').value = '';
    document.getElementById('rBorrowEvent').value = '';
    document.getElementById('rBorrowLocation').value = '';
    document.getElementById('rBorrowRemarks').value = '';
    document.getElementById('rBorrowDateNeeded').value = '';
    document.getElementById('rBorrowReturnDate').value = '';
    document.getElementById('rBorrowEventAt').value = '';
    const minDateTime = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    document.getElementById('rBorrowDateNeeded').min = minDateTime;
    document.getElementById('rBorrowReturnDate').min = minDateTime;
    document.getElementById('rBorrowEventAt').min = minDateTime;

    bindCharCounter('rBorrowPurpose', 'rBorrowPurposeCount', 200);
    bindCharCounter('rBorrowRemarks', 'rBorrowRemarksCount', 300);

    closeOverlay('rItemDetailModal');
    closeOverlay('rBorrowReviewModal');
    openOverlay('rBorrowFormModal');
  }

  function applyBorrowFormValues(v) {
    if (!v) return;
    document.getElementById('rBorrowItemId').value = v.itemId || '';
    document.getElementById('rBorrowFullName').value = v.fullName || '';
    document.getElementById('rBorrowResidentCode').value = v.residentCode || '';
    document.getElementById('rBorrowContact').value = v.contact || '';
    document.getElementById('rBorrowQty').value = v.quantity || '';
    document.getElementById('rBorrowDateNeeded').value = v.dateNeeded || '';
    document.getElementById('rBorrowReturnDate').value = v.returnDate || '';
    document.getElementById('rBorrowEventAt').value = v.eventAt || '';
    document.getElementById('rBorrowPurpose').value = v.purpose || '';
    document.getElementById('rBorrowEvent').value = v.event || '';
    document.getElementById('rBorrowLocation').value = v.location || '';
    document.getElementById('rBorrowRemarks').value = v.remarks || '';
  }

  function showBorrowReview() {
    const v = readBorrowFormValues();
    if (!selectedItem || !validateBorrowValues(v, selectedItem)) return;
    pendingBorrowSubmit = v;
    renderBorrowReview(selectedItem, v);
    closeOverlay('rBorrowFormModal');
    openOverlay('rBorrowReviewModal');
  }

  async function confirmBorrowSubmit() {
    const rid = residentId();
    if (!rid || rid === 'null' || rid === 'undefined') {
      notifyError('Please log in first.');
      return;
    }
    const v = pendingBorrowSubmit;
    if (!v || !selectedItem || !validateBorrowValues(v, selectedItem)) {
      notifyError('Please go back and complete the borrow form.');
      return;
    }

    const body = {
      residentUserId: rid,
      itemId: v.itemId,
      quantity: v.quantity,
      // Legacy columns are DATE-only; preserve the selected hours in remarks below.
      dateNeeded: v.dateNeeded.slice(0, 10),
      returnDate: v.returnDate.slice(0, 10),
      purpose: v.purpose,
      remarks: buildBorrowRemarksFromValues(v),
      fullName: v.fullName,
      residentCode: v.residentCode,
      contact: v.contact,
    };

    const submitBtn = document.getElementById('rBorrowConfirmSubmit');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const res = await API.borrow.submit(body);
      closeOverlay('rBorrowReviewModal');
      closeOverlay('rBorrowFormModal');
      notifySuccess(`Request ${res.borrowCode || ''} submitted successfully!`);
      document.getElementById('rBorrowForm')?.reset();
      pendingBorrowSubmit = null;
      notifyBorrowChange({ action: 'submit', borrowCode: res.borrowCode });
      loadItems();
    } catch (err) {
      notifyError(err.message || 'Submit failed');
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  function renderGrid() {
    const grid = document.getElementById('rBorrowItemsGrid');
    const empty = document.getElementById('rBorrowItemsEmpty');
    const countEl = document.getElementById('rBorrowCount');
    if (!grid) return;

    const filters = getFilters();
    const visible = filterItems(allItems, filters);

    if (countEl) {
      countEl.textContent = `Showing ${visible.length} of ${allItems.length} items`;
    }

    if (!visible.length) {
      grid.innerHTML = '';
      if (empty) empty.hidden = false;
      return;
    }
    if (empty) empty.hidden = true;
    grid.innerHTML = visible.map(renderItemCard).join('');
  }

  async function loadCategories() {
    const sel = document.getElementById('rBorrowCategory');
    if (!sel) return;
    try {
      const cats = await API.inventory.categories();
      sel.innerHTML = '<option value="all">All Categories</option>' +
        cats.map((c) => `<option value="${c.categoryId}">${esc(c.name)}</option>`).join('');
    } catch (e) { /* ignore */ }
  }

  async function loadItems() {
    try {
      allItems = await API.inventory.list({});
      renderGrid();
    } catch (e) {
      const grid = document.getElementById('rBorrowItemsGrid');
      if (grid) grid.innerHTML = '<p>Could not load items. Make sure XAMPP MySQL is running.</p>';
    }
  }

  async function loadMyBorrows() {
    const body = document.getElementById('rMyBorrowList');
    if (!body) return;
    const rid = residentId();
    if (!rid) {
      body.innerHTML = '<p style="padding:1rem">Please log in to view your borrow requests.</p>';
      return;
    }
    try {
      const rows = await API.borrow.my(rid);
      myBorrowRows = Array.isArray(rows) ? rows : [];
      if (!rows.length) {
        body.innerHTML = `<div class="empty-state"><div class="empty-icon"><i class="fas fa-inbox"></i></div><div><div class="empty-title">No borrow requests yet</div><p class="empty-subtitle">Browse available items and submit a request.</p></div></div>`;
        return;
      }
      body.innerHTML = rows.map((r) => `
        <div class="reports-table__row" role="row">
          <div class="reports-table__cell reports-table__cell--id" role="cell" data-label="Request ID">${esc(r.borrowCode)}</div>
          <div class="reports-table__cell reports-table__cell--issue" role="cell" data-label="Item">${esc(r.itemName)}</div>
          <div class="reports-table__cell" role="cell" data-label="Quantity">${r.quantity} ${esc(r.itemUnit)}</div>
          <div class="reports-table__cell" role="cell" data-label="Date Needed">${fmtDate(r.dateNeeded)}</div>
          <div class="reports-table__cell" role="cell" data-label="Return Date">${fmtDate(r.returnDate)}</div>
          <div class="reports-table__cell reports-table__cell--status" role="cell" data-label="Status">${borrowStatusBadge(r.status)}</div>
        </div>`).join('');
    } catch (e) {
      body.innerHTML = '<p style="padding:1rem">Could not load your borrow requests.</p>';
    }
  }

  async function submitBorrow(e) {
    e.preventDefault();
    showBorrowReview();
  }

  function findItem(id) {
    return allItems.find((i) => String(i.itemId) === String(id));
  }

  function initAvailable() {
    loadCategories().then(loadItems);
    watchBorrowSync(function () {
      loadItems();
    });

    let timer;
    document.getElementById('rBorrowSearch')?.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(renderGrid, 250);
    });
    document.getElementById('rBorrowCategory')?.addEventListener('change', renderGrid);
    document.getElementById('rBorrowAvailability')?.addEventListener('change', renderGrid);

    document.getElementById('rBorrowItemsGrid')?.addEventListener('click', (e) => {
      const viewBtn = e.target.closest('[data-view-item]');
      const borrowBtn = e.target.closest('[data-borrow-item]');
      const card = e.target.closest('[data-item-id]');
      const id = viewBtn?.getAttribute('data-view-item') ||
        borrowBtn?.getAttribute('data-borrow-item') ||
        card?.getAttribute('data-item-id');
      if (!id) return;
      const item = findItem(id);
      if (!item) return;

      if (borrowBtn) {
        e.stopPropagation();
        selectedItem = item;
        setSelectedCard(item.itemId);
        openBorrowForm(item);
        return;
      }
      if (viewBtn) {
        e.stopPropagation();
        openItemDetails(item);
        return;
      }
      if (card) {
        if (isCompactLayout()) openItemDetails(item);
        else showSidePanel(item);
      }
    });

    document.getElementById('rPanelClose')?.addEventListener('click', clearItemSelection);
    document.getElementById('rPanelBorrowBtn')?.addEventListener('click', () => {
      if (selectedItem) openBorrowForm(selectedItem);
    });
    document.getElementById('rPanelDetailsBtn')?.addEventListener('click', () => {
      if (!selectedItem) return;
      openItemDetails(selectedItem);
    });
    document.getElementById('rDetailBorrowBtn')?.addEventListener('click', () => {
      if (selectedItem) openBorrowForm(selectedItem);
    });

    COMPACT_MQ?.addEventListener('change', () => {
      if (isCompactLayout()) hideSidePanel();
    });

    document.getElementById('rBorrowForm')?.addEventListener('submit', submitBorrow);
    document.getElementById('rBorrowContinueReview')?.addEventListener('click', showBorrowReview);
    document.getElementById('rBorrowBackToEdit')?.addEventListener('click', () => {
      if (pendingBorrowSubmit) applyBorrowFormValues(pendingBorrowSubmit);
      closeOverlay('rBorrowReviewModal');
      openOverlay('rBorrowFormModal');
    });
    document.getElementById('rBorrowConfirmSubmit')?.addEventListener('click', confirmBorrowSubmit);
    document.getElementById('rBorrowPrintRequest')?.addEventListener('click', printBorrowRequest);
  }

  async function printBorrowRequest() {
    if (!selectedItem || !pendingBorrowSubmit) return;
    const v = pendingBorrowSubmit;
    const button = document.getElementById('rBorrowPrintRequest');
    if (button) { button.disabled = true; button.dataset.oldLabel = button.innerHTML; button.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Creating PDF…'; }
    try {
      const url = new URL('../../FrontPage/php/borrow_request_pdf.php', global.location.href);
      const response = await fetch(url.href, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          itemId: v.itemId,
          quantity: v.quantity,
          dateNeeded: v.dateNeeded,
          returnDate: v.returnDate,
          purpose: v.purpose,
          event: v.event,
          eventAt: v.eventAt,
          location: v.location,
          remarks: v.remarks,
          contact: v.contact,
        }),
      });
      const contentType = response.headers.get('content-type') || '';
      if (!response.ok || !contentType.includes('application/pdf')) {
        const result = await response.json().catch(() => ({}));
        throw new Error(result.error || 'Could not create the PDF. Please try again.');
      }
      const blob = await response.blob();
      const objectUrl = URL.createObjectURL(blob);
      const download = document.createElement('a');
      download.href = objectUrl;
      download.download = `borrow-request-${new Date().toISOString().slice(0, 10)}.pdf`;
      document.body.appendChild(download);
      download.click();
      download.remove();
      global.setTimeout(() => URL.revokeObjectURL(objectUrl), 30000);
      notifySuccess('Compact borrowing request PDF downloaded.');
    } catch (error) {
      notifyError(error.message || 'Could not create the PDF.');
    } finally {
      if (button) { button.disabled = false; button.innerHTML = button.dataset.oldLabel || '<i class="fas fa-file-pdf"></i> Download compact PDF'; }
    }
  }

  function printMyBorrowHistory() {
    const sheet = document.getElementById('borrowPrintSheet');
    if (!sheet) return;
    if (!myBorrowRows.length) { global.alert('No borrowing records are available to print.'); return; }
    const first = global.localStorage.getItem('residentFirstname') || '';
    const last = global.localStorage.getItem('residentLastname') || '';
    const residentName = (first + ' ' + last).trim() || 'Resident';
    const rows = myBorrowRows.map(r => `<tr><td>${esc(residentName)}</td><td>${esc(r.itemName || '')}</td><td>${esc(r.quantity || '')} ${esc(r.itemUnit || '')}</td><td>${fmtDate(r.submittedAt || '')}</td><td>${fmtDate(r.returnDate || '')}</td><td>${fmtDate(r.returnedAt || '')}</td><td>${esc(r.status || '')}</td><td>${esc(r.borrowCode || '')}</td></tr>`).join('');
    sheet.innerHTML = `<h1>BarangayConnect — Borrowing History</h1><p class="print-meta">Resident: ${esc(residentName)} · Printed: ${esc(new Date().toLocaleString())}</p><table><thead><tr><th>Resident</th><th>Item</th><th>Quantity</th><th>Date requested</th><th>Expected return</th><th>Actual return</th><th>Status</th><th>Reference</th></tr></thead><tbody>${rows}</tbody></table><div class="signature"><span>Prepared by: ____________________</span><span>Resident: ____________________</span></div>`;
    global.print();
  }

  function initMyRequests() {
    loadMyBorrows();
    watchBorrowSync(function () { loadMyBorrows(); });
    document.getElementById('rRefreshBorrows')?.addEventListener('click', loadMyBorrows);
    document.getElementById('rPrintBorrowHistory')?.addEventListener('click', printMyBorrowHistory);
  }

  function init() {
    bindCloseButtons();
    const page = document.body.getAttribute('data-r-borrow-page');
    if (page === 'available') initAvailable();
    else if (page === 'my-requests') initMyRequests();
    else {
      initAvailable();
      initMyRequests();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window);
