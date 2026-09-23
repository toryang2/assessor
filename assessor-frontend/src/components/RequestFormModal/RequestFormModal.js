import React, { useState, useEffect } from 'react';
import {
  Box,
  TextField,
  Grid,
  Checkbox,
  FormControlLabel,
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
  DialogContent,
  DialogActions,
  InputAdornment,
  FormHelperText,
  Autocomplete,
  CircularProgress,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Paper
} from '@mui/material';
import { motion } from 'framer-motion';
import { Receipt, Payment, Save, Cancel, Search } from '@mui/icons-material';

import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';
import useSafetyWatchdog from '../../hooks/useSafetyWatchdog';

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
  const raw = (middle || '').trim();
  const mi = raw.replace(/\./g, '');
  const middleFormatted = mi ? (mi.length === 1 ? ` ${mi}.` : ` ${mi}`) : '';
  return `${last || ''}${hasNames && first ? ', ' : ''}${first || ''}${middleFormatted}`.trim();
};

// Normalize a combined declarant string with the same rule
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


// Helper function to format effectivity according to whole-year / EXEMPT rules
const formatEffectivityDisplay = (item) => {
  if (!item) return '—';
  const isExempt =
    Boolean(item.effectivity_exempt) ||
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

const RequestFormModal = ({ property, onSave, onCancel, open, onClose }) => {
  const isSmallScreen = (() => {
    try { const w = window.innerWidth; const h = window.innerHeight; return (w <= 1280 && h <= 720) || (w <= 1366 && h <= 768) || (w <= 1920 && h <= 1080); } catch (_) { return false; }
  })();
  const { user } = useAuth();
  const [formData, setFormData] = useState({
    amount_paid: '',
    receipt_number: '',
    date_issued: '',
    place_issued: '',
    prepared_by: '',
    purpose: '',
    purpose_details: '',
    client_name: '',
    client_address: '',
    contact_number: '',
    remarks: ''
  });
  const [isOfficialRequest, setIsOfficialRequest] = useState(false);

  const [toast, setToast] = useState({ open: false, message: '', severity: 'success' });
  const [loading, setLoading] = useState(false);
  const [validationErrors, setValidationErrors] = useState(new Set());
  const [propertySearchTerm, setPropertySearchTerm] = useState('');
  const [propertyOptions, setPropertyOptions] = useState([]);
  const [propertySearchLoading, setPropertySearchLoading] = useState(false);
  const [selectedProperty, setSelectedProperty] = useState(property);
  const [taxHistoryModal, setTaxHistoryModal] = useState(false);
  const [taxHistory, setTaxHistory] = useState([]);
  const [taxHistoryLoading, setTaxHistoryLoading] = useState(false);
  const [purposeOptions, setPurposeOptions] = useState([]);
  const [purposeAmountMap, setPurposeAmountMap] = useState({});

  // Safety watchdogs for async UI states within the modal
  useSafetyWatchdog({
    isLoading: propertySearchLoading && open,
    isInitialLoad: propertySearchLoading && open && propertyOptions.length === 0,
    onTimeout: () => {
      setPropertySearchLoading(false);
      setToast({ open: true, message: 'Property search timed out. Please try again.', severity: 'error' });
    },
    timeoutMs: 15000,
    componentName: 'RequestFormModal:PropertySearch',
    enabled: true
  });

  useSafetyWatchdog({
    isLoading: taxHistoryLoading && open,
    isInitialLoad: taxHistoryLoading && open && taxHistory.length === 0,
    onTimeout: () => {
      setTaxHistoryLoading(false);
      setToast({ open: true, message: 'Tax history load timed out. Please try again.', severity: 'error' });
    },
    timeoutMs: 20000,
    componentName: 'RequestFormModal:TaxHistory',
    enabled: true
  });

  const buildPurposeAmountMap = (items) => {
    const map = {};
    for (const it of items || []) {
      if (!it?.value) continue;
      const n = Number(it?.amount);
      map[it.value] = !isNaN(n) ? n : 0;
    }
    return map;
  };

  // Initialize form with current date and user's name
  useEffect(() => {
    if (open) {
      const today = new Date().toISOString().split('T')[0];
      setFormData({
        amount_paid: '',
        receipt_number: '',
        date_issued: today,
        place_issued: '',
        prepared_by: user?.full_name || user?.username || '',
        purpose: '',
        purpose_details: '',
        client_name: '',
        client_address: '',
        contact_number: '',
        remarks: ''
      });
      setIsOfficialRequest(false);
      setSelectedProperty(property);
    }
  }, [open, user, property]);

  // Load request defaults (purpose list + place issued) from settings
  useEffect(() => {
    const loadRequestDefaults = async () => {
      try {
        const [settings, purposesRes] = await Promise.all([
          apiService.getSettings(),
          apiService.getRequestPurposes()
        ]);
        const activeItems = (purposesRes?.items || []).filter(x => x.status === 'active');
        if (activeItems.length > 0) {
          const opts = activeItems.map(p => ({
            value: String(p.purpose || ''),
            label: String(p.purpose || ''),
            amount: Number(p.amount) || 0
          }));
          setPurposeOptions(opts);
          const map = buildPurposeAmountMap(opts);
          setPurposeAmountMap(map);

          setFormData(prev => {
            const desiredPurpose = prev.purpose || opts[0]?.value || '';
            const next = {
              ...prev,
              purpose: desiredPurpose,
              place_issued: prev.place_issued || (settings?.request_place_issued_default || ''),
              verifier_signatory_name: settings?.verifier_signatory_name || '',
              verifier_signatory_title: settings?.verifier_signatory_title || '',
              municipal_assessor_name: settings?.municipal_assessor_name || '',
              municipal_assessor_title: settings?.municipal_assessor_title || '',
              municipal_assessor_license: settings?.municipal_assessor_license || '',
              municipal_assessor_suffix: settings?.municipal_assessor_suffix || ''
            };
            if (!isOfficialRequest && desiredPurpose && map[desiredPurpose] !== undefined) {
              next.amount_paid = Number(map[desiredPurpose]).toFixed(2);
            }
            return next;
          });
        } else {
          setFormData(prev => ({
            ...prev,
            place_issued: prev.place_issued || (settings?.request_place_issued_default || ''),
            verifier_signatory_name: settings?.verifier_signatory_name || '',
            verifier_signatory_title: settings?.verifier_signatory_title || '',
            municipal_assessor_name: settings?.municipal_assessor_name || '',
            municipal_assessor_title: settings?.municipal_assessor_title || '',
            municipal_assessor_license: settings?.municipal_assessor_license || '',
            municipal_assessor_suffix: settings?.municipal_assessor_suffix || ''
          }));
        }
      } catch (_) {
        // Fallback: try to just fetch settings if purposes fail
        try {
          const fallbackSettings = await apiService.getSettings();
          setFormData(prev => ({
            ...prev,
            place_issued: prev.place_issued || (fallbackSettings?.request_place_issued_default || ''),
            verifier_signatory_name: fallbackSettings?.verifier_signatory_name || '',
            verifier_signatory_title: fallbackSettings?.verifier_signatory_title || '',
            municipal_assessor_name: fallbackSettings?.municipal_assessor_name || '',
            municipal_assessor_title: fallbackSettings?.municipal_assessor_title || '',
            municipal_assessor_license: fallbackSettings?.municipal_assessor_license || '',
            municipal_assessor_suffix: fallbackSettings?.municipal_assessor_suffix || ''
          }));
        } catch (e) {
          // ignore
        }
      }
    };

    if (open) loadRequestDefaults();
  }, [open]);

  const handlePurposeChange = (event) => {
    const value = event.target.value;
    setFormData(prev => {
      const next = { ...prev, purpose: value };
      if (!isOfficialRequest && value && purposeAmountMap[value] !== undefined) {
        const amt = Number(purposeAmountMap[value]);
        if (!isNaN(amt)) next.amount_paid = amt.toFixed(2);
      }
      return next;
    });

    if (validationErrors.has('purpose')) {
      setValidationErrors(prev => {
        const next = new Set(prev);
        next.delete('purpose');
        return next;
      });
    }
  };

  // Search for properties
  const searchProperties = async (searchTerm) => {
    if (!searchTerm || searchTerm.trim().length < 3) {
      setPropertyOptions([]);
      return;
    }

    try {
      setPropertySearchLoading(true);
      const response = await apiService.getProperties({
        q: searchTerm.trim(),
        per_page: 10,
        // Add cache busting timestamp to prevent browser caching
        _t: Date.now()
      });

      if (response && response.properties) {
        setPropertyOptions(response.properties);
      } else if (response && response.data) {
        setPropertyOptions(response.data);
      } else {
        setPropertyOptions([]);
      }
    } catch (error) {
      console.error('Error searching properties:', error);
      setPropertyOptions([]);
    } finally {
      setPropertySearchLoading(false);
    }
  };

  // Handle property search input change
  const handlePropertySearchChange = (event, newValue) => {
    // Don't convert to uppercase immediately - let CSS handle visual display
    setPropertySearchTerm(newValue || '');

    if (newValue && newValue.length >= 2) {
      // Use original value for search to maintain case-insensitive functionality
      searchProperties(newValue);
    } else {
      setPropertyOptions([]);
    }

    // Clear property selection validation error when user starts typing
    if (validationErrors.has('property_selection')) {
      setValidationErrors(prev => {
        const newErrors = new Set(prev);
        newErrors.delete('property_selection');
        return newErrors;
      });
    }
  };

  // Handle property selection
  const handlePropertySelect = (event, selectedOption) => {
    setSelectedProperty(selectedOption);
    setTaxHistoryModal(false);
    setTaxHistory([]);

    // Clear property selection validation error when user selects a property
    if (validationErrors.has('property_selection')) {
      setValidationErrors(prev => {
        const newErrors = new Set(prev);
        newErrors.delete('property_selection');
        return newErrors;
      });
    }
  };

  // Fetch tax history for selected property
  const handleViewTaxHistory = async () => {
    if (!selectedProperty?.tax_declaration_number) {
      setToast({
        open: true,
        message: 'No property selected or missing tax declaration number',
        severity: 'error'
      });
      return;
    }

    setTaxHistoryLoading(true);
    try {
      const response = await apiService.getTaxDeclarationHistory(selectedProperty.tax_declaration_number);
      setTaxHistory(response || []);
      setTaxHistoryModal(true);
    } catch (error) {
      console.error('Error fetching tax declaration history:', error);
      setToast({
        open: true,
        message: 'Failed to fetch tax declaration history',
        severity: 'error'
      });
      setTaxHistory([]);
    } finally {
      setTaxHistoryLoading(false);
    }
  };

  // Confirm property selection and close tax history modal
  const handleConfirmProperty = () => {
    setTaxHistoryModal(false);
    setToast({
      open: true,
      message: `Property "${selectedProperty.tax_declaration_number}" confirmed for request`,
      severity: 'success'
    });
  };

  // Validation function
  const validateForm = () => {
    const missingFields = [];
    const errorFields = new Set();

    if (!selectedProperty) {
      missingFields.push('Property Selection');
      errorFields.add('property_selection');
    }

    if (!isOfficialRequest) {
      if (!formData.amount_paid || parseFloat(formData.amount_paid) <= 0) {
        missingFields.push('Amount Paid');
        errorFields.add('amount_paid');
      }

      if (!formData.receipt_number?.trim()) {
        missingFields.push('Receipt Number');
        errorFields.add('receipt_number');
      }
    }

    if (!formData.date_issued) {
      missingFields.push('Date Issued');
      errorFields.add('date_issued');
    }

    if (!formData.place_issued?.trim()) {
      missingFields.push('Place Issued');
      errorFields.add('place_issued');
    }

    if (!formData.prepared_by?.trim()) {
      missingFields.push('Prepared By');
      errorFields.add('prepared_by');
    }

    if (!formData.purpose) {
      missingFields.push('Purpose');
      errorFields.add('purpose');
    }

    if (!formData.purpose_details?.trim()) {
      missingFields.push('Purpose Details');
      errorFields.add('purpose_details');
    }

    if (!formData.client_name?.trim()) {
      missingFields.push('Client Name');
      errorFields.add('client_name');
    }

    setValidationErrors(errorFields);

    if (missingFields.length > 0) {
      setToast({
        open: true,
        message: `Please fill in all required fields: ${missingFields.join(', ')}`,
        severity: 'error'
      });
      return false;
    }

    setValidationErrors(new Set());
    return true;
  };

  // Handle form submission
  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!validateForm()) {
      return;
    }

    setLoading(true);
    try {
      // Send "0.00" (string) for official requests to avoid server-side
      // required checks that treat numeric 0 as empty (e.g., PHP empty()).
      const finalAmountPaid = isOfficialRequest ? '0.00' : parseFloat(formData.amount_paid);
      const finalReceiptNumber = isOfficialRequest ? 'Official Use' : uppercaseFieldValue('receipt_number', formData.receipt_number);

      // Prepare the data for saving with uppercase applied to appropriate fields
      const requestData = {
        client_name: uppercaseFieldValue('client_name', formData.client_name),
        client_address: uppercaseFieldValue('client_address', formData.client_address),
        contact_number: uppercaseFieldValue('contact_number', formData.contact_number),
        remarks: uppercaseFieldValue('remarks', formData.remarks),
        receipt_number: finalReceiptNumber,
        place_issued: formData.place_issued,
        prepared_by: formData.prepared_by,
        purpose: formData.purpose,
        purpose_details: formData.purpose_details,
        date_issued: formData.date_issued,
        property_id: selectedProperty?.id,
        amount_paid: finalAmountPaid,
        is_official_request: isOfficialRequest ? 1 : 0,
        created_at: new Date().toISOString(),
        verifier_signatory_name: formData.verifier_signatory_name,
        verifier_signatory_title: formData.verifier_signatory_title,
        municipal_assessor_name: formData.municipal_assessor_name,
        municipal_assessor_title: formData.municipal_assessor_title,
        municipal_assessor_license: formData.municipal_assessor_license,
        municipal_assessor_suffix: formData.municipal_assessor_suffix
      };

      // Call API to save the request
      const response = await apiService.createRequest(requestData);

      setToast({
        open: true,
        message: 'Request form saved successfully!',
        severity: 'success'
      });

      // Call the onSave callback with the saved data
      if (onSave) {
        // Combine the response data with property information for the receipt
        const receiptData = {
          ...response,
          // Add property information for the receipt display
          tax_declaration_number: selectedProperty?.tax_declaration_number,
          declarant_last_name: selectedProperty?.declarant_last_name,
          declarant_first_name: selectedProperty?.declarant_first_name,
          declarant_middle_initial: selectedProperty?.declarant_middle_initial,
          business: selectedProperty?.business,
          location: selectedProperty?.location,
          assessed_value: selectedProperty?.assessed_value
        };
        onSave(receiptData);
      }

      // Close the modal after a short delay
      setTimeout(() => {
        handleClose();
      }, 1500);

    } catch (error) {
      console.error('Error saving request form:', error);
      const extractErrorMessages = (err) => {
        const data = err?.response?.data || err?.data;

        // Prefer structured backend errors if present
        if (data) {
          if (Array.isArray(data.errors)) {
            return data.errors.filter(Boolean).map(String);
          }
          if (data.errors && typeof data.errors === 'object') {
            const out = [];
            for (const v of Object.values(data.errors)) {
              if (Array.isArray(v)) out.push(...v);
              else if (v) out.push(v);
            }
            if (out.length) return out.filter(Boolean).map(String);
          }
          if (Array.isArray(data.message)) {
            return data.message.filter(Boolean).map(String);
          }
          if (typeof data.message === 'string' && data.message.trim()) {
            return [data.message.trim()];
          }
        }

        if (typeof err?.message === 'string' && err.message.trim()) {
          // Support newline-delimited messages as bullets
          if (err.message.includes('\n')) {
            return err.message.split('\n').map(s => s.trim()).filter(Boolean);
          }
          return [err.message.trim()];
        }

        return ['Error saving request form'];
      };

      const messages = extractErrorMessages(error);
      setToast({
        open: true,
        message: messages.length > 1 ? messages : messages[0],
        severity: 'error'
      });
    } finally {
      setLoading(false);
    }
  };

  // Helper function to uppercase field values on submit
  const uppercaseFieldValue = (field, value) => {
    const uppercaseFields = new Set([
      'client_name',
      'client_address',
      'contact_number',
      'remarks',
      'receipt_number',
      'place_issued',
      'prepared_by'
    ]);

    if (uppercaseFields.has(field) && typeof value === 'string') {
      return value.toUpperCase();
    }
    return value;
  };

  // Handle form field changes
  const handleChange = (field) => (event) => {
    const value = event.target.value;

    setFormData(prev => ({
      ...prev,
      [field]: value
    }));

    // Clear validation error when user starts typing
    if (validationErrors.has(field)) {
      setValidationErrors(prev => {
        const newErrors = new Set(prev);
        newErrors.delete(field);
        return newErrors;
      });
    }
  };

  // Handle modal close
  const handleClose = () => {
    setFormData({
      amount_paid: '',
      receipt_number: '',
      date_issued: '',
      place_issued: '',
      prepared_by: '',
      purpose: '',
      purpose_details: '',
      client_name: '',
      client_address: '',
      contact_number: '',
      remarks: ''
    });
    setIsOfficialRequest(false);
    setValidationErrors(new Set());
    setSelectedProperty(null);
    setPropertySearchTerm('');
    setPropertyOptions([]);
    setTaxHistoryModal(false);
    setTaxHistory([]);
    if (onClose) onClose();
  };

  return (
    <>
      <Dialog
        open={open}
        onClose={handleClose}
        maxWidth={isSmallScreen ? 'sm' : 'md'}
        fullWidth
        PaperProps={{
          component: motion.div,
          initial: { opacity: 0, y: 20 },
          animate: { opacity: 1, y: 0 },
          transition: { duration: 0.3 },
          sx: { width: isSmallScreen ? '60vw' : undefined, maxHeight: '100vh' }
        }}
      >
        <DialogTitle sx={{
          bgcolor: 'primary.main',
          color: 'white',
          display: 'flex',
          alignItems: 'center',
          gap: 1
        }}>
          <Receipt />
          Request Form
        </DialogTitle>
        <DialogContent sx={isSmallScreen ? { p: 2, '& .MuiTextField-root': { mb: 1 }, '& .MuiInputBase-root': { fontSize: '0.9rem' }, '& .MuiFormLabel-root': { fontSize: '0.85rem' }, '& .MuiButton-root': { padding: '6px 12px' } } : { p: 3 }}>
          <form onSubmit={handleSubmit}>
            <Grid container {...(isSmallScreen ? { rowSpacing: 1, columnSpacing: 2 } : { spacing: 3 })}>
              {/* Property Search Section */}
              <Grid item xs={12}>
                <Card variant="outlined">
                  <CardContent sx={{ p: isSmallScreen ? 2 : 3 }}>
                    <Typography variant="h6" gutterBottom sx={{
                      display: 'flex',
                      alignItems: 'center',
                      gap: 1,
                      color: 'primary.main'
                    }}>
                      <Search />
                      Property Information (Required) *
                    </Typography>
                    <Divider sx={{ mb: 1 }} />
                    <Autocomplete
                      options={propertyOptions}
                      getOptionLabel={(option) => {
                        const declarant = formatDeclarantFromParts(option.declarant_last_name, option.declarant_first_name, option.declarant_middle_initial);
                        const business = sanitizeBusinessName(option.business_name);
                        const displayName = declarant && business ? `${declarant} / ${business}` : (declarant || business || '');
                        return `${option.tax_declaration_number || ''} - ${displayName}`;
                      }}
                      value={selectedProperty}
                      onChange={handlePropertySelect}
                      inputValue={propertySearchTerm}
                      onInputChange={handlePropertySearchChange}
                      loading={propertySearchLoading}
                      noOptionsText="No properties found. Try searching with different terms."
                      renderInput={(params) => (
                        <TextField
                          {...params}
                          label="Search for Property *"
                          placeholder="Search by Tax Declaration Number, owner name, or business name..."
                          helperText={validationErrors.has('property_selection') ? "Property selection is required" : "Start typing to search for properties. Property selection is required."}
                          error={validationErrors.has('property_selection')}
                          size={isSmallScreen ? 'small' : 'medium'}
                          margin={isSmallScreen ? 'dense' : 'normal'}
                          inputProps={{ ...params.inputProps, style: { textTransform: 'uppercase' } }}
                          InputProps={{
                            ...params.InputProps,
                            endAdornment: (
                              <>
                                {propertySearchLoading ? <CircularProgress color="inherit" size={20} /> : null}
                                {params.InputProps.endAdornment}
                              </>
                            ),
                          }}
                        />
                      )}
                      renderOption={(props, option) => (
                        <Box component="li" {...props}>
                          <Box>
                            <Typography variant="body2" fontWeight="bold">
                              {option.tax_declaration_number}
                            </Typography>
                            <Typography variant="caption" color="text.secondary">
                              {(() => {
                                const declarant = formatDeclarantFromParts(option.declarant_last_name, option.declarant_first_name, option.declarant_middle_initial);
                                const business = sanitizeBusinessName(option.business_name);
                                if (declarant && business) return `${declarant} / ${business}`;
                                return declarant || business || '';
                              })()}
                            </Typography>
                          </Box>
                        </Box>
                      )}
                      isOptionEqualToValue={(option, value) => option.id === value.id}
                      clearOnBlur={false}
                      clearOnEscape={false}
                    />

                    {validationErrors.has('property_selection') && (
                      <Typography variant="body2" color="error" sx={{ mt: 1, fontSize: '0.75rem' }}>
                        Property selection is required. Please search and select a property before proceeding.
                      </Typography>
                    )}

                    {!selectedProperty && propertySearchTerm && propertyOptions.length === 0 && !propertySearchLoading && (
                      <Typography variant="body2" color="text.secondary" sx={{ mt: 1, fontStyle: 'italic' }}>
                        No properties found matching "{propertySearchTerm}". Try searching with different terms. Property selection is required to create a request.
                      </Typography>
                    )}

                    {selectedProperty && (
                      <Box sx={{ mt: 2, p: 2, bgcolor: 'grey.50', borderRadius: 1 }}>
                        <Typography variant="subtitle2" gutterBottom>Selected Property:</Typography>
                        <Grid container spacing={2}>
                          <Grid item xs={12} md={6}>
                            <Typography variant="body2">
                              <strong>Tax Declaration Number:</strong> {selectedProperty.tax_declaration_number}
                            </Typography>
                          </Grid>
                          <Grid item xs={12} md={6}>
                            <Typography variant="body2">
                              <strong>Location:</strong> {selectedProperty.location || 'N/A'}
                            </Typography>
                          </Grid>
                          <Grid item xs={12} md={6}>
                            <Typography variant="body2">
                              <strong>Owner/Business:</strong> {(() => {
                                const declarant = formatDeclarantFromParts(selectedProperty.declarant_last_name, selectedProperty.declarant_first_name, selectedProperty.declarant_middle_initial);
                                const business = sanitizeBusinessName(selectedProperty.business_name);
                                if (declarant && business) return `${declarant} / ${business}`;
                                return declarant || business || 'N/A';
                              })()}
                            </Typography>
                          </Grid>
                          <Grid item xs={12} md={6}>
                            <Typography variant="body2">
                              <strong>Area:</strong> {(() => {
                                const haRaw = selectedProperty.area_hectare;
                                const sqmRaw = selectedProperty.area_sqm;
                                const oldHaRaw = selectedProperty.area_hectare_old;
                                const numHa = Number(haRaw);
                                const numSqm = Number(sqmRaw);
                                const hasHa = haRaw !== undefined && haRaw !== null && haRaw !== '' && !isNaN(numHa) && numHa > 0;
                                const hasSqm = sqmRaw !== undefined && sqmRaw !== null && sqmRaw !== '' && !isNaN(numSqm) && numSqm > 0;
                                const hasOldHa = !!(oldHaRaw && oldHaRaw !== '');
                                if (!hasHa && !hasSqm && !hasOldHa) return '—';
                                let currentArea = '';
                                if (hasHa) {
                                  const unit = numHa <= 1 ? 'ha' : 'has';
                                  currentArea = `${numHa.toFixed(4)} ${unit}`;
                                } else if (hasSqm) {
                                  currentArea = `${numSqm.toFixed(2)} sqm`;
                                }
                                if (hasOldHa && currentArea) return `${currentArea} ${oldHaRaw}`;
                                if (hasOldHa) return oldHaRaw;
                                return currentArea || '—';
                              })()}
                            </Typography>
                          </Grid>
                          <Grid item xs={12} md={6}>
                            <Typography variant="body2">
                              <strong>Assessed Value:</strong> {(() => {
                                const currentValue = selectedProperty.assessed_value;
                                const oldValue = selectedProperty.assessed_value_old;
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
                          </Grid>
                        </Grid>

                        <Box sx={{ mt: 2, display: 'flex', gap: 1 }}>
                          <Button
                            variant="outlined"
                            size="small"
                            onClick={handleViewTaxHistory}
                            disabled={taxHistoryLoading}
                            startIcon={taxHistoryLoading ? <CircularProgress size={16} /> : null}
                          >
                            {taxHistoryLoading ? 'Loading...' : 'View Tax History'}
                          </Button>
                        </Box>
                      </Box>
                    )}
                  </CardContent>
                </Card>
              </Grid>

              {/* Client Information Section */}
              <Grid item xs={12}>
                <Card variant="outlined">
                  <CardContent sx={{ p: isSmallScreen ? 2 : 3 }}>
                    <Typography variant="h6" gutterBottom sx={{
                      display: 'flex',
                      alignItems: 'center',
                      gap: 1,
                      color: 'primary.main'
                    }}>
                      <Receipt />
                      Client Information
                    </Typography>
                    <Divider sx={{ mb: 2 }} />

                    <Grid container {...(isSmallScreen ? { rowSpacing: 1, columnSpacing: 2 } : { spacing: 2 })}>
                      <Grid item xs={12} md={6}>
                        <TextField
                          fullWidth
                          label="Client Name *"
                          value={formData.client_name}
                          onChange={handleChange('client_name')}
                          error={validationErrors.has('client_name')}
                          inputProps={{ style: { textTransform: 'uppercase' } }}
                          size={isSmallScreen ? 'small' : 'medium'}
                          margin={isSmallScreen ? 'dense' : 'normal'}
                        />
                      </Grid>

                      <Grid item xs={12} md={6}>
                        <TextField
                          fullWidth
                          label="Contact Number"
                          value={formData.contact_number}
                          onChange={handleChange('contact_number')}
                          inputProps={{ style: { textTransform: 'uppercase' } }}
                          size={isSmallScreen ? 'small' : 'medium'}
                          margin={isSmallScreen ? 'dense' : 'normal'}
                        />
                      </Grid>

                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          label="Client Address"
                          value={formData.client_address}
                          onChange={handleChange('client_address')}
                          inputProps={{ style: { textTransform: 'uppercase' } }}
                          multiline
                          rows={2}
                          size={isSmallScreen ? 'small' : 'medium'}
                          margin={isSmallScreen ? 'dense' : 'normal'}
                        />
                      </Grid>

                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          label="Remarks"
                          value={formData.remarks}
                          onChange={handleChange('remarks')}
                          inputProps={{ style: { textTransform: 'uppercase' } }}
                          multiline
                          rows={3}
                          placeholder="Additional notes or special instructions..."
                          size={isSmallScreen ? 'small' : 'medium'}
                          margin={isSmallScreen ? 'dense' : 'normal'}
                        />
                      </Grid>
                    </Grid>
                  </CardContent>
                </Card>
              </Grid>

              {/* Payment Information Section */}
              <Grid item xs={12}>
                <Card variant="outlined">
                  <CardContent sx={{ p: isSmallScreen ? 2 : 3 }}>
                    <Box
                      sx={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        gap: 1,
                        mb: 0.5
                      }}
                    >
                      <Typography
                        variant="h6"
                        gutterBottom={false}
                        sx={{
                          display: 'flex',
                          alignItems: 'center',
                          gap: 1,
                          color: 'primary.main'
                        }}
                      >
                        <Payment />
                        Payment Information
                      </Typography>

                      <FormControlLabel
                        sx={{
                          m: 0,
                          '& .MuiFormControlLabel-label': {
                            fontSize: isSmallScreen ? '0.8rem' : '0.9rem'
                          }
                        }}
                        control={(
                          <Checkbox
                            size={isSmallScreen ? 'small' : 'medium'}
                            checked={isOfficialRequest}
                            onChange={(e) => {
                              const checked = e.target.checked;
                              setIsOfficialRequest(checked);
                              setFormData(prev => ({
                                ...prev,
                                amount_paid: checked
                                  ? '0.00'
                                  : (prev.purpose && purposeAmountMap[prev.purpose] !== undefined
                                    ? Number(purposeAmountMap[prev.purpose]).toFixed(2)
                                    : ''),
                                receipt_number: checked ? 'Official Use' : ''
                              }));

                              if (checked) {
                                setValidationErrors(prev => {
                                  const next = new Set(prev);
                                  next.delete('amount_paid');
                                  next.delete('receipt_number');
                                  return next;
                                });
                              }
                            }}
                          />
                        )}
                        label="Official Request"
                      />
                    </Box>
                    <Divider sx={{ mb: 2 }} />

                    <Grid container {...(isSmallScreen ? { rowSpacing: 1, columnSpacing: 2 } : { spacing: 2 })}>
                      <Grid item xs={12} md={6}>
                        <TextField
                          fullWidth
                          label="Amount Paid *"
                          type="number"
                          value={formData.amount_paid}
                          onChange={handleChange('amount_paid')}
                          disabled={isOfficialRequest}
                          InputProps={{
                            startAdornment: <InputAdornment position="start">₱</InputAdornment>,
                          }}
                          inputProps={{ min: 0, step: 0.01 }}
                          error={validationErrors.has('amount_paid')}
                          placeholder="0.00"
                          onBlur={() => {
                            if (isOfficialRequest) return;
                            const v = formData.amount_paid;
                            if (v === '' || v === null || v === undefined) return;
                            const n = Number(v);
                            if (!isNaN(n)) {
                              setFormData(prev => ({
                                ...prev,
                                amount_paid: n.toFixed(2)
                              }));
                            }
                          }}
                          size={isSmallScreen ? 'small' : 'medium'}
                          margin={isSmallScreen ? 'dense' : 'normal'}
                        />
                      </Grid>
                      <Grid item xs={12} md={6}>
                        <TextField
                          fullWidth
                          label="Receipt Number *"
                          value={formData.receipt_number}
                          onChange={handleChange('receipt_number')}
                          inputProps={{ style: { textTransform: 'uppercase' } }}
                          error={validationErrors.has('receipt_number')}
                          disabled={isOfficialRequest}
                          size={isSmallScreen ? 'small' : 'medium'}
                          margin={isSmallScreen ? 'dense' : 'normal'}
                        />
                      </Grid>
                      <Grid item xs={12} md={6}>
                        <TextField
                          fullWidth
                          label="Date Issued *"
                          type="date"
                          value={formData.date_issued}
                          onChange={handleChange('date_issued')}
                          InputLabelProps={{ shrink: true }}
                          error={validationErrors.has('date_issued')}
                          size={isSmallScreen ? 'small' : 'medium'}
                          margin={isSmallScreen ? 'dense' : 'normal'}
                        />
                      </Grid>
                      <Grid item xs={12} md={6}>
                        <TextField
                          fullWidth
                          label="Place Issued"
                          value={formData.place_issued}
                          onChange={handleChange('place_issued')}
                          InputProps={{ readOnly: true, style: isSmallScreen ? { fontSize: '0.9rem' } : undefined }}
                          inputProps={{ tabIndex: -1 }}
                          variant="outlined"
                          // inputProps={{style: { textTransform: 'uppercase' }}}
                          error={validationErrors.has('place_issued')}
                          size={isSmallScreen ? 'small' : 'medium'}
                          margin={isSmallScreen ? 'dense' : 'normal'}
                          sx={{
                            pointerEvents: 'none',
                            '& .MuiOutlinedInput-input.Mui-disabled': { WebkitTextFillColor: 'inherit' }
                          }}
                        />
                      </Grid>
                      <Grid item xs={12} md={6}>
                        <FormControl fullWidth error={validationErrors.has('purpose')} size={isSmallScreen ? 'small' : 'medium'}>
                          <InputLabel>Purpose *</InputLabel>
                          <Select
                            value={formData.purpose}
                            onChange={handlePurposeChange}
                            label="Purpose *">
                            {purposeOptions.map((option) => (
                              <MenuItem key={option.value} value={option.value}>
                                {option.label}
                              </MenuItem>
                            ))}
                          </Select>
                        </FormControl>
                      </Grid>
                      <Grid item xs={12} md={6}>
                        <TextField
                          fullWidth
                          label="Prepared By"
                          value={formData.prepared_by}
                          onChange={handleChange('prepared_by')}
                          InputProps={{ readOnly: true, style: isSmallScreen ? { fontSize: '0.9rem' } : undefined }}
                          InputLabelProps={{ shrink: true }}
                          inputProps={{ tabIndex: -1 }}
                          variant="outlined"
                          error={validationErrors.has('prepared_by')}
                          size={isSmallScreen ? 'small' : 'medium'}
                          sx={{
                            pointerEvents: 'none',
                            '& .MuiOutlinedInput-input.Mui-disabled': { WebkitTextFillColor: 'inherit' }
                          }}
                        />
                      </Grid>
                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          multiline
                          minRows={4}
                          label="Purpose Details *"
                          value={formData.purpose_details}
                          onChange={handleChange('purpose_details')}
                          error={validationErrors.has('purpose_details')}
                          helperText={validationErrors.has('purpose_details') ? 'Purpose Details is required.' : 'Enter the specific purpose or statement for this request. This text will appear on the printed document.'}
                          placeholder="e.g. PROCESS RIGHT-OF-WAY ACQUISITION AND REFERENCE CONCERNING AFFECTED LOTS NECESSARY FOR VERIFICATION PURPOSES"
                          size={isSmallScreen ? 'small' : 'medium'}
                          margin={isSmallScreen ? 'dense' : 'normal'}
                        />
                      </Grid>
                    </Grid>
                  </CardContent>
                </Card>
              </Grid>
            </Grid>
            <Box sx={{ display: 'flex', justifyContent: 'flex-end', gap: 1.5, mt: 2 }}>
              <Button
                onClick={handleClose}
                variant="outlined"
                size={isSmallScreen ? 'small' : 'medium'}
                disabled={loading}
              >
                Cancel
              </Button>
              <Button
                onClick={handleSubmit}
                startIcon={<Save />}
                variant="contained"
                size={isSmallScreen ? 'small' : 'medium'}
                disabled={loading}
              >
                {loading ? 'Saving...' : 'Save Request'}
              </Button>
            </Box>
          </form>
        </DialogContent>
      </Dialog>

      {/* Tax History Modal */}
      <Dialog
        open={taxHistoryModal}
        onClose={() => setTaxHistoryModal(false)}
        maxWidth={isSmallScreen ? 'lg' : 'xl'}
        fullWidth
        PaperProps={{
          component: motion.div,
          initial: { opacity: 0, y: 20 },
          animate: { opacity: 1, y: 0 },
          transition: { duration: 0.3 },
          sx: { width: isSmallScreen ? '80vw' : undefined, maxHeight: '90vh' }
        }}
      >
        <DialogTitle sx={{
          bgcolor: 'primary.main',
          color: 'white',
          display: 'flex',
          alignItems: 'center',
          gap: 1
        }}>
          <Search />
          Tax Declaration History - {selectedProperty?.tax_declaration_number}
        </DialogTitle>

        <DialogContent sx={{ p: isSmallScreen ? 2 : 3 }}>
          {taxHistoryLoading ? (
            <Box display="flex" justifyContent="center" p={3}>
              <CircularProgress />
            </Box>
          ) : taxHistory.length > 0 ? (
            <Box>
              <Typography variant="body1" sx={{ mb: 2 }}>
                Property: <strong>{selectedProperty?.tax_declaration_number}</strong> -
                {(() => {
                  const declarant = formatDeclarantFromParts(
                    selectedProperty?.declarant_last_name,
                    selectedProperty?.declarant_first_name,
                    selectedProperty?.declarant_middle_initial
                  );
                  const business = sanitizeBusinessName(selectedProperty?.business_name);
                  if (declarant && business) return ` ${declarant} / ${business}`;
                  return ` ${declarant || business || ''}`;
                })()}
              </Typography>

              <TableContainer component={Paper} sx={{ maxHeight: '60vh', overflow: 'auto' }}>
                <Table size="small" stickyHeader>
                  {/* <colgroup>
                    <col style={{ width: '15%' }} />
                    <col style={{ width: '12%' }} />
                    <col style={{ width: '6%' }} />
                    <col style={{ width: '6%' }} />
                    <col style={{ width: '9%' }} />
                    <col style={{ width: '9%' }} />
                    <col style={{ width: '11%' }} />
                    <col style={{ width: '9%' }} />
                    <col style={{ width: '28%' }} />
                  </colgroup> */}
                  <TableHead>
                    <TableRow>
                      <TableCell><strong>Tax Declaration Number</strong></TableCell>
                      <TableCell><strong>Declarant</strong></TableCell>
                      <TableCell><strong>Barangay</strong></TableCell>
                      <TableCell><strong>Lot Number</strong></TableCell>
                      <TableCell><strong>Survey Number</strong></TableCell>
                      <TableCell><strong>Area</strong></TableCell>
                      <TableCell><strong>Title Number</strong></TableCell>
                      <TableCell><strong>Assessed Value</strong></TableCell>
                      <TableCell><strong>Effectivity</strong></TableCell>
                      <TableCell><strong>Memoranda</strong></TableCell>
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
                            <Typography variant="body2" fontWeight="600" color={isConsolidated ? "warning.main" : "primary"}>
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
                              const b = sanitizeBusinessName(item.business_name);
                              if (!d && !b) return '';
                              return (
                                <>
                                  {d && (
                                    <Typography style={{ fontSize: 12, fontWeight: 600 }}>
                                      {d}
                                    </Typography>
                                  )}
                                  {b && (
                                    <Typography style={{ fontSize: 10 }}>
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
                          <TableCell>
                            {(() => {
                              const haRaw = item.area_hectare;
                              const sqmRaw = item.area_sqm;
                              const oldHaRaw = item.area_hectare_old;
                              const numHa = Number(haRaw);
                              const numSqm = Number(sqmRaw);
                              const hasHa = haRaw !== undefined && haRaw !== null && haRaw !== '' && !isNaN(numHa) && numHa > 0;
                              const hasSqm = sqmRaw !== undefined && sqmRaw !== null && sqmRaw !== '' && !isNaN(numSqm) && numSqm > 0;
                              const hasOldHa = !!(oldHaRaw && oldHaRaw !== '');
                              if (!hasHa && !hasSqm && !hasOldHa) return '—';
                              let currentArea = '';
                              if (hasHa) {
                                const unit = numHa <= 1 ? 'ha' : 'has';
                                currentArea = `${numHa.toFixed(4)} ${unit}`;
                              } else if (hasSqm) {
                                currentArea = `${numSqm.toFixed(2)} sqm`;
                              }
                              if (hasOldHa && currentArea) return `${currentArea} ${oldHaRaw}`;
                              if (hasOldHa) return oldHaRaw;
                              return currentArea || '—';
                            })()}
                          </TableCell>
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
                          <TableCell>{formatEffectivityDisplay(item)}</TableCell>
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
            </Box>
          ) : (
            <Typography color="text.secondary" textAlign="center">
              No tax declaration history found for this property.
            </Typography>
          )}
        </DialogContent>

        <DialogActions sx={{ p: 3, pt: 0 }}>
          <Button
            onClick={() => setTaxHistoryModal(false)}
            variant="outlined"
          >
            Close
          </Button>
          <Button
            onClick={handleConfirmProperty}
            variant="contained"
            color="success"
            startIcon={<Save />}
          >
            Confirm Property Selection
          </Button>
        </DialogActions>
      </Dialog>

      {/* Toast Notification */}
      <Snackbar
        open={toast.open}
        autoHideDuration={6000}
        onClose={() => setToast(prev => ({ ...prev, open: false }))}
        anchorOrigin={{ vertical: 'top', horizontal: 'center' }}
      >
        <Alert
          onClose={() => setToast(prev => ({ ...prev, open: false }))}
          severity={toast.severity}
          sx={{
            width: '100%',
            alignItems: 'flex-start',
            '& .MuiAlert-message': {
              width: '100%',
              overflowWrap: 'anywhere',
              maxHeight: '60vh',
              overflowY: 'auto'
            }
          }}
        >
          {Array.isArray(toast.message) ? (
            <Box component="ul" sx={{ m: 0, pl: 2 }}>
              {toast.message.map((m, idx) => (
                <Box component="li" key={idx} sx={{ mb: 0.25 }}>
                  {m}
                </Box>
              ))}
            </Box>
          ) : (
            toast.message
          )}
        </Alert>
      </Snackbar>
    </>
  );
};

export default RequestFormModal;
