import React, { useState, useEffect, useRef, useMemo } from 'react';
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
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  LinearProgress,
  Chip,
  Divider,
  Tabs,
  Tab,
  TextField,
  InputAdornment,
  TablePagination,
  Alert,
  Tooltip,
  useTheme,
} from '@mui/material';
import {
  Close as CloseIcon,
  CloudSync as CloudSyncIcon,
  InfoOutlined as InfoIcon,
  Warning as WarningIcon,
  Refresh as RefreshIcon,
  PlayArrow as PlayIcon,
  Stop as StopIcon,
  Download as DownloadIcon,
  Search as SearchIcon,
  OpenInNew as OpenInNewIcon,
  Replay as ReplayIcon,
  CheckCircle as CheckCircleFilledIcon,
  Cancel as CancelIcon,
  HourglassEmpty as HourglassIcon,
  Description as DescriptionIcon,
  Receipt as ReceiptIcon,
  FiberManualRecord as DotIcon,
} from '@mui/icons-material';
import { motion } from 'framer-motion';
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
          width: 72,
          height: 72,
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

// ─── Helper formatters ────────────────────────────────────────────
const formatDate = (dateString) => {
  if (!dateString) return 'Never';
  const d = new Date(dateString);
  if (isNaN(d.getTime())) return dateString;
  return d.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  });
};

// Perspective-aware Direction Label and Tooltip Helper
const getDirectionInfo = (direction, perspective = 'local') => {
  const isLocalPerspective = perspective === 'local';
  if (direction === 'local_to_live') {
    return isLocalPerspective
      ? { label: 'Push', tooltip: 'Pushed to Live Server', full: 'Local → Live' }
      : { label: 'Received', tooltip: 'Received from Local Server', full: 'Received from Local' };
  }
  if (direction === 'live_to_local') {
    return isLocalPerspective
      ? { label: 'Pull', tooltip: 'Pulled from Live Server', full: 'Live → Local' }
      : { label: 'Sent', tooltip: 'Sent to Local Server', full: 'Sent to Local' };
  }
  return { label: direction || 'Synced', tooltip: direction || 'Synced', full: direction || 'Synced' };
};

// Declarant & Business formatting helper matching PropertyTable.js
const formatSyncDeclarant = (displayData) => {
  if (!displayData) return '';
  const last = (displayData.declarant_last_name || '').trim();
  const first = (displayData.declarant_first_name || '').trim();
  const middle = (displayData.declarant_middle_initial || '').trim();

  const hasParts = !!(last || first);
  if (hasParts) {
    const mi = middle.replace(/\./g, '');
    const middleFormatted = mi ? (mi.length === 1 ? ` ${mi}.` : ` ${mi}`) : '';
    if (last && first) {
      return `${last}, ${first}${middleFormatted}`.trim();
    } else if (last) {
      return last;
    } else {
      return `${first}${middleFormatted}`.trim();
    }
  }

  // Fallback to owner_name if discrete parts are not populated
  if (displayData.owner_name) {
    const s = String(displayData.owner_name).trim();
    return s.replace(/\s*,\s*/g, ', ').replace(/^,\s*|\s*,\s*$/g, '').trim();
  }
  return '';
};

const formatSyncBusiness = (displayData) => {
  if (!displayData || !displayData.business_name) return '';
  return String(displayData.business_name).replace(/,\s*/g, ' ').trim();
};

