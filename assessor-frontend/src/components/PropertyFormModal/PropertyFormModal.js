import React, { useState, useEffect } from 'react';
import {
  Box,
  TextField,
  Grid,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  Button,
  Typography,
  Alert,
  Divider,
  Snackbar,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  IconButton,
  List,
  ListItem,
  ListItemText,
  ListItemSecondaryAction,
  Chip,
  Tooltip,
  CircularProgress,
  Checkbox,
  FormControlLabel
} from '@mui/material';
import { useTheme } from '@mui/material/styles';
import { motion } from 'framer-motion';
import {
  CloudUpload,
  Settings as SettingsIcon,
  Delete as DeleteIcon,
  Edit as EditIcon,
  HomeWork as PropertyIcon,
  Close as CloseIcon,
  Save as SaveIcon,
  Badge as BadgeIcon,
  Person as PersonIcon,
  LocationOn as LocationIcon,
  Calculate as ValuationIcon,
  Event as EffectivityIcon,
  Notes as NotesIcon,
  AttachFile as AttachFileIcon,
  InsertDriveFile as FileIcon,
  Visibility as VisibilityIcon,
  AccountTree as RevisionIcon
} from '@mui/icons-material';

import { apiService, uploadFile } from '../../utils/api';
import useLoadingWatchdog from '../../hooks/useLoadingWatchdog';

