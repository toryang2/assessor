import { useCallback } from 'react';

/**
 * Custom hook for cache busting functionality
 * Provides consistent cache busting across all components
 */
export const useCacheBuster = () => {
  /**
   * Add cache busting parameters to API requests
   * @param {Object} params - Original parameters
   * @param {boolean} forceRefresh - Whether to force a fresh request
   * @returns {Object} Parameters with cache busting
   */
  const addCacheBuster = useCallback((params = {}, forceRefresh = false) => {
    return {
      ...params,
      _t: forceRefresh ? Date.now() : Date.now(),
      _v: process.env.REACT_APP_VERSION || '1.0.0'
    };
  }, []);

  /**
   * Add cache busting to URL
   * @param {string} url - Original URL
   * @returns {string} URL with cache busting
   */
  const addCacheBusterToUrl = useCallback((url) => {
    const separator = url.includes('?') ? '&' : '?';
    return `${url}${separator}_t=${Date.now()}`;
  }, []);

  /**
   * Clear local storage cache
   */
  const clearLocalStorageCache = useCallback(() => {
    try {
      const keys = Object.keys(localStorage);
      keys.forEach(key => {
        if (key.startsWith('assessor_') && !key.includes('token') && !key.includes('user')) {
          localStorage.removeItem(key);
        }
      });
    } catch (error) {
      console.warn('Failed to clear localStorage cache:', error);
    }
  }, []);

  /**
   * Force a complete page refresh
   */
  const forceRefresh = useCallback(() => {
    window.location.reload(true);
  }, []);

  /**
   * Refresh data with cache busting
   * @param {Function} fetchFunction - Function to fetch data
   * @param {Array} dependencies - Dependencies for the fetch function
   */
  const refreshData = useCallback(async (fetchFunction, dependencies = []) => {
    try {
      await fetchFunction();
    } catch (error) {
      console.error('Error refreshing data:', error);
    }
  }, []);

  return {
    addCacheBuster,
    addCacheBusterToUrl,
    clearLocalStorageCache,
    forceRefresh,
    refreshData
  };
};
