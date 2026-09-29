// Activity Log Utility Functions
// This file provides centralized activity logging functionality

/**
 * Record an activity log entry
 * @param {string} activityType - Type of activity (LOGIN, LOGOUT, FAILED_LOGIN, PASSWORD_CHANGE, PROFILE_UPDATE)
 * @param {string} userType - Type of user (RESIDENT, ADMIN)
 * @param {string} userId - User identifier (email, username, or residentId)
 * @param {string} userName - User's display name
 * @param {object} details - Additional details about the activity (optional)
 */
function recordActivity(activityType, userType, userId, userName, details = {}) {
  const activityLog = JSON.parse(localStorage.getItem('activityLog')) || [];
  
  const activity = {
    id: Date.now().toString() + Math.random().toString(36).substr(2, 9),
    timestamp: new Date().toISOString(),
    activityType: activityType,
    userType: userType,
    userId: userId,
    userName: userName,
    details: details
  };
  
  activityLog.push(activity);
  
  // Keep only the last 1000 entries to prevent localStorage from getting too large
  if (activityLog.length > 1000) {
    activityLog.shift();
  }
  
  localStorage.setItem('activityLog', JSON.stringify(activityLog));
}

/**
 * Get activity logs for a specific user
 * @param {string} userId - User identifier
 * @param {number} limit - Maximum number of entries to return (default: 100)
 * @returns {Array} Array of activity log entries
 */
function getUserActivityLog(userId, limit = 100) {
  const activityLog = JSON.parse(localStorage.getItem('activityLog')) || [];
  return activityLog
    .filter(activity => activity.userId === userId)
    .sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp))
    .slice(0, limit);
}

/**
 * Get all activity logs
 * @param {number} limit - Maximum number of entries to return (default: 100)
 * @returns {Array} Array of activity log entries
 */
function getAllActivityLog(limit = 100) {
  const activityLog = JSON.parse(localStorage.getItem('activityLog')) || [];
  return activityLog
    .sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp))
    .slice(0, limit);
}

/**
 * Get activity logs filtered by type
 * @param {string} activityType - Type of activity to filter by
 * @param {number} limit - Maximum number of entries to return (default: 100)
 * @returns {Array} Array of activity log entries
 */
function getActivityLogByType(activityType, limit = 100) {
  const activityLog = JSON.parse(localStorage.getItem('activityLog')) || [];
  return activityLog
    .filter(activity => activity.activityType === activityType)
    .sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp))
    .slice(0, limit);
}

/**
 * Format timestamp for display (relative time)
 * @param {string} isoString - ISO timestamp string
 * @returns {string} Formatted relative time string
 */
function formatActivityTimestamp(isoString) {
  const date = new Date(isoString);
  const now = new Date();
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);
  
  if (diffMins < 1) {
    return 'Just now';
  } else if (diffMins < 60) {
    return `${diffMins} minute${diffMins > 1 ? 's' : ''} ago`;
  } else if (diffHours < 24) {
    return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
  } else if (diffDays < 7) {
    return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
  } else {
    return date.toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  }
}

/**
 * Format full timestamp for display
 * @param {string} isoString - ISO timestamp string
 * @returns {string} Formatted full date and time string
 */
function formatFullTimestamp(isoString) {
  const date = new Date(isoString);
  return date.toLocaleString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: true
  });
}

/**
 * Get activity type display name and icon
 * @param {string} activityType - Type of activity
 * @returns {object} Object with display name and icon class
 */
function getActivityTypeInfo(activityType) {
  const activityTypes = {
    'LOGIN': { name: 'Login', icon: 'fa-sign-in-alt', color: '#27ae60' },
    'LOGOUT': { name: 'Logout', icon: 'fa-sign-out-alt', color: '#e74c3c' },
    'FAILED_LOGIN': { name: 'Failed Login', icon: 'fa-times-circle', color: '#e67e22' },
    'PASSWORD_CHANGE': { name: 'Password Change', icon: 'fa-key', color: '#3498db' },
    'PROFILE_UPDATE': { name: 'Profile Update', icon: 'fa-user-edit', color: '#9b59b6' }
  };
  
  return activityTypes[activityType] || { name: activityType, icon: 'fa-circle', color: '#95a5a6' };
}

