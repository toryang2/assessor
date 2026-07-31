import React, { useState, useEffect, useCallback } from 'react';
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
  IconButton,
  Typography,
  Grid,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Alert,
  CircularProgress,
  Tooltip,
  Snackbar,
  MenuItem,
  FormControl,
  InputLabel,
  Select
} from '@mui/material';
import {
  Search as SearchIcon,
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Refresh as RefreshIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';

import { etracsService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';

const entityTypes = ['INDIVIDUAL', 'CORPORATION', 'MULTIPLE', 'GOVERNMENT'];

const EtracsEntityTable = () => {
  const { isSuperAdmin, isAdmin } = useAuth();
  const canEdit = isSuperAdmin || isAdmin;

  const [entities, setEntities] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [toast, setToast] = useState({ open: false, message: '', severity: 'success' });
  
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(20);
  const [total, setTotal] = useState(0);
  const [searchQuery, setSearchQuery] = useState('');
  const [searchInput, setSearchInput] = useState('');

  // Form Modal States
  const [formOpen, setFormOpen] = useState(false);
  const [editingId, setEditingId] = useState(null);
  
  const defaultFormData = {
    entity_name: '', entity_address: '', entity_type: 'INDIVIDUAL',
    first_name: '', last_name: '', middle_name: '',
    birthdate: '', birthplace: '', gender: '', civil_status: '', citizenship: '',
    profession: '', tin: '', sss: '', acr: '', religion: '', height: '', weight: '',
    date_registered: '', org_type: '', nature_of_business: '', place_registered: '',
    admin_name: '', admin_position: '', admin_address: ''
  };

  const [formData, setFormData] = useState(defaultFormData);
  const [formLoading, setFormLoading] = useState(false);
  const [formError, setFormError] = useState(null);

  // View Details Modal States
  const [viewDialogOpen, setViewDialogOpen] = useState(false);
  const [viewEntity, setViewEntity] = useState(null);

  // Delete Modal States
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);

  const fetchEntities = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const params = {
        page: page + 1,
        per_page: rowsPerPage,
      };
      if (searchQuery) params.q = searchQuery;

      const result = await etracsService.getEntities(params);
      setEntities(result.data || []);
      setTotal(result.total || 0);
    } catch (err) {
      setError(err.message || 'Failed to load entities');
      setEntities([]);
    } finally {
      setLoading(false);
    }
  }, [page, rowsPerPage, searchQuery]);

  useEffect(() => {
    fetchEntities();
  }, [fetchEntities]);

  const handleSearch = () => {
    setPage(0);
    setSearchQuery(searchInput);
  };

  const handleKeyDown = (e) => {
    if (e.key === 'Enter') handleSearch();
  };

  const openForm = (entity = null) => {
    if (entity) {
      setEditingId(entity.objid);
      setFormData({
        entity_name: entity.entity_name || '',
        entity_address: entity.entity_address || '',
        entity_type: entity.entity_type || 'INDIVIDUAL',
        first_name: entity.first_name || '',
        last_name: entity.last_name || '',
        middle_name: entity.middle_name || '',
        birthdate: entity.birthdate || '',
        birthplace: entity.birthplace || '',
        gender: entity.gender || '',
        civil_status: entity.civil_status || '',
        citizenship: entity.citizenship || '',
        profession: entity.profession || '',
        tin: entity.tin || '',
        sss: entity.sss || '',
        acr: entity.acr || '',
        religion: entity.religion || '',
        height: entity.height || '',
        weight: entity.weight || '',
        date_registered: entity.date_registered ? entity.date_registered.split(' ')[0] : '',
        org_type: entity.org_type || '',
        nature_of_business: entity.nature_of_business || '',
        place_registered: entity.place_registered || '',
        admin_name: entity.admin_name || '',
        admin_position: entity.admin_position || '',
        admin_address: entity.admin_address || ''
      });
    } else {
      setEditingId(null);
      setFormData(defaultFormData);
    }
    setFormError(null);
    setFormOpen(true);
  };

  const closeForm = () => {
    setFormOpen(false);
    setEditingId(null);
  };

  const handleFormChange = (field) => (e) => {
    setFormData((prev) => {
      const newData = { ...prev, [field]: e.target.value };
      // Auto-update entity_name if first_name/last_name change for INDIVIDUAL
      if (newData.entity_type === 'INDIVIDUAL' && (field === 'first_name' || field === 'last_name' || field === 'middle_name')) {
        const first = newData.first_name || '';
        const mid = newData.middle_name ? ` ${newData.middle_name}` : '';
        const last = newData.last_name || '';
        if (first || last) {
          newData.entity_name = `${first}${mid} ${last}`.trim().replace(/\s+/g, ' ');
        }
      }
      return newData;
    });
  };

  const handleSave = async () => {
    if (!formData.entity_name) {
      setFormError('Entity Name is required');
      return;
    }
    try {
      setFormLoading(true);
      setFormError(null);
      if (editingId) {
        await etracsService.updateEntity(editingId, formData);
        setToast({ open: true, message: 'Entity updated successfully', severity: 'success' });
      } else {
        await etracsService.createEntity(formData);
        setToast({ open: true, message: 'Entity created successfully', severity: 'success' });
      }
      closeForm();
      fetchEntities();
    } catch (err) {
      setFormError(err.message || 'Failed to save entity');
    } finally {
      setFormLoading(false);
    }
  };

  const confirmDelete = (entity) => {
    setDeleteTarget(entity);
    setDeleteDialogOpen(true);
  };

  const handleDelete = async () => {
    if (!deleteTarget) return;
    try {
      await etracsService.deleteEntity(deleteTarget.objid);
      setToast({ open: true, message: 'Entity deleted successfully', severity: 'success' });
      setDeleteDialogOpen(false);
      setDeleteTarget(null);
      fetchEntities();
    } catch (err) {
      setError(err.message || 'Failed to delete entity');
      setDeleteDialogOpen(false);
    }
  };

  return (
    <Box>
      <Box sx={{ mb: 3, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <Typography variant="h5" fontWeight={700} color="primary.main">
          Taxpayers (Entities)
        </Typography>
        {canEdit && (
          <Button
            variant="contained"
            startIcon={<AddIcon />}
            onClick={() => openForm()}
            sx={{ borderRadius: 2, textTransform: 'none', fontWeight: 600 }}
          >
            New Taxpayer
          </Button>
        )}
      </Box>

      {/* Search Bar */}
      <Paper sx={{ p: 2, mb: 2, borderRadius: 3 }}>
        <Grid container spacing={2} alignItems="center">
          <Grid item xs={12} md={6}>
            <TextField
              fullWidth
              size="small"
              placeholder="Search by name..."
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              onKeyDown={handleKeyDown}
              InputProps={{
                startAdornment: <SearchIcon sx={{ color: 'text.secondary', mr: 1 }} />,
              }}
            />
          </Grid>
          <Grid item xs={12} md={2}>
            <Button variant="contained" onClick={handleSearch} startIcon={<SearchIcon />} size="small" fullWidth>
              Search
            </Button>
          </Grid>
          <Grid item xs={12} md={1}>
            <Tooltip title="Refresh">
              <IconButton onClick={fetchEntities} size="small">
                <RefreshIcon />
              </IconButton>
            </Tooltip>
          </Grid>
        </Grid>
      </Paper>

      {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}

      {/* Table */}
      <TableContainer component={Paper} sx={{ borderRadius: 3, boxShadow: '0 2px 8px rgba(0,0,0,0.06)' }}>
        <Table size="small">
          <TableHead>
            <TableRow sx={{ background: '#f8fafc' }}>
              <TableCell sx={{ fontWeight: 600 }}>ID</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>Entity Name</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>Type</TableCell>
              <TableCell sx={{ fontWeight: 600 }}>Address</TableCell>
              <TableCell sx={{ fontWeight: 600, textAlign: 'center' }}>Actions</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {loading ? (
              <TableRow>
                <TableCell colSpan={5} align="center" sx={{ py: 6 }}>
                  <CircularProgress size={32} />
                  <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>Loading taxpayers...</Typography>
                </TableCell>
              </TableRow>
            ) : entities.length === 0 ? (
              <TableRow>
                <TableCell colSpan={5} align="center" sx={{ py: 6 }}>
                  <Typography variant="body1" color="text.secondary">
                    {searchQuery ? 'No taxpayers match your search' : 'No taxpayers found.'}
                  </Typography>
                </TableCell>
              </TableRow>
            ) : (
              entities.map((row) => (
                <motion.tr
                  key={row.objid}
                  component={TableRow}
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ duration: 0.2 }}
                  hover
                  sx={{ '&:hover': { backgroundColor: '#f8fafc' } }}
                >
                  <TableCell><Typography variant="body2" sx={{ fontFamily: 'monospace', color: 'text.secondary' }}>{row.objid ? row.objid.substring(0, 8) : '—'}</Typography></TableCell>
                  <TableCell>
                    <Typography 
                      variant="body2" 
                      fontWeight={600} 
                      color="primary.main"
                      sx={{ cursor: 'pointer', '&:hover': { textDecoration: 'underline' } }}
                      onClick={() => { setViewEntity(row); setViewDialogOpen(true); }}
                    >
                      {row.entity_name}
                    </Typography>
                  </TableCell>
                  <TableCell><Typography variant="body2">{row.entity_type}</Typography></TableCell>
                  <TableCell>
                    <Typography variant="body2" sx={{ maxWidth: 300, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                      {row.entity_address || '—'}
                    </Typography>
                  </TableCell>
                  <TableCell align="center">
                    {canEdit && (
                      <>
                        <Tooltip title="Edit">
                          <IconButton size="small" onClick={() => openForm(row)}>
                            <EditIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                        <Tooltip title="Delete">
                          <IconButton size="small" color="error" onClick={() => confirmDelete(row)}>
                            <DeleteIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      </>
                    )}
                  </TableCell>
                </motion.tr>
              ))
            )}
          </TableBody>
        </Table>
        <TablePagination
          component="div"
          count={total}
          page={page}
          onPageChange={(_, p) => setPage(p)}
          rowsPerPage={rowsPerPage}
          onRowsPerPageChange={(e) => { setRowsPerPage(parseInt(e.target.value, 10)); setPage(0); }}
          rowsPerPageOptions={[10, 20, 50, 100]}
        />
      </TableContainer>

      {/* Form Modal */}
      <Dialog open={formOpen} onClose={closeForm} maxWidth="md" fullWidth>
        <DialogTitle>{editingId ? 'Edit Taxpayer' : 'New Taxpayer'}</DialogTitle>
        <DialogContent dividers>
          {formError && <Alert severity="error" sx={{ mb: 2 }}>{formError}</Alert>}
          
          <Typography variant="subtitle2" color="primary" sx={{ mb: 2, mt: 1 }}>General Info</Typography>
          <Grid container spacing={2}>
            <Grid item xs={12} md={8}>
              <TextField
                fullWidth size="small" label="Entity Name *"
                value={formData.entity_name}
                onChange={handleFormChange('entity_name')}
              />
            </Grid>
            <Grid item xs={12} md={4}>
              <FormControl fullWidth size="small">
                <InputLabel>Entity Type</InputLabel>
                <Select value={formData.entity_type} onChange={handleFormChange('entity_type')} label="Entity Type">
                  {entityTypes.map(t => <MenuItem key={t} value={t}>{t}</MenuItem>)}
                </Select>
              </FormControl>
            </Grid>
            <Grid item xs={12}>
              <TextField
                fullWidth size="small" label="Full Address" multiline rows={2}
                value={formData.entity_address}
                onChange={handleFormChange('entity_address')}
              />
            </Grid>
          </Grid>

          {formData.entity_type === 'INDIVIDUAL' && (
            <>
              <Typography variant="subtitle2" color="primary" sx={{ mb: 2, mt: 3 }}>Individual Details</Typography>
              <Grid container spacing={2}>
                <Grid item xs={12} sm={4}>
                  <TextField fullWidth size="small" label="First Name" value={formData.first_name} onChange={handleFormChange('first_name')} />
                </Grid>
                <Grid item xs={12} sm={4}>
                  <TextField fullWidth size="small" label="Middle Name" value={formData.middle_name} onChange={handleFormChange('middle_name')} />
                </Grid>
                <Grid item xs={12} sm={4}>
                  <TextField fullWidth size="small" label="Last Name" value={formData.last_name} onChange={handleFormChange('last_name')} />
                </Grid>
                
                <Grid item xs={12} sm={4}>
                  <TextField fullWidth size="small" type="date" label="Birth Date" InputLabelProps={{ shrink: true }} value={formData.birthdate} onChange={handleFormChange('birthdate')} />
                </Grid>
                <Grid item xs={12} sm={8}>
                  <TextField fullWidth size="small" label="Birth Place" value={formData.birthplace} onChange={handleFormChange('birthplace')} />
                </Grid>

                <Grid item xs={12} sm={4}>
                  <FormControl fullWidth size="small">
                    <InputLabel>Gender</InputLabel>
                    <Select value={formData.gender} onChange={handleFormChange('gender')} label="Gender">
                      <MenuItem value=""><em>None</em></MenuItem>
                      <MenuItem value="M">Male</MenuItem>
                      <MenuItem value="F">Female</MenuItem>
                    </Select>
                  </FormControl>
                </Grid>
                <Grid item xs={12} sm={4}>
                  <FormControl fullWidth size="small">
                    <InputLabel>Civil Status</InputLabel>
                    <Select value={formData.civil_status} onChange={handleFormChange('civil_status')} label="Civil Status">
                      <MenuItem value=""><em>None</em></MenuItem>
                      <MenuItem value="SINGLE">Single</MenuItem>
                      <MenuItem value="MARRIED">Married</MenuItem>
                      <MenuItem value="WIDOWED">Widowed</MenuItem>
                      <MenuItem value="DIVORCED">Divorced</MenuItem>
                    </Select>
                  </FormControl>
                </Grid>
                <Grid item xs={12} sm={4}>
                  <TextField fullWidth size="small" label="Citizenship" value={formData.citizenship} onChange={handleFormChange('citizenship')} />
                </Grid>

                <Grid item xs={12} sm={4}>
                  <TextField fullWidth size="small" label="TIN" value={formData.tin} onChange={handleFormChange('tin')} />
                </Grid>
                <Grid item xs={12} sm={4}>
                  <TextField fullWidth size="small" label="SSS" value={formData.sss} onChange={handleFormChange('sss')} />
                </Grid>
                <Grid item xs={12} sm={4}>
                  <TextField fullWidth size="small" label="Profession" value={formData.profession} onChange={handleFormChange('profession')} />
                </Grid>
              </Grid>
            </>
          )}

          {['CORPORATION', 'MULTIPLE', 'GOVERNMENT'].includes(formData.entity_type) && (
            <>
              <Typography variant="subtitle2" color="primary" sx={{ mb: 2, mt: 3 }}>Juridical Details</Typography>
              <Grid container spacing={2}>
                <Grid item xs={12} sm={6}>
                  <TextField fullWidth size="small" label="TIN" value={formData.tin} onChange={handleFormChange('tin')} />
                </Grid>
                <Grid item xs={12} sm={6}>
                  <TextField fullWidth size="small" type="date" label="Date Registered" InputLabelProps={{ shrink: true }} value={formData.date_registered} onChange={handleFormChange('date_registered')} />
                </Grid>
                <Grid item xs={12} sm={6}>
                  <TextField fullWidth size="small" label="Org Type" value={formData.org_type} onChange={handleFormChange('org_type')} />
                </Grid>
                <Grid item xs={12} sm={6}>
                  <TextField fullWidth size="small" label="Nature of Business" value={formData.nature_of_business} onChange={handleFormChange('nature_of_business')} />
                </Grid>
                <Grid item xs={12}>
                  <TextField fullWidth size="small" label="Place Registered" value={formData.place_registered} onChange={handleFormChange('place_registered')} />
                </Grid>

                <Grid item xs={12}><Typography variant="caption" color="text.secondary">Administrator</Typography></Grid>
                <Grid item xs={12} sm={6}>
                  <TextField fullWidth size="small" label="Name" value={formData.admin_name} onChange={handleFormChange('admin_name')} />
                </Grid>
                <Grid item xs={12} sm={6}>
                  <TextField fullWidth size="small" label="Position" value={formData.admin_position} onChange={handleFormChange('admin_position')} />
                </Grid>
                <Grid item xs={12}>
                  <TextField fullWidth size="small" label="Address" value={formData.admin_address} onChange={handleFormChange('admin_address')} />
                </Grid>
              </Grid>
            </>
          )}

        </DialogContent>
        <DialogActions>
          <Button onClick={closeForm}>Cancel</Button>
          <Button variant="contained" onClick={handleSave} disabled={formLoading}>
            {formLoading ? 'Saving...' : 'Save'}
          </Button>
        </DialogActions>
      </Dialog>

      {/* View Details Modal */}
      <Dialog open={viewDialogOpen} onClose={() => setViewDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle sx={{ fontWeight: 600 }}>Taxpayer Profile</DialogTitle>
        <DialogContent dividers>
          {viewEntity && (
            <Grid container spacing={2}>
              <Grid item xs={12}>
                <Typography variant="h6" color="primary.main">{viewEntity.entity_name}</Typography>
                <Typography variant="body2" color="text.secondary">{viewEntity.entity_address || 'No address specified'}</Typography>
                <Typography variant="caption" sx={{ display: 'inline-block', mt: 1, px: 1, py: 0.5, bgcolor: '#e2e8f0', borderRadius: 1, fontWeight: 600 }}>
                  {viewEntity.entity_type}
                </Typography>
              </Grid>

              <Grid item xs={12}><Typography variant="subtitle2" sx={{ mt: 1 }}>Details</Typography></Grid>
              
              {viewEntity.entity_type === 'INDIVIDUAL' ? (
                <>
                  <Grid item xs={6} sm={4}><Typography variant="caption" color="text.secondary">TIN</Typography><Typography variant="body2">{viewEntity.tin || '—'}</Typography></Grid>
                  <Grid item xs={6} sm={4}><Typography variant="caption" color="text.secondary">Birth Date</Typography><Typography variant="body2">{viewEntity.birthdate || '—'}</Typography></Grid>
                  <Grid item xs={6} sm={4}><Typography variant="caption" color="text.secondary">Gender</Typography><Typography variant="body2">{viewEntity.gender || '—'}</Typography></Grid>
                  <Grid item xs={6} sm={4}><Typography variant="caption" color="text.secondary">Civil Status</Typography><Typography variant="body2">{viewEntity.civil_status || '—'}</Typography></Grid>
                  <Grid item xs={6} sm={4}><Typography variant="caption" color="text.secondary">Citizenship</Typography><Typography variant="body2">{viewEntity.citizenship || '—'}</Typography></Grid>
                  <Grid item xs={6} sm={4}><Typography variant="caption" color="text.secondary">Profession</Typography><Typography variant="body2">{viewEntity.profession || '—'}</Typography></Grid>
                </>
              ) : (
                <>
                  <Grid item xs={6} sm={4}><Typography variant="caption" color="text.secondary">TIN</Typography><Typography variant="body2">{viewEntity.tin || '—'}</Typography></Grid>
                  <Grid item xs={6} sm={4}><Typography variant="caption" color="text.secondary">Date Registered</Typography><Typography variant="body2">{viewEntity.date_registered ? viewEntity.date_registered.split(' ')[0] : '—'}</Typography></Grid>
                  <Grid item xs={6} sm={4}><Typography variant="caption" color="text.secondary">Org Type</Typography><Typography variant="body2">{viewEntity.org_type || '—'}</Typography></Grid>
                  <Grid item xs={6} sm={4}><Typography variant="caption" color="text.secondary">Nature of Bus.</Typography><Typography variant="body2">{viewEntity.nature_of_business || '—'}</Typography></Grid>
                  
                  <Grid item xs={12}><Typography variant="subtitle2" sx={{ mt: 1 }}>Administrator</Typography></Grid>
                  <Grid item xs={6} sm={6}><Typography variant="caption" color="text.secondary">Name</Typography><Typography variant="body2">{viewEntity.admin_name || '—'}</Typography></Grid>
                  <Grid item xs={6} sm={6}><Typography variant="caption" color="text.secondary">Position</Typography><Typography variant="body2">{viewEntity.admin_position || '—'}</Typography></Grid>
                </>
              )}
            </Grid>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setViewDialogOpen(false)}>Close</Button>
        </DialogActions>
      </Dialog>

      {/* Delete Confirmation Modal */}
      <Dialog open={deleteDialogOpen} onClose={() => setDeleteDialogOpen(false)}>
        <DialogTitle>Delete Taxpayer?</DialogTitle>
        <DialogContent>
          <Typography>
            Are you sure you want to delete <strong>{deleteTarget?.entity_name}</strong>? This action cannot be undone and will fail if the taxpayer is currently assigned to a property.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeleteDialogOpen(false)}>Cancel</Button>
          <Button onClick={handleDelete} color="error" variant="contained">Delete</Button>
        </DialogActions>
      </Dialog>

      <Snackbar
        open={toast.open}
        autoHideDuration={3000}
        onClose={() => setToast((prev) => ({ ...prev, open: false }))}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
      >
        <Alert severity={toast.severity} sx={{ width: '100%' }}>{toast.message}</Alert>
      </Snackbar>
    </Box>
  );
};

export default EtracsEntityTable;
