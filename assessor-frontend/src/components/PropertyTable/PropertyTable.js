import React, { useState, useEffect } from 'react';
import {
  Box,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TablePagination,
  TextField,
  Button,
  Chip,
  IconButton,
  Typography,
  Grid,
  Card,
  CardContent,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Alert
} from '@mui/material';
import {
  Search as SearchIcon,
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  History as HistoryIcon,
  AttachFile as AttachFileIcon,
  FilterList as FilterIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import { format } from 'date-fns';

import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';
import { statusColors } from '../../theme/theme';
import PropertyFormModal from '../PropertyFormModal/PropertyFormModal';

const PropertyTable = () => {
  const { isAdmin } = useAuth();
  const [properties, setProperties] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [totalCount, setTotalCount] = useState(0);
  
  // Safety check - ensure properties is always an array
  const safeProperties = properties || [];
  
  // Search and filter states
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState({
    status: '',
    location: '',
    dateFrom: null,
    dateTo: null
  });
  
  // Modal states
  const [propertyModal, setPropertyModal] = useState(false);
  const [selectedProperty, setSelectedProperty] = useState(null);
  const [deleteDialog, setDeleteDialog] = useState(false);
  const [propertyToDelete, setPropertyToDelete] = useState(null);

  useEffect(() => {
    fetchProperties();
  }, [page, rowsPerPage, searchTerm, filters]);

  const fetchProperties = async () => {
    try {
      setLoading(true);
      setError(''); // Clear previous errors
      
      const params = {
        page: page + 1,
        per_page: rowsPerPage,
        // Map search term to the fields the API expects
        tax_declaration_number: searchTerm || '',
        owner_name: searchTerm || '',
        property_location: filters.location || '',
        status: filters.status || '',
        date_from: filters.dateFrom ? format(filters.dateFrom, 'yyyy-MM-dd') : '',
        date_to: filters.dateTo ? format(filters.dateTo, 'yyyy-MM-dd') : ''
      };
      
      console.log('Fetching properties with params:', params);
      console.log('API endpoint:', '/wp-json/assessor/v1/properties');
      
      const response = await apiService.getProperties(params);
      console.log('API response:', response);
      
      if (response && response.properties) {
        setProperties(response.properties);
        setTotalCount(response.pagination ? response.pagination.total : response.properties.length);
        console.log('Properties loaded:', response.properties.length);
      } else if (response && response.data) {
        // Fallback for different response format
        setProperties(response.data);
        setTotalCount(response.total || response.data.length);
        console.log('Properties loaded (fallback):', response.data.length);
      } else {
        console.warn('Unexpected API response format:', response);
        setProperties([]);
        setTotalCount(0);
      }
    } catch (err) {
      console.error('Error fetching properties:', err);
      setError(`Failed to fetch properties: ${err.message || 'Unknown error'}`);
      setProperties([]);
      setTotalCount(0);
    } finally {
      setLoading(false);
    }
  };

  const handleSearch = (event) => {
    setSearchTerm(event.target.value);
    setPage(0);
  };

  const handleFilterChange = (field, value) => {
    setFilters(prev => ({ ...prev, [field]: value }));
    setPage(0);
  };

  const handlePageChange = (event, newPage) => {
    setPage(newPage);
  };

  const handleRowsPerPageChange = (event) => {
    setRowsPerPage(parseInt(event.target.value, 10));
    setPage(0);
  };

  const handleAddProperty = () => {
    setSelectedProperty(null);
    setPropertyModal(true);
  };

  const handleEditProperty = (property) => {
    setSelectedProperty(property);
    setPropertyModal(true);
  };

  const handleDeleteProperty = (property) => {
    setPropertyToDelete(property);
    setDeleteDialog(true);
  };

  const confirmDelete = async () => {
    try {
      await apiService.deleteProperty(propertyToDelete.id);
      setDeleteDialog(false);
      setPropertyToDelete(null);
      fetchProperties();
    } catch (err) {
      setError('Failed to delete property');
    }
  };

  const handlePropertySaved = () => {
    setPropertyModal(false);
    setSelectedProperty(null);
    fetchProperties();
  };

  const getStatusColor = (status) => {
    return statusColors[status] || statusColors.info;
  };

  const clearFilters = () => {
    setFilters({
      status: '',
      location: '',
      dateFrom: null,
      dateTo: null
    });
    setSearchTerm('');
    setPage(0);
  };

  if (loading && (!safeProperties || safeProperties.length === 0)) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <Typography>Loading properties...</Typography>
      </Box>
    );
  }

  // Add error boundary protection
  if (!safeProperties) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <Typography color="error">Error: Properties data is not available</Typography>
      </Box>
    );
  }

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      <Typography variant="h4" gutterBottom>
        Property Records
      </Typography>

      {error && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError('')}>
          <Typography variant="body2">
            {error}
          </Typography>
          <Typography variant="caption" sx={{ mt: 1, display: 'block' }}>
            Check the browser console for more details.
          </Typography>
        </Alert>
      )}

      {/* Search and Filters */}
      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Grid container spacing={2} alignItems="center">
            <Grid item xs={12} md={4}>
              <TextField
                fullWidth
                label="Search Properties"
                value={searchTerm}
                onChange={handleSearch}
                placeholder="Search by tax declaration, owner, or location..."
                InputProps={{
                  startAdornment: <SearchIcon sx={{ mr: 1, color: 'text.secondary' }} />
                }}
              />
            </Grid>
            
            <Grid item xs={12} md={2}>
              <FormControl fullWidth>
                <InputLabel>Status</InputLabel>
                <Select
                  value={filters.status}
                  label="Status"
                  onChange={(e) => handleFilterChange('status', e.target.value)}
                >
                  <MenuItem value="">All</MenuItem>
                  <MenuItem value="active">Active</MenuItem>
                  <MenuItem value="inactive">Inactive</MenuItem>
                  <MenuItem value="archived">Archived</MenuItem>
                  <MenuItem value="pending">Pending</MenuItem>
                </Select>
              </FormControl>
            </Grid>

            <Grid item xs={12} md={2}>
              <TextField
                fullWidth
                label="Location"
                value={filters.location}
                onChange={(e) => handleFilterChange('location', e.target.value)}
                placeholder="City/Municipality"
              />
            </Grid>

            <Grid item xs={12} md={2}>
              <DatePicker
                label="From Date"
                value={filters.dateFrom}
                onChange={(date) => handleFilterChange('dateFrom', date)}
                renderInput={(params) => <TextField {...params} fullWidth />}
              />
            </Grid>

            <Grid item xs={12} md={2}>
              <DatePicker
                label="To Date"
                value={filters.dateTo}
                onChange={(date) => handleFilterChange('dateTo', date)}
                renderInput={(params) => <TextField {...params} fullWidth />}
              />
            </Grid>

            <Grid item xs={12} md={6}>
              <Button
                variant="outlined"
                startIcon={<FilterIcon />}
                onClick={clearFilters}
                sx={{ mr: 1 }}
              >
                Clear Filters
              </Button>
            </Grid>

            <Grid item xs={12} md={6} textAlign="right">
              <Button
                variant="contained"
                startIcon={<AddIcon />}
                onClick={handleAddProperty}
                color="primary"
              >
                Add Property
              </Button>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* Properties Table */}
      <Paper sx={{ width: '100%', overflow: 'hidden' }}>
        <TableContainer>
          <Table stickyHeader>
            <TableHead>
              <TableRow>
                <TableCell>Tax Declaration</TableCell>
                <TableCell>Owner</TableCell>
                <TableCell>Location</TableCell>
                <TableCell>Property Type</TableCell>
                <TableCell>Status</TableCell>
                <TableCell>Last Updated</TableCell>
                <TableCell>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {safeProperties && safeProperties.length > 0 ? safeProperties.map((property) => (
                <TableRow key={property.id} hover>
                  <TableCell>
                    <Typography variant="body2" fontWeight={600}>
                      {property.tax_declaration_number}
                    </Typography>
                  </TableCell>
                  <TableCell>{property.owner_name}</TableCell>
                  <TableCell>{property.location}</TableCell>
                  <TableCell>{property.property_type}</TableCell>
                  <TableCell>
                    <Chip
                      label={property.status}
                      size="small"
                      sx={{
                        backgroundColor: getStatusColor(property.status),
                        color: 'white',
                        fontWeight: 600
                      }}
                    />
                  </TableCell>
                  <TableCell>
                    {format(new Date(property.updated_at), 'MMM dd, yyyy')}
                  </TableCell>
                  <TableCell>
                    <Box display="flex" gap={1}>
                      <IconButton
                        size="small"
                        onClick={() => handleEditProperty(property)}
                        color="primary"
                      >
                        <EditIcon />
                      </IconButton>
                      
                      <IconButton
                        size="small"
                        onClick={() => window.location.href = `/versions/${property.id}`}
                        color="info"
                      >
                        <HistoryIcon />
                      </IconButton>
                      
                      <IconButton
                        size="small"
                        onClick={() => window.location.href = `/documents/${property.id}`}
                        color="secondary"
                      >
                        <AttachFileIcon />
                      </IconButton>
                      
                      {isAdmin && (
                        <IconButton
                          onClick={() => handleDeleteProperty(property)}
                          color="error"
                        >
                          <DeleteIcon />
                        </IconButton>
                      )}
                    </Box>
                  </TableCell>
                </TableRow>
              )) : (
                <TableRow>
                  <TableCell colSpan={7} align="center">
                    <Typography variant="body2" color="text.secondary">
                      {loading ? 'Loading properties...' : 'No properties found'}
                    </Typography>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </TableContainer>
        
        <TablePagination
          rowsPerPageOptions={[5, 10, 25, 50]}
          component="div"
          count={totalCount}
          rowsPerPage={rowsPerPage}
          page={page}
          onPageChange={handlePageChange}
          onRowsPerPageChange={handleRowsPerPageChange}
        />
      </Paper>

      {/* Property Form Modal */}
      <Dialog
        open={propertyModal}
        onClose={() => setPropertyModal(false)}
        maxWidth="md"
        fullWidth
      >
        <DialogTitle>
          {selectedProperty ? 'Edit Property' : 'Add New Property'}
        </DialogTitle>
        <DialogContent>
          <PropertyFormModal
            property={selectedProperty}
            onSave={handlePropertySaved}
            onCancel={() => setPropertyModal(false)}
            open={propertyModal}
          />
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialog} onClose={() => setDeleteDialog(false)}>
        <DialogTitle>Confirm Delete</DialogTitle>
        <DialogContent>
          <Typography>
            Are you sure you want to delete the property "{propertyToDelete?.tax_declaration_number}"?
            This action cannot be undone.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeleteDialog(false)}>Cancel</Button>
          <Button onClick={confirmDelete} color="error" variant="contained">
            Delete
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default PropertyTable;
