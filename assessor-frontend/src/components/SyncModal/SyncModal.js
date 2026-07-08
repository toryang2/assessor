import React, { useState, useEffect, useRef } from 'react';
import {
  Dialog,
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
  LinearProgress
} from '@mui/material';
import { Close as CloseIcon, CloudSync as CloudSyncIcon, InfoOutlined as InfoIcon, CheckCircleOutline as CheckCircleIcon, Error as ErrorIcon, Warning as WarningIcon } from '@mui/icons-material';
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
      <Box sx={{ 
        display: 'flex', 
        alignItems: 'center', 
        gap: 1.5, 
        p: { xs: 1.5, sm: 2 }, 
        mb: { xs: 1.5, sm: 3 }, 
        borderRadius: '12px', 
        bgcolor: '#f4f4f5', 
        color: '#52525b', 
        border: '1px solid #e4e4e7'
      }}>
        <InfoIcon sx={{ fontSize: 20, color: '#71717a' }} />
        <Typography sx={{ fontSize: { xs: '0.75rem', sm: '0.85rem' }, fontWeight: 500, lineHeight: 1.4 }}>
          Live Server Dashboard. Data synchronization is managed directly from the Local Server.
        </Typography>
      </Box>

      <Box sx={{ display: 'flex', gap: { xs: 1.5, sm: 2 }, mb: { xs: 2, sm: 4 } }}>
        <Box sx={{ flex: 1, p: { xs: 1.5, sm: 2.5 }, borderRadius: '16px', bgcolor: '#ffffff', border: '1px solid #e4e4e7', boxShadow: '0 1px 3px rgba(0,0,0,0.02)' }}>
          <Typography sx={{ fontSize: { xs: '0.65rem', sm: '0.75rem' }, fontWeight: 600, color: '#a1a1aa', textTransform: 'uppercase', letterSpacing: '0.05em', mb: 0.5 }}>Last Push</Typography>
          <Typography sx={{ fontSize: { xs: '0.95rem', sm: '1.1rem' }, fontWeight: 600, color: '#18181b' }}>{formatDate(syncConfig?.last_local_push)}</Typography>
        </Box>
        <Box sx={{ flex: 1, p: { xs: 1.5, sm: 2.5 }, borderRadius: '16px', bgcolor: '#ffffff', border: '1px solid #e4e4e7', boxShadow: '0 1px 3px rgba(0,0,0,0.02)' }}>
          <Typography sx={{ fontSize: { xs: '0.65rem', sm: '0.75rem' }, fontWeight: 600, color: '#a1a1aa', textTransform: 'uppercase', letterSpacing: '0.05em', mb: 0.5 }}>Last Pull</Typography>
          <Typography sx={{ fontSize: { xs: '0.95rem', sm: '1.1rem' }, fontWeight: 600, color: '#18181b' }}>{formatDate(syncConfig?.last_local_pull)}</Typography>
        </Box>
      </Box>

      <Typography sx={{ fontSize: { xs: '0.9rem', sm: '1rem' }, fontWeight: 600, color: '#18181b', mb: { xs: 1, sm: 2 } }}>Recent Activity</Typography>
      
      <TableContainer sx={{ 
        flex: 1, 
        overflowY: 'auto',
        borderRadius: '12px',
        border: '1px solid #e4e4e7',
        '&::-webkit-scrollbar': { display: 'none' },
        scrollbarWidth: 'none',
        msOverflowStyle: 'none',
      }}>
        <Table size="small" stickyHeader>
          <TableHead>
            <TableRow>
              <TableCell sx={{ bgcolor: '#fafafa', color: '#71717a', fontSize: '0.75rem', fontWeight: 500, borderBottom: '1px solid #e4e4e7', py: 1 }}>Timestamp</TableCell>
              <TableCell sx={{ bgcolor: '#fafafa', color: '#71717a', fontSize: '0.75rem', fontWeight: 500, borderBottom: '1px solid #e4e4e7', py: 1 }}>Table</TableCell>
              <TableCell sx={{ bgcolor: '#fafafa', color: '#71717a', fontSize: '0.75rem', fontWeight: 500, borderBottom: '1px solid #e4e4e7', py: 1 }}>Record ID</TableCell>
              <TableCell sx={{ bgcolor: '#fafafa', color: '#71717a', fontSize: '0.75rem', fontWeight: 500, borderBottom: '1px solid #e4e4e7', py: 1 }}>Details</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {loadingLogs ? (
              <TableRow>
                <TableCell colSpan={4} align="center" sx={{ py: 4, borderBottom: 'none' }}><CircularProgress size={20} sx={{ color: '#a1a1aa' }} /></TableCell>
              </TableRow>
            ) : auditLogs.length === 0 ? (
              <TableRow>
                <TableCell colSpan={4} align="center" sx={{ py: 4, color: '#a1a1aa', fontSize: '0.85rem', borderBottom: 'none' }}>No recent activity found.</TableCell>
              </TableRow>
            ) : (
              auditLogs.map((log) => (
                <TableRow key={log.id} sx={{ '&:last-child td, &:last-child th': { border: 0 } }}>
                  <TableCell sx={{ color: '#3f3f46', fontSize: '0.8rem', borderColor: '#f4f4f5' }}>{formatDate(log.created_at)}</TableCell>
                  <TableCell sx={{ borderColor: '#f4f4f5' }}>
                    <Box sx={{ display: 'inline-flex', px: 1, py: 0.5, bgcolor: '#f4f4f5', color: '#52525b', borderRadius: '4px', fontSize: '0.7rem', fontWeight: 600 }}>
                      {log.table_name.replace('assessor_', '')}
                    </Box>
                  </TableCell>
                  <TableCell sx={{ color: '#3f3f46', fontSize: '0.8rem', borderColor: '#f4f4f5' }}>{log.record_id}</TableCell>
                  <TableCell sx={{ color: '#3f3f46', fontSize: '0.8rem', borderColor: '#f4f4f5' }}>
                    {log.new_values && JSON.parse(log.new_values)?.tax_declaration_number ? 
                      <Box sx={{ fontFamily: 'monospace', color: '#18181b' }}>{JSON.parse(log.new_values).tax_declaration_number}</Box>
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

const SyncModal = ({ open, onClose }) => {
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

  const renderStatusIcon = () => (
    <Box sx={{ 
      transform: { xs: 'scale(0.8)', sm: 'scale(1)' },
      display: 'flex', justifyContent: 'center' 
    }}>
      <AnimatedCloudIcon status={syncStatus} size={72} />
    </Box>
  );

  const renderStatusText = () => {
    switch (syncStatus) {
      case 'syncing': return 'Synchronizing...';
      case 'success': return 'In Sync';
      case 'failed': return 'Sync Failed';
      case 'incomplete': return 'Partially Synced';
      default: return 'Ready to Sync';
    }
  };

  const isSyncing = syncStatus === 'syncing';
  const isLocalBuild = syncConfig?.is_local_build !== false; 

  return (
    <Dialog 
      open={open} 
      onClose={onClose}
      maxWidth="sm"
      fullWidth
      PaperProps={{
        sx: { 
          height: { xs: '90vh', sm: '80vh', md: '700px' },
          maxHeight: '90vh',
          borderRadius: { xs: '16px', sm: '24px' }, 
          boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
          display: 'flex',
          flexDirection: 'column',
          overflow: 'hidden',
          bgcolor: '#ffffff',
          m: { xs: 2, sm: 0 },
          fontFamily: '"Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'
        }
      }}
    >
      <Box sx={{ 
        py: { xs: 2, sm: 3 }, 
        px: { xs: 2.5, sm: 4 },
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        borderBottom: '1px solid #f4f4f5',
        flexShrink: 0
      }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
          <CloudSyncIcon sx={{ fontSize: { xs: 20, sm: 24 }, color: '#18181b' }} />
          <Typography sx={{ fontWeight: 600, fontSize: { xs: '0.95rem', sm: '1.1rem' }, color: '#18181b' }}>
            Synchronization
          </Typography>
        </Box>
        <IconButton onClick={onClose} disabled={isSyncing} sx={{ color: '#a1a1aa', padding: { xs: 0.5, sm: 1 }, '&:hover': { bgcolor: '#f4f4f5', color: '#18181b' } }}>
          <CloseIcon fontSize="small" />
        </IconButton>
      </Box>
      
      <Box sx={{ 
        p: { xs: 2.5, sm: 4 }, 
        flex: 1,
        display: 'flex',
        flexDirection: 'column',
        overflow: 'hidden'
      }}>
        {loadingConfig ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', flex: 1 }}>
            <CircularProgress size={28} sx={{ color: '#d4d4d8' }} />
          </Box>
        ) : !isLocalBuild ? (
          <LiveServerDashboard syncConfig={syncConfig} />
        ) : (
          <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center', flex: 1 }}>
            
            <Box sx={{ mt: { xs: 0, sm: 2 }, mb: { xs: 2, sm: 5 }, display: 'flex', flexDirection: 'column', alignItems: 'center', flexShrink: 1, minHeight: 0, overflow: 'hidden' }}>
              <Box sx={{ height: { xs: 60, sm: 80 }, mb: { xs: 1, sm: 3 }, display: 'flex', alignItems: 'center' }}>
                <AnimatePresence mode="wait">
                  <motion.div
                    key={syncStatus}
                    initial={{ opacity: 0, scale: 0.95 }}
                    animate={{ opacity: 1, scale: 1 }}
                    exit={{ opacity: 0, scale: 0.95 }}
                    transition={{ duration: 0.2 }}
                  >
                    {renderStatusIcon()}
                  </motion.div>
                </AnimatePresence>
              </Box>

              {syncStatus === 'idle' ? (
                <Typography sx={{ fontSize: { xs: '0.8rem', sm: '0.9rem' }, color: '#71717a', textAlign: 'center', maxWidth: '85%', lineHeight: 1.5 }}>
                  Push local changes and pull recent updates. Background sync runs automatically every 5 minutes.
                </Typography>
              ) : (
                <Box sx={{ 
                  width: '100%',
                  px: { xs: 2, sm: 3 }, 
                  py: { xs: 1.5, sm: 2 }, 
                  borderRadius: '12px', 
                  bgcolor: syncStatus === 'failed' ? '#fef2f2' : (syncStatus === 'success' ? '#f0fdf4' : (syncStatus === 'syncing' ? '#f8fafc' : '#fffbeb')),
                  border: `1px solid ${syncStatus === 'failed' ? '#fecaca' : (syncStatus === 'success' ? '#bbf7d0' : (syncStatus === 'syncing' ? '#e4e4e7' : '#fde68a'))}`,
                  color: syncStatus === 'failed' ? '#991b1b' : (syncStatus === 'success' ? '#166534' : (syncStatus === 'syncing' ? '#3f3f46' : '#92400e')),
                  fontSize: { xs: '0.85rem', sm: '0.95rem' },
                  fontWeight: 600,
                  textAlign: 'center',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: 1.5
                }}>
                  {syncStatus === 'syncing' && <CircularProgress size={16} sx={{ color: 'inherit' }} />}
                  {syncStatus === 'success' && <CheckCircleIcon sx={{ fontSize: 20 }} />}
                  {syncStatus === 'failed' && <ErrorIcon sx={{ fontSize: 20 }} />}
                  {syncStatus === 'incomplete' && <WarningIcon sx={{ fontSize: 20 }} />}
                  {syncStatus === 'syncing' 
                    ? 'Synchronizing with Live Server...' 
                    : (syncMessage || renderStatusText())}
                </Box>
              )}
            </Box>

            <Box sx={{ 
              width: '100%', 
              mt: 'auto', 
              p: { xs: 2, sm: 3 }, 
              borderRadius: '16px', 
              bgcolor: '#fafafa',
              border: '1px solid #f4f4f5',
              flexShrink: 0
            }}>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', mb: { xs: 2, sm: 3 } }}>
                <Box>
                  <Typography sx={{ fontWeight: 600, color: '#18181b', fontSize: { xs: '0.85rem', sm: '0.95rem' } }}>Image Downloader</Typography>
                  <Typography sx={{ color: '#71717a', fontSize: { xs: '0.75rem', sm: '0.8rem' }, mt: 0.5 }}>
                    Concurrent bulk downloading for missing files.
                  </Typography>
                </Box>
                <Box sx={{ textAlign: 'right' }}>
                  <Typography sx={{ fontSize: { xs: '0.65rem', sm: '0.7rem' }, fontWeight: 600, color: '#a1a1aa', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                    Missing
                  </Typography>
                  <Typography sx={{ fontSize: { xs: '1.25rem', sm: '1.5rem' }, fontWeight: 700, color: '#18181b', lineHeight: 1, mt: 0.5 }}>
                    {fileDownloadStatusLoading ? <CircularProgress size={16} thickness={4} sx={{ color: '#d4d4d8' }}/> : (fileDownloadStatus?.missing_files ?? '?')}
                  </Typography>
                </Box>
              </Box>
              
              <Box sx={{ display: 'flex', gap: 1.5, justifyContent: 'flex-end' }}>
                <Button 
                  onClick={loadDownloadStatus} 
                  disabled={fileDownloadStatusLoading || isDownloading}
                  sx={{ 
                    borderRadius: '8px', 
                    textTransform: 'none', 
                    fontWeight: 500, 
                    color: '#52525b', 
                    bgcolor: '#ffffff',
                    border: '1px solid #e4e4e7',
                    px: { xs: 1.5, sm: 2 },
                    py: 0.5,
                    fontSize: { xs: '0.8rem', sm: '0.875rem' },
                    boxShadow: '0 1px 2px rgba(0,0,0,0.02)',
                    '&:hover': { bgcolor: '#f4f4f5', borderColor: '#d4d4d8' } 
                  }}
                >
                  Refresh
                </Button>
                {!isDownloading ? (
                  <Button 
                    onClick={handleBulkDownload}
                    disabled={!fileDownloadStatus || fileDownloadStatus.missing_files === 0}
                    sx={{ 
                      borderRadius: '8px', 
                      textTransform: 'none', 
                      fontWeight: 500, 
                      bgcolor: '#18181b', 
                      color: '#ffffff',
                      px: { xs: 1.5, sm: 2 },
                      py: 0.5,
                      fontSize: { xs: '0.8rem', sm: '0.875rem' },
                      boxShadow: '0 1px 3px rgba(0,0,0,0.1)',
                      '&:hover': { bgcolor: '#27272a' },
                      '&.Mui-disabled': { bgcolor: '#e4e4e7', color: '#a1a1aa' }
                    }}
                  >
                    Start
                  </Button>
                ) : (
                  <Button 
                    onClick={stopBulkDownload}
                    sx={{ 
                      borderRadius: '8px', 
                      textTransform: 'none', 
                      fontWeight: 500, 
                      bgcolor: '#ef4444', 
                      color: '#ffffff',
                      px: { xs: 1.5, sm: 2 },
                      py: 0.5,
                      fontSize: { xs: '0.8rem', sm: '0.875rem' },
                      boxShadow: '0 1px 3px rgba(239,68,68,0.2)',
                      '&:hover': { bgcolor: '#dc2626' }
                    }}
                  >
                    Stop
                  </Button>
                )}
              </Box>

              {downloadProgress.total > 0 && (
                <Box sx={{ mt: { xs: 2, sm: 3 } }}>
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 1, px: 0.5 }}>
                    <Typography sx={{ fontSize: { xs: '0.7rem', sm: '0.75rem' }, fontWeight: 500, color: '#52525b' }}>
                      {downloadProgress.total > 0 ? Math.min(100, Math.round(((downloadProgress.total - downloadProgress.remaining) / downloadProgress.total) * 100)) : 0}% Complete
                    </Typography>
                    <Typography sx={{ fontSize: { xs: '0.7rem', sm: '0.75rem' }, fontWeight: 500, color: '#a1a1aa' }}>
                      {downloadProgress.remaining} left
                    </Typography>
                  </Box>
                  <LinearProgress 
                    variant="determinate" 
                    value={downloadProgress.total > 0 ? Math.min(100, Math.round(((downloadProgress.total - downloadProgress.remaining) / downloadProgress.total) * 100)) : 0} 
                    sx={{ 
                      height: 4, 
                      borderRadius: 2,
                      bgcolor: '#e4e4e7',
                      '& .MuiLinearProgress-bar': {
                          borderRadius: 2,
                          bgcolor: '#18181b'
                      }
                    }}
                  />
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', mt: 1.5, px: 0.5 }}>
                     <Typography sx={{ fontSize: { xs: '0.65rem', sm: '0.7rem' }, color: '#10b981', fontWeight: 600 }}>{downloadProgress.downloaded} Downloaded</Typography>
                     {downloadProgress.failed > 0 && <Typography sx={{ fontSize: { xs: '0.65rem', sm: '0.7rem' }, color: '#ef4444', fontWeight: 600 }}>{downloadProgress.failed} Failed</Typography>}
                  </Box>
                </Box>
              )}

              {(isDownloading || downloadProgress.downloaded > 0 || downloadProgress.failed > 0) && (
                <Box sx={{ 
                  mt: 1.5,
                  display: 'flex',
                  alignItems: 'center',
                  gap: 1.5,
                  px: 0.5
                }}>
                  {isDownloading && <CircularProgress size={12} sx={{ color: '#18181b' }} />}
                  <Typography sx={{ fontSize: { xs: '0.7rem', sm: '0.75rem' }, color: '#71717a' }}>
                    {downloadProgress.isFetching ? 
                      "Scanning database..." : 
                      isDownloading ? "Downloading files sequentially..." : "Sequence complete."
                    }
                  </Typography>
                </Box>
              )}
            </Box>
          </Box>
        )}
      </Box>
      
      <Box sx={{ 
        px: { xs: 2.5, sm: 4 }, 
        py: { xs: 2, sm: 3 }, 
        bgcolor: '#ffffff', 
        borderTop: '1px solid #f4f4f5', 
        display: 'flex',
        justifyContent: 'flex-end',
        alignItems: 'center',
        gap: { xs: 1.5, sm: 2 },
        flexShrink: 0
      }}>
        {isLocalBuild && (
          <>
            <Button
              onClick={handleFullResync}
              disabled={isSyncing}
              sx={{ 
                borderRadius: '8px', 
                px: { xs: 1.5, sm: 2 }, 
                py: { xs: 0.5, sm: 1 }, 
                textTransform: 'none', 
                fontWeight: 500,
                color: '#52525b',
                border: '1px solid #e4e4e7',
                bgcolor: '#ffffff',
                fontSize: { xs: '0.85rem', sm: '0.95rem' },
                boxShadow: '0 1px 2px rgba(0,0,0,0.02)',
                '&:hover': { bgcolor: '#fafafa', borderColor: '#d4d4d8' }
              }}
            >
              Full Resync
            </Button>

            <Button 
              onClick={handleSyncNow} 
              disabled={isSyncing}
              sx={{ 
                borderRadius: '8px', 
                px: { xs: 2, sm: 3 }, 
                py: { xs: 0.5, sm: 1 }, 
                textTransform: 'none', 
                fontWeight: 500,
                bgcolor: '#18181b',
                color: '#ffffff',
                fontSize: { xs: '0.85rem', sm: '0.95rem' },
                boxShadow: '0 2px 4px rgba(0,0,0,0.1)',
                '&:hover': {
                  bgcolor: '#27272a',
                  boxShadow: '0 4px 6px rgba(0,0,0,0.15)',
                },
                '&.Mui-disabled': {
                  bgcolor: '#e4e4e7',
                  color: '#a1a1aa'
                }
              }}
            >
              {isSyncing ? 'Syncing...' : 'Sync Now'}
            </Button>
          </>
        )}
      </Box>
    </Dialog>
  );
};

export default SyncModal;
