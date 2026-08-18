import React, { createContext, useContext, useState, useEffect, useRef, useCallback } from 'react';
import useLoadingWatchdog from '../hooks/useLoadingWatchdog';
import { apiService } from '../utils/api';

const AuthContext = createContext();

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};

export const AuthProvider = ({ children }) => {
  const initialToken = (() => {
    try { return sessionStorage.getItem('assessor_token') || null; } catch (_) { return null; }
  })();
  const initialUser = (() => {
    try {
      const raw = sessionStorage.getItem('assessor_user');
      return raw ? JSON.parse(raw) : null;
    } catch (_) { return null; }
  })();
  const [user, setUser] = useState(initialUser);
  const [token, setToken] = useState(initialToken);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  // Sync state management
  const [syncStatus, setSyncStatus] = useState('idle'); // 'idle' | 'syncing' | 'success' | 'failed' | 'incomplete'
  const [syncMessage, setSyncMessage] = useState(null);
  const isSyncingRef = useRef(false);

  // AFK timeout management
  const timeoutRef = useRef(null);
  const lastActivityRef = useRef(Date.now());
  const [afkTimeout, setAfkTimeout] = useState(() => {
    try {
      const stored = localStorage.getItem('assessor_afk_timeout');
      return stored ? parseInt(stored, 10) : 30; // Default 30 minutes
    } catch (_) {
      return 30;
    }
  });

  // Always use session-only mode for security

  // Check for existing token on mount (non-destructive token validation)
  useEffect(() => {
    const validateStoredAuth = async () => {
      console.log('🔍 AuthContext: Checking sessionStorage for auth');
      const sessionToken = sessionStorage.getItem('assessor_token');
      const sessionUser = sessionStorage.getItem('assessor_user');

      if (sessionToken && sessionUser) {
        try {
          console.log('🔍 AuthContext: Validating session token...');
          const response = await apiService.validateToken();
          // console.log('🔍 AuthContext: Session token validation response:', response);

          if (response.valid) {
            console.log('✅ AuthContext: Session token is valid, setting user state');
            setToken(sessionToken);
            setUser(JSON.parse(sessionUser));
          } else {
            console.log('❌ AuthContext: Session token is invalid, clearing storage');
            sessionStorage.removeItem('assessor_token');
            sessionStorage.removeItem('assessor_user');
          }
        } catch (error) {
          console.error('❌ AuthContext: Session token validation failed:', error);
          sessionStorage.removeItem('assessor_token');
          sessionStorage.removeItem('assessor_user');
        }
      } else {
        console.log('🔍 AuthContext: No session auth found');
      }
    };

    validateStoredAuth();
  }, []);

  const login = async (credentials) => {
    try {
      console.log('🔍 AuthContext: Login attempt with credentials:', { username: credentials.username });
      setLoading(true);
      setError(null);

      const response = await apiService.login(credentials);
      console.log('🔍 AuthContext: Login API response:', response);

      if (response.success && response.token) {
        const { token: newToken, user: userData } = response;
        console.log('✅ AuthContext: Login successful, storing auth data');
        console.log('🔍 AuthContext: Token to store:', newToken);
        console.log('🔍 AuthContext: User data to store:', userData);

        // Store in sessionStorage for session-only security
        sessionStorage.setItem('assessor_token', newToken);
        sessionStorage.setItem('assessor_user', JSON.stringify(userData));

        // Verify storage
        const storedToken = sessionStorage.getItem('assessor_token');
        const storedUser = sessionStorage.getItem('assessor_user');
        console.log('🔍 AuthContext: Verification - stored session token:', !!storedToken);
        console.log('🔍 AuthContext: Verification - stored session user:', !!storedUser);

        // Update state
        setToken(newToken);
        setUser(userData);
        try {
          // Reset last page so post-login starts on Dashboard
          localStorage.removeItem('assessor_current_page');
        } catch (_) { }

        console.log('✅ AuthContext: Auth state updated, user authenticated');
        return { success: true };
      } else {
        console.log('❌ AuthContext: Login failed - no success or token');
        console.log('🔍 AuthContext: Response details:', response);
        throw new Error(response.message || 'Login failed');
      }
    } catch (error) {
      console.error('❌ AuthContext: Login error:', error);
      const message = (error && error.message) ? error.message : 'Invalid username or password';
      setError(message);
      return { success: false, error: message };
    } finally {
      setLoading(false);
    }
  };

  // Safety watchdog for auth loading (e.g., login)
  useLoadingWatchdog({
    isLoading: loading,
    isInitialLoad: loading,
    setLoading,
    setError,
    componentName: 'AuthContext',
    timeoutMs: 15000,
    timeoutMessage: 'Authentication timed out. Please try again.',
    enabled: true
  });

  const logout = useCallback(async () => {
    try {
      if (token) {
        await apiService.logout();
      }
    } catch (error) {
      console.error('Logout error:', error);
    } finally {
      // Clear sessionStorage and state regardless of API call success
      sessionStorage.removeItem('assessor_token');
      sessionStorage.removeItem('assessor_user');
      setToken(null);
      setUser(null);
      setError(null);
    }
  }, [token]);

  const clearError = () => {
    setError(null);
  };

  const updateUserLocally = useCallback((updates) => {
    if (!updates) return;
    setUser((prev) => {
      if (!prev) return prev;
      const next = { ...prev, ...updates };
      try {
        sessionStorage.setItem('assessor_user', JSON.stringify(next));
      } catch (e) {
        // ignore storage errors
      }
      return next;
    });
  }, []);

  const refreshCurrentUser = useCallback(async () => {
    try {
      const response = await apiService.validateToken();
      if (response?.valid && response.user) {
        sessionStorage.setItem('assessor_user', JSON.stringify(response.user));
        setUser(response.user);
        return response.user;
      }
    } catch (err) {
      console.error('Failed to refresh user', err);
    }
    return null;
  }, []);

  // AFK timeout functions
  const resetAfkTimeout = useCallback(() => {
    const now = Date.now();
    lastActivityRef.current = now;

    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
      timeoutRef.current = null;
    }

    if (token && user) {
      const timeoutMs = afkTimeout * 60 * 1000; // Convert minutes to milliseconds
      console.log(`🕐 AuthContext: Setting AFK timeout for ${afkTimeout} minutes (${timeoutMs}ms)`);

      timeoutRef.current = setTimeout(() => {
        const timeSinceLastActivity = Date.now() - lastActivityRef.current;
        console.log(`🕐 AuthContext: AFK timeout triggered. Time since last activity: ${Math.round(timeSinceLastActivity / 1000)}s`);

        // Double-check that we're still inactive
        if (timeSinceLastActivity >= timeoutMs - 1000) { // Allow 1 second tolerance
          console.log('🕐 AuthContext: AFK timeout confirmed, logging out');
          logout();
        } else {
          console.log('🕐 AuthContext: AFK timeout cancelled due to recent activity');
        }
      }, timeoutMs);
    }
  }, [token, user, afkTimeout, logout]);

  const updateAfkTimeout = useCallback((newTimeout) => {
    setAfkTimeout(newTimeout);
    try {
      localStorage.setItem('assessor_afk_timeout', newTimeout.toString());
    } catch (_) {
      // Ignore storage errors
    }
    resetAfkTimeout(); // Reset with new timeout
  }, [resetAfkTimeout]);

  // Activity tracking with throttling to prevent excessive timeout resets
  const trackActivity = useCallback(() => {
    const now = Date.now();
    const timeSinceLastActivity = now - lastActivityRef.current;

    // Only reset timeout if at least 1 second has passed since last activity
    // This prevents excessive timeout resets from rapid events
    if (timeSinceLastActivity >= 1000) {
      console.log(`🕐 AuthContext: Activity detected, resetting AFK timeout (${Math.round(timeSinceLastActivity / 1000)}s since last activity)`);
      lastActivityRef.current = now;
      resetAfkTimeout();
    }
  }, [resetAfkTimeout]);


  // Set up activity listeners and AFK timeout
  useEffect(() => {
    if (!token || !user) {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
        timeoutRef.current = null;
      }
      return;
    }

    // Set up activity tracking with more comprehensive event list
    const events = [
      'mousedown', 'mousemove', 'keypress', 'keydown', 'scroll', 'touchstart', 'click',
      'focus', 'blur', 'resize', 'wheel', 'contextmenu'
    ];

    // Use passive listeners for better performance
    const eventOptions = { passive: true, capture: true };

    events.forEach(event => {
      document.addEventListener(event, trackActivity, eventOptions);
    });

    // Also track window focus/blur for better AFK detection
    const handleWindowFocus = () => {
      console.log('🕐 AuthContext: Window focused, resetting AFK timeout');
      trackActivity();
    };

    const handleWindowBlur = () => {
      console.log('🕐 AuthContext: Window blurred');
      // Don't reset timeout on blur, but log it for debugging
    };

    window.addEventListener('focus', handleWindowFocus);
    window.addEventListener('blur', handleWindowBlur);

    // Start AFK timeout
    resetAfkTimeout();

    return () => {
      events.forEach(event => {
        document.removeEventListener(event, trackActivity, eventOptions);
      });
      window.removeEventListener('focus', handleWindowFocus);
      window.removeEventListener('blur', handleWindowBlur);

      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
        timeoutRef.current = null;
      }
    };
  }, [token, user, trackActivity, resetAfkTimeout]);

  // Refactored runSync to be exposed
  const triggerManualSync = useCallback(async (forceFull = false) => {
    if (isSyncingRef.current) return;

    isSyncingRef.current = true;
    setSyncStatus('syncing');
    setSyncMessage(null);

    try {
      console.log('🔄 AuthContext: Triggering background sync...', forceFull ? '(FULL RESYNC)' : '');
      const response = await apiService.triggerSync(forceFull);
      console.log('✅ AuthContext: Background sync completed', response);

      if (response && response.success !== false) {
        setSyncStatus('success');
        setSyncMessage(response.message || 'Sync completed successfully');
      } else {
        setSyncStatus('failed');
        setSyncMessage(response?.message || 'Sync failed');
      }
      return response;
    } catch (err) {
      console.error('❌ AuthContext: Background sync failed', err);
      setSyncStatus('failed');
      setSyncMessage(err.message || 'Connection error during sync');
      throw err;
    } finally {
      isSyncingRef.current = false;
    }
  }, []);

  // Sync polling (triggers every 5 minutes when authenticated)
  const userId = user?.id;
  useEffect(() => {
    if (!token || !userId) return;

    let syncInterval;

    // Run sync immediately on login/load, then every 5 minutes
    triggerManualSync().catch(e => console.error("Background sync error:", e));
    syncInterval = setInterval(() => {
      triggerManualSync().catch(e => console.error("Background sync interval error:", e));
    }, 5 * 60 * 1000);

    return () => {
      if (syncInterval) {
        clearInterval(syncInterval);
      }
    };
  }, [token, userId, triggerManualSync]);

  const isAuthenticated = !!token && !!user;
  const isSuperAdmin = user?.role === 'superadmin';
  const isAdmin = user?.role === 'admin' || user?.role === 'administrator';
  const isAssessor = user?.role === 'assessor' || user?.role === 'municipal assessor';
  const isViewer = user?.role === 'viewer';
  const canManage = !!(isSuperAdmin || isAdmin || isAssessor);
  const canEdit = !!(isSuperAdmin || isAdmin || isAssessor || user?.role === 'verifier' || user?.role === 'editor'); // Only non-viewer roles can edit

  const value = {
    user,
    token,
    loading,
    error,
    isAuthenticated,
    isSuperAdmin,
    isAdmin,
    isAssessor,
    isViewer,
    canManage,
    canEdit,
    login,
    logout,
    clearError,
    afkTimeout,
    updateAfkTimeout,
    updateUserLocally,
    refreshCurrentUser,
    syncStatus,
    syncMessage,
    triggerManualSync,
  };

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
};

