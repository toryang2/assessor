import React, { useEffect, useState } from 'react';
import { ThemeProvider } from '@mui/material/styles';
import { CssBaseline } from '@mui/material';
import GlobalStyles from '@mui/material/GlobalStyles';
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
  const [isSmallScreen, setIsSmallScreen] = useState(false);

  useEffect(() => {
    const detectSmallScreen = () => {
      try {
        const scrW = window.screen?.width || window.innerWidth;
        const scrH = window.screen?.height || window.innerHeight;
        const winW = window.innerWidth;
        const winH = window.innerHeight;
        
        // Consider exact 1280x720, 1366x768, portrait modes, or small viewports <= these bounds
        const matchExact720p = (scrW === 1280 && scrH === 720) || (scrW === 720 && scrH === 1280);
        const matchExact768p = (scrW === 1366 && scrH === 768) || (scrW === 768 && scrH === 1366);
        const matchExact1080 = (scrW === 1920 && scrH === 1080) || (scrW === 1080 && scrH === 1920);
        const matchViewport = (winW <= 1280 && winH <= 720) || (winW <= 1366 && winH <= 768) || (winW <= 1920 && winH <= 1080);
        
        setIsSmallScreen(Boolean(matchExact720p || matchExact768p || matchExact1080 || matchViewport));
      } catch (_) {
        setIsSmallScreen(false);
      }
    };
    detectSmallScreen();
    window.addEventListener('resize', detectSmallScreen);
    return () => window.removeEventListener('resize', detectSmallScreen);
  }, []);

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
      <GlobalStyles
        styles={{
          'html, body, #root': { height: '100%', fontFamily: '"Inter", "Roboto", "Helvetica", "Arial", sans-serif' },
          '*': { fontFamily: '"Inter", "Roboto", "Helvetica", "Arial", sans-serif' },
          '@media print': {
            'html, body, #root, *': { fontFamily: '"Inter", "Roboto", "Helvetica", "Arial", sans-serif !important' }
          }
        }}
      />
      {isSmallScreen && (
        <GlobalStyles styles={{ html: { fontSize: '14px' } }} />
      )}
      <LocalizationProvider dateAdapter={AdapterDateFns}>
        <AuthProvider>
          <SimpleApp />
        </AuthProvider>
      </LocalizationProvider>
    </ThemeProvider>
  );
};

export default App;

