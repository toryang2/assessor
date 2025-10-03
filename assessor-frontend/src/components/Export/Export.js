import React, { useState, useEffect } from 'react';
import {
  Box,
  Paper,
  Grid,
  Card,
  CardContent,
  Typography,
  Button,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  TextField,
  Chip,
  Alert,
  LinearProgress,
  List,
  ListItem,
  ListItemText,
  ListItemIcon,
  Divider,
  Checkbox,
  FormControlLabel,
  FormGroup,
  Accordion,
  AccordionSummary,
  AccordionDetails,
  Stepper,
  Step,
  StepLabel,
  StepContent,
  IconButton,
  Tooltip,
  Collapse,
  Stack
} from '@mui/material';
import {
  Download as DownloadIcon,
  FileDownload as FileDownloadIcon,
  TableChart as TableChartIcon,
  History as HistoryIcon,
  People as PeopleIcon,
  Description as DescriptionIcon,
  Settings as SettingsIcon,
  Refresh as RefreshIcon,
  ExpandMore as ExpandMoreIcon,
  FilterList as FilterListIcon,
  CheckBox as CheckBoxIcon,
  CheckBoxOutlineBlank as CheckBoxOutlineBlankIcon,
  Info as InfoIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';

import { apiService } from '../../utils/api';

const Export = () => {
  const [exportType, setExportType] = useState('properties');
  const [exportFormat, setExportFormat] = useState('csv');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [exportProgress, setExportProgress] = useState(0);
  const [activeStep, setActiveStep] = useState(0);
  const [showFilters, setShowFilters] = useState(false);
  const [showFieldSelection, setShowFieldSelection] = useState(false);
  
  // Filter states
  const [filters, setFilters] = useState({
    dateFrom: null,
    dateTo: null,
    status: '',
    location: '',
    propertyType: ''
  });
  
  // Field selection states
  const [selectedFields, setSelectedFields] = useState({
    properties: [
      'tax_declaration_number',
      'previous_tax_declaration_number',
      'declarant_name',
      'business',
      'location',
      'lot_number',
      'unique_lot_number_identified',
      'area_hectare',
      'area_sqm',
      'title_number',
      'assessed_value',
      'effectivity_date',
      'pin',
      'address',
      'assessment_date',
      'kind_of_property',
      'gen_class',
      'memoranda',
      'supporting_documents',
      'status',
      'created_by',
      'updated_by',
      'created_at',
      'updated_at'
    ],
    audit: [
      'id',
      'user_id',
      'action',
      'table_name',
      'record_id',
      'old_values',
      'new_values',
      'ip_address',
      'user_agent',
      'created_at'
    ],
    users: [
      'id',
      'username',
      'email',
      'full_name',
      'role',
      'status',
      'last_login',
      'created_at',
      'updated_at'
    ]
  });

  const exportTypes = [
    { value: 'properties', label: 'Properties', icon: <TableChartIcon />, description: 'Export property records with all details' },
    { value: 'audit', label: 'Audit Trail', icon: <HistoryIcon />, description: 'Export system audit logs and activity history' },
    { value: 'users', label: 'Users', icon: <PeopleIcon />, description: 'Export user accounts and access information' }
  ];

  const exportFormats = [
    { value: 'csv', label: 'CSV', description: 'Comma-separated values, compatible with Excel' },
    { value: 'json', label: 'JSON', description: 'JavaScript Object Notation, for data processing' }
  ];

  const fieldOptions = {
    properties: [
      { value: 'tax_declaration_number', label: 'Tax Declaration Number' },
      { value: 'previous_tax_declaration_number', label: 'Previous Tax Declaration Number' },
      { value: 'declarant_name', label: 'Declarant Name' },
      { value: 'business', label: 'Business' },
      { value: 'location', label: 'Location' },
      { value: 'lot_number', label: 'Lot Number' },
      { value: 'unique_lot_number_identified', label: 'Unique Lot Number Identified' },
      { value: 'area_hectare', label: 'Area (Hectare)' },
      { value: 'area_hectare_old', label: 'Area Hectare (Old)' },
      { value: 'area_sqm', label: 'Area (Square Meters)' },
      { value: 'title_number', label: 'Title Number' },
      { value: 'assessed_value', label: 'Assessed Value' },
      { value: 'assessed_value_old', label: 'Assessed Value (Old)' },
      { value: 'effectivity_date', label: 'Effectivity Date' },
      { value: 'pin', label: 'PIN' },
      { value: 'address', label: 'Address' },
      { value: 'assessment_date', label: 'Assessment Date' },
      { value: 'kind_of_property', label: 'Kind of Property' },
      { value: 'gen_class', label: 'General Class' },
      { value: 'memoranda', label: 'Memoranda' },
      { value: 'supporting_documents', label: 'Supporting Documents' },
      { value: 'supporting_documents_old', label: 'Supporting Documents (Old)' },
      { value: 'status', label: 'Status' },
      { value: 'created_by', label: 'Created By' },
      { value: 'updated_by', label: 'Updated By' },
      { value: 'created_at', label: 'Created Date' },
      { value: 'updated_at', label: 'Updated Date' }
    ],
    audit: [
      { value: 'id', label: 'ID' },
      { value: 'user_id', label: 'User ID' },
      { value: 'action', label: 'Action' },
      { value: 'table_name', label: 'Table Name' },
      { value: 'record_id', label: 'Record ID' },
      { value: 'old_values', label: 'Old Values' },
      { value: 'new_values', label: 'New Values' },
      { value: 'ip_address', label: 'IP Address' },
      { value: 'user_agent', label: 'User Agent' },
      { value: 'created_at', label: 'Date & Time' }
    ],
    users: [
      { value: 'id', label: 'ID' },
      { value: 'username', label: 'Username' },
      { value: 'email', label: 'Email' },
      { value: 'full_name', label: 'Full Name' },
      { value: 'role', label: 'Role' },
      { value: 'status', label: 'Status' },
      { value: 'last_login', label: 'Last Login' },
      { value: 'created_at', label: 'Created Date' },
      { value: 'updated_at', label: 'Updated Date' }
    ]
  };

  const handleExportTypeChange = (type) => {
    setExportType(type);
    setFilters({
      dateFrom: null,
      dateTo: null,
      status: '',
      location: '',
      propertyType: ''
    });
    setActiveStep(0);
  };

  const handleNext = () => {
    setActiveStep((prevActiveStep) => prevActiveStep + 1);
  };

  const handleBack = () => {
    setActiveStep((prevActiveStep) => prevActiveStep - 1);
  };

  const handleReset = () => {
    setActiveStep(0);
    setShowFilters(false);
    setShowFieldSelection(false);
  };

  const handleFieldToggle = (field) => {
    setSelectedFields(prev => ({
      ...prev,
      [exportType]: prev[exportType].includes(field)
        ? prev[exportType].filter(f => f !== field)
        : [...prev[exportType], field]
    }));
  };

  const handleSelectAllFields = () => {
    setSelectedFields(prev => ({
      ...prev,
      [exportType]: fieldOptions[exportType].map(f => f.value)
    }));
  };

  const handleDeselectAllFields = () => {
    setSelectedFields(prev => ({
      ...prev,
      [exportType]: []
    }));
  };

  const handleExport = async () => {
    if (!exportType || !exportFormat) {
      setError('Please select both export type and format');
      return;
    }

    setLoading(true);
    setError('');
    setSuccess('');
    setExportProgress(0);

    try {
      const exportConfig = {
        type: exportType,
        format: exportFormat,
        filters: filters,
        fields: selectedFields[exportType],
        // Add cache busting timestamp to prevent browser caching
        _t: Date.now()
      };

      const response = await apiService.exportData(exportConfig);
      
      // Handle the response based on format
      if (exportFormat === 'csv') {
        // Create and download CSV file
        const blob = new Blob([response], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${exportType}_export_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
      } else if (exportFormat === 'json') {
        // Create and download JSON file
        const blob = new Blob([JSON.stringify(response, null, 2)], { type: 'application/json' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${exportType}_export_${new Date().toISOString().split('T')[0]}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
      }

      setSuccess(`Export completed successfully! ${exportType} data has been downloaded.`);
      setExportProgress(100);
    } catch (error) {
      console.error('Export error:', error);
      setError(`Export failed: ${error.message || 'Unknown error occurred'}`);
    } finally {
      setLoading(false);
      // Reset progress after a delay
      setTimeout(() => setExportProgress(0), 2000);
    }
  };

  const clearFilters = () => {
    setFilters({
      dateFrom: null,
      dateTo: null,
      status: '',
      location: '',
      propertyType: ''
    });
  };

  const getFilterFields = () => {
    switch (exportType) {
      case 'properties':
        return (
          <Stack spacing={3}>
            <Grid container spacing={2}>
              <Grid item xs={12} sm={6} md={3}>
                <DatePicker
                  label="From Date"
                  value={filters.dateFrom}
                  onChange={(date) => setFilters(prev => ({ ...prev, dateFrom: date }))}
                  slotProps={{
                    textField: {
                      fullWidth: true,
                      size: 'small'
                    }
                  }}
                />
              </Grid>
              <Grid item xs={12} sm={6} md={3}>
                <DatePicker
                  label="To Date"
                  value={filters.dateTo}
                  onChange={(date) => setFilters(prev => ({ ...prev, dateTo: date }))}
                  slotProps={{
                    textField: {
                      fullWidth: true,
                      size: 'small'
                    }
                  }}
                />
              </Grid>
              <Grid item xs={12} sm={6} md={3}>
                <FormControl fullWidth size="small">
                  <InputLabel>Status</InputLabel>
                  <Select
                    value={filters.status}
                    label="Status"
                    onChange={(e) => setFilters(prev => ({ ...prev, status: e.target.value }))}
                  >
                  <MenuItem value="">All</MenuItem>
                  <MenuItem value="active">Active</MenuItem>
                  <MenuItem value="inactive">Inactive</MenuItem>
                  <MenuItem value="archived">Archived</MenuItem>
                  <MenuItem value="pending">Pending</MenuItem>
                  <MenuItem value="draft">Draft</MenuItem>
                  </Select>
                </FormControl>
              </Grid>
              <Grid item xs={12} sm={6} md={3}>
                <TextField
                  fullWidth
                  size="small"
                  label="Location"
                  value={filters.location}
                  onChange={(e) => setFilters(prev => ({ ...prev, location: e.target.value }))}
                  placeholder="City/Municipality"
                />
              </Grid>
            </Grid>
          </Stack>
        );
      
      case 'audit':
        return (
          <Stack spacing={3}>
            <Grid container spacing={2}>
              <Grid item xs={12} sm={6}>
                <DatePicker
                  label="From Date"
                  value={filters.dateFrom}
                  onChange={(date) => setFilters(prev => ({ ...prev, dateFrom: date }))}
                  slotProps={{
                    textField: {
                      fullWidth: true,
                      size: 'small'
                    }
                  }}
                />
              </Grid>
              <Grid item xs={12} sm={6}>
                <DatePicker
                  label="To Date"
                  value={filters.dateTo}
                  onChange={(date) => setFilters(prev => ({ ...prev, dateTo: date }))}
                  slotProps={{
                    textField: {
                      fullWidth: true,
                      size: 'small'
                    }
                  }}
                />
              </Grid>
            </Grid>
          </Stack>
        );
      
      default:
        return null;
    }
  };

  const steps = [
    {
      label: 'Select Data Type',
      description: 'Choose what type of data to export'
    },
    {
      label: 'Choose Format',
      description: 'Select the export format'
    },
    {
      label: 'Set Filters',
      description: 'Optional: Filter the data'
    },
    {
      label: 'Select Fields',
      description: 'Choose which fields to include'
    },
    {
      label: 'Export',
      description: 'Download your data'
    }
  ];

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      {/* Header */}
      <Box sx={{ marginBottom: 3 }}>
        <Typography variant="h5" component="h2" gutterBottom>
          Data Export
        </Typography>
        <Typography variant="body1" color="text.secondary">
          Export your data in various formats with custom filters and field selection
        </Typography>
      </Box>

      {/* Alerts */}
      {error && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError('')}>
          {error}
        </Alert>
      )}

      {success && (
        <Alert severity="success" sx={{ mb: 2 }} onClose={() => setSuccess('')}>
          {success}
        </Alert>
      )}

      {/* Stepper */}
      <Paper sx={{ p: 3, mb: 3 }}>
        <Stepper activeStep={activeStep} orientation="horizontal">
          {steps.map((step, index) => (
            <Step key={step.label}>
              <StepLabel>
                <Box>
                  <Typography variant="body2" sx={{ fontWeight: activeStep === index ? 600 : 400 }}>
                    {step.label}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {step.description}
                  </Typography>
                </Box>
              </StepLabel>
            </Step>
          ))}
        </Stepper>
      </Paper>

      {/* Data Type Selector - Always Visible */}
      <Paper sx={{ p: 3, mb: 3 }}>
        <Box display="flex" justifyContent="space-between" alignItems="center" mb={2}>
          <Typography variant="h6" component="h3">
            Export Data Type
          </Typography>
          <Typography variant="body2" color="text.secondary">
            You can change this at any time
          </Typography>
        </Box>
        <Grid container spacing={2}>
          {exportTypes.map(type => (
            <Grid item xs={12} sm={4} key={type.value}>
              <Card 
                sx={{ 
                  cursor: 'pointer',
                  border: exportType === type.value ? 2 : 1,
                  borderColor: exportType === type.value ? 'primary.main' : 'divider',
                  '&:hover': { borderColor: 'primary.main', boxShadow: 2 }
                }}
                onClick={() => handleExportTypeChange(type.value)}
              >
                <CardContent>
                  <Box display="flex" alignItems="center" mb={1}>
                    {type.icon}
                    <Typography variant="subtitle1" sx={{ ml: 1 }}>
                      {type.label}
                    </Typography>
                  </Box>
                  <Typography variant="body2" color="text.secondary">
                    {type.description}
                  </Typography>
                </CardContent>
              </Card>
            </Grid>
          ))}
        </Grid>
      </Paper>

      {/* Step Content */}
      <Paper sx={{ p: 3, mb: 3, minHeight: '400px', display: 'flex', flexDirection: 'column' }}>
        {/* Step 0: Export Type Selection */}
        {activeStep === 0 && (
          <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
            <Typography variant="h6" component="h3" gutterBottom>
              Welcome to Export Wizard
            </Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
              Select your data type above, then choose the export format and configure your export settings.
            </Typography>
            <Box sx={{ 
              p: 3, 
              bgcolor: 'grey.50', 
              borderRadius: 2, 
              border: '1px solid', 
              borderColor: 'grey.200',
              textAlign: 'center'
            }}>
              <Typography variant="h6" color="primary" gutterBottom>
                Ready to Export {exportTypes.find(t => t.value === exportType)?.label || 'Your Data'}?
              </Typography>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                You can change the data type at any time using the selector above.
              </Typography>
              <Typography variant="body2" color="text.secondary">
                Click "Next" to continue with the export configuration.
              </Typography>
            </Box>
            <Box sx={{ mt: 'auto', display: 'flex', justifyContent: 'flex-end' }}>
              <Button
                variant="contained"
                onClick={handleNext}
                disabled={!exportType}
              >
                Next
              </Button>
            </Box>
          </Box>
        )}

        {/* Step 1: Export Format Selection */}
        {activeStep === 1 && (
          <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
            <Typography variant="h6" component="h3" gutterBottom>
              Choose Export Format
            </Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
              Select the format for your exported data
            </Typography>
            <Grid container spacing={2}>
              {exportFormats.map(format => (
                <Grid item xs={12} sm={6} key={format.value}>
                  <Card 
                    sx={{ 
                      cursor: 'pointer',
                      border: exportFormat === format.value ? 2 : 1,
                      borderColor: exportFormat === format.value ? 'primary.main' : 'divider',
                      '&:hover': { borderColor: 'primary.main', boxShadow: 2 }
                    }}
                    onClick={() => setExportFormat(format.value)}
                  >
                    <CardContent>
                      <Box display="flex" alignItems="center" mb={1}>
                        <FileDownloadIcon />
                        <Typography variant="h6" sx={{ ml: 1 }}>
                          {format.label}
                        </Typography>
                      </Box>
                      <Typography variant="body2" color="text.secondary">
                        {format.description}
                      </Typography>
                    </CardContent>
                  </Card>
                </Grid>
              ))}
            </Grid>
            <Box sx={{ mt: 'auto', display: 'flex', justifyContent: 'flex-end', gap: 2 }}>
              <Button onClick={handleBack}>
                Back
              </Button>
              <Button
                variant="contained"
                onClick={handleNext}
                disabled={!exportFormat}
              >
                Next
              </Button>
            </Box>
          </Box>
        )}

        {/* Step 2: Filters */}
        {activeStep === 2 && (
          <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
            <Box display="flex" justifyContent="space-between" alignItems="center" mb={2}>
              <Typography variant="h6" component="h3">
                Set Filters (Optional)
              </Typography>
              <Button
                variant="outlined"
                startIcon={<RefreshIcon />}
                onClick={clearFilters}
                size="small"
              >
                Clear All
              </Button>
            </Box>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
              Filter your data to export only what you need
            </Typography>
            {getFilterFields()}
            <Box sx={{ mt: 'auto', display: 'flex', justifyContent: 'flex-end', gap: 2 }}>
              <Button onClick={handleBack}>
                Back
              </Button>
              <Button
                variant="contained"
                onClick={handleNext}
              >
                Next
              </Button>
            </Box>
          </Box>
        )}

        {/* Step 3: Field Selection */}
        {activeStep === 3 && (
          <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
            <Box display="flex" justifyContent="space-between" alignItems="center" mb={2}>
              <Typography variant="h6" component="h3">
                Select Fields to Export
              </Typography>
              <Box>
                <Button
                  variant="outlined"
                  size="small"
                  onClick={handleSelectAllFields}
                  sx={{ mr: 1 }}
                >
                  Select All
                </Button>
                <Button
                  variant="outlined"
                  size="small"
                  onClick={handleDeselectAllFields}
                >
                  Deselect All
                </Button>
              </Box>
            </Box>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
              Choose which fields to include in your export
            </Typography>
            
            <Accordion>
              <AccordionSummary expandIcon={<ExpandMoreIcon />}>
                <Typography variant="subtitle1">
                  Available Fields ({fieldOptions[exportType]?.length || 0})
                </Typography>
              </AccordionSummary>
              <AccordionDetails>
                <FormGroup>
                  <Grid container spacing={1}>
                    {fieldOptions[exportType]?.map(field => (
                      <Grid item xs={12} sm={6} md={4} key={field.value}>
                        <FormControlLabel
                          control={
                            <Checkbox
                              checked={selectedFields[exportType].includes(field.value)}
                              onChange={() => handleFieldToggle(field.value)}
                              size="small"
                            />
                          }
                          label={
                            <Typography variant="body2">
                              {field.label}
                            </Typography>
                          }
                        />
                      </Grid>
                    ))}
                  </Grid>
                </FormGroup>
              </AccordionDetails>
            </Accordion>
            
            <Box sx={{ mt: 2, p: 2, bgcolor: 'grey.50', borderRadius: 1 }}>
              <Typography variant="body2" color="text.secondary">
                <strong>Selected:</strong> {selectedFields[exportType].length} of {fieldOptions[exportType]?.length} fields
              </Typography>
            </Box>
            
            <Box sx={{ mt: 'auto', display: 'flex', justifyContent: 'flex-end', gap: 2 }}>
              <Button onClick={handleBack}>
                Back
              </Button>
              <Button
                variant="contained"
                onClick={handleNext}
                disabled={selectedFields[exportType].length === 0}
              >
                Next
              </Button>
            </Box>
          </Box>
        )}

        {/* Step 4: Export */}
        {activeStep === 4 && (
          <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
            <Typography variant="h6" component="h3" gutterBottom>
              Ready to Export
            </Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
              Review your export settings and click Export to download your data
            </Typography>

            {/* Export Summary */}
            <Card sx={{ mb: 3, bgcolor: 'grey.50' }}>
              <CardContent>
                <Typography variant="subtitle1" gutterBottom>
                  Export Summary
                </Typography>
                <List dense>
                  <ListItem sx={{ py: 0 }}>
                    <ListItemIcon>
                      <SettingsIcon />
                    </ListItemIcon>
                    <ListItemText
                      primary="Data Type"
                      secondary={exportTypes.find(t => t.value === exportType)?.label}
                    />
                  </ListItem>
                  <ListItem sx={{ py: 0 }}>
                    <ListItemIcon>
                      <FileDownloadIcon />
                    </ListItemIcon>
                    <ListItemText
                      primary="Format"
                      secondary={exportFormats.find(f => f.value === exportFormat)?.label}
                    />
                  </ListItem>
                  <ListItem sx={{ py: 0 }}>
                    <ListItemIcon>
                      <DescriptionIcon />
                    </ListItemIcon>
                    <ListItemText
                      primary="Fields"
                      secondary={`${selectedFields[exportType].length} fields selected`}
                    />
                  </ListItem>
                </List>
              </CardContent>
            </Card>

            {/* Export Button */}
            <Box sx={{ display: 'flex', justifyContent: 'center', mb: 3 }}>
              <Button
                variant="contained"
                size="large"
                startIcon={<DownloadIcon />}
                onClick={handleExport}
                disabled={loading || selectedFields[exportType].length === 0}
                sx={{ px: 4, py: 1.5 }}
              >
                {loading ? 'Exporting...' : 'Export Data'}
              </Button>
            </Box>
            
            {loading && (
              <Box sx={{ mb: 3 }}>
                <LinearProgress variant="determinate" value={exportProgress} />
                <Typography variant="body2" color="text.secondary" sx={{ mt: 1, textAlign: 'center' }}>
                  {exportProgress}% Complete
                </Typography>
              </Box>
            )}

            <Box sx={{ mt: 'auto', display: 'flex', justifyContent: 'flex-end', gap: 2 }}>
              <Button onClick={handleBack}>
                Back
              </Button>
              <Button onClick={handleReset}>
                Start Over
              </Button>
            </Box>
          </Box>
        )}
      </Paper>
    </Box>
  );
};

export default Export;




