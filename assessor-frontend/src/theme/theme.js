import { createTheme } from '@mui/material/styles';

// Friendly, flat color palette - no gradients
const colors = {
  primary: {
    main: '#2563eb', // Softer blue
    light: '#60a5fa',
    dark: '#1d4ed8',
    contrastText: '#ffffff'
  },
  secondary: {
    main: '#f59e0b', // Warm gold
    light: '#fbbf24',
    dark: '#d97706',
    contrastText: '#000000'
  },
  background: {
    default: '#f8fafc', // Very light gray
    paper: '#ffffff'
  },
  text: {
    primary: '#334155', // Softer dark gray
    secondary: '#64748b'
  },
  success: {
    main: '#10b981', // Soft green
    light: '#34d399',
    dark: '#059669'
  },
  warning: {
    main: '#f59e0b', // Warm orange
    light: '#fbbf24',
    dark: '#d97706'
  },
  error: {
    main: '#ef4444', // Soft red
    light: '#f87171',
    dark: '#dc2626'
  },
  info: {
    main: '#3b82f6', // Soft blue
    light: '#60a5fa',
    dark: '#2563eb'
  },
  // Additional friendly colors
  neutral: {
    50: '#f8fafc',
    100: '#f1f5f9',
    200: '#e2e8f0',
    300: '#cbd5e1',
    400: '#94a3b8',
    500: '#64748b',
    600: '#475569',
    700: '#334155',
    800: '#1e293b',
    900: '#0f172a'
  }
};

