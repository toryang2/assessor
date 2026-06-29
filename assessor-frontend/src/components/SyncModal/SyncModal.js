import React from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Typography,
  Box,
  CircularProgress,
  IconButton,
  Alert
} from '@mui/material';
import { Close as CloseIcon } from '@mui/icons-material';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuth } from '../../contexts/AuthContext';
import AnimatedCloudIcon from '../AnimatedCloudIcon/AnimatedCloudIcon';

const SyncModal = ({ open, onClose }) => {
  const { syncStatus, syncMessage, triggerManualSync } = useAuth();

  const handleSyncNow = () => {
    triggerManualSync();
  };

  const renderStatusIcon = () => {
    return <AnimatedCloudIcon status={syncStatus} size={64} />;
  };

  const renderStatusText = () => {
    switch (syncStatus) {
      case 'syncing':
        return 'Synchronizing with Live Server...';
      case 'success':
        return 'Synchronization Complete';
      case 'failed':
        return 'Synchronization Failed';
      case 'incomplete':
        return 'Synchronization Partially Complete';
      default:
        return 'Ready to Sync';
    }
  };

  return (
    <Dialog 
      open={open} 
      onClose={onClose}
      maxWidth="sm"
      fullWidth
      PaperProps={{
        sx: {
          borderRadius: 2,
          boxShadow: '0 8px 32px rgba(0,0,0,0.1)'
        }
      }}
    >
      <DialogTitle sx={{ m: 0, p: 2, pb: 1, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <Typography variant="h6" fontWeight={600}>
          Data Synchronization
        </Typography>
        <IconButton onClick={onClose} size="small" disabled={syncStatus === 'syncing'}>
          <CloseIcon />
        </IconButton>
      </DialogTitle>
      
      <DialogContent sx={{ p: 4 }}>
        <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center', py: 3 }}>
          
          <Box sx={{ height: 80, display: 'flex', alignItems: 'center', justifyContent: 'center', mb: 2 }}>
            <AnimatePresence mode="wait">
              <motion.div
                key={syncStatus}
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -10 }}
                transition={{ duration: 0.2 }}
              >
                {renderStatusIcon()}
              </motion.div>
            </AnimatePresence>
          </Box>

          <Typography variant="h6" gutterBottom color="text.primary" align="center">
            {renderStatusText()}
          </Typography>

          {syncStatus === 'idle' && (
            <Typography variant="body2" color="text.secondary" align="center" sx={{ maxWidth: '80%' }}>
              Click "Sync Now" to manually push local changes and pull the latest updates from the live server. Background sync runs automatically every 5 minutes.
            </Typography>
          )}

          {syncMessage && syncStatus !== 'idle' && (
            <Alert 
              severity={syncStatus === 'failed' ? 'error' : (syncStatus === 'success' ? 'success' : 'warning')} 
              sx={{ mt: 3, width: '100%', borderRadius: 2 }}
            >
              {syncMessage}
            </Alert>
          )}
          
        </Box>
      </DialogContent>
      
      <DialogActions sx={{ px: 3, py: 2, borderTop: '1px solid', borderColor: 'divider' }}>
        <Button 
          onClick={onClose} 
          color="inherit" 
          disabled={syncStatus === 'syncing'}
        >
          Close
        </Button>
        <Button 
          onClick={handleSyncNow} 
          variant="contained" 
          color="primary"
          disabled={syncStatus === 'syncing'}
          startIcon={syncStatus === 'syncing' ? <CircularProgress size={20} color="inherit" /> : <AnimatedCloudIcon status="idle" size={20} />}
          sx={{ borderRadius: 2, px: 3 }}
        >
          {syncStatus === 'syncing' ? 'Syncing...' : 'Sync Now'}
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default SyncModal;
