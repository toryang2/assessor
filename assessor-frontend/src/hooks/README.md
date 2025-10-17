# Universal Safety Watchdog Hook

This directory contains a universal safety watchdog system to prevent infinite loading states across all React components in the application.

## Overview

The safety watchdog system provides protection against infinite loading states that can occur when:
- Network requests hang indefinitely
- Backend services become unresponsive
- API endpoints fail silently
- Authentication processes get stuck

## Available Hooks

### 1. `useLoadingWatchdog`

The main hook for preventing infinite loading states in data-fetching components.

```javascript
import useLoadingWatchdog from '../../hooks/useLoadingWatchdog';

const MyComponent = () => {
  const [loading, setLoading] = useState(true);
  const [initialLoad, setInitialLoad] = useState(true);
  const [error, setError] = useState('');

  // Universal safety watchdog
  useLoadingWatchdog({
    isLoading: loading,
    isInitialLoad: initialLoad,
    setLoading,
    setInitialLoad,
    setError,
    componentName: 'MyComponent',
    timeoutMs: 20000,
    timeoutMessage: 'Data request timed out. Please check your connection and try again.',
    enabled: true
  });

  // ... rest of component
};
```

### 2. `useSafetyWatchdog`

A more flexible hook for custom timeout handling.

```javascript
import useSafetyWatchdog from '../../hooks/useSafetyWatchdog';

const MyComponent = () => {
  const [loading, setLoading] = useState(true);
  const [initialLoad, setInitialLoad] = useState(true);

  const handleTimeout = () => {
    setLoading(false);
    setInitialLoad(false);
    // Custom timeout logic here
  };

  useSafetyWatchdog({
    isLoading: loading,
    isInitialLoad: initialLoad,
    onTimeout: handleTimeout,
    timeoutMs: 20000,
    componentName: 'MyComponent',
    enabled: true
  });

  // ... rest of component
};
```

## Usage Examples

### PropertyTable Component

```javascript
// Before (manual implementation)
useEffect(() => {
  if (initialLoad && loading) {
    const timeoutId = setTimeout(() => {
      console.warn('PropertyTable: initial load timed out. Showing fallback UI.');
      setLoading(false);
      setInitialLoad(false);
      setError(prev => prev || 'Request timed out. Please check your connection and try again.');
    }, 20000);
    return () => clearTimeout(timeoutId);
  }
}, [initialLoad, loading]);

// After (universal hook)
useLoadingWatchdog({
  isLoading: loading,
  isInitialLoad: initialLoad,
  setLoading,
  setInitialLoad,
  setError,
  componentName: 'PropertyTable',
  timeoutMs: 20000,
  timeoutMessage: 'Property data request timed out. Please check your connection and try again.',
  enabled: true
});
```

### Dashboard Component

```javascript
useLoadingWatchdog({
  isLoading: loading,
  isInitialLoad: !dashboardData && loading,
  setLoading,
  setError: () => setToast({ 
    open: true, 
    message: 'Dashboard data request timed out. Please check your connection and try again.', 
    severity: 'error' 
  }),
  componentName: 'Dashboard',
  timeoutMs: 15000,
  timeoutMessage: 'Dashboard data timed out. Please check your connection and try again.',
  enabled: true
});
```

### RequestsTable Component

```javascript
useLoadingWatchdog({
  isLoading: loading,
  isInitialLoad: initialLoad,
  setLoading,
  setInitialLoad,
  setError,
  componentName: 'RequestsTable',
  timeoutMs: 20000,
  timeoutMessage: 'Request data timed out. Please check your connection and try again.',
  enabled: true
});
```

## Configuration Options

