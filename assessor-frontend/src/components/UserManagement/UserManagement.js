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
  Card,
  CardContent,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  IconButton,
  Dialog,
  DialogTitle,
  CircularProgress,
  DialogContent,
  DialogActions,
  Alert,
  Snackbar,
  Menu,
  InputAdornment,
  Divider,
  Chip,
} from '@mui/material';
import { keyframes } from '@mui/system';
import {
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Person as PersonIcon,
  Refresh as RefreshIcon,
  Search as SearchIcon,
  Clear as ClearIcon,
  MoreVert as MoreVertIcon,
  CheckCircleOutline as CheckCircleOutlineIcon,
  Block as BlockIcon,
  AdminPanelSettings as AdminIcon,
  Security as SecurityIcon,
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { format } from 'date-fns';

import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';
import LoadingDots from '../LoadingDots';
import useLoadingWatchdog from '../../hooks/useLoadingWatchdog';

const ROLE_DESCRIPTIONS = {
  superadmin: 'Full system privileges including user creation, server synchronization, and advanced settings.',
  admin: 'Full administrative access to manage users, property records, and system configurations.',
  assessor: 'Can manage assessor records and perform official municipal assessor operations.',
  verifier: 'Can review, inspect, and verify property assessments and certifications.',
  editor: 'Can create and modify property records and transactions.',
  viewer: 'Read-only access. Can search and view records without editing.',
};

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
      const raw = sessionStorage.getItem('assessor_user');
      if (!raw) return false;
      const u = JSON.parse(raw);
      return String(u?.role || '').toLowerCase() === 'superadmin';
    } catch (_) {
      return false;
    }
  })();

  const [users, setUsers] = useState([]);
  const tableContainerRef = useRef(null);
  const [loading, setLoading] = useState(true);
  const [initialLoad, setInitialLoad] = useState(true);
  const [error, setError] = useState('');
  const [toast, setToast] = useState({ open: false, message: '', severity: 'success' });
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(50);
  const [totalCount, setTotalCount] = useState(0);
  const fetchSeqRef = useRef(0);

  // Search and filter states
  const [searchInput, setSearchInput] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [filters, setFilters] = useState({
    role: 'all',
    status: 'all',
  });

  // Modal states
  const [userModal, setUserModal] = useState(false);
  const [selectedUser, setSelectedUser] = useState(null);
  const [savingUser, setSavingUser] = useState(false);
  const [userFormError, setUserFormError] = useState('');

  const [deleteDialog, setDeleteDialog] = useState(false);
  const [userToDelete, setUserToDelete] = useState(null);
  const [actionLoading, setActionLoading] = useState(false);

  const [statusDialog, setStatusDialog] = useState(false);
  const [userToToggle, setUserToToggle] = useState(null);

  // Row Action Menu state
  const [actionMenuAnchor, setActionMenuAnchor] = useState(null);
  const [menuUser, setMenuUser] = useState(null);

  // Debounce search input (300ms)
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchInput.trim());
      setPage(0);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    if (canManage) {
      fetchUsers();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canManage, page, rowsPerPage, debouncedSearch, filters]);

  const fetchUsers = async () => {
    const seq = ++fetchSeqRef.current;
    try {
      setLoading(true);
      setError('');

      const params = {
        page: page + 1,
        per_page: rowsPerPage,
        q: debouncedSearch || '',
        role: filters.role !== 'all' ? filters.role : '',
        status: filters.status !== 'all' ? filters.status : '',
        _t: Date.now(),
      };

      const response = await apiService.getUsers(params);
      if (seq !== fetchSeqRef.current) return;

      let fetchedUsers = [];
      if (response && response.users) {
        fetchedUsers = response.users;
        setTotalCount(response.pagination ? response.pagination.total : response.users.length);
      } else if (response && response.data) {
        fetchedUsers = response.data;
        setTotalCount(response.total || response.data.length);
      } else {
        console.warn('Unexpected API response format:', response);
        setUsers([]);
        setTotalCount(0);
        return;
      }

      const sortedUsers = sortUsersByRole(fetchedUsers);
      setUsers(sortedUsers);
    } catch (err) {
      console.error('Error fetching users:', err);
      setError(`Failed to fetch users: ${err.message || 'Unknown error'}`);
      setUsers([]);
      setTotalCount(0);
    } finally {
      if (seq === fetchSeqRef.current) setLoading(false);
      setInitialLoad(false);
    }
  };

  const handleFilterChange = (field, value) => {
    setFilters((prev) => ({ ...prev, [field]: value }));
    setPage(0);
  };

  const handlePageChange = (event, newPage) => {
    setPage(newPage);
    if (tableContainerRef.current) {
      tableContainerRef.current.scrollTo(0, 0);
    }
  };

  const handleRowsPerPageChange = (event) => {
    setRowsPerPage(parseInt(event.target.value, 10));
    setPage(0);
  };

  const handleAddUser = () => {
    setSelectedUser({
      username: '',
      email: '',
      full_name: '',
      role: 'assessor',
      status: 'active',
      password: '',
      password_confirm: '',
    });
    setUserFormError('');
    setUserModal(true);
  };

  const handleEditUser = (user) => {
    setSelectedUser({
      ...user,
      password: '',
      password_confirm: '',
    });
    setUserFormError('');
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

  const handleOpenMenu = (event, user) => {
    setActionMenuAnchor(event.currentTarget);
    setMenuUser(user);
  };

  const handleCloseMenu = () => {
    setActionMenuAnchor(null);
    setMenuUser(null);
  };

  const confirmDelete = async () => {
    if (!userToDelete) return;
    try {
      setActionLoading(true);
      await apiService.deleteUser(userToDelete.id);
      setDeleteDialog(false);
      setUserToDelete(null);
      fetchUsers();
      setToast({ open: true, message: 'User deleted successfully', severity: 'success' });
    } catch (err) {
      setError(err?.message || 'Failed to delete user');
      setToast({ open: true, message: err?.message || 'Failed to delete user', severity: 'error' });
    } finally {
      setActionLoading(false);
    }
  };

  const confirmToggleStatus = async () => {
    if (!userToToggle) return;
    try {
      setActionLoading(true);
      const newStatus = userToToggle.status === 'active' ? 'inactive' : 'active';
      await apiService.updateUser(userToToggle.id, { status: newStatus });
      setStatusDialog(false);
      setUserToToggle(null);
      fetchUsers();
      setToast({
        open: true,
        message: `User ${newStatus === 'active' ? 'activated' : 'deactivated'} successfully`,
        severity: 'success',
      });
    } catch (err) {
      setError(err?.message || 'Failed to update user status');
      setToast({ open: true, message: err?.message || 'Failed to update user status', severity: 'error' });
    } finally {
      setActionLoading(false);
    }
  };

  const handleUserSaved = () => {
    setUserModal(false);
    setSelectedUser(null);
    setSavingUser(false);
    fetchUsers();
  };

  const getRolePriority = (role) => {
    switch (role?.toLowerCase()) {
      case 'superadmin':
        return 1;
      case 'assessor':
      case 'municipal assessor':
        return 2;
      case 'admin':
        return 3;
      case 'verifier':
        return 4;
      case 'editor':
        return 5;
      case 'viewer':
        return 6;
      default:
        return 7;
    }
  };

  const sortUsersByRole = (userList) => {
    return [...userList].sort((a, b) => {
      const priorityA = getRolePriority(a.role);
      const priorityB = getRolePriority(b.role);
      if (priorityA !== priorityB) {
        return priorityA - priorityB;
      }
      return (a.full_name || a.username || '').localeCompare(b.full_name || b.username || '');
    });
  };

  const getRoleDisplayName = (role) => {
    const roleNames = {
      superadmin: 'Super Administrator',
      admin: 'Administrator',
      assessor: 'Municipal Assessor',
      verifier: 'Verifier',
      editor: 'Editor',
      viewer: 'View Only',
    };
    return roleNames[role] || role;
  };

  const renderRoleChip = (role) => {
    const cleanRole = (role || '').toLowerCase();
    const label = getRoleDisplayName(role);

    if (cleanRole === 'superadmin' || cleanRole === 'assessor') {
      return (
        <Chip
          size="small"
          label={label}
          icon={<AdminIcon />}
          sx={{
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
          }}
        />
      );
    }

    if (cleanRole === 'admin') {
      return (
        <Chip
          size="small"
          label={label}
          color="error"
          icon={<AdminIcon />}
          sx={{
            fontWeight: 700,
            position: 'relative',
            overflow: 'hidden',
            '&::after': {
              content: '""',
              position: 'absolute',
              inset: 0,
              background: 'linear-gradient(145deg, rgba(255,255,255,0.16) 0%, rgba(255,255,255,0.06) 35%, rgba(255,255,255,0.0) 60%)',
              pointerEvents: 'none',
            },
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
          }}
        />
      );
    }

    if (cleanRole === 'editor') {
      return (
        <Chip
          size="small"
          label={label}
          color="secondary"
          icon={<SecurityIcon />}
          sx={{ fontWeight: 600 }}
        />
      );
    }

    if (cleanRole === 'verifier') {
      return (
        <Chip
          size="small"
          label={label}
          color="primary"
          icon={<SecurityIcon />}
          sx={{ fontWeight: 600 }}
        />
      );
    }

    // viewer or default
    return (
      <Chip
        size="small"
        label={label}
        color="info"
        icon={<SecurityIcon />}
        sx={{ fontWeight: 600 }}
      />
    );
  };

  const clearFilters = () => {
    setFilters({
      role: 'all',
      status: 'all',
    });
    setSearchInput('');
    setDebouncedSearch('');
    setPage(0);
  };

  const hasActiveFilters = Boolean(searchInput || filters.role !== 'all' || filters.status !== 'all');

  // Safety watchdog for initial users load
  useLoadingWatchdog({
    isLoading: loading,
    isInitialLoad: initialLoad,
    setLoading,
    setInitialLoad,
    setError,
    componentName: 'UserManagement',
    timeoutMs: 20000,
    timeoutMessage: 'Users failed to load in time. Please refresh.',
    enabled: true,
  });

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
      <Box
        sx={{
          display: 'flex',
          flexDirection: 'column',
          justifyContent: 'center',
          alignItems: 'center',
          minHeight: '70vh',
          gap: 2,
        }}
      >
        <CircularProgress size={44} thickness={4} />
        <Typography variant="h6" color="text.secondary">
          Loading users<LoadingDots />
        </Typography>
      </Box>
    );
  }

  return (
    <Box
      component={motion.div}
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      sx={{
        display: 'flex',
        flexDirection: 'column',
        height: 'calc(100vh - 64px - 3rem)',
        overflow: 'hidden',
      }}
    >
      {/* Page Header with direct primary action */}
      <Box
        sx={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'flex-start',
          mb: 2.5,
          flexShrink: 0,
        }}
      >
        <Box>
          <Typography variant="h5" fontWeight={700} color="text.primary" sx={{ lineHeight: 1.2 }}>
            User Management
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
            Manage system users, roles and access.
          </Typography>
        </Box>
        <Button
          variant="contained"
          color="primary"
          startIcon={<AddIcon />}
          onClick={handleAddUser}
          sx={{
            fontWeight: 600,
            textTransform: 'none',
            px: 2.5,
            py: 1,
            borderRadius: 1.5,
            boxShadow: 'none',
            '&:hover': { boxShadow: '0 2px 4px rgba(0,0,0,0.1)' },
          }}
        >
          Add User
        </Button>
      </Box>

      {/* Feedback Snackbars */}
      <Snackbar
        open={!!error}
        autoHideDuration={4500}
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
        onClose={() => setToast((prev) => ({ ...prev, open: false }))}
        anchorOrigin={{ vertical: 'top', horizontal: 'center' }}
      >
        <Alert
          onClose={() => setToast((prev) => ({ ...prev, open: false }))}
          severity={toast.severity}
          sx={{ width: '100%' }}
        >
          {toast.message}
        </Alert>
      </Snackbar>

      {/* User Filter Toolbar */}
      <Card
        variant="outlined"
        sx={{
          mb: 2.5,
          flexShrink: 0,
          bgcolor: 'background.paper',
          borderColor: 'divider',
          borderRadius: 2,
        }}
      >
        <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
          <Box
            sx={{
              display: 'flex',
              flexWrap: 'wrap',
              gap: 2,
              alignItems: 'center',
            }}
          >
            <TextField
              size="small"
              placeholder="Search users by name, username, or email..."
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              sx={{
                flex: { xs: '1 1 100%', md: '1 1 320px' },
                maxWidth: { md: 450 },
              }}
              InputProps={{
                startAdornment: (
                  <InputAdornment position="start">
                    <SearchIcon sx={{ color: 'text.secondary', fontSize: 20 }} />
                  </InputAdornment>
                ),
                endAdornment: searchInput ? (
                  <InputAdornment position="end">
                    <IconButton size="small" onClick={() => setSearchInput('')}>
                      <ClearIcon fontSize="small" />
                    </IconButton>
                  </InputAdornment>
                ) : null,
              }}
            />

            <FormControl size="small" sx={{ minWidth: 180, flexShrink: 0 }}>
              <InputLabel id="role-filter-label">Role</InputLabel>
              <Select
                labelId="role-filter-label"
                value={filters.role}
                label="Role"
                onChange={(e) => handleFilterChange('role', e.target.value)}
              >
                <MenuItem value="all">All Roles</MenuItem>
                <MenuItem value="superadmin">Super Administrator</MenuItem>
                {effectiveIsSuperAdmin && <MenuItem value="admin">Administrator</MenuItem>}
                <MenuItem value="assessor">Municipal Assessor</MenuItem>
                <MenuItem value="verifier">Verifier</MenuItem>
                <MenuItem value="editor">Editor</MenuItem>
                <MenuItem value="viewer">View Only</MenuItem>
              </Select>
            </FormControl>

            <FormControl size="small" sx={{ minWidth: 150, flexShrink: 0 }}>
              <InputLabel id="status-filter-label">Status</InputLabel>
              <Select
                labelId="status-filter-label"
                value={filters.status}
                label="Status"
                onChange={(e) => handleFilterChange('status', e.target.value)}
              >
                <MenuItem value="all">All Status</MenuItem>
                <MenuItem value="active">Active</MenuItem>
                <MenuItem value="inactive">Inactive</MenuItem>
              </Select>
            </FormControl>

            {hasActiveFilters && (
              <Button
                variant="outlined"
                color="secondary"
                size="small"
                startIcon={<RefreshIcon />}
                onClick={clearFilters}
                sx={{
                  height: 40,
                  textTransform: 'none',
                  fontWeight: 600,
                  borderRadius: 1.5,
                  ml: { md: 'auto' },
                }}
              >
                Clear filters
              </Button>
            )}
          </Box>
        </CardContent>
      </Card>

      {/* Users Table */}
      <Paper
        variant="outlined"
        sx={{
          width: '100%',
          display: 'flex',
          flexDirection: 'column',
          flexGrow: 1,
          flexShrink: 1,
          minHeight: 0,
          overflow: 'hidden',
          position: 'relative',
          borderRadius: 2,
          borderColor: 'divider',
        }}
      >
        {loading && !initialLoad && (
          <Box
            sx={{
              position: 'absolute',
              top: 0,
              left: 0,
              right: 0,
              bottom: 0,
              display: 'flex',
              flexDirection: 'column',
              alignItems: 'center',
              justifyContent: 'center',
              zIndex: 10,
              bgcolor: 'rgba(255,255,255,0.7)',
            }}
          >
            <CircularProgress size={32} sx={{ mb: 1.5 }} />
            <Typography variant="body2" color="text.secondary" fontWeight={500}>
              Updating staff list...
            </Typography>
          </Box>
        )}

        <TableContainer ref={tableContainerRef} sx={{ flexGrow: 1, flexShrink: 1, minHeight: 0, overflow: 'auto' }}>
          <Table stickyHeader sx={{ tableLayout: 'fixed' }}>
            <TableHead>
              <TableRow>
                <TableCell sx={{ minWidth: 260, fontWeight: 700, bgcolor: 'neutral.100' }}>User</TableCell>
                <TableCell sx={{ width: 180, fontWeight: 700, bgcolor: 'neutral.100' }}>Role</TableCell>
                <TableCell sx={{ width: 130, fontWeight: 700, bgcolor: 'neutral.100' }}>Status</TableCell>
                <TableCell sx={{ width: 180, fontWeight: 700, bgcolor: 'neutral.100' }}>Last Login</TableCell>
                <TableCell sx={{ width: 180, fontWeight: 700, bgcolor: 'neutral.100' }}>Created</TableCell>
                <TableCell sx={{ width: 80, fontWeight: 700, bgcolor: 'neutral.100' }} align="right">
                  Actions
                </TableCell>
              </TableRow>
            </TableHead>
            <TableBody
              sx={{
                opacity: loading && !initialLoad ? 0.6 : 1,
                pointerEvents: loading && !initialLoad ? 'none' : 'auto',
                transition: 'opacity 0.2s ease-in-out',
              }}
            >
              {users && users.length > 0 &&
                users.map((user) => {
                  const displayName = user.full_name || user.username || 'Unnamed User';
                  const isUserSuperAdmin = user.role === 'superadmin';
                  const isActionDisabled = isUserSuperAdmin && !effectiveIsSuperAdmin;

                  return (
                    <TableRow key={user.id} hover sx={{ '&:last-child td': { borderBottom: 0 } }}>
                      {/* User (Name prioritized) */}
                      <TableCell sx={{ verticalAlign: 'middle', py: 1.5 }}>
                        <Box display="flex" alignItems="center" gap={1.5}>
                          <Box
                            sx={{
                              width: 36,
                              height: 36,
                              borderRadius: '50%',
                              bgcolor: 'primary.light',
                              color: 'primary.contrastText',
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'center',
                              flexShrink: 0,
                              fontWeight: 700,
                              fontSize: '0.85rem',
                            }}
                          >
                            {displayName.charAt(0).toUpperCase()}
                          </Box>
                          <Box sx={{ minWidth: 0 }}>
                            <Typography
                              variant="body2"
                              fontWeight={700}
                              color="text.primary"
                              sx={{
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                              }}
                            >
                              {displayName}
                            </Typography>
                            <Box
                              sx={{
                                display: 'flex',
                                flexWrap: 'wrap',
                                alignItems: 'center',
                                gap: 1,
                                mt: 0.25,
                              }}
                            >
                              <Typography variant="caption" color="text.secondary">
                                Username: <strong>{user.username}</strong>
                              </Typography>
                              {user.email && (
                                <>
                                  <Typography variant="caption" color="text.secondary">
                                    •
                                  </Typography>
                                  <Typography variant="caption" color="text.secondary">
                                    {user.email}
                                  </Typography>
                                </>
                              )}
                            </Box>
                          </Box>
                        </Box>
                      </TableCell>

                      {/* Static Role Badge */}
                      <TableCell sx={{ verticalAlign: 'middle', py: 1.5 }}>
                        {renderRoleChip(user.role)}
                      </TableCell>

                      {/* Static Status Badge */}
                      <TableCell sx={{ verticalAlign: 'middle', py: 1.5 }}>
                        <Box display="flex" alignItems="center" gap={0.75}>
                          <Box
                            sx={{
                              width: 8,
                              height: 8,
                              borderRadius: '50%',
                              bgcolor: user.status === 'active' ? 'success.main' : 'text.disabled',
                              flexShrink: 0,
                            }}
                          />
                          <Typography
                            variant="body2"
                            fontWeight={600}
                            color={user.status === 'active' ? 'success.dark' : 'text.secondary'}
                          >
                            {user.status === 'active' ? 'Active' : 'Inactive'}
                          </Typography>
                        </Box>
                      </TableCell>

                      {/* Last Login */}
                      <TableCell sx={{ verticalAlign: 'middle', py: 1.5 }}>
                        <Typography variant="body2" color="text.secondary">
                          {user.last_login
                            ? format(new Date(user.last_login), 'MMM dd, yyyy h:mm a')
                            : 'Never'}
                        </Typography>
                      </TableCell>

                      {/* Created */}
                      <TableCell sx={{ verticalAlign: 'middle', py: 1.5 }}>
                        <Typography variant="body2" color="text.secondary">
                          {user.created_at
                            ? format(new Date(user.created_at), 'MMM dd, yyyy')
                            : '—'}
                        </Typography>
                      </TableCell>

                      {/* Actions Menu */}
                      <TableCell align="right" sx={{ verticalAlign: 'middle', py: 1.5 }}>
                        <IconButton
                          size="small"
                          aria-label="User actions"
                          onClick={(e) => handleOpenMenu(e, user)}
                          disabled={isActionDisabled}
                          sx={{ color: 'text.secondary' }}
                        >
                          <MoreVertIcon fontSize="small" />
                        </IconButton>
                      </TableCell>
                    </TableRow>
                  );
                })}

              {/* Empty state */}
              {(!loading || initialLoad) && (!users || users.length === 0) && (
                <TableRow>
                  <TableCell colSpan={6} sx={{ border: 'none', py: 8 }}>
                    <Box
                      sx={{
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: 'center',
                        justifyContent: 'center',
                        textAlign: 'center',
                        gap: 1.5,
                      }}
                    >
                      <Box
                        sx={{
                          width: 56,
                          height: 56,
                          borderRadius: '50%',
                          bgcolor: 'neutral.100',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          color: 'text.secondary',
                        }}
                      >
                        <PersonIcon sx={{ fontSize: 32 }} />
                      </Box>
                      {debouncedSearch || filters.role !== 'all' || filters.status !== 'all' ? (
                        <>
                          <Typography variant="h6" fontWeight={600} color="text.primary">
                            No matching users
                          </Typography>
                          <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 360 }}>
                            Try a different name, username or email, or reset your search filters.
                          </Typography>
                          <Button variant="outlined" size="small" onClick={clearFilters} sx={{ mt: 1 }}>
                            Clear filters
                          </Button>
                        </>
                      ) : (
                        <>
                          <Typography variant="h6" fontWeight={600} color="text.primary">
                            No users yet
                          </Typography>
                          <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 360 }}>
                            Create the first system user to get started.
                          </Typography>
                          <Button
                            variant="contained"
                            color="primary"
                            startIcon={<AddIcon />}
                            onClick={handleAddUser}
                            sx={{ mt: 1 }}
                          >
                            Add User
                          </Button>
                        </>
                      )}
                    </Box>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </TableContainer>

        <TablePagination
          sx={{ flexShrink: 0, borderTop: '1px solid', borderColor: 'divider' }}
          rowsPerPageOptions={[20, 50, 100]}
          component="div"
          count={totalCount}
          rowsPerPage={rowsPerPage}
          page={page}
          onPageChange={handlePageChange}
          onRowsPerPageChange={handleRowsPerPageChange}
        />
      </Paper>

      {/* Row Actions Menu */}
      <Menu
        anchorEl={actionMenuAnchor}
        open={Boolean(actionMenuAnchor)}
        onClose={handleCloseMenu}
        transformOrigin={{ horizontal: 'right', vertical: 'top' }}
        anchorOrigin={{ horizontal: 'right', vertical: 'bottom' }}
        PaperProps={{
          elevation: 2,
          sx: { minWidth: 170, borderRadius: 1.5, py: 0.5 },
        }}
      >
        <MenuItem
          onClick={() => {
            const u = menuUser;
            handleCloseMenu();
            handleEditUser(u);
          }}
          sx={{ gap: 1.5, fontSize: '0.875rem' }}
        >
          <EditIcon fontSize="small" sx={{ color: 'text.secondary' }} />
          Edit User
        </MenuItem>

        {menuUser && (
          <MenuItem
            onClick={() => {
              const u = menuUser;
              handleCloseMenu();
              handleToggleStatus(u);
            }}
            sx={{ gap: 1.5, fontSize: '0.875rem' }}
          >
            {menuUser.status === 'active' ? (
              <>
                <BlockIcon fontSize="small" sx={{ color: 'warning.main' }} />
                Deactivate User
              </>
            ) : (
              <>
                <CheckCircleOutlineIcon fontSize="small" sx={{ color: 'success.main' }} />
                Activate User
              </>
            )}
          </MenuItem>
        )}

        {menuUser &&
          menuUser.role !== 'superadmin' &&
          (effectiveIsSuperAdmin || menuUser.role !== 'admin') && [
            <Divider key="delete-divider" sx={{ my: 0.5 }} />,
            <MenuItem
              key="delete-menu-item"
              onClick={() => {
                const u = menuUser;
                handleCloseMenu();
                handleDeleteUser(u);
              }}
              sx={{ gap: 1.5, fontSize: '0.875rem', color: 'error.main' }}
            >
              <DeleteIcon fontSize="small" color="error" />
              Delete User
            </MenuItem>,
          ]}
      </Menu>

      {/* Add / Edit User Dialog */}
      <Dialog
        open={userModal}
        onClose={() => {
          if (!savingUser) setUserModal(false);
        }}
        maxWidth="md"
        fullWidth
        PaperProps={{ sx: { borderRadius: 2 } }}
      >
        <DialogTitle sx={{ pb: 1, fontWeight: 700 }}>
          {selectedUser?.id ? 'Edit User' : 'Add New User'}
        </DialogTitle>
        <DialogContent dividers sx={{ p: 3 }}>
          {userFormError && (
            <Alert severity="error" sx={{ mb: 2.5 }}>
              {userFormError}
            </Alert>
          )}

          {/* Section 1: User Information */}
          <Typography variant="subtitle2" fontWeight={700} color="primary.main" gutterBottom>
            User Information
          </Typography>
          <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, gap: 2, mb: 3 }}>
            <TextField
              fullWidth
              size="small"
              label="Full Name"
              placeholder="e.g. Juan Dela Cruz"
              value={selectedUser?.full_name || ''}
              onChange={(e) =>
                setSelectedUser((prev) => ({ ...(prev || {}), full_name: e.target.value }))
              }
            />
            <TextField
              fullWidth
              size="small"
              label="Username"
              required
              value={selectedUser?.username || ''}
              onChange={(e) =>
                setSelectedUser((prev) => ({ ...(prev || {}), username: e.target.value }))
              }
            />
            <Box sx={{ gridColumn: { xs: '1', sm: '1 / -1' } }}>
              <TextField
                fullWidth
                size="small"
                label="Email Address"
                type="email"
                required
                value={selectedUser?.email || ''}
                onChange={(e) =>
                  setSelectedUser((prev) => ({ ...(prev || {}), email: e.target.value }))
                }
              />
            </Box>
          </Box>

          <Divider sx={{ my: 2.5 }} />

          {/* Section 2: Access */}
          <Typography variant="subtitle2" fontWeight={700} color="primary.main" gutterBottom>
            Access & Permissions
          </Typography>
          <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, gap: 2, mb: 1.5 }}>
            <FormControl fullWidth size="small">
              <InputLabel id="dialog-role-label">Role</InputLabel>
              <Select
                labelId="dialog-role-label"
                value={selectedUser?.role || 'assessor'}
                label="Role"
                onChange={(e) =>
                  setSelectedUser((prev) => ({ ...(prev || {}), role: e.target.value }))
                }
              >
                {(effectiveIsSuperAdmin || (selectedUser && selectedUser.role === 'admin')) && (
                  <MenuItem
                    value="admin"
                    disabled={!effectiveIsSuperAdmin && selectedUser && selectedUser.role === 'admin'}
                  >
                    Administrator
                  </MenuItem>
                )}
                <MenuItem value="assessor">Municipal Assessor</MenuItem>
                <MenuItem value="verifier">Verifier</MenuItem>
                <MenuItem value="editor">Editor</MenuItem>
                <MenuItem value="viewer">View Only</MenuItem>
              </Select>
            </FormControl>

            <FormControl fullWidth size="small">
              <InputLabel id="dialog-status-label">Account Status</InputLabel>
              <Select
                labelId="dialog-status-label"
                value={selectedUser?.status || 'active'}
                label="Account Status"
                onChange={(e) =>
                  setSelectedUser((prev) => ({ ...(prev || {}), status: e.target.value }))
                }
              >
                <MenuItem value="active">Active</MenuItem>
                <MenuItem value="inactive">Inactive</MenuItem>
              </Select>
            </FormControl>
          </Box>

          {/* Role explanation */}
          <Box
            sx={{
              p: 1.5,
              borderRadius: 1.5,
              bgcolor: 'neutral.50',
              border: '1px solid',
              borderColor: 'divider',
              mb: 3,
            }}
          >
            <Typography variant="caption" fontWeight={700} color="text.primary" display="block">
              {getRoleDisplayName(selectedUser?.role || 'assessor')}
            </Typography>
            <Typography variant="caption" color="text.secondary">
              {ROLE_DESCRIPTIONS[selectedUser?.role || 'assessor'] ||
                'Standard system privileges for municipal staff.'}
            </Typography>
          </Box>

          <Divider sx={{ my: 2.5 }} />

          {/* Section 3: Password */}
          <Typography variant="subtitle2" fontWeight={700} color="primary.main" gutterBottom>
            {selectedUser?.id ? 'Security & Password' : 'Password'}
          </Typography>
          {selectedUser?.id && (
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
              Leave blank to keep the current password.
            </Typography>
          )}

          <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, gap: 2 }}>
            <TextField
              fullWidth
              size="small"
              label={selectedUser?.id ? 'New Password (optional)' : 'Password'}
              type="password"
              value={selectedUser?.password || ''}
              onChange={(e) =>
                setSelectedUser((prev) => ({ ...(prev || {}), password: e.target.value }))
              }
            />
            <TextField
              fullWidth
              size="small"
              label={selectedUser?.id ? 'Confirm New Password' : 'Confirm Password'}
              type="password"
              value={selectedUser?.password_confirm || ''}
              onChange={(e) =>
                setSelectedUser((prev) => ({ ...(prev || {}), password_confirm: e.target.value }))
              }
            />
          </Box>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setUserModal(false)} disabled={savingUser} sx={{ textTransform: 'none' }}>
            Cancel
          </Button>
          <Button
            variant="contained"
            disabled={savingUser}
            onClick={async () => {
              setUserFormError('');
              if (!selectedUser?.username?.trim()) {
                setUserFormError('Username is required.');
                return;
              }
              if (!selectedUser?.email?.trim()) {
                setUserFormError('Email address is required.');
                return;
              }
              if (!selectedUser?.id && !selectedUser?.password) {
                setUserFormError('Password is required for new accounts.');
                return;
              }
              if (selectedUser?.password && selectedUser.password !== selectedUser.password_confirm) {
                setUserFormError('Passwords do not match.');
                return;
              }

              try {
                setSavingUser(true);
                const payload = {
                  username: selectedUser.username.trim(),
                  email: selectedUser.email.trim(),
                  full_name: (selectedUser.full_name || '').trim(),
                  role: selectedUser.role || 'assessor',
                  status: selectedUser.status || 'active',
                };
                if (!selectedUser.id || selectedUser.password) {
                  payload.password = selectedUser.password || '';
                  payload.password_confirm = selectedUser.password_confirm || '';
                }

                if (selectedUser.id) {
                  await apiService.updateUser(selectedUser.id, payload);
                  setToast({ open: true, message: 'User updated successfully', severity: 'success' });
                } else {
                  await apiService.createUser(payload);
                  setToast({ open: true, message: 'User created successfully', severity: 'success' });
                }
                handleUserSaved();
              } catch (e) {
                setUserFormError(e.message || 'Failed to save user');
              } finally {
                setSavingUser(false);
              }
            }}
            sx={{ textTransform: 'none', fontWeight: 600, minWidth: 120 }}
          >
            {savingUser ? (
              <CircularProgress size={20} color="inherit" />
            ) : selectedUser?.id ? (
              'Save Changes'
            ) : (
              'Create User'
            )}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog
        open={deleteDialog}
        onClose={() => {
          if (!actionLoading) setDeleteDialog(false);
        }}
        maxWidth="xs"
        fullWidth
        PaperProps={{ sx: { borderRadius: 2 } }}
      >
        <DialogTitle sx={{ pb: 1, fontWeight: 700 }}>Delete User?</DialogTitle>
        <DialogContent>
          <Box sx={{ p: 2, bgcolor: 'neutral.50', borderRadius: 1.5, border: '1px solid', borderColor: 'divider', mb: 2 }}>
            <Typography variant="body1" fontWeight={700} color="text.primary">
              {userToDelete?.full_name || userToDelete?.username}
            </Typography>
            <Typography variant="body2" color="text.secondary">
              {userToDelete?.email || `Username: ${userToDelete?.username}`}
            </Typography>
          </Box>
          <Typography variant="body2" color="error.main" fontWeight={600}>
            This action cannot be undone and will permanently remove this user account.
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setDeleteDialog(false)} disabled={actionLoading} sx={{ textTransform: 'none' }}>
            Cancel
          </Button>
          <Button
            onClick={confirmDelete}
            color="error"
            variant="contained"
            disabled={actionLoading}
            sx={{ textTransform: 'none', fontWeight: 600 }}
          >
            {actionLoading ? <CircularProgress size={20} color="inherit" /> : 'Delete User'}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Activate / Deactivate Confirmation Dialog */}
      <Dialog
        open={statusDialog}
        onClose={() => {
          if (!actionLoading) setStatusDialog(false);
        }}
        maxWidth="xs"
        fullWidth
        PaperProps={{ sx: { borderRadius: 2 } }}
      >
        <DialogTitle sx={{ pb: 1, fontWeight: 700 }}>
          {userToToggle?.status === 'active' ? 'Deactivate User?' : 'Activate User?'}
        </DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.primary" sx={{ mb: 1 }}>
            <strong>{userToToggle?.full_name || userToToggle?.username}</strong>
            {userToToggle?.status === 'active'
              ? ' will no longer be able to sign in.'
              : ' will regain access to the system.'}
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setStatusDialog(false)} disabled={actionLoading} sx={{ textTransform: 'none' }}>
            Cancel
          </Button>
          <Button
            onClick={confirmToggleStatus}
            color={userToToggle?.status === 'active' ? 'warning' : 'primary'}
            variant="contained"
            disabled={actionLoading}
            sx={{ textTransform: 'none', fontWeight: 600 }}
          >
            {actionLoading ? (
              <CircularProgress size={20} color="inherit" />
            ) : userToToggle?.status === 'active' ? (
              'Deactivate User'
            ) : (
              'Activate User'
            )}
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default UserManagement;
