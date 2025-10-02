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
  Alert,
  Chip,
  FormControl,
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

// Format date function - accessible to both components
const formatDate = (dateString) => {
  if (!dateString) return '';
  try {
    const date = new Date(dateString);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = date.getHours();
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    const hours12 = hours % 12 || 12;
    
    return `${month}/${day}/${year} @ ${hours12}:${minutes} ${ampm}`;
  } catch {
    return dateString;
  }
};

// Format date only (mm/dd/yyyy)
const formatDateOnly = (dateString) => {
  if (!dateString) return '';
  try {
    const date = new Date(dateString);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    
    return `${month}/${day}/${year}`;
  } catch {
    return dateString;
  }
};

// Format date function - accessible to both components
const formatDateTable = (dateString) => {
  if (!dateString) return '';
  try {
    const date = new Date(dateString);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    
    return `${month}/${day}/${year}`;
  } catch {
    return dateString;
  }
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
        <div style={{ fontSize: 14, marginTop: 8, fontWeight: 700, textDecoration: 'underline', fontFamily: 'Tahoma, serif' }}>{headerTitle}</div>
      </div>

      <table style={{ border: '1px solid #000', borderCollapse: 'separate', borderSpacing: 0, margin: '12px auto', width: '100%', tableLayout: 'fixed' }} className="info">
        <colgroup>
          <col style={{ width: '50%' }} />
          <col style={{ width: '50%' }} />
        </colgroup>
        <tbody>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top', wordBreak: 'break-all', overflowWrap: 'anywhere' }}>
              <strong>TAX DECLARATION NUMBER:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].tax_declaration_number) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top', wordBreak: 'break-all', overflowWrap: 'anywhere' }}>
              <strong>PIN:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].pin) || ''}</span>
            </td>
          </tr>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>OWNER:</strong> <span>{sanitizeDeclarant(printHistory?.[0]?.declarant_name) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>ADDRESS:</strong>{' '}
              <span style={{ whiteSpace: 'normal', wordBreak: 'break-word', overflowWrap: 'anywhere' }}>
                {(printHistory && printHistory[0] && printHistory[0].address) || ''}
              </span>
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
               <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top', wordBreak: 'break-all', overflowWrap: 'anywhere' }}>
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
                  const unit = numHa <= 1 ? 'ha' : 'has';
                  currentArea = `${numHa.toFixed(4)} ${unit}`;
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
               <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top', wordBreak: 'break-all', overflowWrap: 'anywhere', hyphens: 'none' }}>{item.title_number || ''}</td>
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
                <div>{printHistory && printHistory[0] &&  printHistory[0].updated_by_name || ''}</div>
                <div>{printHistory && printHistory[0] && formatDate(printHistory[0].updated_at) || ''}</div>
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
                <div>{requestData?.date_issued ? formatDateOnly(requestData.date_issued) : ''}</div>
                <div>{requestData?.place_issued || ''}</div>
                <div>{requestData?.prepared_by ? `${requestData.prepared_by} ${requestData.updated_at ? formatDate(requestData.updated_at) : ''}` : ''}</div>
              </div>
            </div>
          </div>
          
          <div style={{ textAlign: 'center', width: '80mm' }}>
            <div style={{ height: '37mm' }} />
            <div style={{ paddingBottom: 4, fontSize: 14, fontWeight: 400, textAlign: 'left' }}>
              <div>{'Verified and checked by:'}</div>
            </div>
            <div style={{ borderBottom: '1px solid #000', paddingTop: 28, fontSize: 14, fontWeight: 600 }}>
              {(() => {
                const fullName = (printHistory && printHistory[0] && printHistory[0].verifier_signatory_name) || (settings && settings.verifier_signatory_name) || '';
                if (!fullName) return '';
                
                // Split by comma to separate main name from suffix
                const parts = fullName.split(',');
                const mainName = parts[0]?.trim() || '';
                const suffix = parts.length > 1 ? parts.slice(1).join(',').trim() : '';
                
                return (
                  <span>
                    {mainName}
                    {suffix ? <span style={{ fontSize: 13, fontWeight: 400 }}>{`, ${suffix}`}</span> : null}
                  </span>
                );
              })()}
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

// Purpose options for filter
const purposeOptions = [
  { value: 'record_verification', label: 'Record Verification' },
  { value: 'tax_declaration', label: 'Tax Declaration' },
  { value: 'property_assessment', label: 'Property Assessment' },
  { value: 'certification', label: 'Certification' },
  { value: 'other', label: 'Other' }
];

const RequestsTable = () => {
  const { isAdmin, isSuperAdmin } = useAuth();
  const [requests, setRequests] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(20);
  const [totalCount, setTotalCount] = useState(0);
  
  // Search state
  const [searchTerm, setSearchTerm] = useState('');
  
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
  
  // Print ref
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
      @page { size: A4 portrait; margin: 12mm 10mm 16mm 8mm; 
          @bottom-right {
            content: counter(page) "/" counter(pages);
            font-family: 'Arial', sans-serif;
            font-size: 10px;
            color: #666;
          }
      }
      @media print {
        /* Header fonts */
        .print-header h3, .print-header h4 { font-family: 'Times New Roman', Times, serif !important; }
        .print-header div[style*="font-family: 'Tahoma"] { font-family: Tahoma, Verdana, sans-serif !important; }
        /* Default app font */
        html, body, #root, * { font-family: 'Inter','Roboto','Helvetica','Arial',sans-serif; }
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
        /* Force word breaking for long strings without spaces */
        .history-table td:first-child { word-break: break-all; overflow-wrap: anywhere; }
        .history-table td:nth-child(5) { word-break: break-all; overflow-wrap: anywhere; }
        /* Ensure Title Number column breaks long text properly */
        .history-table td:nth-child(5) { word-break: break-all; overflow-wrap: anywhere; hyphens: none; }
        /* Ensure info header cells wrap properly for long addresses */
        .info td { white-space: normal !important; word-break: break-word !important; overflow-wrap: anywhere !important; }
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

  // Fetch requests
  const fetchSeqRef = useRef(0);
  const fetchRequests = async () => {
    const seq = ++fetchSeqRef.current;
    try {
      setLoading(true);
      setError('');
      
      const params = {
        page: page + 1,
        per_page: rowsPerPage,
        search: searchTerm || '',
        purpose: filters.purpose || '',
        date_issued: filters.dateIssued || '',
        prepared_by: filters.preparedBy || '',
        // Add cache busting timestamp to prevent browser caching
        _t: Date.now()
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
      if (seq === fetchSeqRef.current) setLoading(false);
      // setInitialLoad(false); // This line was not in the original file, so it's removed.
    }
  };

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

  useEffect(() => {
    fetchRequests();
  }, [page, rowsPerPage, searchTerm, filters]);

  // Handle search
  const handleSearch = (event) => {
    setSearchTerm(event.target.value.toUpperCase());
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

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      <Typography variant="h4" gutterBottom>
        Requests Management
      </Typography>
      <Typography variant="body1" color="text.secondary">
        Manage and view all payment requests and receipts
      </Typography>

      {/* Search and Actions */}
      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Grid container spacing={2} alignItems="center">
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                placeholder="Search by receipt number, client name, or prepared by..."
                value={searchTerm}
                onChange={handleSearch}
                helperText={`Total: ${totalCount} requests`}
                InputProps={{
                  startAdornment: <SearchIcon sx={{ mr: 1, color: 'text.secondary' }} />
                }}
              />
            </Grid>
            <Grid item xs={12} md={6} sx={{ textAlign: 'right' }}>
              <Box sx={{ display: 'flex', gap: 1, justifyContent: 'flex-end' }}>
                <Button
                  variant="outlined"
                  startIcon={<FilterListIcon />}
                  onClick={() => setFilterModal(true)}
                  color="primary"
                >
                  Filters
                </Button>
                <Button
                  variant="contained"
                  startIcon={<AddIcon />}
                  onClick={handleCreateRequest}
                  color="primary"
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
       <Paper sx={{ width: '100%', overflow: 'hidden' }}>
          <TableContainer sx={{ height: { xs: 'calc(100vh - 360px)', md: 'calc(100vh - 360px)' }, overflow: 'auto' }}>
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
              <TableBody>
                {loading ? (
                  <TableRow>
                    <TableCell colSpan={9} align="center">
                      <Typography>Loading requests...</Typography>
                    </TableCell>
                  </TableRow>
                  ) : requests.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={9} align="center">
                      <Typography color="text.secondary">
                        {searchTerm ? 'No requests found matching your search.' : 'No requests found.'}
                      </Typography>
                    </TableCell>
                  </TableRow>
                  ) : (
                  requests.map((request) => (
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
                            const hasNames = !!(request.declarant_last_name || request.declarant_first_name);
                            const declarant = hasNames
                              ? `${request.declarant_last_name || ''}${hasNames && request.declarant_first_name ? ', ' : ''}${request.declarant_first_name || ''}${request.declarant_middle_initial ? ` ${request.declarant_middle_initial}.` : ''}`
                              : '';
                            const business = request.business ? String(request.business).replace(/,\s*/g, ' ') : '';
                            if (declarant && business) return `${declarant} / ${business}`;
                            return declarant || business || '';
                          })()}
                        </Typography>
                        {request.tax_declaration_number && (
                          <Typography variant="caption" color="text.secondary" display="block">
                            TD: {request.tax_declaration_number}
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
                  ))
                )}
              </TableBody>
            </Table>
          </TableContainer>

        {/* Pagination */}
        <TablePagination
          component="div"
          count={totalCount}
          page={page}
          onPageChange={handleChangePage}
          rowsPerPage={rowsPerPage}
          onRowsPerPageChange={handleChangeRowsPerPage}
          rowsPerPageOptions={[10, 20, 50, 100]}
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
             <Box sx={{ display: 'flex', justifyContent: 'center', p: 3 }}>
               <Typography>Loading Request History...</Typography>
             </Box>
                      ) : (
              <Box sx={{ maxHeight: '70vh', overflow: 'auto', width: '100%' }}>
                <PrintableHistory ref={printRef} settings={settings} printHistory={printHistory} requestData={printRequestData} />
              </Box>
            )}
         </DialogContent>
        <DialogActions>
          <Button onClick={() => setPrintModal(false)}>Close</Button>
          <Button
            onClick={handlePrint}
            variant="contained"
            startIcon={<PrintIcon />}
          >
            Print
          </Button>
        </DialogActions>
      </Dialog>

             {/* Hidden printable content for react-to-print */}
       <div style={{ position: 'fixed', left: '-10000px', top: 0 }}>
         <PrintableHistory ref={printRef} settings={settings} printHistory={printHistory} requestData={printRequestData} />
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
