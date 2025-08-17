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
  Button,
  Typography,
  Card,
  CardContent,
  Grid,
  Chip,
  IconButton,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Alert,
  Divider,
  List,
  ListItem,
  ListItemText,
  ListItemIcon,
  Collapse
} from '@mui/material';
import {
  ArrowBack as ArrowBackIcon,
  Restore as RestoreIcon,
  ExpandMore as ExpandMoreIcon,
  ExpandLess as ExpandLessIcon,
  Compare as CompareIcon,
  History as HistoryIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { format } from 'date-fns';

import { apiService } from '../../utils/api';
import { statusColors } from '../../theme/theme';

const VersionHistory = () => {
  // For now, we'll use a default property ID or get it from props
  const propertyId = 1; // This should be passed as a prop or selected from a list
  const [property, setProperty] = useState(null);
  const [versions, setVersions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selectedVersion, setSelectedVersion] = useState(null);
  const [compareDialog, setCompareDialog] = useState(false);
  const [restoreDialog, setRestoreDialog] = useState(false);
  const [versionToRestore, setVersionToRestore] = useState(null);
  const [expandedVersions, setExpandedVersions] = useState(new Set());

  useEffect(() => {
    if (propertyId) {
      fetchPropertyAndVersions();
    }
  }, [propertyId]);

  const fetchPropertyAndVersions = async () => {
    try {
      setLoading(true);
      
      // Fetch property details
      const propertyResponse = await apiService.getProperty(propertyId);
      setProperty(propertyResponse.data);
      
      // Fetch version history
      const versionsResponse = await apiService.getPropertyVersions(propertyId);
      setVersions(versionsResponse.data || []);
    } catch (err) {
      setError('Failed to fetch property and version history');
      console.error('Error fetching data:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleVersionExpand = (versionId) => {
    const newExpanded = new Set(expandedVersions);
    if (newExpanded.has(versionId)) {
      newExpanded.delete(versionId);
    } else {
      newExpanded.add(versionId);
    }
    setExpandedVersions(newExpanded);
  };

  const handleCompareVersion = (version) => {
    setSelectedVersion(version);
    setCompareDialog(true);
  };

  const handleRestoreVersion = (version) => {
    setVersionToRestore(version);
    setRestoreDialog(true);
  };

  const confirmRestore = async () => {
    try {
      await apiService.restorePropertyVersion(propertyId, versionToRestore.id);
      setRestoreDialog(false);
      setVersionToRestore(null);
      fetchPropertyAndVersions(); // Refresh data
    } catch (err) {
      setError('Failed to restore version');
    }
  };

  const getStatusColor = (status) => {
    return statusColors[status] || statusColors.info;
  };

  const formatValue = (value) => {
    if (value === null || value === undefined || value === '') {
      return <em style={{ color: '#999' }}>Not specified</em>;
    }
    return value;
  };

  const renderVersionDetails = (version) => {
    const changes = version.changes || {};
    const changeFields = Object.keys(changes);
    
    if (changeFields.length === 0) {
      return (
        <Typography variant="body2" color="text.secondary">
          No changes recorded for this version
        </Typography>
      );
    }

    return (
      <List dense>
        {changeFields.map(field => {
          const change = changes[field];
          return (
            <ListItem key={field} sx={{ pl: 4 }}>
              <ListItemIcon>
                <Box
                  sx={{
                    width: 8,
                    height: 8,
                    borderRadius: '50%',
                    backgroundColor: change.old_value !== change.new_value ? '#f59e0b' : '#10b981'
                  }}
                />
              </ListItemIcon>
              <ListItemText
                primary={
                  <Box>
                    <Typography variant="body2" component="span" fontWeight={600}>
                      {field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}:
                    </Typography>
                    <Box component="span" sx={{ ml: 1 }}>
                      <Typography
                        variant="body2"
                        component="span"
                        sx={{
                          textDecoration: 'line-through',
                          color: 'error.main',
                          mr: 1
                        }}
                      >
                        {formatValue(change.old_value)}
                      </Typography>
                      <Typography
                        variant="body2"
                        component="span"
                        sx={{ color: 'success.main' }}
                      >
                        {formatValue(change.new_value)}
                      </Typography>
                    </Box>
                  </Box>
                }
              />
            </ListItem>
          );
        })}
      </List>
    );
  };

  if (loading) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <Typography>Loading version history...</Typography>
      </Box>
    );
  }

  if (error) {
    return (
      <Box>
        <Alert severity="error" sx={{ mb: 2 }}>
          {error}
        </Alert>
                 <Button
           variant="outlined"
           startIcon={<ArrowBackIcon />}
           onClick={() => window.history.back()}
         >
           Back
         </Button>
      </Box>
    );
  }

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      {/* Header */}
      <Box display="flex" alignItems="center" mb={3}>
                 <Button
           variant="outlined"
           startIcon={<ArrowBackIcon />}
           onClick={() => window.history.back()}
           sx={{ mr: 2 }}
         >
           Back
         </Button>
        <Typography variant="h4">
          Version History
        </Typography>
      </Box>

      {/* Property Summary */}
      {property && (
        <Card sx={{ mb: 3 }}>
          <CardContent>
            <Typography variant="h6" gutterBottom>
              Property Summary
            </Typography>
            <Grid container spacing={2}>
              <Grid item xs={12} md={3}>
                <Typography variant="body2" color="text.secondary">
                  Tax Declaration
                </Typography>
                <Typography variant="body1" fontWeight={600}>
                  {property.tax_declaration_number}
                </Typography>
              </Grid>
              <Grid item xs={12} md={3}>
                <Typography variant="body2" color="text.secondary">
                  Owner
                </Typography>
                <Typography variant="body1">
                  {property.owner_name}
                </Typography>
              </Grid>
              <Grid item xs={12} md={3}>
                <Typography variant="body2" color="text.secondary">
                  Location
                </Typography>
                <Typography variant="body1">
                  {property.location}
                </Typography>
              </Grid>
              <Grid item xs={12} md={3}>
                <Typography variant="body2" color="text.secondary">
                  Current Status
                </Typography>
                <Chip
                  label={property.status}
                  size="small"
                  sx={{
                    backgroundColor: getStatusColor(property.status),
                    color: 'white',
                    fontWeight: 600
                  }}
                />
              </Grid>
            </Grid>
          </CardContent>
        </Card>
      )}

      {/* Version History Table */}
      <Paper sx={{ width: '100%', overflow: 'hidden' }}>
        <TableContainer>
          <Table>
            <TableHead>
              <TableRow>
                <TableCell>Version</TableCell>
                <TableCell>Date</TableCell>
                <TableCell>Changed By</TableCell>
                <TableCell>Changes</TableCell>
                <TableCell>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {versions.map((version, index) => (
                <React.Fragment key={version.id}>
                  <TableRow hover>
                    <TableCell>
                      <Box display="flex" alignItems="center">
                        <HistoryIcon sx={{ mr: 1, color: 'primary.main' }} />
                        <Typography variant="body2" fontWeight={600}>
                          v{versions.length - index}
                        </Typography>
                      </Box>
                    </TableCell>
                    <TableCell>
                      {format(new Date(version.created_at), 'MMM dd, yyyy HH:mm')}
                    </TableCell>
                    <TableCell>
                      {version.changed_by || 'System'}
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2" color="text.secondary">
                        {Object.keys(version.changes || {}).length} field(s) changed
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Box display="flex" gap={1}>
                        <IconButton
                          size="small"
                          onClick={() => handleVersionExpand(version.id)}
                          color="primary"
                        >
                          {expandedVersions.has(version.id) ? <ExpandLessIcon /> : <ExpandMoreIcon />}
                        </IconButton>
                        
                        <IconButton
                          size="small"
                          onClick={() => handleCompareVersion(version)}
                          color="info"
                        >
                          <CompareIcon />
                        </IconButton>
                        
                        {index > 0 && (
                          <IconButton
                            size="small"
                            onClick={() => handleRestoreVersion(version)}
                            color="warning"
                          >
                            <RestoreIcon />
                          </IconButton>
                        )}
                      </Box>
                    </TableCell>
                  </TableRow>
                  
                  {/* Expanded Version Details */}
                  <TableRow>
                    <TableCell colSpan={6} sx={{ p: 0 }}>
                      <Collapse in={expandedVersions.has(version.id)} timeout="auto" unmountOnExit>
                        <Box sx={{ p: 2, backgroundColor: '#f8fafc' }}>
                          {renderVersionDetails(version)}
                        </Box>
                      </Collapse>
                    </TableCell>
                  </TableRow>
                </React.Fragment>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      </Paper>

      {/* Version Comparison Dialog */}
      <Dialog
        open={compareDialog}
        onClose={() => setCompareDialog(false)}
        maxWidth="lg"
        fullWidth
      >
        <DialogTitle>
          Compare Version v{selectedVersion ? versions.length - versions.findIndex(v => v.id === selectedVersion?.id) : ''} with Current
        </DialogTitle>
        <DialogContent>
          {selectedVersion && (
            <Grid container spacing={2}>
              <Grid item xs={6}>
                <Typography variant="h6" gutterBottom>
                  Version v{versions.length - versions.findIndex(v => v.id === selectedVersion.id)}
                </Typography>
                <Typography variant="body2" color="text.secondary" gutterBottom>
                  {format(new Date(selectedVersion.created_at), 'MMM dd, yyyy HH:mm')}
                </Typography>
                {/* Display selected version data */}
                <Box sx={{ mt: 2 }}>
                  {Object.entries(selectedVersion.changes || {}).map(([field, change]) => (
                    <Box key={field} sx={{ mb: 1 }}>
                      <Typography variant="body2" fontWeight={600}>
                        {field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}:
                      </Typography>
                      <Typography variant="body2" color="success.main">
                        {formatValue(change.new_value)}
                      </Typography>
                    </Box>
                  ))}
                </Box>
              </Grid>
              
              <Grid item xs={6}>
                <Typography variant="h6" gutterBottom>
                  Current Version
                </Typography>
                <Typography variant="body2" color="text.secondary" gutterBottom>
                  Latest
                </Typography>
                {/* Display current property data */}
                <Box sx={{ mt: 2 }}>
                  {Object.entries(selectedVersion?.changes || {}).map(([field, change]) => (
                    <Box key={field} sx={{ mb: 1 }}>
                      <Typography variant="body2" fontWeight={600}>
                        {field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}:
                      </Typography>
                      <Typography variant="body2" color="error.main">
                        {formatValue(change.old_value)}
                      </Typography>
                    </Box>
                  ))}
                </Box>
              </Grid>
            </Grid>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCompareDialog(false)}>Close</Button>
        </DialogActions>
      </Dialog>

      {/* Restore Confirmation Dialog */}
      <Dialog open={restoreDialog} onClose={() => setRestoreDialog(false)}>
        <DialogTitle>Confirm Version Restoration</DialogTitle>
        <DialogContent>
          <Typography>
            Are you sure you want to restore this property to version v{versionToRestore ? versions.length - versions.findIndex(v => v.id === versionToRestore.id) : ''}?
            This will create a new version with the current data and then apply the selected version's data.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRestoreDialog(false)}>Cancel</Button>
          <Button onClick={confirmRestore} color="warning" variant="contained">
            Restore Version
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default VersionHistory;



