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
  CardContent
} from '@mui/material';
import { motion } from 'framer-motion';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';

import { apiService } from '../../utils/api';

const PropertyFormModal = ({ property, onSave, onCancel, open }) => {
  const [formData, setFormData] = useState({
    tax_declaration_number: '',
    owner_name: '',
    owner_address: '',
    owner_contact: '',
    property_type: '',
    property_address: '',
    location: '',
    land_area: '',
    land_area_unit: 'sqm',
    building_area: '',
    building_area_unit: 'sqm',
    assessed_value: '',
    market_value: '',
    status: 'active',
    remarks: ''
  });

  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);
  const [submitError, setSubmitError] = useState('');

  useEffect(() => {
    if (property) {
      setFormData({
        tax_declaration_number: property.tax_declaration_number || '',
        owner_name: property.owner_name || '',
        owner_address: property.owner_address || '',
        owner_contact: property.owner_contact || '',
        property_type: property.property_type || '',
        property_address: property.property_address || '',
        location: property.location || '',
        land_area: property.land_area || '',
        land_area_unit: property.land_area_unit || 'sqm',
        building_area: property.building_area || '',
        building_area_unit: property.building_area_unit || 'sqm',
        assessed_value: property.assessed_value || '',
        market_value: property.market_value || '',
        status: property.status || 'active',
        remarks: property.remarks || ''
      });
    } else {
      // Reset form for new property
      setFormData({
        tax_declaration_number: '',
        owner_name: '',
        owner_address: '',
        owner_contact: '',
        property_type: '',
        property_address: '',
        location: '',
        land_area: '',
        land_area_unit: 'sqm',
        building_area: '',
        building_area_unit: 'sqm',
        assessed_value: '',
        market_value: '',
        status: 'active',
        remarks: ''
      });
    }
    setErrors({});
    setSubmitError('');
  }, [property]);

  const handleInputChange = (field, value) => {
    setFormData(prev => ({ ...prev, [field]: value }));
    
    // Clear error for this field
    if (errors[field]) {
      setErrors(prev => ({ ...prev, [field]: '' }));
    }
  };

  const validateForm = () => {
    const newErrors = {};

    if (!formData.tax_declaration_number.trim()) {
      newErrors.tax_declaration_number = 'Tax declaration number is required';
    }

    if (!formData.owner_name.trim()) {
      newErrors.owner_name = 'Owner name is required';
    }

    if (!formData.property_type.trim()) {
      newErrors.property_type = 'Property type is required';
    }

    if (!formData.location.trim()) {
      newErrors.location = 'Location is required';
    }

    if (formData.land_area && isNaN(formData.land_area)) {
      newErrors.land_area = 'Land area must be a valid number';
    }

    if (formData.building_area && isNaN(formData.building_area)) {
      newErrors.building_area = 'Building area must be a valid number';
    }

    if (formData.assessed_value && isNaN(formData.assessed_value)) {
      newErrors.assessed_value = 'Assessed value must be a valid number';
    }

    if (formData.market_value && isNaN(formData.market_value)) {
      newErrors.market_value = 'Market value must be a valid number';
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    if (!validateForm()) {
      return;
    }

    setLoading(true);
    setSubmitError('');

    try {
      // Map form fields to API expected fields
      const apiData = {
        tax_declaration_number: formData.tax_declaration_number,
        owner_name: formData.owner_name,
        owner_address: formData.owner_address,
        property_location: formData.location, // Map location to property_location
        property_type: formData.property_type,
        land_area: formData.land_area || 0,
        building_area: formData.building_area || 0,
        assessed_value: formData.assessed_value || 0,
        market_value: formData.market_value || 0,
        status: formData.status || 'active'
      };

      console.log('Sending to API:', apiData);
      console.log('Form data:', formData);
      
      let response;
      
      if (property) {
        // Update existing property
        console.log('Updating property:', property.id);
        response = await apiService.updateProperty(property.id, apiData);
      } else {
        // Create new property
        console.log('Creating new property');
        response = await apiService.createProperty(apiData);
      }

      console.log('API response:', response);
      onSave(response.data || response);
    } catch (err) {
      console.error('Property save error:', err);
      console.error('Error response:', err.response);
      
      let errorMessage = 'Failed to save property';
      if (err.response?.data?.message) {
        errorMessage = err.response.data.message;
      } else if (err.response?.data?.error) {
        errorMessage = err.response.data.error;
      } else if (err.message) {
        errorMessage = err.message;
      }
      
      setSubmitError(errorMessage);
    } finally {
      setLoading(false);
    }
  };

  const propertyTypes = [
    'Residential',
    'Commercial',
    'Industrial',
    'Agricultural',
    'Mixed Use',
    'Vacant Lot',
    'Other'
  ];

  const areaUnits = ['sqm', 'hectares', 'acres'];
  const statusOptions = ['active', 'inactive', 'archived', 'pending'];

  if (!open) return null;

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      <form onSubmit={handleSubmit}>
        {submitError && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {submitError}
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
                  label="Tax Declaration Number *"
                  value={formData.tax_declaration_number}
                  onChange={(e) => handleInputChange('tax_declaration_number', e.target.value)}
                  error={!!errors.tax_declaration_number}
                  helperText={errors.tax_declaration_number}
                  required
                />
              </Grid>
              
              <Grid item xs={12} md={6}>
                <FormControl fullWidth required>
                  <InputLabel>Property Type *</InputLabel>
                  <Select
                    value={formData.property_type}
                    label="Property Type *"
                    onChange={(e) => handleInputChange('property_type', e.target.value)}
                    error={!!errors.property_type}
                  >
                    {propertyTypes.map(type => (
                      <MenuItem key={type} value={type}>{type}</MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>

              <Grid item xs={12} md={6}>
                <FormControl fullWidth>
                  <InputLabel>Status</InputLabel>
                  <Select
                    value={formData.status}
                    label="Status"
                    onChange={(e) => handleInputChange('status', e.target.value)}
                  >
                    {statusOptions.map(status => (
                      <MenuItem key={status} value={status}>
                        {status.charAt(0).toUpperCase() + status.slice(1)}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Location *"
                  value={formData.location}
                  onChange={(e) => handleInputChange('location', e.target.value)}
                  error={!!errors.location}
                  helperText={errors.location}
                  placeholder="City/Municipality"
                  required
                />
              </Grid>
            </Grid>
          </CardContent>
        </Card>

        {/* Owner Information */}
        <Card sx={{ mb: 3 }}>
          <CardContent>
            <Typography variant="h6" gutterBottom>
              Owner Information
            </Typography>
            <Grid container spacing={2}>
              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Owner Name *"
                  value={formData.owner_name}
                  onChange={(e) => handleInputChange('owner_name', e.target.value)}
                  error={!!errors.owner_name}
                  helperText={errors.owner_name}
                  required
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Owner Contact"
                  value={formData.owner_contact}
                  onChange={(e) => handleInputChange('owner_contact', e.target.value)}
                  placeholder="Phone/Email"
                />
              </Grid>

              <Grid item xs={12}>
                <TextField
                  fullWidth
                  label="Owner Address"
                  value={formData.owner_address}
                  onChange={(e) => handleInputChange('owner_address', e.target.value)}
                  multiline
                  rows={2}
                  placeholder="Complete address"
                />
              </Grid>
            </Grid>
          </CardContent>
        </Card>

        {/* Property Details */}
        <Card sx={{ mb: 3 }}>
          <CardContent>
            <Typography variant="h6" gutterBottom>
              Property Details
            </Typography>
            <Grid container spacing={2}>
              <Grid item xs={12}>
                <TextField
                  fullWidth
                  label="Property Address"
                  value={formData.property_address}
                  onChange={(e) => handleInputChange('property_address', e.target.value)}
                  multiline
                  rows={2}
                  placeholder="Complete property address"
                />
              </Grid>

              <Grid item xs={12} md={4}>
                <TextField
                  fullWidth
                  label="Land Area"
                  value={formData.land_area}
                  onChange={(e) => handleInputChange('land_area', e.target.value)}
                  error={!!errors.land_area}
                  helperText={errors.land_area}
                  type="number"
                  inputProps={{ min: 0, step: 0.01 }}
                />
              </Grid>

              <Grid item xs={12} md={2}>
                <FormControl fullWidth>
                  <InputLabel>Unit</InputLabel>
                  <Select
                    value={formData.land_area_unit}
                    label="Unit"
                    onChange={(e) => handleInputChange('land_area_unit', e.target.value)}
                  >
                    {areaUnits.map(unit => (
                      <MenuItem key={unit} value={unit}>{unit}</MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>

              <Grid item xs={12} md={4}>
                <TextField
                  fullWidth
                  label="Building Area"
                  value={formData.building_area}
                  onChange={(e) => handleInputChange('building_area', e.target.value)}
                  error={!!errors.building_area}
                  helperText={errors.building_area}
                  type="number"
                  inputProps={{ min: 0, step: 0.01 }}
                />
              </Grid>

              <Grid item xs={12} md={2}>
                <FormControl fullWidth>
                  <InputLabel>Unit</InputLabel>
                  <Select
                    value={formData.building_area_unit}
                    label="Unit"
                    onChange={(e) => handleInputChange('building_area_unit', e.target.value)}
                  >
                    {areaUnits.map(unit => (
                      <MenuItem key={unit} value={unit}>{unit}</MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>
            </Grid>
          </CardContent>
        </Card>

        {/* Valuation */}
        <Card sx={{ mb: 3 }}>
          <CardContent>
            <Typography variant="h6" gutterBottom>
              Valuation
            </Typography>
            <Grid container spacing={2}>
              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Assessed Value (₱)"
                  value={formData.assessed_value}
                  onChange={(e) => handleInputChange('assessed_value', e.target.value)}
                  error={!!errors.assessed_value}
                  helperText={errors.assessed_value}
                  type="number"
                  inputProps={{ min: 0, step: 0.01 }}
                  placeholder="0.00"
                />
              </Grid>

              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  label="Market Value (₱)"
                  value={formData.market_value}
                  onChange={(e) => handleInputChange('market_value', e.target.value)}
                  error={!!errors.market_value}
                  helperText={errors.market_value}
                  type="number"
                  inputProps={{ min: 0, step: 0.01 }}
                  placeholder="0.00"
                />
              </Grid>
            </Grid>
          </CardContent>
        </Card>

        {/* Remarks */}
        <Card sx={{ mb: 3 }}>
          <CardContent>
            <Typography variant="h6" gutterBottom>
              Additional Information
            </Typography>
            <TextField
              fullWidth
              label="Remarks"
              value={formData.remarks}
              onChange={(e) => handleInputChange('remarks', e.target.value)}
              multiline
              rows={3}
              placeholder="Additional notes or remarks about the property"
            />
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
