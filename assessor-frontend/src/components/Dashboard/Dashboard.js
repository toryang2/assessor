import React, { useState, useEffect } from 'react';
import { motion } from 'framer-motion';
import {
  Box,
  Grid,
  Card,
  CardContent,
  Typography,
  Button,
  List,
  ListItem,
  ListItemText,
  ListItemIcon,
  Chip,
  Avatar,
  useTheme,
  CircularProgress,
  Dialog,
  DialogTitle,
  DialogContent,
  IconButton,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Paper,
  Snackbar,
  Alert
} from '@mui/material';
import {
  Business,
  TrendingUp,
  History,
  FileDownload,
  Add,
  Edit,
  Notifications,
  ArrowForward,
  Close,
  Visibility
} from '@mui/icons-material';
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip as RechartsTooltip,
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell
} from 'recharts';
import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';
import { useCacheBuster } from '../../hooks/useCacheBuster';
import { animations, statusColors } from '../../theme/theme';
import { format } from 'date-fns';
import PropertyFormModal from '../PropertyFormModal/PropertyFormModal';

const Dashboard = ({ onNavigate }) => {
  const theme = useTheme();
  const { isAuthenticated, loading: authLoading } = useAuth();
  const { addCacheBuster } = useCacheBuster();
  const [dashboardData, setDashboardData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [properties, setProperties] = useState([]);
  const [propertiesLoading, setPropertiesLoading] = useState(false);
  const [showPropertyForm, setShowPropertyForm] = useState(false);
  const [showPropertiesList, setShowPropertiesList] = useState(false);
  const [editingProperty, setEditingProperty] = useState(null);
  const [toast, setToast] = useState({ open: false, message: '', severity: 'success' });
  const [settings, setSettings] = useState(null);
  const [requestsCount, setRequestsCount] = useState(null);
  const isSmallScreen = (() => {
    try {
      const w = window.innerWidth;
      const h = window.innerHeight;
      return (w <= 1280 && h <= 720) || (w <= 1366 && h <= 768);
    } catch (_) {
      return false;
    }
  })();

  useEffect(() => {
    // Only fetch dashboard data when authentication is complete and user is authenticated
    if (!authLoading && isAuthenticated) {
      fetchDashboardData();
      fetchProperties();
      fetchSettings();
      fetchRequestStats();
    }
  }, [authLoading, isAuthenticated]);

  const fetchDashboardData = async (useCacheBusting = false) => {
    try {
      setLoading(true);
      console.log('🔍 Dashboard: Fetching dashboard data...', useCacheBusting ? '(with cache busting)' : '');
      
      let data;
      if (useCacheBusting) {
        // Use cache busting to ensure fresh data
        const params = addCacheBuster({}, true);
        data = await apiService.getDashboardData(params);
      } else {
        data = await apiService.getDashboardData();
      }
      
      console.log('✅ Dashboard: Data fetched successfully:', data);
      setDashboardData(data);
    } catch (error) {
      console.error('❌ Dashboard: Error fetching dashboard data:', error);
      setToast({ 
        open: true, 
        message: 'Failed to fetch dashboard data. Please try again.', 
        severity: 'error' 
      });
    } finally {
      setLoading(false);
    }
  };

  const fetchProperties = async (useCacheBusting = false) => {
    try {
      setPropertiesLoading(true);
      
      let data;
      if (useCacheBusting) {
        // Use cache busting to ensure fresh data
        const params = addCacheBuster({ per_page: 5 }, true);
        data = await apiService.getProperties(params);
      } else {
        data = await apiService.getProperties({ per_page: 5 });
      }
      
      setProperties(data.properties || []);
    } catch (error) {
      console.error('❌ Dashboard: Error fetching properties:', error);
    } finally {
      setPropertiesLoading(false);
    }
  };

  const fetchSettings = async () => {
    try {
      const data = await apiService.getSettings();
      setSettings(data);
    } catch (error) {
      console.error('❌ Dashboard: Error fetching settings:', error);
    }
  };

  const fetchRequestStats = async (useCacheBusting = false) => {
    try {
      const params = useCacheBusting ? addCacheBuster({}, true) : {};
      const stats = await apiService.getRequestStatistics(params);
      const count =
        (stats && (stats.total_requests ?? stats.requests_total ?? stats.total ?? stats.count)) ?? 0;
      setRequestsCount(count);
    } catch (error) {
      console.error('❌ Dashboard: Error fetching request statistics:', error);
    }
  };

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

  const handleAddProperty = () => {
    setEditingProperty(null);
    setShowPropertyForm(true);
  };

  const handleViewProperties = () => {
    if (onNavigate) {
      onNavigate('Properties');
    } else {
      setShowPropertiesList(true);
    }
  };

  const handlePropertySave = async (message) => {
    const wasEditing = !!editingProperty;
    setShowPropertyForm(false);
    setEditingProperty(null);
    // Show success message
    setToast({ open: true, message: message || 'Property saved successfully', severity: 'success' });
    // Optimistically update total properties to avoid stale cache in production
    if (!wasEditing) {
      setDashboardData(prev => ({
        ...prev,
        total_properties: (prev && typeof prev.total_properties === 'number') ? prev.total_properties + 1 : 1
      }));
    }
    // Refresh data with cache busting to ensure we get the latest total properties count
    await fetchDashboardData(true);
    await fetchProperties(true);
  };

  const handlePropertyCancel = () => {
    setShowPropertyForm(false);
    setEditingProperty(null);
  };

  const handleEditProperty = (property) => {
    setEditingProperty(property);
    setShowPropertyForm(true);
  };

  const handleViewProperty = (property) => {
    if (onNavigate) {
      onNavigate('Properties');
    } else {
      // Show property details in a modal or expand the list
      setShowPropertiesList(true);
    }
  };


  // Show loading state while authentication is being checked
  if (authLoading) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '50vh' }}>
        <CircularProgress />
      </Box>
    );
  }



  // Sample data for charts (replace with real data from API)
  const propertyTypeData = [
    { name: 'Residential', value: 45, color: theme.palette.primary.main },
    { name: 'Commercial', value: 30, color: theme.palette.secondary.main },
    { name: 'Industrial', value: 15, color: theme.palette.success.main },
    { name: 'Agricultural', value: 10, color: theme.palette.warning.main }
  ];

  const monthlyActivityData = [
    { month: 'Jan', properties: 12, updates: 8 },
    { month: 'Feb', properties: 15, updates: 12 },
    { month: 'Mar', properties: 18, updates: 15 },
    { month: 'Apr', properties: 22, updates: 18 },
    { month: 'May', properties: 25, updates: 22 },
    { month: 'Jun', properties: 28, updates: 25 }
  ];

  const quickActions = [
    {
      title: 'Add New Property',
      description: 'Create a new property assessment record',
      icon: <Add />,
      color: theme.palette.primary.main,
      action: handleAddProperty
    },
    {
      title: 'View Properties',
      description: 'Browse and manage property records',
      icon: <Business />,
      color: theme.palette.secondary.main,
      action: handleViewProperties
    },
    {
      title: 'Export Data',
      description: 'Generate reports and export data',
      icon: <FileDownload />,
      color: theme.palette.secondary.main,
      action: () => console.log('Export Data clicked')
    },
    {
      title: 'Audit Trail',
      description: 'View system activity and changes',
      icon: <History />,
      color: theme.palette.info.main,
      action: () => console.log('Audit Trail clicked')
    }
  ];

  const StatCard = ({ title, value, icon, color, subtitle, trend }) => (
    <motion.div
      whileHover={{ scale: 1.02, y: -5 }}
      transition={{ duration: 0.2 }}
    >
      <Card
        sx={{
          height: '100%',
          background: color + '08',
          border: `1px solid ${color}20`,
          position: 'relative',
          overflow: 'hidden'
        }}
      >
        <CardContent>
          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <Box>
              <Typography variant="h4" component="div" fontWeight={600} color={color}>
                {value}
              </Typography>
              <Typography variant="body2" color="text.secondary" gutterBottom>
                {title}
              </Typography>
              {subtitle && (
                <Typography variant="caption" color="text.secondary">
                  {subtitle}
                </Typography>
              )}
            </Box>
            <Avatar
              sx={{
                backgroundColor: color + '15',
                color: color,
                width: 56,
                height: 56
              }}
            >
              {icon}
            </Avatar>
          </Box>
          
          {trend && (
            <Box sx={{ display: 'flex', alignItems: 'center', mt: 1 }}>
              <TrendingUp sx={{ fontSize: 16, color: theme.palette.success.main, mr: 0.5 }} />
              <Typography variant="caption" color="success.main">
                {trend}
              </Typography>
            </Box>
          )}
        </CardContent>
      </Card>
    </motion.div>
  );

  const QuickActionCard = ({ action, loading = false }) => (
    <motion.div
      whileHover={{ scale: 1.02 }}
      whileTap={{ scale: 0.98 }}
    >
      <Card
        sx={{
          height: '100%',
          cursor: loading ? 'not-allowed' : 'pointer',
          opacity: loading ? 0.7 : 1
        }}
        onClick={loading ? undefined : action.action}
      >
        <CardContent sx={{ textAlign: 'center', padding: 3 }}>
          <Avatar
            sx={{
              backgroundColor: action.color + '15',
              color: action.color,
              width: 64,
              height: 64,
              margin: '0 auto 16px'
            }}
          >
            {loading ? <CircularProgress size={32} color="inherit" /> : action.icon}
          </Avatar>
          <Typography variant="h6" component="h3" gutterBottom>
            {action.title}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            {action.description}
          </Typography>
        </CardContent>
      </Card>
    </motion.div>
  );

  if (loading) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '50vh' }}>
        <Typography>Loading dashboard...</Typography>
      </Box>
    );
  }

  return (
    <Box>
      {/* Header Photo */}
      {dashboardData?.header_photo_url && (
        <motion.div
          initial="initial"
          animate="animate"
          variants={animations.fadeIn}
        >
          <Box sx={{ marginBottom: 4, marginTop: -3, marginLeft: -3, marginRight: -3 }}>
            <Box
              sx={{
                width: '100%',
                height: isSmallScreen ? '100px' : '200px', // shorter at 720p
                backgroundImage: `url(${dashboardData.header_photo_url})`,
                backgroundSize: 'cover',
                backgroundPosition: 'center',
                backgroundRepeat: 'no-repeat',
                borderRadius: 0,
                boxShadow: theme.shadows[4],
                position: 'relative',
                overflow: 'hidden'
              }}
            >
              {/* Header content with logo and text */}
              <Box
                sx={{
                  position: 'absolute',
                  top: 0,
                  left: 0,
                  right: 0,
                  bottom: 0,
                  background: 'linear-gradient(135deg, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.3) 100%)',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  padding: 3
                }}
              >
                {/* Left side - Logo and text */}
                <Box sx={{ display: 'flex', alignItems: 'center', gap: 3 }}>
                  <Box sx={{ display: 'flex', alignItems: 'center', flexShrink: 0 }}>
                    {settings?.app_logo_url ? (
                      <img src={settings.app_logo_url} alt="Logo" style={{ maxHeight: isSmallScreen ? 64 : 86, display: 'block' }} />
                    ) : (
                      <Business sx={{ fontSize: isSmallScreen ? 64 : 80, color: 'white' }} />
                    )}
                  </Box>
                  <Box sx={{ display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
                    <Typography
                        variant="h2"
                        component="h1"
                        sx={{
                          color: 'white',
                          fontWeight: 600,
                          marginBottom: 0,
                          fontFamily: 'Poppins, sans-serif',
                          fontSize: isSmallScreen ? '1.5rem' : undefined
                        }}
                      >
                        Assessor's Archiving System
                      </Typography>
                      <Typography
                        variant="h3"
                        sx={{
                          color: 'white',
                          fontWeight: 500,
                          marginBottom: 0,
                          fontFamily: 'Poppins, sans-serif',
                          fontSize: isSmallScreen ? '1.2rem' : undefined
                        }}
                      >
                        Municipality of {toFormalCase(settings?.header_municipality || 'KITAOTAO')}
                      </Typography>
                      <Typography
                        variant="h3"
                        sx={{
                          color: 'white',
                          fontWeight: 400,
                          marginBottom: 0,
                          fontFamily: 'Poppins, sans-serif',
                          fontSize: isSmallScreen ? '1.15rem' : undefined
                        }}
                      >
                        Province of {toFormalCase(settings?.header_province || 'BUKIDNON')}
                      </Typography>
                  </Box>
                </Box>
              </Box>
            </Box>
          </Box>
        </motion.div>
      )}

      {/* Header */}
      <motion.div
        initial="initial"
        animate="animate"
        variants={animations.fadeIn}
      >
        <Box sx={{ marginBottom: 4, marginTop: 4 }}>
          <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <Box>
              <Typography variant="h3" component="h1" gutterBottom>
                Welcome back!
              </Typography>
              <Typography variant="body1" color="text.secondary">
                Here's what's happening with your property assessment system today.
              </Typography>
            </Box>
            <Button
              variant="outlined"
              onClick={async () => {
                await fetchDashboardData(true);
                await fetchProperties(true);
              }}
              disabled={loading || propertiesLoading}
              startIcon={<TrendingUp />}
            >
              Refresh Data
            </Button>
          </Box>
        </Box>
      </motion.div>

      {/* Statistics Cards */}
      <motion.div
        initial="initial"
        animate="animate"
        variants={animations.stagger}
      >
        <Grid container spacing={3} sx={{ marginBottom: 4 }}>
          <Grid item xs={12} sm={6} md={3}>
            <StatCard
              title="Total Properties"
              value={dashboardData?.total_properties || 0}
              icon={<Business />}
              color={theme.palette.primary.main}
              subtitle="Active records"
              trend="+12% this month"
            />
          </Grid>
          <Grid item xs={12} sm={6} md={3}>
            <StatCard
              title="Version History"
              value={dashboardData?.version_counts || 0}
              icon={<History />}
              color={theme.palette.secondary.main}
              subtitle="Total versions"
              trend="+8% this month"
            />
          </Grid>
          <Grid item xs={12} sm={6} md={3}>
            <StatCard
              title="Recent Updates"
              value={dashboardData?.recent_activity?.length || 0}
              icon={<History />}
              color={theme.palette.success.main}
              subtitle="Last 24 hours"
              trend="+15% this week"
            />
          </Grid>
          <Grid item xs={12} sm={6} md={3}>
            <StatCard
              title="Requests"
              value={(dashboardData?.requests_count ?? null) ?? (requestsCount ?? 0)}
              icon={<Business />}
              color={theme.palette.info.main}
              subtitle="Total requests"
              trend="+3% this month"
            />
          </Grid>
        </Grid>
      </motion.div>

      {/* Charts and Quick Actions */}
      {/* <Grid container spacing={4}> */}
        {/* Property Type Distribution */}
        {/* <Grid item xs={12} md={6}>
          <motion.div
            initial="initial"
            animate="animate"
            variants={animations.slideIn}
          >
            <Card>
              <CardContent>
                <Typography variant="h6" component="h3" gutterBottom>
                  Property Type Distribution
                </Typography>
                <ResponsiveContainer width="100%" height={300}>
                  <PieChart>
                    <Pie
                      data={propertyTypeData}
                      cx="50%"
                      cy="50%"
                      labelLine={false}
                      label={({ name, percent }) => `${name} ${(percent * 100).toFixed(0)}%`}
                      outerRadius={80}
                      fill="#8884d8"
                      dataKey="value"
                    >
                      {propertyTypeData.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={entry.color} />
                      ))}
                    </Pie>
                    <RechartsTooltip />
                  </PieChart>
                </ResponsiveContainer>
              </CardContent>
            </Card>
          </motion.div>
        </Grid> */}

        {/* Monthly Activity */}
        {/* <Grid item xs={12} md={6}>
          <motion.div
            initial="initial"
            animate="animate"
            variants={animations.slideIn}
          >
            <Card>
              <CardContent>
                <Typography variant="h6" component="h3" gutterBottom>
                  Monthly Activity
                </Typography>
                <ResponsiveContainer width="100%" height={300}>
                  <BarChart data={monthlyActivityData}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="month" />
                    <YAxis />
                    <RechartsTooltip />
                    <Bar dataKey="properties" fill={theme.palette.primary.main} name="New Properties" />
                    <Bar dataKey="updates" fill={theme.palette.secondary.main} name="Updates" />
                  </BarChart>
                </ResponsiveContainer>
              </CardContent>
            </Card>
          </motion.div>
        </Grid>
      </Grid> */}

      {/* Quick Actions */}
      <motion.div
        initial="initial"
        animate="animate"
        variants={animations.fadeIn}
        transition={{ delay: 0.3 }}
      >
        <Box sx={{ marginTop: 4, marginBottom: 3 }}>
          <Typography variant="h5" component="h2" gutterBottom>
            Quick Actions
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ marginBottom: 3 }}>
            Get started with common tasks
          </Typography>
        </Box>

        <Grid container spacing={3}>
          {quickActions.map((action, index) => (
            <Grid item xs={12} sm={6} md={3} key={action.title}>
              <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: index * 0.1, duration: 0.3 }}
              >
                <QuickActionCard 
                  action={action} 
                  loading={action.title === 'Add New Property' && (loading || propertiesLoading)}
                />
              </motion.div>
            </Grid>
          ))}
        </Grid>
      </motion.div>

      {/* Recent Properties */}
      <motion.div
        initial="initial"
        animate="animate"
        variants={animations.fadeIn}
        transition={{ delay: 0.4 }}
      >
        <Box sx={{ marginTop: 4, marginBottom: 3 }}>
          <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 2 }}>
            <Typography variant="h5" component="h2">
              Recent Properties
            </Typography>
            <Button
              endIcon={<ArrowForward />}
              onClick={handleViewProperties}
              sx={{ textTransform: 'none' }}
            >
              View All Properties
            </Button>
          </Box>
          
          {propertiesLoading ? (
            <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: 200 }}>
              <CircularProgress />
            </Box>
          ) : properties.length === 0 ? (
            <Card>
              <CardContent sx={{ textAlign: 'center', py: 4 }}>
                <Business sx={{ fontSize: 48, color: 'text.secondary', mb: 2 }} />
                <Typography variant="h6" color="text.secondary" gutterBottom>
                  No Properties Yet
                </Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                  Get started by adding your first property assessment record.
                </Typography>
                <Button
                  variant="contained"
                  startIcon={<Add />}
                  onClick={handleAddProperty}
                >
                  Add First Property
                </Button>
              </CardContent>
            </Card>
          ) : (
            <Grid container spacing={2}>
              {properties.slice(0, 6).map((property, index) => (
                <Grid item xs={12} sm={6} md={4} key={property.id}>
                  <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: index * 0.1, duration: 0.3 }}
                  >
                    <Card
                      sx={{
                        height: '100%',
                        cursor: 'pointer',
                        '&:hover': {
                          boxShadow: theme.shadows[8],
                          transform: 'translateY(-2px)',
                          transition: 'all 0.2s ease-in-out'
                        }
                      }}
                      onClick={() => handleViewProperty(property)}
                    >
                      <CardContent>
                        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 2 }}>
                          <Typography variant="h6" component="h3" noWrap>
                            {property.tax_declaration_number || `Property ${property.id}`}
                          </Typography>
                          <Chip
                            label={property.kind_of_property || 'Unknown'}
                            size="small"
                            color="primary"
                            variant="outlined"
                          />
                        </Box>
                        
                        <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
                          {property.declarant_last_name && property.declarant_first_name 
                            ? `${property.declarant_last_name}, ${property.declarant_first_name}`
                            : property.business_name || 'No owner specified'
                          }
                        </Typography>
                        
                        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                          {property.location || property.address || 'Location not specified'}
                        </Typography>
                        
                        {property.assessed_value && (
                          <Typography variant="body2" fontWeight={500} color="primary.main">
                            ₱{Number(property.assessed_value).toLocaleString()}
                          </Typography>
                        )}
                        
                        <Box sx={{ display: 'flex', justifyContent: 'flex-end', mt: 2 }}>
                          <IconButton
                            size="small"
                            onClick={(e) => {
                              e.stopPropagation();
                              handleEditProperty(property);
                            }}
                          >
                            <Edit fontSize="small" />
                          </IconButton>
                        </Box>
                      </CardContent>
                    </Card>
                  </motion.div>
                </Grid>
              ))}
            </Grid>
          )}
        </Box>
      </motion.div>

      {/* Recent Activity */}
      <motion.div
        initial="initial"
        animate="animate"
        variants={animations.fadeIn}
        transition={{ delay: 0.5 }}
      >
        <Box sx={{ marginTop: 4 }}>
          <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 2 }}>
            <Typography variant="h5" component="h2">
              Recent Activity
            </Typography>
            <Button
              endIcon={<ArrowForward />}
              onClick={() => console.log('View All Activity clicked')}
              sx={{ textTransform: 'none' }}
            >
              View All
            </Button>
          </Box>

          <Card>
            <CardContent>
              <List>
                {dashboardData?.recent_activity?.slice(0, 5).map((activity, index) => (
                  <motion.div
                    key={activity.id}
                    initial={{ opacity: 0, x: -20 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ delay: index * 0.1, duration: 0.3 }}
                  >
                    <ListItem
                      sx={{
                        borderBottom: index < 4 ? `1px solid ${theme.palette.divider}` : 'none',
                        paddingY: 2
                      }}
                    >
                      <ListItemIcon>
                        <Avatar
                          sx={{
                            backgroundColor: statusColors[activity.action] + '20',
                            color: statusColors[activity.action],
                            width: 40,
                            height: 40
                          }}
                        >
                          {activity.action === 'create' && <Add />}
                          {activity.action === 'update' && <Edit />}
                          {activity.action === 'delete' && <History />}
                          {activity.action === 'login' && <Notifications />}
                        </Avatar>
                      </ListItemIcon>
                      <ListItemText
                        primary={
                          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                            <Typography variant="body1" fontWeight={500}>
                              {activity.action}
                            </Typography>
                            <Chip
                              label={activity.property}
                              size="small"
                              color="primary"
                              variant="outlined"
                            />
                          </Box>
                        }
                        secondary={
                          <Typography variant="body2" color="text.secondary">
                            {activity.property} • {format(new Date(activity.timestamp), 'MMM dd, yyyy HH:mm')}
                          </Typography>
                        }
                      />
                    </ListItem>
                  </motion.div>
                ))}
              </List>
            </CardContent>
          </Card>
        </Box>
      </motion.div>

             {/* Property Form Modal */}
       <PropertyFormModal
         open={showPropertyForm}
         property={editingProperty}
         onSave={handlePropertySave}
         onCancel={handlePropertyCancel}
         onClose={handlePropertyCancel}
       />

             {/* Properties List Dialog */}
       <Dialog open={showPropertiesList} onClose={() => setShowPropertiesList(false)} maxWidth="lg" fullWidth>
         <DialogTitle>
           <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
             <Typography variant="h6">Properties Overview</Typography>
             <Box>
               <Button
                 variant="contained"
                 startIcon={<Add />}
                 onClick={() => {
                   setShowPropertiesList(false);
                   handleAddProperty();
                 }}
                 sx={{ mr: 1 }}
               >
                 Add New Property
               </Button>
               <IconButton onClick={() => setShowPropertiesList(false)}>
                 <Close />
               </IconButton>
             </Box>
           </Box>
         </DialogTitle>
         <DialogContent>
           <TableContainer component={Paper}>
             <Table>
               <TableHead>
                 <TableRow>
                   <TableCell><strong>Tax Declaration #</strong></TableCell>
                   <TableCell><strong>Owner</strong></TableCell>
                   <TableCell><strong>Location</strong></TableCell>
                   <TableCell><strong>Type</strong></TableCell>
                   <TableCell><strong>Assessed Value</strong></TableCell>
                   <TableCell><strong>Area (ha)</strong></TableCell>
                   <TableCell align="right"><strong>Actions</strong></TableCell>
                 </TableRow>
               </TableHead>
               <TableBody>
                 {propertiesLoading ? (
                   <TableRow>
                     <TableCell colSpan={7} align="center">
                       <CircularProgress />
                     </TableCell>
                   </TableRow>
                 ) : properties.length === 0 ? (
                   <TableRow>
                     <TableCell colSpan={7} align="center">
                       <Box sx={{ py: 4, textAlign: 'center' }}>
                         <Business sx={{ fontSize: 48, color: 'text.secondary', mb: 2 }} />
                         <Typography variant="h6" color="text.secondary" gutterBottom>
                           No Properties Found
                         </Typography>
                         <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                           Get started by adding your first property assessment record.
                         </Typography>
                         <Button
                           variant="contained"
                           startIcon={<Add />}
                           onClick={() => {
                             setShowPropertiesList(false);
                             handleAddProperty();
                           }}
                         >
                           Add First Property
                         </Button>
                       </Box>
                     </TableCell>
                   </TableRow>
                 ) : (
                   properties.map((property) => (
                     <TableRow 
                       key={property.id}
                       sx={{ '&:hover': { backgroundColor: 'action.hover' } }}
                     >
                       <TableCell>
                         <Typography variant="body2" fontWeight={500}>
                           {property.tax_declaration_number || `ID: ${property.id}`}
                         </Typography>
                       </TableCell>
                       <TableCell>
                         <Typography variant="body2">
                           {property.declarant_last_name && property.declarant_first_name 
                             ? `${property.declarant_last_name}, ${property.declarant_first_name}`
                             : property.business_name || 'Not specified'
                           }
                         </Typography>
                       </TableCell>
                       <TableCell>
                         <Typography variant="body2">
                           {property.location || property.address || 'Not specified'}
                         </Typography>
                       </TableCell>
                       <TableCell>
                         <Chip 
                           label={property.kind_of_property || 'Unknown'} 
                           size="small" 
                           color="primary" 
                           variant="outlined" 
                         />
                       </TableCell>
                       <TableCell>
                         {property.assessed_value ? (
                           <Typography variant="body2" fontWeight={500} color="primary.main">
                             ₱{Number(property.assessed_value).toLocaleString()}
                           </Typography>
                         ) : (
                           <Typography variant="body2" color="text.secondary">
                             Not assessed
                           </Typography>
                         )}
                       </TableCell>
                       <TableCell>
                         {property.area_hectare ? (
                           <Typography variant="body2">
                             {Number(property.area_hectare).toFixed(4)}
                           </Typography>
                         ) : (
                           <Typography variant="body2" color="text.secondary">
                             -
                           </Typography>
                         )}
                       </TableCell>
                       <TableCell align="right">
                         <IconButton 
                           size="small"
                           onClick={() => handleEditProperty(property)}
                           title="Edit Property"
                         >
                           <Edit fontSize="small" />
                         </IconButton>
                         <IconButton 
                           size="small"
                           onClick={() => handleViewProperty(property)}
                           title="View Details"
                         >
                           <Visibility fontSize="small" />
                         </IconButton>
                       </TableCell>
                     </TableRow>
                   ))
                 )}
               </TableBody>
             </Table>
           </TableContainer>
                  </DialogContent>
       </Dialog>

       {/* Toast Notifications */}
       <Snackbar
         open={toast.open}
         autoHideDuration={4000}
         onClose={() => setToast(prev => ({ ...prev, open: false }))}
         anchorOrigin={{ vertical: 'top', horizontal: 'right' }}
       >
         <Alert 
           onClose={() => setToast(prev => ({ ...prev, open: false }))} 
           severity={toast.severity} 
           sx={{ width: '100%' }}
         >
           {toast.message}
         </Alert>
       </Snackbar>
     </Box>
   );
 };

export default Dashboard;
