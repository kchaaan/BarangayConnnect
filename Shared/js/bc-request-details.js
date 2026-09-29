/**
 * Render full resident request payload in admin review modals (Personal Identification form).
 */
(function (global) {
  const SKIP_KEYS = new Set([
    'id',
    'requestId',
    'documentType',
    'submissionDate',
    'status',
    'requestType',
    'requestNotes',
    'remarks',
    'residentId',
    'residentIdLocal',
    'firstName',
    'middleName',
    'lastName',
    'suffix',
    'mobile',
    'email',
  ]);

  const FORM_SECTIONS = [
    {
      title: 'Name',
      fields: [
        { key: 'firstName', label: 'First name' },
        { key: 'middleName', label: 'Middle name' },
        { key: 'lastName', label: 'Last name' },
        { key: 'suffix', label: 'Suffix' },
      ],
    },
    {
      title: 'Personal information',
      fields: [
        { key: 'gender', label: 'Gender', format: 'title' },
        { key: 'birthdate', label: 'Date of birth', format: 'date' },
        { key: 'age', label: 'Age' },
        { key: 'placeOfBirth', label: 'Place of birth' },
        { key: 'civilStatus', label: 'Civil status', format: 'title' },
        { key: 'citizenship', label: 'Citizenship' },
        { key: 'religion', label: 'Religion' },
      ],
    },
    {
      title: 'Address',
      fields: [
        { key: 'street', label: 'Street' },
        { key: 'houseUnit', label: 'House no. / unit' },
        { key: 'blockLot', label: 'Block / lot' },
        { key: 'houseStreet', label: 'House / street line' },
        { key: 'barangay', label: 'Barangay' },
        { key: 'city', label: 'City / municipality' },
        { key: 'province', label: 'Province' },
        { key: 'region', label: 'Region' },
        { key: 'address', label: 'Complete address' },
      ],
    },
  ];

  const OTHER_REQUEST_LABELS = {
    purpose: 'Purpose',
    eventDate: 'Event date',
    eventTime: 'Event time',
    eventPlace: 'Event place',
    organizationName: 'Organization name',
    incidentDetails: 'Incident details',
    incidentDate: 'Incident date',
    incidentLocation: 'Incident location',
    partiesInvolved: 'Parties involved',
    businessName: 'Business name',
    businessLocation: 'Business location',
    dtiSecRegistration: 'DTI / SEC registration',
    constructionPurpose: 'Construction purpose',
    propertyAddress: 'Property address',
  };

  function escapeHtml(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fixText(str) {
    let s = String(str ?? '');
    s = s.replace(/\\\//g, '/');
    s = s.replace(/Para\?\?aque/gi, 'Parañaque');
    return s;
  }

  function hasValue(val) {
    if (val === null || val === undefined) return false;
    if (typeof val === 'number') return true;
    return String(val).trim() !== '';
  }

  function formatValue(key, val, format) {
    if (!hasValue(val)) return '';
    const v = fixText(val);
    if (format === 'date' || key.toLowerCase().includes('date')) {
      const d = new Date(v);
      if (!Number.isNaN(d.getTime())) {
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
      }
    }
    if (format === 'title' || key === 'gender' || key === 'civilStatus') {
      return v.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    }
    return v;
  }

  function tableRowHtml(label, value) {
    return (
      '<tr><th scope="row">' +
      escapeHtml(label) +
      '</th><td>' +
      escapeHtml(value) +
      '</td></tr>'
    );
  }

  function detailsTableHtml(rows) {
    return '<table class="bc-details-table"><tbody>' + rows.join('') + '</tbody></table>';
  }

  function buildFullName(req) {
    const parts = [req.firstName, req.middleName, req.lastName].filter(Boolean);
    let name = parts.join(' ') || '—';
    if (req.suffix) name += ', ' + req.suffix;
    return fixText(name);
  }

  function renderRequestFormDetails(req, containerEl) {
    if (!containerEl || !req) return;

    const htmlParts = [];
    let any = false;

    FORM_SECTIONS.forEach((section) => {
      const items = [];
      section.fields.forEach((f) => {
        const raw = req[f.key];
        if (!hasValue(raw)) return;
        const display = formatValue(f.key, raw, f.format);
        if (display) items.push(tableRowHtml(f.label, display));
      });
      if (!items.length) return;
      any = true;
      htmlParts.push(
        '<div class="bc-req-detail-group">' +
          '<h4 class="bc-req-detail-group__title">' +
          escapeHtml(section.title) +
          '</h4>' +
          detailsTableHtml(items) +
          '</div>'
      );
    });

    const extraRows = [];

    Object.keys(OTHER_REQUEST_LABELS).forEach((key) => {
      if (SKIP_KEYS.has(key) || hasValue(req[key]) === false) return;
      if (FORM_SECTIONS.some((s) => s.fields.some((f) => f.key === key))) return;
      any = true;
      extraRows.push(tableRowHtml(OTHER_REQUEST_LABELS[key], formatValue(key, req[key])));
    });

    const known = new Set([
      ...SKIP_KEYS,
      ...FORM_SECTIONS.flatMap((s) => s.fields.map((f) => f.key)),
      ...Object.keys(OTHER_REQUEST_LABELS),
    ]);

    Object.keys(req).forEach((key) => {
      if (known.has(key) || !hasValue(req[key])) return;
      any = true;
      const label = key
        .replace(/([A-Z])/g, ' $1')
        .replace(/^./, (c) => c.toUpperCase())
        .trim();
      extraRows.push(tableRowHtml(label, formatValue(key, req[key])));
    });

    if (extraRows.length) {
      htmlParts.push(
        '<div class="bc-req-detail-group">' +
          '<h4 class="bc-req-detail-group__title">Additional details</h4>' +
          detailsTableHtml(extraRows) +
          '</div>'
      );
    }

    if (!any) {
      containerEl.innerHTML =
        '<p class="muted">No form details were stored for this request.</p>';
      return;
    }

    containerEl.innerHTML = htmlParts.join('');
  }

  function populateRequestModal(req, ids) {
    ids = ids || {};
    const get = (id, fallback) => {
      const el = document.getElementById(id);
      if (el) el.textContent = fallback;
    };

    get(ids.requestId || 'modalRequestId', req.requestId || '—');
    get(ids.documentType || 'modalDocumentType', fixText(req.documentType || '—'));
    get(
      ids.dateSubmitted || 'modalDateSubmitted',
      req.submissionDate ? new Date(req.submissionDate).toLocaleString() : '—'
    );

    const statusEl = document.getElementById(ids.status || 'modalStatus');
    if (statusEl) {
      const st = (req.status || 'pending').replace(/_/g, ' ');
      statusEl.textContent = st.charAt(0).toUpperCase() + st.slice(1);
    }

    get(ids.phone || 'modalResidentPhone', fixText(req.mobile || '—'));
    get(ids.email || 'modalResidentEmail', fixText(req.email || '—'));
    get(
      ids.notes || 'residentNotes',
      fixText(req.requestNotes || '') || 'No additional notes provided.'
    );
  }

  function loadRequestFiles(requestId, containerId, filesUrl) {
    const container = document.getElementById(containerId || 'requirementList');
    if (!container) return Promise.resolve();

    container.innerHTML = '<p class="muted">Loading attachments…</p>';

    return fetch(filesUrl)
      .then((res) => res.json())
      .then((files) => {
        if (!files || !files.length) {
          container.innerHTML = '<p class="muted">No uploaded requirements.</p>';
          return;
        }

        if (files.length === 1) {
          container.insertAdjacentHTML(
            'afterbegin',
            '<p class="bc-req-files-hint muted">This request was saved with only one attachment. ' +
              'If the resident uploaded Valid ID, Proof of Residency, and Photo, ask them to submit a new request after refreshing the form page.</p>'
          );
        }

        container.innerHTML = '';
        files.forEach((file) => {
          const isImage = (file.fileType || '').startsWith('image/');
          const label = escapeHtml(file.label || file.fileName || 'Attachment');
          const fileName = escapeHtml(file.fileName || '');
          const fileType = escapeHtml(file.fileType || 'file');
          if (isImage) {
            container.insertAdjacentHTML(
              'beforeend',
              '<div class="requirement-item requirement-item--upload">' +
                '<strong class="requirement-item__label">' +
                label +
                '</strong>' +
                '<div class="requirement-item__preview">' +
                '<img src="data:' +
                escapeHtml(file.fileType) +
                ';base64,' +
                file.fileBase64 +
                '" alt="' +
                label +
                '" /></div></div>'
            );
          } else {
            container.insertAdjacentHTML(
              'beforeend',
              '<div class="requirement-item requirement-item--upload requirement-item--document">' +
                '<strong class="requirement-item__label">' +
                label +
                '</strong>' +
                '<div class="bc-req-file-row">' +
                '<p class="bc-req-file-meta" title="' +
                fileName +
                '">' +
                fileName +
                ' <span class="bc-req-file-type">(' +
                fileType +
                ')</span></p>' +
                '<a class="bc-req-file-download" download="' +
                fileName +
                '" href="data:' +
                escapeHtml(file.fileType) +
                ';base64,' +
                file.fileBase64 +
                '">Download file</a></div></div>'
            );
          }
        });
      })
      .catch(() => {
        container.innerHTML = '<p class="muted">Failed to load uploaded files.</p>';
      });
  }

  global.BarangayConnectRequestDetails = {
    renderRequestFormDetails,
    populateRequestModal,
    loadRequestFiles,
    buildFullName,
  };
})(window);