// Reusable standard section container matching RequestFormModal styling
const FormSection = ({ icon: Icon, title, subtitle, children, sx = {}, error = false }) => {
  const theme = useTheme();
  return (
    <Box
      sx={{
        borderRadius: 1,
        border: 1,
        borderColor: error ? theme.palette.error.main : theme.palette.divider,
        backgroundColor: theme.palette.background.paper,
        p: { xs: 1.5, sm: 2 },
        ...sx
      }}
    >
      <Box sx={{ mb: 2 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          {Icon && (
            <Icon
              sx={{
                fontSize: 18,
                color: theme.palette.primary.main
              }}
            />
          )}
          <Typography
            variant="subtitle2"
            sx={{
              fontWeight: 700,
              textTransform: 'uppercase',
              letterSpacing: '0.04em',
              color: theme.palette.primary.main,
              lineHeight: 1.2
            }}
          >
            {title}
          </Typography>
        </Box>
        {subtitle && (
          <Typography
            variant="caption"
            sx={{
              color: theme.palette.text.secondary,
              display: 'block',
              mt: 0.25,
              pl: Icon ? 3.25 : 0
            }}
          >
            {subtitle}
          </Typography>
        )}
      </Box>
      {children}
    </Box>
  );
};

const PropertyFormModal = ({ property, onSave, onCancel, open, onClose }) => {
  const theme = useTheme();
  const isEffectivityExemptValue = (value) =>
    value === true ||
    value === 1 ||
    value === '1';

  // Extract a reliable 4-digit year from various backend formats
  const extractEffectivityYear = (raw) => {
    if (!raw) return '';
    const s = String(raw).trim();
    // If already a 4-digit year, return as-is
    const yOnly = s.match(/^\d{4}$/);
    if (yOnly) return yOnly[0];
    // Try to capture a 4-digit year anywhere in the string (e.g., 2025-01-01)
    const yInString = s.match(/(\d{4})/);
    if (yInString) return yInString[1];
    // Last resort: Date parse, but avoid timezone off-by-one by using UTC methods
    const d = new Date(s);
    if (!isNaN(d.getTime())) return String(d.getUTCFullYear());
    return '';
  };
  const [formData, setFormData] = useState({
    tax_declaration_number: '',
    previous_tax_declaration_number: '',
    revision_id: '',
    declarant_last_name: '',
    declarant_first_name: '',
    declarant_middle_initial: '',
    business_name: '',
    location: '',
    lot_number: '',
    survey_number: '',
    area_hectare: '',
    area_sqm: '',
    area_unit: 'hectares',
    title_number: '',
    assessed_value: '',
    assessed_value_old: '',
    effectivity_date: '',
    effectivity_exempt: false,
    pin: '',
    address: '',
    assessment_date: '',
    kind_of_property: '',
    gen_class: '',
    memoranda: '',
    property_state: 'CURRENT',
    supporting_documents: []
  });

  const [toast, setToast] = useState({ open: false, message: '', severity: 'success' });
  const [loading, setLoading] = useState(false);
  const [duplicateTdnError, setDuplicateTdnError] = useState(false);
  const [revisionEntries, setRevisionEntries] = useState([]);
  const [revisionLoading, setRevisionLoading] = useState(false);
  const [revisionError, setRevisionError] = useState(null);

  // Preference state: Use last selected revision for new entries
  const getStoredUseLastRevisionPref = () => {
    try {
      const val = localStorage.getItem('assessor_use_last_selected_revision');
      return val === 'true';
    } catch (_) {
      return false;
    }
  };

  const getStoredLastRevisionId = () => {
    try {
      return localStorage.getItem('assessor_last_selected_revision_id') || '';
    } catch (_) {
      return '';
    }
  };

  const [useLastRevision, setUseLastRevision] = useState(getStoredUseLastRevisionPref);
  const [propertyTypeOptions, setPropertyTypeOptions] = useState([]);
  const [generalClassOptions, setGeneralClassOptions] = useState([]);
  const [locationOptions, setLocationOptions] = useState([]);
  const [existingDocuments, setExistingDocuments] = useState([]);
  const [docPreview, setDocPreview] = useState({ open: false, src: '', filename: '' });
  const [pendingUploads, setPendingUploads] = useState([]);
  const [documentsToDelete, setDocumentsToDelete] = useState([]);
  const [optionsLoading, setOptionsLoading] = useState(true);
  const [optionsError, setOptionsError] = useState(null);
  const [memorandaTemplates, setMemorandaTemplates] = useState([]);
  const [manageTemplatesOpen, setManageTemplatesOpen] = useState(false);
  const [editingTemplateId, setEditingTemplateId] = useState(null);

  // Save verification state for network-related uncertainties
  const [saveVerify, setSaveVerify] = useState({ pending: false, tdn: '', checking: false });

  // Safety watchdog for options loading while modal is open
  useLoadingWatchdog({
    isLoading: optionsLoading && open,
    isInitialLoad: optionsLoading && open,
    setLoading: setOptionsLoading,
    setError: setOptionsError,
    componentName: 'PropertyFormModal',
    timeoutMs: 20000,
    timeoutMessage: 'Form options failed to load in time. Please retry.',
    enabled: true,
    dependencies: [open]
  });

  // Keyboard navigation state for multi-character typing
  const [keyboardBuffer, setKeyboardBuffer] = useState({
    location: { buffer: '', timestamp: 0 },
    kind_of_property: { buffer: '', timestamp: 0 },
    gen_class: { buffer: '', timestamp: 0 }
  });

  // Keyboard navigation for dropdowns with multi-character support
  const handleKeyboardNavigation = (options, currentValue, keyPressed, valueKey = null, fieldName = '') => {
    if (!options || options.length === 0) return currentValue;

    const now = Date.now();
    const key = keyPressed.toLowerCase();
    const getValue = (option) => valueKey ? option[valueKey] : option;
    const getLabel = (option) => valueKey ? option.name : option;

    // Update buffer - reset if more than 1 second has passed
    const currentBuffer = keyboardBuffer[fieldName] || { buffer: '', timestamp: 0 };
    const timeDiff = now - currentBuffer.timestamp;
    const newBuffer = timeDiff > 1000 ? key : currentBuffer.buffer + key;

    // Update the buffer state
    setKeyboardBuffer(prev => ({
      ...prev,
      [fieldName]: { buffer: newBuffer, timestamp: now }
    }));

    // Find options that start with the current buffer
    const matchingOptions = options.filter(option =>
      getLabel(option).toLowerCase().startsWith(newBuffer)
    );

    if (matchingOptions.length === 0) {
      // If no matches with full buffer, try just the new key
      const singleKeyMatches = options.filter(option =>
        getLabel(option).toLowerCase().startsWith(key)
      );
      if (singleKeyMatches.length > 0) {
        // Reset buffer to just the new key
        setKeyboardBuffer(prev => ({
          ...prev,
          [fieldName]: { buffer: key, timestamp: now }
        }));
        return getValue(singleKeyMatches[0]);
      }
      return currentValue;
    }

    // If current value matches the buffer and we have multiple matches, cycle through them
    const currentLabel = getLabel(options.find(opt => getValue(opt) === currentValue))?.toLowerCase() || '';
    if (currentLabel.startsWith(newBuffer) && matchingOptions.length > 1) {
      const currentMatchIndex = matchingOptions.findIndex(option => getValue(option) === currentValue);
      if (currentMatchIndex !== -1) {
        const nextMatchIndex = (currentMatchIndex + 1) % matchingOptions.length;
        return getValue(matchingOptions[nextMatchIndex]);
      }
    }

    // Otherwise, jump to first matching option
    return getValue(matchingOptions[0]);
  };

  // Parse legacy pipe- or comma-separated URLs from supporting_documents_old (and supporting_documents if needed)
  const parseLegacySupportingDocuments = (prop) => {
    if (!prop) return [];
    const sources = [prop.supporting_documents_old, prop.supporting_documents]
      .filter(Boolean)
      .map(String)
      .join(' | ');
    if (!sources) return [];
    // Split on pipe or comma and trim
    const parts = sources
      .split(/\||,/)
      .map(s => String(s).trim())
      .filter(s => s && /^https?:\/\//i.test(s));
    const toExt = (url) => {
      try {
        const path = url.split('?')[0];
        const ext = path.split('.').pop().toLowerCase();
        return ext || '';
      } catch (_) { return ''; }
    };
    const toName = (url) => {
      try {
        const path = url.split('?')[0];
        return decodeURIComponent(path.substring(path.lastIndexOf('/') + 1));
      } catch (_) { return url; }
    };
    return parts.map((url, idx) => ({
      id: null, // legacy entry, not deletable via API
      file_url: url,
      file_type: toExt(url),
      original_filename: toName(url),
      filename: toName(url),
      description: 'Legacy document'
    }));
  };

  // Helper function to validate and clean assessment date
  const cleanAssessmentDate = (dateValue) => {
    if (!dateValue || dateValue.trim() === '') return null;

    // Check if it's a valid date format (YYYY-MM-DD)
    const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
    if (!dateRegex.test(dateValue)) return null;

    // Check if it's a valid date
    const date = new Date(dateValue);
    if (isNaN(date.getTime())) return null;

    return dateValue;
  };

  // Helper function to format assessment date for form display
  const formatAssessmentDateForForm = (dateValue) => {
    if (!dateValue) return '';

    // If it's already in YYYY-MM-DD format, return as-is
    if (typeof dateValue === 'string' && dateValue.match(/^\d{4}-\d{2}-\d{2}$/)) {
      return dateValue;
    }

    // If it's a full datetime string, extract just the date part
    if (typeof dateValue === 'string' && dateValue.length >= 10) {
      const datePart = dateValue.slice(0, 10);
      // Validate the extracted date
      return cleanAssessmentDate(datePart) ? datePart : '';
    }

    // Try to parse and format the date
    try {
      const date = new Date(dateValue);
      if (!isNaN(date.getTime())) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
      }
    } catch (e) {
      // If parsing fails, return empty string
    }

    return '';
  };

  const normalizeEffectivityYearForForm = (value) => {
    if (value === null || value === undefined) {
      return '';
    }

    const raw = String(value).trim();

    if (raw === '') {
      return '';
    }

    if (/^exempt$/i.test(raw)) {
      return '';
    }

    return /^\d{4}$/.test(raw) ? raw : '';
  };

  useEffect(() => {
    if (property) {
      const effectivityIsExempt =
        isEffectivityExemptValue(property.effectivity_exempt) ||
        /^exempt$/i.test(
          String(property.effectivity_date ?? '').trim()
        );

      setFormData({
        tax_declaration_number: property.tax_declaration_number || '',
        previous_tax_declaration_number: property.previous_tax_declaration_number || '',
        revision_id: property.revision_id || '',
        property_state: property.property_state || 'CURRENT',
        declarant_last_name: property.declarant_last_name || '',
        declarant_first_name: property.declarant_first_name || '',
        declarant_middle_initial: property.declarant_middle_initial || '',
        business_name: property.business_name || '',
        location: property.location || '',
        lot_number: property.lot_number || '',
        survey_number: property.survey_number || property.unique_lot_number_identified || '',
        area_hectare: (() => {
          const v = property.area_hectare;
          const n = Number(v);
          const hasHa = (v !== undefined && v !== null && v !== '' && !isNaN(n) && n > 0);
          // Only load hectares if the unit is hectares
          if (hasHa) {
            const unit = (() => {
              const numSqm = Number(property.area_sqm);
              const numHa = Number(property.area_hectare);
              const hasSqmInner = property.area_sqm !== undefined && property.area_sqm !== null && property.area_sqm !== '' && !isNaN(numSqm) && numSqm > 0;
              const hasHaInner = property.area_hectare !== undefined && property.area_hectare !== null && property.area_hectare !== '' && !isNaN(numHa) && numHa > 0;
              // Prioritize hectares if both exist (since that's the default unit)
              if (hasHaInner) return 'hectares';
              if (hasSqmInner) return 'sqm';
              return 'hectares';
            })();
            const result = unit === 'hectares' ? n.toFixed(4) : '';
            console.log('PropertyFormModal - area_hectare result:', { unit, result, value: n });
            return result;
          }
          return '';
        })(),
        area_sqm: (() => {
          const v = property.area_sqm;
          const n = Number(v);
          const hasSqm = (v !== undefined && v !== null && v !== '' && !isNaN(n) && n > 0);
          // Only load sqm if the unit is sqm
          if (hasSqm) {
            const unit = (() => {
              const numSqm = Number(property.area_sqm);
              const numHa = Number(property.area_hectare);
              const hasSqmInner = property.area_sqm !== undefined && property.area_sqm !== null && property.area_sqm !== '' && !isNaN(numSqm) && numSqm > 0;
              const hasHaInner = property.area_hectare !== undefined && property.area_hectare !== null && property.area_hectare !== '' && !isNaN(numHa) && numHa > 0;
              // Prioritize hectares if both exist (since that's the default unit)
              if (hasHaInner) return 'hectares';
              if (hasSqmInner) return 'sqm';
              return 'hectares';
            })();
            const result = unit === 'sqm' ? n.toFixed(2) : '';
            console.log('PropertyFormModal - area_sqm result:', { unit, result, value: n });
            return result;
          }
          return '';
        })(),
        area_unit: (() => {
          const numSqm = Number(property.area_sqm);
          const numHa = Number(property.area_hectare);
          const hasSqm = property.area_sqm !== undefined && property.area_sqm !== null && property.area_sqm !== '' && !isNaN(numSqm) && numSqm > 0;
          const hasHa = property.area_hectare !== undefined && property.area_hectare !== null && property.area_hectare !== '' && !isNaN(numHa) && numHa > 0;

          // Debug logging
          console.log('PropertyFormModal - Area initialization:', {
            area_hectare: property.area_hectare,
            area_sqm: property.area_sqm,
            hasHa,
            hasSqm,
            numHa,
            numSqm
          });

          // Prioritize hectares if both exist (since that's the default unit)
          if (hasHa) return 'hectares';
          if (hasSqm) return 'sqm';
          return 'hectares';
        })(),
        title_number: property.title_number || '',
        assessed_value: property.assessed_value || '',
        assessed_value_old: property.assessed_value_old || '',
        effectivity_date: effectivityIsExempt
          ? ''
          : normalizeEffectivityYearForForm(property.effectivity_date),
        effectivity_exempt: effectivityIsExempt,
        pin: property.pin || '',
        address: property.address || '',
        assessment_date: formatAssessmentDateForForm(property.assessment_date),
        kind_of_property: property.kind_of_property || '',
        gen_class: property.gen_class || '',
        memoranda: property.memoranda || '',
        supporting_documents: [],
        // Load old area text if present
        area_hectare_old: property.area_hectare_old || ''
      });
    } else {
      // Reset form for new property
      const initialNewRevisionId = (() => {
        if (getStoredUseLastRevisionPref()) {
          return getStoredLastRevisionId();
        }
        return '';
      })();

      setFormData({
        tax_declaration_number: '',
        previous_tax_declaration_number: '',
        revision_id: initialNewRevisionId,
        property_state: 'CURRENT',
        declarant_last_name: '',
        declarant_first_name: '',
        declarant_middle_initial: '',
        business_name: '',
        location: '',
        lot_number: '',
        survey_number: '',
        area_hectare: '',
        area_sqm: '',
        area_unit: 'hectares',
        title_number: '',
        assessed_value: '',
        assessed_value_old: '',
        effectivity_date: '',
        effectivity_exempt: false,
        pin: '',
        address: '',
        assessment_date: '',
        kind_of_property: '',
        gen_class: '',
        memoranda: '',
        supporting_documents: [],
        area_hectare_old: ''
      });
    }
    // Load existing documents for edit mode
    const loadDocs = async () => {
      try {
        if (property && property.id) {
          const res = await apiService.getPropertyDocuments(property.id);
          const docs = (res && res.documents) ? res.documents : [];
          const legacy = parseLegacySupportingDocuments(property);
          setExistingDocuments([...legacy, ...docs]);
        } else {
          const legacy = parseLegacySupportingDocuments(property);
          setExistingDocuments([...legacy]);
        }
      } catch (_) {
        const legacy = parseLegacySupportingDocuments(property);
        setExistingDocuments([...legacy]);
      }
    };
    loadDocs();
    setPendingUploads([]);
    setDocumentsToDelete([]);
  }, [property, open]);

  // Ensure toast does not persist across modal openings
  useEffect(() => {
    if (!open) {
      setToast(prev => ({ ...prev, open: false, message: '' }));
    }
  }, [open]);

  // Reset options arrays when modal closes to prevent flickering
  useEffect(() => {
    if (!open) {
      setPropertyTypeOptions([]);
      setGeneralClassOptions([]);
      setLocationOptions([]);
      setOptionsLoading(true);
      setOptionsError(null);
    }
  }, [open]);

  // Cache for options to avoid repeated API calls
  const [optionsCache, setOptionsCache] = useState({
    propertyTypes: null,
    generalClasses: null,
    locations: null,
    lastFetched: null
  });

  // Check if cache is still valid (5 minutes)
  const isCacheValid = () => {
    if (!optionsCache.lastFetched) return false;
    const now = Date.now();
    const cacheAge = now - optionsCache.lastFetched;
    return cacheAge < 5 * 60 * 1000; // 5 minutes
  };

  const loadRevisionEntries = async () => {
    setRevisionLoading(true);
    setRevisionError(null);
    try {
      const res = await apiService.getRevisionEntries();
      const entries = Array.isArray(res?.items)
        ? res.items
        : Array.isArray(res?.entries)
          ? res.entries
          : Array.isArray(res)
            ? res
            : [];
      setRevisionEntries(entries);

      // In NEW property mode: if preference is ON and a stored revision ID was used,
      // verify that it actually exists in the loaded entries. If not, reset to blank and prune stale ID.
      if (!property) {
        const storedPref = getStoredUseLastRevisionPref();
        const storedId = getStoredLastRevisionId();
        if (storedPref && storedId) {
          const exists = entries.some(e => String(e.id) === String(storedId));
          if (!exists) {
            try {
              localStorage.removeItem('assessor_last_selected_revision_id');
            } catch (_) { }
            setFormData(prev => ({
              ...prev,
              revision_id: prev.revision_id === storedId ? '' : prev.revision_id
            }));
          }
        }
      }
    } catch (err) {
      console.error('PropertyFormModal: Error loading revision entries:', err);
      setRevisionError(err.message || 'Failed to load revision entries');
    } finally {
      setRevisionLoading(false);
    }
  };

  const retryLoadRevisions = () => {
    loadRevisionEntries();
  };

  useEffect(() => {
    if (open) {
      setUseLastRevision(getStoredUseLastRevisionPref());
      loadRevisionEntries();
    } else {
      setRevisionError(null);
    }
  }, [open]);

  useEffect(() => {
    // Don't load if modal is not open
    if (!open) {
      return;
    }

    const loadOptions = async () => {
      // Always fetch fresh data from server when modal opens
      // Don't rely on cache for this component since it may have stale data
      console.log('PropertyFormModal: Fetching fresh options from server...');

      setOptionsLoading(true);
      setOptionsError(null);

      try {
        const [typesRes, classesRes, locationsRes, templatesRes] = await Promise.all([
          apiService.getPropertyTypes(),
          apiService.getGeneralClasses(),
          apiService.getLocations(),
          apiService.getMemorandaTemplates().catch(() => ({ templates: [] })) // Default to empty if fails
        ]);

        const types = (Array.isArray(typesRes?.items) ? typesRes.items : []).filter(i => i.status === 'active');
        const classes = (Array.isArray(classesRes?.items) ? classesRes.items : []).filter(i => i.status === 'active');
        const locations = (Array.isArray(locationsRes?.items) ? locationsRes.items : []).filter(i => i.status === 'active');
        const templates = Array.isArray(templatesRes?.templates) ? templatesRes.templates : [];

        // Validate that we got meaningful data
        if (types.length === 0 || classes.length === 0 || locations.length === 0) {
          throw new Error('Incomplete options data received from server');
        }

        // Update state
        setPropertyTypeOptions(types);
        setGeneralClassOptions(classes);
        setLocationOptions(locations);
        setMemorandaTemplates(templates);

        // Update cache
        setOptionsCache({
          propertyTypes: types,
          generalClasses: classes,
          locations: locations,
          lastFetched: Date.now()
        });

        console.log('PropertyFormModal: Successfully loaded options:', {
          typesCount: types.length,
          classesCount: classes.length,
          locationsCount: locations.length
        });
      } catch (e) {
        console.error('PropertyFormModal: Error loading options:', e);
        setOptionsError(e.message || 'Failed to load form options');

        // Use cached data if available, otherwise fallback to defaults
        if (optionsCache.propertyTypes && optionsCache.generalClasses && optionsCache.locations) {
          console.log('PropertyFormModal: Using cached data as fallback');
          setPropertyTypeOptions(optionsCache.propertyTypes);
          setGeneralClassOptions(optionsCache.generalClasses);
          setLocationOptions(optionsCache.locations);
        } else {
          console.log('PropertyFormModal: Using default fallback options');
          // Enhanced fallback options
          setPropertyTypeOptions([
            { code: 'LAND', name: 'LAND' },
            { code: 'BUILDING', name: 'BUILDING' },
            { code: 'MACHINERY', name: 'MACHINERY' },
            { code: 'IMPROVEMENTS', name: 'IMPROVEMENTS' },
            { code: 'PLANT_TREES', name: 'PLANT/TREES' }
          ]);
          setGeneralClassOptions([
            { code: 'RESIDENTIAL', name: 'RESIDENTIAL' },
            { code: 'COMMERCIAL', name: 'COMMERCIAL' },
            { code: 'INDUSTRIAL', name: 'INDUSTRIAL' },
            { code: 'AGRICULTURAL', name: 'AGRICULTURAL' },
            { code: 'MIXED_USE', name: 'MIXED USE' },
            { code: 'VACANT_LOT', name: 'VACANT LOT' },
            { code: 'SPECIAL', name: 'SPECIAL' }
          ]);
          setLocationOptions([
            { code: 'BARANGAY', name: 'BARANGAY' }
          ]);
        }
      } finally {
        setOptionsLoading(false);
      }
    };

    // Load options when modal opens - always fetch fresh
    loadOptions();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    // Only set default values when options are loaded and we're creating a new property
    if (!property && !optionsLoading && propertyTypeOptions.length > 0 && generalClassOptions.length > 0 && locationOptions.length > 0) {
      setFormData(prev => ({
        ...prev,
        kind_of_property: propertyTypeOptions[0]?.code || '',
        gen_class: generalClassOptions[0]?.code || '',
        location: locationOptions[0]?.name || ''
      }));
    }
  }, [property, optionsLoading, propertyTypeOptions, generalClassOptions, locationOptions]);

  // Retry mechanism for failed options loading
  const retryLoadOptions = async () => {
    setOptionsLoading(true);
    setOptionsError(null);

    try {
      console.log('PropertyFormModal: Retrying options load from server...');
      const [typesRes, classesRes, locationsRes, templatesRes] = await Promise.all([
        apiService.getPropertyTypes(),
        apiService.getGeneralClasses(),
        apiService.getLocations(),
        apiService.getMemorandaTemplates().catch(() => ({ templates: [] })) // Default to empty if fails
      ]);

      const types = (Array.isArray(typesRes?.items) ? typesRes.items : []).filter(i => i.status === 'active');
      const classes = (Array.isArray(classesRes?.items) ? classesRes.items : []).filter(i => i.status === 'active');
      const locations = (Array.isArray(locationsRes?.items) ? locationsRes.items : []).filter(i => i.status === 'active');
      const templates = Array.isArray(templatesRes?.templates) ? templatesRes.templates : [];

      if (types.length === 0 || classes.length === 0 || locations.length === 0) {
        throw new Error('Incomplete options data received from server on retry');
      }

      setPropertyTypeOptions(types);
      setGeneralClassOptions(classes);
      setLocationOptions(locations);
      setMemorandaTemplates(templates);

      console.log('PropertyFormModal: Retry successful');
    } catch (e) {
      console.error('PropertyFormModal: Retry failed:', e);
      setOptionsError(e.message || 'Failed to load form options');
    } finally {
      setOptionsLoading(false);
    }
  };

  // Helper function to format currency
  const formatCurrency = (value) => {
    if (!value || value === '') return '';
    const num = typeof value === 'string' ? parseFloat(value.replace(/[^\d.-]/g, '')) : value;
    if (isNaN(num)) return '';
    return new Intl.NumberFormat('en-PH', {
      style: 'currency',
      currency: 'PHP',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }).format(num);
  };

  // Helper function to parse currency input
  const parseCurrencyInput = (value) => {
    if (!value || value === '') return '';
    const num = parseFloat(value.replace(/[^\d.-]/g, ''));
    return isNaN(num) ? '' : num;
  };

  const effectivityIsExempt = isEffectivityExemptValue(formData.effectivity_exempt);

  const validateEffectivityYear = (value) => {
    const raw = String(value || '').trim();

    if (raw === '') {
      return true;
    }

    if (!/^\d{4}$/.test(raw)) {
      return false;
    }

    const year = Number(raw);

    return year >= 1800 && year <= 2100;
  };

  const normalizeEffectivityForSubmit = (value, exempt) => {
    if (isEffectivityExemptValue(exempt)) {
      return null;
    }

    const raw = String(value ?? '').trim();

    if (raw === '') {
      return null;
    }

    if (!/^\d{4}$/.test(raw)) {
      return null;
    }

    const year = Number(raw);

    if (year < 1800 || year > 2100) {
      return null;
    }

    return raw;
  };

  // Helper function to uppercase field values on submit
  const uppercaseFieldValue = (field, value) => {
    const uppercaseFields = new Set([
      'tax_declaration_number',
      'previous_tax_declaration_number',
      'declarant_last_name',
      'declarant_first_name',
      'declarant_middle_initial',
      'business_name',
      'location',
      'lot_number',
      'survey_number',
      'title_number',
      'pin',
      'address',
      'kind_of_property',
      'gen_class',
      'memoranda'
    ]);

    if (uppercaseFields.has(field) && typeof value === 'string') {
      return value.toUpperCase();
    }
    return value;
  };

  const handleInputChange = (field, value) => {
    // Clear duplicate TDN error when user starts typing in TDN field
    if (field === 'tax_declaration_number' && duplicateTdnError) {
      setDuplicateTdnError(false);
    }

    // Handle area field updates - only update the selected unit
    if (field === 'area_hectare') {
      // Only update hectares, don't sync with sqm
      setFormData(prev => ({ ...prev, area_hectare: value }));
    } else if (field === 'area_sqm') {
      // Only update sqm, don't sync with hectares
      setFormData(prev => ({ ...prev, area_sqm: value }));
    } else {
      setFormData(prev => ({ ...prev, [field]: value }));
    }
  };

  const validateForm = () => {
    const errors = [];

    if (!formData.tax_declaration_number.trim()) {
      errors.push('Tax Declaration Number is required');
    }

    // Check if options are still loading
    if (optionsLoading) {
      errors.push('Form options are still loading. Please wait.');
    }

    // Check if there was an error loading options
    if (optionsError) {
      errors.push('Failed to load form options. Please try refreshing the page.');
    }

    // Validate location selection
    if (!formData.location.trim()) {
      errors.push('Location is required');
    } else if (locationOptions.length > 0 && !locationOptions.some(loc => loc.name === formData.location)) {
      errors.push('Selected location is not valid');
    }

    // Validate kind of property selection
    if (!formData.kind_of_property.trim()) {
      errors.push('Kind of Property is required');
    } else if (propertyTypeOptions.length > 0 && !propertyTypeOptions.some(pt => pt.code === formData.kind_of_property)) {
      errors.push('Selected kind of property is not valid');
    }

    // Validate general class selection (optional but must be valid if selected)
    if (formData.gen_class.trim() && generalClassOptions.length > 0 && !generalClassOptions.some(gc => gc.code === formData.gen_class)) {
      errors.push('Selected general class is not valid');
    }

    if (!isEffectivityExemptValue(formData.effectivity_exempt)) {
      const effectivity = String(formData.effectivity_date || '').trim();

      if (
        effectivity !== '' &&
        !validateEffectivityYear(effectivity)
      ) {
        errors.push(
          'Effectivity Year must be blank or a valid 4-digit year from 1800 to 2100.'
        );
      }
    }

    return errors;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    // Prevent submission if options are still loading
    if (optionsLoading) {
      setToast({ open: true, message: 'Please wait for form options to load before submitting', severity: 'error' });
      return;
    }
    // If offline, notify and stop to avoid unknown save state
    try {
      if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        setToast({ open: true, message: 'You appear to be offline. Please reconnect before saving.', severity: 'error' });
        return;
      }
    } catch (_) { }

    const validationErrors = validateForm();
    if (validationErrors.length > 0) {
      setToast({ open: true, message: validationErrors.join('. '), severity: 'error' });
      return;
    }

    setLoading(true);

    try {
      const supportingDocsString = Array.isArray(formData.supporting_documents)
        ? formData.supporting_documents
          .map((doc) => (typeof doc === 'string' ? doc : (doc && doc.name) || ''))
          .filter(Boolean)
          .join(', ')
        : (formData.supporting_documents || '');

      // Pull signatories from bootstrap settings to store on the property record
      let settings = null;
      try {
        settings = await apiService.getBootstrapSettings();
      } catch (_) { }

      // Only submit the selected unit; blank the other
      const submitData = {
        tax_declaration_number: uppercaseFieldValue('tax_declaration_number', formData.tax_declaration_number),
        previous_tax_declaration_number: uppercaseFieldValue('previous_tax_declaration_number', formData.previous_tax_declaration_number),
        revision_id: formData.revision_id || null,
        declarant_last_name: uppercaseFieldValue('declarant_last_name', formData.declarant_last_name),
        declarant_first_name: uppercaseFieldValue('declarant_first_name', formData.declarant_first_name),
        declarant_middle_initial: uppercaseFieldValue('declarant_middle_initial', formData.declarant_middle_initial),
        business_name: uppercaseFieldValue('business_name', formData.business_name),
        location: uppercaseFieldValue('location', formData.location),
        lot_number: uppercaseFieldValue('lot_number', formData.lot_number),
        survey_number: uppercaseFieldValue('survey_number', formData.survey_number),
        unique_lot_number_identified: uppercaseFieldValue('survey_number', formData.survey_number),
        area_hectare: formData.area_unit === 'hectares'
          ? (formData.area_hectare === '' ? '' : Number(formData.area_hectare))
          : '',
        area_sqm: formData.area_unit === 'sqm'
          ? (formData.area_sqm === '' ? '' : Number(formData.area_sqm))
          : '',
        title_number: uppercaseFieldValue('title_number', formData.title_number),
        assessed_value: formData.assessed_value === '' ? '' : Number(formData.assessed_value),
        assessed_value_old: formData.assessed_value_old,
        effectivity_date: normalizeEffectivityForSubmit(
          formData.effectivity_date,
          formData.effectivity_exempt
        ),
        effectivity_exempt: isEffectivityExemptValue(formData.effectivity_exempt),
        pin: uppercaseFieldValue('pin', formData.pin),
        address: uppercaseFieldValue('address', formData.address),
        assessment_date: cleanAssessmentDate(formData.assessment_date),
        kind_of_property: uppercaseFieldValue('kind_of_property', formData.kind_of_property),
        gen_class: uppercaseFieldValue('gen_class', formData.gen_class),
        memoranda: uppercaseFieldValue('memoranda', formData.memoranda),
        supporting_documents: supportingDocsString,
        // Pass old area text if provided; allow explicit null on clear when updating
        ...(property ? { area_hectare_old: (formData.area_hectare_old === '' ? '' : (formData.area_hectare_old ?? '')) } : { area_hectare_old: formData.area_hectare_old ?? '' }),
        verifier_signatory_name: settings?.verifier_signatory_name || '',
        verifier_signatory_title: settings?.verifier_signatory_title || '',
        municipal_assessor_name: settings?.municipal_assessor_name || '',
        municipal_assessor_suffix: settings?.municipal_assessor_suffix || '',
        municipal_assessor_title: settings?.municipal_assessor_title || '',
        municipal_assessor_license: String(settings?.municipal_assessor_license || '')
      };

      // On update: if assessed_value is left blank, do not send the field
      // to avoid backend converting empty string to 0.00.
      if (property && (formData.assessed_value === '' || formData.assessed_value === null || formData.assessed_value === undefined)) {
        delete submitData.assessed_value;
      }

      let saved;
      if (property) {
        saved = await apiService.updateProperty(property.id, submitData);
      } else {
        saved = await apiService.createProperty(submitData);
      }

      const propertyId = (saved && saved.id) ? saved.id : (property && property.id);

      // Update property state if a valid propertyId exists
      if (propertyId) {
        const stateToSave = formData.property_state || 'CURRENT';
        try {
          await apiService.updatePropertyState(propertyId, stateToSave);
        } catch (e) {
          console.error('Error updating property state:', e);
          setToast({ open: true, message: 'Property saved but failed to update state.', severity: 'warning' });
        }
      }

      // Upload supporting documents (actual files only)
      const files = Array.isArray(pendingUploads) ? pendingUploads : [];
      const fileObjects = files.filter((doc) => doc && (doc.name));
      if (propertyId && fileObjects.length > 0) {
        try {
          await Promise.all(
            fileObjects.map((file) => uploadFile(file, propertyId))
          );
        } catch (e) {
          console.error('Error uploading supporting documents:', e);
          // Proceed but notify user that some uploads failed
          setToast({ open: true, message: 'Some documents failed to upload', severity: 'warning' });
        }
      }

      // Perform deferred deletions
      const toDelete = Array.isArray(documentsToDelete) ? documentsToDelete : [];
      if (propertyId && toDelete.length > 0) {
        try {
          await Promise.all(
            toDelete.map((docId) => apiService.deletePropertyDocument(propertyId, docId))
          );
        } catch (e) {
          console.error('Error deleting documents:', e);
          setToast({ open: true, message: 'Some documents failed to delete', severity: 'warning' });
        }
      }

      onSave(property ? 'Property updated successfully' : 'Property created successfully');
      setToast({ open: true, message: property ? 'Property updated successfully' : 'Property created successfully', severity: 'success' });
      onCancel();
    } catch (error) {
      console.error('Error saving property:', error);
      const msg = error.message || 'Error saving property';

      console.log('Full error message:', msg);
      console.log('Message includes check:', msg.includes('Tax Declaration Number already exists'));
      console.log('Message includes check (lowercase):', msg.toLowerCase().includes('tax declaration number already exists'));

      // Check if it's a duplicate TDN error
      if (msg.includes('Tax Declaration Number already exists') ||
        msg.toLowerCase().includes('tax declaration number already exists')) {
        console.log('Setting duplicateTdnError to true');
        setDuplicateTdnError(true);
      } else {
        console.log('Setting duplicateTdnError to false');
        setDuplicateTdnError(false);
      }

      // Detect possible network-related uncertainty (no response / timeout / offline)
      const maybeNetwork = (() => {
        if (typeof navigator !== 'undefined' && navigator.onLine === false) return true;
        const m = String(msg || '').toLowerCase();
        return m.includes('no response') || m.includes('network') || m.includes('timeout');
      })();
      if (maybeNetwork) {
        setToast({ open: true, message: 'A network issue occurred. Save status is unknown. You can verify after reconnecting.', severity: 'warning' });
        setSaveVerify({ pending: true, tdn: formData.tax_declaration_number, checking: false });
      } else {
        setToast({ open: true, message: msg, severity: 'error' });
      }
    } finally {
      setLoading(false);
    }
  };

  const verifySaveStatus = async () => {
    if (!saveVerify.tdn) {
      setSaveVerify({ pending: false, tdn: '', checking: false });
      return;
    }
    try {
      setSaveVerify(prev => ({ ...prev, checking: true }));
      const res = await apiService.getPropertyByTaxNumber(saveVerify.tdn);
      const exists = !!(res && (res.id || (Array.isArray(res) && res.length > 0)));
      if (exists) {
        setToast({ open: true, message: `Record for TDN ${saveVerify.tdn} is present. Save succeeded.`, severity: 'success' });
      } else {
        setToast({ open: true, message: `Record for TDN ${saveVerify.tdn} was not found. Please try saving again.`, severity: 'warning' });
      }
    } catch (e) {
      setToast({ open: true, message: 'Could not verify due to network/server issue. Please try again later.', severity: 'error' });
    } finally {
      setSaveVerify({ pending: false, tdn: '', checking: false });
    }
  };

  return (
    <>
      <Snackbar
        open={toast.open}
        autoHideDuration={3000}
        onClose={() => setToast(prev => ({ ...prev, open: false }))}
        anchorOrigin={{ vertical: 'top', horizontal: 'center' }}
      >
        <Alert
          onClose={() => setToast(prev => ({ ...prev, open: false }))}
          severity={toast.severity}
          sx={{ width: '100%', borderRadius: 1 }}
        >
          {toast.message}
        </Alert>
      </Snackbar>

      <Dialog
        open={open}
        onClose={loading ? undefined : (onClose || onCancel)}
        fullWidth
        maxWidth={false}
        scroll="paper"
        PaperProps={{
          component: motion.div,
          initial: { opacity: 0, y: 12 },
          animate: { opacity: 1, y: 0 },
          exit: { opacity: 0, y: 12 },
          transition: { duration: 0.2, ease: 'easeOut' },
          sx: {
            width: {
              xs: 'calc(100vw - 20px)',
              sm: '94vw',
              md: '1180px',
              lg: '1280px'
            },
            maxWidth: '1320px',
            height: { xs: '96vh', sm: '92vh' },
            maxHeight: '94vh',
            borderRadius: 1,
            display: 'flex',
            flexDirection: 'column',
            overflow: 'hidden',
            backgroundColor: theme.palette.background.paper,
            m: { xs: 1, sm: 2 }
          }
        }}
      >
        {/* ================= A. FIXED HEADER ================= */}
        <DialogTitle
          sx={{
            py: { xs: 1.5, sm: 2 },
            px: { xs: 2, sm: 3 },
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            borderBottom: 1,
            borderColor: 'divider',
            backgroundColor: theme.palette.background.default,
            flexShrink: 0
          }}
        >
          {/* Left Side: Icon in soft rounded square, Title, Badge, Subtitle */}
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, minWidth: 0 }}>
            <Box
              sx={{
                width: { xs: 36, sm: 40 },
                height: { xs: 36, sm: 40 },
                borderRadius: 1,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                backgroundColor: theme.palette.primary.main + '14',
                color: theme.palette.primary.main,
                flexShrink: 0
              }}
            >
              <PropertyIcon sx={{ fontSize: { xs: 20, sm: 24 } }} />
            </Box>

            <Box sx={{ minWidth: 0 }}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
                <Typography
                  variant="h6"
                  sx={{
                    fontWeight: 600,
                    color: theme.palette.text.primary,
                    lineHeight: 1.2,
                    fontSize: { xs: '1rem', sm: '1.2rem' }
                  }}
                >
                  {property ? 'Edit Property Record' : 'Add New Property Record'}
                </Typography>

                <Chip
                  label={property ? 'EDITING' : 'NEW RECORD'}
                  size="small"
                  color={property ? 'primary' : 'success'}
                  variant="outlined"
                  sx={{
                    height: 20,
                    fontSize: '0.68rem',
                    fontWeight: 700,
                    letterSpacing: '0.04em'
                  }}
                />
              </Box>

              <Typography
                variant="caption"
                sx={{
                  color: theme.palette.text.secondary,
                  display: { xs: 'none', sm: 'block' },
                  mt: 0.25
                }}
              >
                Encode and maintain the official real property assessment record.
              </Typography>
            </Box>
          </Box>

          {/* Right Side: TDN badge, Property State badge, Close button */}
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexShrink: 0, ml: 1.5 }}>
            {formData.tax_declaration_number && (
              <Chip
                label={`TDN: ${formData.tax_declaration_number}`}
                size="small"
                variant="outlined"
                sx={{
                  display: { xs: 'none', sm: 'inline-flex' },
                  height: 24,
                  fontSize: '0.75rem',
                  fontWeight: 600,
                  fontFamily: 'monospace',
                  borderColor: theme.palette.divider,
                  color: theme.palette.text.primary,
                  backgroundColor: theme.palette.background.paper
                }}
              />
            )}

            <Chip
              label={formData.property_state || 'CURRENT'}
              size="small"
              color={
                formData.property_state === 'CURRENT'
                  ? 'success'
                  : formData.property_state === 'CANCELLED'
                    ? 'error'
                    : formData.property_state === 'PENDING'
                      ? 'warning'
                      : 'default'
              }
              sx={{
                height: 24,
                fontSize: '0.72rem',
                fontWeight: 700,
                letterSpacing: '0.02em'
              }}
            />

            <IconButton
              size="small"
              onClick={onClose || onCancel}
              disabled={loading}
              aria-label="Close Property Form"
              sx={{
                color: theme.palette.text.secondary,
                ml: 0.5,
                '&:hover': {
                  color: theme.palette.error.main
                }
              }}
            >
              <CloseIcon fontSize="small" />
            </IconButton>
          </Box>
        </DialogTitle>

        {/* ================= B. SCROLLABLE FORM BODY ================= */}
        <DialogContent
          sx={{
            p: { xs: 1.5, sm: 2.5 },
            overflowY: 'auto',
            flex: '1 1 auto',
            backgroundColor: theme.palette.background.default
          }}
        >
          <form onSubmit={handleSubmit} id="property-form-element">
            <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
              {/* Options Loading Error Banner */}
              {optionsError && (
                <Alert
                  severity="error"
                  action={
                    <Button
                      color="inherit"
                      size="small"
                      onClick={retryLoadOptions}
                      disabled={optionsLoading}
                    >
                      {optionsLoading ? 'Retrying...' : 'Retry'}
                    </Button>
                  }
                  sx={{ borderRadius: 1 }}
                >
                  {optionsError}
                </Alert>
              )}

              {/* Network / Save Verification Alert */}
              {saveVerify.pending && (
                <Alert
                  severity="warning"
                  sx={{ borderRadius: 1 }}
                  action={
                    <Button
                      color="inherit"
                      size="small"
                      onClick={verifySaveStatus}
                      disabled={saveVerify.checking}
                    >
                      {saveVerify.checking ? 'Checking...' : 'Verify Now'}
                    </Button>
                  }
                >
                  We detected a possible network issue while saving. Save status for TDN "{saveVerify.tdn}" is unknown.
                </Alert>
              )}

              {/* Revision Loading Error Banner */}
              {revisionError && (
                <Alert
                  severity="error"
                  action={
                    <Button
                      color="inherit"
                      size="small"
                      onClick={retryLoadRevisions}
                      disabled={revisionLoading}
                    >
                      {revisionLoading ? 'Retrying...' : 'Retry'}
                    </Button>
                  }
                  sx={{ borderRadius: 1 }}
                >
                  Failed to load revision entries: {revisionError}
                </Alert>
              )}

              {/* ---------------- ASSESSMENT REVISION ---------------- */}
              {(() => {
                const selectedRevision = revisionEntries.find(r => String(r.id) === String(formData.revision_id));
                const isLegacyUnmatched = Boolean(
                  formData.revision_id &&
                  !selectedRevision &&
                  !revisionLoading
                );

                // Build options list including legacy unmatched option if editing historical property
                const revisionOptions = [...revisionEntries];
                if (isLegacyUnmatched) {
                  revisionOptions.unshift({
                    id: formData.revision_id,
                    revision_year: 'Previously Assigned Revision',
                    revision_code: 'HISTORICAL',
                    status: 'unavailable',
                    from_year: '',
                    to_year: ''
                  });
                }

                const currentSelection = revisionOptions.find(r => String(r.id) === String(formData.revision_id)) || null;

                const isPresent = currentSelection && (!currentSelection.to_year || String(currentSelection.to_year).toLowerCase() === 'present');
                const coverageDisplay = currentSelection && currentSelection.from_year
                  ? `${currentSelection.from_year} – ${isPresent ? 'Present' : currentSelection.to_year}`
                  : null;

                const activeRevisionName = currentSelection
                  ? (currentSelection.revision_year || currentSelection.revision_code || 'Unnamed Revision')
                  : null;

                const handleRevisionChange = (newRevisionId) => {
                  setFormData(prev => ({
                    ...prev,
                    revision_id: newRevisionId
                  }));

                  // If in NEW property mode, save to localStorage as the last selected revision
                  if (!property && newRevisionId) {
                    try {
                      localStorage.setItem('assessor_last_selected_revision_id', newRevisionId);
                    } catch (_) { }
                  }
                };

                const handleToggleUseLastRevision = (e) => {
                  const checked = e.target.checked;
                  setUseLastRevision(checked);
                  try {
                    localStorage.setItem('assessor_use_last_selected_revision', checked ? 'true' : 'false');
                    if (checked && formData.revision_id) {
                      localStorage.setItem('assessor_last_selected_revision_id', formData.revision_id);
                    }
                  } catch (_) { }
                };

                const renderRevisionRow = (option, { isSelectedValue = false } = {}) => {
                  if (!option) return null;
                  const isOptionActive = String(option.status || '').toLowerCase() === 'active';
                  const isOptionUnavailable = option.status === 'unavailable';
                  const optIsPresent = !option.to_year || String(option.to_year).toLowerCase() === 'present';
                  const coverageRange = option.from_year
                    ? `(${option.from_year} → ${optIsPresent ? 'Present' : option.to_year})`
                    : null;
                  const codeDisplay = option.revision_code || null;
                  const statusLabel = isOptionUnavailable
                    ? 'Unavailable'
                    : isOptionActive
                      ? 'Active'
                      : 'Inactive';

                  return (
                    <Box
                      sx={{
                        display: 'grid',
                        gridTemplateColumns: isSelectedValue
                          ? 'minmax(0, 1.2fr) auto auto auto'
                          : 'minmax(140px, 1fr) auto auto auto',
                        alignItems: 'center',
                        columnGap: { xs: 1, sm: 1.5 },
                        width: '100%',
                        minWidth: 0,
                        overflow: 'hidden'
                      }}
                    >
                      {/* 1. NAME - Strongest visual anchor */}
                      <Typography
                        variant="body2"
                        sx={{
                          fontWeight: 600,
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis',
                          color: theme.palette.text.primary,
                          fontSize: '0.84rem',
                          letterSpacing: '0.01em'
                        }}
                        title={option.revision_year || option.revision_code || 'Revision'}
                      >
                        {option.revision_year || option.revision_code || 'Revision'}
                      </Typography>

                      {/* 2. COVERAGE / PERIOD - Subtle secondary metadata */}
                      <Typography
                        variant="caption"
                        sx={{
                          whiteSpace: 'nowrap',
                          color: theme.palette.text.secondary,
                          fontSize: '0.76rem',
                          fontFamily: 'monospace',
                          fontWeight: 500,
                          letterSpacing: '-0.01em',
                          opacity: 0.9
                        }}
                      >
                        {coverageRange || ''}
                      </Typography>

                      {/* 3. CODE - Compact reference badge/code */}
                      {codeDisplay ? (
                        <Typography
                          variant="caption"
                          sx={{
                            whiteSpace: 'nowrap',
                            color: theme.palette.text.secondary,
                            fontSize: '0.74rem',
                            fontWeight: 600,
                            px: 0.6,
                            py: 0.15,
                            borderRadius: 0.5,
                            backgroundColor: theme.palette.action.hover,
                            border: `1px solid ${theme.palette.divider}`
                          }}
                        >
                          {codeDisplay}
                        </Typography>
                      ) : <Box />}

                      {/* 4. STATUS CHIP - Restrained, clean enterprise chip */}
                      {/* <Chip
                        size="small"
                        label={statusLabel}
                        color={isOptionActive ? 'success' : isOptionUnavailable ? 'warning' : 'default'}
                        variant={isOptionActive ? 'filled' : 'outlined'}
                        sx={{
                          height: 20,
                          fontSize: '0.66rem',
                          fontWeight: 700,
                          letterSpacing: '0.02em',
                          justifySelf: 'end',
                          flexShrink: 0,
                          ...(isOptionActive && {
                            backgroundColor: theme.palette.success.main,
                            color: '#ffffff'
                          })
                        }}
                      /> */}
                    </Box>
                  );
                };

                return (
                  <FormSection
                    icon={RevisionIcon}
                    title="Assessment Revision"
                    subtitle="Select the approved revision applicable to this property."
                  >
                    <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1.5 }}>
                      {/* Revision Selector + Inline Metadata Row (Single horizontal line on desktop) */}
                      <Box
                        sx={{
                          display: 'flex',
                          alignItems: 'center',
                          flexWrap: { xs: 'wrap', md: 'nowrap' },
                          gap: 1.5,
                          width: '100%',
                          minWidth: 0
                        }}
                      >
                        {/* External "Revision" Label */}
                        {/* <Typography
                          variant="body2"
                          sx={{
                            whiteSpace: 'nowrap',
                            fontWeight: 600,
                            color: theme.palette.text.secondary,
                            fontSize: '0.84rem',
                            flexShrink: 0
                          }}
                        >
                          Revision
                        </Typography> */}

                        {/* Compact Single-Line Revision Select */}
                        <Box sx={{ width: { xs: '100%', sm: 380, md: 430, lg: 470 }, flexShrink: 0 }}>
                          <FormControl fullWidth size="small">
                            <Select
                              id="assessment-revision-select"
                              value={formData.revision_id || ''}
                              displayEmpty
                              renderValue={(selected) => {
                                if (!selected) {
                                  return (
                                    <Typography
                                      component="span"
                                      variant="body2"
                                      sx={{
                                        color: theme.palette.text.secondary,
                                        fontStyle: 'italic',
                                        fontSize: '0.84rem',
                                        whiteSpace: 'nowrap',
                                        overflow: 'hidden',
                                        textOverflow: 'ellipsis',
                                        display: 'block'
                                      }}
                                    >
                                      Select revision...
                                    </Typography>
                                  );
                                }
                                const match = revisionOptions.find(o => String(o.id) === String(selected)) || {
                                  id: selected,
                                  revision_year: selected,
                                  revision_code: '',
                                  status: 'unavailable'
                                };
                                return renderRevisionRow(match, { isSelectedValue: true });
                              }}
                              onChange={(e) => handleRevisionChange(e.target.value)}
                              disabled={revisionLoading}
                              sx={{
                                height: 40,
                                minHeight: 40,
                                backgroundColor: theme.palette.background.paper,
                                borderRadius: 1,
                                transition: theme.transitions.create(['border-color', 'box-shadow', 'background-color'], {
                                  duration: 150
                                }),
                                '& .MuiOutlinedInput-notchedOutline': {
                                  borderColor: theme.palette.divider
                                },
                                '&:hover .MuiOutlinedInput-notchedOutline': {
                                  borderColor: theme.palette.text.secondary
                                },
                                '&.Mui-focused .MuiOutlinedInput-notchedOutline': {
                                  borderColor: theme.palette.primary.main,
                                  borderWidth: '1.5px'
                                },
                                '& .MuiSelect-select': {
                                  py: 0,
                                  height: 40,
                                  display: 'flex',
                                  alignItems: 'center',
                                  whiteSpace: 'nowrap',
                                  overflow: 'hidden',
                                  textOverflow: 'ellipsis',
                                  pr: '36px !important'
                                },
                                '& .MuiSelect-icon': {
                                  color: theme.palette.text.secondary,
                                  right: 8
                                }
                              }}
                              MenuProps={{
                                autoFocus: false,
                                PaperProps: {
                                  elevation: 4,
                                  sx: {
                                    maxHeight: 360,
                                    width: 'max-content',
                                    minWidth: '100%',
                                    maxWidth: 'min(92vw, 680px)',
                                    borderRadius: 1,
                                    border: `1px solid ${theme.palette.divider}`,
                                    mt: 0.5,
                                    boxShadow: '0 4px 16px rgba(0, 0, 0, 0.08)'
                                  }
                                }
                              }}
                            >
                              <MenuItem value="" sx={{ fontSize: '0.84rem', fontStyle: 'italic', color: 'text.secondary' }}>
                                <em>Select revision...</em>
                              </MenuItem>
                              {revisionLoading ? (
                                <MenuItem disabled sx={{ fontSize: '0.84rem' }}>
                                  <CircularProgress size={16} sx={{ mr: 1 }} />
                                  Loading revisions...
                                </MenuItem>
                              ) : revisionError ? (
                                <MenuItem
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    retryLoadRevisions();
                                  }}
                                  sx={{
                                    color: 'error.main',
                                    display: 'flex',
                                    justifyContent: 'space-between',
                                    fontSize: '0.84rem'
                                  }}
                                >
                                  <Typography variant="body2" sx={{ color: 'error.main', fontSize: '0.84rem' }}>
                                    Unable to load revisions
                                  </Typography>
                                  <Typography variant="caption" sx={{ color: 'primary.main', fontWeight: 700, ml: 1 }}>
                                    Retry
                                  </Typography>
                                </MenuItem>
                              ) : revisionOptions.length === 0 ? (
                                <MenuItem disabled sx={{ fontSize: '0.84rem' }}>No revisions configured</MenuItem>
                              ) : (
                                revisionOptions.map(option => {
                                  const isSelected = String(option.id) === String(formData.revision_id);
                                  return (
                                    <MenuItem
                                      key={option.id}
                                      value={option.id}
                                      sx={{
                                        py: 1,
                                        px: 1.5,
                                        minHeight: 40,
                                        display: 'flex',
                                        alignItems: 'center',
                                        backgroundColor: isSelected ? theme.palette.action.selected : 'transparent',
                                        borderLeft: isSelected
                                          ? `3px solid ${theme.palette.primary.main}`
                                          : '3px solid transparent',
                                        '&:hover': {
                                          backgroundColor: theme.palette.action.hover
                                        },
                                        transition: 'background-color 120ms ease, border-left-color 120ms ease'
                                      }}
                                    >
                                      {renderRevisionRow(option, { isSelectedValue: false })}
                                    </MenuItem>
                                  );
                                })
                              )}
                            </Select>
                          </FormControl>
                        </Box>

                        {/* Inline Metadata: [Active Revision] Selected Name • Coverage: 2026–2030 */}
                        {/* {currentSelection && (
                          <Box
                            sx={{
                              display: 'flex',
                              alignItems: 'center',
                              gap: 1.25,
                              flex: 1,
                              minWidth: 0,
                              height: 40,
                              overflow: 'hidden',
                              whiteSpace: 'nowrap'
                            }}
                          >
                            <Chip
                              size="small"
                              label={
                                currentSelection.status === 'unavailable'
                                  ? 'Historical'
                                  : String(currentSelection.status || '').toLowerCase() === 'active'
                                    ? 'Active Revision'
                                    : (currentSelection.status || 'Archived')
                              }
                              color={
                                String(currentSelection.status || '').toLowerCase() === 'active'
                                  ? 'success'
                                  : currentSelection.status === 'unavailable'
                                    ? 'warning'
                                    : 'default'
                              }
                              variant={String(currentSelection.status || '').toLowerCase() === 'active' ? 'filled' : 'outlined'}
                              sx={{
                                height: 22,
                                fontSize: '0.68rem',
                                fontWeight: 700,
                                letterSpacing: '0.02em',
                                flexShrink: 0,
                                ...(String(currentSelection.status || '').toLowerCase() === 'active' && {
                                  backgroundColor: theme.palette.success.main,
                                  color: '#ffffff'
                                })
                              }}
                            />

                            <Typography
                              variant="body2"
                              sx={{
                                fontWeight: 600,
                                color: theme.palette.text.primary,
                                fontSize: '0.85rem',
                                lineHeight: 1,
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                                minWidth: 0,
                                flexShrink: 1
                              }}
                              title={activeRevisionName}
                            >
                              {activeRevisionName}
                            </Typography>

                            {coverageDisplay && (
                              <>
                                <Typography
                                  component="span"
                                  sx={{
                                    color: theme.palette.text.disabled,
                                    fontSize: '0.85rem',
                                    lineHeight: 1,
                                    userSelect: 'none',
                                    flexShrink: 0
                                  }}
                                >
                                  •
                                </Typography>
                                <Typography
                                  variant="body2"
                                  sx={{
                                    color: theme.palette.text.secondary,
                                    fontSize: '0.82rem',
                                    lineHeight: 1,
                                    fontWeight: 500,
                                    flexShrink: 0,
                                    whiteSpace: 'nowrap'
                                  }}
                                >
                                  Coverage: {coverageDisplay}
                                </Typography>
                              </>
                            )}

                            {isLegacyUnmatched && (
                              <Typography
                                variant="caption"
                                sx={{
                                  color: 'warning.main',
                                  fontWeight: 600,
                                  lineHeight: 1,
                                  flexShrink: 0,
                                  ml: 0.5
                                }}
                              >
                                (Preserved ID)
                              </Typography>
                            )}
                          </Box>
                        )} */}
                      </Box>

                      {/* "Use last selected revision for new entries" Preference (NEW property mode only) */}
                      {!property && (
                        <Box
                          sx={{
                            display: 'flex',
                            alignItems: 'center',
                            pt: 0.25,
                            minHeight: 26
                          }}
                        >
                          <FormControlLabel
                            control={
                              <Checkbox
                                size="small"
                                checked={useLastRevision}
                                onChange={handleToggleUseLastRevision}
                                sx={{
                                  p: 0.5,
                                  color: theme.palette.text.secondary,
                                  '&.Mui-checked': {
                                    color: theme.palette.primary.main
                                  }
                                }}
                              />
                            }
                            label={
                              <Typography
                                variant="body2"
                                sx={{
                                  fontWeight: 500,
                                  fontSize: '0.82rem',
                                  color: theme.palette.text.primary,
                                  userSelect: 'none'
                                }}
                              >
                                Use last selected revision for new entries
                              </Typography>
                            }
                            sx={{ mr: 1, mb: 0 }}
                          />
                          <Typography
                            variant="caption"
                            sx={{
                              color: theme.palette.text.secondary,
                              fontSize: '0.74rem',
                              display: { xs: 'none', sm: 'inline' }
                            }}
                          >
                            (Applies only to new properties)
                          </Typography>
                        </Box>
                      )}
                    </Box>
                  </FormSection>
                );
              })()}

              {/* ---------------- 1. RECORD IDENTIFICATION ---------------- */}
              <FormSection
                icon={BadgeIcon}
                title="Record Identification"
                subtitle="Core identifiers and status for this real property record."
                error={duplicateTdnError}
              >
                <Grid container spacing={2}>
                  <Grid item xs={12} sm={6} md={3}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Tax Declaration Number"
                      value={formData.tax_declaration_number}
                      onChange={(e) => handleInputChange('tax_declaration_number', e.target.value)}
                      required
                      error={duplicateTdnError}
                      helperText={duplicateTdnError ? 'This Tax Declaration Number already exists. Please use a different number.' : ''}
                      inputProps={{
                        style: { textTransform: 'uppercase', fontWeight: 600 }
                      }}
                      sx={{
                        '& .MuiOutlinedInput-root': {
                          backgroundColor: theme.palette.background.paper
                        }
                      }}
                    />
                  </Grid>

                  <Grid item xs={12} sm={6} md={3}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Previous Tax Dec. Number"
                      value={formData.previous_tax_declaration_number}
                      onChange={(e) => handleInputChange('previous_tax_declaration_number', e.target.value)}
                      helperText="Use ';' to separate multiple previous TDs"
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                    />
                  </Grid>

                  <Grid item xs={12} sm={6} md={3}>
                    <TextField
                      fullWidth
                      size="small"
                      label="PIN (Property Identification No.)"
                      value={formData.pin}
                      onChange={(e) => handleInputChange('pin', e.target.value)}
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                    />
                  </Grid>

                  <Grid item xs={12} sm={6} md={3}>
                    <FormControl fullWidth size="small">
                      <InputLabel id="property-state-label">Property State</InputLabel>
                      <Select
                        labelId="property-state-label"
                        value={formData.property_state || 'CURRENT'}
                        onChange={(e) => handleInputChange('property_state', e.target.value)}
                        label="Property State"
                      >
                        <MenuItem value="CURRENT">CURRENT</MenuItem>
                        <MenuItem value="CANCELLED">CANCELLED</MenuItem>
                        <MenuItem value="INTERIM">INTERIM</MenuItem>
                        <MenuItem value="PENDING">PENDING</MenuItem>
                      </Select>
                    </FormControl>
                  </Grid>
                </Grid>
              </FormSection>

              {/* ---------------- 2. OWNER / DECLARANT ---------------- */}
              <FormSection
                icon={PersonIcon}
                title="Owner / Declarant"
                subtitle="Primary property owner, administrator, and declared correspondence address."
              >
                <Grid container spacing={2}>
                  <Grid item xs={12} sm={4} md={4}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Declarant Last Name"
                      value={formData.declarant_last_name}
                      onChange={(e) => handleInputChange('declarant_last_name', e.target.value)}
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                    />
                  </Grid>

                  <Grid item xs={12} sm={4} md={4}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Declarant First Name"
                      value={formData.declarant_first_name}
                      onChange={(e) => handleInputChange('declarant_first_name', e.target.value)}
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                    />
                  </Grid>

                  <Grid item xs={12} sm={4} md={4}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Middle Name / Initial"
                      value={formData.declarant_middle_initial}
                      onChange={(e) => {
                        const raw = String(e.target.value || '').replace(/\./g, '');
                        handleInputChange('declarant_middle_initial', raw);
                      }}
                      inputProps={{ maxLength: 255, style: { textTransform: 'uppercase' } }}
                    />
                  </Grid>

                  <Grid item xs={12} md={5}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Administrator / Business Name"
                      value={formData.business_name}
                      onChange={(e) => handleInputChange('business_name', e.target.value)}
                      placeholder="Optional administrator or commercial entity"
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                    />
                  </Grid>

                  <Grid item xs={12} md={7}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Declarant Address"
                      value={formData.address}
                      onChange={(e) => handleInputChange('address', e.target.value)}
                      placeholder="Complete street, barangay, or municipality address"
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                    />
                  </Grid>
                </Grid>
              </FormSection>

              {/* ---------------- 3. PROPERTY LOCATION & CLASSIFICATION ---------------- */}
              <FormSection
                icon={LocationIcon}
                title="Property Location & Classification"
                subtitle="Official geographic location, cadastre, title records, and land use class."
              >
                <Grid container spacing={2}>
                  <Grid item xs={12}>
                    <FormControl
                      fullWidth
                      size="small"
                      required
                      onKeyDown={(e) => {
                        if (e.key.length === 1 && /[a-zA-Z]/.test(e.key)) {
                          e.preventDefault();
                          const newValue = handleKeyboardNavigation(
                            locationOptions.map(loc => loc.name),
                            formData.location,
                            e.key,
                            null,
                            'location'
                          );
                          handleInputChange('location', newValue);
                        }
                      }}
                    >
                      <InputLabel id="location-select-label">Location (Barangay)</InputLabel>
                      <Select
                        labelId="location-select-label"
                        value={
                          !optionsLoading && locationOptions.length > 0 && locationOptions.some(loc => loc.name === formData.location)
                            ? formData.location
                            : ''
                        }
                        label="Location (Barangay)"
                        onChange={(e) => handleInputChange('location', e.target.value)}
                        disabled={optionsLoading || locationOptions.length === 0}
                        MenuProps={{ PaperProps: { style: { maxHeight: 300 } } }}
                      >
                        {optionsLoading ? (
                          <MenuItem disabled>Loading locations...</MenuItem>
                        ) : optionsError ? (
                          <MenuItem disabled>
                            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                              <Typography variant="body2" color="error">
                                Error loading locations
                              </Typography>
                              <Button
                                size="small"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  retryLoadOptions();
                                }}
                                sx={{ minWidth: 'auto', p: 0.5 }}
                              >
                                Retry
                              </Button>
                            </Box>
                          </MenuItem>
                        ) : locationOptions.length === 0 ? (
                          <MenuItem disabled>No locations available</MenuItem>
                        ) : (
                          locationOptions.map(loc => (
                            <MenuItem key={loc.code} value={loc.name}>{loc.name}</MenuItem>
                          ))
                        )}
                      </Select>
                    </FormControl>
                  </Grid>

                  <Grid item xs={12} sm={6}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Lot Number"
                      value={formData.lot_number}
                      onChange={(e) => handleInputChange('lot_number', e.target.value)}
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                    />
                  </Grid>

                  <Grid item xs={12} sm={6}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Survey Number"
                      value={formData.survey_number}
                      onChange={(e) => handleInputChange('survey_number', e.target.value)}
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                    />
                  </Grid>

                  <Grid item xs={12} sm={6}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Title Number (OCT / TCT / CLOA)"
                      value={formData.title_number}
                      onChange={(e) => handleInputChange('title_number', e.target.value)}
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                    />
                  </Grid>

                  <Grid item xs={12} sm={6}>
                    <FormControl
                      fullWidth
                      size="small"
                      required
                      onKeyDown={(e) => {
                        if (e.key.length === 1 && /[a-zA-Z]/.test(e.key)) {
                          e.preventDefault();
                          const newValue = handleKeyboardNavigation(
                            propertyTypeOptions,
                            formData.kind_of_property,
                            e.key,
                            'code',
                            'kind_of_property'
                          );
                          handleInputChange('kind_of_property', newValue);
                        }
                      }}
                    >
                      <InputLabel id="kind-of-property-label">Kind of Property</InputLabel>
                      <Select
                        labelId="kind-of-property-label"
                        value={
                          !optionsLoading && propertyTypeOptions.length > 0 && propertyTypeOptions.some(pt => pt.code === formData.kind_of_property)
                            ? formData.kind_of_property
                            : ''
                        }
                        label="Kind of Property"
                        onChange={(e) => handleInputChange('kind_of_property', e.target.value)}
                        disabled={optionsLoading || propertyTypeOptions.length === 0}
                        MenuProps={{ PaperProps: { style: { maxHeight: 300 } } }}
                      >
                        {optionsLoading ? (
                          <MenuItem disabled>Loading property types...</MenuItem>
                        ) : optionsError ? (
                          <MenuItem disabled>Error loading property types</MenuItem>
                        ) : (
                          propertyTypeOptions.map(pt => (
                            <MenuItem key={pt.code} value={pt.code}>{pt.name}</MenuItem>
                          ))
                        )}
                      </Select>
                    </FormControl>
                  </Grid>

                  <Grid item xs={12}>
                    <FormControl
                      fullWidth
                      size="small"
                      onKeyDown={(e) => {
                        if (e.key.length === 1 && /[a-zA-Z]/.test(e.key)) {
                          e.preventDefault();
                          const newValue = handleKeyboardNavigation(
                            generalClassOptions,
                            formData.gen_class,
                            e.key,
                            'code',
                            'gen_class'
                          );
                          handleInputChange('gen_class', newValue);
                        }
                      }}
                    >
                      <InputLabel id="general-class-label">General Classification</InputLabel>
                      <Select
                        labelId="general-class-label"
                        value={
                          !optionsLoading && generalClassOptions.length > 0 && generalClassOptions.some(gc => gc.code === formData.gen_class)
                            ? formData.gen_class
                            : ''
                        }
                        label="General Classification"
                        onChange={(e) => handleInputChange('gen_class', e.target.value)}
                        disabled={optionsLoading || generalClassOptions.length === 0}
                        MenuProps={{ PaperProps: { style: { maxHeight: 300 } } }}
                      >
                        {optionsLoading ? (
                          <MenuItem disabled>Loading general classes...</MenuItem>
                        ) : optionsError ? (
                          <MenuItem disabled>Error loading general classes</MenuItem>
                        ) : (
                          generalClassOptions.map(gc => (
                            <MenuItem key={gc.code} value={gc.code}>{gc.name}</MenuItem>
                          ))
                        )}
                      </Select>
                    </FormControl>
                  </Grid>
                </Grid>
              </FormSection>

              {/* ---------------- 4. ASSESSMENT & VALUATION ---------------- */}
              <FormSection
                icon={ValuationIcon}
                title="Assessment & Valuation"
                subtitle="Calculated taxable area, assessed valuations, assessment date, and effectivity status."
                sx={{
                  borderLeft: 3,
                  borderLeftColor: theme.palette.primary.main
                }}
              >
                <Grid container spacing={2}>
                  {/* Current Area input with Ha / Sqm Unit Switcher */}
                  <Grid item xs={12} sm={Boolean(property && property.area_hectare_old) ? 6 : 6} md={3}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Area"
                      value={formData.area_unit === 'hectares' ? formData.area_hectare : formData.area_sqm}
                      onChange={(e) => {
                        const value = e.target.value;
                        if (formData.area_unit === 'hectares') {
                          handleInputChange('area_hectare', value);
                        } else {
                          handleInputChange('area_sqm', value);
                        }
                      }}
                      type="number"
                      inputProps={{ min: 0, step: formData.area_unit === 'hectares' ? 0.0001 : 0.01 }}
                      placeholder={formData.area_unit === 'hectares' ? '0.0000' : '0.00'}
                      onBlur={() => {
                        const v = formData.area_unit === 'hectares' ? formData.area_hectare : formData.area_sqm;
                        if (v === '' || v === null || v === undefined) return;
                        const n = Number(v);
                        if (!isNaN(n)) {
                          if (formData.area_unit === 'hectares') {
                            setFormData(prev => ({ ...prev, area_hectare: n.toFixed(4) }));
                          } else {
                            setFormData(prev => ({ ...prev, area_sqm: n.toFixed(2) }));
                          }
                        }
                      }}
                      InputProps={{
                        endAdornment: (
                          <FormControl sx={{ minWidth: 64, ml: 0.5 }}>
                            <Select
                              value={formData.area_unit}
                              onChange={(e) => {
                                const newUnit = e.target.value;
                                setFormData(prev => {
                                  const next = { ...prev, area_unit: newUnit };
                                  const numHa = Number(prev.area_hectare);
                                  const numSqm = Number(prev.area_sqm);
                                  const hasHa = prev.area_hectare !== undefined && prev.area_hectare !== null && prev.area_hectare !== '' && !isNaN(numHa);
                                  const hasSqm = prev.area_sqm !== undefined && prev.area_sqm !== null && prev.area_sqm !== '' && !isNaN(numSqm);
                                  if (newUnit === 'hectares') {
                                    if (!hasHa && hasSqm) {
                                      next.area_hectare = (numSqm / 10000).toFixed(4);
                                      next.area_sqm = '';
                                    } else if (hasHa) {
                                      next.area_sqm = '';
                                    }
                                  } else if (newUnit === 'sqm') {
                                    if (!hasSqm && hasHa) {
                                      next.area_sqm = (numHa * 10000).toFixed(2);
                                      next.area_hectare = '';
                                    } else if (hasSqm) {
                                      next.area_hectare = '';
                                    }
                                  }
                                  return next;
                                });
                              }}
                              sx={{
                                '& .MuiSelect-select': { py: 0.25, px: 0.75, fontSize: '0.8rem', fontWeight: 600 },
                                '& .MuiOutlinedInput-notchedOutline': { border: 'none' }
                              }}
                            >
                              <MenuItem value="hectares">ha</MenuItem>
                              <MenuItem value="sqm">sqm</MenuItem>
                            </Select>
                          </FormControl>
                        )
                      }}
                    />
                  </Grid>

                  {/* Previous Area (Historical) if applicable */}
                  {Boolean(property && property.area_hectare_old) && (
                    <Grid item xs={12} sm={6} md={3}>
                      <TextField
                        fullWidth
                        size="small"
                        label="Previous Area (Old)"
                        value={formData.area_hectare_old ?? ''}
                        onChange={(e) => handleInputChange('area_hectare_old', e.target.value)}
                        placeholder="Historical area string"
                        helperText="Clear to remove old area"
                        sx={{
                          '& .MuiOutlinedInput-root': {
                            backgroundColor: theme.palette.action.hover
                          }
                        }}
                      />
                    </Grid>
                  )}

                  {/* Assessed Value (₱) */}
                  <Grid item xs={12} sm={Boolean(property && property.assessed_value_old) ? 6 : 6} md={3}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Assessed Value"
                      value={formData.assessed_value}
                      onChange={(e) => handleInputChange('assessed_value', e.target.value)}
                      type="number"
                      inputProps={{ min: 0, step: 0.01 }}
                      placeholder="0.00"
                      InputProps={{
                        startAdornment: (
                          <Typography
                            variant="body2"
                            sx={{
                              color: theme.palette.text.secondary,
                              mr: 0.5,
                              fontWeight: 600
                            }}
                          >
                            ₱
                          </Typography>
                        )
                      }}
                      onBlur={() => {
                        const v = formData.assessed_value;
                        if (v === '' || v === null || v === undefined) return;
                        const n = Number(v);
                        if (!isNaN(n)) {
                          handleInputChange('assessed_value', n.toFixed(2));
                        }
                      }}
                    />
                  </Grid>

                  {/* Previous Assessed Value (Historical) if applicable */}
                  {Boolean(property && property.assessed_value_old) && (
                    <Grid item xs={12} sm={6} md={3}>
                      <TextField
                        fullWidth
                        size="small"
                        label="Previous Assessed Value (Old)"
                        value={formData.assessed_value_old}
                        onChange={(e) => handleInputChange('assessed_value_old', e.target.value)}
                        placeholder="Historical value notes"
                        sx={{
                          '& .MuiOutlinedInput-root': {
                            backgroundColor: theme.palette.action.hover
                          }
                        }}
                      />
                    </Grid>
                  )}

                  {/* Assessment Date */}
                  <Grid item xs={12} sm={6} md={3}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Assessment Date"
                      value={formData.assessment_date}
                      onChange={(e) => handleInputChange('assessment_date', e.target.value)}
                      type="date"
                      InputLabelProps={{ shrink: true }}
                    />
                  </Grid>

                  {/* Effectivity Year with BLANK and EXEMPT buttons */}
                  <Grid item xs={12} sm={6} md={3}>
                    <Box>
                      <TextField
                        fullWidth
                        size="small"
                        label="Effectivity Year"
                        value={formData.effectivity_date}
                        onChange={(e) => {
                          const value = String(e.target.value || '');
                          if (/^\d{0,4}$/.test(value)) {
                            setFormData(prev => ({
                              ...prev,
                              effectivity_date: value,
                              effectivity_exempt: false
                            }));
                          }
                        }}
                        type="text"
                        inputProps={{
                          inputMode: 'numeric',
                          pattern: '[0-9]*',
                          maxLength: 4,
                          style: { fontWeight: 600 }
                        }}
                        placeholder="YYYY"
                        disabled={effectivityIsExempt}
                        helperText={effectivityIsExempt ? 'Property is flagged as EXEMPT' : '4-digit year or blank'}
                      />
                      <Box sx={{ display: 'flex', gap: 1, mt: 0.75 }}>
                        <Button
                          size="small"
                          variant="outlined"
                          disabled={effectivityIsExempt && !formData.effectivity_date}
                          onClick={() => {
                            setFormData(prev => ({
                              ...prev,
                              effectivity_date: '',
                              effectivity_exempt: false
                            }));
                          }}
                          sx={{
                            fontSize: '0.72rem',
                            py: 0.25,
                            px: 1,
                            minWidth: 'auto',
                            borderColor: theme.palette.divider,
                            color: theme.palette.text.secondary
                          }}
                        >
                          BLANK
                        </Button>
                        <Button
                          size="small"
                          variant={effectivityIsExempt ? 'contained' : 'outlined'}
                          color={effectivityIsExempt ? 'primary' : 'inherit'}
                          onClick={() => {
                            setFormData(prev => ({
                              ...prev,
                              effectivity_date: '',
                              effectivity_exempt: !effectivityIsExempt
                            }));
                          }}
                          sx={{
                            fontSize: '0.72rem',
                            py: 0.25,
                            px: 1,
                            minWidth: 'auto',
                            fontWeight: 600
                          }}
                        >
                          EXEMPT
                        </Button>
                      </Box>
                    </Box>
                  </Grid>
                </Grid>
              </FormSection>

              {/* ---------------- 5. MEMORANDA ---------------- */}
              <FormSection
                icon={NotesIcon}
                title="Memoranda"
                subtitle="Official assessment remarks, encumbrances, annotations, and quick standard templates."
              >
                <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1, flexWrap: 'wrap', gap: 1 }}>
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.75, flexWrap: 'wrap' }}>
                    <Typography variant="caption" sx={{ color: theme.palette.text.secondary, fontWeight: 700, mr: 0.5 }}>
                      QUICK INSERT:
                    </Typography>
                    {memorandaTemplates.map(template => (
                      <Button
                        key={template.id}
                        variant="outlined"
                        size="small"
                        sx={{
                          borderRadius: 6,
                          textTransform: 'none',
                          fontSize: '0.75rem',
                          py: 0.2,
                          px: 1.2,
                          minHeight: 26,
                          borderColor: theme.palette.divider,
                          color: theme.palette.text.primary,
                          '&:hover': {
                            borderColor: theme.palette.primary.main,
                            backgroundColor: theme.palette.primary.main + '0a'
                          }
                        }}
                        onClick={() => {
                          const currentText = formData.memoranda ? formData.memoranda + '\n\n' : '';
                          handleInputChange('memoranda', currentText + template.template_text);
                        }}
                      >
                        + {template.title}
                      </Button>
                    ))}
                  </Box>

                  <Tooltip title="Manage Templates">
                    <Button
                      variant="outlined"
                      size="small"
                      color="primary"
                      onClick={() => setManageTemplatesOpen(true)}
                      sx={{
                        fontSize: '0.75rem',
                        py: 0.25,
                        px: 1,
                        minHeight: 26,
                        display: 'flex',
                        alignItems: 'center',
                        gap: 0.5
                      }}
                    >
                      <SettingsIcon sx={{ fontSize: 15 }} />
                      Templates
                    </Button>
                  </Tooltip>
                </Box>

                <TextField
                  fullWidth
                  size="small"
                  multiline
                  rows={4}
                  label="Official Memoranda"
                  value={formData.memoranda}
                  onChange={(e) => handleInputChange('memoranda', e.target.value)}
                  placeholder="Enter assessment remarks, tax exemptions, or administrative annotations..."
                  inputProps={{ style: { textTransform: 'uppercase' } }}
                />
              </FormSection>

              {/* ---------------- 6. SUPPORTING DOCUMENTS ---------------- */}
              <FormSection
                icon={AttachFileIcon}
                title="Supporting Documents"
                subtitle="Upload title deeds, tax receipts, surveyor endorsements, and cadastral maps."
              >
                {/* Hidden File Input */}
                <input
                  accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                  style={{ display: 'none' }}
                  id="supporting-documents-upload"
                  multiple
                  type="file"
                  onChange={(e) => {
                    const files = Array.from(e.target.files);
                    setFormData(prev => ({
                      ...prev,
                      supporting_documents: files
                    }));
                    setPendingUploads(files);
                  }}
                />

                {/* Modern Dashed Upload Zone */}
                <Box
                  component="label"
                  htmlFor="supporting-documents-upload"
                  sx={{
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    justifyContent: 'center',
                    py: 2.5,
                    px: 2,
                    border: '2px dashed',
                    borderColor: theme.palette.divider,
                    borderRadius: 1,
                    backgroundColor: theme.palette.background.paper,
                    cursor: 'pointer',
                    transition: 'all 0.2s ease',
                    textAlign: 'center',
                    '&:hover': {
                      borderColor: theme.palette.primary.main,
                      backgroundColor: theme.palette.primary.main + '05'
                    }
                  }}
                >
                  <CloudUpload sx={{ fontSize: 32, color: theme.palette.primary.main, mb: 0.5 }} />
                  <Typography variant="body2" sx={{ fontWeight: 600, color: theme.palette.text.primary }}>
                    Click to select supporting documents
                  </Typography>
                  <Typography variant="caption" sx={{ color: theme.palette.text.secondary, mt: 0.25 }}>
                    Accepted formats: PDF, DOC, DOCX, JPG, JPEG, PNG
                  </Typography>
                </Box>

                {/* Pending Upload Queue */}
                {Array.isArray(pendingUploads) && pendingUploads.length > 0 && (
                  <Box sx={{ mt: 2 }}>
                    <Typography variant="caption" sx={{ fontWeight: 700, color: theme.palette.text.secondary, display: 'block', mb: 1 }}>
                      PENDING UPLOADS (SAVED ON SUBMISSION):
                    </Typography>
                    <Box sx={{ display: 'flex', flexDirection: 'column', gap: 0.75 }}>
                      {pendingUploads.map((file, idx) => (
                        <Box
                          key={idx}
                          sx={{
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'space-between',
                            p: 1,
                            px: 1.5,
                            borderRadius: 1,
                            border: 1,
                            borderColor: theme.palette.divider,
                            backgroundColor: theme.palette.background.paper
                          }}
                        >
                          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, minWidth: 0 }}>
                            <FileIcon sx={{ fontSize: 18, color: theme.palette.primary.main, flexShrink: 0 }} />
                            <Typography variant="body2" noWrap sx={{ fontSize: '0.82rem', fontWeight: 500 }}>
                              {file?.name || String(file)}
                            </Typography>
                            {file?.size && (
                              <Typography variant="caption" sx={{ color: theme.palette.text.secondary, flexShrink: 0 }}>
                                ({(file.size / 1024).toFixed(1)} KB)
                              </Typography>
                            )}
                          </Box>

                          <IconButton
                            size="small"
                            onClick={() => {
                              const updated = pendingUploads.filter((_, i) => i !== idx);
                              setPendingUploads(updated);
                              setFormData(prev => ({ ...prev, supporting_documents: updated }));
                            }}
                            sx={{ color: theme.palette.text.secondary, '&:hover': { color: theme.palette.error.main } }}
                          >
                            <CloseIcon fontSize="small" />
                          </IconButton>
                        </Box>
                      ))}
                    </Box>
                  </Box>
                )}

                {/* Existing Documents Cards */}
                {Array.isArray(existingDocuments) && existingDocuments.length > 0 && (
                  <Box sx={{ mt: 2.5 }}>
                    <Typography variant="caption" sx={{ fontWeight: 700, color: theme.palette.text.secondary, display: 'block', mb: 1 }}>
                      RECORD DOCUMENTS ({existingDocuments.length}):
                    </Typography>
                    <Grid container spacing={1.5}>
                      {existingDocuments.map((doc, idx) => {
                        const ext = String(doc.file_type || '').toLowerCase();
                        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
                        const isLegacy = !doc.id;

                        return (
                          <Grid item key={doc.id || idx} xs={12} sm={6} md={4} lg={3}>
                            <Box
                              sx={{
                                border: 1,
                                borderColor: theme.palette.divider,
                                borderRadius: 1,
                                p: 1.25,
                                backgroundColor: theme.palette.background.paper,
                                display: 'flex',
                                flexDirection: 'column',
                                height: '100%',
                                position: 'relative'
                              }}
                            >
                              {/* Header: file name & delete button */}
                              <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
                                <Typography
                                  variant="body2"
                                  noWrap
                                  sx={{ fontWeight: 600, fontSize: '0.8rem', mr: 1 }}
                                  title={doc.original_filename || doc.filename}
                                >
                                  {doc.original_filename || doc.filename}
                                </Typography>

                                {!isLegacy ? (
                                  <IconButton
                                    size="small"
                                    color="error"
                                    onClick={() => {
                                      setDocumentsToDelete(prev => (prev.includes(doc.id) ? prev : [...prev, doc.id]));
                                      setExistingDocuments(prev => prev.filter(d => d.id !== doc.id));
                                      setToast({ open: true, message: 'Document marked for deletion. Save to apply.', severity: 'info' });
                                    }}
                                    title="Delete document"
                                    sx={{ p: 0.5 }}
                                  >
                                    <DeleteIcon fontSize="small" />
                                  </IconButton>
                                ) : (
                                  <Chip
                                    label="Legacy"
                                    size="small"
                                    variant="outlined"
                                    sx={{ height: 18, fontSize: '0.65rem' }}
                                  />
                                )}
                              </Box>

                              {/* Body: Preview image or file action */}
                              <Box sx={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: 90, backgroundColor: theme.palette.background.default, borderRadius: 1, overflow: 'hidden', mb: 1 }}>
                                {isImage ? (
                                  <img
                                    src={doc.file_url}
                                    alt={doc.original_filename || doc.filename}
                                    style={{ width: '100%', height: 90, objectFit: 'cover', cursor: 'pointer' }}
                                    onClick={() => setDocPreview({ open: true, src: doc.file_url, filename: doc.original_filename || doc.filename })}
                                  />
                                ) : (
                                  <Box sx={{ textAlign: 'center', p: 1 }}>
                                    <FileIcon sx={{ fontSize: 32, color: theme.palette.primary.main }} />
                                    <Typography variant="caption" sx={{ display: 'block', textTransform: 'uppercase', mt: 0.5, fontWeight: 600 }}>
                                      {ext || 'FILE'}
                                    </Typography>
                                  </Box>
                                )}
                              </Box>

                              {/* Action Footer */}
                              <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mt: 'auto' }}>
                                {isImage ? (
                                  <Button
                                    size="small"
                                    startIcon={<VisibilityIcon fontSize="small" />}
                                    onClick={() => setDocPreview({ open: true, src: doc.file_url, filename: doc.original_filename || doc.filename })}
                                    sx={{ fontSize: '0.75rem', p: 0.25 }}
                                  >
                                    Preview
                                  </Button>
                                ) : (
                                  <Button
                                    size="small"
                                    onClick={() => window.open(doc.file_url, '_blank')}
                                    sx={{ fontSize: '0.75rem', p: 0.25 }}
                                  >
                                    Open File
                                  </Button>
                                )}
                              </Box>
                            </Box>
                          </Grid>
                        );
                      })}
                    </Grid>
                  </Box>
                )}
              </FormSection>
            </Box>
          </form>
        </DialogContent>

        {/* ================= C. STABLE / STICKY FOOTER ================= */}
        <DialogActions
          sx={{
            py: 1.5,
            px: { xs: 2, sm: 3 },
            borderTop: 1,
            borderColor: 'divider',
            backgroundColor: theme.palette.background.default,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            flexShrink: 0
          }}
        >
          <Typography
            variant="caption"
            sx={{
              color: theme.palette.text.secondary,
              display: { xs: 'none', sm: 'block' }
            }}
          >
            Required fields are marked with an asterisk (*). Changes take effect immediately upon save.
          </Typography>

          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, ml: 'auto' }}>
            <Button
              onClick={onCancel}
              variant="outlined"
              disabled={loading}
              sx={{ minWidth: 90 }}
            >
              Cancel
            </Button>

            <Button
              onClick={handleSubmit}
              variant="contained"
              disabled={loading || optionsLoading}
              startIcon={
                loading ? (
                  <CircularProgress size={16} color="inherit" />
                ) : (
                  <SaveIcon />
                )
              }
              sx={{ minWidth: 140 }}
            >
              {loading ? 'Saving...' : (property ? 'Update Property' : 'Create Property')}
            </Button>
          </Box>
        </DialogActions>
      </Dialog>

      {/* Image Preview Dialog */}
      <Dialog
        open={docPreview.open}
        onClose={() => setDocPreview({ open: false, src: '', filename: '' })}
        maxWidth="md"
        fullWidth
        PaperProps={{ sx: { borderRadius: 1 } }}
      >
        <DialogTitle sx={{ py: 1.5, px: 2, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>{docPreview.filename}</Typography>
          <IconButton size="small" onClick={() => setDocPreview({ open: false, src: '', filename: '' })}>
            <CloseIcon fontSize="small" />
          </IconButton>
        </DialogTitle>
        <DialogContent sx={{ p: 2, textAlign: 'center', backgroundColor: theme.palette.background.default }}>
          {docPreview.src ? (
            <img src={docPreview.src} alt={docPreview.filename} style={{ maxWidth: '100%', maxHeight: '70vh', objectFit: 'contain' }} />
          ) : null}
        </DialogContent>
      </Dialog>

      {/* Manage Templates Dialog */}
      <Dialog open={manageTemplatesOpen} onClose={() => {
        setManageTemplatesOpen(false);
        setEditingTemplateId(null);
      }} maxWidth="sm" fullWidth>
        <DialogTitle>Manage Memoranda Templates</DialogTitle>
        <DialogContent>
          <Box mb={2} mt={1}>
            <Typography variant="subtitle2" gutterBottom>{editingTemplateId ? 'Edit Template' : 'Add New Template'}</Typography>
            <TextField
              fullWidth
              size="small"
              label="Title"
              id="new-template-title"
              sx={{ mb: 1.5 }}
            />
            <TextField
              fullWidth
              size="small"
              label="Memoranda teplate"
              multiline
              rows={3}
              id="new-template-text"
              sx={{ mb: 1.5 }}
            />
            <Box display="flex" gap={1}>
              <Button
                variant="contained"
                color="primary"
                onClick={async () => {
                  const titleInput = document.getElementById('new-template-title');
                  const textInput = document.getElementById('new-template-text');
                  if (!titleInput.value.trim() || !textInput.value.trim()) return;

                  try {
                    setOptionsLoading(true);
                    const payload = {
                      title: titleInput.value.trim(),
                      template_text: textInput.value.trim()
                    };
                    if (editingTemplateId) {
                      payload.id = editingTemplateId;
                    }
                    const res = await apiService.saveMemorandaTemplate(payload);
                    if (res && res.templates) {
                      setMemorandaTemplates(res.templates);
                    }
                    titleInput.value = '';
                    textInput.value = '';
                    setEditingTemplateId(null);
                  } catch (err) {
                    console.error('Failed to save template', err);
                  } finally {
                    setOptionsLoading(false);
                  }
                }}
                disabled={optionsLoading}
              >
                {editingTemplateId ? 'Save Changes' : 'Add Template'}
              </Button>
              {editingTemplateId && (
                <Button
                  variant="outlined"
                  onClick={() => {
                    setEditingTemplateId(null);
                    document.getElementById('new-template-title').value = '';
                    document.getElementById('new-template-text').value = '';
                  }}
                  disabled={optionsLoading}
                >
                  Cancel
                </Button>
              )}
            </Box>
          </Box>
          <Divider sx={{ my: 2 }} />
          <Typography variant="subtitle2" gutterBottom>Existing Templates</Typography>
          {memorandaTemplates.length === 0 ? (
            <Typography variant="body2" color="text.secondary">No templates found.</Typography>
          ) : (
            <List dense>
              {memorandaTemplates.map(template => (
                <ListItem key={template.id} divider>
                  <ListItemText
                    primary={template.title}
                    secondary={template.template_text.substring(0, 50) + (template.template_text.length > 50 ? '...' : '')}
                  />
                  <ListItemSecondaryAction>
                    <IconButton edge="end" aria-label="edit" disabled={optionsLoading} sx={{ mr: 1 }} onClick={() => {
                      setEditingTemplateId(template.id);
                      document.getElementById('new-template-title').value = template.title;
                      document.getElementById('new-template-text').value = template.template_text;
                    }}>
                      <EditIcon fontSize="small" />
                    </IconButton>
                    <IconButton edge="end" aria-label="delete" disabled={optionsLoading} onClick={async () => {
                      try {
                        setOptionsLoading(true);
                        await apiService.deleteMemorandaTemplate(template.id);
                        const res = await apiService.getMemorandaTemplates();
                        if (res && res.templates) {
                          setMemorandaTemplates(res.templates);
                        }
                        if (editingTemplateId === template.id) {
                          setEditingTemplateId(null);
                          document.getElementById('new-template-title').value = '';
                          document.getElementById('new-template-text').value = '';
                        }
                      } catch (err) {
                        console.error('Failed to delete template', err);
                      } finally {
                        setOptionsLoading(false);
                      }
                    }}>
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  </ListItemSecondaryAction>
                </ListItem>
              ))}
            </List>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => {
            setManageTemplatesOpen(false);
            setEditingTemplateId(null);
          }}>Close</Button>
        </DialogActions>
      </Dialog>
    </>
  );
};

export default PropertyFormModal;
