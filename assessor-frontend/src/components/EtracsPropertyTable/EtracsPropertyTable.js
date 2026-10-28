import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
  Box,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TablePagination,
  TextField,
  Button,
  IconButton,
  Typography,
  Grid,
  Card,
  CardContent,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Alert,
  CircularProgress,
  Chip,
  Select,
  MenuItem,
  FormControl,
  InputLabel,
  Tooltip,
  Skeleton,
  LinearProgress,
} from '@mui/material';
import {
  Search as SearchIcon,
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Visibility as VisibilityIcon,
  Cancel as CancelIcon,
  Refresh as RefreshIcon,
  Assignment as AssignmentIcon,
  CheckCircle as CheckCircleIcon,
  Block as BlockIcon,
  Send as SendIcon,
  ThumbDown as ThumbDownIcon,
  ThumbUp as ThumbUpIcon,
  History as HistoryIcon,
  ExpandMore as ExpandMoreIcon,
  ExpandLess as ExpandLessIcon,
} from '@mui/icons-material';

import { etracsService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';
import EtracsPropertyFormModal from '../EtracsPropertyFormModal/EtracsPropertyFormModal';
import TaxDeclarationPreviewModal from './TaxDeclarationPreviewModal';
import EtracsAuditTimelineModal from './EtracsAuditTimelineModal';

const stateColors = {
  CURRENT: { bg: '#e8f5e9', color: '#2e7d32', label: 'Current' },
  FORAPPROVAL: { bg: '#fff8e1', color: '#b78103', label: 'For Approval' },
  INTERIM: { bg: '#e0f2fe', color: '#0369a1', label: 'Interim Draft' },
  PENDING: { bg: '#e0f2fe', color: '#0369a1', label: 'Pending Draft' },
  DISAPPROVED: { bg: '#fef2f2', color: '#991b1b', label: 'Disapproved' },
  CANCELLED: { bg: '#ffebee', color: '#c62828', label: 'Cancelled' },
  DELETED: { bg: '#f5f5f5', color: '#757575', label: 'Deleted' },
};

// Fixed metric card definition for administrative dashboard
const METRIC_DEFS = [
  { key: 'total', title: 'Total Records', color: '#1976d2', icon: <AssignmentIcon fontSize="small" /> },
  { key: 'current', title: 'Current', color: '#2e7d32', icon: <CheckCircleIcon fontSize="small" /> },
  { key: 'forapproval', title: 'For Approval', color: '#ed6c02', icon: <SendIcon fontSize="small" /> },
  { key: 'interim', title: 'Draft / Interim', color: '#0288d1', icon: <EditIcon fontSize="small" /> },
  { key: 'cancelled', title: 'Cancelled', color: '#d32f2f', icon: <BlockIcon fontSize="small" /> },
];

const EtracsPropertyTable = () => {
  const { isSuperAdmin, isAdmin, isAssessor, canManage } = useAuth();
  const canEdit = isSuperAdmin || isAdmin;
  const canApprove = isSuperAdmin || isAdmin || isAssessor || canManage;

  const [properties, setProperties] = useState([]);
  const [initialLoading, setInitialLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState(null);
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(20);
  const [total, setTotal] = useState(0);
  const [searchQuery, setSearchQuery] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [stateFilter, setStateFilter] = useState('');
  const [rpuTypeFilter, setRpuTypeFilter] = useState('');
  const [txnTypeFilter, setTxnTypeFilter] = useState('');
  const [transactionTypes, setTransactionTypes] = useState([]);

  // Stats independent state
  const [stats, setStats] = useState(null);
  const [statsLoading, setStatsLoading] = useState(true);
  const [statsError, setStatsError] = useState(null);

  // Request generation ref to cancel / ignore out-of-order responses
  const fetchGenRef = useRef(0);
  const isMountedRef = useRef(true);

  // Expandable owner state (keyed by property id)
  const [expandedOwners, setExpandedOwners] = useState({});

  const toggleOwner = (id) => {
    setExpandedOwners((prev) => ({
      ...prev,
      [id]: !prev[id],
    }));
  };

  // Modal states
  const [formOpen, setFormOpen] = useState(false);
  const [selectedProperty, setSelectedProperty] = useState(null);
  const [viewDialogOpen, setViewDialogOpen] = useState(false);
  const [viewProperty, setViewProperty] = useState(null);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [cancelDialogOpen, setCancelDialogOpen] = useState(false);
  const [cancelTarget, setCancelTarget] = useState(null);
  const [cancelReason, setCancelReason] = useState('');
  const [cancelNote, setCancelNote] = useState('');

  // Lifecycle dialog states
  const [approveDialogOpen, setApproveDialogOpen] = useState(false);
  const [approveTarget, setApproveTarget] = useState(null);
  const [disapproveDialogOpen, setDisapproveDialogOpen] = useState(false);
  const [disapproveTarget, setDisapproveTarget] = useState(null);
  const [disapproveReason, setDisapproveReason] = useState('');
  const [actionSubmitting, setActionSubmitting] = useState(false);

  // Audit history modal state
  const [auditDialogOpen, setAuditDialogOpen] = useState(false);
  const [auditTargetFaasId, setAuditTargetFaasId] = useState(null);

  useEffect(() => {
    isMountedRef.current = true;
    return () => {
      isMountedRef.current = false;
    };
  }, []);

  // Independent stats loader
  const loadStats = useCallback(async () => {
    setStatsLoading(true);
    setStatsError(null);
    try {
      const statsData = await etracsService.getStats();
      if (isMountedRef.current) {
        setStats(statsData || {});
      }
    } catch (err) {
      if (isMountedRef.current) {
        setStatsError(err.message || 'Failed to load statistics');
      }
    } finally {
      if (isMountedRef.current) {
        setStatsLoading(false);
      }
    }
  }, []);

  // Independent metadata loader (transaction types)
  useEffect(() => {
    let active = true;
    const loadTxnTypes = async () => {
      try {
        const types = await etracsService.getTransactionTypes();
        if (active) {
          setTransactionTypes(types || []);
        }
      } catch (e) {
        console.warn('Failed to load transaction types:', e);
      }
    };
    loadTxnTypes();
    loadStats();
    return () => {
      active = false;
    };
  }, [loadStats]);

  // Main properties list query with request guard
  const fetchProperties = useCallback(async () => {
    const currentGen = ++fetchGenRef.current;

    // Distinguish initial load from background refresh
    if (properties.length === 0) {
      setInitialLoading(true);
    } else {
      setRefreshing(true);
    }
    setError(null);

    try {
      const params = {
        page: page + 1,
        per_page: rowsPerPage,
      };
      if (searchQuery) params.q = searchQuery;
      if (stateFilter) params.state = stateFilter;
      if (rpuTypeFilter) params.rpu_type = rpuTypeFilter;
      if (txnTypeFilter) params.txntype = txnTypeFilter;

      const result = await etracsService.getFaasList(params);

      // Check if stale response
      if (!isMountedRef.current || currentGen !== fetchGenRef.current) {
        return;
      }

      setProperties(result.data || []);
      setTotal(result.total || 0);
    } catch (err) {
      if (!isMountedRef.current || currentGen !== fetchGenRef.current) {
        return;
      }
      setError(err.message || 'Failed to load ETRACS properties');
      if (properties.length === 0) {
        setProperties([]);
      }
    } finally {
      if (isMountedRef.current && currentGen === fetchGenRef.current) {
        setInitialLoading(false);
        setRefreshing(false);
      }
    }
  }, [page, rowsPerPage, searchQuery, stateFilter, rpuTypeFilter, txnTypeFilter, properties.length]);

  useEffect(() => {
    fetchProperties();
  }, [page, rowsPerPage, searchQuery, stateFilter, rpuTypeFilter, txnTypeFilter]); // eslint-disable-line react-hooks/exhaustive-deps

  // Debounce search input
  useEffect(() => {
    const handler = setTimeout(() => {
      setSearchQuery((prev) => {
        if (prev !== searchInput) {
          setPage(0);
          return searchInput;
        }
        return prev;
      });
    }, 400);
    return () => clearTimeout(handler);
  }, [searchInput]);

  const handleSearch = () => {
    setSearchQuery((prev) => {
      if (prev !== searchInput) {
        setPage(0);
        return searchInput;
      }
      return prev;
    });
  };

  const handleKeyDown = (e) => {
    if (e.key === 'Enter') {
      handleSearch();
    }
  };

  const handleView = async (id) => {
    try {
      const data = await etracsService.getFaas(id);
      setViewProperty(data);
      setViewDialogOpen(true);
    } catch (err) {
      setError('Failed to load record details');
    }
  };

  const handleEdit = async (id) => {
    try {
      const data = await etracsService.getFaas(id);
      setSelectedProperty(data);
      setFormOpen(true);
    } catch (err) {
      setError('Failed to load record for editing');
    }
  };

  const handleDelete = async () => {
    if (!deleteTarget) return;
    try {
      await etracsService.deleteFaas(deleteTarget.id);
      setDeleteDialogOpen(false);
      setDeleteTarget(null);
      fetchProperties();
      loadStats();
    } catch (err) {
      setError('Failed to delete record');
    }
  };

  const refreshAll = useCallback(async () => {
    fetchProperties();
    loadStats();
  }, [fetchProperties, loadStats]);

  const handleCancel = async () => {
    if (!cancelTarget || actionSubmitting) return;
    try {
      setActionSubmitting(true);
      setError(null);
      await etracsService.cancelFaas(cancelTarget.id, {
        cancel_reason: cancelReason,
        cancel_note: cancelNote,
      });
      setCancelDialogOpen(false);
      setCancelTarget(null);
      setCancelReason('');
      setCancelNote('');
      refreshAll();
    } catch (err) {
      setError(err.message || 'Failed to cancel record');
    } finally {
      setActionSubmitting(false);
    }
  };

  const handleSubmitForApproval = async (row) => {
    if (actionSubmitting) return;
    try {
      setActionSubmitting(true);
      setError(null);
      await etracsService.submitFaas(row.id);
      refreshAll();
    } catch (err) {
      setError(err.message || 'Failed to submit declaration for approval');
    } finally {
      setActionSubmitting(false);
    }
  };

  const handleApprove = async () => {
    if (!approveTarget || actionSubmitting) return;
    try {
      setActionSubmitting(true);
      setError(null);
      await etracsService.approveFaas(approveTarget.id);
      setApproveDialogOpen(false);
      setApproveTarget(null);
      refreshAll();
    } catch (err) {
      setError(err.message || 'Failed to approve declaration');
    } finally {
      setActionSubmitting(false);
    }
  };

  const handleDisapprove = async () => {
    if (!disapproveTarget || actionSubmitting) return;
    try {
      setActionSubmitting(true);
      setError(null);
      await etracsService.disapproveFaas(disapproveTarget.id, {
        disapprove_reason: disapproveReason,
      });
      setDisapproveDialogOpen(false);
      setDisapproveTarget(null);
      setDisapproveReason('');
      refreshAll();
    } catch (err) {
      setError(err.message || 'Failed to return declaration');
    } finally {
      setActionSubmitting(false);
    }
  };

  const handleFormSave = () => {
    setFormOpen(false);
    setSelectedProperty(null);
    refreshAll();
  };

  const formatCurrency = (val) => {
    if (val === null || val === undefined || val === '') return '—';
    return Number(val).toLocaleString('en-PH', { style: 'currency', currency: 'PHP' });
  };

  return (
    <Box
      sx={{
        display: 'flex',
        flexDirection: 'column',
        height: '100%',
        minHeight: 0,
        minWidth: 0,
        overflow: 'hidden',
        width: '100%',
        maxWidth: '100%',
      }}
    >
      {/* 5 Fixed-Size Metric Cards (Skeleton while loading, predictable height, no layout shift) */}
      <Grid container spacing={1.5} sx={{ mb: 1.5, flexShrink: 0 }} alignItems="stretch">
        {METRIC_DEFS.map((metric) => {
          const rawVal = stats ? stats[metric.key] : null;
          const displayVal = rawVal !== null && rawVal !== undefined ? Number(rawVal).toLocaleString() : '—';

          return (
            <Grid item xs={12} sm={6} md={2.4} key={metric.key}>
              <Card
                variant="outlined"
                sx={{
                  height: '100%',
                  minHeight: 80,
                  borderRadius: 2,
                  borderColor: 'divider',
                  backgroundColor: '#ffffff',
                  display: 'flex',
                  flexDirection: 'column',
                  justifyContent: 'center',
                  boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
                }}
              >
                <CardContent sx={{ p: 1.5, '&:last-child': { pb: 1.5 } }}>
                  <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 0.5 }}>
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 600, textTransform: 'uppercase', letterSpacing: 0.5, fontSize: '0.7rem' }}>
                      {metric.title}
                    </Typography>
                    <Box
                      sx={{
                        color: metric.color,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        p: 0.5,
                        borderRadius: 1,
                        backgroundColor: `${metric.color}14`,
                      }}
                    >
                      {metric.icon}
                    </Box>
                  </Box>
                  {statsLoading ? (
                    <Skeleton variant="text" width="60%" height={32} />
                  ) : statsError ? (
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                      <Typography variant="caption" color="error">Error loading</Typography>
                      <IconButton size="small" onClick={loadStats} sx={{ p: 0.2 }}>
                        <RefreshIcon fontSize="inherit" />
                      </IconButton>
                    </Box>
                  ) : (
                    <Typography variant="h5" component="div" sx={{ fontWeight: 700, color: 'text.primary', lineHeight: 1.2, fontSize: '1.25rem' }}>
                      {displayVal}
                    </Typography>
                  )}
                </CardContent>
              </Card>
            </Grid>
          );
        })}
      </Grid>

      {/* Filter Toolbar */}
      <Paper variant="outlined" sx={{ p: 1.5, mb: 1.5, borderRadius: 2, backgroundColor: '#ffffff', flexShrink: 0 }}>
        <Grid container spacing={1.5} alignItems="center">
          <Grid item xs={12} sm={4} md={4}>
            <TextField
              fullWidth
              size="small"
              placeholder="Search TD#, Owner, PIN, Lot..."
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              onKeyDown={handleKeyDown}
              InputProps={{
                startAdornment: <SearchIcon sx={{ color: 'text.secondary', mr: 1, fontSize: 20 }} />,
              }}
            />
          </Grid>
          <Grid item xs={6} sm={2.5} md={2}>
            <FormControl fullWidth size="small">
              <InputLabel>State</InputLabel>
              <Select value={stateFilter} onChange={(e) => { setStateFilter(e.target.value); setPage(0); }} label="State">
                <MenuItem value="">All States</MenuItem>
                <MenuItem value="CURRENT">Current</MenuItem>
                <MenuItem value="FORAPPROVAL">For Approval</MenuItem>
                <MenuItem value="INTERIM">Interim Draft</MenuItem>
                <MenuItem value="DISAPPROVED">Disapproved</MenuItem>
                <MenuItem value="CANCELLED">Cancelled</MenuItem>
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={6} sm={2.5} md={2}>
            <FormControl fullWidth size="small">
              <InputLabel>Type</InputLabel>
              <Select value={rpuTypeFilter} onChange={(e) => { setRpuTypeFilter(e.target.value); setPage(0); }} label="Type">
                <MenuItem value="">All Types</MenuItem>
                <MenuItem value="LAND">Land</MenuItem>
                <MenuItem value="BLDG">Building</MenuItem>
                <MenuItem value="MACH">Machinery</MenuItem>
                <MenuItem value="PLANTTREE">Plant/Tree</MenuItem>
                <MenuItem value="MISC">Misc</MenuItem>
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={6} sm={3} md={2}>
            <FormControl fullWidth size="small">
              <InputLabel>Transaction</InputLabel>
              <Select value={txnTypeFilter} onChange={(e) => { setTxnTypeFilter(e.target.value); setPage(0); }} label="Transaction">
                <MenuItem value="">All Transactions</MenuItem>
                {transactionTypes.map((t) => (
                  <MenuItem key={t.code} value={t.code}>{t.code} - {t.name}</MenuItem>
                ))}
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={6} sm={2} md={2} sx={{ display: 'flex', gap: 1, justifyContent: 'flex-end' }}>
            <Button variant="contained" onClick={handleSearch} startIcon={<SearchIcon />} size="small" sx={{ textTransform: 'none', fontWeight: 600 }}>
              Search
            </Button>
            <Tooltip title="Refresh properties">
              <span>
                <IconButton onClick={refreshAll} size="small" disabled={refreshing || initialLoading}>
                  {refreshing ? <CircularProgress size={18} /> : <RefreshIcon fontSize="small" />}
                </IconButton>
              </span>
            </Tooltip>
          </Grid>
        </Grid>
      </Paper>

      {/* Action Bar */}
      <Box sx={{ mb: 1.5, display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexShrink: 0 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
          {canApprove && (
            <Button
              variant="outlined"
              color="primary"
              size="small"
              startIcon={<HistoryIcon />}
              onClick={() => { setAuditTargetFaasId(null); setAuditDialogOpen(true); }}
              sx={{ borderRadius: 1.5, textTransform: 'none', fontWeight: 600 }}
            >
              Shadow Audit Log
            </Button>
          )}
          {refreshing && (
            <Typography variant="caption" color="text.secondary" sx={{ display: 'inline-flex', alignItems: 'center', gap: 0.5 }}>
              <CircularProgress size={12} thickness={5} /> Refreshing data...
            </Typography>
          )}
        </Box>
        <Box>
          {canEdit && (
            <Button
              variant="contained"
              size="small"
              startIcon={<AddIcon />}
              onClick={() => { setSelectedProperty(null); setFormOpen(true); }}
              sx={{ borderRadius: 1.5, textTransform: 'none', fontWeight: 600 }}
            >
              New FAAS Record
            </Button>
          )}
        </Box>
      </Box>

      {error && <Alert severity="error" sx={{ mb: 1.5, flexShrink: 0 }} onClose={() => setError(null)}>{error}</Alert>}

      {/* Table Frame with Sticky Header and Contained Overflow */}
      <Paper
        variant="outlined"
        sx={{
          borderRadius: 2,
          overflow: 'hidden',
          backgroundColor: '#ffffff',
          position: 'relative',
          display: 'flex',
          flexDirection: 'column',
          flex: '1 1 0',
          minHeight: 0,
          minWidth: 0,
        }}
      >
        {refreshing && (
          <LinearProgress
            sx={{
              position: 'absolute',
              top: 0,
              left: 0,
              right: 0,
              zIndex: 10,
              height: 3,
            }}
          />
        )}
        <TableContainer
          sx={{
            flex: '1 1 0',
            minHeight: 0,
            minWidth: 0,
            height: 0,
            overflow: 'auto',
          }}
        >
          <Table size="small" stickyHeader sx={{ minWidth: 1648, tableLayout: 'fixed' }}>
            <colgroup>
              <col style={{ width: '150px' }} />
              <col style={{ width: '210px' }} />
              <col style={{ width: '140px' }} />
              <col style={{ width: '120px' }} />
              <col style={{ width: '90px' }} />
              <col style={{ width: '80px' }} />
              <col style={{ width: '180px' }} />
              <col style={{ width: '130px' }} />
              <col style={{ width: '130px' }} />
              <col style={{ width: '80px' }} />
              <col style={{ width: '92px' }} />
              <col style={{ width: '116px' }} />
            </colgroup>
            <TableHead>
              <TableRow>
                <TableCell sx={{ width: 150, fontWeight: 700, backgroundColor: '#f8fafc' }}>TD No.</TableCell>
                <TableCell sx={{ width: 210, fontWeight: 700, backgroundColor: '#f8fafc' }}>Owner</TableCell>
                <TableCell sx={{ width: 140, fontWeight: 700, backgroundColor: '#f8fafc' }}>PIN</TableCell>
                <TableCell sx={{ width: 120, fontWeight: 700, backgroundColor: '#f8fafc' }}>Barangay</TableCell>
                <TableCell sx={{ width: 90, fontWeight: 700, backgroundColor: '#f8fafc' }}>Type</TableCell>
                <TableCell sx={{ width: 80, fontWeight: 700, backgroundColor: '#f8fafc' }}>Class</TableCell>
                <TableCell sx={{ width: 180, fontWeight: 700, backgroundColor: '#f8fafc' }}>Actual Use</TableCell>
                <TableCell align="right" sx={{ width: 130, fontWeight: 700, backgroundColor: '#f8fafc' }}>Market Value</TableCell>
                <TableCell align="right" sx={{ width: 130, fontWeight: 700, backgroundColor: '#f8fafc' }}>Assessed Value</TableCell>
                <TableCell align="center" sx={{ width: 80, fontWeight: 700, backgroundColor: '#f8fafc' }}>Txn</TableCell>
                <TableCell align="center" sx={{ width: 92, fontWeight: 700, backgroundColor: '#f8fafc' }}>Origin</TableCell>
                <TableCell align="center" sx={{ width: 116, fontWeight: 700, backgroundColor: '#f8fafc' }}>State</TableCell>
                <TableCell
                  align="center"
                  sx={{
                    position: 'sticky',
                    right: 0,
                    zIndex: 3,
                    backgroundColor: '#f1f5f9',
                    borderLeft: '2px solid #e0e0e0',
                    boxShadow: '-4px 0 8px rgba(0,0,0,0.06)',
                    whiteSpace: 'nowrap',
                  }}
                >
                  Actions
                </TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {initialLoading ? (
                // Skeletons during first load
                Array.from({ length: 8 }).map((_, idx) => (
                  <TableRow key={idx}>
                    <TableCell><Skeleton variant="text" width="80%" /></TableCell>
                    <TableCell><Skeleton variant="text" width="90%" /></TableCell>
                    <TableCell><Skeleton variant="text" width="85%" /></TableCell>
                    <TableCell><Skeleton variant="text" width="70%" /></TableCell>
                    <TableCell><Skeleton variant="text" width="60%" /></TableCell>
                    <TableCell><Skeleton variant="text" width="60%" /></TableCell>
                    <TableCell><Skeleton variant="text" width="75%" /></TableCell>
                    <TableCell align="right"><Skeleton variant="text" width="70%" sx={{ ml: 'auto' }} /></TableCell>
                    <TableCell align="right"><Skeleton variant="text" width="70%" sx={{ ml: 'auto' }} /></TableCell>
                    <TableCell align="center"><Skeleton variant="rounded" width={40} height={20} sx={{ mx: 'auto' }} /></TableCell>
                    <TableCell align="center"><Skeleton variant="rounded" width={55} height={20} sx={{ mx: 'auto' }} /></TableCell>
                    <TableCell align="center"><Skeleton variant="rounded" width={70} height={20} sx={{ mx: 'auto' }} /></TableCell>
                    <TableCell
                      align="center"
                      sx={{
                        width: 100,
                        minWidth: 100,
                        maxWidth: 100,
                        boxSizing: 'border-box',
                        p: '4px 6px',
                        position: 'sticky',
                        right: 0,
                        zIndex: 1,
                        backgroundColor: '#ffffff',
                        borderLeft: '2px solid #e0e0e0',
                        boxShadow: '-4px 0 8px rgba(0,0,0,0.06)',
                      }}
                    >
                      <Skeleton variant="circular" width={22} height={22} sx={{ mx: 'auto' }} />
                    </TableCell>
                  </TableRow>
                ))
              ) : properties.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={13} align="center" sx={{ py: 8 }}>
                    <Typography variant="body1" color="text.secondary" fontWeight={500}>
                      {searchQuery ? 'No declarations match your search query.' : 'No ETRACS records found.'}
                    </Typography>
                    {searchQuery && (
                      <Button size="small" onClick={() => { setSearchInput(''); setSearchQuery(''); }} sx={{ mt: 1 }}>
                        Clear Search
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ) : (
                properties.map((row) => {
                  const effectiveState = row.effective_state || row.state;
                  const isReplica = row.origin_type === 'REPLICA';
                  const st = stateColors[effectiveState] || stateColors.CURRENT;
                  const ownerText = row.owner_name || row.taxpayer_name || '—';
                  const pinText = row.pin || row.rp_pin || '—';
                  const actualUseText = row.actualuse || '—';
                  const barangayText = row.barangay || '—';
                  const isOwnerExpanded = Boolean(expandedOwners[row.id]);
                  const hasLongOwner = ownerText && ownerText !== '—' && ownerText.length > 40;

                  return (
                    <TableRow
                      key={row.id}
                      hover
                      sx={{
                        cursor: 'pointer',
                        '&:hover': {
                          backgroundColor: '#f1f5f9',
                          '& .sticky-actions-cell': {
                            backgroundColor: '#f1f5f9',
                          },
                        },
                        transition: 'background-color 0.15s ease',
                      }}
                      onClick={() => handleView(row.id)}
                    >
                      {/* TD No. */}
                      <TableCell sx={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        <Typography variant="body2" fontWeight={600} color="primary.main">
                          {row.tdno || '—'}
                        </Typography>
                      </TableCell>

                      {/* Owner with native View/Less expandable interaction */}
                      <TableCell sx={{ verticalAlign: 'top' }}>
                        <Box sx={{ width: '100%', maxWidth: 220 }}>
                          <Typography
                            variant="body2"
                            sx={{
                              whiteSpace: isOwnerExpanded ? 'normal' : 'nowrap',
                              overflow: 'hidden',
                              textOverflow: 'ellipsis',
                              wordBreak: 'break-word',
                              display: 'block',
                            }}
                          >
                            {ownerText}
                          </Typography>
                          {hasLongOwner && (
                            <Button
                              size="small"
                              variant="text"
                              onClick={(e) => {
                                e.stopPropagation();
                                toggleOwner(row.id);
                              }}
                              sx={{
                                textTransform: 'none',
                                p: 0,
                                minWidth: 'auto',
                                fontSize: '0.7rem',
                                mt: 0.25,
                                lineHeight: 1.2,
                              }}
                              startIcon={
                                isOwnerExpanded ? (
                                  <ExpandLessIcon sx={{ fontSize: 14 }} />
                                ) : (
                                  <ExpandMoreIcon sx={{ fontSize: 14 }} />
                                )
                              }
                            >
                              {isOwnerExpanded ? 'Less' : 'View'}
                            </Button>
                          )}
                        </Box>
                      </TableCell>

                      {/* PIN */}
                      <TableCell sx={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        <Tooltip title={pinText} enterDelay={500}>
                          <Typography variant="body2" sx={{ fontFamily: 'monospace', fontSize: '0.8rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {pinText}
                          </Typography>
                        </Tooltip>
                      </TableCell>

                      {/* Barangay */}
                      <TableCell sx={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        <Tooltip title={barangayText} enterDelay={500}>
                          <Typography variant="body2" sx={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {barangayText}
                          </Typography>
                        </Tooltip>
                      </TableCell>

                      {/* Type */}
                      <TableCell sx={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        <Typography variant="body2">{row.rpu_type || '—'}</Typography>
                      </TableCell>

                      {/* Classification */}
                      <TableCell sx={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        <Typography variant="body2">{row.classification || '—'}</Typography>
                      </TableCell>

                      {/* Actual Use */}
                      <TableCell sx={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        <Tooltip title={actualUseText} enterDelay={500}>
                          <Typography variant="body2" sx={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {actualUseText}
                          </Typography>
                        </Tooltip>
                      </TableCell>

                      {/* Market Value */}
                      <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                        <Typography variant="body2" sx={{ fontFamily: 'monospace' }}>
                          {formatCurrency(row.total_market_value)}
                        </Typography>
                      </TableCell>

                      {/* Assessed Value */}
                      <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                        <Typography variant="body2" fontWeight={600} sx={{ fontFamily: 'monospace' }}>
                          {formatCurrency(row.total_assessed_value)}
                        </Typography>
                      </TableCell>

                      {/* Txn Code */}
                      <TableCell align="center">
                        <Chip
                          label={row.txntype_code || '—'}
                          size="small"
                          variant="outlined"
                          sx={{ fontSize: '0.7rem', height: 20, px: 0.5 }}
                        />
                      </TableCell>

                      {/* Origin */}
                      <TableCell align="center">
                        <Chip
                          label={isReplica ? 'REPLICA' : 'LOCAL'}
                          size="small"
                          color={isReplica ? 'info' : 'success'}
                          variant="outlined"
                          sx={{ fontSize: '0.65rem', fontWeight: 700, height: 20 }}
                        />
                      </TableCell>

                      {/* Effective State */}
                      <TableCell align="center">
                        <Chip
                          label={st.label}
                          size="small"
                          sx={{
                            backgroundColor: st.bg,
                            color: st.color,
                            fontWeight: 600,
                            fontSize: '0.7rem',
                            height: 22,
                          }}
                        />
                      </TableCell>

                      {/* Sticky Actions Cell (Fixed 100px Compact Grid) */}
                      <TableCell
                        className="sticky-actions-cell"
                        align="center"
                        onClick={(e) => e.stopPropagation()}
                        sx={{
                          width: 100,
                          minWidth: 100,
                          maxWidth: 100,
                          boxSizing: 'border-box',
                          p: '4px 2px',
                          position: 'sticky',
                          right: 0,
                          zIndex: 1,
                          backgroundColor: '#ffffff',
                          borderLeft: '2px solid #e0e0e0',
                          boxShadow: '-4px 0 8px rgba(0,0,0,0.06)',
                          transition: 'background-color 0.15s ease',
                          verticalAlign: 'middle',
                        }}
                      >
                        <Box
                          sx={{
                            display: 'grid',
                            gridTemplateColumns: 'repeat(3, 26px)',
                            gap: '2px',
                            justifyContent: 'center',
                            alignItems: 'center',
                            width: '100%',
                          }}
                        >
                          <Tooltip title="View Declaration">
                            <IconButton
                              size="small"
                              onClick={(e) => { e.stopPropagation(); handleView(row.id); }}
                              sx={{ width: 26, height: 26, p: 0 }}
                            >
                              <VisibilityIcon sx={{ fontSize: 16 }} />
                            </IconButton>
                          </Tooltip>

                          {/* Audit History (Available to assessors/managers) */}
                          {canApprove && (
                            <Tooltip title="Audit History">
                              <IconButton
                                size="small"
                                color="default"
                                onClick={(e) => { e.stopPropagation(); setAuditTargetFaasId(row.id); setAuditDialogOpen(true); }}
                                sx={{ width: 26, height: 26, p: 0 }}
                              >
                                <HistoryIcon sx={{ fontSize: 16 }} />
                              </IconButton>
                            </Tooltip>
                          )}

                          {/* Submit for Approval (Drafts: INTERIM, PENDING, DISAPPROVED) - LOCAL records only */}
                          {!isReplica && canEdit && (effectiveState === 'INTERIM' || effectiveState === 'PENDING' || effectiveState === 'DISAPPROVED') && (
                            <Tooltip title="Submit for Approval">
                              <IconButton
                                size="small"
                                color="primary"
                                onClick={(e) => { e.stopPropagation(); handleSubmitForApproval(row); }}
                                sx={{ width: 26, height: 26, p: 0 }}
                              >
                                <SendIcon sx={{ fontSize: 16 }} />
                              </IconButton>
                            </Tooltip>
                          )}

                          {/* Assessor Approval Actions (FORAPPROVAL) - LOCAL records only */}
                          {!isReplica && canApprove && effectiveState === 'FORAPPROVAL' && (
                            <>
                              <Tooltip title="Approve Declaration">
                                <IconButton
                                  size="small"
                                  color="success"
                                  onClick={(e) => { e.stopPropagation(); setApproveTarget(row); setApproveDialogOpen(true); }}
                                  sx={{ width: 26, height: 26, p: 0 }}
                                >
                                  <ThumbUpIcon sx={{ fontSize: 16 }} />
                                </IconButton>
                              </Tooltip>
                              <Tooltip title="Disapprove / Return">
                                <IconButton
                                  size="small"
                                  color="error"
                                  onClick={(e) => { e.stopPropagation(); setDisapproveTarget(row); setDisapproveDialogOpen(true); }}
                                  sx={{ width: 26, height: 26, p: 0 }}
                                >
                                  <ThumbDownIcon sx={{ fontSize: 16 }} />
                                </IconButton>
                              </Tooltip>
                            </>
                          )}

                          {/* Edit (only local drafts can be edited) */}
                          {!isReplica && canEdit && (effectiveState === 'INTERIM' || effectiveState === 'PENDING' || effectiveState === 'DISAPPROVED') && (
                            <Tooltip title="Edit Draft">
                              <IconButton
                                size="small"
                                onClick={(e) => { e.stopPropagation(); handleEdit(row.id); }}
                                sx={{ width: 26, height: 26, p: 0 }}
                              >
                                <EditIcon sx={{ fontSize: 16 }} />
                              </IconButton>
                            </Tooltip>
                          )}

                          {/* Cancel (only CURRENT local declarations can be cancelled directly) */}
                          {!isReplica && canApprove && effectiveState === 'CURRENT' && (
                            <Tooltip title="Cancel FAAS">
                              <IconButton
                                size="small"
                                color="warning"
                                onClick={(e) => { e.stopPropagation(); setCancelTarget(row); setCancelDialogOpen(true); }}
                                sx={{ width: 26, height: 26, p: 0 }}
                              >
                                <CancelIcon sx={{ fontSize: 16 }} />
                              </IconButton>
                            </Tooltip>
                          )}

                          {/* Delete (only unapproved local drafts can be deleted) */}
                          {!isReplica && canEdit && (effectiveState === 'INTERIM' || effectiveState === 'PENDING' || effectiveState === 'DISAPPROVED') && (
                            <Tooltip title="Delete Draft">
                              <IconButton
                                size="small"
                                color="error"
                                onClick={(e) => { e.stopPropagation(); setDeleteTarget(row); setDeleteDialogOpen(true); }}
                                sx={{ width: 26, height: 26, p: 0 }}
                              >
                                <DeleteIcon sx={{ fontSize: 16 }} />
                              </IconButton>
                            </Tooltip>
                          )}
                        </Box>
                      </TableCell>
                    </TableRow>
                  );
                })
              )}
            </TableBody>
          </Table>
        </TableContainer>

        {/* Stable Pagination Footer Outside Scroll Region */}
        <Box sx={{ borderTop: '1px solid', borderColor: 'divider', backgroundColor: '#f8fafc', flexShrink: 0 }}>
          <TablePagination
            component="div"
            count={total}
            page={page}
            onPageChange={(_, p) => setPage(p)}
            rowsPerPage={rowsPerPage}
            onRowsPerPageChange={(e) => { setRowsPerPage(parseInt(e.target.value, 10)); setPage(0); }}
            rowsPerPageOptions={[10, 20, 50, 100]}
          />
        </Box>
      </Paper>

      {/* Modals & Dialogs */}
      <TaxDeclarationPreviewModal
        open={viewDialogOpen}
        onClose={() => setViewDialogOpen(false)}
        property={viewProperty}
      />

      {/* Shadow Audit Timeline Modal */}
      <EtracsAuditTimelineModal
        open={auditDialogOpen}
        onClose={() => setAuditDialogOpen(false)}
        faasId={auditTargetFaasId}
      />

      {/* Delete Confirmation */}
      <Dialog open={deleteDialogOpen} onClose={() => setDeleteDialogOpen(false)}>
        <DialogTitle>Delete FAAS Record?</DialogTitle>
        <DialogContent>
          <Typography>Are you sure you want to delete TD# <strong>{deleteTarget?.tdno}</strong>? This action cannot be undone.</Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeleteDialogOpen(false)}>Cancel</Button>
          <Button onClick={handleDelete} color="error" variant="contained">Delete</Button>
        </DialogActions>
      </Dialog>

      {/* Cancel Confirmation */}
      <Dialog open={cancelDialogOpen} onClose={() => setCancelDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Cancel FAAS Record?</DialogTitle>
        <DialogContent>
          <Typography sx={{ mb: 2 }}>Cancel TD# <strong>{cancelTarget?.tdno}</strong>? This will set the state to CANCELLED.</Typography>
          <TextField
            fullWidth
            label="Cancel Reason"
            value={cancelReason}
            onChange={(e) => setCancelReason(e.target.value)}
            size="small"
            sx={{ mb: 2 }}
          />
          <TextField
            fullWidth
            label="Cancel Note"
            value={cancelNote}
            onChange={(e) => setCancelNote(e.target.value)}
            size="small"
            multiline
            rows={2}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCancelDialogOpen(false)} disabled={actionSubmitting}>Back</Button>
          <Button onClick={handleCancel} color="warning" variant="contained" disabled={actionSubmitting}>
            {actionSubmitting ? 'Cancelling...' : 'Confirm Cancel'}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Approve Confirmation Dialog */}
      <Dialog open={approveDialogOpen} onClose={() => setApproveDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Approve Declaration?</DialogTitle>
        <DialogContent>
          <Typography sx={{ mb: 2 }}>
            Are you sure you want to approve TD# <strong>{approveTarget?.tdno}</strong>?
          </Typography>
          <Typography variant="body2" color="text.secondary">
            Approving this declaration will promote its state to <strong>CURRENT</strong>. If a previous tax declaration was referenced ({approveTarget?.prevtdno || 'none'}), it will be automatically cancelled.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setApproveDialogOpen(false)} disabled={actionSubmitting}>Cancel</Button>
          <Button onClick={handleApprove} color="success" variant="contained" disabled={actionSubmitting}>
            {actionSubmitting ? 'Approving...' : 'Approve & Make Current'}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Disapprove Confirmation Dialog */}
      <Dialog open={disapproveDialogOpen} onClose={() => setDisapproveDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Disapprove Declaration?</DialogTitle>
        <DialogContent>
          <Typography sx={{ mb: 2 }}>
            Return declaration TD# <strong>{disapproveTarget?.tdno}</strong> to draft status?
          </Typography>
          <TextField
            fullWidth
            label="Disapproval Reason"
            value={disapproveReason}
            onChange={(e) => setDisapproveReason(e.target.value)}
            size="small"
            multiline
            rows={3}
            placeholder="Enter reason for returning or rejecting this declaration..."
            sx={{ mt: 1 }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDisapproveDialogOpen(false)} disabled={actionSubmitting}>Back</Button>
          <Button onClick={handleDisapprove} color="error" variant="contained" disabled={actionSubmitting}>
            {actionSubmitting ? 'Returning...' : 'Disapprove'}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Form Modal */}
      <EtracsPropertyFormModal
        open={formOpen}
        onClose={() => { setFormOpen(false); setSelectedProperty(null); }}
        property={selectedProperty}
        onSave={handleFormSave}
        transactionTypes={transactionTypes}
      />
    </Box>
  );
};

export default EtracsPropertyTable;
