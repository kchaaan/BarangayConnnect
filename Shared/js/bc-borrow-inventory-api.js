/**
 * Borrow & Inventory PHP APIs.
 */
(function (global) {
  const XAMPP_HTTP_BASE =
    'http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/';

  function isFileProtocol() {
    return global.location.protocol === 'file:';
  }

  function phpFile(file, fromHref) {
    if (isFileProtocol()) {
      return XAMPP_HTTP_BASE + 'FrontPage/php/' + file;
    }
    return new URL('../../FrontPage/php/' + file, fromHref || global.location.href).href;
  }

  function buildUrl(file, params, fromHref) {
    const u = new URL(phpFile(file, fromHref));
    Object.keys(params || {}).forEach((key) => {
      const val = params[key];
      if (val != null && val !== '') {
        u.searchParams.set(key, String(val));
      }
    });
    return u.href;
  }

  async function fetchJson(url, options) {
    const res = await fetch(url, options);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const err = new Error(data.error || res.statusText || 'Request failed');
      err.status = res.status;
      err.data = data;
      throw err;
    }
    if (data && data.success === false) {
      const err = new Error(data.error || 'Request failed');
      err.data = data;
      throw err;
    }
    return data;
  }

  const inventory = {
    listUrl(opts, fromHref) {
      return buildUrl('inventory_api.php', { action: 'list', ...opts }, fromHref);
    },
    categoriesUrl(fromHref) {
      return buildUrl('inventory_api.php', { action: 'categories' }, fromHref);
    },
    statsUrl(fromHref) {
      return buildUrl('inventory_api.php', { action: 'stats' }, fromHref);
    },
    getUrl(id, fromHref) {
      return buildUrl('inventory_api.php', { action: 'get', id }, fromHref);
    },
    list(opts, fromHref) {
      return fetchJson(inventory.listUrl(opts, fromHref));
    },
    categories(fromHref) {
      return fetchJson(inventory.categoriesUrl(fromHref));
    },
    stats(fromHref) {
      return fetchJson(inventory.statsUrl(fromHref));
    },
    get(id, fromHref) {
      return fetchJson(inventory.getUrl(id, fromHref));
    },
    create(body, fromHref) {
      return fetchJson(buildUrl('inventory_api.php', { action: 'create' }, fromHref), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
    },
    update(id, body, fromHref) {
      return fetchJson(buildUrl('inventory_api.php', { action: 'update', id }, fromHref), {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
    },
    createCategory(body, fromHref) {
      return fetchJson(buildUrl('inventory_api.php', { action: 'category' }, fromHref), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
    },
    delete(id, fromHref) {
      return fetchJson(buildUrl('inventory_api.php', { action: 'delete', id }, fromHref), {
        method: 'DELETE',
      });
    },
  };

  const borrow = {
    listUrl(opts, fromHref) {
      return buildUrl('borrow_api.php', { action: 'list', ...opts }, fromHref);
    },
    myUrl(residentId, fromHref) {
      return buildUrl('borrow_api.php', { action: 'my', resident_id: residentId }, fromHref);
    },
    statsUrl(fromHref) {
      return buildUrl('borrow_api.php', { action: 'stats' }, fromHref);
    },
    monitoringUrl(fromHref) {
      return buildUrl('borrow_api.php', { action: 'monitoring' }, fromHref);
    },
    historyUrl(fromHref) {
      return buildUrl('borrow_api.php', { action: 'history' }, fromHref);
    },
    getUrl(id, fromHref) {
      return buildUrl('borrow_api.php', { action: 'get', id }, fromHref);
    },
    list(opts, fromHref) {
      return fetchJson(borrow.listUrl(opts, fromHref));
    },
    my(residentId, fromHref) {
      return fetchJson(borrow.myUrl(residentId, fromHref));
    },
    stats(fromHref) {
      return fetchJson(borrow.statsUrl(fromHref));
    },
    monitoring(fromHref) {
      return fetchJson(borrow.monitoringUrl(fromHref));
    },
    history(fromHref) {
      return fetchJson(borrow.historyUrl(fromHref));
    },
    submit(body, fromHref) {
      return fetchJson(buildUrl('borrow_api.php', { action: 'submit' }, fromHref), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
    },
    updateStatus(id, body, fromHref) {
      return fetchJson(buildUrl('borrow_api.php', { action: 'status', id }, fromHref), {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
    },
  };

  global.BarangayConnectBorrowInventoryApi = {
    XAMPP_HTTP_BASE,
    isFileProtocol,
    inventory,
    borrow,
    fetchJson,
  };
})(window);
