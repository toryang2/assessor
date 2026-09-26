import React, { useState, useEffect, useRef, useMemo, useCallback } from 'react';
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
  Chip,
  FormControl,
  CircularProgress,
  InputLabel,
  Select,
  MenuItem,
  Tooltip
} from '@mui/material';
import {
  Search as SearchIcon,
  Add as AddIcon,
  Delete as DeleteIcon,
  Print as PrintIcon,
  FilterList as FilterListIcon,
  Clear as ClearIcon,
  Refresh as RefreshIcon,
  PlaylistAdd as PlaylistAddIcon,
  ExpandMore as ExpandMoreIcon,
  ExpandLess as ExpandLessIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';

import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';
import { useReactToPrint } from 'react-to-print';
import RequestFormModal from '../RequestFormModal/RequestFormModal';
import BulkRequestModal from '../BulkRequestModal/BulkRequestModal';
import LoadingDots from '../LoadingDots';
import useLoadingWatchdog from '../../hooks/useLoadingWatchdog';
import { formatAppDate } from '../../utils/dateTime';
import HistoryPrintDocument, { getHistoryPrintPageStyle } from '../HistoryPrintDocument/HistoryPrintDocument';

// Format declarant from discrete fields; add dot only for single-character middle
const formatDeclarantFromParts = (last, first, middle) => {
  const hasNames = !!(last || first);
  if (!hasNames) return '';
  const raw = (middle || '').trim();
  const mi = raw.replace(/\./g, '');
  const middleFormatted = mi ? (mi.length === 1 ? ` ${mi}.` : ` ${mi}`) : '';
  return `${last || ''}${hasNames && first ? ', ' : ''}${first || ''}${middleFormatted}`.trim();
};

// Format date function - table cells
const formatDateTable = (dateString) => {
  if (!dateString) return '';
  return formatAppDate(dateString, { month: '2-digit', day: '2-digit', year: 'numeric' });
};

// Custom hook for debounced search
const useDebounce = (value, delay) => {
  const [debouncedValue, setDebouncedValue] = useState(value);

  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedValue(value);
    }, delay);

    return () => {
      clearTimeout(handler);
    };
  }, [value, delay]);

  return debouncedValue;
};

