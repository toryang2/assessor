import React, { createContext, useContext, useState, useEffect } from 'react';
import { ThemeProvider } from '@mui/material/styles';
import { theme as classicTheme } from '../theme/theme';
import { newTheme } from '../theme/newTheme';

const UIThemeContext = createContext();

export const useUITheme = () => {
  return useContext(UIThemeContext);
};

export const UIThemeProvider = ({ children }) => {
  // Initialize theme from localStorage or default to 'classic'
  const [uiTheme, setUiTheme] = useState(() => {
    try {
      const savedTheme = localStorage.getItem('assessor_ui_theme');
      return savedTheme === 'new' ? 'new' : 'classic';
    } catch (e) {
      return 'classic';
    }
  });

  const isClassic = uiTheme === 'classic';
  const isNewDesign = uiTheme === 'new';

  useEffect(() => {
    // Persist to localStorage
    try {
      localStorage.setItem('assessor_ui_theme', uiTheme);
    } catch (e) {
      console.error('Failed to save UI theme', e);
    }

    // Add root marker
    const rootElement = document.documentElement;
    rootElement.setAttribute('data-ui-theme', uiTheme);
  }, [uiTheme]);

  const toggleUiTheme = () => {
    setUiTheme(prev => prev === 'classic' ? 'new' : 'classic');
  };

  const currentMuiTheme = isClassic ? classicTheme : newTheme;

  const value = {
    uiTheme,
    setUiTheme,
    toggleUiTheme,
    isClassic,
    isNewDesign
  };

  return (
    <UIThemeContext.Provider value={value}>
      <ThemeProvider theme={currentMuiTheme}>
        {children}
      </ThemeProvider>
    </UIThemeContext.Provider>
  );
};
