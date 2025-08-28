/**
 * Cache Busting Utility
 * Prevents browser caching by adding timestamps to API requests
 */

export const addCacheBuster = (params = {}) => {
  return {
    ...params,
    _t: Date.now(),
    _v: process.env.REACT_APP_VERSION || '1.0.0'
  };
};

export const addCacheBusterToUrl = (url) => {
  const separator = url.includes('?') ? '&' : '?';
  return `${url}${separator}_t=${Date.now()}`;
};

export const clearLocalStorageCache = () => {
  try {
    // Clear any cached data
    const keys = Object.keys(localStorage);
    keys.forEach(key => {
      if (key.startsWith('assessor_') && !key.includes('token') && !key.includes('user')) {
        localStorage.removeItem(key);
      }
    });
  } catch (error) {
    console.warn('Failed to clear localStorage cache:', error);
  }
};

export const forceRefresh = () => {
  // Force a complete page refresh
  window.location.reload(true);
};
