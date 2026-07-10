import React, { useState, useEffect, useRef } from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  Button,
  Typography,
  Box,
  CircularProgress,
  IconButton,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  LinearProgress,
  Chip,
  Divider,
  useTheme
} from '@mui/material';
import { Close as CloseIcon, CloudSync as CloudSyncIcon, InfoOutlined as InfoIcon, CheckCircleOutline as CheckCircleIcon, Error as ErrorIcon, Warning as WarningIcon, Refresh as RefreshIcon, PlayArrow as PlayIcon, Stop as StopIcon, Download as DownloadIcon } from '@mui/icons-material';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuth } from '../../contexts/AuthContext';
import { apiService } from '../../utils/api';
import AnimatedCloudIcon from '../AnimatedCloudIcon/AnimatedCloudIcon';

// ─── Animated Pulse Ring (behind icon during sync) ────────────────
const PulseRing = () => (
  <Box sx={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
    {[0, 1, 2].map(i => (
      <motion.div
        key={i}
        style={{
          position: 'absolute',
          width: 72, height: 72,
          borderRadius: '50%',
          border: '2px solid',
          borderColor: 'rgba(37, 99, 235, 0.15)',
        }}
        initial={{ scale: 0.8, opacity: 0.5 }}
        animate={{ scale: 1.8, opacity: 0 }}
        transition={{
          duration: 2.4,
          delay: i * 0.8,
          repeat: Infinity,
          ease: 'easeOut',
        }}
      />
    ))}
  </Box>
);

// ─── Live Server Dashboard ────────────────────────────────────────
const LiveServerDashboard = ({ syncConfig }) => {
  const theme = useTheme();
  const [auditLogs, setAuditLogs] = useState([]);
  const [loadingLogs, setLoadingLogs] = useState(true);

  useEffect(() => {
    setLoadingLogs(true);
    apiService.getAuditTrail({ action: 'SYNC_FROM_LOCAL', per_page: 10 })
      .then(res => setAuditLogs(res.logs || []))
      .catch(err => console.error("Failed to fetch audit logs:", err))
      .finally(() => setLoadingLogs(false));
  }, []);

  const formatDate = (dateString) => {
    if (!dateString) return 'Never';
    return new Date(dateString).toLocaleString('en-US', {
      month: 'short', day: 'numeric', year: 'numeric',
      hour: 'numeric', minute: '2-digit', hour12: true
    });
  };

  return (
    <Box sx={{ width: '100%', height: '100%', display: 'flex', flexDirection: 'column' }}>
      {/* Info banner */}
      <Box sx={{
        display: 'flex',
        alignItems: 'center',
        gap: 1.5,
        p: { xs: 1.5, sm: 2 },
        mb: { xs: 1.5, sm: 3 },
        borderRadius: 1,
        bgcolor: 'info.light',
        color: 'info.dark',
        border: `1px solid`,
        borderColor: 'info.main',
        opacity: 0.9,
      }}>
        <InfoIcon sx={{ fontSize: 20, color: 'info.main' }} />
        <Typography sx={{ fontSize: { xs: '0.75rem', sm: '0.85rem' }, fontWeight: 500, lineHeight: 1.4 }}>
          Live Server Dashboard. Data synchronization is managed directly from the Local Server.
        </Typography>
      </Box>

      {/* Last Push / Last Pull cards */}
      <Box sx={{ display: 'flex', gap: { xs: 1.5, sm: 2 }, mb: { xs: 2, sm: 3 } }}>
        {[
          { label: 'Last Push', value: formatDate(syncConfig?.last_local_push) },
          { label: 'Last Pull', value: formatDate(syncConfig?.last_local_pull) }
        ].map(card => (
          <Box key={card.label} sx={{
            flex: 1,
            p: { xs: 1.5, sm: 2.5 },
            borderRadius: 1,
            bgcolor: 'background.default',
            border: `1px solid`,
            borderColor: 'divider',
          }}>
            <Typography sx={{ fontSize: { xs: '0.65rem', sm: '0.75rem' }, fontWeight: 600, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.05em', mb: 0.5 }}>
              {card.label}
            </Typography>
            <Typography sx={{ fontSize: { xs: '0.95rem', sm: '1.1rem' }, fontWeight: 600, color: 'text.primary' }}>
              {card.value}
            </Typography>
          </Box>
        ))}
      </Box>

      {/* Recent Activity */}
      <Typography variant="h6" sx={{ fontSize: { xs: '0.9rem', sm: '1rem' }, mb: { xs: 1, sm: 1.5 } }}>
        Recent Activity
      </Typography>
      <TableContainer sx={{
        flex: 1,
        overflowY: 'auto',
        borderRadius: 1,
        border: `1px solid`,
        borderColor: 'divider',
        '&::-webkit-scrollbar': { display: 'none' },
        scrollbarWidth: 'none',
        msOverflowStyle: 'none',
      }}>
        <Table size="small" stickyHeader>
          <TableHead>
            <TableRow>
              {['Timestamp', 'Table', 'Record ID', 'Details'].map(col => (
                <TableCell key={col}>{col}</TableCell>
              ))}
            </TableRow>
          </TableHead>
          <TableBody>
            {loadingLogs ? (
              <TableRow>
                <TableCell colSpan={4} align="center" sx={{ py: 4, borderBottom: 'none' }}>
                  <CircularProgress size={20} />
                </TableCell>
              </TableRow>
            ) : auditLogs.length === 0 ? (
              <TableRow>
                <TableCell colSpan={4} align="center" sx={{ py: 4, color: 'text.secondary', fontSize: '0.85rem', borderBottom: 'none' }}>
                  No recent activity found.
                </TableCell>
              </TableRow>
            ) : (
              auditLogs.map((log) => (
                <TableRow key={log.id} sx={{
                  '&:last-child td, &:last-child th': { border: 0 },
                  '&:hover': { bgcolor: 'action.hover' },
                }}>
                  <TableCell sx={{ fontSize: '0.8rem' }}>{formatDate(log.created_at)}</TableCell>
                  <TableCell>
                    <Chip
                      label={log.table_name.replace('assessor_', '')}
                      size="small"
                      color="primary"
                      variant="outlined"
                      sx={{ fontWeight: 600, fontSize: '0.7rem' }}
                    />
                  </TableCell>
                  <TableCell sx={{ fontSize: '0.8rem' }}>{log.record_id}</TableCell>
                  <TableCell sx={{ fontSize: '0.8rem' }}>
                    {log.new_values && JSON.parse(log.new_values)?.tax_declaration_number ?
                      <Typography component="span" sx={{ fontFamily: 'monospace', fontWeight: 600, fontSize: '0.8rem' }}>
                        {JSON.parse(log.new_values).tax_declaration_number}
                      </Typography>
                       : 'Synced'}
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

// ─── Main SyncModal Component ─────────────────────────────────────
const SyncModal = ({ open, onClose }) => {
  const theme = useTheme();
  const { syncStatus, syncMessage, triggerManualSync } = useAuth();
  const [syncConfig, setSyncConfig] = useState(null);
  const [loadingConfig, setLoadingConfig] = useState(true);

  const [fileDownloadStatus, setFileDownloadStatus] = useState(null);
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
        setDownloadProgress(prev => ({
          ...prev,
          failed: prev.failed + 1,
          remaining: Math.max(0, prev.remaining - 1)
        }));
      }
      
      await downloadNext();
    };
    
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

  const renderStatusText = () => {
    switch (syncStatus) {
      case 'syncing': return 'Synchronizing...';
      case 'success': return 'In Sync';
      case 'failed': return 'Sync Failed';
      case 'incomplete': return 'Partially Synced';
      default: return 'Ready to Sync';
    }
  };

  const getStatusChipProps = () => {
    switch (syncStatus) {
      case 'syncing': return { color: 'primary', label: 'Syncing' };
      case 'success': return { color: 'success', label: 'In Sync' };
      case 'failed': return { color: 'error', label: 'Failed' };
      case 'incomplete': return { color: 'warning', label: 'Partial' };
      default: return { color: 'default', label: 'Idle' };
    }
  };

  const getStatusAlertSeverity = () => {
    switch (syncStatus) {
      case 'success': return 'success';
      case 'failed': return 'error';
      case 'incomplete': return 'warning';
      case 'syncing': return 'info';
      default: return 'info';
    }
  };

  const isSyncing = syncStatus === 'syncing';
  const isLocalBuild = syncConfig?.is_local_build !== false; 

  const progressPercent = downloadProgress.total > 0
    ? Math.min(100, Math.round(((downloadProgress.total - downloadProgress.remaining) / downloadProgress.total) * 100))
    : 0;

  const chipProps = getStatusChipProps();

  return (
    <Dialog 
      open={open} 
      onClose={onClose}
      maxWidth="sm"
      fullWidth
      PaperProps={{
        component: motion.div,
        initial: { opacity: 0, y: 20 },
        animate: { opacity: 1, y: 0 },
        transition: { duration: 0.3 },
        sx: { 
          height: { xs: '90vh', sm: '80vh', md: '700px' },
          maxHeight: '90vh',
          display: 'flex',
          flexDirection: 'column',
          overflow: 'hidden',
          m: { xs: 2, sm: 0 },
        }
      }}
    >
      {/* ─── Header (matches app's DialogTitle pattern) ──── */}
      <DialogTitle sx={{ 
        bgcolor: 'background.default', 
        color: 'text.primary',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        py: { xs: 1.5, sm: 2 },
        px: { xs: 2.5, sm: 3 },
        borderBottom: '1px solid',
        borderColor: 'divider',
        flexShrink: 0,
      }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
          <CloudSyncIcon sx={{ fontSize: { xs: 20, sm: 24 }, color: 'primary.main' }} />
          <Box>
            <Typography sx={{ fontWeight: 600, fontSize: { xs: '0.95rem', sm: '1.05rem' }, lineHeight: 1.3, color: 'text.primary' }}>
              Synchronization
            </Typography>
            <Typography variant="caption" sx={{ color: 'text.secondary' }}>
              {isLocalBuild ? 'Local Server' : 'Live Server'}
            </Typography>
          </Box>
        </Box>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <Chip 
            label={chipProps.label}
            size="small"
            color={chipProps.color}
            variant="outlined"
            sx={{ 
              fontWeight: 600, 
              fontSize: '0.7rem',
              height: 24,
              '& .MuiChip-label': { px: 1 },
            }} 
          />
          <IconButton
            onClick={onClose}
            disabled={isSyncing}
            size="small"
            sx={{
              color: 'text.secondary',
              '&:hover': { bgcolor: 'action.hover' },
            }}
          >
            <CloseIcon fontSize="small" />
          </IconButton>
        </Box>
      </DialogTitle>
      
      {/* ─── Body ────────────────────────────────────────────── */}
      <DialogContent sx={{ 
        p: { xs: 2.5, sm: 3 }, 
        flex: 1,
        display: 'flex',
        flexDirection: 'column',
        overflow: 'hidden',
      }}>
        {loadingConfig ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', flex: 1 }}>
            <CircularProgress size={28} />
          </Box>
        ) : !isLocalBuild ? (
          <LiveServerDashboard syncConfig={syncConfig} />
        ) : (
          <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center', flex: 1 }}>
            
            {/* Status area */}
            <Box sx={{
              mt: { xs: 0, sm: 1 },
              mb: { xs: 2, sm: 4 },
              display: 'flex',
              flexDirection: 'column',
              alignItems: 'center',
              flexShrink: 1,
              minHeight: 0,
              overflow: 'hidden',
              width: '100%',
            }}>
              {/* Animated icon container */}
              <Box sx={{
                height: { xs: 70, sm: 90 },
                mb: { xs: 1, sm: 2 },
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                position: 'relative',
              }}>
                {isSyncing && <PulseRing />}
                <AnimatePresence mode="wait">
                  <motion.div
                    key={syncStatus}
                    initial={{ opacity: 0, scale: 0.9 }}
                    animate={{ opacity: 1, scale: 1 }}
                    exit={{ opacity: 0, scale: 0.9 }}
                    transition={{ duration: 0.25 }}
                    style={{ position: 'relative', zIndex: 1 }}
                  >
                    <Box sx={{ transform: { xs: 'scale(0.8)', sm: 'scale(1)' }, display: 'flex', justifyContent: 'center' }}>
                      <AnimatedCloudIcon status={syncStatus} size={72} />
                    </Box>
                  </motion.div>
                </AnimatePresence>
              </Box>

              {syncStatus === 'idle' ? (
                <Typography variant="body2" sx={{
                  color: 'text.secondary',
                  textAlign: 'center',
                  maxWidth: '85%',
                  lineHeight: 1.6,
                }}>
                  Push local changes and pull recent updates. Background sync runs automatically every 5 minutes.
                </Typography>
              ) : (
                <motion.div
                  initial={{ opacity: 0, y: 6 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.25 }}
                  style={{ width: '100%' }}
                >
                  <Box sx={{
                    width: '100%',
                    px: { xs: 2, sm: 3 },
                    py: { xs: 1.5, sm: 2 },
                    borderRadius: 1,
                    bgcolor: syncStatus === 'failed' ? '#fef2f2' : (syncStatus === 'success' ? '#f0fdf4' : (syncStatus === 'syncing' ? '#f8fafc' : '#fffbeb')),
                    border: '1px solid',
                    borderColor: syncStatus === 'failed' ? '#fecaca' : (syncStatus === 'success' ? '#bbf7d0' : (syncStatus === 'syncing' ? '#e2e8f0' : '#fde68a')),
                    color: syncStatus === 'failed' ? '#991b1b' : (syncStatus === 'success' ? '#166534' : (syncStatus === 'syncing' ? '#334155' : '#92400e')),
                    fontSize: { xs: '0.85rem', sm: '0.95rem' },
                    fontWeight: 600,
                    textAlign: 'center',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: 1.5,
                    opacity: 0.9,
                  }}>
                    {syncStatus === 'syncing' && <CircularProgress size={16} sx={{ color: 'inherit' }} />}
                    {syncStatus === 'success' && <CheckCircleIcon sx={{ fontSize: 20 }} />}
                    {syncStatus === 'failed' && <ErrorIcon sx={{ fontSize: 20 }} />}
                    {syncStatus === 'incomplete' && <WarningIcon sx={{ fontSize: 20 }} />}
                    {syncStatus === 'syncing'
                      ? 'Synchronizing with Live Server...'
                      : (syncMessage || renderStatusText())}
                  </Box>
                </motion.div>
              )}
            </Box>

            {/* ─── Image Downloader Card ─────────────────────── */}
            <Box sx={{ 
              width: '100%', 
              mt: 'auto', 
              p: { xs: 2, sm: 3 }, 
              borderRadius: 1,
              bgcolor: 'background.default',
              border: '1px solid',
              borderColor: 'divider',
              flexShrink: 0,
            }}>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', mb: { xs: 2, sm: 2.5 } }}>
                <Box>
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 0.5 }}>
                    <DownloadIcon sx={{ fontSize: 18, color: 'primary.main' }} />
                    <Typography variant="subtitle2" sx={{ fontWeight: 600, color: 'text.primary' }}>
                      Image Downloader
                    </Typography>
                  </Box>
                  <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                    Concurrent bulk downloading for missing files.
                  </Typography>
                </Box>
                <Box sx={{ textAlign: 'right', minWidth: 60 }}>
                  <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                    Missing
                  </Typography>
                  <Typography sx={{ fontSize: { xs: '1.25rem', sm: '1.5rem' }, fontWeight: 700, color: 'text.primary', lineHeight: 1, mt: 0.5 }}>
                    {fileDownloadStatusLoading
                      ? <CircularProgress size={16} thickness={4} />
                      : (fileDownloadStatus?.missing_files ?? '?')}
                  </Typography>
                </Box>
              </Box>

              <Divider sx={{ mb: 2 }} />
              
              {/* Action buttons */}
              <Box sx={{ display: 'flex', gap: 1.5, justifyContent: 'flex-end' }}>
                <Button 
                  variant="outlined"
                  size="small"
                  onClick={loadDownloadStatus} 
                  disabled={fileDownloadStatusLoading || isDownloading}
                  startIcon={<RefreshIcon sx={{ fontSize: 16 }} />}
                  sx={{ textTransform: 'none', fontWeight: 500 }}
                >
                  Refresh
                </Button>
                {!isDownloading ? (
                  <Button 
                    variant="contained"
                    size="small"
                    onClick={handleBulkDownload}
                    disabled={!fileDownloadStatus || fileDownloadStatus.missing_files === 0}
                    startIcon={<PlayIcon sx={{ fontSize: 16 }} />}
                    sx={{ textTransform: 'none', fontWeight: 500 }}
                  >
                    Start
                  </Button>
                ) : (
                  <Button 
                    variant="contained"
                    color="error"
                    size="small"
                    onClick={stopBulkDownload}
                    startIcon={<StopIcon sx={{ fontSize: 16 }} />}
                    sx={{ textTransform: 'none', fontWeight: 500 }}
                  >
                    Stop
                  </Button>
                )}
              </Box>

              {/* Progress bar */}
              {downloadProgress.total > 0 && (
                <Box sx={{ mt: 2.5 }}>
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 0.75 }}>
                    <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary' }}>
                      {progressPercent}% Complete
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                      {downloadProgress.remaining} left
                    </Typography>
                  </Box>
                  <LinearProgress 
                    variant="determinate" 
                    value={progressPercent}
                    color={progressPercent === 100 ? 'success' : 'primary'}
                    sx={{ 
                      height: 6, 
                      borderRadius: 3,
                    }}
                  />
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', mt: 1, gap: 1 }}>
                    <Chip
                      label={`${downloadProgress.downloaded} Downloaded`}
                      size="small"
                      color="success"
                      variant="outlined"
                      sx={{ fontSize: '0.65rem', height: 22 }}
                    />
                    {downloadProgress.failed > 0 && (
                      <Chip
                        label={`${downloadProgress.failed} Failed`}
                        size="small"
                        color="error"
                        variant="outlined"
                        sx={{ fontSize: '0.65rem', height: 22 }}
                      />
                    )}
                  </Box>
                </Box>
              )}

              {/* Status text */}
              {(isDownloading || downloadProgress.downloaded > 0 || downloadProgress.failed > 0) && (
                <Box sx={{ 
                  mt: 1.5,
                  display: 'flex',
                  alignItems: 'center',
                  gap: 1,
                }}>
                  {isDownloading && <CircularProgress size={12} />}
                  <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                    {downloadProgress.isFetching ? 
                      "Scanning database..." : 
                      isDownloading ? "Downloading files sequentially..." : "Sequence complete."}
                  </Typography>
                </Box>
              )}
            </Box>
          </Box>
        )}
      </DialogContent>
      
      {/* ─── Footer ──────────────────────────────────────────── */}
      {isLocalBuild && (
        <Box sx={{ 
          px: { xs: 2.5, sm: 3 }, 
          py: { xs: 1.5, sm: 2 }, 
          bgcolor: 'background.default',
          borderTop: '1px solid',
          borderColor: 'divider',
          display: 'flex',
          justifyContent: 'flex-end',
          alignItems: 'center',
          gap: { xs: 1.5, sm: 2 },
          flexShrink: 0,
        }}>
          <Button
            variant="outlined"
            onClick={handleFullResync}
            disabled={isSyncing}
            sx={{ 
              textTransform: 'none', 
              fontWeight: 500,
              fontSize: { xs: '0.85rem', sm: '0.875rem' },
            }}
          >
            Full Resync
          </Button>

          <Button 
            variant="contained"
            onClick={handleSyncNow} 
            disabled={isSyncing}
            startIcon={isSyncing ? <CircularProgress size={16} color="inherit" /> : <CloudSyncIcon sx={{ fontSize: 18 }} />}
            sx={{ 
              textTransform: 'none', 
              fontWeight: 500,
              fontSize: { xs: '0.85rem', sm: '0.875rem' },
            }}
          >
            {isSyncing ? 'Syncing...' : 'Sync Now'}
          </Button>
        </Box>
      )}
    </Dialog>
  );
};

export default SyncModal;
