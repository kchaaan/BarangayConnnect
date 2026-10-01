/* Resident-controlled QR export. The bundled encoder builds the QR and all
 * personal data stays in this browser; no external QR service is contacted. */
(function (global) {
  'use strict';

  function value(id) {
    const input = global.document.getElementById(id);
    return input ? String(input.value || '').trim() : '';
  }

  function escapeHtml(valueToEscape) {
    return String(valueToEscape == null ? '' : valueToEscape)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function safeFilename(valueToClean) {
    return String(valueToClean || 'resident-information')
      .replace(/[\\/:*?"<>|\u0000-\u001f]/g, '-')
      .replace(/[^a-zA-Z0-9_-]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'resident-information';
  }

  function collectInformation() {
    const fullName = [value('firstName'), value('middleName'), value('lastName'), value('suffix')]
      .filter(Boolean).join(' ');
    const address = [value('street'), value('barangay'), value('city'), value('province'), value('postalCode')]
      .filter(Boolean).join(', ');
    return {
      residentId: global.localStorage.getItem('residentCode') || global.currentResident?.residentId || '',
      fullName,
      dateOfBirth: value('dateOfBirth'),
      age: value('age'),
      gender: value('gender'),
      placeOfBirth: value('placeOfBirth'),
      civilStatus: value('civilStatus'),
      citizenship: value('citizenship'),
      religion: value('religion'),
      address,
      mobile: value('mobile'),
      email: value('email'),
      emergencyContactName: value('emergencyName'),
      emergencyContactNumber: value('emergencyNumber'),
      emergencyContactRelationship: value('emergencyRelationship'),
    };
  }

  function qrText(info) {
    const printUrl = new URL('ResidentPrint.html', global.location.href);
    printUrl.hash = '';
    printUrl.search = '';
    printUrl.searchParams.set('resident', info.residentId);
    return printUrl.href;
  }

  function qrImageUrl(container) {
    const canvas = container.querySelector('canvas');
    if (canvas) return canvas.toDataURL('image/png');
    const image = container.querySelector('img');
    return image ? image.src : '';
  }

  function loadQrLibrary() {
    if (typeof global.QRCode === 'function') return Promise.resolve();
    if (global._bcQrLoadPromise) return global._bcQrLoadPromise;
    global._bcQrLoadPromise = new Promise((resolve, reject) => {
      const script = global.document.createElement('script');
      script.src = new URL('../../Shared/js/vendor/qrcode.min.js?v=20261001-local5', global.location.href).href;
      script.async = true;
      script.onload = () => typeof global.QRCode === 'function' ? resolve() : reject(new Error('QR library unavailable'));
      script.onerror = () => reject(new Error('QR library failed to load'));
      global.document.head.appendChild(script);
    }).catch((error) => {
      global._bcQrLoadPromise = null;
      throw error;
    });
    return global._bcQrLoadPromise;
  }

  function printInformation(info, qrDataUrl) {
    const printWindow = global.open('', '_blank', 'popup,width=900,height=900');
    if (!printWindow) {
      global.alert('Allow pop-ups for this site to print or save the resident PDF.');
      return;
    }

    const sealUrl = new URL('../../Shared/images/barangay-tambo-seal.jpg', global.location.href).href;
    const fields = [
      ['Resident ID', info.residentId], ['Full name', info.fullName], ['Date of birth', info.dateOfBirth],
      ['Age', info.age], ['Gender', info.gender], ['Place of birth', info.placeOfBirth],
      ['Civil status', info.civilStatus], ['Citizenship', info.citizenship], ['Religion', info.religion],
      ['Address', info.address], ['Mobile number', info.mobile], ['Email', info.email],
      ['Emergency contact', info.emergencyContactName], ['Emergency number', info.emergencyContactNumber],
      ['Relationship', info.emergencyContactRelationship],
    ];
    const rows = fields.map(([label, fieldValue]) =>
      `<div class="field"><span>${escapeHtml(label)}</span><strong>${escapeHtml(fieldValue || 'Not provided')}</strong></div>`
    ).join('');
    const printedAt = new Date().toLocaleString('en-PH');

    printWindow.document.open();
    printWindow.document.write(`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title></title>
<style>
  @page { size: A4; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 14mm 16mm; color: #243329; font: 10.5pt Arial,sans-serif; }
  header { display: flex; align-items: center; gap: 10px; padding-bottom: 10px; border-bottom: 3px solid #5f7f58; }
  .seal { width: 48px; height: 48px; border: 1px solid #dfe9da; border-radius: 50%; object-fit: cover; }
  .brand { color: #5f7f58; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
  .place { margin-top: 3px; color: #5f6d5b; font-size: 9pt; }
  time { margin-left: auto; color: #5f6d5b; font-size: 8.5pt; text-align: right; }
  h1 { margin: 18px 0 5px; color: #243329; font-size: 19pt; }
  .lead { margin: 0 0 14px; color: #5f6d5b; font-size: 9pt; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; }
  .field { min-height: 48px; padding: 7px 9px; border: 1px solid #dfe9da; border-radius: 5px; break-inside: avoid; }
  .field span { display: block; margin-bottom: 4px; color: #5f6d5b; font-size: 7.5pt; font-weight: 700; text-transform: uppercase; }
  .field strong { display: block; font-size: 9.5pt; overflow-wrap: anywhere; }
  .qr-section { margin-top: 14px; padding-top: 9px; border-top: 1px solid #dfe9da; text-align: center; break-inside: avoid; }
  .qr-section h2 { margin: 0 0 5px; font-size: 11pt; }
  .qr-section p { margin: 0 0 6px; color: #5f6d5b; font-size: 8pt; }
  .qr-section img { display: block; width: 220px; height: 220px; margin: 0 auto; image-rendering: pixelated; }
  .privacy { margin-top: 5px !important; font-weight: 700; }
  @media screen { body { max-width: 800px; margin: 20px auto; padding: 24px; box-shadow: 0 1px 12px #0002; } }
</style></head><body>
<header><img class="seal" src="${escapeHtml(sealUrl)}" alt="Barangay Tambo seal"><div><div class="brand">BarangayConnect</div><div class="place">Barangay Tambo, Parañaque City</div></div><time>Printed<br>${escapeHtml(printedAt)}</time></header>
<h1>Resident Information</h1><p class="lead">Personal and emergency-contact details provided by the resident.</p>
<div class="grid">${rows}</div>
<section class="qr-section"><h2>Resident Information QR Code</h2><p>Scan to read the details listed above.</p><img src="${escapeHtml(qrDataUrl)}" alt="Resident information QR code"><p class="privacy">Contains personal data. Share only with trusted barangay staff.</p></section>
</body></html>`);
    printWindow.document.close();
    const showPrintDialog = function () {
      global.setTimeout(function () {
        if (printWindow.closed) return;
        printWindow.focus();
        printWindow.print();
      }, 250);
    };
    if (printWindow.document.readyState === 'complete') showPrintDialog();
    else printWindow.addEventListener('load', showPrintDialog, { once: true });
  }

  function init() {
    const generateButton = global.document.getElementById('residentQrGenerate');
    const output = global.document.getElementById('residentQrResult');
    const container = global.document.getElementById('residentQrCode');
    const status = global.document.getElementById('residentQrStatus');
    if (!generateButton || !output || !container) return;

    let currentInfo = null;
    generateButton.addEventListener('click', async function () {
      const info = collectInformation();
      if (!info.residentId || !info.fullName) {
        global.alert('Load your resident profile before generating the QR code.');
        return;
      }
      currentInfo = info;
      output.hidden = false;
      container.replaceChildren();
      generateButton.disabled = true;
      if (status) { status.hidden = false; status.textContent = 'Generating QR code…'; }
      try {
        await loadQrLibrary();
        new global.QRCode(container, {
          text: qrText(info),
          width: 300,
          height: 300,
          minimumTypeNumber: 1,
          colorDark: '#173b24',
          colorLight: '#ffffff',
          correctLevel: global.QRCode.CorrectLevel?.L ?? 1,
        });
        if (!container.querySelector('canvas, svg, img, table')) {
          throw new Error('QR encoder did not create an image.');
        }
        if (status) status.textContent = 'QR ready. Sign-in is required; a phone needs the site hosted at a reachable HTTPS address (localhost works only on this computer).';
      } catch (error) {
        console.error('Resident QR generation failed:', error);
        container.replaceChildren();
        if (status) status.textContent = 'QR generation failed: ' + (error && error.message ? error.message : 'unknown error');
      } finally {
        generateButton.disabled = false;
      }
    });

    global.document.getElementById('residentQrDownload')?.addEventListener('click', function () {
      const dataUrl = qrImageUrl(container);
      if (!dataUrl) {
        global.alert('Generate the QR code first.');
        return;
      }
      const link = global.document.createElement('a');
      link.href = dataUrl;
      link.download = safeFilename(currentInfo?.residentId) + '-resident-qr.png';
      link.click();
    });

    global.document.getElementById('residentQrPrint')?.addEventListener('click', function () {
      const dataUrl = qrImageUrl(container);
      if (!currentInfo || !dataUrl) {
        global.alert('Generate the QR code first.');
        return;
      }
      printInformation(currentInfo, dataUrl);
    });
  }

  if (global.document.readyState === 'loading') global.document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})(window);
