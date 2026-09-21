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
  CircularProgress,
  ToggleButton,
  ToggleButtonGroup,
  Select,
  MenuItem,
  FormControl,
  InputLabel,
  Chip,
  Menu,
  Tooltip,
  Collapse
} from '@mui/material';
import { GitBranch, CheckCircle2, XCircle } from 'lucide-react';
import {
  Search as SearchIcon,
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Visibility as VisibilityIcon,
  Print as PrintIcon,
  Receipt as ReceiptIcon,
  AttachFile as AttachFileIcon,
  BrokenImage as BrokenImageIcon,
  Image as ImageIcon,
  ExpandMore as ExpandMoreIcon,
  ExpandLess as ExpandLessIcon
} from '@mui/icons-material';
import { color, motion } from 'framer-motion';

// We'll load html2pdf.js from CDN at runtime to avoid webpack sourcemap warnings

import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';
import { statusColors } from '../../theme/theme';
import PropertyFormModal from '../PropertyFormModal/PropertyFormModal';
import { useReactToPrint } from 'react-to-print';
import LoadingDots from '../LoadingDots';
import useLoadingWatchdog from '../../hooks/useLoadingWatchdog';
import { formatAppDateTime, formatAppDate } from '../../utils/dateTime';
import HistoryPrintDocument, { HISTORY_PRINT_PAGE_STYLE } from '../HistoryPrintDocument/HistoryPrintDocument';

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


// Format declarant from discrete fields; add dot only for single-character middle
const formatDeclarantFromParts = (last, first, middle) => {
  const hasNames = !!(last || first);
  if (!hasNames) return '';
  // Normalize middle: remove any dots so we control dot rendering
  const raw = (middle || '').trim();
  const mi = raw.replace(/\./g, '');
  const middleFormatted = mi ? (mi.length === 1 ? ` ${mi}.` : ` ${mi}`) : '';
  return `${last || ''}${hasNames && first ? ', ' : ''}${first || ''}${middleFormatted}`.trim();
};