const formatSyncAssessedValue = (displayData) => {
  if (!displayData) return '₱0.00';
  const currentValue = displayData.assessed_value;
  const oldValue = displayData.assessed_value_old;
  const hasCurrent = currentValue !== undefined && currentValue !== null && currentValue !== '';
  const hasOld = oldValue !== undefined && oldValue !== null && oldValue !== '';

  if (!hasCurrent && !hasOld) return '₱0.00';

  let displayValue = '';
  if (hasCurrent && !isNaN(Number(currentValue))) {
    displayValue = `₱${Number(currentValue).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  } else if (hasCurrent) {
    displayValue = String(currentValue);
  }

  if (hasOld && displayValue) {
    return `${displayValue} (${oldValue})`;
  } else if (hasOld) {
    return String(oldValue);
  } else {
    return displayValue || '₱0.00';
  }
};

const getActionChipProps = (action) => {
  switch (action) {
    case 'created':
      return { label: 'Created', color: 'success', variant: 'outlined' };
    case 'updated':
      return { label: 'Updated', color: 'primary', variant: 'outlined' };
    case 'skipped':
      return { label: 'Skipped', color: 'default', variant: 'outlined' };
    case 'failed':
      return { label: 'Failed', color: 'error', variant: 'filled' };
    case 'deleted':
      return { label: 'Deleted', color: 'error', variant: 'outlined' };
    default:
      return { label: action || 'Synced', color: 'default', variant: 'outlined' };
  }
};

// ─── Phase Item Row ───────────────────────────────────────────────
const PhaseItem = ({ label, status, details }) => {
  const getIcon = () => {
    switch (status) {
      case 'running':
        return <CircularProgress size={16} color="primary" />;
      case 'completed':
        return <CheckCircleFilledIcon sx={{ fontSize: 18, color: 'success.main' }} />;
      case 'failed':
        return <CancelIcon sx={{ fontSize: 18, color: 'error.main' }} />;
      default:
        return <HourglassIcon sx={{ fontSize: 18, color: 'text.disabled' }} />;
    }
  };

  return (
    <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', py: 0.75, px: 1.5, borderRadius: 1, '&:hover': { bgcolor: 'action.hover' } }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.25 }}>
        {getIcon()}
        <Typography variant="body2" sx={{ fontWeight: 500, color: status === 'pending' ? 'text.secondary' : 'text.primary' }}>
          {label}
        </Typography>
      </Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        {details && (
          <Typography variant="caption" sx={{ color: 'text.secondary', fontFamily: 'monospace' }}>
            {details}
          </Typography>
        )}
        <Chip
          size="small"
          label={status}
          color={status === 'completed' ? 'success' : (status === 'failed' ? 'error' : (status === 'running' ? 'primary' : 'default'))}
          variant="outlined"
          sx={{ fontSize: '0.65rem', height: 20, textTransform: 'capitalize' }}
        />
      </Box>
    </Box>
  );
};

// ─── Record Detail Dialog ─────────────────────────────────────────
const RecordDetailDialog = ({ open, onClose, item, perspective = 'local' }) => {
  if (!item) return null;
  const { record_type, record_id, action, direction, display_data, created_at } = item;
  const dirInfo = getDirectionInfo(direction, perspective);

  const declarant = record_type === 'property' ? formatSyncDeclarant(display_data) : '';
  const business = record_type === 'property' ? formatSyncBusiness(display_data) : '';

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', pb: 1 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          {record_type === 'property' ? <DescriptionIcon color="primary" /> : <ReceiptIcon color="secondary" />}
          <Typography variant="h6" sx={{ fontSize: '1.05rem', fontWeight: 600 }}>
            {record_type === 'property' ? 'Property Sync Details' : 'Request Sync Details'}
          </Typography>
        </Box>
        <IconButton size="small" onClick={onClose}><CloseIcon fontSize="small" /></IconButton>
      </DialogTitle>
      <DialogContent dividers sx={{ pt: 2 }}>
        <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          {/* Status header banner */}
          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', p: 1.5, borderRadius: 1, bgcolor: 'background.default', border: '1px solid', borderColor: 'divider' }}>
            <Box>
              <Typography variant="caption" color="text.secondary">Record ID / UUID</Typography>
              <Typography sx={{ fontFamily: 'monospace', fontWeight: 600, fontSize: '0.85rem' }}>{record_id}</Typography>
            </Box>
            <Box sx={{ display: 'flex', gap: 1 }}>
              <Tooltip title={dirInfo.tooltip}>
                <Chip size="small" label={dirInfo.full} variant="outlined" sx={{ fontSize: '0.7rem' }} />
              </Tooltip>
              <Chip size="small" {...getActionChipProps(action)} sx={{ fontSize: '0.7rem' }} />
            </Box>
          </Box>

          {/* Properties Details */}
          {record_type === 'property' && (
            <Table size="small">
              <TableBody>
                <TableRow>
                  <TableCell sx={{ fontWeight: 600, width: '40%', color: 'text.secondary' }}>Tax Declaration No.</TableCell>
                  <TableCell sx={{ fontFamily: 'monospace', fontWeight: 700 }}>{display_data?.tax_declaration_number || '—'}</TableCell>
                </TableRow>
                <TableRow>
                  <TableCell sx={{ fontWeight: 600, color: 'text.secondary', verticalAlign: 'top' }}>Declarant / Owner</TableCell>
                  <TableCell>
                    {!declarant && !business ? (
                      '—'
                    ) : (
                      <Box>
                        {declarant && (
                          <Typography variant="body2" sx={{ fontWeight: 700, display: 'block' }}>
                            {declarant}
                          </Typography>
                        )}
                        {business && (
                          <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block', mt: 0.25 }}>
                            {business}
                          </Typography>
                        )}
                      </Box>
                    )}
                  </TableCell>
                </TableRow>
                <TableRow>
                  <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>Location</TableCell>
                  <TableCell>{display_data?.location || '—'}</TableCell>
                </TableRow>
                <TableRow>
                  <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>PIN</TableCell>
                  <TableCell sx={{ fontFamily: 'monospace' }}>{display_data?.pin || '—'}</TableCell>
                </TableRow>
                <TableRow>
                  <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>Assessed Value</TableCell>
                  <TableCell sx={{ fontWeight: 600 }}>
                    {formatSyncAssessedValue(display_data)}
                  </TableCell>
                </TableRow>
                <TableRow>
                  <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>Status</TableCell>
                  <TableCell>
                    <Chip size="small" label={display_data?.status || 'Active'} variant="outlined" sx={{ textTransform: 'capitalize', fontSize: '0.7rem' }} />
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          )}

          {/* Requests Details */}
          {record_type === 'request' && (
            <Table size="small">
              <TableBody>
                <TableRow>
                  <TableCell sx={{ fontWeight: 600, width: '40%', color: 'text.secondary' }}>Receipt Number</TableCell>
                  <TableCell sx={{ fontFamily: 'monospace', fontWeight: 700 }}>{display_data?.receipt_number || '—'}</TableCell>
                </TableRow>
                <TableRow>
                  <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>Client Name</TableCell>
                  <TableCell>{display_data?.client_name || '—'}</TableCell>
                </TableRow>
                <TableRow>
                  <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>Purpose</TableCell>
                  <TableCell>{display_data?.purpose || '—'}</TableCell>
                </TableRow>
                <TableRow>
                  <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>Amount Paid</TableCell>
                  <TableCell>
                    {display_data?.amount_paid != null ? `₱${Number(display_data.amount_paid).toLocaleString('en-US', { minimumFractionDigits: 2 })}` : '—'}
                  </TableCell>
                </TableRow>
                <TableRow>
                  <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>Date Issued</TableCell>
                  <TableCell>{display_data?.date_issued || '—'}</TableCell>
                </TableRow>
              </TableBody>
            </Table>
          )}

          {/* Error / Diagnostic details if any */}
          {display_data?.error && (
            <Alert severity="error" sx={{ mt: 1 }}>
              <Typography variant="subtitle2" sx={{ fontWeight: 600 }}>Sync Error Diagnostic</Typography>
              <Typography variant="body2" sx={{ fontFamily: 'monospace', fontSize: '0.8rem', mt: 0.5 }}>
                {display_data.error}
              </Typography>
            </Alert>
          )}

          <Typography variant="caption" sx={{ color: 'text.disabled', textAlign: 'right' }}>
            Synchronized at: {formatDate(created_at)}
          </Typography>
        </Box>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Close</Button>
      </DialogActions>
    </Dialog>
  );
};

// ─── Full Record Browser Dialog ───────────────────────────────────
const RecordBrowserDialog = ({ open, onClose, runId, initialType = 'property', perspective = 'local' }) => {
  const [activeType, setActiveType] = useState(initialType);
  const [actionFilter, setActionFilter] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(25);
  const [itemsData, setItemsData] = useState({ items: [], total: 0 });
  const [loading, setLoading] = useState(false);
  const [selectedItem, setSelectedItem] = useState(null);

  useEffect(() => {
    setActiveType(initialType);
    setPage(0);
  }, [initialType, open]);

  const fetchItems = () => {
    if (!runId || !open) return;
    setLoading(true);
    apiService.getSyncReportItems(runId, {
      record_type: activeType,
      action: actionFilter,
      search: searchTerm,
      page: page + 1,
      per_page: rowsPerPage,
    })
      .then(res => {
        setItemsData({ items: res.items || [], total: res.total || 0 });
      })
      .catch(err => console.error("Failed to load sync items:", err))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchItems();
  }, [runId, open, activeType, actionFilter, page, rowsPerPage]);

  const handleSearchKeyPress = (e) => {
    if (e.key === 'Enter') {
      setPage(0);
      fetchItems();
    }
  };

  return (
    <Dialog open={open} onClose={onClose} maxWidth="md" fullWidth PaperProps={{ sx: { height: '80vh', display: 'flex', flexDirection: 'column' } }}>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', pb: 1 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
          <CloudSyncIcon color="primary" />
          <Box>
            <Typography variant="h6" sx={{ fontSize: '1.1rem', fontWeight: 600 }}>Sync Record Browser</Typography>
            <Typography variant="caption" color="text.secondary">Run ID: {runId || 'N/A'}</Typography>
          </Box>
        </Box>
        <IconButton size="small" onClick={onClose}><CloseIcon fontSize="small" /></IconButton>
      </DialogTitle>

      {/* Tabs & Filters */}
      <Box sx={{ px: 3, pt: 1, borderBottom: '1px solid', borderColor: 'divider', display: 'flex', flexWrap: 'wrap', gap: 2, alignItems: 'center', justifyContent: 'space-between' }}>
        <Tabs value={activeType} onChange={(e, val) => { setActiveType(val); setPage(0); }} sx={{ minHeight: 40 }}>
          <Tab value="property" label="Properties" icon={<DescriptionIcon sx={{ fontSize: 16 }} />} iconPosition="start" sx={{ minHeight: 40, py: 0, textTransform: 'none', fontWeight: 600 }} />
          <Tab value="request" label="Requests" icon={<ReceiptIcon sx={{ fontSize: 16 }} />} iconPosition="start" sx={{ minHeight: 40, py: 0, textTransform: 'none', fontWeight: 600 }} />
        </Tabs>

        {/* Quick action filter chips */}
        <Box sx={{ display: 'flex', gap: 0.75, pb: 1 }}>
          {['all', 'created', 'updated', 'skipped', 'failed'].map((act) => (
            <Chip
              key={act}
              label={act.charAt(0).toUpperCase() + act.slice(1)}
              size="small"
              clickable
              color={actionFilter === act ? 'primary' : 'default'}
              variant={actionFilter === act ? 'filled' : 'outlined'}
              onClick={() => { setActionFilter(act); setPage(0); }}
              sx={{ fontSize: '0.7rem', height: 24 }}
            />
          ))}
        </Box>
      </Box>

      {/* Search Input Bar */}
      <Box sx={{ px: 3, py: 1.5, bgcolor: 'background.default', borderBottom: '1px solid', borderColor: 'divider', display: 'flex', gap: 2 }}>
        <TextField
          size="small"
          placeholder={activeType === 'property' ? "Search by TDN, Declarant, or PIN..." : "Search by Receipt, Client, or Purpose..."}
          fullWidth
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          onKeyPress={handleSearchKeyPress}
          InputProps={{
            startAdornment: (
              <InputAdornment position="start">
                <SearchIcon fontSize="small" sx={{ color: 'text.secondary' }} />
              </InputAdornment>
            ),
          }}
        />
        <Button variant="contained" size="small" onClick={() => { setPage(0); fetchItems(); }} sx={{ textTransform: 'none', px: 3 }}>
          Search
        </Button>
      </Box>

      {/* Records Table */}
      <DialogContent sx={{ p: 0, flex: 1, overflow: 'auto' }}>
        <TableContainer sx={{ height: '100%' }}>
          <Table size="small" stickyHeader>
            <TableHead>
              <TableRow>
                {activeType === 'property' ? (
                  <>
                    <TableCell sx={{ fontWeight: 600 }}>TDN</TableCell>
                    <TableCell sx={{ fontWeight: 600 }}>Owner / Declarant</TableCell>
                    <TableCell sx={{ fontWeight: 600 }}>Location</TableCell>
                    <TableCell sx={{ fontWeight: 600 }}>Assessed Value</TableCell>
                    <TableCell sx={{ fontWeight: 600 }}>Direction</TableCell>
                    <TableCell sx={{ fontWeight: 600 }}>Action</TableCell>
                    <TableCell align="center" sx={{ fontWeight: 600 }}>Details</TableCell>
                  </>
                ) : (
                  <>
                    <TableCell sx={{ fontWeight: 600 }}>Receipt No.</TableCell>
                    <TableCell sx={{ fontWeight: 600 }}>Client Name</TableCell>
                    <TableCell sx={{ fontWeight: 600 }}>Purpose</TableCell>
                    <TableCell sx={{ fontWeight: 600 }}>Amount</TableCell>
                    <TableCell sx={{ fontWeight: 600 }}>Direction</TableCell>
                    <TableCell sx={{ fontWeight: 600 }}>Action</TableCell>
                    <TableCell align="center" sx={{ fontWeight: 600 }}>Details</TableCell>
                  </>
                )}
              </TableRow>
            </TableHead>
            <TableBody>
              {loading ? (
                <TableRow>
                  <TableCell colSpan={7} align="center" sx={{ py: 6 }}>
                    <CircularProgress size={24} />
                    <Typography variant="caption" sx={{ display: 'block', mt: 1, color: 'text.secondary' }}>Loading sync records...</Typography>
                  </TableCell>
                </TableRow>
              ) : itemsData.items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7} align="center" sx={{ py: 6, color: 'text.secondary' }}>
                    No sync records found for the selected criteria.
                  </TableCell>
                </TableRow>
              ) : (
                itemsData.items.map((row) => {
                  const d = row.display_data || {};
                  const dirInfo = getDirectionInfo(row.direction, perspective);
                  return (
                    <TableRow key={row.id} hover sx={{ '&:last-child td, &:last-child th': { border: 0 } }}>
                      {activeType === 'property' ? (
                        <>
                          <TableCell sx={{ fontFamily: 'monospace', fontWeight: 600, fontSize: '0.8rem' }}>{d.tax_declaration_number || '—'}</TableCell>
                          <TableCell sx={{ fontSize: '0.8rem', verticalAlign: 'top' }}>
                            {(() => {
                              const declarant = formatSyncDeclarant(d);
                              const business = formatSyncBusiness(d);
                              if (!declarant && !business) return '—';
                              return (
                                <Box>
                                  {declarant && (
                                    <Typography variant="body2" sx={{ fontWeight: 700, fontSize: '0.8rem', lineHeight: 1.25 }}>
                                      {declarant}
                                    </Typography>
                                  )}
                                  {business && (
                                    <Typography variant="caption" sx={{ color: 'text.secondary', fontSize: '0.725rem', display: 'block', mt: 0.25, lineHeight: 1.2 }}>
                                      {business}
                                    </Typography>
                                  )}
                                </Box>
                              );
                            })()}
                          </TableCell>
                          <TableCell sx={{ fontSize: '0.8rem' }}>{d.location || '—'}</TableCell>
                          <TableCell sx={{ fontSize: '0.8rem', fontWeight: 600 }}>
                            {formatSyncAssessedValue(d)}
                          </TableCell>
                          <TableCell>
                            <Tooltip title={dirInfo.tooltip}>
                              <Chip size="small" label={dirInfo.label} variant="outlined" sx={{ fontSize: '0.65rem', height: 20 }} />
                            </Tooltip>
                          </TableCell>
                          <TableCell>
                            <Chip size="small" {...getActionChipProps(row.action)} sx={{ fontSize: '0.65rem', height: 20 }} />
                          </TableCell>
                          <TableCell align="center">
                            <IconButton size="small" onClick={() => setSelectedItem(row)}>
                              <OpenInNewIcon fontSize="small" sx={{ fontSize: 16 }} />
                            </IconButton>
                          </TableCell>
                        </>
                      ) : (
                        <>
                          <TableCell sx={{ fontFamily: 'monospace', fontWeight: 600, fontSize: '0.8rem' }}>{d.receipt_number || '—'}</TableCell>
                          <TableCell sx={{ fontSize: '0.8rem' }}>{d.client_name || '—'}</TableCell>
                          <TableCell sx={{ fontSize: '0.8rem' }}>{d.purpose || '—'}</TableCell>
                          <TableCell sx={{ fontSize: '0.8rem' }}>
                            {d.amount_paid != null ? `₱${Number(d.amount_paid).toLocaleString('en-US', { minimumFractionDigits: 2 })}` : '—'}
                          </TableCell>
                          <TableCell>
                            <Tooltip title={dirInfo.tooltip}>
                              <Chip size="small" label={dirInfo.label} variant="outlined" sx={{ fontSize: '0.65rem', height: 20 }} />
                            </Tooltip>
                          </TableCell>
                          <TableCell>
                            <Chip size="small" {...getActionChipProps(row.action)} sx={{ fontSize: '0.65rem', height: 20 }} />
                          </TableCell>
                          <TableCell align="center">
                            <IconButton size="small" onClick={() => setSelectedItem(row)}>
                              <OpenInNewIcon fontSize="small" sx={{ fontSize: 16 }} />
                            </IconButton>
                          </TableCell>
                        </>
                      )}
                    </TableRow>
                  );
                })
              )}
            </TableBody>
          </Table>
        </TableContainer>
      </DialogContent>

      <TablePagination
        component="div"
        count={itemsData.total}
        page={page}
        onPageChange={(e, newPage) => setPage(newPage)}
        rowsPerPage={rowsPerPage}
        onRowsPerPageChange={(e) => { setRowsPerPage(parseInt(e.target.value, 10)); setPage(0); }}
        rowsPerPageOptions={[10, 25, 50, 100]}
        sx={{ borderTop: '1px solid', borderColor: 'divider', flexShrink: 0 }}
      />

      <RecordDetailDialog
        open={Boolean(selectedItem)}
        onClose={() => setSelectedItem(null)}
        item={selectedItem}
        perspective={perspective}
      />
    </Dialog>
  );
};

// ─── Live Server Dashboard / Monitor ──────────────────────────────
const LiveServerDashboard = ({ syncConfig, onOpenBrowser }) => {
  const [latestReport, setLatestReport] = useState(null);
  const [reportHistory, setReportHistory] = useState([]);
  const [loading, setLoading] = useState(true);

  const fetchLiveMonitorData = async () => {
    setLoading(true);
    try {
      const [repRes, histRes] = await Promise.all([
        apiService.getLatestSyncReport(),
        apiService.getSyncReportHistory({ per_page: 8 }),
      ]);
      if (repRes?.run) {
        setLatestReport(repRes.run);
      }
      if (histRes?.runs) {
        setReportHistory(histRes.runs);
      }
    } catch (err) {
      console.error("Failed to load Live Monitor data:", err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchLiveMonitorData();
  }, []);

  const counters = latestReport?.summary?.counters || {
    properties: { created: 0, updated: 0, skipped: 0, failed: 0, total: 0 },
    requests: { created: 0, updated: 0, skipped: 0, failed: 0, deleted: 0, total: 0 },
  };

  // Determine age notice if older than 3 hours
  const hoursSinceLastSync = useMemo(() => {
    if (!latestReport?.completed_at && !latestReport?.started_at) return null;
    const syncTime = new Date(latestReport.completed_at || latestReport.started_at).getTime();
    const diffHours = Math.floor((Date.now() - syncTime) / (1000 * 60 * 60));
    return diffHours;
  }, [latestReport]);

  return (
    <Box sx={{ width: '100%', height: '100%', display: 'flex', flexDirection: 'column', gap: 2.5 }}>
      {/* Informational Header */}
      <Box sx={{
        display: 'flex',
        alignItems: 'center',
        gap: 1.5,
        p: { xs: 1.5, sm: 2 },
        borderRadius: 1.5,
        bgcolor: 'primary.50',
        color: 'primary.900',
        border: `1px solid`,
        borderColor: 'primary.200',
      }}>
        <InfoIcon sx={{ fontSize: 22, color: 'primary.main', flexShrink: 0 }} />
        <Box>
          <Typography sx={{ fontSize: { xs: '0.8rem', sm: '0.9rem' }, fontWeight: 700, lineHeight: 1.3 }}>
            Live Server Monitor
          </Typography>
          <Typography sx={{ fontSize: { xs: '0.75rem', sm: '0.8rem' }, color: 'text.secondary', mt: 0.25 }}>
            Synchronization is initiated by the Local Server. This screen displays the mirrored synchronization activity received by this server.
          </Typography>
        </Box>
      </Box>

      {/* Connection & Latest Run Status Banner */}
      <Box sx={{
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: 2,
        p: 2,
        borderRadius: 1.5,
        bgcolor: 'background.default',
        border: '1px solid',
        borderColor: 'divider',
      }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
          <Chip
            icon={<DotIcon sx={{ fontSize: '10px !important', color: 'success.main' }} />}
            label="Connected"
            size="small"
            color="success"
            variant="outlined"
            sx={{ fontWeight: 700, fontSize: '0.75rem' }}
          />
          <Box>
            <Typography sx={{ fontWeight: 600, fontSize: '0.9rem', color: 'text.primary' }}>
              Last synchronized: {latestReport?.completed_at || latestReport?.started_at ? formatDate(latestReport.completed_at || latestReport.started_at) : 'No synchronization recorded'}
            </Typography>
            {hoursSinceLastSync !== null && hoursSinceLastSync >= 3 && (
              <Typography variant="caption" sx={{ color: 'warning.dark', fontWeight: 500, display: 'block' }}>
                Note: Last synchronization was {hoursSinceLastSync} hours ago.
              </Typography>
            )}
            {latestReport?.id && (
              <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>
                Sync Mode: <span style={{ textTransform: 'capitalize' }}>{latestReport.mode}</span> • Run ID: <span style={{ fontFamily: 'monospace' }}>{latestReport.id}</span>
              </Typography>
            )}
          </Box>
        </Box>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <Chip
            label={latestReport?.status === 'completed' ? '✓ Completed' : (latestReport?.status || 'Idle')}
            color={latestReport?.status === 'completed' ? 'success' : (latestReport?.status === 'failed' ? 'error' : 'default')}
            size="small"
            sx={{ fontWeight: 600, textTransform: 'capitalize' }}
          />
          <IconButton size="small" onClick={fetchLiveMonitorData} disabled={loading} sx={{ color: 'text.secondary' }}>
            <RefreshIcon fontSize="small" />
          </IconButton>
        </Box>
      </Box>

      {/* Properties and Requests Metric Cards */}
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, gap: 2 }}>
        {/* Properties Card */}
        <Box sx={{ p: 2, borderRadius: 1.5, bgcolor: 'background.default', border: '1px solid', borderColor: 'divider', display: 'flex', flexDirection: 'column' }}>
          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1.5 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <DescriptionIcon sx={{ fontSize: 20, color: 'primary.main' }} />
              <Typography sx={{ fontWeight: 700, fontSize: '0.9rem' }}>Properties</Typography>
            </Box>
            <Button
              size="small"
              variant="outlined"
              endIcon={<OpenInNewIcon fontSize="small" />}
              onClick={() => onOpenBrowser('property', latestReport?.id)}
              disabled={!latestReport?.id}
              sx={{ textTransform: 'none', fontSize: '0.75rem', py: 0.2, px: 1 }}
            >
              View Properties
            </Button>
          </Box>
          <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 1, textAlign: 'center' }}>
            <Box sx={{ p: 1, bgcolor: 'background.paper', borderRadius: 1, border: '1px solid', borderColor: 'divider' }}>
              <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>New</Typography>
              <Typography sx={{ fontWeight: 700, color: 'success.main', fontSize: '1.05rem' }}>{counters.properties?.created || 0}</Typography>
            </Box>
            <Box sx={{ p: 1, bgcolor: 'background.paper', borderRadius: 1, border: '1px solid', borderColor: 'divider' }}>
              <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>Updated</Typography>
              <Typography sx={{ fontWeight: 700, color: 'info.main', fontSize: '1.05rem' }}>{counters.properties?.updated || 0}</Typography>
            </Box>
            <Box sx={{ p: 1, bgcolor: 'background.paper', borderRadius: 1, border: '1px solid', borderColor: 'divider' }}>
              <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>Skipped</Typography>
              <Typography sx={{ fontWeight: 700, color: 'warning.main', fontSize: '1.05rem' }}>{counters.properties?.skipped || 0}</Typography>
            </Box>
            <Box sx={{ p: 1, bgcolor: 'background.paper', borderRadius: 1, border: '1px solid', borderColor: 'divider' }}>
              <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>Failed</Typography>
              <Typography sx={{ fontWeight: 700, color: 'error.main', fontSize: '1.05rem' }}>{counters.properties?.failed || 0}</Typography>
            </Box>
          </Box>
        </Box>

        {/* Requests Card */}
        <Box sx={{ p: 2, borderRadius: 1.5, bgcolor: 'background.default', border: '1px solid', borderColor: 'divider', display: 'flex', flexDirection: 'column' }}>
          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1.5 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <ReceiptIcon sx={{ fontSize: 20, color: 'primary.main' }} />
              <Typography sx={{ fontWeight: 700, fontSize: '0.9rem' }}>Requests</Typography>
            </Box>
            <Button
              size="small"
              variant="outlined"
              endIcon={<OpenInNewIcon fontSize="small" />}
              onClick={() => onOpenBrowser('request', latestReport?.id)}
              disabled={!latestReport?.id}
              sx={{ textTransform: 'none', fontSize: '0.75rem', py: 0.2, px: 1 }}
            >
              View Requests
            </Button>
          </Box>
          <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(5, 1fr)', gap: 1, textAlign: 'center' }}>
            <Box sx={{ p: 1, bgcolor: 'background.paper', borderRadius: 1, border: '1px solid', borderColor: 'divider' }}>
              <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>New</Typography>
              <Typography sx={{ fontWeight: 700, color: 'success.main', fontSize: '1.05rem' }}>{counters.requests?.created || 0}</Typography>
            </Box>
            <Box sx={{ p: 1, bgcolor: 'background.paper', borderRadius: 1, border: '1px solid', borderColor: 'divider' }}>
              <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>Updated</Typography>
              <Typography sx={{ fontWeight: 700, color: 'info.main', fontSize: '1.05rem' }}>{counters.requests?.updated || 0}</Typography>
            </Box>
            <Box sx={{ p: 1, bgcolor: 'background.paper', borderRadius: 1, border: '1px solid', borderColor: 'divider' }}>
              <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>Deleted</Typography>
              <Typography sx={{ fontWeight: 700, color: 'error.main', fontSize: '1.05rem' }}>{counters.requests?.deleted || 0}</Typography>
            </Box>
            <Box sx={{ p: 1, bgcolor: 'background.paper', borderRadius: 1, border: '1px solid', borderColor: 'divider' }}>
              <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>Skipped</Typography>
              <Typography sx={{ fontWeight: 700, color: 'warning.main', fontSize: '1.05rem' }}>{counters.requests?.skipped || 0}</Typography>
            </Box>
            <Box sx={{ p: 1, bgcolor: 'background.paper', borderRadius: 1, border: '1px solid', borderColor: 'divider' }}>
              <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>Failed</Typography>
              <Typography sx={{ fontWeight: 700, color: 'error.main', fontSize: '1.05rem' }}>{counters.requests?.failed || 0}</Typography>
            </Box>
          </Box>
        </Box>
      </Box>

      {/* Sync Activity / History Section */}
      <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column', minHeight: 200 }}>
        <Typography variant="h6" sx={{ fontSize: { xs: '0.9rem', sm: '1rem' }, mb: 1, fontWeight: 700 }}>
          Sync Activity
        </Typography>
        <TableContainer sx={{
          flex: 1,
          overflowY: 'auto',
          borderRadius: 1.5,
          border: `1px solid`,
          borderColor: 'divider',
        }}>
          <Table size="small" stickyHeader>
            <TableHead>
              <TableRow>
                <TableCell sx={{ fontWeight: 700 }}>Run Time</TableCell>
                <TableCell sx={{ fontWeight: 700 }}>Mode</TableCell>
                <TableCell sx={{ fontWeight: 700 }}>Status</TableCell>
                <TableCell sx={{ fontWeight: 700 }}>Summary</TableCell>
                <TableCell sx={{ fontWeight: 700 }} align="right">Records</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {loading ? (
                <TableRow>
                  <TableCell colSpan={5} align="center" sx={{ py: 4, borderBottom: 'none' }}>
                    <CircularProgress size={20} />
                  </TableCell>
                </TableRow>
              ) : reportHistory.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5} align="center" sx={{ py: 4, color: 'text.secondary', fontSize: '0.85rem', borderBottom: 'none' }}>
                    No sync activity recorded yet on Live Server.
                  </TableCell>
                </TableRow>
              ) : (
                reportHistory.map((run, idx) => {
                  const rCounters = run.summary?.counters || {};
                  const pTot = (rCounters.properties?.created || 0) + (rCounters.properties?.updated || 0);
                  const qTot = (rCounters.requests?.created || 0) + (rCounters.requests?.updated || 0);
                  return (
                    <TableRow key={run.id} sx={{
                      '&:hover': { bgcolor: 'action.hover' },
                    }}>
                      <TableCell sx={{ fontSize: '0.8rem', fontWeight: idx === 0 ? 700 : 400 }}>
                        {formatDate(run.started_at)}
                        {idx === 0 && <Chip label="Latest" size="small" color="primary" sx={{ ml: 1, height: 18, fontSize: '0.65rem' }} />}
                      </TableCell>
                      <TableCell sx={{ fontSize: '0.8rem', textTransform: 'capitalize' }}>{run.mode}</TableCell>
                      <TableCell>
                        <Chip
                          label={run.status === 'completed' ? '✓ Completed' : run.status}
                          size="small"
                          color={run.status === 'completed' ? 'success' : (run.status === 'failed' ? 'error' : 'warning')}
                          variant="outlined"
                          sx={{ fontWeight: 600, fontSize: '0.7rem', height: 20 }}
                        />
                      </TableCell>
                      <TableCell sx={{ fontSize: '0.8rem', color: 'text.secondary' }}>
                        {run.summary?.message || 'Synchronization cycle'}
                      </TableCell>
                      <TableCell align="right" sx={{ fontSize: '0.8rem', fontWeight: 600 }}>
                        <Button
                          size="small"
                          variant="text"
                          onClick={() => onOpenBrowser('property', run.id)}
                          sx={{ textTransform: 'none', py: 0, px: 0.5, fontSize: '0.75rem' }}
                        >
                          {pTot} props, {qTot} reqs
                        </Button>
                      </TableCell>
                    </TableRow>
                  );
                })
              )}
            </TableBody>
          </Table>
        </TableContainer>
      </Box>
    </Box>
  );
};

// ─── Main SyncModal / Sync Center Component ───────────────────────
const SyncModal = ({ open, onClose }) => {
  const theme = useTheme();
  const { syncStatus, syncMessage, syncRunId, triggerManualSync } = useAuth();
  const [syncConfig, setSyncConfig] = useState(null);
  const [loadingConfig, setLoadingConfig] = useState(true);

  // Sync reporting & queue status
  const [activeReport, setActiveReport] = useState(null);
  const [queueStatus, setQueueStatus] = useState(null);
  const [queueLoading, setQueueLoading] = useState(false);
  const [recentItems, setRecentItems] = useState([]);
  const [recentItemsLoading, setRecentItemsLoading] = useState(false);

  // Active view inside modal: 'overview' | 'properties' | 'requests' | 'phases' | 'files'
  const [activeTab, setActiveTab] = useState(0);

  // Full Record Browser Dialog & Record Detail Dialog
  const [browserOpen, setBrowserOpen] = useState(false);
  const [browserType, setBrowserType] = useState('property');
  const [selectedItem, setSelectedItem] = useState(null);

  // Confirmation Dialog for Full Resync
  const [fullResyncConfirmOpen, setFullResyncConfirmOpen] = useState(false);

  // File Download state
  const [fileDownloadStatus, setFileDownloadStatus] = useState(null);
  const [fileDownloadStatusLoading, setFileDownloadStatusLoading] = useState(false);
  const [isDownloading, setIsDownloading] = useState(false);
  const [downloadProgress, setDownloadProgress] = useState({ downloaded: 0, failed: 0, remaining: 0, total: 0, isFetching: false });
  const downloadAbortRef = useRef(false);

  // Fetch report and queue details
  const refreshSyncData = async () => {
    if (!open) return;
    setQueueLoading(true);
    try {
      const qRes = await apiService.getSyncQueueStatus();
      setQueueStatus(qRes);
    } catch (e) {
      console.error('Failed to load sync queue status', e);
    } finally {
      setQueueLoading(false);
    }

    try {
      const repRes = await apiService.getLatestSyncReport();
      if (repRes?.run) {
        setActiveReport(repRes.run);
        // Load recent items for preview tables
        setRecentItemsLoading(true);
        const itemsRes = await apiService.getSyncReportItems(repRes.run.id, { per_page: 8 });
        setRecentItems(itemsRes?.items || []);
      }
    } catch (e) {
      console.error('Failed to load latest sync report', e);
    } finally {
      setRecentItemsLoading(false);
    }
  };

  useEffect(() => {
    if (open) {
      setLoadingConfig(true);
      apiService.getSyncConfig()
        .then(config => setSyncConfig(config))
        .catch(err => console.error("Failed to load sync config:", err))
        .finally(() => setLoadingConfig(false));

      refreshSyncData();
    }
  }, [open]);

  // Refresh report when sync status changes or a sync run completes
  useEffect(() => {
    if (syncStatus === 'success' || syncStatus === 'failed') {
      refreshSyncData();
    }
  }, [syncStatus, syncRunId]);

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

  const handleExecuteFullResync = () => {
    setFullResyncConfirmOpen(false);
    triggerManualSync(true);
  };

  const handleRetryFailed = async () => {
    try {
      await apiService.clearFailedSyncItems();
      await refreshSyncData();
      triggerManualSync();
    } catch (e) {
      console.error('Failed to retry failed items:', e);
    }
  };

  const isSyncing = syncStatus === 'syncing';
  const isLocalBuild = syncConfig?.is_local_build !== false;

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

  const chipProps = getStatusChipProps();

  // Parse counters from activeReport summary
  const summaryCounters = activeReport?.summary?.counters || {
    properties: { created: 0, updated: 0, skipped: 0, failed: 0, total: 0 },
    requests: { created: 0, updated: 0, skipped: 0, failed: 0, deleted: 0, total: 0 },
  };

  const summaryPhases = activeReport?.summary?.phases || {};

  const propCount = (summaryCounters.properties?.created || 0) + (summaryCounters.properties?.updated || 0);
  const reqCount  = (summaryCounters.requests?.created || 0) + (summaryCounters.requests?.updated || 0);
  const skippedCount = (summaryCounters.properties?.skipped || 0) + (summaryCounters.requests?.skipped || 0);
  const failedCount  = (summaryCounters.properties?.failed || 0) + (summaryCounters.requests?.failed || 0);

  const progressPercent = downloadProgress.total > 0
    ? Math.min(100, Math.round(((downloadProgress.total - downloadProgress.remaining) / downloadProgress.total) * 100))
    : 0;

  // Filter preview items
  const propertyPreviewItems = useMemo(() => recentItems.filter(i => i.record_type === 'property'), [recentItems]);
  const requestPreviewItems  = useMemo(() => recentItems.filter(i => i.record_type === 'request'), [recentItems]);

  return (
    <Dialog
      open={open}
      onClose={onClose}
      maxWidth="md"
      fullWidth
      PaperProps={{
        component: motion.div,
        initial: { opacity: 0, y: 20 },
        animate: { opacity: 1, y: 0 },
        transition: { duration: 0.3 },
        sx: {
          height: { xs: '95vh', sm: '85vh', md: '780px' },
          maxHeight: '95vh',
          display: 'flex',
          flexDirection: 'column',
          overflow: 'hidden',
          m: { xs: 1, sm: 2 },
        }
      }}
    >
      {/* ─── Header ───────────────────────────────────────────── */}
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
          <CloudSyncIcon sx={{ fontSize: { xs: 22, sm: 26 }, color: 'primary.main' }} />
          <Box>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <Typography sx={{ fontWeight: 700, fontSize: { xs: '1rem', sm: '1.15rem' }, lineHeight: 1.3, color: 'text.primary' }}>
                Sync Center
              </Typography>
              {/* Connection Status Indicator */}
              <Tooltip title={isLocalBuild ? "Connected to Local Server" : "Connected to Live Server"}>
                <Chip
                  icon={<DotIcon sx={{ fontSize: '10px !important', color: 'success.main' }} />}
                  label={isLocalBuild ? "Local Server" : "Live Server"}
                  size="small"
                  variant="outlined"
                  sx={{ height: 22, fontSize: '0.7rem', fontWeight: 600 }}
                />
              </Tooltip>
            </Box>
            <Typography variant="caption" sx={{ color: 'text.secondary' }}>
              {activeReport?.completed_at ? `Last synced: ${formatDate(activeReport.completed_at)}` : (queueStatus?.last_pull ? `Last pull: ${formatDate(queueStatus.last_pull)}` : 'Real-time synchronization engine')}
            </Typography>
          </Box>
        </Box>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <Chip
            label={chipProps.label}
            size="small"
            color={chipProps.color}
            variant="outlined"
            sx={{ fontWeight: 600, fontSize: '0.75rem', height: 26 }}
          />
          <IconButton onClick={onClose} disabled={isSyncing} size="small" sx={{ color: 'text.secondary' }}>
            <CloseIcon fontSize="small" />
          </IconButton>
        </Box>
      </DialogTitle>

      {/* ─── Navigation Tabs ──────────────────────────────────── */}
      {isLocalBuild && (
        <Box sx={{ px: 3, borderBottom: '1px solid', borderColor: 'divider', bgcolor: 'background.paper', flexShrink: 0 }}>
          <Tabs value={activeTab} onChange={(e, val) => setActiveTab(val)} sx={{ minHeight: 44 }}>
            <Tab label="Overview" sx={{ minHeight: 44, textTransform: 'none', fontWeight: 600, fontSize: '0.85rem' }} />
            <Tab label={`Properties (${propCount})`} sx={{ minHeight: 44, textTransform: 'none', fontWeight: 600, fontSize: '0.85rem' }} />
            <Tab label={`Requests (${reqCount})`} sx={{ minHeight: 44, textTransform: 'none', fontWeight: 600, fontSize: '0.85rem' }} />
            <Tab label="Phases & Steps" sx={{ minHeight: 44, textTransform: 'none', fontWeight: 600, fontSize: '0.85rem' }} />
            <Tab label={`Attachments (${fileDownloadStatus?.missing_files ?? '...'})`} sx={{ minHeight: 44, textTransform: 'none', fontWeight: 600, fontSize: '0.85rem' }} />
          </Tabs>
        </Box>
      )}

      {/* ─── Body Content ─────────────────────────────────────── */}
      <DialogContent sx={{ p: { xs: 2, sm: 3 }, flex: 1, display: 'flex', flexDirection: 'column', overflow: 'auto' }}>
        {loadingConfig ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', flex: 1 }}>
            <CircularProgress size={32} />
          </Box>
        ) : !isLocalBuild ? (
          <LiveServerDashboard
            syncConfig={syncConfig}
            onOpenBrowser={(type, runId) => {
              setBrowserType(type);
              if (runId && activeReport?.id !== runId) {
                setActiveReport(prev => ({ ...(prev || {}), id: runId }));
              }
              setBrowserOpen(true);
            }}
          />
        ) : (
          <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2.5, width: '100%' }}>

            {/* TAB 0: OVERVIEW */}
            {activeTab === 0 && (
              <>
                {/* Status and Active Pulse Banner */}
                <Box sx={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 2,
                  p: 2,
                  borderRadius: 1.5,
                  bgcolor: syncStatus === 'failed' ? '#fef2f2' : (syncStatus === 'success' ? '#f0fdf4' : (syncStatus === 'syncing' ? '#eff6ff' : 'background.default')),
                  border: '1px solid',
                  borderColor: syncStatus === 'failed' ? '#fecaca' : (syncStatus === 'success' ? '#bbf7d0' : (syncStatus === 'syncing' ? '#bfdbfe' : 'divider')),
                }}>
                  <Box sx={{ position: 'relative', width: 44, height: 44, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                    {isSyncing && <PulseRing />}
                    <AnimatedCloudIcon status={syncStatus} size={40} />
                  </Box>
                  <Box sx={{ flex: 1 }}>
                    <Typography sx={{ fontWeight: 600, fontSize: '0.95rem', color: syncStatus === 'failed' ? '#991b1b' : (syncStatus === 'success' ? '#166534' : 'text.primary') }}>
                      {isSyncing ? 'Synchronizing with Live Server...' : (syncMessage || renderStatusText())}
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>
                      {activeReport?.completed_at || activeReport?.started_at
                        ? `Last synchronized: ${formatDate(activeReport.completed_at || activeReport.started_at)}`
                        : 'Background synchronization runs automatically every 5 minutes.'}
                    </Typography>
                    {activeReport?.id && (
                      <Typography variant="caption" sx={{ color: 'text.disabled', fontSize: '0.7rem', display: 'block', mt: 0.25 }}>
                        Mode: <span style={{ textTransform: 'capitalize' }}>{activeReport.mode}</span> • Run ID: <span style={{ fontFamily: 'monospace' }}>{activeReport.id}</span>
                      </Typography>
                    )}
                  </Box>
                  <IconButton size="small" onClick={refreshSyncData} disabled={queueLoading || isSyncing} sx={{ color: 'text.secondary' }}>
                    <RefreshIcon fontSize="small" />
                  </IconButton>
                </Box>

                {/* Summary Metrics Cards */}
                <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr 1fr', sm: 'repeat(4, 1fr)' }, gap: 1.5 }}>
                  <Box sx={{ p: 1.75, borderRadius: 1.5, bgcolor: 'background.default', border: '1px solid', borderColor: 'divider' }}>
                    <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                      Properties
                    </Typography>
                    <Typography sx={{ fontSize: '1.4rem', fontWeight: 700, color: 'primary.main', mt: 0.5 }}>
                      {propCount}
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                      {summaryCounters.properties?.created || 0} new, {summaryCounters.properties?.updated || 0} updated
                    </Typography>
                  </Box>

                  <Box sx={{ p: 1.75, borderRadius: 1.5, bgcolor: 'background.default', border: '1px solid', borderColor: 'divider' }}>
                    <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                      Requests
                    </Typography>
                    <Typography sx={{ fontSize: '1.4rem', fontWeight: 700, color: 'secondary.main', mt: 0.5 }}>
                      {reqCount}
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                      {summaryCounters.requests?.created || 0} new, {summaryCounters.requests?.updated || 0} updated
                    </Typography>
                  </Box>

                  <Box sx={{ p: 1.75, borderRadius: 1.5, bgcolor: 'background.default', border: '1px solid', borderColor: 'divider' }}>
                    <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                      Skipped
                    </Typography>
                    <Typography sx={{ fontSize: '1.4rem', fontWeight: 700, color: 'text.primary', mt: 0.5 }}>
                      {skippedCount}
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                      Unchanged / up-to-date
                    </Typography>
                  </Box>

                  <Box sx={{ p: 1.75, borderRadius: 1.5, bgcolor: 'background.default', border: '1px solid', borderColor: failedCount > 0 || (queueStatus?.failed || 0) > 0 ? 'error.light' : 'divider' }}>
                    <Typography variant="caption" sx={{ fontWeight: 600, color: failedCount > 0 ? 'error.main' : 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                      Failed / Pending
                    </Typography>
                    <Typography sx={{ fontSize: '1.4rem', fontWeight: 700, color: failedCount > 0 ? 'error.main' : 'text.primary', mt: 0.5 }}>
                      {queueStatus?.failed ?? failedCount} / {queueStatus?.pending ?? 0}
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                      Queue status
                    </Typography>
                  </Box>
                </Box>

                {/* Queue Failure Alert with Retry Failed button */}
                {(queueStatus?.failed > 0 || failedCount > 0) && (
                  <Alert
                    severity="warning"
                    action={
                      <Button color="inherit" size="small" onClick={handleRetryFailed} startIcon={<ReplayIcon sx={{ fontSize: 16 }} />} sx={{ textTransform: 'none', fontWeight: 600 }}>
                        Retry Failed
                      </Button>
                    }
                    sx={{ borderRadius: 1.5 }}
                  >
                    There are {queueStatus?.failed || failedCount} synchronization items that encountered an error. You can reset them to pending to retry.
                  </Alert>
                )}

                {/* Compact Table Preview: Properties */}
                <Box sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 1.5, overflow: 'hidden' }}>
                  <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', px: 2, py: 1.25, bgcolor: 'background.default', borderBottom: '1px solid', borderColor: 'divider' }}>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                      <DescriptionIcon fontSize="small" color="primary" />
                      <Typography variant="subtitle2" sx={{ fontWeight: 600 }}>Recent Property Records</Typography>
                    </Box>
                    <Button
                      size="small"
                      onClick={() => { setBrowserType('property'); setBrowserOpen(true); }}
                      endIcon={<OpenInNewIcon sx={{ fontSize: 14 }} />}
                      sx={{ textTransform: 'none', fontSize: '0.75rem' }}
                    >
                      View All Properties
                    </Button>
                  </Box>
                  <Table size="small">
                    <TableHead>
                      <TableRow>
                        <TableCell sx={{ fontWeight: 600 }}>TDN</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Owner</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Location</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Action</TableCell>
                        <TableCell align="center" sx={{ fontWeight: 600 }}>Details</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {recentItemsLoading ? (
                        <TableRow><TableCell colSpan={5} align="center" sx={{ py: 3 }}><CircularProgress size={20} /></TableCell></TableRow>
                      ) : propertyPreviewItems.length === 0 ? (
                        <TableRow><TableCell colSpan={5} align="center" sx={{ py: 3, color: 'text.secondary' }}>No property changes in recent run.</TableCell></TableRow>
                      ) : (
                        propertyPreviewItems.slice(0, 4).map(item => (
                          <TableRow key={item.id} hover>
                            <TableCell sx={{ fontFamily: 'monospace', fontWeight: 600, fontSize: '0.8rem' }}>{item.display_data?.tax_declaration_number || '—'}</TableCell>
                            <TableCell sx={{ fontSize: '0.8rem', verticalAlign: 'top' }}>
                              {(() => {
                                const declarant = formatSyncDeclarant(item.display_data);
                                const business = formatSyncBusiness(item.display_data);
                                if (!declarant && !business) return '—';
                                return (
                                  <Box>
                                    {declarant && (
                                      <Typography variant="body2" sx={{ fontWeight: 700, fontSize: '0.8rem', lineHeight: 1.25 }}>
                                        {declarant}
                                      </Typography>
                                    )}
                                    {business && (
                                      <Typography variant="caption" sx={{ color: 'text.secondary', fontSize: '0.725rem', display: 'block', mt: 0.25, lineHeight: 1.2 }}>
                                        {business}
                                      </Typography>
                                    )}
                                  </Box>
                                );
                              })()}
                            </TableCell>
                            <TableCell sx={{ fontSize: '0.8rem' }}>{item.display_data?.location || '—'}</TableCell>
                            <TableCell><Chip size="small" {...getActionChipProps(item.action)} sx={{ fontSize: '0.65rem', height: 20 }} /></TableCell>
                            <TableCell align="center">
                              <IconButton size="small" onClick={() => setSelectedItem(item)}><OpenInNewIcon sx={{ fontSize: 15 }} /></IconButton>
                            </TableCell>
                          </TableRow>
                        ))
                      )}
                    </TableBody>
                  </Table>
                </Box>

                {/* Compact Table Preview: Requests */}
                <Box sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 1.5, overflow: 'hidden' }}>
                  <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', px: 2, py: 1.25, bgcolor: 'background.default', borderBottom: '1px solid', borderColor: 'divider' }}>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                      <ReceiptIcon fontSize="small" color="secondary" />
                      <Typography variant="subtitle2" sx={{ fontWeight: 600 }}>Recent Request Records</Typography>
                    </Box>
                    <Button
                      size="small"
                      onClick={() => { setBrowserType('request'); setBrowserOpen(true); }}
                      endIcon={<OpenInNewIcon sx={{ fontSize: 14 }} />}
                      sx={{ textTransform: 'none', fontSize: '0.75rem' }}
                    >
                      View All Requests
                    </Button>
                  </Box>
                  <Table size="small">
                    <TableHead>
                      <TableRow>
                        <TableCell sx={{ fontWeight: 600 }}>Receipt</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Client</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Purpose</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Action</TableCell>
                        <TableCell align="center" sx={{ fontWeight: 600 }}>Details</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {recentItemsLoading ? (
                        <TableRow><TableCell colSpan={5} align="center" sx={{ py: 3 }}><CircularProgress size={20} /></TableCell></TableRow>
                      ) : requestPreviewItems.length === 0 ? (
                        <TableRow><TableCell colSpan={5} align="center" sx={{ py: 3, color: 'text.secondary' }}>No request changes in recent run.</TableCell></TableRow>
                      ) : (
                        requestPreviewItems.slice(0, 4).map(item => (
                          <TableRow key={item.id} hover>
                            <TableCell sx={{ fontFamily: 'monospace', fontWeight: 600, fontSize: '0.8rem' }}>{item.display_data?.receipt_number || '—'}</TableCell>
                            <TableCell sx={{ fontSize: '0.8rem' }}>{item.display_data?.client_name || '—'}</TableCell>
                            <TableCell sx={{ fontSize: '0.8rem' }}>{item.display_data?.purpose || '—'}</TableCell>
                            <TableCell><Chip size="small" {...getActionChipProps(item.action)} sx={{ fontSize: '0.65rem', height: 20 }} /></TableCell>
                            <TableCell align="center">
                              <IconButton size="small" onClick={() => setSelectedItem(item)}><OpenInNewIcon sx={{ fontSize: 15 }} /></IconButton>
                            </TableCell>
                          </TableRow>
                        ))
                      )}
                    </TableBody>
                  </Table>
                </Box>
              </>
            )}

            {/* TAB 1: PROPERTIES LIST */}
            {activeTab === 1 && (
              <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>Properties Synchronized</Typography>
                  <Button
                    variant="outlined"
                    size="small"
                    startIcon={<SearchIcon />}
                    onClick={() => { setBrowserType('property'); setBrowserOpen(true); }}
                    sx={{ textTransform: 'none' }}
                  >
                    Open Full Browser
                  </Button>
                </Box>
                <TableContainer sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 1.5 }}>
                  <Table size="small">
                    <TableHead>
                      <TableRow>
                        <TableCell sx={{ fontWeight: 600 }}>TDN</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Owner</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Location</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>PIN</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Action</TableCell>
                        <TableCell align="center" sx={{ fontWeight: 600 }}>Action</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {propertyPreviewItems.length === 0 ? (
                        <TableRow><TableCell colSpan={6} align="center" sx={{ py: 4, color: 'text.secondary' }}>No property items in the current sync run.</TableCell></TableRow>
                      ) : (
                        propertyPreviewItems.map(item => (
                          <TableRow key={item.id} hover>
                            <TableCell sx={{ fontFamily: 'monospace', fontWeight: 600 }}>{item.display_data?.tax_declaration_number || '—'}</TableCell>
                            <TableCell sx={{ verticalAlign: 'top' }}>
                              {(() => {
                                const declarant = formatSyncDeclarant(item.display_data);
                                const business = formatSyncBusiness(item.display_data);
                                if (!declarant && !business) return '—';
                                return (
                                  <Box>
                                    {declarant && (
                                      <Typography variant="body2" sx={{ fontWeight: 700, fontSize: '0.85rem' }}>
                                        {declarant}
                                      </Typography>
                                    )}
                                    {business && (
                                      <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block', mt: 0.25 }}>
                                        {business}
                                      </Typography>
                                    )}
                                  </Box>
                                );
                              })()}
                            </TableCell>
                            <TableCell>{item.display_data?.location || '—'}</TableCell>
                            <TableCell sx={{ fontFamily: 'monospace' }}>{item.display_data?.pin || '—'}</TableCell>
                            <TableCell><Chip size="small" {...getActionChipProps(item.action)} sx={{ fontSize: '0.7rem' }} /></TableCell>
                            <TableCell align="center">
                              <Button size="small" onClick={() => setSelectedItem(item)} sx={{ textTransform: 'none', fontSize: '0.75rem' }}>View Details</Button>
                            </TableCell>
                          </TableRow>
                        ))
                      )}
                    </TableBody>
                  </Table>
                </TableContainer>
              </Box>
            )}

            {/* TAB 2: REQUESTS LIST */}
            {activeTab === 2 && (
              <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>Requests Synchronized</Typography>
                  <Button
                    variant="outlined"
                    size="small"
                    startIcon={<SearchIcon />}
                    onClick={() => { setBrowserType('request'); setBrowserOpen(true); }}
                    sx={{ textTransform: 'none' }}
                  >
                    Open Full Browser
                  </Button>
                </Box>
                <TableContainer sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 1.5 }}>
                  <Table size="small">
                    <TableHead>
                      <TableRow>
                        <TableCell sx={{ fontWeight: 600 }}>Receipt</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Client</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Purpose</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Amount Paid</TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>Action</TableCell>
                        <TableCell align="center" sx={{ fontWeight: 600 }}>Action</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {requestPreviewItems.length === 0 ? (
                        <TableRow><TableCell colSpan={6} align="center" sx={{ py: 4, color: 'text.secondary' }}>No request items in the current sync run.</TableCell></TableRow>
                      ) : (
                        requestPreviewItems.map(item => (
                          <TableRow key={item.id} hover>
                            <TableCell sx={{ fontFamily: 'monospace', fontWeight: 600 }}>{item.display_data?.receipt_number || '—'}</TableCell>
                            <TableCell>{item.display_data?.client_name || '—'}</TableCell>
                            <TableCell>{item.display_data?.purpose || '—'}</TableCell>
                            <TableCell>{item.display_data?.amount_paid != null ? `₱${Number(item.display_data.amount_paid).toLocaleString('en-US', { minimumFractionDigits: 2 })}` : '—'}</TableCell>
                            <TableCell><Chip size="small" {...getActionChipProps(item.action)} sx={{ fontSize: '0.7rem' }} /></TableCell>
                            <TableCell align="center">
                              <Button size="small" onClick={() => setSelectedItem(item)} sx={{ textTransform: 'none', fontSize: '0.75rem' }}>View Details</Button>
                            </TableCell>
                          </TableRow>
                        ))
                      )}
                    </TableBody>
                  </Table>
                </TableContainer>
              </Box>
            )}

            {/* TAB 3: PHASES & STEPS */}
            {activeTab === 3 && (
              <Box sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 1.5, p: 2, display: 'flex', flexDirection: 'column', gap: 1 }}>
                <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1 }}>Synchronization Execution Pipeline</Typography>
                <PhaseItem
                  label="1. Uploading Local Changes (Push Queue)"
                  status={summaryPhases.push?.status || (isSyncing ? 'running' : 'completed')}
                  details={`${summaryPhases.push?.pushed ?? (queueStatus?.synced ?? 0)} pushed`}
                />
                <Divider />
                <PhaseItem
                  label="2. Reconciling Configuration & Lookups"
                  status={summaryPhases.config?.status || (isSyncing ? 'pending' : 'completed')}
                  details={summaryPhases.config?.tables ? `${summaryPhases.config.tables.length} tables` : 'bidirectional'}
                />
                <Divider />
                <PhaseItem
                  label="3. Synchronizing Revision Entries"
                  status={summaryPhases.revisions?.status || (isSyncing ? 'pending' : 'completed')}
                  details={summaryPhases.revisions?.upserted ? `${summaryPhases.revisions.upserted} upserted` : null}
                />
                <Divider />
                <PhaseItem
                  label="4. Pulling Property Records"
                  status={summaryPhases.properties?.status || (isSyncing ? 'pending' : 'completed')}
                  details={`${summaryPhases.properties?.pulled ?? propCount} pulled`}
                />
                <Divider />
                <PhaseItem
                  label="5. Pulling Request Records"
                  status={summaryPhases.requests?.status || (isSyncing ? 'pending' : 'completed')}
                  details={`${summaryPhases.requests?.pulled ?? reqCount} pulled`}
                />
                <Divider />
                <PhaseItem
                  label="6. Updating System Users"
                  status={summaryPhases.users?.status || (isSyncing ? 'pending' : 'completed')}
                  details={summaryPhases.users?.upserted ? `${summaryPhases.users.upserted} users` : null}
                />
              </Box>
            )}

            {/* TAB 4: ATTACHMENTS & FILE DOWNLOADER */}
            {activeTab === 4 && (
              <Box sx={{
                width: '100%',
                p: { xs: 2, sm: 3 },
                borderRadius: 1.5,
                bgcolor: 'background.default',
                border: '1px solid',
                borderColor: 'divider',
              }}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', mb: 2 }}>
                  <Box>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 0.5 }}>
                      <DownloadIcon sx={{ fontSize: 20, color: 'primary.main' }} />
                      <Typography variant="subtitle1" sx={{ fontWeight: 700, color: 'text.primary' }}>
                        Attachment File Downloader
                      </Typography>
                    </Box>
                    <Typography variant="body2" sx={{ color: 'text.secondary' }}>
                      Downloads missing property photos and supporting PDF documents from the live server.
                    </Typography>
                  </Box>
                  <Box sx={{ textAlign: 'right', minWidth: 70 }}>
                    <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                      Missing Files
                    </Typography>
                    <Typography sx={{ fontSize: '1.6rem', fontWeight: 700, color: 'text.primary', lineHeight: 1, mt: 0.5 }}>
                      {fileDownloadStatusLoading ? <CircularProgress size={18} thickness={4} /> : (fileDownloadStatus?.missing_files ?? '?')}
                    </Typography>
                  </Box>
                </Box>

                <Divider sx={{ mb: 2 }} />

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
                      sx={{ textTransform: 'none', fontWeight: 600 }}
                    >
                      Start Download
                    </Button>
                  ) : (
                    <Button
                      variant="contained"
                      color="error"
                      size="small"
                      onClick={stopBulkDownload}
                      startIcon={<StopIcon sx={{ fontSize: 16 }} />}
                      sx={{ textTransform: 'none', fontWeight: 600 }}
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
                        {downloadProgress.remaining} remaining
                      </Typography>
                    </Box>
                    <LinearProgress
                      variant="determinate"
                      value={progressPercent}
                      color={progressPercent === 100 ? 'success' : 'primary'}
                      sx={{ height: 6, borderRadius: 3 }}
                    />
                    <Box sx={{ display: 'flex', justifyContent: 'space-between', mt: 1, gap: 1 }}>
                      <Chip
                        label={`${downloadProgress.downloaded} Downloaded`}
                        size="small"
                        color="success"
                        variant="outlined"
                        sx={{ fontSize: '0.7rem', height: 22 }}
                      />
                      {downloadProgress.failed > 0 && (
                        <Chip
                          label={`${downloadProgress.failed} Failed`}
                          size="small"
                          color="error"
                          variant="outlined"
                          sx={{ fontSize: '0.7rem', height: 22 }}
                        />
                      )}
                    </Box>
                  </Box>
                )}

                {/* Status text with corrected wording */}
                {(isDownloading || downloadProgress.downloaded > 0 || downloadProgress.failed > 0) && (
                  <Box sx={{ mt: 2, display: 'flex', alignItems: 'center', gap: 1 }}>
                    {isDownloading && <CircularProgress size={14} />}
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 500 }}>
                      {downloadProgress.isFetching
                        ? "Scanning database for missing files..."
                        : isDownloading
                        ? "Downloading files with 5 concurrent workers..."
                        : "Download cycle complete."}
                    </Typography>
                  </Box>
                )}
              </Box>
            )}

          </Box>
        )}
      </DialogContent>

      {/* ─── Footer Action Bar ────────────────────────────────── */}
      {isLocalBuild && (
        <Box sx={{
          px: { xs: 2.5, sm: 3 },
          py: { xs: 1.5, sm: 2 },
          bgcolor: 'background.default',
          borderTop: '1px solid',
          borderColor: 'divider',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          flexShrink: 0,
        }}>
          <Box sx={{ display: 'flex', gap: 1 }}>
            <Button
              variant="outlined"
              size="small"
              onClick={() => { setBrowserType('property'); setBrowserOpen(true); }}
              startIcon={<SearchIcon sx={{ fontSize: 16 }} />}
              sx={{ textTransform: 'none', fontWeight: 500 }}
            >
              Browse All Records
            </Button>
          </Box>

          <Box sx={{ display: 'flex', gap: 1.5, alignItems: 'center' }}>
            <Button
              variant="outlined"
              color="warning"
              onClick={() => setFullResyncConfirmOpen(true)}
              disabled={isSyncing}
              sx={{ textTransform: 'none', fontWeight: 500 }}
            >
              Full Resync...
            </Button>

            <Button
              variant="contained"
              onClick={handleSyncNow}
              disabled={isSyncing}
              startIcon={isSyncing ? <CircularProgress size={16} color="inherit" /> : <CloudSyncIcon sx={{ fontSize: 18 }} />}
              sx={{ textTransform: 'none', fontWeight: 600, px: 2.5 }}
            >
              {isSyncing ? 'Syncing...' : 'Sync Now'}
            </Button>
          </Box>
        </Box>
      )}

      {/* Full Resync Confirmation Dialog */}
      <Dialog open={fullResyncConfirmOpen} onClose={() => setFullResyncConfirmOpen(false)} maxWidth="xs">
        <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1, color: 'warning.main' }}>
          <WarningIcon />
          <Typography variant="h6" sx={{ fontSize: '1rem', fontWeight: 600 }}>Confirm Full Resync</Typography>
        </DialogTitle>
        <DialogContent>
          <Typography variant="body2" sx={{ color: 'text.secondary', lineHeight: 1.6 }}>
            A Full Resync will reset all sync cursors and re-evaluate every property and request record on the Live Server from the beginning of time.
          </Typography>
          <Typography variant="body2" sx={{ color: 'text.primary', fontWeight: 600, mt: 1.5 }}>
            Are you sure you want to proceed?
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 2.5, pb: 2 }}>
          <Button onClick={() => setFullResyncConfirmOpen(false)} sx={{ textTransform: 'none' }}>Cancel</Button>
          <Button variant="contained" color="warning" onClick={handleExecuteFullResync} sx={{ textTransform: 'none', fontWeight: 600 }}>
            Start Full Resync
          </Button>
        </DialogActions>
      </Dialog>

      {/* Record Browser Dialog */}
      <RecordBrowserDialog
        open={browserOpen}
        onClose={() => setBrowserOpen(false)}
        runId={activeReport?.id || syncRunId}
        initialType={browserType}
        perspective={isLocalBuild ? 'local' : 'live'}
      />

      {/* Record Detail Dialog */}
      <RecordDetailDialog
        open={Boolean(selectedItem)}
        onClose={() => setSelectedItem(null)}
        item={selectedItem}
        perspective={isLocalBuild ? 'local' : 'live'}
      />
    </Dialog>
  );
};

export default SyncModal;
