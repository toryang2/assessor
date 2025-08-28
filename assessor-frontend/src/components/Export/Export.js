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
  FormGroup
} from '@mui/material';
import {
  Download as DownloadIcon,
  FileDownload as FileDownloadIcon,
  TableChart as TableChartIcon,
  History as HistoryIcon,
  People as PeopleIcon,
  Description as DescriptionIcon,
  Settings as SettingsIcon,
  Refresh as RefreshIcon
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
      'owner_name',
      'location',
      'property_type',
      'status',
      'assessed_value',
      'market_value',
      'created_at',
      'updated_at'
    ],
    audit: [
      'action',
      'user_name',
      'table_name',
      'record_id',
      'created_at',
      'ip_address'
    ],
    users: [
      'username',
      'email',
      'role',
      'status',
      'created_at',
      'last_login'
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
      { value: 'owner_name', label: 'Owner Name' },
      { value: 'owner_address', label: 'Owner Address' },
      { value: 'owner_contact', label: 'Owner Contact' },
      { value: 'property_type', label: 'Property Type' },
      { value: 'property_address', label: 'Property Address' },
      { value: 'location', label: 'Location' },
      { value: 'land_area', label: 'Land Area' },
      { value: 'land_area_unit', label: 'Land Area Unit' },
      { value: 'building_area', label: 'Building Area' },
      { value: 'building_area_unit', label: 'Building Area Unit' },
      { value: 'assessed_value', label: 'Assessed Value' },
      { value: 'market_value', label: 'Market Value' },
      { value: 'status', label: 'Status' },
      { value: 'remarks', label: 'Remarks' },
      { value: 'created_at', label: 'Created Date' },
      { value: 'updated_at', label: 'Updated Date' }
    ],
    audit: [
      { value: 'action', label: 'Action' },
      { value: 'user_id', label: 'User ID' },
      { value: 'user_name', label: 'User Name' },
      { value: 'table_name', label: 'Table Name' },
      { value: 'record_id', label: 'Record ID' },
      { value: 'changes', label: 'Changes Made' },
      { value: 'ip_address', label: 'IP Address' },
      { value: 'user_agent', label: 'User Agent' },
      { value: 'remarks', label: 'Remarks' },
      { value: 'created_at', label: 'Date & Time' }
    ],
    users: [
      { value: 'username', label: 'Username' },
      { value: 'email', label: 'Email' },
      { value: 'role', label: 'Role' },
      { value: 'status', label: 'Status' },
      { value: 'first_name', label: 'First Name' },
      { value: 'last_name', label: 'Last Name' },
      { value: 'created_at', label: 'Created Date' },
      { value: 'last_login', label: 'Last Login' }
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
          <Grid container spacing={2}>
            <Grid item xs={12} md={3}>
              <DatePicker
                label="From Date"
                value={filters.dateFrom}
                onChange={(date) => setFilters(prev => ({ ...prev, dateFrom: date }))}
                slotProps={{
                  textField: {
                    fullWidth: true
                  }
                }}
              />
            </Grid>
            <Grid item xs={12} md={3}>
              <DatePicker
                label="To Date"
                value={filters.dateTo}
                onChange={(date) => setFilters(prev => ({ ...prev, dateTo: date }))}
                slotProps={{
                  textField: {
                    fullWidth: true
                  }
                }}
              />
            </Grid>
            <Grid item xs={12} md={3}>
              <FormControl fullWidth>
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
                </Select>
              </FormControl>
            </Grid>
            <Grid item xs={12} md={3}>
              <TextField
                fullWidth
                label="Location"
                value={filters.location}
                onChange={(e) => setFilters(prev => ({ ...prev, location: e.target.value }))}
                placeholder="City/Municipality"
              />
            </Grid>
          </Grid>
        );
      
      case 'audit':
        return (
          <Grid container spacing={2}>
            <Grid item xs={12} md={4}>
              <DatePicker
                label="From Date"
                value={filters.dateFrom}
                onChange={(date) => setFilters(prev => ({ ...prev, dateFrom: date }))}
                slotProps={{
                  textField: {
                    fullWidth: true
                  }
                }}
              />
            </Grid>
            <Grid item xs={12} md={4}>
              <DatePicker
                label="To Date"
                value={filters.dateTo}
                onChange={(date) => setFilters(prev => ({ ...prev, dateTo: date }))}
                slotProps={{
                  textField: {
                    fullWidth: true
                  }
                }}
              />
            </Grid>
          </Grid>
        );
      
      default:
        return null;
    }
  };

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      <Typography variant="h4" gutterBottom>
        Data Export
      </Typography>

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

      {/* Export Configuration */}
      <Grid container spacing={3}>
        {/* Export Type Selection */}
        <Grid item xs={12} md={4}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                Export Type
              </Typography>
              <FormControl fullWidth>
                <InputLabel>Select Data Type</InputLabel>
                <Select
                  value={exportType}
                  label="Select Data Type"
                  onChange={(e) => handleExportTypeChange(e.target.value)}
                >
                  {exportTypes.map(type => (
                    <MenuItem key={type.value} value={type.value}>
                      <Box display="flex" alignItems="center">
                        {type.icon}
                        <Box sx={{ ml: 1 }}>
                          <Typography variant="body2">{type.label}</Typography>
                          <Typography variant="caption" color="text.secondary">
                            {type.description}
                          </Typography>
                        </Box>
                      </Box>
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </CardContent>
          </Card>
        </Grid>

        {/* Export Format Selection */}
        <Grid item xs={12} md={4}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                Export Format
              </Typography>
              <FormControl fullWidth>
                <InputLabel>Select Format</InputLabel>
                <Select
                  value={exportFormat}
                  label="Select Format"
                  onChange={(e) => setExportFormat(e.target.value)}
                >
                  {exportFormats.map(format => (
                    <MenuItem key={format.value} value={format.value}>
                      <Box display="flex" alignItems="center">
                        <FileDownloadIcon />
                        <Box sx={{ ml: 1 }}>
                          <Typography variant="body2">{format.label}</Typography>
                          <Typography variant="caption" color="text.secondary">
                            {format.description}
                          </Typography>
                        </Box>
                      </Box>
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </CardContent>
          </Card>
        </Grid>

        {/* Export Action */}
        <Grid item xs={12} md={4}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                Export Action
              </Typography>
              <Button
                fullWidth
                variant="contained"
                startIcon={<DownloadIcon />}
                onClick={handleExport}
                disabled={loading || selectedFields[exportType].length === 0}
                sx={{ py: 2 }}
              >
                {loading ? 'Exporting...' : 'Export Data'}
              </Button>
              
              {loading && (
                <Box sx={{ mt: 2 }}>
                  <LinearProgress variant="determinate" value={exportProgress} />
                  <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
                    {exportProgress}% Complete
                  </Typography>
                </Box>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      {/* Filters */}
      <Card sx={{ mt: 3, mb: 3 }}>
        <CardContent>
          <Box display="flex" justifyContent="space-between" alignItems="center" mb={2}>
            <Typography variant="h6">
              Export Filters
            </Typography>
            <Button
              variant="outlined"
              startIcon={<RefreshIcon />}
              onClick={clearFilters}
              size="small"
            >
              Clear Filters
            </Button>
          </Box>
          {getFilterFields()}
        </CardContent>
      </Card>

      {/* Field Selection */}
      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Box display="flex" justifyContent="space-between" alignItems="center" mb={2}>
            <Typography variant="h6">
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
          
          <FormGroup>
            <Grid container spacing={2}>
              {fieldOptions[exportType]?.map(field => (
                <Grid item xs={12} md={4} key={field.value}>
                  <FormControlLabel
                    control={
                      <Checkbox
                        checked={selectedFields[exportType].includes(field.value)}
                        onChange={() => handleFieldToggle(field.value)}
                      />
                    }
                    label={field.label}
                  />
                </Grid>
              ))}
            </Grid>
          </FormGroup>
          
          <Box sx={{ mt: 2 }}>
            <Typography variant="body2" color="text.secondary">
              Selected {selectedFields[exportType].length} of {fieldOptions[exportType]?.length} fields
            </Typography>
          </Box>
        </CardContent>
      </Card>

      {/* Export Information */}
      <Card>
        <CardContent>
          <Typography variant="h6" gutterBottom>
            Export Information
          </Typography>
          <List dense>
            <ListItem>
              <ListItemIcon>
                <SettingsIcon />
              </ListItemIcon>
              <ListItemText
                primary="Export Type"
                secondary={exportTypes.find(t => t.value === exportType)?.label}
              />
            </ListItem>
            <ListItem>
              <ListItemIcon>
                <FileDownloadIcon />
              </ListItemIcon>
              <ListItemText
                primary="Export Format"
                secondary={exportFormats.find(f => f.value === exportFormat)?.label}
              />
            </ListItem>
            <ListItem>
              <ListItemIcon>
                <DescriptionIcon />
              </ListItemIcon>
              <ListItemText
                primary="Selected Fields"
                secondary={`${selectedFields[exportType].length} fields selected`}
              />
            </ListItem>
            <ListItem>
              <ListItemIcon>
                <TableChartIcon />
              </ListItemIcon>
              <ListItemText
                primary="Estimated Size"
                secondary="File size will depend on the amount of data and selected fields"
              />
            </ListItem>
          </List>
        </CardContent>
      </Card>
    </Box>
  );
};

export default Export;




