import React, { useState, useEffect } from 'react';
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
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Alert
} from '@mui/material';
import {
  Search as SearchIcon,
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  FilterList as FilterIcon,
  Visibility as VisibilityIcon,
  Print as PrintIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import { format } from 'date-fns';
// We'll load html2pdf.js from CDN at runtime to avoid webpack sourcemap warnings

import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';
import { statusColors } from '../../theme/theme';
import PropertyFormModal from '../PropertyFormModal/PropertyFormModal';

const PropertyTable = () => {
  const { isAdmin } = useAuth();
  const [properties, setProperties] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [totalCount, setTotalCount] = useState(0);
  
  // Safety check - ensure properties is always an array
  const safeProperties = properties || [];
  
  // Search and filter states
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState({
    status: '',
    location: '',
    dateFrom: null,
    dateTo: null
  });
  
  // Modal states
  const [propertyModal, setPropertyModal] = useState(false);
  const [selectedProperty, setSelectedProperty] = useState(null);
  const [deleteDialog, setDeleteDialog] = useState(false);
  const [propertyToDelete, setPropertyToDelete] = useState(null);
  const [historyModal, setHistoryModal] = useState(false);
  const [taxHistory, setTaxHistory] = useState([]);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [printModal, setPrintModal] = useState(false);
  const [printHistory, setPrintHistory] = useState([]);
  const [printLoading, setPrintLoading] = useState(false);
  const initialSettings = (() => {
    if (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__) return window.__ASSESSOR_SETTINGS__;
    try {
      const cached = localStorage.getItem('assessor_settings');
      if (cached) return JSON.parse(cached);
    } catch (_) {}
    const cachedLogo = typeof window !== 'undefined' ? localStorage.getItem('app_logo_url') : '';
    if (cachedLogo) return { app_logo_url: cachedLogo };
    return null;
  })();
  const [settings, setSettings] = useState(initialSettings);

  useEffect(() => {
    fetchProperties();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, rowsPerPage, searchTerm, filters]);

  useEffect(() => {
    const loadSettings = async () => {
      try {
        const data = await apiService.getSettings();
        setSettings(data);
        try {
          localStorage.setItem('assessor_settings', JSON.stringify(data));
          if (data && data.app_logo_url) localStorage.setItem('app_logo_url', data.app_logo_url);
        } catch (_) {}
      } catch (e) {
        setSettings(null);
      }
    };
    loadSettings();
  }, []);

  const fetchProperties = async () => {
    try {
      setLoading(true);
      setError(''); // Clear previous errors
      
      const params = {
        page: page + 1,
        per_page: rowsPerPage,
        // Map search term to the fields the API expects
        tax_declaration_number: searchTerm || '',
        declarant_last_name: searchTerm || '',
        declarant_first_name: searchTerm || '',
        location: filters.location || '',
        status: filters.status || '',
        date_from: filters.dateFrom ? format(filters.dateFrom, 'yyyy-MM-dd') : '',
        date_to: filters.dateTo ? format(filters.dateTo, 'yyyy-MM-dd') : ''
      };
      
      console.log('Fetching properties with params:', params);
      console.log('API endpoint:', '/wp-json/assessor/v1/properties');
      
      const response = await apiService.getProperties(params);
      console.log('API response:', response);
      
      if (response && response.properties) {
        setProperties(response.properties);
        setTotalCount(response.pagination ? response.pagination.total : response.properties.length);
        console.log('Properties loaded:', response.properties.length);
      } else if (response && response.data) {
        // Fallback for different response format
        setProperties(response.data);
        setTotalCount(response.total || response.data.length);
        console.log('Properties loaded (fallback):', response.data.length);
      } else {
        console.warn('Unexpected API response format:', response);
        setProperties([]);
        setTotalCount(0);
      }
    } catch (err) {
      console.error('Error fetching properties:', err);
      setError(`Failed to fetch properties: ${err.message || 'Unknown error'}`);
      setProperties([]);
      setTotalCount(0);
    } finally {
      setLoading(false);
    }
  };

  const handleSearch = (event) => {
    setSearchTerm(event.target.value);
    setPage(0);
  };

  const handleFilterChange = (field, value) => {
    setFilters(prev => ({ ...prev, [field]: value }));
    setPage(0);
  };

  const handlePageChange = (event, newPage) => {
    setPage(newPage);
  };

  const handleRowsPerPageChange = (event) => {
    setRowsPerPage(parseInt(event.target.value, 10));
    setPage(0);
  };

  const handleAddProperty = () => {
    setSelectedProperty(null);
    setPropertyModal(true);
  };

  const handleEditProperty = (property) => {
    setSelectedProperty(property);
    setPropertyModal(true);
  };

  const handleDeleteProperty = (property) => {
    setPropertyToDelete(property);
    setDeleteDialog(true);
  };

  const confirmDelete = async () => {
    try {
      await apiService.deleteProperty(propertyToDelete.id);
      setDeleteDialog(false);
      setPropertyToDelete(null);
      fetchProperties();
    } catch (err) {
      setError('Failed to delete property');
    }
  };

  const handlePropertySaved = () => {
    setPropertyModal(false);
    setSelectedProperty(null);
    fetchProperties();
  };

  const handleViewHistory = async (taxDeclarationNumber) => {
    try {
      setHistoryLoading(true);
      setHistoryModal(true);
      
      const response = await apiService.getTaxDeclarationHistory(taxDeclarationNumber);
      setTaxHistory(response || []);
    } catch (err) {
      console.error('Error fetching tax declaration history:', err);
      setTaxHistory([]);
    } finally {
      setHistoryLoading(false);
    }
  };

  const handleViewPrintableHistory = async (taxDeclarationNumber) => {
    try {
      setPrintLoading(true);
      setPrintModal(true);
      const response = await apiService.getTaxDeclarationHistory(taxDeclarationNumber);
      setPrintHistory(response || []);
    } catch (err) {
      console.error('Error fetching printable history:', err);
      setPrintHistory([]);
    } finally {
      setPrintLoading(false);
    }
  };

  // removed html2pdf

  const handlePrint = async () => {
    const printWindow = window.open('', '_blank');
    if (!printWindow) return;
    const rawLogo = (settings && settings.app_logo_url) || (window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.app_logo_url) || '';
    const appLogoUrl = rawLogo ? (rawLogo + (rawLogo.indexOf('?') === -1 ? '?v=' + Date.now() : '&v=' + Date.now())) : '';
    const headerPh = 'Republic of the Philippines';
    // Format helpers
    const toFormalCase = (text) => {
      if (!text) return '';
      const small = new Set(['of','and','the','for','in','on','at','a','an']);
      const words = String(text).toLowerCase().split(/\s+/);
      return words.map((w, i) => {
        if (!w) return w;
        if (i > 0 && small.has(w)) return w;
        return w.charAt(0).toUpperCase() + w.slice(1);
      }).join(' ');
    };
    const baseProvince = (settings && settings.header_province) || (window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_province) || 'Bukidnon';
    const headerProvince = `Province of ${toFormalCase(baseProvince)}`;
    const baseMunicipality = (settings && settings.header_municipality) || (window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_municipality) || 'KITAOTAO';
    const headerMunicipality = `MUNICIPALITY OF ${baseMunicipality}`;
    const headerOffice = (settings && settings.header_office) || (window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_office) || 'OFFICE OF THE MUNICIPAL ASSESSOR';
    const headerTitle = 'RECORD VERIFICATION DATA FORM';
    const styles = `
      <style>
        /* Paged.js pagination and margin boxes */
        @page {
          size: A4 portrait;
          margin: 12mm 8mm;
          @bottom-right {
            content: counter(page) "/" counter(pages);
            font-size: 10px;
            color: #666;
          }
        }
        @media print {
          html, body { width: 210mm; }
          body { padding: 0; }
        }
        body { font-family: Arial, sans-serif; }

        .header { font-family: Times New Roman, sans-serif; text-align: center; }
        .header img { height: 64px; display: block; margin: 0 auto 8px auto; }
        .header h3 { font-size: 16px; margin: 2px 0; font-weight: 400; }
        .header h4 { font-size: 16px; margin: 2px 0; font-weight: 400; }
        .subheader { margin-top: 8px; font-weight: 700; text-decoration: underline; }
        .info { border: 1px solid #000; border-collapse: separate; border-spacing: 0; margin: 12px auto; }
        .info td { border: none; padding: 6px 8px; font-size: 12px; vertical-align: top; text-align: left; }
        .label { width: 220px; font-weight: 600; }
        .value { font-weight: normal; }

        /* Table layout and fragmentation with Paged.js */
        table { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; }
        thead { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        thead, tfoot { display: table-header-group; }
        th, td { border: 1px solid #ddd; padding: 4px; font-size: 10px; text-align: center; vertical-align: top; }
        th { background-color: #cccccc !important; text-align: center; vertical-align: middle !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        td { vertical-align: top !important; break-inside: auto; page-break-inside: auto; }
        tr { break-inside: auto; page-break-inside: auto; }
        .caption { color: #666; font-size: 11px; }

        /* Memoranda column: allow splitting across pages */
        td.memo { white-space: normal; word-break: break-word; overflow-wrap: anywhere; hyphens: auto; }
        td.memo .memo-content { break-inside: auto; page-break-inside: auto; }

        /* Column widths */
        thead th:nth-child(1), tbody td:nth-child(1) { width: 100px; }
        thead th:nth-child(2), tbody td:nth-child(2) { width: 100px; }
        thead th:nth-child(3), tbody td:nth-child(3) { width: 50px; }
        thead th:nth-child(4), tbody td:nth-child(4) { width: 70px; }
        thead th:nth-child(5), tbody td:nth-child(5) { width: 70px; }
        thead th:nth-child(6), tbody td:nth-child(6) { width: 90px; }
        thead th:nth-child(7), tbody td:nth-child(7) { width: 70px; }
        thead th:nth-child(8), tbody td:nth-child(8) { width: 40%; text-align: left; }
      </style>
    `;
    const rows = (printHistory || []).map((item, index) => `
      <tr>
        <td>
          <div>${item.tax_declaration_number || ''}</div>
        </td>
        <td>${item.declarant_name || ''}</td>
        <td>${item.lot_number || ''}</td>
        <td>${item.area_hectare ? item.area_hectare + (item.area_hectare <= 1 ? ' ha' : ' has') : ''}</td>
        <td>${item.title_number || ''}</td>
        <td>₱${(item.assessed_value !== undefined && item.assessed_value !== null)
          ? Number(item.assessed_value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
          : '0.00'}</td>
        <td>${item.effectivity_date || ''}</td>
        <td class="memo"><div class="memo-content">${item.memoranda || ''}</div></td>
      </tr>
    `).join('');
    const html = `
      <html>
        <head>
          <title></title>
          ${styles}
        </head>
        <body>
          <div class="header">
            <img src="${appLogoUrl}" alt="Logo" onerror="this.style.display='none'" />
            <h4>${headerPh}</h4>
            <h4>${headerProvince}</h4>
            <h3>${headerMunicipality}</h3>
            <h3>${headerOffice}</h3>
            <div class="subheader">${headerTitle}</div>
          </div>

          <table class="info">
            <tr>
              <td class="label">TAX DECLARATION NUMBER: <span class="value">${(printHistory[0] && printHistory[0].tax_declaration_number) || ''}</span></td>
              <td class="label">PIN: <span class="value">${(printHistory[0] && printHistory[0].pin) || ''}</span></td>
            </tr>
            <tr>
              <td class="label">OWNER: <span class="value">${(printHistory[0] && printHistory[0].declarant_name) || ''}</span></td>
              <td class="label">ADDRESS: <span class="value">${(printHistory[0] && printHistory[0].address) || ''}</span></td>
            </tr>
            <tr>
              <td class="label">LOCATION: <span class="value">${(printHistory[0] && printHistory[0].location) || ''}</span></td>
              <td class="label">ASSESSMENT DATE: <span class="value">${(printHistory[0] && printHistory[0].assessment_date) || ''}</span></td>
            </tr>
            <tr>
              <td class="label">EFFECTIVITY DATE: <span class="value">${(printHistory[0] && printHistory[0].effectivity_date) || ''}</span></td>
              <td class="label">KIND OF PROPERTY: <span class="value">${(printHistory[0] && printHistory[0].kind_of_property) || ''}</span></td>
            </tr>
            <tr>
              <td class="label"></td>
              <td class="label">GEN. CLASS: <span class="value">${(printHistory[0] && printHistory[0].gen_class) || ''}</span></td>
            </tr>
          </table>
          

          <table class="history-table">
            <thead>
              <tr>
                <th>Tax Declaration Number</th>
                <th>Declarant</th>
                <th>Lot Number</th>
                <th>Area (hectare)</th>
                <th>Title Number</th>
                <th>Assessed Value</th>
                <th>Effectivity</th>
                <th>Memoranda</th>
              </tr>
            </thead>
            <tbody>
              ${rows}
            </tbody>
          </table>
          <script src="https://unpkg.com/pagedjs/dist/paged.polyfill.js"></script>
          <script>
            (function(){
              try {
                var base = (window.opener && window.opener.location) ? window.opener.location.origin : (location.origin || '');
                if (base) { history.replaceState(null, '', base + '/print'); }
              } catch(e){}
              function printNow(){
                setTimeout(function(){
                  try { window.focus(); window.print(); } catch(e){}
                  try { window.close(); } catch(e){}
                }, 200);
              }
              function setup(){
                try { document.addEventListener('pagedjs:rendered', function(){ printNow(); }, { once: true }); } catch(e){}
                // Fallback: if Paged.js fails, attempt to print after images load
                var imgs = Array.prototype.slice.call(document.images || []);
                if (!imgs.length) {
                  setTimeout(printNow, 800);
                } else {
                  var remaining = imgs.length;
                  var timer = setTimeout(printNow, 3000);
                  imgs.forEach(function(img){
                    if (img.complete) { if (--remaining === 0) { clearTimeout(timer); printNow(); } }
                    else {
                      img.addEventListener('load', function(){ if (--remaining === 0) { clearTimeout(timer); printNow(); } });
                      img.addEventListener('error', function(){ if (--remaining === 0) { clearTimeout(timer); printNow(); } });
                    }
                  });
                }
              }
              if (document.readyState === 'complete' || document.readyState === 'interactive') { setup(); }
              else { document.addEventListener('DOMContentLoaded', setup); }
            })();
          </script>
        </body>
      </html>
    `;

    // Use HTML2PDF to generate a real PDF for consistent headers/footers/paging
    // Fallback to simple print popup (html2pdf removed per request)
    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
  };

  // const getStatusColor = (status) => {
  //   return statusColors[status] || statusColors.info;
  // };

  const clearFilters = () => {
    setFilters({
      status: '',
      location: '',
      dateFrom: null,
      dateTo: null
    });
    setSearchTerm('');
    setPage(0);
  };

  if (loading && (!safeProperties || safeProperties.length === 0)) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <Typography>Loading properties...</Typography>
      </Box>
    );
  }

  // Add error boundary protection
  if (!safeProperties) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <Typography color="error">Error: Properties data is not available</Typography>
      </Box>
    );
  }

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      <Typography variant="h4" gutterBottom>
        Property Records
      </Typography>

      {error && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError('')}>
          <Typography variant="body2">
            {error}
          </Typography>
          <Typography variant="caption" sx={{ mt: 1, display: 'block' }}>
            Check the browser console for more details.
          </Typography>
        </Alert>
      )}

      {/* Search and Filters */}
      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Grid container spacing={2} alignItems="center">
            <Grid item xs={12} md={4}>
              <TextField
                fullWidth
                label="Search Properties"
                value={searchTerm}
                onChange={handleSearch}
                placeholder="Search by tax declaration number or declarant..."
                InputProps={{
                  startAdornment: <SearchIcon sx={{ mr: 1, color: 'text.secondary' }} />
                }}
              />
            </Grid>
            
            <Grid item xs={12} md={2}>
              <FormControl fullWidth>
                <InputLabel>Status</InputLabel>
                <Select
                  value={filters.status}
                  label="Status"
                  onChange={(e) => handleFilterChange('status', e.target.value)}
                >
                  <MenuItem value="">All</MenuItem>
                  <MenuItem value="active">Active</MenuItem>
                  <MenuItem value="inactive">Inactive</MenuItem>
                  <MenuItem value="archived">Archived</MenuItem>
                  <MenuItem value="pending">Pending</MenuItem>
                </Select>
              </FormControl>
            </Grid>

            <Grid item xs={12} md={2}>
              <TextField
                fullWidth
                label="Location"
                value={filters.location}
                onChange={(e) => handleFilterChange('location', e.target.value)}
                placeholder="City/Municipality"
              />
            </Grid>

            <Grid item xs={12} md={2}>
              <DatePicker
                label="From Date"
                value={filters.dateFrom}
                onChange={(date) => handleFilterChange('dateFrom', date)}
                renderInput={(params) => <TextField {...params} fullWidth />}
              />
            </Grid>

            <Grid item xs={12} md={2}>
              <DatePicker
                label="To Date"
                value={filters.dateTo}
                onChange={(date) => handleFilterChange('dateTo', date)}
                renderInput={(params) => <TextField {...params} fullWidth />}
              />
            </Grid>

            <Grid item xs={12} md={6}>
              <Button
                variant="outlined"
                startIcon={<FilterIcon />}
                onClick={clearFilters}
                sx={{ mr: 1 }}
              >
                Clear Filters
              </Button>
            </Grid>

            <Grid item xs={12} md={6} textAlign="right">
              <Button
                variant="contained"
                startIcon={<AddIcon />}
                onClick={handleAddProperty}
                color="primary"
              >
                Add Property
              </Button>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* Properties Table */}
      <Paper sx={{ width: '100%', overflow: 'hidden' }}>
        <TableContainer>
          <Table stickyHeader>
            <TableHead>
              <TableRow>
                <TableCell sx={{ width: 150 }}>Tax Declaration Number</TableCell>
                <TableCell sx={{ width: 150 }}>Declarant</TableCell>
                <TableCell sx={{ width: 80 }}>Lot Number</TableCell>
                <TableCell sx={{ width: 80 }}>Area (hectare)</TableCell>
                <TableCell sx={{ width: 80 }}>Title Number</TableCell>
                <TableCell sx={{ width: 120 }}>Assessed Value</TableCell>
                <TableCell sx={{ width: 80 }}>Effectivity</TableCell>
                <TableCell>Memoranda</TableCell>
                <TableCell sx={{ width: 140 }}>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody sx={{ '& td': { verticalAlign: 'top', py: 0.75 } }}>
              {safeProperties && safeProperties.length > 0 ? safeProperties.map((property) => (
                <TableRow key={property.id} hover>
                  <TableCell sx={{ verticalAlign: 'top' }}>
                    <Typography
                      variant="body2"
                      sx={{ cursor: 'pointer', color: 'primary.main', textDecoration: 'underline', fontWeight: 600, lineHeight: 1.4, display: 'inline' }}
                      onClick={() => handleViewHistory(property.tax_declaration_number)}
                    >
                      {property.tax_declaration_number}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    {property.declarant_last_name && property.declarant_first_name 
                      ? `${property.declarant_last_name}, ${property.declarant_first_name}${property.declarant_middle_initial ? ` ${property.declarant_middle_initial}.` : ''}`
                      : property.declarant || 'N/A'
                    }
                  </TableCell>
                  <TableCell>{property.lot_number}</TableCell>
                  <TableCell>{property.area_hectare}</TableCell>
                  <TableCell>{property.title_number || '—'}</TableCell>
                  <TableCell>
                    <Typography variant="body2" fontWeight={600}>
                      ₱{(property.assessed_value !== undefined && property.assessed_value !== null)
                        ? Number(property.assessed_value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                        : '0.00'}
                    </Typography>
                  </TableCell>
                  <TableCell>{property.effectivity_date || '—'}</TableCell>
                  <TableCell sx={{ width: 280, maxWidth: 280, verticalAlign: 'top' }}>
                    <Typography
                      variant="body2"
                      sx={{ whiteSpace: 'normal', wordBreak: 'break-word' }}
                    >
                      {property.memoranda || '—'}
                    </Typography>
                  </TableCell>
                  <TableCell sx={{ verticalAlign: 'top' }}>
                    <Box display="flex" gap={1} alignItems="flex-start">
                      <IconButton
                        size="small"
                        onClick={() => handleViewPrintableHistory(property.tax_declaration_number)}
                        color="default"
                        sx={{ p: 0.25 }}
                        title="View History (Print)"
                      >
                        <VisibilityIcon fontSize="small" />
                      </IconButton>
                      <IconButton
                        size="small"
                        onClick={() => handleEditProperty(property)}
                        color="primary"
                        sx={{ p: 0.25 }}
                      >
                        <EditIcon fontSize="small" />
                      </IconButton>
                      {isAdmin && (
                        <IconButton
                          onClick={() => handleDeleteProperty(property)}
                          color="error"
                          size="small"
                          sx={{ p: 0.25 }}
                        >
                          <DeleteIcon fontSize="small" />
                        </IconButton>
                      )}
                    </Box>
                  </TableCell>
                </TableRow>
              )) : (
                <TableRow>
                  <TableCell colSpan={9} align="center">
                    <Typography variant="body2" color="text.secondary">
                      {loading ? 'Loading properties...' : 'No properties found'}
                    </Typography>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </TableContainer>
        
        <TablePagination
          rowsPerPageOptions={[5, 10, 25, 50]}
          component="div"
          count={totalCount}
          rowsPerPage={rowsPerPage}
          page={page}
          onPageChange={handlePageChange}
          onRowsPerPageChange={handleRowsPerPageChange}
        />
      </Paper>

      {/* Property Form Modal */}
      <Dialog
        open={propertyModal}
        onClose={() => setPropertyModal(false)}
        maxWidth="md"
        fullWidth
      >
        <DialogTitle>
          {selectedProperty ? 'Edit Property' : 'Add New Property'}
        </DialogTitle>
        <DialogContent>
          <PropertyFormModal
            property={selectedProperty}
            onSave={handlePropertySaved}
            onCancel={() => setPropertyModal(false)}
            open={propertyModal}
          />
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialog} onClose={() => setDeleteDialog(false)}>
        <DialogTitle>Confirm Delete</DialogTitle>
        <DialogContent>
          <Typography>
            Are you sure you want to delete the property "{propertyToDelete?.tax_declaration_number}"?
            This action cannot be undone.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeleteDialog(false)}>Cancel</Button>
          <Button onClick={confirmDelete} color="error" variant="contained">
            Delete
          </Button>
        </DialogActions>
      </Dialog>

      {/* Tax Declaration History Modal */}
      <Dialog 
        open={historyModal} 
        onClose={() => setHistoryModal(false)}
        maxWidth="md"
        fullWidth
      >
        <DialogTitle>
          Tax Declaration History
        </DialogTitle>
        <DialogContent>
          {historyLoading ? (
            <Box display="flex" justifyContent="center" p={3}>
              <Typography>Loading history...</Typography>
            </Box>
          ) : taxHistory.length > 0 ? (
            <TableContainer component={Paper}>
              <Table size="small" stickyHeader>
                {/* <TableHead>
                  <TableRow>
                    <TableCell>Tax Declaration Number</TableCell>
                    <TableCell>Declarant</TableCell>
                    <TableCell>Lot Number</TableCell>
                    <TableCell>Area (hectare)</TableCell>
                    <TableCell>Title Number</TableCell>
                    <TableCell>Assessed Value</TableCell>
                    <TableCell>Effectivity</TableCell>
                  </TableRow>
                </TableHead> */}
                <TableBody>
                  {taxHistory.map((item, index) => (
                    <TableRow key={index} hover>
                      <TableCell>
                        <Typography variant="body2" fontWeight={600} color="primary">
                          {item.tax_declaration_number}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {index === 0 ? 'Current' : 'Previous'}
                        </Typography>
                      </TableCell>
                      <TableCell>{item.declarant_name}</TableCell>
                      <TableCell>{item.lot_number || '—'}</TableCell>
                      <TableCell>{item.area_hectare || '—'}</TableCell>
                      <TableCell>{item.title_number || '—'}</TableCell>
                      <TableCell>
                        ₱{(item.assessed_value !== undefined && item.assessed_value !== null)
                          ? Number(item.assessed_value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                          : '0.00'}
                      </TableCell>
                      <TableCell>{item.effectivity_date || '—'}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </TableContainer>
          ) : (
            <Typography>No history found for this tax declaration number.</Typography>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setHistoryModal(false)}>Close</Button>
        </DialogActions>
      </Dialog>

      {/* Printable Tax Declaration History Modal */}
      <Dialog 
        open={printModal} 
        onClose={() => setPrintModal(false)}
        maxWidth="md"
        fullWidth
      >
        <DialogTitle>
          Tax Declaration History (Printable)
        </DialogTitle>
        <DialogContent>
          {printLoading ? (
            <Box display="flex" justifyContent="center" p={3}>
              <Typography>Loading history...</Typography>
            </Box>
          ) : printHistory.length > 0 ? (
            <TableContainer component={Paper}>
              <Table size="small" stickyHeader>
                <TableHead>
                  <TableRow>
                    <TableCell>Tax Declaration Number</TableCell>
                    <TableCell>Declarant</TableCell>
                    <TableCell>Lot Number</TableCell>
                    <TableCell>Area (hectare)</TableCell>
                    <TableCell>Title Number</TableCell>
                    <TableCell>Assessed Value</TableCell>
                    <TableCell>Effectivity</TableCell>
                    <TableCell>Memoranda</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {printHistory.map((item, index) => (
                    <TableRow key={index} hover>
                      <TableCell>
                        <Typography variant="body2" fontWeight={600} color="primary">
                          {item.tax_declaration_number}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {index === 0 ? 'Current' : 'Previous'}
                        </Typography>
                      </TableCell>
                      <TableCell>{item.declarant_name}</TableCell>
                      <TableCell>{item.lot_number || '—'}</TableCell>
                      <TableCell>{item.area_hectare || '—'}</TableCell>
                      <TableCell>{item.title_number || '—'}</TableCell>
                      <TableCell>
                        ₱{(item.assessed_value !== undefined && item.assessed_value !== null)
                          ? Number(item.assessed_value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                          : '0.00'}
                      </TableCell>
                      <TableCell>{item.effectivity_date || '—'}</TableCell>
                      <TableCell sx={{ maxWidth: 280 }}>
                        <Typography variant="body2" sx={{ whiteSpace: 'normal', wordBreak: 'break-word' }}>
                          {item.memoranda || '—'}
                        </Typography>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </TableContainer>
          ) : (
            <Typography>No history found for this tax declaration number.</Typography>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setPrintModal(false)}>Close</Button>
          <Button onClick={handlePrint} startIcon={<PrintIcon />} variant="contained">
            Print
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default PropertyTable;

