/**
 * Daily Resolved / Cancelled counters for admin A_Reports.html.
 * Resets at local midnight; increments only on In Progress → Resolved/Cancelled.
 */
(function (global) {
  const STORAGE_KEY = 'bc_admin_reports_daily_stats';

  function todayKey() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function normalizeStatus(status) {
    return String(status || '').trim().toLowerCase();
  }

  function loadDailyStats() {
    try {
      const raw = global.localStorage.getItem(STORAGE_KEY);
      const parsed = raw ? JSON.parse(raw) : null;
      if (parsed && parsed.date === todayKey()) {
        return {
          date: parsed.date,
          resolved: Number(parsed.resolved) || 0,
          cancelled: Number(parsed.cancelled) || 0,
        };
      }
    } catch (e) {
      /* ignore */
    }
    return { date: todayKey(), resolved: 0, cancelled: 0 };
  }

  function saveDailyStats(stats) {
    try {
      global.localStorage.setItem(STORAGE_KEY, JSON.stringify(stats));
    } catch (e) {
      /* ignore */
    }
  }

  function recordStatusTransition(oldStatus, newStatus) {
    const oldKey = normalizeStatus(oldStatus);
    const newKey = normalizeStatus(newStatus);
    if (oldKey !== 'in progress') return loadDailyStats();

    let stats = loadDailyStats();
    if (stats.date !== todayKey()) {
      stats = { date: todayKey(), resolved: 0, cancelled: 0 };
    }

    if (newKey === 'resolved') {
      stats.resolved += 1;
    } else if (newKey === 'cancelled') {
      stats.cancelled += 1;
    } else {
      return stats;
    }

    saveDailyStats(stats);
    return stats;
  }

  global.BarangayConnectReportsDailyStats = {
    loadDailyStats,
    saveDailyStats,
    recordStatusTransition,
    todayKey,
  };
})(window);
