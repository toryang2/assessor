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
  Card,
  CardContent,
  Snackbar,
  Dialog,
  DialogTitle,
  DialogContent
} from '@mui/material';
import { motion } from 'framer-motion';
import { CloudUpload } from '@mui/icons-material';

import { apiService, uploadFile } from '../../utils/api';
import useLoadingWatchdog from '../../hooks/useLoadingWatchdog';

const PropertyFormModal = ({ property, onSave, onCancel, open, onClose }) => {
  const isSmallScreen = (() => {
    try {
      const w = window.innerWidth;
      const h = window.innerHeight;
      return (w <= 1280 && h <= 720) || (w <= 1366 && h <= 768) || (w <= 1920 && h <= 1080);
    } catch (_) {
      return false;
    }
  })();
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
  const [propertyTypeOptions, setPropertyTypeOptions] = useState([]);
  const [generalClassOptions, setGeneralClassOptions] = useState([]);
  const [locationOptions, setLocationOptions] = useState([]);
  const [existingDocuments, setExistingDocuments] = useState([]);
  const [docPreview, setDocPreview] = useState({ open: false, src: '', filename: '' });
  const [pendingUploads, setPendingUploads] = useState([]);
  const [documentsToDelete, setDocumentsToDelete] = useState([]);
  const [optionsLoading, setOptionsLoading] = useState(true);
  const [optionsError, setOptionsError] = useState(null);
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

  // Helper: current datetime in Asia/Manila (+08:00) as SQL string YYYY-MM-DD HH:mm:ss
  const nowInPHTSql = () => {
    const now = new Date();
    const utcMs = now.getTime() + (now.getTimezoneOffset() * 60000);
    const ph = new Date(utcMs + (8 * 60 * 60000));
    const pad = (n) => String(n).padStart(2, '0');
    const yyyy = ph.getFullYear();
    const mm = pad(ph.getMonth() + 1);
    const dd = pad(ph.getDate());
    const HH = pad(ph.getHours());
    const MM = pad(ph.getMinutes());
    const SS = pad(ph.getSeconds());
    return `${yyyy}-${mm}-${dd} ${HH}:${MM}:${SS}`;
  };

  useEffect(() => {
    if (property) {
      setFormData({
        tax_declaration_number: property.tax_declaration_number || '',
        previous_tax_declaration_number: property.previous_tax_declaration_number || '',
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
        effectivity_date: extractEffectivityYear(property.effectivity_date),
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
       setFormData({
         tax_declaration_number: '',
         previous_tax_declaration_number: '',
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
          setExistingDocuments([ ...legacy, ...docs ]);
        } else {
          const legacy = parseLegacySupportingDocuments(property);
          setExistingDocuments([ ...legacy ]);
        }
      } catch (_) {
        const legacy = parseLegacySupportingDocuments(property);
        setExistingDocuments([ ...legacy ]);
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
        const [typesRes, classesRes, locationsRes] = await Promise.all([
          apiService.getPropertyTypes(),
          apiService.getGeneralClasses(),
          apiService.getLocations()
        ]);
        
        const types = (Array.isArray(typesRes?.items) ? typesRes.items : []).filter(i => i.status === 'active');
        const classes = (Array.isArray(classesRes?.items) ? classesRes.items : []).filter(i => i.status === 'active');
        const locations = (Array.isArray(locationsRes?.items) ? locationsRes.items : []).filter(i => i.status === 'active');
        
        // Validate that we got meaningful data
        if (types.length === 0 || classes.length === 0 || locations.length === 0) {
          throw new Error('Incomplete options data received from server');
        }
        
        // Update state
        setPropertyTypeOptions(types);
        setGeneralClassOptions(classes);
        setLocationOptions(locations);
        
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
      const [typesRes, classesRes, locationsRes] = await Promise.all([
        apiService.getPropertyTypes(),
        apiService.getGeneralClasses(),
        apiService.getLocations()
      ]);
      
      const types = (Array.isArray(typesRes?.items) ? typesRes.items : []).filter(i => i.status === 'active');
      const classes = (Array.isArray(classesRes?.items) ? classesRes.items : []).filter(i => i.status === 'active');
      const locations = (Array.isArray(locationsRes?.items) ? locationsRes.items : []).filter(i => i.status === 'active');
      
      if (types.length === 0 || classes.length === 0 || locations.length === 0) {
        throw new Error('Incomplete options data received from server');
      }
      
      setPropertyTypeOptions(types);
      setGeneralClassOptions(classes);
      setLocationOptions(locations);
      
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
    } catch (_) {}
    
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

      // Pull signatories from settings to store on the property record
      let settings = null;
      try {
        settings = await apiService.getSettings();
      } catch (_) {}

      // Only submit the selected unit; blank the other
      const submitData = {
        tax_declaration_number: uppercaseFieldValue('tax_declaration_number', formData.tax_declaration_number),
        previous_tax_declaration_number: uppercaseFieldValue('previous_tax_declaration_number', formData.previous_tax_declaration_number),
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
        effectivity_date: formData.effectivity_date,
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

      // Add timestamps based on create vs update
      if (property) {
        // Update: only set updated_at
        submitData.updated_at = nowInPHTSql();
      } else {
        // Create: set both created_at and updated_at
        const ts = nowInPHTSql();
        submitData.created_at = ts;
        submitData.updated_at = ts;
      }

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

  const propertyTypes = propertyTypeOptions.map(o => o.code);

  if (!open) return null;

  return (
    <Dialog 
      open={open} 
      onClose={onClose || onCancel} 
      scroll="body"
      maxWidth={false}
      fullWidth
      PaperProps={{
        sx: {
          maxHeight: '90vh',
          width: isSmallScreen ? '85vw' : '96vw',
          maxWidth: 'none'
        }
      }}
    >
      <DialogTitle>
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <Typography variant="h6">
            {property ? 'Edit Property' : 'Add New Property'}
          </Typography>
        </Box>
      </DialogTitle>
      <DialogContent
        sx={
          isSmallScreen
            ? {
                overflowY: 'visible',
                '& .MuiTextField-root': { width: '100%', mb: 1 },
                '& .MuiInputBase-root': { fontSize: '0.82rem' },
                '& .MuiFormLabel-root': { fontSize: '0.82rem' },
                '& .MuiFormHelperText-root': { fontSize: '0.72rem', lineHeight: 1.2 },
                '& .MuiMenuItem-root': { fontSize: '0.82rem' },
                '& .MuiOutlinedInput-input': { padding: '8.5px 12px' },
                '& .MuiSelect-select.MuiInputBase-input': { padding: '8.5px 32px 8.5px 12px' },
                '& .MuiInputLabel-outlined': { transform: 'translate(14px, 9px) scale(1)' },
                '& .MuiInputLabel-outlined.MuiInputLabel-shrink': { transform: 'translate(14px, -9px) scale(0.75)' },
                '& .MuiButton-root': { padding: '6px 12px' }
              }
            : {
                overflowY: 'visible',
                '& .MuiTextField-root': { width: '100%' },
                '& .MuiInputBase-root': { fontSize: '0.86rem' },
                '& .MuiFormLabel-root': { fontSize: '0.86rem' },
                '& .MuiFormHelperText-root': { fontSize: '0.76rem', lineHeight: 1.2 },
                '& .MuiMenuItem-root': { fontSize: '0.86rem' },
                '& .MuiOutlinedInput-input': { padding: '8.5px 12px' },
                '& .MuiSelect-select.MuiInputBase-input': { padding: '8.5px 32px 8.5px 12px' },
                '& .MuiInputLabel-outlined': { transform: 'translate(14px, 9px) scale(1)' },
                '& .MuiInputLabel-outlined.MuiInputLabel-shrink': { transform: 'translate(14px, -9px) scale(0.75)' }
              }
        }
      >
        <Snackbar
          open={toast.open}
          autoHideDuration={3000}
          onClose={() => setToast(prev => ({ ...prev, open: false }))}
          anchorOrigin={{ vertical: 'top', horizontal: 'center' }}
        >
          <Alert onClose={() => setToast(prev => ({ ...prev, open: false }))} severity={toast.severity} sx={{ width: '100%' }}>
            {toast.message}
          </Alert>
        </Snackbar>
        <form onSubmit={handleSubmit}>
          {/* Options Loading Error Display */}
          {optionsError && (
            <Alert 
              severity="error" 
              sx={{ mb: 2 }}
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
            >
              {optionsError}
            </Alert>
          )}

          {/* Tax Declaration Information */}
          <Box sx={{ mb: 0, ml: 2 }}>
            <Typography variant="h6" gutterBottom>
              Tax Declaration Information
            </Typography>
            <Grid
                container
                spacing={isSmallScreen ? 1 : 2}
                sx={{
                  '& > .MuiGrid-item': {
                    flexBasis: { md: '19.8%' },
                    maxWidth: { md: '19.8%' }
                  }
                }}
              >
              <Grid item xs={12} md={3}>
                <TextField
                  label="Tax Declaration Number"
                  value={formData.tax_declaration_number}
                  onChange={(e) => handleInputChange('tax_declaration_number', e.target.value)}
                  required
                  error={duplicateTdnError}
                  helperText={duplicateTdnError ? 'This Tax Declaration Number already exists. Please use a different number.' : ''}
                  onFocus={() => console.log('TDN field focused, duplicateTdnError:', duplicateTdnError)}
                  inputProps={{
                    'data-debug': `duplicateTdnError: ${duplicateTdnError}`,
                    style: { textTransform: 'uppercase' }
                  }}
                />
              </Grid>

              <Grid item xs={12} md={3}>
                <TextField
                  label="Previous Tax Declaration Number"
                  value={formData.previous_tax_declaration_number}
                  onChange={(e) => handleInputChange('previous_tax_declaration_number', e.target.value)}
                  helperText="Optional: Use ';' to enter multiple previous TDs (consolidated)"
                  inputProps={{ style: { textTransform: 'uppercase' } }}
                />
              </Grid>

              <Grid item xs={12} md={3}>
                <TextField
                  label="PIN"
                  value={formData.pin}
                  onChange={(e) => handleInputChange('pin', e.target.value)}
                  inputProps={{ style: { textTransform: 'uppercase' } }}
                />
              </Grid>

              <Grid item xs={12} md={3}>
                <FormControl fullWidth>
                  <InputLabel>Property State</InputLabel>
                  <Select
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
          </Box>

          {/* Basic Information */}
          <Card sx={{ mb: 1.5 }}>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                Basic Information
              </Typography>
              <Grid
                container
                spacing={isSmallScreen ? 1 : 2}
                sx={{
                  '& > .MuiGrid-item:not(.full-width-row)': {
                    flexBasis: { md: '20%' },
                    maxWidth: { md: '20%' }
                  }
                }}
              >
                <Grid item xs={12} md={3}>
                  <TextField
                    label="Declarant Last Name"
                    value={formData.declarant_last_name}
                    onChange={(e) => handleInputChange('declarant_last_name', e.target.value)}
                    // required
                    inputProps={{ style: { textTransform: 'uppercase' } }}
                  />
                </Grid>

                <Grid item xs={12} md={3}>
                  <TextField
                    label="Declarant First Name"
                    value={formData.declarant_first_name}
                    onChange={(e) => handleInputChange('declarant_first_name', e.target.value)}
                    // required
                    inputProps={{ style: { textTransform: 'uppercase' } }}
                  />
                </Grid>

                <Grid item xs={12} md={3}>
                  <TextField
                    label="Declarant Middle Name/Initial"
                    value={formData.declarant_middle_initial}
                    onChange={(e) => {
                      const raw = String(e.target.value || '').replace(/\./g, '');
                      // allow up to 255 chars per backend change, but typical use is 1-3 letters
                      handleInputChange('declarant_middle_initial', raw);
                    }}
                    inputProps={{ maxLength: 255, style: { textTransform: 'uppercase' } }}
                  />
                </Grid>

                <Grid item xs={12} md={3}>
                  <TextField
                    label="Administrator/Business Name"
                    InputLabelProps={{ sx: { color: 'primary.main' } }}
                    value={formData.business_name}
                    onChange={(e) => handleInputChange('business_name', e.target.value)}
                    inputProps={{ sx: { color: 'primary.main' }, style: { textTransform: 'uppercase' } }}
                    placeholder="Administrator / business name (optional)"
                  />
                </Grid>

                <Grid item xs={12} md={3}>
                  <TextField
                    label="Address"
                    value={formData.address}
                    onChange={(e) => handleInputChange('address', e.target.value)}
                    placeholder="Complete address"
                    inputProps={{ style: { textTransform: 'uppercase' } }}
                  />
                </Grid>

                <Grid item xs={12} md={3}>
                  <TextField
                    label="Title Number"
                    value={formData.title_number}
                    onChange={(e) => handleInputChange('title_number', e.target.value)}
                    inputProps={{ style: { textTransform: 'uppercase' } }}
                  />
                </Grid>

                <Grid item xs={12} md={3}>
                  <TextField
                    label="Lot Number"
                    value={formData.lot_number}
                    onChange={(e) => handleInputChange('lot_number', e.target.value)}
                    inputProps={{ style: { textTransform: 'uppercase' } }}
                  />
                </Grid>

                <Grid item xs={12} md={3}>
                  <TextField
                    label="Survey Number"
                    value={formData.survey_number}
                    onChange={(e) => handleInputChange('survey_number', e.target.value)}
                    inputProps={{ style: { textTransform: 'uppercase' } }}
                   />
                 </Grid>

                <Grid item xs={12} md={3}>
                  {Boolean(property && property.area_hectare_old) ? (
                    <Box sx={{ display: 'flex', gap: 2 }}>
                      <TextField
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
                              const formatted = n.toFixed(4);
                              setFormData(prev => ({ ...prev, area_hectare: formatted }));
                            } else {
                              const formatted = n.toFixed(2);
                              setFormData(prev => ({ ...prev, area_sqm: formatted }));
                            }
                          }
                        }}
                        InputProps={{
                          endAdornment: (
                            <FormControl sx={{ minWidth: 68, ml: 1 }}>
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
                                  '& .MuiSelect-select': { py: 0.5, px: 1, minHeight: 'auto' },
                                  '& .MuiOutlinedInput-notchedOutline': { border: 'none' },
                                  '&:hover .MuiOutlinedInput-notchedOutline': { border: 'none' },
                                  '&.Mui-focused .MuiOutlinedInput-notchedOutline': { border: 'none' }
                                }}
                              >
                                <MenuItem value="hectares">ha</MenuItem>
                                <MenuItem value="sqm">sqm</MenuItem>
                              </Select>
                            </FormControl>
                          )
                        }}
                        sx={{ flex: 1 }}
                      />
                      <TextField
                        label="Area (Hectares - Old)"
                        value={formData.area_hectare_old ?? ''}
                        onChange={(e) => handleInputChange('area_hectare_old', e.target.value)}
                        placeholder="Enter previous area in hectares"
                        inputProps={{}}
                        helperText="Clear this field and save to remove old area (sets to null)."
                        sx={{ flex: 1 }}
                      />
                    </Box>
                  ) : (
                    <TextField
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
                            const formatted = n.toFixed(4);
                            setFormData(prev => ({ ...prev, area_hectare: formatted }));
                          } else {
                            const formatted = n.toFixed(2);
                            setFormData(prev => ({ ...prev, area_sqm: formatted }));
                          }
                        }
                      }}
                      InputProps={{
                        endAdornment: (
                          <FormControl sx={{ minWidth: 68, ml: 1 }}>
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
                                '& .MuiSelect-select': { py: 0.5, px: 1, minHeight: 'auto' },
                                '& .MuiOutlinedInput-notchedOutline': { border: 'none' },
                                '&:hover .MuiOutlinedInput-notchedOutline': { border: 'none' },
                                '&.Mui-focused .MuiOutlinedInput-notchedOutline': { border: 'none' }
                              }}
                            >
                              <MenuItem value="hectares">ha</MenuItem>
                              <MenuItem value="sqm">sqm</MenuItem>
                            </Select>
                          </FormControl>
                        )
                      }}
                    />
                  )}
                </Grid>

                <Grid item xs={12} md={3}>
                  {Boolean(property && property.assessed_value_old) ? (
                    <Box sx={{ display: 'flex', gap: 2 }}>
                      <TextField
                        label="Assessed Value (₱)"
                        value={formData.assessed_value}
                        onChange={(e) => handleInputChange('assessed_value', e.target.value)}
                        type="number"
                        inputProps={{ min: 0, step: 0.01 }}
                        placeholder="0.00"
                        onBlur={() => {
                          const v = formData.assessed_value;
                          if (v === '' || v === null || v === undefined) return;
                          const n = Number(v);
                          if (!isNaN(n)) {
                            handleInputChange('assessed_value', n.toFixed(2));
                          }
                        }}
                        sx={{ flex: 1 }}
                      />
                      <TextField
                        label="Assessed Value (Old)"
                        value={formData.assessed_value_old}
                        onChange={(e) => handleInputChange('assessed_value_old', e.target.value)}
                        inputProps={{}}
                        placeholder="Enter previous assessed value notes"
                        sx={{ flex: 1 }}
                      />
                    </Box>
                  ) : (
                    <TextField
                      label="Assessed Value (₱)"
                      value={formData.assessed_value}
                      onChange={(e) => handleInputChange('assessed_value', e.target.value)}
                      type="number"
                      inputProps={{ min: 0, step: 0.01 }}
                      placeholder="0.00"
                      onBlur={() => {
                        const v = formData.assessed_value;
                        if (v === '' || v === null || v === undefined) return;
                        const n = Number(v);
                        if (!isNaN(n)) {
                          handleInputChange('assessed_value', n.toFixed(2));
                        }
                      }}
                    />
                  )}
                </Grid>

                <Grid item xs={12} md={3}>
                  <TextField
                    label="Effectivity Year"
                    value={formData.effectivity_date}
                    onChange={(e) => handleInputChange('effectivity_date', e.target.value)}
                    type="number"
                    inputProps={{ min: 1800, max: 2100 }}
                    placeholder="YYYY"
                  />
                </Grid>

                <Grid item xs={12} md={3}>
                  <TextField
                    label="Assessment Date"
                    value={formData.assessment_date}
                    onChange={(e) => handleInputChange('assessment_date', e.target.value)}
                    type="date"
                    InputLabelProps={{
                      shrink: true,
                    }}
                    inputProps={{}}
                  />
                </Grid>

                <Grid item xs={12} md={3}>
                  <FormControl 
                    fullWidth
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
                    <InputLabel>Location</InputLabel>
                    <Select
                      value={
                        !optionsLoading && locationOptions.length > 0 && locationOptions.some(loc => loc.name === formData.location)
                          ? formData.location
                          : ''
                      }
                      label="Location"
                      onChange={(e) => handleInputChange('location', e.target.value)}
                      disabled={optionsLoading || locationOptions.length === 0}
                      MenuProps={{
                        PaperProps: {
                          style: {
                            maxHeight: 300,
                          },
                        },
                      }}
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
                
                <Grid item xs={12} className="full-width-row">
                  <Typography variant="h6" gutterBottom>
                    Kind of Property
                  </Typography>
                </Grid>
              
                <Grid item xs={12} md={6} sx={{ display: 'flex', flexDirection: 'column' }}>
                  <FormControl 
                    fullWidth 
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
                    <InputLabel>Kind of Property</InputLabel>
                    <Select
                      value={
                        !optionsLoading && propertyTypeOptions.length > 0 && propertyTypeOptions.some(pt => pt.code === formData.kind_of_property)
                          ? formData.kind_of_property
                          : ''
                      }
                      label="Kind of Property"
                      onChange={(e) => handleInputChange('kind_of_property', e.target.value)}
                      disabled={optionsLoading || propertyTypeOptions.length === 0}
                      MenuProps={{
                        PaperProps: {
                          style: {
                            maxHeight: 300,
                          },
                        },
                      }}
                    >
                      {optionsLoading ? (
                        <MenuItem disabled>Loading property types...</MenuItem>
                      ) : optionsError ? (
                        <MenuItem disabled>
                          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                            <Typography variant="body2" color="error">
                              Error loading property types
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
                      ) : propertyTypeOptions.length === 0 ? (
                        <MenuItem disabled>No property types available</MenuItem>
                      ) : (
                        propertyTypeOptions.map(pt => (
                          <MenuItem key={pt.code} value={pt.code}>{pt.name}</MenuItem>
                        ))
                      )}
                    </Select>
                  </FormControl>
                </Grid>

                <Grid item xs={12} md={6}>
                  <FormControl 
                    fullWidth
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
                    <InputLabel>General Class</InputLabel>
                    <Select
                      value={
                        !optionsLoading && generalClassOptions.length > 0 && generalClassOptions.some(gc => gc.code === formData.gen_class)
                          ? formData.gen_class
                          : ''
                      }
                      label="General Class"
                      onChange={(e) => handleInputChange('gen_class', e.target.value)}
                      disabled={optionsLoading || generalClassOptions.length === 0}
                      MenuProps={{
                        PaperProps: {
                          style: {
                            maxHeight: 300,
                          },
                        },
                      }}
                    >
                      {optionsLoading ? (
                        <MenuItem disabled>Loading general classes...</MenuItem>
                      ) : optionsError ? (
                        <MenuItem disabled>
                          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                            <Typography variant="body2" color="error">
                              Error loading general classes
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
                      ) : generalClassOptions.length === 0 ? (
                        <MenuItem disabled>No general classes available</MenuItem>
                      ) : (
                        generalClassOptions.map(gc => (
                          <MenuItem key={gc.code} value={gc.code}>{gc.name}</MenuItem>
                        ))
                      )}
                    </Select>
                  </FormControl>
                </Grid>
              </Grid>
            </CardContent>
          </Card>

          {/* Kind of Property */}
          {/* <Card sx={{ mb: 1.5 }}>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                Kind of Property
              </Typography>
              <Grid
                container
                spacing={isSmallScreen ? 1.5 : 2}
                sx={{
                  '& > .MuiGrid-item': {
                    flexBasis: { md: '20%' },
                    maxWidth: { md: '20%' }
                  }
                }}
              >
                
              </Grid>
            </CardContent>
          </Card> */}

          {/* Supporting Documents */}
          <Card sx={{ mb: 1 }}>
            <CardContent sx={{ pb: 0, '&:last-child': { pb: 0 } }}>
              <Typography variant="h6" gutterBottom>
                Supporting Documents
              </Typography>
              <Grid
                container
                spacing={isSmallScreen ? 1.5 : 2}
              >
                <Grid item xs={12}>
                  <TextField
                    fullWidth
                    label="Memoranda"
                    value={formData.memoranda}
                    onChange={(e) => handleInputChange('memoranda', e.target.value)}
                    placeholder="Additional notes or memoranda"
                    multiline
                    rows={7}
                    inputProps={{ style: { textTransform: 'uppercase' } }}
                    sx={{
                      '& .MuiInputBase-multiline': {
                        padding: '0.3rem 0 0 0.5rem',
                      },
                      '& .MuiInputBase-multiline textarea': {
                        padding: '0.3rem 0 0 0.5rem',   // top=0, right=0, bottom=0, left=0.5rem
                      }
                    }}
                  />
                </Grid>

                <Grid item xs={12}>
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
                  <label htmlFor="supporting-documents-upload">
                    <Button
                      variant="outlined"
                      component="span"
                      startIcon={<CloudUpload />}
                      fullWidth
                      sx={{ 
                        height: isSmallScreen ? 44 : 56, 
                        borderStyle: 'dashed',
                        borderWidth: 2,
                        '&:hover': {
                          borderStyle: 'solid'
                        }
                      }}
                    >
                      {pendingUploads && pendingUploads.length > 0 
                        ? `${pendingUploads.length} file(s) selected`
                        : 'Upload Supporting Documents'
                      }
                    </Button>
                  </label>

                  {(Array.isArray(pendingUploads) && pendingUploads.length > 0) && (
                    <Box sx={{ mt: 1 }}>
                      <Typography variant="caption" color="text.secondary">
                        Selected (to be uploaded upon save):
                      </Typography>
                      <Box
                        sx={{
                          mt: 0.5,
                          ml: 0,
                          height: 80,
                          minHeight: 80,
                          maxHeight: 80,
                          overflowY: 'auto',
                          overflowX: 'hidden',
                          pr: 0.5
                        }}
                      >
                        {pendingUploads.map((file, index) => (
                          <Typography
                            key={index}
                            variant="body2"
                            component="div"
                            sx={{
                              minWidth: 0,
                              m: 0,
                              lineHeight: 1.25
                            }}
                          >
                            • {(file && file.name) ? file.name : String(file)}
                          </Typography>
                        ))}
                      </Box>
                    </Box>
                  )}
                </Grid>

                {/* Existing documents (edit mode) */}
                <Grid item xs={12}>
                  {Array.isArray(existingDocuments) && existingDocuments.length > 0 && (
                    <Box sx={{ mt: 2 }}>
                      <Typography variant="subtitle2" sx={{ mb: 1 }}>Existing Documents</Typography>
                      <Grid container spacing={1.5}>
                        {existingDocuments.map((doc) => {
                          const ext = String(doc.file_type || '').toLowerCase();
                          const isImage = ['jpg','jpeg','png','gif'].includes(ext);
                          return (
                            <Grid item key={doc.id} xs={12} sm={6} md={4} lg={3}>
                              <Box sx={{ border: '1px solid #eee', p: 1, borderRadius: 1 }}>
                                <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                                  <Typography variant="body2" sx={{ mr: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={doc.original_filename || doc.filename}>
                                    {doc.original_filename || doc.filename}
                                  </Typography>
                                  <Button size="small" color="error" onClick={() => {
                                    // Defer deletion until save; optimistically hide from list
                                    setDocumentsToDelete(prev => (prev.includes(doc.id) ? prev : [...prev, doc.id]));
                                    setExistingDocuments(prev => prev.filter(d => d.id !== doc.id));
                                    setToast({ open: true, message: 'Document marked for deletion. Save to apply.', severity: 'info' });
                                  }}>Delete</Button>
                                </Box>
                                <Box sx={{ mt: 1 }}>
                                  {isImage ? (
                                    <img
                                      src={doc.file_url}
                                      alt={doc.original_filename || doc.filename}
                                      style={{ width: '100%', height: 140, objectFit: 'cover', cursor: 'pointer' }}
                                      onClick={() => setDocPreview({ open: true, src: doc.file_url, filename: doc.original_filename || doc.filename })}
                                    />
                                  ) : (
                                    <Button size="small" onClick={() => window.open(doc.file_url, '_blank')}>View File</Button>
                                  )}
                                </Box>
                                {doc.description && (
                                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
                                    {doc.description}
                                  </Typography>
                                )}
                              </Box>
                            </Grid>
                          );
                        })}
                      </Grid>
                    </Box>
                  )}
                </Grid>
              </Grid>
            </CardContent>
          </Card>

          {/* Form Actions */}
          <Box display="flex" justifyContent="flex-end" gap={2} mt={3}>
            <Button
              variant="outlined"
              onClick={onCancel}
              disabled={loading}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="contained"
              disabled={loading || optionsLoading}
            >
              {loading ? 'Saving...' : (property ? 'Update Property' : 'Create Property')}
            </Button>
          </Box>
        </form>
        {/* Network/Save verification banner */}
        {saveVerify.pending && (
          <Alert 
            severity="warning" 
            sx={{ mt: 2 }}
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
        {/* Image Preview Dialog */}
        <Dialog open={docPreview.open} onClose={() => setDocPreview({ open: false, src: '', filename: '' })} maxWidth="md" fullWidth>
          <DialogTitle>{docPreview.filename}</DialogTitle>
          <DialogContent>
            {docPreview.src ? (
              <img src={docPreview.src} alt={docPreview.filename} style={{ width: '100%', height: 'auto' }} />
            ) : null}
          </DialogContent>
        </Dialog>
          </DialogContent>
        </Dialog>
  );
};

export default PropertyFormModal;