// Adjust a combined declarant name string ("LAST, FIRST MI" or "LAST, FIRST MI.")
// Apply rule: if middle token length === 1 -> ensure trailing dot; if length > 1 -> no dot
const normalizeDeclarantString = (name) => {
  const s = sanitizeDeclarant(name);
  if (!s) return s;

  // Split by comma to separate last name from first/middle
  const parts = s.split(',');
  if (parts.length < 2) return s;

  const last = parts[0].trim();
  const rest = parts.slice(1).join(',').trim();
  if (!rest) return `${last}`;

  // Handle cases where we have "LAST, ET. AL., FIRST MI" format
  // We want to preserve the "ET. AL." part and format the first name and middle initial
  const restParts = rest.split(/\s+/);

  // Find the actual first name and middle initial
  // Look for the last meaningful word (middle initial) and the word before it (first name)
  const meaningfulParts = restParts.filter(part => part.length > 0);

  if (meaningfulParts.length === 0) return `${last}`;
  if (meaningfulParts.length === 1) return `${last}, ${rest}`;

  // Take the last two meaningful parts as first name and middle initial
  const first = meaningfulParts[meaningfulParts.length - 2];
  const middleRaw = meaningfulParts[meaningfulParts.length - 1];

  // Format middle initial
  const middleNoDots = middleRaw.replace(/\./g, '');
  const middleFormatted = middleNoDots.length === 1 ? `${middleNoDots}.` : middleNoDots;

  // Reconstruct with all parts preserved
  const beforeFirst = meaningfulParts.slice(0, -2).join(' ');
  const result = `${last}, ${beforeFirst ? beforeFirst + ' ' : ''}${first} ${middleFormatted}`.trim();

  return result;
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

// Helper function to get image attachment status for a property
const getImageAttachmentStatus = (property) => {
  if (!property) return { hasImages: false, hasBrokenLinks: false, totalUrls: 0, validUrls: 0 };

  const supportingDocs = property.supporting_documents;
  const supportingDocsOld = property.supporting_documents_old;

  // Helper function to extract and validate URLs from a field
  const extractUrls = (field) => {
    if (!field || !String(field).trim()) return [];

    return String(field)
      .split(/\||,/)
      .map(url => String(url).trim())
      .filter(url => url);
  };

  // Get all URLs from both fields
  const allUrls = [
    ...extractUrls(supportingDocs),
    ...extractUrls(supportingDocsOld)
  ];

  // Filter valid HTTP/HTTPS URLs
  const validUrls = allUrls.filter(url => /^https?:\/\//i.test(url));
  const brokenUrls = allUrls.filter(url => url && !/^https?:\/\//i.test(url));

  return {
    hasImages: validUrls.length > 0,
    hasBrokenLinks: brokenUrls.length > 0,
    totalUrls: allUrls.length,
    validUrls: validUrls.length,
    brokenUrls: brokenUrls.length
  };
};

// Helper for state badge colors
const getStateColor = (state) => {
  switch (state) {
    case 'CURRENT':
      return { bg: '#e8f5e9', text: '#2e7d32' };
    case 'CANCELLED':
      return { bg: '#ffebee', text: '#c62828' };
    case 'INTERIM':
      return { bg: '#fff3e0', text: '#e65100' };
    case 'PENDING':
      return { bg: '#e3f2fd', text: '#1565c0' };
    default:
      return { bg: '#f5f5f5', text: '#616161' };
  }
};

const PropertyTable = () => {
  const { isAdmin, isSuperAdmin, canEdit, isViewer } = useAuth();
  const tableContainerRef = useRef(null);
  const [properties, setProperties] = useState([]);
  const [loading, setLoading] = useState(true);
  const [initialLoad, setInitialLoad] = useState(true);
  const [error, setError] = useState('');
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(50);
  const [totalCount, setTotalCount] = useState(0);
  const [allProperties, setAllProperties] = useState([]);
  const [loadingAll, setLoadingAll] = useState(false);

  // Safety check - ensure properties is always an array
  const safeProperties = properties || [];

  // Search state
  const [searchTerm, setSearchTerm] = useState('');
  const debouncedSearchTerm = useDebounce(searchTerm, 300); // 300ms delay
  // Image status filter: 'all' | 'with' | 'without'
  const [imageFilter, setImageFilter] = useState('all');
  // Revision filter
  const [revisionFilter, setRevisionFilter] = useState('');
  const [revisionEntries, setRevisionEntries] = useState([]);

  // Location filter. Empty string means All Locations
  const [locationFilter, setLocationFilter] = useState('');
  const [locationOptions, setLocationOptions] = useState([]);

  // Kind and Class filters
  const [kindFilter, setKindFilter] = useState('');
  const [kindOptions, setKindOptions] = useState([]);
  const [classFilter, setClassFilter] = useState('');
  const [classOptions, setClassOptions] = useState([]);

  // Property State filter and editing state
  const [stateFilter, setStateFilter] = useState('');
  const [stateAnchorEl, setStateAnchorEl] = useState(null);
  const [selectedStatePropertyId, setSelectedStatePropertyId] = useState(null);
  const [updatingState, setUpdatingState] = useState(false);

  const imageCounts = useMemo(() => {
    // Always prioritize allProperties for accurate counts, fall back to safeProperties only if allProperties is empty
    const base = (allProperties && allProperties.length > 0) ? allProperties : safeProperties;
    let withImg = 0, without = 0;
    (base || []).forEach((property) => {
      const s = getImageAttachmentStatus(property);
      if (s.hasImages) withImg += 1;
      if (!s.hasImages) without += 1;
    });
    return { withImg, without };
  }, [allProperties, safeProperties]);

  const filteredProperties = useMemo(() => {
    // Use allProperties when searching or when image filter is active, otherwise use safeProperties
    // Revision filter is handled server-side, so no client-side filtering needed
    const base = (debouncedSearchTerm || imageFilter !== 'all') ?
      (allProperties && allProperties.length ? allProperties : safeProperties) :
      safeProperties;

    return (base || []).filter((property) => {
      if (!property) return false;

      // Apply search filter
      if (debouncedSearchTerm) {
        // Normalize search term: remove dots so "victoria c. dapanas" matches "victoria c dapanas"
        const normalizedSearch = debouncedSearchTerm.replace(/\./g, '').toUpperCase();

        // Get raw database values (no dots added)
        const rawLast = String(property.declarant_last_name || '').toUpperCase();
        const rawFirst = String(property.declarant_first_name || '').toUpperCase();
        const rawMiddle = String(property.declarant_middle_initial || '').toUpperCase();
        const rawBusiness = property.business_name ? String(property.business_name).replace(/,\s*/g, ' ').toUpperCase() : '';

        // Create combined format matching display: "LAST, FIRST MIDDLE / BUSINESS"
        const combinedDisplayFormat = (() => {
          const parts = [];
          if (rawLast) parts.push(rawLast);
          if (rawFirst) {
            if (parts.length > 0) parts.push(', ' + rawFirst);
            else parts.push(rawFirst);
          }
          if (rawMiddle) parts.push(' ' + rawMiddle);
          const declarantStr = parts.join('');
          if (declarantStr && rawBusiness) return `${declarantStr} / ${rawBusiness}`;
          return declarantStr || rawBusiness || '';
        })();

        // Create natural format: "FIRST MIDDLE LAST"
        const naturalFormat = `${rawFirst} ${rawMiddle} ${rawLast}`.trim();

        // Create reversed format with business: "FIRST MIDDLE LAST / BUSINESS"
        const naturalWithBusiness = naturalFormat && rawBusiness ? `${naturalFormat} / ${rawBusiness}` : (naturalFormat || rawBusiness);

        const matchesSearch =
          (property.tax_declaration_number && String(property.tax_declaration_number).toUpperCase().includes(normalizedSearch)) ||
          (rawLast && rawLast.includes(normalizedSearch)) ||
          (rawFirst && rawFirst.includes(normalizedSearch)) ||
          (rawMiddle && rawMiddle.includes(normalizedSearch)) ||
          (combinedDisplayFormat && combinedDisplayFormat.includes(normalizedSearch)) ||
          (naturalFormat && naturalFormat.includes(normalizedSearch)) ||
          (naturalWithBusiness && naturalWithBusiness.includes(normalizedSearch)) ||
          (property.lot_number && String(property.lot_number).toUpperCase().includes(normalizedSearch)) ||
          (property.title_number && String(property.title_number).toUpperCase().includes(normalizedSearch)) ||
          (rawBusiness && rawBusiness.includes(normalizedSearch));

        if (!matchesSearch) return false;
      }

      // Apply image filter
      if (imageFilter === 'all') return true;
      const status = getImageAttachmentStatus(property);
      if (imageFilter === 'with') return status.hasImages;
      if (imageFilter === 'without') return !status.hasImages;

      return true;
    });
  }, [allProperties, safeProperties, imageFilter, debouncedSearchTerm]);

  const pagedProperties = useMemo(() => {
    // When using server-side pagination (no search term, image filter is 'all'),
    // use safeProperties directly instead of client-side slicing
    // Revision filter is handled server-side, so it's included in safeProperties
    if (!debouncedSearchTerm && imageFilter === 'all') {
      return safeProperties;
    }

    // For client-side filtering (search or image filter), apply slicing
    const start = page * rowsPerPage;
    const end = start + rowsPerPage;
    return filteredProperties.slice(start, end);
  }, [filteredProperties, safeProperties, page, rowsPerPage, debouncedSearchTerm, imageFilter]);

  const extractImageUrls = (prop) => {
    try {
      const sources = [prop?.supporting_documents, prop?.supporting_documents_old]
        .filter(Boolean)
        .map(String)
        .join(' | ');
      if (!sources) return [];
      return sources
        .split(/\||,/)
        .map(s => String(s).trim())
        .filter(u => u && /^https?:\/\//i.test(u));
    } catch (_) { return []; }
  };

  const checkImageUrl = (url, timeoutMs = 5000) => new Promise((resolve) => {
    let done = false;
    const timer = setTimeout(() => { if (!done) { done = true; resolve(false); } }, timeoutMs);
    try {
      const img = new Image();
      img.onload = () => { if (!done) { done = true; clearTimeout(timer); resolve(true); } };
      img.onerror = () => { if (!done) { done = true; clearTimeout(timer); resolve(false); } };
      img.src = url;
    } catch (_) {
      clearTimeout(timer);
      resolve(false);
    }
  });

  useEffect(() => {
    let cancelled = false;
    const run = async () => {
      const updates = {};
      for (const prop of pagedProperties) {
        const propId = prop?.id;
        if (!propId) continue;
        if (imageStatusByPropertyId[propId]?.verified) continue;
        const urls = extractImageUrls(prop);
        if (!urls.length) {
          updates[propId] = { hasImages: false, hasBrokenLinks: false, verified: true };
          continue;
        }
        // Check all URLs with early exit on first success
        const toCheck = urls;
        let anyOk = false;
        for (const u of toCheck) {
          const sep = u.includes('?') ? '&' : '?';
          const testUrl = `${u}${sep}__ping=${Date.now()}`;
          // eslint-disable-next-line no-await-in-loop
          const ok = await checkImageUrl(testUrl, 4000);
          if (ok) { anyOk = true; break; }
        }
        updates[propId] = { hasImages: anyOk, hasBrokenLinks: !anyOk, verified: true };
      }
      if (!cancelled && Object.keys(updates).length) {
        setImageStatusByPropertyId(prev => ({ ...prev, ...updates }));
      }
    };
    run();
    return () => { cancelled = true; };
  }, [pagedProperties, imageFilter]);

  // Modal states
  const [propertyModal, setPropertyModal] = useState(false);
  const [selectedProperty, setSelectedProperty] = useState(null);
  const [deleteDialog, setDeleteDialog] = useState(false);
  const [expandedMemos, setExpandedMemos] = useState({});
  const [expandedDeclarants, setExpandedDeclarants] = useState({});

  const toggleMemo = (id) => {
    setExpandedMemos(prev => ({
      ...prev,
      [id]: !prev[id]
    }));
  };

  const toggleDeclarant = (id) => {
    setExpandedDeclarants(prev => ({
      ...prev,
      [id]: !prev[id]
    }));
  };
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
  const [imageModal, setImageModal] = useState(false);
  const [propertyImages, setPropertyImages] = useState([]);
  const [maximizedImage, setMaximizedImage] = useState({ open: false, src: '', alt: '' });
  const [imageStatusByPropertyId, setImageStatusByPropertyId] = useState({});
  const initialSettings = (() => {
    if (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__) return window.__ASSESSOR_SETTINGS__;
    try {
      const cached = localStorage.getItem('assessor_settings');
      if (cached) return JSON.parse(cached);
    } catch (_) { }
    const cachedLogo = typeof window !== 'undefined' ? localStorage.getItem('app_logo_url') : '';
    if (cachedLogo) return { app_logo_url: cachedLogo };
    return null;
  })();
  const [settings, setSettings] = useState(initialSettings);

  // Universal safety watchdog: prevent infinite initial loading if the backend/network hangs
  useLoadingWatchdog({
    isLoading: loading,
    isInitialLoad: initialLoad,
    setLoading,
    setInitialLoad,
    setError,
    componentName: 'PropertyTable',
    timeoutMs: 20000,
    timeoutMessage: 'Property data request timed out. Please check your connection and try again.',
    enabled: true
  });

  // Fetch revision entries
  useEffect(() => {
    const fetchRevisionEntries = async () => {
      try {
        console.log('🔍 FETCHING REVISION ENTRIES...');
        const response = await apiService.getRevisionEntries();
        console.log('🔍 REVISION ENTRIES RESPONSE:', response);
        const entries = response?.items || [];
        console.log('🔍 SETTING REVISION ENTRIES:', entries);
        setRevisionEntries(entries);
      } catch (error) {
        console.error('Failed to fetch revision entries:', error);
      }
    };
    fetchRevisionEntries();
  }, []);

  // Fetch location options, property types, and general classes
  useEffect(() => {
    const fetchDropdowns = async () => {
      try {
        const [locRes, kindRes, classRes] = await Promise.all([
          apiService.getLocations(),
          apiService.getPropertyTypes(),
          apiService.getGeneralClasses()
        ]);

        const locItems = Array.isArray(locRes?.items) ? locRes.items : [];
        const kindItems = Array.isArray(kindRes?.items) ? kindRes.items : [];
        const classItems = Array.isArray(classRes?.items) ? classRes.items : [];

        setLocationOptions(locItems.filter(i => i.status === 'active'));
        setKindOptions(kindItems.filter(i => i.status === 'active'));
        setClassOptions(classItems.filter(i => i.status === 'active'));
      } catch (e) {
        console.error('Failed to fetch dropdowns:', e);
        setLocationOptions([]);
        setKindOptions([]);
        setClassOptions([]);
      }
    };
    fetchDropdowns();
  }, []);

  // Fetch properties for pagination (only when not searching and image filter is 'all')
  // Revision, location, kind, and class filters use server-side filtering
  useEffect(() => {
    if (!debouncedSearchTerm && imageFilter === 'all') {
      if (revisionFilter || locationFilter || kindFilter || classFilter || stateFilter) {
        // Use server-side filtering when filters are active
        fetchPropertiesWithFilters(revisionFilter, locationFilter, kindFilter, classFilter, stateFilter);
      } else {
        // Use regular fetchProperties when no filters are active
        fetchProperties();
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, rowsPerPage, debouncedSearchTerm, imageFilter, revisionFilter, locationFilter, kindFilter, classFilter, stateFilter]);

  // Fetch full dataset for client-side filtering/pagination when searching or when image filter is active
  const fetchAllDataset = useCallback(async () => {
    try {
      setLoadingAll(true);
      const params = {
        all: 1,
        q: debouncedSearchTerm || '',
        revision_id: revisionFilter || '',
        location: locationFilter || '',
        kind_of_property: kindFilter || '',
        gen_class: classFilter || '',
        property_state: stateFilter || '',
        _t: Date.now()
      };
      const response = await apiService.getProperties(params);
      const items = (response && response.properties)
        ? response.properties
        : (response && response.data)
          ? response.data
          : [];
      setAllProperties(items);
    } catch (e) {
      setAllProperties([]);
    } finally {
      setLoadingAll(false);
    }
  }, [debouncedSearchTerm, revisionFilter, locationFilter, kindFilter, classFilter, stateFilter]);

  useEffect(() => {
    // Always refresh the all-properties dataset when filters change so
    // imageCounts stay accurate for the current revision/search/image filter.
    // This includes when switching back to "All Revisions" (empty string).
    fetchAllDataset();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearchTerm, imageFilter, revisionFilter, locationFilter, kindFilter, classFilter, stateFilter]);

  // Fetch full dataset for image counts on initial load
  useEffect(() => {
    const fetchInitialCounts = async () => {
      try {
        setLoadingAll(true);
        const params = {
          all: 1,
          q: '',
          _t: Date.now()
        };
        const response = await apiService.getProperties(params);
        const items = (response && response.properties)
          ? response.properties
          : (response && response.data)
            ? response.data
            : [];
        setAllProperties(items);
      } catch (e) {
        // Fall back silently
        setAllProperties([]);
      } finally {
        setLoadingAll(false);
      }
    };

    // Fetch initial counts if we don't have allProperties yet
    if (!allProperties.length) {
      fetchInitialCounts();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Open printable modal automatically when navigated from Dashboard Recent Properties
  useEffect(() => {
    try {
      const tdn = localStorage.getItem('assessor_open_print_tdn');
      if (tdn) {
        // Clear immediately to avoid re-opening on subsequent visits
        localStorage.removeItem('assessor_open_print_tdn');
        // Delay slightly to avoid modal jank while table renders
        setTimeout(() => {
          handleViewPrintableHistory(tdn);
        }, 50);
      }
    } catch (_) { }
  }, []);

  useEffect(() => {
    const loadSettings = async () => {
      try {
        const data = await apiService.getSettings();
        setSettings(data);
        try {
          localStorage.setItem('assessor_settings', JSON.stringify(data));
          if (data && data.app_logo_url) localStorage.setItem('app_logo_url', data.app_logo_url);
        } catch (_) { }
      } catch (e) {
        setSettings(null);
      }
    };
    loadSettings();
  }, []);


  const fetchSeqRef = useRef(0);
  const fetchPropertiesWithFilters = useCallback(async (revisionId, locationName, kindName, className, stateName) => {
    const seq = ++fetchSeqRef.current;
    try {
      setLoading(true);
      setInitialLoad(false); // Clear initial load state immediately to prevent watchdog timeout
      setError(''); // Clear previous errors

      const params = {
        page: page + 1,
        per_page: rowsPerPage,
        q: debouncedSearchTerm || '',
        revision_id: revisionId || '',
        location: locationName || '',
        kind_of_property: kindName || '',
        gen_class: className || '',
        property_state: stateName || '',
        // Add cache busting timestamp to prevent browser caching
        _t: Date.now()
      };

      console.log('🔍 FETCH PROPERTIES WITH FILTERS DEBUG:', {
        page: page + 1,
        per_page: rowsPerPage,
        searchTerm: debouncedSearchTerm,
        revisionId,
        locationName,
        params
      });

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
        setProperties([]);
        setTotalCount(0);
      }
    } catch (error) {
      console.error('Error fetching properties with revision:', error);
      setError('Failed to load properties');
      setProperties([]);
      setTotalCount(0);
    } finally {
      setLoading(false);
    }
  }, [page, rowsPerPage, debouncedSearchTerm]);

  const fetchProperties = useCallback(async (forceRefresh = false) => {
    const seq = ++fetchSeqRef.current;
    try {
      setLoading(true);
      setInitialLoad(false); // Clear initial load state immediately to prevent watchdog timeout
      setError(''); // Clear previous errors

      const params = {
        page: page + 1,
        per_page: rowsPerPage,
        q: debouncedSearchTerm || '',
        revision_id: revisionFilter || '',
        location: locationFilter || '',
        kind_of_property: kindFilter || '',
        gen_class: classFilter || '',
        property_state: stateFilter || '',
        // Add cache busting timestamp to prevent browser caching
        _t: forceRefresh ? Date.now() : Date.now()
      };

      console.log('🔍 FETCH PROPERTIES DEBUG:', {
        page: page + 1,
        per_page: rowsPerPage,
        searchTerm: debouncedSearchTerm,
        revisionFilter,
        locationFilter,
        stateFilter,
        params
      });


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
    }
  }, [page, rowsPerPage, debouncedSearchTerm, revisionFilter, locationFilter, kindFilter, classFilter]);

  const handleSearch = (event) => {
    const raw = event.target.value || '';
    // Only normalize special dash characters, preserve all spaces
    const normalized = raw.replace(/[\u2013\u2014]/g, '-');
    setSearchTerm(normalized);
    setPage(0); // Reset to first page when searching
  };

  const handleSearchKeyDown = (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      const list = filteredProperties || [];
      if (!list.length) return;
      // Prefer exact TDN match on current page; otherwise open the first visible row
      const exact = list.find(p => String(p.tax_declaration_number || '').toUpperCase() === String(searchTerm || '').toUpperCase());
      const target = exact || list[0];
      if (target && target.tax_declaration_number) handleViewPrintableHistory(target.tax_declaration_number);
    }
  };

  // No additional filters
  const handleFilterChange = () => { };
  const handleImageFilterChange = (_event, value) => {
    // Guard against deselection or unexpected values
    if (value === null || (value !== 'all' && value !== 'with' && value !== 'without')) {
      return;
    }
    setImageFilter(value);
    setPage(0);
  };

  const handlePageChange = (event, newPage) => {
    setPage(newPage);
    if (tableContainerRef.current) {
      tableContainerRef.current.scrollTo(0, 0);
    }
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
      // Refresh current page and counts respecting filters
      fetchPropertiesWithFilters(revisionFilter, locationFilter, kindFilter, classFilter, stateFilter);
      // Always refresh the all-properties dataset so imageCounts stay in sync
      fetchAllDataset();
    } catch (err) {
      setError('Failed to delete property');
    }
  };

  const handlePropertySaved = () => {
    setPropertyModal(false);
    setSelectedProperty(null);
    // Refresh current page and counts respecting filters
    fetchPropertiesWithFilters(revisionFilter, locationFilter, kindFilter, classFilter, stateFilter);
    // Always refresh the all-properties dataset so imageCounts stay in sync
    fetchAllDataset();
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
          setPrintDocuments([...docs, ...legacy]);
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

  const handleViewImages = async (property) => {
    try {
      setImageModal(true);

      // Get all image URLs from supporting documents
      const supportingDocs = property.supporting_documents;
      const supportingDocsOld = property.supporting_documents_old;

      const extractUrls = (field) => {
        if (!field || !String(field).trim()) return [];
        return String(field)
          .split(/\||,/)
          .map(url => String(url).trim())
          .filter(url => url && /^https?:\/\//i.test(url));
      };

      const allUrls = [
        ...extractUrls(supportingDocs),
        ...extractUrls(supportingDocsOld)
      ];

      // Format URLs for display
      const images = allUrls.map((url, idx) => {
        const path = url.split('?')[0];
        const name = decodeURIComponent(path.substring(path.lastIndexOf('/') + 1));
        return {
          id: `image-${idx}`,
          url: url,
          name: name || `Image ${idx + 1}`,
          alt: name || `Property image ${idx + 1}`
        };
      });

      setPropertyImages(images);
    } catch (err) {
      console.error('Error loading property images:', err);
      setPropertyImages([]);
    }
  };

  // removed html2pdf
  const printRef = useRef(null);
  const handlePrint = useReactToPrint({
    contentRef: printRef,
    removeAfterPrint: true,
    pageStyle: HISTORY_PRINT_PAGE_STYLE
  });

  const getStatusColor = (status) => {
    return statusColors[status] || statusColors.info;
  };

  const clearFilters = () => {
    setSearchTerm('');
    setPage(0);
    setImageFilter('all');
    setRevisionFilter('');
    setLocationFilter('');
  };

  if (initialLoad && loading && (!safeProperties || safeProperties.length === 0)) {
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
          Loading properties<LoadingDots />
        </Typography>
        {/* <Typography variant="body2" color="text.secondary">
          Please wait while the system loads
        </Typography> */}
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
    const small = new Set(['of', 'and', 'the', 'for', 'in', 'on', 'at', 'a', 'an']);
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

    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}
      sx={{
        display: 'flex',
        flexDirection: 'column',
        height: 'calc(100vh - 64px - 3rem)',
        overflow: 'hidden'
      }}>
      <Typography variant="h4" gutterBottom sx={{ flexShrink: 0 }}>
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
      <Card sx={{ mb: 3, flexShrink: 0 }}>
        <CardContent>
          <Grid container spacing={{ xs: 1, sm: 2 }} alignItems="center">
            {/* Search Field and Add Button Row */}
            <Grid item xs={12} sm={6} md={6} lg={6} xl={6}>
              <TextField
                fullWidth
                label="Search Properties"
                size='small'
                value={searchTerm}
                onChange={handleSearch}
                onKeyDown={handleSearchKeyDown}
                placeholder="Search by TDN, name, lot number, or title number..."
                // helperText="Search by Tax Declaration Number, Declarant Last Name, Declarant First Name, Lot Number, or Title Number"
                InputProps={{
                  startAdornment: <SearchIcon sx={{ mr: 1, color: 'text.secondary' }} />
                }}
              />
            </Grid>
            <Grid item xs={12} sm={6} md={6} lg={6} xl={6} display="flex" justifyContent={{ xs: 'flex-start', sm: 'flex-end' }}>
              <Button
                variant="contained"
                size="small"
                startIcon={<AddIcon />}
                onClick={handleAddProperty}
                disabled={isViewer}
                color="primary"
                sx={{
                  height: 40,
                  minHeight: 40,
                  px: 1.5,
                  minWidth: 'auto',
                  width: 'auto'
                }}
              >
                <Box component="span" sx={{ display: { xs: 'none', sm: 'inline' } }}>
                  Add Property
                </Box>
                <Box component="span" sx={{ display: { xs: 'inline', sm: 'none' } }}>
                  Add
                </Box>
              </Button>
            </Grid>

            {/* Filters Row */}
            <Grid item xs={12}>
              <Box
                display="flex"
                alignItems="center"
                gap={{ xs: 1, sm: 2 }}
                flexWrap="wrap"
                sx={{
                  '& > *': {
                    flex: '0 1 auto',
                    minWidth: 'auto'
                  }
                }}
              >
                <FormControl size="small" sx={{
                  minWidth: 150,
                  width: 'auto',
                }}>
                  <InputLabel shrink>Revision</InputLabel>
                  <Select
                    value={revisionFilter}
                    onChange={(e) => {
                      const newValue = e.target.value;
                      setRevisionFilter(newValue);
                      setPage(0);
                      // Immediately fetch with the new revision + current location, kind, class, state
                      fetchPropertiesWithFilters(newValue, locationFilter, kindFilter, classFilter, stateFilter);
                    }}
                    label="Revision"
                    displayEmpty
                    renderValue={(selected) => {
                      const v = selected === undefined || selected === null ? '' : selected;
                      if (v === '') return 'All Revisions';
                      const found = (revisionEntries || []).find(r => String(r.id) === String(v));
                      return found ? found.revision_year : 'All Revisions';
                    }}
                  >
                    <MenuItem value="" selected={revisionFilter === ''}>
                      <em>All Revisions</em>
                    </MenuItem>
                    {revisionEntries.map((revision) => (
                      <MenuItem key={revision.id} value={revision.id}>
                        {revision.revision_year}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>

                <FormControl size="small" sx={{
                  minWidth: { xs: 120, sm: 120 },
                  width: { xs: '100%', sm: 'auto' },
                }}>
                  <InputLabel shrink>Location</InputLabel>
                  <Select
                    value={locationFilter}
                    onChange={(e) => {
                      const newLoc = e.target.value;
                      setLocationFilter(newLoc);
                      setPage(0);
                      // Immediately fetch with current revision + new location + current kind, class
                      fetchPropertiesWithFilters(revisionFilter, newLoc, kindFilter, classFilter, stateFilter);
                    }}
                    label="Location"
                    displayEmpty
                    renderValue={(selected) => {
                      const v = selected === undefined || selected === null ? '' : selected;
                      if (v === '') return 'All Locations';
                      const found = (locationOptions || []).find(l => String(l.name) === String(v));
                      return found ? found.name : 'All Locations';
                    }}
                  >
                    <MenuItem value="" selected={locationFilter === ''}>
                      <em>All Locations</em>
                    </MenuItem>
                    {locationOptions.map((loc) => (
                      <MenuItem key={loc.code || loc.name} value={loc.name}>
                        {loc.name}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>

                <FormControl size="small" sx={{
                  minWidth: { xs: 120, sm: 120 },
                  width: { xs: '100%', sm: 'auto' },
                }}>
                  <InputLabel shrink>Kind</InputLabel>
                  <Select
                    value={kindFilter}
                    onChange={(e) => {
                      const newKind = e.target.value;
                      setKindFilter(newKind);
                      setPage(0);
                      fetchPropertiesWithFilters(revisionFilter, locationFilter, newKind, classFilter, stateFilter);
                    }}
                    label="Kind"
                    displayEmpty
                    renderValue={(selected) => {
                      const v = selected === undefined || selected === null ? '' : selected;
                      if (v === '') return 'All Kinds';
                      const found = (kindOptions || []).find(k => String(k.code) === String(v));
                      return found ? found.name : 'All Kinds';
                    }}
                  >
                    <MenuItem value="" selected={kindFilter === ''}>
                      <em>All Kinds</em>
                    </MenuItem>
                    {kindOptions.map((k) => (
                      <MenuItem key={k.code || k.name} value={k.code}>
                        {k.name}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>

                <FormControl size="small" sx={{
                  minWidth: { xs: 120, sm: 120 },
                  width: { xs: '100%', sm: 'auto' },
                }}>
                  <InputLabel shrink>Class</InputLabel>
                  <Select
                    value={classFilter}
                    onChange={(e) => {
                      const newClass = e.target.value;
                      setClassFilter(newClass);
                      setPage(0);
                      fetchPropertiesWithFilters(revisionFilter, locationFilter, kindFilter, newClass, stateFilter);
                    }}
                    label="Class"
                    displayEmpty
                    renderValue={(selected) => {
                      const v = selected === undefined || selected === null ? '' : selected;
                      if (v === '') return 'All Classes';
                      const found = (classOptions || []).find(c => String(c.code) === String(v));
                      return found ? found.name : 'All Classes';
                    }}
                  >
                    <MenuItem value="" selected={classFilter === ''}>
                      <em>All Classes</em>
                    </MenuItem>
                    {classOptions.map((c) => (
                      <MenuItem key={c.code || c.name} value={c.code}>
                        {c.name}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>

                {/* State Filter */}
                <FormControl size="small" variant="outlined" sx={{ minWidth: 140 }}>
                  <Select
                    value={stateFilter}
                    onChange={(e) => {
                      const newState = e.target.value;
                      setStateFilter(newState);
                      setPage(0);
                      fetchPropertiesWithFilters(revisionFilter, locationFilter, kindFilter, classFilter, newState);
                    }}
                    displayEmpty
                    renderValue={(selected) => {
                      const v = selected === undefined || selected === null ? '' : selected;
                      if (v === '') return 'All States';
                      return v;
                    }}
                  >
                    <MenuItem value="" selected={stateFilter === ''}>
                      <em>All States</em>
                    </MenuItem>
                    <MenuItem value="CURRENT">CURRENT</MenuItem>
                    <MenuItem value="CANCELLED">CANCELLED</MenuItem>
                    <MenuItem value="INTERIM">INTERIM</MenuItem>
                    <MenuItem value="PENDING">PENDING</MenuItem>
                  </Select>
                </FormControl>


                <ToggleButtonGroup
                  size="small"
                  exclusive
                  value={imageFilter}
                  onChange={handleImageFilterChange}
                  aria-label="Image filter"
                  sx={{
                    height: 40, // same as TextField small height
                    width: { xs: '100%', sm: 'auto' },
                    '& .MuiToggleButton-root': {
                      height: '100%',
                      py: 0.5,
                      flex: { xs: 1, sm: 'none' },
                      minWidth: { xs: '60px', sm: 'auto' },
                      px: 1.5
                    },
                  }}
                >
                  <ToggleButton value="all" aria-label="All">
                    <Box component="span" sx={{ display: { xs: 'none', sm: 'inline' } }}>
                      All
                    </Box>
                    <Box component="span" sx={{ display: { xs: 'inline', sm: 'none' } }}>
                      All
                    </Box>
                  </ToggleButton>
                  <ToggleButton value="with" aria-label="With image">
                    <ImageIcon sx={{ mr: { xs: 0, sm: 0.5 }, color: 'success.main' }} />
                    <Box component="span" sx={{ display: { xs: 'none', sm: 'inline' } }}>
                      {imageCounts.withImg ? ` ${imageCounts.withImg}` : ''}
                    </Box>
                    <Box component="span" sx={{ display: { xs: 'inline', sm: 'none' } }}>
                      {imageCounts.withImg || ''}
                    </Box>
                  </ToggleButton>
                  <ToggleButton value="without" aria-label="Without image">
                    <BrokenImageIcon sx={{ mr: { xs: 0, sm: 0.5 }, color: 'error.main' }} />
                    <Box component="span" sx={{ display: { xs: 'none', sm: 'inline' } }}>
                      {imageCounts.without ? ` ${imageCounts.without}` : ''}
                    </Box>
                    <Box component="span" sx={{ display: { xs: 'inline', sm: 'none' } }}>
                      {imageCounts.without || ''}
                    </Box>
                  </ToggleButton>
                </ToggleButtonGroup>
              </Box>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* Properties Table */}
      <Paper sx={{ width: '100%', display: 'flex', flexDirection: 'column', flexGrow: 1, flexShrink: 1, minHeight: 0, overflow: 'hidden', position: 'relative' }}>
        {loading && (
          <Box sx={{ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', zIndex: 10, pointerEvents: 'none' }}>
            <CircularProgress size={40} sx={{ mb: 2, color: 'primary.main' }} />
            <Typography variant="body1" color="text.primary" sx={{ fontWeight: 600 }}>
              {debouncedSearchTerm ? 'Searching properties...' :
                imageFilter !== 'all' ? 'Filtering properties...' : 'Loading properties...'}
            </Typography>
          </Box>
        )}
        <TableContainer ref={tableContainerRef} sx={{ flexGrow: 1, flexShrink: 1, minHeight: 0, overflow: 'auto' }}>
          <Table stickyHeader sx={{ minWidth: '100rem' }}>
            <TableHead>
              <TableRow>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>Tax Declaration Number</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>PIN</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>State</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>Declarant</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>Barangay</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>Lot Number</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>Survey Number</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>Area</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>Title Number</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>Assessed Value</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>Kind</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>Class</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>Effectivity</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap', minWidth: 200 }}>Memoranda</TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap', position: 'sticky', right: 0, zIndex: 3, backgroundColor: '#f1f5f9', borderLeft: '2px solid #e0e0e0', boxShadow: '-4px 0 8px rgba(0,0,0,0.06)' }}>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody sx={{
              '& td': { verticalAlign: 'top', py: 0.75, whiteSpace: 'nowrap' },
              opacity: loading ? 0.5 : 1,
              pointerEvents: loading ? 'none' : 'auto',
              transition: 'opacity 0.2s ease-in-out'
            }}>
              {pagedProperties && pagedProperties.length > 0 && pagedProperties.map((property) => (
                <TableRow key={property.id} hover>
                  <TableCell sx={{ verticalAlign: 'top' }}>
                    <Typography
                      variant="body2"
                      sx={{ cursor: 'pointer', color: 'primary.main', textDecoration: 'underline', fontWeight: 600, lineHeight: 1.4, display: 'inline' }}
                      onClick={() => handleViewHistory(property.tax_declaration_number)}
                    >
                      {property.tax_declaration_number}
                    </Typography>
                    {property.previous_tax_declaration_number && String(property.previous_tax_declaration_number).includes(';') && (
                      <Box mt={0.5}>
                        <Typography
                          variant="caption"
                          color="white"
                          sx={{ px: 0.75, py: 0.25, borderRadius: 0.5, bgcolor: 'warning.light', fontWeight: 600 }}
                          title={`Prev TD: ${property.previous_tax_declaration_number}`}
                        >
                          Consolidated
                        </Typography>
                      </Box>
                    )}

                    {/* Previous TD - non-consolidated */}
                    {property.previous_tax_declaration_number &&
                      !String(property.previous_tax_declaration_number).includes(';') && (
                        <Typography
                          variant="caption"
                          display="block"
                          sx={{
                            mt: 0.25,
                            color: 'text.secondary',
                            lineHeight: 1.5,
                          }}
                        >
                          <GitBranch
                            className="w-3 h-3"
                            style={{
                              width: 11,
                              height: 11,
                              color: '#2563eb',
                              flexShrink: 0,
                            }}
                          />
                          Prev: {property.previous_tax_declaration_number}
                        </Typography>
                      )}
                  </TableCell>
                  <TableCell sx={{ whiteSpace: 'nowrap' }}>{property.pin || ''}</TableCell>
                  <TableCell>
                    <Chip
                      icon={
                        (property.property_state || 'CURRENT').toUpperCase() === 'CURRENT' ? (
                          <CheckCircle2 size={12} color={getStateColor(property.property_state || 'CURRENT').text} />
                        ) : (
                          <XCircle size={12} color={getStateColor(property.property_state || 'CURRENT').text} />
                        )
                      }
                      label={property.property_state || 'CURRENT'}
                      size="small"
                      sx={{
                        backgroundColor: getStateColor(property.property_state || 'CURRENT').bg,
                        color: getStateColor(property.property_state || 'CURRENT').text,
                        fontWeight: 600,
                        fontSize: '0.7rem',
                        height: 20,
                        '& .MuiChip-icon': {
                          marginLeft: '6px'
                        }
                      }}
                    />
                  </TableCell>
                  <TableCell
                    sx={{
                      width: 320,
                      minWidth: 320,
                      maxWidth: 320,
                      verticalAlign: 'top',
                      whiteSpace: 'normal',
                    }}
                  >
                    {(() => {
                      const declarant = formatDeclarantFromParts(
                        property.declarant_last_name,
                        property.declarant_first_name,
                        property.declarant_middle_initial
                      );

                      const business = property.business_name
                        ? String(property.business_name).replace(/,\s*/g, ' ')
                        : '';

                      const maxLineLength = Math.max(declarant.length, business.length);

                      if (!declarant && !business) {
                        return '—';
                      }

                      return (
                        <Box>
                          {/* Declarant - bold */}
                          {declarant && (
                            <Typography
                              variant="body2"
                              fontWeight="bold"
                              display="block"
                              sx={{
                                whiteSpace: expandedDeclarants[property.id] ? 'normal' : 'nowrap',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                maxWidth: 320,
                                wordBreak: 'break-word'
                              }}
                            >
                              {declarant}
                            </Typography>
                          )}

                          {/* Business - smaller/normal */}
                          {business && (
                            <Typography
                              variant="caption"
                              color="text.secondary"
                              display="block"
                              sx={{
                                whiteSpace: expandedDeclarants[property.id] ? 'normal' : 'nowrap',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                maxWidth: 320,
                              }}
                            >
                              {business}
                            </Typography>
                          )}

                          {/* View / Less */}
                          {maxLineLength > 40 && (
                            <Button
                              size="small"
                              variant="text"
                              onClick={() => toggleDeclarant(property.id)}
                              sx={{
                                textTransform: 'none',
                                p: 0,
                                minWidth: 'auto',
                                fontSize: '0.7rem',
                                mt: 0.25,
                                lineHeight: 1.2,
                              }}
                              startIcon={
                                expandedDeclarants[property.id] ? (
                                  <ExpandLessIcon sx={{ fontSize: 14 }} />
                                ) : (
                                  <ExpandMoreIcon sx={{ fontSize: 14 }} />
                                )
                              }
                            >
                              {expandedDeclarants[property.id] ? 'Less' : 'View'}
                            </Button>
                          )}
                        </Box>
                      );
                    })()}
                  </TableCell>
                  <TableCell>{property.location || '—'}</TableCell>
                  <TableCell>{property.lot_number || '—'}</TableCell>
                  <TableCell>{property.survey_number || '—'}</TableCell>
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
                  })()}</TableCell>
                  <TableCell>{property.title_number || '—'}</TableCell>
                  <TableCell>
                    <Typography variant="body2" color="text.primary">
                      {(() => {
                        const currentValue = property.assessed_value;
                        const oldValue = property.assessed_value_old;
                        const hasCurrent = currentValue !== undefined && currentValue !== null;
                        const hasOld = oldValue && oldValue !== '';

                        if (!hasCurrent && !hasOld) return '₱0.00';

                        let displayValue = '';
                        if (hasCurrent) {
                          displayValue = `₱${Number(currentValue).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                        }

                        if (hasOld && displayValue) {
                          return `${displayValue} ${oldValue}`;
                        } else if (hasOld) {
                          return oldValue;
                        } else {
                          return displayValue || '₱0.00';
                        }
                      })()}
                    </Typography>
                  </TableCell>
                  <TableCell>{property.kind_of_property_name || property.kind_of_property || '—'}</TableCell>
                  <TableCell>{property.gen_class_name || property.gen_class || '—'}</TableCell>
                  <TableCell>{property.effectivity_date || '-'}</TableCell>

                  <TableCell sx={{ minWidth: 200, maxWidth: 640, verticalAlign: 'top', whiteSpace: 'normal' }}>
                    {property.memoranda ? (
                      <Box>
                        <Typography
                          variant="body2"
                          sx={{
                            whiteSpace: expandedMemos[property.id] ? 'pre-wrap' : 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            maxWidth: 500,
                            transition: 'all 0.2s ease'
                          }}
                        >
                          {property.memoranda}
                        </Typography>
                        {property.memoranda.length > 60 && (
                          <Button
                            size="small"
                            onClick={() => toggleMemo(property.id)}
                            sx={{ textTransform: 'none', p: 0, minWidth: 'auto', fontSize: '0.7rem', mt: 0.25, lineHeight: 1.2 }}
                            startIcon={expandedMemos[property.id] ? <ExpandLessIcon sx={{ fontSize: 14 }} /> : <ExpandMoreIcon sx={{ fontSize: 14 }} />}
                          >
                            {expandedMemos[property.id] ? 'Less' : 'View'}
                          </Button>
                        )}
                      </Box>
                    ) : (
                      <Typography variant="body2">-</Typography>
                    )}
                  </TableCell>
                  <TableCell sx={{ verticalAlign: 'top', position: 'sticky', right: 0, zIndex: 1, backgroundColor: '#fff', borderLeft: '2px solid #e0e0e0', boxShadow: '-4px 0 8px rgba(0,0,0,0.06)' }}>
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

                      {canEdit && (
                        <IconButton
                          size="small"
                          onClick={() => handleEditProperty(property)}
                          color="primary"
                          sx={{ p: 0.25 }}
                        >
                          <EditIcon fontSize="small" />
                        </IconButton>
                      )}
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

                      {(() => {
                        const prelim = getImageAttachmentStatus(property);
                        const verified = imageStatusByPropertyId[property.id];
                        const status = verified?.verified ? {
                          hasImages: !!verified.hasImages,
                          hasBrokenLinks: !!verified.hasBrokenLinks,
                          validUrls: prelim.validUrls,
                          brokenUrls: prelim.brokenUrls
                        } : prelim;
                        if (!status.hasImages && !status.hasBrokenLinks) return null;

                        return (
                          <Box display="flex" alignItems="center" gap={0.25}>
                            {status.hasImages && (
                              <IconButton
                                size="small"
                                color="success"
                                sx={{ p: 0.25 }}
                                title={`View ${status.validUrls} image${status.validUrls > 1 ? 's' : ''}`}
                                onClick={() => handleViewImages(property)}
                              >
                                <ImageIcon fontSize="small" />
                              </IconButton>
                            )}
                            {status.hasBrokenLinks && (
                              <IconButton
                                size="small"
                                color="error"
                                sx={{ p: 0.25 }}
                                title={`Has ${status.brokenUrls} broken link${status.brokenUrls > 1 ? 's' : ''}`}
                              >
                                <BrokenImageIcon fontSize="small" />
                              </IconButton>
                            )}
                          </Box>
                        );
                      })()}
                    </Box>
                  </TableCell>
                </TableRow>
              ))}
              {!loading && (!pagedProperties || pagedProperties.length === 0) && (
                <TableRow>
                  <TableCell colSpan={14} sx={{ border: 'none', p: 0 }}>
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
                        <Typography variant="h6" color="text.secondary" sx={{ mb: 0.5, fontWeight: 500 }}>
                          No properties found
                        </Typography>
                        <Typography variant="body2" color="text.disabled">
                          Try adjusting your search or filters
                        </Typography>
                      </Box>
                    </Box>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </TableContainer>

        <TablePagination
          sx={{ flexShrink: 0 }}
          rowsPerPageOptions={[20, 50, 100]}
          component="div"
          count={(debouncedSearchTerm || imageFilter !== 'all') ? filteredProperties.length : totalCount}
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
            if (['jpg', 'jpeg', 'png', 'gif'].includes(t)) {
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
              <Table size="small" stickyHeader sx={{ tableLayout: 'fixed' }}>
                <TableBody sx={{ '& td': { borderBottom: 'none', padding: { xs: '3px 8px', md: '4px 12px' } } }}>
                  <TableRow sx={{ '& td': { borderBottom: 'none' } }}>
                    <TableCell><strong>TAX DECLARATION NUMBER:</strong> {taxHistory[0]?.tax_declaration_number}</TableCell>
                    <TableCell><strong>PIN:</strong> {taxHistory[0]?.pin}</TableCell>
                  </TableRow>
                  <TableRow sx={{ '& td': { borderBottom: 'none' } }}>
                    <TableCell sx={{ verticalAlign: 'top', whiteSpace: 'normal', wordBreak: 'break-word' }}><strong>OWNER:</strong> {normalizeDeclarantString(taxHistory[0]?.declarant_name) || ''}</TableCell>
                    <TableCell sx={{ verticalAlign: 'top', whiteSpace: 'normal', wordBreak: 'break-word' }}>
                      <strong>ADDRESS:</strong>{' '}
                      <span style={{ whiteSpace: 'normal', wordBreak: 'break-word', overflowWrap: 'anywhere' }}>
                        {taxHistory[0]?.address}
                      </span>
                    </TableCell>
                  </TableRow>
                  <TableRow sx={{ '& td': { borderBottom: 'none' } }}>
                    <TableCell><strong>ADMINISTRATOR/BUSINESS NAME:</strong> {sanitizeBusinessName(taxHistory[0]?.business_name) || ''}</TableCell>
                    <TableCell><strong>ASSESSMENT DATE:</strong> {taxHistory[0]?.assessment_date}</TableCell>
                  </TableRow>
                  <TableRow sx={{ '& td': { borderBottom: 'none' } }}>
                    <TableCell><strong>LOCATION:</strong> {taxHistory[0]?.location}</TableCell>
                    <TableCell><strong>KIND OF PROPERTY:</strong> {taxHistory[0]?.kind_of_property_name || taxHistory[0]?.kind_of_property}</TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell><strong>EFFECTIVITY:</strong> {taxHistory[0]?.effectivity_date}</TableCell>
                    <TableCell><strong>GEN. CLASS:</strong> {taxHistory[0]?.gen_class_name || taxHistory[0]?.gen_class}</TableCell>
                  </TableRow>
                </TableBody>
              </Table>
              <Table size="small" stickyHeader>
                <TableHead>
                  <TableRow>
                    <TableCell>Tax Declaration Number</TableCell>
                    <TableCell>Declarant</TableCell>
                    <TableCell>Barangay</TableCell>
                    <TableCell>Lot Number</TableCell>
                    <TableCell>Area</TableCell>
                    <TableCell>Title Number</TableCell>
                    <TableCell>Assessed Value</TableCell>
                    <TableCell>Effectivity</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody sx={{ '& td': { verticalAlign: 'top' } }}>
                  {taxHistory.map((item, index) => {
                    // Check if this TDN is consolidated (appears in another item's previous_tax_declaration_number)
                    const wasConsolidatedInto = taxHistory.some(otherItem => {
                      if (otherItem.previous_tax_declaration_number && String(otherItem.previous_tax_declaration_number).includes(';')) {
                        const prevTds = String(otherItem.previous_tax_declaration_number).split(';').map(td => String(td).trim());
                        return prevTds.includes(String(item.tax_declaration_number).trim());
                      }
                      return false;
                    });
                    // Check if this TDN is a consolidated TD (has previous_tax_declaration_number with semicolons)
                    const isConsolidatedTD = item.previous_tax_declaration_number && String(item.previous_tax_declaration_number).includes(';');
                    const isConsolidated = wasConsolidatedInto || isConsolidatedTD;

                    return (
                      <TableRow key={index} hover>
                        <TableCell>
                          <Typography variant="body2" fontWeight={600} color={isConsolidated ? "warning.main" : "primary"}>
                            {item.tax_declaration_number}
                          </Typography>
                          <Typography variant="caption" color="text.secondary" sx={{ textTransform: 'capitalize' }}>
                            {item.property_state ? item.property_state.toLowerCase() : (index === 0 ? 'current' : 'previous')}
                          </Typography>
                          <Typography variant="caption" color="text.secondary">
                            {item.previous_tax_declaration_number && String(item.previous_tax_declaration_number).includes(';') ? (
                              <Box mt={0.5}>
                                <Typography variant="caption" color="white" bgcolor="warning.light" sx={{ px: 0.75, py: 0.25, borderRadius: 0.5, fontWeight: 600 }}>
                                  Consolidated
                                </Typography>
                              </Box>
                            ) : null}
                          </Typography>
                        </TableCell>
                        <TableCell>
                          {(() => {
                            const d = normalizeDeclarantString(item.declarant_name);
                            const b = item.business_name
                              ? String(item.business_name).replace(/,\s*/g, ' ')
                              : '';

                            if (!d && !b) return '—';

                            return (
                              <>
                                {d && (
                                  <Typography variant="body" fontWeight="bold">
                                    {d}
                                  </Typography>
                                )}
                                {b && (
                                  <Typography variant="caption" color="text.secondary" display="block">
                                    {b}
                                  </Typography>
                                )}
                              </>
                            );
                          })()}
                        </TableCell>
                        <TableCell>{item.location || '—'}</TableCell>
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
                        })()}</TableCell>
                        <TableCell sx={{ whiteSpace: 'normal', wordBreak: 'break-word' }}>{item.title_number || '—'}</TableCell>
                        <TableCell>
                          {(() => {
                            const currentValue = item.assessed_value;
                            const oldValue = item.assessed_value_old;
                            const hasCurrent = currentValue !== undefined && currentValue !== null;
                            const hasOld = oldValue && oldValue !== '';

                            if (!hasCurrent && !hasOld) return '₱0.00';

                            let displayValue = '';
                            if (hasCurrent) {
                              displayValue = `₱${Number(currentValue).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                            }

                            if (hasOld && displayValue) {
                              return `${displayValue} ${oldValue}`;
                            } else if (hasOld) {
                              return oldValue;
                            } else {
                              return displayValue || '₱0.00';
                            }
                          })()}
                        </TableCell>
                        <TableCell>{item.effectivity_date || '—'}</TableCell>
                      </TableRow>
                    );
                  })}
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
        PaperProps={{ sx: { maxWidth: '80vw', height: '90vh' } }}
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
                <TableContainer component={Paper}>
                  {(() => {
                    const isSmallScreen = (() => {
                      try { const w = window.innerWidth; const h = window.innerHeight; return (w <= 1280 && h <= 720) || (w <= 1366 && h <= 768) || (w <= 1920 && h <= 1080); } catch (_) { return false; }
                    })();
                    return (
                      <div className="print-header" style={{ textAlign: 'center', fontFamily: 'Times New Roman, sans-serif' }}>
                        {appLogoUrl ? (
                          <img src={appLogoUrl} alt="Logo" style={{ height: isSmallScreen ? 48 : 64, display: 'block', margin: isSmallScreen ? '0 auto 6px auto' : '0 auto 8px auto' }} onError={(e) => { e.currentTarget.style.display = 'none'; }} />
                        ) : null}
                        <h4 style={{ fontSize: isSmallScreen ? 14 : 16, margin: '-6px 0', fontWeight: 400 }}>{headerPh}</h4>
                        <h4 style={{ fontSize: isSmallScreen ? 14 : 16, margin: '-6px 0', fontWeight: 400 }}>{headerProvince}</h4>
                        <h4 style={{ fontSize: isSmallScreen ? 14 : 16, margin: '-6px 0', fontWeight: 400 }}>{headerMunicipality}</h4>
                        <h3 style={{ fontSize: isSmallScreen ? 14 : 16, margin: '-6px 0', fontWeight: 600 }}>{headerOffice}</h3>
                        <div style={{ marginTop: isSmallScreen ? 6 : 8, marginBottom: isSmallScreen ? 10 : 15, fontWeight: 700, textDecoration: 'underline', fontFamily: 'Tahoma, serif', fontSize: isSmallScreen ? 13 : 14 }}>{headerTitle}</div>
                      </div>
                    );
                  })()}
                  <Table size="small" stickyHeader sx={{ tableLayout: 'fixed' }}>
                    <TableBody sx={{ '& td': { borderBottom: 'none', padding: { xs: '3px 8px', md: '4px 12px' } } }}>
                      <TableRow sx={{ '& td': { paddingTop: '12px' } }}>
                        <TableCell><strong>TAX DECLARATION NUMBER:</strong> {printHistory[0].tax_declaration_number}</TableCell>
                        <TableCell><strong>PIN:</strong> {printHistory[0].pin}</TableCell>
                      </TableRow>
                      <TableRow>
                        <TableCell sx={{ whiteSpace: 'normal', wordBreak: 'break-word' }}><strong>OWNER:</strong> {normalizeDeclarantString(printHistory[0].declarant_name) || ''}</TableCell>
                        <TableCell sx={{ whiteSpace: 'normal', wordBreak: 'break-word' }}>
                          <strong>ADDRESS:</strong>{' '}
                          <span style={{ whiteSpace: 'normal', wordBreak: 'break-word', overflowWrap: 'anywhere' }}>
                            {printHistory[0].address}
                          </span>
                        </TableCell>
                      </TableRow>
                      <TableRow>
                        <TableCell><strong>ADMINISTRATOR/BUSINESS NAME:</strong> {sanitizeBusinessName(printHistory[0].business_name) || ''}</TableCell>
                        <TableCell><strong>ASSESSMENT DATE:</strong> {printHistory[0].assessment_date}</TableCell>
                      </TableRow>
                      <TableRow>
                        <TableCell><strong>LOCATION:</strong> {printHistory[0].location}</TableCell>
                        <TableCell><strong>KIND OF PROPERTY:</strong> {printHistory[0].kind_of_property_name || printHistory[0].kind_of_property}</TableCell>
                      </TableRow>
                      <TableRow sx={{ '& td': { paddingBottom: '12px' } }}>
                        <TableCell><strong>EFFECTIVITY:</strong> {printHistory[0].effectivity_date}</TableCell>
                        <TableCell><strong>GEN. CLASS:</strong> {printHistory[0].gen_class_name || printHistory[0].gen_class}</TableCell>
                      </TableRow>
                    </TableBody>
                  </Table>
                  <Table size="small" stickyHeader>
                    {/* <colgroup>
                  <col style={{ width: '15%' }} />
                  <col style={{ width: '12%' }} />
                  <col style={{ width: '6%' }} />
                  <col style={{ width: '9%' }} />
                  <col style={{ width: '9%' }} />
                  <col style={{ width: '11%' }} />
                  <col style={{ width: '9%' }} />
                  <col style={{ width: '28%' }} />
                </colgroup> */}
                    <TableHead>
                      <TableRow>
                        <TableCell>Tax Declaration Number</TableCell>
                        <TableCell>Declarant</TableCell>
                        <TableCell>Barangay</TableCell>
                        <TableCell>Lot Number</TableCell>
                        <TableCell>Survey Number</TableCell>
                        <TableCell>Area</TableCell>
                        <TableCell>Title Number</TableCell>
                        <TableCell>Assessed Value</TableCell>
                        <TableCell>Effectivity</TableCell>
                        <TableCell>Memoranda</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody sx={{ '& td': { verticalAlign: 'top' } }}>
                      {printHistory.map((item, index) => {
                        // Check if this TDN is consolidated (appears in another item's previous_tax_declaration_number)
                        const wasConsolidatedInto = printHistory.some(otherItem => {
                          if (otherItem.previous_tax_declaration_number && String(otherItem.previous_tax_declaration_number).includes(';')) {
                            const prevTds = String(otherItem.previous_tax_declaration_number).split(';').map(td => String(td).trim());
                            return prevTds.includes(String(item.tax_declaration_number).trim());
                          }
                          return false;
                        });
                        // Check if this TDN is a consolidated TD (has previous_tax_declaration_number with semicolons)
                        const isConsolidatedTD = item.previous_tax_declaration_number && String(item.previous_tax_declaration_number).includes(';');
                        const isConsolidated = wasConsolidatedInto || isConsolidatedTD;

                        return (
                          <TableRow key={index} hover>
                            <TableCell>
                              <Typography
                                variant="body2"
                                fontWeight={600}
                                color={isConsolidated ? "warning.main" : "primary"}
                              >
                                {item.tax_declaration_number}
                              </Typography>
                              <Typography variant="caption" color="text.secondary" sx={{ textTransform: 'capitalize' }}>
                                {item.property_state ? item.property_state.toLowerCase() : (index === 0 ? 'current' : 'previous')}
                              </Typography>
                              <Typography variant="caption" color="text.secondary">
                                {item.previous_tax_declaration_number && String(item.previous_tax_declaration_number).includes(';') ? (
                                  <Box mt={0.5}>
                                    <Typography variant="caption" color="white" bgcolor="warning.light" sx={{ px: 0.75, py: 0.25, borderRadius: 0.5, fontWeight: 600 }}>
                                      Consolidated
                                    </Typography>
                                  </Box>
                                ) : null}
                              </Typography>
                            </TableCell>
                            <TableCell>
                              {(() => {
                                const d = normalizeDeclarantString(item.declarant_name);
                                const b = item.business_name
                                  ? String(item.business_name).replace(/,\s*/g, ' ')
                                  : '';

                                if (!d && !b) return '—';

                                return (
                                  <>
                                    {d && (
                                      <Typography variant='body' fontWeight='bold'>
                                        {d}
                                      </Typography>
                                    )}
                                    {b && (
                                      <Typography variant="caption" color="text.secondary" display="block">
                                        {b}
                                      </Typography>
                                    )}
                                  </>
                                );
                              })()}
                            </TableCell>
                            <TableCell>{item.location || '—'}</TableCell>
                            <TableCell>{item.lot_number || '—'}</TableCell>
                            <TableCell>{item.survey_number || '—'}</TableCell>
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
                            })()}</TableCell>
                            <TableCell>{item.title_number || '—'}</TableCell>
                            <TableCell>
                              {(() => {
                                const currentValue = item.assessed_value;
                                const oldValue = item.assessed_value_old;
                                const hasCurrent = currentValue !== undefined && currentValue !== null;
                                const hasOld = oldValue && oldValue !== '';

                                if (!hasCurrent && !hasOld) return '₱0.00';

                                let displayValue = '';
                                if (hasCurrent) {
                                  displayValue = `₱${Number(currentValue).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                                }

                                if (hasOld && displayValue) {
                                  return `${displayValue} ${oldValue}`;
                                } else if (hasOld) {
                                  return oldValue;
                                } else {
                                  return displayValue || '₱0.00';
                                }
                              })()}
                            </TableCell>
                            <TableCell>{item.effectivity_date || '—'}</TableCell>
                            <TableCell sx={{ maxWidth: 280 }}>
                              <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', overflowWrap: 'anywhere' }}>
                                {item.memoranda || '—'}
                              </Typography>
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </TableContainer>
                {/* Attached Documents Section (Preview Only, non-sticky; included in scroll area) */}
                {Array.isArray(printDocuments) && printDocuments.length > 0 && (
                  <Box sx={{ p: 1, backgroundColor: 'background.paper' }}>
                    <Typography variant="caption" sx={{ fontWeight: 700, mb: 0.5 }}>
                      Attached Documents
                    </Typography>
                    {(() => {
                      const isLegacy = (d) => String(d?.id || '').startsWith('legacy-') || String(d?.description || '') === 'Legacy document';
                      const managedDocs = (printDocuments || []).filter(d => !isLegacy(d));
                      const legacyDocs = (printDocuments || []).filter(d => isLegacy(d));

                      const isImage = (ext) => ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(String(ext || '').toLowerCase());

                      const renderThumbnails = (docs) => {
                        const images = docs.filter(d => isImage(d.file_type));
                        if (!images.length) return null;
                        return (
                          <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1 }}>
                            {images.map((doc) => (
                              <Box
                                key={doc.id}
                                sx={{
                                  width: 128,
                                  height: 128,
                                  border: '1px solid #ddd',
                                  borderRadius: 1,
                                  overflow: 'hidden',
                                  cursor: 'pointer',
                                  '&:hover': { borderColor: 'primary.main', boxShadow: 1 }
                                }}
                                onClick={() => setPrintDocPreview({ open: true, src: doc.file_url, filename: doc.original_filename || doc.filename, type: String(doc.file_type || '').toLowerCase() })}
                                title={doc.original_filename || doc.filename}
                              >
                                <img
                                  src={doc.file_url}
                                  alt={doc.original_filename || doc.filename}
                                  style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }}
                                  onError={(e) => { e.currentTarget.style.display = 'none'; e.currentTarget.nextSibling.style.display = 'flex'; }}
                                />
                                <Box sx={{ display: 'none', alignItems: 'center', justifyContent: 'center', height: '100%', color: 'text.secondary', backgroundColor: 'grey.100' }}>
                                  <Typography variant="caption">Failed to load</Typography>
                                </Box>
                              </Box>
                            ))}
                          </Box>
                        );
                      };

                      const renderLinks = (docs) => (
                        <Box sx={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 0.5 }}>
                          {docs.map((doc) => {
                            const ext = String(doc.file_type || '').toLowerCase();
                            if (isImage(ext)) return null; // images handled by thumbnails
                            return (
                              <Box key={doc.id} sx={{ display: 'inline-flex', alignItems: 'center' }}>
                                <Button
                                  size="small"
                                  variant="text"
                                  onClick={() => setPrintDocPreview({ open: true, src: doc.file_url, filename: doc.original_filename || doc.filename, type: ext })}
                                  sx={{ minWidth: 0, p: 0.25, fontSize: '0.75rem', textTransform: 'none' }}
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
                          {/* Managed docs: thumbnails for images, links for others */}
                          {renderThumbnails(managedDocs)}
                          {renderLinks(managedDocs)}

                          {/* Legacy docs section (if any) */}
                          {legacyDocs.length > 0 && (
                            <>
                              <Typography variant="caption" color="text.secondary" sx={{ mt: 1, display: 'block' }}>
                                Legacy documents
                              </Typography>
                              {renderThumbnails(legacyDocs)}
                              {renderLinks(legacyDocs)}
                            </>
                          )}
                        </>
                      );
                    })()}
                  </Box>
                )}
              </Box>
            </Box>
          ) : (
            <Typography>No history found for this tax declaration number.</Typography>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setPrintModal(false)}>Close</Button>
          <Button onClick={handlePrint} startIcon={<PrintIcon />} variant="contained" disabled={isViewer || !printHistory.length || !printRef.current}>
            Print
          </Button>
        </DialogActions>
      </Dialog>
      {/* Property Images Modal */}
      <Dialog
        open={imageModal}
        onClose={() => setImageModal(false)}
        maxWidth="md"
        fullWidth
      // PaperProps={{ sx: { maxWidth: '90vw', height: '90vh' } }}
      >
        <DialogTitle>
          Property Images
        </DialogTitle>
        <DialogContent sx={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
          {propertyImages.length > 0 ? (
            <Box
              sx={{
                display: 'flex',
                flexWrap: 'wrap',
                gap: 2,
                flex: 1,
                overflow: 'auto',
                p: 1
              }}
            >
              {propertyImages.map((image) => (
                <Box
                  key={image.id}
                  sx={{
                    position: 'relative',
                    width: 192,
                    height: 192,
                    border: '1px solid #ddd',
                    borderRadius: 1,
                    overflow: 'hidden',
                    cursor: 'pointer',
                    '&:hover': {
                      borderColor: 'primary.main',
                      boxShadow: 2
                    }
                  }}
                  onClick={() => setMaximizedImage({
                    open: true,
                    src: image.url,
                    alt: image.alt
                  })}
                >
                  <img
                    src={image.url}
                    alt={image.alt}
                    style={{
                      width: '100%',
                      height: '100%',
                      objectFit: 'cover',
                      display: 'block'
                    }}
                    onError={(e) => {
                      e.currentTarget.style.display = 'none';
                      e.currentTarget.nextSibling.style.display = 'flex';
                    }}
                  />
                  <Box
                    sx={{
                      position: 'absolute',
                      top: 0,
                      left: 0,
                      right: 0,
                      bottom: 0,
                      display: 'none',
                      alignItems: 'center',
                      justifyContent: 'center',
                      backgroundColor: 'grey.100',
                      color: 'text.secondary'
                    }}
                  >
                    <Typography variant="body2">Failed to load</Typography>
                  </Box>
                  <Box
                    sx={{
                      position: 'absolute',
                      bottom: 0,
                      left: 0,
                      right: 0,
                      backgroundColor: 'rgba(0,0,0,0.7)',
                      color: 'white',
                      p: 0.5
                    }}
                  >
                    <Typography variant="caption" noWrap>
                      {image.name}
                    </Typography>
                  </Box>
                </Box>
              ))}
            </Box>
          ) : (
            <Box display="flex" justifyContent="center" alignItems="center" flex={1}>
              <Typography variant="body2" color="text.secondary">
                No images found for this property
              </Typography>
            </Box>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setImageModal(false)}>Close</Button>
        </DialogActions>
      </Dialog>

      {/* Maximized Image Modal */}
      <Dialog
        open={maximizedImage.open}
        onClose={() => setMaximizedImage({ open: false, src: '', alt: '' })}
        maxWidth="md"
        fullWidth
      // PaperProps={{ sx: { maxWidth: '95vw', height: '95vh' } }}
      >
        <DialogTitle>
          {maximizedImage.alt}
        </DialogTitle>
        <DialogContent sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', p: 0 }}>
          <img
            src={maximizedImage.src}
            alt={maximizedImage.alt}
            style={{
              maxWidth: '100%',
              maxHeight: '100%',
              objectFit: 'contain'
            }}
            onError={(e) => {
              e.currentTarget.style.display = 'none';
              e.currentTarget.nextSibling.style.display = 'flex';
            }}
          />
          <Box
            sx={{
              display: 'none',
              flexDirection: 'column',
              alignItems: 'center',
              justifyContent: 'center',
              height: '50vh',
              color: 'text.secondary'
            }}
          >
            <BrokenImageIcon sx={{ fontSize: 48, mb: 2 }} />
            <Typography variant="h6">Failed to load image</Typography>
            <Typography variant="body2">The image could not be displayed</Typography>
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setMaximizedImage({ open: false, src: '', alt: '' })}>Close</Button>
        </DialogActions>
      </Dialog>

      {/* Hidden printable content for react-to-print */}
      <div style={{ position: 'fixed', left: '-10000px', top: 0 }}>
        <HistoryPrintDocument ref={printRef} settings={settings} printHistory={printHistory} requestData={printRequestData} documentType="property" />
        <div className="print-page-footer"><span className="pageNumber" /></div>
      </div>
    </Box>
  );
};

export default PropertyTable;
