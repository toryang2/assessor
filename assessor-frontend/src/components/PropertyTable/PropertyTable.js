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
  Visibility as VisibilityIcon,
  Print as PrintIcon,
  Receipt as ReceiptIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';
 
// We'll load html2pdf.js from CDN at runtime to avoid webpack sourcemap warnings

import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';
import { statusColors } from '../../theme/theme';
import PropertyFormModal from '../PropertyFormModal/PropertyFormModal';
import { useReactToPrint } from 'react-to-print';

// Helper function to sanitize declarant names by removing leading/trailing commas
const sanitizeDeclarant = (name) => {
  if (!name) return '';
  const s = String(name).trim();
  if (!s) return '';
  let out = s.replace(/\s*,\s*/g, ', ').replace(/^,\s*|\s*,\s*$/g, '').trim();
  if (out === ',') out = '';
  return out;
};

// Helper function to sanitize business names by removing leading/trailing commas
const sanitizeBusinessName = (name) => {
  if (!name) return '';
  const s = String(name).trim();
  if (!s) return '';
  let out = s.replace(/\s*,\s*/g, ', ').replace(/^,\s*|\s*,\s*$/g, '').trim();
  if (out === ',') out = '';
  return out;
};

const PrintableHistory = forwardRef(({ settings, printHistory, requestData }, ref) => {
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
          <img src={appLogoUrl} alt="Logo" style={{ height: 64, display: 'block', margin: '5mm auto 8px auto' }} onError={(e) => { e.currentTarget.style.display = 'none'; }} />
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
              <strong>OWNER:</strong> <span>{sanitizeDeclarant(printHistory?.[0]?.declarant_name) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>ADDRESS:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].address) || ''}</span>
            </td>
          </tr>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
            <strong>BUSINESS NAME:</strong> <span>{sanitizeBusinessName(printHistory?.[0]?.business_name) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>ASSESSMENT DATE:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].assessment_date) || ''}</span>
            </td>
          </tr>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
            <strong>LOCATION:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].location) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>KIND OF PROPERTY:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].kind_of_property) || ''}</span>
            </td>
          </tr>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
            <strong>EFFECTIVITY DATE:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].effectivity_date) || ''}</span>
            </td>
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
              <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>{(() => {
                const d = sanitizeDeclarant(item.declarant_name);
                const b = item.business_name ? String(item.business_name).replace(/,\s*/g, ' ') : '';
                return d && b ? `${d} / ${b}` : (d || b || '');
              })()}</td>
              <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>{item.lot_number || ''}</td>
              <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>{(() => {
                const haRaw = item.area_hectare;
                const sqmRaw = item.area_sqm;
                const oldHaRaw = item.area_hectare_old;
                const numHa = Number(haRaw);
                const numSqm = Number(sqmRaw);
                const hasHa = haRaw !== undefined && haRaw !== null && haRaw !== '' && !isNaN(numHa) && numHa > 0;
                const hasSqm = sqmRaw !== undefined && sqmRaw !== null && sqmRaw !== '' && !isNaN(numSqm) && numSqm > 0;
                const hasOldHa = oldHaRaw && oldHaRaw !== '';
                
                if (!hasHa && !hasSqm && !hasOldHa) return '';
                
                let currentArea = '';
                if (hasHa) {
                  currentArea = `${numHa.toFixed(4)} ha`;
                } else if (hasSqm) {
                  currentArea = `${numSqm.toFixed(2)} sqm`;
                }
                
                if (hasOldHa && currentArea) {
                  return `${currentArea} (Old: ${oldHaRaw})`;
                } else if (hasOldHa) {
                  return oldHaRaw;
                } else {
                  return currentArea || '';
                }
              })()}</td>
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
      <div className="print-signature" style={{ fontFamily: 'Arial, sans serif', width: '100%', marginTop: '0mm', marginBottom: '0mm', paddingTop: '0mm', paddingBottom: '0mm', paddingRight: '10mm', paddingLeft: '10mm' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between' }}>

          <div style={{ textAlign: 'left', width: '100mm' }}>
            {/* Spacer */}
            <div style={{ height: '37mm' }} />

            {/* Encoded Info (side by side) */}
            <div style={{ display: 'flex', flexDirection: 'row', marginBottom: 4 }}>
              {/* Labels */}
              <div style={{ width: '25mm', fontSize: 12, fontWeight: 400 }}>
                <div>{'Encoded by:'}</div>
                <div>{'Date and Time:'}</div>
              </div>

              {/* Values */}
              <div style={{ fontSize: 12, fontWeight: 400 }}>
                <div>{(printHistory && printHistory[0] && (printHistory[0].created_by_name || printHistory[0].updated_by_name)) || ''}</div>
                <div>{(() => {
                  const dt = (printHistory && printHistory[0] && printHistory[0].created_at) || '';
                  if (!dt) return '';
                  try {
                    const d = new Date(dt);
                    if (isNaN(d.getTime())) return String(dt);
                    const pad = (n) => String(n).padStart(2, '0');
                    const yyyy = d.getFullYear();
                    const mm = pad(d.getMonth() + 1);
                    const dd = pad(d.getDate());
                    let hours = d.getHours();
                    const minutes = pad(d.getMinutes());
                    const ampm = hours >= 12 ? 'PM' : 'AM';
                    hours = hours % 12;
                    hours = hours ? hours : 12; // 0 -> 12
                    const hh12 = pad(hours);
                    return `${yyyy}/${mm}/${dd} ${hh12}:${minutes} ${ampm}`;
                  } catch (_) {
                    return String(dt);
                  }
                })()}</div>
              </div>
            </div>

            {/* Receipt Info (side by side) */}
            <div style={{ display: 'flex', flexDirection: 'row', marginTop: 4 }}>
              {/* Labels */}
              <div style={{ width: '19mm', fontSize: 10, fontWeight: 400 }}>
                <div style={{ paddingTop: 50 }}>
                  {'Amount Paid: '}
                </div>
                <div>{'Receipt No.: '}</div>
                <div>{'Date Issued: '}</div>
                <div>{'Place Issued: '}</div>
                <div>{'Prepared by: '}</div>
              </div>

              {/* Data Values */}
              <div style={{ fontSize: 10, fontWeight: 400 }}>
                <div style={{ paddingTop: 50 }}>{requestData?.amount_paid ? `₱${requestData.amount_paid.toLocaleString()}` : '₱'}</div>
                <div>{requestData?.receipt_number || ''}</div>
                <div>{requestData?.date_issued ? new Date(requestData.date_issued).toLocaleDateString('en-CA') : ''}</div>
                <div>{requestData?.place_issued || ''}</div>
                <div>{requestData?.prepared_by || ''}</div>
              </div>
            </div>
          </div>
          
          <div style={{ textAlign: 'center', width: '80mm' }}>
            <div style={{ height: '18mm' }} />
            <div style={{ paddingBottom: 4, fontSize: 14, fontWeight: 400, textAlign: 'left' }}>
              <div>{'Verified and checked by:'}</div>
            </div>
            <div style={{ borderBottom: '1px solid #000', paddingTop: 28, fontSize: 14, fontWeight: 600 }}>
              {(printHistory && printHistory[0] && printHistory[0].verifier_signatory_name) || (settings && settings.verifier_signatory_name) || ''}
            </div>
            <div style={{ fontSize: 11, marginBottom: 30 }}>
              {(printHistory && printHistory[0] && printHistory[0].verifier_signatory_title) || (settings && settings.verifier_signatory_title) || 'VERIFIER'}
            </div>
            <div style={{ paddingBottom: 4, fontSize: 14, fontWeight: 400, textAlign: 'left' }}>
              <div>{'Certified correct as to available record/s:'}</div>
            </div>
            <div style={{ borderBottom: '1px solid #000', paddingTop: 28, fontSize: 14, fontWeight: 600 }}>
              {(() => {
                const name = (printHistory && printHistory[0] && printHistory[0].municipal_assessor_name) || (settings && settings.municipal_assessor_name);
                const suffix = (printHistory && printHistory[0] && printHistory[0].municipal_assessor_suffix) || (settings && settings.municipal_assessor_suffix);
                const base = (name || '');
                return (
                  <span>
                    {base}
                    {suffix ? <span style={{ fontSize: 13, fontWeight: 400 }}>{`, ${suffix}`}</span> : null}
                  </span>
                );
              })()}
            </div>
            <div style={{ fontSize: 11, paddingTop: 0 }}>
              {(printHistory && printHistory[0] && printHistory[0].municipal_assessor_title) || (settings && settings.municipal_assessor_title) || 'MUNICIPAL ASSESSOR'}
            </div>
            <div style={{ fontSize: 10 }}>
              {(() => {
                const lic = (printHistory && printHistory[0] && printHistory[0].municipal_assessor_license) || (settings && settings.municipal_assessor_license) || '';
                return lic ? `License No.: ${lic}` : '';
              })()}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
});

