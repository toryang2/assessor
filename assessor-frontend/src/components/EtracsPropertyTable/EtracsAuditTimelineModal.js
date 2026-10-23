import React, { useState, useEffect, useCallback } from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Typography,
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
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  Chip,
  IconButton,
  CircularProgress,
  Alert,
  Tooltip,
  Grid,
  Divider,
} from '@mui/material';
import {
  Refresh as RefreshIcon,
  History as HistoryIcon,
  Close as CloseIcon,
  Sync as SyncIcon,
  Create as CreateIcon,
  CheckCircle as ApproveIcon,
  Cancel as CancelIcon,
  ThumbDown as DisapproveIcon,
  Send as SubmitIcon,
  Edit as EditIcon,
  Layers as LineageIcon,
  Assessment as AssessmentIcon,
} from '@mui/icons-material';
import { etracsService } from '../../utils/api';

const eventTypeConfig = {
  CREATE: { label: 'Created', color: '#0284c7', bg: '#e0f2fe', icon: <CreateIcon fontSize="small" /> },
  UPDATE: { label: 'Updated', color: '#475569', bg: '#f1f5f9', icon: <EditIcon fontSize="small" /> },
  SUBMIT: { label: 'Submitted', color: '#b45309', bg: '#fef3c7', icon: <SubmitIcon fontSize="small" /> },
  APPROVE: { label: 'Approved', color: '#15803d', bg: '#dcfce7', icon: <ApproveIcon fontSize="small" /> },
  DISAPPROVE: { label: 'Disapproved', color: '#b91c1c', bg: '#fee2e2', icon: <DisapproveIcon fontSize="small" /> },
  CANCEL: { label: 'Cancelled', color: '#dc2626', bg: '#ffe4e6', icon: <CancelIcon fontSize="small" /> },
  SUPERSEDED: { label: 'Superseded', color: '#9333ea', bg: '#f3e8ff', icon: <LineageIcon fontSize="small" /> },
  LINEAGE_PERSIST: { label: 'Lineage Stored', color: '#7c3aed', bg: '#ede9fe', icon: <LineageIcon fontSize="small" /> },
  ASSESSMENT_UPDATE: { label: 'Assessments Updated', color: '#0d9488', bg: '#ccfbf1', icon: <AssessmentIcon fontSize="small" /> },
  POINTER_ADVANCE: { label: 'RPUMaster Advanced', color: '#0369a1', bg: '#e0f2fe', icon: <LineageIcon fontSize="small" /> },
  DELETE: { label: 'Draft Deleted', color: '#64748b', bg: '#f1f5f9', icon: <CancelIcon fontSize="small" /> },
  SYNC_PULL: { label: 'Source Sync', color: '#2563eb', bg: '#dbeafe', icon: <SyncIcon fontSize="small" /> },
};

