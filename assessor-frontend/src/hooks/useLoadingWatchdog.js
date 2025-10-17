import { useEffect, useRef, useCallback } from 'react';

/**
 * Advanced loading watchdog hook with common timeout handlers
 * 
 * @param {Object} options - Configuration options
 * @param {boolean} options.isLoading - Current loading state
 * @param {boolean} options.isInitialLoad - Whether this is the initial load
 * @param {Function} options.setLoading - Function to set loading state
 * @param {Function} options.setInitialLoad - Function to set initial load state
 * @param {Function} options.setError - Function to set error state
 * @param {number} options.timeoutMs - Timeout duration in milliseconds (default: 20000)
 * @param {string} options.componentName - Name of the component for logging (optional)
 * @param {boolean} options.enabled - Whether the watchdog is enabled (default: true)
 * @param {string} options.timeoutMessage - Custom timeout message (optional)
 * @param {Array} options.dependencies - Additional dependencies to watch (optional)
 * 
 * @returns {Object} - Watchdog state and controls
 */
const useLoadingWatchdog = ({
  isLoading,
  isInitialLoad,
  setLoading,
  setInitialLoad,
  setError,
  timeoutMs = 20000,
  componentName = 'Component',
  enabled = true,
  timeoutMessage = 'Request timed out. Please check your connection and try again.',
  dependencies = []
}) => {
  const timeoutRef = useRef(null);
  const hasTimedOutRef = useRef(false);

  // Default timeout handler
  const defaultTimeoutHandler = useCallback(() => {
    try {
      console.warn(`${componentName}: initial load timed out. Showing fallback UI.`);
    } catch (_) {}
    
    hasTimedOutRef.current = true;
    
    // Update states
    if (setLoading) setLoading(false);
    if (setInitialLoad) setInitialLoad(false);
    if (setError) {
      setError(prev => prev || timeoutMessage);
    }
  }, [componentName, setLoading, setInitialLoad, setError, timeoutMessage]);

  useEffect(() => {
    // Clear any existing timeout
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
      timeoutRef.current = null;
    }

    // Reset timeout flag
    hasTimedOutRef.current = false;

    // Only set up watchdog if enabled and conditions are met
    if (enabled && isInitialLoad && isLoading) {
      timeoutRef.current = setTimeout(() => {
        defaultTimeoutHandler();
      }, timeoutMs);
    }

    // Cleanup function
    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
        timeoutRef.current = null;
      }
    };
  }, [isLoading, isInitialLoad, enabled, timeoutMs, defaultTimeoutHandler, ...dependencies]);

  // Manual timeout trigger (useful for testing or manual intervention)
  const triggerTimeout = useCallback(() => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
      timeoutRef.current = null;
    }
    defaultTimeoutHandler();
  }, [defaultTimeoutHandler]);

  // Reset watchdog (useful when starting a new load)
  const resetWatchdog = useCallback(() => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
      timeoutRef.current = null;
    }
    hasTimedOutRef.current = false;
  }, []);

  // Check if timeout has occurred
  const hasTimedOut = hasTimedOutRef.current;

  return {
    hasTimedOut,
    triggerTimeout,
    resetWatchdog,
    isActive: enabled && isInitialLoad && isLoading && !hasTimedOut
  };
};

export default useLoadingWatchdog;