### useLoadingWatchdog Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `isLoading` | boolean | - | Current loading state |
| `isInitialLoad` | boolean | - | Whether this is the initial load |
| `setLoading` | function | - | Function to set loading state |
| `setInitialLoad` | function | - | Function to set initial load state |
| `setError` | function | - | Function to set error state |
| `timeoutMs` | number | 20000 | Timeout duration in milliseconds |
| `componentName` | string | 'Component' | Name for logging purposes |
| `enabled` | boolean | true | Whether watchdog is enabled |
| `timeoutMessage` | string | - | Custom timeout message |
| `dependencies` | array | [] | Additional dependencies to watch |

### useSafetyWatchdog Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `isLoading` | boolean | - | Current loading state |
| `isInitialLoad` | boolean | - | Whether this is the initial load |
| `onTimeout` | function | - | Custom timeout handler |
| `timeoutMs` | number | 20000 | Timeout duration in milliseconds |
| `componentName` | string | 'Component' | Name for logging purposes |
| `enabled` | boolean | true | Whether watchdog is enabled |
| `dependencies` | array | [] | Additional dependencies to watch |

## Return Values

### useLoadingWatchdog Returns

```javascript
const {
  hasTimedOut,      // boolean - whether timeout has occurred
  triggerTimeout,   // function - manually trigger timeout
  resetWatchdog,    // function - reset watchdog state
  isActive          // boolean - whether watchdog is currently active
} = useLoadingWatchdog({...});
```

### useSafetyWatchdog Returns

```javascript
const {
  hasTimedOut,      // boolean - whether timeout has occurred
  triggerTimeout,   // function - manually trigger timeout
  isActive          // boolean - whether watchdog is currently active
} = useSafetyWatchdog({...});
```

## Best Practices

1. **Always use the watchdog for initial data loading**
2. **Set appropriate timeout values based on expected response times**
3. **Provide meaningful error messages to users**
4. **Use component names for better debugging**
5. **Consider different timeout values for different types of operations**

## Timeout Recommendations

| Component Type | Recommended Timeout | Reason |
|----------------|-------------------|---------|
| Dashboard | 15s | Quick overview data |
| Data Tables | 20s | Large datasets |
| File Uploads | 30s | Large files take time |
| Authentication | 10s | Should be fast |
| Export Operations | 30s | Can be resource intensive |

## Migration Guide

### Step 1: Import the Hook

```javascript
import useLoadingWatchdog from '../../hooks/useLoadingWatchdog';
```

### Step 2: Replace Manual Timeout Logic

Remove existing manual timeout implementations:

```javascript
// Remove this
useEffect(() => {
  if (initialLoad && loading) {
    const timeoutId = setTimeout(() => {
      // manual timeout logic
    }, 20000);
    return () => clearTimeout(timeoutId);
  }
}, [initialLoad, loading]);
```

### Step 3: Add Universal Watchdog

```javascript
// Add this
useLoadingWatchdog({
  isLoading: loading,
  isInitialLoad: initialLoad,
  setLoading,
  setInitialLoad,
  setError,
  componentName: 'YourComponentName',
  timeoutMs: 20000,
  timeoutMessage: 'Your custom timeout message',
  enabled: true
});
```

## Troubleshooting

### Watchdog Not Triggering

1. Check that `isLoading` and `isInitialLoad` are both true
2. Verify that `enabled` is true
3. Ensure the component is actually in a loading state

### Multiple Timeouts

1. Make sure to clear existing timeouts when starting new requests
2. Use the `resetWatchdog` function when needed
3. Check for multiple instances of the same component

### Performance Issues

1. Use appropriate timeout values
2. Don't set timeouts too low for legitimate slow operations
3. Consider using different timeouts for different operations

## Testing

The watchdog can be tested by:

1. **Manual timeout trigger**: Use `triggerTimeout()` function
2. **Network simulation**: Use browser dev tools to simulate slow networks
3. **Backend delays**: Temporarily add delays to API responses
4. **Component isolation**: Test individual components with the watchdog

## Future Enhancements

- [ ] Configurable retry logic
- [ ] Exponential backoff for retries
- [ ] Network status detection
- [ ] Automatic recovery mechanisms
- [ ] Performance metrics collection

