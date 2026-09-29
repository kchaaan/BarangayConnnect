/**
 * Admin Borrow & Inventory pages — live data from PHP APIs.
 */
(function (global) {
  const API = global.BarangayConnectBorrowInventoryApi;
  if (!API) return;

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

  function statusBadge(status) {
    const map = {
      pending: ['bi-badge--pending', 'Pending'],
      approved: ['bi-badge--approved', 'Approved'],
      rejected: ['bi-badge--rejected', 'Rejected'],
      released: ['bi-badge--released', 'Released'],
      returned: ['bi-badge--returned', 'Returned'],
      overdue: ['bi-badge--overdue', 'Overdue'],
      damaged_lost: ['bi-badge--damaged', 'Damaged / Lost'],
      available: ['bi-badge--available', 'Available'],
      low_stock: ['bi-badge--low', 'Low Stock'],
      unavailable: ['bi-badge--unavailable', 'Unavailable'],
    };
    const m = map[status] || ['bi-badge--completed', status];
    return `<span class="bi-badge ${m[0]}">${esc(m[1])}</span>`;
  }

  function qtyClass(item) {
    if ((item.availableQty || 0) <= 0) return 'bi-qty--zero';
    if (item.status === 'low_stock') return 'bi-qty--low';
    return 'bi-qty--ok';
  }

  function bindSearchRefresh(searchSel, statusSel, categorySel, reloadFn) {
    const search = document.querySelector(searchSel);
    const status = statusSel ? document.querySelector(statusSel) : null;
    const category = categorySel ? document.querySelector(categorySel) : null;
    let timer;
    const run = () => reloadFn({
      search: search?.value?.trim() || '',
      status: status?.value || 'all',
      category_id: category?.value && category.value !== 'all' ? category.value : '',
    });
    search?.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(run, 300);
    });
    status?.addEventListener('change', run);
    category?.addEventListener('change', run);
    return run;
  }

  async function loadCategoriesInto(selectEl) {
    if (!selectEl) return;
    const cats = await API.inventory.categories();
    const current = selectEl.value;
    selectEl.innerHTML = '<option value="all">All Categories</option>';
    cats.forEach((c) => {
      const opt = document.createElement('option');
      opt.value = String(c.categoryId);
      opt.textContent = c.name;
      selectEl.appendChild(opt);
    });
    if (current) selectEl.value = current;
  }

  function borrowActionButtons(r) {
    const view = `<button type="button" class="bi-icon-btn" title="View details" data-borrow-view="${r.borrowPk}"><i class="fas fa-eye"></i></button>`;
    if (r.status !== 'pending') return view;
    return `${view}
      <button type="button" class="bi-icon-btn bi-icon-btn--approve" title="Approve" data-borrow-approve="${r.borrowPk}"><i class="fas fa-check"></i></button>
      <button type="button" class="bi-icon-btn bi-icon-btn--reject" title="Reject" data-borrow-reject="${r.borrowPk}"><i class="fas fa-times"></i></button>`;
  }

  function notifyBorrowChange(detail) {
    if (global.BarangayConnectBorrowSync) {
      global.BarangayConnectBorrowSync.notify(Object.assign({ source: 'admin' }, detail || {}));
    }
  }

  function notifyInventoryChange(detail) {
    notifyBorrowChange(Object.assign({ scope: 'inventory', action: 'inventory-changed' }, detail || {}));
  }

  function watchBorrowSync(callback) {
    if (global.BarangayConnectBorrowSync) {
      global.BarangayConnectBorrowSync.watch(callback);
    }
  }

  async function reloadBorrowRequests(run) {
    await run({
      search: document.querySelector('#brSearch')?.value?.trim() || '',
      status: document.querySelector('#brStatus')?.value || 'all',
      category_id: document.querySelector('#brCategory')?.value || '',
    });
  }

  async function askConfirm(message, opts) {
    if (global.bcConfirm) {
      return await global.bcConfirm(message, opts || {});
    }
    return global.confirm(message);
  }

  async function quickApproveBorrow(id, onDone) {
    const ok = await askConfirm('Approve this borrow request?', {
      title: 'Approve request',
      okText: 'Approve',
      cancelText: 'Cancel',
    });
    if (!ok) return;
    try {
      await API.borrow.updateStatus(id, { status: 'approved' });
      if (onDone) await onDone();
      notifyBorrowChange({ action: 'status', borrowPk: id, status: 'approved' });
      if (global.bcNotify?.success) global.bcNotify.success('Request approved.');
    } catch (err) {
      if (global.bcNotify?.error) global.bcNotify.error(err.message || 'Approve failed');
    }
  }

  /* ---- Borrow Requests ---- */
  async function initBorrowRequests() {
    const tbody = document.getElementById('biBorrowTableBody');
    if (!tbody) return;

    const setStats = (s) => {
      const map = {
        biStatTotal: s.total,
        biStatPending: s.pending,
        biStatApproved: s.approved,
        biStatRejected: s.rejected,
      };
      Object.keys(map).forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.textContent = map[id] ?? 0;
      });
    };

    const render = async (filters) => {
      try {
        const [stats, allRows, items] = await Promise.all([
          API.borrow.stats(),
          API.borrow.list({ status: filters.status, category_id: filters.category_id, search: filters.search }),
          API.inventory.list({ available_only: '1' }),
        ]);
        const rows = allRows.filter((r) => ['pending', 'approved', 'rejected'].includes(r.status));
        setStats(stats);
        tbody.innerHTML = rows.length
          ? rows.map((r) => `
            <tr>
              <td>${esc(r.borrowCode)}</td>
              <td>${esc(r.residentName)}</td>
              <td>${esc(r.itemName)}</td>
              <td>${r.quantity}</td>
              <td>${fmtDate(r.dateNeeded)}</td>
              <td>${fmtDate(r.returnDate)}</td>
              <td>${statusBadge(r.status)}</td>
              <td class="bi-actions">${borrowActionButtons(r)}</td>
            </tr>`).join('')
          : `<tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><i class="fas fa-inbox"></i></div><div><div class="empty-title">No borrow requests yet</div><p class="empty-subtitle">New resident submissions will appear here.</p></div></div></td></tr>`;

        const avail = document.getElementById('biAvailBody');
        if (avail) {
          avail.innerHTML = items.slice(0, 6).map((i) => `
            <tr><td>${esc(i.name)}</td><td class="${qtyClass(i)}">${i.availableQty}</td><td>${esc(i.unit)}</td></tr>`).join('');
        }
      } catch (e) {
        tbody.innerHTML = `<tr><td colspan="8">Could not load data. Start XAMPP MySQL and refresh.</td></tr>`;
      }
    };

    await loadCategoriesInto(document.getElementById('brCategory'));
    const run = bindSearchRefresh('#brSearch', '#brStatus', '#brCategory', render);
    run({ search: '', status: 'all', category_id: '' });
    watchBorrowSync(function () {
      reloadBorrowRequests(run);
    });

    tbody.addEventListener('click', async (e) => {
      const viewBtn = e.target.closest('[data-borrow-view]');
      const approveBtn = e.target.closest('[data-borrow-approve]');
      const rejectBtn = e.target.closest('[data-borrow-reject]');
      const reload = () => reloadBorrowRequests(run);

      if (approveBtn) {
        await quickApproveBorrow(Number(approveBtn.getAttribute('data-borrow-approve')), reload);
        return;
      }

      if (viewBtn || rejectBtn) {
        const id = (viewBtn || rejectBtn).getAttribute(viewBtn ? 'data-borrow-view' : 'data-borrow-reject');
        try {
          const row = await API.fetchJson(API.borrow.getUrl(id));
          openBorrowRequestModal(row, reload, rejectBtn ? 'reject' : null);
        } catch (err) {
          if (global.bcNotify) global.bcNotify(err.message || 'Could not load request.', 'error');
        }
      }
    });
  }

  function openBorrowRequestModal(row, onDone, focusField) {
    const modal = document.getElementById('borrowRequestModal');
    if (!modal) return;
    const canReview = row.status === 'pending';
    modal.querySelector('[data-f-id]') && (modal.querySelector('[data-f-id]').textContent = row.borrowCode);
    const meta = modal.querySelector('.bi-modal-meta');
    if (meta) {
      meta.innerHTML = `
        <div><strong>Request ID</strong>${esc(row.borrowCode)}</div>
        <div><strong>Date Submitted</strong>${fmtDate(row.submittedAt)}</div>
        <div><strong>Status</strong>${statusBadge(row.status)}</div>`;
    }
    const body = modal.querySelector('.bi-modal-body');
    if (body) {
      body.innerHTML = `
        <section class="bi-section"><h3 class="bi-section-title"><i class="fas fa-user"></i> Borrower</h3>
          <div class="bi-detail-grid">
            <div class="bi-detail-item"><label>Name</label><span>${esc(row.residentName)}</span></div>
            <div class="bi-detail-item"><label>Resident ID</label><span>${esc(row.residentCode || '—')}</span></div>
            <div class="bi-detail-item"><label>Contact</label><span>${esc(row.contactNumber)}</span></div>
            <div class="bi-detail-item full"><label>Address</label><span>${esc(row.address)}</span></div>
          </div></section>
        <section class="bi-section"><h3 class="bi-section-title"><i class="fas fa-box"></i> Item</h3>
          <div class="bi-detail-grid">
            <div class="bi-detail-item"><label>Item</label><span>${esc(row.itemName)}</span></div>
            <div class="bi-detail-item"><label>Category</label><span>${esc(row.categoryName)}</span></div>
            <div class="bi-detail-item"><label>Quantity</label><span>${row.quantity} ${esc(row.itemUnit)}</span></div>
            <div class="bi-detail-item"><label>Available</label><span>${row.itemAvailableQty} ${esc(row.itemUnit)}</span></div>
          </div></section>
        <section class="bi-section"><h3 class="bi-section-title"><i class="fas fa-calendar-alt"></i> Schedule</h3>
          <div class="bi-detail-grid">
            <div class="bi-detail-item"><label>Date Needed</label><span>${fmtDate(row.dateNeeded)}</span></div>
            <div class="bi-detail-item"><label>Return Date</label><span>${fmtDate(row.returnDate)}</span></div>
          </div></section>
        <section class="bi-section"><h3 class="bi-section-title"><i class="fas fa-file-alt"></i> Purpose</h3>
          <div class="bi-detail-grid">
            <div class="bi-detail-item full"><label>Purpose</label><span>${esc(row.purpose)}</span></div>
            <div class="bi-detail-item full"><label>Remarks</label><span>${esc(row.remarks || '—')}</span></div>
          </div></section>
        <section class="bi-section"><h3 class="bi-section-title"><i class="fas fa-user-cog"></i> Admin Review</h3>
          <div class="bi-form-grid">
            <div class="bi-form-field full"><label>Approval Remarks</label><input type="text" id="biAdminRemarks" value="${esc(row.adminRemarks || '')}"></div>
            <div class="bi-form-field"><label>Rejection Reason</label><input type="text" id="biRejectReason" value="${esc(row.rejectionReason || '')}"></div>
          </div></section>`;
    }
    modal.classList.add('is-open');

    const close = () => modal.classList.remove('is-open');
    modal.querySelectorAll('[data-bi-close]').forEach((b) => { b.onclick = close; });

    const act = async (status) => {
      try {
        await API.borrow.updateStatus(row.borrowPk, {
          status,
          adminRemarks: document.getElementById('biAdminRemarks')?.value || '',
          rejectionReason: document.getElementById('biRejectReason')?.value || '',
        });
        close();
        if (onDone) onDone();
        notifyBorrowChange({ action: 'status', borrowPk: row.borrowPk, status: status });
        if (global.bcNotify?.success) global.bcNotify.success('Status updated.');
      } catch (err) {
        if (global.bcNotify?.error) global.bcNotify.error(err.message || 'Update failed');
      }
    };

    const approveBtn = modal.querySelector('[data-bi-approve]');
    const rejectBtn = modal.querySelector('[data-bi-reject]');
    if (approveBtn) {
      approveBtn.style.display = canReview ? '' : 'none';
      approveBtn.onclick = canReview ? () => act('approved') : null;
    }
    if (rejectBtn) {
      rejectBtn.style.display = canReview ? '' : 'none';
      rejectBtn.onclick = canReview ? () => act('rejected') : null;
    }

    if (focusField === 'reject') {
      setTimeout(() => document.getElementById('biRejectReason')?.focus(), 80);
    }
  }

  /* ---- Monitoring ---- */
  async function initMonitoring() {
    const tbody = document.getElementById('biMonitorTableBody');
    if (!tbody) return;

    const render = async (filters) => {
      try {
        const [stats, rows] = await Promise.all([API.borrow.stats(), API.borrow.monitoring()]);
        ['biMonTotal', 'biMonReleased', 'biMonReturned', 'biMonOverdue', 'biMonDamaged'].forEach((id, i) => {
          const keys = ['total', 'released', 'returned', 'overdue', 'damaged_lost'];
          const el = document.getElementById(id);
          if (el) el.textContent = stats[keys[i]] ?? 0;
        });
        let list = rows;
        if (filters.status && filters.status !== 'all') {
          list = list.filter((r) => r.status === filters.status);
        }
        if (filters.search) {
          const q = filters.search.toLowerCase();
          list = list.filter((r) =>
            (r.borrowCode || '').toLowerCase().includes(q) ||
            (r.residentName || '').toLowerCase().includes(q) ||
            (r.itemName || '').toLowerCase().includes(q));
        }
        tbody.innerHTML = list.length ? list.map((r) => `
          <tr>
            <td>${esc(r.borrowCode)}</td><td>${esc(r.residentName)}</td><td>${esc(r.itemName)}</td><td>${r.quantity}</td>
            <td>${fmtDate(r.dateNeeded)}</td><td>${fmtDate(r.returnDate)}</td><td>${fmtDate(r.returnedAt)}</td>
            <td>${statusBadge(r.status)}</td>
            <td><button type="button" class="bi-icon-btn" data-monitor-view="${r.borrowPk}"><i class="fas fa-eye"></i></button></td>
          </tr>`).join('') : `<tr><td colspan="9">No active borrowed items.</td></tr>`;
      } catch (e) {
        tbody.innerHTML = `<tr><td colspan="9">Could not load monitoring data.</td></tr>`;
      }
    };

    const run = bindSearchRefresh('#monSearch', '#monStatus', null, render);
    run({ search: '', status: 'all' });
    watchBorrowSync(function () {
      run({ search: document.querySelector('#monSearch')?.value?.trim() || '', status: document.querySelector('#monStatus')?.value || 'all' });
    });

    tbody.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-monitor-view]');
      if (!btn) return;
      const id = btn.getAttribute('data-monitor-view');
      const row = await API.fetchJson(API.borrow.getUrl(id));
      openMonitorModal(row, () => run({ search: '', status: 'all' }));
    });
  }

  function openMonitorModal(row, onDone) {
    const modal = document.getElementById('monitorDetailModal');
    if (!modal) return;
    modal.querySelector('.bi-modal-meta').innerHTML = `
      <div><strong>Request ID</strong>${esc(row.borrowCode)}</div>
      <div><strong>Submitted</strong>${fmtDate(row.submittedAt)}</div>
      <div><strong>Status</strong>${statusBadge(row.status)}</div>`;
    modal.classList.add('is-open');
    modal.querySelectorAll('[data-bi-close]').forEach((b) => {
      b.onclick = () => modal.classList.remove('is-open');
    });
    const act = async (status, extra) => {
      try {
        await API.borrow.updateStatus(row.borrowPk, { status, ...extra });
        modal.classList.remove('is-open');
        if (onDone) onDone();
        notifyBorrowChange({ action: 'status', borrowPk: row.borrowPk, status: status });
        if (global.bcNotify?.success) global.bcNotify.success('Updated.');
      } catch (err) {
        if (global.bcNotify?.error) global.bcNotify.error(err.message || 'Update failed');
      }
    };
    const releaseBtn = modal.querySelector('[data-bi-release]');
    const returnedBtn = modal.querySelector('[data-bi-returned]');
    const damagedBtn = modal.querySelector('[data-bi-damaged]');
    if (releaseBtn) releaseBtn.onclick = () => act('released');
    if (returnedBtn) returnedBtn.onclick = () => act('returned', { returnCondition: 'Good Condition' });
    if (damagedBtn) damagedBtn.onclick = () => act('damaged_lost');
  }

  /* ---- History ---- */
  async function initHistory() {
    const tbody = document.getElementById('biHistoryTableBody');
    if (!tbody) return;
    try {
      const rows = await API.borrow.history();
      tbody.innerHTML = rows.length ? rows.map((r) => `
        <tr>
          <td>${esc(r.borrowCode)}</td><td>${esc(r.residentName)}</td><td>${esc(r.itemName)}</td><td>${r.quantity}</td>
          <td>${fmtDate(r.dateNeeded)}</td><td>${fmtDate(r.returnedAt || r.returnDate)}</td>
          <td>${statusBadge(r.status)}</td>
          <td></td>
        </tr>`).join('') : `<tr><td colspan="8">No history records yet.</td></tr>`;
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="8">Could not load history.</td></tr>`;
    }
  }

  /* ---- Inventory ---- */
  function clearItemForm(prefix) {
    document.getElementById(`${prefix}Name`).value = '';
    document.getElementById(`${prefix}Desc`).value = '';
    document.getElementById(`${prefix}Total`).value = '0';
    document.getElementById(`${prefix}Avail`).value = '0';
    const cat = document.getElementById(`${prefix}Category`);
    if (cat) cat.value = '';
    const unit = document.getElementById(`${prefix}Unit`);
    if (unit) unit.value = 'pcs';
  }

  function readItemForm(prefix) {
    return {
      name: document.getElementById(`${prefix}Name`)?.value?.trim() || '',
      categoryId: Number(document.getElementById(`${prefix}Category`)?.value || 0),
      description: document.getElementById(`${prefix}Desc`)?.value?.trim() || '',
      totalQty: Number(document.getElementById(`${prefix}Total`)?.value || 0),
      availableQty: Number(document.getElementById(`${prefix}Avail`)?.value || 0),
      unit: document.getElementById(`${prefix}Unit`)?.value || 'pcs',
    };
  }

  function validateItemForm(body) {
    if (!body.name) {
      if (global.bcNotify?.error) global.bcNotify.error('Item name is required.');
      return false;
    }
    if (body.totalQty < 0 || body.availableQty < 0) {
      if (global.bcNotify?.error) global.bcNotify.error('Quantities cannot be negative.');
      return false;
    }
    if (body.availableQty > body.totalQty) {
      if (global.bcNotify?.error) global.bcNotify.error('Available quantity cannot exceed total quantity.');
      return false;
    }
    return true;
  }

  async function populateCategorySelects() {
    const cats = await API.inventory.categories();
    const options = '<option value="">Select category</option>' +
      cats.map((c) => `<option value="${c.categoryId}">${esc(c.name)}</option>`).join('');
    ['addItemCategory', 'editItemCategory'].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.innerHTML = options;
    });
    return cats;
  }

  async function initInventory() {
    const tbody = document.getElementById('biInventoryTableBody');
    if (!tbody) return;

    const setStats = (s) => {
      const elMap = { biInvTotal: s.total, biInvAvail: s.available, biInvLow: s.low_stock, biInvUnavail: s.unavailable };
      Object.keys(elMap).forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.textContent = elMap[id] ?? 0;
      });
    };

    const render = async (filters) => {
      try {
        const [stats, items] = await Promise.all([
          API.inventory.stats(),
          API.inventory.list({ status: filters.status, category_id: filters.category_id, search: filters.search }),
        ]);
        setStats(stats);
        tbody.innerHTML = items.length ? items.map((i) => `
          <tr>
            <td><div class="bi-item-cell"><div class="bi-item-thumb" style="display:flex;align-items:center;justify-content:center;background:#eef2ee"><i class="fas fa-box" style="color:#7faa70"></i></div>
              <div><p class="bi-item-name">${esc(i.name)}</p><p class="bi-item-desc">${esc(i.description || '')}</p></div></div></td>
            <td>${esc(i.categoryName || '—')}</td>
            <td class="${qtyClass(i)}">${i.availableQty}</td><td>${i.totalQty}</td><td>${esc(i.unit || 'pcs')}</td>
            <td>${statusBadge(i.status)}</td>
            <td class="bi-actions">
              <button type="button" class="bi-btn bi-btn--outline" style="padding:0.35rem 0.65rem;font-size:0.78rem" data-edit-item="${i.itemId}"><i class="fas fa-pen"></i> Edit</button>
              <button type="button" class="bi-btn bi-btn--outline bi-btn--danger" style="padding:0.35rem 0.65rem;font-size:0.78rem;margin-left:0.25rem" data-delete-item="${i.itemId}" title="Delete item"><i class="fas fa-trash"></i></button>
            </td>
          </tr>`).join('') : `<tr><td colspan="7">No inventory items. Add one with the button above.</td></tr>`;
      } catch (e) {
        tbody.innerHTML = `<tr><td colspan="7">Could not load inventory.</td></tr>`;
      }
    };

    await loadCategoriesInto(document.getElementById('invCategoryFilter'));
    await populateCategorySelects();

    const run = bindSearchRefresh('#invSearch', '#invStatusFilter', '#invCategoryFilter', render);
    run({ search: '', status: 'all', category_id: '' });

    watchBorrowSync(function (payload) {
      if (payload && payload.scope === 'inventory') run();
    });

    document.getElementById('addItemSave')?.addEventListener('click', async () => {
      const body = readItemForm('addItem');
      if (!validateItemForm(body)) return;
      const btn = document.getElementById('addItemSave');
      if (btn) btn.disabled = true;
      try {
        await API.inventory.create(body);
        BorrowInventoryUI.closeModal('addItemModal');
        clearItemForm('addItem');
        await run();
        notifyInventoryChange({ action: 'create' });
        if (global.bcNotify?.success) global.bcNotify.success('Item added successfully.');
      } catch (err) {
        if (global.bcNotify?.error) global.bcNotify.error(err.message || 'Save failed');
      } finally {
        if (btn) btn.disabled = false;
      }
    });

    document.getElementById('editItemSave')?.addEventListener('click', async () => {
      const itemId = Number(document.getElementById('editItemId')?.value || 0);
      const body = readItemForm('editItem');
      if (!itemId || !validateItemForm(body)) return;
      const btn = document.getElementById('editItemSave');
      if (btn) btn.disabled = true;
      try {
        await API.inventory.update(itemId, body);
        BorrowInventoryUI.closeModal('editItemModal');
        await run();
        notifyInventoryChange({ action: 'update', itemId });
        if (global.bcNotify?.success) global.bcNotify.success('Item updated successfully.');
      } catch (err) {
        if (global.bcNotify?.error) global.bcNotify.error(err.message || 'Update failed');
      } finally {
        if (btn) btn.disabled = false;
      }
    });

    tbody.addEventListener('click', async (e) => {
      const editBtn = e.target.closest('[data-edit-item]');
      const deleteBtn = e.target.closest('[data-delete-item]');
      if (editBtn) {
        const itemId = Number(editBtn.getAttribute('data-edit-item'));
        try {
          const item = await API.inventory.get(itemId);
          document.getElementById('editItemId').value = item.itemId;
          document.getElementById('editItemName').value = item.name || '';
          document.getElementById('editItemDesc').value = item.description || '';
          document.getElementById('editItemTotal').value = item.totalQty ?? 0;
          document.getElementById('editItemAvail').value = item.availableQty ?? 0;
          document.getElementById('editItemCategory').value = item.categoryId || '';
          document.getElementById('editItemUnit').value = item.unit || 'pcs';
          BorrowInventoryUI.openModal('editItemModal');
        } catch (err) {
          if (global.bcNotify?.error) global.bcNotify.error(err.message || 'Could not load item');
        }
        return;
      }
      if (deleteBtn) {
        const itemId = Number(deleteBtn.getAttribute('data-delete-item'));
        const confirmed = await askConfirm('Delete this item? Items with borrow history cannot be deleted.');
        if (!confirmed) return;
        try {
          await API.inventory.delete(itemId);
          await run();
          notifyInventoryChange({ action: 'delete', itemId });
          if (global.bcNotify?.success) global.bcNotify.success('Item deleted.');
        } catch (err) {
          if (global.bcNotify?.error) global.bcNotify.error(err.message || 'Delete failed');
        }
      }
    });
  }

  /* ---- Categories ---- */
  async function initCategories() {
    const grid = document.getElementById('biCategoryGrid');
    const tbody = document.getElementById('biCategoryTableBody');
    if (!grid && !tbody) return;
    try {
      const cats = await API.inventory.categories();
      if (grid) {
        grid.innerHTML = cats.map((c) => `
          <div class="bi-category-card">
            <h4>${esc(c.name)}</h4>
            <p>${esc(c.description || '')}</p>
            <div class="bi-category-count">${c.itemCount} <span style="font-size:0.82rem;font-weight:400;color:var(--bi-muted)">items</span></div>
          </div>`).join('');
      }
      if (tbody) {
        tbody.innerHTML = cats.map((c) => `
          <tr><td>${esc(c.name)}</td><td>${esc(c.description || '')}</td><td>${c.itemCount}</td><td></td></tr>`).join('');
      }
    } catch (e) {
      if (tbody) tbody.innerHTML = `<tr><td colspan="4">Could not load categories.</td></tr>`;
    }
  }

  function init() {
    const page = document.body.getAttribute('data-bi-page');
    if (page === 'borrow-requests') initBorrowRequests();
    else if (page === 'monitoring') initMonitoring();
    else if (page === 'history') initHistory();
    else if (page === 'inventory') initInventory();
    else if (page === 'categories') initCategories();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window);
