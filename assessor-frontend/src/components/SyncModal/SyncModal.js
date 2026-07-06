import React, { useState, useEffect } from 'react';
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
  Divider,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Paper,
  Chip
} from '@mui/material';
import { Close as CloseIcon, CloudSync as CloudSyncIcon } from '@mui/icons-material';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuth } from '../../contexts/AuthContext';
import { apiService } from '../../utils/api';
import AnimatedCloudIcon from '../AnimatedCloudIcon/AnimatedCloudIcon';

const LiveServerDashboard = ({ syncConfig }) => {
  const [auditLogs, setAuditLogs] = useState([]);
  const [loadingLogs, setLoadingLogs] = useState(true);

  useEffect(() => {
    setLoadingLogs(true);
    apiService.getAuditTrail({ action: 'SYNC_FROM_LOCAL', per_page: 10 })
      .then(res => {
        setAuditLogs(res.logs || []);
      })
      .catch(err => {
        console.error("Failed to fetch audit logs:", err);
      })
      .finally(() => {
        setLoadingLogs(false);
      });
  }, []);

  const formatDate = (dateString) => {
    if (!dateString) return 'Never';
    return new Date(dateString).toLocaleString('en-US', {
      month: 'short', day: 'numeric', year: 'numeric',
      hour: 'numeric', minute: '2-digit', hour12: true
    });
  };

  return (
    <Box sx={{ width: '100%', mt: 1 }}>
      <Alert severity="info" sx={{ mb: 3, borderRadius: 2 }}>
        This is the Live Server. Data synchronization is managed from the Local Server. This dashboard displays incoming synchronization activity.
      </Alert>

      <Box sx={{ display: 'flex', gap: 2, mb: 3 }}>
        <Paper elevation={0} sx={{ p: 2, flex: 1, border: '1px solid', borderColor: 'divider', borderRadius: 2, bgcolor: 'background.default' }}>
          <Typography variant="caption" color="text.secondary" fontWeight={600} display="block" gutterBottom>
            LAST LOCAL PUSH (UPLOADS TO LIVE)
          </Typography>
          <Typography variant="body1" fontWeight={500}>
            {formatDate(syncConfig?.last_local_push)}
          </Typography>
        </Paper>
        <Paper elevation={0} sx={{ p: 2, flex: 1, border: '1px solid', borderColor: 'divider', borderRadius: 2, bgcolor: 'background.default' }}>
          <Typography variant="caption" color="text.secondary" fontWeight={600} display="block" gutterBottom>
            LAST LOCAL PULL (DOWNLOADS FROM LIVE)
          </Typography>
          <Typography variant="body1" fontWeight={500}>
            {formatDate(syncConfig?.last_local_pull)}
          </Typography>
        </Paper>
      </Box>

      <Typography variant="subtitle2" color="text.primary" gutterBottom fontWeight={600}>
        Recent Synced Properties (Audit Trail)
      </Typography>
      
      <TableContainer component={Paper} elevation={0} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 2, maxHeight: 250 }}>
        <Table size="small" stickyHeader>
          <TableHead>
            <TableRow>
              <TableCell sx={{ bgcolor: 'background.default', fontWeight: 600 }}>Timestamp</TableCell>
              <TableCell sx={{ bgcolor: 'background.default', fontWeight: 600 }}>Table</TableCell>
              <TableCell sx={{ bgcolor: 'background.default', fontWeight: 600 }}>Record ID</TableCell>
              <TableCell sx={{ bgcolor: 'background.default', fontWeight: 600 }}>Details</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {loadingLogs ? (
              <TableRow>
                <TableCell colSpan={4} align="center" sx={{ py: 3 }}>
                  <CircularProgress size={24} />
                </TableCell>
              </TableRow>
            ) : auditLogs.length === 0 ? (
              <TableRow>
                <TableCell colSpan={4} align="center" sx={{ py: 3, color: 'text.secondary' }}>
                  No recent synchronization activity found.
                </TableCell>
              </TableRow>
            ) : (
              auditLogs.map((log) => (
                <TableRow key={log.id}>
                  <TableCell>{formatDate(log.created_at)}</TableCell>
                  <TableCell>
                    <Chip label={log.table_name.replace('assessor_', '')} size="small" variant="outlined" />
                  </TableCell>
                  <TableCell>{log.record_id}</TableCell>
                  <TableCell>
                    {log.new_values && JSON.parse(log.new_values)?.tax_declaration_number ? 
                      `TDN: ${JSON.parse(log.new_values).tax_declaration_number}` : 'Synced'}
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </TableContainer>
    </Box>
  );
};

const SyncModal = ({ open, onClose }) => {
  const { syncStatus, syncMessage, triggerManualSync } = useAuth();
  const [syncConfig, setSyncConfig] = useState(null);
  const [loadingConfig, setLoadingConfig] = useState(true);

  useEffect(() => {
    if (open) {
      setLoadingConfig(true);
      apiService.getSyncConfig()
        .then(config => setSyncConfig(config))
        .catch(err => console.error("Failed to load sync config:", err))
        .finally(() => setLoadingConfig(false));
    }
  }, [open]);

  const handleSyncNow = () => triggerManualSync();
  const handleFullResync = () => triggerManualSync(true);

  const renderStatusIcon = () => <AnimatedCloudIcon status={syncStatus} size={64} />;

  const renderStatusText = () => {
    switch (syncStatus) {
      case 'syncing': return 'Synchronizing with Live Server...';
      case 'success': return 'Synchronization Complete';
      case 'failed': return 'Synchronization Failed';
      case 'incomplete': return 'Synchronization Partially Complete';
      default: return 'Ready to Sync';
    }
  };

  const isSyncing = syncStatus === 'syncing';
  const isLocalBuild = syncConfig?.is_local_build !== false; // Default to true if not loaded to prevent flicker

  return (
    <Dialog 
      open={open} 
      onClose={onClose}
      maxWidth={isLocalBuild ? "sm" : "md"}
      fullWidth
      PaperProps={{
        sx: { borderRadius: 2, boxShadow: '0 8px 32px rgba(0,0,0,0.1)' }
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
      
      <DialogContent sx={{ p: isLocalBuild ? 4 : 3 }}>
        {loadingConfig ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', py: 5 }}>
            <CircularProgress />
          </Box>
        ) : !isLocalBuild ? (
          <LiveServerDashboard syncConfig={syncConfig} />
        ) : (
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
        )}
      </DialogContent>
      
      <DialogActions sx={{ px: 3, py: 2, borderTop: '1px solid', borderColor: 'divider', gap: 1 }}>
        <Button onClick={onClose} color="inherit" disabled={isSyncing} sx={{ mr: 'auto' }}>
          Close
        </Button>

        {isLocalBuild && (
          <>
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
          </>
        )}
      </DialogActions>
    </Dialog>
  );
};

export default SyncModal;
