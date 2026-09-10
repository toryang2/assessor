import { createTheme, responsiveFontSizes } from '@mui/material/styles';

const newDesignColors = {
  primary: {
    main: '#2563eb', // blue-600
    light: '#dbeafe', // blue-100
    dark: '#1d4ed8', // blue-700
    contrastText: '#ffffff'
  },
  background: {
    default: '#f8fafc', // slate-50
    paper: '#ffffff',
  },
  text: {
    primary: '#1e293b', // slate-800
    secondary: '#64748b', // slate-500
    disabled: '#94a3b8', // slate-400
  },
  border: {
    main: '#e2e8f0', // slate-200
    subtle: '#dbeafe', // blue-100
  },
  success: {
    main: '#16a34a', // green-600
    light: '#dcfce7', // green-100
    dark: '#14532d', // green-900
    contrastText: '#ffffff'
  },
  warning: {
    main: '#d97706', // amber-600
    light: '#fef3c7', // amber-100
    dark: '#78350f', // amber-900
    contrastText: '#ffffff'
  },
  error: {
    main: '#dc2626', // red-600
    light: '#fee2e2', // red-100
    dark: '#7f1d1d', // red-900
    contrastText: '#ffffff'
  },
  info: {
    main: '#2563eb', // blue-600
    light: '#dbeafe', // blue-100
    dark: '#1e3a8a', // blue-900
    contrastText: '#ffffff'
  }
};

const customShadows = Array(25).fill('none');
customShadows[1] = '0 1px 2px 0 rgba(0, 0, 0, 0.05)';
customShadows[2] = '0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1)';
customShadows[3] = '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1)';
customShadows[4] = '0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1)';
customShadows[5] = '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)';
customShadows[6] = '0 25px 50px -12px rgba(0, 0, 0, 0.25)';
for (let i = 7; i < 25; i++) { customShadows[i] = customShadows[6]; }

