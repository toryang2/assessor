import React, { useEffect, useRef, useState } from 'react';
import {
  Avatar,
  Box,
  TextField,
  Button,
  Grid,
  Typography,
  Divider,
  Alert,
  Snackbar
} from '@mui/material';
import {
  Save as SaveIcon,
  Cancel as CancelIcon
} from '@mui/icons-material';
import { useAuth } from '../../contexts/AuthContext';
import { apiService } from '../../utils/api';

const EditProfile = ({ onSave, onCancel }) => {
  const { user, updateUserLocally, refreshCurrentUser } = useAuth();
  const [formData, setFormData] = useState({
    username: user?.username || '',
    email: user?.email || '',
    full_name: user?.full_name || '',
    password: '',
    password_confirm: ''
  });
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);
  const [toast, setToast] = useState({ open: false, message: '', severity: 'success' });
  const [avatarPreview, setAvatarPreview] = useState(user?.avatar_url || '');
  const [avatarFile, setAvatarFile] = useState(null);
  const previewUrlRef = useRef(null);

  useEffect(() => {
    if (!avatarFile) {
      setAvatarPreview(user?.avatar_url || '');
    }
  }, [user?.avatar_url, avatarFile]);

  const handleChange = (field) => (e) => {
    const value = e.target.value;
    setFormData(prev => ({ ...prev, [field]: value }));
    // Clear error for this field when user starts typing
    if (errors[field]) {
      setErrors(prev => ({ ...prev, [field]: '' }));
    }
  };

  const validate = () => {
    const newErrors = {};

    if (!formData.full_name.trim()) {
      newErrors.full_name = 'Full name is required';
    }

    if (formData.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
      newErrors.email = 'Invalid email format';
    }

    if (formData.password || formData.password_confirm) {
      if (formData.password.length < 6) {
        newErrors.password = 'Password must be at least 6 characters';
      } else if (formData.password !== formData.password_confirm) {
        newErrors.password_confirm = 'Passwords do not match';
      }
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    if (!validate()) {
      return;
    }

    setLoading(true);
    try {
      const payload = {
        email: formData.email.trim() || '',
        full_name: formData.full_name.trim()
      };

      // Only include password if it's provided
      if (formData.password) {
        payload.password = formData.password;
        payload.password_confirm = formData.password_confirm;
      }

      if (avatarFile) {
        const response = await apiService.uploadUserAvatar(user.id, avatarFile);
        const uploadedAvatarUrl = response?.avatar_url || null;
        if (uploadedAvatarUrl) {
          updateUserLocally({ avatar_url: uploadedAvatarUrl });
          setAvatarPreview(uploadedAvatarUrl);
          setAvatarFile(null);
          if (previewUrlRef.current) {
            URL.revokeObjectURL(previewUrlRef.current);
            previewUrlRef.current = null;
          }
        }
      }

      await apiService.updateUser(user.id, payload);
      updateUserLocally({
        email: payload.email || null,
        full_name: payload.full_name
      });
      await refreshCurrentUser();
      
      setToast({ 
        open: true, 
        message: 'Profile updated successfully', 
        severity: 'success' 
      });

      // Call onSave callback after a short delay to show the success message
      setTimeout(() => {
        if (onSave) {
          onSave();
        }
      }, 500);
    } catch (error) {
      const errorMessage = error.message || 'Failed to update profile';
      setToast({ 
        open: true, 
        message: errorMessage, 
        severity: 'error' 
      });
    } finally {
      setLoading(false);
    }
  };

  const handleAvatarSelect = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    if (previewUrlRef.current) {
      URL.revokeObjectURL(previewUrlRef.current);
      previewUrlRef.current = null;
    }
    const objectUrl = URL.createObjectURL(file);
    previewUrlRef.current = objectUrl;
    setAvatarPreview(objectUrl);
    setAvatarFile(file);
  };

  const avatarInitial = user?.full_name?.charAt(0) || user?.username?.charAt(0) || 'U';

  return (
    <Box>
      <form onSubmit={handleSubmit}>
        <Grid container spacing={2} sx={{ mt: 1 }}>
          <Grid item xs={12}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
              <Avatar
                src={avatarPreview || user?.avatar_url || undefined}
                sx={{ 
                  width: 72, 
                  height: 72, 
                  bgcolor: (avatarPreview || user?.avatar_url) ? '#fff' : 'primary.main', 
                  fontSize: '1.75rem'
                }}
              >
                {(!avatarPreview && !user?.avatar_url) && avatarInitial}
              </Avatar>
              <Box>
                <Button
                  variant="outlined"
                  component="label"
                >
                  Choose Photo
                  <input type="file" accept="image/*" hidden onChange={handleAvatarSelect} />
                </Button>
                <Typography variant="caption" color="text.secondary" display="block" sx={{ mt: 0.5 }}>
                  JPG, PNG, GIF, or WEBP up to 5MB.
                </Typography>
              </Box>
            </Box>
          </Grid>

          <Grid item xs={12} md={6}>
            <TextField
              fullWidth
              label="Username"
              value={formData.username}
              disabled
              helperText="Username cannot be changed"
              required
            />
          </Grid>

          <Grid item xs={12} md={6}>
            <TextField
              fullWidth
              label="Email"
              type="email"
              value={formData.email}
              onChange={handleChange('email')}
              error={!!errors.email}
              helperText={errors.email}
            />
          </Grid>

          <Grid item xs={12}>
            <TextField
              fullWidth
              label="Full Name"
              value={formData.full_name}
              onChange={handleChange('full_name')}
              error={!!errors.full_name}
              helperText={errors.full_name}
              required
            />
          </Grid>

          <Grid item xs={12}>
            <Divider sx={{ my: 2 }}>
              <Typography variant="body2" color="text.secondary">
                Change Password (Optional)
              </Typography>
            </Divider>
          </Grid>

          <Grid item xs={12} md={6}>
            <TextField
              fullWidth
              label="New Password"
              type="password"
              value={formData.password}
              onChange={handleChange('password')}
              error={!!errors.password}
              helperText={errors.password || 'Leave blank to keep current password'}
            />
          </Grid>

          <Grid item xs={12} md={6}>
            <TextField
              fullWidth
              label="Confirm New Password"
              type="password"
              value={formData.password_confirm}
              onChange={handleChange('password_confirm')}
              error={!!errors.password_confirm}
              helperText={errors.password_confirm}
            />
          </Grid>
        </Grid>

        <Box sx={{ display: 'flex', justifyContent: 'flex-end', gap: 2, mt: 3 }}>
          <Button
            onClick={onCancel}
            variant="outlined"
            startIcon={<CancelIcon />}
            disabled={loading}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="contained"
            startIcon={<SaveIcon />}
            disabled={loading}
          >
            {loading ? 'Saving...' : 'Save Changes'}
          </Button>
        </Box>
      </form>

      <Snackbar
        open={toast.open}
        autoHideDuration={6000}
        onClose={() => setToast({ ...toast, open: false })}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
      >
        <Alert 
          onClose={() => setToast({ ...toast, open: false })} 
          severity={toast.severity}
          sx={{ width: '100%' }}
        >
          {toast.message}
        </Alert>
      </Snackbar>
    </Box>
  );
};

export default EditProfile;

