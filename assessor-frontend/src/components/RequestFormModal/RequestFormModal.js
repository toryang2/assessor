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
  DialogContent,
  DialogActions,
  InputAdornment,
  FormHelperText
} from '@mui/material';
import { motion } from 'framer-motion';
import { Receipt, Payment, Save, Cancel } from '@mui/icons-material';

import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';

const RequestFormModal = ({ property, onSave, onCancel, open, onClose }) => {
  const { user } = useAuth();
  const [formData, setFormData] = useState({
    amount_paid: '',
    receipt_number: '',
    date_issued: '',
    place_issued: '',
    prepared_by: '',
    payment_type: '',
    purpose: '',
    client_name: '',
    client_address: '',
    contact_number: '',
    remarks: ''
  });

  const [toast, setToast] = useState({ open: false, message: '', severity: 'success' });
  const [loading, setLoading] = useState(false);
  const [validationErrors, setValidationErrors] = useState(new Set());

  // Payment type options
  const paymentTypeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'check', label: 'Check' },
    { value: 'bank_transfer', label: 'Bank Transfer' },
    { value: 'gcash', label: 'GCash' },
    { value: 'other', label: 'Other' }
  ];

  // Purpose options
  const purposeOptions = [
    { value: 'record_verification', label: 'Record Verification' },
    { value: 'tax_declaration', label: 'Tax Declaration' },
    { value: 'property_assessment', label: 'Property Assessment' },
    { value: 'certification', label: 'Certification' },
    { value: 'other', label: 'Other' }
  ];

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
        payment_type: '',
        purpose: '',
        client_name: '',
        client_address: '',
        contact_number: '',
        remarks: ''
      });
    }
  }, [open, user]);

  // Validation function
  const validateForm = () => {
    const missingFields = [];
    const errorFields = new Set();

    if (!formData.amount_paid || parseFloat(formData.amount_paid) <= 0) {
      missingFields.push('Amount Paid');
      errorFields.add('amount_paid');
    }

    if (!formData.receipt_number?.trim()) {
      missingFields.push('Receipt Number');
      errorFields.add('receipt_number');
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

    if (!formData.payment_type) {
      missingFields.push('Payment Type');
      errorFields.add('payment_type');
    }

    if (!formData.purpose) {
      missingFields.push('Purpose');
      errorFields.add('purpose');
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
      // Prepare the data for saving
      const requestData = {
        ...formData,
        property_id: property?.id,
        amount_paid: parseFloat(formData.amount_paid),
        created_at: new Date().toISOString()
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
          ...response.data,
          // Add property information for the receipt display
          tax_declaration_number: property?.tax_declaration_number,
          declarant_last_name: property?.declarant_last_name,
          declarant_first_name: property?.declarant_first_name,
          declarant_middle_initial: property?.declarant_middle_initial,
          business: property?.business,
          location: property?.location,
          assessed_value: property?.assessed_value
        };
        onSave(receiptData);
      }

      // Close the modal after a short delay
      setTimeout(() => {
        handleClose();
      }, 1500);

    } catch (error) {
      console.error('Error saving request form:', error);
      setToast({
        open: true,
        message: error.response?.data?.message || 'Error saving request form',
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
      payment_type: '',
      purpose: '',
      client_name: '',
      client_address: '',
      contact_number: '',
      remarks: ''
    });
    setValidationErrors(new Set());
    if (onClose) onClose();
  };

  return (
    <>
      <Dialog
        open={open}
        onClose={handleClose}
        maxWidth="md"
        fullWidth
        PaperProps={{
          component: motion.div,
          initial: { opacity: 0, y: 20 },
          animate: { opacity: 1, y: 0 },
          transition: { duration: 0.3 }
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

        <DialogContent sx={{ p: 3 }}>
          <form onSubmit={handleSubmit}>
            <Grid container spacing={3}>
              {/* Client Information Section */}
              <Grid item xs={12}>
                <Card variant="outlined">
                  <CardContent>
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
                    
                    <Grid container spacing={2}>
                                             <Grid item xs={12} md={6}>
                         <TextField
                           fullWidth
                           label="Client Name *"
                           value={formData.client_name}
                           onChange={handleChange('client_name')}
                                                       error={validationErrors.has('client_name')}
                         />
                       </Grid>

                      <Grid item xs={12} md={6}>
                        <TextField
                          fullWidth
                          label="Contact Number"
                          value={formData.contact_number}
                          onChange={handleChange('contact_number')}
                        />
                      </Grid>

                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          label="Client Address"
                          value={formData.client_address}
                          onChange={handleChange('client_address')}
                          multiline
                          rows={2}
                        />
                      </Grid>

                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          label="Remarks"
                          value={formData.remarks}
                          onChange={handleChange('remarks')}
                          multiline
                          rows={3}
                          placeholder="Additional notes or special instructions..."
                        />
                      </Grid>
                    </Grid>
                  </CardContent>
                </Card>
              </Grid>

              {/* Payment Information Section */}
              <Grid item xs={12}>
                <Card variant="outlined">
                  <CardContent>
                    <Typography variant="h6" gutterBottom sx={{ 
                      display: 'flex', 
                      alignItems: 'center', 
                      gap: 1,
                      color: 'primary.main' 
                    }}>
                      <Payment />
                      Payment Information
                    </Typography>
                    <Divider sx={{ mb: 2 }} />
                    
                    <Grid container spacing={2}>
                        <Grid item xs={12} md={6}>
                          <TextField
                            fullWidth
                            label="Amount Paid *"
                            type="number"
                            value={formData.amount_paid}
                            onChange={handleChange('amount_paid')}
                            InputProps={{
                                startAdornment: <InputAdornment position="start">₱</InputAdornment>,
                            }}
                            inputProps={{ min: 0, step: 0.01 }}
                            error={validationErrors.has('amount_paid')}
                         />
                       </Grid>
                        <Grid item xs={12} md={6}>
                          <TextField
                            fullWidth
                            label="Receipt Number *"
                            value={formData.receipt_number}
                            onChange={handleChange('receipt_number')}
                            error={validationErrors.has('receipt_number')}
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
                         />
                       </Grid>
                        <Grid item xs={12} md={6}>
                          <TextField
                            fullWidth
                            label="Place Issued *"
                            value={formData.place_issued}
                            onChange={handleChange('place_issued')}
                            error={validationErrors.has('place_issued')}
                         />
                       </Grid>
                        <Grid item xs={12} md={6}>
                          <TextField
                            fullWidth
                            label="Prepared By *"
                            value={formData.prepared_by}
                            onChange={handleChange('prepared_by')}
                            InputProps={{ readOnly: true }}
                            variant="filled"
                            error={validationErrors.has('prepared_by')}
                         />
                       </Grid>
                        <Grid item xs={12} md={6}>
                         <FormControl fullWidth error={validationErrors.has('payment_type')}>
                           <InputLabel>Payment Type *</InputLabel>
                           <Select
                             value={formData.payment_type}
                             onChange={handleChange('payment_type')}
                             label="Payment Type *">
                             {paymentTypeOptions.map((option) => (
                               <MenuItem key={option.value} value={option.value}>
                                 {option.label}
                               </MenuItem>
                             ))}
                           </Select>
                         </FormControl>
                       </Grid>
                        <Grid item xs={12}>
                         <FormControl fullWidth error={validationErrors.has('purpose')}>
                           <InputLabel>Purpose *</InputLabel>
                           <Select
                             value={formData.purpose}
                             onChange={handleChange('purpose')}
                             label="Purpose *">
                             {purposeOptions.map((option) => (
                               <MenuItem key={option.value} value={option.value}>
                                 {option.label}
                               </MenuItem>
                             ))}
                           </Select>
                         </FormControl>
                       </Grid>
                    </Grid>
                  </CardContent>
                </Card>
              </Grid>

              {/* Property Information (if available) */}
              {property && (
                <Grid item xs={12}>
                  <Card variant="outlined">
                    <CardContent>
                      <Typography variant="h6" gutterBottom sx={{ color: 'primary.main' }}>
                        Property Information
                      </Typography>
                      <Divider sx={{ mb: 2 }} />
                      
                      <Grid container spacing={2}>
                        <Grid item xs={12} md={6}>
                          <TextField
                            fullWidth
                            label="Tax Declaration Number"
                            value={property.tax_declaration_number || ''}
                            InputProps={{ readOnly: true }}
                            variant="filled"
                          />
                        </Grid>
                        <Grid item xs={12} md={6}>
                          <TextField
                            fullWidth
                            label="Property Location"
                            value={property.location || ''}
                            InputProps={{ readOnly: true }}
                            variant="filled"
                          />
                        </Grid>
                        <Grid item xs={12} md={6}>
                          <TextField
                            fullWidth
                            label="Owner / Business Name"
                                                         value={(() => {
                               const hasNames = !!(property.declarant_last_name || property.declarant_first_name);
                               const declarant = hasNames
                                 ? `${property.declarant_last_name || ''}${hasNames && property.declarant_first_name ? ', ' : ''}${property.declarant_first_name || ''}${property.declarant_middle_initial ? ` ${property.declarant_middle_initial}.` : ''}`
                                 : '';
                               const business = property.business ? String(property.business).replace(/,\s*/g, ' ') : '';
                               if (declarant && business) return `${declarant} / ${business}`;
                               return declarant || business || '';
                             })()}
                            InputProps={{ readOnly: true }}
                            variant="filled"
                          />
                        </Grid>
                        <Grid item xs={12} md={6}>
                          <TextField
                            fullWidth
                            label="Assessed Value"
                            value={property.assessed_value ? `₱${property.assessed_value.toLocaleString()}` : '₱0.00'}
                            InputProps={{ readOnly: true }}
                            variant="filled"
                          />
                        </Grid>
                      </Grid>
                    </CardContent>
                  </Card>
                </Grid>
              )}
            </Grid>
          </form>
        </DialogContent>

        <DialogActions sx={{ p: 3, pt: 0 }}>
          <Button
            onClick={handleClose}
            startIcon={<Cancel />}
            variant="outlined"
            disabled={loading}
          >
            Cancel
          </Button>
                     <Button
             onClick={handleSubmit}
             startIcon={<Save />}
             variant="contained"
             disabled={loading}
           >
             {loading ? 'Saving...' : 'Save Request'}
           </Button>
        </DialogActions>
      </Dialog>

      {/* Toast Notification */}
      <Snackbar
        open={toast.open}
        autoHideDuration={6000}
        onClose={() => setToast(prev => ({ ...prev, open: false }))}
        anchorOrigin={{ vertical: 'top', horizontal: 'right' }}
      >
        <Alert
          onClose={() => setToast(prev => ({ ...prev, open: false }))}
          severity={toast.severity}
          sx={{ width: '100%' }}
        >
          {toast.message}
        </Alert>
      </Snackbar>
    </>
  );
};

export default RequestFormModal;
