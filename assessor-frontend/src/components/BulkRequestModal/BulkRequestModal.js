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
  IconButton,
  Tooltip
} from '@mui/material';
import {
  Close as CloseIcon,
  Delete as DeleteIcon,
  Search as SearchIcon,
  PlaylistAdd as PlaylistAddIcon
} from '@mui/icons-material';
import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';

// Helper function to format declarant name from parts
const formatDeclarantFromParts = (last, first, middle) => {
  const hasNames = !!(last || first);
  if (!hasNames) return '';
  const raw = (middle || '').trim();
  const mi = raw.replace(/\./g, '');
  const middleFormatted = mi ? (mi.length === 1 ? ` ${mi}.` : ` ${mi}`) : '';
  return `${last || ''}${hasNames && first ? ', ' : ''}${first || ''}${middleFormatted}`.trim();
};

const formatPropertyTitle = (prop) => {
  if (!prop) return '';
  const declarant = formatDeclarantFromParts(
    prop.declarant_last_name,
    prop.declarant_first_name,
    prop.declarant_middle_initial
  );
  const business = prop.business ? String(prop.business).replace(/,\s*/g, ' ') : '';
  return declarant && business ? `${declarant} | ${business}` : (declarant || business || '—');
};

// Helper function to uppercase field values on submit (matching RequestFormModal)
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