export const EtracsAuditTimelineModal = ({
  open,
  onClose,
  faasId = null,
  objectType = 'FAAS',
  title = 'Shadow ETRACS Audit History',
}) => {
  const [events, setEvents] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(0);
  const [perPage, setPerPage] = useState(10);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  // Filters
  const [eventTypeFilter, setEventTypeFilter] = useState('');
  const [sourceClassFilter, setSourceClassFilter] = useState('');
  const [originTypeFilter, setOriginTypeFilter] = useState('');
  const [searchCorrelation, setSearchCorrelation] = useState('');

  const fetchAuditEvents = useCallback(async () => {
    if (!open) return;
    try {
      setLoading(true);
      setError(null);

      const params = {
        page: page + 1,
        per_page: perPage,
      };

      if (faasId) {
        params.faas_id = faasId;
      }
      if (objectType && !faasId) {
        params.object_type = objectType;
      }
      if (eventTypeFilter) {
        params.event_type = eventTypeFilter;
      }
      if (sourceClassFilter) {
        params.source_classification = sourceClassFilter;
      }
      if (originTypeFilter) {
        params.origin_type = originTypeFilter;
      }
      if (searchCorrelation) {
        params.correlation_id = searchCorrelation;
      }

      const res = await etracsService.getAuditTrail(params);
      setEvents(res.data || []);
      setTotal(res.total || 0);
    } catch (err) {
      if (err.status === 403) {
        setError('Access restricted. Manager or Assessor privileges required to view shadow audit trail.');
      } else {
        setError(err.message || 'Audit history is currently unavailable.');
      }
      setEvents([]);
    } finally {
      setLoading(false);
    }
  }, [open, faasId, objectType, page, perPage, eventTypeFilter, sourceClassFilter, originTypeFilter, searchCorrelation]);

  useEffect(() => {
    if (open) {
      fetchAuditEvents();
    }
  }, [open, fetchAuditEvents]);

  const formatTimestamp = (utcStr, rawStr) => {
    if (utcStr) {
      return (
        <Tooltip title={`Stored UTC Instant: ${utcStr}`}>
          <span>{utcStr.replace('T', ' ').replace('Z', ' UTC')}</span>
        </Tooltip>
      );
    }
    if (rawStr) {
      return `${rawStr} (UTC)`;
    }
    return '—';
  };

  const renderDetailsSummary = (event) => {
    const details = event.details;
    if (!details) {
      if (event.old_state || event.new_state) {
        return (
          <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>
            State: <strong>{event.old_state || 'NONE'}</strong> → <strong>{event.new_state}</strong>
          </Typography>
        );
      }
      return <Typography variant="caption" color="text.secondary">No additional details recorded</Typography>;
    }

    const isObj = typeof details === 'object' && details !== null;

    return (
      <Box sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>
        {event.old_state && event.new_state && (
          <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.primary', display: 'block', mb: 0.5 }}>
            State Transition: {event.old_state} → {event.new_state}
          </Typography>
        )}
        {isObj ? (
          <Box component="ul" sx={{ pl: 2, m: 0 }}>
            {Object.entries(details).map(([k, v]) => {
              if (v === null || v === undefined) return null;
              const displayVal = typeof v === 'object' ? JSON.stringify(v) : String(v);
              return (
                <li key={k}>
                  <strong>{k.replace(/_/g, ' ')}:</strong> {displayVal}
                </li>
              );
            })}
          </Box>
        ) : (
          <span>{String(details)}</span>
        )}
      </Box>
    );
  };

  return (
    <Dialog open={open} onClose={onClose} maxWidth="lg" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', pb: 1 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <HistoryIcon color="primary" />
          <Typography variant="h6" fontWeight={700}>
            {title} {faasId ? `(FAAS ID: ${faasId})` : ''}
          </Typography>
        </Box>
        <IconButton onClick={onClose} size="small">
          <CloseIcon />
        </IconButton>
      </DialogTitle>
      <Divider />

      <DialogContent sx={{ p: 3, bgcolor: '#f8fafc' }}>
        {/* Filters */}
        <Paper sx={{ p: 2, mb: 2, borderRadius: 2 }}>
          <Grid container spacing={2} alignItems="center">
            <Grid item xs={12} sm={3}>
              <FormControl fullWidth size="small">
                <InputLabel>Event Type</InputLabel>
                <Select
                  value={eventTypeFilter}
                  label="Event Type"
                  onChange={(e) => {
                    setEventTypeFilter(e.target.value);
                    setPage(0);
                  }}
                >
                  <MenuItem value="">All Events</MenuItem>
                  {Object.keys(eventTypeConfig).map((key) => (
                    <MenuItem key={key} value={key}>
                      {eventTypeConfig[key].label} ({key})
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </Grid>
            <Grid item xs={6} sm={2.5}>
              <FormControl fullWidth size="small">
                <InputLabel>Provenance</InputLabel>
                <Select
                  value={sourceClassFilter}
                  label="Provenance"
                  onChange={(e) => {
                    setSourceClassFilter(e.target.value);
                    setPage(0);
                  }}
                >
                  <MenuItem value="">All Classifications</MenuItem>
                  <MenuItem value="LOCAL_AUTHORING">Local Authoring</MenuItem>
                  <MenuItem value="APPROVAL">Approval Transition</MenuItem>
                  <MenuItem value="CANCELLATION">Cancellation</MenuItem>
                  <MenuItem value="LINEAGE">Lineage/Supersession</MenuItem>
                  <MenuItem value="SOURCE_SYNC">Source Sync (Replica)</MenuItem>
                </Select>
              </FormControl>
            </Grid>
            <Grid item xs={6} sm={2.5}>
              <FormControl fullWidth size="small">
                <InputLabel>Ownership</InputLabel>
                <Select
                  value={originTypeFilter}
                  label="Ownership"
                  onChange={(e) => {
                    setOriginTypeFilter(e.target.value);
                    setPage(0);
                  }}
                >
                  <MenuItem value="">All Origins</MenuItem>
                  <MenuItem value="LOCAL">LOCAL</MenuItem>
                  <MenuItem value="REPLICA">REPLICA</MenuItem>
                </Select>
              </FormControl>
            </Grid>
            <Grid item xs={8} sm={3}>
              <TextField
                fullWidth
                size="small"
                label="Correlation ID"
                placeholder="Search UUID..."
                value={searchCorrelation}
                onChange={(e) => setSearchCorrelation(e.target.value)}
              />
            </Grid>
            <Grid item xs={4} sm={1} sx={{ display: 'flex', justifyContent: 'flex-end' }}>
              <Tooltip title="Refresh Audit Trail">
                <IconButton onClick={fetchAuditEvents} color="primary">
                  <RefreshIcon />
                </IconButton>
              </Tooltip>
            </Grid>
          </Grid>
        </Paper>

        {error && (
          <Alert severity={error.includes('restricted') ? 'warning' : 'error'} sx={{ mb: 2 }}>
            {error}
          </Alert>
        )}

        {/* Audit Table */}
        <TableContainer component={Paper} sx={{ borderRadius: 2, boxShadow: '0 1px 4px rgba(0,0,0,0.06)' }}>
          <Table size="small">
            <TableHead>
              <TableRow sx={{ bgcolor: '#f1f5f9' }}>
                <TableCell sx={{ fontWeight: 600, width: '180px' }}>UTC Timestamp</TableCell>
                <TableCell sx={{ fontWeight: 600, width: '140px' }}>Actor / User</TableCell>
                <TableCell sx={{ fontWeight: 600, width: '150px' }}>Event</TableCell>
                <TableCell sx={{ fontWeight: 600, width: '110px' }}>Outcome</TableCell>
                <TableCell sx={{ fontWeight: 600, width: '120px' }}>Provenance</TableCell>
                <TableCell sx={{ fontWeight: 600 }}>Object & Details</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {loading ? (
                <TableRow>
                  <TableCell colSpan={6} align="center" sx={{ py: 6 }}>
                    <CircularProgress size={28} />
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
                      Loading audit events...
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : events.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} align="center" sx={{ py: 6 }}>
                    <Typography variant="body2" color="text.secondary">
                      {error ? 'Audit records unavailable.' : 'No audit history records found for this query.'}
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : (
                events.map((ev) => {
                  const cfg = eventTypeConfig[ev.event_type] || {
                    label: ev.event_type,
                    color: '#475569',
                    bg: '#f1f5f9',
                  };
                  const isSuccess = ev.action_outcome === 'SUCCESS';
                  const isAborted = ev.action_outcome === 'ABORTED';

                  return (
                    <TableRow key={ev.event_id || ev.id} hover sx={{ '&:hover': { bgcolor: '#f8fafc' } }}>
                      <TableCell sx={{ fontSize: '0.8rem', fontFamily: 'monospace' }}>
                        {formatTimestamp(ev.created_at_utc, ev.created_at)}
                      </TableCell>
                      <TableCell>
                        <Typography variant="body2" fontWeight={600} sx={{ fontSize: '0.8rem' }}>
                          {ev.actor_name || 'System'}
                        </Typography>
                        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', fontSize: '0.7rem' }}>
                          {ev.user_id ? `UID: ${ev.user_id}` : ''}
                        </Typography>
                      </TableCell>
                      <TableCell>
                        <Chip
                          icon={cfg.icon}
                          label={cfg.label}
                          size="small"
                          sx={{
                            bgcolor: cfg.bg,
                            color: cfg.color,
                            fontWeight: 600,
                            fontSize: '0.7rem',
                            '& .MuiChip-icon': { color: cfg.color },
                          }}
                        />
                      </TableCell>
                      <TableCell>
                        <Chip
                          label={ev.action_outcome || 'SUCCESS'}
                          size="small"
                          variant="outlined"
                          color={isSuccess ? 'success' : isAborted ? 'warning' : 'error'}
                          sx={{ fontSize: '0.7rem', fontWeight: 600 }}
                        />
                      </TableCell>
                      <TableCell>
                        <Chip
                          label={ev.origin_type || 'LOCAL'}
                          size="small"
                          sx={{
                            fontSize: '0.65rem',
                            bgcolor: ev.origin_type === 'REPLICA' ? '#e0f2fe' : '#f0fdf4',
                            color: ev.origin_type === 'REPLICA' ? '#0369a1' : '#15803d',
                            fontWeight: 600,
                            mr: 0.5,
                          }}
                        />
                        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', fontSize: '0.7rem' }}>
                          {ev.source_classification || ''}
                        </Typography>
                      </TableCell>
                      <TableCell>
                        <Typography variant="body2" fontWeight={600} sx={{ fontSize: '0.8rem' }}>
                          {ev.object_type}: {ev.object_id || ev.faas_id || '—'}
                        </Typography>
                        {renderDetailsSummary(ev)}
                      </TableCell>
                    </TableRow>
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
            rowsPerPage={perPage}
            onRowsPerPageChange={(e) => {
              setPerPage(parseInt(e.target.value, 10));
              setPage(0);
            }}
            rowsPerPageOptions={[5, 10, 20, 50]}
          />
        </TableContainer>
      </DialogContent>

      <DialogActions sx={{ p: 2, bgcolor: '#f1f5f9' }}>
        <Button onClick={onClose} variant="contained" color="primary">
          Close
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default EtracsAuditTimelineModal;
