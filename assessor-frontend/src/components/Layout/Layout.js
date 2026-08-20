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
  Popover,
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
  Receipt as ReceiptIcon,
  Description as DescriptionIcon,
  People as PeopleIcon,
  Info as InfoIcon,
  CheckCircle as CheckCircleIcon,
  Warning as WarningIcon,
  CloudSync as CloudSyncIcon,
  NewReleases as NewReleasesIcon
} from '@mui/icons-material';
import { useAuth } from '../../contexts/AuthContext';
import { animations } from '../../theme/theme';
import { apiService } from '../../utils/api';
import { useCacheBuster } from '../../hooks/useCacheBuster';
import changelogData from '../../data/changelog.json';
import LoadingDots from '../LoadingDots';
import Dashboard from '../Dashboard/Dashboard';
import PropertyTable from '../PropertyTable/PropertyTable';
import EtracsPropertyTable from '../EtracsPropertyTable/EtracsPropertyTable';
import EtracsEntityTable from '../EtracsEntityTable/EtracsEntityTable';
import RequestsTable from '../RequestsTable/RequestsTable';
import AuditTrail from '../AuditTrail/AuditTrail';
import UserManagement from '../UserManagement/UserManagement';
import Export from '../Export/Export';
import Settings from '../Settings/Settings';
import Profile from '../Profile/Profile';
import useSafetyWatchdog from '../../hooks/useSafetyWatchdog';
import SyncModal from '../SyncModal/SyncModal';
import AnimatedCloudIcon from '../AnimatedCloudIcon/AnimatedCloudIcon';
import ChangelogModal from '../ChangelogModal/ChangelogModal';

