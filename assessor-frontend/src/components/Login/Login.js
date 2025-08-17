import React, { useState, useEffect } from 'react';

import { motion } from 'framer-motion';
import {
  Box,
  Card,
  CardContent,
  TextField,
  Button,
  Typography,
  Alert,
  InputAdornment,
  IconButton,
  Container
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

const Login = () => {
  
  const { login, error, clearError } = useAuth();
  
  const [formData, setFormData] = useState({
    username: '',
    password: ''
  });
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [validationErrors, setValidationErrors] = useState({});

  // Redirect if already authenticated


  // Clear error when component mounts
  useEffect(() => {
    clearError();
  }, [clearError]);

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
    setLoading(true);
    
    try {
      console.log('🔍 Login Component: Calling login function...');
      const result = await login(formData);
      console.log('🔍 Login Component: Login result:', result);
      
      if (result.success) {
        console.log('✅ Login Component: Login successful');
      } else {
        console.log('❌ Login Component: Login failed:', result.error);
      }
    } catch (error) {
      console.error('❌ Login Component: Login error:', error);
    } finally {
      setLoading(false);
    }
  };

  const togglePasswordVisibility = () => {
    setShowPassword(!showPassword);
  };

  return (
    <Box
      sx={{
        minHeight: '100vh',
        background: '#f0f4f8',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 2
      }}
    >
      <Container maxWidth="sm">
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
              position: 'relative'
            }}
          >
            {/* Header with Philippine Government branding */}
            <Box
              sx={{
                padding: 4,
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
                  <Business sx={{ fontSize: 60, marginBottom: 2, opacity: 0.8 }} />
                </motion.div>
                
                <motion.div
                  variants={{
                    initial: { opacity: 0, y: 20 },
                    animate: { opacity: 1, y: 0, transition: { duration: 0.6, ease: "easeOut" } }
                  }}
                >
                  <Typography variant="h4" component="h1" gutterBottom sx={{ fontWeight: 600 }}>
                    Philippine Local Government
                  </Typography>
                </motion.div>
                
                <motion.div
                  variants={{
                    initial: { opacity: 0, y: 20 },
                    animate: { opacity: 1, y: 0, transition: { duration: 0.6, ease: "easeOut" } }
                  }}
                >
                  <Typography variant="h6" component="h2" sx={{ fontWeight: 500 }}>
                    Property Assessor System
                  </Typography>
                </motion.div>
                
                <motion.div
                  variants={{
                    initial: { opacity: 0, y: 20 },
                    animate: { opacity: 1, y: 0, transition: { duration: 0.6, ease: "easeOut" } }
                  }}
                >
                  <Typography variant="body1" sx={{ marginTop: 1, opacity: 0.8 }}>
                    Comprehensive History Archiving & Management
                  </Typography>
                </motion.div>
              </motion.div>
            </Box>

            <CardContent sx={{ padding: 4 }}>
              <motion.form
                onSubmit={handleSubmit}
                initial="initial"
                animate="animate"
                variants={animations.fadeIn}
              >
                <Typography variant="h5" component="h3" gutterBottom sx={{ textAlign: 'center', marginBottom: 3, fontWeight: 600 }}>
                  Sign In
                </Typography>

                {error && (
                  <motion.div
                    initial={{ opacity: 0, y: -10 }}
                    animate={{ opacity: 1, y: 0 }}
                  >
                    <Alert severity="error" sx={{ marginBottom: 3 }}>
                      {error}
                    </Alert>
                  </motion.div>
                )}

                <TextField
                  fullWidth
                  label="Username"
                  name="username"
                  value={formData.username}
                  onChange={handleInputChange}
                  error={!!validationErrors.username}
                  helperText={validationErrors.username}
                  margin="normal"
                  variant="outlined"
                  InputProps={{
                    startAdornment: (
                      <InputAdornment position="start">
                        <AccountCircle color="primary" />
                      </InputAdornment>
                    ),
                  }}
                  sx={{ marginBottom: 2 }}
                />

                <TextField
                  fullWidth
                  label="Password"
                  name="password"
                  type={showPassword ? 'text' : 'password'}
                  value={formData.password}
                  onChange={handleInputChange}
                  error={!!validationErrors.password}
                  helperText={validationErrors.password}
                  margin="normal"
                  variant="outlined"
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
                  sx={{ marginBottom: 3 }}
                />

                <motion.div
                  whileHover={{ scale: 1.02 }}
                  whileTap={{ scale: 0.98 }}
                >
                  <Button
                    type="submit"
                    fullWidth
                    variant="contained"
                    size="large"
                    disabled={loading}
                    sx={{
                      height: 56,
                      fontSize: '1.1rem',
                      fontWeight: 600,
                      background: theme.palette.primary.main,
                      '&:hover': {
                        background: theme.palette.primary.dark,
                        transform: 'translateY(-2px)',
                        boxShadow: '0 8px 25px rgba(37, 99, 235, 0.3)'
                      }
                    }}
                  >
                    {loading ? 'Signing In...' : 'Sign In'}
                  </Button>
                </motion.div>

                <Box sx={{ marginTop: 3, textAlign: 'center' }}>
                  <Typography variant="body2" color="text.secondary">
                    Secure access to government property assessment records
                  </Typography>
                </Box>
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
              © 2024 Philippine Local Government. All rights reserved.
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
    </Box>
  );
};

export default Login;
