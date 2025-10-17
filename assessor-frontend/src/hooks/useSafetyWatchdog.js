import { useEffect, useRef } from 'react';

/**
 * Universal safety watchdog hook to prevent infinite loading states
 * 
 * @param {Object} options - Configuration options
 * @param {boolean} options.isLoading - Current loading state
 * @param {boolean} options.isInitialLoad - Whether this is the initial load
 * @param {Function} options.onTimeout - Callback when timeout occurs
 * @param {number} options.timeoutMs - Timeout duration in milliseconds (default: 20000)
 * @param {string} options.componentName - Name of the component for logging (optional)
 * @param {boolean} options.enabled - Whether the watchdog is enabled (default: true)
 * @param {Array} options.dependencies - Additional dependencies to watch (optional)
 * 
 * @returns {Object} - Watchdog state and controls
 */
const useSafetyWatchdog = ({
  isLoading,
  isInitialLoad,
  onTimeout,
  timeoutMs = 20000,
  componentName = 'Component',
  enabled = true,
  dependencies = []
}) => {
  const timeoutRef = useRef(null);
  const hasTimedOutRef = useRef(false);

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
        try {
          console.warn(`${componentName}: initial load timed out. Showing fallback UI.`);
        } catch (_) {}
        
        hasTimedOutRef.current = true;
        
        // Call the timeout handler
        if (onTimeout && typeof onTimeout === 'function') {
          onTimeout();
        }
      }, timeoutMs);
    }

    // Cleanup function
    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
        timeoutRef.current = null;
      }
    };
  }, [isLoading, isInitialLoad, enabled, timeoutMs, componentName, ...dependencies]);

  // Manual timeout trigger (useful for testing or manual intervention)
  const triggerTimeout = () => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
      timeoutRef.current = null;
    }
    hasTimedOutRef.current = true;
    if (onTimeout && typeof onTimeout === 'function') {
      onTimeout();
    }
  };

  // Check if timeout has occurred
  const hasTimedOut = hasTimedOutRef.current;

  return {
    hasTimedOut,
    triggerTimeout,
    isActive: enabled && isInitialLoad && isLoading && !hasTimedOut
  };
};

export default useSafetyWatchdog;