const drawerWidth = '20rem'; // 320px at 16px font size

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
  const computedDrawerWidth = isSmallScreen ? '18.75rem' : drawerWidth; // 300px at 16px font size
  const { user, logout, isSuperAdmin, isAdmin, isAssessor, isViewer, syncStatus } = useAuth();
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
  const [syncModalOpen, setSyncModalOpen] = useState(false);
  const [changelogOpen, setChangelogOpen] = useState(false);
  
  const [notificationAnchorEl, setNotificationAnchorEl] = useState(null);
  const [notifications, setNotifications] = useState([]);
  const unreadCount = notifications.filter(n => !n.isRead).length;
  const [notifsLoaded, setNotifsLoaded] = useState(false);
  const [hasNewRelease, setHasNewRelease] = useState(false);
  const loadedUserId = React.useRef(null);

  // Synchronized Initialization & Persistence
  useEffect(() => {
    if (user && loadedUserId.current !== user.id) {
      // 1. Load existing notifications
      let initialNotifs = [];
      try {
        const saved = localStorage.getItem(`assessor_notifications_${user.id}`);
        if (saved) {
          initialNotifs = JSON.parse(saved).filter(n => n.type !== 'changelog');
        }
      } catch (e) {
        console.error('Failed to load notifications', e);
      }

      // 2. Check changelog
      try {
        const lastSeenVersion = localStorage.getItem(`last_seen_version_v6_${user.id}`);
        const currentVersion = changelogData[0]?.version;
        
        console.log('Changelog Check - Current Version:', currentVersion, 'Last Seen:', lastSeenVersion);

        if (currentVersion && lastSeenVersion !== currentVersion) {
          setHasNewRelease(true);
        } else {
          setHasNewRelease(false);
        }
      } catch (e) {
        console.error('Failed to check changelog version:', e);
      }

      // 3. Commit state
      setNotifications(initialNotifs);

      loadedUserId.current = user.id;
      setNotifsLoaded(true);
    }
  }, [user]);

  // Save to local storage only when loadedUserId matches user.id and notifsLoaded is true
  useEffect(() => {
    if (user && notifsLoaded && loadedUserId.current === user.id) {
      try {
        localStorage.setItem(`assessor_notifications_${user.id}`, JSON.stringify(notifications));
      } catch (e) {
        console.error('Failed to save notifications', e);
      }
    }
  }, [notifications, user, notifsLoaded]);

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

  const handleNotificationMenuOpen = (event) => {
    setNotificationAnchorEl(event.currentTarget);
  };

  const handleNotificationMenuClose = () => {
    setNotificationAnchorEl(null);
  };

  const handleNotificationClick = (notif) => {
    if (!notif.isRead) {
      setNotifications(prev => {
        const updated = prev.map(n => n.id === notif.id ? { ...n, isRead: true } : n);
        return updated;
      });

    }
    if (notif.id.startsWith('changelog_')) {
      setChangelogOpen(true);
    }
    handleNotificationMenuClose();
  };



  const handleProfileMenuOpen = (event) => {
    setAnchorEl(event.currentTarget);
  };

  const handleProfileClick = () => {
    setCurrentPage('Profile');
    setAnchorEl(null);
    setMobileOpen(false);
    try {
      localStorage.setItem('assessor_current_page', 'Profile');
    } catch (_) {
      // Ignore localStorage errors
    }
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

    const onSettingsUpdated = (e) => {
      if (e.detail) {
        setSettings(e.detail);
        try {
          localStorage.setItem('assessor_settings', JSON.stringify(e.detail));
        } catch (_) {}
      }
    };
    window.addEventListener('settingsUpdated', onSettingsUpdated);
    return () => window.removeEventListener('settingsUpdated', onSettingsUpdated);
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
    const restrictedPages = ['Audit Trail', 'Export', 'User Management', 'Settings', 'ETRACS Properties', 'Taxpayers'];
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
      text: 'ETRACS Properties',
      icon: <DescriptionIcon />,
      badge: null,
      show: (isSuperAdmin || isAdmin) && settings?.enable_etracs_features == 1
    },
    {
      text: 'Taxpayers',
      icon: <PeopleIcon />,
      badge: null,
      show: (isSuperAdmin || isAdmin) && settings?.enable_etracs_features == 1
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
          width: { md: `calc(100% - ${computedDrawerWidth})` },
          ml: { md: computedDrawerWidth },
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
              {currentPage === 'ETRACS Properties' && <DescriptionIcon sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'Taxpayers' && <PeopleIcon sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'Requests' && <ReceiptIcon sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'Audit Trail' && <History sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'Export' && <FileDownload sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'User Management' && <AccountCircle sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'Settings' && <SettingsIcon sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              {currentPage === 'Profile' && <AccountCircle sx={{ fontSize: 24, color: 'primary.main', verticalAlign: 'middle' }} />}
              <Typography variant="h6" fontWeight={600} sx={{ lineHeight: 1, display: 'inline-flex', alignItems: 'center' }}>
                {currentPage}
              </Typography>
            </motion.div>
          </Box>

          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <Tooltip title="Data Synchronization">
              <IconButton 
                color="inherit" 
                size="small"
                onClick={() => setSyncModalOpen(true)}
              >
                <AnimatedCloudIcon status={syncStatus} />
              </IconButton>
            </Tooltip>

            <Tooltip title="What's New">
              <IconButton 
                color="inherit" 
                size="small"
                onClick={() => {
                  setChangelogOpen(true);
                  if (hasNewRelease) {
                    setHasNewRelease(false);
                    const currentVersion = changelogData[0]?.version;
                    if (currentVersion && user) {
                      try {
                        localStorage.setItem(`last_seen_version_v6_${user.id}`, currentVersion);
                      } catch (e) { }
                    }
                  }
                }}
              >
                <Badge badgeContent={hasNewRelease ? 1 : 0} color="secondary" variant="dot">
                  <NewReleasesIcon />
                </Badge>
              </IconButton>
            </Tooltip>

            <Tooltip title="Notifications">
              <IconButton color="inherit" size="small" onClick={handleNotificationMenuOpen}>
                <Badge badgeContent={unreadCount} color="error">
                  <Notifications />
                </Badge>
              </IconButton>
            </Tooltip>

            <Popover
              anchorEl={notificationAnchorEl}
              open={Boolean(notificationAnchorEl)}
              onClose={handleNotificationMenuClose}
              PaperProps={{
                elevation: 4,
                sx: { 
                  width: 360, 
                  mt: 1.5,
                  borderRadius: 3,
                  overflow: 'hidden',
                  display: 'flex',
                  flexDirection: 'column'
                }
              }}
              transformOrigin={{ horizontal: 'right', vertical: 'top' }}
              anchorOrigin={{ horizontal: 'right', vertical: 'bottom' }}
            >
              <Box sx={{ px: 2, py: 2, display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderBottom: `1px solid ${theme.palette.divider}` }}>
                <Typography variant="subtitle1" fontWeight={600}>Notifications</Typography>
                <Box>
                  {unreadCount > 0 && (
                    <Typography 
                      variant="caption" 
                      color="primary.main"
                      sx={{ 
                        cursor: 'pointer', 
                        fontWeight: 600,
                        '&:hover': { textDecoration: 'underline' } 
                      }}
                      onClick={() => {
                        setNotifications(prev => prev.map(n => ({ ...n, isRead: true })));

                      }}
                    >
                      Mark all read
                    </Typography>
                  )}
                  {notifications.length > 0 && (
                    <Typography 
                      variant="caption" 
                      color="text.secondary"
                      sx={{ 
                        cursor: 'pointer', 
                        ml: 1.5,
                        fontWeight: 600,
                        '&:hover': { textDecoration: 'underline' } 
                      }}
                      onClick={() => {
                        setNotifications([]);
                      }}
                    >
                      Clear
                    </Typography>
                  )}
                </Box>
              </Box>
              <Divider />
              <Box sx={{ overflowY: 'auto', maxHeight: 400 }}>
              {notifications.length === 0 ? (
                <Box sx={{ p: 4, textAlign: 'center', display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                  <Notifications sx={{ fontSize: 48, color: 'text.disabled', mb: 1 }} />
                  <Typography variant="body1" fontWeight={500} color="text.secondary">All Caught Up!</Typography>
                  <Typography variant="body2" color="text.disabled">You have no new notifications.</Typography>
                </Box>
              ) : (
                <List sx={{ p: 0 }}>
                  {[...notifications]
                    .sort((a, b) => {
                      if (a.type === 'changelog' && b.type !== 'changelog') return -1;
                      if (b.type === 'changelog' && a.type !== 'changelog') return 1;
                      return new Date(b.timestamp) - new Date(a.timestamp);
                    })
                    .map((notif, idx, arr) => (
                    <React.Fragment key={notif.id}>
                      <ListItemButton 
                        onClick={() => handleNotificationClick(notif)}
                        sx={{ 
                          m: 0,
                          borderRadius: 0,
                          width: '100%',
                          whiteSpace: 'normal',
                          bgcolor: notif.isRead ? 'transparent' : 'rgba(25, 118, 210, 0.04)',
                          py: 2,
                          px: 2,
                          alignItems: 'flex-start',
                          transition: 'background-color 0.2s',
                          '&:hover': { bgcolor: 'action.hover' }
                        }}
                      >
                        <Avatar sx={{ 
                          mr: 2, 
                          width: 40, height: 40,
                          bgcolor: notif.type === 'success' ? 'success.light' 
                                  : notif.type === 'error' ? 'error.light'
                                  : notif.type === 'changelog' ? 'secondary.light'
                                  : 'info.light' 
                        }}>
                          {notif.type === 'success' && <CheckCircleIcon />}
                          {notif.type === 'error' && <WarningIcon />}
                          {notif.type === 'changelog' && <InfoIcon />}
                          {notif.type === 'info' && <CloudSyncIcon />}
                        </Avatar>
                        <ListItemText 
                          primary={
                            <Typography variant="body2" fontWeight={notif.isRead ? 500 : 700} color="text.primary">
                              {notif.title}
                            </Typography>
                          }
                          secondary={
                            <>
                              <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5, mb: 0.5 }}>
                                {notif.message}
                              </Typography>
                              <Typography variant="caption" color="text.disabled" sx={{ fontSize: '0.7rem' }}>
                                {new Date(notif.timestamp).toLocaleString()}
                              </Typography>
                            </>
                          }
                        />
                        {!notif.isRead && (
                          <Box sx={{ width: 8, height: 8, bgcolor: 'primary.main', borderRadius: '50%', mt: 1 }} />
                        )}
                      </ListItemButton>
                      {idx < arr.length - 1 && <Divider component="li" />}
                    </React.Fragment>
                  ))}
                </List>
              )}
              </Box>
            </Popover>
            
            <Tooltip title="Profile">
              <IconButton
                onClick={handleProfileMenuOpen}
                sx={{ ml: 1 }}
              >
                <Avatar
                  src={user?.avatar_url || undefined}
                  sx={{ 
                    width: 32, 
                    height: 32,
                    bgcolor: user?.avatar_url ? 'transparent' : 'primary.main',
                    fontSize: '0.875rem'
                  }}
                >
                  {!user?.avatar_url && (user?.full_name?.charAt(0) || user?.username?.charAt(0) || 'U')}
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
          width: { md: `calc(100% - ${computedDrawerWidth})` },
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
                {currentPage === 'ETRACS Properties' && canAccessPage('ETRACS Properties') ? <EtracsPropertyTable /> : 
                  currentPage === 'ETRACS Properties' && <Box sx={{ textAlign: 'center', py: 8 }}>
                    <Typography variant="h5" color="text.secondary" gutterBottom>Access Denied</Typography>
                    <Typography variant="body1" color="text.secondary">You don't have permission to access this page.</Typography>
                  </Box>}
                {currentPage === 'Taxpayers' && canAccessPage('Taxpayers') ? <EtracsEntityTable /> : 
                  currentPage === 'Taxpayers' && <Box sx={{ textAlign: 'center', py: 8 }}>
                    <Typography variant="h5" color="text.secondary" gutterBottom>Access Denied</Typography>
                    <Typography variant="body1" color="text.secondary">You don't have permission to access this page.</Typography>
                  </Box>}
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
                {currentPage === 'Profile' && <Profile />}
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
        
        <MenuItem onClick={handleProfileClick}>
          <ListItemIcon>
            <AccountCircle fontSize="small" />
          </ListItemIcon>
          Profile
        </MenuItem>

        <MenuItem onClick={() => { setChangelogOpen(true); handleProfileMenuClose(); }}>
          <ListItemIcon>
            <InfoIcon fontSize="small" />
          </ListItemIcon>
          What's New
        </MenuItem>
        
        <Divider />
        
        <MenuItem onClick={handleLogout}>
          <ListItemIcon>
            <Logout fontSize="small" />
          </ListItemIcon>
          Logout
        </MenuItem>
      </Menu>

      <SyncModal 
        open={syncModalOpen} 
        onClose={() => setSyncModalOpen(false)} 
      />

      <ChangelogModal 
        open={changelogOpen} 
        onClose={() => setChangelogOpen(false)} 
      />
    </Box>
  );
};

export default Layout;
