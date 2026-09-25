import React, { useState, useEffect, useMemo } from 'react';
import { createPortal } from 'react-dom';
import {
  Printer,
  FileText,
  Image as ImageIcon,
  Paperclip,
  Eye,
  ExternalLink,
  ChevronRight,
  AlertTriangle,
  GitBranch,
  Calendar,
  User,
  ShieldCheck,
  CheckCircle2,
  XCircle,
  Clock,
  Layers,
  Trash2
} from 'lucide-react';
import {
  Close as CloseIcon,
  BrokenImage as BrokenImageIcon
} from '@mui/icons-material';
import { apiService } from '../../utils/api';

/**
 * Format declarant from discrete fields; add dot only for single-character middle
 */
const formatDeclarantFromParts = (last, first, middle) => {
  const hasNames = !!(last || first);
  if (!hasNames) return '';
  const raw = (middle || '').trim();
  const mi = raw.replace(/\./g, '');
  const middleFormatted = mi ? (mi.length === 1 ? ` ${mi}.` : ` ${mi}`) : '';
  return `${last || ''}${hasNames && first ? ', ' : ''}${first || ''}${middleFormatted}`.trim();
};

/**
 * Adjust a combined declarant name string ("LAST, FIRST MI" or "LAST, FIRST MI.")
 */
const normalizeDeclarantString = (name) => {
  if (!name) return '';
  const s = String(name).trim().replace(/\s*,\s*/g, ', ').replace(/^,\s*|\s*,\s*$/g, '').trim();
  if (!s || s === ',') return '';

  const parts = s.split(',');
  if (parts.length < 2) return s;

  const last = parts[0].trim();
  const rest = parts.slice(1).join(',').trim();
  if (!rest) return `${last}`;

  const restParts = rest.split(/\s+/);
  const meaningfulParts = restParts.filter(part => part.length > 0);

  if (meaningfulParts.length === 0) return `${last}`;
  if (meaningfulParts.length === 1) return `${last}, ${rest}`;

  const first = meaningfulParts[meaningfulParts.length - 2];
  const middleRaw = meaningfulParts[meaningfulParts.length - 1];

  const middleNoDots = middleRaw.replace(/\./g, '');
  const middleFormatted = middleNoDots.length === 1 ? `${middleNoDots}.` : middleNoDots;

  const beforeFirst = meaningfulParts.slice(0, -2).join(' ');
  return `${last}, ${beforeFirst ? beforeFirst + ' ' : ''}${first} ${middleFormatted}`.trim();
};

/**
 * Helper to sanitize business name
 */
const sanitizeBusinessName = (name) => {
  if (!name) return '';
  const s = String(name).trim();
  if (!s) return '';
  let out = s.replace(/\s*,\s*/g, ' ').replace(/^,\s*|\s*,\s*$/g, '').trim();
  return out;
};

/**
 * Helper to format effectivity according to whole-year / EXEMPT rules
 */
const formatEffectivityDisplay = (item) => {
  if (!item) return '—';
  const isExempt =
    item.effectivity_exempt === true ||
    item.effectivity_exempt === 1 ||
    item.effectivity_exempt === '1' ||
    /^exempt$/i.test(String(item.effectivity_date ?? '').trim());

  if (isExempt) {
    return 'EXEMPT';
  }

  const raw = String(item.effectivity_date ?? '').trim();
  if (raw === '') {
    return '—';
  }

  return raw;
};

/**
 * Helper to format area display
 */
const formatAreaDisplay = (item) => {
  if (!item) return '—';
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
    const unit = numHa <= 1 ? 'ha' : 'has';
    currentArea = `${numHa.toFixed(4)} ${unit}`;
  } else if (hasSqm) {
    currentArea = `${numSqm.toFixed(2)} sqm`;
  }

  if (hasOldHa && currentArea) {
    return `${currentArea} ${oldHaRaw}`;
  } else if (hasOldHa) {
    return oldHaRaw;
  } else {
    return currentArea || '—';
  }
};

/**
 * Helper to format assessed value
 */
