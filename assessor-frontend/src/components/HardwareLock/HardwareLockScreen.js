import React, { useState, useEffect } from 'react';
import { Box, Paper, Typography, TextField, Button, Alert, CircularProgress, IconButton, Tooltip } from '@mui/material';
import { ContentCopy as ContentCopyIcon, Lock as LockIcon } from '@mui/icons-material';
import { apiService } from '../../utils/api';

const HardwareLockScreen = ({ onUnlocked }) => {
  const [hardwareId, setHardwareId] = useState('');
  const [activationKey, setActivationKey] = useState('');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    fetchStatus();
  }, []);

  const fetchStatus = async () => {
    try {
      setLoading(true);
      const status = await apiService.getHardwareLockStatus();
      if (status && status.hardware_id) {
        setHardwareId(status.hardware_id);
      }
      setLoading(false);
    } catch (err) {
      setError('Could not fetch hardware lock status. Is the server running?');
      setLoading(false);
    }
  };

  const handleCopy = () => {
    navigator.clipboard.writeText(hardwareId);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!activationKey.trim()) {
      setError('Please enter an activation key.');
      return;
    }

    try {
      setSubmitting(true);
      setError('');
      await apiService.activateHardwareLock(activationKey);

      // Clear global locked state and reload app
      window.dispatchEvent(new Event('hardware_unlocked'));
      if (onUnlocked) {
        onUnlocked();
      } else {
        window.location.reload();
      }
    } catch (err) {
      setError(err.message || 'Invalid Activation Key. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <Box sx={{
        position: 'fixed', top: 0, left: 0, right: 0, bottom: 0, zIndex: 9999,
        display: 'flex', justifyContent: 'center', alignItems: 'center', bgcolor: '#f4f6f8'
      }}>
        <CircularProgress />
      </Box>
    );
  }

  return (
    <Box
      sx={{
        position: 'fixed', top: 0, left: 0, right: 0, bottom: 0, zIndex: 9999,
        display: 'flex',
        justifyContent: 'center',
        alignItems: 'center',
        bgcolor: '#f4f6f8',
        p: 3
      }}
    >
      <Paper elevation={3} sx={{ p: 5, maxWidth: 500, width: '100%', textAlign: 'center', borderRadius: 2 }}>
        <Box sx={{ display: 'flex', justifyContent: 'center', mb: 2, color: 'primary.main' }}>
          <LockIcon sx={{ fontSize: 60 }} />
        </Box>

        <Typography variant="h4" gutterBottom fontWeight="bold" color="text.primary">
          Application Locked
        </Typography>

        <Typography variant="body1" color="text.secondary" paragraph sx={{ mb: 4 }}>
          This installation of the Assessor Archiving System must be activated to run on this machine. Please provide the Hardware ID below to your administrator to receive an Activation Key.
        </Typography>

        {error && (
          <Alert severity="error" sx={{ mb: 3, textAlign: 'left' }}>
            {error}
          </Alert>
        )}

        <Box sx={{ mb: 4, textAlign: 'left' }}>
          <Typography variant="subtitle2" color="text.secondary" gutterBottom>
            Hardware ID
          </Typography>
          <Box sx={{ display: 'flex', alignItems: 'center' }}>
            <TextField
              fullWidth
              value={hardwareId}
              variant="outlined"
              InputProps={{
                readOnly: true,
                sx: { fontFamily: 'monospace', fontWeight: 'bold', fontSize: '1.2rem', bgcolor: '#f0f0f0' }
              }}
            />
            <Tooltip title="Copy to clipboard">
              <IconButton onClick={handleCopy} color="primary" sx={{ ml: 1, border: '1px solid', borderColor: 'divider' }}>
                <ContentCopyIcon />
              </IconButton>
            </Tooltip>
          </Box>
        </Box>

        <form onSubmit={handleSubmit}>
          <Box sx={{ mb: 3, textAlign: 'left' }}>
            <Typography variant="subtitle2" color="text.secondary" gutterBottom>
              Activation Key
            </Typography>
            <TextField
              fullWidth
              value={activationKey}
              onChange={(e) => setActivationKey(e.target.value)}
              placeholder="Enter 16-character Activation Key"
              variant="outlined"
              disabled={submitting}
              InputProps={{
                sx: { fontFamily: 'monospace', fontWeight: 'bold' }
              }}
            />
          </Box>

          <Button
            type="submit"
            variant="contained"
            color="primary"
            fullWidth
            size="large"
            disabled={submitting || !activationKey.trim()}
            sx={{ py: 1.5, fontSize: '1.1rem', fontWeight: 'bold' }}
          >
            {submitting ? <CircularProgress size={24} color="inherit" /> : 'Activate Application'}
          </Button>
        </form>
      </Paper>
    </Box>
  );
};

export default HardwareLockScreen;
