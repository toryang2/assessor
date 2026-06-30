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
  Alert,
  Tooltip,
  Divider
} from '@mui/material';
import { Close as CloseIcon, CloudSync as CloudSyncIcon } from '@mui/icons-material';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuth } from '../../contexts/AuthContext';
import AnimatedCloudIcon from '../AnimatedCloudIcon/AnimatedCloudIcon';

const SyncModal = ({ open, onClose }) => {
  const { syncStatus, syncMessage, triggerManualSync } = useAuth();

  const handleSyncNow = () => {
    triggerManualSync();
  };

  const handleFullResync = () => {
    triggerManualSync(true);
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

  const isSyncing = syncStatus === 'syncing';

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
        <IconButton onClick={onClose} size="small" disabled={isSyncing}>
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
              Click <strong>Sync Now</strong> to push local changes and pull recent updates from the live server. Background sync runs automatically every 5 minutes.
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

          <Divider sx={{ width: '100%', mt: 3, mb: 2 }} />

          <Box sx={{ width: '100%', px: 1 }}>
            <Typography variant="caption" color="text.secondary" display="block" gutterBottom>
              <strong>Full Resync</strong> — Use this if local data is missing records from the live server (e.g. first-time setup or incomplete initial sync). This re-downloads <em>all</em> properties from the live site, which may take a few minutes.
            </Typography>
          </Box>
          
        </Box>
      </DialogContent>
      
      <DialogActions sx={{ px: 3, py: 2, borderTop: '1px solid', borderColor: 'divider', gap: 1 }}>
        <Button 
          onClick={onClose} 
          color="inherit" 
          disabled={isSyncing}
          sx={{ mr: 'auto' }}
        >
          Close
        </Button>

        <Tooltip title="Re-download ALL records from the live site. Use when local data is incomplete." arrow>
          <span>
            <Button
              onClick={handleFullResync}
              variant="outlined"
              color="warning"
              disabled={isSyncing}
              startIcon={isSyncing ? <CircularProgress size={18} color="inherit" /> : <CloudSyncIcon />}
              sx={{ borderRadius: 2, px: 2 }}
            >
              Full Resync
            </Button>
          </span>
        </Tooltip>

        <Button 
          onClick={handleSyncNow} 
          variant="contained" 
          color="primary"
          disabled={isSyncing}
          startIcon={isSyncing ? <CircularProgress size={20} color="inherit" /> : <AnimatedCloudIcon status="idle" size={20} />}
          sx={{ borderRadius: 2, px: 3 }}
        >
          {isSyncing ? 'Syncing...' : 'Sync Now'}
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default SyncModal;