const PropertyTable = () => {
  const { isAdmin, isSuperAdmin } = useAuth();
  const [properties, setProperties] = useState([]);
  const [loading, setLoading] = useState(true);
  const [initialLoad, setInitialLoad] = useState(true);
  const [error, setError] = useState('');
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(50);
  const [totalCount, setTotalCount] = useState(0);
  
  // Safety check - ensure properties is always an array
  const safeProperties = properties || [];
  
  // Search state
  const [searchTerm, setSearchTerm] = useState('');
  
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
  const [printDocuments, setPrintDocuments] = useState([]);
  const [printLoading, setPrintLoading] = useState(false);
  const [printDocPreview, setPrintDocPreview] = useState({ open: false, src: '', filename: '', type: '' });
  const [printRequestData, setPrintRequestData] = useState(null);
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
  }, [page, rowsPerPage, searchTerm]);

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

  const fetchSeqRef = useRef(0);
  const fetchProperties = async (forceRefresh = false) => {
    const seq = ++fetchSeqRef.current;
    try {
      setLoading(true);
      setError(''); // Clear previous errors
      
      const params = {
        page: page + 1,
        per_page: rowsPerPage,
        q: searchTerm || '',
        // Add cache busting timestamp to prevent browser caching
        _t: forceRefresh ? Date.now() : Date.now()
      };
      
      const response = await apiService.getProperties(params);
      // Ignore if a newer request has started
      if (seq !== fetchSeqRef.current) return;
      
      if (response && response.properties) {
        setProperties(response.properties);
        setTotalCount(response.pagination ? response.pagination.total : response.properties.length);
      } else if (response && response.data) {
        // Fallback for different response format
        setProperties(response.data);
        setTotalCount(response.total || response.data.length);
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
      // Only clear loading for the latest request
      if (seq === fetchSeqRef.current) setLoading(false);
      setInitialLoad(false);
    }
  };

  const handleSearch = (event) => {
    const raw = event.target.value.toUpperCase() || '';
    const normalized = raw
      .replace(/[\u2013\u2014]/g, '-') // en/em dash to hyphen
      .replace(/\s*-\s*/g, '-')        // collapse spaces around hyphen
      .toUpperCase();
    setSearchTerm(normalized);
    setPage(0);
  };

  const handleSearchKeyDown = (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      const list = safeProperties || [];
      if (!list.length) return;
      // Prefer exact TDN match on current page; otherwise open the first visible row
      const exact = list.find(p => String(p.tax_declaration_number || '').toUpperCase() === String(searchTerm || '').toUpperCase());
      const target = exact || list[0];
      if (target && target.tax_declaration_number) handleViewPrintableHistory(target.tax_declaration_number);
    }
  };

  // No additional filters
  const handleFilterChange = () => {};

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
      // Load documents for the current (latest) property for preview
      try {
        const current = Array.isArray(response) && response.length > 0 ? response[0] : null;
        const propertyId = current && current.id;
        if (propertyId) {
          const docsRes = await apiService.getPropertyDocuments(propertyId);
          const docs = (docsRes && docsRes.documents) ? docsRes.documents : (Array.isArray(docsRes) ? docsRes : []);
          // Add legacy URLs from supporting_documents_old and supporting_documents in current record
          const current = Array.isArray(response) && response.length > 0 ? response[0] : null;
          const legacySources = [current?.supporting_documents_old, current?.supporting_documents]
            .filter(Boolean)
            .map(String)
            .join(' | ');
          const legacy = (legacySources
            ? legacySources.split(/\||,/).map(s => String(s).trim()).filter(s => s && /^https?:\/\//i.test(s))
            : [])
            .map((url, idx) => {
              const path = url.split('?')[0];
              const ext = (path.split('.').pop() || '').toLowerCase();
              const name = decodeURIComponent(path.substring(path.lastIndexOf('/') + 1));
              return { id: `legacy-${idx}`, file_url: url, file_type: ext, original_filename: name, filename: name, description: 'Legacy document' };
            });
          setPrintDocuments([ ...docs, ...legacy ]);
        } else {
          setPrintDocuments([]);
        }
      } catch (e) {
        console.error('Error fetching property documents:', e);
        setPrintDocuments([]);
      }
    } catch (err) {
      console.error('Error fetching printable history:', err);
      setPrintHistory([]);
      setPrintDocuments([]);
    } finally {
      setPrintLoading(false);
    }
  };

  // removed html2pdf
  const printRef = useRef(null);
  const handlePrint = useReactToPrint({
    contentRef: printRef,
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
        const bottomMarginPx = 0 * pxPerMm; // 16 Default Change to 1 if super low
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
        @page :first {
          margin-top: 5mm;
        }
        @page {
          margin-top: 5mm;
          padding-top: 5mm;
        }
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
    setSearchTerm('');
    setPage(0);
  };

  if (initialLoad && loading && (!safeProperties || safeProperties.length === 0)) {
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
              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Search Properties"
                  size='small'
                  value={searchTerm}
                  onChange={handleSearch}
                  onKeyDown={handleSearchKeyDown}
                  placeholder="Search by TDN, name, lot number, or title number..."
                  helperText="Search by Tax Declaration Number, Declarant Last Name, Declarant First Name, Lot Number, or Title Number"
                  InputProps={{ 
                    startAdornment: <SearchIcon sx={{ mr: 1, color: 'text.secondary' }} />
                  }}
                />
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
        <TableContainer sx={{ height: { xs: 'calc(100vh - 360px)', md: 'calc(100vh - 320px)' }, overflow: 'auto' }}>
          <Table stickyHeader sx={{ tableLayout: 'fixed' }}>
            <colgroup>
              <col style={{ width: '200px' }} />
              <col style={{ width: '200px' }} />
              <col style={{ width: '120px' }} />
              <col style={{ width: '120px' }} />
              <col style={{ width: '120px' }} />
              <col style={{ width: '150px' }} />
              <col style={{ width: '120px' }} />
              <col />
              <col style={{ width: '140px' }} />
            </colgroup>
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
                    {(() => {
                      const hasNames = !!(property.declarant_last_name || property.declarant_first_name);
                      const declarant = hasNames
                        ? `${property.declarant_last_name || ''}${hasNames && property.declarant_first_name ? ', ' : ''}${property.declarant_first_name || ''}${property.declarant_middle_initial ? ` ${property.declarant_middle_initial}.` : ''}`
                        : '';
                      const business = property.business_name ? String(property.business_name).replace(/,\s*/g, ' ') : '';
                      if (declarant && business) return `${declarant} / ${business}`;
                      return declarant || business || '—';
                    })()}
                  </TableCell>
                  <TableCell>{property.lot_number || '—'}</TableCell>
                  <TableCell>{(() => {
                    const haRaw = property.area_hectare;
                    const sqmRaw = property.area_sqm;
                    const oldHaRaw = property.area_hectare_old;
                    const numHa = Number(haRaw);
                    const numSqm = Number(sqmRaw);
                    const hasHa = haRaw !== undefined && haRaw !== null && haRaw !== '' && !isNaN(numHa) && numHa > 0;
                    const hasSqm = sqmRaw !== undefined && sqmRaw !== null && sqmRaw !== '' && !isNaN(numSqm) && numSqm > 0;
                    const hasOldHa = oldHaRaw && oldHaRaw !== '';
                    
                    if (!hasHa && !hasSqm && !hasOldHa) return '—';
                    
                    let currentArea = '';
                    if (hasHa) {
                      currentArea = `${numHa.toFixed(4)} ha`;
                    } else if (hasSqm) {
                      currentArea = `${numSqm.toFixed(2)} sqm`;
                    }
                    
                    if (hasOldHa && currentArea) {
                      return `${currentArea} (Old: ${oldHaRaw})`;
                    } else if (hasOldHa) {
                      return oldHaRaw;
                    } else {
                      return currentArea || '—';
                    }
                  })()}</TableCell>
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
                      {(isAdmin || isSuperAdmin) && (
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
          rowsPerPageOptions={[10, 25, 50, 75, 100]}
          component="div"
          count={totalCount}
          rowsPerPage={rowsPerPage}
          page={page}
          onPageChange={handlePageChange}
          onRowsPerPageChange={handleRowsPerPageChange}
        />
      </Paper>

             {/* Property Form Modal */}
       <PropertyFormModal
         property={selectedProperty}
         onSave={handlePropertySaved}
         onCancel={() => setPropertyModal(false)}
         open={propertyModal}
       />


      {/* Preview: Document Viewer */}
      <Dialog open={printDocPreview.open} onClose={() => setPrintDocPreview({ open: false, src: '', filename: '', type: '' })} maxWidth="md" fullWidth>
        <DialogTitle>{printDocPreview.filename}</DialogTitle>
        <DialogContent>
          {(() => {
            const t = (printDocPreview.type || '').toLowerCase();
            if (['jpg','jpeg','png','gif'].includes(t)) {
              return (
                <img src={printDocPreview.src} alt={printDocPreview.filename} style={{ width: '100%', height: 'auto' }} />
              );
            }
            if (t === 'pdf') {
              return (
                <iframe src={printDocPreview.src} title={printDocPreview.filename} style={{ width: '100%', height: '80vh', border: 'none' }} />
              );
            }
            // Fallback: render link
            return (
              <Button variant="outlined" onClick={() => window.open(printDocPreview.src, '_blank')}>Open File</Button>
            );
          })()}
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
        maxWidth="lg"
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
                    <TableCell><strong>TAX DECLARATION NUMBER:</strong> {taxHistory[0]?.tax_declaration_number}</TableCell>
                    <TableCell><strong>PIN:</strong> {taxHistory[0]?.pin}</TableCell>
                  </TableRow>
                  <TableRow sx={{ '& td': { borderBottom: 'none' } }}>
                    <TableCell><strong>OWNER:</strong> {sanitizeDeclarant(taxHistory[0]?.declarant_name) || ''}</TableCell>
                    <TableCell><strong>ADDRESS:</strong> {taxHistory[0]?.address}</TableCell>
                  </TableRow>
                  <TableRow sx={{ '& td': { borderBottom: 'none' } }}>
                    <TableCell><strong>BUSINESS NAME:</strong> {sanitizeBusinessName(taxHistory[0]?.business_name) || ''}</TableCell>
                    <TableCell><strong>ASSESSMENT DATE:</strong> {taxHistory[0]?.assessment_date}</TableCell>
                  </TableRow>
                  <TableRow sx={{ '& td': { borderBottom: 'none' } }}>
                  <TableCell><strong>LOCATION:</strong> {taxHistory[0]?.location}</TableCell>
                    <TableCell><strong>KIND OF PROPERTY:</strong> {taxHistory[0]?.kind_of_property}</TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell><strong>EFFECTIVITY:</strong> {taxHistory[0]?.effectivity_date}</TableCell>
                    <TableCell><strong>GEN. CLASS:</strong> {taxHistory[0]?.gen_class}</TableCell>
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
                      <TableCell>{(() => {
                        const d = sanitizeDeclarant(item.declarant_name);
                        const b = item.business_name ? String(item.business_name).replace(/,\s*/g, ' ') : '';
                        return d && b ? `${d} / ${b}` : (d || b || '—');
                      })()}</TableCell>
                      <TableCell>{item.lot_number || '—'}</TableCell>
                      <TableCell>{(() => {
                        const haRaw = item.area_hectare;
                        const sqmRaw = item.area_sqm;
                        const oldHaRaw = item.area_hectare_old;
                        const numHa = Number(haRaw);
                        const numSqm = Number(sqmRaw);
                        const hasHa = haRaw !== undefined && haRaw !== null && haRaw !== '' && !isNaN(numHa) && numHa > 0;
                        const hasSqm = sqmRaw !== undefined && sqmRaw !== null && sqmRaw !== '' && !isNaN(numSqm) && numSqm > 0;
                        const hasOldHa = oldHaRaw && oldHaRaw !== '';
                        
                        if (!hasHa && !hasSqm && !hasOldHa) return '—';
                        
                        let currentArea = '';
                        if (hasHa) {
                          currentArea = `${numHa.toFixed(4)} ha`;
                        } else if (hasSqm) {
                          currentArea = `${numSqm.toFixed(2)} sqm`;
                        }
                        
                        if (hasOldHa && currentArea) {
                          return `${currentArea} (Old: ${oldHaRaw})`;
                        } else if (hasOldHa) {
                          return oldHaRaw;
                        } else {
                          return currentArea || '—';
                        }
                      })()}</TableCell>
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
        <DialogContent sx={{ position: 'relative', display: 'flex', flexDirection: 'column', height: '100%' }}>
          {printLoading ? (
            <Box display="flex" justifyContent="center" p={3}>
              <Typography>Loading history...</Typography>
            </Box>
          ) : printHistory.length > 0 ? (
            <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%', overflow: 'hidden' }}>
            <Box sx={{ flex: 1, overflow: 'auto' }}>
            <TableContainer component={Paper} sx={{ paddingBottom: Array.isArray(printDocuments) && printDocuments.length > 0 ? '200px' : '0px' }}>
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
                    <TableCell><strong>OWNER:</strong> {sanitizeDeclarant(printHistory[0].declarant_name) || ''}</TableCell>
                    <TableCell><strong>ADDRESS:</strong> {printHistory[0].address}</TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell><strong>BUSINESS NAME:</strong> {sanitizeBusinessName(printHistory[0].business_name) || ''}</TableCell>
                    <TableCell><strong>ASSESSMENT DATE:</strong> {printHistory[0].assessment_date}</TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell><strong>LOCATION:</strong> {printHistory[0].location}</TableCell>
                    <TableCell><strong>KIND OF PROPERTY:</strong> {printHistory[0].kind_of_property}</TableCell>
                  </TableRow>
                  <TableRow sx={{ '& td': { paddingBottom: '12px' } }}>
                    <TableCell><strong>EFFECTIVITY:</strong> {printHistory[0].effectivity_date}</TableCell>
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
                  <col style={{ width: '28%' }} />
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
                      <TableCell>{(() => {
                        const d = sanitizeDeclarant(item.declarant_name);
                        const b = item.business_name ? String(item.business_name).replace(/,\s*/g, ' ') : '';
                        return d && b ? `${d} / ${b}` : (d || b || '—');
                      })()}</TableCell>
                      <TableCell>{item.lot_number || '—'}</TableCell>
                      <TableCell>{(() => {
                        const haRaw = item.area_hectare;
                        const sqmRaw = item.area_sqm;
                        const oldHaRaw = item.area_hectare_old;
                        const numHa = Number(haRaw);
                        const numSqm = Number(sqmRaw);
                        const hasHa = haRaw !== undefined && haRaw !== null && haRaw !== '' && !isNaN(numHa) && numHa > 0;
                        const hasSqm = sqmRaw !== undefined && sqmRaw !== null && sqmRaw !== '' && !isNaN(numSqm) && numSqm > 0;
                        const hasOldHa = oldHaRaw && oldHaRaw !== '';
                        
                        if (!hasHa && !hasSqm && !hasOldHa) return '—';
                        
                        let currentArea = '';
                        if (hasHa) {
                          currentArea = `${numHa.toFixed(4)} ha`;
                        } else if (hasSqm) {
                          currentArea = `${numSqm.toFixed(2)} sqm`;
                        }
                        
                        if (hasOldHa && currentArea) {
                          return `${currentArea} (Old: ${oldHaRaw})`;
                        } else if (hasOldHa) {
                          return oldHaRaw;
                        } else {
                          return currentArea || '—';
                        }
                      })()}</TableCell>
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
            </Box>
            {/* Attached Documents Section (Preview Only, sticky at bottom; reserved space above to avoid overlap) */}
            {Array.isArray(printDocuments) && printDocuments.length > 0 && (
              <Box sx={{ 
                p: 1, 
                position: 'sticky', 
                bottom: 0, 
                backgroundColor: 'background.paper', 
                borderTop: '1px solid #eee',
                zIndex: 1,
                marginTop: 'auto'
              }}>
                <Typography variant="caption" sx={{ fontWeight: 700, mb: 0.5 }}>
                  Attached Documents
                </Typography>
                {(() => {
                  const isLegacy = (d) => String(d?.id || '').startsWith('legacy-') || String(d?.description || '') === 'Legacy document';
                  const managedDocs = (printDocuments || []).filter(d => !isLegacy(d));
                  const legacyDocs = (printDocuments || []).filter(d => isLegacy(d));
                  const renderLinks = (docs) => (
                    <Box sx={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 0.5 }}>
                      {docs.map((doc) => {
                        const ext = String(doc.file_type || '').toLowerCase();
                        return (
                          <Box key={doc.id} sx={{ display: 'inline-flex', alignItems: 'center' }}>
                            <Button 
                              size="small" 
                              variant="text"
                              onClick={() => setPrintDocPreview({ open: true, src: doc.file_url, filename: doc.original_filename || doc.filename, type: ext })}
                              sx={{ 
                                minWidth: 0,
                                p: 0.25,
                                fontSize: '0.75rem',
                                textTransform: 'none'
                              }}
                            >
                              {doc.original_filename || doc.filename}
                            </Button>
                          </Box>
                        );
                      })}
                    </Box>
                  );
                  return (
                    <>
                      {renderLinks(managedDocs)}
                      {legacyDocs.length > 0 && (
                        <>
                          <Typography variant="caption" color="text.secondary" sx={{ mt: 0.5, display: 'block' }}>
                            Legacy documents
                          </Typography>
                          {renderLinks(legacyDocs)}
                        </>
                      )}
                    </>
                  );
                })()}
              </Box>
            )}
            </Box>
          ) : (
            <Typography>No history found for this tax declaration number.</Typography>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setPrintModal(false)}>Close</Button>
          <Button onClick={handlePrint} startIcon={<PrintIcon />} variant="contained" disabled={!printHistory.length || !printRef.current}>
            Print
          </Button>
        </DialogActions>
      </Dialog>
      {/* Hidden printable content for react-to-print */}
      <div style={{ position: 'fixed', left: '-10000px', top: 0 }}>
        <PrintableHistory ref={printRef} settings={settings} printHistory={printHistory} requestData={printRequestData} />
        <div className="print-page-footer"><span className="pageNumber" /></div>
      </div>
    </Box>
  );
};

export default PropertyTable;
