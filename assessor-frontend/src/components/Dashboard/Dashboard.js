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
  CircularProgress
} from '@mui/material';
import {
  Business,
  TrendingUp,
  History,
  FileDownload,
  Add,
  Edit,
  Notifications,
  ArrowForward
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
import { animations, statusColors } from '../../theme/theme';
import { format } from 'date-fns';

const Dashboard = () => {
  const theme = useTheme();
  const { isAuthenticated, loading: authLoading } = useAuth();
  const [dashboardData, setDashboardData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Only fetch dashboard data when authentication is complete and user is authenticated
    if (!authLoading && isAuthenticated) {
      fetchDashboardData();
    }
  }, [authLoading, isAuthenticated]);

  const fetchDashboardData = async () => {
    try {
      setLoading(true);
      console.log('🔍 Dashboard: Fetching dashboard data...');
      const data = await apiService.getDashboardData();
      console.log('✅ Dashboard: Data fetched successfully:', data);
      setDashboardData(data);
    } catch (error) {
      console.error('❌ Dashboard: Error fetching dashboard data:', error);
    } finally {
      setLoading(false);
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
      action: () => console.log('Add New Property clicked')
    },
    {
      title: 'View Properties',
      description: 'Browse and manage property records',
      icon: <Business />,
      color: theme.palette.secondary.main,
      action: () => console.log('View Properties clicked')
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

  const QuickActionCard = ({ action }) => (
    <motion.div
      whileHover={{ scale: 1.02 }}
      whileTap={{ scale: 0.98 }}
    >
      <Card
        sx={{
          height: '100%',
                   cursor: 'pointer'
        }}
        onClick={action.action}
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
            {action.icon}
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
      {/* Header */}
      <motion.div
        initial="initial"
        animate="animate"
        variants={animations.fadeIn}
      >
        <Box sx={{ marginBottom: 4 }}>
          <Typography variant="h3" component="h1" gutterBottom>
            Welcome back!
          </Typography>
          <Typography variant="body1" color="text.secondary">
            Here's what's happening with your property assessment system today.
          </Typography>
          

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
              title="Active Users"
              value="24"
              icon={<Business />}
              color={theme.palette.info.main}
              subtitle="Online now"
              trend="+3% this month"
            />
          </Grid>
        </Grid>
      </motion.div>

      {/* Charts and Quick Actions */}
      <Grid container spacing={4}>
        {/* Property Type Distribution */}
        <Grid item xs={12} md={6}>
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
        </Grid>

        {/* Monthly Activity */}
        <Grid item xs={12} md={6}>
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
      </Grid>

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
                <QuickActionCard action={action} />
              </motion.div>
            </Grid>
          ))}
        </Grid>
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
    </Box>
  );
};

export default Dashboard;
