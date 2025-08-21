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
  Tooltip
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
  Settings as SettingsIcon
} from '@mui/icons-material';
import { useAuth } from '../../contexts/AuthContext';
import { animations } from '../../theme/theme';
import { apiService } from '../../utils/api';
import Dashboard from '../Dashboard/Dashboard';
import PropertyTable from '../PropertyTable/PropertyTable';
import AuditTrail from '../AuditTrail/AuditTrail';
import UserManagement from '../UserManagement/UserManagement';
import Export from '../Export/Export';
import Settings from '../Settings/Settings';

const drawerWidth = 280;

const Layout = ({ children }) => {
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('md'));
  const { user, logout } = useAuth();
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
    // Try to restore the last visited page from localStorage
    try {
      const savedPage = localStorage.getItem('assessor_current_page');
      return savedPage || 'Dashboard';
    } catch (_) {
      return 'Dashboard';
    }
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
      try {
        localStorage.removeItem('assessor_current_page');
      } catch (_) {
        // Ignore localStorage errors
      }
    }
  }, [user]);

  const navigationItems = [
    {
      text: 'Dashboard',
      icon: <DashboardIcon />,
      badge: null
    },
    {
      text: 'Properties',
      icon: <Business />,
      badge: null
    },
    {
      text: 'Audit Trail',
      icon: <History />,
      badge: null
    },
    {
      text: 'Export',
      icon: <FileDownload />,
      badge: null
    },
    {
      text: 'User Management',
      icon: <AccountCircle />,
      badge: null
    },
    {
      text: 'Settings',
      icon: <SettingsIcon />,
      badge: null
    }
  ];

  const drawer = (
    <Box>
      <Box sx={{ 
        p: 2, 
        borderBottom: `1px solid ${theme.palette.divider}`,
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        textAlign: 'center'
      }}>
        {settings?.app_logo_url ? (
          <img src={settings.app_logo_url} alt="Logo" style={{ maxHeight: 86, marginBottom: 8 }} />
        ) : (
          <Business sx={{ fontSize: 28, color: 'primary.main', mb: 1 }} />
        )}
        <Typography variant="h6" fontWeight={600} color="primary" sx={{ mb: 1 }}>
          Assessor's Archiving System
        </Typography>
        <Typography variant="body2" color="text.secondary">
          Local Government
        </Typography>
      </Box>
      
      <List>
        {navigationItems.map((item) => (
          <motion.div key={item.text}>
            <ListItem disablePadding>
              <ListItemButton
                onClick={() => handleNavigation(item.text)}
                selected={currentPage === item.text}
                sx={{
                  mx: 1,
                  borderRadius: 2,
                  display: 'flex',
                  alignItems: 'center',
                  '&.Mui-selected': {
                    backgroundColor: 'primary.main',
                    color: 'white',
                    '&:hover': {
                      backgroundColor: 'primary.dark',
                      color: 'white',
                    },
                  },
                  '&:hover': {
                    backgroundColor: 'action.hover',
                  },
                }}
              >
                <ListItemIcon
                  sx={{
                    color: currentPage === item.text ? 'white' : 'text.secondary',
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
                    color: currentPage === item.text ? 'white' : 'inherit',
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
          width: { md: `calc(100% - ${drawerWidth}px)` },
          ml: { md: `${drawerWidth}px` },
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

          <Box sx={{ flexGrow: 1, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <motion.div
              initial={{ opacity: 0, x: -20 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ duration: 0.3 }}
              style={{ display: 'flex', alignItems: 'center', gap: '8px' }}
            >
              {currentPage === 'Dashboard' && <DashboardIcon sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'Properties' && <Business sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
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
        sx={{ width: { md: drawerWidth }, flexShrink: { md: 0 } }}
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
              width: drawerWidth,
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
              width: drawerWidth,
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
          width: { md: `calc(100% - ${drawerWidth}px)` },
          mt: '64px',
          background: '#f8fafc',
          minHeight: 'calc(100vh - 64px)'
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
            {currentPage === 'Dashboard' && <Dashboard />}
            {currentPage === 'Properties' && <PropertyTable />}
            {currentPage === 'Audit Trail' && <AuditTrail />}
            {currentPage === 'Export' && <Export />}
            {currentPage === 'User Management' && <UserManagement />}
            {currentPage === 'Settings' && <Settings />}
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
            {user?.role?.charAt(0).toUpperCase() + user?.role?.slice(1)}
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

