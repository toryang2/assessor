import React from 'react';
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

