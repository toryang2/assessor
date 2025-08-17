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
  Typography,
  Card,
  CardContent,
  Grid,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  Chip,
  IconButton,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Alert,
  List,
  ListItem,
  ListItemText,
  ListItemIcon,
  Collapse
} from '@mui/material';
import {
  Search as SearchIcon,
  FilterList as FilterIcon,
  ExpandMore as ExpandMoreIcon,
  ExpandLess as ExpandLessIcon,
  Refresh as RefreshIcon,
  History as HistoryIcon,
  Person as PersonIcon,
  Settings as SettingsIcon,
  Description as DescriptionIcon,
  AttachFile as AttachFileIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import { format } from 'date-fns';

import { apiService } from '../../utils/api';
import { statusColors } from '../../theme/theme';

const AuditTrail = () => {
  const [auditLogs, setAuditLogs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(20);
  const [totalCount, setTotalCount] = useState(0);
  
  // Search and filter states
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState({
    action: '',
    table: '',
    user_id: '',
    dateFrom: null,
    dateTo: null
  });
  
  // Detail view states
  const [expandedLogs, setExpandedLogs] = useState(new Set());
  const [detailDialog, setDetailDialog] = useState(false);
  const [selectedLog, setSelectedLog] = useState(null);

  useEffect(() => {
    fetchAuditTrail();
  }, [page, rowsPerPage, searchTerm, filters]);

  const fetchAuditTrail = async () => {
    try {
      setLoading(true);
      const params = {
        page: page + 1,
        per_page: rowsPerPage,
        search: searchTerm,
        ...filters
      };
      
      const response = await apiService.getAuditTrail(params);
      setAuditLogs(response.data || []);
      setTotalCount(response.total || response.data.length);
    } catch (err) {
      setError('Failed to fetch audit trail');
      console.error('Error fetching audit trail:', err);
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

  const handleLogExpand = (logId) => {
    const newExpanded = new Set(expandedLogs);
    if (newExpanded.has(logId)) {
      newExpanded.delete(logId);
    } else {
      newExpanded.add(logId);
    }
    setExpandedLogs(newExpanded);
  };

  const handleViewDetails = (log) => {
    setSelectedLog(log);
    setDetailDialog(true);
  };

  const clearFilters = () => {
    setFilters({
      action: '',
      table: '',
      user_id: '',
      dateFrom: null,
      dateTo: null
    });
    setSearchTerm('');
    setPage(0);
  };

  const getActionIcon = (action) => {
    switch (action.toLowerCase()) {
      case 'login':
      case 'logout':
        return <PersonIcon />;
      case 'create':
      case 'insert':
        return <DescriptionIcon />;
      case 'update':
        return <SettingsIcon />;
      case 'delete':
        return <DescriptionIcon />;
      case 'upload':
        return <AttachFileIcon />;
      default:
        return <HistoryIcon />;
    }
  };

  const getActionColor = (action) => {
    switch (action.toLowerCase()) {
      case 'create':
      case 'insert':
        return 'success';
      case 'update':
        return 'info';
      case 'delete':
        return 'error';
      case 'login':
        return 'primary';
      case 'logout':
        return 'warning';
      default:
        return 'default';
    }
  };

  const getTableDisplayName = (table) => {
    const tableNames = {
      'wp_assessor_properties': 'Properties',
      'wp_assessor_users': 'Users',
      'wp_assessor_documents': 'Documents',
      'wp_assessor_property_versions': 'Property Versions',
      'wp_assessor_audit_trail': 'Audit Trail'
    };
    return tableNames[table] || table.replace('wp_assessor_', '').replace(/_/g, ' ');
  };

  const formatChanges = (changes) => {
    if (!changes) return 'No changes recorded';
    
    try {
      const parsed = typeof changes === 'string' ? JSON.parse(changes) : changes;
      return Object.entries(parsed).map(([field, change]) => (
        <Box key={field} sx={{ mb: 1 }}>
          <Typography variant="body2" fontWeight={600}>
            {field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}:
          </Typography>
          <Box sx={{ ml: 2 }}>
            <Typography variant="body2" color="error.main" component="span">
              {change.old_value || 'Not specified'} →
            </Typography>
            <Typography variant="body2" color="success.main" component="span" sx={{ ml: 1 }}>
              {change.new_value || 'Not specified'}
            </Typography>
          </Box>
        </Box>
      ));
    } catch (e) {
      return <Typography variant="body2" color="text.secondary">Invalid change data</Typography>;
    }
  };

  if (loading && auditLogs.length === 0) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <Typography>Loading audit trail...</Typography>
      </Box>
    );
  }

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      <Typography variant="h4" gutterBottom>
        Audit Trail
      </Typography>

      {error && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError('')}>
          {error}
        </Alert>
      )}

      {/* Search and Filters */}
      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Grid container spacing={2} alignItems="center">
            <Grid item xs={12} md={3}>
              <TextField
                fullWidth
                label="Search Audit Logs"
                value={searchTerm}
                onChange={handleSearch}
                placeholder="Search by action, user, or details..."
                InputProps={{
                  startAdornment: <SearchIcon sx={{ mr: 1, color: 'text.secondary' }} />
                }}
              />
            </Grid>
            
            <Grid item xs={12} md={2}>
              <FormControl fullWidth>
                <InputLabel>Action</InputLabel>
                <Select
                  value={filters.action}
                  label="Action"
                  onChange={(e) => handleFilterChange('action', e.target.value)}
                >
                  <MenuItem value="">All</MenuItem>
                  <MenuItem value="create">Create</MenuItem>
                  <MenuItem value="update">Update</MenuItem>
                  <MenuItem value="delete">Delete</MenuItem>
                  <MenuItem value="login">Login</MenuItem>
                  <MenuItem value="logout">Logout</MenuItem>
                  <MenuItem value="upload">Upload</MenuItem>
                </Select>
              </FormControl>
            </Grid>

            <Grid item xs={12} md={2}>
              <FormControl fullWidth>
                <InputLabel>Table</InputLabel>
                <Select
                  value={filters.table}
                  label="Table"
                  onChange={(e) => handleFilterChange('table', e.target.value)}
                >
                  <MenuItem value="">All</MenuItem>
                  <MenuItem value="wp_assessor_properties">Properties</MenuItem>
                  <MenuItem value="wp_assessor_users">Users</MenuItem>
                  <MenuItem value="wp_assessor_documents">Documents</MenuItem>
                  <MenuItem value="wp_assessor_property_versions">Versions</MenuItem>
                </Select>
              </FormControl>
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
              <Button
                variant="outlined"
                startIcon={<RefreshIcon />}
                onClick={fetchAuditTrail}
              >
                Refresh
              </Button>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* Audit Trail Table */}
      <Paper sx={{ width: '100%', overflow: 'hidden' }}>
        <TableContainer>
          <Table stickyHeader>
            <TableHead>
              <TableRow>
                <TableCell>Action</TableCell>
                <TableCell>User</TableCell>
                <TableCell>Table</TableCell>
                <TableCell>Record ID</TableCell>
                <TableCell>Date & Time</TableCell>
                <TableCell>IP Address</TableCell>
                <TableCell>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {auditLogs.map((log) => (
                <React.Fragment key={log.id}>
                  <TableRow hover>
                    <TableCell>
                      <Box display="flex" alignItems="center">
                        {getActionIcon(log.action)}
                        <Chip
                          label={log.action}
                          size="small"
                          color={getActionColor(log.action)}
                          sx={{ ml: 1 }}
                        />
                      </Box>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">
                        {log.user_name || log.user_id || 'System'}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">
                        {getTableDisplayName(log.table_name)}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">
                        {log.record_id || 'N/A'}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">
                        {format(new Date(log.created_at), 'MMM dd, yyyy HH:mm:ss')}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2" fontFamily="monospace">
                        {log.ip_address || 'N/A'}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Box display="flex" gap={1}>
                        <IconButton
                          size="small"
                          onClick={() => handleLogExpand(log.id)}
                          color="primary"
                        >
                          {expandedLogs.has(log.id) ? <ExpandLessIcon /> : <ExpandMoreIcon />}
                        </IconButton>
                        
                        <IconButton
                          size="small"
                          onClick={() => handleViewDetails(log)}
                          color="info"
                        >
                          <HistoryIcon />
                        </IconButton>
                      </Box>
                    </TableCell>
                  </TableRow>
                  
                  {/* Expanded Log Details */}
                  <TableRow>
                    <TableCell colSpan={7} sx={{ p: 0 }}>
                      <Collapse in={expandedLogs.has(log.id)} timeout="auto" unmountOnExit>
                        <Box sx={{ p: 2, backgroundColor: '#f8fafc' }}>
                          <Grid container spacing={2}>
                            <Grid item xs={12} md={6}>
                              <Typography variant="subtitle2" gutterBottom>
                                Changes Made:
                              </Typography>
                              {formatChanges(log.changes)}
                            </Grid>
                            <Grid item xs={12} md={6}>
                              <Typography variant="subtitle2" gutterBottom>
                                Additional Details:
                              </Typography>
                              <Typography variant="body2" color="text.secondary">
                                User Agent: {log.user_agent || 'Not specified'}
                              </Typography>
                              {log.remarks && (
                                <Typography variant="body2" color="text.secondary">
                                  Remarks: {log.remarks}
                                </Typography>
                              )}
                            </Grid>
                          </Grid>
                        </Box>
                      </Collapse>
                    </TableCell>
                  </TableRow>
                </React.Fragment>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
        
        <TablePagination
          rowsPerPageOptions={[10, 20, 50, 100]}
          component="div"
          count={totalCount}
          rowsPerPage={rowsPerPage}
          page={page}
          onPageChange={handlePageChange}
          onRowsPerPageChange={handleRowsPerPageChange}
        />
      </Paper>

      {/* Detail View Dialog */}
      <Dialog
        open={detailDialog}
        onClose={() => setDetailDialog(false)}
        maxWidth="md"
        fullWidth
      >
        <DialogTitle>
          Audit Log Details
        </DialogTitle>
        <DialogContent>
          {selectedLog && (
            <Grid container spacing={2} sx={{ mt: 1 }}>
              <Grid item xs={12} md={6}>
                <Typography variant="subtitle2" gutterBottom>
                  Basic Information
                </Typography>
                <List dense>
                  <ListItem>
                    <ListItemText
                      primary="Action"
                      secondary={
                        <Chip
                          label={selectedLog.action}
                          size="small"
                          color={getActionColor(selectedLog.action)}
                        />
                      }
                    />
                  </ListItem>
                  <ListItem>
                    <ListItemText
                      primary="User"
                      secondary={selectedLog.user_name || selectedLog.user_id || 'System'}
                    />
                  </ListItem>
                  <ListItem>
                    <ListItemText
                      primary="Table"
                      secondary={getTableDisplayName(selectedLog.table_name)}
                    />
                  </ListItem>
                  <ListItem>
                    <ListItemText
                      primary="Record ID"
                      secondary={selectedLog.record_id || 'N/A'}
                    />
                  </ListItem>
                </List>
              </Grid>
              
              <Grid item xs={12} md={6}>
                <Typography variant="subtitle2" gutterBottom>
                  Technical Details
                </Typography>
                <List dense>
                  <ListItem>
                    <ListItemText
                      primary="Date & Time"
                      secondary={format(new Date(selectedLog.created_at), 'MMM dd, yyyy HH:mm:ss')}
                    />
                  </ListItem>
                  <ListItem>
                    <ListItemText
                      primary="IP Address"
                      secondary={selectedLog.ip_address || 'N/A'}
                    />
                  </ListItem>
                  <ListItem>
                    <ListItemText
                      primary="User Agent"
                      secondary={selectedLog.user_agent || 'Not specified'}
                    />
                  </ListItem>
                </List>
              </Grid>
              
              <Grid item xs={12}>
                <Typography variant="subtitle2" gutterBottom>
                  Changes Made
                </Typography>
                <Box sx={{ p: 2, backgroundColor: '#f8fafc', borderRadius: 1 }}>
                  {formatChanges(selectedLog.changes)}
                </Box>
              </Grid>
              
              {selectedLog.remarks && (
                <Grid item xs={12}>
                  <Typography variant="subtitle2" gutterBottom>
                    Remarks
                  </Typography>
                  <Typography variant="body2">
                    {selectedLog.remarks}
                  </Typography>
                </Grid>
              )}
            </Grid>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDetailDialog(false)}>Close</Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default AuditTrail;




