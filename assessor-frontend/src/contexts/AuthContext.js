import React, { createContext, useContext, useState, useEffect } from 'react';
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
  const [user, setUser] = useState(null);
  const [token, setToken] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  // Check for existing token on mount (temporarily disabled token validation)
  useEffect(() => {
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
            console.error('❌ AuthContext: Token validation failed:', error);
            // Token validation failed, clear storage
            localStorage.removeItem('assessor_token');
            localStorage.removeItem('assessor_user');
          }
        } else {
          console.log('🔍 AuthContext: No stored auth found');
        }
      
      setLoading(false);
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
        
        console.log('✅ AuthContext: Auth state updated, user authenticated');
        return { success: true };
      } else {
        console.log('❌ AuthContext: Login failed - no success or token');
        console.log('🔍 AuthContext: Response details:', response);
        throw new Error(response.message || 'Login failed');
      }
    } catch (error) {
      console.error('❌ AuthContext: Login error:', error);
      setError(error.message);
      return { success: false, error: error.message };
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

  const isAuthenticated = !!token && !!user;
  const isAdmin = user?.role === 'admin';

  const value = {
    user,
    token,
    loading,
    error,
    isAuthenticated,
    isAdmin,
    login,
    logout,
    clearError,
  };

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
};