const RequestsTable = () => {
  const tableContainerRef = useRef(null);
  const { isAdmin, isSuperAdmin, isViewer } = useAuth();

  // Grouped requests states
  const [groups, setGroups] = useState([]);
  const [loading, setLoading] = useState(true);
  const [initialLoad, setInitialLoad] = useState(true);
  const [error, setError] = useState('');
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(20);
  const [totalCount, setTotalCount] = useState(0);
  const [allGroups, setAllGroups] = useState([]);

  // Expanded batch groups
  const [expandedGroups, setExpandedGroups] = useState(() => new Set());

  // Safety check - ensure groups is always an array
  const safeGroups = useMemo(() => groups || [], [groups]);

  // Search state
  const [searchTerm, setSearchTerm] = useState('');
  const debouncedSearchTerm = useDebounce(searchTerm, 300); // 300ms delay

  // Filter states
  const [filters, setFilters] = useState({
    purpose: '',
    dateIssued: '',
    preparedBy: ''
  });
  const [filterModal, setFilterModal] = useState(false);
  const [users, setUsers] = useState([]);
  const [purposeOptions, setPurposeOptions] = useState([]);

  // Modal states
  const [printModal, setPrintModal] = useState(false);
  const [printRequestData, setPrintRequestData] = useState(null);
  const [printHistory, setPrintHistory] = useState([]);
  const [printLoading, setPrintLoading] = useState(false);
  const [printGeneratedAt, setPrintGeneratedAt] = useState(null);
  const [requestFormModal, setRequestFormModal] = useState(false);
  const [bulkRequestModal, setBulkRequestModal] = useState(false);

  // Settings state
  const [settings, setSettings] = useState({});

  // Universal safety watchdog: prevent infinite initial loading if the backend/network hangs
  useLoadingWatchdog({
    isLoading: loading,
    isInitialLoad: initialLoad,
    setLoading,
    setInitialLoad,
    setError,
    componentName: 'RequestsTable',
    timeoutMs: 20000,
    timeoutMessage: 'Request data timed out. Please check your connection and try again.',
    enabled: true
  });

  // Filtered groups for client-side filtering when searching
  const filteredGroups = useMemo(() => {
    const base = (allGroups && allGroups.length) ? allGroups : safeGroups;
    return (base || []).filter((group) => {
      if (!group) return false;

      // Apply search filter across group and all its children
      if (debouncedSearchTerm) {
        const searchUpper = debouncedSearchTerm.toUpperCase();

        const matchesGroupHeader =
          (group.client_name && String(group.client_name).toUpperCase().includes(searchUpper)) ||
          (group.client_address && String(group.client_address).toUpperCase().includes(searchUpper)) ||
          (group.purpose && String(group.purpose).toUpperCase().includes(searchUpper)) ||
          (group.purpose_details && String(group.purpose_details).toUpperCase().includes(searchUpper)) ||
          (group.remarks && String(group.remarks).toUpperCase().includes(searchUpper)) ||
          (group.prepared_by && String(group.prepared_by).toUpperCase().includes(searchUpper));

        if (matchesGroupHeader) return true;

        // Check if ANY child request matches the search
        const matchesAnyChild = (group.children || []).some((child) => {
          return (
            (child.receipt_number && String(child.receipt_number).toUpperCase().includes(searchUpper)) ||
            (child.tax_declaration_number && String(child.tax_declaration_number).toUpperCase().includes(searchUpper)) ||
            (child.declarant_last_name && String(child.declarant_last_name).toUpperCase().includes(searchUpper)) ||
            (child.declarant_first_name && String(child.declarant_first_name).toUpperCase().includes(searchUpper)) ||
            (child.declarant_middle_initial && String(child.declarant_middle_initial).toUpperCase().includes(searchUpper)) ||
            (child.business && String(child.business).toUpperCase().includes(searchUpper)) ||
            (child.location && String(child.location).toUpperCase().includes(searchUpper)) ||
            (child.purpose && String(child.purpose).toUpperCase().includes(searchUpper)) ||
            (child.purpose_details && String(child.purpose_details).toUpperCase().includes(searchUpper)) ||
            (child.remarks && String(child.remarks).toUpperCase().includes(searchUpper)) ||
            (child.prepared_by && String(child.prepared_by).toUpperCase().includes(searchUpper))
          );
        });

        if (!matchesAnyChild) return false;
      }

      // Apply other filters
      if (filters.purpose && group.purpose !== filters.purpose) return false;
      if (filters.dateIssued && group.date_issued !== filters.dateIssued) return false;
      if (filters.preparedBy && group.prepared_by !== filters.preparedBy) return false;

      return true;
    });
  }, [allGroups, safeGroups, debouncedSearchTerm, filters]);

  // Paged groups for display
  const pagedGroups = useMemo(() => {
    // Normal browsing uses server-side pagination.
    // safeGroups already contains exactly the requested API page.
    if (!debouncedSearchTerm) {
      return safeGroups;
    }

    // Search mode loads the full matching dataset,
    // therefore frontend pagination is required here.
    const start = page * rowsPerPage;
    const end = start + rowsPerPage;
    return filteredGroups.slice(start, end);
  }, [
    filteredGroups,
    safeGroups,
    page,
    rowsPerPage,
    debouncedSearchTerm
  ]);

  // Paper size state with localStorage persistence
  const [paperSize, setPaperSize] = useState(() => {
    try {
      return localStorage.getItem('assessor_print_paper_size') || 'a4';
    } catch (_) {
      return 'a4';
    }
  });

  const handlePaperSizeChange = (newSize) => {
    if (!newSize) return;
    setPaperSize(newSize);
    try {
      localStorage.setItem('assessor_print_paper_size', newSize);
    } catch (_) { }
  };

  // Print ref
  const printRef = useRef(null);

  const getPrintFontUrl = (filename) => {
    const publicUrl = (process.env.PUBLIC_URL || '').replace(/\/$/, '');
    const relativePath = `${publicUrl || '.'}/fonts/${filename}`;

    return new URL(relativePath, document.baseURI).toString();
  };

  const handlePrint = useReactToPrint({
    contentRef: printRef,
    removeAfterPrint: true,

    fonts: [
      {
        family: 'Plus Jakarta Sans',
        source: getPrintFontUrl('PlusJakartaSans-VariableFont.ttf'),
        weight: '300 900',
        style: 'normal',
      },
      {
        family: 'Space Mono',
        source: getPrintFontUrl('SpaceMono-Regular.ttf'),
        weight: '400',
        style: 'normal',
      },
      {
        family: 'Space Mono',
        source: getPrintFontUrl('SpaceMono-Bold.ttf'),
        weight: '700',
        style: 'normal',
      },
    ],

    pageStyle: getHistoryPrintPageStyle(paperSize),
  });

  // Fetch requests (grouped=1)
  const fetchSeqRef = useRef(0);
  const fetchRequests = useCallback(async (forceRefresh = false) => {
    const seq = ++fetchSeqRef.current;
    try {
      setLoading(true);
      setError('');

      const params = {
        grouped: 1,
        page: page + 1,
        per_page: rowsPerPage,
        search: debouncedSearchTerm || '',
        purpose: filters.purpose || '',
        date_issued: filters.dateIssued || '',
        prepared_by: filters.preparedBy || '',
        // Add cache busting timestamp to prevent browser caching
        _t: forceRefresh ? Date.now() : Date.now()
      };

      const response = await apiService.getRequests(params);

      // Ignore if a newer request has started
      if (seq !== fetchSeqRef.current) return;

      if (response && response.groups) {
        setGroups(response.groups);
        setTotalCount(response.pagination ? response.pagination.total : response.groups.length);
      } else if (response && response.requests) {
        // Fallback for standard flat format if backend isn't grouped
        const fakeGroups = response.requests.map(r => ({
          group_key: r.id,
          batch_id: r.batch_id || null,
          is_bulk: !!r.batch_id,
          request_count: 1,
          total_amount: parseFloat(r.amount_paid || 0),
          client_name: r.client_name,
          client_address: r.client_address,
          contact_number: r.contact_number,
          email: r.email,
          purpose_details: r.purpose_details,
          remarks: r.remarks,
          date_issued: r.date_issued,
          place_issued: r.place_issued,
          prepared_by: r.prepared_by,
          payment_type: r.payment_type,
          is_official_request: r.is_official_request,
          children: [r]
        }));
        setGroups(fakeGroups);
        setTotalCount(response.pagination ? response.pagination.total : fakeGroups.length);
      } else {
        setGroups([]);
        setTotalCount(0);
      }
    } catch (err) {
      console.error('Error fetching requests:', err);
      setError(`Failed to fetch requests: ${err.message || 'Unknown error'}`);
      setGroups([]);
      setTotalCount(0);
    } finally {
      if (seq === fetchSeqRef.current) {
        setLoading(false);
        setInitialLoad(false);
      }
    }
  }, [page, rowsPerPage, debouncedSearchTerm, filters]);

  // Fetch settings
  const fetchSettings = async () => {
    try {
      const response = await apiService.getSettings();
      setSettings(response || {});
    } catch (err) {
      console.error('Error fetching settings:', err);
    }
  };

  // Fetch request purposes from settings
  const fetchRequestPurposes = async () => {
    try {
      const response = await apiService.getRequestPurposes();

      const activeItems = (response?.items || [])
        .filter((item) => item?.status === 'active')
        .map((item) => ({
          value: String(item.purpose || ''),
          label: String(item.purpose || '')
        }))
        .filter((item) => item.value);

      setPurposeOptions(activeItems);
    } catch (err) {
      console.error('Error fetching request purposes:', err);
      setPurposeOptions([]);
    }
  };

  // Load data on component mount
  useEffect(() => {
    fetchSettings();
    fetchUsers();
    fetchRequestPurposes();
  }, []);

  // Fetch requests for pagination (only when not searching)
  useEffect(() => {
    if (!debouncedSearchTerm) {
      fetchRequests();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, rowsPerPage, debouncedSearchTerm, filters]);

  // Fetch full grouped dataset for client-side filtering/pagination when searching
  useEffect(() => {
    const fetchAll = async () => {
      try {
        const params = {
          grouped: 1,
          all: 1,
          search: debouncedSearchTerm || '',
          purpose: filters.purpose || '',
          date_issued: filters.dateIssued || '',
          prepared_by: filters.preparedBy || '',
          _t: Date.now()
        };
        const response = await apiService.getRequests(params);
        const items = response?.groups || [];
        setAllGroups(items);
      } catch (e) {
        setAllGroups([]);
      }
    };

    if (debouncedSearchTerm) {
      fetchAll();
    } else {
      setAllGroups([]);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearchTerm, filters]);

  // Handle search
  const handleSearch = (event) => {
    const raw = event.target.value || '';
    const normalized = raw
      .replace(/[\u2013\u2014]/g, '-') // en/em dash to hyphen
      .replace(/\s*-\s*/g, '-')        // collapse spaces around hyphen
      .trim();
    setSearchTerm(normalized);
    setPage(0);
  };

  // Handle filter changes
  const handleFilterChange = (field, value) => {
    setFilters(prev => ({
      ...prev,
      [field]: value
    }));
    setPage(0);
  };

  // Fetch users for prepared by dropdown
  const fetchUsers = async () => {
    try {
      const response = await apiService.getUsers();
      setUsers(response?.users || []);
    } catch (err) {
      console.error('Error fetching users:', err);
    }
  };

  // Clear all filters
  const clearFilters = () => {
    setSearchTerm('');
    setFilters({
      purpose: '',
      dateIssued: '',
      preparedBy: ''
    });
    setPage(0);
  };

  // Handle page change
  const handleChangePage = (event, newPage) => {
    setPage(newPage);
    if (tableContainerRef.current) {
      tableContainerRef.current.scrollTo(0, 0);
    }
  };

  // Handle rows per page change
  const handleChangeRowsPerPage = (event) => {
    const newRows = parseInt(event.target.value, 10);
    setRowsPerPage(newRows);
    setPage(0);
  };

  // Toggle expand group
  const handleToggleExpand = (groupKey) => {
    setExpandedGroups(prev => {
      const next = new Set(prev);
      if (next.has(groupKey)) {
        next.delete(groupKey);
      } else {
        next.add(groupKey);
      }
      return next;
    });
  };

  // Handle print request
  const handlePrintRequest = async (request) => {
    try {
      const generatedAt = new Date();
      setPrintGeneratedAt(generatedAt);

      setPrintLoading(true);
      setPrintModal(true);
      setPrintRequestData(request);
      setPrintHistory([]);

      if (request.tax_declaration_number) {
        const response = await apiService.getTaxDeclarationHistory(request.tax_declaration_number);
        if (response && Array.isArray(response)) {
          setPrintHistory(response);
        } else {
          setPrintHistory([]);
        }
      } else {
        if (request.tax_declaration_number || request.declarant_last_name || request.business) {
          const fallbackHistory = [{
            tax_declaration_number: request.tax_declaration_number || '',
            declarant_name: `${request.declarant_last_name || ''}${request.declarant_first_name ? ', ' + request.declarant_first_name : ''}${request.declarant_middle_initial ? ' ' + request.declarant_middle_initial + '.' : ''}`,
            business_name: request.business || '',
            location: request.location || '',
            assessed_value: request.assessed_value || '',
            effectivity_date: request.date_issued || '',
            created_at: request.updated_at || new Date().toISOString(),
            created_by_name: request.created_by_name || request.prepared_by || ''
          }];
          setPrintHistory(fallbackHistory);
        } else {
          setPrintHistory([]);
        }
      }
    } catch (err) {
      console.error('Error fetching tax declaration history:', err);
      setPrintHistory([]);
    } finally {
      setPrintLoading(false);
    }
  };

  // Normal request creation success
  const handleRequestFormSaved = () => {
    setRequestFormModal(false);
    setPage(0);
    fetchRequests(true);
  };

  // Bulk request creation success
  const handleBulkRequestSuccess = (response) => {
    setBulkRequestModal(false);
    setPage(0);
    fetchRequests(true);
    if (response?.batch_id) {
      setExpandedGroups(prev => {
        const next = new Set(prev);
        next.add(response.batch_id);
        return next;
      });
    }
  };

  // Handle delete request
  const handleDeleteRequest = async (requestId) => {
    if (!window.confirm('Are you sure you want to delete this request?')) {
      return;
    }

    try {
      await apiService.deleteRequest(requestId);
      fetchRequests(true);
    } catch (err) {
      console.error('Error deleting request:', err);
      alert('Failed to delete request');
    }
  };

  // Show initial loading state
  if (initialLoad && loading && (!safeGroups || safeGroups.length === 0)) {
    return (
      <Box sx={{
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'center',
        alignItems: 'center',
        minHeight: '70vh',
        gap: 2
      }}>
        <CircularProgress size={50} thickness={4} />
        <Typography variant="h6" color="text.secondary">
          Loading requests<LoadingDots />
        </Typography>
      </Box>
    );
  }

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}
      sx={{
        display: 'flex',
        flexDirection: 'column',
        height: 'calc(100vh - 64px - 3rem)',
        overflow: 'hidden'
      }}>
      {/* Page Header */}
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2, flexShrink: 0 }}>
        <Box>
          <Typography variant="h5" sx={{ fontWeight: 700, color: 'text.primary', letterSpacing: '-0.01em' }}>
            Requests Management
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.25 }}>
            Manage payment requests, receipts, clients, and related property records.
          </Typography>
        </Box>
        <Chip
          label={`${totalCount.toLocaleString()} request groups`}
          size="small"
          sx={{
            height: '24px',
            fontSize: '0.75rem',
            fontWeight: 600,
            backgroundColor: '#f1f5f9',
            color: '#475569',
            border: '1px solid #e2e8f0'
          }}
        />
      </Box>

      {/* Search and Actions Toolbar */}
      <Card
        sx={{
          mb: 2.5,
          flexShrink: 0,
          borderRadius: 3,
          border: '1px solid',
          borderColor: 'divider',
          boxShadow: '0 6px 20px rgba(15, 23, 42, 0.045)'
        }}
      >
        <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
          <Grid container spacing={2} alignItems="center">
            <Grid item xs={12} md={5}>
              <TextField
                fullWidth
                size='small'
                placeholder="Search by receipt, client, address, declarant, TD, remarks, or details..."
                value={searchTerm}
                onChange={handleSearch}
                InputProps={{
                  startAdornment: <SearchIcon sx={{ mr: 1, color: 'text.secondary' }} />
                }}
              />
            </Grid>
            <Grid item xs={12} md={7}>
              <Box sx={{ display: 'flex', gap: 1, justifyContent: { xs: 'flex-start', md: 'flex-end' }, alignItems: 'center', flexWrap: 'wrap' }}>
                <Tooltip title="Refresh requests" arrow>
                  <IconButton
                    size="small"
                    onClick={() => fetchRequests(true)}
                    color="default"
                    sx={{
                      height: 40,
                      width: 40,
                      border: '1px solid #cbd5e1',
                      borderRadius: 1
                    }}
                  >
                    <RefreshIcon fontSize="small" />
                  </IconButton>
                </Tooltip>
                <Button
                  size="small"
                  variant="outlined"
                  startIcon={<FilterListIcon />}
                  onClick={() => setFilterModal(true)}
                  color="primary"
                  sx={{
                    height: 40,
                    px: 2,
                    fontWeight: 600
                  }}
                >
                  Filters
                </Button>
                <Button
                  size="small"
                  variant="outlined"
                  startIcon={<PlaylistAddIcon />}
                  onClick={() => setBulkRequestModal(true)}
                  color="primary"
                  sx={{
                    height: 40,
                    px: 2,
                    fontWeight: 600
                  }}
                >
                  Bulk Request
                </Button>
                <Button
                  size="small"
                  variant="contained"
                  startIcon={<AddIcon />}
                  onClick={() => setRequestFormModal(true)}
                  color="primary"
                  sx={{
                    height: 40,
                    px: 2,
                    fontWeight: 600
                  }}
                >
                  Create Request
                </Button>
              </Box>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* Filter Modal */}
      <Dialog
        open={filterModal}
        onClose={() => setFilterModal(false)}
        maxWidth="sm"
        fullWidth
      >
        <DialogTitle>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <FilterListIcon />
            Filter Requests
          </Box>
        </DialogTitle>
        <DialogContent>
          <Grid container spacing={2} sx={{ mt: 1 }}>
            <Grid item xs={12}>
              <FormControl fullWidth size="small">
                <InputLabel>Purpose</InputLabel>
                <Select
                  value={filters.purpose}
                  onChange={(e) => handleFilterChange('purpose', e.target.value)}
                  label="Purpose"
                >
                  <MenuItem value="">All Purposes</MenuItem>
                  {purposeOptions.map((option) => (
                    <MenuItem key={option.value} value={option.value}>
                      {option.label}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </Grid>
            <Grid item xs={12}>
              <TextField
                fullWidth
                size="small"
                label="Date Issued"
                type="date"
                value={filters.dateIssued}
                onChange={(e) => handleFilterChange('dateIssued', e.target.value)}
                InputLabelProps={{ shrink: true }}
              />
            </Grid>
            <Grid item xs={12}>
              <FormControl fullWidth size="small">
                <InputLabel>Prepared By</InputLabel>
                <Select
                  value={filters.preparedBy}
                  onChange={(e) => handleFilterChange('preparedBy', e.target.value)}
                  label="Prepared By"
                >
                  <MenuItem value="">All Users</MenuItem>
                  {users.map((user) => (
                    <MenuItem key={user.id} value={user.full_name}>
                      {user.full_name}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </Grid>
          </Grid>
        </DialogContent>
        <DialogActions>
          <Button
            variant="outlined"
            startIcon={<ClearIcon />}
            onClick={clearFilters}
            color="secondary"
          >
            Clear Filters
          </Button>
          <Button
            variant="contained"
            onClick={() => setFilterModal(false)}
            color="primary"
          >
            Apply Filters
          </Button>
        </DialogActions>
      </Dialog>

      {/* Requests Table Paper */}
      <Paper
        sx={{
          width: '100%',
          display: 'flex',
          flexDirection: 'column',
          flexGrow: 1,
          flexShrink: 1,
          minHeight: 0,
          overflow: 'hidden',
          position: 'relative',
          borderRadius: 3,
          border: '1px solid',
          borderColor: 'divider',
          boxShadow: '0 8px 24px rgba(15, 23, 42, 0.05)'
        }}
      >
        {loading && (
          <Box sx={{ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', zIndex: 10, pointerEvents: 'none' }}>
            <CircularProgress size={40} sx={{ mb: 2, color: 'primary.main' }} />
            <Typography variant="body1" color="text.primary" sx={{ fontWeight: 600 }}>
              {debouncedSearchTerm ? 'Searching requests...' : 'Loading requests...'}
            </Typography>
          </Box>
        )}
        <TableContainer ref={tableContainerRef} sx={{ flexGrow: 1, flexShrink: 1, minHeight: 0, overflow: 'auto' }}>
          <Table stickyHeader sx={{ minWidth: '1310px', tableLayout: 'fixed' }}>
            <TableHead>
              <TableRow sx={{
                '& th': {
                  backgroundColor: '#f8fafc',
                  color: 'text.secondary',
                  fontWeight: 800,
                  fontSize: '0.72rem',
                  textTransform: 'uppercase',
                  letterSpacing: '0.04em',
                  borderBottom: '1px solid #e2e8f0',
                  py: 1.5,
                  px: '14px',
                  whiteSpace: 'nowrap'
                }
              }}>
                <TableCell sx={{ width: '150px' }}>Receipt</TableCell>
                <TableCell sx={{ width: '240px' }}>Client</TableCell>
                <TableCell sx={{ width: '280px' }}>Property</TableCell>
                <TableCell sx={{ width: '340px' }}>Request Details</TableCell>
                <TableCell sx={{ width: '140px' }}>Issued</TableCell>
                <TableCell sx={{ width: '160px' }}>Prepared By</TableCell>
                <TableCell sx={{
                  width: '100px',
                  position: 'sticky',
                  right: 0,
                  zIndex: 4,
                  backgroundColor: '#f8fafc',
                  borderLeft: '1px solid #e2e8f0',
                  boxShadow: '-4px 0 10px rgba(15, 23, 42, 0.04)',
                  textAlign: 'center'
                }}>
                  Actions
                </TableCell>
              </TableRow>
            </TableHead>
            <TableBody
              sx={{
                '& td': {
                  verticalAlign: 'top',
                  py: 0.75,
                  px: 1.5
                },
                '& tr:last-of-type td': {
                  borderBottom: 0
                },
                opacity: (loading && !initialLoad) ? 0.5 : 1,
                pointerEvents: (loading && !initialLoad) ? 'none' : 'auto',
                transition: 'opacity 0.2s ease-in-out'
              }}
            >
              {pagedGroups.map((group) => {
                const isBulk = group.is_bulk;
                const isExpanded = expandedGroups.has(group.group_key);
                const children = group.children || [];
                const firstChild = children[0] || {};

                const clientSecondary = [
                  group.client_address,
                  group.contact_number,
                  group.email
                ].filter(Boolean).join(' • ');

                // BULK PARENT ROW & SEAMLESS CHILDREN
                if (isBulk) {
                  return (
                    <React.Fragment key={group.group_key}>
                      <TableRow
                        className="request-group-parent"
                        onClick={() => handleToggleExpand(group.group_key)}
                        sx={{
                          cursor: 'pointer',
                          backgroundColor: '#fbfdff',
                          '&:hover': {
                            backgroundColor: 'rgba(2, 71, 171, 0.025)'
                          },
                          '& td': {
                            borderBottom: isExpanded ? '0 !important' : undefined
                          }
                        }}
                      >
                        {/* RECEIPT / GROUP CONTROL */}
                        <TableCell>
                          <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 0.5 }}>
                            <IconButton
                              size="small"
                              onClick={(event) => {
                                event.stopPropagation();
                                handleToggleExpand(group.group_key);
                              }}
                              sx={{
                                p: 0.25,
                                mt: 0.1,
                                flexShrink: 0
                              }}
                              aria-label={isExpanded ? 'Collapse requests' : 'Expand requests'}
                            >
                              {isExpanded ? (
                                <ExpandLessIcon fontSize="small" />
                              ) : (
                                <ExpandMoreIcon fontSize="small" />
                              )}
                            </IconButton>

                            <Box sx={{ minWidth: 0 }}>
                              <Typography
                                sx={{
                                  fontWeight: 700,
                                  fontSize: '0.84rem',
                                  lineHeight: 1.25,
                                  whiteSpace: 'nowrap',
                                  overflow: 'hidden',
                                  textOverflow: 'ellipsis'
                                }}
                              >
                                {children.length} {children.length === 1 ? 'Receipt' : 'Receipts'}
                              </Typography>

                              <Chip
                                label="Bulk Batch"
                                size="small"
                                sx={{
                                  height: '18px',
                                  fontSize: '0.65rem',
                                  fontWeight: 600,
                                  backgroundColor: '#eff6ff',
                                  color: '#1d4ed8',
                                  border: '1px solid #bfdbfe',
                                  mt: 0.3,
                                  '& .MuiChip-label': { px: 0.6, py: 0 }
                                }}
                              />
                            </Box>
                          </Box>
                        </TableCell>

                        {/* CLIENT (SHARED REQUESTOR INFORMATION) */}
                        <TableCell>
                          <Typography
                            variant="body2"
                            sx={{
                              fontWeight: 700,
                              fontSize: '0.86rem',
                              lineHeight: 1.25,
                              whiteSpace: 'nowrap',
                              overflow: 'hidden',
                              textOverflow: 'ellipsis'
                            }}
                            title={group.client_name}
                          >
                            {group.client_name || '—'}
                          </Typography>
                          <Typography
                            variant="caption"
                            color="text.secondary"
                            sx={{
                              display: 'block',
                              mt: 0.15,
                              fontSize: '0.70rem',
                              lineHeight: 1.2
                            }}
                          >
                            {children.length} {children.length === 1 ? 'Request' : 'Requests'}
                          </Typography>
                          {clientSecondary && (
                            <Typography
                              variant="caption"
                              color="text.secondary"
                              sx={{
                                display: 'block',
                                mt: 0.2,
                                fontSize: '0.72rem',
                                lineHeight: 1.25,
                                whiteSpace: 'nowrap',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis'
                              }}
                              title={clientSecondary}
                            >
                              {clientSecondary}
                            </Typography>
                          )}
                        </TableCell>

                        {/* PROPERTY */}
                        <TableCell>
                          <Typography variant="body2" sx={{ fontWeight: 600, fontSize: '0.84rem', lineHeight: 1.25 }}>
                            {children.length} Tax Declarations
                          </Typography>
                          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.2, fontSize: '0.72rem' }}>
                            {isExpanded ? 'Showing individual TDs below' : 'Click row to view TDs'}
                          </Typography>
                        </TableCell>

                        {/* REQUEST DETAILS */}
                        <TableCell>
                          <Tooltip
                            title={group.purpose_details ? String(group.purpose_details) : ''}
                            placement="top-start"
                            arrow
                          >
                            <Typography
                              variant="body2"
                              sx={{
                                display: '-webkit-box',
                                WebkitBoxOrient: 'vertical',
                                WebkitLineClamp: 2,
                                overflow: 'hidden',
                                overflowWrap: 'anywhere',
                                lineHeight: 1.35,
                                fontWeight: 600,
                                fontSize: '0.84rem',
                                cursor: group.purpose_details ? 'help' : 'default'
                              }}
                            >
                              {group.purpose_details || '—'}
                            </Typography>
                          </Tooltip>
                          {group.remarks && (
                            <Typography
                              variant="caption"
                              color="text.secondary"
                              sx={{
                                display: 'block',
                                mt: 0.25,
                                whiteSpace: 'nowrap',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                lineHeight: 1.25,
                                fontSize: '0.72rem'
                              }}
                              title={`Remarks: ${group.remarks}`}
                            >
                              <strong>Remarks:</strong> {group.remarks}
                            </Typography>
                          )}
                        </TableCell>

                        {/* ISSUED */}
                        <TableCell>
                          <Typography variant="body2" sx={{ fontWeight: 500, fontSize: '0.84rem', lineHeight: 1.2 }}>
                            {formatDateTable(group.date_issued)}
                          </Typography>
                          {group.place_issued && (
                            <Typography
                              variant="caption"
                              color="text.secondary"
                              sx={{
                                display: 'block',
                                mt: 0.2,
                                fontSize: '0.72rem',
                                lineHeight: 1.25,
                                whiteSpace: 'nowrap',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis'
                              }}
                              title={group.place_issued}
                            >
                              {group.place_issued}
                            </Typography>
                          )}
                        </TableCell>

                        {/* PREPARED BY */}
                        <TableCell>
                          <Typography
                            variant="body2"
                            sx={{
                              fontWeight: 500,
                              fontSize: '0.84rem',
                              lineHeight: 1.25,
                              whiteSpace: 'nowrap',
                              overflow: 'hidden',
                              textOverflow: 'ellipsis'
                            }}
                          >
                            {group.prepared_by || '—'}
                          </Typography>
                        </TableCell>

                        {/* ACTIONS (STICKY AREA) */}
                        <TableCell
                          onClick={(event) => event.stopPropagation()}
                          sx={{
                            position: 'sticky',
                            right: 0,
                            zIndex: 2,
                            backgroundColor: '#fbfdff',
                            borderLeft: '1px solid #e2e8f0',
                            boxShadow: '-4px 0 10px rgba(15, 23, 42, 0.04)',
                            textAlign: 'center',
                            py: 0.75,
                            px: 1
                          }}
                        >
                          {/* Row click operates the expansion; standalone Collapse button removed */}
                        </TableCell>
                      </TableRow>

                      {/* SEAMLESS CHILD ROWS (NO NESTED TABLE/PANEL/CARD) */}
                      {isExpanded && children.map((child) => {
                        const declarant = formatDeclarantFromParts(
                          child.declarant_last_name,
                          child.declarant_first_name,
                          child.declarant_middle_initial
                        );
                        const business = child.business ? String(child.business).replace(/,\s*/g, ' ') : '';
                        const partyName = declarant && business ? `${declarant} | ${business}` : (declarant || business || '');

                        const propertyMeta = [
                          child.location,
                          child.kind_of_property_name || child.kind_of_property,
                          child.gen_class_name || child.gen_class
                        ].filter(Boolean).join(' • ');

                        return (
                          <TableRow
                            key={child.id}
                            className="request-group-child"
                            sx={{
                              backgroundColor: '#f8fafc',
                              '&:hover': {
                                backgroundColor: '#f1f5f9'
                              },
                              '& td': {
                                py: 0.55,
                                px: 1.5,
                                borderTop: '1px solid #eef2f7',
                                borderBottom: '1px solid #eef2f7'
                              }
                            }}
                          >
                            {/* RECEIPT: VERTICALLY CENTERED WITH HIERARCHY CONNECTOR */}
                            <TableCell
                              sx={{
                                position: 'relative',
                                verticalAlign: 'middle',
                                py: 0.75,
                                px: 1.5,
                                overflow: 'visible',
                              }}
                            >
                              {/* Vertical hierarchy connector */}
                              <Box
                                aria-hidden="true"
                                sx={{
                                  position: 'absolute',
                                  left: 8,
                                  top: 0,
                                  bottom: 0,
                                  width: '1px',
                                  backgroundColor: '#d7dee8',
                                  pointerEvents: 'none',
                                }}
                              />

                              {/* Horizontal hierarchy connector */}
                              <Box
                                aria-hidden="true"
                                sx={{
                                  position: 'absolute',
                                  left: 8,
                                  top: '50%',
                                  width: 18,
                                  height: '1px',
                                  backgroundColor: '#d7dee8',
                                  pointerEvents: 'none',
                                }}
                              />

                              {/* Receipt number — CENTERED ON THE ROW */}
                              <Box
                                sx={{
                                  position: 'absolute',
                                  top: '50%',
                                  left: '42px',
                                  transform: 'translateY(-50%)',
                                  width: 'calc(100% - 42px)',
                                  pointerEvents: 'none',
                                }}
                              >
                                <Typography
                                  sx={{
                                    fontWeight: 700,
                                    fontSize: '0.82rem',
                                    lineHeight: 1.2,
                                    color: 'text.primary',
                                    whiteSpace: 'nowrap',
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis',
                                  }}
                                >
                                  {child.receipt_number || '—'}
                                </Typography>
                              </Box>
                            </TableCell>

                            {/* CLIENT: EMPTY CELL TO PRESERVE TABLE ALIGNMENT (SHARED INFO BELONGS TO PARENT) */}
                            <TableCell>
                              {/* Intentionally empty for bulk children */}
                            </TableCell>

                            {/* PROPERTY */}
                            <TableCell>
                              <Typography
                                variant="body2"
                                sx={{
                                  fontWeight: 700,
                                  fontSize: '0.82rem',
                                  lineHeight: 1.25,
                                  whiteSpace: 'nowrap',
                                  overflow: 'hidden',
                                  textOverflow: 'ellipsis'
                                }}
                              >
                                {child.tax_declaration_number || '—'}
                              </Typography>
                              {partyName && (
                                <Typography
                                  variant="caption"
                                  color="text.primary"
                                  sx={{
                                    display: 'block',
                                    mt: 0.15,
                                    fontSize: '0.72rem',
                                    fontWeight: 500,
                                    lineHeight: 1.2,
                                    whiteSpace: 'nowrap',
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis'
                                  }}
                                  title={partyName}
                                >
                                  {partyName}
                                </Typography>
                              )}
                              {propertyMeta && (
                                <Typography
                                  variant="caption"
                                  color="text.secondary"
                                  sx={{
                                    display: 'block',
                                    mt: 0.15,
                                    fontSize: '0.70rem',
                                    lineHeight: 1.2,
                                    whiteSpace: 'nowrap',
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis'
                                  }}
                                  title={propertyMeta}
                                >
                                  {propertyMeta}
                                </Typography>
                              )}
                            </TableCell>

                            {/* REQUEST DETAILS (BELONGS TO PARENT ONLY - INTENTIONALLY EMPTY FOR CHILD) */}
                            <TableCell>
                              {/* Request Details belong to the parent bulk row only */}
                            </TableCell>

                            {/* ISSUED */}
                            <TableCell>
                              {/* <Typography
                                variant="body2"
                                sx={{
                                  fontWeight: 500,
                                  fontSize: '0.80rem',
                                  lineHeight: 1.2
                                }}
                              >
                                {formatDateTable(child.date_issued || group.date_issued)}
                              </Typography> */}
                            </TableCell>

                            {/* PREPARED BY */}
                            <TableCell>
                              {/* <Typography
                                variant="body2"
                                sx={{
                                  fontWeight: 500,
                                  fontSize: '0.80rem',
                                  lineHeight: 1.2,
                                  whiteSpace: 'nowrap',
                                  overflow: 'hidden',
                                  textOverflow: 'ellipsis'
                                }}
                              >
                                {child.prepared_by || group.prepared_by || '—'}
                              </Typography> */}
                            </TableCell>

                            {/* ACTIONS (INDIVIDUAL CHILD PRINT & DELETE) */}
                            <TableCell
                              sx={{
                                position: 'sticky',
                                right: 0,
                                zIndex: 2,
                                backgroundColor: '#f8fafc',
                                borderLeft: '1px solid #e2e8f0',
                                boxShadow: '-4px 0 10px rgba(15, 23, 42, 0.04)',
                                textAlign: 'center',
                                py: 0.55,
                                px: 1
                              }}
                            >
                              <Box sx={{ display: 'flex', gap: 0.5, justifyContent: 'center', alignItems: 'center' }}>
                                <Tooltip title="Print request" arrow>
                                  <span>
                                    <IconButton
                                      size="small"
                                      disabled={isViewer}
                                      onClick={(event) => {
                                        event.stopPropagation();
                                        handlePrintRequest(child);
                                      }}
                                      sx={{ p: 0.35 }}
                                    >
                                      <PrintIcon sx={{ fontSize: 16 }} />
                                    </IconButton>
                                  </span>
                                </Tooltip>
                                {(isAdmin || isSuperAdmin) && (
                                  <Tooltip title="Delete request" arrow>
                                    <IconButton
                                      size="small"
                                      color="error"
                                      onClick={(event) => {
                                        event.stopPropagation();
                                        handleDeleteRequest(child.id);
                                      }}
                                      sx={{ p: 0.35 }}
                                    >
                                      <DeleteIcon sx={{ fontSize: 16 }} />
                                    </IconButton>
                                  </Tooltip>
                                )}
                              </Box>
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </React.Fragment>
                  );
                }

                // STANDALONE REQUEST ROW
                const request = firstChild;
                const isOfficial =
                  String(request.is_official_request) === '1' ||
                  request.is_official_request === 1 ||
                  request.is_official_request === true;

                const declarant = formatDeclarantFromParts(
                  request.declarant_last_name,
                  request.declarant_first_name,
                  request.declarant_middle_initial
                );
                const business = request.business ? String(request.business).replace(/,\s*/g, ' ') : '';
                const partyName = declarant && business ? `${declarant} | ${business}` : (declarant || business || '');

                const propertyMeta = [
                  request.location,
                  request.kind_of_property_name || request.kind_of_property,
                  request.gen_class_name || request.gen_class
                ].filter(Boolean).join(' • ');

                return (
                  <TableRow
                    key={group.group_key}
                    sx={{
                      '&:hover': {
                        backgroundColor: 'rgba(2, 71, 171, 0.025)'
                      }
                    }}
                  >
                    {/* RECEIPT */}
                    <TableCell>
                      <Typography
                        variant="body2"
                        sx={{
                          fontWeight: 700,
                          fontSize: '0.86rem',
                          lineHeight: 1.25,
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis'
                        }}
                      >
                        {request.receipt_number || '—'}
                      </Typography>
                      {/* <Box sx={{ mt: 0.35 }}>
                        {isOfficial ? (
                          <Chip
                            label="Official Use"
                            size="small"
                            sx={{
                              height: '19px',
                              fontSize: '0.68rem',
                              fontWeight: 600,
                              borderColor: '#fde68a',
                              color: '#92400e',
                              backgroundColor: '#fffbeb',
                              border: '1px solid #fde68a',
                              '& .MuiChip-label': { px: 0.75, py: 0 }
                            }}
                          />
                        ) : ''}
                      </Box> */}
                    </TableCell>

                    {/* CLIENT */}
                    <TableCell>
                      <Typography
                        variant="body2"
                        sx={{
                          fontWeight: 700,
                          fontSize: '0.86rem',
                          lineHeight: 1.25,
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis'
                        }}
                        title={request.client_name}
                      >
                        {request.client_name || '—'}
                      </Typography>
                      {clientSecondary && (
                        <Typography
                          variant="caption"
                          color="text.secondary"
                          sx={{
                            display: 'block',
                            mt: 0.2,
                            fontSize: '0.72rem',
                            lineHeight: 1.25,
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis'
                          }}
                          title={clientSecondary}
                        >
                          {clientSecondary}
                        </Typography>
                      )}
                    </TableCell>

                    {/* PROPERTY */}
                    <TableCell>
                      <Typography
                        variant="body2"
                        sx={{
                          fontWeight: 700,
                          fontSize: '0.86rem',
                          lineHeight: 1.25,
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis'
                        }}
                      >
                        {request.tax_declaration_number || '—'}
                      </Typography>
                      {partyName && (
                        <Typography
                          variant="caption"
                          color="text.primary"
                          sx={{
                            display: 'block',
                            mt: 0.2,
                            fontSize: '0.74rem',
                            fontWeight: 500,
                            lineHeight: 1.25,
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis'
                          }}
                          title={partyName}
                        >
                          {partyName}
                        </Typography>
                      )}
                      {propertyMeta && (
                        <Typography
                          variant="caption"
                          color="text.secondary"
                          sx={{
                            display: 'block',
                            mt: 0.2,
                            fontSize: '0.72rem',
                            lineHeight: 1.25,
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis'
                          }}
                          title={propertyMeta}
                        >
                          {propertyMeta}
                        </Typography>
                      )}
                    </TableCell>

                    {/* REQUEST DETAILS */}
                    <TableCell>
                      <Tooltip
                        title={request.purpose_details ? String(request.purpose_details) : ''}
                        placement="top-start"
                        arrow
                      >
                        <Typography
                          variant="body2"
                          sx={{
                            display: '-webkit-box',
                            WebkitBoxOrient: 'vertical',
                            WebkitLineClamp: 2,
                            overflow: 'hidden',
                            overflowWrap: 'anywhere',
                            lineHeight: 1.35,
                            fontWeight: 600,
                            fontSize: '0.84rem',
                            cursor: request.purpose_details ? 'help' : 'default'
                          }}
                        >
                          {request.purpose_details || '—'}
                        </Typography>
                      </Tooltip>
                      {request.remarks && (
                        <Typography
                          variant="caption"
                          color="text.secondary"
                          sx={{
                            display: 'block',
                            mt: 0.25,
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            lineHeight: 1.25,
                            fontSize: '0.72rem'
                          }}
                          title={`Remarks: ${request.remarks}`}
                        >
                          <strong>Remarks:</strong> {request.remarks}
                        </Typography>
                      )}
                    </TableCell>

                    {/* ISSUED */}
                    <TableCell>
                      <Typography
                        variant="body2"
                        sx={{
                          fontWeight: 500,
                          fontSize: '0.84rem',
                          lineHeight: 1.2
                        }}
                      >
                        {formatDateTable(request.date_issued)}
                      </Typography>
                      {request.place_issued && (
                        <Typography
                          variant="caption"
                          color="text.secondary"
                          sx={{
                            display: 'block',
                            mt: 0.2,
                            fontSize: '0.72rem',
                            lineHeight: 1.25,
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis'
                          }}
                          title={request.place_issued}
                        >
                          {request.place_issued}
                        </Typography>
                      )}
                    </TableCell>

                    {/* PREPARED BY */}
                    <TableCell>
                      <Typography
                        variant="body2"
                        sx={{
                          fontWeight: 500,
                          fontSize: '0.84rem',
                          lineHeight: 1.25,
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis'
                        }}
                      >
                        {request.prepared_by || '—'}
                      </Typography>
                      {request.updated_by_name && request.updated_by_name !== request.prepared_by && (
                        <Typography
                          variant="caption"
                          color="text.secondary"
                          sx={{
                            display: 'block',
                            mt: 0.2,
                            fontSize: '0.72rem',
                            lineHeight: 1.25,
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis'
                          }}
                          title={`Updated by: ${request.updated_by_name}`}
                        >
                          Updated by {request.updated_by_name}
                        </Typography>
                      )}
                    </TableCell>

                    {/* ACTIONS */}
                    <TableCell
                      sx={{
                        position: 'sticky',
                        right: 0,
                        zIndex: 2,
                        backgroundColor: '#fff',
                        borderLeft: '1px solid #e2e8f0',
                        boxShadow: '-4px 0 10px rgba(15, 23, 42, 0.04)',
                        textAlign: 'center',
                        py: 0.75,
                        px: 1
                      }}
                    >
                      <Box sx={{ display: 'flex', gap: 0.5, justifyContent: 'center', alignItems: 'center' }}>
                        <Tooltip title="Print request" arrow>
                          <span>
                            <IconButton
                              size="small"
                              onClick={() => handlePrintRequest(request)}
                              disabled={isViewer}
                              sx={{ p: 0.5 }}
                            >
                              <PrintIcon fontSize="small" />
                            </IconButton>
                          </span>
                        </Tooltip>
                        {(isAdmin || isSuperAdmin) && (
                          <Tooltip title="Delete request" arrow>
                            <IconButton
                              size="small"
                              onClick={() => handleDeleteRequest(request.id)}
                              color="error"
                              sx={{ p: 0.5 }}
                            >
                              <DeleteIcon fontSize="small" />
                            </IconButton>
                          </Tooltip>
                        )}
                      </Box>
                    </TableCell>
                  </TableRow>
                );
              })}
              {!loading && pagedGroups.length === 0 && (
                <TableRow>
                  <TableCell colSpan={7} sx={{ border: 'none', p: 0 }}>
                    <Box sx={{
                      minHeight: 400,
                      width: '100%',
                      display: 'flex',
                      alignItems: 'center'
                    }}>
                      <Box
                        sx={{
                          position: 'sticky',
                          left: '50%',
                          transform: 'translateX(-50%)',
                          display: 'inline-flex',
                          flexDirection: 'column',
                          alignItems: 'center',
                          justifyContent: 'center'
                        }}
                      >
                        <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                          <Typography variant="h6" color="text.secondary" sx={{ mb: 0.5, fontWeight: 500 }}>
                            No requests found
                          </Typography>
                          <Typography variant="body2" color="text.disabled">
                            {searchTerm ? 'Try adjusting your search or filters' : 'No requests found matching your criteria.'}
                          </Typography>
                        </Box>
                      </Box>
                    </Box>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </TableContainer>

        {/* Pagination */}
        <TablePagination
          sx={{ flexShrink: 0 }}
          rowsPerPageOptions={[20, 50, 100]}
          component="div"
          count={debouncedSearchTerm ? filteredGroups.length : totalCount}
          page={page}
          onPageChange={handleChangePage}
          rowsPerPage={rowsPerPage}
          onRowsPerPageChange={handleChangeRowsPerPage}
        />
      </Paper>

      {/* Print Modal */}
      <Dialog
        open={printModal}
        onClose={() => setPrintModal(false)}
        maxWidth="md"
      >
        <DialogTitle>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <PrintIcon />
            Print Request History
          </Box>
        </DialogTitle>
        <DialogContent
          sx={{
            p: 2,
            overflow: 'hidden',
            display: 'flex',
            justifyContent: 'center'
          }}
        >
          {printLoading ? (
            <Box sx={{
              display: 'flex',
              flexDirection: 'column',
              justifyContent: 'center',
              alignItems: 'center',
              minHeight: '40vh',
              minWidth: '40vw',
              gap: 2
            }}>
              <CircularProgress size={50} thickness={4} />
              <Typography variant="h6" color="text.secondary">
                Loading Request History...
              </Typography>
            </Box>
          ) : (
            <Box
              sx={{
                width: '100%',
                maxHeight: '70vh',
                overflow: 'auto',
                display: 'flex',
                justifyContent: 'center',
                alignItems: 'flex-start'
              }}
            >
              <HistoryPrintDocument
                settings={settings}
                printHistory={printHistory}
                requestData={printRequestData}
                documentType="request"
                paperSize={paperSize}
                printGeneratedAt={printGeneratedAt}
              />
            </Box>
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 1.5, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <Box sx={{ display: 'inline-flex', alignItems: 'center', gap: 0.5, border: '1px solid #cbd5e1', borderRadius: '6px', p: '2px', backgroundColor: '#f8fafc' }}>
            {[
              { id: 'auto', label: 'Auto Fit' },
              { id: 'letter', label: 'Letter' },
              { id: 'a4', label: 'A4' },
              { id: 'legal', label: 'Legal' }
            ].map((item) => (
              <Button
                key={item.id}
                size="small"
                onClick={() => handlePaperSizeChange(item.id)}
                variant={paperSize === item.id ? 'contained' : 'text'}
                sx={{
                  minWidth: 'auto',
                  px: 1.25,
                  py: 0.25,
                  fontSize: '0.75rem',
                  fontWeight: 600,
                  textTransform: 'none',
                  borderRadius: '4px',
                  boxShadow: paperSize === item.id ? '0 1px 2px rgba(0,0,0,0.08)' : 'none',
                  color: paperSize === item.id ? '#1e40af' : '#64748b',
                  backgroundColor: paperSize === item.id ? '#ffffff' : 'transparent',
                  '&:hover': {
                    backgroundColor: paperSize === item.id ? '#ffffff' : '#e2e8f0',
                    boxShadow: paperSize === item.id ? '0 1px 2px rgba(0,0,0,0.08)' : 'none'
                  }
                }}
              >
                {item.label}
              </Button>
            ))}
          </Box>
          <Box sx={{ display: 'flex', gap: 1 }}>
            <Button onClick={() => setPrintModal(false)}>Close</Button>
            <Button
              onClick={handlePrint}
              variant="contained"
              startIcon={<PrintIcon />}
              disabled={isViewer}
            >
              Print
            </Button>
          </Box>
        </DialogActions>
      </Dialog>

      {/* Hidden printable content for react-to-print */}
      <div style={{ position: 'fixed', left: '-10000px', top: 0 }}>
        <HistoryPrintDocument
          ref={printRef}
          settings={settings}
          printHistory={printHistory}
          requestData={printRequestData}
          documentType="request"
          paperSize={paperSize}
          printGeneratedAt={printGeneratedAt}
        />
      </div>

      {/* Request Form Modal */}
      <RequestFormModal
        property={null}
        onSave={handleRequestFormSaved}
        onCancel={() => setRequestFormModal(false)}
        open={requestFormModal}
        onClose={() => setRequestFormModal(false)}
      />

      {/* Bulk Request Modal */}
      <BulkRequestModal
        open={bulkRequestModal}
        onClose={() => setBulkRequestModal(false)}
        onSuccess={handleBulkRequestSuccess}
      />

      {/* Error Alert */}
      {error && (
        <Alert severity="error" sx={{ mt: 2 }} onClose={() => setError('')}>
          {error}
        </Alert>
      )}
    </Box>
  );
};

export default RequestsTable;