const formatAssessedValue = (item) => {
  if (!item) return '₱0.00';
  const currentValue = item.assessed_value;
  const oldValue = item.assessed_value_old;
  const hasCurrent = currentValue !== undefined && currentValue !== null && currentValue !== '';
  const hasOld = oldValue && oldValue !== '';

  if (!hasCurrent && !hasOld) return '₱0.00';

  let displayValue = '';
  if (hasCurrent && !isNaN(Number(currentValue))) {
    displayValue = `₱${Number(currentValue).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  } else if (hasCurrent) {
    displayValue = String(currentValue);
  }

  if (hasOld && displayValue) {
    return `${displayValue} ${oldValue}`;
  } else if (hasOld) {
    return String(oldValue);
  } else {
    return displayValue || '₱0.00';
  }
};

/**
 * Helper to format market value
 */
const formatMarketValue = (item) => {
  if (!item) return '—';
  const val = item.market_value;
  if (val === undefined || val === null || val === '') return '—';
  const num = Number(val);
  if (!isNaN(num)) {
    return `₱${num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }
  return String(val);
};

const formatBytes = (bytes) => {
  if (!bytes || isNaN(Number(bytes))) return null;
  const num = Number(bytes);
  if (num < 1024) return `${num} B`;
  if (num < 1024 * 1024) return `${(num / 1024).toFixed(1)} KB`;
  return `${(num / (1024 * 1024)).toFixed(1)} MB`;
};

const PropertyDossierModal = ({
  open,
  onClose,
  property,
  settings,
  currentTab = 'overview',
  onTabChange,
  onOpenPrint,
  onSelectProperty,
  onPreviewImage,
  canEdit = false,
  isAdmin = false,
  allProperties = []
}) => {
  const [internalTab, setInternalTab] = useState('overview');
  const [documents, setDocuments] = useState([]);
  const [loadingDocs, setLoadingDocs] = useState(false);
  const [lineageList, setLineageList] = useState([]);
  const [loadingLineage, setLoadingLineage] = useState(false);

  // Sync tab with parent if provided
  const activeTab = onTabChange ? currentTab : internalTab;
  const handleTabClick = (tabId) => {
    if (onTabChange) {
      onTabChange(tabId);
    } else {
      setInternalTab(tabId);
    }
  };

  // Prevent background scrolling while dossier is open
  useEffect(() => {
    if (!open) return;

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = previousOverflow;
    };
  }, [open]);

  // Close on Escape key
  useEffect(() => {
    if (!open) return;
    const handleKeyDown = (e) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [open, onClose]);

  // Load documents and lineage whenever open and property change
  useEffect(() => {
    if (!open || !property) {
      setDocuments([]);
      setLineageList([]);
      return;
    }

    let isMounted = true;

    // Fetch documents
    const fetchDocs = async () => {
      try {
        setLoadingDocs(true);
        const propertyId = property.id;
        let docs = [];

        if (propertyId) {
          try {
            const docsRes = await apiService.getPropertyDocuments(propertyId);
            docs = (docsRes && docsRes.documents) ? docsRes.documents : (Array.isArray(docsRes) ? docsRes : []);
          } catch (e) {
            console.error('Error fetching property documents in dossier:', e);
          }
        }

        const legacySources = [property.supporting_documents_old, property.supporting_documents]
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
            return {
              id: `legacy-${idx}`,
              file_url: url,
              file_type: ext,
              original_filename: name,
              filename: name,
              description: 'Legacy document',
              is_legacy: true
            };
          });

        if (isMounted) {
          setDocuments([...docs, ...legacy]);
        }
      } catch (err) {
        console.error('Error compiling dossier documents:', err);
        if (isMounted) setDocuments([]);
      } finally {
        if (isMounted) setLoadingDocs(false);
      }
    };

    // Fetch lineage/history (if TD number available)
    const fetchLineage = async () => {
      try {
        setLoadingLineage(true);
        const tdn = property.tax_declaration_number;
        if (tdn) {
          const res = await apiService.getTaxDeclarationHistory(tdn);
          if (isMounted) {
            setLineageList(Array.isArray(res) ? res : []);
          }
        }
      } catch (e) {
        console.error('Error fetching lineage for dossier:', e);
        if (isMounted) setLineageList([]);
      } finally {
        if (isMounted) setLoadingLineage(false);
      }
    };

    fetchDocs();
    fetchLineage();

    return () => {
      isMounted = false;
    };
  }, [open, property]);

  // Handle document deletion (if permitted)
  const handleDeleteDoc = async (docId) => {
    if (!docId || String(docId).startsWith('legacy-')) return;
    if (!window.confirm('Are you sure you want to remove this document?')) return;
    try {
      await apiService.deletePropertyDocument(property.id, docId);
      setDocuments(prev => prev.filter(d => d.id !== docId));
    } catch (err) {
      console.error('Failed to delete document:', err);
      alert('Failed to delete document. Please try again.');
    }
  };

  // Organize Lineage History: Ancestors (predecessors), Current Selected TD, and Successors
  const { ancestors, successors, hasLineageData } = useMemo(() => {
    const realLineage = (lineageList || []).filter(item => !item?.is_history_origin);
    if (!realLineage || realLineage.length === 0) {
      return { ancestors: [], successors: [], hasLineageData: false };
    }

    const currentTdn = String(property?.tax_declaration_number || '').trim();
    const currentIndex = realLineage.findIndex(item => String(item.tax_declaration_number).trim() === currentTdn);

    if (currentIndex === -1) {
      // If current record is not found in history array, treat history elements as ancestors
      return {
        ancestors: realLineage,
        successors: [],
        hasLineageData: realLineage.length > 0
      };
    }

    // In assessor API, tax declaration history is commonly ordered latest-to-oldest:
    // Items with index < currentIndex are successors (newer TDs)
    // Items with index > currentIndex are ancestors (older TDs)
    const succ = realLineage.slice(0, currentIndex);
    const anc = realLineage.slice(currentIndex + 1);

    return {
      ancestors: anc,
      successors: succ,
      hasLineageData: true
    };
  }, [lineageList, property?.tax_declaration_number]);

  if (!open || !property) return null;

  // Resolve declarant name
  const discreteOwner = formatDeclarantFromParts(
    property.declarant_last_name,
    property.declarant_first_name,
    property.declarant_middle_initial
  );
  const ownerName = discreteOwner || normalizeDeclarantString(property.declarant_name) || '—';

  // Business / administrator
  const businessName = sanitizeBusinessName(property.business_name);

  // Municipality / Province fallback
  const resolvedMunicipality = property.municipality ||
    settings?.header_municipality ||
    settings?.psgcMunicipalityName ||
    'KITAOTAO';
  const resolvedProvince = property.province ||
    settings?.header_province ||
    'Bukidnon';

  const isCurrent = String(property.property_state || 'CURRENT').toUpperCase() === 'CURRENT';
  const stateLabel = (property.property_state || 'CURRENT').toUpperCase();

  // Signatories resolution from settings/property
  const appraisedByName = property.appraised_by ||
    property.verifier_signatory_name ||
    settings?.verifier_signatory_name ||
    '—';
  const appraisedByTitle = property.verifier_signatory_title ||
    settings?.verifier_signatory_title ||
    'LAOO II / Appraiser';

  const taxMappedByName = property.tax_mapped_by ||
    property.prepared_by ||
    settings?.prepared_by_name ||
    settings?.prepared_by ||
    '—';
  const taxMappedByTitle = property.tax_mapped_by_title ||
    settings?.prepared_by_title ||
    'Tax Mapper / Aide';

  const approvedByName = property.municipal_assessor_name ||
    settings?.municipal_assessor_name ||
    '—';
  const approvedBySuffix = property.municipal_assessor_suffix ||
    settings?.municipal_assessor_suffix ||
    '';
  const approvedByTitle = property.municipal_assessor_title ||
    settings?.municipal_assessor_title ||
    'Municipal Assessor';

  // Actual use fallback
  const actualUseDisplay = property.actual_use ||
    property.gen_class_name ||
    property.gen_class ||
    '—';

  // Check if superseded/cancelled
  const isCancelledOrInactive = !isCurrent;
  const predecessorTdn = property.previous_tax_declaration_number;
  const successorTdn = successors.length > 0 ? successors[0].tax_declaration_number : null;

  const isImageDoc = (doc) => {
    const ext = String(doc?.file_type || '').toLowerCase();
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
  };

  return createPortal(
    <div
      className="fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-3 sm:p-5 overflow-y-auto"
      onClick={(e) => {
        if (e.target === e.currentTarget) {
          onClose();
        }
      }}
    >
      {/* Modal Container */}
      <div
        className="bg-white rounded-2xl shadow-2xl border border-blue-100 max-w-4xl w-full my-auto max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-200"
        role="dialog"
        aria-modal="true"
      >
        {/* 1. Header (Aistudio Style: Sky Blue Gradient) */}
        <div className="bg-gradient-to-r from-sky-700 via-sky-600 to-sky-800 text-white p-6 shrink-0 flex items-start justify-between gap-4">
          <div className="space-y-1.5 flex-1 min-w-0">
            {/* First row: TD Number, Status Chip, Classification Chip */}
            <div className="flex flex-wrap items-center gap-2.5">
              <span className="font-mono text-xl font-extrabold tracking-tight text-white drop-shadow-xs">
                {property.tax_declaration_number || '—'}
              </span>

              <span
                className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold ${
                  isCurrent
                    ? 'bg-emerald-100 text-emerald-800'
                    : 'bg-rose-100 text-rose-800'
                }`}
              >
                <span className="w-1.5 h-1.5 rounded-full bg-current opacity-80" />
                {stateLabel}
              </span>

              {(property.gen_class_name || property.gen_class) && (
                <span className="bg-white/20 text-sky-50 rounded-md text-xs font-semibold px-2 py-0.5">
                  {property.gen_class_name || property.gen_class}
                </span>
              )}
            </div>

            {/* Header Owner Line */}
            <div className="text-sm font-semibold text-sky-100 truncate">
              {ownerName}
              {businessName && (
                <span className="text-sky-200/90 font-normal italic ml-2">
                  ({businessName})
                </span>
              )}
            </div>

            {/* Header Secondary Location Line */}
            <div className="text-xs text-sky-200 flex flex-wrap items-center gap-1.5">
              <span className="font-mono">
                PIN: {property.pin || '—'}
              </span>
              <span>•</span>
              <span>
                {property.location || '—'}, {resolvedMunicipality}
              </span>
            </div>
          </div>

          {/* Header Action Buttons (Print & Close) */}
          <div className="flex items-center gap-2 shrink-0">
            {onOpenPrint && (
              <button
                type="button"
                onClick={() => onOpenPrint(property)}
                title="Print Official Tax Declaration"
                className="p-2 bg-white/15 hover:bg-white/25 rounded-xl border border-white/20 text-white transition-colors cursor-pointer"
              >
                <Printer className="w-4 h-4" />
              </button>
            )}

            <button
              type="button"
              onClick={onClose}
              className="p-2 text-sky-200 hover:text-white rounded-xl hover:bg-sky-500/30 transition flex items-center justify-center cursor-pointer"
              title="Close Dossier (Esc)"
              aria-label="Close Dossier"
            >
              <CloseIcon sx={{ fontSize: 20 }} />
            </button>
          </div>
        </div>

        {/* 2. Tab Navigation Bar (Aistudio Style) */}
        <div className="flex border-b border-sky-100 bg-slate-50/70 px-6 pt-2 shrink-0 gap-3 text-xs font-semibold overflow-x-auto">
          {/* Tab 1: Property Dossier */}
          <button
            type="button"
            onClick={() => handleTabClick('overview')}
            className={`pb-2.5 px-3 border-b-2 whitespace-nowrap transition-colors cursor-pointer ${
              activeTab === 'overview'
                ? 'border-sky-600 text-sky-900 font-bold'
                : 'border-transparent text-slate-500 hover:text-slate-800 font-medium'
            }`}
          >
            Property Dossier
          </button>

          {/* Tab 2: Lineage History */}
          <button
            type="button"
            onClick={() => handleTabClick('lineage')}
            className={`pb-2.5 px-3 border-b-2 whitespace-nowrap transition-colors cursor-pointer ${
              activeTab === 'lineage'
                ? 'border-sky-600 text-sky-900 font-bold'
                : 'border-transparent text-slate-500 hover:text-slate-800 font-medium'
            }`}
          >
            Lineage History
          </button>

          {/* Tab 3: Memoranda & Annotations */}
          <button
            type="button"
            onClick={() => handleTabClick('memoranda')}
            className={`pb-2.5 px-3 border-b-2 whitespace-nowrap transition-colors cursor-pointer ${
              activeTab === 'memoranda'
                ? 'border-sky-600 text-sky-900 font-bold'
                : 'border-transparent text-slate-500 hover:text-slate-800 font-medium'
            }`}
          >
            Memoranda & Annotations
          </button>

          {/* Tab 4: Supporting Documents */}
          <button
            type="button"
            onClick={() => handleTabClick('documents')}
            className={`pb-2.5 px-3 border-b-2 whitespace-nowrap transition-colors cursor-pointer ${
              activeTab === 'documents'
                ? 'border-sky-600 text-sky-900 font-bold'
                : 'border-transparent text-slate-500 hover:text-slate-800 font-medium'
            }`}
          >
            Supporting Documents {documents.length > 0 && `(${documents.length})`}
          </button>
        </div>

        {/* 3. Modal Body (Single Scroll Container) */}
        <div className="flex-1 overflow-y-auto p-6 space-y-6">

          {/* TAB 1: OVERVIEW / PROPERTY DOSSIER */}
          {activeTab === 'overview' && (
            <div className="space-y-6">
              {/* Valuation Banner */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 p-4 bg-sky-50/80 rounded-2xl border border-sky-100">
                {/* Assessed Value */}
                <div className="space-y-1">
                  <div className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                    Assessed Value
                  </div>
                  <div className="text-2xl font-extrabold text-sky-900 font-mono">
                    {formatAssessedValue(property)}
                  </div>
                  <div className="text-[11px] text-sky-700 font-medium">
                    Taxable assessment base
                  </div>
                </div>

                {/* Market Value */}
                <div className="space-y-1">
                  <div className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                    Market Value
                  </div>
                  <div className="text-xl font-bold text-slate-800 font-mono">
                    {formatMarketValue(property)}
                  </div>
                  <div className="text-[11px] text-slate-500">
                    Base appraised valuation
                  </div>
                </div>

                {/* Assessment Level */}
                <div className="space-y-1">
                  <div className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                    Assessment Level
                  </div>
                  <div className="text-xl font-bold text-slate-800 font-mono">
                    {property.assessment_level ? `${property.assessment_level}%` : '—'}
                  </div>
                  <div className="text-[11px] text-slate-500">
                    Effectivity: <span className="font-semibold text-slate-700">{formatEffectivityDisplay(property)}</span>
                  </div>
                </div>
              </div>

              {/* Two-Column Information Area */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">

                {/* Left Card: DECLARED OWNER & LOCATION */}
                <div className="bg-white p-4 rounded-xl border border-slate-200 space-y-3">
                  <h3 className="text-xs font-bold text-slate-700 uppercase tracking-wider border-b border-slate-100 pb-2">
                    DECLARED OWNER & LOCATION
                  </h3>

                  <div className="space-y-3 text-xs">
                    <div>
                      <dt className="text-slate-400 font-medium">Primary Owner</dt>
                      <dd className="mt-0.5 text-sm font-semibold text-slate-900 leading-snug">
                        {ownerName}
                      </dd>
                    </div>

                    {businessName && (
                      <div>
                        <dt className="text-slate-400 font-medium">Administrator / Business Name</dt>
                        <dd className="mt-0.5 text-slate-800 font-medium">
                          {businessName}
                        </dd>
                      </div>
                    )}

                    <div className="grid grid-cols-2 gap-3 pt-1 border-t border-slate-50">
                      <div>
                        <dt className="text-slate-400 font-medium">Taxpayer TIN</dt>
                        <dd className="mt-0.5 font-mono text-slate-800 font-semibold">
                          {property.tin || '—'}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-slate-400 font-medium">Barangay</dt>
                        <dd className="mt-0.5 text-slate-800 font-medium">
                          {property.location || '—'}
                        </dd>
                      </div>
                    </div>

                    <div>
                      <dt className="text-slate-400 font-medium">Property Street Address</dt>
                      <dd className="mt-0.5 text-slate-800 break-words">
                        {property.address || '—'}
                      </dd>
                    </div>

                    <div className="grid grid-cols-2 gap-3 pt-1 border-t border-slate-50">
                      <div>
                        <dt className="text-slate-400 font-medium">Municipality</dt>
                        <dd className="mt-0.5 text-slate-800 font-medium">
                          {resolvedMunicipality}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-slate-400 font-medium">Province</dt>
                        <dd className="mt-0.5 text-slate-800 font-medium">
                          {resolvedProvince}
                        </dd>
                      </div>
                    </div>
                  </div>
                </div>

                {/* Right Card: CADASTRAL & TITLE DESCRIPTION */}
                <div className="bg-white p-4 rounded-xl border border-slate-200 space-y-3">
                  <h3 className="text-xs font-bold text-slate-700 uppercase tracking-wider border-b border-slate-100 pb-2">
                    CADASTRAL & TITLE DESCRIPTION
                  </h3>

                  <div className="space-y-3 text-xs">
                    {/* First field: Certificate of Title */}
                    <div>
                      <dt className="text-slate-400 font-medium">Certificate of Title No.</dt>
                      <dd className="mt-0.5 font-mono text-slate-800 font-semibold">
                        {property.title_number || <span className="text-slate-400 font-normal italic">No Title Annotated</span>}
                      </dd>
                    </div>

                    {/* Second row: Lot Number & Survey Number */}
                    <div className="grid grid-cols-2 gap-3 pt-1 border-t border-slate-50">
                      <div>
                        <dt className="text-slate-400 font-medium">Lot Number</dt>
                        <dd className="mt-0.5 font-medium text-slate-800">
                          {property.lot_number || '—'}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-slate-400 font-medium">Survey Number</dt>
                        <dd className="mt-0.5 font-medium text-slate-800">
                          {property.survey_number || '—'}
                        </dd>
                      </div>
                    </div>

                    {/* Third row: Total Area & Kind of Property */}
                    <div className="grid grid-cols-2 gap-3 pt-1 border-t border-slate-50">
                      <div>
                        <dt className="text-slate-400 font-medium">Total Land / Floor Area</dt>
                        <dd className="mt-0.5 font-semibold text-slate-900">
                          {formatAreaDisplay(property)}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-slate-400 font-medium">Kind of Property</dt>
                        <dd className="mt-0.5 font-semibold text-slate-800">
                          {property.kind_of_property_name || property.kind_of_property || '—'}
                        </dd>
                      </div>
                    </div>

                    {/* Final field: Actual Use */}
                    <div className="grid grid-cols-2 gap-3 pt-1 border-t border-slate-50">
                      <div>
                        <dt className="text-slate-400 font-medium">Actual Use</dt>
                        <dd className="mt-0.5 font-medium text-slate-800">
                          {actualUseDisplay}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-slate-400 font-medium">Property Identification No.</dt>
                        <dd className="mt-0.5 font-mono text-[11px] font-semibold text-sky-900 bg-sky-50 px-2 py-0.5 rounded border border-sky-100 inline-block">
                          {property.pin || '—'}
                        </dd>
                      </div>
                    </div>

                    {property.previous_tax_declaration_number && (
                      <div className="pt-1 border-t border-slate-50">
                        <dt className="text-slate-400 font-medium">Previous TD Number</dt>
                        <dd className="mt-0.5 font-mono text-slate-700">
                          {property.previous_tax_declaration_number}
                        </dd>
                      </div>
                    )}
                  </div>
                </div>

              </div>

              {/* 4. Connected Predecessor Notice (Aistudio Style) */}
              {predecessorTdn && (
                <div className="p-4 bg-sky-50 rounded-xl border border-sky-200 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs">
                  <div className="flex items-center gap-3">
                    <div className="w-8 h-8 rounded-lg bg-sky-100 text-sky-700 flex items-center justify-center shrink-0">
                      <GitBranch className="w-4 h-4" />
                    </div>
                    <div>
                      <div className="text-slate-500 font-medium">Connected Predecessor Tax Dec:</div>
                      <div className="font-mono font-bold text-sky-950 text-sm">
                        {predecessorTdn}
                      </div>
                    </div>
                  </div>

                  <button
                    type="button"
                    onClick={() => handleTabClick('lineage')}
                    className="inline-flex items-center gap-1.5 font-semibold text-sky-700 hover:text-sky-900 bg-white/80 hover:bg-white border border-sky-200 px-3 py-1.5 rounded-lg transition-colors cursor-pointer"
                  >
                    <span>View Lineage History</span>
                    <ChevronRight className="w-3.5 h-3.5" />
                  </button>
                </div>
              )}

              {/* 5. Cancellation Record Notice (Aistudio Style) */}
              {isCancelledOrInactive && (
                <div className="p-4 bg-rose-50 rounded-xl border border-rose-200 text-rose-900 space-y-2 text-xs">
                  <div className="flex items-center gap-2 font-bold text-rose-800 text-sm">
                    <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
                    <span>Cancellation Record Notice</span>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 pt-1 text-xs">
                    <div>
                      <span className="text-rose-700/80 font-medium">Cancellation Date: </span>
                      <span className="font-semibold">{property.cancellation_date || property.updated_at || 'Recorded as Inactive'}</span>
                    </div>

                    <div>
                      <span className="text-rose-700/80 font-medium">Reason: </span>
                      <span className="font-semibold">{property.cancellation_reason || property.property_state || 'Cancelled / Superseded'}</span>
                    </div>

                    {successorTdn && (
                      <div>
                        <span className="text-rose-700/80 font-medium">Superseded By TD: </span>
                        <span className="font-mono font-bold text-rose-950">{successorTdn}</span>
                      </div>
                    )}
                  </div>
                </div>
              )}

            </div>
          )}

          {/* TAB 2: LINEAGE HISTORY (Vertical Timeline) */}
          {activeTab === 'lineage' && (
            <div className="space-y-6">
              <div className="flex items-center justify-between pb-2 border-b border-slate-100">
                <div className="flex items-center gap-2">
                  <Layers className="w-4 h-4 text-sky-600" />
                  <span className="text-xs font-bold text-slate-700 uppercase tracking-wider">
                    Tax Declaration Lineage Timeline
                  </span>
                </div>
                <span className="text-xs text-slate-500">
                  {lineageList.length} recorded node{lineageList.length === 1 ? '' : 's'}
                </span>
              </div>

              {loadingLineage ? (
                <div className="text-xs text-slate-500 py-8 text-center flex items-center justify-center gap-2">
                  <span className="inline-block w-4 h-4 border-2 border-sky-600 border-t-transparent rounded-full animate-spin" />
                  Tracing property declaration lineage...
                </div>
              ) : !hasLineageData && !predecessorTdn ? (
                <div className="text-center py-10 text-xs text-slate-400 bg-slate-50 rounded-xl border border-slate-100 p-6">
                  No lineage records or successor declarations found for this tax declaration.
                </div>
              ) : (
                <div className="relative pl-6 sm:pl-8 space-y-6 before:absolute before:top-3 before:bottom-3 before:left-3 sm:before:left-4 before:w-0.5 before:bg-slate-200">

                  {/* 1. SUCCESSORS (newer records, if any) */}
                  {successors.map((item, idx) => {
                    const itemOwner = normalizeDeclarantString(item.declarant_name) || '—';
                    return (
                      <div key={item.id || `succ-${idx}`} className="relative group">
                        {/* Node Bullet (S1, S2...) */}
                        <div className="absolute -left-6 sm:-left-8 top-3 w-6 h-6 rounded-full bg-emerald-500 text-white font-bold text-[10px] flex items-center justify-center ring-4 ring-white shadow-xs">
                          {`S${successors.length - idx}`}
                        </div>

                        {/* Successor Card */}
                        <div className="bg-emerald-50/60 p-4 rounded-xl border border-emerald-200 space-y-2">
                          <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                              <span className="font-mono text-sm font-bold text-emerald-950">
                                {item.tax_declaration_number}
                              </span>
                              <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">
                                Successor ({item.property_state || 'CURRENT'})
                              </span>
                            </div>

                            <span className="font-mono text-xs font-bold text-emerald-900">
                              {formatAssessedValue(item)}
                            </span>
                          </div>

                          <div className="text-xs text-slate-600 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span className="font-medium text-slate-900">{itemOwner}</span>
                            <span className="text-slate-300">•</span>
                            <span>Effectivity: {formatEffectivityDisplay(item)}</span>
                            {item.title_number && (
                              <>
                                <span className="text-slate-300">•</span>
                                <span className="font-mono">Title: {item.title_number}</span>
                              </>
                            )}
                          </div>

                          {onSelectProperty && (
                            <div className="pt-2 border-t border-emerald-100/60 flex justify-end">
                              <button
                                type="button"
                                onClick={() => onSelectProperty(item)}
                                className="text-xs font-semibold text-emerald-700 hover:text-emerald-900 inline-flex items-center gap-1 cursor-pointer"
                              >
                                <span>Inspect Successor Record</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                              </button>
                            </div>
                          )}
                        </div>
                      </div>
                    );
                  })}

                  {/* 2. CURRENT ACTIVE SELECTED TD */}
                  <div className="relative">
                    {/* Node Bullet (Active Selection) */}
                    <div className="absolute -left-6 sm:-left-8 top-3 w-6 h-6 rounded-full bg-sky-600 text-white font-bold text-xs flex items-center justify-center ring-4 ring-white shadow-md">
                      ●
                    </div>

                    {/* Active Selected Card */}
                    <div className="bg-sky-50/90 p-4 rounded-xl border-2 border-sky-400 shadow-sm space-y-2">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                          <span className="font-mono text-base font-extrabold text-sky-950">
                            {property.tax_declaration_number}
                          </span>
                          <span className="text-[10px] font-bold uppercase tracking-wider px-2.5 py-0.5 rounded-full bg-sky-200/80 text-sky-900 border border-sky-300">
                            Active Selection
                          </span>
                          <span className={`text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full ${isCurrent ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'}`}>
                            {stateLabel}
                          </span>
                        </div>

                        <span className="font-mono text-sm font-extrabold text-sky-950">
                          {formatAssessedValue(property)}
                        </span>
                      </div>

                      <div className="text-xs text-slate-700 flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span className="font-bold text-slate-900">{ownerName}</span>
                        <span className="text-slate-300">•</span>
                        <span>PIN: <span className="font-mono">{property.pin || '—'}</span></span>
                        <span className="text-slate-300">•</span>
                        <span>Area: {formatAreaDisplay(property)}</span>
                        <span className="text-slate-300">•</span>
                        <span>Effectivity: {formatEffectivityDisplay(property)}</span>
                      </div>

                      {property.memoranda && (
                        <div className="mt-2 pt-2 border-t border-sky-200/80 text-[11px] text-slate-600 font-mono line-clamp-2">
                          {property.memoranda}
                        </div>
                      )}
                    </div>
                  </div>

                  {/* 3. ANCESTORS / HISTORICAL RECORDS (older declarations) */}
                  {ancestors.map((item, idx) => {
                    const itemOwner = normalizeDeclarantString(item.declarant_name) || '—';
                    return (
                      <div key={item.id || `anc-${idx}`} className="relative group">
                        {/* Node Bullet (P1, P2...) */}
                        <div className="absolute -left-6 sm:-left-8 top-3 w-6 h-6 rounded-full bg-slate-400 text-white font-bold text-[10px] flex items-center justify-center ring-4 ring-white shadow-xs">
                          {`P${idx + 1}`}
                        </div>

                        {/* Ancestor Card */}
                        <div className="bg-slate-50 p-4 rounded-xl border border-slate-200 text-xs space-y-2 hover:border-slate-300 transition-colors">
                          <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                              <span className="font-mono text-sm font-bold text-slate-900">
                                {item.tax_declaration_number}
                              </span>
                              <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-slate-200 text-slate-700">
                                {item.property_state || 'PREVIOUS'}
                              </span>
                            </div>

                            <span className="font-mono text-xs font-bold text-slate-800">
                              {formatAssessedValue(item)}
                            </span>
                          </div>

                          <div className="text-xs text-slate-600 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span className="font-medium text-slate-900">{itemOwner}</span>
                            <span className="text-slate-300">•</span>
                            <span>Effectivity: {formatEffectivityDisplay(item)}</span>
                            {item.title_number && (
                              <>
                                <span className="text-slate-300">•</span>
                                <span className="font-mono">Title: {item.title_number}</span>
                              </>
                            )}
                            {item.previous_tax_declaration_number && (
                              <>
                                <span className="text-slate-300">•</span>
                                <span className="text-slate-500 font-mono">Prior: {item.previous_tax_declaration_number}</span>
                              </>
                            )}
                          </div>

                          {onSelectProperty && (
                            <div className="pt-2 border-t border-slate-200/60 flex justify-end">
                              <button
                                type="button"
                                onClick={() => onSelectProperty(item)}
                                className="text-xs font-semibold text-sky-700 hover:text-sky-900 inline-flex items-center gap-1 cursor-pointer"
                              >
                                <span>Inspect This Historical Record</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                              </button>
                            </div>
                          )}
                        </div>
                      </div>
                    );
                  })}

                  {/* Fallback predecessor card if lineage array was empty but previous_tax_declaration_number exists */}
                  {ancestors.length === 0 && predecessorTdn && (
                    <div className="relative group">
                      <div className="absolute -left-6 sm:-left-8 top-3 w-6 h-6 rounded-full bg-slate-400 text-white font-bold text-[10px] flex items-center justify-center ring-4 ring-white shadow-xs">
                        P1
                      </div>
                      <div className="bg-slate-50 p-4 rounded-xl border border-slate-200 text-xs space-y-1">
                        <div className="font-mono text-sm font-bold text-slate-900">
                          {predecessorTdn}
                        </div>
                        <div className="text-slate-500">
                          Connected predecessor tax declaration indicated in record.
                        </div>
                        {onSelectProperty && (
                          <div className="pt-2 border-t border-slate-200/60 flex justify-end">
                            <button
                              type="button"
                              onClick={() => onSelectProperty(predecessorTdn)}
                              className="text-xs font-semibold text-sky-700 hover:text-sky-900 inline-flex items-center gap-1 cursor-pointer"
                            >
                              <span>Inspect This Historical Record</span>
                              <ChevronRight className="w-3.5 h-3.5" />
                            </button>
                          </div>
                        )}
                      </div>
                    </div>
                  )}

                </div>
              )}
            </div>
          )}

          {/* TAB 3: MEMORANDA & ANNOTATIONS */}
          {activeTab === 'memoranda' && (
            <div className="space-y-6">
              {/* Memoranda Block */}
              <div className="space-y-3">
                <div className="flex items-center justify-between pb-2 border-b border-slate-100">
                  <span className="text-xs font-bold text-slate-700 uppercase tracking-wider">
                    Memoranda & Legal Annotations
                  </span>
                  <span className="text-xs text-slate-400">
                    Official Assessment Record
                  </span>
                </div>

                {property.memoranda ? (
                  <div className="bg-slate-50 p-5 rounded-xl border border-slate-200 font-mono text-xs text-slate-800 leading-relaxed whitespace-pre-wrap break-words shadow-2xs">
                    {property.memoranda}
                  </div>
                ) : (
                  <div className="text-center py-10 text-xs text-slate-400 bg-slate-50 rounded-xl border border-slate-100 p-6">
                    No memoranda annotations recorded for this property.
                  </div>
                )}
              </div>

              {/* Assessment Approvals & Signatures Box (Aistudio Style) */}
              <div className="p-4 bg-sky-50 rounded-xl border border-sky-100 space-y-2">
                <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-sky-900">
                  <ShieldCheck className="w-4 h-4 text-sky-700" />
                  <span>Assessment Approvals & Signatures</span>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2 text-xs">
                  {/* Appraised by */}
                  <div className="bg-white/80 p-3 rounded-lg border border-sky-200/60">
                    <span className="text-[11px] font-medium text-slate-500 block">Appraised by</span>
                    <span className="font-bold text-slate-900 block mt-0.5">{appraisedByName}</span>
                    <span className="text-[10px] text-slate-500 block">{appraisedByTitle}</span>
                  </div>

                  {/* Tax Mapped by */}
                  <div className="bg-white/80 p-3 rounded-lg border border-sky-200/60">
                    <span className="text-[11px] font-medium text-slate-500 block">Tax Mapped by</span>
                    <span className="font-bold text-slate-900 block mt-0.5">{taxMappedByName}</span>
                    <span className="text-[10px] text-slate-500 block">{taxMappedByTitle}</span>
                  </div>

                  {/* Approved by */}
                  <div className="bg-white/80 p-3 rounded-lg border border-sky-200/60">
                    <span className="text-[11px] font-medium text-slate-500 block">Approved by</span>
                    <span className="font-bold text-slate-900 block mt-0.5">
                      {approvedByName}{approvedBySuffix ? ` ${approvedBySuffix}` : ''}
                    </span>
                    <span className="text-[10px] text-slate-500 block">{approvedByTitle}</span>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* TAB 4: SUPPORTING DOCUMENTS (Aistudio Style) */}
          {activeTab === 'documents' && (
            <div className="space-y-6">
              {/* Header Title & Subtitle */}
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-1 pb-3 border-b border-slate-100">
                <div>
                  <h3 className="text-xs font-bold text-slate-800 uppercase tracking-wider">
                    ATTACHED DEEDS, TITLES & CLEARANCES
                  </h3>
                  <p className="text-xs text-slate-500">
                    Legal proof of ownership and assessment references
                  </p>
                </div>
                <span className="text-xs font-semibold text-slate-600 bg-slate-100 px-2.5 py-1 rounded-full self-start sm:self-auto">
                  {documents.length} document{documents.length === 1 ? '' : 's'}
                </span>
              </div>

              {loadingDocs ? (
                <div className="text-xs text-slate-500 py-10 text-center flex items-center justify-center gap-2">
                  <span className="inline-block w-4 h-4 border-2 border-sky-600 border-t-transparent rounded-full animate-spin" />
                  Loading attached documents...
                </div>
              ) : documents.length === 0 ? (
                /* Document Empty State */
                <div className="p-10 text-center border border-dashed border-slate-200 rounded-xl text-slate-400 text-xs space-y-2">
                  <Paperclip className="w-8 h-8 mx-auto text-slate-300" />
                  <div className="font-medium text-slate-500">No supporting documents uploaded yet.</div>
                  <div className="text-[11px] text-slate-400">Supporting deeds, titles, or clearance attachments will appear here.</div>
                </div>
              ) : (
                /* Document Cards Grid */
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  {documents.map((doc) => {
                    const isImg = isImageDoc(doc);
                    const docName = doc.original_filename || doc.filename || 'Property Attachment';
                    const docType = doc.document_type || doc.file_type || (isImg ? 'IMAGE' : 'FILE');
                    const fileSize = formatBytes(doc.file_size);
                    const fileDate = doc.created_at || doc.uploaded_at;
                    const canDelete = !doc.is_legacy && (canEdit || isAdmin);

                    return (
                      <div
                        key={doc.id}
                        className="p-3.5 bg-white rounded-xl border border-slate-200 hover:border-slate-300 hover:shadow-xs transition-all flex flex-col justify-between gap-3"
                      >
                        <div className="flex items-start gap-3">
                          {/* Document Icon / Thumbnail */}
                          {isImg ? (
                            <button
                              type="button"
                              onClick={() => {
                                if (onPreviewImage) {
                                  onPreviewImage({ src: doc.file_url, alt: docName });
                                }
                              }}
                              className="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 overflow-hidden shrink-0 group/thumb cursor-pointer relative"
                              title="Click to zoom image"
                            >
                              <img
                                src={doc.file_url}
                                alt={docName}
                                className="w-full h-full object-cover group-hover/thumb:scale-105 transition"
                                onError={(e) => {
                                  e.currentTarget.style.display = 'none';
                                  if (e.currentTarget.nextSibling) {
                                    e.currentTarget.nextSibling.style.display = 'flex';
                                  }
                                }}
                              />
                              <div className="hidden absolute inset-0 items-center justify-center bg-slate-100 text-slate-400">
                                <BrokenImageIcon sx={{ fontSize: 18 }} />
                              </div>
                            </button>
                          ) : (
                            <div className="w-12 h-12 rounded-lg bg-sky-50 text-sky-700 border border-sky-100 flex items-center justify-center shrink-0">
                              <FileText className="w-6 h-6" />
                            </div>
                          )}

                          {/* Info */}
                          <div className="flex-1 min-w-0 space-y-1">
                            <div className="flex items-center gap-1.5 flex-wrap">
                              <span className="px-2 py-0.5 rounded-md bg-sky-50 text-sky-800 text-[10px] font-bold border border-sky-100 uppercase tracking-wider">
                                {docType}
                              </span>
                              {doc.is_legacy && (
                                <span className="text-[10px] text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded">
                                  Legacy
                                </span>
                              )}
                            </div>

                            <div className="text-xs font-semibold text-slate-800 truncate" title={docName}>
                              {docName}
                            </div>

                            {doc.description && doc.description !== 'Legacy document' && (
                              <p className="text-[11px] text-slate-500 line-clamp-1">
                                {doc.description}
                              </p>
                            )}

                            {(fileSize || fileDate) && (
                              <div className="text-[10px] text-slate-400 flex items-center gap-1.5">
                                {fileSize && <span>{fileSize}</span>}
                                {fileSize && fileDate && <span>•</span>}
                                {fileDate && <span>{fileDate}</span>}
                              </div>
                            )}
                          </div>
                        </div>

                        {/* Actions (View & Delete) */}
                        <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100 text-xs">
                          {isImg ? (
                            <button
                              type="button"
                              onClick={() => {
                                if (onPreviewImage) {
                                  onPreviewImage({ src: doc.file_url, alt: docName });
                                }
                              }}
                              className="inline-flex items-center gap-1 text-sky-700 hover:text-sky-900 font-semibold px-2 py-1 rounded hover:bg-sky-50 transition cursor-pointer"
                            >
                              <Eye className="w-3.5 h-3.5" />
                              <span>View Image</span>
                            </button>
                          ) : (
                            <a
                              href={doc.file_url}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="inline-flex items-center gap-1 text-sky-700 hover:text-sky-900 font-semibold px-2 py-1 rounded hover:bg-sky-50 transition"
                            >
                              <ExternalLink className="w-3.5 h-3.5" />
                              <span>Open Document</span>
                            </a>
                          )}

                          {canDelete && (
                            <button
                              type="button"
                              onClick={() => handleDeleteDoc(doc.id)}
                              className="inline-flex items-center gap-1 text-rose-600 hover:text-rose-800 font-medium px-2 py-1 rounded hover:bg-rose-50 transition cursor-pointer"
                              title="Delete Document"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                              <span>Delete</span>
                            </button>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          )}

        </div>
      </div>
    </div>,
    document.body
  );
};

export default PropertyDossierModal;
