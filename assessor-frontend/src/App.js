import React, { useEffect } from 'react';
import { ThemeProvider } from '@mui/material/styles';
import { CssBaseline } from '@mui/material';
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { AdapterDateFns } from '@mui/x-date-pickers/AdapterDateFns';

import { theme } from './theme/theme';
import { AuthProvider, useAuth } from './contexts/AuthContext';

// Components
import Login from './components/Login/Login';
import Dashboard from './components/Dashboard/Dashboard';
import Layout from './components/Layout/Layout';

// Simple App Component - No Routing
const SimpleApp = () => {
  const { isAuthenticated } = useAuth();
  return isAuthenticated ? <Layout /> : <Login />;
};

// Main App Component
const App = () => {
  useEffect(() => {
    // Cache busting for Hostinger - add timestamp to prevent caching
    const timestamp = Date.now();
    console.log('🚀 App initialized with cache bust timestamp:', timestamp);
    
    // Store version info for debugging
    if (process.env.REACT_APP_VERSION) {
      console.log('📦 App version:', process.env.REACT_APP_VERSION);
    }
    
    // Clear any old cache data on app start (preserve auth and navigation state)
    try {
      const keys = Object.keys(localStorage);
      keys.forEach(key => {
        const isAssessorKey = key.startsWith('assessor_');
        const isAuthKey = key.includes('token') || key.includes('user');
        const isSafeKey = (
          key === 'assessor_current_page' ||
          key === 'assessor_settings' ||
          key === 'assessor_afk_timeout' ||
          key === 'app_logo_url'
        );
        if (isAssessorKey && !isAuthKey && !isSafeKey) {
          localStorage.removeItem(key);
        }
      });
    } catch (error) {
      console.warn('Failed to clear localStorage cache:', error);
    }
  }, []);

  return (
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <LocalizationProvider dateAdapter={AdapterDateFns}>
        <AuthProvider>
          <SimpleApp />
        </AuthProvider>
      </LocalizationProvider>
    </ThemeProvider>
  );
};

export default App;

