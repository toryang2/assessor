import React, { useState, useEffect, useRef } from 'react';
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
  Grid,
  Card,
  CardContent,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  Chip,
  IconButton,
  Dialog,
  DialogTitle,
  CircularProgress,
  DialogContent,
  DialogActions,
  Alert,
  Snackbar,
  List,
  ListItem,
  ListItemText,
  ListItemIcon,
  Divider,
  Switch,
  FormControlLabel
} from '@mui/material';
import {
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Lock as LockIcon,
  LockOpen as LockOpenIcon,
  Person as PersonIcon,
  AdminPanelSettings as AdminIcon,
  Security as SecurityIcon,
  Refresh as RefreshIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { format } from 'date-fns';

import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';
import { keyframes } from '@mui/system';
import LoadingDots from '../LoadingDots';

const glow = keyframes`
  0% { filter: drop-shadow(0 0 0px rgba(156, 39, 176, 0.0)); }
  50% { filter: drop-shadow(0 0 10px rgba(156, 39, 176, 0.75)) drop-shadow(0 0 4px rgba(255,255,255,0.45)); }
  100% { filter: drop-shadow(0 0 0px rgba(156, 39, 176, 0.0)); }
`;

const sweep = keyframes`
  0% { transform: translateX(-120%); }
  100% { transform: translateX(120%); }
`;

const UserManagement = () => {
  const { canManage, isSuperAdmin } = useAuth();
  const effectiveIsSuperAdmin = (() => {
    if (isSuperAdmin) return true;
    try {
      const raw = localStorage.getItem('assessor_user');
      if (!raw) return false;
      const u = JSON.parse(raw);
      return String(u?.role || '').toLowerCase() === 'superadmin';
    } catch (_) { return false; }
  })();
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [initialLoad, setInitialLoad] = useState(true);
  const [error, setError] = useState('');
  const [toast, setToast] = useState({ open: false, message: '', severity: 'success' });
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [totalCount, setTotalCount] = useState(0);
  const fetchSeqRef = useRef(0);
  
  // Search and filter states
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState({
    role: 'all',
    status: 'all'
  });
  
  // Modal states
  const [userModal, setUserModal] = useState(false);
  const [selectedUser, setSelectedUser] = useState(null);
  const [deleteDialog, setDeleteDialog] = useState(false);
  const [userToDelete, setUserToDelete] = useState(null);
  const [statusDialog, setStatusDialog] = useState(false);
  const [userToToggle, setUserToToggle] = useState(null);

  useEffect(() => {
    if (canManage) {
      fetchUsers();
    }
  }, [canManage, page, rowsPerPage, searchTerm, filters]);

  const fetchUsers = async () => {
    const seq = ++fetchSeqRef.current;
    try {
      setLoading(true);
      setError('');
      
      const params = {
        page: page + 1,
        per_page: rowsPerPage,
        q: searchTerm || '',
        role: filters.role !== 'all' ? filters.role : '',
        status: filters.status !== 'all' ? filters.status : '',
        // Add cache busting timestamp to prevent browser caching
        _t: Date.now()
      };
      
      const response = await apiService.getUsers(params);
      // Ignore if a newer request has started
      if (seq !== fetchSeqRef.current) return;
      
      if (response && response.users) {
        setUsers(response.users);
        setTotalCount(response.pagination ? response.pagination.total : response.users.length);
      } else if (response && response.data) {
        // Fallback for different response format
        setUsers(response.data);
        setTotalCount(response.total || response.data.length);
      } else {
        console.warn('Unexpected API response format:', response);
        setUsers([]);
        setTotalCount(0);
      }
    } catch (err) {
      console.error('Error fetching users:', err);
      setError(`Failed to fetch users: ${err.message || 'Unknown error'}`);
      setUsers([]);
      setTotalCount(0);
    } finally {
      // Only clear loading for the latest request
      if (seq === fetchSeqRef.current) setLoading(false);
      setInitialLoad(false);
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

  const handleAddUser = () => {
    setSelectedUser(null);
    setUserModal(true);
  };

  const handleEditUser = (user) => {
    setSelectedUser(user);
    setUserModal(true);
  };

  const handleDeleteUser = (user) => {
    setUserToDelete(user);
    setDeleteDialog(true);
  };

  const handleToggleStatus = (user) => {
    setUserToToggle(user);
    setStatusDialog(true);
  };

  const confirmDelete = async () => {
    try {
      await apiService.deleteUser(userToDelete.id);
      setDeleteDialog(false);
      setUserToDelete(null);
      fetchUsers();
      setToast({ open: true, message: 'User deleted successfully', severity: 'success' });
    } catch (err) {
      setError('Failed to delete user');
      setToast({ open: true, message: 'Failed to delete user', severity: 'error' });
    }
  };

  const confirmToggleStatus = async () => {
    try {
      const newStatus = userToToggle.status === 'active' ? 'inactive' : 'active';
      await apiService.updateUser(userToToggle.id, { status: newStatus });
      setStatusDialog(false);
      setUserToToggle(null);
      fetchUsers();
      setToast({ open: true, message: `User ${newStatus === 'active' ? 'activated' : 'deactivated'} successfully`, severity: 'success' });
    } catch (err) {
      setError('Failed to update user status');
      setToast({ open: true, message: 'Failed to update user status', severity: 'error' });
    }
  };

  const handleUserSaved = () => {
    setUserModal(false);
    setSelectedUser(null);
    fetchUsers();
  };

  const getRoleColor = (role) => {
    switch (role) {
      // For custom gold styles we return default color and style via sx
      case 'superadmin':
        return 'default';
      case 'admin':
        return 'error';
      case 'assessor':
        return 'primary';
      case 'verifier':
        return 'primary';
      case 'editor':
        return 'secondary';
      case 'viewer':
        return 'info';
      default:
        return 'default';
    }
  };

  const getRoleChipProps = (role) => {
    // Default props
    const base = { color: getRoleColor(role), sx: {}, icon: null };
    if (role === 'superadmin') {
      return {
        ...base,
        color: 'default',
        icon: <AdminIcon />,
        sx: {
          backgroundImage: 'linear-gradient(135deg, #E1BEE7 0%, #CE93D8 40%, #BA68C8 70%, #9C27B0 100%)',
          animation: `${glow} 2.2s ease-in-out infinite`,
          color: '#fff',
          fontWeight: 700,
          boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.6)',
          position: 'relative',
          overflow: 'hidden',
          willChange: 'filter',
          '&::before': {
            content: '""',
            position: 'absolute',
            top: '-20%',
            left: '-50%',
            width: '200%',
            height: '140%',
            background: 'linear-gradient(120deg, rgba(255,255,255,0.0) 45%, rgba(255,255,255,0.45) 50%, rgba(255,255,255,0.0) 55%)',
            animation: `${sweep} 2.4s ease-in-out infinite`,
            pointerEvents: 'none',
          },
          '& .MuiChip-icon': { color: '#fff' },
        },
      };
    }
    if (role === 'admin') {
      return { 
        ...base, 
        color: getRoleColor(role), 
        icon: <AdminIcon />,
        sx: {
          position: 'relative',
          overflow: 'hidden',
          // Glass gradient overlay
          '&::after': {
            content: '""',
            position: 'absolute',
            inset: 0,
            background: 'linear-gradient(145deg, rgba(255,255,255,0.16) 0%, rgba(255,255,255,0.06) 35%, rgba(255,255,255,0.0) 60%)',
            pointerEvents: 'none',
          },
          // Moving highlight sweep
          '&::before': {
            content: '""',
            position: 'absolute',
            top: '-20%',
            left: '-50%',
            width: '200%',
            height: '140%',
            background: 'linear-gradient(120deg, rgba(255,255,255,0.0) 45%, rgba(255,255,255,0.35) 50%, rgba(255,255,255,0.0) 55%)',
            animation: `${sweep} 2.8s ease-in-out infinite`,
            pointerEvents: 'none',
          },
          '& .MuiChip-icon': { color: 'inherit' },
        }
      };
    }
    if (role === 'editor') {
      return { ...base, icon: <SecurityIcon /> };
    }
    if (role === 'assessor' || role === 'verifier' || role === 'viewer') {
      return { ...base, icon: <SecurityIcon /> };
    }
    return base;
  };

  const getStatusColor = (status) => {
    return status === 'active' ? 'success' : 'warning';
  };

  const getRoleDisplayName = (role) => {
    const roleNames = {
      'superadmin': 'Super Administrator',
      'admin': 'Administrator',
      'assessor': 'Municipal Assessor',
      'verifier': 'Verifier',
      'editor': 'Editor',
      'viewer': 'View Only'
    };
    return roleNames[role] || role;
  };

  const clearFilters = () => {
    setFilters({
      role: 'all',
      status: 'all'
    });
    setSearchTerm('');
    setPage(0);
  };

  if (!canManage) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <Typography variant="h6" color="error">
          Access Denied: Only Administrators and Municipal Assessors can access this page
        </Typography>
      </Box>
    );
  }

  if (initialLoad && loading && users.length === 0) {
    return (
      <Box sx={{ 
        display: 'flex', 
        flexDirection: 'column',
        justifyContent: 'center', 
        alignItems: 'center', 
        minHeight: '70vh',
        gap: 2
      }}>
        <CircularProgress size={50} thickness={4} />
        <Typography variant="h6" color="text.secondary">
          Loading users<LoadingDots />
        </Typography>
        {/* <Typography variant="body2" color="text.secondary">
          Please wait while the system loads
        </Typography> */}
      </Box>
    );
  }

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
        <Typography variant="h4" gutterBottom>
          User Management
        </Typography>

      <Snackbar
        open={!!error}
        autoHideDuration={4000}
        onClose={() => setError('')}
        anchorOrigin={{ vertical: 'top', horizontal: 'center' }}
      >
        <Alert onClose={() => setError('')} severity="error" sx={{ width: '100%' }}>
          {error}
        </Alert>
      </Snackbar>
      <Snackbar
        open={toast.open}
        autoHideDuration={3000}
        onClose={() => setToast(prev => ({ ...prev, open: false }))}
        anchorOrigin={{ vertical: 'top', horizontal: 'center' }}
      >
        <Alert onClose={() => setToast(prev => ({ ...prev, open: false }))} severity={toast.severity} sx={{ width: '100%' }}>
          {toast.message}
        </Alert>
      </Snackbar>

      {/* Search and Filters */}
      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Grid container spacing={2} alignItems="center">
            <Grid item xs={12} md={4}>
              <TextField
                fullWidth
                label="Search Users"
                value={searchTerm}
                onChange={handleSearch}
                placeholder="Search by username, email, or name..."
              />
            </Grid>
            
            <Grid item xs={12} md={2}>
              <FormControl fullWidth>
                <InputLabel shrink>Role</InputLabel>
                <Select
                  value={filters.role}
                  label="Role"
                  onChange={(e) => handleFilterChange('role', e.target.value)}
                  displayEmpty
                  renderValue={(selected) => (selected && selected !== 'all' ? getRoleDisplayName(selected) : 'All')}
                >
                  <MenuItem value="all">All</MenuItem>
                  {(effectiveIsSuperAdmin) && (
                    <MenuItem value="admin">Administrator</MenuItem>
                  )}
                  <MenuItem value="assessor">Municipal Assessor</MenuItem>
                  <MenuItem value="verifier">Verifier</MenuItem>
                  <MenuItem value="editor">Editor</MenuItem>
                  <MenuItem value="viewer">View Only</MenuItem>
                </Select>
              </FormControl>
            </Grid>

            <Grid item xs={12} md={2}>
              <FormControl fullWidth>
                <InputLabel shrink>Status</InputLabel>
                <Select
                  value={filters.status}
                  label="Status"
                  onChange={(e) => handleFilterChange('status', e.target.value)}
                  displayEmpty
                  renderValue={(selected) => (selected && selected !== 'all' ? selected.charAt(0).toUpperCase() + selected.slice(1) : 'All')}
                >
                  <MenuItem value="all">All</MenuItem>
                  <MenuItem value="active">Active</MenuItem>
                  <MenuItem value="inactive">Inactive</MenuItem>
                </Select>
              </FormControl>
            </Grid>

            <Grid item xs={12} md={2}>
              <Button
                variant="outlined"
                startIcon={<RefreshIcon />}
                onClick={clearFilters}
                sx={{ mr: 1 }}
              >
                Clear Filters
              </Button>
            </Grid>

            <Grid item xs={12} md={2} textAlign="right">
              <Button variant="contained" startIcon={<AddIcon />} onClick={handleAddUser} color="primary">
                Add User
              </Button>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* Users Table */}
      <Paper sx={{ width: '100%', overflow: 'hidden' }}>
        <TableContainer sx={{ height: { xs: 'calc(100vh - 360px)', md: 'calc(100vh - 320px)' }, overflow: 'auto' }}>
          <Table stickyHeader sx={{ tableLayout: 'fixed' }}>
            <colgroup>
              <col style={{ width: '300px' }} />
              <col style={{ width: '160px' }} />
              <col style={{ width: '120px' }} />
              <col style={{ width: '180px' }} />
              <col style={{ width: '180px' }} />
              <col style={{ width: '140px' }} />
            </colgroup>
            <TableHead>
              <TableRow>
                <TableCell sx={{ width: 300 }}>User</TableCell>
                <TableCell sx={{ width: 160 }}>Role</TableCell>
                <TableCell sx={{ width: 120 }}>Status</TableCell>
                <TableCell sx={{ width: 180 }}>Last Login</TableCell>
                <TableCell sx={{ width: 180 }}>Created</TableCell>
                <TableCell sx={{ width: 140 }}>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {users && users.length > 0 ? users.map((user) => (
                <TableRow key={user.id} hover>
                  <TableCell sx={{ verticalAlign: 'top' }}>
                    <Box display="flex" alignItems="center">
                      <PersonIcon sx={{ mr: 1, color: 'primary.main' }} />
                      <Box>
                        <Typography variant="body2" fontWeight={700}>
                          {user.username}
                          {user.full_name ? (
                            <Typography component="span" variant="caption" color="text.secondary" sx={{ ml: 1, fontWeight: 400 }}>
                              ({user.full_name})
                            </Typography>
                          ) : null}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {user.email || ''}
                          </Typography>
                      </Box>
                    </Box>
                  </TableCell>
                  <TableCell sx={{ verticalAlign: 'top' }}>
                    {(() => {
                      const chip = getRoleChipProps(user.role);
                      return (
                    <Chip
                      label={getRoleDisplayName(user.role)}
                      size="small"
                          color={chip.color}
                          icon={chip.icon}
                          sx={chip.sx}
                    />
                      );
                    })()}
                  </TableCell>
                  <TableCell sx={{ verticalAlign: 'top' }}>
                    <Chip
                      label={user.status}
                      size="small"
                      color={getStatusColor(user.status)}
                      icon={user.status === 'active' ? <LockOpenIcon /> : <LockIcon />}
                    />
                  </TableCell>
                  <TableCell sx={{ verticalAlign: 'top' }}>
                    <Typography variant="body2">
                      {user.last_login 
                        ? format(new Date(user.last_login), 'MMM dd, yyyy HH:mm a')
                        : 'Never'
                      }
                    </Typography>
                  </TableCell>
                  <TableCell sx={{ verticalAlign: 'top' }}>
                    <Typography variant="body2">
                      {format(new Date(user.created_at), 'MMM dd, yyyy HH:mm a')}
                    </Typography>
                  </TableCell>
                  <TableCell sx={{ verticalAlign: 'top' }}>
                    <Box display="flex" gap={1}>
                      {/* Disable edit/status/delete for superadmin unless current user is superadmin */}
                      <IconButton
                        size="small"
                        onClick={() => handleEditUser(user)}
                        color="primary"
                        disabled={user.role === 'superadmin'}
                      >
                        <EditIcon />
                      </IconButton>
                      
                      <IconButton
                        size="small"
                        onClick={() => handleToggleStatus(user)}
                        color={user.status === 'active' ? 'warning' : 'success'}
                        disabled={user.role === 'superadmin'}
                      >
                        {user.status === 'active' ? <LockIcon /> : <LockOpenIcon />}
                      </IconButton>
                      
                      {(user.role !== 'superadmin') && (effectiveIsSuperAdmin || user.role !== 'admin') && (
                        <IconButton
                          size="small"
                          onClick={() => handleDeleteUser(user)}
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
                  <TableCell colSpan={6} align="center">
                    <Typography variant="body2" color="text.secondary">
                      {loading ? 'Loading users...' : 'No users found'}
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

      {/* User Form Modal */}
      <Dialog
        open={userModal}
        onClose={() => setUserModal(false)}
        maxWidth="md"
        fullWidth
      >
        <DialogTitle>
          {selectedUser ? 'Edit User' : 'Add New User'}
        </DialogTitle>
        <DialogContent>
          <Grid container spacing={2} sx={{ mt: 0 }}>
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                label="Username"
                value={selectedUser?.username || ''}
                onChange={(e) => setSelectedUser(prev => ({ ...(prev||{}), username: e.target.value }))}
              />
            </Grid>
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                label="Email"
                type="email"
                value={selectedUser?.email || ''}
                onChange={(e) => setSelectedUser(prev => ({ ...(prev||{}), email: e.target.value }))}
              />
            </Grid>
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                label="Full Name"
                value={selectedUser?.full_name || ''}
                onChange={(e) => setSelectedUser(prev => ({ ...(prev||{}), full_name: e.target.value }))}
              />
            </Grid>
            <Grid item xs={12} md={6}>
              <FormControl fullWidth>
                <InputLabel>Role</InputLabel>
                <Select
                  value={selectedUser?.role || 'assessor'}
                  label="Role"
                  onChange={(e) => setSelectedUser(prev => ({ ...(prev||{}), role: e.target.value }))}
                >
                  {(effectiveIsSuperAdmin || (selectedUser && selectedUser.role === 'admin')) && (
                    <MenuItem value="admin" disabled={!effectiveIsSuperAdmin && selectedUser && selectedUser.role === 'admin'}>
                      Administrator
                    </MenuItem>
                  )}
                  <MenuItem value="assessor">Municipal Assessor</MenuItem>
                  <MenuItem value="verifier">Verifier</MenuItem>
                  <MenuItem value="editor">Editor</MenuItem>
                  <MenuItem value="viewer">View Only</MenuItem>
                </Select>
              </FormControl>
            </Grid>
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                label={selectedUser && selectedUser.id ? 'New Password (optional)' : 'Password'}
                type="password"
                value={selectedUser?.password || ''}
                onChange={(e) => setSelectedUser(prev => ({ ...(prev||{}), password: e.target.value }))}
              />
            </Grid>
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                label={selectedUser && selectedUser.id ? 'Confirm New Password' : 'Confirm Password'}
                type="password"
                value={selectedUser?.password_confirm || ''}
                onChange={(e) => setSelectedUser(prev => ({ ...(prev||{}), password_confirm: e.target.value }))}
              />
            </Grid>
            <Grid item xs={12} md={6}>
              <FormControl fullWidth>
                <InputLabel>Status</InputLabel>
                <Select
                  value={selectedUser?.status || 'active'}
                  label="Status"
                  onChange={(e) => setSelectedUser(prev => ({ ...(prev||{}), status: e.target.value }))}
                >
                  <MenuItem value="active">Active</MenuItem>
                  <MenuItem value="inactive">Inactive</MenuItem>
                </Select>
              </FormControl>
            </Grid>
          </Grid>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setUserModal(false)}>Cancel</Button>
          <Button variant="contained" onClick={async () => {
            try {
              const payload = {
                username: selectedUser?.username || '',
                email: selectedUser?.email || '',
                full_name: selectedUser?.full_name || '',
                role: selectedUser?.role || 'assessor',
                status: selectedUser?.status || 'active',
              };
              if (!selectedUser?.id || selectedUser?.password) {
                payload.password = selectedUser?.password || '';
                payload.password_confirm = selectedUser?.password_confirm || '';
              }
              if (selectedUser?.id) {
                await apiService.updateUser(selectedUser.id, payload);
                setError('');
                setToast({ open: true, message: 'User updated successfully', severity: 'success' });
              } else {
                await apiService.createUser(payload);
                setError('');
                setToast({ open: true, message: 'User created successfully', severity: 'success' });
              }
              handleUserSaved();
            } catch (e) {
              setError(e.message || 'Failed to save user');
              setToast({ open: true, message: e.message || 'Failed to save user', severity: 'error' });
            }
          }}>
            Save
          </Button>
        </DialogActions>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialog} onClose={() => setDeleteDialog(false)}>
        <DialogTitle>Confirm User Deletion</DialogTitle>
        <DialogContent>
          <Typography>
            Are you sure you want to delete the user "{userToDelete?.username}"?
            This action cannot be undone and will remove all associated data.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeleteDialog(false)}>Cancel</Button>
          <Button onClick={confirmDelete} color="error" variant="contained">
            Delete User
          </Button>
        </DialogActions>
      </Dialog>

      {/* Status Toggle Confirmation Dialog */}
      <Dialog open={statusDialog} onClose={() => setStatusDialog(false)}>
        <DialogTitle>Confirm Status Change</DialogTitle>
        <DialogContent>
          <Typography>
            Are you sure you want to {userToToggle?.status === 'active' ? 'deactivate' : 'activate'} the user "{userToToggle?.username}"?
            {userToToggle?.status === 'active' 
              ? ' This will prevent them from accessing the system.'
              : ' This will restore their access to the system.'
            }
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setStatusDialog(false)}>Cancel</Button>
          <Button onClick={confirmToggleStatus} color="warning" variant="contained">
            {userToToggle?.status === 'active' ? 'Deactivate' : 'Activate'}
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default UserManagement;
