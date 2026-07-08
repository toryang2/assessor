import React, { useState, useEffect, useRef } from 'react';
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
  Chip,
  Grid,
  LinearProgress
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

  // File download state
  const [fileDownloadStatus, setFileDownloadStatus] = useState(null); // { missing_files }
  const [fileDownloadStatusLoading, setFileDownloadStatusLoading] = useState(false);
  const [isDownloading, setIsDownloading] = useState(false);
  const [downloadProgress, setDownloadProgress] = useState({ downloaded: 0, failed: 0, remaining: 0, total: 0, isFetching: false });
  const downloadAbortRef = useRef(false);

  useEffect(() => {
    if (open) {
      setLoadingConfig(true);
      apiService.getSyncConfig()
        .then(config => setSyncConfig(config))
        .catch(err => console.error("Failed to load sync config:", err))
        .finally(() => setLoadingConfig(false));
    }
  }, [open]);

  const loadDownloadStatus = async () => {
    setFileDownloadStatusLoading(true);
    try {
      const res = await apiService.getDownloadStatus();
      setFileDownloadStatus(res);
      setDownloadProgress(prev => ({ ...prev, remaining: res.missing_files, total: res.missing_files }));
    } catch (e) {
      console.error('Failed to load download status', e);
    }
    setFileDownloadStatusLoading(false);
  };

  useEffect(() => {
    if (open && syncConfig?.is_local_build) {
      loadDownloadStatus();
    }
  }, [open, syncConfig]);

  const handleBulkDownload = async () => {
    if (isDownloading) return;
    setIsDownloading(true);
    setDownloadProgress({ downloaded: 0, failed: 0, remaining: 0, total: 0, isFetching: true });
    downloadAbortRef.current = false;
    
    let missingFiles = [];
    try {
      const data = await apiService.getMissingFilesList();
      missingFiles = data.missing_files || [];
    } catch (e) {
      console.error(`Error fetching files: ${e.message}`);
      setIsDownloading(false);
      return;
    }

    let currentRemaining = missingFiles.length;
    if (currentRemaining === 0) {
      await loadDownloadStatus();
      currentRemaining = downloadProgress.remaining;
    }
    
    setDownloadProgress({ downloaded: 0, failed: 0, remaining: currentRemaining, total: currentRemaining, isFetching: false });

    const concurrency = 5;
    let currentIndex = 0;

    const downloadNext = async () => {
      if (downloadAbortRef.current) return;
      
      const idx = currentIndex++;
      if (idx >= missingFiles.length) return;
      
      const file = missingFiles[idx];
      
      try {
        const res = await apiService.downloadBatch([file]);
        
        const downloadedThis = res.downloaded || 0;
        const failedThis = res.failed || 0;
        
        setDownloadProgress(prev => ({
          ...prev,
          downloaded: prev.downloaded + downloadedThis,
          failed: prev.failed + failedThis,
          remaining: Math.max(0, prev.remaining - 1)
        }));
        
        setFileDownloadStatus(prev => ({ 
          ...prev, 
          missing_files: Math.max(0, (prev?.missing_files || 0) - 1) 
        }));

      } catch (e) {
        console.error(`Error during download: ${e.message}`);
        // Count as failed
        setDownloadProgress(prev => ({
          ...prev,
          failed: prev.failed + 1,
          remaining: Math.max(0, prev.remaining - 1)
        }));
      }
      
      // Queue next
      await downloadNext();
    };
    
    // Start workers
    const workers = [];
    for (let w = 0; w < concurrency; w++) {
      workers.push(downloadNext());
    }
    
    await Promise.all(workers);
    
    setIsDownloading(false);
  };

  const stopBulkDownload = () => {
    downloadAbortRef.current = true;
  };

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

            <Divider sx={{ width: '100%', mt: 3, mb: 2 }} />

            <Box sx={{ width: '100%', px: 1 }}>
              <Typography variant="h6" gutterBottom>Bulk Image Downloader</Typography>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                This tool uses concurrent background workers to continuously download all missing images one by one at maximum speed.
              </Typography>
              
              <Paper variant="outlined" sx={{ p: 2 }}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2, flexWrap: 'wrap', gap: 2 }}>
                  <Box>
                    <Typography variant="subtitle2">Files Missing Locally:</Typography>
                    <Typography variant="h5">
                      {fileDownloadStatusLoading ? <CircularProgress size={24} /> : (fileDownloadStatus?.missing_files ?? '?')}
                    </Typography>
                  </Box>
                  <Box sx={{ display: 'flex', gap: 1 }}>
                    <Button 
                      variant="outlined" 
                      onClick={loadDownloadStatus} 
                      disabled={fileDownloadStatusLoading || isDownloading}
                      size="small"
                    >
                      Refresh Count
                    </Button>
                    {!isDownloading ? (
                      <Button 
                        variant="contained" 
                        color="primary" 
                        onClick={handleBulkDownload}
                        disabled={!fileDownloadStatus || fileDownloadStatus.missing_files === 0}
                      >
                        Start Bulk Download
                      </Button>
                    ) : (
                      <Button 
                        variant="contained" 
                        color="error" 
                        onClick={stopBulkDownload}
                      >
                        Stop Downloading
                      </Button>
                    )}
                  </Box>
                </Box>

                {/* Progress Stats */}
                {downloadProgress.total > 0 && (
                  <Box sx={{ mb: 2, p: 2, bgcolor: 'grey.50', borderRadius: 1 }}>
                    <Grid container spacing={2}>
                      <Grid item xs={4}>
                        <Typography variant="caption" color="text.secondary">Downloaded</Typography>
                        <Typography variant="body1" color="success.main" fontWeight="bold">{downloadProgress.downloaded}</Typography>
                      </Grid>
                      <Grid item xs={4}>
                        <Typography variant="caption" color="text.secondary">Failed</Typography>
                        <Typography variant="body1" color="error.main" fontWeight="bold">{downloadProgress.failed}</Typography>
                      </Grid>
                      <Grid item xs={4}>
                        <Typography variant="caption" color="text.secondary">Remaining</Typography>
                        <Typography variant="body1" fontWeight="bold">{downloadProgress.remaining}</Typography>
                      </Grid>
                    </Grid>
                    
                    {/* Linear Progress Bar */}
                    <Box sx={{ mt: 2, display: 'flex', alignItems: 'center' }}>
                      <Box sx={{ width: '100%', mr: 1 }}>
                        <LinearProgress 
                          variant="determinate" 
                          value={downloadProgress.total > 0 ? Math.min(100, Math.round(((downloadProgress.total - downloadProgress.remaining) / downloadProgress.total) * 100)) : 0} 
                          sx={{ height: 8, borderRadius: 4 }}
                        />
                      </Box>
                      <Box sx={{ minWidth: 35 }}>
                        <Typography variant="body2" color="text.secondary">
                          {downloadProgress.total > 0 ? Math.min(100, Math.round(((downloadProgress.total - downloadProgress.remaining) / downloadProgress.total) * 100)) : 0}%
                        </Typography>
                      </Box>
                    </Box>
                  </Box>
                )}

                {/* Single Status Line */}
                {(isDownloading || downloadProgress.downloaded > 0 || downloadProgress.failed > 0) && (
                  <Box sx={{ 
                    mt: 2,
                    p: 2, 
                    bgcolor: '#f5f5f5', 
                    borderRadius: 1,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 2
                  }}>
                    {isDownloading && <CircularProgress size={20} />}
                    <Typography variant="body2" sx={{ fontWeight: 500, color: 'text.secondary' }}>
                      {downloadProgress.isFetching ? 
                        "Scanning database and files to generate download list. This may take a moment..." : 
                        `Downloaded ${downloadProgress.downloaded} files, ${downloadProgress.failed} failed. ${downloadProgress.remaining} remaining.`
                      }
                    </Typography>
                  </Box>
                )}
              </Paper>
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
