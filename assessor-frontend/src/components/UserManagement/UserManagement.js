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
  DialogContent,
  DialogActions,
  Alert,
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

const UserManagement = () => {
  const { isAdmin } = useAuth();
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [totalCount, setTotalCount] = useState(0);
  
  // Search and filter states
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState({
    role: '',
    status: ''
  });
  
  // Modal states
  const [userModal, setUserModal] = useState(false);
  const [selectedUser, setSelectedUser] = useState(null);
  const [deleteDialog, setDeleteDialog] = useState(false);
  const [userToDelete, setUserToDelete] = useState(null);
  const [statusDialog, setStatusDialog] = useState(false);
  const [userToToggle, setUserToToggle] = useState(null);

  useEffect(() => {
    if (isAdmin) {
      fetchUsers();
    }
  }, [isAdmin, page, rowsPerPage, searchTerm, filters]);

  const fetchUsers = async () => {
    try {
      setLoading(true);
      const params = {
        page: page + 1,
        per_page: rowsPerPage,
        search: searchTerm,
        ...filters
      };
      
      const response = await apiService.getUsers(params);
      const list = response.users || response.data || [];
      setUsers(list);
      setTotalCount(response.total || list.length);
    } catch (err) {
      setError('Failed to fetch users');
      console.error('Error fetching users:', err);
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
    } catch (err) {
      setError('Failed to delete user');
    }
  };

  const confirmToggleStatus = async () => {
    try {
      const newStatus = userToToggle.status === 'active' ? 'inactive' : 'active';
      await apiService.updateUser(userToToggle.id, { status: newStatus });
      setStatusDialog(false);
      setUserToToggle(null);
      fetchUsers();
    } catch (err) {
      setError('Failed to update user status');
    }
  };

  const handleUserSaved = () => {
    setUserModal(false);
    setSelectedUser(null);
    fetchUsers();
  };

  const getRoleColor = (role) => {
    switch (role) {
      case 'admin':
        return 'error';
      case 'assessor':
        return 'primary';
      case 'viewer':
        return 'info';
      default:
        return 'default';
    }
  };

  const getStatusColor = (status) => {
    return status === 'active' ? 'success' : 'warning';
  };

  const getRoleDisplayName = (role) => {
    const roleNames = {
      'admin': 'Administrator',
      'assessor': 'Property Assessor',
      'viewer': 'View Only'
    };
    return roleNames[role] || role;
  };

  const clearFilters = () => {
    setFilters({
      role: '',
      status: ''
    });
    setSearchTerm('');
    setPage(0);
  };

  if (!isAdmin) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <Typography variant="h6" color="error">
          Access Denied: Admin privileges required
        </Typography>
      </Box>
    );
  }

  if (loading && users.length === 0) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <Typography>Loading users...</Typography>
      </Box>
    );
  }

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      <Typography variant="h4" gutterBottom>
        User Management
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
                <InputLabel>Role</InputLabel>
                <Select
                  value={filters.role}
                  label="Role"
                  onChange={(e) => handleFilterChange('role', e.target.value)}
                >
                  <MenuItem value="">All</MenuItem>
                  <MenuItem value="admin">Administrator</MenuItem>
                  <MenuItem value="assessor">Property Assessor</MenuItem>
                  <MenuItem value="viewer">View Only</MenuItem>
                </Select>
              </FormControl>
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
              <Button
                variant="contained"
                startIcon={<AddIcon />}
                onClick={handleAddUser}
                color="primary"
              >
                Add User
              </Button>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* Users Table */}
      <Paper sx={{ width: '100%', overflow: 'hidden' }}>
        <TableContainer>
          <Table stickyHeader>
            <TableHead>
              <TableRow>
                <TableCell>User</TableCell>
                <TableCell>Role</TableCell>
                <TableCell>Status</TableCell>
                <TableCell>Last Login</TableCell>
                <TableCell>Created</TableCell>
                <TableCell>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {users.map((user) => (
                <TableRow key={user.id} hover>
                  <TableCell>
                    <Box display="flex" alignItems="center">
                      <PersonIcon sx={{ mr: 1, color: 'primary.main' }} />
                      <Box>
                        <Typography variant="body2" fontWeight={600}>
                          {user.username}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {user.email}
                        </Typography>
                        {user.first_name && user.last_name && (
                          <Typography variant="caption" display="block" color="text.secondary">
                            {user.first_name} {user.last_name}
                          </Typography>
                        )}
                      </Box>
                    </Box>
                  </TableCell>
                  <TableCell>
                    <Chip
                      label={getRoleDisplayName(user.role)}
                      size="small"
                      color={getRoleColor(user.role)}
                      icon={user.role === 'admin' ? <AdminIcon /> : <SecurityIcon />}
                    />
                  </TableCell>
                  <TableCell>
                    <Chip
                      label={user.status}
                      size="small"
                      color={getStatusColor(user.status)}
                      icon={user.status === 'active' ? <LockOpenIcon /> : <LockIcon />}
                    />
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">
                      {user.last_login 
                        ? format(new Date(user.last_login), 'MMM dd, yyyy HH:mm')
                        : 'Never'
                      }
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">
                      {format(new Date(user.created_at), 'MMM dd, yyyy')}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Box display="flex" gap={1}>
                      <IconButton
                        size="small"
                        onClick={() => handleEditUser(user)}
                        color="primary"
                      >
                        <EditIcon />
                      </IconButton>
                      
                      <IconButton
                        size="small"
                        onClick={() => handleToggleStatus(user)}
                        color={user.status === 'active' ? 'warning' : 'success'}
                      >
                        {user.status === 'active' ? <LockIcon /> : <LockOpenIcon />}
                      </IconButton>
                      
                      {user.role !== 'admin' && (
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
              ))}
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
                  <MenuItem value="admin">Administrator</MenuItem>
                  <MenuItem value="assessor">Property Assessor</MenuItem>
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
              }
              if (selectedUser?.id) {
                await apiService.updateUser(selectedUser.id, payload);
              } else {
                await apiService.createUser(payload);
              }
              handleUserSaved();
            } catch (e) {
              setError(e.message || 'Failed to save user');
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