const BulkRequestModal = ({
  open,
  onClose,
  onSuccess
}) => {
  const { user } = useAuth();

  // Shared form data (no payment_type, no amount fields)
  const [formData, setFormData] = useState({
    client_name: '',
    client_address: '',
    contact_number: '',
    email: '',
    purpose: '',
    purpose_details: '',
    date_issued: '',
    place_issued: '',
    prepared_by: '',
    remarks: ''
  });

  const [isOfficialRequest, setIsOfficialRequest] = useState(false);
  const [purposeOptions, setPurposeOptions] = useState([]);
  const [purposeAmountMap, setPurposeAmountMap] = useState({});

  // Property search state
  const [propertySearchTerm, setPropertySearchTerm] = useState('');
  const [propertyOptions, setPropertyOptions] = useState([]);
  const [propertySearchLoading, setPropertySearchLoading] = useState(false);

  // Selected TDs for bulk request: array of items: { property, receipt_number }
  const [selectedItems, setSelectedItems] = useState([]);

  // Submission state & error messages
  const [submitting, setSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState('');

  // Load defaults, settings, and active purposes
  useEffect(() => {
    if (!open) return;

    const today = new Date().toISOString().split('T')[0];
    setFormData({
      client_name: '',
      client_address: '',
      contact_number: '',
      email: '',
      purpose: '',
      purpose_details: '',
      date_issued: today,
      place_issued: '',
      prepared_by: user?.full_name || user?.username || '',
      remarks: ''
    });
    setIsOfficialRequest(false);
    setSelectedItems([]);
    setPropertySearchTerm('');
    setPropertyOptions([]);
    setErrorMessage('');

    const loadDefaults = async () => {
      try {
        const [settings, purposesRes] = await Promise.all([
          apiService.getSettings(),
          apiService.getRequestPurposes()
        ]);

        const activeItems = (purposesRes?.items || []).filter(x => x.status === 'active');
        const opts = activeItems.map(p => ({
          value: String(p.purpose || ''),
          label: String(p.purpose || ''),
          amount: Number(p.amount) || 0
        })).filter(x => x.value);

        setPurposeOptions(opts);

        const amountMap = {};
        opts.forEach(o => {
          amountMap[o.value] = o.amount;
        });
        setPurposeAmountMap(amountMap);

        const defaultPurpose = opts[0]?.value || '';
        const defaultPlace = settings?.request_place_issued_default || '';

        setFormData(prev => ({
          ...prev,
          purpose: prev.purpose || defaultPurpose,
          place_issued: prev.place_issued || defaultPlace,
          prepared_by: prev.prepared_by || (user?.full_name || user?.username || '')
        }));
      } catch (err) {
        console.error('Error loading request defaults:', err);
        try {
          const fallbackSettings = await apiService.getSettings();
          setFormData(prev => ({
            ...prev,
            place_issued: prev.place_issued || (fallbackSettings?.request_place_issued_default || ''),
            prepared_by: prev.prepared_by || (user?.full_name || user?.username || '')
          }));
        } catch (_) {
          // ignore fallback error
        }
      }
    };

    loadDefaults();
  }, [open, user]);

  // Handle shared field changes
  const handleFieldChange = (field, value) => {
    setFormData(prev => ({
      ...prev,
      [field]: value
    }));
  };

  // Toggle official request
  const handleToggleOfficial = (e) => {
    const checked = e.target.checked;
    setIsOfficialRequest(checked);

    if (checked) {
      setSelectedItems(prev => prev.map(item => ({
        ...item,
        receipt_number: 'Official Use'
      })));
    } else {
      setSelectedItems(prev => prev.map(item => ({
        ...item,
        receipt_number: item.receipt_number === 'Official Use' ? '' : item.receipt_number
      })));
    }
  };

  // Search properties with debouncing
  useEffect(() => {
    if (!open) return;
    const term = propertySearchTerm.trim();
    if (term.length < 2) {
      setPropertyOptions([]);
      return;
    }

    const timer = setTimeout(async () => {
      try {
        setPropertySearchLoading(true);
        const response = await apiService.getProperties({
          q: term,
          per_page: 10,
          _t: Date.now()
        });

        const list = response?.properties || response?.data || [];
        setPropertyOptions(list);
      } catch (err) {
        console.error('Error searching properties:', err);
        setPropertyOptions([]);
      } finally {
        setPropertySearchLoading(false);
      }
    }, 300);

    return () => clearTimeout(timer);
  }, [propertySearchTerm, open]);

  // Add property to selected table
  const handleAddProperty = (property) => {
    if (!property || !property.id) return;

    // Prevent duplicate property selection
    const exists = selectedItems.some(i => i.property.id === property.id);
    if (exists) {
      setErrorMessage(`Property TD "${property.tax_declaration_number || property.id}" is already added.`);
      return;
    }

    setErrorMessage('');

    setSelectedItems(prev => [
      ...prev,
      {
        property,
        receipt_number: isOfficialRequest ? 'Official Use' : ''
      }
    ]);

    // Clear search input
    setPropertySearchTerm('');
    setPropertyOptions([]);
  };

  // Remove property from selected table
  const handleRemoveItem = (indexToRemove) => {
    setSelectedItems(prev => prev.filter((_, idx) => idx !== indexToRemove));
  };

  // Update item receipt number
  const handleItemReceiptChange = (index, value) => {
    setSelectedItems(prev => prev.map((item, idx) => {
      if (idx !== index) return item;
      return {
        ...item,
        receipt_number: value
      };
    }));
  };

  // Rates and total computation (read-only)
  const ratePerRequest = isOfficialRequest ? 0 : (purposeAmountMap[formData.purpose] || 0);
  const totalCalculatedAmount = isOfficialRequest ? 0 : (ratePerRequest * selectedItems.length);

  // UI validations for submit button
  const hasItems = selectedItems.length > 0;
  const hasSharedRequired = !!(
    formData.client_name?.trim() &&
    formData.date_issued?.trim() &&
    formData.place_issued?.trim() &&
    formData.prepared_by?.trim() &&
    formData.purpose?.trim() &&
    formData.purpose_details?.trim()
  );

  // Check item validations
  const receiptNumbers = selectedItems.map(i => (i.receipt_number || '').trim());
  const hasDuplicateReceipts = !isOfficialRequest && (
    new Set(receiptNumbers.filter(Boolean)).size !== receiptNumbers.filter(Boolean).length
  );
  const hasEmptyReceipts = !isOfficialRequest && receiptNumbers.some(r => !r);

  const isSubmitDisabled =
    !hasItems ||
    !hasSharedRequired ||
    hasEmptyReceipts ||
    hasDuplicateReceipts ||
    submitting;

  // Handle Bulk Submit
  const handleSubmit = async (e) => {
    e.preventDefault();
    if (isSubmitDisabled) return;

    setErrorMessage('');
    setSubmitting(true);

    try {
      const payload = {
        client_name: uppercaseFieldValue('client_name', formData.client_name),
        client_address: uppercaseFieldValue('client_address', formData.client_address),
        contact_number: uppercaseFieldValue('contact_number', formData.contact_number),
        email: formData.email?.trim() || '',
        date_issued: formData.date_issued,
        place_issued: uppercaseFieldValue('place_issued', formData.place_issued),
        prepared_by: uppercaseFieldValue('prepared_by', formData.prepared_by),
        purpose: formData.purpose,
        purpose_details: formData.purpose_details,
        remarks: uppercaseFieldValue('remarks', formData.remarks),
        is_official_request: isOfficialRequest ? 1 : 0,
        items: selectedItems.map(item => ({
          property_id: item.property.id,
          receipt_number: isOfficialRequest ? 'Official Use' : uppercaseFieldValue('receipt_number', item.receipt_number)
        }))
      };

      const response = await apiService.createBulkRequests(payload);

      if (response && response.success) {
        if (onSuccess) {
          onSuccess(response);
        }
        onClose();
      } else {
        setErrorMessage(response?.message || 'Failed to create bulk requests.');
      }
    } catch (err) {
      console.error('Error creating bulk requests:', err);
      setErrorMessage(err?.message || 'An error occurred while creating bulk requests.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      onClose={submitting ? undefined : onClose}
      maxWidth="md"
      fullWidth
      PaperProps={{
        sx: {
          borderRadius: 3,
          maxHeight: '92vh',
          display: 'flex',
          flexDirection: 'column'
        }
      }}
    >
      <DialogTitle sx={{ pb: 1, pt: 2.5, px: 3, display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <Box>
          <Typography variant="h6" sx={{ fontWeight: 700, color: 'text.primary', lineHeight: 1.2 }}>
            Create Bulk Request
          </Typography>
          <Typography variant="caption" color="text.secondary">
            One requestor • Multiple Tax Declarations
          </Typography>
        </Box>
        <IconButton
          size="small"
          onClick={onClose}
          disabled={submitting}
          sx={{ color: 'text.secondary' }}
        >
          <CloseIcon fontSize="small" />
        </IconButton>
      </DialogTitle>

      <Divider />

      <DialogContent sx={{ p: 3, overflowY: 'auto' }}>
        {errorMessage && (
          <Alert severity="error" sx={{ mb: 2.5 }} onClose={() => setErrorMessage('')}>
            {errorMessage}
          </Alert>
        )}

        <form id="bulk-request-form" onSubmit={handleSubmit}>
          {/* SECTION A: REQUESTOR */}
          <Typography
            variant="subtitle2"
            sx={{
              fontWeight: 700,
              textTransform: 'uppercase',
              letterSpacing: '0.05em',
              color: 'primary.main',
              mb: 1.5
            }}
          >
            A. Requestor Information
          </Typography>

          <Grid container spacing={2} sx={{ mb: 3 }}>
            <Grid item xs={12} sm={6}>
              <TextField
                fullWidth
                size="small"
                required
                label="Client Name"
                placeholder="e.g. JUAN DELA CRUZ"
                value={formData.client_name}
                inputProps={{ style: { textTransform: 'uppercase' } }}
                onChange={(e) => handleFieldChange('client_name', e.target.value)}
              />
            </Grid>
            <Grid item xs={12} sm={6}>
              <TextField
                fullWidth
                size="small"
                label="Client Address"
                placeholder="e.g. POBLACION, KITAOTAO"
                value={formData.client_address}
                inputProps={{ style: { textTransform: 'uppercase' } }}
                onChange={(e) => handleFieldChange('client_address', e.target.value)}
              />
            </Grid>
            <Grid item xs={12} sm={6}>
              <TextField
                fullWidth
                size="small"
                label="Contact Number"
                placeholder="e.g. 0917..."
                value={formData.contact_number}
                inputProps={{ style: { textTransform: 'uppercase' } }}
                onChange={(e) => handleFieldChange('contact_number', e.target.value)}
              />
            </Grid>
            <Grid item xs={12} sm={6}>
              <TextField
                fullWidth
                size="small"
                type="email"
                label="Email"
                placeholder="client@example.com"
                value={formData.email}
                onChange={(e) => handleFieldChange('email', e.target.value)}
              />
            </Grid>
          </Grid>

          {/* SECTION B: REQUEST DETAILS */}
          <Typography
            variant="subtitle2"
            sx={{
              fontWeight: 700,
              textTransform: 'uppercase',
              letterSpacing: '0.05em',
              color: 'primary.main',
              mb: 1.5
            }}
          >
            B. Request Details
          </Typography>

          <Grid container spacing={2} sx={{ mb: 3 }}>
            <Grid item xs={12} sm={6}>
              <FormControl fullWidth size="small" required>
                <InputLabel>Purpose</InputLabel>
                <Select
                  value={formData.purpose}
                  onChange={(e) => handleFieldChange('purpose', e.target.value)}
                  label="Purpose"
                >
                  {purposeOptions.map(opt => (
                    <MenuItem key={opt.value} value={opt.value}>
                      {opt.label}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </Grid>

            <Grid item xs={12} sm={6}>
              <FormControlLabel
                control={
                  <Checkbox
                    checked={isOfficialRequest}
                    onChange={handleToggleOfficial}
                    color="primary"
                    size="small"
                  />
                }
                label={
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    Official Use (Free / No Receipt Required)
                  </Typography>
                }
                sx={{ mt: 0.5 }}
              />
            </Grid>

            <Grid item xs={12}>
              <TextField
                fullWidth
                size="small"
                required
                multiline
                rows={2}
                label="Purpose Details / Request Description"
                placeholder="e.g. FOR BIR REQUIREMENT / VERIFICATION OF PROPERTY RECORDS..."
                value={formData.purpose_details}
                onChange={(e) => handleFieldChange('purpose_details', e.target.value)}
                helperText="This is the main request description displayed in the requests table."
              />
            </Grid>

            <Grid item xs={12} sm={4}>
              <TextField
                fullWidth
                size="small"
                type="date"
                label="Date Issued"
                InputLabelProps={{ shrink: true }}
                value={formData.date_issued}
                onChange={(e) => handleFieldChange('date_issued', e.target.value)}
              />
            </Grid>

            <Grid item xs={12} sm={4}>
              <TextField
                fullWidth
                size="small"
                label="Place Issued"
                value={formData.place_issued || ''}
                InputProps={{
                  readOnly: true
                }}
                inputProps={{
                  tabIndex: -1,
                }}
                sx={{
                  pointerEvents: 'none'
                }}
              />
            </Grid>

            <Grid item xs={12} sm={4}>
              <TextField
                fullWidth
                size="small"
                label="Prepared By"
                value={formData.prepared_by || ''}
                InputProps={{
                  readOnly: true
                }}
                inputProps={{
                  tabIndex: -1,
                }}
                sx={{
                  pointerEvents: 'none'
                }}
              />
            </Grid>

            <Grid item xs={12}>
              <TextField
                fullWidth
                size="small"
                label="Remarks (Optional)"
                placeholder="Internal notes or reference numbers"
                value={formData.remarks}
                inputProps={{ style: { textTransform: 'uppercase' } }}
                onChange={(e) => handleFieldChange('remarks', e.target.value)}
              />
            </Grid>
          </Grid>

          {/* SECTION C: BULK PROPERTY SELECTION */}
          <Typography
            variant="subtitle2"
            sx={{
              fontWeight: 700,
              textTransform: 'uppercase',
              letterSpacing: '0.05em',
              color: 'primary.main',
              mb: 1.5
            }}
          >
            C. Tax Declarations ({selectedItems.length} Selected)
          </Typography>

          <Box sx={{ mb: 2 }}>
            <Autocomplete
              options={propertyOptions}
              loading={propertySearchLoading}
              getOptionLabel={(option) => {
                const title = formatPropertyTitle(option);
                return `${option.tax_declaration_number || 'No TD'} — ${title} (${option.location || ''})`;
              }}
              filterOptions={(x) => x}
              onChange={(e, val) => handleAddProperty(val)}
              value={null}
              inputValue={propertySearchTerm}
              onInputChange={(e, val) => setPropertySearchTerm(val)}
              renderInput={(params) => (
                <TextField
                  {...params}
                  size="small"
                  placeholder="Type TD Number, Declarant name, or Location to find and add property..."
                  InputProps={{
                    ...params.InputProps,
                    startAdornment: (
                      <InputAdornment position="start">
                        <SearchIcon fontSize="small" color="action" />
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
            />
          </Box>

          {/* SELECTED TD TABLE */}
          {selectedItems.length === 0 ? (
            <Paper
              variant="outlined"
              sx={{
                p: 3,
                textAlign: 'center',
                backgroundColor: '#f8fafc',
                borderStyle: 'dashed'
              }}
            >
              <Typography variant="body2" color="text.secondary">
                No Tax Declarations added yet. Search above to add properties to this bulk request.
              </Typography>
            </Paper>
          ) : (
            <TableContainer component={Paper} variant="outlined" sx={{ maxHeight: 280, borderRadius: 2 }}>
              <Table size="small" stickyHeader>
                <TableHead>
                  <TableRow sx={{ '& th': { backgroundColor: '#f1f5f9', fontWeight: 700, fontSize: '0.72rem', textTransform: 'uppercase' } }}>
                    <TableCell sx={{ width: '170px' }}>TD Number</TableCell>
                    <TableCell>Declarant / Business</TableCell>
                    <TableCell sx={{ width: '150px' }}>Barangay</TableCell>
                    <TableCell sx={{ width: '160px' }}>Receipt No. *</TableCell>
                    <TableCell sx={{ width: '60px', textAlign: 'center' }}>Remove</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {selectedItems.map((item, idx) => {
                    const isDuplicate = !isOfficialRequest && item.receipt_number && selectedItems.some((other, oIdx) => oIdx !== idx && other.receipt_number?.trim() === item.receipt_number?.trim());

                    return (
                      <TableRow key={item.property.id || idx} hover>
                        <TableCell sx={{ fontWeight: 600, fontSize: '0.8rem' }}>
                          {item.property.tax_declaration_number || '—'}
                        </TableCell>
                        <TableCell sx={{ fontSize: '0.78rem' }}>
                          {formatPropertyTitle(item.property)}
                        </TableCell>
                        <TableCell sx={{ fontSize: '0.78rem', color: 'text.secondary' }}>
                          {item.property.location || '—'}
                        </TableCell>
                        <TableCell>
                          <TextField
                            size="small"
                            value={item.receipt_number}
                            disabled={isOfficialRequest}
                            error={isDuplicate}
                            helperText={isDuplicate ? 'Duplicate' : ''}
                            inputProps={{ style: { textTransform: 'uppercase' } }}
                            onChange={(e) => handleItemReceiptChange(idx, e.target.value)}
                            sx={{
                              '& .MuiInputBase-input': {
                                py: 0.5,
                                px: 1,
                                fontSize: '0.78rem'
                              },
                              '& .MuiFormHelperText-root': {
                                m: 0,
                                fontSize: '0.65rem'
                              }
                            }}
                          />
                        </TableCell>
                        <TableCell sx={{ textAlign: 'center' }}>
                          <Tooltip title="Remove TD">
                            <IconButton
                              size="small"
                              color="error"
                              onClick={() => handleRemoveItem(idx)}
                              disabled={submitting}
                            >
                              <DeleteIcon fontSize="small" />
                            </IconButton>
                          </Tooltip>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )}

          {hasDuplicateReceipts && (
            <Typography variant="caption" color="error" sx={{ display: 'block', mt: 1 }}>
              * Duplicate receipt numbers detected across selected Tax Declarations. Each regular request must have a unique receipt number.
            </Typography>
          )}

          {/* BULK SUMMARY (READ-ONLY) */}
          {selectedItems.length > 0 && (
            <Box sx={{ mt: 2, p: 1.5, backgroundColor: '#f8fafc', borderRadius: 2, border: '1px solid #e2e8f0', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <Box>
                <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.primary' }}>
                  {selectedItems.length} Tax Declaration{selectedItems.length > 1 ? 's' : ''}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {isOfficialRequest ? 'Official Use' : `Rate per Request: ₱${ratePerRequest.toFixed(2)}`}
                </Typography>
              </Box>
              <Typography variant="subtitle1" sx={{ fontWeight: 800, color: 'primary.main' }}>
                Total: ₱{totalCalculatedAmount.toFixed(2)}
              </Typography>
            </Box>
          )}
        </form>
      </DialogContent>

      <Divider />

      <DialogActions sx={{ p: 2, px: 3, justifyContent: 'space-between' }}>
        <Button
          onClick={onClose}
          disabled={submitting}
          color="inherit"
          size="small"
        >
          Cancel
        </Button>
        <Button
          type="submit"
          form="bulk-request-form"
          variant="contained"
          color="primary"
          size="small"
          disabled={isSubmitDisabled}
          startIcon={submitting ? <CircularProgress size={16} color="inherit" /> : <PlaylistAddIcon />}
          sx={{ px: 2.5, fontWeight: 700 }}
        >
          {submitting
            ? 'Creating Requests...'
            : (selectedItems.length > 0
              ? `Create ${selectedItems.length} Request${selectedItems.length > 1 ? 's' : ''}`
              : 'Create Requests')}
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default BulkRequestModal;