// Friendly theme with softer styling
export const theme = createTheme({
  palette: {
    primary: colors.primary,
    secondary: colors.secondary,
    background: colors.background,
    text: colors.text,
    success: colors.success,
    warning: colors.warning,
    error: colors.error,
    info: colors.info
  },
  typography: {
    fontFamily: '"Inter", "Roboto", "Helvetica", "Arial", sans-serif',
    h1: {
      fontSize: '2.5rem',
      fontWeight: 600,
      color: colors.primary.main,
      marginBottom: '1rem'
    },
    h2: {
      fontSize: '2rem',
      fontWeight: 600,
      color: colors.primary.main,
      marginBottom: '0.75rem'
    },
    h3: {
      fontSize: '1.5rem',
      fontWeight: 600,
      color: colors.primary.main,
      marginBottom: '0.5rem'
    },
    h4: {
      fontSize: '1.25rem',
      fontWeight: 500,
      color: colors.text.primary,
      marginBottom: '0.5rem'
    },
    h5: {
      fontSize: '1.125rem',
      fontWeight: 500,
      color: colors.text.primary,
      marginBottom: '0.25rem'
    },
    h6: {
      fontSize: '1rem',
      fontWeight: 500,
      color: colors.text.primary,
      marginBottom: '0.25rem'
    },
    body1: {
      fontSize: '1rem',
      lineHeight: 1.6,
      color: colors.text.primary
    },
    body2: {
      fontSize: '0.875rem',
      lineHeight: 1.5,
      color: colors.text.secondary
    },
    button: {
      textTransform: 'none',
      fontWeight: 500,
      fontSize: '0.875rem'
    }
  },
  shape: {
    borderRadius: 4 // Standard corners
  },
  components: {
    MuiButton: {
      styleOverrides: {
        root: {
          borderRadius: 4,
          padding: '10px 24px',
          fontWeight: 500,
          boxShadow: 'none', // No shadow by default
          border: '1px solid transparent'
        },
        contained: {
          backgroundColor: colors.primary.main,
          color: colors.primary.contrastText,
          '&:hover': {
            backgroundColor: colors.primary.dark,
            boxShadow: '0 6px 16px rgba(37, 99, 235, 0.25)'
          }
        },
        outlined: {
          borderColor: colors.primary.main,
          color: colors.primary.main,
          '&:hover': {
            backgroundColor: colors.primary.main,
            color: colors.primary.contrastText,
            borderColor: colors.primary.main
          }
        },
        text: {
          color: colors.primary.main,
          '&:hover': {
            backgroundColor: colors.neutral[100]
          }
        }
      }
    },
    MuiCard: {
      styleOverrides: {
        root: {
          borderRadius: 4,
          boxShadow: '0 2px 8px rgba(0, 0, 0, 0.08)',
          border: `1px solid ${colors.neutral[200]}`
        }
      }
    },
    MuiPaper: {
      styleOverrides: {
        root: {
          borderRadius: 4,
          boxShadow: '0 2px 8px rgba(0, 0, 0, 0.08)'
        }
      }
    },
    MuiTextField: {
      styleOverrides: {
        root: {
                                  '& .MuiOutlinedInput-root': {
              borderRadius: 4,
              backgroundColor: colors.neutral[50],
            '&:hover .MuiOutlinedInput-notchedOutline': {
              borderColor: colors.primary.light
            },
            '&.Mui-focused .MuiOutlinedInput-notchedOutline': {
              borderColor: colors.primary.main,
              borderWidth: '2px'
            },
            '&.Mui-focused': {
              backgroundColor: colors.background.paper
            }
          }
        }
      }
    },
    MuiTableHead: {
      styleOverrides: {
        root: {
          backgroundColor: colors.neutral[100],
          '& .MuiTableCell-head': {
            color: colors.text.primary,
            fontWeight: 600,
            borderBottom: `2px solid ${colors.neutral[200]}`
          }
        }
      }
    },
    MuiTableCell: {
      styleOverrides: {
        root: {
          borderBottom: `1px solid ${colors.neutral[200]}`,
          padding: '16px',
          fontSize: '0.875rem'
        }
      }
    },
    MuiChip: {
      styleOverrides: {
        root: {
          borderRadius: 20,
          fontWeight: 500,
          fontSize: '0.75rem',
          height: '24px'
        }
      }
    },
    MuiDialog: {
      styleOverrides: {
        paper: {
          borderRadius: 4,
          boxShadow: '0 20px 40px rgba(0, 0, 0, 0.15)'
        }
      }
    },
    MuiDialogTitle: {
      styleOverrides: {
        root: {
          backgroundColor: colors.neutral[50],
          borderBottom: `1px solid ${colors.neutral[200]}`,
          padding: '20px 24px'
        }
      }
    },
    MuiDialogContent: {
      styleOverrides: {
        root: {
          padding: '24px'
        }
      }
    },
    MuiIconButton: {
      styleOverrides: {
        root: {
          borderRadius: 4
        }
      }
    },
    MuiSelect: {
      styleOverrides: {
        root: {
          borderRadius: 4,
          backgroundColor: colors.neutral[50]
        }
      }
    },
    MuiMenuItem: {
      styleOverrides: {
        root: {
          borderRadius: 4,
          margin: '2px 8px',
          '&:hover': {
            backgroundColor: colors.neutral[100]
          }
        }
      }
    }
  }
});

// Animation variants for framer-motion - softer animations
export const animations = {
  fadeIn: {
    initial: { opacity: 0, y: 10 },
    animate: { opacity: 1, y: 0 },
    exit: { opacity: 0, y: -10 },
    transition: { duration: 0.4, ease: "easeOut" }
  },
  slideIn: {
    initial: { x: -50, opacity: 0 },
    animate: { x: 0, opacity: 1 },
    exit: { x: 50, opacity: 0 },
    transition: { duration: 0.5, ease: "easeOut" }
  },
  scaleIn: {
    initial: { scale: 0.95, opacity: 0 },
    animate: { scale: 1, opacity: 1 },
    exit: { scale: 0.95, opacity: 0 },
    transition: { duration: 0.4, ease: "easeOut" }
  },
  stagger: {
    animate: {
      transition: {
        staggerChildren: 0.08
      }
    }
  }
};

// Status colors for property records - softer versions
export const statusColors = {
  active: colors.success.main,
  inactive: colors.neutral[500],
  archived: colors.info.main,
  deleted: colors.error.main,
  pending: colors.warning.main,
  approved: colors.success.main,
  rejected: colors.error.main
};

// Export colors for use in components
export { colors };
