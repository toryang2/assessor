import React, { useEffect, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
  Box,
  Drawer,
  AppBar,
  Toolbar,
  Typography,
  IconButton,
  List,
  ListItem,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  Divider,
  Avatar,
  Menu,
  MenuItem,
  Badge,
  useTheme,
  useMediaQuery,
  Tooltip,
  CircularProgress
} from '@mui/material';
import {
  Menu as MenuIcon,
  Dashboard as DashboardIcon,
  Business,
  AccountCircle,
  Logout,
  Notifications,
  History,
  FileDownload,
  Archive,
  Settings as SettingsIcon,
  Receipt as ReceiptIcon
} from '@mui/icons-material';
import { useAuth } from '../../contexts/AuthContext';
import { animations } from '../../theme/theme';
import { apiService } from '../../utils/api';
import { useCacheBuster } from '../../hooks/useCacheBuster';
import LoadingDots from '../LoadingDots';
import Dashboard from '../Dashboard/Dashboard';
import PropertyTable from '../PropertyTable/PropertyTable';
import RequestsTable from '../RequestsTable/RequestsTable';
import AuditTrail from '../AuditTrail/AuditTrail';
import UserManagement from '../UserManagement/UserManagement';
import Export from '../Export/Export';
import Settings from '../Settings/Settings';
import useSafetyWatchdog from '../../hooks/useSafetyWatchdog';

const drawerWidth = 320;

