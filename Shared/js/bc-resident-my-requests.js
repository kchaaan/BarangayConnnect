/**
 * Resident "My Requests" list — status & admin remarks from service_requests API.
 */
(function (global) {
  const Api = global.BarangayConnectRequestsApi;
  let allMyRequests = [];

  const REQUEST_TYPE_LABELS = {
    personal_identification: 'Personal Identification & Residency',
    property_construction_business: 'Property, Construction & Business',
    family_social_welfare: 'Family, Social & Welfare',
    legal_complaint_settlement: 'Legal, Complaint & Settlement',
    community_other: 'Community & Other Requests',
  };

  function escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function statusKey(status) {
    const s = String(status || 'pending').toLowerCase();
    if (s === 'completed') return 'completed';
    if (s === 'rejected') return 'rejected';
    if (s === 'in_progress' || s.includes('progress')) return 'in_progress';
    return 'pending';
  }

  function statusLabel(status) {
    const labels = {
      pending: 'Pending',
      in_progress: 'In Progress',
      completed: 'Completed',
      rejected: 'Rejected',
    };
    return labels[statusKey(status)] || String(status || 'Pending');
  }

  function requestTypeLabel(type) {
    if (!type) return 'Service request';
    return REQUEST_TYPE_LABELS[type] || String(type).replace(/_/g, ' ');
  }

  function formatDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function formatDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return (
      d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) +
      ' · ' +
      d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    );
  }

  function residentId() {
    const S = global.BarangayConnectSession;
    return S ? S.getResidentId() : global.localStorage.getItem('residentId');
  }

  function residentMatchTokens() {
    const pk = residentId();
    const code = global.localStorage.getItem('residentCode') || '';
    const tokens = new Set();
    if (pk) {
      tokens.add(String(pk).trim());
    }
    if (code) {
      tokens.add(String(code).trim());
    }
    return tokens;
  }

  function residentCode() {
    return String(global.localStorage.getItem('residentCode') || '').trim();
  }

  /** Client-side guard — must match server bc_fetch_service_requests_for_resident rules. */
  function requestBelongsToResident(req, tokens) {
    if (!req || !tokens || tokens.size === 0) {
      return false;
    }
    const pk = residentId();
    const rowPk = req.residentUserId != null ? parseInt(String(req.residentUserId), 10) : 0;
    if (rowPk > 0) {
      return pk && String(rowPk) === String(pk);
    }
    const code = residentCode();
    const payloadId = req.residentId != null ? String(req.residentId).trim() : '';
    const localId = req.residentIdLocal != null ? String(req.residentIdLocal).trim() : '';
    if (code) {
      // Strong filter when resident code is available.
      return localId === code || payloadId === code;
    }
    return (payloadId && tokens.has(payloadId)) || (localId && tokens.has(localId));
  }

  function setListState(prefix, state) {
    const loading = document.getElementById(prefix + 'Loading');
    const empty = document.getElementById(prefix + 'Empty');
    const error = document.getElementById(prefix + 'Error');
    const guest = document.getElementById(prefix + 'Guest');
    if (loading) loading.hidden = state !== 'loading';
    if (empty) empty.hidden = state !== 'empty';
    if (error) error.hidden = state !== 'error';
    if (guest) guest.hidden = state !== 'guest';
    if (state === 'none') {
      if (loading) loading.hidden = true;
      if (empty) empty.hidden = true;
      if (error) error.hidden = true;
      if (guest) guest.hidden = true;
    }
  }

  function selectedStatusFilter() {
    return document.getElementById('reqStatusFilter')?.value || 'all';
  }

  function applyStatusFilter(list) {
    const selected = selectedStatusFilter();
    if (selected === 'all') return list;
    return (list || []).filter(function (req) {
      return statusKey(req.status) === selected;
    });
  }

  function renderFilteredRequests() {
    const list = document.getElementById('myRequestList');
    if (!list) return;
    list.innerHTML = '';
    const filtered = applyStatusFilter(allMyRequests);
    if (!filtered.length) {
      setListState('myRequest', 'empty');
      return;
    }
    setListState('myRequest', 'none');
    filtered.forEach(function (req) {
      list.appendChild(createRequestCard(req));
    });
  }

  function updateStats(requests) {
    const pending = document.getElementById('reqStatPending');
    const progress = document.getElementById('reqStatProgress');
    const completed = document.getElementById('reqStatCompleted');
    if (!pending) return;

    let p = 0;
    let ip = 0;
    let c = 0;
    (requests || []).forEach(function (r) {
      const k = statusKey(r.status);
      if (k === 'pending') p += 1;
      else if (k === 'in_progress') ip += 1;
      else if (k === 'completed') c += 1;
    });
    pending.textContent = String(p);
    progress.textContent = String(ip);
    completed.textContent = String(c);
  }

  function createRequestCard(req) {
    const sk = statusKey(req.status);
    const card = document.createElement('article');
    card.className = 'req-track-card req-track-card--' + sk;
    card.dataset.id = String(req.id);

    const remarks = (req.remarks || '').trim();
    const hasUpdate = remarks.length > 0 || sk !== 'pending';
    const updateBlock = hasUpdate
      ? `<div class="req-track-update" role="status">
          <span class="req-track-update__label"><i class="fas fa-bell" aria-hidden="true"></i> Barangay update</span>
          <p class="req-track-update__text">${
            remarks
              ? escapeHtml(remarks)
              : escapeHtml('Status: ' + statusLabel(req.status) + (req.updatedAt ? ' — last updated ' + formatDate(req.updatedAt) : ''))
          }</p>
        </div>`
      : `<p class="req-track-note">Your request is queued. You will see admin notes here when staff review it.</p>`;

    card.innerHTML = `
      <div class="req-track-card__head">
        <div class="req-track-card__info">
          <span class="req-track-card__id">${escapeHtml(req.requestId || 'REQ')}</span>
          <h3>${escapeHtml(req.documentType || 'Document request')}</h3>
          <p class="req-track-card__meta">${escapeHtml(requestTypeLabel(req.requestType))}</p>
        </div>
        <span class="req-track-status req-track-status--${sk}">${escapeHtml(statusLabel(req.status))}</span>
      </div>
      <div class="req-track-card__details">
        <div class="req-track-detail-row"><span>Submitted</span><span>${formatDate(req.submissionDate)}</span></div>
        ${
          req.updatedAt
            ? `<div class="req-track-detail-row"><span>Last updated</span><span>${formatDate(req.updatedAt)}</span></div>`
            : ''
        }
      </div>
      ${updateBlock}
      <button type="button" class="req-track-btn-view"><i class="fas fa-eye" aria-hidden="true"></i> View details</button>
    `;
    return card;
  }

  function openDetailsModal(req) {
    const modal = document.getElementById('requestDetailsModal');
    if (!modal) return;

    document.getElementById('residentReqDetailTitle').textContent =
      req.documentType || 'Request details';

    const set = function (id, val) {
      const el = document.getElementById(id);
      if (el) el.textContent = val || '—';
    };

    set('rdReqId', req.requestId);
    set('rdDocType', req.documentType);
    set('rdCategory', requestTypeLabel(req.requestType));
    set('rdStatus', statusLabel(req.status));
    set('rdSubmitted', formatDateTime(req.submissionDate));
    set('rdUpdated', req.updatedAt ? formatDateTime(req.updatedAt) : '—');
    set('rdRemarks', (req.remarks || '').trim() || 'No admin message yet.');
    set('rdNotes', (req.requestNotes || '').trim() || '—');

    const name = [req.firstName, req.middleName, req.lastName].filter(Boolean).join(' ');
    set('rdName', name || '—');
    set('rdEmail', req.email);
    set('rdMobile', req.mobile);
    set('rdAddress', req.address || req.full_address);

    const remarksBox = document.getElementById('rdRemarksWrap');
    if (remarksBox) {
      const hasRemarks = Boolean((req.remarks || '').trim());
      remarksBox.classList.toggle('req-detail-remarks--empty', !hasRemarks);
    }

    modal.classList.add('active');
    modal.removeAttribute('aria-hidden');
    document.body.classList.add('req-detail-modal-open');
  }

  function closeDetailsModal() {
    const modal = document.getElementById('requestDetailsModal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('req-detail-modal-open');
  }

  async function loadMyRequests() {
    const list = document.getElementById('myRequestList');
    if (!list || !Api) {
      setListState('myRequest', 'error');
      return;
    }

    const rid = residentId();
    if (!rid) {
      setListState('myRequest', 'guest');
      list.innerHTML = '';
      updateStats([]);
      return;
    }

    setListState('myRequest', 'loading');
    list.innerHTML = '';

    try {
      const tokens = residentMatchTokens();
      const data = await Api.fetchMyRequests(rid);
      const mine = Array.isArray(data)
        ? data.filter(function (req) {
            return requestBelongsToResident(req, tokens);
          })
        : [];
      if (mine.length === 0) {
        allMyRequests = [];
        setListState('myRequest', 'empty');
        updateStats([]);
        return;
      }
      updateStats(mine);
      allMyRequests = mine;
      renderFilteredRequests();
    } catch (e) {
      console.warn(e);
      allMyRequests = [];
      setListState('myRequest', 'error');
      updateStats([]);
    }
  }

  function initMyRequests() {
    document.getElementById('refreshMyRequests')?.addEventListener('click', loadMyRequests);
    document.getElementById('reqStatusFilter')?.addEventListener('change', renderFilteredRequests);

    document.getElementById('myRequestList')?.addEventListener('click', async function (e) {
      const btn = e.target.closest('.req-track-btn-view');
      if (!btn) return;
      const card = btn.closest('.req-track-card');
      const id = card?.dataset?.id;
      if (!id || !Api) return;
      try {
        const res = await fetch(Api.apiUrl('get', id));
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const req = await res.json();
        openDetailsModal(req);
      } catch (err) {
        console.warn(err);
        if (global.bcNotify) {
          global.bcNotify('error', 'Could not load request details.', { title: 'Error' });
        }
      }
    });

    document.getElementById('requestDetailsClose')?.addEventListener('click', closeDetailsModal);
    document.getElementById('requestDetailsModal')?.addEventListener('click', function (e) {
      if (e.target.id === 'requestDetailsModal') closeDetailsModal();
    });

    loadMyRequests();
  }

  global.BarangayConnectResidentMyRequests = {
    init: initMyRequests,
    load: loadMyRequests,
    statusLabel,
    requestTypeLabel,
  };
})(window);
