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
  Clear as ClearIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';

import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';
import { useReactToPrint } from 'react-to-print';
import RequestFormModal from '../RequestFormModal/RequestFormModal';
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
  const [requests, setRequests] = useState([]);
  const [loading, setLoading] = useState(true);
  const [initialLoad, setInitialLoad] = useState(true);
  const [error, setError] = useState('');
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(20);
  const [totalCount, setTotalCount] = useState(0);
  const [allRequests, setAllRequests] = useState([]);

  // Safety check - ensure requests is always an array
  const safeRequests = useMemo(() => requests || [], [requests]);

  // Search state
  const [searchTerm, setSearchTerm] = useState('');
  const debouncedSearchTerm = useDebounce(searchTerm, 300); // 300ms delay

  // Filter states
  const [filters, setFilters] = useState({
    purpose: '',
    requestType: '',
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

  // Filtered requests for client-side filtering when searching
  const filteredRequests = useMemo(() => {
    const base = (allRequests && allRequests.length) ? allRequests : safeRequests;
    return (base || []).filter((request) => {
      if (!request) return false;

      // Apply search filter
      if (debouncedSearchTerm) {
        const searchUpper = debouncedSearchTerm.toUpperCase();
        const matchesSearch =
          (request.receipt_number && String(request.receipt_number).toUpperCase().includes(searchUpper)) ||
          (request.client_name && String(request.client_name).toUpperCase().includes(searchUpper)) ||
          (request.client_address && String(request.client_address).toUpperCase().includes(searchUpper)) ||
          (request.declarant_last_name && String(request.declarant_last_name).toUpperCase().includes(searchUpper)) ||
          (request.declarant_first_name && String(request.declarant_first_name).toUpperCase().includes(searchUpper)) ||
          (request.declarant_middle_initial && String(request.declarant_middle_initial).toUpperCase().includes(searchUpper)) ||
          (request.business && String(request.business).toUpperCase().includes(searchUpper)) ||
          (request.tax_declaration_number && String(request.tax_declaration_number).toUpperCase().includes(searchUpper)) ||
          (request.purpose && String(request.purpose).toUpperCase().includes(searchUpper)) ||
          (request.purpose_details && String(request.purpose_details).toUpperCase().includes(searchUpper)) ||
          (request.remarks && String(request.remarks).toUpperCase().includes(searchUpper)) ||
          (request.prepared_by && String(request.prepared_by).toUpperCase().includes(searchUpper));

        if (!matchesSearch) return false;
      }

      // Apply other filters
      if (filters.purpose && request.purpose !== filters.purpose) return false;
      if (filters.requestType !== '') {
        const isOfficial = String(request.is_official_request) === '1' || request.is_official_request === 1 || request.is_official_request === true;
        if (filters.requestType === '1' && !isOfficial) return false;
        if (filters.requestType === '0' && isOfficial) return false;
      }
      if (filters.dateIssued && request.date_issued !== filters.dateIssued) return false;
      if (filters.preparedBy && request.prepared_by !== filters.preparedBy) return false;

      return true;
    });
  }, [allRequests, safeRequests, debouncedSearchTerm, filters]);

  // Paged requests for display
  const pagedRequests = useMemo(() => {
    // Normal browsing uses server-side pagination.
    // safeRequests already contains exactly the requested API page.
    if (!debouncedSearchTerm) {
      return safeRequests;
    }

    // Search mode loads the full matching dataset,
    // therefore frontend pagination is required here.
    const start = page * rowsPerPage;
    const end = start + rowsPerPage;
    return filteredRequests.slice(start, end);
  }, [
    filteredRequests,
    safeRequests,
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

  // Fetch requests
  const fetchSeqRef = useRef(0);
  const fetchRequests = useCallback(async (forceRefresh = false) => {
    const seq = ++fetchSeqRef.current;
    try {
      setLoading(true);
      setError('');

      const params = {
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

      if (response && response.requests) {
        setRequests(response.requests);
        setTotalCount(response.pagination ? response.pagination.total : response.requests.length);
      } else if (response && response.data) {
        // Fallback for different response format
        setRequests(response.data);
        setTotalCount(response.total || response.data.length);
      } else {
        console.warn('Unexpected API response format:', response);
        setRequests([]);
        setTotalCount(0);
      }
    } catch (err) {
      console.error('Error fetching requests:', err);
      setError(`Failed to fetch requests: ${err.message || 'Unknown error'}`);
      setRequests([]);
      setTotalCount(0);
    } finally {
      // Only clear loading for the latest request
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

  // Fetch full dataset for client-side filtering/pagination when searching
  useEffect(() => {
    const fetchAll = async () => {
      try {
        const params = {
          all: 1,
          search: debouncedSearchTerm || '',
          purpose: filters.purpose || '',
          date_issued: filters.dateIssued || '',
          prepared_by: filters.preparedBy || '',
          _t: Date.now()
        };
        const response = await apiService.getRequests(params);
        const items = (response && response.requests)
          ? response.requests
          : (response && response.data)
            ? response.data
            : [];
        setAllRequests(items);
      } catch (e) {
        // Fall back silently; keep existing page data
        setAllRequests([]);
      }
    };

    if (debouncedSearchTerm) {
      fetchAll();
    } else {
      // Clear all requests when not searching to use paginated data
      setAllRequests([]);
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
    setPage(0); // Reset to first page when searching
  };

  // Handle filter changes
  const handleFilterChange = (field, value) => {
    setFilters(prev => ({
      ...prev,
      [field]: value
    }));
    setPage(0); // Reset to first page when filtering
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
      requestType: '',
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
    setRowsPerPage(parseInt(event.target.value, 10));
    setPage(0);
  };

  // Handle print request
  const handlePrintRequest = async (request) => {
    try {
      const generatedAt = new Date();
      setPrintGeneratedAt(generatedAt);

      setPrintLoading(true);
      setPrintModal(true);
      setPrintRequestData(request);
      setPrintHistory([]); // Initialize as empty array

      console.log('Request data for printing:', request);

      // Get tax declaration history (same as PropertyTable)
      if (request.tax_declaration_number) {
        console.log('Fetching tax declaration history for:', request.tax_declaration_number);
        const response = await apiService.getTaxDeclarationHistory(request.tax_declaration_number);
        console.log('Tax declaration history response:', response);

        if (response && Array.isArray(response)) {
          setPrintHistory(response);
        } else {
          console.warn('Tax declaration history response is not an array:', response);
          setPrintHistory([]);
        }
      } else {
        console.log('No tax_declaration_number found in request:', request);
        // Create a fallback history with current property data from the request
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
          console.log('Using fallback history:', fallbackHistory);
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

  // Handle create request
  const handleCreateRequest = () => {
    setRequestFormModal(true);
  };

  // Handle request form saved
  const handleRequestFormSaved = (requestData) => {
    setRequestFormModal(false);
    setPage(0);
    // Refresh the requests list
    fetchRequests(true);
    console.log('Request form saved:', requestData);
  };

  // Handle delete request
  const handleDeleteRequest = async (requestId) => {
    if (!window.confirm('Are you sure you want to delete this request?')) {
      return;
    }

    try {
      await apiService.deleteRequest(requestId);
      fetchRequests(true); // Refresh the list
    } catch (err) {
      console.error('Error deleting request:', err);
      alert('Failed to delete request');
    }
  };

  // Format amount
  const formatAmount = (amount) => {
    if (!amount) return '₱0.00';
    return `₱${parseFloat(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  // Show initial loading state
  if (initialLoad && loading && (!safeRequests || safeRequests.length === 0)) {
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
      <Typography variant="h4" gutterBottom sx={{ flexShrink: 0 }}>
        Requests Management
      </Typography>
      <Typography variant="body1" color="text.secondary" sx={{ flexShrink: 0 }}>
        Manage and view all payment requests and receipts
      </Typography>

      {/* Search and Actions */}
      <Card sx={{ mb: 3, flexShrink: 0 }}>
        <CardContent>
          <Grid container spacing={2} alignItems="flex-start">
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                size='small'
                placeholder={`Total: ${totalCount} requests`}
                value={searchTerm}
                onChange={handleSearch}
                InputProps={{
                  startAdornment: <SearchIcon sx={{ mr: 1, color: 'text.secondary' }} />
                }}
              />
            </Grid>
            <Grid item xs={12} md={6} sx={{ textAlign: 'right' }}>
              <Box sx={{ display: 'flex', gap: 1, justifyContent: 'flex-end' }}>
                <Button
                  size="small"
                  variant="outlined"
                  startIcon={<FilterListIcon />}
                  onClick={() => setFilterModal(true)}
                  color="primary"
                  sx={{
                    height: 40,
                    '& .MuiToggleButton-root': {
                      height: '100%',
                      py: 0.5,
                    },
                  }}
                >
                  Filters
                </Button>
                <Button
                  size="small"
                  variant="contained"
                  startIcon={<AddIcon />}
                  onClick={handleCreateRequest}
                  color="primary"
                  sx={{
                    height: 40,
                    '& .MuiToggleButton-root': {
                      height: '100%',
                      py: 0.5,
                    },
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
              <FormControl fullWidth>
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
              <FormControl fullWidth>
                <InputLabel>Request Type</InputLabel>
                <Select
                  value={filters.requestType}
                  onChange={(e) => handleFilterChange('requestType', e.target.value)}
                  label="Request Type"
                >
                  <MenuItem value="">All Request Types</MenuItem>
                  <MenuItem value="0">Regular</MenuItem>
                  <MenuItem value="1">Official Use</MenuItem>
                </Select>
              </FormControl>
            </Grid>
            <Grid item xs={12}>
              <TextField
                fullWidth
                label="Date Issued"
                type="date"
                value={filters.dateIssued}
                onChange={(e) => handleFilterChange('dateIssued', e.target.value)}
                InputLabelProps={{ shrink: true }}
              />
            </Grid>
            <Grid item xs={12}>
              <FormControl fullWidth>
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

      {/* Requests Table */}
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
          <Table stickyHeader sx={{ minWidth: '1120px', tableLayout: 'fixed' }}>
            <TableHead>
              <TableRow sx={{
                '& th': {
                  backgroundColor: '#f8fafc',
                  color: 'text.secondary',
                  fontWeight: 800,
                  fontSize: '0.72rem',
                  textTransform: 'uppercase',
                  letterSpacing: '0.04em',
                  borderBottom: '1px solid',
                  borderColor: 'divider',
                  py: 1.5,
                  px: '14px',
                  whiteSpace: 'nowrap'
                }
              }}>
                <TableCell sx={{ width: '150px' }}>Receipt</TableCell>
                <TableCell sx={{ width: '235px' }}>Client</TableCell>
                <TableCell sx={{ width: '275px' }}>Property</TableCell>
                <TableCell sx={{ width: '330px' }}>Purpose</TableCell>
                <TableCell sx={{ width: '135px' }}>Payment</TableCell>
                <TableCell sx={{ width: '135px' }}>Issued</TableCell>
                <TableCell sx={{ width: '160px' }}>Prepared By</TableCell>
                <TableCell sx={{
                  width: '105px',
                  position: 'sticky',
                  right: 0,
                  zIndex: 3,
                  backgroundColor: '#f8fafc !important',
                  borderLeft: '1px solid',
                  borderColor: 'divider',
                  boxShadow: '-4px 0 8px rgba(15, 23, 42, 0.04)',
                  textAlign: 'center'
                }}>
                  Actions
                </TableCell>
              </TableRow>
            </TableHead>
            <TableBody sx={{
              '& td': {
                verticalAlign: 'top',
                py: 0.7,
                px: 1.5,
                borderBottom: '1px solid',
                borderColor: 'divider'
              },
              '& tr:last-of-type td': {
                borderBottom: 0
              },
              opacity: (loading && !initialLoad) ? 0.5 : 1,
              pointerEvents: (loading && !initialLoad) ? 'none' : 'auto',
              transition: 'opacity 0.2s ease-in-out'
            }}>
              {pagedRequests.map((request) => {
                const isOfficial = String(request.is_official_request) === '1' || request.is_official_request === 1 || request.is_official_request === true;
                const declarant = formatDeclarantFromParts(request.declarant_last_name, request.declarant_first_name, request.declarant_middle_initial);
                const business = request.business ? String(request.business).replace(/,\s*/g, ' ') : '';
                const propertyTitle = declarant && business ? `${declarant} | ${business}` : (declarant || business || '');
                const clientSecondary = [
                  request.client_address,
                  request.contact_number,
                  request.email
                ].filter(Boolean).join(' • ');
                const propertySecondary = [
                  request.location,
                  request.kind_of_property_name || request.kind_of_property,
                  request.gen_class_name || request.gen_class
                ].filter(Boolean).join(' • ');

                return (
                  <TableRow
                    key={request.id}
                    hover
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
                          fontSize: '0.84rem',
                          color: 'text.primary',
                          letterSpacing: '0.02em',
                          lineHeight: 1.25,
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis'
                        }}
                      >
                        {request.receipt_number || '—'}
                      </Typography>
                      <Box sx={{ mt: 0.2 }}>
                        {isOfficial ? (
                          <Chip
                            label="Official Use"
                            size="small"
                            variant="outlined"
                            sx={{
                              height: '19px',
                              fontSize: '0.68rem',
                              fontWeight: 600,
                              borderColor: '#fcd34d',
                              color: '#b45309',
                              backgroundColor: '#fffbeb',
                              '& .MuiChip-label': { px: 0.75, py: 0 }
                            }}
                          />
                        ) : (
                          <Chip
                            label="Regular"
                            size="small"
                            variant="outlined"
                            sx={{
                              height: '19px',
                              fontSize: '0.68rem',
                              fontWeight: 500,
                              borderColor: '#e2e8f0',
                              color: '#475569',
                              backgroundColor: '#f8fafc',
                              '& .MuiChip-label': { px: 0.75, py: 0 }
                            }}
                          />
                        )}
                      </Box>
                    </TableCell>

                    {/* CLIENT */}
                    <TableCell>
                      <Typography
                        variant="body2"
                        sx={{
                          fontWeight: 700,
                          fontSize: '0.84rem',
                          color: 'text.primary',
                          lineHeight: 1.25,
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis'
                        }}
                      >
                        {request.client_name || '—'}
                      </Typography>
                      {clientSecondary && (
                        <Typography
                          variant="caption"
                          color="text.secondary"
                          display="block"
                          sx={{
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
                          fontSize: '0.84rem',
                          color: request.tax_declaration_number ? 'primary.main' : 'text.secondary',
                          lineHeight: 1.25,
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis'
                        }}
                      >
                        {request.tax_declaration_number || '—'}
                      </Typography>
                      {propertyTitle && (
                        <Typography
                          variant="caption"
                          color="text.primary"
                          display="block"
                          sx={{
                            mt: 0.2,
                            fontSize: '0.74rem',
                            fontWeight: 500,
                            lineHeight: 1.25,
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis'
                          }}
                          title={propertyTitle}
                        >
                          {propertyTitle}
                        </Typography>
                      )}
                      {propertySecondary && (
                        <Typography
                          variant="caption"
                          color="text.secondary"
                          display="block"
                          sx={{
                            mt: 0.2,
                            fontSize: '0.72rem',
                            lineHeight: 1.25,
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis'
                          }}
                          title={propertySecondary}
                        >
                          {propertySecondary}
                        </Typography>
                      )}
                    </TableCell>

                    {/* REQUEST DETAILS (ONLY PURPOSE_DETAILS, NEVER PURPOSE) */}
                    <TableCell>
                      {request.purpose_details ? (
                        <Tooltip
                          title={String(request.purpose_details)}
                          placement="top-start"
                          arrow
                        >
                          <Typography
                            variant="body2"
                            color="text.primary"
                            sx={{
                              display: '-webkit-box',
                              WebkitBoxOrient: 'vertical',
                              WebkitLineClamp: 2,
                              overflow: 'hidden',
                              overflowWrap: 'anywhere',
                              fontSize: '0.84rem',
                              lineHeight: 1.3,
                              cursor: 'help'
                            }}
                          >
                            {String(request.purpose_details)}
                          </Typography>
                        </Tooltip>
                      ) : (
                        <Typography variant="body2" color="text.secondary" sx={{ fontSize: '0.84rem', lineHeight: 1.25 }}>
                          —
                        </Typography>
                      )}

                      {request.remarks && (
                        <Typography
                          variant="caption"
                          color="text.secondary"
                          display="block"
                          sx={{
                            mt: 0.2,
                            fontSize: '0.72rem',
                            fontStyle: 'italic',
                            lineHeight: 1.25,
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis'
                          }}
                          title={`Remarks: ${request.remarks}`}
                        >
                          Remarks: {request.remarks}
                        </Typography>
                      )}
                    </TableCell>

                    {/* PAYMENT */}
                    <TableCell>
                      <Typography
                        variant="body2"
                        sx={{
                          fontWeight: 700,
                          fontSize: '0.84rem',
                          color: 'primary.dark',
                          lineHeight: 1.25,
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis'
                        }}
                      >
                        {formatAmount(request.amount_paid)}
                      </Typography>
                      <Typography
                        variant="caption"
                        color="text.secondary"
                        display="block"
                        sx={{
                          mt: 0.2,
                          fontSize: '0.72rem',
                          lineHeight: 1.25,
                          textTransform: isOfficial ? 'none' : 'capitalize',
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis'
                        }}
                      >
                        {isOfficial ? 'Official Use' : (request.payment_type ? request.payment_type.replace(/_/g, ' ') : '—')}
                      </Typography>
                    </TableCell>

                    {/* ISSUED */}
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
                        {formatDateTable(request.date_issued) || '—'}
                      </Typography>
                      {request.place_issued && (
                        <Typography
                          variant="caption"
                          color="text.secondary"
                          display="block"
                          sx={{
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
                          display="block"
                          sx={{
                            mt: 0.2,
                            fontSize: '0.72rem',
                            lineHeight: 1.25,
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis'
                          }}
                          title={`Updated by: ${request.updated_by_name}`}
                        >
                          Updated by: {request.updated_by_name}
                        </Typography>
                      )}
                    </TableCell>

                    {/* ACTIONS */}
                    <TableCell sx={{
                      verticalAlign: 'top',
                      position: 'sticky',
                      right: 0,
                      zIndex: 1,
                      backgroundColor: '#fff',
                      borderLeft: '1px solid',
                      borderColor: 'divider',
                      boxShadow: '-4px 0 8px rgba(15, 23, 42, 0.04)',
                      textAlign: 'center',
                      py: 0.7,
                      px: 1
                    }}>
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
              {!loading && pagedRequests.length === 0 && (
                <TableRow>
                  <TableCell colSpan={8} sx={{ border: 'none', p: 0 }}>
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
          count={debouncedSearchTerm ? filteredRequests.length : totalCount}
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
