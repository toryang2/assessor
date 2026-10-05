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
  IconButton,
  Typography,
  Alert,
  Snackbar,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  InputAdornment,
  Autocomplete,
  CircularProgress,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Paper,
  Chip,
  Tooltip
} from '@mui/material';
import { useTheme } from '@mui/material/styles';
import { motion, AnimatePresence } from 'framer-motion';
import {
  Receipt,
  Search,
  Close as CloseIcon,
  HomeWork as PropertyIcon,
  Person as ClientIcon,
  Description as DetailsIcon,
  CalendarToday as DateIcon,
  LocationOn as LocationIcon,
  Badge as PreparedByIcon,
  Lock as LockIcon,
  History as HistoryIcon,
  VerifiedUser as VerifiedIcon,
  Save as SaveIcon,
  Payment as PaymentIcon
} from '@mui/icons-material';

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

const isEffectivityExemptValue = (value) =>
  value === true ||
  value === 1 ||
  value === '1';

// Helper function to format effectivity according to whole-year / EXEMPT rules
const formatEffectivityDisplay = (item) => {
  if (!item) return '—';
  const isExempt =
    isEffectivityExemptValue(item.effectivity_exempt) ||
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

// Reusable standard section header matching Assessor application styling
const SectionHeader = ({ icon: Icon, title, subtitle }) => {
  const theme = useTheme();
  return (
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
  );
};

const RequestFormModal = ({ property, onSave, onCancel, open, onClose }) => {
  const theme = useTheme();
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
          apiService.getBootstrapSettings(),
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
  }, [open, isOfficialRequest]);

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
    setPropertySearchTerm(newValue || '');

    if (newValue && newValue.length >= 2) {
      searchProperties(newValue);
    } else {
      setPropertyOptions([]);
    }

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

  // Helper function to uppercase field values on submit
  const uppercaseFieldValue = (field, value) => {
    const uppercaseFields = new Set([
      'client_name',
      'client_address',
      'contact_number',
      'remarks',
      'receipt_number',
      'place_issued',
      'prepared_by',
      'purpose_details'
    ]);

    if (uppercaseFields.has(field) && typeof value === 'string') {
      return value.toUpperCase();
    }
    return value;
  };

  // Handle form submission
  const handleSubmit = async (e) => {
    if (e && e.preventDefault) {
      e.preventDefault();
    }

    if (!validateForm()) {
      return;
    }

    setLoading(true);
    try {
      const finalAmountPaid = isOfficialRequest ? '0.00' : parseFloat(formData.amount_paid);
      const finalReceiptNumber = isOfficialRequest ? 'Official Use' : uppercaseFieldValue('receipt_number', formData.receipt_number);

      const requestData = {
        client_name: uppercaseFieldValue('client_name', formData.client_name),
        client_address: uppercaseFieldValue('client_address', formData.client_address),
        contact_number: uppercaseFieldValue('contact_number', formData.contact_number),
        remarks: uppercaseFieldValue('remarks', formData.remarks),
        receipt_number: finalReceiptNumber,
        place_issued: formData.place_issued,
        prepared_by: formData.prepared_by,
        purpose: formData.purpose,
        purpose_details: uppercaseFieldValue('purpose_details', formData.purpose_details),
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

      const response = await apiService.createRequest(requestData);

      setToast({
        open: true,
        message: 'Request form saved successfully!',
        severity: 'success'
      });

      if (onSave) {
        const receiptData = {
          ...response,
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

      setTimeout(() => {
        handleClose();
      }, 1500);

    } catch (error) {
      console.error('Error saving request form:', error);
      const extractErrorMessages = (err) => {
        const data = err?.response?.data || err?.data;

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

  // Handle form field changes
  const handleChange = (field) => (event) => {
    const value = event.target.value;

    setFormData(prev => ({
      ...prev,
      [field]: value
    }));

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
    if (onCancel) onCancel();
    if (onClose) onClose();
  };

  // Format owner / business display helper
  const renderOwnerDisplay = (prop) => {
    if (!prop) return '—';
    const declarant = formatDeclarantFromParts(
      prop.declarant_last_name,
      prop.declarant_first_name,
      prop.declarant_middle_initial
    );
    const business = sanitizeBusinessName(prop.business_name || prop.business);
    if (declarant && business) return `${declarant} / ${business}`;
    return declarant || business || '—';
  };

  // Format property area
  const renderAreaDisplay = (prop) => {
    if (!prop) return '—';
    const haRaw = prop.area_hectare;
    const sqmRaw = prop.area_sqm;
    const oldHaRaw = prop.area_hectare_old;
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
  };

  // Format assessed value
  const renderAssessedValueDisplay = (prop) => {
    if (!prop) return '₱0.00';
    const currentValue = prop.assessed_value;
    const oldValue = prop.assessed_value_old;
    const hasCurrent = currentValue !== undefined && currentValue !== null;
    const hasOld = oldValue && oldValue !== '';

    if (!hasCurrent && !hasOld) return '₱0.00';

    let displayVal = '';
    if (hasCurrent) {
      displayVal = `₱${Number(currentValue).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }

    if (hasOld && displayVal) {
      return `${displayVal} ${oldValue}`;
    } else if (hasOld) {
      return oldValue;
    } else {
      return displayVal || '₱0.00';
    }
  };

  return (
    <>
      <Dialog
        open={open}
        onClose={loading ? undefined : handleClose}
        fullWidth
        maxWidth={false}
        PaperProps={{
          component: motion.div,
          initial: { opacity: 0, y: 12 },
          animate: { opacity: 1, y: 0 },
          exit: { opacity: 0, y: 12 },
          transition: { duration: 0.2, ease: 'easeOut' },
          sx: {
            width: {
              xs: 'calc(100vw - 24px)',
              sm: '760px',
              md: '960px',
              lg: '1040px'
            },
            maxWidth: '1050px',
            maxHeight: '92vh',
            borderRadius: 1, // Restrained 4px shape language matching theme.shape.borderRadius
            display: 'flex',
            flexDirection: 'column',
            overflow: 'hidden',
            backgroundColor: theme.palette.background.paper,
            m: { xs: 1.5, sm: 2 }
          }
        }}
      >
        {/* ================= MODAL HEADER ================= */}
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
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, minWidth: 0 }}>
            <Receipt
              sx={{
                fontSize: { xs: 22, sm: 24 },
                color: theme.palette.primary.main,
                flexShrink: 0
              }}
            />
            <Box sx={{ minWidth: 0 }}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
                <Typography
                  variant="h6"
                  sx={{
                    fontWeight: 600,
                    color: theme.palette.text.primary,
                    lineHeight: 1.2
                  }}
                >
                  Create Request
                </Typography>

                {/* Status Indicator */}
                {selectedProperty ? (
                  <Chip
                    label={`Property: ${selectedProperty.tax_declaration_number}`}
                    size="small"
                    color="success"
                    variant="outlined"
                    sx={{
                      height: 22,
                      fontSize: '0.75rem',
                      fontWeight: 600
                    }}
                  />
                ) : (
                  <Chip
                    label="No Property Selected"
                    size="small"
                    variant="outlined"
                    sx={{
                      height: 22,
                      fontSize: '0.75rem',
                      color: theme.palette.text.secondary,
                      borderColor: theme.palette.divider
                    }}
                  />
                )}
              </Box>
              <Typography
                variant="caption"
                sx={{
                  color: theme.palette.text.secondary,
                  display: 'block',
                  mt: 0.25
                }}
              >
                Create and issue an official assessment document
              </Typography>
            </Box>
          </Box>

          <IconButton
            size="small"
            onClick={handleClose}
            disabled={loading}
            aria-label="Close Request Form"
            sx={{
              color: theme.palette.text.secondary,
              '&:hover': {
                color: theme.palette.error.main
              }
            }}
          >
            <CloseIcon fontSize="small" />
          </IconButton>
        </DialogTitle>

        {/* ================= MODAL SCROLLABLE BODY ================= */}
        <DialogContent
          sx={{
            p: { xs: 2, sm: 2.5 },
            overflowY: 'auto',
            flex: '1 1 auto',
            backgroundColor: theme.palette.background.paper
          }}
        >
          <form onSubmit={handleSubmit} id="request-form-element">
            <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2.5 }}>

              {/* ---------------- 1. PROPERTY SECTION ---------------- */}
              <Box
                sx={{
                  p: 2,
                  borderRadius: 1,
                  border: 1,
                  borderColor: validationErrors.has('property_selection')
                    ? theme.palette.error.main
                    : theme.palette.divider,
                  backgroundColor: theme.palette.background.paper
                }}
              >
                <SectionHeader
                  icon={PropertyIcon}
                  title="Property"
                  subtitle="Select the real property associated with this assessment request."
                />

                <Autocomplete
                  options={propertyOptions}
                  getOptionLabel={(option) => {
                    const declarant = formatDeclarantFromParts(
                      option.declarant_last_name,
                      option.declarant_first_name,
                      option.declarant_middle_initial
                    );
                    const business = sanitizeBusinessName(option.business_name);
                    const displayName = declarant && business ? `${declarant} / ${business}` : (declarant || business || '');
                    return `${option.tax_declaration_number || ''} - ${displayName}`;
                  }}
                  value={selectedProperty}
                  onChange={handlePropertySelect}
                  inputValue={propertySearchTerm}
                  onInputChange={handlePropertySearchChange}
                  loading={propertySearchLoading}
                  noOptionsText="No properties found. Try searching with a TDN, owner, or business."
                  isOptionEqualToValue={(option, value) => option?.id === value?.id}
                  clearOnBlur={false}
                  clearOnEscape={false}
                  renderInput={(params) => (
                    <TextField
                      {...params}
                      size="small"
                      label="Search Property *"
                      placeholder="Search Tax Declaration Number, owner, or business..."
                      error={validationErrors.has('property_selection')}
                      inputProps={{
                        ...params.inputProps,
                        style: {
                          ...params.inputProps?.style,
                          textTransform: 'uppercase'
                        }
                      }}
                      InputProps={{
                        ...params.InputProps,
                        startAdornment: (
                          <InputAdornment position="start">
                            <Search sx={{ color: theme.palette.text.secondary, fontSize: 20 }} />
                          </InputAdornment>
                        ),
                        endAdornment: (
                          <>
                            {propertySearchLoading ? <CircularProgress color="inherit" size={18} /> : null}
                            {params.InputProps.endAdornment}
                          </>
                        )
                      }}
                    />
                  )}
                  renderOption={(props, option) => {
                    const declarant = formatDeclarantFromParts(
                      option.declarant_last_name,
                      option.declarant_first_name,
                      option.declarant_middle_initial
                    );
                    const business = sanitizeBusinessName(option.business_name);
                    const location = option.location;

                    return (
                      <Box
                        component="li"
                        {...props}
                        sx={{
                          py: 1,
                          px: 1.5,
                          borderBottom: 1,
                          borderColor: theme.palette.divider,
                          '&:hover': {
                            backgroundColor: theme.palette.action.hover
                          }
                        }}
                      >
                        <Box sx={{ width: '100%' }}>
                          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1 }}>
                            <Typography
                              variant="body2"
                              sx={{
                                fontFamily: 'monospace',
                                fontWeight: 700,
                                color: theme.palette.primary.main
                              }}
                            >
                              {option.tax_declaration_number}
                            </Typography>
                            {option.property_state && (
                              <Chip
                                label={option.property_state.toLowerCase()}
                                size="small"
                                variant="outlined"
                                color={option.property_state.toLowerCase() === 'current' ? 'success' : 'default'}
                                sx={{
                                  height: 20,
                                  fontSize: '0.7rem',
                                  textTransform: 'capitalize'
                                }}
                              />
                            )}
                          </Box>

                          {(declarant || business) && (
                            <Typography
                              variant="body2"
                              sx={{
                                color: theme.palette.text.primary,
                                fontWeight: 500,
                                mt: 0.25
                              }}
                            >
                              {declarant}
                              {business && declarant && (
                                <span style={{ color: theme.palette.text.secondary, fontWeight: 400 }}> / {business}</span>
                              )}
                              {business && !declarant && business}
                            </Typography>
                          )}

                          {location && (
                            <Typography
                              variant="caption"
                              sx={{
                                color: theme.palette.text.secondary,
                                display: 'block',
                                mt: 0.25
                              }}
                            >
                              {location}
                            </Typography>
                          )}
                        </Box>
                      </Box>
                    );
                  }}
                />

                {/* Property validation error */}
                {validationErrors.has('property_selection') && (
                  <Typography
                    variant="caption"
                    color="error"
                    sx={{ mt: 0.75, display: 'block' }}
                  >
                    Select a property before saving this request.
                  </Typography>
                )}

                {/* Selected Property Panel */}
                <AnimatePresence>
                  {selectedProperty && (
                    <motion.div
                      initial={{ opacity: 0, height: 0 }}
                      animate={{ opacity: 1, height: 'auto' }}
                      exit={{ opacity: 0, height: 0 }}
                      transition={{ duration: 0.2 }}
                    >
                      <Box
                        sx={{
                          mt: 2,
                          p: 2,
                          borderRadius: 1,
                          border: 1,
                          borderColor: theme.palette.divider,
                          backgroundColor: theme.palette.background.default
                        }}
                      >
                        {/* Selected Property Header */}
                        <Box
                          sx={{
                            display: 'flex',
                            alignItems: { xs: 'flex-start', sm: 'center' },
                            justifyContent: 'space-between',
                            flexDirection: { xs: 'column', sm: 'row' },
                            gap: 1,
                            pb: 1.5,
                            mb: 1.5,
                            borderBottom: 1,
                            borderColor: theme.palette.divider
                          }}
                        >
                          <Box>
                            <Typography
                              variant="caption"
                              sx={{
                                fontWeight: 700,
                                color: theme.palette.text.secondary,
                                textTransform: 'uppercase'
                              }}
                            >
                              Selected Property
                            </Typography>
                            <Typography
                              variant="h6"
                              sx={{
                                fontFamily: 'monospace',
                                fontWeight: 700,
                                color: theme.palette.text.primary,
                                lineHeight: 1.2,
                                mt: 0.25
                              }}
                            >
                              {selectedProperty.tax_declaration_number}
                            </Typography>
                            <Typography
                              variant="body2"
                              sx={{
                                color: theme.palette.text.primary,
                                fontWeight: 500,
                                mt: 0.25
                              }}
                            >
                              {renderOwnerDisplay(selectedProperty)}
                            </Typography>
                          </Box>

                          <Button
                            variant="outlined"
                            size="small"
                            onClick={handleViewTaxHistory}
                            disabled={taxHistoryLoading}
                            startIcon={
                              taxHistoryLoading ? (
                                <CircularProgress size={14} color="inherit" />
                              ) : (
                                <HistoryIcon fontSize="small" />
                              )
                            }
                          >
                            {taxHistoryLoading ? 'Loading History...' : 'View Tax History'}
                          </Button>
                        </Box>

                        {/* Property Details Grid */}
                        <Grid container spacing={2}>
                          <Grid item xs={12} sm={6} md={3}>
                            <Typography variant="caption" sx={{ color: theme.palette.text.secondary, fontWeight: 600 }}>
                              LOCATION
                            </Typography>
                            <Typography variant="body2" sx={{ color: theme.palette.text.primary, fontWeight: 500 }}>
                              {selectedProperty.location || '—'}
                            </Typography>
                          </Grid>

                          <Grid item xs={12} sm={6} md={3}>
                            <Typography variant="caption" sx={{ color: theme.palette.text.secondary, fontWeight: 600 }}>
                              AREA
                            </Typography>
                            <Typography variant="body2" sx={{ color: theme.palette.text.primary, fontWeight: 500 }}>
                              {renderAreaDisplay(selectedProperty)}
                            </Typography>
                          </Grid>

                          <Grid item xs={12} sm={6} md={3}>
                            <Typography variant="caption" sx={{ color: theme.palette.text.secondary, fontWeight: 600 }}>
                              ASSESSED VALUE
                            </Typography>
                            <Typography variant="body2" sx={{ color: theme.palette.primary.main, fontWeight: 600 }}>
                              {renderAssessedValueDisplay(selectedProperty)}
                            </Typography>
                          </Grid>

                          <Grid item xs={12} sm={6} md={3}>
                            <Typography variant="caption" sx={{ color: theme.palette.text.secondary, fontWeight: 600 }}>
                              EFFECTIVITY
                            </Typography>
                            <Typography variant="body2" sx={{ color: theme.palette.text.primary, fontWeight: 500 }}>
                              {formatEffectivityDisplay(selectedProperty)}
                            </Typography>
                          </Grid>
                        </Grid>
                      </Box>
                    </motion.div>
                  )}
                </AnimatePresence>
              </Box>

              {/* ---------------- 2. CLIENT SECTION ---------------- */}
              <Box
                sx={{
                  p: 2,
                  borderRadius: 1,
                  border: 1,
                  borderColor: theme.palette.divider,
                  backgroundColor: theme.palette.background.paper
                }}
              >
                <SectionHeader
                  icon={ClientIcon}
                  title="Client"
                  subtitle="Who is requesting this document?"
                />

                <Grid container spacing={2}>
                  <Grid item xs={12} sm={7}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Client Name *"
                      value={formData.client_name}
                      onChange={handleChange('client_name')}
                      error={validationErrors.has('client_name')}
                      helperText={validationErrors.has('client_name') ? 'Client name is required.' : undefined}
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                    />
                  </Grid>

                  <Grid item xs={12} sm={5}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Contact Number"
                      value={formData.contact_number}
                      onChange={handleChange('contact_number')}
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                      placeholder="Optional"
                    />
                  </Grid>

                  <Grid item xs={12}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Client Address"
                      value={formData.client_address}
                      onChange={handleChange('client_address')}
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                      multiline
                      minRows={2}
                      maxRows={3}
                      placeholder="Street, Barangay, Municipality/City, Province"
                    />
                  </Grid>

                  <Grid item xs={12}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Remarks"
                      value={formData.remarks}
                      onChange={handleChange('remarks')}
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                      multiline
                      minRows={2}
                      maxRows={4}
                      placeholder="Additional notes or special instructions..."
                    />
                  </Grid>
                </Grid>
              </Box>

              {/* ---------------- 3. REQUEST DETAILS SECTION ---------------- */}
              <Box
                sx={{
                  p: 2,
                  borderRadius: 1,
                  border: 1,
                  borderColor: theme.palette.divider,
                  backgroundColor: theme.palette.background.paper
                }}
              >
                <SectionHeader
                  icon={DetailsIcon}
                  title="Request Details"
                  subtitle="Specify request type, purpose, and detailed description."
                />

                {/* Request Type Segmented Control at top of Request Details */}
                <Box sx={{ mb: 2 }}>
                  <Typography
                    variant="caption"
                    sx={{
                      fontWeight: 600,
                      color: theme.palette.text.secondary,
                      textTransform: 'uppercase',
                      display: 'block',
                      mb: 1
                    }}
                  >
                    Request Type
                  </Typography>

                  <Box
                    sx={{
                      display: 'grid',
                      gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' },
                      gap: 1.5,
                      p: 0.5,
                      borderRadius: 1,
                      border: 1,
                      borderColor: theme.palette.divider,
                      backgroundColor: theme.palette.background.default
                    }}
                  >
                    {/* STANDARD REQUEST BUTTON */}
                    <Box
                      component="button"
                      type="button"
                      onClick={() => {
                        if (isOfficialRequest) {
                          setIsOfficialRequest(false);
                          setFormData(prev => ({
                            ...prev,
                            amount_paid: prev.purpose && purposeAmountMap[prev.purpose] !== undefined
                              ? Number(purposeAmountMap[prev.purpose]).toFixed(2)
                              : '',
                            receipt_number: ''
                          }));
                        }
                      }}
                      sx={{
                        p: 1.25,
                        borderRadius: 1,
                        border: 1,
                        borderColor: !isOfficialRequest ? theme.palette.primary.main : 'transparent',
                        backgroundColor: !isOfficialRequest ? theme.palette.background.paper : 'transparent',
                        cursor: 'pointer',
                        textAlign: 'left',
                        display: 'flex',
                        alignItems: 'flex-start',
                        gap: 1.25,
                        transition: 'all 0.15s ease',
                        '&:hover': {
                          backgroundColor: !isOfficialRequest ? theme.palette.background.paper : theme.palette.action.hover
                        }
                      }}
                    >
                      <Box
                        sx={{
                          width: 14,
                          height: 14,
                          borderRadius: '50%',
                          border: 2,
                          borderColor: !isOfficialRequest ? theme.palette.primary.main : theme.palette.text.secondary,
                          backgroundColor: !isOfficialRequest ? theme.palette.primary.main : 'transparent',
                          mt: 0.35,
                          flexShrink: 0
                        }}
                      />
                      <Box>
                        <Typography
                          variant="body2"
                          sx={{
                            fontWeight: 600,
                            color: !isOfficialRequest ? theme.palette.primary.main : theme.palette.text.primary,
                            lineHeight: 1.2
                          }}
                        >
                          Standard Request
                        </Typography>
                        <Typography
                          variant="caption"
                          sx={{
                            color: theme.palette.text.secondary,
                            display: 'block',
                            mt: 0.25
                          }}
                        >
                          Regular paid request with Official Receipt
                        </Typography>
                      </Box>
                    </Box>

                    {/* OFFICIAL REQUEST BUTTON */}
                    <Box
                      component="button"
                      type="button"
                      onClick={() => {
                        if (!isOfficialRequest) {
                          setIsOfficialRequest(true);
                          setFormData(prev => ({
                            ...prev,
                            amount_paid: '0.00',
                            receipt_number: 'Official Use'
                          }));
                          setValidationErrors(prev => {
                            const next = new Set(prev);
                            next.delete('amount_paid');
                            next.delete('receipt_number');
                            return next;
                          });
                        }
                      }}
                      sx={{
                        p: 1.25,
                        borderRadius: 1,
                        border: 1,
                        borderColor: isOfficialRequest ? theme.palette.primary.main : 'transparent',
                        backgroundColor: isOfficialRequest ? theme.palette.background.paper : 'transparent',
                        cursor: 'pointer',
                        textAlign: 'left',
                        display: 'flex',
                        alignItems: 'flex-start',
                        gap: 1.25,
                        transition: 'all 0.15s ease',
                        '&:hover': {
                          backgroundColor: isOfficialRequest ? theme.palette.background.paper : theme.palette.action.hover
                        }
                      }}
                    >
                      <Box
                        sx={{
                          width: 14,
                          height: 14,
                          borderRadius: '50%',
                          border: 2,
                          borderColor: isOfficialRequest ? theme.palette.primary.main : theme.palette.text.secondary,
                          backgroundColor: isOfficialRequest ? theme.palette.primary.main : 'transparent',
                          mt: 0.35,
                          flexShrink: 0
                        }}
                      />
                      <Box>
                        <Typography
                          variant="body2"
                          sx={{
                            fontWeight: 600,
                            color: isOfficialRequest ? theme.palette.primary.main : theme.palette.text.primary,
                            lineHeight: 1.2
                          }}
                        >
                          Official Request
                        </Typography>
                        <Typography
                          variant="caption"
                          sx={{
                            color: theme.palette.text.secondary,
                            display: 'block',
                            mt: 0.25
                          }}
                        >
                          Government or internal use (No payment required)
                        </Typography>
                      </Box>
                    </Box>
                  </Box>
                </Box>

                <Grid container spacing={2}>
                  <Grid item xs={12}>
                    <FormControl
                      fullWidth
                      size="small"
                      error={validationErrors.has('purpose')}
                    >
                      <InputLabel id="request-purpose-label">Purpose *</InputLabel>
                      <Select
                        labelId="request-purpose-label"
                        value={formData.purpose}
                        onChange={handlePurposeChange}
                        label="Purpose *"
                      >
                        {purposeOptions.map((option) => (
                          <MenuItem key={option.value} value={option.value}>
                            {option.label}
                          </MenuItem>
                        ))}
                      </Select>
                      {validationErrors.has('purpose') && (
                        <Typography variant="caption" color="error" sx={{ mt: 0.5, ml: 1.5 }}>
                          Purpose is required.
                        </Typography>
                      )}
                    </FormControl>
                  </Grid>

                  <Grid item xs={12}>
                    <TextField
                      fullWidth
                      size="small"
                      multiline
                      minRows={3}
                      maxRows={5}
                      label="Purpose Details *"
                      value={formData.purpose_details}
                      onChange={handleChange('purpose_details')}
                      error={validationErrors.has('purpose_details')}
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                      helperText={
                        validationErrors.has('purpose_details')
                          ? 'Purpose Details is required.'
                          : 'Enter the specific purpose or statement. This text will appear directly on the printed document.'
                      }
                    />
                  </Grid>
                </Grid>
              </Box>

              {/* ---------------- 4. ISSUANCE SECTION ---------------- */}
              <Box
                sx={{
                  p: 2,
                  borderRadius: 1,
                  border: 1,
                  borderColor: theme.palette.divider,
                  backgroundColor: theme.palette.background.paper
                }}
              >
                <SectionHeader
                  icon={VerifiedIcon}
                  title="Issuance"
                  subtitle="Verification and issuing office parameters."
                />

                <Grid container spacing={2}>
                  <Grid item xs={12} sm={4}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Date Issued *"
                      type="date"
                      value={formData.date_issued}
                      onChange={handleChange('date_issued')}
                      InputLabelProps={{ shrink: true }}
                      error={validationErrors.has('date_issued')}
                      InputProps={{
                        startAdornment: (
                          <InputAdornment position="start">
                            <DateIcon sx={{ fontSize: 16, color: theme.palette.text.secondary }} />
                          </InputAdornment>
                        )
                      }}
                    />
                  </Grid>

                  <Grid item xs={12} sm={4}>
                    <Tooltip title="Configured via Office Settings (Read-only)" arrow>
                      <TextField
                        fullWidth
                        size="small"
                        label="Place Issued"
                        value={formData.place_issued}
                        onChange={handleChange('place_issued')}
                        InputProps={{
                          readOnly: true,
                          startAdornment: (
                            <InputAdornment position="start">
                              <LocationIcon sx={{ fontSize: 16, color: theme.palette.text.secondary }} />
                            </InputAdornment>
                          ),
                          endAdornment: (
                            <InputAdornment position="end">
                              <LockIcon sx={{ fontSize: 14, color: theme.palette.text.secondary }} />
                            </InputAdornment>
                          )
                        }}
                        inputProps={{ tabIndex: -1 }}
                        error={validationErrors.has('place_issued')}
                      />
                    </Tooltip>
                  </Grid>

                  <Grid item xs={12} sm={4}>
                    <Tooltip title="Current active user account (Read-only)" arrow>
                      <TextField
                        fullWidth
                        size="small"
                        label="Prepared By"
                        value={formData.prepared_by}
                        onChange={handleChange('prepared_by')}
                        InputLabelProps={{ shrink: true }}
                        InputProps={{
                          readOnly: true,
                          startAdornment: (
                            <InputAdornment position="start">
                              <PreparedByIcon sx={{ fontSize: 16, color: theme.palette.text.secondary }} />
                            </InputAdornment>
                          ),
                          endAdornment: (
                            <InputAdornment position="end">
                              <LockIcon sx={{ fontSize: 14, color: theme.palette.text.secondary }} />
                            </InputAdornment>
                          )
                        }}
                        inputProps={{ tabIndex: -1 }}
                        error={validationErrors.has('prepared_by')}
                      />
                    </Tooltip>
                  </Grid>
                </Grid>
              </Box>

              {/* ---------------- 5. PAYMENT SECTION ---------------- */}
              <Box
                sx={{
                  p: 2,
                  borderRadius: 1,
                  border: 1,
                  borderColor: theme.palette.divider,
                  backgroundColor: theme.palette.background.paper,
                  minHeight: '160px',
                  display: 'flex',
                  flexDirection: 'column'
                }}
              >
                <SectionHeader
                  icon={PaymentIcon}
                  title="Payment"
                  subtitle="Payment details and official receipt records."
                />

                {/* Stable Grid for Payment Fields */}
                <Grid container spacing={2}>
                  <Grid item xs={12} sm={6}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Amount Paid *"
                      type={isOfficialRequest ? 'text' : 'number'}
                      value={isOfficialRequest ? '0.00' : formData.amount_paid}
                      onChange={handleChange('amount_paid')}
                      disabled={isOfficialRequest}
                      helperText={
                        isOfficialRequest
                          ? 'No payment required.'
                          : 'Amount based on selected purpose.'
                      }
                      InputProps={{
                        readOnly: isOfficialRequest,
                        startAdornment: (
                          <InputAdornment position="start">
                            <span style={{ fontWeight: 600, color: theme.palette.text.secondary }}>₱</span>
                          </InputAdornment>
                        ),
                        ...(isOfficialRequest ? {
                          sx: {
                            backgroundColor: theme.palette.background.default,
                            '& .MuiInputBase-input': {
                              color: theme.palette.text.primary,
                              fontWeight: 600
                            }
                          }
                        } : {})
                      }}
                      inputProps={{ min: 0, step: 0.01 }}
                      error={!isOfficialRequest && validationErrors.has('amount_paid')}
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
                    />
                  </Grid>

                  <Grid item xs={12} sm={6}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Receipt Number *"
                      value={isOfficialRequest ? 'Official Use' : formData.receipt_number}
                      onChange={handleChange('receipt_number')}
                      disabled={isOfficialRequest}
                      helperText={
                        isOfficialRequest
                          ? 'Official request record.'
                          : 'Enter official receipt number.'
                      }
                      inputProps={{ style: { textTransform: 'uppercase' } }}
                      error={!isOfficialRequest && validationErrors.has('receipt_number')}
                      placeholder="e.g. OR-123456"
                      InputProps={
                        isOfficialRequest ? {
                          readOnly: true,
                          sx: {
                            backgroundColor: theme.palette.background.default,
                            '& .MuiInputBase-input': {
                              color: theme.palette.text.primary,
                              fontWeight: 600
                            }
                          }
                        } : undefined
                      }
                    />
                  </Grid>
                </Grid>

                {/* Stable subtle indicator for Official Request */}
                <Box sx={{ mt: 1, minHeight: 20, display: 'flex', alignItems: 'center' }}>
                  {isOfficialRequest ? (
                    <Typography
                      variant="caption"
                      sx={{
                        color: theme.palette.success.main,
                        fontWeight: 600
                      }}
                    >
                      Official Request — No payment required for this request.
                    </Typography>
                  ) : null}
                </Box>
              </Box>

            </Box>
          </form>
        </DialogContent>

        {/* ================= MODAL FOOTER ================= */}
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
            Request will be permanently recorded in assessment logs.
          </Typography>

          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, ml: 'auto' }}>
            <Button
              onClick={handleClose}
              variant="outlined"
              disabled={loading}
            >
              Cancel
            </Button>

            <Button
              onClick={handleSubmit}
              variant="contained"
              disabled={loading}
              startIcon={
                loading ? (
                  <CircularProgress size={16} color="inherit" />
                ) : (
                  <SaveIcon />
                )
              }
            >
              {loading ? 'Saving Request...' : 'Save Request'}
            </Button>
          </Box>
        </DialogActions>
      </Dialog>

      {/* ================= TAX DECLARATION HISTORY MODAL ================= */}
      <Dialog
        open={taxHistoryModal}
        onClose={() => setTaxHistoryModal(false)}
        maxWidth="lg"
        fullWidth
        PaperProps={{
          component: motion.div,
          initial: { opacity: 0, y: 12 },
          animate: { opacity: 1, y: 0 },
          transition: { duration: 0.2 },
          sx: {
            width: { xs: 'calc(100vw - 24px)', sm: '90vw', md: '1100px' },
            maxHeight: '90vh',
            borderRadius: 1,
            display: 'flex',
            flexDirection: 'column',
            overflow: 'hidden',
            backgroundColor: theme.palette.background.paper
          }
        }}
      >
        {/* Tax History Header */}
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
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
            <HistoryIcon sx={{ fontSize: 22, color: theme.palette.primary.main }} />
            <Box>
              <Typography variant="h6" sx={{ fontWeight: 600, color: theme.palette.text.primary, lineHeight: 1.2 }}>
                Tax Declaration History
              </Typography>
              <Typography
                variant="caption"
                sx={{
                  color: theme.palette.text.secondary,
                  fontFamily: 'monospace',
                  fontWeight: 600
                }}
              >
                {selectedProperty?.tax_declaration_number}
              </Typography>
            </Box>
          </Box>

          <IconButton
            size="small"
            onClick={() => setTaxHistoryModal(false)}
            aria-label="Close Tax History"
            sx={{
              color: theme.palette.text.secondary,
              '&:hover': {
                color: theme.palette.error.main
              }
            }}
          >
            <CloseIcon fontSize="small" />
          </IconButton>
        </DialogTitle>

        <DialogContent
          sx={{
            p: { xs: 2, sm: 2.5 },
            overflowY: 'auto',
            backgroundColor: theme.palette.background.paper
          }}
        >
          {taxHistoryLoading ? (
            <Box display="flex" flexDirection="column" alignItems="center" justifyContent="center" py={8} gap={2}>
              <CircularProgress size={32} />
              <Typography variant="body2" sx={{ color: theme.palette.text.secondary }}>
                Loading tax declaration history chain...
              </Typography>
            </Box>
          ) : taxHistory.length > 0 ? (
            <Box>
              {/* Compact Property Summary Box */}
              <Box
                sx={{
                  mb: 2,
                  p: 1.5,
                  borderRadius: 1,
                  border: 1,
                  borderColor: theme.palette.divider,
                  backgroundColor: theme.palette.background.default,
                  display: 'flex',
                  flexWrap: 'wrap',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  gap: 1.5
                }}
              >
                <Box>
                  <Typography variant="caption" sx={{ color: theme.palette.text.secondary, fontWeight: 700 }}>
                    SUBJECT PROPERTY
                  </Typography>
                  <Typography sx={{ fontFamily: 'monospace', fontWeight: 700, color: theme.palette.primary.main }}>
                    {selectedProperty?.tax_declaration_number}
                  </Typography>
                  <Typography variant="body2" sx={{ fontWeight: 500, color: theme.palette.text.primary }}>
                    {renderOwnerDisplay(selectedProperty)}
                  </Typography>
                </Box>
                <Box sx={{ display: 'flex', gap: 2 }}>
                  <Box>
                    <Typography variant="caption" sx={{ color: theme.palette.text.secondary, fontWeight: 600 }}>LOCATION</Typography>
                    <Typography variant="body2" sx={{ fontWeight: 500 }}>{selectedProperty?.location || '—'}</Typography>
                  </Box>
                  <Box>
                    <Typography variant="caption" sx={{ color: theme.palette.text.secondary, fontWeight: 600 }}>ASSESSED VALUE</Typography>
                    <Typography variant="body2" sx={{ fontWeight: 600, color: theme.palette.primary.main }}>
                      {renderAssessedValueDisplay(selectedProperty)}
                    </Typography>
                  </Box>
                </Box>
              </Box>

              {/* Table Container */}
              <TableContainer
                component={Paper}
                elevation={0}
                sx={{
                  maxHeight: '55vh',
                  overflow: 'auto',
                  border: 1,
                  borderColor: theme.palette.divider,
                  borderRadius: 1
                }}
              >
                <Table size="small" stickyHeader>
                  <TableHead>
                    <TableRow>
                      <TableCell sx={{ fontWeight: 600 }}>Tax Declaration No.</TableCell>
                      <TableCell sx={{ fontWeight: 600 }}>Declarant</TableCell>
                      <TableCell sx={{ fontWeight: 600 }}>Barangay</TableCell>
                      <TableCell sx={{ fontWeight: 600 }}>Lot Number</TableCell>
                      <TableCell sx={{ fontWeight: 600 }}>Survey Number</TableCell>
                      <TableCell sx={{ fontWeight: 600 }}>Area</TableCell>
                      <TableCell sx={{ fontWeight: 600 }}>Title Number</TableCell>
                      <TableCell sx={{ fontWeight: 600 }}>Assessed Value</TableCell>
                      <TableCell sx={{ fontWeight: 600 }}>Effectivity</TableCell>
                      <TableCell sx={{ fontWeight: 600, minWidth: 220 }}>Memoranda</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody sx={{ '& td': { verticalAlign: 'top', py: 1 } }}>
                    {taxHistory.map((item, index) => {
                      const wasConsolidatedInto = taxHistory.some(otherItem => {
                        if (otherItem.previous_tax_declaration_number && String(otherItem.previous_tax_declaration_number).includes(';')) {
                          const prevTds = String(otherItem.previous_tax_declaration_number).split(';').map(td => String(td).trim());
                          return prevTds.includes(String(item.tax_declaration_number).trim());
                        }
                        return false;
                      });
                      const isConsolidatedTD = item.previous_tax_declaration_number && String(item.previous_tax_declaration_number).includes(';');
                      const isConsolidated = wasConsolidatedInto || isConsolidatedTD;

                      const stateText = item.property_state ? item.property_state.toLowerCase() : (index === 0 ? 'current' : 'previous');
                      const isCurrentState = stateText === 'current';
                      const isCancelledState = stateText === 'cancelled';

                      return (
                        <TableRow
                          key={index}
                          hover
                          sx={{
                            backgroundColor: isCurrentState ? theme.palette.action.hover : 'inherit'
                          }}
                        >
                          <TableCell>
                            <Typography
                              variant="body2"
                              sx={{
                                fontFamily: 'monospace',
                                fontWeight: 700,
                                color: isConsolidated
                                  ? theme.palette.warning.main
                                  : (isCurrentState ? theme.palette.primary.main : theme.palette.text.primary)
                              }}
                            >
                              {item.tax_declaration_number}
                            </Typography>

                            <Box sx={{ display: 'flex', gap: 0.5, mt: 0.5, flexWrap: 'wrap' }}>
                              <Chip
                                label={stateText}
                                size="small"
                                variant="outlined"
                                color={
                                  isCurrentState
                                    ? 'success'
                                    : isCancelledState
                                      ? 'error'
                                      : 'default'
                                }
                                sx={{
                                  height: 18,
                                  fontSize: '0.65rem',
                                  textTransform: 'uppercase',
                                  fontWeight: 600
                                }}
                              />
                              {isConsolidated && (
                                <Chip
                                  label="CONSOLIDATED"
                                  size="small"
                                  variant="outlined"
                                  color="warning"
                                  sx={{
                                    height: 18,
                                    fontSize: '0.65rem',
                                    fontWeight: 600
                                  }}
                                />
                              )}
                            </Box>
                          </TableCell>

                          <TableCell>
                            {(() => {
                              const d = normalizeDeclarantString(item.declarant_name);
                              const b = sanitizeBusinessName(item.business_name);
                              if (!d && !b) return '—';
                              return (
                                <Box>
                                  {d && (
                                    <Typography variant="body2" sx={{ fontWeight: 600, color: theme.palette.text.primary }}>
                                      {d}
                                    </Typography>
                                  )}
                                  {b && (
                                    <Typography variant="caption" sx={{ color: theme.palette.text.secondary }}>
                                      {b}
                                    </Typography>
                                  )}
                                </Box>
                              );
                            })()}
                          </TableCell>

                          <TableCell sx={{ fontSize: '0.85rem' }}>{item.location || '—'}</TableCell>
                          <TableCell sx={{ fontSize: '0.85rem' }}>{item.lot_number || '—'}</TableCell>
                          <TableCell sx={{ fontSize: '0.85rem' }}>{item.survey_number || '—'}</TableCell>

                          <TableCell sx={{ fontSize: '0.85rem', whiteSpace: 'nowrap' }}>
                            {renderAreaDisplay(item)}
                          </TableCell>

                          <TableCell sx={{ fontSize: '0.85rem' }}>{item.title_number || '—'}</TableCell>

                          <TableCell sx={{ fontSize: '0.85rem', fontWeight: 600, color: theme.palette.primary.main, whiteSpace: 'nowrap' }}>
                            {renderAssessedValueDisplay(item)}
                          </TableCell>

                          <TableCell sx={{ fontSize: '0.85rem' }}>{formatEffectivityDisplay(item)}</TableCell>

                          <TableCell sx={{ maxWidth: 280 }}>
                            <Typography
                              variant="body2"
                              sx={{
                                fontSize: '0.8rem',
                                color: theme.palette.text.primary,
                                whiteSpace: 'pre-wrap',
                                wordBreak: 'break-word'
                              }}
                            >
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
            <Box py={6} textAlign="center">
              <Typography color="text.secondary">
                No tax declaration history found for this property.
              </Typography>
            </Box>
          )}
        </DialogContent>

        <DialogActions
          sx={{
            py: 1.5,
            px: { xs: 2, sm: 3 },
            borderTop: 1,
            borderColor: 'divider',
            backgroundColor: theme.palette.background.default,
            display: 'flex',
            justifyContent: 'space-between',
            flexShrink: 0
          }}
        >
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
          >
            Confirm Property Selection
          </Button>
        </DialogActions>
      </Dialog>

      {/* ================= TOAST NOTIFICATION ================= */}
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
