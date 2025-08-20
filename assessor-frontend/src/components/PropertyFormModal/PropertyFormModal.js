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
  Snackbar
} from '@mui/material';
import { motion } from 'framer-motion';
import { CloudUpload } from '@mui/icons-material';

import { apiService } from '../../utils/api';

const PropertyFormModal = ({ property, onSave, onCancel, open }) => {
  const [formData, setFormData] = useState({
    tax_declaration_number: '',
    previous_tax_declaration_number: '',
    declarant_last_name: '',
    declarant_first_name: '',
    declarant_middle_initial: '',
    business_name: '',
    location: '',
    lot_number: '',
    unique_lot_number_identified: '',
    area_hectare: '',
    title_number: '',
    assessed_value: '',
    effectivity_date: '',
    pin: '',
    address: '',
    assessment_date: '',
    kind_of_property: '',
    gen_class: '',
    memoranda: '',
    supporting_documents: []
  });

  const [errors, setErrors] = useState([]);
  const [toast, setToast] = useState({ open: false, message: '', severity: 'success' });
  const [loading, setLoading] = useState(false);
  const [propertyTypeOptions, setPropertyTypeOptions] = useState([]);
  const [generalClassOptions, setGeneralClassOptions] = useState([]);
  const [locationOptions, setLocationOptions] = useState([]);

  useEffect(() => {
    if (property) {
      setFormData({
        tax_declaration_number: property.tax_declaration_number || '',
        previous_tax_declaration_number: property.previous_tax_declaration_number || '',
        declarant_last_name: property.declarant_last_name || '',
        declarant_first_name: property.declarant_first_name || '',
        declarant_middle_initial: property.declarant_middle_initial || '',
        business_name: property.business_name || '',
        location: property.location || '',
        lot_number: property.lot_number || '',
        unique_lot_number_identified: property.unique_lot_number_identified || '',
        area_hectare: property.area_hectare || '',
        title_number: property.title_number || '',
        assessed_value: property.assessed_value || '',
        effectivity_date: property.effectivity_date ? String(new Date(property.effectivity_date).getFullYear()) : '',
        pin: property.pin || '',
        address: property.address || '',
        assessment_date: property.assessment_date ? property.assessment_date.slice(0, 10) : '',
        kind_of_property: property.kind_of_property || '',
        gen_class: property.gen_class || '',
        memoranda: property.memoranda || '',
        supporting_documents: Array.isArray(property.supporting_documents)
          ? property.supporting_documents
          : (typeof property.supporting_documents === 'string' && property.supporting_documents.trim() !== ''
            ? property.supporting_documents.split(',').map(s => s.trim())
            : [])
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
         unique_lot_number_identified: '',
         area_hectare: '',
         title_number: '',
         assessed_value: '',
         effectivity_date: '',
         pin: '',
         address: '',
         assessment_date: '',
         kind_of_property: '',
         gen_class: '',
         memoranda: '',
         supporting_documents: []
       });
    }
    setErrors([]);
  }, [property]);

  useEffect(() => {
    const loadOptions = async () => {
      try {
        const [typesRes, classesRes, locationsRes] = await Promise.all([
          apiService.getPropertyTypes(),
          apiService.getGeneralClasses(),
          apiService.getLocations()
        ]);
        const types = (Array.isArray(typesRes?.items) ? typesRes.items : []).filter(i => i.status === 'active');
        const classes = (Array.isArray(classesRes?.items) ? classesRes.items : []).filter(i => i.status === 'active');
        const locations = (Array.isArray(locationsRes?.items) ? locationsRes.items : []).filter(i => i.status === 'active');
        setPropertyTypeOptions(types);
        setGeneralClassOptions(classes);
        setLocationOptions(locations);
      } catch (e) {
        // fallback to defaults if API fails
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
      }
    };
    loadOptions();
  }, []);

  useEffect(() => {
    if (!property) {
      setFormData(prev => ({
        ...prev,
        kind_of_property: prev.kind_of_property || (propertyTypeOptions[0]?.code || ''),
        gen_class: prev.gen_class || (generalClassOptions[0]?.code || ''),
        location: prev.location || (locationOptions[0]?.name || '')
      }));
    }
  }, [property, propertyTypeOptions, generalClassOptions, locationOptions]);

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

  const handleInputChange = (field, value) => {
    const uppercaseFields = new Set([
      'tax_declaration_number',
      'previous_tax_declaration_number',
      'declarant_last_name',
      'declarant_first_name',
      'declarant_middle_initial',
      'business_name',
      'location',
      'lot_number',
      'unique_lot_number_identified',
      'title_number',
      'pin',
      'address',
      'kind_of_property',
      'gen_class',
      'memoranda'
    ]);
    const nextValue = (typeof value === 'string' && uppercaseFields.has(field)) ? value.toUpperCase() : value;
    setFormData(prev => ({ ...prev, [field]: nextValue }));
    
    // Clear error for this field
    if (errors.includes(field)) {
      setErrors(prev => prev.filter(err => err !== field));
    }
  };

  const validateForm = () => {
    const errors = [];

    if (!formData.tax_declaration_number.trim()) {
      errors.push('Tax Declaration Number is required');
    }
    // Declarant name is optional
    if (!formData.location.trim()) {
      errors.push('Location is required');
    }
    if (!formData.kind_of_property.trim()) {
      errors.push('Kind of Property is required');
    }
    
    return errors;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    const validationErrors = validateForm();
    if (validationErrors.length > 0) {
      setErrors(validationErrors);
      return;
    }

    setLoading(true);
    setErrors([]);

    try {
      const supportingDocsString = Array.isArray(formData.supporting_documents)
        ? formData.supporting_documents
            .map((doc) => (typeof doc === 'string' ? doc : (doc && doc.name) || ''))
            .filter(Boolean)
            .join(', ')
        : (formData.supporting_documents || '');

      const submitData = {
        tax_declaration_number: formData.tax_declaration_number,
        previous_tax_declaration_number: formData.previous_tax_declaration_number,
        declarant_last_name: formData.declarant_last_name,
        declarant_first_name: formData.declarant_first_name,
        declarant_middle_initial: formData.declarant_middle_initial,
        business_name: formData.business_name,
        location: formData.location,
        lot_number: formData.lot_number,
        unique_lot_number_identified: formData.unique_lot_number_identified,
        area_hectare: formData.area_hectare === '' ? '' : Number(formData.area_hectare),
        title_number: formData.title_number,
        assessed_value: formData.assessed_value === '' ? '' : Number(formData.assessed_value),
        effectivity_date: formData.effectivity_date,
        pin: formData.pin,
        address: formData.address,
        assessment_date: formData.assessment_date,
        kind_of_property: formData.kind_of_property,
        gen_class: formData.gen_class,
        memoranda: formData.memoranda,
        supporting_documents: supportingDocsString
      };
      
      if (property) {
        await apiService.updateProperty(property.id, submitData);
        onSave('Property updated successfully');
        setToast({ open: true, message: 'Property updated successfully', severity: 'success' });
      } else {
        await apiService.createProperty(submitData);
        onSave('Property created successfully');
        setToast({ open: true, message: 'Property created successfully', severity: 'success' });
      }
      
      onCancel();
    } catch (error) {
      console.error('Error saving property:', error);
      const msg = error.response?.data?.message || 'Error saving property';
      setErrors([msg]);
      setToast({ open: true, message: msg, severity: 'error' });
    } finally {
      setLoading(false);
    }
  };

  const propertyTypes = propertyTypeOptions.map(o => o.code);

  if (!open) return null;

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      <Snackbar
        open={toast.open}
        autoHideDuration={3000}
        onClose={() => setToast(prev => ({ ...prev, open: false }))}
        anchorOrigin={{ vertical: 'top', horizontal: 'right' }}
      >
        <Alert onClose={() => setToast(prev => ({ ...prev, open: false }))} severity={toast.severity} sx={{ width: '100%' }}>
          {toast.message}
        </Alert>
      </Snackbar>
      <form onSubmit={handleSubmit}>
        {/* Error Display */}
        {errors.length > 0 && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {errors.map((error, index) => (
              <div key={index}>{error}</div>
            ))}
          </Alert>
        )}

        {/* Basic Information */}
        <Card sx={{ mb: 3 }}>
          <CardContent>
            <Typography variant="h6" gutterBottom>
              Basic Information
            </Typography>
            <Grid container spacing={2}>
              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Tax Declaration Number"
                  value={formData.tax_declaration_number}
                  onChange={(e) => handleInputChange('tax_declaration_number', e.target.value)}
                  error={errors.includes('tax_declaration_number')}
                  helperText={errors.includes('tax_declaration_number') ? errors.find(err => err === 'tax_declaration_number') : ''}
                  required
                  inputProps={{ style: { textTransform: 'uppercase' }, tabIndex: 1 }}
                />
              </Grid>
              
              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Previous Tax Declaration Number"
                  value={formData.previous_tax_declaration_number}
                  onChange={(e) => handleInputChange('previous_tax_declaration_number', e.target.value)}
                  helperText="Optional: Enter the previous tax declaration number to create a historical link"
                  inputProps={{ style: { textTransform: 'uppercase' }, tabIndex: 2 }}
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Declarant Last Name"
                  value={formData.declarant_last_name}
                  onChange={(e) => handleInputChange('declarant_last_name', e.target.value)}
                  error={errors.includes('declarant_last_name')}
                  helperText={errors.includes('declarant_last_name') ? errors.find(err => err === 'declarant_last_name') : ''}
                  // required
                  inputProps={{ style: { textTransform: 'uppercase' }, tabIndex: 3 }}
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Title Number"
                  value={formData.title_number}
                  onChange={(e) => handleInputChange('title_number', e.target.value)}
                  inputProps={{ style: { textTransform: 'uppercase' }, tabIndex: 10 }}
                />
              </Grid>
              
              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Declarant First Name"
                  value={formData.declarant_first_name}
                  onChange={(e) => handleInputChange('declarant_first_name', e.target.value)}
                  error={errors.includes('declarant_first_name')}
                  helperText={errors.includes('declarant_first_name') ? errors.find(err => err === 'declarant_first_name') : ''}
                  // required
                  inputProps={{ style: { textTransform: 'uppercase' }, tabIndex: 4}}
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Assessed Value (₱)"
                  value={formData.assessed_value}
                  onChange={(e) => handleInputChange('assessed_value', e.target.value)}
                  error={errors.includes('assessed_value')}
                  helperText={errors.includes('assessed_value') ? errors.find(err => err === 'assessed_value') : ''}
                  type="number"
                  inputProps={{ min: 0, step: 0.01, tabIndex: 11 }}
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
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Declarant Middle Initial"
                  value={formData.declarant_middle_initial}
                  onChange={(e) => handleInputChange('declarant_middle_initial', String(e.target.value || '').replace(/\s/g, '').slice(0, 1))}
                  inputProps={{ style: { textTransform: 'uppercase' }, maxLength: 1, tabIndex: 5 }}
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Effectivity Year"
                  value={formData.effectivity_date}
                  onChange={(e) => handleInputChange('effectivity_date', e.target.value)}
                  type="number"
                  inputProps={{ min: 1800, max: 2100, tabIndex: 12 }}
                  placeholder="YYYY"
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Business Name"
                  InputLabelProps={{ sx: { color: 'primary.main' } }}
                  value={formData.business_name}
                  onChange={(e) => handleInputChange('business_name', e.target.value)}
                  inputProps={{ sx: { color: 'primary.main' }, style: { textTransform: 'uppercase' }, tabIndex: 6 }}
                  placeholder="Enter business name (optional)"
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Assessment Date"
                  value={formData.assessment_date}
                  onChange={(e) => handleInputChange('assessment_date', e.target.value)}
                  type="date"
                  InputLabelProps={{
                    shrink: true,
                  }}
                  inputProps={{ tabIndex: 13 }}
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <FormControl fullWidth required>
                  <InputLabel>Location</InputLabel>
                  <Select
                    value={formData.location}
                    label="Location"
                    onChange={(e) => handleInputChange('location', e.target.value)}
                    error={errors.includes('location')}
                    inputProps={{ tabIndex: 7 }}
                  >
                    {locationOptions.map(loc => (
                      <MenuItem key={loc.code} value={loc.name}>{loc.name}</MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="PIN"
                  value={formData.pin}
                  onChange={(e) => handleInputChange('pin', e.target.value)}
                  inputProps={{ style: { textTransform: 'uppercase' }, tabIndex: 14 }}
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Lot Number"
                  value={formData.lot_number}
                  onChange={(e) => handleInputChange('lot_number', e.target.value)}
                  inputProps={{ style: { textTransform: 'uppercase' }, tabIndex: 8 }}
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Unique Lot Number Identified"
                  value={formData.unique_lot_number_identified}
                  onChange={(e) => handleInputChange('unique_lot_number_identified', e.target.value)}
                  inputProps={{ style: { textTransform: 'uppercase' }, tabIndex: 15 }}
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Area (Hectares)"
                  value={formData.area_hectare}
                  onChange={(e) => handleInputChange('area_hectare', e.target.value)}
                  error={errors.includes('area_hectare')}
                  helperText={errors.includes('area_hectare') ? errors.find(err => err === 'area_hectare') : ''}
                  type="number"
                  inputProps={{ min: 0, step: 0.0001, tabIndex: 9 }}
                  placeholder="0.0000"
                  onBlur={() => {
                    const v = formData.area_hectare;
                    if (v === '' || v === null || v === undefined) return;
                    const n = Number(v);
                    if (!isNaN(n)) {
                      handleInputChange('area_hectare', n.toFixed(4));
                    }
                  }}
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Address"
                  value={formData.address}
                  onChange={(e) => handleInputChange('address', e.target.value)}
                  placeholder="Complete address"
                  inputProps={{ style: { textTransform: 'uppercase' }, tabIndex: 16 }}
                />
              </Grid>
            </Grid>
          </CardContent>
        </Card>

        {/* Kind of Property */}
        <Card sx={{ mb: 3 }}>
          <CardContent>
            <Typography variant="h6" gutterBottom>
              Kind of Property
            </Typography>
            <Grid container spacing={2}>
              <Grid item xs={12} md={6}>
                <FormControl fullWidth required>
                  <InputLabel>Kind of Property</InputLabel>
                  <Select
                    value={formData.kind_of_property}
                    label="Kind of Property"
                    onChange={(e) => handleInputChange('kind_of_property', e.target.value)}
                    error={errors.includes('kind_of_property')}
                    inputProps={{ tabIndex: 17 }}
                  >
                    {propertyTypeOptions.map(pt => (
                      <MenuItem key={pt.code} value={pt.code}>{pt.name}</MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>

              <Grid item xs={12} md={6}>
                <FormControl fullWidth>
                  <InputLabel>General Class</InputLabel>
                  <Select
                    value={formData.gen_class}
                    label="General Class"
                    onChange={(e) => handleInputChange('gen_class', e.target.value)}
                    inputProps={{ tabIndex: 18 }}
                  >
                    {generalClassOptions.map(gc => (
                      <MenuItem key={gc.code} value={gc.code}>{gc.name}</MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>
            </Grid>
          </CardContent>
        </Card>

        {/* Supporting Documents */}
        <Card sx={{ mb: 3 }}>
          <CardContent>
            <Typography variant="h6" gutterBottom>
              Supporting Documents
            </Typography>
            <Grid container spacing={2}>
              <Grid item xs={12}>
                <TextField
                  fullWidth
                  label="Memoranda"
                  value={formData.memoranda}
                  onChange={(e) => handleInputChange('memoranda', e.target.value)}
                  placeholder="Additional notes or memoranda"
                  multiline
                  rows={3}
                  inputProps={{ tabIndex: 19 }}
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
                  }}
                  inputProps={{ tabIndex: 20 }}
                />
                <label htmlFor="supporting-documents-upload">
                  <Button
                    variant="outlined"
                    component="span"
                    startIcon={<CloudUpload />}
                  fullWidth
                    sx={{ 
                      height: 56, 
                      borderStyle: 'dashed',
                      borderWidth: 2,
                      '&:hover': {
                        borderStyle: 'solid'
                      }
                    }}
                  >
                    {formData.supporting_documents && formData.supporting_documents.length > 0 
                      ? `${formData.supporting_documents.length} file(s) selected`
                      : 'Upload Supporting Documents'
                    }
                  </Button>
                </label>
                {Array.isArray(formData.supporting_documents) && formData.supporting_documents.length > 0 && (
                  <Box sx={{ mt: 1 }}>
                    <Typography variant="caption" color="text.secondary">
                      Selected files:
                    </Typography>
                    {formData.supporting_documents.map((file, index) => (
                      <Typography key={index} variant="body2" sx={{ ml: 1 }}>
                        • {typeof file === 'string' ? file : (file && file.name) ? file.name : String(file)}
                      </Typography>
                    ))}
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
            disabled={loading}
          >
            {loading ? 'Saving...' : (property ? 'Update Property' : 'Create Property')}
          </Button>
        </Box>
      </form>
    </Box>
  );
};

export default PropertyFormModal;