const Layout = ({ children }) => {
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('md'));
  const isSmallScreen = (() => {
    try {
      const w = window.innerWidth;
      const h = window.innerHeight;
      return (w <= 1280 && h <= 720) || (w <= 1366 && h <= 768) || (w <= 1920 && h <= 1080);
    } catch (_) {
      return false;
    }
  })();
  const computedDrawerWidth = isSmallScreen ? 300 : drawerWidth;
  const { user, logout, isSuperAdmin, isAdmin, isAssessor, isViewer } = useAuth();
  const initialSettings = (() => {
    if (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__) return window.__ASSESSOR_SETTINGS__;
    try {
      const cached = localStorage.getItem('assessor_settings');
      if (cached) return JSON.parse(cached);
    } catch (_) {}
    const cachedLogo = typeof window !== 'undefined' ? localStorage.getItem('app_logo_url') : '';
    if (cachedLogo) return { app_logo_url: cachedLogo };
    return null;
  })();
  const [settings, setSettings] = useState(initialSettings);
  
  const [mobileOpen, setMobileOpen] = useState(false);
  const [anchorEl, setAnchorEl] = useState(null);
  const [currentPage, setCurrentPage] = useState(() => {
    // On reload, restore last page if saved; otherwise default to Dashboard
    try {
      const savedPage = localStorage.getItem('assessor_current_page');
      return savedPage || 'Dashboard';
    } catch (_) {
      return 'Dashboard';
    }
  });
  const [isPageAccessChecked, setIsPageAccessChecked] = useState(false);

  // Safety watchdog for access-check stage to avoid infinite spinner
  useSafetyWatchdog({
    isLoading: !isPageAccessChecked,
    isInitialLoad: !isPageAccessChecked,
    onTimeout: () => {
      try { console.warn('Layout: access check timed out. Proceeding to render.'); } catch (_) {}
      setIsPageAccessChecked(true);
    },
    timeoutMs: 15000,
    componentName: 'Layout',
    enabled: true
  });

  const handleDrawerToggle = () => {
    setMobileOpen(!mobileOpen);
  };

  const handleProfileMenuOpen = (event) => {
    setAnchorEl(event.currentTarget);
  };

  const handleProfileMenuClose = () => {
    setAnchorEl(null);
  };

  const handleLogout = async () => {
    // Clear the current page from localStorage when logging out
    try {
      localStorage.removeItem('assessor_current_page');
    } catch (_) {
      // Ignore localStorage errors
    }
    await logout();
  };

  const handleNavigation = (page) => {
    // Check if user has access to the requested page
    if (!canAccessPage(page)) {
      // Redirect to Dashboard if user doesn't have access
      setCurrentPage('Dashboard');
      setMobileOpen(false);
      try {
        localStorage.setItem('assessor_current_page', 'Dashboard');
      } catch (_) {
        // Ignore localStorage errors
      }
      return;
    }
    
    setCurrentPage(page);
    setMobileOpen(false);
    // Save the current page to localStorage for persistence across refreshes
    try {
      localStorage.setItem('assessor_current_page', page);
    } catch (_) {
      // Ignore localStorage errors
    }
  };

  useEffect(() => {
    const loadSettings = async () => {
      try {
        const data = await apiService.getSettings();
        setSettings(data);
        try {
          localStorage.setItem('assessor_settings', JSON.stringify(data));
          if (data && data.app_logo_url) localStorage.setItem('app_logo_url', data.app_logo_url);
        } catch (_) {}
      } catch (e) {
        setSettings(null);
      }
    };
    loadSettings();
  }, []);

  // Reset current page to Dashboard when user logs out
  useEffect(() => {
    if (!user) {
      setCurrentPage('Dashboard');
      setIsPageAccessChecked(false);
      try {
        localStorage.removeItem('assessor_current_page');
      } catch (_) {
        // Ignore localStorage errors
      }
    }
  }, [user]);

  // Helper function to check if user can access a specific page
  const canAccessPage = (pageName) => {
    const restrictedPages = ['Audit Trail', 'Export', 'User Management', 'Settings'];
    if (restrictedPages.includes(pageName)) {
      return isSuperAdmin || isAdmin || isAssessor;
    }
    return true; // All other pages are accessible
  };

  // Check if current page is accessible for current user role
  useEffect(() => {
    if (user && currentPage) {
      if (!canAccessPage(currentPage)) {
        // Redirect to Dashboard if user doesn't have access to current page
        setCurrentPage('Dashboard');
        try {
          localStorage.setItem('assessor_current_page', 'Dashboard');
        } catch (_) {
          // Ignore localStorage errors
        }
      }
      setIsPageAccessChecked(true);
    }
  }, [user, currentPage]);

  const navigationItems = [
    {
      text: 'Dashboard',
      icon: <DashboardIcon />,
      badge: null,
      show: true // Always show Dashboard
    },
    {
      text: 'Properties',
      icon: <Business />,
      badge: null,
      show: true // Always show Properties
    },
    {
      text: 'Requests',
      icon: <ReceiptIcon />,
      badge: null,
      show: !isViewer, // Hide Requests for viewers
      disabled: false
    },
    {
      text: 'Audit Trail',
      icon: <History />,
      badge: null,
      show: canAccessPage('Audit Trail')
    },
    {
      text: 'Export',
      icon: <FileDownload />,
      badge: null,
      show: canAccessPage('Export')
    },
    {
      text: 'User Management',
      icon: <AccountCircle />,
      badge: null,
      show: canAccessPage('User Management')
    },
    {
      text: 'Settings',
      icon: <SettingsIcon />,
      badge: null,
      show: canAccessPage('Settings')
    }
  ];
  const toFormalCase = (text) => {
    if (!text) return '';
    const small = new Set(['of','and','the','for','in','on','at','a','an']);
    const words = String(text).toLowerCase().split(/\s+/);
    return words.map((w, i) => {
      if (!w) return w;
      if (i > 0 && small.has(w)) return w;
      return w.charAt(0).toUpperCase() + w.slice(1);
    }).join(' ');
  };
  const headerPh = 'Republic of the Philippines';
  const baseProvince = (settings && settings.header_province) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_province) || 'Bukidnon';
  const headerProvince = `Province of ${toFormalCase(baseProvince)}`;
  const baseMunicipality = (settings && settings.header_municipality) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_municipality) || 'KITAOTAO';
  const headerMunicipality = `Municipality of ${toFormalCase(baseMunicipality)}`;
  const headerOffice = (settings && settings.header_office) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_office) || 'OFFICE OF THE MUNICIPAL ASSESSOR';


  const formatRole = (role) => {
    if (!role || typeof role !== 'string') return '';
    const normalized = role.trim().toLowerCase();
    if (normalized === 'assessor') return 'Municipal Assessor';
    if (normalized === 'municipal assessor') return 'Municipal Assessor';
    if (normalized === 'administrator') return 'Administrator';
    if (normalized === 'admin') return 'Administrator';
    if (normalized === 'superadmin') return 'Super Admininstrator';
    return role
      .split(/\s+/)
      .map(part => part.charAt(0).toUpperCase() + part.slice(1))
      .join(' ');
  };

  const drawer = (
    <Box>
      <Box sx={{ 
        py: isSmallScreen ? 3 : 4, 
        px: 2,
        borderBottom: `1px solid ${theme.palette.divider}`,
        display: 'flex',
        flexDirection: 'row',
        alignItems: 'center',
        gap: isSmallScreen ? 1.5 : 2
      }}>
        <Box sx={{ display: 'flex', alignItems: 'center', flexShrink: 0 }}>
          {settings?.app_logo_url ? (
            <img src={settings.app_logo_url} alt="Logo" style={{ maxHeight: isSmallScreen ? 48 : 64, display: 'block' }} />
          ) : (
            <Business sx={{ fontSize: isSmallScreen ? 22 : 28, color: 'primary.main' }} />
          )}
        </Box>
        <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
          <Typography variant="body2" fontWeight={600} sx={{ fontSize: isSmallScreen ? '0.9rem' : undefined }}>
            Assessor's Archiving System
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ fontSize: isSmallScreen ? '0.85rem' : undefined }}>
            {headerMunicipality}
          </Typography>
          <Typography variant="caption" color="text.secondary" sx={{ fontSize: isSmallScreen ? '0.75rem' : undefined }}>
            {headerProvince}
          </Typography>
        </Box>
      </Box>
      
      <List>
        {navigationItems.filter(item => item.show).map((item) => (
          <motion.div key={item.text}>
            <ListItem disablePadding>
              <ListItemButton
                  onClick={() => handleNavigation(item.text)}
                  selected={currentPage === item.text}
                  sx={{
                      display: 'flex',
                      alignItems: 'center',
                      position: 'relative',
                      borderRadius: 0,
                      '&.Mui-selected': {
                        backgroundColor: 'rgba(25, 118, 210, 0.08)',
                        color: 'primary.main',
                        '&::before': {
                          content: '""',
                          position: 'absolute',
                          left: 0,
                          top: 0,
                          bottom: 0,
                          width: 4,
                          backgroundColor: 'primary.main',
                        },
                        '&:hover': {
                          backgroundColor: 'rgba(25, 118, 210, 0.12)',
                          color: 'primary.main',
                        },
                      },
                      '&:hover': {
                        backgroundColor: 'action.hover',
                      },
                    }}
                >
                  <ListItemIcon
                   sx={{
                     color: currentPage === item.text ? 'primary.main' : 'text.secondary',
                     minWidth: 40,
                     display: 'flex',
                     alignItems: 'center',
                     justifyContent: 'center',
                   }}
                 >
                  {item.icon}
                </ListItemIcon>
                                 <ListItemText 
                   primary={item.text}
                   primaryTypographyProps={{
                     fontWeight: currentPage === item.text ? 600 : 400,
                     color: currentPage === item.text ? 'primary.main' : 'inherit',
                   }}
                   sx={{
                     marginLeft: 0,
                   }}
                 />
                {item.badge && (
                  <Badge badgeContent={item.badge} color="error" />
                )}
              </ListItemButton>
            </ListItem>
          </motion.div>
        ))}
      </List>
    </Box>
  );

  return (
    <Box sx={{ display: 'flex', height: '100vh' }}>
        {/* App Bar */}
      <AppBar
        position="fixed"
        sx={{
          width: { md: `calc(100% - ${computedDrawerWidth}px)` },
          ml: { md: `${computedDrawerWidth}px` },
          background: '#f8fafc',
          color: '#475569',
          boxShadow: '0 2px 8px rgba(0,0,0,0.08)'
        }}
      >
        <Toolbar sx={{ alignItems: 'center' }}>
          <IconButton
            color="inherit"
            aria-label="open drawer"
            edge="start"
            onClick={handleDrawerToggle}
            sx={{ mr: 2, display: { md: 'none' } }}
          >
            <MenuIcon />
          </IconButton>

          <Box sx={{ flexGrow: 1, display: 'flex' }}>
            <motion.div
              initial={{ opacity: 0, x: -20 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ duration: 0.3 }}
              style={{ display: 'flex', alignItems: 'center', gap: '8px' }}
            >
              {currentPage === 'Dashboard' && <DashboardIcon sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'Properties' && <Business sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'Requests' && <ReceiptIcon sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'Audit Trail' && <History sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'Export' && <FileDownload sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'User Management' && <AccountCircle sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'Settings' && <SettingsIcon sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              <Typography variant="h6" fontWeight={600} sx={{ lineHeight: 1, display: 'inline-flex', alignItems: 'center' }}>
                {currentPage}
              </Typography>
            </motion.div>
          </Box>

          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <Tooltip title="Notifications">
              <IconButton color="inherit" size="small">
                <Badge badgeContent={0} color="error">
                  <Notifications />
                </Badge>
              </IconButton>
            </Tooltip>
            
            <Tooltip title="Profile">
              <IconButton
                onClick={handleProfileMenuOpen}
                sx={{ ml: 1 }}
              >
                <Avatar
                  sx={{ 
                    width: 32, 
                    height: 32,
                    bgcolor: 'primary.main',
                    fontSize: '0.875rem'
                  }}
                >
                  {user?.full_name?.charAt(0) || user?.username?.charAt(0) || 'U'}
                </Avatar>
              </IconButton>
            </Tooltip>
          </Box>
        </Toolbar>
      </AppBar>

      {/* Drawer */}
      <Box
        component="nav"
        sx={{ width: { md: computedDrawerWidth }, flexShrink: { md: 0 } }}
      >
        <Drawer
          variant="temporary"
          open={mobileOpen}
          onClose={handleDrawerToggle}
          ModalProps={{
            keepMounted: true,
          }}
          sx={{
            display: { xs: 'block', md: 'none' },
            '& .MuiDrawer-paper': { 
              boxSizing: 'border-box', 
              width: computedDrawerWidth,
              background: '#ffffff',
              borderRight: `1px solid ${theme.palette.divider}`
            },
          }}
        >
          {drawer}
        </Drawer>
        <Drawer
          variant="permanent"
          sx={{
            display: { xs: 'none', md: 'block' },
            '& .MuiDrawer-paper': { 
              boxSizing: 'border-box', 
              width: computedDrawerWidth,
              background: '#ffffff',
              borderRight: `1px solid ${theme.palette.divider}`
            },
          }}
          open
        >
          {drawer}
        </Drawer>
      </Box>

      {/* Main Content */}
      <Box
        component="main"
        sx={{
          flexGrow: 1,
          p: 3,
          width: { md: `calc(100% - ${computedDrawerWidth}px)` },
          mt: '64px',
          background: '#f8fafc',
          minHeight: 'calc(100vh - 64px)',
          // Hide scrollbar but maintain scrolling functionality
          '&::-webkit-scrollbar': {
            display: 'none'
          },
          // For Firefox
          scrollbarWidth: 'none',
          // For IE and Edge
          msOverflowStyle: 'none',
          // Ensure scrolling still works
          overflow: 'auto'
        }}
      >
        <AnimatePresence mode="wait">
          <motion.div
            key={currentPage}
            initial="initial"
            animate="animate"
            exit="exit"
            variants={animations.fadeIn}
          >
            {!isPageAccessChecked ? (
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
                  Loading<LoadingDots />
                </Typography>
                {/* <Typography variant="body2" color="text.secondary">
                  Please wait while the system loads
                </Typography> */}
              </Box>
            ) : (
              <>
                {currentPage === 'Dashboard' && <Dashboard onNavigate={handleNavigation} />}
                {currentPage === 'Properties' && <PropertyTable />}
                {currentPage === 'Requests' && <RequestsTable />}
                {currentPage === 'Audit Trail' && canAccessPage('Audit Trail') ? <AuditTrail /> : 
                  currentPage === 'Audit Trail' && <Box sx={{ textAlign: 'center', py: 8 }}>
                    <Typography variant="h5" color="text.secondary" gutterBottom>Access Denied</Typography>
                    <Typography variant="body1" color="text.secondary">You don't have permission to access this page.</Typography>
                  </Box>}
                {currentPage === 'Export' && canAccessPage('Export') ? <Export /> : 
                  currentPage === 'Export' && <Box sx={{ textAlign: 'center', py: 8 }}>
                    <Typography variant="h5" color="text.secondary" gutterBottom>Access Denied</Typography>
                    <Typography variant="body1" color="text.secondary">You don't have permission to access this page.</Typography>
                  </Box>}
                {currentPage === 'User Management' && canAccessPage('User Management') ? <UserManagement /> : 
                  currentPage === 'User Management' && <Box sx={{ textAlign: 'center', py: 8 }}>
                    <Typography variant="h5" color="text.secondary" gutterBottom>Access Denied</Typography>
                    <Typography variant="body1" color="text.secondary">You don't have permission to access this page.</Typography>
                  </Box>}
                {currentPage === 'Settings' && canAccessPage('Settings') ? <Settings /> : 
                  currentPage === 'Settings' && <Box sx={{ textAlign: 'center', py: 8 }}>
                    <Typography variant="h5" color="text.secondary" gutterBottom>Access Denied</Typography>
                    <Typography variant="body1" color="text.secondary">You don't have permission to access this page.</Typography>
                  </Box>}
              </>
            )}
          </motion.div>
        </AnimatePresence>
      </Box>

      {/* Profile Menu */}
      <Menu
        anchorEl={anchorEl}
        open={Boolean(anchorEl)}
        onClose={handleProfileMenuClose}
        anchorOrigin={{
          vertical: 'bottom',
          horizontal: 'right',
        }}
        transformOrigin={{
          vertical: 'top',
          horizontal: 'right',
        }}
        PaperProps={{
          sx: {
            mt: 1,
            minWidth: 200,
            boxShadow: '0 8px 24px rgba(0,0,0,0.12)',
            borderRadius: 4
          }
        }}
      >
        <Box sx={{ padding: 2, borderBottom: `1px solid ${theme.palette.divider}` }}>
          <Typography variant="subtitle1" fontWeight={600}>
            {user?.full_name || user?.username}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            {formatRole(user?.role)}
          </Typography>
        </Box>
        
        <MenuItem onClick={handleProfileMenuClose}>
          <ListItemIcon>
            <AccountCircle fontSize="small" />
          </ListItemIcon>
          Profile
        </MenuItem>
        
        <Divider />
        
        <MenuItem onClick={handleLogout}>
          <ListItemIcon>
            <Logout fontSize="small" />
          </ListItemIcon>
          Logout
        </MenuItem>
      </Menu>
    </Box>
  );
};

export default Layout;

