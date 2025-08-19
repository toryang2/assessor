import React, { useState, useEffect, useRef, forwardRef } from 'react';
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
import { useReactToPrint } from 'react-to-print';

const PrintableHistory = forwardRef(({ settings, printHistory }, ref) => {
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
  const rawLogo = (settings && settings.app_logo_url) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.app_logo_url) || '';
  const appLogoUrl = rawLogo ? (rawLogo + (rawLogo.indexOf('?') === -1 ? '?v=' + Date.now() : '&v=' + Date.now())) : '';
  const headerPh = 'Republic of the Philippines';
  const baseProvince = (settings && settings.header_province) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_province) || 'Bukidnon';
  const headerProvince = `Province of ${toFormalCase(baseProvince)}`;
  const baseMunicipality = (settings && settings.header_municipality) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_municipality) || 'KITAOTAO';
  const headerMunicipality = `MUNICIPALITY OF ${baseMunicipality}`;
  const headerOffice = (settings && settings.header_office) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_office) || 'OFFICE OF THE MUNICIPAL ASSESSOR';
  const headerTitle = 'RECORD VERIFICATION DATA FORM';

  return (
    <div ref={ref} className="print-root" style={{ width: '210mm' }}>
      <div className="print-header" style={{ textAlign: 'center', fontFamily: 'Times New Roman, sans-serif' }}>
        {appLogoUrl ? (
          <img src={appLogoUrl} alt="Logo" style={{ height: 64, display: 'block', margin: '0 auto 8px auto' }} onError={(e) => { e.currentTarget.style.display = 'none'; }} />
        ) : null}
        <h4 style={{ fontSize: 16, margin: '-7px 0', fontWeight: 400 }}>{headerPh}</h4>
        <h4 style={{ fontSize: 16, margin: '-7px 0', fontWeight: 400 }}>{headerProvince}</h4>
        <h4 style={{ fontSize: 16, margin: '-7px 0', fontWeight: 400 }}>{headerMunicipality}</h4>
        <h3 style={{ fontSize: 16, margin: '-7px 0', fontWeight: 600}}>{headerOffice}</h3>
        <div style={{ marginTop: 8, fontWeight: 700, textDecoration: 'underline', fontFamily: 'Tahoma, serif' }}>{headerTitle}</div>
      </div>

      <table style={{ border: '1px solid #000', borderCollapse: 'separate', borderSpacing: 0, margin: '12px auto', width: '100%' }} className="info">
        <tbody>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>TAX DECLARATION NUMBER:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].tax_declaration_number) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>PIN:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].pin) || ''}</span>
            </td>
          </tr>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>OWNER:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].declarant_name) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>ADDRESS:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].address) || ''}</span>
            </td>
          </tr>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>LOCATION:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].location) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>ASSESSMENT DATE:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].assessment_date) || ''}</span>
            </td>
          </tr>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>EFFECTIVITY DATE:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].effectivity_date) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>KIND OF PROPERTY:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].kind_of_property) || ''}</span>
            </td>
          </tr>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }} />
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>GEN. CLASS:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].gen_class) || ''}</span>
            </td>
          </tr>
        </tbody>
      </table>

      <table className="history-table" style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed' }}>
        <colgroup>
          <col style={{ width: '15%', }} />
          <col style={{ width: '12%' }} />
          <col style={{ width: '9%' }} />
          <col style={{ width: '9%' }} />
          <col style={{ width: '9%' }} />
          <col style={{ width: '11%' }} />
          <col style={{ width: '9%' }} />
          <col style={{ width: '32%' }} />
        </colgroup>
        <thead>
          <tr>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Tax Declaration Number</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Declarant</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Lot Number</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Area (hectare)</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Title Number</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Assessed Value</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Effectivity</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Memoranda</th>
          </tr>
        </thead>
        <tbody>
          {(printHistory || []).map((item, index) => (
            <tr key={index}>
              <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>
                <div>{item.tax_declaration_number || ''}</div>
              </td>
              <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>{item.declarant_name || ''}</td>
              <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>{item.lot_number || ''}</td>
              <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>{item.area_hectare ? item.area_hectare + (item.area_hectare <= 1 ? ' ha' : ' has') : ''}</td>
              <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>{item.title_number || ''}</td>
              <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>₱{(item.assessed_value !== undefined && item.assessed_value !== null)
                ? Number(item.assessed_value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : '0.00'}</td>
              <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>{item.effectivity_date || ''}</td>
              <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top', textAlign: 'left' }}>
                <div style={{ whiteSpace: 'normal', wordBreak: 'break-word', overflowWrap: 'anywhere' }}>{item.memoranda || ''}</div>
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr>
            <td colSpan="8" style={{ height: 0, lineHeight: 0, padding: 0, borderTop: '1px solid #ddd' }} />
          </tr>
        </tfoot>
      </table>

      {/* Spacer to push signature to the bottom of the last page when possible */}
      <div className="print-bottom-spacer" />

      {/* Signature block (print-only). Will naturally render on the last page and sit low. */}
      <div className="print-signature" style={{ width: '100%', marginTop: '8mm', paddingBottom: '8mm' }}>
        <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
          <div style={{ textAlign: 'center', width: '70mm' }}>
            <div style={{ height: '18mm' }} />
            <div style={{ borderTop: '1px solid #000', paddingTop: 4, fontSize: 12, fontWeight: 600 }}>
              {(settings && settings.signatory_name) || '____________________________'}
            </div>
            <div style={{ fontSize: 11 }}>
              {(settings && settings.signatory_title) || 'Municipal Assessor'}
            </div>
            <div style={{ fontSize: 10, marginTop: 2 }}>
              {(settings && settings.signatory_office) || headerOffice}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
});

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
  const printRef = useRef(null);
  const handlePrint = useReactToPrint({
    content: () => printRef.current,
    removeAfterPrint: true,
    onBeforeGetContent: () => {
      try {
        const root = printRef.current;
        if (!root) return;
        const spacer = root.querySelector('.print-bottom-spacer');
        if (!spacer) return;
        // Reset spacer first
        spacer.style.height = '0px';
        // Convert mm to px (assuming 96 DPI)
        const pxPerMm = 96 / 25.4;
        const a4HeightPx = 297 * pxPerMm;
        const topMarginPx = 12 * pxPerMm;
        const bottomMarginPx = 1 * pxPerMm; // 16 Default Change to 1 if super low
        const usablePageHeightPx = a4HeightPx - topMarginPx - bottomMarginPx;
        // Current total height (with signature present)
        const totalHeight = root.scrollHeight;
        const remainder = totalHeight % usablePageHeightPx;
        const spacerHeight = remainder === 0 ? 0 : (usablePageHeightPx - remainder);
        spacer.style.height = `${Math.max(0, Math.floor(spacerHeight))}px`;
      } catch (_) {}
    },
    onAfterPrint: () => {
      const root = printRef.current;
      if (!root) return;
      const spacer = root.querySelector('.print-bottom-spacer');
      if (spacer) spacer.style.height = '0px';
    },
    pageStyle: `
      @page { size: A4 portrait; margin: 12mm 8mm 16mm 8mm; 
          @bottom-right {
            content: counter(page) "/" counter(pages);
            font-family: 'Arial', sans-serif;
            font-size: 10px;
            color: #666;
          }
      }
      @media print {
        html, body { width: 210mm; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        *,
        :root,
        body,
        div, span, p, strong, em,
        table, thead, tbody, tfoot, tr, th, td,
        h1, h2, h3, h4, h5, h6 {
          color: #000 !important;
        }
        .history-table td { vertical-align: top !important; text-align: left !important; }
        .history-table td:last-child { text-align: left !important; }
        .history-table th { vertical-align: center !important; }
        .print-page-footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: right; font-size: 10px; padding: 2mm 8mm; }
        .print-page-footer .pageNumber::after { content: counter(page) " of " counter(pages); }
        /* Layout helpers to keep the signature at the bottom of the last page when space allows */
        .print-root { display: flex; flex-direction: column; min-height: calc(297mm - 12mm - 16mm); }
        .print-bottom-spacer { flex: 1 1 auto; }
        .print-signature { page-break-inside: avoid; }
      }
      thead { display: table-header-group; }
      tfoot { display: table-footer-group; }
      tfoot td { border: 0; border-top: 1px solid #ddd; }
      table { page-break-inside: auto; }
      tr { page-break-inside: auto; break-inside: auto; }
      td { page-break-inside: auto; }
      /* Allow memoranda content to split */
      td:last-child { white-space: normal; text-align: left; }
    `
  });

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
  const rawLogo = (settings && settings.app_logo_url) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.app_logo_url) || '';
  const appLogoUrl = rawLogo ? (rawLogo + (rawLogo.indexOf('?') === -1 ? '?v=' + Date.now() : '&v=' + Date.now())) : '';
  const headerPh = 'Republic of the Philippines';
  const baseProvince = (settings && settings.header_province) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_province) || 'Bukidnon';
  const headerProvince = `Province of ${toFormalCase(baseProvince)}`;
  const baseMunicipality = (settings && settings.header_municipality) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_municipality) || 'KITAOTAO';
  const headerMunicipality = `MUNICIPALITY OF ${baseMunicipality}`;
  const headerOffice = (settings && settings.header_office) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_office) || 'OFFICE OF THE MUNICIPAL ASSESSOR';
  const headerTitle = 'RECORD VERIFICATION DATA FORM';

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
                  <TableCell>{property.area_hectare ? property.area_hectare + (property.area_hectare <= 1 ? ' ha' : ' has') : ''}</TableCell>
                  <TableCell>{property.title_number || '—'}</TableCell>
                  <TableCell>
                    <Typography variant="body2" color="text.primary">
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
        <DialogTitle sx={{ textAlign: 'center' }}>
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
                <TableBody sx={{ '& td': { padding: '4px 8px' } }}>
                  <TableRow sx={{ '& td': { borderBottom: 'none' } }}>
                    <TableCell><strong>TAX DECLARATION NUMBER:</strong> {printHistory[0].tax_declaration_number}</TableCell>
                    <TableCell><strong>PIN:</strong> {printHistory[0].pin}</TableCell>
                  </TableRow>
                  <TableRow sx={{ '& td': { borderBottom: 'none' } }}>
                    <TableCell><strong>OWNER:</strong> {printHistory[0].declarant_name}</TableCell>
                    <TableCell><strong>ADDRESS:</strong> {printHistory[0].address}</TableCell>
                  </TableRow>
                  <TableRow sx={{ '& td': { borderBottom: 'none' } }}>
                    <TableCell><strong>LOCATION:</strong> {printHistory[0].location}</TableCell>
                    <TableCell><strong>ASSESSMENT DATE:</strong> {printHistory[0].assessment_date}</TableCell>
                  </TableRow>
                  <TableRow sx={{ '& td': { borderBottom: 'none' } }}>
                    <TableCell><strong>EFFECTIVITY:</strong> {printHistory[0].effectivity_date}</TableCell>
                    <TableCell><strong>KIND OF PROPERTY:</strong> {printHistory[0].kind_of_property}</TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell></TableCell>
                    <TableCell><strong>GEN. CLASS:</strong> {printHistory[0].gen_class}</TableCell>
                  </TableRow>
                </TableBody>
              </Table>
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
                <TableBody sx={{ '& td': { verticalAlign: 'top' } }}>
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
                      <TableCell>{item.area_hectare ? item.area_hectare + (item.area_hectare <= 1 ? ' ha' : ' has') : '—'}</TableCell>
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
        maxWidth="xl"
        fullWidth
        PaperProps={{ sx: { maxWidth: '60vw', height: '90vh' } }}
      >
        <DialogTitle sx={{ textAlign: 'center' }}>
          Tax Declaration History (Printable)
        </DialogTitle>
        <DialogContent>
          {printLoading ? (
            <Box display="flex" justifyContent="center" p={3}>
              <Typography>Loading history...</Typography>
            </Box>
          ) : printHistory.length > 0 ? (
            <TableContainer component={Paper}>
              <div className="print-header" style={{ textAlign: 'center', fontFamily: 'Times New Roman, sans-serif' }}>
                {appLogoUrl ? (
                  <img src={appLogoUrl} alt="Logo" style={{ height: 64, display: 'block', margin: '0 auto 8px auto' }} onError={(e) => { e.currentTarget.style.display = 'none'; }} />
                ) : null}
                <h4 style={{ fontSize: 16, margin: '-7px 0', fontWeight: 400 }}>{headerPh}</h4>
                <h4 style={{ fontSize: 16, margin: '-7px 0', fontWeight: 400 }}>{headerProvince}</h4>
                <h4 style={{ fontSize: 16, margin: '-7px 0', fontWeight: 400 }}>{headerMunicipality}</h4>
                <h3 style={{ fontSize: 16, margin: '-7px 0', fontWeight: 600}}>{headerOffice}</h3>
                <div style={{ marginTop: 8, marginBottom: 15, fontWeight: 700, textDecoration: 'underline', fontFamily: 'Tahoma, serif' }}>{headerTitle}</div>
              </div>
              <Table size="small" stickyHeader>
                <TableBody sx={{ '& td': { borderBottom: 'none', padding: '4px 12px' } }}>
                  <TableRow sx={{ '& td': { paddingTop: '12px' } }}>
                    <TableCell><strong>TAX DECLARATION NUMBER:</strong> {printHistory[0].tax_declaration_number}</TableCell>
                    <TableCell><strong>PIN:</strong> {printHistory[0].pin}</TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell><strong>OWNER:</strong> {printHistory[0].declarant_name}</TableCell>
                    <TableCell><strong>ADDRESS:</strong> {printHistory[0].address}</TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell><strong>LOCATION:</strong> {printHistory[0].location}</TableCell>
                    <TableCell><strong>ASSESSMENT DATE:</strong> {printHistory[0].assessment_date}</TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell><strong>EFFECTIVITY:</strong> {printHistory[0].effectivity_date}</TableCell>
                    <TableCell><strong>KIND OF PROPERTY:</strong> {printHistory[0].kind_of_property}</TableCell>
                  </TableRow>
                  <TableRow sx={{ '& td': { paddingBottom: '12px' } }}>
                    <TableCell></TableCell>
                    <TableCell><strong>GEN. CLASS:</strong> {printHistory[0].gen_class}</TableCell>
                  </TableRow>
                </TableBody>
              </Table>
              <Table size="small" stickyHeader>
                <colgroup>
                  <col style={{ width: '15%' }} />
                  <col style={{ width: '12%' }} />
                  <col style={{ width: '6%' }} />
                  <col style={{ width: '9%' }} />
                  <col style={{ width: '9%' }} />
                  <col style={{ width: '11%' }} />
                  <col style={{ width: '9%' }} />
                  <col style={{ width: '32%' }} />
                </colgroup>
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
                <TableBody sx={{ '& td': { verticalAlign: 'top' } }}>
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
                      <TableCell>{item.area_hectare ? item.area_hectare + (item.area_hectare <= 1 ? ' ha' : ' has') : '—'}</TableCell>
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
      {/* Hidden printable content for react-to-print */}
      <div style={{ position: 'fixed', left: '-10000px', top: 0 }}>
        <PrintableHistory ref={printRef} settings={settings} printHistory={printHistory} />
        <div className="print-page-footer"><span className="pageNumber" /></div>
      </div>
    </Box>
  );
};

export default PropertyTable;