const baseTheme = createTheme({
  shape: {
    borderRadius: 8,
  },
  shadows: customShadows,
  palette: {
    primary: newDesignColors.primary,
    background: newDesignColors.background,
    text: newDesignColors.text,
    divider: newDesignColors.border.main,
    success: newDesignColors.success,
    warning: newDesignColors.warning,
    error: newDesignColors.error,
    info: newDesignColors.info,
  },
  typography: {
    fontFamily: '"Plus Jakarta Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
    h1: { fontWeight: 700, fontSize: '2rem', lineHeight: 1.2, letterSpacing: '-0.02em' },
    h2: { fontWeight: 700, fontSize: '1.75rem', lineHeight: 1.2, letterSpacing: '-0.02em' },
    h3: { fontWeight: 600, fontSize: '1.5rem', lineHeight: 1.2, letterSpacing: '-0.01em' },
    h4: { fontWeight: 600, fontSize: '1.25rem', lineHeight: 1.2, letterSpacing: '-0.01em' },
    h5: { fontWeight: 600, fontSize: '1.125rem', lineHeight: 1.2 },
    h6: { fontWeight: 600, fontSize: '1rem', lineHeight: 1.2 },
    body1: { fontWeight: 400, fontSize: '1rem', lineHeight: 1.5 },
    body2: { fontWeight: 400, fontSize: '0.875rem', lineHeight: 1.5 },
    subtitle1: { fontWeight: 500, fontSize: '1rem', lineHeight: 1.5 },
    subtitle2: { fontWeight: 500, fontSize: '0.875rem', lineHeight: 1.5 },
    button: { textTransform: 'none', fontWeight: 600 },
    caption: { fontWeight: 400, fontSize: '0.75rem', lineHeight: 1.5 },
    overline: { fontWeight: 600, fontSize: '0.75rem', letterSpacing: '0.05em', textTransform: 'uppercase' },
    monospace: { fontFamily: '"Space Mono", monospace' }
  },
  components: {
    MuiButton: {
      defaultProps: {
        disableElevation: true,
      },
      styleOverrides: {
        root: {
          borderRadius: 8,
          textTransform: 'none',
          fontWeight: 600,
          '@media (max-width:600px)': {
            minHeight: '44px',
          }
        },
        containedPrimary: {
          backgroundColor: newDesignColors.primary.main,
          '&:hover': {
            backgroundColor: newDesignColors.primary.dark,
          }
        },
        outlined: {
          borderColor: newDesignColors.border.main,
          color: newDesignColors.text.primary,
          '&:hover': {
            backgroundColor: newDesignColors.background.default,
            borderColor: newDesignColors.border.main,
          }
        }
      }
    },
    MuiCard: {
      styleOverrides: {
        root: {
          borderRadius: 16,
          boxShadow: '0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1)',
          border: `1px solid ${newDesignColors.border.subtle}`,
          backgroundColor: newDesignColors.background.paper,
        }
      }
    },
    MuiPaper: {
      styleOverrides: {
        root: {
          backgroundImage: 'none',
        },
        elevation1: {
          boxShadow: '0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1)',
          border: `1px solid ${newDesignColors.border.main}`,
        },
        elevation2: {
          boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1)',
        }
      }
    },
    MuiChip: {
      styleOverrides: {
        root: {
          borderRadius: 9999,
          fontWeight: 600,
        },
        colorSuccess: {
          backgroundColor: newDesignColors.success.light,
          color: newDesignColors.success.main,
        },
        colorWarning: {
          backgroundColor: newDesignColors.warning.light,
          color: newDesignColors.warning.main,
        },
        colorError: {
          backgroundColor: newDesignColors.error.light,
          color: newDesignColors.error.main,
        },
        colorInfo: {
          backgroundColor: newDesignColors.info.light,
          color: newDesignColors.info.main,
        },
        colorPrimary: {
          backgroundColor: newDesignColors.primary.light,
          color: newDesignColors.primary.main,
        }
      }
    },
    MuiTable: {
      styleOverrides: {
        root: {
          borderCollapse: 'collapse',
        }
      }
    },
    MuiTableHead: {
      styleOverrides: {
        root: {
          backgroundColor: '#ffffff',
          borderBottom: `1px solid ${newDesignColors.border.subtle}`,
        }
      }
    },
    MuiTableRow: {
      styleOverrides: {
        root: {
          transition: 'all 0.2s',
          borderLeft: '4px solid transparent',
          '&:hover': {
            backgroundColor: 'rgba(239, 246, 255, 0.4)', // blue-50/40
            borderLeftColor: newDesignColors.primary.main,
          }
        },
        head: {
          borderLeft: 'none',
          '&:hover': {
            backgroundColor: 'transparent',
            borderLeft: 'none',
          }
        }
      }
    },
    MuiTableCell: {
      styleOverrides: {
        root: {
          borderBottom: `1px solid ${newDesignColors.background.default}`,
          color: newDesignColors.text.primary,
          padding: '12px 16px',
          fontSize: '0.8125rem', // 13px
          '@media (max-width:600px)': {
            padding: '12px 8px',
          }
        },
        head: {
          color: newDesignColors.text.disabled,
          fontWeight: 700,
          textTransform: 'uppercase',
          letterSpacing: '0.05em',
          fontSize: '0.65rem', // 10px
          backgroundColor: 'transparent',
          borderBottom: `1px solid ${newDesignColors.border.subtle}`,
        }
      }
    },
    MuiOutlinedInput: {
      styleOverrides: {
        root: {
          borderRadius: 8,
          backgroundColor: '#f8fafc', // slate-50
          transition: 'all 0.2s',
          fontSize: '0.875rem',
          color: newDesignColors.text.primary,
          '@media (max-width:600px)': {
            minHeight: '44px',
          },
          '& .MuiOutlinedInput-notchedOutline': {
            borderColor: newDesignColors.border.subtle, // blue-100
          },
          '&:hover': {
            backgroundColor: '#ffffff',
          },
          '&:hover .MuiOutlinedInput-notchedOutline': {
            borderColor: '#93c5fd', // blue-300
          },
          '&.Mui-focused': {
            backgroundColor: '#ffffff',
            boxShadow: '0 0 0 2px rgba(191, 219, 254, 0.5)', // focus:ring-blue-200
          },
          '&.Mui-focused .MuiOutlinedInput-notchedOutline': {
            borderColor: '#60a5fa', // blue-400
            borderWidth: '1px',
          },
          '&.Mui-disabled': {
            backgroundColor: '#f1f5f9', // slate-100
            color: newDesignColors.text.disabled,
          }
        },
        input: {
          padding: '10px 14px', // Similar to py-2 px-3
        }
      }
    },
    MuiInputLabel: {
      styleOverrides: {
        root: {
          color: newDesignColors.text.secondary,
          fontWeight: 600,
          fontSize: '0.875rem',
          '&.Mui-focused': {
            color: newDesignColors.primary.main,
          }
        }
      }
    },
    MuiFormHelperText: {
      styleOverrides: {
        root: {
          fontSize: '0.75rem',
          color: newDesignColors.text.secondary,
        }
      }
    },
    MuiDialog: {
      styleOverrides: {
        paper: {
          borderRadius: 16,
          boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25)', // shadow-2xl
          border: '1px solid #e0f2fe', // sky-100
          '@media (max-width:600px)': {
            margin: 0,
            width: '100%',
            maxHeight: '100%',
            borderRadius: 0,
          }
        }
      }
    },
    MuiBackdrop: {
      styleOverrides: {
        root: {
          backgroundColor: 'rgba(15, 23, 42, 0.6)', // slate-900/60
          backdropFilter: 'blur(2px)',
        }
      }
    },
    MuiDialogTitle: {
      styleOverrides: {
        root: {
          backgroundColor: '#0369a1', // sky-700
          color: '#ffffff',
          fontWeight: 700,
          fontSize: '1.125rem',
          padding: '16px 24px',
        }
      }
    },
    MuiDialogContent: {
      styleOverrides: {
        root: {
          padding: '24px',
        },
        dividers: {
          borderColor: '#eff6ff', // blue-50
        }
      }
    },
    MuiDialogActions: {
      styleOverrides: {
        root: {
          backgroundColor: '#f8fafc', // slate-50
          padding: '16px 24px',
          borderTop: '1px solid #e2e8f0', // slate-200
        }
      }
    },
    MuiMenu: {
      styleOverrides: {
        paper: {
          borderRadius: 12,
          boxShadow: '0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1)', // shadow-lg
          border: `1px solid ${newDesignColors.border.main}`,
        }
      }
    },
    MuiMenuItem: {
      styleOverrides: {
        root: {
          fontSize: '0.875rem',
          padding: '8px 16px',
          '&:hover': {
            backgroundColor: newDesignColors.primary.light,
          }
        }
      }
    },
    MuiAlert: {
      styleOverrides: {
        root: {
          borderRadius: 8,
          fontSize: '0.875rem',
        },
        standardSuccess: {
          backgroundColor: newDesignColors.success.light,
          color: newDesignColors.success.dark,
        },
        standardError: {
          backgroundColor: newDesignColors.error.light,
          color: newDesignColors.error.dark,
        },
        standardWarning: {
          backgroundColor: newDesignColors.warning.light,
          color: newDesignColors.warning.dark,
        },
        standardInfo: {
          backgroundColor: newDesignColors.info.light,
          color: newDesignColors.info.dark,
        }
      }
    },
    MuiTooltip: {
      styleOverrides: {
        tooltip: {
          backgroundColor: newDesignColors.text.primary,
          color: '#ffffff',
          fontSize: '0.75rem',
          borderRadius: 4,
          padding: '6px 10px',
        },
        arrow: {
          color: newDesignColors.text.primary,
        }
      }
    },
    MuiDrawer: {
      styleOverrides: {
        paper: {
          backgroundColor: newDesignColors.background.paper,
          borderRight: `1px solid ${newDesignColors.border.subtle}`,
        }
      }
    },
    MuiAppBar: {
      styleOverrides: {
        root: {
          backgroundColor: newDesignColors.background.default,
          color: newDesignColors.text.primary,
          boxShadow: '0 1px 2px 0 rgba(0, 0, 0, 0.05)',
          borderBottom: `1px solid ${newDesignColors.border.main}`,
        }
      }
    }
  }
});

export const newTheme = responsiveFontSizes(baseTheme);
