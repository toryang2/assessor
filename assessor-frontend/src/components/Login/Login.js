import React, { useState, useEffect, useMemo, useRef } from 'react';

import { motion } from 'framer-motion';
import {
  Box,
  Card,
  CardContent,
  TextField,
  Button,
  Typography,
  Alert,
  Snackbar,
  InputAdornment,
  IconButton,
  Container,
  CircularProgress
} from '@mui/material';
import {
  Visibility,
  VisibilityOff,
  AccountCircle,
  Lock,
  Business
} from '@mui/icons-material';
import { useAuth } from '../../contexts/AuthContext';
import { theme, animations } from '../../theme/theme';
import { apiService } from '../../utils/api';
import LoadingDots from '../LoadingDots';

const Login = () => {
  
  const { login, error, clearError } = useAuth();
  // Stable cache-buster and asset base to prevent background image reloads on re-render
  const bgVersionRef = useRef(
    (typeof window !== 'undefined' && (window.REACT_APP_VERSION || window.__APP_BUILD__)) || '1'
  );
  const assetsBase = useMemo(() => {
    if (typeof window === 'undefined') return '';
    return window.__PUBLIC_URL__ || (window.location.origin + "/wp-content/themes/assessor-theme/assets");
  }, []);
  
  const [formData, setFormData] = useState({
    username: '',
    password: ''
  });
  const [showPassword, setShowPassword] = useState(false);
  const [validationErrors, setValidationErrors] = useState({});
  const [loading, setLoading] = useState(false);
  const initialSettings = (() => {
    if (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__) return window.__ASSESSOR_SETTINGS__;
    try {
      const cached = localStorage.getItem('assessor_settings');
      if (cached) return JSON.parse(cached);
    } catch (_) {}
    const cachedLogo = typeof window !== 'undefined' ? localStorage.getItem('app_logo_url') : '';
    if (cachedLogo) return { app_logo_url: cachedLogo };
    return null;
  })();
  const [settings, setSettings] = useState(initialSettings);
  const [snackbar, setSnackbar] = useState({ open: false, message: '', severity: 'error' });

  // Redirect if already authenticated


  // Clear error when component mounts
  const [year, setYear] = useState(new Date().getFullYear());

  useEffect(() => {
    // Get current year in Asia/Manila timezone
    const formatter = new Intl.DateTimeFormat('en-US', {
      timeZone: 'Asia/Manila',
      year: 'numeric',
    });

    const parts = formatter.formatToParts(new Date());
    const yearPart = parts.find(p => p.type === 'year');
    if (yearPart) {
      setYear(yearPart.value);
    }
  }, []);
  
  useEffect(() => {
    clearError();
  }, [clearError]);

  useEffect(() => {
    const loadSettings = async () => {
      try {
        const data = await apiService.getSettings();
        setSettings(data);
        try {
          localStorage.setItem('assessor_settings', JSON.stringify(data));
          if (data && data.app_logo_url) localStorage.setItem('app_logo_url', data.app_logo_url);
        } catch (_) {}
      } catch (e) {
        const fallback = (window && window.__ASSESSOR_SETTINGS__) || null;
        setSettings(fallback);
      }
    };
    loadSettings();
  }, []);

  const handleInputChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: value
    }));
    
    // Clear validation error for this field
    if (validationErrors[name]) {
      setValidationErrors(prev => ({
        ...prev,
        [name]: ''
      }));
    }
  };

  const validateForm = () => {
    const errors = {};
    
    if (!formData.username.trim()) {
      errors.username = 'Username is required';
    }
    
    if (!formData.password) {
      errors.password = 'Password is required';
    }
    
    setValidationErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    console.log('🔍 Login Component: Form submitted with data:', formData);
    
    if (!validateForm()) {
      console.log('❌ Login Component: Form validation failed');
      return;
    }

    console.log('✅ Login Component: Form validation passed, starting login process');
    clearError();
    setLoading(true);
    
    try {
      console.log('🔍 Login Component: Calling login function...');
      const result = await login(formData);
      console.log('🔍 Login Component: Login result:', result);
      
      if (result.success) {
        console.log('✅ Login Component: Login successful');
        setSnackbar({ open: true, message: 'Signed in successfully', severity: 'success' });
      } else {
        console.log('❌ Login Component: Login failed:', result.error);
        setSnackbar({ open: true, message: result.error || 'Invalid username or password', severity: 'error' });
      }
    } catch (error) {
      console.error('❌ Login Component: Login error:', error);
      setSnackbar({ open: true, message: error.message || 'Login failed', severity: 'error' });
    } finally {
      setLoading(false);
    }
  };

  const togglePasswordVisibility = () => {
    setShowPassword(!showPassword);
  };

  // If already authenticated, do not render Login (avoid flicker on refresh)
  const { isAuthenticated } = useAuth();
  if (isAuthenticated) return null;
  const baseMunicipality = (settings && settings.header_municipality) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_municipality) || 'KITAOTAO';
  const toFormalCase = (text) => {
    if (!text) return '';
    const small = new Set(['of','and','the','for','in','on','at','a','an']);
    const words = String(text).toLowerCase().split(/\s+/);
    return words.map((w, i) => {
      if (!w) return w;
      if (i > 0 && small.has(w)) return w;
      return w.charAt(0).toUpperCase() + w.slice(1);
    }).join(' ');
  };
  const headerMunicipality = `${toFormalCase(baseMunicipality)}`;
  const isSmallScreen = (() => {
    try {
      const w = window.innerWidth;
      const h = window.innerHeight;
      return (w <= 1280 && h <= 720) || (w <= 1366 && h <= 768);
    } catch (_) {
      return false;
    }
  })();
  return (
    <Box
      sx={{
        minHeight: '100vh',
        background: `linear-gradient(rgba(240, 244, 248, 0.6), rgba(240, 244, 248, 0.6)), url('${assetsBase}/background.jpg?v=${bgVersionRef.current}')`,
        backgroundSize: 'cover',
        backgroundPosition: 'center',
        backgroundRepeat: 'no-repeat',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: isSmallScreen ? 1.5 : 2
      }}
    >
      <Container maxWidth={isSmallScreen ? 'xs' : 'sm'}>
        <motion.div
          initial="initial"
          animate="animate"
          variants={animations.scaleIn}
        >
          <Card
            elevation={8}
            sx={{
              borderRadius: 4,
              overflow: 'hidden',
              position: 'relative',
              backgroundColor: 'rgba(255, 255, 255, 0.5)', // Semi-transparent white background
              backdropFilter: 'blur(10px)', // Add blur effect for glass morphism
              border: '1px solid rgba(255, 255, 255, 0.2)' // Subtle border
            }}
          >
            {/* Header with Philippine Government branding */}
            <Box
              sx={{
                padding: isSmallScreen ? 3 : 4,
                paddingBottom: isSmallScreen ? 2 : 2.5,
                textAlign: 'center',
                position: 'relative',
                overflow: 'hidden'
              }}
            >
              <motion.div
                initial="initial"
                animate="animate"
                variants={{
                  initial: { opacity: 0 },
                  animate: {
                    opacity: 1,
                    transition: {
                      staggerChildren: 0.2
                    }
                  }
                }}
              >
                <motion.div
                  variants={{
                    initial: { opacity: 0, y: 20 },
                    animate: { opacity: 1, y: 0, transition: { duration: 0.6, ease: "easeOut" } }
                  }}
                >
                  {settings?.app_logo_url ? (
                    <img src={settings.app_logo_url} alt="Logo" style={{ height: isSmallScreen ? 64 : 96, marginBottom: 16, opacity: 0.9 }} />
                  ) : (
                    <Business sx={{ fontSize: isSmallScreen ? 44 : 60, marginBottom: 2, opacity: 0.8 }} />
                  )}
                </motion.div>
                
                <motion.div
                  variants={{
                    initial: { opacity: 0, y: 20 },
                    animate: { opacity: 1, y: 0, transition: { duration: 0.6, ease: "easeOut" } }
                  }}
                >
                  <Typography variant="h4" component="h1" gutterBottom sx={{ fontWeight: 600, fontSize: isSmallScreen ? '1.35rem' : undefined }}>
                    Local Government of {headerMunicipality}
                  </Typography>
                </motion.div>
                
                <motion.div
                  variants={{
                    initial: { opacity: 0, y: 20 },
                    animate: { opacity: 1, y: 0, transition: { duration: 0.6, ease: "easeOut" } }
                  }}
                >
                  <Typography variant="h6" component="h2" sx={{ fontWeight: 500, fontSize: isSmallScreen ? '1rem' : undefined }}>
                    Assessor's Office Archiving System
                  </Typography>
                </motion.div>
              </motion.div>
            </Box>

            <CardContent sx={{ padding: isSmallScreen ? 3 : 4, paddingTop: isSmallScreen ? 2 : 2.5 }}>
              <motion.form
                onSubmit={handleSubmit}
                initial="initial"
                animate="animate"
                variants={animations.fadeIn}
              >
                <Typography variant="h5" component="h3" sx={{ textAlign: 'center', marginBottom: 1.5, fontWeight: 600, fontSize: isSmallScreen ? '1.1rem' : undefined }}>
                  Sign In
                </Typography>

                {/* Error toast is shown via Snackbar below */}

                <TextField
                  fullWidth
                  label="Username"
                  name="username"
                  value={formData.username}
                  onChange={handleInputChange}
                  error={!!validationErrors.username}
                  helperText={validationErrors.username || ' '}
                  margin={isSmallScreen ? 'dense' : 'normal'}
                  variant="outlined"
                  size={isSmallScreen ? 'small' : 'medium'}
                  InputProps={{
                    startAdornment: (
                      <InputAdornment position="start">
                        <AccountCircle color="primary" />
                      </InputAdornment>
                    ),
                  }}
                  sx={{ 
                    marginBottom: isSmallScreen ? 1.5 : 2,
                    '& .MuiOutlinedInput-root': {
                      backgroundColor: 'transparent !important',
                      '&:hover': {
                        backgroundColor: 'transparent !important',
                      },
                      '&.Mui-focused': {
                        backgroundColor: 'transparent !important',
                      },
                    },
                  }}
                />

                <TextField
                  fullWidth
                  label="Password"
                  name="password"
                  type={showPassword ? 'text' : 'password'}
                  value={formData.password}
                  onChange={handleInputChange}
                  error={!!validationErrors.password}
                  helperText={validationErrors.password || ' '}
                  margin={isSmallScreen ? 'dense' : 'normal'}
                  variant="outlined"
                  size={isSmallScreen ? 'small' : 'medium'}
                  InputProps={{
                    startAdornment: (
                      <InputAdornment position="start">
                        <Lock color="primary" />
                      </InputAdornment>
                    ),
                    endAdornment: (
                      <InputAdornment position="end">
                        <IconButton
                          onClick={togglePasswordVisibility}
                          edge="end"
                        >
                          {showPassword ? <VisibilityOff /> : <Visibility />}
                        </IconButton>
                      </InputAdornment>
                    ),
                  }}
                  sx={{ 
                    marginBottom: isSmallScreen ? 2 : 3,
                    '& .MuiOutlinedInput-root': {
                      backgroundColor: 'transparent !important',
                      '&:hover': {
                        backgroundColor: 'transparent !important',
                      },
                      '&.Mui-focused': {
                        backgroundColor: 'transparent !important',
                      },
                    },
                  }}
                />

                <motion.div
                  whileHover={{ scale: 1.02 }}
                  whileTap={{ scale: 0.98 }}
                >
                  <Button
                    type="submit"
                    fullWidth
                    variant="contained"
                    size={isSmallScreen ? 'medium' : 'large'}
                    disabled={loading}
                    startIcon={loading ? <CircularProgress size={20} color="inherit" /> : null}
                    sx={{
                      height: isSmallScreen ? 44 : 56,
                      fontSize: isSmallScreen ? '0.95rem' : '1.1rem',
                      fontWeight: 600,
                      background: theme.palette.primary.main,
                      '&:hover': {
                        background: theme.palette.primary.dark,
                        transform: loading ? 'none' : 'translateY(-2px)',
                        boxShadow: loading ? 'none' : (isSmallScreen ? '0 6px 18px rgba(37, 99, 235, 0.22)' : '0 8px 25px rgba(37, 99, 235, 0.3)')
                      }
                    }}
                  >
                    {loading ? <>Signing In<LoadingDots /></> : 'Sign In'}
                  </Button>
                </motion.div>

                {/* <Box sx={{ marginTop: 3, textAlign: 'center' }}>
                  <Typography variant="body2" color="text.secondary">
                    Secure access to government property assessment records
                  </Typography>
                </Box> */}
              </motion.form>
            </CardContent>
          </Card>
        </motion.div>

        {/* Footer */}
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ delay: 0.5, duration: 0.5 }}
        >
          <Box sx={{ textAlign: 'center', marginTop: 3 }}>
            <Typography variant="body2" color="#475569" sx={{ opacity: 0.8 }}>
              © {year} Municipality of {headerMunicipality}. All rights reserved.
            </Typography>
          </Box>
          <Box sx={{ textAlign: 'center', marginTop: 3 }}>
          <Typography variant="body2" color="#475569" sx={{ opacity: 0.8 }}>
              Developed by:{' '}
            </Typography>
            <Typography variant="body2" color="#475569" sx={{ opacity: 0.8, marginTop: 2 }}>
              <a
                href="https://github.com/toryang2"
                target="_blank"
                rel="noopener noreferrer"
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  textDecoration: 'none',
                  color: '#475569'
                }}
              >
                <img
                  src="https://github.com/toryang2.png"
                  alt="GitHub Profile"
                  style={{ width: 40, height: 40, borderRadius: '50%', marginRight: 6 }}
                />
              </a>
            </Typography>
          </Box>
        </motion.div>
      </Container>

      <style>{`
        @keyframes pulse {
          0% { transform: scale(1); opacity: 0.1; }
          50% { transform: scale(1.05); opacity: 0.2; }
          100% { transform: scale(1); opacity: 0.1; }
        }
      `}</style>

      <Snackbar
        open={snackbar.open || !!error}
        autoHideDuration={4000}
        onClose={() => setSnackbar(prev => ({ ...prev, open: false }))}
        anchorOrigin={{ vertical: 'top', horizontal: 'center' }}
      >
        <Alert onClose={() => setSnackbar(prev => ({ ...prev, open: false }))} severity="error" sx={{ width: '100%' }}>
          {snackbar.message || error || 'Login failed'}
        </Alert>
      </Snackbar>
    </Box>
  );
};

export default Login;
