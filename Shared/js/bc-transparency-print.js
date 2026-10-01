/* Printable transparency project/program report. The browser print dialog can
 * print the report or save it as a real PDF without requiring a PDF library. */
(function (global) {
  'use strict';

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function peso(value) {
    return '₱ ' + (Number(value) || 0).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function formatDate(value) {
    if (!value) return 'Not specified';
    const date = new Date(value + (String(value).length === 10 ? 'T00:00:00' : ''));
    return Number.isNaN(date.getTime()) ? 'Not specified' : date.toLocaleDateString('en-PH', {
      year: 'numeric', month: 'long', day: 'numeric'
    });
  }

  function printItem(item) {
    if (!item) return false;

    const printWindow = global.open('', '_blank', 'popup,width=900,height=720');
    if (!printWindow) {
      global.alert('Allow pop-ups for this site to print or save the PDF report.');
      return false;
    }

    const budget = Number(item.budget) || 0;
    const spent = Number(item.spent) || 0;
    const remaining = budget - spent;
    const percent = budget > 0 ? Math.min(100, Math.round((spent / budget) * 100)) : 0;
    const sealUrl = new URL('../../Shared/images/barangay-tambo-seal.jpg', global.location.href).href;
    const generatedAt = new Date().toLocaleString('en-PH');

    printWindow.document.open();
    printWindow.document.write(`<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title></title>
<style>
  @page { size: A4; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 16mm; color: #243329; font: 11pt Arial, sans-serif; }
  header { border-bottom: 3px solid #5f7f58; padding-bottom: 12px; margin-bottom: 24px; }
  .brand-row { display: flex; align-items: center; gap: 10px; }
  .seal { width: 48px; height: 48px; border: 1px solid #dfe9da; border-radius: 50%; object-fit: cover; }
  .brand { color: #5f7f58; font-size: 10pt; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
  .municipality { margin-top: 3px; color: #5f6d5b; font-size: 9pt; }
  .printed-at { margin-left: auto; color: #5f6d5b; font-size: 9pt; text-align: right; white-space: nowrap; }
  h1 { margin: 8px 0 4px; font-size: 22pt; }
  .muted { color: #5f6d5b; }
  .meta { margin: 0; font-size: 9.5pt; }
  h2 { margin: 24px 0 10px; padding-bottom: 6px; border-bottom: 1px solid #dfe9da; font-size: 13pt; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .field { padding: 11px; border: 1px solid #dfe9da; border-radius: 6px; break-inside: avoid; }
  .label { display: block; margin-bottom: 5px; color: #5f6d5b; font-size: 8pt; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
  .value { font-size: 11pt; font-weight: 600; }
  .description { white-space: pre-wrap; line-height: 1.55; }
  .progress { height: 9px; margin-top: 7px; background: #e2e8e2; border-radius: 9px; overflow: hidden; }
  .progress span { display: block; height: 100%; width: ${percent}%; background: #5f7f58; }
  footer { margin-top: 32px; padding-top: 10px; border-top: 1px solid #dfe9da; color: #5f6d5b; font-size: 9pt; }
  @media screen { body { max-width: 800px; margin: 32px auto; padding: 24px; } }
</style></head><body>
<header><div class="brand-row"><img class="seal" src="${escapeHtml(sealUrl)}" alt="Barangay Tambo seal"><div><div class="brand">BarangayConnect</div><div class="municipality">Barangay Tambo, Parañaque City</div></div><time class="printed-at">Printed<br>${escapeHtml(generatedAt)}</time></div>
<h1>${escapeHtml(item.name)}</h1>
<p class="meta muted">${escapeHtml(item.type || 'Project')}</p></header>
<h2>Project details</h2><div class="grid">
  <div class="field"><span class="label">Type</span><span class="value">${escapeHtml(item.type || 'Project')}</span></div>
  <div class="field"><span class="label">Status</span><span class="value">${escapeHtml(item.status || 'Not specified')}</span></div>
  <div class="field"><span class="label">Start date</span><span class="value">${escapeHtml(formatDate(item.startDate))}</span></div>
  <div class="field"><span class="label">End date</span><span class="value">${escapeHtml(formatDate(item.endDate))}</span></div>
</div>
<h2>Budget and progress</h2><div class="grid">
  <div class="field"><span class="label">Approved budget</span><span class="value">${escapeHtml(peso(budget))}</span></div>
  <div class="field"><span class="label">Amount spent</span><span class="value">${escapeHtml(peso(spent))}</span></div>
  <div class="field"><span class="label">Remaining balance</span><span class="value">${escapeHtml(peso(remaining))}</span></div>
  <div class="field"><span class="label">Budget utilization</span><span class="value">${percent}%</span><div class="progress"><span></span></div></div>
</div>
<h2>Description</h2><div class="field description">${escapeHtml(item.description || 'No description provided.')}</div>
<footer>BarangayConnect transparency record · Please verify details with the Barangay Tambo office.</footer>
</body></html>`);
    printWindow.document.close();
    const openPrintDialog = function () {
      global.setTimeout(function () {
        if (printWindow.closed) return;
        printWindow.focus();
        printWindow.print();
      }, 250);
    };
    if (printWindow.document.readyState === 'complete') openPrintDialog();
    else printWindow.addEventListener('load', openPrintDialog, { once: true });
    return true;
  }

  global.BCTransparencyPrint = { printItem };
})(window);
