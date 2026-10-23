import React, { useState, useEffect, useCallback } from 'react';
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
  Avatar
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
} from '@mui/icons-material';
import { motion } from 'framer-motion';

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

const EtracsPropertyTable = () => {
  const { isSuperAdmin, isAdmin, isAssessor, canManage } = useAuth();
  const canEdit = isSuperAdmin || isAdmin;
  const canApprove = isSuperAdmin || isAdmin || isAssessor || canManage;

  const [properties, setProperties] = useState([]);
  const [loading, setLoading] = useState(true);
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
  const [stats, setStats] = useState(null);

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

  const fetchProperties = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const params = {
        page: page + 1,
        per_page: rowsPerPage,
      };
      if (searchQuery) params.q = searchQuery;
      if (stateFilter) params.state = stateFilter;
      if (rpuTypeFilter) params.rpu_type = rpuTypeFilter;
      if (txnTypeFilter) params.txntype = txnTypeFilter;

      const result = await etracsService.getFaasList(params);
      setProperties(result.data || []);
      setTotal(result.total || 0);
    } catch (err) {
      setError(err.message || 'Failed to load ETRACS properties');
      setProperties([]);
    } finally {
      setLoading(false);
    }
  }, [page, rowsPerPage, searchQuery, stateFilter, rpuTypeFilter, txnTypeFilter]);

  useEffect(() => {
    fetchProperties();
  }, [fetchProperties]);

  useEffect(() => {
    const loadMeta = async () => {
      try {
        const [txnTypes, statsData] = await Promise.all([
          etracsService.getTransactionTypes(),
          etracsService.getStats(),
        ]);
        setTransactionTypes(txnTypes || []);
        setStats(statsData);
      } catch (e) {
        console.warn('Failed to load ETRACS meta:', e);
      }
    };
    loadMeta();
  }, []);

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
    }, 500);
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
    } catch (err) {
      setError('Failed to delete record');
    }
  };

  const refreshAll = useCallback(async () => {
    fetchProperties();
    try {
      const statsData = await etracsService.getStats();
      setStats(statsData);
    } catch (_) {}
  }, [fetchProperties]);

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
        reason: disapproveReason,
      });
      setDisapproveDialogOpen(false);
      setDisapproveTarget(null);
      setDisapproveReason('');
      refreshAll();
    } catch (err) {
      setError(err.message || 'Failed to disapprove declaration');
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
    <Box>
      {/* Stats Cards */}
      {stats && (
        <Grid container spacing={3} sx={{ mb: 4 }} alignItems="stretch">
          {[
            { title: 'Total Records', value: stats.total?.toLocaleString() || 0, icon: <AssignmentIcon />, color: '#3b82f6' },
            { title: 'Current', value: stats.current?.toLocaleString() || 0, icon: <CheckCircleIcon />, color: '#10b981' },
            { title: 'For Approval', value: stats.forapproval?.toLocaleString() || 0, icon: <SendIcon />, color: '#f59e0b' },
            { title: 'Draft / Interim', value: stats.interim?.toLocaleString() || 0, icon: <EditIcon />, color: '#6366f1' },
            { title: 'Cancelled', value: stats.cancelled?.toLocaleString() || 0, icon: <BlockIcon />, color: '#ef4444' },
          ].map((stat, i) => (
            <Grid item xs={12} sm={6} md={2.4} key={i}>
              <motion.div
                whileHover={{ scale: 1.02, y: -5 }}
                transition={{ duration: 0.2 }}
                style={{ height: '100%' }}
              >
                <Card
                  sx={{
                    height: '100%',
                    minHeight: 140,
                    background: stat.color + '08',
                    border: `1px solid ${stat.color}20`,
                    position: 'relative',
                    overflow: 'hidden',
                    display: 'flex',
                    flexDirection: 'column'
                  }}
                >
                  <CardContent sx={{ p: 3, flexGrow: 1, display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
                    <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                      <Box>
                        <Typography
                          variant="h4"
                          component="div"
                          fontWeight={600}
                          color={stat.color}
                        >
                          {stat.value}
                        </Typography>
                        <Typography variant="body2" color="text.secondary" gutterBottom>
                          {stat.title}
                        </Typography>
                      </Box>
                      <Avatar
                        sx={{
                          backgroundColor: stat.color + '15',
                          color: stat.color,
                          width: 56,
                          height: 56
                        }}
                      >
                        {stat.icon}
                      </Avatar>
                    </Box>
                  </CardContent>
                </Card>
              </motion.div>
            </Grid>
          ))}
        </Grid>
      )}

      {/* Search & Filters */}
      <Paper sx={{ p: 2, mb: 2, borderRadius: 3 }}>
        <Grid container spacing={2} alignItems="center">
          <Grid item xs={12} md={4}>
            <TextField
              fullWidth
              size="small"
              placeholder="Search TD#, Owner, PIN, Lot..."
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              onKeyDown={handleKeyDown}
              InputProps={{
                startAdornment: <SearchIcon sx={{ color: 'text.secondary', mr: 1 }} />,
              }}
            />
          </Grid>
          <Grid item xs={6} md={2}>
            <FormControl fullWidth size="small">
              <InputLabel>State</InputLabel>
              <Select value={stateFilter} onChange={(e) => { setStateFilter(e.target.value); setPage(0); }} label="State">
                <MenuItem value="">All</MenuItem>
                <MenuItem value="CURRENT">Current</MenuItem>
                <MenuItem value="FORAPPROVAL">For Approval</MenuItem>
                <MenuItem value="INTERIM">Interim Draft</MenuItem>
                <MenuItem value="DISAPPROVED">Disapproved</MenuItem>
                <MenuItem value="CANCELLED">Cancelled</MenuItem>
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={6} md={2}>
            <FormControl fullWidth size="small">
              <InputLabel>Type</InputLabel>
              <Select value={rpuTypeFilter} onChange={(e) => { setRpuTypeFilter(e.target.value); setPage(0); }} label="Type">
                <MenuItem value="">All</MenuItem>
                <MenuItem value="LAND">Land</MenuItem>
                <MenuItem value="BLDG">Building</MenuItem>
                <MenuItem value="MACH">Machinery</MenuItem>
                <MenuItem value="PLANTTREE">Plant/Tree</MenuItem>
                <MenuItem value="MISC">Misc</MenuItem>
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={6} md={2}>
            <FormControl fullWidth size="small">
              <InputLabel>Transaction</InputLabel>
              <Select value={txnTypeFilter} onChange={(e) => { setTxnTypeFilter(e.target.value); setPage(0); }} label="Transaction">
                <MenuItem value="">All</MenuItem>
                {transactionTypes.map((t) => (
                  <MenuItem key={t.code} value={t.code}>{t.code} - {t.name}</MenuItem>
                ))}
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={6} md={2} sx={{ display: 'flex', gap: 1 }}>
            <Button variant="contained" onClick={handleSearch} startIcon={<SearchIcon />} size="small">
              Search
            </Button>
            <Tooltip title="Refresh">
              <IconButton onClick={fetchProperties} size="small">
                <RefreshIcon />
              </IconButton>
            </Tooltip>
          </Grid>
        </Grid>
      </Paper>

      {/* Action bar */}
      <Box sx={{ mb: 2, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <Box>
          {canApprove && (
            <Button
              variant="outlined"
              color="primary"
              startIcon={<HistoryIcon />}
              onClick={() => { setAuditTargetFaasId(null); setAuditDialogOpen(true); }}
              sx={{ borderRadius: 2, textTransform: 'none', fontWeight: 600 }}
            >
              Shadow Audit Log
            </Button>
          )}
        </Box>
        <Box>
          {canEdit && (
            <Button
              variant="contained"
              startIcon={<AddIcon />}
              onClick={() => { setSelectedProperty(null); setFormOpen(true); }}
              sx={{ borderRadius: 2, textTransform: 'none', fontWeight: 600 }}
            >
              New FAAS Record
            </Button>
          )}
        </Box>
      </Box>

      {error && <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError(null)}>{error}</Alert>}

      {/* Table */}
      <TableContainer component={Paper} sx={{ borderRadius: 3, boxShadow: '0 2px 8px rgba(0,0,0,0.06)' }}>
        <Table size="small">
          <TableHead>
            <TableRow sx={{ background: '#f8fafc' }}>
              <TableCell sx={{ fontWeight: 600 }}>TD No.</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>Owner</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>PIN</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>Barangay</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>Type</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>Class</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>Actual Use</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>Market Value</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>Assessed Value</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>Txn</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>Origin</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>State</TableCell>
              <TableCell sx={{ fontWeight: 600, textAlign: 'center' }}>Actions</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {loading ? (
              <TableRow>
                <TableCell colSpan={13} align="center" sx={{ py: 6 }}>
                  <CircularProgress size={32} />
                  <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>Loading ETRACS records...</Typography>
                </TableCell>
              </TableRow>
            ) : properties.length === 0 ? (
              <TableRow>
                <TableCell colSpan={13} align="center" sx={{ py: 6 }}>
                  <Typography variant="body1" color="text.secondary">
                    {searchQuery ? 'No records match your search' : 'No ETRACS records yet. Click "New FAAS Record" to add one.'}
                  </Typography>
                </TableCell>
              </TableRow>
            ) : (
              properties.map((row) => {
                const effectiveState = row.effective_state || row.state;
                const isReplica = row.origin_type === 'REPLICA';
                const st = stateColors[effectiveState] || stateColors.CURRENT;
                return (
                  <motion.tr
                    key={row.id}
                    component={TableRow}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.2 }}
                    hover
                    sx={{ '&:hover': { backgroundColor: '#f8fafc' }, cursor: 'pointer' }}
                    onClick={() => handleView(row.id)}
                  >
                    <TableCell>
                      <Typography variant="body2" fontWeight={600} color="primary.main">
                        {row.tdno || '—'}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2" sx={{ maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {row.owner_name || row.taxpayer_name || '—'}
                      </Typography>
                    </TableCell>
                    <TableCell><Typography variant="body2">{row.pin || row.rp_pin || '—'}</Typography></TableCell>
                    <TableCell><Typography variant="body2">{row.barangay || '—'}</Typography></TableCell>
                    <TableCell><Typography variant="body2">{row.rpu_type || '—'}</Typography></TableCell>
                    <TableCell><Typography variant="body2">{row.classification || '—'}</Typography></TableCell>
                    <TableCell><Typography variant="body2">{row.actualuse || '—'}</Typography></TableCell>
                    <TableCell><Typography variant="body2">{formatCurrency(row.total_market_value)}</Typography></TableCell>
                    <TableCell><Typography variant="body2">{formatCurrency(row.total_assessed_value)}</Typography></TableCell>
                    <TableCell>
                      <Chip label={row.txntype_code || '—'} size="small" variant="outlined" sx={{ fontSize: '0.7rem' }} />
                    </TableCell>
                    <TableCell>
                      <Chip
                        label={isReplica ? 'REPLICA' : 'LOCAL'}
                        size="small"
                        color={isReplica ? 'info' : 'success'}
                        variant="outlined"
                        sx={{ fontSize: '0.65rem', fontWeight: 700 }}
                      />
                    </TableCell>
                    <TableCell>
                      <Chip
                        label={st.label}
                        size="small"
                        sx={{ backgroundColor: st.bg, color: st.color, fontWeight: 600, fontSize: '0.7rem' }}
                      />
                    </TableCell>
                    <TableCell align="center" onClick={(e) => e.stopPropagation()}>
                      <Tooltip title="View Declaration">
                        <IconButton size="small" onClick={() => handleView(row.id)}>
                          <VisibilityIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                      {/* Audit History (Available to assessors/managers) */}
                      {canApprove && (
                        <Tooltip title="Audit History">
                          <IconButton size="small" color="default" onClick={() => { setAuditTargetFaasId(row.id); setAuditDialogOpen(true); }}>
                            <HistoryIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      )}
                      {/* Submit for Approval (Drafts: INTERIM, PENDING, DISAPPROVED) - LOCAL records only */}
                      {!isReplica && canEdit && (effectiveState === 'INTERIM' || effectiveState === 'PENDING' || effectiveState === 'DISAPPROVED') && (
                        <Tooltip title="Submit for Approval">
                          <IconButton size="small" color="primary" onClick={() => handleSubmitForApproval(row)}>
                            <SendIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      )}
                      {/* Assessor Approval Actions (FORAPPROVAL) - LOCAL records only */}
                      {!isReplica && canApprove && effectiveState === 'FORAPPROVAL' && (
                        <>
                          <Tooltip title="Approve Declaration">
                            <IconButton size="small" color="success" onClick={() => { setApproveTarget(row); setApproveDialogOpen(true); }}>
                              <ThumbUpIcon fontSize="small" />
                            </IconButton>
                          </Tooltip>
                          <Tooltip title="Disapprove / Return">
                            <IconButton size="small" color="error" onClick={() => { setDisapproveTarget(row); setDisapproveDialogOpen(true); }}>
                              <ThumbDownIcon fontSize="small" />
                            </IconButton>
                          </Tooltip>
                        </>
                      )}
                      {/* Edit (only local drafts can be edited) */}
                      {!isReplica && canEdit && (effectiveState === 'INTERIM' || effectiveState === 'PENDING' || effectiveState === 'DISAPPROVED') && (
                        <Tooltip title="Edit Draft">
                          <IconButton size="small" onClick={() => handleEdit(row.id)}>
                            <EditIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      )}
                      {/* Cancel (only CURRENT local declarations can be cancelled directly) */}
                      {!isReplica && canApprove && effectiveState === 'CURRENT' && (
                        <Tooltip title="Cancel FAAS">
                          <IconButton size="small" color="warning" onClick={() => { setCancelTarget(row); setCancelDialogOpen(true); }}>
                            <CancelIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      )}
                      {/* Delete (only unapproved local drafts can be deleted) */}
                      {!isReplica && canEdit && (effectiveState === 'INTERIM' || effectiveState === 'PENDING' || effectiveState === 'DISAPPROVED') && (
                        <Tooltip title="Delete Draft">
                          <IconButton size="small" color="error" onClick={() => { setDeleteTarget(row); setDeleteDialogOpen(true); }}>
                            <DeleteIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      )}
                    </TableCell>
                  </motion.tr>
                );
              })
            )}
          </TableBody>
        </Table>
        <TablePagination
          component="div"
          count={total}
          page={page}
          onPageChange={(_, p) => setPage(p)}
          rowsPerPage={rowsPerPage}
          onRowsPerPageChange={(e) => { setRowsPerPage(parseInt(e.target.value, 10)); setPage(0); }}
          rowsPerPageOptions={[10, 20, 50, 100]}
        />
      </TableContainer>

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
