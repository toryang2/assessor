import React, { createContext, useContext, useState, useEffect, useRef, useCallback } from 'react';
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
    try { return localStorage.getItem('assessor_token') || null; } catch (_) { return null; }
  })();
  const initialUser = (() => {
    try {
      const raw = localStorage.getItem('assessor_user');
      return raw ? JSON.parse(raw) : null;
    } catch (_) { return null; }
  })();
  const [user, setUser] = useState(initialUser);
  const [token, setToken] = useState(initialToken);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  
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

  // Check for existing token on mount (non-destructive token validation)
  useEffect(() => {
    // Clear any leftover unload flags on page load
    try {
      sessionStorage.removeItem('assessor_page_unloading');
    } catch (_) {
      // Ignore errors
    }

    const validateStoredAuth = async () => {
      const storedToken = localStorage.getItem('assessor_token');
      const storedUser = localStorage.getItem('assessor_user');
      
      console.log('🔍 AuthContext: Checking stored auth...', { storedToken: !!storedToken, storedUser: !!storedUser });
      
              if (storedToken && storedUser) {
          try {
            console.log('🔍 AuthContext: Validating stored token...');
            // Validate the token by making a test API call
            const response = await apiService.validateToken();
            console.log('🔍 AuthContext: Token validation response:', response);
            
            if (response.valid) {
              console.log('✅ AuthContext: Token is valid, setting user state');
              setToken(storedToken);
              setUser(JSON.parse(storedUser));
            } else {
              console.log('❌ AuthContext: Token is invalid, clearing storage');
              // Token is invalid, clear storage
              localStorage.removeItem('assessor_token');
              localStorage.removeItem('assessor_user');
            }
          } catch (error) {
            console.error('❌ AuthContext: Token validation failed (keeping stored auth for now):', error);
            // Keep stored auth; let API calls surface logout when user interacts
            if (storedToken && storedUser) {
              setToken(storedToken);
              try { setUser(JSON.parse(storedUser)); } catch (_) { setUser(null); }
            }
          }
        } else {
          console.log('🔍 AuthContext: No stored auth found');
        }
      
      // loading is kept false to avoid blocking UI; we opportunistically update auth state
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
        
        // Store in localStorage
        localStorage.setItem('assessor_token', newToken);
        localStorage.setItem('assessor_user', JSON.stringify(userData));
        
        // Verify storage
        const storedToken = localStorage.getItem('assessor_token');
        const storedUser = localStorage.getItem('assessor_user');
        console.log('🔍 AuthContext: Verification - stored token:', !!storedToken);
        console.log('🔍 AuthContext: Verification - stored user:', !!storedUser);
        
        // Update state
        setToken(newToken);
        setUser(userData);
        try {
          // Reset last page so post-login starts on Dashboard
          localStorage.removeItem('assessor_current_page');
        } catch (_) {}
        
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

  const logout = async () => {
    try {
      if (token) {
        await apiService.logout();
      }
    } catch (error) {
      console.error('Logout error:', error);
    } finally {
      // Clear local storage and state regardless of API call success
      localStorage.removeItem('assessor_token');
      localStorage.removeItem('assessor_user');
      setToken(null);
      setUser(null);
      setError(null);
    }
  };

  const clearError = () => {
    setError(null);
  };

  // AFK timeout functions
  const resetAfkTimeout = useCallback(() => {
    lastActivityRef.current = Date.now();
    
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
    }
    
    if (token && user) {
      timeoutRef.current = setTimeout(() => {
        console.log('🕐 AuthContext: AFK timeout reached, logging out');
        logout();
      }, afkTimeout * 60 * 1000); // Convert minutes to milliseconds
    }
  }, [token, user, afkTimeout]);

  const updateAfkTimeout = useCallback((newTimeout) => {
    setAfkTimeout(newTimeout);
    try {
      localStorage.setItem('assessor_afk_timeout', newTimeout.toString());
    } catch (_) {
      // Ignore storage errors
    }
    resetAfkTimeout(); // Reset with new timeout
  }, [resetAfkTimeout]);

  // Activity tracking
  const trackActivity = useCallback(() => {
    lastActivityRef.current = Date.now();
    resetAfkTimeout();
  }, [resetAfkTimeout]);

  // Browser close logout - use a more reliable method
  const handleBeforeUnload = useCallback(() => {
    if (token && user) {
      // Set a flag to indicate we're about to unload
      try {
        sessionStorage.setItem('assessor_page_unloading', 'true');
        console.log('🔄 AuthContext: Page unloading, set flag');
      } catch (error) {
        console.error('Error setting unload flag:', error);
      }
    }
  }, [token, user]);

  // Handle actual page unload (different from beforeunload)
  const handlePageHide = useCallback(() => {
    if (token && user) {
      // Check if this is a refresh by looking for the flag we set
      const isRefresh = sessionStorage.getItem('assessor_page_unloading') === 'true';
      
      if (!isRefresh) {
        // Only clear storage if it's not a refresh
        try {
          localStorage.removeItem('assessor_token');
          localStorage.removeItem('assessor_user');
          localStorage.removeItem('assessor_afk_timeout');
          console.log('🔄 AuthContext: Browser closing (not refresh), cleared local storage');
        } catch (error) {
          console.error('Error clearing storage on pagehide:', error);
        }
      } else {
        console.log('🔄 AuthContext: Page refresh detected, keeping session');
        // Clear the flag for next time
        try {
          sessionStorage.removeItem('assessor_page_unloading');
        } catch (error) {
          console.error('Error clearing unload flag:', error);
        }
      }
    }
  }, [token, user]);

  // Set up activity listeners and AFK timeout
  useEffect(() => {
    if (!token || !user) {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
        timeoutRef.current = null;
      }
      return;
    }

    // Set up activity tracking
    const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
    
    events.forEach(event => {
      document.addEventListener(event, trackActivity, true);
    });

    // Set up browser close logout
    window.addEventListener('beforeunload', handleBeforeUnload);
    window.addEventListener('pagehide', handlePageHide);

    // Start AFK timeout
    resetAfkTimeout();

    return () => {
      events.forEach(event => {
        document.removeEventListener(event, trackActivity, true);
      });
      window.removeEventListener('beforeunload', handleBeforeUnload);
      window.removeEventListener('pagehide', handlePageHide);
      
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
      }
    };
  }, [token, user, trackActivity, handleBeforeUnload, resetAfkTimeout]);

  const isAuthenticated = !!token && !!user;
  const isSuperAdmin = user?.role === 'superadmin';
  const isAdmin = user?.role === 'admin' || user?.role === 'administrator';
  const isAssessor = user?.role === 'assessor' || user?.role === 'municipal assessor';
  const canManage = !!(isSuperAdmin || isAdmin || isAssessor);

  const value = {
    user,
    token,
    loading,
    error,
    isAuthenticated,
    isSuperAdmin,
    isAdmin,
    isAssessor,
    canManage,
    login,
    logout,
    clearError,
    afkTimeout,
    updateAfkTimeout,
  };

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
};

