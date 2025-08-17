import React, { useState } from 'react';
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
  Description,
  Timeline
} from '@mui/icons-material';
import { useAuth } from '../../contexts/AuthContext';
import { animations } from '../../theme/theme';
import Dashboard from '../Dashboard/Dashboard';
import PropertyTable from '../PropertyTable/PropertyTable';
import AuditTrail from '../AuditTrail/AuditTrail';
import UserManagement from '../UserManagement/UserManagement';
import Export from '../Export/Export';
import DocumentManager from '../DocumentManager/DocumentManager';
import VersionHistory from '../VersionHistory/VersionHistory';

const drawerWidth = 280;

const Layout = ({ children }) => {
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('md'));
  const { user, logout } = useAuth();
  
  const [mobileOpen, setMobileOpen] = useState(false);
  const [anchorEl, setAnchorEl] = useState(null);
  const [currentPage, setCurrentPage] = useState('Dashboard');

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
    await logout();
  };

  const handleNavigation = (page) => {
    setCurrentPage(page);
    setMobileOpen(false);
  };

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
      text: 'Version History',
      icon: <Timeline />,
      badge: null
    },
    {
      text: 'Documents',
      icon: <Description />,
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
    }
  ];



  const drawer = (
    <Box sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
      {/* Header */}
             <Box
         sx={{
           padding: 3,
           textAlign: 'center',
           position: 'relative',
           overflow: 'hidden'
         }}
       >
        <motion.div
          initial="initial"
          animate="animate"
          variants={{
            initial: { opacity: 0 },
            animate: {
              opacity: 1,
              transition: {
                staggerChildren: 0.15
              }
            }
          }}
        >
          <motion.div
            variants={{
              initial: { opacity: 0, y: 15 },
              animate: { opacity: 1, y: 0, transition: { duration: 0.5, ease: "easeOut" } }
            }}
          >
            <Business sx={{ fontSize: 48, marginBottom: 1 }} />
          </motion.div>
          
          <motion.div
            variants={{
              initial: { opacity: 0, y: 15 },
              animate: { opacity: 1, y: 0, transition: { duration: 0.5, ease: "easeOut" } }
            }}
          >
            <Typography variant="h6" component="h2" gutterBottom>
              Property Assessor
            </Typography>
          </motion.div>
          
          <motion.div
            variants={{
              initial: { opacity: 0, y: 15 },
              animate: { opacity: 1, y: 0, transition: { duration: 0.5, ease: "easeOut" } }
            }}
          >
            <Typography variant="body2" sx={{ opacity: 0.9 }}>
              History Archiving System
            </Typography>
          </motion.div>
        </motion.div>
      </Box>

      {/* Navigation */}
      <Box sx={{ flex: 1, overflow: 'auto' }}>
        <List sx={{ padding: 2 }}>
          {navigationItems.map((item, index) => (
            <motion.div
              key={item.text}
              initial={{ opacity: 0, x: -20 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ delay: index * 0.1, duration: 0.3 }}
            >
              <ListItem disablePadding sx={{ marginBottom: 1 }}>
                <ListItemButton
                  onClick={() => handleNavigation(item.text)}
                  selected={currentPage === item.text}
                  sx={{
                    borderRadius: 2,
                    '&.Mui-selected': {
                      backgroundColor: theme.palette.primary.light + '20',
                      color: theme.palette.primary.main,
                      '&:hover': {
                        backgroundColor: theme.palette.primary.light + '30',
                      },
                    },
                    '&:hover': {
                      backgroundColor: theme.palette.action.hover,
                    },
                  }}
                >
                  <ListItemIcon
                    sx={{
                      color: 'inherit',
                      minWidth: 40
                    }}
                  >
                    {item.icon}
                  </ListItemIcon>
                  <ListItemText 
                    primary={item.text}
                    primaryTypographyProps={{
                      fontWeight: 400
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

      {/* Footer */}
      <Box sx={{ padding: 2, borderTop: `1px solid ${theme.palette.divider}` }}>
        <Typography variant="caption" color="text.secondary" align="center">
          © 2024 Philippine Local Government
        </Typography>
      </Box>
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
        <Toolbar>
          <IconButton
            color="inherit"
            aria-label="open drawer"
            edge="start"
            onClick={handleDrawerToggle}
            sx={{ mr: 2, display: { md: 'none' } }}
          >
            <MenuIcon />
          </IconButton>

          <Box sx={{ flexGrow: 1, display: 'flex', alignItems: 'center' }}>
            <motion.div
              initial={{ opacity: 0, x: -20 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ duration: 0.5 }}
            >
              <Typography variant="h6" noWrap component="div">
                {currentPage}
              </Typography>
            </motion.div>
          </Box>

          {/* Right side actions */}
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <Tooltip title="Notifications">
              <IconButton color="inherit" size="large">
                <Badge badgeContent={3} color="error">
                  <Notifications />
                </Badge>
              </IconButton>
            </Tooltip>

            <Tooltip title="Profile">
              <IconButton
                onClick={handleProfileMenuOpen}
                color="inherit"
                size="large"
              >
                <Avatar
                  sx={{
                    width: 32,
                    height: 32,
                    backgroundColor: theme.palette.secondary.main,
                    color: theme.palette.secondary.contrastText
                  }}
                >
                  {user?.full_name?.charAt(0) || user?.username?.charAt(0) || 'U'}
                </Avatar>
              </IconButton>
            </Tooltip>
          </Box>
        </Toolbar>
      </AppBar>

      {/* Sidebar */}
      <Box
        component="nav"
        sx={{ width: { md: drawerWidth }, flexShrink: { md: 0 } }}
      >
        {/* Mobile drawer */}
        <Drawer
          variant="temporary"
          open={mobileOpen}
          onClose={handleDrawerToggle}
          ModalProps={{
            keepMounted: true, // Better open performance on mobile.
          }}
          sx={{
            display: { xs: 'block', md: 'none' },
            '& .MuiDrawer-paper': {
              boxSizing: 'border-box',
              width: drawerWidth,
              border: 'none',
              boxShadow: '2px 0 8px rgba(0,0,0,0.08)',
              backgroundColor: theme.palette.background.paper
            },
          }}
        >
          {drawer}
        </Drawer>

        {/* Desktop drawer */}
        <Drawer
          variant="permanent"
          sx={{
            display: { xs: 'none', md: 'block' },
            '& .MuiDrawer-paper': {
              boxSizing: 'border-box',
              width: drawerWidth,
              border: 'none',
              boxShadow: '2px 0 8px rgba(0,0,0,0.08)',
              backgroundColor: theme.palette.background.paper
            },
          }}
          open
        >
          {drawer}
        </Drawer>
      </Box>

      {/* Main content */}
      <Box
        component="main"
        sx={{
          flexGrow: 1,
          width: { md: `calc(100% - ${drawerWidth}px)` },
          marginTop: '64px', // AppBar height
          padding: 3,
          backgroundColor: theme.palette.background.default,
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
            {currentPage === 'Version History' && <VersionHistory />}
            {currentPage === 'Documents' && <DocumentManager />}
            {currentPage === 'Audit Trail' && <AuditTrail />}
            {currentPage === 'Export' && <Export />}
            {currentPage === 'User Management' && <UserManagement />}
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
