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
  Collapse
} from '@mui/material';
import {
  Search as SearchIcon,
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Visibility as VisibilityIcon,
  Print as PrintIcon,
  Receipt as ReceiptIcon,
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

// Purpose options for filter
const purposeOptions = [
  { value: 'record_verification', label: 'Record Verification' },
  { value: 'tax_declaration', label: 'Tax Declaration' },
  { value: 'property_assessment', label: 'Property Assessment' },
  { value: 'certification', label: 'Certification' },
  { value: 'other', label: 'Other' }
];

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
  const [loadingAll, setLoadingAll] = useState(false);

  // Safety check - ensure requests is always an array
  const safeRequests = requests || [];

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

  // Modal states
  const [printModal, setPrintModal] = useState(false);
  const [printRequestData, setPrintRequestData] = useState(null);
  const [printHistory, setPrintHistory] = useState([]);
  const [printLoading, setPrintLoading] = useState(false);
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
          (request.remarks && String(request.remarks).toUpperCase().includes(searchUpper)) ||
          (request.prepared_by && String(request.prepared_by).toUpperCase().includes(searchUpper));

        if (!matchesSearch) return false;
      }

      // Apply other filters
      if (filters.purpose && request.purpose !== filters.purpose) return false;
      if (filters.dateIssued && request.date_issued !== filters.dateIssued) return false;
      if (filters.preparedBy && request.prepared_by !== filters.preparedBy) return false;

      return true;
    });
  }, [allRequests, safeRequests, debouncedSearchTerm, filters]);

  // Paged requests for display
  const pagedRequests = useMemo(() => {
    const start = page * rowsPerPage;
    const end = start + rowsPerPage;
    return filteredRequests.slice(start, end);
  }, [filteredRequests, page, rowsPerPage]);

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
    } catch (_) {}
  };

  // Print ref
  const printRef = useRef(null);
  const handlePrint = useReactToPrint({
    contentRef: printRef,
    removeAfterPrint: true,
    pageStyle: getHistoryPrintPageStyle(paperSize)
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

  // Load data on component mount
  useEffect(() => {
    fetchSettings();
    fetchUsers();
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
        setLoadingAll(true);
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
      } finally {
        setLoadingAll(false);
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
    // Refresh the requests list
    fetchRequests();
    // Show success message or handle as needed
    console.log('Request form saved:', requestData);
  };

  // Handle delete request
  const handleDeleteRequest = async (requestId) => {
    if (!window.confirm('Are you sure you want to delete this request?')) {
      return;
    }

    try {
      await apiService.deleteRequest(requestId);
      fetchRequests(); // Refresh the list
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
        {/* <Typography variant="body2" color="text.secondary">
          Please wait while the system loads
        </Typography> */}
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
                // helperText={`Total: ${totalCount} requests`}
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
                    height: 40, // same as TextField small height
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
                    height: 40, // same as TextField small height
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
      <Paper sx={{ width: '100%', display: 'flex', flexDirection: 'column', flexGrow: 1, flexShrink: 1, minHeight: 0, overflow: 'hidden', position: 'relative' }}>
        {loading && (
          <Box sx={{ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', zIndex: 10, pointerEvents: 'none' }}>
            <CircularProgress size={40} sx={{ mb: 2, color: 'primary.main' }} />
            <Typography variant="body1" color="text.primary" sx={{ fontWeight: 600 }}>
              {debouncedSearchTerm ? 'Searching requests...' : 'Loading requests...'}
            </Typography>
          </Box>
        )}
        <TableContainer ref={tableContainerRef} sx={{ flexGrow: 1, flexShrink: 1, minHeight: 0, overflow: 'auto' }}>
          <Table stickyHeader sx={{ tableLayout: 'fixed' }}>
            <TableHead>
              <TableRow>
                <TableCell><strong>Receipt No.</strong></TableCell>
                <TableCell><strong>Client Name</strong></TableCell>
                <TableCell><strong>Property</strong></TableCell>
                <TableCell><strong>Amount</strong></TableCell>
                <TableCell><strong>Remarks</strong></TableCell>
                <TableCell><strong>Purpose</strong></TableCell>
                <TableCell><strong>Date Issued</strong></TableCell>
                <TableCell><strong>Prepared By</strong></TableCell>
                <TableCell><strong>Actions</strong></TableCell>
              </TableRow>
            </TableHead>
            <TableBody sx={{
              opacity: (loading && !initialLoad) ? 0.5 : 1,
              pointerEvents: (loading && !initialLoad) ? 'none' : 'auto',
              transition: 'opacity 0.2s ease-in-out'
            }}>
              {pagedRequests.map((request) => (
                <TableRow key={request.id} hover>
                  <TableCell>
                    <Typography variant="body2" fontWeight="medium">
                      {request.receipt_number}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">
                      {request.client_name}
                    </Typography>
                    {request.client_address && (
                      <Typography variant="caption" color="text.secondary" display="block">
                        {request.client_address}
                      </Typography>
                    )}
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">
                      {(() => {
                        const declarant = formatDeclarantFromParts(request.declarant_last_name, request.declarant_first_name, request.declarant_middle_initial);
                        const business = request.business ? String(request.business).replace(/,\s*/g, ' ') : '';
                        if (declarant && business) return `${declarant} / ${business}`;
                        return declarant || business || '';
                      })()}
                    </Typography>
                    {request.tax_declaration_number && (
                      <Typography variant="caption" color="text.secondary" display="block">
                        TD: {request.tax_declaration_number}
                        <br />
                        BARANGAY: {request.location}
                      </Typography>
                    )}
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2" fontWeight="bold" color="primary">
                      {formatAmount(request.amount_paid)}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">
                      {request.remarks || '-'}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">
                      {request.purpose}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">
                      {formatDateTable(request.date_issued)}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">
                      {request.prepared_by}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Box sx={{ display: 'flex', gap: 1 }}>
                      <IconButton
                        size="small"
                        onClick={() => handlePrintRequest(request)}
                        disabled={isViewer}
                        title="Print Request History"
                      >
                        <PrintIcon />
                      </IconButton>
                      {(isAdmin || isSuperAdmin) && (
                        <IconButton
                          size="small"
                          onClick={() => handleDeleteRequest(request.id)}
                          title="Delete Request"
                          color="error"
                        >
                          <DeleteIcon />
                        </IconButton>
                      )}
                    </Box>
                  </TableCell>
                </TableRow>
              ))}
              {!loading && pagedRequests.length === 0 && (
                <TableRow>
                  <TableCell colSpan={9} sx={{ border: 'none', p: 0 }}>
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
        <DialogContent sx={{ p: 2 }}>
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
              {/* <Typography variant="body2" color="text.secondary">
                 Please wait while the system loads
               </Typography> */}
            </Box>
          ) : (
            <Box sx={{ maxHeight: '70vh', overflow: 'auto', width: '100%' }}>
              <HistoryPrintDocument ref={printRef} settings={settings} printHistory={printHistory} requestData={printRequestData} documentType="request" />
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
        <HistoryPrintDocument ref={printRef} settings={settings} printHistory={printHistory} requestData={printRequestData} documentType="request" paperSize={paperSize} />
        <div className="print-page-footer"><span className="pageNumber" /></div>
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
